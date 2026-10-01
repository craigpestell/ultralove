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
 * soft teal / periwinkle / rose, on the pale lavender base #f6f1fa. The 'dark' kind sits on near-black
 * #06060f instead; a style with 'dark' => true also switches the text drawn straight on the backdrop to
 * light colours (the area's 'dark_css' in ulbd_areas()), and one with 'fade' => false drops the theme's
 * white fades at the area's edges (its 'fade_selector'), with hard edges like the live site's backdrop.
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
 * The night aurora's glows - the live site's pure-CSS stand-in for its old bg.jpg (blurred purple / teal
 * aurora on near-black), with the alphas scaled - shared by 'night-aurora' and 'night-drift'. At normal
 * intensity this is the production backdrop exactly.
 */
function ulbd_night_layers( $k ) {
	// Shape, colour, alpha, and optionally a mid stop (colour, alpha, position %).
	$glows = array(
		array( '14% 20% at 65% 25%', array( 155, 215, 225 ), .95, array( 120, 190, 215 ), .5, 50 ),
		array( '16% 14% at 80% 32%', array( 175, 205, 235 ), .75 ),
		array( '12% 16% at 50% 8%', array( 115, 155, 205 ), .65 ),
		array( '12% 14% at 73% 8%', array( 60, 110, 195 ), .60 ),
		array( '12% 16% at 14% 32%', array( 175, 105, 195 ), .85, array( 140, 75, 170 ), .4, 55 ),
		array( '10% 22% at 6% 52%', array( 150, 70, 170 ), .70 ),
		array( '12% 20% at 29% 18%', array( 135, 75, 175 ), .70 ),
		array( '12% 14% at 36% 3%', array( 120, 85, 175 ), .55 ),
		array( '18% 22% at 91% 45%', array( 85, 55, 155 ), .60 ),
		array( '8% 10% at 43% 40%', array( 145, 85, 175 ), .70 ),
		array( '10% 14% at 48% 68%', array( 135, 85, 175 ), .55 ),
		array( '9% 12% at 60% 63%', array( 60, 135, 175 ), .50 ),
		array( '12% 16% at 74% 72%', array( 95, 65, 165 ), .50 ),
		array( '12% 14% at 14% 94%', array( 145, 85, 175 ), .60 ),
	);
	$layers = array();
	foreach ( $glows as $g ) {
		list( $shape, $rgb, $a ) = $g;
		$mid                     = isset( $g[3] ) ? ', ' . ulbd_rgba( $g[3][0], $g[3][1], $g[3][2], $g[4], $k ) . ' ' . $g[5] . '%' : '';
		$layers[]                = sprintf( 'radial-gradient(ellipse %s, %s 0%s, transparent 100%%)', $shape, ulbd_rgba( $rgb[0], $rgb[1], $rgb[2], $a, $k ), $mid );
	}
	$layers[] = 'radial-gradient(ellipse 60% 70% at 40% 60%, rgba(15, 12, 55, .7) 0, transparent 100%)';

	return $layers;
}

/** The bat silhouette (24 x 14) shared by the Halloween styles. */
function ulbd_bat_path() {
	return 'M0 7c3-4 6-3 8-1 1-2 2-3 4-3s3 1 4 3c2-2 5-3 8 1-3 0-4 2-5 4-2-2-4-1-5 1l-2-2-2 2c-1-2-3-3-5-1-1-2-2-4-5-4z';
}

/**
 * The witchy Halloween tile (320 x 280): a witch's hat, a broom, a cauldron, bats and stars. Its colours come
 * from $p - 'fill' (the silhouettes), 'accent' (hat band, broom binding), 'bubble' (the brew), 'star', 'cut'
 * (lines cut into the silhouettes, the backdrop's own colour), 'strap' and 'fuzz' (cheeky only: the cuffs, heart buckle and buzz), and 'a', the
 * silhouettes' opacity before the intensity factor.
 * The cheeky version swaps in a broom whose handle curves up into a wand tip (with a little buzz), a cauldron
 * in an X leather harness with an O-ring, a studded hat with a heart buckle, heart bubbles and fuzzy handcuffs.
 * With $masks, two masquerade masks ('mask' coloured, trimmed in 'accent', ribbons in 'fuzz') fill the tile's
 * empty right edge and bottom.
 */
function ulbd_witchy_svg( $p, $cheeky, $k, $masks = false ) {
	$heart = 'M12 21s-7-4.4-9.5-8.9A5.4 5.4 0 0 1 12 6.1a5.4 5.4 0 0 1 9.5 6C19 16.6 12 21 12 21z';

	// Witch's hat, 44 x 40: a crooked cone on an elliptical brim, with a band and a buckle.
	$hat = '<path d="M10 34 19 8C20 4 23 1 28 0 25 3 24 6 25 10L32 34z" fill="' . $p['fill'] . '"/>'
		. '<ellipse cx="21" cy="35" rx="21" ry="4.5" fill="' . $p['fill'] . '"/>'
		. '<path d="M11.4 29h19.2l1.2 4.2H10.2z" fill="' . $p['accent'] . '"/>';
	$hat .= $cheeky
		? '<g fill="' . $p['star'] . '"><circle cx="13.6" cy="31.1" r=".8"/><circle cx="16.4" cy="31.1" r=".8"/><circle cx="25.6" cy="31.1" r=".8"/><circle cx="28.4" cy="31.1" r=".8"/></g>'
			. '<path d="' . $heart . '" transform="translate(16.7 26.3) scale(.36)" fill="' . $p['fuzz'] . '"/>'
		: '<rect x="18.2" y="28.8" width="5.6" height="4.6" fill="none" stroke="' . $p['fill'] . '" stroke-width="1.2"/>';

	// Broom, 70 long, flying left: handle, binding, bristles with a few lines cut in. The cheeky handle curves
	// up at its front end into a rounded tip, with two buzz arcs in front of it.
	$broom = $cheeky
		? '<path d="M46 6H16C8 6 3 4 2.2-1" fill="none" stroke="' . $p['fill'] . '" stroke-width="2.6" stroke-linecap="round"/>'
			. '<ellipse cx="2" cy="-1.6" rx="2.8" ry="3.6" transform="rotate(-18 2 -1.6)" fill="' . $p['fill'] . '"/>'
			. '<path d="M-3.5-6.5q-2 3.5 0 7M-7-9q-3.5 6 0 12" fill="none" stroke="' . $p['fuzz'] . '" stroke-width="1" stroke-linecap="round"/>'
		: '<path d="M2 6H46" stroke="' . $p['fill'] . '" stroke-width="2.4" stroke-linecap="round"/>';
	$broom .= '<path d="M48 3C56 0 64-4 70-6 68 2 68 10 70 18 64 16 56 12 48 9z" fill="' . $p['fill'] . '"/>'
		. '<path d="M51 5 68-1M51 6H69M51 7 68 13" stroke="' . $p['cut'] . '" stroke-width=".7" stroke-linecap="round"/>'
		. '<rect x="44" y="2.4" width="4.4" height="7.2" rx="1" fill="' . $p['accent'] . '"/>';

	// Cauldron, 40 x 38 (bubbles above): legs, pot, rim, a drip of brew. The cheeky one wears an X harness
	// with a metal O-ring where the straps cross, and its bubbles are hearts.
	$cauldron = '<rect x="9" y="31" width="4" height="6" rx="1" fill="' . $p['fill'] . '"/><rect x="27" y="31" width="4" height="6" rx="1" fill="' . $p['fill'] . '"/>'
		. '<path d="M4 11H36C40 22 36 32 26 34H14C4 32 0 22 4 11z" fill="' . $p['fill'] . '"/>'
		. '<rect x="1" y="7" width="38" height="5" rx="2.5" fill="' . $p['fill'] . '"/>'
		. '<path d="M18.6 12v3.5a1.4 1.4 0 0 0 2.8 0V12z" fill="' . $p['bubble'] . '"/>';
	if ( $cheeky ) {
		$cauldron .= '<path d="M8 13 30 32M32 13 10 32" stroke="' . $p['strap'] . '" stroke-width="2.8"/>'
			. '<circle cx="20" cy="23.4" r="3" fill="' . $p['strap'] . '" stroke="' . $p['star'] . '" stroke-width="1.3"/>'
			. '<g fill="' . $p['star'] . '"><circle cx="11.6" cy="16.1" r=".6"/><circle cx="28.4" cy="16.1" r=".6"/><circle cx="13.8" cy="28.8" r=".6"/><circle cx="26.2" cy="28.8" r=".6"/></g>'
			. '<g fill="' . $p['bubble'] . '"><path d="' . $heart . '" transform="translate(10 -2) scale(.3)"/><path d="' . $heart . '" transform="translate(19 -9) scale(.22)"/><path d="' . $heart . '" transform="translate(23.5 -1) scale(.36)"/></g>';
	} else {
		$cauldron .= '<g fill="' . $p['bubble'] . '"><circle cx="14" cy="3" r="2.2"/><circle cx="21" cy="-3" r="1.6"/><circle cx="27" cy="3.4" r="2.6"/></g>';
	}

	// The odd one out in the empty lower-left: a small tilted hat, or (cheeky) fuzzy handcuffs - two rings with
	// a dashed, round-capped stroke for the fluff, joined by a short chain.
	$extra = $cheeky
		? '<g transform="translate(96 120) rotate(-14) scale(1.4)"><g fill="none" stroke="' . $p['fuzz'] . '" stroke-width="3.6" stroke-linecap="round" stroke-dasharray=".1 1.7"><circle cx="7" cy="8" r="5.4"/><circle cx="29" cy="8" r="5.4"/></g>'
			. '<g fill="none" stroke="' . $p['star'] . '" stroke-width=".9"><ellipse cx="14.6" cy="8" rx="2.2" ry="1.2"/><ellipse cx="18" cy="8" rx="1.2" ry="2"/><ellipse cx="21.4" cy="8" rx="2.2" ry="1.2"/></g></g>'
		: '<g transform="translate(112 118) rotate(12) scale(.8)">' . $hat . '</g>';

	if ( $masks ) {
		$mp     = array(
			'fill'   => $p['mask'],
			'trim'   => $p['accent'],
			'ribbon' => $p['fuzz'],
		);
		$extra .= '<g transform="translate(238 92) rotate(12 30 10) scale(1.05)">' . ulbd_mask_svg( $mp, true ) . '</g>'
			. '<g transform="translate(110 222) rotate(-10 30 10) scale(.85)">' . ulbd_mask_svg( $mp, false, false, true ) . '</g>';
	}

	$bat  = ulbd_bat_path();
	$a    = min( 1, $p['a'] * $k );
	$star = min( 1, .8 * $k );

	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="280" viewBox="0 0 320 280">
			<g opacity="%1$s">
				<g transform="translate(24 30) rotate(-8 21 20) scale(1.2)">%2$s</g>
				<g transform="translate(150 54) rotate(-14) scale(1.3)">%3$s</g>
				<g transform="translate(200 172) scale(1.2)">%4$s</g>
				%5$s
				<path d="%6$s" transform="translate(52 196) rotate(10 12 7) scale(1.5)" fill="%7$s"/>
				<path d="%6$s" transform="translate(268 22) rotate(-12 12 7)" fill="%7$s"/>
			</g>
			<g fill="%8$s" fill-opacity="%9$s">
				<circle cx="130" cy="18" r="1.3"/><circle cx="292" cy="112" r="1"/><circle cx="20" cy="150" r="1.2"/><circle cx="160" cy="254" r=".9"/><circle cx="96" cy="84" r=".8"/>
			</g>
		</svg>',
		$a,
		$hat,
		$broom,
		$cauldron,
		$extra,
		$bat,
		$p['fill'],
		$p['star'],
		$star
	);
}

/** The witchy palettes: purple silhouettes on a dark night, or black ones on pumpkin orange. */
function ulbd_witchy_palette( $orange ) {
	return $orange
		? array(
			'fill'   => 'rgb(24,10,14)',
			'accent' => 'rgb(255,196,110)',
			'bubble' => 'rgb(24,10,14)',
			'star'   => 'rgb(255,236,200)',
			'cut'    => 'rgb(240,128,34)',
			'strap'  => 'rgb(150,62,18)',
			'fuzz'   => 'rgb(24,10,14)',
			'mask'   => 'rgb(24,10,14)',
			'a'      => .8,
		)
		: array(
			'fill'   => 'rgb(92,52,120)',
			'accent' => 'rgb(255,150,50)',
			'bubble' => 'rgb(140,230,110)',
			'star'   => 'rgb(255,230,190)',
			'cut'    => 'rgb(14,7,22)',
			'strap'  => 'rgb(20,8,24)',
			'fuzz'   => 'rgb(235,120,185)',
			'mask'   => 'rgb(20,8,24)',
			'a'      => .8,
		);
}

/**
 * A masquerade (voyeur) eye mask, 60 x 24 before ribbons: cat-eye tips, a dip over the nose, almond eye holes
 * cut out (even-odd, so the backdrop shows through), a trim line with lace dots and a jewel. Colours from $p -
 * 'fill', 'trim', 'ribbon' (ribbons and feather, also the jewel). Options: trailing ribbons, a feather plume
 * at the right tip, or a stick to hold it up by.
 */
function ulbd_mask_svg( $p, $ribbons = false, $feather = false, $stick = false ) {
	$out = '';
	if ( $stick ) {
		$out .= '<path d="M50 17 58 46" stroke="' . $p['trim'] . '" stroke-width="1.6" stroke-linecap="round"/>';
	}
	if ( $ribbons ) {
		$out .= '<path d="M3 5C-4 9-2 18-9 24M57 5C64 9 62 18 69 24" fill="none" stroke="' . $p['ribbon'] . '" stroke-width="1.3" stroke-linecap="round"/>';
	}
	if ( $feather ) {
		$out .= '<path d="M54-1C57-12 63-19 70-24 66-13 61-6 56 0z" fill="' . $p['ribbon'] . '"/>'
			. '<path d="M55-.5C60-9 64-15 69-23" fill="none" stroke="' . $p['fill'] . '" stroke-width=".5"/>';
	}
	$out .= '<path fill-rule="evenodd" d="M30 6C36 2 46 0 58-2 56 6 54 14 46 18 40 21 34 18 30 14 26 18 20 21 14 18 6 14 4 6 2-2 14 0 24 2 30 6zM35 9C38 6 44 6 47 9 44 12 38 12 35 9zM25 9C22 6 16 6 13 9 16 12 22 12 25 9z" fill="' . $p['fill'] . '" stroke="' . $p['trim'] . '" stroke-width=".9"/>'
		. '<g fill="' . $p['trim'] . '"><circle cx="10" cy="1.2" r=".7"/><circle cx="17" cy="2.4" r=".7"/><circle cx="23" cy="4.2" r=".7"/><circle cx="37" cy="4.2" r=".7"/><circle cx="43" cy="2.4" r=".7"/><circle cx="50" cy="1.2" r=".7"/></g>'
		. '<circle cx="30" cy="9.4" r="1.7" fill="' . $p['ribbon'] . '" stroke="' . $p['trim'] . '" stroke-width=".5"/>';

	return $out;
}

/** The mask palettes: black masks with gold trim and pink ribbons for dark backdrops, all black on orange. */
function ulbd_mask_palette( $orange ) {
	return $orange
		? array(
			'fill'   => 'rgb(24,10,14)',
			'trim'   => 'rgb(255,222,170)',
			'ribbon' => 'rgb(24,10,14)',
			'light'  => 'rgb(255,236,200)',
			'star'   => 'rgb(255,236,200)',
			'a'      => .8,
		)
		: array(
			'fill'   => 'rgb(18,6,20)',
			'trim'   => 'rgb(232,196,110)',
			'ribbon' => 'rgb(235,120,185)',
			'light'  => 'rgb(255,200,120)',
			'star'   => 'rgb(255,230,190)',
			'a'      => .9,
		);
}

/** The masquerade tile (300 x 260): a large mask with ribbons and a feather, one on a stick, a small one, bats and stars. */
function ulbd_masquerade_svg( $p, $k ) {
	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="300" height="260" viewBox="0 0 300 260">
			<g opacity="%1$s">
				<g transform="translate(36 60) rotate(-8 30 10) scale(1.5)">%2$s</g>
				<g transform="translate(170 150) rotate(10 30 10) scale(1.1)">%3$s</g>
				<g transform="translate(206 26) rotate(14 30 10) scale(.7)">%4$s</g>
				<path d="%5$s" transform="translate(48 192) rotate(-8 12 7) scale(1.2)" fill="%6$s"/>
				<path d="%5$s" transform="translate(262 110) rotate(12 12 7) scale(.8)" fill="%6$s"/>
			</g>
			<g fill="%7$s" fill-opacity="%8$s">
				<circle cx="150" cy="20" r="1.3"/><circle cx="20" cy="130" r="1"/><circle cx="140" cy="236" r="1.2"/><circle cx="286" cy="200" r=".9"/><circle cx="120" cy="130" r=".8"/>
			</g>
		</svg>',
		min( 1, $p['a'] * $k ),
		ulbd_mask_svg( $p, true, true ),
		ulbd_mask_svg( $p, false, false, true ),
		ulbd_mask_svg( $p ),
		ulbd_bat_path(),
		$p['fill'],
		$p['star'],
		min( 1, .8 * $k )
	);
}

/**
 * One large, intricate masquerade mask for the centre of the area (720 x 340): a spider web spun across it -
 * spokes from the nose bridge and sagging rings, clipped to the mask so the eye holes stay open - trimmed eye
 * rims, scalloped lace along the edges, filigree swirls at the tips, a jewel, feather plumes rising from both
 * tips and a little spider hanging from a thread. Drawn in the small mask's units, scaled up 9x.
 */
function ulbd_web_mask_svg( $p, $k ) {
	$shape = 'M30 6C36 2 46 0 58-2 56 6 54 14 46 18 40 21 34 18 30 14 26 18 20 21 14 18 6 14 4 6 2-2 14 0 24 2 30 6z';
	$holes = 'M35 9C38 6 44 6 47 9 44 12 38 12 35 9zM25 9C22 6 16 6 13 9 16 12 22 12 25 9z';

	// The web: 16 spokes from the bridge, and rings stretched sideways to reach the tips, each strand between
	// two spokes sagging towards the centre.
	$cx    = 30;
	$cy    = 10;
	$n     = 16;
	$spoke = '';
	$rings = '';
	$pt    = function ( $r, $i ) use ( $cx, $cy, $n ) {
		$a = 2 * M_PI * $i / $n;
		return array( $cx + 1.6 * $r * cos( $a ), $cy + $r * sin( $a ) );
	};
	for ( $i = 0; $i < $n; $i++ ) {
		list( $x, $y ) = $pt( 24, $i );
		$spoke        .= sprintf( 'M%d %d %.2f %.2f', $cx, $cy, $x, $y );
	}
	foreach ( array( 2.4, 4.6, 7, 9.6, 12.4, 15.4, 18.6, 22 ) as $r ) {
		list( $x0, $y0 ) = $pt( $r, 0 );
		$rings          .= sprintf( 'M%.2f %.2f', $x0, $y0 );
		for ( $i = 1; $i <= $n; $i++ ) {
			list( $xa, $ya ) = $pt( $r, $i - 1 );
			list( $xb, $yb ) = $pt( $r, $i );
			$qx              = $cx + ( ( $xa + $xb ) / 2 - $cx ) * .86;
			$qy              = $cy + ( ( $ya + $yb ) / 2 - $cy ) * .86;
			$rings          .= sprintf( 'Q%.2f %.2f %.2f %.2f', $qx, $qy, $xb, $yb );
		}
	}

	// Lace scallops along the lower edge, as small circles.
	$lace = '';
	foreach ( array( array( 8, 13 ), array( 11, 16 ), array( 15, 18.6 ), array( 20, 20 ), array( 25, 18 ), array( 35, 18 ), array( 40, 20 ), array( 45, 18.6 ), array( 49, 16 ), array( 52, 13 ) ) as $c ) {
		$lace .= sprintf( '<circle cx="%s" cy="%s" r=".9"/>', $c[0], $c[1] + .6 );
	}

	$plume = 'M0 0C2-7 6-12 12-16 9-9 6-4 2 1z';
	$spider_legs = 'M-1-.4-3.2-2.2M-1 .2-3.6-.4M-1 .6-3.4 1.8M-.8 1-2.4 3.2M1-.4 3.2-2.2M1 .2 3.6-.4M1 .6 3.4 1.8M.8 1 2.4 3.2';

	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="720" height="340" viewBox="0 0 720 340">
			<defs>
				<clipPath id="m"><path clip-rule="evenodd" d="%1$s%2$s"/></clipPath>
				<radialGradient id="j"><stop offset="0" stop-color="%5$s" stop-opacity=".7"/><stop offset="1" stop-color="%5$s" stop-opacity="0"/></radialGradient>
			</defs>
			<g opacity="%9$s" transform="translate(90 100) scale(9)">
				<g fill="%6$s">
					<path d="%10$s" transform="translate(53 -1) scale(.6)"/>
					<path d="%10$s" transform="translate(56 0) rotate(18) scale(.6)" opacity=".7"/>
					<path d="%10$s" transform="translate(7 -1) scale(-.6 .6)"/>
					<path d="%10$s" transform="translate(4 0) scale(-1 1) rotate(18) scale(.6)" opacity=".7"/>
				</g>
				<path fill-rule="evenodd" d="%1$s%2$s" fill="%3$s"/>
				<g clip-path="url(#m)" fill="none" stroke="%4$s" stroke-width=".16" stroke-opacity=".85"><path d="%7$s"/><path d="%8$s"/></g>
				<path d="%1$s" fill="none" stroke="%4$s" stroke-width=".55"/>
				<path d="%2$s" fill="none" stroke="%4$s" stroke-width=".45"/>
				<g fill="%4$s">%11$s</g>
				<g fill="none" stroke="%4$s" stroke-width=".35" stroke-linecap="round">
					<path d="M8 2.2c-2.4 0-3.2 2.6-1.4 3.4s2.8-1.4 1.2-2"/>
					<path d="M52 2.2c2.4 0 3.2 2.6 1.4 3.4s-2.8-1.4-1.2-2"/>
				</g>
				<circle cx="30" cy="9" r="4" fill="url(#j)"/>
				<path d="M30 6.4 31.8 9 30 11.6 28.2 9z" fill="%6$s" stroke="%4$s" stroke-width=".3"/>
				<path d="M50 17.4V22" stroke="%4$s" stroke-width=".12"/>
				<g transform="translate(50 23.4)" fill="%3$s" stroke="%4$s" stroke-width=".18">
					<path d="%12$s" fill="none" stroke-linecap="round"/>
					<ellipse cx="0" cy=".8" rx="1.1" ry="1.4"/><circle cx="0" cy="-.9" r=".7"/>
				</g>
			</g>
		</svg>',
		$shape,
		$holes,
		$p['fill'],
		$p['trim'],
		$p['light'],
		$p['ribbon'],
		$spoke,
		$rings,
		min( 1, $p['a'] * $k ),
		$plume,
		$lace,
		$spider_legs
	);
}

/** A sparse star field tile (160 x 160) for behind a single centred motif. */
function ulbd_stars_svg( $rgb, $k ) {
	return sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"><g fill="%s" fill-opacity="%s"><circle cx="20" cy="24" r="1.1"/><circle cx="110" cy="14" r=".8"/><circle cx="70" cy="80" r="1"/><circle cx="140" cy="100" r="1.2"/><circle cx="36" cy="136" r=".9"/></g></svg>',
		$rgb,
		min( 1, .7 * $k )
	);
}

/** A fishnet tile (18 x 18): two diagonals through the corners, which join up into a diamond net. */
function ulbd_fishnet_svg( $k ) {
	return sprintf( '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M0 0 18 18M18 0 0 18" stroke="rgb(8,2,8)" stroke-opacity="%s" stroke-width="1.4"/></svg>', min( 1, .7 * $k ) );
}

/** The pumpkin-orange base: a warm light centre for the text, deepening to burnt orange at the edges. */
function ulbd_orange_layers( $k ) {
	return array(
		'radial-gradient(ellipse 55% 60% at 50% 45%, ' . ulbd_rgba( 255, 196, 120, .55, $k ) . ' 0, transparent 100%)',
		'radial-gradient(ellipse 45% 55% at 0% 0%, ' . ulbd_rgba( 190, 70, 10, .5, $k ) . ' 0, transparent 100%)',
		'radial-gradient(ellipse 45% 55% at 100% 100%, ' . ulbd_rgba( 190, 70, 10, .5, $k ) . ' 0, transparent 100%)',
		'#f08022',
	);
}

/**
 * All styles, keyed by id: array( label, kind, description, css callable, optional dark and fade flags -
 * dark off and fade on when left out).
 * Kinds group the picker: 'css' (gradients / patterns), 'svg' (SVG images), 'animated', 'plain', 'dark',
 * and one per holiday - 'halloween', 'thanksgiving', 'christmas', 'new-year' (dark or light).
 */
function ulbd_styles() {
	$base  = '#f6f1fa';
	$night = '#06060f';

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
		'night-aurora' => array(
			'label'       => 'Night aurora (live site)',
			'kind'        => 'dark',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Purple and teal glows on near-black - the backdrop on the live site today.',
			'css'         => function ( $k ) use ( $night ) {
				return array( 'background' => implode( ', ', array_merge( ulbd_night_layers( $k ), array( $night ) ) ) );
			},
		),
		'night-drift'  => array(
			'label'       => 'Drifting night aurora',
			'kind'        => 'dark',
			'dark'        => true,
			'fade'        => false,
			'description' => 'The night aurora, oversized and slowly drifting (a 40s loop).',
			'css'         => function ( $k ) use ( $night ) {
				return array(
					'background' => implode( ', ', array_merge( ulbd_night_layers( $k ), array( $night ) ) ),
					'size'       => '160% 160%',
					'animation'  => 'ulbd-night-drift 40s ease-in-out infinite alternate',
					'keyframes'  => '0% { background-position: 0% 0%; } 50% { background-position: 100% 40%; } 100% { background-position: 30% 100%; }',
				);
			},
		),
		'night-mesh'   => array(
			'label'       => 'Night mesh',
			'kind'        => 'dark',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Purple, teal and violet colour fields from the corners, on near-black.',
			'css'         => function ( $k ) use ( $night ) {
				return array(
					'background' => implode(
						', ',
						array(
							'radial-gradient(at 0% 0%, ' . ulbd_rgba( 150, 70, 170, .7, $k ) . ' 0, transparent 55%)',
							'radial-gradient(at 100% 0%, ' . ulbd_rgba( 60, 135, 175, .6, $k ) . ' 0, transparent 55%)',
							'radial-gradient(at 100% 100%, ' . ulbd_rgba( 95, 65, 165, .65, $k ) . ' 0, transparent 55%)',
							'radial-gradient(at 0% 100%, ' . ulbd_rgba( 60, 110, 195, .55, $k ) . ' 0, transparent 55%)',
							$night,
						)
					),
				);
			},
		),
		'halloween'    => array(
			'label'       => 'Halloween',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'A harvest moon, bats and stars over pumpkin-orange and purple glows on near-black.',
			'css'         => function ( $k ) {
				$bat = ulbd_bat_path();
				$svg = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="240" height="200" viewBox="0 0 240 200">
						<path d="%1$s" transform="translate(28 40) rotate(-10 12 7) scale(2.2)" fill="rgb(92,52,120)" fill-opacity="%2$s"/>
						<path d="%1$s" transform="translate(150 120) rotate(12 12 7) scale(1.6)" fill="rgb(92,52,120)" fill-opacity="%2$s"/>
						<circle cx="120" cy="28" r="1.4" fill="rgb(255,230,190)" fill-opacity="%3$s"/>
						<circle cx="206" cy="66" r="1" fill="rgb(255,230,190)" fill-opacity="%3$s"/>
						<circle cx="66" cy="150" r="1.2" fill="rgb(255,230,190)" fill-opacity="%3$s"/>
						<circle cx="190" cy="182" r=".9" fill="rgb(255,230,190)" fill-opacity="%3$s"/>
					</svg>',
					$bat,
					min( 1, .75 * $k ),
					min( 1, .8 * $k )
				);

				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( $svg ),
							'radial-gradient(circle 150px at 84% 26%, ' . ulbd_rgba( 255, 236, 190, .95, $k ) . ' 0 38px, ' . ulbd_rgba( 255, 170, 80, .35, $k ) . ' 42px, transparent 150px)',
							'radial-gradient(ellipse 70% 55% at 50% 110%, ' . ulbd_rgba( 255, 120, 30, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 60% at 8% 30%, ' . ulbd_rgba( 120, 50, 170, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 35% 45% at 66% 0%, ' . ulbd_rgba( 90, 40, 150, .5, $k ) . ' 0, transparent 100%)',
							'#0e0716',
						)
					),
					'size'       => '240px 200px, auto, auto, auto, auto, auto',
				);
			},
		),
		'halloween-orange'        => array(
			'label'       => 'Halloween - orange & bats',
			'kind'        => 'halloween',
			'fade'        => false,
			'description' => 'Black bats of a few sizes swooping over pumpkin orange.',
			'css'         => function ( $k ) {
				$svg = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="280" height="220" viewBox="0 0 280 220">
						<g fill="rgb(24,10,14)" fill-opacity="%2$s">
							<path d="%1$s" transform="translate(24 30) rotate(-10 12 7) scale(2.4)"/>
							<path d="%1$s" transform="translate(170 22) rotate(8 12 7) scale(1.2)"/>
							<path d="%1$s" transform="translate(196 120) rotate(14 12 7) scale(1.8)"/>
							<path d="%1$s" transform="translate(70 150) rotate(-6 12 7) scale(1)"/>
							<path d="%1$s" transform="translate(120 92) rotate(-18 12 7) scale(.7)"/>
						</g>
					</svg>',
					ulbd_bat_path(),
					min( 1, .85 * $k )
				);

				return array(
					'background' => implode( ', ', array_merge( array( ulbd_svg_url( $svg ) ), ulbd_orange_layers( $k ) ) ),
					'size'       => '280px 220px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-witchy'        => array(
			'label'       => 'Halloween - witchy',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Witches\' hats, brooms, bubbling cauldrons and bats over a green cauldron glow on near-black.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( ulbd_witchy_svg( ulbd_witchy_palette( false ), false, $k ) ),
							'radial-gradient(ellipse 60% 50% at 50% 110%, ' . ulbd_rgba( 90, 200, 90, .35, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 60% at 8% 30%, ' . ulbd_rgba( 120, 50, 170, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 35% 45% at 88% 10%, ' . ulbd_rgba( 255, 120, 30, .3, $k ) . ' 0, transparent 100%)',
							'#0e0716',
						)
					),
					'size'       => '320px 280px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-witchy-orange' => array(
			'label'       => 'Halloween - witchy orange',
			'kind'        => 'halloween',
			'fade'        => false,
			'description' => 'Black witches\' hats, brooms, cauldrons and bats on pumpkin orange.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode( ', ', array_merge( array( ulbd_svg_url( ulbd_witchy_svg( ulbd_witchy_palette( true ), false, $k ) ) ), ulbd_orange_layers( $k ) ) ),
					'size'       => '320px 280px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-cheeky'        => array(
			'label'       => 'Halloween - cheeky',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Witchy, with a wink: wand-tipped brooms, a cauldron in a leather harness, heart bubbles, a studded hat and fuzzy cuffs, on deep plum.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( ulbd_witchy_svg( ulbd_witchy_palette( false ), true, $k ) ),
							'radial-gradient(ellipse 60% 50% at 50% 110%, ' . ulbd_rgba( 179, 9, 75, .45, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 60% at 8% 30%, ' . ulbd_rgba( 120, 50, 170, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 35% 45% at 88% 10%, ' . ulbd_rgba( 255, 120, 30, .3, $k ) . ' 0, transparent 100%)',
							'#120614',
						)
					),
					'size'       => '320px 280px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-cheeky-orange' => array(
			'label'       => 'Halloween - cheeky orange',
			'kind'        => 'halloween',
			'fade'        => false,
			'description' => 'The cheeky witchy tile in black on pumpkin orange.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode( ', ', array_merge( array( ulbd_svg_url( ulbd_witchy_svg( ulbd_witchy_palette( true ), true, $k ) ) ), ulbd_orange_layers( $k ) ) ),
					'size'       => '320px 280px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-cheeky-masks'  => array(
			'label'       => 'Halloween - cheeky masquerade',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'The cheeky witchy tile with masquerade masks joining the wand-tipped brooms, harnessed cauldron and fuzzy cuffs, on deep plum.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( ulbd_witchy_svg( ulbd_witchy_palette( false ), true, $k, true ) ),
							'radial-gradient(ellipse 60% 50% at 50% 110%, ' . ulbd_rgba( 179, 9, 75, .45, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 60% at 8% 30%, ' . ulbd_rgba( 120, 50, 170, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 35% 45% at 88% 10%, ' . ulbd_rgba( 255, 120, 30, .3, $k ) . ' 0, transparent 100%)',
							'#120614',
						)
					),
					'size'       => '320px 280px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-cheeky-masks-orange' => array(
			'label'       => 'Halloween - cheeky masquerade orange',
			'kind'        => 'halloween',
			'fade'        => false,
			'description' => 'The cheeky masquerade tile in black on pumpkin orange.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode( ', ', array_merge( array( ulbd_svg_url( ulbd_witchy_svg( ulbd_witchy_palette( true ), true, $k, true ) ) ), ulbd_orange_layers( $k ) ) ),
					'size'       => '320px 280px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-masquerade'    => array(
			'label'       => 'Halloween - masquerade',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Black lace masquerade masks trimmed in gold, with pink ribbons and feathers, bats and stars on deep plum.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( ulbd_masquerade_svg( ulbd_mask_palette( false ), $k ) ),
							'radial-gradient(ellipse 45% 60% at 10% 25%, ' . ulbd_rgba( 140, 60, 170, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 50% at 90% 80%, ' . ulbd_rgba( 179, 9, 75, .45, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 40% at 70% 0%, ' . ulbd_rgba( 232, 196, 110, .22, $k ) . ' 0, transparent 100%)',
							'#24102a',
						)
					),
					'size'       => '300px 260px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-masquerade-orange' => array(
			'label'       => 'Halloween - masquerade orange',
			'kind'        => 'halloween',
			'fade'        => false,
			'description' => 'Black masquerade masks with ribbons and feathers, and bats, on pumpkin orange.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode( ', ', array_merge( array( ulbd_svg_url( ulbd_masquerade_svg( ulbd_mask_palette( true ), $k ) ) ), ulbd_orange_layers( $k ) ) ),
					'size'       => '300px 260px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-fishnet'       => array(
			'label'       => 'Halloween - fishnet masquerade',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Masquerade masks over a black fishnet on hot pink and plum glows.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( ulbd_masquerade_svg( ulbd_mask_palette( false ), $k ) ),
							ulbd_svg_url( ulbd_fishnet_svg( $k ) ),
							'radial-gradient(ellipse 50% 60% at 15% 30%, ' . ulbd_rgba( 200, 20, 100, .75, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 50% 60% at 85% 75%, ' . ulbd_rgba( 179, 9, 75, .7, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 40% at 60% 10%, ' . ulbd_rgba( 120, 40, 160, .5, $k ) . ' 0, transparent 100%)',
							'#1a0612',
						)
					),
					'size'       => '300px 260px, 18px 18px, auto, auto, auto, auto',
				);
			},
		),
		'halloween-web-mask'      => array(
			'label'       => 'Halloween - spider web mask',
			'kind'        => 'halloween',
			'dark'        => true,
			'fade'        => false,
			'description' => 'One large masquerade mask in the centre, spun with a gold spider web, with lace, filigree, feathers, a jewel and a dangling spider, over stars on deep plum.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( ulbd_web_mask_svg( ulbd_mask_palette( false ), $k ) ),
							ulbd_svg_url( ulbd_stars_svg( 'rgb(255,230,190)', $k ) ),
							'radial-gradient(ellipse 45% 55% at 50% 50%, ' . ulbd_rgba( 140, 60, 170, .5, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 50% at 95% 100%, ' . ulbd_rgba( 179, 9, 75, .4, $k ) . ' 0, transparent 100%)',
							'#1a0a20',
						)
					),
					'size'       => 'min(94%, 720px) auto, 160px 160px, auto, auto, auto',
					'position'   => '50% 50%, 0 0, 0 0, 0 0, 0 0',
					'repeat'     => 'no-repeat, repeat, no-repeat, no-repeat, no-repeat',
				);
			},
		),
		'halloween-web-mask-orange' => array(
			'label'       => 'Halloween - spider web mask orange',
			'kind'        => 'halloween',
			'fade'        => false,
			'description' => 'The spider web mask in black and cream, centred on pumpkin orange.',
			'css'         => function ( $k ) {
				return array(
					'background' => implode( ', ', array_merge( array( ulbd_svg_url( ulbd_web_mask_svg( ulbd_mask_palette( true ), $k ) ) ), ulbd_orange_layers( $k ) ) ),
					'size'       => 'min(94%, 720px) auto, auto, auto, auto, auto',
					'position'   => '50% 50%, 0 0, 0 0, 0 0, 0 0',
					'repeat'     => 'no-repeat',
				);
			},
		),
		'thanksgiving' => array(
			'label'       => 'Thanksgiving',
			'kind'        => 'thanksgiving',
			'description' => 'Scattered autumn leaves in rust, amber, gold and plum over warm harvest glows on cream.',
			'css'         => function ( $k ) {
				$leaf = 'M12 1c6 5 8 13 0 22C4 14 6 6 12 1z';
				$svg  = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
						<g fill-opacity="%2$s" stroke-opacity="%3$s" stroke-width="1" stroke-linecap="round">
							<path d="%1$s" transform="translate(22 20) rotate(-30 12 12) scale(1.1)" fill="rgb(196,86,44)"/>
							<path d="M12 4v19" transform="translate(22 20) rotate(-30 12 12) scale(1.1)" stroke="rgb(196,86,44)"/>
							<path d="%1$s" transform="translate(128 44) rotate(40 12 12) scale(.8)" fill="rgb(226,150,60)"/>
							<path d="M12 4v19" transform="translate(128 44) rotate(40 12 12) scale(.8)" stroke="rgb(226,150,60)"/>
							<path d="%1$s" transform="translate(70 120) rotate(15 12 12)" fill="rgb(214,176,70)"/>
							<path d="M12 4v19" transform="translate(70 120) rotate(15 12 12)" stroke="rgb(214,176,70)"/>
							<path d="%1$s" transform="translate(160 150) rotate(-60 12 12) scale(.7)" fill="rgb(150,70,110)"/>
							<path d="M12 4v19" transform="translate(160 150) rotate(-60 12 12) scale(.7)" stroke="rgb(150,70,110)"/>
						</g>
					</svg>',
					$leaf,
					min( 1, .28 * $k ),
					min( 1, .45 * $k )
				);

				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( $svg ),
							'radial-gradient(ellipse 60% 60% at 50% 45%, rgba(255, 255, 255, .6) 0, transparent 100%)',
							'radial-gradient(ellipse 45% 55% at 12% 20%, ' . ulbd_rgba( 240, 170, 90, .45, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 50% at 90% 72%, ' . ulbd_rgba( 210, 110, 70, .35, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 45% 40% at 55% 0%, ' . ulbd_rgba( 240, 205, 130, .4, $k ) . ' 0, transparent 100%)',
							'#fbf5ec',
						)
					),
					'size'       => '200px 200px, auto, auto, auto, auto, auto',
				);
			},
		),
		'christmas'    => array(
			'label'       => 'Christmas',
			'kind'        => 'christmas',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Slowly falling snow over red and gold glows on deep pine green (a 60s loop).',
			'css'         => function ( $k ) {
				// Two snow tiles falling at different speeds, for depth. Each moves a whole number of tiles per
				// loop, so the loop is seamless.
				$dots  = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
						<g fill="rgb(255,255,255)" fill-opacity="%s">
							<circle cx="20" cy="30" r="1.6"/><circle cx="90" cy="12" r="1.2"/><circle cx="160" cy="48" r="1.8"/>
							<circle cx="56" cy="96" r="1.3"/><circle cx="130" cy="120" r="1.6"/><circle cx="186" cy="150" r="1.1"/>
							<circle cx="30" cy="170" r="1.5"/><circle cx="100" cy="186" r="1.2"/>
						</g>
					</svg>',
					min( 1, .6 * $k )
				);
				$flake = 'M0-9V9M-7.8-4.5L7.8 4.5M-7.8 4.5L7.8-4.5';
				$flakes = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300">
						<g fill="none" stroke="rgb(255,255,255)" stroke-opacity="%2$s" stroke-width="1.4" stroke-linecap="round">
							<path d="%1$s" transform="translate(60 70)"/>
							<path d="%1$s" transform="translate(210 40) scale(.7)"/>
							<path d="%1$s" transform="translate(160 200) scale(1.2)"/>
							<path d="%1$s" transform="translate(40 250) scale(.8)"/>
						</g>
					</svg>',
					$flake,
					min( 1, .45 * $k )
				);

				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( $dots ),
							ulbd_svg_url( $flakes ),
							'radial-gradient(ellipse 40% 55% at 10% 25%, ' . ulbd_rgba( 190, 30, 50, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 35% 45% at 85% 20%, ' . ulbd_rgba( 245, 190, 110, .26, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 60% 50% at 60% 100%, ' . ulbd_rgba( 40, 130, 80, .5, $k ) . ' 0, transparent 100%)',
							'#0b1d16',
						)
					),
					'size'       => '200px 200px, 300px 300px, auto, auto, auto, auto',
					'animation'  => 'ulbd-christmas 60s linear infinite',
					'keyframes'  => '0% { background-position: 0 0, 0 0, 0 0, 0 0, 0 0; } 100% { background-position: 200px 400px, 0 300px, 0 0, 0 0, 0 0; }',
				);
			},
		),
		'new-year'     => array(
			'label'       => 'New Year\'s',
			'kind'        => 'new-year',
			'dark'        => true,
			'fade'        => false,
			'description' => 'Gold, lavender and pink confetti with sparkles over gold and violet glows on midnight blue.',
			'css'         => function ( $k ) {
				$spark = 'M12 0l2 10 10 2-10 2-2 10-2-10-10-2 10-2z';
				$svg   = sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180">
						<g fill-opacity="%2$s">
							<rect x="20" y="24" width="10" height="4" rx="1" transform="rotate(30 25 26)" fill="rgb(232,196,110)"/>
							<rect x="120" y="18" width="8" height="4" rx="1" transform="rotate(-40 124 20)" fill="rgb(177,101,255)"/>
							<rect x="80" y="84" width="10" height="4" rx="1" transform="rotate(70 85 86)" fill="rgb(235,150,195)"/>
							<rect x="150" y="110" width="9" height="4" rx="1" transform="rotate(15 154 112)" fill="rgb(232,196,110)"/>
							<rect x="30" y="140" width="8" height="4" rx="1" transform="rotate(-20 34 142)" fill="rgb(170,225,232)"/>
							<circle cx="60" cy="60" r="2" fill="rgb(235,150,195)"/>
							<circle cx="110" cy="160" r="2" fill="rgb(177,101,255)"/>
						</g>
						<g fill="rgb(255,236,190)" fill-opacity="%3$s">
							<path d="%1$s" transform="translate(150 50) scale(.55)"/>
							<path d="%1$s" transform="translate(52 100) scale(.4)"/>
						</g>
					</svg>',
					$spark,
					min( 1, .65 * $k ),
					min( 1, .85 * $k )
				);

				return array(
					'background' => implode(
						', ',
						array(
							ulbd_svg_url( $svg ),
							'radial-gradient(ellipse 60% 50% at 50% 105%, ' . ulbd_rgba( 230, 185, 90, .4, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 40% 55% at 12% 20%, ' . ulbd_rgba( 130, 80, 200, .55, $k ) . ' 0, transparent 100%)',
							'radial-gradient(ellipse 35% 45% at 88% 15%, ' . ulbd_rgba( 60, 80, 180, .45, $k ) . ' 0, transparent 100%)',
							'#0a0a1f',
						)
					),
					'size'       => '180px 180px, auto, auto, auto, auto',
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
