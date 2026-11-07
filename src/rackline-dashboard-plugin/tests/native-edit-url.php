<?php
// Query decoding regression based on installed CreateDocker.php lines 330–334.
// Fixture names contain no markup, so Unraid unscript() leaves their text unchanged.
$cases = json_decode(file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($cases) || count($cases) < 5) throw new RuntimeException('Missing fixtures');
foreach ($cases as $case) {
    parse_str(parse_url($case['url'], PHP_URL_QUERY), $query);
    $parts = explode(':', urldecode($query['xmlTemplate']), 2);
    if ($parts[0] !== 'edit' || $parts[1] !== $case['path']) throw new RuntimeException('Template did not round-trip');
}
echo count($cases) . " native template query decode fixtures passed\n";
