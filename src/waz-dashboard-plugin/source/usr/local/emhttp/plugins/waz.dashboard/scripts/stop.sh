#!/bin/bash

STATE_DIR="/var/run/waz.dashboard"

stop_process() {
  local PID_FILE="$1"
  local PATTERN="$2"
  if [ -s "$PID_FILE" ]; then
    local PID
    PID="$(cat "$PID_FILE" 2>/dev/null)"
    if [ -n "$PID" ] && [ -r "/proc/$PID/cmdline" ] && tr '\0' ' ' < "/proc/$PID/cmdline" | grep -q "$PATTERN"; then
      kill "$PID" 2>/dev/null || true
      for _ in 1 2 3 4 5; do
        kill -0 "$PID" 2>/dev/null || break
        sleep 1
      done
      if kill -0 "$PID" 2>/dev/null; then
        echo "GPU watcher did not stop; refusing to replace its runtime." >&2
        return 1
      fi
    fi
  fi
  rm -f "$PID_FILE"
}

stop_process "$STATE_DIR/gpu-sampler.pid" '/waz.dashboard/include/gpu-sampler.php' || exit 1
rm -f "$STATE_DIR/gpu.json"
