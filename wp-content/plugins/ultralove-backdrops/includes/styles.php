<?php
/**
 * The backdrop style library.
 *
 * Every style is a callable that takes an intensity factor ($k: 0.6 subtle, 1 normal, 1.45 bold) and returns
 * the CSS for the background layer only - the areas' white edge fades and their content are the theme's and
 * stay as they are. A style returns:
 *  - 'background' (required): the CSS background shorthand, layers comma-separated;
 *  - optional 'size', 'position', 'repeat': background-size / -position / -repeat for those layers;
 *  - optional 'animation' + 'keyframes' (@keyframes body, named ulbd-<id>): slow ambient motion,
 *    switched off for visitors who ask for reduced motion.
 * Colours are the site's: lavender rgb(177,101,255), plum #3b1d4a, brand pink #b3094b, plus the aurora's
 * soft teal / periwinkle / rose, on the pale lavender base #f6f1fa.
 *
 * To add a style: add an entry to ulbd_styles() - it shows up in the admin picker with a live preview.
 *
 * @package UltraLove_Backdrops
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** rgba() with its alpha scaled by the intensity factor (and kept within 0-1). */
function ulbd_rgba( $r, $g, $b, $a, $k ) {
	return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim( rtrim( number_format( max( 0, min( 1, $a * $k ) ), 3, '.', '' ), '0' ), '.' ) );
}

/** An SVG document as a CSS url() data URI. */
function ulbd_svg_url( $svg ) {
	return 'url("data:image/svg+xml,' . rawurlencode( preg_replace( '/\s+/', ' ', trim( $svg ) ) ) . '")';
}

/** The aurora glows (the theme's --ul-aurora, with the alphas scaled) - shared by 'aurora' and 'aurora-drift'. */
function ulbd_aurora_layers( $k ) {
	$glows = array(
		array( '14% 20% at 65% 25%', array( 170, 225, 232 ), .60, .25, 50 ),
		array( '16% 14% at 80% 32%', array( 200, 220, 245 ), .55 ),
		array( '12% 16% at 50% 8%', array( 190, 205, 240 ), .50 ),
		array( '12% 14% at 73% 8%', array( 175, 195, 240 ), .45 ),
		array( '12% 16% at 14% 32%', array( 215, 170, 230 ), .60, .25, 55 ),
		array( '10% 22% at 6% 52%', array( 225, 175, 215 ), .50 ),
		array( '12% 20% at 29% 18%', array( 205, 175, 235 ), .50 ),
		array( '12% 14% at 36% 3%', array( 200, 185, 235 ), .45 ),
		array( '18% 22% at 91% 45%', array( 220, 180, 225 ), .50 ),
		array( '8% 10% at 43% 40%', array( 240, 180, 205 ), .45 ),
		array( '10% 14% at 48% 68%', array( 210, 180, 235 ), .45 ),
		array( '9% 12% at 60% 63%', array( 170, 215, 230 ), .45 ),
		array( '12% 16% at 74% 72%', array( 215, 190, 240 ), .45 ),
		array( '12% 14% at 14% 94%', array( 235, 185, 215 ), .45 ),
	);
	$layers = array();
	foreach ( $glows as $g ) {
		list( $shape, $rgb, $a ) = $g;
		$mid                     = isset( $g[3] ) ? ', ' . ulbd_rgba( $rgb[0], $rgb[1], $rgb[2], $g[3], $k ) . ' ' . $g[4] . '%' : '';
		$layers[]                = sprintf( 'radial-gradient(ellipse %s, %s 0%s, transparent 100%%)', $shape, ulbd_rgba( $rgb[0], $rgb[1], $rgb[2], $a, $k ), $mid );
	}
	$layers[] = 'radial-gradient(ellipse 60% 70% at 40% 60%, rgba(255, 255, 255, .55) 0, transparent 100%)';

	return $layers;
}

/**
 * All styles, keyed by id: array( label, kind, description, css callable ).
 * Kinds group the picker: 'css' (gradients / patterns), 'svg' (SVG images), 'animated', 'plain'.
 */
function ulbd_styles() {
	$base = '#f6f1fa';

	return array(
		'aurora'       => array(
			'label'       => 'Aurora (current)',
			'kind'        => 'css',
			'description' => 'Soft lavender, teal and rose glows - the site\'s current backdrop.',
			'css'         => function ( $k ) use ( $base ) {
				return array( 'background' => implode( ', ', array_merge( ulbd_aurora_layers( $k ), array( $base ) ) ) );
			},
		),
		'aurora-drift' => array(
			'label'       => 'Drifting aurora',
			'kind'        => 'animated',
			'description' => 'The aurora, oversized and slowly drifting (a 40s loop).',
			'css'         => function ( $k ) use ( $base ) {
				return array(
					'background' => implode( ', ', array_merge( ulbd_aurora_layers( $k ), array( $base ) ) ),
					'size'       => '160% 160%',
					'animation'  => 'ulbd-aurora-drift 40s ease-in-out infinite alternate',
					'keyframes'  => '0% { background-position: 0% 0%; } 50% { background-position: 100% 40%; } 100% { background-position: 30% 100%; }',
				);
			},
		),
		'mesh'         => array(
			'label'       => 'Mesh gradient',
			'kind'        => 'css',
			'description' => 'Four large colour fields blending into each other from the corners.',
			'css'         => function ( $k ) use ( $base ) {
				return array(
					'background' => implode(
						', ',
						array(
							'radial-gradient(at 0% 0%, ' . ulbd_rgba( 215, 170, 230, .75, $k ) . ' 0, transparent 55%)',
							'radial-gradient(at 100% 0%, ' . ulbd_rgba( 170, 225, 232, .70, $k ) . ' 0, transparent 55%)',
							'radial-gradient(at 100% 100%, ' . ulbd_rgba( 235, 185, 215, .70, $k ) . ' 0, transparent 55%)',
							'radial-gradient(at 0% 100%, ' . ulbd_rgba( 190, 205, 240, .70, $k ) . ' 0, transparent 55%)',
							$base,
						)
					),
				);
			},
		),
		'silk'         => array(
			'label'       => 'Silk (conic)',
			'kind'        => 'css',
			'description' => 'A soft conic sweep of lavender, rose and teal, washed out towards the middle.',
			'css'         => function ( $k ) use ( $base ) {
				return array(
					'background' => implode(
						', ',
						array(
							'radial-gradient(ellipse 70% 60% at 50% 50%, rgba(255, 255, 255, .7) 0, transparent 100%)',
							'conic-gradient(from 210deg at 35% 45%, ' . ulbd_rgba( 215, 170, 230, .55, $k ) . ', ' . ulbd_rgba( 170, 225, 232, .45, $k ) . ', ' . ulbd_rgba( 235, 185, 215, .5, $k ) . ', ' . ulbd_rgba( 200, 185, 235, .5, $k ) . ', ' . ulbd_rgba( 215, 170, 230, .55, $k ) . ')',
							$base,
						)
					),
				);
			},
		),
		'dots'         => array(
			'label'       => 'Dot grid',
			'kind'        => 'css',
			'description' => 'A fine lavender dot grid over a faint wash.',
			'css'         => function ( $k ) use ( $base ) {
				return array(
					'background' => 'radial-gradient(circle, ' . ulbd_rgba( 177, 101, 255, .32, $k ) . ' 1.3px, transparent 1.8px), radial-gradient(ellipse 80% 70% at 50% 40%, ' . ulbd_rgba( 215, 170, 230, .35, $k ) . ' 0, transparent 100%), ' . $base,
					'size'       => '22px 22px, 100% 100%, auto',
				);
			},
		),
		'stripes'      => array(
			'label'       => 'Fine stripes',
			'kind'        => 'css',
			'description' => 'Thin diagonal lavender pinstripes over a soft glow.',
			'css'         => function ( $k ) use ( $base ) {
				return array(
					'background' => 'repeating-linear-gradient(135deg, ' . ulbd_rgba( 177, 101, 255, .10, $k ) . ' 0 2px, transparent 2px 14px), radial-gradient(ellipse 70% 80% at 30% 30%, ' . ulbd_rgba( 235, 185, 215, .35, $k ) . ' 0, transparent 100%), ' . $base,
				);
			},
		),
		'tint'         => array(
			'label'       => 'Lavender tint',
			'kind'        => 'plain',
			'description' => 'A quiet lavender-to-white gradient.',
			'css'         => function ( $k ) {
				return array( 'background' => 'linear-gradient(180deg, ' . ulbd_rgba( 215, 190, 240, .55, $k ) . ' 0, rgba(255, 255, 255, 0) 100%), #fff' );
			},
		),
		'waves'        => array(
			'label'       => 'Waves (SVG)',
			'kind'        => 'svg',
			'description' => 'Three layered waves rising from the bottom edge.',
			'css'         => function ( $k ) use ( $base ) {
				$svg = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 600" preserveAspectRatio="none">
						<path fill="rgb(215,170,230)" fill-opacity="%s" d="M0 330 C 240 250 480 410 720 330 S 1200 250 1440 330 V600 H0z"/>
						<path fill="rgb(170,225,232)" fill-opacity="%s" d="M0 410 C 300 340 540 480 780 410 S 1200 350 1440 420 V600 H0z"/>
						<path fill="rgb(235,185,215)" fill-opacity="%s" d="M0 490 C 260 440 560 560 860 490 S 1260 450 1440 500 V600 H0z"/>
					</svg>',
					min( 1, .45 * $k ),
					min( 1, .40 * $k ),
					min( 1, .45 * $k )
				);

				return array(
					'background' => ulbd_svg_url( $svg ) . ', radial-gradient(ellipse 70% 60% at 50% 20%, ' . ulbd_rgba( 200, 185, 235, .35, $k ) . ' 0, transparent 100%), ' . $base,
					'size'       => '100% 100%, auto, auto',
					'repeat'     => 'no-repeat',
				);
			},
		),
		'blobs'        => array(
			'label'       => 'Soft blobs (SVG)',
			'kind'        => 'svg',
			'description' => 'Large blurred colour blobs - an SVG Gaussian blur, so no CSS filter cost.',
			'css'         => function ( $k ) use ( $base ) {
				$svg = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 600" preserveAspectRatio="xMidYMid slice">
						<defs><filter id="b" x="-50%%" y="-50%%" width="200%%" height="200%%"><feGaussianBlur stdDeviation="70"/></filter></defs>
						<g filter="url(#b)">
							<ellipse cx="260" cy="180" rx="260" ry="170" fill="rgb(215,170,230)" fill-opacity="%1$s"/>
							<ellipse cx="1150" cy="140" rx="280" ry="160" fill="rgb(170,225,232)" fill-opacity="%2$s"/>
							<ellipse cx="760" cy="430" rx="320" ry="150" fill="rgb(235,185,215)" fill-opacity="%1$s"/>
							<ellipse cx="1300" cy="480" rx="200" ry="120" fill="rgb(190,205,240)" fill-opacity="%2$s"/>
						</g>
					</svg>',
					min( 1, .7 * $k ),
					min( 1, .6 * $k )
				);

				return array(
					'background' => ulbd_svg_url( $svg ) . ', ' . $base,
					'size'       => 'cover',
					'position'   => 'center',
					'repeat'     => 'no-repeat',
				);
			},
		),
		'grain'        => array(
			'label'       => 'Grain (SVG noise)',
			'kind'        => 'svg',
			'description' => 'A film-grain texture (SVG feTurbulence) over the mesh gradient.',
			'css'         => function ( $k ) use ( $base ) {
				$svg = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="220" height="220">
						<filter id="n"><feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="2" stitchTiles="stitch"/><feColorMatrix values="0 0 0 0 .23  0 0 0 0 .11  0 0 0 0 .29  0 0 0 %s 0"/></filter>
						<rect width="100%%" height="100%%" filter="url(#n)"/>
					</svg>',
					min( 1, .22 * $k )
				);

				return array(
					'background' => ulbd_svg_url( $svg ) . ', radial-gradient(at 0% 0%, ' . ulbd_rgba( 215, 170, 230, .7, $k ) . ' 0, transparent 55%), radial-gradient(at 100% 0%, ' . ulbd_rgba( 170, 225, 232, .65, $k ) . ' 0, transparent 55%), radial-gradient(at 100% 100%, ' . ulbd_rgba( 235, 185, 215, .65, $k ) . ' 0, transparent 55%), radial-gradient(at 0% 100%, ' . ulbd_rgba( 190, 205, 240, .65, $k ) . ' 0, transparent 55%), ' . $base,
					'size'       => '220px 220px, auto, auto, auto, auto, auto',
				);
			},
		),
		'hearts'       => array(
			'label'       => 'Scattered hearts (SVG)',
			'kind'        => 'svg',
			'description' => 'A light pattern of small tilted hearts in lavender and rose.',
			'css'         => function ( $k ) use ( $base ) {
				$heart = 'M12 21s-7-4.4-9.5-8.9A5.4 5.4 0 0 1 12 6.1a5.4 5.4 0 0 1 9.5 6C19 16.6 12 21 12 21z';
				$svg   = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">
						<path d="%1$s" transform="translate(14 14) rotate(-14 12 12) scale(.8)" fill="rgb(177,101,255)" fill-opacity="%2$s"/>
						<path d="%1$s" transform="translate(74 70) rotate(12 12 12) scale(.62)" fill="rgb(235,150,195)" fill-opacity="%3$s"/>
					</svg>',
					$heart,
					min( 1, .16 * $k ),
					min( 1, .2 * $k )
				);

				return array(
					'background' => ulbd_svg_url( $svg ) . ', radial-gradient(ellipse 75% 70% at 50% 40%, ' . ulbd_rgba( 215, 170, 230, .35, $k ) . ' 0, transparent 100%), ' . $base,
					'size'       => '120px 120px, auto, auto',
				);
			},
		),
		'mesh-shift'   => array(
			'label'       => 'Shifting mesh',
			'kind'        => 'animated',
			'description' => 'The mesh gradient, with its colour fields slowly trading places (a 30s loop).',
			'css'         => function ( $k ) use ( $base ) {
				return array(
					'background' => 'radial-gradient(at 0% 0%, ' . ulbd_rgba( 215, 170, 230, .75, $k ) . ' 0, transparent 55%), radial-gradient(at 100% 0%, ' . ulbd_rgba( 170, 225, 232, .7, $k ) . ' 0, transparent 55%), radial-gradient(at 100% 100%, ' . ulbd_rgba( 235, 185, 215, .7, $k ) . ' 0, transparent 55%), radial-gradient(at 0% 100%, ' . ulbd_rgba( 190, 205, 240, .7, $k ) . ' 0, transparent 55%), ' . $base,
					'size'       => '200% 200%',
					'animation'  => 'ulbd-mesh-shift 30s ease-in-out infinite alternate',
					'keyframes'  => '0% { background-position: 0% 0%; } 100% { background-position: 100% 100%; }',
				);
			},
		),
		'none'         => array(
			'label'       => 'Plain white',
			'kind'        => 'plain',
			'description' => 'No backdrop - the white page.',
			'css'         => function () {
				return array( 'background' => '#fff' );
			},
		),
	);
}
