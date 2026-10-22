# Current rolling release

`waz.dashboard.plg` is Unraid Dashboard v0.8.4, release build `2026.10.22.02`.

It contains the integrated Health banner, Server Status, Workloads, and Storage panels. Its compact fan display and controls use the separate MD12xx Fan Control plugin API when that plugin is installed; WAZ no longer owns any fan hardware or policy logic. This is a hardware-specific rolling test build, not a Community Applications release.

See [installation](../docs/INSTALL.md), [dependencies](../docs/DEPENDENCIES.md), and [recovery](../docs/RECOVERY.md) before using it.

Install the raw `waz.dashboard.plg` URL through Unraid's **Plugins → Install Plugin** page. Its stable `pluginURL` lets Unraid detect and apply later WAZ builds through the normal Plugins update controls.

For a canonical terminal install/update that preserves the existing WAZ configuration, run:

```bash
wget -qO /tmp/install-waz-dashboard.sh \
  https://raw.githubusercontent.com/TheIlluminate92/unraid-dashboard/main/releases/install-waz-dashboard.sh
bash /tmp/install-waz-dashboard.sh
```

The helper downloads and verifies the package into a private temporary directory under the canonical `waz.dashboard.plg` filename. It retains a unique configuration backup and installs directly without invoking Uninstall or `plugin remove`. Existing alias registrations require a reviewed migration before deployment; see [registration plan](../docs/PLUGIN_UPDATES.md).

The `history/` directory preserves the standalone Health builds that still exist from development. They are provided to show the progression of the project and should not be installed alongside v0.8.4.
