<?php
declare(strict_types=1);
define('WAZ_STORAGE_NO_MAIN', true);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/storage.php';
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function disk(string $name, string $type, string $model, float $capacity): array {
    return ['name' => $name, 'type' => $type, 'model' => $model, 'capacityBytes' => $capacity, 'identityColor' => '#badbad', 'usagePercent' => 99];
}
$disks = waz_storage_apply_array_colors([
    disk('parity', 'Parity', 'ST24000NM002H-3KS133', 24000277250048),
    disk('disk1', 'Data', 'ST24000NM000H-3KS103', 24000277250048),
    disk('disk2', 'Data', 'ST24000NM000C-3WD103', 24000277250048),
    disk('disk3', 'Data', 'ST14000NM001G-2KJ103', 14000519643136),
    disk('disk4', 'Data', 'OTHER-24TB', 24000277250048),
    array_merge(disk('cache', 'Cache', 'SSD', 1e12), ['identityColor' => '#a78bfa']),
]);
check(array_column(array_slice($disks, 0, 5), 'identityGroup') === ['parity', '24tb-h', '24tb-c', '14tb', 'other'], 'Live R730 families classify independently');
check(count(array_unique(array_column(array_slice($disks, 0, 5), 'identityColor'))) === 5, 'Five separate reserved colors');
check($disks[0]['identityColor'] === '#e55454', 'Parity takes precedence over H-series');
check($disks[5]['identityColor'] === '#a78bfa' && !isset($disks[5]['identityGroup']), 'Preserve pool colors');
check(waz_storage_array_identity(['type' => 'Data', 'id' => 'ST24000NM000C-3WD103_SERIAL'])['identityGroup'] === '24tb-c', 'Native identifier fallback');
check(waz_storage_array_identity(disk('disk99', 'Data', 'Another vendor', 14000519643136))['identityGroup'] === '14tb', '14 TB physical capacity fallback');
check(waz_storage_array_identity(['type' => 'Data', 'sizeBytes' => 14e12, 'usedBytes' => 14e12])['identityGroup'] === 'other', 'Formatted/used capacity must not classify identity');
$swapped = waz_storage_apply_array_colors([disk('disk1', 'Data', 'ST24000NM000C-3WD103', 24e12)]);
check($swapped[0]['identityColor'] === $disks[2]['identityColor'], 'A replaced drive follows its model rather than old slot color');
$legend = waz_storage_array_color_legend(array_slice($disks, 0, 4));
check(count($legend) === 5 && $legend[4]['count'] === 0 && $legend[0]['count'] === 1, 'Legend reserves the unused fourth blue');
check(waz_storage_array_color_legend([$disks[5]]) === [], 'Pool-only systems omit array palette');
echo "Array color-family regressions passed.\n";
