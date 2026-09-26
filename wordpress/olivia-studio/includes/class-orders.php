<?php
/**
 * Orders record every card payment. Fulfilment runs exactly once per order: the status
 * flips from pending to paid with a conditional UPDATE, and only the call that wins that
 * update fulfils. Stripe webhooks and the customer's return page can both trigger it.
 *
 * type: dropin (one class) | pack (pass, optionally booking a class with it) | gift | private
 *       | membership (first payment via Checkout; renewals are recorded from invoice.paid)
 */

defined( 'ABSPATH' ) || exit;

class OYS_Orders {

	public static function create( array $data ) {
		global $wpdb;
		$row = wp_parse_args( $data, array(
			'user_id'      => get_current_user_id(),
			'type'         => 'dropin',
			'product_id'   => '',
			'session_id'   => 0,
			'booking_id'   => 0,
			'description'  => '',
			'amount_cents' => 0,
			'currency'     => OYS_Settings::get( 'currency' ),
			'status'       => 'pending',
			'meta'         => array(),
		) );
		$row['meta']       = wp_json_encode( $row['meta'] );
		$row['created_at'] = oys_now();
		$wpdb->insert( OYS_Install::table( 'orders' ), $row );
		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'orders' ) . ' WHERE id = %d', $id ) ) );
	}

	public static function by_stripe_session( $sid ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'orders' ) . ' WHERE stripe_session_id = %s', $sid ) ) );
	}

	public static function by_payment_intent( $pi ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'orders' ) . ' WHERE stripe_payment_intent = %s', $pi ) ) );
	}

	private static function hydrate( $row ) {
		if ( $row ) {
			$row->meta = json_decode( (string) $row->meta, true ) ?: array();
		}
		return $row;
	}

	public static function update( $id, array $row ) {
		global $wpdb;
		if ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}
		$wpdb->update( OYS_Install::table( 'orders' ), $row, array( 'id' => (int) $id ) );
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		return array_map( array( __CLASS__, 'hydrate' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'orders' ) . " WHERE user_id = %d AND status IN ('paid','refunded','partially_refunded') ORDER BY created_at DESC", $user_id ) ) );
	}

	public static function query( $status = '', $limit = 200 ) {
		global $wpdb;
		$t   = OYS_Install::table( 'orders' );
		$sql = $status ? $wpdb->prepare( "SELECT * FROM $t WHERE status = %s", $status ) : "SELECT * FROM $t WHERE status <> 'pending' OR created_at > '" . esc_sql( oys_utc_plus( -DAY_IN_SECONDS ) ) . "'";
		return array_map( array( __CLASS__, 'hydrate' ), $wpdb->get_results( $sql . ' ORDER BY created_at DESC LIMIT ' . (int) $limit ) );
	}

	/**
	 * Record the payment and fulfil the order once.
	 * @param array $stripe checkout session fields: payment_intent, receipt_url, amount_total
	 */
	public static function mark_paid( $order_id, array $stripe = array() ) {
		global $wpdb;
		$t  = OYS_Install::table( 'orders' );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'paid', paid_at = %s WHERE id = %d AND status IN ('pending','expired','failed')", oys_now(), $order_id ) );
		if ( 1 !== (int) $ok ) {
			return false; // Already fulfilled (or refunded) by another request.
		}
		$update = array();
		if ( ! empty( $stripe['payment_intent'] ) ) {
			$update['stripe_payment_intent'] = $stripe['payment_intent'];
		}
		if ( ! empty( $stripe['receipt_url'] ) ) {
			$update['receipt_url'] = $stripe['receipt_url'];
		}
		if ( $update ) {
			self::update( $order_id, $update );
		}
		self::fulfil( self::get( $order_id ) );
		return true;
	}

	private static function fulfil( $order ) {
		switch ( $order->type ) {
			case 'dropin':
			case 'private':
				OYS_Bookings::confirm_order( $order->id );
				if ( 'private' === $order->type && ! empty( $order->meta['request_id'] ) ) {
					OYS_Privates::mark_paid( (int) $order->meta['request_id'], $order->id );
				}
				break;

			case 'pack':
				$product = OYS_Products::get( $order->product_id );
				if ( ! $product ) {
					break;
				}
				$pass_id = OYS_Passes::grant_product( $order->user_id, $product, $order->id );
				OYS_Emails::pass_purchased( $order->id, $pass_id );
				if ( $order->booking_id ) {
					// "Buy a pass and book this class": every held seat (the customer and any guests) uses one credit of the new pass.
					OYS_Bookings::confirm_order( $order->id, $pass_id );
				}
				break;

			case 'gift':
				OYS_Gifts::issue_for_order( $order );
				break;

			case 'membership':
				OYS_Memberships::activate_from_order( $order );
				break;
		}
		OYS_Emails::admin_new_order( $order->id );
		do_action( 'oys_order_paid', $order->id );
	}

	/** Checkout expired or payment failed. */
	public static function mark_unpaid( $order_id, $status = 'expired' ) {
		global $wpdb;
		$t  = OYS_Install::table( 'orders' );
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = %s WHERE id = %d AND status = 'pending'", $status, $order_id ) );
		if ( 1 === (int) $ok ) {
			OYS_Bookings::release_order( $order_id );
		}
	}

	/**
	 * A refund was issued (from the admin screen or directly in Stripe).
	 * A full refund also takes back what was bought: a future booking is cancelled and
	 * unused credits of a pass bought with this order are removed.
	 */
	public static function mark_refunded( $order_id, $amount_refunded_cents ) {
		$order = self::get( $order_id );
		if ( ! $order || ! in_array( $order->status, array( 'paid', 'partially_refunded' ), true ) ) {
			return;
		}
		$full = $amount_refunded_cents >= (int) $order->amount_cents;
		$meta = $order->meta;
		$meta['refunded_cents'] = (int) $amount_refunded_cents;
		self::update( $order_id, array( 'status' => $full ? 'refunded' : 'partially_refunded', 'meta' => $meta ) );
		if ( ! $full ) {
			return;
		}
		foreach ( OYS_Bookings::for_order( $order_id, array( 'confirmed' ) ) as $b ) {
			OYS_Bookings::void( $b->id, 'Refunded' );
		}
		if ( 'pack' === $order->type ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . OYS_Install::table( 'passes' ) . ' SET credits_left = 0 WHERE order_id = %d AND source = %s', $order_id, 'purchase' ) );
		}
		if ( 'gift' === $order->type ) {
			global $wpdb;
			$wpdb->update( OYS_Install::table( 'gift_cards' ), array( 'status' => 'void' ), array( 'order_id' => $order_id, 'status' => 'active' ) );
		}
	}

	/** New customers only: no paid order and no booking yet. */
	public static function is_new_customer( $user_id ) {
		global $wpdb;
		$o = OYS_Install::table( 'orders' );
		$b = OYS_Install::table( 'bookings' );
		$paid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $o WHERE user_id = %d AND status IN ('paid','refunded','partially_refunded')", $user_id ) );
		$book = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE user_id = %d AND status IN ('confirmed','attended','no_show','late_cancelled')", $user_id ) );
		return 0 === $paid && 0 === $book;
	}

	public static function statuses() {
		return array(
			'pending'            => __( 'Checkout started', 'olivia-studio' ),
			'paid'               => __( 'Paid', 'olivia-studio' ),
			'expired'            => __( 'Abandoned', 'olivia-studio' ),
			'failed'             => __( 'Failed', 'olivia-studio' ),
			'refunded'           => __( 'Refunded', 'olivia-studio' ),
			'partially_refunded' => __( 'Partly refunded', 'olivia-studio' ),
		);
	}

	public static function types() {
		return array(
			'dropin'  => __( 'Drop-in', 'olivia-studio' ),
			'pack'    => __( 'Pass', 'olivia-studio' ),
			'gift'    => __( 'Gift card', 'olivia-studio' ),
			'private'    => __( 'Private session', 'olivia-studio' ),
			'membership' => __( 'Membership', 'olivia-studio' ),
		);
	}
}
