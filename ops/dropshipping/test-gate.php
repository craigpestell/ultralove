<?php
/**
 * Standalone eligibility tests: php ops/dropshipping/test-gate.php.
 * The WordPress API doubles below never connect to a site or database.
 */

if ( defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	throw new RuntimeException( 'Run this standalone test with PHP CLI, not through WordPress.' );
}

define( 'ABSPATH', __DIR__ . '/' );

$environment = 'development';
$option      = null;
$user_id     = 1;
$approval    = 'yes';
$checks      = 0;

function add_action( ...$args ) {}
function wp_get_environment_type() {
	return $GLOBALS['environment'];
}
function get_option( $key, $default = false ) {
	return $GLOBALS['option'] ?? $default;
}
function get_current_user_id() {
	return $GLOBALS['user_id'];
}
function get_user_meta( $id, $key, $single ) {
	return $GLOBALS['approval'];
}
function WC() {
	return $GLOBALS['woocommerce'];
}
function checked( $value, $expected = true, $echo = true ) {
	$result = (string) $value === (string) $expected ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}
function esc_html__( $text, $domain ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html_e( $text, $domain ) {
	echo esc_html__( $text, $domain );
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

class WC_Order_Item_Shipping {
	public $order;
	public function get_order() {
		return $this->order;
	}
}

require __DIR__ . '/../../wp-content/plugins/ultralove-dropshipping/ultralove-dropshipping.php';

$assert = function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};

foreach ( array( 'development', 'production', 'staging', 'local' ) as $environment ) {
	$supported = in_array( $environment, array( 'development', 'production' ), true );
	$assert( $supported === UltraLove_Dropshipping::supported_environment(), $environment . ' support policy.' );
	foreach ( array( null, 'no', 'yes', '1' ) as $option ) {
		$expected = $supported && 'yes' === $option;
		$assert( $expected === UltraLove_Dropshipping::enabled(), $environment . ' explicit enablement policy.' );
		$assert( $expected === UltraLove_Dropshipping::approved(), $environment . ' approved account policy.' );
	}
}

$environment = 'production';
$option      = 'yes';
$approval    = 'no';
$assert( ! UltraLove_Dropshipping::approved(), 'Enabled production still requires account approval.' );
$approval = 'yes';
$user_id  = 0;
$assert( ! UltraLove_Dropshipping::approved(), 'Guests cannot use dropshipping.' );

$woocommerce = (object) array(
	'cart' => new class {
		public $needs_shipping = true;
		public function needs_shipping_address() {
			return $this->needs_shipping;
		}
	},
	'session' => new class {
		public $selected = false;
		public function get( $key, $default = false ) {
			return $this->selected;
		}
	},
);
$user_id = 1;
foreach ( array( false, true ) as $selected ) {
	$woocommerce->session->selected = $selected;
	ob_start();
	UltraLove_Dropshipping::checkout_field();
	$field = ob_get_clean();
	$assert( false !== strpos( $field, 'woocommerce-form__label-for-checkbox checkbox' ), 'Shared checkout checkbox label classes.' );
	$assert( 1 === preg_match( '/<input\b[^>]*class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"[^>]*>\s*<span>/', $field ), 'Shared checkbox input classes and adjacent label span.' );
	$assert( false !== strpos( $field, 'id="ultralove_dropship_field"' ) && false !== strpos( $field, 'for="ultralove_dropship"' ), 'Preserved wrapper ID and accessible label.' );
	$assert( false !== strpos( $field, 'name="ultralove_dropship" id="ultralove_dropship" value="1"' ), 'Preserved submitted field name, ID and value.' );
	$assert( $selected === ( false !== strpos( $field, 'checked=' ) ), 'Session-selected checkbox state.' );
	$assert( false === strpos( $field, 'class="required"' ) && false === strpos( $field, 'validate-required' ) && ! preg_match( '/<input\b[^>]*\srequired(?:\s|=|\/?>)/', $field ), 'Dropshipping remains optional.' );
	$assert( false !== strpos( $field, 'Dropship this order directly to my customer' ) && false !== strpos( $field, 'id="ultralove-dropship-help"' ), 'Preserved label and help text.' );
}
foreach ( array( 'disabled', 'unapproved', 'guest', 'no-shipping' ) as $case ) {
	$option = 'disabled' === $case ? 'no' : 'yes';
	$approval = 'unapproved' === $case ? 'no' : 'yes';
	$user_id = 'guest' === $case ? 0 : 1;
	$woocommerce->cart->needs_shipping = 'no-shipping' !== $case;
	ob_start();
	UltraLove_Dropshipping::checkout_field();
	$assert( '' === ob_get_clean(), $case . ' checkout must not render dropshipping.' );
}

$shipping = new WC_Order_Item_Shipping();
$shipping->order = new class {
	public $marked = 'yes';
	public function get_meta( $key ) {
		return $this->marked;
	}
};
$meta = (object) array( 'key' => 'Items', 'value' => 'First product &times; 2, Product, with a comma &times; 1, Green &amp; Pink &times; 3' );
$original = '<p>Original summary</p>';
$rendered = UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping );
$assert( 3 === substr_count( $rendered, '<li ' ), 'Every shipping product has its own list entry.' );
$assert( false !== strpos( $rendered, 'Product, with a comma' ), 'Product-name commas must not split entries.' );
$assert( false !== strpos( $rendered, 'Green &amp; Pink' ) && false === strpos( $rendered, '&amp;amp;' ), 'Product entities remain correctly escaped.' );
$assert( false !== strpos( $rendered, '&times; 2</strong>' ) && false !== strpos( $rendered, '&times; 3</strong>' ), 'Quantities remain visible and attached to their products.' );
$assert( false !== strpos( $rendered, 'display:list-item' ) && false !== strpos( $rendered, 'overflow-wrap:anywhere' ), 'Long names wrap within distinct list rows.' );
$assert( 'First product &times; 2, Product, with a comma &times; 1, Green &amp; Pink &times; 3' === $meta->value, 'Saved shipping metadata remains unchanged.' );
$meta->value = '<script>alert("x")</script> &times; 1';
$rendered = UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping );
$assert( false === strpos( $rendered, '<script>' ) && false !== strpos( $rendered, '&lt;script&gt;' ), 'Product names cannot inject markup.' );
$meta->value = html_entity_decode( 'Single product &times; 1.5', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
$assert( 1 === substr_count( UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping ), '<li ' ), 'Single items and fractional quantities are supported.' );
foreach ( array( '', 'Legacy unstructured summary', 'Product &times; 1, incomplete tail' ) as $value ) {
	$meta->value = $value;
	$assert( $original === UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping ), 'Nonstandard summaries retain WooCommerce rendering.' );
}
$meta->value = 'Product &times; 1';
$shipping->order->marked = 'no';
$assert( $original === UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping ), 'Ordinary orders are unchanged.' );
$shipping->order->marked = 'yes';
$meta->key = 'Other field';
$assert( $original === UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping ), 'Other shipping metadata is unchanged.' );
$meta->key = 'Items';
$assert( $original === UltraLove_Dropshipping::shipping_items( $original, $meta, new stdClass() ), 'Product line-item metadata is unchanged.' );
$shipping->order = false;
$assert( $original === UltraLove_Dropshipping::shipping_items( $original, $meta, $shipping ), 'Detached shipping items are unchanged.' );

echo $checks . " environment, eligibility, checkout and shipping summary rendering assertions passed.\n";
