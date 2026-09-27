<?php
/**
 * Olivia Yoga theme.
 */

defined( 'ABSPATH' ) || exit;

define( 'OY_VERSION', '2.0.0' );
define( 'OY_EMAIL', 'olivia.kovacs6@gmail.com' );
define( 'OY_INSTAGRAM', 'https://www.instagram.com/oliivia_yoga/' );
define( 'OY_FACEBOOK', 'https://www.facebook.com/oliviajogaoktato' );

require_once get_template_directory() . '/inc/helpers.php';
require_once get_template_directory() . '/inc/content-types.php';
require_once get_template_directory() . '/inc/shortcodes.php';
require_once get_template_directory() . '/inc/seo.php';
if ( is_admin() ) {
	require_once get_template_directory() . '/inc/demo-import.php';
}

add_action( 'after_setup_theme', function () {
	load_theme_textdomain( 'olivia-yoga', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'responsive-embeds' );
	register_nav_menus( array( 'primary' => __( 'Main menu', 'olivia-yoga' ) ) );
	add_image_size( 'oy-card', 900, 560, true );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'oy-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Archivo:wdth,wght@62..125,400..800&display=swap', array(), null );
	wp_enqueue_style( 'oy-site', get_template_directory_uri() . '/assets/site.css', array(), OY_VERSION );
	wp_enqueue_style( 'oy-wp', get_template_directory_uri() . '/assets/wp.css', array( 'oy-site' ), OY_VERSION );
	wp_enqueue_script( 'oy-site', get_template_directory_uri() . '/assets/site.js', array(), OY_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
} );

add_filter( 'wp_resource_hints', function ( $urls, $type ) {
	if ( 'preconnect' === $type ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}, 10, 2 );

add_filter( 'excerpt_length', fn() => 28 );
add_filter( 'excerpt_more', fn() => '…' );

/** Fallback main menu when none is assigned in Appearance → Menus. */
function oy_fallback_menu() {
	$items = array( 'private-yoga' => 'Private yoga', 'schedule-pricing' => 'Schedule & pricing', 'corporate-yoga' => 'Corporate', 'events' => 'Events', 'about' => 'About', 'journal' => 'Journal', 'contact' => 'Contact' );
	$sub   = '<li><a href="' . esc_url( oy_page_link( 'yoga-classes' ) ) . '">All classes</a></li>';
	foreach ( oy_classes() as $c ) {
		if ( ! get_post_meta( $c->ID, 'oy_link', true ) ) {
			$sub .= '<li><a href="' . esc_url( get_permalink( $c ) ) . '">' . esc_html( $c->post_title ) . '</a></li>';
		}
	}
	$out = '<ul class="menu"><li class="menu-item-has-children"><a href="' . esc_url( oy_page_link( 'yoga-classes' ) ) . '">Classes</a><ul class="sub-menu">' . $sub . '</ul></li>';
	foreach ( $items as $slug => $label ) {
		$out .= '<li><a href="' . esc_url( oy_page_link( $slug ) ) . '">' . esc_html( $label ) . '</a></li>';
	}
	return $out . '</ul>';
}

function oy_menu( $drawer = false ) {
	if ( has_nav_menu( 'primary' ) ) {
		return wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'depth' => 2 ) );
	}
	return oy_fallback_menu();
}

// Sub-menu toggle button for keyboard and touch users.
add_filter( 'walker_nav_menu_start_el', function ( $html, $item, $depth, $args ) {
	if ( 0 === $depth && in_array( 'menu-item-has-children', (array) $item->classes, true ) && ( $args->theme_location ?? '' ) === 'primary' ) {
		$html .= '<button class="sub-toggle" type="button" aria-expanded="false" aria-label="' . esc_attr( sprintf( __( 'Show %s', 'olivia-yoga' ), $item->title ) ) . '">' . oy_icon( 'chev' ) . '</button>';
	}
	return $html;
}, 10, 4 );

add_filter( 'nav_menu_link_attributes', function ( $atts, $item ) {
	if ( $item->current ) {
		$atts['aria-current'] = 'page';
	}
	return $atts;
}, 10, 2 );

/** Is this one of the booking plugin's pages (book, account, gift cards)? */
function oy_is_studio_page() {
	if ( ! is_page() || ! function_exists( 'oys_page_url' ) ) {
		return false;
	}
	$id = get_queried_object_id();
	foreach ( array( 'book', 'account', 'gifts' ) as $k ) {
		if ( (int) get_option( 'oys_page_' . $k ) === $id ) {
			return $k;
		}
	}
	return false;
}

/** Per-page side column. */
function oy_page_aside( $slug ) {
	$card = function ( $title, $text, $href, $label, $photo = '', $dark = true ) {
		return '<div class="aside-card' . ( $dark ? ' aside-card--forest' : '' ) . '">' . ( $photo ? '<div class="frame">' . oy_photo( $photo, '340px' ) . '</div>' : '' ) . '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>' . oy_btn( $href, $label, $dark ? 'orchid' : 'primary' ) . '</div>';
	};
	switch ( $slug ) {
		case 'about':
			$c = oy_icon( 'check' );
			return '<div class="aside-card"><div class="frame">' . oy_photo( 'olivia-riverside-profile', '340px' ) . '</div><h2>' . esc_html__( 'Training', 'olivia-yoga' ) . '</h2><ul class="aside-list">'
				. '<li>' . $c . 'Hatha yoga teacher certification, 2023</li><li>' . $c . 'Recognized by Yoga Alliance International</li><li>' . $c . 'Samadhi Yoga Studio, Budapest</li><li>' . $c . 'Teacher training with Ádám Diószegi</li></ul>' . oy_btn( oy_book_url(), __( 'Practice with me', 'olivia-yoga' ) ) . '</div>';
		case 'private-yoga':
			$price = class_exists( 'OYS_Products' ) ? OYS_Products::private_price_for( 60 ) : 9500;
			return $card( sprintf( __( 'From %s per session', 'olivia-yoga' ), '$' . number_format( $price / 100 ) ), __( '60, 75 or 90 minutes at your home, lanai, clubhouse, on the beach or online.', 'olivia-yoga' ), oy_private_url(), __( 'Request a private session', 'olivia-yoga' ), 'tree-pose-meadow' )
				. '<div class="aside-card"><h2>' . esc_html__( 'Areas I travel to', 'olivia-yoga' ) . '</h2>' . oy_areas_list() . '</div>';
		case 'corporate-yoga':
			return $card( __( 'Plan a session for your team', 'olivia-yoga' ), __( 'Tell me your team size, location and goals. I\'ll send a proposal with pricing.', 'olivia-yoga' ), oy_contact_url( 'corporate' ), __( 'Request a proposal', 'olivia-yoga' ), 'outdoor-class-garden' );
		case 'events':
			return $card( __( 'Hear about the next one', 'olivia-yoga' ), __( 'Sound yoga, live-music flows and beach sessions are announced here and on Instagram first.', 'olivia-yoga' ), OY_INSTAGRAM, __( 'Follow on Instagram', 'olivia-yoga' ), 'festival-dance' );
		case 'faq':
			return $card( __( 'Still wondering?', 'olivia-yoga' ), __( 'Send your question — I\'m happy to help you choose the right class.', 'olivia-yoga' ), oy_contact_url(), __( 'Ask a question', 'olivia-yoga' ), 'olivia-riverside-profile' );
		case 'contact':
			return '<div class="aside-card aside-card--forest"><div class="frame">' . oy_photo( 'olivia-leading-garden-class', '340px' ) . '</div><h2>' . esc_html__( 'Say hello', 'olivia-yoga' ) . '</h2><ul class="aside-list">'
				. '<li>' . oy_icon( 'mail' ) . '<a href="mailto:' . esc_attr( OY_EMAIL ) . '">' . esc_html( OY_EMAIL ) . '</a></li><li>' . oy_icon( 'instagram' ) . '<a href="' . esc_url( OY_INSTAGRAM ) . '" target="_blank" rel="noopener">@oliivia_yoga</a></li>'
				. '<li>' . oy_icon( 'facebook' ) . '<a href="' . esc_url( OY_FACEBOOK ) . '" target="_blank" rel="noopener">Facebook</a></li>'
				. ( function_exists( 'oys_fb_group_url' ) && oys_fb_group_url() ? '<li>' . oy_icon( 'users' ) . '<a href="' . esc_url( oys_fb_group_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Our Facebook group', 'olivia-yoga' ) . '</a></li>' : '' )
				. '<li>' . oy_icon( 'pin' ) . '<span>Fort Myers, FL</span></li></ul>'
				. '<p>' . esc_html__( 'Ready to book? Group classes and passes can be booked and paid online.', 'olivia-yoga' ) . '</p>' . oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ) . '</div>';
	}
	return '';
}
