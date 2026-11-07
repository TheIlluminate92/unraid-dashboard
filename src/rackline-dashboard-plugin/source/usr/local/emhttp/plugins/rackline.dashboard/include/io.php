<?php
/** Read-only kernel counters. No disks are opened or probed. Rates belong to the client. */
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
    if (!is_string($diskstats) || trim($diskstats) === '' || !is_string($uptime))
        return rackline_io_unavailable('Kernel counters unavailable', $sampledAtMs);
    if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s+[0-9]+(?:\.[0-9]+)?\s*$/D', $uptime, $match)
        || !is_finite((float) $match[1]))
        return rackline_io_unavailable('Invalid kernel uptime', $sampledAtMs);
    $devices = [];
    foreach (preg_split('/\r?\n/', trim($diskstats)) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) < 14 || !ctype_digit($fields[0]) || !ctype_digit($fields[1])
            || !preg_match('/^[A-Za-z0-9_!.-]+$/D', $fields[2]) || isset($devices[$fields[2]]))
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
    $sample = rackline_io_parse(@file_get_contents('/proc/diskstats'),
        @file_get_contents('/proc/uptime'), (int) floor(microtime(true) * 1000));
    if ($sample['status'] !== 'ok') http_response_code(503);
    echo json_encode($sample, JSON_UNESCAPED_SLASHES);
}
