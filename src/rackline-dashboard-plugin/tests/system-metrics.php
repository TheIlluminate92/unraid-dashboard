<?php
/** Synthetic-only proc/sysfs fixtures. Does not read a live host or run probes. */
declare(strict_types=1);
define('RACKLINE_METRICS_NO_MAIN', true);
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/metrics.php';

$assertions = 0;
function check(bool $value, string $message): void {
    global $assertions;
    $assertions++;
    if (!$value) throw new RuntimeException($message);
}
function fixture(string $root, string $path, string $content): void {
    $file = $root . $path;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, $content);
}
function cpu_fixture(string $root, int $cpu, ?int $package, ?int $core, ?string $online = null): void {
    $base = '/sys/devices/system/cpu/cpu' . $cpu;
    if ($package !== null) fixture($root, $base . '/topology/physical_package_id', (string) $package);
    if ($core !== null) fixture($root, $base . '/topology/core_id', (string) $core);
    if ($online !== null) fixture($root, $base . '/online', $online);
}
function sensor_fixture(string $root, int $hwmon, string $driver, int $sensor, string $label, string $value): void {
    $base = '/sys/class/hwmon/hwmon' . $hwmon;
    fixture($root, $base . '/name', $driver);
    fixture($root, $base . '/temp' . $sensor . '_label', $label);
    fixture($root, $base . '/temp' . $sensor . '_input', $value);
}
function remove_fixture(string $root): void {
    if (!is_dir($root)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($root);
}

$root = sys_get_temp_dir() . '/rackline-system-fixture-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $cpu = rackline_metrics_cpu_counters("cpu 100 20 30 400 5 6 7 8 50 10\ncpu0 20 0 5 80 1 2 3 4 10 0\nctxt 3\n");
    check($cpu['cpu']['total'] === 576.0 && $cpu['cpu']['idle'] === 405.0, 'guest and guest_nice must not be counted twice');
    check($cpu['cpu0']['total'] === 115.0 && $cpu['cpu0']['idle'] === 81.0, 'all eight non-guest CPU fields retained');
    check(rackline_metrics_cpu_counters('cpu 0 0 0 0')['cpu']['total'] === 0.0, 'actual zero CPU counters retained');
    foreach (['cpu 1 2 3', 'cpu 1 -2 3 4', 'cpu 1 x 3 4', 'cpu 1 2 3 4 0 NaN', 'cpu 9007199254740992 0 0 0', 'cpu 9007199254740991 1 0 0', "cpu 1 2 3 4\ncpu 1 2 3 4"] as $bad)
        check(rackline_metrics_cpu_counters($bad)['cpu']['total'] === null, 'malformed or imprecise CPU sample is unknown');
    check(rackline_metrics_cpu_counters(null) === [], 'missing proc/stat is not idle');
    check(rackline_metrics_counter('NaN') === null && rackline_metrics_counter('1e6') === null && rackline_metrics_counter('0') === 0.0, 'strict safe counters');

    $memory = rackline_metrics_memory("MemTotal: 10000 kB\nMemAvailable: 7000 kB\nCached: 3000 kB\nSReclaimable: 1000 kB\nShmem: 500 kB\n");
    check($memory['totalBytes'] === 10240000.0 && $memory['availableBytes'] === 7168000.0, 'memory uses kernel kB as KiB');
    check($memory['cachedBytes'] === 3584000.0, 'page cache includes reclaimable and subtracts shmem');
    check($memory['ecc'] === null && $memory['hardwareSummary'] === null, 'no unsupported DIMM or ECC claims');
    foreach ([null, 'MemTotal: 1 kB', "Cached: 1 kB\nSReclaimable: 1 kB", "Cached: 1 kB\nSReclaimable: 1 kB\nShmem: 3 kB", "Cached: 9007199254740991 kB\nSReclaimable: 1 kB\nShmem: 0 kB"] as $bad)
        check(rackline_metrics_memory($bad)['cachedBytes'] === null, 'missing/inconsistent cache accounting remains null');
    check(rackline_metrics_memory("MemTotal: 1 kB\nMemAvailable: 2 kB")['availableBytes'] === null, 'impossible available memory not clamped');
    check(rackline_metrics_memory("MemTotal: 1 kB\nMemAvailable: 0 kB")['availableBytes'] === 0.0, 'actual zero available accepted');

    // Package IDs and hwmon numbers deliberately disagree. Core 0 exists on both sockets.
    cpu_fixture($root, 0, 0, 0); cpu_fixture($root, 8, 0, 0, '1');
    cpu_fixture($root, 2, 2, 0, '1'); cpu_fixture($root, 10, 2, 0, '1');
    cpu_fixture($root, 12, 2, 1, '0');
    $counters = rackline_metrics_cpu_counters("cpu 10 0 0 20\ncpu0 1 0 0 2\ncpu8 1 0 0 2\ncpu2 1 0 0 2\ncpu10 1 0 0 2\ncpu12 1 0 0 2");
    $topology = rackline_metrics_topology($root, $counters);
    check(count($topology) === 2 && $topology[1]['packageId'] === 2, 'noncontiguous package IDs preserved, offline CPUs omitted');
    check(array_column($topology[0]['threads'], 'cpuId') === [0, 8], 'SMT logical IDs preserved');
    sensor_fixture($root, 9, 'coretemp', 1, 'Package id 0', '40000');
    sensor_fixture($root, 9, 'coretemp', 2, 'Core 0', '38000');
    sensor_fixture($root, 1, 'coretemp', 1, 'Package id 2', '62000');
    sensor_fixture($root, 1, 'coretemp', 2, 'Core 0', '59000');
    $temps = rackline_metrics_temperatures($root, $topology);
    check($temps['cores']['0:0'] === 38.0 && $temps['cores']['2:0'] === 59.0, 'core temperatures are socket scoped');
    check($temps['package'] === 62.0 && $temps['packageCoverageComplete'], 'headline is hottest fully covered package');
    fixture($root, '/sys/class/hwmon/hwmon1/temp1_fault', '1');
    $temps = rackline_metrics_temperatures($root, $topology);
    check($temps['package'] === null && !$temps['packageCoverageComplete'], 'lost socket sensor cannot turn into lower headline maximum');
    unlink($root . '/sys/class/hwmon/hwmon1/temp1_fault');
    fixture($root, '/sys/class/hwmon/hwmon1/temp1_input', 'not available');
    check(rackline_metrics_temperatures($root, $topology)['package'] === null, 'malformed package temperature is unknown');
    fixture($root, '/sys/class/hwmon/hwmon1/temp1_input', '62000');
    sensor_fixture($root, 3, 'coretemp', 1, 'Core 1', '48000');
    check(!isset(rackline_metrics_temperatures($root, $topology)['cores']['0:1']), 'unidentified multisocket sensors cannot invent socket zero');
    sensor_fixture($root, 4, 'k10temp', 1, 'Tctl', '95000');
    check(rackline_metrics_temperatures($root, $topology)['package'] === 62.0, 'AMD Tctl offset is not mistaken for package temperature');
    sensor_fixture($root, 5, 'coretemp', 1, 'Package id 2', '61000');
    check(rackline_metrics_temperatures($root, $topology)['package'] === null, 'conflicting duplicate package sensors are unknown');
    remove_fixture($root . '/sys/class/hwmon/hwmon5');

    $v4 = "Iface Destination Gateway Flags RefCnt Use Metric Mask MTU Window IRTT\neth0 00000000 01000000 0003 0 0 100 00000000 0 0 0\nbr0 00000000 01000000 0003 0 0 10 00000000 0 0 0\neth1 00000000 01000000 0000 0 0 0 00000000 0 0 0\n";
    check(rackline_metrics_route($v4) === 'br0', 'lowest metric active default route selected');
    check(rackline_metrics_route('eth0 00000000 00000000 0201 0 0 0 00000000') === null, 'reject route cannot masquerade as network');
    check(rackline_metrics_route('../../bad 00000000 00000000 0003 0 0 0 00000000') === null, 'route interface cannot escape sysfs');
    check(rackline_metrics_route('eth0 00000000 00000000 ffffffffffffffffffffffffffff 0 0 0 00000000') === null, 'malformed route flags cannot overflow bit operations');
    $v6 = str_repeat('0', 32) . ' 00 ' . str_repeat('0', 32) . ' 00 ' . str_repeat('0', 32) . ' 00000064 00000000 00000000 00000001 eth6';
    check(rackline_metrics_route($v6, true) === 'eth6', 'IPv6-only default route supported');
    check(rackline_metrics_route(str_replace('00000001 eth6', 'ffffffffffffffffffffffff eth6', $v6), true) === null, 'IPv6 flags must fit native field width');
    fixture($root, '/proc/net/route', $v4);
    fixture($root, '/sys/class/net/br0/operstate', 'up');
    fixture($root, '/sys/class/net/br0/ifindex', '7');
    fixture($root, '/sys/class/net/br0/speed', '-1');
    fixture($root, '/sys/class/net/br0/duplex', 'unknown');
    fixture($root, '/sys/class/net/br0/statistics/rx_bytes', '0');
    fixture($root, '/sys/class/net/br0/statistics/tx_bytes', '200');
    $network = rackline_metrics_network($root);
    check($network['interface'] === 'br0' && $network['identity'] === 'br0:7' && $network['selection'] === 'ipv4-default-route', 'interface identity derives from selected kernel device');
    check($network['counters']['rxBytes'] === 0.0 && $network['counters']['txBytes'] === 200.0, 'actual zero network counters retained');
    check($network['speedMbps'] === null && $network['duplex'] === null && $network['counters']['rxDropped'] === null, 'missing/unsupported network fields remain unknown');
    unlink($root . '/sys/class/net/br0/statistics/rx_bytes');
    fixture($root, '/sys/class/net/br0/operstate', 'unknown');
    $network = rackline_metrics_network($root);
    check($network['counters']['rxBytes'] === null && $network['up'] === null, 'source loss cannot become zero rate or down link');
    fixture($root, '/sys/class/net/br0/ifindex', '0');
    check(rackline_metrics_network($root)['identity'] === null, 'invalid kernel interface index does not create identity');
    fixture($root, '/sys/class/net/br0/ifindex', '7');
    fixture($root, RACKLINE_METRICS_CONFIG, 'PRIMARY_INTERFACE="bond1"');
    fixture($root, '/sys/class/net/bond1/operstate', 'down');
    fixture($root, '/sys/class/net/bond1/ifindex', '8');
    fixture($root, '/sys/class/net/bond1/bonding/slaves', 'eth2 eth3');
    fixture($root, '/sys/class/net/eth2/operstate', 'up');
    fixture($root, '/sys/class/net/eth2/speed', '10000');
    fixture($root, '/sys/class/net/eth2/duplex', 'full');
    fixture($root, '/proc/net/bonding/bond1', "Bonding Mode: IEEE 802.3ad Dynamic link aggregation\nSlave Interface: eth2\nMII Status: up\nSlave Interface: eth3\nLink Failure Count: 9\n");
    $network = rackline_metrics_network($root);
    check($network['interface'] === 'bond1' && $network['selection'] === 'configured' && $network['up'] === false, 'Rackline-specific interface config overrides route');
    check($network['mode'] === 'LACP' && count($network['members']) === 2 && $network['members'][0]['speedMbps'] === 10000.0, 'bond metadata is read without commands');
    check($network['members'][0]['linkFailureCount'] === null && $network['members'][1]['linkFailureCount'] === 9.0, 'bond member failure count cannot leak from next member');
    fixture($root, RACKLINE_METRICS_CONFIG, 'PRIMARY_INTERFACE="../bad"');
    check(rackline_metrics_network($root)['interface'] === 'br0', 'invalid configured interface cannot redirect reads');
    unlink($root . '/proc/net/route');
    fixture($root, '/proc/net/ipv6_route', $v6);
    check(rackline_metrics_network($root)['interface'] === 'eth6', 'IPv6 fallback used without IPv4 route');

    fixture($root, '/proc/stat', "cpu 100 20 30 400 5 6 7 8 50 10\ncpu0 20 0 5 80\ncpu8 20 0 5 80\ncpu2 20 0 5 80\ncpu10 20 0 5 80\n");
    fixture($root, '/proc/cpuinfo', "processor : 0\nmodel name : Synthetic dual package CPU\n");
    fixture($root, '/proc/meminfo', "MemTotal: 10000 kB\nMemAvailable: 7000 kB\nCached: 3000 kB\nSReclaimable: 1000 kB\nShmem: 500 kB\n");
    fixture($root, '/proc/uptime', '120.25 88.5');
    fixture($root, '/proc/sys/kernel/random/boot_id', '00000000-0000-4000-8000-000000000001');
    fixture($root, '/proc/net/route', $v4);
    fixture($root, '/sys/class/net/br0/statistics/rx_bytes', '100');
    $result = rackline_metrics_snapshot($root, 123456);
    check($result['status'] === 'ok' && $result['sampledAtMs'] === 123456 && $result['monotonicSeconds'] === 120.25, 'complete snapshot and fixture timestamps');
    check($result['bootId'] === '00000000-0000-4000-8000-000000000001', 'native boot identity retained for rate boundaries');
    check($result['cpu']['threadCount'] === 4 && $result['cpu']['physicalCoreCount'] === 2 && $result['cpu']['model'] === 'Synthetic dual package CPU', 'frontend CPU schema preserved');
    check($result['cpu']['cores'][0]['tempC'] === 38.0 && $result['cpu']['cores'][1]['tempC'] === 59.0 && $result['cooling']['cpuTempC'] === 62.0, 'topology joins package-qualified temperature labels');
    check(json_decode(json_encode($result, JSON_THROW_ON_ERROR))->cpu->counters instanceof stdClass, 'CPU counter map is always JSON object');
    check($result['cooling']['coolantTempC'] === null && $result['cooling']['flowLph'] === null, 'absent optional cooling stays null');
    unlink($root . '/sys/class/net/br0/statistics/rx_bytes');
    check(rackline_metrics_snapshot($root, 123457)['status'] === 'partial', 'one unavailable source does not discard remaining valid metrics');
    fixture($root, '/sys/class/hwmon/hwmon8/name', 'highflownext');
    fixture($root, '/sys/class/hwmon/hwmon8/temp1_label', 'Coolant temp');
    fixture($root, '/sys/class/hwmon/hwmon8/temp1_input', '29000');
    fixture($root, '/sys/class/hwmon/hwmon8/fan1_label', 'Flow [dL/h]');
    fixture($root, '/sys/class/hwmon/hwmon8/fan1_input', '900');
    check(rackline_metrics_coolant($root) === ['coolantTempC' => 29.0, 'flowLph' => 90.0], 'native optional flow units converted');

    $empty = $root . '/empty'; mkdir($empty);
    $missing = rackline_metrics_snapshot($empty, 123458);
    check($missing['status'] === 'unavailable' && isset($missing['error']), 'entire source loss explicitly unavailable');
    check($missing['cpu']['threadCount'] === null && $missing['cpu']['physicalCoreCount'] === null && $missing['cpu']['model'] === null, 'no invented host CPU metadata');
    check($missing['network']['interface'] === null && $missing['network']['up'] === null && $missing['memory']['totalBytes'] === null, 'missing kernel data never falsely healthy/zero');
    check($missing['monotonicSeconds'] === null && $missing['bootId'] === null, 'missing native clocks and identity unknown');
    fixture($empty, '/proc/stat', "cpu 1 2 3 4\ncpu3 1 2 3 4");
    $fallback = rackline_metrics_snapshot($empty, 123459);
    check($fallback['cpu']['threadCount'] === 1 && $fallback['cpu']['physicalCoreCount'] === null, 'logical CPUs retained despite unavailable sysfs topology');
    check($fallback['cpu']['cores'][0]['threads'][0]['cpuId'] === 3 && $fallback['cpu']['cores'][0]['coreId'] === null && $fallback['cpu']['cores'][0]['packageId'] === null, 'fallback does not fabricate physical IDs');
    cpu_fixture($empty, 3, 4, 7);
    sensor_fixture($empty, 7, 'k10temp', 1, 'Tdie', '51000');
    sensor_fixture($empty, 7, 'k10temp', 2, 'Tctl', '71000');
    check(rackline_metrics_snapshot($empty, 123460)['cooling']['cpuTempC'] === 51.0, 'single-package AMD Tdie supported without Tctl offset');
    fixture($empty, '/proc/sys/kernel/random/boot_id', 'malformed');
    check(rackline_metrics_snapshot($empty, 123461)['bootId'] === null, 'malformed boot identity cannot become valid baseline');

    // A fixture is intentionally incapable of causing runtime commands/writes.
    $source = file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/metrics.php');
    check(!preg_match('/waz[._]|shell_exec\s*\(|\bexec\s*\(|\bsystem\s*\(|proc_open\s*\(|popen\s*\(|file_put_contents\s*\(|\bmkdir\s*\(|\$_(?:GET|POST|REQUEST)\b/i', $source), 'collector has no prior-plugin dependency, commands, state writes, or request-controlled file paths');
    echo "System metrics fixtures passed ($assertions assertions)\n";
} finally {
    remove_fixture($root);
}
