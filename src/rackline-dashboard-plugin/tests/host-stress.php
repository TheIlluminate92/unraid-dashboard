<?php
/** Deterministic synthetic stress only. No live paths, sockets, commands or probes. */
declare(strict_types=1);
define('RACKLINE_METRICS_NO_MAIN', true);
define('RACKLINE_STORAGE_NO_MAIN', true);
define('RACKLINE_IO_NO_MAIN', true);
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/metrics.php';
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/storage.php';
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/io.php';

$assertions = 0;
$timings = [];
$start = hrtime(true);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function check(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (RuntimeException $error) { check(true, $message); return; }
    check(false, $message);
}
function measure(string $name, callable $fn): mixed {
    global $timings;
    $before = hrtime(true);
    $value = $fn();
    $elapsed = (hrtime(true) - $before) / 1e9;
    $timings[$name] = round($elapsed, 4);
    check($elapsed < 5.0, $name . ' must finish within a generous 5-second CI ceiling');
    return $value;
}
function put(string $root, string $path, string $value): void {
    $file = $root . $path;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, $value);
}
function disk(array $overrides = []): array {
    return array_replace(['name' => 'disk1', 'type' => 'Data', 'device' => 'sdb', 'idx' => '1',
        'model' => 'MODEL', 'id' => 'MODEL_FIXTURE-A', 'status' => 'DISK_OK', 'numErrors' => '0',
        'rotational' => '1', 'size' => '1000000', 'fsSize' => '1000000', 'fsUsed' => '250000', 'fsFree' => '750000', 'temp' => '30'], $overrides);
}
function cleanup(string $root): void {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($root);
}
$root = sys_get_temp_dir() . '/rackline-host-stress-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    // Every exact JavaScript boundary, including float-rounding traps.
    foreach (['9007199254740992', '18446744073709551615', '-1', 'NaN', 'Infinity', '1e309', '1.5', '', '1,2'] as $bad) {
        check(rackline_metrics_counter($bad) === null, 'System counters reject unsafe decimal');
        check(rackline_io_counter($bad) === null, 'I/O counters reject unsafe decimal');
        check(rackline_storage_count($bad) === null, 'Storage counts reject unsafe decimal');
    }
    check(rackline_storage_count('9007199254740991.1') === null, 'Fraction cannot round into a valid count');
    check(rackline_storage_count('0009007199254740991') === 9007199254740991, 'Exact safe maximum preserved');
    check(rackline_metrics_counter('9007199254740991') === 9007199254740991.0, 'Exact system boundary');
    check(rackline_io_counter('17592186044415', 512) === 9007199254740480.0, 'Last safe sector counter');
    check(rackline_io_counter('17592186044416', 512) === null, 'Unsafe sector counter unavailable');
    check(rackline_storage_kib_bytes('8796093022208') === null, 'Unsafe KiB conversion unavailable');
    check(rackline_storage_number('12,34') === null, 'Malformed thousands punctuation rejected');

    $stat = "cpu 1 2 3 4\ncpu0 1 2 3 4";
    check(rackline_metrics_cpu_counters($stat . "\ncpu0 2 3 4 5")['cpu0']['total'] === null, 'Duplicate CPU invalidates logical counter');
    $manyCpu = 'cpu 1 2 3 4';
    for ($i = 0; $i < 8192; $i++) $manyCpu .= "\ncpu$i 1 2 3 4";
    $cpu = measure('8192_cpu_parse', static fn() => rackline_metrics_cpu_counters($manyCpu));
    check(count($cpu) === 8193, 'Large supported logical CPU inventory retained');
    check(rackline_metrics_cpu_counters($manyCpu . "\ncpu8192 1 2 3 4") === [], 'CPU inventory limit rejects rather than truncates');
    $topology = measure('8192_cpu_topology', static fn() => rackline_metrics_topology($root, $cpu));
    check(count($topology) === 8192 && $topology[8191]['threads'][0]['cpuId'] === 8191, 'Large sysfs-free topology is complete');
    check(rackline_metrics_memory("MemTotal: 10 kB\nMemTotal: 20 kB\nMemAvailable: 0 kB")['totalBytes'] === null, 'Duplicate memory fields are unknown');
    put($root, '/proc/uptime', '9007199254740992 0');
    check(rackline_metrics_snapshot($root)['monotonicSeconds'] === null, 'Imprecise uptime cannot seed rates');
    put($root, '/oversized', str_repeat('x', 1048577));
    check(rackline_metrics_read($root . '/oversized') === null && rackline_storage_read($root . '/oversized') === null, 'Oversized metadata is never parsed partially');

    // Bond metadata must be linear, bounded and immune to duplicate member rows.
    put($root, RACKLINE_METRICS_CONFIG, 'PRIMARY_INTERFACE="bond0"');
    put($root, '/sys/class/net/bond0/bonding/slaves', str_repeat('eth0 ', 10000));
    put($root, '/sys/class/net/eth0/operstate', 'up');
    put($root, '/proc/net/bonding/bond0', "Bonding Mode: 802.3ad\nSlave Interface: eth0\nLink Failure Count: 5\nSlave Interface: eth0\nLink Failure Count: 9\n" . str_repeat("unused\n", 100000));
    $network = measure('duplicate_bond_members', static fn() => rackline_metrics_network($root));
    check(count($network['members']) === 1 && $network['members'][0]['linkFailureCount'] === null, 'Duplicate members cannot duplicate totals or choose one failure count');
    put($root, '/sys/class/net/bond0/bonding/slaves', implode(' ', array_map(static fn(int $i): string => 'eth' . $i, range(0, 256))));
    check(rackline_metrics_network($root)['members'] === [], 'Oversized bond inventory unavailable');

    $line = '8 0 sda 10 0 20 5 30 0 40 6 2 7 8';
    $largeIo = [];
    for ($i = 0; $i < 8192; $i++) $largeIo[] = str_replace('sda', 'disk' . $i, $line);
    $largeIo = implode("\n", $largeIo);
    $io = measure('8192_io_devices', static fn() => rackline_io_parse($largeIo, '100 50', 1000));
    check($io['status'] === 'ok' && count((array) $io['devices']) === 8192, 'Large diskstats sample retained exactly');
    foreach ([$largeIo . "\n" . $line, str_repeat('x', 1048577), str_replace('sda', '..', $line), $line . str_repeat(' 0', 30), str_replace('8 0 sda', '18446744073709551615 0 sda', $line)] as $bad)
        check(rackline_io_parse($bad, '100 50', 1000)['status'] === 'unavailable', 'Malformed/oversized diskstats unavailable');
    check(rackline_io_parse($line, '9007199254740992 0', 1000)['status'] === 'unavailable', 'Imprecise I/O clock rejected');
    $reset = rackline_io_parse(str_replace('20 5', '0 5', $line), '1 0', 1001);
    check($reset['devices']->sda['readBytes'] === 0.0 && $reset['monotonicSeconds'] === 1.0, 'Counter/uptime resets remain observable');

    // Scalar shape attacks never leak warnings into endpoint JSON.
    $nested = ['bad' => ['deep' => 'value']];
    foreach (['name', 'type'] as $key) rejects(static fn() => rackline_storage_disk_records([disk([$key => $nested])], [], [], $root), 'Malformed native identity fails explicitly');
    foreach (['device', 'model', 'id', 'serial', 'status', 'numErrors', 'rotational', 'transport', 'temp', 'idx', 'color', 'fsType', 'fsStatus', 'size', 'fsSize', 'fsUsed', 'fsFree'] as $key) {
        $result = rackline_storage_disk_records([disk([$key => $nested])], [], [], $root);
        check(json_encode($result, JSON_THROW_ON_ERROR) !== '', 'Nested field remains serializable: ' . $key);
        check(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), '"Array"'), 'Nested field never becomes fabricated text: ' . $key);
    }
    check(rackline_storage_media([]) === 'Unknown' && rackline_storage_temperature_thresholds([], [], []) === ['warning' => null, 'critical' => null], 'Missing media cannot invent HDD/SSD thresholds');
    foreach (['green-onion', 'unblinking'] as $color) {
        $ambiguous = rackline_storage_disk_records([disk(['temp' => '*', 'color' => $color])], [], [], $root)[0];
        check(!$ambiguous['spunDown'] && $ambiguous['statusLabel'] === 'UNKNOWN', 'Malformed color cannot prove disk power state');
    }
    foreach (['1e99', '-300', '300'] as $temp) check(rackline_storage_disk_records([disk(['temp' => $temp])], [], [], $root)[0]['temperatureC'] === null, 'Impossible temperature unknown');
    rejects(static fn() => rackline_storage_disk_records([disk(), disk(['device' => 'sdc'])], [], [], $root), 'Duplicate names fail instead of dropping a real drive');
    rejects(static fn() => rackline_storage_disk_records([disk(), disk(['name' => 'disk2'])], [], [], $root), 'Duplicate physical devices cannot double totals');
    rejects(static fn() => rackline_storage_disk_records(array_fill(0, 1025, disk()), [], [], $root), 'Huge native disk inventory bounded');
    check(!rackline_storage_serial_matches('MODEL', ['id' => 'MODEL_NEW-SERIAL']), 'Model token cannot be mistaken for serial');
    check(!rackline_storage_serial_matches('OLD', ['id' => 'MODEL_PREFIX_OLD_EXTRA']), 'Arbitrary middle token cannot be mistaken for serial');
    check(!rackline_storage_serial_matches(str_repeat('A', 100000), ['id' => 'MODEL_OTHER']), 'Oversized serial cannot compile an unsafe dynamic regex or emit a warning');
    check(rackline_storage_serial_matches('SERIAL', ['id' => 'MODEL_SERIAL_WWN']), 'Native SAS serial plus WWN remains supported');
    check(!rackline_storage_serial_matches('SERIAL', ['id' => 'MODEL_SERIAL', 'serial' => $nested]), 'Malformed explicit serial cannot defer to fallback');
    put($root, '/smart/disk1', "Serial Number: FIXTURE-A\nSerial Number: OLD\nSMART Health Status: OK\n");
    check(!rackline_storage_cached_smart('disk1', disk(), $root . '/smart')['available'], 'Mixed duplicate SMART cache identities rejected');
    put($root, '/smart/disk1', "Serial Number: FIXTURE-A\nSMART Health Status: OK\n" . str_repeat('x', 262000));
    $budget = RACKLINE_STORAGE_SMART_BUDGET;
    measure('aggregate_smart_budget', static function () use ($root, &$budget): void {
        for ($i = 0; $i < 100; $i++) rackline_storage_cached_smart('disk1', disk(), $root . '/smart', $budget);
    });
    check($budget === 0, 'Cached SMART cannot exceed aggregate read budget');
    check(!rackline_storage_cached_smart('disk1', disk(), $root . '/smart', $budget)['available'], 'Exhausted SMART budget stays unknown');

    $disks = rackline_storage_disk_records([disk()], [], [], $root);
    $groups = ['1' => ['grid_columns' => 64, 'grid_rows' => 64, 'tray_start_num' => 0]];
    $map = measure('4096_bays', static function () use (&$disks, $groups): array { return rackline_storage_location_data($disks, [disk()], $groups, [], []); });
    check($map['available'] && count($map['groups'][0]['cells']) === 4096 && $map['groups'][0]['cells'][4095]['tray'] === 4095, 'Large physical layout retains every real bay label');
    foreach ([array_fill(0, 65, ['grid_columns' => 1, 'grid_rows' => 1]), ['1' => ['grid_columns' => 100, 'grid_rows' => 100]], ['1' => ['grid_columns' => '1e50', 'grid_rows' => 1]], ['1' => ['grid_columns' => 2, 'grid_rows' => 2, 'tray_start_num' => '9007199254740991']], ['1' => ['grid_columns' => $nested]]] as $bad) {
        $result = measure('oversized_or_invalid_bays_' . count($timings), static function () use (&$disks, $bad): array { return rackline_storage_location_data($disks, [disk()], $bad, [], []); });
        check(!$result['available'] && $result['groups'] === [], 'Oversized/invalid layout is unavailable, never truncated or renumbered');
    }
    $groups = ['1' => ['grid_columns' => 2, 'grid_rows' => 1]];
    $locations = ['a' => ['groupid' => '1', 'tray' => 1]];
    $devices = ['a' => ['devicenode' => '/dev/sdb', 'smart_serialnumber' => 'FIXTURE-A']];
    $map = rackline_storage_location_data($disks, [disk()], $groups, $locations, $devices);
    check($map['groups'][0]['cells'][0]['disk']['name'] === 'disk1', 'Exact current identity mapped');
    rackline_storage_location_data($disks, [disk()], [], [], []);
    check($disks[0]['location'] === null, 'Reusing record array cannot retain stale location');
    $duplicateLocations = $locations + ['b' => ['groupid' => '1', 'tray' => 1]];
    check(!rackline_storage_location_data($disks, [disk()], $groups, $duplicateLocations, $devices + ['b' => $devices['a']])['available'], 'Two occupants cannot fabricate a certain bay mapping');
    $devices['a']['smart_serialnumber'] = 'OLD';
    $map = rackline_storage_location_data($disks, [disk()], $groups, $locations, $devices);
    check($map['groups'][0]['cells'][0]['present'] && $map['groups'][0]['cells'][0]['disk'] === null, 'Cached stale serial remains inventory-only');

    $native = [disk(['name' => 'cache', 'type' => 'Cache', 'device' => 'nvme0n1']), disk(['name' => 'cache2', 'type' => 'Cache', 'device' => 'nvme1n1']), disk(['name' => 'cache22', 'type' => 'Cache', 'device' => 'nvme2n1'])];
    $poolDisks = rackline_storage_disk_records($native, [], [], $root);
    $pools = rackline_storage_pool_data($poolDisks, ['cache' => [], 'cache2' => []]);
    check(array_column($pools, 'memberCount') === [1, 2], 'Overlapping pool names never duplicate native devices');
    $poolDisks = rackline_storage_disk_records($native, [], [], $root);
    $pools = rackline_storage_pool_data($poolDisks, []);
    check(count($pools) === 3 && array_sum(array_column($pools, 'memberCount')) === 3, 'Missing pool configs never erase native cache inventory');
    foreach ($pools as $pool) check($pool['state'] === 'unknown' && $pool['profile'] === null && $pool['sizeBytes'] === null && $pool['members'][0]['poolName'] === null, 'Fallback grouping makes no pool membership/capacity claim');
    $attention = rackline_storage_attention(rackline_storage_array_data($poolDisks, ['mdState' => 'STARTED']), rackline_storage_parity_data($poolDisks, [], []), $pools);
    check($attention['count'] === 3, 'Missing pool metadata explicitly needs attention');
    $unknownArray = rackline_storage_array_data($disks, ['mdState' => 'STARTED']);
    $arrayAttention = rackline_storage_attention($unknownArray, rackline_storage_parity_data([], [], []), []);
    check(in_array('Array · native status incomplete', $arrayAttention['messages'], true), 'Missing array health must appear in frontend attention feed');
    $huge = rackline_storage_disk_records([disk(['fsSize' => '8000000000000', 'fsUsed' => '7000000000000']), disk(['name' => 'disk2', 'device' => 'sdc', 'fsSize' => '8000000000000', 'fsUsed' => '7000000000000'])], [], [], $root);
    $array = rackline_storage_array_data($huge, ['mdState' => 'STARTED', 'mdNumMissing' => '0', 'mdNumDisabled' => '0', 'mdNumInvalid' => '0']);
    check($array['sizeBytes'] === null && $array['usedBytes'] === null && $array['state'] === 'unknown', 'Aggregate bytes overflow cannot look complete');
    check(rackline_storage_parity_data([], ['mdResyncPos' => '11', 'mdResyncSize' => '10'], [])['progressPercent'] === null, 'Impossible parity position is not clamped to complete');

    foreach (['0/0', '1-2/0', '1-99', '59,garbage', '1,,2', '-1', '1e1', '2.5', '*/-1', str_repeat('1,', 200) . '1'] as $field) check(rackline_storage_cron_values($field, 0, 59) === [], 'Malformed cron cannot invent schedule');
    check(rackline_storage_cron_values('5/20', 0, 59) === [5, 25, 45], 'Native stepped single start expands correctly');
    $now = (new DateTimeImmutable('2026-01-01 01:00:00', new DateTimeZone('UTC')))->getTimestamp();
    foreach (["0 0 * 0 " . str_repeat('1,', 100000) . "1 mdcmd check", "0 0 * 2 " . str_repeat('1,', 25000) . '1 mdcmd check', '0 0 * 0 1 mdcmd check', '0/0 3 * * * mdcmd check'] as $cron)
        check(measure('malformed_cron_' . count($timings), static fn() => rackline_storage_next_parity(['mode' => '1'], $now, $cron)) === null, 'Pathological cron rejected before day search');
    date_default_timezone_set('UTC');
    check(rackline_storage_next_parity(['mode' => '1'], $now, '0 3 2 * 1 mdcmd check') === (new DateTimeImmutable('2026-01-02 03:00:00'))->getTimestamp(), 'Cron restricted day/weekday follows OR semantics');

    // Exercise actual required/optional metadata ingestion, repeated snapshots and
    // passive-read invariants rather than testing only normalized helper arrays.
    $sources = [];
    foreach (rackline_storage_sources() as $key => $_) $sources[$key] = $root . '/snapshot/' . $key;
    $ini = "[disk1]\n";
    foreach (disk() as $key => $value) $ini .= $key . '="' . $value . "\"\n";
    put($root, '/snapshot/disks', $ini);
    put($root, '/snapshot/variables', "mdState=STARTED\nmdNumMissing=0\nmdNumDisabled=0\nmdNumInvalid=0\n");
    put($root, '/snapshot/locations/groups.json', '{"1":{"grid_columns":2,"grid_rows":1}}');
    put($root, '/snapshot/locations/locations.json', '{"a":{"groupid":"1","tray":1}}');
    put($root, '/snapshot/locations/devices.json', '{"a":{"devicenode":"/dev/sdb","smart_serialnumber":"FIXTURE-A"}}');
    $before = [];
    foreach (['disks', 'variables', 'locations/groups.json', 'locations/locations.json', 'locations/devices.json'] as $path) $before[$path] = hash_file('sha256', $root . '/snapshot/' . $path);
    measure('256_complete_passive_snapshots', static function () use ($sources): void {
        for ($i = 0; $i < 256; $i++) {
            $snapshot = rackline_storage_snapshot($sources);
            check(count($snapshot['array']['disks']) === 1 && $snapshot['locations']['groups'][0]['cells'][0]['disk']['name'] === 'disk1', 'Repeated passive snapshot retains complete identity');
            check(json_encode($snapshot, JSON_THROW_ON_ERROR) !== '', 'Complete snapshot serializes');
        }
    });
    foreach ($before as $path => $hash) check(hash_file('sha256', $root . '/snapshot/' . $path) === $hash, 'Collection cannot mutate fixture metadata');
    put($root, '/snapshot/disks', str_replace('model="MODEL"', 'model[]="bad"', $ini));
    check(rackline_storage_snapshot($sources)['array']['disks'][0]['model'] === 'MODEL', 'Nested optional INI model safely falls back to known identity');
    put($root, '/snapshot/disks', str_replace('name="disk1"', 'name[]="bad"', $ini));
    rejects(static fn() => rackline_storage_snapshot($sources), 'Nested required identity fails explicitly');
    put($root, '/snapshot/disks', str_repeat('x', 1048577));
    rejects(static fn() => rackline_storage_snapshot($sources), 'Oversized required metadata fails explicitly');
    put($root, '/snapshot/disks', $ini);
    put($root, '/snapshot/variables', "[unterminated\n");
    rejects(static fn() => rackline_storage_snapshot($sources), 'Truncated required metadata fails explicitly');
    check(rackline_metrics_cpu_counters('cpu ' . str_repeat('0 ', 400000))['cpu']['total'] === null, 'Many-field CPU line rejected without unbounded token allocation');
    check(rackline_io_parse($line . str_repeat(' 0', 400000), '1 0', 1)['status'] === 'unavailable', 'Many-field I/O line rejected without unbounded token allocation');

    $sensorRoot = $root . '/sensors';
    put($sensorRoot, '/sys/class/hwmon/hwmon0/name', 'coretemp');
    for ($i = 1; $i <= 1024; $i++) {
        put($sensorRoot, '/sys/class/hwmon/hwmon0/temp' . $i . '_input', '40000');
        put($sensorRoot, '/sys/class/hwmon/hwmon0/temp' . $i . '_label', $i === 1 ? 'Package id 0' : 'Core ' . ($i - 2));
    }
    $sensorTopology = [['packageId' => 0, 'coreId' => 0, 'threads' => [['cpuId' => 0]]]];
    $temperatures = measure('1024_temperature_sensors', static fn() => rackline_metrics_temperatures($sensorRoot, $sensorTopology));
    check($temperatures['package'] === 40.0 && $temperatures['packageCoverageComplete'], 'Large supported temperature scan remains complete');
    put($sensorRoot, '/sys/class/hwmon/hwmon0/temp1025_input', '50000');
    check(rackline_metrics_temperatures($sensorRoot, $sensorTopology)['package'] === null, 'Sensor overflow cannot leave a partial maximum');
    put($sensorRoot, '/sys/class/hwmon/hwmon0/name', 'highflownext');
    check(rackline_metrics_coolant($sensorRoot) === ['coolantTempC' => null, 'flowLph' => null], 'Coolant sensor work also bounded');

    // Seeded malformed-record campaign. All results must serialize without warnings,
    // NaN or infinity; rejecting required native identity is an explicit outcome.
    mt_srand(2601108);
    $values = [null, false, true, [], $nested, '', 'NaN', '1e309', '-1', '9007199254740991.1', str_repeat('x', 512), '0'];
    $fields = ['name', 'type', 'device', 'id', 'serial', 'model', 'numErrors', 'fsSize', 'fsUsed', 'temp', 'fsStatus'];
    measure('2000_seeded_malformed_records', static function () use ($values, $fields, $root): void {
        for ($i = 0; $i < 2000; $i++) {
            $raw = disk([$fields[mt_rand(0, count($fields) - 1)] => $values[mt_rand(0, count($values) - 1)]]);
            try {
                $records = rackline_storage_disk_records([$raw], [], [], $root);
                check(json_encode($records, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) !== '', 'Fuzzed record remains valid JSON');
            } catch (RuntimeException $error) { check($error->getMessage() === 'Malformed native disk identity', 'Only deliberate malformed identity rejection is accepted'); }
        }
    });
    $elapsed = (hrtime(true) - $start) / 1e9;
    check(memory_get_peak_usage(true) < 128 * 1024 * 1024, 'Peak fixture memory stays below 128 MiB');
    echo json_encode(['assertions' => $assertions, 'elapsedSeconds' => round($elapsed, 4), 'peakMiB' => round(memory_get_peak_usage(true) / 1048576, 2), 'timingsSeconds' => $timings], JSON_THROW_ON_ERROR) . "\n";
} finally {
    cleanup($root);
    restore_error_handler();
}
