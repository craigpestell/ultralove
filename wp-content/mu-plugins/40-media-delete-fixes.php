<?php
/**
 * Plugin Name: Media Library Delete Fixes
 * Description: Two Cloudinary-related bugs made some Media Library deletes fail with the browser
 *              alert "Error in deleting the attachment":
 *
 *              1. Cloudinary's Delete_Sync::can_delete_asset() (user_has_cap filter) strips the
 *                 delete_posts capability from any attachment it considers "pending sync" - i.e.
 *                 a sync was attempted in the last 5 minutes. With auto-sync on, the plugin keeps
 *                 retrying the ~19k unsynced attachments in the background, so a rotating set of a
 *                 few hundred attachments is delete-locked at any moment (admin-ajax delete-post
 *                 answers "-1"). Removing that filter lets deletes through; Cloudinary's own
 *                 delete_attachment hook still destroys the remote asset when one exists.
 *
 *              2. For preview-only formats (PDFs etc.) Cloudinary's filter_downsize() returns
 *                 $size[0] / $size[1] as width/height, which for a named size such as
 *                 'woocommerce_thumbnail' is the characters 'w' / 'o'. On delete, the Cloudflare
 *                 plugin's cache purge calls wp_get_attachment_image_src() for every size, WooCommerce
 *                 feeds those strings into wp_constrain_dimensions() and PHP 8 fatals
 *                 ("Unsupported operand types: int / string", HTTP 500). This replaces non-numeric
 *                 dimensions with the registered size's dimensions (or 0).
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		global $wp_filter;
		if ( empty( $wp_filter['user_has_cap'] ) ) {
			return;
		}
		foreach ( $wp_filter['user_has_cap']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && 'can_delete_asset' === $fn[1] && is_a( $fn[0], 'Cloudinary\Sync\Delete_Sync' ) ) {
					remove_filter( 'user_has_cap', $fn, $priority );
				}
			}
		}
	},
	PHP_INT_MAX
);

add_filter(
	'image_downsize',
	function ( $image, $attachment_id, $size ) {
		if ( ! is_array( $image ) || ( is_numeric( $image[1] ?? 0 ) && is_numeric( $image[2] ?? 0 ) ) ) {
			return $image;
		}
		$width  = 0;
		$height = 0;
		if ( is_array( $size ) ) {
			$width  = (int) ( $size[0] ?? 0 );
			$height = (int) ( $size[1] ?? 0 );
		} elseif ( is_string( $size ) ) {
			$sizes  = wp_get_registered_image_subsizes();
			$width  = (int) ( $sizes[ $size ]['width'] ?? 0 );
			$height = (int) ( $sizes[ $size ]['height'] ?? 0 );
		}
		$image[1] = $width;
		$image[2] = $height;
		return $image;
	},
	11,
	3
);
