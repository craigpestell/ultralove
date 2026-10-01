/**
 * Appearance > Backdrops: the intensity buttons and pattern size sliders change the tiles' previews, and the "Preview on the site"
 * links follow the current (unsaved) choices - both areas' and the text weight - so any combination can be
 * tried on the home page.
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
			if (area === 'text') {
				params.push('ulbd_text=' + encodeURIComponent(checked(picker, 'text_weight')));
				return;
			}
			params.push('ulbd_' + area + '=' + encodeURIComponent(checked(picker, 'style')));
			params.push('ulbd_' + area + '_intensity=' + encodeURIComponent(checked(picker, 'intensity')));
			params.push('ulbd_' + area + '_scale=' + encodeURIComponent(picker.querySelector('input[name$="[scale]"]').value));
		});
		document.querySelectorAll('.ulbd-preview-link').forEach(function (link) {
			var area = link.closest('.ulbd-picker').getAttribute('data-area');
			var base = (window.ulbdAdmin && window.ulbdAdmin.pages[area]) || '/';
			// The footer box is at the bottom of every page - open the preview there.
			link.href = base + (base.indexOf('?') === -1 ? '?' : '&') + params.join('&') + (area === 'footer' ? '#hero-footer-combo' : '');
		});
	}

	pickers.forEach(function (picker) {
		// The slider resizes the tiles' patterns as it moves; the links catch up on change.
		picker.addEventListener('input', function (event) {
			if (event.target.name && /\[scale\]$/.test(event.target.name)) {
				picker.style.setProperty('--ulbd-scale', event.target.value / 100);
				picker.querySelector('.ulbd-scale output').textContent = event.target.value + '%';
			}
		});
		picker.addEventListener('change', function (event) {
			if (event.target.name && /\[intensity\]$/.test(event.target.name)) {
				picker.setAttribute('data-intensity', event.target.value);
			}
			updateLinks();
		});
	});

	updateLinks();
}());
