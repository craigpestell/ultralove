/**
 * Product cards - the whole card (.product-inner, padding included) links to the product page, not just
 * the thumbnail image and the title.
 *
 * Done with a click handler rather than a CSS overlay link because an overlay would have to escape
 * .mf-product-thumbnail's position:relative, which the Quick View / wishlist bar is positioned against.
 * Clicks on the card's own controls (links, the quantity box and Add to cart form, the Quick View /
 * wishlist bar) keep doing what they do. Ctrl/Cmd/Shift-click and middle-click open a new tab, as a
 * link would. A click the carousel cancelled (the end of a drag-to-scroll) or that ends a text
 * selection doesn't navigate. Keyboard users already reach the product through the card's links.
 */
(function () {
    'use strict';

    var CARD = 'ul.products li.product .product-inner';
    var CONTROLS = 'a, button, input, select, textarea, label, form, [role="button"], .quantity, .footer-button, .mf-product-details-hover';

    function productUrl(card) {
        var link = card.querySelector('.mf-product-thumbnail a[href], .woo-loop-product__title a[href]');
        return link ? link.href : null;
    }

    function cardFor(event) {
        if (event.defaultPrevented || !event.target.closest) {
            return null;
        }
        var card = event.target.closest(CARD);
        if (!card || event.target.closest(CONTROLS)) {
            return null;
        }
        var selection = window.getSelection ? window.getSelection() : null;
        if (selection && !selection.isCollapsed && card.contains(selection.anchorNode)) {
            return null;
        }
        return card;
    }

    document.addEventListener('click', function (event) {
        if (event.button !== 0) {
            return;
        }
        var card = cardFor(event);
        var url = card && productUrl(card);
        if (!url) {
            return;
        }
        if (event.ctrlKey || event.metaKey || event.shiftKey) {
            window.open(url, '_blank', 'noopener');
        } else {
            window.location.href = url;
        }
    });

    // Middle-click.
    document.addEventListener('auxclick', function (event) {
        if (event.button !== 1) {
            return;
        }
        var card = cardFor(event);
        var url = card && productUrl(card);
        if (url) {
            event.preventDefault();
            window.open(url, '_blank', 'noopener');
        }
    });
}());
