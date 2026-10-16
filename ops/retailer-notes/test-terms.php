<?php
/** Offline regression for the structured retailer terms: every distinct value in the 2026-10-16 production inventory. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/terms.php';

function fail( string $message ): void { throw new RuntimeException( $message ); }
$lines = static fn( string $text ): array => array_values( array_filter( explode( "\n", $text ), 'strlen' ) );

// documentation/retailer-info-inventory.md: every complete value of each field and what it must become.
$info = array(
	'DISCOUNTS DO NOT APPLY'                                  => '{"v":1,"no_discounts":true}',
	'(SEASONAL - LIMITED SUPPLY)'                             => '{"v":1,"tags":["seasonal"]}',
	'Ships in Non-Retail Packaging DISCOUNTS DO NOT APPLY'    => '{"v":1,"no_discounts":true,"tags":["non_retail_packaging"]}',
	'(LIMITED EDITION)'                                       => '{"v":1,"tags":["limited_edition"]}',
	"DISCOUNTS DO NOT APPLY\n(SEASONAL - LIMITED SUPPLY)"     => '{"v":1,"no_discounts":true,"tags":["seasonal"]}',
	"DISCOUNTS DO NOT APPLY\nSPECIAL ORDER ONLY"              => '{"v":1,"no_discounts":true,"tags":["special_order"]}',
	'NO FURTHER DISCOUNTS APPLY'                              => '{"v":1,"no_discounts":true}',
	"DISCOUNTS DO NOT APPLY\nSALE PRICED"                     => '{"v":1,"no_discounts":true,"tags":["sale_priced"]}',
	'DISPLAY KIT ONLY ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT' => '{"v":1,"other":["DISPLAY KIT ONLY ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT"]}',
	'Retail Demo Unit'                                        => '{"v":1,"tags":["retail_demo"]}',
	'SALE PRICED'                                             => '{"v":1,"tags":["sale_priced"]}',
	'WHILE STOCK LASTS'                                       => '{"v":1,"tags":["while_stock_lasts"]}',
);
$important = array(
	"LIMIT 1 PER STORE\nFREE WITH 3 UNITS BOUGHT"             => '{"v":1,"limit":"store","free_with":3}',
	"LIMIT 1 COLOR PER STORE\nFREE WITH 2 UNITS BOUGHT"       => '{"v":1,"limit":"color","free_with":2}',
	"LIMIT 1 PER STORE\nFREE WITH 2 UNITS BOUGHT"             => '{"v":1,"limit":"store","free_with":2}',
	"LIMIT 1 COLOR PER STORE\nFREE WITH 3 UNITS BOUGHT"       => '{"v":1,"limit":"color","free_with":3}',
	"FREE WITH 2 UNITS BOUGHT\nLIMIT 1 COLOR PER STORE"       => '{"v":1,"limit":"color","free_with":2}',
	'LIMIT 1 PER STORE'                                       => '{"v":1,"limit":"store"}',
	'FREE WITH 3 UNITS BOUGHT'                                => '{"v":1,"free_with":3}',
	"LIMIT 1 BOTTLE PER STORE\nFREE WITH 3 UNITS BOUGHT"      => '{"v":1,"limit":"bottle","free_with":3}',
	'FREE WITH 2 UNITS BOUGHT'                                => '{"v":1,"free_with":2}',
	"FREE WITH 3 UNITS BOUGHT\nLIMIT 1 PER STORE"             => '{"v":1,"limit":"store","free_with":3}',
	"FREE WITH 2 UNITS BOUGHT\nLIMIT 1 PER STORE"             => '{"v":1,"limit":"store","free_with":2}',
	"LIMIT 1 PER STORE\nFREE WITH 3 UNITS BOUGHT PER FLAVOR"  => '{"v":1,"limit":"store","free_with":3,"free_per":"flavor"}',
	'LIMIT 1 PER TESTER'                                      => '{"v":1,"limit":"tester"}',
	'FREE COCK RING WITH PURCHASE'                            => '{"v":1,"other":["FREE COCK RING WITH PURCHASE"]}',
	"LIMIT 1 PER STORE\nFREE WITH 5 UNITS BOUGHT"             => '{"v":1,"limit":"store","free_with":5}',
	"LIMIT 1 PER STORE\nFREE WITH 9 (3 EACH) PRODUCTS BOUGHT" => '{"v":1,"limit":"store","other":["FREE WITH 9 (3 EACH) PRODUCTS BOUGHT"]}',
	'ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT'    => '{"v":1,"other":["ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT"]}',
	"FREE WITH PURCHASE OF 12 ESSENTIAL BULLETS\nLIMIT 1 PER STORE" => '{"v":1,"limit":"store","other":["FREE WITH PURCHASE OF 12 ESSENTIAL BULLETS"]}',
	"LIMIT 1 PER STORE\nFREE WITH 2 EACH OF THE JO8015 LINE PURCHASED" => '{"v":1,"limit":"store","other":["FREE WITH 2 EACH OF THE JO8015 LINE PURCHASED"]}',
	"LIMIT 1 PER STORE\nFREE WITH 3 PRODUCTS BOUGHT"          => '{"v":1,"limit":"store","other":["FREE WITH 3 PRODUCTS BOUGHT"]}',
	'LIMIT 1 STAND PER STORE'                                 => '{"v":1,"limit":"stand"}',
	'MUST ORDER 2 OF EACH COLOR IN ORDER TO RECEIVE A FREE DISPLAY' => '{"v":1,"other":["MUST ORDER 2 OF EACH COLOR IN ORDER TO RECEIVE A FREE DISPLAY"]}',
	'MUST ORDER 4 ASSORTED COLORS IN ORDER TO RECEIVE A FREE DISPLAY' => '{"v":1,"other":["MUST ORDER 4 ASSORTED COLORS IN ORDER TO RECEIVE A FREE DISPLAY"]}',
	"ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT Color May Vary\nLIMIT 1 PER STORE ONLY\nFREE WITH 3 UNITS BOUGHT" => '{"v":1,"limit":"store","free_with":3,"other":["ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT Color May Vary"]}',
);
if ( 12 !== count( $info ) || 24 !== count( $important ) ) {
	fail( 'The inventory has 12 Retailer info and 24 Important retailer info values.' );
}
foreach ( array( 'info' => $info, 'important' => $important ) as $field => $cases ) {
	foreach ( $cases as $text => $expected ) {
		$terms = 'info' === $field ? ultralove_rn_terms_from_legacy( $lines( $text ), array() ) : ultralove_rn_terms_from_legacy( array(), $lines( $text ) );
		if ( ultralove_rn_terms_encode( $terms ) !== $expected ) {
			fail( "Wrong terms for [$field] " . str_replace( "\n", ' / ', $text ) . ': ' . ultralove_rn_terms_encode( $terms ) );
		}
		// Converting to the old text and back loses nothing, and decoding the stored form gives the same terms.
		$legacy = ultralove_rn_terms_to_legacy( $terms );
		if ( ultralove_rn_terms_from_legacy( $legacy['info'], $legacy['important'] ) !== $terms || ultralove_rn_terms_decode( ultralove_rn_terms_encode( $terms ) ) !== $terms ) {
			fail( 'Round trip changed the terms of: ' . $text );
		}
	}
}

// Both fields on one product: the discounts note joins the limit and the offer; order and wording are fixed.
$terms = ultralove_rn_terms_from_legacy( array( 'DISCOUNTS DO NOT APPLY' ), array( "FREE WITH 3 UNITS BOUGHT\nLIMIT 1 COLOR PER STORE" ) );
if ( array( 'Limit 1 color per store', 'Free with 3 units', 'Discounts do not apply' ) !== ultralove_rn_terms_lines( $terms, 'card' )
	|| array( 'Limit 1 color per store', 'Free with 3 units bought', 'Discounts do not apply' ) !== ultralove_rn_terms_lines( $terms, 'page' ) ) {
	fail( 'Terms lines must come out in the order limit, free offer, discounts, other.' );
}
// Different wording of the same thing is the same terms.
$same = array(
	ultralove_rn_terms_from_legacy( array(), array( 'ONE PER STORE ONLY' ) ),
	ultralove_rn_terms_from_legacy( array(), array( 'LIMIT 1 PER STORE' ) ),
	ultralove_rn_terms_from_legacy( array(), array( 'Limit 1 Per Store Only.' ) ),
);
if ( $same[0] !== $same[1] || $same[1] !== $same[2] ) {
	fail( 'Equivalent wording must give identical terms.' );
}
if ( ultralove_rn_terms_from_legacy( array( 'no discounts apply' ), array() ) !== ultralove_rn_terms_from_legacy( array( 'NO FURTHER DISCOUNTS APPLY' ), array() ) ) {
	fail( 'The two discount wordings must be one flag.' );
}
// Conflicts are kept whole and reported.
$review = array();
$terms = ultralove_rn_terms_from_legacy( array(), array( 'LIMIT 1 PER STORE', 'LIMIT 1 COLOR PER STORE', 'FREE WITH 2 UNITS BOUGHT', 'FREE WITH 3 UNITS BOUGHT' ), $review );
if ( 'store' !== $terms['limit'] || 2 !== $terms['free_with'] || array( 'LIMIT 1 COLOR PER STORE', 'FREE WITH 3 UNITS BOUGHT' ) !== $terms['other'] || 2 !== count( $review ) ) {
	fail( 'Conflicting limits or offers must stay whole in Other terms and be reported.' );
}
// Clean-up of arbitrary input.
$clean = ultralove_rn_terms_clean( array( 'limit' => 'nope', 'free_with' => '-4', 'free_per' => 'flavor', 'tags' => array( 'retail_demo', 'bogus', 'seasonal', 'seasonal' ), 'other' => array( '  a   b ', 'A B', '' ) ) );
if ( $clean !== array( 'limit' => '', 'free_with' => 0, 'free_per' => '', 'no_discounts' => false, 'tags' => array( 'seasonal', 'retail_demo' ), 'other' => array( 'a b' ) ) ) {
	fail( 'Terms were not forced into a valid shape: ' . json_encode( $clean ) );
}
if ( '' !== ultralove_rn_terms_encode( array() ) || ! ultralove_rn_terms_empty( array( 'limit' => 'bad' ) ) || null !== ultralove_rn_terms_decode( 'not json' ) ) {
	fail( 'Empty terms must store nothing, and bad JSON must not decode.' );
}
if ( array( 'Seasonal, limited supply', 'Retail demo unit' ) !== ultralove_rn_terms_tag_labels( array( 'tags' => array( 'retail_demo', 'seasonal' ) ) ) ) {
	fail( 'Tag labels keep a fixed order.' );
}
// Longest sentence-case card line must fit the estimate of one row on desktop.
foreach ( ultralove_rn_limit_options() as $option ) {
	if ( mb_strlen( $option['label'] ) > 24 ) {
		fail( 'A limit label is longer than one estimated desktop row: ' . $option['label'] );
	}
}
echo "All retailer terms checks passed\n";
