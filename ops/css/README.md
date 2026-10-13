# Trimmed stylesheets

## Product-card thumbnail display size

The child theme caps grid and carousel thumbnail images at 200 CSS pixels, centered inside
their existing cards. Smaller images remain fluid; card widths, column counts, list view,
single-product galleries and banners are unchanged. The 200/400px sources cover the capped
image at 1x/2x density. Native lazy `sizes="auto, ..."` remains enabled, and the fallback
hints in `30-woocommerce-loop-image-sizes.php` and `38-product-images-cloudflare.php` must
stay aligned with the cap. Mobile fallback hints must not exceed 200px either.

Validate actual rendered widths and fresh-session image selection at 1x/2x, including
shop grids and home/product/footer carousels. Regenerate critical CSS after deployment
to dev and before a production release; no trimmed-parent CSS regeneration is needed
for this child-theme-only rule.

## Trimmed copies

The site's biggest stylesheets are mostly styles it never uses, so the child theme serves copies trimmed to
what the site's pages and scripts use, under the same handles (`ultralove_use_trimmed_styles()` in the child
`functions.php`; inline styles attached to a handle, like Martfury's header styles, stay attached):

| Handle | Original | Trimmed copy (`wp-content/themes/martfury-child/css/`) |
|---|---|---|
| `martfury` | parent theme `style.css`, 660 KB | `martfury-trimmed.css`, ~243 KB |
| `ionicons` | parent theme `css/ionicons.min.css`, 50 KB | `ionicons-trimmed.css`, ~1 KB |
| `eleganticons` | parent theme `css/eleganticons.min.css`, 21 KB | `eleganticons-trimmed.css`, ~1 KB |
| `font-awesome` | parent theme `css/font-awesome.min.css`, 30 KB | `font-awesome-trimmed.css`, ~1 KB |
| `woocommerce-general` | WooCommerce `assets/css/woocommerce.css`, 86 KB | `woocommerce-<version>-trimmed.css`, ~48 KB |

The icon fonts' copies keep only the icons the site shows; their font files still come from the parent theme.
WooCommerce isn't in git: its CSS is read from dev, and the copy's name carries the WooCommerce version it was
made from. It's only used while that version is installed, so after a WooCommerce update the original is
served until this is rerun (and the build replaces the old-version copy).

**Never edit the trimmed files by hand.** Regenerate them after a Martfury or WooCommerce update, or when the
site gains a page type or feature the samples don't cover (a new template, plugin or widget), since a style
used only there would otherwise be missing.

## Regenerating

Needs SSH access to `ultralove-dev-app`, Node, Python 3 with Pillow, and Google Chrome.

```bash
cd ops/css
npm install
./prepare.sh          # pages from dev (logged out + in, desktop + mobile, AJAX fragments), their scripts,
                      # and each page's DOM after scripts run -> work/
node build.mjs        # purge each stylesheet against work/ -> the child theme's css/*-trimmed.css
python3 compare.py    # render pages with the original vs trimmed stylesheets, diff the screenshots
```

`compare.py` prints the share of differing pixels per page and writes side-by-side crops of the
differing area to `work/cmp/diff-*.png`. Differences that change sides or size between runs are
screenshot timing (images and sliders still loading), not CSS; rerun to check. Add pages a change
affects to `PAGES` in `collect-html.sh` and to `pages` in `compare.py`.

What keeps a selector: it matches a class, id or tag in the collected HTML, in a page's DOM after its
scripts run, or a word in any loaded script; or it matches the `safelist` in `build.mjs` (state classes
scripts toggle, and script-driven widgets: Slick, FlexSlider, PhotoSwipe, prettyPhoto, jQuery UI,
tooltips, Select2, Gravity Forms, YITH, blockUI, WooCommerce notices). All `@font-face`, `@keyframes` and
custom properties are kept. `work/rejected-<handle>.txt` lists every removed selector. Pages are collected with `?ao_noptimize=1`, so they
list their own stylesheets rather than Autoptimize's combined file.
