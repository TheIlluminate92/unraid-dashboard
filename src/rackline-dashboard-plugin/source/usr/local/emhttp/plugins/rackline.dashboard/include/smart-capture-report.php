<?php

declare(strict_types=1);

// This endpoint only retrieves a proven previous capture; it never refreshes one.
define('RACKLINE_SMART_REPORT_NO_MAIN', true);
require_once __DIR__ . '/smart-report.php';
require_once __DIR__ . '/smart-capture-lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Referrer-Policy: no-referrer');
$paths = rackline_capture_paths();
$response = rackline_capture_download($_SERVER['REQUEST_METHOD'] ?? '', $_GET, $_SERVER['QUERY_STRING'] ?? '',
    rackline_capture_native_auth(), static fn(string $name): array => rackline_capture_binding($name, $paths), $paths['cache']);
http_response_code($response['status']);
if ($response['status'] === 405) header('Allow: GET');
if (isset($response['filename'])) header('Content-Disposition: inline; filename="' . $response['filename'] . '"');
echo $response['body'];
