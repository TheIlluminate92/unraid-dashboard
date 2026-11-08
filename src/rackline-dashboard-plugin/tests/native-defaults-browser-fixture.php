<?php
/** Synthetic inert source -> real parser/display projection -> browser CSS fixture. */
declare(strict_types=1);
define('RACKLINE_STORAGE_NO_MAIN', true);
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/storage.php';
$source = <<<'DEFAULTS'
<?php
$bgcolor_flash = '2468AC';
$device_bg_color = 1;
$displayinfo = array('synthetic' => 1, 'nested' => array('enabled' => true));
$description = "model\r\ncomment";
DEFAULTS;
$defaults = rackline_storage_location_defaults($source);
if ($defaults !== ['bgcolor_flash'=>'2468AC','device_bg_color'=>1]) throw new RuntimeException('Synthetic native defaults did not parse');
$settings = array_replace($defaults, ['device_bg_color'=>0]);
$display = rackline_storage_location_display(
    ['devicenode'=>'/dev/sdb','model_name'=>'Synthetic model','comment'=>'Synthetic alias'],
    [], $settings, ['name'=>'fixture-boot-alias','type'=>'Boot']
);
if ($display['color'] !== '#2468ac' || !$display['identityVerified']) throw new RuntimeException('Native default projection mismatch');
echo json_encode(['display'=>$display,'expectedBackground'=>'rgb(36, 104, 172)','expectedForeground'=>'rgb(255, 255, 255)'], JSON_THROW_ON_ERROR), "\n";
