<?php
global $martfury_woocommerce;
$show_title     = true;
$container_class = 'container';
if ( ! empty( $martfury_woocommerce ) && method_exists( $martfury_woocommerce, 'get_catalog_elements' ) ) {
	$elements = $martfury_woocommerce->get_catalog_elements();
	if ( empty( $elements ) || ! in_array( 'title', $elements ) ) {
		$show_title = false;
	}

}

$show_title =  apply_filters( 'martfury_catalog_page_title', $show_title );

$container_class = apply_filters( 'martfury_catalog_page_header_container', 'container' );

?>

<div class="page-header page-header-catalog">
	<?php if ( $show_title ) { ?>
        <div class="page-title">
            <div class="<?php echo esc_attr( $container_class ); ?>">
				<?php the_archive_title( '<h1 class="entry-title">', '</h1>' ); ?>
            </div>
        </div>
	<?php } ?>
	<?php if ( ! empty( $elements ) && in_array( 'breadcrumb', $elements ) ) : ?>
        <div class="page-breadcrumbs">
            <div class="<?php echo esc_attr( $container_class ); ?>">
				<?php martfury_get_breadcrumbs(); ?>
            </div>
        </div>
	<?php endif; ?>
</div>