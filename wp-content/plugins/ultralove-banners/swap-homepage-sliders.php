<?php
/**
 * One-time swap: replaces the two RevSlider hero shortcodes in the homepage's Elementor data
 * with the new ultralove_banner shortcode. Run via: wp eval-file swap-homepage-sliders.php
 */

$post_id = 14;
$data    = get_post_meta( $post_id, '_elementor_data', true );
$before  = $data;

$data = str_replace(
	'[rev_slider alias=\"slider-1\"]',
	'[ultralove_banner group=\"home\"]',
	$data
);
$data = str_replace(
	'[rev_slider alias=\"chorus-1\"]',
	'',
	$data
);

if ( $data === $before ) {
	echo "NO CHANGES MADE - patterns not found\n";
} else {
	// update_post_meta() runs the value through wp_unslash() internally (it assumes meta values
	// come from $_POST-style "magic quoted" data) - since this JSON's escaped quotes are meaningful
	// content, not an artifact of that convention, wp_slash() compensates so the right value lands
	// in the database.
	update_post_meta( $post_id, '_elementor_data', wp_slash( $data ) );
	echo "Updated post meta successfully\n";
}
