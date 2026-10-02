<?php
/**
 * The 404 page's content - the child's copy of Martfury's template part, with <main> straight in the page
 * container like every page (see martfury_open_site_content_container() in functions.php).
 *
 * @package Martfury
 */

$img = martfury_get_option( 'not_found_img' );

if ( ! $img ) {
	$img = get_template_directory_uri() . '/images/bg-404.jpg';
}

?>

<main id="main" class="site-main">

		<section class="error-404 not-found">
			<div class="page-content">
				<?php printf( '<img src="%s" alt="%s">', esc_url($img), esc_attr__( 'ohh! page not found', 'martfury' )); ?>

				<h3 class="page-title"><?php esc_html_e( 'ohh! page not found', 'martfury' ); ?></h3>

				<p>
					<?php esc_html_e( "It seems we can't find what you're looking for. Perhaps searching can help or go back to ", 'martfury' ); ?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Homepage', 'martfury' ); ?></a>
				</p>

				<?php get_search_form(); ?>

			</div>
			<!-- .page-content -->
		</section>
		<!-- .error-404 -->

</main><!-- #main -->
