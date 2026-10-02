<?php
/**
 * Single Product Up-Sells
 *
 * Martfury's template (woocommerce/single-product/up-sells.php), with the product list in the child
 * theme's scrollable carousel (.ul-carousel - js/product-carousel.js) instead of Martfury's Slick
 * carousel. The section is classed "related upsells" rather than "up-sells upsells products":
 * Martfury's scripts.js starts Slick on ".up-sells ul.products", and its styles for the upsells
 * section are the same rules as for section.related.
 *
 * @see           https://docs.woocommerce.com/document/template-structure/
 * @package       WooCommerce/Templates
 * @version       3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$upsell_title = martfury_get_option( 'product_upsells_title' );

if ( $upsells ) : ?>

	<section class="related upsells ul-product-section">
		<h2 class="related-title" id="ul-upsells-title"><?php echo esc_html( $upsell_title ); ?></h2>

		<div class="ul-carousel" data-ul-carousel aria-labelledby="ul-upsells-title">
			<?php woocommerce_product_loop_start(); ?>

			<?php foreach ( $upsells as $upsell ) : ?>

				<?php
				$post_object = get_post( $upsell->get_id() );

				setup_postdata( $GLOBALS['post'] =& $post_object );

				wc_get_template_part( 'content', 'product' ); ?>

			<?php endforeach; ?>

			<?php woocommerce_product_loop_end(); ?>
		</div>
	</section>

<?php endif;

wp_reset_postdata();
