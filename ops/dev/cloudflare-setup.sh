#!/usr/bin/env bash
# Put dev behind Cloudflare: Tunnel (no inbound web ports on the droplet) + Access
# (only DEV_ACCESS_EMAILS can reach dev.ultralove.ca). Idempotent; re-run after a
# rebuild to reinstall the tunnel connector on the new droplet.
#
# Needs CLOUDFLARE_API_TOKEN with: Account > Cloudflare Tunnel: Edit,
# Account > Access: Apps and Policies: Edit, Zone (ultralove.ca) > DNS: Edit.
# Zero Trust must be enabled on the account (free plan is fine).
#
# Usage: CLOUDFLARE_API_TOKEN=... ops/dev/cloudflare-setup.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=ops/dev/config.env
source "$DIR/config.env"
: "${CLOUDFLARE_API_TOKEN:?Set CLOUDFLARE_API_TOKEN}"
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mxx\033[0m %s\n' "$*" >&2; exit 1; }

cf() { # cf METHOD PATH [JSON]
  local out
  out="$(curl -sS -X "$1" "https://api.cloudflare.com/client/v4$2" -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
    -H 'Content-Type: application/json' ${3:+--data "$3"})"
  if [[ "$(jq -r '.success' <<<"$out")" != true ]]; then
    die "Cloudflare API $1 $2 failed: $(jq -c '.errors' <<<"$out")"
  fi
  printf '%s' "$out"
}

ZONE="$(cf GET "/zones?name=$PROD_DOMAIN")"
ZONE_ID="$(jq -r '.result[0].id' <<<"$ZONE")"; ACC="$(jq -r '.result[0].account.id' <<<"$ZONE")"
[[ "$ZONE_ID" != null ]] || die "Zone $PROD_DOMAIN not visible to this token"
log "Zone $PROD_DOMAIN ($ZONE_ID), account $ACC"

# --- Access -------------------------------------------------------------------
# Access goes first so that a failure here never leaves dev routed without a login.
curl -sS "https://api.cloudflare.com/client/v4/accounts/$ACC/access/apps" -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  | jq -e '.success' >/dev/null || die "Cloudflare Access isn't usable: enable Zero Trust (dash > Zero Trust, Free plan) and give the token Access: Apps and Policies: Edit"
APP_ID="$(cf GET "/accounts/$ACC/access/apps" | jq -r --arg d "$DEV_DOMAIN" '.result[] | select(.domain==$d) | .id' | head -1)"
if [[ -z "$APP_ID" ]]; then
  log "Creating Access application for $DEV_DOMAIN"
  APP_ID="$(cf POST "/accounts/$ACC/access/apps" "$(jq -nc --arg d "$DEV_DOMAIN" \
    '{name:"Ultralove dev", domain:$d, type:"self_hosted", session_duration:"24h", app_launcher_visible:true}')" | jq -r '.result.id')"
fi
INCLUDE="$(for e in $DEV_ACCESS_EMAILS; do jq -nc --arg e "$e" '{email:{email:$e}}'; done | jq -sc .)"
POLICY="$(jq -nc --argjson inc "$INCLUDE" '{name:"Allowed people", decision:"allow", include:$inc, precedence:1}')"
POL_ID="$(cf GET "/accounts/$ACC/access/apps/$APP_ID/policies" | jq -r '.result[] | select(.name=="Allowed people") | .id' | head -1)"
if [[ -n "$POL_ID" ]]; then cf PUT "/accounts/$ACC/access/apps/$APP_ID/policies/$POL_ID" "$POLICY" >/dev/null
else cf POST "/accounts/$ACC/access/apps/$APP_ID/policies" "$POLICY" >/dev/null; fi
log "Access allows: $DEV_ACCESS_EMAILS"

# Email one-time PIN login, so allowed people don't need a Cloudflare account.
IDPS="$(cf GET "/accounts/$ACC/access/identity_providers" | jq -r '.result[].type')"
if ! grep -qx onetimepin <<<"$IDPS"; then
  curl -sS -X POST "https://api.cloudflare.com/client/v4/accounts/$ACC/access/identity_providers" \
    -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H 'Content-Type: application/json' \
    --data '{"name":"One-time PIN","type":"onetimepin","config":{}}' | jq -e '.success' >/dev/null \
    && log "Added One-time PIN login method" \
    || printf '\033[1;33m!!\033[0m %s\n' "Add the One-time PIN login method by hand: Zero Trust > Integrations > Identity providers > Add > One-time PIN (token lacks 'Access: Organizations, Identity Providers, and Groups: Edit')" >&2
fi

# --- Tunnel -------------------------------------------------------------------
TUN_ID="$(cf GET "/accounts/$ACC/cfd_tunnel?name=$CF_TUNNEL_NAME&is_deleted=false" | jq -r '.result[0].id // empty')"
if [[ -z "$TUN_ID" ]]; then
  log "Creating tunnel $CF_TUNNEL_NAME"
  TUN_ID="$(cf POST "/accounts/$ACC/cfd_tunnel" "$(jq -nc --arg n "$CF_TUNNEL_NAME" '{name:$n, config_src:"cloudflare"}')" | jq -r '.result.id')"
fi
log "Tunnel $TUN_ID: routing $DEV_DOMAIN -> http://127.0.0.1:80"
cf PUT "/accounts/$ACC/cfd_tunnel/$TUN_ID/configurations" "$(jq -nc --arg h "$DEV_DOMAIN" \
  '{config:{ingress:[{hostname:$h, service:"http://127.0.0.1:80", originRequest:{httpHostHeader:$h}}, {service:"http_status:404"}]}}')" >/dev/null

log "Installing connector on $DEV_HOST_ALIAS"
cf GET "/accounts/$ACC/cfd_tunnel/$TUN_ID/token" | jq -r '.result' \
  | ssh "$DEV_HOST_ALIAS" 'read -r T; cloudflared service uninstall >/dev/null 2>&1 || true; cloudflared service install "$T" >/dev/null 2>&1; systemctl is-active cloudflared'

# --- DNS ------------------------------------------------------------------------
log "Pointing $DEV_DOMAIN at the tunnel"
for id in $(cf GET "/zones/$ZONE_ID/dns_records?name=$DEV_DOMAIN" | jq -r '.result[] | select(.type=="A" or .type=="AAAA" or .type=="CNAME") | .id'); do
  cf DELETE "/zones/$ZONE_ID/dns_records/$id" >/dev/null
done
cf POST "/zones/$ZONE_ID/dns_records" "$(jq -nc --arg n "$DEV_DOMAIN" --arg c "$TUN_ID.cfargotunnel.com" \
  '{type:"CNAME", name:$n, content:$c, proxied:true, comment:"Ultralove dev (Cloudflare Tunnel; ops/dev/cloudflare-setup.sh)"}')" >/dev/null

log "Checking https://$DEV_DOMAIN (expect a redirect to the Cloudflare Access login)"
for _ in $(seq 1 12); do
  loc="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "https://$DEV_DOMAIN/" || true)"
  [[ "$loc" == *cloudflareaccess.com* ]] && { echo "    $loc"; log "Dev is live behind Cloudflare Access"; exit 0; }
  sleep 5
done
die "Unexpected response from https://$DEV_DOMAIN: $loc"
