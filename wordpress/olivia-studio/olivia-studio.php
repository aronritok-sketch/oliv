<?php
/**
 * Plugin Name:       Olivia Studio — Booking & Payments
 * Description:       Class schedule, online booking, class passes, memberships, private sessions, gift cards, waitlist, customer accounts and Stripe payments for Olivia Kovacs Yoga.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Olivia Kovacs Yoga
 * Text Domain:       olivia-studio
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'OYS_VERSION', '1.3.0' );
define( 'OYS_DB_VERSION', '10' );
define( 'OYS_FILE', __FILE__ );
define( 'OYS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OYS_URL', plugin_dir_url( __FILE__ ) );

require_once OYS_DIR . 'includes/class-i18n.php';
require_once OYS_DIR . 'includes/class-install.php';
require_once OYS_DIR . 'includes/helpers.php';
require_once OYS_DIR . 'includes/class-settings.php';
require_once OYS_DIR . 'includes/class-products.php';
require_once OYS_DIR . 'includes/class-schedule.php';
require_once OYS_DIR . 'includes/class-passes.php';
require_once OYS_DIR . 'includes/class-bookings.php';
require_once OYS_DIR . 'includes/class-orders.php';
require_once OYS_DIR . 'includes/class-stripe.php';
require_once OYS_DIR . 'includes/class-email-templates.php';
require_once OYS_DIR . 'includes/class-emails.php';
require_once OYS_DIR . 'includes/class-messages.php';
require_once OYS_DIR . 'includes/class-locations.php';
require_once OYS_DIR . 'includes/class-coupons.php';
require_once OYS_DIR . 'includes/class-rewards.php';
require_once OYS_DIR . 'includes/class-newsletter.php';
require_once OYS_DIR . 'includes/class-ai.php';
require_once OYS_DIR . 'includes/class-teachers.php';
require_once OYS_DIR . 'includes/class-connect.php';
require_once OYS_DIR . 'includes/class-customers.php';
require_once OYS_DIR . 'includes/class-privates.php';
require_once OYS_DIR . 'includes/class-gifts.php';
require_once OYS_DIR . 'includes/class-memberships.php';
require_once OYS_DIR . 'includes/class-zoom.php';
require_once OYS_DIR . 'includes/class-security.php';
require_once OYS_DIR . 'includes/class-cron.php';
require_once OYS_DIR . 'includes/class-frontend.php';
require_once OYS_DIR . 'includes/class-privacy.php';
require_once OYS_DIR . 'includes/admin/class-calendar.php';
require_once OYS_DIR . 'includes/class-app-api.php';

if ( is_admin() ) {
	require_once OYS_DIR . 'includes/admin/class-admin.php';
	require_once OYS_DIR . 'includes/admin/class-teachers-admin.php';
}

OYS_I18n::boot();

register_activation_hook( __FILE__, array( 'OYS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OYS_Cron', 'unschedule' ) );

// Translations and the database upgrade run on init (WordPress 6.7+ warns about translating earlier).
add_action( 'init', function () {
	load_plugin_textdomain( 'olivia-studio', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	OYS_Install::maybe_upgrade();
}, 1 );

// WordPress's own Hungarian and Spanish files (dates, month names, login screens): fetched once
// when an administrator opens the dashboard, if they are missing.
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'install_languages' ) || wp_doing_ajax() || get_transient( 'oys_core_langs_checked' ) ) {
		return;
	}
	set_transient( 'oys_core_langs_checked', 1, DAY_IN_SECONDS );
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	$have = get_available_languages();
	foreach ( OYS_I18n::languages() as $l ) {
		if ( 'en_US' !== $l['locale'] && ! in_array( $l['locale'], $have, true ) && wp_can_install_language_pack() ) {
			wp_download_language_pack( $l['locale'] );
		}
	}
} );

// Customers get their emails in the language they use the site in.
add_action( 'user_register', fn( $user_id ) => OYS_I18n::remember( (int) $user_id ), 1 );
add_action( 'init', function () {
	if ( is_user_logged_in() && ! wp_doing_cron() && ! oys_is_admin_screen() ) {
		OYS_I18n::remember();
	}
}, 20 );

add_action( 'plugins_loaded', function () {
	OYS_Customers::init();
	OYS_Stripe::init();
	OYS_Cron::init();
	OYS_Frontend::init();
	OYS_Privacy::init();
	OYS_Calendar::init();
	OYS_Zoom::init();
	OYS_App_API::init();
	OYS_Coupons::init();
	OYS_Newsletter::init();
	OYS_Teachers::init();
	if ( is_admin() ) {
		OYS_Admin::init();
		OYS_Teachers_Admin::init();
	}
} );
