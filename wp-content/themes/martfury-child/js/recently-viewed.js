(function ($) {
    var config = window.ulRecentlyViewed || {};
    var storageKey = config.storageKey || 'ultralove_recently_viewed';
    var maxItems = parseInt(config.maxItems, 10) || 100;

    function normalizeIds(ids) {
        var seen = {};

        return ids
            .map(function (value) {
                return parseInt(value, 10);
            })
            .filter(function (value) {
                if (!value || seen[value]) {
                    return false;
                }

                seen[value] = true;
                return true;
            });
    }

    function readIds() {
        var rawValue;

        try {
            rawValue = window.localStorage.getItem(storageKey);
        } catch (error) {
            return [];
        }

        if (!rawValue) {
            return [];
        }

        try {
            return normalizeIds(JSON.parse(rawValue));
        } catch (error) {
            return normalizeIds(rawValue.split('|'));
        }
    }

    function writeIds(ids) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(normalizeIds(ids)));
        } catch (error) {
            // Ignore storage failures and leave the feature inactive.
        }
    }

    function trackCurrentProduct() {
        var productId = parseInt(config.productId, 10);
        var ids;

        if (!productId) {
            return;
        }

        ids = readIds().filter(function (value) {
            return value !== productId;
        });
        ids.push(productId);

        if (ids.length > maxItems) {
            ids = ids.slice(ids.length - maxItems);
        }

        writeIds(ids);
    }

    function syncContainerState() {
        var hasProducts = readIds().length > 0;

        ['#footer-recently-viewed', '#footer-bot-recently-viewed', '#header-recently-viewed'].forEach(function (selector) {
            var $container = $(selector);

            if (!$container.length) {
                return;
            }

            $container.toggleClass('no-products', !hasProducts);
        });
    }

    function isRecentlyViewedRequest(url) {
        return /martfury_(footer|header)_recently_viewed/.test(url || '');
    }

    function appendData(existingData, ids) {
        var payload = {
            viewed_products: ids.join('|'),
            current_product: parseInt(config.productId, 10) || 0
        };

        if (!existingData) {
            return $.param(payload);
        }

        if (typeof existingData === 'string') {
            return existingData + '&' + $.param(payload);
        }

        if ($.isPlainObject(existingData)) {
            return $.extend({}, existingData, payload);
        }

        return existingData;
    }

    $.ajaxPrefilter(function (options, originalOptions) {
        var ids;

        if (!isRecentlyViewedRequest(options.url)) {
            return;
        }

        ids = readIds();
        options.data = appendData(options.data || originalOptions.data, ids);
    });

    // The footer block now holds standard product cards (see ultralove_render_recently_viewed_cards()
    // in functions.php); once Martfury's AJAX handler has inserted them, make them a carousel with the
    // same settings as the related products carousel.
    function initCardsCarousel() {
        var $list = $('#footer-recently-viewed ul.products, #footer-bot-recently-viewed ul.products').not('.slick-initialized');

        if (!$list.length || !$.fn.slick) {
            return;
        }

        $list.slick({
            rtl: (window.martfuryData && martfuryData.direction === 'true'),
            slidesToShow: 5,
            slidesToScroll: 5,
            arrows: true,
            dots: true,
            infinite: false,
            prevArrow: '<span class="icon-chevron-left slick-prev-arrow"></span>',
            nextArrow: '<span class="icon-chevron-right slick-next-arrow"></span>',
            responsive: [
                {breakpoint: 1200, settings: {slidesToShow: 4, slidesToScroll: 4}},
                {breakpoint: 992, settings: {slidesToShow: 3, slidesToScroll: 3}},
                {breakpoint: 767, settings: {slidesToShow: 2, slidesToScroll: 2}}
            ]
        });

        $list.on('afterChange', function () {
            if (window.martfury && martfury.lazyLoad) {
                martfury.lazyLoad();
            }
        });
    }

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (/martfury_footer_recently_viewed/.test(settings.url || '')) {
            initCardsCarousel();
        }
    });

    // The Quantity Field on Shop Page plugin copies the quantity onto the Add to cart button's
    // data-quantity with a handler bound to the forms present at page load, so it misses cards that
    // arrive by AJAX; do the same for the recently viewed cards.
    $(document.body).on('change input', '#footer-recently-viewed form.cart input.qty, #footer-bot-recently-viewed form.cart input.qty', function () {
        $(this.form).find('[data-quantity]').attr('data-quantity', this.value);
    });

    $(function () {
        trackCurrentProduct();
        syncContainerState();
    });
})(jQuery);