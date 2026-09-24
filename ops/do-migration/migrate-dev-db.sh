#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
DUMP_FILE="${SCRIPT_DIR}/tmp-prod-seed.sql"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing ${ENV_FILE}. Copy .env.example to .env and fill values." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

require_var() {
  local key="$1"
  if [[ -z "${!key:-}" ]]; then
    echo "Missing required variable: ${key}" >&2
    exit 1
  fi
}

for key in SRC_DB_HOST SRC_DB_NAME SRC_DB_USER SRC_DB_PASS DEV_DB_NAME_WORDPRESS DEV_DB_USER DEV_DB_PASS SANITIZE_EMAIL_DOMAIN; do
  require_var "$key"
done

DEV_DB_HOST="${DEV_DB_HOST:-127.0.0.1}"
DEV_DB_PORT="${DEV_DB_PORT:-3306}"

echo "Exporting production data from ${SRC_DB_HOST}/${SRC_DB_NAME}..."
MYSQLDUMP_ARGS=(
  -h "$SRC_DB_HOST"
  -u "$SRC_DB_USER"
  -p"$SRC_DB_PASS"
  --single-transaction
  --quick
)

# Avoid PROCESS privilege requirement on managed MySQL by skipping tablespace metadata.
if mysqldump --help 2>/dev/null | grep -q -- '--no-tablespaces'; then
  MYSQLDUMP_ARGS+=(--no-tablespaces)
fi

# MariaDB's mysqldump does not support --set-gtid-purged.
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  MYSQLDUMP_ARGS+=(--set-gtid-purged=OFF)
fi

mysqldump "${MYSQLDUMP_ARGS[@]}" "$SRC_DB_NAME" > "$DUMP_FILE"

# MySQL 8 dumps may include collations unsupported by MariaDB targets.
if grep -q 'utf8mb4_0900_ai_ci' "$DUMP_FILE"; then
  echo "Normalizing unsupported MySQL 8 collations for target import..."
  sed -i \
    -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
    -e 's/utf8mb4_0900_bin/utf8mb4_bin/g' \
    "$DUMP_FILE"
fi

echo "Importing into dev DB ${DEV_DB_NAME_WORDPRESS} on ${DEV_DB_HOST}:${DEV_DB_PORT}..."
mysql -h "$DEV_DB_HOST" -P "$DEV_DB_PORT" -u "$DEV_DB_USER" -p"$DEV_DB_PASS" "$DEV_DB_NAME_WORDPRESS" < "$DUMP_FILE"

# Baseline sanitization to avoid accidental real email sends from dev.
echo "Sanitizing user emails..."
mysql -h "$DEV_DB_HOST" -P "$DEV_DB_PORT" -u "$DEV_DB_USER" -p"$DEV_DB_PASS" "$DEV_DB_NAME_WORDPRESS" <<SQL
UPDATE wp_users
SET user_email = CONCAT('user+', ID, '@${SANITIZE_EMAIL_DOMAIN}');
SQL

echo "Removing dump file..."
rm -f "$DUMP_FILE"

echo "Dev DB migration and sanitization completed."
