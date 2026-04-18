jQuery( document ).ready(function( $ ) {

	/*===============================================
	=            General Setting Section            =
	===============================================*/
	$( '#ced_hpul_empty_user_name_text' ).parents( 'table.form-table' ).attr({
		id 			: 'ced_hpul_until_login',
		'data-type'	: 'Hide_Price_Until_Login_Features'
	}).addClass( 'ced_hpul_form_table' );

	$( '#ced_hp_summary_text' ).parents( 'table.form-table' ).attr({
		id 			: 'ced_hpul_using_pass',
		'data-type'	: 'Hide_Price_using_Password_Features'
	}).addClass( 'ced_hpul_form_table' );

	$( '[id*="ced_hpr_role"]' ).parents( 'table.form-table' ).attr({
		id 			: 'ced_hpul_role_base',
		'data-type'	: 'Hide_Price_for_roles'
	}).addClass( 'ced_hpul_form_table' );

	$( document ).find( 'table.ced_hpul_form_table' ).hide();
	$( document ).find( 'table.ced_hpul_form_table' ).prev( 'p' ).hide();
	$( document ).find( 'table.ced_hpul_form_table' ).prev( 'p' ).prev( 'h2' ).hide();
	$( document ).find( '.ced_hpul_enable_hide_price' ).each( function( index, el ) {
		var $this 	= $( this ),
			value  	= $this.is( ':checked' ) ? $this.val() : '';

		if ( value != "Hide_Price_for_none" ) {
			$( document ).find( 'table[data-type="'+ value +'"]' ).show();
			$( document ).find( 'table[data-type="'+ value +'"]' ).prev( 'p' ).show();
			$( document ).find( 'table[data-type="'+ value +'"]' ).prev( 'p' ).prev( 'h2' ).show();
		}
	});

	$( document ).on( 'click', '.ced_hpul_enable_hide_price', function( event ) {
		var $this 	= $( this ),
			value  	= $this.is( ':checked' ) ? $this.val() : '';

		if ( value != "Hide_Price_for_none" ) {
			$( document ).find( 'table.ced_hpul_form_table' ).not( 'table[data-type="'+ value +'"]' ).hide( 'slow' );
			$( document ).find( 'table.ced_hpul_form_table' ).not( 'table[data-type="'+ value +'"]' ).prev( 'p' ).hide( 'slow' );
			$( document ).find( 'table.ced_hpul_form_table' ).not( 'table[data-type="'+ value +'"]' ).prev( 'p' ).prev( 'h2' ).hide( 'slow' );

			$( document ).find( 'table[data-type="'+ value +'"]' ).show( 'slow' );
			$( document ).find( 'table[data-type="'+ value +'"]' ).prev( 'p' ).show( 'slow' );
			$( document ).find( 'table[data-type="'+ value +'"]' ).prev( 'p' ).prev( 'h2' ).show( 'slow' );
		} else {
			$( document ).find( 'table.ced_hpul_form_table' ).hide( 'slow' );
			$( document ).find( 'table.ced_hpul_form_table' ).prev( 'p' ).hide( 'slow' );
			$( document ).find( 'table.ced_hpul_form_table' ).prev( 'p' ).prev( 'h2' ).hide( 'slow' );
		}
	});

	var registerForm = $( '.ced_hpul_register_form:checked' ).val();
	if ( registerForm == 'hpul_enable_form' || typeof registerForm == 'undefined' ) {
		$( '#ced_hpul_register_link' ).parents( 'tr' ).hide();
	}

	$( document ).on( 'click', '.ced_hpul_register_form', function( event ) {
		var $this = $( this );
		if ( $this.val() == 'hpul_custom_form' ) {
			$( '#ced_hpul_register_link, #ced_hpul_register_first_login_text, #ced_hpul_register_second_login_text, #ced_hpul_register_third_login_text' ).parents( 'tr' ).show();
		} else if ( $this.val() == 'hpul_disable_form' ) {
			$( '#ced_hpul_register_link, #ced_hpul_register_first_login_text, #ced_hpul_register_second_login_text, #ced_hpul_register_third_login_text' ).parents( 'tr' ).hide();
		} else if ( $this.val() == 'hpul_enable_form' ) {
			$( '#ced_hpul_register_first_login_text, #ced_hpul_register_second_login_text, #ced_hpul_register_third_login_text' ).parents( 'tr' ).show();
			$( '#ced_hpul_register_link' ).parents( 'tr' ).hide();
		}
	});
	/*=====  End of General Setting Section  ======*/
	


	/*=======================================
	=            Captcha Section            =
	=======================================*/
	var plugin_captcha = $( '.ced_hpul_captcha_option:checked' ).val();
	if ( plugin_captcha == 'hpul_disable_captcha' ) {
		$( '#ced_hp_captch_site_key, #ced_hp_captch_secret_key' ).parents( 'tr' ).hide();
	}

	$( document ).on( 'click', '.ced_hpul_captcha_option', function( event ) {
		var $this 		= $( this ),
		plugin_captcha 	= $this.val();
		if ( plugin_captcha == 'hpul_disable_captcha' ) {
			$( '#ced_hp_captch_site_key, #ced_hp_captch_secret_key' ).parents( 'tr' ).hide();
		} else {
			$( '#ced_hp_captch_site_key, #ced_hp_captch_secret_key' ).parents( 'tr' ).show();
		}
		
	});
	/*=====  End of Captcha Section  ======*/
	
});