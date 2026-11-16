<?php
/**
 * The Hub's classes: the weekly calendar, the attendance sheet after a class and the management reports – the best of
 * what the studio used before (Motibro: „online jelenléti ív, egy érintéssel”, „Foglalt, de nem jött el”, „havi
 * jelenlét/bevétel kimutatások”, „toplisták”, „lemorzsolódó vendégek”, „születésnap jelző”, „Excel export”), on the
 * phone, from one screen (Áron, 16 November: „a hubból tudja kezelni óra után nagyon egyszerűen … ki jött, ki nem jött
 * el, minden vezető motibro funkció legyen benne”, „heti nézetes naptár is legyen”).
 *
 * - Week: Monday–Sunday, every class of the studio (a teacher: their own), how full it is and how far the attendance
 *   sheet is filled in.
 * - Attendance sheet: everyone booked, one tap for „Itt volt” / „Nem jött el”, „Mindenki itt volt”, how they paid,
 *   what is left on their pass, whether they were in the live room, the payment at the studio („Fizetett”).
 * - The live room remembers who came in (oys_live_joined_<session>); some hours after the class the sheet fills itself
 *   in (setting auto_attendance_hours, 0 = off): who was in the room attended, who wasn't did not come; a class without
 *   the live room: everyone booked attended. Whatever the studio marked stays as it is.
 * - Reports: a month's bookings, attendance, no-shows, revenue, passes sold, new customers, the regulars, the ones
 *   drifting away, today's birthdays, and a CSV export (opens in Excel).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Hub_Classes {

	const JOINED = 'oys_live_joined_'; // option per session: booking ids that came into the live room

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'oys_live_joined', array( __CLASS__, 'on_join' ), 10, 2 );
		add_action( 'oys_hourly', array( __CLASS__, 'auto_attendance' ) );
		add_action( 'admin_post_oys_hub_export', array( __CLASS__, 'export' ) );
	}

	/* ---------- The live room: who came in ---------- */

	public static function on_join( $session, $who ) {
		if ( ! empty( $who['owner'] ) || ! empty( $who['test'] ) || empty( $who['booking'] ) ) {
			return;
		}
		$ids = self::joined( $session->id );
		if ( ! in_array( (int) $who['booking']->id, $ids, true ) ) {
			$ids[] = (int) $who['booking']->id;
			update_option( self::JOINED . (int) $session->id, $ids, false );
		}
	}

	/** @return int[] booking ids */
	public static function joined( $session_id ) {
		return array_map( 'intval', (array) get_option( self::JOINED . (int) $session_id, array() ) );
	}

	/* ---------- The week ---------- */

	/** Monday of the week that holds $date (Y-m-d, studio time). */
	public static function monday( $date = '' ) {
		$tz = wp_timezone();
		$d  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? new DateTimeImmutable( $date, $tz ) : new DateTimeImmutable( 'today', $tz );
		return $d->modify( '-' . ( (int) $d->format( 'N' ) - 1 ) . ' days' );
	}

	public static function week( $date = '' ) {
		$mon   = self::monday( $date );
		$utc   = new DateTimeZone( 'UTC' );
		$list  = OYS_Schedule::query( array( 'from' => $mon->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'to' => $mon->modify( '+7 days' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) ) );
		$list  = array_filter( $list, fn( $s ) => OYS_Hub::can_session( $s ) && 'cancelled' !== $s->status );
		$days  = array();
		$today = wp_date( 'Y-m-d' );
		for ( $i = 0; $i < 7; $i++ ) {
			$d                  = $mon->modify( "+$i days" );
			$days[ $d->format( 'Y-m-d' ) ] = array( 'date' => $d->format( 'Y-m-d' ), 'name' => wp_date( 'l', $d->getTimestamp() ), 'day' => wp_date( _x( 'F j', 'week view day', 'olivia-studio' ), $d->getTimestamp() ), 'today' => $d->format( 'Y-m-d' ) === $today, 'classes' => array() );
		}
		foreach ( $list as $s ) {
			$key = wp_date( 'Y-m-d', oys_ts( $s->starts_at ) );
			if ( isset( $days[ $key ] ) ) {
				$days[ $key ]['classes'][] = self::card( $s );
			}
		}
		$sun = $mon->modify( '+6 days' );
		return array(
			'start' => $mon->format( 'Y-m-d' ),
			'prev'  => $mon->modify( '-7 days' )->format( 'Y-m-d' ),
			'next'  => $mon->modify( '+7 days' )->format( 'Y-m-d' ),
			'label' => wp_date( _x( 'M j', 'week view range', 'olivia-studio' ), $mon->getTimestamp() ) . ' – ' . wp_date( _x( 'M j', 'week view range', 'olivia-studio' ), $sun->getTimestamp() ),
			'this'  => $mon->format( 'Y-m-d' ) === self::monday()->format( 'Y-m-d' ),
			'days'  => array_values( $days ),
		);
	}

	/** One class in the week: when, how full, how far the sheet is. */
	public static function card( $s ) {
		$tally = self::tally( $s->id );
		$over  = oys_ts( $s->ends_at ) < time();
		return array(
			'id'       => (int) $s->id,
			'title'    => oys_session_title( $s ),
			'time'     => oys_time( $s->starts_at ) . '–' . oys_time( $s->ends_at ),
			'booked'   => (int) $s->booked + (int) $s->online_booked,
			'capacity' => oys_is_online( $s ) ? (int) $s->capacity : (int) $s->capacity + (int) $s->online_capacity,
			'kind'     => (string) $s->kind,
			'over'     => $over,
			'now'      => time() >= oys_ts( $s->starts_at ) - 15 * MINUTE_IN_SECONDS && time() <= oys_ts( $s->ends_at ),
			'here'     => $tally['attended'],
			'missed'   => $tally['no_show'],
			'open'     => $over ? $tally['confirmed'] : 0, // still to mark
		);
	}

	private static function tally( $session_id ) {
		global $wpdb;
		$out = array( 'confirmed' => 0, 'attended' => 0, 'no_show' => 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . OYS_Install::table( 'bookings' ) . " WHERE session_id = %d AND status IN ('confirmed','attended','no_show') GROUP BY status", $session_id ) ) as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/** Classes over in the last two days with someone not marked yet: the „after class” to-dos on Today. */
	public static function to_mark() {
		$list = OYS_Schedule::query( array( 'from' => oys_utc_plus( -2 * DAY_IN_SECONDS ), 'to' => oys_now(), 'status' => 'scheduled' ) );
		$out  = array();
		foreach ( $list as $s ) {
			if ( oys_ts( $s->ends_at ) <= time() && OYS_Hub::can_session( $s ) ) {
				$c = self::card( $s );
				if ( $c['open'] ) {
					$c['day'] = oys_date( $s->starts_at, 'D M j' );
					$out[]    = $c;
				}
			}
		}
		return $out;
	}

	/* ---------- The attendance sheet ---------- */

	public static function sheet( $s ) {
		$joined = self::joined( $s->id );
		$live   = oys_has_online( $s ) && OYS_Live::uses_live( $s );
		$labels = OYS_Bookings::paid_with_labels();
		$people = array();
		foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed', 'attended', 'no_show' ) ) as $b ) {
			$pass = '';
			if ( 'credit' === $b->paid_with && ! $b->guest_of ) {
				$left = OYS_Passes::balance( (int) $b->user_id, OYS_Bookings::credit_kind( $s, $b->mode ) );
				$pass = sprintf( _n( '%d session left on the pass', '%d sessions left on the pass', $left, 'olivia-studio' ), $left );
			}
			$due = 'door' === $b->paid_with && ! $b->collected_with ? (int) $b->due_cents : 0;
			$people[] = array(
				'booking' => (int) $b->id,
				'user'    => $b->guest_of ? 0 : (int) $b->user_id,
				'name'    => OYS_Bookings::person_label( $b ),
				'status'  => (string) $b->status,
				'paid'    => $labels[ $b->paid_with ] ?? (string) $b->paid_with,
				'pass'    => $pass,
				'due'     => $due ? oys_money( $due ) : '',
				'joined'  => $live ? in_array( (int) $b->id, $joined, true ) : null,
				'health'  => $b->guest_of ? '' : (string) get_user_meta( (int) $b->user_id, 'oys_health_notes', true ),
			);
		}
		$tally = self::tally( $s->id );
		return array(
			'id'      => (int) $s->id,
			'title'   => oys_session_title( $s ),
			'when'    => oys_date( $s->starts_at, 'l, F j' ) . ' · ' . oys_time( $s->starts_at ) . '–' . oys_time( $s->ends_at ),
			'over'    => oys_ts( $s->ends_at ) < time(),
			'started' => oys_ts( $s->starts_at ) - 15 * MINUTE_IN_SECONDS <= time(),
			'live'    => $live ? OYS_Live::url( $s->id ) : '',
			'roster'  => admin_url( 'admin.php?page=' . ( OYS_Hub::can_manage() ? 'oys-schedule' : 'oys-teach' ) . '&session=' . (int) $s->id ),
			'people'  => $people,
			'tally'   => $tally,
			'waitlist' => count( OYS_Bookings::waitlist( $s->id ) ),
			'auto'    => (int) OYS_Settings::get( 'auto_attendance_hours' ),
		);
	}

	/** Mark one booking (or a guest) of the class. */
	public static function mark( $s, $booking_id, $status ) {
		$b = OYS_Bookings::get( (int) $booking_id );
		if ( ! $b || (int) $b->session_id !== (int) $s->id || ! in_array( $status, array( 'attended', 'no_show', 'confirmed' ), true ) ) {
			return new WP_Error( 'oys_hub', __( 'This booking is not in this class.', 'olivia-studio' ) );
		}
		OYS_Bookings::set_attendance( $b->id, $status );
		return true;
	}

	/** Everyone not marked yet → $status. @return int how many */
	public static function mark_rest( $s, $status = 'attended' ) {
		$n = 0;
		foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed' ) ) as $b ) {
			OYS_Bookings::set_attendance( $b->id, $status );
			$n++;
		}
		return $n;
	}

	/**
	 * Some hours after the class the sheet fills itself in: who came into the live room attended, who didn't did not
	 * come; a class without the live room: everyone still booked attended. Hourly.
	 */
	public static function auto_attendance() {
		$hours = (int) OYS_Settings::get( 'auto_attendance_hours' );
		if ( $hours < 1 ) {
			return;
		}
		global $wpdb;
		$b   = OYS_Install::table( 'bookings' );
		$t   = OYS_Install::table( 'sessions' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT s.id FROM $t s JOIN $b b ON b.session_id = s.id WHERE b.status = 'confirmed' AND s.status = 'scheduled' AND s.ends_at <= %s AND s.ends_at > %s", oys_utc_plus( -$hours * HOUR_IN_SECONDS ), oys_utc_plus( -14 * DAY_IN_SECONDS ) ) );
		foreach ( $ids as $id ) {
			$s = OYS_Schedule::get( (int) $id );
			if ( ! $s ) {
				continue;
			}
			$live   = oys_has_online( $s ) && OYS_Live::uses_live( $s );
			$joined = self::joined( $s->id );
			foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed' ) ) as $bk ) {
				$in = ! $live || in_array( (int) $bk->id, $joined, true ) || ( $bk->guest_of && in_array( (int) $bk->guest_of, $joined, true ) );
				OYS_Bookings::set_attendance( $bk->id, $in ? 'attended' : 'no_show' );
			}
		}
		// the room's list is not needed after a month
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200", $wpdb->esc_like( self::JOINED ) . '%' ) ) as $name ) {
			$s = OYS_Schedule::get( (int) substr( $name, strlen( self::JOINED ) ) );
			if ( ! $s || oys_ts( $s->ends_at ) < time() - 30 * DAY_IN_SECONDS ) {
				delete_option( $name );
			}
		}
	}

	/* ---------- Reports ---------- */

	/** @return array a month's numbers (studio only) */
	public static function report( $month = '' ) {
		global $wpdb;
		$tz    = wp_timezone();
		$first = preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ? new DateTimeImmutable( $month . '-01', $tz ) : new DateTimeImmutable( 'first day of this month 00:00', $tz );
		$next  = $first->modify( '+1 month' );
		$utc   = new DateTimeZone( 'UTC' );
		$from  = $first->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$to    = $next->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$b     = OYS_Install::table( 'bookings' );
		$s     = OYS_Install::table( 'sessions' );
		$o     = OYS_Install::table( 'orders' );
		$st    = array( 'confirmed' => 0, 'attended' => 0, 'no_show' => 0, 'cancelled' => 0, 'late_cancelled' => 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT b.status, COUNT(*) n FROM $b b JOIN $s s ON s.id = b.session_id WHERE s.starts_at >= %s AND s.starts_at < %s GROUP BY b.status", $from, $to ) ) as $r ) {
			$st[ $r->status ] = (int) $r->n;
		}
		$classes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $s WHERE status = 'scheduled' AND starts_at >= %s AND starts_at < %s", $from, $to ) );
		$people  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT b.user_id) FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.status = 'attended' AND b.guest_of = 0 AND s.starts_at >= %s AND s.starts_at < %s", $from, $to ) );
		$rev     = 0;
		$sold    = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT type, amount_cents, meta FROM $o WHERE status IN ('paid','partially_refunded') AND paid_at >= %s AND paid_at < %s", $from, $to ) ) as $r ) {
			$meta = json_decode( (string) $r->meta, true ) ?: array();
			$rev += empty( $meta['stripe_account'] ) ? (int) $r->amount_cents : (int) ( $meta['app_fee_cents'] ?? 0 );
			$sold += 'pass' === $r->type || 'pack' === $r->type ? 1 : 0;
		}
		$granted = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'passes' ) . ' WHERE created_at >= %s AND created_at < %s', $from, $to ) );
		$new     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s AND m.meta_value LIKE %s WHERE u.user_registered >= %s AND u.user_registered < %s", $wpdb->get_blog_prefix() . 'capabilities', '%oys_customer%', $from, $to ) );
		$marked  = $st['attended'] + $st['no_show'];
		$classes_rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.class_slug, s.title, s.kind, SUM(b.status = 'attended') here, SUM(b.status IN ('confirmed','attended','no_show')) booked FROM $s s LEFT JOIN $b b ON b.session_id = s.id WHERE s.status = 'scheduled' AND s.starts_at >= %s AND s.starts_at < %s GROUP BY s.id", $from, $to ) ) as $r ) {
			$sess = OYS_Schedule::get( (int) $r->id );
			$name = $sess ? oys_session_title( $sess ) : (string) $r->class_slug;
			$classes_rows[ $name ] = $classes_rows[ $name ] ?? array( 'name' => $name, 'classes' => 0, 'booked' => 0, 'here' => 0 );
			$classes_rows[ $name ]['classes']++;
			$classes_rows[ $name ]['booked'] += (int) $r->booked;
			$classes_rows[ $name ]['here']   += (int) $r->here;
		}
		$by_class = array_values( array_map( fn( $c ) => $c + array( 'avg' => number_format_i18n( $c['classes'] ? $c['booked'] / $c['classes'] : 0, 1 ) ), $classes_rows ) );
		usort( $by_class, fn( $a, $z ) => $z['booked'] <=> $a['booked'] );
		return array(
			'month'    => $first->format( 'Y-m' ),
			'label'    => wp_date( 'F Y', $first->getTimestamp() ),
			'prev'     => $first->modify( '-1 month' )->format( 'Y-m' ),
			'next'     => $next->format( 'Y-m' ),
			'isNow'    => $first->format( 'Y-m' ) === wp_date( 'Y-m' ),
			'kpis'     => array(
				array( __( 'Classes', 'olivia-studio' ), (string) $classes ),
				array( __( 'Bookings', 'olivia-studio' ), (string) ( $st['confirmed'] + $st['attended'] + $st['no_show'] ) ),
				array( __( 'Came', 'olivia-studio' ), (string) $st['attended'] ),
				array( __( 'Booked but did not come', 'olivia-studio' ), (string) $st['no_show'] ),
				array( __( 'Attendance', 'olivia-studio' ), $marked ? round( 100 * $st['attended'] / $marked ) . '%' : '–' ),
				array( __( 'Different people', 'olivia-studio' ), (string) $people ),
				array( __( 'Cancelled in time', 'olivia-studio' ), (string) $st['cancelled'] ),
				array( __( 'Revenue', 'olivia-studio' ), oys_money( $rev ) ),
				array( __( 'Passes sold or given', 'olivia-studio' ), (string) max( $sold, $granted ) ),
				array( __( 'New customers', 'olivia-studio' ), (string) $new ),
			),
			'classes'  => $by_class,
			'regulars' => self::regulars(),
			'drifting' => self::drifting(),
			'birthdays' => self::birthdays(),
			'export'   => wp_nonce_url( admin_url( 'admin-post.php?action=oys_hub_export&month=' . $first->format( 'Y-m' ) ), 'oys_hub_export' ),
		);
	}

	private static function person( $user_id, $extra ) {
		$u = get_userdata( (int) $user_id );
		return $u ? array( 'user' => (int) $user_id, 'name' => trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name, 'note' => $extra ) : null;
	}

	/** The ten who came most in the last 90 days. */
	public static function regulars() {
		global $wpdb;
		$b    = OYS_Install::table( 'bookings' );
		$s    = OYS_Install::table( 'sessions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.user_id, COUNT(*) n FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.status = 'attended' AND b.guest_of = 0 AND s.starts_at >= %s GROUP BY b.user_id ORDER BY n DESC LIMIT 10", oys_utc_plus( -90 * DAY_IN_SECONDS ) ) );
		return array_values( array_filter( array_map( fn( $r ) => self::person( $r->user_id, sprintf( _n( '%d class in 90 days', '%d classes in 90 days', (int) $r->n, 'olivia-studio' ), (int) $r->n ) ), $rows ) ) );
	}

	/** Came at least twice in the 90 days before, nothing in the last 30 days and nothing booked: worth a message. */
	public static function drifting() {
		global $wpdb;
		$b    = OYS_Install::table( 'bookings' );
		$s    = OYS_Install::table( 'sessions' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.user_id, MAX(s.starts_at) last FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.status = 'attended' AND b.guest_of = 0 AND s.starts_at >= %s GROUP BY b.user_id HAVING COUNT(*) >= 2 AND MAX(s.starts_at) < %s ORDER BY last DESC LIMIT 30",
			oys_utc_plus( -120 * DAY_IN_SECONDS ),
			oys_utc_plus( -30 * DAY_IN_SECONDS )
		) );
		$out = array();
		foreach ( $rows as $r ) {
			if ( OYS_Bookings::for_user( (int) $r->user_id, 'upcoming', array( 'confirmed' ) ) ) {
				continue;
			}
			$p = self::person( $r->user_id, sprintf( __( 'last class: %s', 'olivia-studio' ), oys_date( $r->last, 'M j' ) ) );
			if ( $p ) {
				$out[] = $p;
			}
		}
		return array_slice( $out, 0, 15 );
	}

	/** Customers whose birthday is today (they set it in their account). */
	public static function birthdays() {
		$ids = get_users( array( 'fields' => 'ID', 'number' => 50, 'meta_key' => 'oys_birthday', 'meta_value' => wp_date( 'm-d' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return array_values( array_filter( array_map( fn( $id ) => self::person( $id, __( 'birthday today 🎂', 'olivia-studio' ) ), $ids ) ) );
	}

	/** A month's bookings as CSV (opens in Excel). */
	public static function export() {
		if ( ! current_user_can( 'oys_manage' ) || ! check_admin_referer( 'oys_hub_export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'olivia-studio' ) );
		}
		global $wpdb;
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) ( $_GET['month'] ?? '' ) ) ? $_GET['month'] : wp_date( 'Y-m' ); // phpcs:ignore WordPress.Security
		$tz    = wp_timezone();
		$utc   = new DateTimeZone( 'UTC' );
		$first = new DateTimeImmutable( $month . '-01', $tz );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			'SELECT b.*, s.starts_at FROM ' . OYS_Install::table( 'bookings' ) . ' b JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = b.session_id WHERE s.starts_at >= %s AND s.starts_at < %s AND b.status IN ('confirmed','attended','no_show','cancelled','late_cancelled') ORDER BY s.starts_at, b.id",
			$first->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$first->modify( '+1 month' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		) );
		$labels = OYS_Bookings::paid_with_labels();
		$states = array( 'confirmed' => __( 'Booked', 'olivia-studio' ), 'attended' => __( 'Came', 'olivia-studio' ), 'no_show' => __( 'Booked but did not come', 'olivia-studio' ), 'cancelled' => __( 'Cancelled', 'olivia-studio' ), 'late_cancelled' => __( 'Cancelled late', 'olivia-studio' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="studio-' . $month . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel reads the accents right
		fputcsv( $out, array( __( 'Date', 'olivia-studio' ), __( 'Time', 'olivia-studio' ), __( 'Class', 'olivia-studio' ), __( 'Name', 'olivia-studio' ), __( 'Email', 'olivia-studio' ), __( 'Paid with', 'olivia-studio' ), __( 'Status', 'olivia-studio' ) ), ';' );
		foreach ( $rows as $r ) {
			$s = OYS_Schedule::get( (int) $r->session_id );
			$u = $r->guest_of ? null : get_userdata( (int) $r->user_id );
			fputcsv( $out, array( oys_date( $r->starts_at, 'Y-m-d' ), oys_time( $r->starts_at ), $s ? oys_session_title( $s ) : '', OYS_Bookings::person_label( $r ), $u ? $u->user_email : (string) $r->guest_email, $labels[ $r->paid_with ] ?? $r->paid_with, $states[ $r->status ] ?? $r->status ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------- REST (oys/v1/hub/…) ---------- */

	public static function routes() {
		$can    = fn() => OYS_Hub::can();
		$manage = fn() => current_user_can( 'oys_manage' );
		$ns     = OYS_Hub::NS;
		register_rest_route( $ns, '/hub/week', array( 'methods' => 'GET', 'permission_callback' => $can, 'callback' => fn( WP_REST_Request $r ) => self::week( (string) $r->get_param( 'start' ) ) ) );
		register_rest_route( $ns, '/hub/class/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => $can, 'callback' => array( __CLASS__, 'rest_sheet' ) ) );
		register_rest_route( $ns, '/hub/class/(?P<id>\d+)/mark', array( 'methods' => 'POST', 'permission_callback' => $can, 'callback' => array( __CLASS__, 'rest_mark' ) ) );
		register_rest_route( $ns, '/hub/class/(?P<id>\d+)/collect', array( 'methods' => 'POST', 'permission_callback' => $can, 'callback' => array( __CLASS__, 'rest_collect' ) ) );
		register_rest_route( $ns, '/hub/reports', array( 'methods' => 'GET', 'permission_callback' => $manage, 'callback' => fn( WP_REST_Request $r ) => self::report( (string) $r->get_param( 'month' ) ) ) );
	}

	private static function session_req( WP_REST_Request $req ) {
		$s = OYS_Schedule::get( (int) $req['id'] );
		return $s && OYS_Hub::can_session( $s ) ? $s : new WP_Error( 'oys_hub', __( 'This class is not yours.', 'olivia-studio' ), array( 'status' => 404 ) );
	}

	public static function rest_sheet( WP_REST_Request $req ) {
		$s = self::session_req( $req );
		return is_wp_error( $s ) ? $s : self::sheet( $s );
	}

	public static function rest_mark( WP_REST_Request $req ) {
		$s = self::session_req( $req );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		if ( $req->get_param( 'all' ) ) {
			self::mark_rest( $s, 'no_show' === $status ? 'no_show' : 'attended' );
		} else {
			$res = self::mark( $s, (int) $req->get_param( 'booking' ), $status );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return self::sheet( OYS_Schedule::get( $s->id ) );
	}

	public static function rest_collect( WP_REST_Request $req ) {
		$s = self::session_req( $req );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$b = OYS_Bookings::get( (int) $req->get_param( 'booking' ) );
		if ( ! $b || (int) $b->session_id !== (int) $s->id || ! OYS_Bookings::collect( $b->id, sanitize_key( (string) $req->get_param( 'with' ) ) ) ) {
			return new WP_Error( 'oys_hub', __( 'This booking has nothing to pay at the studio.', 'olivia-studio' ), array( 'status' => 400 ) );
		}
		return self::sheet( $s );
	}
}

OYS_Hub_Classes::init();
