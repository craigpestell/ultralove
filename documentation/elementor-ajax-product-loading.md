# Elementor AJAX Product Loading

## Background

Martfury Addons Elementor product widgets (grids, carousels, tabs) support a
**Lazy Loading** setting that defers product HTML until the widget scrolls into
view, fetching it via `?wc-ajax=mf_elementor_get_elements`.

This was disabled in the child theme to force server-side rendering (SSR) on
the homepage, improving initial page load and crawlability.

The override lives in:
`wp-content/themes/martfury-child/functions.php` — function `ultralove_force_elementor_product_widgets_ssr`

Affected widget types:
- `martfury-products-grid`
- `martfury-products-carousel`
- `martfury-product-tabs-grid`
- `martfury-product-tabs-carousel`
- `martfury-products-of-category`
- `martfury-products-of-category-2`

---

## Re-enabling AJAX Loading

### Option A — Re-enable globally (all pages)

1. Open `wp-content/themes/martfury-child/functions.php`
2. Comment out the hook on the line that reads:
   ```php
   add_action( 'elementor/widget/before_render_content', 'ultralove_force_elementor_product_widgets_ssr', 5 );
   ```
   Change it to:
   ```php
   // add_action( 'elementor/widget/before_render_content', 'ultralove_force_elementor_product_widgets_ssr', 5 );
   ```
3. Clear any page/object cache (WP Rocket, Redis, Nginx FastCGI, etc.)
4. Restart PHP-FPM if needed:
   ```bash
   systemctl restart php8.3-fpm.service
   ```
5. Verify on the homepage — `?wc-ajax=mf_elementor_get_elements` requests should
   reappear in the browser network tab as widgets scroll into view.

---

### Option B — Re-enable for specific pages only

Keep the hook active but narrow the condition so SSR is only enforced where
needed. Edit the `if` check inside `ultralove_force_elementor_product_widgets_ssr`:

```php
// Current: enforces SSR only on the homepage
if ( is_admin() || ! is_front_page() ) {
    return;
}

// Example: enforce SSR only on page ID 123, allow AJAX everywhere else
if ( is_admin() || ! is_page( 123 ) ) {
    return;
}
```

To find a page's ID, open it in the WordPress admin editor and read the `post=`
value from the URL.

---

### Option C — Per-widget via Elementor editor

Without any code changes, each individual widget can control its own behaviour:

1. Edit the page in Elementor.
2. Click the product widget.
3. Go to **Style** tab → **Lazy Loading** → toggle **off**.
4. Save and publish.

> Note: this only works when the child-theme override (Option A/B) is **removed
> or narrowed**, because the override forces `lazy_loading = no` at runtime
> regardless of the Elementor editor setting.
