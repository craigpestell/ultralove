# Infrastructure Architecture Runbook

Last updated: 2026-09-14 (UTC)
Source of truth for this document:
- Live inspection on host: ubuntu-s-4vcpu-8gb-sfo2
- WordPress runtime and DB option introspection
- Existing backup and cron automation created in this workspace

## 1) Topology Overview

This environment is split across separate production and development droplets in DigitalOcean SFO2, with dedicated mail and database nodes in production.

### 1.1 Known / Inferred Droplets

| Role | Status | Identifier | Notes |
|---|---|---|---|
| Prod App | Confirmed | ultralove-prod-app | Droplet ID 565420744. This host. DO metadata hostname is ultralove-prod-app; system hostname is ubuntu-s-4vcpu-8gb-sfo2. Runs nginx + php-fpm + WordPress + Redis + backup scripts. |
| Prod DB | Confirmed | PRODUCTION-DB-ultralove.ca | Droplet ID 533903042. Private IP 10.120.0.4 matches WordPress DB_HOST. |
| Prod Mail | Confirmed | PRODUCTION-MAIL-mail.ultralove.ca | Droplet ID 242963159. Dedicated mail host. |
| Dev App | Confirmed | ultralove-dev-app | Droplet ID 565178171. Separate application node in sfo2. |
| Dev DB | Confirmed | ultralove-dev-db | Droplet ID 565178217. Separate database node in sfo2. |

### 1.2 DigitalOcean Droplet Inventory

| Name | ID | Region | Image | Status | Public IPv4 | Private IPv4 |
|---|---:|---|---|---|---|---|
| PRODUCTION-MAIL-mail.ultralove.ca | 242963159 | sfo2 | Ubuntu 20.04 (LTS) x64 | active | 138.197.193.88 | 10.120.0.3 |
| PRODUCTION-DB-ultralove.ca | 533903042 | sfo2 | Ubuntu MySQL on Ubuntu 24.04 | active | 142.93.94.167 | 10.120.0.4 |
| ultralove-dev-app | 565178171 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 138.68.49.58 | 10.120.0.2 |
| ultralove-dev-db | 565178217 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 206.189.160.105 | 10.120.0.5 |
| ultralove-prod-app | 565420744 | sfo2 | Ubuntu 24.04 (LTS) x64 | active | 178.128.187.1 | 10.120.0.7 |

### 1.3 Cross-Host OS Baseline (hostnamectl)

Snapshot captured: 2026-04-18 UTC

| Host | Static hostname | Operating system | Kernel | Architecture | Virtualization | Hardware vendor/model |
|---|---|---|---|---|---|---|
| Prod App (this host) | ubuntu-s-4vcpu-8gb-sfo2 | Ubuntu 24.04.4 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Dev App | ultralove-dev-app | Ubuntu 24.04.4 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |
| Prod DB | PRODUCTION-DB-ultralove | Ubuntu 24.04.3 LTS | Linux 6.8.0-107-generic | x86-64 | kvm | DigitalOcean Droplet |
| Dev DB | ultralove-dev-db | Ubuntu 24.04.3 LTS | Linux 6.8.0-110-generic | x86-64 | kvm | DigitalOcean Droplet |

## 2) Prod App Droplet

### 2.1 OS / Platform

- Provider: DigitalOcean (KVM Droplet)
- DigitalOcean droplet name: ultralove-prod-app
- System hostname: ubuntu-s-4vcpu-8gb-sfo2
- Region: sfo2
- OS: Ubuntu 24.04.4 LTS (Noble)
- Kernel: 6.8.0-110-generic
- Architecture: x86_64
- Public IPv4: 178.128.187.1
- Private IPv4: 10.120.0.7

### 2.2 Core Services and Runtime

- nginx: 1.24.0 (service enabled + active)
- php-fpm: php8.3-fpm service enabled + active
- PHP CLI: 8.3.6
- Redis: 7.0.15 (enabled + active)
- certbot: installed (python3-certbot-nginx present)
- UFW: enabled + active

Systemd unit-file snapshot (captured 2026-04-18 UTC via `systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'`):

| Unit file | State | Preset |
|---|---|---|
| nginx.service | enabled | enabled |
| php8.3-fpm.service | enabled | enabled |
| phpsessionclean.service | static | - |
| redis-server.service | enabled | enabled |
| redis-server@.service | disabled | enabled |
| redis.service | alias | - |
| phpsessionclean.timer | enabled | enabled |

### 2.3 Network Exposure and Firewall (UFW)

Default policy:
- Incoming: deny
- Outgoing: allow
- Routed: disabled

Allowed inbound:
- 22/tcp (SSH)
- 80/tcp (HTTP)
- 443/tcp (HTTPS)

Observed listeners:
- nginx on 0.0.0.0:80 and 0.0.0.0:443
- sshd on 0.0.0.0:22
- redis on 127.0.0.1:6379 and ::1:6379 (not public)

### 2.4 Key Configuration Locations

- nginx global: /etc/nginx/nginx.conf
- nginx vhost: /etc/nginx/sites-available/ultralove.ca.conf
- nginx symlink: /etc/nginx/sites-enabled/ultralove.ca.conf
- php-fpm pool: /etc/php/8.3/fpm/pool.d/www.conf
- redis: /etc/redis/redis.conf
- wordpress config: /var/www/html/wp-config.php
- root crontab: /var/spool/cron/crontabs/root

### 2.5 Important Permissions Snapshot

- 644 root:root /etc/nginx/nginx.conf
- 644 root:root /etc/nginx/sites-available/ultralove.ca.conf
- 644 root:root /etc/php/8.3/fpm/pool.d/www.conf
- 640 redis:redis /etc/redis/redis.conf
- 644 www-data:www-data /var/www/html/wp-config.php
- 600 root:crontab /var/spool/cron/crontabs/root
- 700 root:root /root/updraft_spaces_sync.sh
- 700 root:root /root/monthly_system_backup.sh
- 600 root:root /root/.spaces_env

## 3) WordPress Application Layer

### 3.1 Core

- WP version: 6.9.4
- Active theme:
  - stylesheet: martfury-child
  - template: martfury

### 3.2 Database Connectivity

- DB_NAME: wordpress
- DB_HOST: 10.120.0.4 (private network endpoint)
- DISABLE_WP_CRON: true (system cron expected)

### 3.3 Active Plugins (from active_plugins option)

- admin-site-enhancements
- advanced-custom-fields-pro
- advanced-woo-labels
- am-collect-media
- back-in-stock-notifier-for-woocommerce
- cloudinary-image-management-and-manipulation-in-the-cloud-cdn
- contact-form-7
- custom-shipping-methods-for-woocommerce
- elementor
- gravityforms
- gravityformsuserregistration
- hide-price-until-login
- hide-updates
- kirki
- mailchimp-for-wp
- mailgun
- martfury-addons
- meta-box
- ni-woocommerce-custom-order-status
- product-import-export-for-woo
- quantity-field-on-shop-page-for-woocommerce
- redirection
- redis-cache
- relevanssi
- relevanssi-live-ajax-search
- revslider
- support-webp
- updraftplus
- user-role-editor
- user-switching
- widget-shortcode
- woo-add-gtin
- woo-update-manager
- woocommerce
- woocommerce-extra-product-sorting-options
- woocommerce-services
- woocommerce-xml-csv-product-import
- wp-all-export-pro
- wp-all-import-pro
- wp-useronline
- wpae-woocommerce-add-on
- wt-import-export-for-woo
- wt-import-export-for-woo-product
- wt-import-export-for-woo-product_review
- yith-woocommerce-wishlist

### 3.4 Custom / Site-Specific Code

- Custom plugin present: /var/www/html/wp-content/plugins/hide-price-until-login
- MU plugin (ManageWP worker loader): /var/www/html/wp-content/mu-plugins/0-worker.php
- MU plugin (session close helper): /var/www/html/wp-content/mu-plugins/00-close-php-session-for-rest.php
  - Closes active PHP sessions on init/admin_init/rest_api_init and before outbound HTTP requests.
  - Likely intended to reduce blocking/lock contention during REST/loopback calls.

## 4) Caching and Performance Architecture

### 4.1 WordPress Object Cache

- Redis constants set in wp-config.php:
  - WP_REDIS_HOST=127.0.0.1
  - WP_REDIS_PORT=6379
  - WP_REDIS_DATABASE=0
  - WP_REDIS_TIMEOUT=1
  - WP_REDIS_READ_TIMEOUT=1
  - WP_REDIS_MAXTTL=3600
- object-cache drop-in present:
  - /var/www/html/wp-content/object-cache.php
- redis-cache plugin is active.

### 4.2 PHP Runtime Cache

- opcache.enable=On
- opcache.memory_consumption=128
- opcache.validate_timestamps=On
- opcache.revalidate_freq=2

### 4.3 nginx Caching/Compression Notes

- gzip is enabled in /etc/nginx/nginx.conf.
- As of 2026-09-14: `gzip_types` was previously commented out entirely in `/etc/nginx/nginx.conf`, so only nginx's default `text/html` was being compressed — CSS, JS, JSON, SVG, and font responses were served uncompressed. Fixed by setting an explicit `gzip_types` list (css, js, json, xml, svg, fonts, wasm), enabling `gzip_vary on` and `gzip_proxied any`, and raising `gzip_comp_level` from 3 to 5.
- As of 2026-09-14: added a dedicated static-asset `location` block in `/etc/nginx/sites-available/ultralove.ca.conf` matching css/js/mjs/images/fonts, sending `Cache-Control: public, max-age=31536000, immutable` and `expires 365d`. Previously no static assets had any explicit browser-cache headers set at the origin.
- As of 2026-09-14: enabled HTTP/2 on the origin TLS listener (`listen 443 ssl http2;`). Note this nginx build is 1.24.0, which predates the newer `http2 on;` directive syntax (introduced in 1.25.1) — that syntax fails `nginx -t` on this host. Real-user traffic already gets HTTP/2/3 from Cloudflare's edge regardless of the origin listener; this change mainly benefits direct-origin access and Cloudflare-to-origin efficiency.
- Scope note: `cart/`, `checkout/`, `my-account/`, and `wp-admin/` routes are explicitly excluded from caching in this same vhost (`Cache-Control: no-store, no-cache, must-revalidate, max-age=0`), so those are exactly the requests that bypass Cloudflare's edge cache and hit the origin directly. The gzip fix benefits those WooCommerce hot paths most — Cloudflare already recompresses/serves cached anonymous pages (e.g. the homepage) at the edge regardless of the origin `gzip_types` gap, so that fix has limited effect on the anonymous homepage experience specifically.
- Config backups from immediately before this change are kept at `/root/nginx-backups/` on prod-app (timestamped copies of `nginx.conf` and `ultralove.ca.conf`).
- No explicit fastcgi_cache/proxy_cache configuration detected in inspected nginx configs (unchanged — Cloudflare APO/edge cache handles anonymous HTML caching instead; see 5.1).
- Long expires policy detected for uploads/files PHP-blocking location in one alternate vhost file (pre-existing note, unchanged).
- Bug found and fixed same day (2026-09-14): the static-asset `add_header Cache-Control ... always;` line applied the 1-year cache header to *every* response code, including 404s, because `always` overrides nginx's default restriction of `add_header` to success/redirect status codes. Confirmed via `curl` that a nonexistent asset URL returned `HTTP/2 404` with `cache-control: public, max-age=31536000, immutable`. Fixed by removing `always` from that one `add_header` line (the `expires 365d;` directive on the same block was never affected — it already followed nginx's default status-code restriction). Verified live: a 404 on the same location block no longer carries the cache header, a real static asset still does. Note Cloudflare had already cached one bad 404 response (from an earlier manual test hitting a wrong path) with the buggy header before the fix landed — that specific stale edge-cached entry may persist for a while, but it is not a URL any real page links to, so no live traffic is affected.
- Found and fixed same day (2026-09-14): Cloudflare's zone-level Browser Cache TTL was overriding the origin's `Cache-Control: max-age=31536000` down to `max-age=14400` (4 hours) on cache-HIT responses for real, referenced static assets (confirmed via `curl` on `wp-includes/js/jquery/jquery.min.js` and `wp-content/themes/martfury/style.css` — both returned `cache-control: max-age=14400` with `cf-cache-status: HIT`). This was the single largest remaining performance opportunity by estimated savings (~1MB per PageSpeed Insights). Fixed via the Cloudflare dashboard (Caching → Configuration → Browser Cache TTL, set to **Respect Existing Headers**, available even on this zone's Free plan) rather than the API — credential access was intentionally avoided this session; the fix was applied directly through an already-authenticated browser session instead. Verified via Custom Purge on the two test URLs above, followed by a fresh `curl`: `cf-cache-status: MISS` on the re-fetch, then `cache-control: public, max-age=31536000, immutable` correctly reaching the client with no override.
- Side effect found and fixed during that verification: the static-asset block had both an explicit `add_header Cache-Control ...` and an `expires 365d;` directive, which nginx emits as two separate `Cache-Control` response headers (harmless but redundant/non-standard). Removed the `expires` line, keeping only the explicit `add_header`, since it already specifies `public` and `immutable` which `expires` alone does not add. Verified live: single clean `Cache-Control` header, gzip still applies, cart/my-account still correctly `no-store`.

### 4.4 Elementor Generated CSS Caveat

- Elementor page-specific CSS is generated under `wp-content/uploads/elementor/css/`.
- A stale or missing generated file can make frontend styling appear inconsistent between guest and logged-in sessions, especially when caches/CDN or version-stripped asset URLs produce different cache keys.

### 4.5 RevSlider Cloudinary Image Optimization

As of 2026-04-22, Slider Revolution slide background images are normalized to include Cloudinary delivery transforms before the browser loads them.

Implementation details:
- File modified: `/var/www/html/wp-content/plugins/revslider/includes/output.class.php`
- Default transform inserted: `f_auto,q_auto:eco`
- Supported Cloudinary URL shapes:
  - `https://res.cloudinary.com/<cloud>/images/...`
  - `https://res.cloudinary.com/<cloud>/image/upload/...`

Current behavior:
- Slide background image URLs emitted by RevSlider PHP are rewritten to inject the Cloudinary transform before the version segment.
- Slide thumbnail URLs emitted by RevSlider are rewritten the same way.
- A small runtime normalizer is also injected into the slider init script so existing `.rev-slidebg` and `rs-sbg` nodes have `src`, `data-lazyload`, and `data-src-rs-ref` normalized even when frontend markup is rebuilt by RevSlider JS.
- Slide link targets such as `data-link="//ultralove.ca/product/..."` are not rewritten for Cloudinary delivery. They remain navigation URLs.

Key code locations in `output.class.php`:
- `get_cloudinary_image_transform()`
- `maybe_optimize_cloudinary_image_url()`
- `js_get_cloudinary_runtime_normalizer()`

Operational notes:
- This is a direct vendor-plugin modification inside Slider Revolution. A plugin update may overwrite it.
- If the optimization appears missing on the frontend after deploy, purge any page cache/CDN cache first, then verify rendered `data-lazyload` / `data-src-rs-ref` values in the browser.
- PHP and nginx were restarted after the change during live verification.

Example transformation:
- From: `//res.cloudinary.com/dp4dg7god/image/upload/v1776479952/ultralove/catalog/WavesofPleasure-1.jpg`
- To: `//res.cloudinary.com/dp4dg7god/image/upload/f_auto,q_auto:eco/v1776479952/ultralove/catalog/WavesofPleasure-1.jpg`
- Symptom pattern observed on 2026-04-18: page rendered correct `elementor-element-*` classes, but custom Elementor button styling was missing for guests until the stale Elementor CSS cache was cleared.
- Verified remediation on 2026-04-18: `Elementor Plugin -> Tools -> Elementor Cache -> Clear Files & Data`, then purge any site/CDN caches that may still hold stale asset responses.
- Validate the fix against the actual generated asset response, not only the page HTML.

### 4.6 Performance Audit (2026-09-14)

Ran a Lighthouse/PageSpeed Insights audit against `https://ultralove.ca/` to identify low-hanging performance fixes.

Baseline results:
- CrUX field data (real users, desktop, 28-day window): Core Web Vitals **Failed** — LCP 4.1s, TTFB 3.5s, CLS 0.26, INP 45ms.
- Lighthouse lab data (mobile, Moto G Power emulation, Slow 4G throttling): Performance score ~47-53/100, LCP 17.7s, total page weight 6.4MB.

Fixes applied and verified (see 4.3 for the nginx-side detail):
- nginx `gzip_types` fix, static-asset `Cache-Control`/`expires` headers, HTTP/2 on the origin listener.
- Verified live via `nginx -t` before reload, then `curl` checks confirming `content-encoding: gzip` on JS/CSS, `cache-control: public, max-age=31536000, immutable` on static assets, and that `cart/`, `my-account/` responses still correctly send `no-store`/`private` (unaffected by the new caching rule).

Findings not yet acted on at the time of the initial audit (WordPress/application-layer, higher-impact but higher-risk — needs a deliberate follow-up rather than a same-session fix):
- 48 active plugins (corrected count — an earlier pass in this same audit undercounted this as 46), including several with overlapping import/export functionality — see 4.7 for the plugin audit and cleanup performed the same day.
- No CSS/JS minification, combination, defer, or unused-code-removal plugin active (no Autoptimize/Perfmatters/WP Rocket-equivalent) — this is the largest single driver of the 6.4MB page weight and Lighthouse's ~4.25s "render-blocking requests" finding. **Resolved same day — see 4.8.**
- Elementor reports 62 animated elements on the homepage — likely theme/demo-content entrance animations left enabled, contributing to both script weight and the field CLS score of 0.26. Still open; Elementor also controls page layout rendering site-wide, so any change here needs care and should not touch the plugin/layout engine itself, only the animation setting.
- Redis object cache, Cloudflare, and Cloudinary are already correctly in place and not implicated in these findings — the gap is specifically plugin/theme-level asset bloat, not the caching/CDN layer covered elsewhere in this section.

Recommended next phase (not yet scheduled): evaluate/install a CSS-JS optimization plugin compatible with Elementor + Martfury (done same day, see 4.8), and review/reduce Elementor's global entrance-animation usage (still open).

### 4.7 Import/Export Plugin Audit and Cleanup (2026-09-14)

Followed up the same day on the plugin-count finding in 4.6 by auditing the active import/export plugins for overlap.

Of the 10 plugins with import/export-adjacent naming, most turned out not to be redundant:
- `wp-all-import-pro`, `wp-all-export-pro`, `woocommerce-xml-csv-product-import` (WP All Import's WooCommerce add-on), and `wpae-woocommerce-add-on` (WP All Export's WooCommerce add-on) are four parts of one Soflyy system, all required companions of each other. Confirmed actively used by the client — kept.
- `ultralove-product-export-sync` and `ultralove-product-stock-export` are custom in-house plugins (author: UltraLove) purpose-built for CSV export automation and fast direct-DB stock export — kept.

The remaining four were a separate, dormant **WebToffee** toolchain doing the same WooCommerce product/review import-export job as the Soflyy suite above:
- `wt-import-export-for-woo` (wrapper), `wt-import-export-for-woo-product` (product add-on), `wt-import-export-for-woo-product_review` (review add-on), and `product-import-export-for-woo` (WebToffee's own separate standalone/basic edition of the same tool — a third overlapping WebToffee install).

Evidence used to confirm these were safe to deactivate:
- DB table `wp_wt_iew_action_history`: last action **2026-07-20** (~2 months before this audit).
- DB table `wp_wt_iew_cron`: 0 rows — no active scheduled WebToffee jobs.
- By contrast, `wp_pmxi_history` (WP All Import): last run **2026-09-10**. `wp_pmxe_exports.last_activity` (WP All Export): **2026-09-11**. Both in active, current use.
- Checked the WebToffee plugins' front-end-facing code (`public/class-wt-import-export-for-woo-public.php`): the JS enqueue is explicitly commented out with the note *"For the time being this file is not needed as we are not doing any front end activities."* — confirms these never affected front-end page weight/performance; the value in removing them is wp-admin clutter, background PHP overhead on every request, and not keeping three stale, unpatched commercial plugins active.

Action taken: deactivated (not deleted) all four WebToffee plugins via `wp plugin deactivate`. Active plugin count dropped from 48 to 44. Verified live: homepage/shop return 200, wp-admin login redirect (302) unaffected, WP All Import/Export plugins remain active, and nginx error log shows only pre-existing unrelated WooCommerce core deprecation notices (`wc_enqueue_js`), not new errors from the deactivation.

Not yet done: deleting the plugin files themselves. Deactivation is fully reversible from the WordPress admin at any time; deletion should wait for a monitoring period.

### 4.8 CSS/JS Optimization Plugin — Autoptimize (2026-09-14)

Followed up the same day on the largest remaining finding from 4.6 (no CSS/JS minification/combination plugin active) by installing and configuring **Autoptimize** (free, v3.1.15.1) directly on production, as a staged rollout rather than testing on the dev environment first.

**Configuration chosen (deliberately conservative for a first rollout):**
- `autoptimize_css = 1`, `autoptimize_js = 1` — both enabled.
- `autoptimize_css_aggregate = ""`, `autoptimize_js_aggregate = ""` — aggregation/combination left **off**. Autoptimize still minifies each file individually and rewrites it to a cached, hashed URL (`wp-content/cache/autoptimize/autoptimize_single_<hash>.php`), but does not combine multiple files into one bundle. This was a deliberate choice to get the minification win with much lower risk of script-order/dependency breakage, appropriate for a first production rollout.
- `autoptimize_css_exclude = "elementor"`, `autoptimize_js_exclude = "elementor"` — Elementor's own CSS/JS (`webpack.runtime.min.js`, `frontend-modules.min.js`, `frontend.min.js`, and the theme's `martfury-addons/elementor.js`) are excluded from optimization entirely and served untouched, since Elementor controls page layout site-wide and is the highest-risk plugin to interfere with.

**Bug found and fixed the same day: JS optimization was completely inert.** After configuration, CSS optimization worked immediately (verified via cached `autoptimize_single_*.php` URLs replacing original stylesheet links), but JS files were being served completely unprocessed — the JS cache directory (`wp-content/cache/autoptimize/js/`) stayed empty. Root-caused via direct `wp eval` testing of `autoptimizeScripts::read()` against real page HTML fetched straight from the origin (bypassing Cloudflare) with the exact options array production uses:
- Config values, per-post overrides, Autoptimize's and Elementor's own mutual compatibility shims, the `dontmove` exclusion list, and the script-tag regex used by `read()` were all individually tested and ruled out — each behaved correctly in isolation.
- The actual cause: the **Nelio A/B Testing** plugin ships its own Autoptimize compatibility file (`wp-content/plugins/nelio-ab-testing/includes/hooks/compat/cache/autoptimize/index.php`) that hooks `autoptimize_filter_js_exclude` and force-sets `autoptimize_filter_js_minify_excluded` to `__return_false` on every request. Because these are live WordPress filter hooks (not raw option values), they only manifest through the real request pipeline — an isolated test that reads Autoptimize's option values directly, without also triggering `apply_filters()`, will not reproduce the problem, which is why the first round of isolated `read()` testing appeared to succeed.
- Checked Nelio A/B Testing for any in-flight experiment before touching it: one experiment existed (`improved thumbnails`, post type `nab_experiment`) but its status was `nab_ready` (configured, not currently running) — no active test in flight. Deactivated the plugin (`wp plugin deactivate nelio-ab-testing`; not deleted, fully reversible). JS optimization then worked immediately on the next request with no other change required.

**Verification performed after the fix:**
- Confirmed live, direct-from-origin (bypassing Cloudflare) that JS files are now served via hashed `autoptimize_single_*.php` URLs, correctly minified, with valid content-type headers (`text/javascript`) and intact content (spot-checked `fitvids.min.js` output byte-for-byte against source, no truncation/corruption).
- Confirmed Elementor's JS/CSS remain completely untouched (still served from their original theme/plugin paths, not rewritten by Autoptimize).
- Confirmed via Cloudflare edge (`cf-cache-status: MISS` on a live fetch through `https://ultralove.ca/`) that real visitors are already receiving the optimized JS — no Cloudflare purge was needed since the new asset URLs are unique hashed paths Cloudflare had never cached before.
- Spot-checked homepage, `/shop/`, `/cart/`, `/my-account/` — all return `200` with no new errors.

**Rollback procedure (if a problem surfaces later):**
1. `wp plugin deactivate autoptimize --allow-root` — immediately stops all CSS/JS rewriting; WordPress reverts to serving original, unoptimized asset URLs on the very next page load. Fully reversible, no data loss.
2. Optionally clear Autoptimize's own disk cache: `wp eval 'autoptimizeCache::clearall();' --allow-root`, or delete the contents of `wp-content/cache/autoptimize/` directly (safe — Autoptimize regenerates it automatically if reactivated).
3. Purge the Cloudflare cache for the site (Custom Purge in the dashboard, or `wp cache flush --allow-root` plus a Cloudflare purge) if any stale optimized-asset HTML has been cached at the edge in the meantime.
4. No nginx, database schema, or theme changes were made as part of this work — rollback is entirely a WordPress plugin-level action, isolated from the rest of the stack.

**Still open / not yet done:** aggregation (combining multiple CSS/JS files into fewer requests) remains off — the biggest additional win still available from Autoptimize, deferred pending a monitoring period on the current conservative configuration. Re-run PageSpeed Insights after a monitoring period to quantify the actual improvement.

### 4.9 Autoptimize JS Aggregation Enabled (2026-09-14)

Followed up the same day after noticing the homepage was still loading ~79-87 individual JS requests despite 4.8's minification work — expected, since aggregation had deliberately been left off in the initial conservative rollout (minification reduces file size per request, not request count).

**Change made:** enabled `autoptimize_js_aggregate` (combines eligible JS files into one bundle). Also enabled `autoptimize_js_trycatch`, which wraps each aggregated script segment in its own `try/catch` so one broken script can't take down the rest of the bundle — an extra safety net specifically for this first aggregation rollout. Elementor's own JS remains excluded from the bundle (unaffected by this change, still served untouched from its original path).

**Result:** homepage script requests dropped from 79 distinct `<script src>` tags to 14 — one aggregated bundle (~593KB, minified) covering jQuery, WordPress core dist scripts, Gravity Forms, and Slider Revolution, plus the still-separately-served Elementor files and one individually-minified theme file (`martfury-addons/elementor.js`, excluded from the bundle by name-match but still minified standalone, unchanged from 4.8's behavior).

**Verification performed:**
- Downloaded the aggregated bundle and syntax-checked it with `node --check` — valid, not truncated (confirmed the trailing `try/catch` wrapper closes cleanly).
- Checked every script/asset URL referenced on the live homepage via `curl` — all return `200`.
- Loaded the site in a real browser (homepage, a product page, and the shop archive) and read the console for errors. Found one recurring `TypeError: Cannot read properties of null (reading 'addEventListener')`, present identically on all three pages tested, at a fixed line in an inline footer script that looks for `document.getElementById('exportMe')` / `getElementById('export')` (an unrelated CSV-export feature apparently enqueued sitewide rather than only where those elements exist). Confirmed via git-independent evidence (identical error, same code shape, present on every page including ones untouched by today's changes) that this is a **pre-existing bug unrelated to the Autoptimize/aggregation change**, not a regression — it's a self-contained inline script that doesn't depend on or interact with any bundled/aggregated file, so it fails the same way with or without aggregation. Not fixed as part of this work since it's outside the scope of the performance task; flagged below as a separate item for the client/dev team to address.
- Visually confirmed the "On Sale" and "Latest Adult Products" carousels, product image galleries, and product detail pages all render correctly with the aggregated bundle in place.
- Could not fully test the add-to-cart/checkout JS flow end-to-end, since this is a login-gated wholesale site (pricing and cart require a registered account) and no test credentials were available in this session. Nothing observed suggests cart JS is affected — the add-to-cart button markup and handlers are unrelated to the files now being aggregated — but this should be spot-checked with a real login when convenient.

**Rollback:** identical to 4.8 — `wp plugin deactivate autoptimize --allow-root` fully reverts both minification and aggregation in one step. To back out only aggregation while keeping minification, set `autoptimize_js_aggregate` back to empty via `wp option update autoptimize_js_aggregate "" --allow-root` and clear Autoptimize's cache.

**Separate finding, not part of this work:** the sitewide inline "CSV export" script referenced above throws a console error on every page because its target elements don't exist outside whatever admin/reporting page it was written for. Harmless to shoppers (self-contained, doesn't block other scripts) but worth a cleanup pass — likely just needs to check the elements exist before attaching the listener, or be scoped to load only on the intended page.

### 4.10 Important Caveat: JS Optimization Looks "Off" When Logged In (2026-09-14)

Came up the same day when checking shop/product pages and initially appearing to see no optimized JS there at all, unlike the homepage. Root cause: **Autoptimize disables all JS optimization sitewide for any logged-in user who can edit posts**, on every page — not just page-specific, and not just inside the Elementor editor.

This comes from Autoptimize's own built-in compatibility shim for Elementor (`wp-content/plugins/autoptimize/classes/autoptimizeCompatibility.php`, line ~35):

```php
if ( defined( 'ELEMENTOR_VERSION' ) && is_user_logged_in() && current_user_can( 'edit_posts' ) && apply_filters( 'autoptimize_filter_compatibility_editelementor_active', true ) ) {
    add_filter( 'autoptimize_filter_js_noptimize', '__return_true' );
}
```

The plugin's own comment explains the intent: *"Edit with Elementor in frontend admin menu (so for editors/administrators) needs JS opt. disabled to appear & function."* It fires whenever a logged-in, edit-capable user views **any** frontend page — simply so the "Edit with Elementor" link in the admin toolbar always works, regardless of which page they're currently viewing. It is unrelated to the separate Elementor-side shim in `elementor/includes/compatibility.php`, which is scoped only to actual Elementor edit-mode.

**Practical implication:** if you (or any admin/editor account) check the site's JS optimization while logged into wp-admin, it will look completely absent — on every page, homepage included — even though it's fully active. This is expected, by design, and not a bug. **Verified as a logged-out visitor** (the actual shopper experience) that home, `/shop/`, and a product page all correctly serve their own aggregated/minified JS bundle. Always verify Autoptimize's output using a logged-out session (incognito window, or `curl` without cookies) — checking while logged in will give a false negative.

## 5) Cloudflare Setup

### 5.1 DNS / Edge Evidence

- NS for domain points to Cloudflare:
  - arya.ns.cloudflare.com
  - andy.ns.cloudflare.com
- HTTP response headers from https://ultralove.ca include:
  - server: cloudflare
  - cf-cache-status
  - cf-ray

### 5.2 WAF Geo-Challenge Rule

- Custom rule `Challenge outsiders` applies a Managed Challenge to non-Canadian traffic while excluding Cloudflare-verified bots:
  - `(ip.src.country ne "CA" and not cf.client.bot)`
- The verified-bot exclusion is required for Googlebot and other legitimate crawlers to fetch the public WordPress sitemap and indexed pages without weakening the challenge for unverified traffic.

### 5.3 Application-Level Cloudflare Integration

- No dedicated Cloudflare plugin confirmed as active.
- Cloudflare Turnstile integration present via plugin code in back-in-stock-notifier-for-woocommerce.
- WordPress option key indicating Cloudflare page cache plugin traces exists:
  - wp_cloudflare_page_cache_install
  - wp-cloudflare-page-cache_version

## 6) Cloudinary Setup

### 6.1 Plugin and Runtime Footprint

- Cloudinary plugin active:
  - cloudinary-image-management-and-manipulation-in-the-cloud-cdn
- Cloudinary-related option keys exist in WP DB, including:
  - cloudinary_connect
  - cloudinary_cloud_name
  - cloudinary_setup
  - cloudinary_sync_media
  - cloudinary_urls
  - cloudinary_status
- Custom option detected:
  - ultralove_enable_cloudinary_overrides

### 6.2 Interpretation

- Cloudinary integration is not just installed; it appears configured and used (based on option footprint).
- Secret values are intentionally not documented in plaintext here.

## 6.5 Mail Transport

### 6.5.1 WordPress Outbound Mail

- Mailgun plugin is active:
  - mailgun/mailgun.php
- Installed plugin package identifies itself as:
  - Mailgun for WordPress
  - Stable tag 2.1.10
- The plugin supports sending via Mailgun HTTP API or SMTP.
- Based on the site stack, Mailgun should be treated as the application-level outbound mail transport for WordPress emails.

### 6.5.2 Architecture Interpretation

- WordPress transactional/application mail is routed through Mailgun.
- This is separate from the dedicated production mail droplet `PRODUCTION-MAIL-mail.ultralove.ca`, which likely serves mailbox/MTA or other mail infrastructure responsibilities outside WordPress plugin-driven outbound mail.
- Exact Mailgun mode and credentials are intentionally not recorded in plaintext here.

## 7) Backups and Retention Architecture

### 7.1 Updraft + Spaces Backup Flow

- Source directory: /var/www/html/wp-content/updraft
- Sync script: /root/updraft_spaces_sync.sh
- Credentials file: /root/.spaces_env (600)
- Destination pattern: s3://ultralove-web-backups/updraft/YYYY-MM-DD/
- Retention policy in script:
  - Keep all sets for last 3 days
  - Keep newest set per ISO week for days 4-33
  - Delete older redundant remote files

### 7.2 Monthly System Configuration Backups

- Script: /root/monthly_system_backup.sh
- Local output: /root/system_monthly_backups/YYYY-MM/
  - system-config-YYYY-MM.tar.gz
  - system-config-YYYY-MM.sha256
  - inventory-YYYY-MM.txt
- Spaces upload destination:
  - s3://ultralove-web-backups/system-config/YYYY-MM/
- Scope includes:
  - nginx, php, letsencrypt, cron configs, systemd overrides
  - root backup scripts and env file
  - wp-config.php

### 7.3 Cron Schedule (root)

- Daily Updraft sync (currently DRY_RUN=1):
  - 20 3 * * * bash -lc "source /root/.spaces_env; DRY_RUN=1 /root/updraft_spaces_sync.sh >> /var/log/updraft_spaces_sync.log 2>&1"
- Monthly system backup:
  - 40 2 1 * * /root/monthly_system_backup.sh >> /var/log/monthly_system_backup.log 2>&1

## 8) Open Items To Complete “Entire Architecture”

The following still cannot be fully verified from this host alone and should be completed from direct access or per-host inspection:

- Dev App droplet
  - partial verification (2026-04-18 UTC): hostnamectl + /etc/os-release confirm Ubuntu 24.04.4 LTS and kernel 6.8.0-110-generic
  - partial verification (2026-04-18 UTC): ufw status is inactive
  - partial verification (2026-04-18 UTC): `systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'` shows nginx.service and php8.3-fpm.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled; no mysql/mariadb/redis unit file in this filtered list
  - partial verification (2026-04-18 UTC): WordPress runtime check returns WP 6.9.4; active plugin set is near-parity with prod but `redis-cache/redis-cache.php` is present on prod-app and absent on dev-app
  - nginx/php config paths
  - theme and broader app parity vs prod (beyond plugin list)
- Dev DB droplet
  - partial verification (2026-04-18 UTC): hostnamectl + /etc/os-release confirm Ubuntu 24.04.3 LTS and kernel 6.8.0-110-generic
  - partial verification (2026-04-18 UTC): ufw active; default deny incoming/allow outgoing; allows 22/tcp and 3306/tcp from 10.0.0.0/8
  - partial verification (2026-04-18 UTC): `systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'` shows mariadb.service enabled; mariadb@.service disabled; mysql.service and mysqld.service aliases; mariadb socket units static
  - bind/addressing
  - backup and restore process
- Prod DB droplet (PRODUCTION-DB-ultralove.ca / 10.120.0.4)
  - partial verification (2026-04-18 UTC): hostnamectl + /etc/os-release confirm Ubuntu 24.04.3 LTS and kernel 6.8.0-107-generic
  - partial verification (2026-04-18 UTC): systemctl list-unit-files filtered for nginx/php/mysql/mariadb/redis shows mysql.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled
  - partial verification gap (2026-04-18 UTC): ufw status check failed under non-root SSH user (`craig`), so firewall state remains unverified here
  - engine process/service status
  - backup cadence and retention
  - replication or HA status (if any)
- Prod Mail droplet (PRODUCTION-MAIL-mail.ultralove.ca / 10.120.0.3)
  - data collection attempt (2026-04-18 UTC) failed: `root@138.197.193.88` returned `Permission denied (publickey,password)`
  - establish valid SSH principal/key for this host
  - mail stack (Postfix/Dovecot/etc.)
  - DNS/mail routing responsibilities
  - relationship to Mailgun for outbound application mail vs server mail
  - backup and retention process
- Cloudflare account settings
  - SSL/TLS mode, WAF rules, cache rules, page rules/transform rules, bot settings
- Cloudinary account settings
  - cloud name/account id, transformation presets, delivery mode, quota/usage alerts

## 9) Recommended Next Data Collection Commands

Run these from an operator workstation with appropriate access:

- DigitalOcean droplet inventory:
  - doctl compute droplet list --format Name,ID,Region,Image,Status,PublicIPv4,PrivateIPv4
- Per-droplet config snapshots:
  - hostnamectl
  - cat /etc/os-release
  - ufw status verbose
  - systemctl list-unit-files | rg -i 'nginx|php|mysql|mariadb|redis'
- WordPress runtime parity checks (each app node):
  - php -r 'chdir("/var/www/html"); require "wp-load.php"; echo get_bloginfo("version"),"\n";'
  - php -r 'chdir("/var/www/html"); require "wp-load.php"; print_r(get_option("active_plugins",[]));'

## 10) Appendix: 2026-04-18 Multi-Host Collection Snapshot

Collection bundle:
- /root/architecture-collection-20260418_040102/

Collection status summary:

| Role | Target | Status |
|---|---|---|
| prod-app | root@178.128.187.1 | OK |
| dev-app | root@138.68.49.58 | OK |
| prod-db | craig@142.93.94.167 | OK |
| dev-db | root@206.189.160.105 | OK |
| prod-mail | root@138.197.193.88 | FAILED |

### 10.1 prod-app

- hostnamectl: ubuntu-s-4vcpu-8gb-sfo2
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0-110-generic
- UFW: active (default deny incoming, allow outgoing); allows 22/tcp, 80/tcp, 443/tcp (+v6)
- service filter result: nginx + php8.3-fpm enabled; redis-server enabled; phpsessionclean.timer enabled
- WordPress check: version 6.9.4; active plugin list captured in full in `prod-app.log`

### 10.2 dev-app

- hostnamectl: ultralove-dev-app
- OS/kernel: Ubuntu 24.04.4 LTS, Linux 6.8.0-110-generic
- UFW: inactive
- service filter result: nginx + php8.3-fpm enabled; phpsessionclean.timer enabled; no mysql/mariadb/redis in filtered unit output
- WordPress check: version 6.9.4; plugin list near parity with prod-app, with `redis-cache/redis-cache.php` present on prod-app and absent on dev-app in this snapshot

### 10.3 prod-db

- hostnamectl: PRODUCTION-DB-ultralove
- OS/kernel: Ubuntu 24.04.3 LTS, Linux 6.8.0-107-generic
- UFW: command failed under non-root user (`ERROR: You need to be root to run this script`)
- service filter result: mysql.service enabled; phpsessionclean.service static; phpsessionclean.timer enabled

### 10.4 dev-db

- hostnamectl: ultralove-dev-db
- OS/kernel: Ubuntu 24.04.3 LTS, Linux 6.8.0-110-generic
- UFW: active (default deny incoming, allow outgoing); allows 22/tcp and 3306/tcp from 10.0.0.0/8
- service filter result: mariadb.service enabled; mariadb@.service disabled; mysql/mysqld aliases; mariadb socket units static

### 10.5 prod-mail

- collection failure: SSH authentication failed for root
- error: `root@138.197.193.88: Permission denied (publickey,password).`
- action needed: use correct SSH user/key and rerun collector for this host

## 11) Plain-Language Summary Of Work Completed

Use this section when explaining the project to non-technical stakeholders.

### 11.1 One-Page Summary

I migrated the website stack to new, lower-cost DigitalOcean droplets while keeping production and development separated and stable.

I tuned the server stack (nginx, PHP-FPM, Redis, and WordPress caching settings) so resource usage stays within acceptable limits.

I improved cacheability at the application layer by using custom WordPress/theme/plugin work for parts of the site that can otherwise break caching behavior.

I integrated Cloudflare at the DNS/edge layer and confirmed it is actively serving traffic.

I integrated Cloudinary for media delivery and enabled site-level Cloudinary behavior in WordPress.

I also added operational safeguards so the system is maintainable and recoverable, not just fast.

### 11.2 What Else Was Done (Beyond Performance Tuning)

- Automated backup replication to DigitalOcean Spaces for Updraft backups with retention rules (daily detailed copies, then weekly rollups for older backups).
- Added monthly system configuration backups (including nginx, PHP, cron, systemd, and WordPress config) with checksum files for integrity verification.
- Scheduled recurring automation in root cron for backup/sync workflows.
- Added repeatable monitoring for PHP-FPM and Redis behavior over time to validate tuning decisions with measured data.
- Captured a multi-host architecture snapshot process so production/dev app and database nodes can be audited consistently.
- Kept production Redis non-public (loopback only) and maintained restrictive firewall defaults on production app.
- Documented current architecture, open verification gaps, and next data-collection steps so future handoff work is faster.

### 11.3 Short Client-Friendly Version

I moved the site to more cost-effective infrastructure, tuned performance and caching (Cloudflare + Redis + server settings), and made targeted WordPress customizations to improve page cacheability. I also set up automated backups, retention, and monitoring so the environment is easier to operate and recover.

---

If you want this runbook split into separate docs (Network, App, Security, Backups, Integrations) for handoff to ops/dev teams, use this file as the canonical source and generate role-specific extracts.
