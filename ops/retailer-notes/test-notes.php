<?php
/** Offline condition equivalence, classification and conservative-merge regressions. */
require_once __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/notes.php';

function expect_notes( array $before, array $expected, array $add = array() ): void {
	$after = ultralove_rn_merge_notes( $before, $add );
	if ( $after !== $expected || ultralove_rn_merge_notes( $after ) !== $after ) {
		throw new RuntimeException( 'Note normalization or idempotence failed: ' . json_encode( array( $before, $after ), JSON_THROW_ON_ERROR ) );
	}
}
$liberty = array( 'info' => array(), 'important' => array( 'LIMIT 1 PER STORE ONLY', 'FREE WITH 2 UNITS BOUGHT' ), 'review' => array() );
foreach ( array( 'NO EXCEPTIONS', "  no\t exceptions.  ", 'No Exceptions;' ) as $note ) {
	if ( array() !== ultralove_rn_note_clauses( $note ) ) {
		throw new RuntimeException( 'Recognized omission must return an empty clause list.' );
	}
}
foreach ( array( '', '  ', 'NO EXCEPTIONS for custom orders' ) as $note ) {
	if ( null !== ultralove_rn_note_clauses( $note ) ) {
		throw new RuntimeException( 'Empty or unknown prose must not be treated as recognized instructions.' );
	}
}
expect_notes( array( 'important' => array(
	'TESTER: 1 PER STORE ONLY FREE WITH 3 UNITS BOUGHT',
	'A MINIMUM OF 3 UNITS MUST BE PURCHASED TO RECEIVE A FREE TESTER - NO EXCEPTIONS',
) ), array( 'info' => array(), 'important' => array( 'LIMIT 1 PER STORE ONLY', 'FREE WITH 3 UNITS BOUGHT' ), 'review' => array() ) );
expect_notes( array( 'info' => array( 'No exceptions.' ), 'important' => array( 'NO EXCEPTIONS' ) ), array(
	'info' => array(), 'important' => array(), 'review' => array(),
) );
expect_notes( array( 'info' => array( 'Custom purchase terms - NO EXCEPTIONS' ) ), array(
	'info' => array( 'Custom purchase terms - NO EXCEPTIONS' ), 'important' => array(), 'review' => array(),
) );
expect_notes( array( 'important' => array(
	'FREE WITH 2 UNITS BOUGHT',
	'A MINIMUM OF 2 UNITS MUST BE PURHCASED TO RECEIVE A FREE TESTER',
) ), array( 'info' => array(), 'important' => array( 'FREE WITH 2 UNITS BOUGHT' ), 'review' => array() ) );
expect_notes( array(
	'info' => array( 'one per store only' ),
	'important' => array( 'limit 1 per store only' ),
), array( 'info' => array(), 'important' => array( 'LIMIT 1 PER STORE ONLY' ), 'review' => array() ) );
expect_notes( array(
	'important' => array( 'LIMIT 1 PER STORE ONLY FREE WITH 2 UNITS BOUGHT', 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ),
), $liberty );
expect_notes( array(
	'info' => array( 'ONE PER STORE ONLY' ),
	'important' => array( 'LIMIT 1 PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ),
), $liberty );
expect_notes( array(
	'info' => array( 'one per store', 'Free with 2 units bought.', 'Manual note' ),
	'important' => array( 'DISCOUNTS DO NOT APPLY' ),
), array( 'info' => array( 'DISCOUNTS DO NOT APPLY', 'Manual note' ), 'important' => $liberty['important'], 'review' => array() ) );
expect_notes( array( 'info' => array( 'NO DISCOUNTS APPLY', 'Discounts Do Not Apply', 'NO FURTHER DISCOUNTS APPLY' ) ), array(
	'info' => array( 'DISCOUNTS DO NOT APPLY', 'NO FURTHER DISCOUNTS APPLY' ), 'important' => array(), 'review' => array(),
) );
expect_notes( array( 'info' => array( 'Manual note' ), 'important' => array( 'manual note' ) ), array(
	'info' => array(), 'important' => array( 'manual note' ), 'review' => array(),
) );
expect_notes( array( 'important' => array(
	'ONE COLOR PER STORE ONLY', 'limit 1 colour per store', 'ONE BOTTLE PER STORE',
	'1 PER TESTER ONLY', 'ONE STAND PER STORE', 'ONE PER STORE',
) ), array( 'info' => array(), 'important' => array(
	'LIMIT 1 COLOR PER STORE ONLY', 'LIMIT 1 BOTTLE PER STORE ONLY',
	'LIMIT 1 PER TESTER ONLY', 'LIMIT 1 STAND PER STORE ONLY', 'LIMIT 1 PER STORE ONLY',
), 'review' => array() ) );
foreach ( array(
	'FREE WITH 3 UNITS BOUGHT PER FLAVOR',
	'FREE WITH 9 (3 EACH) PRODUCTS BOUGHT',
	'FREE WITH 2 EACH OF THE JO8015 LINE PURCHASED',
	'FREE WITH PURCHASE OF 12 ESSENTIAL BULLETS',
) as $offer ) {
	expect_notes( array( 'important' => array( $offer . ' 1 Per Store Only' ) ), array(
		'info' => array(), 'important' => array( $offer, 'LIMIT 1 PER STORE ONLY' ), 'review' => array(),
	) );
}
$conflict = array( 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT', 'LIMIT 1 PER STORE ONLY FREE WITH 3 UNITS BOUGHT' );
expect_notes( array( 'important' => $conflict ), array(
	'info' => array(), 'important' => $conflict,
	'review' => array( 'Different purchase conditions: preserve whole offers for manual review.' ),
) );
$qualified_conflict = array( 'FREE WITH 2 UNITS BOUGHT NO EXCEPTIONS', 'FREE WITH 3 UNITS BOUGHT' );
expect_notes( array( 'important' => $qualified_conflict ), array(
	'info' => array(), 'important' => $qualified_conflict,
	'review' => array( 'Different purchase conditions: preserve whole offers for manual review.' ),
) );
$display_conflict = array( 'MUST ORDER 2 OF EACH COLOR IN ORDER TO RECEIVE A FREE DISPLAY', 'MUST ORDER 4 ASSORTED COLORS IN ORDER TO RECEIVE A FREE DISPLAY' );
expect_notes( array( 'info' => $display_conflict ), array(
	'info' => $display_conflict, 'important' => array(),
	'review' => array( 'Different purchase conditions: preserve whole offers for manual review.' ),
) );
foreach ( array(
	'ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT',
	'ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT Color May Vary',
	'DISPLAY KIT ONLY ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT',
	'LIMIT 2 PER STORE ONLY', 'FREE WITH MYSTERY OFFER',
) as $unknown ) {
	expect_notes( array( 'info' => array( $unknown ) ), array(
		'info' => array( $unknown ), 'important' => array(),
		'review' => array( 'Unrecognized combined condition: ' . $unknown ),
	) );
}
expect_notes( array( 'info' => array( 'Ships in Non-Retail Packaging DISCOUNTS DO NOT APPLY' ) ), array(
	'info' => array( 'Ships in Non-Retail Packaging DISCOUNTS DO NOT APPLY' ), 'important' => array(), 'review' => array(),
) );
expect_notes( $liberty, $liberty, array( 'info' => array( 'one per store only' ) ) );
echo "All retailer condition normalization checks passed\n";
