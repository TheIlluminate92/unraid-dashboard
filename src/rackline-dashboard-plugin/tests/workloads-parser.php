<?php
declare(strict_types=1);
define('RACKLINE_WORKLOADS_NO_MAIN', true);
require dirname(__DIR__) . '/source/usr/local/emhttp/plugins/rackline.dashboard/include/workloads.php';
function workload_check(bool $condition, string $why): void { if (!$condition) throw new RuntimeException($why); }
function workload_rejects(callable $fn, string $why): void { try { $fn(); } catch (RuntimeException | InvalidArgumentException $error) { return; } throw new RuntimeException($why); }
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$id = str_repeat('a', 64); $second = str_repeat('b', 64);
$raw = [
    ['Id' => $id, 'Names' => ['/alpha'], 'Image' => 'example/alpha:latest', 'ImageID' => 'sha256:' . str_repeat('c', 64), 'State' => 'running', 'Status' => 'Up 2 hours (healthy)', 'Labels' => ['net.unraid.docker.managed' => 'dockerman', 'folder.view3' => 'Media'], 'Ports' => [['PublicPort' => 8080, 'PrivatePort' => 80]], 'Command' => 'do not expose secret argv'],
    ['Id' => $second, 'Names' => ['/owner-managed'], 'Image' => 'example/owner:latest', 'State' => 'exited', 'Status' => 'Exited (137)', 'Labels' => ['net.unraid.docker.managed' => 'composeman']],
];
$inventory = rackline_workloads_inventory($raw);
workload_check($inventory[0]['id'] === $id && $inventory[0]['name'] === 'alpha', 'Full immutable ID and exact name');
foreach ([['message' => 'daemon error'], ['error' => 'denied'], [$raw[0], $raw[0]], [$raw[0], array_replace($raw[1], ['Names' => ['/alpha']])], [array_replace($raw[0], ['Id' => substr($id, 0, 12)])], [array_replace($raw[0], ['Names' => ['/bad?name']])]] as $bad) workload_rejects(fn() => rackline_workloads_inventory($bad), 'Reject error/malformed/duplicate identities');
$prefixCollision = array_replace($raw[1], ['Id' => substr($id, 0, 12) . str_repeat('b', 52)]);
workload_check(count(rackline_workloads_inventory([$raw[0], $prefixCollision])) === 2, 'Distinct full IDs retain colliding short prefixes without conflating them');

$root = sys_get_temp_dir() . '/rackline-workloads-' . bin2hex(random_bytes(6));
mkdir($root . '/docroot/state/plugins/dynamix.docker.manager', 0700, true);
mkdir($root . '/templates/nested', 0700, true);
$alphaTemplate = $root . '/templates/nested/my alpha+50%.xml';
file_put_contents($alphaTemplate, '<Container><Name>alpha</Name><Repository>example/alpha:latest</Repository><WebUI>http://[IP]:[PORT:80]</WebUI><Icon>https://example.invalid/remote.png</Icon><Shell>sh</Shell><Support>https://example.invalid/support</Support></Container>');
file_put_contents($root . '/templates/my-owner-managed.xml', '<Container><Name>owner-managed</Name><Repository>example/owner:latest</Repository></Container>');
$cachePath = $root . '/docroot/state/plugins/dynamix.docker.manager/docker.json';
$cache = ['alpha' => ['template' => '/untrusted/cache/template.xml', 'icon' => '/state/plugins/dynamix.docker.manager/images/alpha.png', 'updated' => 'false', 'url' => 'http://192.0.2.10:8080/?a=1&amp;b=2'], 'owner-managed' => ['template' => $root . '/templates/my-owner-managed.xml', 'updated' => 'false', 'autostart' => true]];
file_put_contents($cachePath, json_encode($cache));
file_put_contents($root . '/autostart', "alpha 10\nowner-managed\n");
file_put_contents($root . '/folder.json', json_encode(['empty' => ['name' => 'Empty', 'containers' => []], 'media' => ['name' => 'Media', 'containers' => ['alpha']]]));
file_put_contents($root . '/prefs.cfg', "0=folder-media\n1=folder-empty\n");
$before = [hash_file('sha256', $alphaTemplate), hash_file('sha256', $cachePath), hash_file('sha256', $root . '/autostart')];
try {
    $native = rackline_workloads_metadata($inventory, $root . '/docroot', $root . '/templates', $root . '/autostart');
    workload_check($native['info']['alpha']['template'] === $alphaTemplate && $native['info']['owner-managed']['template'] === '', 'Editor requires exact XML name and current dockerman ownership');
    workload_check($native['info']['alpha']['autostart'] === true && $native['status']['cacheAvailable'], 'Autostart read from its native file, not stale metadata');
    $public = rackline_workloads_container_public($inventory[0], $native['info']['alpha']);
    workload_check($public['imageId'] === str_repeat('c', 12) && $public['menu']['template'] === $alphaTemplate && $public['updateStatus'] === 1, 'Native IDs/menu/template status schema');
    workload_check($public['menu']['webUi'] === 'http://192.0.2.10:8080/?a=1&b=2' && $public['menu']['shell'] === 'sh', 'Use resolved native links; do not execute template URL resolver');
    workload_check($public['gpuActive'] === null && !str_contains(json_encode($public), 'secret argv'), 'Unsupported GPU unknown and raw command not leaked');
    $owner = rackline_workloads_container_public($inventory[1], ['template' => $alphaTemplate, 'updated' => 'false', 'autostart' => null]);
    workload_check($owner['menu']['template'] === '' && $owner['updateStatus'] === 3 && $owner['autostart'] === null, 'Unmanaged container never adopted and unknown autostart not invented');
    $nativeMissing = rackline_workloads_metadata($inventory, $root . '/missing', $root . '/templates', $root . '/missing-autostart');
    workload_check(!$nativeMissing['status']['cacheAvailable'] && !$nativeMissing['status']['autostartAvailable'] && $nativeMissing['info']['alpha']['template'] === $alphaTemplate, 'Independent template lookup works with native library/cache absent');
    $noTemplates = rackline_workloads_metadata($inventory, $root . '/missing', $root . '/missing-templates', $root . '/missing-autostart');
    workload_check(!$noTemplates['status']['templatesAvailable'] && $noTemplates['info']['alpha']['template'] === '', 'Missing template directory degrades safely');
    file_put_contents($root . '/templates/wrong-case.xml', '<Container><Name>Alpha</Name></Container>');
    $caseNative = rackline_workloads_metadata($inventory, $root . '/missing', $root . '/templates', $root . '/autostart');
    workload_check($caseNative['info']['alpha']['template'] === $alphaTemplate, 'Template names are matched exactly, not case-insensitively');
    file_put_contents($root . '/templates/duplicate.xml', '<Container><Name>alpha</Name></Container>');
    workload_check(rackline_workloads_metadata($inventory, $root . '/docroot', $root . '/templates', $root . '/autostart')['info']['alpha']['template'] === '', 'Duplicate matching templates cannot choose arbitrary editor');
    unlink($root . '/templates/duplicate.xml');
    file_put_contents($root . '/outside.xml', '<Container><Name>alpha</Name></Container>');
    symlink($root . '/outside.xml', $root . '/templates/linked.xml');
    workload_check(rackline_workloads_template($root . '/templates/linked.xml', $root . '/templates') === null, 'Template symlink cannot escape user-template directory');
    file_put_contents($root . '/templates/entity.xml', '<!DOCTYPE Container [<!ENTITY secret SYSTEM "file:///not-read">]><Container><Name>&secret;</Name></Container>');
    workload_check(rackline_workloads_template($root . '/templates/entity.xml', $root . '/templates') === null, 'Do not resolve XML entities or external DTDs');
    file_put_contents($root . '/oversized.json', str_repeat('x', RACKLINE_WORKLOADS_BYTES + 1));
    workload_check(rackline_workloads_read($root . '/oversized.json') === null, 'Native/cache inputs are bounded');
    $folderConfiguration = rackline_workloads_folder_configuration([$root . '/folder.json'], $root . '/prefs.cfg');
    workload_check(array_keys($folderConfiguration['folders']) === ['media', 'empty'], 'FolderView Plus/native preference ordering');
    $folders = rackline_workloads_folders([$public, $owner], $folderConfiguration['folders']);
    workload_check($folders[1]['containers'] === ['alpha'] && $folders[2]['name'] === 'Empty' && $folders[2]['total'] === 0 && $folders[3]['name'] === 'Ungrouped', 'Keep configured empty folders and ungrouped members');
    $nativeOrganizer = ['version' => 1, 'resources' => ['alpha-ref' => ['meta' => ['names' => ['/alpha']]]], 'views' => ['default' => ['root' => 'root', 'entries' => ['root' => ['type' => 'folder', 'children' => ['group', 'empty']], 'group' => ['type' => 'folder', 'name' => 'Group', 'children' => ['nested', 'group']], 'nested' => ['type' => 'folder', 'children' => ['ref']], 'ref' => ['type' => 'ref', 'target' => 'alpha-ref'], 'empty' => ['type' => 'folder', 'name' => 'Empty', 'children' => []]]]]];
    $normalized = rackline_workloads_native_configuration($nativeOrganizer);
    workload_check($normalized['group']['containers'] === ['alpha'] && $normalized['empty']['containers'] === [], 'Native Organizer nested membership, cycle guard and empty folders');
    $collidingFolders = rackline_workloads_folders([], ['a b' => ['name' => 'One', 'containers' => []], 'ab' => ['name' => 'Two', 'containers' => []]]);
    workload_check(count(array_unique(array_column($collidingFolders, 'id'))) === 3, 'Folder IDs stay unique after sanitization, including empty groups');
    $labels = rackline_workloads_folders([$public, $owner], []);
    workload_check($labels[1]['name'] === 'Media' && $labels[1]['containers'] === ['alpha'], 'Docker label fallback groups without external plugin');
    $casePublic = $public; $casePublic['name'] = 'Alpha';
    $caseFolders = rackline_workloads_folders([$public, $casePublic], ['a' => ['name' => 'Group', 'containers' => ['ALPHA']]]);
    workload_check($caseFolders[1]['containers'] === [], 'Ambiguous case-insensitive member cannot choose the wrong identity');
    $malformed = rackline_workloads_folders([$owner], ['bad' => ['name' => [], 'containers' => [[]]], 'empty' => ['name' => 'Empty', 'containers' => [[]], 'regex' => [], 'icon' => []]]);
    workload_check($malformed[1]['name'] === 'Empty' && $malformed[1]['containers'] === [], 'Malformed optional folder values produce no PHP warnings');
    $calls = []; $deadlineSeen = null;
    $details = ['Id' => $id, 'Name' => '/alpha', 'State' => ['Running' => true, 'Paused' => false, 'Restarting' => false, 'Health' => ['Status' => 'healthy'], 'StartedAt' => '2026-11-01T00:00:00Z'], 'RestartCount' => 2, 'Mounts' => [['Source' => '/mnt/user/example-share', 'Destination' => '/media', 'Type' => 'bind', 'RW' => false]], 'NetworkSettings' => ['Networks' => ['bridge' => ['IPAddress' => '192.0.2.11']]], 'Config' => ['Env' => ['SECRET=do not expose']]];
    $transport = static function (array $paths, float $deadline) use (&$calls, &$deadlineSeen, $raw, $details, $id): array {
        if ($deadlineSeen === null) $deadlineSeen = $deadline;
        workload_check($deadlineSeen === $deadline && $deadline - hrtime(true) / 1e9 <= 3.5, 'Inventory and selected inspect share bounded deadline');
        $calls[] = $paths;
        if (isset($paths['inventory'])) return ['inventory' => ['status' => 'ok', 'data' => $raw]];
        workload_check($paths === ['selected' => '/containers/' . $id . '/json'], 'One selected immutable inspect only, no peer stats or details');
        return ['selected' => ['status' => 'ok', 'data' => $details]];
    };
    $metadata = static fn(array $items): array => $native;
    $response = rackline_workloads_collect('alpha', $transport, $metadata, $folderConfiguration);
    workload_check(count($calls) === 2 && count($response['containers']) === 2 && $response['selectedStatus'] === 'ok', 'List and one optional inspect');
    workload_check($response['selected']['ports'] === ['8080→80'] && $response['selected']['storage']['pools'] === ['Share · example-share'] && $response['selected']['restartCount'] === 2, 'Selected metadata preserves addresses, ports, mounts and restart count');
    workload_check($response['selected']['stats']['available'] === false && $response['gpu']['status'] === 'unsupported' && $response['topProcesses'] === [] && $response['summary']['gpuContainers'] === null, 'Metadata endpoint never fabricates stats, processes or GPU capability');
    workload_check(!str_contains(json_encode($response), 'do not expose') && !str_contains(json_encode($response), 'Config'), 'Raw daemon details/env/argv excluded');
    $calls = []; $deadlineSeen = null;
    workload_check(rackline_workloads_collect('Alpha', $transport, $metadata, $folderConfiguration)['selectedStatus'] === 'not_found' && count($calls) === 1, 'Selected name must match exactly');
    workload_rejects(fn() => rackline_workloads_collect('../bad', $transport, $metadata, $folderConfiguration), 'Unsafe selected name rejected');
    workload_rejects(fn() => rackline_workloads_collect('', static fn() => ['inventory' => ['status' => 'ok', 'data' => ['message' => 'denied']]], $metadata, []), 'Docker error object cannot become empty success');
    $selectedContainer = $inventory[0] + ['public' => $public];
    foreach ([['Id' => $second], ['Name' => '/renamed'], ['message' => 'error'], ['State' => ['Running' => 'yes']]] as $override) workload_check(rackline_workloads_selected($selectedContainer, array_replace_recursive($details, $override)) === null, 'Selected metadata rejects stale identity or error/state payload');
    foreach ([['Running' => false], ['Paused' => true], ['Restarting' => true]] as $change) {
        $selected = rackline_workloads_selected($selectedContainer, array_replace_recursive($details, ['State' => $change]));
        workload_check($selected !== null && $selected['state'] !== 'running' && !$selected['stats']['available'] && $selected['uptimeSeconds'] === null, 'Selected stopped/restarting/paused stats remain unavailable');
    }
    workload_check($before === [hash_file('sha256', $alphaTemplate), hash_file('sha256', $cachePath), hash_file('sha256', $root . '/autostart')], 'Reads leave native templates/cache/autostart unchanged');
    $source = file_get_contents(dirname(__DIR__) . '/source/usr/local/emhttp/plugins/rackline.dashboard/include/workloads.php');
    foreach (['waz.dashboard', 'shell_exec(', 'exec(', 'file_put_contents(', 'mkdir(', 'getAllInfo(', 'new DockerClient', '/proc/'] as $forbidden) workload_check(!str_contains($source, $forbidden), 'No legacy runtime, shell, process scan or write: ' . $forbidden);
    $batch = file_get_contents(dirname(__DIR__) . '/source/usr/local/emhttp/plugins/rackline.dashboard/include/containers.php');
    workload_check(!str_contains($batch, 'waz.dashboard') && rackline_container_gpu_unavailable()['available'] === false, 'Batch has no legacy GPU cache dependency');
    workload_check(rackline_workloads_url('javascript:alert(1)') === '' && rackline_workloads_url('http://[IP]/') === '', 'Unsafe/unresolved native URLs unavailable');
    workload_check(rackline_workloads_url('http://[2001:db8::1]:8080/') === 'http://[2001:db8::1]:8080/', 'Resolved IPv6 links remain supported');
    echo "Rackline independent workload inventory, ownership and metadata tests passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($root);
}
