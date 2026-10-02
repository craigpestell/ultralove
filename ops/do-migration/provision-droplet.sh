#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"

usage() {
  echo "Usage: $0 ROLE"
  echo "  ROLE: prod-app | dev-app | dev-db"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1" >&2
    exit 1
  }
}

trim_value() {
  local raw="$1"
  # Trim leading/trailing whitespace from .env values.
  sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' <<<"$raw"
}

if [[ $# -ne 1 ]]; then
  usage
  exit 1
fi

ROLE="$1"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE. Copy .env.example to .env and fill values." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

DO_REGION="$(trim_value "${DO_REGION:-}")"
DO_SSH_KEY_ID="$(trim_value "${DO_SSH_KEY_ID:-}")"
DO_VPC_UUID="$(trim_value "${DO_VPC_UUID:-}")"

require_cmd doctl

if ! doctl auth list >/dev/null 2>&1; then
  echo "doctl is not authenticated. Run: doctl auth init" >&2
  exit 1
fi

case "$ROLE" in
  prod-app)
    NAME="$PROD_APP_NAME"
    SIZE="$APP_DROPLET_SIZE"
    IMAGE="$APP_IMAGE"
    TAGS="Production,nginx,php,php-fpm,wordpress"  # as on today's production droplet
    ;;
  dev-app)
    NAME="$DEV_APP_NAME"
    SIZE="$APP_DROPLET_SIZE"
    IMAGE="$APP_IMAGE"
    TAGS="ultralove,wordpress,development,app"
    ;;
  dev-db)
    NAME="$DEV_DB_NAME"
    SIZE="$DEV_DB_DROPLET_SIZE"
    IMAGE="$DB_IMAGE"
    TAGS="ultralove,wordpress,development,db"
    ;;
  *)
    usage
    exit 1
    ;;
esac

if [[ -z "${DO_SSH_KEY_ID:-}" || -z "${DO_REGION:-}" ]]; then
  echo "DO_SSH_KEY_ID and DO_REGION are required in .env" >&2
  exit 1
fi

if [[ -n "${DO_VPC_UUID:-}" ]]; then
  if ! VPC_REGION="$(doctl vpcs get "$DO_VPC_UUID" --format Region --no-header 2>/dev/null)"; then
    echo "Invalid DO_VPC_UUID or inaccessible VPC: '$DO_VPC_UUID'" >&2
    echo "Check with: doctl vpcs list --format ID,Name,Region,Default" >&2
    exit 1
  fi

  VPC_REGION="$(trim_value "$VPC_REGION")"
  if [[ "$VPC_REGION" != "$DO_REGION" ]]; then
    echo "VPC region mismatch: DO_VPC_UUID is in '$VPC_REGION' but DO_REGION is '$DO_REGION'" >&2
    echo "Use a VPC in the same region or update DO_REGION." >&2
    exit 1
  fi
fi

CMD=(
  doctl compute droplet create "$NAME"
  --size "$SIZE"
  --image "$IMAGE"
  --region "$DO_REGION"
  --ssh-keys "$DO_SSH_KEY_ID"
  --enable-monitoring
  --droplet-agent=true
  --tag-names "$TAGS"
  --format ID,Name,PublicIPv4,Memory,VCPUs,Disk
  --no-header
)

if [[ -n "${DO_VPC_UUID:-}" ]]; then
  CMD+=(--vpc-uuid "$DO_VPC_UUID")
fi

echo "Creating droplet: $NAME ($SIZE, $IMAGE, $DO_REGION)"
"${CMD[@]}"

echo "Droplet creation requested. Check status with:"
echo "  doctl compute droplet list --format ID,Name,PublicIPv4,PrivateIPv4,Status"
