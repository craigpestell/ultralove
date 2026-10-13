#!/usr/bin/env bash
# Push the current branch and deploy it to dev, printing one result line.
#
# Usage: deploy/ship-dev.sh
#
# Wraps push + `deploy.sh dev --yes` and keeps the verbose output in a log file,
# so a routine dev deploy costs one short line instead of a screen of rsync output.
# Failures print the tail of the log. Prod is never touched; use deploy.sh prod.
source "$(dirname "$0")/lib.sh"
cd "$REPO_ROOT" || exit

BRANCH="$(git branch --show-current)"
[[ -n "$BRANCH" ]] || die "Detached HEAD. Check out a branch first."
[[ "$BRANCH" != main ]] || die "On main: merging to main already deploys to dev (deploy-dev workflow)."
[[ -z "$(git status --porcelain -- wp-content)" ]] || die "Uncommitted changes under wp-content/. Commit first."

LOG="$(mktemp "${TMPDIR:-/tmp}/ship-dev.XXXXXX")"

git push -q -u origin HEAD >"$LOG" 2>&1 || { tail -15 "$LOG" >&2; die "git push failed (log: $LOG)"; }

SHA="$(git rev-parse HEAD)"
if ! "$REPO_ROOT/deploy/deploy.sh" dev --yes --ref "$SHA" >>"$LOG" 2>&1; then
  tail -25 "$LOG" >&2
  die "dev deploy of ${SHA:0:10} failed (full log: $LOG)"
fi

# Count only the plan block; the apply step prints the same files again.
FILES="$(awk '/Changes \(rsync/{p=1;next} /==>/{p=0} p&&/^ +([<>]f|\*deleting)/{n++} END{print n+0}' "$LOG")"
if grep -q 'Nothing to change' "$LOG"; then
  SUMMARY="already live, nothing to change"
else
  SUMMARY="$FILES file(s) changed, smoke test passed"
fi
log "dev is at ${SHA:0:10} ($BRANCH): $SUMMARY (log: $LOG)"
