<?php
/** Offline regression for the guarded repair preview, apply and verification. */
define( 'ABSPATH', __DIR__ );
define( 'WP_CLI', true );
class WP_CLI {
	public static function error( string $message ): void { throw new RuntimeException( $message ); }
	public static function success( string $message ): void {}
	public static function log( string $message ): void {}
	public static function add_command( ...$args ): void {}
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function home_url(): string { return 'https://dev.example.test'; }
function wp_json_encode( ...$args ) { return json_encode( ...$args ); }
function wp_slash( string $value ): string { return addslashes( $value ); }
function clean_post_cache( int $id ): void {}
function get_post_field( string $field, int $id, string $context ): string {
	return $GLOBALS['products'][ $id ][ 'post_title' === $field ? 'title' : 'slug' ];
}
function get_post_meta( int $id, string $key, bool $single ): string {
	return $GLOBALS['products'][ $id ][ $key ] ?? '';
}
function update_post_meta( int $id, string $key, string $value ): void {
	$GLOBALS['products'][ $id ][ $key ] = stripslashes( $value );
}
function wc_get_product( int $id ) {
	return new class( $id ) {
		public function __construct( private int $id ) {}
		public function set_name( string $name ): void { $GLOBALS['products'][ $this->id ]['title'] = $name; }
		public function save(): void {}
	};
}
require __DIR__ . '/../../wp-content/mu-plugins/41-product-retailer-notes.php';
$offer = 'ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT';
$products = array();
foreach ( array( 'Leopard Tester', 'Tester', 'Testers', 'Tester Kit', 'Tester Only', 'RETAIL TESTER', 'Display ONLY Kit (NO TESTER)' ) as $index => $identity ) {
	$products[ $index + 1 ] = array(
		'title' => 'SKU<br> Product',
		'slug' => 'existing-product-' . $index,
		ULTRALOVE_RN_ORIGINAL => 'SKU<br> Product <b>' . $identity . '<br>' . $offer . '</b>',
		ULTRALOVE_RN_INFO => 'Manual retailer note',
		ULTRALOVE_RN_IMPORTANT => $identity . ' ' . $offer . "\nLIMIT 1 PER STORE ONLY",
	);
}
$products[8] = $products[1];
$products[8]['title'] = 'SKU<br> Manually edited product';
$products[9] = $products[1];
$products[9][ ULTRALOVE_RN_ORIGINAL ] = 'SKU<br> Product<br><b>ONE PER STORE ONLY FREE WITH TESTER &amp; 3 UNITS BOUGHT</b>';
$products[9][ ULTRALOVE_RN_IMPORTANT ] = 'ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT';
$products[10] = $products[1];
$products[10][ ULTRALOVE_RN_ORIGINAL ] = 'SKU<br> Product<br><b>Tester FREE WITH MYSTERY OFFER</b>';
$products[10][ ULTRALOVE_RN_IMPORTANT ] = 'Tester FREE WITH MYSTERY OFFER';
$products[11] = $products[1];
$products[11][ ULTRALOVE_RN_IMPORTANT ] = 'Leopard Tester FREE WITH 9 UNITS BOUGHT';
$products[12] = $products[1];
$products[12]['title'] = 'SKU<br> Product Leopard Tester';
$products[13] = $products[1];
$products[13][ ULTRALOVE_RN_ORIGINAL ] = 'SKU<br> Product TESTER<br>ONE PER STORE ONLY<br> FREE WITH 2 UNITS BOUGHT</b>';
$products[13][ ULTRALOVE_RN_IMPORTANT ] = 'TESTER ' . $offer;
$products[14] = $products[1];
$products[14][ ULTRALOVE_RN_ORIGINAL ] = 'SKU<br> Product<br><b>ESTER ' . $offer . '</b>';
$products[14][ ULTRALOVE_RN_IMPORTANT ] = 'TESTER ' . $offer;
$products[15] = $products[1];
$products[15][ ULTRALOVE_RN_ORIGINAL ] = 'SKU<br> Product<br><b>Tester</b>';
$products[15][ ULTRALOVE_RN_IMPORTANT ] = 'Tester';
$products[16] = $products[1];
$products[16][ ULTRALOVE_RN_INFO ] = $products[16][ ULTRALOVE_RN_IMPORTANT ];
$products[16][ ULTRALOVE_RN_IMPORTANT ] = 'Manual important note';
$wpdb = new class {
	public string $posts = 'posts';
	public function get_col( string $query ): array { return array_keys( $GLOBALS['products'] ); }
};
$path = tempnam( sys_get_temp_dir(), 'ul-tester-repairs-' );
if ( false === $path || ! unlink( $path ) ) {
	throw new RuntimeException( 'Cannot allocate private test plan.' );
}
try {
	$args = array( 'preview', $path );
	require __DIR__ . '/repair-products.php';
	$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( array_column( $plan['rows'], 'id' ) !== array( 1, 2, 3, 4, 5, 6, 7, 13, 14, 15, 16 )
		|| ! in_array( 8, $plan['flagged'], true )
		|| ! in_array( 10, $plan['flagged'], true )
		|| ! in_array( 11, $plan['flagged'], true ) ) {
		throw new RuntimeException( 'Repair scope failed identity, provenance or manual-edit guards.' );
	}
	foreach ( $plan['rows'] as $row ) {
		$id = $row['id'];
		$expected_title = ultralove_rn_split_title( $products[ $id ][ ULTRALOVE_RN_ORIGINAL ] )['title'];
		$expected_note = 15 === $id ? '' : ( $id <= 7 ? "LIMIT 1 PER STORE ONLY\n" : '' ) . $offer;
		if ( 16 === $id ) {
			$expected_note = 'Manual important note' . "\n" . $offer;
		}
		if ( $row['after']['title'] !== $expected_title
			|| $row['before']['slug'] !== $row['after']['slug']
			|| $row['after']['important'] !== $expected_note
			|| $row['after']['info'] !== ( 16 === $id ? 'LIMIT 1 PER STORE ONLY' : 'Manual retailer note' )
			|| $row['after']['original'] !== $row['before']['original'] ) {
			throw new RuntimeException( 'Repair lost identity, independent notes, original or slug on ' . $id );
		}
	}
	$before_apply = $products;
	$products[16]['slug'] = 'concurrent-url-edit';
	$drifted = $products;
	try {
		$args = array( 'apply', $path );
		require __DIR__ . '/repair-products.php';
		throw new RuntimeException( 'Apply accepted a stale slug snapshot.' );
	} catch ( RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), 'Product 16 differs from the plan' ) || $products !== $drifted ) {
			throw $error;
		}
	}
	$products = $before_apply;
	$args = array( 'apply', $path );
	require __DIR__ . '/repair-products.php';
	$args = array( 'verify', $path );
	require __DIR__ . '/repair-products.php';
	if ( ! unlink( $path ) ) {
		throw new RuntimeException( 'Cannot remove first test plan.' );
	}
	$args = array( 'preview', $path );
	require __DIR__ . '/repair-products.php';
	$repeat = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( $repeat['rows'] ) {
		throw new RuntimeException( 'Repair is not idempotent.' );
	}
} finally {
	if ( file_exists( $path ) && ! unlink( $path ) ) {
		throw new RuntimeException( 'Cannot remove private test plan.' );
	}
}
echo "All guarded repair checks passed\n";
