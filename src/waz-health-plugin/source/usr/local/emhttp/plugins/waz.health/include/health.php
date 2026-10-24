<?php

declare(strict_types=1);

function waz_health_allowed_state(string $state): string
{
    $state = strtolower(trim($state));
    return in_array($state, ['normal', 'attention', 'fault', 'unknown'], true)
        ? $state
        : 'unknown';
}

function waz_health_state_rank(string $state): int
{
    $ranks = ['normal' => 0, 'unknown' => 1, 'attention' => 2, 'fault' => 3];
    return $ranks[waz_health_allowed_state($state)] ?? 1;
}

function waz_health_display_name(array $config, ?string $hostname = null): string
{
    $name = trim((string) ($config['DISPLAY_NAME'] ?? ''));
    if ($name !== '' && !in_array(strtolower($name), ['auto', 'waz-server'], true)) {
        return $name;
    }
    $hostname = trim($hostname ?? (gethostname() ?: ''));
    return $hostname !== '' ? $hostname : 'SERVER';
}

function waz_health_read_config(): array
{
    $defaults = [
        'DISPLAY_NAME' => 'auto',
        'UPS_APC_TARGET' => '',
        'OVERALL_STATE' => 'normal',
        'OVERALL_MESSAGE' => '',
        'ARRAY_STATE' => 'normal',
        'ARRAY_MESSAGE' => '',
        'STORAGE_STATE' => 'normal',
        'STORAGE_MESSAGE' => '',
        'COOLING_STATE' => 'normal',
        'COOLING_MESSAGE' => '',
        'UPS_STATE' => 'normal',
        'UPS_MESSAGE' => '',
        'STORAGE_WARN_PERCENT' => '95',
        'STORAGE_FAULT_PERCENT' => '98',
        'CPU_WARN_C' => '60',
        'CPU_FAULT_C' => '70',
        'COOLANT_WARN_C' => '35',
        'COOLANT_FAULT_C' => '40',
        'FLOW_WARN_LPH' => '130',
        'FLOW_FAULT_LPH' => '120',
        'THROTTLE_LATCH_SECONDS' => '300',
        'UPS_LOAD_WARN_PERCENT' => '90',
        'UPS_LOAD_FAULT_PERCENT' => '100',
    ];

    $path = '/boot/config/plugins/waz.health/waz.health.cfg';
    $configured = is_file($path) ? @parse_ini_file($path, false, INI_SCANNER_RAW) : [];
    return array_merge($defaults, is_array($configured) ? $configured : []);
}

function waz_health_config_number(array $config, string $key, float $fallback): float
{
    $value = $config[$key] ?? null;
    return is_numeric($value) ? (float) $value : $fallback;
}

function waz_health_read_number(string $path): ?float
{
    $raw = @file_get_contents($path);
    if ($raw === false || !is_numeric(trim($raw))) {
        return null;
    }
    return (float) trim($raw);
}

function waz_health_first_number($value): ?float
{
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (preg_match('/-?[0-9]+(?:\.[0-9]+)?/', (string) $value, $matches)) {
        return (float) $matches[0];
    }
    return null;
}

function waz_health_format_number(float $value, int $decimals = 1): string
{
    if ($decimals <= 0) {
        return number_format($value, 0, '.', '');
    }
    return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
}

function waz_health_uptime_seconds(): ?int
{
    $raw = @file_get_contents('/proc/uptime');
    if ($raw === false) {
        return null;
    }
    $firstField = strtok(trim($raw), " \t");
    return $firstField !== false && is_numeric($firstField)
        ? max(0, (int) floor((float) $firstField))
        : null;
}

function waz_health_status_from_config(array $config, string $name): array
{
    $prefix = strtoupper($name);
    return [
        'label' => $prefix,
        'state' => waz_health_allowed_state((string) ($config[$prefix . '_STATE'] ?? 'normal')),
        'message' => trim((string) ($config[$prefix . '_MESSAGE'] ?? '')),
    ];
}

function waz_health_merge_status(array $configured, array $detected): array
{
    $configuredRank = waz_health_state_rank((string) ($configured['state'] ?? 'normal'));
    $detectedRank = waz_health_state_rank((string) ($detected['state'] ?? 'normal'));
    if ($detectedRank > $configuredRank) {
        $result = $detected;
    } elseif ($configuredRank > $detectedRank) {
        $result = $configured;
    } else {
        $result = $detected;
        $messages = array_values(array_unique(array_filter([
            trim((string) ($configured['message'] ?? '')),
            trim((string) ($detected['message'] ?? '')),
        ])));
        $result['message'] = implode(' · ', $messages);
    }
    $result['label'] = $configured['label'] ?? $detected['label'] ?? '';
    if (isset($detected['metrics'])) {
        $result['metrics'] = $detected['metrics'];
    }
    return $result;
}

function waz_health_array_status(): array
{
    $result = ['label' => 'ARRAY', 'state' => 'normal', 'message' => ''];
    $values = is_file('/var/local/emhttp/var.ini')
        ? @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW)
        : [];
    if (!is_array($values) || !$values) {
        $result['state'] = 'unknown';
        $result['message'] = 'Array status is unavailable';
        return $result;
    }

    $values = array_change_key_case($values, CASE_LOWER);
    $issues = [];
    $state = strtoupper(trim((string) ($values['mdstate'] ?? '')));
    if ($state !== '' && $state !== 'STARTED') {
        $issues[] = $state === 'STOPPED' ? 'Array stopped' : 'Array state ' . strtolower($state);
    }
    foreach (['mdnummissing' => 'missing', 'mdnumdisabled' => 'disabled', 'mdnuminvalid' => 'invalid'] as $key => $label) {
        $count = isset($values[$key]) && is_numeric($values[$key]) ? (int) $values[$key] : 0;
        if ($count > 0) {
            $issues[] = $count . ' ' . $label . ' disk' . ($count === 1 ? '' : 's');
        }
    }
    $syncErrors = isset($values['mdsyncerrs']) && is_numeric($values['mdsyncerrs'])
        ? (int) $values['mdsyncerrs']
        : 0;
    if ($syncErrors > 0) {
        $issues[] = $syncErrors . ' parity error' . ($syncErrors === 1 ? '' : 's');
    }
    if ($issues) {
        $result['state'] = 'fault';
        $result['message'] = implode(' · ', $issues);
    }
    return $result;
}

function waz_health_storage_label(string $section, array $disk): string
{
    $name = trim((string) ($disk['name'] ?? $section));
    if (preg_match('/^disk(\d+)$/i', $name, $matches)) {
        return 'Disk ' . $matches[1];
    }
    return ucwords(str_replace(['-', '_'], ' ', $name));
}

function waz_health_storage_status(array $config): array
{
    $warn = waz_health_config_number($config, 'STORAGE_WARN_PERCENT', 95.0);
    $fault = waz_health_config_number($config, 'STORAGE_FAULT_PERCENT', 98.0);
    $result = [
        'label' => 'STORAGE',
        'state' => 'normal',
        'message' => '',
        'metrics' => ['highestUsagePercent' => null],
    ];
    $disks = is_file('/var/local/emhttp/disks.ini')
        ? @parse_ini_file('/var/local/emhttp/disks.ini', true, INI_SCANNER_RAW)
        : [];
    if (!is_array($disks)) {
        return $result;
    }

    $issues = [];
    $highestUsage = null;
    foreach ($disks as $section => $disk) {
        if (!is_array($disk)) {
            continue;
        }
        $sizeRaw = str_replace(',', '', (string) ($disk['fsSize'] ?? ''));
        $freeRaw = str_replace(',', '', (string) ($disk['fsFree'] ?? ''));
        if (!is_numeric($sizeRaw) || !is_numeric($freeRaw) || (float) $sizeRaw <= 0.0) {
            continue;
        }
        $size = (float) $sizeRaw;
        $free = max(0.0, (float) $freeRaw);
        $usage = max(0.0, min(100.0, (($size - $free) / $size) * 100.0));
        $highestUsage = $highestUsage === null ? $usage : max($highestUsage, $usage);
        if ($usage < $warn) {
            continue;
        }
        $issueState = $usage >= $fault ? 'fault' : 'attention';
        $threshold = $issueState === 'fault' ? $fault : $warn;
        $issues[] = [
            'state' => $issueState,
            'usage' => $usage,
            'message' => waz_health_storage_label((string) $section, $disk)
                . ' ' . waz_health_format_number($usage) . '% full / '
                . waz_health_format_number($threshold, 0) . '% '
                . ($issueState === 'fault' ? 'fault' : 'warn'),
        ];
    }
    $result['metrics']['highestUsagePercent'] = $highestUsage === null ? null : round($highestUsage, 1);
    usort($issues, static function (array $left, array $right): int {
        $rank = waz_health_state_rank($right['state']) <=> waz_health_state_rank($left['state']);
        return $rank !== 0 ? $rank : ($right['usage'] <=> $left['usage']);
    });
    if ($issues) {
        $result['state'] = $issues[0]['state'];
        $shown = array_slice($issues, 0, 3);
        $messages = array_column($shown, 'message');
        if (count($issues) > count($shown)) {
            $messages[] = '+' . (count($issues) - count($shown)) . ' more';
        }
        $result['message'] = implode(' · ', $messages);
    }
    return $result;
}

function waz_health_hwmon_directories(string $deviceName): array
{
    $matches = [];
    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $directory) {
        $name = @file_get_contents($directory . '/name');
        if ($name !== false && strtolower(trim($name)) === strtolower($deviceName)) {
            $matches[] = $directory;
        }
    }
    return $matches;
}

function waz_health_hwmon_labeled_value(string $deviceName, string $kind, array $wantedLabels, float $divisor): ?float
{
    $wantedLabels = array_map('strtolower', $wantedLabels);
    foreach (waz_health_hwmon_directories($deviceName) as $directory) {
        foreach (glob($directory . '/' . $kind . '*_input') ?: [] as $inputPath) {
            $prefix = substr($inputPath, 0, -strlen('_input'));
            $label = @file_get_contents($prefix . '_label');
            if ($label === false || !in_array(strtolower(trim($label)), $wantedLabels, true)) {
                continue;
            }
            $value = waz_health_read_number($inputPath);
            if ($value !== null) {
                return $value / $divisor;
            }
        }
    }
    return null;
}

function waz_health_cpu_temperature(): ?float
{
    $temperature = waz_health_hwmon_labeled_value('coretemp', 'temp', ['Package id 0', 'CPU Temp'], 1000.0);
    if ($temperature !== null) {
        return $temperature;
    }
    foreach (waz_health_hwmon_directories('coretemp') as $directory) {
        $fallback = waz_health_read_number($directory . '/temp1_input');
        if ($fallback !== null) {
            return $fallback / 1000.0;
        }
    }
    return null;
}

function waz_health_throttle_status(int $latchSeconds): array
{
    $base = '/sys/devices/system/cpu/cpu0/thermal_throttle/';
    $current = [
        'packageCount' => waz_health_read_number($base . 'package_throttle_count'),
        'packageTimeMs' => waz_health_read_number($base . 'package_throttle_total_time_ms'),
        'coreCount' => waz_health_read_number($base . 'core_throttle_count'),
        'coreTimeMs' => waz_health_read_number($base . 'core_throttle_total_time_ms'),
    ];
    if ($current['packageCount'] === null && $current['coreCount'] === null) {
        return ['active' => false, 'lastEventAt' => null];
    }

    $handle = @fopen('/var/tmp/waz-health-throttle-state.json', 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        return ['active' => false, 'lastEventAt' => null];
    }
    $raw = stream_get_contents($handle);
    $previous = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
    $now = time();
    $lastEventAt = is_array($previous) && isset($previous['lastEventAt'])
        ? (int) $previous['lastEventAt']
        : null;
    $increased = false;
    if (is_array($previous) && isset($previous['counters']) && is_array($previous['counters'])) {
        foreach ($current as $key => $value) {
            $old = $previous['counters'][$key] ?? null;
            if ($value !== null && is_numeric($old) && $value > (float) $old) {
                $increased = true;
            }
        }
    }
    if ($increased) {
        $lastEventAt = $now;
    }
    $encoded = json_encode(['counters' => $current, 'lastEventAt' => $lastEventAt], JSON_UNESCAPED_SLASHES);
    rewind($handle);
    ftruncate($handle, 0);
    if ($encoded !== false) {
        fwrite($handle, $encoded);
    }
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return [
        'active' => $lastEventAt !== null && ($now - $lastEventAt) < max(1, $latchSeconds),
        'lastEventAt' => $lastEventAt,
    ];
}

function waz_health_cooling_status(array $config): array
{
    $cpu = waz_health_cpu_temperature();
    $coolant = waz_health_hwmon_labeled_value('highflownext', 'temp', ['Coolant temp'], 1000.0);
    // The high flow NEXT hwmon driver exposes flow as fan input in dL/h.
    $flow = waz_health_hwmon_labeled_value('highflownext', 'fan', ['Flow [dL/h]'], 10.0);
    $throttle = waz_health_throttle_status((int) waz_health_config_number($config, 'THROTTLE_LATCH_SECONDS', 300.0));
    $issues = [];
    $missing = [];
    foreach ([['CPU', $cpu, true], ['Coolant', $coolant, filter_var($config['COOLANT_REQUIRED'] ?? false, FILTER_VALIDATE_BOOL)], ['Flow', $flow, filter_var($config['FLOW_REQUIRED'] ?? false, FILTER_VALIDATE_BOOL)]] as [$label, $value, $required]) {
        if ($required && $value === null) $missing[] = $label;
    }
    if ($throttle['active']) {
        $age = max(0, time() - (int) $throttle['lastEventAt']);
        $issues[] = [
            'state' => 'fault',
            'priority' => 0,
            'message' => 'CPU thermal throttling detected ' . ($age < 5 ? 'now' : $age . 's ago'),
        ];
    }
    foreach ([
        ['CPU', $cpu, 'CPU_WARN_C', 60.0, 'CPU_FAULT_C', 70.0, 1],
        ['Coolant', $coolant, 'COOLANT_WARN_C', 35.0, 'COOLANT_FAULT_C', 40.0, 2],
    ] as $check) {
        [$label, $value, $warnKey, $warnDefault, $faultKey, $faultDefault, $priority] = $check;
        $warn = waz_health_config_number($config, $warnKey, $warnDefault);
        $fault = waz_health_config_number($config, $faultKey, $faultDefault);
        if ($value === null || $value < $warn) {
            continue;
        }
        $issueState = $value >= $fault ? 'fault' : 'attention';
        $threshold = $issueState === 'fault' ? $fault : $warn;
        $issues[] = [
            'state' => $issueState,
            'priority' => $priority,
            'message' => $label . ' ' . waz_health_format_number($value) . '°C / '
                . waz_health_format_number($threshold, 0) . '°C '
                . ($issueState === 'fault' ? 'fault' : 'warn'),
        ];
    }
    $flowWarn = waz_health_config_number($config, 'FLOW_WARN_LPH', 130.0);
    $flowFault = waz_health_config_number($config, 'FLOW_FAULT_LPH', 120.0);
    if ($flow !== null && $flow <= $flowWarn) {
        $issueState = $flow <= $flowFault ? 'fault' : 'attention';
        $threshold = $issueState === 'fault' ? $flowFault : $flowWarn;
        $issues[] = [
            'state' => $issueState,
            'priority' => 3,
            'message' => 'Flow ' . waz_health_format_number($flow) . ' L/hr / '
                . waz_health_format_number($threshold, 0) . ' L/hr '
                . ($issueState === 'fault' ? 'fault' : 'warn'),
        ];
    }
    usort($issues, static function (array $left, array $right): int {
        $rank = waz_health_state_rank($right['state']) <=> waz_health_state_rank($left['state']);
        return $rank !== 0 ? $rank : ($left['priority'] <=> $right['priority']);
    });
    return [
        'label' => 'COOLING',
        'state' => $issues ? $issues[0]['state'] : ($missing ? 'unknown' : 'normal'),
        'message' => implode(' · ', array_merge(array_column($issues, 'message'), $missing ? ['Required cooling sensors unavailable: ' . implode(', ', $missing)] : [])),
        'metrics' => [
            'cpuTempC' => $cpu === null ? null : round($cpu, 1),
            'coolantTempC' => $coolant === null ? null : round($coolant, 1),
            'flowLph' => $flow === null ? null : round($flow, 1),
            'thermalThrottleLatched' => (bool) $throttle['active'],
        ],
    ];
}

function waz_health_find_executable(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    return null;
}

function waz_health_run_command(string $command): ?array
{
    $lines = [];
    $exitCode = 1;
    @exec($command . ' 2>/dev/null', $lines, $exitCode);
    return $exitCode === 0 ? $lines : null;
}

function waz_health_read_ups_data(): ?array
{
    $config = waz_health_read_config();
    $target = trim((string) ($config['UPS_APC_TARGET'] ?? ''));
    // A dashboard-only override reads an existing network UPS server. It does
    // not change the host's UPS daemon, shutdown policy, or network listeners.
    if ($target !== '' && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*(?::[0-9]{1,5})?$/D', $target)) {
        return ['source' => 'apcupsd', 'STATUS' => 'COMMLOST'];
    }
    $upsc = waz_health_find_executable(['/usr/bin/upsc', '/usr/sbin/upsc', '/sbin/upsc']);
    if ($target === '' && $upsc !== null) {
        $names = waz_health_run_command(escapeshellarg($upsc) . ' -l');
        $name = is_array($names) ? trim((string) ($names[0] ?? '')) : '';
        if ($name !== '') {
            $lines = waz_health_run_command(escapeshellarg($upsc) . ' ' . escapeshellarg($name));
            if (is_array($lines)) {
                $data = ['source' => 'nut', 'name' => $name];
                foreach ($lines as $line) {
                    if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                        $data[trim($matches[1])] = trim($matches[2]);
                    }
                }
                return $data;
            }
        }
    }

    $apcaccess = waz_health_find_executable(['/sbin/apcaccess', '/usr/sbin/apcaccess', '/usr/bin/apcaccess']);
    if ($apcaccess !== null) {
        $lines = waz_health_run_command(escapeshellarg($apcaccess) . ' status' . ($target !== '' ? ' ' . escapeshellarg($target) : ''));
        if (is_array($lines) && $lines) {
            $data = ['source' => 'apcupsd'];
            foreach ($lines as $line) {
                if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                    $data[strtoupper(trim($matches[1]))] = trim($matches[2]);
                }
            }
            return $data;
        }
    }
    if ($target !== '') {
        return ['source' => 'apcupsd', 'STATUS' => 'COMMLOST'];
    }
    return null;
}

function waz_health_ups_status(array $config): array
{
    $data = waz_health_read_ups_data();
    $result = [
        'label' => 'UPS',
        'state' => 'normal',
        'message' => '',
        'metrics' => ['available' => false],
    ];
    if ($data === null) {
        return $result;
    }

    $isNut = ($data['source'] ?? '') === 'nut';
    $status = strtoupper((string) ($isNut ? ($data['ups.status'] ?? '') : ($data['STATUS'] ?? '')));
    $charge = waz_health_first_number($isNut ? ($data['battery.charge'] ?? null) : ($data['BCHARGE'] ?? null));
    $runtimeSeconds = $isNut
        ? waz_health_first_number($data['battery.runtime'] ?? null)
        : (($minutes = waz_health_first_number($data['TIMELEFT'] ?? null)) === null ? null : $minutes * 60.0);
    $load = waz_health_first_number($isNut ? ($data['ups.load'] ?? null) : ($data['LOADPCT'] ?? null));
    $model = trim((string) ($isNut ? ($data['ups.model'] ?? '') : ($data['MODEL'] ?? '')));
    $tokens = preg_split('/[\s,]+/', $status, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $faultTokens = ['LB', 'RB', 'OVER', 'FSD', 'OFF', 'COMMLOST', 'LOWBATT', 'REPLACEBATT', 'OVERLOAD'];
    $attentionTokens = ['OB', 'CAL', 'TRIM', 'BOOST', 'BYPASS', 'ONBATT'];
    $issues = [];

    foreach ($faultTokens as $token) {
        if (in_array($token, $tokens, true) || strpos($status, $token) !== false) {
            $labels = [
                'LB' => 'Low battery', 'LOWBATT' => 'Low battery',
                'RB' => 'Replace battery', 'REPLACEBATT' => 'Replace battery',
                'OVER' => 'UPS overload', 'OVERLOAD' => 'UPS overload',
                'COMMLOST' => 'UPS communication lost',
                'FSD' => 'Forced shutdown active', 'OFF' => 'UPS output off',
            ];
            $issues[] = ['state' => 'fault', 'message' => $labels[$token] ?? $token];
        }
    }
    if (!$issues) {
        foreach ($attentionTokens as $token) {
            if (in_array($token, $tokens, true) || strpos($status, $token) !== false) {
                $issues[] = [
                    'state' => 'attention',
                    'message' => in_array($token, ['OB', 'ONBATT'], true) ? 'On battery' : 'UPS ' . strtolower($token),
                ];
                break;
            }
        }
    }

    $loadWarn = waz_health_config_number($config, 'UPS_LOAD_WARN_PERCENT', 90.0);
    $loadFault = waz_health_config_number($config, 'UPS_LOAD_FAULT_PERCENT', 100.0);
    if ($load !== null && $load >= $loadWarn) {
        $issueState = $load >= $loadFault ? 'fault' : 'attention';
        $issues[] = [
            'state' => $issueState,
            'message' => 'Load ' . waz_health_format_number($load) . '% / '
                . waz_health_format_number($issueState === 'fault' ? $loadFault : $loadWarn, 0) . '% '
                . ($issueState === 'fault' ? 'fault' : 'warn'),
        ];
    }

    if ($issues) {
        usort($issues, static function (array $left, array $right): int {
            return waz_health_state_rank($right['state']) <=> waz_health_state_rank($left['state']);
        });
        $details = [];
        if ($charge !== null) {
            $details[] = waz_health_format_number($charge, 0) . '% charge';
        }
        if ($runtimeSeconds !== null) {
            $details[] = waz_health_format_number($runtimeSeconds / 60.0, 0) . 'm runtime';
        }
        $messages = array_values(array_unique(array_column($issues, 'message')));
        $result['state'] = $issues[0]['state'];
        $result['message'] = implode(' · ', array_merge($messages, $details));
    }
    $result['metrics'] = [
        'available' => true,
        'source' => $data['source'] ?? null,
        'model' => $model !== '' ? $model : null,
        'status' => $status !== '' ? $status : null,
        'chargePercent' => $charge,
        'runtimeSeconds' => $runtimeSeconds,
        'loadPercent' => $load,
    ];
    return $result;
}

function waz_health_overall_status(array $config, array $subsystems): array
{
    $state = waz_health_allowed_state((string) ($config['OVERALL_STATE'] ?? 'normal'));
    $message = trim((string) ($config['OVERALL_MESSAGE'] ?? ''));
    foreach ($subsystems as $subsystem) {
        if (waz_health_state_rank((string) $subsystem['state']) > waz_health_state_rank($state)) {
            $state = (string) $subsystem['state'];
            $message = '';
        }
    }
    $labels = [
        'normal' => 'SYSTEM NORMAL',
        'attention' => 'ATTENTION',
        'fault' => 'FAULT',
        'unknown' => 'STATUS UNKNOWN',
    ];
    return ['label' => $labels[$state] ?? 'STATUS UNKNOWN', 'state' => $state, 'message' => $message];
}

function waz_health_snapshot(): array
{
    $config = waz_health_read_config();
    $subsystems = [
        'array' => waz_health_merge_status(waz_health_status_from_config($config, 'array'), waz_health_array_status()),
        'storage' => waz_health_merge_status(waz_health_status_from_config($config, 'storage'), waz_health_storage_status($config)),
        'cooling' => waz_health_merge_status(waz_health_status_from_config($config, 'cooling'), waz_health_cooling_status($config)),
        'ups' => waz_health_merge_status(waz_health_status_from_config($config, 'ups'), waz_health_ups_status($config)),
    ];
    return [
        'schemaVersion' => 3,
        'version' => '@@APP_VERSION@@',
        'pluginVersion' => '@@PLUGIN_VERSION@@',
        'server' => [
            'label' => waz_health_display_name($config),
            'uptimeSeconds' => waz_health_uptime_seconds(),
        ],
        'overall' => waz_health_overall_status($config, $subsystems),
        'subsystems' => $subsystems,
        'generatedAt' => time(),
    ];
}
