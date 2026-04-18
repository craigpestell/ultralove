<?php
$classes = 'container';
if ( martfury_get_catalog_full_width() ) {
	$classes = 'martfury-container';
}

?>

<div class="page-header page-header-default">
	<div class="page-breadcrumbs">
        <div class="<?php echo esc_attr( $classes ); ?>">
			<?php martfury_get_breadcrumbs(); ?>
		</div>
	</div>
</div>