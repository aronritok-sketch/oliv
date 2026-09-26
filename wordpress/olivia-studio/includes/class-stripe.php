<?php
/**
 * Stripe Checkout (hosted payment page) over the REST API, without the PHP SDK.
 * Card data never touches this site; Apple Pay, Google Pay and Link work automatically.
 *
 * Webhook endpoint: /wp-json/oys/v1/stripe-webhook
 * Events: checkout.session.completed, checkout.session.async_payment_succeeded,
 *         checkout.session.async_payment_failed, checkout.session.expired, charge.refunded,
 *         customer.subscription.updated, customer.subscription.deleted, invoice.paid,
 *         invoice.payment_failed
 */

defined( 'ABSPATH' ) || exit;

class OYS_Stripe {

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'oys/v1', '/stripe-webhook', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by the Stripe signature.
			) );
		} );
	}

	public static function api_base() {
		return defined( 'OYS_STRIPE_API_BASE' ) ? rtrim( OYS_STRIPE_API_BASE, '/' ) : 'https://api.stripe.com';
	}

	/** @return array|WP_Error decoded response */
	public static function request( $method, $path, array $params = array(), $idempotency_key = '' ) {
		$secret = OYS_Settings::stripe_secret();
		if ( ! $secret ) {
			return new WP_Error( 'oys_stripe_keys', __( 'Online payments are not set up yet.', 'olivia-studio' ) );
		}
		$url  = self::api_base() . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Stripe-Version' => '2024-06-20',
			),
		);
		if ( 'GET' === $method ) {
			$url = $params ? $url . '?' . self::encode( $params ) : $url;
		} else {
			$args['body']                     = self::encode( $params );
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			if ( $idempotency_key ) {
				$args['headers']['Idempotency-Key'] = $idempotency_key;
			}
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			oys_log( 'Stripe request failed', array( 'path' => $path, 'error' => $res->get_error_message() ) );
			return new WP_Error( 'oys_stripe_http', __( 'We couldn\'t reach the payment provider. Please try again in a moment.', 'olivia-studio' ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$msg = $body['error']['message'] ?? 'HTTP ' . $code;
			oys_log( 'Stripe error', array( 'path' => $path, 'code' => $code, 'message' => $msg ) );
			return new WP_Error( 'oys_stripe_api', __( 'The payment could not be started. Please try again or contact us.', 'olivia-studio' ), array( 'stripe' => $msg ) );
		}
		return $body;
	}

	/** Stripe's bracket syntax: line_items[0][price_data][currency]=usd */
	private static function encode( array $params ) {
		return http_build_query( $params, '', '&', PHP_QUERY_RFC1738 );
	}

	/** Reuse one Stripe customer per account so receipts and saved details line up. */
	public static function customer_for( $user_id ) {
		$meta_key = OYS_Settings::is_live() ? 'oys_stripe_customer_live' : 'oys_stripe_customer_test';
		$existing = get_user_meta( $user_id, $meta_key, true );
		if ( $existing ) {
			return $existing;
		}
		$user = get_userdata( $user_id );
		$res  = self::request( 'POST', '/v1/customers', array(
			'email'    => $user->user_email,
			'name'     => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			'phone'    => get_user_meta( $user_id, 'oys_phone', true ),
			'metadata' => array( 'wp_user_id' => $user_id ),
		), 'oys-customer-' . $user_id . '-' . ( OYS_Settings::is_live() ? 'live' : 'test' ) );
		if ( is_wp_error( $res ) ) {
			return '';
		}
		update_user_meta( $user_id, $meta_key, $res['id'] );
		return $res['id'];
	}

	/**
	 * Create a Checkout Session for an order and return the hosted page URL.
	 * @return string|WP_Error
	 */
	/**
	 * @param array $lines optional line items for the receipt: [ [ 'name', 'description', 'unit_amount', 'quantity' ], … ]
	 *                     (e.g. the customer's own spot plus "Guest ticket × 2"). Defaults to one line for the whole order.
	 */
	public static function start_checkout( $order_id, $product_name, $description = '', array $lines = array() ) {
		$order = OYS_Orders::get( $order_id );
		$user  = get_userdata( $order->user_id );
		$hold  = max( 30, (int) OYS_Settings::get( 'hold_minutes' ) ); // Stripe's minimum is 30 minutes.

		$return = oys_page_url( 'book', array_filter( array( 'oys_order' => $order_id, 'oys_key' => self::order_key( $order_id ), 'app' => empty( $order->meta['app'] ) ? '' : 1 ) ) );
		$params = array(
			'mode'                => 'payment',
			'client_reference_id' => (string) $order_id,
			'success_url'         => add_query_arg( 'oys_return', 'success', $return ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => add_query_arg( 'oys_return', 'cancel', $return ),
			'expires_at'          => time() + $hold * MINUTE_IN_SECONDS,
			'line_items'          => self::line_items( $order, $lines ?: array( array( $product_name, $description, (int) $order->amount_cents, 1 ) ) ),
			'metadata'            => array( 'order_id' => $order_id, 'type' => $order->type, 'site' => home_url() ),
			'payment_intent_data' => array(
				'description' => $product_name,
				'metadata'    => array( 'order_id' => $order_id ),
			),
		);
		$customer = self::customer_for( $order->user_id );
		if ( $customer ) {
			$params['customer'] = $customer;
		} else {
			$params['customer_email'] = $user->user_email;
		}
		$res = self::request( 'POST', '/v1/checkout/sessions', $params, 'oys-order-' . $order_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$meta                 = $order->meta;
		$meta['checkout_url'] = $res['url'];
		OYS_Orders::update( $order_id, array( 'stripe_session_id' => $res['id'], 'meta' => $meta ) );
		return $res['url'];
	}

	private static function line_items( $order, array $lines ) {
		$items = array();
		foreach ( $lines as $l ) {
			$items[] = array(
				'quantity'   => max( 1, (int) $l[3] ),
				'price_data' => array(
					'currency'     => $order->currency,
					'unit_amount'  => (int) $l[2],
					'product_data' => array_filter( array( 'name' => $l[0], 'description' => $l[1] ?: null ) ),
				),
			);
		}
		return $items;
	}

	/**
	 * Checkout in subscription mode for a membership plan. The first invoice is paid on the
	 * Stripe page; later renewals arrive as invoice.paid webhooks.
	 * @return string|WP_Error
	 */
	public static function start_subscription_checkout( $order_id, array $product ) {
		$order  = OYS_Orders::get( $order_id );
		$return = oys_page_url( 'book', array( 'oys_order' => $order_id, 'oys_key' => self::order_key( $order_id ) ) );
		$params = array(
			'mode'                => 'subscription',
			'client_reference_id' => (string) $order_id,
			'success_url'         => add_query_arg( 'oys_return', 'success', $return ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => add_query_arg( 'oys_return', 'cancel', $return ),
			'expires_at'          => time() + max( 30, (int) OYS_Settings::get( 'hold_minutes' ) ) * MINUTE_IN_SECONDS,
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => $order->currency,
						'unit_amount'  => (int) $product['price_cents'],
						'recurring'    => array( 'interval' => 'year' === $product['interval'] ? 'year' : 'month', 'interval_count' => max( 1, (int) $product['interval_count'] ) ),
						'product_data' => array_filter( array( 'name' => $product['name'], 'description' => $product['description'] ?: null ) ),
					),
				),
			),
			'metadata'            => array( 'order_id' => $order_id, 'type' => 'membership', 'site' => home_url() ),
			'subscription_data'   => array( 'metadata' => array( 'order_id' => $order_id, 'product_id' => $product['id'], 'wp_user_id' => $order->user_id ) ),
		);
		$customer = self::customer_for( $order->user_id );
		if ( $customer ) {
			$params['customer'] = $customer;
		} else {
			$params['customer_email'] = get_userdata( $order->user_id )->user_email;
		}
		$res = self::request( 'POST', '/v1/checkout/sessions', $params, 'oys-order-' . $order_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		OYS_Orders::update( $order_id, array( 'stripe_session_id' => $res['id'] ) );
		return $res['url'];
	}

	/** Fulfil from the Checkout Session (webhook or return page). */
	public static function sync_session( $session_id ) {
		$cs = self::request( 'GET', '/v1/checkout/sessions/' . rawurlencode( $session_id ), array( 'expand' => array( 'payment_intent.latest_charge', 'invoice' ) ) );
		if ( is_wp_error( $cs ) ) {
			return $cs;
		}
		return self::apply_session( $cs );
	}

	private static function apply_session( array $cs ) {
		$order = OYS_Orders::by_stripe_session( $cs['id'] );
		if ( ! $order && ! empty( $cs['client_reference_id'] ) ) {
			$order = OYS_Orders::get( (int) $cs['client_reference_id'] );
			if ( $order && $order->stripe_session_id && $order->stripe_session_id !== $cs['id'] ) {
				$order = null;
			}
		}
		if ( ! $order ) {
			return new WP_Error( 'oys_unknown_order', 'Unknown order' );
		}
		if ( 'paid' === ( $cs['payment_status'] ?? '' ) || 'no_payment_required' === ( $cs['payment_status'] ?? '' ) ) {
			$pi      = $cs['payment_intent'] ?? '';
			$receipt = '';
			if ( is_array( $pi ) ) {
				$receipt = $pi['latest_charge']['receipt_url'] ?? '';
				$pi      = $pi['id'] ?? '';
			}
			if ( 'subscription' === ( $cs['mode'] ?? '' ) ) {
				$sub  = is_array( $cs['subscription'] ?? null ) ? ( $cs['subscription']['id'] ?? '' ) : (string) ( $cs['subscription'] ?? '' );
				$cust = is_array( $cs['customer'] ?? null ) ? ( $cs['customer']['id'] ?? '' ) : (string) ( $cs['customer'] ?? '' );
				$inv  = is_array( $cs['invoice'] ?? null ) ? $cs['invoice'] : null;
				$meta = $order->meta;
				$meta['subscription_id'] = $sub;
				$meta['customer_id']     = $cust;
				OYS_Orders::update( $order->id, array( 'meta' => $meta ) );
				if ( $inv ) {
					$receipt = $inv['hosted_invoice_url'] ?? '';
					$pi      = is_string( $inv['payment_intent'] ?? null ) ? $inv['payment_intent'] : $pi;
				}
			}
			if ( ! empty( $cs['amount_total'] ) && (int) $cs['amount_total'] !== (int) $order->amount_cents ) {
				oys_log( 'Amount mismatch', array( 'order' => $order->id, 'stripe' => $cs['amount_total'] ) );
			}
			OYS_Orders::mark_paid( $order->id, array( 'payment_intent' => $pi, 'receipt_url' => $receipt ) );
		} elseif ( 'expired' === ( $cs['status'] ?? '' ) ) {
			OYS_Orders::mark_unpaid( $order->id, 'expired' );
		}
		return OYS_Orders::get( $order->id );
	}

	public static function refund( $order_id, $amount_cents = 0 ) {
		$order = OYS_Orders::get( $order_id );
		if ( ! $order || ! $order->stripe_payment_intent ) {
			return new WP_Error( 'oys_refund', __( 'This order has no card payment to refund.', 'olivia-studio' ) );
		}
		$params = array( 'payment_intent' => $order->stripe_payment_intent, 'metadata' => array( 'order_id' => $order_id ) );
		if ( $amount_cents ) {
			$params['amount'] = (int) $amount_cents;
		}
		$res = self::request( 'POST', '/v1/refunds', $params, 'oys-refund-' . $order_id . '-' . (int) $amount_cents );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$already = (int) ( $order->meta['refunded_cents'] ?? 0 );
		OYS_Orders::mark_refunded( $order_id, $already + (int) $res['amount'] );
		return $res;
	}

	/** Signed key for return URLs, so an order page can't be opened by guessing ids. */
	public static function order_key( $order_id ) {
		return substr( hash_hmac( 'sha256', 'order-' . $order_id, wp_salt( 'auth' ) ), 0, 20 );
	}

	/* ---------- Webhook ---------- */

	public static function verify_signature( $payload, $header, $secret, $tolerance = 300 ) {
		if ( ! $secret || ! $header ) {
			return false;
		}
		$t    = 0;
		$sigs = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sigs[] = $kv[1];
			}
		}
		if ( ! $t || ! $sigs || abs( time() - $t ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	public static function webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		if ( ! self::verify_signature( $payload, $request->get_header( 'stripe_signature' ), OYS_Settings::webhook_secret() ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 400 );
		}
		$event = json_decode( $payload, true );
		if ( empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad payload' ), 400 );
		}

		global $wpdb;
		$t     = OYS_Install::table( 'stripe_events' );
		$fresh = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (event_id, type, received_at) VALUES (%s, %s, %s)", $event['id'], $event['type'], oys_now() ) );
		if ( 1 !== (int) $fresh ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$object = $event['data']['object'] ?? array();
		try {
			switch ( $event['type'] ) {
				case 'checkout.session.completed':
				case 'checkout.session.async_payment_succeeded':
					if ( 'paid' === ( $object['payment_status'] ?? '' ) ) {
						// Re-read the session to get the receipt link; fall back to the event data.
						$res = self::sync_session( $object['id'] );
						if ( is_wp_error( $res ) ) {
							self::apply_session( $object );
						}
					}
					break;
				case 'checkout.session.async_payment_failed':
					$order = OYS_Orders::by_stripe_session( $object['id'] );
					if ( $order ) {
						OYS_Orders::mark_unpaid( $order->id, 'failed' );
					}
					break;
				case 'checkout.session.expired':
					$order = OYS_Orders::by_stripe_session( $object['id'] );
					if ( $order ) {
						OYS_Orders::mark_unpaid( $order->id, 'expired' );
					}
					break;
				case 'customer.subscription.updated':
				case 'customer.subscription.deleted':
					OYS_Memberships::sync( $object );
					break;
				case 'invoice.paid':
					OYS_Memberships::record_invoice( $object );
					break;
				case 'invoice.payment_failed':
					OYS_Memberships::payment_failed( $object );
					break;
				case 'charge.refunded':
					$order = ! empty( $object['payment_intent'] ) ? OYS_Orders::by_payment_intent( $object['payment_intent'] ) : null;
					if ( $order ) {
						OYS_Orders::mark_refunded( $order->id, (int) ( $object['amount_refunded'] ?? 0 ) );
					}
					break;
			}
		} catch ( Throwable $e ) {
			// Let Stripe retry: forget the event so the retry is processed.
			$wpdb->delete( $t, array( 'event_id' => $event['id'] ) );
			oys_log( 'Webhook error', array( 'event' => $event['id'], 'error' => $e->getMessage() ) );
			return new WP_REST_Response( array( 'error' => 'processing failed' ), 500 );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
