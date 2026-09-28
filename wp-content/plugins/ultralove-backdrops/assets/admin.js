/**
 * Appearance > Backdrops: the intensity buttons switch the tiles' previews, and the "Preview on the site"
 * links follow the current (unsaved) choices - both areas' - so any combination can be tried on the home page.
 */
(function () {
	'use strict';

	var pickers = document.querySelectorAll('.ulbd-picker');

	function checked(picker, field) {
		var input = picker.querySelector('input[name$="[' + field + ']"]:checked');
		return input ? input.value : '';
	}

	function updateLinks() {
		var params = [];
		pickers.forEach(function (picker) {
			var area = picker.getAttribute('data-area');
			params.push('ulbd_' + area + '=' + encodeURIComponent(checked(picker, 'style')));
			params.push('ulbd_' + area + '_intensity=' + encodeURIComponent(checked(picker, 'intensity')));
		});
		document.querySelectorAll('.ulbd-preview-link').forEach(function (link) {
			var area = link.closest('.ulbd-picker').getAttribute('data-area');
			var base = (window.ulbdAdmin && window.ulbdAdmin.pages[area]) || '/';
			// The footer box is at the bottom of every page - open the preview there.
			link.href = base + (base.indexOf('?') === -1 ? '?' : '&') + params.join('&') + (area === 'footer' ? '#hero-footer-combo' : '');
		});
	}

	pickers.forEach(function (picker) {
		picker.addEventListener('change', function (event) {
			if (event.target.name && /\[intensity\]$/.test(event.target.name)) {
				picker.setAttribute('data-intensity', event.target.value);
			}
			updateLinks();
		});
	});

	updateLinks();
}());
