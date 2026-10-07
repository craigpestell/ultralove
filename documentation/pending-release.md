# Pending production release

## Dev-only dropshipping pilot (not a production release)

`ultralove-dropshipping` adds approved-retailer manual dropship checkout.
Its option defaults off and its checkout gate only runs in development.
Plugin activation, `ultralove_dropshipping_enabled=yes`, and individual user
approvals are dev-only configuration steps, not production release instructions.
See [the pilot runbook](dropshipping-pilot.md) for packing, verification, and
rollback. Do not ship or enable the pilot on production without a separate review.

Everything below is on **dev.ultralove.ca** and waits for the client's approval, then ships to
production as one release. Code goes through `deploy/deploy.sh` (see the `ultralove-deploy` skill);
the database / plugin steps are **not code** and have to be repeated on the production server by hand,
in the order listed.

## Code

Nothing pending: production runs `main` (`84ac3c8`, deployed 2026-10-02 04:58 UTC) on the new production droplet
(Ubuntu 26.04, PHP 8.5 - see `documentation/ARCHITECTURE.md` 1.3). After a deploy that changes page HTML, purge
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
