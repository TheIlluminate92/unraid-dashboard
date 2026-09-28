# Version history

This repository preserves the development artifacts that still exist. Historical builds are included for comparison and archaeology, not as recommended installations.

## Original dashboard reference — build 2026.08.24.12

File: [`original/waz.dashboard-reference-2026.08.24.12.plg`](../original/waz.dashboard-reference-2026.08.24.12.plg)

This was the compact System/Cooling dashboard plugin used as the starting reference. It included fast CPU sampling, logical-CPU rows, rolling averages, a five-minute graph, power and cooling data, and early pool cards. It did not contain the current unified Health banner, Workloads inspector, Storage panel, plugin integrations, or final layout.

SHA-256: `777eb4ae236274a099daab59de9656ada445f3ffa88b245b409dffe856d4a0ab`

## WAZ Health v0.1.1 — build 2026.08.25.002

File: [`releases/history/waz.health-v0.1.1.zip`](../releases/history/waz.health-v0.1.1.zip)

- Added the first sticky full-width banner.
- Used placeholder overall and subsystem states.
- Added browser-local time and compact uptime.
- Established the JSON endpoint and browser API.

SHA-256: `3b86f3f8da10aca6c67db000f6efe9e6a08e3d94c8a73660adce9ce1706a8a6a`

## WAZ Health v0.2.0 — build 2026.08.25.003

File: [`releases/history/waz.health-v0.2.0.zip`](../releases/history/waz.health-v0.2.0.zip)

- Added five-second live refresh without a page reload.
- Added the expandable second row for attention and fault details.
- Retained placeholder collectors while the live rules were being defined.

SHA-256: `1dc8f44c03e2cf7e869477391611d3f2362787776cfd5e040e3881765a5e450a`

## WAZ Health v0.3.0 — build 2026.08.25.004

File: [`releases/history/waz.health-v0.3.0.plg`](../releases/history/waz.health-v0.3.0.plg)

- Derived overall health from the worst subsystem state.
- Added live Array, Storage, Cooling, throttling, and UPS collectors.
- Added the final storage, CPU, coolant, flow, and UPS thresholds used by v0.7.0.
- Removed the unfinished Services subsystem.

SHA-256: `aaafa0a28e831e3347e73a9b9c5ed1256fdb056c7c277f0ee4da1bfb74cd337e`

## Dashboard development milestones — v0.4.x through v0.6.x

These were rolling test packages copied directly to the reference server and were not retained as release artifacts. The surviving source and conversation notes show the progression:

- v0.4.x expanded the System panel with CPU topology, RAM, GPU, network, rack power, coolant flow, and HBA temperatures.
- v0.5.x added the full Storage panel, parity scheduling, pool selectors, physical Disk Location maps, and the dynamic Array table.
- v0.6.x added Workloads, FolderView Plus/native Organizer imports, native Docker controls, selected-container telemetry, top processes, and refined attention rules.

No downloadable files are presented for these milestones because the exact packages were not preserved.

## Unraid Dashboard v0.7.0 — build 2026.08.26.16

This was the first unified release. It combined the Health banner, Server Status, Workloads, and Storage under one plugin and migrated the old standalone Health configuration. It was replaced in the rolling release location by v0.8.0 and is not retained as a separate downloadable artifact.

## Unraid Dashboard v0.8.1 — build 2026.09.07.01

File: [`releases/waz.dashboard.plg`](../releases/waz.dashboard.plg)

This is the current rolling release. It fixes disk-temperature severity so WAZ follows Unraid's per-device overrides and separate global HDD/SSD defaults instead of falling through to built-in thresholds. A configured zero threshold is treated as disabled.

## Unraid Dashboard v0.8.0 — build 2026.09.01.04

This release added Unraid's native Tile Management window through the blue wrench and retired WAZ's duplicate fan controller in favor of the dedicated MD12xx Fan Control v0.4.3 API.

SHA-256: `c13aaa646f7896ed83d1ad0c22f48cf0596063ecac3691502b4eb36043495b48`
