<?php
// Switch every Gravity Form's reCAPTCHA field to a Turnstile field, modelled on an existing Turnstile field.
// Run from /var/www/html: sudo -u www-data wp eval-file ops/gravityforms/switch-recaptcha-to-turnstile.php [dry]
// (copy it to the server first). `dry` only reports. Back the forms up first - see documentation/pending-release.md.
$dry = isset( $args[0] ) && 'dry' === $args[0];
$forms = GFAPI::get_forms();

$model = null;
foreach ( $forms as $f ) {
	foreach ( $f['fields'] as $x ) {
		if ( 'turnstile' === $x->type ) {
			$model = $x;
			break 2;
		}
	}
}
if ( $model ) {
	$keep = array_diff_key( json_decode( wp_json_encode( $model ), true ), array_flip( array( 'id', 'formId', 'pageNumber', 'layoutGridColumnSpan', 'layoutGroupId', 'layoutSpacerGridColumnSpan', 'fields' ) ) );
	WP_CLI::log( 'Model field (form ' . $model->formId . '): ' . wp_json_encode( array_intersect_key( $keep, array_flip( array( 'type', 'label', 'labelPlacement', 'visibility', 'displayOnly' ) ) ) ) );
} else {
	// No form uses Turnstile yet: the settings dev's footer form field has.
	$keep = array( 'type' => 'turnstile', 'label' => 'Turnstile', 'displayOnly' => true, 'labelPlacement' => '', 'visibility' => 'visible' );
	WP_CLI::log( 'No existing Turnstile field; using the default settings.' );
}


foreach ( $forms as $f ) {
	$changed = false;
	foreach ( $f['fields'] as $i => $x ) {
		if ( 'captcha' !== $x->type ) {
			continue;
		}
		$props = array_merge( json_decode( wp_json_encode( $x ), true ), $keep, array( 'id' => $x->id, 'formId' => $f['id'], 'type' => 'turnstile', 'inputType' => '' ) );
		unset( $props['captchaType'], $props['captchaTheme'], $props['captchaSize'], $props['captchaBadge'], $props['captchaLanguage'] );
		$f['fields'][ $i ] = GF_Fields::create( $props );
		$changed = true;
		WP_CLI::log( "Form {$f['id']} ({$f['title']}): field {$x->id} reCAPTCHA -> Turnstile" );
	}
	if ( $changed && ! $dry ) {
		$r = GFAPI::update_form( $f );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
	}
}
WP_CLI::success( $dry ? 'Dry run, nothing changed.' : 'Done.' );
