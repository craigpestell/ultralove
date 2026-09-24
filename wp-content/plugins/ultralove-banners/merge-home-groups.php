<?php
/**
 * One-time migration for sites that already have slides in the old "home-left" / "home-right"
 * groups: moves them all into the single "home" group (which shows two slides at a time) and
 * swaps the two homepage shortcodes for one. Slides alternate left, right, left, right... so the
 * first pair on screen is the old left banner beside the old right banner. Run with the
 * ultralove-banners plugin (2.0.0+) active:
 *   sudo -u www-data wp eval-file merge-home-groups.php
 * Safe to run twice (a second run finds nothing to move).
 */

$fetch = function ( $group ) {
	return get_posts(
		array(
			'post_type'   => 'ub_banner_slide',
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
			'meta_key'    => '_ub_group',
			'meta_value'  => $group,
		)
	);
};

$left  = $fetch( 'home-left' );
$right = $fetch( 'home-right' );
$order = 0;
$moved = 0;
for ( $i = 0; $i < max( count( $left ), count( $right ) ); $i++ ) {
	foreach ( array( $left, $right ) as $side ) {
		if ( ! isset( $side[ $i ] ) ) {
			continue;
		}
		wp_update_post( array( 'ID' => $side[ $i ]->ID, 'menu_order' => $order++ ) );
		update_post_meta( $side[ $i ]->ID, '_ub_group', 'home' );
		++$moved;
	}
}
echo "Moved {$moved} slide(s) into the home group.\n";

$post_id = (int) get_option( 'page_on_front' );
$data    = get_post_meta( $post_id, '_elementor_data', true );
if ( ! $post_id || ! is_string( $data ) || null === json_decode( $data ) ) {
	echo "Homepage Elementor data missing or not valid JSON; shortcodes not touched.\n";
	return;
}
$new = str_replace(
	array( '[ultralove_banner group=\"home-left\"]', '[ultralove_banner group=\"home-right\"]' ),
	array( '[ultralove_banner group=\"home\"]', '' ),
	$data,
	$count
);
if ( ! $count ) {
	echo "No old home shortcodes on the homepage.\n";
	return;
}
if ( null === json_decode( $new ) ) {
	echo "ABORT: swapped data is not valid JSON; homepage left untouched.\n";
	return;
}
update_option( 'ub_homepage_backup', array( 'time' => gmdate( 'c' ), 'post' => $post_id, 'data' => $data ), false );
update_post_meta( $post_id, '_elementor_data', wp_slash( $new ) );
if ( class_exists( '\Elementor\Plugin' ) ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}
echo "Swapped {$count} shortcode(s) on post {$post_id}. The right-hand column is now empty: make the row a single full-width column in Elementor.\n";
