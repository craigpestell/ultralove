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
notes and recognized terminal retailer instructions automatically; existing products
require a separate migration.

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
If stripping a tester offer would collide with a retail product, the planner tries
the descriptive `-tester` suffix, still refusing the change if that URL is also taken.
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

### Historical-slug follow-up (2026-10-15, merged PR #201, code `00256ae`)

- Dev and production each migrated another 287 URLs, with zero reported problems.
  Both had fresh, verified root-only database backups in
  `/root/ultralove-slug-followup-20261015/`: dev 900,929,578 bytes and production
  947,783,098 bytes. Production code recovery archive:
  `/var/lib/ultralove-deploy/backups/20261015T203117Z-e27c4e8d9c.tgz`.
- Merged-main CI and automatic dev deployment passed before production deployment.
  Production applied four samples, then the remaining 283. All 287 permalinks,
  saved previous slugs, enabled exact-target 301s and repeat-run behavior passed
  independent checks. Final production counts at this stage: 1,099 `done`,
  229 `no-match`, 3 `same`, zero eligible or colliding changes.
- Product 97245 now uses
  `/product/l9486t-lelo-enigma-double-sonic-black/`; its reported historical
  tester-offer URL redirects directly to that path.
- Cleared the scoped Nginx page cache and confirmed a scoped Cloudflare purge
  of 581 URLs. Origin and public HTTP checks passed, including all 287 new
  published URLs in the four product sitemaps and no affected old URLs.
  Live inlined critical CSS matched the source after ignoring its exact
  generated leading attribution comment, which HTML optimization removes.
  Nginx, PHP-FPM and Redis remained active. No Search Console submission.
- The default-group Redirection recovery caveat above still applies. Private
  plans, CSVs and independent verification/purge/HTTP evidence accompany the
  backups. Audit all planner categories and unmigrated titles in future:
  `no-match` alone does not expose malformed or unwrapped title notes.

### Unwrapped and malformed title notes

The supplier sometimes supplies terminal instructions without an opening
`<b>` tag, or without any wrapper. The parser now recognizes only the explicit
terminal discount, store/free-unit, special-order, stock/seasonal and sale-price
phrases used by the migration. An immediately preceding `TESTER` / `TESTER ONLY`
joins that note; product names, variants, and "Demo and Display" remain intact.
An orphan closing `</b>` / `</font>` is accepted only after that recognized run.
Unknown malformed markup remains flagged and skipped; unfamiliar offer wording
requires review, not guessed removal. Standalone tester names are not reclassified.

Duplicate opening angles before a valid `<br>` are repaired (`<<br>` becomes
`<br>`); literal comparisons and escaped angles are preserved. The slug planner
still tries historical suffix removal when malformed original markup makes
WordPress sanitize the original and residual to the same string.

After deployment, run title `preview` (which now includes unwrapped instructions),
review, back up, apply approved IDs, then `slug-preview` / `slug-apply` for those
IDs. Product 47748 was already cleaned with a trailing `<`; do not blindly replay
saved originals over current names. Its one-time repair must require that the
current name exactly equals the corrected parser result plus the stray `<`,
preserving `_ul_original_title` and both note fields. Then migrate its slug with
the standard command. Verify titles, preserved notes, exact 301 targets, canonical
URLs and sitemap membership after scoped cache purges.

### Tester-name audit (read-only, 2026-10-15)

Production has 64 published products with `tester` anywhere in their current
names, including plural and case variants:

- 34 have store-limit or purchase offers in Retailer info.
- 21 still have offer text in their names: 20 use the terminal phrases covered
  by the pending parser fix; product 26625's multi-line purchase offer still
  requires review.
- 3 already have these offers in Important retailer info.
- 6 have no store/purchase offer in their current names or note fields. Preserve
  meaningful qualifiers such as "Tester Not Included", "Tester Holder",
  "Display For Tester", "PLUS Testers" and "w/Testers"; unrelated discount notes
  should not move merely because the product name mentions testers.

Another 490 products mention testers only in saved original titles. Of these,
489 have store/purchase offers in Important retailer info; product 24256 instead
has the descriptive phrase "with Testers" moved there. Product 51896 also lost
"Display For Tester" along with its offer. Review those two titles separately;
do not replay saved originals over subsequent product edits. No production data
changed during this audit.

Retained tester names now classify whole recognized store/purchase instructions
as Important, including the Starstruck line-specific offer and Sync Lite Tester
Kit. Unrelated discounts and unknown/manual mixed notes stay regular. Wrapped
"with Testers" and "Display For Tester" remain product identity, including the
latter followed by a recognized offer inside the same wrapper. Future imports
remove exact copies of newly Important notes from regular info.

`ops/retailer-notes/repair-products.php` provides `preview`, `apply`, and `verify`
modes through `wp eval-file`, taking an absolute root-private JSON plan path.
Preview refuses to overwrite a plan. Review every before/after record and take a
fresh database backup before applying. Apply checks the site and every field
before any write, rechecks each record immediately before saving, and verifies
the persisted result. Keep the plan for scoped recovery; do not restore a whole
production database over new orders.

The migration cleans recognized current title notes, moves only whole known
tester offers also found in saved supplier-title segments, and guards the
one-time title repairs for 47748, 24256 and 51896 against current content.
Unchanged note fields retain their exact stored formatting. Unknown malformed
titles are excluded and counted for review. It does not touch descriptions or
slugs: follow with scoped `slug-preview` / `slug-apply`, then verify every
redirect and idempotence. Descriptive-name repairs preserve current URLs.

### Dev repair verification (2026-10-15, PR #202)

Deployed `6ab7aa6ddd4c4ed78940fde7f759e1aa408f19d9` to dev and took a
901,103,879-byte database backup before applying the reviewed private plan.
Evidence is under `/root/ultralove-retailer-repair-20261015/` on dev:
`before-repairs.sql`, `repairs.json`, `slug-plans.json`, `slug-results.csv`,
and the apply/verification logs. Keep these root-private and outside the webroot.

- Applied and verified all 63 title/note repairs: 34 existing tester offers moved
  to Important, 26 unwrapped/orphan-tag instruction titles cleaned, the trailing
  angle repaired on 47748, and descriptive tester wording restored on 24256 and
  51896. A fresh preview found zero further repairs.
- Reviewed and migrated exactly 27 slugs (the 26 instruction-title repairs plus
  47748), with zero collisions, conflicting redirects or migration problems.
  The 34 reclassifications and two descriptive-name repairs preserved their URLs.
- Independently verified all 27 stored slugs, previous-slug metadata, exact enabled
  301 rules and repeat-planner `done` status. Controls 24533 and 67001 remain
  legitimate `same` records.
- Purged the dev Nginx page cache. Every old path returned its exact 301 target;
  every new path returned 200 with the correct canonical and guest product title.
  All 63 repaired products rendered the expected regular/Important fields.
- Dev indexing remains disabled: `/wp-sitemap.xml` correctly returns 404.
  Verified all 27 new URLs, and absence of the old URLs, directly through the
  WordPress product sitemap provider without enabling indexing.
- Home, shop, account and login smoke paths passed; Nginx, PHP-FPM and Redis
  remained active. Parser tests passed. Offline importer regressions additionally
  cover duplicate removal, emptying regular info, preserving manual notes and
  originals, and repeat-import idempotence; CI runs both test scripts and lints
  the repair script.

### Production repair verification (2026-10-15, PR #202)

After explicit dev approval, merged #202 as
`2d1be24991b3779766a85b1df45df87fcc04f9fe`. Main CI (38807559755) and the
automatic dev deployment (38807559700) passed. Standard production deployment
passed the dev gate, found no drift, linted changed PHP, restarted PHP-FPM and
passed all four smoke paths.

Created a fresh 947,944,008-byte, mode-0600 database backup at
`/root/ultralove-retailer-repair-20261015/before-repairs.sql` on production.
Retain that directory's private before/after plans, CSV and verification logs
for scoped recovery, never a full database restore over subsequent orders.

- All 63 production repair records exactly matched the tested dev before/after
  records. Applied and independently verified all 63; repeat preview proposed zero.
- Reviewed all 27 old/new slug pairs against dev, with no collisions or conflicting
  redirects. `wp ul-retailer-notes slug-apply --ids=... --csv=...` changed 27,
  skipped zero and reported zero problems. Independent verification confirmed
  saved slugs, previous-slug metadata, exact enabled 301 rules and idempotence.
- Purged Nginx page cache and 97 scoped Cloudflare URLs.
- Origin and public HTTP verification passed all 27 exact redirects, new 200
  responses, canonicals and guest titles; all 63 guest retailer fields; all four
  public product sitemaps; and home/shop/account/login smoke paths.
  Public requests from the production host received 403, so public verification
  was completed from the local machine against exported expected values.
  No WAF rules were changed. Nginx, PHP-FPM and Redis remained active.

Product 26625's unsupported multi-line offer remains unchanged for manual review.
Descriptions and description styling were not changed.

### Deferred: retailer instructions in descriptions

After title cleanup, audit both long and short product descriptions for retailer
instructions, including the reported nested markup:

```html
<b><font color="red"><font size="4">LIMIT 1 PER STORE ONLY<br> FREE WITH 2 UNITS BOUGHT</font></font></b>
```

Preview matches before extracting only clearly identified instructions into
retailer fields, preserving surrounding description content and meaningful
tester wording. Include `LIMIT 1 PER STORE ONLY`, nested wrappers and line
breaks in regression coverage; deduplicate against existing notes and ensure
future imports do not reintroduce the instructions. This is a separate,
not-yet-implemented migration requiring dev verification before production.

### Description-format inventory and class proposal (read-only, 2026-10-15)

Audited both raw description fields on all non-trash/non-auto-draft parent
products: all 6,528 were published, with 6,493 nonempty long descriptions and
4,141 nonempty short descriptions. Variations, revisions and encoded HTML
alternatives are outside this inventory. No description writes were performed.
Detailed JSON, bounded examples and the read-only audit script are retained in
the private production repair evidence directory (`description-audit-expanded.json`
and `audit-description-styles.php`).

Found 47 normalized tag/attribute signatures and 85 ancestor structures.
911 products contain `font`; 123 contain inline styles; 2,436 contain bold;
74 contain underlining. Counts below are element occurrences, not independent
callouts, and overlap across nested wrappers and products.

| Formatting family | Observed values and element counts |
| --- | --- |
| Bold / underline | `b`: 5,719; `u`: 80 |
| Red colour | `font[color=red]`: 996 on 885 products; red inline spans: 125 on 95 products |
| Legacy font size | `1`: 3; `2`: 121; `2.5`: 17; `3`: 356; `3.5`: 1; `4`: 298; `4.5`: 146; `5`: 84; `6`: 13; `8`: 2; `10`: 3 |
| Span font size | `small`: 94; `medium`: 9; `large`: 6; `x-large`: 14; `xx-large`: 4 |
| Other named font colours | `black`: 9; `dark`: 3; `fuchsia`: 3; `orange`: 3; `darkturquoise`: 2; `blue`: 2; `hotpink`: 2; `slate`, `fuscia`, `purple`, `turquoise`: 1 each |
| Hex-like font colours | `fe39bc`: 7; `71349c`: 6; `#df0101`, `#5b8dee`, `#ea2181`, `#bae5d2`, `#ff8d33`, `#c9daee`, `#74457b`: 1 each |
| Other inline text colour | `span[style="color: dark;"]`: 2 |
| Layout / media, not callouts | Left-aligned table cells: 54; float-left/padding-12px divs: 50; clear-both divs: 25; three distinct Wistia positioning/sizing div signatures: 1 each |

No additional strong/italic/strike/big/small/center or font-face signatures were
observed by the audit. Decimal sizes and sizes 8/10 are not valid legacy font
sizes; `dark`, `slate`, `fuscia` and hashless hex values need explicit review,
not blind copying into CSS.

Wrapper variations include paragraph-wrapped and bare bold/font chains, nested
size fonts, equivalent colour/size spans, duplicate bold wrappers, list-item
nesting, non-bold notices, and malformed source (`pthe` on product 88021).
The most common structures are `p > b > font[color=red]` (830 occurrences),
its inner size-3 font (276), inner size-4 font (274), and the bare
`b > font[color=red]` (161). These counts overlap, not separate blocks.
DOM parsing produced structural diagnostics on 92 fields; these are review
signals from a repairing parser, not 92 proven independent source defects.

Marilyn Monroe (47748) contains the reported red/size-3 marketing phrase in
`post_excerpt`, not the long description. It must remain product content.
Other distinct uses include marketing headlines, "SMART PHONE NOT INCLUDED"
(23550), missing-charger notices (88021), and product-colour/scent labels
(35223, 35227, 35231, 35235, 35239, 36421 and 97180-97183).

Expanded retailer-phrase discovery flagged 521 fields across 464 products:
463 long and 58 short descriptions. These are candidates, not approved removals.
The initial narrower search found 475 fields on 441 products. Broader discovery
adds minimum-quantity, limit, no-exceptions and limited-supply wording.
Marketing phrases such as "creativity run free with" (54170) and
"worry-free with ingredients" (72413/72415) are false positives.
Conversely, 65257/65265 have genuine terminal store/free-unit instructions inside
long marketing paragraphs, so extracting the entire matched paragraph is unsafe.
Plain-bold instructions also occur; colour alone cannot classify retailer text.

Small semantic system (implemented on the description-callout branch; dev review
required before production):

- `ul-product-callout`: normal-sized, bold accent text for marketing emphasis.
  Marilyn's paragraph would contain one such span, with the nested fonts removed.
- `ul-product-callout--lead`: optional larger introductory headline treatment,
  selected by content role rather than mechanically mapping every old size.
- `ul-product-notice`: product caveats such as excluded accessories, remaining
  in the description and visually distinct from retailer purchase conditions.
- Preserve colour-identity/brand cues for separate review; do not flatten them
  into the accent style or create a class for every historical colour.
- Recognized retailer instructions go to the existing regular/Important fields,
  deduplicated against saved notes, not a new description warning class.

Preserve surrounding paragraphs, line breaks, lists, links, tables, ordinary bold
and media. Any future migration needs reviewed before/after previews, guarded
field writes, regression fixtures for mixed/malformed blocks, importer parity,
and dev visual verification before production. This inventory does not authorize
description or CSS mutation.

#### Product editor examples and guarded pilot

The product edit screen has a **Product description style examples** panel below
the editors, showing escaped HTML and a rendered preview for the callout, lead
callout and product notice. The lead example includes both the base and modifier
classes. Preview and storefront use the same child-theme stylesheet,
`css/product-descriptions.css`; no example is inserted into saved content.
Retailer conditions still belong in the existing retailer-specific fields.

Dev deployed commit `53f7580` on 2026-10-15 (#204). Deployment smoke tests,
the deployed WordPress metabox renderer, product-editor stylesheet enqueue and
product-list exclusion checks passed. Local regression and browser computed-style
checks passed for all three examples. After the user authenticated the dev browser,
the admin examples were also verified against their source snippets and computed
styles. No access settings were changed. The initial code deployment migrated no
descriptions; the subsequent authorized dev migration is recorded below.

The accompanying conservative cleanup recognizes only reviewed standalone
phrases/whole retailer instructions. Mixed paragraphs, unknown red wording,
colour labels, links, layout, media and malformed fields remain for review.
Lead treatment is available to editors but is never inferred from old font sizes.
Importer normalization is disabled until a product is explicitly opted in by the
guarded migration; deploying the code alone changes no description data.

`ops/retailer-notes/clean-descriptions.php` provides `preview`, `apply`, `verify`
and guarded `restore` modes via `wp eval-file`, using a private absolute JSON
plan path outside the webroot and optional comma-separated IDs for preview.
Plans include original fields and notes, site/version, product name and slug.
Review the plan and take a database backup before applying on dev. This phase
does not authorize any production migration.

### Dev description migration and retailer-note placement

- Dev description migration applied to 219 products; verify mode confirmed exact
  descriptions, notes, import opt-in, unchanged titles and unchanged slugs.
- Repeat preview proposed zero changes. The 1,276 flagged fields remain for review.
- A fresh private dev database backup and the complete before/after plan are retained
  under `/var/lib/ultralove-deploy/description-cleanup-20261015/`. A three-product
  apply/verify/restore rehearsal passed before the full apply.
- Storefront retailer notes appear both in their original product-summary position
  and above the main Description tab content, Important retailer info first.
  The original position was restored following user review. Existing card and
  Quick View output and wp-admin fields remain unchanged. Notes-only products
  retain a Description tab so their instructions are not lost.
- Initial dev code `4920da3` deployed successfully. Browser checks confirmed both notes
  appear once, Important first, before the Nova 2 Retail Kit description, with no
  notes remaining above its price. Desktop and 390px mobile checks passed without
  description overflow; logged-out origin HTML confirmed the same placement.
- Follow-up dev code `49f0c99` restored the original summary hook at priority 11,
  while retaining notes above Description. Browser checks on Marilyn Monroe
  confirmed one identical regular note in each location. The theme renders the
  summary notes after its price/status block and before the short description.
- Marilyn Monroe's migrated marketing callout rendered with the shared accent
  style and no legacy red font elements in the main description. Runtime,
  description and title-parser regressions and PHP lint passed, including
  notes-only and notes-free product cases.
- Production description migration remains pending approval. Follow the critical
  CSS release gate before production since the first-screen markup has changed.

### Preserving tester product identities

- Keep tester identities in product names, including Leopard Tester, Tester Only,
  Tester Kit, RETAIL TESTER and display kits marked NO TESTER. Extract only whole
  recognized store/purchase instructions into Important retailer info. Ambiguous
  mixed tester instructions are retained and flagged for review, not auto-applied.
- Quantity instructions such as `1 PER TESTER ONLY` are retailer conditions, not
  tester identities; retain them in Important retailer info. Regression coverage
  includes the Shameless Display Tester Holder case, wrapped and unwrapped notes.
- The importer shares this parser, retains unrelated retailer notes and the saved
  original supplier name, and is repeat-safe.
- `ops/retailer-notes/repair-products.php` supports private `preview`, `apply` and
  `verify` plans via `wp eval-file`. Generic restoration requires a matching old
  note in the saved supplier title and exactly one identity whose removal explains
  the current title. Substantive manual title edits and unmatched notes are skipped.
- Fresh plans snapshot titles, original supplier titles, both retailer fields and
  slugs. Apply preflights every snapshot, rechecks before each write and verifies
  readback. Retain the reviewed plan and take a fresh DB backup before dev writes.
  Existing slugs and redirects must remain unchanged; do not run slug migration.
  Do not create redirects from intermediate cleaned URLs. If a later approved
  repair changes URLs, retarget existing original retailer-info redirects directly
  to the final URL and suppress automatic intermediate redirects.
- Offline coverage: `test-parser.php`, `test-runtime.php`, `test-descriptions.php`
  and `test-repairs.php` under `ops/retailer-notes/`. The repair suite covers exact
  restoration, unrelated notes, manual-edit/provenance guards, stale slug rejection
  before any writes, readback and repeat safety; it also runs in CI.
- Tester restoration is a dev-only follow-up. Production remains unchanged pending
  explicit approval.
- Dev execution verified on 2026-10-15 at `ca4113b52e`:
  - Reviewed the fresh 507-product plan; zero flags. The earlier 508-product preview
    was superseded, not applied: product 29768's `1 PER TESTER ONLY` is a quantity
    instruction and its title/notes were verified unchanged.
  - Fresh private backup:
    `/var/lib/ultralove-deploy/tester-identities-ca4113b/before-complete.sql.gz`
    (125,312,432 bytes, mode 600, gzip integrity checked). Export used
    `--single-transaction --set-gtid-purged=OFF`. SHA-256:
    `6fe9a5f66259f01ed7b01b1e9e3d3f574256550e4989c9be1d37749bc972b4ca`.
  - Applied and readback-verified all 507 title/Important-note repairs against
    `/var/lib/ultralove-deploy/tester-identities-ca4113b/plan.json`; saved originals,
    regular retailer notes and slugs stayed unchanged. Repeat preview returned
    zero repairs and zero flags.
  - Before/after fingerprints matched for all 6,464 product slugs, all 6,516
    Redirection rule definitions and all 2,391 previous/core old-slug metadata rows.
    No redirects were created or retargeted, and no intermediate aliases appeared.
  - Verified guest origin PDPs 96703 (Leopard Tester), 96694 (Tester), 27513
    (RETAIL TESTER), 51885 (Tester Only), 51887 (NO TESTER display kit) and 29768
    (Tester Holder). Titles and canonicals matched saved data; retailer notes
    appeared identically once in the original summary position and once in
    Description. Authenticated browser also verified Liberty at its existing URL.
    Its original retailer-info URL still returned one 301 directly to that URL.
  - Deployment smoke passed; Nginx, PHP-FPM and Redis were active. No production
    writes or deployment. Returning tester wording to URLs is outside this repair;
    any later approved URL work must follow the direct-retarget policy above.

### Retailer-condition consistency follow-up (dev first)

- Store-limit synonyms, including `ONE PER STORE ONLY` and `LIMIT 1 PER STORE
  ONLY`, consolidate into **Important retailer info** as `LIMIT 1 PER STORE ONLY`.
  Remove the equivalent condition from regular Retailer info. Keep separate
  purchase requirements on their own lines; never merge different quantities,
  scopes, product qualifiers or conflicting purchase offers.
- Shared normalization applies to admin saves, title imports (including already
  clean titles), and opted-in description cleanup. Unknown prose remains in its
  original field. Ambiguous conditions are reported; bulk normalization excludes
  the entire affected product for manual review.
- `ops/retailer-notes/normalize-notes.php` provides preview/apply/verify/restore
  through `wp eval-file`. Plans must be new private absolute paths outside the
  webroot, bound to this site and a catalog/redirect/provenance fingerprint.
  Optional preview scope is comma-separated positive product IDs. The runner
  writes only `_ul_retailer_info` and `_ul_retailer_important`; it preserves
  absent versus empty metadata for exact restoration and supports recovery
  after a partial apply. Review the full plan and take a database backup first.
- Run final note normalization **after** historical tester-identity repairs:
  those repairs intentionally match raw source notes for provenance. Do not
  reuse older description or URL plans after normalization.
- Offline regressions cover the Liberty/Backwall conditions, cross-field
  promotion/deduplication, admin partial submissions and slashes, clean-title
  imports, ambiguous/conflicting offers, stale-state rejection, failed-write
  recovery, exact metadata restoration and repeat safety.
- No production changes in this follow-up. Current-name URL work is still
  paused; no additional redirects or aliases have been written.
