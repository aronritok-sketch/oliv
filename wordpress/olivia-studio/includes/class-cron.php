<?php
/**
 * Background jobs (WP-Cron). On a live site, trigger WP-Cron from a real cron job
 * every 5 minutes so holds and reminders run on time even without visitors:
 *   define( 'DISABLE_WP_CRON', true ); and  wget -q -O - https://example.com/wp-cron.php
 */

defined( 'ABSPATH' ) || exit;

class OYS_Cron {

	public static function init() {
		add_filter( 'cron_schedules', function ( $s ) {
			$s['oys_5min'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes' );
			return $s;
		} );
		add_action( 'oys_frequent', array( __CLASS__, 'frequent' ) );
		add_action( 'oys_hourly', array( __CLASS__, 'hourly' ) );
		if ( ! wp_next_scheduled( 'oys_frequent' ) || ! wp_next_scheduled( 'oys_hourly' ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'oys_frequent' ) ) {
			wp_schedule_event( time() + 60, 'oys_5min', 'oys_frequent' );
		}
		if ( ! wp_next_scheduled( 'oys_hourly' ) ) {
			wp_schedule_event( time() + 120, 'hourly', 'oys_hourly' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'oys_frequent' );
		wp_clear_scheduled_hook( 'oys_hourly' );
	}

	public static function frequent() {
		OYS_Bookings::expire_holds();
		self::send_reminders();
		self::send_join_reminders();
	}

	public static function hourly() {
		OYS_Schedule::generate();
		self::send_pass_expiry();
	}

	/**
	 * Class reminders to the people who booked (not guests), at `reminder_hours` and optionally a
	 * second time at `reminder2_hours` before the class. Bookings made after a reminder's time
	 * don't get that reminder: they just had their confirmation.
	 */
	public static function send_reminders() {
		global $wpdb;
		$b    = OYS_Install::table( 'bookings' );
		$s    = OYS_Install::table( 'sessions' );
		$sent = 0;
		foreach ( array( 'reminder_sent' => 'reminder_hours', 'reminder2_sent' => 'reminder2_hours' ) as $flag => $setting ) {
			$hours = (int) OYS_Settings::get( $setting );
			if ( $hours < 1 || ! OYS_Email_Templates::enabled( 'reminder' ) ) {
				continue;
			}
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT b.id FROM $b b JOIN $s s ON s.id = b.session_id
				 WHERE b.status = 'confirmed' AND b.guest_of = 0 AND b.$flag = 0 AND s.status = 'scheduled'
				 AND s.starts_at > %s AND s.starts_at <= %s AND b.created_at < DATE_SUB(s.starts_at, INTERVAL %d HOUR)",
				oys_now(), oys_utc_plus( $hours * HOUR_IN_SECONDS ), $hours
			) );
			foreach ( $ids as $id ) {
				$wpdb->update( $b, array( $flag => 1 ), array( 'id' => $id ) );
				// The later reminder makes the earlier one pointless if both are due at once.
				if ( 'reminder_sent' === $flag ) {
					$wpdb->update( $b, array( 'reminder2_sent' => (int) OYS_Settings::get( 'reminder2_hours' ) >= $hours ? 1 : 0 ), array( 'id' => $id, 'reminder2_sent' => 0 ) );
				}
				OYS_Emails::reminder( $id );
				$sent++;
			}
		}
		return $sent;
	}

	/** Everyone joining online (people who booked and guests with an email) gets the link shortly before the class. */
	public static function send_join_reminders() {
		global $wpdb;
		$minutes = (int) OYS_Settings::get( 'join_reminder_minutes' );
		if ( $minutes < 1 || ! OYS_Email_Templates::enabled( 'join_reminder' ) ) {
			return 0;
		}
		$b   = OYS_Install::table( 'bookings' );
		$s   = OYS_Install::table( 'sessions' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT b.id FROM $b b JOIN $s s ON s.id = b.session_id
			 WHERE b.status = 'confirmed' AND b.mode = 'online' AND b.join_reminder_sent = 0 AND ( b.guest_of = 0 OR b.guest_email <> '' )
			 AND s.status = 'scheduled' AND s.starts_at > %s AND s.starts_at <= %s",
			oys_now(), oys_utc_plus( $minutes * MINUTE_IN_SECONDS )
		) );
		foreach ( $ids as $id ) {
			$wpdb->update( $b, array( 'join_reminder_sent' => 1 ), array( 'id' => $id ) );
			OYS_Emails::join_reminder( $id );
		}
		return count( $ids );
	}

	/** Passes with unused classes that expire within `pass_expiry_days` (once per pass). */
	public static function send_pass_expiry() {
		global $wpdb;
		$days = (int) OYS_Settings::get( 'pass_expiry_days' );
		if ( $days < 1 || ! OYS_Email_Templates::enabled( 'pass_expiring' ) ) {
			return 0;
		}
		$t   = OYS_Install::table( 'passes' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM $t WHERE credits_left > 0 AND expiry_notice_sent = 0 AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND created_at < %s",
			oys_now(), oys_utc_plus( $days * DAY_IN_SECONDS ), oys_utc_plus( - DAY_IN_SECONDS )
		) );
		foreach ( $ids as $id ) {
			$wpdb->update( $t, array( 'expiry_notice_sent' => 1 ), array( 'id' => $id ) );
			OYS_Emails::pass_expiring( $id );
		}
		return count( $ids );
	}
}
