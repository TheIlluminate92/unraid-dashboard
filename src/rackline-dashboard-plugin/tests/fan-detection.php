<?php
// Fixed-path bootstrap detection against temporary fixtures, never a controller.
declare(strict_types=1);
ob_start();require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/view.php';ob_end_clean();
$base = sys_get_temp_dir() . '/rackline-fan-detection-' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);$checks=0;
function check_detection(?bool $expected, string $path, string $label): void {
    global $checks;
    $actual=rackline_view_fan_available($path);if($actual!==$expected)throw new RuntimeException($label . ': ' . var_export($actual,true));$checks++;
}
function clean_detection(string $path): void {
    if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $entry)if($entry!=='.'&&$entry!=='..')clean_detection($path.'/'.$entry);rmdir($path);}else unlink($path);
}
try {
    check_detection(null,$base.'/uninspectable','missing parent is unknown');
    file_put_contents($base.'/file-parent','fixture');check_detection(null,$base.'/file-parent','non-directory parent is unknown');
    mkdir($base.'/plugins');$plugins=$base.'/plugins';check_detection(false,$plugins,'complete parent confirms missing plugin');
    mkdir($plugins.'/md12xx.fancontrol');check_detection(false,$plugins,'complete plugin confirms missing include');
    mkdir($plugins.'/md12xx.fancontrol/include');check_detection(false,$plugins,'complete include confirms missing API');
    $api=$plugins.'/md12xx.fancontrol/include/api.php';mkdir($api);check_detection(null,$plugins,'non-regular API is unknown');rmdir($api);
    symlink($base.'/not-present',$api);check_detection(null,$plugins,'dangling API link is unknown');unlink($api);
    file_put_contents($api,'<?php // synthetic controller presence');check_detection(true,$plugins,'regular readable API detected');unlink($api);
    rmdir($plugins.'/md12xx.fancontrol/include');symlink($base.'/not-present',$plugins.'/md12xx.fancontrol/include');check_detection(null,$plugins,'dangling include is unknown');unlink($plugins.'/md12xx.fancontrol/include');
    mkdir($base.'/bounded');for($i=0;$i<1100;$i++)file_put_contents($base.'/bounded/entry-'.$i,'');check_detection(null,$base.'/bounded','bounded incomplete scan cannot claim absence');
    echo "Fan bootstrap detection: $checks fixture checks passed\n";
} finally { clean_detection($base); }
