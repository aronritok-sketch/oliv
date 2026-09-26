<?php
/**
 * One-click content setup (Appearance → Olivia setup): pages, class types, FAQs, journal posts,
 * photos in the media library, menus, and — with the Olivia Studio plugin — the weekly timetable.
 * Safe to run again: existing items (matched by slug) are left alone.
 */

defined( 'ABSPATH' ) || exit;

const OY_CLASS_PHOTO = array( 'hatha-flow' => 'hatha-flow-studio-class', 'slow-flow' => 'final-relaxation', 'private-yoga' => 'olivia-golden-hour-prayer', 'sound-yoga' => 'sound-yoga-gong-bowls', 'office-yoga' => 'evening-lawn-yoga', 'yoga-for-athletes' => 'outdoor-bridge-pose', 'online-yoga' => 'small-group-class' );
const OY_CLASS_ASIDE = array( 'hatha-flow' => 'group-class-chair-pose', 'slow-flow' => 'evening-slow-flow', 'sound-yoga' => 'garden-yoga-evening', 'yoga-for-athletes' => 'outdoor-class-garden', 'online-yoga' => 'final-relaxation' );
const OY_POST_PHOTO  = array( 'private-yoga-fort-myers-what-to-expect' => 'tree-pose-meadow', 'hatha-flow-vs-slow-flow' => 'evening-slow-flow', 'sunrise-beach-yoga-fort-myers' => 'dancer-pose-beach' );
const OY_PAGE_PHOTO  = array( 'about' => 'olivia-studio-mat', 'yoga-classes' => 'hatha-flow-group-warrior', 'private-yoga' => 'dancer-pose-forest', 'schedule-pricing' => 'group-class-chair-pose', 'corporate-yoga' => 'team-yoga-outdoors', 'events' => 'festival-pavilion-class', 'faq' => 'small-group-class', 'contact' => 'olivia-outdoor-class', 'journal' => 'olivia-riverside-profile', 'book' => 'hatha-flow-group-warrior', 'account' => 'olivia-studio-mat', 'gift-cards' => 'festival-dance' );
const OY_CLASS_LINK  = array( 'private-yoga' => 'private-yoga', 'office-yoga' => 'corporate-yoga' );

add_action( 'admin_menu', function () {
	add_theme_page( __( 'Olivia setup', 'olivia-yoga' ), __( 'Olivia setup', 'olivia-yoga' ), 'manage_options', 'oy-setup', 'oy_setup_page' );
} );

function oy_setup_page() {
	echo '<div class="wrap"><h1>' . esc_html__( 'Olivia Kovács Yoga — content setup', 'olivia-yoga' ) . '</h1>';
	if ( isset( $_GET['done'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['done'] ) ) . '</p></div>';
	}
	echo '<p>' . esc_html__( 'Creates the pages, class types, FAQs, journal posts, photos and menus from the approved site copy, and the weekly timetable in the booking plugin. Items that already exist are not changed, so it is safe to run again.', 'olivia-yoga' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="oy_import">';
	wp_nonce_field( 'oy_import' );
	submit_button( __( 'Set up the site content', 'olivia-yoga' ) );
	echo '</form></div>';
}

add_action( 'admin_post_oy_import', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'oy_import' );
	$log = oy_import_all();
	wp_safe_redirect( admin_url( 'themes.php?page=oy-setup&done=' . rawurlencode( implode( ' · ', $log ) ) ) );
	exit;
} );

/** Copy a bundled photo into the media library once (matched by its key). */
function oy_import_image( $key ) {
	$found = get_posts( array( 'post_type' => 'attachment', 'meta_key' => 'oy_key', 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids' ) );
	if ( $found ) {
		return (int) $found[0];
	}
	$imgs = oy_images();
	$src  = get_template_directory() . '/assets/img/' . $key . '-1200.jpg';
	if ( empty( $imgs[ $key ] ) || ! file_exists( $src ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$tmp = wp_tempnam( $key . '.jpg' );
	copy( $src, $tmp );
	$id = media_handle_sideload( array( 'name' => $key . '.jpg', 'tmp_name' => $tmp ), 0, $imgs[ $key ]['alt'] );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return 0;
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $imgs[ $key ]['alt'] );
	update_post_meta( $id, 'oy_key', $key );
	update_post_meta( $id, 'oy_pos', $imgs[ $key ]['pos'] );
	return (int) $id;
}

function oy_import_all() {
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content.json' ), true );
	$log  = array();
	@set_time_limit( 300 );

	// Class types first, so page copy can link to them.
	$n = 0;
	foreach ( $data['classes'] as $slug => $c ) {
		if ( get_page_by_path( $slug, OBJECT, 'oy_class' ) ) {
			continue;
		}
		$id = wp_insert_post( array(
			'post_type'    => 'oy_class',
			'post_status'  => 'publish',
			'post_title'   => $c['title'],
			'post_name'    => $slug,
			'post_excerpt' => $c['excerpt'],
			'post_content' => '',
			'menu_order'   => $n++,
		) );
		update_post_meta( $id, 'oy_duration', $c['duration'] );
		update_post_meta( $id, 'oy_level', $c['level'] );
		update_post_meta( $id, 'oy_intensity', (string) $c['intensity'] );
		update_post_meta( $id, 'oy_seo_title', $c['seo_title'] ?? '' );
		if ( isset( OY_CLASS_LINK[ $slug ] ) ) {
			update_post_meta( $id, 'oy_link', OY_CLASS_LINK[ $slug ] );
		}
		if ( isset( OY_CLASS_PHOTO[ $slug ] ) ) {
			set_post_thumbnail( $id, oy_import_image( OY_CLASS_PHOTO[ $slug ] ) );
		}
		if ( isset( OY_CLASS_ASIDE[ $slug ] ) ) {
			update_post_meta( $id, 'oy_aside_photo', (string) oy_import_image( OY_CLASS_ASIDE[ $slug ] ) );
		}
	}
	$log[] = sprintf( '%d class types', $n );

	// Pages.
	$slugs = array( 'home' => 'home', 'about' => 'about', 'classes' => 'yoga-classes', 'private-yoga' => 'private-yoga', 'schedule-pricing' => 'schedule-pricing', 'corporate-yoga' => 'corporate-yoga', 'events' => 'events', 'faq' => 'faq', 'contact' => 'contact', 'journal' => 'journal' );
	$ids   = array();
	foreach ( $slugs as $key => $slug ) {
		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			$ids[ $key ] = $existing->ID;
			continue;
		}
		$ids[ $key ] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $data['pages'][ $key ]['title'], 'post_name' => $slug, 'post_content' => '' ) );
		$ids[ $key . '_new' ] = true;
	}
	$made = 0;
	foreach ( $slugs as $key => $slug ) {
		if ( empty( $ids[ $key . '_new' ] ) ) {
			continue;
		}
		$p       = $data['pages'][ $key ];
		$content = oy_resolve_tokens( $p['content'] );
		if ( 'schedule-pricing' === $key ) {
			$content = preg_replace( '#<p>Give a single class.*?</p>#s', '<p>Give a class pass or a private session. The gift card is emailed straight to the person you choose, with a code they redeem in their account.</p>', $content );
		}
		if ( 'private-yoga' === $key ) {
			$content .= "\n<h2>Request your session</h2>\n[oys_private_request]";
		}
		wp_update_post( array( 'ID' => $ids[ $key ], 'post_content' => $content, 'post_excerpt' => $p['excerpt'] ?? '' ) );
		update_post_meta( $ids[ $key ], 'oy_seo_title', $p['seo_title'] ?? '' );
		update_post_meta( $ids[ $key ], 'oy_seo_desc', $p['seo_desc'] ?? '' );
		if ( isset( OY_PAGE_PHOTO[ $slug ] ) ) {
			set_post_thumbnail( $ids[ $key ], oy_import_image( OY_PAGE_PHOTO[ $slug ] ) );
		}
		$made++;
	}
	foreach ( array( 'book', 'account', 'gift-cards' ) as $slug ) {
		$pg = get_page_by_path( $slug );
		if ( $pg && ! has_post_thumbnail( $pg ) ) {
			set_post_thumbnail( $pg, oy_import_image( OY_PAGE_PHOTO[ $slug ] ) );
		}
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $ids['home'] );
	update_option( 'page_for_posts', $ids['journal'] );
	$log[] = sprintf( '%d pages', $made );

	// Class copy (after pages exist so their links resolve).
	foreach ( $data['classes'] as $slug => $c ) {
		$post = get_page_by_path( $slug, OBJECT, 'oy_class' );
		if ( $post && '' === $post->post_content ) {
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => oy_resolve_tokens( $c['content'] ) ) );
		}
	}

	// FAQs.
	if ( ! get_posts( array( 'post_type' => 'oy_faq', 'numberposts' => 1, 'fields' => 'ids' ) ) ) {
		foreach ( $data['faqs'] as $i => $f ) {
			$id = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $f['q'], 'post_content' => wpautop( esc_html( $f['a'] ) ), 'menu_order' => $i ) );
			update_post_meta( $id, 'oy_home', '1' === (string) $f['home'] ? '1' : '' );
		}
		$log[] = sprintf( '%d FAQs', count( $data['faqs'] ) );
	}

	// Journal posts.
	$posts = 0;
	foreach ( $data['posts'] as $slug => $p ) {
		if ( get_page_by_path( $slug, OBJECT, 'post' ) ) {
			continue;
		}
		$cat  = term_exists( $p['category'], 'category' ) ?: wp_insert_term( $p['category'], 'category' );
		$date = wp_date( 'Y-m-d 09:00:00', time() - (int) $p['days_ago'] * DAY_IN_SECONDS );
		$id   = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $p['title'], 'post_name' => $slug, 'post_excerpt' => $p['excerpt'], 'post_content' => oy_resolve_tokens( $p['content'] ), 'post_date' => $date, 'post_category' => array( (int) ( is_array( $cat ) ? $cat['term_id'] : $cat ) ) ) );
		if ( isset( OY_POST_PHOTO[ $slug ] ) ) {
			set_post_thumbnail( $id, oy_import_image( OY_POST_PHOTO[ $slug ] ) );
		}
		$posts++;
	}
	$log[] = sprintf( '%d journal posts', $posts );
	$hello = get_post( 1 );
	if ( $hello && 'hello-world' === $hello->post_name ) {
		wp_delete_post( 1, true );
	}

	// Menus.
	if ( ! wp_get_nav_menu_object( 'Main' ) ) {
		$menu = wp_create_nav_menu( 'Main' );
		$add  = fn( $title, $url, $parent = 0, $obj = null ) => wp_update_nav_menu_item( $menu, 0, array_filter( array(
			'menu-item-title'     => $title,
			'menu-item-url'       => $obj ? '' : $url,
			'menu-item-object'    => $obj ? $obj->post_type : '',
			'menu-item-object-id' => $obj ? $obj->ID : '',
			'menu-item-type'      => $obj ? 'post_type' : 'custom',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent,
		) ) );
		$classes = $add( 'Classes', '', 0, get_post( $ids['classes'] ) );
		foreach ( oy_classes() as $c ) {
			if ( ! get_post_meta( $c->ID, 'oy_link', true ) ) {
				$add( $c->post_title, '', $classes, $c );
			}
		}
		foreach ( array( 'private-yoga' => 'Private yoga', 'schedule-pricing' => 'Schedule & pricing', 'corporate-yoga' => 'Corporate', 'events' => 'Events', 'about' => 'About', 'journal' => 'Journal', 'contact' => 'Contact' ) as $key => $label ) {
			$add( $label, '', 0, get_post( $ids[ $key ] ) );
		}
		set_theme_mod( 'nav_menu_locations', array_merge( (array) get_theme_mod( 'nav_menu_locations', array() ), array( 'primary' => $menu ) ) );
		$log[] = 'menu';
	}

	// Timetable (booking plugin).
	if ( class_exists( 'OYS_Schedule' ) && ! OYS_Schedule::templates() ) {
		foreach ( $data['sessions'] as $s ) {
			[ $sh, $sm ] = array_map( 'intval', explode( ':', $s['start'] ) );
			[ $eh, $em ] = array_map( 'intval', explode( ':', $s['end'] ) );
			OYS_Schedule::save_template( array(
				'class_slug'   => $s['class'],
				'weekday'      => (int) $s['day'],
				'start_time'   => sprintf( '%02d:%02d', $sh, $sm ),
				'duration_min' => ( $eh * 60 + $em ) - ( $sh * 60 + $sm ),
				'capacity'     => 12,
				'location'     => $s['location'],
				'price_cents'  => 2500,
				'note'         => $s['note'],
				'active'       => 1,
			) );
		}
		$made = OYS_Schedule::generate();
		foreach ( $data['events'] as $ev ) {
			$start = wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) . ' ' . $ev['start'];
			$utc   = oys_local_to_utc( $start );
			[ $sh, $sm ] = array_map( 'intval', explode( ':', $ev['start'] ) );
			[ $eh, $em ] = array_map( 'intval', explode( ':', $ev['end'] ) );
			OYS_Schedule::save( array(
				'kind'            => 'event',
				'class_slug'      => 'sound-yoga',
				'title'           => str_replace( ' (sample — edit before publishing)', '', $ev['title'] ),
				'description'     => wp_strip_all_tags( $ev['content'] ),
				'starts_at'       => $utc,
				'ends_at'         => gmdate( 'Y-m-d H:i:s', oys_ts( $utc ) + ( ( $eh * 60 + $em ) - ( $sh * 60 + $sm ) ) * 60 ),
				'capacity'        => 20,
				'location'        => $ev['venue'],
				'price_cents'     => oys_cents_from_input( $ev['price'] ),
				'credits_allowed' => 0,
				'note'            => 'Sample event',
				'status'          => 'scheduled',
			) );
		}
		$log[] = sprintf( 'timetable: %d weekly classes, %d dates', count( $data['sessions'] ), $made );
	}
	flush_rewrite_rules();
	return $log;
}
