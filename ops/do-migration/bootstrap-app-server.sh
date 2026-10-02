#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

DOMAIN="${1:-}"
WP_ROOT="${2:-/var/www/html}"

if [[ -z "$DOMAIN" ]]; then
  echo "Usage: sudo $0 DOMAIN [WP_ROOT]" >&2
  exit 1
fi

apt-get update -y

package_available() {
  local pkg="$1"
  local candidate
  candidate="$(apt-cache policy "$pkg" 2>/dev/null | awk '/Candidate:/ {print $2}')"
  [[ -n "$candidate" && "$candidate" != "(none)" ]]
}

# PHP 8.5 (Ubuntu 26.04's own) with the extensions production uses. No opcache package: OPcache is part of
# PHP itself since 8.5.
PHP_VERSION="8.5"
PHP_PACKAGES=()
for ext in fpm cli mysql curl xml mbstring zip gd intl imagick bcmath soap redis igbinary readline; do
  PHP_PACKAGES+=("php${PHP_VERSION}-${ext}")
done
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

apt-get install -y nginx "${PHP_PACKAGES[@]}" unzip curl fail2ban certbot python3-certbot-nginx mariadb-client

if command -v aws >/dev/null 2>&1; then
  echo "aws CLI already installed: $(aws --version 2>&1 | head -n 1)"
elif package_available awscli; then
  apt-get install -y awscli
else
  echo "awscli package not available in this repository; skipping awscli installation"
fi

systemctl enable nginx "$PHP_FPM_SERVICE" fail2ban
systemctl start nginx "$PHP_FPM_SERVICE" fail2ban

if [[ -z "$PHP_FPM_SOCKET" || ! -S "$PHP_FPM_SOCKET" ]]; then
  PHP_FPM_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | head -n 1 || true)"
fi

if [[ -z "$PHP_FPM_SOCKET" ]]; then
  echo "Could not determine PHP-FPM socket path under /run/php" >&2
  exit 1
fi

mkdir -p "$WP_ROOT"
chown -R www-data:www-data "$WP_ROOT"
chmod -R 755 "$WP_ROOT"

cat >/etc/nginx/sites-available/${DOMAIN}.conf <<EOF
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};
    root ${WP_ROOT};
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_read_timeout 300;
    }

    location ~ /\\. {
        deny all;
    }
}
EOF

ln -sf /etc/nginx/sites-available/${DOMAIN}.conf /etc/nginx/sites-enabled/${DOMAIN}.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "App server baseline completed for ${DOMAIN}."
echo "Using PHP-FPM socket: ${PHP_FPM_SOCKET}"
echo "Next: rsync WordPress files and run certbot --nginx -d ${DOMAIN} -d www.${DOMAIN}"
