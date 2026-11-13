<?php
/**
 * One-to-one services (Olivia, 2026-11-12): individual online yoga, the prenatal class, stretching, relaxation and
 * breathing … Each is booked through the private-session request, has its own length, may be for two people (with
 * a friend), and has its own passes: a credit pays only for a session of its own service.
 *
 *  - Services: option `oys_services` (key => label, class, duration, duo, description, sort), set by the site's
 *    edition (the theme's content file, `setup.services`); filter `oys_services`.
 *  - Products of kind private_single / private_pack with a `service` belong to it; `people` 2 = for two people.
 *  - A session of a service: kind `private`, class_slug = the service's class (+ "-duo" for two people).
 *  - Credit kind: the service key (+ "_duo"). Private sessions without a service (private yoga as before) keep
 *    the `private` credits.
 *  - Lengths (Olivia, 13 November: „itt lett egy új konstrukció: 90 perc”): a service may come in more than one length
 *    (`durations`, e.g. "60,90"; the first is `duration`). A session of another length has its own credits
 *    (service key + "_90"), so a 60-minute pass never pays for a 90-minute session.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Services {

	const OPTION = 'oys_services';

	public static function all() {
		$raw = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $s ) {
			$key = sanitize_key( $key );
			if ( '' === $key || 'yoga' === $key || 'private' === $key ) {
				continue; // reserved: private yoga without a service
			}
			$out[ $key ] = self::shape( $key, (array) $s );
		}
		// Sites from 1.5.0 had the prenatal class without a list of services.
		if ( ! isset( $out['prenatal'] ) && array_filter( get_option( OYS_Products::OPTION, array() ) ?: array(), fn( $p ) => str_starts_with( (string) ( $p['kind'] ?? '' ), 'prenatal_' ) || 'prenatal' === ( $p['service'] ?? '' ) ) ) {
			$out['prenatal'] = self::shape( 'prenatal', array( 'label' => __( 'Prenatal session', 'olivia-studio' ), 'class' => 'kismamatorna', 'duration' => 60, 'duo' => 1 ) );
		}
		uasort( $out, fn( $a, $b ) => (int) $a['sort'] <=> (int) $b['sort'] );
		return apply_filters( 'oys_services', $out );
	}

	private static function shape( $key, array $s ) {
		$s        = wp_parse_args( $s, array( 'label' => $key, 'class' => $key, 'duration' => 60, 'durations' => '', 'duo' => 0, 'description' => '', 'sort' => 50 ) );
		$s['key'] = $key;
		$s['duration'] = max( 15, (int) $s['duration'] );
		$s['duo']      = empty( $s['duo'] ) ? 0 : 1;
		$list          = array_map( 'intval', is_array( $s['durations'] ) ? $s['durations'] : explode( ',', (string) $s['durations'] ) );
		$s['durations'] = array_values( array_unique( array_merge( array( $s['duration'] ), array_filter( $list, fn( $m ) => $m >= 15 ) ) ) );
		return $s;
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Services that have an active product (they appear on the price list and in the request form). */
	public static function offered() {
		$keys = array();
		foreach ( OYS_Products::all( true ) as $p ) {
			if ( '' !== $p['service'] ) {
				$keys[ $p['service'] ] = true;
			}
		}
		return array_intersect_key( self::all(), $keys );
	}

	/** The credit kind of a service session: the service key, "_duo" for two people, "_90" for another length. */
	public static function credit_kind( $key, $people = 1, $minutes = 0 ) {
		$s = (int) $minutes ? self::get( $key ) : null;
		return $key . ( (int) $people > 1 ? '_duo' : '' ) . ( $s && (int) $minutes !== (int) $s['duration'] ? '_' . (int) $minutes : '' );
	}

	/** Is this one of the service's lengths? */
	public static function has_length( $key, $minutes ) {
		$s = self::get( $key );
		return $s && in_array( (int) $minutes, $s['durations'], true );
	}

	/** [service key, people] of a session's class, or null (private yoga, group classes). */
	public static function for_class( $class_slug ) {
		foreach ( self::all() as $key => $s ) {
			if ( $s['class'] === $class_slug ) {
				return array( $key, 1 );
			}
			if ( $s['class'] . '-duo' === $class_slug ) {
				return array( $key, 2 );
			}
		}
		return null;
	}

	/** Price of one session of a service, alone or for two, of a length (the service's private_single product). */
	public static function price( $key, $people = 1, $minutes = 0 ) {
		$people  = (int) $people > 1 ? 2 : 1;
		$s       = self::get( $key );
		$minutes = (int) $minutes ?: ( $s ? (int) $s['duration'] : 0 );
		foreach ( OYS_Products::all( true ) as $p ) {
			if ( 'private_single' === $p['kind'] && $key === $p['service'] && (int) $p['people'] === $people && self::product_minutes( $p ) === $minutes ) {
				return (int) $p['price_cents'];
			}
		}
		return 0;
	}

	/** A service product's length: its own, or the service's. */
	public static function product_minutes( array $p ) {
		$s = ! empty( $p['service'] ) ? self::get( $p['service'] ) : null;
		return (int) ( $p['duration_min'] ?? 0 ) ?: ( $s ? (int) $s['duration'] : 60 );
	}

	/** The lengths a service is sold in (those with an active product), shortest first. */
	public static function lengths( $key ) {
		$out = array();
		foreach ( OYS_Products::all( true ) as $p ) {
			if ( $key === ( $p['service'] ?? '' ) && in_array( $p['kind'], array( 'private_single', 'private_pack' ), true ) ) {
				$out[ self::product_minutes( $p ) ] = true;
			}
		}
		$out = array_keys( $out );
		sort( $out );
		return $out;
	}

	public static function label( $key, $people = 1, $minutes = 0 ) {
		$s = self::get( $key );
		if ( ! $s ) {
			return __( 'Private session', 'olivia-studio' );
		}
		$label = oys_t( $s['label'] );
		$label = (int) $people > 1 ? sprintf( __( '%s, with a friend', 'olivia-studio' ), $label ) : $label;
		return (int) $minutes && (int) $minutes !== (int) $s['duration'] ? sprintf( __( '%1$s, %2$d minutes', 'olivia-studio' ), $label, (int) $minutes ) : $label;
	}
}
