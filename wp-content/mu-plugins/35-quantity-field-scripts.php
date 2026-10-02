<?php
/**
 * Plugin Name: Quantity Field Scripts (wc_enqueue_js replacement)
 * Description: The "Quantity Field on Shop Page" plugin (1.4.2) queues its JavaScript with wc_enqueue_js(),
 *              deprecated since WooCommerce 10.4, on every request including admin-ajax and REST, which logged
 *              a deprecation notice each time. This unhooks those two handlers and adds the same JavaScript with
 *              wp_add_inline_script() on front-end pages only. It starts with `jQuery(`, so 32-defer-jquery.php
 *              can wrap it when jQuery is deferred.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		if ( ! class_exists( 'woa_wqfsp' ) ) {
			return;
		}
		$plugin = woa_wqfsp::instance();
		remove_action( 'init', array( $plugin, 'woa_quantity_handler' ) );
		remove_action( 'init', array( $plugin, 'woa_confirm_add' ) );

		add_action(
			'wp_enqueue_scripts',
			function () {
				wp_add_inline_script(
					'jquery-core',
					'jQuery(function ($) {
	// Keep the add-to-cart button\'s quantity in step with the quantity field.
	$("form.cart").on("change", "input.qty", function () {
		$(this.form).find("[data-quantity]").attr("data-quantity", this.value);
	});
	$(document.body).on("adding_to_cart", function () {
		$("a.added_to_cart").remove();
	});
	$(document.body).on("added_to_cart", function () {
		$(".added_to_cart").after("<p class=\'confirm_add\'>Item Added</p>");
	});
});'
				);
			}
		);
	},
	0
);
