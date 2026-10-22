#!/bin/bash
set -euo pipefail

PLUGIN_NAME="waz.dashboard.plg"
PLUGIN_URL="https://raw.githubusercontent.com/TheIlluminate92/unraid-dashboard/main/releases/waz.dashboard.plg"
EXPECTED_SHA256="8ac9ac2700a4c3d003511d012ea973f74baf04dd5a02b74b37dad3426c5e11a8"
SOURCE="${1:-$PLUGIN_URL}"
WORK_DIR="$(mktemp -d /tmp/waz-dashboard-install.XXXXXX)"
PACKAGE="$WORK_DIR/$PLUGIN_NAME"
CONFIG_FILE="/boot/config/plugins/waz.dashboard/waz.dashboard.cfg"
RUNTIME_DIR="/usr/local/emhttp/plugins/waz.dashboard"
trap 'rm -rf "$WORK_DIR"' EXIT

echo "Preparing a checksum-verified canonical Unraid Dashboard installer..."
case "$SOURCE" in
  http://*|https://*) wget -qO "$PACKAGE" "$SOURCE" ;;
  *) [ -f "$SOURCE" ] || { echo "Package not found: $SOURCE" >&2; exit 1; }
     cp "$SOURCE" "$PACKAGE" ;;
esac
ACTUAL_SHA256="$(sha256sum "$PACKAGE" | awk '{print $1}')"
if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
  echo "Package checksum mismatch; the installed plugin was not changed." >&2
  exit 1
fi

# Preserve a unique backup; never remove the installed plugin to perform an update.
if [ -f "$CONFIG_FILE" ]; then
  CONFIG_BACKUP="$(mktemp /boot/config/plugins/waz.dashboard.cfg.preinstall.XXXXXX)"
  cp -p "$CONFIG_FILE" "$CONFIG_BACKUP"
  echo "Configuration backup retained at $CONFIG_BACKUP"
fi
plugin install "$PACKAGE" forced
[ -d "$RUNTIME_DIR" ] || { echo "Installation did not create $RUNTIME_DIR" >&2; exit 1; }
echo "Unraid Dashboard installed under $PLUGIN_NAME. Reload the Unraid WebUI."
echo "Future releases use Plugins > Check for Updates."
