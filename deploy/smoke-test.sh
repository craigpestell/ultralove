#!/usr/bin/env bash
# Hit key pages and fail on non-200 responses or PHP fatal errors.
# Usage: deploy/smoke-test.sh <dev|prod>
source "$(dirname "$0")/lib.sh"
load_env "${1:?Usage: deploy/smoke-test.sh <dev|prod>}"

fail=0
for path in / /shop/ /my-account/ /wp-login.php; do
  body="$(mktemp)"
  code="$(curl -s -o "$body" -w '%{http_code}' --max-time 30 "$SITE_URL$path?deploy_smoke=$RANDOM")"
  if [[ "$code" != 200 ]]; then
    warn "$path -> HTTP $code"; fail=1
  elif grep -qiE 'critical error on this website|Fatal error|Parse error' "$body"; then
    warn "$path -> 200 but page contains a PHP error"; fail=1
  else
    printf '    %-16s %s\n' "$path" "$code"
  fi
  rm -f "$body"
done
remote "journalctl -u '$PHP_FPM_SERVICE' --since '-3 min' --no-pager 2>/dev/null | grep -iE 'fatal|segfault' | tail -5" || true
exit "$fail"
