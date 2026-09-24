#!/usr/bin/env bash
# Give the "Deploy to dev" GitHub Action access to the dev droplet.
#   - Dedicated key: private half lives only in the GitHub "dev" environment secret
#     DEV_SSH_KEY; public half is committed as ops/dev/ci-deploy-key.pub.
#   - Authorized on dev with `restrict` (no forwarding/pty). Dev only, never prod.
#   - DEV_HOST / DEV_SSH_KNOWN_HOSTS secrets updated with dev's IP and pinned host key.
# Re-run after every dev rebuild. --rotate issues a new key.
#
# Usage: ops/dev/setup-ci-deploy.sh [--rotate]
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
REPO_SLUG="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
PUB_FILE="$DIR/ci-deploy-key.pub"

gh api -X PUT "repos/$REPO_SLUG/environments/dev" --silent
if [[ ! -f "$PUB_FILE" || "${1:-}" == "--rotate" ]]; then
  log "Generating new CI deploy key"
  T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
  ssh-keygen -q -t ed25519 -N '' -C 'ultralove-ci-deploy (GitHub Actions -> dev)' -f "$T/key"
  gh secret set DEV_SSH_KEY --env dev --repo "$REPO_SLUG" < "$T/key"
  cp "$T/key.pub" "$PUB_FILE"
  log "Wrote $PUB_FILE (commit it)"
fi
PUB="$(cat "$PUB_FILE")"
DEV_IP="$(ssh -G "$DEV_HOST_ALIAS" | awk '/^hostname /{print $2}')"

log "Authorizing CI key on dev ($DEV_IP)"
ssh "$DEV_HOST_ALIAS" "sed -i '/ultralove-ci-deploy/d' /root/.ssh/authorized_keys; echo 'restrict $PUB' >> /root/.ssh/authorized_keys"

log "Updating DEV_HOST and DEV_SSH_KNOWN_HOSTS secrets"
HOSTKEY="$(ssh "$DEV_HOST_ALIAS" 'cat /etc/ssh/ssh_host_ed25519_key.pub' | awk '{print $1" "$2}')"
gh secret set DEV_HOST --env dev --repo "$REPO_SLUG" --body "$DEV_IP"
gh secret set DEV_SSH_KNOWN_HOSTS --env dev --repo "$REPO_SLUG" --body "$DEV_IP $HOSTKEY"
gh secret list --env dev --repo "$REPO_SLUG"
