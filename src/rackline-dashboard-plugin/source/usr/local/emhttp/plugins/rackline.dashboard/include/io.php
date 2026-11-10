<?php
/** Read-only kernel counters. No disks are opened or probed. Rates belong to the client. */
const RACKLINE_IO_MAX_BYTES = 1048576;
const RACKLINE_IO_MAX_DEVICES = 8192;
function rackline_io_read($path, $limit) {
    $text = @file_get_contents($path, false, null, 0, $limit + 1);
    return $text === false || strlen($text) > $limit ? false : $text;
}
function rackline_io_unavailable($reason, $sampledAtMs) {
    return ['schemaVersion' => 1, 'status' => 'unavailable', 'error' => $reason,
        'sampledAtMs' => $sampledAtMs, 'monotonicSeconds' => null, 'devices' => (object) []];
}
function rackline_io_counter($value, $multiplier = 1) {
    // JSON integers must remain exact in JavaScript. Reject overflow, never fabricate zero.
    if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)) return null;
    $number = (float) $value;
    if (!is_finite($number) || $number > floor(9007199254740991 / $multiplier)) return null;
    return $number * $multiplier;
}
function rackline_io_parse($diskstats, $uptime, $sampledAtMs) {
    if (!is_string($diskstats) || strlen($diskstats) > RACKLINE_IO_MAX_BYTES || trim($diskstats) === ''
        || !is_string($uptime) || strlen($uptime) > 128)
        return rackline_io_unavailable('Kernel counters unavailable', $sampledAtMs);
    if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s+[0-9]+(?:\.[0-9]+)?\s*$/D', $uptime, $match)
        || !is_finite((float) $match[1]) || (float) $match[1] > 9007199254740991)
        return rackline_io_unavailable('Invalid kernel uptime', $sampledAtMs);
    $devices = [];
    foreach (preg_split('/\r?\n/', trim($diskstats)) as $line) {
        if (count($devices) >= RACKLINE_IO_MAX_DEVICES) return rackline_io_unavailable('Kernel inventory exceeds bounded collection', $sampledAtMs);
        $fields = preg_split('/\s+/', trim($line), 33);
        if (count($fields) < 14 || count($fields) > 32 || rackline_io_counter($fields[0]) === null || rackline_io_counter($fields[1]) === null
            || !preg_match('/^[A-Za-z0-9_!.-]{1,128}$/D', $fields[2]) || in_array($fields[2], ['.', '..'], true) || isset($devices[$fields[2]]))
            return rackline_io_unavailable('Invalid kernel disk counters', $sampledAtMs);
        // Validate all numeric counters, including optional discard/flush fields.
        for ($i = 3; $i < count($fields); $i++) {
            if (rackline_io_counter($fields[$i]) === null)
                return rackline_io_unavailable('Invalid kernel disk counters', $sampledAtMs);
        }
        $read = rackline_io_counter($fields[5], 512);
        $write = rackline_io_counter($fields[9], 512);
        if ($read === null || $write === null)
            return rackline_io_unavailable('Kernel counters exceed safe precision', $sampledAtMs);
        $devices[$fields[2]] = [
            'major' => $fields[0], 'minor' => $fields[1],
            'readBytes' => $read, 'writeBytes' => $write,
            'readOperations' => rackline_io_counter($fields[3]),
            'writeOperations' => rackline_io_counter($fields[7]),
            'inFlight' => rackline_io_counter($fields[11]),
            'busyMs' => rackline_io_counter($fields[12]),
            'weightedBusyMs' => rackline_io_counter($fields[13])
        ];
    }
    return ['schemaVersion' => 1, 'status' => 'ok', 'sampledAtMs' => $sampledAtMs,
        'monotonicSeconds' => (float) $match[1], 'devices' => (object) $devices];
}
if (!defined('RACKLINE_IO_NO_MAIN')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(rackline_io_unavailable('GET required', (int) floor(microtime(true) * 1000)));
        exit;
    }
    $sample = rackline_io_parse(rackline_io_read('/proc/diskstats', RACKLINE_IO_MAX_BYTES),
        rackline_io_read('/proc/uptime', 128), (int) floor(microtime(true) * 1000));
    if ($sample['status'] !== 'ok') http_response_code(503);
    echo json_encode($sample, JSON_UNESCAPED_SLASHES);
}
