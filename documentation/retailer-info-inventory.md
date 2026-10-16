# Production retailer-info inventory

Snapshot: 2026-10-16, after the catalog description cleanup at runtime revision
`e12cbcc55566dd04a7924d43ce79f63178eb8978`.

Read-only inventory of 6,528 WooCommerce products, excluding `trash`,
`auto-draft` and `inherit` statuses. Values were read with WordPress
`get_post_meta($id, $key, true)`, so duplicate metadata rows do not inflate counts.
All products with a non-empty value in either field have `publish` status.

Each row is one distinct, non-empty **complete field value**, counted once per
product, not once per rendered block. ` / ` denotes a newline in the original
value; it is not part of the stored text. Case, wording and line order are
preserved, so reversed conditions remain separate rows. Whitespace-only values
are counted as empty. A product can have both fields.

| Field | Products with a value | Empty | Unique complete values |
|---|---:|---:|---:|
| Retailer info | 744 | 5,784 | 12 |
| Important retailer info | 622 | 5,906 | 24 |

## Retailer info

Metadata key: `_ul_retailer_info`.

| Complete text value (newline shown as ` / `) | Products |
|---|---:|
| DISCOUNTS DO NOT APPLY | 713 |
| (SEASONAL - LIMITED SUPPLY) | 13 |
| Ships in Non-Retail Packaging DISCOUNTS DO NOT APPLY | 5 |
| (LIMITED EDITION) | 2 |
| DISCOUNTS DO NOT APPLY / (SEASONAL - LIMITED SUPPLY) | 2 |
| DISCOUNTS DO NOT APPLY / SPECIAL ORDER ONLY | 2 |
| NO FURTHER DISCOUNTS APPLY | 2 |
| DISCOUNTS DO NOT APPLY / SALE PRICED | 1 |
| DISPLAY KIT ONLY ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT | 1 |
| Retail Demo Unit | 1 |
| SALE PRICED | 1 |
| WHILE STOCK LASTS | 1 |
| **Total** | **744** |

## Important retailer info

Metadata key: `_ul_retailer_important`.

| Complete text value (newline shown as ` / `) | Products |
|---|---:|
| LIMIT 1 PER STORE / FREE WITH 3 UNITS BOUGHT | 253 |
| LIMIT 1 COLOR PER STORE / FREE WITH 2 UNITS BOUGHT | 76 |
| LIMIT 1 PER STORE / FREE WITH 2 UNITS BOUGHT | 59 |
| LIMIT 1 COLOR PER STORE / FREE WITH 3 UNITS BOUGHT | 58 |
| FREE WITH 2 UNITS BOUGHT / LIMIT 1 COLOR PER STORE | 43 |
| LIMIT 1 PER STORE | 32 |
| FREE WITH 3 UNITS BOUGHT | 27 |
| LIMIT 1 BOTTLE PER STORE / FREE WITH 3 UNITS BOUGHT | 23 |
| FREE WITH 2 UNITS BOUGHT | 13 |
| FREE WITH 3 UNITS BOUGHT / LIMIT 1 PER STORE | 10 |
| FREE WITH 2 UNITS BOUGHT / LIMIT 1 PER STORE | 5 |
| LIMIT 1 PER STORE / FREE WITH 3 UNITS BOUGHT PER FLAVOR | 5 |
| LIMIT 1 PER TESTER | 3 |
| FREE COCK RING WITH PURCHASE | 2 |
| LIMIT 1 PER STORE / FREE WITH 5 UNITS BOUGHT | 2 |
| LIMIT 1 PER STORE / FREE WITH 9 (3 EACH) PRODUCTS BOUGHT | 2 |
| ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT | 2 |
| FREE WITH PURCHASE OF 12 ESSENTIAL BULLETS / LIMIT 1 PER STORE | 1 |
| LIMIT 1 PER STORE / FREE WITH 2 EACH OF THE JO8015 LINE PURCHASED | 1 |
| LIMIT 1 PER STORE / FREE WITH 3 PRODUCTS BOUGHT | 1 |
| LIMIT 1 STAND PER STORE | 1 |
| MUST ORDER 2 OF EACH COLOR IN ORDER TO RECEIVE A FREE DISPLAY | 1 |
| MUST ORDER 4 ASSORTED COLORS IN ORDER TO RECEIVE A FREE DISPLAY | 1 |
| ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT Color May Vary / LIMIT 1 PER STORE ONLY / FREE WITH 3 UNITS BOUGHT | 1 |
| **Total** | **622** |

This is an exact inventory, not another normalization pass. Unusual, ambiguous
and repeated wording is intentionally reported as stored; no additional product
data was changed to produce this report.

## Product editor presets

The product editor's **Product data > General** tab provides a preset dropdown
above each existing retailer-info textarea. Choices are the complete values in
this snapshot used by at least **5 products**, listed alphabetically: 3 Retailer
info choices and 12 Important retailer info choices. These are code-owned presets,
not a live database query; rare values remain available through manual entry.

Selecting a choice appends its text on a new line without replacing existing
content, then resets the dropdown and focuses the textarea. Dropdown labels use
` / ` for readability; inserted combinations use real newlines. Text remains
editable and is not saved until the product is saved. Existing save-time
classification, equivalent-condition merging and conflicting-offer warnings
remain unchanged. No existing product values are migrated or rewritten by
adding these controls.
