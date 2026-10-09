# Dev environment (dev.ultralove.ca)

Dev is **private and disposable**: it can be rebuilt from these scripts in well under
an hour, nobody on the public internet can reach it, and it holds no real customer
data or live credentials. (The previous dev box was public, shared prod's private
network and had prod's keys; it was compromised on 2026-09-18 and destroyed.)

## Shape

| | |
|---|---|
| Droplet | `ultralove-dev-managed` (id 606134262, sfo2): Ubuntu 26.04, nginx + PHP 8.5 + Redis, database on prod's DigitalOcean managed MySQL cluster (`db-mysql-sfo2-16643`, database `wordpress_dev`), the same arrangement as production. Live since 2026-10-05; it replaced `ultralove-dev` (local MySQL) |
| Network | Own VPC `ultralove-dev-vpc` (10.140.0.0/20), no route to prod or mail |
| Inbound | DO firewall + UFW: **SSH only** (key-only, fail2ban). nginx listens on 127.0.0.1 |
| Web access | Cloudflare Tunnel (`cloudflared`, outbound only) → Cloudflare Access login (email PIN) for `DEV_ACCESS_EMAILS` |
| Data | Anonymized copy of prod. `DEV_KEEP_EMAILS` accounts (Craig, client) kept intact to log in |
| Uploads | Not copied; nginx 302-redirects missing `/wp-content/uploads/*` to ultralove.ca |
| Code | Custom code: `deploy/deploy.sh dev` (auto on merge to main). Core/vendor plugins: copied from prod |
| wp-config | Dev-only: own DB creds + salts, `WP_ENVIRONMENT_TYPE=development`, `DISALLOW_FILE_MODS`, WP-cron off, outbound HTTP blocked except the existing WordPress hosts and the exact Shopify integration test-store hostname |
| Side effects | mu-plugin `00-ultralove-environment-guard` suppresses all email; plugins holding live credentials (Mailgun, Mailchimp, Updraft, Cloudflare, Cloudinary, ManageWP, Jetpack) are deactivated; webhooks disabled; pending scheduled actions cancelled |

## Scripts

The scripts below were written for the earlier single-box dev (local MySQL). `ultralove-dev-managed` was built by hand
on top of that setup: `rebuild.sh`, `bootstrap.sh` and `refresh-db.sh` still install and load a local MySQL, so
they need a managed-database variant (`DB_HOST`/`DB_NAME` in `wp-config.php`, restore into the cluster) before the
next rebuild. `bootstrap.sh` already installs the nginx page cache (`ops/nginx/11-ultralove-page-cache.conf`).
`setup-prod-push.sh` has not been re-run for the new droplet, so `sync-code.sh` and `refresh-db.sh` (prod -> dev)
won't work until it is.

| Script | Does |
|---|---|
| `rebuild.sh` | Everything below in order: a brand-new dev from nothing |
| `provision.sh` | VPC, firewall, droplet; points the `ultralove-dev-app` ssh alias at it |
| `bootstrap.sh` | Server setup (runs on the droplet; `rebuild.sh` pipes it over ssh) |
| `setup-prod-push.sh` | Lets prod push to dev directly (prod key accepted only from prod's IP; dev gets no access to prod) |
| `sync-code.sh` | Prod → dev copy of core/vendor code, then core + plugin checksum verification |
| `refresh-db.sh` | Prod DB → dev (streamed prod→dev), anonymize, verify, rewrite URLs, disable risky plugins, `dev-admin` login |
| `setup-ci-deploy.sh` | Deploy key + GitHub `dev` environment secrets for the auto-deploy Action |
| `cloudflare-setup.sh` | Tunnel, DNS and Access (needs `CLOUDFLARE_API_TOKEN`) |
| `parity.sh` | Compare core/plugin/theme versions and status between prod and dev |

Config lives in `config.env` (no secrets). Secrets live only on servers: the dev DB
password in `/root/.ultralove-dev-db.cnf`, the fallback admin login in
`/root/.ultralove-dev-admin`, and CI keys in GitHub environment secrets.

## Common tasks

```bash
ops/dev/refresh-db.sh          # fresh anonymized data (drops dev DB)
ops/dev/sync-code.sh           # after updating WordPress/plugins on prod
ops/dev/parity.sh              # are dev and prod on the same versions?
ADOPT_REF=origin/main ops/dev/rebuild.sh && CLOUDFLARE_API_TOKEN=... ops/dev/cloudflare-setup.sh   # start over
ssh ultralove-dev-app 'tail -f /var/log/wordpress/debug.log'   # PHP notices (+ suppressed emails)
ssh ultralove-dev-app 'cd /var/www/html && wp --allow-root cron event run --due-now'   # run cron by hand
```

Plugin/WordPress updates: update on dev first by copying the new version into dev's
`wp-content/plugins` (wp-admin installs are disabled), test, then update prod, then
`sync-code.sh` + `parity.sh` to confirm they match.

## Known local modifications to vendor plugins (lost on plugin update)

- `cloudinary-image-management-and-manipulation-in-the-cloud-cdn` 3.3.2: connection-string
  regex allows digits in cloud names; "Unsync all Cloudinary media" bulk action and
  `wp cloudinary unsync` CLI (`php/class-connect.php`, `php/sync/class-unsync.php`,
  `php/traits/trait-cli.php`; May 2026). Re-apply or confirm upstream fixes after updating.

## Shopify supplier test workflow

**Status: deployed, connected, and live-tested on dev (2026-10-08 UTC).**
Feature branch `feat/dev-shopify-supplier` is under draft PR #138; it has not
been merged or deployed to production. Twenty isolated policy assertions and
28 live workflow assertions pass. Root-only credentials, the narrow Shopify
egress exception, and the five-minute cron entry are installed.

**WooCommerce > Shopify Supplier** controls the dev-only
`ultralove-shopify-supplier` plugin. It remains disabled on production regardless
of copied options. Only the Shopify development store
`ultralove-integration-lab.myshopify.com` is supported; no other Shopify hostname
is permitted by the outbound allowlist.

**Client requirement: resellers may add any UltraLove catalog product to their
own catalog.** Do not add a per-product dropshipping eligibility flag or supplier
approval gate for listing products. Resellers choose what to list; listing
permission is separate from stock availability when placing an order.
The product selection below, its 25-product cap, and simple-product validation
are test-connector safety/coverage limits, not business restrictions.
The current pilot is not yet a full-catalog reseller browsing/import interface.

### Bounded catalog app worker

The existing Shopify app now has a feed-based catalog-only execution path.
It browses 50 parent products per request (feed maximum 100), supports
category/name/SKU filtering, and imports only an explicitly selected batch of
at most 50 parent products. It never automatically imports the entire catalog
or a category. Published child variants are fetched in batches of at most 100.
The existing selected-product/order worker and its settings are unchanged.

Run the repository's `ops/dropshipping/run-shopify-catalog.php` as an operator
script outside the webroot, after deploying the related feed/connector code:

```bash
php8.5 /root/.ultralove-shopify-dev/run-shopify-catalog.php --browse
php8.5 /root/.ultralove-shopify-dev/run-shopify-catalog.php --categories
php8.5 /root/.ultralove-shopify-dev/run-shopify-catalog.php --browse --category-id=123 --search=SKU --after-id=456
php8.5 /root/.ultralove-shopify-dev/run-shopify-catalog.php --import=17186,17188
```

Browse/filter arguments affect only retrieval; `--import` requires explicit
IDs and cannot be combined with browse filters. New copies are drafts; existing
retailer listings are reused without updates. Missing prices or ambiguous stock
remain review-required and unavailable. Shopify may process selected imports
asynchronously; the worker waits for completion and verifies all selected IDs,
variants and media rather than treating a submitted job as success.

No WordPress bootstrap or SQL export runs in this app worker. The loopback GETs
use the authenticated feed with `X-UltraLove-Catalog-Read-Only: 1`. A dev-only MU
guard rejects database writes, reads the existing journal cursor without
publishing it, and uses the existing persistent object cache for its rate quota.
The worker requires response headers confirming the guard and zero database
writes. Unavailable cache, failed authentication or an attempted write fails
the request, without advancing an import checkpoint. Normal feed requests and
independent cron activity retain their existing behavior.

Each sorted selection has a root-only directory
`/root/.ultralove-shopify-dev/catalog-<selection hash>/`. Retain the original
`feed-checkpoint.json` and the importer's ownership/resume ledger. Repeating the
same `--import=...` command resumes that selection instead of recreating products.
Do not replace a checkpoint during an uncertain import.

After explicitly deciding to remove the imported copies, run:

```bash
php8.5 /root/.ultralove-shopify-dev/run-shopify-catalog.php --cleanup=17186,17188
```

Cleanup uses the same selection/checkpoint. It must only delete newly created,
still-draft products with matching supplier identity and this run's ownership;
it must never delete reused, published, or unrelated listings. Do not run cleanup
as part of normal verification. Production feed/plugins remain inactive.

Select up to 25 **existing** published simple products with SKUs, prices, and
managed stock, an approved dropshipping retailer, and an initial retail markup.
No synthetic products are created on dev. The initial pilot selects existing
product IDs `17186`, `17188`, and `17192` (SKUs `1513`, `2455`, `70072`).
New imports are Shopify drafts with dev-specific handles, variant mappings, and the
tag `ultralove-dev-supplier`. Stock sync preserves retailer-edited prices.
The dev catalog consumer now uses authenticated product, inventory, and change
feed HTTP requests rather than WooCommerce content getters. Local product objects
remain in use for mapping persistence and the existing synthetic order workflow.
Supplier withdrawal zeroes mapped stock and excludes that SKU from forwarding;
it does not delete or archive the retailer's product. Republishing reuses its
mapping. Unknown/unmanaged stock and unsupported types fail explicitly.
On 2026-10-09 the authenticated feed pull and **19 live product-only assertions**
passed, followed by **28 synthetic order/tracking regression assertions**.
The already-mapped Shopify products were ACTIVE; their publication state was
preserved, not changed back to draft. Source stock/status and temporarily edited
Shopify content were restored. A genuine feed-linked scheduled cycle completed
at `2026-10-09T02:35:08+00:00`.
All three imported images reached Shopify's ready state; titles/SKUs match and
stock was restored to `327`, `125`, and `414` after verification.
The earlier lab's two synthetic Shopify products remain archived.
Deselection stops forwarding; manually archive a Shopify draft if it is no
longer needed.

The CLI command `wp ultralove shopify sync` runs under the root-only system
worker, not as a browser callback or public webhook. Credentials and logs live
in `/root/.ultralove-shopify-dev/` with permissions `0600`; credentials are never
stored in WordPress options or displayed by the admin page. The five-minute cron
entry is `/etc/cron.d/ultralove-shopify-dev`. The earlier integration-lab worker
is paused. An actual scheduler-triggered cycle succeeded at
`2026-10-08T17:05:07+00:00`, confirmed by the root worker log and
`ul_dev_shopify_last_success`, not just a manual invocation.

Only paid Shopify **test** orders tagged `ultralove-dev-supplier` are accepted.
Use the fictional delivery recipient `Synthetic Recipient` at
`123 Synthetic Street` and a complete Canadian test address. Product lines are
real catalog items; test customers/orders are deliberately not real transactions.
Orders belong to the chosen approved retailer and use its billing email and
WooCommerce product prices. They start on hold without supplier payment.
Shipping and tax are excluded from these integration-test totals.
Email suppression, payment configuration, Access, and disabled external
integrations remain unchanged.

Bridge-created orders expose **Shopify test tracking** under their shipping
details. Enter a `LAB-` number and mark the test order completed to return
tracking for supplier-owned lines only, without notifying the recipient.
Already-forwarded tracking cannot be changed silently.

Verification:

```sh
php ops/dropshipping/test-shopify-supplier.php
php ops/dropshipping/test-shopify-feed.php
# After the feed-consumer code is deployed, provision its separate credential:
ssh ultralove-dev-app 'wp --allow-root --path=/var/www/html eval-file -' \
  < ops/dropshipping/setup-shopify-feed.php
# Product-only sync (does not import orders or return tracking):
wp --allow-root --path=/var/www/html ultralove shopify sync --catalog-only
# Product-only live verification; source stock/status and retailer edits restored:
ssh ultralove-dev-app 'wp --allow-root --path=/var/www/html eval-file -' \
  < ops/dropshipping/verify-shopify-feed.php
# On dev, with the committed verifier uploaded outside the web root:
wp --allow-root --path=/var/www/html eval-file /root/.ultralove-shopify-dev/verify-shopify-bridge.php
wp --allow-root --path=/var/www/html ultralove shopify sync
```

The live verifier uses selected existing products, temporarily adjusts/restores
dev stock, creates/deletes only its own test orders, and checks mixed-line
isolation, wholesale attribution, stock synchronization, retry behavior, and
tracking. It does not create products, change production inventory, enable a
payment gateway, or make a real supplier payment. Run it with at least two
selected products and positive stock on the first product.

Disable the connection on **WooCommerce > Shopify Supplier** to stop the worker
without deleting mappings/orders. Remove only `/etc/cron.d/ultralove-shopify-dev`
to pause scheduling. Revoke the app credential separately if retiring the
connection. This is not independent-retailer OAuth or a production rollout.

The feed credential belongs to the marked customer-role `shopify-feed-consumer`
account, with only the additional `ultralove_read_product_feed` capability and no
dropshipping approval. It is separate from the verifier's rotating credentials.
The application password is kept in root-only `feed-client.json` beside the
existing Shopify credentials, never in options. Setup is idempotent; revocation
requires explicitly removing the obsolete root file and rerunning setup.
Do not display the file.

This operator-only consumer authenticates against the fixed loopback REST URL
with the dev Host header. WordPress's standard localhost transport is permitted
without widening external HTTP access or changing Cloudflare Access. Retailer
onboarding must instead use approved HTTPS access; this is not an external
connection recipe. Feed state (catalog DTOs, generation/cursor and mappings,
not secrets) lives in `ul_dev_shopify_feed_state`. It advances only after every
Shopify catalog mutation succeeds. A 409 starts a fresh snapshot and change
replay without deleting mappings. 401/403/429, malformed responses and partial
Shopify failures stop the cycle with a visible error; no checkpoint advances.
429 reports `Retry-After`; the existing five-minute scheduler supplies the next
attempt, rather than immediately retrying inside the worker.

## Supplier product and inventory feed

The separate `ultralove-product-feed` plugin implements the feed-first milestone
on **dev only**. Its OpenAPI 3.1 contract is
[`openapi.json`](../../wp-content/plugins/ultralove-product-feed/openapi.json).
It uses WooCommerce-compatible field names with explicitly documented supplier
extensions; it is not a drop-in `wc/v3` endpoint. The dev Shopify test bridge
consumes it for catalog data; the dedicated lab source and CSV exporters remain
unchanged.

Record the exact consumer setup and test results in the
[retailer product feed guide](../../documentation/retailer-product-feed-guide.md)
while adapting the existing Shopify test connector to this feed. Keep lab-only
operator steps distinct from released retailer instructions.

### Account access and endpoints

Under **Users > Edit user > Supplier catalog API**, an administrator can grant
catalog API access. Use a customer-role reseller account and a revocable
WordPress application password over HTTPS, never an administrator's credentials.
Application passwords inherit that account's other WordPress permissions.
Catalog access is independent of dropshipping order approval. Existing staff
with `manage_woocommerce` can also read the feed.

Base URL: `https://dev.ultralove.ca/wp-json/ultralove-feed/v1`.
Dev remains protected by Cloudflare Access; application-password authentication
does not bypass Access. Private-origin verification runs locally on the dev host.

| GET route | Purpose |
|---|---|
| `/products` | Full published catalog, including out-of-stock products |
| `/products/{id}` | Current product and stock |
| `/inventory` | Lightweight current stock records |
| `/changes` | Resumable current-state upsert/removal notifications |
| `/openapi` | Deployed contract |

All routes require authentication and account permission. No write/order routes
are exposed. Responses are `private, no-store`; the limit is 300 requests per
account per minute, with `429` and `Retry-After`. `per_page` is 1-100; paginate
using `after_id`/`next_after_id`. Optional repeated `ids[]=17186` parameters filter
catalog or stock reads. No product eligibility flag, price or positive-stock
requirement, or 25-product pilot cap applies to the feed.

### Data contract and CSV mapping

| Existing CSV data | Feed field |
|---|---|
| SKU / Variant SKU | `sku`; use stable `id` for synchronization |
| Title, Content | `name`, `description` |
| Excerpt | `short_description` is the actual short description; legacy CSV stores SKU here |
| Regular/Sale Price | `regular_price`, `sale_price`; `price` is the active supplier price |
| Stock | `stock_quantity`, `stock_status`, `manage_stock`, `backorders` |
| Image URL / Additional Image URLs / Shopify image rows | Structured `images` |
| Categories / Brands | Structured `categories` / `brands` |
| UPC | `global_unique_id`, falling back to existing `hwp_product_gtin` |

Prices are decimal strings with `currency` and `prices_include_tax`; they do not
include a reseller's markup or calculate order shipping/taxes. Empty prices/SKUs
are preserved rather than dropping published products. Unmanaged/unknown stock
is `null`, not zero; negative backorder quantities are preserved. Variations
carry `parent_id` and `stock_owner_id` for inherited/shared parent inventory.
Only published variations of published parents are exposed. Images retain
their source URLs, so private dev images still require Access.
Deleted image references appear in `data_warnings` and server logs; only the
missing image is omitted, not the product. No source catalog/media is repaired
or changed by reading the API.

### Initial import and incremental synchronization

1. Read the first `/products` page and save its `generation` and `change_cursor`.
2. Import all pages using `next_after_id`; do not replace the saved change cursor
   with cursors from later snapshot pages.
3. Read `/changes?after=<saved cursor>&generation=<saved generation>`.
4. Apply upserts/removals idempotently by `id`; persist the response cursor only
   after applying the complete page. Continue while `has_more` is true.
5. On `409 feed_resync_required`, restart the full import. Seven-day journal
   retention is not an unlimited offline synchronization guarantee.

Changes describe **current state**, not historical snapshots; repeated events
for an ID are normal. Delivery cursors are assigned only to committed events,
under a database lock, so a slow producer transaction is not skipped when a
later producer commits first. Retention removes only a contiguous prefix.
Product dates are informational, not reliable stock cursors.

Normal WooCommerce/product/meta changes enqueue events immediately. A minute
worker reconciles 500 published records per cycle, refreshing product caches
and comparing fingerprints to catch updates that bypass hooks; its complete
scan also detects removals. At the initial 6,430-product size this requires
13 batches, provided jobs finish before the next minute. Inspect
`ultralove_feed_reconciled_at`, checkpoint progress and logs; do not claim
immediate change notifications for direct SQL updates.

### Runtime, verification and recovery

The committed cron definition is
[`product-feed.cron`](../dropshipping/product-feed.cron), installed as
`/etc/cron.d/ultralove-product-feed`. Root-only runtime files are under
`/root/.ultralove-product-feed/` (directory `0700`, credentials/logs `0600`).
[`product-feed.logrotate`](../dropshipping/product-feed.logrotate) bounds the
reconciliation log. Activation creates only the `ultralove_feed_changes`,
`ultralove_feed_state`, and `ultralove_feed_rates` tables; no products or orders.

```sh
php ops/dropshipping/test-product-feed.php
php ops/dropshipping/test-shopify-supplier.php
python3 ops/dropshipping/verify-product-feed.py --schema-only \
  --spec wp-content/plugins/ultralove-product-feed/openapi.json
# On dev; committed helpers live outside the web root:
python3 /root/.ultralove-product-feed/verify-product-feed.py
wp --allow-root --path=/var/www/html ultralove feed reconcile --batch-size=500
```

The live verifier creates only owned test credentials/users, uses existing
catalog products, temporarily changes/restores a non-Shopify-pilot product's
stock/status, and validates full catalog/schema coverage, authentication,
concurrent commits, removals, retention, reconciliation and rate limits. Its
warm inventory p95 target is at most two seconds under five concurrent clients.
The allowed test account's credential remains root-only; the denied credential
is revoked. No synthetic products, customer emails, real payments or shipments.

To stop the feed, deactivate its plugin and remove only its cron entry; preserve
the journal tables. Normal code rollback uses the deploy pipeline. After a
database restore/rebuild, explicitly rotate `ultralove_feed_generation` using
`wp_generate_uuid4()` and reset `ultralove_feed_reconcile_after` to zero so clients
must resynchronize. Recreating a missing journal also rotates the generation.
The existing CSV download workflow stays available throughout recovery.

### Verified dev baseline (2026-10-08 UTC)

Implementation revision `ff51422` is deployed and active. Verification covered
all **6,430 published products** without omissions or duplicates. Dev currently
has no published variations; isolated contract tests cover variations, parent
visibility and shared stock ownership.

- 335 isolated feed assertions, 20 existing supplier policy assertions, and
  OpenAPI schema validation pass.
- 33 live HTTP checks pass, including full catalog schema coverage, next-request
  stock freshness, account-access revocation, committed-event ordering,
  tombstones, cursor expiry, reconciliation and the exact account quota.
- Six additional benchmark checks pass under five concurrent clients:
  cold catalog (100 products) **0.572s**, cold inventory (50 records) **0.380s**;
  warm p95 catalog **1.176s**, inventory **0.779s**, both below the two-second target.
  Cold tests invalidate only the selected products' caches, not the whole site.
- The initial full reconciliation processed all 6,430 products in 13 batches
  in **105.257s**, completed at `2026-10-08T18:24:33+00:00`, populated 6,430
  fingerprint records and reset its checkpoint to zero.
- The minute worker and daily/seven-rotation log policy were installed at
  `2026-10-08T18:24:34Z`; targeted logrotate validation passed.
  Actual cron launches at `18:25:01` and `18:26:01` each completed a 500-record
  batch, advancing the checkpoint to product ID 22460 without manual runs.
- 35 products contain pre-existing missing image references. They remain
  importable with explicit `data_warnings`; catalog/media data is untouched.

CSV bytes remained unchanged during the read-only API traversal. Live mutation
tests restored their source stock/status, account access and reconciliation
checkpoint; the denied credential was revoked. Final checks confirmed both CSV
files remained available, the test customer had only its intended feed access,
and the existing Shopify worker still succeeded at
`2026-10-08T18:25:06+00:00`. Verification/performance results and reconciliation
evidence are retained under the root-only runtime directory.

This completes the feed-first dev scope only: contract, read-only API,
incremental synchronization/reconciliation, and dev testing/deployment.
Full-catalog Shopify import, independent-retailer OAuth, order placement,
payment, fulfillment and production rollout are separate future phases.
