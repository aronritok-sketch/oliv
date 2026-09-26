<?php
/**
 * Sets up a local/CI WordPress for development and tests. Run from the WordPress folder:
 *   php …/dev/setup-site.php install   (installs WordPress)
 *   php …/dev/setup-site.php           (plugin + theme; run it twice — the theme's code only
 *                                       loads on the request after it is switched on)
 *
 * Installs WordPress (admin / admin12345), turns on the Olivia Studio plugin and the Olivia Yoga
 * theme, imports the content and timetable, and points payments (and Zoom, when OYS_ZOOM_API_BASE
 * is defined) at the local mock.
 */

if ( 'install' === ( $argv[1] ?? '' ) ) {
	define( 'WP_INSTALLING', true ); // Lets wp-load run before the tables exist.
}
require getcwd() . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'Olivia Kovács Yoga', 'admin', 'olivia@example.com', true, '', 'admin12345' );
	update_option( 'timezone_string', 'America/New_York' );
	update_option( 'permalink_structure', '/%postname%/' );
	echo "WordPress installed\n";
}
if ( 'install' === ( $argv[1] ?? '' ) ) {
	exit( 0 );
}

if ( ! is_plugin_active( 'olivia-studio/olivia-studio.php' ) ) {
	$r = activate_plugin( 'olivia-studio/olivia-studio.php' );
	echo is_wp_error( $r ) ? 'Plugin error: ' . $r->get_error_message() . "\n" : "Plugin active\n";
}

if ( 'olivia-yoga' !== get_stylesheet() ) {
	switch_theme( 'olivia-yoga' );
	echo "Theme switched — run this script once more to import the content.\n";
	exit( 0 );
}

require_once get_template_directory() . '/inc/demo-import.php';
wp_set_current_user( 1 );
echo 'Imported: ' . implode( ', ', oy_import_all() ) . "\n";

OYS_Settings::update( array( 'stripe_mode' => 'test', 'stripe_test_secret' => 'sk_test_mock', 'stripe_test_webhook' => 'whsec_mock' ) );
if ( defined( 'OYS_ZOOM_API_BASE' ) ) {
	OYS_Settings::update( array( 'zoom_account_id' => 'acc_mock', 'zoom_client_id' => 'zoom_client', 'zoom_client_secret' => 'zoom_secret', 'zoom_auto' => 1 ) );
	echo "Zoom mock connected.\n";
}
flush_rewrite_rules();
echo "Stripe mock keys set. Done.\n";
