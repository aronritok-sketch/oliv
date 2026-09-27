<?php
/**
 * Bookings: reserve with a credit or membership, hold seats while a card payment is in
 * progress, confirm, cancel (with the cancellation policy), attendance, guests, waitlist.
 *
 * A booking is one row per person. The customer's own row has guest_of = 0; each guest they
 * bring is a row with guest_of = host row id, the same user_id (the host owns and pays for it)
 * and guest_name / guest_email. Every row takes one seat and is paid on its own (pass credit,
 * membership for the host only, card, free), so rosters, cancellations and refunds work per person.
 *
 * Booking status: pending (payment hold) → confirmed → attended | no_show
 *                 pending → expired;  confirmed → cancelled | late_cancelled
 *
 * Paying at the studio: paid_with = 'door', due_cents = what they'll pay (the drop-in price or
 * the donation they chose); the studio marks it paid on the roster (collected_with = cash|other).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Bookings {

	const ACTIVE = array( 'pending', 'confirmed', 'attended', 'no_show' );

	public static function init() {
		add_action( 'oys_seat_released', array( __CLASS__, 'process_waitlist' ), 10, 1 );
	}

	/* ---------- Reading ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'bookings' ) . ' WHERE id = %d', $id ) );
	}

	public static function for_session( $session_id, $statuses = null ) {
		global $wpdb;
		$t   = OYS_Install::table( 'bookings' );
		$sql = $wpdb->prepare( "SELECT * FROM $t WHERE session_id = %d", $session_id );
		if ( $statuses ) {
			$sql .= " AND status IN ('" . implode( "','", array_map( 'esc_sql', (array) $statuses ) ) . "')";
		}
		return $wpdb->get_results( $sql . ' ORDER BY ( CASE WHEN guest_of = 0 THEN id ELSE guest_of END ), guest_of, id' );
	}

	public static function for_order( $order_id, $statuses = null ) {
		global $wpdb;
		$t   = OYS_Install::table( 'bookings' );
		$sql = $wpdb->prepare( "SELECT * FROM $t WHERE order_id = %d", $order_id );
		if ( $statuses ) {
			$sql .= " AND status IN ('" . implode( "','", array_map( 'esc_sql', (array) $statuses ) ) . "')";
		}
		return $wpdb->get_results( $sql . ' ORDER BY guest_of, id' );
	}

	/** Guests brought by a booking (their rows). */
	public static function guests_of( $host_id, $statuses = array( 'pending', 'confirmed', 'attended', 'no_show' ) ) {
		global $wpdb;
		$t = OYS_Install::table( 'bookings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE guest_of = %d AND status IN ('" . implode( "','", array_map( 'esc_sql', (array) $statuses ) ) . "') ORDER BY id", $host_id ) );
	}

	/** The customer's own bookings (not their guests), joined with the session, each with ->guests. */
	public static function for_user( $user_id, $when = 'upcoming', $statuses = null ) {
		global $wpdb;
		$b   = OYS_Install::table( 'bookings' );
		$s   = OYS_Install::table( 'sessions' );
		$sql = $wpdb->prepare( "SELECT b.*, s.kind, s.class_slug, s.title, s.starts_at, s.ends_at, s.location, s.online_url, s.status AS session_status, s.price_cents
			FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.user_id = %d AND b.guest_of = 0", $user_id );
		if ( 'upcoming' === $when ) {
			$sql .= $wpdb->prepare( ' AND s.ends_at >= %s', oys_now() );
		} elseif ( 'past' === $when ) {
			$sql .= $wpdb->prepare( ' AND s.ends_at < %s', oys_now() );
		}
		if ( $statuses ) {
			$sql .= " AND b.status IN ('" . implode( "','", array_map( 'esc_sql', (array) $statuses ) ) . "')";
		}
		$sql .= 'upcoming' === $when ? ' ORDER BY s.starts_at ASC' : ' ORDER BY s.starts_at DESC';
		$rows = $wpdb->get_results( $sql );
		foreach ( $rows as $r ) {
			$r->guests = self::guests_of( $r->id, array( 'confirmed', 'attended', 'no_show' ) );
		}
		return $rows;
	}

	/**
	 * The customer's own booking for a session (guests excluded). A card payment that was
	 * started but not finished is not a booking: see unfinished_for().
	 */
	public static function active_for( $user_id, $session_id ) {
		global $wpdb;
		$t = OYS_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $t WHERE user_id = %d AND session_id = %d AND guest_of = 0 AND status IN ('confirmed','attended','no_show') ORDER BY id DESC LIMIT 1",
			$user_id, $session_id
		) );
	}

	/** Seats held for card checkouts this customer started for the session but hasn't paid (own spot and guests). */
	public static function unfinished_for( $user_id, $session_id ) {
		global $wpdb;
		$t = OYS_Install::table( 'bookings' );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $t WHERE user_id = %d AND session_id = %d AND status = 'pending' AND hold_expires > %s ORDER BY id DESC",
			$user_id, $session_id, oys_now()
		) );
	}

	/**
	 * Drop the customer's unfinished card checkouts for a session before they book again:
	 * the Stripe page is closed so it can't be paid later, and the held seats are freed.
	 * If Stripe says a payment did go through after all, that order is fulfilled instead.
	 * @return string 'paid' when an earlier payment turned out complete, '' otherwise
	 */
	public static function abandon_unfinished( $user_id, $session_id ) {
		$result = '';
		foreach ( array_unique( array_map( 'intval', wp_list_pluck( self::unfinished_for( $user_id, $session_id ), 'order_id' ) ) ) as $order_id ) {
			$order = $order_id ? OYS_Orders::get( $order_id ) : null;
			if ( ! $order || 'pending' !== $order->status ) {
				continue;
			}
			if ( $order->stripe_session_id ) {
				$res = OYS_Stripe::expire_session( $order );
				if ( is_wp_error( $res ) ) {
					// Not open any more (paid, or already expired) or Stripe unreachable: ask Stripe what happened.
					$synced = OYS_Stripe::sync_session( $order->stripe_session_id );
					if ( is_wp_error( $synced ) ) {
						continue; // Keep the hold rather than risk charging twice.
					}
					if ( in_array( $synced->status, array( 'paid', 'partially_refunded' ), true ) ) {
						$result = 'paid';
						continue;
					}
				}
			}
			OYS_Orders::mark_unpaid( $order_id, 'expired' );
		}
		return $result;
	}

	/** Which pass credits pay for a session: private, online (joining online) or class. */
	public static function credit_kind( $session, $mode = 'studio' ) {
		if ( 'private' === $session->kind ) {
			return 'private';
		}
		return 'online' === oys_mode_for( $session, $mode ) ? 'online' : 'class';
	}

	/** Message when the seats for this way of joining are gone. */
	private static function full_error( $session, $mode, $left ) {
		if ( $left > 0 && PHP_INT_MAX !== $left ) {
			return new WP_Error( 'oys_full', sprintf( _n( 'Only %d spot is left. Bring fewer guests or join the waitlist.', 'Only %d spots are left. Bring fewer guests or join the waitlist.', $left, 'olivia-studio' ), $left ) );
		}
		if ( oys_is_hybrid( $session ) && 'online' === $mode ) {
			return new WP_Error( 'oys_full', __( 'The online spots for this class are taken.', 'olivia-studio' ) );
		}
		if ( oys_is_hybrid( $session ) ) {
			return new WP_Error( 'oys_full', __( 'The studio is full. You can still join live online, or join the waitlist.', 'olivia-studio' ) );
		}
		return new WP_Error( 'oys_full', __( 'Sorry, this class is full. You can join the waitlist.', 'olivia-studio' ) );
	}

	/**
	 * Link an online participant joins with: their own Zoom link, the class's Zoom meeting, or
	 * the link set on the class. '' for people in the studio.
	 */
	public static function join_link( $booking, $session = null ) {
		$session = $session ?: OYS_Schedule::get( $booking->session_id );
		if ( ! $session || 'online' !== oys_mode_for( $session, $booking->mode ?? 'studio' ) ) {
			return '';
		}
		$url = apply_filters( 'oys_join_link', '', $booking, $session );
		return $url ?: (string) $session->online_url;
	}

	/** Display name for a roster line: the customer, or "Guest name (guest of Anna)". */
	public static function person_label( $b ) {
		$u = get_userdata( $b->user_id );
		if ( $b->guest_of ) {
			return sprintf( __( '%1$s (guest of %2$s)', 'olivia-studio' ), $b->guest_name ?: __( 'Guest', 'olivia-studio' ), $u ? $u->display_name : '' );
		}
		return $u ? $u->display_name : '#' . $b->user_id;
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

	/** Clean up guest input: [ ['name' => .., 'email' => ..], … ] limited to max_guests. */
	public static function clean_guests( $names, $emails = array() ) {
		$out = array();
		foreach ( (array) $names as $i => $name ) {
			$name = sanitize_text_field( wp_unslash( $name ) );
			if ( '' === $name ) {
				continue;
			}
			$email = sanitize_email( wp_unslash( $emails[ $i ] ?? '' ) );
			$out[] = array( 'name' => mb_substr( $name, 0, 120 ), 'email' => is_email( $email ) ? $email : '' );
		}
		return array_slice( $out, 0, max( 0, (int) OYS_Settings::get( 'max_guests' ) ) );
	}

	/* ---------- Booking without a card payment ---------- */

	/**
	 * Book the customer and/or guests straight away.
	 *
	 * $args:
	 *   method        host payment: credit | membership | free | admin | cash | comp
	 *   guests        [ ['name','email'], … ]
	 *   guest_method  guests' payment: credit | free | admin | cash | comp (default: same as method, credit for membership)
	 *   host_booking  existing booking id when only adding guests to it (the host row is not created)
	 *   mode          'studio' | 'online' – how they join a hybrid class (online-only classes are always online)
	 *   notify        send the confirmation (default true)
	 *   force         staff: book even when full
	 *
	 * @return int|WP_Error the host booking id (or, when adding guests, the existing host id)
	 */
	public static function book_party( $user_id, $session, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'method' => 'credit', 'guests' => array(), 'guest_method' => '', 'host_booking' => 0, 'notify' => true, 'force' => false, 'mode' => 'studio', 'amount' => null ) );
		if ( is_numeric( $session ) ) {
			$session = OYS_Schedule::get( $session );
		}
		$guests       = $args['guests'];
		$host_booking = $args['host_booking'] ? self::get( $args['host_booking'] ) : null;
		// Guests join the same way as the person who brings them.
		$mode         = oys_mode_for( $session, $host_booking ? $host_booking->mode : $args['mode'] );
		$with_host    = ! $host_booking;
		$guest_method = $args['guest_method'] ?: ( 'membership' === $args['method'] ? 'credit' : $args['method'] );

		if ( $with_host ) {
			$check = self::can_book( $user_id, $session );
			if ( is_wp_error( $check ) && ! ( $args['force'] && 'oys_closed' === $check->get_error_code() ) ) {
				return $check;
			}
		} else {
			if ( ! $host_booking || (int) $host_booking->user_id !== (int) $user_id || 'confirmed' !== $host_booking->status || (int) $host_booking->session_id !== (int) $session->id ) {
				return new WP_Error( 'oys_state', __( 'Book your own spot first, then add guests.', 'olivia-studio' ) );
			}
			$reason = OYS_Schedule::closed_reason( $session );
			if ( $reason && ! $args['force'] ) {
				return new WP_Error( 'oys_closed', $reason );
			}
			if ( ! $guests ) {
				return new WP_Error( 'oys_guests', __( 'Add at least one guest name.', 'olivia-studio' ) );
			}
		}

		// Credits needed up front, so nothing is half-booked.
		$needs = ( $with_host && 'credit' === $args['method'] ? 1 : 0 ) + ( 'credit' === $guest_method ? count( $guests ) : 0 );
		if ( $needs && ( ! $session->credits_allowed || OYS_Passes::available_for( $user_id, $session, $mode ) < $needs ) ) {
			return new WP_Error( 'oys_no_credit', $session->credits_allowed
				? sprintf( _n( 'You need %d class on your pass for this booking.', 'You need %d classes on your pass for this booking.', $needs, 'olivia-studio' ), $needs )
				: __( 'Passes can\'t be used for this session.', 'olivia-studio' ) );
		}
		if ( in_array( 'door', array( $with_host ? $args['method'] : '', $guests ? $guest_method : '' ), true ) && ! $args['force'] ) {
			$door = self::pay_later_allowed( $user_id, $session, $mode );
			if ( is_wp_error( $door ) ) {
				return $door;
			}
		}
		$due    = null === $args['amount'] ? OYS_Schedule::price_for( $session, $mode ) : max( 0, (int) $args['amount'] );
		$member = null;
		if ( $with_host && 'membership' === $args['method'] ) {
			$member = OYS_Memberships::current_for( $user_id );
			$covers = OYS_Memberships::covers( $member, $session, $mode );
			if ( is_wp_error( $covers ) ) {
				return $covers;
			}
		}

		$people = ( $with_host ? 1 : 0 ) + count( $guests );
		if ( ! OYS_Schedule::take_seats( $session->id, $people, $mode ) ) {
			if ( ! $args['force'] ) {
				return self::full_error( $session, $mode, OYS_Schedule::spots_left( OYS_Schedule::get( $session->id ), $mode ) );
			}
			OYS_Schedule::force_seats( $session->id, $people, $mode );
		}

		$created = array();
		$pay     = function ( $method ) use ( $user_id, $session, $member, $mode, $due ) {
			$row = array( 'paid_with' => $method, 'mode' => $mode );
			if ( 'door' === $method ) {
				$row['due_cents'] = $due;
			} elseif ( 'credit' === $method ) {
				$row['pass_id'] = OYS_Passes::consume_for( $user_id, $session, $mode );
				if ( ! $row['pass_id'] ) {
					return null;
				}
			} elseif ( 'membership' === $method ) {
				$row['membership_id'] = $member->id;
			}
			return $row;
		};
		$rollback = function () use ( &$created, $session, $people, $mode ) {
			foreach ( $created as $id ) {
				$b = self::get( $id );
				if ( 'credit' === $b->paid_with && $b->pass_id ) {
					OYS_Passes::refund_credit( $b->pass_id, $b->user_id );
				}
				self::set( $id, array( 'status' => 'cancelled', 'cancelled_at' => oys_now(), 'note' => 'Rolled back' ) );
			}
			OYS_Schedule::release_seats( $session->id, $people, $mode );
		};

		$host_id = $host_booking ? (int) $host_booking->id : 0;
		if ( $with_host ) {
			$row = $pay( $args['method'] );
			if ( null === $row ) {
				OYS_Schedule::release_seats( $session->id, $people, $mode );
				return new WP_Error( 'oys_no_credit', __( 'You don\'t have a valid pass for this class.', 'olivia-studio' ) );
			}
			$host_id   = self::insert( array_merge( array( 'session_id' => $session->id, 'user_id' => $user_id, 'status' => 'confirmed' ), $row ) );
			$created[] = $host_id;
		}
		foreach ( $guests as $g ) {
			$row = $pay( $guest_method );
			if ( null === $row ) {
				$rollback();
				return new WP_Error( 'oys_no_credit', __( 'Not enough classes on your pass for all guests.', 'olivia-studio' ) );
			}
			$created[] = self::insert( array_merge( array( 'session_id' => $session->id, 'user_id' => $user_id, 'status' => 'confirmed', 'guest_of' => $host_id, 'guest_name' => $g['name'], 'guest_email' => $g['email'] ), $row ) );
		}

		// Two tabs booking the last class of a limited membership at once: undo this one.
		if ( $member && (int) $member->classes_per_period && OYS_Memberships::used_in_period( $member ) > (int) $member->classes_per_period ) {
			$rollback();
			return new WP_Error( 'oys_membership', __( 'You\'ve used all classes of this period.', 'olivia-studio' ) );
		}

		self::leave_waitlist( $user_id, $session->id );
		if ( $args['notify'] ) {
			OYS_Emails::booking_confirmed( $host_id, $created );
		}
		foreach ( $created as $id ) {
			do_action( 'oys_booking_confirmed', $id );
		}
		return $host_id;
	}

	/** Book one person with a pass credit (used by the waitlist and staff). */
	public static function book_with_credit( $user_id, $session, $notify = true, $mode = 'studio' ) {
		return self::book_party( $user_id, $session, array( 'method' => 'credit', 'notify' => $notify, 'mode' => $mode ) );
	}

	/** Book one person with their membership. */
	public static function book_with_membership( $user_id, $session, $notify = true, $mode = 'studio' ) {
		return self::book_party( $user_id, $session, array( 'method' => 'membership', 'notify' => $notify, 'mode' => $mode ) );
	}

	/** Staff adds someone to the roster (complimentary or paid at the door). */
	public static function book_manual( $user_id, $session, $paid_with = 'admin', $notify = true, $force = false, $mode = 'studio' ) {
		if ( is_numeric( $session ) ) {
			$session = OYS_Schedule::get( $session );
		}
		if ( ! $session ) {
			return new WP_Error( 'oys_missing', __( 'Session not found.', 'olivia-studio' ) );
		}
		return self::book_party( $user_id, $session, array( 'method' => $paid_with, 'notify' => $notify, 'force' => $force, 'mode' => $mode ) );
	}

	/* ---------- Paying at the studio ---------- */

	/** Bookings paid at the studio that the person didn't come to, since the studio last reset it. */
	public static function pay_later_no_shows( $user_id ) {
		global $wpdb;
		$since = (string) get_user_meta( $user_id, 'oys_pay_later_reset', true );
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . OYS_Install::table( 'bookings' ) . " WHERE user_id = %d AND guest_of = 0 AND paid_with = 'door' AND status = 'no_show' AND created_at > %s",
			$user_id, $since ?: '1970-01-01 00:00:00'
		) );
	}

	/** Has the customer ever had a spot (booked, attended or missed)? */
	public static function has_booked_before( $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . OYS_Install::table( 'bookings' ) . " WHERE user_id = %d AND guest_of = 0 AND status IN ('confirmed','attended','no_show') LIMIT 1", $user_id ) );
	}

	/**
	 * Can this customer book now and pay at the studio? Studio classes only (not online, not
	 * private sessions), when the class allows it; Studio → Settings decides for whom (everyone
	 * or first class only), and after too many missed unpaid bookings they pay in advance.
	 * @return true|WP_Error
	 */
	public static function pay_later_allowed( $user_id, $session, $mode = 'studio' ) {
		$rule = OYS_Settings::get( 'pay_later' );
		if ( 'off' === $rule || ! $session || empty( $session->pay_later ) || 'private' === $session->kind || 'online' === oys_mode_for( $session, $mode ) ) {
			return new WP_Error( 'oys_pay_later', __( 'Paying at the studio isn\'t available for this class.', 'olivia-studio' ) );
		}
		if ( ! oys_is_donation( $session ) && OYS_Schedule::price_for( $session, $mode ) < 1 ) {
			return new WP_Error( 'oys_pay_later', __( 'This class is free: just reserve your spot.', 'olivia-studio' ) );
		}
		if ( 'first' === $rule && self::has_booked_before( $user_id ) ) {
			return new WP_Error( 'oys_pay_later_first', __( 'Paying at the studio is for your first class. Please pay online or use a pass.', 'olivia-studio' ) );
		}
		$max = (int) OYS_Settings::get( 'pay_later_max_no_shows' );
		if ( $max > 0 && self::pay_later_no_shows( $user_id ) >= $max ) {
			return new WP_Error( 'oys_pay_later_blocked', __( 'You missed a few classes you had booked to pay at the studio, so please pay in advance for now.', 'olivia-studio' ) );
		}
		return true;
	}

	/** The studio received the payment for a pay-at-the-studio booking (and the person is here). */
	public static function collect( $booking_id, $with = 'cash' ) {
		$b = self::get( $booking_id );
		if ( ! $b || 'door' !== $b->paid_with || ! in_array( $b->status, array( 'confirmed', 'attended', 'no_show' ), true ) ) {
			return false;
		}
		self::set( $b->id, array( 'collected_with' => in_array( $with, array( 'cash', 'other' ), true ) ? $with : 'cash', 'status' => 'attended', 'checked_in_at' => $b->checked_in_at ?: oys_now() ) );
		return true;
	}

	/** Still to be paid at the studio for a session, in cents. */
	public static function due_at_studio( $session_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(due_cents),0) FROM ' . OYS_Install::table( 'bookings' ) . " WHERE session_id = %d AND paid_with = 'door' AND collected_with = '' AND status IN ('confirmed','attended')", $session_id ) );
	}

	/* ---------- Card payments: hold, confirm, release ---------- */

	/**
	 * Hold seats while the customer pays by card (for themselves and/or guests).
	 * Guests are attached to the new host row, or to $host_booking when adding guests later.
	 * @return int|WP_Error id of the first held row
	 */
	public static function hold( $user_id, $session, $order_id = 0, array $guests = array(), $include_host = true, $host_booking = 0, $mode = 'studio' ) {
		$mode = oys_mode_for( $session, $mode );
		if ( $include_host ) {
			$check = self::can_book( $user_id, $session );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		} else {
			$host = self::get( $host_booking );
			if ( ! $host || (int) $host->user_id !== (int) $user_id || 'confirmed' !== $host->status ) {
				return new WP_Error( 'oys_state', __( 'Book your own spot first, then add guests.', 'olivia-studio' ) );
			}
			$mode = oys_mode_for( $session, $host->mode );
			$reason = OYS_Schedule::closed_reason( $session );
			if ( $reason ) {
				return new WP_Error( 'oys_closed', $reason );
			}
		}
		$people = ( $include_host ? 1 : 0 ) + count( $guests );
		if ( $people < 1 ) {
			return new WP_Error( 'oys_guests', __( 'Add at least one guest name.', 'olivia-studio' ) );
		}
		if ( ! OYS_Schedule::take_seats( $session->id, $people, $mode ) ) {
			return self::full_error( $session, $mode, OYS_Schedule::spots_left( OYS_Schedule::get( $session->id ), $mode ) );
		}
		// A few minutes longer than the Stripe Checkout Session, which can't be paid after it expires.
		$expires = oys_utc_plus( ( max( 30, (int) OYS_Settings::get( 'hold_minutes' ) ) + 5 ) * MINUTE_IN_SECONDS );
		$base    = array( 'session_id' => $session->id, 'user_id' => $user_id, 'status' => 'pending', 'paid_with' => 'card', 'order_id' => $order_id, 'hold_expires' => $expires, 'mode' => $mode );
		$host_id = $include_host ? self::insert( $base ) : (int) $host_booking;
		$first   = $include_host ? $host_id : 0;
		foreach ( $guests as $g ) {
			$id    = self::insert( array_merge( $base, array( 'guest_of' => $host_id, 'guest_name' => $g['name'], 'guest_email' => $g['email'] ) ) );
			$first = $first ?: $id;
		}
		return $first;
	}

	/**
	 * Payment arrived: confirm every row held for this order. If $pass_id is given (a pass bought
	 * together with the booking), each row is paid with one credit of it. Safe to call twice.
	 */
	public static function confirm_order( $order_id, $pass_id = 0 ) {
		global $wpdb;
		$rows = self::for_order( $order_id, array( 'pending', 'expired' ) );
		if ( ! $rows ) {
			return false;
		}
		$ids = array();
		foreach ( $rows as $b ) {
			if ( 'expired' === $b->status && ! OYS_Schedule::take_seat( $b->session_id, $b->mode ) ) {
				// The hold lapsed and the class filled up meanwhile. The customer has paid, so they keep the spot.
				OYS_Schedule::force_seats( $b->session_id, 1, $b->mode );
				OYS_Emails::admin_notice(
					__( 'Class is over capacity by one', 'olivia-studio' ),
					sprintf( __( 'A payment for booking #%d arrived after its hold expired and the class had filled up in the meantime. The booking was confirmed anyway.', 'olivia-studio' ), $b->id )
				);
			}
			$row = array( 'status' => 'confirmed', 'hold_expires' => null );
			if ( $pass_id ) {
				$ok = $wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'passes' ) . ' SET credits_left = credits_left - 1 WHERE id = %d AND credits_left > 0', $pass_id ) );
				if ( 1 === (int) $ok ) {
					$row['paid_with'] = 'credit';
					$row['pass_id']   = $pass_id;
				}
			}
			self::set( $b->id, $row );
			$ids[] = (int) $b->id;
		}
		$first = self::get( $ids[0] );
		$host  = $first->guest_of ? (int) $first->guest_of : (int) $first->id;
		self::leave_waitlist( $first->user_id, $first->session_id );
		OYS_Emails::booking_confirmed( $host, $ids );
		foreach ( $ids as $id ) {
			do_action( 'oys_booking_confirmed', $id );
		}
		return true;
	}

	/** Kept for callers of the single-booking API. */
	public static function confirm_paid( $booking_id, $order_id ) {
		return self::confirm_order( $order_id );
	}

	/** Payment failed or the checkout expired: free the seat. */
	public static function release_hold( $booking_id ) {
		global $wpdb;
		$t  = OYS_Install::table( 'bookings' );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'expired' WHERE id = %d AND status = 'pending'", $booking_id ) );
		if ( 1 === (int) $ok ) {
			$b = self::get( $booking_id );
			OYS_Schedule::release_seat( $b->session_id, $b->mode );
		}
	}

	public static function release_order( $order_id ) {
		foreach ( self::for_order( $order_id, array( 'pending' ) ) as $b ) {
			self::release_hold( $b->id );
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
		if ( self::unfinished_for( $user_id, $session->id ) ) {
			return new WP_Error( 'oys_unfinished', __( 'You have an unfinished card payment for this class. Continue it, or start again from the class page.', 'olivia-studio' ) );
		}
		return true;
	}

	/* ---------- Cancelling ---------- */

	/** Can the customer still cancel without losing the class? */
	public static function in_cancel_window( $booking, $session = null ) {
		$session = $session ?: OYS_Schedule::get( $booking->session_id );
		$hours   = 'private' === $session->kind ? (int) OYS_Settings::get( 'private_cancel_hours' ) : (int) OYS_Settings::get( 'cancel_hours' );
		return time() <= oys_ts( $session->starts_at ) - $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Cancel a booking. Cancelling the customer's own row cancels their guests too;
	 * a guest row can also be cancelled on its own.
	 * $opts: by_studio (bool) — the studio cancelled, so the class always comes back;
	 *        reason (string); notify (bool, default true);
	 *        email (template key instead of the default), email_extra (html added to the email)
	 * @return string|WP_Error outcome of the row: returned | credit | membership | late | none
	 */
	public static function cancel( $booking_id, array $opts = array() ) {
		$opts    = wp_parse_args( $opts, array( 'by_studio' => false, 'reason' => '', 'notify' => true, 'email' => '', 'email_extra' => '' ) );
		$booking = self::get( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'oys_missing', __( 'Booking not found.', 'olivia-studio' ) );
		}
		if ( 'pending' === $booking->status ) {
			self::abandon_unfinished( $booking->user_id, $booking->session_id );
			return 'none';
		}
		if ( 'confirmed' !== $booking->status ) {
			return new WP_Error( 'oys_state', __( 'This booking can no longer be cancelled.', 'olivia-studio' ) );
		}
		$session = OYS_Schedule::get( $booking->session_id );
		if ( ! $opts['by_studio'] && oys_ts( $session->starts_at ) < time() ) {
			return new WP_Error( 'oys_started', __( 'This class has already started.', 'olivia-studio' ) );
		}
		$on_time  = $opts['by_studio'] || self::in_cancel_window( $booking, $session );
		$rows     = array( $booking );
		if ( ! $booking->guest_of ) {
			$rows = array_merge( $rows, self::guests_of( $booking->id, array( 'confirmed', 'pending' ) ) );
		}
		$outcomes = array();
		foreach ( $rows as $b ) {
			if ( 'pending' === $b->status ) {
				self::release_hold( $b->id );
				continue;
			}
			$outcomes[ $b->id ] = self::cancel_row( $b, $session, $on_time );
		}
		if ( $opts['notify'] ) {
			OYS_Emails::booking_cancelled( $booking_id, $outcomes[ $booking_id ], $opts['by_studio'], $opts['reason'], array_keys( $outcomes ), $opts['email'], $opts['email_extra'] );
		}
		foreach ( $outcomes as $id => $outcome ) {
			do_action( 'oys_booking_cancelled', $id, $outcome );
		}
		return $outcomes[ $booking_id ];
	}

	private static function cancel_row( $b, $session, $on_time ) {
		$outcome = 'late';
		if ( $on_time ) {
			$outcome = 'none';
			if ( 'membership' === $b->paid_with ) {
				$outcome = 'membership'; // Nothing to give back: the class simply doesn't count.
			} elseif ( 'credit' === $b->paid_with && $b->pass_id ) {
				OYS_Passes::refund_credit( $b->pass_id, $b->user_id );
				$outcome = 'returned';
			} elseif ( 'card' === $b->paid_with && OYS_Connect::account_for_order( OYS_Orders::get( $b->order_id ) ) ) {
				$outcome = 'refund'; // Paid to a teacher's own Stripe: refunded below, once the seat is freed.
			} elseif ( 'card' === $b->paid_with ) {
				OYS_Passes::grant( $b->user_id, array(
					'name'          => 'private' === $session->kind ? __( 'Private session credit', 'olivia-studio' ) : ( 'online' === $b->mode ? __( 'Online class credit', 'olivia-studio' ) : __( 'Class credit', 'olivia-studio' ) ),
					'kind'          => self::credit_kind( $session, $b->mode ),
					'credits'       => 1,
					'validity_days' => (int) OYS_Settings::get( 'dropin_credit_days' ),
					'order_id'      => $b->order_id,
					'source'        => 'cancel',
				) );
				$outcome = 'credit';
			}
		}
		self::set( $b->id, array( 'status' => $on_time ? 'cancelled' : 'late_cancelled', 'cancelled_at' => oys_now() ) );
		OYS_Schedule::release_seat( $b->session_id, $b->mode );
		if ( 'refund' === $outcome ) {
			$outcome = self::refund_seat( $b, $session );
		}
		return $outcome;
	}

	/**
	 * Refund one cancelled seat of a card payment made to a teacher's Stripe account (a studio
	 * credit would leave the money with the teacher). Falls back to a class credit if Stripe says no.
	 * @return string 'refunded' | 'credit'
	 */
	private static function refund_seat( $b, $session ) {
		global $wpdb;
		$order  = OYS_Orders::get( $b->order_id );
		$left   = (int) $order->amount_cents - (int) ( $order->meta['refunded_cents'] ?? 0 );
		$others = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'bookings' ) . " WHERE order_id = %d AND id <> %d AND status IN ('confirmed','attended','no_show','pending')", $order->id, $b->id ) );
		$amount = $others ? min( $left, OYS_Connect::seat_value( $order ) ) : $left;
		$res    = $amount > 0 ? OYS_Stripe::refund( $order->id, $amount, 'b' . $b->id ) : new WP_Error( 'oys_refund', 'nothing left to refund' );
		if ( ! is_wp_error( $res ) ) {
			self::set( $b->id, array( 'note' => trim( $b->note . ' Refunded ' . oys_money( $amount ) ) ) );
			return 'refunded';
		}
		oys_log( 'Teacher payment refund failed, class credit given', array( 'booking' => $b->id, 'order' => $order->id, 'error' => $res->get_error_message() ) );
		OYS_Passes::grant( $b->user_id, array(
			'name'          => __( 'Class credit', 'olivia-studio' ),
			'kind'          => self::credit_kind( $session, $b->mode ),
			'credits'       => 1,
			'validity_days' => (int) OYS_Settings::get( 'dropin_credit_days' ),
			'order_id'      => $b->order_id,
			'source'        => 'cancel',
		) );
		return 'credit';
	}

	/** Staff/refund: cancel without giving anything back and without emails. */
	public static function void( $booking_id, $note = '' ) {
		$b = self::get( $booking_id );
		if ( $b && in_array( $b->status, array( 'confirmed', 'pending' ), true ) ) {
			self::set( $b->id, array( 'status' => 'cancelled', 'cancelled_at' => oys_now(), 'note' => $note ) );
			OYS_Schedule::release_seat( $b->session_id, $b->mode );
		}
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
	 * A seat opened up. People with a membership or a valid pass are moved in automatically, in waitlist order;
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
			$member = OYS_Memberships::current_for( (int) $entry->user_id );
			if ( $member && true === OYS_Memberships::covers( $member, $session ) ) {
				$id = self::book_with_membership( (int) $entry->user_id, $session, false );
				if ( ! is_wp_error( $id ) ) {
					OYS_Emails::waitlist_promoted( $id );
					continue;
				}
			}
			if ( $session->credits_allowed && OYS_Passes::available_for( (int) $entry->user_id, $session ) > 0 ) {
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

	public static function paid_with_labels() {
		return array(
			'credit'     => __( 'Pass', 'olivia-studio' ),
			'door'       => __( 'Pays at the studio', 'olivia-studio' ),
			'membership' => __( 'Membership', 'olivia-studio' ),
			'card'       => __( 'Card', 'olivia-studio' ),
			'free'       => __( 'Free', 'olivia-studio' ),
			'cash'       => __( 'Cash', 'olivia-studio' ),
			'comp'       => __( 'Complimentary', 'olivia-studio' ),
			'admin'      => __( 'Added by studio', 'olivia-studio' ),
		);
	}
}

OYS_Bookings::init();
