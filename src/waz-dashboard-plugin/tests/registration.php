<?php
// Exercise the actual first installer FILE using Unraid's inline-script naming.
$manifest = simplexml_load_file($argv[1], 'SimpleXMLElement', LIBXML_NOCDATA);
$name = (string)$manifest['name'];
$guard = (string)$manifest->FILE[0]->INLINE;
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/registration-test-' . bin2hex(random_bytes(8));
mkdir($root); mkdir($root . '/boot'); mkdir($root . '/log');
$guard = str_replace(['/boot/config/plugins', '/var/log/plugins'], [$root . '/boot', $root . '/log'], $guard);
function run_guard($root, $filename, $guard, $expected) {
    $path = $root . '/' . $filename;
    file_put_contents($path, $guard);
    exec('bash ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    unlink($path);
    if ($status !== $expected) throw new RuntimeException(implode("\n", $output));
}
try {
    run_guard($root, 'inline1-' . $name . '.sh', $guard, 0);
    run_guard($root, 'inline1-' . $name . '-test.sh', $guard, 1);
    run_guard($root, 'unknown.sh', $guard, 1);
    $duplicate = $root . '/boot/alias.plg';
    $data = '<PLUGIN name="' . $name . '" version="old"/>';
    file_put_contents($duplicate, $data);
    symlink($duplicate, $root . '/log/alias.plg');
    run_guard($root, 'inline1-' . $name . '.sh', $guard, 1);
    if (file_get_contents($duplicate) !== $data || !is_link($root . '/log/alias.plg')) {
        throw new RuntimeException('Registration guard mutated existing files');
    }
    unlink($root . '/log/alias.plg'); unlink($duplicate);
    file_put_contents($root . '/boot/unrelated.plg', '<PLUGIN name="unrelated"/>');
    run_guard($root, 'inline1-' . $name . '.sh', $guard, 0);
    unlink($root . '/boot/unrelated.plg');
    echo "Canonical registration guard passed.\n";
} finally {
    foreach (glob($root . '/log/*') ?: [] as $path) unlink($path);
    foreach (glob($root . '/boot/*') ?: [] as $path) unlink($path);
    rmdir($root . '/log'); rmdir($root . '/boot'); rmdir($root);
}
