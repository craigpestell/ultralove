jQuery( document ).ready( function( $ ) {
	
	/*************** Hides initial details need to be hidden ***************/
	$( '#ced_hpul_loading_img' ).hide();
	$( '#ced_hpul_login_loading_img' ).hide();
	
	/******* Performing pop up registration form action on clicking login link *******/	
	$( '#ced_hpul_login_link' ).on( 'click', function( e ) {
		e.stopPropagation();
		var $this 	= $( this ),
		formUrl 	= $this.data( 'form' ),
		caption 	= $this.data( 'caption' );
		
		$( '#ced_hpul_login_form' ).hide();
		$( '#ced_hpul_guest_registration_form' ).show();

		if ( formUrl != '' && typeof formUrl != 'undefined'  ) {
			window.location = formUrl;
			return false;
		}
		
		tb_show( caption, globals.thckbxRegisterUri );
		$( '#ced_hpul_guest_registration_form' ).parents( '#TB_window' ).addClass( 'hpul_thickbox_window' );
		return false;
	});
	
	
	/*************** Hiding registration form popup if click on outside the form ***************/
	$( 'html' ).click( function() {
		$('.popup').hide();
	});
	
	/*************** Hiding registration form popup if close button clicked ***************/
	$('.popup-btn-close').click(function(e){
		$('.popup').hide();
		$('#ced_hpul_guest_registration_form_wrap').hide();
	});

	/*************** Maintaining registration form  from hiding popup if click on the form ***************/
	$('.popup').click(function(e){
		  e.stopPropagation();
	});
	
	/**************** Checks if enter key is pressed ***************/
	$('#user_cpass').keypress(function (e) {
		var key = e.which;
		// the enter key code
		if(key == 13) {
			$('#ced_hpul_submit').click();
			return false;
		}
	});
	
	/*************** Getting all values from registraiton form and done validation on it ***************/
	$('#ced_hpul_submit').on('click', function( e ) {
		e.preventDefault();
		
		var uname 	= $( '#user_name' ).val(),
		uemail 		= $( '#user_email' ).val(),
		upass 		= $( '#user_pass' ).val(),
		ucpass 		= $( '#user_cpass' ).val(),
		reCAPTCHA 	= $( document ).find( '#g-recaptcha-response' ).val();
				
		if( uname == '' || uname == null ) {
			$( '#ced_hpul_error_message' ).text( globals.empty_user_msg );
			setTimeout( function() { 
				$( '#ced_hpul_error_message' ).text(''); 
			}, 2000 );
			return false;
		} else if ( uemail == '' || uemail == null ) {
			$( '#ced_hpul_error_message' ).text(globals.empty_email);
			setTimeout( function() { 
				$( '#ced_hpul_error_message' ).text(''); 
			}, 2000 );
			return false;
		} else if ( upass == '' || upass == null ) {
			$( '#ced_hpul_error_message' ).text( globals.empty_pass_msg );
			setTimeout( function() { 
				$( '#ced_hpul_error_message' ).text(''); 
			}, 2000 );
			return false;
		} else if ( upass.length < 5 ) {
			$( '#ced_hpul_error_message' ).text( "Password must be of atleast 5 character long." );
			setTimeout( function() { 
				$( '#ced_hpul_error_message' ).text(''); 
			}, 2000 );
			return false;
		} else if (ucpass == '' || ucpass == null) {
			$('#ced_hpul_error_message').text(globals.empty_cpass);
			setTimeout( function() { 
				$( '#ced_hpul_error_message' ).text(''); 
			}, 2000 );
			return false;
		} else if ( upass != ucpass ) {
			$( '#ced_hpul_error_message' ).text( globals.mismatch_pass );
			setTimeout( function() { 
				$( '#ced_hpul_error_message' ).text(''); 
			}, 2000 );
			return false;
		}  else if ( reCAPTCHA === '' || reCAPTCHA == '' || reCAPTCHA == null || typeof reCAPTCHA == 'undefined' ) {
			if ( $( document ).find( '#g-recaptcha-response' ).length > 0 ) {
				$( '#ced_hpul_error_message' ).text( globals.mismatch_captcha );
				setTimeout( function() {
					$( '#ced_hpul_error_message' ).text(''); 
				}, 2000 );
				return false;
			}
		}

		/*** Sending ajax request to register a new user and logged in ***/
		$('#ced_hpul_loading_img').show();
		$.post(
			globals.ajaxurl,
		    {
		        'action'	: 'ced_hp_submit_guest_registration_form',
		        'uname'		: uname,
		        'uemail'	: uemail,
		        'upass'		: upass,
		        'reCAPTCHA'	: reCAPTCHA
		    },
		    function( response ) {
		    	$( '#ced_hpul_loading_img' ).hide();
		    	try {
			    	if ( response.success ) { 
			    		$( '#ced_hpul_login_success_message' ).text( response.data );
						window.location = '';
			    	} else {
			    		$( '#ced_hpul_error_message' ).text( response.data );
						setTimeout( function() { 
							$( '#ced_hpul_error_message' ).text(''); 
						}, 2000 );
			    	}
		    	} catch ( e ) {
		    		console.log( e );
		    	}
		    }
		);
	})
	
	/*************** Toggles login form popup ***************/
	
	var scrollTopLogin = '';
	var newHeightLogin = '100';

	$(window).bind('scroll', function() {
		scrollTopLogin = $( window ).scrollTop();
		newHeightLogin = scrollTopLogin + 100;
	});
	
	/****** Toggle login form for logged in user ********/
	
	$( '#ced_hp_login_form_link' ).on( 'click', function( e ) {
		e.stopPropagation();

		var $this 	= $( this ),
		caption 	= $this.data( 'caption' );

		$( '#ced_hpul_login_form' ).show();
		$( '#ced_hpul_guest_registration_form' ).hide();
		
		tb_show( caption, globals.thckbxLoginUri );
		$( '#ced_hpul_login_form' ).parents( '#TB_window' ).addClass( 'hpul_thickbox_window' );
		return false;
	});

	/*************** Hiding login form popup if click on outside the form ***************/
	$('html').click(function() {
		$('.login-popup').hide();
	});

	/*************** Hiding login form popup if close button is clicked  ***************/
	$('.login-popup-btn-close').click(function(){
		$('.login-popup').hide();
	});
	
	/*************** Maintaining login form popup if click on the form ***************/
	$('.login-popup').click(function(e){
		  e.stopPropagation();
	});
	
	/**************** Checks if enter key is pressed ***************/
	$('#login_user_pass').keypress(function (e) {
		var key = e.which;
		// the enter key code
		if(key == 13) {
			$('#ced_hpul_login_submit').click();
			return false;
		}
	});
	
	/*************** Obtaining login form data and done validtaion on it ***************/
	$('#ced_hpul_login_submit').on('click', function(e) {
		
		var uname 	= $( '#login_user_name' ).val(),
		upass 		= $('#login_user_pass').val();
								
		if( uname == '' || uname == null ) {
			$('#ced_hpul_login_error_message').text(globals.empty_user_msg);
			setTimeout(function() { $('#ced_hpul_login_error_message').text(''); }, 2000);
		}
		
		else if (upass == '' || upass == null) {
			$('#ced_hpul_login_error_message').text(globals.empty_pass_msg);
			setTimeout(function() { $('#ced_hpul_login_error_message').text(''); }, 2000);
		}
		
		/** Sending an ajax request to login user**/
		else {
			$( '#ced_hpul_login_loading_img' ).show();
			$.post(
				globals.ajaxurl,
			    {
			        'action'	: 'ced_hpul_login_user',
			        'uname'		: uname,
			        'upass'		: upass			        
			    },
			    function( response ) {
			    	$('#ced_hpul_login_loading_img').hide();
			    	try {
			    		if ( response.success ) {
				    		$('#ced_hpul_login_error_message').text('');
				    		$('#ced_hpul_login_success_message').text( globals.login_sucess_msg );
							window.location = '';
			    		} else {
				    		$('#ced_hpul_login_error_message').text( response.data );
							setTimeout(function() { $('#ced_hpul_error_message').text(''); }, 2000);							
				    	}
			    	} catch( e ) {
			    		console.log( e );
			    	}
			    }
			);
		}
	});
});