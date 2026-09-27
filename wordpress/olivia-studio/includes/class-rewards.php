<?php
/**
 * Little thank-yous: a birthday discount code, and the loyalty draw.
 *
 * Birthday: customers may add their birthday (month and day) in their profile. On the day, they
 * get a personal code (`birthday_percent` off, valid `birthday_days`), once a year.
 *
 * Loyalty draw: every class a customer comes to in a period (half-year by default) is one ticket.
 * When the period ends, one ticket is drawn at random (weighted by tickets), the winner gets a free
 * pass (`raffle_prize`), and the newsletter subscribers get an announcement without the name:
 * the draw happened and a new period has started. One row per period in the `raffles` table.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Rewards {

	/* ---------- Birthday ---------- */

	/** 'MM-DD' or '' from user meta. */
	public static function birthday( $user_id ) {
		$b = (string) get_user_meta( $user_id, 'oys_birthday', true );
		return preg_match( '/^\d{2}-\d{2}$/', $b ) ? $b : '';
	}

	public static function set_birthday( $user_id, $month, $day ) {
		$month = (int) $month;
		$day   = (int) $day;
		if ( ! $month || ! $day || ! checkdate( $month, $day, 2024 ) ) { // 2024: leap year, so 02-29 is allowed.
			delete_user_meta( $user_id, 'oys_birthday' );
			return;
		}
		update_user_meta( $user_id, 'oys_birthday', sprintf( '%02d-%02d', $month, $day ) );
	}

	/** Cron: birthday codes for today's birthdays (studio time). @return int codes sent */
	public static function send_birthdays() {
		if ( ! (int) OYS_Settings::get( 'birthday_on' ) ) {
			return 0;
		}
		$today = wp_date( 'm-d' );
		$days  = array( $today );
		if ( '02-28' === $today && ! checkdate( 2, 29, (int) wp_date( 'Y' ) ) ) {
			$days[] = '02-29'; // Leap-day birthdays are celebrated on the 28th.
		}
		$year  = wp_date( 'Y' );
		$users = get_users( array(
			'fields'     => 'ID',
			'meta_query' => array( array( 'key' => 'oys_birthday', 'value' => $days, 'compare' => 'IN' ) ),
		) );
		$sent = 0;
		foreach ( $users as $user_id ) {
			if ( get_user_meta( $user_id, 'oys_birthday_sent', true ) === $year ) {
				continue;
			}
			update_user_meta( $user_id, 'oys_birthday_sent', $year );
			$percent = max( 1, min( 100, (int) OYS_Settings::get( 'birthday_percent' ) ) );
			$expires = oys_utc_plus( max( 1, (int) OYS_Settings::get( 'birthday_days' ) ) * DAY_IN_SECONDS );
			$id      = OYS_Coupons::create( array( 'kind' => 'percent', 'value' => $percent, 'applies' => 'all', 'max_uses' => 1, 'user_id' => $user_id, 'expires' => $expires, 'source' => 'birthday', 'prefix' => 'BDAY', 'note' => sprintf( 'Birthday %s', $year ) ) );
			if ( ! is_wp_error( $id ) ) {
				OYS_Emails::birthday( $user_id, OYS_Coupons::get( $id ) );
				$sent++;
			}
		}
		return $sent;
	}

	/* ---------- Loyalty draw ---------- */

	/** Months per period: 6 (half-year), 12 (year), 3 (quarter); 0 = off. */
	public static function period_months() {
		return array( 'quarter' => 3, 'half' => 6, 'year' => 12 )[ OYS_Settings::get( 'raffle_period' ) ] ?? 0;
	}

	/**
	 * The period a moment falls in: [ key, start UTC, end UTC, label ].
	 * Periods follow the calendar in studio time: Jan–Jun and Jul–Dec for half-years.
	 */
	public static function period( $ts = null, $months = null ) {
		$months = $months ?: max( 1, self::period_months() );
		$tz     = wp_timezone();
		$now    = ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz );
		$y      = (int) $now->format( 'Y' );
		$idx    = intdiv( (int) $now->format( 'n' ) - 1, $months );
		$start  = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $y, $idx * $months + 1 ), $tz );
		$end    = $start->modify( "+$months months" );
		$key    = 12 === $months ? (string) $y : ( 6 === $months ? $y . '-H' . ( $idx + 1 ) : $y . '-Q' . ( $idx + 1 ) );
		$utc    = new DateTimeZone( 'UTC' );
		$label  = 12 === $months ? (string) $y : $start->format( 'M' ) . '–' . $end->modify( '-1 day' )->format( 'M Y' );
		return array( $key, $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $label );
	}

	/** Tickets per customer: classes they came to (or were booked into and didn't miss) that ended in the period. */
	public static function standings( $start, $end, $limit = 0 ) {
		global $wpdb;
		$b   = OYS_Install::table( 'bookings' );
		$s   = OYS_Install::table( 'sessions' );
		$sql = $wpdb->prepare(
			"SELECT b.user_id, COUNT(*) AS tickets FROM $b b JOIN $s s ON s.id = b.session_id
			WHERE b.guest_of = 0 AND b.status IN ('attended','confirmed') AND s.status = 'scheduled'
			AND s.ends_at >= %s AND s.ends_at < %s AND s.ends_at <= %s GROUP BY b.user_id ORDER BY tickets DESC, b.user_id",
			$start, $end, oys_now()
		);
		return $wpdb->get_results( $sql . ( $limit ? ' LIMIT ' . (int) $limit : '' ) );
	}

	public static function tickets( $user_id, $ts = null ) {
		[ , $start, $end ] = self::period( $ts );
		foreach ( self::standings( $start, $end ) as $row ) {
			if ( (int) $row->user_id === (int) $user_id ) {
				return (int) $row->tickets;
			}
		}
		return 0;
	}

	public static function draws( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . OYS_Install::table( 'raffles' ) . ' ORDER BY ends_at DESC LIMIT ' . (int) $limit );
	}

	/** Cron: draw the period that just ended (once). @return object|null the draw */
	public static function run_draw() {
		if ( ! self::period_months() ) {
			return null;
		}
		// The period before the current one.
		[ , $current_start ] = self::period();
		return self::draw( oys_ts( $current_start ) - HOUR_IN_SECONDS );
	}

	/**
	 * Draw the period that contains $ts (it must have ended). Each ticket is one chance.
	 * @return object|null the raffles row, or null when already drawn / not ended yet
	 */
	public static function draw( $ts ) {
		global $wpdb;
		[ $key, $start, $end, $label ] = self::period( $ts );
		if ( oys_ts( $end ) > time() ) {
			return null;
		}
		$t = OYS_Install::table( 'raffles' );
		// The unique period key makes the draw happen once, even with two cron runs at the same time.
		if ( ! $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (period_key, label, starts_at, ends_at, drawn_at) VALUES (%s, %s, %s, %s, %s)", $key, $label, $start, $end, oys_now() ) ) ) {
			return null;
		}
		$id    = (int) $wpdb->insert_id;
		$rows  = self::standings( $start, $end );
		$total = array_sum( array_map( fn( $r ) => (int) $r->tickets, $rows ) );
		$row   = array( 'tickets_total' => $total, 'entrants' => count( $rows ) );
		if ( $total ) {
			$w                     = self::pick( $rows );
			$row['winner_id']      = (int) $w->user_id;
			$row['winner_tickets'] = (int) $w->tickets;
			$product = OYS_Products::get( OYS_Settings::get( 'raffle_prize' ) );
			if ( $product ) {
				$row['pass_id'] = OYS_Passes::grant_product( $row['winner_id'], $product, 0, 'raffle' );
			}
			OYS_Emails::raffle_winner( $row['winner_id'], $product, $row['winner_tickets'], $label );
			if ( (int) OYS_Settings::get( 'raffle_announce' ) ) {
				$row['newsletter_id'] = OYS_Newsletter::announce_draw( $label, $total, count( $rows ), $product );
			}
		}
		$wpdb->update( $t, $row, array( 'id' => $id ) );
		do_action( 'oys_raffle_drawn', $id );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
	}

	/** One ticket at random: each ticket is one chance. $rows = standings(). */
	public static function pick( array $rows ) {
		$total = array_sum( array_map( fn( $r ) => (int) $r->tickets, $rows ) );
		$n     = random_int( 1, max( 1, $total ) );
		foreach ( $rows as $r ) {
			$n -= (int) $r->tickets;
			if ( $n <= 0 ) {
				return $r;
			}
		}
		return end( $rows );
	}

	/** For the account and the app: this period's tickets and when it's drawn. */
	public static function status( $user_id ) {
		if ( ! self::period_months() ) {
			return null;
		}
		[ , , $end, $label ] = self::period();
		return array( 'tickets' => self::tickets( $user_id ), 'label' => $label, 'draw' => wp_date( 'M j, Y', oys_ts( $end ) ), 'prize' => ( OYS_Products::get( OYS_Settings::get( 'raffle_prize' ) )['name'] ?? '' ) );
	}
}
