<?php
/**
 * Plugin Name:       Olivia Studio — Booking & Payments
 * Description:       Class schedule, online booking, class passes, private sessions, gift cards, waitlist, customer accounts and Stripe payments for Olivia Kovács Yoga.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Olivia Kovács Yoga
 * Text Domain:       olivia-studio
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'OYS_VERSION', '1.0.0' );
define( 'OYS_DB_VERSION', '1' );
define( 'OYS_FILE', __FILE__ );
define( 'OYS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OYS_URL', plugin_dir_url( __FILE__ ) );

require_once OYS_DIR . 'includes/class-install.php';
require_once OYS_DIR . 'includes/helpers.php';
require_once OYS_DIR . 'includes/class-settings.php';
require_once OYS_DIR . 'includes/class-products.php';
require_once OYS_DIR . 'includes/class-schedule.php';
require_once OYS_DIR . 'includes/class-passes.php';
require_once OYS_DIR . 'includes/class-bookings.php';
require_once OYS_DIR . 'includes/class-orders.php';
require_once OYS_DIR . 'includes/class-stripe.php';
require_once OYS_DIR . 'includes/class-emails.php';
require_once OYS_DIR . 'includes/class-customers.php';
require_once OYS_DIR . 'includes/class-privates.php';
require_once OYS_DIR . 'includes/class-gifts.php';
require_once OYS_DIR . 'includes/class-cron.php';
require_once OYS_DIR . 'includes/class-frontend.php';
require_once OYS_DIR . 'includes/class-privacy.php';

if ( is_admin() ) {
	require_once OYS_DIR . 'includes/admin/class-admin.php';
}

register_activation_hook( __FILE__, array( 'OYS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OYS_Cron', 'unschedule' ) );

add_action( 'plugins_loaded', function () {
	OYS_Install::maybe_upgrade();
	load_plugin_textdomain( 'olivia-studio', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	OYS_Customers::init();
	OYS_Stripe::init();
	OYS_Cron::init();
	OYS_Frontend::init();
	OYS_Privacy::init();
	if ( is_admin() ) {
		OYS_Admin::init();
	}
} );
