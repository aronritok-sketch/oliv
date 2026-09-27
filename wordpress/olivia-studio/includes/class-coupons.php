<?php
/**
 * Discount codes for card payments: passes and drop-in classes (not memberships).
 *
 * A coupon takes a percentage or a fixed amount off, can be for everyone or one customer
 * (birthday gifts), has a number of uses and an expiry date. The code is checked when the
 * customer continues to payment; the discount is shown on the Stripe receipt line and stored on
 * the order (meta coupon_id, coupon_code, discount_cents); a use is counted when the payment
 * arrives.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Coupons {

	public static function init() {
		add_action( 'oys_order_paid', array( __CLASS__, 'on_order_paid' ) );
	}

	public static function applies_options() {
		return array(
			'all'     => __( 'Passes and classes', 'olivia-studio' ),
			'passes'  => __( 'Passes only', 'olivia-studio' ),
			'classes' => __( 'Drop-in classes only', 'olivia-studio' ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'coupons' ) . ' WHERE id = %d', $id ) );
	}

	public static function by_code( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'coupons' ) . ' WHERE code = %s', self::normalize( $code ) ) );
	}

	public static function normalize( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $code ) );
	}

	/** A code nobody has yet, e.g. BDAY-7KQ2MX. */
	public static function generate_code( $prefix = 'OY' ) {
		do {
			$code = self::normalize( $prefix ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
			$code = strtr( $code, array( 'O' => 'Q', '0' => '8', 'I' => 'J', '1' => '7', 'L' => 'K' ) );
		} while ( self::by_code( $code ) );
		return $code;
	}

	/**
	 * $args: code (generated when empty), kind percent|amount, value (percent or cents),
	 * applies all|passes|classes, max_uses, user_id (0 = anyone), expires (UTC or null), source, note
	 * @return int|WP_Error coupon id
	 */
	public static function create( array $args ) {
		global $wpdb;
		$args = wp_parse_args( $args, array( 'code' => '', 'kind' => 'percent', 'value' => 10, 'applies' => 'all', 'max_uses' => 1, 'user_id' => 0, 'expires' => null, 'source' => 'manual', 'note' => '', 'prefix' => 'OY' ) );
		$code = self::normalize( $args['code'] ) ?: self::generate_code( $args['prefix'] );
		if ( self::by_code( $code ) ) {
			return new WP_Error( 'oys_coupon', __( 'That code already exists.', 'olivia-studio' ) );
		}
		$kind  = 'amount' === $args['kind'] ? 'amount' : 'percent';
		$value = 'percent' === $kind ? min( 100, max( 1, (int) $args['value'] ) ) : max( 1, (int) $args['value'] );
		$wpdb->insert( OYS_Install::table( 'coupons' ), array(
			'code'       => $code,
			'user_id'    => (int) $args['user_id'],
			'kind'       => $kind,
			'value'      => $value,
			'applies'    => isset( self::applies_options()[ $args['applies'] ] ) ? $args['applies'] : 'all',
			'max_uses'   => max( 0, (int) $args['max_uses'] ),
			'used'       => 0,
			'expires_at' => $args['expires'] ?: null,
			'source'     => sanitize_key( $args['source'] ),
			'note'       => sanitize_text_field( $args['note'] ),
			'created_at' => oys_now(),
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Can this customer use the code for this purchase? $for = 'passes' | 'classes'.
	 * @return object|WP_Error the coupon
	 */
	public static function validate( $code, $user_id, $for ) {
		$c = self::by_code( $code );
		if ( ! $c ) {
			return new WP_Error( 'oys_coupon', __( 'That code isn\'t valid. Check the spelling and try again.', 'olivia-studio' ) );
		}
		if ( (int) $c->user_id && (int) $c->user_id !== (int) $user_id ) {
			return new WP_Error( 'oys_coupon', __( 'This code belongs to another account.', 'olivia-studio' ) );
		}
		if ( $c->expires_at && oys_ts( $c->expires_at ) < time() ) {
			return new WP_Error( 'oys_coupon', __( 'This code has expired.', 'olivia-studio' ) );
		}
		if ( (int) $c->max_uses && (int) $c->used >= (int) $c->max_uses ) {
			return new WP_Error( 'oys_coupon', __( 'This code has already been used.', 'olivia-studio' ) );
		}
		if ( 'all' !== $c->applies && $for !== $c->applies ) {
			return new WP_Error( 'oys_coupon', 'passes' === $c->applies ? __( 'This code is for passes.', 'olivia-studio' ) : __( 'This code is for drop-in classes.', 'olivia-studio' ) );
		}
		return $c;
	}

	/** "20% off" or "$10 off". */
	public static function label( $c ) {
		return 'percent' === $c->kind ? sprintf( __( '%d%% off', 'olivia-studio' ), (int) $c->value ) : sprintf( __( '%s off', 'olivia-studio' ), oys_money( (int) $c->value ) );
	}

	/**
	 * Take the discount off Stripe line items [ name, description, unit_cents, qty ].
	 * Percentages come off every line; a fixed amount comes off the first lines until used up.
	 * @return array [ lines, discount_cents ]
	 */
	public static function apply_lines( $c, array $lines ) {
		$off = 0;
		$left = 'amount' === $c->kind ? (int) $c->value : 0;
		foreach ( $lines as $i => $l ) {
			$unit = (int) $l[2];
			$qty  = max( 1, (int) $l[3] );
			if ( 'percent' === $c->kind ) {
				$new = (int) round( $unit * ( 100 - (int) $c->value ) / 100 );
			} else {
				$per  = min( $unit, intdiv( $left, $qty ) );
				$new  = $unit - $per;
				$left -= $per * $qty;
			}
			$off         += ( $unit - $new ) * $qty;
			$lines[ $i ][2] = $new;
			$lines[ $i ][1] = trim( $l[1] . ' · ' . sprintf( __( 'code %1$s (%2$s)', 'olivia-studio' ), $c->code, self::label( $c ) ), ' ·' );
		}
		return array( $lines, $off );
	}

	/**
	 * Order + Stripe lines with the customer's code applied, or the unchanged ones when no code
	 * was entered. @return array|WP_Error [ order fields, lines ]
	 */
	public static function for_checkout( $code, $user_id, $for, array $order, array $lines ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return array( $order, $lines );
		}
		$c = self::validate( $code, $user_id, $for );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		[ $lines, $off ] = self::apply_lines( $c, $lines );
		$order['amount_cents'] = max( 0, (int) $order['amount_cents'] - $off );
		$order['description']  = $order['description'] . ' (' . $c->code . ')';
		$order['meta']         = array_merge( $order['meta'] ?? array(), array( 'coupon_id' => (int) $c->id, 'coupon_code' => $c->code, 'discount_cents' => $off ) );
		return array( $order, $lines );
	}

	/** Count the use once the payment is in. */
	public static function on_order_paid( $order_id ) {
		$o = OYS_Orders::get( $order_id );
		if ( $o && ! empty( $o->meta['coupon_id'] ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'coupons' ) . ' SET used = used + 1 WHERE id = %d', (int) $o->meta['coupon_id'] ) );
		}
	}

	/** A customer's own codes they can still use (birthday, prizes). */
	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . OYS_Install::table( 'coupons' ) . ' WHERE user_id = %d AND ( max_uses = 0 OR used < max_uses ) AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY expires_at',
			$user_id, oys_now()
		) );
	}

	public static function query( $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . OYS_Install::table( 'coupons' ) . ' ORDER BY id DESC LIMIT ' . (int) $limit );
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'coupons' ), array( 'id' => (int) $id ) );
	}
}
