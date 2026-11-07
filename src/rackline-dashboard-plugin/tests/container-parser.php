<?php
declare(strict_types=1);
define('RACKLINE_CONTAINERS_NO_MAIN',true);
require dirname(__DIR__).'/source/usr/local/emhttp/plugins/rackline.dashboard/include/containers.php';
function check(bool $condition,string $why): void { if(!$condition)throw new RuntimeException($why); }
function rejects(callable $fn,string $why): void { try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($why); }
$inspectCalls=0;
function fixture_transport(callable $mock,array $override=[]): callable {
    return static function(array $paths,float $deadline)use($mock,$override):array {
        global $inspectCalls;
        if($paths && str_ends_with((string)reset($paths),'/json') && !isset($paths['inventory'])) {
            $inspectCalls++;check($deadline-hrtime(true)/1e9<=3.5,'Inspect shares bounded deadline');$out=[];
            foreach($paths as $key=>$path) {
                check(preg_match('#^/containers/([a-f0-9]{64})/json$#D',$path,$match)===1,'Exact immutable inspect path');
                $out[$key]=['status'=>'ok','data'=>array_replace_recursive(['Id'=>$match[1],'Name'=>'/alpha','State'=>['Running'=>true,'Paused'=>false,'Restarting'=>false,'StartedAt'=>'2026-11-07T12:00:00.123456789Z']],$override)];
            }
            return $out;
        }
        return $mock($paths,$deadline);
    };
}
$id=str_repeat('a',64);$other=str_repeat('b',64);
check(rackline_container_ids(strtoupper(substr($id,0,12)))===[substr($id,0,12)],'Normalize validated IDs');
check(rackline_container_ids('')===[],'Empty batch');
foreach(['../docker','abc','a?stream=true',str_repeat('a',65),implode(',',array_fill(0,13,$id))] as $input) rejects(fn()=>rackline_container_ids($input),'Reject invalid/oversized ID list');
check(rackline_container_ids($id.','.$id)===[$id],'Deduplicate identical requests');
foreach(['/version','/containers/a/stats','http://remote/containers/json?all=true','/containers/'.$id.'/stop','/containers/'.$id.'/stats?stream=true'] as $path) check(!rackline_container_path($path),'No arbitrary path/mutation');
check(rackline_container_path('/containers/json?all=true'),'Fixed list allowlist');
check(rackline_container_path('/containers/'.$id.'/stats?stream=false&one-shot=true'),'Fixed one-shot allowlist');
$empty=rackline_container_stats([],1000,1);
check(!$empty['available']&&$empty['memoryBytes']===null&&$empty['networkRxBytes']===null&&$empty['blockReadBytes']===null,'Missing is not zero');
check(!rackline_container_stats(['message'=>'permission denied'],1000,1)['available'],'Docker error is unavailable');
$raw=['cpu_stats'=>['cpu_usage'=>['total_usage'=>0],'system_cpu_usage'=>500,'online_cpus'=>4],'memory_stats'=>['usage'=>0],'networks'=>['eth0'=>['rx_bytes'=>0,'tx_bytes'=>0]],'blkio_stats'=>['io_service_bytes_recursive'=>[['major'=>8,'minor'=>0,'op'=>'Read','value'=>0],['major'=>8,'minor'=>0,'op'=>'Write','value'=>0]]]];
$zero=rackline_container_stats($raw,1000,1);
check($zero['available']&&$zero['cpuCounters']['usage']===0.0&&$zero['memoryBytes']===0.0&&$zero['networkRxBytes']===0.0&&$zero['blockWriteBytes']===0.0,'Supported zero survives');
$raw['cpu_stats']=[];$raw['memory_stats']=['usage'=>100,'stats'=>['total_inactive_file'=>25,'inactive_file'=>50]];
$parsed=rackline_container_stats($raw,1000,1);check($parsed['cpuCounters']===null&&$parsed['memoryBytes']===75.0&&$parsed['memoryMode']==='working_set','Independent CPU and memory, cgroup v1 priority');
$raw['memory_stats']=['usage'=>100,'stats'=>['inactive_file'=>20]];check(rackline_container_stats($raw,1000,1)['memoryBytes']===80.0,'cgroup v2 working set');
$raw['memory_stats']=['usage'=>100,'stats'=>['inactive_file'=>200]];check(rackline_container_stats($raw,1000,1)['memoryMode']==='usage','Invalid cache cannot erase memory');
$raw['networks']=['eth0'=>['rx_bytes'=>4]];$raw['blkio_stats']['io_service_bytes_recursive']=[];
$parsed=rackline_container_stats($raw,1000,1);check($parsed['networkRxBytes']===null&&$parsed['blockReadBytes']===null,'Partial network and empty block accounting unknown');
$raw['networks']=['z'=>['rx_bytes'=>1,'tx_bytes'=>2],'a'=>['rx_bytes'=>3,'tx_bytes'=>4]];
$first=rackline_container_stats($raw,1000,1);$raw['networks']=array_reverse($raw['networks'],true);$second=rackline_container_stats($raw,2000,2);check($first['networkIdentity']===$second['networkIdentity']&&$first['networkRxBytes']===4.0,'Stable network identity');
$calls=[];$deadlineSeen=null;
$mock=function(array $paths,float $deadline)use(&$calls,&$deadlineSeen,$id,$other,$raw):array {
    $calls[]=$paths;if($deadlineSeen===null)$deadlineSeen=$deadline;check($deadline===$deadlineSeen,'One total deadline');check($deadline-hrtime(true)/1e9<=3.5,'Bounded deadline');
    if(isset($paths['inventory']))return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running'],['Id'=>$other,'Names'=>['/beta'],'State'=>'exited']]]];
    check(count($paths)===1&&reset($paths)==='/containers/'.$id.'/stats?stream=false&one-shot=true','Only resolved running immutable ID sampled');
    return [array_key_first($paths)=>['status'=>'ok','data'=>$raw,'sampledAtMs'=>1000,'monotonicSeconds'=>1]];
};
$batch=rackline_container_batch([substr($id,0,12),$other,str_repeat('c',12)],fixture_transport($mock));
check(count($calls)===2&&$inspectCalls===1&&$batch['requestedCount']===3&&$batch['sampledCount']===1&&$batch['partial'],'One inventory, bounded inspect and bounded sample batch');
check($batch['containers'][0]['generation']==='2026-11-07T12:00:00.123456789Z','Inspect generation exposed minimally');
check($batch['containers'][0]['name']==='alpha'&&$batch['containers'][1]['status']==='not_running'&&$batch['containers'][2]['id']===null,'Resolved and missing identity');
$mock=function(array $paths,float $deadline)use($id):array {if(isset($paths['inventory']))return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running']]]];return [$id=>['status'=>'timeout','data'=>null]];};
$batch=rackline_container_batch([$id],fixture_transport($mock));check($batch['partial']&&$batch['containers'][0]['status']==='timeout'&&!$batch['containers'][0]['stats']['available'],'Timeout remains explicit');
$mock=function(array $paths,float $deadline)use($id,$other):array {if(isset($paths['inventory']))return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running']]]];return [$id=>['status'=>'ok','data'=>['id'=>$other,'memory_stats'=>['usage'=>5]]]];};
check(rackline_container_batch([$id],fixture_transport($mock))['containers'][0]['status']==='unavailable','Reject mismatched returned immutable ID');
$mock=function(array $paths,float $deadline)use($id):array {check(isset($paths['inventory']),'Ambiguous prefix never sampled');return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running'],['Id'=>substr($id,0,12).str_repeat('b',52),'Names'=>['/other'],'State'=>'running']]]];};
check(rackline_container_batch([substr($id,0,12)],fixture_transport($mock))['containers'][0]['id']===null,'Ambiguous prefix unknown');
$mock=function(array $paths,float $deadline)use($id):array {if(isset($paths['inventory']))return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running','HostConfig'=>['NetworkMode'=>'host']]]]];return [$id=>['status'=>'ok','data'=>['memory_stats'=>['usage'=>0],'networks'=>['eth0'=>['rx_bytes'=>0,'tx_bytes'=>0]]]]];};
$batch=rackline_container_batch([$id],fixture_transport($mock));check($batch['containers'][0]['stats']['networkRxBytes']===null&&$batch['containers'][0]['stats']['memoryBytes']===0.0,'Host-network counters remain unknown');
foreach(['paused','restarting','exited','created','dead'] as $state) {
    $mock=function(array $paths,float $deadline)use($id,$state):array {check(isset($paths['inventory']),'Nonrunning containers never sampled');return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>$state]]]];};
    $batch=rackline_container_batch([$id],fixture_transport($mock));check($batch['containers'][0]['status']==='not_running'&&!$batch['containers'][0]['stats']['available'],'Inactive stats object is unavailable');
}
$inspectOnly=function(array $paths,float $deadline)use($id):array {check(isset($paths['inventory']),'Invalid/newly inactive inspect must skip stats');return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running']]]];};
foreach([['Id'=>$other],['Name'=>'/renamed'],['State'=>['StartedAt'=>'invalid']],['State'=>['StartedAt'=>'0001-01-01T00:00:00Z']]] as $override) {
    $batch=rackline_container_batch([$id],fixture_transport($inspectOnly,$override));check($batch['containers'][0]['status']==='unavailable'&&$batch['containers'][0]['generation']===null,'Reject changed identity or invalid generation');
}
foreach([['Running'=>false],['Paused'=>true],['Restarting'=>true]] as $change) {
    $batch=rackline_container_batch([$id],fixture_transport($inspectOnly,['State'=>$change]));check($batch['containers'][0]['status']==='not_running'&&$batch['containers'][0]['generation']===null,'Inspect rechecks latest running state');
}
$restartMock=function(array $paths,float $deadline)use($id):array {if(isset($paths['inventory']))return ['inventory'=>['status'=>'ok','data'=>[['Id'=>$id,'Names'=>['/alpha'],'State'=>'running']]]];return [$id=>['status'=>'ok','data'=>['memory_stats'=>['usage'=>10]]]];};
$before=rackline_container_batch([$id],fixture_transport($restartMock));$after=rackline_container_batch([$id],fixture_transport($restartMock,['State'=>['StartedAt'=>'2026-11-07T12:05:00Z']]));check($before['containers'][0]['generation']!==$after['containers'][0]['generation'],'Same ID restart changes generation');
check(rackline_container_path('/containers/'.$id.'/json'),'GET inspect allowlist');
$gpu=['available'=>true,'sampledAtMs'=>1000,'loadPercent'=>20,'videoLoadPercent'=>5,'processes'=>[['container'=>'alpha','pid'=>42,'name'=>'secret argument'],['container'=>'alpha'],['container'=>'../bad']],'videoProcesses'=>[['container'=>'alpha']]];
$parsed=rackline_container_gpu($gpu,2000);check($parsed['available']&&$parsed['attributedNames']===['alpha']&&!str_contains(json_encode($parsed),'secret'),'Only attributed names, no processes');
check(!rackline_container_gpu($gpu,7000)['available']&&rackline_container_gpu($gpu,7000)['attributedNames']===[],'Stale GPU does not imply activity');
check(!rackline_container_gpu($gpu,500)['available'],'Future GPU sample unavailable');
$source=file_get_contents(dirname(__DIR__).'/source/usr/local/emhttp/plugins/rackline.dashboard/include/containers.php');
foreach(["CURLOPT_HTTPGET=>true","CURLOPT_FOLLOWLOCATION=>false","CURLOPT_PROXY=>''","CURLOPT_UNIX_SOCKET_PATH=>RACKLINE_CONTAINER_SOCKET","count(\$active)<4","CURLOPT_TIMEOUT_MS=>min(1200,\$remaining)","RACKLINE_CONTAINER_BYTES = 1048576"] as $guard)check(str_contains($source,$guard),'Transport safety boundary: '.$guard);
foreach(['shell_exec(', 'file_put_contents(', 'CURLOPT_POST', 'CURLOPT_CUSTOMREQUEST'] as $forbidden)check(!str_contains($source,$forbidden),'No writes or shell: '.$forbidden);
echo "Rackline container parser and mock transport checks passed.\n";
