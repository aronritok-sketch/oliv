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
	$log   = array_merge( $log, oy_remove_wp_samples(), oy_import_privacy( $data, $refresh ) );

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
 *    at /online-jogaorak/kismamatorna/;
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
	foreach ( OY_ONLINE_POST_SLUGS as $en => $hu ) {
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
	$rev = '2026-11-13';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_content_rev' ) === $rev ) {
		return array();
	}
	kses_remove_filters();
	$log = oy_import_all( true );
	kses_init_filters();
	update_option( 'oy_content_rev', $rev );
	return array_merge( array( 'content: Olivia 13 November' ), $log );
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
