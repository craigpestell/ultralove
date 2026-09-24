#!/usr/bin/env bash
# Copy the live owned wp-content files from a server into this working tree so
# drift (edits made directly on the server) can be reviewed and committed.
#
# Usage: deploy/pull-live.sh <dev|prod>
#
# After running: `git status` / `git diff`, commit what should be kept, push,
# then deploy. Files you don't want to keep: revert them locally and the next
# deploy will remove them from the server (after the drift check passes).
source "$(dirname "$0")/lib.sh"
load_env "${1:?Usage: deploy/pull-live.sh <dev|prod>}"

log "Pulling owned paths from $DEPLOY_ENV [$DEPLOY_HOST:$WP_CONTENT] into wp-content/"
"$RSYNC" -rlE --checksum --delete --filter="merge $RULES_FILE" \
  --exclude='*.bak' --exclude='*.bak-*' --exclude='*.zip' --exclude='*.sql' \
  -e "ssh -o BatchMode=yes" --out-format='    %i %n' \
  "$DEPLOY_HOST:$WP_CONTENT/" "$REPO_ROOT/wp-content/"
git -C "$REPO_ROOT" status --short -- wp-content
