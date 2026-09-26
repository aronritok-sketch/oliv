<?php
/**
 * Weekly templates and the sessions generated from them, plus one-off sessions
 * (workshops, events, private sessions). Seat counting is atomic: a seat is taken with
 * a conditional UPDATE, so two people can never book the last spot at the same time.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Schedule {

	/* ---------- Templates ---------- */

	public static function templates( $only_active = false ) {
		global $wpdb;
		$t   = OYS_Install::table( 'templates' );
		$sql = "SELECT * FROM $t" . ( $only_active ? ' WHERE active = 1' : '' ) . ' ORDER BY weekday, start_time';
		return $wpdb->get_results( $sql );
	}

	public static function template( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'templates' ) . ' WHERE id = %d', $id ) );
	}

	public static function save_template( array $data, $id = 0 ) {
		global $wpdb;
		$t    = OYS_Install::table( 'templates' );
		$data = array(
			'class_slug'   => sanitize_title( $data['class_slug'] ?? '' ),
			'weekday'      => min( 7, max( 1, (int) ( $data['weekday'] ?? 1 ) ) ),
			'start_time'   => preg_match( '/^\d{2}:\d{2}$/', $data['start_time'] ?? '' ) ? $data['start_time'] : '18:00',
			'duration_min' => max( 15, (int) ( $data['duration_min'] ?? 60 ) ),
			'capacity'     => max( 1, (int) ( $data['capacity'] ?? 12 ) ),
			'location'     => sanitize_text_field( $data['location'] ?? '' ),
			'online_url'   => esc_url_raw( $data['online_url'] ?? '' ),
			'price_cents'  => (int) ( $data['price_cents'] ?? 0 ),
			'note'         => sanitize_text_field( $data['note'] ?? '' ),
			'active'       => empty( $data['active'] ) ? 0 : 1,
		);
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, $data );
			$id = (int) $wpdb->insert_id;
		}
		return $id;
	}

	public static function delete_template( $id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'templates' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Create sessions for every active template up to `weeks_ahead`. Already generated
	 * occurrences are skipped, so this is safe to run repeatedly (cron does, hourly).
	 */
	public static function generate( $weeks = null ) {
		global $wpdb;
		$weeks = $weeks ?: (int) OYS_Settings::get( 'weeks_ahead' );
		$s     = OYS_Install::table( 'sessions' );
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$made  = 0;
		foreach ( self::templates( true ) as $tpl ) {
			for ( $d = 0; $d < $weeks * 7; $d++ ) {
				$day = $today->modify( "+$d days" );
				if ( (int) $day->format( 'N' ) !== (int) $tpl->weekday ) {
					continue;
				}
				$start_local = new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $tpl->start_time, $tz );
				$start       = $start_local->setTimezone( new DateTimeZone( 'UTC' ) );
				if ( $start->getTimestamp() < time() ) {
					continue;
				}
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $s WHERE template_id = %d AND starts_at = %s", $tpl->id, $start->format( 'Y-m-d H:i:s' ) ) );
				if ( $exists ) {
					continue;
				}
				$wpdb->insert( $s, array(
					'kind'        => 'group',
					'class_slug'  => $tpl->class_slug,
					'starts_at'   => $start->format( 'Y-m-d H:i:s' ),
					'ends_at'     => $start->modify( '+' . (int) $tpl->duration_min . ' minutes' )->format( 'Y-m-d H:i:s' ),
					'capacity'    => $tpl->capacity,
					'location'    => $tpl->location,
					'online_url'  => $tpl->online_url,
					'price_cents' => $tpl->price_cents,
					'note'        => $tpl->note,
					'template_id' => $tpl->id,
					'created_at'  => oys_now(),
				) );
				$made++;
			}
		}
		return $made;
	}

	/* ---------- Sessions ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * @param array $args kind (string|array), from, to (UTC), status, class_slug, limit
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$s     = OYS_Install::table( 'sessions' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['kind'] ) ) {
			$kinds   = (array) $args['kind'];
			$where[] = 'kind IN (' . implode( ',', array_fill( 0, count( $kinds ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $kinds );
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = 'starts_at >= %s';
			$vals[]  = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'starts_at < %s';
			$vals[]  = $args['to'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['class_slug'] ) ) {
			$where[] = 'class_slug = %s';
			$vals[]  = $args['class_slug'];
		}
		$order = ! empty( $args['desc'] ) ? 'DESC' : 'ASC';
		$limit = ! empty( $args['limit'] ) ? ' LIMIT ' . (int) $args['limit'] : '';
		$sql   = "SELECT * FROM $s WHERE " . implode( ' AND ', $where ) . " ORDER BY starts_at $order" . $limit;
		return $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql );
	}

	/** Public, bookable-looking sessions in the booking window. */
	public static function upcoming_public( $days = null, $kinds = array( 'group', 'event' ) ) {
		$days = $days ?: (int) OYS_Settings::get( 'booking_window_days' );
		return self::query( array(
			'kind'   => $kinds,
			'from'   => oys_now(),
			'to'     => oys_utc_plus( $days * DAY_IN_SECONDS ),
			'status' => 'scheduled',
		) );
	}

	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$t   = OYS_Install::table( 'sessions' );
		$row = array();
		foreach ( array( 'kind', 'class_slug', 'title', 'description', 'starts_at', 'ends_at', 'capacity', 'location', 'online_url', 'price_cents', 'credits_allowed', 'note', 'status', 'template_id' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$row[ $k ] = $data[ $k ];
			}
		}
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			return (int) $id;
		}
		$row['created_at'] = oys_now();
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function spots_left( $session ) {
		return max( 0, (int) $session->capacity - (int) $session->booked );
	}

	/** Why a session can't be booked right now, or '' if it can. */
	public static function closed_reason( $session ) {
		if ( ! $session || 'scheduled' !== $session->status ) {
			return __( 'This class is no longer available.', 'olivia-studio' );
		}
		$close = oys_ts( $session->starts_at ) - (int) OYS_Settings::get( 'booking_close_minutes' ) * MINUTE_IN_SECONDS;
		if ( time() > $close ) {
			return __( 'Online booking for this class has closed.', 'olivia-studio' );
		}
		return '';
	}

	/** Atomically take one seat. Returns true when a seat was free. */
	public static function take_seat( $session_id ) {
		global $wpdb;
		$t = OYS_Install::table( 'sessions' );
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET booked = booked + 1 WHERE id = %d AND status = 'scheduled' AND booked < capacity", $session_id ) );
	}

	public static function release_seat( $session_id ) {
		global $wpdb;
		$t = OYS_Install::table( 'sessions' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET booked = booked - 1 WHERE id = %d AND booked > 0", $session_id ) );
		do_action( 'oys_seat_released', (int) $session_id );
	}

	/** Recount seats from bookings (repairs drift after manual database edits). */
	public static function recount( $session_id ) {
		global $wpdb;
		$b     = OYS_Install::table( 'bookings' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE session_id = %d AND ( status IN ('confirmed','attended','no_show') OR ( status = 'pending' AND hold_expires > %s ) )", $session_id, oys_now() ) );
		$wpdb->update( OYS_Install::table( 'sessions' ), array( 'booked' => $count ), array( 'id' => $session_id ) );
		return $count;
	}

	/**
	 * Cancel a whole session (studio side): every booking gets its class back
	 * (credit returned, or studio credit for card payments) and people are emailed.
	 */
	public static function cancel_session( $session_id, $reason = '' ) {
		$session = self::get( $session_id );
		if ( ! $session || 'cancelled' === $session->status ) {
			return 0;
		}
		self::save( array( 'status' => 'cancelled' ), $session_id );
		$n = 0;
		foreach ( OYS_Bookings::for_session( $session_id, array( 'confirmed', 'pending' ) ) as $booking ) {
			OYS_Bookings::cancel( $booking->id, array( 'by_studio' => true, 'reason' => $reason ) );
			$n++;
		}
		OYS_Bookings::clear_waitlist( $session_id );
		return $n;
	}

	public static function kinds() {
		return array(
			'group'   => __( 'Group class', 'olivia-studio' ),
			'event'   => __( 'Event / workshop', 'olivia-studio' ),
			'private' => __( 'Private session', 'olivia-studio' ),
		);
	}

	public static function weekdays() {
		return array( 1 => __( 'Monday' ), 2 => __( 'Tuesday' ), 3 => __( 'Wednesday' ), 4 => __( 'Thursday' ), 5 => __( 'Friday' ), 6 => __( 'Saturday' ), 7 => __( 'Sunday' ) );
	}
}
