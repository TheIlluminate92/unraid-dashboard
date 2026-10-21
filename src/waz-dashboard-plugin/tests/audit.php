<?php
declare(strict_types=1);
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$plugin = dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard';
$healthPath = dirname(__DIR__, 2) . '/waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/include/health.php';
require $healthPath;
// Run pure classification against controlled missing readings, without probing
// the test runner's sysfs or writing its throttle cache.
$source = file_get_contents($healthPath);
$start = strpos($source, 'function waz_health_cooling_status(');
$end = strpos($source, 'function waz_health_find_executable(', $start);
$function = substr($source, $start, $end-$start);
$function = str_replace('waz_health_cooling_status(', 'audit_cooling_status(', $function);
$function = str_replace('waz_health_cpu_temperature()', 'null', $function);
$function = preg_replace('/waz_health_hwmon_labeled_value\([^;]+\)/', 'null', $function);
$function = preg_replace('/waz_health_throttle_status\([^;]+\)/', "['active'=>false,'lastEventAt'=>null]", $function);
eval($function);
$result = audit_cooling_status(['COOLANT_REQUIRED'=>true,'FLOW_REQUIRED'=>true]);
check($result['state'] === 'unknown' && str_contains($result['message'], 'CPU, Coolant, Flow'), 'Missing required sensors must not be normal');
$worker = file_get_contents($plugin . '/include/gpu-sampler.php');
$mock = "register_shutdown_function(function(){echo http_response_code();});\n" . str_replace(['declare(strict_types=1);', 'PHP_SAPI'], ['', "'fpm-fcgi'"], substr($worker,5));
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($mock), $output, $code);
check(implode('', $output) === '404', 'HTTP worker access must exit before starting a sampler');
$workloads = file_get_contents($plugin . '/include/workloads.php');
check(!str_contains($workloads, "'/cmdline'"), 'Process argv must not enter the response');
echo "Dashboard worker, sensor and process privacy regressions passed.\n";
