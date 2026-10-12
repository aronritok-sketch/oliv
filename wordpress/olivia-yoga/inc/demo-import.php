<?php
/**
 * One-click content setup (Appearance → Olivia setup): pages, class types, FAQs, journal posts,
 * past events, photos in the media library, menus, the Hungarian and Spanish translations and,
 * with the Olivia Studio plugin, the weekly timetable.
 *
 * Two modes:
 *  - Set up: creates what is missing (matched by slug). Safe to run again.
 *  - Refresh: also overwrites the pages, classes, FAQs, journal posts and past events that came with
 *    the theme with the current approved copy and photos, rebuilds the main menu and removes photos
 *    the theme no longer ships. Posts and pages added by hand, bookings and the timetable are kept.
 */

defined( 'ABSPATH' ) || exit;

const OY_PAGE_SLUGS = array( 'home' => 'home', 'about' => 'about', 'classes' => 'yoga-classes', 'private-yoga' => 'private-yoga', 'schedule-pricing' => 'schedule-pricing', 'corporate-yoga' => 'corporate-yoga', 'events' => 'events', 'faq' => 'faq', 'contact' => 'contact', 'journal' => 'journal' );
const OY_PAGE_PHOTO  = array( 'about' => 'olivia-sunset-smile', 'yoga-classes' => 'studio-group-flow', 'private-yoga' => 'private-side-angle', 'schedule-pricing' => 'garden-class-lunges', 'corporate-yoga' => 'team-warriors-gym', 'events' => 'festival-seated-teacher', 'faq' => 'olivia-childs-pose', 'contact' => 'olivia-terrace-profile', 'journal' => 'olivia-riverside-profile', 'book' => 'deck-down-dog', 'account' => 'olivia-studio-standing', 'gift-cards' => 'singing-bowls-studio' );

add_action( 'admin_menu', function () {
	add_theme_page( __( 'Olivia setup', 'olivia-yoga' ), __( 'Olivia setup', 'olivia-yoga' ), 'manage_options', 'oy-setup', 'oy_setup_page' );
} );

function oy_setup_page() {
	echo '<div class="wrap"><h1>' . esc_html__( 'Olivia Kovacs Yoga: content setup', 'olivia-yoga' ) . '</h1>';
	if ( isset( $_GET['done'] ) ) {
		echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['done'] ) ) . '</p></div>';
	}
	echo '<p>' . esc_html__( 'Creates the pages, class types, FAQs, journal posts, past events, photos and menus from the approved site copy in English, Hungarian and Spanish, and the weekly timetable in the booking plugin. Items that already exist are not changed, so it is safe to run again.', 'olivia-yoga' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="oy_import">';
	wp_nonce_field( 'oy_import' );
	submit_button( __( 'Set up the site content', 'olivia-yoga' ), 'primary', 'submit', false );
	echo '</form>';
	echo '<h2>' . esc_html__( 'Refresh the content', 'olivia-yoga' ) . '</h2><p>' . esc_html__( 'Overwrites the pages, class types, FAQs, journal posts and past events that came with the theme with the current copy, photos and translations, rebuilds the main menu and removes photos the theme no longer uses. Edits you made to those items are lost. Pages and posts you added yourself, bookings and the timetable are not touched.', 'olivia-yoga' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Overwrite the theme content with the current copy?', 'olivia-yoga' ) . '"><input type="hidden" name="action" value="oy_import"><input type="hidden" name="refresh" value="1">';
	wp_nonce_field( 'oy_import' );
	submit_button( __( 'Refresh the content', 'olivia-yoga' ), 'secondary', 'submit', false );
	echo '</form></div>';
}

add_action( 'admin_post_oy_import', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'oy_import' );
	$log = oy_import_all( ! empty( $_POST['refresh'] ) );
	wp_safe_redirect( admin_url( 'themes.php?page=oy-setup&done=' . rawurlencode( implode( ' · ', $log ) ) ) );
	exit;
} );

/** Copy a bundled photo into the media library once (matched by its key). */
function oy_import_image( $key ) {
	if ( ! $key ) {
		return 0;
	}
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

/** Store the Hungarian and Spanish versions of an item (fields that are empty are cleared). */
function oy_import_translations( $id, array $item, array $fields, $resolve = false ) {
	foreach ( array( 'hu', 'es' ) as $lang ) {
		foreach ( $fields as $field => $source ) {
			$value = (string) ( $item[ $lang ][ $source ] ?? '' );
			if ( $resolve && 'content' === $field ) {
				$value = oy_resolve_tokens( $value );
			}
			if ( '' === $value ) {
				delete_post_meta( $id, oy_tr_key( $field, $lang ) );
			} else {
				update_post_meta( $id, oy_tr_key( $field, $lang ), wp_slash( $value ) );
			}
		}
	}
}

/** Find a theme-managed post by slug; on refresh it is updated, otherwise left alone. */
function oy_import_target( $slug, $type ) {
	$post = get_page_by_path( $slug, OBJECT, $type );
	return $post ? (int) $post->ID : 0;
}

function oy_import_category( array $names ) {
	$cat = term_exists( $names[0], 'category' ) ?: wp_insert_term( $names[0], 'category' );
	$id  = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat );
	update_term_meta( $id, 'oy_hu_name', $names[1] ?? '' );
	update_term_meta( $id, 'oy_es_name', $names[2] ?? '' );
	return $id;
}

/** The next given weekday (1 = Monday … 7 = Sunday) at least $days days from now, as Y-m-d. */
function oy_import_next_weekday( $weekday, $days ) {
	$ts = time() + $days * DAY_IN_SECONDS;
	while ( (int) wp_date( 'N', $ts ) !== (int) $weekday ) {
		$ts += DAY_IN_SECONDS;
	}
	return wp_date( 'Y-m-d', $ts );
}

function oy_import_all( $refresh = false ) {
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content.json' ), true );
	$log  = array();
	@set_time_limit( 600 );

	// Class types first, so page copy can link to them.
	$n    = 0;
	$made = 0;
	foreach ( $data['classes'] as $slug => $c ) {
		$id = oy_import_target( $slug, 'oy_class' );
		if ( $id && ! $refresh ) {
			$n++;
			continue;
		}
		$args = array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => $c['title'], 'post_name' => $slug, 'post_excerpt' => $c['excerpt'], 'menu_order' => $n++ );
		if ( $id ) {
			$args['ID'] = $id;
			wp_update_post( wp_slash( $args ) );
		} else {
			$id = wp_insert_post( wp_slash( $args + array( 'post_content' => '' ) ) );
		}
		update_post_meta( $id, 'oy_duration', $c['duration'] );
		update_post_meta( $id, 'oy_lang', $c['lang'] );
		update_post_meta( $id, 'oy_seo_title', $c['seo_title'] ?? '' );
		update_post_meta( $id, 'oy_link', $c['link'] ?? '' );
		delete_post_meta( $id, 'oy_level' );
		delete_post_meta( $id, 'oy_intensity' );
		update_post_meta( $id, 'oy_needs_copy', '1' );
		if ( ! empty( $c['photo'] ) ) {
			set_post_thumbnail( $id, oy_import_image( $c['photo'] ) );
		}
		update_post_meta( $id, 'oy_aside_photo', ! empty( $c['aside'] ) ? (string) oy_import_image( $c['aside'] ) : '' );
		oy_import_translations( $id, $c, array( 'title' => 'title', 'excerpt' => 'excerpt', 'duration' => 'duration', 'lang' => 'lang', 'seo_title' => 'seo_title' ) );
		$made++;
	}
	$log[] = sprintf( '%d class types', $made );

	// Pages.
	$ids = array();
	$new = array();
	foreach ( OY_PAGE_SLUGS as $key => $slug ) {
		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			$ids[ $key ] = $existing->ID;
			if ( $refresh ) {
				$new[ $key ] = true;
			}
			continue;
		}
		$ids[ $key ] = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $data['pages'][ $key ]['title'], 'post_name' => $slug, 'post_content' => '' ) ) );
		$new[ $key ] = true;
	}
	foreach ( $new as $key => $yes ) {
		$p       = $data['pages'][ $key ];
		$content = oy_resolve_tokens( $p['content'] );
		if ( 'private-yoga' === $key ) {
			$content .= "\n<h2>" . 'Request your session' . "</h2>\n[oys_private_request]";
		}
		wp_update_post( wp_slash( array( 'ID' => $ids[ $key ], 'post_title' => $p['title'], 'post_content' => $content, 'post_excerpt' => $p['excerpt'] ?? '' ) ) );
		update_post_meta( $ids[ $key ], 'oy_seo_title', $p['seo_title'] ?? '' );
		update_post_meta( $ids[ $key ], 'oy_seo_desc', $p['seo_desc'] ?? '' );
		if ( 'private-yoga' === $key ) {
			foreach ( array( 'hu' => 'Kérj időpontot', 'es' => 'Pide tu sesión' ) as $l => $h ) {
				$p[ $l ]['content'] = ( $p[ $l ]['content'] ?? '' ) . "\n<h2>" . $h . "</h2>\n[oys_private_request]";
			}
		}
		oy_import_translations( $ids[ $key ], $p, array( 'title' => 'title', 'excerpt' => 'excerpt', 'content' => 'content', 'seo_title' => 'seo_title', 'seo_desc' => 'seo_desc' ), true );
		$slug = OY_PAGE_SLUGS[ $key ];
		if ( isset( OY_PAGE_PHOTO[ $slug ] ) ) {
			set_post_thumbnail( $ids[ $key ], oy_import_image( OY_PAGE_PHOTO[ $slug ] ) );
		}
	}
	foreach ( array( 'book', 'account', 'gift-cards' ) as $slug ) {
		$pg = get_page_by_path( $slug );
		if ( $pg && ( $refresh || ! has_post_thumbnail( $pg ) ) ) {
			set_post_thumbnail( $pg, oy_import_image( OY_PAGE_PHOTO[ $slug ] ) );
		}
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $ids['home'] );
	update_option( 'page_for_posts', $ids['journal'] );
	$log[] = sprintf( '%d pages', count( $new ) );

	// Class copy (after pages exist so their links resolve).
	foreach ( $data['classes'] as $slug => $c ) {
		$post = get_page_by_path( $slug, OBJECT, 'oy_class' );
		if ( $post && get_post_meta( $post->ID, 'oy_needs_copy', true ) ) {
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => oy_resolve_tokens( $c['content'] ) ) ) );
			oy_import_translations( $post->ID, $c, array( 'content' => 'content' ), true );
			delete_post_meta( $post->ID, 'oy_needs_copy' );
		}
	}

	// FAQs: created once; a refresh replaces them all.
	$faqs = get_posts( array( 'post_type' => 'oy_faq', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	if ( ! $faqs || $refresh ) {
		foreach ( $faqs as $old ) {
			wp_delete_post( $old, true );
		}
		foreach ( $data['faqs'] as $i => $f ) {
			$id = wp_insert_post( wp_slash( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $f['q'], 'post_content' => wpautop( esc_html( $f['a'] ) ), 'menu_order' => $i ) ) );
			update_post_meta( $id, 'oy_home', '1' === (string) $f['home'] ? '1' : '' );
			$tr = array();
			foreach ( array( 'hu', 'es' ) as $l ) {
				$tr[ $l ] = array( 'title' => $f[ $l ]['q'], 'content' => wpautop( esc_html( $f[ $l ]['a'] ) ) );
			}
			oy_import_translations( $id, $tr, array( 'title' => 'title', 'content' => 'content' ) );
		}
		$log[] = sprintf( '%d FAQs', count( $data['faqs'] ) );
	}

	// Journal posts.
	$posts = 0;
	foreach ( $data['posts'] as $slug => $p ) {
		$id = oy_import_target( $slug, 'post' );
		if ( $id && ! $refresh ) {
			continue;
		}
		$args = array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $p['title'],
			'post_name'     => $slug,
			'post_excerpt'  => $p['excerpt'],
			'post_content'  => oy_resolve_tokens( $p['content'] ),
			'post_date'     => wp_date( 'Y-m-d 09:00:00', time() - (int) $p['days_ago'] * DAY_IN_SECONDS ),
			'post_category' => array( oy_import_category( $p['category'] ) ),
		);
		if ( $id ) {
			$args['ID'] = $id;
			unset( $args['post_date'] );
			wp_update_post( wp_slash( $args ) );
		} else {
			$id = wp_insert_post( wp_slash( $args ) );
		}
		if ( ! empty( $p['photo'] ) ) {
			set_post_thumbnail( $id, oy_import_image( $p['photo'] ) );
		}
		update_post_meta( $id, 'oy_art', $p['art'] ?? '' );
		oy_import_translations( $id, $p, array( 'title' => 'title', 'excerpt' => 'excerpt', 'content' => 'content' ), true );
		$posts++;
	}
	$log[] = sprintf( '%d journal posts', $posts );
	$hello = get_post( 1 );
	if ( $hello && 'hello-world' === $hello->post_name ) {
		wp_delete_post( 1, true );
	}

	// Past events (with photos).
	$moments = 0;
	foreach ( $data['moments'] as $i => $m ) {
		$slug = sanitize_title( $m['date'] . '-' . $m['title'] );
		$id   = oy_import_target( $slug, 'oy_moment' );
		if ( $id && ! $refresh ) {
			continue;
		}
		$args = array( 'post_type' => 'oy_moment', 'post_status' => 'publish', 'post_title' => $m['title'], 'post_name' => $slug, 'post_content' => wpautop( esc_html( $m['text'] ) ), 'menu_order' => $i );
		if ( $id ) {
			$args['ID'] = $id;
			wp_update_post( wp_slash( $args ) );
		} else {
			$id = wp_insert_post( wp_slash( $args ) );
		}
		update_post_meta( $id, 'oy_date', $m['date'] );
		update_post_meta( $id, 'oy_place', $m['place'] );
		set_post_thumbnail( $id, oy_import_image( $m['photo'] ) );
		$tr = array();
		foreach ( array( 'hu', 'es' ) as $l ) {
			$tr[ $l ] = array( 'title' => $m[ $l ]['title'], 'place' => $m[ $l ]['place'], 'content' => wpautop( esc_html( $m[ $l ]['text'] ) ) );
		}
		oy_import_translations( $id, $tr, array( 'title' => 'title', 'place' => 'place', 'content' => 'content' ) );
		$moments++;
	}
	$log[] = sprintf( '%d past events', $moments );

	// Menus.
	$main = wp_get_nav_menu_object( 'Main' );
	if ( $main && $refresh ) {
		wp_delete_nav_menu( $main->term_id );
		$main = null;
	}
	if ( ! $main ) {
		$menu = wp_create_nav_menu( 'Main' );
		$add  = fn( $title, $parent = 0, $obj = null ) => wp_update_nav_menu_item( $menu, 0, array_filter( array(
			'menu-item-title'     => $title,
			'menu-item-object'    => $obj->post_type,
			'menu-item-object-id' => $obj->ID,
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent,
		) ) );
		$classes = $add( 'Classes', 0, get_post( $ids['classes'] ) );
		foreach ( oy_classes() as $c ) {
			if ( ! get_post_meta( $c->ID, 'oy_link', true ) ) {
				$add( '', $classes, $c );
			}
		}
		$labels = array( 'private-yoga' => 'Private yoga', 'schedule-pricing' => 'Schedule & pricing', 'corporate-yoga' => 'Teams & athletes', 'events' => 'Events', 'about' => 'About', 'journal' => 'Journal', 'contact' => 'Contact' );
		foreach ( $labels as $key => $label ) {
			$add( $label, 0, get_post( $ids[ $key ] ) );
		}
		set_theme_mod( 'nav_menu_locations', array_merge( (array) get_theme_mod( 'nav_menu_locations', array() ), array( 'primary' => $menu ) ) );
		$log[] = 'menu';
	}

	// Photos the theme no longer ships.
	if ( $refresh ) {
		$keep    = oy_images();
		$removed = 0;
		foreach ( get_posts( array( 'post_type' => 'attachment', 'meta_key' => 'oy_key', 'numberposts' => -1, 'fields' => 'ids' ) ) as $att ) {
			if ( empty( $keep[ get_post_meta( $att, 'oy_key', true ) ] ) ) {
				wp_delete_attachment( $att, true );
				$removed++;
			}
		}
		$log[] = sprintf( '%d old photos removed', $removed );
	}

	// Timetable (booking plugin), only on a fresh site.
	if ( class_exists( 'OYS_Schedule' ) && ! OYS_Schedule::templates() ) {
		foreach ( $data['sessions'] as $s ) {
			[ $sh, $sm ] = array_map( 'intval', explode( ':', $s['start'] ) );
			[ $eh, $em ] = array_map( 'intval', explode( ':', $s['end'] ) );
			OYS_Schedule::save_template( array(
				'class_slug'      => $s['class'],
				'weekday'         => (int) $s['day'],
				'start_time'      => sprintf( '%02d:%02d', $sh, $sm ),
				'duration_min'    => ( $eh * 60 + $em ) - ( $sh * 60 + $sm ),
				'capacity'        => 12,
				'location'        => $s['location'],
				'format'          => $s['format'] ?? 'studio',
				'online_capacity' => 'studio' === ( $s['format'] ?? 'studio' ) ? 0 : 20,
				'price_cents'     => 'online' === ( $s['format'] ?? '' ) ? 1500 : 2500,
				'lang'            => $s['lang'] ?? 'en',
				'note'            => $s['note'],
				'active'          => 1,
			) );
		}
		$made = OYS_Schedule::generate();
		foreach ( $data['events'] as $ev ) {
			$utc         = oys_local_to_utc( oy_import_next_weekday( (int) $ev['weekday'], 30 ) . ' ' . $ev['start'] );
			[ $sh, $sm ] = array_map( 'intval', explode( ':', $ev['start'] ) );
			[ $eh, $em ] = array_map( 'intval', explode( ':', $ev['end'] ) );
			OYS_Schedule::save( array(
				'kind'            => 'event',
				'class_slug'      => 'sound-yoga',
				'title'           => $ev['title'],
				'description'     => $ev['content'],
				'starts_at'       => $utc,
				'ends_at'         => gmdate( 'Y-m-d H:i:s', oys_ts( $utc ) + ( ( $eh * 60 + $em ) - ( $sh * 60 + $sm ) ) * 60 ),
				'capacity'        => 20,
				'location'        => $ev['venue'],
				'price_cents'     => oys_cents_from_input( $ev['price'] ),
				'credits_allowed' => 0,
				'lang'            => $ev['lang'] ?? 'en',
				'note'            => 'Sample event',
				'status'          => 'scheduled',
			) );
		}
		$log[] = sprintf( 'timetable: %d weekly classes, %d dates', count( $data['sessions'] ), $made );
	}
	flush_rewrite_rules();
	return $log;
}
