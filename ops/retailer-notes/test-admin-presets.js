'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

let onChange;
const fields = {
	_ul_retailer_info: makeField(),
	_ul_retailer_important: makeField()
};
function makeField() {
	return {
		value: '',
		events: [],
		focused: false,
		dispatchEvent(event) { this.events.push([event.type, event.bubbles]); },
		focus() { this.focused = true; }
	};
}
vm.runInNewContext(fs.readFileSync(
	__dirname + '/../../wp-content/mu-plugins/ultralove-retailer-notes/admin-presets.js', 'utf8'
), {
	document: {
		addEventListener(type, callback) { assert.equal(type, 'change'); onChange = callback; },
		getElementById(id) { return fields[id]; }
	},
	Event: class { constructor(type, options) { this.type = type; this.bubbles = options.bubbles; } }
});

for (const key of Object.keys(fields)) {
	const field = fields[key];
	const otherKey = Object.keys(fields).find(candidate => candidate !== key);
	for (const initial of ['', 'Custom note', 'Custom note\n', 'Custom note\r\n', "Supplier's note \\ <text>  "]) {
		field.value = initial;
		field.events = [];
		field.focused = false;
		const otherValue = fields[otherKey].value;
		const select = {
			value: 'LIMIT 1 PER STORE ONLY\nFREE WITH 3 UNITS BOUGHT',
			dataset: { retailerField: key },
			matches(selector) { assert.equal(selector, 'select.ultralove-rn-preset'); return true; }
		};
		const preset = select.value;
		onChange({ target: select });
		assert.equal(field.value, initial + (initial && !/[\r\n]$/.test(initial) ? '\n' : '') + preset);
		assert.equal(fields[otherKey].value, otherValue);
		assert.equal(select.value, '');
		assert.equal(field.focused, true);
		assert.deepEqual(field.events, [['input', true], ['change', true]]);
		const appended = field.value;
		onChange({ target: select });
		assert.equal(field.value, appended, 'Empty placeholder must do nothing');
		select.value = preset;
		onChange({ target: select });
		assert.equal(field.value, appended + '\n' + preset, 'Same preset must remain selectable');
	}
}
onChange({ target: { matches() { return false; } } });
assert.throws(() => onChange({
	target: { matches() { return true; }, value: 'Note', dataset: { retailerField: 'missing' } }
}), /Retailer preset textarea not found/);
console.log('All admin preset JavaScript checks passed');
