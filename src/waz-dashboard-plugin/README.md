# WAZ System

WAZ Dashboard is a rolling-test Unraid 7.2+ dashboard plugin. It installs matched System, Workloads, and Storage panels in the three native dashboard columns and does not modify Unraid core files.

## Version 0.8.0 scope

- Sticky full-width WAZ Health banner across the WebUI, now installed and maintained by this single dashboard plugin
- Blue dashboard wrench in the WAZ Health header opens Unraid's native Tile Management window and uses Unraid's saved module preferences
- Banner colors matched to the dashboard's dark background, border, text, cyan, green, amber, and red palette
- Automatic migration of existing standalone WAZ Health thresholds and overrides into `waz.dashboard.cfg`
- Recoverable standalone-plugin retirement by renaming `waz.health.plg` to `waz.health.plg.disabled` and removing only its RAM-backed runtime
- Matched SERVER STATUS, WORKLOADS, and STORAGE headers with live overall state at the upper right
- Contextual second-row attention details for Docker and Storage faults or warnings
- Compact 3x3 status board ordered as CPU/GPU/rack power, RAM/coolant/HBA 0, and network/flow/HBA 1
- CPU load, five-minute browser-local history, physical-core topology, and paired logical CPUs
- RAM usage based on `MemAvailable` plus cached DDR/ECC/DIMM inventory
- RAM and `/var/log` usage bars with live used and total sizes
- Intel GPU load, video-engine load, and best-effort process/container attribution in the top status board
- Primary network throughput, bond mode, and member link state
- Equal-width cyan download and violet upload values in the top status board
- UPS-derived rack power, CPU package power, coolant temperature, and coolant flow
- One combined live snapshot each second
- One-screen System layout with reduced-height history graphs
- Right-column Storage panel matched to the System panel height
- Compact dual-parity status with live check progress, calculated speed, ETA, last result, and errors
- Real next-parity timing calculated from Unraid's generated Scheduler cron entry
- Remembered selectors for Cache, Servers, Downloads, Akashic, and future pools
- Dynamic pool membership and a permanent two-column Array table built from cached Unraid disk state
- R360, MD1200 Top, and MD1200 Bottom maps using Disk Location assignments, identity colors, hidden trays, and empty bays
- Physical bay numbering compressed to R360 1–8 and MD1200 1–12 while retaining the configured hidden-cell geometry
- Center-column Workloads panel using the existing FolderView3 names, icons, colors, order, and container membership
- Container icons that open Unraid's normal Docker control menu and simultaneously select the container for deeper telemetry
- Long container names wrap within taller, aligned icon cards instead of being reduced to an ellipsis
- Five-second selected-container CPU, RAM, network, disk I/O, mount/pool access, GPU attribution, and status telemetry
- Permanent Top 4 CPU process list with host-versus-Docker ownership, RAM use, and full-command tooltips
- Folder import compatibility for FolderView Plus, Unraid's native Docker Organizer, FolderView3, FolderView2, and legacy FolderView
- Label-derived folders when no FolderView configuration file exists
- Workloads header attention state for unhealthy containers and autostart services that remain stopped after exit 1 or 137; intentionally stopped and on-demand containers are ignored
- Docker-vdisk used/total capacity in the Workloads summary instead of the System memory section
- Storage capacities displayed in decimal KB, MB, GB, and TB units
- Compact MD12xx fan control in the full-width Health header, backed entirely by the dedicated MD12xx Fan Control plugin API
- Dynamic shelf names and RPM readouts in Auto mode plus the manual speeds advertised by the controller API
- No WAZ-owned serial access, temperature curve, SES discovery, commissioning, watchdog, or fan-controller process

When HBA Viewer is installed, WAZ System reads its authenticated `export.php` endpoint once per minute. That endpoint uses HBA Viewer's existing 60-second overview cache and controller-read lock, so WAZ does not launch an additional StorCLI scan. If HBA Viewer is absent, both HBA cells show unavailable.

When Disk Location is installed, WAZ Storage reads its saved `groups.json`, `locations.json`, and `devices.json` files. Disk state, capacity, temperature, parity, and pool membership come from Unraid's cached runtime and pool configuration. WAZ Storage does not run `smartctl`, mount scans, or commands that wake sleeping array disks.

Disk-temperature severity follows Unraid's configuration precedence: per-device `hotTemp` and `maxTemp` overrides from `/boot/config/smart-one.cfg`, then the HDD or SSD defaults in the `[display]` section of `/boot/config/plugins/dynamix/dynamix.cfg`. A configured value of zero disables that threshold. Media-specific built-in values are used only when Unraid has no configured value.

The Intel GPU watcher selects the first Intel `i915` DRM card, which avoids the Matrox BMC display adapter on WAZ-SERVER. GPU clients remain visible for five seconds after they exit so short jobs can still be identified.

## Build

From Windows PowerShell:

```powershell
.\scripts\build.ps1
.\tests\verify.ps1
```

The installable manifest is written to `dist/waz.dashboard.plg`.

## Install on Unraid

In **Plugins → Install Plugin**, paste:

```text
https://raw.githubusercontent.com/TheIlluminate92/unraid-dashboard/main/releases/waz.dashboard.plg
```

After this one-time installation, Unraid's normal **Check for Updates** and **Update** controls use the manifest's stable GitHub `pluginURL`. For a terminal replacement or recovery install:

```bash
curl -fsSL https://raw.githubusercontent.com/TheIlluminate92/unraid-dashboard/main/releases/install-waz-dashboard.sh | bash
```

Reload the Unraid Dashboard after installation. Updates replace only the runtime plugin files and preserve `/boot/config/plugins/waz.dashboard/waz.dashboard.cfg`.

## Configuration

The first installation creates:

```text
/boot/config/plugins/waz.dashboard/waz.dashboard.cfg
```

Defaults:

```ini
DISPLAY_NAME="WAZ-SERVER"
PRIMARY_INTERFACE="bond0"
GPU_CARD="auto"
GPU_RECENT_SECONDS="5"
OVERALL_STATE="normal"
STORAGE_WARN_PERCENT="95"
STORAGE_FAULT_PERCENT="98"
CPU_WARN_C="60"
CPU_FAULT_C="70"
COOLANT_WARN_C="35"
COOLANT_FAULT_C="40"
FLOW_WARN_LPH="130"
FLOW_FAULT_LPH="120"
THROTTLE_LATCH_SECONDS="300"
UPS_LOAD_WARN_PERCENT="90"
UPS_LOAD_FAULT_PERCENT="100"
```

`GPU_CARD` may be set to a DRM card such as `card1` if automatic Intel GPU selection ever needs to be overridden.

## Functional checks on Unraid

```bash
php -l /usr/local/emhttp/plugins/waz.dashboard/include/metrics.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/workloads.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/storage.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/gpu-sampler.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/health.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/status.php
php /usr/local/emhttp/plugins/waz.dashboard/include/metrics.php | jq .
php /usr/local/emhttp/plugins/waz.dashboard/include/workloads.php | jq '{pluginVersion,folderSource,summary,folders,topProcesses}'
php -r '$_GET["selected"]="Plex"; require "/usr/local/emhttp/plugins/waz.dashboard/include/workloads.php";' | jq '.selected | {name,state,stats,storage,gpu,processes}'
php /usr/local/emhttp/plugins/waz.dashboard/include/storage.php | jq .
php /usr/local/emhttp/plugins/waz.dashboard/include/status.php | jq .
cat /var/run/waz.dashboard/gpu.json | jq .
```

## Uninstall

Use Unraid's Plugins page. Uninstall stops the GPU watcher and removes the plugin runtime, persistent settings, state, and manifest. Unraid core files are never replaced.
