<?php
/**
 * Related Products
 *
 * Martfury's template (woocommerce/single-product/related.php), with the product list in the child
 * theme's scrollable carousel (.ul-carousel - js/product-carousel.js) instead of Martfury's Slick
 * carousel. The section drops the "products" class: Martfury's scripts.js starts Slick on
 * ".related.products ul.products", while its styles (and the child theme's) key off section.related.
 *
 * @see           https://docs.woocommerce.com/document/template-structure/
 * @package       WooCommerce/Templates
 * @version       3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$related_title   = martfury_get_option( 'product_related_title' );
$container_class = 'martfury-container'; // the site-wide page container (see functions.php)
if ( $related_products ) : ?>

	<section class="related ul-product-section">
		<div class="<?php echo esc_attr( $container_class ); ?>">
			<div class="related-content">
				<h2 class="related-title" id="ul-related-title"><?php echo esc_html( $related_title ); ?></h2>

				<div class="ul-carousel" data-ul-carousel aria-labelledby="ul-related-title">
					<?php woocommerce_product_loop_start(); ?>

					<?php foreach ( $related_products as $related_product ) : ?>

						<?php
						$post_object = get_post( $related_product->get_id() );

						setup_postdata( $GLOBALS['post'] =& $post_object );

						wc_get_template_part( 'content', 'product' ); ?>

					<?php endforeach; ?>

					<?php woocommerce_product_loop_end(); ?>
				</div>
			</div>
		</div>
	</section>

<?php endif;

wp_reset_postdata();
