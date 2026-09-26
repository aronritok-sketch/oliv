<?php
/**
 * Plugin settings (one option array). Secrets can also come from wp-config.php constants,
 * which take precedence: OYS_STRIPE_SECRET_KEY, OYS_STRIPE_WEBHOOK_SECRET, OYS_ZOOM_ACCOUNT_ID,
 * OYS_ZOOM_CLIENT_ID, OYS_ZOOM_CLIENT_SECRET.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Settings {

	const OPTION = 'oys_settings';

	public static function defaults() {
		return array(
			'stripe_mode'             => 'test',
			'stripe_test_secret'      => '',
			'stripe_test_webhook'     => '',
			'stripe_live_secret'      => '',
			'stripe_live_webhook'     => '',
			'currency'                => 'usd',
			'hold_minutes'            => 30,
			'cancel_hours'            => 12,
			'private_cancel_hours'    => 24,
			'dropin_credit_days'      => 90,
			'waitlist_cutoff_hours'   => 2,
			'booking_window_days'     => 28,
			'booking_close_minutes'   => 30,
			'weeks_ahead'             => 6,
			'reminder_hours'          => 24,
			'reminder2_hours'         => 0,
			'join_reminder_minutes'   => 30,
			'pass_expiry_days'        => 7,
			'max_guests'              => 4,
			'online_price_cents'      => 600,
			'online_per_credit'       => 4,
			'zoom_account_id'         => '',
			'zoom_client_id'          => '',
			'zoom_client_secret'      => '',
			'zoom_host'               => 'me',
			'zoom_auto'               => 1,
			'zoom_personal'           => 0,
			'zoom_waiting_room'       => 0,
			'email_from_name'         => 'Olivia Kovács Yoga',
			'email_from'              => get_option( 'admin_email' ),
			'notify_email'            => get_option( 'admin_email' ),
			'waiver_version'          => '2026-09',
			'waiver_text'             => "I understand that yoga includes physical movement and that there is a risk of injury. I confirm I am in good health or have my doctor's approval to practice, and I will tell Olivia about injuries, pregnancy or health conditions before class. I will listen to my body, rest whenever I need to, and take responsibility for my own practice. I release Olivia Kovács Yoga from liability for injury, except in cases of gross negligence.",
			'cancel_policy'           => 'Cancel up to 12 hours before a group class and the class goes back on your pass. Paid for a drop-in? You get a class credit instead. Later cancellations and no-shows use the class. Private sessions can be moved or cancelled up to 24 hours before.',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	public static function ensure_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	public static function is_live() {
		return 'live' === self::get( 'stripe_mode' );
	}

	public static function stripe_secret() {
		if ( defined( 'OYS_STRIPE_SECRET_KEY' ) ) {
			return OYS_STRIPE_SECRET_KEY;
		}
		return self::is_live() ? self::get( 'stripe_live_secret' ) : self::get( 'stripe_test_secret' );
	}

	public static function webhook_secret() {
		if ( defined( 'OYS_STRIPE_WEBHOOK_SECRET' ) ) {
			return OYS_STRIPE_WEBHOOK_SECRET;
		}
		return self::is_live() ? self::get( 'stripe_live_webhook' ) : self::get( 'stripe_test_webhook' );
	}

	public static function payments_ready() {
		return (bool) self::stripe_secret();
	}
}
