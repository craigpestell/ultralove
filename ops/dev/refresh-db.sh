#!/usr/bin/env bash
# Replace the dev database with an anonymized copy of production.
#
# The dump streams prod -> this machine -> dev MySQL; it is never written to disk.
# Then: anonymize (ops/dev/anonymize.sql), rewrite URLs to the dev domain,
# deactivate plugins that hold live credentials, and create a dev admin login.
#
# Usage: ops/dev/refresh-db.sh [--yes]
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
source "$DIR/../../deploy/lib.sh"

if [[ "${1:-}" != "--yes" ]]; then
  read -r -p "This DROPS the dev database on $DEV_HOST_ALIAS and reloads it from production. Continue? [y/N] " a
  [[ "$a" =~ ^[Yy]$ ]] || die "Aborted"
fi

DWP="cd '$WP_ROOT' && wp --allow-root --skip-plugins --skip-themes"
PREFIX="$(ssh "$PROD_HOST" "cd '$WP_ROOT' && wp --allow-root --skip-plugins --skip-themes db prefix")"
DEV_CNF=/root/.ultralove-dev-db.cnf

log "Recreating dev database $DB_NAME"
ssh "$DEV_HOST_ALIAS" "mysql -e 'DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'"

DEV_IP="$(ssh -G "$DEV_HOST_ALIAS" | awk '/^hostname /{print $2}')"
PUSH_SSH="ssh -i $PUSH_KEY -o UserKnownHostsFile=$PUSH_KNOWN_HOSTS -o StrictHostKeyChecking=yes -o BatchMode=yes root@$DEV_IP"
log "Streaming production DB straight into dev ($DEV_IP); nothing passes through this machine"
START=$SECONDS
# mysqldump directly (wp db export mangles --no-* flags). Credentials are read
# from prod's wp-config into env vars on prod and never printed or sent anywhere.
# shellcheck disable=SC2016
ssh "$PROD_HOST" 'set -euo pipefail; cd '"$WP_ROOT"'; W="wp --allow-root --skip-plugins --skip-themes";
  HP="$($W config get DB_HOST)"; DB="$($W config get DB_NAME)"; U="$($W config get DB_USER)";
  CA="$($W config get MYSQL_SSL_CA 2>/dev/null || true)"; export MYSQL_PWD="$($W config get DB_PASSWORD)";
  IGN=""; for t in woocommerce_sessions wffilemods wfknownfilelist; do IGN="$IGN --ignore-table=$DB.'"$PREFIX"'$t"; done;
  mysqldump -h "${HP%%:*}" -P "${HP##*:}" -u "$U" ${CA:+--ssl-ca="$CA"} --ssl-mode=REQUIRED \
    --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 $IGN "$DB" \
    | gzip -1 | '"$PUSH_SSH"' "gunzip | mysql --defaults-extra-file='"$DEV_CNF"'"'
log "Imported in $((SECONDS - START))s"

log "Anonymizing"
KEEP_SQL="$(for e in $DEV_KEEP_EMAILS; do printf "'%s'," "$(tr '[:upper:]' '[:lower:]' <<<"$e")"; done)"
KEEP_SQL="${KEEP_SQL%,}"; KEEP_SQL="${KEEP_SQL:-''}"
sed -e "s/{{P}}/$PREFIX/g" -e "s/{{KEEP}}/$KEEP_SQL/g" "$DIR/anonymize.sql" \
  | ssh "$DEV_HOST_ALIAS" "mysql --defaults-extra-file=$DEV_CNF --force 2>&1 | grep -v \"doesn't exist\" || true"

log "Verifying anonymization"
LEAKS="$(ssh "$DEV_HOST_ALIAS" "mysql --defaults-extra-file=$DEV_CNF -N -e \"
  SELECT CONCAT('users with real emails: ', COUNT(*)) FROM ${PREFIX}users WHERE user_email NOT LIKE '%@example.invalid' AND LOWER(TRIM(user_email)) NOT IN ($KEEP_SQL) AND LOWER(TRIM(user_login)) NOT IN ($KEEP_SQL);
  SELECT CONCAT('order billing emails: ', COUNT(*)) FROM ${PREFIX}postmeta WHERE meta_key='_billing_email' AND meta_value NOT LIKE '%@example.invalid';
  SELECT CONCAT('order phones: ', COUNT(*)) FROM ${PREFIX}postmeta WHERE meta_key='_billing_phone' AND meta_value NOT IN ('Redacted','');\"")"
printf '    %s\n' "$LEAKS" | sed 's/\t/ /g'
if printf '%s\n' "$LEAKS" | grep -qvE ': 0$'; then die "Anonymization check failed; dev DB still has personal data. Fix anonymize.sql before using dev."; fi

log "Rewriting URLs https://$PROD_DOMAIN -> https://$DEV_DOMAIN"
SKIP="${PREFIX}woocommerce_order_itemmeta,${PREFIX}woocommerce_order_items,${PREFIX}wc_order_product_lookup,${PREFIX}relevanssi,${PREFIX}pmxe_posts,${PREFIX}pmxi_posts,${PREFIX}actionscheduler_actions,${PREFIX}actionscheduler_logs,${PREFIX}wc_orders_meta"
for pair in "https://www.$PROD_DOMAIN|https://$DEV_DOMAIN" "https://$PROD_DOMAIN|https://$DEV_DOMAIN" \
            "https:\\/\\/www.$PROD_DOMAIN|https:\\/\\/$DEV_DOMAIN" "https:\\/\\/$PROD_DOMAIN|https:\\/\\/$DEV_DOMAIN"; do
  from="${pair%%|*}" to="${pair##*|}"
  ssh "$DEV_HOST_ALIAS" "$DWP search-replace '$from' '$to' --all-tables-with-prefix --skip-columns=guid --skip-tables='$SKIP' --report-changed-only --format=count" \
    | sed "s|^|    $from: |"
done

log "Deactivating plugins with live credentials: $DEV_DISABLED_PLUGINS"
for p in $DEV_DISABLED_PLUGINS; do
  ssh "$DEV_HOST_ALIAS" "$DWP plugin deactivate $p --quiet 2>/dev/null" && echo "    $p" || true
done

log "Creating dev admin login"
ADMIN_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 20)"
ssh "$DEV_HOST_ALIAS" "$DWP user create dev-admin dev-admin@example.invalid --role=administrator --user_pass='$ADMIN_PASS' --quiet 2>/dev/null \
  || $DWP user update dev-admin --user_pass='$ADMIN_PASS' --skip-email --quiet; \
  umask 077; printf 'user=dev-admin\npassword=%s\n' '$ADMIN_PASS' > /root/.ultralove-dev-admin"

ssh "$DEV_HOST_ALIAS" "cd '$WP_ROOT' && wp --allow-root cache flush --quiet 2>/dev/null; wp --allow-root elementor flush-css --quiet 2>/dev/null; true"
log "Kept intact: $(ssh "$DEV_HOST_ALIAS" "mysql --defaults-extra-file=$DEV_CNF -N -e \"SELECT GROUP_CONCAT(CONCAT('#', ID, ' ', user_login) SEPARATOR ', ') FROM ${PREFIX}users WHERE LOWER(TRIM(user_email)) IN ($KEEP_SQL) OR LOWER(TRIM(user_login)) IN ($KEEP_SQL)\"")"
log "Dev DB refreshed. wp-admin login: dev-admin / $ADMIN_PASS  (also saved in /root/.ultralove-dev-admin on the droplet)"
