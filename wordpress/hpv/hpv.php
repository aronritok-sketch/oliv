<?php
/**
 * HelloProVision delivery (hlprv deploy/README.md): this theme runs as a child of iu_theme, which updates it from
 * the GitHub releases and runs the setup below after every new version (dev → staging → live).
 *
 * Built from the oliv repo (wordpress/olivia-yoga) by wordpress/hpv/build-child-themes.py – edit the source there.
 */

defined( 'ABSPATH' ) || exit;

// The design is this theme's own: the parent's base styles stay off (they reset type and spacing).
add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'parent-style' );
	wp_dequeue_style( 'style' ); // style.css only holds the theme header.
}, 20 );

// The booking plugin's REST routes (Stripe webhook, mobile app) live under /wp-json/, as documented for the
// Stripe dashboard and the app; iu_theme hides the REST prefix by default.
add_filter( 'rest_url_prefix', fn() => 'wp-json', 20 );

/**
 * Setup as code (hpv_child_setup, after every new version; by hand: wp hpv-site setup --force):
 * the edition's settings (languages, currency, prices, time zone), then the content – pages, class types, FAQs,
 * journal, photos, menu, front page and, with the Olivia Studio plugin, the weekly timetable. Idempotent: pages
 * and posts are matched by address and kept as they are (the theme's "Refresh the content" button overwrites).
 */
add_action( 'hpv_child_setup', function ( $from, $to, $slug ) {
	if ( ! get_current_user_id() ) {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		if ( $admins ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}
	require_once get_stylesheet_directory() . '/inc/demo-import.php';
	$log = array();
	if ( '' === (string) $from || get_option( 'oy_edition' ) !== oy_edition() ) {
		$log = array_merge( $log, oy_switch_edition( oy_edition() ) );
	}
	if ( 'Hello world!' === get_the_title( 1 ) ) {
		wp_delete_post( 1, true );
	}
	$sample = get_page_by_path( 'sample-page' );
	if ( $sample ) {
		wp_delete_post( $sample->ID, true );
	}
	if ( get_option( 'permalink_structure' ) === '' ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}
	kses_remove_filters();
	$log = array_merge( $log, oy_import_all( false ) );
	kses_init_filters();
	flush_rewrite_rules( false );
	update_option( 'oy_hpv_setup_log', array( 'version' => $to, 'log' => $log, 'time' => time() ), false );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $slug . ' ' . $to . ': ' . implode( ' · ', $log ) );
	}
}, 10, 3 );
