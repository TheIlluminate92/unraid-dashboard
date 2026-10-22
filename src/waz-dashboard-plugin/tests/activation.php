<?php
declare(strict_types=1);
// Exercise actual installer activation scripts entirely under a private temp root.
$manifest = simplexml_load_file($argv[1], 'SimpleXMLElement', LIBXML_NOCDATA);
$name = (string) $manifest['name'];
$activation = '';
foreach ($manifest->FILE as $file) if ((string)$file['Run'] === '/bin/bash' && (string)$file['Method'] !== 'remove') $activation = (string)$file->INLINE;
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function clean(string $dir): void { foreach (new FilesystemIterator($dir) as $p) { if ($p->isDir() && !$p->isLink()) clean($p->getPathname()); else unlink($p->getPathname()); } rmdir($dir); }
foreach (['success','invalid-php','invalid-icon','failed-start','failed-stop'] as $scenario) {
    $root = sys_get_temp_dir() . '/plugin-activation-' . bin2hex(random_bytes(8));
    mkdir($root);
        $map = ['/usr/local/emhttp/plugins'=>$root.'/plugins','/boot/config/plugins'=>$root.'/config','/var/run'=>$root.'/run','/var/tmp'=>$root.'/tmp'];
    $live = $root . '/plugins/' . $name;
    $staged = $root . '/plugins/.' . $name . '.staged';
    $previous = $root . '/plugins/.' . $name . '.previous';
    try {
        foreach ($manifest->FILE as $file) {
            $target = (string)$file['Name'];
            if ($target === '') continue;
            $target = strtr($target,$map);
            check(str_starts_with($target,$staged.'/'), 'All payload files must be staged');
            if (!is_dir(dirname($target))) mkdir(dirname($target),0755,true);
            $contents = (string)$file->INLINE;
            file_put_contents($target, (string)$file['Type'] === 'base64' ? base64_decode(trim($contents), true) : ltrim(strtr($contents,$map), "\r\n"));
        }
        mkdir($live . '/scripts',0755,true);
        mkdir($root . '/config/' . $name,0755,true);
        file_put_contents($live.'/sentinel','previous');
        $config = $name === 'md12xx.fancontrol' ? 'config.json' : 'waz.dashboard.cfg';
        $saved = $name === 'md12xx.fancontrol' ? '{"enabled":false,"shelves":[]}' : "DISPLAY_NAME=Saved\n";
        file_put_contents($root . '/config/' . $name . '/' . $config,$saved);
        file_put_contents($live.'/scripts/start.sh',"#!/bin/bash\necho restored > " . escapeshellarg($root.'/restored') . "\n");
        file_put_contents($live.'/scripts/stop.sh',"#!/bin/bash\nexit " . ($scenario==='failed-stop'?'1':'0') . "\n");
        chmod($live.'/scripts/start.sh',0755); chmod($live.'/scripts/stop.sh',0755);
        file_put_contents($staged.'/scripts/start.sh',"#!/bin/bash\nexit " . ($scenario==='failed-start'?'1':'0') . "\n");
        file_put_contents($staged.'/scripts/stop.sh',"#!/bin/bash\nexit 0\n");
        if ($scenario === 'invalid-php') file_put_contents($staged.'/include/' . ($name === 'md12xx.fancontrol' ? 'common.php' : 'metrics.php'), '<?php syntax error!');
        if ($scenario === 'invalid-icon') file_put_contents($staged.'/images/waz.dashboard.png', 'invalid PNG');
        file_put_contents($root.'/activate.sh',strtr($activation,$map));
        $out = [];
        exec('bash '.escapeshellarg($root.'/activate.sh').' 2>&1',$out,$status);
        check(($status===0) === ($scenario==='success'), $scenario . ': ' . implode("\n",$out));
        if ($scenario === 'success') check(is_file($previous.'/sentinel') && !is_file($live.'/sentinel'), 'Success retains previous runtime');
        else check(is_file($live.'/sentinel'), 'Failure preserves/restores old runtime');
        if ($scenario === 'failed-start') check(is_file($root.'/restored'), 'Previous runtime restarted after activation failure');
        check(str_starts_with(file_get_contents($root . '/config/' . $name . '/' . $config),$saved), 'Existing configuration preserved; only absent defaults may be appended');
    } finally { clean($root); }
}
echo "Staged validation, failed start/stop and runtime rollback passed.\n";
