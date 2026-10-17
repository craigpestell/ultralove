<?php
define( 'ULTRALOVE_SKU_AUDIT_LIB_ONLY', true );
require __DIR__ . '/audit-skus.php';
$cases = array(
	array( 'UL-1234 Rose Lip Gloss', 'UL-1234', 'match', 'Rose Lip Gloss' ),
	array( 'UL-1234 - Rose Lip Gloss', 'UL-1234', 'match', 'Rose Lip Gloss' ),
	array( 'ul-1234 Rose', 'UL-1234', 'case_only', 'Rose' ),
	array( 'UL1234 Rose', 'UL-1234', 'format_only', 'Rose' ),
	array( 'UL-1235 Rose', 'UL-1234', 'mismatch', 'Rose' ),
	array( 'UL-12345 Rose', 'UL-1234', 'mismatch', 'Rose' ),
	array( 'UL-1234 Rose', '', 'no_sku_field', 'Rose' ),
	array( 'AB 12 Rose &amp; Co', 'AB 12', 'match', 'Rose & Co' ),
	array( '<b>UL-1</b> Rose', 'UL-1', 'match', 'Rose' ),
	array( "UL-1\xC2\xA0Rose", 'UL-1', 'match', 'Rose' ),
	array( '', 'UL-1', 'empty_title', '' ),
	array( 'UL-1', 'UL-1', 'match', '' ),
);
$fail = 0;
foreach ( $cases as $c ) {
	$r = ultralove_sku_audit_classify( $c[0], $c[1] );
	if ( $r['result'] !== $c[2] || $r['stripped_name'] !== $c[3] ) {
		$fail++;
		echo "FAIL: ", json_encode( $c ), ' => ', json_encode( $r ), "\n";
	}
}
echo $fail ? "$fail failed\n" : "all " . count( $cases ) . " passed\n";
exit( $fail ? 1 : 0 );
