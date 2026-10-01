<?php
/**
 * Content wrappers
 *
 * Martfury's wrapper (shop, product categories and brands, search results, product pages) plus a <main> - the
 * page's main landmark for screen readers, as in Martfury's page.php. Without it these pages had none.
 *
 * @author      WooThemes
 * @package     WooCommerce/Templates
 * @version     3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<div id="primary" class="content-area <?php martfury_content_columns(); ?>">
	<main id="main" class="site-main">
