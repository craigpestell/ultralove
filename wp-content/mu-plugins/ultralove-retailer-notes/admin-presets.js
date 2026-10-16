(function () {
	'use strict';

	document.addEventListener('change', function (event) {
		var select = event.target;
		if (!select.matches('select.ultralove-rn-preset') || !select.value) {
			return;
		}
		var field = document.getElementById(select.dataset.retailerField);
		if (!field) {
			throw new Error('Retailer preset textarea not found: ' + select.dataset.retailerField);
		}
		var separator = field.value && !/[\r\n]$/.test(field.value) ? '\n' : '';
		field.value += separator + select.value;
		field.dispatchEvent(new Event('input', { bubbles: true }));
		field.dispatchEvent(new Event('change', { bubbles: true }));
		select.value = '';
		field.focus();
	});
}());
