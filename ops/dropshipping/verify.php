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
	update_option( UltraLove_Dropshipping::OPTION, 'yes' );
	wp_set_current_user( $user_ids['unapproved'] );
	$assert( ! UltraLove_Dropshipping::approved(), 'Ordinary customer must not be approved.' );
	wp_set_current_user( 0 );
	$assert( ! UltraLove_Dropshipping::approved(), 'Guest must not be approved.' );
	wp_set_current_user( $user_ids['approved'] );

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
