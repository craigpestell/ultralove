<?php
/**
 * Offline presentation regressions. Run: php ops/media-library/test.php
 */
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
	throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
$access = true;
$available = true;
$empty = false;
$registered_styles = array();
$inline_styles = array();
$scripts = array();
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function is_user_logged_in() { return $GLOBALS['access']; }
function get_current_user_id() { return 1; }
function __( $text, $domain = '' ) { return $text; }
function _n( $one, $many, $count, $domain = '' ) { return $count === 1 ? $one : $many; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
function wp_unslash( $text ) { return $text; }
function sanitize_text_field( $text ) { return strip_tags( $text ); }
function absint( $number ) { return abs( (int) $number ); }
function wp_kses( $text, $tags ) { return strip_tags( $text, '<strong><em>' ); }
function wp_kses_post( $text ) { return $text; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wc_get_endpoint_url( ...$args ) { return '/my-account/media-library/'; }
function wc_get_page_permalink( $page ) { return '/my-account/'; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url = '' ) {
	if ( is_array( $key ) ) { return $value . '?' . http_build_query( $key ); }
	return $url . '?' . $key . '=' . $value;
}
function wp_nonce_url( $url, $action, $field ) { return $url . '&' . $field . '=fixture-nonce'; }
function wp_nonce_field( $action, $field ) { echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="fixture-nonce">'; }
function wp_dropdown_categories( $args ) {
	echo '<select name="' . esc_attr( $args['name'] ) . '" id="' . esc_attr( $args['id'] ) . '"><option>All categories</option></select>';
}
function paginate_links( $args ) {
	return '<a class="prev page-numbers" href="?ml_page=1">' . $args['prev_text'] . '</a><span class="page-numbers current" aria-current="page">2</span><a class="next page-numbers" href="?ml_page=3">' . $args['next_text'] . '</a>';
}
function wp_reset_postdata() {}
function get_the_ID() { return $GLOBALS['fixture_id']; }
function wc_get_product( $id ) { return new MediaLibraryFixtureProduct( $id ); }
function get_post_thumbnail_id( $id ) { return $id; }
function get_post_meta( $id, $key, $single ) { return $id === 1 ? '2,3' : ''; }
function get_post_mime_type( $id ) { return 'image/jpeg'; }
function get_attached_file( $id, $unfiltered ) { return $GLOBALS['available'] ? $GLOBALS['source'] : false; }
function wp_get_upload_dir() { return array( 'basedir' => dirname( $GLOBALS['source'] ) ); }
function wp_get_attachment_image( $id, $size, $icon, $attrs ) {
	return '<img src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22%3E%3Crect x=%2265%22 y=%2220%22 width=%2270%22 height=%22160%22 rx=%2210%22 fill=%22%233b1d4a%22/%3E%3C/svg%3E" width="200" height="200" alt="' . esc_attr( $attrs['alt'] ) . '">';
}
function is_account_page() { return $GLOBALS['account_page'] ?? true; }
function is_wc_endpoint_url( $endpoint ) { return $GLOBALS['endpoint'] ?? true; }
function plugin_dir_url( $file ) { return '/wp-content/plugins/ultralove-media-library/'; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function wp_register_style( $handle, $src, ...$args ) { $GLOBALS['registered_styles'][$handle] = $src; }
function wp_enqueue_style( $handle ) {}
function wp_add_inline_style( $handle, $css ) { $GLOBALS['inline_styles'][$handle] = $css; }
function wp_enqueue_script( $handle, $src, ...$args ) { $GLOBALS['scripts'][$handle] = $src; }
class MediaLibraryFixtureProduct {
	public function __construct( private int $id ) {}
	public function get_sku() { return 'SKU-' . $this->id; }
	public function get_name() { return $this->get_sku() . '<br>Product ' . $this->id . ( $this->id === 1 ? ' with a longer descriptive name' : '' ); }
}
class WC_Data_Store {
	public static function load( $name ) { return new self(); }
	public function search_products( ...$args ) { return array( 1, 4, 5 ); }
}
class WP_Query {
	public int $max_num_pages = 3;
	private int $cursor = 0;
	private array $ids;
	public function __construct( $args ) { $this->ids = $GLOBALS['empty'] ? array() : array( 1, 4, 5 ); }
	public function have_posts() { return $this->cursor < count( $this->ids ); }
	public function the_post() { $GLOBALS['fixture_id'] = $this->ids[$this->cursor++]; }
}
require __DIR__ . '/../../wp-content/plugins/ultralove-media-library/ultralove-media-library.php';
$source = tempnam( sys_get_temp_dir(), 'ul-ml-test-' );
if ( false === $source ) { throw new RuntimeException( 'Cannot create source fixture.' ); }
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
};
$render = static function (): string {
	ob_start();
	UltraLove_Media_Library::render();
	return ob_get_clean();
};
try {
	$_GET = array( 'ml_page' => 2 );
	$html = $render();
	$assert( substr_count( $html, 'class="ul-ml__card"' ) === 3, 'All available products render.' );
	$assert( str_contains( $html, '3 products on this page' ), 'Visible product count matches rendered cards.' );
	$assert( str_contains( $html, 'Page 2 of 3' ), 'Current page context is visible.' );
	$assert( str_contains( $html, 'for="ul-ml-search"' ) && str_contains( $html, 'id="ul-ml-search"' ), 'Search has an associated visible label.' );
	$assert( str_contains( $html, 'for="ul-ml-category"' ) && str_contains( $html, 'id="ul-ml-category"' ), 'Category has an associated visible label.' );
	$assert( substr_count( $html, 'Product pages,' ) === 2 && str_contains( $html, '>Next<' ), 'Pagination is labeled above and below results.' );
	$assert( substr_count( $html, 'action=ultralove_ml_download' ) === 5 && substr_count( $html, 'action=ultralove_ml_zip_product' ) === 3, 'Original and product ZIP routes are preserved.' );
	$assert( str_contains( $html, 'name="ul_ml_nonce"' ) && str_contains( $html, 'name="product_ids[]"' ), 'Bulk selection form preserves nonce and product IDs.' );
	$assert( str_contains( $html, 'Download all images (ZIP)' ) && str_contains( $html, 'Download original image 1 for' ), 'Downloads have visible actions and contextual accessible labels.' );
	$assert( str_contains( $html, 'reset when you change pages or filters' ), 'Page-local selection is explained.' );
	$_GET['ml_search'] = '"><script>alert(1)</script>';
	$html = $render();
	$assert( ! str_contains( $html, '<script>' ) && str_contains( $html, 'Reset filters' ), 'Active filters are escaped and resettable.' );
	$empty = true;
	$assert( str_contains( $render(), 'No products found' ) && str_contains( $render(), 'Browse all products' ), 'No-match state provides recovery.' );
	$empty = false;
	$available = false;
	$html = $render();
	$assert( str_contains( $html, 'No downloadable images on this page' ) && ! str_contains( $html, 'ul-ml__zipform' ), 'Unavailable originals do not produce an empty selection form.' );
	$assert( str_contains( $html, 'Product pages, top' ), 'Unavailable-image pages still offer navigation.' );
	$access = false;
	$assert( ! str_contains( $render(), 'ul-ml__card' ) && str_contains( $render(), 'not available for this account' ), 'Access denial is preserved.' );
	UltraLove_Media_Library::enqueue();
	$assert( $registered_styles['ultralove-media-library'] === false, 'Endpoint CSS has no external stylesheet URL.' );
	$assert( $inline_styles['ultralove-media-library'] === file_get_contents( __DIR__ . '/../../wp-content/plugins/ultralove-media-library/assets/media-library.css' ), 'Complete library stylesheet is inlined.' );
	$registered_styles = $inline_styles = $scripts = array();
	$endpoint = false;
	UltraLove_Media_Library::enqueue();
	$assert( ! $registered_styles && ! $inline_styles && ! $scripts, 'No library assets load on other endpoints.' );
	if ( in_array( '--html', $argv, true ) ) {
		$access = $available = true;
		$_GET = array( 'ml_page' => 2 );
		echo $render();
	} else {
		echo "PASS: $checks media library presentation assertions.\n";
	}
} finally {
	unlink( $source );
}
