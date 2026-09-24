<?php
/**
 * Homepage half of switching back to RevSlider: turns the two ultralove_banner shortcodes in the
 * homepage's Elementor data back into their RevSlider shortcodes. It swaps only those strings (it
 * does not restore the saved backup), so any other homepage edits made since are kept.
 * Pass "purge" to also delete the banner slide posts:
 *   sudo -u www-data wp eval-file rollback-to-revslider.php purge
 */
$post_id = (int) get_option( 'page_on_front' );
$data    = get_post_meta( $post_id, '_elementor_data', true );
if ( ! $post_id || ! is_string( $data ) || null === json_decode( $data ) ) {
	echo "ABORT: homepage Elementor data missing or not valid JSON.\n";
	return;
}

$new = str_replace(
	array( '[ultralove_banner group=\"home\"]' ),
	array( '[rev_slider alias=\"slider-1\"]' ),
	$data,
	$count
);
if ( $count && null === json_decode( $new ) ) {
	echo "ABORT: swapped data is not valid JSON; homepage left untouched.\n";
	return;
}
if ( $count ) {
	update_post_meta( $post_id, '_elementor_data', wp_slash( $new ) );
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}
$check = get_post_meta( $post_id, '_elementor_data', true );
echo "Reverted {$count} shortcode(s) on post {$post_id}; stored data valid JSON: " . ( null !== json_decode( $check ) ? 'yes' : 'NO' ) . "\n";

if ( isset( $args ) && in_array( 'purge', (array) $args, true ) ) {
	$n = 0;
	foreach ( get_posts( array( 'post_type' => 'ub_banner_slide', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
		wp_delete_post( $id, true );
		++$n;
	}
	echo "Deleted {$n} banner slide post(s).\n";
}
