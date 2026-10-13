<?php
/**
 * Run with wp eval-file on dev after deploying and activating the plugin.
 * Uses synthetic users/products/orders and removes only its own fixtures.
 */

if ( ! defined( 'ABSPATH' ) || 'development' !== wp_get_environment_type() || ! class_exists( 'UltraLove_Dropshipping' ) ) {
	throw new RuntimeException( 'Run this verification through WP-CLI on dev with UltraLove Dropshipping active.' );
}

$checks          = 0;
$user_ids        = array();
$order_ids       = array();
$product         = null;
$previous_user   = get_current_user_id();
$previous_option = get_option( UltraLove_Dropshipping::OPTION, null );
$previous_post   = $_POST;
$assert          = function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};

try {
	update_option( UltraLove_Dropshipping::OPTION, 'yes' );
	$suffix = strtolower( wp_generate_password( 12, false ) );
	foreach ( array( 'approved', 'unapproved', 'admin' ) as $kind ) {
		$id = wp_insert_user( array( 'user_login' => 'dropship-test-' . $kind . '-' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $kind . '-' . $suffix . '@example.invalid', 'role' => 'admin' === $kind ? 'administrator' : 'customer' ) );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$user_ids[ $kind ] = $id;
		$customer         = new WC_Customer( $id );
		$customer->set_props( array( 'billing_first_name' => 'Test', 'billing_last_name' => 'Retailer', 'billing_email' => $kind . '-' . $suffix . '@example.invalid', 'billing_country' => 'CA', 'billing_state' => 'BC', 'billing_city' => 'Vancouver', 'billing_postcode' => 'V6B 1A1', 'billing_address_1' => '100 Test Retailer Street', 'shipping_first_name' => 'Saved', 'shipping_last_name' => 'Retailer', 'shipping_country' => 'CA', 'shipping_state' => 'BC', 'shipping_city' => 'Vancouver', 'shipping_postcode' => 'V6B 1A1', 'shipping_address_1' => '100 Saved Retailer Street' ) );
		$customer->save();
	}
	update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, 'yes' );
	wp_set_current_user( $user_ids['admin'] );
	delete_option( UltraLove_Dropshipping::OPTION );
	$assert( ! UltraLove_Dropshipping::enabled(), 'Absent option must default off.' );
	update_option( UltraLove_Dropshipping::OPTION, 'no' );
	ob_start();
	UltraLove_Dropshipping::profile( get_userdata( $user_ids['approved'] ) );
	$assert( '' === ob_get_clean(), 'Disabled dropshipping must hide user-profile controls even from administrators.' );
	ob_start();
	UltraLove_Dropshipping::admin_page();
	$disabled_admin = ob_get_clean();
	$assert( false !== strpos( $disabled_admin, 'ultralove_dropshipping_enabled' ), 'Disabled feature must retain its admin switch.' );
	$assert( ! UltraLove_Dropshipping::approved(), 'Account approval cannot bypass the disabled feature.' );
	$die_handler = function () {
		return function ( $message, $title, $arguments ) {
			throw new RuntimeException( (string) $message, (int) $arguments['response'] );
		};
	};
	add_filter( 'wp_die_handler', $die_handler );
	try {
		foreach ( array( 'yes', 'no' ) as $approval ) {
			update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, $approval );
			$_POST = array(
				'ultralove_dropshipping_nonce' => wp_create_nonce( 'ultralove_dropshipping_approval_' . $user_ids['approved'] ),
			);
			if ( 'no' === $approval ) {
				$_POST['ultralove_dropshipping_approved'] = 'yes';
			}
			$blocked = false;
			try {
				UltraLove_Dropshipping::save_profile( $user_ids['approved'] );
			} catch ( RuntimeException $error ) {
				$assert( 403 === $error->getCode(), 'Disabled approval changes must be rejected with 403.' );
				$blocked = true;
			}
			$assert( $blocked, 'A valid stale nonce cannot grant or revoke access while disabled.' );
			$assert( $approval === get_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, true ), 'Rejected save must preserve the existing approval.' );
		}
	} finally {
		remove_filter( 'wp_die_handler', $die_handler );
	}
	update_option( UltraLove_Dropshipping::OPTION, 'yes' );
	update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, 'yes' );
	ob_start();
	UltraLove_Dropshipping::profile( get_userdata( $user_ids['approved'] ) );
	$assert( false !== strpos( ob_get_clean(), 'name="ultralove_dropshipping_approved"' ), 'Enabling dropshipping restores administrator approval controls.' );
	ob_start();
	UltraLove_Dropshipping::admin_page();
	$admin_content = ob_get_clean();
	$assert( false !== strpos( $admin_content, 'ultralove_dropshipping_enabled' ), 'WordPress admin must expose the pilot switch.' );
	$assert( false !== strpos( $admin_content, 'user-edit.php?user_id=' . $user_ids['approved'] ), 'Admin page must link to approved retailer.' );
	$_POST = array( 'ultralove_dropshipping_nonce' => wp_create_nonce( 'ultralove_dropshipping_approval_' . $user_ids['approved'] ) );
	UltraLove_Dropshipping::save_profile( $user_ids['approved'] );
	$assert( 'no' === get_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, true ), 'Administrator can revoke approval.' );
	$_POST['ultralove_dropshipping_approved'] = 'yes';
	UltraLove_Dropshipping::save_profile( $user_ids['approved'] );
	$assert( 'yes' === get_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, true ), 'Administrator can approve retailer.' );
	$_POST = array();
	wp_set_current_user( $user_ids['approved'] );
	ob_start();
	UltraLove_Dropshipping::profile( get_userdata( $user_ids['approved'] ) );
	$assert( '' === ob_get_clean(), 'Retailer cannot see self-approval controls.' );
	wc_load_cart();
	WC()->customer = new WC_Customer( $user_ids['approved'], true );
	WC()->cart->empty_cart();
	$product = new WC_Product_Simple();
	$product->set_name( 'Synthetic dropshipping verification ' . $suffix );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( '100' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 10 );
	$product->save();
	$assert( (bool) WC()->cart->add_to_cart( $product->get_id(), 1 ), 'Synthetic product must be added to cart.' );
	$assert( UltraLove_Dropshipping::approved(), 'Approved user must be eligible.' );
	update_option( UltraLove_Dropshipping::OPTION, 'no' );
	$assert( ! UltraLove_Dropshipping::approved(), 'Disabled pilot must fail closed.' );
	ob_start();
	UltraLove_Dropshipping::checkout_field();
	$assert( '' === ob_get_clean(), 'Disabled checkout must not render dropshipping fields.' );
	UltraLove_Dropshipping::enqueue();
	$assert( ! wp_script_is( 'ultralove-dropshipping', 'enqueued' ), 'Disabled checkout must not enqueue dropshipping JavaScript.' );
	$assert( ! wp_style_is( 'ultralove-dropshipping', 'enqueued' ), 'Disabled checkout must not enqueue dropshipping styles.' );
	ob_start();
	UltraLove_Dropshipping::delivery_heading();
	$assert( '' === ob_get_clean(), 'Disabled checkout must not render a recipient section heading.' );
	$errors = new WP_Error();
	UltraLove_Dropshipping::validate( array( UltraLove_Dropshipping::FIELD => true, 'ship_to_different_address' => true, 'billing_email' => UltraLove_Dropshipping::retailer_email() ), $errors );
	$assert( in_array( 'ul_dropship_unapproved', $errors->get_error_codes(), true ), 'Disabled checkout must reject forged opt-ins.' );
	update_option( UltraLove_Dropshipping::OPTION, 'yes' );
	wp_set_current_user( $user_ids['unapproved'] );
	$assert( ! UltraLove_Dropshipping::approved(), 'Ordinary customer must not be approved.' );
	ob_start();
	UltraLove_Dropshipping::checkout_field();
	$assert( '' === ob_get_clean(), 'Unapproved checkout must not render the dropshipping checkbox.' );
	ob_start();
	UltraLove_Dropshipping::delivery_heading();
	$assert( '' === ob_get_clean(), 'Unapproved checkout must not render a recipient section heading.' );
	wp_set_current_user( 0 );
	$assert( ! UltraLove_Dropshipping::approved(), 'Guest must not be approved.' );
	wp_set_current_user( $user_ids['approved'] );
	$previous_choice = WC()->session->get( UltraLove_Dropshipping::FIELD, false );
	try {
		foreach ( array( false, true ) as $selected ) {
			WC()->session->set( UltraLove_Dropshipping::FIELD, $selected );
			ob_start();
			UltraLove_Dropshipping::checkout_field();
			$field = ob_get_clean();
			$assert( false !== strpos( $field, 'woocommerce-form__label-for-checkbox checkbox' ), 'Dropshipping must use the shared checkout checkbox label classes.' );
			$assert( 1 === preg_match( '/<input\b[^>]*class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"[^>]*>\s*<span>/', $field ), 'Dropshipping must use the shared checkbox input classes and adjacent text span.' );
			$assert( false !== strpos( $field, 'id="ultralove_dropship_field"' ) && false !== strpos( $field, 'for="ultralove_dropship"' ), 'Dropshipping must retain its wrapper ID and associated label.' );
			$assert( false !== strpos( $field, 'name="ultralove_dropship" id="ultralove_dropship" value="1"' ), 'Dropshipping must retain its submitted field identity and value.' );
			$assert( $selected === ( false !== strpos( $field, 'checked=' ) ), 'Dropshipping must render its saved session selection.' );
			$assert( false === strpos( $field, 'class="required"' ) && false === strpos( $field, 'validate-required' ) && ! preg_match( '/<input\b[^>]*\srequired(?:\s|=|\/?>)/', $field ), 'Dropshipping must remain optional.' );
		}
	} finally {
		WC()->session->set( UltraLove_Dropshipping::FIELD, $previous_choice );
	}
	ob_start();
	UltraLove_Dropshipping::delivery_heading();
	$heading = ob_get_clean();
	$assert( false !== strpos( $heading, 'Customer delivery details' ) && false !== strpos( $heading, ' hidden' ), 'Approved checkout must have a delivery heading hidden until opt-in.' );

	$data = array( UltraLove_Dropshipping::FIELD => true, 'ship_to_different_address' => true, 'billing_email' => UltraLove_Dropshipping::retailer_email() );
	$errors = new WP_Error();
	UltraLove_Dropshipping::validate( $data, $errors );
	$assert( ! $errors->has_errors(), 'Valid dropship selection should pass.' );
	foreach ( array( 'address', 'email', 'approval' ) as $case ) {
		$invalid = $data;
		if ( 'address' === $case ) {
			$invalid['ship_to_different_address'] = false;
		} elseif ( 'email' === $case ) {
			$invalid['billing_email'] = 'parcel-recipient@example.invalid';
		} else {
			update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, 'no' );
		}
		$errors = new WP_Error();
		UltraLove_Dropshipping::validate( $invalid, $errors );
		$assert( $errors->has_errors(), 'Reject invalid ' . $case . '.' );
		update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, 'yes' );
	}
	$_POST = array( UltraLove_Dropshipping::FIELD => array( '1' ) );
	$parsed = UltraLove_Dropshipping::posted_data( array() );
	$assert( $parsed['ul_dropship_bad'], 'Reject array-shaped opt-in.' );
	$_POST = array( UltraLove_Dropshipping::FIELD => '1' );
	$assert( false === apply_filters( 'woocommerce_checkout_update_customer_data', true, WC()->checkout() ), 'Dropship must not save recipient to user profile.' );
	$_POST = array();
	$assert( true === apply_filters( 'woocommerce_checkout_update_customer_data', true, WC()->checkout() ), 'Ordinary checkout must retain profile updates.' );
	UltraLove_Dropshipping::update_review( 'ultralove_dropship=1' );
	$assert( true === WC()->session->get( UltraLove_Dropshipping::FIELD ), 'Review must persist selection.' );
	UltraLove_Dropshipping::update_review( '' );
	$assert( false === WC()->session->get( UltraLove_Dropshipping::FIELD ), 'Review must clear selection.' );
	update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, 'no' );
	UltraLove_Dropshipping::update_review( 'ultralove_dropship=1' );
	$assert( false === WC()->session->get( UltraLove_Dropshipping::FIELD ), 'Revoked approval cannot set session selection.' );
	update_user_meta( $user_ids['approved'], UltraLove_Dropshipping::APPROVAL, 'yes' );

	$order = wc_create_order( array( 'customer_id' => $user_ids['approved'] ) );
	if ( is_wp_error( $order ) ) {
		throw new RuntimeException( $order->get_error_message() );
	}
	$order_ids[] = $order->get_id();
	$order->set_billing_email( $data['billing_email'] );
	UltraLove_Dropshipping::create_order( $order, $data );
	$order->save();
	$assert( 'yes' === wc_get_order( $order->get_id() )->get_meta( UltraLove_Dropshipping::MARKER ), 'Order marker must persist.' );
	foreach ( WC()->mailer()->get_emails() as $email ) {
		if ( $email->is_customer_email() ) {
			$recipient = apply_filters( 'woocommerce_email_recipient_' . $email->id, 'parcel-recipient@example.invalid', $order, $email );
			$assert( $data['billing_email'] === $recipient, 'Customer email ' . $email->id . ' must target retailer.' );
		}
	}
	$assert( 'staff@example.invalid' === apply_filters( 'woocommerce_email_recipient_new_order', 'staff@example.invalid', $order ), 'Staff recipients must be unchanged.' );
	$ordinary = new WC_Order();
	$assert( 'ordinary@example.invalid' === UltraLove_Dropshipping::email_recipient( 'ordinary@example.invalid', $ordinary ), 'Ordinary email recipients must be unchanged.' );
	ob_start();
	UltraLove_Dropshipping::email_instructions( $order, true, false, null );
	$content = ob_get_clean();
	$assert( false !== strpos( $content, 'DROPSHIP' ) && false !== strpos( $content, 'wholesale invoice' ), 'Staff email must include packing instructions.' );
	WC()->customer->set_shipping_address_1( '200 Test Recipient Street' );
	WC()->session->set( UltraLove_Dropshipping::FIELD, true );
	UltraLove_Dropshipping::reset_session( $order->get_id() );
	$assert( false === WC()->session->get( UltraLove_Dropshipping::FIELD ), 'Processed order must clear selection.' );
	$assert( '100 Saved Retailer Street' === WC()->customer->get_shipping_address_1(), 'Session must restore saved retailer shipping address.' );
	$assert( '100 Saved Retailer Street' === ( new WC_Customer( $user_ids['approved'] ) )->get_shipping_address_1(), 'Stored profile must remain intact.' );
	WP_CLI::success( $checks . ' dropshipping integration assertions passed.' );
} finally {
	$_POST = $previous_post;
	foreach ( $order_ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	if ( $product ) {
		$product->delete( true );
	}
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
	WC()->session->set( UltraLove_Dropshipping::FIELD, false );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $user_ids as $id ) {
		wp_delete_user( $id );
	}
	wp_set_current_user( $previous_user );
	if ( null === $previous_option ) {
		delete_option( UltraLove_Dropshipping::OPTION );
	} else {
		update_option( UltraLove_Dropshipping::OPTION, $previous_option );
	}
}
