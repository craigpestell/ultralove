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

This is an operator-backed development connection, not yet an embedded retailer
product-picker UI. Implementation and isolated tests do not establish live
deployment or import success; record those results separately below.

Keep staff setup separate from actions a retailer can actually perform.
Do not describe a lab-only administrator control as a retailer-facing screen.

| Step | Actor | Action to document | Current status |
|---|---|---|---|
| 1 | UltraLove staff / retailer | Grant catalog access and issue dedicated, revocable feed credentials | Dedicated dev credential and actual revocation verified; external retailer onboarding pending |
| 2 | Integrator / test operator | Configure the existing Shopify test app's connector to read the feed | Dev adapter deployed; authenticated feed pull and scheduled cycle verified |
| 3 | Retailer | Choose products from the published catalog | Staff's three-product dev selection verified; retailer-facing selection UI pending |
| 4 | Retailer / connector | Set initial retail prices and import selected products as drafts | Fresh live draft, initial markup and media verified; populated barcode mapping remains isolated-tested |
| 5 | Retailer | Review images, descriptions, SKUs, variants, prices, and availability | Fresh live simple-product draft data/media and retailer-edit preservation verified; retailer UI walkthrough and variable-product support pending |
| 6 | Connector | Synchronize inventory and changes without overwriting retailer edits | Live stock, price, content and publication-state preservation verified |
| 7 | Integrator / retailer | Recover from interruptions, removals, expired cursors, and revoked access | Live withdrawal/republication, 409 resync, removed scope and revoked password verified; rate-limit/transport failures isolated-tested |
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

Record the exact search/filter/selection controls that the consumer supports,
how selections are saved, and how a retailer adds or removes products later.

Retailers may select any published UltraLove catalog product, including
out-of-stock products. Listing permission is separate from order availability.
Do not carry the current connector's 25-product/simple-product lab limits into
the feed's business rules. Document any unsupported product types explicitly.

Use stable supplier product/variation IDs for synchronization; record SKUs as
well. Do not match products by title alone.

## 4. Initial import and pricing

Record the initial price or markup controls, currency checks, duplicate handling,
and where Shopify displays the import result and failures.

Verify that products arrive as drafts before any publication. Check titles,
descriptions, images, SKU/barcode mappings, variant options, and stock ownership.
Surface missing-image warnings rather than silently omitting products.
Supplier prices must not be mistaken for the retailer's selling prices.

The consumer must save the first catalog page's generation and change cursor,
complete pagination, and then replay changes to catch updates made during the
initial import. Record how the user knows the import has finished.

## 5. Review and publication

Capture the exact Shopify screens and checks for reviewing imported drafts,
editing retail prices/content, and choosing sales channels.

Do not publish test products to a real storefront as part of verification.
Retailer publication instructions remain pending until the intended consumer
workflow has been tested.

## 6. Inventory and change synchronization

Record the configured synchronization interval, last-success indicator,
stock location, stock interpretation, and visible error messages.

Verify:

- Stock changes reach the correct Shopify product/variant and inventory location.
- Retailer-edited prices and descriptions remain intact.
- Out-of-stock and unknown stock are handled explicitly; unknown stock is not
  silently treated as available stock.
- A retailer's deselection stops synchronization without deleting their product.
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
