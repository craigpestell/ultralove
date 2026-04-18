#!/usr/bin/env bash
set -euo pipefail

# Parallel launcher for cloudinary-sync-recover-safe.php.
#
# Usage:
#   ./run-cloudinary-workers.sh [workers] [limit]
#
# Env overrides:
#   DRY_RUN=0|1 (default: 0)
#   DELAY_MS=250
#   BATCH_PAUSE_MS=3000
#   BATCH_SIZE=40
#   WORKERS=4
#   LIMIT=400
#   OFFSET=0
#   PROGRESS_INTERVAL=10
#   WP_CONTENT_DIR=/var/www/html/wp-content
#   SYNC_SCRIPT=cloudinary-sync-recover-safe.php
#   LOG_DIR=/tmp/cld-sync-<timestamp>

WORKERS="${1:-${WORKERS:-4}}"
LIMIT="${2:-${LIMIT:-400}}"
DRY_RUN="${DRY_RUN:-0}"
DELAY_MS="${DELAY_MS:-250}"
BATCH_PAUSE_MS="${BATCH_PAUSE_MS:-3000}"
BATCH_SIZE="${BATCH_SIZE:-40}"
OFFSET="${OFFSET:-0}"
PROGRESS_INTERVAL="${PROGRESS_INTERVAL:-10}"
WP_CONTENT_DIR="${WP_CONTENT_DIR:-/var/www/html/wp-content}"
SYNC_SCRIPT="${SYNC_SCRIPT:-cloudinary-sync-recover-safe.php}"
LOG_DIR="${LOG_DIR:-/tmp/cld-sync-$(date +%Y%m%d_%H%M%S)}"

if ! [[ "$WORKERS" =~ ^[0-9]+$ ]] || [[ "$WORKERS" -lt 1 ]]; then
  echo "ERROR: WORKERS must be a positive integer" >&2
  exit 1
fi

if ! [[ "$LIMIT" =~ ^[0-9]+$ ]] || [[ "$LIMIT" -lt 1 ]]; then
  echo "ERROR: LIMIT must be a positive integer" >&2
  exit 1
fi

if ! [[ "$DRY_RUN" =~ ^[01]$ ]]; then
  echo "ERROR: DRY_RUN must be 0 or 1" >&2
  exit 1
fi

if [[ ! -f "$WP_CONTENT_DIR/$SYNC_SCRIPT" ]]; then
  echo "ERROR: Sync script not found at $WP_CONTENT_DIR/$SYNC_SCRIPT" >&2
  exit 1
fi

if ! command -v wp >/dev/null 2>&1; then
  echo "ERROR: wp CLI not found" >&2
  exit 1
fi

mkdir -p "$LOG_DIR"

echo "=== Cloudinary Parallel Launcher ==="
echo "Workers          : $WORKERS"
echo "Limit (global)   : $LIMIT"
echo "Dry run          : $DRY_RUN"
echo "Delay ms         : $DELAY_MS"
echo "Batch pause ms   : $BATCH_PAUSE_MS"
echo "Batch size       : $BATCH_SIZE"
echo "Offset           : $OFFSET"
echo "Progress interval: ${PROGRESS_INTERVAL}s"
echo "WP content dir   : $WP_CONTENT_DIR"
echo "Sync script      : $SYNC_SCRIPT"
echo "Log dir          : $LOG_DIR"
echo

pids=()
logs=()

cleanup() {
  echo
  echo "Stopping workers..."
  for pid in "${pids[@]:-}"; do
    if kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
    fi
  done
}
trap cleanup INT TERM

for ((i=0; i<WORKERS; i++)); do
  log="$LOG_DIR/worker-$i.log"
  logs+=("$log")

  (
    cd "$WP_CONTENT_DIR"
    CLD_DRY_RUN="$DRY_RUN" \
    CLD_DELAY_MS="$DELAY_MS" \
    CLD_BATCH_PAUSE_MS="$BATCH_PAUSE_MS" \
    CLD_BATCH_SIZE="$BATCH_SIZE" \
    CLD_LIMIT="$LIMIT" \
    CLD_OFFSET="$OFFSET" \
    CLD_WORKER_TOTAL="$WORKERS" \
    CLD_WORKER_INDEX="$i" \
    stdbuf -oL -eL wp eval-file "$SYNC_SCRIPT" --allow-root
  ) >"$log" 2>&1 &

  pids+=("$!")
  echo "Started worker $i (pid=${pids[$i]}) -> $log"
done

echo

actionable_summary() {
  local worker="$1"
  local log="$2"
  local hb

  hb="$(grep -E '>>> HEARTBEAT:' "$log" | tail -n 1 || true)"
  if [[ -n "$hb" ]]; then
    echo "$hb" | sed -n "s/.*processed=\([0-9]\+\)\/\([0-9]\+\), done=\([0-9]\+\), skipped=\([0-9]\+\), errors=\([0-9]\+\).*/worker=${worker} processed=\1\/\2 done=\3 skipped=\4 errors=\5/p"
    return
  fi

  if grep -q '=== SUMMARY' "$log"; then
    local d s e
    d="$(sed -n 's/^Processed (attempted): \([0-9]\+\)$/\1/p' "$log" | tail -n 1)"
    s="$(sed -n 's/^Skipped *: \([0-9]\+\)$/\1/p' "$log" | tail -n 1)"
    e="$(sed -n 's/^Errors *: \([0-9]\+\)$/\1/p' "$log" | tail -n 1)"
    d="${d:-0}"; s="${s:-0}"; e="${e:-0}"
    echo "worker=${worker} done=$d skipped=$s errors=$e status=finished"
    return
  fi

  echo "worker=${worker} status=starting"
}

while true; do
  alive=0
  echo "--- $(date '+%F %T') progress ---"

  for i in "${!pids[@]}"; do
    pid="${pids[$i]}"
    log="${logs[$i]}"

    if kill -0 "$pid" 2>/dev/null; then
      alive=$((alive + 1))
    fi

    actionable_summary "$i" "$log"
  done

  echo "alive_workers=$alive/$WORKERS"
  echo

  if [[ "$alive" -eq 0 ]]; then
    break
  fi

  sleep "$PROGRESS_INTERVAL"
done

exit_code=0
for i in "${!pids[@]}"; do
  if wait "${pids[$i]}"; then
    rc=0
  else
    rc=$?
  fi
  echo "worker=$i exit_code=$rc log=${logs[$i]}"
  if [[ "$rc" -ne 0 ]]; then
    exit_code=1
  fi
done

echo
if [[ "$exit_code" -eq 0 ]]; then
  echo "All workers completed successfully."
else
  echo "One or more workers failed. Check logs in $LOG_DIR" >&2
fi

exit "$exit_code"
