<?php
/** Offline regression for the importer apply/merge path. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) { $GLOBALS['actions'][] = $args; }
function __( $text, $domain ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function add_filter( ...$args ) { $GLOBALS['filters'][] = $args; }
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
class WC_Product {
	public function get_id(): int { return 1; }
}
function woocommerce_product_description_tab() { echo '<p>Product description</p>'; }
$product = new WC_Product();
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
$meta = array(
	ULTRALOVE_RN_INFO => "Manual <note>\nSecond line",
	ULTRALOVE_RN_IMPORTANT => 'Limit "one" & only',
);
ob_start();
$callback = static function ( $key, $tab ) {
	if ( 'description' !== $key || 'Original description' !== $tab['title'] ) {
		throw new RuntimeException( 'Description callback arguments changed.' );
	}
	echo '<p>Original content</p>';
};
$tabs = ultralove_rn_description_tab( array(
	'description' => array( 'title' => 'Original description', 'callback' => $callback ),
	'reviews' => array( 'callback' => 'unchanged' ),
) );
call_user_func( $tabs['description']['callback'], 'description', $tabs['description'] );
$editor = ob_get_clean();
foreach ( $meta as $key => $value ) {
	foreach ( explode( "\n", $value ) as $line ) {
		if ( ! str_contains( $editor, esc_html( $line ) ) ) {
			throw new RuntimeException( 'Description tab lost an escaped retailer note.' );
		}
	}
}
if ( 1 !== substr_count( $editor, 'ul-retailer-notes--single' )
	|| strpos( $editor, 'ul-retailer-notes__important' ) > strpos( $editor, 'ul-retailer-notes__info' )
	|| strpos( $editor, 'ul-retailer-notes__info' ) > strpos( $editor, 'Original content' )
	|| 'unchanged' !== $tabs['reviews']['callback'] ) {
	throw new RuntimeException( 'Retailer notes are duplicated, misplaced, or changed other tabs.' );
}
$summary_hooks = array_values( array_filter( $GLOBALS['actions'], static fn( $action ) =>
	'woocommerce_single_product_summary' === $action[0] && 'ultralove_rn_show_single' === $action[1]
) );
if ( 1 !== count( $summary_hooks ) || 11 !== $summary_hooks[0][2] ) {
	throw new RuntimeException( 'Retailer notes must also appear once in the original above-price position.' );
}
$tabs = ultralove_rn_description_tab( array() );
ob_start();
call_user_func( $tabs['description']['callback'], 'description', $tabs['description'] );
if ( ! str_contains( ob_get_clean(), 'ul-retailer-notes--single' ) ) {
	throw new RuntimeException( 'Notes disappeared on a product without a long description.' );
}
$meta = array();
if ( array() !== ultralove_rn_description_tab( array() ) ) {
	throw new RuntimeException( 'A notes-free product gained an empty description tab.' );
}
echo "All runtime checks passed\n";
