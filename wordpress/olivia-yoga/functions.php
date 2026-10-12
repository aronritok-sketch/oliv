<?php
/**
 * Olivia Yoga theme.
 */

defined( 'ABSPATH' ) || exit;

define( 'OY_VERSION', '3.0.0' );
define( 'OY_EMAIL', 'olivia.kovacs6@gmail.com' );
define( 'OY_INSTAGRAM', 'https://www.instagram.com/oliivia_yoga/' );
define( 'OY_FACEBOOK', 'https://www.facebook.com/oliviajogaoktato' );

require_once get_template_directory() . '/inc/i18n.php';
require_once get_template_directory() . '/inc/helpers.php';
require_once get_template_directory() . '/inc/content-types.php';
require_once get_template_directory() . '/inc/shortcodes.php';
require_once get_template_directory() . '/inc/seo.php';
if ( is_admin() ) {
	require_once get_template_directory() . '/inc/demo-import.php';
	require_once get_template_directory() . '/inc/translations-admin.php';
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
	wp_enqueue_style( 'oy-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Quicksand:wght@400..700&display=swap', array(), null );
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

/** Main menu entries when none is assigned in Appearance → Menus. */
function oy_menu_items() {
	return array(
		'private-yoga'     => __( 'Private yoga', 'olivia-yoga' ),
		'schedule-pricing' => __( 'Schedule & pricing', 'olivia-yoga' ),
		'corporate-yoga'   => __( 'Teams & athletes', 'olivia-yoga' ),
		'events'           => __( 'Events', 'olivia-yoga' ),
		'about'            => __( 'About', 'olivia-yoga' ),
		'journal'          => __( 'Journal', 'olivia-yoga' ),
		'contact'          => __( 'Contact', 'olivia-yoga' ),
	);
}

function oy_fallback_menu() {
	$sub = '<li><a href="' . esc_url( oy_page_link( 'yoga-classes' ) ) . '">' . esc_html__( 'All classes', 'olivia-yoga' ) . '</a></li>';
	foreach ( oy_classes() as $c ) {
		if ( ! get_post_meta( $c->ID, 'oy_link', true ) ) {
			$sub .= '<li><a href="' . esc_url( get_permalink( $c ) ) . '">' . esc_html( get_the_title( $c ) ) . '</a></li>';
		}
	}
	$out = '<ul class="menu"><li class="menu-item-has-children"><a href="' . esc_url( oy_page_link( 'yoga-classes' ) ) . '">' . esc_html__( 'Classes', 'olivia-yoga' ) . '</a><ul class="sub-menu">' . $sub . '</ul></li>';
	foreach ( oy_menu_items() as $slug => $label ) {
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

/** Olivia's training, as a list (About page side column). */
function oy_training_list() {
	$c     = oy_icon( 'check' );
	$items = array(
		__( 'Hatha yoga teacher, Yoga Alliance International certified (2023)', 'olivia-yoga' ),
		__( 'Samadhi Yoga Studio, Budapest, with Ádám Diószegi', 'olivia-yoga' ),
		__( 'Tibetan singing bowl and sound massage practitioner', 'olivia-yoga' ),
		__( 'Prenatal & postnatal fitness training', 'olivia-yoga' ),
		__( 'Personal trainer and massage therapist background', 'olivia-yoga' ),
		__( 'Self-myofascial release (SMR) course', 'olivia-yoga' ),
	);
	return '<ul class="aside-list">' . implode( '', array_map( fn( $t ) => '<li>' . $c . '<span>' . esc_html( $t ) . '</span></li>', $items ) ) . '</ul>';
}

/** Per-page side column. */
function oy_page_aside( $slug ) {
	$card = function ( $title, $text, $href, $label, $photo = '', $dark = true ) {
		return '<div class="aside-card' . ( $dark ? ' aside-card--forest' : '' ) . '">' . ( $photo ? '<div class="frame">' . oy_photo( $photo, '340px' ) . '</div>' : '' ) . '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>' . oy_btn( $href, $label, $dark ? 'orchid' : 'primary' ) . '</div>';
	};
	switch ( $slug ) {
		case 'about':
			return '<div class="aside-card"><div class="frame">' . oy_photo( 'olivia-riverside-profile', '340px' ) . '</div><h2>' . esc_html__( 'Training', 'olivia-yoga' ) . '</h2>' . oy_training_list() . oy_btn( oy_book_url(), __( 'Practice with me', 'olivia-yoga' ), 'orchid' ) . '</div>';
		case 'private-yoga':
			$price = class_exists( 'OYS_Products' ) ? OYS_Products::private_price_for( 60 ) : 9500;
			return '<div class="aside-card aside-card--forest aside-card--compact"><p class="aside-card__kicker">' . esc_html__( 'Private sessions', 'olivia-yoga' ) . '</p><h2>' . esc_html( sprintf( __( 'From %s', 'olivia-yoga' ), '$' . number_format( $price / 100 ) ) ) . '</h2>'
				. '<p>' . esc_html__( '60, 75 or 90 minutes at your home, lanai, clubhouse, on the beach or online. Prenatal fitness and singing bowl massage too.', 'olivia-yoga' ) . '</p>'
				. '<ul class="aside-list aside-list--light"><li>' . oy_icon( 'globe' ) . esc_html__( 'In English or Hungarian', 'olivia-yoga' ) . '</li><li>' . oy_icon( 'clock' ) . esc_html__( 'Reply within a day', 'olivia-yoga' ) . '</li></ul>'
				. oy_btn( oy_private_url(), __( 'Request a private session', 'olivia-yoga' ), 'orchid' ) . '</div>'
				. '<div class="aside-card aside-card--compact"><h2 class="aside-card__small">' . esc_html__( 'Areas I travel to', 'olivia-yoga' ) . '</h2>' . oy_areas_list() . '</div>';
		case 'corporate-yoga':
			return $card( __( 'Plan a session for your team', 'olivia-yoga' ), __( 'Tell me your team size, location and goals. I will send a proposal with pricing.', 'olivia-yoga' ), oy_contact_url( 'corporate' ), __( 'Request a proposal', 'olivia-yoga' ), 'team-handpan-session' );
		case 'events':
			return $card( __( 'Hear about the next one', 'olivia-yoga' ), __( 'Sound yoga, live-music flows and outdoor sessions are announced here and on Instagram first.', 'olivia-yoga' ), OY_INSTAGRAM, __( 'Follow on Instagram', 'olivia-yoga' ), 'pavilion-silhouettes' );
		case 'faq':
			return $card( __( 'Still wondering?', 'olivia-yoga' ), __( 'Send your question. I am happy to help you choose the right class.', 'olivia-yoga' ), oy_contact_url(), __( 'Ask a question', 'olivia-yoga' ), 'olivia-sunset-smile' );
		case 'contact':
			return '<div class="aside-card aside-card--forest"><div class="frame">' . oy_photo( 'olivia-sunlit-portrait', '340px' ) . '</div><h2>' . esc_html__( 'Say hello', 'olivia-yoga' ) . '</h2><ul class="aside-list">'
				. '<li>' . oy_icon( 'mail' ) . '<a href="mailto:' . esc_attr( OY_EMAIL ) . '">' . esc_html( OY_EMAIL ) . '</a></li><li>' . oy_icon( 'instagram' ) . '<a href="' . esc_url( OY_INSTAGRAM ) . '" target="_blank" rel="noopener">@oliivia_yoga</a></li>'
				. '<li>' . oy_icon( 'facebook' ) . '<a href="' . esc_url( OY_FACEBOOK ) . '" target="_blank" rel="noopener">Facebook</a></li>'
				. ( function_exists( 'oys_fb_group_url' ) && oys_fb_group_url() ? '<li>' . oy_icon( 'group', 'icon' ) . '<a href="' . esc_url( oys_fb_group_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Our Facebook group', 'olivia-yoga' ) . '</a></li>' : '' )
				. '<li>' . oy_icon( 'pin' ) . '<span>Fort Myers, FL</span></li><li>' . oy_icon( 'globe' ) . '<span>' . esc_html__( 'English, Hungarian', 'olivia-yoga' ) . '</span></li></ul>'
				. '<p>' . esc_html__( 'Ready to book? Group classes and passes can be booked and paid online.', 'olivia-yoga' ) . '</p>' . oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ) . '</div>';
	}
	return '';
}

// Journal posts illustrated with Olivia's own graphics show the whole image, not a crop.
add_filter( 'body_class', function ( $classes ) {
	if ( is_singular( 'post' ) && get_post_meta( get_queried_object_id(), 'oy_art', true ) ) {
		$classes[] = 'has-art-photo';
	}
	return $classes;
} );
