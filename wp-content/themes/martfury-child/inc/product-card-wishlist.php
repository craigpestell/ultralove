<?php
defined( 'ABSPATH' ) || exit;

add_action( 'martfury_after_product_loop_thumbnail_link', 'ultralove_product_card_wishlist', 90 );
add_action( 'wp_ajax_ultralove_wishlist_state', 'ultralove_wishlist_state' );
add_action( 'wp_ajax_nopriv_ultralove_wishlist_state', 'ultralove_wishlist_state' );

function ultralove_product_card_wishlist() {
	global $product;

	if ( ! $product instanceof WC_Product || ! function_exists( 'YITH_WCWL' ) ) {
		return;
	}

	$label    = get_option( 'yith_wcwl_add_to_wishlist_text', __( 'Add to wishlist', 'yith-woocommerce-wishlist' ) );
	$disabled = ! is_user_logged_in() && 'yes' === get_option( 'yith_wcwl_disable_wishlist_for_unauthenticated_users', 'no' );
	$url      = $disabled
		? add_query_arg( array( 'wishlist_notice' => 'true', 'add_to_wishlist' => $product->get_id() ), wc_get_page_permalink( 'myaccount' ) )
		: wp_nonce_url( add_query_arg( 'add_to_wishlist', $product->get_id(), $product->get_permalink() ), 'add_to_wishlist' );
	$has_bar  = ! empty( martfury_get_option( 'catalog_featured_icons' ) )
		&& ( ! is_customize_preview() || apply_filters( 'martfury_preview_featured_icons', false ) );

	if ( ! $has_bar ) {
		echo '<div class="footer-button">';
	}
	?>
	<div class="yith-wcwl-add-to-wishlist add-to-wishlist-<?php echo esc_attr( $product->get_id() ); ?> yith-wcwl-add-to-wishlist--link-style ultralove-wishlist-heart" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
		<div class="yith-wcwl-add-button">
			<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $disabled ? 'disabled_item' : 'add_to_wishlist single_add_to_wishlist'; ?>"
				data-ul-add-url="<?php echo esc_url( $url ); ?>"
				data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-product-type="<?php echo esc_attr( $product->get_type() ); ?>"
				data-original-product-id="<?php echo esc_attr( $product->get_parent_id() ); ?>"
				data-product-title="<?php echo esc_attr( $product->get_name() ); ?>" data-rel="tooltip"
				title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" rel="nofollow">
				<svg class="yith-wcwl-icon-svg" aria-hidden="true" fill="none" stroke-width="1.5" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"></path></svg>
				<span><?php echo esc_html( $label ); ?></span>
			</a>
		</div>
	</div>
	<?php
	if ( ! $has_bar ) {
		echo '</div>';
	}
}

function ultralove_wishlist_state() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_send_json_error( array( 'message' => 'Use POST to retrieve wishlist state.' ), 405 );
	}
	if ( ! check_ajax_referer( 'load_fragments', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Wishlist verification expired. Please reload the page.' ), 403 );
	}
	if ( ! function_exists( 'yith_wcwl_get_wishlist' ) ) {
		wp_send_json_error( array( 'message' => 'Wishlist is unavailable.' ), 503 );
	}

	// Resolve only the current visitor's default list; never accept an owner or wishlist ID from the browser.
	$wishlist = yith_wcwl_get_wishlist();
	$ids      = array();
	if ( $wishlist ) {
		foreach ( $wishlist->get_items() as $item ) {
			$id = (int) $item->get_product_id();
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
	}

	nocache_headers();
	wp_send_json_success( array( 'product_ids' => array_values( array_unique( $ids ) ) ) );
}
