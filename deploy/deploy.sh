#!/usr/bin/env bash
# Deploy the repo's owned wp-content paths to dev or prod.
#
# Usage:
#   deploy/deploy.sh <dev|prod> [--ref REF] [--dry-run] [--yes]
#                    [--adopt] [--skip-dev-gate REASON]
#
#   --ref REF         commit/branch/tag to deploy (default: HEAD)
#   --dry-run         show what would change on the server, change nothing
#   --yes             don't prompt before applying
#   --adopt           first deploy to a server with no recorded SHA: verify the
#                     server already matches REF and record it, without writing files
#   --skip-dev-gate   prod only: allow a SHA that isn't live on dev (reason logged)
#
# Safety model:
#   1. Only committed, pushed code is deployed (git archive of a SHA).
#   2. Drift check: live files must match the last deployed SHA. If someone
#      edited the server directly, the deploy stops; run deploy/pull-live.sh,
#      commit the drift, and redeploy.
#   3. Prod requires the same SHA to be live on dev first.
#   4. Files are staged and php-linted on the server before going live, and a
#      tarball backup of the previous files is kept for deploy/rollback.sh.
source "$(dirname "$0")/lib.sh"

ENV_NAME="${1:-}"; shift || true
[[ "$ENV_NAME" == dev || "$ENV_NAME" == prod ]] || die "Usage: deploy/deploy.sh <dev|prod> [--ref REF] [--dry-run] [--yes] [--adopt] [--skip-dev-gate REASON]"

REF=HEAD DRY_RUN=0 YES=0 ADOPT=0 SKIP_GATE_REASON=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) REF="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --yes|-y) YES=1; shift ;;
    --adopt) ADOPT=1; shift ;;
    --skip-dev-gate) SKIP_GATE_REASON="${2:?reason required}"; shift 2 ;;
    *) die "Unknown option: $1" ;;
  esac
done

load_env "$ENV_NAME"
cd "$REPO_ROOT" || exit

# --- 1. Resolve and validate the commit ------------------------------------
git fetch -q origin
SHA="$(git rev-parse --verify "$REF^{commit}")" || die "Cannot resolve ref '$REF'"
SHORT="${SHA:0:10}"
if [[ "$REF" == HEAD && -n "$(git status --porcelain -- wp-content)" ]]; then
  die "Uncommitted changes under wp-content/. Commit and push first."
fi
git branch -r --contains "$SHA" | grep -q . || die "$SHORT is not pushed to origin. Push it first."
if [[ -n "${REQUIRE_BRANCH:-}" ]]; then
  git merge-base --is-ancestor "$SHA" "$REQUIRE_BRANCH" || die "$SHORT is not on $REQUIRE_BRANCH. Merge the PR first."
fi
log "Deploying $SHORT ($(git log -1 --format=%s "$SHA")) to $ENV_NAME [$DEPLOY_HOST]"

# --- 2. Dev gate (prod only) -------------------------------------------------
if [[ "${REQUIRE_DEV_GATE:-0}" == 1 && "$ADOPT" == 0 ]]; then
  if [[ -n "$SKIP_GATE_REASON" ]]; then
    warn "Skipping dev gate: $SKIP_GATE_REASON"
  else
    DEV_SHA="$(load_env dev; remote_deployed_sha 2>/dev/null || true)"
    [[ "$DEV_SHA" == "$SHA" ]] || die "Dev gate: dev is at '${DEV_SHA:-unknown}', not $SHORT. Deploy to dev and verify first (or --skip-dev-gate \"reason\")."
    log "Dev gate passed: $SHORT is live on dev"
  fi
fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# --- 3. Drift check ----------------------------------------------------------
DEPLOYED_SHA="$(remote_deployed_sha)"
if [[ -z "$DEPLOYED_SHA" ]]; then
  [[ "$ADOPT" == 1 ]] || die "No deployed SHA recorded on $ENV_NAME. Run with --adopt once to baseline (verifies the server matches $SHORT)."
  BASE_SHA="$SHA"
else
  [[ "$ADOPT" == 0 ]] || die "$ENV_NAME already has a recorded SHA (${DEPLOYED_SHA:0:10}); --adopt is only for the first deploy."
  git cat-file -e "$DEPLOYED_SHA^{commit}" 2>/dev/null || die "Deployed SHA ${DEPLOYED_SHA:0:10} not found locally. git fetch --all?"
  BASE_SHA="$DEPLOYED_SHA"
fi
log "Checking live files against ${BASE_SHA:0:10} for drift"
export_tree "$BASE_SHA" "$TMP/base"
DRIFT="$(diff_tree_vs_live "$TMP/base")"

# Server-side junk that git deliberately ignores (*.bak*, *.zip, desktop.ini...)
# isn't real drift: move it out of the webroot into $STATE_DIR/quarantine
# (it's often web-readable) instead of blocking the deploy on it.
JUNK="$(printf '%s\n' "$DRIFT" | awk '$1=="*deleting"{print $2}' | while read -r f; do
  [[ -n "$f" && "$f" != */ ]] && git check-ignore -q --no-index "wp-content/$f" && echo "$f"; done || true)"
if [[ -n "$JUNK" ]]; then
  DRIFT="$(printf '%s\n' "$DRIFT" | grep -vxF -f <(printf '*deleting   %s\n' $JUNK) || true)"
  log "Git-ignored junk in owned paths on $ENV_NAME (will be moved to $STATE_DIR/quarantine/):"
  printf '    %s\n' $JUNK
  if [[ "$DRY_RUN" == 0 ]]; then
    printf '%s\n' "$JUNK" | remote "set -e; q='$STATE_DIR/quarantine/$(date -u +%Y%m%dT%H%M%SZ)'; mkdir -p \"\$q\"; chmod 700 '$STATE_DIR'; cd '$WP_CONTENT'; while read -r f; do mkdir -p \"\$q/\$(dirname \"\$f\")\"; mv -- \"\$f\" \"\$q/\$f\"; done"
  fi
fi

if [[ -n "$DRIFT" ]]; then
  warn "Live files on $ENV_NAME differ from ${BASE_SHA:0:10} (edited on the server?):"
  printf '%s\n' "$DRIFT" | sed 's/^/    /' >&2
  die "Refusing to overwrite drift. Run: deploy/pull-live.sh $ENV_NAME, review, commit, push, then redeploy."
fi
log "No drift"

if [[ "$ADOPT" == 1 ]]; then
  [[ "$DRY_RUN" == 1 ]] && { log "Dry run: $ENV_NAME matches $SHORT; would record it as deployed."; exit 0; }
  remote "mkdir -p '$STATE_DIR/backups' && chmod 700 '$STATE_DIR' && echo '$SHA' > '$STATE_DIR/deployed_sha' && echo \"\$(date -u +%FT%TZ) adopt $SHA by ${USER:-unknown}\" >> '$STATE_DIR/deploy.log'"
  log "Adopted: $ENV_NAME recorded at $SHORT"
  exit 0
fi

# --- 4. Plan -----------------------------------------------------------------
export_tree "$SHA" "$TMP/target"
CHANGES="$(diff_tree_vs_live "$TMP/target")"
if [[ -z "$CHANGES" ]]; then
  log "Nothing to change: $ENV_NAME already matches $SHORT"
  [[ "$DRY_RUN" == 1 ]] || remote "echo '$SHA' > '$STATE_DIR/deployed_sha'"
  exit 0
fi
log "Changes (rsync itemize: >f = update/create, *deleting = remove):"
printf '%s\n' "$CHANGES" | sed 's/^/    /'
[[ "$DRY_RUN" == 1 ]] && { log "Dry run; nothing written."; exit 0; }

if [[ "$YES" == 0 ]]; then
  read -r -p "Apply these changes to $ENV_NAME? [y/N] " ans
  [[ "$ans" =~ ^[Yy]$ ]] || die "Aborted"
fi

# --- 5. Stage + lint on the server ------------------------------------------
STAGE="$STATE_DIR/staging"
log "Staging on server and linting PHP with the server's PHP"
remote "mkdir -p '$STAGE/wp-content' '$STATE_DIR/backups' && chmod 700 '$STATE_DIR'"
"$RSYNC" -rlE --checksum --delete -e "ssh -o BatchMode=yes" "$TMP/target/wp-content/" "$DEPLOY_HOST:$STAGE/wp-content/"
CHANGED_PHP="$(printf '%s\n' "$CHANGES" | awk '$1 ~ /^>f/ && $2 ~ /\.php$/ {print $2}')"
if [[ -n "$CHANGED_PHP" ]]; then
  printf '%s\n' "$CHANGED_PHP" | remote "cd '$STAGE/wp-content' && fail=0; while read -r f; do out=\$(php -l \"\$f\" 2>&1) || { echo \"\$out\"; fail=1; }; done; exit \$fail" \
    || die "PHP lint failed on the server; nothing was deployed."
fi

# --- 6. Backup, apply, fix ownership -----------------------------------------
TS="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$STATE_DIR/backups/$TS-${BASE_SHA:0:10}.tgz"
log "Backing up current owned files to $BACKUP"
remote "cd '$WP_CONTENT' && tar -czf '$BACKUP' --ignore-failed-read \
  mu-plugins themes/martfury themes/martfury-child plugins/hide-price-until-login plugins/ultralove-* \
  object-cache.php cloudinary-*.php run-cloudinary-workers.sh 2>/dev/null; \
  ls -1t '$STATE_DIR'/backups/*.tgz | tail -n +21 | xargs -r rm -f"

log "Applying"
"$RSYNC" -q -e "ssh -o BatchMode=yes" "$RULES_FILE" "$DEPLOY_HOST:$STATE_DIR/owned-paths.rules"
remote "rsync -rlE --checksum --delete --out-format='    %i %n' --filter='merge $STATE_DIR/owned-paths.rules' '$STAGE/wp-content/' '$WP_CONTENT/' \
  && cd '$WP_CONTENT' && chown -R '$WEB_USER:$WEB_USER' mu-plugins themes/martfury themes/martfury-child plugins/hide-price-until-login plugins/ultralove-* \
     object-cache.php cloudinary-*.php run-cloudinary-workers.sh 2>/dev/null; true"

# Opcache on these servers does not revalidate timestamps: PHP changes need an FPM restart.
if printf '%s\n' "$CHANGES" | awk '{print $NF}' | grep -q '\.php$'; then
  log "PHP changed: restarting $PHP_FPM_SERVICE"
  remote "systemctl restart '$PHP_FPM_SERVICE' && systemctl is-active --quiet '$PHP_FPM_SERVICE'" || die "$PHP_FPM_SERVICE failed to restart! Roll back: deploy/rollback.sh $ENV_NAME"
fi

remote "echo '$SHA' > '$STATE_DIR/deployed_sha' && echo \"\$(date -u +%FT%TZ) deploy $SHA from ${BASE_SHA} by ${USER:-unknown} backup=$BACKUP${SKIP_GATE_REASON:+ skip-dev-gate=\\\"$SKIP_GATE_REASON\\\"}\" >> '$STATE_DIR/deploy.log'"
log "Recorded $SHORT as deployed on $ENV_NAME"

# --- 7. Smoke test -----------------------------------------------------------
"$REPO_ROOT/deploy/smoke-test.sh" "$ENV_NAME" || die "Smoke test failed. Roll back with: deploy/rollback.sh $ENV_NAME"
log "Deploy of $SHORT to $ENV_NAME complete"
