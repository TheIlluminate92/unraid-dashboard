<?php
/* Synthetic, bounded stress only. No Docker, sockets, host paths or live inventory. */
declare(strict_types=1);
define('RACKLINE_WORKLOADS_NO_MAIN', true);
require dirname(__DIR__) . '/source/usr/local/emhttp/plugins/rackline.dashboard/include/workloads.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
$timings = [];
function stress_check(bool $condition, string $why): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($why);
}
function stress_rejects(callable $fn, string $why): void {
    try { $fn(); } catch (RuntimeException | InvalidArgumentException $error) { stress_check(true, $why); return; }
    stress_check(false, $why);
}
function stress_measure(string $name, callable $fn): void {
    global $timings;
    $start = hrtime(true);
    $fn();
    $elapsed = (hrtime(true) - $start) / 1e9;
    $timings[$name] = round($elapsed * 1000, 2);
    stress_check($elapsed < 10, $name . ' exceeds conservative offline execution bound');
}
function stress_row(int $index): array {
    return ['Id' => hash('sha256', 'synthetic-' . $index), 'Names' => ['/service-' . $index], 'State' => 'running',
        'Status' => 'Up', 'Image' => 'example.invalid/synthetic:latest', 'Labels' => []];
}
function stress_remove(string $root): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($root);
}
$root = sys_get_temp_dir() . '/rackline-workload-stress-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    $one = stress_row(1);
    $id = $one['Id'];
    $started = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
    $inspect = ['Id' => $id, 'Name' => '/service-1', 'State' => ['Running' => true, 'Paused' => false, 'Restarting' => false, 'StartedAt' => $started]];
    $raw = array_map('stress_row', range(1, 3000));
    stress_check(strlen(json_encode($raw)) < RACKLINE_CONTAINER_BYTES, 'Large synthetic inventory fits real transport body cap');
    stress_measure('3000 containers and indexed folders', static function () use ($raw): void {
        $inventory = rackline_workloads_inventory($raw);
        $containers = array_map(static fn(array $item): array => rackline_workloads_container_public($item, []), $inventory);
        $configured = [];
        foreach ($containers as $i => $container) $configured['group-' . $i] = ['name' => 'Group ' . $i, 'containers' => [strtoupper($container['name'])]];
        $partial = false;
        $folders = rackline_workloads_folders($containers, $configured, $partial);
        stress_check(count($folders) === 3001 && !$partial, 'Large indexed membership keeps all folders and exact inventory');
        stress_check(array_sum(array_column(array_slice($folders, 1), 'total')) === 3000, 'Every container assigned exactly once');
    });
    foreach (['0', '123', '-12', '9223372036854775807', '00123'] as $label) {
        $folders = rackline_workloads_folders([['name' => '0', 'state' => 'running', 'folderLabel' => $label]], []);
        stress_check($folders[1]['name'] === $label && $folders[1]['running'] === 1 && $folders[1]['containers'] === ['0'], 'Numeric labels/names do not become fatal integer keys');
    }
    stress_measure('4096 regex operations plus pathological patterns', static function () use ($raw): void {
        $containers = array_map(static fn(array $item): array => rackline_workloads_container_public($item, []), rackline_workloads_inventory($raw));
        $configured = array_fill(0, 40, ['name' => 'No match', 'regex' => '^never-']);
        $partial = false;
        $folders = rackline_workloads_folders($containers, $configured, $partial);
        stress_check($partial && count($folders[0]['containers']) === 3000 && end($folders)['total'] === 3000, 'Regex budget preserves all inventory and reports partial grouping');
        foreach (['^(a+)+$', '(*NO_JIT)^(a|aa)+$', '(*LIMIT_MATCH=999999999)^(a+)+$', '[', str_repeat('a', 513)] as $regex) {
            $partial = false;
            $folders = rackline_workloads_folders([['name' => str_repeat('a', 240) . 'b', 'state' => 'running']], [['name' => 'Stress', 'regex' => $regex]], $partial);
            stress_check($partial && $folders[1]['containers'] === [], 'Pathological/invalid regex cannot monopolize grouping or fabricate matches');
        }
        $partial = false;
        $folders = rackline_workloads_folders([['name' => 'service-01', 'state' => 'running']], [['name' => 'Normal', 'regex' => '^service-[0-9]+$']], $partial);
        stress_check(!$partial && $folders[1]['total'] === 1, 'Normal FolderView regex matching survives hardening');
    });
    stress_measure('12000-level Organizer graph and cycles', static function (): void {
        $entries = ['root' => ['type' => 'folder', 'children' => ['f0']]];
        for ($i = 0; $i < 12000; $i++) $entries['f' . $i] = ['type' => 'folder', 'name' => 'Deep', 'children' => [$i === 11999 ? 'ref' : 'f' . ($i + 1)]];
        $entries['f11999']['children'][] = 'f0';
        $entries['ref'] = ['type' => 'ref', 'target' => 'resource'];
        $organizer = ['resources' => ['resource' => ['meta' => ['names' => ['/service-1']]]], 'views' => ['default' => ['root' => 'root', 'entries' => $entries]]];
        stress_check(strlen(json_encode($organizer)) < RACKLINE_WORKLOADS_BYTES, 'Deep graph fits real metadata input cap');
        $partial = false;
        $folders = rackline_workloads_native_configuration($organizer, $partial);
        stress_check(!$partial && $folders['f0']['containers'] === ['service-1'], 'Iterative traversal preserves deep membership and terminates cycles');
        $entries = ['root' => ['type' => 'folder', 'children' => []], 'shared' => ['type' => 'folder', 'children' => []]];
        for ($i = 0; $i < 500; $i++) {
            $entries['root']['children'][] = 'f' . $i;
            $entries['f' . $i] = ['type' => 'folder', 'name' => 'Group ' . $i, 'children' => ['shared']];
            $entries['shared']['children'][] = 'r' . $i;
            $entries['r' . $i] = ['type' => 'ref', 'target' => 'resource'];
        }
        $organizer['views']['default']['entries'] = $entries;
        $partial = false;
        $folders = rackline_workloads_native_configuration($organizer, $partial);
        stress_check($partial && count($folders) < 500, 'Shared graph traversal has one aggregate budget, not a budget per folder');
    });
    stress_measure('malformed nested Docker values', static function () use ($one, $inspect): void {
        $badValues = [null, false, true, -1, 0, 1.5, 'unexpected', [], ['unexpected'], ['nested' => []]];
        foreach ($badValues as $value) {
            foreach (['cpu_stats', 'memory_stats', 'networks', 'blkio_stats'] as $field) {
                $stats = rackline_container_stats([$field => $value], 1000, 1);
                stress_check(!$stats['available'], 'Malformed stats roots stay unavailable without warnings');
            }
            foreach (['cpu_usage', 'system_cpu_usage', 'online_cpus'] as $field) {
                stress_check(!rackline_container_stats(['cpu_stats' => [$field => $value]], 1000, 1)['available'], 'Malformed CPU descendants remain unavailable');
            }
            foreach (['Labels', 'Image', 'ImageID', 'Status', 'Ports', 'HostConfig'] as $field) {
                $item = rackline_workloads_inventory([array_replace($one, [$field => $value])])[0];
                $public = rackline_workloads_container_public($item, ['icon' => $value, 'url' => $value, 'shell' => $value]);
                stress_check(is_array($public) && is_string($public['name']), 'Malformed optional inventory/cache fields do not emit warnings');
            }
            stress_rejects(static fn() => rackline_workloads_inventory([array_replace($one, ['State' => $value])]), 'Malformed state cannot become stopped or enable native Start');
            $selected = rackline_workloads_inventory([$one])[0];
            $selected['public'] = rackline_workloads_container_public($selected, []);
            foreach (['NetworkSettings', 'Mounts', 'RestartCount'] as $field) stress_check(rackline_workloads_selected($selected, array_replace($inspect, [$field => $value])) !== null, 'Malformed optional inspect metadata degrades without warnings');
        }
        stress_check(rackline_container_stats(['cpu_stats' => ['cpu_usage' => ['total_usage' => 1], 'system_cpu_usage' => 10, 'online_cpus' => 1.5]], 1000, 1)['cpuCounters'] === null, 'CPU counts must be integral');
        foreach ([NAN, INF, -INF, -1, '100', true, []] as $value) stress_check(rackline_container_number($value) === null, 'Non-finite/non-numeric counters rejected');
        $stats = rackline_container_stats(['networks' => ['a' => ['rx_bytes' => PHP_FLOAT_MAX, 'tx_bytes' => 0], 'b' => ['rx_bytes' => PHP_FLOAT_MAX, 'tx_bytes' => 0]]], 1000, 1);
        stress_check($stats['networkRxBytes'] === null, 'Overflowing aggregate counters remain unknown');
    });
    foreach ([[$one, $one], [$one, array_replace(stress_row(2), ['Names' => $one['Names']])], ['object' => $one], [['message' => 'secret failure']], [array_replace($one, ['Names' => 'x'])], [array_replace($one, ['Names' => ['/' . str_repeat('a', 256)]])]] as $bad) {
        stress_rejects(static fn() => rackline_workloads_inventory($bad), 'Inventory rejects malformed/duplicate identities');
        stress_rejects(static fn() => rackline_container_batch([$one['Id']], static fn() => ['inventory' => ['status' => 'ok', 'data' => $bad]]), 'Batch uses same strict identity validation');
    }
    foreach ([array_fill(0, 13, $id), ['../invalid'], [17], [str_repeat('a', 65)]] as $badIds) stress_rejects(static fn() => rackline_container_batch($badIds, static function (): void { throw new LogicException('Invalid IDs must not call transport'); }), 'Batch itself enforces request bounds');
    foreach (['2026-02-31T00:00:00Z', '2026-11-07T24:00:00Z', '2026-11-07T12:60:00Z', '2026-11-07T12:00:60Z', '2026-11-07T12:00:00+99:99', '0001-01-01T00:00:00Z', 'tomorrow', '', []] as $date) stress_check(rackline_container_timestamp($date) === null, 'Invalid/normalized/relative restart date rejected');
    stress_check(rackline_container_timestamp('2024-02-29T23:59:59.123456789+02:00') !== null, 'Valid leap date, nanoseconds and timezone remain supported');
    $runBatch = static function (array $details, array $sample, ?array &$calls = null) use ($one, $id): array {
        $calls = []; $deadlineSeen = null;
        return rackline_container_batch([$id], static function (array $paths, float $deadline) use ($one, $details, $sample, &$calls, &$deadlineSeen): array {
            $calls[] = $paths;
            $deadlineSeen ??= $deadline;
            stress_check($deadline === $deadlineSeen, 'List/inspect/stats share one deadline');
            if (isset($paths['inventory'])) return ['inventory' => ['status' => 'ok', 'data' => [$one]]];
            $key = array_key_first($paths);
            return [$key => str_ends_with(reset($paths), '/json') ? ['status' => 'ok', 'data' => $details] : $sample];
        });
    };
    foreach ([['message' => 'secret error'], ['error' => 'secret error'], ['Name' => '/changed'], ['State' => ['StartedAt' => '2026-02-31T00:00:00Z']], ['State' => ['StartedAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]]] as $change) {
        $batch = $runBatch(array_replace_recursive($inspect, $change), ['status' => 'ok', 'data' => ['memory_stats' => ['usage' => 1]]], $calls);
        stress_check(count($calls) === 2 && $batch['partial'] && $batch['containers'][0]['generation'] === null, 'Invalid inspect blocks stats and clears generation');
    }
    foreach (['timeout', 'oversized', 'unavailable', 'deferred'] as $status) {
        $batch = $runBatch($inspect, ['status' => $status, 'data' => null]);
        stress_check($batch['partial'] && $batch['containers'][0]['status'] === $status && !$batch['containers'][0]['stats']['available'], 'Sample transport failures remain visible and reset baseline');
    }
    foreach ([['status' => 'ok', 'data' => null], ['status' => 'ok', 'data' => ['name' => '/renamed', 'memory_stats' => ['usage' => 1]]], ['status' => 'ok', 'data' => ['id' => stress_row(2)['Id'], 'memory_stats' => ['usage' => 1]]]] as $sample) {
        $batch = $runBatch($inspect, $sample);
        stress_check($batch['partial'] && $batch['sampledCount'] === 0 && $batch['containers'][0]['generation'] === null, 'Malformed/misidentified successful sample is not a false success');
    }
    foreach ([time() - 3600, time() - 30, time() + 60] as $read) {
        $batch = $runBatch($inspect, ['status' => 'ok', 'data' => ['read' => gmdate('Y-m-d\TH:i:s\Z', $read), 'memory_stats' => ['usage' => 1]]]);
        stress_check($batch['partial'] && $batch['sampledCount'] === 0, 'Pre-restart/stale/future daemon read cannot acquire a fresh receipt timestamp');
    }
    $read = time() - 3;
    $batch = $runBatch($inspect, ['status' => 'ok', 'data' => ['read' => gmdate('Y-m-d\TH:i:s\Z', $read), 'memory_stats' => ['usage' => 1]]]);
    stress_check($batch['containers'][0]['stats']['sampledAtMs'] === (float)($read * 1000) && $batch['containers'][0]['stats']['timestampSource'] === 'daemon', 'Actual daemon timestamp retained');
    $batch = $runBatch($inspect, ['status' => 'ok', 'data' => ['memory_stats' => ['usage' => 1]]]);
    stress_check($batch['containers'][0]['stats']['timestampSource'] === 'received', 'Missing optional daemon read explicitly identifies receipt timing');
    stress_measure('3000 inventory, maximum 25 mocked GETs', static function () use ($raw, $started): void {
        $requested = array_column(array_slice($raw, 0, 12), 'Id');
        $names = array_column($raw, 'Names', 'Id'); $calls = 0;
        $batch = rackline_container_batch($requested, static function (array $paths) use ($raw, $started, $names, &$calls): array {
            $calls += count($paths);
            stress_check(count($paths) <= 12, 'Batch cannot grow beyond 12 targets');
            if (isset($paths['inventory'])) return ['inventory' => ['status' => 'ok', 'data' => $raw]];
            $out = [];
            foreach ($paths as $key => $path) {
                stress_check(rackline_container_path($path), 'Every mocked operation stays on exact GET allowlist');
                $out[$key] = ['status' => 'ok', 'data' => str_ends_with($path, '/json') ? ['Id' => $key, 'Name' => $names[$key][0], 'State' => ['Running' => true, 'Paused' => false, 'Restarting' => false, 'StartedAt' => $started]] : ['id' => $key, 'memory_stats' => ['usage' => 0]]];
            }
            return $out;
        });
        stress_check($calls === 25 && $batch['sampledCount'] === 12 && !$batch['partial'], 'Maximum batch does exactly one list, 12 inspect, 12 stats');
    });
    foreach (['javascript:alert(1)', '//example.invalid/', "https://example.invalid/'onclick='x", 'https://example.invalid/"x', 'https://example.invalid/<svg>', 'https://example.invalid/&#x27;x', "https://example.invalid/\\x", "https://example.invalid/\x7f", 'https://user:synthetic-secret@example.invalid/', 'https://', 'https://[IP]:[PORT:80]', 'https://' . str_repeat('a', 8193)] as $url) stress_check(rackline_workloads_url($url) === '', 'Unsafe/credential-bearing native URL not passed to Docker menu');
    foreach (['https://example.invalid/?a=1&amp;b=2', 'http://[2001:db8::1]:8080/', '/Docker', 'https://example.invalid/a%27b'] as $url) stress_check(rackline_workloads_url($url) !== '', 'Safe resolved links remain supported');
    foreach (['sh', 'bash', 'ash', 'zsh', '/bin/sh', '/bin/bash', '/bin/ash', '/bin/zsh'] as $shell) stress_check(rackline_workloads_shell($shell) === $shell, 'Common shell token supported');
    foreach (["sh';alert(1)//", 'bash -c x', "sh\n", [], '/tmp/custom-shell'] as $shell) stress_check(rackline_workloads_shell($shell) === '', 'Arbitrary shell/markup not forwarded');
    $metadataRoot = $root . '/metadata';
    mkdir($metadataRoot . '/state/plugins/dynamix.docker.manager', 0700, true);
    $templates = $root . '/templates'; mkdir($templates);
    $template = $templates . '/my service+50%.xml';
    $xml = '<Container><Name>service-1</Name><Repository>example.invalid/synthetic:latest</Repository><Shell>sh</Shell></Container>';
    file_put_contents($template, $xml);
    file_put_contents($metadataRoot . '/state/plugins/dynamix.docker.manager/docker.json', json_encode(['service-1' => ['private' => 'SYNTHETIC_SECRET', 'template' => '/do-not-trust.xml', 'url' => 'https://user:SYNTHETIC_SECRET@example.invalid/']]));
    $owned = rackline_workloads_inventory([array_replace($one, ['Labels' => ['net.unraid.docker.managed' => 'dockerman']])]);
    $getMetadata = static fn(string $path = ''): array => rackline_workloads_metadata($owned, $metadataRoot, $path === '' ? $templates : $path, $root . '/missing-autostart');
    stress_check($getMetadata()['info']['service-1']['template'] === $template, 'Unique direct-child template and literal plus/percent path retained');
    foreach (['<Container><Config><Name>service-1</Name></Config><Name>other-owner</Name></Container>', '<Container><Name>service-1</Name><Name>other-owner</Name></Container>'] as $badXml) {
        file_put_contents($templates . '/ambiguous.xml', $badXml);
        $metadata = $getMetadata();
        if (str_contains($badXml, '<Config>')) stress_check($metadata['info']['service-1']['template'] === $template, 'Nested XML names cannot claim another container');
        else stress_check($metadata['status']['templatesPartial'] && $metadata['info']['service-1']['template'] === '', 'Duplicate root identity fields suppress unsafe editor');
    }
    unlink($templates . '/ambiguous.xml');
    foreach (['<Container>', str_repeat('x', RACKLINE_WORKLOADS_TEMPLATE_BYTES + 1), '<!DOCTYPE Container [<!ENTITY bad SYSTEM "file:///not-read">]><Container><Name>&bad;</Name></Container>'] as $badXml) {
        file_put_contents($templates . '/partial.xml', $badXml);
        $metadata = $getMetadata();
        stress_check($metadata['status']['templatesPartial'] && $metadata['info']['service-1']['template'] === '', 'Malformed/oversized/entity XML cannot hide a duplicate editor target');
    }
    unlink($templates . '/partial.xml');
    file_put_contents($root . '/outside.xml', $xml);
    symlink($root . '/outside.xml', $templates . '/escape.xml');
    stress_check($getMetadata()['status']['templatesPartial'] && $getMetadata()['info']['service-1']['template'] === '', 'Escaping template symlink cannot establish unique ownership');
    unlink($templates . '/escape.xml');
    mkdir($root . '/external-directory'); symlink($root . '/external-directory', $templates . '/linked-directory');
    stress_check($getMetadata()['status']['templatesPartial'], 'Unscanned linked directory is explicit partial metadata');
    unlink($templates . '/linked-directory');
    stress_measure('template file/count/aggregate/depth bounds', static function () use ($root, $getMetadata): void {
        $directory = $root . '/count'; mkdir($directory);
        for ($i = 0; $i < 257; $i++) file_put_contents($directory . '/' . $i . '.xml', '<Container><Name>service-' . ($i + 1) . '</Name></Container>');
        stress_check($getMetadata($directory)['status']['templatesPartial'] && $getMetadata($directory)['info']['service-1']['template'] === '', '257 templates exceed scan count without guessing editor');
        $directory = $root . '/aggregate'; mkdir($directory);
        for ($i = 0; $i < 33; $i++) {
            $xml = '<Container><Name>service-' . ($i + 1) . '</Name></Container>';
            file_put_contents($directory . '/' . $i . '.xml', $xml . str_repeat(' ', RACKLINE_WORKLOADS_TEMPLATE_BYTES - strlen($xml)));
        }
        stress_check($getMetadata($directory)['status']['templatesPartial'], 'Aggregate template bytes remain capped at 8 MiB');
        $directory = $root . '/depth'; mkdir($directory); $nested = $directory;
        for ($i = 0; $i < 10; $i++) { $nested .= '/d'; mkdir($nested); }
        file_put_contents($nested . '/deep.xml', '<Container><Name>service-1</Name></Container>');
        stress_check($getMetadata($directory)['status']['templatesPartial'], 'Directory depth cap is explicit partial metadata');
        $directory = $root . '/entries'; mkdir($directory);
        for ($i = 0; $i < 1025; $i++) file_put_contents($directory . '/' . $i . '.txt', '');
        stress_check($getMetadata($directory)['status']['templatesPartial'], 'Non-XML directory entries cannot bypass scan bound');
    });
    $owner = rackline_workloads_inventory([array_replace($one, ['Labels' => ['net.unraid.docker.managed' => 'composeman']])]);
    $metadata = rackline_workloads_metadata($owner, $metadataRoot, $templates, $root . '/missing-autostart');
    stress_check($metadata['info']['service-1']['template'] === '' && $metadata['info']['service-1']['autostart'] === null, 'Stale native cache/template cannot adopt another manager or invent autostart');
    $secretRow = $owned[0]['raw']; $secretRow['Command'] = 'SYNTHETIC_SECRET'; $secretRow['Labels']['unrelated'] = 'SYNTHETIC_SECRET';
    $secretInspect = $inspect + ['Config' => ['Env' => ['SYNTHETIC_SECRET']], 'Args' => ['SYNTHETIC_SECRET']];
    $response = rackline_workloads_collect('service-1', static fn(array $paths): array => isset($paths['inventory']) ? ['inventory' => ['status' => 'ok', 'data' => [$secretRow]]] : ['selected' => ['status' => 'ok', 'data' => $secretInspect]], static fn(): array => $getMetadata(), ['folders' => [], 'partial' => true]);
    stress_check(!str_contains(json_encode($response), 'SYNTHETIC_SECRET') && $response['nativeMetadata']['foldersPartial'], 'Secrets remain excluded and partial grouping propagates visibly');
    file_put_contents($metadataRoot . '/state/plugins/dynamix.docker.manager/docker.json', str_repeat('x', RACKLINE_WORKLOADS_BYTES + 1));
    stress_check(!$getMetadata()['status']['cacheAvailable'], 'Oversized stale cache remains unavailable, without blocking verified templates');
    stress_check(hash_file('sha256', $template) === hash('sha256', $xml), 'Collector leaves native template fixture untouched');
    stress_check(memory_get_peak_usage(true) < 128 * 1024 * 1024, 'Synthetic suite stays below 128 MiB peak allocation');
    echo json_encode(['checks' => $checks, 'milliseconds' => $timings, 'peakMiB' => round(memory_get_peak_usage(true) / 1048576, 2)], JSON_UNESCAPED_SLASHES) . "\n";
} finally {
    stress_remove($root);
}
