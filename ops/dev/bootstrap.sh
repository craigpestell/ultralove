#!/usr/bin/env bash
# Configure a fresh Ubuntu 26.04 droplet as the Ultralove dev server (Ubuntu's own PHP 8.5).
# Runs ON the droplet as root; invoked by ops/dev/rebuild.sh, which passes
# config.env values as environment variables. Idempotent.
#
# Web traffic only arrives through the Cloudflare Tunnel: nginx listens on
# 127.0.0.1 only, and the DO firewall allows nothing but SSH inbound.
set -euo pipefail
: "${DEV_DOMAIN:?}" "${PROD_DOMAIN:?}" "${WP_ROOT:?}" "${PHP_VERSION:?}" "${DB_NAME:?}" "${DB_USER:?}" "${WP_ACCESSIBLE_HOSTS:?}"
TABLE_PREFIX="${TABLE_PREFIX:-wp_}"
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1
log() { printf '\033[1;34m[bootstrap]\033[0m %s\n' "$*"; }
PHP="php${PHP_VERSION}"

# --- Packages -----------------------------------------------------------------
log "Waiting for first-boot setup (cloud-init / apt) to finish"
cloud-init status --wait >/dev/null 2>&1 || true
APT_LOCK=(-o DPkg::Lock::Timeout=600)
apt-get() { command apt-get "${APT_LOCK[@]}" "$@"; }
log "Installing packages"
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq nginx mysql-server redis-server fail2ban unattended-upgrades ufw rsync unzip curl \
  "$PHP-fpm" "$PHP-cli" "$PHP-mysql" "$PHP-curl" "$PHP-xml" "$PHP-mbstring" "$PHP-zip" "$PHP-gd" \
  "$PHP-intl" "$PHP-imagick" "$PHP-bcmath" "$PHP-soap" "$PHP-redis" "$PHP-igbinary" "$PHP-readline" >/dev/null
# No "$PHP-opcache": OPcache is part of PHP itself since 8.5 (the package no longer exists).

if ! command -v cloudflared >/dev/null; then
  log "Installing cloudflared"
  install -d -m 0755 /usr/share/keyrings
  curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg
  echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main" > /etc/apt/sources.list.d/cloudflared.list
  apt-get update -qq && apt-get install -y -qq cloudflared >/dev/null
fi

if ! command -v wp >/dev/null; then
  log "Installing wp-cli"
  curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
  chmod +x /usr/local/bin/wp
fi

# --- Hardening ----------------------------------------------------------------
log "Hardening SSH, firewall, automatic security updates"
cat > /etc/ssh/sshd_config.d/99-ultralove.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
sshd -t && { systemctl reload ssh 2>/dev/null || true; }
ufw --force default deny incoming >/dev/null
ufw --force default allow outgoing >/dev/null
ufw allow OpenSSH >/dev/null
ufw --force enable >/dev/null
systemctl enable --now fail2ban unattended-upgrades >/dev/null

# --- MySQL --------------------------------------------------------------------
log "Configuring MySQL"
cat > /etc/mysql/mysql.conf.d/99-ultralove.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
mysqlx = OFF
innodb_buffer_pool_size = 2G
max_allowed_packet = 256M
EOF
systemctl restart mysql
DB_CNF=/root/.ultralove-dev-db.cnf
if [[ ! -f "$DB_CNF" ]]; then
  DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 32)"
  mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
            ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
            GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
  umask 077
  printf '[client]\nuser=%s\npassword=%s\ndatabase=%s\n' "$DB_USER" "$DB_PASS" "$DB_NAME" > "$DB_CNF"
fi
DB_PASS="$(awk -F= '/^password=/{print $2}' "$DB_CNF")"

# --- PHP ----------------------------------------------------------------------
log "Configuring PHP-FPM"
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-ultralove.ini" <<'EOF'
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
; Dev: pick up edited PHP files immediately (check timestamps on every request) - no FPM restart needed.
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
EOF
systemctl restart "$PHP-fpm"

# --- nginx (loopback only; cloudflared connects to it) --------------------------
log "Configuring nginx"
mkdir -p "$WP_ROOT" /var/log/wordpress
chown www-data:www-data /var/log/wordpress
touch /var/log/wordpress/debug.log && chown www-data:www-data /var/log/wordpress/debug.log
cat > /etc/nginx/sites-available/ultralove-dev.conf <<EOF
server {
    listen 127.0.0.1:80 default_server;
    server_name ${DEV_DOMAIN};
    root ${WP_ROOT};
    index index.php index.html;
    client_max_body_size 64m;

    # Requests arrive via cloudflared; trust Cloudflare's client IP header.
    set_real_ip_from 127.0.0.1;
    real_ip_header CF-Connecting-IP;

    # Never serve repo docs, DB dumps, backups, scripts or dotfiles.
    location ^~ /documentation/ { deny all; }
    location ~* (\\.sql|\\.sql\\.gz|\\.md|\\.log|\\.sh|\\.bak[^/]*|\\.orig|\\.swp)\$ { deny all; }
    location ~ /\\. { deny all; }

    # Uploads aren't copied to dev: serve local files if present, otherwise
    # redirect to the same file on production.
    location ^~ /wp-content/uploads/ {
        try_files \$uri @prod_uploads;
    }
    location @prod_uploads {
        return 302 https://${PROD_DOMAIN}\$request_uri;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/${PHP}-fpm.sock;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 300;
    }
}
EOF
ln -sf /etc/nginx/sites-available/ultralove-dev.conf /etc/nginx/sites-enabled/ultralove-dev.conf
rm -f /etc/nginx/sites-enabled/default
sed -i 's/^\(\s*\)listen \[::\]:80 default_server;/\1# &/' /etc/nginx/sites-available/default 2>/dev/null || true
nginx -t && systemctl restart nginx

# --- wp-config.php (dev-only; never copied from prod) ----------------------------
if [[ ! -f "$WP_ROOT/wp-config.php" ]]; then
  log "Writing dev wp-config.php with fresh salts"
  SALTS="$(curl -fsSL https://api.wordpress.org/secret-key/1.1/salt/)"
  cat > "$WP_ROOT/wp-config.php" <<EOF
<?php
// Ultralove DEV. Generated by ops/dev/bootstrap.sh. Never copy production's wp-config here.
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

${SALTS}

\$table_prefix = '${TABLE_PREFIX}';

define( 'WP_ENVIRONMENT_TYPE', 'development' );
define( 'WP_HOME', 'https://${DEV_DOMAIN}' );
define( 'WP_SITEURL', 'https://${DEV_DOMAIN}' );

// Code only arrives via deploy/deploy.sh; no plugin/theme installs or edits from wp-admin.
define( 'DISALLOW_FILE_MODS', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'FS_METHOD', 'direct' );

// No scheduled jobs (exports, syncs, emails) unless run by hand: wp cron event run --due-now
define( 'DISABLE_WP_CRON', true );

// Block outbound HTTP to live services (Mailgun, Mailchimp, Cloudinary, sync targets...).
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', '${WP_ACCESSIBLE_HOSTS}' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '/var/log/wordpress/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
define( 'WP_REDIS_PREFIX', 'ultralove-dev:' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
EOF
  chown root:www-data "$WP_ROOT/wp-config.php"
  chmod 640 "$WP_ROOT/wp-config.php"
fi

mkdir -p /var/lib/ultralove-deploy/backups && chmod 700 /var/lib/ultralove-deploy
log "Bootstrap complete: nginx on 127.0.0.1:80, $PHP-fpm, MySQL 8 ($DB_NAME), Redis, cloudflared, wp-cli"
