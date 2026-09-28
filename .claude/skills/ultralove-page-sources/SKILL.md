---
name: ultralove-page-sources
description: Map of where everything on an Ultralove (ultralove.ca / dev.ultralove.ca) page comes from - parent/child theme templates and hooks, Elementor page data, plugins and MU plugins, widgets, menus, theme options, database content, AJAX-loaded blocks and caches - and in detail where the product cards ("product thumbnails") in grids and carousels are generated, styled and scripted. Use before changing any text, layout or styling on the site, when asked "where does X come from", when a change doesn't show up, or whenever product cards, product grids or product carousels are involved.
---

# Where Ultralove page content comes from

The site is WordPress + WooCommerce on the **Martfury** theme with a **martfury-child** child theme.
**Elementor is deactivated** (dev now; production at the next release): the home page is the child
theme's `front-page.php`, other pages are plain page content (shortcodes / HTML). Code the repo owns (see `deploy/owned-paths.rules`): both themes,
`wp-content/mu-plugins/`, `plugins/hide-price-until-login`, `plugins/ultralove-*`. Everything else -
vendor plugins (martfury-addons, Elementor, YITH, ...), uploads and **all database content** - lives only
on the servers. Deploy code with the `ultralove-deploy` skill; database content is edited per server.

Rule of thumb: change behaviour in the **child theme** (hooks in `functions.php`, CSS in `style.css`,
scripts in `js/`), never in vendor plugins (not in git, overwritten on update). The parent theme is in
git but treat it as vendor too - override it from the child.

## Finding the source of something on a page

1. `git grep` the exact text / class in `wp-content/`. Hits in `martfury-child` win over `martfury`.
2. No hit - it's database content. Search on the server (single-quoted SQL strings: **prod MySQL runs
   ANSI_QUOTES**, so `"..."` is a column name there and the query silently matches nothing):
   ```bash
   ssh ultralove-dev-app 'cd /var/www/html && sudo -u www-data wp db query "SELECT post_id, meta_key FROM wp_postmeta WHERE meta_value LIKE '\''%text%'\''"'
   ```
   Check `wp_posts.post_content`, `wp_postmeta` (Elementor's `_elementor_data`), `wp_options`
   (widgets, theme options, plugin settings such as YITH labels), `wp_term_taxonomy.description`.
3. Still nothing - the element is added in the browser: an AJAX-loaded block (below), a plugin script, or
   a `gettext` replacement (`ultralove_custom_text_replace()` in the child `functions.php` rewrites
   translated strings).
4. Look at the page's `<body>` classes: they say which template/layout ran (`mf-catalog-page`,
   `sidebar-content`, `shop-view-grid`/`shop-view-list`, `single-product-layout-1`,
   `page-id-14` = Home, `elementor-page`, `logged-in`).

## Content sources

| Source | What it holds | Where / how to change |
|---|---|---|
| Parent theme `wp-content/themes/martfury` | Page templates, header/footer, WooCommerce layout, product card markup (`inc/frontend/woocommerce.php`), theme CSS/JS (`style.css`, `js/scripts.js`) | Override from the child; don't edit |
| Child theme `wp-content/themes/martfury-child` | `front-page.php` (the whole home page: banner, nav row, `#topcta` call to action - its text is edited here - "On Sale" carousel, "Latest Adult Products", links), `functions.php` hooks/filters and shortcodes (`[ultralove_brand_grid]` for Brands We Carry), `style.css` (all site styling on top of the parent), `js/`, `footer.php` (`#hero-footer-combo`), `template-parts/headers`, `template-parts/footers`, `woocommerce/single-product/related.php` + `up-sells.php` (carousel markup), `woocommerce/emails` | Git + deploy |
| Page content (`post_content`) | Brands We Carry = `[ultralove_brand_grid]`; Sale Products = a `[products on_sale="true"]` shortcode; Contact Us (text + Gravity Form 3), Buy Wholesale (Gravity Form 2), My account / Cart / Checkout (WooCommerce shortcodes) | Page editor or `wp post update` |
| Old Elementor data | `_elementor_data` post meta is still in the database (Home = post 14 with its revisions) but unused while Elementor is off | Leave it; don't edit |
| martfury-addons (vendor plugin) | Martfury shortcodes and (unused now) Elementor widgets | Not in git. Override from the child theme |
| Theme options (Kirki) | Header/footer layouts, footer recently viewed settings, catalog layout, product loop hover style, logos | Customizer; read in code via `martfury_get_option()` (the child filters some) |
| Widgets | Footer widget text cards, sidebar filters (`widget_martfury_layered_nav`, ...), Gravity Forms | Appearance > Widgets (`widget_*` options) |
| Menus | Main navigation (Shop, On Sale, Browse by Brand, Buy Wholesale, Contact) | Appearance > Menus |
| ultralove-banners (repo plugin) | Home/header rotating banners and the aurora backdrop behind banner + `#topcta` (`#ub-hero-combo-bg`, `banner.css`) | Git; banner content in its settings (`ub_*` options; `ub_homepage_backup*` are backups) |
| Product data | Titles (SKU / name / promo note joined with `<br>`, e.g. "DISCOUNTS DO NOT APPLY"), prices, stock, images, categories, brands (`product_brand`) | WooCommerce admin / WP All Import |
| Other plugins shaping output | YITH Wishlist ("Add to favourites" labels are YITH settings), Advanced Woo Labels (labels on product images), Hide Price Until Login, Quantity Field on Shop Page, Back In Stock Notifier ("Notify me"), Relevanssi (search results), Gravity Forms / Contact Form 7, Autoptimize (CSS/JS aggregation), Redirection | Their settings screens |
| MU plugins `wp-content/mu-plugins` | Site-wide fixes, e.g. `30-woocommerce-loop-image-sizes.php` (product image `sizes`), `05-shop-cache-ttl.php` (4-hour cache on `/shop/`), `01-yith-wishlist-lookup-cache.php`, `martfury-mobile-detect-override.php` | Git + deploy |
| Image URLs | Rewritten to Cloudinary by `ultralove_use_cloudinary_urls()` / `ultralove_use_cloudinary_url()` in the child `functions.php` | Git |

### Loaded after the page (AJAX / scripts)

- **Footer "Recently viewed"**: Martfury requests `wc_ajax_martfury_footer_recently_viewed` when the block
  scrolls into view; the child replaces the handler (`ultralove_martfury_footer_recently_viewed()` ->
  `ultralove_render_recently_viewed_cards()`), the viewed IDs come from the browser
  (`js/recently-viewed.js`, localStorage `ultralove_recently_viewed`).
- **Wishlist hearts**: YITH renders empty `.wishlist-fragment` placeholders and fills them in one request at
  page load; content added later must trigger `yith_wcwl_reload_fragments` (see `js/recently-viewed.js`).

## Product cards ("product thumbnails")

### Markup

Every card is a WooCommerce loop item (`li.product`) from the parent theme's
`woocommerce/content-product.php` (list view: `content-product-list.php`), filled in by Martfury's hooks in
`wp-content/themes/martfury/inc/frontend/woocommerce.php` (class `Martfury_WooCommerce`):

- `open_product_inner()` / `close_product_inner()` - `.product-inner` (the card box)
- `product_content_thumbnail()` - `.mf-product-thumbnail` with the image link; the
  `martfury_after_product_loop_thumbnail_link` hook adds the Quick View / wishlist bar
  (`.footer-button`, slides up on hover) - which icons depend on the `product_loop_hover` / featured icons
  theme options (`product_loop_hover()`)
- `open_product_details()` / `open_product_content()` / `products_title()` (`h2.woo-loop-product__title`)
- `open_product_price_box()` - `.mf-product-price-box` holding price and the cart row
- `product_ribbons()` - sale/new ribbons; Advanced Woo Labels adds its own

Child theme changes (`functions.php`): `woocommerce_template_loop_add_to_cart()` is replaced - quantity +
Add to cart row, a disabled `form.cart.ul-out-of-stock` row for out-of-stock items, and the Back In Stock
Notifier button as `.ul-notify-row` (`ultralove_capture_instock_notifier_catalog_button()`). Hide Price
Until Login hides price and cart row for logged-out visitors (`.footer-button` inside the price box is
`display:none`) - measure logged-out pages with that row forced visible.

### Where cards appear

| List | Page | Rendered by | Container |
|---|---|---|---|
| Catalog grid | Shop, categories (incl. On Sale = `sale-closeout-items`), brand filter, search | WooCommerce archive template, `div.mf-shop-content > ul.products` | Beside the filter sidebar (`.catalog-sidebar`, `col-md-3`) from 992px |
| Home "On Sale" | Home | `front-page.php`: `[products on_sale="true" limit="12" orderby="rand"]` in `.ul-carousel` | `.ul-home-container` (1150 / 950 / 700px) |
| Home "Latest Adult Products" | Home | `front-page.php`: `[products limit="30" orderby="date"]` | `.ul-home-container` |
| Related / upsells | Product pages | Child `woocommerce/single-product/related.php`, `up-sells.php` | `.ul-carousel` |
| Recently viewed | Footer of most pages | `ultralove_render_recently_viewed_cards()` (AJAX) | `.ul-carousel` |
| Cross-sells | Cart (items in cart) | WooCommerce | grid |

### Behaviour (child `js/`)

- `product-card-link.js` - the whole card clicks through to the product (one delegated listener; ignores
  the card's own controls)
- `product-carousel.js` - the shared carousel (`.ul-carousel[data-ul-carousel]`): round prev/next buttons
  that wrap round, progress bar, drag to scroll, keyboard; `window.ulProductCarousel.init()/initAll()`
- `recently-viewed.js` - footer recently viewed IDs, carousel start, wishlist fragments, quantity sync
- Parent `js/scripts.js` - Quick View (`.mf-product-quick-view`, delegated on body); YITH handles
  `.add_to_wishlist` (delegated on document)

### Styling (child `style.css` - search for these section comments)

- **"Product grids and carousels - one sizing rule everywhere"** - columns: the element holding each
  `ul.products` is a size container; cards per row = as many 215px+ cards as fit, min 2 (`--ul-cols`,
  steps at 645/860/1075/1290px of container width). Grids and carousels share it - **don't add
  window-width column breakpoints**; change the 215px steps instead. 215px is what the cart row needs
  ("Out of stock" ~214px, "Add to cart" ~204px).
- **"Product grid cards: consistent height + non-wrapping cart row"** - flex cards, fixed title box,
  cart row pinned to the bottom
- Card corners + hover glow (`--ul-purple-shadow`, defined on `:root` with the shared
  `.ul-purple-shadow` rule), transparent hover border
- **"Quick view / wishlist on the thumbnail"** - the slide-up bar
- **"Product carousel - ..."** (`.ul-carousel`) - scroller, arrows (`.ul-carousel__arrow`), progress bar
- **"Buttons"** - one button system (`ul-btn`, `--primary/--secondary/--tertiary`, `--icon`); every rule
  is `:is(#page, #mf-quick-view-modal) :where(...)`, so beating it needs that id weight
- Home page sections: `.ul-home-*` (`.ul-home-bleed` full width, `.ul-home-container` the content
  edges shared with `#bottomcta` and the footer); product section headings `.cat-header`
- The parent theme's own card CSS is in `martfury/style.css` (`.woocommerce ul.products li.product ...`)

Images: `sizes` from `mu-plugins/30-woocommerce-loop-image-sizes.php` (keep in step with the card widths
above), URLs via Cloudinary, native `loading="lazy"`.

## Caches - when a change doesn't show

- **Redis object cache**: after direct SQL edits, `wp cache delete <post_id> post_meta` (or `wp cache flush`).
- **Cloudflare** caches HTML for some URLs (the home page showed `cf-cache-status: HIT`, days old) and static
  assets for a year. CSS/JS URLs carry `?ver=<file mtime>` (deploys set mtimes), so a stale HTML page keeps
  pointing at an old stylesheet. Purge the page URL in the Cloudflare dashboard (by URL, with the
  person's OK); don't use the API token.
- **`/shop/` sends a 4-hour Cache-Control** (`05-shop-cache-ttl.php`) - browsers keep the old shop HTML.
- Check with a query string (`?v=2`); avoid `?m=...` - WordPress reads `m` as a date archive and shows
  "No products were found".

## Gotchas

- Testing in Chrome: a background tab/window throttles timers, `requestAnimationFrame` and smooth
  scrolling; hidden iframes don't fire in-viewport lazy loads. Measure synchronously, trigger AJAX
  blocks directly, and keep browser use minimal.
- Database/plugin changes (page content, plugin activation) are per server - list them in
  `documentation/pending-release.md` so the production release repeats them.
- Most visual work is previewed on dev first; production changes wait for client approval.
