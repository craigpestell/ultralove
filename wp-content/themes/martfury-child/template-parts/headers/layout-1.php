<?php
// Use the ultralove-banners header only while that plugin is active; otherwise render the parent
// theme's original header (RevSlider banners) unchanged.
if ( ! shortcode_exists( 'ultralove_banner' ) ) {
	include get_template_directory() . '/template-parts/headers/layout-1.php';
	return;
}

?>
<div class="sideslidertopmobile">
    <?php echo do_shortcode('[ultralove_banner group="menu-bar"]'); ?>
</div>
<div class="header-main-row <?php echo martfury_header_container_classes(); ?>">
<div class="header-main">
    <div class="<?php echo martfury_header_container_classes(); ?>">
        <div class="row header-row">
            <div class="header-logo col-lg-3 col-md-3 col-sm-6 col-xs-6">
                <div class="d-logo">
					<?php get_template_part( 'template-parts/logo' ); ?>
                </div>
				<?php if ( intval( martfury_get_option( 'sticky_header' ) ) ) : ?>
                    <div class="d-department">
						<?php martfury_extra_department( false ); ?>
                    </div>
				<?php endif; ?>
            </div>
            <div class="header-extras col-lg-9 col-md-9 col-sm-6 col-xs-6">
				<?php martfury_extra_search(); ?>
                <ul class="extras-menu">
					<?php
					martfury_extra_hotline();
					martfury_extra_compare();
					martfury_extra_wislist();
					martfury_extra_cart();
					martfury_extra_account();
					?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="sideslidertop">
    <?php echo do_shortcode('[ultralove_banner group="menu-bar"]'); ?>
</div>
</div>

<?php
// The navigation row (ultralove_main_menu_row() in functions.php), directly below the logo row on every page.
ultralove_main_menu_row();
?>
<div class="mobile-menu hidden-lg hidden-md">
    <div class="container">
        <div class="mobile-menu-row">
            <a class="mf-toggle-menu" id="mf-toggle-menu" href="#">
                <i class="icon-menu"></i>
            </a>
			<?php martfury_extra_search( false ); ?>
        </div>
    </div>
</div>

