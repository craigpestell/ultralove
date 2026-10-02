/*
 * Stands in for Slick (the `slick` script handle) on pages that don't open with a Slick carousel; see
 * 31-trim-frontend-scripts.php. Martfury's script calls .slick() on many selections that are empty on these
 * pages, and those calls do nothing. The first call on elements (a Quick View gallery) loads the real Slick
 * and then replays every call made while it loaded.
 */
(function ($) {
	'use strict';

	var calls = [];

	function load() {
		var script = document.createElement('script');
		script.src = window.ulSlick.src;
		script.onload = function () {
			// Slick has replaced $.fn.slick with itself.
			calls.forEach(function (call) {
				$.fn.slick.apply(call[0], call[1]);
			});
			calls = [];
		};
		document.head.appendChild(script);
	}

	$.fn.slick = function () {
		if (this.length) {
			calls.push([this, arguments]);
			if (calls.length === 1) {
				load();
			}
		}
		return this;
	};
})(jQuery);
