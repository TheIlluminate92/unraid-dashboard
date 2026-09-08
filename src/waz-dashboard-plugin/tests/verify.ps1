$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$manifest = Join-Path $projectRoot 'dist/waz.dashboard.plg'

& (Join-Path $projectRoot 'scripts/build.ps1') -OutputPath $manifest

$xmlSettings = [System.Xml.XmlReaderSettings]::new()
$xmlSettings.DtdProcessing = [System.Xml.DtdProcessing]::Parse
$reader = [System.Xml.XmlReader]::Create($manifest, $xmlSettings)
try {
    while ($reader.Read()) { }
} finally {
    $reader.Dispose()
}

$required = @(
    '/usr/local/emhttp/plugins/waz.dashboard/WazDashboard.page',
    '/usr/local/emhttp/plugins/waz.dashboard/WazWorkloads.page',
    '/usr/local/emhttp/plugins/waz.dashboard/WazStorage.page',
    '/usr/local/emhttp/plugins/waz.dashboard/WazHealthBanner.page',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/css/system.css',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/css/workloads.css',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/css/storage.css',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/css/banner.css',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/js/system.js',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/js/workloads.js',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/js/storage.js',
    '/usr/local/emhttp/plugins/waz.dashboard/assets/js/banner.js',
    '/usr/local/emhttp/plugins/waz.dashboard/include/metrics.php',
    '/usr/local/emhttp/plugins/waz.dashboard/include/workloads.php',
    '/usr/local/emhttp/plugins/waz.dashboard/include/storage.php',
    '/usr/local/emhttp/plugins/waz.dashboard/include/gpu-sampler.php',
    '/usr/local/emhttp/plugins/waz.dashboard/include/health.php',
    '/usr/local/emhttp/plugins/waz.dashboard/include/status.php',
    '/usr/local/emhttp/plugins/waz.dashboard/scripts/start.sh',
    '/usr/local/emhttp/plugins/waz.dashboard/scripts/stop.sh'
)

$manifestText = [System.IO.File]::ReadAllText($manifest)
foreach ($path in $required) {
    if (-not $manifestText.Contains("Name=`"$path`"")) {
        throw "Missing packaged file: $path"
    }
}

if ($manifestText.Contains('@@')) {
    throw 'An unexpanded build placeholder remains in the manifest.'
}
if ($manifestText -notmatch 'Method="remove"') {
    throw 'Manifest is missing an uninstall handler.'
}
if ($manifestText -match 'rm -rf\s+"/boot/config/plugins/waz\.dashboard"') {
    throw 'The uninstall handler removes persistent settings, which breaks forced rolling updates.'
}
if (-not $manifestText.Contains('<!ENTITY pluginURL "https://raw.githubusercontent.com/TheIlluminate92/waz-control/main/releases/waz.dashboard.plg">') -or -not $manifestText.Contains('pluginURL="&pluginURL;"')) {
    throw 'Manifest is missing its stable Plugin Manager update URL.'
}
if ($manifestText -match 'pluginURL\s+"file://' -or $manifestText -match 'pluginURL="file://') {
    throw 'Manifest reverted to a local-only Plugin Manager URL.'
}
if ($manifestText -match 'setTimeout\(systemLoop,\s*100\)' -or $manifestText -match 'setInterval\([^\)]*,\s*100\)') {
    throw 'Legacy 100 ms browser polling remains.'
}
if ($manifestText -notmatch "\['column1'\]") {
    throw 'The System tile is not assigned to one native dashboard column.'
}
if ($manifestText -notmatch "\['column2'\]") {
    throw 'The Workloads tile is not assigned to the center dashboard column.'
}
if ($manifestText -notmatch "\['column3'\]") {
    throw 'The Storage tile is not assigned to the right dashboard column.'
}

foreach ($marker in @(
    'MemAvailable',
    'intel_gpu_top',
    'PRIMARY_INTERFACE',
    '#111417',
    'GPU_RECENT_SECONDS',
    'waz-summary-cpu-detail',
    '/plugins/hbaviewer/export.php',
    'waz-hba-0-temp',
    'waz-summary-flow',
    'waz-summary-power-detail',
    'waz-log-bar',
    "waz_filesystem_usage('/var/log')",
    'NOMPOWER',
    'grid-template-columns: repeat(3',
    'font-size: 15px',
    'height: 70px',
    'height: 54px',
    'waz-storage-tile',
    'waz-storage-alert',
    'waz_storage_attention',
    'waz-parity-progress',
    'waz-pool-tabs',
    'waz-location-groups',
    'waz-array-disks',
    'waz.dashboard.storage.selectedPool',
    'ResizeObserver',
    'groups.json',
    'locations.json',
    'devices.json',
    'WAZ_STORAGE_WARN_PERCENT',
    'WAZ_SMART_ONE_CFG',
    'waz_storage_temperature_thresholds',
    "'hotssd'",
    "'maxssd'",
    'waz_storage_group_layout',
    'waz_storage_next_parity',
    'nextScheduledAt',
    'LAST PARITY',
    'Parity 1',
    'border: 1px solid #34566b',
    'color: var(--disk-color, var(--waz-cyan))',
    "'sourceTray'",
    'grid-auto-rows: minmax(28px, 1fr)',
    'waz-summary-network-rx',
    'waz-summary-network-tx',
    'waz-workloads-tile',
    'waz-workloads-alert',
    'attentionMessages',
    "preg_match('/Exited \((1|137)\)/i'",
    "container['autostart'] === true",
    'autostart failed · killed / possible OOM',
    'autostart failed · application error',
    'waz-folder-tabs',
    'waz-container-grid',
    'grid-auto-rows: minmax(74px, auto)',
    'overflow-wrap: anywhere',
    'waz-top-processes',
    'waz-workloads-docker',
    'dockerVdisk',
    "waz_workloads_filesystem_usage('/var/lib/docker')",
    'docker-labels',
    'net.unraid.docker.folder',
    'TOP PROCESSES',
    'LIVE CPU · HOST + DOCKER',
    'waz.dashboard.workloads.selectedFolder',
    'waz.dashboard.workloads.selectedContainer',
    'addDockerContainerContext',
    "document.getElementById('docker_view')",
    "document.querySelector('#db_box1 > tbody.system')",
    'FolderView3',
    '/boot/config/plugins/folderview.plus/docker.json',
    '/boot/config/plugins/dynamix.my.servers/configs/docker.organizer.json',
    'waz_workloads_native_configuration',
    '/boot/config/plugins/folder.view3/docker.json',
    '/boot/config/plugins/folder.view2/docker.json',
    '/boot/config/plugins/folder.view/docker.json',
    'folderSource',
    'SERVER STATUS',
    'waz-system-header',
    '/containers/',
    'stats?stream=false',
    'process-samples.json',
    "var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']"
    'waz-health-banner',
    'subsystemOrder = ["array", "storage", "cooling", "ups"]',
    '/plugins/waz.dashboard/include/status.php',
    '/boot/config/plugins/waz.dashboard/waz.dashboard.cfg',
    '/var/run/waz.dashboard/health-throttle-state.json',
    '--waz-health-background: #111417',
    '--waz-health-border: #303840',
    '--waz-health-cyan: #22b8f0',
    'waz-health__modules',
    'typeof window.contentMgmt === "function"',
    'Manage dashboard modules',
    'LEGACY_HEALTH_CONFIG',
    '${LEGACY_HEALTH_PLUGIN}.disabled'
    '/plugins/md12xx.fancontrol/include/api.php'
    'body.set("action", "control")'
    'payload.status || payload'
    'allowedManualSpeeds'
    'normalizeFanStatus'
    'applyFanHealth'
    'the dedicated MD12xx Fan Control API does not report an enabled replacement'
    'averageRpm'
    'fanEndpoint'
    'csrfToken'
)) {
    if (-not $manifestText.Contains($marker)) {
        throw "Missing implementation marker: $marker"
    }
}

$bannerSource = [System.IO.File]::ReadAllText((Join-Path (Split-Path -Parent $projectRoot) 'waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/assets/js/banner.js'))
if (-not $bannerSource.Contains('window.csrf_token || config.csrfToken')) {
    throw 'MD12xx header control is not using Unraid''s current page session token.'
}
foreach ($oldFanPath in @('include/md1200.php', 'include/md1200-control.php', 'include/md1200-controller.php', 'scripts/backup-md1200.sh', 'scripts/diagnose-md1200.sh', 'scripts/test-md1200-controls.sh')) {
    if ($manifestText.Contains($oldFanPath)) { throw "WAZ still packages retired fan-control logic: $oldFanPath" }
}
foreach ($oldFanMarker in @('set_speed ', 'MD1200_ENABLED="no"', 'MD1200_TOP_PORT=', '/var/run/waz.dashboard/md1200')) {
    if ($manifestText.Contains($oldFanMarker)) { throw "WAZ still owns retired fan-control behavior: $oldFanMarker" }
}

if ($manifestText.Contains('/boot/config/plugins/waz.dashboard/waz.health.cfg')) {
    throw 'Integrated Health is still pointed at the legacy configuration filename.'
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    $phpFiles = @(
        'include/metrics.php', 'include/workloads.php', 'include/storage.php', 'include/gpu-sampler.php'
    )
    foreach ($relative in $phpFiles) {
        $file = Join-Path $projectRoot ('source/usr/local/emhttp/plugins/waz.dashboard/' + $relative)
        & $php.Source -l $file | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $relative" }
    }
    & $php.Source (Join-Path $projectRoot 'tests/storage-thresholds.php') | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Storage temperature threshold regression tests failed.' }
    foreach ($relative in @('include/health.php', 'include/status.php')) {
        $file = Join-Path (Split-Path -Parent $projectRoot) ('waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/' + $relative)
        & $php.Source -l $file | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $relative" }
    }
}

$bash = Get-Command bash -ErrorAction SilentlyContinue
if ($bash) {
    foreach ($relative in @('start.sh', 'stop.sh')) {
        $file = Join-Path $projectRoot ('source/usr/local/emhttp/plugins/waz.dashboard/scripts/' + $relative)
        & $bash.Source -n $file
        if ($LASTEXITCODE -ne 0) { throw "Shell syntax check failed: $relative" }
    }
}

foreach ($removedMarker in @('WAZ // SYSTEM', 'waz-allocation', 'section=allocation', 'waz_allocation', 'waz-gpu-block', 'SERVER POWER', 'serverInputWatts', 'CONTAINER PROCESSES', 'waz-selected-process-list', 'waz-workloads-running-detail', 'waz-workloads-health-detail', 'waz-workloads-gpu-detail', 'waz-workloads-healthy', 'waz-workloads-issues', 'waz-docker-bar', 'waz-docker-percent', 'waz-docker-used', 'waz-docker-total', 'waz-array-location')) {
    if ($manifestText.Contains($removedMarker)) {
        throw "Removed UI or collector remains: $removedMarker"
    }
}

$boardOrder = @(
    'waz-summary-cpu', 'waz-summary-gpu', 'waz-summary-power',
    'waz-summary-memory', 'waz-summary-cooling', 'waz-hba-0-temp',
    'waz-summary-network', 'waz-summary-flow', 'waz-hba-1-temp'
)
$lastPosition = -1
foreach ($marker in $boardOrder) {
    $position = $manifestText.IndexOf('id="' + $marker + '"', [System.StringComparison]::Ordinal)
    if ($position -le $lastPosition) {
        throw "Status board marker is missing or out of order: $marker"
    }
    $lastPosition = $position
}

Write-Output 'WAZ System plugin manifest verification passed.'
