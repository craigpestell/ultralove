# Pending production release

Everything below is on **dev.ultralove.ca** and waits for the client's approval, then ships to
production as one release. Code goes through `deploy/deploy.sh` (see the `ultralove-deploy` skill);
the database / plugin steps are **not code** and have to be repeated on the production server by hand,
in the order listed.

## Code

Nothing pending: production runs `main` (`45e8b69`, deployed 2026-10-01 20:12 UTC - phone product cards, the
new header with the search on its own row, cart page layout below 992px, regenerated critical CSS). After a
deploy that changes page HTML, purge Cloudflare (Caching > Purge Everything, or the changed URLs): APO serves
cached HTML (`cf-cache-status: HIT`) until then.

## Production steps after the code deploy

**Phones: one responsive design instead of Martfury's mobile version** - on dev since 2026-10-01, waiting for
the go-ahead. Until then production still sends phones Martfury's separate mobile markup (user-agent detected),
so the new phone header, product cards and product page don't show on real phones there.

1. `wp theme mod set enable_mobile_version 0` (rollback: set it back to `1`).
2. Cloudflare > APO: turn off "Cache by device type" (one cached copy per page again).
3. Purge Cloudflare (Purge Everything).

**Footer "About Ultra Love" link label** (PageSpeed SEO "Links do not have descriptive text") - done on dev
2026-10-01. The footer text widget `widget_text` #2 ends with a `footlink` button to `/about` reading "Learn
More"; change it to "More About Ultra Love" (Appearance > Widgets, or a `wp eval-file` that backs up
`widget_text` first and replaces `<a class="footlink" href="/about">Learn More</a>`). Rollback: change the label
back. Then purge Cloudflare (the footer is on every page).

## Already on production (not part of a release)

- Martfury's phone-version fixed Add to cart bar off (2026-10-01, dev and prod): theme mod
  `product_add_to_cart_fixed_mobile` = 0 (`wp theme mod set product_add_to_cart_fixed_mobile 0`; unset it to
  restore Martfury's default, on). The child theme's add-to-cart button rules pinned the bar to the top-left over
  the header instead of along the bottom; the phone version goes away with the responsive switch anyway.
- Cloudflare Turnstile instead of reCAPTCHA v2 on all three forms (footer form 1, Registration 2, Contact Us 3),
  2026-10-01: Gravity Forms Turnstile add-on 1.5.0 with a production widget for `ultralove.ca`, fields switched
  by `ops/gravityforms/switch-recaptcha-to-turnstile.php`. Backup of the forms before:
  `/root/gf-forms-before-turnstile-20261001022554.json`. Rollback: restore each form from it with
  `GFAPI::update_form()` and deactivate the add-on (the reCAPTCHA v2 keys are still in Forms > Settings).

Release of 2026-09-29 / 2026-09-30 (code: product cards and carousels, container-based product columns,
account and Buy Wholesale styling, home page as `front-page.php`, `[ultralove_brand_grid]`, trimmed Martfury
CSS, deferred jQuery, trimmed stylesheets, header search fix). Backups of the replaced content are in `/root`
on the production server.

- Brands We Carry page (post 16575) content is `[ultralove_brand_grid]` (2026-09-29).
- Contact Us page (post 70) has the Gravity Forms #3 shortcode after its text (2026-09-29).
- Elementor removed; nothing renders with it (2026-09-29). Its `_elementor_data` post meta is still in the
  database, unused.
- UltraLove Backdrops active.
- Autoptimize combines CSS, inline CSS included: `autoptimize_css_aggregate` and
  `autoptimize_css_include_inline` = `on` (2026-09-30). Rollback: set both to `''`, `wp autoptimize clear`.
- Home page call to action: "UltraLove" -> "Ultra Love" (2026-09-28); the text is in `front-page.php`.
