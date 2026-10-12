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
		}
		$pos = get_post_meta( (int) $src, 'oy_pos', true );
		if ( $pos ) {
			$attrs['style'] = 'object-position:' . esc_attr( $pos );
		}
		return wp_get_attachment_image( (int) $src, 'large', false, $attrs );
	}
	$imgs = oy_images();
	if ( empty( $imgs[ $src ] ) ) {
		return '';
	}
	$m    = $imgs[ $src ];
	$base = get_template_directory_uri() . '/assets/img/' . $src;
	$alt  = null === $alt ? oy_t( $m['alt'] ) : $alt;
	return sprintf(
		'<img class="photo" src="%1$s-1200.jpg" srcset="%1$s-600.jpg %8$dw, %1$s-1200.jpg %2$dw" sizes="%3$s" alt="%4$s" width="%2$d" height="%5$d" style="object-position:%6$s" %7$s decoding="async">',
		esc_url( $base ), (int) $m['w'], esc_attr( $sizes ), esc_attr( $alt ), (int) $m['h'], esc_attr( $m['pos'] ),
		$eager ? 'fetchpriority="high"' : 'loading="lazy"',
		min( 600, (int) $m['w'] )
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
	return '<span class="picto picto--' . esc_attr( $tone ) . '" aria-hidden="true">' . oy_icon( $icon, 'picto__icon' ) . '</span>';
}

function oy_btn( $href, $label, $style = 'primary', $extra = '' ) {
	return '<a class="btn btn--' . esc_attr( $style ) . ( $extra ? ' ' . esc_attr( $extra ) : '' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
}

function oy_kicker( $text ) {
	return '<p class="kicker">' . esc_html( $text ) . '</p>';
}

function oy_section_head( $title, $text = '', $kicker = '', $id = '' ) {
	return '<header class="section__head" data-reveal>' . ( $kicker ? oy_kicker( $kicker ) : '' ) . '<h2' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</h2>' . ( $text ? '<p class="lead">' . esc_html( $text ) . '</p>' : '' ) . '</header>';
}

/** Olivia's title and her specialities, used on the hero name card, the About page and articles. */
function oy_title_line() {
	return __( 'Yoga Teacher | Movement & Wellness Coach', 'olivia-yoga' );
}

function oy_specialities() {
	return array( __( 'Yoga', 'olivia-yoga' ), __( 'Mobility', 'olivia-yoga' ), __( 'Prenatal Fitness', 'olivia-yoga' ), __( 'Meditation', 'olivia-yoga' ), __( 'Private Singing Bowl Massage', 'olivia-yoga' ) );
}

function oy_specialities_line( $cls = 'specialities' ) {
	return '<p class="' . esc_attr( $cls ) . '">' . implode( '<i aria-hidden="true">•</i>', array_map( 'esc_html', oy_specialities() ) ) . '</p>';
}

/** Name card: name, title line and specialities. */
function oy_name_card( $cls = '' ) {
	return '<div class="namecard ' . esc_attr( $cls ) . '"><p class="namecard__name">Olivia Kovacs</p><p class="namecard__title">' . esc_html( oy_title_line() ) . '</p>' . oy_specialities_line( 'namecard__spec' ) . '</div>';
}

/* ---------- URLs used across templates ---------- */

function oy_page_link( $slug, $anchor = '' ) {
	$page = get_page_by_path( $slug );
	$url  = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
	return $anchor ? $url . '#' . $anchor : $url;
}

function oy_book_url() {
	return function_exists( 'oys_page_url' ) ? oys_page_url( 'book' ) : oy_page_link( 'contact', 'book' );
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

/** Resolve {{page:slug}}, {{class:slug}}, {{contact:topic}} and {{private}} tokens in imported copy. */
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
	return str_replace( '{{private}}', oy_private_url(), $text );
}

/* ---------- Breadcrumbs and page head ---------- */

function oy_crumbs( array $trail ) {
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
	$lead = $lead ? '<p class="lead">' . esc_html( $lead ) . '</p>' : '';
	$text = '<div class="page-head__text">' . oy_crumbs( $trail ) . '<h1>' . esc_html( $h1 ) . '</h1>' . $lead . $meta . '</div>';
	if ( $photo ) {
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
	$lang = oy_class_meta( $post_id, 'oy_lang' );
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
