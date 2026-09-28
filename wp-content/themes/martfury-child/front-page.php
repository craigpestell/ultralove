<?php
/**
 * Home page, built in the theme rather than Elementor. The call to action's text is edited here.
 *
 * Sections, top to bottom - all full width (they break out of the page's Bootstrap .container), their
 * content in .ul-home-container, which has the same edges as #bottomcta and the footer (see style.css):
 *  - the rotating banner (UltraLove Banners, group "home") and the navigation row below it (the header
 *    leaves the row out on this page - ultralove_home_nav_below_banner());
 *  - #topcta: heading, intro and the two main buttons on the aurora backdrop (which UltraLove Banners
 *    extends up behind the banner - #ub-hero-combo-bg);
 *  - #carousel: "On Sale" (12 random on-sale products in the product carousel, js/product-carousel.js),
 *    its "More On-Sale Products" button, and "Latest Adult Products" (the 30 newest), with the two links
 *    below. Both lists are WooCommerce [products] shortcodes, so their cards are the shop's.
 *
 * @package Martfury
 */

get_header();
?>
<div id="ul-home" class="ul-home">
    <section class="ul-home-bleed ul-home-banner">
		<?php echo do_shortcode( '[ultralove_banner group="home"]' ); ?>
    </section>

    <div class="ul-home-bleed site-header ul-home-nav">
		<?php ultralove_main_menu_row(); ?>
    </div>

    <section id="topcta" class="ul-home-bleed">
        <div class="ul-home-container">
            <div id="ctatop">
                <div class="ul-home-cta-content">
                    <h1>Adult Wholesale Canada</h1>
                    <p>Ultra Love supplies Canadian retailers with adult toys and intimate wellness products, competitive wholesale pricing, and dedicated account support.</p>
                    <a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">View Products</a>
                    <a href="<?php echo esc_url( home_url( '/become-a-retailer/' ) ); ?>">Wholesale Signup</a>
                </div>
            </div>
        </div>
    </section>

    <section id="carousel" class="ul-home-bleed ul-home-products">
        <div class="ul-home-container">
            <div class="ul-home-section ul-home-sale">
                <div class="cat-header">
                    <h2 class="cat-title">On Sale</h2>
                </div>
                <div class="ul-carousel" data-ul-carousel>
					<?php echo do_shortcode( '[products limit="12" columns="5" orderby="rand" on_sale="true"]' ); ?>
                </div>
                <div class="ul-home-more">
                    <a class="ul-btn ul-btn--primary ul-btn--lg" href="<?php echo esc_url( home_url( '/product-category/sale-closeout-items/' ) ); ?>">More On-Sale Products</a>
                </div>
            </div>

            <div class="ul-home-section ul-home-latest">
                <div class="cat-header">
                    <h2 class="cat-title">Latest Adult Products</h2>
                </div>
				<?php echo do_shortcode( '[products limit="30" columns="5" orderby="date" order="DESC"]' ); ?>
            </div>

            <div class="ul-home-links">
                <a class="ul-btn ul-btn--primary ul-btn--lg" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">View More New Products</a>
                <a class="ul-btn ul-btn--primary ul-btn--lg" href="<?php echo esc_url( home_url( '/brands/' ) ); ?>">Browse Products by Brand</a>
            </div>
        </div>
    </section>
</div>
<?php
get_footer();
