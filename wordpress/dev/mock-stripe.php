<?php
/**
 * Local Stripe stand-in for end-to-end tests (never used on a live site).
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 mock-stripe.php
 * wp-config.php: define( 'OYS_STRIPE_API_BASE', 'http://127.0.0.1:8090' );
 * Plugin settings: test secret key "sk_test_mock", webhook secret "whsec_mock".
 *
 * Implements the calls the plugin makes (customers, Checkout in payment and subscription
 * mode, subscriptions, billing portal, refunds), a fake hosted checkout page that "pays"
 * and delivers signed webhooks, and test helpers:
 *   /_webhook?type=…&id=cs_…     re-send an event for a Checkout Session
 *   /_renew?sub=sub_…            next billing period paid (invoice.paid + subscription.updated)
 *   /_fail?sub=sub_…             renewal payment failed (invoice.payment_failed + past_due)
 *   /_end?sub=sub_…              Stripe gave up / ended the subscription (subscription.deleted)
 */

const WEBHOOK_URL    = 'http://127.0.0.1:8080/wp-json/oys/v1/stripe-webhook';
const WEBHOOK_SECRET = 'whsec_mock';

$store_file = sys_get_temp_dir() . '/mock-stripe.json';
$fp         = fopen( $store_file . '.lock', 'c' );
flock( $fp, LOCK_EX );
$db = is_file( $store_file ) ? json_decode( file_get_contents( $store_file ), true ) : array();
$db += array( 'sessions' => array(), 'subs' => array(), 'invoices' => array(), 'n' => 0 );

function save() { global $db, $store_file; file_put_contents( $store_file, json_encode( $db ) ); }
function unlock() { global $fp; flock( $fp, LOCK_UN ); }
function out( $data, $code = 200 ) { http_response_code( $code ); header( 'Content-Type: application/json' ); echo json_encode( $data ); }
function rid( $prefix ) { global $db; $db['n']++; return $prefix . '_' . substr( md5( $db['n'] . microtime() ), 0, 14 ); }
function period_seconds( $sub ) { return ( 'year' === $sub['interval'] ? 365 : 30 ) * 86400 * max( 1, (int) $sub['interval_count'] ); }

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
parse_str( file_get_contents( 'php://input' ), $body );
parse_str( $_SERVER['QUERY_STRING'] ?? '', $query );

if ( str_starts_with( $path, '/v1/' ) && ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) !== 'Bearer sk_test_mock' ) {
	return out( array( 'error' => array( 'message' => 'Invalid API Key provided' ) ), 401 );
}

if ( 'POST' === $method && '/v1/customers' === $path ) {
	return out( array( 'id' => rid( 'cus' ), 'email' => $body['email'] ?? '' ) );
}

if ( 'POST' === $method && '/v1/checkout/sessions' === $path ) {
	$id    = rid( 'cs_test' );
	$total = 0;
	$lines = array();
	foreach ( $body['line_items'] as $li ) {
		$total  += (int) $li['price_data']['unit_amount'] * (int) $li['quantity'];
		$lines[] = array( 'name' => $li['price_data']['product_data']['name'] ?? '', 'description' => $li['price_data']['product_data']['description'] ?? '', 'quantity' => (int) $li['quantity'], 'unit_amount' => (int) $li['price_data']['unit_amount'] );
	}
	$db['sessions'][ $id ] = array(
		'id' => $id, 'object' => 'checkout.session', 'mode' => $body['mode'] ?? 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
		'amount_total' => $total, 'currency' => $body['line_items'][0]['price_data']['currency'],
		'client_reference_id' => $body['client_reference_id'] ?? null, 'metadata' => $body['metadata'] ?? array(),
		'customer' => $body['customer'] ?? rid( 'cus' ),
		'success_url' => $body['success_url'], 'cancel_url' => $body['cancel_url'],
		'name' => $lines[0]['name'], 'lines' => $lines,
		'recurring' => $body['line_items'][0]['price_data']['recurring'] ?? null,
		'sub_metadata' => $body['subscription_data']['metadata'] ?? array(),
		'payment_intent' => null, 'subscription' => null, 'invoice' => null, 'url' => 'http://127.0.0.1:8090/pay/' . $id,
	);
	save();
	return out( $db['sessions'][ $id ] );
}

if ( preg_match( '#^/v1/checkout/sessions/([^/]+)(/expire)?$#', $path, $m ) ) {
	$s = $db['sessions'][ $m[1] ] ?? null;
	if ( ! $s ) {
		return out( array( 'error' => array( 'message' => 'No such checkout.session' ) ), 404 );
	}
	if ( 'POST' === $method && ! empty( $m[2] ) ) {
		if ( 'open' !== $s['status'] ) {
			return out( array( 'error' => array( 'message' => 'Only Checkout Sessions with a status of open can be expired.' ) ), 400 );
		}
		$db['sessions'][ $m[1] ]['status'] = 'expired';
		save();
		return out( $db['sessions'][ $m[1] ] );
	}
	$expand = (array) ( $query['expand'] ?? array() );
	if ( $s['payment_intent'] && in_array( 'payment_intent.latest_charge', $expand, true ) ) {
		$s['payment_intent'] = array( 'id' => $s['payment_intent'], 'latest_charge' => array( 'id' => 'ch_' . substr( $s['payment_intent'], 3 ), 'receipt_url' => 'https://pay.stripe.com/receipts/mock/' . $s['payment_intent'] ) );
	}
	if ( $s['invoice'] && in_array( 'invoice', $expand, true ) ) {
		$s['invoice'] = $db['invoices'][ $s['invoice'] ];
	}
	return out( $s );
}

if ( preg_match( '#^/v1/subscriptions/([^/]+)$#', $path, $m ) ) {
	$id = $m[1];
	if ( empty( $db['subs'][ $id ] ) ) {
		return out( array( 'error' => array( 'message' => 'No such subscription' ) ), 404 );
	}
	if ( 'POST' === $method && isset( $body['cancel_at_period_end'] ) ) {
		$db['subs'][ $id ]['cancel_at_period_end'] = 'true' === $body['cancel_at_period_end'];
		save();
		unlock();
		send_webhook( 'customer.subscription.updated', $db['subs'][ $id ] );
	} elseif ( 'DELETE' === $method ) {
		$db['subs'][ $id ]['status']   = 'canceled';
		$db['subs'][ $id ]['ended_at'] = time();
		save();
		unlock();
		send_webhook( 'customer.subscription.deleted', $db['subs'][ $id ] );
	}
	return out( $db['subs'][ $id ] );
}

if ( 'POST' === $method && '/v1/billing_portal/sessions' === $path ) {
	return out( array( 'id' => rid( 'bps' ), 'url' => 'http://127.0.0.1:8090/portal?customer=' . rawurlencode( $body['customer'] ) . '&return=' . rawurlencode( $body['return_url'] ) ) );
}

if ( 'POST' === $method && '/v1/refunds' === $path ) {
	$amount = isset( $body['amount'] ) ? (int) $body['amount'] : 0;
	foreach ( array_merge( $db['sessions'], $db['invoices'] ) as $s ) {
		if ( ( $s['payment_intent'] ?? '' ) === $body['payment_intent'] ) {
			$amount = $amount ?: (int) ( $s['amount_total'] ?? $s['amount_paid'] );
		}
	}
	return out( array( 'id' => rid( 're' ), 'amount' => $amount, 'status' => 'succeeded' ) );
}

// Fake Stripe customer portal.
if ( '/portal' === $path ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	printf( '<!doctype html><title>Mock billing portal</title><body style="font-family:system-ui;max-width:420px;margin:60px auto"><p style="color:#635bff;font-weight:700">MOCK STRIPE CUSTOMER PORTAL</p><h1>Manage billing</h1><p>Customer %s</p><p><a id="portal-return" href="%s">Return to the site</a></p>', htmlspecialchars( $query['customer'] ?? '' ), htmlspecialchars( $query['return'] ?? '/' ) );
	return;
}

// Fake hosted checkout page.
if ( preg_match( '#^/pay/([^/]+)$#', $path, $m ) ) {
	$id = $m[1];
	$s  = $db['sessions'][ $id ] ?? null;
	if ( ! $s ) {
		http_response_code( 404 );
		echo 'Unknown session';
		return;
	}
	if ( 'POST' === $method ) {
		$db['sessions'][ $id ]['status']         = 'complete';
		$db['sessions'][ $id ]['payment_status'] = 'paid';
		$invoice = null;
		if ( 'subscription' === $s['mode'] ) {
			$sub_id = rid( 'sub' );
			$now    = time();
			$db['subs'][ $sub_id ] = array(
				'id' => $sub_id, 'object' => 'subscription', 'status' => 'active', 'customer' => $s['customer'], 'cancel_at_period_end' => false,
				'current_period_start' => $now, 'interval' => $s['recurring']['interval'] ?? 'month', 'interval_count' => (int) ( $s['recurring']['interval_count'] ?? 1 ),
				'metadata' => $s['sub_metadata'], 'amount' => $s['amount_total'],
			);
			$db['subs'][ $sub_id ]['current_period_end'] = $now + period_seconds( $db['subs'][ $sub_id ] );
			$inv_id = rid( 'in' );
			$db['invoices'][ $inv_id ] = array( 'id' => $inv_id, 'object' => 'invoice', 'subscription' => $sub_id, 'billing_reason' => 'subscription_create', 'amount_paid' => $s['amount_total'], 'currency' => $s['currency'], 'payment_intent' => rid( 'pi' ), 'hosted_invoice_url' => 'https://invoice.stripe.com/mock/' . $inv_id );
			$db['sessions'][ $id ]['subscription'] = $sub_id;
			$db['sessions'][ $id ]['invoice']      = $inv_id;
			$invoice = $db['invoices'][ $inv_id ];
		} else {
			$db['sessions'][ $id ]['payment_intent'] = rid( 'pi' );
		}
		save();
		unlock();
		if ( empty( $_POST['skip_webhook'] ) ) {
			send_webhook( 'checkout.session.completed', $db['sessions'][ $id ] );
			if ( $invoice ) {
				send_webhook( 'invoice.paid', $invoice );
			}
		}
		header( 'Location: ' . str_replace( '{CHECKOUT_SESSION_ID}', $id, $s['success_url'] ) );
		return;
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	$rows = '';
	foreach ( $s['lines'] as $l ) {
		$rows .= sprintf( '<tr class="line"><td>%s<br><small>%s</small></td><td>× %d</td><td>$%s</td></tr>', htmlspecialchars( $l['name'] ), htmlspecialchars( $l['description'] ), $l['quantity'], number_format( $l['unit_amount'] * $l['quantity'] / 100, 2 ) );
	}
	printf( '<!doctype html><title>Mock Stripe Checkout</title><body style="font-family:system-ui;max-width:460px;margin:60px auto">
		<p style="color:#635bff;font-weight:700">MOCK STRIPE (test)%s</p><table style="width:100%%;border-collapse:collapse" cellpadding="6">%s</table><p id="total" style="font-size:32px">Total $%s</p>
		<form method="post"><button id="pay" style="width:100%%;padding:14px;background:#635bff;color:#fff;border:0;border-radius:6px;font-size:16px">%s</button></form>
		<form method="post"><input type="hidden" name="skip_webhook" value="1"><button id="pay-no-webhook" style="margin-top:8px">Pay (webhook delayed)</button></form>
		<p><a id="cancel" href="%s">Back / cancel</a></p>', 'subscription' === $s['mode'] ? ' · SUBSCRIPTION' : '', $rows, number_format( $s['amount_total'] / 100, 2 ), 'subscription' === $s['mode'] ? 'Subscribe' : 'Pay', htmlspecialchars( $s['cancel_url'] ) );
	return;
}

if ( '/_webhook' === $path ) {
	$object = $db['sessions'][ $query['id'] ];
	unlock();
	send_webhook( $query['type'], $object );
	return out( array( 'sent' => true ) );
}

if ( in_array( $path, array( '/_renew', '/_fail', '/_end' ), true ) ) {
	$sub_id = $query['sub'];
	$sub    = $db['subs'][ $sub_id ];
	$inv_id = rid( 'in' );
	$events = array();
	if ( '/_renew' === $path ) {
		$sub['current_period_start'] = $sub['current_period_end'];
		$sub['current_period_end']   = $sub['current_period_start'] + period_seconds( $sub );
		$sub['status']               = 'active';
		$db['invoices'][ $inv_id ]   = array( 'id' => $inv_id, 'object' => 'invoice', 'subscription' => $sub_id, 'billing_reason' => 'subscription_cycle', 'amount_paid' => $sub['amount'], 'currency' => 'usd', 'payment_intent' => rid( 'pi' ), 'hosted_invoice_url' => 'https://invoice.stripe.com/mock/' . $inv_id );
		$events = array( array( 'invoice.paid', $db['invoices'][ $inv_id ] ) );
	} elseif ( '/_fail' === $path ) {
		$sub['status']             = 'past_due';
		$db['invoices'][ $inv_id ] = array( 'id' => $inv_id, 'object' => 'invoice', 'subscription' => $sub_id, 'billing_reason' => 'subscription_cycle', 'amount_paid' => 0, 'amount_due' => $sub['amount'], 'currency' => 'usd', 'hosted_invoice_url' => 'https://invoice.stripe.com/mock/' . $inv_id );
		$events = array( array( 'invoice.payment_failed', $db['invoices'][ $inv_id ] ) );
	} else {
		$sub['status']   = 'canceled';
		$sub['ended_at'] = time();
	}
	$db['subs'][ $sub_id ] = $sub;
	save();
	unlock();
	foreach ( $events as $e ) {
		send_webhook( $e[0], $e[1] );
	}
	send_webhook( '/_end' === $path ? 'customer.subscription.deleted' : 'customer.subscription.updated', $sub );
	return out( $sub );
}

function send_webhook( $type, $object ) {
	$payload = json_encode( array( 'id' => 'evt_' . substr( md5( $type . $object['id'] . microtime() ), 0, 14 ), 'type' => $type, 'data' => array( 'object' => $object ) ) );
	$t       = time();
	$sig     = hash_hmac( 'sha256', $t . '.' . $payload, WEBHOOK_SECRET );
	$ch      = curl_init( WEBHOOK_URL );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', "Stripe-Signature: t=$t,v1=$sig" ), CURLOPT_TIMEOUT => 30 ) );
	$res = curl_exec( $ch );
	file_put_contents( sys_get_temp_dir() . '/mock-stripe-webhooks.log', date( 'c' ) . " $type " . curl_getinfo( $ch, CURLINFO_HTTP_CODE ) . " $res\n", FILE_APPEND );
}

out( array( 'error' => array( 'message' => 'Not implemented in mock: ' . $method . ' ' . $path ) ), 404 );
