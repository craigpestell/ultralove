#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 DOMAIN TARGET_IP" >&2
  exit 1
fi

DOMAIN="$1"
TARGET_IP="$2"

check_url() {
  local path="$1"
  local expected="$2"
  local code
  code=$(curl -k -s -o /dev/null -w "%{http_code}" --resolve "${DOMAIN}:443:${TARGET_IP}" "https://${DOMAIN}${path}" || true)
  if [[ "$code" =~ $expected ]]; then
    echo "PASS ${path} -> ${code}"
  else
    echo "FAIL ${path} -> ${code} (expected ${expected})"
    return 1
  fi
}

failures=0

check_url "/" "^(200|301|302)$" || failures=$((failures + 1))
check_url "/wp-login.php" "^(200|301|302)$" || failures=$((failures + 1))
check_url "/wp-json/" "^(200|301|302)$" || failures=$((failures + 1))
check_url "/wp-json/cloudinary/v1" "^(200|301|302|401|403)$" || failures=$((failures + 1))

if [[ $failures -gt 0 ]]; then
  echo "Cutover verification failed with ${failures} issue(s)."
  exit 1
fi

echo "Cutover verification passed."
