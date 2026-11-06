<?php
/**
 * Setup as code (hpv_child_setup – iu_theme runs it after every new version; by hand: wp hpv-site setup --force).
 *
 *   1. Settings: site name, Budapest time, Hungarian dates, addresses without a trailing slash (/rólam, as before).
 *   2. Pages: every page of the old site at its old address, with its content as an HTML block. A page is updated
 *      with a new version's content only if nobody has edited it since (md5 of what the setup last wrote).
 *   3. Home page: "főoldal" (its own address goes to the home page).
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
	if ( 'Hello world!' === get_the_title( 1 ) ) {
		wp_delete_post( 1, true );
	}
	$sample = get_page_by_path( 'sample-page' ) ?: get_page_by_path( 'minta-oldal' );
	if ( $sample ) {
		wp_delete_post( $sample->ID, true );
	}

	kses_remove_filters();
	// The old addresses keep their accents (/rólam, not /rolam): WordPress would strip them when it makes the slug.
	$keep_accents = fn( $title, $raw, $context ) => 'save' === $context && '' !== (string) $raw ? sanitize_title_with_dashes( $raw, '', 'save' ) : $title;
	add_filter( 'sanitize_title', $keep_accents, 11, 3 );
	$md5     = (array) get_option( 'oj_setup_md5', array() );
	$made    = 0;
	$updated = 0;
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
	remove_filter( 'sanitize_title', $keep_accents, 11 );
	kses_init_filters();
	$log[] = sprintf( '%d new pages, %d updated', $made, $updated );

	$home = get_page_by_path( 'főoldal' );
	if ( $home ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home->ID );
		$log[] = 'home page: főoldal';
	}
	foreach ( (array) ( $data['deleted'] ?? array() ) as $gone ) { // pages Olivia had removed: to the bin
		$pg = get_page_by_path( $gone );
		if ( $pg ) {
			wp_trash_post( $pg->ID );
			$log[] = 'removed: ' . $gone;
		}
	}
	flush_rewrite_rules( false );
	update_option( 'oj_setup_log', array( 'version' => $data['version'] ?? '', 'log' => $log, 'time' => time() ), false );
	return $log;
}

// Pages Olivia removed (Jógaóra cégeknek): the old address goes to the home page (301), as on the static site.
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
