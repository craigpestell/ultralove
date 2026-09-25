<?php
/**
 * The template for displaying the footer.
 *
 * Contains the closing of the #content div and all content after
 *
 * @package Martfury
 */
?>

<?php do_action( 'martfury_before_site_content_close' ); ?>
</div><!-- #content -->
<?php do_action( 'martfury_before_footer' ) ?>

<div id="hero-footer-combo">
<div id="bottomcta">
<h2>
		Ordering wholesale products has never been easier.</h2>
<div class="ul-cta-buttons"><a href="/shop/"> View Products </a> <a href="/become-a-retailer/">Wholesale Signup </a></div>

</div>
<?php if ( ! function_exists( 'elementor_theme_do_location' ) || ! elementor_theme_do_location( 'footer' ) ) {
	?>
    <footer id="colophon" class="site-footer">
		<?php do_action( 'martfury_footer' ) ?>
    </footer><!-- #colophon -->
	<?php do_action( 'martfury_after_footer' ) ?>
<?php } ?>
</div><!-- #hero-footer-combo -->
<?php // #hero-footer-combo wraps #bottomcta and the site footer in one shared box so the
      // aurora gradient below (see style.css) paints behind both with no seam between them -
      // the same technique used for the home banner + #topcta, but here as a real DOM wrapper
      // (both pieces are plain theme template output, so no JS-measured backdrop is needed). ?>
<?php if ( ! function_exists( 'elementor_theme_do_location' ) || ! elementor_theme_do_location( 'footer' ) ) {
	// The copyright/payments row, moved out of the site footer (see the child
	// template-parts/footers/layout-1.php) so it sits on plain white below the backdrop. Keeps the
	// parent's .footer-layout-1/.footer-bottom classes so its layout styles still apply.
	?>
    <div id="footer-copyright-row" class="footer-layout footer-layout-1">
        <div class="<?php echo martfury_footer_container_classes(); ?>">
            <div class="footer-bottom">
                <div class="row footer-row">
                    <div class="col-footer-copyright col-lg-6 col-md-12 col-sm-12 col-xs-12">
						<?php martfury_footer_copyright(); ?>
                    </div>
                    <div class="col-footer-payments col-lg-6 col-md-12 col-sm-12 col-xs-12">
						<?php martfury_footer_payments(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php } ?>
</div><!-- #page -->
<?php if( is_checkout() ) :?>
	<script>
		jQuery(function() {
			var firstload = false;

			jQuery( document ).ajaxComplete(function() {
				if(firstload) return;
				jQuery('.payment_box').hide();
				jQuery('.wc_payment_method > input').prop('checked', false);
				firstload = true;
			});	
		});
	</script>
<?php endif; ?> 
<?php wp_footer(); ?>
<script>
            document.addEventListener('DOMContentLoaded', function () {
                const table = document.getElementById('exportMe');
                const exportBtn = document.getElementById('export');

                const toCsv = function (table) {
                    // Query all rows
                    const rows = table.querySelectorAll('tr');

                    return [].slice
                        .call(rows)
                        .map(function (row) {
                            // Query all cells
                            const cells = row.querySelectorAll('th,td');
                            return [].slice
                                .call(cells)
                                .map(function (cell) {
                                    return cell.textContent;
                                })
                                .join(',');
                        })
                        .join('\n');
                };

                const download = function (text, fileName) {
                    const link = document.createElement('a');
                    link.setAttribute('href', `data:text/csv;charset=utf-8,${encodeURIComponent(text)}`);
                    link.setAttribute('download', fileName);

                    link.style.display = 'none';
                    document.body.appendChild(link);

                    link.click();

                    document.body.removeChild(link);
                };

                exportBtn.addEventListener('click', function () {
                    // Export to csv
                    const csv = toCsv(table);

                    // Download it
                    download(csv, 'download.csv');
	    });
            });
        </script>

</body>
</html>
