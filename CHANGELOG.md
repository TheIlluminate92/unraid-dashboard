# Changelog

## 0.8.5 - 2026-10-24 (build 2026.10.24.05)

- Keeps Drives & Storage full-width above a two-column Server Status / Workloads row on screens at least 1200px wide. Smaller screens stack the panels.
- Retains bay drawers, enclosure geometry, collapsible details and existing saved block layouts. No backend, service or storage configuration changes.

## 0.8.5 - 2026-10-24 (build 2026.10.24.04)

- Prioritizes drive layouts in full-width dashboard rows. Secondary system/workload panels and parity/pool/array statistics use native collapsible details; health alerts stay visible.
- Uses readable horizontal bay labels and retains Disk Location's geometry and numbering. Spatial maps scroll on small displays rather than squeezing labels or silently changing the physical layout.
- Adds keyboard-accessible bay buttons and one selected-bay drawer, including available model, serial, capacity, temperature and assignment details. Empty and unassigned bays remain distinct; enclosure collapse settings survive refresh.
- Preserves the live SAS serial-token matching fix and pool names without member suffixes. Cancelled parity checks report attention without masking actual parity errors.
- Storage uses a new drive-first layout preset; Server Status and Workloads saved layouts remain unchanged. No management daemon, array, service or Disk Location settings are altered.

## 0.8.5 - 2026-10-24 (build 2026.10.24.03)

- Extends the shared layout editor to Workloads and Storage. Each panel saves its own order and dimensions; existing Server Status layouts remain compatible.
- Adds individually editable Workloads summary tiles and sections for folders, containers, processes and selected-container details. Storage exposes parity, pool selection, selected-pool details, disk locations and array as separate blocks.
- Keeps headers and health alerts outside movable content, retains native Docker controls and pool selection, and preserves hidden array sections on pool-only hosts. Panels grow with their content rather than being clipped to Server Status height.
- Adds regressions for per-panel saved settings, repeated initialization, hidden blocks, page structure and native control IDs.

## 0.8.5 - 2026-10-24 (build 2026.10.24.02)

- Default and legacy banner names follow the local hostname; custom labels remain supported.
- Adds per-browser movable/resizable Server Status sections and summary tiles, keyboard editing controls and layout reset. Missing optional hardware tiles hide automatically.
- Stopped and paused containers no longer inherit a historical healthcheck failure in the inspector. Running health alerts and autostart failure detection remain active.
- Health and rack-power collection share local NUT/apcupsd discovery and an optional dashboard-only network APC source. Missing UPS widgets hide; an explicitly configured failed source still reports a fault. No UPS daemon or shutdown settings are changed.
- Mirrors Disk Location's group names, hidden bay shape, horizontal/vertical trays and numbering directions. Supports zero-based labels while retaining the plugin's one-based association keys; rejects stale device-name identity matches and distinguishes mapped unassigned drives from empty bays. Disk Location retains ownership of layouts and assignments; Docker folder ownership remains with its managers.

## 0.8.5 — 2026-10-22 (build 2026.10.22.04)

- Keeps the wrench, clock and uptime anchored to the right when optional cooling controls are absent.
- Supports WAZ Cooling Control: hides shelf controls when no MD12xx shelves are available, shows a chassis IPMI settings link when available, and includes chassis faults in Cooling and overall health. Existing shelf-only payloads remain compatible.
- Preserves the plugin update identity and saved configuration. The dashboard displays cooling state; fan policy and hardware writes remain in the cooling plugin.
- Validation: 16 JavaScript regressions, Windows manifest checks, and all five Windows/Linux PHP compatibility jobs passed. See #15 and #16.

## 0.8.4 — 2026-10-22 (build 2026.10.22.03)

- Adds three CPU views: socket summaries, physical cores, and all logical threads. Remembers expansion choices and calculates utilization from valid weighted counter deltas.
- Supports pool-only systems by hiding the array section when no array disks are assigned. Empty slots do not create faults; assigned missing disks still do.
- Hides unavailable MD12xx controls and avoids polling missing optional plugin endpoints while retrying transient failures.
- Adds a bundled dashboard icon, Dashboard launch link, and asset cache versions tied to the package build. See #7, #8, #9 and #13.

## 0.8.3 — 2026-10-20

Restricts the GPU worker to CLI, serializes instances and reaps its child. Removes full process arguments from responses, reports missing required sensors as unknown, and validates a staged runtime before activation with rollback. Adds regression coverage, PHP compatibility CI and migration documentation. No live installation or 7.4 hardware certification is claimed.

See [maintenance and migration](docs/MAINTENANCE_AND_MIGRATION.md) for behavior, rollback boundaries, validation and server-split guidance.

## 0.8.2 — 2026-10-12 (build 2026.10.12.01)

- Reject noncanonical installer names and duplicate internal-name registrations before runtime/configuration changes.
- Document backed-up registration migration, filename-keyed update caching, and sequential duplicate checks; no automatic server cleanup.
- Use TheIlluminate92 attribution while retaining project credits and separate application/build metadata.


## v0.8.1 — 2026-09-07

- Fixed false disk-temperature warnings caused by reading nonexistent `warning` and `critical` runtime fields.
- Honor per-device `hotTemp` and `maxTemp` overrides from Unraid's `smart-one.cfg`.
- Honor Unraid's separate global HDD and SSD temperature defaults from Dynamix configuration.
- Treat a configured zero threshold as disabled, matching Unraid.

## v0.8.0 — 2026-08-31

- Added the blue dashboard wrench for Unraid's native Tile Management window.
- Registered the stable GitHub release manifest with Unraid Plugin Manager so future releases appear as normal plugin updates.
- Added a checksum-guarded terminal replacement installer that preserves WAZ configuration.
- Replaced the custom MD1200 Docker controller with a host-native WAZ controller process.
- Added compact MD1200 control to the Health header while shifting subsystem indicators left.
- Auto mode displays separate Top and Bottom average fan RPM from SES telemetry.
- Manual mode offers hardware-confirmed 20%, 30%, 40%, and 50% commands for both shelves.
- Preserved independent disk-temperature control per shelf with hysteresis and periodic reassertion.
- Restored a distinct 50% very-hot and sensor-failure step instead of silently remaining at 30%.
- Blocked controller writes while the legacy Docker container is running.
- Added cross-process serial locking and separated command-write status from fan telemetry status.
- Staged fan control disabled by default and added migration backups under the `Back-Up` share.
- Added a read-only SES mapping diagnostic that writes its results under the `Back-Up` share.
- Mapped the 24 TB Top shelf to SCSI address `0:0:18:0` and the 14 TB Bottom shelf to `0:0:11:0`, with runtime `/dev/sg*` resolution.
- Added a guarded 20%/50% commissioning test that records per-fan RPM, restores both shelves to their normal 20% resting speed, and writes a ZIP under the `Back-Up` share.
- Corrected BlueDress serial framing to use carriage-return-only command termination after the first test exposed that CRLF commands were not applied reliably.
- Opened each BlueDress serial session read/write and drained its console response after hardware testing showed write-only sessions could leave `set_speed` unapplied.
- Confirmed both primary active EMM serial paths: 50% produced approximately 6,300–6,400 RPM and restoring 20% produced approximately 3,300–3,550 RPM.
- Fixed Dashboard Auto/Manual changes to use Unraid's current page session token and rely on Unraid's built-in POST validation.
- Preserved Dashboard settings during forced plugin updates so controller enablement and policy survive reinstalling the staged package.
- Replaced the WAZ-owned MD1200 controller with a small client for the dedicated MD12xx Fan Control v0.4.3 API.
- Removed WAZ serial, temperature-curve, SES, commissioning, migration-backup, and controller-process logic.
- Made header shelf labels and Manual speed options follow the dedicated plugin's API instead of hard-coded Top/Bottom and 20–50% assumptions.

## v0.7.0 — 2026-08-26

First unified WAZ Control release.

- Combined the Health banner and Dashboard panels under one `waz.dashboard` plugin.
- Added matched Server Status, Workloads, and Storage panels.
- Added live CPU, RAM, GPU, network, rack-power, coolant, flow, UPS, HBA, parity, pool, array, Docker, and process telemetry.
- Added dynamic Docker-folder, selected-container, and selected-pool views.
- Added FolderView Plus, native Docker Organizer, Disk Location, and HBA Viewer integration.
- Added workload and storage attention rows with specific causes.
- Ignored intentionally stopped/on-demand containers when evaluating Workloads attention.
- Matched the integrated Health banner to the dashboard color palette.
- Added standalone WAZ Health configuration migration and recoverable manifest retirement.

The development versions before v0.7.0 were rolling internal test builds and are not published here as supported releases.
