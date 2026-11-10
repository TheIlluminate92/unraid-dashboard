<?php

declare(strict_types=1);

// Explicit authenticated action only; never called by snapshot collection.
define('RACKLINE_SMART_REPORT_NO_MAIN', true);
require_once __DIR__ . '/smart-report.php';
require_once __DIR__ . '/smart-capture-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');
ignore_user_abort(true);
$paths = rackline_capture_paths();
$body = (string)file_get_contents('php://input', false, null, 0, 4097);
$response = rackline_capture_response($_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['QUERY_STRING'] ?? '',
    $_SERVER['CONTENT_TYPE'] ?? '', $body, rackline_capture_native_auth(),
    is_string($GLOBALS['var']['csrf_token'] ?? null) ? $GLOBALS['var']['csrf_token'] : '',
    static fn(string $name): array => rackline_capture_binding($name, $paths),
    static function (array $binding) use ($paths): array {
        clearstatcache(true, $paths['executable']);
        $stat = @lstat($paths['executable']);
        if (!$stat || is_link($paths['executable']) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0 || !is_executable($paths['executable']))
            return ['kind' => 'failed', 'error' => 'executable_unavailable'];
        return rackline_capture_run(array_merge([$paths['executable'], '-x'], $binding['options'], ['/dev/' . $binding['device']]));
    }, $paths['cache'], (int)(hrtime(true) / 1e9), (int)floor(microtime(true) * 1000));
http_response_code($response['status']);
if ($response['status'] === 405) header('Allow: POST');
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
