#!/usr/bin/env bash
# Compare WordPress core, plugin and theme versions/status between prod and dev.
# Custom code is kept identical by deploy/deploy.sh; this catches vendor drift
# (e.g. a plugin updated on prod but not dev). Fix drift with ops/dev/sync-code.sh.
#
# Usage: ops/dev/parity.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

snapshot() {
  ssh "$1" "cd '$WP_ROOT' && W='wp --allow-root --skip-plugins --skip-themes'; \
    echo \"core \$(\$W core version)\"; \
    \$W plugin list --fields=name,version,status --format=csv | tail -n +2 | sed 's/^/plugin /'; \
    \$W theme list --fields=name,version,status --format=csv | tail -n +2 | sed 's/^/theme /'" | sort
}
snapshot "$PROD_HOST" > "$TMP/prod"
snapshot "$DEV_HOST_ALIAS" > "$TMP/dev"
# Plugins intentionally deactivated on dev aren't drift.
for p in $DEV_DISABLED_PLUGINS; do sed -i.bak -E "s/^(plugin $p,[^,]*),inactive$/\1,active/" "$TMP/dev"; done
if diff -u --label prod --label dev "$TMP/prod" "$TMP/dev"; then
  echo "Parity OK: core, plugins and themes match (versions + status)."
else
  echo; echo "Drift found (- prod, + dev). Run ops/dev/sync-code.sh to copy prod's versions to dev." >&2
  exit 1
fi
