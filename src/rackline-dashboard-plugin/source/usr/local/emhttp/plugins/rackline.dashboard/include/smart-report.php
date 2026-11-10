<?php

declare(strict_types=1);

// Independent cache reader: never load native Device/SMART handlers or generate a report.
if (!defined('RACKLINE_STORAGE_NO_MAIN')) define('RACKLINE_STORAGE_NO_MAIN', true);
require_once __DIR__ . '/storage.php';

function rackline_smart_report_disk(string $name, string $source): ?array
{
    $text = rackline_storage_read($source);
    if ($text === null) throw new RuntimeException('Inventory unavailable');
    // Native inventory is generated text. Reject binary/control ambiguity before
    // PHP's INI parser can silently truncate it or hide malformed records.
    if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text))
        throw new RuntimeException('Invalid inventory text');
    // PHP's INI parser otherwise silently merges duplicate section names.
    // The INI parser accepts CRLF and lone CR as line boundaries. Scan the
    // same boundaries so a carriage return cannot conceal a repeated section.
    // PHP also skips a leading UTF-8 BOM. Do not let it hide the first header.
    $sectionText = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    if (str_contains($sectionText, "\xEF\xBB\xBF")) throw new RuntimeException('Unsupported inventory encoding');
    $sectionText = str_replace(["\r\n", "\r"], "\n", $sectionText);
    preg_match_all('/^[ \t]*\[([^\]\r\n]+)\][ \t]*(?:;[^\r\n]*)?$/m', $sectionText, $sections);
    $sectionLines = preg_match_all('/^[ \t]*\[/m', $sectionText);
    if ($sectionLines !== count($sections[1])) throw new RuntimeException('Unsupported inventory section');
    $sectionNames = array_map(static fn ($value) => trim($value, " \t\""), $sections[1]);
    if (count($sectionNames) > RACKLINE_STORAGE_MAX_DISKS || count(array_unique($sectionNames)) !== count($sectionNames))
        throw new RuntimeException('Ambiguous inventory');
    $disks = @parse_ini_string($text, true, INI_SCANNER_RAW);
    if (!is_array($disks) || count($disks) > RACKLINE_STORAGE_MAX_DISKS) throw new RuntimeException('Inventory unavailable');
    $names = $devices = $identities = $serials = [];
    $selected = null;
    foreach ($disks as $section => $disk) {
        if (!is_array($disk)) throw new RuntimeException('Malformed inventory');
        foreach (['name', 'type', 'device', 'id', 'serial', 'model'] as $key)
            if (isset($disk[$key]) && (!is_scalar($disk[$key]) || is_bool($disk[$key]))) throw new RuntimeException('Malformed inventory');
        $diskName = rackline_storage_text($disk['name'] ?? trim((string) $section, '"'));
        if (!in_array(rackline_storage_text($disk['type'] ?? ''), ['Data', 'Parity', 'Cache'], true)
            || rackline_storage_unassigned_slot($disk)) continue;
        $device = rackline_storage_text($disk['device'] ?? '');
        $id = rackline_storage_text($disk['id'] ?? '');
        $serial = rackline_storage_text($disk['serial'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $diskName) || isset($names[$diskName])
            || $device !== '' && isset($devices[$device]) || $id !== '' && isset($identities[$id])
            || $serial !== '' && isset($serials[$serial])) throw new RuntimeException('Ambiguous inventory');
        $names[$diskName] = true;
        if ($device !== '') $devices[$device] = true;
        if ($id !== '') $identities[$id] = true;
        if ($serial !== '') $serials[$serial] = true;
        if ($diskName === $name) $selected = $disk;
    }
    return $selected;
}

function rackline_smart_report_query(array $query, string $rawQuery): bool
{
    if (count($query) !== 2 || !is_string($query['name'] ?? null) || !is_string($query['identity'] ?? null)
        || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $query['name'])
        || !preg_match('/^[a-f0-9]{64}$/D', $query['identity']) || strlen($rawQuery) > 1024) return false;
    $seen = [];
    foreach (explode('&', $rawQuery) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) !== 2) return false;
        [$key, $value] = array_map('urldecode', $parts);
        if (!in_array($key, ['name', 'identity'], true) || isset($seen[$key]) || $query[$key] !== $value) return false;
        $seen[$key] = true;
    }
    return count($seen) === 2;
}

function rackline_smart_report_response(string $method, array $query, string $rawQuery, array $sources): array
{
    if ($method !== 'GET') return ['status' => 405, 'body' => "GET required\n"];
    if (!rackline_smart_report_query($query, $rawQuery)) return ['status' => 400, 'body' => "Invalid cached report request\n"];
    $unavailable = ['status' => 404, 'body' => "No identity-verified cached SMART report is available for this drive.\n"];
    try {
        $raw = rackline_smart_report_disk($query['name'], $sources['disks']);
        if ($raw === null || rackline_storage_smart_identity($query['name'], $raw) !== $query['identity']) return $unavailable;
        $report = rackline_storage_smart_report($query['name'], $raw, $sources['smartCache']);
        if ($report === null) return $unavailable;
        // A drive can be replaced after the dashboard snapshot or during the read.
        $current = rackline_smart_report_disk($query['name'], $sources['disks']);
        if ($current === null || rackline_storage_smart_identity($query['name'], $current) !== $query['identity']) return $unavailable;
        $cached = gmdate('Y-m-d H:i:s', (int) ($report['cachedAtMs'] / 1000)) . ' UTC';
        return ['status' => 200, 'filename' => 'rackline-smart-' . $query['name'] . '.txt',
            'body' => "Cached SMART report for " . $query['name'] . "\nCache file modified: " . $cached
                . "\nThis is the existing cached report, not a new SMART query or self-test.\n"
                . "The cache timestamp is not a test time and does not establish current drive health.\n\n" . $report['text']];
    } catch (Throwable $error) {
        // No paths, identities or internal exceptions in responses.
        return ['status' => 503, 'body' => "Cached SMART report is temporarily unavailable.\n"];
    }
}

if (!defined('RACKLINE_SMART_REPORT_NO_MAIN')) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Referrer-Policy: no-referrer');
    $response = rackline_smart_report_response($_SERVER['REQUEST_METHOD'] ?? '', $_GET,
        $_SERVER['QUERY_STRING'] ?? '', rackline_storage_sources());
    http_response_code($response['status']);
    if ($response['status'] === 405) header('Allow: GET');
    if (isset($response['filename'])) header('Content-Disposition: inline; filename="' . $response['filename'] . '"');
    echo $response['body'];
}
