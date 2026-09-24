#!/usr/bin/env bash
# Switch a site between the RevSlider banners/header and the ultralove-banners ones.
#   ./switch.sh enable   <ssh-host>   activate plugin, create slides (if none), swap homepage shortcodes
#   ./switch.sh rollback <ssh-host> [purge]   swap homepage back, deactivate plugin ("purge" deletes the slides)
# The header switches by itself: the child theme uses the new header only while the plugin is active.
set -euo pipefail
cmd="${1:?enable|rollback}"; host="${2:?ssh host}"; purge="${3:-}"
here="$(cd "$(dirname "$0")" && pwd)"
wp() { ssh "$host" "cd /var/www/html && sudo -u www-data wp $*"; }
scp -q "$here/enable-new-banners.php" "$here/rollback-to-revslider.php" "$here/../migrate-from-revslider.php" "$host:/tmp/"
ssh "$host" "chmod 644 /tmp/enable-new-banners.php /tmp/rollback-to-revslider.php /tmp/migrate-from-revslider.php"
case "$cmd" in
  enable)
    wp plugin activate ultralove-banners
    wp eval-file /tmp/migrate-from-revslider.php
    wp eval-file /tmp/enable-new-banners.php
    ;;
  rollback)
    wp eval-file /tmp/rollback-to-revslider.php $purge
    wp plugin deactivate ultralove-banners
    ;;
  *) echo "unknown command: $cmd"; exit 1 ;;
esac
# Clearing Autoptimize also triggers a full Cloudflare purge on sites with that plugin set up.
wp autoptimize clear || true
wp elementor flush_css || true
ssh "$host" "rm -f /tmp/enable-new-banners.php /tmp/rollback-to-revslider.php /tmp/migrate-from-revslider.php"
echo "done: $cmd on $host"
