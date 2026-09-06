# SEO: Adult Wholesale Canada

Last updated: 2026-09-06 (UTC)

## Goal

Improve the homepage relevance and crawlability for the commercial query `adult wholesale canada`, while preserving the site's Canadian traffic protection.

## Completed Changes

### Homepage On-Page SEO

- Homepage URL: `https://ultralove.ca/`
- Document title: `Adult Wholesale Canada | Adult Toys for Retailers | UltraLove`
- Meta description: `UltraLove is a Canadian adult wholesale distributor serving retailers with adult toys, intimate wellness products, competitive pricing, and wholesale account support.`
- The existing homepage wholesale call-to-action was changed from an `H2` to the single page `H1`: `Adult Wholesale Canada`.
- Added one retailer-focused supporting paragraph and retained the existing Shop and Wholesale Signup links.
- The homepage modification time was refreshed after the Elementor content update so the WordPress page sitemap signals the change.

Implementation:

- Theme metadata hooks: `wp-content/themes/martfury-child/functions.php`
- Homepage content: WordPress page ID `14`, Elementor HTML widget `41ff0dd`
- The previous widget HTML is retained in hidden page meta key `_ultralove_homepage_wholesale_widget_backup` for rollback.
- Theme commit: `cfe18ef feat: optimize homepage for adult wholesale canada`

### Sitemap And Crawler Access

The WordPress sitemap was healthy at the origin but Google Search Console reported that it could not fetch it.

Diagnosis:

- Origin request to `https://ultralove.ca/wp-sitemap.xml` returned HTTP `200` with `application/xml` content.
- A public edge request returned Cloudflare HTTP `403` with `cf-mitigated: challenge`.
- The active Cloudflare custom rule `Challenge outsiders` applied a Managed Challenge to every non-Canadian request with `(ip.src.country ne "CA")`.

Remediation:

- Updated the Cloudflare rule expression to `(ip.src.country ne "CA" and not cf.client.bot)`.
- The Managed Challenge remains active for non-Canadian, unverified traffic.
- Cloudflare-verified bots, including Googlebot, can now fetch the sitemap and crawl public pages without a challenge.
- Do not replace this condition with a Googlebot user-agent exception; user agents can be spoofed.

The durable WAF configuration note is also recorded in `documentation/ARCHITECTURE.md`.

## Verification Completed

- PHP syntax check passed for the child theme.
- Local Nginx/PHP-FPM rendering confirmed the homepage title, meta description, and `H1`.
- The homepage is indexable (`blog_public=1`) and emits canonical URL `https://ultralove.ca/`.
- WordPress publishes `https://ultralove.ca/wp-sitemap.xml`, including a page sitemap.
- Cloudflare dashboard confirms the active WAF expression includes the verified-bot exception.

## Required Follow-Up

In Google Search Console:

1. Run **Test live URL** for `https://ultralove.ca/wp-sitemap.xml`.
2. Resubmit `https://ultralove.ca/wp-sitemap.xml` if Search Console retains the previous fetch error.
3. Request indexing for `https://ultralove.ca/`.
4. Monitor impressions, clicks, and average position for `adult wholesale canada` and related retailer-intent searches.

Search ranking is not immediate or guaranteed. The completed work gives Google a crawlable sitemap and stronger homepage relevance signals; rank movement depends on re-crawling, index updates, competition, site authority, and the page's commercial usefulness.