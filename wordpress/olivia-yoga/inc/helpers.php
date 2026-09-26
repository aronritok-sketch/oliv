<?php
/**
 * Markup helpers shared by the templates (photos, stickers, marquee, buttons, page heads).
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
	return sprintf(
		'<img class="photo" src="%1$s-1200.jpg" srcset="%1$s-600.jpg 600w, %1$s-1200.jpg %2$dw" sizes="%3$s" alt="%4$s" width="%2$d" height="%5$d" style="object-position:%6$s" %7$s decoding="async">',
		esc_url( $base ), (int) $m['w'], esc_attr( $sizes ), esc_attr( null === $alt ? $m['alt'] : $alt ), (int) $m['h'], esc_attr( $m['pos'] ),
		$eager ? 'fetchpriority="high"' : 'loading="lazy"'
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

function oy_icon( $name, $cls = 'icon' ) {
	static $icons = array(
		'sun'       => '<circle cx="12" cy="12" r="4.5"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
		'home'      => '<path d="M3.5 11 12 4l8.5 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/>',
		'wave'      => '<path d="M2.5 15c2.5 0 2.5-2 5-2s2.5 2 5 2 2.5-2 5-2 2.5 2 4 2"/><path d="M2.5 19c2.5 0 2.5-2 5-2s2.5 2 5 2 2.5-2 5-2 2.5 2 4 2"/><circle cx="12" cy="7" r="3"/>',
		'building'  => '<rect x="4.5" y="3.5" width="15" height="17" rx="1"/><path d="M8.5 7.5h2M13.5 7.5h2M8.5 11.5h2M13.5 11.5h2M10.5 20.5v-4h3v4"/>',
		'screen'    => '<rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M9 20h6M12 16.5V20"/>',
		'clock'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		'pin'       => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="10" r="2.3"/>',
		'mail'      => '<rect x="3" y="5.5" width="18" height="13" rx="1.5"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
		'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6" fill="currentColor"/>',
		'facebook'  => '<path d="M14.5 8.5H17V4.8h-2.5a4 4 0 0 0-4 4v2.2H8v3.6h2.5v6.9h3.6v-6.9h2.6l.6-3.6h-3.2V9.3a.8.8 0 0 1 .4-.8z"/>',
		'calendar'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="1.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
		'menu'      => '<path d="M4 8h16M4 16h16"/>',
		'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'chev'      => '<path d="m6 9 6 6 6-6"/>',
		'users'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.2c2.4.2 4.2 1.9 4.8 4.8"/>',
		'leaf'      => '<path d="M5 19C5 10 11 5 20 4c0 9-5 15-14 15z"/><path d="M5 19 13 11"/>',
		'check'     => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		'arrow'     => '<path d="M4.5 12h15M13.5 6l6 6-6 6"/>',
		'user'      => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
	);
	return '<svg class="' . esc_attr( $cls ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $icons[ $name ] ?? '' ) . '</svg>';
}

function oy_btn( $href, $label, $style = 'primary', $extra = '' ) {
	return '<a class="btn btn--' . esc_attr( $style ) . ( $extra ? ' ' . esc_attr( $extra ) : '' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
}

function oy_sticker( $text, $cls = '' ) {
	static $n = 0;
	$n++;
	return '<span class="sticker ' . esc_attr( $cls ) . '" aria-hidden="true"><svg viewBox="0 0 120 120"><defs><path id="st-' . $n . '" d="M60 60 m-44 0 a44 44 0 1 1 88 0 a44 44 0 1 1 -88 0"/></defs>'
		. '<circle cx="60" cy="60" r="58"/><text><textPath href="#st-' . $n . '" textLength="272">' . esc_html( $text ) . '</textPath></text></svg><span class="sticker__core">✺</span></span>';
}

function oy_marquee( array $words ) {
	$run = '';
	foreach ( $words as $w ) {
		$run .= '<span>' . esc_html( $w ) . '</span><i aria-hidden="true">✺</i>';
	}
	return '<div class="marquee" aria-hidden="true"><div class="marquee__track"><div>' . $run . '</div><div>' . $run . '</div></div></div>';
}

function oy_badge( $icon, $tone = 'lilac' ) {
	return '<span class="badge-icon badge-icon--' . esc_attr( $tone ) . '">' . oy_icon( $icon ) . '</span>';
}

function oy_kicker( $text ) {
	return '<p class="kicker">' . esc_html( $text ) . '</p>';
}

function oy_section_head( $title, $text = '', $kicker = '', $id = '' ) {
	return '<header class="section__head" data-reveal>' . ( $kicker ? oy_kicker( $kicker ) : '' ) . '<h2' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . esc_html( $title ) . '</h2>' . ( $text ? '<p class="lead">' . esc_html( $text ) . '</p>' : '' ) . '</header>';
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
			. '<div class="page-head__photo"><div class="frame">' . $photo . '</div>' . oy_sticker( 'Olivia Kovács ✺ Fort Myers ✺ ', 'sticker--head' ) . '</div></div></header>';
	}
	return '<header class="page-head page-head--' . $tone . $size . '"><div class="container">' . $text . '</div></header>';
}

function oy_intensity( $n ) {
	$n     = max( 1, min( 3, (int) $n ) );
	$label = array( 1 => __( 'Gentle', 'olivia-yoga' ), 2 => __( 'Moderate', 'olivia-yoga' ), 3 => __( 'Dynamic', 'olivia-yoga' ) )[ $n ];
	$dots  = '';
	for ( $i = 0; $i < 3; $i++ ) {
		$dots .= '<i class="' . ( $i < $n ? 'on' : '' ) . '"></i>';
	}
	return '<span class="intensity" title="' . esc_attr( $label ) . '"><span class="sr-only">' . esc_html( sprintf( __( 'Intensity: %s', 'olivia-yoga' ), $label ) ) . '</span>' . $dots . '</span>';
}
