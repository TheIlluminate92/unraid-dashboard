<?php
/**
 * Rackline-owned system telemetry. Native kernel reads only: no commands,
 * daemons, hardware probes, shared collector state, or external requests.
 * The optional root parameter is an internal fixture seam, never HTTP input.
 */
declare(strict_types=1);

const RACKLINE_METRICS_VERSION = '@@APP_VERSION@@';
const RACKLINE_METRICS_RELEASE = '@@RELEASE_VERSION@@';
const RACKLINE_METRICS_CONFIG = '/boot/config/plugins/rackline.dashboard/rackline.dashboard.cfg';
const RACKLINE_METRICS_SAFE_INTEGER = 9007199254740991;
const RACKLINE_METRICS_MAX_CPUS = 8192;
const RACKLINE_METRICS_MAX_HWMON = 256;
const RACKLINE_METRICS_MAX_SENSORS = 1024;
const RACKLINE_METRICS_MAX_MEMBERS = 256;

function rackline_metrics_read(string $path, int $limit = 1048576): ?string
{
    $value = @file_get_contents($path, false, null, 0, $limit + 1);
    return $value === false || strlen($value) > $limit ? null : trim($value);
}

function rackline_metrics_counter(?string $value): ?float
{
    if ($value === null || !preg_match('/^[0-9]+$/D', $value)) return null;
    $number = (float) $value;
    return is_finite($number) && $number <= RACKLINE_METRICS_SAFE_INTEGER ? $number : null;
}

function rackline_metrics_number(string $path): ?float
{
    return rackline_metrics_counter(rackline_metrics_read($path, 128));
}

function rackline_metrics_cpu_counters(?string $text): array
{
    $result = [];
    if ($text !== null && strlen($text) > 1048576) return [];
    foreach (explode("\n", $text ?? '') as $line) {
        if (!preg_match('/^(cpu(?:[0-9]+)?)\s+(.+)$/D', trim($line), $match)) continue;
        $name = $match[1];
        if ($name !== 'cpu' && rackline_metrics_id(substr($name, 3)) === null) continue;
        if (!array_key_exists($name, $result) && count($result) >= RACKLINE_METRICS_MAX_CPUS + 1) return [];
        $values = array_map('rackline_metrics_counter', preg_split('/\s+/', $match[2], 11));
        $valid = count($values) >= 4 && count($values) <= 10 && !in_array(null, $values, true) && !array_key_exists($name, $result);
        // Linux guest and guest_nice already belong to user and nice. Only the
        // first eight counters count toward total; adding fields 9/10 doubles VMs.
        $total = $valid ? array_sum(array_slice($values, 0, 8)) : null;
        $idle = $valid ? $values[3] + ($values[4] ?? 0) : null;
        if ($total !== null && ($total > RACKLINE_METRICS_SAFE_INTEGER || $idle > $total)) $total = $idle = null;
        $result[$name] = ['total' => $total, 'idle' => $idle];
    }
    return $result;
}

function rackline_metrics_cpu_model(?string $text): ?string
{
    if (preg_match('/^model name\s*:\s*(.+)$/mi', $text ?? '', $match)) return trim($match[1]);
    return null;
}

function rackline_metrics_id(?string $text): ?int
{
    $number = rackline_metrics_counter($text);
    return $number !== null && $number <= 2147483647 ? (int) $number : null;
}

function rackline_metrics_topology(string $root, array $counters): array
{
    $groups = [];
    $seen = [];
    $paths = glob($root . '/sys/devices/system/cpu/cpu[0-9]*') ?: [];
    if (count($paths) > RACKLINE_METRICS_MAX_CPUS || count($counters) > RACKLINE_METRICS_MAX_CPUS + 1) return [];
    foreach ($paths as $path) {
        if (!preg_match('/cpu([0-9]+)$/D', $path, $match) || rackline_metrics_read($path . '/online', 8) === '0') continue;
        $id = rackline_metrics_id($match[1]);
        if ($id === null) continue;
        $core = rackline_metrics_id(rackline_metrics_read($path . '/topology/core_id', 32));
        $package = rackline_metrics_id(rackline_metrics_read($path . '/topology/physical_package_id', 32));
        // An absent package/core is not evidence that everything is socket zero.
        $key = $core !== null && $package !== null ? $package . ':' . $core : 'unknown:' . $id;
        if (!isset($groups[$key])) $groups[$key] = ['packageId' => $package, 'coreId' => $core, 'tempC' => null, 'threads' => []];
        $groups[$key]['threads'][] = ['cpuId' => $id];
        $seen[$id] = true;
    }
    // Retain each logical CPU when sysfs topology is absent or temporarily incomplete.
    foreach ($counters as $name => $counter) {
        if (!preg_match('/^cpu([0-9]+)$/D', $name, $match)) continue;
        $id = rackline_metrics_id($match[1]);
        if ($id === null || isset($seen[$id])) continue;
        // A sysfs offline record takes precedence over a racing proc/stat sample.
        if (rackline_metrics_read($root . '/sys/devices/system/cpu/cpu' . $id . '/online', 8) === '0') continue;
        $groups['unknown:' . $id] = ['packageId' => null, 'coreId' => null, 'tempC' => null, 'threads' => [['cpuId' => $id]]];
    }
    foreach ($groups as &$group) usort($group['threads'], static fn(array $a, array $b): int => $a['cpuId'] <=> $b['cpuId']);
    unset($group);
    $result = array_values($groups);
    usort($result, static fn(array $a, array $b): int => [$a['packageId'] ?? PHP_INT_MAX, $a['coreId'] ?? PHP_INT_MAX, $a['threads'][0]['cpuId']] <=> [$b['packageId'] ?? PHP_INT_MAX, $b['coreId'] ?? PHP_INT_MAX, $b['threads'][0]['cpuId']]);
    return $result;
}

function rackline_metrics_temperature(string $input): ?float
{
    $text = rackline_metrics_read($input, 128);
    $fault = rackline_metrics_read(substr($input, 0, -6) . '_fault', 8);
    if ($fault !== null && $fault !== '0') return null;
    if ($text === null || !preg_match('/^-?[0-9]+$/D', $text)) return null;
    $value = (float) $text / 1000;
    return is_finite($value) && $value >= -273.15 && $value <= 250 ? $value : null;
}

function rackline_metrics_temperatures(string $root, array $topology): array
{
    $packages = [];
    $completeTopology = count($topology) > 0;
    foreach ($topology as $core) {
        if ($core['packageId'] === null) $completeTopology = false;
        else $packages[$core['packageId']] = true;
    }
    $packageValues = [];
    $coreValues = [];
    $directories = glob($root . '/sys/class/hwmon/hwmon*') ?: [];
    $sensorCount = 0;
    if (count($directories) > RACKLINE_METRICS_MAX_HWMON) $directories = [];
    foreach ($directories as $directory) {
        $driver = rackline_metrics_read($directory . '/name', 64);
        if (!in_array($driver, ['coretemp', 'k10temp'], true)) continue;
        $sensors = [];
        $packageIds = [];
        $inputs = glob($directory . '/temp*_input') ?: [];
        $sensorCount += count($inputs);
        if ($sensorCount > RACKLINE_METRICS_MAX_SENSORS) return ['package' => null, 'packages' => (object) [], 'cores' => [], 'packageCoverageComplete' => false];
        foreach ($inputs as $input) {
            $label = rackline_metrics_read(substr($input, 0, -6) . '_label', 128);
            if ($label === null) continue;
            $sensors[] = ['label' => $label, 'value' => rackline_metrics_temperature($input)];
            if ($driver === 'coretemp' && preg_match('/^Package id ([0-9]+)$/iD', $label, $match)) {
                $package = rackline_metrics_id($match[1]);
                if ($package !== null) $packageIds[$package] = true;
            }
        }
        // Core N labels are scoped to their package, never a machine-wide core ID.
        // Do not guess a socket from hwmon numbering or PCI enumeration order.
        $package = count($packageIds) === 1 ? (int) array_key_first($packageIds) : null;
        if (!$packageIds && $completeTopology && count($packages) === 1) $package = (int) array_key_first($packages);
        foreach ($sensors as $sensor) {
            $label = $sensor['label'];
            $value = $sensor['value'];
            if ($driver === 'coretemp' && preg_match('/^Package id ([0-9]+)$/iD', $label, $match)) {
                $id = rackline_metrics_id($match[1]);
                if ($id !== null) $packageValues[$id][] = $value;
            } elseif ($package !== null && (($driver === 'coretemp' && strcasecmp($label, 'CPU Temp') === 0)
                || ($driver === 'k10temp' && strcasecmp($label, 'Tdie') === 0))) {
                // AMD Tctl may include an offset; only a measured Tdie is used.
                $packageValues[$package][] = $value;
            } elseif ($driver === 'coretemp' && $package !== null && preg_match('/^Core ([0-9]+)$/iD', $label, $match)) {
                $id = rackline_metrics_id($match[1]);
                if ($id !== null) $coreValues[$package . ':' . $id][] = $value;
            }
        }
    }
    $temperatures = [];
    foreach ($packageValues as $id => $values) $temperatures[$id] = count($values) === 1 ? $values[0] : null;
    foreach ($coreValues as $id => $values) $coreValues[$id] = count($values) === 1 ? $values[0] : null;
    $known = [];
    foreach ($packages as $id => $_) {
        if (!isset($temperatures[$id])) $completeTopology = false;
        else $known[] = $temperatures[$id];
    }
    // The headline maximum requires coverage of every known socket. Losing a
    // sensor must not quietly become a lower "maximum" or a fabricated zero.
    return ['package' => $completeTopology && $known ? max($known) : null,
        'packages' => (object) $temperatures, 'cores' => $coreValues,
        'packageCoverageComplete' => $completeTopology && count($known) > 0];
}

function rackline_metrics_memory(?string $text): array
{
    $values = [];
    foreach (explode("\n", $text ?? '') as $line) {
        if (!preg_match('/^([A-Za-z_()]+):\s+([0-9]+)\s+kB$/D', trim($line), $match)) continue;
        $value = rackline_metrics_counter($match[2]);
        $values[$match[1]] = !array_key_exists($match[1], $values) && $value !== null && $value <= floor(RACKLINE_METRICS_SAFE_INTEGER / 1024) ? $value * 1024 : null;
    }
    $total = $values['MemTotal'] ?? null;
    if ($total !== null && $total <= 0) $total = null;
    $available = $values['MemAvailable'] ?? null;
    if ($available !== null && $total !== null && $available > $total) $available = null;
    $cached = isset($values['Cached'], $values['SReclaimable'], $values['Shmem'])
        ? $values['Cached'] + $values['SReclaimable'] - $values['Shmem'] : null;
    if ($cached !== null && ($cached < 0 || $cached > RACKLINE_METRICS_SAFE_INTEGER || ($total !== null && $cached > $total))) $cached = null;
    return ['totalBytes' => $total, 'availableBytes' => $available, 'cachedBytes' => $cached,
        'hardwareSummary' => null, 'ecc' => null];
}

function rackline_metrics_interface(?string $name): bool
{
    return $name !== null && $name !== '.' && $name !== '..' && preg_match('/^[A-Za-z0-9_.:-]{1,15}$/D', $name) === 1;
}

function rackline_metrics_route(?string $text, bool $ipv6 = false): ?string
{
    $routes = [];
    foreach (explode("\n", $text ?? '') as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if ($ipv6) {
            if (count($parts) !== 10 || $parts[0] !== str_repeat('0', 32) || $parts[1] !== '00'
                || $parts[2] !== str_repeat('0', 32) || $parts[3] !== '00'
                || !preg_match('/^[a-f0-9]{8}$/iD', $parts[5]) || !preg_match('/^[a-f0-9]{8}$/iD', $parts[8])) continue;
            $name = $parts[9]; $metric = hexdec($parts[5]); $flags = hexdec($parts[8]);
        } else {
            if (count($parts) < 8 || $parts[1] !== '00000000' || $parts[7] !== '00000000'
                || !preg_match('/^[a-f0-9]{1,8}$/iD', $parts[3]) || rackline_metrics_counter($parts[6]) === null) continue;
            $name = $parts[0]; $metric = (float) $parts[6]; $flags = hexdec($parts[3]);
        }
        if (!rackline_metrics_interface($name) || $name === 'lo' || !is_finite((float) $metric)
            || !($flags & 1) || ($flags & 0x200)) continue;
        $routes[] = ['name' => $name, 'metric' => $metric];
    }
    usort($routes, static fn(array $a, array $b): int => [$a['metric'], $a['name']] <=> [$b['metric'], $b['name']]);
    return $routes[0]['name'] ?? null;
}

function rackline_metrics_link(string $base): array
{
    $state = rackline_metrics_read($base . '/operstate', 32);
    // Linux "unknown" is not down; bridge/virtual interfaces may report it.
    $up = $state === 'up' ? true : (in_array($state, ['down', 'lowerlayerdown', 'notpresent'], true) ? false : null);
    $speed = rackline_metrics_number($base . '/speed');
    if ($speed !== null && $speed <= 0) $speed = null;
    $duplex = rackline_metrics_read($base . '/duplex', 16);
    return ['up' => $up, 'speedMbps' => $speed, 'duplex' => in_array($duplex, ['full', 'half'], true) ? $duplex : null];
}

function rackline_metrics_network(string $root): array
{
    $config = rackline_metrics_read($root . RACKLINE_METRICS_CONFIG, 65536);
    $config = $config !== null ? @parse_ini_string($config, false, INI_SCANNER_RAW) : [];
    $configured = is_array($config) && is_string($config['PRIMARY_INTERFACE'] ?? null) ? trim($config['PRIMARY_INTERFACE']) : null;
    $interface = rackline_metrics_interface($configured) ? $configured : null;
    $selection = $interface !== null ? 'configured' : null;
    if ($interface === null) {
        $interface = rackline_metrics_route(rackline_metrics_read($root . '/proc/net/route'));
        if ($interface !== null) $selection = 'ipv4-default-route';
    }
    if ($interface === null) {
        $interface = rackline_metrics_route(rackline_metrics_read($root . '/proc/net/ipv6_route'), true);
        if ($interface !== null) $selection = 'ipv6-default-route';
    }
    $base = $interface !== null ? $root . '/sys/class/net/' . $interface : null;
    $link = $base !== null ? rackline_metrics_link($base) : ['up' => null, 'speedMbps' => null, 'duplex' => null];
    $counters = [];
    foreach (['rxBytes' => 'rx_bytes', 'txBytes' => 'tx_bytes', 'rxDropped' => 'rx_dropped', 'txDropped' => 'tx_dropped'] as $key => $file)
        $counters[$key] = $base !== null ? rackline_metrics_number($base . '/statistics/' . $file) : null;
    $ifindex = $base !== null ? rackline_metrics_number($base . '/ifindex') : null;
    if ($ifindex !== null && $ifindex <= 0) $ifindex = null;
    $members = [];
    $bond = $interface !== null ? rackline_metrics_read($root . '/proc/net/bonding/' . $interface) : null;
    $mode = $bond !== null && preg_match('/^Bonding Mode:\s*(.+)$/mi', $bond, $match) ? trim($match[1]) : null;
    if ($mode !== null && stripos($mode, '802.3ad') !== false) $mode = 'LACP';
    $slaves = $base !== null ? rackline_metrics_read($base . '/bonding/slaves', 65536) : null;
    $names = array_values(array_unique(preg_split('/\s+/', trim($slaves ?? ''))));
    if (count($names) > RACKLINE_METRICS_MAX_MEMBERS) $names = [];
    $failureByMember = [];
    foreach (preg_split('/^Slave Interface:\s*/m', $bond ?? '') as $section) {
        if (!preg_match('/^([A-Za-z0-9_.:-]{1,15})\s*\n/', $section, $match)) continue;
        $name = $match[1];
        $failure = preg_match('/^Link Failure Count:\s*([0-9]+)$/m', $section, $match) ? rackline_metrics_counter($match[1]) : null;
        $failureByMember[$name] = array_key_exists($name, $failureByMember) ? null : $failure;
    }
    foreach ($names as $name) {
        if (!rackline_metrics_interface($name)) continue;
        $member = rackline_metrics_link($root . '/sys/class/net/' . $name);
        $failures = $failureByMember[$name] ?? null;
        $members[] = ['name' => $name] + $member + ['linkFailureCount' => $failures];
    }
    return ['interface' => $interface, 'identity' => $interface !== null && $ifindex !== null ? $interface . ':' . $ifindex : null,
        'selection' => $selection] + $link + ['mode' => $mode, 'mtu' => $base !== null ? rackline_metrics_number($base . '/mtu') : null,
        'counters' => $counters, 'members' => $members];
}

function rackline_metrics_coolant(string $root): array
{
    $temperature = [];
    $flow = [];
    $directories = glob($root . '/sys/class/hwmon/hwmon*') ?: [];
    $sensorCount = 0;
    if (count($directories) > RACKLINE_METRICS_MAX_HWMON) $directories = [];
    foreach ($directories as $directory) {
        if (rackline_metrics_read($directory . '/name', 64) !== 'highflownext') continue;
        $inputs = glob($directory . '/temp*_input') ?: [];
        $fans = glob($directory . '/fan*_input') ?: [];
        $sensorCount += count($inputs) + count($fans);
        if ($sensorCount > RACKLINE_METRICS_MAX_SENSORS) return ['coolantTempC' => null, 'flowLph' => null];
        foreach ($inputs as $input)
            if (strcasecmp(rackline_metrics_read(substr($input, 0, -6) . '_label', 128) ?? '', 'Coolant temp') === 0) $temperature[] = rackline_metrics_temperature($input);
        foreach ($fans as $input) {
            if (strcasecmp(rackline_metrics_read(substr($input, 0, -6) . '_label', 128) ?? '', 'Flow [dL/h]') !== 0) continue;
            $value = rackline_metrics_number($input);
            $fault = rackline_metrics_read(substr($input, 0, -6) . '_fault', 8);
            $flow[] = $value !== null && ($fault === null || $fault === '0') ? $value / 10 : null;
        }
    }
    return ['coolantTempC' => count($temperature) === 1 ? $temperature[0] : null, 'flowLph' => count($flow) === 1 ? $flow[0] : null];
}

function rackline_metrics_snapshot(string $root = '', ?int $now = null): array
{
    $counters = rackline_metrics_cpu_counters(rackline_metrics_read($root . '/proc/stat'));
    $topology = rackline_metrics_topology($root, $counters);
    $temperatures = rackline_metrics_temperatures($root, $topology);
    $physical = count($topology) > 0 ? count($topology) : null;
    foreach ($topology as &$core) {
        if ($core['packageId'] === null || $core['coreId'] === null) $physical = null;
        else $core['tempC'] = $temperatures['cores'][$core['packageId'] . ':' . $core['coreId']] ?? null;
    }
    unset($core);
    $threads = array_sum(array_map(static fn(array $core): int => count($core['threads']), $topology));
    $memory = rackline_metrics_memory(rackline_metrics_read($root . '/proc/meminfo'));
    $network = rackline_metrics_network($root);
    $uptime = rackline_metrics_read($root . '/proc/uptime', 128);
    $mono = $uptime !== null && preg_match('/^([0-9]+(?:\.[0-9]+)?)\s+[0-9]+(?:\.[0-9]+)?$/D', $uptime, $match) ? (float) $match[1] : null;
    if ($mono !== null && (!is_finite($mono) || $mono > RACKLINE_METRICS_SAFE_INTEGER)) $mono = null;
    $bootId = rackline_metrics_read($root . '/proc/sys/kernel/random/boot_id', 64);
    if ($bootId === null || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $bootId)) $bootId = null;
    $hasCpu = isset($counters['cpu']['total']);
    $hasMemory = $memory['totalBytes'] !== null;
    $hasNetwork = $network['counters']['rxBytes'] !== null && $network['counters']['txBytes'] !== null;
    $status = $hasCpu && $hasMemory && $hasNetwork ? 'ok' : ($hasCpu || $hasMemory || $hasNetwork ? 'partial' : 'unavailable');
    $result = ['schemaVersion' => 1, 'version' => RACKLINE_METRICS_VERSION, 'pluginVersion' => RACKLINE_METRICS_RELEASE,
        'sampledAtMs' => $now ?? (int) round(microtime(true) * 1000), 'monotonicSeconds' => $mono, 'bootId' => $bootId, 'status' => $status,
        'cpu' => ['model' => rackline_metrics_cpu_model(rackline_metrics_read($root . '/proc/cpuinfo')),
            'threadCount' => $threads > 0 ? $threads : null, 'physicalCoreCount' => $physical,
            'counters' => (object) $counters, 'cores' => $topology],
        'memory' => $memory, 'network' => $network,
        'cooling' => ['cpuTempC' => $temperatures['package'], 'packageTempsC' => $temperatures['packages'],
            'packageCoverageComplete' => $temperatures['packageCoverageComplete']] + rackline_metrics_coolant($root)];
    if ($status === 'unavailable') $result['error'] = 'Native system counters unavailable';
    return $result;
}

if (!defined('RACKLINE_METRICS_NO_MAIN')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            echo json_encode(['schemaVersion' => 1, 'error' => 'GET required']);
        } else {
            $snapshot = rackline_metrics_snapshot();
            if ($snapshot['status'] === 'unavailable') http_response_code(503);
            echo json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['schemaVersion' => 1, 'error' => 'Unable to read Rackline system telemetry']);
    }
}
