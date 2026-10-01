# Pending production release

Everything below is on **dev.ultralove.ca** and waits for the client's approval, then ships to
production as one release. Code goes through `deploy/deploy.sh` (see the `ultralove-deploy` skill);
the database / plugin steps are **not code** and have to be repeated on the production server by hand,
in the order listed.

## Code

Production runs `d8f500a` (critical CSS, 2026-10-01). On `main` since then, deployed with the next
`deploy/deploy.sh prod`: critical CSS for logged-out visitors only (#30), the trimmed icon-font / WooCommerce
stylesheets and Gravity Forms' CSS loading with its form (#31), the Turnstile add-on's scripts held back with
Gravity Forms' (and loaded in the order it needs), and the font preloads. No database step for those.

## Production steps

### Cloudflare Turnstile instead of reCAPTCHA v2 (all three forms)

On dev since 2026-10-01: the footer form (1), Registration (2) and Contact Us (3) use Turnstile. Do these
after the code deploy above (it holds the add-on's scripts back correctly; without it the widget doesn't
render). Run on the production app server from `/var/www/html`, as `sudo -u www-data wp ...`.

1. **Keys** (the site owner, in the Cloudflare dashboard > Turnstile): add `ultralove.ca` to the widget dev
   uses, or create a widget for production. Keep the site key and secret key at hand.
2. **Install and activate the add-on** (Gravity Forms licence; same version as dev, 1.5.0): wp-admin >
   Forms > Add-Ons > Cloudflare Turnstile (`wp gf install` would need Gravity Forms' CLI add-on, which isn't
   installed).
3. **Enter the keys**: Forms > Settings > Turnstile - site key, secret key; theme and widget size as on dev.
4. **Back up the forms, then switch the reCAPTCHA fields** (copy `ops/gravityforms/switch-recaptcha-to-turnstile.php`
   to the server first):
   ```bash
   wp eval 'echo wp_json_encode( GFAPI::get_forms() );' > /root/gf-forms-before-turnstile-$(date +%Y%m%d%H%M%S).json
   wp eval-file /tmp/switch-recaptcha-to-turnstile.php dry   # lists the fields it will change
   wp eval-file /tmp/switch-recaptcha-to-turnstile.php
   wp cache flush
   ```
5. **Check**: the widget shows on Contact Us, Become a Retailer and the footer form, and a test message
   from Contact Us arrives.

Rollback: restore the forms from the backup (`GFAPI::update_form()` for each form in the JSON) and deactivate
the add-on; the reCAPTCHA v2 keys stay in Forms > Settings > reCAPTCHA until removed.

## Already on production (not part of a release)

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
