#!/usr/bin/env bash
# Build (or rebuild) the whole dev environment from scratch:
#   provision droplet -> bootstrap server -> copy core/vendor code from prod ->
#   anonymized DB copy -> baseline deploy state -> deploy main.
# Cloudflare Tunnel/Access is separate: ops/dev/cloudflare-setup.sh (once per droplet).
#
# Usage: ops/dev/rebuild.sh
#   ADOPT_REF=<ref> overrides the commit recorded as deployed (default origin/main;
#   it must match the custom code currently live on prod).
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
REPO="$(cd "$DIR/../.." && pwd)"

"$DIR/provision.sh"
PREFIX="$(ssh "$PROD_HOST" "cd '$WP_ROOT' && wp --allow-root --skip-plugins --skip-themes db prefix")"
ssh "$DEV_HOST_ALIAS" "DEV_DOMAIN='$DEV_DOMAIN' PROD_DOMAIN='$PROD_DOMAIN' WP_ROOT='$WP_ROOT' PHP_VERSION='$PHP_VERSION' \
  DB_NAME='$DB_NAME' DB_USER='$DB_USER' WP_ACCESSIBLE_HOSTS='$WP_ACCESSIBLE_HOSTS' TABLE_PREFIX='$PREFIX' bash -s" < "$DIR/bootstrap.sh"
"$DIR/sync-code.sh"
"$DIR/refresh-db.sh" --yes

# Record what's on dev now. Prod's owned files match origin/main, so adopt that,
# then deploy main normally (a no-op unless main has moved on).
ssh "$DEV_HOST_ALIAS" "rm -f /var/lib/ultralove-deploy/deployed_sha"
git -C "$REPO" fetch -q origin
"$REPO/deploy/deploy.sh" dev --ref "${ADOPT_REF:-origin/main}" --adopt
"$DIR/parity.sh" || true
echo "Dev rebuilt. If this is a new droplet, run ops/dev/cloudflare-setup.sh to reconnect the tunnel."
