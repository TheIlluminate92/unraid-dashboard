<?php
declare(strict_types=1);
if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) throw new RuntimeException('Linux lifecycle test requires posix and pcntl');
function check(bool $pass, string $why): void { if (!$pass) throw new RuntimeException($why); }
$tmp = sys_get_temp_dir() . '/waz-gpu-test-' . bin2hex(random_bytes(8));
mkdir($tmp);
$source = file_get_contents(dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/gpu-sampler.php');
$source = str_replace(['/var/run/waz.dashboard','/boot/config/plugins/waz.dashboard/waz.dashboard.cfg'], [$tmp, $tmp.'/config'], $source);
$source = str_replace("waz_gpu_executable(['/usr/bin/intel_gpu_top', '/usr/local/bin/intel_gpu_top'])", var_export($tmp.'/sampler',true), $source);
$source = str_replace('$card = waz_gpu_card($config);', '$card = "card0";', $source);
file_put_contents($tmp.'/worker.php',$source);
file_put_contents($tmp.'/sampler', "#!/bin/bash\necho \$\$ > " . escapeshellarg($tmp.'/child.pid') . "\ntrap 'exit 0' TERM INT\nwhile true; do echo '{\"engines\":{\"Video\":{\"busy\":1}},\"clients\":{}}'; sleep 0.1; done\n");
chmod($tmp.'/sampler',0755);
$worker = proc_open([PHP_BINARY,$tmp.'/worker.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$tmp.'/errors','a']], $pipes);
$child = 0;
try {
    $deadline = microtime(true)+5;
    do { if (is_file($tmp.'/gpu.json') && is_file($tmp.'/child.pid')) break; usleep(50000); clearstatcache(); } while (microtime(true)<$deadline);
    check(is_file($tmp.'/gpu.json'), 'Worker did not publish a sample');
    $child = (int)file_get_contents($tmp.'/child.pid');
    check($child > 1 && posix_kill($child,0), 'Direct child is running');
    $duplicate = proc_open([PHP_BINARY,$tmp.'/worker.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$tmp.'/errors','a']], $pipes);
    check(proc_close($duplicate)===1, 'Duplicate worker must be rejected by lifetime lock');
    check((int)file_get_contents($tmp.'/child.pid')===$child, 'Duplicate did not create another sampler');
    proc_terminate($worker);
    $deadline=microtime(true)+5;
    do { if (!proc_get_status($worker)['running']) break; usleep(50000); } while(microtime(true)<$deadline);
    check(!proc_get_status($worker)['running'], 'Worker did not stop');
    check(!posix_kill($child,0), 'Sampler child was orphaned');
    echo "GPU singleton and child cleanup passed.\n";
} finally {
    if (is_resource($worker)) { proc_terminate($worker,9); proc_close($worker); }
    if ($child>1 && posix_kill($child,0)) posix_kill($child,9);
    foreach (glob($tmp.'/*') as $file) unlink($file); rmdir($tmp);
}
