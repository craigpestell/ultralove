<?php
/**
 * Plugin Name: UltraLove Backdrops
 * Description: Pick the background style of the home page's call to action (#topcta) and of the footer box
 *              (#hero-footer-combo - #bottomcta + the site footer, on every page) from a library of CSS, SVG,
 *              animated and dark backdrops, with live previews (Appearance > Backdrops) and an admin-only
 *              preview on the site before saving. Only the background layer changes - plus, on a dark
 *              backdrop, light text for what sits straight on it; the areas' content is the theme's. A pattern size slider scales the
 *              tiled SVG motifs (bats, hearts, leaves...) from half to three times their size. Also sets the site's display text weight
 *              (regular or bold).
 * Version: 1.7.0
 *
 * @package UltraLove_Backdrops
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ULBD_VERSION', '1.7.0' );
define( 'ULBD_OPTION', 'ulbd_settings' );

require_once __DIR__ . '/includes/styles.php';

/**
 * The two areas: key => label, the elements each one's background goes on, 'dark_css' - printed when the
 * area's style is a dark one: its pills and text boxes as the live site's see-through dark glass (black at
 * .52 / .38) with white text, their purple borders and shadows kept, and white text for anything drawn
 * straight on the backdrop. 'pills' and 'boxes' are the area's pills (headings, links) and text boxes, whose
 * see-through backgrounds the text box opacity setting sets.
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
			'pills'         => array( 'body #topcta h1' ),
			'boxes'         => array( 'body #topcta p' ),
		),
		'footer' => array(
			'label'         => 'Footer box',
			'description'   => '"Ordering wholesale products has never been easier." and the site footer below it (#hero-footer-combo), on every page.',
			'selectors'     => array( 'body #hero-footer-combo' ),
			// The quick links (Ordering · About Us · Contact) are underlined pink text in pills: dark glass, white links.
			'dark_css'      => 'body #bottomcta h2, body .site-footer .footer-widgets .widget-title, #hero-footer-combo #footer-links .widget_nav_menu ul li a { background: rgba(0, 0, 0, .52); color: #fff !important; }'
				. ' body .site-footer .footer-widgets .textwidget, body .site-footer .footer-widgets .gform_wrapper { background: rgba(0, 0, 0, .38); color: #fff !important; }'
				. ' #hero-footer-combo #footer-links .widget_nav_menu ul li a { color: #fff !important; }'
				. ' #hero-footer-combo #footer-links .widget_nav_menu ul li a:hover, #hero-footer-combo #footer-links .widget_nav_menu ul li a:focus-visible { color: #f4c3d9 !important; }',
			'pills'         => array( 'body #bottomcta h2', 'body .site-footer .footer-widgets .widget-title', '#hero-footer-combo #footer-links .widget_nav_menu ul li a' ),
			'boxes'         => array( 'body .site-footer .footer-widgets .textwidget', 'body .site-footer .footer-widgets .gform_wrapper' ),
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

/** The pattern size slider: min, max and step, in percent of the styles' own tile sizes. */
function ulbd_scale_range() {
	return array( 50, 300, 10 );
}

/**
 * The text box opacity slider: min, max and step of the pills' and text boxes' actual opacity, in percent (100 is
 * solid). Left unset, an area keeps the standard glass - white at .8 (pills) / .7 (boxes) on light backdrops,
 * black at .52 / .38 on dark ones - which the slider starts from at 80.
 */
function ulbd_boxes_range() {
	return array( 0, 100, 5 );
}

/** A slider value in percent, clamped to $range and snapped to its step (100 when not a number). */
function ulbd_clean_percent( $value, $range ) {
	list( $min, $max, $step ) = $range;
	if ( ! is_numeric( $value ) ) {
		return 100;
	}

	return (int) max( $min, min( $max, round( $value / $step ) * $step ) );
}

/** A pattern size in percent, clamped to the slider's range and snapped to its step (100 when not a number). */
function ulbd_clean_scale( $value ) {
	return ulbd_clean_percent( $value, ulbd_scale_range() );
}

/** A text box opacity in percent, clamped to the slider's range and snapped to its step - or null (standard) when not a number. */
function ulbd_clean_boxes( $value ) {
	return is_numeric( $value ) ? ulbd_clean_percent( $value, ulbd_boxes_range() ) : null;
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
		'linked'      => ! empty( $saved['linked'] ),
		'text_weight' => isset( $saved['text_weight'] ) && isset( ulbd_text_weights()[ $saved['text_weight'] ] ) ? $saved['text_weight'] : 'regular',
	);
	foreach ( array_keys( ulbd_areas() ) as $area ) {
		$settings[ $area ] = array(
			'style'     => isset( $saved[ $area ]['style'] ) && isset( ulbd_styles()[ $saved[ $area ]['style'] ] ) ? $saved[ $area ]['style'] : 'aurora',
			'intensity' => isset( $saved[ $area ]['intensity'] ) && isset( ulbd_intensities()[ $saved[ $area ]['intensity'] ] ) ? $saved[ $area ]['intensity'] : 'normal',
			'scale'     => isset( $saved[ $area ]['scale'] ) ? ulbd_clean_scale( $saved[ $area ]['scale'] ) : 100,
			'boxes'     => isset( $saved[ $area ]['boxes'] ) ? ulbd_clean_boxes( $saved[ $area ]['boxes'] ) : null,
		);
	}

	return $settings;
}

/**
 * Settings in effect for this request: the saved ones, overridden by ?ulbd_<area>=<style>,
 * ?ulbd_<area>_intensity=<preset>, ?ulbd_<area>_scale=<percent>, ?ulbd_<area>_boxes=<percent> and ?ulbd_text=<weight> for users who can manage options (the "Preview on
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
			if ( isset( $_GET[ 'ulbd_' . $area . '_scale' ] ) ) {
				$settings[ $area ]['scale']   = ulbd_clean_scale( sanitize_text_field( wp_unslash( $_GET[ 'ulbd_' . $area . '_scale' ] ) ) );
				$settings[ $area ]['preview'] = true;
			}
			if ( isset( $_GET[ 'ulbd_' . $area . '_boxes' ] ) && '' !== $_GET[ 'ulbd_' . $area . '_boxes' ] ) {
				$settings[ $area ]['boxes']   = ulbd_clean_boxes( sanitize_text_field( wp_unslash( $_GET[ 'ulbd_' . $area . '_boxes' ] ) ) );
				$settings[ $area ]['preview'] = true;
			}
			// phpcs:enable
		}
	}

	return $settings;
}

/**
 * A style's pixel lengths made scalable: every Npx becomes calc(Npx * var(--ulbd-scale, 1)), so the pattern
 * size setting (--ulbd-scale on the area, or on the admin picker) resizes the tiles - and, in an animation's
 * keyframes, the distances they travel, so a loop that moves whole tiles stays seamless at any size.
 */
function ulbd_scalable( $css ) {
	return preg_replace( '/(\d*\.?\d+)px\b/', 'calc($1px * var(--ulbd-scale, 1))', $css );
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
			$decl .= ' background-' . $prop . ': ' . ( 'size' === $prop ? ulbd_scalable( $css[ $prop ] ) : $css[ $prop ] ) . ';';
		}
	}
	$keyframes = '';
	if ( ! empty( $css['animation'] ) ) {
		$decl     .= ' animation: ' . $css['animation'] . ';';
		$keyframes = '@keyframes ulbd-' . $style_id . ' { ' . ulbd_scalable( $css['keyframes'] ) . ' }';
	}

	return array( $decl, $keyframes );
}

/**
 * An area's pills and text boxes at a text box opacity (percent, 100 = solid): all of them in the same glass -
 * white on a light backdrop, black on a dark one - at that opacity.
 */
function ulbd_boxes_css( $info, $dark, $percent ) {
	return implode( ', ', array_merge( $info['pills'], $info['boxes'] ) ) . sprintf( ' { background: rgba(%s, %s); }', $dark ? '0, 0, 0' : '255, 255, 255', $percent / 100 );
}

/**
 * Front end: the chosen backgrounds, in the <head>. Nothing is printed for an area left on the default (the
 * theme's own aurora at normal intensity), so the theme stays the baseline.
 *
 * The <style> stays in the page, out of Autoptimize (<!--noptimize-->): combined into its deferred stylesheet,
 * the settings would only apply once that loaded, and the first paint would come from the critical CSS - a
 * snapshot of dev's settings, stale on production as soon as a setting changes there. Inline, the first paint
 * always has the saved settings, and the critical CSS generator leaves this block out (ops/critical-css).
 * Autoptimize puts the critical CSS and the theme's stylesheet after it (just before </head>), so every rule
 * here outranks the theme's by specificity rather than by order: area selectors start with body or an id, and
 * the text weight is set on html:root (the theme sets it on :root).
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
			$out[] = 'html:root { --ul-display-weight: ' . ulbd_text_weights()[ $active['text_weight'] ][1] . '; }';
		}
		foreach ( ulbd_areas() as $area => $info ) {
			$s       = $active[ $area ];
			$preview = $preview || ! empty( $s['preview'] );
			$style = ulbd_styles()[ $s['style'] ];
			// Pills and text boxes at the chosen opacity: printed last, after the dark glass they adjust.
			$boxes = null !== $s['boxes'] ? ulbd_boxes_css( $info, ! empty( $style['dark'] ), $s['boxes'] ) : '';
			if ( 'aurora' === $s['style'] && 'normal' === $s['intensity'] ) {
				if ( $boxes ) {
					$out[] = $boxes;
				}
				continue;
			}
			list( $decl, $kf ) = ulbd_style_css( $s['style'], $s['intensity'] );
			if ( 100 !== $s['scale'] ) {
				$decl = '--ulbd-scale: ' . ( $s['scale'] / 100 ) . '; ' . $decl;
			}
			$selectors         = implode( ', ', $info['selectors'] );
			$out[]             = $selectors . ' { ' . $decl . ' }';
			if ( ! empty( $style['dark'] ) && $info['dark_css'] ) {
				$out[] = $info['dark_css'];
			}
			if ( $boxes ) {
				$out[] = $boxes;
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
			echo "<!--noptimize--><style id=\"ulbd-backdrops\">\n" . implode( "\n", array_merge( array_values( $keyframes ), $out ) ) . "\n</style><!--/noptimize-->\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from the style library.
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
 * New backdrops: each admin's list of styles they have seen (user meta), so styles added since their last visit
 * to Appearance > Backdrops are counted in the admin menu and labelled "New" in the picker. Visiting the page
 * marks every style seen. An admin with no list yet starts from the styles the plugin had before the count
 * existed, so the ones added alongside it show as new once.
 */
define( 'ULBD_SEEN_META', 'ulbd_seen_styles' );

/** The styles in the plugin before the new-backdrop count existed: everyone's starting "seen" list. */
function ulbd_baseline_styles() {
	return array( 'aurora', 'aurora-drift', 'mesh', 'silk', 'dots', 'stripes', 'tint', 'waves', 'blobs', 'grain', 'hearts', 'mesh-shift', 'night-aurora', 'night-drift', 'night-mesh', 'halloween', 'thanksgiving', 'christmas', 'new-year', 'none' );
}

/** Style ids the current user hasn't seen yet. */
function ulbd_new_styles() {
	$seen = get_user_meta( get_current_user_id(), ULBD_SEEN_META, true );
	if ( ! is_array( $seen ) ) {
		$seen = ulbd_baseline_styles();
	}

	return array_values( array_diff( array_keys( ulbd_styles() ), $seen ) );
}

/** WordPress's own count bubble (as on Plugins or Comments), for a menu title. */
function ulbd_count_bubble( $count ) {
	return $count ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%1$d new backdrops</span></span>', $count ) : '';
}

/*
 * Admin: Appearance > Backdrops, with the new-backdrop count on it and on Appearance.
 */
add_action(
	'admin_menu',
	function () {
		global $menu;

		$count = current_user_can( 'manage_options' ) ? count( ulbd_new_styles() ) : 0;
		add_theme_page( 'Backdrops', 'Backdrops' . ulbd_count_bubble( $count ), 'manage_options', 'ulbd-backdrops', 'ulbd_render_admin' );
		if ( $count ) {
			foreach ( $menu as $i => $item ) {
				if ( 'themes.php' === $item[2] ) {
					$menu[ $i ][0] .= ulbd_count_bubble( $count ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- adding the count bubble, as core does.
				}
			}
		}
	}
);

/*
 * Visiting the page: remember which styles are new for this visit's labels, mark them all seen, and take the
 * bubbles off this page's menu (built before this runs).
 */
add_action(
	'load-appearance_page_ulbd-backdrops',
	function () {
		global $menu, $submenu;

		$GLOBALS['ulbd_new_this_visit'] = ulbd_new_styles();
		update_user_meta( get_current_user_id(), ULBD_SEEN_META, array_keys( ulbd_styles() ) );
		$strip = function ( $title ) {
			return preg_replace( '/ <span class="awaiting-mod count-\d+">.*$/s', '', $title );
		};
		foreach ( $menu as $i => $item ) {
			if ( 'themes.php' === $item[2] ) {
				$menu[ $i ][0] = $strip( $item[0] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
		foreach ( isset( $submenu['themes.php'] ) ? $submenu['themes.php'] : array() as $i => $item ) {
			if ( 'ulbd-backdrops' === $item[2] ) {
				$submenu['themes.php'][ $i ][0] = $strip( $item[0] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
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

/**
 * Keep only known areas, styles, intensities, pattern sizes, text box opacities and text weights. With 'linked' on, the first
 * area's choices (the home page call to action) are saved for every area, so the front end needs nothing new.
 */
function ulbd_sanitize( $input ) {
	$weight = isset( $input['text_weight'] ) ? sanitize_key( $input['text_weight'] ) : 'regular';
	$clean  = array(
		'linked'      => ! empty( $input['linked'] ),
		'text_weight' => isset( ulbd_text_weights()[ $weight ] ) ? $weight : 'regular',
	);
	foreach ( array_keys( ulbd_areas() ) as $area ) {
		$style     = isset( $input[ $area ]['style'] ) ? sanitize_key( $input[ $area ]['style'] ) : 'aurora';
		$intensity = isset( $input[ $area ]['intensity'] ) ? sanitize_key( $input[ $area ]['intensity'] ) : 'normal';

		$clean[ $area ] = array(
			'style'     => isset( ulbd_styles()[ $style ] ) ? $style : 'aurora',
			'intensity' => isset( ulbd_intensities()[ $intensity ] ) ? $intensity : 'normal',
			'scale'     => isset( $input[ $area ]['scale'] ) ? ulbd_clean_scale( $input[ $area ]['scale'] ) : 100,
			'boxes'     => isset( $input[ $area ]['boxes'] ) ? ulbd_clean_boxes( $input[ $area ]['boxes'] ) : null,
		);
	}
	if ( $clean['linked'] ) {
		$areas = array_keys( ulbd_areas() );
		foreach ( array_slice( $areas, 1 ) as $area ) {
			$clean[ $area ] = $clean[ $areas[0] ];
		}
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

/** The settings page: per area, a grid of live preview tiles to pick from, an intensity, a pattern size and a preview link. */
function ulbd_render_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// New since this admin's last visit (worked out before the visit marked them seen).
	$new_styles  = isset( $GLOBALS['ulbd_new_this_visit'] ) ? $GLOBALS['ulbd_new_this_visit'] : array();
	$new_by_kind = array();
	foreach ( ulbd_styles() as $id => $style ) {
		$new_by_kind[ $style['kind'] ] = ( isset( $new_by_kind[ $style['kind'] ] ) ? $new_by_kind[ $style['kind'] ] : 0 ) + ( in_array( $id, $new_styles, true ) ? 1 : 0 );
	}
	$settings    = ulbd_settings();
	$scale_range = ulbd_scale_range();
	$boxes_range = ulbd_boxes_range();
	$kinds       = array(
		'plain'        => 'Plain',
		'css'          => 'CSS',
		'svg'          => 'SVG',
		'animated'     => 'Animated',
		'dark'         => 'Dark',
		'halloween'    => 'Halloween',
		'thanksgiving' => 'Thanksgiving',
		'christmas'    => 'Christmas',
		'new-year'     => 'New Year\'s',
	);

	// Every style's tile at every intensity, as classes the intensity buttons switch between.
	$tile_css  = array();
	$keyframes = array();
	foreach ( ulbd_styles() as $id => $style ) {
		if ( ! empty( $style['dark'] ) ) {
			$tile_css[] = '.ulbd-tile--' . $id . ' .ulbd-swatch__pill { background: rgba(0, 0, 0, var(--ulbd-box-alpha, .52)); color: #fff; }';
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
	<div class="wrap ulbd" data-linked="<?php echo $settings['linked'] ? '1' : '0'; ?>">
		<h1>Backdrops</h1>
		<p class="ulbd-intro">The background behind the home page's call to action and behind the footer box, and the weight of
			the site's display text. Pick a style and an intensity for each area, use <strong>Preview on the site</strong> to see
			the combination on the home page (only you see it, nothing is saved), then <strong>Save</strong>. Everything in front of
			the backdrop stays as it is, except on a dark style: the pills and text boxes turn to see-through dark glass with
			white text, and the footer's quick links turn white. <strong>Pattern size</strong> scales the repeating motifs (bats, hats, hearts, leaves,
			snow...) from half to three times their size; glows and full-width shapes stay as they are. <strong>Text box opacity</strong> sets how solid the pills and text boxes
			on the backdrop are, from 0% (clear) to 100% (solid); <strong>Reset</strong> returns them to the standard glass. Animated styles stand still for visitors who ask their device for reduced motion.</p>

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
					<div class="ulbd-controls">
						<div class="ulbd-control">
							<span class="ulbd-control__label" aria-hidden="true">Weight</span>
							<fieldset class="ulbd-intensity">
								<legend>Weight</legend>
								<?php foreach ( ulbd_text_weights() as $key => $weight ) : ?>
									<label>
										<input type="radio" name="<?php echo esc_attr( ULBD_OPTION . '[text_weight]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['text_weight'], $key ); ?>>
										<span style="font-weight: <?php echo (int) $weight[1]; ?>"><?php echo esc_html( $weight[0] ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</div>
					</div>
				</header>
				<p class="ulbd-actions">
					<a class="button ulbd-preview-link" target="_blank" rel="noopener" href="<?php echo esc_url( home_url( '/' ) ); ?>">Preview on the site</a>
				</p>
			</section>

			<?php
			// One backdrop for both areas (a single picker, the first area's), or a tab per area.
			$areas      = ulbd_areas();
			$first_area = array_key_first( $areas );
			?>
			<p class="ulbd-link">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( ULBD_OPTION . '[linked]' ); ?>" value="1" <?php checked( $settings['linked'] ); ?>>
					Use the same backdrop for the home page call to action and the footer box
				</label>
			</p>

			<div class="ulbd-tabs" role="tablist" aria-label="Backdrop area" <?php echo $settings['linked'] ? 'hidden' : ''; ?>>
				<?php foreach ( $areas as $area => $info ) : ?>
					<button type="button" role="tab" class="ulbd-tab" id="ulbd-tab-<?php echo esc_attr( $area ); ?>" aria-controls="ulbd-panel-<?php echo esc_attr( $area ); ?>" aria-selected="<?php echo $area === $first_area ? 'true' : 'false'; ?>" tabindex="<?php echo $area === $first_area ? '0' : '-1'; ?>"><?php echo esc_html( $info['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $areas as $area => $info ) : ?>
				<section class="ulbd-picker ulbd-area" id="ulbd-panel-<?php echo esc_attr( $area ); ?>" role="tabpanel" aria-labelledby="ulbd-tab-<?php echo esc_attr( $area ); ?>" data-area="<?php echo esc_attr( $area ); ?>" data-intensity="<?php echo esc_attr( $settings[ $area ]['intensity'] ); ?>" style="--ulbd-scale: <?php echo esc_attr( $settings[ $area ]['scale'] / 100 ); ?><?php echo null !== $settings[ $area ]['boxes'] ? esc_attr( '; --ulbd-box-alpha: ' . $settings[ $area ]['boxes'] / 100 ) : ''; ?>" <?php echo $area !== $first_area ? 'hidden' : ''; ?>>
					<header class="ulbd-picker__header">
						<div>
							<?php if ( $area === $first_area ) : ?>
								<h2><span class="ulbd-unlinked-only"><?php echo esc_html( $info['label'] ); ?></span><span class="ulbd-linked-only">Home page call to action and footer box</span></h2>
								<p><span class="ulbd-unlinked-only"><?php echo esc_html( $info['description'] ); ?></span><span class="ulbd-linked-only">One backdrop for both: the "Adult Wholesale Canada" block under the home banner, and the footer box on every page.</span></p>
							<?php else : ?>
								<h2><?php echo esc_html( $info['label'] ); ?></h2>
								<p><?php echo esc_html( $info['description'] ); ?></p>
							<?php endif; ?>
						</div>
						<div class="ulbd-controls">
							<div class="ulbd-control">
								<span class="ulbd-control__label" aria-hidden="true">Intensity</span>
								<fieldset class="ulbd-intensity">
									<legend>Intensity</legend>
									<?php foreach ( ulbd_intensities() as $key => $preset ) : ?>
										<label>
											<input type="radio" name="<?php echo esc_attr( ULBD_OPTION . '[' . $area . '][intensity]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings[ $area ]['intensity'], $key ); ?>>
											<span><?php echo esc_html( $preset[0] ); ?></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</div>
							<label class="ulbd-control ulbd-scale">
								<span class="ulbd-control__label">Pattern size <output><?php echo (int) $settings[ $area ]['scale']; ?>%</output></span>
								<input type="range" name="<?php echo esc_attr( ULBD_OPTION . '[' . $area . '][scale]' ); ?>" min="<?php echo (int) $scale_range[0]; ?>" max="<?php echo (int) $scale_range[1]; ?>" step="<?php echo (int) $scale_range[2]; ?>" value="<?php echo (int) $settings[ $area ]['scale']; ?>">
							</label>
							<?php
							// Opacity in percent (100 = solid), or Standard (the theme's mixed glass) until the slider moves:
							// the hidden field holds the setting, empty for Standard.
							$boxes = $settings[ $area ]['boxes'];
							?>
							<div class="ulbd-control ulbd-scale ulbd-boxes">
								<span class="ulbd-control__label">
									<label for="ulbd-boxes-<?php echo esc_attr( $area ); ?>">Text box opacity</label>
									<output><?php echo null === $boxes ? 'Standard' : (int) $boxes . '%'; ?></output>
									<button type="button" class="button-link ulbd-boxes__reset" <?php echo null === $boxes ? 'hidden' : ''; ?>>Reset</button>
								</span>
								<input type="range" id="ulbd-boxes-<?php echo esc_attr( $area ); ?>" min="<?php echo (int) $boxes_range[0]; ?>" max="<?php echo (int) $boxes_range[1]; ?>" step="<?php echo (int) $boxes_range[2]; ?>" value="<?php echo null === $boxes ? 80 : (int) $boxes; ?>">
								<input type="hidden" name="<?php echo esc_attr( ULBD_OPTION . '[' . $area . '][boxes]' ); ?>" value="<?php echo null === $boxes ? '' : (int) $boxes; ?>">
							</div>
						</div>
					</header>

					<?php
					// The styles in a tab per kind, opening on the tab that holds the saved style. Radios in hidden
					// tabs are still submitted, so the choice is kept whichever tab is showing.
					$current_kind = ulbd_styles()[ $settings[ $area ]['style'] ]['kind'];
					?>
					<div class="ulbd-kind-tabs" role="tablist" aria-label="<?php echo esc_attr( $info['label'] . ' styles' ); ?>">
						<?php foreach ( $kinds as $kind => $kind_label ) : ?>
							<button type="button" role="tab" class="ulbd-kind-tab" id="ulbd-kind-tab-<?php echo esc_attr( $area . '-' . $kind ); ?>" aria-controls="ulbd-kind-<?php echo esc_attr( $area . '-' . $kind ); ?>" aria-selected="<?php echo $kind === $current_kind ? 'true' : 'false'; ?>" tabindex="<?php echo $kind === $current_kind ? '0' : '-1'; ?>" data-kind="<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $kind_label ); ?><?php echo $new_by_kind[ $kind ] ? '<span class="ulbd-new-count">' . (int) $new_by_kind[ $kind ] . ' new</span>' : ''; ?><span class="ulbd-kind-tab__dot" <?php echo $kind === $current_kind ? '' : 'hidden'; ?>><span class="screen-reader-text"> (selected style)</span></span></button>
						<?php endforeach; ?>
					</div>

					<?php foreach ( $kinds as $kind => $kind_label ) : ?>
						<div class="ulbd-grid" role="tabpanel" id="ulbd-kind-<?php echo esc_attr( $area . '-' . $kind ); ?>" aria-labelledby="ulbd-kind-tab-<?php echo esc_attr( $area . '-' . $kind ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" <?php echo $kind === $current_kind ? '' : 'hidden'; ?>>
							<?php
							foreach ( ulbd_styles() as $id => $style ) :
								if ( $style['kind'] !== $kind ) {
									continue;
								}
								?>
								<label class="ulbd-tile ulbd-tile--<?php echo esc_attr( $id ); ?>">
									<input type="radio" name="<?php echo esc_attr( ULBD_OPTION . '[' . $area . '][style]' ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $settings[ $area ]['style'], $id ); ?>>
									<span class="ulbd-swatch"><span class="ulbd-swatch__pill">Aa</span></span>
									<span class="ulbd-tile__label"><?php echo esc_html( $style['label'] ); ?><?php echo in_array( $id, $new_styles, true ) ? ' <span class="ulbd-new">New</span>' : ''; ?></span>
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
