<?php
/**
 * The end of every page: closes #content, then the regions below it (related products, recently viewed - hooked
 * to martfury_before_footer) and the site footer: the call to action (#bottomcta), the footer widgets
 * (martfury_footer - template-parts/footers/layout-1.php) and the copyright row. The footer carries the backdrop
 * (UltraLove Backdrops, area "footer"); the copyright row sits on white below it.
 *
 * @package Martfury
 */
?>

<?php do_action( 'martfury_before_site_content_close' ); ?>
</div><!-- #content -->
<?php do_action( 'martfury_before_footer' ); ?>

<footer id="colophon" class="site-footer">
	<div id="bottomcta">
		<h2>Ordering wholesale products has never been easier.</h2>
		<div class="ul-cta-buttons">
			<a class="ul-link ul-link--lg ul-link--boxed" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">View Products</a>
			<a class="ul-link ul-link--lg ul-link--boxed" href="<?php echo esc_url( home_url( '/become-a-retailer/' ) ); ?>">Wholesale Signup</a>
		</div>
	</div>

	<?php do_action( 'martfury_footer' ); ?>

	<div class="footer-bottom">
		<div class="footer-copyright">Copyright &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
		<?php martfury_footer_payments(); ?>
	</div>
</footer><!-- #colophon -->
<?php do_action( 'martfury_after_footer' ); ?>
</div><!-- #page -->
<?php wp_footer(); ?>

</body>
</html>
