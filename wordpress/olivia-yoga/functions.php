<?php
/**
 * Olivia Yoga theme.
 */

defined( 'ABSPATH' ) || exit;

define( 'OY_VERSION', '3.11.106' );

require_once get_template_directory() . '/inc/edition.php';

// The edition's contact details (Olivia's, or the Hathaflow demo's); a child theme or wp-config.php may set them first.
defined( 'OY_EMAIL' ) || define( 'OY_EMAIL', oy_brand( 'email' ) );
defined( 'OY_INSTAGRAM' ) || define( 'OY_INSTAGRAM', oy_brand( 'instagram' ) );
defined( 'OY_FACEBOOK' ) || define( 'OY_FACEBOOK', oy_brand( 'facebook' ) );
require_once get_template_directory() . '/inc/i18n.php';
require_once get_template_directory() . '/inc/helpers.php';
require_once get_template_directory() . '/inc/content-types.php';
require_once get_template_directory() . '/inc/shortcodes.php';
require_once get_template_directory() . '/inc/seo.php';
require_once get_template_directory() . '/inc/login.php';
require_once get_template_directory() . '/inc/llms.php';
require_once get_template_directory() . '/inc/consent.php';

// Olívia, 16 November: „itt kicsiben nagyon szétesett a kép minősége” – WordPress's „sizes: auto” makes the browser take
// the picture as wide as its box, but a card crops a wide photo to 4:3 and needs a bigger one; our own sizes say so.
add_filter( 'wp_img_tag_add_auto_sizes', '__return_false' );
// Szerkesztés a weboldalon (Olivia, 11.13): only on her Hungarian sites, not the US one or the Hathaflow demo
add_filter( 'oye_enabled', fn( $on ) => $on && ( oy_online_only() || 'hu' === oy_default_lang() ) && ! oy_is_demo() );
require_once get_template_directory() . '/inc/page-editor.php';
require_once get_template_directory() . '/inc/typography.php';
// The booking plugin's „By paying you accept the terms” links to the ÁSZF when the site has one (online edition, 11.13)
add_filter( 'oys_terms_url', function ( $url ) {
	$p = get_page_by_path( 'aszf' );
	return $p && 'publish' === $p->post_status ? get_permalink( $p ) : $url;
} );
// Olivia's notes of 13 November on the online price list: no „Most popular” label, the gift card as its own
// highlighted line, the drop-in card with only that line, the group class's length in its title
add_filter( 'oys_featured_badge', fn( $html ) => oy_online_only() ? '' : $html );
// Olívia, 14 November: „Legyenek egységesek a bérletes boxok, tehát pl az ár fölött a szolgáltatás” – the group class's
// cards are named after the class, like the one-to-one ones; the number of sessions and the minutes are under the price
add_filter( 'oys_price_card_title', function ( $title, $p ) {
	if ( ! oy_online_only() || ! in_array( $p['kind'] ?? '', array( 'online_pack', 'pack', 'drop_in', 'online_drop', 'intro' ), true ) ) {
		return $title;
	}
	$class = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
	return $class ? get_the_title( $class ) : $title;
}, 10, 2 );
// Olívia, 14 November: „elég alul jelezni, hogy online, a címben ne legyen” – on a timetable card the „Online” tag says it
add_filter( 'oys_card_title', fn( $title, $s ) => oy_online_only() && function_exists( 'oys_is_online' ) && oys_is_online( $s ) ? trim( (string) preg_replace( '/\s*\bonline\b\s*/iu', ' ', (string) $title ) ) : $title, 10, 2 );
add_filter( 'oys_price_gift_line', fn( $on ) => $on || oy_online_only() );
add_filter( 'oys_dropin_features', fn( $f ) => oy_online_only() ? array() : $f );
add_filter( 'oys_pricing_groups', function ( $g ) {
	if ( oy_online_only() ) {
		// Olívia, 15 November: „a gyere alkalmi jeggyel mondatot kiszedhetjük”
		$g['classes'] = array( __( 'Group live online flow yoga classes', 'olivia-yoga' ), __( '90 minutes, every Wednesday from 18:30 to 20:00.', 'olivia-yoga' ) );
	}
	return $g;
} );
// „Jógáról”: the eight limbs of yoga stand together at the top of the first page, so the list below leaves them out
// Olívia, 14 November (online): „összes írásnál … a 8 ág legyen a végén és ne lapozós legyen, hanem a jelenlegi összes cikk
// jelenjen meg egy oldalon” – there every article is on one page, the eight limbs last (home.php puts them in order)
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() && $q->is_main_query() && $q->is_home() ) {
		if ( oy_online_only() ) {
			$q->set( 'posts_per_page', -1 );
			$q->set( 'nopaging', true );
			return;
		}
		$term = get_term_by( 'slug', OY_LIMBS_CATEGORY, 'category' );
		if ( $term ) {
			$q->set( 'category__not_in', array( (int) $term->term_id ) );
		}
	}
} );
if ( is_admin() ) {
	require_once get_template_directory() . '/inc/demo-import.php';
	require_once get_template_directory() . '/inc/translations-admin.php';
	require_once get_template_directory() . '/inc/image-position.php';
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
	// Olívia, 15 November: „az egész oldalon, mindenhol ahol quicksand betűtípus van, Cabint szeretnék helyette!” – the online site
	$oy_text_font = oy_online_only() ? 'Cabin:ital,wght@0,400..700;1,400..700' : 'Quicksand:wght@400..700';
	wp_enqueue_style( 'oy-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=' . $oy_text_font . '&display=swap', array(), null );
	wp_enqueue_style( 'oy-site', get_template_directory_uri() . '/assets/site.css', array(), OY_VERSION );
	wp_enqueue_style( 'oy-wp', get_template_directory_uri() . '/assets/wp.css', array( 'oy-site' ), OY_VERSION );
	if ( oy_online_only() ) { // the look of oliviajoga.hu (Olivia, 11.12)
		wp_enqueue_style( 'oy-online', get_template_directory_uri() . '/assets/online.css', array( 'oy-wp' ), OY_VERSION );
	}
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
	if ( oy_online_only() ) {
		return array(
			'schedule-pricing' => oy_schedule_label(),
			'about'            => __( 'About', 'olivia-yoga' ),
			'faq'              => __( 'FAQ', 'olivia-yoga' ),
			'journal'          => __( 'About yoga', 'olivia-yoga' ),
			'contact'          => __( 'Contact', 'olivia-yoga' ),
		);
	}
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

// WordPress marks the journal page as the parent of every single item, class pages included; only posts belong to it.
add_filter( 'nav_menu_css_class', function ( $classes, $item ) {
	if ( is_singular() && ! is_singular( 'post' ) && (int) $item->object_id === (int) get_option( 'page_for_posts' ) ) {
		$classes = array_values( array_diff( (array) $classes, array( 'current_page_parent' ) ) );
	}
	return $classes;
}, 10, 2 );

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
			if ( oy_is_demo() ) {
				return $card( __( 'Come to a class', 'olivia-yoga' ), __( 'Beginners are always welcome. Book a drop-in or a class pass online.', 'olivia-yoga' ), oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'olivia-riverside-profile' );
			}
			if ( oy_online_only() ) {
				// Olívia, 14 November: „ez a rész teljesen jöjjön ki, legyen helyette más” – the trainings are in the text
				// already; beside it now: the next live class and her five services with their lengths
				$next = '';
				if ( class_exists( 'OYS_Schedule' ) && function_exists( 'oys_now' ) ) {
					$soon = OYS_Schedule::query( array( 'kind' => array( 'group' ), 'from' => oys_now(), 'to' => oys_utc_plus( 120 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) );
					if ( $soon ) {
						$next = '<p class="aside-next">' . oy_icon( 'calendar' ) . '<span>' . esc_html__( 'Next live group class:', 'olivia-yoga' ) . ' <b>' . esc_html( wp_date( 'F j., l, H:i', oys_ts( $soon[0]->starts_at ) ) ) . '</b></span></p>';
					}
				}
				$items = '';
				foreach ( oy_classes() as $c ) {
					$len    = oy_class_meta( $c->ID, 'oy_duration' );
					$items .= '<li>' . oy_icon( 'arrow' ) . '<span><a href="' . esc_url( oy_class_url( $c->post_name ) ) . '">' . esc_html( get_the_title( $c ) ) . '</a>' . ( $len ? ' <small>' . esc_html( $len ) . '</small>' : '' ) . '</span></li>';
				}
				return '<div class="aside-card aside-card--forest aside-card--practice"><h2>' . esc_html__( 'Practise with me, live', 'olivia-yoga' ) . '</h2>' . $next
					. ( $items ? '<ul class="aside-list aside-list--light">' . $items . '</ul>' : '' ) . oy_btn( oy_page_link( 'schedule-pricing' ), oy_schedule_label(), 'orchid' ) . '</div>';
			}
			return '<div class="aside-card"><div class="frame">' . oy_photo( 'olivia-riverside-profile', '340px' ) . '</div><h2>' . esc_html__( 'Training', 'olivia-yoga' ) . '</h2>' . oy_training_list() . oy_btn( oy_book_url(), __( 'Practice with me', 'olivia-yoga' ), 'orchid' ) . '</div>';
		case 'private-yoga':
			$price = class_exists( 'OYS_Products' ) ? OYS_Products::private_price_for( 60 ) : 9500;
			$price = function_exists( 'oys_money' ) ? oys_money( $price ) : '$' . number_format( $price / 100 );
			if ( oy_online_only() ) {
				return '<div class="aside-card aside-card--forest aside-card--compact"><p class="aside-card__kicker">' . esc_html__( 'Private online sessions', 'olivia-yoga' ) . '</p><h2>' . esc_html( sprintf( __( 'From %s', 'olivia-yoga' ), $price ) ) . '</h2>'
					. '<p>' . esc_html__( '60, 75 or 90 minutes, live on video, planned around your body and your goals. Prenatal fitness too.', 'olivia-yoga' ) . '</p>'
					. '<ul class="aside-list aside-list--light"><li>' . oy_icon( 'globe' ) . esc_html__( 'In Hungarian', 'olivia-yoga' ) . '</li><li>' . oy_icon( 'clock' ) . esc_html__( 'Reply within a day', 'olivia-yoga' ) . '</li></ul>'
					. oy_btn( oy_private_url(), __( 'Request a private session', 'olivia-yoga' ), 'orchid' ) . '</div>';
			}
			if ( oy_is_demo() ) {
				return '<div class="aside-card aside-card--forest aside-card--compact"><p class="aside-card__kicker">' . esc_html__( 'Private sessions', 'olivia-yoga' ) . '</p><h2>' . esc_html( sprintf( __( 'From %s', 'olivia-yoga' ), $price ) ) . '</h2>'
					. '<p>' . esc_html__( '60, 75 or 90 minutes in the studio or online, planned around your body and your goals.', 'olivia-yoga' ) . '</p>'
					. '<ul class="aside-list aside-list--light"><li>' . oy_icon( 'home' ) . esc_html__( 'In the studio or online', 'olivia-yoga' ) . '</li><li>' . oy_icon( 'clock' ) . esc_html__( 'Reply within a day', 'olivia-yoga' ) . '</li></ul>'
					. oy_btn( oy_private_url(), __( 'Request a private session', 'olivia-yoga' ), 'orchid' ) . '</div>';
			}
			return '<div class="aside-card aside-card--forest aside-card--compact"><p class="aside-card__kicker">' . esc_html__( 'Private sessions', 'olivia-yoga' ) . '</p><h2>' . esc_html( sprintf( __( 'From %s', 'olivia-yoga' ), $price ) ) . '</h2>'
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
				. '<li>' . oy_icon( 'mail' ) . '<a href="mailto:' . esc_attr( OY_EMAIL ) . '">' . esc_html( OY_EMAIL ) . '</a></li>'
				. ( OY_INSTAGRAM ? '<li>' . oy_icon( 'instagram' ) . '<a href="' . esc_url( OY_INSTAGRAM ) . '" target="_blank" rel="noopener">@oliivia_yoga</a></li>' : '' )
				. ( OY_FACEBOOK ? '<li>' . oy_icon( 'facebook' ) . '<a href="' . esc_url( OY_FACEBOOK ) . '" target="_blank" rel="noopener">Facebook</a></li>' : '' )
				. ( function_exists( 'oys_fb_group_url' ) && oys_fb_group_url() ? '<li>' . oy_icon( 'group', 'icon' ) . '<a href="' . esc_url( oys_fb_group_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Our Facebook group', 'olivia-yoga' ) . '</a></li>' : '' )
				. ( oy_online_only()
					? '</ul>' // Olívia, 14 November: „az online, élőben pont nem kell”
					: '<li>' . oy_icon( 'pin' ) . '<span>' . esc_html( oy_brand( 'place' ) ) . '</span></li><li>' . oy_icon( 'globe' ) . '<span>' . esc_html( oy_is_demo() ? __( 'English', 'olivia-yoga' ) : __( 'English, Hungarian', 'olivia-yoga' ) ) . '</span></li></ul>' )
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
