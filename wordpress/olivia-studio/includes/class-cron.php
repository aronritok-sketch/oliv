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
	}

	public static function hourly() {
		OYS_Schedule::generate();
	}

	public static function send_reminders() {
		global $wpdb;
		$b     = OYS_Install::table( 'bookings' );
		$s     = OYS_Install::table( 'sessions' );
		$hours = (int) OYS_Settings::get( 'reminder_hours' );
		if ( $hours < 1 ) {
			return 0;
		}
		// Bookings made after the reminder window opened don't need a reminder; they just got a confirmation.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT b.id FROM $b b JOIN $s s ON s.id = b.session_id
			 WHERE b.status = 'confirmed' AND b.reminder_sent = 0 AND s.status = 'scheduled'
			 AND s.starts_at > %s AND s.starts_at <= %s AND b.created_at < DATE_SUB(s.starts_at, INTERVAL %d HOUR)",
			oys_now(), oys_utc_plus( $hours * HOUR_IN_SECONDS ), $hours
		) );
		foreach ( $ids as $id ) {
			$wpdb->update( $b, array( 'reminder_sent' => 1 ), array( 'id' => $id ) );
			OYS_Emails::reminder( $id );
		}
		return count( $ids );
	}
}
