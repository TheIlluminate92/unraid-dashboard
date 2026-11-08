<?php

declare(strict_types=1);

/**
 * Rackline-owned storage snapshot. Reads only existing Unraid metadata and optional
 * Disk Location JSON. Never opens disk devices, invokes probes, or creates caches.
 * Source paths are fixed at the HTTP boundary; function arguments support offline tests.
 */

const RACKLINE_STORAGE_VERSION = '@@APP_VERSION@@';
const RACKLINE_STORAGE_PLUGIN_VERSION = '@@RELEASE_VERSION@@';
const RACKLINE_STORAGE_WARN_PERCENT = 95.0;
const RACKLINE_STORAGE_FAULT_PERCENT = 98.0;
const RACKLINE_STORAGE_SAFE_INTEGER = 9007199254740991;
const RACKLINE_STORAGE_MAX_DISKS = 1024;
const RACKLINE_STORAGE_MAX_GROUPS = 64;
const RACKLINE_STORAGE_MAX_BAYS = 4096;
const RACKLINE_STORAGE_MAX_POOLS = 128;
const RACKLINE_STORAGE_SMART_BUDGET = 8388608;

function rackline_storage_text($value): string
{
    return is_scalar($value) && !is_bool($value) ? trim((string) $value) : '';
}

function rackline_storage_sources(): array
{
    return [
        'disks' => '/var/local/emhttp/disks.ini',
        'variables' => '/var/local/emhttp/var.ini',
        'smartSettings' => '/boot/config/smart-one.cfg',
        'smartCache' => '/var/local/emhttp/smart',
        'pools' => '/boot/config/pools',
        'locations' => '/boot/config/plugins/disklocation',
        'locationDefaults' => '/usr/local/emhttp/plugins/disklocation/pages/default_settings.php',
        'dynamix' => '/boot/config/plugins/dynamix/dynamix.cfg',
        'parityCron' => '/boot/config/plugins/dynamix/parity-check.cron',
    ];
}

function rackline_storage_read(string $path, int $limit = 1048576): ?string
{
    if (!is_file($path) || !is_readable($path)) return null;
    $text = @file_get_contents($path, false, null, 0, $limit + 1);
    return $text === false || strlen($text) > $limit ? null : $text;
}

function rackline_storage_ini(string $path, bool $sections = false, bool $required = false): array
{
    $text = rackline_storage_read($path);
    $values = $text === null ? false : @parse_ini_string($text, $sections, INI_SCANNER_RAW);
    if ($required && !is_array($values)) throw new RuntimeException('Native storage metadata unavailable');
    return is_array($values) ? $values : [];
}

function rackline_storage_json(string $path): array
{
    $values = json_decode(rackline_storage_read($path) ?? '', true);
    return is_array($values) ? $values : [];
}

function rackline_storage_number($value): ?float
{
    $clean = rackline_storage_text($value);
    if (str_contains($clean, ',')) {
        if (!preg_match('/^[+-]?[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?$/D', $clean)) return null;
        $clean = str_replace(',', '', $clean);
    }
    return $clean !== '' && is_numeric($clean) && is_finite((float) $clean)
        && abs((float) $clean) <= RACKLINE_STORAGE_SAFE_INTEGER ? (float) $clean : null;
}

function rackline_storage_kib_bytes($value): ?float
{
    $number = rackline_storage_number($value);
    return $number === null || $number < 0 || $number > floor(RACKLINE_STORAGE_SAFE_INTEGER / 1024)
        ? null : $number * 1024.0;
}

function rackline_storage_count($value): ?int
{
    // Validate decimal syntax before conversion: large fractions can round to
    // integers in a double and otherwise turn invalid native counts into facts.
    if (is_float($value)) return is_finite($value) && $value >= 0 && $value <= RACKLINE_STORAGE_SAFE_INTEGER && floor($value) === $value ? (int) $value : null;
    $text = rackline_storage_text($value);
    if (!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)$/D', $text)) return null;
    $text = ltrim(str_replace(',', '', $text), '0');
    if (strlen($text) > 16 || strlen($text) === 16 && strcmp($text, (string) RACKLINE_STORAGE_SAFE_INTEGER) > 0) return null;
    return (int) $text;
}

function rackline_storage_usage(?float $size, ?float $used, ?float $free): ?float
{
    if ($size === null || !is_finite($size) || $size <= 0
        || ($used !== null && (!is_finite($used) || $used < 0 || $used > $size))
        || ($free !== null && (!is_finite($free) || $free < 0 || $free > $size))) {
        return null;
    }
    if ($used === null && $free !== null) {
        $used = $size - $free;
    }
    return $used === null ? null : $used / $size * 100.0;
}

function rackline_storage_state_rank(string $state): int
{
    return ['normal' => 0, 'unknown' => 1, 'attention' => 2, 'fault' => 3][$state] ?? 1;
}

function rackline_storage_worst_state(array $states): string
{
    $worst = 'normal';
    foreach ($states as $state) {
        if (rackline_storage_state_rank((string) $state) > rackline_storage_state_rank($worst)) {
            $worst = (string) $state;
        }
    }
    return $worst;
}

function rackline_storage_title(string $name): string
{
    $labels = [
        'cache' => 'Cache',
        'servers' => 'Servers',
        'downloads-cache' => 'Downloads',
        'akashic' => 'Akashic',
    ];
    return $labels[$name] ?? ucwords(str_replace(['-', '_'], ' ', $name));
}

function rackline_storage_identity_color(string $key): string
{
    $palette = ['#22b8f0', '#a78bfa', '#2dd4bf', '#f2a900', '#e879f9', '#60a5fa', '#f97316', '#34d399'];
    $index = (int) sprintf('%u', crc32(strtolower($key))) % count($palette);
    return $palette[$index];
}

function rackline_storage_pool_colors(array $names): array
{
    $fixed = ['cache' => '#a78bfa', 'mnemosyne' => '#2dd4bf', 'aegis' => '#f2a900', 'elysium' => '#e879f9', 'akashic' => '#60a5fa', 'downloads' => '#f97316', 'avalon' => '#34d399'];
    $palette = ['#22b8f0', '#fb7185', '#c4b454', '#818cf8', '#d4a373', '#a3e635', '#f472b6', '#38bdf8'];
    sort($names, SORT_STRING);
    $colors = [];
    $used = array_values($fixed);
    foreach ($names as $name) {
        if (isset($fixed[strtolower((string) $name)])) { $colors[$name] = $fixed[strtolower((string) $name)]; continue; }
        $start = (int) sprintf('%u', crc32((string) $name)) % count($palette);
        $color = null;
        for ($i = 0; $i < count($palette); $i++) {
            $candidate = $palette[($start + $i) % count($palette)];
            if (!in_array($candidate, $used, true)) { $color = $candidate; break; }
        }
        $colors[$name] = $color ?? ('hsl(' . ((int) sprintf('%u', crc32((string) $name)) % 360) . ' 65% 65%)');
        $used[] = $colors[$name];
    }
    return $colors;
}


function rackline_storage_array_palette(): array
{
    return [
        'parity' => ['label' => 'Parity', 'color' => '#e55454'],
        '24tb-h' => ['label' => '24 TB · H series', 'color' => '#3975db'],
        '24tb-c' => ['label' => '24 TB · C series', 'color' => '#329fd9'],
        '14tb' => ['label' => '14 TB', 'color' => '#9bc9ff'],
        'other' => ['label' => 'Other array drives', 'color' => '#617fa8'],
    ];
}

function rackline_storage_array_identity(array $disk): array
{
    $palette = rackline_storage_array_palette();
    $model = strtoupper(rackline_storage_text($disk['model'] ?? ''));
    // The native id is model_serial; classification never relies on tray order.
    if ($model === '') $model = strtoupper(explode('_', rackline_storage_text($disk['id'] ?? ''), 2)[0]);
    $capacity = rackline_storage_number($disk['capacityBytes'] ?? null);
    $group = 'other';
    if (($disk['type'] ?? '') === 'Parity') $group = 'parity';
    elseif (preg_match('/^ST24000NM[0-9]{3}H(?:-|$)/', $model)) $group = '24tb-h';
    elseif (preg_match('/^ST24000NM[0-9]{3}C(?:-|$)/', $model)) $group = '24tb-c';
    elseif (preg_match('/^ST14000NM/', $model) || ($capacity !== null && round($capacity / 1e12) === 14.0)) $group = '14tb';
    return ['identityGroup' => $group, 'identityLabel' => $palette[$group]['label'], 'identityColor' => $palette[$group]['color']];
}

function rackline_storage_apply_array_colors(array $disks): array
{
    foreach ($disks as &$disk) {
        if (in_array($disk['type'] ?? '', ['Data', 'Parity'], true)) $disk = array_merge($disk, rackline_storage_array_identity($disk));
    }
    unset($disk);
    return $disks;
}

function rackline_storage_array_color_legend(array $disks): array
{
    $groups = [];
    foreach (rackline_storage_array_palette() as $key => $identity) $groups[$key] = array_merge(['key' => $key, 'count' => 0], $identity);
    $total = 0;
    foreach ($disks as $disk) {
        if (in_array($disk['type'] ?? '', ['Data', 'Parity'], true) && isset($groups[$disk['identityGroup'] ?? ''])) {
            $groups[$disk['identityGroup']]['count']++;
            $total++;
        }
    }
    return $total ? array_values($groups) : [];
}

function rackline_storage_media(array $disk): string
{
    if (strtolower(rackline_storage_text($disk['transport'] ?? '')) === 'nvme') {
        return 'NVMe';
    }
    $rotational = rackline_storage_text($disk['rotational'] ?? null);
    return $rotational === '1' ? 'HDD' : ($rotational === '0' ? 'SSD' : 'Unknown');
}

function rackline_storage_temperature_setting($value): ?float
{
    $number = rackline_storage_number($value);
    return $number !== null && $number >= 0 ? $number : null;
}

function rackline_storage_temperature_thresholds(array $disk, array $smartSettings, array $displaySettings): array
{
    $id = rackline_storage_text($disk['id'] ?? '');
    $specific = $id !== '' && is_array($smartSettings[$id] ?? null) ? $smartSettings[$id] : [];
    $settings = array_merge($disk, $specific);
    $media = rackline_storage_media($disk);

    $warning = rackline_storage_temperature_setting($settings['hotTemp'] ?? null);
    $critical = rackline_storage_temperature_setting($settings['maxTemp'] ?? null);

    if ($media === 'Unknown') return ['warning' => $warning, 'critical' => $critical];
    $globalWarningKey = $media === 'HDD' ? 'hot' : 'hotssd';
    $globalCriticalKey = $media === 'HDD' ? 'max' : 'maxssd';
    if ($warning === null) {
        $warning = rackline_storage_temperature_setting($displaySettings[$globalWarningKey] ?? null);
        if ($warning === null && $media !== 'HDD') {
            $warning = rackline_storage_temperature_setting($displaySettings['hot'] ?? null);
        }
        if ($warning === null) {
            $warning = $media === 'HDD' ? 45.0 : 65.0;
        }
    }
    if ($critical === null) {
        $critical = rackline_storage_temperature_setting($displaySettings[$globalCriticalKey] ?? null);
        if ($critical === null && $media !== 'HDD') {
            $critical = rackline_storage_temperature_setting($displaySettings['max'] ?? null);
        }
        if ($critical === null) {
            $critical = $media === 'HDD' ? 50.0 : 75.0;
        }
    }

    return ['warning' => $warning, 'critical' => $critical];
}

function rackline_storage_unassigned_slot(array $disk): bool
{
    return strtoupper(rackline_storage_text($disk['status'] ?? '')) === 'DISK_NP'
        && rackline_storage_text($disk['device'] ?? '') === ''
        && rackline_storage_text($disk['id'] ?? '') === ''
        && (rackline_storage_number($disk['size'] ?? null) ?? 0) <= 0;
}


function rackline_storage_parse_smart_cache(string $text, int $modified): array
{
    $attributes = [];
    $names = [5 => 'Reallocated sectors', 9 => 'Power-on hours', 187 => 'Reported uncorrectable', 197 => 'Pending sectors', 198 => 'Offline uncorrectable', 199 => 'Interface CRC errors'];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if (preg_match('/^\s*(\d+)\s+\S+\s+0x[0-9a-f]+\s+\d+\s+\d+\s+\d+\s+\S+\s+\S+\s+\S+\s+(.+)$/i', $line, $m) && isset($names[(int) $m[1]])) {
            $attributes[$names[(int) $m[1]]] = trim($m[2]);
        }
        if (preg_match('/^\s*(Power On Hours|Percentage Used|Available Spare|Media and Data Integrity Errors|Critical Warning|Elements in grown defect list):\s*(.+)$/i', $line, $m)) $attributes[$m[1]] = trim($m[2]);
    }
    $health = preg_match('/(?:SMART overall-health self-assessment test result|SMART Health Status):\s*([^\r\n]+)/i', $text, $m) ? trim($m[1]) : null;
    return ['available' => $health !== null || count($attributes) > 0, 'sampledAtMs' => $modified * 1000, 'health' => $health, 'attributes' => $attributes];
}

function rackline_storage_serial_matches(string $serial, array $raw): bool
{
    $serial = trim($serial);
    if ($serial === '') return false;
    // Explicit serials are authoritative when supplied. Device nodes and slot
    // names can be reused after replacement and are never identity evidence.
    if (array_key_exists('serial', $raw) && $raw['serial'] !== null && (!is_scalar($raw['serial']) || is_bool($raw['serial']))) return false;
    $currentSerial = rackline_storage_text($raw['serial'] ?? '');
    if ($currentSerial !== '') return $currentSerial === $serial;
    $id = rackline_storage_text($raw['id'] ?? '');
    if ($id === $serial) return true;
    $model = rackline_storage_text($raw['model'] ?? '');
    $identity = $model !== '' && str_starts_with($id, $model . '_') ? substr($id, strlen($model) + 1) : (explode('_', $id, 2)[1] ?? '');
    // Native SAS identifiers may add a WWN after the serial. The model token
    // itself and arbitrary later tokens are never accepted as a serial match.
    if ($identity === $serial) return true;
    if (!str_starts_with($identity, $serial . '_')) return false;
    $suffix = substr($identity, strlen($serial) + 1);
    return $suffix !== '' && !str_contains($suffix, '_');
}

function rackline_storage_smart_identity(string $name, array $raw): ?string
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $name)) return null;
    $identity = [$name];
    foreach (['device', 'id', 'serial', 'model'] as $key) {
        if (isset($raw[$key]) && (!is_scalar($raw[$key]) || is_bool($raw[$key]))) return null;
        $value = rackline_storage_text($raw[$key] ?? '');
        // Replacement-character JSON would collapse different malformed byte
        // identities into one selector. Reject them instead of repairing them.
        if (preg_match('//u', $value) !== 1) return null;
        $identity[] = $value;
    }
    if ($identity[2] === '' && $identity[3] === '') return null;
    // Selection guard, not an authentication token. Do not put raw serials in URLs.
    return hash('sha256', "rackline-smart-selection-v1\0" . json_encode($identity));
}

function rackline_storage_smart_report(string $name, array $raw, string $directory, ?int &$remaining = null, ?array &$cacheMetadata = null): ?array
{
    $cacheMetadata = ['available' => false, 'cacheStatus' => 'unavailable'];
    if (rackline_storage_smart_identity($name, $raw) === null || $remaining !== null && $remaining <= 0) return null;
    // Only existing regular files in the fixed cache directory. Links and special
    // files are not reports. Recheck metadata so disappearing/replaced files fail closed.
    $path = $directory . '/' . $name;
    clearstatcache(true, $path);
    if (is_link($directory) || is_link($path) || !is_dir($directory)) return null;
    $before = @lstat($path);
    if (!is_array($before)) {
        // Failed reads/access checks are unknown, not proof that a cache is absent.
        if (is_readable($directory) && is_executable($directory))
            $cacheMetadata = ['available' => false, 'cacheStatus' => 'missing', 'cachePresent' => false];
        return null;
    }
    if (($before['mode'] & 0170000) !== 0100000) return null;
    $cacheMetadata['cachePresent'] = true;
    $limit = $remaining === null ? 262144 : min(262144, $remaining);
    if ($before['size'] <= 0 || $before['size'] > $limit || $before['mtime'] <= 0) {
        $cacheMetadata['unavailableReason'] = $before['size'] > $limit ? 'report_too_large' : 'invalid_report';
        if ($remaining !== null && $before['size'] > $limit) $remaining -= $limit;
        return null;
    }
    $text = rackline_storage_read($path, $limit);
    if ($remaining !== null) $remaining -= $text === null ? $limit : strlen($text);
    clearstatcache(true, $path);
    $after = @lstat($path);
    if (!is_array($after)) {
        $cacheMetadata = ['available' => false, 'cacheStatus' => 'unavailable', 'unavailableReason' => 'changed_report'];
        return null;
    }
    foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $key) if ($before[$key] !== $after[$key]) {
        $cacheMetadata = ['available' => false, 'cacheStatus' => 'unavailable', 'unavailableReason' => 'changed_report'];
        return null;
    }
    if ($text === null || strlen($text) !== $before['size']) {
        $cacheMetadata['unavailableReason'] = 'unreadable_report';
        return null;
    }
    // Cache presence/time is useful even when native attribute-only files lack
    // identity. Never project their text, attributes or health as this drive's.
    $cacheMetadata['cachedAtMs'] = $before['mtime'] * 1000;
    if (str_contains($text, "\0")) {
        $cacheMetadata['unavailableReason'] = 'invalid_report';
        return null;
    }
    $serialCount = preg_match_all('/^\s*Serial Number:\s*([^\r\n]+)\r?$/mi', $text, $matches);
    if ($serialCount !== 1 || !rackline_storage_serial_matches($matches[1][0], $raw)) {
        $cacheMetadata['cacheStatus'] = 'identity_unverified';
        $cacheMetadata['unavailableReason'] = $serialCount === 0 ? 'missing_report_identity'
            : ($serialCount !== 1 ? 'ambiguous_report_identity' : 'report_identity_mismatch');
        return null;
    }
    $smart = rackline_storage_parse_smart_cache($text, (int) $before['mtime']);
    if (!$smart['available']) {
        $cacheMetadata['unavailableReason'] = 'invalid_report';
        return null;
    }
    $cacheMetadata = ['available' => true, 'cacheStatus' => 'verified', 'cachePresent' => true, 'cachedAtMs' => $before['mtime'] * 1000];
    return ['text' => $text, 'cachedAtMs' => $before['mtime'] * 1000, 'smart' => $smart];
}

function rackline_storage_cached_smart(string $name, array $raw, string $directory, ?int &$remaining = null): array
{
    $report = rackline_storage_smart_report($name, $raw, $directory, $remaining, $cacheMetadata);
    if ($report === null) return $cacheMetadata;
    return $report['smart'] + $cacheMetadata + [
        'reportUrl' => '/plugins/rackline.dashboard/include/smart-report.php?name=' . rawurlencode($name)
            . '&identity=' . rackline_storage_smart_identity($name, $raw),
    ];
}

function rackline_storage_disk_records(array $rawDisks, array $smartSettings, array $displaySettings, string $smartCache): array
{
    if (count($rawDisks) > RACKLINE_STORAGE_MAX_DISKS) throw new RuntimeException('Native disk inventory exceeds bounded collection');
    $remaining = RACKLINE_STORAGE_SMART_BUDGET;
    $records = [];
    $names = [];
    $devices = [];
    foreach ($rawDisks as $section => $raw) {
        if (!is_array($raw)) {
            continue;
        }
        foreach (['name', 'type'] as $key) if (isset($raw[$key]) && !is_scalar($raw[$key])) throw new RuntimeException('Malformed native disk identity');
        $name = rackline_storage_text($raw['name'] ?? trim((string) $section, '"'));
        $type = rackline_storage_text($raw['type'] ?? '');
        if ($name === '' || !in_array($type, ['Data', 'Parity', 'Cache'], true) || rackline_storage_unassigned_slot($raw)) {
            continue;
        }
        $device = rackline_storage_text($raw['device'] ?? '');
        if (isset($names[$name]) || $device !== '' && isset($devices[$device])) throw new RuntimeException('Ambiguous native disk identity');
        $names[$name] = true;
        if ($device !== '') $devices[$device] = true;
        $size = rackline_storage_kib_bytes($raw['fsSize'] ?? null);
        $used = rackline_storage_kib_bytes($raw['fsUsed'] ?? null);
        $free = rackline_storage_kib_bytes($raw['fsFree'] ?? null);
        $usage = rackline_storage_usage($size, $used, $free);
        $temperature = rackline_storage_number($raw['temp'] ?? null);
        if ($temperature !== null && ($temperature < -273.15 || $temperature > 250)) $temperature = null;
        $media = rackline_storage_media($raw);
        $thresholds = rackline_storage_temperature_thresholds($raw, $smartSettings, $displaySettings);
        $warning = $thresholds['warning'];
        $critical = $thresholds['critical'];
        $status = strtoupper(rackline_storage_text($raw['status'] ?? ''));
        $errors = rackline_storage_count($raw['numErrors'] ?? null);
        // Missing temperature is not evidence of standby. Only the native standby
        // marker supports that claim; do not query a physical drive to fill gaps.
        $color = strtolower(rackline_storage_text($raw['color'] ?? ''));
        $spunDown = str_ends_with($color, '-blink');
        $active = $temperature !== null || str_ends_with($color, '-on');
        $states = [$status === '' || $errors === null ? 'unknown' : 'normal'];
        if ($status !== '' && $status !== 'DISK_OK') {
            $states[] = 'fault';
        }
        if ($errors !== null && $errors > 0) {
            $states[] = 'fault';
        }
        if ($temperature !== null && $critical !== null && $critical > 0 && $temperature >= $critical) {
            $states[] = 'fault';
        } elseif ($temperature !== null && $warning !== null && $warning > 0 && $temperature >= $warning) {
            $states[] = 'attention';
        }
        if ($usage !== null && $usage >= RACKLINE_STORAGE_FAULT_PERCENT) {
            $states[] = 'fault';
        } elseif ($usage !== null && $usage >= RACKLINE_STORAGE_WARN_PERCENT) {
            $states[] = 'attention';
        }
        $records[] = [
            'name' => $name,
            'selectionToken' => rackline_storage_smart_identity($name, $raw),
            'index' => rackline_storage_count($raw['idx'] ?? null),
            'model' => rackline_storage_text($raw['model'] ?? '') ?: explode('_', rackline_storage_text($raw['id'] ?? ''), 2)[0],
            'capacityBytes' => rackline_storage_kib_bytes($raw['size'] ?? null),
            'device' => rackline_storage_text($raw['device'] ?? ''),
            'type' => $type,
            'mediaType' => $media,
            'fileSystem' => rackline_storage_text($raw['fsType'] ?? '') ?: null,
            'mounted' => strcasecmp(rackline_storage_text($raw['fsStatus'] ?? ''), 'Mounted') === 0,
            'sizeBytes' => $size,
            'usedBytes' => $used,
            'freeBytes' => $free,
            'usagePercent' => $usage,
            'temperatureC' => $temperature,
            'warningC' => $warning,
            'criticalC' => $critical,
            'spunDown' => $spunDown,
            'status' => $status,
            'statusLabel' => $status !== '' && $status !== 'DISK_OK' ? $status : ($spunDown ? 'STANDBY' : ($status === 'DISK_OK' && $active ? 'ACTIVE' : 'UNKNOWN')),
            'errors' => $errors,
            'state' => rackline_storage_worst_state($states),
            'location' => null,
            'identityColor' => null,
            'poolName' => null,
            'smart' => rackline_storage_cached_smart($name, $raw, $smartCache, $remaining),
        ];
    }
    return $records;
}

function rackline_storage_location_flag($value): ?bool
{
    if (in_array($value, [false, 0, '0'], true)) return false;
    if (in_array($value, [true, 1, '1'], true)) return true;
    return null;
}

function rackline_storage_location_color($value): ?string
{
    return is_string($value) && preg_match('/^[0-9a-f]{6}$/iD', $value) ? '#' . strtolower($value) : null;
}

/** Read known literal native defaults as data. Never include native PHP. */
function rackline_storage_location_defaults(string $text): array
{
    if (!function_exists('token_get_all') || strlen($text) > 262144) return [];
    try { $rawTokens = token_get_all($text, TOKEN_PARSE); } catch (ParseError $error) { return []; }
    $tokens = array_values(array_filter($rawTokens, static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)));
    if (count($tokens) > 20000 || !is_array($tokens[0] ?? null) || $tokens[0][0] !== T_OPEN_TAG) return [];
    $keys = ['bgcolor_parity','bgcolor_unraid','bgcolor_cache','bgcolor_flash','bgcolor_others','bgcolor_empty','device_bg_color'];
    $values = []; $seen = []; $count = count($tokens);
    // Whole-file whitelist: PHP tag, comments, simple scalar assignments, and
    // an optional final closing tag. Anything executable or dynamic is unknown.
    for ($i = 1; $i < $count;) {
        $variable = $tokens[$i++];
        if (is_array($variable) && $variable[0] === T_CLOSE_TAG && $i === $count) break;
        if (!is_array($variable) || $variable[0] !== T_VARIABLE || ($tokens[$i++] ?? null) !== '=') return [];
        $literal = $tokens[$i++] ?? null;
        $negative = $literal === '-';
        if ($negative) $literal = $tokens[$i++] ?? null;
        if (!is_array($literal)) return [];
        $scalar = in_array($literal[0], [T_CONSTANT_ENCAPSED_STRING,T_LNUMBER,T_DNUMBER], true)
            || $literal[0] === T_STRING && in_array(strtolower($literal[1]), ['true','false','null'], true);
        if (!$scalar || $negative && !in_array($literal[0], [T_LNUMBER,T_DNUMBER], true) || ($tokens[$i++] ?? null) !== ';') return [];
        $key = substr($variable[1],1);
        if (!in_array($key, $keys, true)) continue;
        if (isset($seen[$key])) { unset($values[$key]); continue; }
        $seen[$key] = true;
        if ($negative) continue;
        if ($key === 'device_bg_color' && $literal[0] === T_LNUMBER && in_array($literal[1], ['0','1'], true)) $values[$key] = (int) $literal[1];
        elseif ($key !== 'device_bg_color' && $literal[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^([\'"])([0-9a-f]{6})\\1$/iD', $literal[1], $m)) $values[$key] = $m[2];
    }
    return $values;
}

/** File-write times are not scan/health times or proof of meaningful changes. */
function rackline_storage_location_file(string $path): array
{
    $unknown = ['data' => [], 'writtenAtMs' => null];
    clearstatcache(true, $path); $before = @lstat($path);
    if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > 1048576) return $unknown;
    $text = rackline_storage_read($path);
    clearstatcache(true, $path); $after = @lstat($path);
    if ($text === null || !is_array($after)) return $unknown;
    foreach (['dev','ino','mode','size','mtime','ctime'] as $key) if ($before[$key] !== $after[$key]) return $unknown;
    $data = json_decode($text, true);
    if (!is_array($data)) return $unknown;
    $time = rackline_storage_count($after['mtime']);
    return ['data' => $data, 'writtenAtMs' => $time !== null && $time > 0 && $time <= intdiv(RACKLINE_STORAGE_SAFE_INTEGER, 1000) ? $time * 1000 : null];
}

function rackline_storage_location_display(?array $device, array $group, array $settings, ?array $native): array
{
    $verified = $device !== null && $native !== null;
    $color = null;
    $mode = array_key_exists('device_bg_color', $settings) ? rackline_storage_location_flag($settings['device_bg_color']) : null;
    $type = $verified ? rackline_storage_text($native['type'] ?? '') : '';
    if ($device === null) $color = rackline_storage_location_color($settings['bgcolor_empty'] ?? null);
    elseif ($mode === false) {
        $key = ['Parity'=>'bgcolor_parity','Data'=>'bgcolor_unraid','Cache'=>'bgcolor_cache','Boot'=>'bgcolor_flash'][$type] ?? 'bgcolor_others';
        $color = rackline_storage_location_color($device['color'] ?? null)
            ?? rackline_storage_location_color($group['group_color'] ?? null)
            ?? ($verified ? rackline_storage_location_color($settings[$key] ?? null) : null);
    } elseif ($mode === true && $verified) {
        $temp = rackline_storage_number($native['temp'] ?? null);
        $hot = rackline_storage_number($native['hotTemp'] ?? null);
        $max = rackline_storage_number($native['maxTemp'] ?? null);
        if ($temp !== null && $temp > 0 && $temp <= 250 && $hot !== null && $max !== null && $hot > 0 && $max >= $hot)
            $color = rackline_storage_location_color($settings[$temp >= $max ? 'bgcolor_parity' : ($temp >= $hot ? 'bgcolor_unraid' : 'bgcolor_cache')] ?? null);
        else $color = rackline_storage_location_color($settings['bgcolor_others'] ?? null);
    }
    $node = $device !== null ? basename(rackline_storage_text($device['devicenode'] ?? '')) : '';
    return ['source' => 'disklocation', 'identityVerified' => $verified,
        'name' => $verified ? (rackline_storage_text($native['name'] ?? '') ?: null) : null,
        'device' => preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $node) ? $node : null,
        'type' => $type ?: null, 'color' => $color,
        'model' => rackline_storage_text($device['model_name'] ?? ''),
        'comment' => rackline_storage_text($device['comment'] ?? '') ?: null];
}

function rackline_storage_group_layout(array $group): array
{
    $columns = rackline_storage_count($group['grid_columns'] ?? 1);
    $rows = rackline_storage_count($group['grid_rows'] ?? 1);
    $start = rackline_storage_count($group['tray_start_num'] ?? 1);
    $direction = rackline_storage_count($group['tray_direction'] ?? 1);
    $countMode = rackline_storage_text($group['grid_count'] ?? 'row');
    if ($columns === null || $rows === null || $columns < 1 || $columns > 100 || $rows < 1 || $rows > 100
        || $columns * $rows > RACKLINE_STORAGE_MAX_BAYS || $start === null || $start > RACKLINE_STORAGE_SAFE_INTEGER - $columns * $rows
        || !in_array($direction, [1, 2, 3, 4], true) || !in_array(strtolower($countMode), ['row', 'column'], true))
        return ['columns' => 0, 'rows' => 0, 'cells' => []];
    $count = $columns * $rows;
    $countByColumn = strcasecmp($countMode, 'column') === 0;
    $bypass = array_key_exists('count_bypass_tray', $group) ? rackline_storage_location_flag($group['count_bypass_tray']) : false;
    if ($bypass === null) return ['columns' => 0, 'rows' => 0, 'cells' => []];
    $hidden = is_array($group['hide_tray'] ?? null) ? $group['hide_tray'] : [];
    foreach ($hidden as $value) if (!is_scalar($value) && $value !== null) return ['columns' => 0, 'rows' => 0, 'cells' => []];
    // Disk Location stores one-based source keys even when the displayed
    // labels begin at zero. Numbering direction changes labels, not bay keys.
    $runs = array_chunk(range(1, $count), $countByColumn ? $rows : $columns);
    if (($direction === 2 && !$countByColumn) || ($direction === 3 && $countByColumn) || $direction === 4) {
        $runs = array_reverse($runs);
    }
    if (($direction === 2 && $countByColumn) || ($direction === 3 && !$countByColumn) || $direction === 4) {
        $runs = array_map('array_reverse', $runs);
    }
    $labels = [];
    $number = $start;
    foreach ($runs as $run) foreach ($run as $key) {
        if (empty($hidden[$key]) || !empty($bypass)) $labels[$key] = $number++;
    }
    $cells = [];
    for ($position = 0; $position < $count; $position++) {
        $row = intdiv($position, $columns);
        $column = $position % $columns;
        $ordinal = $countByColumn ? $column * $rows + $row : $position;
        $sourceTray = 1 + $ordinal;
        $isHidden = !empty($hidden[$sourceTray]);
        $cells[] = [
            'sourceTray' => $sourceTray,
            'tray' => $isHidden ? null : ($labels[$sourceTray] ?? null),
            'hidden' => $isHidden,
        ];
    }
    return ['columns' => $columns, 'rows' => $rows, 'cells' => $cells];
}

function rackline_storage_location_identity_matches(array $device, array $raw): bool
{
    return rackline_storage_serial_matches(rackline_storage_text($device['smart_serialnumber'] ?? ''), $raw);
}

/** Resolve native Boot aliases without accepting contradictory assigned disks. */
function rackline_storage_location_nodes(array $rawDisks): array
{
    $candidates = [];
    foreach ($rawDisks as $raw) if (is_array($raw)) {
        $node = rackline_storage_text($raw['device'] ?? '');
        if ($node !== '') $candidates[$node][] = $raw;
    }
    // Even different locators cannot prove a physical identity when the native
    // inventory repeats its exact ID or explicit serial across multiple nodes.
    $owners = []; $ambiguous = [];
    foreach ($candidates as $node => $records) foreach ($records as $record) foreach (['id','serial'] as $field) {
        $value = rackline_storage_text($record[$field] ?? '');
        if ($value !== '') $owners[$field . ':' . $value][$node] = true;
    }
    foreach ($owners as $nodes) if (count($nodes) > 1) foreach ($nodes as $node => $_) $ambiguous[$node] = true;
    $result = [];
    foreach ($candidates as $node => $records) {
        if (isset($ambiguous[$node])) continue;
        if (count($records) === 1) { $result[$node] = ['current' => $records[0], 'display' => $records[0]]; continue; }
        $assigned = array_values(array_filter($records, static fn(array $r): bool => in_array($r['type'] ?? null, ['Data', 'Parity', 'Cache'], true)));
        if (count($assigned) !== 1) continue;
        $current = $assigned[0];
        if (rackline_storage_text($current['id'] ?? '') === '') continue;
        foreach ($records as $record) {
            if ($record !== $current && ($record['type'] ?? null) !== 'Boot') continue 2;
            foreach (['id', 'serial', 'model', 'transport'] as $key) {
                $a = $current[$key] ?? ''; $b = $record[$key] ?? '';
                if (!is_string($a) || !is_string($b) || $a !== $b || !preg_match('//u', $a) || preg_match('/[\x00-\x1f\x7f]/', $a)) continue 3;
            }
        }
        // Native Disk Location's node-indexed display uses the last alias. The
        // actionable Rackline projection remains the single assigned member.
        $result[$node] = ['current' => $current, 'display' => $records[count($records) - 1]];
    }
    return $result;
}

function rackline_storage_location_data(array &$disks, array $rawDisks, array $groupsRaw, array $locationsRaw, array $devicesRaw, array $settings = []): array
{
    foreach ($disks as &$disk) $disk['location'] = null;
    unset($disk);
    $unavailable = ['available' => false, 'groups' => []];
    if (count($groupsRaw) > RACKLINE_STORAGE_MAX_GROUPS || count($locationsRaw) > RACKLINE_STORAGE_MAX_BAYS
        || count($devicesRaw) > RACKLINE_STORAGE_MAX_BAYS) return $unavailable;
    $bayCount = 0;
    foreach ($groupsRaw as $group) {
        if (!is_array($group)) return $unavailable;
        $columns = rackline_storage_count($group['grid_columns'] ?? 1);
        $rows = rackline_storage_count($group['grid_rows'] ?? 1);
        if ($columns === null || $rows === null || $columns < 1 || $rows < 1 || $columns > 100 || $rows > 100) return $unavailable;
        $bayCount += $columns * $rows;
        if ($bayCount > RACKLINE_STORAGE_MAX_BAYS) return $unavailable;
    }
    // Native Disk Location extracts count_bypass_tray from EACH group.
    // A similarly named global setting must never overwrite that group value.
    $nodeRecords = rackline_storage_location_nodes($rawDisks);
    $currentByNode = [];
    foreach ($nodeRecords as $node => $record) $currentByNode[$node] = $record['current'];
    $locationByDevice = [];
    $locationKeysByGroup = [];
    $displayTrayByGroup = [];
    foreach ($locationsRaw as $key => $location) {
        if (!is_array($location)) return $unavailable;
        $groupId = rackline_storage_text($location['groupid'] ?? '');
        $tray = rackline_storage_count($location['tray'] ?? null);
        if ($groupId === '' || $tray === null || $tray < 1) return $unavailable;
        $device = $devicesRaw[$key] ?? null;
        if (is_array($device) && isset($device['removed']) && !is_scalar($device['removed'])) return $unavailable;
        if (!is_array($device) || rackline_storage_text($device['removed'] ?? '') !== '') continue;
        if (isset($locationKeysByGroup[$groupId][$tray])) return $unavailable;
        $locationKeysByGroup[$groupId][$tray] = (string) $key;
    }


    foreach ($groupsRaw as $groupId => $group) {
        if (!is_array($group)) {
            continue;
        }
        $layout = rackline_storage_group_layout($group);
        if (!$layout['cells']) return $unavailable;
        foreach ($layout['cells'] as $cell) {
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
        if (rackline_storage_text($device['removed'] ?? '') !== '') {
            continue;
        }
        $node = basename(rackline_storage_text($device['devicenode'] ?? ''));
        if ($node === '' || !rackline_storage_location_identity_matches($device, $currentByNode[$node] ?? [])) {
            continue;
        }
        if (array_key_exists($node, $locationByDevice)) { $locationByDevice[$node] = null; continue; }
        $locationByDevice[$node] = [
            'key' => (string) $key,
            'groupId' => rackline_storage_text($location['groupid'] ?? ''),
            'tray' => rackline_storage_count($location['tray'] ?? null),
            'color' => preg_match('/^[0-9a-f]{6}$/i', rackline_storage_text($device['color'] ?? '')) ? '#' . $device['color'] : null,
            'comment' => rackline_storage_text($device['comment'] ?? '') ?: null,
            'model' => rackline_storage_text($device['model_name'] ?? '') ?: null,
        ];
    }

    $diskByDevice = [];
    foreach ($disks as &$disk) {
        $device = (string) $disk['device'];
        if (isset($locationByDevice[$device])) {
            $meta = $locationByDevice[$device];
            if (!isset($displayTrayByGroup[$meta['groupId']][$meta['tray']])) continue;
            $groupName = rackline_storage_text($groupsRaw[$meta['groupId']]['group_name'] ?? 'Shelf');
            $displayGroup = $groupName;
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
        $layout = rackline_storage_group_layout($group);
        $columns = $layout['columns'];
        $rows = $layout['rows'];
        $locationKeys = $locationKeysByGroup[(string) $groupId] ?? [];
        $cells = [];
        foreach ($layout['cells'] as $layoutCell) {
            $sourceTray = (int) $layoutCell['sourceTray'];
            $key = $locationKeys[$sourceTray] ?? null;
            $deviceMeta = $key !== null && isset($devicesRaw[$key]) && is_array($devicesRaw[$key]) ? $devicesRaw[$key] : null;
            $deviceNode = $deviceMeta ? basename(rackline_storage_text($deviceMeta['devicenode'] ?? '')) : '';
            $present = !$layoutCell['hidden'] && $deviceMeta !== null && rackline_storage_text($deviceMeta['removed'] ?? '') === '';
            $active = $present && isset($locationByDevice[$deviceNode], $diskByDevice[$deviceNode])
                && $locationByDevice[$deviceNode]['groupId'] === (string) $groupId && $locationByDevice[$deviceNode]['tray'] === $sourceTray
                && rackline_storage_location_identity_matches($deviceMeta, $currentByNode[$deviceNode] ?? [])
                ? $diskByDevice[$deviceNode]
                : null;
            $displayNative = $present && isset($nodeRecords[$deviceNode], $locationByDevice[$deviceNode])
                && $locationByDevice[$deviceNode]['groupId'] === (string) $groupId && $locationByDevice[$deviceNode]['tray'] === $sourceTray
                && rackline_storage_location_identity_matches($deviceMeta, $nodeRecords[$deviceNode]['current'])
                ? $nodeRecords[$deviceNode]['display'] : null;
            $cells[] = [
                'display' => $layoutCell['hidden'] ? null : rackline_storage_location_display($present ? $deviceMeta : null, $group, $settings, $displayNative),
                'tray' => $layoutCell['tray'],
                'sourceTray' => $sourceTray,
                'hidden' => $layoutCell['hidden'],
                'present' => !$layoutCell['hidden'] && $present,
                'inventory' => !$present ? null : [
                    'model' => rackline_storage_text($deviceMeta['model_name'] ?? ''),
                    'serial' => rackline_storage_text($deviceMeta['smart_serialnumber'] ?? ''),
                    'capacityBytes' => rackline_storage_number($deviceMeta['capacity'] ?? null),
                ],
                'disk' => $active === null ? null : [
                    'name' => $active['name'],
                    'device' => $active['device'],
                    'selectionToken' => $active['selectionToken'] ?? null,
                    'type' => $active['type'],
                    'mediaType' => $active['mediaType'],
                    'smart' => $active['smart'] ?? ['available' => false],
                    'poolName' => $active['poolName'],
                    'state' => $active['state'],
                    'spunDown' => $active['spunDown'],
                    'temperatureC' => $active['temperatureC'],
                    'sizeBytes' => $active['sizeBytes'],
                    'usedBytes' => $active['usedBytes'],
                    'usagePercent' => $active['usagePercent'],
                    'identityColor' => $active['identityColor'],
                ],
            ];
        }
        $name = rackline_storage_text($group['group_name'] ?? ('Shelf ' . $groupId));
        $width = rackline_storage_count($group['tray_width'] ?? null);
        $height = rackline_storage_count($group['tray_height'] ?? null);
        $width = $width !== null && $width > 0 && $width <= 4096 ? $width : null;
        $height = $height !== null && $height > 0 && $height <= 4096 ? $height : null;
        if (($group['disk_tray_direction'] ?? 'h') === 'v') [$width, $height] = [$height, $width];
        $groups[] = [
            'trayAlign' => in_array($group['tray_align'] ?? null, ['left','center','right','start','end'], true) ? $group['tray_align'] : null,
            'nameAlign' => in_array($group['tray_align_txt'] ?? null, ['left','center','right','hide','vertical'], true) ? $group['tray_align_txt'] : null,
            'position' => in_array($group['tray_pos'] ?? null, ['left','right','none'], true) ? $group['tray_pos'] : null,
            'trayWidth' => $width, 'trayHeight' => $height,
            'id' => (string) $groupId,
            'name' => $name,
            'trayDirection' => ($group['disk_tray_direction'] ?? 'h') === 'v' ? 'v' : 'h',
            'columns' => $columns,
            'rows' => $rows,
            'cells' => $cells,
        ];
    }
    // Native devices.php iterates persisted groups directly. Its is_array(...)
    // ?? ksort(...) expression does not sort an array; preserve source order.
    return ['available' => count($groups) > 0, 'groups' => $groups];
}

function rackline_storage_pool_data(array &$disks, array $poolConfigs): array
{
    if (count($poolConfigs) > RACKLINE_STORAGE_MAX_POOLS) throw new RuntimeException('Pool inventory exceeds bounded collection');
    $pools = [];
    $preferredOrder = ['cache' => 0, 'servers' => 1, 'downloads-cache' => 2, 'akashic' => 3];
    $colors = rackline_storage_pool_colors(array_keys($poolConfigs));
    // A pool named cache2 owns cache2/cache22 before the shorter cache prefix.
    $owners = [];
    foreach ($disks as $disk) {
        $matches = [];
        foreach ($poolConfigs as $name => $_) if ($disk['name'] === (string) $name || preg_match('/^' . preg_quote((string) $name, '/') . '[0-9]+$/D', $disk['name'])) $matches[] = (string) $name;
        usort($matches, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $owners[$disk['name']] = $matches[0] ?? null;
    }
    foreach ($poolConfigs as $name => $config) {
        $name = (string) $name;
        if (!is_array($config)) $config = [];
        $members = [];
        foreach ($disks as &$disk) {
            if ($disk['type'] !== 'Cache') {
                continue;
            }
            if (($owners[$disk['name']] ?? null) === (string) $name) {
                $disk['poolName'] = $name;
                $disk['identityColor'] = $colors[$name];
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
        $usage = rackline_storage_usage($size, $used, $free);
        $states = array_column($members, 'state');
        if (!$members || $usage === null) $states[] = 'unknown';
        if ($usage !== null) {
            $states[] = $usage >= RACKLINE_STORAGE_FAULT_PERCENT ? 'fault' : ($usage >= RACKLINE_STORAGE_WARN_PERCENT ? 'attention' : 'normal');
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
            'label' => rackline_storage_title($name),
            'fileSystem' => rackline_storage_text($config['diskFsType'] ?? '') ?: ($members[0]['fileSystem'] ?? null),
            'profile' => rackline_storage_text($config['diskFsProfile'] ?? '') ?: null,
            'sizeBytes' => $size,
            'usedBytes' => $used,
            'freeBytes' => $free,
            'usagePercent' => $usage,
            'state' => rackline_storage_worst_state($states),
            'identityColor' => $color,
            'memberCount' => count($members),
            'members' => array_values($members),
            'order' => $preferredOrder[$name] ?? (100 + count($pools)),
        ];
    }
    // Native Cache disks must remain visible if optional pool configuration is
    // missing. Preserve each disk separately without inventing pool membership.
    foreach ($disks as $disk) {
        if ($disk['type'] !== 'Cache' || isset($owners[$disk['name']])) continue;
        $pools[] = [
            'name' => 'unmapped:' . $disk['name'], 'label' => 'Pool unknown · ' . $disk['name'],
            'fileSystem' => $disk['fileSystem'], 'profile' => null,
            'sizeBytes' => null, 'usedBytes' => null, 'freeBytes' => null, 'usagePercent' => null,
            'state' => rackline_storage_worst_state([$disk['state'], 'unknown']), 'identityColor' => $disk['identityColor'],
            'memberCount' => 1, 'members' => [$disk], 'metadataComplete' => false, 'order' => 100 + count($pools),
        ];
    }
    usort($pools, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);
    foreach ($pools as &$pool) {
        unset($pool['order']);
    }
    unset($pool);
    return $pools;
}

function rackline_storage_array_data(array $disks, array $variables): array
{
    $arrayDisks = array_values(array_filter($disks, static fn(array $disk): bool => $disk['type'] === 'Data'));
    usort($arrayDisks, static fn(array $left, array $right): int => $left['index'] <=> $right['index']);
    $size = 0.0;
    $used = 0.0;
    $hasSize = count($arrayDisks) > 0;
    $hasUsed = count($arrayDisks) > 0;
    foreach ($arrayDisks as $disk) {
        $diskSize = rackline_storage_number($disk['sizeBytes'] ?? null);
        $diskUsed = rackline_storage_number($disk['usedBytes'] ?? null);
        $diskFree = rackline_storage_number($disk['freeBytes'] ?? null);
        if ($diskSize === null || $diskSize <= 0) $hasSize = false;
        else $size += $diskSize;
        // A partial or inconsistent native sample is not a full/empty filesystem.
        // Do not require used + free == size: filesystem accounting may differ.
        if ($diskSize !== null && ($diskUsed !== null && $diskUsed > $diskSize || $diskFree !== null && $diskFree > $diskSize)) {
            $hasUsed = false;
            continue;
        }
        if ($diskUsed === null && $diskSize !== null && $diskSize >= 0 && $diskFree !== null && $diskFree >= 0)
            $diskUsed = $diskSize - $diskFree;
        if ($diskUsed === null || $diskUsed < 0) $hasUsed = false;
        else $used += $diskUsed;
    }
    if (!is_finite($size) || $size > RACKLINE_STORAGE_SAFE_INTEGER) $hasSize = false;
    if (!is_finite($used) || $used > RACKLINE_STORAGE_SAFE_INTEGER) $hasUsed = false;
    $states = array_column($arrayDisks, 'state');
    $configured = count(array_filter($disks, static fn(array $disk): bool => in_array($disk['type'], ['Data', 'Parity'], true))) > 0;
    $mdState = strtoupper(rackline_storage_text($variables['mdState'] ?? ''));
    $counts = [];
    foreach (['mdNumMissing', 'mdNumDisabled', 'mdNumInvalid'] as $key) {
        $counts[$key] = rackline_storage_count($variables[$key] ?? null);
        if ($counts[$key] !== null && $counts[$key] > 0) $states[] = 'fault';
        elseif ($configured && $counts[$key] === null) $states[] = 'unknown';
    }
    if ($configured && ($mdState === '' || (count($arrayDisks) > 0 && (!$hasSize || !$hasUsed)))) $states[] = 'unknown';
    if ($mdState !== '' && $mdState !== 'STARTED') {
        $states[] = 'fault';
    }
    return [
        'mdState' => $mdState ?: 'UNKNOWN',
        'configured' => $configured,
        'missingCount' => $counts['mdNumMissing'],
        'disabledCount' => $counts['mdNumDisabled'],
        'invalidCount' => $counts['mdNumInvalid'],
        'state' => rackline_storage_worst_state($states),
        'diskCount' => count($arrayDisks),
        'sizeBytes' => $hasSize ? $size : null,
        'usedBytes' => $hasUsed ? $used : null,
        'usagePercent' => $hasSize && $hasUsed && $size > 0 ? $used / $size * 100.0 : null,
        'disks' => $arrayDisks,
    ];
}

function rackline_storage_cron_values(string $expression, int $minimum, int $maximum): array
{
    $values = [];
    if (strlen($expression) > 256) return [];
    foreach (explode(',', trim($expression)) as $part) {
        if (!preg_match('/^(\*|[0-9]+(?:-[0-9]+)?)(?:\/([0-9]+))?$/D', $part, $match)) return [];
        $step = isset($match[2]) ? rackline_storage_count($match[2]) : 1;
        if ($step === null || $step < 1) return [];
        $range = $match[1];
        if ($range === '*') {
            $start = $minimum; $end = $maximum;
        } else {
            $parts = explode('-', $range);
            $start = rackline_storage_count($parts[0]);
            $end = isset($parts[1]) ? rackline_storage_count($parts[1]) : (isset($match[2]) ? $maximum : $start);
        }
        if ($start === null || $end === null || $start < $minimum || $end > $maximum || $end < $start) return [];
        for ($value = $start; $value <= $end; $value += $step) $values[$value] = true;
    }
    $result = array_map('intval', array_keys($values));
    sort($result, SORT_NUMERIC);
    return $result;
}

function rackline_storage_cron_matches(string $expression, int $value, int $minimum, int $maximum): bool
{
    return in_array($value, rackline_storage_cron_values($expression, $minimum, $maximum), true);
}

function rackline_storage_next_parity(array $parityConfig, int $now, string $cron = ''): ?int
{
    if (rackline_storage_text($parityConfig['mode'] ?? '') === '0' || trim($cron) === '' || strlen($cron) > 65536) {
        return null;
    }
    $schedule = null;
    foreach (preg_split('/\r?\n/', $cron) ?: [] as $line) {
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
    $minutes = rackline_storage_cron_values($minuteExpression, 0, 59);
    $hours = rackline_storage_cron_values($hourExpression, 0, 23);
    $days = rackline_storage_cron_values($dayExpression, 1, 31);
    $months = rackline_storage_cron_values($monthExpression, 1, 12);
    $weekdays = rackline_storage_cron_values($weekdayExpression, 0, 7);
    if (!$minutes || !$hours || !$days || !$months || !$weekdays) {
        return null;
    }
    $timezone = new DateTimeZone(date_default_timezone_get());
    $day = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0, 0);
    $weekOfMonth = null;
    if (preg_match('/^W([1-5])$/i', rackline_storage_text($parityConfig['dotm'] ?? ''), $match)) {
        $weekOfMonth = (int) $match[1];
    }
    for ($offset = 0; $offset <= 800; $offset++) {
        $candidateDay = $day->modify('+' . $offset . ' days');
        $month = (int) $candidateDay->format('n');
        $dayOfMonth = (int) $candidateDay->format('j');
        $weekday = (int) $candidateDay->format('w');
        $weekdayMatches = in_array($weekday, $weekdays, true) || ($weekday === 0 && in_array(7, $weekdays, true));
        $dayMatches = in_array($dayOfMonth, $days, true);
        // Native cron uses OR when both day-of-month and weekday are restricted.
        $dateMatches = str_starts_with($dayExpression, '*') || str_starts_with($weekdayExpression, '*')
            ? $dayMatches && $weekdayMatches : $dayMatches || $weekdayMatches;
        if (!in_array($month, $months, true) || !$dateMatches
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

function rackline_storage_parity_data(array $disks, array $variables, array $parityConfig, string $cron = '', ?int $now = null): array
{
    $parityDisks = array_values(array_filter($disks, static fn(array $disk): bool => $disk['type'] === 'Parity'));
    usort($parityDisks, static fn(array $left, array $right): int => strnatcasecmp((string) $left['name'], (string) $right['name']));
    $position = rackline_storage_count($variables['mdResyncPos'] ?? null);
    $size = rackline_storage_count($variables['mdResyncSize'] ?? null);
    $active = (rackline_storage_count($variables['mdResync'] ?? null) ?? 0) > 0
        || ($position !== null && $size !== null && $position > 0 && $size > 0 && $position < $size);
    $errors = rackline_storage_count($variables['mdSyncErrs'] ?? ($variables['sbSyncErrs'] ?? null));
    $counts = array_map(static fn(string $key): ?int => rackline_storage_count($variables[$key] ?? null), ['mdNumMissing', 'mdNumDisabled', 'mdNumInvalid']);
    $missing = in_array(null, $counts, true) ? null : array_sum($counts);
    if ($missing !== null && $missing > RACKLINE_STORAGE_SAFE_INTEGER) $missing = null;
    $missingFault = count(array_filter($counts, static fn(?int $count): bool => $count !== null && $count > 0)) > 0;
    $exitValue = $variables['sbSyncExit'] ?? null;
    $exit = is_scalar($exitValue) && !is_bool($exitValue) ? trim((string) $exitValue) : '';
    if (!preg_match('/^-?\d+$/D', $exit)) $exit = null;
    $cancelled = $exit === '-4';
    $states = array_column($parityDisks, 'state');
    $configured = count($parityDisks) > 0;
    $complete = $errors !== null && $missing !== null && $exit !== null && !in_array('unknown', $states, true);
    if ($configured && !$complete) $states[] = 'unknown';
    if (($errors !== null && $errors > 0) || $missingFault || ($exit !== null && $exit !== '0' && !$cancelled)) {
        $states[] = 'fault';
    } elseif ($active || $cancelled) {
        $states[] = 'attention';
    }
    $started = rackline_storage_count($variables['sbSynced'] ?? null);
    $finished = rackline_storage_count($variables['sbSynced2'] ?? null);
    return [
        'state' => rackline_storage_worst_state($states),
        'configured' => $configured,
        'valid' => !$configured || $cancelled || in_array('fault', $states, true) ? false : ($complete ? true : null),
        'missingCount' => $missing,
        'exitCode' => $exit,
        'cancelled' => $cancelled,
        'errors' => $errors,
        'action' => rackline_storage_text($variables['mdResyncAction'] ?? 'check'),
        'active' => $active,
        'positionBytes' => rackline_storage_kib_bytes($position),
        'sizeBytes' => rackline_storage_kib_bytes($size),
        'progressPercent' => $position !== null && $size !== null && $size > 0 && $position <= $size ? $position / $size * 100.0 : null,
        'lastStartedAt' => $started > 0 ? $started : null,
        'lastFinishedAt' => $finished > 0 ? $finished : null,
        'lastDurationSeconds' => $started > 0 && $finished >= $started ? $finished - $started : null,
        'nextScheduledAt' => rackline_storage_next_parity($parityConfig, $now ?? time(), $cron),
        'disks' => $parityDisks,
    ];
}

function rackline_storage_disk_attention(array $disk): ?string
{
    if (($disk['state'] ?? 'normal') === 'normal') {
        return null;
    }
    $name = preg_replace('/^disk/i', 'Disk ', rackline_storage_text($disk['name'] ?? 'Disk'));
    if (($disk['type'] ?? '') === 'Parity') {
        $name = strcasecmp(rackline_storage_text($disk['name'] ?? ''), 'parity2') === 0 ? 'Parity 2' : 'Parity 1';
    }
    if (($disk['state'] ?? '') === 'unknown') return $name . ' · native status incomplete';
    $status = strtoupper(rackline_storage_text($disk['status'] ?? ''));
    if ($status !== '' && $status !== 'DISK_OK') {
        return $name . ' · ' . ($disk['statusLabel'] ?? $status);
    }
    $errors = (int) ($disk['errors'] ?? 0);
    if ($errors > 0) {
        return $name . ' · ' . $errors . ' error' . ($errors === 1 ? '' : 's');
    }
    $temperature = rackline_storage_number($disk['temperatureC'] ?? null);
    $critical = rackline_storage_number($disk['criticalC'] ?? null);
    $warning = rackline_storage_number($disk['warningC'] ?? null);
    if ($temperature !== null && $critical !== null && $critical > 0 && $temperature >= $critical) {
        return $name . ' · ' . round($temperature) . '°C critical';
    }
    if ($temperature !== null && $warning !== null && $warning > 0 && $temperature >= $warning) {
        return $name . ' · ' . round($temperature) . '°C warm';
    }
    $usage = rackline_storage_number($disk['usagePercent'] ?? null);
    if ($usage !== null && $usage >= RACKLINE_STORAGE_WARN_PERCENT) {
        return $name . ' · ' . round($usage) . '% full';
    }
    return $name . ' · requires attention';
}

function rackline_storage_attention(array $array, array $parity, array $pools): array
{
    $messages = [];
    if (($parity['active'] ?? false) === true) {
        $progress = rackline_storage_number($parity['progressPercent'] ?? null);
        $messages[] = 'Parity check in progress' . ($progress === null ? '' : ' · ' . round($progress) . '%');
    } elseif (($parity['configured'] ?? false) && ($parity['valid'] ?? null) !== true) {
        $messages[] = ($parity['valid'] ?? null) === null ? 'Parity · native status incomplete' : (((int) ($parity['errors'] ?? 0)) > 0
            ? 'Parity · ' . (int) $parity['errors'] . ' error' . ((int) $parity['errors'] === 1 ? '' : 's')
            : (($parity['cancelled'] ?? false) ? 'Parity · check cancelled' : 'Parity · last check did not complete cleanly'));
    }
    foreach ((array) ($parity['disks'] ?? []) as $disk) {
        $message = is_array($disk) ? rackline_storage_disk_attention($disk) : null;
        if ($message !== null) {
            $messages[] = $message;
        }
    }
    if (($array['configured'] ?? false) && ($array['state'] ?? '') === 'unknown') $messages[] = 'Array · native status incomplete';
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
        $message = is_array($disk) ? rackline_storage_disk_attention($disk) : null;
        if ($message !== null) {
            $messages[] = $message;
        }
    }
    foreach ($pools as $pool) {
        if (!is_array($pool) || ($pool['state'] ?? 'normal') === 'normal') {
            continue;
        }
        if (($pool['metadataComplete'] ?? true) === false || ($pool['state'] ?? '') === 'unknown')
            $messages[] = rackline_storage_text($pool['label'] ?? 'Pool') . ' · native pool status incomplete';
        $usage = rackline_storage_number($pool['usagePercent'] ?? null);
        if ($usage !== null && $usage >= RACKLINE_STORAGE_WARN_PERCENT) {
            $messages[] = (string) ($pool['label'] ?? rackline_storage_title((string) ($pool['name'] ?? 'Pool'))) . ' · ' . round($usage) . '% full';
            continue;
        }
        foreach ((array) ($pool['members'] ?? []) as $disk) {
            $message = is_array($disk) ? rackline_storage_disk_attention($disk) : null;
            if ($message !== null) {
                $messages[] = $message;
            }
        }
    }
    $messages = array_values(array_unique($messages));
    return ['count' => count($messages), 'messages' => $messages];
}

function rackline_storage_snapshot(array $sources = []): array
{
    $sources += rackline_storage_sources();
    $rawDisks = rackline_storage_ini($sources['disks'], true, true);
    $variables = rackline_storage_ini($sources['variables'], false, true);
    $dynamixConfig = rackline_storage_ini($sources['dynamix'], true);
    $parityConfig = is_array($dynamixConfig['parity'] ?? null) ? $dynamixConfig['parity'] : [];
    $displaySettings = is_array($dynamixConfig['display'] ?? null) ? $dynamixConfig['display'] : [];
    $smartSettings = rackline_storage_ini($sources['smartSettings'], true);
    $disks = rackline_storage_disk_records($rawDisks, $smartSettings, $displaySettings, $sources['smartCache']);
    $locationFiles = []; $locationTimes = [];
    foreach (['groups','locations','devices','settings'] as $key) {
        $file = rackline_storage_location_file($sources['locations'] . '/' . $key . '.json');
        $locationFiles[$key] = $file['data']; $locationTimes[$key] = $file['writtenAtMs'];
    }
    $locationDefaults = isset($sources['locationDefaults']) ? rackline_storage_location_defaults(rackline_storage_read($sources['locationDefaults'], 262144) ?? '') : [];
    $locations = rackline_storage_location_data($disks, $rawDisks, $locationFiles['groups'], $locationFiles['locations'], $locationFiles['devices'], array_replace($locationDefaults, $locationFiles['settings']));
    $locations['source'] = 'disklocation_cache';
    $locations['cacheWrittenAtMs'] = $locationTimes;
    $poolConfigs = [];
    $poolPaths = glob($sources['pools'] . '/*.cfg') ?: [];
    if (count($poolPaths) > RACKLINE_STORAGE_MAX_POOLS) throw new RuntimeException('Pool inventory exceeds bounded collection');
    $poolBytes = 0;
    foreach ($poolPaths as $path) {
        $poolBytes += (int) @filesize($path);
        if ($poolBytes > 1048576) throw new RuntimeException('Pool metadata exceeds bounded collection');
        $poolConfigs[basename($path, '.cfg')] = rackline_storage_ini($path);
    }
    $pools = rackline_storage_pool_data($disks, $poolConfigs);
    $disks = rackline_storage_apply_array_colors($disks);
    foreach ($disks as &$disk) {
        if (!$disk['identityColor']) $disk['identityColor'] = rackline_storage_identity_color('pool:' . ($disk['poolName'] ?: $disk['name']));
    }
    unset($disk);
    $diskByDevice = [];
    foreach ($disks as $disk) {
        $diskByDevice[(string) $disk['device']] = [
            'poolName' => $disk['poolName'],
            'identityColor' => $disk['identityColor'],
            'identityGroup' => $disk['identityGroup'] ?? null,
            'identityLabel' => $disk['identityLabel'] ?? null,
        ];
    }
    foreach ($locations['groups'] as &$group) {
        foreach ($group['cells'] as &$cell) {
            if (is_array($cell['disk'] ?? null)) {
                $meta = $diskByDevice[(string) ($cell['disk']['device'] ?? '')] ?? null;
                $cell['disk']['poolName'] = $meta['poolName'] ?? null;
                $cell['disk']['identityColor'] = $meta['identityColor'] ?? $cell['disk']['identityColor'];
                $cell['disk']['identityGroup'] = $meta['identityGroup'] ?? null;
                $cell['disk']['identityLabel'] = $meta['identityLabel'] ?? null;
            }
        }
        unset($cell);
    }
    unset($group);
    $array = rackline_storage_array_data($disks, $variables);
    $parity = rackline_storage_parity_data($disks, $variables, $parityConfig, rackline_storage_read($sources['parityCron']) ?? '');
    $state = rackline_storage_worst_state(array_merge([$array['state'], $parity['state']], array_column($pools, 'state')));
    return [
        'schemaVersion' => 1,
        'version' => RACKLINE_STORAGE_VERSION,
        'pluginVersion' => RACKLINE_STORAGE_PLUGIN_VERSION,
        'sampledAtMs' => (int) round(microtime(true) * 1000),
        'state' => $state,
        'attention' => rackline_storage_attention($array, $parity, $pools),
        'array' => $array,
        'parity' => $parity,
        'pools' => $pools,
        'locations' => $locations,
        'driveColorGroups' => rackline_storage_array_color_legend($disks),
    ];
}

if (!defined('RACKLINE_STORAGE_NO_MAIN')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['schemaVersion' => 1, 'error' => 'GET required']);
        exit;
    }
    try {
        echo json_encode(rackline_storage_snapshot(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(503);
        echo json_encode([
            'schemaVersion' => 1,
            'version' => RACKLINE_STORAGE_VERSION,
            'pluginVersion' => RACKLINE_STORAGE_PLUGIN_VERSION,
            'error' => 'Unable to read Rackline Storage telemetry',
        ], JSON_UNESCAPED_SLASHES);
    }
}
