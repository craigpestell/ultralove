<?php
/** Offline checks for product-editor preset data, wiring and asset scope. */
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) { $GLOBALS['actions'][] = $args; }
function add_filter( ...$args ) {}
function __( $text, $domain ) { return $text; }
function woocommerce_wp_select( $args ) { $GLOBALS['fields'][] = array( 'select', $args ); }
function woocommerce_wp_textarea_input( $args ) { $GLOBALS['fields'][] = array( 'textarea', $args ); }
function get_current_screen() { return $GLOBALS['screen']; }
function plugins_url( $path, $file ) { return '/wp-content/mu-plugins' . $path; }
function wp_enqueue_script( ...$args ) { $GLOBALS['scripts'][] = $args; }
require __DIR__ . '/../../wp-content/mu-plugins/41-product-retailer-notes.php';

$inventory = file_get_contents( __DIR__ . '/../../documentation/retailer-info-inventory.md' );
$sections = explode( '## Important retailer info', $inventory );
$expected = array();
foreach ( array( ULTRALOVE_RN_INFO => $sections[0], ULTRALOVE_RN_IMPORTANT => $sections[1] ) as $key => $section ) {
	preg_match_all( '/^\| ([^|]+) \| (\d+) \|$/m', $section, $rows, PREG_SET_ORDER );
	foreach ( $rows as $row ) {
		if ( (int) $row[2] >= 5 ) {
			$expected[ $key ][] = str_replace( ' / ', "\n", trim( $row[1] ) );
		}
	}
}
if ( ultralove_rn_admin_presets() !== $expected
	|| 3 !== count( $expected[ ULTRALOVE_RN_INFO ] )
	|| 12 !== count( $expected[ ULTRALOVE_RN_IMPORTANT ] ) ) {
	throw new RuntimeException( 'Presets must match all inventory values used by at least five products, in frequency order.' );
}
ob_start();
ultralove_rn_admin_fields();
ob_end_clean();
foreach ( array( ULTRALOVE_RN_IMPORTANT, ULTRALOVE_RN_INFO ) as $index => $key ) {
	$select = $fields[ $index * 2 ];
	$textarea = $fields[ $index * 2 + 1 ];
	$options = array( '' => 'Choose text to append...' );
	$sorted = $expected[ $key ];
	usort( $sorted, 'strcasecmp' );
	foreach ( $sorted as $text ) {
		$options[ $text ] = str_replace( "\n", ' / ', $text );
	}
	if ( 'select' !== $select[0] || $key . '_preset' !== $select[1]['id']
		|| '' !== $select[1]['value'] || $options !== $select[1]['options']
		|| 'select ultralove-rn-preset' !== $select[1]['class']
		|| $key !== $select[1]['custom_attributes']['data-retailer-field']
		|| 'textarea' !== $textarea[0] || $key !== $textarea[1]['id'] ) {
		throw new RuntimeException( 'A preset is not paired with its original editable textarea.' );
	}
}
if ( 4 !== count( $fields ) ) {
	throw new RuntimeException( 'Expected exactly two dropdowns and two textareas.' );
}
$scripts = array();
foreach ( array( null, (object) array( 'base' => 'edit', 'post_type' => 'product' ), (object) array( 'base' => 'post', 'post_type' => 'post' ) ) as $screen ) {
	ultralove_rn_admin_scripts();
}
if ( $scripts ) {
	throw new RuntimeException( 'Preset script leaked outside the product editor.' );
}
$screen = (object) array( 'base' => 'post', 'post_type' => 'product' );
ultralove_rn_admin_scripts();
$asset = __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/admin-presets.js';
if ( 1 !== count( $scripts ) || '/wp-content/mu-plugins/ultralove-retailer-notes/admin-presets.js' !== $scripts[0][1]
	|| (string) filemtime( $asset ) !== $scripts[0][3] || true !== $scripts[0][4] ) {
	throw new RuntimeException( 'Product editor asset URL, cache version or footer placement is incorrect.' );
}
echo "All admin preset PHP checks passed\n";
