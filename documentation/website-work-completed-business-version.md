# Website Infrastructure Upgrade Summary (Business Version)

Last updated: 2026-09-14 (UTC)

## Executive Summary

We modernized and optimized the website platform to reduce monthly infrastructure cost, improve speed and stability, and lower ongoing operational risk.

The project covered infrastructure migration, caching and performance tuning, application-level cacheability improvements in WordPress, media CDN integration, and backup/monitoring automation.

It also included follow-up work to ensure Slider Revolution images use Cloudinary optimization consistently, including cases where slider markup is rebuilt on the frontend. On the homepage, this covered 10 large slide images and 7 smaller slide images. The large banners were roughly 900 KB+ each, the smaller images were roughly 300 KB each, and the optimized versions are now under 100 KB each.

Most recently, a Lighthouse/PageSpeed audit surfaced a server-side compression and caching gap that was fixed and verified live, and identified WordPress plugin/page-builder bloat as the next, higher-impact performance opportunity (see section 6 and Optional Next-Phase Opportunities below).

## Business Outcomes

- Lower hosting cost by moving key workloads to less expensive droplets sized for current demand.
- Better page performance and capacity through layered caching (Cloudflare edge + Redis object cache + server/runtime tuning).
- Improved customer experience with faster and more consistent page delivery.
- Reduced operational risk with automated backups, retention policy, and integrity checks.
- Better maintainability through documented architecture and repeatable audit/collection procedures.

## Scope Of Work Completed

### 1) Infrastructure Cost Optimization

- Migrated development app, development database, and production app workloads to lower-cost DigitalOcean droplets.
- Preserved environment separation between production and development.

### 2) Performance And Caching Optimization

- Configured Cloudflare as active edge delivery for the domain.
- Enabled and tuned Redis-backed WordPress object caching.
- Tuned nginx, PHP-FPM, and PHP OPcache settings to keep server resource usage within acceptable bounds.
- Added a Cloudflare WAF managed challenge rule targeting all traffic originating outside Canada, reflecting the site's Canadian audience. In the first 24 hours, 107,000 challenges were issued and only 33 were solved (a ~0.03% solve rate). Each unsolved challenge is a request that never reached the origin server — PHP is not invoked, no database queries run, and WordPress does not bootstrap. This represents a ~99.97% reduction in bot and scanner-driven origin load from international sources.

### 3) WordPress Cacheability Improvements

- Implemented targeted customizations in the child theme and selected plugins to reduce cache blockers on dynamic pages.
- Focus areas included martfury-child, hide-price-until-login behavior, and wishlist-related behavior.

### 4) Media Delivery Modernization

- Integrated Cloudinary for media/CDN delivery in WordPress.
- Enabled site-level Cloudinary integration behavior for ongoing media offload/delivery.
- Extended Slider Revolution output so Cloudinary-hosted slider images receive optimization parameters such as `f_auto,q_auto:eco`.
- Added a fallback normalization layer so slider-rendered image attributes continue to use optimized Cloudinary URLs even when the slider frontend rewrites markup dynamically.
- Reduced homepage slider image delivery from 10 large images at roughly 900 KB+ each and 7 smaller images at roughly 300 KB each to under 100 KB each.
- Current observed max image file size on the homepage is approximately 62 KB after the Cloudinary slider optimization updates.
- Estimated homepage slider-image payload dropped from roughly 11.1 MB to under 1.7 MB, which is a savings of at least about 9.4 MB or roughly 85%.
- In practical terms, that level of reduction can translate into several seconds of homepage load-time improvement, roughly around 4 to 8 seconds on typical consumer/mobile connections.
- JPG/PNG uploads audit found many original files still large (roughly 2 to 3 MB each), for example: `Carezza_Sleeve_Candy_4_.jpg` (~2.78 MB), `products-SCAEP-BL.jpg` (~2.71 MB), `IMG_4692.jpeg` (~2.68 MB), and `IMG_0946-2.jpg` (~2.53 MB).
- This supports the conclusion that default WordPress image resizing did not materially optimize source file byte size for these assets, while Cloudinary delivery transforms provided the major payload reduction on the homepage.
- Uploads audit identified additional heavy source assets in `wp-content/uploads`, primarily GIF files around 8 to 10 MB each (for example: `Womanize-Compare.gif` ~9.93 MB, `L9141A.gif` ~9.58 MB, `WE8450I.gif` ~9.32 MB, `ST3025C.gif` ~9.22 MB).

### 5) Operational Reliability Enhancements

- Added automated backup synchronization to DigitalOcean Spaces with retention logic.
- Added monthly system configuration backups with checksum files for verification.
- Added recurring cron-based automation for backup tasks.
- Added repeatable monitoring workflow for PHP-FPM and Redis to validate performance tuning against measured data.

### 6) Performance Audit and Origin Caching Fixes (2026-09-14)

- Ran a Lighthouse/PageSpeed Insights audit against the live site to establish a baseline and find low-hanging fixes. Real-user data showed Core Web Vitals failing (LCP 4.1s, TTFB 3.5s, CLS 0.26); the mobile lab test scored ~47-53/100 with a 6.4MB page weight.
- Found that server-level compression (`gzip_types`) had been left entirely disabled, so only page HTML was compressed — CSS, JS, and similar assets were served uncompressed on every request that Cloudflare's edge cache doesn't cover (cart, checkout, account pages, and API/AJAX calls). Fixed and verified live.
- Found that static assets (images, CSS, JS, fonts) had no browser-cache headers at all, meaning repeat visitors re-downloaded roughly 1MB of unchanged assets on every page load. Added a one-year cache policy and verified it live.
- Enabled HTTP/2 on the origin server connection as a supporting best practice.
- All changes were backed up before applying, syntax-validated, and confirmed live without any service interruption; checkout/cart/account pages were confirmed to still correctly bypass caching as intended.
- Identified a larger, second-phase opportunity: the WordPress plugin and page-builder layer (46 active plugins, no CSS/JS optimization plugin, and heavy use of Elementor entrance animations) is the main remaining driver of page weight and load time. This is flagged for a deliberate follow-up phase rather than an in-session change, since it touches live plugin behavior and needs proper testing.

## Short Client-Facing Version

    We reduced infrastructure spend, improved website speed and stability, and made the platform easier to operate. This included server migration, Cloudflare and Redis caching improvements, WordPress cacheability customizations, Cloudinary media CDN integration, improved optimization coverage for Slider Revolution images, stronger backup/monitoring automation, and a performance audit that found and fixed a server-side compression/caching gap. As part of that media work, the homepage's 10 large slider images and 7 smaller slider images dropped from roughly 900 KB+ each and roughly 300 KB each respectively to under 100 KB each. A Cloudflare geo-challenge rule was also added to block non-Canadian bot and scanner traffic at the edge before it reaches the server — in the first 24 hours alone it intercepted 107,000 requests with a solve rate of only 0.03%, meaning the overwhelming majority never touched the origin.

## Optional Next-Phase Opportunities

- Evaluate and roll out a CSS/JS optimization plugin (minify, combine, remove unused code) compatible with the current Elementor/Martfury setup — the single biggest remaining lever for page speed.
- Audit the 9 currently active import/export plugins for overlap and consolidate to what's actually still used.
- Review and reduce Elementor's site-wide entrance-animation usage (62 animated elements currently detected on the homepage), which affects both load time and layout stability.
- Finalize outstanding cross-host verification items (mail node access, database firewall verification under correct privilege).
- Define baseline SLOs and monthly performance/cost reporting.
- Formalize disaster recovery test cadence (backup restore drills).
