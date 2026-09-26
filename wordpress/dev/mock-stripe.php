<?php
/**
 * Local Stripe stand-in for end-to-end tests (never used on a live site).
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 mock-stripe.php
 * wp-config.php: define( 'OYS_STRIPE_API_BASE', 'http://127.0.0.1:8090' );
 * Plugin settings: test secret key "sk_test_mock", webhook secret "whsec_mock".
 *
 * Implements the calls the plugin makes, plus a fake hosted checkout page that
 * "pays" and delivers a signed checkout.session.completed webhook.
 */

const WEBHOOK_URL    = 'http://127.0.0.1:8080/wp-json/oys/v1/stripe-webhook';
const WEBHOOK_SECRET = 'whsec_mock';

$store_file = sys_get_temp_dir() . '/mock-stripe.json';
$fp         = fopen( $store_file . '.lock', 'c' );
flock( $fp, LOCK_EX );
$db = is_file( $store_file ) ? json_decode( file_get_contents( $store_file ), true ) : array( 'sessions' => array(), 'n' => 0 );
function save() { global $db, $store_file; file_put_contents( $store_file, json_encode( $db ) ); }
function out( $data, $code = 200 ) { http_response_code( $code ); header( 'Content-Type: application/json' ); echo json_encode( $data ); }
function rid( $prefix ) { global $db; $db['n']++; return $prefix . '_' . substr( md5( $db['n'] . microtime() ), 0, 14 ); }

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
	$id  = rid( 'cs_test' );
	$amt = (int) $body['line_items'][0]['price_data']['unit_amount'];
	$db['sessions'][ $id ] = array(
		'id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid',
		'amount_total' => $amt, 'currency' => $body['line_items'][0]['price_data']['currency'],
		'client_reference_id' => $body['client_reference_id'] ?? null, 'metadata' => $body['metadata'] ?? array(),
		'success_url' => $body['success_url'], 'cancel_url' => $body['cancel_url'],
		'name' => $body['line_items'][0]['price_data']['product_data']['name'] ?? '',
		'payment_intent' => null, 'url' => 'http://127.0.0.1:8090/pay/' . $id,
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
		$db['sessions'][ $m[1] ]['status'] = 'expired';
		save();
		return out( $db['sessions'][ $m[1] ] );
	}
	if ( $s['payment_intent'] && in_array( 'payment_intent.latest_charge', (array) ( $query['expand'] ?? array() ), true ) ) {
		$s['payment_intent'] = array( 'id' => $s['payment_intent'], 'latest_charge' => array( 'id' => 'ch_' . substr( $s['payment_intent'], 3 ), 'receipt_url' => 'https://pay.stripe.com/receipts/mock/' . $s['payment_intent'] ) );
	}
	return out( $s );
}

if ( 'POST' === $method && '/v1/refunds' === $path ) {
	$amount = isset( $body['amount'] ) ? (int) $body['amount'] : 0;
	foreach ( $db['sessions'] as $s ) {
		if ( $s['payment_intent'] === $body['payment_intent'] ) {
			$amount = $amount ?: $s['amount_total'];
		}
	}
	return out( array( 'id' => rid( 're' ), 'amount' => $amount, 'status' => 'succeeded' ) );
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
		$db['sessions'][ $id ]['payment_intent'] = rid( 'pi' );
		save();
		flock( $fp, LOCK_UN );
		if ( empty( $_POST['skip_webhook'] ) ) {
			send_webhook( 'checkout.session.completed', $db['sessions'][ $id ] );
		}
		header( 'Location: ' . str_replace( '{CHECKOUT_SESSION_ID}', $id, $s['success_url'] ) );
		return;
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	printf( '<!doctype html><title>Mock Stripe Checkout</title><body style="font-family:system-ui;max-width:420px;margin:60px auto">
		<p style="color:#635bff;font-weight:700">MOCK STRIPE (test)</p><h1>%s</h1><p style="font-size:32px">$%s</p>
		<form method="post"><button id="pay" style="width:100%%;padding:14px;background:#635bff;color:#fff;border:0;border-radius:6px;font-size:16px">Pay</button></form>
		<form method="post"><input type="hidden" name="skip_webhook" value="1"><button id="pay-no-webhook" style="margin-top:8px">Pay (webhook delayed)</button></form>
		<p><a id="cancel" href="%s">Back / cancel</a></p>', htmlspecialchars( $s['name'] ), number_format( $s['amount_total'] / 100, 2 ), htmlspecialchars( $s['cancel_url'] ) );
	return;
}

if ( '/_webhook' === $path ) { // Test helper: re-send an event for a session.
	$object = $db['sessions'][ $query['id'] ];
	flock( $fp, LOCK_UN );
	send_webhook( $query['type'], $object );
	return out( array( 'sent' => true ) );
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
