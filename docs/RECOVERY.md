# Recovery

Unraid Dashboard adds plugin-owned files and does not permanently replace Unraid core files.

## Normal removal

Use the Unraid Plugins page, or run:

```bash
plugin remove waz.dashboard.plg
```

The uninstall handler stops the GPU watcher and removes:

- `/usr/local/emhttp/plugins/waz.dashboard`
- `/boot/config/plugins/waz.dashboard`
- `/var/run/waz.dashboard`
- `/boot/config/plugins/waz.dashboard.plg`

Reload the WebUI afterward.

## If the WebUI is unusable

Use SSH or the local console and run the normal removal command above. If Plugin Manager cannot execute it, move only the exact dashboard manifest out of the active plugin directory, remove the RAM-backed runtime, and reboot:

```bash
mv /boot/config/plugins/waz.dashboard.plg /boot/config/plugins/waz.dashboard.plg.disabled
rm -rf /usr/local/emhttp/plugins/waz.dashboard
reboot
```

The final two commands are intentionally narrow. Do not use broad recursive deletion against `/boot/config/plugins`, `/usr/local/emhttp/plugins`, or another parent directory.

## Restore standalone WAZ Health

If the old manifest was preserved by migration and the dashboard plugin has been removed:

```bash
mv /boot/config/plugins/waz.health.plg.disabled /boot/config/plugins/waz.health.plg
plugin install /boot/config/plugins/waz.health.plg
```
