/**
 * Product carousel - related products, upsells and the footer's recently viewed products
 * (.ul-carousel[data-ul-carousel] around a standard ul.products list; styles in style.css).
 *
 * The list itself is the scroller (native horizontal scrolling with scroll-snap), so touch swipe,
 * trackpads and the browser's own keyboard/focus scrolling all work, and the cards keep the product
 * grid's CSS. This script adds what makes it read as a carousel and drive like one:
 *  - previous/next buttons that page by the number of whole cards in view (hidden at either end),
 *  - a progress bar under the cards (click to jump, drag the thumb to scrub),
 *  - start/end state classes for the fade on whichever edge has more cards,
 *  - mouse drag-to-scroll (a drag doesn't also click the card under the pointer),
 *  - left/right arrow keys while focus is inside the carousel.
 * Motion is instant for visitors who ask for reduced motion.
 * window.ulProductCarousel.initAll(scope) sets up carousels added later (recently viewed arrives by AJAX).
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var count = 0;

    var CHEVRON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' +
        '<path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function scrollBehavior() {
        return reduceMotion && reduceMotion.matches ? 'auto' : 'smooth';
    }

    function makeButton(dir, label, controls) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'ul-btn ul-btn--tertiary ul-btn--icon ul-carousel__arrow ul-carousel__arrow--' + dir;
        button.setAttribute('aria-label', label);
        button.setAttribute('aria-controls', controls);
        button.innerHTML = CHEVRON;
        return button;
    }

    function init(root) {
        var track = root && root.querySelector('ul.products');

        if (!track || root.ulCarousel) {
            return;
        }

        var carousel = root.ulCarousel = {};
        var prev, next, bar, thumb, frame = 0, drag = null, suppressClick = false;

        track.id = track.id || 'ul-carousel-' + (++count);
        root.setAttribute('role', 'region');
        root.setAttribute('aria-roledescription', 'carousel');

        prev = makeButton('prev', 'Previous products', track.id);
        next = makeButton('next', 'Next products', track.id);
        bar = document.createElement('div');
        bar.className = 'ul-carousel__progress';
        bar.setAttribute('aria-hidden', 'true');
        thumb = document.createElement('span');
        thumb.className = 'ul-carousel__thumb';
        bar.appendChild(thumb);
        root.appendChild(prev);
        root.appendChild(next);
        root.appendChild(bar);

        function cards() {
            return track.querySelectorAll(':scope > li.product');
        }

        // One card's width (they're all the same) and how many whole cards fit in view.
        function cardWidth() {
            var first = cards()[0];
            return first ? first.getBoundingClientRect().width : track.clientWidth;
        }

        function pageWidth() {
            var width = cardWidth();
            return Math.max(1, Math.floor((track.clientWidth - 20) / width)) * width;
        }

        function maxScroll() {
            return Math.max(0, track.scrollWidth - track.clientWidth);
        }

        function update() {
            frame = 0;

            var max = maxScroll();
            var x = Math.min(Math.max(track.scrollLeft, 0), max);
            var scrollable = max > 2;
            var atStart = x <= 2;
            var atEnd = x >= max - 2;
            var ratio = scrollable ? track.clientWidth / track.scrollWidth : 1;
            var progress = scrollable ? x / max : 0;

            root.classList.toggle('is-scrollable', scrollable);
            root.classList.toggle('is-at-start', atStart);
            root.classList.toggle('is-at-end', atEnd);
            prev.disabled = !scrollable || atStart;
            next.disabled = !scrollable || atEnd;

            thumb.style.width = (ratio * 100) + '%';
            thumb.style.transform = 'translateX(' + (progress * (1 - ratio) / ratio * 100) + '%)';
        }

        function requestUpdate() {
            if (!frame) {
                frame = window.requestAnimationFrame(update);
            }
        }

        function scrollByCards(direction, amount) {
            track.scrollBy({left: direction * amount, behavior: scrollBehavior()});
        }

        // After a drag (which turns snapping off) settle on the nearest card edge.
        function settle() {
            var width = cardWidth();
            track.scrollTo({left: Math.round(track.scrollLeft / width) * width, behavior: scrollBehavior()});
        }

        prev.addEventListener('click', function () {
            scrollByCards(-1, pageWidth());
        });

        next.addEventListener('click', function () {
            scrollByCards(1, pageWidth());
        });

        root.addEventListener('keydown', function (event) {
            if ((event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') || event.target.closest('input, select, textarea')) {
                return;
            }
            event.preventDefault();
            scrollByCards(event.key === 'ArrowLeft' ? -1 : 1, cardWidth());
        });

        // Mouse drag-to-scroll. Touch and pens scroll natively; form controls in the cards are left alone.
        track.addEventListener('pointerdown', function (event) {
            if (event.pointerType !== 'mouse' || event.button !== 0 || !root.classList.contains('is-scrollable') ||
                event.target.closest('input, button, select, textarea, label')) {
                return;
            }
            drag = {x: event.clientX, left: track.scrollLeft, moved: false, id: event.pointerId};
        });

        track.addEventListener('pointermove', function (event) {
            if (!drag || event.pointerId !== drag.id) {
                return;
            }
            var dx = event.clientX - drag.x;
            if (!drag.moved && Math.abs(dx) > 5) {
                drag.moved = true;
                root.classList.add('is-dragging');
                track.setPointerCapture(event.pointerId);
            }
            if (drag.moved) {
                track.scrollLeft = drag.left - dx;
            }
        });

        function endDrag() {
            if (!drag) {
                return;
            }
            if (drag.moved) {
                suppressClick = true;
                window.setTimeout(function () {
                    suppressClick = false;
                }, 0);
                root.classList.remove('is-dragging');
                settle();
            }
            drag = null;
        }

        track.addEventListener('pointerup', endDrag);
        track.addEventListener('pointercancel', endDrag);
        track.addEventListener('lostpointercapture', endDrag);

        track.addEventListener('click', function (event) {
            if (suppressClick) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);

        // Links and images would otherwise start a native drag-and-drop instead of the scroll.
        track.addEventListener('dragstart', function (event) {
            event.preventDefault();
        });

        // Progress bar: click to jump there, drag the thumb to scrub.
        bar.addEventListener('pointerdown', function (event) {
            if (event.button !== 0) {
                return;
            }
            var rect = bar.getBoundingClientRect();
            var thumbRect = thumb.getBoundingClientRect();
            var scale = track.scrollWidth / rect.width;

            event.preventDefault();

            if (event.target !== thumb) {
                var target = (event.clientX - rect.left - thumbRect.width / 2) / (rect.width - thumbRect.width);
                track.scrollTo({left: Math.min(Math.max(target, 0), 1) * maxScroll(), behavior: scrollBehavior()});
                return;
            }

            var startX = event.clientX;
            var startLeft = track.scrollLeft;

            root.classList.add('is-dragging');
            bar.setPointerCapture(event.pointerId);

            function move(moveEvent) {
                track.scrollLeft = startLeft + (moveEvent.clientX - startX) * scale;
            }

            function up() {
                bar.removeEventListener('pointermove', move);
                bar.removeEventListener('pointerup', up);
                bar.removeEventListener('pointercancel', up);
                root.classList.remove('is-dragging');
                settle();
            }

            bar.addEventListener('pointermove', move);
            bar.addEventListener('pointerup', up);
            bar.addEventListener('pointercancel', up);
        });

        track.addEventListener('scroll', requestUpdate, {passive: true});
        window.addEventListener('resize', requestUpdate);
        if (window.ResizeObserver) {
            new ResizeObserver(requestUpdate).observe(track);
        }

        carousel.update = update;
        update();
    }

    function initAll(scope) {
        Array.prototype.forEach.call((scope || document).querySelectorAll('[data-ul-carousel]'), init);
    }

    window.ulProductCarousel = {init: init, initAll: initAll};

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAll();
        });
    } else {
        initAll();
    }
})();
