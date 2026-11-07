<?php
$racklineWeb = '/plugins/rackline.dashboard';
$racklineWaz = '/usr/local/emhttp/plugins/waz.dashboard/include/';
$racklineReady = is_file($racklineWaz . 'metrics.php') && is_file($racklineWaz . 'storage.php') && is_file($racklineWaz . 'workloads.php');
$racklineVar = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
$racklineBoot = [
    'hostName' => (string) ($racklineVar['NAME'] ?? 'This server'),
    'available' => $racklineReady,
    'fanAvailable' => is_file('/usr/local/emhttp/plugins/md12xx.fancontrol/include/api.php'),
    'version' => '@@APP_VERSION@@',
];
?>
<link rel="stylesheet" href="<?=$racklineWeb?>/assets/css/rackline.css?v=@@RELEASE_VERSION@@">
<section id="rackline-root" aria-label="Rackline server workspace">
  <header class="rl-heading"><div><span class="rl-eyebrow">HOST-LOCAL UNRAID WORKSPACE</span><h1>Rackline <small id="rl-host"></small></h1></div><nav aria-label="Native server pages"><a href="/Dashboard">Original dashboard</a><a href="/Main">Main</a><a href="/Docker">Docker</a><a id="rl-fan-link" href="/MD12xxFanControl" hidden>Fan control</a></nav></header>
  <div id="rl-availability" class="rl-notice" role="status">Connecting to this server…</div>
  <section class="rl-pulse" aria-label="Live performance strip">
    <article><div class="rl-label">CPU <span id="rl-topology">Topology pending</span></div><div class="rl-reading"><b id="rl-cpu">—</b><span id="rl-temperature">—</span><small id="rl-model"></small></div><div id="rl-cores" class="rl-cores"></div><small class="rl-foot">Logical CPU IDs · all threads visible</small></article>
    <article><div class="rl-label">MEMORY <span>Used / total</span></div><div class="rl-reading"><b id="rl-ram">—</b></div><div class="rl-meter"><i id="rl-ram-bar"></i></div><small id="rl-ram-detail" class="rl-foot">Waiting for MemAvailable</small></article>
    <article><div class="rl-label">NETWORK <span id="rl-interface">—</span></div><div class="rl-dual"><div><small>↓ RECEIVE</small><b id="rl-rx">—</b></div><div><small>↑ TRANSMIT</small><b id="rl-tx">—</b></div></div><small id="rl-network-detail" class="rl-foot">Waiting for two samples</small></article>
    <article><div class="rl-label">DISK I/O <span>Known physical devices</span></div><div class="rl-dual"><div><small>READ</small><b id="rl-read">—</b></div><div><small>WRITE</small><b id="rl-write">—</b></div></div><small id="rl-io-detail" class="rl-foot">Kernel counters · no disk probes</small></article>
  </section>
  <div class="rl-meta"><span id="rl-system-age">System: waiting</span><span id="rl-storage-age">Storage: waiting</span><span id="rl-docker-age">Docker: waiting</span><span id="rl-io-age">I/O: waiting</span></div>
  <div id="rl-alerts" class="rl-alerts" role="status" hidden></div>
  <nav class="rl-tabs" role="tablist" aria-label="Rackline workspaces"><button id="rl-tab-drives" role="tab" aria-selected="true" aria-controls="rl-drives" data-tab="drives">Drives &amp; pools</button><button id="rl-tab-docker" role="tab" aria-selected="false" aria-controls="rl-docker" data-tab="docker">Docker folders</button><button id="rl-tab-cooling" role="tab" aria-selected="false" aria-controls="rl-cooling" data-tab="cooling">Cooling &amp; fans</button></nav>
  <section id="rl-drives" role="tabpanel" aria-labelledby="rl-tab-drives">
    <div class="rl-workspace-head"><h2>Drives &amp; pools</h2><label>Search <input id="rl-drive-search" type="search" placeholder="Drive, device, pool…"></label></div>
    <div class="rl-layout"><aside><div id="rl-pools" class="rl-filters"></div><div id="rl-parity" class="rl-note"></div></aside><div class="rl-main"><div class="rl-scroll"><table><caption class="rl-sr">Drives from cached Unraid inventory</caption><thead><tr><th>Drive</th><th>Pool / role</th><th>Used</th><th>Temperature</th><th>Read / write</th><th>State</th></tr></thead><tbody id="rl-drive-rows"></tbody></table></div><section class="rl-box"><h3>Physical locations</h3><div id="rl-bays"></div></section></div><aside id="rl-drive-inspector" class="rl-inspector"><h3>Select a drive</h3><p>Cached health and device details appear here.</p></aside></div>
  </section>
  <section id="rl-docker" role="tabpanel" aria-labelledby="rl-tab-docker" hidden>
    <div class="rl-workspace-head"><div><h2>Docker workspace</h2><small id="rl-folder-source">Reading configured folders…</small></div><label>Search <input id="rl-container-search" type="search" placeholder="Container or state…"></label></div>
    <div class="rl-layout"><aside><div id="rl-folders" class="rl-filters"></div><div id="rl-docker-summary" class="rl-note"></div></aside><div class="rl-main"><div class="rl-scroll"><table><caption class="rl-sr">Docker inventory and selected-container telemetry</caption><thead><tr><th>Container</th><th>State / health</th><th>CPU</th><th>RAM</th><th>Controls</th></tr></thead><tbody id="rl-container-rows"></tbody></table></div><p class="rl-note">CPU and RAM are sampled for the selected running container only. CPU is % of one logical CPU and can exceed 100%.</p><section class="rl-box"><h3>Top host / Docker processes</h3><div id="rl-processes"></div></section></div><aside id="rl-container-inspector" class="rl-inspector"><h3>Select a container</h3><p>Selection shows details. Native controls remain separate.</p></aside></div>
  </section>
  <section id="rl-cooling" role="tabpanel" aria-labelledby="rl-tab-cooling" hidden><div class="rl-workspace-head"><h2>Cooling &amp; fans</h2><a id="rl-fan-manage" class="rl-button" href="/MD12xxFanControl" hidden>Open native fan control ↗</a></div><p class="rl-note">The existing cooling controller owns temperatures, curves, fan speeds, watchdogs and hardware access. Rackline reads status and opens its native control page.</p><div id="rl-cooling-status" class="rl-cooling-grid"></div><p id="rl-fan-age" class="rl-note"></p></section>
  <footer class="rl-footer"><span>Rackline v@@APP_VERSION@@ · one instance per server</span><a href="/Dashboard">Return to unchanged WAZ / native dashboard</a></footer>
</section>
<?php if (!isset($racklineNoBootstrap)): ?>
<script id="rackline-bootstrap" type="application/json"><?=json_encode($racklineBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<script src="/plugins/dynamix.docker.manager/javascript/docker.js?v=@@RELEASE_VERSION@@"></script>
<script defer src="<?=$racklineWeb?>/assets/js/model.js?v=@@RELEASE_VERSION@@"></script>
<script defer src="<?=$racklineWeb?>/assets/js/app.js?v=@@RELEASE_VERSION@@"></script>
<?php endif; ?>
