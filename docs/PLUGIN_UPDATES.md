# Plugin registration and update behavior

Visible author: **TheIlluminate92**. Existing acknowledgements and upstream credits remain authoritative.
Application versions describe features; date-based `YYYY.MM.DD.NN` build versions drive Installed Plugins updates. Unraid uses `strcmp`, not semantic version ordering.

## One active filename

Always install/update using the canonical raw URL from the README. Download historical or test manifests for audit, but save an intentionally selected package under the canonical filename before installing it. Never install a versioned filename directly. Historical manifests are immutable and lack the new safeguard.

The first installer step now rejects aliases before stopping processes, replacing runtime files, or touching configuration. It also refuses an install/update if another `.plg` in the boot or registration directory has the same internal name. It does not remove files, invoke uninstall, or silently migrate registrations.

The guard uses Unraid's inline-script basename convention (`inline1-<installer-basename>.sh`). A changed convention fails closed; validate against the host updater before deployment. [Official source, pinned revision](https://github.com/unraid/webgui/blob/26eb1b5c1d547ddbf44a38e63956ba24f659098b/emhttp/plugins/dynamix.plugin.manager/scripts/plugin).

## Why duplicate rows perform duplicate work

Unraid registers the **installer filename**, even when manifests share the same internal `name`. `checkall` walks registered symlinks sequentially, downloads each registration's `pluginURL`, and parses its manifest. Update cache entries are keyed by that same filename. Updating an alias retains the alias; a canonical URL alone does not normalize it. Retries can dominate network delays; package size alone does not prove the host's cause. This project does not patch Unraid core or share caches across registrations.

## Reviewed migration plan (do not execute without discussion)

1. Collect read-only manifest metadata, symlink targets, cached manifests, updater source/version, processes/logs, and host network timing. Confirm the canonical registration is installed successfully and identify every alias by both internal name and exact path.
2. Confirm no install/update is in flight. Back up the entire shared configuration directory, both installed manifests, registration symlink targets, and relevant update-cache files into a dated backup outside the active plugin directories. Record checksums and verify backup readability/completeness. Retain commissioning records, saved identity/calibration, and current runtime; no fan test or service restart is needed for registration-only cleanup.
3. After reviewing the exact paths with the operator, move only confirmed alias boot manifests into that backup with `.disabled` suffixes. Remove only their matching `/var/log/plugins` symlinks and matching `/tmp/plugins` cache entries after backup verification. Do not run native Uninstall or `plugin remove`; do not remove a shared runtime/configuration directory. Never use a broad wildcard removal.
4. Verify shared configuration checksums are unchanged, canonical symlinks resolve correctly, and the runtime remains healthy. Run the normal update check once; confirm one registration/check per plugin and record elapsed time. Then discuss deployment of the new canonical package separately, since an actual update restarts its runtime.
5. Rollback restores backed-up alias manifests and symlinks only if necessary; it does not downgrade runtime or overwrite successful commissioning data. Retain backups until reboot/update behavior has been validated.

MD12xx uninstall intentionally removes configuration and commissioning data. Both duplicate entries can address the same runtime/configuration. Waz uninstall behavior differs; do not assume that difference makes MD12xx alias uninstall safe.

## Validation boundary

Registration fixtures verify canonical acceptance, alias rejection, duplicate rejection, unchanged existing registration files, and unrelated-plugin acceptance. These are packaging checks, not live hardware validation. Quiet 35% MD12xx startup recovery remains unexercised on hardware; its gates are unchanged.
