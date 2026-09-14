# Website Work Completed Summary

Last updated: 2026-09-14 (UTC)

## One-Page Summary

I migrated the website stack to new, lower-cost DigitalOcean droplets while keeping production and development separated and stable.

I tuned the server stack (nginx, PHP-FPM, Redis, and WordPress caching settings) so resource usage stays within acceptable limits.

I improved cacheability at the application layer by using custom WordPress/theme/plugin work for parts of the site that can otherwise break caching behavior.

I integrated Cloudflare at the DNS/edge layer and confirmed it is actively serving traffic.

I added a Cloudflare WAF custom rule to issue a managed challenge to all traffic originating outside Canada, reflecting the site's Canadian audience. In the first 24 hours, 107,000 managed challenges were issued and only 33 were solved — a ~0.03% solve rate — confirming that virtually all non-Canadian traffic is automated. Every unsolved challenge is a request that never reached the origin server, representing a ~99.97% reduction in bot and scanner load on the origin from international sources.

I integrated Cloudinary for media delivery and enabled site-level Cloudinary behavior in WordPress.

I added targeted Slider Revolution image delivery optimization so slider background images also receive Cloudinary transformation parameters instead of bypassing the optimized media path. The homepage loads 10 large slide images and 7 smaller slide images. The large banner images were roughly 900 KB or more each, the smaller images were around 300 KB each, and the optimized versions are now under 100 KB each.

I ran a Lighthouse/PageSpeed Insights performance audit and fixed a real gap at the nginx layer: compression (`gzip_types`) had been left entirely commented out, so only HTML was being compressed — CSS, JS, and other assets were shipped uncompressed on every request Cloudflare doesn't cache (cart, checkout, my-account, wp-admin, AJAX/API calls). I also added long-lived browser cache headers for static assets, which had none before, and enabled HTTP/2 on the origin listener. I then found and fixed two follow-on issues from that change: a misconfigured header that was accidentally applying the 1-year cache policy to 404 responses, and a Cloudflare zone setting (Browser Cache TTL) that was silently capping the new 1-year cache policy down to 4 hours for real visitors — the single largest remaining PageSpeed opportunity at the time (~1MB). Fixed via the Cloudflare dashboard and verified live.

I evaluated and installed a CSS/JS optimization plugin (Autoptimize) to address the next-largest performance opportunity identified by the audit, configured conservatively (no file combination yet, Elementor explicitly excluded) with a documented rollback procedure. Found and fixed a same-day bug where JS optimization was silently inert due to a conflicting filter hook shipped by the (unused) Nelio A/B Testing plugin; deactivated that plugin after confirming no A/B test was actively running, which resolved the issue.

I also added operational safeguards so the system is maintainable and recoverable, not just fast.

## What Else Was Done (Beyond Performance Tuning)

- Automated backup replication to DigitalOcean Spaces for Updraft backups with retention rules (daily detailed copies, then weekly rollups for older backups).
- Added monthly system configuration backups (including nginx, PHP, cron, systemd, and WordPress config) with checksum files for integrity verification.
- Scheduled recurring automation in root cron for backup/sync workflows.
- Added repeatable monitoring for PHP-FPM and Redis behavior over time to validate tuning decisions with measured data.
- Captured a multi-host architecture snapshot process so production/dev app and database nodes can be audited consistently.
- Kept production Redis non-public (loopback only) and maintained restrictive firewall defaults on production app.
- Documented current architecture, open verification gaps, and next data-collection steps so future handoff work is faster.
- Extended RevSlider image output so Cloudinary-hosted slider images now use `f_auto,q_auto:eco` on slide backgrounds and thumbnails.
- Added a runtime normalization layer for Slider Revolution so rebuilt slider markup also preserves Cloudinary optimization on `src`, `data-lazyload`, and related image attributes.
- Reduced homepage slider image delivery from 10 large images at roughly 900 KB+ each and 7 smaller images at roughly 300 KB each to under 100 KB each after Cloudinary optimization was applied consistently.
- Estimated homepage slider-image payload reduction: from roughly 11.1 MB before optimization to under 1.7 MB after optimization, a savings of at least about 9.4 MB or roughly 85%.
- Very rough frontend impact estimate: this amount of image transfer reduction can improve homepage load time by several seconds, often in the range of about 4 to 8 seconds on typical consumer/mobile connections, with smaller gains on fast broadband.
- Uploads audit (largest original images found): several files in `wp-content/uploads` are still very large, mostly GIFs around 8 to 10 MB each. Representative examples include `Womanize-Compare.gif` (~9.93 MB), `L9141A.gif` (~9.58 MB), `WE8450I.gif` (~9.32 MB), and `ST3025C.gif` (~9.22 MB).
- Documented the RevSlider/Cloudinary implementation and the vendor-update caveat in the architecture runbook.
- Added Cloudflare WAF managed challenge rule for all non-Canadian traffic. In the first 24 hours: 107,000 challenges issued, 33 solved (~0.03% solve rate), blocking approximately 99.97% of international automated traffic before it reaches PHP, MySQL, or WordPress on the origin.
- Ran a PageSpeed Insights / Lighthouse audit: mobile lab score ~47-53/100 (17.7s LCP, 6.4MB page weight), real-user field Core Web Vitals failing (LCP 4.1s, TTFB 3.5s, CLS 0.26).
- Fixed nginx `gzip_types` (was fully commented out — only HTML was compressed), added `Cache-Control: public, max-age=31536000, immutable` for static assets (previously no browser-cache headers at all), and enabled HTTP/2 on the origin TLS listener. Verified live with `nginx -t` before reload and confirmed cart/checkout/my-account still correctly stay uncached.
- Identified the larger remaining performance lever: 48 active plugins (corrected from an initial undercount of 46), no CSS/JS minification or unused-code-removal plugin, and 62 Elementor-animated elements on the homepage.
- Audited the 10 plugins with import/export-adjacent names and found four were a dormant WebToffee toolchain (`wt-import-export-for-woo` and its product/review add-ons, plus a separate standalone WebToffee edition `product-import-export-for-woo`) doing the same job as WP All Import/Export, which the client actively uses. WebToffee's last real activity was 2026-07-20 with zero active scheduled jobs, versus WP All Import/Export running as recently as 2026-09-10/11. Confirmed the WebToffee plugins never loaded anything on the front end (their JS enqueue was already commented out in the plugin code). Deactivated all four; active plugin count dropped from 48 to 44. Verified live with no errors and WP All Import/Export unaffected.
- CSS/JS minification and Elementor animation cleanup remain open, flagged as the next optimization phase pending a plugin-level decision.
- Found that a misconfigured `add_header ... always` parameter was applying the new 1-year static-asset cache policy to 404 responses as well as real assets. Fixed by removing `always` (nginx's default status-code restriction already excludes 404s). Verified live.
- Found that Cloudflare's zone-level Browser Cache TTL setting (4 hours) was overriding the origin's 1-year `Cache-Control` header on every cache HIT for real visitors — the single largest remaining PageSpeed opportunity (~1MB). Fixed via the Cloudflare dashboard (switched to "Respect Existing Headers"), verified via Custom Purge + fresh fetch showing the correct 1-year policy. Also found and fixed a duplicate `Cache-Control` header (caused by having both an explicit `add_header` and an `expires` directive on the same nginx location block) discovered while verifying this fix.
- Installed and configured Autoptimize (free CSS/JS optimization plugin) as a staged, conservative rollout: minification enabled for both CSS and JS, file combination left off for now, and Elementor's own CSS/JS explicitly excluded from optimization since Elementor controls page layout site-wide.
- Found that JS optimization was completely inert immediately after configuration (CSS worked, JS did not) despite identical settings. Root-caused via direct testing against real page content: the Nelio A/B Testing plugin ships its own Autoptimize compatibility hook that was silently blocking JS minification for excluded scripts. Checked for any in-flight A/B experiment first (found one configured but not running), then deactivated Nelio A/B Testing, which resolved the issue immediately with no other change needed.
- Verified live: JS and CSS are both now correctly minified and served from cached URLs, Elementor's own scripts remain untouched, homepage/shop/cart/my-account all return 200, and real visitors (checked through Cloudflare) are already receiving the optimized files with no cache purge needed.
- Documented a full rollback procedure for the Autoptimize change (single plugin deactivation, fully reversible, no database or nginx changes involved) as a condition of rolling this out on production.

## Short Client-Friendly Version

I moved the site to more cost-effective infrastructure, tuned performance and caching (Cloudflare + Redis + server settings), made targeted WordPress customizations to improve page cacheability, extended Cloudinary optimization to slider images that were previously bypassing those delivery settings, and added a Cloudflare geo-challenge rule that blocked ~99.97% of non-Canadian bot and scanner traffic from ever reaching the origin server. On the homepage, that work covered 10 large slide images and 7 smaller slide images, reducing the large banners from roughly 900 KB+ each and the smaller images from roughly 300 KB each to under 100 KB each. I also set up automated backups, retention, and monitoring so the environment is easier to operate and recover. I ran a performance audit and fixed a compression/caching gap at the server level (including a Cloudflare setting that was silently limiting how long browsers could cache files), audited the site's import/export plugins and deactivated four dormant ones that duplicated the actively-used WP All Import/Export system, and installed and configured a CSS/JS optimization plugin (Autoptimize) with Elementor safely excluded and a full rollback plan documented — including tracking down and fixing a same-day bug where an unused A/B-testing plugin was silently blocking part of the optimization.
