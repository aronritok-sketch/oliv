<?php
/**
 * Bookings: reserve with a credit, hold a seat while a card payment is in progress,
 * confirm, cancel (with the cancellation policy), attendance, and the waitlist.
 *
 * Booking status: pending (payment hold) → confirmed → attended | no_show
 *                 pending → expired;  confirmed → cancelled | late_cancelled
 */

defined( 'ABSPATH' ) || exit;

class OYS_Bookings {

	const ACTIVE = array( 'pending', 'confirmed', 'attended', 'no_show' );

	public static function init() {
		add_action( 'oys_seat_released', array( __CLASS__, 'process_waitlist' ), 10, 1 );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'bookings' ) . ' WHERE id = %d', $id ) );
	}

	public static function for_session( $session_id, $statuses = null ) {
		global $wpdb;
		$t   = OYS_Install::table( 'bookings' );
		$sql = $wpdb->prepare( "SELECT * FROM $t WHERE session_id = %d", $session_id );
		if ( $statuses ) {
			$statuses = array_map( 'esc_sql', (array) $statuses );
			$sql     .= " AND status IN ('" . implode( "','", $statuses ) . "')";
		}
		return $wpdb->get_results( $sql . ' ORDER BY created_at ASC' );
	}

	/** Bookings joined with their session. $when = upcoming | past | all */
	public static function for_user( $user_id, $when = 'upcoming', $statuses = null ) {
		global $wpdb;
		$b   = OYS_Install::table( 'bookings' );
		$s   = OYS_Install::table( 'sessions' );
		$sql = $wpdb->prepare( "SELECT b.*, s.kind, s.class_slug, s.title, s.starts_at, s.ends_at, s.location, s.online_url, s.status AS session_status, s.price_cents
			FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.user_id = %d", $user_id );
		if ( 'upcoming' === $when ) {
			$sql .= $wpdb->prepare( ' AND s.ends_at >= %s', oys_now() );
		} elseif ( 'past' === $when ) {
			$sql .= $wpdb->prepare( ' AND s.ends_at < %s', oys_now() );
		}
		if ( $statuses ) {
			$sql .= " AND b.status IN ('" . implode( "','", array_map( 'esc_sql', (array) $statuses ) ) . "')";
		}
		$sql .= 'upcoming' === $when ? ' ORDER BY s.starts_at ASC' : ' ORDER BY s.starts_at DESC';
		return $wpdb->get_results( $sql );
	}

	public static function active_for( $user_id, $session_id ) {
		global $wpdb;
		$t = OYS_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $t WHERE user_id = %d AND session_id = %d AND ( status IN ('confirmed','attended','no_show') OR ( status = 'pending' AND hold_expires > %s ) ) ORDER BY id DESC LIMIT 1",
			$user_id, $session_id, oys_now()
		) );
	}

	public static function credit_kind( $session ) {
		return 'private' === $session->kind ? 'private' : 'class';
	}

	private static function insert( array $row ) {
		global $wpdb;
		$row = array_merge( array( 'created_at' => oys_now() ), $row );
		$wpdb->insert( OYS_Install::table( 'bookings' ), $row );
		return (int) $wpdb->insert_id;
	}

	private static function set( $id, array $row ) {
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'bookings' ), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Book a session with a pass credit.
	 * @return int|WP_Error booking id
	 */
	public static function book_with_credit( $user_id, $session, $notify = true ) {
		if ( is_numeric( $session ) ) {
			$session = OYS_Schedule::get( $session );
		}
		$check = self::can_book( $user_id, $session );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! $session->credits_allowed ) {
			return new WP_Error( 'oys_no_credits', __( 'Passes can\'t be used for this session.', 'olivia-studio' ) );
		}
		if ( ! OYS_Schedule::take_seat( $session->id ) ) {
			return new WP_Error( 'oys_full', __( 'Sorry, the last spot was just taken. You can join the waitlist.', 'olivia-studio' ) );
		}
		$pass_id = OYS_Passes::consume( $user_id, self::credit_kind( $session ), $session );
		if ( ! $pass_id ) {
			OYS_Schedule::release_seat( $session->id );
			return new WP_Error( 'oys_no_credit', __( 'You don\'t have a valid pass for this class.', 'olivia-studio' ) );
		}
		$id = self::insert( array(
			'session_id' => $session->id,
			'user_id'    => $user_id,
			'status'     => 'confirmed',
			'paid_with'  => 'credit',
			'pass_id'    => $pass_id,
		) );
		self::leave_waitlist( $user_id, $session->id );
		if ( $notify ) {
			OYS_Emails::booking_confirmed( $id );
		}
		do_action( 'oys_booking_confirmed', $id );
		return $id;
	}

	/** Staff adds someone to the roster (complimentary or paid at the door). */
	public static function book_manual( $user_id, $session, $paid_with = 'admin', $notify = true, $force = false ) {
		if ( is_numeric( $session ) ) {
			$session = OYS_Schedule::get( $session );
		}
		if ( ! $session ) {
			return new WP_Error( 'oys_missing', __( 'Session not found.', 'olivia-studio' ) );
		}
		if ( self::active_for( $user_id, $session->id ) ) {
			return new WP_Error( 'oys_dupe', __( 'Already booked.', 'olivia-studio' ) );
		}
		if ( ! OYS_Schedule::take_seat( $session->id ) ) {
			if ( ! $force ) {
				return new WP_Error( 'oys_full', __( 'This session is full.', 'olivia-studio' ) );
			}
			self::force_seat( $session->id );
		}
		$id = self::insert( array( 'session_id' => $session->id, 'user_id' => $user_id, 'status' => 'confirmed', 'paid_with' => $paid_with ) );
		self::leave_waitlist( $user_id, $session->id );
		if ( $notify ) {
			OYS_Emails::booking_confirmed( $id );
		}
		do_action( 'oys_booking_confirmed', $id );
		return $id;
	}

	/** Reserve a seat while the customer pays by card. */
	public static function hold( $user_id, $session, $order_id = 0 ) {
		$check = self::can_book( $user_id, $session );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! OYS_Schedule::take_seat( $session->id ) ) {
			return new WP_Error( 'oys_full', __( 'Sorry, this class is full. You can join the waitlist.', 'olivia-studio' ) );
		}
		// A few minutes longer than the Stripe Checkout Session, which can't be paid after it expires.
		$minutes = max( 30, (int) OYS_Settings::get( 'hold_minutes' ) ) + 5;
		return self::insert( array(
			'session_id'   => $session->id,
			'user_id'      => $user_id,
			'status'       => 'pending',
			'paid_with'    => 'card',
			'order_id'     => $order_id,
			'hold_expires' => oys_utc_plus( $minutes * MINUTE_IN_SECONDS ),
		) );
	}

	/** Payment arrived: confirm the held booking. Safe to call more than once. */
	public static function confirm_paid( $booking_id, $order_id ) {
		$booking = self::get( $booking_id );
		if ( ! $booking ) {
			return false;
		}
		if ( 'confirmed' === $booking->status ) {
			return true;
		}
		if ( 'pending' !== $booking->status ) {
			// The hold lapsed before the payment was recorded. The customer has paid, so they
			// keep the spot; if that overfills the class the studio is told.
			if ( ! OYS_Schedule::take_seat( $booking->session_id ) ) {
				self::force_seat( $booking->session_id );
				OYS_Emails::admin_notice(
					__( 'Class is over capacity by one', 'olivia-studio' ),
					sprintf( __( 'A payment for booking #%d arrived after its hold expired and the class had filled up in the meantime. The booking was confirmed anyway.', 'olivia-studio' ), $booking_id )
				);
			}
		}
		self::set( $booking_id, array( 'status' => 'confirmed', 'order_id' => $order_id, 'hold_expires' => null ) );
		self::leave_waitlist( $booking->user_id, $booking->session_id );
		OYS_Emails::booking_confirmed( $booking_id );
		do_action( 'oys_booking_confirmed', $booking_id );
		return true;
	}

	private static function force_seat( $session_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'sessions' ) . ' SET booked = booked + 1 WHERE id = %d', $session_id ) );
	}

	/** Payment failed or the checkout expired: free the seat. */
	public static function release_hold( $booking_id ) {
		global $wpdb;
		$t  = OYS_Install::table( 'bookings' );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'expired' WHERE id = %d AND status = 'pending'", $booking_id ) );
		if ( 1 === (int) $ok ) {
			$b = self::get( $booking_id );
			OYS_Schedule::release_seat( $b->session_id );
		}
	}

	public static function expire_holds() {
		global $wpdb;
		$t   = OYS_Install::table( 'bookings' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'pending' AND hold_expires IS NOT NULL AND hold_expires < %s", oys_now() ) );
		foreach ( $ids as $id ) {
			self::release_hold( $id );
		}
		return count( $ids );
	}

	/** @return true|WP_Error */
	public static function can_book( $user_id, $session ) {
		if ( ! $session ) {
			return new WP_Error( 'oys_missing', __( 'This class could not be found.', 'olivia-studio' ) );
		}
		$reason = OYS_Schedule::closed_reason( $session );
		if ( $reason ) {
			return new WP_Error( 'oys_closed', $reason );
		}
		if ( self::active_for( $user_id, $session->id ) ) {
			return new WP_Error( 'oys_dupe', __( 'You\'re already booked into this class.', 'olivia-studio' ) );
		}
		return true;
	}

	/** Can the customer still cancel without losing the class? */
	public static function in_cancel_window( $booking, $session = null ) {
		$session = $session ?: OYS_Schedule::get( $booking->session_id );
		$hours   = 'private' === $session->kind ? (int) OYS_Settings::get( 'private_cancel_hours' ) : (int) OYS_Settings::get( 'cancel_hours' );
		return time() <= oys_ts( $session->starts_at ) - $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Cancel a booking.
	 * $opts: by_studio (bool) — the studio cancelled, so the class always comes back;
	 *        reason (string); notify (bool, default true)
	 * @return string|WP_Error outcome: returned | credit | late | none
	 */
	public static function cancel( $booking_id, array $opts = array() ) {
		$opts    = wp_parse_args( $opts, array( 'by_studio' => false, 'reason' => '', 'notify' => true ) );
		$booking = self::get( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'oys_missing', __( 'Booking not found.', 'olivia-studio' ) );
		}
		if ( 'pending' === $booking->status ) {
			self::release_hold( $booking_id );
			return 'none';
		}
		if ( 'confirmed' !== $booking->status ) {
			return new WP_Error( 'oys_state', __( 'This booking can no longer be cancelled.', 'olivia-studio' ) );
		}
		$session = OYS_Schedule::get( $booking->session_id );
		if ( ! $opts['by_studio'] && oys_ts( $session->starts_at ) < time() ) {
			return new WP_Error( 'oys_started', __( 'This class has already started.', 'olivia-studio' ) );
		}
		$on_time = $opts['by_studio'] || self::in_cancel_window( $booking, $session );
		$outcome = 'late';
		if ( $on_time ) {
			$outcome = 'none';
			if ( 'credit' === $booking->paid_with && $booking->pass_id ) {
				OYS_Passes::refund_credit( $booking->pass_id, $booking->user_id );
				$outcome = 'returned';
			} elseif ( 'card' === $booking->paid_with ) {
				OYS_Passes::grant( $booking->user_id, array(
					'name'          => 'private' === $session->kind ? __( 'Private session credit', 'olivia-studio' ) : __( 'Class credit', 'olivia-studio' ),
					'kind'          => self::credit_kind( $session ),
					'credits'       => 1,
					'validity_days' => (int) OYS_Settings::get( 'dropin_credit_days' ),
					'order_id'      => $booking->order_id,
					'source'        => 'cancel',
				) );
				$outcome = 'credit';
			}
		}
		self::set( $booking_id, array( 'status' => $on_time ? 'cancelled' : 'late_cancelled', 'cancelled_at' => oys_now() ) );
		OYS_Schedule::release_seat( $booking->session_id );
		if ( $opts['notify'] ) {
			OYS_Emails::booking_cancelled( $booking_id, $outcome, $opts['by_studio'], $opts['reason'] );
		}
		do_action( 'oys_booking_cancelled', $booking_id, $outcome );
		return $outcome;
	}

	public static function set_attendance( $booking_id, $status ) {
		if ( ! in_array( $status, array( 'confirmed', 'attended', 'no_show' ), true ) ) {
			return;
		}
		self::set( $booking_id, array( 'status' => $status, 'checked_in_at' => 'attended' === $status ? oys_now() : null ) );
	}

	/* ---------- Waitlist ---------- */

	public static function join_waitlist( $user_id, $session_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . OYS_Install::table( 'waitlist' ) . ' (session_id, user_id, created_at) VALUES (%d, %d, %s)', $session_id, $user_id, oys_now() ) );
	}

	public static function leave_waitlist( $user_id, $session_id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'waitlist' ), array( 'user_id' => (int) $user_id, 'session_id' => (int) $session_id ) );
	}

	public static function clear_waitlist( $session_id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'waitlist' ), array( 'session_id' => (int) $session_id ) );
	}

	public static function waitlist( $session_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'waitlist' ) . ' WHERE session_id = %d ORDER BY created_at ASC, id ASC', $session_id ) );
	}

	public static function waitlist_position( $user_id, $session_id ) {
		foreach ( self::waitlist( $session_id ) as $i => $w ) {
			if ( (int) $w->user_id === (int) $user_id ) {
				return $i + 1;
			}
		}
		return 0;
	}

	public static function waitlists_for_user( $user_id ) {
		global $wpdb;
		$w = OYS_Install::table( 'waitlist' );
		$s = OYS_Install::table( 'sessions' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT w.*, s.class_slug, s.title, s.starts_at, s.kind FROM $w w JOIN $s s ON s.id = w.session_id WHERE w.user_id = %d AND s.starts_at > %s ORDER BY s.starts_at", $user_id, oys_now() ) );
	}

	/**
	 * A seat opened up. People with a valid pass are moved in automatically, in waitlist order;
	 * everyone else gets an email that a spot is free (first to book gets it).
	 * Stops before `waitlist_cutoff_hours` so nobody is surprised by a last-minute booking.
	 */
	public static function process_waitlist( $session_id ) {
		$session = OYS_Schedule::get( $session_id );
		if ( ! $session || 'scheduled' !== $session->status ) {
			return;
		}
		if ( time() > oys_ts( $session->starts_at ) - (int) OYS_Settings::get( 'waitlist_cutoff_hours' ) * HOUR_IN_SECONDS ) {
			return;
		}
		foreach ( self::waitlist( $session_id ) as $entry ) {
			$session = OYS_Schedule::get( $session_id );
			if ( OYS_Schedule::spots_left( $session ) < 1 ) {
				return;
			}
			if ( $session->credits_allowed && OYS_Passes::balance( $entry->user_id, self::credit_kind( $session ) ) > 0 ) {
				$id = self::book_with_credit( (int) $entry->user_id, $session, false );
				if ( ! is_wp_error( $id ) ) {
					OYS_Emails::waitlist_promoted( $id );
					continue;
				}
			}
			if ( ! $entry->notified_at ) {
				global $wpdb;
				$wpdb->update( OYS_Install::table( 'waitlist' ), array( 'notified_at' => oys_now() ), array( 'id' => $entry->id ) );
				OYS_Emails::waitlist_spot_open( (int) $entry->user_id, $session );
			}
		}
	}

	public static function statuses() {
		return array(
			'pending'        => __( 'Awaiting payment', 'olivia-studio' ),
			'confirmed'      => __( 'Booked', 'olivia-studio' ),
			'attended'       => __( 'Attended', 'olivia-studio' ),
			'no_show'        => __( 'No-show', 'olivia-studio' ),
			'cancelled'      => __( 'Cancelled', 'olivia-studio' ),
			'late_cancelled' => __( 'Late cancel', 'olivia-studio' ),
			'expired'        => __( 'Not completed', 'olivia-studio' ),
		);
	}
}

OYS_Bookings::init();
