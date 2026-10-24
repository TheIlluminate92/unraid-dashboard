<?php
declare(strict_types=1);
define('WAZ_STORAGE_NO_MAIN', true);
require dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/storage.php';
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$fixtures=json_decode(file_get_contents(__DIR__.'/disk-location-fixtures.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($fixtures as $fixture) {
    $group=$fixture['group']; $layout=waz_storage_group_layout($group);
    foreach ($layout['cells'] as $cell) {
        if ($cell['hidden']) continue;
        $expected=$fixture['expected'][(string)$cell['sourceTray']] ?? null;
        check($cell['tray'] === $expected,'Plugin numbering mismatch: '.json_encode([$group,$cell,$expected]));
    }
}
$group=['grid_columns'=>8,'grid_rows'=>1,'tray_start_num'=>0];
$cells=waz_storage_group_layout($group)['cells'];
check(array_column($cells,'tray') === range(0,7),'Zero-based 0-7 bay labels');
check(array_column($cells,'sourceTray') === range(1,8),'Stored keys stay one-based');
check(waz_storage_location_identity_matches(['smart_serialnumber'=>'SERIAL-A'],['id'=>'MODEL_SERIAL-A']),'Matching identity');
check(!waz_storage_location_identity_matches(['smart_serialnumber'=>'SERIAL-A'],['id'=>'MODEL_SERIAL-B']),'Reject reused device name');
check(!waz_storage_location_identity_matches([],['id'=>'MODEL_SERIAL-A']),'Unknown identity remains unmapped');
echo 'Disk Location compatibility: '.count($fixtures)." numbering fixtures, zero-based labels and stable identity passed.\n";
