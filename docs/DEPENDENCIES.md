# Dependencies

## Baseline

- Unraid 7.2+ on x86-64; originally hardware-tested on Unraid 7.3.2; observed maintenance baseline 7.3.3/PHP 8.4.26, with new hardware failure-path validation pending
- Unraid WebUI/emhttp plugin system
- Docker enabled for the Workloads panel
- A modern browser with JavaScript enabled
- Terminal or SSH access for installation and recovery

## Optional integrations

- **FolderView Plus:** Docker folders, colors, icons, order, and membership
- **Unraid Docker Organizer:** native folder fallback
- **Disk Location:** R360/MD1200-style physical slot assignments and empty bays
- **HBA Viewer:** cached controller temperatures and identity information
- **apcupsd or NUT:** UPS state, charge, runtime, and load
- **intel_gpu_top:** Intel GPU engine load and client attribution
- **lm-sensors/Linux hwmon:** CPU and cooling telemetry
- **MD12xx Fan Control v0.4.3+:** fan health, shelf RPM, Auto/Manual mode, and header controls through its local API

Missing optional integrations generally produce unavailable metrics rather than an installation failure, but layouts are tuned for the reference server.

## Runtime sources

The collectors read Unraid/Linux runtime state including:

- `/proc/uptime`, `/proc/meminfo`, `/proc/stat`, and process/cgroup data
- `/var/local/emhttp/var.ini` and `/var/local/emhttp/disks.ini`
- `/boot/config/pools/*.cfg` and the generated parity schedule
- `/sys/class/hwmon`, CPU topology, thermal-throttle counters, network statistics, and RAPL power
- Docker's local socket through Unraid's Docker client

These paths and formats can change between Unraid releases.

## Reference hardware assumptions

The current layout and collectors were tested with a Dell PowerEdge R360, Intel Xeon E-2488, Intel Arc Pro A40, Dell HBA355i, SAS9300-8e, bonded networking, Aqua Computer high flow NEXT, multiple SSD pools, and an XFS array connected through MD1200 shelves.

Other CPUs, GPUs, HBAs, sensor labels, network interfaces, disk counts, and physical layouts may require changes.

See [maintenance and migration](MAINTENANCE_AND_MIGRATION.md) for PHP CI coverage, per-host sensor requirements and current 7.4/8.0 boundaries.
