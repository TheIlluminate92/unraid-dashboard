# Configuration

Persistent settings live at:

```text
/boot/config/plugins/waz.dashboard/waz.dashboard.cfg
```

The installer preserves existing values during rolling updates.

## General

```ini
DISPLAY_NAME="WAZ-SERVER"
PRIMARY_INTERFACE="bond0"
GPU_CARD="auto"
GPU_RECENT_SECONDS="5"
```

`PRIMARY_INTERFACE` must match the desired physical or bonded interface. `GPU_CARD=auto` selects the first Intel `i915` DRM card; set a specific `cardN` when necessary.

## Health thresholds

```ini
OVERALL_STATE="normal"
OVERALL_MESSAGE=""
ARRAY_STATE="normal"
ARRAY_MESSAGE=""
STORAGE_STATE="normal"
STORAGE_MESSAGE=""
COOLING_STATE="normal"
COOLING_MESSAGE=""
UPS_STATE="normal"
UPS_MESSAGE=""
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

The coolant collector currently expects a `highflownext` hwmon device with `Coolant temp` and `Flow [dL/h]` labels. Adapt the collector for other hardware.

## MD12xx fan control integration

WAZ no longer owns fan-controller settings or hardware logic. When **MD12xx Fan Control v0.4.3 or newer** is installed, the Health header reads its authenticated local API for controller health, mode, supported manual speeds, shelf names, temperatures, targets, and RPM telemetry. Auto/Manual changes from the WAZ header are submitted to that same plugin API.

Configure discovery, shelf mapping, commissioning, temperature curves, fail-safe behavior, and controller enablement in **Settings → Utilities → MD12xx Fan Control**. If the plugin is absent, WAZ hides the fan control without affecting the rest of the dashboard. Legacy `MD1200_*` values left in `waz.dashboard.cfg` by older WAZ builds are inert and may be removed manually.

## Required cooling sensors

CPU temperature is required for normal cooling health. Optional `COOLANT_REQUIRED="yes"` and `FLOW_REQUIRED="yes"` mark missing coolant/flow readings unknown. Their defaults are off for air-cooled hosts; set them explicitly on hosts that require water-cooling telemetry. Existing thresholds are unchanged. See [migration guidance](MAINTENANCE_AND_MIGRATION.md).
