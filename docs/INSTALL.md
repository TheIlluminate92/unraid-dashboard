# Installation

Unraid Dashboard v0.8.1 is a rolling test build for Unraid 7.2+ and is currently tested on Unraid 7.3.2.

## Before installing

- Read the [disclaimer](../DISCLAIMER.md), [dependencies](DEPENDENCIES.md), and [recovery procedure](RECOVERY.md).
- Confirm SSH or local terminal access.
- Back up the Unraid flash configuration.
- Review `waz.dashboard.cfg` defaults for the target hardware.
- Install and commission MD12xx Fan Control separately if fan status and controls are wanted in the WAZ header.

## Install as a normal Unraid plugin

Open **Plugins**, select **Install Plugin**, and paste this URL:

```text
https://raw.githubusercontent.com/TheIlluminate92/unraid-dashboard/main/releases/waz.dashboard.plg
```

Select **Install** and reload the entire Unraid WebUI when installation finishes.

For a terminal installation or recovery replacement, run:

```bash
curl -fsSL https://raw.githubusercontent.com/TheIlluminate92/unraid-dashboard/main/releases/install-waz-dashboard.sh | bash
```

## Verify

```bash
php -l /usr/local/emhttp/plugins/waz.dashboard/include/metrics.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/workloads.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/storage.php
php -l /usr/local/emhttp/plugins/waz.dashboard/include/status.php

php /usr/local/emhttp/plugins/waz.dashboard/include/status.php |
jq '{pluginVersion,overall,subsystems}'
```

## MD12xx Fan Control integration

Install and configure **MD12xx Fan Control v0.4.3 or newer** independently. Once its controller is enabled and commissioned, WAZ automatically discovers its local API and displays the compact fan control in the Health header. WAZ does not open serial adapters, calculate fan targets, read SES hardware, or maintain a second controller process. If the dedicated plugin is absent or unavailable, the fan control remains hidden.

When upgrading from an older WAZ Dashboard build that still owns fan control, the installer refuses to stop an enabled legacy WAZ controller unless the dedicated plugin API reports its replacement controller enabled. This is a one-time safety guard against losing temperature-driven changes during migration.

## Updating

Open **Plugins** and select **Check for Updates**. When a newer WAZ release build is available, use its normal **Update** button. Runtime files are replaced in RAM; existing `/boot/config/plugins/waz.dashboard/waz.dashboard.cfg` settings are preserved and new defaults are appended. The terminal installer remains available as a recovery path.

## Standalone WAZ Health migration

The unified installer migrates missing Health settings from `/boot/config/plugins/waz.health/waz.health.cfg`, renames `waz.health.plg` to `waz.health.plg.disabled`, and removes the old RAM-backed runtime so two banner loaders cannot coexist. The legacy configuration is retained as a backup.
