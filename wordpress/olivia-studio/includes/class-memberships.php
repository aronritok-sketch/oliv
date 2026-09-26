<?php
/**
 * Memberships: recurring Stripe subscriptions that let a customer book group classes
 * without spending pass credits. A plan is a product of kind `membership`:
 * unlimited (`classes_per_period` = 0) or N classes per billing period.
 *
 * Stripe is the source of truth for billing. This table mirrors the subscription
 * (status, current period, cancel_at_period_end) and is kept in sync by webhooks:
 *   checkout.session.completed (mode=subscription)  → create the membership
 *   customer.subscription.updated                   → status / period / scheduled cancel
 *   customer.subscription.deleted                   → ended; future membership bookings cancelled
 *   invoice.paid                                    → renewal recorded as a payment
 *   invoice.payment_failed                          → past_due; customer asked to update the card
 *
 * status: incomplete → active ⇄ past_due → cancelled   (also: unpaid, paused as reported by Stripe)
 */

defined( 'ABSPATH' ) || exit;

class OYS_Memberships {

	/** Statuses that may book classes. past_due keeps access while Stripe retries the card. */
	const BOOKABLE = array( 'active', 'trialing', 'past_due' );

	public static function init() {
		add_action( 'admin_post_oys_membership', array( __CLASS__, 'handle_customer_action' ) );
		add_action( 'admin_post_oys_join', array( __CLASS__, 'handle_join' ) );
		add_action( 'admin_post_nopriv_oys_join', array( 'OYS_Frontend', 'handle_checkout_guest' ) );
	}

	/* ---------- Reading ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'memberships' ) . ' WHERE id = %d', $id ) );
	}

	public static function by_subscription( $sub_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'memberships' ) . ' WHERE stripe_subscription_id = %s', $sub_id ) );
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'memberships' ) . " WHERE user_id = %d AND status <> 'incomplete' ORDER BY ( status IN ('active','trialing','past_due') ) DESC, created_at DESC", $user_id ) );
	}

	/** The membership that can book right now (the first bookable one). */
	public static function current_for( $user_id ) {
		foreach ( self::for_user( $user_id ) as $m ) {
			if ( in_array( $m->status, self::BOOKABLE, true ) ) {
				return $m;
			}
		}
		return null;
	}

	public static function query( $status = '' ) {
		global $wpdb;
		$t = OYS_Install::table( 'memberships' );
		return $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = %s ORDER BY created_at DESC", $status ) )
			: $wpdb->get_results( "SELECT * FROM $t WHERE status <> 'incomplete' ORDER BY FIELD(status,'past_due','active','trialing','unpaid','paused','cancelled'), created_at DESC LIMIT 500" );
	}

	/** Monthly recurring revenue in cents, from bookable memberships. */
	public static function mrr() {
		$total = 0;
		foreach ( self::query() as $m ) {
			if ( ! in_array( $m->status, self::BOOKABLE, true ) || $m->cancel_at_period_end ) {
				continue;
			}
			$p = OYS_Products::get( $m->product_id );
			if ( ! $p ) {
				continue;
			}
			$months = ( 'year' === $p['interval'] ? 12 : 1 ) * max( 1, (int) $p['interval_count'] );
			$total += (int) round( $p['price_cents'] / $months );
		}
		return $total;
	}

	/** Classes booked on this membership within its current period (late cancels and no-shows count). */
	public static function used_in_period( $m ) {
		global $wpdb;
		if ( ! $m->current_period_start || ! $m->current_period_end ) {
			return 0;
		}
		$b = OYS_Install::table( 'bookings' );
		$s = OYS_Install::table( 'sessions' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.membership_id = %d AND b.status IN ('confirmed','attended','no_show','late_cancelled') AND s.starts_at >= %s AND s.starts_at < %s",
			$m->id, $m->current_period_start, $m->current_period_end
		) );
	}

	public static function remaining( $m ) {
		if ( ! (int) $m->classes_per_period ) {
			return PHP_INT_MAX;
		}
		return max( 0, (int) $m->classes_per_period - self::used_in_period( $m ) );
	}

	/**
	 * Can this membership pay for this session?
	 * - group classes and events that accept passes
	 * - status allows booking
	 * - if the membership ends (scheduled cancel) or has a class limit: the class must be in the current period
	 * @return true|WP_Error
	 */
	public static function covers( $m, $session ) {
		if ( ! $m || ! in_array( $m->status, self::BOOKABLE, true ) ) {
			return new WP_Error( 'oys_membership', __( 'Your membership isn\'t active.', 'olivia-studio' ) );
		}
		if ( 'private' === $session->kind || ! $session->credits_allowed ) {
			return new WP_Error( 'oys_membership', __( 'Memberships can\'t be used for this session.', 'olivia-studio' ) );
		}
		$in_period = $m->current_period_end && $session->starts_at < $m->current_period_end;
		if ( ( $m->cancel_at_period_end || (int) $m->classes_per_period ) && ! $in_period ) {
			return new WP_Error( 'oys_membership', $m->cancel_at_period_end
				? __( 'Your membership ends before this class.', 'olivia-studio' )
				: sprintf( __( 'This class is in your next billing period. You can book it from %s.', 'olivia-studio' ), oys_date( $m->current_period_end, get_option( 'date_format' ) ) ) );
		}
		if ( self::remaining( $m ) < 1 ) {
			return new WP_Error( 'oys_membership', sprintf( __( 'You\'ve used all %d classes of this period. Your next period starts %s.', 'olivia-studio' ), (int) $m->classes_per_period, oys_date( $m->current_period_end, get_option( 'date_format' ) ) ) );
		}
		return true;
	}

	/* ---------- Joining ---------- */

	/** "Join" button: start a Stripe Checkout in subscription mode. */
	public static function handle_join() {
		check_admin_referer( 'oys_join' );
		$user_id = get_current_user_id();
		$p       = OYS_Products::get( sanitize_key( $_POST['product'] ?? '' ) );
		$back    = $p ? oys_page_url( 'book', array( 'product' => $p['id'] ) ) : oys_page_url( 'book' );
		if ( ! $p || 'membership' !== $p['kind'] || empty( $p['active'] ) ) {
			oys_redirect( $back );
		}
		if ( self::current_for( $user_id ) ) {
			oys_flash( __( 'You already have an active membership. You can change or cancel it in your account.', 'olivia-studio' ), 'error' );
			oys_redirect( oys_account_url( 'membership' ) );
		}
		if ( ! OYS_Customers::has_waiver( $user_id ) ) {
			if ( empty( $_POST['waiver'] ) ) {
				oys_flash( __( 'Please accept the participation agreement to continue.', 'olivia-studio' ), 'error' );
				oys_redirect( $back );
			}
			OYS_Customers::record_waiver( $user_id );
		}
		$order_id = OYS_Orders::create( array(
			'user_id'      => $user_id,
			'type'         => 'membership',
			'product_id'   => $p['id'],
			'description'  => sprintf( __( 'Membership: %s (first payment)', 'olivia-studio' ), $p['name'] ),
			'amount_cents' => (int) $p['price_cents'],
		) );
		$url = OYS_Stripe::start_subscription_checkout( $order_id, $p );
		if ( is_wp_error( $url ) ) {
			OYS_Orders::update( $order_id, array( 'status' => 'failed' ) );
			oys_flash( $url->get_error_message(), 'error' );
			oys_redirect( $back );
		}
		wp_redirect( $url );
		exit;
	}

	/**
	 * First payment done (checkout.session.completed or the return page): create the membership
	 * from the subscription. Called once per order by OYS_Orders::fulfil().
	 */
	public static function activate_from_order( $order ) {
		$sub_id = $order->meta['subscription_id'] ?? '';
		if ( ! $sub_id ) {
			return 0;
		}
		$existing = self::by_subscription( $sub_id );
		if ( $existing ) {
			return (int) $existing->id;
		}
		$p = OYS_Products::get( $order->product_id );
		global $wpdb;
		$wpdb->insert( OYS_Install::table( 'memberships' ), array(
			'user_id'                => $order->user_id,
			'product_id'             => $order->product_id,
			'name'                   => $p ? $p['name'] : $order->product_id,
			'classes_per_period'     => $p ? (int) $p['classes_per_period'] : 0,
			'status'                 => 'active',
			'stripe_subscription_id' => $sub_id,
			'stripe_customer_id'     => (string) ( $order->meta['customer_id'] ?? '' ),
			'order_id'               => $order->id,
			'created_at'             => oys_now(),
		) );
		$id  = (int) $wpdb->insert_id;
		$sub = OYS_Stripe::request( 'GET', '/v1/subscriptions/' . rawurlencode( $sub_id ) );
		if ( ! is_wp_error( $sub ) ) {
			self::sync( $sub, false );
		}
		OYS_Emails::membership_started( $id );
		do_action( 'oys_membership_started', $id );
		return $id;
	}

	/* ---------- Keeping in sync with Stripe ---------- */

	/**
	 * Mirror a Stripe subscription object onto the membership.
	 * @param array $sub     Stripe subscription (API version 2024-06-20: periods on the subscription)
	 * @param bool  $notify  send emails on meaningful changes
	 */
	public static function sync( array $sub, $notify = true ) {
		$m = self::by_subscription( $sub['id'] ?? '' );
		if ( ! $m ) {
			return null;
		}
		$status = (string) ( $sub['status'] ?? $m->status );
		$status = 'canceled' === $status ? 'cancelled' : $status;
		$start  = $sub['current_period_start'] ?? ( $sub['items']['data'][0]['current_period_start'] ?? null );
		$end    = $sub['current_period_end'] ?? ( $sub['items']['data'][0]['current_period_end'] ?? null );
		$row    = array(
			'status'               => $status,
			'cancel_at_period_end' => empty( $sub['cancel_at_period_end'] ) ? 0 : 1,
			'updated_at'           => oys_now(),
		);
		if ( $start ) {
			$row['current_period_start'] = gmdate( 'Y-m-d H:i:s', (int) $start );
		}
		if ( $end ) {
			$row['current_period_end'] = gmdate( 'Y-m-d H:i:s', (int) $end );
		}
		if ( 'cancelled' === $status && ! $m->ended_at ) {
			$row['ended_at'] = ! empty( $sub['ended_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $sub['ended_at'] ) : oys_now();
		}
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'memberships' ), $row, array( 'id' => $m->id ) );
		$fresh = self::get( $m->id );

		if ( $notify ) {
			if ( ! $m->cancel_at_period_end && $fresh->cancel_at_period_end && 'cancelled' !== $status ) {
				OYS_Emails::membership_cancel_scheduled( $m->id );
			}
			if ( 'cancelled' !== $m->status && 'cancelled' === $status ) {
				self::release_future_bookings( $fresh );
				OYS_Emails::membership_ended( $m->id );
				do_action( 'oys_membership_ended', $m->id );
			}
		} elseif ( 'cancelled' === $status && 'cancelled' !== $m->status ) {
			self::release_future_bookings( $fresh );
		}
		return $fresh;
	}

	/** A membership ended: classes booked with it after the end date are cancelled (customer emailed). */
	private static function release_future_bookings( $m ) {
		global $wpdb;
		$b   = OYS_Install::table( 'bookings' );
		$s   = OYS_Install::table( 'sessions' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT b.id FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.membership_id = %d AND b.status = 'confirmed' AND s.starts_at > %s",
			$m->id, $m->ended_at ?: oys_now()
		) );
		foreach ( $ids as $id ) {
			OYS_Bookings::cancel( (int) $id, array( 'by_studio' => true, 'reason' => __( 'Your membership has ended.', 'olivia-studio' ) ) );
		}
	}

	/** invoice.paid: record renewals as payments (the first invoice is the checkout order). */
	public static function record_invoice( array $invoice ) {
		$sub_id = $invoice['subscription'] ?? '';
		$m      = $sub_id ? self::by_subscription( $sub_id ) : null;
		if ( ! $m ) {
			return;
		}
		if ( ( $invoice['billing_reason'] ?? '' ) === 'subscription_create' ) {
			if ( $m->order_id ) {
				OYS_Orders::update( $m->order_id, array_filter( array( 'stripe_invoice_id' => $invoice['id'] ?? null, 'receipt_url' => $invoice['hosted_invoice_url'] ?? '' ) ) );
			}
		} else {
			global $wpdb;
			$t = OYS_Install::table( 'orders' );
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE stripe_invoice_id = %s", $invoice['id'] ) ) ) {
				$order_id = OYS_Orders::create( array(
					'user_id'      => $m->user_id,
					'type'         => 'membership',
					'product_id'   => $m->product_id,
					'description'  => sprintf( __( 'Membership: %s (renewal)', 'olivia-studio' ), $m->name ),
					'amount_cents' => (int) ( $invoice['amount_paid'] ?? 0 ),
					'currency'     => $invoice['currency'] ?? OYS_Settings::get( 'currency' ),
					'status'       => 'paid',
					'meta'         => array( 'membership_id' => (int) $m->id, 'subscription_id' => $sub_id ),
				) );
				OYS_Orders::update( $order_id, array(
					'paid_at'               => oys_now(),
					'stripe_invoice_id'     => $invoice['id'],
					'stripe_payment_intent' => is_string( $invoice['payment_intent'] ?? null ) ? $invoice['payment_intent'] : '',
					'receipt_url'           => $invoice['hosted_invoice_url'] ?? '',
				) );
				do_action( 'oys_order_paid', $order_id );
			}
		}
		// Period dates come with the subscription update; refresh them now in case that event is late.
		$sub = OYS_Stripe::request( 'GET', '/v1/subscriptions/' . rawurlencode( $sub_id ) );
		if ( ! is_wp_error( $sub ) ) {
			self::sync( $sub );
		}
	}

	/** invoice.payment_failed: mark past due and ask the customer to update their card. */
	public static function payment_failed( array $invoice ) {
		$m = ! empty( $invoice['subscription'] ) ? self::by_subscription( $invoice['subscription'] ) : null;
		if ( ! $m ) {
			return;
		}
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'memberships' ), array( 'status' => 'past_due', 'updated_at' => oys_now() ), array( 'id' => $m->id ) );
		OYS_Emails::membership_payment_failed( $m->id, $invoice['hosted_invoice_url'] ?? '' );
	}

	/* ---------- Changes by the customer or the studio ---------- */

	/** Cancel at the end of the paid period (true) or undo that (false). */
	public static function set_cancel_at_period_end( $m, $cancel ) {
		$sub = OYS_Stripe::request( 'POST', '/v1/subscriptions/' . rawurlencode( $m->stripe_subscription_id ), array( 'cancel_at_period_end' => $cancel ? 'true' : 'false' ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		return self::sync( $sub );
	}

	/** Staff: end now (no refund; refund the last payment separately if needed). */
	public static function cancel_now( $m ) {
		$sub = OYS_Stripe::request( 'DELETE', '/v1/subscriptions/' . rawurlencode( $m->stripe_subscription_id ) );
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		return self::sync( $sub );
	}

	/** Stripe's hosted customer portal: update card, see invoices, cancel. */
	public static function portal_url( $m ) {
		$res = OYS_Stripe::request( 'POST', '/v1/billing_portal/sessions', array(
			'customer'   => $m->stripe_customer_id,
			'return_url' => oys_account_url( 'membership' ),
		) );
		return is_wp_error( $res ) ? $res : $res['url'];
	}

	public static function handle_customer_action() {
		check_admin_referer( 'oys_membership' );
		$m = self::get( (int) ( $_POST['membership'] ?? 0 ) );
		if ( ! $m || (int) $m->user_id !== get_current_user_id() ) {
			oys_redirect( oys_account_url( 'membership' ) );
		}
		$do = sanitize_key( $_POST['do'] ?? '' );
		if ( 'portal' === $do ) {
			$url = self::portal_url( $m );
			if ( ! is_wp_error( $url ) ) {
				wp_redirect( $url );
				exit;
			}
			oys_flash( __( 'The billing page is not available right now. Please try again or contact us.', 'olivia-studio' ), 'error' );
		} elseif ( in_array( $do, array( 'cancel', 'resume' ), true ) ) {
			$res = self::set_cancel_at_period_end( $m, 'cancel' === $do );
			if ( is_wp_error( $res ) ) {
				oys_flash( $res->get_error_message(), 'error' );
			} elseif ( 'cancel' === $do ) {
				oys_flash( sprintf( __( 'Your membership will end on %s. You can keep booking until then.', 'olivia-studio' ), esc_html( oys_date( $res->current_period_end, get_option( 'date_format' ) ) ) ) );
			} else {
				oys_flash( __( 'Welcome back! Your membership will renew as usual.', 'olivia-studio' ) );
			}
		}
		oys_redirect( oys_account_url( 'membership' ) );
	}

	public static function statuses() {
		return array(
			'incomplete' => __( 'Checkout started', 'olivia-studio' ),
			'trialing'   => __( 'Trial', 'olivia-studio' ),
			'active'     => __( 'Active', 'olivia-studio' ),
			'past_due'   => __( 'Payment failed', 'olivia-studio' ),
			'unpaid'     => __( 'Unpaid', 'olivia-studio' ),
			'paused'     => __( 'Paused', 'olivia-studio' ),
			'cancelled'  => __( 'Ended', 'olivia-studio' ),
		);
	}
}

OYS_Memberships::init();
