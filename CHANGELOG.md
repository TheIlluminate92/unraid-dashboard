# Changelog

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
