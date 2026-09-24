#!/usr/bin/env bash
# Copy WordPress core, vendor plugins and themes from production to dev so the
# versions match. Prod pushes straight to dev (same datacenter) using the key set
# up by ops/dev/setup-prod-push.sh; nothing passes through this machine, and dev
# never gets access to prod.
# Excludes uploads, caches, backups, exports, git, docs, dumps and wp-config.php.
# Afterwards verifies core + wordpress.org plugin checksums on dev.
#
# Usage: ops/dev/sync-code.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

DEV_IP="$(ssh -G "$DEV_HOST_ALIAS" | awk '/^hostname /{print $2}')"
PUSH_SSH="ssh -i $PUSH_KEY -o UserKnownHostsFile=$PUSH_KNOWN_HOSTS -o StrictHostKeyChecking=yes -o BatchMode=yes"
EXCLUDES=(
  --exclude=/wp-config.php --exclude=/.git/ --exclude=/documentation/ --exclude=/.user.ini
  --exclude=/wp-content/uploads/ --exclude=/wp-content/cache/ --exclude=/wp-content/updraft/
  --exclude=/wp-content/upgrade/ --exclude='/wp-content/webtoffee_*' --exclude=/wp-content/w3tc-config/
  --exclude=/wp-content/wp-cloudflare-super-page-cache/ --exclude=/wp-content/debug.log
  --exclude=/wp-content/mu-plugins/0-worker.php
  --exclude='*.sql' --exclude='*.sql.gz' --exclude='*.zip' --exclude='*.bak' --exclude='*.bak-*' --exclude='*.log'
)

log "Pushing code $PROD_HOST:$WP_ROOT -> dev ($DEV_IP):$WP_ROOT"
# Excluded paths on dev (wp-config.php, uploads, ...) are left alone.
ssh "$PROD_HOST" "rsync -a --delete --stats $(printf '%q ' "${EXCLUDES[@]}") --chown=www-data:www-data \
  -e '$PUSH_SSH' '$WP_ROOT/' 'root@$DEV_IP:$WP_ROOT/'" | grep -E '^(Number of (regular )?files transferred|Total transferred file size)'

log "Verifying WordPress core and wordpress.org plugin checksums on dev"
ssh "$DEV_HOST_ALIAS" "cd '$WP_ROOT' && W='wp --allow-root --skip-plugins --skip-themes'; \
  \$W core verify-checksums 2>&1 | tail -3; \
  if \$W core is-installed 2>/dev/null; then \
    \$W plugin verify-checksums --all --format=csv 2>&1 | grep -vE '^plugin_name|could not be verified|No checksums' | head -40 || true; \
    echo '(plugins not on wordpress.org, e.g. premium ones, cannot be verified)'; \
  else echo '(plugin checksums need the database; run after refresh-db.sh)'; fi"
