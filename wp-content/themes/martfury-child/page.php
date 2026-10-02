<?php
/**
 * Pages (Martfury's page.php, in the shared structure).
 *
 * The page structure every template shares (see martfury_open_site_content_container() in the child's functions.php):
 * #content > .martfury-container > main#main.site-main, with the sidebar (where the layout has one) beside
 * <main> - no Bootstrap .row / #primary column wrappers. Martfury's copy wrapped <main> in div#primary.
 *
 * @package Martfury
 */

get_header(); ?>
<main id="main" class="site-main">

	<?php while ( have_posts() ) : the_post(); ?>

		<?php get_template_part( 'template-parts/content', 'page' ); ?>

		<?php
		// If comments are open or we have at least one comment, load up the comment template.
		if ( comments_open() || get_comments_number() ) :
			comments_template();
		endif;
		?>

	<?php endwhile; ?>

</main><!-- #main -->
<?php get_sidebar(); ?>
<?php get_footer(); ?>
