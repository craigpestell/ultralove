<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

ced_hpul_start_session();

// Checks if setting is enable
/**
 * Removes and adds action at shop and single product page
 * @name ced_hpul_remove_default_callbacks
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function ced_hpul_remove_default_callbacks() {
	
	if( get_option( 'ced_hpul_enable_hide_price' ) == "Hide_Price_Until_Login_Features" ) {

		if ( ! is_user_logged_in() ) {
	// print_r(get_option( 'ced_hpul_enable_hide_price' ));
	// die();
			remove_action( 'woocommerce_view_order', 'woocommerce_order_details_table', 10 );
			remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
			add_filter( 'woocommerce_cart_totals_taxes_total_html', 'remove_tax_html', 10, 1 );
			add_filter( 'woocommerce_cart_totals_order_total_html', 'remove_cart_total', 10, 1 );
			add_filter( 'woocommerce_cart_totals_coupon_html', 'remove_coupon_html', 10, 2 );
			add_filter( 'woocommerce_cart_totals_fee_html', 'remove_fee_html', 10, 2 );
			add_filter( 'woocommerce_cart_shipping_method_full_label', 'remove_shipping_method', 10, 2 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
			add_filter( 'woocommerce_cart_item_price', 'remove_price_from_cart_table', 10, 2 );
			add_filter( 'woocommerce_cart_item_subtotal', 'remove_total_from_cart_table', 10, 2 );
			add_filter( 'woocommerce_cart_item_price', 'remove_total_from_mini_cart', 10, 1 );
			add_filter( 'woocommerce_cart_subtotal', 'remove_cart_subtotal', 10, 3 );
			add_filter( 'woocommerce_get_formatted_order_total', 'remove_myaccount_total', 10, 2 );
			add_filter( 'woocommerce_get_order_item_totals', 'remove_thankyoupage_total', 10, 1 );
			remove_action( 'woocommerce_thankyou_order_received_text', 'woocommerce_order_details_table', 10 );
			remove_action( 'woocommerce_checkout_order_review', 'woocommerce_order_review', 10 );
			add_filter( 'woocommerce_cart_contents_total', 'remove_price_from_cart', 10, 1 );
			add_filter( 'woocommerce_get_variation_price_html', 'remove_variation_price', 10, 2 );
			add_filter( 'woocommerce_get_price_html', 'remove_woocommerce_price_html', 10, 2 );
			if ( get_option( 'ced_hp_order_email_price_guest' ) == 'yes' ) {
				add_filter( 'woocommerce_order_formatted_line_subtotal', 'hpul_order_formatted_line_subtotal', 10 );
			}

			if ( get_option( 'ced_hp_order_email_price_logged_in' ) == 'yes' ) {
				add_filter( 'woocommerce_order_formatted_line_subtotal', 'hpul_order_formatted_line_subtotal', 10 );
			}
		}
	} elseif ( get_option( 'ced_hpul_enable_hide_price' ) == "Hide_Price_for_roles" ) {
		if ( ! is_user_logged_in() ) {
			remove_action( 'woocommerce_view_order', 'woocommerce_order_details_table', 10 );
			remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
			add_filter( 'woocommerce_cart_totals_taxes_total_html', 'remove_tax_html', 10, 1 );
			add_filter( 'woocommerce_cart_totals_order_total_html', 'remove_cart_total', 10, 1 );
			add_filter( 'woocommerce_cart_totals_coupon_html', 'remove_coupon_html', 10, 2 );
			add_filter( 'woocommerce_cart_totals_fee_html', 'remove_fee_html', 10, 2 );
			add_filter( 'woocommerce_cart_shipping_method_full_label', 'remove_shipping_method', 10, 2 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
			add_filter( 'woocommerce_cart_item_price', 'remove_price_from_cart_table', 10, 2 );
			add_filter( 'woocommerce_cart_item_subtotal', 'remove_total_from_cart_table', 10, 2 );
			add_filter( 'woocommerce_cart_item_price', 'remove_total_from_mini_cart', 10, 1 );
			add_filter( 'woocommerce_cart_subtotal', 'remove_cart_subtotal', 10, 3 );
			add_filter( 'woocommerce_get_formatted_order_total', 'remove_myaccount_total', 10, 2 );
			add_filter( 'woocommerce_get_order_item_totals', 'remove_thankyoupage_total', 10, 1 );
			remove_action( 'woocommerce_thankyou_order_received_text', 'woocommerce_order_details_table', 10 );
			remove_action( 'woocommerce_checkout_order_review', 'woocommerce_order_review', 10 );
			add_filter( 'woocommerce_cart_contents_total', 'remove_price_from_cart', 10, 1 );
			add_filter( 'woocommerce_get_variation_price_html', 'remove_variation_price', 10, 2 );
			add_filter( 'woocommerce_get_price_html', 'remove_woocommerce_price_html', 10, 2 );
			if ( get_option( 'ced_hp_order_email_price_guest' ) == 'yes' ) {
				add_filter( 'woocommerce_order_formatted_line_subtotal', 'hpul_order_formatted_line_subtotal', 10 );
			}
		
			if ( get_option( 'ced_hp_order_email_price_logged_in' ) == 'yes' ) {
				add_filter( 'woocommerce_order_formatted_line_subtotal', 'hpul_order_formatted_line_subtotal', 10 );
			}
		}
		foreach ( wp_get_current_user()->roles as $role => $val_role ) {
			if(!in_array( $val_role, get_option( 'ced_hpr_role' ) )  ) {
				//	$user = wp_get_current_user();
				
				//	echo "<pre>";print_r($user->roles[0]);die('pop');
				//if (is_user_logged_in() )  {
					//die('pop');
					//The user has the "author" role
				
				
				remove_action( 'woocommerce_view_order', 'woocommerce_order_details_table', 10 );
				remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
				add_filter( 'woocommerce_cart_totals_taxes_total_html', 'remove_tax_html', 10, 1 );
				add_filter(  'woocommerce_cart_totals_order_total_html', 'remove_cart_total', 10, 1 );
				add_filter( 'woocommerce_cart_totals_coupon_html', 'remove_coupon_html', 10, 2 );
				add_filter( 'woocommerce_cart_totals_fee_html', 'remove_fee_html', 10, 2 );
				add_filter( 'woocommerce_cart_shipping_method_full_label','remove_shipping_method', 10, 2 );
				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
				remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
				add_filter( 'woocommerce_cart_item_price', 'remove_price_from_cart_table', 10, 2 );
				add_filter( 'woocommerce_cart_item_subtotal', 'remove_total_from_cart_table', 10, 2 );
				add_filter( 'woocommerce_cart_item_price', 'remove_total_from_mini_cart', 10, 1 );
				add_filter( 'woocommerce_cart_subtotal', 'remove_cart_subtotal', 10, 3);
				add_filter( 'woocommerce_get_formatted_order_total', 'remove_myaccount_total', 10, 2 );
				add_filter( 'woocommerce_get_order_item_totals', 'remove_thankyoupage_total', 10, 1 );
				remove_action( 'woocommerce_thankyou_order_received_text', 'woocommerce_order_details_table', 10 );
				remove_action( 'woocommerce_checkout_order_review', 'woocommerce_order_review', 10 );
				add_filter( 'woocommerce_cart_contents_total', 'remove_price_from_cart', 10, 1 );
				add_filter( 'woocommerce_get_variation_price_html', 'remove_variation_price', 10, 2 );
				add_filter( 'woocommerce_get_price_html', 'remove_woocommerce_price_html', 10, 2 );
				break; 
				//}
			} else{ return false; }
		}
	}
}
add_action( 'init', 'ced_hpul_remove_default_callbacks' );

add_action( 'wp', 'ced_hpul_show_login_register_link' );
function ced_hpul_show_login_register_link() {
	if( get_option( 'ced_hpul_enable_hide_price' ) == "Hide_Price_Until_Login_Features" ) {
		if ( ! is_user_logged_in() ) {
			$op_val = get_option( 'ced_hpul_extra_pages_options' );
			if ( !empty( $op_val ) ) {
				/**
				 * If shop page is enabled.
				 */
				if ( in_array( 'hpul_shop_page', get_option( 'ced_hpul_extra_pages_options' )  ) ) {
					/**
					 * If currently visiting the shop page.
					 */
					if ( is_shop() ) {
						add_filter( 'woocommerce_format_content', 'addLoginButton_hpul' );
					}
				}

				/**
				 * If product page is enabled.
				 */
				if ( in_array( 'hpul_product_page', get_option( 'ced_hpul_extra_pages_options' )  ) ) {
					/**
					 * If currently visiting the product page.
					 */
					if ( is_product() ) {
						add_action( 'woocommerce_single_product_summary', 'doHidePriceUntilLogin' );
					}
				}

				/**
				 * If cart page is enabled.
				 */
				if ( in_array( 'hpul_cart_page', get_option( 'ced_hpul_extra_pages_options' )  ) ) {
					/**
					 * If currently visiting the cart page.
					 */
					if ( is_cart() ) {
						add_action( 'woocommerce_before_cart_table', 'show_registration_login_link', 10 );	
					}
				}

				/**
				 * If checkout page is enabled.
				 */
				if ( in_array( 'hpul_checkout_page', get_option( 'ced_hpul_extra_pages_options' )  ) ) {
					/**
					 * If currently visiting the checkout page.
					 */
					if ( is_checkout() ) {
						add_action( 'woocommerce_checkout_before_customer_details', 'add_login_link_on_checkout_page', 10 );
					}
				}
			} else {
				/**
				 * Show login register link at all pages if no pages selected.
				 */
				// add_filter( 'woocommerce_format_content', 'addLoginButton_hpul' );
				add_action( 'woocommerce_single_product_summary', 'doHidePriceUntilLogin' );
				add_action( 'woocommerce_before_cart_table', 'show_registration_login_link', 10 );	
				add_action( 'woocommerce_checkout_before_customer_details', 'add_login_link_on_checkout_page', 10 );
			}
		}
	}
}

/**
 * set default setting to hide price to none.
 * @name update_default_hpul_setting
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
function ced_hpul_default() {
	$op_hd_pr = get_option( 'ced_hpul_enable_hide_price' );
	! empty( $op_hd_pr ) ? '' : update_option( 'ced_hpul_enable_hide_price', 'Hide_Price_for_none' ) ;
}
add_action('init', 'ced_hpul_default');

/**
 * removes total,subtotal,coupon,fees labels
 * @name woocommerce_cart_totals
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
if ( ! function_exists( 'woocommerce_cart_totals' ) ) {
	function woocommerce_cart_totals() {
		include_once "cart-total.php";
	}
}

/**
 * removes cart total
 * @name remove_cart_total
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
function remove_cart_total( $total ) {
	return '';
}

/**
 * removes tax price html from cart
 * @name remove_tax_html
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
function remove_tax_html( $value ) {
	return '';
}

/**
 * removes fee price html from cart
 * @name remove_fee_html
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
function remove_fee_html( $cart_totals_fee_html, $fee ) {
	return '';
}

/**
 * removes coupon html from cart
 * @name remove_coupon_html
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
function remove_coupon_html( $value, $coupon ) {
	return '';
}

/**
 * remove shipping method price from cart
 * @name remove_shipping_method
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com
 */
function remove_shipping_method( $label, $method) {
	return $method->label;
}

	
/**
 * Adding registration/login link text on shop page
 * @name addLoginButton_hpul
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function addLoginButton_hpul( $raw_string ) {
	return hpul_login_register_text_n_link( $raw_string );
}

function hpul_login_register_text_n_link( $raw_string = '' ) {
	$login_first_link_text 	= __( 'Please', CED_HPUL_TXT_DMN );
	$login_second_link_text = __( 'Register/Login', CED_HPUL_TXT_DMN );
	$login_third_link_text 	= __( 'to see the prices of products.', CED_HPUL_TXT_DMN );
	$login_link_html 		= '';
	$custom_form_link 		= '';
	$custom_form_caption 	= __( "Register/Login Form" );
	if( get_option( 'ced_hpul_enable_hide_price' ) == "Hide_Price_Until_Login_Features" ) {	
		if ( ! is_user_logged_in() ) {
			$customForm = get_option( 'ced_hpul_register_form' );
			if ( !empty( $customForm ) ) {
				if ( $customForm == 'hpul_disable_form' ) {
					return $raw_string;
				}

				if ( $customForm == 'hpul_custom_form' ) {
					$op_reg_link = get_option( CED_HPUL_PREFIX.'_register_link' );
					$custom_form_link = !empty( $op_reg_link ) ? $op_reg_link : '';
				}
			}

			$op_reg_fr_login_text =get_option( CED_HPUL_PREFIX . '_register_first_login_text' );
			if ( ! empty( $op_reg_fr_login_text ) ) {
				
				$login_first_link_text 	= get_option( CED_HPUL_PREFIX . '_register_first_login_text' );
			}

			$op_reg_sec_login_text =get_option( CED_HPUL_PREFIX . '_register_second_login_text' );
			if ( ! empty( $op_reg_sec_login_text ) ) {
				$login_second_link_text = get_option( CED_HPUL_PREFIX.'_register_second_login_text' );
			}
			$op_reg_thr_login_text =get_option( CED_HPUL_PREFIX . '_register_third_login_text' );
			if ( ! empty( $op_reg_thr_login_text ) ) {
				$login_third_link_text 	= get_option( CED_HPUL_PREFIX.'_register_third_login_text' );
			}
			
			$login_link_html .= "<span class='ced_hpul_login_link'>$login_first_link_text</span>";
			$login_link_html .= "<a id='ced_hpul_login_link' class='ced_hpul_login_link' data-caption='{$custom_form_caption}' data-form='{$custom_form_link}' href='javascript:void(0);'>";
				$login_link_html .= $login_second_link_text;
			$login_link_html .= "</a>";
			$login_link_html .= "<span class='ced_hpul_login_link'>{$login_third_link_text}</span>";
			echo $login_link_html;
			require_once plugin_dir_path( __FILE__ ) . 'template-registration-login.php';
		}
		return $raw_string;
	}	
	return $raw_string; 
}

/**
 * Adding registration/login link text on cart page
 * @name show_registration_login_link
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function show_registration_login_link() {
	hpul_login_register_text_n_link();
}


/**
 * Includes registraion/login link on single product page
 * @name doHidePriceUntilLogin
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function doHidePriceUntilLogin() {?>
	<div class="ced_hpul_single_summary_wrapper">
		<span><?php echo get_option('ced_hpul_summary_text');?></span>
		<?php 
		hpul_login_register_text_n_link();
		?>
	</div>
<?php 	
}

/**
 * Adding registraion/login link on thank you page 
 * @name add_login_link_on_thank_you_page
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 * 
 */
function add_login_link_on_thank_you_page() {
	hpul_login_register_text_n_link();
}
add_action( 'woocommerce_thankyou','add_login_link_on_thank_you_page', 10 );
	
/**
 * Adds login/registration link on checkout page
 * @name add_login_link_on_checkout_page 
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function add_login_link_on_checkout_page() {
	hpul_login_register_text_n_link();
}
	
/**
 * Removes price from mini cart  on cart page
 * @name remove_price_from_cart
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
*/
function remove_price_from_cart( $cart_total ) {
	return '';
}
	
/**
 * removes total from header cart
 * @name remove_cart_subtotal
 * @author Cedcommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function remove_cart_subtotal( $cart_subtotal, $compound, $instance ) {
	return '';
}

/**
 * removes price for variation item
 * @name remove_variation_price
 * @author Cedcommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function remove_variation_price( $price, $variation ) {
	return '';
}
	
/**
 * removes price for grouped products
 * @name remove_woocommerce_price_html
 * @author Cedcommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function remove_woocommerce_price_html( $price, $instance ) {
	return '';
}
		
/**
 * Removes price from cart table on cart page
 * @name remove_price_from_cart_table
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
*/
function remove_price_from_cart_table( $cart_table_price, $pack ) {	
	return null;
}

/**
 * Removes total from cart table on cart page
 * @name remove_total_from_cart_table
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
*/
function remove_total_from_cart_table( $total, $pack ) {
	return null;
}

/**
 * Remove total from mini cart
 * @name remove_total_from_mini_cart
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function remove_total_from_mini_cart( $price ) {
	return null;
} 

/**
 * Removing mini_cart subtotal from shop page
 * 
 * @name remove_mini_cart_shipping_subtotal
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function remove_mini_cart_shipping_subtotal( $subtotal, $total ) {
	return null;
}

/**
 * Remove total from myaccount page
 * 
 * @name remove_myaccount_total
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function remove_myaccount_total( $total, $pack ) {
	return null;
}

/**
 * Remove total from thankyou page
 * 
 * @name remove_thankyoupage_total
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 * @return array $total_rows
 */
function remove_thankyoupage_total( $total_rows )	{
	unset( $total_rows[ 'cart_subtotal' ] );
	return $total_rows;
}

/**
 * Handles Ajax reuest to register user and login 
 * @name ced_hpul_submit_register_data
 * @author CedCommerce <plugins@cedcommerce.com>
 * @link http://cedcommerce.com/
 */
function ced_hpul_submit_register_data() {
	$uname	 = 	sanitize_text_field( $_POST[ 'uname' ] );
	$uemail	 =	sanitize_text_field( $_POST[ 'uemail' ] );
	$upass	 =	sanitize_text_field( $_POST[ 'upass' ] );

	if ( get_option( 'ced_hpul_captcha_option' ) == 'hpul_enable_captcha' and get_option( 'ced_hp_captch_site_key' ) != '' ) {
		$recaptchaResponse 	= $_POST[ 'reCAPTCHA' ];
		$no_captcha_secret 	= get_option( 'ced_hp_captch_secret_key' );
		$response 			= wp_remote_get( 'https://www.google.com/recaptcha/api/siteverify?secret=' . $no_captcha_secret . '&response=' . $recaptchaResponse );
		$response 			= json_decode( $response[ 'body' ], true );
		if ( true !== $response[ 'success' ] ) {
			wp_send_json_error( __( 'Robot test error: we suggest a new try.', CED_HPUL_TXT_DMN ) );
			wp_die();
		}
	}

	if ( ! is_email( $uemail ) ) {
		wp_send_json_error( __( "Please enter a valid email address.", CED_HPUL_TXT_DMN ) );
		wp_die();
	} else {
		$user_register = wp_create_user( $uname, $upass, $uemail );
		if ( ! is_object( $user_register ) ) {
			$creds = array( 
				'user_login' 	=>  $uname, 
				'user_password' => $upass, 
				'remember' 		=> true 
			);
			$secure_cookie  = is_ssl() ? true : false;

			/**
			 * Signing in after register.
			 */
			$user 			= wp_signon( $creds, $secure_cookie );
			if ( is_wp_error( $user ) ) {
				wp_send_json_error( __( 'Something went worng, please try again later.', CED_HPUL_TXT_DMN ) );
				wp_die();
			}
			wp_send_json_success(  __( "You've successfully registred with us.", CED_HPUL_TXT_DMN ) );
		} else if ( array_key_exists('existing_user_email',$user_register->errors) ) {
			wp_send_json_error( $user_register->errors[ 'existing_user_email' ][0] );
		} else {
			wp_send_json_error( $user_register->errors[ 'existing_user_login' ][0] );
		}
	}
	wp_die();
}
add_action( 'wp_ajax_ced_ced_hp_submit_guest_registration_form', 'ced_hpul_submit_register_data' );
add_action( 'wp_ajax_nopriv_ced_hp_submit_guest_registration_form', 'ced_hpul_submit_register_data' );

/**
 * Handles Ajax reuest login registered user
 * @name ced_hpul_login_user_data
 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
 */
function ced_hpul_login_user_data()	{
	$uname	 = sanitize_text_field( $_POST[ 'uname' ] );
	$upass	 = sanitize_text_field( $_POST[ 'upass' ] );
	$creds = array( 
		'user_login' 	=>  $uname, 
		'user_password' => $upass, 
		'remember' 		=> true 
	);
	$secure_cookie      = is_ssl() ? true : false;
	
	/**
	 * Remove recaptcha authentication from recaptch plugin, if installed and activated.
	 */
	remove_filter( 'wp_authenticate_user', 'wr_no_captcha_verify_login_captcha', 10, 2 );

	if( wp_signon( $creds, $secure_cookie )->data->ID > 0 ) {
		wp_send_json_success( __( 'Successfully registered and login', CED_HPUL_TXT_DMN ) );
	} else {
		wp_send_json_error( __( "Invalid login detail, please try again.", CED_HPUL_TXT_DMN ) );
	}
	wp_die();
}
add_action( 'wp_ajax_ced_hpul_login_user', 'ced_hpul_login_user_data' );
add_action( 'wp_ajax_nopriv_ced_hpul_login_user', 'ced_hpul_login_user_data' );	


/**
 * Removes and adds action at single product page
 *
 * @author CedCommerce
 */
function ced_hp_remove_default_callbacks() {
	if( get_option( 'ced_hpul_enable_hide_price' ) == "Hide_Price_using_Password_Features" ) {
		if ( ! isset( $_SESSION[ 'ced_hp_password_matched' ] ) ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
			remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		} else {
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			add_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
			add_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		}
	}
}
add_action('init', 'ced_hp_remove_default_callbacks');
/**
 * Checks if session is set
*/
if ( ! isset( $_SESSION[ 'ced_hp_password_matched' ] ) ) {

	/**
	 * Adds a Read More button
	 *
	 * @author CedCommerce
	 */
	 function addViewButton() {
		if( get_option( 'ced_hpul_enable_hide_price' ) == "Hide_Price_using_Password_Features" ) {
			global $product;
			echo '<a class="button product_type_booking add_to_cart_button" data-product_sku="'.$product->get_sku().'" data-product_id="'.get_the_ID().'" data-quantity="1" href="'.get_the_permalink().'" rel="nofollow">Read more</a>';
		}
	}
	add_action( 'woocommerce_after_shop_loop_item', 'addViewButton'); 

	/**
	 * removes price from order details page.
	 * @param  array $items 
	 * @return array
	 */
	function hpul_hide_order_email_price( $items ) {
		if ( empty( $items ) or ! is_array( $items ) ) {
			return $items;
		}

		foreach ( $items as $key => $value ) {
			$items[ $key ][ 'item_meta' ][ '_line_subtotal' ][0] = '';
			$items[ $key ][ 'item_meta' ][ '_line_subtotal' ][0] = '';
			$items[ $key ][ 'line_subtotal' ] 	= '';
			$items[ $key ][ 'line_total' ] 		= '';

			foreach ( $value[ 'item_meta_array' ] as $k => $val ) {
				if ( $val->key == '_line_subtotal' or $val->key == '_line_total' ) {
					$items[ $key ][ 'item_meta_array' ][ $k ]->value = '';
				}
			}
		}
		return $items;
	}

	/**
	 * Removes Total: row from order details page.
	 * @param  array $total_rows 
	 * @return array
	 */
	function hpul_get_order_item_totals( $total_rows ) {
		if ( empty( $total_rows ) or ! is_array( $total_rows ) ) {
			return $total_rows;
		}

		if ( array_key_exists( 'order_total', $total_rows ) ) {
			unset( $total_rows[ 'order_total' ] );
		}
		return $total_rows;
	}
	
	/**
	 * Removes price from order mail content.
	 * @param  string $subtotal 
	 * @return void
	 */
	function hpul_order_formatted_line_subtotal( $subtotal ) {
		return '';
	}

	/**
	 * Hides Price
	 *
	 * @author CedCommerce
	*/
	function doHidePrice() {
		if(get_option('ced_hpul_enable_hide_price') == "Hide_Price_using_Password_Features")
		{?>
		<div class="ced_hp_single_summary_wrapper">
			<input type="button" value="<?php echo get_option('ced_hp_order_now_text');?>" class="ced_hp_order_now button alt" id="ced_hp_order_now">
			<div id="ced_hp_guest_password_form" class="guest_password_form">
				<input type="password" id="ced_hp_pass_for_price" placeholder="<?php echo get_option('ced_hp_password_field_placeholder');?>" value="" name="enter_pass" class="ced_hp_enter_pass">
				<input type="button" id="ced_hp_submit" class="button alt" value="<?php echo get_option('ced_hp_submit_text');?>" name="submit_pass">
				<img src="<?php echo CED_HP_PLUGIN_URL.'assets/images/ajax-loader.gif';?>" id="ced_hp_loading_img" style="margin-left: 8px;margin-top: 8px;vertical-align: middle;width: 30px;">
				<span id="ced_hp_success_message" style="color: green;margin-left: 8px;margin-top: 10px;display: inline-block;" ></span>
				<span id="ced_hp_error_message" style="color: red;margin-left: 8px;margin-top: 10px;display: inline-block;"></span>
			</div>
		</div>
	<?php } 
	}
	add_action('woocommerce_single_product_summary', 'doHidePrice');
}
/**
 * Handles Ajax reuest to match the password and show the price
 * 
 * @author CedCommerce
 */
function ced_hp_submit_price_passw() {
	$passwordEntered = $_POST['password'];
	if ( $passwordEntered != get_option('ced_hp_password_for_price') ) {
		echo _e(get_option('ced_hp_wrong_password_text'),'hide-price-until-login');
	} else if ( $passwordEntered == get_option('ced_hp_password_for_price') ) {
		$_SESSION['ced_hp_password_matched'] = get_option('ced_hp_password_for_price');
		echo _e('success','hide-price-until-login');
	}
	wp_die();
}
add_action( 'wp_ajax_ced_hp_submit_price_passw', 'ced_hp_submit_price_passw' );
add_action( 'wp_ajax_nopriv_ced_hp_submit_price_passw', 'ced_hp_submit_price_passw' );
?>