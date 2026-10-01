<?php
/**
 * Plugin Name: UltraLove Backdrops
 * Description: Pick the background style of the home page's call to action (#topcta) and of the footer box
 *              (#hero-footer-combo - #bottomcta + the site footer, on every page) from a library of CSS, SVG,
 *              animated and dark backdrops, with live previews (Appearance > Backdrops) and an admin-only
 *              preview on the site before saving. Only the background layer changes - plus, on a dark
 *              backdrop, hard edges instead of the theme's white edge fades and light text for what sits
 *              straight on it; the areas' content is the theme's. Also sets the site's display text weight
 *              (regular or bold).
 * Version: 1.2.0
 *
 * @package UltraLove_Backdrops
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ULBD_VERSION', '1.2.0' );
define( 'ULBD_OPTION', 'ulbd_settings' );

require_once __DIR__ . '/includes/styles.php';

/**
 * The two areas: key => label, the elements each one's background goes on, 'dark_css' - printed when the
 * area's style is a dark one: its pills and text boxes as the live site's see-through dark glass (black at
 * .52 / .38) with white text, their purple borders and shadows kept, and white text for anything drawn
 * straight on the backdrop - and 'fade_selector', the theme's white edge-fade overlay, hidden for a style
 * without fades.
 */
function ulbd_areas() {
	return array(
		'topcta' => array(
			'label'         => 'Home page call to action',
			'description'   => 'The "Adult Wholesale Canada" block under the home banner (#topcta).',
			// With UltraLove Banners' combined "aurora" backdrop on, #topcta is transparent and one element behind
			// the banner and #topcta (#ub-hero-combo-bg) carries the backdrop instead - style that one then.
			'selectors'     => array( 'body:not(.ub-hero-combo) #topcta', 'body #ub-hero-combo-bg' ),
			'dark_css'      => 'body #topcta h1 { background: rgba(0, 0, 0, .52); color: #fff !important; }'
				. ' body #topcta p { background: rgba(0, 0, 0, .38); color: #fff !important; }',
			'fade_selector' => '#topcta:before',
		),
		'footer' => array(
			'label'         => 'Footer box',
			'description'   => '"Ordering wholesale products has never been easier." and the site footer below it (#hero-footer-combo), on every page.',
			'selectors'     => array( 'body #hero-footer-combo' ),
			// The quick links (Ordering · About Us · Contact) are underlined pink text in a pill: dark glass, white links.
			'dark_css'      => 'body #bottomcta h2, body .site-footer .footer-widgets .widget-title, #hero-footer-combo #footer-links .widget_nav_menu ul { background: rgba(0, 0, 0, .52); color: #fff !important; }'
				. ' body .site-footer .footer-widgets .textwidget, body .site-footer .footer-widgets .gform_wrapper { background: rgba(0, 0, 0, .38); color: #fff !important; }'
				. ' #hero-footer-combo #footer-links .widget_nav_menu ul li a { color: #fff !important; }'
				. ' #hero-footer-combo #footer-links .widget_nav_menu ul li a:hover, #hero-footer-combo #footer-links .widget_nav_menu ul li a:focus-visible { color: #f4c3d9 !important; }'
				. ' #hero-footer-combo #footer-links .widget_nav_menu ul li + li:before { color: #b98ad6; }',
			'fade_selector' => '#hero-footer-combo:before',
		),
	);
}

/** Intensity presets: key => array( label, factor ). */
function ulbd_intensities() {
	return array(
		'subtle' => array( 'Subtle', .6 ),
		'normal' => array( 'Normal', 1 ),
		'bold'   => array( 'Bold', 1.45 ),
	);
}

/**
 * Display text weights: key => array( label, CSS weight ). The theme's --ul-display-weight (header navigation
 * and Search, pill headings, the #topcta text box, section titles, footer text boxes and copyright) is set
 * to it. The theme draws only two weights of Work Sans - 300 and 500 - so those are the choices.
 */
function ulbd_text_weights() {
	return array(
		'regular' => array( 'Regular', 300 ),
		'bold'    => array( 'Bold', 500 ),
	);
}

/** Saved settings, with defaults (the theme's own aurora at normal intensity everywhere, regular text). */
function ulbd_settings() {
	$saved    = get_option( ULBD_OPTION, array() );
	$settings = array(
		'text_weight' => isset( $saved['text_weight'] ) && isset( ulbd_text_weights()[ $saved['text_weight'] ] ) ? $saved['text_weight'] : 'regular',
	);
	foreach ( array_keys( ulbd_areas() ) as $area ) {
		$settings[ $area ] = array(
			'style'     => isset( $saved[ $area ]['style'] ) && isset( ulbd_styles()[ $saved[ $area ]['style'] ] ) ? $saved[ $area ]['style'] : 'aurora',
			'intensity' => isset( $saved[ $area ]['intensity'] ) && isset( ulbd_intensities()[ $saved[ $area ]['intensity'] ] ) ? $saved[ $area ]['intensity'] : 'normal',
		);
	}

	return $settings;
}

/**
 * Settings in effect for this request: the saved ones, overridden by ?ulbd_<area>=<style>,
 * ?ulbd_<area>_intensity=<preset> and ?ulbd_text=<weight> for users who can manage options (the "Preview on
 * the site" links).
 */
function ulbd_active_settings() {
	$settings = ulbd_settings();
	if ( ! is_admin() && current_user_can( 'manage_options' ) ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preview, admins only.
		if ( isset( $_GET['ulbd_text'] ) && isset( ulbd_text_weights()[ sanitize_key( $_GET['ulbd_text'] ) ] ) ) {
			$settings['text_weight'] = sanitize_key( $_GET['ulbd_text'] );
			$settings['preview']     = true;
		}
		// phpcs:enable
		foreach ( array_keys( ulbd_areas() ) as $area ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preview, admins only.
			if ( isset( $_GET[ 'ulbd_' . $area ] ) && isset( ulbd_styles()[ sanitize_key( $_GET[ 'ulbd_' . $area ] ) ] ) ) {
				$settings[ $area ]['style']   = sanitize_key( $_GET[ 'ulbd_' . $area ] );
				$settings[ $area ]['preview'] = true;
			}
			if ( isset( $_GET[ 'ulbd_' . $area . '_intensity' ] ) && isset( ulbd_intensities()[ sanitize_key( $_GET[ 'ulbd_' . $area . '_intensity' ] ) ] ) ) {
				$settings[ $area ]['intensity'] = sanitize_key( $_GET[ 'ulbd_' . $area . '_intensity' ] );
				$settings[ $area ]['preview']   = true;
			}
			// phpcs:enable
		}
	}

	return $settings;
}

/** A style's CSS declarations (background, size, ...) at an intensity, plus its @keyframes. */
function ulbd_style_css( $style_id, $intensity ) {
	$styles = ulbd_styles();
	if ( ! isset( $styles[ $style_id ] ) ) {
		return array( '', '' );
	}
	$k   = ulbd_intensities()[ $intensity ][1];
	$css = call_user_func( $styles[ $style_id ]['css'], $k );

	$decl = 'background: ' . $css['background'] . ';';
	foreach ( array( 'size', 'position', 'repeat' ) as $prop ) {
		if ( ! empty( $css[ $prop ] ) ) {
			$decl .= ' background-' . $prop . ': ' . $css[ $prop ] . ';';
		}
	}
	$keyframes = '';
	if ( ! empty( $css['animation'] ) ) {
		$decl     .= ' animation: ' . $css['animation'] . ';';
		$keyframes = '@keyframes ulbd-' . $style_id . ' { ' . $css['keyframes'] . ' }';
	}

	return array( $decl, $keyframes );
}

/**
 * Front end: the chosen backgrounds, in the <head> after the theme's stylesheet. Nothing is printed for an
 * area left on the default (the theme's own aurora at normal intensity), so the theme stays the baseline.
 */
add_action(
	'wp_head',
	function () {
		$out       = array();
		$keyframes = array();
		$animated  = array();
		$active    = ulbd_active_settings();
		$preview   = ! empty( $active['preview'] );
		if ( 'regular' !== $active['text_weight'] ) {
			$out[] = ':root { --ul-display-weight: ' . ulbd_text_weights()[ $active['text_weight'] ][1] . '; }';
		}
		foreach ( ulbd_areas() as $area => $info ) {
			$s       = $active[ $area ];
			$preview = $preview || ! empty( $s['preview'] );
			if ( 'aurora' === $s['style'] && 'normal' === $s['intensity'] ) {
				continue;
			}
			list( $decl, $kf ) = ulbd_style_css( $s['style'], $s['intensity'] );
			$selectors         = implode( ', ', $info['selectors'] );
			$out[]             = $selectors . ' { ' . $decl . ' }';
			$style = ulbd_styles()[ $s['style'] ];
			if ( ! empty( $style['dark'] ) && $info['dark_css'] ) {
				$out[] = $info['dark_css'];
			}
			if ( isset( $style['fade'] ) && ! $style['fade'] ) {
				$out[] = $info['fade_selector'] . ' { display: none; }';
			}
			if ( $kf ) {
				$keyframes[ $s['style'] ] = $kf;
				$animated[]               = $selectors;
			}
		}
		if ( $animated ) {
			$out[] = '@media (prefers-reduced-motion: reduce) { ' . implode( ', ', $animated ) . ' { animation: none; } }'; // Same selectors, later: wins without !important.
		}
		if ( $out ) {
			echo "<style id=\"ulbd-backdrops\">\n" . implode( "\n", array_merge( array_values( $keyframes ), $out ) ) . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from the style library.
		}
		if ( $preview ) {
			echo '<style>#ulbd-preview-badge{position:fixed;left:16px;bottom:16px;z-index:99999;padding:8px 14px;border-radius:999px;background:#3b1d4a;color:#fff;font:500 13px/1.3 sans-serif;box-shadow:0 4px 14px rgba(59,29,74,.35)}#ulbd-preview-badge a{color:#fff;text-decoration:underline;margin-left:8px}</style>';
			add_action(
				'wp_footer',
				function () {
					printf(
						'<div id="ulbd-preview-badge">Preview (not saved)<a href="%s">Back to settings</a></div>',
						esc_url( admin_url( 'themes.php?page=ulbd-backdrops' ) )
					);
				}
			);
		}
	},
	100
);

/*
 * Admin: Appearance > Backdrops.
 */
add_action(
	'admin_menu',
	function () {
		add_theme_page( 'Backdrops', 'Backdrops', 'manage_options', 'ulbd-backdrops', 'ulbd_render_admin' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'ulbd',
			ULBD_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'ulbd_sanitize',
				'default'           => array(),
			)
		);
	}
);

/** Keep only known areas, styles, intensities and text weights. */
function ulbd_sanitize( $input ) {
	$weight = isset( $input['text_weight'] ) ? sanitize_key( $input['text_weight'] ) : 'regular';
	$clean  = array(
		'text_weight' => isset( ulbd_text_weights()[ $weight ] ) ? $weight : 'regular',
	);
	foreach ( array_keys( ulbd_areas() ) as $area ) {
		$style     = isset( $input[ $area ]['style'] ) ? sanitize_key( $input[ $area ]['style'] ) : 'aurora';
		$intensity = isset( $input[ $area ]['intensity'] ) ? sanitize_key( $input[ $area ]['intensity'] ) : 'normal';

		$clean[ $area ] = array(
			'style'     => isset( ulbd_styles()[ $style ] ) ? $style : 'aurora',
			'intensity' => isset( ulbd_intensities()[ $intensity ] ) ? $intensity : 'normal',
		);
	}

	return $clean;
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'appearance_page_ulbd-backdrops' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ulbd-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), ULBD_VERSION );
		wp_enqueue_script( 'ulbd-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), ULBD_VERSION, true );
		wp_localize_script(
			'ulbd-admin',
			'ulbdAdmin',
			array(
				'pages' => array(
					'topcta' => home_url( '/' ),
					'footer' => home_url( '/' ),
					'text'   => home_url( '/' ),
				),
			)
		);
	}
);

/** The settings page: per area, a grid of live preview tiles to pick from, an intensity, and a preview link. */
function ulbd_render_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = ulbd_settings();
	$kinds    = array(
		'css'      => 'CSS',
		'svg'      => 'SVG',
		'animated' => 'Animated',
		'dark'     => 'Dark',
		'seasonal' => 'Seasonal',
		'plain'    => 'Plain',
	);

	// Every style's tile at every intensity, as classes the intensity buttons switch between.
	$tile_css  = array();
	$keyframes = array();
	foreach ( ulbd_styles() as $id => $style ) {
		if ( isset( $style['fade'] ) && ! $style['fade'] ) {
			$tile_css[] = '.ulbd-tile--' . $id . ' .ulbd-swatch__fade { display: none; }';
		}
		foreach ( array_keys( ulbd_intensities() ) as $intensity ) {
			list( $decl, $kf ) = ulbd_style_css( $id, $intensity );
			$tile_css[]        = '.ulbd-picker[data-intensity="' . $intensity . '"] .ulbd-tile--' . $id . ' .ulbd-swatch { ' . $decl . ' }';
			if ( $kf ) {
				$keyframes[ $id ] = $kf;
			}
		}
	}
	?>
	<div class="wrap ulbd">
		<h1>Backdrops</h1>
		<p class="ulbd-intro">The background behind the home page's call to action and behind the footer box, and the weight of
			the site's display text. Pick a style and an intensity for each area, use <strong>Preview on the site</strong> to see
			the combination on the home page (only you see it, nothing is saved), then <strong>Save</strong>. The white fades at
			the edges and everything in front of the backdrop stay as they are, except on a dark style: like the live site's
			backdrop it has hard edges, the pills and text boxes turn to see-through dark glass with white text, and the
			footer's quick links turn white. Animated styles stand still for visitors who ask their device for reduced motion.</p>

		<style><?php echo implode( "\n", array_merge( array_values( $keyframes ), $tile_css ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from the style library. ?></style>

		<form method="post" action="options.php">
			<?php settings_fields( 'ulbd' ); ?>

			<section class="ulbd-picker ulbd-text" data-area="text">
				<header class="ulbd-picker__header">
					<div>
						<h2>Text weight</h2>
						<p>The header navigation, Log in / Register and the Search button; the "Adult Wholesale Canada" heading and the
							text box under it; the product section titles (On Sale, Latest Adult Products, the carousels); and the
							footer's headings, text boxes and copyright line. Other buttons are always bold.</p>
					</div>
					<fieldset class="ulbd-intensity">
						<legend>Weight</legend>
						<?php foreach ( ulbd_text_weights() as $key => $weight ) : ?>
							<label>
								<input type="radio" name="<?php echo esc_attr( ULBD_OPTION . '[text_weight]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['text_weight'], $key ); ?>>
								<span style="font-weight: <?php echo (int) $weight[1]; ?>"><?php echo esc_html( $weight[0] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</header>
				<p class="ulbd-actions">
					<a class="button ulbd-preview-link" target="_blank" rel="noopener" href="<?php echo esc_url( home_url( '/' ) ); ?>">Preview on the site</a>
				</p>
			</section>

			<?php foreach ( ulbd_areas() as $area => $info ) : ?>
				<section class="ulbd-picker" data-area="<?php echo esc_attr( $area ); ?>" data-intensity="<?php echo esc_attr( $settings[ $area ]['intensity'] ); ?>">
					<header class="ulbd-picker__header">
						<div>
							<h2><?php echo esc_html( $info['label'] ); ?></h2>
							<p><?php echo esc_html( $info['description'] ); ?></p>
						</div>
						<fieldset class="ulbd-intensity">
							<legend>Intensity</legend>
							<?php foreach ( ulbd_intensities() as $key => $preset ) : ?>
								<label>
									<input type="radio" name="<?php echo esc_attr( ULBD_OPTION . '[' . $area . '][intensity]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings[ $area ]['intensity'], $key ); ?>>
									<span><?php echo esc_html( $preset[0] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</header>

					<?php foreach ( $kinds as $kind => $kind_label ) : ?>
						<h3 class="ulbd-kind"><?php echo esc_html( $kind_label ); ?></h3>
						<div class="ulbd-grid">
							<?php
							foreach ( ulbd_styles() as $id => $style ) :
								if ( $style['kind'] !== $kind ) {
									continue;
								}
								?>
								<label class="ulbd-tile ulbd-tile--<?php echo esc_attr( $id ); ?>">
									<input type="radio" name="<?php echo esc_attr( ULBD_OPTION . '[' . $area . '][style]' ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $settings[ $area ]['style'], $id ); ?>>
									<span class="ulbd-swatch"><span class="ulbd-swatch__fade"></span><span class="ulbd-swatch__pill">Aa</span></span>
									<span class="ulbd-tile__label"><?php echo esc_html( $style['label'] ); ?></span>
									<span class="ulbd-tile__desc"><?php echo esc_html( $style['description'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>

					<p class="ulbd-actions">
						<a class="button ulbd-preview-link" target="_blank" rel="noopener" href="<?php echo esc_url( home_url( '/' ) ); ?>">Preview on the site</a>
					</p>
				</section>
			<?php endforeach; ?>

			<?php submit_button( 'Save backdrops' ); ?>
		</form>
	</div>
	<?php
}
