<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const WAZ_WORKLOADS_VERSION = '@@APP_VERSION@@';
const WAZ_WORKLOADS_PLUGIN_VERSION = '@@PLUGIN_VERSION@@';
const WAZ_WORKLOADS_FOLDER_CONFIGS = [
    '/boot/config/plugins/folderview.plus/docker.json',
    '/boot/config/plugins/folder.view3/docker.json',
    '/boot/config/plugins/folder.view2/docker.json',
    '/boot/config/plugins/folder.view/docker.json',
    '/boot/config/plugins/dynamix.my.servers/configs/docker.organizer.json',
];
const WAZ_WORKLOADS_GPU_STATE = '/var/run/waz.dashboard/gpu.json';
const WAZ_WORKLOADS_PROCESS_STATE = '/var/run/waz.dashboard/process-samples.json';
const WAZ_WORKLOADS_FALLBACK_ICON = '/plugins/dynamix.docker.manager/images/question.png';
const WAZ_WORKLOADS_CLOCK_TICKS = 100.0;

ob_start();

function waz_workloads_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $contents = (string) @file_get_contents($path);
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function waz_workloads_number($value): ?float
{
    return is_numeric($value) ? (float) $value : null;
}

function waz_workloads_filesystem_usage(string $path): array
{
    if (!is_dir($path)) {
        return ['available' => false, 'path' => $path];
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false || $total <= 0) {
        return ['available' => false, 'path' => $path];
    }
    $used = max(0.0, (float) $total - (float) $free);
    return [
        'available' => true,
        'path' => $path,
        'totalBytes' => (float) $total,
        'usedBytes' => $used,
        'freeBytes' => (float) $free,
        'usagePercent' => $used * 100.0 / (float) $total,
    ];
}

function waz_workloads_color($value, string $fallback): string
{
    $value = trim((string) $value);
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
}

function waz_workloads_container_labels(string $shortId): array
{
    if (!preg_match('/^[a-f0-9]{12}$/i', $shortId)) {
        return [];
    }
    $matches = glob('/var/lib/docker/containers/' . strtolower($shortId) . '*/config.v2.json') ?: [];
    if (!$matches) {
        return [];
    }
    $config = waz_workloads_json_file($matches[0]);
    $labels = $config['Config']['Labels'] ?? [];
    return is_array($labels) ? $labels : [];
}

function waz_workloads_folder_color(array $folder, int $index): string
{
    $palette = ['#22b8f0', '#a78bfa', '#2dd4bf', '#60a5fa', '#e879f9', '#34d399', '#f2a900', '#f97316'];
    $settings = is_array($folder['settings'] ?? null) ? $folder['settings'] : [];
    $candidate = $settings['preview_vertical_bars_color'] ?? $settings['preview_border_color'] ?? '';
    return waz_workloads_color($candidate, $palette[$index % count($palette)]);
}

function waz_workloads_native_member_names(string $entryId, array $entries, array $resources, array &$visited): array
{
    if ($entryId === '' || isset($visited[$entryId]) || !isset($entries[$entryId]) || !is_array($entries[$entryId])) {
        return [];
    }
    $visited[$entryId] = true;
    $entry = $entries[$entryId];
    if (($entry['type'] ?? '') === 'folder') {
        $names = [];
        foreach ((array) ($entry['children'] ?? []) as $childId) {
            $names = array_merge($names, waz_workloads_native_member_names((string) $childId, $entries, $resources, $visited));
        }
        return $names;
    }
    if (($entry['type'] ?? '') !== 'ref') {
        return [];
    }
    $resource = $resources[(string) ($entry['target'] ?? '')] ?? null;
    if (!is_array($resource)) {
        return [];
    }
    $metaNames = is_array($resource['meta']['names'] ?? null) ? $resource['meta']['names'] : [];
    $name = trim((string) ($metaNames[0] ?? $resource['name'] ?? ''), " /\t\n\r\0\x0B");
    return $name === '' ? [] : [$name];
}

function waz_workloads_native_configuration(array $organizer): array
{
    $views = is_array($organizer['views'] ?? null) ? $organizer['views'] : [];
    $view = is_array($views['default'] ?? null) ? $views['default'] : null;
    if ($view === null) {
        foreach ($views as $candidate) {
            if (is_array($candidate)) {
                $view = $candidate;
                break;
            }
        }
    }
    if ($view === null) {
        return [];
    }
    $entries = is_array($view['entries'] ?? null) ? $view['entries'] : [];
    $resources = is_array($organizer['resources'] ?? null) ? $organizer['resources'] : [];
    $rootId = (string) ($view['root'] ?? 'root');
    $root = is_array($entries[$rootId] ?? null) ? $entries[$rootId] : null;
    if ($root === null || ($root['type'] ?? '') !== 'folder') {
        return [];
    }
    $folders = [];
    foreach ((array) ($root['children'] ?? []) as $childId) {
        $childId = (string) $childId;
        $folder = is_array($entries[$childId] ?? null) ? $entries[$childId] : null;
        if ($folder === null || ($folder['type'] ?? '') !== 'folder') {
            continue;
        }
        $visited = [];
        $folders[$childId] = [
            'name' => trim((string) ($folder['name'] ?? '')),
            'containers' => array_values(array_unique(waz_workloads_native_member_names($childId, $entries, $resources, $visited))),
        ];
    }
    return array_filter($folders, static fn(array $folder): bool => $folder['name'] !== '');
}

function waz_workloads_folder_configuration(): array
{
    $candidates = WAZ_WORKLOADS_FOLDER_CONFIGS;
    foreach (glob('/boot/config/plugins/folder.view*/docker.json') ?: [] as $candidate) {
        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }
    foreach ($candidates as $path) {
        $folders = waz_workloads_json_file($path);
        if (isset($folders['version'], $folders['resources'], $folders['views'])) {
            $folders = waz_workloads_native_configuration($folders);
        }
        if ($folders !== []) {
            $ordered = [];
            $preferences = (array) @parse_ini_file('/boot/config/plugins/dockerMan/userprefs.cfg', false, INI_SCANNER_RAW);
            foreach (array_values($preferences) as $entry) {
                $entry = (string) $entry;
                if (!str_starts_with($entry, 'folder-')) {
                    continue;
                }
                $id = substr($entry, 7);
                if ($id !== '' && isset($folders[$id]) && is_array($folders[$id])) {
                    $ordered[$id] = $folders[$id];
                }
            }
            if ($ordered === []) {
                uasort($folders, static fn($left, $right): int => strnatcasecmp(
                    (string) (is_array($left) ? ($left['name'] ?? '') : ''),
                    (string) (is_array($right) ? ($right['name'] ?? '') : '')
                ));
            } else {
                foreach ($folders as $id => $folder) {
                    if (!isset($ordered[$id])) {
                        $ordered[$id] = $folder;
                    }
                }
                $folders = $ordered;
            }
            return ['path' => $path, 'folders' => $folders];
        }
    }
    return ['path' => null, 'folders' => []];
}

function waz_workloads_folders(array $containers, array $configured): array
{
    $assigned = [];
    $folders = [];
    $byName = [];
    foreach ($containers as $container) {
        $byName[strtolower((string) $container['name'])] = $container;
    }

    $allNames = array_map(static fn(array $container): string => (string) $container['name'], $containers);
    $folders[] = [
        'id' => 'all',
        'name' => 'All',
        'icon' => null,
        'color' => '#22b8f0',
        'containers' => $allNames,
    ];

    $index = 0;
    foreach ($configured as $folderId => $folder) {
        if (!is_array($folder)) {
            continue;
        }
        $name = trim((string) ($folder['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $members = [];
        foreach ((array) ($folder['containers'] ?? []) as $member) {
            $key = strtolower(trim((string) $member));
            if ($key !== '' && isset($byName[$key]) && !isset($assigned[$key])) {
                $members[] = (string) $byName[$key]['name'];
                $assigned[$key] = true;
            }
        }
        foreach ($containers as $container) {
            $key = strtolower((string) $container['name']);
            if (isset($assigned[$key])) {
                continue;
            }
            $label = trim((string) ($container['folderLabel'] ?? ''));
            if ($label !== '' && strcasecmp($label, $name) === 0) {
                $members[] = (string) $container['name'];
                $assigned[$key] = true;
            }
        }
        $regex = trim((string) ($folder['regex'] ?? ''));
        if ($regex !== '') {
            $pattern = '/' . str_replace('/', '\\/', $regex) . '/i';
            foreach ($containers as $container) {
                $key = strtolower((string) $container['name']);
                if (!isset($assigned[$key]) && @preg_match($pattern, (string) $container['name']) === 1) {
                    $members[] = (string) $container['name'];
                    $assigned[$key] = true;
                }
            }
        }
        $folders[] = [
            'id' => 'folder-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $folderId),
            'name' => $name,
            'icon' => trim((string) ($folder['icon'] ?? '')) ?: null,
            'color' => waz_workloads_folder_color($folder, $index++),
            'containers' => $members,
        ];
    }

    $labelGroups = [];
    foreach ($containers as $container) {
        $key = strtolower((string) $container['name']);
        $label = trim((string) ($container['folderLabel'] ?? ''));
        if (!isset($assigned[$key]) && $label !== '') {
            $labelGroups[$label][] = (string) $container['name'];
            $assigned[$key] = true;
        }
    }
    foreach ($labelGroups as $label => $members) {
        $folders[] = [
            'id' => 'label-' . substr(sha1($label), 0, 12),
            'name' => $label,
            'icon' => null,
            'color' => waz_workloads_folder_color([], $index++),
            'containers' => $members,
        ];
    }

    $unassigned = [];
    foreach ($containers as $container) {
        if (!isset($assigned[strtolower((string) $container['name'])])) {
            $unassigned[] = (string) $container['name'];
        }
    }
    if ($unassigned && count($folders) > 1) {
        $folders[] = [
            'id' => 'unassigned',
            'name' => 'Ungrouped',
            'icon' => null,
            'color' => '#87939f',
            'containers' => $unassigned,
        ];
    }

    $stateByName = [];
    foreach ($containers as $container) {
        $stateByName[(string) $container['name']] = (string) $container['state'];
    }
    foreach ($folders as &$folder) {
        $folder['total'] = count($folder['containers']);
        $folder['running'] = count(array_filter(
            $folder['containers'],
            static fn(string $name): bool => ($stateByName[$name] ?? '') === 'running'
        ));
    }
    unset($folder);
    return $folders;
}

function waz_workloads_process_container(int $pid, array $containerIds): ?string
{
    $cgroup = @file_get_contents('/proc/' . $pid . '/cgroup');
    if ($cgroup === false) {
        return null;
    }
    if (!preg_match('/(?:docker[-\/]|\/)([a-f0-9]{12,64})(?:\.scope|\/|$)/i', $cgroup, $match)) {
        return null;
    }
    $candidate = strtolower($match[1]);
    foreach ($containerIds as $id => $name) {
        if (str_starts_with($candidate, $id) || str_starts_with($id, $candidate)) {
            return (string) $name;
        }
    }
    return null;
}

function waz_workloads_memory_total(): float
{
    $contents = (string) @file_get_contents('/proc/meminfo');
    if (preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $contents, $match)) {
        return (float) $match[1] * 1024.0;
    }
    return 0.0;
}

function waz_workloads_process_snapshot(array $containerIds): array
{
    $previous = waz_workloads_json_file(WAZ_WORKLOADS_PROCESS_STATE);
    $previousAt = waz_workloads_number($previous['sampledAt'] ?? null);
    $previousItems = is_array($previous['items'] ?? null) ? $previous['items'] : [];
    $sampledAt = microtime(true);
    $elapsed = $previousAt !== null ? $sampledAt - $previousAt : null;
    $uptimeParts = preg_split('/\s+/', trim((string) @file_get_contents('/proc/uptime')));
    $uptime = waz_workloads_number($uptimeParts[0] ?? null) ?? 0.0;
    $memoryTotal = waz_workloads_memory_total();
    $currentItems = [];
    $processes = [];

    foreach (glob('/proc/[0-9]*/stat') ?: [] as $statPath) {
        $pid = (int) basename(dirname($statPath));
        $raw = (string) @file_get_contents($statPath);
        $end = strrpos($raw, ')');
        if ($pid <= 0 || $end === false) {
            continue;
        }
        $commStart = strpos($raw, '(');
        $comm = $commStart === false ? 'unknown' : substr($raw, $commStart + 1, $end - $commStart - 1);
        $fields = preg_split('/\s+/', trim(substr($raw, $end + 1)));
        if (count($fields) < 20) {
            continue;
        }
        $ticks = (float) ($fields[11] ?? 0) + (float) ($fields[12] ?? 0);
        $startTicks = (float) ($fields[19] ?? 0);
        $key = (string) $pid;
        $currentItems[$key] = ['ticks' => $ticks, 'start' => $startTicks];
        $cpu = 0.0;
        $before = is_array($previousItems[$key] ?? null) ? $previousItems[$key] : null;
        if ($before && $elapsed !== null && $elapsed >= 0.35 && $elapsed <= 30.0 && (float) ($before['start'] ?? -1) === $startTicks) {
            $cpu = max(0.0, ($ticks - (float) ($before['ticks'] ?? $ticks)) / WAZ_WORKLOADS_CLOCK_TICKS / $elapsed * 100.0);
        } else {
            $lifetime = $uptime - ($startTicks / WAZ_WORKLOADS_CLOCK_TICKS);
            if ($lifetime > 0.0) {
                $cpu = max(0.0, ($ticks / WAZ_WORKLOADS_CLOCK_TICKS) / $lifetime * 100.0);
            }
        }
        $status = (string) @file_get_contents('/proc/' . $pid . '/status');
        $rssBytes = preg_match('/^VmRSS:\s+(\d+)\s+kB/im', $status, $rss) ? (float) $rss[1] * 1024.0 : 0.0;
        $container = waz_workloads_process_container($pid, $containerIds);
        $processes[] = [
            'pid' => $pid,
            'name' => $comm,
            // Full argv can contain credentials. Never put it in API responses.
            'command' => $comm,
            'cpuPercent' => round($cpu, 1),
            'memoryBytes' => $rssBytes,
            'memoryPercent' => $memoryTotal > 0 ? round($rssBytes * 100.0 / $memoryTotal, 2) : null,
            'container' => $container,
        ];
    }

    @mkdir(dirname(WAZ_WORKLOADS_PROCESS_STATE), 0755, true);
    $encoded = json_encode(['sampledAt' => $sampledAt, 'items' => $currentItems], JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        $temporary = WAZ_WORKLOADS_PROCESS_STATE . '.tmp.' . getmypid();
        if (@file_put_contents($temporary, $encoded, LOCK_EX) !== false) {
            @rename($temporary, WAZ_WORKLOADS_PROCESS_STATE);
        }
    }

    usort($processes, static function (array $left, array $right): int {
        $cpu = ((float) $right['cpuPercent']) <=> ((float) $left['cpuPercent']);
        return $cpu !== 0 ? $cpu : ((float) $right['memoryBytes'] <=> (float) $left['memoryBytes']);
    });
    return $processes;
}

function waz_workloads_gpu(): array
{
    $gpu = waz_workloads_json_file(WAZ_WORKLOADS_GPU_STATE);
    $sampledAt = (int) ($gpu['sampledAtMs'] ?? 0);
    $gpu['stale'] = $sampledAt <= 0 || ((int) round(microtime(true) * 1000) - $sampledAt) > 5000;
    return $gpu;
}

function waz_workloads_gpu_for(string $name, array $gpu): array
{
    $matches = static function ($process) use ($name): bool {
        return is_array($process) && strcasecmp(trim((string) ($process['container'] ?? '')), $name) === 0;
    };
    $processes = array_values(array_filter((array) ($gpu['processes'] ?? []), $matches));
    $video = array_values(array_filter((array) ($gpu['videoProcesses'] ?? []), $matches));
    return [
        'available' => ($gpu['available'] ?? false) === true && ($gpu['stale'] ?? true) === false,
        'active' => count($processes) > 0,
        'videoActive' => count($video) > 0,
        'loadPercent' => waz_workloads_number($gpu['loadPercent'] ?? null),
        'videoLoadPercent' => waz_workloads_number($gpu['videoLoadPercent'] ?? null),
        'processes' => $processes,
    ];
}

function waz_workloads_stats(array $stats): array
{
    if (!$stats) {
        return ['available' => false];
    }
    $cpuNow = (float) ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0);
    $cpuBefore = (float) ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
    $systemNow = (float) ($stats['cpu_stats']['system_cpu_usage'] ?? 0);
    $systemBefore = (float) ($stats['precpu_stats']['system_cpu_usage'] ?? 0);
    $reportedOnline = waz_workloads_number($stats['cpu_stats']['online_cpus'] ?? null);
    $perCpuCount = count((array) ($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []));
    $online = $reportedOnline !== null && $reportedOnline > 0 ? $reportedOnline : (float) max(1, $perCpuCount);
    $cpuDelta = $cpuNow - $cpuBefore;
    $systemDelta = $systemNow - $systemBefore;
    $cpuPercent = $cpuDelta > 0 && $systemDelta > 0 ? $cpuDelta / $systemDelta * $online * 100.0 : 0.0;

    $memoryRaw = (float) ($stats['memory_stats']['usage'] ?? 0);
    $memoryCache = (float) ($stats['memory_stats']['stats']['inactive_file'] ?? $stats['memory_stats']['stats']['cache'] ?? 0);
    $memoryUsage = max(0.0, $memoryRaw - $memoryCache);
    $memoryLimit = (float) ($stats['memory_stats']['limit'] ?? 0);
    $rx = 0.0;
    $tx = 0.0;
    foreach ((array) ($stats['networks'] ?? []) as $network) {
        $rx += (float) ($network['rx_bytes'] ?? 0);
        $tx += (float) ($network['tx_bytes'] ?? 0);
    }
    $read = 0.0;
    $write = 0.0;
    foreach ((array) ($stats['blkio_stats']['io_service_bytes_recursive'] ?? []) as $item) {
        $operation = strtolower((string) ($item['op'] ?? ''));
        if ($operation === 'read') {
            $read += (float) ($item['value'] ?? 0);
        } elseif ($operation === 'write') {
            $write += (float) ($item['value'] ?? 0);
        }
    }
    return [
        'available' => true,
        'cpuPercent' => $cpuBefore > 0 && $systemBefore > 0 ? round(max(0.0, $cpuPercent), 1) : null,
        'cpuCounters' => ['usage' => $cpuNow, 'system' => $systemNow, 'online' => $online],
        'memoryBytes' => $memoryUsage,
        'memoryLimitBytes' => $memoryLimit,
        'memoryPercent' => $memoryLimit > 0 ? round($memoryUsage * 100.0 / $memoryLimit, 1) : null,
        'networkRxBytes' => $rx,
        'networkTxBytes' => $tx,
        'blockReadBytes' => $read,
        'blockWriteBytes' => $write,
        'processCount' => (int) ($stats['pids_stats']['current'] ?? 0),
        'sampledAtMs' => (int) round(microtime(true) * 1000),
    ];
}

function waz_workloads_pool_label(string $source, array $poolNames): string
{
    foreach ($poolNames as $pool) {
        if ($source === '/mnt/' . $pool || str_starts_with($source, '/mnt/' . $pool . '/')) {
            return ucwords(str_replace(['-', '_'], ' ', $pool));
        }
    }
    if (preg_match('#^/mnt/disk(\d+)(?:/|$)#i', $source, $match)) {
        return 'Array · Disk ' . $match[1];
    }
    if (preg_match('#^/mnt/user/([^/]+)#i', $source, $match)) {
        return 'Share · ' . $match[1];
    }
    if (str_starts_with($source, '/mnt/user0/')) {
        return 'Array user shares';
    }
    if (str_starts_with($source, '/mnt/disks/')) {
        return 'Unassigned Devices';
    }
    if (str_starts_with($source, '/var/lib/docker')) {
        return 'Docker vdisk';
    }
    return 'Host filesystem';
}

function waz_workloads_mounts(array $details): array
{
    $poolNames = [];
    foreach (glob('/boot/config/pools/*.cfg') ?: [] as $path) {
        $poolNames[] = basename($path, '.cfg');
    }
    $mounts = [];
    $pools = [];
    foreach ((array) ($details['Mounts'] ?? []) as $mount) {
        $source = trim((string) ($mount['Source'] ?? ''));
        if ($source === '') {
            continue;
        }
        $label = waz_workloads_pool_label($source, $poolNames);
        $pools[$label] = true;
        $mounts[] = [
            'source' => $source,
            'destination' => trim((string) ($mount['Destination'] ?? '')),
            'readWrite' => (bool) ($mount['RW'] ?? false),
            'type' => trim((string) ($mount['Type'] ?? '')),
            'pool' => $label,
        ];
    }
    return ['pools' => array_keys($pools), 'mounts' => $mounts];
}

function waz_workloads_addresses(array $container): array
{
    $addresses = [];
    foreach ((array) ($container['Networks'] ?? []) as $network => $values) {
        $ip = trim((string) ($values['IPAddress'] ?? ''));
        if ($ip !== '') {
            $addresses[] = $network . ' · ' . $ip;
        }
    }
    $ports = [];
    foreach ((array) ($container['Ports'] ?? []) as $values) {
        if (!is_array($values)) {
            continue;
        }
        $public = trim((string) ($values['PublicPort'] ?? ''));
        $private = trim((string) ($values['PrivatePort'] ?? ''));
        if ($public !== '') {
            $ports[] = $public . ($private !== '' && $private !== $public ? '→' . $private : '');
        }
    }
    return ['addresses' => array_values(array_unique($addresses)), 'ports' => array_values(array_unique($ports))];
}

function waz_workloads_current_health(string $state, string $health): string
{
    return $state === 'running' && in_array($health, ['healthy', 'unhealthy', 'starting'], true)
        ? $health : 'none';
}

function waz_workloads_container_public(array $container, array $info, array $gpu): array
{
    $status = (string) ($container['Status'] ?? '');
    $health = stripos($status, '(unhealthy)') !== false ? 'unhealthy' : (stripos($status, '(healthy)') !== false ? 'healthy' : 'none');
    $state = !empty($container['Paused']) ? 'paused' : (!empty($container['Running']) ? 'running' : 'stopped');
    $health = waz_workloads_current_health($state, $health);
    $updated = (string) ($info['updated'] ?? 'undef');
    $updateStatus = $updated === 'false' ? 1 : ($updated === 'true' ? 0 : 3);
    $name = (string) $container['Name'];
    $gpuState = waz_workloads_gpu_for($name, $gpu);
    $labels = waz_workloads_container_labels((string) $container['Id']);
    $folderLabel = '';
    foreach (['folder.view3', 'folder.view2', 'folder.view', 'net.unraid.docker.folder', 'com.unraid.docker.folder'] as $folderLabelKey) {
        $candidate = trim((string) ($labels[$folderLabelKey] ?? ''));
        if ($candidate !== '') {
            $folderLabel = $candidate;
            break;
        }
    }
    return [
        'id' => (string) $container['Id'],
        'name' => $name,
        'image' => (string) ($container['Image'] ?? ''),
        'imageId' => (string) ($container['ImageId'] ?? ''),
        'icon' => trim((string) ($info['icon'] ?? $container['Icon'] ?? '')) ?: WAZ_WORKLOADS_FALLBACK_ICON,
        'state' => $state,
        'health' => $health,
        'status' => $status,
        'updateStatus' => $updateStatus,
        'autostart' => (bool) ($info['autostart'] ?? false),
        'gpuActive' => $gpuState['active'],
        'videoActive' => $gpuState['videoActive'],
        'folderLabel' => $folderLabel,
        'menu' => [
            'template' => (string) ($info['template'] ?? ''),
            'webUi' => html_entity_decode((string) ($info['url'] ?? '')),
            'tailscaleWebUi' => html_entity_decode((string) ($info['TSurl'] ?? '')),
            'shell' => (string) ($info['shell'] ?? ''),
            'support' => html_entity_decode((string) ($info['Support'] ?? '')),
            'project' => html_entity_decode((string) ($info['Project'] ?? '')),
            'registry' => html_entity_decode((string) ($info['registry'] ?? '')),
            'donate' => html_entity_decode((string) ($info['DonateLink'] ?? '')),
            'readme' => html_entity_decode((string) ($info['ReadMe'] ?? '')),
        ],
        '_raw' => $container,
    ];
}


// Fast path: validate the selected container against its current Docker state.
// It never reads the inventory/templates, scans host processes, or samples peers.
function waz_workloads_active_stats($client, string $name): array
{
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $name)) return ['selected' => null];
    $details = $client->getContainerDetails(rawurlencode($name));
    if (!is_array($details) || empty($details['Id'])) return ['selected' => null];
    $state = $details['State'] ?? [];
    $running = !empty($state['Running']) && empty($state['Paused']) && empty($state['Restarting']);
    $status = !empty($state['Paused']) ? 'paused' : (!empty($state['Restarting']) ? 'restarting' : ($running ? 'running' : 'stopped'));
    $id = (string) $details['Id'];
    $raw = $running ? $client->getDockerJSON('/containers/' . rawurlencode($id) . '/stats?stream=false&one-shot=true') : [];
    return ['selected' => [
        'id' => $id,
        'name' => ltrim((string) ($details['Name'] ?? $name), '/'),
        'state' => $status,
        'health' => waz_workloads_current_health($status, (string) ($state['Health']['Status'] ?? 'none')),
        'stats' => waz_workloads_stats(is_array($raw) ? $raw : []),
    ]];
}

$response = [];
try {
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
    if ($docroot === '') {
        $docroot = '/usr/local/emhttp';
    }
    $dockerClientPath = $docroot . '/plugins/dynamix.docker.manager/include/DockerClient.php';
    if (!is_file($dockerClientPath) || !is_readable('/var/run/docker.sock')) {
        throw new RuntimeException('Docker is unavailable');
    }
    $_SERVER['REQUEST_URI'] = 'docker';
    require_once $dockerClientPath;
    $client = new DockerClient();
    if (($_GET['section'] ?? '') === 'stats') {
        $response = waz_workloads_active_stats($client, trim((string) ($_GET['selected'] ?? '')));
        $response['sampledAtMs'] = (int) round(microtime(true) * 1000);
    } else {
    $templates = new DockerTemplates();
    $rawContainers = $client->getDockerContainers();
    $allInfo = $templates->getAllInfo();
    $gpu = waz_workloads_gpu();
    $containers = [];
    foreach ($rawContainers as $raw) {
        if (!is_array($raw) || trim((string) ($raw['Name'] ?? '')) === '') {
            continue;
        }
        $name = (string) $raw['Name'];
        $containers[] = waz_workloads_container_public($raw, (array) ($allInfo[$name] ?? []), $gpu);
    }
    usort($containers, static fn(array $left, array $right): int => strnatcasecmp((string) $left['name'], (string) $right['name']));

    $containerIds = [];
    foreach ($containers as $container) {
        $containerIds[strtolower((string) $container['id'])] = (string) $container['name'];
    }
    $processes = waz_workloads_process_snapshot($containerIds);
    $topProcesses = array_slice($processes, 0, 4);

    $selectedName = trim((string) ($_GET['selected'] ?? ''));
    if ($selectedName !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/', $selectedName)) {
        $selectedName = '';
    }
    $selected = null;
    foreach ($containers as $container) {
        if ($selectedName !== '' && strcasecmp((string) $container['name'], $selectedName) === 0) {
            $selected = $container;
            break;
        }
    }
    if ($selected !== null) {
        $raw = $selected['_raw'];
        $details = $client->getContainerDetails((string) $selected['id']);
        $stats = []; // Stats are sampled only by the active inspector's fast path.
        $health = (string) ($details['State']['Health']['Status'] ?? $selected['health']);
        $startedAt = strtotime((string) ($details['State']['StartedAt'] ?? '')) ?: 0;
        $addresses = waz_workloads_addresses($raw);
        $selected['stats'] = waz_workloads_stats($stats);
        $selected['gpu'] = waz_workloads_gpu_for((string) $selected['name'], $gpu);
        $selected['storage'] = waz_workloads_mounts($details);
        $selected['addresses'] = $addresses['addresses'];
        $selected['ports'] = $addresses['ports'];
        $selected['uptimeSeconds'] = $selected['state'] === 'running' && $startedAt > 0 ? max(0, time() - $startedAt) : null;
        $selected['restartCount'] = (int) ($details['RestartCount'] ?? 0);
        $selected['health'] = waz_workloads_current_health($selected['state'], $health);
    }

    $running = count(array_filter($containers, static fn(array $container): bool => $container['state'] === 'running'));
    $paused = count(array_filter($containers, static fn(array $container): bool => $container['state'] === 'paused'));
    $attentionMessages = [];
    foreach ($containers as $container) {
        $name = (string) $container['name'];
        if ($container['health'] === 'unhealthy') {
            $attentionMessages[] = $name . ' · healthcheck unhealthy';
            continue;
        }
        $exitMatch = [];
        if ($container['state'] === 'stopped'
            && $container['autostart'] === true
            && preg_match('/Exited \((1|137)\)/i', (string) $container['status'], $exitMatch) === 1) {
            $exitCode = (int) $exitMatch[1];
            $attentionMessages[] = $name . ($exitCode === 137
                ? ' · autostart failed · killed / possible OOM'
                : ' · autostart failed · application error');
        }
    }
    $issues = count($attentionMessages);
    $updates = count(array_filter($containers, static fn(array $container): bool => $container['updateStatus'] === 1));
    $gpuContainers = count(array_filter($containers, static fn(array $container): bool => $container['gpuActive']));
    foreach ($containers as &$container) {
        unset($container['_raw']);
    }
    unset($container);
    if ($selected !== null) {
        unset($selected['_raw']);
    }
    $folderConfiguration = waz_workloads_folder_configuration();
    $folders = waz_workloads_folders($containers, $folderConfiguration['folders']);
    $folderSource = $folderConfiguration['path'];
    if ($folderSource === null && count($folders) > 1) {
        $folderSource = 'docker-labels';
    }
    $response = [
        'schemaVersion' => 1,
        'version' => WAZ_WORKLOADS_VERSION,
        'pluginVersion' => WAZ_WORKLOADS_PLUGIN_VERSION,
        'dockerAvailable' => true,
        'state' => $issues > 0 ? 'attention' : 'normal',
        'attention' => [
            'count' => $issues,
            'messages' => $attentionMessages,
        ],
        'summary' => [
            'total' => count($containers),
            'running' => $running,
            'paused' => $paused,
            'stopped' => count($containers) - $running - $paused,
            'issues' => $issues,
            'updates' => $updates,
            'gpuContainers' => $gpuContainers,
            'dockerVdisk' => waz_workloads_filesystem_usage('/var/lib/docker'),
        ],
        'folderSource' => $folderSource,
        'folders' => $folders,
        'containers' => $containers,
        'topProcesses' => $topProcesses,
        'selected' => $selected,
        'generatedAt' => time(),
        'sampledAtMs' => (int) round(microtime(true) * 1000),
    ];
    }
} catch (Throwable $error) {
    http_response_code(503);
    $response = [
        'schemaVersion' => 1,
        'version' => WAZ_WORKLOADS_VERSION,
        'pluginVersion' => WAZ_WORKLOADS_PLUGIN_VERSION,
        'dockerAvailable' => false,
        'state' => 'fault',
        'error' => $error->getMessage(),
        'generatedAt' => time(),
    ];
}

ob_end_clean();
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
