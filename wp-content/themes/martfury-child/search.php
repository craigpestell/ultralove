<?php
/**
 * Post search results - the child's copy of Martfury's search.php. (The header search is for products, which
 * WooCommerce's archive shows.)
 *
 * In the page structure every template shares: #content > main#main.site-main, the
 * sidebar (where the layout has one) beside <main> - see ultralove_no_site_content_container() in functions.php.
 *
 * @package Martfury
 */

get_header(); ?>
<main id="main" class="site-main">

	<?php if ( have_posts() ) : ?>

		<div class="row">
			<div class="mf-post-list">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php get_template_part( 'template-parts/content', 'search' ); ?>
				<?php endwhile; ?>
			</div>
		</div>

		<?php martfury_numeric_pagination(); ?>

	<?php else : ?>

		<?php get_template_part( 'template-parts/content', 'none' ); ?>

	<?php endif; ?>

</main><!-- #main -->
<?php get_sidebar(); ?>
<?php get_footer(); ?>
