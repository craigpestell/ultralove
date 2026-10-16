<?php
/** Offline regression for the importer apply/merge path, the product editor fields and the storefront markup. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) { $GLOBALS['actions'][] = $args; }
function __( $text, $domain = '' ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_textarea( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html__( $text, $domain ) { return esc_html( $text ); }
function esc_attr__( $text, $domain ) { return esc_attr( $text ); }
function add_filter( ...$args ) { $GLOBALS['filters'][] = $args; }
function wp_slash( $value ) { return addslashes( $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
class WC_Admin_Meta_Boxes {
	public static array $errors = array();
	public static function add_error( $message ) { self::$errors[] = $message; }
}
function get_post_field( $field, $id, $context ) { return $GLOBALS['title']; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $key ] = stripslashes( $value ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $key ] ); }
function wc_get_product( $id ) {
	return new class {
		private string $name;
		public function set_name( string $name ): void { $this->name = $name; }
		public function save(): void { $GLOBALS['title'] = $this->name; }
	};
}
require __DIR__ . '/../../wp-content/mu-plugins/41-product-retailer-notes.php';

/** The stored terms of the test product, or null. */
function stored_terms(): ?array {
	$json = $GLOBALS['meta'][ ULTRALOVE_RN_TERMS ] ?? '';
	return '' === $json ? null : json_decode( $json, true );
}
function fail( string $message ): void { throw new RuntimeException( $message ); }

$offer = 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT';
$supplier = 'WE3125<br> Sync Lite<br> Tester Kit<br><b>' . $offer . '</b>';
$title = $supplier;
class WC_Product {
	public function get_id(): int { return 1; }
}
function woocommerce_product_description_tab() { echo '<p>Product description</p>'; }
$product = new WC_Product();

// Importer: the title's offer joins what the product already has; both old fields end up in the terms.
$meta = array(
	ULTRALOVE_RN_ORIGINAL => 'Preserved original',
	ULTRALOVE_RN_INFO => "Manual note\n" . strtolower( $offer ),
	ULTRALOVE_RN_IMPORTANT => 'Existing important note',
);
if ( ! ultralove_rn_apply_to_product( 1 ) || $title !== 'WE3125<br> Sync Lite<br> Tester Kit' ) {
	fail( 'Importer classification lost identity or did not report the change.' );
}
$expected_terms = array( 'v' => 1, 'limit' => 'store', 'free_with' => 2, 'other' => array( 'Existing important note', 'Manual note' ) );
if ( stored_terms() !== $expected_terms || isset( $meta[ ULTRALOVE_RN_INFO ] ) || isset( $meta[ ULTRALOVE_RN_IMPORTANT ] ) || $meta[ ULTRALOVE_RN_ORIGINAL ] !== 'Preserved original' ) {
	fail( "Importer duplicated an offer, dropped existing content or left the old fields behind. " . json_encode( $meta ) );
}
$expected = $meta;
$title = $supplier;
ultralove_rn_apply_to_product( 1 );
if ( $meta !== $expected || ultralove_rn_apply_to_product( 1 ) ) {
	fail( 'Repeated import/apply was not idempotent.' );
}

// A product with only the offer in its name: the original title is saved.
$title = $supplier;
$meta = array( ULTRALOVE_RN_INFO => $offer );
ultralove_rn_apply_to_product( 1 );
if ( stored_terms() !== array( 'v' => 1, 'limit' => 'store', 'free_with' => 2 ) || $meta[ ULTRALOVE_RN_ORIGINAL ] !== $supplier || isset( $meta[ ULTRALOVE_RN_INFO ] ) ) {
	fail( 'Offer was not stored as terms, or the original was not saved.' );
}

// Tester variant keeps its identity and the existing note.
$supplier = 'W637718<br> Womanizer Liberty <b> Leopard Tester<br>' . $offer . '</b>';
$title = $supplier;
$meta = array( ULTRALOVE_RN_INFO => 'Manual retailer note' );
ultralove_rn_apply_to_product( 1 );
if ( 'W637718<br> Womanizer Liberty Leopard Tester' !== $title
	|| stored_terms() !== array( 'v' => 1, 'limit' => 'store', 'free_with' => 2, 'other' => array( 'Manual retailer note' ) )
	|| $supplier !== $meta[ ULTRALOVE_RN_ORIGINAL ] ) {
	fail( 'Importer lost the tester variant or existing retailer notes.' );
}
$expected = $meta;
$title = $supplier;
ultralove_rn_apply_to_product( 1 );
if ( $meta !== $expected || ultralove_rn_apply_to_product( 1 ) ) {
	fail( 'Tester variant import was not idempotent.' );
}
$title = 'SKU<br> Product<br><b>Tester FREE WITH MYSTERY OFFER</b>';
$before_ambiguous = $title;
if ( ultralove_rn_apply_to_product( 1 ) || $title !== $before_ambiguous || $meta !== $expected ) {
	fail( 'Importer must skip ambiguous tester wording without modifying data.' );
}

// Conflicting offers are kept whole and reported, never merged into an invented offer.
$title = 'SKU<br> Product<br><b>ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT</b>';
$meta = array( ULTRALOVE_RN_IMPORTANT => 'FREE WITH 2 UNITS BOUGHT' );
ultralove_rn_apply_to_product( 1 );
if ( stored_terms()['free_with'] !== 2 || ! in_array( 'FREE WITH 3 UNITS BOUGHT', stored_terms()['other'], true ) ) {
	fail( 'A conflicting free offer must stay whole in Other terms.' );
}

// Old fields on a product that is not migrated yet read as terms (before any migration), and move over on import.
$title = 'Already clean product';
$meta = array( ULTRALOVE_RN_INFO => 'ONE PER STORE ONLY', ULTRALOVE_RN_IMPORTANT => $offer );
$before = $meta;
if ( ultralove_rn_get_terms( 1 )['limit'] !== 'store' || ultralove_rn_get_terms( 1 )['free_with'] !== 2 || $meta !== $before ) {
	fail( 'Old fields must read as terms without being rewritten.' );
}
ultralove_rn_apply_to_product( 1 );
if ( $title !== 'Already clean product' || isset( $meta[ ULTRALOVE_RN_ORIGINAL ] ) || stored_terms() !== array( 'v' => 1, 'limit' => 'store', 'free_with' => 2 )
	|| isset( $meta[ ULTRALOVE_RN_INFO ] ) || ultralove_rn_apply_to_product( 1 ) ) {
	fail( 'Clean-title imports must leave the name alone, move the old fields into terms once and then stay quiet.' );
}
$before = $meta;

// Product editor save.
$post_terms = static fn( array $post ) => array_merge( array( 'ul_rn_present' => '1' ), $post );
$_POST = array( 'ul_rn_limit' => 'store' ); // not from the General tab: no marker
ultralove_rn_admin_save( 1 );
if ( $meta !== $before ) {
	fail( 'A save without the terms fields must leave the product alone.' );
}
$_POST = $post_terms( array( 'ul_rn_limit' => 'color', 'ul_rn_free_with' => '3', 'ul_rn_no_discounts' => '1', 'ul_rn_tags' => array( 'seasonal', 'bogus' ), 'ul_rn_other' => '' ) );
ultralove_rn_admin_save( 1 );
$saved = $meta;
if ( stored_terms() !== array( 'v' => 1, 'limit' => 'color', 'free_with' => 3, 'no_discounts' => true, 'tags' => array( 'seasonal' ) )
	|| isset( $meta[ ULTRALOVE_RN_INFO ] ) || isset( $meta[ ULTRALOVE_RN_IMPORTANT ] ) ) {
	fail( 'Admin save did not store the selections, drop unknown tags and remove the old fields.' );
}
ultralove_rn_admin_save( 1 );
if ( $meta !== $saved ) {
	fail( 'Saving twice changed the data.' );
}
$_POST = $post_terms( array( 'ul_rn_limit' => '', 'ul_rn_free_with' => '0', 'ul_rn_other' => '' ) );
ultralove_rn_admin_save( 1 );
if ( isset( $meta[ ULTRALOVE_RN_TERMS ] ) ) {
	fail( 'Clearing every control must remove the stored terms.' );
}
$meta = array( ULTRALOVE_RN_INFO => 'DISCOUNTS DO NOT APPLY' );
ultralove_rn_admin_save( 1 );
if ( ultralove_rn_get_terms( 1 ) !== ultralove_rn_terms_blank() ) {
	fail( 'Clearing the terms must not let a stale old field come back.' );
}
// Typed Other lines that are really a limit or an offer go to the matching control.
$_POST = $post_terms( array( 'ul_rn_other' => "One Per Store Only\nSupplier's note \\ keep" ) );
$_POST['ul_rn_other'] = addslashes( $_POST['ul_rn_other'] );
ultralove_rn_admin_save( 1 );
if ( stored_terms() !== array( 'v' => 1, 'limit' => 'store', 'other' => array( "Supplier's note \\ keep" ) ) ) {
	fail( 'Other terms were not sorted into controls, or slashes were altered.' );
}
WC_Admin_Meta_Boxes::$errors = array();
$_POST = $post_terms( array( 'ul_rn_other' => 'FREE WITH MYSTERY OFFER' ) );
ultralove_rn_admin_save( 1 );
if ( ! WC_Admin_Meta_Boxes::$errors || stored_terms()['other'] !== array( 'FREE WITH MYSTERY OFFER' ) ) {
	fail( 'Ambiguous admin wording must be preserved and reported.' );
}
WC_Admin_Meta_Boxes::$errors = array();
$_POST = $post_terms( array( 'ul_rn_limit' => 'store', 'ul_rn_other' => 'LIMIT 1 COLOR PER STORE' ) );
ultralove_rn_admin_save( 1 );
if ( ! WC_Admin_Meta_Boxes::$errors || stored_terms()['limit'] !== 'store' || stored_terms()['other'] !== array( 'LIMIT 1 COLOR PER STORE' ) ) {
	fail( 'Two different limits must both be kept and reported.' );
}
$_POST = array();

// Product editor fields: the saved choices come back selected.
$post = (object) array( 'ID' => 1 );
$meta = array( ULTRALOVE_RN_TERMS => json_encode( array( 'v' => 1, 'limit' => 'color', 'free_with' => 3, 'no_discounts' => true, 'tags' => array( 'limited_edition' ), 'other' => array( 'Free display <b>' ) ) ) );
ob_start();
ultralove_rn_admin_fields();
$form = ob_get_clean();
foreach ( array(
	'<option value="color" data-card="Limit 1 color per store" selected>',
	'<option value="3" selected>',
	'id="ul_rn_no_discounts" name="ul_rn_no_discounts" value="1" checked',
	'value="limited_edition" checked',
	'Free display &lt;b&gt;',
	'name="ul_rn_present"',
	'<span>Limit 1 color per store</span><span>Free with 3 units</span><span>Discounts do not apply</span><span>Free display &lt;b&gt;</span>',
) as $needle ) {
	if ( ! str_contains( $form, $needle ) ) {
		fail( 'Product editor fields lost: ' . $needle );
	}
}
if ( str_contains( $form, 'value="seasonal" checked' ) || str_contains( $form, 'presets' ) ) {
	fail( 'Product editor shows a tag that is not set, or the retired preset dropdowns.' );
}

// Storefront: card.
$meta = array( ULTRALOVE_RN_TERMS => json_encode( array( 'v' => 1, 'limit' => 'color', 'free_with' => 3, 'no_discounts' => true, 'tags' => array( 'seasonal' ) ) ) );
$card = ultralove_rn_markup( 1, 'card' );
if ( ! str_contains( $card, '<span class="ul-retailer-notes__line">Limit 1 color per store</span><span class="ul-retailer-notes__line">Free with 3 units</span><span class="ul-retailer-notes__line">Discounts do not apply</span>' )
	|| str_contains( $card, 'tags' ) || str_contains( $card, 'Seasonal' ) || str_contains( $card, 'Retailer terms:' ) ) {
	fail( 'Card shows the wrong text: short wording, no label and no status tags.' );
}
ob_start();
ultralove_rn_card_open();
ultralove_rn_show_card();
ultralove_rn_card_close();
$box = ob_get_clean();
if ( ! preg_match( '/^<div class="ul-rn-box ul-rn-d3 ul-rn-m4">.*<\/div><\/div>$/s', $box ) || 1 !== preg_match( '/^<div class="ul-rn-box/', $box ) ) {
	fail( 'A card with terms must wrap name and terms in one box that reserves 3 title lines on desktop and 4 on phones for these three terms.' );
}
$hooks = array();
foreach ( $GLOBALS['actions'] as $action ) {
	if ( 'woocommerce_shop_loop_item_title' === $action[0] ) {
		$hooks[ $action[1] ] = $action[2];
	}
}
if ( 9 !== ( $hooks['ultralove_rn_card_open'] ?? null ) || 12 !== ( $hooks['ultralove_rn_show_card'] ?? null ) || 13 !== ( $hooks['ultralove_rn_card_close'] ?? null ) ) {
	fail( 'The box must open before the name (10) and close after the terms, before the brand (15).' );
}
// Only status tags: nothing on the card, no box.
$meta = array( ULTRALOVE_RN_TERMS => json_encode( array( 'v' => 1, 'tags' => array( 'seasonal' ) ) ) );
ob_start();
ultralove_rn_card_open();
ultralove_rn_show_card();
ultralove_rn_card_close();
if ( '' !== ob_get_clean() ) {
	fail( 'A product with only status tags must not change its card.' );
}
if ( ultralove_rn_terms_reserved_lines( array( 'Limit 1 per store' ), 24, 18 ) !== 1
	|| ultralove_rn_terms_reserved_lines( array( 'Limit 1 per store', 'Free with 3 units' ), 24, 18 ) !== 2
	|| ultralove_rn_terms_reserved_lines( array( 'MUST ORDER 2 OF EACH COLOR IN ORDER TO RECEIVE A FREE DISPLAY' ), 24, 18 ) !== 3
	|| ultralove_rn_terms_reserved_lines( array_fill( 0, 9, 'Limit 1 per store' ), 24, 18 ) !== 5 ) {
	fail( 'Reserved title lines are wrong (1 term -> 1 line, 2 -> 2, a 3-row note -> 3, capped at 5).' );
}

// Storefront: product page and tab.
$meta = array( ULTRALOVE_RN_TERMS => json_encode( array( 'v' => 1, 'limit' => 'store', 'free_with' => 2, 'no_discounts' => true, 'tags' => array( 'seasonal', 'retail_demo' ), 'other' => array( 'Limit "one" & only <x>' ) ) ) );
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
foreach ( array( 'Retailer terms:', 'Limit 1 per store', 'Free with 2 units bought', 'Discounts do not apply', 'Limit &quot;one&quot; &amp; only &lt;x&gt;', 'Seasonal, limited supply', 'Retail demo unit' ) as $text ) {
	if ( ! str_contains( $editor, $text ) ) {
		fail( 'Description tab lost: ' . $text );
	}
}
if ( 1 !== substr_count( $editor, 'ul-retailer-notes--single' )
	|| strpos( $editor, 'ul-retailer-notes__terms' ) > strpos( $editor, 'ul-retailer-notes__tags' )
	|| strpos( $editor, 'ul-retailer-notes__tags' ) > strpos( $editor, 'Original content' )
	|| 'unchanged' !== $tabs['reviews']['callback'] ) {
	fail( 'Retailer terms are duplicated, misplaced, or changed other tabs.' );
}
$summary_hooks = array_values( array_filter( $GLOBALS['actions'], static fn( $action ) =>
	'woocommerce_single_product_summary' === $action[0] && 'ultralove_rn_show_single' === $action[1]
) );
if ( 1 !== count( $summary_hooks ) || 11 !== $summary_hooks[0][2] ) {
	fail( 'Retailer terms must also appear once in the original summary position.' );
}
ob_start();
ultralove_rn_show_single();
$summary = ob_get_clean();
if ( 1 !== substr_count( $summary, 'ul-retailer-notes--single' ) || ! str_contains( $summary, 'Limit 1 per store' ) ) {
	fail( 'Original summary position lost the retailer terms.' );
}
$tabs = ultralove_rn_description_tab( array() );
ob_start();
call_user_func( $tabs['description']['callback'], 'description', $tabs['description'] );
if ( ! str_contains( ob_get_clean(), 'ul-retailer-notes--single' ) ) {
	fail( 'Terms disappeared on a product without a long description.' );
}
$meta = array();
if ( array() !== ultralove_rn_description_tab( array() ) ) {
	fail( 'A terms-free product gained an empty description tab.' );
}
echo "All runtime checks passed\n";
