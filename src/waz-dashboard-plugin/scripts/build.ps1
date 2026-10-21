param(
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$templatePath = Join-Path $projectRoot 'plugin/waz.dashboard.plg.in'
$sourceRoot = Join-Path $projectRoot 'source'
$healthSourceRoot = Join-Path (Split-Path -Parent $projectRoot) 'waz-health-plugin/source/usr/local/emhttp/plugins/waz.health'
$appVersion = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'VERSION')).Trim()
$releaseVersion = [System.IO.File]::ReadAllText((Join-Path $projectRoot 'RELEASE')).Trim()

if (-not $OutputPath) {
    $OutputPath = Join-Path $projectRoot 'dist/waz.dashboard.plg'
}

$files = foreach ($file in (Get-ChildItem -LiteralPath $sourceRoot -File -Recurse)) {
    [pscustomobject]@{
        Source = $file.FullName
        Target = $file.FullName.Substring($sourceRoot.Length).Replace('\', '/')
        IsHealth = $false
    }
}

$healthMappings = [ordered]@{
    'WazHealthBanner.page' = '/usr/local/emhttp/plugins/waz.dashboard/WazHealthBanner.page'
    'assets/css/banner.css' = '/usr/local/emhttp/plugins/waz.dashboard/assets/css/banner.css'
    'assets/js/banner.js' = '/usr/local/emhttp/plugins/waz.dashboard/assets/js/banner.js'
    'include/health.php' = '/usr/local/emhttp/plugins/waz.dashboard/include/health.php'
    'include/status.php' = '/usr/local/emhttp/plugins/waz.dashboard/include/status.php'
}
foreach ($mapping in $healthMappings.GetEnumerator()) {
    $source = Join-Path $healthSourceRoot $mapping.Key
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Missing integrated health source: $source"
    }
    $files += [pscustomobject]@{ Source = $source; Target = $mapping.Value; IsHealth = $true }
}

$blocks = foreach ($file in ($files | Sort-Object Target)) {
    $relative = $file.Target.Replace('/plugins/waz.dashboard/', '/plugins/.waz.dashboard.staged/')
    $content = [System.IO.File]::ReadAllText($file.Source).Replace("`r`n", "`n")
    $content = $content.Replace('@@APP_VERSION@@', $appVersion).Replace('@@PLUGIN_VERSION@@', $releaseVersion)
    if ($file.IsHealth) {
        $content = $content.Replace('/boot/config/plugins/waz.health/waz.health.cfg', '/boot/config/plugins/waz.dashboard/waz.dashboard.cfg')
        $content = $content.Replace('/usr/local/emhttp/plugins/waz.health', '/usr/local/emhttp/plugins/waz.dashboard')
        $content = $content.Replace('/plugins/waz.health', '/plugins/waz.dashboard')
        $content = $content.Replace('/var/tmp/waz-health-throttle-state.json', '/var/run/waz.dashboard/health-throttle-state.json')
    }
    if ($content.Contains(']]>')) {
        throw "Source file cannot be embedded in CDATA: $($file.Source)"
    }
    @"
<FILE Name="$relative">
<INLINE>
<![CDATA[
$content
]]>
</INLINE>
</FILE>
"@
}

$template = [System.IO.File]::ReadAllText($templatePath).Replace("`r`n", "`n")
$manifest = $template.Replace('@@FILES@@', ($blocks -join "`n"))
$manifest = $manifest.Replace('@@APP_VERSION@@', $appVersion).Replace('@@PLUGIN_VERSION@@', $releaseVersion)
$manifest = $manifest.Replace('@@RELEASE_VERSION@@', $releaseVersion)
$outputDirectory = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
[System.IO.File]::WriteAllText($OutputPath, $manifest, [System.Text.UTF8Encoding]::new($false))

Write-Output "Built $OutputPath"
