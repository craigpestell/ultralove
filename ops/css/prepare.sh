#!/usr/bin/env bash
# Gathers PurgeCSS's inputs into ops/css/work/ (see README.md):
#   html/ - every page type from dev, logged out and logged in, desktop and mobile, plus AJAX fragments
#   js/   - every script those pages load (class names scripts add must survive the trim)
#   dom/  - each page's DOM after its scripts have run (classes added at runtime, e.g. FlexSlider's)
set -euo pipefail
cd "$(dirname "$0")"
CHROME=${CHROME:-"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"}
rm -rf work && mkdir -p work/html work/js work/dom work/render

# 1. Pages, rendered on the dev server itself (dev is behind Cloudflare Access).
ssh ultralove-dev-app 'bash -s' < collect-html.sh
ssh ultralove-dev-app 'tar -C /tmp/ulcss -czf - . && rm -rf /tmp/ulcss' | tar -C work/html -xzf -

# 2. Scripts they load (production URLs; dev-only cache names fall back to the dev server).
python3 - <<'EOF' > work/js-urls.txt
import re, glob, html
s = set()
for f in glob.glob('work/html/*.html'):
    for m in re.findall(r'''(?:src|data-ul-lazy-src)=["']([^"']+\.js[^"']*)["']''', open(f, errors='ignore').read()):
        u = html.unescape(m)
        u = ('https:' + u) if u.startswith('//') else ('https://ultralove.ca' + u) if u.startswith('/') else u
        u = u.replace('dev.ultralove.ca', 'ultralove.ca')
        if 'ultralove.ca' in u:
            s.add(u)
print('\n'.join(sorted(s)))
EOF
i=0
while read -r u; do
	i=$((i + 1))
	curl -s "$u" -o "work/js/$i.js"
	if grep -q '404 Not Found' "work/js/$i.js"; then
		# -n: without it ssh reads the rest of the URL list from stdin and the loop stops early.
		ssh -n ultralove-dev-app "curl -s -H 'Host: dev.ultralove.ca' -H 'X-Forwarded-Proto: https' 'http://127.0.0.1${u#https://ultralove.ca}'" > "work/js/$i.js"
	fi
done < work/js-urls.txt
cp ../../wp-content/themes/martfury/js/scripts.js work/js/martfury-scripts-src.js
cp ../../wp-content/themes/martfury-child/js/*.js ../../wp-content/plugins/ultralove-banners/assets/*.js work/js/ 2>/dev/null || true

# 3. DOM after scripts, rendered locally with the original stylesheet and production assets.
for f in work/html/out-*.html work/html/in-*.html work/html/ajax-*.html; do
	n=$(basename "$f" .html)
	case $n in *-m-*) w=412,3600 ;; *) w=1366,3200 ;; esac
	sed 's#dev\.ultralove\.ca#ultralove.ca#g' "$f" > "work/render/$n.html"
	"$CHROME" --headless=new --disable-gpu --allow-file-access-from-files --window-size=$w --virtual-time-budget=7000 \
		--dump-dom "file://$PWD/work/render/$n.html" > "work/dom/$n.html" 2>/dev/null || true
done

echo "html: $(ls work/html | wc -l)  js: $(ls work/js | wc -l) (of $(wc -l < work/js-urls.txt) urls + theme sources)  dom: $(ls work/dom | wc -l)"
if [ "$(ls work/js | wc -l)" -lt "$(wc -l < work/js-urls.txt)" ]; then
	echo "Not every script was downloaded - don't build from this; rerun." >&2
	exit 1
fi
