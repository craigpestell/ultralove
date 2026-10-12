# Pending production release

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
