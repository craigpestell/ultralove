#!/usr/bin/env bash
# Let prod push code/DB straight to dev (fast, same datacenter) without giving
# dev any access to prod:
#   - prod gets a dedicated key ($PUSH_KEY)
#   - dev accepts it only from prod's public IP, with no forwarding/pty (restrict)
#   - prod pins dev's host key, taken over our own trusted SSH connection
# Re-run after every dev rebuild (new droplet = new host key/IP).
#
# Usage: ops/dev/setup-prod-push.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

DEV_IP="$(ssh -G "$DEV_HOST_ALIAS" | awk '/^hostname /{print $2}')"
log "Ensuring push key on $PROD_HOST"
PUB="$(ssh "$PROD_HOST" "test -f '$PUSH_KEY' || ssh-keygen -q -t ed25519 -N '' -C 'ultralove prod->dev push' -f '$PUSH_KEY'; cat '$PUSH_KEY.pub'")"

log "Authorizing it on dev ($DEV_IP) from $PROD_PUBLIC_IP only"
ssh "$DEV_HOST_ALIAS" "grep -qF '$PUB' /root/.ssh/authorized_keys || echo 'from=\"$PROD_PUBLIC_IP\",restrict $PUB' >> /root/.ssh/authorized_keys"

log "Pinning dev's host key on prod"
HOSTKEY="$(ssh "$DEV_HOST_ALIAS" 'cat /etc/ssh/ssh_host_ed25519_key.pub' | awk '{print $1" "$2}')"
ssh "$PROD_HOST" "touch '$PUSH_KNOWN_HOSTS' && chmod 600 '$PUSH_KNOWN_HOSTS' && ssh-keygen -q -R '$DEV_IP' -f '$PUSH_KNOWN_HOSTS' 2>/dev/null; echo '$DEV_IP $HOSTKEY' >> '$PUSH_KNOWN_HOSTS'; rm -f '$PUSH_KNOWN_HOSTS.old'"

ssh "$PROD_HOST" "ssh -i '$PUSH_KEY' -o UserKnownHostsFile='$PUSH_KNOWN_HOSTS' -o StrictHostKeyChecking=yes -o BatchMode=yes root@$DEV_IP 'echo prod-to-dev push OK: \$(hostname)'"
