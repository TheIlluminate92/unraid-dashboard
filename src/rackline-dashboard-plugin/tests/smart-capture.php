<?php
/** Offline only: synthetic files, mock capture calls, and PHP fixture child processes. */
declare(strict_types=1);
define('RACKLINE_SMART_REPORT_NO_MAIN', true);
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/smart-report.php';
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/smart-capture-lib.php';
$checks = 0;
set_error_handler(static function (int $severity, string $message): bool { if (error_reporting() & $severity) throw new RuntimeException($message); return false; });
function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function rejects(callable $call, string $message): void { $rejected = false; try { $call(); } catch (Throwable $e) { $rejected = true; } check($rejected, $message); }
$root = sys_get_temp_dir() . '/rackline-capture-fixture-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$raw = ['name' => 'cache', 'device' => 'sde', 'id' => 'SYNTHETIC_MODEL_S1', 'type' => 'Cache', 'transport' => 'ata'];
$parts = ['cache', $raw, ['variables' => 'foo=1', 'all' => '', 'one' => ''], '/sys/devices/fixture/block/sde', '8:64', '18446744073709551615', '12345678-1234-1234-1234-123456789abc', ['block' => true, 'numbers' => '8:64'], false];
$binding = rackline_capture_binding_from_parts(...$parts);
check($binding['diskseq'] === '18446744073709551615', 'diskseq never truncated');
check($binding['numbers'] === '8:64' && strlen($binding['token']) === 64, 'bounded current locator');
$benign = $parts; $benign[2]['variables'] = 'foo=2';
check(rackline_capture_binding_from_parts(...$benign)['token'] === $binding['token'], 'unrelated counters do not invalidate binding');
foreach ([0 => 'cache2', 3 => '/sys/devices/replaced/block/sde', 5 => '18446744073709551614', 6 => '12345678-1234-1234-1234-123456789abd'] as $field => $value) {
    $p = $parts; $p[$field] = $value; if ($field === 0) $p[1]['name'] = $value;
    check(rackline_capture_binding_from_parts(...$p)['token'] !== $binding['token'], 'binding field ' . $field);
}
foreach (['device' => 'sdf', 'id' => 'OTHER_EXACT_ID', 'transport' => 'scsi'] as $key => $value) {
    $p = $parts; $p[1][$key] = $value;
    check(rackline_capture_binding_from_parts(...$p)['token'] !== $binding['token'], 'raw identity field ' . $key);
}
foreach (['../sde', 'sde1', '/dev/sde', 'sde;id', "sde\n", 'nvme0n1'] as $device) {
    $p = $parts; $p[1]['device'] = $device; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'invalid device');
}
foreach (['0', '01', '1e3', '1.1', '-1', "1\n", str_repeat('1', 21)] as $value) {
    $p = $parts; $p[5] = $value; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'diskseq syntax');
}
foreach (RACKLINE_CAPTURE_FIELDS as $field) {
    foreach (['-d sat', '', '-1', "a;touch /tmp/no"] as $value) {
        $p = $parts; $p[1][$field] = $value; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'native custom option unsupported');
        $p = $parts; $p[2]['one'] = '[SYNTHETIC_MODEL_S1]' . "\n" . $field . '=' . json_encode($value);
        rejects(fn() => rackline_capture_binding_from_parts(...$p), 'merged custom option unsupported');
    }
}
foreach (["foo=\x80", "foo=\0", '[bad', 'smType[]=sat', '"smType"="-d sat"'] as $config) {
    $p = $parts; $p[2]['all'] = $config; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'invalid controller source');
}
$p = $parts; $p[7]['numbers'] = '8:65'; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'node rdev mismatch');
$p = $parts; $p[7]['block'] = false; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'nonblock node');
$p = $parts; $p[8] = true; rejects(fn() => rackline_capture_binding_from_parts(...$p), 'partition node');
$csrf = str_repeat('C', 32);
$base = ['action' => 'prepare', 'name' => 'cache', 'device' => 'sde', 'identity' => $binding['identity'], 'csrf_token' => $csrf];
$report = "smartctl 7.5\n=== START OF INFORMATION SECTION ===\nDevice Model: Synthetic\n=== START OF READ SMART DATA SECTION ===\nSMART overall-health self-assessment test result: PASSED\n";
$runs = 0;
$runner = static function (array $b) use (&$runs, $report): array { $runs++; return ['kind' => 'finished', 'exit' => 0, 'stdout' => $report, 'stderr' => '']; };
$bind = static fn(string $name): array => $binding;
$mono = 1000;
function request(array|string $request, string $dir, ?callable $bindOverride = null, ?callable $runOverride = null, string $method = 'POST', bool $auth = true, string $reference = '', string $query = '', string $type = 'application/x-www-form-urlencoded'): array {
    global $csrf, $bind, $runner, $mono;
    return rackline_capture_response($method, $query, $type, is_string($request) ? $request : http_build_query($request, '', '&'), $auth,
        $reference === '' ? $csrf : $reference, $bindOverride ?? $bind, $runOverride ?? $runner, $dir, $mono, 1700000000000);
}
$dir = $root . '/cache';
$prepared = request($base, $dir);
check($prepared['status'] === 200 && $prepared['requiresWakeConfirmation'] === true, 'prepare requires wake confirmation');
check(!file_exists($dir) && $runs === 0, 'prepare never creates cache or invokes reader');
$capture = $base + ['binding' => $prepared['binding'], 'requestId' => $prepared['requestId'], 'wake' => 'confirmed']; $capture['action'] = 'capture';
foreach (['GET', 'HEAD', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) check(request($capture, $dir, method: $method)['status'] === 405, 'method rejected');
check(request($capture, $dir, auth: false)['status'] === 403, 'auth rejected');
check(request($capture, $dir, reference: str_repeat('D', 32))['status'] === 403, 'CSRF rejected after middleware consumption');
foreach (['name=cache', http_build_query($base) . '&name=cache', http_build_query($base) . '&%6Eame=cache', http_build_query($base) . '&file=/etc/passwd', http_build_query($base) . '&name[]=sde', str_repeat('x', 4097), 'action=prepare&name=%ZZ', http_build_query($base) . '&', http_build_query($base) . ';file=abc'] as $body)
    check(in_array(request($body, $dir)['status'], [400, 403], true), 'invalid raw fields');
foreach (['binding', 'requestId', 'wake'] as $key) { $r = $capture; unset($r[$key]); check(request($r, $dir)['status'] === 400, 'required capture input'); }
$r = $capture; $r['wake'] = 'no'; check(request($r, $dir)['status'] === 400, 'no implicit wake permission');
$r = $capture; $r['device'] = 'sdf'; check(request($r, $dir)['status'] === 409, 'selected device changed');
$r = $capture; $r['binding'] = str_repeat('0', 64); check(request($r, $dir)['status'] === 409, 'stale bound token');
$r = $capture; $r['requestId'] = '1-' . str_repeat('a', 32); check(request($r, $dir)['status'] === 409, 'old request cannot replay after ledger pruning');
$r = $capture; $r['requestId'] = '2000-' . str_repeat('a', 32); check(request($r, $dir)['status'] === 409, 'future request rejected');
check(request($capture, $dir, query: 'x=1')['status'] === 400, 'query forbidden');
check(request($capture, $dir, type: 'application/json')['status'] === 400, 'unrecognized body type');
check($runs === 0 && !file_exists($dir), 'invalid requests never invoke runner or create cache');
$result = request($capture, $dir);
check($result['status'] === 200 && $runs === 1, 'one explicit capture');
check($result['capturedAtMs'] >= 1700000000000 && $result['capturedAtMs'] < 1700000003000, 'capture completion timestamp');
$record = rackline_capture_cached($binding, $dir);
check($record['text'] === $report && $record['binding'] === $binding['token'], 'atomic report and provenance');
check(($record['exitStatus'] ?? null) === 0 && !array_key_exists('health', $result), 'no fabricated health');
check(request($capture, $dir) === $result && $runs === 1, 'idempotent repeated capture');
$next = $capture; $next['requestId'] = $mono . '-' . str_repeat('a', 32);
check(request($next, $dir)['status'] === 429 && $runs === 1, 'per-device cooldown');
$lock = rackline_capture_lock($dir . '/global.lock');
check(request($next, $dir)['status'] === 503 && $runs === 1, 'concurrent global lock rejects before reader');
flock($lock, LOCK_UN); fclose($lock);
$oldBytes = file_get_contents($dir . '/' . $binding['token'] . '.json');
$mono += 61; $next['requestId'] = $mono . '-' . str_repeat('b', 32);
$badRun = static fn(array $b): array => ['kind' => 'finished', 'exit' => 2, 'stdout' => 'open failed', 'stderr' => '/private/path'];
check(request($next, $dir, runOverride: $badRun)['status'] === 502, 'failed read');
check(file_get_contents($dir . '/' . $binding['token'] . '.json') === $oldBytes, 'failed refresh preserves last good report');
foreach ([0, 8, 16, 32, 64, 128, 248] as $exit) {
    $r = rackline_capture_result(['kind' => 'finished', 'exit' => $exit, 'stdout' => $report]);
    check($r['ok'] && $r['hasWarnings'] === ($exit !== 0), 'health bits preserve report');
}
foreach ([1, 2, 4, 7, 9, 255, -1, 256, '0', null] as $exit)
    check(!rackline_capture_result(['kind' => 'finished', 'exit' => $exit, 'stdout' => $report])['ok'], 'command/transport failure status');
foreach (['', 'partial', $report . "\0", $report . "\x80", $report . str_repeat('x', RACKLINE_CAPTURE_BYTES)] as $text)
    check(!rackline_capture_result(['kind' => 'finished', 'exit' => 0, 'stdout' => $text])['ok'], 'invalid report rejected');
// Rebinding before launch and after completion must not publish stale bodies.
foreach ([2, 3] as $changeAt) {
    $calls = 0; $starts = 0; $r = $capture; $r['requestId'] = $mono . '-' . str_repeat((string)$changeAt, 32);
    $changing = static function (string $name) use (&$calls, $binding, $changeAt): array { $calls++; $b = $binding; if ($calls >= $changeAt) $b['token'] = str_repeat('f', 64); return $b; };
    $run = static function (array $b) use (&$starts, $runner): array { $starts++; return $runner($b); };
    $out = request($r, $root . '/race' . $changeAt, $changing, $run);
    check($out['status'] === 409 && $starts === ($changeAt === 2 ? 0 : 1), 'reassignment guard');
    check(!is_file($root . '/race' . $changeAt . '/' . $binding['token'] . '.json'), 'no stale report publication');
}
$uncertainDir = $root . '/uncertain';
$r = $capture; $r['requestId'] = $mono . '-' . str_repeat('d', 32);
$uncertain = request($r, $uncertainDir, runOverride: static fn(array $b): array => ['kind' => 'uncertain']);
check($uncertain['status'] === 503, 'uncertain process result');
$r['requestId'] = $mono . '-' . str_repeat('e', 32); $before = $runs;
check(request($r, $uncertainDir)['status'] === 409 && $runs === $before, 'uncertain operation blocks all retries');
// A legitimate delayed confirmation still starts a fresh server-side cooldown.
$delayedDir = $root . '/delayed';
$r = $capture; $r['requestId'] = ($mono - 100) . '-' . str_repeat('a',32);
check(request($r,$delayedDir)['status']===200,'delayed confirmation accepted');
$r['requestId'] = $mono . '-' . str_repeat('b',32);
$before=$runs;
check(request($r,$delayedDir)['status']===429 && $runs===$before,'cooldown uses server attempt, not preparation time');
$expiryDir = $root . '/expiry';
$r = $capture; $r['requestId'] = ($mono - 599) . '-' . str_repeat('a',32);
check(request($r,$expiryDir)['status']===200,'confirmation near request expiry');
$mono += 2; $r['requestId'] = $mono . '-' . str_repeat('b',32);
check(request($r,$expiryDir)['status']===429,'expired request ledger retains cooldown');
// A removed assignment after a confirmed child exit is a failed selection, not an indefinitely live process.
$missingAfterCalls=0;
$missingAfter=static function(string $name) use (&$missingAfterCalls,$binding): array { $missingAfterCalls++; if($missingAfterCalls>=3)throw new RuntimeException('gone'); return $binding; };
$r=$capture;$r['requestId']=$mono.'-'.str_repeat('c',32);
check(request($r,$root.'/removed',$missingAfter)['status']===409,'post-capture missing assignment');
$removedLedger=rackline_capture_json($root.'/removed/requests.json');
check($removedLedger['requests'][$r['requestId']]['state']==='completed','known exited process does not remain uncertain');
// Private path protections; these files are synthetic and only under this fixture directory.
$unsafe = $root . '/unsafe'; mkdir($unsafe, 0755);
check(request($capture, $unsafe)['status'] === 503, 'nonprivate directory rejected');
$linked = $root . '/linked'; symlink($dir, $linked);
check(request($capture, $linked)['status'] === 503, 'directory link rejected');
$unsafeFile = $root . '/symlink-file'; mkdir($unsafeFile, 0700); symlink($dir . '/global.lock', $unsafeFile . '/global.lock');
check(request($capture, $unsafeFile)['status'] === 503, 'lock symlink rejected');
// Corrupt ledger and fixed budgets fail closed before starting the fake reader.
$quotaDir=$root.'/quota';rackline_capture_private_dir($quotaDir);
$ledger=['schema'=>1,'boot'=>$binding['boot'],'requests'=>[]];
for($i=0;$i<128;$i++)$ledger['requests'][$mono.'-'.str_pad(dechex($i),32,'0',STR_PAD_LEFT)]=['issued'=>$mono,'attempted'=>$mono,'binding'=>hash('sha256',(string)$i),'state'=>'completed','response'=>['status'=>502,'error'=>'command_failed']];
rackline_capture_atomic($quotaDir.'/requests.json',$ledger);
$r=$capture;$r['requestId']=$mono.'-'.str_repeat('f',32);$before=$runs;
check(request($r,$quotaDir)['status']===429 && $runs===$before,'bounded idempotency ledger');
$ledger['requests']=[$r['requestId']=>['issued'=>$mono,'attempted'=>$mono,'binding'=>$binding['token'],'state'=>'broken']];
rackline_capture_atomic($quotaDir.'/requests.json',$ledger);
check(request($r,$quotaDir)['status']===503 && $runs===$before,'malformed ledger fails closed');
$spaceDir=$root.'/space';rackline_capture_private_dir($spaceDir);
$f=fopen($spaceDir.'/budget','x+b');ftruncate($f,33554433);fclose($f);chmod($spaceDir.'/budget',0600);
check(request($r,$spaceDir)['status']===507 && $runs===$before,'fixed cache byte budget');
// Direct process adapter tests use only a tiny generated PHP fixture, never smartctl.
$fixture = $root . '/fake.php';
file_put_contents($fixture, '<?php $mode=$argv[1]; if($mode==="sleep"){usleep(500000);} elseif($mode==="large"){echo str_repeat("x",300000);} elseif($mode==="stderr"){fwrite(STDERR,str_repeat("x",20000));} elseif($mode==="literal"){echo $argv[2];} else {echo "fixture";exit((int)$mode);}');
$php = getenv('RACKLINE_PHP_BIN') ?: PHP_BINARY;
foreach ([0, 8, 128] as $exit) { $out = rackline_capture_run([$php, $fixture, (string)$exit], 2); check($out['kind'] === 'finished' && $out['exit'] === $exit && $out['stdout'] === 'fixture', 'direct argv status'); }
$out = rackline_capture_run([$php, $fixture, 'literal', '$(touch /tmp/never);x'], 2);
check($out['stdout'] === '$(touch /tmp/never);x', 'argv metacharacters remain literal');
$start = microtime(true); $out = rackline_capture_run([$php, $fixture, 'sleep'], 0.05);
check($out['kind'] === 'failed' && $out['error'] === 'timeout' && microtime(true) - $start < 2, 'bounded fixture timeout');
foreach (['large', 'stderr'] as $mode) { $out = rackline_capture_run([$php, $fixture, $mode], 2); check($out['kind'] === 'failed' && $out['error'] === 'output_limit', 'bounded output'); }
// Prove the prototype is excluded from normal build input and makes no native cache writes.
$endpoint = file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/smart-capture.php');
check(str_contains($endpoint, "['executable'], '-x'") && !preg_match('/smartctl\s+-[tXsS]/', $endpoint), 'read-only argv construction');
check(!str_contains($endpoint, '$_GET[') && !str_contains($endpoint, '$_POST['), 'no client path/options injection');
check(!is_dir(__DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/experiments'), 'not packaged');
// Saved-capture retrieval is strictly passive and must rebind before returning body.
$q = ['name'=>'cache', 'device'=>'sde', 'identity'=>$binding['identity'], 'binding'=>$binding['token']];
$url = http_build_query($q);
$beforeFiles = [];
foreach (new DirectoryIterator($dir) as $f) if ($f->isFile()) $beforeFiles[$f->getFilename()] = hash_file('sha256', $f->getPathname());
for ($i=0; $i<100; $i++) {
    $download = rackline_capture_download('GET', $q, $url, true, $bind, $dir);
    check($download['status'] === 200 && str_ends_with($download['body'], $report), 'passive repeated capture download');
}
foreach ($beforeFiles as $filename=>$digest) check(hash_file('sha256', $dir.'/'.$filename) === $digest, 'download makes no file changes');
check(rackline_capture_download('POST', $q, $url, true, $bind, $dir)['status'] === 405, 'download GET only');
check(rackline_capture_download('GET', $q, $url, false, $bind, $dir)['status'] === 403, 'download auth');
check(rackline_capture_download('GET', $q, $url.'&binding='.$q['binding'], true, $bind, $dir)['status'] === 400, 'duplicate download parameter');
$qBad = $q; $qBad['device'] = 'sdf';
check(rackline_capture_download('GET', $qBad, http_build_query($qBad), true, $bind, $dir)['status'] === 404, 'download current locator required');
$changeCalls=0;
$changing = static function(string $name) use (&$changeCalls,$binding): array {$changeCalls++;$b=$binding;if($changeCalls>1)$b['token']=str_repeat('e',64);return $b;};
check(rackline_capture_download('GET',$q,$url,true,$changing,$dir)['status'] === 404,'download rebind before disclosure');
$badRecord = $record; $badRecord['sha256'] = str_repeat('0',64);
rackline_capture_atomic($dir.'/'.$binding['token'].'.json',$badRecord);
$badDownload=rackline_capture_download('GET',$q,$url,true,$bind,$dir);
check($badDownload['status']===503 && !str_contains($badDownload['body'],'Synthetic'), 'tampered provenance has no body');
rackline_capture_atomic($dir.'/'.$binding['token'].'.json',$record);
check(str_starts_with($result['reportUrl'], '/plugins/rackline.dashboard/include/smart-capture-report.php?') && !str_contains($result['reportUrl'], 'SYNTHETIC_MODEL'), 'fixed URL with opaque identity');
// Selected state GET distinguishes verified capture, proven absence, unsupported and unavailable.
$stateQuery=['name'=>'cache','device'=>'sde','identity'=>$binding['identity']];$stateUrl=http_build_query($stateQuery);
$state=rackline_capture_state('GET',$stateQuery,$stateUrl,true,$bind,$dir);
check($state['status']===200 && $state['supported'] && $state['available'] && $state['capturedAtMs']===$record['capturedAtMs'], 'state verified capture metadata');
check($state['source']==='rackline_capture' && !isset($state['text']) && !isset($state['health']), 'state no report body or fabricated health');
$missingDir=$root.'/state-missing';
$state=rackline_capture_state('GET',$stateQuery,$stateUrl,true,$bind,$missingDir);
check($state['status']===200 && $state['supported'] && !$state['available'] && !file_exists($missingDir), 'state proven missing without writes');
check(rackline_capture_state('GET',$stateQuery,$stateUrl,true,$bind,$unsafe)['status']===503,'unsafe state is unavailable, not absent');
check(rackline_capture_state('GET',$stateQuery,$stateUrl,true,$bind,$linked)['status']===503,'linked state is unavailable, not absent');
foreach(['unsupported_device','unsupported_transport','unsupported_controller_options'] as $reason){
 $unsupported=static function(string $name) use($reason):array {throw new RuntimeException($reason);};
 $state=rackline_capture_state('GET',$stateQuery,$stateUrl,true,$unsupported,$missingDir);
 check($state['status']===200 && !$state['supported'] && !$state['available'] && $state['reason']===$reason,'explicit unsupported state');
}
check(rackline_capture_state('POST',$stateQuery,$stateUrl,true,$bind,$dir)['status']===405,'state GET only');
check(rackline_capture_state('GET',$stateQuery,$stateUrl,false,$bind,$dir)['status']===403,'state auth');
check(rackline_capture_state('GET',$stateQuery,$stateUrl.'&name=cache',true,$bind,$dir)['status']===400,'state duplicate query');
$badQuery=$stateQuery;$badQuery['identity']=str_repeat('0',64);
check(rackline_capture_state('GET',$badQuery,http_build_query($badQuery),true,$bind,$dir)['status']===409,'state original selection digest');
foreach(['../cache','cache%','cache[]',"cache\n"] as $name){$badQuery=$stateQuery;$badQuery['name']=$name;check(rackline_capture_state('GET',$badQuery,http_build_query($badQuery),true,$bind,$dir)['status']===400,'invalid state name');}
$badQuery=$stateQuery;$badQuery['device']=['sde'];check(rackline_capture_state('GET',$badQuery,http_build_query($badQuery),true,$bind,$dir)['status']===400,'state array query');
$changeCalls=0;
check(rackline_capture_state('GET',$stateQuery,$stateUrl,true,$changing,$dir)['status']===409,'state rebind before metadata disclosure');
foreach ($beforeFiles as $filename=>$digest) {
 // requests ledger may have changed only through explicit earlier captures; report is unchanged by state.
 if($filename===$binding['token'].'.json')check(hash_file('sha256',$dir.'/'.$filename)===$digest,'state never refreshes capture');
}
// Cleanup only this newly created synthetic fixture tree, without following links.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) { if ($file->isLink() || $file->isFile()) unlink($file->getPathname()); else rmdir($file->getPathname()); }
rmdir($root);
echo 'SMART capture offline assertions: ' . $checks . " passed\n";
