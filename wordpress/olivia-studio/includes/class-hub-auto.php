<?php
/**
 * The studio's automations, after Motibro's („Automatizációk”: lezáratlan jelenléti ívek értesítő, napi összefoglaló a
 * klubvezetőnek, bejelentkezésről / kijelentkezésről / várólistáról értesítő az oktatónak, emlékeztető lemorzsolódó
 * vendégeknek, hétvégi heti kedvcsináló) – Áron, 16 November: „mehet a kutatás, dolgozz tovább”.
 *
 * On the teacher's phone (push, OYS_Studio_App; each can be switched off in Hub → My settings):
 * - a booking, a cancellation, someone on the waitlist (push_bookings);
 * - the class is over: „who came?” – opens the attendance sheet (push_sheet), 10–70 minutes after the end, once;
 * - every morning around 7:30 the day: classes, who's booked, what is waiting, yesterday's attendance (push_daily).
 * To the customers (emails, editable and switched off by default in Studio → Emails):
 * - we_miss_you: came regularly, not in the last 30 days, nothing booked – once in 60 days;
 * - weekly_digest: on Saturday, next week's classes, to the newsletter subscribers only.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Hub_Auto {

	public static function init() {
		add_action( 'oys_booking_confirmed', array( __CLASS__, 'on_booking' ) );
		add_action( 'oys_booking_cancelled', array( __CLASS__, 'on_cancel' ), 10, 2 );
		add_action( 'oys_waitlist_joined', array( __CLASS__, 'on_waitlist' ), 10, 2 );
		add_action( 'oys_frequent', array( __CLASS__, 'tick' ) );
		add_action( 'oys_hourly', array( __CLASS__, 'retention' ) );
		add_filter( 'oys_email_templates', array( __CLASS__, 'templates' ) );
	}

	/* ---------- who hears about what ---------- */

	/** Users with a device switched on who see this class and want this kind of push. */
	private static function listeners( $session, $pref ) {
		$out = array();
		foreach ( OYS_Studio_App::subscribers() as $uid ) {
			if ( OYS_Hub::can( $uid ) && ( ! $session || OYS_Hub::can_session( $session, $uid ) ) && OYS_Hub::prefs( $uid )[ $pref ] ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	private static function when( $s ) {
		return oys_session_title( $s ) . ' · ' . oys_date( $s->starts_at, 'D M j' ) . ' ' . oys_time( $s->starts_at );
	}

	/** Someone booked (not the studio itself, not a guest row). */
	public static function on_booking( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		if ( ! $b || $b->guest_of || ! OYS_Studio_App::subs() || OYS_Hub::can() ) {
			return;
		}
		$s = OYS_Schedule::get( $b->session_id );
		if ( ! $s ) {
			return;
		}
		$n = (int) $s->booked + (int) $s->online_booked;
		foreach ( self::listeners( $s, 'push_bookings' ) as $uid ) {
			OYS_Studio_App::notify( $uid, sprintf( __( 'New booking: %s', 'olivia-studio' ), OYS_Bookings::person_label( $b ) ), self::when( $s ) . ' · ' . sprintf( __( '%d booked', 'olivia-studio' ), $n ), OYS_Studio_App::url( 's' . (int) $s->id ), 'b' . (int) $s->id );
		}
	}

	public static function on_cancel( $booking_id, $outcome = '' ) {
		$b = OYS_Bookings::get( $booking_id );
		if ( ! $b || $b->guest_of || ! OYS_Studio_App::subs() || OYS_Hub::can() ) {
			return;
		}
		$s = OYS_Schedule::get( $b->session_id );
		if ( ! $s || 'scheduled' !== $s->status ) {
			return;
		}
		foreach ( self::listeners( $s, 'push_bookings' ) as $uid ) {
			OYS_Studio_App::notify( $uid, sprintf( __( 'Cancelled: %s', 'olivia-studio' ), OYS_Bookings::person_label( $b ) ), self::when( $s ), OYS_Studio_App::url( 's' . (int) $s->id ), 'b' . (int) $s->id );
		}
	}

	public static function on_waitlist( $user_id, $session_id ) {
		$s = OYS_Schedule::get( (int) $session_id );
		$u = get_userdata( (int) $user_id );
		if ( ! $s || ! $u || ! OYS_Studio_App::subs() || OYS_Hub::can() ) {
			return;
		}
		$name = trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name;
		foreach ( self::listeners( $s, 'push_bookings' ) as $uid ) {
			OYS_Studio_App::notify( $uid, sprintf( __( 'On the waitlist: %s', 'olivia-studio' ), $name ), self::when( $s ), OYS_Studio_App::url( 's' . (int) $s->id ), 'w' . (int) $s->id );
		}
	}

	/* ---------- every 5 minutes: „who came?” after a class, the morning summary ---------- */

	public static function tick() {
		if ( ! OYS_Studio_App::subs() ) {
			return;
		}
		self::sheet_reminders();
		self::morning();
	}

	/** 10–70 minutes after a class with people still to mark: once, „who came?” → the attendance sheet. */
	public static function sheet_reminders() {
		foreach ( OYS_Schedule::query( array( 'from' => oys_utc_plus( -6 * HOUR_IN_SECONDS ), 'to' => oys_now(), 'status' => 'scheduled' ) ) as $s ) {
			$end = oys_ts( $s->ends_at );
			if ( $end > time() - 10 * MINUTE_IN_SECONDS || $end < time() - 70 * MINUTE_IN_SECONDS || get_option( 'oys_sheet_pushed_' . (int) $s->id ) ) {
				continue;
			}
			$open = count( OYS_Bookings::for_session( $s->id, array( 'confirmed' ) ) );
			update_option( 'oys_sheet_pushed_' . (int) $s->id, time(), false );
			if ( ! $open ) {
				continue;
			}
			foreach ( self::listeners( $s, 'push_sheet' ) as $uid ) {
				OYS_Studio_App::notify( $uid, sprintf( __( 'Class over: %s', 'olivia-studio' ), oys_session_title( $s ) ), sprintf( _n( 'Who came? %d person to mark – one tap each.', 'Who came? %d people to mark – one tap each.', $open, 'olivia-studio' ), $open ), OYS_Studio_App::url( 's' . (int) $s->id ), 's' . (int) $s->id );
			}
		}
	}

	/** Around 7:30 in the morning (studio time), once a day: the day ahead and what is waiting. */
	public static function morning() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$hm  = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );
		if ( $hm < 7 * 60 + 30 || $hm > 10 * 60 || get_option( 'oys_daily_push' ) === $now->format( 'Y-m-d' ) ) {
			return;
		}
		update_option( 'oys_daily_push', $now->format( 'Y-m-d' ), false );
		self::morning_send();
	}

	/** @return int how many got it */
	public static function morning_send() {
		$n = 0;
		foreach ( self::listeners( null, 'push_daily' ) as $uid ) {
			$text = self::summary( $uid );
			if ( $text ) {
				$n += OYS_Studio_App::notify( $uid, sprintf( __( 'Good morning, %s!', 'olivia-studio' ), OYS_Hub::chat_name( $uid ) ), $text, OYS_Studio_App::url( 'today' ), 'daily' ) ? 1 : 0;
			}
		}
		return $n;
	}

	/** The day in a sentence or two; '' when there is nothing to say. */
	public static function summary( $user_id ) {
		$tz    = wp_timezone();
		$utc   = new DateTimeZone( 'UTC' );
		$day   = new DateTimeImmutable( 'today', $tz );
		$parts = array();
		$today = array_filter( OYS_Schedule::query( array( 'from' => $day->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'to' => $day->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'status' => 'scheduled' ) ), fn( $s ) => OYS_Hub::can_session( $s, $user_id ) );
		if ( $today ) {
			$parts[] = sprintf( _n( 'Today: %d class', 'Today: %d classes', count( $today ), 'olivia-studio' ), count( $today ) ) . ' – ' . implode( ', ', array_map( fn( $s ) => oys_time( $s->starts_at ) . ' ' . oys_session_title( $s ) . ' (' . ( (int) $s->booked + (int) $s->online_booked ) . ')', $today ) );
		}
		$prev = get_current_user_id();
		wp_set_current_user( $user_id ); // the Hub's counts are the current user's (a teacher sees their own)
		$c      = OYS_Hub::counts();
		$tomark = OYS_Hub_Classes::to_mark();
		wp_set_current_user( $prev );
		$wait = array_filter( array(
			$c['messages'] ? sprintf( _n( '%d message', '%d messages', $c['messages'], 'olivia-studio' ), $c['messages'] ) : '',
			$c['chats'] ? sprintf( _n( '%d class chat', '%d class chats', $c['chats'], 'olivia-studio' ), $c['chats'] ) : '',
			$c['requests'] ? sprintf( _n( '%d private request', '%d private requests', $c['requests'], 'olivia-studio' ), $c['requests'] ) : '',
		) );
		if ( $wait ) {
			$parts[] = sprintf( __( 'Waiting for you: %s', 'olivia-studio' ), implode( ', ', $wait ) );
		}
		if ( $tomark ) {
			$parts[] = sprintf( _n( '%d attendance sheet to fill in', '%d attendance sheets to fill in', count( $tomark ), 'olivia-studio' ), count( $tomark ) );
		}
		global $wpdb;
		$scope = OYS_Hub::scope( $user_id );
		$came  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'bookings' ) . ' b JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = b.session_id WHERE b.status = 'attended' AND s.starts_at >= %s AND s.starts_at < %s" . ( null === $scope ? '' : $wpdb->prepare( ' AND s.teacher_id = %d', $scope ) ), $day->modify( '-1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $day->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) ) );
		if ( $came ) {
			$parts[] = sprintf( _n( 'Yesterday %d person came', 'Yesterday %d people came', $came, 'olivia-studio' ), $came );
		}
		return implode( ' · ', $parts );
	}

	/* ---------- the customers: „we miss you”, the weekly classes ---------- */

	public static function retention() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		if ( (int) $now->format( 'G' ) >= 10 && (int) $now->format( 'G' ) < 19 && get_option( 'oys_retention_day' ) !== $now->format( 'Y-m-d' ) ) {
			update_option( 'oys_retention_day', $now->format( 'Y-m-d' ), false );
			self::retention_send( $now );
		}
	}

	/** Once a day (10:00–19:00): „we miss you” to those drifting away; on Saturday the coming week. */
	public static function retention_send( DateTimeImmutable $now ) {
		$sent = array( 'missed' => 0, 'weekly' => 0 );
		global $wpdb; // the „who came?” flags of classes over two weeks ago
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( 'oys_sheet_pushed_' ) . '%', time() - 14 * DAY_IN_SECONDS ) );
		if ( OYS_Email_Templates::enabled( 'we_miss_you' ) ) {
			foreach ( OYS_Hub_Classes::drifting() as $p ) {
				$last = (int) get_user_meta( $p['user'], 'oys_missed_sent', true );
				if ( $last < time() - 60 * DAY_IN_SECONDS ) {
					update_user_meta( $p['user'], 'oys_missed_sent', time() );
					$sent['missed'] += OYS_Emails::we_miss_you( $p['user'] ) ? 1 : 0;
				}
			}
		}
		if ( '6' === $now->format( 'N' ) && OYS_Email_Templates::enabled( 'weekly_digest' ) && get_option( 'oys_weekly_digest' ) !== $now->format( 'o-W' ) ) {
			update_option( 'oys_weekly_digest', $now->format( 'o-W' ), false );
			foreach ( OYS_Newsletter::subscribers() as $uid ) {
				$sent['weekly'] += OYS_Emails::weekly_digest( $uid ) ? 1 : 0;
			}
		}
		return $sent;
	}

	/** The two customer emails, editable in Studio → Emails (registry format of OYS_Email_Templates). */
	public static function templates( $all ) {
		$add = array(
			'we_miss_you'   => array( __( 'We miss you (drifting away)', 'olivia-studio' ), 'reminders', 'customer', __( 'To someone who came regularly but not in the last 30 days and has nothing booked; at most once in 60 days', 'olivia-studio' ),
				'We miss you, {first_name}', 'We miss you', "Hi {first_name}, I haven't seen you on the mat for a while. Your place is waiting whenever you're ready – here are the next classes.", 'Take care of yourself!', 'Book a class' ),
			'weekly_digest' => array( __( 'Next week\'s classes (weekend)', 'olivia-studio' ), 'reminders', 'customer', __( 'On Saturday, to the newsletter subscribers: the classes of the coming week', 'olivia-studio' ),
				'Next week on the mat', 'Next week', 'Hi {first_name}, here is what is coming up next week. Book your spot!', '', 'All classes' ),
		);
		foreach ( $add as $key => $d ) {
			$all[ $key ] = array( 'label' => $d[0], 'group' => $d[1], 'to' => $d[2], 'when' => $d[3], 'editable' => true, 'subject' => $d[4], 'heading' => $d[5], 'message' => $d[6], 'closing' => $d[7], 'button' => $d[8], 'vars' => OYS_Email_Templates::COMMON, 'off' => true );
		}
		return $all;
	}
}

OYS_Hub_Auto::init();
