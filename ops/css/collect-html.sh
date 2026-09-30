#!/usr/bin/env bash
# Runs on the dev app server: saves the rendered HTML of every page type (logged out and logged in,
# desktop and mobile user agents) plus AJAX-loaded fragments to /tmp/ulcss, for PurgeCSS.
set -u
cd /var/www/html
OUT=/tmp/ulcss; rm -rf "$OUT"; mkdir -p "$OUT"
W="sudo -u www-data wp --skip-themes"
H=dev.ultralove.ca
UA_D='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36'
UA_M='Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36'

ADMIN=$($W user list --role=administrator --field=ID 2>/dev/null | head -1)
COOKIE_NAME=$($W eval 'echo LOGGED_IN_COOKIE;' 2>/dev/null | tail -1)
COOKIE_VAL=$($W eval "echo wp_generate_auth_cookie( $ADMIN, time() + 1800, 'logged_in' );" 2>/dev/null | tail -1)
JAR=$OUT/.jar; : > "$JAR"; chmod 600 "$JAR"

SIMPLE=/product/w637756-womanizer-beauty-lilac/
PID=$($W db query "SELECT ID FROM wp_posts WHERE post_name='w637756-womanizer-beauty-lilac' AND post_type='product'" --skip-column-names 2>/dev/null | head -1)
PAGES="/ /shop/ /product-category/vibrators-massagers/ /product-brand/california-exotics/ $SIMPLE /sale-products/ /brands/ /wishlist/ /ordering-info/ /contact-us/ /about/ /become-a-retailer/ /my-account/ /cart/ /checkout/ /?s=vibrator&post_type=product /no-such-page-404/ /shop/page/2/"

get() { # name ua loggedin path [extra curl args...]
	local name=$1 ua=$2 li=$3 path=$4; shift 4
	local ck=()
	[ "$li" = 1 ] && ck=(-b "$COOKIE_NAME=$COOKIE_VAL" -b "$JAR" -c "$JAR")
	curl -s --max-time 60 -H "Host: $H" -H "X-Forwarded-Proto: https" -A "$ua" "${ck[@]}" "$@" "http://127.0.0.1$path" -o "$OUT/$name.html"
}

i=0
for p in $PAGES; do
	i=$((i+1))
	get "out-d-$i" "$UA_D" 0 "$p"
	get "out-m-$i" "$UA_M" 0 "$p"
done

# Logged in: put a product in the cart first so the cart and checkout render with items.
get li-addtocart "$UA_D" 1 "/?add-to-cart=$PID"
i=0
for p in $PAGES /my-account/orders/ /my-account/edit-account/ /my-account/edit-address/ /my-account/edit-address/billing/ /my-account/downloads/; do
	i=$((i+1))
	get "in-d-$i" "$UA_D" 1 "$p"
	get "in-m-$i" "$UA_M" 1 "$p"
done

# AJAX fragments: quick view, mini cart, footer recently viewed.
get ajax-quickview "$UA_D" 1 "/?wc-ajax=martfury_product_quick_view" --data "product_id=$PID"
get ajax-quickview-out "$UA_D" 0 "/?wc-ajax=martfury_product_quick_view" --data "product_id=$PID"
get ajax-fragments "$UA_D" 1 "/?wc-ajax=get_refreshed_fragments" -X POST
get ajax-recent "$UA_D" 1 "/?wc-ajax=martfury_footer_recently_viewed" --data "product_ids=$PID"

rm -f "$JAR"
ls "$OUT" | wc -l
du -sh "$OUT"
grep -L "</html>" "$OUT"/out-*.html "$OUT"/in-*.html | head
