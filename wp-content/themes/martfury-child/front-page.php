<?php
/**
 * Home page. The call to action's text is edited here.
 *
 * The same structure as every other page: #content > main#main.site-main (see
 * ultralove_no_site_content_container() in functions.php). Top to bottom:
 *  - the rotating banner (UltraLove Banners, group "home"), 24px below the header's navigation row;
 *  - #topcta: heading, intro and the two main buttons on the aurora backdrop (which UltraLove Banners
 *    extends up behind the banner - #ub-hero-combo-bg). These two have backdrops, so they run full width
 *    (.ul-home-bleed) with their content in a .martfury-container;
 *  - the products (.ul-home-products), at the page width like any page's content: "On Sale" (12 random
 *    on-sale products in the product carousel, js/product-carousel.js) and its "More On-Sale Products"
 *    button, "Latest Adult Products" (the 30 newest), then the two links. Both lists are WooCommerce
 *    [products] shortcodes, so their cards are the shop's.
 *
 * @package Martfury
 */

get_header();
?>
<main id="main" class="site-main">
    <section class="ul-home-bleed ul-home-banner">
		<?php echo do_shortcode( '[ultralove_banner group="home"]' ); ?>
    </section>

    <section id="topcta" class="ul-home-bleed">
        <div class="martfury-container">
            <div id="ctatop">
                <div class="ul-home-cta-content">
                    <h1>Adult Wholesale Canada</h1>
                    <p>Ultra Love supplies Canadian retailers with adult toys and intimate wellness products, competitive wholesale pricing, and dedicated account support.</p>
                    <a class="ul-link ul-link--lg ul-link--boxed" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">View Products</a>
                    <a class="ul-link ul-link--lg ul-link--boxed" href="<?php echo esc_url( home_url( '/become-a-retailer/' ) ); ?>">Wholesale Signup</a>
                </div>
            </div>
        </div>
    </section>

    <div class="ul-home-products">
        <section class="ul-home-section ul-home-sale" aria-labelledby="ul-home-sale-title">
            <div class="cat-header">
                <h2 class="cat-title" id="ul-home-sale-title">On Sale</h2>
            </div>
            <div class="ul-carousel" data-ul-carousel>
				<?php echo do_shortcode( '[products limit="12" columns="5" orderby="rand" on_sale="true"]' ); ?>
            </div>
            <div class="ul-home-links">
                <a class="ul-btn ul-btn--primary ul-btn--lg" href="<?php echo esc_url( home_url( '/product-category/sale-closeout-items/' ) ); ?>">More On-Sale Products</a>
            </div>
        </section>

        <section class="ul-home-section ul-home-latest" aria-labelledby="ul-home-latest-title">
            <div class="cat-header">
                <h2 class="cat-title" id="ul-home-latest-title">Latest Adult Products</h2>
            </div>
			<?php echo do_shortcode( '[products limit="30" columns="5" orderby="date" order="DESC"]' ); ?>
        </section>

        <div class="ul-home-links">
            <a class="ul-link ul-link--lg ul-link--boxed" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">View More New Products</a>
            <a class="ul-link ul-link--lg ul-link--boxed" href="<?php echo esc_url( home_url( '/brands/' ) ); ?>">Browse Products by Brand</a>
        </div>
    </div>
</main><!-- #main -->
<?php
get_footer();
