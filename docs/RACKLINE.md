# Rackline: independent host workspace

## Current state — 2026-11-07

Rackline is a **source implementation for review, not an installed-host release**. It is a separate `rackline.dashboard` plugin, version 0.1.0, build 2026.11.07.01. No live installation, configuration, container action, fan change or WAZ replacement is performed by this source work. Operational ownership stays with each host's existing owner; no new operational owner is assigned here.

Each server runs its own `/Rackline` workspace inside its authenticated Unraid WebUI. A small additive Dashboard panel opens it. The existing `/Dashboard`, WAZ tiles, native Main/Docker pages, WAZ health banner and fan controls remain in place. There is no separate service, port, cloud API, fleet aggregator or dashboard-layout editor. This first integration is an additive workspace/launcher, not a DOM replacement of Unraid's Dashboard. Native-theme acceptance on installed hosts remains pending.

## Design and source contracts

- A shared performance strip stays present across Drives, Docker and Cooling tabs. Wide desktop layouts keep it sticky; narrow screens stack it rather than covering the viewport. Tables and physical bay maps scroll locally on small screens.
- CPU aggregate and every logical CPU are calculated from consecutive counters, preserving socket/core/logical IDs. Missing data and counter resets remain unavailable. Replayed/regressing sample times cannot masquerade as fresh data; without an HTTP Date header, large host/browser clock skew is conservatively shown as stale. Memory uses `MemTotal - MemAvailable`; cache overlaps available memory. Network uses the collector-selected interface with explicit link and rate units.
- Storage uses existing cached WAZ inventory, SMART and Disk Location data. Physical columns, tray labels, hidden positions, empty and present-but-unmapped bays are preserved. No new SMART queries, disk opening, wake/probe commands or storage mutations are added. Native device links open `/Main/Device` for intentional operations.
- The only new collector reads fixed `/proc/diskstats` and `/proc/uptime` paths. It is GET-only, returns cumulative counters and never runs commands. Frontend throughput joins exact assigned physical device names from cached inventory. Partition/virtual rows are not blindly summed. A partial device set is not presented as a full total. Disk IOPS/latency are not yet shown.
- Docker folders come from WAZ's existing normalized FolderView Plus/native Organizer/legacy/label grouping. Empty folders remain visible. Only the selected running container is sampled at one second; other inventory rows explicitly show unavailable CPU/RAM, not fabricated peer statistics. CPU is percent of one logical CPU and may exceed 100%. Short/full IDs require exact name and unique identity. Recreation, stop, failed samples and counter resets invalidate old telemetry. History is session-only, at most five minutes.
- Docker menu actions use installed Unraid `docker.js`. The Edit route is explicitly anchored to `/Docker/UpdateContainer`, rather than inheriting `/Rackline`. The template is URL encoded. Applying edits remains native and may recreate/restart a container. Menu registration stays stable across telemetry-only updates. Cleanup removes only Rackline-owned trigger/action delegates and menu nodes; preexisting foreign menus fall back to the native Docker page. Template-less containers are directed to native Docker/their owning application, not silently adopted or recreated.
- Cooling reads the existing local MD12xx controller endpoint only when detected. Watchdog recovery/fault, stale state, shelf status and optional chassis/IPMI state are shown. The existing `/MD12xxFanControl` page and WAZ health-banner controls own every setting change. Rackline adds no fan writer, policy, watchdog, hardware access or POST.
- Polls do not overlap per feed and pause in hidden tabs: system 1 s, selected running container 1 s while Docker is visible, physical I/O 2 s, storage/inventory/fans 5 s. Request timeout is 8 s. Optional fan 404/410 stops further polling until reload. Feed errors/staleness are explicit; last successful data may remain visible. Native links stay usable.

Dependencies: working WAZ `metrics.php`, `storage.php`, `workloads.php`; installed Unraid Docker manager for its native menus; optional Disk Location/fan integrations as already configured. Missing optional integrations stay unavailable. Legacy WAZ Docker accounting may coerce unsupported network/block counters to zero; Rackline labels that limitation rather than claiming complete host-network accounting.

Security boundary: collectors rely on Unraid's same-origin authenticated WebUI, as WAZ does. Do not expose them through a new unauthenticated proxy. No credentials or CSRF tokens are introduced or copied. UI text is escaped and container icons are restricted to local paths. The native Docker manager remains responsible for its own mutation endpoints and authorization. This does not establish compatibility with every future Unraid/docker.js version.

## Build and source checks

```sh
node --test src/rackline-dashboard-plugin/tests/*.test.mjs
node --test src/waz-dashboard-plugin/tests/*.test.mjs
php src/rackline-dashboard-plugin/tests/io-parser.php
python3 src/rackline-dashboard-plugin/tests/package-safety.py
bash src/rackline-dashboard-plugin/scripts/build.sh
(cd src/rackline-dashboard-plugin/dist && sha256sum -c SHA256SUMS)
```

Also run the repository's required aggregate `pwsh -File src/waz-dashboard-plugin/tests/verify.ps1`. CI retains existing WAZ checks and adds PHP 8.2/8.4 Rackline lint/parser/package tests. Local source checks are not live host acceptance.

The build produces `src/rackline-dashboard-plugin/dist/rackline.dashboard.plg`, `.tgz` and `SHA256SUMS`. Build output is ignored, reproducible, and **review-only with no automatic update URL**. The PLG embeds its payload, verifies the archive digest/paths and WAZ dependency, stages and PHP-lints before swapping only its own runtime, and retains its predecessor for manual rollback. It never rewrites WAZ/core configuration, restarts services or changes fan settings. Package tests redirect all system paths into temporary fixtures and stub PHP only to exercise installer mechanics. Actual PHP parser/lint checks are separate.

For browser QA only, `python3 src/rackline-dashboard-plugin/tests/render-fixture.py <output-directory>` builds the actual view and frontend using explicitly synthetic telemetry and stubbed native controls. This fixture is not packaged. Its toolbar simulates stop, recreation, unavailable/malformed feeds and watchdog recovery. It is not a live-server demo or authentication test.

## Validation record and limits

On 2026-11-07, 30 local Node adapter/runtime/native-menu/adversarial tests, all 42 existing WAZ JavaScript tests, 4 package tests, existing WAZ build, and release checksums passed. PHP and PowerShell were not available locally. The first draft commit `c5c75d1b28d738651802fe1eacc5ead1a938d9c9` passed Rackline PHP 8.2/8.4 lint/parser/package CI and existing WAZ Windows PHP 8.2/8.3/8.4 aggregate plus Linux PHP 8.2/8.4 checks. Later hardening must pass CI on its own exact commit; the initial green run does not cover subsequent edits. The package installer is exercised only against temporary filesystem fixtures with fake PHP. Browser QA verified desktop and 320/390/768/1024/1440px iframe layouts, local table scrolling without document overflow, 32 logical CPUs, bay labels/hidden positions, empty folders, template-less ownership, native-menu keyboard activation/persistence, selected stop/recreation, malformed-feed recovery, watchdog recovery and focused native links remaining intact during metric updates. These use actual frontend files with synthetic responses; the native control stub cannot prove server-side mutation behavior. Source review corrected menu Edit routing, unstable menu registration, bay schema handling, watchdog omission, stopped/recreated telemetry retention and keyboard controls. The additional adversarial pass added nested payload validation, replay/regression freshness checks (HTTP Date clock offset when available), normalized Docker-error rejection, drive/filter focus stability and cleanup when a native template disappears. No WAZ collector was modified for these guards.

Live collector compatibility, native theme behavior, same-origin authentication, real context menus, fan status, existing dashboard coexistence and rollback are separate acceptance gates. Do not label a server verified from source tests alone. Keep private evidence and host inventory out of this public repository.

## Pilot and rollback plan — not executed

1. Obtain authorization for a Rackline pilot install on one chosen host after reviewing CI and the exact artifact/digest. Do not install on both simultaneously. Record existing Unraid/WAZ versions and preserve its current plugin/config recovery state privately.
2. Use native Unraid plugin installation for the reviewed `rackline.dashboard.plg`. Confirm dependency/lint checks succeed. Record the exact retained predecessor path printed by the installer. Do not use or replace `releases/waz.dashboard.plg`.
3. Verify native `/Dashboard`, Main, Docker and fan pages remain intact; `/Rackline` has a single launcher and host identity. Compare read-only system, drive, folder and fan readings against native views. Check pool-only/array host layouts, empty groups, hidden bays, optional absence, stale feeds, narrow screens, keyboard focus, back/forward and background-tab behavior.
4. Verify native menu visibility and editor destinations without submitting changes. A real start/stop/restart/edit/apply, fan change, storage action or disruptive test needs the applicable explicit approval; a read-only QA request does not grant those.
5. To leave Rackline immediately, return to unchanged `/Dashboard`. To remove the pilot, use native plugin removal when authorized: the remover retains the Rackline runtime under a printed `.rackline.dashboard.removed.*` directory, and leaves WAZ and configuration alone. Reverting to an earlier Rackline build is a separate authorized manual operation using the specifically recorded predecessor; do not guess or delete rollback paths.
6. Restart/reboot persistence, actual plugin-manager removal/reinstall and hardware/load validation require a separately scheduled authorized acceptance pass. There is no automatic release promotion.

## Comparison with the design render

The implementation preserves the charcoal/orange palette, four desktop metric cards, workspace tabs, left-side groups, center table, right-side inspector and physical bay map. The native Unraid shell will surround it on a real host; QA screenshots deliberately use a labeled synthetic harness instead of a fabricated shell. The implementation shows logical-CPU labels and explicit sample freshness, adds Cooling/fan status, and replaces simulated controls with native handoffs. It is not pixel-identical: summary CPU/network history curves and all-container metric columns from the illustrative render are not implemented. Only selected-container CPU has a short session trend; unsupported data is unavailable rather than decorative. No multi-server selector is provided because each host owns its instance.
