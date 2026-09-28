/**
 * Martfury Product Deals Carousel (Elementor widget - the home page's "On Sale" carousel) as the child
 * theme's product carousel, the same one as related products, upsells and recently viewed
 * (js/product-carousel.js, .ul-carousel in style.css): native scrolling, the round previous/next
 * buttons, the progress bar, drag to scroll, and the product grid's number of cards across.
 *
 * martfury-addons' handler turns the list into a Slick slider; after it has run (a later priority on the
 * same Elementor hook) the slider is removed and the list wrapped in the carousel's markup instead.
 */
(function ($) {
    'use strict';

    function useProductCarousel($scope) {
        if (!window.ulProductCarousel) {
            return;
        }

        $scope.find('.mf-elementor-product-deals-carousel ul.products').each(function () {
            var $list = $(this);

            if ($list.hasClass('slick-initialized')) {
                $list.slick('unslick');
            }

            if (!$list.parent().is('.ul-carousel')) {
                $list.wrap('<div class="ul-carousel" data-ul-carousel></div>');
            }

            window.ulProductCarousel.init($list.parent()[0]);
        });
    }

    $(window).on('elementor/frontend/init', function () {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/martfury-product-deals-carousel.default', useProductCarousel, 20);
    });
}(jQuery));
