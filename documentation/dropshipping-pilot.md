# Dropshipping development pilot

UltraLove fulfils an approved retailer's order directly to one customer.
This milestone uses classic WooCommerce checkout, not CSV order import or
Shopify integration. Catalogue exports remain separate.

## Enable on dev

Deploy the committed branch with `deploy/deploy.sh dev --ref <branch> --dry-run`,
then `deploy/deploy.sh dev --ref <branch> --yes`. Activate
**UltraLove Dropshipping** in Plugins, then open **WooCommerce > Dropshipping**.
Check **Enable the dropshipping pilot on this development site** and save.
The option defaults to `no`; the checkout feature refuses to
run outside `WP_ENVIRONMENT_TYPE=development`.

The Dropshipping page links to Users, approved accounts, and WooCommerce orders.
An administrator edits a retailer in Users and checks the dropshipping approval.
Existing accounts are not approved automatically. Removing approval
blocks new dropship orders, including checkout submissions already in progress.

Do not change dev's email suppression, outbound HTTP block, webhook isolation,
or payment configuration. Use synthetic addresses and an offline gateway for
tests; never remit funds or dispatch a test order.

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

Run `ops/dropshipping/verify.php` via `wp eval-file` on dev after deployment and
activation. It creates and deletes only its own synthetic users, product, and
order, and restores the prior pilot option. Run browser checks for two different
recipient addresses followed by ordinary checkout, plus unauthorized and
revoked approvals. Confirm retailer profile isolation, normal shipping totals,
order metadata, and email recipients without sending mail.

Disable new dropship checkouts by unchecking the pilot switch on
**WooCommerce > Dropshipping** and saving. For command-line recovery,
use `wp option update ultralove_dropshipping_enabled no`.
Existing order markers and notification protection remain while the plugin is
active. Prefer disabling the pilot over deactivating/removing the plugin when
marked orders still need handling. Code rollback uses the deploy pipeline;
never delete existing orders as part of rollback.

Production release is excluded. Before it, review the development-only gate,
destinations, eligible products, payment policy, tax treatment, handling fees,
service levels, returns, and physical fulfilment responsibilities.
