<?php
/**
 * Plugin settings (one option array). Secrets can also come from wp-config.php constants,
 * which take precedence: OYS_STRIPE_SECRET_KEY, OYS_STRIPE_WEBHOOK_SECRET, OYS_STRIPE_CONNECT_WEBHOOK_SECRET, OYS_ZOOM_ACCOUNT_ID,
 * OYS_ZOOM_CLIENT_ID, OYS_ZOOM_CLIENT_SECRET, OYS_LIVEKIT_URL, OYS_LIVEKIT_API_KEY, OYS_LIVEKIT_API_SECRET (HathaFlow Live), OYS_ANTHROPIC_API_KEY.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Settings {

	const OPTION = 'oys_settings';

	public static function defaults() {
		return array(
			'pay_provider'            => 'stripe',
			'stripe_mode'             => 'test',
			'stripe_test_secret'      => '',
			'stripe_test_webhook'     => '',
			'stripe_live_secret'      => '',
			'stripe_live_webhook'     => '',
			'stripe_test_connect_webhook' => '',
			'stripe_live_connect_webhook' => '',
			'currency'                => 'usd',
			'languages'               => 'en,hu,es',
			'default_lang'            => 'en',
			'hold_minutes'            => 30,
			'cancel_hours'            => 12,
			'private_cancel_hours'    => 24,
			'cancel_extend_days'      => 7,
			'auto_attendance_hours'   => 3, // the attendance sheet fills itself in this many hours after a class (0 = off)
			// timetables: list days without a group class too („yes”), or only the days with a class and, under the
			// timetable, a box for the one-to-one sessions („no”; Olivia, 13 November: „itt legyenek csak a szerdák”)
			'schedule_empty_days'     => 'yes',
			'dropin_credit_days'      => 90,
			'waitlist_cutoff_hours'   => 2,
			// 0: a free spot is offered to the whole waitlist by email, first to book gets it (Olivia, 16 November); 1: people
			// with a pass or membership are booked in by themselves, in waitlist order
			'waitlist_auto'           => 0,
			'booking_window_days'     => 42, // Áron, 16 November: „6 héttel előre foglalható”
			'booking_close_minutes'   => 30,
			'weeks_ahead'             => 6,
			'reminder_hours'          => 24,
			'reminder2_hours'         => 0,
			'join_reminder_minutes'   => 30,
			'pass_expiry_days'        => 7,
			'max_guests'              => 4,
			'pay_later'               => 'all',
			'pay_later_max_no_shows'  => 2,
			'pay_later_note'          => 'Pay at the studio with cash, Venmo or Zelle.',
			'donation_min_cents'      => 500,
			'donation_suggestions'    => '5,10,15,20',
			'private_first_extra_min' => 15,
			'private_note'            => 'Your first private session includes about 15 extra minutes to talk through your goals, so please plan a little more time. We may take a few extra minutes for this later on too.',
			'fb_group_url'            => 'https://www.facebook.com/groups/485915674202871',
			'min_people_default'      => 2,
			'decide_hours_default'    => 3,
			'min_nudge_hours'         => 12,
			'birthday_on'             => 1,
			'birthday_percent'        => 20,
			'birthday_days'           => 30,
			'raffle_period'           => 'half',
			'raffle_prize'            => 'pack-5',
			'raffle_announce'         => 1,
			'newsletter_batch'        => 50,
			'ai_api_key'              => '',
			'ai_model'                => 'claude-opus-5',
			'teacher_share_default'   => 50,
			'settle_membership_cents' => 1500,
			'owner_name'              => 'Olivia',
			'online_price_cents'      => 600,
			'online_only'             => 0,
			'online_per_credit'       => 4,
			'zoom_account_id'         => '',
			'zoom_client_id'          => '',
			'zoom_client_secret'      => '',
			'zoom_host'               => 'me',
			'zoom_auto'               => 1,
			'zoom_personal'           => 0,
			'zoom_waiting_room'       => 0,
			'livekit_url'             => '',
			'livekit_api_key'         => '',
			'livekit_api_secret'      => '',
			'live_on'                 => 1,
			'live_open_minutes'       => 10,
			'live_grid_max'           => 25,
			'live_keep_days'          => 30,
			'live_record'             => 'group',
			'live_replay_days'        => 7,
			'live_intro_minutes'      => 10,
			'live_summary'            => 'auto',
			'live_chat_days'          => 7,
			'email_from_name'         => 'Olivia Kovacs Yoga',
			'email_from'              => get_option( 'admin_email' ),
			'notify_email'            => get_option( 'admin_email' ),
			'email_reply_to'          => '',
			'smtp_host'               => '',
			'smtp_port'               => 587,
			'smtp_secure'             => 'tls',
			'smtp_user'               => '',
			'smtp_pass'               => '',
			'waiver_version'          => '2026-09',
			'waiver_text'             => "I understand that yoga includes physical movement and that there is a risk of injury. I confirm I am in good health or have my doctor's approval to practice, and I will tell Olivia about injuries, pregnancy or health conditions before class. I will listen to my body, rest whenever I need to, and take responsibility for my own practice. I release Olivia Kovacs Yoga from liability for injury, except in cases of gross negligence.",
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

	/**
	 * The cancellation rule as guests read it (booking form, account, private request, emails). A theme can add to it
	 * (filter oys_cancel_policy): Olívia, 19 November – „Mindenhova … írjuk oda a lemondáshoz”, that a paid but missed
	 * group class can still be watched again.
	 */
	public static function cancel_policy() {
		$text = trim( (string) self::get( 'cancel_policy' ) );
		return '' === $text ? '' : trim( (string) apply_filters( 'oys_cancel_policy', $text ) );
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

	/** Signing secret of the Connect webhook endpoint (events from the teachers' Stripe accounts). */
	public static function connect_webhook_secret() {
		if ( defined( 'OYS_STRIPE_CONNECT_WEBHOOK_SECRET' ) ) {
			return OYS_STRIPE_CONNECT_WEBHOOK_SECRET;
		}
		return self::is_live() ? self::get( 'stripe_live_connect_webhook' ) : self::get( 'stripe_test_connect_webhook' );
	}

	public static function payments_ready() {
		return 'hpv' === self::pay_provider() ? OYS_HPV_Pay::ready() : (bool) self::stripe_secret();
	}

	/**
	 * Who takes the card payments: „stripe” – the studio's own Stripe account (the keys above); „hpv” – Arovia Group LLC's
	 * Stripe through the HelloProVision CRM, booked in its QuickBooks (class-hpv-pay.php). OYS_PAY_PROVIDER wins.
	 */
	public static function pay_provider() {
		$p = defined( 'OYS_PAY_PROVIDER' ) ? (string) OYS_PAY_PROVIDER : (string) self::get( 'pay_provider' );
		return 'hpv' === $p ? 'hpv' : 'stripe';
	}
}
