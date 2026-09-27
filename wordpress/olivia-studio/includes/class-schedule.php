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
		$old  = $id ? self::template( $id ) : null;
		$data = array(
			'class_slug'   => sanitize_title( $data['class_slug'] ?? '' ),
			'weekday'      => min( 7, max( 1, (int) ( $data['weekday'] ?? 1 ) ) ),
			'start_time'   => preg_match( '/^\d{2}:\d{2}$/', $data['start_time'] ?? '' ) ? $data['start_time'] : '18:00',
			'duration_min' => max( 15, (int) ( $data['duration_min'] ?? 60 ) ),
			'capacity'     => max( 1, (int) ( $data['capacity'] ?? 12 ) ),
			'location'     => sanitize_text_field( $data['location'] ?? '' ),
			'format'       => in_array( $data['format'] ?? '', array( 'online', 'hybrid' ), true ) ? $data['format'] : 'studio',
			'online_capacity'    => max( 0, (int) ( $data['online_capacity'] ?? 0 ) ),
			'online_price_cents' => max( 0, (int) ( $data['online_price_cents'] ?? 0 ) ),
			'online_url'   => esc_url_raw( $data['online_url'] ?? '' ),
			'price_cents'  => (int) ( $data['price_cents'] ?? 0 ),
			// Forms that don't show these fields keep what the weekly class had.
			'pricing'      => 'donation' === ( $data['pricing'] ?? ( $old->pricing ?? '' ) ) ? 'donation' : 'fixed',
			'pay_later'    => array_key_exists( 'pay_later', $data ) ? ( empty( $data['pay_later'] ) ? 0 : 1 ) : (int) ( $old->pay_later ?? 1 ),
			// NULL = the location's minimum and decision time.
			'min_people'   => array_key_exists( 'min_people', $data ) ? self::nullable_int( $data['min_people'] ) : ( $old->min_people ?? null ),
			'decide_hours' => array_key_exists( 'decide_hours', $data ) ? self::nullable_int( $data['decide_hours'], 1 ) : ( $old->decide_hours ?? null ),
			'note'         => sanitize_text_field( $data['note'] ?? '' ),
			'active'       => empty( $data['active'] ) ? 0 : 1,
			'valid_from'   => ! empty( $data['valid_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['valid_from'] ) ? $data['valid_from'] : null,
		);
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, $data );
			$id = (int) $wpdb->insert_id;
		}
		return $id;
	}

	/** '' or null → null; otherwise an int of at least $min. */
	public static function nullable_int( $v, $min = 0 ) {
		return null === $v || '' === $v ? null : max( $min, (int) $v );
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
				if ( (int) $day->format( 'N' ) !== (int) $tpl->weekday || ( $tpl->valid_from && $day->format( 'Y-m-d' ) < $tpl->valid_from ) ) {
					continue;
				}
				$start_local = new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $tpl->start_time, $tz );
				$start       = $start_local->setTimezone( new DateTimeZone( 'UTC' ) );
				if ( $start->getTimestamp() < time() ) {
					continue;
				}
				// A date that was moved or cancelled still holds its original slot, so it isn't created again.
				$slot   = $start->format( 'Y-m-d H:i:s' );
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $s WHERE template_id = %d AND ( tpl_slot = %s OR ( tpl_slot IS NULL AND starts_at = %s ) )", $tpl->id, $slot, $slot ) );
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
					'format'      => $tpl->format,
					'online_capacity'    => $tpl->online_capacity,
					'online_price_cents' => $tpl->online_price_cents,
					'online_url'  => $tpl->online_url,
					'price_cents' => $tpl->price_cents,
					'pricing'     => $tpl->pricing ?: 'fixed',
					'pay_later'   => (int) $tpl->pay_later,
					'min_people'  => $tpl->min_people,
					'decide_hours' => $tpl->decide_hours,
					'note'        => $tpl->note,
					'template_id' => $tpl->id,
					'tpl_slot'    => $slot,
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
		foreach ( array( 'kind', 'class_slug', 'title', 'description', 'starts_at', 'ends_at', 'capacity', 'location', 'format', 'online_url', 'price_cents', 'online_capacity', 'online_price_cents', 'zoom_meeting_id', 'zoom_join_url', 'zoom_password', 'credits_allowed', 'pricing', 'pay_later', 'min_people', 'decide_hours', 'min_state', 'nudge_sent', 'note', 'status', 'template_id', 'tpl_slot' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$row[ $k ] = $data[ $k ];
			}
		}
		if ( $id ) {
			$before = self::get( $id );
			// Moved to another time, or the minimum changed: the go-ahead decision is made again.
			if ( $before && ( ( isset( $row['starts_at'] ) && $row['starts_at'] !== $before->starts_at ) || ( array_key_exists( 'min_people', $row ) && (string) $row['min_people'] !== (string) $before->min_people ) || ( array_key_exists( 'decide_hours', $row ) && (string) $row['decide_hours'] !== (string) $before->decide_hours ) ) && ! isset( $row['min_state'] ) && 1 === (int) $before->min_state ) {
				$row['min_state']  = 0;
				$row['nudge_sent'] = 0;
			}
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			/** Fires after a session changed (time, place, format, status…); Zoom keeps its meeting in step. */
			do_action( 'oys_session_saved', (int) $id, $before );
			return (int) $id;
		}
		$row['created_at'] = oys_now();
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
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

	/**
	 * Seats are counted per way of joining: studio seats in `booked` / `capacity`; the online
	 * seats of a hybrid class in `online_booked` / `online_capacity` (0 = no limit). An online-only
	 * class counts its (online) seats in `booked` / `capacity`.
	 */
	private static function counts_online( $session_id, $mode ) {
		if ( 'online' !== $mode ) {
			return false;
		}
		global $wpdb;
		return 'hybrid' === $wpdb->get_var( $wpdb->prepare( 'SELECT format FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE id = %d', $session_id ) );
	}

	/** Atomically take one seat. Returns true when a seat was free. */
	public static function take_seat( $session_id, $mode = 'studio' ) {
		return self::take_seats( $session_id, 1, $mode );
	}

	/** Atomically take $n seats at once (a customer and their guests): all or nothing. */
	public static function take_seats( $session_id, $n, $mode = 'studio' ) {
		global $wpdb;
		$n = max( 1, (int) $n );
		$t = OYS_Install::table( 'sessions' );
		if ( self::counts_online( $session_id, $mode ) ) {
			return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET online_booked = online_booked + %d WHERE id = %d AND status = 'scheduled' AND ( online_capacity = 0 OR online_booked + %d <= online_capacity )", $n, $session_id, $n ) );
		}
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET booked = booked + %d WHERE id = %d AND status = 'scheduled' AND booked + %d <= capacity", $n, $session_id, $n ) );
	}

	/** Take seats even when full (staff, or a late payment that must be honoured). */
	public static function force_seats( $session_id, $n, $mode = 'studio' ) {
		global $wpdb;
		$col = self::counts_online( $session_id, $mode ) ? 'online_booked' : 'booked';
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'sessions' ) . " SET $col = $col + %d WHERE id = %d", (int) $n, $session_id ) );
	}

	public static function release_seats( $session_id, $n, $mode = 'studio' ) {
		global $wpdb;
		$t   = OYS_Install::table( 'sessions' );
		$col = self::counts_online( $session_id, $mode ) ? 'online_booked' : 'booked';
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET $col = GREATEST(0, CAST($col AS SIGNED) - %d) WHERE id = %d", (int) $n, $session_id ) );
		do_action( 'oys_seat_released', (int) $session_id, $mode );
	}

	public static function release_seat( $session_id, $mode = 'studio' ) {
		self::release_seats( $session_id, 1, $mode );
	}

	/** Free seats for this way of joining; PHP_INT_MAX when online seats are unlimited. */
	public static function spots_left( $session, $mode = 'studio' ) {
		if ( oys_is_hybrid( $session ) && 'online' === $mode ) {
			return (int) $session->online_capacity ? max( 0, (int) $session->online_capacity - (int) $session->online_booked ) : PHP_INT_MAX;
		}
		return max( 0, (int) $session->capacity - (int) $session->booked );
	}

	/** Recount seats from bookings (repairs drift after manual database edits). */
	public static function recount( $session_id ) {
		global $wpdb;
		$b      = OYS_Install::table( 'bookings' );
		$s      = self::get( $session_id );
		$active = "( status IN ('confirmed','attended','no_show') OR ( status = 'pending' AND hold_expires > %s ) )";
		if ( oys_is_hybrid( $s ) ) {
			$studio = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE session_id = %d AND mode <> 'online' AND $active", $session_id, oys_now() ) );
			$online = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE session_id = %d AND mode = 'online' AND $active", $session_id, oys_now() ) );
			$wpdb->update( OYS_Install::table( 'sessions' ), array( 'booked' => $studio, 'online_booked' => $online ), array( 'id' => $session_id ) );
			return $studio;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE session_id = %d AND $active", $session_id, oys_now() ) );
		$wpdb->update( OYS_Install::table( 'sessions' ), array( 'booked' => $count, 'online_booked' => 0 ), array( 'id' => $session_id ) );
		return $count;
	}

	/** Price of one ticket for this way of joining. */
	public static function price_for( $session, $mode = 'studio' ) {
		if ( oys_is_hybrid( $session ) && 'online' === $mode ) {
			return (int) $session->online_price_cents;
		}
		return (int) $session->price_cents;
	}

	/**
	 * Cancel a whole session (studio side): every booking gets its class back
	 * (credit returned, or studio credit for card payments) and people are emailed.
	 */
	public static function cancel_session( $session_id, $reason = '', array $opts = array() ) {
		$session = self::get( $session_id );
		if ( ! $session || 'cancelled' === $session->status ) {
			return 0;
		}
		self::save( array( 'status' => 'cancelled' ), $session_id );
		$n = 0;
		foreach ( OYS_Bookings::for_session( $session_id, array( 'confirmed', 'pending' ) ) as $booking ) {
			$fresh = OYS_Bookings::get( $booking->id );
			if ( ! in_array( $fresh->status, array( 'confirmed', 'pending' ), true ) ) {
				continue; // Already cancelled together with its host booking.
			}
			OYS_Bookings::cancel( $booking->id, array_merge( $opts, array( 'by_studio' => true, 'reason' => $reason ) ) );
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

	public static function formats() {
		return array(
			'studio' => __( 'In person', 'olivia-studio' ),
			'online' => __( 'Online (live stream)', 'olivia-studio' ),
			'hybrid' => __( 'In person + live online', 'olivia-studio' ),
		);
	}

	public static function weekdays() {
		return array( 1 => __( 'Monday' ), 2 => __( 'Tuesday' ), 3 => __( 'Wednesday' ), 4 => __( 'Thursday' ), 5 => __( 'Friday' ), 6 => __( 'Saturday' ), 7 => __( 'Sunday' ) );
	}
}
