<?php
/* Rackline read-only inventory. Native metadata is read, never regenerated. */
declare(strict_types=1);

if (!defined('RACKLINE_CONTAINERS_NO_MAIN')) define('RACKLINE_CONTAINERS_NO_MAIN', true);
require_once __DIR__ . '/containers.php';

const RACKLINE_WORKLOADS_VERSION = '@@APP_VERSION@@';
const RACKLINE_WORKLOADS_PLUGIN_VERSION = '@@RELEASE_VERSION@@';
const RACKLINE_WORKLOADS_BYTES = 1048576;
const RACKLINE_WORKLOADS_TEMPLATE_BYTES = 262144;
const RACKLINE_WORKLOADS_TEMPLATE_TOTAL_BYTES = 8388608;
const RACKLINE_WORKLOADS_TEMPLATE_LIMIT = 256;
const RACKLINE_WORKLOADS_FOLDER_WORK = 131072;
const RACKLINE_WORKLOADS_REGEX_WORK = 4096;
const RACKLINE_WORKLOADS_TEMPLATE_ROOT = '/boot/config/plugins/dockerMan/templates-user';
const RACKLINE_WORKLOADS_FOLDER_CONFIGS = [
    '/boot/config/plugins/folderview.plus/docker.json',
    '/boot/config/plugins/folder.view3/docker.json',
    '/boot/config/plugins/folder.view2/docker.json',
    '/boot/config/plugins/folder.view/docker.json',
    '/boot/config/plugins/dynamix.my.servers/configs/docker.organizer.json',
];
const RACKLINE_WORKLOADS_FALLBACK_ICON = '/plugins/dynamix.docker.manager/images/question.png';

function rackline_workloads_text($value): string
{
    return is_string($value) ? $value : '';
}

function rackline_workloads_read(string $path, int $limit = RACKLINE_WORKLOADS_BYTES): ?string
{
    // Existing regular files only. No FIFOs, sockets, devices, commands or cache writes.
    if (!is_file($path)) return null;
    $handle = @fopen($path, 'rb');
    if ($handle === false) return null;
    try { $contents = @stream_get_contents($handle, $limit + 1); }
    finally { fclose($handle); }
    return is_string($contents) && strlen($contents) <= $limit ? $contents : null;
}

function rackline_workloads_json_file(string $path): array
{
    $contents = rackline_workloads_read($path);
    if ($contents === null) return [];
    $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents, true);
    return is_array($decoded) ? $decoded : [];
}

function rackline_workloads_color($value, string $fallback): string
{
    $value = trim(rackline_workloads_text($value));
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
}

function rackline_workloads_folder_color(array $folder, int $index): string
{
    $palette = ['#22b8f0', '#a78bfa', '#2dd4bf', '#60a5fa', '#e879f9', '#34d399', '#f2a900', '#f97316'];
    $settings = is_array($folder['settings'] ?? null) ? $folder['settings'] : [];
    $candidate = $settings['preview_vertical_bars_color'] ?? $settings['preview_border_color'] ?? '';
    return rackline_workloads_color($candidate, $palette[$index % count($palette)]);
}

function rackline_workloads_native_member_names(string $entryId, array $entries, array $resources, array &$visited, ?int &$budget = null): array
{
    // Organizer input is a graph, not necessarily a shallow tree. Keep traversal
    // iterative and share a work budget across top-level folders, including cycles.
    $budget ??= RACKLINE_WORKLOADS_FOLDER_WORK;
    $pending = [$entryId];
    $names = [];
    while ($pending && $budget > 0) {
        $id = array_pop($pending);
        $budget--;
        if ($id === '' || isset($visited[$id]) || !is_array($entries[$id] ?? null)) continue;
        $visited[$id] = true;
        $entry = $entries[$id];
        if (($entry['type'] ?? '') === 'folder') {
            $children = is_array($entry['children'] ?? null) ? $entry['children'] : [];
            // Bound queued edges too: an enormous fan-out must not allocate more work.
            if (count($children) + count($pending) > $budget) { $budget = 0; break; }
            foreach (array_reverse($children) as $childId) {
                $pending[] = is_int($childId) ? (string) $childId : rackline_workloads_text($childId);
            }
            continue;
        }
        if (($entry['type'] ?? '') !== 'ref') continue;
        $resource = $resources[rackline_workloads_text($entry['target'] ?? null)] ?? null;
        if (!is_array($resource)) continue;
        $metaNames = is_array($resource['meta']['names'] ?? null) ? $resource['meta']['names'] : [];
        $name = trim(rackline_workloads_text($metaNames[0] ?? $resource['name'] ?? null), " /\t\n\r\0\x0B");
        if ($name !== '') $names[] = $name;
    }
    return $names;
}

function rackline_workloads_native_configuration(array $organizer, ?bool &$partial = null): array
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
    $rootId = rackline_workloads_text($view['root'] ?? 'root');
    $root = is_array($entries[$rootId] ?? null) ? $entries[$rootId] : null;
    if ($root === null || ($root['type'] ?? '') !== 'folder') {
        return [];
    }
    $folders = [];
    $budget = RACKLINE_WORKLOADS_FOLDER_WORK;
    foreach ((array) ($root['children'] ?? []) as $childId) {
        if (--$budget <= 0) { $partial = true; break; }
        $childId = is_int($childId) ? (string) $childId : rackline_workloads_text($childId);
        $folder = is_array($entries[$childId] ?? null) ? $entries[$childId] : null;
        if ($folder === null || ($folder['type'] ?? '') !== 'folder') {
            continue;
        }
        $visited = [];
        $folders[$childId] = [
            'name' => trim(rackline_workloads_text($folder['name'] ?? null)),
            'containers' => array_values(array_unique(rackline_workloads_native_member_names($childId, $entries, $resources, $visited, $budget))),
        ];
        if ($budget <= 0) { $partial = true; break; }
    }
    return array_filter($folders, static fn(array $folder): bool => $folder['name'] !== '');
}

function rackline_workloads_folder_configuration(?array $candidates = null, string $preferencesPath = '/boot/config/plugins/dockerMan/userprefs.cfg'): array
{
    $discover = $candidates === null;
    $candidates ??= RACKLINE_WORKLOADS_FOLDER_CONFIGS;
    foreach ($discover ? (glob('/boot/config/plugins/folder.view*/docker.json') ?: []) : [] as $candidate) {
        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }
    foreach ($candidates as $path) {
        $partial = false;
        $folders = rackline_workloads_json_file($path);
        if (isset($folders['version'], $folders['resources'], $folders['views'])) {
            $folders = rackline_workloads_native_configuration($folders, $partial);
        }
        if ($folders !== []) {
            $ordered = [];
            $preferencesText = rackline_workloads_read($preferencesPath);
            $preferences = $preferencesText === null ? [] : (array) @parse_ini_string($preferencesText, false, INI_SCANNER_RAW);
            foreach (array_values($preferences) as $entry) {
                $entry = rackline_workloads_text($entry);
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
                    rackline_workloads_text(is_array($left) ? ($left['name'] ?? null) : null),
                    rackline_workloads_text(is_array($right) ? ($right['name'] ?? null) : null)
                ));
            } else {
                foreach ($folders as $id => $folder) {
                    if (!isset($ordered[$id])) {
                        $ordered[$id] = $folder;
                    }
                }
                $folders = $ordered;
            }
            return ['path' => $path, 'folders' => $folders, 'partial' => $partial];
        }
    }
    return ['path' => null, 'folders' => []];
}

function rackline_workloads_folders(array $containers, array $configured, ?bool &$partial = null): array
{
    $assigned = [];
    $folders = [];
    $byName = [];
    $caseNames = [];
    $labelNames = [];
    foreach ($containers as $container) {
        $key = (string) $container['name'];
        $byName[$key] = $container;
        $lower = strtolower($key);
        $caseNames[$lower] = array_key_exists($lower, $caseNames) ? null : $key;
        $label = strtolower(trim(rackline_workloads_text($container['folderLabel'] ?? null)));
        if ($label !== '') $labelNames[$label][] = $key;
    }
    $remaining = $byName;
    $budget = RACKLINE_WORKLOADS_FOLDER_WORK;
    $regexBudget = RACKLINE_WORKLOADS_REGEX_WORK;

    $allNames = array_map(static fn(array $container): string => (string) $container['name'], $containers);
    $folders[] = [
        'id' => 'all',
        'name' => 'All',
        'icon' => null,
        'color' => '#22b8f0',
        'containers' => $allNames,
    ];

    $index = 0;
    $folderIds = ['all' => true];
    foreach ($configured as $folderId => $folder) {
        if (--$budget < 0) { $partial = true; break; }
        if (!is_array($folder)) {
            continue;
        }
        $name = trim(rackline_workloads_text($folder['name'] ?? null));
        if ($name === '') {
            continue;
        }
        $members = [];
        foreach ((array) ($folder['containers'] ?? []) as $member) {
            if (--$budget < 0) { $partial = true; break; }
            $key = trim(rackline_workloads_text($member));
            if (!isset($byName[$key])) {
                $key = $caseNames[strtolower($key)] ?? '';
            }
            if ($key !== '' && isset($byName[$key]) && !isset($assigned[$key])) {
                $members[] = (string) $byName[$key]['name'];
                $assigned[$key] = true;
                unset($remaining[$key]);
            }
        }
        foreach ($labelNames[strtolower($name)] ?? [] as $key) {
            if (--$budget < 0) { $partial = true; break; }
            if (!isset($assigned[$key])) {
                $members[] = $key;
                $assigned[$key] = true;
                unset($remaining[$key]);
            }
        }
        $regex = trim(rackline_workloads_text($folder['regex'] ?? null));
        if ($regex !== '' && strlen($regex) <= 512) {
            // Per-match PCRE limits and a request-wide match budget prevent a
            // small configured regex from monopolizing a PHP/WebUI worker.
            $pattern = '/(*LIMIT_MATCH=1000)(*LIMIT_DEPTH=64)' . str_replace('/', '\\/', $regex) . '/i';
            foreach ($remaining as $key => $container) {
                if (--$regexBudget < 0) { $partial = true; break; }
                $match = @preg_match($pattern, (string) $container['name']);
                if ($match === false) { $partial = true; break; }
                if ($match === 1) {
                    $members[] = (string) $container['name'];
                    $assigned[$key] = true;
                    unset($remaining[$key]);
                }
            }
        } elseif ($regex !== '') $partial = true;
        $rawId = (string) $folderId;
        $safeId = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId) ?? '', 0, 64);
        $baseId = 'folder-' . $safeId . ($rawId === $safeId ? '' : '-' . substr(sha1($rawId), 0, 12));
        $id = $baseId;
        $suffix = 0;
        while (isset($folderIds[$id])) $id = $baseId . '-' . ++$suffix;
        $folderIds[$id] = true;
        $folders[] = [
            'id' => $id,
            'name' => $name,
            'icon' => trim(rackline_workloads_text($folder['icon'] ?? null)) ?: null,
            'color' => rackline_workloads_folder_color($folder, $index++),
            'containers' => $members,
        ];
    }

    $labelGroups = [];
    foreach ($containers as $container) {
        $key = (string) $container['name'];
        $label = trim((string) ($container['folderLabel'] ?? ''));
        if (!isset($assigned[$key]) && $label !== '') {
            $labelGroups[$label][] = (string) $container['name'];
            $assigned[$key] = true;
        }
    }
    foreach ($labelGroups as $label => $members) {
        $label = (string) $label; // PHP coerces numeric-string keys to integers.
        $folders[] = [
            'id' => 'label-' . substr(sha1($label), 0, 12),
            'name' => $label,
            'icon' => null,
            'color' => rackline_workloads_folder_color([], $index++),
            'containers' => $members,
        ];
    }

    $unassigned = [];
    foreach ($containers as $container) {
        if (!isset($assigned[(string) $container['name']])) {
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

function rackline_workloads_pool_label(string $source, array $poolNames): string
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

function rackline_workloads_mounts(array $details): array
{
    $poolNames = [];
    foreach (glob('/boot/config/pools/*.cfg') ?: [] as $path) {
        $poolNames[] = basename($path, '.cfg');
    }
    $mounts = [];
    $pools = [];
    foreach ((array) ($details['Mounts'] ?? []) as $mount) {
        if (!is_array($mount)) continue;
        $source = trim(rackline_workloads_text($mount['Source'] ?? null));
        if ($source === '') {
            continue;
        }
        $label = rackline_workloads_pool_label($source, $poolNames);
        $pools[$label] = true;
        $mounts[] = [
            'source' => $source,
            'destination' => trim(rackline_workloads_text($mount['Destination'] ?? null)),
            'readWrite' => (bool) ($mount['RW'] ?? false),
            'type' => trim(rackline_workloads_text($mount['Type'] ?? null)),
            'pool' => $label,
        ];
    }
    return ['pools' => array_keys($pools), 'mounts' => $mounts];
}

function rackline_workloads_addresses(array $container): array
{
    $addresses = [];
    foreach ((array) ($container['Networks'] ?? []) as $network => $values) {
        if (!is_array($values)) continue;
        $ip = trim(rackline_workloads_text($values['IPAddress'] ?? null));
        if ($ip !== '') {
            $addresses[] = $network . ' · ' . $ip;
        }
    }
    $ports = [];
    foreach ((array) ($container['Ports'] ?? []) as $values) {
        if (!is_array($values)) {
            continue;
        }
        $public = is_int($values['PublicPort'] ?? null) ? (string) $values['PublicPort'] : trim(rackline_workloads_text($values['PublicPort'] ?? null));
        $private = is_int($values['PrivatePort'] ?? null) ? (string) $values['PrivatePort'] : trim(rackline_workloads_text($values['PrivatePort'] ?? null));
        if ($public !== '') {
            $ports[] = $public . ($private !== '' && $private !== $public ? '→' . $private : '');
        }
    }
    return ['addresses' => array_values(array_unique($addresses)), 'ports' => array_values(array_unique($ports))];
}

function rackline_workloads_current_health(string $state, string $health): string
{
    return $state === 'running' && in_array($health, ['healthy', 'unhealthy', 'starting'], true)
        ? $health : 'none';
}

/** Read only an existing user template. Do not turn owner-managed containers into DockerMan containers. */
function rackline_workloads_template(string $path, string $root): ?array
{
    if (!class_exists('DOMDocument')) return null;
    $base = realpath($root);
    $resolved = realpath($path);
    if ($base === false || $resolved === false || !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)
        || !str_ends_with(strtolower($resolved), '.xml')) return null;
    $xml = rackline_workloads_read($resolved, RACKLINE_WORKLOADS_TEMPLATE_BYTES);
    if ($xml === null || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) return null;
    $before = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->tagName !== 'Container') return null;
        $result = ['template' => $path];
        $query = new DOMXPath($document);
        foreach (['Name', 'Repository', 'WebUI', 'Icon', 'Shell', 'Registry', 'Support', 'Project', 'DonateLink', 'ReadMe'] as $field) {
            $elements = $query->query('/Container/' . $field);
            if ($elements === false || $elements->length > 1 || ($field === 'Name' && $elements->length !== 1)) return null;
            $result[$field] = trim($elements->item(0)?->textContent ?? '');
        }
        return $result;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($before);
    }
}

/** Native cache paths are defined by Unraid's DockerClient.php; never include that executable helper. */
function rackline_workloads_metadata(array $containers, string $docroot, string $templateRoot = RACKLINE_WORKLOADS_TEMPLATE_ROOT, string $autostartPath = '/var/lib/docker/unraid-autostart'): array
{
    $cachePath = rtrim($docroot, '/') . '/state/plugins/dynamix.docker.manager/docker.json';
    $cacheBytes = rackline_workloads_read($cachePath);
    $cache = $cacheBytes === null ? null : json_decode($cacheBytes, true);
    $cacheAvailable = is_array($cache);
    $cache = $cacheAvailable ? $cache : [];
    $autostartBytes = rackline_workloads_read($autostartPath);
    $autostart = [];
    foreach (preg_split('/\r?\n/', $autostartBytes ?? '') ?: [] as $line) {
        $name = preg_split('/\s+/', trim($line))[0] ?? '';
        if ($name !== '') $autostart[$name] = true;
    }
    $wanted = [];
    $allInfo = [];
    foreach ($containers as $container) {
        $name = $container['name'];
        $info = is_array($cache[$name] ?? null) ? $cache[$name] : [];
        $info['autostart'] = $autostartBytes !== null ? isset($autostart[$name]) : null;
        // Cached template names do not grant ownership, even after a same-name recreation.
        $info['template'] = '';
        if (($container['labels']['net.unraid.docker.managed'] ?? '') === 'dockerman') $wanted[$name] = true;
        else $info['updated'] = 'undef';
        $allInfo[$name] = $info;
    }
    $templateCount = 0;
    $templateBytes = 0;
    $templates = [];
    $seen = [];
    $limited = false;
    $readTemplate = static function (string $path) use (&$templates, &$seen, &$templateCount, &$templateBytes, &$limited, $templateRoot, $wanted): void {
        if (isset($seen[$path])) return;
        $seen[$path] = true;
        $size = @filesize($path);
        if ($templateCount >= RACKLINE_WORKLOADS_TEMPLATE_LIMIT || ($size !== false && $templateBytes + $size > RACKLINE_WORKLOADS_TEMPLATE_TOTAL_BYTES)) { $limited = true; return; }
        $templateCount++;
        $templateBytes += $size === false ? 0 : $size;
        $template = rackline_workloads_template($path, $templateRoot);
        // An unreadable/malformed XML could hide a duplicate name. A partial
        // scan cannot prove that any editor target is unique.
        if ($template === null) { $limited = true; return; }
        if (!isset($wanted[$template['Name']])) return;
        $name = $template['Name'];
        // Two templates claiming the same name are ambiguous; don't choose an editor arbitrarily.
        if (array_key_exists($name, $templates)) $templates[$name] = null;
        else $templates[$name] = $template;
    };
    // Reading a bounded list directly also works before anyone opens the native Docker page.
    if ($wanted && is_dir($templateRoot) && class_exists('DOMDocument')) {
        try {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
            $files->setMaxDepth(8);
            $entries = 0;
            foreach ($files as $file) {
                if (++$entries > RACKLINE_WORKLOADS_TEMPLATE_LIMIT * 4) { $limited = true; break; }
                if ($file->isDir() && ($files->getDepth() >= 8 || $file->isLink() || !$file->isReadable())) { $limited = true; break; }
                if (!$file->isFile() || str_starts_with($file->getFilename(), '.') || strtolower($file->getExtension()) !== 'xml') continue;
                $readTemplate($file->getPathname());
                if ($limited) break;
            }
        } catch (Throwable $error) { $limited = true; }
    }
    foreach ($templates as $name => $template) {
        if ($template === null || $limited) continue;
        $info = $allInfo[$name];
        $info['template'] = $template['template'];
        foreach (['WebUI' => 'url', 'Icon' => 'icon', 'Shell' => 'shell', 'Registry' => 'registry', 'Support' => 'Support', 'Project' => 'Project', 'DonateLink' => 'DonateLink', 'ReadMe' => 'ReadMe'] as $source => $target) {
            if (!is_string($info[$target] ?? null) || $info[$target] === '') $info[$target] = $template[$source];
        }
        $allInfo[$name] = $info;
    }
    return [
        'info' => $allInfo,
        'status' => ['cacheAvailable' => $cacheAvailable, 'templatesAvailable' => is_dir($templateRoot) && class_exists('DOMDocument'),
            'templatesPartial' => $limited, 'autostartAvailable' => $autostartBytes !== null,
            'note' => 'Native cached links and update status are not refreshed by Rackline.'],
    ];
}

/** Accept the daemon's immutable identity and exact name; malformed/duplicate identities are rejected. */
function rackline_workloads_inventory(array $raw): array
{
    $containers = [];
    foreach (rackline_container_inventory($raw) as $item) {
        $name = ltrim($item['Names'][0], '/');
        $containers[] = ['id' => $item['Id'], 'name' => $name, 'labels' => is_array($item['Labels'] ?? null) ? $item['Labels'] : [], 'raw' => $item];
    }
    return $containers;
}

function rackline_workloads_url($value): string
{
    $url = html_entity_decode(rackline_workloads_text($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Native docker.js owns the menu HTML; do not hand it markup or JS delimiters.
    // Embedded credentials and unresolved native URL templates remain private.
    if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url)
        || preg_match('/\[(?:IP|PORT:[0-9]+|hostname|magicdns|noserve)\]/i', $url)) return '';
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return $url;
    if (!preg_match('#^https?://#i', $url)) return '';
    $parts = parse_url($url);
    return is_array($parts) && ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']) ? $url : '';
}

function rackline_workloads_shell($value): string
{
    $shell = rackline_workloads_text($value);
    return in_array($shell, ['sh', 'bash', 'ash', 'zsh', '/bin/sh', '/bin/bash', '/bin/ash', '/bin/zsh'], true) ? $shell : '';
}

function rackline_workloads_container_public(array $container, array $info): array
{
    $raw = $container['raw'];
    $labels = $container['labels'];
    $state = rackline_workloads_text($raw['State'] ?? null);
    $state = in_array($state, ['running', 'paused', 'restarting'], true) ? $state : 'stopped';
    $status = rackline_workloads_text($raw['Status'] ?? null);
    $health = stripos($status, '(unhealthy)') !== false ? 'unhealthy' : (stripos($status, '(healthy)') !== false ? 'healthy' : (stripos($status, '(health: starting)') !== false ? 'starting' : 'none'));
    $folder = '';
    foreach (['folder.view3', 'folder.view2', 'folder.view', 'net.unraid.docker.folder', 'com.unraid.docker.folder'] as $key) {
        $candidate = trim(rackline_workloads_text($labels[$key] ?? null));
        if ($candidate !== '') { $folder = $candidate; break; }
    }
    $template = ($labels['net.unraid.docker.managed'] ?? '') === 'dockerman' ? rackline_workloads_text($info['template'] ?? null) : '';
    $updated = $template !== '' ? ($info['updated'] ?? 'undef') : 'undef';
    $icon = rackline_workloads_text($info['icon'] ?? $labels['net.unraid.docker.icon'] ?? null);
    // Never load remote icons, including template defaults that native DockerMan has not cached.
    if (!str_starts_with($icon, '/') || str_starts_with($icon, '//') || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $icon)) $icon = RACKLINE_WORKLOADS_FALLBACK_ICON;
    $imageId = rackline_workloads_text($raw['ImageID'] ?? null);
    $imageId = preg_match('/^(?:sha256:)?([a-f0-9]{64})$/D', $imageId, $match) ? substr($match[1], 0, 12) : '';
    return [
        'id' => $container['id'], 'name' => $container['name'], 'image' => rackline_workloads_text($raw['Image'] ?? null),
        'imageId' => $imageId, 'icon' => $icon, 'state' => $state,
        'health' => rackline_workloads_current_health($state, $health), 'status' => $status,
        'updateStatus' => $updated === 'false' || $updated === false ? 1 : ($updated === 'true' || $updated === true ? 0 : 3),
        'autostart' => is_bool($info['autostart'] ?? null) ? $info['autostart'] : null,
        'gpuActive' => null, 'videoActive' => null, 'folderLabel' => $folder,
        'menu' => [
            'template' => $template,
            'webUi' => rackline_workloads_url($labels['net.unraid.docker.webui'] ?? '') ?: rackline_workloads_url($info['url'] ?? ''),
            'tailscaleWebUi' => rackline_workloads_url($info['TSurl'] ?? ''),
            'shell' => rackline_workloads_shell($labels['net.unraid.docker.shell'] ?? $info['shell'] ?? ''),
            'support' => rackline_workloads_url($info['Support'] ?? ''), 'project' => rackline_workloads_url($info['Project'] ?? ''),
            'registry' => rackline_workloads_url($info['registry'] ?? ''), 'donate' => rackline_workloads_url($info['DonateLink'] ?? ''),
            'readme' => rackline_workloads_url($info['ReadMe'] ?? ''),
        ],
    ];
}

function rackline_workloads_selected(array $container, array $details): ?array
{
    $selected = $container['public'];
    if (isset($details['message']) || isset($details['error']) || ($details['Id'] ?? null) !== $selected['id']
        || !is_string($details['Name'] ?? null) || ltrim($details['Name'], '/') !== $selected['name']) return null;
    $state = is_array($details['State'] ?? null) ? $details['State'] : [];
    if (!is_bool($state['Running'] ?? null) || !is_bool($state['Paused'] ?? null) || !is_bool($state['Restarting'] ?? null)) return null;
    $selected['state'] = $state['Paused'] ? 'paused' : ($state['Restarting'] ? 'restarting' : ($state['Running'] ? 'running' : 'stopped'));
    $selected['health'] = rackline_workloads_current_health($selected['state'], rackline_workloads_text($state['Health']['Status'] ?? null));
    $selected['stats'] = ['available' => false]; // Telemetry belongs only to the existing bounded batch collector.
    $selected['gpu'] = rackline_container_gpu_unavailable();
    $selected['storage'] = rackline_workloads_mounts($details);
    $addresses = rackline_workloads_addresses(['Networks' => $details['NetworkSettings']['Networks'] ?? [], 'Ports' => $container['raw']['Ports'] ?? []]);
    $selected['addresses'] = $addresses['addresses'];
    $selected['ports'] = $addresses['ports'];
    $started = rackline_container_timestamp($state['StartedAt'] ?? null);
    $selected['uptimeSeconds'] = $selected['state'] === 'running' && $started !== null && $started <= microtime(true) ? max(0, (int) floor(microtime(true) - $started)) : null;
    $selected['restartCount'] = is_int($details['RestartCount'] ?? null) && $details['RestartCount'] >= 0 ? $details['RestartCount'] : null;
    return $selected;
}

function rackline_workloads_collect(string $selectedName, callable $transport, callable $metadata, array $folderConfiguration): array
{
    if (strlen($selectedName) > 255 || ($selectedName !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/D', $selectedName))) throw new InvalidArgumentException('Invalid container name');
    $deadline = hrtime(true) / 1e9 + 3.5;
    $inventory = $transport(['inventory' => '/containers/json?all=true'], $deadline)['inventory'] ?? [];
    if (($inventory['status'] ?? '') !== 'ok' || !is_array($inventory['data'] ?? null)) throw new RuntimeException('Docker inventory unavailable');
    $raw = rackline_workloads_inventory($inventory['data']);
    $native = $metadata($raw);
    $containers = [];
    $selectedContainer = null;
    foreach ($raw as $container) {
        $info = is_array($native['info'][$container['name']] ?? null) ? $native['info'][$container['name']] : [];
        $public = rackline_workloads_container_public($container, $info);
        $containers[] = $public;
        if ($container['name'] === $selectedName) $selectedContainer = $container + ['public' => $public];
    }
    usort($containers, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    $selected = null;
    $selectedStatus = $selectedName === '' ? 'not_requested' : 'not_found';
    if ($selectedContainer !== null) {
        // One selected immutable-ID inspect, with the same deadline as inventory. Never inspect peers.
        $inspection = $transport(['selected' => '/containers/' . $selectedContainer['id'] . '/json'], $deadline)['selected'] ?? [];
        $selectedStatus = $inspection['status'] ?? 'unavailable';
        if ($selectedStatus === 'ok' && is_array($inspection['data'] ?? null)) {
            $selected = rackline_workloads_selected($selectedContainer, $inspection['data']);
            if ($selected === null) $selectedStatus = 'unavailable';
        }
    }
    $running = 0; $paused = 0; $updates = 0; $messages = [];
    foreach ($containers as $container) {
        if ($container['state'] === 'running') $running++;
        if ($container['state'] === 'paused') $paused++;
        if ($container['updateStatus'] === 1) $updates++;
        if ($container['health'] === 'unhealthy') $messages[] = $container['name'] . ' · healthcheck unhealthy';
        elseif ($container['state'] === 'stopped' && $container['autostart'] === true && preg_match('/Exited \((1|137)\)/i', $container['status'], $match)) {
            $messages[] = $container['name'] . ($match[1] === '137' ? ' · autostart failed · killed / possible OOM' : ' · autostart failed · application error');
        }
    }
    $foldersPartial = ($folderConfiguration['partial'] ?? false) === true;
    $folders = rackline_workloads_folders($containers, $folderConfiguration['folders'] ?? [], $foldersPartial);
    $folderSource = $folderConfiguration['path'] ?? null;
    if ($folderSource === null && count($folders) > 1) $folderSource = 'docker-labels';
    return [
        'schemaVersion' => 1, 'version' => RACKLINE_WORKLOADS_VERSION, 'pluginVersion' => RACKLINE_WORKLOADS_PLUGIN_VERSION,
        'dockerAvailable' => true, 'state' => $messages ? 'attention' : 'normal',
        'attention' => ['count' => count($messages), 'messages' => $messages],
        'summary' => ['total' => count($containers), 'running' => $running, 'paused' => $paused,
            'stopped' => count($containers) - $running - $paused, 'issues' => count($messages), 'updates' => $updates,
            'gpuContainers' => null, 'dockerVdisk' => ['available' => false]],
        'folderSource' => $folderSource, 'folders' => $folders, 'containers' => $containers,
        'topProcesses' => [], 'processesAvailable' => false, 'gpu' => rackline_container_gpu_unavailable(),
        'nativeMetadata' => ($native['status'] ?? []) + ['foldersPartial' => $foldersPartial], 'selected' => $selected, 'selectedStatus' => $selectedStatus,
        'generatedAt' => time(), 'sampledAtMs' => (int) round(microtime(true) * 1000),
    ];
}

if (!defined('RACKLINE_WORKLOADS_NO_MAIN')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); throw new InvalidArgumentException('GET required'); }
        if (!is_string($_GET['selected'] ?? '')) throw new InvalidArgumentException('Invalid container name');
        if (!function_exists('curl_multi_init') || !defined('CURLOPT_UNIX_SOCKET_PATH') || !is_readable(RACKLINE_CONTAINER_SOCKET)) throw new RuntimeException('Bounded Docker collector unavailable');
        $docroot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '/usr/local/emhttp';
        $response = rackline_workloads_collect(trim($_GET['selected'] ?? ''), 'rackline_container_transport',
            static fn(array $containers): array => rackline_workloads_metadata($containers, $docroot), rackline_workloads_folder_configuration());
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (InvalidArgumentException $error) {
        if (http_response_code() !== 405) http_response_code(400);
        echo json_encode(['schemaVersion' => 1, 'dockerAvailable' => false, 'state' => 'fault', 'error' => $error->getMessage()]);
    } catch (Throwable $error) {
        http_response_code(503);
        echo json_encode(['schemaVersion' => 1, 'dockerAvailable' => false, 'state' => 'fault', 'error' => 'Docker inventory unavailable']);
    }
}
