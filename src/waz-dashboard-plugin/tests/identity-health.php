<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/include/health.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
foreach (['', 'auto', 'WAZ-SERVER'] as $name) {
    check(waz_health_display_name(['DISPLAY_NAME' => $name], 'fixture-host') === 'fixture-host', 'Default name must follow host');
}
check(waz_health_display_name(['DISPLAY_NAME' => 'Custom label'], 'fixture-host') === 'Custom label', 'Preserve custom label');
check(waz_health_display_name([], '') === 'SERVER', 'Missing hostname fallback');
$source = file_get_contents(dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/workloads.php');
$start = strpos($source, 'function waz_workloads_current_health(');
$end = strpos($source, 'function waz_workloads_container_public(', $start);
eval(substr($source, $start, $end - $start));
foreach (['stopped', 'paused'] as $state) {
    foreach (['healthy', 'unhealthy', 'starting', 'none', ''] as $health) {
        check(waz_workloads_current_health($state, $health) === 'none', 'Non-running health is historic');
    }
}
foreach (['healthy', 'unhealthy', 'starting'] as $health) {
    check(waz_workloads_current_health('running', $health) === $health, 'Retain live health');
}
check(waz_workloads_current_health('running', '') === 'none', 'No healthcheck');
echo "Host identity and container lifecycle health regressions passed.\n";
