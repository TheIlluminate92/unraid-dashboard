<?php
/** Offline synthetic fixtures only. No real Unraid paths are read or written. */
declare(strict_types=1);
define('RACKLINE_STORAGE_NO_MAIN', true);
require __DIR__ . '/../source/usr/local/emhttp/plugins/rackline.dashboard/include/storage.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function native_disk(array $overrides = []): array {
    // Distinct synthetic disk names represent distinct physical devices unless a
    // mapping fixture explicitly supplies its node. Duplicate tests live in host-stress.
    if (isset($overrides['name']) && $overrides['name'] !== 'disk1' && !array_key_exists('device', $overrides))
        $overrides['device'] = 'fixture-' . $overrides['name'];
    return array_replace(['name' => 'disk1', 'idx' => '1', 'device' => 'sdb', 'id' => 'MODEL_FIXTURE-A',
        'type' => 'Data', 'status' => 'DISK_OK', 'numErrors' => '0', 'temp' => '34', 'color' => 'green-on',
        'rotational' => '1', 'transport' => 'sas', 'size' => '2000000000', 'fsType' => 'xfs',
        'fsStatus' => 'Mounted', 'fsSize' => '1000000', 'fsUsed' => '250000', 'fsFree' => '750000'], $overrides);
}
function ini_text(array $values, bool $sections = true): string {
    $text = '';
    foreach ($values as $key => $value) {
        if ($sections) { $text .= '[' . $key . "]\n"; $text .= ini_text($value, false); }
        else $text .= $key . '="' . str_replace('"', '\\"', (string) $value) . "\"\n";
    }
    return $text;
}
function throws(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
$temporary = sys_get_temp_dir() . '/rackline-storage-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$files = [];
$directories = [$temporary];
$write = static function (string $path, string $contents) use (&$files): void {
    file_put_contents($path, $contents);
    $files[] = $path;
};
try {
    $sources = rackline_storage_sources();
    foreach ($sources as $key => $_) $sources[$key] = $temporary . '/' . $key;
    foreach (['smartCache', 'pools', 'locations'] as $key) { mkdir($sources[$key], 0700); $directories[] = $sources[$key]; }
    $smartPath = $sources['smartCache'] . '/disk1';
    $smartText = "Serial Number: FIXTURE-A\n  5 Reallocated_Sector_Ct 0x0033 100 100 036 Pre-fail Always - 7\n  9 Power_On_Hours 0x0032 097 097 000 Old_age Always - 2912\n";
    $write($smartPath, $smartText);
    touch($smartPath, 100);
    foreach ([null, false, true, '', [], INF, NAN, '1e999'] as $value) check(rackline_storage_number($value) === null, 'Unknown/nonfinite numbers remain null');
    check(rackline_storage_number('0') === 0.0 && rackline_storage_number('1,024') === 1024.0, 'Native numeric values');
    check(rackline_storage_kib_bytes('2') === 2048.0, 'Native KiB converted to bytes');
    check(rackline_storage_kib_bytes('-1') === null && rackline_storage_kib_bytes('1e307') === null, 'Invalid or overflowing capacity stays unknown');
    foreach ([null, '', 'unavailable', '-1', '0.5', '1e99'] as $value) check(rackline_storage_count($value) === null, 'Invalid count never becomes a healthy zero');
    check(rackline_storage_usage(100.0, null, 40.0) === 60.0, 'Free-only usage');
    check(rackline_storage_usage(null, 10.0, 20.0) === null, 'Unknown capacity is not zero');
    check(rackline_storage_usage(100.0, null, 101.0) === null, 'Free larger than capacity is not empty');
    check(rackline_storage_usage(100.0, 101.0, null) === null, 'Used larger than capacity is not full');
    check(rackline_storage_usage(100.0, 20.0, 101.0) === null, 'Impossible free is not masked by plausible used');
    check(rackline_storage_usage(100.0, 20.0, 90.0) === 20.0, 'Filesystem accounting need not sum exactly to capacity');

    $ata = rackline_storage_parse_smart_cache($smartText, 100);
    check($ata['available'] && $ata['health'] === null, 'Attributes must not imply passed health');
    check($ata['attributes']['Reallocated sectors'] === '7' && $ata['attributes']['Power-on hours'] === '2912', 'ATA raw SMART attributes');
    check($ata['sampledAtMs'] === 100000, 'SMART timestamp belongs to cached report');
    $nvme = rackline_storage_parse_smart_cache("SMART overall-health self-assessment test result: PASSED\nPercentage Used: 4%\nMedia and Data Integrity Errors: 2\n", 200);
    check($nvme['health'] === 'PASSED' && $nvme['attributes']['Percentage Used'] === '4%', 'NVMe SMART cache');
    $sas = rackline_storage_parse_smart_cache("SMART Health Status: OK\nElements in grown defect list: 0\n", 300);
    check($sas['health'] === 'OK' && $sas['attributes']['Elements in grown defect list'] === '0', 'SAS SMART cache');
    check(!rackline_storage_parse_smart_cache('Drive is in STANDBY mode', 100)['available'], 'Standby-only report is unavailable');
    $cached = rackline_storage_cached_smart('disk1', native_disk(), $sources['smartCache']);
    check(array_intersect_key($cached, $ata) === $ata && $cached['cachedAtMs'] === 100000, 'Read matching SMART cache only');
    check(str_starts_with($cached['reportUrl'], '/plugins/rackline.dashboard/include/smart-report.php?name=disk1&identity='), 'Fixed cached report route');
    check(!str_contains($cached['reportUrl'], 'FIXTURE-A'), 'No raw serial in report link');
    foreach ([['id' => 'MODEL_FIXTURE-B'], ['id' => 'MODEL_FIXTURE-ABC'], ['id' => ''], ['serial' => 'FIXTURE-B']] as $overrides)
        check(!rackline_storage_cached_smart('disk1', native_disk($overrides), $sources['smartCache'])['available'], 'Reject missing or mismatched cache identity');
    check(!rackline_storage_cached_smart('../disks', native_disk(), $sources['smartCache'])['available'], 'No SMART path traversal');
    check(!rackline_storage_cached_smart('disk2', native_disk(), $sources['smartCache'])['available'], 'Missing cache stays unavailable');
    check(rackline_storage_serial_matches('FIXTURE-A', ['id' => 'MODEL_FIXTURE-A_WWN']), 'SAS WWN suffix identity');
    check(rackline_storage_serial_matches('FIXTURE-A', ['serial' => 'FIXTURE-A']), 'Explicit current serial identity');
    check(!rackline_storage_serial_matches('', ['id' => 'MODEL_FIXTURE-A']), 'Empty serial never matches');
    foreach (['device', 'id', 'serial', 'model'] as $identityField) {
        foreach (["\x80", "\x81", "\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80"] as $invalidUtf8) {
            check(rackline_storage_smart_identity('disk1', native_disk([$identityField => 'MODEL_' . $invalidUtf8])) === null,
                'Malformed UTF-8 identity never receives a selector');
        }
    }
    $unicodeA = rackline_storage_smart_identity('disk1', native_disk(['model' => "Fixture \u{00E9}"]));
    $unicodeB = rackline_storage_smart_identity('disk1', native_disk(['model' => "Fixture e\u{0301}"]));
    check(is_string($unicodeA) && is_string($unicodeB) && $unicodeA !== $unicodeB, 'Distinct valid UTF-8 identities are not normalized together');

    $write($sources['smartCache'] . '/crlf', "Serial number: FIXTURE-A\r\nSMART Health Status: OK\r\n");
    check(rackline_storage_cached_smart('crlf', native_disk(), $sources['smartCache'])['health'] === 'OK', 'CRLF serial identity');
    $write($sources['smartCache'] . '/no-identity', "SMART Health Status: OK\n");
    check(!rackline_storage_cached_smart('no-identity', native_disk(), $sources['smartCache'])['available'], 'Slot name alone cannot validate SMART identity');
    $write($sources['smartCache'] . '/oversized', $smartText . str_repeat('x', 262145));
    check(!rackline_storage_cached_smart('oversized', native_disk(), $sources['smartCache'])['available'], 'Oversized cache is not parsed partially');

    // Audited native logical caches may contain attributes only, with no serial.
    // Parsing possible values is not permission to attribute them to this disk.
    foreach ([
        'attribute-ata' => "  5 Reallocated_Sector_Ct 0x0033 100 100 036 Pre-fail Always - 0\n  9 Power_On_Hours 0x0032 099 099 000 Old_age Always - 123\n",
        'attribute-sas' => "Elements in grown defect list: 0\n",
    ] as $cacheName => $attributeText) {
        $attributePath = $sources['smartCache'] . '/' . $cacheName;
        $write($attributePath, $attributeText);
        touch($attributePath, 100);
        $parsedAttributes = rackline_storage_parse_smart_cache($attributeText, 100);
        check($parsedAttributes['available'] && $parsedAttributes['health'] === null, 'Attribute-only text is parsable without inventing health');
        $unverified = rackline_storage_cached_smart($cacheName, native_disk(), $sources['smartCache']);
        check($unverified === ['available' => false, 'cacheStatus' => 'identity_unverified', 'cachePresent' => true,
            'cachedAtMs' => 100000, 'unavailableReason' => 'missing_report_identity'], 'Present unverified cache exposes only status and file age');
        check(rackline_storage_smart_report($cacheName, native_disk(), $sources['smartCache']) === null, 'No report body for unverified identity');
    }
    $write($sources['smartCache'] . '/sdb', $smartText);
    check(rackline_storage_cached_smart('missing-logical', native_disk(), $sources['smartCache']) ===
        ['available' => false, 'cacheStatus' => 'missing', 'cachePresent' => false], 'Device-keyed reports never substitute for absent logical cache');
    check($cached['cacheStatus'] === 'verified' && $cached['cachePresent'] === true, 'Verified metadata retains cache presence');

    $display = ['hot' => '52', 'max' => '57', 'hotssd' => '70', 'maxssd' => '80'];
    check(rackline_storage_temperature_thresholds(native_disk(), [], $display) === ['warning' => 52.0, 'critical' => 57.0], 'HDD defaults from Dynamix');
    check(rackline_storage_temperature_thresholds(native_disk(['rotational' => '0']), [], $display) === ['warning' => 70.0, 'critical' => 80.0], 'SSD defaults from Dynamix');
    check(rackline_storage_temperature_thresholds(native_disk(['transport' => 'nvme']), [], $display) === ['warning' => 70.0, 'critical' => 80.0], 'NVMe defaults from Dynamix');
    check(rackline_storage_temperature_thresholds(native_disk(), ['MODEL_FIXTURE-A' => ['hotTemp' => '0', 'maxTemp' => '0']], $display) === ['warning' => 0.0, 'critical' => 0.0], 'Explicit disabled thresholds');
    check(rackline_storage_temperature_thresholds(native_disk(), ['MODEL_FIXTURE-A' => ['hotTemp' => '55', 'maxTemp' => '60']], $display) === ['warning' => 55.0, 'critical' => 60.0], 'Per-device threshold priority');
    $raw = [native_disk(), native_disk(['name' => 'disk2', 'temp' => '*', 'color' => '']),
        native_disk(['name' => 'disk3', 'temp' => '*', 'color' => 'green-blink']),
        native_disk(['name' => 'disk4', 'temp' => '*', 'color' => 'green-on']),
        native_disk(['name' => 'disk5', 'temp' => '60']),
        native_disk(['name' => 'disk6', 'numErrors' => '2']),
        ['name' => 'disk7', 'type' => 'Data', 'status' => 'DISK_NP', 'device' => '', 'id' => '', 'size' => '0']];
    $disks = rackline_storage_disk_records($raw, [], [], $sources['smartCache']);
    foreach ($disks as $diskRecord) check(is_string($diskRecord['selectionToken']) && preg_match('/^[a-f0-9]{64}$/D', $diskRecord['selectionToken']), 'Every current disk has an opaque selection token independent of report availability');
    check(count($disks) === 6, 'Unassigned placeholders excluded');
    check($disks[0]['location'] === null && $disks[0]['smart']['health'] === null, 'Root record nullable location and health');
    check($disks[1]['temperatureC'] === null && $disks[1]['spunDown'] === false && $disks[1]['statusLabel'] === 'UNKNOWN', 'Missing temperature never proves standby or activity');
    check($disks[2]['spunDown'] === true && $disks[2]['statusLabel'] === 'STANDBY', 'Native standby marker retained');
    check(!$disks[3]['spunDown'] && $disks[3]['statusLabel'] === 'ACTIVE', 'Explicit active marker without temperature');
    check($disks[4]['state'] === 'fault' && $disks[5]['state'] === 'fault', 'Temperature and error faults retained');
    $assigned = rackline_storage_disk_records([native_disk(['status' => 'DISK_NP', 'device' => '', 'temp' => '*'])], [], [], $sources['smartCache']);
    check(count($assigned) === 1 && $assigned[0]['state'] === 'fault', 'Assigned missing disk retained');
    $incompleteRaw = native_disk();
    foreach (['status', 'numErrors', 'fsUsed', 'fsFree'] as $key) unset($incompleteRaw[$key]);
    $incomplete = rackline_storage_disk_records([$incompleteRaw], [], [], $sources['smartCache']);
    check($incomplete[0]['errors'] === null && $incomplete[0]['usedBytes'] === null && $incomplete[0]['usagePercent'] === null && $incomplete[0]['state'] === 'unknown', 'Missing counters and status do not fabricate a healthy disk');
    foreach (['garbage', '-1', '0.5'] as $value) {
        $invalid = rackline_storage_disk_records([native_disk(['numErrors' => $value])], [], [], $sources['smartCache']);
        check($invalid[0]['errors'] === null && $invalid[0]['state'] === 'unknown', 'Malformed error count is unknown');
    }
    $completeVariables = ['mdState' => 'STARTED', 'mdNumMissing' => '0', 'mdNumDisabled' => '0', 'mdNumInvalid' => '0'];
    $unknownTotals = rackline_storage_array_data($incomplete, $completeVariables);
    check($unknownTotals['sizeBytes'] === 1024000000.0 && $unknownTotals['usedBytes'] === null && $unknownTotals['usagePercent'] === null && $unknownTotals['state'] === 'unknown', 'Capacity alone never becomes zero used');
    $partialDisks = rackline_storage_disk_records([native_disk(), native_disk(['name' => 'disk2', 'fsSize' => '', 'fsUsed' => '', 'fsFree' => ''])], [], [], $sources['smartCache']);
    $partialTotals = rackline_storage_array_data($partialDisks, $completeVariables);
    check($partialTotals['sizeBytes'] === null && $partialTotals['usedBytes'] === null && $partialTotals['usagePercent'] === null, 'Partial member totals never appear as complete totals');
    $freeOnly = rackline_storage_disk_records([native_disk(['fsUsed' => ''])], [], [], $sources['smartCache']);
    check(rackline_storage_array_data($freeOnly, $completeVariables)['usedBytes'] === 256000000.0, 'Known free plus known capacity can derive used bytes');
    $zeroUsed = rackline_storage_disk_records([native_disk(['fsUsed' => '0', 'fsFree' => ''])], [], [], $sources['smartCache']);
    check(rackline_storage_array_data($zeroUsed, $completeVariables)['usagePercent'] === 0.0, 'Reported zero use remains real zero');
    $missingCounts = rackline_storage_array_data($zeroUsed, ['mdState' => 'STARTED']);
    check($missingCounts['missingCount'] === null && $missingCounts['disabledCount'] === null && $missingCounts['invalidCount'] === null && $missingCounts['state'] === 'unknown', 'Missing array health counters stay unknown');
    foreach ([['fsUsed' => '', 'fsFree' => '1000001'], ['fsUsed' => '1000001', 'fsFree' => ''], ['fsUsed' => '100', 'fsFree' => '1000001']] as $overrides) {
        $impossible = rackline_storage_disk_records([native_disk($overrides)], [], [], $sources['smartCache']);
        $totals = rackline_storage_array_data($impossible, $completeVariables);
        check($impossible[0]['usagePercent'] === null, 'Impossible per-disk capacity remains unknown');
        check($totals['usedBytes'] === null && $totals['usagePercent'] === null && $totals['state'] === 'unknown', 'Impossible capacity cannot become a valid array aggregate');
    }

    $fixtures = json_decode(file_get_contents(__DIR__ . '/storage-layout-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($fixtures as $fixture) foreach (rackline_storage_group_layout($fixture['group'])['cells'] as $cell) {
        if ($cell['hidden']) { check($cell['tray'] === null, 'Hidden cells have null displayed tray'); continue; }
        check($cell['tray'] === ($fixture['expected'][(string) $cell['sourceTray']] ?? null), 'Disk Location numbering fixture');
    }
    $group = ['grid_columns' => 3, 'grid_rows' => 2, 'tray_start_num' => 0, 'hide_tray' => [2 => true], 'group_name' => 'Fixture shelf'];
    $layout = rackline_storage_group_layout($group);
    check(array_column($layout['cells'], 'sourceTray') === range(1, 6), 'Source bay keys stay one-based');
    check(array_column($layout['cells'], 'tray') === [0, null, 1, 2, 3, 4], 'Display labels skip hidden cell');
    $bypassed = rackline_storage_group_layout(array_replace($group, ['count_bypass_tray' => true]));
    check(array_column($bypassed['cells'], 'tray') === [0, null, 2, 3, 4, 5], 'Hidden bay counting setting retained');
    // Count bypassed slots is a per-group setting, not a global override.
    $nativeGroup = ['grid_columns' => '6', 'grid_rows' => '2', 'grid_count' => 'column',
        'tray_direction' => '1', 'tray_start_num' => '0', 'hide_tray' => [1 => '1', 2 => '1'], 'count_bypass_tray' => '1'];
    $emptyDisks = [];
    $mixed = rackline_storage_location_data($emptyDisks, [], ['1' => $nativeGroup,
        '2' => array_replace($nativeGroup, ['count_bypass_tray' => '0'])], [], [], ['count_bypass_tray' => true]);
    check(array_column($mixed['groups'][0]['cells'], 'tray') === [null,2,4,6,8,10,null,3,5,7,9,11], 'Native counted hidden trays retain full grid numbering');
    check(array_column($mixed['groups'][1]['cells'], 'tray') === [null,0,2,4,6,8,null,1,3,5,7,9], 'Each group keeps independent skip numbering despite global key');
    check(array_column($mixed['groups'][0]['cells'], 'sourceTray') === [1,3,5,7,9,11,2,4,6,8,10,12], 'Hidden columns keep their source positions');
    check(!rackline_storage_group_layout(array_replace($nativeGroup, ['count_bypass_tray' => []]))['cells'], 'Nested bypass setting fails closed');
    foreach ([null, '', 'yes', 2, -1, 1.0, [], ['1']] as $bad) {
        $emptyDisks = [];
        check(!rackline_storage_location_data($emptyDisks, [], ['1' => array_replace($nativeGroup, ['count_bypass_tray' => $bad])], [], [])['available'], 'Malformed explicit group bypass must not silently renumber');
    }
    foreach ([false, 0, '0', true, 1, '1'] as $valid) check(rackline_storage_location_flag($valid) === in_array($valid, [true,1,'1'], true), 'Exact native group flag encodings');
    $defaultsText = <<<'DEFAULTS'
<?php
// $bgcolor_flash = 'ffffff';
/* $bgcolor_flash = 'eeeeee'; */
$bgcolor_flash = '123ABC'; // allowed literal
$device_bg_color = 0;
$bgcolor_cache = "112233";
DEFAULTS;
    $defaults = rackline_storage_location_defaults($defaultsText);
    check($defaults === (function_exists('token_get_all') ? ['bgcolor_flash'=>'123ABC', 'device_bg_color'=>0, 'bgcolor_cache'=>'112233'] : []), 'Native literal defaults use optional tokenizer or stay unknown');
    foreach (["\n\$bgcolor_flash = 'ffffff';", "\n\$bgcolor_flash =\n'ffffff';", "\n\$bgcolor_flash .= 'ff';", "\n\$bgcolor_flash = getenv('ANY');"] as $extra)
        check(!isset(rackline_storage_location_defaults($defaultsText . $extra)['bgcolor_flash']), 'Duplicate or unsupported native assignments stay unknown');
    check(rackline_storage_location_defaults("<?php\nif (false)\n\$bgcolor_flash = '123abc';") === [], 'Conditional defaults not guessed');
    check(rackline_storage_location_defaults(str_repeat('x',262145)) === [], 'Native defaults read bounded');
    foreach (["return;\n", "include_once 'unknown.php';\n", "extract(['bgcolor_cache'=>'abcdef']);\n", "try { throw new Exception(); } finally {}\n"] as $statement)
        check(rackline_storage_location_defaults("<?php\n".$statement."\$bgcolor_cache='abcdef';") === [], 'Unsupported PHP statement fails all optional defaults closed');

    check(rackline_storage_location_defaults("<?php\n\$notes = 'literal text\n\$bgcolor_cache=\"ffffff\";\n';\n") === [], 'Multiline string bodies are never assignments');
    check(!isset(rackline_storage_location_defaults($defaultsText . "\nunset(\$bgcolor_flash);")['bgcolor_flash']), 'Unset invalidates literal default');
    check(rackline_storage_location_defaults("<?php\n\$bgcolor_flash='123abc';\n/* unterminated") === [], 'Unterminated source comment fails closed');

    $styleSettings = ['device_bg_color'=>0, 'bgcolor_flash'=>'123abc','bgcolor_cache'=>'112233','bgcolor_empty'=>'222222', 'bgcolor_others'=>'333333', 'bgcolor_unraid'=>'444444','bgcolor_parity'=>'555555'];
    $styleDevice = ['devicenode'=>'/dev/sdb','model_name'=>'Synthetic model','comment'=>'<b>cached text only</b>'];
    $styleNative = ['name'=>'flash','type'=>'Boot','temp'=>'40','hotTemp'=>'45','maxTemp'=>'55'];
    check(rackline_storage_location_display($styleDevice, [], $styleSettings, $styleNative)['color'] === '#123abc', 'Native Boot alias uses configured native color');
    check(rackline_storage_location_display($styleDevice, ['group_color'=>'abcdef'], $styleSettings, $styleNative)['color'] === '#abcdef', 'Group override precedes type color');
    check(rackline_storage_location_display($styleDevice + ['color'=>'fedcba'], ['group_color'=>'abcdef'], $styleSettings, $styleNative)['color'] === '#fedcba', 'Device override precedes group');
    foreach ([40=>'#112233',45=>'#444444',55=>'#555555'] as $temp=>$expectedColor)
        check(rackline_storage_location_display($styleDevice + ['color'=>'fedcba'], ['group_color'=>'abcdef'], array_replace($styleSettings,['device_bg_color'=>1]), array_replace($styleNative,['temp'=>(string)$temp]))['color'] === $expectedColor, 'Native heatmap ignores custom overrides');
    $unverifiedDisplay = rackline_storage_location_display($styleDevice + ['color'=>'abcdef'], [], $styleSettings, null);
    check(!$unverifiedDisplay['identityVerified'] && $unverifiedDisplay['name'] === null && $unverifiedDisplay['type'] === null && $unverifiedDisplay['color'] === '#abcdef', 'Unverified cache style does not become native identity');
    check($unverifiedDisplay['comment'] === '<b>cached text only</b>', 'Cached comment stays text for escaped rendering');
    foreach ([null,'', 'wrong', []] as $mode) check(rackline_storage_location_display($styleDevice, [], array_replace($styleSettings,['device_bg_color'=>$mode]), $styleNative)['color'] === null, 'Unknown mode has no invented color');
    $emptyDisks=[];
    $verticalMap = rackline_storage_location_data($emptyDisks, [], ['1'=>array_replace($nativeGroup, ['disk_tray_direction'=>'v','tray_width'=>'300','tray_height'=>'95','tray_align'=>'right','tray_align_txt'=>'hide','tray_pos'=>'none'])], [], [], $styleSettings);
    check($verticalMap['groups'][0]['trayWidth'] === 95 && $verticalMap['groups'][0]['trayHeight'] === 300 && $verticalMap['groups'][0]['nameAlign'] === 'hide', 'Native vertical dimensions and alignment retained');
    // Dynamic generic geometry matrix: different enclosures, count modes,
    // directions, start labels, and per-group flags share no host-specific slots.
    foreach ([[1,12],[12,1],[6,2],[4,6],[2,2],[100,1]] as [$cols,$rows]) foreach (['row','column'] as $order) foreach ([1,2,3,4] as $direction) foreach ([false,true] as $bypass) {
        $shape = ['grid_columns'=>$cols,'grid_rows'=>$rows,'grid_count'=>$order,'tray_direction'=>$direction,'tray_start_num'=>17,'hide_tray'=>[1=>1,2=>1],'count_bypass_tray'=>$bypass];
        $shapeResult = rackline_storage_group_layout($shape);
        check(count($shapeResult['cells']) === $cols*$rows, 'Dynamic layout preserves exact enclosure cell count');
        $sourceKeys = array_column($shapeResult['cells'],'sourceTray'); sort($sourceKeys);
        check($sourceKeys === range(1,$cols*$rows), 'Dynamic source tray keys never duplicate or disappear');
        foreach ($shapeResult['cells'] as $cell) if ($cell['sourceTray'] <= 2) check($cell['hidden'] && $cell['tray'] === null, 'Dynamic hidden positions stay reserved');
        $labels = array_values(array_filter(array_column($shapeResult['cells'],'tray'), static fn($x): bool=>$x!==null));
        check(count(array_unique($labels)) === count($labels), 'Dynamic displayed labels remain unique');
        if (!$bypass && count($labels)) { sort($labels); check($labels === range(17,16+count($labels)), 'Skipped hidden labels form native contiguous numbering'); }
    }
    $emptyDisks=[];
    $ordered = rackline_storage_location_data($emptyDisks, [], ['9'=>$nativeGroup,'shelf-next'=>$nativeGroup,'2'=>$nativeGroup], [], []);
    check(array_column($ordered['groups'],'id') === ['9','shelf-next','2'], 'Persisted group iteration order is authoritative, not numeric sorting');
    $groups = ['1' => $group];
    $locations = ['valid' => ['groupid' => '1', 'tray' => 1], 'hidden' => ['groupid' => '1', 'tray' => 2],
        'stale' => ['groupid' => '1', 'tray' => 3], 'inventory-only' => ['groupid' => '1', 'tray' => 4],
        'removed' => ['groupid' => '1', 'tray' => 5]];
    $device = ['smart_serialnumber' => 'FIXTURE-A', 'devicenode' => '/dev/sdb', 'model_name' => 'Fixture model', 'capacity' => '2000000000', 'color' => '123456'];
    $devices = ['valid' => $device, 'hidden' => array_replace($device, ['devicenode' => '/dev/sdc', 'smart_serialnumber' => 'FIXTURE-C']),
        'stale' => array_replace($device, ['devicenode' => '/dev/sdd', 'smart_serialnumber' => 'OLD-FIXTURE']),
        'inventory-only' => array_replace($device, ['devicenode' => '/dev/sdz']),
        'removed' => array_replace($device, ['removed' => '2025-01-01'])];
    $rawDisks = ['disk1' => native_disk(), 'disk2' => native_disk(['name' => 'disk2', 'device' => 'sdc', 'id' => 'MODEL_FIXTURE-C']),
        'disk3' => native_disk(['name' => 'disk3', 'device' => 'sdd', 'id' => 'MODEL_NEW-FIXTURE'])];
    $disks = rackline_storage_disk_records($rawDisks, [], [], $sources['smartCache']);
    $alias = array_replace($rawDisks['disk1'], ['name' => 'flash', 'type' => 'Boot']);
    foreach ([$rawDisks + ['flash' => $alias], ['flash' => $alias] + $rawDisks] as $aliased) {
        $aliasMap = rackline_storage_location_data($disks, $aliased, $groups, $locations, $devices);
        check($aliasMap['groups'][0]['cells'][0]['disk']['name'] === 'disk1', 'Exact Boot alias preserves assigned location independent of order');
    }
    foreach (['id' => 'MODEL_OTHER', 'serial' => 'OTHER', 'model' => 'OTHER', 'transport' => 'ata', 'type' => 'Cache'] as $key => $value) {
        foreach ([$rawDisks + ['flash' => array_replace($alias, [$key => $value])], ['flash' => array_replace($alias, [$key => $value])] + $rawDisks] as $aliased) {
            $aliasMap = rackline_storage_location_data($disks, $aliased, $groups, $locations, $devices);
            check($aliasMap['groups'][0]['cells'][0]['disk'] === null && $disks[0]['location'] === null, 'Contradictory native alias or duplicate assignment cannot map: ' . $key);
        }
    }
    foreach ([false,true] as $differentIds) foreach ([false,true] as $reverse) {
        $cross = [native_disk(['name'=>'disk1','device'=>'sdb','serial'=>'DUPLICATE']), native_disk(['name'=>'disk2','device'=>'sdc','id'=>$differentIds?'OTHER_MODEL':'MODEL_FIXTURE-A','serial'=>'DUPLICATE'])];
        if ($reverse) $cross = array_reverse($cross);
        $crossDisks = rackline_storage_disk_records($cross, [], [], $sources['smartCache']);
        $crossMap = rackline_storage_location_data($crossDisks, $cross, ['1'=>['grid_columns'=>2,'grid_rows'=>1]],
            ['a'=>['groupid'=>'1','tray'=>1],'b'=>['groupid'=>'1','tray'=>2]],
            ['a'=>['devicenode'=>'/dev/sdb','smart_serialnumber'=>'DUPLICATE'],'b'=>['devicenode'=>'/dev/sdc','smart_serialnumber'=>'DUPLICATE']], $styleSettings);
        foreach ($crossMap['groups'][0]['cells'] as $cell) check($cell['disk'] === null && !$cell['display']['identityVerified'], 'Cross-node duplicate identity has no verified location or click target');
        foreach ($crossDisks as $disk) check($disk['location'] === null, 'Cross-node duplicate identity has no array/pool location projection');
    }
    $crossIdOnly = [native_disk(['name'=>'disk1','device'=>'sdb']), native_disk(['name'=>'disk2','device'=>'sdc'])];
    check(rackline_storage_location_nodes($crossIdOnly) === [] && rackline_storage_location_nodes(array_reverse($crossIdOnly)) === [], 'Cross-node duplicate ID rejected without explicit serial');
    $map = rackline_storage_location_data($disks, $rawDisks, $groups, $locations, $devices);
    $cells = $map['groups'][0]['cells'];
    check($disks[0]['location']['tray'] === 0 && $disks[0]['location']['sourceTray'] === 1, 'Source/display trays remain distinct');
    check($cells[0]['disk']['name'] === 'disk1' && !array_key_exists('location', $cells[0]['disk']), 'Reduced location disk intentionally omits location');
    check($cells[1]['hidden'] && !$cells[1]['present'] && $cells[1]['inventory'] === null && $cells[1]['disk'] === null, 'Hidden cells do not expose occupants');
    check($cells[2]['present'] && $cells[2]['inventory']['serial'] === 'OLD-FIXTURE' && $cells[2]['disk'] === null && $disks[2]['location'] === null, 'Reused device never maps a stale serial');
    check($cells[3]['present'] && $cells[3]['disk'] === null, 'Cached inventory can exist without a current disk');
    check(!$cells[4]['present'] && $cells[4]['inventory'] === null, 'Removed inventory is not present');
    check(!$cells[5]['present'] && $cells[5]['disk'] === null, 'Unassigned bay remains empty');
    $unknown = rackline_storage_disk_records([native_disk(['id' => ''])], [], [], $sources['smartCache']);
    $unknownMap = rackline_storage_location_data($unknown, [native_disk(['id' => ''])], $groups, $locations, $devices);
    check($unknown[0]['location'] === null && $unknownMap['groups'][0]['cells'][0]['disk'] === null, 'Device-only Disk Location matches are forbidden');
    $unmapped = rackline_storage_location_data($unknown, [], [], [], []);
    check(!$unmapped['available'] && $unmapped['groups'] === [], 'Missing optional Disk Location returns no invented map');

    $poolRaw = [native_disk(['name' => 'cache', 'type' => 'Cache', 'device' => 'nvme0n1', 'transport' => 'nvme', 'rotational' => '0']),
        native_disk(['name' => 'cache2', 'type' => 'Cache', 'device' => 'nvme1n1', 'fsSize' => '', 'fsUsed' => '', 'fsFree' => ''])];
    $poolDisks = rackline_storage_disk_records($poolRaw, [], [], $sources['smartCache']);
    $pools = rackline_storage_pool_data($poolDisks, ['cache' => ['diskFsType' => 'btrfs', 'diskFsProfile' => 'raid1']]);
    check($pools[0]['memberCount'] === 2 && $pools[0]['profile'] === 'raid1', 'Native configured pool members and profile');
    check($pools[0]['sizeBytes'] === 1024000000.0 && $pools[0]['usagePercent'] === 25.0, 'Pool shared capacity is not summed per member');
    check($poolDisks[1]['poolName'] === 'cache' && $poolDisks[0]['identityColor'] === $poolDisks[1]['identityColor'], 'Pool metadata applied consistently');
    $poolOnly = rackline_storage_array_data($poolDisks, ['mdState' => 'STARTED']);
    $noParity = rackline_storage_parity_data($poolDisks, [], []);
    check(!$poolOnly['configured'] && !$noParity['configured'] && !$noParity['valid'], 'Pool-only system has no array or valid parity');
    check(rackline_storage_attention($poolOnly, $noParity, $pools)['count'] === 0, 'Pool-only system has no phantom parity attention');
    $parity = [['type' => 'Parity', 'name' => 'parity', 'state' => 'normal']];
    $unknownParity = rackline_storage_parity_data($parity, [], []);
    check($unknownParity['configured'] && $unknownParity['valid'] === null && $unknownParity['state'] === 'unknown', 'Truncated parity metadata never implies valid parity');
    check($unknownParity['errors'] === null && $unknownParity['missingCount'] === null && $unknownParity['exitCode'] === null && $unknownParity['positionBytes'] === null, 'Missing parity fields stay null');
    $healthyParityVariables = $completeVariables + ['sbSyncExit' => '0', 'mdSyncErrs' => '0'];
    check(rackline_storage_parity_data($parity, $healthyParityVariables, [])['valid'] === true, 'Complete healthy parity metadata remains valid');
    foreach (['mdSyncErrs', 'mdNumMissing', 'mdNumDisabled', 'mdNumInvalid', 'sbSyncExit'] as $key) {
        $truncated = $healthyParityVariables;
        unset($truncated[$key]);
        check(rackline_storage_parity_data($parity, $truncated, [])['valid'] === null, 'Every required parity field is needed for valid: ' . $key);
        $malformed = array_replace($healthyParityVariables, [$key => 'unknown']);
        check(rackline_storage_parity_data($parity, $malformed, [])['valid'] === null, 'Malformed parity field never implies valid: ' . $key);
    }
    $knownFault = rackline_storage_parity_data($parity, ['mdNumDisabled' => '1'], []);
    check($knownFault['valid'] === false && $knownFault['state'] === 'fault', 'Known faults survive other unknown parity fields');
    check(rackline_storage_attention($poolOnly, $unknownParity, [])['messages'] === ['Parity · native status incomplete'], 'Unknown parity gets an honest attention message');
    $cancelled = rackline_storage_parity_data($parity, ['sbSyncExit' => '-4'], []);
    check($cancelled['state'] === 'attention' && $cancelled['cancelled'], 'Cancelled parity is attention');
    check(rackline_storage_parity_data($parity, ['sbSyncExit' => '-4', 'mdSyncErrs' => '1'], [])['state'] === 'fault', 'Cancellation never masks parity errors');
    $cron = "# synthetic schedule\n0 3 * * * /usr/local/sbin/mdcmd check\n";
    $now = (new DateTimeImmutable('2026-01-01 01:00:00'))->getTimestamp();
    $expected = (new DateTimeImmutable('2026-01-01 03:00:00'))->getTimestamp();
    check(rackline_storage_next_parity(['mode' => '1'], $now, $cron) === $expected, 'Next parity schedule parsed without executing command');
    check(rackline_storage_next_parity(['mode' => '0'], $now, $cron) === null, 'Disabled schedule stays unavailable');
    check(rackline_storage_next_parity(['mode' => '1'], $now, '') === null, 'Missing cron stays unavailable');
    check(rackline_storage_array_identity(['type' => 'Data', 'model' => 'ST24000NM000H-EXAMPLE'])['identityGroup'] === '24tb-h', 'Array family identity retained');
    check(rackline_storage_array_identity(['type' => 'Parity', 'model' => 'ST24000NM000H-EXAMPLE'])['identityGroup'] === 'parity', 'Parity color wins over model family');

    // This pool fixture is a different physical disk, not a duplicate identity.
    $write($sources['disks'], ini_text($rawDisks + ['cache' => array_replace($poolRaw[0], ['id'=>'MODEL_POOL-A'])]));
    $write($sources['variables'], ini_text(['mdState' => 'STARTED'], false));
    $write($sources['dynamix'], ini_text(['display' => $display, 'parity' => ['mode' => '0']]));
    $write($sources['smartSettings'], ini_text(['MODEL_FIXTURE-A' => ['hotTemp' => '45', 'maxTemp' => '50']]));
    $write($sources['parityCron'], $cron);
    $write($sources['pools'] . '/cache.cfg', ini_text(['diskFsType' => 'btrfs', 'diskFsProfile' => 'single'], false));
    foreach (['groups' => $groups, 'locations' => $locations, 'devices' => $devices, 'settings' => ['count_bypass_tray' => false]] as $name => $data)
        $write($sources['locations'] . '/' . $name . '.json', json_encode($data, JSON_THROW_ON_ERROR));
    $write($sources['locationDefaults'], $defaultsText);
    touch($sources['locations'] . '/groups.json', 1000);
    $cacheReceipt = rackline_storage_location_file($sources['locations'] . '/groups.json');
    check($cacheReceipt['writtenAtMs'] === 1000000 && $cacheReceipt['data'] === $groups, 'Layout cache timestamp is its file write time');
    check(rackline_storage_location_file($temporary . '/missing.json')['writtenAtMs'] === null, 'Missing cache write time is unknown');
    $dynamicGroups = ['1'=>array_replace($group,['tray_start_num'=>25,'count_bypass_tray'=>'1'])];
    $write($sources['locations'] . '/groups.json', json_encode($dynamicGroups, JSON_THROW_ON_ERROR));
    $dynamicSnapshot = rackline_storage_snapshot($sources);
    check($dynamicSnapshot['array']['disks'][0]['location']['tray'] === 25 && $dynamicSnapshot['locations']['groups'][0]['cells'][0]['tray'] === 25, 'Saved layout edits propagate to physical and array projection on next snapshot');
    $write($sources['locations'] . '/groups.json', json_encode($groups, JSON_THROW_ON_ERROR)); touch($sources['locations'] . '/groups.json',1000);
    $before = [];
    foreach (array_unique($files) as $file) $before[$file] = hash_file('sha256', $file);
    $snapshot = rackline_storage_snapshot($sources);
    check($snapshot['locations']['source'] === 'disklocation_cache' && $snapshot['locations']['cacheWrittenAtMs']['groups'] === 1000000, 'Native source and file times remain separate from snapshot time');
    check($snapshot['schemaVersion'] === 1 && $snapshot['sampledAtMs'] > 0, 'Standalone native snapshot schema');
    check(count($snapshot['array']['disks']) === 3 && $snapshot['pools'][0]['name'] === 'cache', 'Complete native snapshot without predecessor files');
    check($snapshot['array']['disks'][0]['smart']['available'] && $snapshot['array']['disks'][0]['smart']['health'] === null, 'Available SMART with unknown health survives snapshot');
    check($snapshot['locations']['groups'][0]['cells'][0]['disk']['identityColor'] === $snapshot['array']['disks'][0]['identityColor'], 'Location and root identity metadata agree');
    foreach ($before as $file => $hash) check(hash_file('sha256', $file) === $hash, 'Collection must not mutate native metadata');
    $withoutOptional = array_replace($sources, ['locations' => $temporary . '/missing-locations', 'smartCache' => $temporary . '/missing-smart']);
    $missingOptional = rackline_storage_snapshot($withoutOptional);
    check(!$missingOptional['locations']['available'] && !$missingOptional['array']['disks'][0]['smart']['available'], 'Optional source gaps do not break collection');
    throws(static fn() => rackline_storage_snapshot(array_replace($sources, ['disks' => $temporary . '/missing-disks'])), 'Missing required disk metadata must fail rather than report health');
    $write($temporary . '/malformed', "[unterminated\n");
    throws(static fn() => rackline_storage_snapshot(array_replace($sources, ['variables' => $temporary . '/malformed'])), 'Malformed required state must fail');
    check(rackline_storage_json($temporary . '/malformed') === [], 'Malformed optional JSON safely unavailable');
    // Exercise the actual serialized contract in the JavaScript model test.
    if (in_array('--json', $argv, true)) echo json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    else echo "Storage parser: native-only snapshot, cached SMART identity, thresholds, standby, 32 layout fixtures, pools and parity passed.\n";
} finally {
    foreach (array_unique($files) as $file) if (is_file($file)) unlink($file);
    foreach (array_reverse($directories) as $directory) rmdir($directory);
}
