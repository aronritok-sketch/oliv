<?php
/**
 * Markup helpers shared by the templates (photos, icons, buttons, page heads, name card).
 */

defined( 'ABSPATH' ) || exit;

const OY_ACCENTS = array( 'lilac', 'pink', 'sun', 'sage' );

function oy_accent_for( $seed ) {
	$sum = 0;
	foreach ( str_split( (string) $seed ) as $ch ) {
		$sum += ord( $ch );
	}
	return OY_ACCENTS[ $sum % count( OY_ACCENTS ) ];
}

function oy_images() {
	static $imgs = null;
	if ( null === $imgs ) {
		$imgs = json_decode( (string) file_get_contents( get_template_directory() . '/assets/img/images.json' ), true ) ?: array();
	}
	return $imgs;
}

/**
 * A theme photo's alt text in the visitor's language. The online edition is Hungarian-only, so
 * Olivia's name follows the Hungarian order there (Kovács Olívia), as on her own Hungarian site.
 */
function oy_alt( $alt ) {
	$alt = oy_t( $alt );
	if ( oy_online_only() ) {
		$alt = strtr( $alt, array( 'Olivia Kovacsról' => 'Kovács Olíviáról', 'Olivia Kovacs-szal' => 'Kovács Olíviával', 'Olivia Kovacs' => 'Kovács Olívia' ) );
	}
	return $alt;
}

/**
 * A bundled photo's file name without the size: the online edition's build gives each photo its Hungarian name
 * (images.json „file”), elsewhere it is the key.
 */
function oy_image_file( $key ) {
	$m = oy_images()[ $key ] ?? array();
	return (string) ( $m['file'] ?? $key );
}

/**
 * A bundled photo's alt text. On the online edition its own Hungarian text with the keywords (images.json „hu”; Olívia,
 * 14 Nov: „az alt textek … angolul vannak. legyen mindegyik magyar és az összes releváns kulcsszó legyen benne”),
 * elsewhere the English text in the visitor's language.
 */
function oy_image_alt( $key ) {
	$m = oy_images()[ $key ] ?? array();
	if ( oy_online_only() && ! empty( $m['hu']['alt'] ) ) {
		return (string) $m['hu']['alt'];
	}
	$alt = (string) ( $m['alt'] ?? '' );
	return '' === $alt ? '' : oy_alt( $alt );
}

/** The name a bundled photo gets in the media library: on the online edition the Hungarian one, accents removed. */
function oy_image_slug( $key ) {
	$m = oy_images()[ $key ] ?? array();
	return oy_online_only() && ! empty( $m['hu']['file'] ) ? (string) $m['hu']['file'] : (string) $key;
}

/**
 * The page editor (inc/page-editor.php) names a web copy of a photo Olívia puts on a page after the text of the place it
 * went to: the bundled photo's alt text (data-oyp is its key), or the media library photo's own.
 */
add_filter( 'oye_slot_alt', function ( $alt, $key ) {
	$k = substr( (string) $key, 2 );
	if ( '' !== (string) $alt ) {
		return $alt;
	}
	if ( isset( oy_images()[ $k ] ) ) {
		return oy_image_alt( $k );
	}
	return preg_match( '/^a(\d+)$/', $k, $m ) ? (string) get_post_meta( (int) $m[1], '_wp_attachment_image_alt', true ) : $alt;
}, 10, 2 );

/**
 * Photos Olívia uploaded herself, described by us after looking at them (the content file's „uploads”, by the file's
 * place in the media library: 2026/11/IMG_7309.jpg). Her own alt text or the place's text would not fit them.
 */
function oy_upload_entry( $id ) {
	static $cache = array();
	$file = oy_content_file();
	if ( ! isset( $cache[ $file ] ) ) {
		$data           = json_decode( (string) file_get_contents( $file ), true );
		$cache[ $file ] = array_change_key_case( (array) ( $data['uploads'] ?? array() ), CASE_LOWER );
	}
	$all = $cache[ $file ];
	if ( ! $all ) {
		return null;
	}
	$rel = strtolower( (string) preg_replace( '/-scaled(\.\w+)$/', '$1', (string) get_post_meta( (int) $id, '_wp_attached_file', true ) ) );
	return $all[ $rel ] ?? null;
}
add_filter( 'oye_image_alt', fn( $alt, $id ) => (string) ( oy_upload_entry( $id )['alt'] ?? $alt ), 10, 2 );
add_filter( 'oye_image_name', fn( $name, $id ) => (string) ( oy_upload_entry( $id )['file'] ?? $name ), 10, 2 );

/**
 * A photo from the theme's bundled set (by key) or the media library (attachment id).
 */
function oy_photo( $src, $sizes = '100vw', $eager = false, $alt = null ) {
	if ( is_numeric( $src ) && (int) $src > 0 ) {
		$attrs = array( 'class' => 'photo', 'sizes' => $sizes, 'decoding' => 'async' );
		if ( $eager ) {
			$attrs['fetchpriority'] = 'high';
			$attrs['loading']       = 'eager';
		}
		if ( null !== $alt ) {
			$attrs['alt'] = $alt;
		} else {
			// A theme photo whose alt text is still the original (or none): show it in the visitor's language.
			$key  = (string) get_post_meta( (int) $src, 'oy_key', true );
			$imgs = $key ? oy_images() : array();
			$own  = (string) get_post_meta( (int) $src, '_wp_attachment_image_alt', true );
			if ( isset( $imgs[ $key ] ) && ( '' === $own || $own === (string) $imgs[ $key ]['alt'] ) ) {
				$attrs['alt'] = oy_image_alt( $key );
			}
		}
		$pos = get_post_meta( (int) $src, 'oy_pos', true );
		if ( $pos ) {
			$attrs['style'] = 'object-position:' . esc_attr( $pos );
		}
		$attrs['data-oyp'] = (string) get_post_meta( (int) $src, 'oy_key', true ) ?: 'a' . (int) $src; // the page editor's key
		return wp_get_attachment_image( (int) $src, 'large', false, $attrs );
	}
	$imgs = oy_images();
	if ( empty( $imgs[ $src ] ) ) {
		return '';
	}
	$m    = $imgs[ $src ];
	$base = get_template_directory_uri() . '/assets/img/' . oy_image_file( $src );
	$alt  = null === $alt ? oy_image_alt( $src ) : $alt;
	return sprintf(
		'<img class="photo" src="%1$s-1200.jpg" srcset="%1$s-600.jpg %8$dw, %1$s-1200.jpg %2$dw" sizes="%3$s" alt="%4$s" width="%2$d" height="%5$d" style="object-position:%6$s" %7$s decoding="async" data-oyp="%9$s">',
		esc_url( $base ), (int) $m['w'], esc_attr( $sizes ), esc_attr( $alt ), (int) $m['h'], esc_attr( $m['pos'] ),
		$eager ? 'fetchpriority="high"' : 'loading="lazy"',
		min( 600, (int) $m['w'] ),
		esc_attr( $src )
	);
}

/** Featured image of a post, or a bundled fallback photo. */
function oy_post_photo( $post_id, $fallback = '', $sizes = '100vw', $eager = false, $alt = null ) {
	$thumb = get_post_thumbnail_id( $post_id );
	if ( $thumb ) {
		return oy_photo( $thumb, $sizes, $eager, $alt );
	}
	return $fallback ? oy_photo( $fallback, $sizes, $eager, $alt ) : '';
}

/**
 * Line icons. The interface ones (arrow, menu, mail…) are plain; the pictograms used as
 * illustrations (home, beach, lotus, bowl…) are drawn in a looser, hand-drawn line.
 */
function oy_icon( $name, $cls = 'icon' ) {
	static $ui = array(
		'clock'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		'play'      => '<circle cx="12" cy="12" r="8.5"/><path d="M10.2 8.6v6.8l5.3-3.4z"/>',
		'gift'      => '<rect x="3.5" y="8.5" width="17" height="12" rx="1.5"/><path d="M3.5 12.5h17M12 8.5v12M12 8.5C10.6 5 6.5 4.6 6.5 7c0 1.6 2.7 1.5 5.5 1.5zm0 0c1.4-3.5 5.5-3.9 5.5-1.5 0 1.6-2.7 1.5-5.5 1.5z"/>',
		'pin'       => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="10" r="2.3"/>',
		'mail'      => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.5 7 8.5 6.3L20.5 7"/>',
		'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6" fill="currentColor"/>',
		'facebook'  => '<path d="M14.5 8.5H17V4.8h-2.5a4 4 0 0 0-4 4v2.2H8v3.6h2.5v6.9h3.6v-6.9h2.6l.6-3.6h-3.2V9.3a.8.8 0 0 1 .4-.8z"/>',
		'calendar'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
		'menu'      => '<path d="M4 8h16M4 16h16"/>',
		'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'chev'      => '<path d="m6 9 6 6 6-6"/>',
		'check'     => '<path d="M5 12.8c1.6 1.2 3 2.6 4.2 4.4C11.6 12.4 15 8.6 19.4 6"/>',
		'arrow'     => '<path d="M4.5 12h15M13.5 6l6 6-6 6"/>',
		'user'      => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
		'globe'     => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.4 2.4 3.4 5.2 3.4 8.5s-1 6.1-3.4 8.5c-2.4-2.4-3.4-5.2-3.4-8.5s1-6.1 3.4-8.5z"/>',
		'leaf'      => '<path d="M5 19C5 10 11 5 20 4c0 9-5 15-14 15z"/><path d="M5 19 13 11"/>',
	);
	static $art = array(
		// Home: a roof line, an arched door and a plant at the step.
		'home'     => '<path d="M6.5 22.5C11 18.4 15.6 14.3 24 8.2c8 6 12.6 10.2 17.4 14.4"/><path d="M10.6 19.8c-.3 6.4-.2 12.6.2 19.4 7.6.4 15.6.4 26.2-.1.3-6.6.3-12.8 0-19.1"/><path d="M20.3 39.2v-7.6c0-2.4 1.6-4 3.7-4s3.7 1.6 3.7 4v7.6"/><path d="M34.4 39c-.4-3.6.6-6.4 3.4-8.2"/><path d="M34.6 35.2c-2.2-.2-3.6-1.2-4.2-3"/>',
		// Beach: a rising sun over two waves.
		'beach'    => '<path d="M14.6 25.6a9.4 9.4 0 0 1 18.8 0"/><path d="M24 10.4v3.4M13.4 14.6l2.3 2.3M34.6 14.6l-2.3 2.3M9 23.5h3M36 23.5h3"/><path d="M5.5 31.4c3 0 4.2-2.4 7.2-2.4s4.2 2.4 7.2 2.4 4.2-2.4 7.2-2.4 4.2 2.4 7.2 2.4 4.2-2.4 7.2-2.4"/><path d="M9.5 37.6c2.6 0 3.6-2 6.2-2s3.6 2 6.2 2 3.6-2 6.2-2 3.6 2 6.2 2"/>',
		// Clubhouse: a low building under a palm.
		'clubhouse' => '<path d="M8.5 40.5c9.8.4 21 .4 31 0"/><path d="M12.4 40.3V26.8c6.4-.4 13-.4 19.6 0v13.5"/><path d="M10 27.4c4.4-3.4 8.6-5.6 12.2-6.4 3.6.8 7.8 3 12.2 6.4"/><path d="M19.4 40.3v-6.4h5.2v6.4"/><path d="M37.6 40.4c-.6-8.8.2-15.8 2.4-21.6"/><path d="M40 18.8c-2.4-3.6-6-4.6-9.6-3.2M40 18.8c.6-3.8 3-6 6-6.4M40 18.8c3.4-.4 5.6 1.2 6.6 4"/>',
		// Online: a laptop with a seated figure on the screen.
		'online'   => '<path d="M9.6 31.6c-.4-6.6-.4-13 0-19.6 9.6-.4 19.2-.4 28.8 0 .4 6.6.4 13 0 19.6"/><path d="M5.5 36.2c12.4.6 24.6.6 37 0-.6-1.8-1.6-3.2-3.4-4.4-10.2-.4-20 -.4-30.2 0-1.8 1.2-2.8 2.6-3.4 4.4z"/><circle cx="24" cy="17.2" r="2.2"/><path d="M18.6 27.6c1.8-1.2 3.6-1.8 5.4-1.8s3.6.6 5.4 1.8M24 20v5.2M19.4 22.8c1.6.8 3 1.2 4.6 1.2s3-.4 4.6-1.2"/>',
		// Group: three figures, arms joined.
		'group'    => '<circle cx="24" cy="13.4" r="3.4"/><circle cx="12.4" cy="17.2" r="3"/><circle cx="35.6" cy="17.2" r="3"/><path d="M17.6 36.6c.4-6.6 2.8-12 6.4-12s6 5.4 6.4 12"/><path d="M6.4 37c.2-5.4 2.2-9.6 6-9.6 1.6 0 3 .8 4 2"/><path d="M41.6 37c-.2-5.4-2.2-9.6-6-9.6-1.6 0-3 .8-4 2"/>',
		// Lotus: meditation.
		'lotus'    => '<path d="M24 36.4c-3.8-3.6-5.6-8.2-5.6-13.2 0-3.2 1.8-6.8 5.6-11 3.8 4.2 5.6 7.8 5.6 11 0 5-1.8 9.6-5.6 13.2z"/><path d="M22.6 36.2c-5.2-.8-9.4-3.6-11.6-8.2-.8-1.8-1.2-3.8-1.2-5.8 3.8.6 6.8 2 8.8 4.2"/><path d="M25.4 36.2c5.2-.8 9.4-3.6 11.6-8.2.8-1.8 1.2-3.8 1.2-5.8-3.8.6-6.8 2-8.8 4.2"/><path d="M7.6 39.6c5.4-.6 10.6-1.6 16.4-3.2 5.8 1.6 11 2.6 16.4 3.2"/>',
		// Singing bowl with a mallet and sound waves.
		'bowl'     => '<path d="M9.4 25.4c.4 7.6 6.4 13 14.6 13s14.2-5.4 14.6-13c-9.6-.6-19.6-.6-29.2 0z"/><path d="M17 38.2c-.4 1.4-.4 2.4 0 3.4 4.6.4 9.4.4 14 0 .4-1 .4-2 0-3.4"/><path d="M30.6 21.2c2.6-4.6 5.8-8.4 9.6-11.4"/><path d="M17.8 19.4c-.8-2.2-.8-4.2.4-6.2M23.4 18.6c-1.2-2.8-1.2-5.6.6-8.4M29 19.4"/>',
		// Prenatal: a mother's profile around the belly, with a small heart.
		'prenatal' => '<circle cx="22.4" cy="9.6" r="3.4"/><path d="M20.6 14.4c-2.6 2.6-3.6 6-3.2 10.2.4 4.2 1.8 7.6 2.2 15.8"/><path d="M24.8 15.2c1.4 2.4 1.6 4.6 1.2 6.6 4.4 1.2 7.2 4.2 7.4 8.2.2 3.8-2.4 6.2-6.8 6.6-1.6.2-3 .2-4.2-.2"/><path d="M26.6 28.6c-.8-1.4-2.8-1.2-2.8.6 0 1.6 2.8 3 2.8 3s2.8-1.4 2.8-3c0-1.8-2-2-2.8-.6z"/>',
		// Mobility: a figure in a deep lunge reaching up.
		'mobility' => '<circle cx="27.4" cy="9.4" r="3.2"/><path d="M26.4 13.4c-1 4.2-1.6 8-1.8 12"/><path d="M26.2 15.6c2.8-2.6 5.6-5.8 8.2-9.6"/><path d="M26 16.4c-3.6-.8-6.8-2.6-9.2-5.4"/><path d="M24.6 25.4c3.6.4 6.4 1.8 8.2 4.4.4 3.2.4 6.6 0 10.2"/><path d="M24.4 25.6c-4.6 2.6-9.2 6.6-14 12.4"/><path d="M7 40.2c11.4.6 22.8.6 34 0"/>',
		// Massage: two hands resting on a back, with warmth lines.
		'massage'  => '<path d="M6.6 33.8c8.4-3.2 16.2-4.6 23.6-4.2 4.4.2 8.4 1 11.8 2.4"/><path d="M6.6 39.6c11.6.6 23.4.6 35 0"/><path d="M16.6 28.4c-.2-3 .6-5.6 2.8-7.4 1.8 1.6 2.4 3.6 1.6 6.2"/><path d="M26 27.4c-.2-3 .6-5.6 2.8-7.4 1.8 1.6 2.4 3.6 1.6 6.2"/><path d="M14.2 14.6c1-1.6 1-3 0-4.6M22.4 13c1-1.6 1-3 0-4.6M30.6 14.6c1-1.6 1-3 0-4.6"/>',
		// Teams: a sun with three figures under it.
		'team'     => '<path d="M17.4 14.4a6.6 6.6 0 0 1 13.2 0"/><path d="M24 4.4v2.4M14.8 8.2l1.6 1.6M33.2 8.2l-1.6 1.6"/><circle cx="14.6" cy="22.4" r="2.6"/><circle cx="24" cy="21.2" r="2.8"/><circle cx="33.4" cy="22.4" r="2.6"/><path d="M9.4 39.6c.2-5.6 2.2-10.6 5.2-10.6s5 5 5.2 10.6M18.8 39.6c.2-6.2 2.4-11.4 5.2-11.4s5 5.2 5.2 11.4M28.2 39.6c.2-5.6 2.2-10.6 5.2-10.6s5 5 5.2 10.6"/>',
		// Heart over an open hand (wellness, care).
		'care'     => '<path d="M24 22.8s-7.6-4.6-7.6-9.8c0-2.8 2-4.6 4.4-4.6 1.4 0 2.6.8 3.2 2 .6-1.2 1.8-2 3.2-2 2.4 0 4.4 1.8 4.4 4.6 0 5.2-7.6 9.8-7.6 9.8z"/><path d="M7.4 30.4c3.6-.2 6.4.6 8.6 2.4l4 .2c2 .2 2.4 2.6.4 3.2l-5.2.6"/><path d="M15.4 36.6c4.6 1.4 9.4 1.6 14.2.4 4-1.8 7.6-4.2 11-7.2.8-1.4-.2-2.8-1.8-2.4l-8.6 4.4"/>',
	);
	if ( isset( $art[ $name ] ) ) {
		return '<svg class="' . esc_attr( $cls . ' icon--art' ) . '" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $art[ $name ] . '</svg>';
	}
	return '<svg class="' . esc_attr( $cls ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $ui[ $name ] ?? '' ) . '</svg>';
}

/** A pictogram on a soft, hand-painted wash (no box). */
function oy_pictogram( $icon, $tone = 'lilac' ) {
	// Olívia, 14 November: „Piktogramokat szedjük ki mindenhonnan. Legyen letisztultabb” – none on the online site, in the
	// templates and in the page texts ([oy_picto]) alike
	if ( function_exists( 'oy_online_only' ) && oy_online_only() ) {
		return '';
	}
	return '<span class="picto picto--' . esc_attr( $tone ) . '" aria-hidden="true">' . oy_icon( $icon, 'picto__icon' ) . '</span>';
}

function oy_btn( $href, $label, $style = 'primary', $extra = '' ) {
	return '<a class="btn btn--' . esc_attr( $style ) . ( $extra ? ' ' . esc_attr( $extra ) : '' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
}

function oy_kicker( $text ) {
	// Online: no small line-and-capitals label over the headings (Olívia, 14 November: „ezt is szedjük ki … az összes
	// ilyet minden aloldalon is”)
	return oy_online_only() ? '' : '<p class="kicker">' . esc_html( $text ) . '</p>';
}

/**
 * The cancellation rule (the booking plugin's setting, so it is written in one place), for the online site's timetables,
 * class pages and request form (Olívia, 14 November: „a lemondási szabály jelenjen meg kicsivel több helyen”).
 */
/**
 * „Bejelentkezés és árak” on the online site, two parts of Olívia's text get a design when the page is shown (her own
 * text stays editable as plain paragraphs):
 * – „ezek az infok is legyenek már kicsit megdesignolva”: under the first title, the group class's facts become small
 *   round chips (day and time, length, live in the browser, camera optional) in a soft box; the sentences the chips
 *   repeat are left out of the box, the rest of her text stays in it;
 * – „ez a rész inkább legyen ugyanilyen”: the one-to-one part (the paragraph that links the one-to-one classes) becomes
 *   the same boxes as „Melyik órát válasszam?” on the classes page, taken from there without the group class.
 */
function oy_pricing_design( $html ) {
	// 1. the group class's facts
	if ( preg_match( '#^(\s*<h2[^>]*>.*?</h2>)((?:\s*<p(?:\s[^>]*)?>.*?</p>)+)#s', $html, $m ) ) {
		$class = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
		$len   = $class ? (string) oy_class_meta( $class->ID, 'oy_duration' ) : '';
		$facts = array_filter(
			array(
				array( 'calendar', __( 'Wednesdays, 18:30–20:00', 'olivia-yoga' ) ),
				array( 'clock', $len ),
				array( 'globe', __( 'live, in your browser', 'olivia-yoga' ) ),
				array( 'user', __( 'camera optional', 'olivia-yoga' ) ),
				// Olívia, 15 November: „ezek az infók is jöhetnek ide … a kamera nem kötelező mellé még fér” – what the note under
				// the timetable said, as chips
				array( 'check', __( 'book ahead', 'olivia-yoga' ) ),
				array( 'group', __( 'starts with at least 3 people', 'olivia-yoga' ) ),
				array( 'leaf', __( 'beginners welcome', 'olivia-yoga' ) ),
			),
			fn( $f ) => '' !== $f[1]
		);
		$chips = '<ul class="oj-info__facts">';
		foreach ( $facts as $f ) {
			$chips .= '<li>' . oy_icon( $f[0] ) . '<span>' . esc_html( $f[1] ) . '</span></li>';
		}
		$chips .= '</ul>';
		$paras = '';
		preg_match_all( '#<p(?:\s[^>]*)?>(.*?)</p>#s', $m[2], $ps );
		foreach ( $ps[1] as $i => $inner ) {
			$keep = array();
			foreach ( preg_split( '#(?<=[.!?])\s+#u', trim( $inner ) ) as $sentence ) {
				$plain = wp_strip_all_tags( $sentence );
				// what the chips say already: the time (only at the start), live in the browser, the camera
				if ( ( 0 === $i && ! $keep && preg_match( '#\d{1,2}:\d{2}#', $plain ) ) || preg_match( '#böngész|browser|kamer|camera#iu', $plain ) ) {
					continue;
				}
				$keep[] = $sentence;
			}
			$text = trim( implode( ' ', $keep ) );
			if ( '' !== trim( wp_strip_all_tags( $text ) ) ) {
				$paras .= '<p>' . $text . '</p>';
			}
		}
		$html = $m[1] . '<div class="oj-info">' . $chips . $paras . '</div>' . substr( $html, strlen( $m[0] ) );
	}
	// 1b. the gift vouchers as a card of their own (Olívia, 15 November: „ajándékutalványok a bejelentkezés és árak aloldalon
	// legyen szebben feltüntetve”) – her words and the button inside it
	$html = (string) preg_replace_callback(
		'#(<h2 id="gift-cards"[^>]*>.*?</h2>)((?:\s*<p(?:\s[^>]*)?>.*?</p>)+)#s',
		fn( $g ) => $g[1] . '<div class="oj-gift"><span class="oj-gift__icon" aria-hidden="true">' . oy_icon( 'gift' ) . '</span><div class="oj-gift__body">' . trim( $g[2] ) . '</div></div>',
		$html,
		1
	);
	// 2. the one-to-one classes as boxes
	$private = array_filter( array( 'egyeni-online-joga', oy_prenatal_slug(), 'stretching', 'relaxacio-legzes' ), fn( $s ) => (bool) get_page_by_path( $s, OBJECT, 'oy_class' ) );
	$classes = get_page_by_path( oy_page_slug( 'yoga-classes' ) );
	if ( count( $private ) < 2 || ! $classes || ! preg_match( '#<div class="oj-guide">.*?</div>#s', (string) $classes->post_content, $g ) ) {
		return $html;
	}
	$urls = array_map( 'oy_class_url', $private );
	$offset = 0;
	while ( preg_match( '#(<h2[^>]*>(?:(?!</h2>).)*</h2>)((?:\s*<p(?:\s[^>]*)?>.*?</p>)+)#s', $html, $pm, PREG_OFFSET_CAPTURE, $offset ) ) {
		$links = 0;
		foreach ( $urls as $u ) {
			$links += str_contains( $pm[2][0], 'href="' . untrailingslashit( $u ) ) ? 1 : 0;
		}
		if ( $links >= 2 ) {
			$guide = apply_filters( 'the_content', oy_resolve_tokens( $g[0] ) );
			$guide = preg_replace_callback(
				'#<a class="oj-guide__box[^"]*" href="([^"]+)".*?</a>\s*#s',
				fn( $a ) => in_array( untrailingslashit( $a[1] ), array_map( 'untrailingslashit', $urls ), true ) ? $a[0] : '',
				$guide
			);
			if ( substr_count( $guide, 'oj-guide__box' ) < 2 ) {
				return $html;
			}
			$box  = '<p class="oj-info__lead">' . esc_html__( 'Choose what you would like a time for, and I will get back to you soon. You will find the prices below.', 'olivia-yoga' ) . '</p>' . $guide;
			$html = substr( $html, 0, $pm[2][1] ) . $box . substr( $html, $pm[2][1] + strlen( $pm[2][0] ) );
			break;
		}
		$offset = $pm[0][1] + strlen( $pm[1][0] );
	}
	return $html;
}

/**
 * The designs built from the text of a page (oy_opening, oy_pricing_design) run on the finished page, after the page
 * editor has put Olívia's own edits in (inc/page-editor.php keys them by the original text, so changing the text before
 * would lose them): the template says which one, this buffer applies it to the main column. Not while she is editing,
 * so she edits her paragraphs as they are.
 */
function oy_main_transform_set( $kind, $arg = '' ) {
	$GLOBALS['oy_main_x'] = array( $kind, $arg );
}

function oy_main_transform( $html ) {
	$x = $GLOBALS['oy_main_x'] ?? array( '', '' );
	if ( ! oy_online_only() || ( function_exists( 'oye_editing' ) && oye_editing() ) ) {
		return $html;
	}
	$html = oy_answer_lines( $html );
	$tag  = '<div class="main-col entry">';
	$a    = strpos( $html, $tag );
	if ( false === $a ) {
		return $html;
	}
	$s    = $a + strlen( $tag );
	$ends = array_filter( array( strpos( $html, '<aside', $s ), strpos( $html, '<footer', $s ) ), fn( $v ) => false !== $v );
	$e    = $ends ? min( $ends ) : strlen( $html );
	$part = substr( $html, $s, $e - $s );
	$part = 'opening' === $x[0] ? oy_opening( $part, $x[1] ?: 'lilac' ) : ( 'pricing' === $x[0] ? oy_pricing_design( $part ) : $part );
	$part = oy_lines( $part );
	return substr( $html, 0, $s ) . $part . substr( $html, $e );
}

/**
 * Olívia, 15 November: „nagyon sok helyen új sorba írtam mondatokat, hogy jobban átlátható legyen. Szépítsd ezeket, akár
 * új boxxal vagy bármi. Asztalin és mobilon is jól nézzen ki.” A paragraph of the text column whose sentences she put on
 * lines of their own (line breaks) becomes a soft box with one line under the other, the colour changing from box to box.
 * Only paragraphs of the column itself – inside an already designed box (opening, note, gift card…) her lines stay as
 * they are. Runs on the finished page, after her edits.
 */
function oy_lines( $html ) {
	$html  = oy_flows( $html );
	$tones = array( 'lilac', 'pink', 'sage', 'sun' );
	$n     = 0;
	$out   = '';
	$at    = 0;
	if ( ! preg_match_all( '#<p(\s[^>]*)?>((?:(?!</p>).)*?<br\s*/?>(?:(?!</p>).)*?)</p>#s', $html, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}
	foreach ( $ms as $m ) {
		$pos   = $m[0][1];
		$attrs = (string) ( $m[1][0] ?? '' );
		if ( preg_match( '#class="[^"]*\b(oj-|btn)#', $attrs ) ) {
			continue;
		}
		$before = substr( $html, 0, $pos );
		$depth  = preg_match_all( '#<(div|details|blockquote|section|figure|li|ul|ol)\b#', $before ) - preg_match_all( '#</(div|details|blockquote|section|figure|li|ul|ol)>#', $before );
		if ( $depth > 0 ) {
			continue;
		}
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '#<br\s*/?>#', $m[2][0] ) ), fn( $l ) => '' !== trim( wp_strip_all_tags( $l ) ) ) );
		if ( count( $lines ) < 2 ) {
			continue;
		}
		$box  = '<div class="oj-lines oj-lines--' . $tones[ $n % 4 ] . '">' . implode( '', array_map( fn( $l ) => '<p>' . $l . '</p>', $lines ) ) . '</div>';
		$out .= substr( $html, $at, $pos - $at ) . $box;
		$at   = $pos + strlen( $m[0][0] );
		++$n;
	}
	return $out . substr( $html, $at );
}

/**
 * Olívia, 15 November: „Az ilyen leírásokból jöhet egy folyamatábra vagy lépésről lépésre ábra az összes aloldalra” – the
 * paragraph under „Hogyan működik?”, „Mi történik …?” or „Mit csinálunk egy órán?” becomes numbered steps: her lines (line
 * breaks) are the steps; a one-sentence description is cut at its „, utána” / „, majd” / „, végül”, keeping her words. What
 * follows the first sentence stays under the steps. Fewer than two steps: as it is.
 */
function oy_flow_steps( $inner, $heading = '' ) {
	$lines = array_values( array_filter( array_map( 'trim', preg_split( '#<br\s*/?>#', $inner ) ), fn( $l ) => '' !== trim( wp_strip_all_tags( $l ) ) ) );
	$note  = '';
	if ( 1 === count( $lines ) && ! str_starts_with( $heading, 'Mit csinálunk' ) ) {
		// „Hogyan működik?”, „Mi történik…?”: each sentence a step
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '#(?<=[.!?])\s+(?=\p{Lu})#u', $lines[0] ) ) ) );
	} elseif ( 1 === count( $lines ) ) {
		$sent  = preg_split( '#(?<=[.!?])\s+(?=\p{Lu})#u', $lines[0], 2 );
		$note  = trim( $sent[1] ?? '' );
		$lines = preg_split( '#,\s+(?=(?:utána|majd|aztán|végül)\s)#u', trim( $sent[0] ) );
		$lines = array_map( fn( $l ) => mb_strtoupper( mb_substr( $l, 0, 1 ) ) . mb_substr( $l, 1 ), array_map( 'trim', $lines ) );
	}
	return count( $lines ) < 2 ? null : array( $lines, $note );
}

function oy_flows( $html ) {
	return (string) preg_replace_callback(
		'#(<h2[^>]*>\s*((?:Hogyan működik|Mi történik|Mit csinálunk)[^<]*)</h2>\s*)<p>((?:(?!</p>).)+)</p>((?:\s*<p>(?:(?!</p>).)+</p>)*)#su',
		function ( $m ) {
			$st = oy_flow_steps( $m[3], trim( $m[2] ) );
			if ( ! $st ) {
				return $m[0];
			}
			$after = $m[4];
			// „Mi történik …?”: the paragraphs right after it go on with the class (the slow end), so they are steps too
			if ( str_starts_with( trim( $m[2] ), 'Mi történik' ) && preg_match_all( '#<p>((?:(?!</p>).)+)</p>#s', $after, $more ) ) {
				$st[0] = array_merge( $st[0], array_map( 'trim', $more[1] ) );
				$after = '';
			}
			$li = '';
			foreach ( $st[0] as $i => $l ) {
				$li .= '<li><span class="oj-flow__n" aria-hidden="true">' . ( $i + 1 ) . '</span><p>' . $l . '</p></li>';
			}
			return $m[1] . '<ol class="oj-flow oj-flow--n' . min( 4, count( $st[0] ) ) . '">' . $li . '</ol>' . ( '' !== $st[1] ? '<p class="oj-flow__note">' . $st[1] . '</p>' : '' ) . $after;
		},
		$html
	);
}

/**
 * The same in the answers of the FAQ (on the FAQ page, the home page, „Amire szükséged lesz”): lines that start with a
 * dash become a list, the other lines paragraphs of their own.
 */
function oy_answer_lines( $html ) {
	return (string) preg_replace_callback(
		'#(<div class="(?:answer|oj-faq-answer)">)(.*?)(</div>)#s',
		function ( $a ) {
			$inner = preg_replace_callback(
				'#<p>((?:(?!</p>).)*?<br\s*/?>(?:(?!</p>).)*?)</p>#s',
				function ( $p ) {
					$lines = array_values( array_filter( array_map( 'trim', preg_split( '#<br\s*/?>#', $p[1] ) ), fn( $l ) => '' !== trim( wp_strip_all_tags( $l ) ) ) );
					$out   = '';
					$list  = array();
					foreach ( $lines as $l ) {
						if ( preg_match( '#^[–-]\s*(.+)$#us', $l, $d ) ) {
							$list[] = '<li>' . $d[1] . '</li>';
							continue;
						}
						if ( $list ) {
							$out .= '<ul class="oj-dashes">' . implode( '', $list ) . '</ul>';
							$list = array();
						}
						$out .= '<p>' . $l . '</p>';
					}
					return $out . ( $list ? '<ul class="oj-dashes">' . implode( '', $list ) . '</ul>' : '' );
				},
				$a[2]
			);
			return $a[1] . $inner . $a[3];
		},
		$html
	);
}

add_action( 'template_redirect', function () {
	if ( ! oy_online_only() || is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( 'oy_main_transform' ); // before the page editor's buffer (1000), so this one gets the page after it
}, 999 );

/** The colour of a class: the same one its card and box use everywhere. */
function oy_class_tone( $slug ) {
	$tones = array( 'hatha-flow' => 'lilac', 'egyeni-online-joga' => 'pink', oy_prenatal_slug() => 'sage', 'stretching' => 'sun', 'relaxacio-legzes' => 'lilac' );
	return $tones[ $slug ] ?? 'lilac';
}

/**
 * Olívia, 14 November: „ezek az alcímek is legyenek egy kicsit megdesignolva” – the opening paragraph of a class page (a
 * short first sentence, then a line break and the rest) becomes a soft box in the class's colour: the first sentence
 * large, the rest under it. Built from the text as it is when shown, so her own edits keep the look; a page that starts
 * with anything else stays as it is.
 */
function oy_opening( $html, $tone = 'lilac' ) {
	if ( ! preg_match( '#^\s*<p(?:\s[^>]*)?>(.*?)</p>#s', $html, $m ) ) {
		return $html;
	}
	// „tone:2” – the second line is the one in large (Olívia, 15 November, one-to-one yoga: „ez jó de pont az egyéni legyen
	// kiemelve”); the first stays above it, small
	$pre  = '';
	$lead = 1;
	if ( preg_match( '#^([a-z]+):(\d)$#', (string) $tone, $t ) ) {
		$tone = $t[1];
		$lead = (int) $t[2];
	}
	if ( 2 === $lead ) {
		$two = preg_split( '#<br\s*/?>#i', $m[1], 2 );
		if ( 2 === count( $two ) && '' !== trim( wp_strip_all_tags( $two[0] ) ) ) {
			$pre  = '<p class="oj-opening__pre">' . trim( $two[0] ) . '</p>';
			$m[1] = ltrim( preg_replace( '#^(\s*<br\s*/?>)+#i', '', $two[1] ) );
		}
	}
	$parts = preg_split( '#<br\s*/?>#i', $m[1], 2 );
	if ( 2 !== count( $parts ) || '' === trim( wp_strip_all_tags( $parts[0] ) ) || '' === trim( wp_strip_all_tags( $parts[1] ) ) ) {
		return $html;
	}
	$rest  = preg_replace( '#^(\s*<br\s*/?>)+\s*#i', '', trim( $parts[1] ) );
	$paras = array_filter( array_map( 'trim', preg_split( '#(?:<br\s*/?>\s*){2,}#i', $rest ) ), fn( $x ) => '' !== $x );
	$box   = '<div class="oj-opening oj-opening--' . esc_attr( $tone ) . '">' . $pre . '<p class="oj-opening__tag">' . trim( $parts[0] ) . '</p>';
	foreach ( $paras as $para ) {
		$box .= '<p>' . $para . '</p>';
	}
	return $box . '</div>' . substr( $html, strlen( $m[0] ) );
}

function oy_cancel_note( $cls = '', $kind = '' ) {
	if ( ! oy_online_only() || ! class_exists( 'OYS_Settings' ) ) {
		return '';
	}
	$text = trim( (string) OYS_Settings::get( 'cancel_policy' ) );
	// a class page shows only the rule that is about it (Olívia, 14 November: „lemaradt a vége” – the whole text did not fit
	// beside the class): the sentences about one-to-one sessions on those, the others on the group class; all of it if a
	// part would come out empty
	if ( in_array( $kind, array( 'private', 'group' ), true ) && '' !== $text ) {
		$parts = preg_split( '/(?<=[.!?])\s+/u', $text );
		$pick  = array_filter( $parts, fn( $p ) => ( false !== mb_stripos( $p, 'egyéni' ) ) === ( 'private' === $kind ) );
		$text  = $pick ? implode( ' ', $pick ) : $text;
	}
	return '' === $text ? '' : '<p class="oj-policy' . ( $cls ? ' ' . esc_attr( $cls ) : '' ) . '">' . oy_icon( 'calendar' ) . '<span><b>' . esc_html__( 'Cancellation:', 'olivia-yoga' ) . '</b> ' . esc_html( $text ) . '</span></p>';
}

/**
 * How many days a group class can be watched again (the booking plugin's setting, so the number is written in one place);
 * 0 when classes are not recorded. Olívia, 14 November: „Az, hogy az óra visszanézhető még 1 hétig, jelenjen meg több
 * helyen is kiemelve!”
 */
function oy_replay_days() {
	if ( ! oy_online_only() || ! class_exists( 'OYS_Settings' ) || 'none' === (string) OYS_Settings::get( 'live_record' ) ) {
		return 0;
	}
	return max( 0, (int) OYS_Settings::get( 'live_replay_days' ) );
}

/**
 * The pictures on the service boxes: soft, overlapping pastel circles, like the circles that peek into the cards' corners,
 * breathing slowly – not drawings (Olívia, 14 November: „gyerekes ez a rajzos piktogram. Elemezd az oldal stílusát …
 * Legyen lágyabb, letisztultabb”; „Tetszik, hogy animált”).
 */
function oy_way_art( $name ) {
	static $art = array(
		// together: three circles overlapping
		'group'    => '<circle class="wa-1" cx="24" cy="42" r="15"/><circle class="wa-2" cx="48" cy="42" r="15"/><circle class="wa-3 wa-breathe" cx="36" cy="26" r="13"/>',
		// one-to-one: a circle held in a larger one
		'home'     => '<circle class="wa-1" cx="36" cy="36" r="25"/><circle class="wa-3 wa-breathe" cx="36" cy="36" r="11"/>',
		// prenatal: a small circle carried inside a large one
		'prenatal' => '<circle class="wa-1" cx="33" cy="34" r="24"/><circle class="wa-3 wa-breathe" cx="42" cy="44" r="10"/>',
		// stretching: a long, leaning oval and a circle reaching on
		'mobility' => '<ellipse class="wa-1" cx="32" cy="40" rx="27" ry="12" transform="rotate(-28 32 40)"/><circle class="wa-3 wa-breathe" cx="54" cy="18" r="9"/>',
		// relaxation: calm ripples
		'lotus'    => '<circle class="wa-2" cx="36" cy="36" r="27"/><circle class="wa-1" cx="36" cy="36" r="18"/><circle class="wa-3 wa-breathe" cx="36" cy="36" r="9"/>',
	);
	$draw = $art[ $name ] ?? '';
	if ( '' === $draw ) {
		return oy_icon( $name, 'way__icon' );
	}
	return '<svg class="way__icon way__art" viewBox="0 0 72 72" aria-hidden="true" focusable="false">' . $draw . '</svg>';
}

/** The short label: „Egy hétig visszanézhető” (the group class box on the home page). */
function oy_replay_label() {
	$days = oy_replay_days();
	return 7 === $days ? __( 'Watch again for a week', 'olivia-yoga' ) : sprintf( __( 'Watch again for %d days', 'olivia-yoga' ), $days );
}

/** The highlighted note under the timetables and beside the group class's button; '' when not recorded. */
function oy_replay_note( $cls = '' ) {
	$days = oy_replay_days();
	if ( ! $days ) {
		return '';
	}
	$head = 7 === $days ? __( 'You can watch it again for a week.', 'olivia-yoga' ) : sprintf( __( 'You can watch it again for %d days.', 'olivia-yoga' ), $days );
	return '<p class="oj-replay' . ( $cls ? ' ' . esc_attr( $cls ) : '' ) . '">' . oy_icon( 'play' ) . '<span><b>' . esc_html( $head ) . '</b> ' . esc_html__( 'Only the group class can be watched again, and only I am on the recording, nobody else: if you missed it, or would like to do it again, it is waiting in your account.', 'olivia-yoga' ) . '</span></p>';
}

function oy_section_head( $title, $text = '', $kicker = '', $id = '' ) {
	return '<header class="section__head" data-reveal>' . ( $kicker ? oy_kicker( $kicker ) : '' ) . '<h2' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</h2>' . ( $text ? '<p class="lead">' . esc_html( $text ) . '</p>' : '' ) . '</header>';
}

/**
 * The articles on the eight limbs of yoga (category „A jóga 8 ága”), in their order 1–8 – shown together at the top
 * of „Jógáról” (Olivia, 13 November: the hatha button „vigyen a jógáról aloldalra, és egyből görgessen le a jóga 8
 * ágához”).
 */
const OY_LIMBS_CATEGORY = 'a-joga-8-aga';

function oy_limbs_posts() {
	$term = get_term_by( 'slug', OY_LIMBS_CATEGORY, 'category' );
	if ( ! $term ) {
		return array();
	}
	$posts = get_posts( array( 'numberposts' => 20, 'category' => $term->term_id, 'suppress_filters' => false ) );
	usort( $posts, fn( $a, $b ) => strnatcmp( $a->post_title, $b->post_title ) );
	return $posts;
}

function oy_limbs_url() {
	return oy_limbs_posts() ? oy_page_link( 'journal' ) . '#' . OY_LIMBS_CATEGORY : oy_page_link( 'faq' );
}

/** Olivia's title and her specialities, used on the hero name card, the About page and articles. */
function oy_title_line() {
	if ( oy_is_demo() ) {
		$line = __( 'Yoga studio | Group, private & online classes', 'olivia-yoga' );
	} elseif ( oy_online_only() ) {
		$line = __( 'Yoga Teacher | Pre- & Postnatal Trainer | Movement & Wellness Coach', 'olivia-yoga' ); // Olivia, 11.13
	} else {
		$line = __( 'Yoga Teacher | Movement & Wellness Coach', 'olivia-yoga' );
	}
	return (string) apply_filters( 'oy_title_line', $line );
}

function oy_specialities() {
	// On the HathaFlow platform every teacher's site lists what that teacher teaches (filter `oy_specialities`).
	$custom = apply_filters( 'oy_specialities', null );
	if ( is_array( $custom ) && $custom ) {
		return array_map( 'strval', $custom );
	}
	if ( oy_is_demo() ) {
		return array( __( 'Hatha flow', 'olivia-yoga' ), __( 'Slow flow', 'olivia-yoga' ), __( 'Private sessions', 'olivia-yoga' ), __( 'Online classes', 'olivia-yoga' ) );
	}
	if ( oy_online_only() ) {
		return array( __( 'Yoga', 'olivia-yoga' ), __( 'Prenatal Fitness', 'olivia-yoga' ), __( 'Mobilization', 'olivia-yoga' ), __( 'Relaxation & breathing', 'olivia-yoga' ) ); // Olivia, 11.13
	}
	return array( __( 'Yoga', 'olivia-yoga' ), __( 'Mobility', 'olivia-yoga' ), __( 'Prenatal Fitness', 'olivia-yoga' ), __( 'Meditation', 'olivia-yoga' ), __( 'Private Singing Bowl Massage', 'olivia-yoga' ) );
}

function oy_specialities_line( $cls = 'specialities' ) {
	return '<p class="' . esc_attr( $cls ) . '">' . implode( '<i aria-hidden="true">•</i>', array_map( 'esc_html', oy_specialities() ) ) . '</p>';
}

/** The theme's legal pages that exist (for the footer): slug → [title, url]. */
function oy_legal_pages() {
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 5, 'meta_key' => 'oy_legal', 'orderby' => 'menu_order title', 'order' => 'ASC' ) ) as $p ) {
		$out[ get_post_meta( $p->ID, 'oy_legal', true ) ] = array( get_the_title( $p ), get_permalink( $p ) );
	}
	return $out;
}

/** The name of the timetable & prices page in buttons and menus: on the online site „Bejelentkezés és árak” (Olivia, 11.13). */
function oy_schedule_label() {
	return oy_online_only() ? __( 'Sign-up & pricing', 'olivia-yoga' ) : __( 'Schedule & pricing', 'olivia-yoga' );
}

/** Name card: name, title line and specialities. */
function oy_name_card( $cls = '' ) {
	return '<div class="namecard ' . esc_attr( $cls ) . '"><p class="namecard__name">' . esc_html( oy_teacher_name() ) . '</p><p class="namecard__title">' . esc_html( oy_title_line() ) . '</p>' . oy_specialities_line( 'namecard__spec' ) . '</div>';
}

/* ---------- URLs used across templates ---------- */

function oy_page_link( $slug, $anchor = '' ) {
	$slug = oy_page_slug( $slug );
	$page = get_page_by_path( $slug );
	$url  = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
	return $anchor ? $url . '#' . $anchor : $url;
}

function oy_book_url() {
	return function_exists( 'oys_page_url' ) ? oys_page_url( 'book' ) : oy_page_link( 'contact', 'book' );
}

/** The prenatal class's address (slug): /online-orak/kismamatorna/ on the Hungarian online site (Olivia, 11.12). */
function oy_prenatal_slug() {
	return 'hu-online' === oy_edition() ? 'kismamatorna' : 'prenatal-fitness';
}

function oy_private_url() {
	return function_exists( 'oys_account_url' ) ? oys_account_url( 'private' ) : add_query_arg( 'topic', 'private', oy_page_link( 'contact' ) ) . '#book';
}

function oy_account_url() {
	return function_exists( 'oys_account_url' ) ? oys_account_url() : wp_login_url();
}

function oy_contact_url( $topic = '' ) {
	$url = oy_page_link( 'contact' );
	return ( $topic ? add_query_arg( 'topic', $topic, $url ) : $url ) . '#book';
}

/** Resolve {{page:slug}}, {{class:slug}}, {{contact:topic}}, {{private}} and {{private:topic}} tokens in imported copy. */
function oy_resolve_tokens( $text ) {
	$text = preg_replace_callback( '/\{\{(page|class|contact):([a-z0-9\-]+)\}\}(#[a-z0-9\-]+)?/', function ( $m ) {
		$anchor = isset( $m[3] ) ? substr( $m[3], 1 ) : '';
		if ( 'page' === $m[1] ) {
			return oy_page_link( 'classes' === $m[2] ? 'yoga-classes' : $m[2], $anchor );
		}
		if ( 'class' === $m[1] ) {
			return oy_class_url( $m[2] );
		}
		return oy_contact_url( $m[2] );
	}, $text );
	// {{private}}: the private session request; {{private:prenatal}}: the same, for a prenatal session
	return preg_replace_callback( '/\{\{private(?::([a-z0-9\-]+))?\}\}/', fn( $m ) => empty( $m[1] ) ? oy_private_url() : add_query_arg( 'topic', $m[1], oy_private_url() ), $text );
}

/* ---------- Breadcrumbs and page head ---------- */

function oy_crumbs( array $trail ) {
	if ( oy_online_only() ) {
		return ''; // the same small capitals over the page titles: out on every page (Olívia, 14 November)
	}
	$items = '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'olivia-yoga' ) . '</a></li>';
	$last  = count( $trail ) - 1;
	foreach ( $trail as $i => $t ) {
		$items .= $i === $last ? '<li aria-current="page">' . esc_html( $t[0] ) . '</li>' : '<li><a href="' . esc_url( $t[1] ) . '">' . esc_html( $t[0] ) . '</a></li>';
	}
	return '<nav class="crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'olivia-yoga' ) . '"><ol>' . $items . '</ol></nav>';
}

function oy_page_head( $h1, $lead, array $trail, $seed, $meta = '', $photo = '' ) {
	$tone = oy_accent_for( $seed );
	$size = mb_strlen( $h1 ) > 34 ? ' page-head--long' : '';
	// one long word („Ajándékutalvány”) cannot break: smaller, so it never runs into the photo (Olívia, 14 November)
	$size .= max( array_map( 'mb_strlen', preg_split( '/\s+/u', $h1 ) ?: array( '' ) ) ) >= 13 ? ' page-head--word' : '';
	$lead = $lead ? '<p class="lead">' . esc_html( $lead ) . '</p>' : '';
	$text = '<div class="page-head__text">' . oy_crumbs( $trail ) . '<h1>' . esc_html( $h1 ) . '</h1>' . $lead . $meta . '</div>';
	if ( $photo ) {
		// the photo fills its tall frame (cropped), so it shows much wider than its column: ask for the large version,
		// or the browser stretches a small one and it looks blurred (Olívia, 14 November: „miért esik így szét a minősége?”)
		$photo = (string) preg_replace( '/ sizes="[^"]*"/', ' sizes="(min-width: 960px) 66vw, 100vw"', $photo, 1 );
		return '<header class="page-head page-head--' . $tone . $size . '"><div class="container page-head__grid">' . $text
			. '<div class="page-head__photo"><div class="frame">' . $photo . '</div></div></div></header>';
	}
	return '<header class="page-head page-head--' . $tone . $size . '"><div class="container">' . $text . '</div></header>';
}

/** Class facts as chips: length and the language(s) it is taught in. */
function oy_class_facts( $post_id, $cls = 'chips' ) {
	$out = '';
	$len = oy_class_meta( $post_id, 'oy_duration' );
	if ( $len ) {
		$out .= '<span class="chip">' . oy_icon( 'clock' ) . esc_html( $len ) . '</span>';
	}
	$lang = oy_online_only() ? '' : oy_class_meta( $post_id, 'oy_lang' ); // online every class is in Hungarian, no need to say it (Olivia, 11.13)
	if ( $lang ) {
		$out .= '<span class="chip">' . oy_icon( 'globe' ) . esc_html( $lang ) . '</span>';
	}
	return $out ? '<div class="' . esc_attr( $cls ) . '">' . $out . '</div>' : '';
}

/** A class detail meta field, in the visitor's language when a translation is set. */
function oy_class_meta( $post_id, $key ) {
	$own = oy_tr( $post_id, str_replace( 'oy_', '', $key ) );
	return $own ?: oy_t( (string) get_post_meta( $post_id, $key, true ) );
}

/* ---------- The look of oliviajoga.hu on the online site (Olivia, 11.12: „a teljes design legyen az, mint az oliviajoga.hu-n”) ---------- */

/** Her logo (OLÍVIA, the pink brush stroke, JÓGA) with „online” in small letters, inline so it takes the text colour. */
function oy_online_logo( $cls = 'oj-logo' ) {
	static $svg = null;
	if ( null === $svg ) {
		$svg = (string) file_get_contents( get_template_directory() . '/assets/img/logo-online.svg' );
	}
	return str_replace( '<svg ', '<svg class="' . esc_attr( $cls ) . '" aria-hidden="true" focusable="false" ', $svg );
}

/** The wave of her logo as a path: a brush stroke along a sine, thin at both ends, thick in the middle. */
function oy_brush_d( $w, $h, $amp, $th, $periods = 1.5, $phase = 0.0, $n = 96 ) {
	$top = array();
	$bot = array();
	for ( $i = 0; $i <= $n; $i++ ) {
		$t     = $i / $n;
		$x     = $w * $t;
		$y     = $h / 2 + $amp * sin( 2 * M_PI * $periods * $t + $phase );
		// a long, gentle taper to nothing at both ends, like a real brush lifting off (Olívia, 14 November: „nem tetszik a
		// hullámnak a széle, el van vágva”)
		$d     = pow( sin( M_PI * $t ), 1.3 ) * $th / 2;
		$top[] = sprintf( '%.1f %.1f', $x, $y - $d );
		$bot[] = sprintf( '%.1f %.1f', $x, $y + $d );
	}
	return 'M' . implode( ' L', $top ) . ' L' . implode( ' L', array_reverse( $bot ) ) . 'Z';
}

/** Two brush strokes behind a picture, like the wave in her logo: a broad one in the tone, a thin orchid one. */
/**
 * The soft two-layer wave behind the hero picture of oliviajoga.hu (its 1.3.0 shape, Olivia 11.12: „nem a gomb, hanem
 * a háttér”), running off the screen on the picture's side. The online hero has it too (Olivia, 11.13).
 */
function oy_soft_blob( $tone = 'pink', $side = 'right' ) {
	$flip = 'right' === $side ? ' transform="matrix(-1 0 0 1 100 0)"' : '';
	return '<svg class="oj-blob oj-blob--' . esc_attr( $side ) . ' oj-tone--' . esc_attr( $tone ) . '" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false"><g' . $flip . '>'
		. '<path class="oj-blob__back" d="M0 6C14 0 34 2 50 8C70 15 92 22 96 40C100 56 86 66 90 80C94 94 76 100 56 98C38 96 18 90 0 94Z"/>'
		. '<path class="oj-blob__front" d="M0 30C18 22 40 28 56 38C74 49 80 64 72 76C64 88 42 90 24 86C14 84 6 84 0 86Z"/></g></svg>';
}

function oy_brush_blob( $tone = 'pink', $side = 'right' ) {
	return '<svg class="oj-blob oj-brush oj-blob--' . esc_attr( $side ) . ' oj-tone--' . esc_attr( $tone ) . '" viewBox="0 0 400 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
		. '<path class="oj-blob__back" d="' . oy_brush_d( 400, 100, 22, 30 ) . '"/><path class="oj-blob__front" d="' . oy_brush_d( 400, 100, 18, 12, 1.5, .7 ) . '"/></svg>';
}
