<?php
declare(strict_types=1);
define('WAZ_STORAGE_NO_MAIN', true);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/storage.php';
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$ata = waz_storage_parse_smart_cache("  5 Reallocated_Sector_Ct 0x0033 100 100 036 Pre-fail Always - 7\n  9 Power_On_Hours 0x0032 097 097 000 Old_age Always - 2912\n", 100);
check($ata['available'] && $ata['health'] === null, 'Attributes must not imply passed SMART health');
check($ata['attributes']['Reallocated sectors'] === '7' && $ata['attributes']['Power-on hours'] === '2912', 'ATA raw columns');
check($ata['sampledAtMs'] === 100000, 'Use cache timestamp, not snapshot time');
$nvme = waz_storage_parse_smart_cache("SMART overall-health self-assessment test result: PASSED\nPercentage Used: 4%\nMedia and Data Integrity Errors: 2\n", 200);
check($nvme['health'] === 'PASSED' && $nvme['attributes']['Percentage Used'] === '4%', 'NVMe cache');
$sas = waz_storage_parse_smart_cache("SMART Health Status: OK\nElements in grown defect list: 0\n", 300);
check($sas['health'] === 'OK' && $sas['attributes']['Elements in grown defect list'] === '0', 'SAS cache');
check(!waz_storage_parse_smart_cache('Drive is in STANDBY mode', 100)['available'], 'Missing attributes stay unavailable');
check(!waz_storage_cached_smart('../disks.ini')['available'], 'No cache path traversal');
$colors = waz_storage_pool_colors(['cache','mnemosyne','elysium','aegis','custom','other']);
check(count(array_unique($colors)) === 6, 'Pools have distinct assignment colors');
check(waz_storage_pool_colors(['mnemosyne','cache'])['cache'] === $colors['cache'], 'Known colors survive pool order changes');
echo "Cached SMART and pool color regressions passed.\n";
