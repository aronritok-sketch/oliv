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

/** Placeholders in the built HTML → this site's addresses. */
function oj_fill( $html ) {
	static $map = null;
	if ( null === $map ) {
		$map = array(
			'%%HOME%%'  => untrailingslashit( home_url() ),
			'%%THEME%%' => get_stylesheet_directory_uri(),
			'%%MEDIA%%' => oj_media_url(),
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
		return $pages[ $path ];
	}
	$home = $pages[''] ?? array();
	return array(
		'title'     => wp_get_document_title(),
		'desc'      => is_singular() ? wp_strip_all_tags( get_the_excerpt() ) : '',
		'cls'       => 'is-page',
		'schema'    => '',
		'prefooter' => $home['prefooter'] ?? '',
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
	$html = preg_replace( '/ aria-current="page"/', '', oj_fill( oj_data()['header'] ?? '' ) );
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
	$html = preg_replace( '/<!-- \/?wp:html -->\s*/', '', $html );
	return oj_fill( do_shortcode( $html ) );
}

// The old addresses have no trailing slash (/rólam): WordPress keeps them as they are.
add_filter( 'user_trailingslashit', function ( $url, $type ) {
	return 'page' === $type || 'single' === $type ? untrailingslashit( $url ) : $url;
}, 10, 2 );
