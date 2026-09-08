<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const WAZ_STORAGE_VERSION = '@@APP_VERSION@@';
const WAZ_STORAGE_WARN_PERCENT = 95.0;
const WAZ_STORAGE_FAULT_PERCENT = 98.0;
const WAZ_DISKS_INI = '/var/local/emhttp/disks.ini';
const WAZ_VAR_INI = '/var/local/emhttp/var.ini';
const WAZ_SMART_ONE_CFG = '/boot/config/smart-one.cfg';
const WAZ_POOL_CONFIG_DIR = '/boot/config/pools';
const WAZ_LOCATION_DIR = '/boot/config/plugins/disklocation';
const WAZ_DYNAMIX_CFG = '/boot/config/plugins/dynamix/dynamix.cfg';
const WAZ_PARITY_CRON = '/boot/config/plugins/dynamix/parity-check.cron';

function waz_storage_ini(string $path, bool $sections = false): array
{
    if (!is_file($path)) {
        return [];
    }
    $values = @parse_ini_file($path, $sections, INI_SCANNER_RAW);
    return is_array($values) ? $values : [];
}

function waz_storage_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $values = json_decode((string) @file_get_contents($path), true);
    return is_array($values) ? $values : [];
}

function waz_storage_number($value): ?float
{
    $clean = str_replace(',', '', trim((string) $value));
    return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
}

function waz_storage_kib_bytes($value): ?float
{
    $number = waz_storage_number($value);
    return $number === null ? null : $number * 1024.0;
}

function waz_storage_usage(?float $size, ?float $used, ?float $free): ?float
{
    if ($size === null || $size <= 0) {
        return null;
    }
    if ($used === null && $free !== null) {
        $used = max(0.0, $size - $free);
    }
    return $used === null ? null : max(0.0, min(100.0, $used * 100.0 / $size));
}

function waz_storage_state_rank(string $state): int
{
    return ['normal' => 0, 'attention' => 1, 'fault' => 2][$state] ?? 0;
}

function waz_storage_worst_state(array $states): string
{
    $worst = 'normal';
    foreach ($states as $state) {
        if (waz_storage_state_rank((string) $state) > waz_storage_state_rank($worst)) {
            $worst = (string) $state;
        }
    }
    return $worst;
}

function waz_storage_title(string $name): string
{
    $labels = [
        'cache' => 'Cache',
        'servers' => 'Servers',
        'downloads-cache' => 'Downloads',
        'akashic' => 'Akashic',
    ];
    return $labels[$name] ?? ucwords(str_replace(['-', '_'], ' ', $name));
}

function waz_storage_identity_color(string $key): string
{
    $palette = ['#22b8f0', '#a78bfa', '#2dd4bf', '#f2a900', '#e879f9', '#60a5fa', '#f97316', '#34d399'];
    $index = (int) sprintf('%u', crc32(strtolower($key))) % count($palette);
    return $palette[$index];
}

function waz_storage_media(array $disk): string
{
    if (strtolower((string) ($disk['transport'] ?? '')) === 'nvme') {
        return 'NVMe';
    }
    return (string) ($disk['rotational'] ?? '1') === '1' ? 'HDD' : 'SSD';
}

function waz_storage_temperature_setting($value): ?float
{
    $number = waz_storage_number($value);
    return $number !== null && $number >= 0 ? $number : null;
}

function waz_storage_temperature_thresholds(array $disk, array $smartSettings, array $displaySettings): array
{
    $id = trim((string) ($disk['id'] ?? ''));
    $specific = $id !== '' && is_array($smartSettings[$id] ?? null) ? $smartSettings[$id] : [];
    $settings = array_merge($disk, $specific);
    $media = waz_storage_media($disk);

    $warning = waz_storage_temperature_setting($settings['hotTemp'] ?? null);
    $critical = waz_storage_temperature_setting($settings['maxTemp'] ?? null);

    $globalWarningKey = $media === 'HDD' ? 'hot' : 'hotssd';
    $globalCriticalKey = $media === 'HDD' ? 'max' : 'maxssd';
    if ($warning === null) {
        $warning = waz_storage_temperature_setting($displaySettings[$globalWarningKey] ?? null);
        if ($warning === null && $media !== 'HDD') {
            $warning = waz_storage_temperature_setting($displaySettings['hot'] ?? null);
        }
        if ($warning === null) {
            $warning = $media === 'HDD' ? 45.0 : 65.0;
        }
    }
    if ($critical === null) {
        $critical = waz_storage_temperature_setting($displaySettings[$globalCriticalKey] ?? null);
        if ($critical === null && $media !== 'HDD') {
            $critical = waz_storage_temperature_setting($displaySettings['max'] ?? null);
        }
        if ($critical === null) {
            $critical = $media === 'HDD' ? 50.0 : 75.0;
        }
    }

    return ['warning' => $warning, 'critical' => $critical];
}

function waz_storage_disk_records(): array
{
    $smartSettings = waz_storage_ini(WAZ_SMART_ONE_CFG, true);
    $dynamixConfig = waz_storage_ini(WAZ_DYNAMIX_CFG, true);
    $displaySettings = is_array($dynamixConfig['display'] ?? null) ? $dynamixConfig['display'] : [];
    $records = [];
    foreach (waz_storage_ini(WAZ_DISKS_INI, true) as $section => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $name = trim((string) ($raw['name'] ?? trim((string) $section, '"')));
        $type = trim((string) ($raw['type'] ?? ''));
        if ($name === '' || !in_array($type, ['Data', 'Parity', 'Cache'], true)) {
            continue;
        }
        $size = waz_storage_kib_bytes($raw['fsSize'] ?? null);
        $used = waz_storage_kib_bytes($raw['fsUsed'] ?? null);
        $free = waz_storage_kib_bytes($raw['fsFree'] ?? null);
        $usage = waz_storage_usage($size, $used, $free);
        $temperature = waz_storage_number($raw['temp'] ?? null);
        $media = waz_storage_media($raw);
        $thresholds = waz_storage_temperature_thresholds($raw, $smartSettings, $displaySettings);
        $warning = $thresholds['warning'];
        $critical = $thresholds['critical'];
        $status = strtoupper(trim((string) ($raw['status'] ?? '')));
        $errors = (int) (waz_storage_number($raw['numErrors'] ?? null) ?? 0);
        $spunDown = $temperature === null || stripos((string) ($raw['color'] ?? ''), 'blink') !== false;
        $states = ['normal'];
        if ($status !== '' && $status !== 'DISK_OK') {
            $states[] = 'fault';
        }
        if ($errors > 0) {
            $states[] = 'fault';
        }
        if ($temperature !== null && $critical !== null && $critical > 0 && $temperature >= $critical) {
            $states[] = 'fault';
        } elseif ($temperature !== null && $warning !== null && $warning > 0 && $temperature >= $warning) {
            $states[] = 'attention';
        }
        if ($usage !== null && $usage >= WAZ_STORAGE_FAULT_PERCENT) {
            $states[] = 'fault';
        } elseif ($usage !== null && $usage >= WAZ_STORAGE_WARN_PERCENT) {
            $states[] = 'attention';
        }
        $records[] = [
            'name' => $name,
            'index' => (int) ($raw['idx'] ?? 0),
            'device' => trim((string) ($raw['device'] ?? '')),
            'type' => $type,
            'mediaType' => $media,
            'fileSystem' => trim((string) ($raw['fsType'] ?? '')) ?: null,
            'mounted' => strcasecmp(trim((string) ($raw['fsStatus'] ?? '')), 'Mounted') === 0,
            'sizeBytes' => $size,
            'usedBytes' => $used,
            'freeBytes' => $free,
            'usagePercent' => $usage,
            'temperatureC' => $temperature,
            'warningC' => $warning,
            'criticalC' => $critical,
            'spunDown' => $spunDown,
            'status' => $status,
            'statusLabel' => $spunDown ? 'STANDBY' : ($status === 'DISK_OK' ? 'ACTIVE' : ($status ?: 'UNKNOWN')),
            'errors' => $errors,
            'state' => waz_storage_worst_state($states),
            'location' => null,
            'identityColor' => null,
            'poolName' => null,
        ];
    }
    return $records;
}

function waz_storage_group_layout(array $group): array
{
    $columns = max(1, (int) ($group['grid_columns'] ?? 1));
    $rows = max(1, (int) ($group['grid_rows'] ?? 1));
    $count = $columns * $rows;
    $start = (int) ($group['tray_start_num'] ?? 1);
    $countByColumn = strcasecmp((string) ($group['grid_count'] ?? 'row'), 'column') === 0;
    $reverse = (string) ($group['tray_direction'] ?? '1') === '-1';
    $hidden = is_array($group['hide_tray'] ?? null) ? $group['hide_tray'] : [];
    $visibleNumber = 0;
    $cells = [];
    for ($position = 0; $position < $count; $position++) {
        $row = intdiv($position, $columns);
        $column = $position % $columns;
        $ordinal = $countByColumn ? $column * $rows + $row : $position;
        if ($reverse) {
            $ordinal = $count - 1 - $ordinal;
        }
        $sourceTray = $start + $ordinal;
        $isHidden = array_key_exists((string) $sourceTray, $hidden);
        if (!$isHidden) {
            $visibleNumber++;
        }
        $cells[] = [
            'sourceTray' => $sourceTray,
            'tray' => $isHidden ? null : $visibleNumber,
            'hidden' => $isHidden,
        ];
    }
    return ['columns' => $columns, 'rows' => $rows, 'cells' => $cells];
}

function waz_storage_location_data(array &$disks): array
{
    $groupsRaw = waz_storage_json(WAZ_LOCATION_DIR . '/groups.json');
    $locationsRaw = waz_storage_json(WAZ_LOCATION_DIR . '/locations.json');
    $devicesRaw = waz_storage_json(WAZ_LOCATION_DIR . '/devices.json');
    $locationByDevice = [];
    $displayTrayByGroup = [];

    foreach ($groupsRaw as $groupId => $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach (waz_storage_group_layout($group)['cells'] as $cell) {
            if (!$cell['hidden']) {
                $displayTrayByGroup[(string) $groupId][(int) $cell['sourceTray']] = (int) $cell['tray'];
            }
        }
    }

    foreach ($locationsRaw as $key => $location) {
        if (!is_array($location) || !isset($devicesRaw[$key]) || !is_array($devicesRaw[$key])) {
            continue;
        }
        $device = $devicesRaw[$key];
        if (trim((string) ($device['removed'] ?? '')) !== '') {
            continue;
        }
        $node = basename(trim((string) ($device['devicenode'] ?? '')));
        if ($node === '') {
            continue;
        }
        $locationByDevice[$node] = [
            'key' => (string) $key,
            'groupId' => (string) ($location['groupid'] ?? ''),
            'tray' => (int) ($location['tray'] ?? 0),
            'color' => preg_match('/^[0-9a-f]{6}$/i', (string) ($device['color'] ?? '')) ? '#' . $device['color'] : null,
            'comment' => trim((string) ($device['comment'] ?? '')) ?: null,
            'model' => trim((string) ($device['model_name'] ?? '')) ?: null,
        ];
    }

    $diskByDevice = [];
    foreach ($disks as &$disk) {
        $device = (string) $disk['device'];
        if (isset($locationByDevice[$device])) {
            $meta = $locationByDevice[$device];
            $groupName = (string) (($groupsRaw[$meta['groupId']]['group_name'] ?? 'Shelf'));
            $displayGroup = strcasecmp($groupName, 'Top') === 0
                ? 'MD1200 Top'
                : (strcasecmp($groupName, 'Bottom') === 0 ? 'MD1200 Bottom' : $groupName);
            $disk['location'] = [
                'groupId' => $meta['groupId'],
                'groupName' => $displayGroup,
                'tray' => $displayTrayByGroup[$meta['groupId']][$meta['tray']] ?? $meta['tray'],
                'sourceTray' => $meta['tray'],
                'label' => $displayGroup . ' ' . ($displayTrayByGroup[$meta['groupId']][$meta['tray']] ?? $meta['tray']),
            ];
            $disk['identityColor'] = $meta['color'];
        }
        $diskByDevice[$device] = &$disk;
    }
    unset($disk);

    $groups = [];
    foreach ($groupsRaw as $groupId => $group) {
        if (!is_array($group)) {
            continue;
        }
        $layout = waz_storage_group_layout($group);
        $columns = $layout['columns'];
        $rows = $layout['rows'];
        $locationKeys = [];
        foreach ($locationsRaw as $key => $location) {
            if (is_array($location) && (string) ($location['groupid'] ?? '') === (string) $groupId) {
                $locationKeys[(int) ($location['tray'] ?? 0)] = (string) $key;
            }
        }
        $cells = [];
        foreach ($layout['cells'] as $layoutCell) {
            $sourceTray = (int) $layoutCell['sourceTray'];
            $key = $locationKeys[$sourceTray] ?? null;
            $deviceMeta = $key !== null && isset($devicesRaw[$key]) && is_array($devicesRaw[$key]) ? $devicesRaw[$key] : null;
            $deviceNode = $deviceMeta ? basename(trim((string) ($deviceMeta['devicenode'] ?? ''))) : '';
            $active = $deviceMeta && trim((string) ($deviceMeta['removed'] ?? '')) === '' && isset($diskByDevice[$deviceNode])
                ? $diskByDevice[$deviceNode]
                : null;
            $cells[] = [
                'tray' => $layoutCell['tray'],
                'sourceTray' => $sourceTray,
                'hidden' => $layoutCell['hidden'],
                'disk' => $active === null ? null : [
                    'name' => $active['name'],
                    'device' => $active['device'],
                    'type' => $active['type'],
                    'mediaType' => $active['mediaType'],
                    'poolName' => $active['poolName'],
                    'state' => $active['state'],
                    'spunDown' => $active['spunDown'],
                    'temperatureC' => $active['temperatureC'],
                    'identityColor' => $active['identityColor'],
                ],
            ];
        }
        $name = trim((string) ($group['group_name'] ?? ('Shelf ' . $groupId)));
        $groups[] = [
            'id' => (string) $groupId,
            'name' => strcasecmp($name, 'Top') === 0 ? 'MD1200 Top' : (strcasecmp($name, 'Bottom') === 0 ? 'MD1200 Bottom' : $name),
            'columns' => $columns,
            'rows' => $rows,
            'cells' => $cells,
        ];
    }
    usort($groups, static fn(array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']));
    return ['available' => count($groups) > 0, 'groups' => $groups];
}

function waz_storage_pool_data(array &$disks): array
{
    $pools = [];
    $preferredOrder = ['cache' => 0, 'servers' => 1, 'downloads-cache' => 2, 'akashic' => 3];
    foreach (glob(WAZ_POOL_CONFIG_DIR . '/*.cfg') ?: [] as $path) {
        $name = basename($path, '.cfg');
        $config = waz_storage_ini($path);
        $members = [];
        foreach ($disks as &$disk) {
            if ($disk['type'] !== 'Cache') {
                continue;
            }
            if ($disk['name'] === $name || preg_match('/^' . preg_quote($name, '/') . '(\d+)$/', (string) $disk['name'])) {
                $disk['poolName'] = $name;
                if (!$disk['identityColor']) {
                    $disk['identityColor'] = waz_storage_identity_color('pool:' . $name);
                }
                $members[] = $disk;
            }
        }
        unset($disk);
        usort($members, static fn(array $left, array $right): int => strnatcasecmp((string) $left['name'], (string) $right['name']));
        $size = null;
        $used = null;
        $free = null;
        foreach ($members as $member) {
            if ($member['sizeBytes'] !== null && $member['sizeBytes'] > 0) {
                $size = $member['sizeBytes'];
                $used = $member['usedBytes'];
                $free = $member['freeBytes'];
                break;
            }
        }
        $usage = waz_storage_usage($size, $used, $free);
        $states = array_column($members, 'state');
        if ($usage !== null) {
            $states[] = $usage >= WAZ_STORAGE_FAULT_PERCENT ? 'fault' : ($usage >= WAZ_STORAGE_WARN_PERCENT ? 'attention' : 'normal');
        }
        $color = null;
        foreach ($members as $member) {
            if ($member['identityColor']) {
                $color = $member['identityColor'];
                break;
            }
        }
        $pools[] = [
            'name' => $name,
            'label' => waz_storage_title($name),
            'fileSystem' => trim((string) ($config['diskFsType'] ?? '')) ?: ($members[0]['fileSystem'] ?? null),
            'profile' => trim((string) ($config['diskFsProfile'] ?? '')) ?: null,
            'sizeBytes' => $size,
            'usedBytes' => $used,
            'freeBytes' => $free,
            'usagePercent' => $usage,
            'state' => waz_storage_worst_state($states),
            'identityColor' => $color,
            'memberCount' => count($members),
            'members' => array_values($members),
            'order' => $preferredOrder[$name] ?? (100 + count($pools)),
        ];
    }
    usort($pools, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);
    foreach ($pools as &$pool) {
        unset($pool['order']);
    }
    unset($pool);
    return $pools;
}

function waz_storage_array_data(array $disks, array $variables): array
{
    $arrayDisks = array_values(array_filter($disks, static fn(array $disk): bool => $disk['type'] === 'Data'));
    usort($arrayDisks, static fn(array $left, array $right): int => $left['index'] <=> $right['index']);
    $size = 0.0;
    $used = 0.0;
    $hasSize = false;
    foreach ($arrayDisks as $disk) {
        if ($disk['sizeBytes'] !== null) {
            $size += $disk['sizeBytes'];
            $used += $disk['usedBytes'] ?? max(0.0, $disk['sizeBytes'] - ($disk['freeBytes'] ?? $disk['sizeBytes']));
            $hasSize = true;
        }
    }
    $states = array_column($arrayDisks, 'state');
    $mdState = strtoupper(trim((string) ($variables['mdState'] ?? '')));
    foreach (['mdNumMissing', 'mdNumDisabled', 'mdNumInvalid'] as $key) {
        if ((int) ($variables[$key] ?? 0) > 0) {
            $states[] = 'fault';
        }
    }
    if ($mdState !== '' && $mdState !== 'STARTED') {
        $states[] = 'fault';
    }
    return [
        'mdState' => $mdState ?: 'UNKNOWN',
        'missingCount' => (int) ($variables['mdNumMissing'] ?? 0),
        'disabledCount' => (int) ($variables['mdNumDisabled'] ?? 0),
        'invalidCount' => (int) ($variables['mdNumInvalid'] ?? 0),
        'state' => waz_storage_worst_state($states),
        'diskCount' => count($arrayDisks),
        'sizeBytes' => $hasSize ? $size : null,
        'usedBytes' => $hasSize ? $used : null,
        'usagePercent' => $hasSize && $size > 0 ? $used * 100.0 / $size : null,
        'disks' => $arrayDisks,
    ];
}

function waz_storage_cron_values(string $expression, int $minimum, int $maximum): array
{
    $values = [];
    foreach (explode(',', trim($expression)) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $step = 1;
        if (strpos($part, '/') !== false) {
            [$part, $stepRaw] = array_pad(explode('/', $part, 2), 2, '1');
            $step = max(1, (int) $stepRaw);
        }
        if ($part === '*') {
            $start = $minimum;
            $end = $maximum;
        } elseif (strpos($part, '-') !== false) {
            [$start, $end] = array_map('intval', array_pad(explode('-', $part, 2), 2, (string) $minimum));
        } elseif (is_numeric($part)) {
            $start = (int) $part;
            $end = $start;
        } else {
            continue;
        }
        $start = max($minimum, $start);
        $end = min($maximum, $end);
        for ($value = $start; $value <= $end; $value += $step) {
            $values[$value] = true;
        }
    }
    $result = array_map('intval', array_keys($values));
    sort($result, SORT_NUMERIC);
    return $result;
}

function waz_storage_cron_matches(string $expression, int $value, int $minimum, int $maximum): bool
{
    return in_array($value, waz_storage_cron_values($expression, $minimum, $maximum), true);
}

function waz_storage_next_parity(array $parityConfig, int $now): ?int
{
    if ((string) ($parityConfig['mode'] ?? '') === '0' || !is_file(WAZ_PARITY_CRON)) {
        return null;
    }
    $schedule = null;
    foreach (@file(WAZ_PARITY_CRON, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && strpos($line, 'mdcmd check') !== false) {
            $parts = preg_split('/\s+/', $line, 6) ?: [];
            if (count($parts) >= 6) {
                $schedule = array_slice($parts, 0, 5);
                break;
            }
        }
    }
    if ($schedule === null) {
        return null;
    }
    [$minuteExpression, $hourExpression, $dayExpression, $monthExpression, $weekdayExpression] = $schedule;
    $minutes = waz_storage_cron_values($minuteExpression, 0, 59);
    $hours = waz_storage_cron_values($hourExpression, 0, 23);
    if (!$minutes || !$hours) {
        return null;
    }
    $timezone = new DateTimeZone(date_default_timezone_get());
    $day = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0, 0);
    $weekOfMonth = null;
    if (preg_match('/^W([1-5])$/i', trim((string) ($parityConfig['dotm'] ?? '')), $match)) {
        $weekOfMonth = (int) $match[1];
    }
    for ($offset = 0; $offset <= 800; $offset++) {
        $candidateDay = $day->modify('+' . $offset . ' days');
        $month = (int) $candidateDay->format('n');
        $dayOfMonth = (int) $candidateDay->format('j');
        $weekday = (int) $candidateDay->format('w');
        $weekdayMatches = waz_storage_cron_matches($weekdayExpression, $weekday, 0, 7)
            || ($weekday === 0 && waz_storage_cron_matches($weekdayExpression, 7, 0, 7));
        if (!waz_storage_cron_matches($monthExpression, $month, 1, 12)
            || !waz_storage_cron_matches($dayExpression, $dayOfMonth, 1, 31)
            || !$weekdayMatches
            || ($weekOfMonth !== null && intdiv($dayOfMonth - 1, 7) + 1 !== $weekOfMonth)) {
            continue;
        }
        foreach ($hours as $hour) {
            foreach ($minutes as $minute) {
                $candidate = $candidateDay->setTime($hour, $minute, 0)->getTimestamp();
                if ($candidate > $now) {
                    return $candidate;
                }
            }
        }
    }
    return null;
}

function waz_storage_parity_data(array $disks, array $variables, array $parityConfig): array
{
    $parityDisks = array_values(array_filter($disks, static fn(array $disk): bool => $disk['type'] === 'Parity'));
    usort($parityDisks, static fn(array $left, array $right): int => strnatcasecmp((string) $left['name'], (string) $right['name']));
    $position = waz_storage_number($variables['mdResyncPos'] ?? null) ?? 0.0;
    $size = waz_storage_number($variables['mdResyncSize'] ?? null) ?? 0.0;
    $active = (int) ($variables['mdResync'] ?? 0) > 0 || ($position > 0 && $size > 0 && $position < $size);
    $errors = (int) ($variables['mdSyncErrs'] ?? ($variables['sbSyncErrs'] ?? 0));
    $missing = (int) ($variables['mdNumMissing'] ?? 0) + (int) ($variables['mdNumDisabled'] ?? 0) + (int) ($variables['mdNumInvalid'] ?? 0);
    $exit = trim((string) ($variables['sbSyncExit'] ?? '0'));
    $states = array_column($parityDisks, 'state');
    if ($errors > 0 || $missing > 0 || ($exit !== '' && $exit !== '0')) {
        $states[] = 'fault';
    } elseif ($active) {
        $states[] = 'attention';
    }
    $started = (int) ($variables['sbSynced'] ?? 0);
    $finished = (int) ($variables['sbSynced2'] ?? 0);
    return [
        'state' => waz_storage_worst_state($states),
        'valid' => $missing === 0 && $errors === 0 && ($exit === '' || $exit === '0'),
        'missingCount' => $missing,
        'exitCode' => $exit,
        'errors' => $errors,
        'action' => trim((string) ($variables['mdResyncAction'] ?? 'check')),
        'active' => $active,
        'positionBytes' => $position * 1024.0,
        'sizeBytes' => $size * 1024.0,
        'progressPercent' => $size > 0 ? max(0.0, min(100.0, $position * 100.0 / $size)) : null,
        'lastStartedAt' => $started > 0 ? $started : null,
        'lastFinishedAt' => $finished > 0 ? $finished : null,
        'lastDurationSeconds' => $started > 0 && $finished >= $started ? $finished - $started : null,
        'nextScheduledAt' => waz_storage_next_parity($parityConfig, time()),
        'disks' => $parityDisks,
    ];
}

function waz_storage_disk_attention(array $disk): ?string
{
    if (($disk['state'] ?? 'normal') === 'normal') {
        return null;
    }
    $name = preg_replace('/^disk/i', 'Disk ', (string) ($disk['name'] ?? 'Disk'));
    if (($disk['type'] ?? '') === 'Parity') {
        $name = strcasecmp((string) ($disk['name'] ?? ''), 'parity2') === 0 ? 'Parity 2' : 'Parity 1';
    }
    $status = strtoupper(trim((string) ($disk['status'] ?? '')));
    if ($status !== '' && $status !== 'DISK_OK') {
        return $name . ' · ' . ($disk['statusLabel'] ?? $status);
    }
    $errors = (int) ($disk['errors'] ?? 0);
    if ($errors > 0) {
        return $name . ' · ' . $errors . ' error' . ($errors === 1 ? '' : 's');
    }
    $temperature = waz_storage_number($disk['temperatureC'] ?? null);
    $critical = waz_storage_number($disk['criticalC'] ?? null);
    $warning = waz_storage_number($disk['warningC'] ?? null);
    if ($temperature !== null && $critical !== null && $critical > 0 && $temperature >= $critical) {
        return $name . ' · ' . round($temperature) . '°C critical';
    }
    if ($temperature !== null && $warning !== null && $warning > 0 && $temperature >= $warning) {
        return $name . ' · ' . round($temperature) . '°C warm';
    }
    $usage = waz_storage_number($disk['usagePercent'] ?? null);
    if ($usage !== null && $usage >= WAZ_STORAGE_WARN_PERCENT) {
        return $name . ' · ' . round($usage) . '% full';
    }
    return $name . ' · requires attention';
}

function waz_storage_attention(array $array, array $parity, array $pools): array
{
    $messages = [];
    if (($parity['active'] ?? false) === true) {
        $progress = waz_storage_number($parity['progressPercent'] ?? null);
        $messages[] = 'Parity check in progress' . ($progress === null ? '' : ' · ' . round($progress) . '%');
    } elseif (($parity['valid'] ?? true) !== true) {
        $messages[] = ((int) ($parity['errors'] ?? 0)) > 0
            ? 'Parity · ' . (int) $parity['errors'] . ' error' . ((int) $parity['errors'] === 1 ? '' : 's')
            : 'Parity · last check did not complete cleanly';
    }
    foreach ((array) ($parity['disks'] ?? []) as $disk) {
        $message = is_array($disk) ? waz_storage_disk_attention($disk) : null;
        if ($message !== null) {
            $messages[] = $message;
        }
    }
    if (($array['mdState'] ?? 'UNKNOWN') !== 'STARTED') {
        $messages[] = 'Array · ' . ($array['mdState'] ?? 'UNKNOWN');
    }
    foreach (['missingCount' => 'missing', 'disabledCount' => 'disabled', 'invalidCount' => 'invalid'] as $key => $label) {
        $count = (int) ($array[$key] ?? 0);
        if ($count > 0) {
            $messages[] = 'Array · ' . $count . ' ' . $label;
        }
    }
    foreach ((array) ($array['disks'] ?? []) as $disk) {
        $message = is_array($disk) ? waz_storage_disk_attention($disk) : null;
        if ($message !== null) {
            $messages[] = $message;
        }
    }
    foreach ($pools as $pool) {
        if (!is_array($pool) || ($pool['state'] ?? 'normal') === 'normal') {
            continue;
        }
        $usage = waz_storage_number($pool['usagePercent'] ?? null);
        if ($usage !== null && $usage >= WAZ_STORAGE_WARN_PERCENT) {
            $messages[] = (string) ($pool['label'] ?? waz_storage_title((string) ($pool['name'] ?? 'Pool'))) . ' · ' . round($usage) . '% full';
            continue;
        }
        foreach ((array) ($pool['members'] ?? []) as $disk) {
            $message = is_array($disk) ? waz_storage_disk_attention($disk) : null;
            if ($message !== null) {
                $messages[] = $message;
            }
        }
    }
    $messages = array_values(array_unique($messages));
    return ['count' => count($messages), 'messages' => $messages];
}

function waz_storage_snapshot(): array
{
    $variables = waz_storage_ini(WAZ_VAR_INI);
    $dynamixConfig = waz_storage_ini(WAZ_DYNAMIX_CFG, true);
    $parityConfig = is_array($dynamixConfig['parity'] ?? null) ? $dynamixConfig['parity'] : [];
    $disks = waz_storage_disk_records();
    $locations = waz_storage_location_data($disks);
    $pools = waz_storage_pool_data($disks);
    foreach ($disks as &$disk) {
        if (!$disk['identityColor']) {
            if ($disk['type'] === 'Parity') {
                $disk['identityColor'] = '#e55454';
            } elseif ($disk['type'] === 'Data') {
                $terabytes = $disk['sizeBytes'] ? (string) round($disk['sizeBytes'] / 1000000000000.0) : 'unknown';
                $disk['identityColor'] = waz_storage_identity_color('data:' . $disk['mediaType'] . ':' . $terabytes);
            } else {
                $disk['identityColor'] = waz_storage_identity_color('pool:' . ($disk['poolName'] ?: $disk['name']));
            }
        }
    }
    unset($disk);
    $diskByDevice = [];
    foreach ($disks as $disk) {
        $diskByDevice[(string) $disk['device']] = [
            'poolName' => $disk['poolName'],
            'identityColor' => $disk['identityColor'],
        ];
    }
    foreach ($locations['groups'] as &$group) {
        foreach ($group['cells'] as &$cell) {
            if (is_array($cell['disk'] ?? null)) {
                $meta = $diskByDevice[(string) ($cell['disk']['device'] ?? '')] ?? null;
                $cell['disk']['poolName'] = $meta['poolName'] ?? null;
                $cell['disk']['identityColor'] = $meta['identityColor'] ?? $cell['disk']['identityColor'];
            }
        }
        unset($cell);
    }
    unset($group);
    $array = waz_storage_array_data($disks, $variables);
    $parity = waz_storage_parity_data($disks, $variables, $parityConfig);
    $state = waz_storage_worst_state(array_merge([$array['state'], $parity['state']], array_column($pools, 'state')));
    return [
        'schemaVersion' => 1,
        'pluginVersion' => WAZ_STORAGE_VERSION,
        'sampledAtMs' => (int) round(microtime(true) * 1000),
        'state' => $state,
        'attention' => waz_storage_attention($array, $parity, $pools),
        'array' => $array,
        'parity' => $parity,
        'pools' => $pools,
        'locations' => $locations,
    ];
}

if (!defined('WAZ_STORAGE_NO_MAIN')) {
    try {
        echo json_encode(waz_storage_snapshot(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode([
            'schemaVersion' => 1,
            'pluginVersion' => WAZ_STORAGE_VERSION,
            'error' => 'Unable to read WAZ Storage telemetry',
        ], JSON_UNESCAPED_SLASHES);
    }
}
