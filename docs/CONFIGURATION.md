# Configuration

Persistent settings live at:

```text
/boot/config/plugins/waz.dashboard/waz.dashboard.cfg
```

The installer preserves existing values during rolling updates.

## General

```ini
DISPLAY_NAME="auto"
PRIMARY_INTERFACE="bond0"
GPU_CARD="auto"
GPU_RECENT_SECONDS="5"
```

`PRIMARY_INTERFACE` must match the desired physical or bonded interface. `GPU_CARD=auto` selects the first Intel `i915` DRM card; set a specific `cardN` when necessary.

`DISPLAY_NAME=auto` follows the local hostname. Blank values and the legacy
`WAZ-SERVER` default also follow the host, so copied defaults do not misidentify
another server. Other nonempty display names remain explicit overrides.

UPS discovery reads the local NUT or apcupsd service, including an upstream feed
already configured in that service. For a dashboard-only network feed, set
`UPS_APC_TARGET="ups-host:3551"` to an existing apcupsd server. This changes
telemetry only; it does not configure safe shutdown or the host UPS service.
The health banner and rack-power tile use the same source. Absent UPS indicators
and unavailable rack power are hidden; a configured source that fails still
raises a communication fault. Rack watts require load and nominal real power.

## Server Status layout

Choose **Edit layout** to drag CPU, memory and network sections into a new
order or resize them with the corner grip. The nine summary tiles have their
own independent grid inside the summary board. Move, width, height and Auto
buttons provide keyboard alternatives. **Done** hides editing controls;
**Reset layout** restores defaults. Layout and CPU expansion settings are
separate and stored in this browser for each server origin. Very narrow
screens stack blocks without overwriting saved widths.

GPU, coolant, flow, HBA and rack-power tiles hide until telemetry is detected.
Previously detected GPU/cooling/HBA readings remain visible if telemetry
later goes missing, so loss of data does not look like removal of hardware.

## Workloads and Storage layouts

Both panels provide the same **Edit layout**, drag, resize, keyboard controls,
Auto size and Reset layout actions. Workloads edits its four summary tiles
independently and provides blocks for Docker folders, container cards, top
processes and container details. Storage provides blocks for parity, pools,
selected-pool details, Disk Location and the array. Native container controls
and pool selection keep working after blocks move.

Each panel saves its own settings per browser origin; resetting Workloads or
Storage does not reset Server Status. Health alerts and panel headers stay
visible above the movable blocks. Pool-only hosts keep the array block hidden,
including while editing or resetting. Panel height follows the edited content.

## Disk Location

Build 2026.10.24.04 prioritizes the physical map in full-width dashboard rows.
Build 2026.10.24.05 keeps this map full-width and puts Server Status and
Workloads side by side below it when the viewport is at least 1200px wide.
On smaller screens those panels stack; their saved internal layouts remain intact.
Each enclosure is collapsible; selecting a bay opens one detail drawer beneath
that enclosure. Bays retain their plugin-assigned positions and labels, with
horizontal pool/role labels. Small displays scroll the spatial diagram rather
than shrinking text or changing the physical shape. Secondary panels and
statistics start collapsed; health alerts remain visible.

The new drive-first Storage preset uses `waz.dashboard.storageLayout.v2`.
Existing v1 settings are retained in browser storage but do not override this
preset. Enclosure collapse preferences use a separate per-origin key. Server
Status and Workloads settings remain compatible. Model/serial details are read
from the existing local plugin inventory and are not saved in browser settings.

Disk Location owns enclosure shape, names, bay assignments and display numbering.
The dashboard reads its saved groups, devices and locations without modifying
them. Stored association keys remain one-based; displayed labels follow the
plugin's start number (including zero), numbering direction and hidden bays.
Horizontal/vertical tray orientation is reflected in the compact map. Drive
identity must match current Unraid inventory before an assigned disk is shown
in a plugin-mapped bay. Active plugin records outside the assigned inventory
are labeled UNASSIGNED rather than EMPTY. Correct physical layouts and mappings
in Disk Location itself.

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
