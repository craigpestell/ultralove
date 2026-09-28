# Pending production release

Everything below is on **dev.ultralove.ca** and waits for the client's approval, then ships to
production as one release. Code goes through `deploy/deploy.sh` (see the `ultralove-deploy` skill);
the database / plugin steps are **not code** and have to be repeated on the production server by hand,
in the order listed.

## Code

Branch `preview/glow-on-card-hover` (the product card / carousel work, the container-based product
columns, the account and Buy Wholesale page styling, the home page as the child theme's `front-page.php`,
the `[ultralove_brand_grid]` shortcode, the `ultralove-page-sources` skill). Merge to `main` via PR, let
the dev auto-deploy run, then deploy prod from `main`.

## Production steps after the code deploy

Run on the production app server from `/var/www/html`, as `sudo -u www-data wp ...`.

1. **Brands We Carry page (post 16575)** - replace its content (a frozen HTML copy of the old Elementor
   logo grid) with the shortcode. Back the old content up first:
   ```bash
   wp post get 16575 --field=post_content > /root/brands-page-content-before-shortcode-$(date +%Y%m%d%H%M%S).html
   wp post update 16575 --post_content='[ultralove_brand_grid]'
   ```
2. **Contact Us page (post 70)** - its form (Gravity Forms #3) came from an Elementor widget; add it to
   the page content, after the text:
   ```bash
   wp post get 70 --field=post_content > /root/contact-page-content-before-form-$(date +%Y%m%d%H%M%S).html
   { wp post get 70 --field=post_content; printf '\n\n[gravityform id="3" title="false" description="false"]\n'; } > /tmp/contact.html
   wp post update 70 /tmp/contact.html
   ```
3. **Deactivate Elementor** - nothing renders with it any more (home = `front-page.php`, the other
   former Elementor pages are plain page content):
   ```bash
   wp plugin deactivate elementor
   ```
4. **Flush the object cache**: `wp cache flush`
5. **Check** `/`, `/brands/` (73 brands with names), `/contact-us/` (the contact form), `/sale-products/`, `/shop/`,
   `/my-account/`, `/become-a-retailer/`, a product page: 200, no PHP errors.
6. **Cloudflare**: purge `https://ultralove.ca/`, `https://ultralove.ca/brands/` and `https://ultralove.ca/contact-us/` (cached HTML), in the
   dashboard (Caching > Configuration > Custom Purge).

Rollback: reactivate Elementor (`wp plugin activate elementor`), restore the Brands and Contact Us content from the
backup files, redeploy the previous code.

## Already on production (not part of the release)

- Home page call to action: "UltraLove" -> "Ultra Love" (Elementor data of post 14, 2026-09-28). The
  same text is now in `front-page.php`.
