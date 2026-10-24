<?php
declare(strict_types=1);
$source = file_get_contents(dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/workloads.php');
foreach ([['function waz_workloads_number(', 'function waz_workloads_filesystem_usage('], ['function waz_workloads_stats(', 'function waz_workloads_pool_label('], ['function waz_workloads_current_health(', 'function waz_workloads_container_public('], ['function waz_workloads_active_stats(', '$response = [];']] as [$from, $to]) {
    $start = strpos($source, $from); $end = strpos($source, $to, $start);
    eval(substr($source, $start, $end - $start));
}
function check(bool $condition, string $why): void { if (!$condition) throw new RuntimeException($why); }
class FixtureClient {
    public array $calls = [];
    public array $state = ['Running' => true];
    public function getContainerDetails(string $name): array { $this->calls[] = ['inspect', $name]; return ['Id' => 'fixture-id', 'Name' => '/alpha', 'State' => $this->state]; }
    public function getDockerJSON(string $url): array { $this->calls[] = ['stats', $url]; return ['cpu_stats' => ['cpu_usage' => ['total_usage' => 42], 'system_cpu_usage' => 100, 'online_cpus' => 4]]; }
}
$client = new FixtureClient();
$result = waz_workloads_active_stats($client, 'alpha');
check(count($client->calls) === 2 && $client->calls[0][0] === 'inspect', 'State must be checked before stats');
check($client->calls[1][1] === '/containers/fixture-id/stats?stream=false&one-shot=true', 'One selected container, one nonblocking sample');
check($result['selected']['stats']['cpuPercent'] === null, 'No lifetime-average CPU from an initial one-shot sample');
check($result['selected']['stats']['cpuCounters']['usage'] === 42.0, 'Expose counters for consecutive-sample CPU');
foreach ([['Running' => false], ['Running' => true, 'Paused' => true], ['Running' => true, 'Restarting' => true]] as $state) {
    $client = new FixtureClient(); $client->state = $state;
    $result = waz_workloads_active_stats($client, 'alpha');
    check(count($client->calls) === 1, 'Inactive container must never receive stats');
    check(!$result['selected']['stats']['available'], 'Inactive stats remain unavailable');
}
foreach (['', '../alpha', 'alpha?stream=true'] as $name) {
    $client = new FixtureClient();
    check(waz_workloads_active_stats($client, $name)['selected'] === null && !$client->calls, 'Reject invalid selections without Docker calls');
}
echo "Selected active Docker stats regressions passed.\n";
