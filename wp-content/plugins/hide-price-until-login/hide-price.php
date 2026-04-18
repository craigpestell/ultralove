<?php
/**
 * Plugin Name: Hide Price Until Login
 * Plugin URI: http://cedcommerce.com/
 * Description: Hide price of the products on shop and product detail page until user is not logged in or until password is entered.
 * Author: CedCommerce
 * Author URI: http://cedcommerce.com/
 * Text Domain: hide-price-until-login
 * Version: 1.1.1
 * Requires at least: 4.3
 * Tested up to: 6.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Checking for multisite
 */
if ( function_exists('is_multisite') && is_multisite() ) {
	include_once( ABSPATH . 'wp-admin/includes/plugin.php');
}

/**
 * Checkes if WooCommerce id active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	/******* Creating constants to use them later *******/
	define( 'CED_HPUL_PLUGIN_URL', plugin_dir_url(__FILE__) );
	define( 'CED_HPUL_PREFIX', 'ced_hpul' );
	define( 'CED_HPUL_VERSION', '1.0.7' );
	define( 'CED_HPUL_TXT_DMN', 'hide-price-until-login' );

	/**
	 * Remove recaptcha authentication from recaptch plugin, if installed and activated.
	 */
	remove_filter( 'wp_authenticate_user', 'wr_no_captcha_verify_login_captcha', 10, 2 );
	/**
	 * Enqueues all the scripts and styles
	 * @name ced_hpul_enqueue
	 * @author CedCommerce <plugins@cedcommerce.com>
 	 * @link http://cedcommerce.com/
	 */
	function ced_hpul_enqueue() {
		wp_register_script( CED_HPUL_PREFIX.'hide-price-script', plugin_dir_url(__FILE__) . 'assets/js/ced_hide_price_until_login.min.js', array( 'jquery' ), CED_HPUL_VERSION, true );		

		$thckbox_register = add_query_arg( 
			array(
				'TB_inline' => 'true',
				'width'     => 800,
				'height'    => 450,
				'inlineId'  => 'ced_hpul_guest_registration_form_wrap',
				)
			);
		$thckbox_login = add_query_arg( 
			array(
				'TB_inline' => 'true',
				'width'     => 480,
				'height'    => 270,
				'inlineId'  => 'ced_hpul_login_form_wrap',
				)
			);

		/** Localize the script with new data ******/
		$translation_array = array(
			'ajaxurl' 			=> 	admin_url( 'admin-ajax.php' ),
			'empty_user_msg'	=>	get_option( CED_HPUL_PREFIX.'_empty_user_name_text' ),
			'empty_email'		=>	get_option( CED_HPUL_PREFIX.'_empty_email_text' ),
			'empty_pass_msg'	=>	get_option( CED_HPUL_PREFIX.'_empty_password_text' ),
			'empty_cpass'		=>	get_option( CED_HPUL_PREFIX.'_empty_confirmed_password_text' ),
			'mismatch_pass'		=>	get_option( CED_HPUL_PREFIX.'_mis_matched_password_text' ),
			'mismatch_captcha'	=>	__( "Captcha doesn't match, please try again.", CED_HPUL_TXT_DMN ),
			'reg_sucess_msg'	=>	get_option( CED_HPUL_PREFIX.'_register_success_text' ),
			'login_sucess_msg'	=>	get_option( CED_HPUL_PREFIX.'_login_success_text' ),
			'thckbxRegisterUri'	=>	$thckbox_register,
			'thckbxLoginUri'	=>	$thckbox_login
			);
		
		wp_add_inline_script( CED_HPUL_PREFIX.'hide-price-script', 'const globals = '. json_encode($translation_array ),'before');
		wp_enqueue_script( CED_HPUL_PREFIX.'hide-price-script' );	


		if ( get_option( 'ced_hpul_captcha_option' ) == 'hpul_enable_captcha' and get_option( 'ced_hp_captch_site_key' ) != '' ) {
			wp_register_script( 'hpul_no_captcha', 'https://www.google.com/recaptcha/api.js' );
			wp_enqueue_script( 'hpul_no_captcha' );
		}

		wp_register_style( 'hide_tbl', plugin_dir_url( __FILE__ ) . 'assets/css/hide_tbl.css', array() );

		if ( !is_user_logged_in() ) {
			/********** Load CSS File *****************/
			wp_register_style( 'pop_up', plugin_dir_url(__FILE__).'assets/css/pop_up.css', array() );
			wp_enqueue_style( 'pop_up' );
		}
		
		if ( !is_user_logged_in() && ( is_checkout() || is_cart() ) ) {
			wp_enqueue_script( CED_HPUL_PREFIX.'hide-shiiping-detail' );
		}
		
		if ( get_option('ced_hpul_enable_hide_price') == "Hide_Price_for_roles" && ( is_checkout() || is_cart() ) ) {
			foreach( wp_get_current_user()->roles as $role=>$val_role ) {
				if( !in_array( $val_role, get_option( 'ced_hpr_role' ) ) ) {
					wp_enqueue_script( CED_HPUL_PREFIX.'hide-shiiping-detail' );
					wp_register_style( 'pop_up', plugin_dir_url(__FILE__).'assets/css/pop_up.min.css', array() );
					wp_enqueue_style( 'pop_up' );
					
					wp_register_style('hide_tbl',plugin_dir_url(__FILE__).'assets/css/hide_tbl.min.css', array() );
					wp_enqueue_style( 'hide_tbl' );
				}
			}
		}


	}
	
	add_action( 'wp_enqueue_scripts', 'ced_hpul_enqueue' );

	add_action( 'hpul_render_captcha', 'hpul_render_captcha_field', 10 );	

	function hpul_render_captcha_field() {
		$site_key = get_option( 'ced_hp_captch_site_key' );
		if ( get_option( 'ced_hpul_captcha_option' ) == 'hpul_enable_captcha' ) {?>
		<tr>
			<td colspan="2">
				<div class="g-recaptcha" data-sitekey="<?php echo $site_key;?>"></div>
			</td>
		</tr>
		<?php 
	}
}

	/**
	 * Returns true when the plugin's password-based storefront mode needs PHP sessions.
	 *
	 * @return bool
	 */
	function ced_hpul_requires_password_session() {
		return get_option( 'ced_hpul_enable_hide_price' ) === 'Hide_Price_using_Password_Features';
	}

	/**
	 * Starts a native PHP session only when this plugin actually needs one.
	 *
	 * @param bool $force Force a session for admin-only plugin state.
	 * @return bool
	 */
	function ced_hpul_start_session( $force = false ) {
		if ( session_id() || headers_sent() ) {
			return (bool) session_id();
		}

		if ( ! $force && ! ced_hpul_requires_password_session() ) {
			return false;
		}

		session_cache_limiter( '' );
		session_start();

		return (bool) session_id();
	}

/***********Includes hide-price-class.php file ************/
include_once 'includes/hide-price-until-login-class.php';

/************** Includes main.php ***************/
include_once "includes/main.php";


	/**
	 * This function is used to load language'.
	 * @name ced_hpul_load_text_domain()
	 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
	 */
	

	function ced_hpul_load_text_domain() {
		$domain = "hide-price-until-login";
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );
		load_textdomain( $domain, plugin_dir_path(__FILE__) .'language/'.$domain.'-' . $locale . '.mo' );
		$var 	= load_plugin_textdomain( CED_HPUL_TXT_DMN, false, plugin_basename( dirname(__FILE__) ) . '/language' );
	}
	add_action( 'plugins_loaded', 'ced_hpul_load_text_domain' );
	
	/**
	 * Adds the options subpanel
	 * @name admin_menu_link
	 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
	 */
	function admin_menu_link() {
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'filter_plugin_actions', 10, 2 );
		add_filter( 'plugin_row_meta', CED_HPUL_PREFIX.'_row_meta', 10, 2 );
	}
	
	add_action('admin_menu', 'admin_menu_link');
	
	/**
	 * This function is for adding plugin row meta.
	 * @name ced_pas_row_meta()
	 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
	 */
	function ced_hpul_row_meta( $links, $file ) {
		if ( strpos( $file, 'hide-price.php' ) !== false ) {
			$new_links = array(
				'more'	 =>	'<a href="http://cedcommerce.com/woocommerce-extensions" target="_blank">'.__( 'More plugins by CedCommerce', CED_HPUL_TXT_DMN ).'</a>'
				);

			$links = array_merge( $links, $new_links );
		}
		return $links;
	}

	/**
	 * Adds the Settings link to the plugin activation/deactivation page
	 * @name filter_plugin_actions
	 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
	 */
	function filter_plugin_actions( $links, $file ) {
		$settings_link = '<a href="admin.php?page=wc-settings&tab=hide_price&section=hpul_general">' . __( 'Settings', CED_HPUL_TXT_DMN ) . '</a>';
		array_unshift( $links, $settings_link ); // before other links
		return $links;
	}

	/******* Creating constants to use them later *******/
	define( 'CED_HP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	define( 'CED_HP_PREFIX', 'ced_hp' );
	
	/**
	 * Enqueues all the scripts and styles
	 *
	 * @author CedCommerce
	*/
	function ced_hp_enqueue() {

		wp_register_script( CED_HP_PREFIX . 'hide-script', plugin_dir_url(__FILE__).'assets/js/ced_hide_price.min.js', array('jquery'), CED_HPUL_VERSION, true );

		/** Localize the script with new data ******/
		$translation_array = array(
			'ajaxurl' 			=> 	admin_url('admin-ajax.php'),
			'success_msg'		=> 	get_option('ced_hp_matched_password_text'),
			'empty_pass_msg' 	=> 	get_option('ced_hp_empty_password_text'),
			'hide_cart_price'	=>	get_option('ced_hpul_enable_hide_price')
			);

		wp_add_inline_script( CED_HP_PREFIX . 'hide-script', 'const global = '. json_encode($translation_array ),'before');
		wp_enqueue_script( CED_HP_PREFIX . 'hide-script' );
	}
	
	add_action( 'wp_enqueue_scripts', 'ced_hp_enqueue' );
	
	add_action( 'admin_enqueue_scripts', 'ced_hpulaup_enqueue1' );

	add_action("wp_ajax_ced_hpul_send_mail","ced_hpul_send_mail");

	add_action("after_setup_theme","ced_hpul_close_email");

	function ced_hpul_close_email()
	{
		if(isset($_GET["ced_hpul_close"]) && $_GET["ced_hpul_close"]==true)
		{
			unset($_GET["ced_hpul_close"]);
			if ( ced_hpul_start_session( true ) ) {
				$_SESSION["ced_hpul_hide_email"]=true;
			}
		}
	}

	function ced_hpul_send_mail()
	{
		if(isset($_POST["flag"]) && $_POST["flag"]==true && !empty($_POST["emailid"]))
		{
			$to = "support@cedcommerce.com";
			$subject = "Wordpress Org Know More";
			$message = 'This user of our woocommerce extension "Hide Price Until Login" wants to know more about marketplace extensions.<br>';
			$message .= 'Email of user : '.$_POST["emailid"];
			$headers = array('Content-Type: text/html; charset=UTF-8');
			$flag = wp_mail( $to, $subject, $message);	
			if($flag == 1)
			{
				echo json_encode(array('status'=>true,'msg'=>__('Soon you will receive the more details of this extension on the given mail.',"hide-price-until-login")));
			}
			else
			{
				echo json_encode(array('status'=>false,'msg'=>__('Sorry,an error occured.Please try again.',"hide-price-until-login")));
			}
		}
		else
		{
			echo json_encode(array('status'=>false,'msg'=>__('Sorry,an error occured.Please try again.',"hide-price-until-login")));
		}
		wp_die();	
	}

	function ced_hpulaup_enqueue1() {
		if( isset( $_GET[ 'tab' ] ) ) {
			if( $_GET['tab'] == "hide_price" ) {
				wp_enqueue_script( CED_HP_PREFIX.'tog-script', plugin_dir_url(__FILE__).'assets/js/hide_price_tog.min.js', array('jquery', 'jquery-ui-accordion'), CED_HPUL_VERSION, true );
				wp_register_style("ced-hpul-custom-style-1",plugin_dir_url( __FILE__ ) .'assets/css/ced_hpul_custom.css');
				wp_enqueue_style( 'ced-boot-css', 'https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css', array(), '2.0.0', 'all' );
				wp_enqueue_style("ced-hpul-custom-style-1");
				// wp_enqueue_script( CED_HP_PREFIX.'custom-script', plugin_dir_url(__FILE__).'assets/js/ced_hpul_custom.js', array('jquery'), CED_HPUL_VERSION, true );
				wp_add_inline_script(CED_HP_PREFIX.'custom-script',"ajax_url",admin_url('admin-ajax.php'));
			}
		}
	} 
} else {	
	/**
	 * To show error notice if woocommerce is not activated.
	 * @name ced_hide_price_until_login_plugin_error_notice
	 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
	 */
	function ced_hide_price_until_login_plugin_error_notice() {
		?>
		<div class="error notice is-dismissible">
			<p><?php _e( 'WooCommerce is not activated. Please install WooCommerce first, to use the hide plugin !!!', CED_HPUL_TXT_DMN ); ?></p>
		</div>
		<?php
	}

	add_action( 'admin_init', 'ced_hide_until_login_price_plugin_deactivate' );
	/**
	 * Deactivating plugins
	 * @name ced_hide_until_login_price_plugin_deactivate
	 * @author CedCommerce <plugins@cedcommerce.com>
	 * @link http://cedcommerce.com/
	 */
	function ced_hide_until_login_price_plugin_deactivate() {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		add_action( 'admin_notices', 'ced_hide_price_until_login_plugin_error_notice' );
	}
}
?>