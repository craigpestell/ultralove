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

Keep staff setup separate from actions a retailer can actually perform.
Do not describe a lab-only administrator control as a retailer-facing screen.

| Step | Actor | Action to document | Current status |
|---|---|---|---|
| 1 | UltraLove staff / retailer | Grant catalog access and issue dedicated, revocable feed credentials | Dedicated dev credential and actual revocation verified; external retailer onboarding pending |
| 2 | Integrator / test operator | Configure the existing Shopify test app's connector to read the feed | Dev adapter deployed; authenticated feed pull and scheduled cycle verified |
| 3 | Retailer | Choose products from the published catalog | Staff's three-product dev selection verified; retailer-facing selection UI pending |
| 4 | Retailer / connector | Set initial retail prices and import selected products as drafts | New creation/markup/media/barcode verified in isolated bridge tests; live existing mappings reused |
| 5 | Retailer | Review images, descriptions, SKUs, variants, prices, and availability | Live SKU/stock and retailer-edit preservation verified; new live draft/media review pending |
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

- Staff grant access under **Users > Edit user > Supplier catalog API**.
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
