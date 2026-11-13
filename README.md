# Unraid Dashboard

<!-- reader-guide:start -->
## Meet the project

A server-local dashboard that brings selected Unraid health, container and hardware information into the administration interface. The main branch contains the WAZ edition; separate Rackline development and installed pilots have their own records.

### What it is built with

PHP for server-side plugin behavior, JavaScript/CSS for the interface, shell collectors and PowerShell packaging/verification. It integrates with Unraid and other host-specific plugins.

### Find your way around

| Start here | What you will find |
|---|---|
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Settings and dependencies. |
| [src/waz-dashboard-plugin/README.md](src/waz-dashboard-plugin/README.md) | Component source/build entry. |
| [docs/RECOVERY.md](docs/RECOVERY.md) | Recovery boundaries. |
| [docs/MAINTENANCE_AND_MIGRATION.md](docs/MAINTENANCE_AND_MIGRATION.md) | Edition/version and host migration context. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development checks and safe problem reports. |

### When something goes wrong

Start by reading the relevant source or guide below. These are investigation
starting points, not instructions to change a live system.

| Symptom or question | First place to look |
|---|---|
| A tile is empty or stale | Identify its collector and dependency before assuming the host itself is failing. |
| Layout looks wrong | Start with the component UI source and reproduce the display issue without changing host configuration. |
| Source differs from installed plugin | Compare edition, package and installed revision; a draft Rackline branch is not main's WAZ release. |

The repository owner maintains this project; a dedicated technical worker is not recorded.

Reader guide reviewed November 12, 2026 against repository source and available
owner records. Existing test, deployment and recovery evidence keeps its original
date; this prose review did not run the application or test a restore.
<!-- reader-guide:end -->

<img src="src/waz-dashboard-plugin/source/usr/local/emhttp/plugins/waz.dashboard/images/waz.dashboard.png" width="96" height="96" alt="WAZ Dashboard icon">

Current maintenance build: **0.8.5 / 2026.10.24.09**. Read [safety, updates and R730xd/R360 migration guidance](docs/MAINTENANCE_AND_MIGRATION.md) before deployment.

The source includes an unreleased selected-container ID matching correction, verified as a local pilot hotfix on November 1. The rolling installer has not changed; see the maintenance record before reinstalling or applying it to another host.

Unraid Dashboard (formerly WAZ Control) is a custom Unraid dashboard and WebUI project built around one heavily customized server. The current unified build is **v0.8.5**.

> [!WARNING]
> **This is a reference build, not a universal or supported Community Applications plugin.** It depends on specific Unraid plugins, runtime files, sensors, hardware, and WebUI behavior. Expect to adapt configuration or code for another server. Read the [disclaimer](DISCLAIMER.md), [dependencies](docs/DEPENDENCIES.md), and [recovery steps](docs/RECOVERY.md) before installing it.

![Unraid Dashboard status](https://img.shields.io/badge/status-rolling%20test-f2a900)
![Version](https://img.shields.io/badge/version-0.8.5-22b8f0)
![Tested on Unraid](https://img.shields.io/badge/tested-Unraid%207.3.3-e95420)

## What v0.8.5 includes

- Sticky full-width Health banner across Unraid WebUI pages
- Live Array, Storage, Cooling, and UPS state with an expandable fault-detail row
- Three matched Dashboard panels: Server Status, Workloads, and Storage
- Three-stage CPU display: socket summaries, physical cores, and individual threads, with remembered expansion choices
- CPU topology, per-thread use, rolling load history, RAM, GPU, networking, rack power, coolant, flow, and HBA temperatures
- Docker folders imported from FolderView Plus or Unraid's Docker Organizer
- Clickable container cards that retain Unraid's native controls and load a dynamic telemetry inspector
- Selected-container CPU, RAM, network, disk I/O, pool/share access, GPU activity, address, port, and status data
- Top four host/Docker CPU processes
- Compact parity state, last/next check timing, dynamic pools, physical disk locations, and an array table when an array is configured
- Decimal storage units and collection paths designed not to wake sleeping array disks
- One installable rolling plugin without permanent edits to Unraid core files
- Optional compact MD12xx fan status and controls in the header through the separate [MD12xx Fan Control](https://github.com/TheIlluminate92/unraid-md12xx-fan-control) plugin's local API
- API-provided shelf names, average RPM, controller health, Auto/Manual mode, targets, and supported manual speeds
- No WAZ-owned serial access, SES discovery, temperature policy, commissioning, watchdog, or fan-controller process

The Docker and Storage panels deliberately use dynamic detail windows. Selecting a Docker folder filters the visible containers; selecting a container loads its live details below. Selecting a pool loads only that pool's member disks while configured Array and physical Disk Location views remain visible. Pool-only servers hide the Array section.

## Install

The current artifact is [`releases/waz.dashboard.plg`](releases/waz.dashboard.plg). Install its raw GitHub URL through Unraid's **Plugins → Install Plugin** page; later releases are delivered through the normal plugin update controls. See [docs/INSTALL.md](docs/INSTALL.md).

Saved development builds and the original dashboard reference are retained for comparison. See [docs/VERSION_HISTORY.md](docs/VERSION_HISTORY.md). They are historical artifacts, not recommended installs.

## Source layout

```text
src/
├── waz-dashboard-plugin/  # unified plugin, panels, collectors, build and verification
└── waz-health-plugin/     # banner component source consumed by the unified build
```

The dashboard build intentionally keeps the banner component beside it and embeds that component under the single `waz.dashboard` runtime during packaging.

## Credit

Unraid Dashboard brings together data and behavior provided by Unraid/Dynamix, Docker Manager and Organizer, FolderView Plus, Disk Location, HBA Viewer, apcupsd/NUT, lm-sensors, and `intel_gpu_top`. See [docs/ATTRIBUTION.md](docs/ATTRIBUTION.md).

The layout, requirements, server-specific decisions, and testing were directed by TheIlluminate92. The PHP, JavaScript, CSS, collectors, packaging, and documentation were developed collaboratively with Codex by OpenAI.

## Support

No support is guaranteed. Pull requests are welcome, especially for clean auto-detection or documented hardware adaptations.

## License

MIT. See [LICENSE](LICENSE).

See [plugin registration and update behavior](docs/PLUGIN_UPDATES.md) before migrating duplicate entries. Use only the canonical installer filename; immutable versioned installers are audit artifacts.


## License and redistribution

[MIT License](LICENSE): reuse and modification are allowed with the notice retained. Provided as is, without warranty. See [licensing and packaging scope](LICENSING.md) for third-party terms and release limitations.
