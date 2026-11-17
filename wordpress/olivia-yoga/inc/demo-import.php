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
	echo '<h2>' . esc_html__( 'Edition', 'olivia-yoga' ) . '</h2><p>' . esc_html__( 'Which version of the site this is. Switching sets the languages, currency, prices and time zone for that edition; do it on a fresh site, before setting up the content.', 'olivia-yoga' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="oy_edition">';
	wp_nonce_field( 'oy_edition' );
	echo '<select name="edition"' . ( defined( 'OY_EDITION' ) ? ' disabled' : '' ) . '>';
	foreach ( OY_EDITIONS as $key => $label ) {
		echo '<option value="' . esc_attr( $key ) . '"' . selected( oy_edition(), $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select> ';
	submit_button( __( 'Switch edition', 'olivia-yoga' ), 'secondary', 'submit', false, defined( 'OY_EDITION' ) ? array( 'disabled' => 'disabled' ) : null );
	echo '</form><h2>' . esc_html__( 'Content', 'olivia-yoga' ) . '</h2>';
	echo '<p>' . esc_html__( 'Creates the pages, class types, FAQs, journal posts, past events, photos and menus from the approved site copy of this edition, and the weekly timetable in the booking plugin. Items that already exist are not changed, so it is safe to run again.', 'olivia-yoga' ) . '</p>';
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

add_action( 'admin_post_oy_edition', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', 403 );
	}
	check_admin_referer( 'oy_edition' );
	$log = defined( 'OY_EDITION' ) ? array() : oy_switch_edition( sanitize_key( wp_unslash( $_POST['edition'] ?? '' ) ) );
	flush_rewrite_rules();
	wp_safe_redirect( admin_url( 'themes.php?page=oy-setup&done=' . rawurlencode( implode( ' · ', $log ) ) ) );
	exit;
} );

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
	$src  = get_template_directory() . '/assets/img/' . oy_image_file( $key ) . '-1200.jpg';
	if ( empty( $imgs[ $key ] ) || ! file_exists( $src ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$name = oy_image_slug( $key );
	$alt  = oy_online_only() && ! empty( $imgs[ $key ]['hu']['alt'] ) ? $imgs[ $key ]['hu']['alt'] : $imgs[ $key ]['alt'];
	$tmp  = wp_tempnam( $name . '.jpg' );
	copy( $src, $tmp );
	$id = media_handle_sideload( array( 'name' => $name . '.jpg', 'tmp_name' => $tmp ), 0, $alt );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return 0;
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
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

/**
 * Dates a weekly class leaves out (Olivia: no class on 23 December): cancelled, so the timetable doesn't make them again.
 * Dates far ahead are made first.
 */
function oy_import_skip_dates( $template_id, array $dates ) {
	$dates = array_filter( $dates, fn( $d ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) && strtotime( $d ) > time() - DAY_IN_SECONDS );
	if ( ! $dates || ! class_exists( 'OYS_Schedule' ) ) {
		return 0;
	}
	$weeks = (int) ceil( ( strtotime( max( $dates ) ) - time() ) / WEEK_IN_SECONDS ) + 1;
	OYS_Schedule::generate( max( (int) OYS_Settings::get( 'weeks_ahead' ), $weeks ) );
	$n = 0;
	foreach ( OYS_Schedule::query( array( 'from' => oys_now(), 'status' => 'scheduled' ) ) as $row ) {
		if ( (int) $row->template_id === (int) $template_id && in_array( wp_date( 'Y-m-d', oys_ts( $row->starts_at ) ), $dates, true ) ) {
			OYS_Schedule::cancel_session( $row->id, 'Ezen a napon nincs óra.' );
			$n++;
		}
	}
	return $n;
}

function oy_import_all( $refresh = false ) {
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$data += array( 'moments' => array(), 'events' => array(), 'posts' => array(), 'faqs' => array(), 'sessions' => array() );
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
		if ( empty( $data['pages'][ $key ] ) ) {
			continue;
		}
		$slug     = oy_page_slug( $slug );
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
		if ( 'private-yoga' === $key && false === strpos( $content, '[oys_private_request]' ) ) {
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
		$photo = $p['photo'] ?? ( OY_PAGE_PHOTO[ OY_PAGE_SLUGS[ $key ] ] ?? '' );
		if ( $photo ) {
			set_post_thumbnail( $ids[ $key ], oy_import_image( $photo ) );
		}
	}
	// The booking plugin's own pages: the edition's title and address, and a photo.
	foreach ( array( 'book' => 'book', 'account' => 'account', 'gifts' => 'gift-cards' ) as $key => $slug ) {
		$pg = get_post( (int) get_option( 'oys_page_' . $key ) ) ?: get_page_by_path( $slug );
		if ( ! $pg ) {
			continue;
		}
		$own = $data['studio_pages'][ $key ] ?? array();
		if ( $own && ( $refresh || $pg->post_name !== $own['slug'] ) ) {
			wp_update_post( wp_slash( array_filter( array( 'ID' => $pg->ID, 'post_title' => $own['title'] ?? '', 'post_name' => $own['slug'] ?? '', 'post_content' => $own['content'] ?? '' ) ) ) );
		}
		if ( $refresh || ! has_post_thumbnail( $pg ) ) {
			set_post_thumbnail( $pg, oy_import_image( $own['photo'] ?? OY_PAGE_PHOTO[ $slug ] ) );
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
				$tr[ $l ] = array( 'title' => $f[ $l ]['q'] ?? '', 'content' => isset( $f[ $l ]['a'] ) ? wpautop( esc_html( $f[ $l ]['a'] ) ) : '' );
			}
			oy_import_translations( $id, $tr, array( 'title' => 'title', 'content' => 'content' ) );
		}
		$log[] = sprintf( '%d FAQs', count( $data['faqs'] ) );
	}

	// Journal posts (the online edition's first English addresses become Hungarian first, so nothing is doubled).
	$log   = array_merge( $log, oy_online_post_slugs() );
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
	$log   = array_merge( $log, oy_remove_wp_samples(), oy_import_privacy( $data, $refresh ), oy_import_legal( $data, $refresh ) );

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
			$tr[ $l ] = array( 'title' => $m[ $l ]['title'] ?? '', 'place' => $m[ $l ]['place'] ?? '', 'content' => isset( $m[ $l ]['text'] ) ? wpautop( esc_html( $m[ $l ]['text'] ) ) : '' );
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
		$labels  = $data['menu'] ?? array( 'classes' => 'Classes', 'private-yoga' => 'Private yoga', 'schedule-pricing' => 'Schedule & pricing', 'corporate-yoga' => 'Teams & athletes', 'events' => 'Events', 'about' => 'About', 'journal' => 'Journal', 'contact' => 'Contact' );
		$classes = $add( $labels['classes'] ?? 'Classes', 0, get_post( $ids['classes'] ) );
		foreach ( oy_classes() as $c ) {
			if ( ! get_post_meta( $c->ID, 'oy_link', true ) ) {
				$add( '', $classes, $c );
			}
		}
		unset( $labels['classes'] );
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $ids[ $key ] ) ) {
				$add( $label, 0, get_post( $ids[ $key ] ) );
			}
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
				'capacity'        => (int) ( $s['capacity'] ?? 12 ),
				'location'        => $s['location'],
				'format'          => $s['format'] ?? 'studio',
				'online_capacity' => 'studio' === ( $s['format'] ?? 'studio' ) ? 0 : (int) ( $s['online_capacity'] ?? 20 ),
				'price_cents'     => isset( $s['price'] ) ? oys_cents_from_input( $s['price'] ) : ( 'online' === ( $s['format'] ?? '' ) ? 1500 : 2500 ),
				'lang'            => $s['lang'] ?? 'en',
				'note'            => $s['note'],
				'active'          => 1,
				'valid_from'      => $s['valid_from'] ?? null,
			) );
		}
		$made = OYS_Schedule::generate();
		foreach ( OYS_Schedule::templates() as $t ) {
			foreach ( $data['sessions'] as $s ) {
				if ( ! empty( $s['skip'] ) && $t->class_slug === $s['class'] && (int) $t->weekday === (int) $s['day'] ) {
					oy_import_skip_dates( (int) $t->id, (array) $s['skip'] );
				}
			}
		}
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

/**
 * The online site (hu-online) after Olivia's notes of 12 November 2026, on a site set up before:
 *  - rev 1 (her oliviajoga.hu doc): the new prices, Wednesday 18:30–20:00 as the only weekly class, the prenatal class
 *    at /online-orak/kismamatorna/;
 *  - rev 2 (her doc „Módosítások online jóga”): the one-to-one services (individual online yoga, prenatal, stretching,
 *    relaxation and breathing) with their passes, no slow flow, no private sessions page (in the classes page now),
 *    „Jógáról” instead of „Napló” (/jogarol/), the Wednesday class from 16 December without 23 December, own streaming.
 * The class texts, pages, FAQs and menu come from the content file. Runs once (option oy_online_rev); on a fresh site
 * the normal import does all this.
 */
/** The online edition's journal posts: the first, English addresses get their Hungarian ones (the old ones redirect). */
function oy_online_post_slugs() {
	if ( ! oy_online_only() ) {
		return array();
	}
	$n = 0;
	foreach ( OY_ONLINE_POST_SLUGS + OY_ONLINE_POST_MOVED as $en => $hu ) {
		$post = get_page_by_path( $en, OBJECT, 'post' );
		if ( $post && ! get_page_by_path( $hu, OBJECT, 'post' ) ) {
			wp_update_post( array( 'ID' => $post->ID, 'post_name' => $hu ) );
			$n++;
		}
	}
	return $n ? array( sprintf( '%d journal posts: Hungarian addresses', $n ) ) : array();
}

/** WordPress's own sample content ("Hello world!", "Sample Page"), in any language – never on a client site. */
function oy_remove_wp_samples() {
	$log   = array();
	$hello = get_post( 1 );
	if ( $hello && 'post' === $hello->post_type && in_array( $hello->post_name, array( 'hello-world', 'hello-vilag', 'hola-mundo' ), true ) ) {
		wp_delete_post( 1, true );
		$log[] = 'removed: the sample post';
	}
	foreach ( array( 'sample-page', 'minta-oldal', 'ez-egy-minta-oldal', 'pagina-ejemplo' ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page && in_array( $page->post_title, array( 'Sample Page', 'Minta oldal', 'Ez egy minta oldal', 'Página de ejemplo' ), true ) ) {
			wp_delete_post( $page->ID, true );
			$log[] = 'removed: the sample page';
		}
	}
	return $log;
}

/**
 * The privacy notice (the content file's "privacy"): a published page, set as the site's privacy policy (the footer
 * links to it). A policy page someone wrote and published themselves is kept; ours is rewritten only on refresh.
 */
function oy_import_privacy( array $data, $refresh = false ) {
	if ( empty( $data['privacy']['content'] ) ) {
		return array();
	}
	$pv   = $data['privacy'];
	$page = get_post( (int) get_option( 'wp_page_for_privacy_policy' ) );
	$ours = $page && get_post_meta( $page->ID, 'oy_privacy', true );
	if ( $page && 'publish' === $page->post_status && ! $ours ) {
		return array();
	}
	if ( ! $page || 'trash' === $page->post_status ) {
		$page = get_page_by_path( $pv['slug'] );
		$ours = $page && get_post_meta( $page->ID, 'oy_privacy', true );
	}
	if ( $ours && 'publish' === $page->post_status && ! $refresh ) {
		update_option( 'wp_page_for_privacy_policy', $page->ID );
		return array();
	}
	$args = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $pv['title'],
		'post_name'    => $pv['slug'],
		'post_content' => strtr( $pv['content'], array( '{email}' => OY_EMAIL, '{teacher}' => oy_teacher_name(), '{site}' => oy_site_name() ) ),
	);
	if ( $page ) {
		$args['ID'] = $page->ID;
		wp_update_post( wp_slash( $args ) );
		$id = $page->ID;
	} else {
		$id = wp_insert_post( wp_slash( $args ) );
	}
	update_post_meta( $id, 'oy_privacy', 1 );
	update_post_meta( $id, 'oy_seo_desc', $pv['seo_desc'] ?? '' );
	update_option( 'wp_page_for_privacy_policy', $id );
	return array( 'privacy notice: /' . $pv['slug'] . '/' );
}

/**
 * The legal pages of the content file's "legal" list (the online edition: ÁSZF and Impresszum, Arovia Group LLC as the
 * seller – Áron, 11.13). Like the privacy notice: a page with the same address that someone wrote themselves is kept;
 * ours is rewritten only on refresh. {email}, {privacy} and {terms} are filled in.
 */
function oy_import_legal( array $data, $refresh = false ) {
	$log = array();
	foreach ( (array) ( $data['legal'] ?? array() ) as $l ) {
		if ( empty( $l['slug'] ) || empty( $l['content'] ) ) {
			continue;
		}
		$page = get_page_by_path( $l['slug'] );
		$ours = $page && get_post_meta( $page->ID, 'oy_legal', true );
		if ( $page && 'publish' === $page->post_status && ( ! $ours || ! $refresh ) ) {
			continue;
		}
		$args = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $l['title'], 'post_name' => $l['slug'], 'post_content' => '' );
		if ( $page ) {
			$args['ID'] = $page->ID;
			wp_update_post( wp_slash( $args ) );
			$id = $page->ID;
		} else {
			$id = wp_insert_post( wp_slash( $args ) );
		}
		update_post_meta( $id, 'oy_legal', $l['slug'] );
		update_post_meta( $id, 'oy_seo_desc', $l['seo_desc'] ?? '' );
		$log[] = 'legal: /' . $l['slug'] . '/';
	}
	// the text last, when every legal page has its address (they link to each other)
	foreach ( (array) ( $data['legal'] ?? array() ) as $l ) {
		$page = empty( $l['slug'] ) ? null : get_page_by_path( $l['slug'] );
		if ( $page && get_post_meta( $page->ID, 'oy_legal', true ) && ( $refresh || '' === trim( $page->post_content ) ) ) {
			$terms = get_page_by_path( 'aszf' );
			wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_content' => strtr( $l['content'], array(
				'{email}'   => OY_EMAIL,
				'{privacy}' => get_privacy_policy_url() ?: home_url( '/adatvedelem/' ),
				'{terms}'   => $terms ? get_permalink( $terms ) : home_url( '/aszf/' ),
			) ) ) ) );
		}
	}
	return $log;
}

/**
 * Settings the edition got after a site was set up (setup.studio in the content file): the sender, reply-to and
 * notification addresses, and the birthday code and loyalty draw switched off on the online site (Áron, 2026-11-13:
 * only what the online yoga uses). Once, and only where the value is still the plugin's default, so anything someone
 * set in the admin stays.
 */
function oy_mail_setup() {
	$rev = '2026-11-13b';
	if ( ! class_exists( 'OYS_Settings' ) || get_option( 'oy_mail_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_mail_rev', $rev );
	$data  = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$admin    = strtolower( (string) get_option( 'admin_email' ) );
	$defaults = array( 'email_from' => $admin, 'email_reply_to' => '', 'notify_email' => $admin, 'birthday_on' => '1', 'raffle_period' => 'half', 'pay_provider' => 'stripe' );
	// 13 November, evening (Áron): Arovia Group LLC takes the payments, through the HelloProVision CRM – but a site
	// that already has its own Stripe key keeps it
	if ( method_exists( 'OYS_Settings', 'pay_provider' ) && '' !== (string) OYS_Settings::stripe_secret() ) {
		unset( $defaults['pay_provider'] );
	}
	$want     = array_intersect_key( (array) ( $data['setup']['studio'] ?? array() ), $defaults );
	$set      = array();
	foreach ( $want as $k => $v ) {
		$have = strtolower( (string) OYS_Settings::get( $k ) );
		if ( (string) $v !== $have && ( '' === $have || (string) $defaults[ $k ] === $have ) ) {
			$set[ $k ] = $v;
		}
	}
	if ( ! $set ) {
		return array();
	}
	OYS_Settings::update( $set );
	return array( 'settings: ' . implode( ', ', array_map( fn( $k, $v ) => $k . ' ' . $v, array_keys( $set ), $set ) ) );
}

/**
 * Olivia's notes of 13 November („oliviajoga.online módosítások 11.13.”) on a live online site: the content that came
 * with the theme gets the new copy once (no „magyarul”, „Bejelentkezés és árak”, the nine prenatal modules) and the menu
 * is rebuilt. Bookings, the timetable, prices and pages someone added are not touched. Olivia had no admin login on
 * the live site before this, so there is nothing of hers to overwrite.
 */
function oy_migrate_1113() {
	// b: + ÁSZF, Impresszum, the privacy notice with Arovia as the controller
	// c: her 20:17 notes – „Olívia” with a long í, the prenatal modules and why each one matters, the yogic-life sentence
	// d: her 21:35 notes – her photos on the class rows, the one-to-one prices (90 minutes for individual yoga, 6 weeks
	//    for every 5-session pass, one set of lines), only the Wednesdays on the timetables
	$rev = '2026-11-13d';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_content_rev' ) === $rev ) {
		return array();
	}
	kses_remove_filters();
	$log = oy_import_all( true );
	kses_init_filters();
	$log = array_merge( $log, oy_apply_online_prices() );
	// her name with a long í in the site's name and the sender's name – only where it is still the old spelling
	$old = 'Olivia Jóga – Online';
	$new = 'Olívia Jóga – Online';
	if ( get_option( 'blogname' ) === $old ) {
		update_option( 'blogname', $new );
		$log[] = 'site name: ' . $new;
	}
	if ( class_exists( 'OYS_Settings' ) && OYS_Settings::get( 'email_from_name' ) === $old ) {
		OYS_Settings::update( array( 'email_from_name' => $new ) );
		$log[] = 'sender name: ' . $new;
	}
	update_option( 'oy_content_rev', $rev );
	return array_merge( array( 'content: Olivia 13 November' ), $log );
}

/**
 * The legal pages of the online edition once more from its content file (only the pages the theme made: ÁSZF,
 * Impresszum), nothing else – Olivia edits her pages herself now. 2026-11-14a: no VAT on top of the prices, the
 * Arovia's EIN (Áron: „Nincs áfa. Mehet minden bele.” – „Igen. Megerősítem”).
 */
function oy_migrate_legal() {
	// b: „Olívia” with a long í in the booking plugin's texts too (the participation agreement: „szólok Oliviának”)
	$rev = '2026-11-14b';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_legal_rev' ) === $rev ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	kses_remove_filters();
	$log = oy_import_legal( (array) $data, true );
	kses_init_filters();
	if ( class_exists( 'OYS_Settings' ) ) {
		$fix = array();
		foreach ( array( 'waiver_text', 'cancel_policy', 'private_note', 'pay_later_note' ) as $k ) {
			$v = (string) OYS_Settings::get( $k );
			$w = preg_replace( '/Olivi(?=[aá])/u', 'Olívi', $v ); // her name only: addresses are lower case (oliviajoga, olivia.kovacs6)
			if ( $w !== $v ) {
				$fix[ $k ] = $w;
			}
		}
		if ( $fix ) {
			OYS_Settings::update( $fix );
			$log[] = 'Olívia in: ' . implode( ', ', array_keys( $fix ) );
		}
	}
	update_option( 'oy_legal_rev', $rev );
	return $log;
}

/**
 * Olivia's prices of 14 November on a live site: the passes and sessions (60 / 90 minutes, „Párban olcsóbb” for every
 * one-to-one service, no expiry on the prenatal pass), and on each class page only its „Árak” list – the rest of the
 * page stays as it is (Olivia edits her pages herself now).
 */
function oy_migrate_prices() {
	// b: Olívia's chat of 14 November: the relaxation pass 20 000 Ft, the pair's 32 000 Ft
	$rev = '2026-11-14b';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_prices_rev' ) === $rev ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$log  = oy_apply_online_prices();
	$pick = fn( $html ) => preg_match( '~<h2>Árak</h2>\s*<ul>.*?</ul>~su', (string) $html, $m ) ? $m[0] : '';
	foreach ( (array) ( $data['classes'] ?? array() ) as $slug => $c ) {
		$post = get_page_by_path( $slug, OBJECT, 'oy_class' );
		$new  = $pick( $c['content'] ?? '' );
		if ( ! $post || '' === $new || '' === $pick( $post->post_content ) ) {
			continue;
		}
		$content = preg_replace( '~<h2>Árak</h2>\s*<ul>.*?</ul>~su', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $new ), $post->post_content, 1 );
		if ( $content !== $post->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ) );
			kses_init_filters();
			$log[] = 'prices on /' . $slug . '/';
		}
	}
	$sp = get_page_by_path( oy_page_slug( 'schedule-pricing' ) );
	if ( $sp && ! empty( $data['pages']['schedule-pricing']['seo_desc'] ) ) {
		update_post_meta( $sp->ID, 'oy_seo_desc', $data['pages']['schedule-pricing']['seo_desc'] );
	}
	update_option( 'oy_prices_rev', $rev );
	return $log;
}

/**
 * Olívia, 14 November: „az alt textek és kép elnevezések angolul vannak. legyen mindegyik magyar és az összes releváns
 * kulcsszó legyen benne. a kép elnevezések ékezet nélkül, kötőjellel legyenek” – „amiket én cserélek képek, abból
 * csinálj egy másolatot megfelelő képmérettel … és fájlelnevezéssel”. On a live online site, once: the theme's photos in
 * the media library get their Hungarian name and text (images.json „hu”; a text someone wrote is kept), and the photos
 * she put on the pages herself get a web copy (the page editor's oye_web_copies()).
 */
function oy_migrate_images() {
	$rev = '2026-11-14a';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_images_rev' ) === $rev ) {
		return array();
	}
	global $wpdb;
	$imgs  = oy_images();
	$urls  = array();
	$named = 0;
	$texts = 0;
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => 'oy_key', 'numberposts' => -1, 'fields' => 'ids' ) ) as $att ) {
		$key = (string) get_post_meta( $att, 'oy_key', true );
		$hu  = $imgs[ $key ]['hu'] ?? null;
		if ( ! $hu ) {
			continue;
		}
		$own = (string) get_post_meta( $att, '_wp_attachment_image_alt', true );
		if ( '' === $own || $own === (string) $imgs[ $key ]['alt'] ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $hu['alt'] );
			$title = get_post_field( 'post_title', $att );
			if ( '' === $title || $title === $imgs[ $key ]['alt'] || $title === $key ) {
				wp_update_post( array( 'ID' => $att, 'post_title' => $hu['alt'] ) );
			}
			$texts++;
		}
		$moved = oy_rename_attachment( $att, $hu['file'] );
		if ( $moved ) {
			$urls += $moved;
			$named++;
		}
	}
	// the old addresses in the posts (a photo placed in a text)
	foreach ( $urls as $old => $new ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $old, $new, '%' . $wpdb->esc_like( $old ) . '%' ) );
	}
	if ( $urls ) {
		clean_post_cache( 0 );
		wp_cache_flush();
	}
	// the page editor's list shows the original photo of each place: the theme's photos have their new names
	$meta = get_option( 'oye_edits_meta', array() );
	if ( is_array( $meta ) && $meta ) {
		foreach ( $meta as $k => $m ) {
			$def = (string) ( $m['def'] ?? '' );
			if ( preg_match( '~/assets/img/([a-z0-9-]+)-(1200|600)\.jpg$~', $def, $x ) && isset( $imgs[ $x[1] ] ) && oy_image_file( $x[1] ) !== $x[1] ) {
				$meta[ $k ]['def'] = str_replace( '/' . $x[1] . '-', '/' . oy_image_file( $x[1] ) . '-', $def );
			}
		}
		update_option( 'oye_edits_meta', $meta, false );
	}
	$log = array( sprintf( 'photos: %d Hungarian names, %d Hungarian texts', $named, $texts ) );
	if ( function_exists( 'oye_web_copies' ) ) {
		$log = array_merge( $log, oye_web_copies() );
	}
	update_option( 'oy_images_rev', $rev );
	return $log;
}

/**
 * A media library photo under a new name, in place: the file, its sizes and the original of a scaled one move, the
 * attachment keeps its id (so every page that shows it keeps showing it). Returns old address => new address.
 */
function oy_rename_attachment( $id, $slug ) {
	$file = (string) get_attached_file( $id );
	$meta = wp_get_attachment_metadata( $id );
	if ( '' === $file || ! file_exists( $file ) || ! is_array( $meta ) || '' === (string) $slug ) {
		return array();
	}
	$dir    = dirname( $file );
	$ext    = (string) pathinfo( $file, PATHINFO_EXTENSION );
	$scaled = ! empty( $meta['original_image'] );
	$old    = (string) preg_replace( '/-scaled$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
	if ( $old === $slug || preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d+$/', $old ) ) {
		return array();
	}
	$base = $slug;
	for ( $i = 2; file_exists( "$dir/$base.$ext" ) || file_exists( "$dir/$base-scaled.$ext" ); $i++ ) {
		$base = $slug . '-' . $i;
	}
	$moves = array( basename( $file ) => $base . ( $scaled ? '-scaled' : '' ) . '.' . $ext );
	if ( $scaled ) {
		$moves[ (string) $meta['original_image'] ] = $base . '.' . $ext;
	}
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $s ) {
		$f = (string) ( $s['file'] ?? '' );
		if ( 0 === strpos( $f, $old . '-' ) ) {
			$moves[ $f ] = $base . substr( $f, strlen( $old ) );
		}
	}
	$done = array();
	foreach ( $moves as $from => $to ) {
		if ( ! file_exists( "$dir/$from" ) ) {
			continue;
		}
		if ( ! @rename( "$dir/$from", "$dir/$to" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( $done as $a => $b ) {
				@rename( "$dir/$b", "$dir/$a" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			return array();
		}
		$done[ $from ] = $to;
	}
	$url = trailingslashit( dirname( (string) wp_get_attachment_url( $id ) ) );
	update_attached_file( $id, "$dir/" . $moves[ basename( $file ) ] );
	$meta['file'] = ltrim( trailingslashit( dirname( (string) $meta['file'] ) ), './' ) . $moves[ basename( $file ) ];
	if ( $scaled ) {
		$meta['original_image'] = $moves[ (string) $meta['original_image'] ];
	}
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $s ) {
		if ( isset( $moves[ (string) ( $s['file'] ?? '' ) ] ) ) {
			$meta['sizes'][ $size ]['file'] = $moves[ $s['file'] ];
		}
	}
	wp_update_attachment_metadata( $id, $meta );
	wp_update_post( array( 'ID' => $id, 'post_name' => $base ) );
	$out = array();
	foreach ( $done as $from => $to ) {
		$out[ $url . $from ] = $url . $to;
	}
	return $out;
}

/**
 * Olívia, 14 November: „a Rólam rész nagyon jó … tetszik a design is, de még egy kicsit törjük meg a folyószöveget kreatív
 * megjelenítésekkel”. On a live site the About page gets its new layout once – only while its text is still the one the
 * theme put there (the content file's „_content_prev_md5”). Her changes made in the page editor stay: they are stored
 * apart, by the sentence they replace, and every one of her sentences is still on the page, word for word.
 */
function oy_migrate_about() {
	// b: „Amiben ma segítek” – her other areas, the lead and the search description too (Olívia: „Igen, a Rólamot kicsit
	//    fogalmazzuk át más területekre is”)
	$rev = '2026-11-14b';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_about_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_about_rev', $rev );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$page = $data['pages']['about'] ?? null;
	$post = get_page_by_path( oy_page_slug( 'about' ) );
	if ( ! $page || ! $post ) {
		return array();
	}
	$log = array();
	// the lead under the title and the search description: her other areas too („fogalmazzuk át más területekre is”)
	if ( ! empty( $page['_excerpt_prev'] ) && trim( $post->post_excerpt ) === $page['_excerpt_prev'] ) {
		wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_excerpt' => $page['excerpt'] ) ) );
		$log[] = 'about: lead';
	}
	if ( ! empty( $page['_seo_desc_prev'] ) && (string) get_post_meta( $post->ID, 'oy_seo_desc', true ) === $page['_seo_desc_prev'] ) {
		update_post_meta( $post->ID, 'oy_seo_desc', $page['seo_desc'] );
	}
	$norm = fn( $h ) => md5( (string) preg_replace( '/\s+/', ' ', trim( (string) $h ) ) );
	$new  = oy_resolve_tokens( $page['content'] );
	$now  = $norm( $post->post_content );
	if ( $now === $norm( $new ) ) {
		return $log;
	}
	if ( ! in_array( $now, (array) ( $page['_content_prev_md5'] ?? array() ), true ) ) {
		return array_merge( $log, array( 'about: kept as it is (changed on the site)' ) );
	}
	kses_remove_filters();
	wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ) );
	kses_init_filters();
	return array_merge( $log, array( 'about: the new layout' ) );
}

/**
 * Olívia's doc of 14 November, the later points, on a live online site (once):
 * - „Minden cikk képet cserélj ki az eredeti képre … Legyenek beigazítva, hogy kitöltse a teljes képet” – the journal
 *   photos in the media library get her raw photo in place of the graphic (same attachment, same name, new sizes), the
 *   posts lose the „art” flag (the card and the post's head fill the frame), the five photos that show something else now
 *   get their own text;
 * - „A kismamatornánál csak kellhetnek ezek az eszközök, nem feltétlen kellenek” – on the prenatal page only those two
 *   sentences change, and the question in the FAQ – where they are still the theme's.
 */
function oy_migrate_doc_1114() {
	$rev = '2026-11-14a';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_doc1114_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_doc1114_rev', $rev );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$imgs   = oy_images();
	$photos = 0;
	$journal = array();
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => 'oy_key', 'numberposts' => -1, 'fields' => 'ids' ) ) as $att ) {
		$key = (string) get_post_meta( $att, 'oy_key', true );
		if ( ! preg_match( '/^journal-\d+$/', $key ) || empty( $imgs[ $key ] ) ) {
			continue;
		}
		$journal[] = $att;
		$src  = get_template_directory() . '/assets/img/' . oy_image_file( $key ) . '-1200.jpg';
		$file = (string) get_attached_file( $att );
		if ( ! file_exists( $src ) || '' === $file ) {
			continue;
		}
		$dir  = dirname( $file );
		$base = (string) preg_replace( '/-scaled$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
		$meta = wp_get_attachment_metadata( $att );
		wp_delete_attachment_files( $att, is_array( $meta ) ? $meta : array(), array(), $file );
		$to = $dir . '/' . $base . '.jpg';
		if ( ! @copy( $src, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			continue;
		}
		update_attached_file( $att, $to );
		wp_update_post( array( 'ID' => $att, 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $to ) );
		update_post_meta( $att, 'oy_pos', $imgs[ $key ]['pos'] );
		$hu  = $imgs[ $key ]['hu'] ?? array();
		$own = (string) get_post_meta( $att, '_wp_attachment_image_alt', true );
		if ( ! empty( $hu['alt'] ) && ( '' === $own || $own === (string) $imgs[ $key ]['alt'] || $own === (string) ( $hu['alt_prev'] ?? '' ) ) ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $hu['alt'] );
			wp_update_post( array( 'ID' => $att, 'post_title' => $hu['alt'] ) );
		}
		$photos++;
	}
	$art = 0;
	if ( $journal ) {
		foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'oy_art' ) ) as $post ) {
			if ( in_array( (int) get_post_thumbnail_id( $post ), $journal, true ) ) {
				delete_post_meta( $post, 'oy_art' );
				$art++;
			}
		}
	}
	$log  = array( sprintf( 'journal: %d raw photos, %d posts fill the frame', $photos, $art ) );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$kit  = $data['classes']['kismamatorna'] ?? array();
	$post = get_page_by_path( 'kismamatorna', OBJECT, 'oy_class' );
	if ( $post && ! empty( $kit['_replace'] ) ) {
		$content = $post->post_content;
		foreach ( (array) $kit['_replace'] as $pair ) {
			$content = str_replace( (string) $pair[0], (string) $pair[1], $content );
		}
		if ( $content !== $post->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ) );
			kses_init_filters();
			$log[] = 'prenatal: what you may need';
		}
	}
	foreach ( (array) ( $data['faqs'] ?? array() ) as $f ) {
		if ( empty( $f['_prev']['q'] ) || ! isset( $f['_prev']['a'] ) ) {
			continue;
		}
		foreach ( get_posts( array( 'post_type' => 'oy_faq', 'post_status' => 'any', 'numberposts' => -1, 'title' => $f['_prev']['q'] ) ) as $faq ) {
			if ( trim( wp_strip_all_tags( $faq->post_content ) ) === trim( $f['_prev']['a'] ) ) {
				wp_update_post( wp_slash( array( 'ID' => $faq->ID, 'post_title' => $f['q'], 'post_content' => wpautop( esc_html( $f['a'] ) ) ) ) );
				$log[] = 'FAQ: ' . $f['q'];
			}
		}
	}
	return $log;
}

/**
 * Olívia, 14 November, later on: „Rólamnál az idővonalba mindenképp írd bele, hogy 2015-től főállású edző voltam …” –
 * the new line goes into „Az utam” on the live About page (after the „19 évesen” one, only if it is not there yet); and the
 * web copies of her own photos that were named before we had looked at them (the content file's „uploads”) get the name
 * and the text we gave them.
 */
function oy_migrate_1114c() {
	$rev = '2026-11-14c';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_1114c_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_1114c_rev', $rev );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$log  = array();
	$post = get_page_by_path( oy_page_slug( 'about' ) );
	if ( $post ) {
		$content = $post->post_content;
		foreach ( (array) ( $data['pages']['about']['_insert'] ?? array() ) as $pair ) {
			if ( str_contains( $content, (string) $pair[0] ) && ! str_contains( $content, (string) $pair[1] ) ) {
				$content = str_replace( (string) $pair[0], $pair[0] . "\n" . $pair[1], $content );
			}
		}
		if ( $content !== $post->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ) );
			kses_init_filters();
			$log[] = 'about: 2015 on the timeline';
		}
	}
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'meta_key' => 'oye_web', 'numberposts' => -1, 'fields' => 'ids' ) ) as $copy ) {
		$entry = oy_upload_entry( (int) get_post_meta( $copy, 'oye_web', true ) );
		if ( ! $entry ) {
			continue;
		}
		if ( oy_rename_attachment( $copy, (string) $entry['file'] ) ) {
			$log[] = 'renamed: ' . $entry['file'];
		}
		if ( get_post_meta( $copy, '_wp_attachment_image_alt', true ) !== $entry['alt'] ) {
			update_post_meta( $copy, '_wp_attachment_image_alt', $entry['alt'] );
			wp_update_post( array( 'ID' => $copy, 'post_title' => $entry['alt'] ) );
		}
	}
	return $log;
}

/**
 * Olívia, 15 November: „az emojik helyett legyen az oldal stílusához illeszkedő design” – the arrow emoji of her post in the
 * site's own forms: a list whose items read „<b>illness</b> ➡️ what is behind it” becomes cards (oj-causes: the illness,
 * under it the reason after the site's arrow), a line of „a ➡️ b ➡️ c” a chain of pills (oj-chain), the folded hands go.
 */
function oy_arrow_marks( $html ) {
	$arrow = '\x{27A1}\x{FE0F}?';
	$html  = (string) preg_replace_callback(
		'#<ul>((?:(?!</ul>).)*' . $arrow . '(?:(?!</ul>).)*)</ul>#su',
		function ( $m ) use ( $arrow ) {
			$items = preg_replace_callback(
				'#<li>((?:(?!</li>).)*?)\s*' . $arrow . '\s*((?:(?!</li>).)*)</li>#su',
				fn( $l ) => '<li><span class="oj-causes__name">' . trim( $l[1] ) . '</span><span class="oj-causes__why">' . trim( $l[2] ) . '</span></li>',
				$m[1]
			);
			return '<ul class="oj-causes">' . $items . '</ul>';
		},
		$html
	);
	$html = (string) preg_replace_callback(
		'#(?:<blockquote>\s*)?<p>([^<]*?' . $arrow . '[^<]*?' . $arrow . '[^<]*)</p>(?:\s*</blockquote>)?#su',
		function ( $m ) use ( $arrow ) {
			$li = array();
			foreach ( preg_split( '#\s*' . $arrow . '\s*#u', trim( $m[1] ) ) as $part ) {
				$li[] = '<li><span>' . mb_strtoupper( mb_substr( $part, 0, 1 ) ) . mb_substr( $part, 1 ) . '</span></li>';
			}
			return '<ol class="oj-chain">' . implode( '', $li ) . '</ol>';
		},
		$html
	);
	return (string) preg_replace( '#\s*\x{1F64F}(?:\x{1F3FB}|\x{1F3FC}|\x{1F3FD}|\x{1F3FE}|\x{1F3FF})?\s*\)#u', '.)', $html );
}

/**
 * Olívia, 14 November, the last points of her doc, on a live site: 2018 (massage) on the About timeline, the general kit
 * list and the „charge your device” note on the prices page, the same in the FAQ answer about what to prepare; „I will
 * get back to you soon” instead of „within a day” (pages and classes); the photo whose focus moved („ennek a képnek nagyon
 * a teteje látszik”). Each text changes only where the old one is still there word for word, a photo's focus only where it
 * is still the old one, so whatever she has changed herself stays. Rev e: runs again where rev d already did (idempotent).
 */
function oy_migrate_1114d() {
	$rev = '2026-11-15s';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_1114d_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_1114d_rev', $rev );
	// Olívia, 15 November: „maradt az aloldal neve, de már bejelentkezés és árak” / „… de már online órák” – the two pages
	// get the addresses of their names, and every link to them (and to the class pages under the classes page) on the site
	// follows; the old addresses redirect (inc/edition.php). The page editor keys her edits by their text without the links,
	// so they stay.
	$log   = array();
	$moved = array( 'orarend-es-arak' => 'bejelentkezes-es-arak', 'online-jogaorak' => 'online-orak', 'gyik' => 'gyakori-kerdesek' );
	foreach ( $moved as $from => $to ) {
		$pg = get_page_by_path( $from );
		if ( $pg && ! get_page_by_path( $to ) ) {
			wp_update_post( array( 'ID' => $pg->ID, 'post_name' => $to ) );
			$log[] = 'address: /' . $from . '/ → /' . $to . '/';
		}
	}
	// „itt is javítsd az url slugot” – the article's address follows its title (the old one redirects, inc/edition.php)
	foreach ( OY_ONLINE_POST_MOVED as $from => $to ) {
		$po = get_page_by_path( $from, OBJECT, 'post' );
		if ( $po && ! get_page_by_path( $to, OBJECT, 'post' ) ) {
			wp_update_post( array( 'ID' => $po->ID, 'post_name' => $to ) );
			$log[] = 'address: /' . $from . '/ → /' . $to . '/';
		}
	}
	$moved += OY_ONLINE_POST_MOVED;
	global $wpdb;
	$links = 0;
	foreach ( $moved as $from => $to ) {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ( 'revision', 'nav_menu_item' ) AND post_content LIKE %s", '%/' . $wpdb->esc_like( $from ) . '/%' ) );
		foreach ( $ids as $id ) {
			$c = (string) get_post_field( 'post_content', (int) $id );
			$n = (string) preg_replace( '~(href=["\'](?:' . preg_quote( untrailingslashit( home_url() ), '~' ) . ')?)/' . preg_quote( $from, '~' ) . '/~', '$1/' . $to . '/', $c );
			if ( $n !== $c ) {
				kses_remove_filters();
				wp_update_post( wp_slash( array( 'ID' => (int) $id, 'post_content' => $n ) ) );
				kses_init_filters();
				++$links;
			}
		}
	}
	if ( $links ) {
		$log[] = 'links to the moved pages updated in ' . $links . ' posts';
	}
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$targets = array();
	foreach ( (array) ( $data['pages'] ?? array() ) as $key => $page ) {
		$targets[] = array( $key, $page, fn() => get_page_by_path( oy_page_slug( OY_PAGE_SLUGS[ $key ] ?? $key ) ) );
	}
	foreach ( (array) ( $data['classes'] ?? array() ) as $key => $class ) {
		$targets[] = array( $key, $class, fn() => get_page_by_path( $key, OBJECT, 'oy_class' ) );
	}
	foreach ( $targets as list( $key, $page, $find ) ) {
		if ( empty( $page['_insert'] ) && empty( $page['_replace'] ) && empty( $page['_excerpt_prev'] ) && empty( $page['_before'] ) ) {
			continue;
		}
		$post = $find();
		if ( ! $post ) {
			continue;
		}
		// the short text on the cards („A relaxáció nem hosszú”): only while it is still the old one word for word
		if ( ! empty( $page['_excerpt_prev'] ) && trim( $post->post_excerpt ) === trim( $page['_excerpt_prev'] ) && ! empty( $page['excerpt'] ) ) {
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_excerpt' => $page['excerpt'] ) ) );
			$log[] = 'excerpt: ' . $key;
			$post = get_post( $post->ID );
		}
		$content = $post->post_content;
		foreach ( (array) ( $page['_insert'] ?? array() ) as $pair ) {
			$pair = array_map( fn( $t ) => oy_resolve_tokens( (string) $t ), $pair ); // links as they are on the site
			if ( str_contains( $content, $pair[0] ) && ! str_contains( $content, $pair[1] ) ) {
				$content = str_replace( $pair[0], $pair[0] . "\n" . $pair[1], $content );
			}
		}
		foreach ( (array) ( $page['_replace'] ?? array() ) as $pair ) {
			$pair    = array_map( fn( $t ) => oy_resolve_tokens( (string) $t ), $pair );
			$content = str_replace( $pair[0], $pair[1], $content );
		}
		// a new part in front of a heading or a button („Minden szolgáltatás aloldalon külön bekezdésbe írd, hogy milyen eszköz
		// szükséges”): once, only where its marker is not on the page yet
		foreach ( (array) ( $page['_before'] ?? array() ) as $add ) {
			$add = array_map( fn( $t ) => oy_resolve_tokens( (string) $t ), $add );
			$pos = strpos( $content, $add[0] );
			if ( false !== $pos && ! str_contains( $content, $add[2] ?? $add[1] ) ) {
				$content = substr( $content, 0, $pos ) . $add[1] . "\n" . substr( $content, $pos );
			}
		}
		if ( $content !== $post->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ) );
			kses_init_filters();
			$log[] = ( 'oy_class' === $post->post_type ? 'class: ' : 'page: ' ) . $key;
		}
	}
	foreach ( (array) ( $data['faqs'] ?? array() ) as $f ) {
		if ( empty( $f['_prev']['q'] ) ) {
			continue;
		}
		foreach ( get_posts( array( 'post_type' => 'oy_faq', 'post_status' => 'any', 'numberposts' => -1, 'title' => $f['_prev']['q'] ) ) as $faq ) {
			if ( ! isset( $f['_prev']['a'] ) ) { // only the question changes („Tényleg működik a csoportos jóga online?”); the answer stays as it is
				wp_update_post( wp_slash( array( 'ID' => $faq->ID, 'post_title' => $f['q'] ) ) );
				$log[] = 'FAQ: ' . $f['q'];
				continue;
			}
			// the question as she asked for it; the answer only while it is still ours (an answer she rewrote stays hers)
			$same = trim( wp_strip_all_tags( $faq->post_content ) ) === trim( $f['_prev']['a'] );
			if ( $same || $faq->post_title !== $f['q'] ) {
				wp_update_post( wp_slash( array_merge( array( 'ID' => $faq->ID, 'post_title' => $f['q'] ), $same ? array( 'post_content' => wpautop( esc_html( $f['a'] ) ) ) : array() ) ) );
				$log[] = 'FAQ: ' . $f['q'];
			}
		}
	}
	// „Ez a kérdés pedig törlődhet”: a question she no longer wants goes to the bin (it can be restored from there)
	foreach ( (array) ( $data['faqs_removed'] ?? array() ) as $f ) {
		if ( '' === trim( (string) ( $f['q'] ?? '' ) ) ) {
			continue; // an empty title would match every question
		}
		foreach ( get_posts( array( 'post_type' => 'oy_faq', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => -1, 'title' => $f['q'] ) ) as $faq ) {
			if ( $faq->post_title === $f['q'] && wp_trash_post( $faq->ID ) ) {
				$log[] = 'FAQ removed: ' . $f['q'];
			}
		}
	}
	// „e-mail címnél mindenhol írd át az info@oliviajoga.online-ra”: the old address gives way to the new one in every text of
	// the site – pages, classes, questions, posts, the booking plugin's settings and email templates; the settings named in
	// „keep” (where the booking notices go) stay
	// „szedjük ki mindenhonnan azt, hogy a képét csak én látom”: such sentences change wherever they are written
	foreach ( (array) ( $data['text_swap']['pairs'] ?? array() ) as $pair ) {
		if ( ! empty( $pair[0] ) && isset( $pair[1] ) ) {
			$log = array_merge( $log, oy_swap_text( (string) $pair[0], (string) $pair[1] ) );
		}
	}
	$swap = (array) ( $data['email_swap'] ?? array() );
	if ( ! empty( $swap['from'] ) && ! empty( $swap['to'] ) && is_email( $swap['to'] ) ) {
		$log = array_merge( $log, oy_swap_email( (string) $swap['from'], (string) $swap['to'], (array) ( $swap['keep'] ?? array() ) ) );
	}
	// „a mire lesz szükséged kerüljön minden szolgáltatás aloldalon jóval előrébb! legyen egy kicsit látványosabb”: the kit part
	// moves up, right after the first part of each class page, as small cards; the prenatal page keeps its own list
	foreach ( (array) ( $data['classes'] ?? array() ) as $key => $class ) {
		$post = get_page_by_path( $key, OBJECT, 'oy_class' );
		if ( ! $post || str_contains( $post->post_content, 'class="oj-kit"' ) ) {
			continue;
		}
		$moved = oy_kit_up( $post->post_content, isset( $class['_kit'] ) ? (string) $class['_kit'] : null );
		if ( $moved !== $post->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $moved ) ) );
			kses_init_filters();
			$log[] = 'class: ' . $key . ' – the kit up front, as cards';
		}
	}
	// „főoldalon a gyiknél az utolsó kérdés ez legyen, a miért jó online jógázni kérdést vegyük ki a főoldalról”: which questions
	// are on the home page; one that comes onto it comes after the others there
	foreach ( (array) ( $data['faqs_home'] ?? array() ) as $q => $on ) {
		if ( '_' === substr( (string) $q, 0, 1 ) || '' === trim( (string) $q ) ) {
			continue;
		}
		foreach ( get_posts( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'numberposts' => -1, 'title' => $q ) ) as $faq ) {
			if ( $faq->post_title !== $q ) {
				continue;
			}
			$want = '1' === (string) $on ? '1' : '';
			if ( get_post_meta( $faq->ID, 'oy_home', true ) !== $want ) {
				update_post_meta( $faq->ID, 'oy_home', $want );
				$log[] = 'FAQ ' . ( $want ? 'on' : 'off' ) . ' the home page: ' . $q;
			}
			if ( $want ) {
				$others = get_posts( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'oy_home', 'meta_value' => '1', 'exclude' => array( $faq->ID ) ) );
				$last   = max( array_merge( array( -1 ), array_map( fn( $id ) => (int) get_post_field( 'menu_order', $id ), $others ) ) );
				if ( (int) $faq->menu_order <= $last ) {
					wp_update_post( array( 'ID' => $faq->ID, 'menu_order' => $last + 1 ) );
				}
			}
		}
	}
	// „ez egy picit legyen szebben összeszedve az egyéni online jóga aloldalon”: the „Miben segíthet…?” questions become cards
	// like the prenatal modules – rebuilt from whatever is on the page, so the words she has changed herself stay
	$one = get_page_by_path( 'egyeni-online-joga', OBJECT, 'oy_class' );
	if ( $one && ! str_contains( $one->post_content, 'oj-modules--pairs' ) ) {
		$cards = oy_h3_cards( $one->post_content, 'Miben segíthet az egyéni online jóga?' );
		if ( $cards !== $one->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $one->ID, 'post_content' => $cards ) ) );
			kses_init_filters();
			$log[] = 'class: egyeni-online-joga – the questions as cards';
		}
	}
	// „Az összes szolgáltatásnál legyenek szebben kategorizálva az árak”: each one-to-one class's price lines become the price
	// cards (from the booking products); her notes under them (how long a pass lasts) stay as she wrote them
	foreach ( (array) ( $data['setup']['services'] ?? array() ) as $service => $svc ) {
		$cls = get_page_by_path( (string) ( $svc['class'] ?? '' ), OBJECT, 'oy_class' );
		if ( ! $cls || str_contains( $cls->post_content, '[oy_prices' ) ) {
			continue;
		}
		$priced = oy_price_section( $cls->post_content, $service );
		if ( $priced !== $cls->post_content ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $cls->ID, 'post_content' => $priced ) ) );
			kses_init_filters();
			$log[] = 'class: ' . $cls->post_name . ' – prices as cards';
		}
	}
	// the gift card page („a kép is legyen más”): her photo instead of the singing bowls, only while the bowls are still there
	$gift_photo = (string) ( $data['studio_pages']['gifts']['photo'] ?? '' );
	$gift_page  = get_post( (int) get_option( 'oys_page_gifts' ) );
	if ( '' !== $gift_photo && $gift_page ) {
		$thumb = (int) get_post_thumbnail_id( $gift_page );
		if ( ! $thumb || 'singing-bowls-studio' === get_post_meta( $thumb, 'oy_key', true ) ) {
			$att = oy_import_image( $gift_photo );
			if ( $att && set_post_thumbnail( $gift_page, $att ) ) {
				$log[] = 'gift card page: new photo';
			}
		}
	}
	// „itt mindig a következő 3 óra legyen látható”: the weekly classes are made 10 weeks ahead (not 6), so with a missed
	// week (23 December) the timetable still has its next three
	$weeks = (int) ( $data['setup']['studio']['weeks_ahead'] ?? 0 );
	if ( $weeks && class_exists( 'OYS_Settings' ) && (int) OYS_Settings::get( 'weeks_ahead' ) < $weeks ) {
		OYS_Settings::update( array( 'weeks_ahead' => $weeks ) );
		$log[] = 'weekly classes ' . $weeks . ' weeks ahead: ' . (int) OYS_Schedule::generate( $weeks ) . ' new dates';
	}
	// Olívia, 15 November: „rólamot még kicsit módosítsuk sorrendben és egy-két képet hozzácsatolva” – the timeline first;
	// under „A kezdetek” the two trainer photos straight after the title (the first one her new photo), then the text;
	// „Amikor jött a jóga” opens with two photos; „A matracon túl” opens with the photo of „Te foglalkozol mással is…”.
	// Each step only once and only where the page still has what it moves, so her own words stay as they are.
	$about = get_page_by_path( oy_page_slug( 'about' ) );
	if ( $about ) {
		$c    = $about->post_content;
		$orig = $c;
		if ( preg_match( '~<div class="oj-path">\s*<h3>.*?</ol>\s*</div>~su', $c, $m, PREG_OFFSET_CAPTURE ) && $m[0][1] > 0 && '' !== trim( substr( $c, 0, $m[0][1] ) ) ) {
			$c = $m[0][0] . "\n" . ltrim( substr( $c, 0, $m[0][1] ) ) . substr( $c, $m[0][1] + strlen( $m[0][0] ) );
		}
		$old_duo = '[oy_figures photos="korall-stretching-circuit,outdoor-group-bands"]';
		if ( str_contains( $c, $old_duo ) && preg_match( '~<h3 class="oj-chapter">A kezdetek</h3>\n?~u', $c, $h ) ) {
			$c = str_replace( array( $old_duo . "\n", $old_duo ), '', $c );
			$c = str_replace( $h[0], rtrim( $h[0] ) . "\n" . '[oy_figures photos="about-gym-class,outdoor-group-bands"]' . "\n", $c );
		}
		if ( ! str_contains( $c, 'about-rock-warrior' ) && preg_match( '~<h3 class="oj-chapter">Amikor jött a jóga</h3>\n?~u', $c, $h ) ) {
			$c = str_replace( $h[0], rtrim( $h[0] ) . "\n" . '[oy_figures photos="about-rock-warrior,about-meadow-stretch"]' . "\n", $c );
		}
		if ( ! str_contains( $c, 'photo="journal-15"' ) && preg_match( '~<h2>A matracon túl</h2>\n?~u', $c, $h ) ) {
			$c = str_replace( $h[0], rtrim( $h[0] ) . "\n" . '[oy_figure photo="journal-15" class="oj-figure--wide"]' . "\n", $c );
		}
		// „a szöveg végére meg kisebben ezek a képek jöhetnek”: a row of smaller photos (three of her paintings, one at
		// sunset, her henna) at the end of the chapter's text – after the little secret, before the button
		$row = '[oy_figures photos="about-painting-embrace,about-painting-mask,about-painting-birds,about-sunset-river,about-henna-arm" class="oj-duo--row"]';
		// a fifth one came later (her henna), and her order: the row that went out with four becomes the row of five
		$c = str_replace( '[oy_figures photos="about-painting-embrace,about-painting-birds,about-painting-mask,about-sunset-river" class="oj-duo--row"]', $row, $c );
		$h2  = strpos( $c, '<h2>A matracon túl</h2>' );
		if ( false !== $h2 && ! str_contains( $c, 'about-sunset-river' ) ) {
			$next = strpos( $c, '<h2', $h2 + 5 );
			$end  = false === $next ? strlen( $c ) : $next;
			$sec  = substr( $c, $h2, $end - $h2 );
			$at   = false;
			if ( preg_match( '~<div class="oj-secret">.*?</div>\n?~su', $sec, $m, PREG_OFFSET_CAPTURE ) ) {
				$at = $m[0][1] + strlen( $m[0][0] );
			} elseif ( preg_match( '~<p>\[oy_booking_link~u', $sec, $m, PREG_OFFSET_CAPTURE ) ) {
				$at = $m[0][1];
			}
			$sec = false === $at ? rtrim( $sec ) . "\n" . $row . "\n" : substr( $sec, 0, $at ) . ( "\n" === substr( $sec, $at - 1, 1 ) ? '' : "\n" ) . $row . "\n" . substr( $sec, $at );
			$c   = substr( $c, 0, $h2 ) . $sec . substr( $c, $end );
		}
		// „a 2026-os szöveg legyen a képzéses kép mellett vagy alatt, ne legyenek üres helyek, legyen szépen eloszlatva”: the
		// trainings in two columns – her photo over the 2023 one, the Fitmummy card over the 2026 one (blank lines between,
		// so each shortcode stays on its own)
		if ( ! str_contains( $c, 'oj-trainings' ) && preg_match( '~\[oy_figure photo="olivia-side-plank-festival"[^\]]*\]\s*(<p>(?:(?!</p>).)*?</p>)\s*(<p>2026(?:(?!</p>).)*?</p>)\s*\[oy_fitmummy\]~su', $c, $m ) ) {
			$c = str_replace( $m[0], "<div class=\"oj-trainings\">\n\n[oy_figure photo=\"olivia-side-plank-festival\"]\n\n[oy_fitmummy]\n\n" . $m[1] . "\n\n" . $m[2] . "\n\n</div>", $c );
		}
		// „Ahol eddig tanítottam: jöhet szélesen hozzá ez a kép” – her festival class, wide, under the box's title
		if ( ! str_contains( $c, 'photo="park-class-seated"' ) && preg_match( '~<h3>Ahol eddig tanítottam</h3>\n?~u', $c, $h ) ) {
			$c = str_replace( $h[0], rtrim( $h[0] ) . "\n" . '[oy_figure photo="park-class-seated" class="oj-figure--wide"]' . "\n", $c );
		}
		if ( $c !== $orig ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $about->ID, 'post_content' => $c ) ) );
			kses_init_filters();
			$log[] = 'about: the timeline first, photos under „A kezdetek”, „Amikor jött a jóga” and „A matracon túl”, a row of photos at its end, the trainings in two columns, the festival photo in „Ahol eddig tanítottam”';
		}
	}
	// Olívia, 15 November, the prices page: the note under the timetable went into the chips at the top („ezek az infók is
	// jöhetnek ide”); „a bérletes rész nem kell”; „az mire szükséges lesz résznél pedig tegyük be pontosan ugyanazt, amit a
	// gyiknél, külön szedve” – the FAQ's answer itself, by class. Only what is still there, once.
	$pr = get_page_by_path( 'bejelentkezes-es-arak' ) ?: get_page_by_path( 'orarend-es-arak' );
	if ( $pr ) {
		$c    = $pr->post_content;
		$orig = $c;
		$c    = (string) preg_replace( '~\s*<p>Kérlek, foglalj előre, hogy időben megkapd a linket\..*?</p>~su', '', $c, 1 );
		$c    = (string) preg_replace( '~\s*<p><strong>Bérlet:</strong>.*?</p>~su', '', $c, 1 );
		if ( ! str_contains( $c, '[oy_faq_answer' ) ) {
			$c = (string) preg_replace( '~(<h3>Amire szükséged lesz</h3>)\s*<ul>.*?</ul>~su', "$1\n[oy_faq_answer q=\"Mit kell előkészítenem\"]", $c, 1 );
		}
		if ( $c !== $orig ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $pr->ID, 'post_content' => $c ) ) );
			kses_init_filters();
			$log[] = 'prices page: the timetable note as chips, no pass paragraph, the kit as in the FAQ';
		}
	}
	// „Itt az legyen: … Egy hosszú nap végén vagy a terápiádhoz kiegészítésként.” – the relaxation box of the classes guide
	$cl = get_page_by_path( 'online-orak' ) ?: get_page_by_path( 'online-jogaorak' );
	if ( $cl && str_contains( $cl->post_content, 'Egy hosszú nap végén is belefér.' ) ) {
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $cl->ID, 'post_content' => str_replace( 'Egy hosszú nap végén is belefér.', 'Egy hosszú nap végén vagy a terápiádhoz kiegészítésként.', $cl->post_content ) ) ) );
		kses_init_filters();
		$log[] = 'classes guide: relaxation „vagy a terápiádhoz kiegészítésként”';
	}
	// Olívia, 15 November: „Ehelyett a cikk helyett: …/foglalkozol-massal-is/ Legyen ez a szöveg: [her Facebook post] … a végén
	// csak: Legyen több olyan gondolat, amiben jó lakni! … Ez a kép legyen hozzá” – the article gets her post (sent in the
	// chat), a title from her own sentence and the fish pose photo; the address stays. Once, only while the old text is there.
	// „csoportos flow jóga szolgáltatáshoz mindenhova ezt a képet tedd be” – the group class's photo (the cards, its head, the
	// home page); once, so a photo she sets later in the admin stays
	$flow = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
	if ( $flow && ! get_option( 'oy_flow_photo_set' ) ) {
		$att = oy_import_image( 'flow-group-lakeside-deck' );
		if ( $att ) {
			set_post_thumbnail( $flow->ID, $att );
			update_option( 'oy_flow_photo_set', 1 );
			$log[] = 'group flow class: her lakeside deck photo';
		}
	}
	$fm = get_page_by_path( 'torodni-magaddal-nem-luxus', OBJECT, 'post' ) ?: get_page_by_path( 'foglalkozol-massal-is', OBJECT, 'post' );
	$fd = $data['posts']['torodni-magaddal-nem-luxus'] ?? null;
	if ( $fm && $fd && ! str_contains( $fm->post_content, 'népbetegség' ) ) {
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $fm->ID, 'post_title' => $fd['title'], 'post_excerpt' => $fd['excerpt'], 'post_content' => $fd['content'] ) ) );
		kses_init_filters();
		$att = oy_import_image( (string) $fd['photo'] );
		if ( $att ) {
			set_post_thumbnail( $fm->ID, $att );
		}
		$log[] = 'article: „Foglalkozol mással is?” → her post, „' . $fd['title'] . '”, the fish pose photo';
		$fm = get_post( $fm->ID );
	}
	// „az emojik helyett legyen az oldal stílusához illeszkedő design” – the arrows of her post become the site's cards and chain
	if ( $fm && preg_match( '#\x{27A1}|\x{1F64F}#u', $fm->post_content ) ) {
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $fm->ID, 'post_content' => oy_arrow_marks( $fm->post_content ) ) ) );
		kses_init_filters();
		$log[] = 'article: the arrow emoji → cards and a chain in the site\'s style';
	}
	// Olívia, 15 November, on „Pajzsmirigy harmonizálás jógával”: „Ebből a cikkből hiányzik a lényeg, a kép” – the poses for
	// an over- and an underactive thyroid, as cards with her photos, after the paragraph that says which poses help; added
	// once, her own words around them stay as they are
	$thy = get_page_by_path( 'pajzsmirigy-harmonizalas-jogaval', OBJECT, 'post' );
	if ( $thy && ! str_contains( $thy->post_content, '[oy_poses' ) ) {
		$c      = $thy->post_content;
		$blocks = str_contains( $c, '<!-- wp:' );
		$sc     = fn( $set ) => $blocks ? "<!-- wp:shortcode -->\n[oy_poses set=\"" . $set . "\"]\n<!-- /wp:shortcode -->" : '[oy_poses set="' . $set . '"]';
		$ins    = "\n\n" . $sc( 'tul' ) . "\n\n" . $sc( 'alul' ) . "\n\n";
		$at     = false;
		foreach ( array( 'Ezek az ászanák akkor is', 'Minden esetben végezz bemelegítést' ) as $needle ) {
			$pos = mb_strpos( $c, $needle );
			if ( false !== $pos ) {
				$pos = strpos( $c, $needle );
				// the start of the paragraph (or block) the sentence is in
				$p1  = strrpos( substr( $c, 0, $pos ), $blocks ? '<!-- wp:' : '<p' );
				$p2  = strrpos( substr( $c, 0, $pos ), "\n\n" );
				$at  = false !== $p1 ? $p1 : ( false !== $p2 ? $p2 : $pos );
				break;
			}
		}
		$c = false === $at ? rtrim( $c ) . $ins : substr( $c, 0, $at ) . ltrim( $ins, "\n" ) . substr( $c, $at );
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $thy->ID, 'post_content' => $c ) ) );
		kses_init_filters();
		$log[] = 'thyroid article: the poses for an over- and an underactive thyroid';
	}
	foreach ( oy_images() as $key => $img ) {
		if ( empty( $img['pos_prev'] ) ) {
			continue;
		}
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'oy_key', 'meta_value' => $key ) ) as $att ) {
			if ( get_post_meta( $att, 'oy_pos', true ) === $img['pos_prev'] ) {
				update_post_meta( $att, 'oy_pos', $img['pos'] );
				$log[] = 'photo focus: ' . $key;
			}
		}
	}
	return $log;
}

/**
 * The questions under a heading (each an h3 with its paragraph) as module cards, two by two; the texts stay as they are.
 * Anything that does not look like that is left alone.
 */
function oy_h3_cards( $content, $heading ) {
	if ( ! preg_match( '~<h2[^>]*>\s*' . preg_quote( $heading, '~' ) . '\s*</h2>\s*~u', $content, $h, PREG_OFFSET_CAPTURE ) ) {
		return $content;
	}
	$start = $h[0][1] + strlen( $h[0][0] );
	$end   = preg_match( '~<h2[\s>]~', $content, $n, PREG_OFFSET_CAPTURE, $start ) ? $n[0][1] : strlen( $content );
	$part  = substr( $content, $start, $end - $start );
	if ( ! preg_match_all( '~<h3[^>]*>(.*?)</h3>\s*(.*?)\s*(?=<h3[\s>]|$)~su', $part, $m, PREG_SET_ORDER ) || count( $m ) < 2 || '' !== trim( (string) preg_replace( '~<h3[^>]*>.*?</h3>\s*.*?(?=<h3[\s>]|$)~su', '', $part ) ) ) {
		return $content;
	}
	$out = '<ul class="oj-modules oj-modules--why oj-modules--pairs">' . "\n";
	foreach ( $m as $q ) {
		$text = trim( (string) preg_replace( '~^<p[^>]*>(.*)</p>$~su', '$1', trim( $q[2] ) ) );
		if ( str_contains( $text, '<p' ) || str_contains( $text, '<h' ) || str_contains( $text, '<ul' ) ) {
			return $content; // more than one paragraph: better left as it is
		}
		$out .= '<li><strong>' . trim( $q[1] ) . '</strong><span>' . $text . '</span></li>' . "\n";
	}
	return substr( $content, 0, $start ) . $out . "</ul>\n" . substr( $content, $end );
}

/**
 * A sentence for another in the content of every post (pages, classes, questions, posts), as it is written there.
 */
function oy_swap_text( $from, $to ) {
	global $wpdb;
	$log = array();
	$like = '%' . $wpdb->esc_like( $from ) . '%';
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future','pending') AND post_type NOT IN ('revision','nav_menu_item') AND post_content LIKE %s", $like ) ) as $id ) {
		$p = get_post( $id );
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => str_replace( $from, $to, $p->post_content ) ) ) );
		kses_init_filters();
		$log[] = 'text: ' . $p->post_type . ' ' . $p->post_name;
	}
	return $log;
}

/**
 * One email address for another everywhere it is written: the content of every post type (and its excerpt), the booking
 * plugin's settings (except the keys in $keep) and its email templates. Returns what changed, for the setup log.
 */
function oy_swap_email( $from, $to, array $keep = array() ) {
	global $wpdb;
	$log  = array();
	$like = '%' . $wpdb->esc_like( $from ) . '%';
	$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future','pending') AND post_type NOT IN ('revision','nav_menu_item') AND ( post_content LIKE %s OR post_excerpt LIKE %s )", $like, $like ) );
	foreach ( $ids as $id ) {
		$p = get_post( $id );
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => str_ireplace( $from, $to, $p->post_content ), 'post_excerpt' => str_ireplace( $from, $to, $p->post_excerpt ) ) ) );
		kses_init_filters();
		$log[] = 'email address: ' . $p->post_type . ' ' . $p->post_name;
	}
	foreach ( array( 'oys_settings', 'oys_email_templates' ) as $opt ) {
		$val = get_option( $opt );
		if ( ! is_array( $val ) ) {
			continue;
		}
		$new = $val;
		array_walk_recursive( $new, function ( &$v, $k ) use ( $from, $to, $keep ) {
			if ( is_string( $v ) && ! in_array( $k, $keep, true ) ) {
				$v = str_ireplace( $from, $to, $v );
			}
		} );
		if ( $new !== $val ) {
			update_option( $opt, $new );
			$log[] = 'email address: ' . $opt;
		}
	}
	return $log;
}

/**
 * The „Mire lesz/lehet szükséged?” part moved to after the first part of a class page, as cards: with $kit the new cards
 * replace it, without (the prenatal page, her own list) its list only gets the card style. A page without that part gets
 * $kit only when one is given.
 */
function oy_kit_up( $content, $kit = null ) {
	$found = preg_match( '~<h2[^>]*>\s*Mire (?:lesz|lehet) szükséged\?\s*</h2>.*?(?=<h2[\s>]|\[oy_booking_link\]|$)~su', $content, $m, PREG_OFFSET_CAPTURE );
	if ( ! $found && null === $kit ) {
		return $content;
	}
	if ( $found ) {
		$new     = null !== $kit ? $kit : preg_replace( '~<ul>~', '<ul class="oj-kit">', rtrim( $m[0][0] ), 1 );
		$content = substr( $content, 0, $m[0][1] ) . substr( $content, $m[0][1] + strlen( $m[0][0] ) );
	} else {
		$new = $kit;
	}
	preg_match_all( '~<h2[\s>]~', $content, $h, PREG_OFFSET_CAPTURE );
	$at = isset( $h[0][1] ) ? $h[0][1][1] : ( false !== strpos( $content, '[oy_booking_link]' ) ? strpos( $content, '[oy_booking_link]' ) : strlen( $content ) );
	return substr( $content, 0, $at ) . rtrim( (string) $new ) . "\n" . substr( $content, $at );
}

/**
 * The list under „Árak”: its price lines (the ones with „Ft”) give way to the price cards, the other lines stay as a note.
 * Left alone when the service has no products to show or the part looks different.
 */
function oy_price_section( $content, $service ) {
	if ( ! preg_match( '~(<h2[^>]*>\s*Árak\s*</h2>\s*)<ul[^>]*>(.*?)</ul>~su', $content, $m, PREG_OFFSET_CAPTURE ) || '' === oy_price_cards( $service ) ) {
		return $content;
	}
	preg_match_all( '~<li[^>]*>(.*?)</li>~su', $m[2][0], $li );
	$prices = array_filter( $li[1], fn( $t ) => str_contains( $t, 'Ft' ) );
	if ( ! $prices ) {
		return $content;
	}
	$notes = array_values( array_filter( array_map( 'trim', array_diff_key( $li[1], $prices ) ) ) );
	$new   = $m[1][0] . '[oy_prices service="' . esc_attr( $service ) . '"]' . ( $notes ? "\n" . '<p class="oj-price-note">' . implode( ' ', $notes ) . '</p>' : '' );
	return substr( $content, 0, $m[0][1] ) . $new . substr( $content, $m[0][1] + strlen( $m[0][0] ) );
}

/**
 * The online edition's passes, one-to-one services and timetable setting from its content file, on a live site. Passes
 * someone has bought keep their own expiry; products the edition no longer has go off sale.
 */
function oy_apply_online_prices() {
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$log  = array();
	if ( class_exists( 'OYS_Products' ) && ! empty( $data['setup']['products'] ) ) {
		$all = get_option( OYS_Products::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( $all as $id => $p ) {
			if ( ! isset( $data['setup']['products'][ $id ] ) ) {
				$all[ $id ]['active'] = 0;
			}
		}
		foreach ( $data['setup']['products'] as $id => $p ) {
			$all[ $id ] = $p;
		}
		update_option( OYS_Products::OPTION, $all );
		$log[] = sprintf( 'prices: %d passes and sessions', count( $data['setup']['products'] ) );
	}
	if ( class_exists( 'OYS_Services' ) && ! empty( $data['setup']['services'] ) ) {
		update_option( OYS_Services::OPTION, (array) $data['setup']['services'] );
	}
	if ( class_exists( 'OYS_Settings' ) ) {
		OYS_Settings::update( array_intersect_key( (array) $data['setup']['studio'], array_flip( array( 'cancel_extend_days', 'schedule_empty_days' ) ) ) );
	}
	return $log;
}

function oy_migrate_online() {
	$rev = '2026-11-12b';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_online_rev' ) === $rev ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$log  = array();
	if ( class_exists( 'OYS_Products' ) ) {
		$all = get_option( OYS_Products::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		// the edition's passes; anything else (the old private sessions, the plugin's dollar-priced defaults) goes off sale
		foreach ( $all as $id => $p ) {
			if ( ! isset( $data['setup']['products'][ $id ] ) ) {
				$all[ $id ]['active'] = 0;
			}
		}
		foreach ( (array) ( $data['setup']['products'] ?? array() ) as $id => $p ) {
			$all[ $id ] = $p;
		}
		update_option( OYS_Products::OPTION, $all );
		$log[] = 'prices and passes: 12 November';
	}
	if ( class_exists( 'OYS_Services' ) ) {
		update_option( OYS_Services::OPTION, (array) ( $data['setup']['services'] ?? array() ) );
		$log[] = sprintf( '%d one-to-one services', count( (array) ( $data['setup']['services'] ?? array() ) ) );
	}
	if ( class_exists( 'OYS_Settings' ) ) {
		OYS_Settings::update( array_intersect_key( (array) $data['setup']['studio'], array_flip( array( 'cancel_extend_days', 'cancel_policy', 'private_note' ) ) ) );
	}
	$old = get_page_by_path( 'prenatal-fitness', OBJECT, 'oy_class' );
	if ( $old && ! get_page_by_path( 'kismamatorna', OBJECT, 'oy_class' ) ) {
		wp_update_post( array( 'ID' => $old->ID, 'post_name' => 'kismamatorna' ) );
		$log[] = 'prenatal class: /kismamatorna/';
	}
	// gone: the slow flow class, the private sessions class and page, the „hatha flow or slow flow” article (old addresses redirect)
	foreach ( array( array( 'slow-flow', 'oy_class' ), array( 'private-yoga', 'oy_class' ), array( 'online-maganora', 'page' ), array( 'hatha-flow-vs-slow-flow', 'post' ) ) as $gone ) {
		$post = get_page_by_path( $gone[0], OBJECT, $gone[1] );
		if ( $post ) {
			wp_trash_post( $post->ID );
			$log[] = 'removed: ' . $gone[0];
		}
	}
	$journal = get_page_by_path( 'naplo' );
	if ( $journal && ! get_page_by_path( 'jogarol' ) ) {
		wp_update_post( array( 'ID' => $journal->ID, 'post_name' => 'jogarol', 'post_title' => $data['pages']['journal']['title'] ?? 'Jógáról' ) );
		$log[] = 'journal: /jogarol/';
	}
	if ( class_exists( 'OYS_Schedule' ) ) {
		global $wpdb;
		$want = $data['sessions'][0] ?? null;
		$keep = 0;
		foreach ( OYS_Schedule::templates() as $t ) {
			$is = $want && (int) $t->weekday === (int) $want['day'] && $t->start_time === $want['start'] && $t->class_slug === $want['class'];
			if ( $is && ! $keep ) {
				[ $sh, $sm ] = array_map( 'intval', explode( ':', $want['start'] ) );
				[ $eh, $em ] = array_map( 'intval', explode( ':', $want['end'] ) );
				OYS_Schedule::save_template( array_merge( (array) $t, array( 'duration_min' => ( $eh * 60 + $em ) - ( $sh * 60 + $sm ), 'location' => $want['location'], 'valid_from' => $want['valid_from'] ?? null, 'active' => 1 ) ), $t->id );
				$keep = (int) $t->id;
				continue;
			}
			if ( (int) $t->active ) {
				OYS_Schedule::save_template( array_merge( (array) $t, array( 'active' => 0 ) ), $t->id );
			}
		}
		// future dates of the other weekly classes, and of the kept one before it starts, go (cancelled with a refund where
		// someone booked); the kept class's other future dates get the new end and place
		$s     = OYS_Install::table( 'sessions' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$from  = $want['valid_from'] ?? '';
		$moved = 'Az órarend változott: az élő online óra szerdánként 18:30-tól 20:00-ig lesz, az első 2026. december 16-án.';
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $s WHERE kind = 'group' AND status = 'scheduled' AND starts_at > %s AND template_id > 0", $now ) ) as $row ) { // phpcs:ignore
			$early = $from && wp_date( 'Y-m-d', oys_ts( $row->starts_at ) ) < $from;
			if ( (int) $row->template_id === $keep && ! $early ) {
				$tpl = OYS_Schedule::template( $keep );
				$wpdb->update( $s, array( 'ends_at' => gmdate( 'Y-m-d H:i:s', oys_ts( $row->starts_at ) + (int) $tpl->duration_min * 60 ), 'location' => $tpl->location ), array( 'id' => $row->id ) );
			} elseif ( (int) $row->booked + (int) $row->online_booked > 0 ) {
				OYS_Schedule::cancel_session( $row->id, $moved );
			} else {
				$wpdb->delete( $s, array( 'id' => $row->id ) );
			}
		}
		$made  = OYS_Schedule::generate();
		$skip  = $keep && ! empty( $want['skip'] ) ? oy_import_skip_dates( $keep, (array) $want['skip'] ) : 0;
		$log[] = sprintf( 'timetable: Wednesday 18:30–20:00 from %s (%d new dates, %d left out)', $from ?: 'now', $made, $skip );
	}
	kses_remove_filters();
	$log = array_merge( $log, oy_import_all( true ) );
	kses_init_filters();
	update_option( 'oy_online_rev', $rev );
	return $log;
}

/**
 * Áron, 16 November: „a telefonszám megadásakor, mikor visszahívást kérek, nincs köszönő oldal” – the thank-you page of
 * the online site's requests (/koszonjuk/, not indexed), made once; her edits to it stay.
 */
function oy_migrate_1116() {
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_1116_rev' ) === '2026-11-16a' ) {
		return array();
	}
	update_option( 'oy_1116_rev', '2026-11-16a' );
	if ( get_page_by_path( 'koszonjuk' ) ) {
		return array();
	}
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Köszönöm!', 'post_name' => 'koszonjuk', 'post_content' => '[oy_thanks]', 'comment_status' => 'closed' ) );
	return $id && ! is_wp_error( $id ) ? array( 'thank-you page' ) : array();
}

/**
 * Olívia, 17 November (chat): „a lemondásnál az egyéni órát írjuk új sorba”; on the flow class „szedjük ki a rövid szót
 * …, toljunk egy lépéssel mindent és írjuk be 1. lépésként azt, hogy ráhangolódunk az órára …, utána jöhet 2. lépésként,
 * hogy megérkezünk: néhány perc légzés”; „minden pózhoz helyett … minden ászanához”.
 *
 * She has rewritten some of these paragraphs herself in the page editor (her lines, her words). The editor keeps her text
 * under the original text's key, so a paragraph whose original changes would lose her version: here her version follows
 * the paragraph to its new key and gets the same change (on her lines the tuning in is a line, a step, of its own).
 */
const OY_1117_TUNE = 'Ráhangolódunk az órára: pár percet beszélek arról a témáról, amit a jógafilozófiából hoztam, és ez fog visszaköszönni az adott órán az ászanákban.';

/**
 * The changes: the page's own text („content”: the flow paragraph also gets its lines, so the steps stay five on the wave)
 * and her own versions in the editor („edit”).
 */
function oy_1117_pairs() {
	$pose  = array( 'minden pózhoz adok', 'minden ászanához adok' );
	$relax = array( 'néhány pillanat csend és egy rövid relaxáció.', 'néhány pillanat csend és relaxáció.' );
	return array(
		'hatha-flow'       => array(
			'content' => array(
				array( 'Megérkezéssel kezdünk: néhány perc légzés, hogy a napot a nappali ajtaján kívül hagyd. Aztán', OY_1117_TUNE . '<br>Megérkezünk: néhány perc légzés, hogy a napot a nappali ajtaján kívül hagyd.<br>Aztán' ),
				array( 'egyensúlyozó pózokkal. Van, ami', 'egyensúlyozó pózokkal.<br>Van, ami' ),
				$relax,
				$pose,
			),
			'edit'    => array( array( 'Megérkezéssel kezdünk: néhány perc légzés', OY_1117_TUNE . ' Megérkezünk: néhány perc légzés' ), $relax, $pose ),
		),
		'schedule-pricing' => array(
			'content' => array( array( ' visszakapod az alkalmat. Az egyéni órát 24 órával', ' visszakapod az alkalmat.<br>Az egyéni órát 24 órával' ) ),
			'edit'    => array( array( ' visszakapod az alkalmat. Az egyéni órát 24 órával', ' visszakapod az alkalmat.<br>Az egyéni órát 24 órával' ) ),
		),
	);
}

function oy_1117_swap( array $pairs, $s ) {
	return str_replace( array_column( $pairs, 0 ), array_column( $pairs, 1 ), (string) $s );
}

/** The same change on her own text: on her lines (line breaks) the tuning in goes on a line of its own. */
function oy_1117_edit( $v, array $pairs ) {
	foreach ( $pairs as $p ) {
		$to = $p[1];
		if ( str_starts_with( $to, OY_1117_TUNE . ' ' ) && preg_match( '#<br\s*/?>#', $v ) ) {
			$to = OY_1117_TUNE . '<br>' . substr( $to, strlen( OY_1117_TUNE ) + 1 );
		}
		$v = str_replace( $p[0], $to, $v );
	}
	return $v;
}

/** Changes a post's text and moves her editor versions of the changed paragraphs along. Returns how many it moved. */
function oy_1117_apply( WP_Post $post, array $set ) {
	$content = $post->post_content;
	$moved   = 0;
	if ( function_exists( 'oye_edits' ) ) {
		$e    = oye_edits();
		$meta = get_option( 'oye_edits_meta', array() );
		$meta = is_array( $meta ) ? $meta : array();
		preg_match_all( '#<(p|li|h[1-4]|dd|blockquote)(?:\s[^>]*)?>((?:(?!</\1>).)*)</\1>#su', $content, $ms, PREG_SET_ORDER );
		foreach ( $ms as $m ) {
			$old = trim( $m[2] );
			$new = oy_1117_swap( $set['content'], $old );
			if ( $new === $old ) {
				continue;
			}
			// the key is taken from the text as the page shows it (typographic quotes): both forms are tried
			foreach ( array_unique( array( $old, wptexturize( $old ) ) ) as $i => $def ) {
				$k = oye_text_key( $def );
				if ( ! isset( $e['t'][ $k ] ) ) {
					continue;
				}
				$k2            = oye_text_key( 0 === $i ? $new : wptexturize( $new ) );
				$v             = oy_1117_edit( (string) $e['t'][ $k ], $set['edit'] );
				unset( $e['t'][ $k ] );
				$e['t'][ $k2 ] = $v;
				$meta[ $k2 ]   = array_merge( (array) ( $meta[ $k ] ?? array() ), array( 'def' => oye_kses( $new ) ) );
				if ( $k2 !== $k ) {
					unset( $meta[ $k ] );
				}
				++$moved;
				break;
			}
		}
		if ( $moved ) {
			update_option( 'oye_edits', $e, true );
			update_option( 'oye_edits_meta', $meta, false );
		}
	}
	$content = oy_1117_swap( $set['content'], $content );
	if ( $content !== $post->post_content ) {
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ) );
		kses_init_filters();
	}
	return $moved;
}

/**
 * Olívia, 17 November: „A mobilizáció és a relaxáció aloldalon a hogyan működik résznél legyen kicsit részletesebb,
 * ugyanúgy ahogy az egyéni jógánál és a kismamatornánál. pl a fizetés nincs benne” – the two pages shared one short text,
 * which she had changed in the page editor to „visszaírok egy javaslattal”; the new texts keep her word, and her edit of
 * the old shared text goes (nothing shows it any more).
 */
const OY_1117_HOW_OLD = 'Kérj időpontot a fiókodban, és visszaírok egy időponttal. Az óra élő, itt az oldalon, a böngésződben.';

function oy_1117_how() {
	return array(
		'stretching'       => 'Kérj időpontot a fiókodban: írd meg, mikor érsz rá, és hol érzed feszesnek vagy merevnek a tested, például a vállad, a csípőd vagy a hátad. Hamarosan visszaírok egy javaslattal: egy kattintással elfogadhatod, aztán kifizetheted, vagy lefoglalhatod a bérletedből. Az óra élő, itt az oldalon, a böngésződben; csak egy jógamatracnyi hely és egy kamerás telefon vagy laptop kell hozzá.',
		'relaxacio-legzes' => 'Kérj időpontot a fiókodban: írd meg, mikor érsz rá, és mire van most szükséged: kevesebb stresszre, jobb alvásra vagy csak egy kis nyugalomra. Hamarosan visszaírok egy javaslattal: egy kattintással elfogadhatod, aztán kifizetheted, vagy lefoglalhatod a bérletedből. Az óra élő, itt az oldalon, a böngésződben; elég hozzá egy csendes, kényelmes hely, ahol le tudsz feküdni, és egy kamerás telefon vagy laptop.',
	);
}

function oy_migrate_1117() {
	$rev = '2026-11-17c';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_1117_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_1117_rev', $rev );
	$log = array();
	foreach ( oy_1117_pairs() as $key => $set ) {
		$post = 'hatha-flow' === $key ? get_page_by_path( $key, OBJECT, 'oy_class' ) : get_page_by_path( oy_page_slug( OY_PAGE_SLUGS[ $key ] ?? $key ) );
		if ( $post ) {
			$moved = oy_1117_apply( $post, $set );
			$log[] = $key . ': Olívia, 17 November' . ( $moved ? ' (' . $moved . ' of her own texts kept)' : '' );
		}
	}
	// „legyen kicsit részletesebb … pl a fizetés nincs benne” – the two short „Hogyan működik?” texts
	$how = 0;
	foreach ( oy_1117_how() as $slug => $text ) {
		$post = get_page_by_path( $slug, OBJECT, 'oy_class' );
		if ( $post && str_contains( $post->post_content, '<p>' . OY_1117_HOW_OLD . '</p>' ) ) {
			kses_remove_filters();
			wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => str_replace( '<p>' . OY_1117_HOW_OLD . '</p>', '<p>' . $text . '</p>', $post->post_content ) ) ) );
			kses_init_filters();
			$log[] = $slug . ': how it works, in detail';
			++$how;
		}
	}
	if ( $how && function_exists( 'oye_edits' ) ) {
		$e    = oye_edits();
		$meta = get_option( 'oye_edits_meta', array() );
		$meta = is_array( $meta ) ? $meta : array();
		foreach ( array_unique( array( oye_text_key( OY_1117_HOW_OLD ), oye_text_key( wptexturize( OY_1117_HOW_OLD ) ) ) ) as $k ) {
			unset( $e['t'][ $k ] );
			if ( is_array( $meta ) ) {
				unset( $meta[ $k ] );
			}
		}
		update_option( 'oye_edits', $e, true );
		update_option( 'oye_edits_meta', $meta, false );
	}
	// „A csoportos óra aloldalára is tegyük be az árakat” – „Árak” before the group class's booking button
	$post = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
	if ( $post && ! preg_match( '~<h2[^>]*>\s*Árak\s*</h2>~u', $post->post_content ) && false !== strpos( $post->post_content, '[oy_booking_link]' ) ) {
		$content = preg_replace( '~\[oy_booking_link\]~', "<h2>Árak</h2>\n[oy_prices service=\"group\"]\n[oy_booking_link]", $post->post_content, 1 );
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ) );
		kses_init_filters();
		$log[] = 'hatha-flow: prices';
	}
	return $log;
}
