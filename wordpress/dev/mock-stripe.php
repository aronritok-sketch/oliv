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
 *
 * Also a Zoom stand-in under /zoom (Server-to-Server OAuth token, meetings, registrants):
 * wp-config.php: define( 'OYS_ZOOM_API_BASE', 'http://127.0.0.1:8090/zoom/v2' );
 *                define( 'OYS_ZOOM_OAUTH_URL', 'http://127.0.0.1:8090/zoom/oauth/token' );
 * Plugin settings: Account ID "acc_mock", Client ID "zoom_client", Client Secret "zoom_secret".
 *   /zoom/_meetings              every meeting and registrant (for tests)
 *
 * Stripe Connect for teachers: accounts, onboarding links (a fake onboarding page that finishes the
 * account and sends account.updated), and calls made on a connected account (Stripe-Account
 * header): Checkout Sessions with an application fee, refunds. Events from connected accounts carry
 * "account" and are signed with the Connect secret "whsec_connect_mock".
 *   /_state                      everything the mock holds (sessions, accounts, refunds), for tests
 *
 * And a Claude (Anthropic Messages API) stand-in for the newsletter drafts:
 * wp-config.php: define( 'OYS_ANTHROPIC_API_URL', 'http://127.0.0.1:8090/anthropic/v1/messages' );
 * API key "sk-ant-mock". Answers with a structured newsletter that quotes the brief.
 */

const WEBHOOK_URL    = 'http://127.0.0.1:8080/wp-json/oys/v1/stripe-webhook';
const WEBHOOK_SECRET = 'whsec_mock';
const CONNECT_SECRET = 'whsec_connect_mock';

$store_file = sys_get_temp_dir() . '/mock-stripe.json';
$fp         = fopen( $store_file . '.lock', 'c' );
flock( $fp, LOCK_EX );
$db = is_file( $store_file ) ? json_decode( file_get_contents( $store_file ), true ) : array();
$db += array( 'sessions' => array(), 'subs' => array(), 'invoices' => array(), 'meetings' => array(), 'accounts' => array(), 'refunds' => array(), 'n' => 0 );

function save() { global $db, $store_file; file_put_contents( $store_file, json_encode( $db ) ); }
function unlock() { global $fp; flock( $fp, LOCK_UN ); }
function out( $data, $code = 200 ) { http_response_code( $code ); header( 'Content-Type: application/json' ); echo json_encode( $data ); }
function rid( $prefix ) { global $db; $db['n']++; return $prefix . '_' . substr( md5( $db['n'] . microtime() ), 0, 14 ); }
function period_seconds( $sub ) { return ( 'year' === $sub['interval'] ? 365 : 30 ) * 86400 * max( 1, (int) $sub['interval_count'] ); }

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
parse_str( file_get_contents( 'php://input' ), $body );
parse_str( $_SERVER['QUERY_STRING'] ?? '', $query );
$account = $_SERVER['HTTP_STRIPE_ACCOUNT'] ?? '';

/* ---------- Zoom ---------- */
if ( '/anthropic/v1/messages' === $path ) {
	unlock();
	$req = json_decode( file_get_contents( 'php://input' ), true ) ?: array();
	if ( 'sk-ant-mock' !== ( $_SERVER['HTTP_X_API_KEY'] ?? '' ) ) {
		out( array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ), 401 );
		exit;
	}
	$ask   = (string) ( $req['messages'][0]['content'] ?? '' );
	$brief = trim( preg_replace( '/^.*(brief for this newsletter:|Olivia\'s note:)/s', '', $ask ) );
	$brief = trim( strtok( $brief, "\n" ) );
	$body  = "Hi {first_name},\n\n" . $brief . "\n\n## Coming up\n- Sunrise flow on the beach\n- Full moon flow\n\nSee you on the mat,\nOlivia";
	out( array(
		'id'          => 'msg_mock',
		'type'        => 'message',
		'role'        => 'assistant',
		'model'       => $req['model'] ?? '',
		'stop_reason' => 'end_turn',
		'content'     => array( array( 'type' => 'text', 'text' => json_encode( array( 'subject' => 'News from the studio', 'preheader' => 'What is coming up', 'body' => $body, 'button_label' => 'Book a class' ) ) ) ),
		'usage'       => array( 'input_tokens' => 100, 'output_tokens' => 80 ),
	) );
	exit;
}

if ( str_starts_with( $path, '/zoom/' ) ) {
	$json = json_decode( file_get_contents( 'php://input' ) ?: 'null', true ) ?: array();
	if ( '/zoom/oauth/token' === $path ) {
		$ok = 'Basic ' . base64_encode( 'zoom_client:zoom_secret' ) === ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) && 'acc_mock' === ( $query['account_id'] ?? '' ) && 'account_credentials' === ( $query['grant_type'] ?? '' );
		return $ok ? out( array( 'access_token' => 'zoom_token_mock', 'token_type' => 'bearer', 'expires_in' => 3599 ) ) : out( array( 'reason' => 'Invalid client_id or client_secret', 'error' => 'invalid_client' ), 400 );
	}
	if ( '/zoom/_meetings' === $path ) {
		return out( $db['meetings'] );
	}
	if ( preg_match( '#^/zoom/(j|s)/(\d+)$#', $path, $m ) ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		printf( '<!doctype html><title>Mock Zoom</title><body style="font-family:system-ui;max-width:420px;margin:60px auto"><p style="color:#2D8CFF;font-weight:700">MOCK ZOOM</p><h1 id="zoom-%s">%s meeting %s</h1>', 's' === $m[1] ? 'host' : 'join', 's' === $m[1] ? 'Starting' : 'Joining', htmlspecialchars( $m[2] ) );
		return;
	}
	if ( 'Bearer zoom_token_mock' !== ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) ) {
		return out( array( 'code' => 124, 'message' => 'Invalid access token.' ), 401 );
	}
	$base = 'http://127.0.0.1:8090/zoom';
	if ( preg_match( '#^/zoom/v2/users/([^/]+)$#', $path ) && 'GET' === $method ) {
		return out( array( 'id' => 'u_mock', 'email' => 'olivia@example.com', 'type' => 2 ) );
	}
	if ( preg_match( '#^/zoom/v2/users/([^/]+)/meetings$#', $path ) && 'POST' === $method ) {
		$db['n']++;
		$id = 80000000000 + $db['n'];
		$db['meetings'][ $id ] = array_merge( $json, array( 'id' => $id, 'join_url' => "$base/j/$id?pwd=mockpwd", 'password' => 'yoga12', 'registrants' => array(), 'status' => 'waiting' ) );
		save();
		return out( $db['meetings'][ $id ] + array( 'start_url' => "$base/s/$id?zak=first" ), 201 );
	}
	if ( preg_match( '#^/zoom/v2/meetings/(\d+)(/registrants(/status)?)?$#', $path, $m ) ) {
		$id = $m[1];
		if ( empty( $db['meetings'][ $id ] ) ) {
			return out( array( 'code' => 3001, 'message' => 'Meeting does not exist: ' . $id . '.' ), 404 );
		}
		if ( empty( $m[2] ) ) {
			if ( 'GET' === $method ) {
				return out( $db['meetings'][ $id ] + array( 'start_url' => "$base/s/$id?zak=" . substr( md5( microtime() ), 0, 8 ) ) );
			}
			if ( 'PATCH' === $method ) {
				$db['meetings'][ $id ] = array_merge( $db['meetings'][ $id ], $json );
				save();
				http_response_code( 204 );
				return;
			}
			if ( 'DELETE' === $method ) {
				unset( $db['meetings'][ $id ] );
				save();
				http_response_code( 204 );
				return;
			}
		}
		if ( '/registrants' === $m[2] && 'POST' === $method ) {
			$rid = 'reg_' . substr( md5( $json['email'] . $id ), 0, 10 );
			$db['meetings'][ $id ]['registrants'][ $rid ] = array( 'email' => $json['email'], 'first_name' => $json['first_name'] ?? '', 'status' => 'approved' );
			save();
			return out( array( 'id' => $id, 'registrant_id' => $rid, 'join_url' => "$base/j/$id?tk=$rid", 'topic' => $db['meetings'][ $id ]['topic'] ?? '' ), 201 );
		}
		if ( '/registrants/status' === $m[2] && 'PUT' === $method ) {
			foreach ( $json['registrants'] ?? array() as $r ) {
				if ( isset( $db['meetings'][ $id ]['registrants'][ $r['id'] ] ) ) {
					$db['meetings'][ $id ]['registrants'][ $r['id'] ]['status'] = 'cancel' === $json['action'] ? 'cancelled' : $json['action'];
				}
			}
			save();
			http_response_code( 204 );
			return;
		}
	}
	return out( array( 'message' => 'Not found' ), 404 );
}

if ( str_starts_with( $path, '/v1/' ) && ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) !== 'Bearer sk_test_mock' ) {
	return out( array( 'error' => array( 'message' => 'Invalid API Key provided' ) ), 401 );
}

if ( '/_state' === $path ) {
	return out( $db );
}

if ( 'POST' === $method && '/v1/accounts' === $path ) {
	$id                   = rid( 'acct' );
	$db['accounts'][ $id ] = array( 'id' => $id, 'object' => 'account', 'email' => $body['email'] ?? '', 'controller' => $body['controller'] ?? array(), 'charges_enabled' => false, 'details_submitted' => false, 'metadata' => $body['metadata'] ?? array() );
	save();
	return out( $db['accounts'][ $id ] );
}

if ( preg_match( '#^/v1/accounts/([^/]+)$#', $path, $m ) ) {
	return isset( $db['accounts'][ $m[1] ] ) ? out( $db['accounts'][ $m[1] ] ) : out( array( 'error' => array( 'message' => 'No such account' ) ), 404 );
}

if ( 'POST' === $method && '/v1/account_links' === $path ) {
	if ( empty( $db['accounts'][ $body['account'] ?? '' ] ) ) {
		return out( array( 'error' => array( 'message' => 'No such account' ) ), 400 );
	}
	return out( array( 'object' => 'account_link', 'url' => 'http://127.0.0.1:8090/connect/' . $body['account'] . '?' . http_build_query( array( 'return' => $body['return_url'], 'refresh' => $body['refresh_url'] ) ) ) );
}

// Fake Connect onboarding page.
if ( preg_match( '#^/connect/([^/]+)$#', $path, $m ) ) {
	$id = $m[1];
	if ( 'POST' === $method ) {
		$db['accounts'][ $id ]['charges_enabled']   = true;
		$db['accounts'][ $id ]['details_submitted'] = true;
		save();
		unlock();
		send_webhook( 'account.updated', $db['accounts'][ $id ], $id );
		header( 'Location: ' . $query['return'] );
		return;
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	printf( '<!doctype html><title>Mock Stripe Connect</title><body style="font-family:system-ui;max-width:420px;margin:60px auto"><p style="color:#635bff;font-weight:700">MOCK STRIPE CONNECT</p><h1>Set up payments</h1><p>Account %s</p><form method="post"><button id="connect-finish" style="width:100%%;padding:14px;background:#635bff;color:#fff;border:0;border-radius:6px">Finish</button></form><p><a id="connect-later" href="%s">Later</a></p>', htmlspecialchars( $id ), htmlspecialchars( $query['refresh'] ?? '/' ) );
	return;
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
		'account' => $account, 'application_fee_amount' => isset( $body['payment_intent_data']['application_fee_amount'] ) ? (int) $body['payment_intent_data']['application_fee_amount'] : null,
		'customer_email' => $body['customer_email'] ?? null,
	);
	if ( $account && ( empty( $db['accounts'][ $account ]['charges_enabled'] ) || ! empty( $body['customer'] ) ) ) {
		return out( array( 'error' => array( 'message' => empty( $body['customer'] ) ? 'The account cannot take charges yet.' : 'No such customer on the connected account.' ) ), 400 );
	}
	save();
	return out( $db['sessions'][ $id ] );
}

if ( preg_match( '#^/v1/checkout/sessions/([^/]+)(/expire)?$#', $path, $m ) ) {
	$s = $db['sessions'][ $m[1] ] ?? null;
	if ( ! $s || ( $s['account'] ?? '' ) !== $account ) {
		// Like Stripe: a session on a connected account is only visible with its Stripe-Account header.
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
	$found = false;
	foreach ( array_merge( $db['sessions'], $db['invoices'] ) as $s ) {
		if ( ( $s['payment_intent'] ?? '' ) === $body['payment_intent'] && ( $s['account'] ?? '' ) === $account ) {
			$amount = $amount ?: (int) ( $s['amount_total'] ?? $s['amount_paid'] );
			$found  = true;
		}
	}
	if ( ! $found ) {
		return out( array( 'error' => array( 'message' => 'No such payment_intent' ) ), 404 );
	}
	$re                = array( 'id' => rid( 're' ), 'amount' => $amount, 'status' => 'succeeded', 'payment_intent' => $body['payment_intent'], 'account' => $account, 'refund_application_fee' => $body['refund_application_fee'] ?? null );
	$db['refunds'][]   = $re;
	save();
	return out( $re );
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
			send_webhook( 'checkout.session.completed', $db['sessions'][ $id ], $s['account'] ?? '' );
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
	send_webhook( $query['type'], $object, $object['account'] ?? '' );
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

function send_webhook( $type, $object, $account = '' ) {
	$event = array( 'id' => 'evt_' . substr( md5( $type . $object['id'] . microtime() ), 0, 14 ), 'type' => $type, 'data' => array( 'object' => $object ) );
	if ( $account ) {
		$event['account'] = $account; // Connect event: from a connected account, signed by the Connect endpoint.
	}
	$payload = json_encode( $event );
	$t       = time();
	$sig     = hash_hmac( 'sha256', $t . '.' . $payload, $account ? CONNECT_SECRET : WEBHOOK_SECRET );
	$ch      = curl_init( WEBHOOK_URL );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', "Stripe-Signature: t=$t,v1=$sig" ), CURLOPT_TIMEOUT => 30 ) );
	$res = curl_exec( $ch );
	file_put_contents( sys_get_temp_dir() . '/mock-stripe-webhooks.log', date( 'c' ) . " $type " . curl_getinfo( $ch, CURLINFO_HTTP_CODE ) . " $res\n", FILE_APPEND );
}

out( array( 'error' => array( 'message' => 'Not implemented in mock: ' . $method . ' ' . $path ) ), 404 );
