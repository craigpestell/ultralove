#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

DOMAIN="${1:-}"
WP_ROOT="${2:-/var/www/html}"
PHP_VERSION_OVERRIDE="${3:-}"

if [[ -z "$DOMAIN" ]]; then
  echo "Usage: sudo $0 DOMAIN [WP_ROOT] [PHP_VERSION]" >&2
  exit 1
fi

apt-get update -y

package_available() {
  local pkg="$1"
  local candidate
  candidate="$(apt-cache policy "$pkg" 2>/dev/null | awk '/Candidate:/ {print $2}')"
  [[ -n "$candidate" && "$candidate" != "(none)" ]]
}

PHP_VERSION=""
if [[ -n "$PHP_VERSION_OVERRIDE" ]]; then
  if package_available "php${PHP_VERSION_OVERRIDE}-fpm"; then
    PHP_VERSION="$PHP_VERSION_OVERRIDE"
  else
    echo "Requested PHP_VERSION '${PHP_VERSION_OVERRIDE}' is not available in apt repositories" >&2
    exit 1
  fi
else
  for v in 8.4 8.3 8.2 8.1; do
    if package_available "php${v}-fpm"; then
      PHP_VERSION="$v"
      break
    fi
  done
fi

if [[ -n "$PHP_VERSION" ]]; then
  PHP_PACKAGES=("php${PHP_VERSION}-fpm")
  for ext in mysql curl xml mbstring zip gd intl; do
    pkg="php${PHP_VERSION}-${ext}"
    if package_available "$pkg"; then
      PHP_PACKAGES+=("$pkg")
    else
      echo "Package not available, skipping: $pkg"
    fi
  done
  PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
  PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
else
  PHP_PACKAGES=(php-fpm php-mysql php-curl php-xml php-mbstring php-zip php-gd php-intl)
  PHP_FPM_SERVICE="php-fpm"
  PHP_FPM_SOCKET=""
fi

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
