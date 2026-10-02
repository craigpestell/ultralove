<?php
/**
 * Content wrappers
 *
 * The shop, product categories and brands, product search results and product pages: just <main> - the page's
 * main landmark, in the structure every template shares (#content > .martfury-container > main#main.site-main,
 * the sidebar beside it; see martfury_open_site_content_container() in the child's functions.php). Martfury's wrapper
 * added a div#primary column around it.
 *
 * @author      WooThemes
 * @package     WooCommerce/Templates
 * @version     3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<main id="main" class="site-main">
