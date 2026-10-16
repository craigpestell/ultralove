(function () {
	'use strict';

	/**
	 * The terms as they will read on a product card (short wording, fixed order). Keep in step with
	 * ultralove_rn_terms_lines() in terms.php.
	 */
	function previewLines(state) {
		var lines = [];
		if (state.limit) {
			lines.push(state.limit);
		}
		var units = parseInt(state.freeWith, 10);
		if (units > 0) {
			lines.push('Free with ' + units + ' units' + (state.perFlavor ? ' per flavor' : ''));
		}
		if (state.noDiscounts) {
			lines.push('Discounts do not apply');
		}
		String(state.other || '').split(/\r?\n/).forEach(function (line) {
			line = line.replace(/\s+/g, ' ').trim();
			if (line && lines.indexOf(line) === -1) {
				lines.push(line);
			}
		});
		return lines;
	}

	var api = { previewLines: previewLines };
	if (typeof window !== 'undefined') {
		window.ulRnTerms = api;
	}
	if (typeof document === 'undefined' || !document.querySelector) {
		return;
	}

	var root = document.querySelector('[data-ul-rn-admin]');
	var preview = root && root.querySelector('[data-ul-rn-preview]');
	if (!root || !preview) {
		return;
	}

	function field(name) {
		return root.querySelector('[name="' + name + '"]');
	}

	function refresh() {
		var limit = field('ul_rn_limit');
		var chosen = limit && limit.options[limit.selectedIndex];
		var lines = previewLines({
			limit: chosen ? chosen.getAttribute('data-card') : '',
			freeWith: field('ul_rn_free_with').value,
			perFlavor: field('ul_rn_free_per').checked,
			noDiscounts: field('ul_rn_no_discounts').checked,
			other: field('ul_rn_other').value
		});
		while (preview.firstChild) {
			preview.removeChild(preview.firstChild);
		}
		lines.forEach(function (line) {
			var row = document.createElement('span');
			row.textContent = line;
			preview.appendChild(row);
		});
	}

	root.addEventListener('input', refresh);
	root.addEventListener('change', refresh);
}());
