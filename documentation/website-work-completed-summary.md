# Website Work Completed Summary

Last updated: 2026-04-27 (UTC)

## One-Page Summary

I migrated the website stack to new, lower-cost DigitalOcean droplets while keeping production and development separated and stable.

I tuned the server stack (nginx, PHP-FPM, Redis, and WordPress caching settings) so resource usage stays within acceptable limits.

I improved cacheability at the application layer by using custom WordPress/theme/plugin work for parts of the site that can otherwise break caching behavior.

I integrated Cloudflare at the DNS/edge layer and confirmed it is actively serving traffic.

I added a Cloudflare WAF custom rule to issue a managed challenge to all traffic originating outside Canada, reflecting the site's Canadian audience. In the first 24 hours, 107,000 managed challenges were issued and only 33 were solved — a ~0.03% solve rate — confirming that virtually all non-Canadian traffic is automated. Every unsolved challenge is a request that never reached the origin server, representing a ~99.97% reduction in bot and scanner load on the origin from international sources.

I integrated Cloudinary for media delivery and enabled site-level Cloudinary behavior in WordPress.

I added targeted Slider Revolution image delivery optimization so slider background images also receive Cloudinary transformation parameters instead of bypassing the optimized media path. The homepage loads 10 large slide images and 7 smaller slide images. The large banner images were roughly 900 KB or more each, the smaller images were around 300 KB each, and the optimized versions are now under 100 KB each.

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

## Short Client-Friendly Version

I moved the site to more cost-effective infrastructure, tuned performance and caching (Cloudflare + Redis + server settings), made targeted WordPress customizations to improve page cacheability, extended Cloudinary optimization to slider images that were previously bypassing those delivery settings, and added a Cloudflare geo-challenge rule that blocked ~99.97% of non-Canadian bot and scanner traffic from ever reaching the origin server. On the homepage, that work covered 10 large slide images and 7 smaller slide images, reducing the large banners from roughly 900 KB+ each and the smaller images from roughly 300 KB each to under 100 KB each. I also set up automated backups, retention, and monitoring so the environment is easier to operate and recover.
