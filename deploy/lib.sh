# Shared helpers for deploy/*.sh. Source this file; do not execute it.
# shellcheck shell=bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RULES_FILE="$REPO_ROOT/deploy/owned-paths.rules"

# Prefer GNU rsync; macOS ships openrsync, which mishandles merge-filter rules.
RSYNC="${RSYNC:-}"
if [[ -z "$RSYNC" ]]; then
  for c in /opt/homebrew/bin/rsync /usr/local/bin/rsync rsync; do
    ver="$("$c" --version 2>/dev/null || true)"
    if [[ "$ver" == "rsync  version 3"* ]]; then
      RSYNC="$c"; break
    fi
  done
fi
[[ -n "$RSYNC" ]] || { echo "GNU rsync 3.x is required (brew install rsync)." >&2; exit 1; }

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m %s\n' "$*" >&2; exit 1; }

load_env() {
  local env="$1" f="$REPO_ROOT/deploy/envs/$1.env"
  [[ -f "$f" ]] || die "Unknown environment '$env' (expected deploy/envs/$env.env)"
  # shellcheck disable=SC1090
  source "$f"
  export DEPLOY_ENV="$env"
  WP_CONTENT="$WP_ROOT/wp-content"
}

remote() { ssh -o BatchMode=yes -o ConnectTimeout=15 "$DEPLOY_HOST" "$@"; }

# Print the SHA last deployed to the current environment (empty if never).
remote_deployed_sha() {
  remote "cat '$STATE_DIR/deployed_sha' 2>/dev/null || true"
}

# Export the owned wp-content tree of a commit into a directory.
export_tree() {
  local sha="$1" dest="$2"
  rm -rf "$dest"; mkdir -p "$dest"
  git -C "$REPO_ROOT" archive --format=tar "$sha" wp-content | tar -x -C "$dest"
  mkdir -p "$dest/wp-content"
}

# rsync owned paths from a local tree to the server's wp-content.
# Extra args (e.g. -n, --itemize-changes) are passed through.
rsync_owned_to() {
  local src="$1" dest="$2"; shift 2
  "$RSYNC" -rlE --checksum --delete --filter="merge $RULES_FILE" "$@" \
    -e "ssh -o BatchMode=yes" "$src/" "$DEPLOY_HOST:$dest/"
}

# Show differences between a local tree and live, ignoring timestamps/owners.
# Prints rsync itemized lines; empty output means identical.
diff_tree_vs_live() {
  local tree="$1"
  rsync_owned_to "$tree/wp-content" "$WP_CONTENT" -n --itemize-changes --out-format='%i %n' \
    | grep -Ev '^(\.d|cd\+\+\+\+\+\+\+\+\+) ' || true
}
