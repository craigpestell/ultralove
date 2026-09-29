<?php
/**
 * Child override of the parent's footer layout 1: identical except the .footer-bottom copyright row
 * is left out here. The child footer.php renders that row itself, after #hero-footer-combo, so it
 * sits on the plain white page instead of inside the shared aurora backdrop.
 */
$footer_class = 'footer-' . martfury_get_option('footer_skin');
?>
<nav class="footer-layout footer-layout-1 <?php echo esc_attr($footer_class); ?>">
	<?php martfury_footer_newsletter(); ?>
	<div class="<?php echo martfury_footer_container_classes(); ?>">
		<div class="footer-content">
			<?php
			martfury_footer_info();
			martfury_footer_widgets();
			martfury_footer_links();
			?>
		</div>
	</div>
</nav>
