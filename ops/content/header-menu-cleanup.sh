#!/usr/bin/env bash
# Slim the desktop header menu and give mobile its own full menu.
#
# Menus live in the database, so deploy.sh doesn't carry them. Run this per
# environment (dev first, prod after sign-off). Idempotent; re-run after a dev
# DB refresh until prod has it.
#
#   1. Create a "Mobile" menu with every item currently in the primary menu and
#      assign it to the `mobile` location (Martfury's mobile nav otherwise falls
#      back to the primary menu, so trimming primary would trim mobile too).
#   2. Remove from the primary menu the items already covered by the footer or the
#      header itself: Home (logo), Cart (cart icon), Ordering / About Us
#      (footer "Footer Links" menu). Contact stays in the header by request.
#
# A CSV of the menus before the change is saved in /var/lib/ultralove-deploy/content-backups/.
#
# Usage: ops/content/header-menu-cleanup.sh <dev|prod>
source "$(dirname "$0")/../../deploy/lib.sh"
load_env "${1:?Usage: ops/content/header-menu-cleanup.sh <dev|prod>}"

REMOVE_TITLES='Home|Cart|Ordering|About Us'

remote "set -euo pipefail; cd '$WP_ROOT'; W='wp --allow-root --skip-plugins --skip-themes'
MAIN_ID=\$(\$W menu list --fields=term_id,locations --format=csv | awk -F, '\$2 ~ /primary/{print \$1}')
[ -n \"\$MAIN_ID\" ] || { echo 'No menu assigned to primary'; exit 1; }

B=/var/lib/ultralove-deploy/content-backups; mkdir -p \$B; TS=\$(date -u +%Y%m%dT%H%M%SZ)
for m in \$(\$W menu list --fields=term_id --format=ids); do
  \$W menu item list \$m --fields=db_id,menu_item_parent,position,title,url,type,object,object_id --format=csv | sed \"s/^/\$m,/\"
done > \$B/menus-\$TS.csv
echo \"Backup: \$B/menus-\$TS.csv\"

# 1. Mobile menu = copy of the current primary menu (only if it doesn't exist yet).
if ! \$W menu list --fields=name --format=csv | grep -qx Mobile; then
  \$W menu create Mobile --porcelain >/dev/null
  \$W menu item list \$MAIN_ID --fields=title,url,type,object,object_id --format=json | python3 -c '
import json, subprocess, sys
for it in json.load(sys.stdin):
    base = [\"wp\", \"--allow-root\", \"--skip-plugins\", \"--skip-themes\", \"menu\", \"item\"]
    if it[\"type\"] == \"post_type\":
        cmd = base + [\"add-post\", \"Mobile\", str(it[\"object_id\"]), \"--title=\" + it[\"title\"]]
    elif it[\"type\"] == \"taxonomy\":
        cmd = base + [\"add-term\", \"Mobile\", it[\"object\"], str(it[\"object_id\"]), \"--title=\" + it[\"title\"]]
    else:
        cmd = base + [\"add-custom\", \"Mobile\", it[\"title\"], it[\"url\"]]
    subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL)
'
  echo \"Created menu 'Mobile' with \$(\$W menu item list Mobile --format=count) items\"
fi
wp --allow-root --skip-plugins menu location assign Mobile mobile >/dev/null && echo \"Mobile menu assigned to the mobile location\"

# 2. Trim the primary (desktop) menu.
\$W menu item list \$MAIN_ID --fields=db_id,title --format=csv | tail -n +2 | while IFS=, read -r id title; do
  t=\$(echo \"\$title\" | tr -d '\"')
  if echo \"\$t\" | grep -qxE '$REMOVE_TITLES'; then \$W menu item delete \$id >/dev/null && echo \"Removed from header: \$t\"; fi
done
echo \"Header menu now: \$(\$W menu item list \$MAIN_ID --fields=title --format=csv | tail -n +2 | tr -d '\"' | paste -sd '|' - | sed 's/|/ · /g')\"
\$W cache flush >/dev/null 2>&1 || true"
