/**
 * Appearance > Backdrops: the intensity buttons and pattern size sliders change the tiles' previews, and the
 * "Preview on the site" links follow the current (unsaved) choices - both areas' and the text weight - so any
 * combination can be tried on the home page. "Use the same backdrop" shows one picker (the first area's) for
 * both areas; otherwise the areas are tabs. Within each area the styles are tabbed by kind, with a dot on the
 * tab that holds the chosen style.
 */
(function () {
	'use strict';

	var root = document.querySelector('.ulbd');
	var pickers = document.querySelectorAll('.ulbd-picker');
	var areaTablist = document.querySelector('.ulbd-tabs');
	var linkBox = document.querySelector('.ulbd-link input');
	var firstArea = document.querySelector('.ulbd-area');

	function linked() {
		return !!(linkBox && linkBox.checked);
	}

	function tabsOf(tablist) {
		return Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
	}

	// Any tab bar on the page: selecting a tab shows its panel and hides its siblings'.
	function selectTab(tab, focus) {
		tabsOf(tab.closest('[role="tablist"]')).forEach(function (t) {
			var on = t === tab;
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.tabIndex = on ? 0 : -1;
			document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
		});
		if (focus) {
			tab.focus();
		}
	}

	document.querySelectorAll('[role="tablist"]').forEach(function (tablist) {
		var tabs = tabsOf(tablist);
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
	});

	// Linked: no area tabs, just the first area's picker, which then stands for both.
	function applyLinked() {
		root.setAttribute('data-linked', linked() ? '1' : '0');
		if (areaTablist) {
			areaTablist.hidden = linked();
			selectTab(tabsOf(areaTablist)[0]);
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
			var boxes = picker.querySelector('input[name$="[boxes]"]').value;
			if (boxes !== '') {
				params.push('ulbd_' + area + '_boxes=' + encodeURIComponent(boxes));
			}
		});
		document.querySelectorAll('.ulbd-preview-link').forEach(function (link) {
			var area = link.closest('.ulbd-picker').getAttribute('data-area');
			var base = (window.ulbdAdmin && window.ulbdAdmin.pages[area]) || '/';
			// The footer box is at the bottom of every page - open the preview there.
			link.href = base + (base.indexOf('?') === -1 ? '?' : '&') + params.join('&') + (area === 'footer' ? '#hero-footer-combo' : '');
		});
	}

	// Text box opacity: the slider sets the hidden field (empty = Standard, the theme's own glass); Reset clears it.
	function setBoxes(picker, value) {
		var control = picker.querySelector('.ulbd-boxes');
		control.querySelector('input[type="hidden"]').value = value;
		control.querySelector('output').textContent = value === '' ? 'Standard' : value + '%';
		control.querySelector('.ulbd-boxes__reset').hidden = value === '';
		if (value === '') {
			picker.style.removeProperty('--ulbd-box-alpha');
			control.querySelector('input[type="range"]').value = 80;
		} else {
			picker.style.setProperty('--ulbd-box-alpha', value / 100);
		}
	}

	document.querySelectorAll('.ulbd-boxes__reset').forEach(function (button) {
		button.addEventListener('click', function () {
			setBoxes(button.closest('.ulbd-picker'), '');
			updateLinks();
		});
	});

	pickers.forEach(function (picker) {
		// The sliders resize the tiles' patterns and fade their pills as they move; the links catch up on change.
		picker.addEventListener('input', function (event) {
			if (event.target.name && /\[scale\]$/.test(event.target.name)) {
				picker.style.setProperty('--ulbd-scale', event.target.value / 100);
				picker.querySelector('.ulbd-scale output').textContent = event.target.value + '%';
			}
			if (event.target.closest('.ulbd-boxes') && event.target.type === 'range') {
				setBoxes(picker, event.target.value);
			}
		});
		picker.addEventListener('change', function (event) {
			if (event.target.name && /\[intensity\]$/.test(event.target.name)) {
				picker.setAttribute('data-intensity', event.target.value);
			}
			if (event.target.name && /\[style\]$/.test(event.target.name)) {
				var kind = event.target.closest('[role="tabpanel"]').getAttribute('data-kind');
				picker.querySelectorAll('.ulbd-kind-tab').forEach(function (tab) {
					tab.querySelector('.ulbd-kind-tab__dot').hidden = tab.getAttribute('data-kind') !== kind;
				});
			}
			updateLinks();
		});
	});

	if (linkBox) {
		linkBox.addEventListener('change', applyLinked);
	}

	updateLinks();
}());
