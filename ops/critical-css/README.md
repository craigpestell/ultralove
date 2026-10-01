# Critical CSS

Every page Autoptimize optimizes inlines the CSS its first screen needs (`<style id="aoatfcss">`) and loads the
full combined stylesheet without blocking rendering (fetched as `media="print"`, switched to `all` when it
has loaded; a `<noscript>` link covers browsers without JavaScript). The inlined CSS is
`wp-content/themes/martfury-child/css/critical.css`, hooked into Autoptimize by
`wp-content/mu-plugins/34-critical-css.php`. With no file (or an empty one) pages load CSS as before.
Cart, checkout and my account aren't optimized by Autoptimize and keep their normal stylesheets.

**Never edit `critical.css` by hand.** It's generated from dev and kept current automatically.

## How it stays current

The **Critical CSS** GitHub workflow (`.github/workflows/critical-css.yml`) runs after every deploy of `main`
to dev, every Monday (for CSS that changes outside git: plugin updates, Customizer settings) and by hand
from the Actions tab. When the result differs from the committed file it opens or updates the PR
"Regenerate critical CSS" (branch `auto/critical-css`); merging it ships the file like any other change.
Nothing is installed or run on production.

Before merging such a PR, run `ops/dev/parity.sh`: the CSS is only as right as dev's copy of the site.

A stale file isn't broken, only less complete: an element whose rules it lacks shows briefly unstyled until
the full stylesheet (always loaded) arrives.

## What the generator does

`generate.mjs`, run in the workflow or locally (needs SSH access to `ultralove-dev-app`, Node and Chrome):

1. Opens an SSH tunnel to dev's nginx and loads real dev pages in headless Chrome, answering every
   `dev.ultralove.ca` request through it (dev sits behind Cloudflare Access). Pages are requested with
   `?ul_critical_css=off`, i.e. as they render without critical CSS.
2. Each page type in `PAGES` as a logged-out visitor (signed-in visitors get the full stylesheet up front), at desktop, wide, tablet and phone
   sizes (phones and tablets with a mobile user agent: Martfury sends them different markup), with JavaScript
   off (the first paint) and on (classes scripts add): keeps every rule that matches an element drawn in the
   first screen, and the rule that hides a hidden element there (not rules for what's inside it). Rules whose
   `@media` doesn't apply at that size are skipped; `:hover`-style states and `::before`/`::after` count as
   their element.
3. Merges the kept rules in the stylesheet's own order (the cascade stays the same), keeping only the
   selectors that matched, adds the `@font-face` (except fonts embedded as `data:` URIs) and `@keyframes`
   they use, makes asset URLs root-relative, and minifies without reordering.
4. Verifies: renders every page/size combination with only the critical CSS and with the full CSS
   (JavaScript off, animations frozen, the footer backdrop's glows off - their positions follow the footer's
   full height, which only the full CSS sets) and compares the first screen pixel by pixel. If any differs by more
   than `DIFF_LIMIT` percent (default 0.5) the theme file isn't written and the run fails; the renders and
   diff images are in `work/` (an artifact in the workflow).

```bash
cd ops/critical-css
npm ci
node generate.mjs                          # all pages
node generate.mjs --pages=/,/shop/ --no-verify   # quick look while working on it (result in work/ only)
```

A run whose critical CSS comes out identical to the committed file stops there (nothing to check or ship).
Check results are kept in `.check-cache/` (between CI runs by the workflow's cache, saved even when a run
fails): a page/size whose critical CSS and full CSS haven't changed since an earlier check isn't rendered again.

The log has a line per page render and per page checked, with the time since the start. Only a full run
writes the theme's file.

Add a page type to `PAGES` in `generate.mjs` when the site gains one (a new template or landing page).
