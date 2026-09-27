<?php
/**
 * Places where classes happen, each with its own minimum number of people, and the automatic
 * "does this class go ahead?" decision.
 *
 * A location has a name (matched against the session's `location`), an address, the minimum
 * number of people and how many hours before the start the decision is made. Online classes
 * use the built-in "online" location. A class can override both numbers (sessions/templates
 * `min_people`, `decide_hours`; NULL = the location's, min 0 = no minimum).
 *
 * At the decision time (cron, every 5 minutes): enough people → the class is on (the studio is
 * told); too few → the class is cancelled, everyone gets their class back and an email with
 * other dates to book instead. Before that, optionally, the people booked get a "bring a friend"
 * email. sessions.min_state: 0 = not decided, 1 = goes ahead, 2 = cancelled for too few people.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Locations {

	const OPTION = 'oys_locations';

	/** @return array[] id => [id, name, address, min_people, decide_hours] (always includes 'online') */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( $saved as $l ) {
			if ( ! empty( $l['id'] ) && ! empty( $l['name'] ) ) {
				$out[ $l['id'] ] = self::clean( $l );
			}
		}
		if ( ! isset( $out['online'] ) ) {
			$out['online'] = array( 'id' => 'online', 'name' => __( 'Online', 'olivia-studio' ), 'address' => '', 'min_people' => 3, 'decide_hours' => 3 );
		}
		return $out;
	}

	private static function clean( array $l ) {
		return array(
			'id'           => sanitize_key( $l['id'] ),
			'name'         => sanitize_text_field( $l['name'] ),
			'address'      => sanitize_text_field( $l['address'] ?? '' ),
			'min_people'   => max( 0, (int) ( $l['min_people'] ?? OYS_Settings::get( 'min_people_default' ) ) ),
			'decide_hours' => max( 1, (int) ( $l['decide_hours'] ?? OYS_Settings::get( 'decide_hours_default' ) ) ),
		);
	}

	/** Replace the list (Studio → Locations). Rows without a name are dropped. */
	public static function save_all( array $rows ) {
		$out  = array();
		$used = array();
		foreach ( $rows as $r ) {
			$name = trim( sanitize_text_field( $r['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$id = sanitize_key( $r['id'] ?? '' ) ?: sanitize_title( $name );
			$id = $id ?: 'place';
			while ( isset( $used[ $id ] ) ) {
				$id .= '-2';
			}
			$used[ $id ] = true;
			$out[]       = self::clean( array_merge( $r, array( 'id' => $id, 'name' => $name ) ) );
		}
		update_option( self::OPTION, $out, false );
	}

	/** The location a session takes place at (by name; online classes → 'online'), or null. */
	public static function for_session( $s ) {
		$all = self::all();
		if ( oys_is_online( $s ) ) {
			return $all['online'];
		}
		$name = strtolower( trim( (string) $s->location ) );
		foreach ( $all as $l ) {
			if ( 'online' !== $l['id'] && '' !== $name && strtolower( $l['name'] ) === $name ) {
				return $l;
			}
		}
		return null;
	}

	/**
	 * The minimum that applies to a session: [ people, hours before the start ].
	 * Group classes use their location's (or the default) rule; events and private sessions only
	 * when the class sets its own minimum. people = 0 → no minimum.
	 */
	public static function rule( $s ) {
		$loc    = self::for_session( $s );
		$own    = null !== $s->min_people && '' !== $s->min_people;
		$people = $own ? (int) $s->min_people : ( $loc ? (int) $loc['min_people'] : (int) OYS_Settings::get( 'min_people_default' ) );
		$hours  = null !== $s->decide_hours && '' !== $s->decide_hours ? (int) $s->decide_hours : ( $loc ? (int) $loc['decide_hours'] : (int) OYS_Settings::get( 'decide_hours_default' ) );
		if ( 'private' === $s->kind || ( 'group' !== $s->kind && ! $own ) ) {
			$people = 0;
		}
		return array( max( 0, $people ), max( 1, $hours ) );
	}

	/** When the decision is made (UTC timestamp), or 0 when the class has no minimum. */
	public static function decide_at( $s ) {
		[ $people, $hours ] = self::rule( $s );
		return $people ? oys_ts( $s->starts_at ) - $hours * HOUR_IN_SECONDS : 0;
	}

	/** People booked (everyone who takes a place: customers and guests, in the studio and online). */
	public static function people( $s ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'bookings' ) . " WHERE session_id = %d AND status IN ('confirmed','attended')", $s->id ) );
	}

	/* ---------- The automatic decision ---------- */

	/** Cron: decide every class whose decision time has come; send "bring a friend" emails before. */
	public static function run() {
		global $wpdb;
		$t    = OYS_Install::table( 'sessions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'scheduled' AND min_state = 0 AND starts_at > %s AND starts_at < %s ORDER BY starts_at", oys_now(), oys_utc_plus( 8 * DAY_IN_SECONDS ) ) );
		$done = array( 'on' => 0, 'cancelled' => 0, 'nudged' => 0 );
		foreach ( $rows as $s ) {
			$at = self::decide_at( $s );
			if ( ! $at ) {
				continue;
			}
			if ( time() >= $at ) {
				$done[ self::decide( $s ) ? 'on' : 'cancelled' ]++;
				continue;
			}
			$nudge = (int) OYS_Settings::get( 'min_nudge_hours' );
			if ( $nudge && ! (int) $s->nudge_sent && time() >= $at - $nudge * HOUR_IN_SECONDS && self::nudge( $s ) ) {
				$done['nudged']++;
			}
		}
		return $done;
	}

	/**
	 * Decide one class now. @return bool true = it goes ahead, false = cancelled for too few people.
	 */
	public static function decide( $s ) {
		global $wpdb;
		[ $min ] = self::rule( $s );
		$people  = self::people( $s );
		$t       = OYS_Install::table( 'sessions' );
		// Claim the decision so two cron runs can't both act on it.
		$state = $people >= $min ? 1 : 2;
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET min_state = %d WHERE id = %d AND min_state = 0 AND status = 'scheduled'", $state, $s->id ) ) ) {
			return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT min_state FROM $t WHERE id = %d", $s->id ) );
		}
		$when = oys_session_title( $s ) . ', ' . oys_date( $s->starts_at, 'D M j, g:i a' ) . ( $s->location ? ' · ' . $s->location : '' );
		if ( 1 === $state ) {
			OYS_Emails::admin_notice(
				sprintf( __( 'Class is on: %s', 'olivia-studio' ), $when ),
				sprintf( _n( '%1$d person is booked (minimum %2$d), so the class goes ahead.', '%1$d people are booked (minimum %2$d), so the class goes ahead.', $people, 'olivia-studio' ), $people, $min ),
				'studio_minimum'
			);
			do_action( 'oys_class_confirmed', (int) $s->id, $people );
			return true;
		}
		$reason = __( 'Not enough people signed up, so it won\'t go ahead this time.', 'olivia-studio' );
		OYS_Schedule::cancel_session( $s->id, $reason, array( 'email' => 'class_cancelled_minimum', 'email_extra' => self::alternatives_html( $s ) ) );
		OYS_Emails::admin_notice(
			sprintf( __( 'Cancelled automatically: %s', 'olivia-studio' ), $when ),
			sprintf( __( 'Only %1$d booked (minimum %2$d). Everyone was emailed with other dates and got their class back.', 'olivia-studio' ), $people, $min ),
			'studio_minimum'
		);
		do_action( 'oys_class_cancelled_minimum', (int) $s->id, $people );
		return false;
	}

	/** "Bring a friend": the class still needs people. @return bool emails sent */
	public static function nudge( $s ) {
		global $wpdb;
		$t = OYS_Install::table( 'sessions' );
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET nudge_sent = 1 WHERE id = %d AND nudge_sent = 0", $s->id ) ) ) {
			return false;
		}
		[ $min ] = self::rule( $s );
		$people  = self::people( $s );
		if ( ! $people || $people >= $min ) {
			return false;
		}
		$sent = 0;
		foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed' ) ) as $b ) {
			if ( ! $b->guest_of && OYS_Emails::minimum_nudge( $b, $s, $min - $people, self::decide_at( $s ) ) ) {
				$sent++;
			}
		}
		return $sent > 0;
	}

	/**
	 * Other dates to book instead of a cancelled class: the next date of the same class at the same
	 * place, other classes in the next few days, and a live online class. @return object[]
	 */
	public static function alternatives( $s, $limit = 4 ) {
		$from  = oys_utc_plus( HOUR_IN_SECONDS );
		$later = OYS_Schedule::query( array( 'kind' => array( 'group', 'event' ), 'from' => $from, 'to' => oys_utc_plus( 14 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) );
		$later = array_values( array_filter( $later, fn( $x ) => (int) $x->id !== (int) $s->id && OYS_Schedule::spots_left( $x ) > 0 && ! OYS_Schedule::closed_reason( $x ) ) );
		$pick  = array();
		$add   = function ( $x ) use ( &$pick ) {
			$pick[ (int) $x->id ] = $x;
		};
		foreach ( $later as $x ) { // Same class, same place.
			if ( $x->class_slug === $s->class_slug && strtolower( $x->location ) === strtolower( $s->location ) && $x->format === $s->format ) {
				$add( $x );
				break;
			}
		}
		$soon = oys_ts( $s->starts_at ) + 3 * DAY_IN_SECONDS;
		foreach ( $later as $x ) { // Other classes in the next days.
			if ( count( $pick ) >= $limit - 1 ) {
				break;
			}
			if ( oys_ts( $x->starts_at ) <= $soon && ! isset( $pick[ (int) $x->id ] ) && ! oys_is_online( $x ) ) {
				$add( $x );
			}
		}
		if ( ! oys_is_online( $s ) ) { // A live online class.
			foreach ( $later as $x ) {
				if ( oys_has_online( $x ) && ! isset( $pick[ (int) $x->id ] ) && oys_ts( $x->starts_at ) <= $soon ) {
					$add( $x );
					break;
				}
			}
		}
		$pick = array_values( $pick );
		usort( $pick, fn( $a, $b ) => oys_ts( $a->starts_at ) <=> oys_ts( $b->starts_at ) );
		return array_slice( $pick, 0, $limit );
	}

	/** The alternatives as an email block, with a "bring a friend" line. */
	public static function alternatives_html( $s ) {
		$alts = self::alternatives( $s );
		$html = '';
		if ( $alts ) {
			$html .= '<p style="margin:22px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#53635A">' . esc_html__( 'Join another class instead', 'olivia-studio' ) . '</p><ul style="margin:0 0 14px;padding-left:18px">';
			foreach ( $alts as $x ) {
				$where = oys_is_online( $x ) ? __( 'live online', 'olivia-studio' ) : ( oys_is_hybrid( $x ) ? $x->location . ' · ' . __( 'or live online', 'olivia-studio' ) : $x->location );
				$html .= '<li style="margin:0 0 6px"><a href="' . esc_url( oys_book_url( $x->id ) ) . '" style="color:#2B5036;font-weight:700">' . esc_html( oys_session_title( $x ) ) . '</a> · ' . esc_html( oys_date( $x->starts_at, 'D M j, g:i a' ) ) . ( $where ? ' · ' . esc_html( $where ) : '' ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '<p style="font-size:14px;color:#53635A">' . esc_html__( 'Tip: bring a friend next time. Booking together helps a class go ahead, and friends can join as your guests when you book.', 'olivia-studio' ) . '</p>';
	}

	/** Public line for the booking page: "Goes ahead with 3 or more people…" or ''. */
	public static function notice( $s ) {
		[ $min ] = self::rule( $s );
		$at      = self::decide_at( $s );
		if ( ! $min || $min < 2 || 'scheduled' !== $s->status ) {
			return '';
		}
		if ( 1 === (int) $s->min_state ) {
			return __( 'This class is confirmed: it goes ahead.', 'olivia-studio' );
		}
		if ( time() >= $at ) {
			return ''; // Being decided right now.
		}
		return sprintf( __( 'This class goes ahead with %1$d or more people. If fewer sign up, it\'s cancelled by %2$s and we let you know; your class comes back to you.', 'olivia-studio' ), $min, wp_date( 'D g:i a', $at ) );
	}
}
