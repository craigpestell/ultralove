<?php
/** Offline regression for the importer apply/merge path. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) {}
function wp_slash( $value ) { return addslashes( $value ); }
function get_post_field( $field, $id, $context ) { return $GLOBALS['title']; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $key ] = stripslashes( $value ); }
function wc_get_product( $id ) {
	return new class {
		private string $name;
		public function set_name( string $name ): void { $this->name = $name; }
		public function save(): void { $GLOBALS['title'] = $this->name; }
	};
}
require __DIR__ . '/../../wp-content/mu-plugins/41-product-retailer-notes.php';

$offer = 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT';
$supplier = 'WE3125<br> Sync Lite<br> Tester Kit<br><b>' . $offer . '</b>';
$title = $supplier;
$meta = array(
	ULTRALOVE_RN_ORIGINAL => 'Preserved original',
	ULTRALOVE_RN_INFO => "Manual note\n" . strtolower( $offer ),
	ULTRALOVE_RN_IMPORTANT => 'Existing important note',
);
if ( ! ultralove_rn_apply_to_product( 1 )
	|| $title !== 'WE3125<br> Sync Lite<br> Tester Kit'
	|| $meta[ ULTRALOVE_RN_INFO ] !== 'Manual note'
	|| $meta[ ULTRALOVE_RN_IMPORTANT ] !== "Existing important note\n" . $offer
	|| $meta[ ULTRALOVE_RN_ORIGINAL ] !== 'Preserved original' ) {
	throw new RuntimeException( 'Importer classification lost identity, duplicated an offer, or changed existing content.' );
}
$expected = $meta;
$title = $supplier;
ultralove_rn_apply_to_product( 1 );
if ( $meta !== $expected || ultralove_rn_apply_to_product( 1 ) ) {
	throw new RuntimeException( 'Repeated import/apply was not idempotent.' );
}
$title = $supplier;
$meta = array( ULTRALOVE_RN_INFO => $offer );
ultralove_rn_apply_to_product( 1 );
if ( '' !== $meta[ ULTRALOVE_RN_INFO ] || $meta[ ULTRALOVE_RN_IMPORTANT ] !== $offer || $meta[ ULTRALOVE_RN_ORIGINAL ] !== $supplier ) {
	throw new RuntimeException( 'Last regular note was not cleared or original was not saved.' );
}
echo "All runtime checks passed\n";
