<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const WAZ_DASHBOARD_VERSION = '@@APP_VERSION@@';
const WAZ_DASHBOARD_PLUGIN_VERSION = '@@PLUGIN_VERSION@@';
const WAZ_DASHBOARD_CONFIG = '/boot/config/plugins/waz.dashboard/waz.dashboard.cfg';
const WAZ_DASHBOARD_STATE = '/var/run/waz.dashboard';

function waz_read_trimmed(string $path): ?string
{
    $value = @file_get_contents($path);
    return $value === false ? null : trim($value);
}

function waz_number(string $path, float $divisor = 1.0): ?float
{
    $value = waz_read_trimmed($path);
    return $value !== null && is_numeric($value) ? (float) $value / $divisor : null;
}

function waz_config(): array
{
    $values = is_file(WAZ_DASHBOARD_CONFIG)
        ? @parse_ini_file(WAZ_DASHBOARD_CONFIG, false, INI_SCANNER_RAW)
        : [];
    return is_array($values) ? $values : [];
}

function waz_hwmon_paths(string $wanted): array
{
    $paths = [];
    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $path) {
        if (strcasecmp((string) waz_read_trimmed($path . '/name'), $wanted) === 0) {
            $paths[] = $path;
        }
    }
    return $paths;
}

function waz_hwmon_labeled(string $device, string $kind, array $labels, float $divisor): ?float
{
    $labels = array_map('strtolower', $labels);
    foreach (waz_hwmon_paths($device) as $directory) {
        foreach (glob($directory . '/' . $kind . '*_input') ?: [] as $input) {
            $label = waz_read_trimmed(substr($input, 0, -6) . '_label');
            if ($label !== null && in_array(strtolower($label), $labels, true)) {
                $value = waz_number($input, $divisor);
                if ($value !== null) {
                    return $value;
                }
            }
        }
    }
    return null;
}

function waz_cpu_model(): string
{
    foreach (@file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^model name\s*:\s*(.+)$/i', $line, $match)) {
            return trim($match[1]);
        }
    }
    return php_uname('m');
}

function waz_cpu_counters(): array
{
    $result = [];
    foreach (@file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!preg_match('/^(cpu\d*)\s+(.+)$/', $line, $match)) {
            continue;
        }
        $values = array_map('intval', preg_split('/\s+/', trim($match[2])) ?: []);
        $result[$match[1]] = [
            'total' => array_sum($values),
            'idle' => ($values[3] ?? 0) + ($values[4] ?? 0),
        ];
    }
    return $result;
}

function waz_core_temperatures(): array
{
    $temperatures = ['package' => null, 'cores' => []];
    foreach (waz_hwmon_paths('coretemp') as $directory) {
        foreach (glob($directory . '/temp*_input') ?: [] as $input) {
            $value = waz_number($input, 1000.0);
            $label = waz_read_trimmed(substr($input, 0, -6) . '_label');
            if ($value === null || $label === null) {
                continue;
            }
            if (stripos($label, 'Package id') === 0 || strcasecmp($label, 'CPU Temp') === 0) {
                $temperatures['package'] = $value;
            } elseif (preg_match('/^Core\s+(\d+)$/i', $label, $match)) {
                $temperatures['cores'][(string) (int) $match[1]] = $value;
            }
        }
    }
    return $temperatures;
}

function waz_cpu_topology(array $temperatures): array
{
    $groups = [];
    foreach (glob('/sys/devices/system/cpu/cpu[0-9]*') ?: [] as $path) {
        if (!preg_match('/cpu(\d+)$/', $path, $match) || waz_read_trimmed($path . '/online') === '0') {
            continue;
        }
        $cpuId = (int) $match[1];
        $coreId = waz_read_trimmed($path . '/topology/core_id');
        $packageId = waz_read_trimmed($path . '/topology/physical_package_id');
        if ($coreId === null) {
            continue;
        }
        $key = ($packageId ?? '0') . ':' . $coreId;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'coreId' => (int) $coreId,
                'packageId' => (int) ($packageId ?? 0),
                'tempC' => $temperatures['cores'][(string) (int) $coreId] ?? null,
                'threads' => [],
            ];
        }
        $groups[$key]['threads'][] = ['cpuId' => $cpuId];
    }
    foreach ($groups as &$group) {
        usort($group['threads'], static fn(array $a, array $b): int => $a['cpuId'] <=> $b['cpuId']);
    }
    unset($group);
    $result = array_values($groups);
    usort($result, static fn(array $a, array $b): int => [$a['packageId'], $a['coreId']] <=> [$b['packageId'], $b['coreId']]);
    return $result;
}

function waz_memory_values(): array
{
    $values = [];
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $match)) {
            $values[$match[1]] = (int) $match[2] * 1024;
        }
    }
    return $values;
}

function waz_memory_inventory(): array
{
    $cachePath = WAZ_DASHBOARD_STATE . '/memory-inventory.json';
    if (is_file($cachePath) && (time() - (int) @filemtime($cachePath)) < 21600) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $dmidecode = null;
    foreach (['/usr/sbin/dmidecode', '/sbin/dmidecode', '/usr/bin/dmidecode'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $dmidecode = $candidate;
            break;
        }
    }
    $lines = [];
    if ($dmidecode !== null) {
        @exec(escapeshellarg($dmidecode) . ' --type 16 --type 17 2>/dev/null', $lines);
    }

    $ecc = false;
    $devices = [];
    $current = null;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^Error Correction Type:\s*(.+)$/i', $trimmed, $match)) {
            $type = strtolower(trim($match[1]));
            $ecc = !in_array($type, ['none', 'unknown', 'other'], true);
        }
        if ($trimmed === 'Memory Device') {
            if (is_array($current) && isset($current['sizeGiB'])) {
                $devices[] = $current;
            }
            $current = [];
            continue;
        }
        if (!is_array($current)) {
            continue;
        }
        if (preg_match('/^Size:\s*(\d+)\s+(MB|GB|GiB)$/i', $trimmed, $match)) {
            $size = (float) $match[1];
            $unit = strtoupper($match[2]);
            $current['sizeGiB'] = $unit === 'MB' ? $size / 1024 : $size;
        } elseif (preg_match('/^Locator:\s*(.+)$/i', $trimmed, $match)) {
            $current['locator'] = trim($match[1]);
        } elseif (preg_match('/^Type:\s*(.+)$/i', $trimmed, $match)) {
            $current['type'] = trim($match[1]);
        } elseif (preg_match('/^Configured Memory Speed:\s*(\d+)\s+MT\/s$/i', $trimmed, $match)) {
            $current['speed'] = (int) $match[1];
        } elseif (preg_match('/^Total Width:\s*(\d+)\s+bits$/i', $trimmed, $match)) {
            $current['totalWidth'] = (int) $match[1];
        } elseif (preg_match('/^Data Width:\s*(\d+)\s+bits$/i', $trimmed, $match)) {
            $current['dataWidth'] = (int) $match[1];
        }
    }
    if (is_array($current) && isset($current['sizeGiB'])) {
        $devices[] = $current;
    }
    foreach ($devices as $device) {
        if (($device['totalWidth'] ?? 0) > ($device['dataWidth'] ?? 0)) {
            $ecc = true;
        }
    }

    $total = array_sum(array_column($devices, 'sizeGiB'));
    $sizes = array_values(array_unique(array_map(static fn(array $item): float => (float) $item['sizeGiB'], $devices)));
    $types = array_values(array_unique(array_filter(array_column($devices, 'type'))));
    $speeds = array_values(array_unique(array_filter(array_column($devices, 'speed'))));
    $summary = '';
    if ($devices) {
        $summary = number_format($total, 0) . ' GiB ' . ($types[0] ?? 'RAM') . ($ecc ? ' ECC' : '')
            . ' · ' . count($devices) . '×' . (count($sizes) === 1 ? number_format($sizes[0], 0) . ' GiB' : 'mixed')
            . ($speeds ? ' · ' . max($speeds) . ' MT/s' : '');
    }
    $result = ['summary' => $summary, 'ecc' => $ecc, 'devices' => $devices];
    @mkdir(WAZ_DASHBOARD_STATE, 0755, true);
    @file_put_contents($cachePath . '.tmp', json_encode($result, JSON_UNESCAPED_SLASHES));
    @rename($cachePath . '.tmp', $cachePath);
    return $result;
}

function waz_rapl_domains(): array
{
    $domains = [];
    foreach (glob('/sys/class/powercap/intel-rapl:*') ?: [] as $path) {
        $name = waz_read_trimmed($path . '/name');
        $energy = waz_number($path . '/energy_uj');
        if ($name === null || stripos($name, 'package-') !== 0 || $energy === null) {
            continue;
        }
        $domains[basename($path)] = [
            'energyUj' => $energy,
            'maxEnergyUj' => waz_number($path . '/max_energy_range_uj') ?? 0,
        ];
    }
    return $domains;
}

function waz_first_number(?string $value): ?float
{
    return $value !== null && preg_match('/-?\d+(?:\.\d+)?/', $value, $match)
        ? (float) $match[0]
        : null;
}

function waz_ups_rack_power(): array
{
    $cachePath = WAZ_DASHBOARD_STATE . '/ups-power.json';
    if (is_file($cachePath) && time() - (int) @filemtime($cachePath) < 5) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $result = ['available' => false, 'source' => null, 'watts' => null, 'loadPercent' => null, 'nominalWatts' => null];
    require_once __DIR__ . '/health.php';
    $values = waz_health_read_ups_data();
    if ($values !== null) {
        $nut = ($values['source'] ?? '') === 'nut';
        $load = waz_first_number($values[$nut ? 'ups.load' : 'LOADPCT'] ?? null);
        $nominal = waz_first_number($values[$nut ? 'ups.realpower.nominal' : 'NOMPOWER'] ?? null);
        $watts = $load !== null && $nominal !== null ? $nominal * $load / 100.0 : null;
        $result = [
            'available' => $watts !== null,
            'source' => $values['source'],
            'watts' => $watts,
            'loadPercent' => $load,
            'nominalWatts' => $nominal,
        ];
    }

    @mkdir(WAZ_DASHBOARD_STATE, 0755, true);
    @file_put_contents($cachePath . '.tmp', json_encode($result, JSON_UNESCAPED_SLASHES));
    @rename($cachePath . '.tmp', $cachePath);
    return $result;
}

function waz_default_interface(): ?string
{
    foreach (@file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (($parts[1] ?? null) === '00000000' && ($parts[0] ?? '') !== 'Iface') {
            return $parts[0];
        }
    }
    return null;
}

function waz_network_members(string $interface): array
{
    $names = preg_split('/\s+/', trim((string) waz_read_trimmed('/sys/class/net/' . $interface . '/bonding/slaves'))) ?: [];
    $members = [];
    $bondText = is_file('/proc/net/bonding/' . $interface)
        ? (string) @file_get_contents('/proc/net/bonding/' . $interface)
        : '';
    foreach (array_filter($names) as $name) {
        $failures = null;
        if (preg_match('/Slave Interface:\s*' . preg_quote($name, '/') . '.*?Link Failure Count:\s*(\d+)/si', $bondText, $match)) {
            $failures = (int) $match[1];
        }
        $members[] = [
            'name' => $name,
            'up' => waz_read_trimmed('/sys/class/net/' . $name . '/operstate') === 'up',
            'speedMbps' => waz_number('/sys/class/net/' . $name . '/speed'),
            'duplex' => strtolower((string) waz_read_trimmed('/sys/class/net/' . $name . '/duplex')),
            'linkFailureCount' => $failures,
        ];
    }
    return $members;
}

function waz_network_snapshot(array $config): array
{
    $configured = trim((string) ($config['PRIMARY_INTERFACE'] ?? 'bond0'));
    $interface = is_dir('/sys/class/net/' . $configured) ? $configured : (waz_default_interface() ?? $configured);
    $base = '/sys/class/net/' . $interface;
    $bondText = is_file('/proc/net/bonding/' . $interface)
        ? (string) @file_get_contents('/proc/net/bonding/' . $interface)
        : '';
    $mode = null;
    if (preg_match('/^Bonding Mode:\s*(.+)$/mi', $bondText, $match)) {
        $mode = stripos($match[1], '802.3ad') !== false ? 'LACP' : trim($match[1]);
    }
    return [
        'interface' => $interface,
        'up' => waz_read_trimmed($base . '/operstate') === 'up',
        'speedMbps' => waz_number($base . '/speed'),
        'duplex' => strtolower((string) waz_read_trimmed($base . '/duplex')),
        'mode' => $mode,
        'mtu' => waz_number($base . '/mtu'),
        'counters' => [
            'rxBytes' => waz_number($base . '/statistics/rx_bytes') ?? 0,
            'txBytes' => waz_number($base . '/statistics/tx_bytes') ?? 0,
            'rxDropped' => waz_number($base . '/statistics/rx_dropped') ?? 0,
            'txDropped' => waz_number($base . '/statistics/tx_dropped') ?? 0,
        ],
        'members' => waz_network_members($interface),
    ];
}

function waz_gpu_snapshot(): array
{
    $path = WAZ_DASHBOARD_STATE . '/gpu.json';
    if (!is_file($path)) {
        return ['available' => false, 'reason' => 'GPU watcher starting'];
    }
    $result = json_decode((string) @file_get_contents($path), true);
    if (!is_array($result)) {
        return ['available' => false, 'reason' => 'GPU data unavailable'];
    }
    $sampledAt = isset($result['sampledAtMs']) ? (int) $result['sampledAtMs'] : 0;
    $result['stale'] = $sampledAt === 0 || ((int) round(microtime(true) * 1000) - $sampledAt) > 5000;
    return $result;
}

function waz_filesystem_usage(string $path): array
{
    if (!is_dir($path)) {
        return ['available' => false, 'path' => $path];
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false || $total <= 0) {
        return ['available' => false, 'path' => $path];
    }
    $used = max(0.0, (float) $total - (float) $free);
    return [
        'available' => true,
        'path' => $path,
        'totalBytes' => (float) $total,
        'usedBytes' => $used,
        'freeBytes' => (float) $free,
        'usagePercent' => $used * 100.0 / (float) $total,
    ];
}

function waz_snapshot(array $config): array
{
    $temperatures = waz_core_temperatures();
    $topology = waz_cpu_topology($temperatures);
    $memory = waz_memory_values();
    $inventory = waz_memory_inventory();
    $threadCount = array_sum(array_map(static fn(array $core): int => count($core['threads']), $topology));
    $coolant = waz_hwmon_labeled('highflownext', 'temp', ['Coolant temp'], 1000.0);
    $flow = waz_hwmon_labeled('highflownext', 'fan', ['Flow [dL/h]'], 10.0);

    return [
        'schemaVersion' => 1,
        'version' => WAZ_DASHBOARD_VERSION,
        'pluginVersion' => WAZ_DASHBOARD_PLUGIN_VERSION,
        'sampledAtMs' => (int) round(microtime(true) * 1000),
        'cpu' => [
            'model' => waz_cpu_model(),
            'threadCount' => $threadCount,
            'counters' => waz_cpu_counters(),
            'cores' => $topology,
        ],
        'memory' => [
            'totalBytes' => $memory['MemTotal'] ?? null,
            'availableBytes' => $memory['MemAvailable'] ?? null,
            'cachedBytes' => ($memory['Cached'] ?? 0) + ($memory['SReclaimable'] ?? 0) - ($memory['Shmem'] ?? 0),
            'hardwareSummary' => $inventory['summary'] ?: null,
            'ecc' => (bool) ($inventory['ecc'] ?? false),
        ],
        'filesystems' => [
            'log' => waz_filesystem_usage('/var/log'),
        ],
        'gpu' => waz_gpu_snapshot(),
        'network' => waz_network_snapshot($config),
        'power' => [
            'rack' => waz_ups_rack_power(),
            'raplDomains' => waz_rapl_domains(),
        ],
        'cooling' => [
            'cpuTempC' => $temperatures['package'],
            'coolantTempC' => $coolant,
            'flowLph' => $flow,
        ],
    ];
}

try {
    $config = waz_config();
    $output = waz_snapshot($config);
    echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'schemaVersion' => 1,
        'error' => 'Unable to read WAZ System telemetry',
    ], JSON_UNESCAPED_SLASHES);
}
