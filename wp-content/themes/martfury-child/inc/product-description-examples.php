<?php
/** Shared storefront styles and product editor examples. */
function ultralove_enqueue_product_description_styles(): void {
	$relative = '/css/product-descriptions.css';
	wp_enqueue_style(
		'ultralove-product-descriptions',
		get_stylesheet_directory_uri() . $relative,
		array(),
		(string) filemtime( get_stylesheet_directory() . $relative )
	);
}
add_action( 'wp_enqueue_scripts', 'ultralove_enqueue_product_description_styles', 30 );

add_action( 'admin_enqueue_scripts', 'ultralove_product_description_example_styles' );
function ultralove_product_description_example_styles(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || 'product' !== $screen->post_type ) {
		return;
	}
	ultralove_enqueue_product_description_styles();
	wp_add_inline_style(
		'ultralove-product-descriptions',
		'#ul-product-description-examples pre{white-space:pre-wrap;overflow-wrap:anywhere;padding:12px;background:#f6f7f7;border:1px solid #dcdcde}
		#ul-product-description-examples .ul-description-example-preview{font-size:16px;line-height:1.6;padding:12px;border:1px solid #dcdcde;background:#fff;color:#333}
		#ul-product-description-examples section+section{margin-top:24px}
		#ul-product-description-examples h3{margin-bottom:4px}'
	);
}

add_action( 'add_meta_boxes_product', 'ultralove_product_description_examples_box' );
function ultralove_product_description_examples_box(): void {
	add_meta_box(
		'ul-product-description-examples',
		'Product description style examples',
		'ultralove_render_product_description_examples',
		'product',
		'normal',
		'low'
	);
}

function ultralove_render_product_description_examples(): void {
	$examples = array(
		array(
			'label' => 'Callout',
			'description' => 'Normal-sized, bold accent text for marketing emphasis.',
			'class' => 'ul-product-callout',
			'text' => 'The Essential Clitoral Stimulator',
		),
		array(
			'label' => 'Lead callout',
			'description' => 'A larger introductory callout. Use the lead modifier together with the base callout class.',
			'class' => 'ul-product-callout ul-product-callout--lead',
			'text' => 'Incredibly Personal',
		),
		array(
			'label' => 'Product notice',
			'description' => 'An underlined product caveat that shoppers should see in the description.',
			'class' => 'ul-product-notice',
			'text' => 'SMART PHONE NOT INCLUDED',
		),
	);
	echo '<p>Copy a snippet into the Text/HTML view of the product description or short description. These are examples only; this panel does not change or insert product content.</p>';
	echo '<p>Store limits, free-tester offers and discount conditions belong in the Retailer info or Important retailer info fields, not in a product notice.</p>';
	foreach ( $examples as $example ) {
		$snippet = '<span class="' . esc_attr( $example['class'] ) . '">' . esc_html( $example['text'] ) . '</span>';
		echo '<section><h3>' . esc_html( $example['label'] ) . '</h3><p>' . esc_html( $example['description'] ) . '</p>';
		echo '<p><strong>HTML</strong></p><pre><code>' . esc_html( $snippet ) . '</code></pre>';
		echo '<p><strong>Rendered preview</strong></p><div class="ul-description-example-preview">' . $snippet . '</div></section>';
	}
}
