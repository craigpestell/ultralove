<?php
/**
 * Single posts - the child's copy of Martfury's single.php.
 *
 * In the page structure every template shares: #content > main#main.site-main, the
 * sidebar (where the layout has one) beside <main> - see ultralove_no_site_content_container() in functions.php.
 *
 * @package Martfury
 */

get_header(); ?>
<main id="main" class="site-main">

	<?php while ( have_posts() ) : the_post(); ?>

		<?php get_template_part( 'template-parts/content', 'single' ); ?>

		<?php martfury_author_box(); ?>
		<?php martfury_related_posts(); ?>
		<?php martfury_post_nav(); ?>

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
