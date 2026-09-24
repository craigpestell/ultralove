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

if [[ ! -f "$GATE_FILE" ]]; then
  echo "Production promotion blocked: dev validation gate missing." >&2
  echo "Run ./validate-dev-and-gate.sh first." >&2
  exit 1
fi

if [[ -z "${PROD_DOMAIN:-}" || -z "${PROD_NEW_APP_IP:-}" ]]; then
  echo "PROD_DOMAIN and PROD_NEW_APP_IP are required in .env" >&2
  exit 1
fi

echo "Dev gate found ($(cat "$GATE_FILE")). Running production pre-cutover checks..."
"${SCRIPT_DIR}/cutover-verify.sh" "$PROD_DOMAIN" "$PROD_NEW_APP_IP"

echo "Production pre-cutover checks passed. You can proceed with production DNS cutover."
