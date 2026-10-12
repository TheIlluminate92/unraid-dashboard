#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_PATH="${1:-$PROJECT_DIR/dist/waz.dashboard.plg}"
APP_VERSION="$(tr -d '\r\n' < "$PROJECT_DIR/VERSION")"
RELEASE_VERSION="$(tr -d '\r\n' < "$PROJECT_DIR/RELEASE")"
FILES_PATH="$(mktemp)"
trap 'rm -f "$FILES_PATH"' EXIT

emit_file() {
  local SOURCE_FILE="$1"
  local RELATIVE="$2"
  local INTEGRATED_HEALTH="${3:-false}"

  if grep -q ']]>' "$SOURCE_FILE"; then
    echo "Source file cannot be embedded in CDATA: $SOURCE_FILE" >&2
    exit 1
  fi
  printf '<FILE Name="%s">\n<INLINE>\n<![CDATA[\n' "$RELATIVE" >> "$FILES_PATH"
  if [ "$INTEGRATED_HEALTH" = true ]; then
    sed \
      -e "s/@@APP_VERSION@@/$APP_VERSION/g;s/@@PLUGIN_VERSION@@/$RELEASE_VERSION/g" \
      -e 's|/boot/config/plugins/waz.health/waz.health.cfg|/boot/config/plugins/waz.dashboard/waz.dashboard.cfg|g' \
      -e 's|/usr/local/emhttp/plugins/waz.health|/usr/local/emhttp/plugins/waz.dashboard|g' \
      -e 's|/plugins/waz.health|/plugins/waz.dashboard|g' \
      -e 's|/var/tmp/waz-health-throttle-state.json|/var/run/waz.dashboard/health-throttle-state.json|g' \
      "$SOURCE_FILE" >> "$FILES_PATH"
  else
    sed "s/@@APP_VERSION@@/$APP_VERSION/g;s/@@PLUGIN_VERSION@@/$RELEASE_VERSION/g" "$SOURCE_FILE" >> "$FILES_PATH"
  fi
  printf '\n]]>\n</INLINE>\n</FILE>\n' >> "$FILES_PATH"
}

while IFS= read -r -d '' SOURCE_FILE; do
  RELATIVE="/${SOURCE_FILE#"$PROJECT_DIR/source/"}"
  emit_file "$SOURCE_FILE" "$RELATIVE"
done < <(find "$PROJECT_DIR/source" -type f -print0 | sort -z)

HEALTH_SOURCE="$PROJECT_DIR/../waz-health-plugin/source/usr/local/emhttp/plugins/waz.health"
emit_file "$HEALTH_SOURCE/WazHealthBanner.page" '/usr/local/emhttp/plugins/waz.dashboard/WazHealthBanner.page' true
emit_file "$HEALTH_SOURCE/assets/css/banner.css" '/usr/local/emhttp/plugins/waz.dashboard/assets/css/banner.css' true
emit_file "$HEALTH_SOURCE/assets/js/banner.js" '/usr/local/emhttp/plugins/waz.dashboard/assets/js/banner.js' true
emit_file "$HEALTH_SOURCE/include/health.php" '/usr/local/emhttp/plugins/waz.dashboard/include/health.php' true
emit_file "$HEALTH_SOURCE/include/status.php" '/usr/local/emhttp/plugins/waz.dashboard/include/status.php' true

mkdir -p "$(dirname "$OUTPUT_PATH")"
awk -v files="$FILES_PATH" -v app="$APP_VERSION" -v release="$RELEASE_VERSION" '
  /@@FILES@@/ { while ((getline line < files) > 0) print line; close(files); next }
  { gsub(/@@APP_VERSION@@/, app); gsub(/@@RELEASE_VERSION@@/, release); print }
' "$PROJECT_DIR/plugin/waz.dashboard.plg.in" > "$OUTPUT_PATH"

echo "Built $OUTPUT_PATH"
