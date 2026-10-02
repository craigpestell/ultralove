#!/usr/bin/env bash
# Monthly backup of the production server's configuration and secrets that aren't in git (installed as
# /root/monthly_system_backup.sh by ops/do-migration/bootstrap-app-server.sh; root cron, 02:40 UTC on the 1st).
# Produces one archive per UTC month with a checksum and an inventory, and uploads them to DigitalOcean Spaces
# (s3://ultralove-web-backups/system-config/<YYYY-MM>/). Spaces credentials come from /root/.spaces_env
# (SPACES_ACCESS_KEY_ID, SPACES_SECRET) - never from this file.
set -euo pipefail

BACKUP_ROOT="${BACKUP_ROOT:-/root/system_monthly_backups}"
MONTH_TAG="${MONTH_TAG:-$(date -u +%Y-%m)}"
OUTPUT_DIR="$BACKUP_ROOT/$MONTH_TAG"
ARCHIVE="$OUTPUT_DIR/system-config-$MONTH_TAG.tar.gz"
CHECKSUM_FILE="$OUTPUT_DIR/system-config-$MONTH_TAG.sha256"
INVENTORY_FILE="$OUTPUT_DIR/inventory-$MONTH_TAG.txt"
TMP_ARCHIVE="$ARCHIVE.tmp"
SPACES_ENV_FILE="${SPACES_ENV_FILE:-/root/.spaces_env}"

log() {
  printf '[%s] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

# Keep backup artifacts private because they contain secrets.
umask 077
mkdir -p "$OUTPUT_DIR"

if [[ -f "$SPACES_ENV_FILE" ]]; then
  # shellcheck source=/dev/null
  source "$SPACES_ENV_FILE"
fi
SPACES_SECRET="${SPACES_SECRET:-${SPACES_TOKEN:-}}"
SPACES_BUCKET="${SPACES_BUCKET:-ultralove-web-backups}"
SPACES_REGION="${SPACES_REGION:-sfo3}"
SPACES_ENDPOINT="${SPACES_ENDPOINT:-https://sfo3.digitaloceanspaces.com}"
SPACES_PREFIX="${SPACES_PREFIX:-system-config}"

aws_s3() {
  aws --endpoint-url "$SPACES_ENDPOINT" --region "$SPACES_REGION" "$@"
}

# Paths that exist are archived; the rest are skipped. Configuration and secrets not in git: nginx, PHP, the
# Cloudflare Origin CA certificate, Redis, the firewall, cron, the deploy record (what was deployed when), the
# backup scripts and their Spaces credentials, and wp-config.php.
declare -a CANDIDATES=(
  "etc/nginx"
  "etc/php"
  "etc/letsencrypt"
  "etc/ssl/cloudflare"
  "etc/redis"
  "etc/ufw"
  "etc/cron.d"
  "etc/cron.daily"
  "etc/cron.weekly"
  "etc/cron.monthly"
  "etc/crontab"
  "etc/systemd/system"
  "var/spool/cron/crontabs/root"
  "var/lib/ultralove-deploy/deployed_sha"
  "var/lib/ultralove-deploy/deploy.log"
  "root/monthly_system_backup.sh"
  "root/uploads_spaces_sync.sh"
  "root/.spaces_env"
  "root/.profile"
  "var/www/html/wp-config.php"
)

declare -a INCLUDE_PATHS=()
for p in "${CANDIDATES[@]}"; do
  if [[ -e "/$p" ]]; then
    INCLUDE_PATHS+=("$p")
  fi
done

if [[ ${#INCLUDE_PATHS[@]} -eq 0 ]]; then
  log "No configured paths exist; nothing to back up."
  exit 1
fi

log "Creating monthly config backup: $ARCHIVE"
log "Path count: ${#INCLUDE_PATHS[@]}"

printf '%s\n' "${INCLUDE_PATHS[@]}" > "$INVENTORY_FILE"

tar -C / -czf "$TMP_ARCHIVE" "${INCLUDE_PATHS[@]}"
mv "$TMP_ARCHIVE" "$ARCHIVE"
chmod 600 "$ARCHIVE"

sha256sum "$ARCHIVE" > "$CHECKSUM_FILE"
chmod 600 "$CHECKSUM_FILE" "$INVENTORY_FILE"

log "Backup complete"
log "Archive: $ARCHIVE"
log "Checksum: $CHECKSUM_FILE"
log "Inventory: $INVENTORY_FILE"

if ! command -v aws >/dev/null 2>&1 || [[ -z "${SPACES_ACCESS_KEY_ID:-}" || -z "$SPACES_SECRET" ]]; then
  log "Spaces upload skipped (aws cli missing, or SPACES_ACCESS_KEY_ID / SPACES_SECRET unset in $SPACES_ENV_FILE)"
  exit 0
fi

export AWS_ACCESS_KEY_ID="$SPACES_ACCESS_KEY_ID"
export AWS_SECRET_ACCESS_KEY="$SPACES_SECRET"
export AWS_DEFAULT_REGION="$SPACES_REGION"

SPACES_PREFIX="${SPACES_PREFIX#/}"
SPACES_PREFIX="${SPACES_PREFIX%/}"
REMOTE_DIR="s3://$SPACES_BUCKET/$SPACES_PREFIX/$MONTH_TAG/"

log "Uploading monthly artifacts to $REMOTE_DIR"
aws_s3 s3 cp "$ARCHIVE" "$REMOTE_DIR"
aws_s3 s3 cp "$CHECKSUM_FILE" "$REMOTE_DIR"
aws_s3 s3 cp "$INVENTORY_FILE" "$REMOTE_DIR"
log "Spaces upload complete"
