#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

DB_NAME="${1:-wordpress_dev}"
DB_USER="${2:-ultra_dev}"
DB_PASS="${3:-}"
APP_PRIVATE_CIDR="${4:-10.0.0.0/8}"

if [[ -z "$DB_PASS" ]]; then
  echo "Usage: sudo $0 [DB_NAME] [DB_USER] DB_PASS [APP_PRIVATE_CIDR]" >&2
  exit 1
fi

apt-get update -y
apt-get install -y mariadb-server ufw

MYSQL_CNF=/etc/mysql/mariadb.conf.d/50-server.cnf
sed -i 's/^bind-address\s*=.*/bind-address = 0.0.0.0/' "$MYSQL_CNF"

systemctl enable mariadb
systemctl restart mariadb

mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'10.%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'10.%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'10.%';
FLUSH PRIVILEGES;
SQL

ufw allow OpenSSH
ufw allow from "${APP_PRIVATE_CIDR}" to any port 3306 proto tcp
ufw --force enable

echo "Dev DB baseline completed."
echo "DB: ${DB_NAME}, User: ${DB_USER}, Allowed CIDR: ${APP_PRIVATE_CIDR}"
