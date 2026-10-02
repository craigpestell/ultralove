#!/usr/bin/env bash
# Nightly off-site copy of the WordPress media library (wp-content/uploads) to DigitalOcean Spaces
# (s3://ultralove-web-backups/uploads/). Installed as /root/uploads_spaces_sync.sh by
# ops/do-migration/bootstrap-app-server.sh; root cron, 03:30 UTC.
#
# Only part of the media is on Cloudinary and UpdraftPlus's backups leave uploads out, so without this the media
# library would exist only on the droplet's disk. `aws s3 sync` uploads new and changed files only (the first run
# copies everything, ~17 GB in Oct 2026). Deletions are NOT mirrored: a file deleted from the site by mistake
# stays in the backup. Runs at the lowest CPU and disk priority. Credentials come from /root/.spaces_env.
set -euo pipefail

SPACES_ENV_FILE="${SPACES_ENV_FILE:-/root/.spaces_env}"
UPLOADS_DIR="${UPLOADS_DIR:-/var/www/html/wp-content/uploads}"

log() {
  printf '[%s] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

if [[ -f "$SPACES_ENV_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$SPACES_ENV_FILE"
fi
SPACES_SECRET="${SPACES_SECRET:-${SPACES_TOKEN:-}}"
SPACES_BUCKET="${SPACES_BUCKET:-ultralove-web-backups}"
SPACES_REGION="${SPACES_REGION:-sfo3}"
SPACES_ENDPOINT="${SPACES_ENDPOINT:-https://sfo3.digitaloceanspaces.com}"
SPACES_PREFIX="${SPACES_PREFIX:-uploads}"

if ! command -v aws >/dev/null 2>&1 || [[ -z "${SPACES_ACCESS_KEY_ID:-}" || -z "$SPACES_SECRET" ]]; then
  log "Skipped: aws cli missing, or SPACES_ACCESS_KEY_ID / SPACES_SECRET unset in $SPACES_ENV_FILE"
  exit 1
fi
[[ -d "$UPLOADS_DIR" ]] || { log "Skipped: $UPLOADS_DIR not found"; exit 1; }

export AWS_ACCESS_KEY_ID="$SPACES_ACCESS_KEY_ID"
export AWS_SECRET_ACCESS_KEY="$SPACES_SECRET"
export AWS_DEFAULT_REGION="$SPACES_REGION"

REMOTE="s3://$SPACES_BUCKET/${SPACES_PREFIX%/}/"
log "Syncing $UPLOADS_DIR/ to $REMOTE (new and changed files; deletions not mirrored)"
nice -n 19 ionice -c3 aws --endpoint-url "$SPACES_ENDPOINT" --region "$SPACES_REGION" \
  s3 sync "$UPLOADS_DIR/" "$REMOTE" --no-progress --only-show-errors
log "Uploads sync complete"
