<?php

declare(strict_types=1);

define('WAZ_STORAGE_NO_MAIN', true);
require_once dirname(__DIR__) . '/source/usr/local/emhttp/plugins/waz.dashboard/include/storage.php';

function expect_thresholds(string $label, array $actual, float $warning, float $critical): void
{
    if ($actual['warning'] !== $warning || $actual['critical'] !== $critical) {
        throw new RuntimeException($label . ' failed: ' . json_encode($actual));
    }
}

$hdd = ['id' => 'HDD-ID', 'rotational' => '1', 'transport' => 'sas'];
$ssd = ['id' => 'SSD-ID', 'rotational' => '0', 'transport' => 'sata'];
$nvme = ['id' => 'NVME-ID', 'rotational' => '0', 'transport' => 'nvme'];
$display = ['hot' => '52', 'max' => '57', 'hotssd' => '70', 'maxssd' => '80'];

expect_thresholds('per-device HDD override', waz_storage_temperature_thresholds(
    $hdd,
    ['HDD-ID' => ['hotTemp' => '55', 'maxTemp' => '60']],
    $display
), 55.0, 60.0);
expect_thresholds('global HDD defaults', waz_storage_temperature_thresholds($hdd, [], $display), 52.0, 57.0);
expect_thresholds('global SSD defaults', waz_storage_temperature_thresholds($ssd, [], $display), 70.0, 80.0);
expect_thresholds('global NVMe defaults', waz_storage_temperature_thresholds($nvme, [], $display), 70.0, 80.0);
expect_thresholds('disabled per-device thresholds', waz_storage_temperature_thresholds(
    $hdd,
    ['HDD-ID' => ['hotTemp' => '0', 'maxTemp' => '0']],
    $display
), 0.0, 0.0);

echo "Storage temperature threshold regression tests passed.\n";
