#!/usr/bin/env bash
# Copy WordPress core, vendor plugins and themes from production to dev so the
# versions match. Goes prod -> local cache -> dev (dev never gets access to prod).
# Excludes uploads, caches, backups, exports, git, docs, dumps and wp-config.php.
# Afterwards verifies core + wordpress.org plugin checksums on dev.
#
# Usage: ops/dev/sync-code.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
source "$DIR/../../deploy/lib.sh"   # for $RSYNC and log helpers

CACHE="${ULTRALOVE_CACHE:-$HOME/.cache/ultralove}/prod-code"
mkdir -p "$CACHE"
EXCLUDES=(
  --exclude=/wp-config.php --exclude=/.git/ --exclude=/documentation/ --exclude=/.user.ini
  --exclude=/wp-content/uploads/ --exclude=/wp-content/cache/ --exclude=/wp-content/updraft/
  --exclude=/wp-content/upgrade/ --exclude='/wp-content/webtoffee_*' --exclude=/wp-content/w3tc-config/
  --exclude=/wp-content/wp-cloudflare-super-page-cache/ --exclude=/wp-content/debug.log
  --exclude=/wp-content/mu-plugins/0-worker.php
  --exclude='*.sql' --exclude='*.sql.gz' --exclude='*.zip' --exclude='*.bak' --exclude='*.bak-*' --exclude='*.log'
)

log "Pulling code from $PROD_HOST:$WP_ROOT into $CACHE"
"$RSYNC" -a --delete --delete-excluded "${EXCLUDES[@]}" "$PROD_HOST:$WP_ROOT/" "$CACHE/"
log "Pushing code to $DEV_HOST_ALIAS:$WP_ROOT"
# Excluded paths (wp-config.php, uploads, ...) on dev are left alone.
"$RSYNC" -a --delete "${EXCLUDES[@]}" --chown=www-data:www-data "$CACHE/" "$DEV_HOST_ALIAS:$WP_ROOT/"

log "Verifying WordPress core and wordpress.org plugin checksums on dev"
ssh "$DEV_HOST_ALIAS" "cd '$WP_ROOT' && W='wp --allow-root --skip-plugins --skip-themes'; \
  \$W core verify-checksums 2>&1 | tail -3; \
  \$W plugin verify-checksums --all --format=csv 2>&1 | grep -vE '^plugin_name|could not be verified|No checksums' | head -40 || true; \
  echo '(plugins not on wordpress.org, e.g. premium ones, cannot be verified)'"
