#!/bin/bash
set -eu

PLUGIN_NAME="waz.dashboard.plg"
PLUGIN_URL="https://raw.githubusercontent.com/TheIlluminate92/waz-control/main/releases/waz.dashboard.plg"
EXPECTED_SHA256="7e9131c89c589ef06b010acec37daffcb9a3071d37caca3365c70e46831aed79"
SOURCE="${1:-$PLUGIN_URL}"
PACKAGE="/tmp/waz.dashboard-install.$$.plg"
CONFIG_DIR="/boot/config/plugins/waz.dashboard"
CONFIG_FILE="$CONFIG_DIR/waz.dashboard.cfg"
CONFIG_BACKUP="/boot/config/plugins/waz.dashboard.cfg.preinstall"
RUNTIME_DIR="/usr/local/emhttp/plugins/waz.dashboard"

cleanup() {
  rm -f "$PACKAGE"
}
trap cleanup EXIT HUP INT TERM

echo "Downloading the new WAZ Control package before removing the installed copy..."
case "$SOURCE" in
  http://*|https://*)
    wget -qO "$PACKAGE" "$SOURCE"
    ;;
  *)
    if [ ! -f "$SOURCE" ]; then
      echo "Package not found: $SOURCE" >&2
      exit 1
    fi
    cp "$SOURCE" "$PACKAGE"
    ;;
esac

ACTUAL_SHA256="$(sha256sum "$PACKAGE" | awk '{print $1}')"
if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
  echo "Package checksum mismatch; the installed plugin was not changed." >&2
  echo "Expected: $EXPECTED_SHA256" >&2
  echo "Received: $ACTUAL_SHA256" >&2
  exit 1
fi

CONFIG_SAVED=0
if [ -f "$CONFIG_FILE" ]; then
  cp -p "$CONFIG_FILE" "$CONFIG_BACKUP"
  CONFIG_SAVED=1
  echo "Saved the existing WAZ configuration to $CONFIG_BACKUP"
elif [ -f "$CONFIG_BACKUP" ]; then
  CONFIG_SAVED=1
  echo "Using the configuration backup retained by an earlier install attempt."
fi

if [ -e "/var/log/plugins/$PLUGIN_NAME" ]; then
  echo "Removing the installed WAZ Control plugin..."
  plugin remove "$PLUGIN_NAME"
elif [ -f "/boot/config/plugins/$PLUGIN_NAME" ]; then
  echo "Removing an unregistered WAZ Control manifest..."
  rm -f "/boot/config/plugins/$PLUGIN_NAME"
else
  echo "No registered WAZ Control plugin was found; continuing with a clean install."
fi

if [ "$CONFIG_SAVED" -eq 1 ]; then
  mkdir -p "$CONFIG_DIR"
  cp -p "$CONFIG_BACKUP" "$CONFIG_FILE"
  echo "Restored the saved WAZ configuration."
fi

echo "Installing the new WAZ Control package..."
plugin install "$PACKAGE" forced

if [ ! -d "$RUNTIME_DIR" ]; then
  echo "Installation did not create $RUNTIME_DIR" >&2
  echo "The saved configuration remains at $CONFIG_BACKUP" >&2
  exit 1
fi

rm -f "$CONFIG_BACKUP"
echo "WAZ Control replacement completed successfully. Reload the Unraid WebUI."
echo "Future releases can be installed from Plugins > Check for Updates."
