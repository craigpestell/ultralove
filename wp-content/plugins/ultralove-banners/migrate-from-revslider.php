<?php
/**
 * One-time migration: creates ub_banner_slide posts from the existing RevSlider slide data
 * (image, link, order) for the three sliders currently in use (the two homepage ones, left and right,
 * become one 'home' group that shows two slides at a time; slides alternate left/right) on the homepage/header.
 *
 * Run on PRODUCTION after activating the ultralove-banners plugin, via:
 *   sudo -u www-data wp eval-file migrate-from-revslider.php
 *
 * Links are the production URLs exactly as RevSlider has them (checked against the RevSlider tables).
 * Where a link is a single post (a product page), the slide is also tied to that post's ID, so the
 * link follows the post; search-result and brand-page links stay as plain URLs. Refuses to run if
 * slides already exist, so it can't create duplicates.
 */

if ( get_posts( array( 'post_type' => 'ub_banner_slide', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) ) {
	echo "Banner slides already exist - nothing done.\n";
	return;
}


$slides = array(
	// group, order, attachment_id, link, title
	array( 'home', 1, 95886, 'https://ultralove.ca/product-brand/orgie/', 'Orgie Promo Banner' ),
	array( 'home', 2, 96472, 'https://ultralove.ca/?s=r3276%2Cr3275&post_type=product', 'R3275 Large Banner' ),
	array( 'home', 3, 93884, 'https://ultralove.ca/?s=we86&post_type=product', 'WE8600 Banner' ),
	array( 'home', 4, 90667, 'https://ultralove.ca/product/p14066-15-ml-man-lust/', 'P14066 Large' ),
	array( 'home', 5, 88434, 'https://ultralove.ca/?s=st45&post_type=product', 'Mastur Banner' ),
	array( 'home', 6, 86523, 'https://ultralove.ca/product-brand/odaya/', 'Odaya Banner' ),
	array( 'home', 7, 91327, 'https://ultralove.ca/?s=w6262&post_type=product', 'W626200 Large' ),
	array( 'home', 8, 83208, 'https://ultralove.ca/?s=sh380&post_type=product', 'Waves of Pleasure' ),
	array( 'home', 9, 91324, 'https://ultralove.ca/product/r2078-romp-rose-glow-discounts-do-not-apply/', 'R2078 Large Banner' ),
	array( 'home', 10, 83206, 'https://ultralove.ca/product/sh6441-5-5-oz-toko-lube-raspberry-feeling/', 'Toko Banner' ),
	array( 'home', 11, 91325, 'https://ultralove.ca/?s=ss980-&post_type=product', 'SS980 Edge Large' ),

	array( 'menu-bar', 1, 96289, 'https://ultralove.ca/?s=r3276%2Cr3275&post_type=product', 'R3275 Small' ),
	array( 'menu-bar', 2, 90666, 'https://ultralove.ca/product/p14066-15-ml-man-lust/', 'P14066 Small' ),
	array( 'menu-bar', 3, 89621, 'https://ultralove.ca/product/r3260-romp-jiggle-discounts-do-not-apply/', 'Jiggle Small' ),
	array( 'menu-bar', 4, 82936, 'https://ultralove.ca/?s=we84&post_type=product', 'WE8450 Small' ),
	array( 'menu-bar', 5, 83289, 'https://ultralove.ca/product/or17960-15-ml-oral-ease/', 'Long Banner' ),
	array( 'menu-bar', 6, 91326, 'https://ultralove.ca/?s=w6262&post_type=product', 'W62600 Small' ),
);

$created = 0;
foreach ( $slides as $slide ) {
	list( $group, $order, $attachment_id, $link, $title ) = $slide;

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'ub_banner_slide',
			'post_status' => 'publish',
			'post_title'  => $title,
			'menu_order'  => $order,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		echo "FAILED: {$title} - " . $post_id->get_error_message() . "\n";
		continue;
	}

	update_post_meta( $post_id, '_ub_group', $group );
	update_post_meta( $post_id, '_ub_link', $link );
	set_post_thumbnail( $post_id, $attachment_id );

	// A link to a single post (not a search or brand page) is stored as that post's ID.
	if ( false === strpos( $link, '?' ) ) {
		$target = url_to_postid( $link );
		if ( $target && (int) get_option( 'page_on_front' ) !== $target && 'publish' === get_post_status( $target ) ) {
			update_post_meta( $post_id, '_ub_post', $target );
		}
	}

	// Long Banner is 1920x449, not the header's 728:90 shape: start it with a centered 1920x237 crop
	// (adjust it in the slide's Edit screen).
	if ( 'menu-bar' === $group && 83289 === $attachment_id ) {
		update_post_meta( $post_id, '_ub_crop', '83289,0,106,1920,237' );
	}

	echo "Created #{$post_id}: {$title} (group={$group}, order={$order}, attachment={$attachment_id})\n";
	$created++;
}

echo "\nDone. Created {$created} of " . count( $slides ) . " slides.\n";
