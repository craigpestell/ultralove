#!/usr/bin/env bash
# Build the replacement production app droplet (Ubuntu 26.04, PHP 8.5) with production's server configuration,
# taken from this repo's ops/ directory (captured from the 24.04 / PHP 8.3 droplet, Oct 2026):
#   ops/php/99-ultralove-production.ini   PHP settings and OPcache sizes
#   ops/php/www.conf                       PHP-FPM pool, sized for s-4vcpu-8gb
#   ops/nginx/10-ultralove-http.conf       http-level tuning (keepalive, upload size, gzip)
#   ops/nginx/99-ultralove-rate-limit-zones.conf, ultralove.ca.conf, localhost-admin.conf
# plus Redis (1 GB cap), the firewall (SSH + HTTP/S from Cloudflare only), Cloudflare client IPs for nginx, the
# WordPress cron runner and the off-site backups to Spaces (ops/prod: monthly config archive, nightly uploads sync;
# they need /root/.spaces_env, copied by hand - see README.md).
#
# Run ON the new droplet as root, from a copy of the repo's ops/ directory, after copying the Cloudflare Origin
# CA certificate (see README.md):
#   tar -czf - --exclude=.env ops/do-migration ops/php ops/nginx ops/prod | ssh root@NEW_IP 'tar -xzf - -C /root'
#   ssh root@NEW_IP /root/ops/do-migration/bootstrap-app-server.sh
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

OPS="$(cd "$(dirname "$0")/.." && pwd)"
WP_ROOT=/var/www/html
PHP_VERSION="8.5"
CERT=/etc/ssl/cloudflare/ultralove.ca.pem
CERT_KEY=/etc/ssl/cloudflare/ultralove.ca.key

if [[ ! -s "$CERT" || ! -s "$CERT_KEY" ]]; then
  echo "Copy the Cloudflare Origin CA certificate first: $CERT and $CERT_KEY (see ops/do-migration/README.md)" >&2
  exit 1
fi

# --- Hostname: what the droplet does (the SSH alias stays ultralove-prod-app). cloud-init would otherwise reset it
# and /etc/hosts at boot.
HOST_NAME="${HOST_NAME:-ultralove-prod-web}"
hostnamectl set-hostname "$HOST_NAME"
sed -i "s/^127\.0\.1\.1 .*/127.0.1.1 $HOST_NAME $HOST_NAME/" /etc/hosts
printf 'preserve_hostname: true\nmanage_etc_hosts: false\n' > /etc/cloud/cloud.cfg.d/99-ultralove-hostname.cfg

export DEBIAN_FRONTEND=noninteractive
apt-get update -y

package_available() {
  local pkg="$1"
  local candidate
  candidate="$(apt-cache policy "$pkg" 2>/dev/null | awk '/Candidate:/ {print $2}')"
  [[ -n "$candidate" && "$candidate" != "(none)" ]]
}

# --- Packages: PHP 8.5 (Ubuntu 26.04's own) with the extensions production uses - no opcache package, OPcache
# is part of PHP itself since 8.5 - and the rest of production's stack.
PHP_PACKAGES=()
for ext in fpm cli mysql curl xml mbstring zip gd intl imagick bcmath soap redis igbinary readline; do
  PHP_PACKAGES+=("php${PHP_VERSION}-${ext}")
done
apt-get install -y nginx "${PHP_PACKAGES[@]}" redis-server ufw fail2ban sysstat atop unzip curl mariadb-client

if command -v aws >/dev/null 2>&1; then
  echo "aws CLI already installed: $(aws --version 2>&1 | head -n 1)"
elif package_available awscli; then
  apt-get install -y awscli
else
  echo "awscli package not available in this repository; skipping awscli installation"
fi

if ! command -v wp >/dev/null 2>&1; then
  curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
  chmod +x /usr/local/bin/wp
fi

# --- PHP: production settings and pool; rotate the slow log (it grew unrotated to 50+ MB on the old droplet).
install -m 644 "$OPS/php/99-ultralove-production.ini" "/etc/php/$PHP_VERSION/fpm/conf.d/99-ultralove-production.ini"
install -m 644 "$OPS/php/www.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"
cat >"/etc/logrotate.d/php$PHP_VERSION-fpm-slowlog" <<EOF
/var/log/php$PHP_VERSION-fpm.www-slow.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    copytruncate
}
EOF

# --- Redis (WordPress object cache): capped at 1 GB, least-frequently-used keys evicted first (the old droplet
# had 3 GB on 16 GB RAM and used ~460 MB at peak). Later directives win, so the block is appended.
if ! grep -q "ultralove: object cache limits" /etc/redis/redis.conf; then
  printf '\n# ultralove: object cache limits\nmaxmemory 1gb\nmaxmemory-policy allkeys-lfu\n' >>/etc/redis/redis.conf
fi

# --- nginx: production's http tuning, rate-limit zones, site and local status page; Cloudflare's client IPs
# (all web traffic comes through Cloudflare, so logs and the per-IP rate limits see the visitor, not the edge);
# unknown hostnames get no response.
install -m 644 "$OPS/nginx/10-ultralove-http.conf" /etc/nginx/conf.d/10-ultralove-http.conf
install -m 644 "$OPS/nginx/99-ultralove-rate-limit-zones.conf" /etc/nginx/conf.d/99-ultralove-rate-limit-zones.conf
{
  echo "# Cloudflare's ranges (https://www.cloudflare.com/ips-v4, ips-v6), written by bootstrap-app-server.sh."
  for ip in $(curl -fsS https://www.cloudflare.com/ips-v4) $(curl -fsS https://www.cloudflare.com/ips-v6); do
    echo "set_real_ip_from $ip;"
  done
  echo "real_ip_header CF-Connecting-IP;"
} >/etc/nginx/conf.d/20-cloudflare-real-ip.conf
install -m 644 "$OPS/nginx/ultralove.ca.conf" /etc/nginx/sites-available/ultralove.ca.conf
install -m 644 "$OPS/nginx/localhost-admin.conf" /etc/nginx/sites-available/localhost-admin.conf
cat >/etc/nginx/sites-available/default <<'EOF'
server {
	listen 80 default_server;
	listen [::]:80 default_server;
	server_name _;
	return 444;
}
EOF
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/ultralove.ca.conf /etc/nginx/sites-enabled/ultralove.ca.conf
ln -sf /etc/nginx/sites-available/localhost-admin.conf /etc/nginx/sites-enabled/localhost-admin.conf
nginx -t

# --- Firewall: SSH from anywhere; HTTP/HTTPS only from Cloudflare, as on the old droplet.
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment SSH
for ip in $(curl -fsS https://www.cloudflare.com/ips-v4); do
  ufw allow proto tcp from "$ip" to any port 80,443 comment Cloudflare
done
ufw --force enable

# --- Off-site backups to Spaces (credentials: /root/.spaces_env, copied by hand): monthly config archive at 02:40 UTC
# on the 1st, media library sync nightly at 03:30 UTC. The root crontab is replaced with exactly these two lines.
install -m 700 "$OPS/prod/monthly_system_backup.sh" /root/monthly_system_backup.sh
install -m 700 "$OPS/prod/uploads_spaces_sync.sh" /root/uploads_spaces_sync.sh
crontab - <<'EOF'
40 2 1 * * /root/monthly_system_backup.sh >> /var/log/monthly_system_backup.log 2>&1
30 3 * * * /root/uploads_spaces_sync.sh >> /var/log/uploads_spaces_sync.log 2>&1
EOF
[[ -s /root/.spaces_env ]] || echo "Note: /root/.spaces_env is missing - the backups will skip their uploads until it's copied."

# --- WordPress cron from the system cron (wp-config.php sets DISABLE_WP_CRON), as on the old droplet.
cat >/etc/cron.d/ultralove-wp-cron <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

*/5 * * * * www-data cd /var/www/html && /usr/local/bin/wp --path=/var/www/html cron event run --due-now >> /var/log/wp-cron-runner.log 2>&1
EOF
touch /var/log/wp-cron-runner.log
chown www-data:www-data /var/log/wp-cron-runner.log

mkdir -p "$WP_ROOT"
chown -R www-data:www-data "$WP_ROOT"

systemctl enable nginx "php$PHP_VERSION-fpm" redis-server fail2ban
systemctl restart "php$PHP_VERSION-fpm" redis-server fail2ban
systemctl reload nginx

echo "Production app baseline complete (PHP $PHP_VERSION, $(nproc) vCPU, $(free -g | awk '/Mem:/ {print $2}') GB)."
echo "Next: copy the WordPress files (sync-site-files.sh) and wp-config.php, then the backup scripts (README.md)."
