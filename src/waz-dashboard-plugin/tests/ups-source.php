<?php
declare(strict_types=1);
$source = file_get_contents(dirname(__DIR__, 2) . '/waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/include/health.php');
$start = strpos($source, 'function waz_health_read_ups_data(');
$end = strpos($source, 'function waz_health_ups_status(', $start);
$function = str_replace(['waz_health_read_ups_data', 'waz_health_read_config', 'waz_health_find_executable', 'waz_health_run_command'], ['fixture_read', 'fixture_config', 'fixture_binary', 'fixture_command'], substr($source, $start, $end - $start));
eval($function);
$target = ''; $calls = []; $fail = false; $nut = false;
function fixture_config(): array { global $target; return ['UPS_APC_TARGET' => $target]; }
function fixture_binary(array $paths): ?string { global $nut; return str_contains($paths[0], 'upsc') ? ($nut ? '/usr/bin/upsc' : null) : '/sbin/apcaccess'; }
function fixture_command(string $command): ?array {
    global $calls, $fail, $nut;
    $calls[] = $command;
    if ($fail) return null;
    if (str_contains($command, 'upsc')) return str_contains($command, ' -l') ? ['fixture'] : ['ups.status: OL', 'ups.load: 30'];
    return ['STATUS : ONLINE', 'LOADPCT : 30', 'NOMPOWER : 900'];
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
check(fixture_read()['STATUS'] === 'ONLINE', 'Local APC feed');
$target = 'ups.example:3551'; $calls = []; $nut = true;
check(fixture_read()['STATUS'] === 'ONLINE', 'Remote feed');
check(count($calls) === 1 && str_contains($calls[0], "'ups.example:3551'"), 'Remote override must bypass local NUT');
$fail = true;
check(fixture_read()['STATUS'] === 'COMMLOST', 'Configured remote failure remains visible');
$target = ''; $nut = false;
check(fixture_read() === null, 'Absent local source');
$target = 'host;touch /tmp/should-never-run'; $calls = [];
check(fixture_read()['STATUS'] === 'COMMLOST' && !$calls, 'Reject invalid target before commands');
$target = ''; $nut = true; $fail = false;
check(fixture_read()['ups.status'] === 'OL', 'Retain NUT auto discovery');
echo "Local, network, absent and invalid UPS source regressions passed.\n";
