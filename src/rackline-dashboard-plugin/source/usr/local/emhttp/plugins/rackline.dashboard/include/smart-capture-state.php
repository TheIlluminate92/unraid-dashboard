<?php

declare(strict_types=1);

// Selected-drive metadata only: never invokes the process adapter or creates a cache.
define('RACKLINE_SMART_REPORT_NO_MAIN', true);
require_once __DIR__ . '/smart-report.php';
require_once __DIR__ . '/smart-capture-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');
$paths = rackline_capture_paths();
$response = rackline_capture_state($_SERVER['REQUEST_METHOD'] ?? '', $_GET, $_SERVER['QUERY_STRING'] ?? '',
    rackline_capture_native_auth(), static fn(string $name): array => rackline_capture_binding($name, $paths), $paths['cache']);
http_response_code($response['status']);
if ($response['status'] === 405) header('Allow: GET');
echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
