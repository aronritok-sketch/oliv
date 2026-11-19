<?php
/**
 * Private sessions: the customer sends a request (where, how long, when suits them),
 * Olivia answers with a concrete time and price, the customer confirms by paying
 * (card or a private-session credit). The offer is a one-person session only the
 * requesting customer can book.
 *
 * status: new → offered → booked | declined | cancelled
 */

defined( 'ABSPATH' ) || exit;

class OYS_Privates {

	public static function init() {
		add_action( 'admin_post_oys_private_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'oys_booking_confirmed', array( __CLASS__, 'on_booking_confirmed' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'private_requests' ) . ' WHERE id = %d', $id ) );
	}

	public static function for_session( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'private_requests' ) . ' WHERE session_id = %d ORDER BY id DESC LIMIT 1', $session_id ) );
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'private_requests' ) . ' WHERE user_id = %d ORDER BY created_at DESC', $user_id ) );
	}

	public static function query( $status = '' ) {
		global $wpdb;
		$t = OYS_Install::table( 'private_requests' );
		return $status ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = %s ORDER BY created_at DESC", $status ) ) : $wpdb->get_results( "SELECT * FROM $t ORDER BY FIELD(status,'new','offered','booked','declined','cancelled'), created_at DESC LIMIT 300" );
	}

	public static function update( $id, array $row ) {
		global $wpdb;
		$row['updated_at'] = oys_now();
		$wpdb->update( OYS_Install::table( 'private_requests' ), $row, array( 'id' => (int) $id ) );
	}

	public static function location_types() {
		return array(
			'home'   => __( 'My home or lanai', 'olivia-studio' ),
			'beach'  => __( 'On the beach', 'olivia-studio' ),
			'club'   => __( 'Condo / HOA clubhouse', 'olivia-studio' ),
			'office' => __( 'Office', 'olivia-studio' ),
			'online' => __( 'Online', 'olivia-studio' ),
		);
	}

	/** What a private request can be for: the one-to-one services on offer, and private yoga as before. */
	public static function topics() {
		$out = array();
		if ( OYS_Products::has_plain_private() || ! OYS_Services::offered() ) {
			$out['yoga'] = __( 'Private yoga', 'olivia-studio' );
		}
		foreach ( OYS_Services::offered() as $key => $s ) {
			$out[ $key ] = OYS_Services::label( $key );
		}
		return $out;
	}

	/**
	 * What was asked for, label → value: the service, its length, how many, where, the times that suit, the note.
	 * Áron, 16 November: „Egyéni kérések esetén mindig legyen meg, hogy mi a szolgáltatás, meg mire kért, és az
	 * e-mailbe is menjen ki, ne csak az adminban legyen látható.” – the same list in the emails, the admin and the account.
	 */
	public static function summary( $r ) {
		$types = self::location_types();
		$rows  = array(
			__( 'Service', 'olivia-studio' )         => self::topic_label( $r->topic ?? '', (int) $r->people ),
			__( 'Length', 'olivia-studio' )          => (int) $r->duration_min . ' ' . __( 'min', 'olivia-studio' ),
			__( 'Group size', 'olivia-studio' )      => max( 1, (int) $r->people ) . ' ' . __( 'people', 'olivia-studio' ),
			__( 'Partner', 'olivia-studio' )         => ! empty( $r->partner_email ) ? trim( $r->partner_name . ' <' . $r->partner_email . '>' ) : '',
			__( 'Location', 'olivia-studio' )        => trim( ( $types[ $r->location_type ] ?? (string) $r->location_type ) . ( '' !== (string) $r->address ? ' – ' . $r->address : '' ) ),
			__( 'Preferred times', 'olivia-studio' ) => (string) $r->preferred,
			__( 'Notes', 'olivia-studio' )           => (string) $r->notes,
		);
		return array_filter( $rows, fn( $v ) => '' !== trim( (string) $v ) );
	}

	/** The same as a small table for an email (inline styles: email programs ignore style sheets). */
	public static function summary_html( $r ) {
		$html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 18px">';
		foreach ( self::summary( $r ) as $label => $value ) {
			$html .= '<tr><td style="padding:8px 14px 8px 0;border-bottom:1px solid #ECE9EA;vertical-align:top;white-space:nowrap;font-size:13px;color:#53635A">' . esc_html( $label ) . '</td>'
				. '<td style="padding:8px 0;border-bottom:1px solid #ECE9EA;vertical-align:top;font-size:15px;font-weight:600;color:#12231A">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	public static function topic_label( $topic, $people = 1 ) {
		return OYS_Services::get( (string) $topic ) ? OYS_Services::label( $topic, $people ) : __( 'Private session', 'olivia-studio' );
	}

	/**
	 * A service session is paid with that service's credits (alone, or with a friend, of its length), private yoga
	 * with private credits.
	 */
	public static function credit_kind_for( $session ) {
		$svc = OYS_Services::for_class( (string) $session->class_slug );
		if ( ! $svc ) {
			return 'private';
		}
		$request = ! empty( $session->id ) ? self::for_session( (int) $session->id ) : null;
		return OYS_Services::credit_kind( $svc[0], $svc[1], $request ? (int) $request->duration_min : 0 );
	}

	/** At most this many times in one go from the calendar (Áron, 19 November: „lehessen egyszerre több időpontot … ne csak egyet”). */
	const MAX_SLOTS = 10;

	/** The hours a one-to-one session can be asked for in the calendar: 14:00–21:00 (filter `oys_calendar_ask_hours`). */
	public static function ask_hours() {
		$hours = array_values( array_map( 'intval', (array) apply_filters( 'oys_calendar_ask_hours', array( 14, 21 ) ) ) );
		$from  = max( 0, min( 23, $hours[0] ?? 14 ) );
		return array( $from, max( $from, min( 23, $hours[1] ?? 21 ) ) );
	}

	/** The half hours to choose from: „14:00” … „21:00”. */
	public static function ask_times() {
		[ $from, $to ] = self::ask_hours();
		$out = array();
		for ( $m = $from * 60; $m <= $to * 60; $m += 30 ) {
			$out[] = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
		}
		return $out;
	}

	/**
	 * The times chosen in the calendar: `pslots` („2026-12-18T18:30,2026-12-20” – a day alone is any time that day) or the
	 * older `pday` + `ptime`. Only coming days, each once, a day either at given times or at any time, at most MAX_SLOTS.
	 * @return array of array( 'day' => 'Y-m-d', 'time' => 'H:i' or '' )
	 */
	public static function slots_from( $raw, $day = '', $time = '' ) {
		$items = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		if ( ! $items && '' !== (string) $day ) {
			$items = array( $day . ( '' !== (string) $time ? 'T' . $time : '' ) );
		}
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$out   = array();
		foreach ( $items as $it ) {
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:T((?:[01]\d|2[0-3]):[0-5]\d))?$/', (string) $it, $m ) ) {
				continue;
			}
			$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $m[1], $tz );
			if ( ! $d || $d->format( 'Y-m-d' ) !== $m[1] || $d < $today ) {
				continue;
			}
			$t = $m[2] ?? '';
			$out[ $m[1] . ( '' !== $t ? 'T' . $t : '' ) ] = array( 'day' => $m[1], 'time' => $t );
		}
		// a day with given times is not also „any time”
		$timed = array_unique( array_column( array_filter( $out, fn( $x ) => '' !== $x['time'] ), 'day' ) );
		$out   = array_filter( $out, fn( $x ) => '' !== $x['time'] || ! in_array( $x['day'], $timed, true ) );
		ksort( $out );
		return array_slice( array_values( $out ), 0, self::MAX_SLOTS );
	}

	/** „2026-12-18T18:30” (the key of a chosen time). */
	public static function slot_key( array $slot ) {
		return $slot['day'] . ( '' !== $slot['time'] ? 'T' . $slot['time'] : '' );
	}

	/** When a chosen time starts (a timestamp), 0 for a day at any time. */
	public static function slot_ts( array $slot ) {
		return '' === $slot['time'] ? 0 : ( new DateTimeImmutable( $slot['day'] . ' ' . $slot['time'], wp_timezone() ) )->getTimestamp();
	}

	/** „december 18., péntek, 18:30”, or „december 18., péntek, bármikor 14:00 és 21:00 között”. */
	public static function slot_label( array $slot ) {
		$day = wp_date( oys_date_format( 'l, F j' ), DateTimeImmutable::createFromFormat( '!Y-m-d', $slot['day'], wp_timezone() )->setTime( 12, 0 )->getTimestamp() );
		if ( '' !== $slot['time'] ) {
			return $day . ', ' . $slot['time'];
		}
		[ $from, $to ] = self::ask_hours();
		return $day . ', ' . sprintf( __( 'any time between %1$s and %2$s', 'olivia-studio' ), sprintf( '%d:00', $from ), sprintf( '%d:00', $to ) );
	}

	/**
	 * What already takes the teacher's time between two moments (timestamps): every scheduled class and one-to-one session,
	 * and the one-to-one times asked for and not answered yet. @return array of array( start, end ) timestamps
	 */
	public static function busy( $from_ts, $to_ts ) {
		global $wpdb;
		$out = array();
		foreach ( OYS_Schedule::query( array( 'from' => gmdate( 'Y-m-d H:i:s', $from_ts - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d H:i:s', $to_ts ), 'status' => 'scheduled' ) ) as $s ) {
			$a = oys_ts( $s->starts_at );
			$b = max( oys_ts( $s->ends_at ?: $s->starts_at ), $a + 30 * MINUTE_IN_SECONDS );
			if ( $b > $from_ts ) {
				$out[] = array( $a, $b );
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT slot_at, duration_min FROM ' . OYS_Install::table( 'private_requests' ) . " WHERE status = 'new' AND slot_at IS NOT NULL AND slot_at >= %s AND slot_at < %s", gmdate( 'Y-m-d H:i:s', $from_ts - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $to_ts ) ) );
		foreach ( (array) $rows as $r ) {
			$a     = oys_ts( $r->slot_at );
			$out[] = array( $a, $a + max( 30, (int) $r->duration_min ) * MINUTE_IN_SECONDS );
		}
		return $out;
	}

	/** Is the time free for a session this long, and far enough ahead (2 hours; filter `oys_private_lead_hours`)? */
	public static function is_free( $start_ts, $minutes, array $busy ) {
		if ( $start_ts < time() + max( 0, (float) apply_filters( 'oys_private_lead_hours', 2 ) ) * HOUR_IN_SECONDS ) {
			return false;
		}
		$end = $start_ts + max( 30, (int) $minutes ) * MINUTE_IN_SECONDS;
		foreach ( $busy as $b ) {
			if ( $start_ts < $b[1] && $end > $b[0] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The calendar's taken half hours, day by day (Áron, 19 November: „érzékelje azt, hogyha be van foglalva egy idősáv, ott
	 * az ne legyen foglalható”): array( 'Y-m-d' => array( '18:00', … ) ) for a session of $minutes.
	 */
	public static function taken_times( $first_day, $last_day, $minutes = 60 ) {
		$tz   = wp_timezone();
		$d    = DateTimeImmutable::createFromFormat( '!Y-m-d', $first_day, $tz );
		$e    = DateTimeImmutable::createFromFormat( '!Y-m-d', $last_day, $tz );
		$out  = array();
		if ( ! $d || ! $e ) {
			return $out;
		}
		$busy  = self::busy( $d->getTimestamp(), $e->modify( '+1 day' )->getTimestamp() );
		$times = self::ask_times();
		for ( ; $d <= $e; $d = $d->modify( '+1 day' ) ) {
			foreach ( $times as $t ) {
				[ $h, $mi ] = array_map( 'intval', explode( ':', $t ) );
				if ( ! self::is_free( $d->setTime( $h, $mi )->getTimestamp(), $minutes, $busy ) ) {
					$out[ $d->format( 'Y-m-d' ) ][] = $t;
				}
			}
		}
		return $out;
	}

	public static function handle_request() {
		check_admin_referer( 'oys_private_request' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			oys_redirect( oys_account_url() );
		}
		$types    = self::location_types();
		$type     = sanitize_key( $_POST['location_type'] ?? 'home' );
		$duration = (int) ( $_POST['duration_min'] ?? 60 );
		$offered  = OYS_Services::offered();
		$topic    = sanitize_key( $_POST['topic'] ?? '' );
		$service  = $offered[ $topic ] ?? ( 1 === count( $offered ) && ! OYS_Products::has_plain_private() ? reset( $offered ) : null );
		$row      = array(
			'user_id'       => $user_id,
			'topic'         => $service ? $service['key'] : 'yoga',
			// a service has its own length (or one of its lengths), and is for one person or, with a friend, for two
			'duration_min'  => $service ? ( in_array( $duration, $service['durations'], true ) ? $duration : $service['duration'] ) : ( in_array( $duration, array( 60, 75, 90 ), true ) ? $duration : 60 ),
			'people'        => min( $service ? ( $service['duo'] ? 2 : 1 ) : 10, max( 1, (int) ( $_POST['people'] ?? 1 ) ) ),
			'location_type' => isset( $types[ $type ] ) ? $type : 'home',
			'address'       => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'preferred'     => sanitize_textarea_field( wp_unslash( $_POST['preferred'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'status'        => 'new',
			'created_at'    => oys_now(),
		);
		// the times chosen in the calendar (Áron, 19 November: „lehessen egyszerre több időpontot befoglalni ne csak egyet”)
		$slots = self::slots_from( implode( ',', array_map( 'sanitize_text_field', array_map( 'strval', (array) wp_unslash( $_POST['slots'] ?? array() ) ) ) ) );
		if ( ! $row['preferred'] && ! $slots ) {
			oys_flash( __( 'Tell me a few days and times that suit you.', 'olivia-studio' ), 'error' );
			oys_redirect( wp_get_referer() ?: oys_account_url( 'private' ) );
		}
		// Olívia, 19 November: „ha párban jönnének … a párnak is kötelező kitölteni a saját adatait … külön visszaigazoló
		// e-mailt kapjanak” (Áron: „e-mail cím alapján, hasonló legyen, mint a csoportos óráknál”) – two people: the partner's
		// name and email; when the session is booked, they become the booking's guest and get their own invite and link
		if ( 2 === (int) $row['people'] ) {
			$row['partner_name']  = mb_substr( sanitize_text_field( wp_unslash( $_POST['partner_name'] ?? '' ) ), 0, 100 );
			$row['partner_email'] = sanitize_email( wp_unslash( $_POST['partner_email'] ?? '' ) );
			if ( '' === $row['partner_name'] || ! is_email( $row['partner_email'] ) ) {
				oys_flash( __( 'Add your partner\'s name and email, so they get their own confirmation and link to join.', 'olivia-studio' ), 'error' );
				oys_redirect( wp_get_referer() ?: oys_account_url( 'private' ) );
			}
		}
		$problem = self::slots_problem( $slots, (int) $row['duration_min'] );
		if ( $problem ) {
			oys_flash( $problem, 'error' );
			oys_redirect( wp_get_referer() ?: oys_account_url( 'private' ) );
		}
		$request = self::create( $row, $slots );
		oys_flash( count( $slots ) > 1
			? sprintf( __( 'Your %d requests are sent. I\'ll confirm the times within one working day, then you can pay for them.', 'olivia-studio' ), count( $slots ) )
			: __( 'Request sent. I\'ll reply within one working day with a time and price.', 'olivia-studio' ) );
		oys_redirect( oys_account_url( 'private' ) );
	}

	/**
	 * What is wrong with the chosen times for a session this long: one taken in the meantime (a class, a one-to-one session,
	 * a time someone else asked for), too soon, or two of them overlapping. '' when they are fine.
	 */
	public static function slots_problem( array $slots, $minutes ) {
		$timed = array_values( array_filter( $slots, fn( $x ) => '' !== $x['time'] ) );
		if ( ! $timed ) {
			return '';
		}
		$ts    = array_map( array( __CLASS__, 'slot_ts' ), $timed );
		$busy  = self::busy( min( $ts ), max( $ts ) + DAY_IN_SECONDS );
		$taken = array();
		foreach ( $timed as $i => $sl ) {
			if ( ! self::is_free( $ts[ $i ], $minutes, $busy ) ) {
				$taken[] = self::slot_label( $sl );
			}
		}
		if ( $taken ) {
			return sprintf( _n( 'This time is no longer free: %s. Please choose another one.', 'These times are no longer free: %s. Please choose others.', count( $taken ), 'olivia-studio' ), implode( '; ', $taken ) );
		}
		$len = max( 30, (int) $minutes ) * MINUTE_IN_SECONDS;
		foreach ( $ts as $i => $a ) {
			foreach ( $ts as $j => $b ) {
				if ( $i < $j && abs( $a - $b ) < $len ) {
					return sprintf( __( 'Two of your times overlap for a %1$d-minute session: %2$s and %3$s. Please keep only one of them.', 'olivia-studio' ), (int) $minutes, self::slot_label( $timed[ $i ] ), self::slot_label( $timed[ $j ] ) );
				}
			}
		}
		return '';
	}

	/**
	 * Saves a request – one for each time chosen in the calendar, together as one batch (each can be offered, paid and held
	 * on its own; a time is held for others until it is answered) – and tells the customer and the studio once.
	 * @return object the (first) request
	 */
	public static function create( array $row, array $slots = array() ) {
		global $wpdb;
		$extra = (string) ( $row['preferred'] ?? '' );
		$ids   = array();
		foreach ( $slots ?: array( null ) as $sl ) {
			$one = $row;
			if ( $sl ) {
				$one['preferred'] = self::slot_label( $sl ) . ( '' !== $extra ? "\n" . $extra : '' );
				$one['slot_at']   = '' !== $sl['time'] ? gmdate( 'Y-m-d H:i:s', self::slot_ts( $sl ) ) : null;
				$one['batch_id']  = $ids ? $ids[0] : 0;
			}
			$wpdb->insert( OYS_Install::table( 'private_requests' ), $one );
			$ids[] = (int) $wpdb->insert_id;
		}
		if ( count( $ids ) > 1 ) {
			$wpdb->update( OYS_Install::table( 'private_requests' ), array( 'batch_id' => $ids[0] ), array( 'id' => $ids[0] ) );
		}
		$request = self::get( $ids[0] );
		// one email and one note in the conversation for all of them, with every time listed
		$all            = clone $request;
		$all->preferred = $slots ? implode( "\n", array_map( array( __CLASS__, 'slot_label' ), $slots ) ) . ( '' !== $extra ? "\n" . $extra : '' ) : $request->preferred;
		OYS_Emails::private_request_received( $all );
		do_action( 'oys_private_requested', $all ); // the Hub: the summary goes into the customer's conversation
		return $request;
	}

	/**
	 * Staff offers a concrete time and price. Creates (or updates) the one-person session.
	 * @return int|WP_Error session id
	 */
	public static function offer( $request_id, $starts_local, $duration_min, $price_cents, $location, $online_url = '', $message = '', $extra_min = 0 ) {
		$request = self::get( $request_id );
		if ( ! $request ) {
			return new WP_Error( 'oys_missing', __( 'Request not found.', 'olivia-studio' ) );
		}
		$start = oys_local_to_utc( $starts_local );
		if ( ! $start ) {
			return new WP_Error( 'oys_time', __( 'Choose a date and time.', 'olivia-studio' ) );
		}
		$service = OYS_Services::get( (string) ( $request->topic ?? '' ) );
		$duo     = $service && $service['duo'] && (int) $request->people > 1;
		$data = array(
			'kind'            => 'private',
			'class_slug'      => $service ? $service['class'] . ( $duo ? '-duo' : '' ) : 'private-yoga',
			'title'           => $service
				? sprintf( '%1$s (%2$d %3$s)', OYS_Services::label( $service['key'], $duo ? 2 : 1 ), $duration_min, __( 'min', 'olivia-studio' ) )
				: sprintf( __( 'Private session (%d min)', 'olivia-studio' ), $duration_min ),
			'starts_at'       => $start,
			// Extra minutes (first session: talking through goals) are blocked in the calendar, not charged.
			'ends_at'         => gmdate( 'Y-m-d H:i:s', oys_ts( $start ) + ( $duration_min + max( 0, (int) $extra_min ) ) * MINUTE_IN_SECONDS ),
			'note'            => $extra_min > 0 ? sprintf( __( 'Includes about %d extra minutes to talk through your goals.', 'olivia-studio' ), (int) $extra_min ) : '',
			'capacity'        => 1,
			'location'        => $location,
			'format'          => 'online' === $request->location_type || ( $online_url && ! $location ) ? 'online' : 'studio',
			'online_url'      => $online_url,
			'price_cents'     => $price_cents,
			// A private credit covers a 60-minute session, a service credit a session of one of the service's lengths
			// (each length has its own credits).
			'credits_allowed' => ( $service ? in_array( (int) $duration_min, $service['durations'], true ) : 60 === (int) $duration_min ) ? 1 : 0,
			'status'          => 'scheduled',
		);
		$session_id = OYS_Schedule::save( $data, $request->session_id ?: 0 );
		self::update( $request_id, array(
			'status'        => 'offered',
			'session_id'    => $session_id,
			'price_cents'   => $price_cents,
			'duration_min'  => $duration_min,
			'admin_message' => $message,
		) );
		OYS_Emails::private_offer( self::get( $request_id ), OYS_Schedule::get( $session_id ) );
		return $session_id;
	}

	/** Is this the customer's first private session (no private session booked before)? */
	public static function is_first( $user_id, $except_request = 0 ) {
		global $wpdb;
		return ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . OYS_Install::table( 'private_requests' ) . " WHERE user_id = %d AND status = 'booked' AND id <> %d LIMIT 1", $user_id, $except_request ) );
	}

	public static function decline( $request_id, $message = '' ) {
		$request = self::get( $request_id );
		if ( ! $request ) {
			return;
		}
		self::update( $request_id, array( 'status' => 'declined', 'admin_message' => $message ) );
		if ( $request->session_id ) {
			OYS_Schedule::save( array( 'status' => 'cancelled' ), $request->session_id );
		}
		OYS_Emails::private_declined( $request, $message );
	}

	public static function mark_paid( $request_id, $order_id ) {
		self::update( $request_id, array( 'status' => 'booked', 'order_id' => $order_id ) );
	}

	/**
	 * Booked with a private credit: mark the request as booked too. A session for two: the partner from the request joins the
	 * booking as its guest (once), and gets the guest's invite with their own link to join, like at a group class.
	 */
	public static function on_booking_confirmed( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = $b && ! (int) $b->guest_of ? OYS_Schedule::get( $b->session_id ) : null;
		if ( $s && 'private' === $s->kind ) {
			$r = self::for_session( $s->id );
			if ( $r && 'offered' === $r->status ) {
				self::update( $r->id, array( 'status' => 'booked' ) );
			}
			if ( $r && (int) $r->people > 1 && is_email( (string) ( $r->partner_email ?? '' ) ) && (int) $r->user_id === (int) $b->user_id && ! OYS_Bookings::guests_of( $b->id ) ) {
				OYS_Bookings::book_party( (int) $b->user_id, $s, array(
					'host_booking' => (int) $b->id,
					'guests'       => array( array( 'name' => (string) $r->partner_name, 'email' => (string) $r->partner_email ) ),
					'guest_method' => 'pair',
					'force'        => true,
				) );
			}
		}
	}

	/** Only the customer who requested a private session may book it. */
	public static function may_book( $user_id, $session ) {
		if ( 'private' !== $session->kind ) {
			return true;
		}
		$r = self::for_session( $session->id );
		return $r && (int) $r->user_id === (int) $user_id;
	}

	public static function statuses() {
		return array(
			'new'       => __( 'New', 'olivia-studio' ),
			'offered'   => __( 'Offer sent', 'olivia-studio' ),
			'booked'    => __( 'Booked', 'olivia-studio' ),
			'declined'  => __( 'Declined', 'olivia-studio' ),
			'cancelled' => __( 'Cancelled', 'olivia-studio' ),
		);
	}
}

OYS_Privates::init();
