<?php
/**
 * Olívia jóga (oliviajoga.hu) – iu_theme child theme (HelloProVision delivery, hlprv deploy/README.md).
 *
 * Built by the oliv repo's oliviajoga-hu/build.py --wp: the same pages, titles, words and photos as the static
 * oliviajoga.hu. Every page is a WordPress page at the old address (/rólam, /árak …, no trailing slash); its content
 * is the page's HTML (an HTML block, editable); the header, footer, titles and descriptions come from data/site.json.
 * Edit the source (oliviajoga-hu/) and rebuild, not here.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/setup.php';

/** The theme's data: pages (title, description, schema, prefooter, content), header, footer, menu. */
function oj_data() {
	static $data = null;
	if ( null === $data ) {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/data/site.json' ), true ) ?: array();
	}
	return $data;
}

/**
 * The photos: uploads/oliviajoga-img (the VPS copies oliviajoga-hu/assets/img there, projects.json media_dir), or
 * else straight from the oliv repo on GitHub.
 */
function oj_media_url() {
	$up  = wp_upload_dir( null, false );
	$dir = defined( 'OJ_MEDIA_DIR' ) ? OJ_MEDIA_DIR : $up['basedir'] . '/oliviajoga-img';
	if ( is_dir( $dir ) ) {
		return $up['baseurl'] . '/' . basename( $dir );
	}
	return 'https://raw.githubusercontent.com/aronritok-sketch/oliv/claude/nifty-ramanujan-3zifiu/oliviajoga-hu/assets/img';
}

/**
 * Olivia's online site (live online yoga, online prenatal class, her account, the online gift card): www.oliviajoga.online;
 * on the dev server its dev copy. OJ_ONLINE_URL (wp-config.php) overrides both.
 */
function oj_online_url() {
	if ( defined( 'OJ_ONLINE_URL' ) ) {
		return untrailingslashit( OJ_ONLINE_URL );
	}
	if ( 'dev.helloprovision.com' === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		return 'https://dev.helloprovision.com/oliviayoga-booking';
	}
	return 'https://www.oliviajoga.online';
}

/**
 * The site from 26 November (Olivia, 11.12 doc): her classes ended on 25 November, the online yoga goes on – the theme
 * switches by itself on that day (Budapest time). Preview before: ?nov26=1 (a cookie keeps it; ?nov26=0 ends it).
 */
function oj_after() {
	static $after = null;
	if ( null !== $after ) {
		return $after;
	}
	$from = oj_data()['after']['from'] ?? '';
	if ( '' === $from ) {
		return $after = false;
	}
	$today = wp_date( 'Y-m-d', null, new DateTimeZone( 'Europe/Budapest' ) );
	if ( $today >= $from ) {
		return $after = true;
	}
	$ask = isset( $_GET['nov26'] ) ? sanitize_key( wp_unslash( $_GET['nov26'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
	if ( null !== $ask ) {
		return $after = '1' === $ask;
	}
	return $after = isset( $_COOKIE['oj_nov26'] ) && '1' === $_COOKIE['oj_nov26'];
}

add_action( 'init', function () {
	if ( ! isset( $_GET['nov26'] ) || headers_sent() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$on = '1' === sanitize_key( wp_unslash( $_GET['nov26'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	setcookie( 'oj_nov26', $on ? '1' : '', $on ? time() + 30 * DAY_IN_SECONDS : time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
} );

/** Header, footer and the pages' built HTML for today: the version from 26 November once that day has come. */
function oj_chrome( $key ) {
	$d = oj_data();
	if ( oj_after() && isset( $d['after'][ $key ] ) ) {
		return $d['after'][ $key ];
	}
	return $d[ $key ] ?? '';
}

/** Placeholders in the built HTML → this site's addresses. */
function oj_fill( $html ) {
	static $map = null;
	if ( null === $map ) {
		$map = array(
			'%%HOME%%'   => untrailingslashit( home_url() ),
			'%%THEME%%'  => get_stylesheet_directory_uri(),
			'%%MEDIA%%'  => oj_media_url(),
			'%%ONLINE%%' => oj_online_url(),
		);
	}
	return strtr( (string) $html, $map );
}

/** The old address of the current page ('' = home, '404'). */
function oj_path() {
	if ( is_front_page() ) {
		return '';
	}
	if ( is_page() ) {
		$p = get_queried_object();
		return rawurldecode( (string) get_page_uri( $p ) );
	}
	return '404';
}

/** The data of a page (old address); a page made in WordPress later gets the defaults. */
function oj_page( $path ) {
	$pages = oj_data()['pages'] ?? array();
	if ( isset( $pages[ $path ] ) ) {
		$p = $pages[ $path ];
		if ( oj_after() && isset( oj_data()['after']['pages'][ $path ] ) ) {
			$p['prefooter'] = oj_data()['after']['pages'][ $path ]['prefooter'];
			$p['desc']      = oj_data()['after']['pages'][ $path ]['desc'] ?? $p['desc'];
		}
		return $p;
	}
	$home = $pages[''] ?? array();
	return array(
		'title'     => wp_get_document_title(),
		'desc'      => is_singular() ? wp_strip_all_tags( get_the_excerpt() ) : '',
		'cls'       => 'is-page',
		'schema'    => '',
		'prefooter' => oj_after() ? ( oj_data()['after']['pages']['']['prefooter'] ?? '' ) : ( $home['prefooter'] ?? '' ),
	);
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'style', 'script' ) );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'parent-style' ); // iu_theme's base styles reset type and spacing
	wp_dequeue_style( 'style' );        // style.css only holds the theme header
	$v = oj_data()['version'] ?? null;
	wp_enqueue_style( 'oj-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Quicksand:wght@400..700&display=swap', array(), null );
	wp_enqueue_style( 'oj-site', get_stylesheet_directory_uri() . '/assets/site.css', array(), $v );
	wp_enqueue_style( 'oj-oliviajoga', get_stylesheet_directory_uri() . '/assets/oliviajoga.css', array( 'oj-site' ), $v );
	wp_enqueue_script( 'oj-site', get_stylesheet_directory_uri() . '/assets/site.js', array(), $v, array( 'strategy' => 'defer', 'in_footer' => true ) );
}, 20 );

// The page's own title and head (description, canonical, Open Graph, structured data) from the old site.
add_filter( 'pre_get_document_title', function ( $title ) {
	$path = oj_path();
	if ( '404' === $path ) {
		return oj_data()['404_title'] ?? $title;
	}
	$pages = oj_data()['pages'] ?? array();
	return isset( $pages[ $path ] ) ? $pages[ $path ]['title'] : $title;
}, 20 );
remove_action( 'wp_head', 'rel_canonical' );
add_action( 'wp_head', function () {
	$path = oj_path();
	if ( '404' === $path ) {
		return;
	}
	$p     = oj_page( $path );
	$canon = '' === $path ? home_url( '/' ) : untrailingslashit( home_url() ) . '/' . str_replace( '%2F', '/', rawurlencode( $path ) );
	$title = wp_get_document_title();
	echo '<meta name="description" content="' . esc_attr( $p['desc'] ) . '">' . "\n";
	echo '<link rel="canonical" href="' . esc_url( $canon ) . '">' . "\n";
	echo '<meta name="theme-color" content="#2B5036">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( oj_data()['site_title'] ?? '' ) . '"><meta property="og:locale" content="hu_HU"><meta property="og:type" content="' . ( 'is-post' === $p['cls'] ? 'article' : 'website' ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '"><meta property="og:description" content="' . esc_attr( $p['desc'] ) . '"><meta property="og:url" content="' . esc_url( $canon ) . '">' . "\n";
	if ( ! empty( $p['schema'] ) ) {
		echo '<script type="application/ld+json">' . oj_fill( $p['schema'] ) . '</script>' . "\n"; // phpcs:ignore -- built JSON
	}
}, 2 );

/** The header with the current page marked in the menu. */
function oj_header( $path ) {
	$html = preg_replace( '/ aria-current="page"/', '', oj_fill( oj_chrome( 'header' ) ) );
	$here = '' === $path ? home_url( '/' ) : untrailingslashit( home_url() ) . '/' . rawurlencode( $path );
	return str_replace( '<a href="' . esc_attr( $here ) . '">', '<a href="' . esc_attr( $here ) . '" aria-current="page">', $html );
}

/** The page's content: the HTML block as it is (no automatic paragraphs), addresses filled in. */
function oj_main() {
	if ( '404' === oj_path() ) {
		return oj_fill( oj_data()['404'] ?? '' );
	}
	$post = get_queried_object();
	$html = $post instanceof WP_Post ? $post->post_content : '';
	// from 26 November the new version of the page – unless someone has edited the page in WordPress since the setup
	$path  = oj_path();
	$after = oj_data()['after']['pages'][ $path ] ?? null;
	$md5   = (array) get_option( 'oj_setup_md5', array() );
	$key   = '' === $path ? 'főoldal' : $path;
	if ( oj_after() && $after && $post instanceof WP_Post && ( ! isset( $md5[ $key ] ) || md5( $post->post_content ) === $md5[ $key ] ) ) {
		$html = $after['content'];
	}
	$html = preg_replace( '/<!-- \/?wp:html -->\s*/', '', $html );
	return oj_fill( do_shortcode( $html ) );
}

// From 26 November „Bejelentkezés & jóga bérlet” is her account on the online site (Olivia): straight there.
add_action( 'template_redirect', function () {
	if ( ! oj_after() || is_404() ) {
		return;
	}
	$to = oj_data()['after']['redirect'][ oj_path() ] ?? '';
	if ( '' !== $to ) {
		wp_redirect( oj_fill( $to ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- her own online site
		exit;
	}
} );

// The old addresses have no trailing slash (/rólam): WordPress keeps them as they are.
add_filter( 'user_trailingslashit', function ( $url, $type ) {
	return 'page' === $type || 'single' === $type ? untrailingslashit( $url ) : $url;
}, 10, 2 );
