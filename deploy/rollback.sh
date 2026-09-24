#!/usr/bin/env bash
# Restore the owned files from the most recent pre-deploy backup on a server.
#
# Usage: deploy/rollback.sh <dev|prod> [backup-file-name]
#
# Prefer redeploying a known-good commit (deploy/deploy.sh <env> --ref <sha>)
# when possible; this is the emergency path that needs no git state.
source "$(dirname "$0")/lib.sh"
load_env "${1:?Usage: deploy/rollback.sh <dev|prod> [backup]}"

BACKUP="${2:-$(remote "ls -1t '$STATE_DIR'/backups/*.tgz 2>/dev/null | head -1")}"
[[ "$BACKUP" == /* ]] || BACKUP="$STATE_DIR/backups/$BACKUP"
[[ -n "$BACKUP" ]] && remote "test -f '$BACKUP'" || die "No backup found on $DEPLOY_ENV"
# Backup names are <timestamp>-<sha-that-was-live>.tgz
PREV_SHA_SHORT="$(basename "$BACKUP" .tgz | sed 's/^[^-]*-//')"

log "Available backups on $DEPLOY_ENV:"; remote "ls -1t '$STATE_DIR/backups/' | head -5 | sed 's/^/    /'"
read -r -p "Restore $BACKUP onto $DEPLOY_ENV (live SHA was $PREV_SHA_SHORT)? [y/N] " ans
[[ "$ans" =~ ^[Yy]$ ]] || die "Aborted"

# Clear owned paths first so files added by the bad deploy don't linger.
remote "set -e; cd '$WP_CONTENT'; \
  find mu-plugins -mindepth 1 -maxdepth 1 ! -name 0-worker.php -exec rm -rf {} +; \
  rm -rf themes/martfury themes/martfury-child plugins/hide-price-until-login plugins/ultralove-*; \
  tar -xzf '$BACKUP'; \
  chown -R '$WEB_USER:$WEB_USER' mu-plugins themes/martfury themes/martfury-child plugins/hide-price-until-login plugins/ultralove-*; \
  systemctl restart '$PHP_FPM_SERVICE'; \
  echo \"\$(date -u +%FT%TZ) rollback to $BACKUP by ${USER:-unknown}\" >> '$STATE_DIR/deploy.log'"

FULL_SHA="$(git -C "$REPO_ROOT" rev-parse --verify -q "$PREV_SHA_SHORT^{commit}" || true)"
if [[ -n "$FULL_SHA" ]]; then
  remote "echo '$FULL_SHA' > '$STATE_DIR/deployed_sha'"
  log "Recorded deployed SHA as ${FULL_SHA:0:10}"
else
  warn "Could not resolve $PREV_SHA_SHORT locally; deployed_sha left unchanged. The next deploy's drift check will flag the difference."
fi
"$REPO_ROOT/deploy/smoke-test.sh" "$DEPLOY_ENV"
