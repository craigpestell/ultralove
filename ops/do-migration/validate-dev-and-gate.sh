#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
GATE_FILE="${SCRIPT_DIR}/.dev-validation-passed"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing ${ENV_FILE}. Copy .env.example to .env and fill values." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

if [[ -z "${DEV_DOMAIN:-}" || -z "${DEV_NEW_APP_IP:-}" ]]; then
  echo "DEV_DOMAIN and DEV_NEW_APP_IP are required in .env" >&2
  exit 1
fi

"${SCRIPT_DIR}/cutover-verify.sh" "$DEV_DOMAIN" "$DEV_NEW_APP_IP"

date -u +"%Y-%m-%dT%H:%M:%SZ" > "$GATE_FILE"

echo "Development validation passed at $(cat "$GATE_FILE")"
echo "Gate file created: $GATE_FILE"
