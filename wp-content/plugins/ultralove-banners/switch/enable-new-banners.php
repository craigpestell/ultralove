<?php
/**
 * Homepage half of switching to the new banners: swaps the two RevSlider shortcodes in the
 * homepage's Elementor data for the ultralove_banner shortcodes, after saving a full copy of the
 * data in the ub_homepage_backup option. Run with the ultralove-banners plugin active:
 *   sudo -u www-data wp eval-file enable-new-banners.php
 */
$post_id = (int) get_option( 'page_on_front' );
$data    = get_post_meta( $post_id, '_elementor_data', true );
if ( ! $post_id || ! is_string( $data ) || null === json_decode( $data ) ) {
	echo "ABORT: homepage Elementor data missing or not valid JSON.\n";
	return;
}

update_option( 'ub_homepage_backup', array( 'time' => gmdate( 'c' ), 'post' => $post_id, 'data' => $data ), false );

$new = str_replace(
	array( '[rev_slider alias=\"slider-1\"]', '[rev_slider alias=\"chorus-1\"]' ),
	array( '[ultralove_banner group=\"home\"]', '' ),
	$data,
	$count
);
if ( 0 === $count ) {
	echo "Nothing to swap (already using the new banners?). Backup saved anyway.\n";
	return;
}
if ( null === json_decode( $new ) ) {
	echo "ABORT: swapped data is not valid JSON; homepage left untouched.\n";
	return;
}

// update_post_meta() unslashes its value, and this JSON's backslashes are meaningful.
update_post_meta( $post_id, '_elementor_data', wp_slash( $new ) );
if ( class_exists( '\Elementor\Plugin' ) ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}
$check = get_post_meta( $post_id, '_elementor_data', true );
echo "Swapped {$count} shortcode(s) on post {$post_id}; stored data valid JSON: " . ( null !== json_decode( $check ) ? 'yes' : 'NO' ) . "\n";
