# Pending production release

## Responsive hosted-image selection (live on dev and production)

Lazy product cards now receive `sizes="auto, ..."` based on their actual loading
attribute, including homepage cards made lazy after WordPress calculates sizes.
Modern browsers select from the existing 200/400px variants using the rendered
container width. Eager images retain their existing loading behavior and
conservative sizing hint; older browsers retain the fallback hint. The Cloudinary
safety-net path follows the same rule. Gallery and zoom profiles are unchanged;
there is no arbitrary 1000px source cap.

Banner srcsets add 400px and 1200px candidates in both Cloudflare modes and the
Cloudinary rollback path. Crop geometry, actual-source limits, responsive preload
generation and existing display sizes are unchanged. No CSS regeneration,
attachment regeneration or delivery-option changes are required.

Validation: 165 offline assertions, PHP lint and whitespace checks passed.
The guarded branch deployment to dev passed drift checks and all four smoke
tests; Nginx, PHP-FPM and Redis are active. Fresh browser sessions against the
actual dev deployment verified 195px lazy cards fetching 200px at 1x, 576px
banners fetching 1200px at 2x, and 390px mobile banners fetching 400px at 1x.
All 42 homepage cards use native auto sizing. Both image regression runners
now run in CI. PR #194 merged as `53829137e2` on 2026-10-13; its merge-revision
CI and automatic dev deployment passed. Production was subsequently observed
at the same merge revision. Fresh browser checks on the ordinary public homepage
passed the same 1x/2x/mobile selection assertions, including all 42 auto-sized
cards. Production Nginx, PHP-FPM and Redis are active.

Production media maintenance completed separately on 2026-10-13: a bounded
172-attachment dry run identified 114 new eligible JPEG originals and the
58 existing exceptions. All 114 copies were synchronized and their mappings
source-verified, including attachment 97577 (`SE5550-20-5T.jpg`). Automatic
synchronization remains disabled. The ordinary public homepage subsequently
rendered all 42 card images with hosted URLs without a cache purge.
Prior mappings and result reports are retained root-only outside the webroot at
`/var/lib/ultralove-images/responsive-followup-20261013/`.
Do not repeat this maintenance batch as a production deployment requirement.

## Persistent plugin-header cache (pending deployment)

`wp-content/mu-plugins/39-persistent-plugins-cache.php` allows the Redis object-cache
drop-in to persist WordPress's `plugins` cache group, avoiding repeated plugin-header
reads. Other ignored groups are unchanged. WordPress plugin lifecycle invalidation
remains in place; direct file edits/deploys can take up to the configured one-hour
maximum TTL to appear in cached headers. No database or configuration changes are needed.
The file uses the unused `39-` prefix rather than sharing `37-` with Cloudflare Images.

## Customer Media Library design cleanup (pending production deployment)

The media-library plugin now has labeled filters, a reset link, clearer original-image download
actions, square contained previews, product/image counts, and accessible page-local selection.
The existing authenticated original/ZIP handlers, nonces and download limits are unchanged.
Endpoint-only styles are emitted inline; the CSS source remains in the plugin for maintenance.
No activation, database migration, upload copying or shared theme/critical CSS changes are required.
Preview and approve the design before deploying to production. Dev lacks most catalog originals,
so validate populated layouts with fixtures as well as the unavailable-original state on dev.
Presentation regressions run with `php ops/media-library/test.php`.
Validation: 18 offline presentation assertions, PHP lint, JavaScript syntax and whitespace checks
passed. A browser-only fixture preview using production theme styles passed selection, partial-select,
clear/focus recovery, keyboard and live-announcement checks. Layouts at 1440, 768, 390 and 320 CSS
pixels had no library overflow and download/filter/selection targets at least 44px tall.
This preview did not change either server or exercise real download responses.

## Product-card thumbnail sizes (live on dev and production)

Product cards now offer exactly 200x200 and 400x400 responsive image variants,
with a 200x200 default source. The shared profile applies to shop grids, home
grids, carousels and other WooCommerce product loops in both hosted Images and
remote-source Cloudflare modes. Existing layout hints, lazy loading, source
clamping and square crops are preserved. Gallery navigation remains 100/200px;
main gallery and zoom sizes are unchanged.

PR #164 merged as `89e65e1c4b` on 2026-10-12 after CI and branch-dev validation.
The exact merge revision passed the automatic dev gate and guarded production
deployment. PHP-FPM restarted and the origin HTML cache was cleared by the
deployment; the configured WordPress Cloudflare integration confirmed a zone
cache purge for this sitewide markup change. Nginx, PHP-FPM and Redis are active,
and all four production smoke checks passed.

All 40 shop card tags and 42 home card tags passed verification on each
environment. Sampled physical downloads confirmed 200x200 and 400x400 variants.
Smaller source images retain the no-upscaling rule (for example, a sampled
production home image supplies 200x200 and 366x366 instead of enlarging to 400).
All 110 offline regression assertions and PHP lint passed. No attachment
regeneration, upload changes or delivery-option changes were made.
Production rollback archive:
`/var/lib/ultralove-deploy/backups/20261012T211609Z-0ae4c313b2.tgz`.

## Hosted image delivery cutover (live on production)

**2026-10-12:** production's source-copy scan finished with 20,543 accepted
attachment copies and 58 unique unresolved attachments in its failure ledger.
After the user approved dev, PR #159 was merged and revision `370b30f624`
passed the merge-revision dev gate and guarded production deployment.
Production delivery options are now `cloudflare-images`; automatic hosted-image
sync remains disabled.

Dev is now using `cloudflare-images` for product and banner delivery, with
automatic synchronization disabled. Review the dev-only generated fixtures:

- [Card and cropped-banner preview](https://dev.ultralove.ca/hosted-images-dev-check/)
- [Product gallery and zoom](https://dev.ultralove.ca/product/hosted-images-dev-test/)
- [Deliberately unsynchronized fallback](https://dev.ultralove.ca/product/hosted-images-dev-fallback/)

These hidden, non-purchasable products are test data only; do not export them to
production. Dev has no readable catalog originals, and no production uploads
were copied to it. Real guest/logged-in origin requests, responsive and crop
geometry, public hosted WebP/AVIF delivery, REST source preservation, dirty
generation recovery and delivery rollback passed. Browser checks after Access login verified the mapped fixture's physical
600x600 desktop and 300x300 mobile downloads, plus the hosted cropped banner.
Ordinary dev catalog thumbnails still fall back through HTTP 307/302 to
production originals (1000x1000 in the observed example); they have no dev
hosted mappings or readable local originals in the initial test. The guarded
dev-only remote preview now supplies 20,270 matching verified identities,
without copying production uploads or relaxing production source checks.
All 40 real shop thumbnail tags on the checked page use hosted delivery, with
300x300 physical downloads confirmed on desktop and mobile. All four gallery
zoom sources on the sampled real product page are hosted; zoom intentionally
retains the 1000x1000 source size. Parentless gallery images are recognized
through their current WooCommerce product rather than assumed unrelated.
The user reviewed dev and approved production deployment on 2026-10-12.

Production cutover retains fallback for the 58 unresolved exceptions.
Automatic synchronization must remain disabled until unsupported animated
sources in its separate maintenance path are addressed.
Normal public home/shop pages, mobile thumbnails, all four gallery/zoom images
on the sampled product, and authenticated gallery delivery passed verification.
Origin HTML cache and Cloudflare zone cache were purged for the sitewide URL
change. Original uploads, source URLs for CLI consumers and backup storage
remain unchanged; no unrelated pending-release operations were applied.
See [the image runbook](ARCHITECTURE.md#63-hosted-cloudflare-images-copies)
for evidence, rollback and test-data recovery details.

### Gallery navigation follow-up (live on production)

The first production checks covered main images and zoom, but missed the
navigation strip's separate `data-thumb` URLs. WooCommerce and Martfury request
those as numeric dimensions, bypassing the shared named CDN profiles.
The follow-up uses the existing square gallery thumbnail profile through
WooCommerce's gallery-size hook. Both primary and secondary navigation
thumbnails and slide selection passed dev verification before production.
Automatic synchronization remains disabled.
Dev verification passed on 2026-10-12: all four navigation thumbnails on the
sampled real product download from Cloudflare as 100x100 images on desktop
and mobile; slide selection works and main/zoom sources remain hosted.
PR #161 passed CI and merged as `0ae4c313b2`; its exact merge revision passed
the automatic dev gate and guarded production deployment. After a confirmed
Cloudflare cache purge, the normal production product URL passed the same
desktop/mobile navigation-size and slide-selection checks, with no Cloudinary
images remaining in the inspected gallery. All four main/zoom sources remain
hosted. Service checks, four smoke tests and the live inline critical CSS
verification passed; original uploads and delivery flags are unchanged.

## Dropshipping production rollout (disabled)

**Production status, 2026-10-08:** release `5e920b0` is deployed and verified.
The existing Dropshipping plugin remains active with
`ultralove_dropshipping_enabled=no`; the admin switch is unchecked, approval
fields and the checkout opt-in are hidden, and the approval baseline is
unchanged. The supplier feed and Shopify supplier code are installed but their
plugins remain inactive. No production test users, products, orders, payments,
emails, or shipments were created.

This release includes the shared dropshipping checkbox markup and regenerated
critical CSS. Staging PHP lint, service checks, and public smoke tests passed.
Cloudflare cache was purged through the configured WordPress plugin after a
normal home-page request was found to contain stale CSS; normal home and Sale
Products requests then matched the released inline CSS. The previous revision
was `f229e6b`, backed up at
`/var/lib/ultralove-deploy/backups/20261009T011121Z-f229e6bc4b.tgz`.
Feature enablement still requires separate approval.

`ultralove-dropshipping` adds approved-retailer manual dropship checkout.
The same code supports development and production; the feature defaults off
independently on each environment.

After CI, merge, and verification of the exact main SHA on dev, deploy that SHA
to production through `deploy/deploy.sh`. Set
`ultralove_dropshipping_enabled=no` **before** activating the plugin.
Activate **UltraLove Dropshipping**, verify its admin page is available with
the switch unchecked, and leave all production account approvals unchanged.
User-profile approval fields stay hidden and access changes are rejected while
the switch is off. Ordinary checkout remains unchanged.

Feature enablement is a separate approval, not part of code deployment.
Do not copy dev options/users or run synthetic integration tests on production.
See [the runbook](dropshipping-pilot.md) for verification, packing, and rollback.
This is a separate rollout; do not automatically apply the unrelated database
or Cloudflare steps listed below.

Everything below is on **dev.ultralove.ca** and waits for the client's approval, then ships to
production as one release. Code goes through `deploy/deploy.sh` (see the `ultralove-deploy` skill);
the database / plugin steps are **not code** and have to be repeated on the production server by hand,
in the order listed.

## Code

**Product CSV admin consolidation** - standard retailer and Shopify CSV
generation is owned only by **UltraLove Product CSV Export** (existing plugin
slug `ultralove-product-export-sync`, version 1.1.0). One WooCommerce submenu
replaces Product Export Sync, Update Product CSV and Product Export Generations;
old page URLs redirect to the unified controls. The duplicate child-theme
generator is removed and obsolete hourly export events are cleared.
CSV schemas/URLs and customer-account links are unchanged. Keep this plugin
active; stock-only exports, WP All Import/Export, the feed and dropshipping
plugins are separate and unchanged. Production deployment awaits approval.
The superseded, unconnected Syncee WordPress plugin was deactivated and its
files deleted only on the isolated integration lab. A root-only recovery
archive is retained outside the webroot; it was not installed on dev or production.

**Update cart icon removal** - the child theme suppresses the parent theme's
decorative `::before` icon on cart/checkout tables to avoid the reported Firefox
artifact. The button label, shared spacing, and cart-update behavior are unchanged.
Code-only change for dev preview; production awaits approval. No database or
plugin-setting changes are required.

**Checkout checkbox visibility** - dev preview, awaiting user testing and production approval.
The child theme draws outlined unchecked boxes and pink checked boxes with white ticks
without relying on the reduced icon font. This covers the terms agreement and other
standard checkout checkbox labels, including checkout login's Remember me, account
creation, and alternate shipping address where available. Hidden inputs remain
keyboard-focusable with a visible focus ring. Native/plugin-specific checkbox markup
and payment/shipping radios are unchanged. Targeted browser fixtures passed against
trimmed desktop and original phone styles; full cart/checkout testing is left to the user.
No database or plugin-setting changes are required.

The pre-dropshipping production baseline is `708abcd` (verified 2026-10-07).
The dropshipping release follows the disabled-rollout procedure above on the
production droplet (Ubuntu 26.04, PHP 8.5 - see `documentation/ARCHITECTURE.md` 1.3).
After a deploy that changes cacheable page HTML, purge
Cloudflare (Caching > Purge Everything, or the changed URLs): APO serves cached HTML (`cf-cache-status: HIT`)
until then.

**`/root` backups from before 2026-10-02** (the Turnstile form backup below, the pre-release content backups) were on
the previous production droplet and were not copied to the new one: they're only in the snapshot
`RETIRED-2026-10-02-PRODUCTION-WEB-ultralove.ca-ubuntu24.04-php8.3` (restore it to a temporary 320 GB+ droplet to
get a file back).

## Production steps after the code deploy

**Phones: one responsive design instead of Martfury's mobile version** - on dev since 2026-10-01, waiting for
the go-ahead. Until then production still sends phones Martfury's separate mobile markup (user-agent detected),
so the new phone header, product cards and product page don't show on real phones there.

1. Code: `mu-plugins/martfury-mobile-detect-override.php` makes `martfury_is_mobile()` always false, so phones get the
   same markup as desktop whatever the `enable_mobile_version` theme option says (no database step; the option can
   stay as it is). Rollback: revert that file.
2. Cloudflare > APO: turn off "Cache by device type" (one cached copy per page again).
3. Purge Cloudflare (Purge Everything).

**Footer "About Ultra Love" link label** (PageSpeed SEO "Links do not have descriptive text") - done on dev
2026-10-01. The footer text widget `widget_text` #2 ends with a `footlink` button to `/about` reading "Learn
More"; change it to "More About Ultra Love" (Appearance > Widgets, or a `wp eval-file` that backs up
`widget_text` first and replaces `<a class="footlink" href="/about">Learn More</a>`). Rollback: change the label
back. Then purge Cloudflare (the footer is on every page).

## Already on production (not part of a release)

- Cloudinary responsive breakpoints on (2026-10-03, prod only; the plugin is inactive on dev): option
  `cloudinary_media_display` has `enable_breakpoints` on with `pixel_step` 90 (srcset 190w-1000w). The plugin
  rewrites every image's `sizes` to `(max-width: Wpx) 100vw, Wpx`; `mu-plugins/30-woocommerce-loop-image-sizes.php`
  puts the measured rule back on product cards (`cloudinary_pre_image_tag`). Original setting saved on
  `ultralove-prod-app` at `/root/cloudinary_media_display-before-breakpoints-20261003T063224Z.json`. Rollback:
  `sudo -u www-data wp option update cloudinary_media_display "$(cat <that file>)" --format=json`, then purge
  Cloudflare. Not changed: `crop_sizes['300x300']` is `c_auto,g_auto,w_240,h_240`, so the "300w" candidate is 240px wide.
- Martfury's phone-version fixed Add to cart bar off (2026-10-01, dev and prod): theme mod
  `product_add_to_cart_fixed_mobile` = 0 (`wp theme mod set product_add_to_cart_fixed_mobile 0`; unset it to
  restore Martfury's default, on). The child theme's add-to-cart button rules pinned the bar to the top-left over
  the header instead of along the bottom; the phone version goes away with the responsive switch anyway.
- Cloudflare Turnstile instead of reCAPTCHA v2 on all three forms (footer form 1, Registration 2, Contact Us 3),
  2026-10-01: Gravity Forms Turnstile add-on 1.5.0 with a production widget for `ultralove.ca`, fields switched
  by `ops/gravityforms/switch-recaptcha-to-turnstile.php`. Backup of the forms before:
  `/root/gf-forms-before-turnstile-20261001022554.json` (in the retired droplet's snapshot, above). Rollback: restore each form from it with
  `GFAPI::update_form()` and deactivate the add-on (the reCAPTCHA v2 keys are still in Forms > Settings).

Release of 2026-09-29 / 2026-09-30 (code: product cards and carousels, container-based product columns,
account and Buy Wholesale styling, home page as `front-page.php`, `[ultralove_brand_grid]`, trimmed Martfury
CSS, deferred jQuery, trimmed stylesheets, header search fix). Backups of the replaced content were in `/root`
on the previous production droplet (now in its snapshot, above).

- Brands We Carry page (post 16575) content is `[ultralove_brand_grid]` (2026-09-29).
- Contact Us page (post 70) has the Gravity Forms #3 shortcode after its text (2026-09-29).
- Elementor removed; nothing renders with it (2026-09-29). Its `_elementor_data` post meta is still in the
  database, unused.
- UltraLove Backdrops active.
- Autoptimize combines CSS, inline CSS included: `autoptimize_css_aggregate` and
  `autoptimize_css_include_inline` = `on` (2026-09-30). Rollback: set both to `''`, `wp autoptimize clear`.
- Home page call to action: "UltraLove" -> "Ultra Love" (2026-09-28); the text is in `front-page.php`.

## Product retailer notes (dev code preview)

`mu-plugins/41-product-retailer-notes.php` loads automatically, adding the
WooCommerce General-tab fields "Retailer info" (`_ul_retailer_info`) and
"Important retailer info" (`_ul_retailer_important`). Notes appear on product
cards, product pages and quick view. WP All Import saves extract `<b>` / `<font>`
notes automatically; existing products require a separate migration.

On each environment, first run `wp ul-retailer-notes preview` from the
WordPress root and review the extracted notes and flagged titles. Only after
approval, run `wp ul-retailer-notes apply`; both commands support `--ids=<ids>`
and `--limit=<n>`. Original titles are preserved in `_ul_original_title`.
`wp ul-retailer-notes restore --ids=<ids>` restores those titles and deletes
both note fields, including any later manual edits. Back up the database
outside the webroot before applying or restoring.

Code deployment alone does not migrate existing products. Production deployment
and migration require separate approval after dev testing. Regenerate critical
CSS before production because product-card styling changes. Offline checks:
`php ops/retailer-notes/test-parser.php` (also run by CI).

### New URLs for migrated products (slug-preview / slug-apply)

After a product's notes are cut out of its name its old slug still contains them
(`…-black-testerone-color-per-store-only-free-with-2-units-bought`). `wp ul-retailer-notes
slug-preview` shows, for every migrated product, the slug with only the removed notes' words
taken out (the SKU and brand the slug carries are kept); products whose slug can't be matched
safely (`no-match`) or whose new slug is taken (`collision`) are listed and left alone.
Historical URLs may differ from the saved original title. If exact removal fails, the
planner recognizes a bounded set of retailer-instruction suffixes (discount restrictions,
store/tester offers, special orders, stock/seasonal notices and sale pricing). This fallback
preserves the historical SKU/name prefix and numeric uniqueness suffix; it never rebuilds
the URL from a newer title. Extracted tester notes may remove a trailing `tester`, but
`tester` retained in the cleaned name stays. Repeated instructions, unknown suffix wording,
and instructions still present in the cleaned name remain unchanged for review.
`slug-apply` (also `--ids`, `--limit`, `--csv=<file>`) saves the new slug and adds a 301 from the
old URL to the new one in the Redirection plugin. The helper uses group "Retailer notes slugs",
but Redirection's URL monitor can create the rule first in its default group. WordPress's own
old-slug redirect also covers the old URL. `slug-restore` puts the previous slugs back and deletes
dedicated-group redirects; default-group monitored rules require separate removal or disabling
(the restored old URL must not keep redirecting away). Run `tidy-titles --apply` first on any
environment migrated before 2026-10-15: it adds the space after `<br>` in names that lack it.

Order: deploy code, `tidy-titles --apply`, `slug-preview`, try `slug-apply --ids=...`, check the old
URL answers 301 to the new one (`curl -sI https://<host>/product/<old-slug>/`), then the full
`slug-apply`, then clear the page caches and purge Cloudflare for `/shop/`, product pages and the
sitemaps. Resubmit the product sitemap in Search Console. Back up the database first.

Dev execution (2026-10-15, code `5bfc05b`):
- Backed up the database outside the webroot in the root-only directory
  `/root/ultralove-slug-migration-20261015/` (`before-slugs.sql`, 900,247,631 bytes).
  Export used `--single-transaction --set-gtid-purged=OFF`; the completed dump and
  per-command logs/CSV evidence are retained there.
- Tidied 1,348 names; changed 826 slugs (one sample, then 825 remaining), with
  zero reported migration problems. Final preview: 826 `done`, 519 `no-match`,
  3 `same`, zero eligible changes. Repeating the title preview reports zero.
- Verified every changed permalink, saved previous slug, and enabled exact-target
  301 rule. Redirection's URL monitor created all 826 rules in its default group
  (ID 1) before the migration helper ran, so the CSV results say `exists`, not
  `created`. **Recovery caveat:** `slug-restore` only removes rules in the
  "Retailer notes slugs" group; these monitored rules must also be removed or
  disabled when restoring, or use the pre-migration database backup with care.
- Cleared dev Nginx page cache. Origin checks of products 17188, 65829, 96703,
  and 20594 each returned 301 to the exact cleaned path, then 200 without a PHP
  error; `/shop/` returned 200. Nginx, PHP-FPM, and Redis remained active.
- Dev sitemap checks did not expose a usable sitemap; `/product-sitemap.xml`
  returned 404. No Search Console submission or Cloudflare configuration change
  was made. Production code and data were not changed by this execution.

Production execution (2026-10-15, merged PR #198, code `e27c4e8`):
- Follow-up audit: the first pass was incomplete. In particular, product 97245 retained
  its old URL because the saved title omitted `ONLY` but the historical URL included it.
  Audit all `no-match` rows, not just rows containing `tester`: historical wording such
  as `no-further-discounts-apply` also needs the suffix fallback.
- Merged-main CI and automatic dev deployment passed before the standard production
  deployment. Live drift checks and server PHP lint passed; the deployment smoke
  tests returned 200. Code recovery archive:
  `/var/lib/ultralove-deploy/backups/20261015T190915Z-4e1a22c325.tgz`.
- Exported and verified the pre-migration database in the root-only directory
  `/root/ultralove-slug-migration-20261015T1910Z/` (`before-slugs.sql`,
  947,247,557 bytes, mode 600, completed-dump marker verified). Production's
  MariaDB client used `--single-transaction`; it does not accept the MySQL-only
  `--set-gtid-purged=OFF` option used on dev.
- Tidied 1,331 names. Changed 812 slugs (one sample, then 811 remaining), with
  zero reported problems. Final preview: 812 `done`, 516 `no-match`, 3 `same`,
  zero eligible changes. A repeated title preview reported zero remaining.
  Ambiguous `no-match` slugs were deliberately preserved.
- Independently verified all 812 current permalinks, saved previous slugs, and
  enabled exact-target 301 rules: zero mismatches or conflicting rules. All CSV
  redirect results were `exists` because Redirection's URL monitor created the
  rules first. The default-group recovery caveat above also applies to production.
  Prefer targeted recovery using the saved CSVs; restoring the entire database
  could overwrite orders or content created after the backup.
- Cleared the scoped Nginx page cache. Using the installed Cloudflare client's
  configured credentials without exposing them, confirmed a scoped purge of
  1,631 URLs: both paths for every changed product, home, shop, sitemap index,
  and four product sitemap pages. No DNS, WAF, or cache configuration changed.
- Origin and independent public GET checks of products 17188, 65842, 97219, and
  20594 each returned 301 to the exact cleaned path, then 200 without PHP fatal
  text. Home, shop, account, login, sitemap index, and all four product sitemaps
  returned 200. All 812 published changed URLs appeared in the origin product
  sitemaps, with no old changed URLs; the same complete 812-URL check passed
  against all four public product sitemaps.
  Server-origin checks used loopback routing with the Cloudflare Origin CA
  certificate; independent public checks used normal TLS verification.
- Nginx, PHP-FPM, and Redis remained active. Parser regressions passed locally.
  Private CSVs, command logs, verification scripts/results, and cache-purge
  confirmation are retained beside the database backup. No Search Console
  submission was performed; the working sitemap index is
  `https://ultralove.ca/wp-sitemap.xml`.
