jQuery(document.body).ready(function(){
	
	/*************** Hides initial details need to be hidden ***************/
	jQuery('#ced_hp_guest_password_form').hide();
	jQuery('#ced_hp_loading_img').hide();
	
	/*************** Toggles passwoprd field ***************/	
	jQuery('#ced_hp_order_now').on('click', function() {
		jQuery('#ced_hp_guest_password_form').toggle('slow');
	});
	
	/**************** Checks if enter key is pressed ***************/
	jQuery('#ced_hp_pass_for_price').keypress(function (e) {
		var key = e.which;
		// the enter key code
		if(key == 13) {
			jQuery('#ced_hp_submit').click();
			return false;
		}
	});
	
	/*************** Sends an ajax request to match the passwords and to set the session and show the price ***************/
	jQuery('#ced_hp_submit').on('click', function() {
		var pass = jQuery('#ced_hp_pass_for_price').val();
		if( pass == '' || pass == null ) {
			jQuery('#ced_hp_error_message').text(global.empty_pass_msg);
			setTimeout(function() { jQuery('#ced_hp_error_message').text(''); }, 2000);
		} else {
			jQuery('#ced_hp_loading_img').show();
			jQuery.post(
				global.ajaxurl,
			    {
			        'action'		:	'ced_hp_submit_price_passw',
			        'password'		:	pass
			    },
			    function( data ) {
			    	jQuery('#ced_hp_loading_img').hide();
			    	if (data == 'success') {
			    		jQuery('#ced_hp_success_message').text(global.success_msg);
						window.location = '';
			    	} else {
			    		jQuery('#ced_hp_error_message').text(data);
						setTimeout(function() { jQuery('#ced_hp_error_message').text(''); }, 2000);
			    	}
			    }
			);
		}
	})
});