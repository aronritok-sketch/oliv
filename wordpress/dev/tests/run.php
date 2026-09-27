<?php
/**
 * Integration tests for the booking logic, run against a real WordPress + database.
 *   WP_DIR=/path/to/wordpress php wordpress/dev/tests/run.php
 * Each test runs inside a database transaction that is rolled back, so the site is left as it was.
 * Stripe calls are answered in-process (pre_http_request), so no network or mock server is needed.
 */

$wp_dir = getenv( 'WP_DIR' ) ?: '';
if ( ! $wp_dir || ! file_exists( $wp_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set WP_DIR to a WordPress install with the plugin active.\n" );
	exit( 2 );
}
$_SERVER['REMOTE_ADDR'] = '10.9.8.7';
$_SERVER['HTTP_HOST']   = '127.0.0.1:8080';
require $wp_dir . '/wp-load.php';

if ( ! class_exists( 'OYS_Bookings' ) ) {
	fwrite( STDERR, "Olivia Studio plugin is not active.\n" );
	exit( 2 );
}

/* ---------- Tiny test harness ---------- */

$GLOBALS['oys_t'] = array( 'pass' => 0, 'fail' => 0, 'current' => '' );

function ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['oys_t']['pass']++;
	} else {
		$GLOBALS['oys_t']['fail']++;
		echo "  FAIL [{$GLOBALS['oys_t']['current']}] $msg\n";
	}
}

function eq( $expected, $actual, $msg ) {
	ok( $expected == $actual, $msg . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

function test( $name, callable $fn ) {
	global $wpdb;
	$GLOBALS['oys_t']['current'] = $name;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$fn();
		echo "ok   $name\n";
	} catch ( Throwable $e ) {
		$GLOBALS['oys_t']['fail']++;
		echo "  FAIL [$name] exception: " . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	}
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
}

/* ---------- Fixtures ---------- */

// No real emails.
add_filter( 'pre_wp_mail', '__return_true' );

// In-process Stripe: remembers what was asked, answers like the API.
$GLOBALS['stripe_calls'] = array();
$GLOBALS['stripe_subs']  = array();
// In-process Zoom: token, meetings, registrants. $GLOBALS['zoom_fail'] = true makes meeting creation fail.
$GLOBALS['zoom_calls']    = array();
$GLOBALS['zoom_meetings'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$is_zoom = str_starts_with( $url, OYS_Zoom::api_base() ) || str_contains( $url, 'oauth/token' );
	if ( ! $is_zoom ) {
		return $pre;
	}
	$path = str_contains( $url, 'oauth/token' ) ? '/oauth' : substr( parse_url( $url, PHP_URL_PATH ), strlen( parse_url( OYS_Zoom::api_base(), PHP_URL_PATH ) ) );
	$body = json_decode( $args['body'] ?? 'null', true ) ?: array();
	$GLOBALS['zoom_calls'][] = array( $args['method'] ?? 'POST', $path, $body );
	$code = 200;
	$json = array();
	if ( '/oauth' === $path ) {
		$json = array( 'access_token' => 'tok', 'expires_in' => 3600 );
	} elseif ( preg_match( '#^/users/[^/]+/meetings$#', $path ) ) {
		if ( ! empty( $GLOBALS['zoom_fail'] ) ) {
			$code = 500;
			$json = array( 'message' => 'Zoom is down' );
		} else {
			$id   = 90000 + count( $GLOBALS['zoom_meetings'] ) + 1;
			$json = array( 'id' => $id, 'join_url' => "https://zoom.test/j/$id?pwd=x", 'password' => 'yoga12' );
			$GLOBALS['zoom_meetings'][ $id ] = $body;
			$code = 201;
		}
	} elseif ( preg_match( '#^/meetings/(\d+)/registrants$#', $path, $m ) ) {
		$json = array( 'registrant_id' => 'reg_' . md5( $body['email'] ), 'join_url' => "https://zoom.test/j/{$m[1]}?tk=" . md5( $body['email'] ) );
		$code = 201;
	} elseif ( preg_match( '#^/meetings/(\d+)$#', $path, $m ) ) {
		if ( 'GET' === $args['method'] ) {
			$json = array( 'id' => $m[1], 'start_url' => "https://zoom.test/s/{$m[1]}?zak=fresh" );
		} else {
			$code = 204;
		}
	} else {
		$code = 204;
	}
	return array( 'headers' => array(), 'body' => $json ? wp_json_encode( $json ) : '', 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 9, 3 );
function zoom_calls( $method, $pattern ) {
	return count( array_filter( $GLOBALS['zoom_calls'], fn( $c ) => $c[0] === $method && preg_match( $pattern, $c[1] ) ) );
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== $pre || ! str_starts_with( $url, OYS_Stripe::api_base() ) ) {
		return $pre;
	}
	$path = parse_url( $url, PHP_URL_PATH );
	parse_str( is_string( $args['body'] ?? null ) ? $args['body'] : '', $body );
	// [ method, path, body, connected account (Stripe-Account header) ]
	$GLOBALS['stripe_calls'][] = array( $args['method'], $path, $body, $args['headers']['Stripe-Account'] ?? '' );
	$json = array( 'id' => 'x_' . count( $GLOBALS['stripe_calls'] ) );
	if ( '/v1/accounts' === $path ) {
		$json = array( 'id' => 'acct_new' . count( $GLOBALS['stripe_calls'] ), 'charges_enabled' => false, 'details_submitted' => false );
	} elseif ( preg_match( '#^/v1/accounts/(.+)$#', $path, $m ) ) {
		$json = array( 'id' => $m[1], 'charges_enabled' => ! empty( $GLOBALS['acct_ready'] ), 'details_submitted' => ! empty( $GLOBALS['acct_ready'] ) );
	} elseif ( '/v1/account_links' === $path ) {
		$json = array( 'url' => 'https://connect.stripe.test/setup/' . $body['account'] );
	} elseif ( '/v1/customers' === $path ) {
		$json = array( 'id' => 'cus_test' );
	} elseif ( '/v1/checkout/sessions' === $path ) {
		$json = array( 'id' => 'cs_' . md5( wp_json_encode( $body ) . count( $GLOBALS['stripe_calls'] ) ), 'url' => 'https://checkout.stripe.test/pay' );
	} elseif ( '/v1/refunds' === $path ) {
		$json = array( 'id' => 're_1', 'amount' => (int) ( $body['amount'] ?? 2500 ) );
	} elseif ( preg_match( '#^/v1/subscriptions/(.+)$#', $path, $m ) ) {
		$sub = $GLOBALS['stripe_subs'][ $m[1] ] ?? array( 'id' => $m[1], 'status' => 'active', 'current_period_start' => time(), 'current_period_end' => time() + 30 * DAY_IN_SECONDS, 'cancel_at_period_end' => false );
		if ( isset( $body['cancel_at_period_end'] ) ) {
			$sub['cancel_at_period_end'] = 'true' === $body['cancel_at_period_end'];
		}
		if ( 'DELETE' === $args['method'] ) {
			$sub['status']   = 'canceled';
			$sub['ended_at'] = time();
		}
		$GLOBALS['stripe_subs'][ $m[1] ] = $sub;
		$json = $sub;
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( $json ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

// Settings are changed for the tests and restored at the end (they live outside the transactions).
$original_settings = get_option( OYS_Settings::OPTION );
$original_templates = get_option( 'oys_email_templates' );
register_shutdown_function( function () use ( $original_settings, $original_templates ) {
	update_option( OYS_Settings::OPTION, $original_settings );
	false === $original_templates ? delete_option( 'oys_email_templates' ) : update_option( 'oys_email_templates', $original_templates );
} );
OYS_Settings::update( array( 'stripe_test_secret' => 'sk_test_unit', 'stripe_test_webhook' => 'whsec_unit', 'stripe_mode' => 'test', 'max_guests' => 4, 'cancel_hours' => 12 ) );

function make_user( $name = 'Test' ) {
	static $n = 0;
	$n++;
	$email = strtolower( $name ) . '.' . $n . '.' . wp_generate_password( 6, false ) . '@example.test';
	$id    = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => 'x-12345678', 'first_name' => $name, 'role' => 'oys_customer' ) );
	OYS_Customers::record_waiver( $id );
	return $id;
}

function make_session( $args = array() ) {
	$start = time() + (int) round( ( $args['in_hours'] ?? 72 ) * HOUR_IN_SECONDS );
	return OYS_Schedule::get( OYS_Schedule::save( array(
		'kind'            => $args['kind'] ?? 'group',
		'class_slug'      => 'hatha-flow',
		'starts_at'       => gmdate( 'Y-m-d H:i:s', $start ),
		'ends_at'         => gmdate( 'Y-m-d H:i:s', $start + 3600 ),
		'capacity'        => $args['capacity'] ?? 10,
		'format'          => $args['format'] ?? 'studio',
		'online_capacity'    => $args['online_capacity'] ?? 0,
		'online_price_cents' => $args['online_price'] ?? 0,
		'online_url'         => $args['online_url'] ?? '',
		'price_cents'     => $args['price'] ?? 2500,
		'credits_allowed' => $args['credits_allowed'] ?? 1,
		'pricing'         => $args['pricing'] ?? 'fixed',
		'pay_later'       => $args['pay_later'] ?? 1,
		'location'        => $args['location'] ?? '',
		'min_people'      => $args['min_people'] ?? null,
		'decide_hours'    => $args['decide_hours'] ?? null,
		'teacher_id'      => $args['teacher'] ?? 0,
		'status'          => 'scheduled',
	) ) );
}

function seats( $session ) {
	return (int) OYS_Schedule::get( $session->id )->booked;
}

function guests( ...$names ) {
	return array_map( fn( $n ) => array( 'name' => $n, 'email' => '' ), $names );
}

function make_membership( $user_id, $limit = 0, $args = array() ) {
	global $wpdb;
	$wpdb->insert( OYS_Install::table( 'memberships' ), array_merge( array(
		'user_id'                => $user_id,
		'product_id'             => $limit ? 'four-a-month' : 'unlimited-monthly',
		'name'                   => 'Test plan',
		'classes_per_period'     => $limit,
		'status'                 => 'active',
		'current_period_start'   => oys_utc_plus( -DAY_IN_SECONDS ),
		'current_period_end'     => oys_utc_plus( 29 * DAY_IN_SECONDS ),
		'stripe_subscription_id' => 'sub_' . wp_generate_password( 10, false ),
		'stripe_customer_id'     => 'cus_test',
		'created_at'             => oys_now(),
	), $args ) );
	return OYS_Memberships::get( $wpdb->insert_id );
}

/* ---------- Tests ---------- */

test( 'seats: party is taken atomically, all or nothing', function () {
	$s = make_session( array( 'capacity' => 3 ) );
	ok( OYS_Schedule::take_seats( $s->id, 2 ), 'two seats taken' );
	ok( ! OYS_Schedule::take_seats( $s->id, 2 ), 'two more refused when only one is left' );
	eq( 2, seats( $s ), 'refused request took nothing' );
	ok( OYS_Schedule::take_seats( $s->id, 1 ), 'last seat taken' );
	OYS_Schedule::release_seats( $s->id, 5 );
	eq( 0, seats( $s ), 'release never goes below zero' );
} );

test( 'credits: party booked from the pass, rollback when short', function () {
	$u = make_user();
	$s = make_session();
	OYS_Passes::grant( $u, array( 'credits' => 3 ) );
	$id = OYS_Bookings::book_party( $u, $s, array( 'method' => 'credit', 'guests' => guests( 'Bea', 'Cora' ), 'notify' => false ) );
	ok( is_int( $id ) && $id > 0, 'booked' );
	eq( 0, OYS_Passes::balance( $u ), 'three credits used' );
	eq( 3, seats( $s ), 'three seats' );
	eq( 2, count( OYS_Bookings::guests_of( $id ) ), 'two guest rows' );

	$u2 = make_user();
	OYS_Passes::grant( $u2, array( 'credits' => 1 ) );
	$res = OYS_Bookings::book_party( $u2, $s, array( 'method' => 'credit', 'guests' => guests( 'Dan' ), 'notify' => false ) );
	ok( is_wp_error( $res ) && 'oys_no_credit' === $res->get_error_code(), 'refused without enough credits' );
	eq( 1, OYS_Passes::balance( $u2 ), 'no credit taken' );
	eq( 3, seats( $s ), 'no seat taken' );
} );

test( 'cancel: on time returns credits for the whole party, late does not', function () {
	$u = make_user();
	$s = make_session();
	OYS_Passes::grant( $u, array( 'credits' => 5 ) );
	$id = OYS_Bookings::book_party( $u, $s, array( 'method' => 'credit', 'guests' => guests( 'Bea' ), 'notify' => false ) );
	eq( 'returned', OYS_Bookings::cancel( $id, array( 'notify' => false ) ), 'outcome returned' );
	eq( 5, OYS_Passes::balance( $u ), 'both credits back' );
	eq( 0, seats( $s ), 'seats released' );

	$late = make_session( array( 'in_hours' => 3 ) );
	$id2  = OYS_Bookings::book_party( $u, $late, array( 'method' => 'credit', 'notify' => false ) );
	eq( 'late', OYS_Bookings::cancel( $id2, array( 'notify' => false ) ), 'late cancel' );
	eq( 4, OYS_Passes::balance( $u ), 'late cancel keeps the credit used' );
	eq( 'late_cancelled', OYS_Bookings::get( $id2 )->status, 'status late_cancelled' );
} );

test( 'guests: remove one guest, add one later', function () {
	$u = make_user();
	$s = make_session();
	OYS_Passes::grant( $u, array( 'credits' => 4 ) );
	$host = OYS_Bookings::book_party( $u, $s, array( 'method' => 'credit', 'guests' => guests( 'Bea', 'Cora' ), 'notify' => false ) );
	$g    = OYS_Bookings::guests_of( $host );
	OYS_Bookings::cancel( $g[0]->id, array( 'notify' => false ) );
	eq( 'confirmed', OYS_Bookings::get( $host )->status, 'host still booked after removing a guest' );
	eq( 2, OYS_Passes::balance( $u ), 'removed guest credit returned' );
	$res = OYS_Bookings::book_party( $u, $s, array( 'method' => 'credit', 'guests' => guests( 'Dora' ), 'host_booking' => $host, 'notify' => false ) );
	eq( $host, $res, 'adding guests returns the host booking' );
	eq( 2, count( OYS_Bookings::guests_of( $host, array( 'confirmed' ) ) ), 'two active guests' );
	ok( null === OYS_Bookings::active_for( make_user(), $s->id ), 'guest rows never count as another customer\'s booking' );
} );

test( 'card: hold party, confirm once, release on expiry', function () {
	$u        = make_user();
	$s        = make_session();
	$order_id = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 7500 ) );
	$first    = OYS_Bookings::hold( $u, $s, $order_id, guests( 'Bea', 'Cora' ) );
	ok( is_int( $first ), 'held' );
	eq( 3, seats( $s ), 'three seats held' );
	OYS_Orders::update( $order_id, array( 'booking_id' => $first ) );
	ok( OYS_Orders::mark_paid( $order_id ), 'first mark_paid fulfils' );
	ok( ! OYS_Orders::mark_paid( $order_id ), 'second mark_paid is a no-op' );
	eq( 3, count( OYS_Bookings::for_order( $order_id, array( 'confirmed' ) ) ), 'all three confirmed' );
	eq( 3, seats( $s ), 'no double counting' );

	$o2 = OYS_Orders::create( array( 'user_id' => make_user(), 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 2500 ) );
	OYS_Bookings::hold( get_userdata( OYS_Orders::get( $o2 )->user_id )->ID, $s, $o2 );
	eq( 4, seats( $s ), 'second hold' );
	OYS_Orders::mark_unpaid( $o2, 'expired' );
	eq( 3, seats( $s ), 'expired checkout releases its seat' );
} );

test( 'refund: full refund cancels the party, partial does not', function () {
	$u        = make_user();
	$s        = make_session();
	$order_id = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 5000 ) );
	OYS_Bookings::hold( $u, $s, $order_id, guests( 'Bea' ) );
	OYS_Orders::mark_paid( $order_id, array( 'payment_intent' => 'pi_1' ) );
	OYS_Orders::mark_refunded( $order_id, 2500 );
	eq( 'partially_refunded', OYS_Orders::get( $order_id )->status, 'partial refund status' );
	eq( 2, count( OYS_Bookings::for_order( $order_id, array( 'confirmed' ) ) ), 'partial refund keeps bookings' );
	OYS_Orders::mark_refunded( $order_id, 5000 );
	eq( 'refunded', OYS_Orders::get( $order_id )->status, 'full refund status' );
	eq( 0, count( OYS_Bookings::for_order( $order_id, array( 'confirmed' ) ) ), 'full refund cancels bookings' );
	eq( 0, seats( $s ), 'seats released' );
} );

test( 'webhook signature: valid, tampered, stale', function () {
	$payload = '{"id":"evt_1"}';
	$t       = time();
	$sig     = hash_hmac( 'sha256', "$t.$payload", 'whsec_unit' );
	ok( OYS_Stripe::verify_signature( $payload, "t=$t,v1=$sig", 'whsec_unit' ), 'valid signature accepted' );
	ok( ! OYS_Stripe::verify_signature( $payload . ' ', "t=$t,v1=$sig", 'whsec_unit' ), 'tampered payload rejected' );
	ok( ! OYS_Stripe::verify_signature( $payload, "t=$t,v1=$sig", 'whsec_other' ), 'wrong secret rejected' );
	$old = $t - 3600;
	ok( ! OYS_Stripe::verify_signature( $payload, "t=$old,v1=" . hash_hmac( 'sha256', "$old.$payload", 'whsec_unit' ), 'whsec_unit' ), 'stale timestamp rejected' );
} );

test( 'webhook: duplicate events are processed once', function () {
	$req = function ( $event ) {
		$payload = wp_json_encode( $event );
		$t       = time();
		$r       = new WP_REST_Request( 'POST', '/oys/v1/stripe-webhook' );
		$r->set_body( $payload );
		$r->set_header( 'stripe-signature', "t=$t,v1=" . hash_hmac( 'sha256', "$t.$payload", 'whsec_unit' ) );
		return OYS_Stripe::webhook( $r );
	};
	$event = array( 'id' => 'evt_dupe_' . wp_generate_password( 6, false ), 'type' => 'ping.test', 'data' => array( 'object' => array() ) );
	eq( 200, $req( $event )->get_status(), 'first delivery accepted' );
	$second = $req( $event );
	ok( ! empty( $second->get_data()['duplicate'] ), 'second delivery recognised as duplicate' );
} );

test( 'membership: covers classes, per-period limit, guests not covered', function () {
	$u = make_user();
	$m = make_membership( $u, 2 );
	$a = make_session();
	$b = make_session( array( 'in_hours' => 96 ) );
	$c = make_session( array( 'in_hours' => 120 ) );
	ok( is_int( OYS_Bookings::book_with_membership( $u, $a, false ) ), 'first class booked' );
	ok( is_int( OYS_Bookings::book_with_membership( $u, $b, false ) ), 'second class booked' );
	$third = OYS_Bookings::book_with_membership( $u, $c, false );
	ok( is_wp_error( $third ) && 'oys_membership' === $third->get_error_code(), 'third refused on a 2-class plan' );
	eq( 2, OYS_Memberships::used_in_period( $m ), 'usage counted' );
	OYS_Bookings::cancel( OYS_Bookings::active_for( $u, $a->id )->id, array( 'notify' => false ) );
	eq( 1, OYS_Memberships::used_in_period( $m ), 'on-time cancel frees the class' );

	$next = make_session( array( 'in_hours' => 40 * 24 ) );
	ok( is_wp_error( OYS_Memberships::covers( $m, $next ) ), 'limited plan: class in the next period not covered yet' );
	$private = make_session( array( 'kind' => 'private' ) );
	ok( is_wp_error( OYS_Memberships::covers( $m, $private ) ), 'private sessions not covered' );

	$unl = make_membership( make_user(), 0 );
	ok( true === OYS_Memberships::covers( $unl, $next ), 'unlimited renewing plan covers later classes' );
} );

test( 'membership: scheduled cancel limits bookings to the paid period; ending cancels future bookings', function () {
	$u = make_user();
	$m = make_membership( $u, 0 );
	OYS_Memberships::set_cancel_at_period_end( $m, true );
	$m = OYS_Memberships::get( $m->id );
	eq( 1, (int) $m->cancel_at_period_end, 'cancel scheduled' );
	ok( is_wp_error( OYS_Memberships::covers( $m, make_session( array( 'in_hours' => 40 * 24 ) ) ) ), 'class after the end date not covered' );
	$soon = make_session();
	$bid  = OYS_Bookings::book_with_membership( $u, $soon, false );
	ok( is_int( $bid ), 'class inside the period still bookable' );
	OYS_Memberships::sync( array( 'id' => $m->stripe_subscription_id, 'status' => 'canceled', 'ended_at' => time() - 60, 'cancel_at_period_end' => false ), false );
	eq( 'cancelled', OYS_Memberships::get( $m->id )->status, 'membership ended' );
	eq( 'cancelled', OYS_Bookings::get( $bid )->status, 'future membership booking cancelled' );
} );

test( 'membership: renewal invoice recorded once as a payment', function () {
	$u   = make_user();
	$m   = make_membership( $u, 0 );
	$inv = array( 'id' => 'in_' . wp_generate_password( 8, false ), 'subscription' => $m->stripe_subscription_id, 'billing_reason' => 'subscription_cycle', 'amount_paid' => 11900, 'currency' => 'usd', 'hosted_invoice_url' => 'https://invoice.test' );
	OYS_Memberships::record_invoice( $inv );
	OYS_Memberships::record_invoice( $inv );
	global $wpdb;
	eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'orders' ) . ' WHERE stripe_invoice_id = %s', $inv['id'] ) ), 'one renewal order' );
	OYS_Memberships::payment_failed( array( 'subscription' => $m->stripe_subscription_id ) );
	eq( 'past_due', OYS_Memberships::get( $m->id )->status, 'failed payment → past due' );
	ok( in_array( 'past_due', OYS_Memberships::BOOKABLE, true ), 'past due members can still book while Stripe retries' );
} );

test( 'waitlist: a member is moved in when a seat opens', function () {
	$s     = make_session( array( 'capacity' => 1 ) );
	$first = make_user();
	OYS_Passes::grant( $first, array( 'credits' => 1 ) );
	$bid    = OYS_Bookings::book_with_credit( $first, $s, false );
	$member = make_user();
	make_membership( $member, 0 );
	OYS_Bookings::join_waitlist( $member, $s->id );
	OYS_Bookings::cancel( $bid, array( 'notify' => false ) );
	$in = OYS_Bookings::active_for( $member, $s->id );
	ok( $in && 'membership' === $in->paid_with, 'member booked from the waitlist with the membership' );
	eq( 0, OYS_Bookings::waitlist_position( $member, $s->id ), 'removed from the waitlist' );
} );

test( 'gift card: redeem once', function () {
	global $wpdb;
	$code = OYS_Gifts::generate_code();
	$wpdb->insert( OYS_Install::table( 'gift_cards' ), array( 'code' => $code, 'product_id' => 'pack-5', 'status' => 'active', 'created_at' => oys_now() ) );
	$u = make_user();
	ok( is_int( OYS_Gifts::redeem( strtolower( $code ), $u ) ), 'redeemed (case-insensitive)' );
	eq( 5, OYS_Passes::balance( $u ), '5 classes added' );
	ok( is_wp_error( OYS_Gifts::redeem( $code, make_user() ) ), 'second redemption refused' );
	ok( 1 === preg_match( '/^OY-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code ), 'code format has no ambiguous characters' );
} );

test( 'rate limiting: login locks after repeated failures', function () {
	$_SERVER['REMOTE_ADDR'] = '10.1.2.' . wp_rand( 1, 250 );
	ok( ! OYS_Security::login_locked( 'someone@example.test' ), 'not locked at first' );
	for ( $i = 0; $i < OYS_Security::MAX_LOGIN_FAILS; $i++ ) {
		OYS_Security::record_failure( 'someone@example.test' );
	}
	ok( OYS_Security::login_locked( 'someone@example.test' ), 'locked after the limit' );
	ok( is_wp_error( OYS_Security::block_if_locked( null, 'someone@example.test' ) ), 'authentication blocked' );
} );

test( 'stripe checkout: party receipt has a guest line with quantity', function () {
	$GLOBALS['stripe_calls'] = array();
	$u        = make_user();
	$s        = make_session();
	$order_id = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 7500 ) );
	$url      = OYS_Stripe::start_checkout( $order_id, 'Hatha Flow', 'Mon', array( array( 'Hatha Flow', 'Mon', 2500, 1 ), array( 'Guest ticket: Hatha Flow', 'Bea, Cora', 2500, 2 ) ) );
	ok( is_string( $url ), 'checkout started' );
	$call  = array_values( array_filter( $GLOBALS['stripe_calls'], fn( $c ) => '/v1/checkout/sessions' === $c[1] ) )[0];
	$items = $call[2]['line_items'];
	eq( 2, count( $items ), 'two line items' );
	eq( 2, (int) $items[1]['quantity'], 'guest line quantity 2' );
	eq( 'Guest ticket: Hatha Flow', $items[1]['price_data']['product_data']['name'], 'guest line name' );
	eq( 'payment', $call[2]['mode'], 'payment mode' );
} );

test( 'online: a studio class converts into online classes, online credits go first', function () {
	OYS_Settings::update( array( 'online_per_credit' => 4 ) );
	$u      = make_user();
	$studio = make_session();
	$on1    = make_session( array( 'format' => 'online', 'price' => 600 ) );
	$on2    = make_session( array( 'format' => 'online', 'price' => 600, 'in_hours' => 96 ) );
	$src    = OYS_Passes::grant( $u, array( 'credits' => 2, 'name' => '5-class pass', 'validity_days' => 60 ) );
	eq( 'online', OYS_Bookings::credit_kind( $on1 ), 'online class uses online credits' );
	eq( 8, OYS_Passes::available_for( $u, $on1 ), '2 studio classes = 8 online classes' );
	eq( 2, OYS_Passes::available_for( $u, $studio ), 'studio class sees studio credits only' );
	$b = OYS_Bookings::book_with_credit( $u, $on1, false );
	ok( ! is_wp_error( $b ), 'online class booked with the studio pass' );
	eq( 1, OYS_Passes::balance( $u, 'class' ), 'one studio class used' );
	eq( 3, OYS_Passes::balance( $u, 'online' ), 'three online classes left over' );
	$conv = OYS_Passes::get( OYS_Bookings::get( $b )->pass_id );
	eq( 'convert', $conv->source, 'converted pass marked' );
	eq( OYS_Passes::get( $src )->expires_at, $conv->expires_at, 'same expiry as the studio pass' );
	OYS_Bookings::book_with_credit( $u, $on2, false );
	eq( 1, OYS_Passes::balance( $u, 'class' ), 'second online class uses the online credits' );
	eq( 2, OYS_Passes::balance( $u, 'online' ), 'online credits down to 2' );
	OYS_Bookings::cancel( OYS_Bookings::active_for( $u, $on2->id )->id, array( 'notify' => false ) );
	eq( 3, OYS_Passes::balance( $u, 'online' ), 'on-time cancel returns the online credit' );
	eq( 1, OYS_Passes::available_for( $u, $studio ), 'online credits never pay for a studio class' );
} );

test( 'online: party on an online class converts as many studio classes as needed', function () {
	OYS_Settings::update( array( 'online_per_credit' => 2 ) );
	$u  = make_user();
	$on = make_session( array( 'format' => 'online', 'price' => 600 ) );
	OYS_Passes::grant( $u, array( 'credits' => 2 ) );
	$r = OYS_Bookings::book_party( $u, $on, array( 'method' => 'credit', 'guests' => guests( 'Bea', 'Cora' ), 'notify' => false ) );
	ok( ! is_wp_error( $r ), '3 people booked' );
	eq( 0, OYS_Passes::balance( $u, 'class' ), 'two studio classes converted' );
	eq( 1, OYS_Passes::balance( $u, 'online' ), 'one online class left' );
	OYS_Settings::update( array( 'online_per_credit' => 4 ) );
} );

test( 'online: memberships include online classes without using the monthly limit', function () {
	$u  = make_user();
	$m  = make_membership( $u, 1 );
	$st = make_session();
	$on = make_session( array( 'format' => 'online', 'price' => 600, 'in_hours' => 30 ) );
	ok( ! is_wp_error( OYS_Bookings::book_with_membership( $u, $st, false ) ), 'studio class uses the one class' );
	eq( true, OYS_Memberships::covers( OYS_Memberships::current_for( $u ), $on ), 'online class still covered' );
	ok( ! is_wp_error( OYS_Bookings::book_with_membership( $u, $on, false ) ), 'online class booked' );
	eq( 1, OYS_Memberships::used_in_period( OYS_Memberships::current_for( $u ) ), 'online class not counted' );
	ok( is_wp_error( OYS_Memberships::covers( OYS_Memberships::current_for( $u ), make_session( array( 'in_hours' => 50 ) ) ) ), 'another studio class is over the limit' );
} );

test( 'online: private session prices', function () {
	ok( OYS_Products::private_price_for( 60, true ) > 0, 'online price exists' );
	ok( OYS_Products::private_price_for( 60, true ) < OYS_Products::private_price_for( 60 ), 'online private is cheaper' );
	eq( 'online', OYS_Products::credit_kind( array( 'kind' => 'online_pack' ) ), 'online pass gives online credits' );
} );

function cal_req( $params, $id = 0 ) {
	$r = new WP_REST_Request( 'POST', '/oys/v1/admin/sessions' . ( $id ? '/' . $id : '' ) );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( wp_json_encode( $params ) );
	if ( $id ) {
		$r->set_url_params( array( 'id' => $id ) );
	}
	return $r;
}

test( 'calendar: weekly class, move one date, change the series, stop it', function () {
	global $wpdb;
	$tz    = wp_timezone();
	$first = ( new DateTimeImmutable( 'today', $tz ) )->modify( '+8 days' );
	$res   = OYS_Calendar::rest_save( cal_req( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'date' => $first->format( 'Y-m-d' ), 'start' => '10:00', 'duration' => 60, 'capacity' => 10, 'format' => 'online', 'price' => 600, 'repeat' => 'weekly' ) ) );
	ok( ! is_wp_error( $res ), 'weekly class created' );
	$s1  = $res->get_data()['session'];
	$tpl = OYS_Schedule::template( OYS_Schedule::get( $s1['id'] )->template_id );
	eq( $first->format( 'Y-m-d' ), $tpl->valid_from, 'repeats from the chosen date' );
	eq( 'online', $tpl->format, 'weekly template is online' );
	$dates = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE template_id = %d ORDER BY starts_at', $tpl->id ) );
	ok( count( $dates ) >= 2, 'several weeks created' );
	eq( $first->format( 'Y-m-d' ), $s1['date'], 'first date is the chosen one, nothing earlier' );
	eq( '10:00', $s1['start'], 'local start time kept' );

	// Move only the first date to the next day at 11:00; generating again must not re-create it.
	$moved = OYS_Calendar::rest_save( cal_req( array_merge( $s1, array( 'date' => $first->modify( '+1 day' )->format( 'Y-m-d' ), 'start' => '11:00', 'scope' => 'one' ) ), $s1['id'] ), $s1['id'] );
	ok( ! is_wp_error( $moved ), 'moved one date' );
	OYS_Schedule::generate();
	$on_old_day = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE template_id = %d AND starts_at = %s', $tpl->id, oys_local_to_utc( $first->format( 'Y-m-d' ) . 'T10:00' ) ) );
	eq( 0, $on_old_day, 'the moved date is not created again' );

	// Someone books the second date; the series moves to 9:30 and they are emailed.
	$u  = make_user();
	$s2 = OYS_Schedule::get( $dates[1]->id );
	OYS_Bookings::book_manual( $u, $s2, 'comp', false );
	$sent = array();
	add_action( 'oys_email_sent', function ( $to, $subject ) use ( &$sent ) { $sent[] = $subject; }, 10, 2 );
	$d2  = OYS_Calendar::out( $s2 );
	$res = OYS_Calendar::rest_save( cal_req( array_merge( $d2, array( 'start' => '09:30', 'scope' => 'series', 'notify' => true ) ), $s2->id ), $s2->id );
	ok( ! is_wp_error( $res ), 'series changed' );
	eq( '09:30', OYS_Schedule::template( $tpl->id )->start_time, 'weekly time changed' );
	$later = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE template_id = %d AND starts_at >= %s ORDER BY starts_at', $tpl->id, $dates[1]->starts_at ) );
	ok( count( $later ) >= 1 && ! array_filter( $later, fn( $x ) => '09:30' !== wp_date( 'H:i', oys_ts( $x->starts_at ) ) ), 'every following date is at 9:30' );
	eq( '11:00', OYS_Calendar::out( OYS_Schedule::get( $s1['id'] ) )['start'], 'the earlier moved date is untouched' );
	ok( (bool) array_filter( $sent, fn( $x ) => str_starts_with( $x, 'Changed:' ) ), 'the person booked was emailed' );
	$before = count( $later );
	OYS_Schedule::generate();
	eq( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE template_id = %d AND starts_at >= %s', $tpl->id, $dates[1]->starts_at ) ), 'no duplicates after generating again' );

	// Stop the weekly class from the third date.
	$c = new WP_REST_Request( 'POST', '/oys/v1/admin/sessions/' . $later[1]->id . '/cancel' );
	$c->set_url_params( array( 'id' => $later[1]->id ) );
	$c->set_body_params( array( 'scope' => 'series' ) );
	OYS_Calendar::rest_cancel( $c );
	eq( 0, (int) OYS_Schedule::template( $tpl->id )->active, 'weekly class stopped' );
	eq( 'scheduled', OYS_Schedule::get( $later[0]->id )->status, 'earlier date kept' );
	eq( 'cancelled', OYS_Schedule::get( $later[1]->id )->status, 'this date cancelled' );
} );

test( 'calendar: validation and capacity below bookings', function () {
	$bad = OYS_Calendar::rest_save( cal_req( array( 'kind' => 'group', 'class_slug' => '', 'date' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ), 'start' => '10:00' ) ) );
	ok( is_wp_error( $bad ), 'a class needs a class type' );
	$past = OYS_Calendar::rest_save( cal_req( array( 'kind' => 'event', 'title' => 'Old', 'date' => wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), 'start' => '10:00' ) ) );
	ok( is_wp_error( $past ), 'no new sessions in the past' );
	$s = make_session( array( 'capacity' => 3 ) );
	OYS_Bookings::book_party( make_user(), $s, array( 'method' => 'free', 'guests' => guests( 'A' ), 'notify' => false ) );
	$d = OYS_Calendar::out( OYS_Schedule::get( $s->id ) );
	OYS_Calendar::rest_save( cal_req( array_merge( $d, array( 'capacity' => 1 ) ), $s->id ), $s->id );
	eq( 2, (int) OYS_Schedule::get( $s->id )->capacity, 'capacity never below the people booked' );
} );

test( 'hybrid: studio and online seats are counted separately', function () {
	$s = make_session( array( 'format' => 'hybrid', 'capacity' => 1, 'online_capacity' => 2, 'online_price' => 600 ) );
	ok( ! is_wp_error( OYS_Bookings::book_manual( make_user(), $s, 'comp', false ) ), 'studio seat booked' );
	$b    = make_user();
	$full = OYS_Bookings::book_manual( $b, $s, 'comp', false );
	ok( is_wp_error( $full ) && str_contains( $full->get_error_message(), 'live online' ), 'full studio points to the online option' );
	$on = OYS_Bookings::book_manual( $b, $s, 'comp', false, false, 'online' );
	ok( ! is_wp_error( $on ), 'online seat booked while the studio is full' );
	$row = OYS_Schedule::get( $s->id );
	eq( 1, (int) $row->booked, 'studio count' );
	eq( 1, (int) $row->online_booked, 'online count' );
	eq( 'online', OYS_Bookings::get( $on )->mode, 'booking remembers it is online' );
	$party = OYS_Bookings::book_party( make_user(), $s, array( 'method' => 'comp', 'mode' => 'online', 'guests' => guests( 'Bea' ), 'notify' => false ) );
	ok( is_wp_error( $party ), 'two more online people do not fit in the last online seat' );
	eq( 1, (int) OYS_Schedule::get( $s->id )->online_booked, 'nothing half-booked' );
	OYS_Bookings::cancel( $on, array( 'notify' => false ) );
	eq( 0, (int) OYS_Schedule::get( $s->id )->online_booked, 'cancel frees the online seat' );
	eq( 1, (int) OYS_Schedule::get( $s->id )->booked, 'studio untouched' );
	OYS_Schedule::recount( $s->id );
	eq( 1, (int) OYS_Schedule::get( $s->id )->booked, 'recount keeps the studio count' );
	eq( 600, OYS_Schedule::price_for( $s, 'online' ), 'online ticket price' );
	eq( 2500, OYS_Schedule::price_for( $s, 'studio' ), 'studio drop-in price' );
	$open = make_session( array( 'format' => 'hybrid', 'capacity' => 1 ) );
	eq( PHP_INT_MAX, OYS_Schedule::spots_left( $open, 'online' ), 'online seats unlimited when 0' );
} );

test( 'hybrid: online takes online credits and does not use a membership limit', function () {
	OYS_Settings::update( array( 'online_per_credit' => 4 ) );
	$u = make_user();
	$s = make_session( array( 'format' => 'hybrid', 'online_price' => 600 ) );
	OYS_Passes::grant( $u, array( 'credits' => 1 ) );
	eq( 1, OYS_Passes::available_for( $u, $s, 'studio' ), 'studio: one class' );
	eq( 4, OYS_Passes::available_for( $u, $s, 'online' ), 'online: four online classes' );
	ok( ! is_wp_error( OYS_Bookings::book_with_credit( $u, $s, false, 'online' ) ), 'booked online with the pass' );
	eq( 0, OYS_Passes::balance( $u, 'class' ), 'studio class converted' );
	eq( 3, OYS_Passes::balance( $u, 'online' ), 'three online classes left' );
	$m  = make_user();
	make_membership( $m, 1 );
	$h1 = make_session( array( 'format' => 'hybrid', 'in_hours' => 40 ) );
	$h2 = make_session( array( 'format' => 'hybrid', 'in_hours' => 50 ) );
	ok( ! is_wp_error( OYS_Bookings::book_with_membership( $m, $h1, false, 'online' ) ), 'member joins online' );
	eq( 0, OYS_Memberships::used_in_period( OYS_Memberships::current_for( $m ) ), 'online does not count' );
	ok( ! is_wp_error( OYS_Bookings::book_with_membership( $m, $h2, false, 'studio' ) ), 'member still has the studio class' );
} );

test( 'hybrid: card hold and late payment use the online seats', function () {
	$u = make_user();
	$s = make_session( array( 'format' => 'hybrid', 'capacity' => 1, 'online_capacity' => 1, 'online_price' => 600 ) );
	$o = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 600 ) );
	$h = OYS_Bookings::hold( $u, $s, $o, array(), true, 0, 'online' );
	ok( ! is_wp_error( $h ), 'online seat held' );
	eq( 1, (int) OYS_Schedule::get( $s->id )->online_booked, 'held online seat counted' );
	eq( 0, (int) OYS_Schedule::get( $s->id )->booked, 'studio seat still free' );
	OYS_Bookings::release_hold( $h );
	eq( 0, (int) OYS_Schedule::get( $s->id )->online_booked, 'released' );
	OYS_Bookings::confirm_order( $o );
	eq( 1, (int) OYS_Schedule::get( $s->id )->online_booked, 'late payment takes the online seat back' );
	eq( 'confirmed', OYS_Bookings::get( $h )->status, 'confirmed' );
} );

test( 'zoom: meeting created once, only for online people, kept in step with the class', function () {
	OYS_Settings::update( array( 'zoom_account_id' => 'acc', 'zoom_client_id' => 'id', 'zoom_client_secret' => 'secret', 'zoom_auto' => 1, 'zoom_personal' => 0 ) );
	delete_transient( OYS_Zoom::TOKEN );
	$GLOBALS['zoom_calls'] = array();
	$s  = make_session( array( 'format' => 'hybrid', 'online_price' => 600 ) );
	$st = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false ) );
	eq( '', OYS_Bookings::join_link( $st, $s ), 'studio people get no link' );
	eq( 0, zoom_calls( 'POST', '#/meetings$#' ), 'no meeting for studio bookings' );
	$on   = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false, false, 'online' ) );
	$link = OYS_Bookings::join_link( $on, OYS_Schedule::get( $s->id ) );
	ok( str_starts_with( $link, 'https://zoom.test/j/' ), 'online person gets the Zoom link' );
	OYS_Bookings::join_link( $on, OYS_Schedule::get( $s->id ) );
	eq( 1, zoom_calls( 'POST', '#/meetings$#' ), 'meeting created once' );
	$meeting = end( $GLOBALS['zoom_meetings'] );
	eq( gmdate( 'Y-m-d\TH:i:s\Z', oys_ts( $s->starts_at ) ), $meeting['start_time'], 'meeting starts with the class' );
	eq( 60, $meeting['duration'], 'meeting length' );
	eq( 'yoga12', OYS_Schedule::get( $s->id )->zoom_password, 'passcode stored' );
	$new = gmdate( 'Y-m-d H:i:s', oys_ts( $s->starts_at ) + HOUR_IN_SECONDS );
	OYS_Schedule::save( array( 'starts_at' => $new, 'ends_at' => gmdate( 'Y-m-d H:i:s', oys_ts( $new ) + 3600 ) ), $s->id );
	eq( 1, zoom_calls( 'PATCH', '#/meetings/\d+$#' ), 'moving the class updates the meeting' );
	OYS_Schedule::save( array( 'note' => 'Bring a strap' ), $s->id );
	eq( 1, zoom_calls( 'PATCH', '#/meetings/\d+$#' ), 'a note change does not touch Zoom' );
	ok( str_contains( OYS_Zoom::start_url( $s->id ), 'zak=fresh' ), 'host start link fetched fresh' );
	OYS_Schedule::cancel_session( $s->id );
	eq( 1, zoom_calls( 'DELETE', '#/meetings/\d+$#' ), 'cancelling the class deletes the meeting' );
	eq( '', OYS_Schedule::get( $s->id )->zoom_meeting_id, 'meeting fields cleared' );
	$manual = make_session( array( 'format' => 'online', 'online_url' => 'https://meet.example/abc' ) );
	$mb     = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $manual, 'comp', false ) );
	eq( 'https://meet.example/abc', OYS_Bookings::join_link( $mb, $manual ), 'a link typed in by hand wins' );
	eq( 1, zoom_calls( 'POST', '#/meetings$#' ), 'no Zoom meeting for it' );
} );

test( 'zoom: personal links per person, cancelled with the booking', function () {
	OYS_Settings::update( array( 'zoom_account_id' => 'acc', 'zoom_client_id' => 'id', 'zoom_client_secret' => 'secret', 'zoom_auto' => 1, 'zoom_personal' => 1 ) );
	$GLOBALS['zoom_calls'] = array();
	$s   = make_session( array( 'format' => 'online', 'price' => 600 ) );
	$u   = make_user( 'Ana' );
	$hid = OYS_Bookings::book_party( $u, $s, array( 'method' => 'comp', 'guests' => array( array( 'name' => 'Bea', 'email' => 'bea@example.test' ) ), 'notify' => false ) );
	$h   = OYS_Bookings::get( $hid );
	$g   = OYS_Bookings::guests_of( $hid )[0];
	$l1  = OYS_Bookings::join_link( $h, $s );
	$l2  = OYS_Bookings::join_link( $g, $s );
	ok( str_contains( $l1, 'tk=' ) && str_contains( $l2, 'tk=' ) && $l1 !== $l2, 'each person has their own link' );
	eq( 2, zoom_calls( 'POST', '#/registrants$#' ), 'both registered' );
	OYS_Bookings::join_link( OYS_Bookings::get( $hid ), $s );
	eq( 2, zoom_calls( 'POST', '#/registrants$#' ), 'link stored, not registered twice' );
	OYS_Bookings::cancel( $g->id, array( 'notify' => false ) );
	eq( 1, zoom_calls( 'PUT', '#/registrants/status$#' ), 'cancelled guest removed from Zoom' );
	eq( '', OYS_Bookings::get( $g->id )->join_url, 'guest link cleared' );
	OYS_Settings::update( array( 'zoom_personal' => 0 ) );
} );

test( 'zoom: a failed meeting does not block booking and is retried later', function () {
	OYS_Settings::update( array( 'zoom_account_id' => 'acc', 'zoom_client_id' => 'id', 'zoom_client_secret' => 'secret', 'zoom_auto' => 1 ) );
	$GLOBALS['zoom_calls'] = array();
	$GLOBALS['zoom_fail']  = true;
	$s  = make_session( array( 'format' => 'online', 'price' => 600, 'in_hours' => 10 ) );
	$id = OYS_Bookings::book_manual( make_user(), $s, 'comp', false );
	ok( ! is_wp_error( $id ), 'booking works while Zoom is down' );
	eq( '', OYS_Bookings::join_link( OYS_Bookings::get( $id ), $s ), 'no link yet' );
	OYS_Bookings::join_link( OYS_Bookings::get( $id ), $s );
	eq( 1, zoom_calls( 'POST', '#/meetings$#' ), 'not retried on every page view' );
	$GLOBALS['zoom_fail'] = false;
	delete_transient( 'oys_zoom_fail_' . $s->id );
	OYS_Zoom::prepare_upcoming();
	ok( '' !== OYS_Schedule::get( $s->id )->zoom_meeting_id, 'the hourly job creates it' );
} );

test( 'zoom: only one meeting when two requests ask at once', function () {
	global $wpdb;
	OYS_Settings::update( array( 'zoom_account_id' => 'acc', 'zoom_client_id' => 'id', 'zoom_client_secret' => 'secret', 'zoom_auto' => 1 ) );
	$GLOBALS['zoom_calls'] = array();
	$s = make_session( array( 'format' => 'online', 'price' => 600 ) );
	// Another request is creating it right now.
	$wpdb->update( OYS_Install::table( 'sessions' ), array( 'zoom_meeting_id' => 'creating:' . time() ), array( 'id' => $s->id ) );
	ok( false === OYS_Zoom::has_meeting( OYS_Schedule::get( $s->id ) ), 'the marker is not a meeting' );
	// A marker left behind by a crash is taken over after a minute.
	$wpdb->update( OYS_Install::table( 'sessions' ), array( 'zoom_meeting_id' => 'creating:' . ( time() - 120 ) ), array( 'id' => $s->id ) );
	$res = OYS_Zoom::ensure_meeting( $s->id );
	ok( ! is_wp_error( $res ) && OYS_Zoom::has_meeting( $res ), 'stale marker taken over, meeting created' );
	OYS_Zoom::ensure_meeting( $s->id );
	eq( 1, zoom_calls( 'POST', '#/meetings$#' ), 'created once' );
} );

function sent_mails( callable $fn ) {
	$log = array();
	$cb  = function ( $to, $subject, $html ) use ( &$log ) { $log[] = array( 'to' => $to, 'subject' => $subject, 'html' => $html ); };
	add_action( 'oys_email_sent', $cb, 10, 3 );
	$fn();
	remove_action( 'oys_email_sent', $cb, 10 );
	return $log;
}

test( 'emails: edited texts with placeholders, and switched-off emails are not sent', function () {
	$u = make_user( 'Nora' );
	$s = make_session();
	OYS_Email_Templates::save( 'booking_confirmed', array( 'subject' => 'See you at {class}, {first_name}!', 'message' => "Hello {first_name}.\n\nSecond paragraph.", 'closing' => '', 'button' => '' ) );
	$log = sent_mails( fn() => OYS_Bookings::book_manual( $u, $s, 'comp', true ) );
	eq( 'See you at Hatha Flow, Nora!', $log[0]['subject'] ?? '', 'subject with placeholders' );
	ok( str_contains( $log[0]['html'], '<p>Hello Nora.</p><p>Second paragraph.</p>' ), 'message paragraphs' );
	ok( ! str_contains( $log[0]['html'], 'Manage booking' ), 'no button when its label is empty' );
	ok( str_contains( $log[0]['html'], 'Hatha Flow' ) && str_contains( $log[0]['html'], 'When' ), 'automatic class details kept' );
	OYS_Email_Templates::save( 'booking_confirmed', array( 'enabled' => 0 ) );
	$log = sent_mails( fn() => OYS_Bookings::book_manual( make_user(), make_session( array( 'in_hours' => 90 ) ), 'comp', true ) );
	eq( 0, count( $log ), 'switched off: not sent' );
	OYS_Email_Templates::reset( 'booking_confirmed' );
	eq( 'Booked: {class}, {date_short}', OYS_Email_Templates::get( 'booking_confirmed' )['subject'], 'reset brings back the original text' );
	ok( ! OYS_Email_Templates::get( 'booking_confirmed' )['enabled'], 'reset keeps it switched off' );
	OYS_Email_Templates::save( 'studio_cancellation', array( 'enabled' => 0 ) );
	ok( false === OYS_Emails::admin_notice( 'x', 'y', 'studio_cancellation' ), 'studio notice switched off' );
	[ $subject, $html ] = OYS_Emails::preview( 'reminder' );
	ok( str_starts_with( $subject, 'Reminder: Slow Flow' ) && str_contains( $html, 'See you soon' ), 'preview with sample details' );
	delete_option( OYS_Email_Templates::OPTION );
} );

test( 'reminders: two class reminders, online join link, pass about to expire', function () {
	global $wpdb;
	delete_option( OYS_Email_Templates::OPTION );
	OYS_Settings::update( array( 'reminder_hours' => 24, 'reminder2_hours' => 2, 'join_reminder_minutes' => 30, 'pass_expiry_days' => 7 ) );
	$b = OYS_Install::table( 'bookings' );
	$u = make_user( 'Rita' );
	$s = make_session( array( 'in_hours' => 20 ) );
	$id = OYS_Bookings::book_manual( $u, $s, 'comp', false );
	$wpdb->update( $b, array( 'created_at' => oys_utc_plus( -3 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
	// Only count Rita's emails: the dev database may hold other classes with reminders due.
	$rita = fn( $log ) => array_filter( $log, fn( $m ) => get_userdata( $u )->user_email === $m['to'] );
	$log  = sent_mails( fn() => OYS_Cron::send_reminders() );
	eq( 1, count( array_filter( $rita( $log ), fn( $m ) => str_starts_with( $m['subject'], 'Reminder: Hatha Flow' ) ) ), 'first reminder sent' );
	eq( 0, count( $rita( sent_mails( fn() => OYS_Cron::send_reminders() ) ) ), 'not sent twice' );
	$wpdb->update( OYS_Install::table( 'sessions' ), array( 'starts_at' => oys_utc_plus( HOUR_IN_SECONDS ), 'ends_at' => oys_utc_plus( 2 * HOUR_IN_SECONDS ) ), array( 'id' => $s->id ) );
	eq( 1, count( $rita( sent_mails( fn() => OYS_Cron::send_reminders() ) ) ), 'second reminder on the day' );
	// Online: join link shortly before, to the customer and a guest with an email.
	OYS_Settings::update( array( 'zoom_auto' => 0 ) );
	OYS_Settings::update( array( 'join_reminder_minutes' => 180 ) );
	$on  = make_session( array( 'format' => 'online', 'price' => 600, 'online_url' => 'https://meet.example/live', 'in_hours' => 2 ) );
	OYS_Bookings::book_party( make_user(), $on, array( 'method' => 'comp', 'guests' => array( array( 'name' => 'Gia', 'email' => 'gia@example.test' ), array( 'name' => 'NoMail', 'email' => '' ) ), 'notify' => false ) );
	$log = sent_mails( fn() => OYS_Cron::send_join_reminders() );
	eq( 2, count( $log ), 'join link to the customer and the guest with an email' );
	ok( str_contains( $log[0]['html'], 'https://meet.example/live' ), 'email has the link' );
	eq( 0, count( sent_mails( fn() => OYS_Cron::send_join_reminders() ) ), 'join link sent once' );
	// Pass expiring.
	$p = OYS_Passes::grant( $u, array( 'credits' => 3, 'validity_days' => 5, 'name' => '5-class pass' ) );
	$wpdb->update( OYS_Install::table( 'passes' ), array( 'created_at' => oys_utc_plus( -60 * DAY_IN_SECONDS ) ), array( 'id' => $p ) );
	$log = sent_mails( fn() => OYS_Cron::send_pass_expiry() );
	eq( 1, count( array_filter( $log, fn( $m ) => str_contains( $m['subject'], '5-class pass expires' ) && str_contains( $m['html'], '3 classes' ) ) ), 'pass expiry reminder' );
	eq( 0, count( sent_mails( fn() => OYS_Cron::send_pass_expiry() ) ), 'once per pass' );
	OYS_Settings::update( array( 'reminder_hours' => 0 ) );
	$s2 = make_session( array( 'in_hours' => 10 ) );
	$id2 = OYS_Bookings::book_manual( make_user(), $s2, 'comp', false );
	$wpdb->update( $b, array( 'created_at' => oys_utc_plus( -3 * DAY_IN_SECONDS ) ), array( 'id' => $id2 ) );
	eq( 0, count( array_filter( sent_mails( fn() => OYS_Cron::send_reminders() ), fn( $m ) => str_contains( $m['subject'], (string) $s2->id ) ) ), 'reminder switched off' );
} );

function api( $method, $path, $body = null, $token = '' ) {
	$req = new WP_REST_Request( $method, '/oys/v1' . $path );
	if ( $token ) {
		$req->set_header( 'Authorization', 'Bearer ' . $token );
	}
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	if ( str_contains( $path, '?' ) ) {
		parse_str( substr( $path, strpos( $path, '?' ) + 1 ), $q );
		$req->set_query_params( $q );
		$req->set_route( '/oys/v1' . substr( $path, 0, strpos( $path, '?' ) ) );
	}
	wp_set_current_user( 0 );
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
}

test( 'app api: login, token, me, logout', function () {
	$u    = make_user( 'Ivy' );
	$user = get_userdata( $u );
	[ $code ] = api( 'POST', '/app/login', array( 'email' => $user->user_email, 'password' => 'wrong' ) );
	eq( 401, $code, 'wrong password refused' );
	[ $code, $data ] = api( 'POST', '/app/login', array( 'email' => $user->user_email, 'password' => 'x-12345678', 'device' => 'iPhone' ) );
	eq( 200, $code, 'logged in' );
	ok( preg_match( '/^' . $u . '\.[A-Za-z0-9]{40}$/', $data['token'] ), 'token shape' );
	eq( 'Ivy', $data['me']['user']['first_name'], 'profile in the login answer' );
	$tok = $data['token'];
	[ $code, $me ] = api( 'GET', '/app/me', null, $tok );
	eq( 200, $code, 'me with the token' );
	ok( ! str_contains( wp_json_encode( get_user_meta( $u, OYS_App_API::META, true ) ), explode( '.', $tok )[1] ), 'only a hash of the token is stored' );
	[ $code ] = api( 'GET', '/app/me', null, $u . '.' . str_repeat( 'a', 40 ) );
	eq( 401, $code, 'forged token refused' );
	[ $code ] = api( 'GET', '/app/me' );
	eq( 401, $code, 'no token refused' );
	api( 'POST', '/app/logout', array(), $tok );
	[ $code ] = api( 'GET', '/app/me', null, $tok );
	eq( 401, $code, 'logged out token no longer works' );
} );

test( 'app api: schedule, book with a pass, card checkout, cancel, waiver', function () {
	$u   = make_user( 'Joy' );
	$tok = OYS_App_API::issue_token( $u, 'test' );
	$s   = make_session( array( 'in_hours' => 30 ) );
	OYS_Passes::grant( $u, array( 'credits' => 3 ) );
	[ , $sched ] = api( 'GET', '/app/schedule?days=5', null, $tok );
	ok( in_array( (int) $s->id, array_column( $sched['sessions'], 'id' ), true ), 'class in the schedule' );
	[ , $detail ] = api( 'GET', '/app/sessions/' . $s->id, null, $tok );
	eq( array( 'credit', 'card', 'door' ), array_column( $detail['options'], 'method' ), 'pass, card and pay at the studio offered' );
	[ $code, $res ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'credit', 'guests' => array( array( 'name' => 'Kim', 'email' => '' ) ) ), $tok );
	eq( 200, $code, 'booked' );
	eq( 'booked', $res['status'], 'status booked' );
	eq( array( 'Kim' ), $res['session']['my_booking']['guests'], 'guest on the booking' );
	eq( 1, OYS_Passes::balance( $u, 'class' ), 'two classes used' );
	[ $code ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'credit' ), $tok );
	eq( 409, $code, 'cannot book twice' );
	[ , $list ] = api( 'GET', '/app/bookings', null, $tok );
	eq( (int) $s->id, (int) $list['bookings'][0]['id'], 'in my bookings' );
	$bid = $list['bookings'][0]['booking']['id'];
	[ $code ] = api( 'POST', '/app/bookings/' . $bid . '/cancel', array(), OYS_App_API::issue_token( make_user(), 'x' ) );
	eq( 404, $code, "someone else's booking can't be cancelled" );
	[ $code, $c ] = api( 'POST', '/app/bookings/' . $bid . '/cancel', array(), $tok );
	eq( 'returned', $c['outcome'], 'cancelled, classes back' );
	eq( 3, OYS_Passes::balance( $u, 'class' ), 'pass restored' );
	// Card: a Stripe Checkout URL, the seat is held, the return page knows it's the app.
	$GLOBALS['stripe_calls'] = array();
	$s2 = make_session( array( 'in_hours' => 40 ) );
	[ $code, $co ] = api( 'POST', '/app/sessions/' . $s2->id . '/book', array( 'method' => 'card' ), $tok );
	eq( 'checkout', $co['status'] ?? '', 'card returns a checkout' );
	ok( str_starts_with( $co['url'], 'https://' ), 'checkout url' );
	eq( 1, seats( $s2 ), 'seat held while paying' );
	$call = array_values( array_filter( $GLOBALS['stripe_calls'], fn( $c ) => '/v1/checkout/sessions' === $c[1] ) )[0];
	ok( str_contains( $call[2]['success_url'], 'app=1' ), 'return page knows it came from the app' );
	// Waiver: a new version must be accepted in the app.
	OYS_Settings::update( array( 'waiver_version' => 'v-test' ) );
	[ $code, $w ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'credit' ), $tok );
	eq( 409, $code, 'waiver needed' );
	eq( 'oys_waiver', $w['code'], 'waiver error code' );
	[ $code ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'credit', 'accept_waiver' => true ), $tok );
	eq( 200, $code, 'booked after accepting' );
	OYS_Settings::update( array( 'waiver_version' => '2026-09' ) );
} );

test( 'app api: hybrid online booking and the join link', function () {
	OYS_Settings::update( array( 'zoom_auto' => 0 ) );
	$u   = make_user( 'Liv' );
	$tok = OYS_App_API::issue_token( $u, 'test' );
	$far = make_session( array( 'format' => 'hybrid', 'online_price' => 600, 'online_url' => 'https://meet.example/h', 'in_hours' => 30 ) );
	[ , $d ] = api( 'GET', '/app/sessions/' . $far->id . '?mode=online', null, $tok );
	eq( 'online', $d['mode'], 'online mode' );
	eq( 600, $d['price_cents'], 'online ticket price' );
	[ $code ] = api( 'POST', '/app/sessions/' . $far->id . '/book', array( 'method' => 'free', 'mode' => 'online' ), $tok );
	eq( 400, $code, 'paid class cannot be booked as free' );
	OYS_Passes::grant( $u, array( 'credits' => 1 ) );
	[ , $r ] = api( 'POST', '/app/sessions/' . $far->id . '/book', array( 'method' => 'credit', 'mode' => 'online' ), $tok );
	eq( 'online', $r['session']['my_booking']['mode'], 'booked online' );
	eq( '', $r['session']['my_booking']['join_url'], 'link not shown a day ahead' );
	$soon = make_session( array( 'format' => 'online', 'price' => 0, 'online_url' => 'https://meet.example/soon', 'in_hours' => 0.9 ) );
	global $wpdb;
	$wpdb->update( OYS_Install::table( 'sessions' ), array( 'starts_at' => oys_utc_plus( 50 * MINUTE_IN_SECONDS ), 'ends_at' => oys_utc_plus( 110 * MINUTE_IN_SECONDS ) ), array( 'id' => $soon->id ) );
	OYS_Bookings::book_manual( $u, $soon->id, 'comp', false, true );
	[ , $list ] = api( 'GET', '/app/bookings', null, $tok );
	$item = array_values( array_filter( $list['bookings'], fn( $b ) => (int) $b['id'] === (int) $soon->id ) )[0];
	eq( 'https://meet.example/soon', $item['booking']['join_url'], 'join link within the hour' );
} );

test( 'app api: login lock applies to the app too', function () {
	$u = get_userdata( make_user() );
	for ( $i = 0; $i < OYS_Security::MAX_LOGIN_FAILS; $i++ ) {
		api( 'POST', '/app/login', array( 'email' => $u->user_email, 'password' => 'nope' ) );
	}
	[ $code, $d ] = api( 'POST', '/app/login', array( 'email' => $u->user_email, 'password' => 'x-12345678' ) );
	eq( 429, $code, 'locked after repeated failures' );
	eq( 'oys_locked', $d['code'], 'locked code' );
} );

/* ---------- Round 1: pay at the studio, donations, messages, private first session ---------- */

test( 'pay at the studio: book, guests, due amount, marked paid', function () {
	OYS_Settings::update( array( 'pay_later' => 'all', 'pay_later_max_no_shows' => 2 ) );
	$u  = make_user( 'Dora' );
	$s  = make_session( array( 'price' => 2500 ) );
	$id = OYS_Bookings::book_party( $u, $s, array( 'method' => 'door', 'guests' => guests( 'Gus' ), 'notify' => false ) );
	ok( is_int( $id ), 'booked to pay at the studio' );
	$b = OYS_Bookings::get( $id );
	eq( 'door', $b->paid_with, 'paid with: at the studio' );
	eq( 2500, (int) $b->due_cents, 'owes the drop-in price' );
	eq( 2, seats( $s ), 'two seats taken (with the guest)' );
	eq( 5000, OYS_Bookings::due_at_studio( $s->id ), 'to collect: two people' );
	ok( OYS_Bookings::collect( $id, 'cash' ), 'marked paid' );
	$b = OYS_Bookings::get( $id );
	eq( 'cash', $b->collected_with, 'collected in cash' );
	eq( 'attended', $b->status, 'paying at the studio means they came' );
	eq( 2500, OYS_Bookings::due_at_studio( $s->id ), 'the guest still to pay' );
	// Cancelling on time gives nothing back: nothing was paid.
	$s2  = make_session( array( 'price' => 2500 ) );
	$id2 = OYS_Bookings::book_party( $u, $s2, array( 'method' => 'door', 'notify' => false ) );
	eq( 'none', OYS_Bookings::cancel( $id2, array( 'notify' => false ) ), 'cancel: nothing to return' );
	eq( 0, OYS_Passes::balance( $u, 'class' ), 'no credit created' );
	eq( 0, seats( $s2 ), 'seat released' );
} );

test( 'pay at the studio: rules (off, online, private, first class, missed classes)', function () {
	$u  = make_user( 'Mia' );
	$s  = make_session( array( 'price' => 2500 ) );
	OYS_Settings::update( array( 'pay_later' => 'off' ) );
	ok( is_wp_error( OYS_Bookings::pay_later_allowed( $u, $s ) ), 'off in settings' );
	OYS_Settings::update( array( 'pay_later' => 'all', 'pay_later_max_no_shows' => 2 ) );
	ok( true === OYS_Bookings::pay_later_allowed( $u, $s ), 'on for everyone' );
	ok( is_wp_error( OYS_Bookings::pay_later_allowed( $u, make_session( array( 'pay_later' => 0 ) ) ) ), 'off for this class' );
	ok( is_wp_error( OYS_Bookings::pay_later_allowed( $u, make_session( array( 'format' => 'online', 'price' => 600 ) ) ) ), 'not for online classes' );
	$hy = make_session( array( 'format' => 'hybrid', 'online_price' => 600 ) );
	ok( true === OYS_Bookings::pay_later_allowed( $u, $hy, 'studio' ) && is_wp_error( OYS_Bookings::pay_later_allowed( $u, $hy, 'online' ) ), 'hybrid: in the studio only' );
	ok( is_wp_error( OYS_Bookings::pay_later_allowed( $u, make_session( array( 'kind' => 'private', 'capacity' => 1 ) ) ) ), 'not for private sessions' );
	ok( is_wp_error( OYS_Bookings::pay_later_allowed( $u, make_session( array( 'price' => 0 ) ) ) ), 'free class: nothing to pay' );
	ok( is_wp_error( OYS_Bookings::book_party( $u, make_session( array( 'pay_later' => 0 ) ), array( 'method' => 'door', 'notify' => false ) ) ), 'booking refused when not allowed' );
	// Two missed unpaid classes: pay in advance from then on.
	foreach ( array( 1, 2 ) as $i ) {
		$id = OYS_Bookings::book_party( $u, make_session( array( 'price' => 2500 ) ), array( 'method' => 'door', 'notify' => false ) );
		OYS_Bookings::set_attendance( $id, 'no_show' );
	}
	eq( 2, OYS_Bookings::pay_later_no_shows( $u ), 'two missed' );
	$e = OYS_Bookings::pay_later_allowed( $u, $s );
	ok( is_wp_error( $e ) && 'oys_pay_later_blocked' === $e->get_error_code(), 'blocked after two missed classes' );
	update_user_meta( $u, 'oys_pay_later_reset', gmdate( 'Y-m-d H:i:s', time() + 1 ) );
	ok( true === OYS_Bookings::pay_later_allowed( $u, $s ), 'allowed again after the studio resets it' );
	// First class only.
	OYS_Settings::update( array( 'pay_later' => 'first' ) );
	$new = make_user( 'Nora' );
	ok( true === OYS_Bookings::pay_later_allowed( $new, $s ), 'first class: allowed' );
	OYS_Bookings::book_manual( $new, make_session(), 'comp', false );
	ok( is_wp_error( OYS_Bookings::pay_later_allowed( $new, $s ) ), 'after the first class: pay in advance' );
	OYS_Settings::update( array( 'pay_later' => 'all' ) );
} );

test( 'donations: amount per person, minimum, pay at the studio, app booking', function () {
	OYS_Settings::update( array( 'pay_later' => 'all', 'donation_min_cents' => 500, 'donation_suggestions' => '5,10,15,20' ) );
	$u   = make_user( 'Dana' );
	$tok = OYS_App_API::issue_token( $u );
	$s   = make_session( array( 'pricing' => 'donation', 'price' => 1000, 'credits_allowed' => 0 ) );
	eq( 'By donation', oys_price_label( $s ), 'timetable label' );
	eq( array( 500, 1000, 1500, 2000 ), oys_donation_amounts(), 'suggested amounts' );
	[ , $d ] = api( 'GET', '/app/sessions/' . $s->id, null, $tok );
	eq( 500, $d['donation']['min_cents'], 'app: minimum' );
	eq( 1000, $d['donation']['suggested_cents'], 'app: suggestion' );
	eq( array( 'card', 'door' ), array_column( $d['options'], 'method' ), 'app: give by card or at the studio' );
	[ $code, $e ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'card', 'mode' => 'studio', 'amount_cents' => 300, 'accept_waiver' => true ), $tok );
	eq( 400, $code, 'below the minimum refused' );
	[ $code, $r ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'card', 'mode' => 'studio', 'amount_cents' => 1500, 'guests' => array( array( 'name' => 'Pal' ) ), 'accept_waiver' => true ), $tok );
	eq( 'checkout', $r['status'], 'card: goes to Stripe' );
	eq( 3000, (int) OYS_Orders::get( $r['order_id'] )->amount_cents, 'order: chosen amount × 2 people' );
	OYS_Orders::mark_unpaid( $r['order_id'], 'expired' );
	[ , $r ] = api( 'POST', '/app/sessions/' . $s->id . '/book', array( 'method' => 'door', 'mode' => 'studio', 'amount_cents' => 1200 ), $tok );
	eq( 'booked', $r['status'], 'give at the studio: booked' );
	eq( 1200, $r['session']['my_booking']['due_cents'], 'app shows what to give at the studio' );
	eq( 1200, OYS_Bookings::due_at_studio( $s->id ), 'roster: to collect' );
} );

test( 'messages: everyone booked, guests with an email, waitlist, log', function () {
	$s = make_session( array( 'capacity' => 2 ) );
	$a = make_user( 'Ann' );
	OYS_Bookings::book_party( $a, $s, array( 'method' => 'comp', 'guests' => array( array( 'name' => 'Gia', 'email' => 'gia@example.test' ) ), 'notify' => false ) );
	$w = make_user( 'Wes' );
	OYS_Bookings::join_waitlist( $w, $s->id );
	ok( is_wp_error( OYS_Messages::send_to_session( $s->id, 'Hi', '' ) ), 'empty message refused' );
	$log = sent_mails( function () use ( $s, &$n ) { $n = OYS_Messages::send_to_session( $s->id, 'About {class}', "Hi {first_name},\n\nbring a blanket." ); } );
	eq( 2, $n, 'the customer and the guest with an email' );
	eq( 'About Hatha Flow', $log[0]['subject'], 'placeholders in the subject' );
	ok( str_contains( $log[0]['html'], 'Hi Ann' ) && str_contains( $log[1]['html'], 'Hi Gia' ), 'each person by name' );
	eq( 3, OYS_Messages::send_to_session( $s->id, 'Change', 'Room 2 today.', array( 'waitlist' => true ) ), 'with the waitlist' );
	eq( 2, OYS_Messages::send_to_session( $s->id, 'Just bookers', 'x', array( 'guests' => false, 'waitlist' => true ) ) - 0, 'without guests' );
	eq( 3, count( OYS_Messages::for_session( $s->id ) ), 'messages are kept for the roster' );
	eq( 0, count( OYS_Messages::recipients( make_session()->id ) ), 'nobody booked, nobody to write to' );
} );

test( 'private: first session gets extra time, note in the offer', function () {
	global $wpdb;
	$u = make_user( 'Pia' );
	$wpdb->insert( OYS_Install::table( 'private_requests' ), array( 'user_id' => $u, 'duration_min' => 60, 'location_type' => 'home', 'preferred' => 'mornings', 'status' => 'new', 'created_at' => oys_now() ) );
	$r = (int) $wpdb->insert_id;
	ok( OYS_Privates::is_first( $u, $r ), 'first private session' );
	$log = sent_mails( function () use ( $r, &$sid ) { $sid = OYS_Privates::offer( $r, wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) . 'T10:00', 60, 9500, 'Home', '', '', 15 ); } );
	$s = OYS_Schedule::get( $sid );
	eq( 75, ( oys_ts( $s->ends_at ) - oys_ts( $s->starts_at ) ) / 60, '60 minutes + 15 blocked' );
	eq( 9500, (int) $s->price_cents, 'price unchanged' );
	ok( str_contains( $s->note, '15 extra minutes' ), 'note on the session' );
	ok( str_contains( $log[0]['html'], 'extra minutes to talk through your goals' ), 'the offer email explains it' );
	OYS_Privates::mark_paid( $r, 0 );
	ok( ! OYS_Privates::is_first( $u ), 'not first any more' );
} );

test( 'calendar and weekly classes keep donation and pay-at-the-studio settings', function () {
	wp_set_current_user( 1 );
	$date = wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS );
	$req  = new WP_REST_Request( 'POST', '/oys/v1/admin/sessions' );
	$req->set_body_params( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'date' => $date, 'start' => '07:15', 'duration' => 60, 'capacity' => 10, 'price' => 1000, 'pricing' => 'donation', 'pay_later' => 0, 'repeat' => 'weekly' ) );
	$res  = rest_do_request( $req )->get_data();
	eq( 'donation', $res['session']['pricing'], 'donation saved' );
	eq( false, $res['session']['pay_later'], 'pay at the studio off' );
	$tpl = OYS_Schedule::template( OYS_Schedule::get( $res['session']['id'] )->template_id );
	eq( 'donation', $tpl->pricing, 'weekly class is by donation' );
	global $wpdb;
	eq( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'sessions' ) . " WHERE template_id = %d AND ( pricing <> 'donation' OR pay_later <> 0 )", $tpl->id ) ), 'every generated date too' );
	wp_set_current_user( 0 );
} );

test( 'app api: newsletter and the Facebook group link', function () {
	OYS_Settings::update( array( 'fb_group_url' => 'https://www.facebook.com/groups/485915674202871' ) );
	$u   = make_user( 'Nel' );
	$tok = OYS_App_API::issue_token( $u );
	[ , $me ] = api( 'POST', '/app/newsletter', array( 'subscribe' => true ), $tok );
	ok( $me['user']['newsletter'], 'subscribed' );
	eq( 'https://www.facebook.com/groups/485915674202871', $me['links']['community'], 'group link for the app' );
	[ , $me ] = api( 'POST', '/app/newsletter', array( 'subscribe' => false ), $tok );
	ok( ! $me['user']['newsletter'], 'unsubscribed' );
	ok( str_contains( OYS_Emails::preview( 'welcome' )[1], 'Facebook group' ), 'emails link the group' );
} );

/* ---------- Round 2: locations and the minimum number of people ---------- */

test( 'locations: rules by place, online, class override, events and privates', function () {
	OYS_Settings::update( array( 'min_people_default' => 2, 'decide_hours_default' => 3 ) );
	OYS_Locations::save_all( array(
		array( 'name' => 'Riverside Studio', 'address' => '1 River Rd', 'min_people' => 4, 'decide_hours' => 12 ),
		array( 'id' => 'online', 'name' => 'Online', 'min_people' => 3, 'decide_hours' => 2 ),
		array( 'name' => '' ),
	) );
	eq( array( 'riverside-studio', 'online' ), array_keys( OYS_Locations::all() ), 'places saved, empty rows dropped' );
	eq( array( 4, 12 ), OYS_Locations::rule( make_session( array( 'location' => 'riverside studio' ) ) ), 'place rule (name matched without case)' );
	eq( array( 3, 2 ), OYS_Locations::rule( make_session( array( 'format' => 'online', 'price' => 600 ) ) ), 'online rule' );
	eq( array( 2, 3 ), OYS_Locations::rule( make_session( array( 'location' => 'Beach' ) ) ), 'unknown place: defaults' );
	eq( array( 6, 1 ), OYS_Locations::rule( make_session( array( 'location' => 'Riverside Studio', 'min_people' => 6, 'decide_hours' => 1 ) ) ), 'the class sets its own' );
	eq( 0, OYS_Locations::rule( make_session( array( 'location' => 'Riverside Studio', 'min_people' => 0 ) ) )[0], 'minimum 0: always goes ahead' );
	eq( 0, OYS_Locations::rule( make_session( array( 'kind' => 'event' ) ) )[0], 'events only with their own minimum' );
	eq( 5, OYS_Locations::rule( make_session( array( 'kind' => 'event', 'min_people' => 5 ) ) )[0], 'event with a minimum' );
	eq( 0, OYS_Locations::rule( make_session( array( 'kind' => 'private', 'capacity' => 1, 'min_people' => 2 ) ) )[0], 'never for private sessions' );
	delete_option( OYS_Locations::OPTION );
} );

test( 'minimum: too few people → cancelled with other dates; enough → the class is on', function () {
	global $wpdb;
	OYS_Settings::update( array( 'min_people_default' => 2, 'decide_hours_default' => 3, 'min_nudge_hours' => 0 ) );
	$u     = make_user( 'Lou' );
	$pid   = OYS_Passes::grant( $u, array( 'credits' => 2 ) );
	$short = make_session( array( 'in_hours' => 2, 'location' => 'Beach' ) );
	$alt   = make_session( array( 'in_hours' => 50, 'location' => 'Beach' ) );
	OYS_Bookings::book_with_credit( $u, $short, false );
	$fine  = make_session( array( 'in_hours' => 2.5, 'location' => 'Beach' ) );
	OYS_Bookings::book_party( make_user(), $fine, array( 'method' => 'comp', 'guests' => guests( 'Kim' ), 'notify' => false ) );
	$later = make_session( array( 'in_hours' => 30, 'location' => 'Beach' ) );
	OYS_Bookings::book_manual( make_user(), $later, 'comp', false );
	$log = sent_mails( fn() => OYS_Locations::run() );
	eq( 'cancelled', OYS_Schedule::get( $short->id )->status, 'one person of two: cancelled' );
	eq( 2, (int) OYS_Schedule::get( $short->id )->min_state, 'marked as cancelled for too few people' );
	eq( 2, OYS_Passes::balance( $u, 'class' ), 'the class is back on the pass' );
	$mine = array_values( array_filter( $log, fn( $m ) => get_userdata( $u )->user_email === $m['to'] ) );
	eq( 1, count( $mine ), 'the customer gets one email' );
	ok( str_contains( $mine[0]['html'], 'not enough people signed up' ), 'it says why' );
	ok( str_contains( $mine[0]['html'], oys_book_url( $fine->id ) ), 'with the next date of the class at the same place' );
	ok( str_contains( $mine[0]['html'], 'Join another class instead' ), 'under "join another class"' );
	ok( str_contains( $mine[0]['html'], 'bring a friend' ), 'and a bring-a-friend tip' );
	ok( (bool) array_filter( $log, fn( $m ) => str_starts_with( $m['subject'], '[Studio] Cancelled automatically:' ) ), 'the studio is told' );
	eq( 'scheduled', OYS_Schedule::get( $fine->id )->status, 'two people (with a guest): goes ahead' );
	eq( 1, (int) OYS_Schedule::get( $fine->id )->min_state, 'marked as confirmed' );
	ok( (bool) array_filter( $log, fn( $m ) => str_starts_with( $m['subject'], '[Studio] Class is on:' ) ), 'the studio hears it is on' );
	eq( 0, (int) OYS_Schedule::get( $later->id )->min_state, 'not decided before its time' );
	eq( 0, count( array_filter( sent_mails( fn() => OYS_Locations::run() ), fn( $m ) => str_contains( $m['subject'], 'Hatha' ) && in_array( $m['to'], array( get_userdata( $u )->user_email ), true ) ) ), 'decided once' );
	// Moving a confirmed class decides it again.
	OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( 26 * HOUR_IN_SECONDS ), 'ends_at' => oys_utc_plus( 27 * HOUR_IN_SECONDS ) ), $fine->id );
	eq( 0, (int) OYS_Schedule::get( $fine->id )->min_state, 'moved: decided again later' );
	// Empty class: cancelled quietly.
	$empty = make_session( array( 'in_hours' => 1.5, 'location' => 'Beach' ) );
	OYS_Locations::run();
	eq( 'cancelled', OYS_Schedule::get( $empty->id )->status, 'nobody booked: cancelled' );
} );

test( 'minimum: bring-a-friend email before the decision, once', function () {
	OYS_Settings::update( array( 'min_people_default' => 3, 'decide_hours_default' => 3, 'min_nudge_hours' => 12 ) );
	$u = make_user( 'Fay' );
	$s = make_session( array( 'in_hours' => 10, 'location' => 'Beach' ) );
	OYS_Bookings::book_manual( $u, $s, 'comp', false );
	$log  = sent_mails( fn() => OYS_Locations::run() );
	$mine = array_values( array_filter( $log, fn( $m ) => get_userdata( $u )->user_email === $m['to'] ) );
	eq( 1, count( $mine ), 'bring-a-friend email' );
	eq( 'Hatha Flow needs 2 more people', $mine[0]['subject'], 'says how many are missing' );
	ok( str_contains( $mine[0]['html'], oys_book_url( $s->id ) ), 'with the link to share' );
	eq( 'scheduled', OYS_Schedule::get( $s->id )->status, 'not decided yet' );
	eq( 0, count( array_filter( sent_mails( fn() => OYS_Locations::run() ), fn( $m ) => get_userdata( $u )->user_email === $m['to'] ) ), 'sent once' );
	ok( str_contains( OYS_Locations::notice( OYS_Schedule::get( $s->id ) ), 'goes ahead with 3 or more people' ), 'booking page explains the minimum' );
	OYS_Settings::update( array( 'min_people_default' => 2, 'min_nudge_hours' => 12 ) );
} );

test( 'minimum: set in the calendar, kept by weekly classes', function () {
	wp_set_current_user( 1 );
	$req = new WP_REST_Request( 'POST', '/oys/v1/admin/sessions' );
	$req->set_body_params( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'date' => wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ), 'start' => '06:30', 'duration' => 60, 'capacity' => 10, 'price' => 2500, 'min_people' => 4, 'decide_hours' => 12, 'repeat' => 'weekly' ) );
	$res = rest_do_request( $req )->get_data();
	eq( 4, $res['session']['min_people'], 'minimum saved' );
	eq( 12, $res['session']['minimum']['hours'], 'decision 12 hours before' );
	$tpl = OYS_Schedule::template( OYS_Schedule::get( $res['session']['id'] )->template_id );
	eq( array( 4, 12 ), array( (int) $tpl->min_people, (int) $tpl->decide_hours ), 'weekly class keeps it' );
	$req = new WP_REST_Request( 'POST', '/oys/v1/admin/sessions/' . $res['session']['id'] );
	$req->set_body_params( array_merge( $res['session'], array( 'min_people' => '', 'decide_hours' => '' ) ) );
	$res = rest_do_request( $req )->get_data();
	eq( null, $res['session']['min_people'], 'cleared: back to the location default' );
	wp_set_current_user( 0 );
} );

/* ---------- Round 3: coupons, birthday, loyalty draw, newsletter, AI drafts ---------- */

test( 'coupons: validate, discount lines, count a use when paid, 100% needs no Stripe', function () {
	$u  = make_user( 'Cora' );
	$u2 = make_user( 'Other' );
	$id = OYS_Coupons::create( array( 'code' => 'spring-20', 'kind' => 'percent', 'value' => 20, 'max_uses' => 1 ) );
	eq( 'SPRING-20', OYS_Coupons::get( $id )->code, 'codes are upper case' );
	ok( is_wp_error( OYS_Coupons::create( array( 'code' => 'SPRING-20' ) ) ), 'codes are unique' );
	ok( is_wp_error( OYS_Coupons::validate( 'nope', $u, 'passes' ) ), 'unknown code refused' );
	[ $order, $lines ] = OYS_Coupons::for_checkout( ' spring-20 ', $u, 'passes', array( 'amount_cents' => 11000, 'description' => '5-class pass', 'type' => 'pack' ), array( array( '5-class pass', '', 11000, 1 ) ) );
	eq( 8800, $order['amount_cents'], '20% off $110' );
	eq( 8800, $lines[0][2], 'receipt line discounted' );
	ok( str_contains( $lines[0][1], 'SPRING-20' ), 'receipt shows the code' );
	eq( 2200, $order['meta']['discount_cents'], 'discount stored on the order' );
	// Fixed amount across a party (host + 2 guests at $25): $10 comes off.
	$amt = OYS_Coupons::get( OYS_Coupons::create( array( 'kind' => 'amount', 'value' => 1000, 'applies' => 'classes' ) ) );
	[ $l2, $off ] = OYS_Coupons::apply_lines( $amt, array( array( 'Class', '', 2500, 1 ), array( 'Guests', '', 2500, 2 ) ) );
	eq( 1000, $off, '$10 off in total' );
	ok( is_wp_error( OYS_Coupons::validate( $amt->code, $u, 'passes' ) ), 'a classes code is refused for passes' );
	// Personal code.
	$mine = OYS_Coupons::create( array( 'kind' => 'percent', 'value' => 15, 'user_id' => $u ) );
	ok( is_wp_error( OYS_Coupons::validate( OYS_Coupons::get( $mine )->code, $u2, 'classes' ) ), 'someone else\'s code refused' );
	// Used once when the order is paid.
	$oid = OYS_Orders::create( array_merge( $order, array( 'user_id' => $u, 'product_id' => 'pack-5' ) ) );
	OYS_Orders::mark_paid( $oid );
	eq( 1, (int) OYS_Coupons::get( $id )->used, 'use counted' );
	ok( is_wp_error( OYS_Coupons::validate( 'SPRING-20', $u, 'passes' ) ), 'used up' );
	// 100% off: fulfilled without Stripe.
	$free = OYS_Coupons::get( OYS_Coupons::create( array( 'kind' => 'percent', 'value' => 100, 'applies' => 'passes' ) ) );
	[ $o3, $l3 ] = OYS_Coupons::for_checkout( $free->code, $u, 'passes', array( 'user_id' => $u, 'type' => 'pack', 'product_id' => 'pack-5', 'description' => '5-class pass', 'amount_cents' => 11000 ), array( array( '5-class pass', '', 11000, 1 ) ) );
	$calls = count( $GLOBALS['stripe_calls'] );
	$url   = OYS_Stripe::start_checkout( OYS_Orders::create( $o3 ), '5-class pass', '', $l3 );
	ok( str_contains( $url, 'oys_return=success' ), 'straight to the thank-you page' );
	eq( $calls, count( $GLOBALS['stripe_calls'] ), 'Stripe not called' );
	eq( 5 + 5, OYS_Passes::balance( $u, 'class' ), 'both passes in the account' );
} );

test( 'birthday: code on the day, once a year, filled in at checkout', function () {
	OYS_Settings::update( array( 'birthday_on' => 1, 'birthday_percent' => 25, 'birthday_days' => 30 ) );
	$u = make_user( 'Bibi' );
	OYS_Rewards::set_birthday( $u, (int) wp_date( 'n' ), (int) wp_date( 'j' ) );
	$o = make_user( 'NotToday' );
	OYS_Rewards::set_birthday( $o, (int) wp_date( 'n', time() + 5 * DAY_IN_SECONDS ), (int) wp_date( 'j', time() + 5 * DAY_IN_SECONDS ) );
	OYS_Rewards::set_birthday( make_user(), 2, 31 );
	$log  = sent_mails( fn() => OYS_Rewards::send_birthdays() );
	$mine = array_values( array_filter( $log, fn( $m ) => get_userdata( $u )->user_email === $m['to'] ) );
	eq( 1, count( $mine ), 'birthday email' );
	eq( 'Happy birthday, Bibi!', $mine[0]['subject'], 'subject' );
	$c = OYS_Coupons::for_user( $u );
	eq( 1, count( $c ), 'a personal code' );
	eq( 25, (int) $c[0]->value, '25% off' );
	ok( str_contains( $mine[0]['html'], $c[0]->code ), 'the code is in the email' );
	eq( 0, count( OYS_Coupons::for_user( $o ) ), 'not before the day' );
	eq( '', OYS_Rewards::birthday( make_user() ), 'optional' );
	eq( 0, count( array_filter( sent_mails( fn() => OYS_Rewards::send_birthdays() ), fn( $m ) => get_userdata( $u )->user_email === $m['to'] ) ), 'once a year' );
} );

test( 'loyalty draw: a ticket per class, weighted draw once, prize and anonymous newsletter', function () {
	global $wpdb;
	OYS_Settings::update( array( 'raffle_period' => 'half', 'raffle_prize' => 'pack-5', 'raffle_announce' => 1 ) );
	// Periods follow the calendar.
	$jan = ( new DateTimeImmutable( '2026-02-10 12:00', wp_timezone() ) )->getTimestamp();
	$p   = OYS_Rewards::period( $jan );
	eq( '2026-H1', $p[0], 'first half-year key' );
	eq( '2026-07-01', wp_date( 'Y-m-d', oys_ts( $p[2] ) ), 'ends on July 1' );
	eq( '2026-Q1', OYS_Rewards::period( $jan, 3 )[0], 'quarters' );
	// Last period: Ann came to 3 classes, Ben to 1 (plus a missed one that doesn't count).
	[ $key, $start, $end ] = OYS_Rewards::period( oys_ts( OYS_Rewards::period()[1] ) - DAY_IN_SECONDS );
	$wpdb->delete( OYS_Install::table( 'raffles' ), array( 'period_key' => $key ) );
	$ann = make_user( 'Ann' );
	$ben = make_user( 'Ben' );
	update_user_meta( $ann, 'oys_marketing', '1' );
	$past = function ( $user, $status ) use ( $start ) {
		global $wpdb;
		$s  = make_session();
		$t  = oys_ts( $start ) + 7 * DAY_IN_SECONDS + wp_rand( 0, 1000 ) * 60;
		$wpdb->update( OYS_Install::table( 'sessions' ), array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $t ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $t + 3600 ) ), array( 'id' => $s->id ) );
		$id = OYS_Bookings::book_manual( $user, $s->id, 'comp', false, true );
		OYS_Bookings::set_attendance( $id, $status );
	};
	$past( $ann, 'attended' );
	$past( $ann, 'attended' );
	$past( $ann, 'attended' );
	$past( $ben, 'attended' );
	$past( $ben, 'no_show' );
	$rows = array_column( array_map( fn( $r ) => (array) $r, OYS_Rewards::standings( $start, $end ) ), 'tickets', 'user_id' );
	eq( 3, (int) $rows[ $ann ], 'Ann: 3 tickets' );
	eq( 1, (int) $rows[ $ben ], 'Ben: 1 ticket (no-show not counted)' );
	$before = OYS_Passes::balance( $ann, 'class' ) + OYS_Passes::balance( $ben, 'class' );
	$log    = sent_mails( function () use ( &$draw ) { $draw = OYS_Rewards::run_draw(); } );
	ok( $draw && in_array( (int) $draw->winner_id, array_map( 'intval', array_keys( $rows ) ), true ), 'a winner among those with tickets' );
	eq( array_sum( $rows ), (int) $draw->tickets_total, 'all tickets in the hat' );
	eq( $before + 5, OYS_Passes::balance( $ann, 'class' ) + OYS_Passes::balance( $ben, 'class' ), 'the winner got a 5-class pass' );
	ok( (bool) array_filter( $log, fn( $m ) => str_starts_with( $m['subject'], 'You won:' ) ), 'winner emailed' );
	$n = OYS_Newsletter::get( $draw->newsletter_id );
	ok( $n && 'sending' === $n->status, 'announcement newsletter queued' );
	ok( ! str_contains( $n->body, 'Ann' ) && ! str_contains( $n->body, 'Ben' ), 'without names' );
	ok( null === OYS_Rewards::run_draw(), 'drawn once' );
	// Many draws: each ticket is one chance (Ann should win about 3 in 4).
	$wins = 0;
	$pool = array( (object) array( 'user_id' => 1, 'tickets' => 3 ), (object) array( 'user_id' => 2, 'tickets' => 1 ) );
	for ( $i = 0; $i < 2000; $i++ ) {
		$wins += 1 === (int) OYS_Rewards::pick( $pool )->user_id ? 1 : 0;
	}
	ok( $wins > 1380 && $wins < 1620, "weighted by tickets (3 of 4 tickets won $wins of 2000)" );
	eq( 0, OYS_Rewards::tickets( $ann ), 'new period: tickets start again' );
} );

test( 'newsletter: format, queue in batches, unsubscribe, subscribers only', function () {
	$html = OYS_Newsletter::render( "Hi {first_name},\n\n## New class\nSunday sunrise **on the beach**.\n\n- One\n- Two\n\n[Book now](https://example.test/book) or https://example.test" );
	ok( str_contains( $html, '<h2' ) && str_contains( $html, '<b>on the beach</b>' ) && str_contains( $html, '<li' ) && str_contains( $html, 'href="https://example.test/book"' ), 'headings, bold, lists and links' );
	ok( ! str_contains( OYS_Newsletter::render( '<script>x</script>' ), '<script>' ), 'escaped' );
	$a = make_user( 'Sub' );
	$b = make_user( 'NoSub' );
	$c = make_user( 'Sub2' );
	update_user_meta( $a, 'oys_marketing', '1' );
	update_user_meta( $c, 'oys_marketing', '1' );
	$id = OYS_Newsletter::save( array( 'subject' => 'News for {first_name}', 'body' => "Hi {first_name},\n\nclasses!", 'button_label' => 'Book', 'button_url' => 'https://example.test/book' ) );
	$subs = count( OYS_Newsletter::subscribers() );
	eq( $subs, OYS_Newsletter::send( $id ), 'queued for every subscriber' );
	ok( is_wp_error( OYS_Newsletter::send( $id ) ), 'sent once' );
	update_user_meta( $c, 'oys_marketing', '' ); // Unsubscribed after it was queued.
	$log = sent_mails( fn() => OYS_Newsletter::process_queue( 1000 ) );
	$to  = array_column( $log, 'to' );
	ok( in_array( get_userdata( $a )->user_email, $to, true ), 'subscriber emailed' );
	ok( ! in_array( get_userdata( $b )->user_email, $to, true ) && ! in_array( get_userdata( $c )->user_email, $to, true ), 'nobody else' );
	$mine = array_values( array_filter( $log, fn( $m ) => get_userdata( $a )->user_email === $m['to'] ) )[0];
	eq( 'News for Sub', $mine['subject'], 'personal subject' );
	ok( str_contains( $mine['html'], 'oys_unsub=' . $a . '.' ), 'unsubscribe link' );
	eq( 'sent', OYS_Newsletter::get( $id )->status, 'done' );
	eq( 0, count( sent_mails( fn() => OYS_Newsletter::process_queue( 1000 ) ) ), 'nothing sent twice' );
	// The unsubscribe link works without logging in; a forged one doesn't.
	preg_match( '/oys_unsub=([0-9]+\.[a-f0-9]+)/', $mine['html'], $m );
	$_GET['oys_unsub'] = $a . '.bad';
	add_filter( 'wp_die_handler', fn() => function ( $msg, $t, $args ) { throw new RuntimeException( 'die:' . ( $args['response'] ?? 0 ) ); } );
	try { OYS_Newsletter::maybe_unsubscribe(); } catch ( RuntimeException $e ) { eq( 'die:400', $e->getMessage(), 'forged link refused' ); }
	$_GET['oys_unsub'] = $m[1];
	try { OYS_Newsletter::maybe_unsubscribe(); } catch ( RuntimeException $e ) { eq( 'die:200', $e->getMessage(), 'unsubscribe page' ); }
	eq( '', get_user_meta( $a, 'oys_marketing', true ), 'unsubscribed' );
	unset( $_GET['oys_unsub'] );
	remove_all_filters( 'wp_die_handler' );
} );

test( 'AI newsletter draft: request shape, structured answer, errors', function () {
	OYS_Settings::update( array( 'ai_api_key' => 'sk-ant-test', 'ai_model' => 'claude-opus-5' ) );
	make_session( array( 'kind' => 'event', 'min_people' => 0 ) );
	$sent = null;
	$reply = array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'subject' => 'Sunrise on the sand', 'preheader' => 'A new Sunday class', 'body' => "Hi {first_name},\n\nSee you Sunday!\n\nOlivia", 'button_label' => 'Book a class' ) ) ) ) );
	$code  = 200;
	$fake  = function ( $pre, $args, $url ) use ( &$sent, &$reply, &$code ) {
		if ( ! str_starts_with( $url, OYS_AI::api_url() ) ) {
			return $pre;
		}
		$sent = array( 'headers' => $args['headers'], 'body' => json_decode( $args['body'], true ) );
		return array( 'headers' => array(), 'body' => wp_json_encode( $reply ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $fake, 5, 3 );
	$d = OYS_AI::draft_newsletter( 'New sunrise class on Sundays' );
	eq( 'Sunrise on the sand', $d['subject'], 'subject from the structured answer' );
	ok( str_contains( $d['body'], '{first_name}' ), 'keeps the name placeholder' );
	eq( 'claude-opus-5', $sent['body']['model'], 'model' );
	eq( 'sk-ant-test', $sent['headers']['x-api-key'], 'API key header' );
	eq( '2023-06-01', $sent['headers']['anthropic-version'], 'API version header' );
	eq( 'server-side-fallback-2026-07-01', $sent['headers']['anthropic-beta'], 'fallback beta header' );
	eq( 'default', $sent['body']['fallbacks'], 'server-side fallbacks' );
	eq( 'json_schema', $sent['body']['output_config']['format']['type'], 'structured output' );
	ok( str_contains( $sent['body']['messages'][0]['content'], 'Schedule for the next three weeks' ), 'timetable as context' );
	ok( ! isset( $sent['body']['thinking'] ) && ! isset( $sent['body']['temperature'] ), 'no removed parameters' );
	$reply = array( 'stop_reason' => 'refusal', 'content' => array() );
	ok( is_wp_error( OYS_AI::draft_newsletter( 'x' ) ), 'refusal handled' );
	$code  = 401;
	$reply = array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) );
	$e = OYS_AI::draft_newsletter( 'x' );
	ok( is_wp_error( $e ) && str_contains( $e->get_error_message(), 'API key' ), 'bad key explained' );
	remove_filter( 'pre_http_request', $fake, 5 );
	OYS_Settings::update( array( 'ai_api_key' => '' ) );
	ok( is_wp_error( OYS_AI::draft_newsletter( 'x' ) ), 'no key: asks for one' );
} );

function last_stripe( $path ) {
	foreach ( array_reverse( $GLOBALS['stripe_calls'] ) as $c ) {
		if ( $c[1] === $path || ( str_starts_with( $path, '#' ) && preg_match( $path . '$#', $c[1] ) ) ) {
			return $c;
		}
	}
	return null;
}

function make_teacher( $args = array() ) {
	global $wpdb;
	$id = OYS_Teachers::save( array_merge( array( 'name' => 'Anna Teach', 'email' => 'anna.' . wp_generate_password( 5, false ) . '@example.test', 'share_percent' => 60, 'headline' => 'Yin and breath' ), $args ) );
	if ( ! empty( $args['stripe'] ) ) {
		$wpdb->update( OYS_Teachers::table(), array( 'stripe_account' => $args['stripe'], 'stripe_ready' => 1, 'stripe_live' => 0 ), array( 'id' => $id ) );
		OYS_Teachers::flush();
	}
	return OYS_Teachers::get( $id );
}

test( 'teachers: profile, login, own classes, weekly classes, emails', function () {
	global $wpdb;
	$t = make_teacher( array( 'share_percent' => 55 ) );
	eq( 'anna-teach', $t->slug, 'slug from the name' );
	eq( 'anna-teach-2', make_teacher()->slug, 'slugs stay unique' );
	ok( is_wp_error( OYS_Teachers::save( array( 'name' => '' ) ) ), 'name required' );
	eq( 55, (int) $t->share_percent, 'own share' );
	eq( 'Anna Teach', OYS_Teachers::name_for( make_session( array( 'teacher' => $t->id ) ) ), 'class shows its teacher' );
	eq( '', OYS_Teachers::name_for( make_session() ), 'the studio\'s own class has no teacher' );
	eq( OYS_Settings::get( 'owner_name' ), OYS_Teachers::display_name( make_session() ), 'owner name for own classes' );
	eq( 0, OYS_Teachers::valid_id( 999999 ), 'unknown teacher id becomes 0' );

	$sent = array();
	$cb   = function ( $to, $subject ) use ( &$sent ) { $sent[] = array( $to, $subject ); };
	add_action( 'oys_email_sent', $cb, 10, 2 );
	$uid = OYS_Teachers::give_access( $t->id );
	ok( is_int( $uid ), 'login created' );
	$user = get_userdata( $uid );
	ok( in_array( 'oys_teacher', $user->roles, true ), 'teacher role' );
	ok( user_can( $user, 'oys_teach' ) && ! user_can( $user, 'oys_manage' ), 'can teach, cannot manage' );
	ok( ! OYS_Customers::is_customer_only( $user ), 'teachers may use the dashboard' );
	ok( (bool) array_filter( $sent, fn( $m ) => $m[0] === $t->email && str_contains( $m[1], 'teacher login' ) ), 'password email sent' );
	wp_set_current_user( $uid );
	eq( (int) $t->id, (int) OYS_Teachers::current()->id, 'current teacher from the login' );
	wp_set_current_user( 0 );
	$other = make_teacher( array( 'email' => $t->email ) );
	ok( is_wp_error( OYS_Teachers::give_access( $other->id ) ), 'one login per teacher' );
	OYS_Teachers::remove_access( $t->id );
	ok( ! user_can( get_userdata( $uid ), 'oys_teach' ), 'login removed' );

	$mine  = make_session( array( 'teacher' => $t->id ) );
	$other = make_session( array( 'teacher' => make_teacher()->id ) );
	ok( OYS_Teachers::owns( $t, $mine ) && ! OYS_Teachers::owns( $t, $other ) && ! OYS_Teachers::owns( $t, make_session() ), 'owns only their classes' );
	$ids = wp_list_pluck( OYS_Schedule::query( array( 'teacher' => $t->id, 'from' => oys_now() ) ), 'id' );
	ok( in_array( $mine->id, $ids ) && ! in_array( $other->id, $ids ), 'query by teacher' );

	// Weekly class: the teacher goes with every date and stays when a form doesn't send it.
	$tpl = OYS_Schedule::save_template( array( 'class_slug' => 'hatha-flow', 'weekday' => (int) wp_date( 'N', time() + 2 * DAY_IN_SECONDS ), 'start_time' => '07:15', 'active' => 1, 'teacher_id' => $t->id ) );
	OYS_Schedule::generate( 2 );
	$dates = $wpdb->get_col( $wpdb->prepare( 'SELECT teacher_id FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE template_id = %d', $tpl ) );
	ok( $dates && array_unique( $dates ) === array( (string) $t->id ), 'generated dates have the teacher' );
	OYS_Schedule::save_template( array( 'class_slug' => 'hatha-flow', 'weekday' => 3, 'start_time' => '07:15', 'active' => 1 ), $tpl );
	eq( (int) $t->id, (int) OYS_Schedule::template( $tpl )->teacher_id, 'kept when not sent' );
	$res = OYS_Calendar::rest_save( cal_req( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'date' => wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ), 'start' => '09:00', 'duration' => 60, 'capacity' => 8, 'price' => 2000, 'teacher_id' => $t->id ) ) );
	eq( (int) $t->id, $res->get_data()['session']['teacher_id'], 'calendar saves the teacher' );
	eq( 'Anna Teach', $res->get_data()['session']['teacher'], 'calendar shows the name' );

	// Emails: the teacher line, {teacher}, notices to the teacher, replies to the teacher.
	eq( 'Anna Teach', OYS_Email_Templates::vars_for( 0, $mine )['teacher'], '{teacher} placeholder' );
	$sent = array();
	$u    = make_user( 'Mia' );
	OYS_Bookings::book_manual( $u, $mine->id, 'comp', true, true );
	ok( (bool) array_filter( $sent, fn( $m ) => $m[0] === $t->email && str_contains( $m[1], 'New booking' ) ), 'teacher told about a new booking' );
	OYS_Teachers::save( array( 'notify' => 0 ), $t->id );
	$sent = array();
	OYS_Bookings::book_manual( make_user(), $mine->id, 'comp', true, true );
	ok( ! array_filter( $sent, fn( $m ) => $m[0] === $t->email ), 'no notices when switched off' );
	remove_action( 'oys_email_sent', $cb, 10 );
	$headers = array();
	$cap     = function ( $atts ) use ( &$headers ) { $headers[] = $atts['headers']; return $atts; };
	add_filter( 'wp_mail', $cap );
	eq( 2, OYS_Messages::send_to_session( $mine->id, 'Hi', 'Bring a blanket', array( 'reply_to' => $t->email ) ), 'teacher message sent to everyone' );
	remove_filter( 'wp_mail', $cap );
	ok( in_array( 'Reply-To: ' . $t->email, $headers[0], true ), 'replies go to the teacher' );

	$data = OYS_App_API::session_data( $mine, $u );
	eq( 'Anna Teach', $data['teacher']['name'], 'app gets the teacher' );
	ok( null === OYS_App_API::session_data( make_session(), $u )['teacher'], 'no teacher for the studio\'s classes' );
	ok( str_contains( OYS_Teachers::shortcode(), 'Anna Teach' ), 'teachers page lists them' );
} );

test( 'connect: card payments for a teacher\'s class go to their Stripe, studio fee, refunds', function () {
	global $wpdb;
	$t = make_teacher( array( 'stripe' => 'acct_anna', 'share_percent' => 60 ) );
	$s = make_session( array( 'teacher' => $t->id, 'price' => 2500 ) );
	$u = make_user();
	$o = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 2500 ) );
	$b = OYS_Bookings::hold( $u, $s, $o );
	OYS_Orders::update( $o, array( 'booking_id' => $b ) );
	ok( is_string( OYS_Stripe::start_checkout( $o, 'Class', '' ) ), 'checkout started' );
	$c = last_stripe( '/v1/checkout/sessions' );
	eq( 'acct_anna', $c[3], 'created on the teacher\'s account' );
	eq( 1000, (int) $c[2]['payment_intent_data']['application_fee_amount'], 'studio fee = 40%' );
	ok( empty( $c[2]['customer'] ) && ! empty( $c[2]['customer_email'] ), 'no studio customer id on their account' );
	$order = OYS_Orders::get( $o );
	eq( 'acct_anna', $order->meta['stripe_account'], 'order remembers the account' );
	eq( 1000, $order->meta['app_fee_cents'], 'order remembers the fee' );

	OYS_Stripe::sync_session( $order->stripe_session_id );
	eq( 'acct_anna', last_stripe( '#^/v1/checkout/sessions/[^/]+' )[3], 'reading the session uses the account' );
	OYS_Stripe::expire_session( $order );
	eq( 'acct_anna', last_stripe( '#^/v1/checkout/sessions/.+/expire' )[3], 'closing the session uses the account' );

	// Passes, studio classes and teachers without Stripe are paid to the studio.
	$pack = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'pack', 'product_id' => 'pack-5', 'session_id' => $s->id, 'amount_cents' => 11000 ) );
	OYS_Stripe::start_checkout( $pack, 'Pass', '' );
	eq( '', last_stripe( '/v1/checkout/sessions' )[3], 'a pass is the studio\'s' );
	$own = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => make_session()->id, 'amount_cents' => 2500 ) );
	OYS_Stripe::start_checkout( $own, 'Class', '' );
	eq( '', last_stripe( '/v1/checkout/sessions' )[3], 'the studio\'s own class' );
	$nost = make_teacher();
	ok( null === OYS_Connect::split_for_order( OYS_Orders::get( OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => make_session( array( 'teacher' => $nost->id ) )->id, 'amount_cents' => 2500 ) ) ) ), 'teacher without Stripe: studio' );
	OYS_Settings::update( array( 'stripe_mode' => 'live', 'stripe_live_secret' => 'sk_live_unit' ) );
	ok( ! OYS_Connect::ready( OYS_Teachers::get( $t->id ) ), 'a test account is not used in live mode' );
	OYS_Settings::update( array( 'stripe_mode' => 'test' ) );

	// Paid, then cancelled in time: refunded on their account (a studio credit would leave the money with them).
	OYS_Orders::mark_paid( $o, array( 'payment_intent' => 'pi_anna' ) );
	$credits = OYS_Passes::balance( $u );
	eq( 'refunded', OYS_Bookings::cancel( $b ), 'cancel refunds the card' );
	$r = last_stripe( '/v1/refunds' );
	eq( 'acct_anna', $r[3], 'refund on the teacher\'s account' );
	eq( 'true', $r[2]['refund_application_fee'], 'studio fee returned' );
	eq( 2500, (int) $r[2]['amount'], 'whole seat refunded' );
	eq( $credits, OYS_Passes::balance( $u ), 'no class credit given' );
	eq( 'refunded', OYS_Orders::get( $o )->status, 'order refunded' );

	// Party: each cancelled guest gets their seat back, the last one the rest.
	$o2 = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 5000 ) );
	$b2 = OYS_Bookings::hold( $u, $s, $o2, guests( 'Bea' ) );
	OYS_Stripe::start_checkout( $o2, 'Class', '' );
	OYS_Orders::mark_paid( $o2, array( 'payment_intent' => 'pi_anna2' ) );
	$guest = OYS_Bookings::guests_of( $b2, array( 'confirmed' ) )[0];
	OYS_Bookings::cancel( $guest->id );
	eq( 2500, (int) last_stripe( '/v1/refunds' )[2]['amount'], 'guest seat refunded' );
	eq( 'partially_refunded', OYS_Orders::get( $o2 )->status, 'host still booked' );
	eq( 'confirmed', OYS_Bookings::get( $b2 )->status, 'host booking kept' );

	// Connect webhook: signed with the Connect secret, updates the teacher.
	OYS_Settings::update( array( 'stripe_test_connect_webhook' => 'whsec_connect_unit' ) );
	$wpdb->update( OYS_Teachers::table(), array( 'stripe_ready' => 0 ), array( 'id' => $t->id ) );
	OYS_Teachers::flush();
	$payload = wp_json_encode( array( 'id' => 'evt_acct_' . wp_generate_password( 6, false ), 'type' => 'account.updated', 'account' => 'acct_anna', 'data' => array( 'object' => array( 'id' => 'acct_anna', 'charges_enabled' => true, 'details_submitted' => true ) ) ) );
	$ts      = time();
	$req     = new WP_REST_Request( 'POST', '/oys/v1/stripe-webhook' );
	$req->set_body( $payload );
	$req->set_header( 'stripe-signature', "t=$ts,v1=" . hash_hmac( 'sha256', "$ts.$payload", 'whsec_connect_unit' ) );
	eq( 200, OYS_Stripe::webhook( $req )->get_status(), 'Connect event accepted' );
	ok( OYS_Connect::ready( OYS_Teachers::get( $t->id ) ), 'account ready from the event' );
	$req->set_header( 'stripe-signature', "t=$ts,v1=" . hash_hmac( 'sha256', "$ts.$payload", 'whsec_wrong' ) );
	eq( 400, OYS_Stripe::webhook( $req )->get_status(), 'bad signature refused' );

	// Onboarding: the account is created once, then a link each time.
	$new = make_teacher();
	$url = OYS_Connect::onboarding_url( $new->id, admin_url( 'admin.php?page=oys-teach-profile' ) );
	ok( str_starts_with( $url, 'https://connect.stripe.test/setup/acct_new' ), 'onboarding link' );
	$acct = OYS_Teachers::get( $new->id )->stripe_account;
	$create = last_stripe( '/v1/accounts' );
	eq( 'full', $create[2]['controller']['stripe_dashboard']['type'], 'teacher gets their own dashboard' );
	eq( 'account', $create[2]['controller']['fees']['payer'], 'teacher pays their Stripe fees' );
	$n = count( array_filter( $GLOBALS['stripe_calls'], fn( $c ) => '/v1/accounts' === $c[1] ) );
	OYS_Connect::onboarding_url( $new->id, admin_url() );
	eq( $n, count( array_filter( $GLOBALS['stripe_calls'], fn( $c ) => '/v1/accounts' === $c[1] ) ), 'account not created twice' );
	eq( $acct, OYS_Teachers::get( $new->id )->stripe_account, 'same account' );
	$GLOBALS['acct_ready'] = false;
	ok( false === OYS_Connect::refresh( $new->id ), 'not finished yet' );
	$GLOBALS['acct_ready'] = true;
	ok( true === OYS_Connect::refresh( $new->id ), 'finished' );
	OYS_Connect::disconnect( $new->id );
	ok( ! OYS_Connect::ready( OYS_Teachers::get( $new->id ) ), 'disconnected' );
} );

test( 'statement: what each visit is worth and who owes whom', function () {
	global $wpdb;
	$t = make_teacher( array( 'share_percent' => 50, 'cash_by' => 'teacher' ) );
	OYS_Settings::update( array( 'settle_membership_cents' => 1500 ) );
	$s     = make_session( array( 'teacher' => $t->id, 'in_hours' => -48, 'price' => 2500 ) );
	$month = wp_date( 'Y-m', oys_ts( $s->starts_at ) );
	$u     = make_user();
	$bk    = function ( $row ) use ( $s, $u, $wpdb ) {
		$wpdb->insert( OYS_Install::table( 'bookings' ), array_merge( array( 'session_id' => $s->id, 'user_id' => $u, 'status' => 'attended', 'created_at' => oys_now() ), $row ) );
		return $wpdb->insert_id;
	};
	$order = function ( $amount, $meta = array(), $type = 'dropin' ) use ( $u, $s ) {
		$id = OYS_Orders::create( array( 'user_id' => $u, 'type' => $type, 'session_id' => $s->id, 'amount_cents' => $amount, 'meta' => $meta ) );
		OYS_Orders::update( $id, array( 'status' => 'paid' ) );
		return $id;
	};
	$bk( array( 'paid_with' => 'card', 'order_id' => $order( 2000, array( 'stripe_account' => 'acct_x', 'app_fee_cents' => 1000 ) ) ) ); // to the teacher's Stripe
	$bk( array( 'paid_with' => 'card', 'order_id' => $order( 2500 ) ) );                                                            // to the studio
	$pack = $order( 10000, array(), 'pack' );
	$bk( array( 'paid_with' => 'credit', 'pass_id' => OYS_Passes::grant( $u, array( 'product_id' => 'pack-5', 'credits' => 5, 'order_id' => $pack ) ) ) ); // 100 / 5
	$bk( array( 'paid_with' => 'credit', 'pass_id' => OYS_Passes::grant( $u, array( 'credits' => 1, 'source' => 'admin' ) ) ) );                 // free: class price
	$bk( array( 'paid_with' => 'membership', 'status' => 'no_show' ) );
	$bk( array( 'paid_with' => 'door', 'due_cents' => 1800, 'collected_with' => 'cash' ) );
	$bk( array( 'paid_with' => 'door', 'due_cents' => 1500 ) );
	$bk( array( 'paid_with' => 'comp' ) );
	$bk( array( 'paid_with' => 'card', 'status' => 'cancelled', 'order_id' => $order( 2500 ) ) );
	make_session( array( 'teacher' => $t->id, 'in_hours' => 48 ) ); // not taught yet
	$st = OYS_Connect::statement( $t->id, $month );
	eq( 1, $st['classes'], 'one finished class' );
	eq( 8, count( $st['rows'] ), 'every paid or free visit, not cancellations' );
	$how = wp_list_pluck( $st['rows'], 'how' );
	eq( array( 'card_direct', 'card', 'credit', 'credit', 'membership', 'cash', 'due', 'free' ), $how, 'kinds of payment' );
	eq( array( 2000, 2500, 2000, 2500, 1500, 1800, 0, 0 ), wp_list_pluck( $st['rows'], 'value' ), 'values' );
	$tot = $st['totals'];
	eq( 2000, $tot['direct'], 'paid straight to the teacher' );
	eq( 1000, $tot['direct_fee'], 'studio fee already taken' );
	eq( 1250 + 1000 + 1250 + 750, $tot['studio_owes'], 'studio owes the teacher their part' );
	eq( 900, $tot['teacher_owes'], 'teacher owes the studio its part of the cash' );
	eq( 4250 - 900, $tot['balance'], 'balance' );
	eq( 1500, $tot['uncollected'], 'still to collect' );
	OYS_Teachers::save( array( 'cash_by' => 'studio' ), $t->id );
	$st2 = OYS_Connect::statement( $t->id, $month );
	eq( 4250 + 900, $st2['totals']['studio_owes'], 'studio collects: owes the teacher their part of the cash' );
	eq( 0, $st2['totals']['teacher_owes'], 'teacher owes nothing' );
	$csv = OYS_Connect::csv( $st );
	ok( str_contains( $csv, 'Balance' ) && str_contains( $csv, '33.50' ), 'CSV with the balance' );
	eq( 0, OYS_Connect::statement( make_teacher()->id, $month )['classes'], 'other teachers see nothing' );
	eq( 1000, OYS_Connect::credit_value( (object) array( 'order_id' => 0, 'source' => 'admin' ), (object) array( 'price_cents' => 1000, 'format' => 'studio' ) ), 'free credit: class price' );
} );

test( 'helpers: money and periods', function () {
	eq( '$25', oys_money( 2500, 'usd' ), 'whole dollars' );
	eq( '$25.50', oys_money( 2550, 'usd' ), 'cents' );
	eq( 2550, oys_cents_from_input( '$25.50' ), 'parse input' );
	eq( 'per month', OYS_Products::period_label( array( 'interval' => 'month', 'interval_count' => 1 ) ), 'monthly label' );
	eq( 'every 3 months', OYS_Products::period_label( array( 'interval' => 'month', 'interval_count' => 3 ) ), 'quarterly label' );
} );

$t = $GLOBALS['oys_t'];
echo "\n{$t['pass']} assertions passed, {$t['fail']} failed\n";
exit( $t['fail'] ? 1 : 0 );
