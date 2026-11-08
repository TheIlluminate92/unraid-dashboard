#!/usr/bin/env python3
"""Offline process/concurrency and HTTP-boundary stress. Never invokes SMART."""
import concurrent.futures
import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

RUNTIME = Path(__file__).resolve().parents[1] / 'source/usr/local/emhttp/plugins/rackline.dashboard/include'
HERE = RUNTIME
PHP = os.environ.get('RACKLINE_PHP_BIN') or shutil.which('php')
checks = 0

def check(value, message):
    global checks
    checks += 1
    if not value:
        raise AssertionError(message)

with tempfile.TemporaryDirectory(prefix='rackline-capture-stress-') as temporary:
    root = Path(temporary)
    root.chmod(0o700)
    driver = root / 'driver.php'
    driver.write_text('''<?php
    define('RACKLINE_SMART_REPORT_NO_MAIN',true);
    require %s;
    require %s;
    $root=$argv[1];$nonce=$argv[2];$mode=$argv[3]??'normal';
    $raw=['name'=>'cache','device'=>'sde','id'=>'SYNTHETIC','type'=>'Cache','transport'=>'ata'];
    $b=rackline_capture_binding_from_parts('cache',$raw,[],'/sys/devices/fixture','8:64','18446744073709551615','12345678-1234-1234-1234-123456789abc',['block'=>true,'numbers'=>'8:64'],false);
    $mono=(int)(hrtime(true)/1e9);$issued=(int)file_get_contents($root.'/issued');$csrf=str_repeat('c',32);
    $q=['action'=>'capture','name'=>'cache','device'=>'sde','identity'=>$b['identity'],'csrf_token'=>$csrf,'binding'=>$b['token'],'requestId'=>$issued.'-'.$nonce,'wake'=>'confirmed'];
    if($mode==='repeat')$q['requestId']=file_get_contents($root.'/request-id');
    if($mode==='normal')file_put_contents($root.'/request-id',$q['requestId']);
    $run=static function($binding)use($root,$mode){
      file_put_contents($root.'/calls',"called\\n",FILE_APPEND|LOCK_EX);
      if($mode==='crash')exit(99);
      usleep(100000);
      return ['kind'=>'finished','exit'=>0,'stdout'=>"=== START OF INFORMATION SECTION ===\\nSynthetic\\n=== START OF READ SMART DATA SECTION ===\\n",'stderr'=>''];
    };
    $result=rackline_capture_response('POST','','application/x-www-form-urlencoded',http_build_query($q),'true'=== 'true',$csrf,fn($name)=>$b,$run,$root.'/cache',$mono,(int)(microtime(true)*1000));
    echo json_encode($result);
    ''' % (json.dumps(str(RUNTIME / 'smart-report.php')), json.dumps(str(HERE / 'smart-capture-lib.php'))))

    def run(directory, nonce='a' * 32, mode='normal'):
        result = subprocess.run([PHP, str(driver), str(directory), nonce, mode], capture_output=True, text=True, timeout=5)
        check(result.returncode == 0 and not result.stderr, 'fake driver failed: ' + result.stderr)
        return json.loads(result.stdout)

    same = root / 'same'; same.mkdir(mode=0o700); (same / 'issued').write_text(str(int(time.monotonic())))
    with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
        result = list(pool.map(lambda _: run(same), range(16)))
    check(all(r['status'] in (200, 503) for r in result), 'concurrent duplicate response status')
    check((same / 'calls').read_text().count('called') == 1, 'one process for concurrent duplicate requests')
    for _ in range(100):
        replayed = run(same, mode='repeat'); check(replayed['status'] == 200, 'idempotent replay: ' + repr(replayed))
    check((same / 'calls').read_text().count('called') == 1, 'replays never spawn')
    with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
        result = list(pool.map(lambda n: run(same, hashlib.sha256(str(n).encode()).hexdigest()[:32], 'fresh'), range(32)))
    check(all(r['status'] in (429, 503) for r in result), 'parallel distinct requests respect cooldown')
    check((same / 'calls').read_text().count('called') == 1, 'cooldown prevents flood')

    crash = root / 'crash'; crash.mkdir(mode=0o700); (crash / 'issued').write_text(str(int(time.monotonic())))
    crashed = subprocess.run([PHP, str(driver), str(crash), 'b' * 32, 'crash'], capture_output=True, text=True, timeout=5)
    check(crashed.returncode == 99, 'fake worker interrupted after durable intent')
    check(run(crash, 'c' * 32, 'fresh')['error'] == 'previous_capture_uncertain', 'crash leaves fail-closed durable intent')
    check((crash / 'calls').read_text().count('called') == 1, 'no retry after interrupted worker')

    # TERM-ignoring fake child must escalate to KILL; no hardware or shell command.
    sleeper = root / 'slow.py'
    sleeper.write_text('import signal,time\nsignal.signal(signal.SIGTERM,signal.SIG_IGN)\ntime.sleep(60)\n')
    code = 'require $argv[1];echo json_encode(rackline_capture_run([$argv[2],$argv[3]],0.15));'
    started = time.monotonic()
    slow = subprocess.run([PHP, '-r', code, str(HERE / 'smart-capture-lib.php'), shutil.which('python3'), str(sleeper)], capture_output=True, text=True, timeout=5)
    check(slow.returncode == 0 and not slow.stderr, 'slow fake process wrapper')
    check(json.loads(slow.stdout) == {'kind': 'failed', 'error': 'timeout'} and time.monotonic() - started < 3, 'TERM then KILL fixture deadline')

    # Real HTTP adapters fail closed without native middleware/session setup.
    web = root / 'web'; web.mkdir(mode=0o700)
    for name in ('smart-capture-lib.php', 'smart-capture.php', 'smart-capture-report.php', 'smart-capture-state.php'):
        shutil.copyfile(HERE / name, web / name)
    for name in ('smart-report.php', 'storage.php'):
        shutil.copyfile(RUNTIME / name, web / name)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
    with (root / 'http.log').open('w+') as log:
        server = subprocess.Popen([PHP, '-S', '127.0.0.1:' + str(port), '-t', str(web)], stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            else: raise AssertionError('fixture server startup')
            for route in ('smart-capture.php', 'smart-capture-report.php', 'smart-capture-state.php'):
                for method in ('GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'):
                    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=2)
                    conn.request(method, '/' + route, 'name=sde&csrf_token=' + 'x' * 32,
                                 {'Content-Type': 'application/x-www-form-urlencoded'})
                    response = conn.getresponse(); body = response.read(); headers = dict((k.lower(), v) for k, v in response.getheaders()); conn.close()
                    allowed = 'POST' if route == 'smart-capture.php' else 'GET'
                    check(response.status == (403 if method == allowed else 405), 'HTTP auth/method gate')
                    check(headers.get('x-content-type-options') == 'nosniff' and 'no-store' in headers.get('cache-control', ''), 'HTTP safe cache headers')
                    check(headers.get('content-security-policy') == "default-src 'none'; sandbox", 'HTTP nonactive content')
                    check(str(root).encode() not in body and b'/var/local/' not in body, 'HTTP no path leakage')
        finally:
            server.terminate(); server.wait(timeout=5)
        log.seek(0); output = log.read()
        check('Fatal error' not in output and 'Warning:' not in output, 'HTTP no PHP warnings')

print(f'SMART capture stress assertions: {checks} passed; 16 duplicate contenders, 100 replays, 32 cooldown contenders, 18 HTTP cases; fixtures only')
