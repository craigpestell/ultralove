# Retailer product feed and dropshipping setup notes

**Status: working notes, not released retailer instructions.**
Last updated: 2026-10-09.

Record the exact steps while connecting the existing Shopify test app to the
UltraLove product feed. Turn verified steps into retailer instructions only
after the end-to-end test passes. Do not create another Shopify app for this
test phase.

## Current baseline

- The existing test app is connected to
  `ultralove-integration-lab.myshopify.com`.
- The dev connector's catalog adapter now reads authenticated products, inventory
  and changes over HTTP. Deployment and live verification results are recorded
  below; do not infer a completed live connection from implementation alone.
- The authenticated product feed is verified on dev, including full catalog
  traversal, inventory, change notifications, access controls, and recovery
  requirements. The consumer's isolated tests cover actual bridge imports,
  checkpoint handling, stock updates, retailer-edit preservation and removals.
- Production feed and Shopify supplier plugins remain inactive. Production
  dropshipping remains disabled.
- My Account already provides **Download Product Inventory CSV (Shopify)**.
  That is the separate manual-import route, not an automatic feed connection.

## Step record

### Bounded browsing and selected imports

The existing Shopify app consumes this WooCommerce feed; there is no SQL export,
CSV replacement, or second Shopify app. The entire published catalog remains
available, but the initial connector does **not** automatically import it all.

- Product browsing requests **50 parent products per page**. The feed rejects
  requests above **100 records**; this is a per-request limit, not a total-catalog
  ceiling. Follow `next_after_id` while `has_more` is true.
- `/categories` provides authenticated category navigation, also defaulting to
  50 records with a maximum of 100.
- `/products` accepts `category_id` (including descendants), `search` (literal
  partial name/SKU, including published variation SKUs), and `parents_only`.
  Existing unfiltered consumers still see published variations.
- The operator-backed app import accepts **1–50 explicitly selected parent
  product IDs**. Published variants are included automatically, fetched in
  requests of at most 100 records. Selecting a category does not import it.
- New Shopify copies remain unpublished drafts. Missing prices, unknown or
  unmanaged stock, and shared parent inventory require review and cannot become
  sellable stock through invented/duplicated quantities.
- Existing retailer listings keep their content, prices, inventory and
  publication status. Listing permission does not approve order forwarding.

The catalog-only app worker lives outside WordPress and uses the existing
root-only app/feed credentials. Its selected feed checkpoint, resume state and
created-versus-reused cleanup ledger are filesystem-only. It does not bootstrap
WordPress or write product mappings/options/postmeta.

Its loopback feed GETs explicitly request the dev-only catalog read mode. That
mode installs a database-query guard before ordinary plugins and authentication,
rejects write statements, uses the existing persistent object cache for request
limits, and reads the published journal cursor without allocating new entries.
The normal connection/account is unchanged; no special database connection or
new account is created. A request requiring a database write fails explicitly
rather than applying it. Normal feed clients and the selected-product/order
worker retain their existing behavior.

#### Customer-facing import titles

The feed keeps its existing retailer-facing `name` and adds `retail_name` for
customer-facing Shopify titles. Only a complete `<b>...</b>` element whose text
matches one of these confirmed instructions is removed:

- `DISCOUNTS DO NOT APPLY`
- `WHILE STOCK LASTS` (including `<b>**WHILE STOCK LASTS**</b>`)
- `TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT`
- `ESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT` (existing source typo)

Matching ignores case, repeated whitespace, HTML entities, and surrounding
asterisks. Other bold text, partial matches, unmarked instructions and notices
wrapped in `<strong>` remain. The stored WooCommerce title, descriptions,
SKUs, prices, stock, media, manual CSV exports and retailer-facing catalog names
are unchanged.

The shared catalog import planner used by the embedded lab app uses
`retail_name` for new draft imports and explicitly reviewed listing updates.
Older feed checkpoints without the field keep their previous `name` behavior.
An empty or invalid `retail_name` fails explicitly instead of restoring the
excluded instruction. Deploying this code does not rewrite existing Shopify
products or change the retired legacy connector.

Run [the read-only dev verifier](../ops/dropshipping/verify-retail-names.php)
through dev WP-CLI to audit all published parent titles and check authenticated,
database-write-guarded feed responses plus import plans for existing products.
It makes no Shopify requests and does not create or edit products.

##### Verified development result (2026-10-11)

- Implementation `76230ec` deployed through `deploy/ship-dev.sh`: three owned
  plugin files changed, staging PHP lint and all four origin smoke tests passed.
  Nginx, PHP-FPM and Redis remained active. PR #151 holds the production-pending
  change; no production deployment or plugin activation occurred.
- The verifier audited **6,429 published parent titles**: **857** matched the
  confirmed removal rules, while **5,571 other bold titles** were preserved.
  **20 authenticated feed/import-plan checks passed**, including a real tester
  title; all stored titles were unchanged.
- Four plans through the embedded app's actual restricted catalog proxy verified
  cleaned discount/tester names and unchanged ordinary/unconfirmed tester names.
  For example, `T0116-61TC Ryder Black` and `WC90103-T Toy Love Gel` are the new
  draft titles; their supplier-facing names retain the original notices.
- **368 feed**, **214 import-planner**, **53 feed-consumer**, **977 inventory**,
  **351 read-only/traversal** and **35 CSV** regression assertions passed;
  OpenAPI JSON, PHP syntax and PR CI checks passed.
- Run the WP-CLI verifier with the normal theme loaded, **not `--skip-themes`**.
  Skipping the theme altered WooCommerce's stored post-type registration and
  caused the next guarded HTTP request to reject its attempted option update.
  Normal WordPress bootstrap restored the registration; the documented command
  and the real app proxy then passed without weakening the write guard.
- No Shopify products were created, rewritten or published. Existing listings
  still require an explicitly reviewed update; old immutable feed checkpoints
  retain their previous title behavior.

##### Verified stock-notice expansion (2026-10-11)

- Rule expansion `bc10748` is deployed to dev. **376 feed assertions** and
  **214 import-planner checks** passed, including exact bold matches with
  surrounding asterisks and preservation of unwrapped, partial and `<strong>`
  matches. Staging PHP lint, origin smoke checks and dev services passed.
- The guarded verifier audited **6,429 published parents**: **858 cleaned**,
  **5,570 other bold titles preserved**, and **20 feed/import-plan checks**
  passed with all source titles unchanged.
- The real app's restricted import planner returned supplier product `17188`
  as `2455 The Ultimate Kiss Oral Lovemaking Book`, without its bold
  `WHILE STOCK LASTS` notice. No Shopify mutation was made. Create a fresh
  preview to use the new rule; existing frozen previews and listings retain
  their previous titles until explicitly reviewed.
- The first guarded verifier request rejected WordPress application-password
  `last_used` bookkeeping. One normal authenticated feed read completed that
  standard bookkeeping; the strict guarded verifier then passed unchanged.
  Never weaken the database-write guard to accommodate authentication logging.
- Production remains at `f3f9a5c`; this expansion has not been deployed there.

This operator-backed development connection is distinct from the embedded
product-picker UI described below. Implementation and isolated tests do not
establish live deployment or import success; record those results separately.

#### Verified development result (2026-10-09)

- The real authenticated feed passed 15 bounded-browsing checks: default 50,
  maximum 100, server rejection of 101, advancing pages, categories, combined
  category/SKU filtering, and explicit selection capped at 50 parents.
  Eight requests recorded 103 guarded SELECT statements and zero database writes.
- One explicitly selected 50-parent page completed in the existing Shopify lab
  store: **46 new unpublished drafts**, plus **four existing listings reused
  unchanged**. There are 50 unique Shopify mappings and no bulk/verification errors.
- Final live verification checked source variant coverage, SKUs, prices, barcodes,
  options, stock, draft identity/status, and ready media. Repeating the same command
  verified the completed batch again without recreating products.
- Shopify normalizes HTML serialization. Verification compares content and
  meaningful markup instead of raw bytes. New descriptions use public production
  upload references; unchanged descriptions in still-unverified, owned drafts
  received description-only repairs. Completed imports and reused listings are
  never eligible for that repair.
- This was a selected batch, not a full-catalog import. Remaining products are
  discoverable through paging. Production remains disabled and untouched.

#### Embedded lab catalog, pricing and draft jobs verified (2026-10-09)

The same installed **UltraLove Dropshipping Lab** app now opens its authenticated
catalog browser inside the Shopify test-store admin. Its separate web application
is deployed as release `13306ab`; no second Shopify app was created.

- Browse 50 published parents per page, advance pages, search names/SKUs and
  filter by category. SKU `1513` and combined category/SKU filtering were verified.
- Select at most 50 parent products and click **Save selection**. Saved choices
  persist after reload. A forged 51-parent submission is visibly rejected.
- **Clear selection** clears the working selection; saving is a separate action.
  Category names render decoded text, not HTML. Existing saved selections were
  preserved during final verification.
- Supplier wholesale CAD prices and stock are for review, not retailer price
  settings or permission to forward orders. Saving does not import, publish or
  synchronize products. The separate pricing/import screen now previews markup
  and queues confirmed background draft imports, as described in section 4.
- The application uses a source-restricted, pinned-key SSH proxy with a separate
  catalog credential. It reads the normal authenticated dev feed and allows
  routine authentication/feed database bookkeeping. Unlike the historical strict
  verifier above, it does not request a no-write guard. The guard itself and
  WordPress plugin/MU-plugin runtime remain unchanged.
- The original three-product dev sync still passed with retailer prices preserved.
  The new saved selections do not change that worker's selection or establish
  ongoing sync for the previously imported 50-parent batch.
- This is verified only for the allowlisted test store. Independent retailers,
  real-order forwarding and production activation remain separate work.

Keep staff setup separate from actions a retailer can actually perform.
Do not describe a lab-only administrator control as a retailer-facing screen.

| Step | Actor | Action to document | Current status |
|---|---|---|---|
| 1 | UltraLove staff / retailer | Grant catalog access and issue dedicated, revocable feed credentials | Dedicated dev credential and actual revocation verified; external retailer onboarding pending |
| 2 | Integrator / test operator | Configure the existing Shopify test app's connector to read the feed | Dev adapter deployed; authenticated feed pull and scheduled cycle verified |
| 3 | Retailer | Choose products from the published catalog | Embedded test-store browsing and saved selections verified, capped at 50 parents; independent-store onboarding pending |
| 4 | Retailer / connector | Set initial retail prices and import selected products as drafts | Embedded lab CAD preview, confirmation, background creation and unchanged reuse verified |
| 5 | Retailer | Review images, descriptions, SKUs, variants, prices, and availability | Native Shopify draft/media/price/stock review and existing-listing preservation verified; live variable-product import remains pending |
| 6 | Connector | Synchronize inventory and changes without overwriting retailer edits | Opt-in app-owned inventory sync deployed to development/lab; authenticated stock reads and embedded controls verified; enrollment remains off, live correction verification pending |
| 7 | Integrator / retailer | Recover from interruptions, removals, expired cursors, and revoked access | App creation-claim restart recovery verified; app-owned inventory withdrawal, expired-cursor/generation recovery and uncertain-mutation retries tested locally; live legacy cutover and stock-write recovery verification remain pending |
| 8 | UltraLove staff / retailer | Separately approve and configure dropshipping fulfilment | Dev checkout/order tests verified; real pilot approval pending |
| 9 | Retailer | Submit an order and receive tracking using the approved workflow | Feed-linked synthetic order/tracking regression passed; real retailer fulfilment remains unapproved |

## 1. Catalog access and credentials

Record:

- The UltraLove account the retailer uses and how staff grant catalog access.
- How a dedicated WordPress application password is created, delivered securely,
  revoked, and replaced. Never use an administrator's credentials.
- The exact fields the connector asks the retailer or integrator to enter.
- How the retailer confirms access, and the message shown when access is denied.

Verified technical facts:

- Administrators grant other accounts access under
  **Users > Edit user > Supplier catalog API**. This grant/revoke control remains
  administrator-only; it is not a shop-manager onboarding task.
- Shop managers and administrators already have feed-reading access through
  `manage_woocommerce`. No per-user feed checkbox is needed for these roles.
  Ordinary customers still require an explicit catalog grant.
- For a manager's own separate API client, use **Profile > Application Passwords**
  over HTTPS. Give the credential a descriptive client-specific name, store the
  one-time password securely, and revoke it from the same section when no longer
  needed. An application password inherits the manager's other permissions:
  **never give a manager's credential to a retailer**. Use a dedicated,
  catalog-only customer account for a retailer connector.
- Dev base URL: `https://dev.ultralove.ca/wp-json/ultralove-feed/v1`.
- Requests require both account permission and authentication over HTTPS.
- Cloudflare Access still protects dev. Feed credentials alone do not bypass
  that boundary; the dev consumer's approved access path must be verified.
- Catalog access is independent of dropshipping approval.

Do not publish a production feed URL as usable until production feed access
has been enabled and tested.

## 2. Connect the existing Shopify test app to the feed

Record the actual connector configuration, field names, authentication method,
required permissions, connection-test result, and disconnect procedure.

The feed is a custom authenticated JSON API, not a drop-in WooCommerce `wc/v3`
API or a Shopify CSV import URL. The adapter must use the
[OpenAPI contract](../wp-content/plugins/ultralove-product-feed/openapi.json).
Existing lab authentication is not an installation recipe for independent
retailers' Shopify stores.

Keep this phase product-only. Connecting a catalog must not silently enable
order forwarding, supplier payment, production dropshipping, or notifications.

### Administrator-led setup of the existing dev connection

This is a staff workflow for the existing test store, not independent-retailer
self-service. **WooCommerce > Shopify Supplier** is available on dev to
staff administrators with `manage_options`. The menu, page and settings-save
handler use the same permission; shop managers and customer accounts cannot
access them. Shop managers' automatic feed-reading permission does not grant
connection-management rights. Saving still requires a valid nonce and validated
settings.

The existing worker already has a separate root-only catalog credential.
Administrators do not issue, enter or rotate that credential on this screen.
Cloudflare Access and the fixed Shopify test-store allowlist remain unchanged.

1. An administrator/operator confirms the dev feed and supplier plugins,
   worker credential and test-store connection are installed. An approved dev
   retailer and enabled dev dropshipping are prerequisites of this existing
   test connector; catalog listing itself does not grant fulfilment approval.
2. The staff administrator opens **WooCommerce > Shopify Supplier**.
3. Under **Existing product IDs**, enter comma-separated IDs from the existing
   WooCommerce catalog, up to 25 published simple products with SKUs, prices and
   managed stock. Out-of-stock products with known zero stock are allowed.
4. Choose the existing **Approved retailer** and set **Initial retail markup (%)**.
   This markup affects newly created Shopify products only, not existing
   retailer selling prices.
5. Preserve the approved **Enable dev supplier sync** state and use
   **Save Changes**. The existing scheduled worker reads the saved selection.
   This screen does not provide a new product-only worker mode; an operator
   uses `wp ultralove shopify sync --catalog-only` for a product-only check.
6. Check **Last successful catalog sync** and any displayed error, then review
   imported drafts in Shopify. New products remain unpublished; previously
   mapped products retain their existing publication state.
7. To stop a product's sync and forwarding, remove its ID and save. Its Shopify
   product and mapping remain; deselected stock is no longer kept current.

Do not change live dev settings merely to rehearse these steps. The original
three-product selection and 0% markup remain the verification baseline.
Retailer-facing product browsing/selection, additional stores, and production
availability remain separate pending work.

**Dev verification, 2026-10-09:** guidance deployed in `6f7185d`; one owned
plugin file changed, staging PHP lint and all four origin smoke checks passed.
**36 supplier policy**, **335 product-feed**, and **53 feed-consumer** isolated
assertions passed, followed by **18 live staff/manager assertions**:

- An existing staff administrator could render setup, selection/markup fields,
  the save nonce and credential guidance. Invalid input was rejected without
  changing settings.
- A temporary standard Shop Manager, with no administrator permission or
  explicit catalog grant, read the feed using actual HTTP application-password
  authentication. Its own credential creation/revocation permissions were
  confirmed under HTTPS; the revoked password subsequently returned **401**.
- That manager could not view/save the administrator configuration or alter
  another account's catalog grant (**403**). Customer configuration requests
  were also rejected with **403**.
- Original selection, retailer, markup and approval were unchanged. Email
  suppression passed, no product/order/payment/dispatch action was run, and
  the temporary manager and application password were removed.

No access-policy expansion or production change occurred. Evidence is retained
on dev in `/root/.ultralove-shopify-dev/staff-onboarding-verification.log`.
This verifies role boundaries and server-rendered guidance, not a completed
retailer-facing browser walkthrough or support for another Shopify store.

## 3. Select products

In the allowlisted Shopify test store, open **Apps > UltraLove Dropshipping Lab**:

1. Enter **Search name or SKU**, choose **Category**, then click **Search catalog**.
2. Use **Next 50 products** to browse further; **First product page** returns to
   the start of the current filter. **More categories** appears when available.
3. Check or uncheck products, up to 50 distinct parents across pages.
4. Click **Save selection (no import)** to persist the choices. Reloading retains
   saved choices. **Clear selection** only clears the working selection; save
   afterward to persist an empty selection.

These choices are not the scheduled worker's selection. Adding or removing a
choice here does not create a Shopify product or start/stop its inventory sync.

Retailers may select any published UltraLove catalog product, including
out-of-stock products. Listing permission is separate from order availability.
Do not carry the scheduled worker's 25-product/simple-product lab limits into
the feed's business rules. Document any unsupported product types explicitly.

Use stable supplier product/variation IDs for synchronization; record SKUs as
well. Do not match products by title alone.

## 4. Initial import and pricing

In the allowlisted test-store app:

1. Save 1-50 parents in the catalog and open **Review pricing and draft imports**.
2. Enter an initial markup of 0-1000%, with at most two decimal places, then click
   **Preview draft import**. The store must use CAD and the verified merchant
   **Shop location**. Retail price is wholesale times `(1 + markup / 100)`,
   rounded to two decimals; no FX, shipping, tax or fee adjustment is included.
3. Review source prices, proposed prices, stock and missing-data/media warnings.
   Existing listings are marked for unchanged reuse, not repricing.
4. Confirm the displayed snapshot and choose **Queue draft import** within ten
   minutes. Saved selections must still match it. Only one job per shop can run
   or wait in the queue.
5. Watch automatically refreshed per-product results and use the Shopify links
   to review drafts. The separate non-root worker persists job/item state and
   creation claims; public requests never execute a root importer.
6. For failed items, use **Retry / reconcile failed items**. Completed items are
   skipped. Uncertain creations are recovered by read-only lookup, never another
   blind create; unresolved uncertainty or a deleted mapped listing requires
   operator review. Do not run the standalone operator importer concurrently.

Existing canonical app-owned listings retain prices, content, publication
state and inventory. New listings are unpublished drafts; unsafe/unknown/shared
stock remains zero with review warnings. The current app no longer reuses old
test fixtures; historical verification below predates that change.
Imports do not automatically enroll products in ongoing sync or
approve order forwarding.

Live verification reused all five existing selections unchanged. Source `17539`
(SKU `SE1414-50 BX`) created draft `8424579366975`, with CAD 7.95 wholesale,
25% markup, CAD 9.94 retail, stock 2, barcode `716770016270`, and one READY image.
Native Shopify admin confirmed Draft status and no publication anywhere.
A second 30% preview reused the same listing without changing its 9.94 price.
Worker restart recovered an intentionally interrupted creation through its
persisted claim, with exactly one listing. All three jobs completed; the original
five selections were restored. This retained draft is separate from the earlier
operator-backed 50-parent batch and historical fresh-draft checks below.

Verify that products arrive as drafts before any publication. Check titles,
descriptions, images, SKU/barcode mappings, variant options, and stock ownership.
Surface missing-image warnings rather than silently omitting products.
Supplier prices must not be mistaken for the retailer's selling prices.

The app snapshots the feed generation/change cursor with its import plan.
Its opt-in inventory consumer independently reads current stock on enrollment
and validates the supplier journal on subsequent cycles; it does not synchronize
catalog content or add supplier variants automatically. This implementation is
deployed to the lab but not enabled. Record how the user knows the import
has finished.

## 5. Review and publication

Capture the exact Shopify screens and checks for reviewing imported drafts,
editing retail prices/content, and choosing sales channels.

Do not publish test products to a real storefront as part of verification.
Retailer publication instructions remain pending until the intended consumer
workflow has been tested.

## 6. Inventory and change synchronization

The deployed app inventory page is `/app/inventory`, linked from the
catalog. Each completed app-owned product requires explicit confirmation and
enrollment. The existing non-root worker targets five-minute cycles after queued
imports. Last success, next due time, variant count and errors are visible.
Corrections set supplier-backed **on-hand** stock at the approved Shop location;
committed Shopify order reservations remain in place. Unknown, unmanaged,
shared, missing, withdrawn or out-of-stock supplier inventory becomes zero.
No prices, descriptions, status/publication, orders or variants are changed.
Pause stops new corrections, although a sent request may finish. Catalog
deselection is independent of inventory enrollment.

Persisted mappings/checkpoints and compare-and-set/idempotent requests recover
from interruptions. An uncertain request past Shopify's 24-hour retry window
pauses for explicit review/re-enrollment. Supplier `Retry-After` delays retries.
Deploy the development feed client and restricted proxy, apply the app inventory
migration, retire the old development scheduler and resolve its test-fixture
claims before enabling canonical imports. None of that live cutover is performed
by the local regression tests. The development feed client (`618a71a`), restricted
proxy and app inventory migration were deployed on 2026-10-09 with app release
`107030b`. All 50 app tests, type-check, lint and app/worker builds passed on the
lab host. Development site smoke tests returned 200. Authenticated source `17539`
inventory and checkpoint reads succeeded (one variant, cursor `9027`), and the
inventory controls loaded inside Shopify Admin without console errors.
The database contains zero inventory enrollments and no queued/running imports;
no live stock correction was sent. The original connector/cron and test fixtures
remain intact pending a separate legacy cutover. Production was not changed.

Verify:

- Stock changes reach the correct Shopify product/variant and inventory location.
- Retailer-edited prices and descriptions remain intact.
- Out-of-stock and unknown stock are handled explicitly; unknown stock is not
  silently treated as available stock.
- A retailer can pause synchronization without deleting their product; changing
  catalog selections does not silently pause an independently enrolled product.
- Supplier removals have a defined, tested Shopify outcome.
- Retries do not create duplicate products or double-apply inventory changes.

Do not advertise the existing lab worker's five-minute schedule as a verified
feed-consumer service level.

### Dev operator implementation and validation record

These are staff/operator steps, not independent-retailer installation instructions:

1. Reuse the existing app/store and dev supplier settings; do not create an app,
   enable production plugins, or change the existing test-order safeguards.
2. Deploy the feed consumer through the normal owned-code deployment pipeline.
3. Run `ops/dropshipping/setup-shopify-feed.php` using dev WP-CLI. It provisions a
   marked customer account with catalog capability and a separate application
   password in root-only `/root/.ultralove-shopify-dev/feed-client.json`.
   Existing verifier passwords are not reused or rotated.
4. The root worker uses authenticated loopback REST requests with the dev Host
   header. Cloudflare Access and external HTTP restrictions remain intact.
   External retailers require approved HTTPS access instead.
5. Run `wp ultralove shopify sync --catalog-only` for product data only. Existing
   mappings are reused; initial creations are drafts, with feed content/media,
   SKU/barcode and configured initial markup. Existing retailer edits are not
   overwritten.
6. Run the product-only live verifier. It tests stock changes, retailer content
   preservation, withdrawal/republication, 409 resync, removed account scope and
   revoked application-password authentication. It restores its mutations.
7. Observe a real scheduled cycle and record its timestamp, not just a manual
   success. Run the existing synthetic order/tracking verifier separately to
   check that catalog wiring has not changed the forwarding safeguards.

Consumer behavior: supplier withdrawal zeroes mapped stock and excludes that
SKU from forwarding, retaining the Shopify product and its mapping. Retailer
deselection merely stops synchronization. Neither action deletes a retailer
product. Unknown stock fails explicitly. Partial failures retain the prior
checkpoint; 409 rebuilds the snapshot without dropping mappings. Rate limiting
reports `Retry-After` and leaves retry to the five-minute worker.

Local validation: **53 isolated Shopify feed consumer assertions**, plus
**20 supplier policy** and **335 product-feed assertions**, passed. The dev
WordPress HTTP client reached the loopback REST endpoint and received the
expected unauthenticated **401 JSON**, without an outbound-allowlist change.
### Verified dev results, 2026-10-09

- Deployed code commit `c6c25fdde12a785efe5904d975dc44fe031b76bd` using
  `deploy/ship-dev.sh`; three owned plugin files changed, staging PHP lint and
  all four origin smoke tests passed. Nginx and PHP-FPM are active.
- Dedicated catalog credential provisioned without displaying its password.
  `wp ultralove shopify sync --catalog-only` then pulled IDs **17186, 17188,
  17192**, SKUs **1513, 2455, 70072**, with stock **327, 125, 414** into their
  existing mappings in `ultralove-integration-lab.myshopify.com`.
- **19 live product-only assertions passed**, including stock mutation and
  restoration, title/description/price preservation, withdrawal to zero stock,
  republication on the same mapping, real HTTP 409 resync, removed capability,
  and actual application-password revocation. The dedicated worker credential
  was not rotated or revoked by those tests.
- The verifier initially assumed these existing Shopify products were drafts.
  Investigation found all three already **ACTIVE**. The verifier was corrected
  to check publication-state preservation, rather than changing their status.
  New draft creation is verified by the isolated bridge tests, not claimed as a
  new live creation in this phase.
- **28 existing synthetic order/tracking regression assertions passed** with
  the feed consumer in place. Own test orders were removed and stock restored;
  payment configuration and email suppression remained unchanged.
- A genuine scheduled feed-linked bridge cycle completed at
  **2026-10-09T02:35:08+00:00**, evidenced by the worker log and
  `ul_dev_shopify_last_success`. Later manual verification left three products,
  cursor **7412**, and no worker error. This is a verified dev cadence, not a
  production service-level promise.
- Production remains at `5e920b074237b0f57dd0fd062ded9bc599c63ae5`; both supplier
  plugins are inactive and dropshipping is `no`. No production deployment or
  enablement occurred during this work.

### Fresh draft import verified, 2026-10-09

- Ran the product-only [fresh draft verifier](../ops/dropshipping/verify-shopify-draft.php)
  on `ultralove-dev-managed` against the existing Shopify development store.
  **23 live assertions passed**. No plugin/runtime code was changed.
- Existing published supplier product **17194**, SKU **70081**, had neither a
  local mapping nor a Shopify SKU/handle match before import. It created
  Shopify product **8424340586559**, handle `ultralove-dev-70081`, as **DRAFT**
  with no publication timestamp. The draft remains available for staff review:
  [open in Shopify admin](https://ultralove-integration-lab.myshopify.com/admin/products/8424340586559).
- Temporarily selected that product alongside the original three and used a
  **50% initial markup**: supplier price **CAD 1.85**, Shopify selling price
  **CAD 2.78**. Tracked inventory at **Shop location** was **720**.
- Feed title/description, supplier tag/vendor, simple-product default variant,
  and SKU matched. This product has **no barcode**; absence was preserved.
  Populated barcode transfer remains covered by isolated tests, not this live
  fixture. Variable products are outside the current connector's coverage.
- The single feed image reached **READY**, retained its feed alt text, and
  measured **351 x 431** pixels. An independent request to the Shopify CDN
  returned **HTTP 200**, `image/jpeg`, **39,065 bytes**. There were no missing
  image warnings for this product; missing-image behavior was not induced here.
- Repeated import preserved the product, variant and media IDs without a
  duplicate. Temporary retailer title, description and price edits survived
  another sync while the product remained unpublished; those edits were then
  restored to the initial imported values.
- The scheduled worker was paused only for the temporary selection and resumed
  afterward. Original settings were restored: IDs **17186, 17188, 17192**,
  original **0%** markup, and the same retailer. The new draft and its mapping
  are retained, but SKU **70081** is **deselected**, outside ongoing sync and
  the order-forwarding allowlist. Its stock is a test snapshot, not a live
  availability promise.
- Final checks at **2026-10-09T06:28Z** confirmed the restored selection, draft
  status, price/stock/media, no worker error, and active cron/Nginx/PHP-FPM.
  Original Shopify products and supplier status/stock/price were unchanged.
  Payment configuration was unchanged and development email suppression passed.
- Root-only evidence is retained in
  `/root/.ultralove-shopify-dev/fresh-draft-verification.log` and
  `fresh-draft-final-state.log` on dev. No orders, payments, emails, shipments,
  storefront publication, production deployment or production enablement were
  performed.

For another fresh verification, choose a different existing, eligible, unmapped
simple product. Pause the dev worker with an exit-trap restoration and back up
the non-secret supplier settings before running
`wp --allow-root eval-file - <new-product-id>` with the verifier on stdin.
The verifier refuses a running cron configuration or an already-mapped product,
restores selection and retailer edits, and retains the resulting unpublished
draft. Do not delete mappings to make the same product appear fresh.

## 7. Recovery and disconnect

Record what the retailer sees and does after a failed sync, credential revocation,
expired cursor, changed feed generation, or Shopify disconnection.

The feed can require a full resync when its generation changes or its retained
change history no longer covers the consumer's cursor. A resync must preserve
existing mappings and retailer edits. Respect rate limits and `Retry-After`;
do not report a failed or partial import as successful.

Document whether disconnecting leaves products in Shopify, how to reconnect
without duplicates, and how staff revoke feed access.

## 8. Separate dropshipping approval and fulfilment

Catalog access and product import do not authorize dropshipping.
Record the approved process for shipping charges, taxes, retailer payment,
discreet packaging, invoices/emails, tracking, and returns before a real pilot.

Use the [dropshipping runbook](dropshipping-pilot.md) for the current checkout
and warehouse requirements. Production enablement requires separate approval.

## 9. Order and tracking test

After the feed-driven catalog flow passes, document a complete synthetic test:
retailer order, supplier acknowledgement, duplicate prevention, customer
delivery address, retailer-only notifications, and tracking return.

Keep development email suppression and payment/network isolation intact.
Do not use real payments, customer recipients, or shipments. Distinguish manual
WooCommerce checkout from the lab-only Shopify test-order connector.

## Evidence to append after each step

For each completed step, record:

- Date, environment, and tested connector revision.
- Exact menu labels, configuration fields, and user actions.
- Expected result and observed result.
- Sanitized screenshots or test references, where useful.
- Failure/retry/disconnect instructions.
- Whether the action belongs to the retailer, UltraLove staff, or the integrator.

Never record passwords, tokens, client secrets, authorization headers, or real
customer/order data. Keep operational credentials out of the retailer guide.

Technical setup references:
[dev feed and Shopify workflow](../ops/dev/README.md) and
[architecture](ARCHITECTURE.md).
