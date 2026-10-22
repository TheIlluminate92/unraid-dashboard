<?php
declare(strict_types=1);
define('WAZ_STORAGE_NO_MAIN', true);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/storage.php';
function check_pool(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
}
$empty = ['status' => 'DISK_NP', 'device' => '', 'id' => '', 'size' => '0'];
check_pool(waz_storage_unassigned_slot($empty), 'Unassigned placeholder excluded');
foreach (['id' => 'ASSIGNED-ID', 'size' => '1000', 'device' => 'sda', 'status' => 'DISK_DSBL'] as $key => $value) {
    check_pool(!waz_storage_unassigned_slot(array_replace($empty, [$key => $value])), 'Assigned/missing evidence preserved: ' . $key);
}
$none = waz_storage_parity_data([], [], []);
check_pool(!waz_storage_array_data([], ['mdState' => 'STARTED'])['configured'], 'Pool-only has no array');
check_pool(!waz_storage_array_data([['type' => 'Cache']], ['mdState' => 'STARTED'])['configured'], 'Pool disks do not configure an array');
check_pool(waz_storage_array_data([['type' => 'Parity', 'state' => 'fault']], ['mdState' => 'STARTED'])['configured'], 'Missing assigned parity keeps array section visible');
check_pool(!$none['configured'] && !$none['valid'] && $none['state'] === 'normal', 'Pool-only parity is unconfigured, not valid or faulted');
check_pool(waz_storage_attention(waz_storage_array_data([], ['mdState' => 'STARTED']), $none, [])['count'] === 0, 'Unconfigured parity produces no phantom attention message');
$disk = ['type' => 'Parity', 'name' => 'parity', 'state' => 'normal'];
$healthy = waz_storage_parity_data([$disk], [], []);
check_pool($healthy['configured'] && $healthy['valid'], 'Configured healthy parity retained');
$fault = waz_storage_parity_data([array_replace($disk, ['state' => 'fault'])], [], []);
check_pool(!$fault['valid'] && $fault['state'] === 'fault', 'Failed assigned parity remains a fault');
$missing = waz_storage_parity_data([$disk], ['mdNumMissing' => '1'], []);
check_pool(!$missing['valid'] && $missing['state'] === 'fault', 'Missing assigned disks remain faults');
echo "Pool-only parity regression tests passed.\n";
