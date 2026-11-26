<?php
/**
 * Setup as code (hpv_child_setup – iu_theme runs it after every new version; by hand: wp hpv-site setup --force).
 *
 *   1. Settings: site name, Budapest time, Hungarian dates, addresses without a trailing slash (/rolam, as before).
 *   2. Pages: every page of the old site, with its content as an HTML block. A page is updated with a new version's
 *      content only if nobody has edited it since (md5 of what the setup last wrote). The addresses have no accents
 *      (Áron, 25 November: /rólam → /rolam): a page still at its old accented address is renamed first; the old
 *      address answers with a 301 (functions.php, the data's „moved” map).
 *   3. Home page: "fooldal" (its own address goes to the home page).
 * Idempotent: a second run changes nothing.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'hpv_child_setup', function ( $from, $to, $slug ) {
	$log = oj_setup();
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $slug . ' ' . $to . ': ' . implode( ' · ', $log ) );
	}
}, 10, 3 );

function oj_setup() {
	if ( ! get_current_user_id() ) {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		if ( $admins ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}
	$data = oj_data();
	$log  = array();
	if ( in_array( get_option( 'blogname' ), array( '', 'WordPress', 'Olivia Kovács Yoga' ), true ) || false === get_option( 'oj_setup_md5' ) ) {
		update_option( 'blogname', 'Olívia jóga' );
		update_option( 'blogdescription', $data['site_title'] ?? '' );
	}
	update_option( 'timezone_string', 'Europe/Budapest' );
	update_option( 'date_format', 'Y. F j.' );
	update_option( 'time_format', 'H:i' );
	update_option( 'start_of_week', 1 );
	if ( '/%postname%' !== get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%' );
	}
	// WordPress's own sample post and page, in any language (the Hungarian ones: „Helló Világ!”, „Ez egy minta oldal”)
	$hello = get_post( 1 );
	if ( $hello && 'post' === $hello->post_type && in_array( $hello->post_name, array( 'hello-world', 'hello-vilag' ), true ) ) {
		wp_delete_post( 1, true );
	}
	foreach ( array( 'sample-page', 'minta-oldal', 'ez-egy-minta-oldal' ) as $s ) {
		$sample = get_page_by_path( $s );
		if ( $sample && in_array( $sample->post_title, array( 'Sample Page', 'Minta oldal', 'Ez egy minta oldal' ), true ) ) {
			wp_delete_post( $sample->ID, true );
		}
	}

	kses_remove_filters();
	$md5     = (array) get_option( 'oj_setup_md5', array() );
	$made    = 0;
	$updated = 0;
	$renamed = 0;
	// the accented addresses → without accents (/rólam → /rolam); the setup's own md5 moves with the page
	foreach ( (array) ( $data['moved'] ?? array() ) as $old => $new ) {
		$old  = (string) $old;
		$slug = '' === (string) $new ? 'fooldal' : (string) $new;
		$page = get_page_by_path( $old );
		if ( ! $page || $page->post_name === $slug ) {
			continue;
		}
		$taken = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $taken && $taken->ID !== $page->ID && 'publish' !== $taken->post_status ) {
			// an unpublished page holds the new address – on the live site WordPress's own draft „Adatkezelési tájékoztató”
			// (made at install, /adatkezelesi-tajekoztato): it steps aside, nothing of it is lost
			wp_update_post( array( 'ID' => $taken->ID, 'post_name' => $slug . '-wp-vazlat' ) );
			$taken = null;
		}
		if ( $taken && $taken->ID !== $page->ID ) {
			continue; // a published page of the same address: left as it is
		}
		wp_update_post( array( 'ID' => $page->ID, 'post_name' => $slug ) );
		$key = 'főoldal' === $old ? 'fooldal' : $slug;
		if ( isset( $md5[ $old ] ) ) {
			$md5[ $key ] = $md5[ $old ];
			unset( $md5[ $old ] );
		}
		$renamed++;
	}
	foreach ( (array) ( $data['pages'] ?? array() ) as $path => $p ) {
		$path = (string) $path;
		if ( '' === $path || in_array( $path, (array) ( $data['deleted'] ?? array() ), true ) ) {
			continue; // the home page is "főoldal"
		}
		$content  = "<!-- wp:html -->\n" . $p['content'] . "\n<!-- /wp:html -->";
		$existing = get_page_by_path( $path );
		if ( ! $existing ) {
			$id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $p['name'], 'post_name' => $path, 'post_content' => $content ) ) );
			if ( $id && ! is_wp_error( $id ) ) {
				$md5[ $path ] = md5( get_post_field( 'post_content', $id, 'raw' ) );
				$made++;
			}
			continue;
		}
		$now = md5( $existing->post_content );
		if ( isset( $md5[ $path ] ) && $md5[ $path ] === $now && md5( $content ) !== $now ) { // ours, not edited, new copy
			wp_update_post( wp_slash( array( 'ID' => $existing->ID, 'post_content' => $content ) ) );
			$md5[ $path ] = md5( get_post_field( 'post_content', $existing->ID, 'raw' ) );
			$updated++;
		}
	}
	update_option( 'oj_setup_md5', $md5, false );
	kses_init_filters();
	// the image positions chosen in the admin (Megjelenés → Képek helye) move with the pictures' new file names
	$pos   = (array) get_option( 'oj_img_pos', array() );
	$moved = 0;
	foreach ( (array) ( $data['img_renamed'] ?? array() ) as $from => $to ) {
		if ( isset( $pos[ $from ] ) && ! isset( $pos[ $to ] ) ) {
			$pos[ $to ] = $pos[ $from ];
			$moved++;
		}
	}
	if ( $moved ) {
		update_option( 'oj_img_pos', $pos );
		$log[] = sprintf( 'image positions: %d moved to the new file names', $moved );
	}
	$log[] = sprintf( '%d new pages, %d updated, %d renamed (no accents)', $made, $updated, $renamed );

	$home = get_page_by_path( 'fooldal' );
	if ( $home ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home->ID );
		$log[] = 'home page: fooldal';
	}
	foreach ( (array) ( $data['deleted'] ?? array() ) as $gone ) { // pages Olivia had removed: to the bin
		$pg = get_page_by_path( $gone );
		if ( $pg ) {
			wp_trash_post( $pg->ID );
			$log[] = 'removed: ' . $gone;
		}
	}
	// az adatkezelési tájékoztató a WordPress „adatvédelmi oldala” (a sütisáv és a WordPress maga is ide linkel)
	$privacy = get_page_by_path( 'adatkezelesi-tajekoztato' );
	if ( $privacy && 'publish' === $privacy->post_status && (int) get_option( 'wp_page_for_privacy_policy' ) !== $privacy->ID ) {
		update_option( 'wp_page_for_privacy_policy', $privacy->ID );
		$log[] = 'privacy page: adatkezelesi-tajekoztato';
	}
	do_action( 'iu_llms_flush' ); // az llms.txt / llms-full.txt az új kiadás szövegéből
	$log[] = oj_sync_media();
	flush_rewrite_rules( false );
	update_option( 'oj_setup_log', array( 'version' => $data['version'] ?? '', 'log' => $log, 'time' => time() ), false );
	return $log;
}

/**
 * The photos a new version uses that the media folder does not have yet (the VPS copies the folder only when the
 * site is created): fetched from the oliv repo on GitHub. At most ~40 seconds a run; the rest on the next run.
 */
function oj_sync_media() {
	$up  = wp_upload_dir( null, false );
	$dir = defined( 'OJ_MEDIA_DIR' ) ? OJ_MEDIA_DIR : $up['basedir'] . '/oliviajoga-img';
	if ( ! is_dir( $dir ) ) {
		return 'photos: from GitHub (no media folder)';
	}
	preg_match_all( '#%%MEDIA%%/([^"\'\s?\\\\]+)#', (string) file_get_contents( __DIR__ . '/../data/site.json' ), $m );
	$need = array_unique( array_map( 'rawurldecode', $m[1] ) );
	$base = 'https://raw.githubusercontent.com/aronritok-sketch/oliv/claude/nifty-ramanujan-3zifiu/oliviajoga-hu/assets/img/';
	$got  = 0;
	$left = 0;
	$t0   = microtime( true );
	// a picture renamed to its keyword name (Áron, 26 November) is already here under its old name: copied, not fetched
	$old_of = array_flip( (array) ( oj_data()['img_renamed'] ?? array() ) );
	foreach ( $need as $rel ) {
		if ( str_contains( $rel, '..' ) || is_file( $dir . '/' . $rel ) ) {
			continue;
		}
		if ( preg_match( '#^(.+?)((?:-(?:600|800|1200|1600))?\.\w+)$#', $rel, $mm ) && isset( $old_of[ $mm[1] ] ) && is_file( $dir . '/' . $old_of[ $mm[1] ] . $mm[2] ) ) {
			wp_mkdir_p( dirname( $dir . '/' . $rel ) );
			if ( copy( $dir . '/' . $old_of[ $mm[1] ] . $mm[2], $dir . '/' . $rel ) ) {
				$got++;
				continue;
			}
		}
		if ( microtime( true ) - $t0 > 40 ) {
			$left++;
			continue;
		}
		$r = wp_remote_get( $base . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ), array( 'timeout' => 20 ) );
		if ( 200 !== wp_remote_retrieve_response_code( $r ) ) {
			$left++;
			continue;
		}
		wp_mkdir_p( dirname( $dir . '/' . $rel ) );
		file_put_contents( $dir . '/' . $rel, wp_remote_retrieve_body( $r ) );
		$got++;
	}
	return sprintf( 'photos: %d fetched, %d left', $got, $left );
}

// Pages Olivia removed (Jógaóra cégeknek): the old address – with or without accents – goes to the home page (301).
add_action( 'template_redirect', function () {
	if ( ! is_404() ) {
		return;
	}
	$req  = trim( rawurldecode( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ), '/' ); // phpcs:ignore
	$base = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $req, $base ) ) {
		$req = trim( substr( $req, strlen( $base ) ), '/' );
	}
	if ( in_array( $req, (array) ( oj_data()['deleted'] ?? array() ), true ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );
