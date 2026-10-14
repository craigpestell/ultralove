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
$check( 'nested font in b: title', $r['title'], 'SCCPOW110B<br>Color Pop OWow<br>Blue' );
$check( 'nested font in b: info', $r['info'], array( 'SALE PRICED' ) );
$check( 'nested font in b: important', $r['important'], array() );

$r = $s( 'F1<br> Fantasy Doll: Carmen<br> <b> DISCOUNTS DO NOT APPLY</b><br><b><font color=red><font size="2"> SPECIAL ORDER ONLY</font></FONT></b>' );
$check( 'two notes: title', $r['title'], 'F1<br>Fantasy Doll: Carmen' );
$check( 'two notes: info', $r['info'], array( 'DISCOUNTS DO NOT APPLY', 'SPECIAL ORDER ONLY' ) );

$r = $s( 'JJ10551TE<br> Jimmy Jane<br> Pulsus G-Spot<br> <b>TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'tester: title', $r['title'], 'JJ10551TE<br>Jimmy Jane<br>Pulsus G-Spot' );
$check( 'tester: important', $r['important'], array( 'TESTER ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT' ) );
$check( 'tester: info', $r['info'], array() );

$r = $s( 'X<br>Y<br><b>DISCOUNTS DO NOT APPLY</b><br><b>TESTER</b><br><b>ONE PER STORE ONLY</b><br><b>FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'split tester run: important', $r['important'], array( 'TESTER', 'ONE PER STORE ONLY', 'FREE WITH 3 UNITS BOUGHT' ) );
$check( 'split tester run: info stays out of important', $r['info'], array( 'DISCOUNTS DO NOT APPLY' ) );

$r = $s( 'X<br>Y<br><b>**ESTER ONE PER STORE&#32;ONLY**</b>' );
$check( 'typo + asterisks + entity', $r['important'], array( 'TESTER ONE PER STORE ONLY' ) );

$r = $s( 'SKU<br>Name<br><font color=red>CLEARANCE</font>' );
$check( 'font outside b: title', $r['title'], 'SKU<br>Name' );
$check( 'font outside b: info', $r['info'], array( 'CLEARANCE' ) );

$r = $s( 'SKU<br>Name<br><font color=red><b>CLEARANCE</b></font>' );
$check( 'b inside font', array( $r['title'], $r['info'] ), array( 'SKU<br>Name', array( 'CLEARANCE' ) ) );

$r = $s( 'SKU Name <font size=2>NEW <b>HOT</b></font> Blue' );
$check( 'mixed font/b', array( $r['title'], $r['info'] ), array( 'SKU Name Blue', array( 'NEW', 'HOT' ) ) );

$r = $s( 'SKU Name <b>SALE</b> <b>sale</b>' );
$check( 'dedupe ignores case', $r['info'], array( 'SALE' ) );

$r = $s( 'SKU <b>Brand</b> Product' );
$check( 'mid-line bold', array( $r['title'], $r['info'] ), array( 'SKU Product', array( 'Brand' ) ) );

$r = $s( 'SKU Name' );
$check( 'no markup untouched', array( $r['changed'], $r['title'] ), array( false, 'SKU Name' ) );

$r = $s( 'SKU<br> Name <strong>NOTE</strong><br><b>X</b>' );
$check( 'strong left alone + flagged', array( $r['title'], $r['other_tags'] ), array( 'SKU<br>Name <strong>NOTE</strong>', true ) );

$r = $s( '<b>ONLY BOLD</b>' );
$check( 'only bold leaves empty title', $r['title'], '' );

$r = $s( 'SKU Name <b>UNCLOSED' );
$check( 'unclosed b: flagged, text kept', array( $r['malformed'], $r['title'] ), array( true, 'SKU Name UNCLOSED' ) );

$r = $s( 'Tom &amp; Jerry<br><B>Sale&nbsp;Priced</B>' );
$check( 'entities in title kept, nbsp collapsed in note', array( $r['title'], $r['info'] ), array( 'Tom &amp; Jerry', array( 'Sale Priced' ) ) );

$r = $s( 'J50075<br> The Original Magic Wand<br><b>Electric</b><br> <b> DISCOUNTS DO NOT APPLY</b>' );
$check( 'name-part bold stays in name', array( $r['title'], $r['info'] ), array( 'J50075<br>The Original Magic Wand<br>Electric', array( 'DISCOUNTS DO NOT APPLY' ) ) );

$r = $s( 'X<br>Y <b><font size=2>Travel Size</font></b>' );
$check( 'name-part bold only: tags go, nothing extracted', array( $r['changed'], $r['title'], $r['info'], $r['malformed'] ), array( true, 'X<br>Y Travel Size', array(), false ) );

$r = $s( 'W623102<br> Liberty 2<br> Purple<br> <b>DISCOUNTS DO NOT APPLY</b></b>' );
$check( 'duplicate closing tag is harmless', array( $r['malformed'], $r['title'], $r['info'] ), array( false, 'W623102<br>Liberty 2<br>Purple', array( 'DISCOUNTS DO NOT APPLY' ) ) );

$r = $s( 'N3333TE<br> Magic Massager<br> TESTER<br> ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT</b>' );
$check( 'closing tag with no opening one is flagged', array( $r['malformed'], $r['important'] ), array( true, array() ) );

echo $fail ? "\n$fail failed\n" : "All parser checks passed\n";
exit( $fail ? 1 : 0 );
