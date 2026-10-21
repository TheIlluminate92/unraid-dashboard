# Maintenance and migration

## 0.8.3 / build 2026.10.20.01

- The GPU worker rejects HTTP execution before doing work. A lifetime file lock prevents duplicate instances, startup is serialized, and the sampling child starts directly without an intermediate shell. Shutdown terminates/reaps that child; failure to stop the worker blocks runtime replacement. Existing orphan samplers are not automatically killed because their owner cannot safely be inferred.
- Workloads responses expose process names, not complete command arguments that may contain credentials. The existing `command` field is retained with the short process name for UI compatibility.
- Missing array state or required CPU temperature is unknown, not normal. Optional absent coolant/flow sensors are not faults on air-cooled hardware. Set `COOLANT_REQUIRED="yes"` and/or `FLOW_REQUIRED="yes"` in the dashboard configuration to make those required on a water-cooled host. Existing thresholds and settings are preserved; neither option is enabled silently.
- Payload PHP and shell syntax is checked in a hidden staged tree before the installed watcher stops. Failed activation restores the previous runtime when the replacement can be stopped safely. Tests exercise invalid staged PHP, stop/start failure, configuration preservation and rollback.
- CI pins checkout/setup actions and tests PHP 8.2/8.3/8.4 where applicable. Repository attributes and builders normalize text line endings. Historical release bytes/checksums remain unchanged.

The fixes are software-tested. No live server update, reboot, hardware failure injection or Unraid 7.4 certification is implied by the release build.

## Installation and recovery

Install under the canonical `waz.dashboard.plg` filename. The terminal helper verifies the exact release checksum and backs up saved configuration. Do not uninstall an alias registration to resolve duplicates. Save an independent boot/config backup before installation.

The hidden `.waz.dashboard.staged` tree is validated before activation; `.waz.dashboard.previous` retains the previous successful runtime. These directories are beneath `/usr/local/emhttp/plugins`. Failed activation restores/restarts the previous tree only when the new watcher stops successfully. Existing configuration values are retained, while absent defaults may be appended.

This is runtime rollback, not a full configuration/registration or power-loss transaction. If interrupted, inspect live/staged/previous trees before retrying; never start workers from two trees. Keep independent configuration backups. Old standalone Health-plugin migration has its own metadata changes and should be reviewed during recovery.

## R730xd / R360 deployment

Each dashboard monitors its own host. Review display name, `PRIMARY_INTERFACE`, GPU selection, Disk Location/HBA Viewer integration, sensor requirements and thresholds per server. Do not copy the R360's bond, GPU or water-cooling assumptions blindly to the R730xd. Intel GPU auto-selection currently targets i915; another driver/card can legitimately be unavailable.

MD12xx header controls address the **local** MD12xx plugin. Install/enable shelf control only on the host physically connected to its SAS shelves and USB adapters. A dashboard on the other server is not a remote fan controller. Preserve shelf identities/calibration and inspect disk assignments after moving hardware. R730 chassis control is a planned separate backend, not part of this update.

Establish the split on the same stable OS first, with unique IP/hostname/licensing and duplicate workloads/hardware jobs disabled. Preserve boot/config backups and the original storage for rollback. Review internal boot, TPM licensing and ZFS feature compatibility separately from file copying.

## OS validation checklist

The observed production baseline is Unraid 7.3.3/PHP 8.4.26. [Unraid 7.4.0-rc.1](https://docs.unraid.net/unraid-os/release-notes/7.4.0/) is a pre-release as of 2026-10-20. Test native Docker menus/multiple networks, shared task tray, plugin update/reinstall, authentication/CSRF, storage/boot-pool displays, GPU worker lifecycle and required/missing sensors before migration. Retain the [7.3 rollback limitations](https://docs.unraid.net/unraid-os/release-notes/7.3.0/).

[Unraid 8](https://unraid.net/blog/unraid-8-qa) plans a new plugin/permission system and reverse proxy. This plugin depends on current WebGUI paths, DockerClient, runtime files and host access; it needs adaptation and does not claim 8.0 compatibility. Runtime PHP endpoints rely on Unraid's authenticated WebGUI and global POST CSRF validation; do not host them independently without equivalent controls.
