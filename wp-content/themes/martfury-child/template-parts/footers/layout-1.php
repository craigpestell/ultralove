<?php
/**
 * The footer widgets: newsletter, contact info, the widget columns and the quick links. The copyright row is
 * footer.php's, below this on white.
 */
$footer_class = 'footer-' . martfury_get_option('footer_skin');
?>
<div class="footer-layout footer-layout-1 <?php echo esc_attr($footer_class); ?>">
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
</div>
