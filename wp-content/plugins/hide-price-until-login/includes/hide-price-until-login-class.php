<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

		/**
		 * Initiates the class setting and called on <b>woocommerce_get_settings_pages</b> hook,
		 * to create a setting tab in <b>WooCommerce</b> and the class <b>WC_Settings_Page</b>
		 * is extendable on this hook.
		 * 
		 * @version  1.0.0
		 * @category function
		 * @name hide_price_until_login_plugin_setting_class_initiate
		 * @author CedCommerce <plugins@cedcommerce.com>
		 * @link http://cedcommerce.com/
		 * @return mixed
		 */
		function hide_price_until_login_plugin_setting_class_initiate($settings) {	
			return $settings[] = new Hide_Price();
			
		}

		/**
		 * Hide Price Until Login Class
		 *
		 * @class	Hide_Price_Until_Login
		 * @version  1.0.0
		 * @category Class
		 * @author CedCommerce <plugins@cedcommerce.com>
		 * @link http://cedcommerce.com/
		 */

		if( ! class_exists( 'Hide_Price' ) ) {
			/**
	 		* Checks if class does not already exist 
	 		*/
	 		if ( ! class_exists( 'WC_Settings_Page', false ) ){
	 			include_once ABSPATH.'wp-content/plugins/woocommerce/includes/admin/settings/class-wc-settings-page.php';
	 		}
	 		if(file_exists(ABSPATH.'wp-admin/includes/user.php')){
	 			include_once ABSPATH.'wp-admin/includes/user.php';
	 		}

	 		class Hide_Price extends WC_Settings_Page {

			/**
			 * constructor			 
			 * Initializes all the settings
			 * @name construct
			 * @author CedCommerce <plugins@cedcommerce.com>
			 * @link http://cedcommerce.com/
			 * @access public
			 */
			public function __construct() {		

				$this->id    = 'hide_price';
				$this->label = __( 'Hide Price', CED_HPUL_TXT_DMN );
				// add tab
				add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );				
				// show sections
				add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );
				// show settings
				add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
				// save settings
				add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );	

			}		

			/**
			 * Get settings
			 * @name get_sections
			 * @author CedCommerce <plugins@cedcommerce.com> 
			 * @link http://cedcommerce.com/
			 * @return array
			 */
			public function get_sections() {
				
				$sections = array(
					'hpul_general' 	=> __( 'General Settings', CED_HPUL_TXT_DMN ),
					'hpul_captcha' 	=> __( 'Captcha Settings', CED_HPUL_TXT_DMN )
					);
				return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
			}
			
			/**
			 * Output the settings
			 * @name output
			 * @author CedCommerce <plugins@cedcommerce.com> 
			 * @link http://cedcommerce.com/
			 */
			public function output() {	
				global $current_section;

				$settings = $this->get_settings( $current_section );
				?>
				<div class="ced_hpul_main_wrapper">
					<?php
					WC_Admin_Settings::output_fields( $settings );
					?>
				</div>
				<?php
				ced_hpul_start_session( true );
				if(!isset($_SESSION["ced_hpul_hide_email"])):
					$actual_link = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
				$urlvars = parse_url($actual_link);
				$url_params = $urlvars["query"];
				?>
				<div class="ced_hpul_email_image">
					<div class="ced_hpul_email_main_content">
						<div class="ced_hpul_cross_image">
							<a class="button-primary ced_hpul_cross_image" href="?<?php echo $url_params?>&ced_hpul_close=true">x</a>
						</div>
						<a href="https://cedcommerce.com/" target="_blank"><div class="ced-recom">
							<h4>CedCommerce recommendations for you </h4>
						</div></a>

						<p></p>
						<div class=""  id="ced_hpul_loader">	
							<a target="_blank" href="https://chat.whatsapp.com/BcJ2QnysUVmB1S2wmwBSnE"><img id="ced-hpul-loading-image" src="<?php echo plugins_url().'/hide-price-until-login/assets/images/market-place.jpg'?>" ></a>
						</div>
						<div class="ced_hpul_banner">
							<a target="_blank" href="https://chat.whatsapp.com/BcJ2QnysUVmB1S2wmwBSnE"><img src="<?php echo plugins_url().'/hide-price-until-login/assets/images/market-place-2.jpg'?>"></a>
						</div>

						<div class="wramvp-support">
							<ul>
								<li><span class="wramvp-support__left">Contact Us :-</span><a href="mailto:support@cedcommerce.com"> support@cedcommerce.com </a>  </li>
								<li><span class="wramvp-support__right">Get expert's advice :-</span><a href="https://join.skype.com/bovbEZQAR4DC"> Join Us</a></li>
							</ul>
						</div>

					</div>
				</div>
			<?php endif;?>
				<div class="ced_contact_menu_wrap">
					<input type="checkbox" href="#" class="ced_menu_open" name="menu-open" id="menu-open" />
					<label class="ced_menu_button" for="menu-open">
					<img src="<?php echo plugins_url().'/hide-price-until-login/assets/images/icon.png'?>" alt="" title="Click to Chat">
					</label>
					<a href=" https://join.skype.com/UHRP45eJN8qQ " class="ced_menu_content ced_skype" target="_blank"> <i class="fa fa-skype" aria-hidden="true"></i> </a>
					<a href=" https://chat.whatsapp.com/BcJ2QnysUVmB1S2wmwBSnE " class="ced_menu_content ced_whatsapp" target="_blank"> <i class="fa fa-whatsapp" aria-hidden="true"></i> </a>
				</div>

			<?php
		}

			/**
			 * Save settings
			 * @name save
			 * @author CedCommerce <plugins@cedcommerce.com> 
			 * @link http://cedcommerce.com/
			 */
			public function save() {	
				echo '<h1>Hello</h1>';

				global $current_section;	
				$settings = $this->get_settings( $current_section );
				// print_r($settings); die();
				
				WC_Admin_Settings::save_fields( $settings );
			}
			
			/**
			 * Get settings array
			 * @name get_settings
			 * @author CedCommerce <plugins@cedcommerce.com> 
			 * @link http://cedcommerce.com/
			 * @return array
			 */
			public function get_settings( $current_section = '' ) {	
				$settings = array();
				switch( $current_section ) {
					case 'hpul_captcha' :
					$settings = $this->hpulCaptchaSettings();
					break;
					default:
					$settings = $this->hidePriceUntilLoginHtml();
					break;
				}

				return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $current_section );
			}
			
			/**
			 * Settings for admin panel
			 * @name hidePriceUntilLoginHtml
			 * @author CedCommerce <plugins@cedcommerce.com> 
			 * @link http://cedcommerce.com/
			 */
			function hidePriceUntilLoginHtml() {

				
				$roles 			= get_editable_roles();
				
				$assigned_roles = array();

				foreach( $roles as $role_name => $role_info ) {
					$initial_assigned_roles = array( $role_name => $role_info['name'] );
					$assigned_roles 		= array_merge( $assigned_roles, $initial_assigned_roles );
				}
				$settings = apply_filters( 
					'hpul_general_settings', 
					array(
						// General Section
						'top-label' => array(
							'name'     => __( 'Settings of Hide Price', CED_HPUL_TXT_DMN ),
							'type'     => 'title',
							'id'       => 'wc_ced_hpul_setting_tab_section_title-1'
							), 
						'enable-disable-hide-price-plugin'	=>array(
							'title'   => __( 'Hiding Feature', CED_HPUL_TXT_DMN ),
							'desc'    => __( 'It control which feature you wan to use', CED_HPUL_TXT_DMN ),
							'id'      => 'ced_hpul_enable_hide_price',
							'class'   => 'ced_hpul_enable_hide_price hpul_half_width',
							'type'    => 'radio',
							'options' => array(
								'Hide_Price_Until_Login_Features'    => __( 'Hide Price Until Login Features', CED_HPUL_TXT_DMN ),
								'Hide_Price_using_Password_Features' => __( 'Hide Price using Password Features', CED_HPUL_TXT_DMN ),
								'Hide_Price_for_roles' 				 => __('Hide Price According to Role of User', CED_HPUL_TXT_DMN ),
								'Hide_Price_for_none' 				 => __('Disable Hide Price', CED_HPUL_TXT_DMN ),
								),
							'autoload'        => false,
							'desc_tip'        =>  true,
							'show_if_checked' => 'option',
							),
						array(
							'type' 	=> 'sectionend',
							'id' 	=> 'hpul_setting_start',
							),
						
						array( 
							'title' =>  __( 'Hide Price Until Login Settings', CED_HPUL_TXT_DMN ), 
							'type'  =>  'title',
							'class' => 'hpul_settings', 
							'desc'  =>  __( 'These settings are mendatory when using Hide Price Until Login Feature.', CED_HPUL_TXT_DMN ), 
							'id' => 'hpul_settings', 
							),
						'empty_user_name_text' => array(
							'title'    => __( 'Empty User Name Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_empty_user_name_text',
							'css'      => 'width: 57%',
							'default'  => 'Please enter user name !',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' => __( "Text to be shown when user name is not entered and pressed submit button to show price on login and registration form", CED_HPUL_TXT_DMN )
							),
						'enable-disable-registration-form'	=>array(
							'title'	=> __( 'Register/Login Form', CED_HPUL_TXT_DMN ),
							'desc'	=> __( 'This setting for your desired registration form.', CED_HPUL_TXT_DMN ),
							'id'	=> 'ced_hpul_register_form',
							'class'	=> 'ced_hpul_register_form',
							'type'	=> 'radio',
							'options' => array(
								'hpul_enable_form'	=> __( "Plugin's Form", CED_HPUL_TXT_DMN ),
								'hpul_custom_form'	=> __( "Custom Form", CED_HPUL_TXT_DMN ),
								'hpul_disable_form' => __( "Disable", CED_HPUL_TXT_DMN )
								),
							'autoload'        => false,
							'desc_tip'        => true,
							'show_if_checked' => 'option'
							),
						'register_first_login_text' => array(
							'title'   	=> __( 'Text Before Register/Login Link', CED_HPUL_TXT_DMN ),
							'id'        => CED_HPUL_PREFIX.'_register_first_login_text',
							'css'      	=> 'width: 57%',
							'default' 	=> 	'Please',
							'type'      => 'text',
							'autoload'  => false,
							'desc_tip' 	=>  __( "Text to show before login/register link, when user is logged out or current user is not registered", CED_HPUL_TXT_DMN )
							),
						'register_second_login_text' => array(
							'title'   	=> __( 'Text for Register/Login Link', CED_HPUL_TXT_DMN ),
							'id'        => CED_HPUL_PREFIX.'_register_second_login_text',
							'css'      	=> 'width: 57%',
							'default'   => 'Register/Login',
							'type'      => 'text',
							'autoload'  => false,
							'desc_tip' 	=>  __( "Text to show for login/register link, when user is logged out or current user is not registered", CED_HPUL_TXT_DMN )
							),
						'register_third_login_text' => array(
							'title'   		=> __( 'Text Before Register/Login Link', CED_HPUL_TXT_DMN ),
							'id'         	=> CED_HPUL_PREFIX.'_register_third_login_text',
							'css'      		=> 'width: 57%',
							'type'          => 'text',
							'default' 		=> 	'to see the price',
							'autoload'      => false,
							'desc_tip' 		=>  __( "Text to show after login/register link, when user is logged out or current user is not registered", CED_HPUL_TXT_DMN )
							),
						'hpul_custom_register_form' => array(
							'title'   	=> __( 'Link of your Regeister/Login form', CED_HPUL_TXT_DMN ),
							'id'        => CED_HPUL_PREFIX.'_register_link',
							'css'      	=> 'width: 57%',
							'type'      => 'text',
							'autoload'  => false,
							'desc_tip'	=>  __( "You've disabled plugin's form it means you need to enter you cutom login/register form link. By doing this, the login link will open your given form. If this field is empty then default regisration popup will open.", CED_HPUL_TXT_DMN )
							),
						'enable-disable-extra-setting' =>array(
							'title'	=> __( 'Register form applicable at', CED_HPUL_TXT_DMN ),
							'desc'	=> __( 'Enable Registration form only at specific woocommerce pages. If you do not choose any of these pages the Registration for will be visible to all of these pages.', CED_HPUL_TXT_DMN ),
							'id'	=> 'ced_hpul_extra_pages_options',
							'class'	=> 'ced_hpul_extra_options wc-enhanced-select',
							'type'	=> 'multiselect',
							'options' => array(
								'hpul_shop_page' 		=> __( "Shop Page", CED_HPUL_TXT_DMN ),
								'hpul_product_page' 	=> __( "Product Single Page", CED_HPUL_TXT_DMN ),
								'hpul_cart_page' 		=> __( "Cart Page", CED_HPUL_TXT_DMN ),
								'hpul_checkout_page' 	=> __( "Checkout Page", CED_HPUL_TXT_DMN )
								),
							'autoload'        => false,
							'desc_tip'        => true,
							// 'show_if_checked' => 'option'
							),
						'hpul_hide_email_price_guest'=>array(
							'title'    	=> __( 'Hide price in customer order email', CED_HPUL_TXT_DMN ),
							'id'       	=> CED_HP_PREFIX.'_order_email_price_guest',
							'type'     	=> 'checkbox',
							'autoload' 	=> false,
							'desc' 		=>  __( "Hide price in email sent when customer places order. Prices will be hidden from email if user is not logged-in while placing an order.", CED_HPUL_TXT_DMN ),
							),
						'hpul_hide_email_price_logged_in'=>array(
							'title'    	=> __( 'Hide price in customer order email', CED_HPUL_TXT_DMN ),
							'id'       	=> CED_HP_PREFIX.'_order_email_price_logged_in',
							'type'     	=> 'checkbox',
							'autoload' 	=> false,
							'desc' 		=>  __( "Hide price in email sent when customer order places. Prices will be hidden from email if user is logged-in while placing an order.", CED_HPUL_TXT_DMN ),
							),
						'empty_email_text' => array(
							'title'    => __( 'Empty Email Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_empty_email_text',
							'css'      => 'width: 57%',
							'default'  => 'Please enter the email first !',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __( "Text to be shown when email is not entered and pressed submit button to show price on registration form", CED_HPUL_TXT_DMN )
							),
						'hpul_empty_password_text' => array(
							'title'    => __( 'Empty Password Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_empty_password_text',
							'css'      => 'width: 57%',
							'default'  => 'Please enter the password first !',
							'type'     => 'textarea',
							'desc_tip' =>  __( "Text to be shown when password is not entered and pressed submit button to show price on login and registration form.", CED_HPUL_TXT_DMN )
							),
						'empty_confirmed_password_text' => array(
							'title'    => __( 'Empty Confirmed Password Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_empty_confirmed_password_text',
							'css'      => 'width: 57%',
							'default'  => 'Please enter the confirmed password first !',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __("Text to be shown when confirmed password is not entered and pressed submit button to show price on registration form", CED_HPUL_TXT_DMN )
							),
						'mismatch_password_text' => array(
							'title'    => __( 'Mis-matched Password Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_mis_matched_password_text',
							'css'      => 'width: 57%',
							'default'  => 'Password you\'ve entered don\'t match',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __( "Text to be shown when password and confirmed password entered don't match on registration form.", CED_HPUL_TXT_DMN )
							),
						'register_success_text'=>array(
							'title'    => __( 'Successfull Registration Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_register_success_text',
							'css'      => 'width: 57%',
							'default'  => 'You\'ve successfully registered and logged in, now price will be shown',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __("Text to be shown if new user successfully registered on the site.", CED_HPUL_TXT_DMN )
							),
						'login_success_text'=>array(
							'title'    => __( 'Successfull Login Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_login_success_text',
							'css'      => 'width: 57%',
							'default'  => 'You\'ve successfully logged in, now price will be shown',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __("Text to be shown if new user successfully logged-in on the site.", CED_HPUL_TXT_DMN )
							),
						'register_submit_text'=>array(
							'title'    => __( 'Register Submit Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_register_submit_text',
							'default'  => 'Sign Up',
							'type'     => 'text',
							'autoload' => false,
							'desc_tip' =>  __("Text for submit button on Registration form", CED_HPUL_TXT_DMN )
							),
						'login_submit_text'=>array(
							'title'    => __( 'Login Submit Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HPUL_PREFIX.'_login_submit_text',
							'default'  => 'Sign In',
							'type'     => 'text',
							'autoload' => false,
							'desc_tip' =>  __("Text for submit button on Login form", CED_HPUL_TXT_DMN )
							),
						array(
							'type' => 'sectionend',
							'id'   => 'hpul_setting_end',
							),
						
						array(
							'title'	=>  __( 'Hide Price Using Password Settings', CED_HPUL_TXT_DMN ),
							'type' 	=>  'title',
							'desc'  =>	 __( 'These settings are mandatory when using Hide Price Password Feature.', CED_HPUL_TXT_DMN ),
							'id'	=> 	'hpup_settings' 
							),
						'summary_text'=>array(
							'title'			=> __( 'Summary Text', CED_HPUL_TXT_DMN ),
							'placeholder'	=> 'Enter some text here to be shown at summary of products',
							'id'            => CED_HP_PREFIX.'_summary_text',
							'type'          => 'textarea',
							'autoload'      => false,
							'desc_tip' 		=> __("This text will be shown under the products' summary", CED_HPUL_TXT_DMN )
							),
						'password_for_price'=>array(
							'title'    	=> __( 'Password to show the price', CED_HPUL_TXT_DMN ),
							'placeholder'	  => 'Enter your password here',
							'id'              => CED_HP_PREFIX.'_password_for_price',
							'type'            => 'text',
							'autoload'        => false,
							'desc_tip' =>  __("Enter some password in the field which will be needed to show the price of product", CED_HPUL_TXT_DMN )
							),
						'order_now_text'=>array(
							'title'    => __( 'Order Now Button Text', CED_HPUL_TXT_DMN ),
							'id'              => CED_HP_PREFIX.'_order_now_text',
							'default'         => 'Order Now',
							'type'            => 'text',
							'autoload'        => false,
							'desc_tip' =>  __("This is the text of Order Now. You can change it from here", CED_HPUL_TXT_DMN )
							),
						'submit_text'=>array(
							'title'    => __( 'Submit Text', CED_HPUL_TXT_DMN ),
							'id'              => CED_HP_PREFIX.'_submit_text',
							'default'         => 'Submit',
							'type'            => 'text',
							'autoload'        => false,
							'desc_tip' =>  __( "This is the text to be shown in submit button.", CED_HPUL_TXT_DMN )
							),
						'password_field_placeholder'=>array(
							'title'    => __( 'Password Field Placeholder', CED_HPUL_TXT_DMN ),
							'id'       => CED_HP_PREFIX.'_password_field_placeholder',
							'default'  => 'Enter your password to order',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __( "This is the placeholder, will be shown to password field", CED_HPUL_TXT_DMN )
							),
						'empty_password_text'=>array(
							'title'    => __( 'Empty Password Text', CED_HPUL_TXT_DMN ),
							'id'       => CED_HP_PREFIX.'_empty_password_text',
							'default'  => 'Please enter the password first !',
							'type'     => 'textarea',
							'autoload' => false,
							'desc_tip' =>  __("Text to be shown when password is not entered and pressed submit button to show price", CED_HPUL_TXT_DMN )
							),
						'wrong_password_text'=>array(
							'title'    	=> __( 'Wrong Password Text', CED_HPUL_TXT_DMN ),
							'id'		=> CED_HP_PREFIX.'_wrong_password_text',
							'default'   => 'Password you\'ve entered is incorrect',
							'type'      => 'textarea',
							'autoload'  => false,
							'desc_tip' 	=>  __( "Text to be shown when wrong password is entered", CED_HPUL_TXT_DMN )
							),
						'matched_password_text'=>array(
							'title'    => __( 'Matched Password Text', CED_HPUL_TXT_DMN ),
							'id'              => CED_HP_PREFIX.'_matched_password_text',
							'default'         => 'Password is matched, now price will be shown',
							'type'            => 'textarea',
							'autoload'        => false,
							'desc_tip' =>  __( "text to be shown if password is matched, Price is going to be show", CED_HPUL_TXT_DMN )
							),
						array(
							'type' => 'sectionend',
							'id' => 'hpul_setting_role',
							),
						
						array( 
							'title' => __( 'Hide Price Using Role Settings', CED_HPUL_TXT_DMN ),
							'type'  => 'title', 
							'desc'  => __( 'These settings are mandatory when using Hide Price Role Feature.', CED_HPUL_TXT_DMN ), 
							'id' 	=> 'hpur_settings' 
							),
						'roles' => array (
							'title' 		=>	 __ ( 'Select User Roles ', CED_HPUL_TXT_DMN ),
							'type' 			=> 	'multiselect',
							'id'			=>	'ced_hpr_role',
							'class' 		=> 	'wc-enhanced-select',
							'options' 		=> 	$assigned_roles,
							'desc_tip' 		=>  true,
							'description' 	=> 	__ ( 'Select user roles to show the price', CED_HPUL_TXT_DMN ),
							),
						'section_end-1' => array(
							'type' 	=> 'sectionend',
							'id' 	=> 'wc_ced_hpul_setting_tab_section_end'
							),
						)
);
return $settings;
}
function hpulCaptchaSettings() {

	$settings =  array(
		'top-label' => array(
			'name'  => __( 'Captcha Settings', CED_HPUL_TXT_DMN ),
			'type'  => 'title',
			'desc'  =>	__( 'Please', CED_HPUL_TXT_DMN ) .' <a href="https://www.google.com/recaptcha/admin">'. __( 'register you domain', CED_HPUL_TXT_DMN ) .'</a>'. __( ' with Google to obtain the API keys and enter them below.', CED_HPUL_TXT_DMN ),
			'id'    => 'ced_hpul_captcha_setting_title'
			), 
		'enable-disable-captcha-setting' =>array(
			'title'	=> __( 'Captcha', CED_HPUL_TXT_DMN ),
			'desc'	=> __( 'Enable captcha to prevent spams.', CED_HPUL_TXT_DMN ),
			'id'	=> 'ced_hpul_captcha_option',
			'class'	=> 'ced_hpul_captcha_option',
			'type'	=> 'radio',
			'options' => array(
				'hpul_enable_captcha' 	=> __( "Enable", CED_HPUL_TXT_DMN ),
				'hpul_disable_captcha'  => __( "Disable", CED_HPUL_TXT_DMN )
				),
			'autoload'        => false,
			'desc_tip'        => true,
			'show_if_checked' => 'option'
			),
		'hpul_captcha_site_key'=>array(
			'title'    => __( 'Site key', CED_HPUL_TXT_DMN ),
			'id'       => CED_HP_PREFIX.'_captch_site_key',
			'type'     => 'text',
			'css'      => 'width: 57%',
			'autoload' => false,
			'placeholder' => __( 'Enter you site key', 'domain' ),
			'desc_tip' =>  __( "Enter your site key got from google recpatcha", CED_HPUL_TXT_DMN )
			),
		'hpul_captcha_secret_key'=>array(
			'title'    => __( 'Secret key', CED_HPUL_TXT_DMN ),
			'id'       => CED_HP_PREFIX.'_captch_secret_key',
			'type'     => 'text',
			'css'      => 'width: 57%',
			'autoload' => false,
			'placeholder' => __( 'Enter you secret key', 'domain' ),
			'desc_tip' =>  __( "Enter your secret key got from google recpatcha", CED_HPUL_TXT_DMN )
			),
		array(
			'type' 	=> 'sectionend',
			'id' 	=> 'hpul_setting_start',
			),
		);

	return $settings = apply_filters( 'hpul_captcha_settings', $settings );
}
}
}
// new Hide_Price();

add_filter( 'woocommerce_get_settings_pages', 'hide_price_until_login_plugin_setting_class_initiate',999 );
?>