# Product-card wishlist hearts

Product cards render a state-neutral heart in the child theme's
`inc/product-card-wishlist.php`, through `martfury_after_product_loop_thumbnail_link`.
The same markup is used for guests and signed-in users; rendering does not read a
wishlist. Existing theme rules provide the outline/filled-heart presentation.
Guest login restrictions remain respected.

`js/product-card-wishlist.js` requests `action=ultralove_wishlist_state` once when
cards are present. The POST uses YITH's existing `load_fragments` nonce and returns
only the current visitor's saved product IDs:

```json
{"success":true,"data":{"product_ids":[123,456]}}
```

This replaces the product cards' former `load_fragments` response containing full
button HTML for every card. There is still one WordPress/PHP state request for all
visitors, including guests; this is not an elimination of all AJAX or of WordPress
bootstrap cost. Its response is explicitly non-cacheable. The handler never accepts
an owner or wishlist ID from the browser.

YITH continues to own storage, guest sessions, persistence, add/remove requests,
the wishlist page and non-card buttons such as the single-product/quick-view button.
No vendor plugin edits or data migration are required. Cards are not
`.wishlist-fragment` elements, so YITH does not render them in its fragment calls,
including mutation responses. A saved card links to the wishlist, as before.

The script applies the same state to cards inserted later, including recently
viewed products and AJAX filters. After YITH additions/removals it refreshes the
state; overlapping refreshes are serialized and followed by one queued reload.
Browser back/forward-cache restores also refresh the state. Failed or malformed
responses produce a console error, a reload instruction in the heart tooltip,
and an `ultralove_wishlist_state_error` event rather than being accepted as an
empty wishlist.

## Validation and release

- Run `php ops/wishlist/test.php`, PHP lint and JavaScript syntax checks.
- On dev, check initial HTML for server-rendered hearts, and verify that card
  loading requests compact state rather than bulk `load_fragments` HTML.
- Verify guest add, reload, saved-link navigation and removal; repeat for an
  account, and for duplicate cards and dynamically inserted cards.
- Compare request size and timing against the previous fragment handler.
- No CSS rules or database settings change. The first-screen markup still needs
  the normal critical CSS workflow checked before production.
- Roll back by deploying the preceding revision; saved wishlists stay in YITH.

### Local validation against the dev runtime (2026-10-13)

No deployment was performed. A temporary loopback-only preview passed the local
module and script through the real dev WordPress/YITH runtime without writing
server code. The 40-card shop response contained all 40 hearts in its HTML, made
one compact state request and made no product-card `load_fragments` request.
The prior guest fragment response was 81,415 bytes; empty compact state was 42
bytes, and one saved product was 47 bytes (all sizes uncompressed).

Real guest add, reload persistence, saved-link navigation and removal passed.
Duplicate and dynamically inserted cards, a page whose first card arrives later,
malformed-response notification, and queued refresh handling passed browser
checks. Read-only authenticated runtime checks matched YITH's native membership
for an existing account's owned default list and rejected an invalid nonce.
The guest test product was removed afterward; account data was not changed.

A same-runtime guest sample measured roughly 3 ms to resolve compact state
versus 164 ms to render 40 YITH fragments, excluding WordPress bootstrap and
network time. This is a handler-work comparison, not a claim that total AJAX
latency becomes 3 ms. The initial hearts no longer wait for that response.

The offline regression suite contains 27 assertions and runs in CI. Guarded dev
deployment and ordinary Autoptimize/critical-CSS release validation remain
required before shipping.
