/**
 * Martfury Product Deals Carousel (Elementor widget, a Slick slider - on the home page) - the same number
 * of cards across as the product grid at every width: 2, 3 from 768px, 4 from 1200px, 5 from 1400px
 * (the grid's col-xs-6 / col-sm-4 / col-mf-5 and the child theme's 4-column band in style.css), never
 * more than the widget's own "slides to show".
 *
 * martfury-addons' handler hardcodes its own breakpoints (5 up to 1366px, 4 below 1200px, 3 below 992px),
 * so after it has run (a later priority on the same Elementor hook) the slider is restarted with the
 * grid's. Everything else - autoplay, infinite, dots - is kept from its settings. Slick's
 * breakpoints apply below the given width and use window.innerWidth, like the CSS media queries.
 *
 * Its previous/next arrows become the same round buttons as the other product carousels' (see
 * js/product-carousel.js and .ul-carousel__arrow in style.css) instead of Martfury's icon-font chevrons.
 */
(function ($) {
    'use strict';

    var CHEVRON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' +
        '<path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function arrow(dir, label) {
        return '<button type="button" class="ul-btn ul-btn--primary ul-btn--icon ul-carousel__arrow ul-carousel__arrow--' + dir +
            '" aria-label="' + label + '">' + CHEVRON + '</button>';
    }

    function matchGridColumns($scope) {
        $scope.find('.mf-elementor-product-deals-carousel ul.products.slick-initialized').each(function () {
            var $list = $(this);
            var slick = $list.slick('getSlick');
            var settings = slick.originalSettings;
            var show = parseInt(settings.slidesToShow, 10) || 5;
            var scroll = parseInt(settings.slidesToScroll, 10) || show;

            function at(breakpoint, columns) {
                return {
                    breakpoint: breakpoint,
                    settings: {slidesToShow: Math.min(show, columns), slidesToScroll: Math.min(scroll, columns)}
                };
            }

            $list.slick('unslick').slick($.extend({}, settings, {
                slidesToShow: Math.min(show, 5),
                slidesToScroll: Math.min(scroll, 5),
                prevArrow: arrow('prev', 'Previous products'),
                nextArrow: arrow('next', 'Next products'),
                responsive: [at(1400, 4), at(1200, 3), at(768, 2)]
            }));
        });
    }

    $(window).on('elementor/frontend/init', function () {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/martfury-product-deals-carousel.default', matchGridColumns, 20);
    });
}(jQuery));
