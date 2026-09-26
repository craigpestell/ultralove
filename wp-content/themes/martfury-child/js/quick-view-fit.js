/*
 * Quick view popup: one column (name / brand / SKU, image, then price and the cart row) when it fits in
 * the window, two columns (image left; header and summary right) when it doesn't. Whether it fits
 * depends on the product (description length, variations, image shape), so it is measured rather than
 * set by a media query: with the one-column layout applied, .mf-product-detail is capped at the window
 * height (see style.css), and if its content is taller than that the popup gets .ul-qv-columns.
 * Re-checked when a product loads, when its images or summary change size, and on window resize.
 * Only at >=992px; below that the parent theme's side sheet scrolls as a whole.
 */
(function ($) {
    var modal = document.getElementById('mf-quick-view-modal');

    if (!modal || !window.MutationObserver) {
        return;
    }

    var content = modal.querySelector('.product-modal-content');
    var columnsClass = 'ul-qv-columns';
    var minWidth = 992;
    var pending = false;
    var resizeObserver = window.ResizeObserver ? new ResizeObserver(schedule) : null;

    if (!content) {
        return;
    }

    function fit() {
        pending = false;

        var detail = content.querySelector('.mf-product-detail');
        var hadColumns = modal.classList.contains(columnsClass);
        var needsColumns = false;

        if (detail && window.innerWidth >= minWidth) {
            // Measure the one-column layout; the class comes back in the same frame, so nothing flickers.
            modal.classList.remove(columnsClass);
            needsColumns = detail.scrollHeight > detail.clientHeight + 1;
        }

        modal.classList.toggle(columnsClass, needsColumns);

        // The gallery's width changed: slick only re-measures on window resize, so tell it.
        if (needsColumns !== hadColumns && $.fn.slick) {
            $(content).find('.woocommerce-product-gallery__wrapper.slick-initialized').slick('setPosition');
        }
    }

    function schedule() {
        if (!pending) {
            pending = true;
            window.requestAnimationFrame(fit);
        }
    }

    // Martfury replaces the popup's contents on each quick view (see productQuickView in martfury/js/scripts.js).
    new MutationObserver(function () {
        if (resizeObserver) {
            resizeObserver.disconnect();
            $(content).find('.woocommerce-product-gallery, .entry-summary').each(function () {
                resizeObserver.observe(this);
            });
        }
        schedule();
    }).observe(content, { childList: true });

    // Images finishing loading (load doesn't bubble, so listen in the capture phase).
    modal.addEventListener('load', schedule, true);
    window.addEventListener('resize', schedule);
})(jQuery);
