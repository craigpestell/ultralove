#!/usr/bin/env bash
# Create (or reuse) the dev VPC, firewall and droplet on DigitalOcean, then point
# the ~/.ssh/config alias at it. Idempotent: safe to re-run.
#
# Usage: ops/dev/provision.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
command -v doctl >/dev/null || { echo "doctl required" >&2; exit 1; }

# --- VPC ----------------------------------------------------------------------
VPC_ID="$(doctl vpcs list --format ID,Name --no-header | awk -v n="$VPC_NAME" '$2==n{print $1}')"
if [[ -z "$VPC_ID" ]]; then
  log "Creating VPC $VPC_NAME ($VPC_RANGE)"
  VPC_ID="$(doctl vpcs create --name "$VPC_NAME" --region "$DO_REGION" --ip-range "$VPC_RANGE" \
    --description "Ultralove dev only; isolated from production" --format ID --no-header)"
fi
log "VPC: $VPC_ID"

# --- Droplet ------------------------------------------------------------------
DROPLET_ID="$(doctl compute droplet list --format ID,Name --no-header | awk -v n="$DROPLET_NAME" '$2==n{print $1}')"
if [[ -z "$DROPLET_ID" ]]; then
  log "Creating droplet $DROPLET_NAME ($DROPLET_SIZE, $DO_REGION)"
  DROPLET_ID="$(doctl compute droplet create "$DROPLET_NAME" --size "$DROPLET_SIZE" --image "$DROPLET_IMAGE" \
    --region "$DO_REGION" --vpc-uuid "$VPC_ID" --ssh-keys "$DO_SSH_KEY_IDS" --tag-names "$TAGS" \
    --enable-monitoring --wait --format ID --no-header)"
fi
IP="$(doctl compute droplet get "$DROPLET_ID" --format PublicIPv4 --no-header)"
log "Droplet: $DROPLET_ID  $IP"

# --- Firewall: SSH only. Web traffic arrives through the Cloudflare Tunnel. ---
FW_ID="$(doctl compute firewall list --format ID,Name --no-header | awk -v n="$FIREWALL_NAME" '$2==n{print $1}')"
OUT="protocol:tcp,ports:all,address:0.0.0.0/0,address:::/0 protocol:udp,ports:all,address:0.0.0.0/0,address:::/0 protocol:icmp,address:0.0.0.0/0,address:::/0"
if [[ -z "$FW_ID" ]]; then
  log "Creating firewall $FIREWALL_NAME (inbound: SSH only)"
  FW_ID="$(doctl compute firewall create --name "$FIREWALL_NAME" \
    --inbound-rules "protocol:tcp,ports:22,address:0.0.0.0/0,address:::/0" \
    --outbound-rules "$OUT" --droplet-ids "$DROPLET_ID" --format ID --no-header)"
else
  doctl compute firewall add-droplets "$FW_ID" --droplet-ids "$DROPLET_ID"
fi
log "Firewall: $FW_ID"

# --- SSH alias ------------------------------------------------------------------
CFG="$HOME/.ssh/config"
touch "$CFG"
python3 - "$CFG" "$DEV_HOST_ALIAS" "$IP" <<'PY'
import re, sys
path, alias, ip = sys.argv[1:]
s = open(path).read()
block = f"Host {alias}\n  HostName {ip}\n  User root\n"
pat = re.compile(rf"^Host {re.escape(alias)}\n(?:[ \t]+.*\n?)*", re.M)
s = pat.sub(block, s) if pat.search(s) else s.rstrip("\n") + "\n\n" + block
open(path, "w").write(s)
PY
ssh-keygen -R "$DEV_HOST_ALIAS" >/dev/null 2>&1 || true
ssh-keygen -R "$IP" >/dev/null 2>&1 || true
log "Waiting for SSH on $IP"
for _ in $(seq 1 30); do
  ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=5 -o BatchMode=yes "$DEV_HOST_ALIAS" true 2>/dev/null && break
  sleep 5
done
ssh -o BatchMode=yes "$DEV_HOST_ALIAS" 'hostname && lsb_release -ds'
log "Provisioned. Next: ops/dev/rebuild.sh (or bootstrap.sh / sync-code.sh / refresh-db.sh individually)"
