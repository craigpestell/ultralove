<?php
$page_header = martfury_get_page_header();
$classes = 'container';
if ( is_page_template( 'template-large-container.php' ) ) {
	$classes = 'martfury-container';
}
$classes = apply_filters('martfury_page_header_container_class', $classes);
?>

<div class="page-header page-header-page">
    <div class="page-breadcrumbs">
        <div class="<?php echo esc_attr( $classes ); ?>">
			<?php martfury_get_breadcrumbs(); ?>
        </div>
    </div>
	<?php
    if ( in_array( 'title', $page_header ) ) {
		the_title( '<h1 class="entry-title">', '</h1>' );
	}
	?>
</div>