'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const window = {};
vm.runInNewContext(fs.readFileSync(
	__dirname + '/../../wp-content/mu-plugins/ultralove-retailer-notes/admin-terms.js', 'utf8'
), { window });
const lines = (state) => Array.from(window.ulRnTerms.previewLines(state)); // arrays from the vm context have another prototype

assert.deepEqual(lines({}), []);
assert.deepEqual(lines({ limit: 'Limit 1 color per store', freeWith: '3', noDiscounts: true }),
	['Limit 1 color per store', 'Free with 3 units', 'Discounts do not apply']);
assert.deepEqual(lines({ freeWith: '0', other: '' }), [], 'No free offer adds nothing');
assert.deepEqual(lines({ freeWith: '5', perFlavor: true }), ['Free with 5 units per flavor']);
assert.deepEqual(lines({ limit: 'Limit 1 per store', other: ' MUST ORDER 2 OF EACH COLOR \r\n\r\nLimit 1 per store\n' }),
	['Limit 1 per store', 'MUST ORDER 2 OF EACH COLOR'], 'Other lines are trimmed and not repeated');
console.log('All admin terms JavaScript checks passed');
