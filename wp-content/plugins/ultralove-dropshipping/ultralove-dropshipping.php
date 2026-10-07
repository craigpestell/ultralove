<?php
/**
 * Plugin Name: UltraLove Dropshipping
 * Description: Approved retailers shipping directly to their customers.
 * Version: 1.1.1
 * Author: UltraLove
 * Requires Plugins: woocommerce
 * Text Domain: ultralove-dropshipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UltraLove_Dropshipping {
	const OPTION   = 'ultralove_dropshipping_enabled';
	const APPROVAL = '_ultralove_dropshipping_approved';
	const FIELD    = 'ultralove_dropship';
	const MARKER   = '_ultralove_dropship';
	const EMAIL    = '_ultralove_dropship_retailer_email';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_ultralove_dropshipping_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'woocommerce_checkout_before_customer_details', array( __CLASS__, 'checkout_field' ) );
		add_action( 'woocommerce_before_checkout_shipping_form', array( __CLASS__, 'delivery_heading' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_review' ) );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_checkout_update_customer_data', array( __CLASS__, 'update_customer' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'create_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'reset_session' ), 100 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_instructions' ) );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email_instructions' ), 10, 4 );
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'order_instructions' ) );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_filters' ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', __( 'Dropshipping', 'ultralove-dropshipping' ), __( 'Dropshipping', 'ultralove-dropshipping' ), 'manage_options', 'ultralove-dropshipping', array( __CLASS__, 'admin_page' ) );
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! self::supported_environment() ) {
			wp_die( esc_html__( 'You cannot configure dropshipping in this environment.', 'ultralove-dropshipping' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ultralove_dropshipping_settings' );
		update_option( self::OPTION, isset( $_POST['ultralove_dropshipping_enabled'] ) && 'yes' === $_POST['ultralove_dropshipping_enabled'] ? 'yes' : 'no' );
		wp_safe_redirect( admin_url( 'admin.php?page=ultralove-dropshipping&updated=1' ) );
		exit;
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage dropshipping.', 'ultralove-dropshipping' ), '', array( 'response' => 403 ) );
		}
		$supported = self::supported_environment();
		$retailers = get_users( array( 'meta_key' => self::APPROVAL, 'meta_value' => 'yes', 'number' => 100, 'orderby' => 'display_name' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dropshipping', 'ultralove-dropshipping' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dropshipping settings saved.', 'ultralove-dropshipping' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'UltraLove ships an approved retailer order directly to one customer. Manual checkout; no CSV order import or Shopify integration.', 'ultralove-dropshipping' ); ?></p>
			<?php if ( $supported ) : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="ultralove_dropshipping_settings">
					<?php wp_nonce_field( 'ultralove_dropshipping_settings' ); ?>
					<p><label><input type="checkbox" name="ultralove_dropshipping_enabled" value="yes" <?php checked( self::enabled() ); ?>>
						<?php esc_html_e( 'Enable dropshipping', 'ultralove-dropshipping' ); ?>
					</label></p>
					<p><?php esc_html_e( 'Turning this off blocks new dropship orders without deleting existing order markers or account approvals.', 'ultralove-dropshipping' ); ?></p>
					<?php submit_button(); ?>
				</form>
			<?php else : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Dropshipping is unavailable in this environment.', 'ultralove-dropshipping' ); ?></p></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Retailer approval', 'ultralove-dropshipping' ); ?></h2>
			<p><?php esc_html_e( 'Enable dropshipping, then edit a retailer under Users and check Dropshipping approval.', 'ultralove-dropshipping' ); ?>
				<a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Manage users', 'ultralove-dropshipping' ); ?></a>
			</p>
			<h3><?php esc_html_e( 'Approved accounts (up to 100 shown)', 'ultralove-dropshipping' ); ?></h3>
			<?php if ( $retailers ) : ?>
				<ul>
					<?php foreach ( $retailers as $retailer ) : ?>
						<li><a href="<?php echo esc_url( get_edit_user_link( $retailer->ID ) ); ?>"><?php echo esc_html( $retailer->display_name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No accounts have been approved.', 'ultralove-dropshipping' ); ?></p>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Order handling', 'ultralove-dropshipping' ); ?></h2>
			<p><?php esc_html_e( 'Ship directly to the customer delivery address.', 'ultralove-dropshipping' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>"><?php esc_html_e( 'Open WooCommerce orders', 'ultralove-dropshipping' ); ?></a></p>
		</div>
		<?php
	}

	public static function supported_environment() {
		return in_array( wp_get_environment_type(), array( 'development', 'production' ), true );
	}

	public static function enabled() {
		return self::supported_environment() && 'yes' === get_option( self::OPTION, 'no' );
	}

	public static function approved() {
		return self::enabled() && get_current_user_id() > 0
			&& 'yes' === get_user_meta( get_current_user_id(), self::APPROVAL, true );
	}

	public static function retailer_email() {
		$customer = new WC_Customer( get_current_user_id() );
		$email    = $customer->get_billing_email();
		return $email ? $email : $customer->get_email();
	}

	public static function profile( $user ) {
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		wp_nonce_field( 'ultralove_dropshipping_approval_' . $user->ID, 'ultralove_dropshipping_nonce' );
		?>
		<h2><?php esc_html_e( 'Dropshipping', 'ultralove-dropshipping' ); ?></h2>
		<p><label><input type="checkbox" name="ultralove_dropshipping_approved" value="yes" <?php checked( 'yes', get_user_meta( $user->ID, self::APPROVAL, true ) ); ?>>
			<?php esc_html_e( 'Approve this account to place dropshipping orders.', 'ultralove-dropshipping' ); ?>
		</label></p>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( ! isset( $_POST['ultralove_dropshipping_nonce'] ) ) {
			return;
		}
		if ( ! self::enabled() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id )
			|| ! is_string( $_POST['ultralove_dropshipping_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ultralove_dropshipping_nonce'] ) ), 'ultralove_dropshipping_approval_' . $user_id ) ) {
			wp_die( esc_html__( 'You cannot change this dropshipping approval.', 'ultralove-dropshipping' ), '', array( 'response' => 403 ) );
		}
		update_user_meta( $user_id, self::APPROVAL, isset( $_POST['ultralove_dropshipping_approved'] ) && 'yes' === $_POST['ultralove_dropshipping_approved'] ? 'yes' : 'no' );
	}

	public static function checkout_field() {
		if ( ! self::approved() || ! WC()->cart->needs_shipping_address() ) {
			return;
		}
		woocommerce_form_field(
			self::FIELD,
			array( 'type' => 'checkbox', 'label' => __( 'Dropship this order directly to my customer', 'ultralove-dropshipping' ), 'class' => array( 'form-row-wide' ) ),
			WC()->session->get( self::FIELD, false )
		);
		echo '<p id="ultralove-dropship-help">' . esc_html__( 'Keep your retailer billing details. Enter one customer delivery address below. Discreet packing; no wholesale invoice in the parcel. Order emails go to your retailer billing email.', 'ultralove-dropshipping' ) . '</p>';
	}

	public static function enqueue() {
		if ( ! self::approved() || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) {
			return;
		}
		wp_enqueue_style( 'ultralove-dropshipping', plugins_url( 'checkout.css', __FILE__ ), array(), '1.1.1' );
		wp_enqueue_script( 'ultralove-dropshipping', plugins_url( 'checkout.js', __FILE__ ), array( 'jquery', 'wc-checkout' ), '1.1.1', true );
		$customer = new WC_Customer( get_current_user_id() );
		wp_localize_script( 'ultralove-dropshipping', 'ultraloveDropshipping', array( 'retailerEmail' => self::retailer_email(), 'savedShipping' => $customer->get_shipping() ) );
	}

	public static function delivery_heading() {
		if ( self::approved() ) {
			echo '<h3 id="ultralove-dropship-delivery-title" hidden>' . esc_html__( 'Customer delivery details', 'ultralove-dropshipping' ) . '</h3>';
		}
	}

	public static function update_review( $posted ) {
		parse_str( $posted, $data );
		WC()->session->set( self::FIELD, self::approved() && isset( $data[ self::FIELD ] ) && '1' === $data[ self::FIELD ] );
	}

	public static function posted_data( $data ) {
		$value                  = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';
		$data[ self::FIELD ]     = '1' === $value;
		$data['ul_dropship_bad'] = ! in_array( $value, array( '', '0', '1' ), true );
		return $data;
	}

	public static function validate( $data, $errors ) {
		if ( ! empty( $data['ul_dropship_bad'] ) ) {
			$errors->add( 'ul_dropship_invalid', __( 'Invalid dropshipping selection. Please reload checkout.', 'ultralove-dropshipping' ) );
		}
		if ( empty( $data[ self::FIELD ] ) ) {
			return;
		}
		if ( ! self::approved() ) {
			$errors->add( 'ul_dropship_unapproved', __( 'Dropshipping is available only to approved retailer accounts when the feature is enabled.', 'ultralove-dropshipping' ) );
		}
		if ( ! WC()->cart->needs_shipping_address() || empty( $data['ship_to_different_address'] ) ) {
			$errors->add( 'ul_dropship_address', __( 'For dropshipping, select Ship to a different address and enter your customer delivery address.', 'ultralove-dropshipping' ) );
		}
		if ( ! is_email( self::retailer_email() ) || 0 !== strcasecmp( $data['billing_email'] ?? '', self::retailer_email() ) ) {
			$errors->add( 'ul_dropship_email', __( 'Use your saved retailer billing email for dropshipping. Update your account billing email first if needed.', 'ultralove-dropshipping' ) );
		}
	}

	public static function update_customer( $update ) {
		// WooCommerce's session customer may change, but a recipient must never become a saved account address.
		return isset( $_POST[ self::FIELD ] ) && '1' === $_POST[ self::FIELD ] ? false : $update;
	}

	public static function create_order( $order, $data ) {
		if ( empty( $data[ self::FIELD ] ) ) {
			return;
		}
		$errors = new WP_Error();
		self::validate( $data, $errors );
		if ( $errors->has_errors() ) {
			throw new Exception( implode( ' ', $errors->get_error_messages() ) );
		}
		$order->update_meta_data( self::MARKER, 'yes' );
		$order->update_meta_data( self::EMAIL, self::retailer_email() );
	}

	public static function reset_session( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( self::MARKER ) ) {
			return;
		}
		WC()->session->set( self::FIELD, false );
		$customer = new WC_Customer( get_current_user_id() );
		foreach ( $customer->get_shipping() as $key => $value ) {
			$setter = 'set_shipping_' . $key;
			if ( is_callable( array( WC()->customer, $setter ) ) ) {
				WC()->customer->$setter( $value );
			}
		}
		WC()->customer->save();
	}

	public static function instructions() {
		return __( 'DROPSHIP: Ship directly to the customer delivery address using discreet, unbranded packing. Do not put a wholesale invoice in the parcel. Billing and order emails belong to the retailer.', 'ultralove-dropshipping' );
	}

	public static function order_instructions( $order ) {
		if ( 'yes' === $order->get_meta( self::MARKER ) ) {
			echo '<p><strong>' . esc_html( self::instructions() ) . '</strong></p>';
		}
	}

	public static function admin_instructions( $order ) {
		self::order_instructions( $order );
	}

	public static function email_instructions( $order, $sent_to_admin, $plain_text, $email ) {
		if ( 'yes' === $order->get_meta( self::MARKER ) ) {
			if ( $plain_text ) {
				echo "\n" . self::instructions() . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				self::order_instructions( $order );
			}
		}
	}

	public static function email_filters( $emails ) {
		foreach ( $emails as $email ) {
			if ( $email->is_customer_email() ) {
				add_filter( 'woocommerce_email_recipient_' . $email->id, array( __CLASS__, 'email_recipient' ), 100, 2 );
			}
		}
		return $emails;
	}

	public static function email_recipient( $recipient, $order ) {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( self::MARKER ) ) {
			return $recipient;
		}
		$email = $order->get_meta( self::EMAIL );
		if ( ! is_email( $email ) ) {
			wc_get_logger()->error( 'Dropship order has no valid retailer email; customer notification suppressed.', array( 'source' => 'ultralove-dropshipping', 'order_id' => $order->get_id() ) );
			return '';
		}
		return $email;
	}
}

add_action( 'plugins_loaded', array( 'UltraLove_Dropshipping', 'init' ) );
