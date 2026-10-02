<?php
/**
 * The fallback template - anything no more specific template matches (Martfury's index.php, in the shared structure).
 *
 * The page structure every template shares (see martfury_open_site_content_container() in the child's functions.php):
 * #content > .martfury-container > main#main.site-main, with the sidebar (where the layout has one) beside
 * <main> - no Bootstrap .row / #primary column wrappers. Martfury's copy wrapped <main> in div#primary.
 *
 * @package Martfury
 */

get_header(); ?>
<main id="main" class="site-main">

	<?php if ( have_posts() ) : ?>

		<div class="row">
			<div class="mf-post-list">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php get_template_part( 'template-parts/content', get_post_format() ); ?>
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
