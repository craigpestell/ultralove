<?php
/**
 * The template part for displaying the main logo on header
 *
 * Child theme override of the Martfury parent theme's template-parts/logo.php: adds width/height
 * to the <img class="site-logo"> so the browser can reserve its box before the image loads
 * (avoids layout shift). The parent theme's own copy is left untouched since it's overwritten on
 * theme updates.
 *
 * The 246x100 below is the current logo file's actual pixel size (ultra-love-logo.png); update it
 * here if that file is ever replaced with one of a different shape.
 *
 * When the redrawn vector logo is in the child theme (images/ultra-love-logo.svg), its <svg> is printed
 * right here in the page instead of an <img>: no extra request, so it paints with the page, and CSS can
 * reach its shapes. Its <title> is swapped for an aria-label (the logo can be printed more than once on a
 * page, and ids must be unique); class="site-logo" keeps every rule written for the logo image working.
 * The purple drop shadow is CSS (style.css, "Header logo"). The sticky header's logo stays an <img>.
 *
 * @package Martfury
 */

$logo_svg      = '';
$logo_svg_file = get_stylesheet_directory() . '/images/ultra-love-logo.svg';
if ( ! is_page_template( 'template-coming-soon-page.php' ) && is_readable( $logo_svg_file ) ) {
	$logo_svg = file_get_contents( $logo_svg_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a theme file, not a remote URL
	$logo_svg = preg_replace( '#<title[^>]*>.*?</title>#s', '', $logo_svg );
	$logo_svg = preg_replace(
		'#<svg\b([^>]*?)\s*role="img"\s*aria-labelledby="[^"]*"#',
		'<svg class="site-logo"$1 role="img" aria-label="' . esc_attr( get_bloginfo( 'name' ) ) . '" focusable="false"',
		$logo_svg,
		1
	);
	$logo_svg = trim( $logo_svg );
}

$logo = '';
$logo = apply_filters( 'martfury_site_logo', martfury_get_option( 'logo' ) );


if ( ! $logo ) {
	$logo = get_template_directory_uri() . '/images/logo/logo.png';
}

if ( is_page_template( 'template-coming-soon-page.php' ) ) {
	$logo = martfury_get_option( 'coming_soon_logo' );

	if ( ! $logo ) {
		return;
	}

	echo '<div class="container">';
}

$logo_sticky_img = '';
if ( intval( martfury_get_option( 'sticky_header' ) ) && intval( martfury_get_option( 'sticky_header_logo' ) ) ) {
	$logo_sticky = martfury_get_option( 'logo_sticky' );
	if ( ! $logo_sticky ) {
		$logo_sticky = $logo;
	}

	$logo_sticky_img = sprintf( '<img class="sticky-logo" alt="%s" src="%s"/>', esc_attr( get_bloginfo( 'name' ) ), esc_url( $logo_sticky ) );
}

?>
    <div class="logo">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( $logo_svg ) : ?>
				<?php echo $logo_svg; // phpcs:ignore WordPress.Security.EscapeOutput -- the theme's own SVG file ?>
			<?php else : ?>
            <img class="site-logo" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                 src="<?php echo esc_url( $logo ); ?>" width="246" height="100"/>
			<?php endif; ?>
			<?php echo wp_kses_post( $logo_sticky_img ); ?>
        </a>
    </div>
<?php
$custom_title_tag = get_post_meta( get_the_ID(), 'site_title_tag_homepage', true );
$custom_title_tag = empty( $custom_title_tag ) ? 'h1' : $custom_title_tag;
$title_tag        = martfury_is_homepage() ? $custom_title_tag : 'p';

printf(
	'<%1$s class="site-title"><a href="%2$s" rel="home">%3$s</a></%1$s>',
	$title_tag,
	esc_url( home_url( '/' ) ),
	get_bloginfo( 'name' )
);
?>
    <h2 class="site-description"><?php bloginfo( 'description' ); ?></h2>

<?php

if ( is_page_template( 'template-coming-soon-page.php' ) ) {
	echo '</div>';
}
