<?php
define('RACKLINE_IO_NO_MAIN', true);
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/io.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$line = '8 0 sda 10 0 20 5 30 0 40 6 2 7 8';
$result = rackline_io_parse($line, "123.45 80.20\n", 1000);
check($result['status'] === 'ok', 'valid snapshot');
$device = $result['devices']->sda;
check($device['readBytes'] == 10240 && $device['writeBytes'] == 20480, 'Linux sectors always 512 bytes');
check($device['readOperations'] == 10 && $device['writeOperations'] == 30, 'operation indices');
check($device['inFlight'] == 2 && $device['busyMs'] == 7 && $device['weightedBusyMs'] == 8, 'busy counters');
check($result['monotonicSeconds'] === 123.45 && $result['sampledAtMs'] === 1000, 'timestamps');
$names = ['sda1', 'nvme0n1', 'nvme0n1p1', 'dm-0', 'md127', 'loop0', 'cciss!c0d0'];
foreach ($names as $name) {
    $r = rackline_io_parse(str_replace('sda', $name, $line), '1 0', 1);
    check(isset($r['devices']->$name), 'preserve exact kernel device name');
}
foreach ([false, '', '8 0 sda 1', $line . "\n" . $line,
          str_replace('20', '-1', $line), str_replace('20', '1e6', $line),
          str_replace('20', '17592186044416', $line), str_replace('sda', '../sda', $line)] as $bad) {
    $r = rackline_io_parse($bad, '1 0', 1);
    check($r['status'] === 'unavailable' && count((array) $r['devices']) === 0, 'bad counters unavailable');
}
foreach ([false, '', '-1 0', 'NaN 0', '1x 0', '1 0 garbage'] as $bad) {
    check(rackline_io_parse($line, $bad, 1)['status'] === 'unavailable', 'bad uptime unavailable');
}
check(rackline_io_parse($line . ' 0 0 0 0 0 0', '1 0', 1)['status'] === 'ok', 'modern discard/flush fields');
$zero = '8 0 sda 0 0 0 0 0 0 0 0 0 0 0';
check(rackline_io_parse($zero, '0 0', 1)['devices']->sda['readBytes'] == 0, 'real zero accepted');
// Collector emits counters without state: reboot/reset is observable, never converted into a rate.
$a = rackline_io_parse($line, '100 50', 10);
$b = rackline_io_parse($zero, '1 0', 20);
check($b['monotonicSeconds'] < $a['monotonicSeconds'], 'reboot clock reset retained');
check($b['devices']->sda['readBytes'] < $a['devices']->sda['readBytes'], 'counter reset retained');
echo "I/O parser tests passed\n";
