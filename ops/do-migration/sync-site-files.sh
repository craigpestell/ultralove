#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"

usage() {
  cat <<'USAGE'
Usage: ./sync-site-files.sh <dev|prod> [--from-local|--from-source-server|--from-prod-server] [--delete] [--dry-run]

Synchronizes website files to the target app droplet DEST_WP_ROOT using rsync over SSH.

Source modes:
  --from-local         SRC_WP_PATH on this machine (default)
  --from-source-server Push directly from this server (no staging)
  --from-prod-server   Pull from old production server, then push to target

Examples:
  ./sync-site-files.sh dev
  ./sync-site-files.sh dev --from-source-server
  ./sync-site-files.sh dev --from-prod-server
  ./sync-site-files.sh dev --dry-run
  ./sync-site-files.sh prod --delete
USAGE
}

if [[ $# -lt 1 ]]; then
  usage >&2
  exit 1
fi

TARGET_ENV="$1"
shift

SOURCE_MODE="local"
DELETE_FLAG=""
DRY_RUN_FLAG=""
for arg in "$@"; do
  case "$arg" in
    --from-local)
      SOURCE_MODE="local"
      ;;
    --from-source-server)
      SOURCE_MODE="source-server"
      ;;
    --from-prod-server)
      SOURCE_MODE="prod-server"
      ;;
    --delete)
      DELETE_FLAG="--delete"
      ;;
    --dry-run)
      DRY_RUN_FLAG="--dry-run"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing ${ENV_FILE}. Copy .env.example to .env and fill values." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

require_var() {
  local key="$1"
  if [[ -z "${!key:-}" ]]; then
    echo "Missing required variable: ${key}" >&2
    exit 1
  fi
}

DEST_WP_ROOT="${DEST_WP_ROOT:-${WP_ROOT:-/var/www/html}}"
SSH_PORT="${APP_SSH_PORT:-22}"
RSYNC_SSH="ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"

case "$TARGET_ENV" in
  dev)
    TARGET_HOST="${DEV_NEW_APP_IP:-}"
    TARGET_USER="${DEV_NEW_APP_SSH_USER:-root}"
    ;;
  prod)
    TARGET_HOST="${PROD_NEW_APP_IP:-}"
    TARGET_USER="${PROD_NEW_APP_SSH_USER:-root}"
    ;;
  *)
    echo "Environment must be 'dev' or 'prod'." >&2
    usage >&2
    exit 1
    ;;
esac

if [[ -z "$TARGET_HOST" ]]; then
  echo "Target host IP is missing for '${TARGET_ENV}'." >&2
  if [[ "$TARGET_ENV" == "dev" ]]; then
    echo "Set DEV_NEW_APP_IP in .env" >&2
  else
    echo "Set PROD_NEW_APP_IP in .env" >&2
  fi
  exit 1
fi

RSYNC_ARGS=(
  -az
  --human-readable
  --partial
  --exclude=.git/
  --exclude=.github/
  --exclude=node_modules/
  --exclude=wp-content/cache/
  --exclude=wp-content/uploads/cache/
)

# macOS ships an older rsync that does not support --info=progress2.
if rsync --help 2>&1 | grep -q -- '--info=FLAGS'; then
  RSYNC_ARGS+=(--info=progress2)
else
  RSYNC_ARGS+=(--progress)
fi

if [[ -n "$DELETE_FLAG" ]]; then
  RSYNC_ARGS+=("$DELETE_FLAG")
fi

if [[ -n "$DRY_RUN_FLAG" ]]; then
  RSYNC_ARGS+=("$DRY_RUN_FLAG")
fi

if [[ "$SOURCE_MODE" == "prod-server" && -n "$DRY_RUN_FLAG" ]]; then
  echo "--dry-run is not supported with --from-prod-server mode." >&2
  echo "Use --from-local/--from-source-server for dry runs, or run without --dry-run." >&2
  exit 1
fi

if [[ -z "$DRY_RUN_FLAG" ]]; then
  # Ensure target root exists before syncing.
  ssh -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new "${TARGET_USER}@${TARGET_HOST}" "mkdir -p '${DEST_WP_ROOT}'"
fi

if [[ "$SOURCE_MODE" == "local" || "$SOURCE_MODE" == "source-server" ]]; then
  SRC_WP_PATH="${SRC_WP_PATH:-${LOCAL_WP_SOURCE:-}}"
  if [[ "$SOURCE_MODE" == "source-server" && -z "$SRC_WP_PATH" ]]; then
    SRC_WP_PATH="/var/www/html"
  fi
  require_var SRC_WP_PATH
  if [[ ! -d "$SRC_WP_PATH" ]]; then
    echo "SRC_WP_PATH does not exist or is not a directory: $SRC_WP_PATH" >&2
    exit 1
  fi

  echo "Syncing files to ${TARGET_ENV} app: ${TARGET_USER}@${TARGET_HOST}:${DEST_WP_ROOT}"
  if [[ "$SOURCE_MODE" == "source-server" ]]; then
    echo "Source mode: this server direct push (no staging)"
  else
    echo "Source mode: local"
  fi
  echo "Source: ${SRC_WP_PATH}"

  rsync "${RSYNC_ARGS[@]}" -e "$RSYNC_SSH" "${SRC_WP_PATH%/}/" "${TARGET_USER}@${TARGET_HOST}:${DEST_WP_ROOT}/"
else
  SRC_APP_IP="${SRC_APP_IP:-${PROD_OLD_APP_IP:-}}"
  SRC_APP_SSH_USER="${SRC_APP_SSH_USER:-${PROD_OLD_APP_SSH_USER:-root}}"
  SRC_APP_SSH_PORT="${SRC_APP_SSH_PORT:-${PROD_OLD_APP_SSH_PORT:-22}}"
  SRC_WP_PATH="${SRC_WP_PATH:-${PROD_OLD_WP_SOURCE:-/var/www/html}}"
  SYNC_STAGING_DIR="${SYNC_STAGING_DIR:-${SCRIPT_DIR}/.sync-staging/${TARGET_ENV}}"

  if [[ -z "$SRC_APP_IP" ]]; then
    echo "Set SRC_APP_IP in .env for --from-prod-server mode." >&2
    exit 1
  fi

  mkdir -p "$SYNC_STAGING_DIR"

  echo "Syncing files to ${TARGET_ENV} app: ${TARGET_USER}@${TARGET_HOST}:${DEST_WP_ROOT}"
  echo "Source mode: old production server via local staging"
  echo "Pulling from: ${SRC_APP_SSH_USER}@${SRC_APP_IP}:${SRC_WP_PATH}"
  echo "Staging path: ${SYNC_STAGING_DIR}"

  SOURCE_RSYNC_SSH="ssh -p ${SRC_APP_SSH_PORT} -o StrictHostKeyChecking=accept-new"

  rsync "${RSYNC_ARGS[@]}" -e "$SOURCE_RSYNC_SSH" "${SRC_APP_SSH_USER}@${SRC_APP_IP}:${SRC_WP_PATH%/}/" "${SYNC_STAGING_DIR%/}/"
  rsync "${RSYNC_ARGS[@]}" -e "$RSYNC_SSH" "${SYNC_STAGING_DIR%/}/" "${TARGET_USER}@${TARGET_HOST}:${DEST_WP_ROOT}/"
fi

if [[ -z "$DRY_RUN_FLAG" ]]; then
  # Ensure web server ownership after sync.
  ssh -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new "${TARGET_USER}@${TARGET_HOST}" "chown -R www-data:www-data '${DEST_WP_ROOT}'"
fi

echo "File sync completed for ${TARGET_ENV}."
