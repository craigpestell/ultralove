<?php 
/**
 * @name template-registration-login.php
 * @desc It includes registation/login form on clicking link as popup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
add_thickbox();
$registerWrapperClass = esc_html( esc_attr( apply_filters( 'hpul_add_register_thickbox_wrapper_class', 'ced_hpul_guest_registration_form' ) ) );
?>
<div id="ced_hpul_guest_registration_form_wrap" style="display: none">
	<div id="ced_hpul_guest_registration_form" class="<?php echo $registerWrapperClass; ?>">
		<table border="0">
			<caption>
				<?php _e( 'Registration Form', CED_HPUL_TXT_DMN ); ?>
			</caption>
			<tr>
				<td>
					<?php _e( 'User name : ', CED_HPUL_TXT_DMN );?>
				</td>
				<td>
					<input type="text" placeholder="<?php _e( 'Enter user name', CED_HPUL_TXT_DMN ); ?>" id="user_name">
				</td>
			</tr>
			<tr>
				<td>
					<?php _e( 'Email : ', CED_HPUL_TXT_DMN );?>
				</td>
				<td>
					<input type="email" placeholder="<?php _e( 'Enter user email', CED_HPUL_TXT_DMN ); ?>" id="user_email">
				</td>
			</tr>
			<tr>
				<td>
					<?php _e( 'Password : ', CED_HPUL_TXT_DMN );?>
				</td>
				<td>
					<input type="password" placeholder="<?php _e( 'Enter password', CED_HPUL_TXT_DMN ); ?>" id="user_pass">
				</td>
			</tr>
			<tr>
				<td>
					<?php _e( 'Confirm Password : ', CED_HPUL_TXT_DMN );?>
				</td>
				<td>
					<input type="password" placeholder="<?php _e( 'Enter password again', CED_HPUL_TXT_DMN ); ?>" id="user_cpass">
				</td>
			</tr>

			<?php 
			/**
			 * Apply captcha is captcha required deatils are filled and captcha is enabled from settings.
			 */
			do_action( 'hpul_render_captcha' ); 
			?>

			<tr>
				<td colspan="2">
					<?php _e( 'Already Registered ! Click ', CED_HPUL_TXT_DMN );?>
					<a id="ced_hp_login_form_link" data-caption="<?php _e( 'Login Form', CED_HPUL_TXT_DMN ); ?>" href="javascript:void(0)"> 
						<?php _e( ' Here', CED_HPUL_TXT_DMN );?>
					</a>
					<?php _e( ' to login.', CED_HPUL_TXT_DMN );?>
				</td>
			</tr>

			<?php 
			do_action( 'hpul_add_registration_popup_row' );
			?>
		</table>
		<?php 
		/**
		 * Allow someone to add their content over here.
		 */
		do_action( 'hpul_after_register_form_table' );
		?>
		<input type="button" id="ced_hpul_submit" class="button alt" value="<?php echo get_option(CED_HPUL_PREFIX.'_register_submit_text');?>" name="submit_pass">
		<img src="<?php echo CED_HPUL_PLUGIN_URL.'assets/images/ajax-loader.gif';?>" id="ced_hpul_loading_img" class="loading-img">
		<span id="ced_hpul_success_message" class="success-msg" ></span>
		<span id="ced_hpul_error_message" class="error-msg"></span>
	</div> 
</div>
<?php 
$loginWrapperClass = esc_html( esc_attr( apply_filters( 'hpul_add_login_thickbox_wrapper_class', 'ced_hpul_login_form' ) ) ); 
?>
<div id="ced_hpul_login_form_wrap" style="display: none">
	<div id="ced_hpul_login_form" class="<?php echo $loginWrapperClass; ?>">
		<table>
			<caption><?php _e( 'Login Form', CED_HPUL_TXT_DMN );?></caption>
			<tr>
				<td>
					<?php _e( 'User name : ', CED_HPUL_TXT_DMN );?>
				</td>
				<td>
					<input type="text" placeholder="<?php _e( 'Enter user name', CED_HPUL_TXT_DMN ); ?>" id="login_user_name">
				</td>
			</tr>
			<tr>
				<td>
					<?php _e( 'Password : ', CED_HPUL_TXT_DMN );?>
				</td>
				<td>
					<input type="password" placeholder="<?php _e( 'Enter password', CED_HPUL_TXT_DMN ); ?>" id="login_user_pass">
				</td>
			</tr>
			<?php 
			do_action( 'hpul_add_login_popup_row' );
			?>
		</table>
		<input type="button" id="ced_hpul_login_submit" class="button alt" value="<?php echo get_option( CED_HPUL_PREFIX.'_login_submit_text' );?>" name="submit_pass">
		<img src="<?php echo CED_HPUL_PLUGIN_URL.'assets/images/ajax-loader.gif';?>" id="ced_hpul_login_loading_img" class="loading-img">
		<span id="ced_hpul_login_success_message" class="success-msg" ></span>
		<span id="ced_hpul_login_error_message" class="error-msg"></span>
	</div> 
</div>