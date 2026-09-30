# Trimmed Martfury stylesheet

The parent theme's `style.css` is 660 KB (74 KB gzipped) and render-blocking, and ~96% of it styles
layouts, widgets and marketplace integrations this site doesn't use. The child theme serves a copy
trimmed to what the site's pages and scripts use instead:
`wp-content/themes/martfury-child/css/martfury-trimmed.css` (~240 KB, ~34 KB gzipped), swapped in
under the same `martfury` handle in the child `functions.php` (Martfury's inline header styles stay
attached to it).

**Never edit the trimmed file by hand.** Regenerate it after a Martfury update, or when the site gains a
page type or feature the samples don't cover (a new template, plugin or widget), since a style used only
there would otherwise be missing.

## Regenerating

Needs SSH access to `ultralove-dev-app`, Node, Python 3 with Pillow, and Google Chrome.

```bash
cd ops/css
npm install
./prepare.sh          # pages from dev (logged out + in, desktop + mobile, AJAX fragments), their scripts,
                      # and each page's DOM after scripts run -> work/
node build.mjs        # purge style.css against work/ -> the child theme's css/martfury-trimmed.css
python3 compare.py    # render pages with the original vs trimmed CSS, diff the screenshots
```

`compare.py` prints the share of differing pixels per page and writes side-by-side crops of the
differing area to `work/cmp/diff-*.png`. Differences that change sides or size between runs are
screenshot timing (images and sliders still loading), not CSS; rerun to check. Add pages a change
affects to `PAGES` in `collect-html.sh` and to `pages` in `compare.py`.

What keeps a selector: it matches a class, id or tag in the collected HTML, in a page's DOM after its
scripts run, or a word in any loaded script; or it matches the `safelist` in `build.mjs` (state classes
scripts toggle, and script-driven widgets: Slick, FlexSlider, PhotoSwipe, prettyPhoto, jQuery UI,
tooltips, Select2, Gravity Forms, YITH, blockUI, WooCommerce notices). All `@font-face`, `@keyframes` and
custom properties are kept. `work/rejected.txt` lists every removed selector.
