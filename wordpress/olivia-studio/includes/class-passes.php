<?php
/**
 * Class passes and credits. A credit is spent atomically, earliest-expiring pass first.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Passes {

	public static function grant( $user_id, array $args ) {
		global $wpdb;
		$args = wp_parse_args( $args, array(
			'product_id'    => '',
			'name'          => __( 'Class credit', 'olivia-studio' ),
			'kind'          => 'class',
			'credits'       => 1,
			'validity_days' => 90,
			'order_id'      => 0,
			'source'        => 'purchase',
		) );
		$wpdb->insert( OYS_Install::table( 'passes' ), array(
			'user_id'       => (int) $user_id,
			'product_id'    => $args['product_id'],
			'name'          => $args['name'],
			'kind'          => $args['kind'],
			'credits_total' => (int) $args['credits'],
			'credits_left'  => (int) $args['credits'],
			'expires_at'    => $args['validity_days'] ? oys_utc_plus( (int) $args['validity_days'] * DAY_IN_SECONDS ) : null,
			'order_id'      => (int) $args['order_id'],
			'source'        => $args['source'],
			'created_at'    => oys_now(),
		) );
		return (int) $wpdb->insert_id;
	}

	public static function grant_product( $user_id, array $product, $order_id = 0, $source = 'purchase' ) {
		return self::grant( $user_id, array(
			'product_id'    => $product['id'],
			'name'          => $product['name'],
			'kind'          => OYS_Products::credit_kind( $product ),
			'credits'       => 'private_single' === $product['kind'] ? 1 : max( 1, (int) $product['credits'] ), // a single session is one credit
			'validity_days' => (int) $product['validity_days'],
			'order_id'      => $order_id,
			'source'        => $source,
		) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'passes' ) . ' WHERE id = %d', $id ) );
	}

	public static function for_user( $user_id, $only_valid = false ) {
		global $wpdb;
		$t   = OYS_Install::table( 'passes' );
		$sql = $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d", $user_id );
		if ( $only_valid ) {
			$sql .= $wpdb->prepare( ' AND credits_left > 0 AND ( expires_at IS NULL OR expires_at > %s )', oys_now() );
		}
		return $wpdb->get_results( $sql . ' ORDER BY ( expires_at IS NULL ), expires_at ASC, id ASC' );
	}

	/** Credits usable for a session (checks kind and that the pass is still valid on class day). */
	public static function usable( $user_id, $kind, $session = null ) {
		global $wpdb;
		$t     = OYS_Install::table( 'passes' );
		$valid = $session ? $session->starts_at : oys_now();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $t WHERE user_id = %d AND kind = %s AND credits_left > 0 AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY ( expires_at IS NULL ), expires_at ASC, id ASC",
			$user_id, $kind, $valid
		) );
	}

	public static function balance( $user_id, $kind = 'class' ) {
		return array_sum( array_map( fn( $p ) => (int) $p->credits_left, self::usable( $user_id, $kind ) ) );
	}

	/** Spend one credit. Returns the pass id used, or 0 if none was available. */
	public static function consume( $user_id, $kind, $session = null ) {
		global $wpdb;
		$t = OYS_Install::table( 'passes' );
		foreach ( self::usable( $user_id, $kind, $session ) as $pass ) {
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET credits_left = credits_left - 1 WHERE id = %d AND credits_left > 0", $pass->id ) );
			if ( 1 === (int) $ok ) {
				return (int) $pass->id;
			}
		}
		return 0;
	}

	/**
	 * Classes of this session the customer can pay with passes. For an online class that's their
	 * online credits plus what their studio credits convert to (one studio class = `online_per_credit`
	 * online classes).
	 */
	public static function available_for( $user_id, $session, $mode = 'studio' ) {
		$kind = OYS_Bookings::credit_kind( $session, $mode );
		$n    = 0;
		foreach ( self::usable( $user_id, $kind, $session ) as $p ) {
			$n += (int) $p->credits_left;
		}
		if ( 'online' === $kind ) {
			foreach ( self::usable( $user_id, 'class', $session ) as $p ) {
				$n += (int) $p->credits_left * self::online_per_credit();
			}
		}
		return $n;
	}

	public static function online_per_credit() {
		return max( 1, (int) OYS_Settings::get( 'online_per_credit' ) );
	}

	/**
	 * Spend one class of a pass on this session. For an online class with no online credits left,
	 * one studio credit is converted into `online_per_credit` online credits (same expiry, shown
	 * as its own pass) and one of those is used, so a studio pass goes further on online classes.
	 * Returns the pass id used, or 0.
	 */
	public static function consume_for( $user_id, $session, $mode = 'studio' ) {
		$kind = OYS_Bookings::credit_kind( $session, $mode );
		$id   = self::consume( $user_id, $kind, $session );
		if ( $id || 'online' !== $kind ) {
			return $id;
		}
		$from = self::consume( $user_id, 'class', $session );
		if ( ! $from ) {
			return 0;
		}
		$src  = self::get( $from );
		$n    = self::online_per_credit();
		$days = $src->expires_at ? max( 1, (int) ceil( ( oys_ts( $src->expires_at ) - time() ) / DAY_IN_SECONDS ) ) : 0;
		$new  = self::grant( $user_id, array(
			'product_id'    => $src->product_id,
			'name'          => sprintf( __( 'Online classes (from %s)', 'olivia-studio' ), $src->name ),
			'kind'          => 'online',
			'credits'       => $n,
			'validity_days' => $days,
			'order_id'      => (int) $src->order_id,
			'source'        => 'convert',
		) );
		global $wpdb;
		if ( $src->expires_at ) {
			$wpdb->update( OYS_Install::table( 'passes' ), array( 'expires_at' => $src->expires_at ), array( 'id' => $new ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'passes' ) . ' SET credits_left = credits_left - 1 WHERE id = %d AND credits_left > 0', $new ) );
		return $new;
	}

	public static function kinds() {
		return array(
			'class'   => __( 'Group classes', 'olivia-studio' ),
			'online'  => __( 'Online classes', 'olivia-studio' ),
			'private' => __( 'Private sessions', 'olivia-studio' ),
		) + self::service_kinds();
	}

	/** Credit kinds of the one-to-one services (OYS_Services): the service, and the service with a friend. */
	public static function service_kinds() {
		$out = array();
		foreach ( OYS_Services::all() as $key => $s ) {
			foreach ( $s['durations'] as $m ) {
				$out[ OYS_Services::credit_kind( $key, 1, $m ) ] = OYS_Services::label( $key, 1, $m );
				if ( $s['duo'] ) {
					$out[ OYS_Services::credit_kind( $key, 2, $m ) ] = OYS_Services::label( $key, 2, $m );
				}
			}
		}
		return $out;
	}

	/**
	 * The studio cancelled a group class: every pass that could have paid for it and runs out by a date is valid
	 * that many days longer (setting `cancel_extend_days`, 7 = the next week's class). Olivia: „amennyiben én mondom
	 * le, az érvényesség kitolódik”. Returns how many passes were extended.
	 */
	public static function extend_for_cancelled( $session ) {
		$days = (int) OYS_Settings::get( 'cancel_extend_days' );
		if ( $days <= 0 || ! $session || ! $session->credits_allowed ) {
			return 0;
		}
		if ( 'private' === $session->kind ) {
			return self::extend_private_for_cancelled( $session, $days );
		}
		if ( ! in_array( $session->kind, array( 'group', 'event' ), true ) ) {
			return 0;
		}
		$kinds = 'online' === $session->format ? array( 'online', 'class' ) : ( 'hybrid' === $session->format ? array( 'class', 'online' ) : array( 'class' ) );
		global $wpdb;
		$t   = OYS_Install::table( 'passes' );
		$in  = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$sql = $wpdb->prepare(
			"UPDATE $t SET expires_at = DATE_ADD(expires_at, INTERVAL %d DAY) WHERE kind IN ($in) AND credits_left > 0 AND expires_at IS NOT NULL AND expires_at > %s AND created_at < %s", // phpcs:ignore
			array_merge( array( $days ), $kinds, array( oys_now(), $session->starts_at ) )
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore
	}

	/**
	 * The studio cancelled a private session (Olivia, 13 November: „Oktatói lemondás esetén 1 héttel hosszabb
	 * érvényesség” on the one-to-one passes too): the booked person's passes of that kind run that many days longer.
	 */
	private static function extend_private_for_cancelled( $session, $days ) {
		$users = array_unique( array_map( fn( $b ) => (int) $b->user_id, OYS_Bookings::for_session( $session->id ) ) );
		$users = array_filter( $users );
		if ( ! $users ) {
			return 0;
		}
		global $wpdb;
		$t   = OYS_Install::table( 'passes' );
		$in  = implode( ',', array_fill( 0, count( $users ), '%d' ) );
		$sql = $wpdb->prepare(
			"UPDATE $t SET expires_at = DATE_ADD(expires_at, INTERVAL %d DAY) WHERE kind = %s AND user_id IN ($in) AND credits_left > 0 AND expires_at IS NOT NULL AND expires_at > %s", // phpcs:ignore
			array_merge( array( $days, OYS_Bookings::credit_kind( $session ) ), $users, array( oys_now() ) )
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore
	}

	/** Give a credit back. If the original pass has expired, the credit comes back as a fresh 30-day credit. */
	public static function refund_credit( $pass_id, $user_id ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( $pass && ( ! $pass->expires_at || oys_ts( $pass->expires_at ) > time() + DAY_IN_SECONDS ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'passes' ) . ' SET credits_left = LEAST(credits_total, credits_left + 1) WHERE id = %d', $pass_id ) );
			return (int) $pass_id;
		}
		return self::grant( $user_id, array(
			'name'          => __( 'Returned class credit', 'olivia-studio' ),
			'kind'          => $pass ? $pass->kind : 'class',
			'credits'       => 1,
			'validity_days' => 30,
			'source'        => 'refund',
		) );
	}

	public static function adjust( $pass_id, $credits_left, $expires_at = null ) {
		global $wpdb;
		$row = array( 'credits_left' => max( 0, (int) $credits_left ) );
		if ( null !== $expires_at ) {
			$row['expires_at'] = $expires_at ?: null;
		}
		$wpdb->update( OYS_Install::table( 'passes' ), $row, array( 'id' => (int) $pass_id ) );
	}
}
