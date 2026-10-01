/**
 * Appearance > Backdrops: the intensity buttons and pattern size sliders change the tiles' previews, and the
 * "Preview on the site" links follow the current (unsaved) choices - both areas' and the text weight - so any
 * combination can be tried on the home page. "Use the same backdrop" shows one picker (the first area's) for
 * both areas; otherwise the areas are tabs.
 */
(function () {
	'use strict';

	var root = document.querySelector('.ulbd');
	var pickers = document.querySelectorAll('.ulbd-picker');
	var tabs = Array.prototype.slice.call(document.querySelectorAll('.ulbd-tab'));
	var tablist = document.querySelector('.ulbd-tabs');
	var linkBox = document.querySelector('.ulbd-link input');
	var firstArea = document.querySelector('.ulbd-area');

	function linked() {
		return !!(linkBox && linkBox.checked);
	}

	function selectTab(tab, focus) {
		tabs.forEach(function (t) {
			var on = t === tab;
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.tabIndex = on ? 0 : -1;
			document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
		});
		if (focus) {
			tab.focus();
		}
	}

	tabs.forEach(function (tab, i) {
		tab.addEventListener('click', function () {
			selectTab(tab);
		});
		tab.addEventListener('keydown', function (event) {
			var next = {ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1}[event.key];
			if (next === undefined) {
				return;
			}
			event.preventDefault();
			selectTab(tabs[(next + tabs.length) % tabs.length], true);
		});
	});

	// Linked: no tabs, just the first area's picker, which then stands for both.
	function applyLinked() {
		root.setAttribute('data-linked', linked() ? '1' : '0');
		if (tablist) {
			tablist.hidden = linked();
		}
		if (tabs.length) {
			selectTab(tabs[0]);
		}
		updateLinks();
	}

	function checked(picker, field) {
		var input = picker.querySelector('input[name$="[' + field + ']"]:checked');
		return input ? input.value : '';
	}

	function updateLinks() {
		var params = [];
		pickers.forEach(function (own) {
			var area = own.getAttribute('data-area');
			if (area === 'text') {
				params.push('ulbd_text=' + encodeURIComponent(checked(own, 'text_weight')));
				return;
			}
			// Linked: every area previews the first area's choices.
			var picker = linked() ? firstArea : own;
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

	if (linkBox) {
		linkBox.addEventListener('change', applyLinked);
	}

	updateLinks();
}());
