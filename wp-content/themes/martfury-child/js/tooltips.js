/**
 * Tooltips - the dark labels over the product card's Quick View and wishlist icons (and the same icons on
 * product pages, in Quick View and in the catalog sidebar), showing the icon's title.
 *
 * Registered under `jquery-ui-tooltip`, the handle Martfury's script depends on (see functions.php), so the
 * site doesn't load jQuery UI (~27 KB) for them. Martfury's own .tooltip() calls do nothing; one delegated
 * listener covers every icon, including ones YITH re-renders and the ones in Quick View. Looks the way
 * jQuery UI's did: the parent theme's .martfury-tooltip markup and styles, centred 13px above the icon
 * (below it when there's no room), fading in and out over 400ms, and the icon's title is set aside while
 * the tooltip shows so the browser doesn't show its own as well.
 */
(function ($) {
    'use strict';

    var TARGETS = '.catalog-sidebar [data-rel=tooltip], .mf-product-thumbnail [data-rel=tooltip], ' +
        '.mf-single-product:not(.mf-product-layout-3) [data-rel=tooltip], #mf-quick-view-modal [data-rel=tooltip]';
    var GAP = 13;
    var FADE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 400;

    var tip = document.createElement('div');
    var content = document.createElement('div');
    var target = null;
    var fadeOut = null;

    tip.id = 'ul-tooltip';
    tip.setAttribute('role', 'tooltip');
    tip.className = 'martfury-tooltip ui-tooltip';
    tip.style.transition = 'opacity ' + FADE + 'ms';
    content.className = 'ui-tooltip-content';
    tip.appendChild(content);

    $.fn.tooltip = function () {
        return this;
    };

    function place(el) {
        var rect = el.getBoundingClientRect();
        var root = document.documentElement;
        var width = tip.offsetWidth;
        var height = tip.offsetHeight;
        var left = rect.left + rect.width / 2 - width / 2;
        var top = rect.top - GAP - height;

        if (top < 0) {
            top = rect.bottom + GAP;
        }
        left = Math.min(Math.max(left, 0), root.clientWidth - width);

        tip.style.left = left + window.pageXOffset + 'px';
        tip.style.top = top + window.pageYOffset + 'px';
    }

    function show(el) {
        var title = el.getAttribute('title');
        if (!title) {
            return;
        }
        hide();

        target = el;
        el.setAttribute('data-ul-title', title);
        el.removeAttribute('title');
        el.setAttribute('aria-describedby', tip.id);

        clearTimeout(fadeOut);
        content.textContent = title;
        tip.classList.toggle('qv-tooltip', !!el.closest('#mf-quick-view-modal'));
        tip.style.opacity = '0';
        document.body.appendChild(tip);
        place(el);
        tip.getBoundingClientRect(); // Start the fade from 0.
        tip.style.opacity = '1';
    }

    function hide() {
        if (!target) {
            return;
        }
        target.setAttribute('title', target.getAttribute('data-ul-title'));
        target.removeAttribute('data-ul-title');
        target.removeAttribute('aria-describedby');
        target = null;

        tip.style.opacity = '0';
        fadeOut = setTimeout(function () {
            tip.remove();
        }, FADE);
    }

    function targetFor(node) {
        return node && node.closest ? node.closest(TARGETS) : null;
    }

    document.addEventListener('mouseover', function (event) {
        var el = targetFor(event.target);
        if (el && el !== target) {
            show(el);
        } else if (!el && target && !target.isConnected) {
            hide(); // YITH replaced the icon under the pointer.
        }
    });

    document.addEventListener('mouseout', function (event) {
        if (target && target.contains(event.target) && !target.contains(event.relatedTarget)) {
            hide();
        }
    });

    document.addEventListener('focusin', function (event) {
        var el = targetFor(event.target);
        if (el && el !== target) {
            show(el);
        }
    });

    document.addEventListener('focusout', function (event) {
        if (target && target.contains(event.target)) {
            hide();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hide();
        }
    });
})(jQuery);
