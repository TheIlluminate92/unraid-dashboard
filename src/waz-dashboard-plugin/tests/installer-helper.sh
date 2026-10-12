#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/boot/config/plugins/waz.dashboard" "$FIXTURE/runtime" "$FIXTURE/bin"
printf 'preserved config\n' > "$FIXTURE/boot/config/plugins/waz.dashboard/waz.dashboard.cfg"
cat > "$FIXTURE/bin/plugin" <<'MOCK'
#!/bin/bash
set -eu
[ "$1" = install ]
[ "${2##*/}" = waz.dashboard.plg ]
[ "$3" = forced ]
printf '%s\n' "$*" >> "$PLUGIN_CALLS"
MOCK
chmod +x "$FIXTURE/bin/plugin"
sed -e "s|/boot/config/plugins|$FIXTURE/boot/config/plugins|g" \
    -e "s|/usr/local/emhttp/plugins/waz.dashboard|$FIXTURE/runtime|g" \
    "$ROOT/releases/install-waz-dashboard.sh" > "$FIXTURE/helper.sh"
export PATH="$FIXTURE/bin:$PATH" PLUGIN_CALLS="$FIXTURE/calls"
bash "$FIXTURE/helper.sh" "$ROOT/releases/waz.dashboard.plg"
[ "$(wc -l < "$PLUGIN_CALLS")" -eq 1 ]
grep -Fxq 'preserved config' "$FIXTURE/boot/config/plugins/waz.dashboard/waz.dashboard.cfg"
BACKUPS=("$FIXTURE"/boot/config/plugins/waz.dashboard.cfg.preinstall.*)
[ "${#BACKUPS[@]}" -eq 1 ]
cmp "${BACKUPS[0]}" "$FIXTURE/boot/config/plugins/waz.dashboard/waz.dashboard.cfg"
printf 'invalid package\n' > "$FIXTURE/invalid.plg"
if bash "$FIXTURE/helper.sh" "$FIXTURE/invalid.plg"; then exit 1; fi
[ "$(wc -l < "$PLUGIN_CALLS")" -eq 1 ]
echo 'Canonical terminal installer and checksum failure passed.'
