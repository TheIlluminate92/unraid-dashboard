#!/bin/bash

RUNTIME_DIR="/usr/local/emhttp/plugins/waz.dashboard"
STATE_DIR="/var/run/waz.dashboard"
GPU_PID_FILE="$STATE_DIR/gpu-sampler.pid"

mkdir -p "$STATE_DIR"
exec 9>"$STATE_DIR/gpu-start.lock"
flock -x 9 || exit 1

pid_matches() {
  local PID_FILE="$1"
  local PATTERN="$2"
  [ -s "$PID_FILE" ] || return 1
  local PID
  PID="$(cat "$PID_FILE" 2>/dev/null)"
  [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null && [ -r "/proc/$PID/cmdline" ] && tr '\0' ' ' < "/proc/$PID/cmdline" | grep -q "$PATTERN"
}

if ! pid_matches "$GPU_PID_FILE" '/waz.dashboard/include/gpu-sampler.php'; then
  rm -f "$GPU_PID_FILE"
  nohup /usr/bin/php "$RUNTIME_DIR/include/gpu-sampler.php" 9>&- >/dev/null 2>&1 &
  echo "$!" > "$GPU_PID_FILE"
fi
