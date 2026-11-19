<?php
/**
 * HelloProVision delivery (hlprv deploy/README.md): this theme runs as a child of iu_theme, which updates it from
 * the GitHub releases and runs the setup below after every new version (dev → staging → live).
 *
 * Built from the oliv repo (wordpress/olivia-yoga) by wordpress/hpv/build-child-themes.py – edit the source there.
 */

defined( 'ABSPATH' ) || exit;

// HelloProVision élesítési csomag (Áron, 2026-11-17: „Történjen mindig ugyanaz”): az éles oldalon a varázsló, az Ads és a
// HotPot Connect, a Leads és a SEO mu-plugin – magától, minden kiadásnál (inc/hpv-kit.php, a forrás: wordpress/hpv/).
if ( is_file( __DIR__ . '/hpv-kit.php' ) ) {
	require_once __DIR__ . '/hpv-kit.php';
}

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
	$log = oy_bundled_plugin_update(); // the booking plugin that came with this version of the theme
	if ( '' === (string) $from || get_option( 'oy_edition' ) !== oy_edition() ) {
		$log = array_merge( $log, oy_switch_edition( oy_edition() ) );
	}
	$log = array_merge( $log, oy_remove_wp_samples() ); // "Hello world!", "Sample Page" – in any language
	if ( get_option( 'permalink_structure' ) === '' ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}
	$log = array_merge( $log, oy_migrate_online() ); // the online site after Olivia's notes of 12 November (once)
	$log = array_merge( $log, oy_mail_setup() ); // the edition's later settings: sender, reply-to, no birthday code / draw online (once, where still default)
	$log = array_merge( $log, oy_migrate_1113() ); // the online site after Olivia's notes of 13 November (once)
	$log = array_merge( $log, oy_migrate_legal() ); // the ÁSZF and Impresszum again when they change (once per revision)
	$log = array_merge( $log, oy_migrate_prices() ); // Olivia's prices of 14 November (once)
	$log = array_merge( $log, oy_migrate_images() ); // Hungarian names and texts for the photos, web copies of hers (once)
	$log = array_merge( $log, oy_migrate_about() ); // the About page broken up (once, only if its text is still the theme's)
	$log = array_merge( $log, oy_migrate_doc_1114() ); // her raw journal photos, the prenatal kit „may be needed” (once)
	$log = array_merge( $log, oy_migrate_1114c() ); // 2015 on her timeline, names for the web copies of her photos (once)
	$log = array_merge( $log, oy_migrate_1114d() ); // 2018 on her timeline, the general kit list, „charge your device” (once)
	$log = array_merge( $log, oy_migrate_1116() ); // the thank-you page of the requests (once)
	$log = array_merge( $log, oy_migrate_1117() ); // Olívia's chat of 17 November: the flow class's steps, „ászana”, the one-to-one rule on its own line (once; her editor texts kept)
	$log = array_merge( $log, oy_migrate_ga4() ); // the privacy page: Google Analytics with consent (once)
	$log = array_merge( $log, oy_migrate_titles_1119() ); // shorter article titles in the search results (once)
	$log = array_merge( $log, oy_migrate_noshow_1119() ); // the cancellation FAQ: a missed group class can still be watched (once)
	kses_remove_filters();
	$log = array_merge( $log, oy_import_all( false ) );
	kses_init_filters();
	flush_rewrite_rules( false );
	update_option( 'oy_hpv_setup_log', array( 'version' => $to, 'log' => $log, 'time' => time() ), false );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $slug . ' ' . $to . ': ' . implode( ' · ', $log ) );
	}
}, 10, 3 );

/**
 * The booking plugin rides along with the theme (plugin/olivia-studio.zip + .version, wordpress/hpv/build-child-themes.py):
 * a live site gets new code only through its pinned theme version (deploy/sites.json), so the theme's setup installs the
 * bundled plugin when it is newer than the one on the site (Áron, 2026-11-13: „tedd fel te az élesre”). Never downgrades.
 */
function oy_bundled_plugin_update() {
	$dir  = get_stylesheet_directory() . '/plugin';
	$zip  = $dir . '/olivia-studio.zip';
	$want = is_readable( $dir . '/olivia-studio.version' ) ? trim( (string) file_get_contents( $dir . '/olivia-studio.version' ) ) : '';
	if ( '' === $want || ! is_readable( $zip ) ) {
		return array();
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$file = 'olivia-studio/olivia-studio.php';
	$have = (string) ( get_plugins()[ $file ]['Version'] ?? '' );
	if ( '' !== $have && version_compare( $have, $want, '>=' ) ) {
		return array();
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$up = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$ok = $up->install( $zip, array( 'overwrite_package' => true ) );
	if ( true !== $ok ) {
		return array( 'olivia-studio ' . $want . ' NOT installed: ' . ( is_wp_error( $ok ) ? $ok->get_error_message() : 'unknown error' ) );
	}
	if ( ! is_plugin_active( $file ) ) {
		activate_plugin( $file );
	}
	return array( 'olivia-studio ' . ( '' !== $have ? $have : '–' ) . ' → ' . $want );
}
