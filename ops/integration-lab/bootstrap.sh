#!/usr/bin/env bash
# Run as root on the dedicated lab only: bash bootstrap.sh <hostname> <admin-ip>
set -euo pipefail
umask 077

HOST="${1:?Supply the lab HTTPS hostname}"
ADMIN_IP="${2:?Supply the administrator public IPv4 address}"
[[ "$HOST" =~ ^ultralove-lab\.[0-9]+-[0-9]+-[0-9]+-[0-9]+\.sslip\.io$ ]] ||
    { echo "Unexpected lab hostname" >&2; exit 1; }
[[ "$ADMIN_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
    { echo "Invalid administrator IPv4 address" >&2; exit 1; }
[[ "$(cat /etc/ultralove-integration-lab)" == "ultralove-integration-lab" ]] ||
    { echo "This is not the dedicated integration lab" >&2; exit 1; }
[[ ! -e /var/www/integration-lab/wp-config.php ]] ||
    { echo "Lab already installed; refusing to overwrite its database/configuration" >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common
add-apt-repository --yes ppa:ondrej/php
apt-get update
apt-get install -y nginx mariadb-server php8.5-fpm php8.5-cli php8.5-mysql \
    php8.5-curl php8.5-gd php8.5-intl php8.5-mbstring php8.5-xml php8.5-zip \
    curl unzip openssl ufw fail2ban certbot python3-certbot-nginx tar
update-alternatives --set php /usr/bin/php8.5

install -d -m 0700 /root/.ultralove-integration-lab
openssl rand -hex 32 > /root/.ultralove-integration-lab/db-password
openssl rand -hex 24 > /root/.ultralove-integration-lab/admin-password
DB_PASSWORD="$(cat /root/.ultralove-integration-lab/db-password)"
mysql <<SQL
CREATE DATABASE integration_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'integration_lab'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON integration_lab.* TO 'integration_lab'@'localhost';
SQL

curl --fail --silent --show-error --location \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    -o /usr/local/bin/wp
chmod 0755 /usr/local/bin/wp
install -d -m 0755 -o www-data -g www-data /var/www/integration-lab
cd /var/www/integration-lab
# WP-CLI's archive extractor truncates long paths in this WordPress release.
curl --fail --silent --show-error --location https://wordpress.org/latest.tar.gz \
    -o /root/.ultralove-integration-lab/wordpress.tar.gz
tar -xzf /root/.ultralove-integration-lab/wordpress.tar.gz --strip-components=1 --no-same-owner
wp --allow-root core verify-checksums
rm /root/.ultralove-integration-lab/wordpress.tar.gz
wp --allow-root config create --dbname=integration_lab --dbuser=integration_lab \
    --dbhost=localhost --prompt=dbpass < /root/.ultralove-integration-lab/db-password > /dev/null
unset DB_PASSWORD
wp --allow-root config set WP_ENVIRONMENT_TYPE development
wp --allow-root config set DISABLE_WP_CRON true --raw
wp --allow-root config set DISALLOW_FILE_EDIT true --raw

install -d -m 0755 wp-content/mu-plugins
cat > wp-content/mu-plugins/integration-lab-safety.php <<'PHP'
<?php
/**
 * Plugin Name: Integration Lab Safety
 * Description: Suppress all email and identify the synthetic-data lab.
 */
defined( 'ABSPATH' ) || exit;
add_filter( 'pre_wp_mail', function () {
    error_log( 'Integration lab: outbound email suppressed.' );
    return true;
} );
add_action( 'admin_notices', function () {
    echo '<div class="notice notice-warning"><p>INTEGRATION LAB: synthetic data only. Email is suppressed. Do not configure live payments or fulfillment.</p></div>';
} );
PHP
wp --allow-root core install --url="https://$HOST" --title='UltraLove Supplier Integration Lab' \
    --admin_user=lab-admin --admin_email=lab-admin@example.invalid --skip-email \
    --prompt=admin_password < /root/.ultralove-integration-lab/admin-password > /dev/null
wp --allow-root option update blog_public 0
wp --allow-root option update users_can_register 0
wp --allow-root rewrite structure '/%postname%/'
wp --allow-root plugin install woocommerce --activate
wp --allow-root option update woocommerce_store_address '100 Synthetic Test Street'
wp --allow-root option update woocommerce_store_city 'Vancouver'
wp --allow-root option update woocommerce_store_postcode 'V6B 1A1'
wp --allow-root option update woocommerce_default_country 'CA:BC'
wp --allow-root option update woocommerce_currency CAD
wp --allow-root option update woocommerce_enable_guest_checkout no
wp --allow-root option update woocommerce_enable_signup_and_login_from_checkout no
wp --allow-root option update woocommerce_enable_myaccount_registration no
wp --allow-root option update woocommerce_allow_tracking no
# shellcheck disable=SC2016
wp --allow-root eval '
WC_Install::create_tables();
WC_Install::create_pages();
foreach ( array( array( "LAB-SIMPLE-001", "Synthetic Lab Product A", "20.00", 10 ),
                array( "LAB-SIMPLE-002", "Synthetic Lab Product B", "35.00", 5 ) ) as $row ) {
    $product = new WC_Product_Simple();
    $product->set_name( $row[1] );
    $product->set_sku( $row[0] );
    $product->set_description( "Synthetic integration test product. Not for real sale or fulfillment." );
    $product->set_regular_price( $row[2] );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( $row[3] );
    $product->set_status( "publish" );
    $product->save();
}
'
chown -R www-data:www-data /var/www/integration-lab
chmod 0640 wp-config.php

cat > /etc/php/8.5/fpm/conf.d/99-integration-lab.ini <<'INI'
memory_limit=256M
upload_max_filesize=32M
post_max_size=32M
display_errors=Off
log_errors=On
expose_php=Off
INI
install -m 0644 /etc/php/8.5/fpm/conf.d/99-integration-lab.ini \
    /etc/php/8.5/cli/conf.d/99-integration-lab.ini
cat > /etc/nginx/sites-available/integration-lab <<NGINX
server {
    listen 80;
    server_name $HOST;
    root /var/www/integration-lab;
    index index.php;
    client_max_body_size 32m;
    add_header X-Robots-Tag "noindex, nofollow" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }
    location ~ /\.(?!well-known) {
        deny all;
    }
    location = /xmlrpc.php {
        deny all;
    }
    location = /wp-config.php {
        deny all;
    }
    location ~* ^/wp-content/uploads/.*\.php$ {
        deny all;
    }
    location ~ ^/(wp-admin/|wp-login\.php) {
        allow $ADMIN_IP;
        allow 127.0.0.1;
        deny all;
        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        }
        try_files \$uri \$uri/ /index.php?\$args;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
}
NGINX
chmod 0644 /etc/nginx/sites-available/integration-lab
ln -s /etc/nginx/sites-available/integration-lab /etc/nginx/sites-enabled/integration-lab
if [[ -L /etc/nginx/sites-enabled/default ]]; then
    unlink /etc/nginx/sites-enabled/default
fi
nginx -t
php-fpm8.5 -t
systemctl restart php8.5-fpm
systemctl reload nginx

ufw default deny incoming
ufw default allow outgoing
ufw allow from "$ADMIN_IP" to any port 22 proto tcp
ufw allow 80/tcp
ufw allow 443/tcp
# Use public DNS rather than a VPC resolver blocked by the isolation rules.
install -d -m 0755 /etc/systemd/resolved.conf.d
cat > /etc/systemd/resolved.conf.d/integration-lab.conf <<'DNS'
[Resolve]
DNS=
DNS=1.1.1.1 1.0.0.1
Domains=~.
DNS
chmod 0644 /etc/systemd/resolved.conf.d/integration-lab.conf
systemctl restart systemd-resolved
for network in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16; do
    ufw deny out to "$network"
done
ufw --force enable
systemctl enable --now fail2ban
certbot --nginx --non-interactive --agree-tos --register-unsafely-without-email \
    --redirect --domain "$HOST"
nginx -t
cat > /etc/cron.d/integration-lab-wordpress <<'CRON'
*/5 * * * * www-data cd /var/www/integration-lab && /usr/bin/php8.5 /usr/local/bin/wp cron event run --due-now >> /var/log/integration-lab-cron.log 2>&1
CRON
chmod 0644 /etc/cron.d/integration-lab-wordpress
touch /var/log/integration-lab-cron.log
chown www-data:www-data /var/log/integration-lab-cron.log
systemctl is-active nginx php8.5-fpm mariadb fail2ban
printf 'Lab installed: https://%s\nAdmin user: lab-admin\nPassword stored in /root/.ultralove-integration-lab/admin-password (root only).\n' "$HOST"
