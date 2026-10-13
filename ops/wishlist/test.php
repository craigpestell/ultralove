<?php
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
	throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
$hooks = array();
$logged_in = false;
$preview = false;
$preview_icons = false;
$icons = array( 'wishlist' );
$options = array();
$nonce_valid = true;
$wishlist = false;
$lookups = 0;
$lookup_arguments = array();
$no_cache = 0;
function add_action( $name, $callback, $priority = 10 ) { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function __( $text, $domain ) { return $text; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function is_customize_preview() { return $GLOBALS['preview']; }
function martfury_get_option( $name ) { return $GLOBALS['icons']; }
function apply_filters( $name, $value ) { return $name === 'martfury_preview_featured_icons' ? $GLOBALS['preview_icons'] : $value; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function add_query_arg( $key, $value, $url = null ) {
	if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
	return $url . '?' . http_build_query( array( $key => $value ) );
}
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=fixture'; }
function wc_get_page_permalink( $page ) { return 'https://example.test/my-account/'; }
function YITH_WCWL() {}
function check_ajax_referer( $action, $name, $die ) {
	if ( $action !== 'load_fragments' || $name !== 'nonce' || $die ) { throw new RuntimeException( 'Unexpected nonce contract.' ); }
	return $GLOBALS['nonce_valid'];
}
function yith_wcwl_get_wishlist( ...$args ) {
	++$GLOBALS['lookups'];
	$GLOBALS['lookup_arguments'] = $args;
	return $GLOBALS['wishlist'];
}
function nocache_headers() { ++$GLOBALS['no_cache']; }
class Wishlist_Test_Response extends RuntimeException {
	public function __construct( public array $data, public int $status ) { parent::__construct(); }
}
function wp_send_json_error( $data, $status ) { throw new Wishlist_Test_Response( array( 'success' => false, 'data' => $data ), $status ); }
function wp_send_json_success( $data ) { throw new Wishlist_Test_Response( array( 'success' => true, 'data' => $data ), 200 ); }
class WC_Product {
	public function get_id() { return 42; }
	public function get_type() { return 'simple'; }
	public function get_parent_id() { return 0; }
	public function get_name() { return 'Fixture "quoted" <script>invalid</script>'; }
	public function get_permalink() { return 'https://example.test/product/fixture/'; }
}
require __DIR__ . '/../../wp-content/themes/martfury-child/inc/product-card-wishlist.php';
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
};
$render = static function (): string {
	ob_start();
	ultralove_product_card_wishlist();
	return ob_get_clean();
};
$respond = static function (): Wishlist_Test_Response {
	try { ultralove_wishlist_state(); } catch ( Wishlist_Test_Response $response ) { return $response; }
	throw new RuntimeException( 'Endpoint failed to send a response.' );
};
$assert( $hooks['martfury_after_product_loop_thumbnail_link'][90] === array( 'ultralove_product_card_wishlist' ), 'Render inside the existing thumbnail bar.' );
foreach ( array( 'wp_ajax_ultralove_wishlist_state', 'wp_ajax_nopriv_ultralove_wishlist_state' ) as $hook ) {
	$assert( $hooks[$hook][10] === array( 'ultralove_wishlist_state' ), 'State endpoint must support accounts and guests.' );
}
$product = new WC_Product();
$guest_html = $render();
$assert( str_contains( $guest_html, 'ultralove-wishlist-heart' ), 'Hearts must be present in the server response.' );
$assert( ! str_contains( $guest_html, 'wishlist-fragment' ) && ! str_contains( $guest_html, 'on-first-load' ), 'Cards must not participate in YITH HTML fragment loading.' );
$assert( ! str_contains( $guest_html, 'class="footer-button"' ), 'Reuse the parent bar instead of nesting bars.' );
$assert( str_contains( $guest_html, 'data-product-id="42"' ) && str_contains( $guest_html, 'data-product-type="simple"' ), 'Preserve the YITH add-action data contract.' );
$assert( str_contains( $guest_html, 'data-ul-add-url="https://example.test/product/fixture/?add_to_wishlist=42&amp;_wpnonce=fixture"' ), 'Keep the add URL available when a saved heart is cloned or its state changes.' );
$assert( str_contains( $guest_html, 'aria-label="Add to wishlist"' ) && str_contains( $guest_html, 'aria-hidden="true"' ), 'Expose the action label, not the decorative SVG.' );
$assert( ! str_contains( $guest_html, '<script>' ) && str_contains( $guest_html, '&lt;script&gt;' ), 'Escape product metadata.' );
$assert( $lookups === 0, 'Rendering cards must perform no wishlist lookups.' );
$logged_in = true;
$assert( $render() === $guest_html, 'Accounts and guests must use the same state-neutral renderer.' );
$logged_in = false;
$_COOKIE = array( 'yith_wcwl_session_fixture' => 'existing-session' );
$assert( $render() === $guest_html, 'An existing guest session must not personalize cached card HTML.' );
$icons = array();
$assert( substr_count( $render(), 'class="footer-button"' ) === 1, 'Provide a bar when parent featured icons are disabled.' );
$icons = array( 'wishlist' );
$preview = true;
$assert( substr_count( $render(), 'class="footer-button"' ) === 1, 'Customizer-hidden parent bars still need one heart bar.' );
$preview_icons = true;
$assert( ! str_contains( $render(), 'class="footer-button"' ), 'Customizer-visible bars must not be duplicated.' );
$preview = false;
$options['yith_wcwl_disable_wishlist_for_unauthenticated_users'] = 'yes';
$restricted = $render();
$assert( str_contains( $restricted, 'class="disabled_item"' ) && str_contains( $restricted, 'https://example.test/my-account/' ), 'Preserve guest login restrictions.' );
$assert( ! str_contains( $restricted, 'class="add_to_wishlist' ), 'Restricted guests must not get an actionable add button.' );
$logged_in = true;
$assert( str_contains( $render(), 'class="add_to_wishlist single_add_to_wishlist"' ), 'Account holders retain add actions when guests are restricted.' );
$product = null;
$assert( $render() === '', 'Non-product loop contexts must not emit controls.' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$response = $respond();
$assert( $response->status === 405 && $response->data['success'] === false && $lookups === 0, 'Reject the wrong HTTP method before reading a list.' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$nonce_valid = false;
$response = $respond();
$assert( $response->status === 403 && $response->data['success'] === false && $lookups === 0, 'Reject invalid nonces before reading a list.' );
$nonce_valid = true;
$response = $respond();
$assert( $response->data === array( 'success' => true, 'data' => array( 'product_ids' => array() ) ), 'Visitors without a list receive an empty array, not HTML.' );
$wishlist = new class {
	public function get_items() {
		return array_map( static fn ( $id ) => new class( $id ) {
			public function __construct( private int $id ) {}
			public function get_product_id() { return $this->id; }
		}, array_merge( range( 1, 40 ), array( 1, 0 ) ) );
	}
};
$_POST = array( 'owner_id' => 999, 'wishlist_id' => 999 );
$before = $lookups;
$response = $respond();
$assert( $lookups === $before + 1 && $lookup_arguments === array(), 'Resolve the current visitor once and ignore browser-supplied ownership.' );
$assert( $response->data['data']['product_ids'] === range( 1, 40 ), 'Return deduplicated integer IDs with a stable array shape.' );
$assert( strlen( json_encode( $response->data ) ) < 250, 'Forty saved products must use under 250 uncompressed response bytes.' );
$assert( $no_cache === 2, 'Successful personalized responses must disable shared caching.' );
echo "Wishlist: {$checks} assertions passed.\n";
