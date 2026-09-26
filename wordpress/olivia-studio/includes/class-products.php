<?php
/**
 * Things people can buy besides a single drop-in: class passes, private-session packs,
 * single private sessions (used for gifts and as offer defaults) and an optional intro offer.
 *
 * kind:
 *   pack           class credits (group classes and events that allow credits; one also covers
 *                  `online_per_credit` online classes)
 *   online_pack    online class credits (live-streamed group classes only)
 *   intro          class credits, only for customers who have never booked or bought before
 *   private_pack   private-session credits (one credit = one 60-minute private session)
 *   private_single price of one private session of `duration_min` (and `online_price_cents` when it's
 *                  online); giftable as one private credit
 *   membership     recurring Stripe subscription; `classes_per_period` group classes per billing
 *                  period (0 = unlimited), billed every `interval_count` × `interval` (month|year)
 */

defined( 'ABSPATH' ) || exit;

class OYS_Products {

	const OPTION = 'oys_products';

	public static function defaults() {
		return array(
			'intro-2'     => array( 'name' => 'New student intro: 2 classes', 'kind' => 'intro', 'credits' => 2, 'validity_days' => 30, 'price_cents' => 3000, 'description' => 'Two group classes within 30 days, for your first visit.', 'features' => "2 group classes\nUse within 30 days\nNew students only", 'featured' => 0, 'giftable' => 0, 'active' => 0, 'duration_min' => 0, 'sort' => 5 ),
			'pack-5'      => array( 'name' => '5-class pass', 'kind' => 'pack', 'credits' => 5, 'validity_days' => 90, 'price_cents' => 11000, 'description' => 'Five group classes, in person or online. Save $15.', 'features' => "Save \$15\nUse for any group class\nValid for 90 days\nAlso available as a gift card", 'featured' => 1, 'giftable' => 1, 'active' => 1, 'duration_min' => 0, 'sort' => 10 ),
			'online-10'   => array( 'name' => 'Online 10-class pass', 'kind' => 'online_pack', 'credits' => 10, 'validity_days' => 180, 'price_cents' => 5000, 'description' => 'Ten live online classes, $5 a class.', 'features' => "10 live online classes\nJoin from anywhere\nValid for 6 months", 'featured' => 0, 'giftable' => 0, 'active' => 1, 'duration_min' => 0, 'sort' => 11 ),
			'private-60'  => array( 'name' => 'Private session, 60 minutes', 'kind' => 'private_single', 'credits' => 1, 'validity_days' => 365, 'price_cents' => 9500, 'online_price_cents' => 6500, 'description' => 'One-on-one at your home, lanai, clubhouse, on the beach or online.', 'features' => "At your home, lanai, beach or online\nPlanned around your goals", 'featured' => 0, 'giftable' => 1, 'active' => 1, 'duration_min' => 60, 'sort' => 20 ),
			'private-75'  => array( 'name' => 'Private session, 75 minutes', 'kind' => 'private_single', 'credits' => 1, 'validity_days' => 365, 'price_cents' => 12000, 'online_price_cents' => 8000, 'description' => '', 'features' => '', 'featured' => 0, 'giftable' => 0, 'active' => 1, 'duration_min' => 75, 'sort' => 21 ),
			'private-90'  => array( 'name' => 'Private session, 90 minutes', 'kind' => 'private_single', 'credits' => 1, 'validity_days' => 365, 'price_cents' => 14000, 'online_price_cents' => 9500, 'description' => '', 'features' => '', 'featured' => 0, 'giftable' => 0, 'active' => 1, 'duration_min' => 90, 'sort' => 22 ),
			'unlimited-monthly' => array( 'name' => 'Unlimited monthly', 'kind' => 'membership', 'credits' => 0, 'validity_days' => 0, 'price_cents' => 11900, 'description' => 'Every group class, every week. Renews monthly; cancel any time.', 'features' => "Unlimited group classes\nIn person or online\nCancel any time", 'featured' => 0, 'giftable' => 0, 'active' => 1, 'duration_min' => 0, 'sort' => 12, 'interval' => 'month', 'interval_count' => 1, 'classes_per_period' => 0 ),
			'four-a-month'      => array( 'name' => '4 classes a month', 'kind' => 'membership', 'credits' => 0, 'validity_days' => 0, 'price_cents' => 8500, 'description' => 'A steady weekly practice. Renews monthly; cancel any time.', 'features' => "4 group classes each month\nIn person or online\nCancel any time", 'featured' => 0, 'giftable' => 0, 'active' => 1, 'duration_min' => 0, 'sort' => 13, 'interval' => 'month', 'interval_count' => 1, 'classes_per_period' => 4 ),
			'private-5'   => array( 'name' => '5 private sessions', 'kind' => 'private_pack', 'credits' => 5, 'validity_days' => 365, 'price_cents' => 42500, 'description' => 'Five 60-minute private sessions. Save $50.', 'features' => "5 × 60 minutes\nSave \$50\nA plan that builds session by session", 'featured' => 0, 'giftable' => 1, 'active' => 1, 'duration_min' => 60, 'sort' => 30 ),
		);
	}

	public static function ensure_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	public static function all( $only_active = false ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		foreach ( $all as $id => &$p ) {
			$p       = wp_parse_args( $p, array( 'name' => $id, 'kind' => 'pack', 'credits' => 1, 'validity_days' => 90, 'price_cents' => 0, 'description' => '', 'features' => '', 'featured' => 0, 'giftable' => 0, 'active' => 1, 'duration_min' => 0, 'sort' => 50, 'interval' => 'month', 'interval_count' => 1, 'classes_per_period' => 0, 'online_price_cents' => 0 ) );
			$p['id'] = $id;
		}
		unset( $p );
		if ( $only_active ) {
			$all = array_filter( $all, fn( $p ) => ! empty( $p['active'] ) );
		}
		uasort( $all, fn( $a, $b ) => (int) $a['sort'] <=> (int) $b['sort'] );
		return $all;
	}

	public static function get( $id ) {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	public static function save( $id, array $data ) {
		$all        = get_option( self::OPTION, array() );
		$all[ $id ] = $data;
		update_option( self::OPTION, $all );
	}

	public static function delete( $id ) {
		$all = get_option( self::OPTION, array() );
		unset( $all[ $id ] );
		update_option( self::OPTION, $all );
	}

	/** Products a customer can buy on their own (not private singles, which are booked through a request). */
	public static function purchasable() {
		return array_filter( self::all( true ), fn( $p ) => in_array( $p['kind'], array( 'pack', 'intro', 'online_pack', 'private_pack' ), true ) );
	}

	public static function memberships( $only_active = true ) {
		return array_filter( self::all( $only_active ), fn( $p ) => 'membership' === $p['kind'] );
	}

	/** After an upgrade: add default products of kinds the site doesn't have yet (e.g. memberships, online passes). */
	public static function add_missing_kinds() {
		$all   = get_option( self::OPTION, array() );
		$all   = is_array( $all ) ? $all : array();
		$kinds = array_map( fn( $p ) => $p['kind'] ?? '', $all );
		foreach ( self::defaults() as $id => $p ) {
			if ( ! in_array( $p['kind'], $kinds, true ) && ! isset( $all[ $id ] ) ) {
				$all[ $id ] = $p;
			}
			// Online prices for private sessions that were set up before online pricing existed.
			if ( 'private_single' === $p['kind'] && isset( $all[ $id ] ) && ! isset( $all[ $id ]['online_price_cents'] ) ) {
				$all[ $id ]['online_price_cents'] = $p['online_price_cents'];
			}
		}
		update_option( self::OPTION, $all );
	}

	/** "per month", "every 3 months", "per year" */
	public static function period_label( $product ) {
		$n    = max( 1, (int) ( $product['interval_count'] ?? 1 ) );
		$unit = 'year' === ( $product['interval'] ?? 'month' ) ? 'year' : 'month';
		if ( 1 === $n ) {
			return 'year' === $unit ? __( 'per year', 'olivia-studio' ) : __( 'per month', 'olivia-studio' );
		}
		return sprintf( 'year' === $unit ? _n( 'every %d year', 'every %d years', $n, 'olivia-studio' ) : _n( 'every %d month', 'every %d months', $n, 'olivia-studio' ), $n );
	}

	public static function giftable() {
		return array_filter( self::all( true ), fn( $p ) => ! empty( $p['giftable'] ) );
	}

	public static function credit_kind( $product ) {
		if ( 'online_pack' === $product['kind'] ) {
			return 'online';
		}
		return in_array( $product['kind'], array( 'private_pack', 'private_single' ), true ) ? 'private' : 'class';
	}

	/** Price of one private session of this length, in person or online (online falls back to the in-person price). */
	public static function private_price_for( $duration, $online = false ) {
		foreach ( self::all( true ) as $p ) {
			if ( 'private_single' === $p['kind'] && (int) $p['duration_min'] === (int) $duration ) {
				return $online && (int) $p['online_price_cents'] ? (int) $p['online_price_cents'] : (int) $p['price_cents'];
			}
		}
		return 0;
	}

	public static function kinds() {
		return array(
			'pack'           => __( 'Class pass (group classes)', 'olivia-studio' ),
			'intro'          => __( 'Intro offer (new students only)', 'olivia-studio' ),
			'online_pack'    => __( 'Online class pass', 'olivia-studio' ),
			'private_pack'   => __( 'Private session pack', 'olivia-studio' ),
			'private_single' => __( 'Single private session (price list and gifts)', 'olivia-studio' ),
			'membership'     => __( 'Membership (monthly or yearly subscription)', 'olivia-studio' ),
		);
	}
}
