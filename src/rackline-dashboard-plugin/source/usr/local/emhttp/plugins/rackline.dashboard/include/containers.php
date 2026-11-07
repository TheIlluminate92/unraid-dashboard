<?php
/* Rackline bounded, GET-only Docker accounting. No host mutations or cache writes. */
declare(strict_types=1);
const RACKLINE_CONTAINER_SOCKET = '/var/run/docker.sock';
const RACKLINE_CONTAINER_GPU = '/var/run/waz.dashboard/gpu.json';
const RACKLINE_CONTAINER_LIMIT = 12;
const RACKLINE_CONTAINER_BYTES = 1048576;
function rackline_container_number($value): ?float {
    return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0 ? (float)$value : null;
}
function rackline_container_ids(string $input): array {
    if (strlen($input) > 779) throw new InvalidArgumentException('Too many or invalid container IDs');
    $ids = $input === '' ? [] : explode(',', strtolower($input));
    if (count($ids) > RACKLINE_CONTAINER_LIMIT) throw new InvalidArgumentException('Too many container IDs');
    foreach ($ids as $id) if (!preg_match('/^(?:[a-f0-9]{12}|[a-f0-9]{64})$/D', $id)) throw new InvalidArgumentException('Invalid container ID');
    return array_values(array_unique($ids));
}
function rackline_container_stats(array $raw, float $now, float $mono): array {
    $out = ['available'=>false,'sampledAtMs'=>$now,'monotonicSeconds'=>$mono,'cpuCounters'=>null,'memoryBytes'=>null,'memoryMode'=>'usage','networkRxBytes'=>null,'networkTxBytes'=>null,'networkIdentity'=>null,'blockReadBytes'=>null,'blockWriteBytes'=>null,'blockIdentity'=>null];
    if (isset($raw['message']) || isset($raw['error'])) return $out;
    $cpu = is_array($raw['cpu_stats'] ?? null) ? $raw['cpu_stats'] : [];
    $usage = rackline_container_number($cpu['cpu_usage']['total_usage'] ?? null);
    $system = rackline_container_number($cpu['system_cpu_usage'] ?? null);
    $online = rackline_container_number($cpu['online_cpus'] ?? null);
    if ($online === null && is_array($cpu['cpu_usage']['percpu_usage'] ?? null)) $online = (float)count($cpu['cpu_usage']['percpu_usage']);
    if ($usage !== null && $system !== null && $system > 0 && $online !== null && $online > 0) $out['cpuCounters'] = ['usage'=>$usage,'system'=>$system,'online'=>$online];
    $memory = is_array($raw['memory_stats'] ?? null) ? $raw['memory_stats'] : [];
    $out['memoryBytes'] = rackline_container_number($memory['usage'] ?? null);
    $cache = rackline_container_number($memory['stats']['total_inactive_file'] ?? $memory['stats']['inactive_file'] ?? null);
    if ($out['memoryBytes'] !== null && $cache !== null && $cache <= $out['memoryBytes']) { $out['memoryBytes'] -= $cache; $out['memoryMode'] = 'working_set'; }
    if (is_array($raw['networks'] ?? null) && count($raw['networks']) > 0) {
        $rx=0.0; $tx=0.0; $names=[]; $valid=true;
        foreach ($raw['networks'] as $name=>$net) {
            if (!is_string($name) || !is_array($net)) { $valid=false; break; }
            $r=rackline_container_number($net['rx_bytes'] ?? null); $t=rackline_container_number($net['tx_bytes'] ?? null);
            if ($r===null || $t===null) { $valid=false; break; }
            $names[]=$name; $rx+=$r; $tx+=$t;
        }
        if ($valid && is_finite($rx) && is_finite($tx)) { sort($names,SORT_STRING); $out['networkRxBytes']=$rx; $out['networkTxBytes']=$tx; $out['networkIdentity']=json_encode($names); }
    }
    $blocks=$raw['blkio_stats']['io_service_bytes_recursive'] ?? null;
    if (is_array($blocks) && count($blocks)>0) {
        $devices=[]; $valid=true;
        foreach ($blocks as $item) {
            if (!is_array($item)) { $valid=false; break; }
            $op=strtolower(is_string($item['op'] ?? null)?$item['op']:'');
            if (!in_array($op,['read','write'],true)) continue;
            $major=$item['major'] ?? null; $minor=$item['minor'] ?? null; $value=rackline_container_number($item['value'] ?? null);
            if (!is_int($major)||!is_int($minor)||$major<0||$minor<0||$value===null) { $valid=false; break; }
            $key=$major.':'.$minor;
            if (isset($devices[$key][$op])) { $valid=false; break; }
            $devices[$key][$op]=$value;
        }
        $read=0.0; $write=0.0;
        foreach ($devices as $device) { if (!isset($device['read'],$device['write'])) { $valid=false; break; } $read+=$device['read']; $write+=$device['write']; }
        if ($valid && $devices && is_finite($read) && is_finite($write)) { $keys=array_keys($devices); sort($keys,SORT_STRING); $out['blockReadBytes']=$read; $out['blockWriteBytes']=$write; $out['blockIdentity']=json_encode($keys); }
    }
    $out['available']=$out['cpuCounters']!==null || $out['memoryBytes']!==null || $out['networkRxBytes']!==null || $out['blockReadBytes']!==null;
    return $out;
}
function rackline_container_path(string $path): bool {
    return $path==='/containers/json?all=true' || preg_match('#^/containers/[a-f0-9]{64}/(?:json|stats\?stream=false&one-shot=true)$#D',$path)===1;
}
/* At most four sockets, bounded bodies and one total deadline across list, inspect and stats. */
function rackline_container_transport(array $paths, float $deadline): array {
    foreach ($paths as $path) if (!rackline_container_path($path)) throw new InvalidArgumentException('Forbidden Docker read path');
    $result=[]; $pending=$paths; $active=[]; $multi=curl_multi_init();
    try {
        while ($pending || $active) {
            $remaining=(int)(($deadline-hrtime(true)/1e9)*1000);
            if ($remaining<=0) break;
            while ($pending && count($active)<4) {
                $key=array_key_first($pending); $path=$pending[$key]; unset($pending[$key]);
                $entry=(object)['handle'=>curl_init(),'key'=>$key,'body'=>'','overflow'=>false];
                curl_setopt_array($entry->handle,[CURLOPT_URL=>'http://localhost'.$path,CURLOPT_UNIX_SOCKET_PATH=>RACKLINE_CONTAINER_SOCKET,CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT_MS=>min(400,$remaining),CURLOPT_TIMEOUT_MS=>min(1200,$remaining),CURLOPT_NOSIGNAL=>true,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use($entry): int { if(strlen($entry->body)+strlen($chunk)>RACKLINE_CONTAINER_BYTES){$entry->overflow=true;return 0;} $entry->body.=$chunk;return strlen($chunk); }]);
                curl_multi_add_handle($multi,$entry->handle); $active[(int)(is_object($entry->handle)?spl_object_id($entry->handle):$entry->handle)]=$entry;
            }
            do { $code=curl_multi_exec($multi,$running); } while($code===CURLM_CALL_MULTI_PERFORM);
            if($code!==CURLM_OK) break;
            while($info=curl_multi_info_read($multi)) {
                $handle=$info['handle']; $key=(int)(is_object($handle)?spl_object_id($handle):$handle); $entry=$active[$key];
                $http=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE); $body=json_decode($entry->body,true);
                $status=$entry->overflow?'oversized':($info['result']===CURLE_OPERATION_TIMEDOUT?'timeout':($info['result']===CURLE_OK && $http===200 && is_array($body)?'ok':'unavailable'));
                $result[$entry->key]=['status'=>$status,'data'=>$status==='ok'?$body:null,'sampledAtMs'=>round(microtime(true)*1000),'monotonicSeconds'=>hrtime(true)/1e9];
                curl_multi_remove_handle($multi,$handle);curl_close($handle);unset($active[$key]);
            }
            if($active) { $wait=max(0.001,min(0.05,$deadline-hrtime(true)/1e9)); if(curl_multi_select($multi,$wait)===-1) usleep(1000); }
        }
    } finally {
        foreach($active as $entry) { $result[$entry->key]=['status'=>'timeout','data'=>null];curl_multi_remove_handle($multi,$entry->handle);curl_close($entry->handle); }
        foreach($pending as $key=>$path) $result[$key]=['status'=>'deferred','data'=>null];
        curl_multi_close($multi);
    }
    return $result;
}
function rackline_container_batch(array $ids, callable $transport): array {
    $deadline=hrtime(true)/1e9+3.5;
    $inventory=$transport(['inventory'=>'/containers/json?all=true'],$deadline)['inventory'] ?? [];
    if(($inventory['status'] ?? '')!=='ok' || !is_array($inventory['data'] ?? null) || isset($inventory['data']['message']) || isset($inventory['data']['error'])) throw new RuntimeException('Docker inventory unavailable');
    $rows=[]; $paths=[]; $hostNetwork=[]; $inspects=[];
    foreach($ids as $requested) {
        $matches=[];
        foreach($inventory['data'] as $item) if(is_array($item)&&is_string($item['Id'] ?? null)&&preg_match('/^[a-f0-9]{64}$/D',$item['Id'])&&str_starts_with($item['Id'],$requested)) $matches[]=$item;
        $row=['requestedId'=>$requested,'id'=>null,'name'=>null,'state'=>'unknown','generation'=>null,'status'=>'unavailable','stats'=>rackline_container_stats([],round(microtime(true)*1000),hrtime(true)/1e9)];
        if(count($matches)===1) {
            $item=$matches[0]; $name=is_string($item['Names'][0] ?? null)?ltrim($item['Names'][0],'/'):'';
            $row['id']=$item['Id']; $row['name']=preg_match('/^[a-zA-Z0-9_.-]+$/D',$name)?$name:null;
            $row['state']=is_string($item['State'] ?? null)?$item['State']:'unknown';
            $row['status']='not_running';
            if($row['state']==='running' && $row['name']!==null) { $hostNetwork[$requested]=($item['HostConfig']['NetworkMode']??null)==='host'; $inspects[$requested]='/containers/'.$item['Id'].'/json'; $row['status']='deferred'; }
        }
        $rows[$requested]=$row;
    }
    // At most 25 GETs: one inventory, 12 minimal inspect validations, 12 stats.
    // Inspect and stats share the same deadline; no private inspect fields escape.
    $inspected=$inspects?$transport($inspects,$deadline):[];
    foreach($inspected as $key=>$inspection) {
        if(!isset($rows[$key])) continue;
        $rows[$key]['status']=$inspection['status']??'unavailable';
        if($rows[$key]['status']!=='ok') continue;
        $details=$inspection['data']??null;
        $name=is_array($details)&&is_string($details['Name']??null)?ltrim($details['Name'],'/'):null;
        $state=is_array($details)&&is_array($details['State']??null)?$details['State']:[];
        if(!is_array($details)||($details['Id']??null)!==$rows[$key]['id']||$name!==$rows[$key]['name']||!is_bool($state['Running']??null)||!is_bool($state['Paused']??null)||!is_bool($state['Restarting']??null)) { $rows[$key]['status']='unavailable';continue; }
        $rows[$key]['state']=$state['Paused']?'paused':($state['Restarting']?'restarting':($state['Running']?'running':'stopped'));
        if($rows[$key]['state']!=='running') { $rows[$key]['status']='not_running';continue; }
        $generation=$state['StartedAt']??null;
        if(!is_string($generation)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',$generation)||str_starts_with($generation,'0001-')||strtotime(preg_replace('/\.[0-9]+(?=Z|[+-])/','',$generation))===false) { $rows[$key]['status']='unavailable';continue; }
        $rows[$key]['generation']=$generation;
        $hostNetwork[$key]=($details['HostConfig']['NetworkMode']??null)==='host'||($hostNetwork[$key]??false);
        $paths[$key]='/containers/'.$rows[$key]['id'].'/stats?stream=false&one-shot=true';
        $rows[$key]['status']='deferred';
    }
    $samples=$paths?$transport($paths,$deadline):[]; $sampled=0;
    foreach($samples as $key=>$sample) {
        if(!isset($rows[$key])) continue;
        $rows[$key]['status']=$sample['status'] ?? 'unavailable';
        if($rows[$key]['status']==='ok' && is_array($sample['data'] ?? null)) {
            $raw=$sample['data'];
            if(isset($raw['id']) && $raw['id']!==$rows[$key]['id']) { $rows[$key]['status']='unavailable'; continue; }
            $stats=rackline_container_stats($raw,(float)($sample['sampledAtMs']??round(microtime(true)*1000)),(float)($sample['monotonicSeconds']??hrtime(true)/1e9));
            if($hostNetwork[$key]??false) { $stats['networkRxBytes']=null;$stats['networkTxBytes']=null;$stats['networkIdentity']=null;$stats['available']=$stats['cpuCounters']!==null||$stats['memoryBytes']!==null||$stats['blockReadBytes']!==null; }
            $rows[$key]['stats']=$stats; $rows[$key]['status']=$stats['available']?'ok':'unavailable';
            if($stats['available']) $sampled++;
        }
    }
    foreach($rows as &$row) if($row['status']!=='ok') $row['generation']=null;
    unset($row);
    $partial=false;foreach($rows as $row) if(!in_array($row['status'],['ok','not_running'],true)) $partial=true;
    return ['schemaVersion'=>1,'sampledAtMs'=>round(microtime(true)*1000),'monotonicSeconds'=>hrtime(true)/1e9,'status'=>'ok','containers'=>array_values($rows),'requestedCount'=>count($ids),'sampledCount'=>$sampled,'partial'=>$partial];
}
function rackline_container_gpu(array $raw,float $now): array {
    $sample=rackline_container_number($raw['sampledAtMs']??null);
    $available=($raw['available']??false)===true && $sample!==null && $now-$sample>=0 && $now-$sample<=5000;
    $out=['available'=>$available,'sampledAtMs'=>$sample,'loadPercent'=>null,'videoLoadPercent'=>null,'attributedNames'=>[],'videoAttributedNames'=>[]];
    if(!$available) return $out;
    foreach(['loadPercent','videoLoadPercent'] as $field) { $n=rackline_container_number($raw[$field]??null);$out[$field]=$n!==null&&$n<=100?$n:null; }
    foreach(['processes'=>'attributedNames','videoProcesses'=>'videoAttributedNames'] as $source=>$target) {
        foreach(is_array($raw[$source]??null)?$raw[$source]:[] as $process) { $name=is_array($process)?($process['container']??null):null;if(is_string($name)&&strlen($name)<=255&&preg_match('/^[a-zA-Z0-9_.-]+$/D',$name))$out[$target][]=$name; }
        $out[$target]=array_values(array_unique($out[$target]));
    }
    return $out;
}
if(!defined('RACKLINE_CONTAINERS_NO_MAIN')) {
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    try {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405);header('Allow: GET');throw new InvalidArgumentException('GET required'); }
        if(!is_string($_GET['ids']??'')) throw new InvalidArgumentException('Invalid container IDs');
        $ids=rackline_container_ids($_GET['ids']??'');
        if(!function_exists('curl_multi_init')||!defined('CURLOPT_UNIX_SOCKET_PATH')||!is_readable(RACKLINE_CONTAINER_SOCKET)) throw new RuntimeException('Bounded Docker collector unavailable');
        $response=rackline_container_batch($ids,'rackline_container_transport');
        $handle=@fopen(RACKLINE_CONTAINER_GPU,'rb');$gpu=[];
        if($handle!==false) { $bytes=stream_get_contents($handle,RACKLINE_CONTAINER_BYTES+1);fclose($handle);if(is_string($bytes)&&strlen($bytes)<=RACKLINE_CONTAINER_BYTES){$decoded=json_decode($bytes,true);if(is_array($decoded))$gpu=$decoded;} }
        $response['gpu']=rackline_container_gpu($gpu,round(microtime(true)*1000));
        echo json_encode($response,JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch(InvalidArgumentException $e) { if(http_response_code()!==405)http_response_code(400);echo json_encode(['schemaVersion'=>1,'status'=>'invalid_request','message'=>$e->getMessage()]);
    } catch(Throwable $e) { http_response_code(503);echo json_encode(['schemaVersion'=>1,'status'=>'unavailable','message'=>'Docker telemetry unavailable']); }
}
