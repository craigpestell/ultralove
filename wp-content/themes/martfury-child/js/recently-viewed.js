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
            viewed_products: ids.join('|')
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

    $(function () {
        trackCurrentProduct();
        syncContainerState();
    });
})(jQuery);