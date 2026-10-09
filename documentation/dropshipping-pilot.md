# Dropshipping rollout and operations

UltraLove fulfils an approved retailer's order directly to one customer.
This milestone uses classic WooCommerce checkout, not CSV order import or
Shopify integration. Catalogue exports remain separate.

The [retailer product feed setup notes](retailer-product-feed-guide.md) track
the feed-to-Shopify walkthrough and its separate dropshipping steps. They
distinguish verified behaviour from instructions still awaiting consumer tests.

## Enable on dev

Deploy the committed branch with `deploy/deploy.sh dev --ref <branch> --dry-run`,
then `deploy/deploy.sh dev --ref <branch> --yes`. Activate
**UltraLove Dropshipping** in Plugins, then open **WooCommerce > Dropshipping**.
Check **Enable dropshipping** and save.
The option defaults to `no` on each site. Development and production are
supported; other environment types cannot enable the feature.

The Dropshipping page links to Users, approved accounts, and WooCommerce orders.
When enabled, an administrator edits a retailer in Users and checks the
dropshipping approval. While disabled, user-profile approval fields are hidden
and approval changes are rejected server-side, including stale submissions.
Disabling the feature preserves existing approvals but blocks all dropshipping.
Existing accounts are not approved automatically. Removing approval
blocks new dropship orders, including checkout submissions already in progress.

Do not change dev's email suppression, outbound HTTP block, webhook isolation,
or payment configuration. Use synthetic addresses and an offline gateway for
tests; never remit funds or dispatch a test order.

## Production deployment, initially disabled

1. Pass CI and verify the release candidate on dev.
2. Merge its reviewed PR into main. Confirm the automatic dev deployment
   succeeded and verify that exact merge SHA, not just the earlier branch SHA.
3. Review `deploy/deploy.sh prod --ref <verified-main-sha> --dry-run`, then
   deploy through the same pipeline. Retain the preceding SHA and backup path.
   Do not bypass drift checks or the exact-SHA dev gate.
4. Set `ultralove_dropshipping_enabled` to `no` on production before activating
   **UltraLove Dropshipping**. Plugin activation is not feature enablement.
5. Confirm **WooCommerce > Dropshipping** exists with its switch unchecked.
   Confirm user profiles have no approval checkbox, ordinary checkout has no
   dropshipping opt-in/script, and the production approval baseline is unchanged.

Do not copy dev's options or user approvals. Do not create production test orders,
send test emails, take payments, change customer addresses, or disable normal
production mail/webhooks. Use read-only rendering and service/log checks for the
disabled release. Feature enablement requires separate approval and review of
production notification/invoice integrations and warehouse readiness.

## Retailer checkout

Approved retailers can select **Dropship this order directly to my customer**.
Keep billing details as the retailer's and supply the customer's complete
shipping address. The saved retailer billing email is required and receives
customer-facing order notifications. Billing email changes must first be made
in the retailer's account.

Existing prices, shipping methods/rates, inventory rules, taxes, and payment
choices are unchanged. No handling fee is added. Shipping is calculated for
the parcel destination. An order belongs to the retailer and has one delivery
address. Recipient addresses are not saved to the retailer's account profile.
Ordinary wholesale orders remain available without opting in.
The optional dropshipping checkbox uses the same shared checkout styling as
the terms-and-conditions checkbox, including checked and keyboard-focus states.
Newly revealed recipient fields start without validation errors; ordinary
WooCommerce validation still runs on user interaction and checkout submission.
While dropshipping is selected, the redundant **Ship to a different address?**
toggle is hidden; the separate delivery address remains enabled. Opting out
restores the normal toggle and destination choice.
Recipient fields appear in a separate bordered **Customer delivery details**
section, distinct from retailer billing. Its heading and styling disappear
when opting out; ordinary shipping fields keep their existing appearance.

## Warehouse checklist

Dropship orders display **DROPSHIP** instructions in the admin order's shipping
section, staff emails, and retailer order details/emails.

1. Confirm payment/dispatch eligibility using the normal order workflow.
2. Use the order's customer delivery address, not the retailer billing address.
3. Pack discreetly, without UltraLove or retailer branding.
4. Do not put a wholesale invoice or wholesale pricing in the parcel.
5. Send invoices and customer-facing order emails to the retailer only.
6. Follow the ordinary carrier/returns workflow; tracking automation is not added.

There is no active invoice/packing-slip plugin on dev. Packing is a manual
warehouse requirement, not something this plugin can enforce physically.
Staff must confirm this workflow before any real pilot fulfilment.

## Verification and rollback

Run `php ops/dropshipping/test-gate.php` locally for isolated environment and
default-off policy coverage. It does not connect to WordPress or a database.

Run `ops/dropshipping/verify.php` via `wp eval-file` **on dev only**, after deployment and
activation. It creates and deletes only its own synthetic users, product, and
order, and restores the prior pilot option. Run browser checks for two different
recipient addresses followed by ordinary checkout, plus unauthorized and
revoked approvals. Confirm retailer profile isolation, normal shipping totals,
order metadata, and email recipients without sending mail.
Compare the dropshipping and terms checkboxes on desktop and mobile: unchecked,
checked, keyboard focus, label clicks, and wrapping. Confirm dropshipping
remains optional and its selection survives checkout updates.

Disable new dropship checkouts by unchecking the pilot switch on
**WooCommerce > Dropshipping** and saving. For command-line recovery,
use `wp option update ultralove_dropshipping_enabled no`.
Existing order markers and notification protection remain while the plugin is
active. Prefer disabling the pilot over deactivating/removing the plugin when
marked orders still need handling. Code rollback uses the deploy pipeline;
never delete existing orders as part of rollback.

Production activation is initially disabled. Before enabling, review destinations,
eligible products, payment policy, tax treatment, handling fees, service levels,
returns, production notification/invoice integrations, and physical fulfilment
responsibilities. Account approval alone cannot enable the feature.
