<?php
/** Offline description parser and opt-in import regressions. */
require_once __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/descriptions.php';

function expect_description( string $source, string $expected, array $important = array(), array $info = array() ): void {
	$result = ultralove_rn_clean_description( $source );
	if ( $result['html'] !== $expected || $result['important'] !== $important || $result['info'] !== $info ) {
		throw new RuntimeException( 'Unexpected cleanup: ' . json_encode( array( $source, $result ), JSON_THROW_ON_ERROR ) );
	}
	$again = ultralove_rn_clean_description( $result['html'] );
	if ( $again['html'] !== $result['html'] || $again['info'] || $again['important'] ) {
		throw new RuntimeException( 'Description normalization is not idempotent.' );
	}
}
$phrase = 'The Essential Clitoral Stimulator';
$source = '<b><font color=red><font size="3"> ' . $phrase . ' </font></FONT></b><p>' . "\r\nSimple and efficient.";
expect_description( $source, '<span class="ul-product-callout">' . $phrase . '</span><p>' . "\r\nSimple and efficient." );
expect_description(
	'<p><b><span style="color: red;"><span style="font-size: small;">Incredibly Personal</span></span></b></p>',
	'<p><span class="ul-product-callout">Incredibly Personal</span></p>'
);
expect_description( '<p><b><font color="red">SMART PHONE NOT INCLUDED</font></b></p>', '<p><span class="ul-product-notice">SMART PHONE NOT INCLUDED</span></p>' );
$offer = 'LIMIT 1 PER STORE ONLY FREE WITH 2 UNITS BOUGHT';
$normalized_offer = "LIMIT 1 PER STORE ONLY\nFREE WITH 2 UNITS BOUGHT";
$offer_html = '<b><b><font color=red><font size="4">LIMIT 1 PER STORE ONLY<br> FREE WITH 2 UNITS BOUGHT</font></FONT></b></b><p>';
expect_description( $offer_html, '<p>', array( 'LIMIT 1 PER STORE ONLY', 'FREE WITH 2 UNITS BOUGHT' ) );
foreach ( array(
	'TESTER - ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT',
	'ONE PER STORE ONLY - FREE WITH 3 UNITS BOUGHT',
	'Display Stand - ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT',
) as $condition ) {
	expect_description(
		'<p><b><font color=red><font size="3">' . $condition . '</font></FONT></b><p>Marketing copy.',
		'<p><p>Marketing copy.',
		array( 'LIMIT 1 PER STORE ONLY', 'FREE WITH 3 UNITS BOUGHT' )
	);
}
expect_description(
	'<p><b>TESTER<br>ONE BOTTLE PER STORE ONLY<br>FREE WITH 3 UNITS BOUGHT</b></p>',
	'<p></p>',
	array( 'LIMIT 1 BOTTLE PER STORE ONLY', 'FREE WITH 3 UNITS BOUGHT' )
);
$lemon_media = '<imgsrc="https://ultralove.ca/wp-content/uploads/RMP_Logo_withoutTM_Black_eps.jpg" alt="" width="147" height="150" class="alignnone size-full wp-image-39803" /><p><p>';
$lemon_offer = '<br><b><font color=red><font size="3"> TESTER - ONE PER STORE ONLY<br> FREE WITH 3 UNITS BOUGHT</font></FONT></b>';
expect_description(
	$lemon_media . $lemon_offer . "<p>\n\nROMP Lemon Crush marketing.",
	$lemon_media . "<p>\n\nROMP Lemon Crush marketing.",
	array( 'LIMIT 1 PER STORE ONLY', 'FREE WITH 3 UNITS BOUGHT' )
);
$lemon_before = array( 'post_content' => $lemon_media . $lemon_offer . '<p>Marketing.', 'post_excerpt' => 'Unchanged excerpt.', 'info' => '', 'important' => "LIMIT 1 PER STORE ONLY\nFREE WITH 3 UNITS BOUGHT" );
$lemon_after = ultralove_rn_description_after( $lemon_before, $lemon_review );
if ( $lemon_after['important'] !== $lemon_before['important'] || $lemon_review || ultralove_rn_description_after( $lemon_after ) !== $lemon_after ) {
	throw new RuntimeException( 'Existing tester offer must deduplicate without review or repeat changes.' );
}
expect_description( '<p><b>DISCOUNTS DO NOT APPLY</b></p>', '<p></p>', array(), array( 'DISCOUNTS DO NOT APPLY' ) );
foreach ( array(
	'<p><b>Meaningful features. ' . $offer . '</b></p>',
	'<p>Let your creativity run free with the new Satisfyer Connect app!</p>',
	'<p>Play worry-free with ingredients you love.</p>',
	'<p><b>TESTER - ONE PER STORE ONLY FREE WITH 3 UNITS BOUGHT. Product details.</b></p>',
	'<p><b>TESTER - rechargeable and waterproof.</b></p>',
	'<p><b>Display Stand - dimensions 20 x 30 cm.</b></p>',
	'<p><b>ONE PER STORE ONLY - FREE WITH 12 PRODUCTS BOUGHT (4 each type of lube &amp; Testers)</b></p>',
	'<b>Ordinary bold</b>',
	'<p><font color="#5b8dee">California Sky</font></p>',
	'<p><font color="orange">Glows Orange</font></p>',
	'<p><font color="red">Unreviewed marketing headline</font></p>',
	'<p><font color="red"><a href="/details">Incredibly Personal</a></font></p>',
	'<p><font color="red" data-extra="keep">Incredibly Personal</font></p>',
	'<p><b><font color="red">Incredibly Personal</b></font></p>',
	'<pthe><b><font color="red">Incredibly Personal</font></b></pthe>',
	'<table><tr><td><b>DISCOUNTS DO NOT APPLY</b></td></tr></table>',
	'<ul><li><b>DISCOUNTS DO NOT APPLY</b></li></ul>',
	'<ul><li><p><b>LIMIT 1 PER STORE ONLY</b></p></li></ul>',
	'<table><tr><td><p><b><font color=red>Incredibly Personal</font></b></p></td></tr></table>',
	'<script>const html = "<p>DISCOUNTS DO NOT APPLY</p>";</script>',
	'<p><span class="ul-product-callout">Incredibly Personal</span></p>',
) as $unchanged ) {
	expect_description( $unchanged, $unchanged );
}
$untouched = '<div style="float:left;padding:12px"><img src="/image.jpg"></div><table><tr><td>Details &amp; sizes</td></tr></table>';
$structured = $untouched . '<p><b><font color="red">Sensual Comfort!</font></b></p>';
expect_description( $structured, $structured );
$before = array( 'post_content' => $offer_html, 'post_excerpt' => '', 'info' => "Manual note\n" . strtolower( $offer ), 'important' => 'Existing important' );
$after = ultralove_rn_description_after( $before );
if ( $after['info'] !== 'Manual note' || $after['important'] !== "Existing important\n" . $normalized_offer || ultralove_rn_description_after( $after ) !== $after ) {
	throw new RuntimeException( 'Note merge/promotion/idempotence failed.' );
}
$conflict = array( 'post_content' => '', 'post_excerpt' => '', 'info' => 'FREE WITH 2 UNITS BOUGHT', 'important' => 'FREE WITH 3 UNITS BOUGHT' );
if ( ultralove_rn_description_after( $conflict, $review ) !== $conflict || ! $review ) {
	throw new RuntimeException( 'Description merger must preserve and report conflicting purchase conditions.' );
}
try {
	ultralove_rn_clean_description( "\xB1" );
	throw new LogicException( 'Invalid UTF-8 was accepted.' );
} catch ( RuntimeException $exception ) {
	// The shared text normalizer explicitly rejects invalid input.
}

define( 'ABSPATH', __DIR__ );
const ULTRALOVE_RN_INFO = '_ul_retailer_info';
const ULTRALOVE_RN_IMPORTANT = '_ul_retailer_important';
function add_action( ...$args ) { $GLOBALS['actions'][] = $args; }
function get_post_type( $id ) { return 'product'; }
function get_post_field( $field, $id, $context ) { return $GLOBALS['post'][ $field ]; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $key ] = stripslashes( $value ); }
function clean_post_cache( $id ) {}
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : addslashes( $value ); }
function wp_update_post( $values, $return_error ) {
	foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
		if ( isset( $values[ $field ] ) ) {
			$GLOBALS['post'][ $field ] = stripslashes( $values[ $field ] );
		}
	}
	return $values['ID'];
}
function is_wp_error( $value ) { return false; }
require_once __DIR__ . '/../../wp-content/mu-plugins/42-product-description-cleanup.php';
$post = array( 'post_content' => $offer_html, 'post_excerpt' => $source, 'post_title' => 'Unchanged', 'post_name' => 'unchanged' );
$meta = array();
$original = $post;
ultralove_description_after_import( 1 );
if ( $post !== $original || $meta ) {
	throw new RuntimeException( 'Import changed an unselected product.' );
}
$meta[ ULTRALOVE_DESCRIPTION_CLEANUP_META ] = ULTRALOVE_DESCRIPTION_CLEANUP_VERSION;
ultralove_description_after_import( 1 );
if ( $post['post_content'] !== '<p>' || ! str_contains( $post['post_excerpt'], 'ul-product-callout' ) || $meta[ ULTRALOVE_RN_IMPORTANT ] !== $normalized_offer
	|| $post['post_title'] !== 'Unchanged' || $post['post_name'] !== 'unchanged' ) {
	throw new RuntimeException( 'Opted-in import did not persist expected data.' );
}
$expected_post = $post;
$expected_meta = $meta;
$post = $original;
ultralove_description_after_import( 1 );
ultralove_description_after_import( 1 );
if ( $post !== $expected_post || $meta !== $expected_meta ) {
	throw new RuntimeException( 'Repeat import was not idempotent.' );
}
$post = array( 'post_content' => $lemon_before['post_content'], 'post_excerpt' => $lemon_before['post_excerpt'], 'post_title' => 'R3276 Romp Lemon Crush Tester Only', 'post_name' => 'r3276-romp-lemon-crush-tester-only' );
$meta = array( ULTRALOVE_DESCRIPTION_CLEANUP_META => ULTRALOVE_DESCRIPTION_CLEANUP_VERSION, ULTRALOVE_RN_IMPORTANT => $lemon_before['important'] );
$tester_import = $post;
ultralove_description_after_import( 1 );
if ( $post['post_content'] !== $lemon_after['post_content'] || $meta[ ULTRALOVE_RN_IMPORTANT ] !== $lemon_before['important']
	|| $post['post_title'] !== $tester_import['post_title'] || $post['post_name'] !== $tester_import['post_name'] ) {
	throw new RuntimeException( 'Tester import did not remove the duplicate while preserving notes and identity.' );
}
$tester_expected = $post;
$post = $tester_import;
ultralove_description_after_import( 1 );
ultralove_description_after_import( 1 );
if ( $post !== $tester_expected || $meta[ ULTRALOVE_RN_IMPORTANT ] !== $lemon_before['important'] ) {
	throw new RuntimeException( 'Tester reimport must remain clean and deduplicated.' );
}
try {
	ultralove_description_write( 1, array_replace( ultralove_description_snapshot( 1 ), array( 'post_excerpt' => 'stale' ) ), ultralove_description_snapshot( 1 ) );
	throw new LogicException( 'Stale state was accepted.' );
} catch ( RuntimeException $exception ) {
}
function get_current_screen() { return $GLOBALS['screen']; }
function get_stylesheet_directory() { return __DIR__ . '/../../wp-content/themes/martfury-child'; }
function get_stylesheet_directory_uri() { return '/wp-content/themes/martfury-child'; }
function wp_enqueue_style( ...$args ) { $GLOBALS['styles'][] = $args; }
function wp_add_inline_style( ...$args ) { $GLOBALS['inline_styles'][] = $args; }
function add_meta_box( ...$args ) { $GLOBALS['boxes'][] = $args; }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
require_once get_stylesheet_directory() . '/inc/product-description-examples.php';
$styles = $inline_styles = $boxes = array();
foreach ( array( null, (object) array( 'base' => 'edit', 'post_type' => 'product' ), (object) array( 'base' => 'post', 'post_type' => 'post' ) ) as $screen ) {
	ultralove_product_description_example_styles();
}
if ( $styles || $inline_styles ) {
	throw new RuntimeException( 'Example styles leaked onto unrelated admin screens.' );
}
$screen = (object) array( 'base' => 'post', 'post_type' => 'product' );
ultralove_product_description_example_styles();
ultralove_enqueue_product_description_styles();
if ( 2 !== count( $styles ) || $styles[0] !== $styles[1] || 1 !== count( $inline_styles ) ) {
	throw new RuntimeException( 'Product editor and storefront do not share the stylesheet.' );
}
ultralove_product_description_examples_box();
if ( $boxes[0][3] !== 'product' || $boxes[0][4] !== 'normal' || $boxes[0][5] !== 'low' ) {
	throw new RuntimeException( 'Examples panel registered on the wrong screen.' );
}
ob_start();
ultralove_render_product_description_examples();
$examples = ob_get_clean();
$dom = new DOMDocument();
$dom->loadHTML( $examples, LIBXML_NOERROR | LIBXML_NOWARNING );
$xpath = new DOMXPath( $dom );
$snippets = $xpath->query( '//pre/code' );
$previews = $xpath->query( '//div[@class="ul-description-example-preview"]' );
if ( 3 !== $snippets->length || 3 !== $previews->length || str_contains( $examples, '<input' ) ) {
	throw new RuntimeException( 'Expected three read-only HTML and preview examples.' );
}
foreach ( $snippets as $index => $snippet ) {
	$preview = '';
	foreach ( $previews->item( $index )->childNodes as $child ) {
		$preview .= $dom->saveHTML( $child );
	}
	if ( $snippet->textContent !== $preview ) {
		throw new RuntimeException( 'HTML example differs from its rendered markup.' );
	}
}
if ( in_array( '--preview', $argv, true ) ) {
	echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Product editor description examples</title><style>';
	echo file_get_contents( get_stylesheet_directory() . '/css/product-descriptions.css' );
	echo $inline_styles[0][1];
	echo 'body{font-family:system-ui;margin:24px}main{max-width:900px}</style><main id="ul-product-description-examples"><h1>Product description style examples</h1>' . $examples . '</main></html>';
} else {
	echo "All description parser, opt-in import and product editor example checks passed\n";
}
