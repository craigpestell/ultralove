<?php
/** Offline test for the retailer-notes title parser. Run: php ops/retailer-notes/test-parser.php */
require __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/parser.php';

$fail = 0;
$check = static function ( string $label, $actual, $expected ) use ( &$fail ): void {
	if ( $actual !== $expected ) {
		$fail++;
		echo "FAIL: $label\n  expected " . json_encode( $expected ) . "\n  actual   " . json_encode( $actual ) . "\n";
	}
};
$s = 'ultralove_rn_split_title';

$r = $s( 'SCCPOW110B<br> Color Pop OWow<br> Blue<br> <b><font color=red><font size="3">SALE PRICED</font></FONT></b>' );
$check( 'nested font in b: title', $r['title'], 'SCCPOW110B<br> Color Pop OWow<br> Blue' );
$check( 'nested font in b: info', $r['info'], array( 'SALE PRICED' ) );
$check( 'nested font in b: important', $r['important'], array() );

$r = $s( 'F1<br> Fantasy Doll: Carmen<br> <b> DISCOUNTS DO NOT APPLY</b><br><b><font color=red><font size="2"> SPECIAL ORDER ONLY</font></FONT></b>' );
$check( 'two notes: title', $r['title'], 'F1<br> Fantasy Doll: Carmen' );
$check( 'two notes: info', $r['info'], array( 'DISCOUNTS DO NOT APPLY', 'SPECIAL ORDER ONLY' ) );

$r = $s( 'JJ10551TE<br> Jimmy Jane<br> Pulsus G-Spot<br> <b>TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'tester: title', $r['title'], 'JJ10551TE<br> Jimmy Jane<br> Pulsus G-Spot' );
$check( 'tester: important', $r['important'], array( 'TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT' ) );
$check( 'tester: info', $r['info'], array() );

$r = $s( 'X<br>Y<br><b>DISCOUNTS DO NOT APPLY</b><br><b>TESTER</b><br><b>ONE PER STORE ONLY</b><br><b>FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'split tester run: important', $r['important'], array( 'TESTER', 'ONE PER STORE ONLY', 'FREE WITH 3 UNITS BOUGHT' ) );
$check( 'split tester run: info stays out of important', $r['info'], array( 'DISCOUNTS DO NOT APPLY' ) );

$r = $s( 'X<br>Y<br><b>**ESTER ONE PER STORE&#32;ONLY**</b>' );
$check( 'typo + asterisks + entity', $r['important'], array( 'TESTER ONE PER STORE ONLY' ) );

$r = $s( 'SKU<br>Name<br><font color=red>CLEARANCE</font>' );
$check( 'font outside b: title', $r['title'], 'SKU<br> Name' );
$check( 'font outside b: info', $r['info'], array( 'CLEARANCE' ) );

$r = $s( 'SKU<br>Name<br><font color=red><b>CLEARANCE</b></font>' );
$check( 'b inside font', array( $r['title'], $r['info'] ), array( 'SKU<br> Name', array( 'CLEARANCE' ) ) );

$r = $s( 'SKU Name <font size=2>NEW <b>HOT</b></font> Blue' );
$check( 'mixed font/b', array( $r['title'], $r['info'] ), array( 'SKU Name Blue', array( 'NEW', 'HOT' ) ) );

$r = $s( 'SKU Name <b>SALE</b> <b>sale</b>' );
$check( 'dedupe ignores case', $r['info'], array( 'SALE' ) );

$r = $s( 'SKU <b>Brand</b> Product' );
$check( 'mid-line bold', array( $r['title'], $r['info'] ), array( 'SKU Product', array( 'Brand' ) ) );

$r = $s( 'SKU Name' );
$check( 'no markup untouched', array( $r['changed'], $r['title'] ), array( false, 'SKU Name' ) );

$r = $s( 'SKU<br> Name <strong>NOTE</strong><br><b>X</b>' );
$check( 'strong left alone + flagged', array( $r['title'], $r['other_tags'] ), array( 'SKU<br> Name <strong>NOTE</strong>', true ) );

$r = $s( '<b>ONLY BOLD</b>' );
$check( 'only bold leaves empty title', $r['title'], '' );

$r = $s( 'SKU Name <b>UNCLOSED' );
$check( 'unclosed b: flagged, text kept', array( $r['malformed'], $r['title'] ), array( true, 'SKU Name UNCLOSED' ) );

$r = $s( 'Tom &amp; Jerry<br><B>Sale&nbsp;Priced</B>' );
$check( 'entities in title kept, nbsp collapsed in note', array( $r['title'], $r['info'] ), array( 'Tom &amp; Jerry', array( 'Sale Priced' ) ) );

$r = $s( 'J50075<br> The Original Magic Wand<br><b>Electric</b><br> <b> DISCOUNTS DO NOT APPLY</b>' );
$check( 'name-part bold stays in name', array( $r['title'], $r['info'] ), array( 'J50075<br> The Original Magic Wand<br> Electric', array( 'DISCOUNTS DO NOT APPLY' ) ) );

$r = $s( 'X<br>Y <b><font size=2>Travel Size</font></b>' );
$check( 'name-part bold only: tags go, nothing extracted', array( $r['changed'], $r['title'], $r['info'], $r['malformed'] ), array( true, 'X<br> Y Travel Size', array(), false ) );

$r = $s( 'W623102<br> Liberty 2<br> Purple<br> <b>DISCOUNTS DO NOT APPLY</b></b>' );
$check( 'duplicate closing tag is harmless', array( $r['malformed'], $r['title'], $r['info'] ), array( false, 'W623102<br> Liberty 2<br> Purple', array( 'DISCOUNTS DO NOT APPLY' ) ) );

$r = $s( 'N3333TE<br> Magic Massager<br> TESTER<br> ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'recognized unwrapped tester run', array( $r['malformed'], $r['title'], $r['important'] ), array( false, 'N3333TE<br> Magic Massager', array( 'TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT' ) ) );

$r = $s( 'N3328TE<br> Mini Tickler Bullet TESTER<br> ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'real inline tester without opening tag', array( $r['title'], $r['important'], $r['malformed'] ), array( 'N3328TE<br> Mini Tickler Bullet', array( 'TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT' ), false ) );
$check( 'unwrapped cleanup is idempotent', $s( $r['title'] )['changed'], false );

$r = $s( 'J50051<br> Magic Wand Mini<br> Demo and Display<br>ONE  PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'demo identity stays, whitespace collapsed', array( $r['title'], $r['info'], $r['malformed'] ), array( 'J50051<br> Magic Wand Mini<br> Demo and Display', array( 'ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT' ), false ) );

$r = $s( 'J50091<br> Magic Wand Power Adapter HV265<br> NO FURTHER DISCOUNTS APPLY' );
$check( 'no wrappers at all', array( $r['title'], $r['info'], $r['changed'] ), array( 'J50091<br> Magic Wand Power Adapter HV265', array( 'NO FURTHER DISCOUNTS APPLY' ), true ) );

$r = $s( 'L9035T<br> Lelo Dot Cruise<br> Peach Please TESTER<br>FREE WITH 2 UNITS BOUGHT</b>' );
$check( 'variant retained before tester', array( $r['title'], $r['important'], $r['malformed'] ), array( 'L9035T<br> Lelo Dot Cruise<br> Peach Please', array( 'TESTER FREE WITH 2 UNITS BOUGHT' ), false ) );

$r = $s( 'R2096<BR> Romp Pleasure Air Lipstick<br> Tester Only<br>ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'tester-only note with mixed-case breaks', array( $r['title'], $r['important'], $r['malformed'] ), array( 'R2096<br> Romp Pleasure Air Lipstick', array( 'Tester Only ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT' ), false ) );

$r = $s( 'SKU<br> Product<br> <b>WHILE STOCK LASTS</b><br> NO FURTHER DISCOUNTS APPLY' );
$check( 'mixed wrapped and unwrapped notes', array( $r['title'], $r['info'], $r['malformed'] ), array( 'SKU<br> Product', array( 'WHILE STOCK LASTS', 'NO FURTHER DISCOUNTS APPLY' ), false ) );

$r = $s( 'W602003<br> Marilyn Monroe Special Edition<br> Classic 2<br> Vivid Red<<br> <b>DISCOUNTS DO NOT APPLY</b>' );
$check( 'real duplicate opening angle', array( $r['title'], $r['info'], $r['malformed'] ), array( 'W602003<br> Marilyn Monroe Special Edition<br> Classic 2<br> Vivid Red', array( 'DISCOUNTS DO NOT APPLY' ), false ) );
$check( 'duplicate opening angle absent in residual', str_contains( $r['residual'], '<<' ), false );

foreach ( array(
	'SKU<br> Product</b>',
	'SKU<br> Product<br> UNKNOWN INSTRUCTION</b>',
	'SKU<br> Product<br><b>TESTER ONE PER STORE ONLY',
) as $title ) {
	$check( 'unknown malformed tags still flagged: ' . $title, $s( $title )['malformed'], true );
}
foreach ( array(
	'SKU<br> Product Tester',
	'SKU<br> Product Tester Edition',
	'SKU<br> Per Store Display Stand',
	'SKU<br> FREE WITH 3 UNITS BOUGHT Collectors Edition',
	'SKU<br> Size 2 < 3',
	'SKU<br> Size 2 &lt; 3',
	'SKU<br> Product<br> FREE WITH PURCHASE OF<br> 2 EACH (8 TOTAL) of E-Stimulator Products',
) as $title ) {
	$check( 'ambiguous or literal title untouched: ' . $title, array( $s( $title )['changed'], $s( $title )['title'] ), array( false, $title ) );
}

// residual: the title with only the extracted notes cut out, nothing added, as the old slug saw it
foreach ( array(
	'WE3125<br> Sync Lite<br> Tester Kit',
	'JDISP-00-16-1<br> Starstruck Display with Testers',
	'SKU<br> Display w/Testers',
) as $name ) {
	$r = $s( $name . '<br><b>ONE PER STORE ONLY<br> FREE WITH 2 UNITS BOUGHT</b><br><b>DISCOUNTS DO NOT APPLY</b>' );
	$check( 'retained tester identity: ' . $name, array( $r['title'], $r['important'], $r['info'] ), array( $name, array( 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ), array( 'DISCOUNTS DO NOT APPLY' ) ) );
}
foreach ( array( 'with Testers', 'Display For Tester' ) as $identity ) {
	$r = $s( 'SKU<br> Product<br><b>' . $identity . '</b><br><b>ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT</b>' );
	$check( 'wrapped tester identity: ' . $identity, array( $r['title'], $r['important'] ), array( 'SKU<br> Product<br> ' . $identity, array( 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ) ) );
}
foreach ( array(
	'ONE PER STORE ONLY FREE WITH 9 (3 each) PRODUCTS BOUGHT',
	'ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT PER FLAVOR',
	'ONE PER STORE ONLY FREE WITH 2 EACH OF THE JO8015 LINE PURCHASED',
	'FREE WITH PURCHASE OF 12 ESSENTIAL BULLETS 1 Per Store Only',
) as $offer ) {
	$check( 'recognized tester offer: ' . $offer, ultralove_rn_is_tester_offer( 'Product Tester Kit', $offer ), true );
}
foreach ( array( 'DISCOUNTS DO NOT APPLY', 'FREE WITH MYSTERY OFFER', 'ONE PER STORE ONLY DISCOUNTS DO NOT APPLY' ) as $note ) {
	$check( 'unrelated or unknown tester note: ' . $note, ultralove_rn_is_tester_offer( 'Product Tester Kit', $note ), false );
}
$check( 'tester substring is not identity', ultralove_rn_is_tester_offer( 'Protester Edition', 'ONE PER STORE ONLY' ), false );
$r = $s( 'W600160<br> Womanizer Duo 2<br><b>Display For Tester ONE PER STORE ONLY<br> FREE WITH 2 UNITS BOUGHT</b>' );
$check( 'display identity beside offer in one wrapper', array( $r['title'], $r['important'] ), array( 'W600160<br> Womanizer Duo 2<br> Display For Tester', array( 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ) ) );

$r = $s( 'J50075<br> The Original Magic Wand<br><b>Electric</b><br> <b> DISCOUNTS DO NOT APPLY</b>' );
$check( 'residual', $r['residual'], "J50075<br> The Original Magic Wand<br>Electric<br> " );
$r = $s( 'SKU Name' );
$check( 'residual of untouched title', $r['residual'], 'SKU Name' );

// slug removal, with real-looking old slugs (brand sits between SKU and name there, which the name lacks)
$slug = static fn( string $html ): string => trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( strip_tags( $html ) ) ), '-' );
$remove = static function ( string $original, string $old_slug ) use ( $s, $slug ) {
	$r = $s( $original );
	return ultralove_rn_slug_remove( $old_slug, $slug( $original ), $slug( $r['residual'] ) );
};
$check( 'slug: tester note at the end', $remove( 'L1640<br> Siri 2<br> Black <b>TESTER<br>ONE COLOR PER STORE ONLY<br> FREE WITH 2 UNITS BOUGHT</b>', 'l1640-lelo-siri-2-black-testerone-color-per-store-only-free-with-2-units-bought' ), 'l1640-lelo-siri-2-black' );
$check( 'slug: discount note', $remove( 'T0998-30TC<br> Realdoe<br> Slim<br><b> DISCOUNTS DO NOT APPLY</b>', 't0998-30tc-toy-love-realdoe-slim-discounts-do-not-apply' ), 't0998-30tc-toy-love-realdoe-slim' );
$check( 'slug: name-part word stays', $remove( 'J50075<br> The Original Magic Wand<br><b>Electric</b><br> <b> DISCOUNTS DO NOT APPLY</b>', 'j50075-doc-johnson-the-original-magic-wandelectric-discounts-do-not-apply' ), 'j50075-doc-johnson-the-original-magic-wandelectric' );
$check( 'slug: note in the middle', $remove( 'SKU <b>Brand</b> Product', 'sku-brand-product' ), 'sku-product' );
$check( 'slug: note glued to the last word, numeric suffix kept', $remove( 'SKU<br> Name<br><b>SALE PRICED</b>', 'sku-acme-namesale-priced-2' ), 'sku-acme-name-2' );
$check( 'slug: real glued example', $remove( '2455<br> The Ultimate Kiss<br> Oral Lovemaking Book<br><b><font color=black><font size="2">WHILE STOCK LASTS</font></FONT></b>', '2455-acme-the-ultimate-kiss-oral-lovemaking-bookwhile-stock-lasts' ), '2455-acme-the-ultimate-kiss-oral-lovemaking-book' );
$check( 'slug: removed text appears twice -> null', $remove( 'SKU<br> Name<br><b>SALE</b>', 'sku-sale-name-namesale' ), null );
$check( 'slug: old slug lacks the words -> null', $remove( 'SKU<br> Name<br><b>SALE PRICED</b>', 'sku-name' ), null );
$check( 'slug: note glued to a word inside the line', $remove( 'SKU<br> Name Aqua<b>TESTER</b>', 'sku-brand-name-aquatester' ), 'sku-brand-name-aqua' );

$suffix = 'ultralove_rn_slug_remove_note_suffix';
$check( 'historical: optional only in old note', $suffix( 'l9486t-lelo-enigma-double-sonic-black-testerone-color-per-store-only-free-with-2-units-bought', 'l9486t-lelo-enigma-double-sonic-black', 'tester-one-color-per-store-free-with-2-units-bought' ), 'l9486t-lelo-enigma-double-sonic-black' );
$check( 'historical: glued note and renamed product', $suffix( 'int039-t-intimate-earth-naughty-nectarines-flavored-lubricanttesterone-bottle-per-store-only-free-with-3-units-bought', 'int039t-naughty-peaches-flavored-lubricant', 'tester-one-bottle-per-store-only-free-with-3-units-bought' ), 'int039-t-intimate-earth-naughty-nectarines-flavored-lubricant' );
$check( 'historical: discount synonym and numeric suffix', $suffix( 'sku-brand-name-no-further-discounts-apply-2', 'sku-new-name', 'discounts-do-not-apply' ), 'sku-brand-name-2' );
$check( 'historical: combined instructions', $suffix( 'sku-name-discounts-do-not-apply-seasonal-limited-supply', 'sku-name', 'discounts-do-not-apply' ), 'sku-name' );
$check( 'historical: tester only variant', $suffix( 'sku-name-tester-onlyone-per-store-only-free-with-3-units-bought', 'sku-name', 'tester-one-per-store-only-free-with-3-units-bought' ), 'sku-name' );
$check( 'historical: tester is part of the name', $suffix( 'sku-display-testerone-per-store-only-free-with-3-units-bought', 'sku-display-tester', 'one-per-store-only-free-with-3-units-bought' ), 'sku-display-tester' );
$check( 'historical: standalone extracted tester', $suffix( 'sku-name-tester', 'sku-name', 'tester-free-with-3-units-bought' ), 'sku-name' );
$check( 'historical: standalone retained tester', $suffix( 'sku-display-with-tester', 'sku-display-with-tester', 'discounts-do-not-apply' ), null );
$check( 'historical: meaningful tester accessory', $suffix( 'sku-tester-w-base-pos', 'sku-tester-w-base-pos', 'discounts-do-not-apply' ), null );
$check( 'historical: unknown instruction is not guessed', $suffix( 'sku-name-tester-special-promotion', 'sku-name', 'tester' ), null );
$check( 'historical: no product prefix', $suffix( 'testerone-per-store-only-free-with-3-units-bought', 'sku-name', 'tester' ), null );
$check( 'historical: repeated instruction is ambiguous', $suffix( 'sku-discounts-do-not-apply-name-discounts-do-not-apply', 'sku-name', 'discounts-do-not-apply' ), null );
$check( 'historical: retained sale-priced phrase', $suffix( 'sku-name-sale-priced', 'sku-name-sale-priced', 'while-stock-lasts' ), null );
$check( 'historical: retained phrase after other instructions', $suffix( 'sku-name-discounts-do-not-apply-sale-priced', 'sku-name-sale-priced', 'discounts-do-not-apply' ), null );
$check( 'historical: tester inside a legitimate word', $suffix( 'sku-name-protester', 'sku-name', 'tester' ), null );
$check( 'historical: alternate discount wording', $suffix( 'ls599728-lush-3-bluetooth-wearable-vibrating-egg-pink-no-discounts-apply', 'ls599728-lush-3-pink-egg-vibrating-bluetooth-wearable', 'no-discounts-apply' ), 'ls599728-lush-3-bluetooth-wearable-vibrating-egg-pink' );

echo $fail ? "\n$fail failed\n" : "All parser checks passed\n";
exit( $fail ? 1 : 0 );
