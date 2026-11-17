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
// A sablonokat (header.php → wp_head → wp_resource_hints) CLI-ből is rendereljük: a mag a SERVER_NAME-et olvassa,
// enélkül „Undefined array key SERVER_NAME” kerül a debug.log-ba, és a CI emiatt bukik.
$_SERVER['SERVER_NAME'] = '127.0.0.1';
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

test( 'waitlist, automatic move-in switched on: a member is moved in when a seat opens', function () {
	OYS_Settings::update( array( 'waitlist_auto' => 1 ) );
	try {
		waitlist_member_moved_in();
	} finally {
		OYS_Settings::update( array( 'waitlist_auto' => 0 ) );
	}
} );

function waitlist_member_moved_in() {
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
}

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
	// at 10:00 in three days (a class late in the evening would run past midnight), with a teacher of its own: one teacher
	// cannot lead two classes at once, and other tests' sessions „in 72 hours” land near 10:00 when the suite runs in the morning
	$s = make_session( array( 'capacity' => 3, 'teacher' => (int) make_teacher( array( 'name' => 'Capacity Teacher' ) )->id, 'in_hours' => ( ( new DateTimeImmutable( 'today 10:00', wp_timezone() ) )->modify( '+3 days' )->getTimestamp() - time() ) / HOUR_IN_SECONDS ) );
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
	ok( str_starts_with( $subject, 'Reminder: ' . OYS_Emails::sample_vars()['class'] ) && str_contains( $html, 'See you soon' ), 'preview with sample details' );
	$next = OYS_Schedule::query( array( 'kind' => 'group', 'from' => oys_now(), 'status' => 'scheduled', 'limit' => 1 ) );
	ok( str_contains( $subject, $next ? oys_session_title( $next[0] ) : 'Hatha Flow' ), 'the preview uses the site\'s own next class, not a made-up one' );
	delete_option( OYS_Email_Templates::OPTION );
} );

test( 'reminders: two class reminders, online join link, pass about to expire', function () {
	global $wpdb;
	delete_option( OYS_Email_Templates::OPTION );
	OYS_Settings::update( array( 'reminder_hours' => 24, 'reminder2_hours' => 2, 'join_reminder_minutes' => 30, 'pass_expiry_days' => 7 ) );
	$b = OYS_Install::table( 'bookings' );
	$u = make_user( 'Rita' );
	$s = make_session( array( 'in_hours' => 20 ) );
	$id = OYS_Bookings::book_manual( $u, $s, 'comp', false, true, 'online' );
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

test( 'one-to-one services: their own credits, alone or with a friend; a single as a gift is one credit', function () {
	global $wpdb;
	$products = get_option( OYS_Products::OPTION );
	$services = get_option( OYS_Services::OPTION );
	try {
		update_option( OYS_Services::OPTION, array(
			'prenatal'   => array( 'label' => 'Prenatal', 'class' => 'kismamatorna', 'duration' => 60, 'duo' => 1, 'sort' => 1 ),
			'relax'      => array( 'label' => 'Relaxation', 'class' => 'relaxacio', 'duration' => 30, 'duo' => 0, 'sort' => 2 ),
		) );
		update_option( OYS_Products::OPTION, array_merge( (array) $products, array(
			'pn-1'  => array( 'name' => 'Prenatal 1', 'kind' => 'private_single', 'service' => 'prenatal', 'people' => 1, 'credits' => 1, 'validity_days' => 0, 'price_cents' => 5000, 'giftable' => 1, 'active' => 1, 'duration_min' => 60 ),
			'pn-2'  => array( 'name' => 'Prenatal 1, two', 'kind' => 'private_single', 'service' => 'prenatal', 'people' => 2, 'credits' => 1, 'validity_days' => 0, 'price_cents' => 8000, 'giftable' => 1, 'active' => 1, 'duration_min' => 60 ),
			'pn-5'  => array( 'name' => 'Prenatal 5', 'kind' => 'private_pack', 'service' => 'prenatal', 'credits' => 5, 'validity_days' => 0, 'price_cents' => 22000, 'active' => 1, 'duration_min' => 60 ),
			'pn-d5' => array( 'name' => 'Prenatal 5, two', 'kind' => 'prenatal_duo_pack', 'credits' => 5, 'validity_days' => 0, 'price_cents' => 36000, 'active' => 1, 'duration_min' => 60 ),
			'rx-1'  => array( 'name' => 'Relax 1', 'kind' => 'private_single', 'service' => 'relax', 'credits' => 1, 'validity_days' => 0, 'price_cents' => 2000, 'active' => 1, 'duration_min' => 30 ),
		) ) );
		eq( array( 'prenatal', 'relax' ), array_keys( OYS_Services::offered() ), 'the services on offer' );
		eq( 5000, OYS_Services::price( 'prenatal' ), 'price alone' );
		eq( 8000, OYS_Services::price( 'prenatal', 2 ), 'price with a friend' );
		$legacy = OYS_Products::get( 'pn-d5' );
		ok( 'private_pack' === $legacy['kind'] && 'prenatal' === $legacy['service'] && 2 === $legacy['people'], '1.5.0 prenatal_duo_pack is read as a prenatal pack for two' );
		ok( isset( OYS_Products::purchasable()['pn-5'] ) && ! isset( OYS_Products::purchasable()['pn-1'] ), 'packs are bought, singles are booked by request' );
		$prices = OYS_Frontend::sc_pricing( array() );
		ok( str_contains( $prices, 'per session, 30 minutes' ) && str_contains( $prices, 'topic=relax' ), 'a 30-minute session of a service has its own card on the price list' );
		$u = make_user( 'Mia' );
		foreach ( array( array( 'prenatal', 1, 60, 'kismamatorna', 'prenatal' ), array( 'relax', 1, 30, 'relaxacio', 'relax' ), array( 'prenatal', 2, 60, 'kismamatorna-duo', 'prenatal_duo' ) ) as $n => [ $topic, $people, $dur, $slug, $kind ] ) {
			$wpdb->insert( OYS_Install::table( 'private_requests' ), array( 'user_id' => $u, 'topic' => $topic, 'duration_min' => $dur, 'people' => $people, 'location_type' => 'online', 'preferred' => 'mornings', 'status' => 'new', 'created_at' => oys_now() ) );
			$s = OYS_Schedule::get( OYS_Privates::offer( (int) $wpdb->insert_id, wp_date( 'Y-m-d', time() + ( 3 + $n ) * DAY_IN_SECONDS ) . 'T10:00', $dur, 5000, '', 'https://meet.example/x' ) );
			eq( $slug, $s->class_slug, "$topic for $people: its class" );
			eq( $kind, OYS_Bookings::credit_kind( $s ), "$topic for $people: paid with $kind credits" );
			eq( 1, (int) $s->credits_allowed, "$topic: a credit covers the service's own length" );
		}
		OYS_Passes::grant( $u, array( 'product_id' => 'p5', 'name' => 'P5', 'kind' => 'private', 'credits' => 5, 'validity_days' => 0 ) );
		eq( 0, OYS_Passes::available_for( $u, $s ), 'private yoga credits do not pay for a prenatal session' );
		OYS_Passes::grant_product( $u, OYS_Products::get( 'pn-d5' ) );
		eq( 5, OYS_Passes::available_for( $u, $s ), 'the friend pack pays for the session for two' );
		$g = OYS_Passes::get( OYS_Passes::grant_product( $u, OYS_Products::get( 'pn-2' ), 0, 'gift' ) );
		ok( 'prenatal_duo' === $g->kind && 1 === (int) $g->credits_total, 'a single for two, as a gift: one session for the two' );
		ok( isset( OYS_Passes::kinds()['relax'] ) && isset( OYS_Passes::kinds()['prenatal_duo'] ), 'the services have their own credit kinds' );
	} finally {
		update_option( OYS_Products::OPTION, $products );
		false === $services ? delete_option( OYS_Services::OPTION ) : update_option( OYS_Services::OPTION, $services );
	}
} );

test( 'one-to-one in two lengths (60 / 90 minutes): own credits, own cards, the price list switches (Olivia, 13 Nov)', function () {
	global $wpdb;
	$products = get_option( OYS_Products::OPTION );
	$services = get_option( OYS_Services::OPTION );
	try {
		update_option( OYS_Services::OPTION, array(
			'yoga1' => array( 'label' => 'One-to-one yoga', 'class' => 'egyeni', 'duration' => 60, 'durations' => '60,90', 'duo' => 0, 'sort' => 1 ),
			'pn'    => array( 'label' => 'Prenatal', 'class' => 'kismama', 'duration' => 60, 'duo' => 1, 'sort' => 2 ),
		) );
		$p = fn( $kind, $svc, $min, $price, $people = 1 ) => array( 'name' => "$svc $kind $min $people", 'kind' => $kind, 'service' => $svc, 'people' => $people, 'credits' => 'private_pack' === $kind ? 5 : 1, 'validity_days' => 'private_pack' === $kind ? 42 : 365, 'price_cents' => $price, 'features' => "One-to-one\nPlanned for you", 'giftable' => 1, 'active' => 1, 'duration_min' => $min );
		update_option( OYS_Products::OPTION, array(
			'y60-1' => $p( 'private_single', 'yoga1', 60, 500000 ), 'y60-5' => $p( 'private_pack', 'yoga1', 60, 2200000 ),
			'y90-1' => $p( 'private_single', 'yoga1', 90, 700000 ), 'y90-5' => $p( 'private_pack', 'yoga1', 90, 3000000 ),
			'pn-1'  => $p( 'private_single', 'pn', 60, 500000 ), 'pn-2' => $p( 'private_single', 'pn', 60, 800000, 2 ),
			'pn-5'  => $p( 'private_pack', 'pn', 60, 2200000 ), 'pn-d5' => $p( 'private_pack', 'pn', 60, 3600000, 2 ),
		) );
		eq( array( 60, 90 ), OYS_Services::lengths( 'yoga1' ), 'the service is sold in 60 and 90 minutes' );
		eq( 700000, OYS_Services::price( 'yoga1', 1, 90 ), '90 minutes: its own price' );
		eq( 500000, OYS_Services::price( 'yoga1' ), 'the first length is the default' );
		eq( 'yoga1', OYS_Products::credit_kind( OYS_Products::get( 'y60-5' ) ), 'the 60-minute pass: the service credits' );
		eq( 'yoga1_90', OYS_Products::credit_kind( OYS_Products::get( 'y90-5' ) ), 'the 90-minute pass: credits of its own' );
		ok( isset( OYS_Passes::kinds()['yoga1_90'] ), 'the 90-minute credits are a kind of their own' );
		$u = make_user( 'Lena' );
		$wpdb->insert( OYS_Install::table( 'private_requests' ), array( 'user_id' => $u, 'topic' => 'yoga1', 'duration_min' => 90, 'people' => 1, 'location_type' => 'online', 'preferred' => 'evenings', 'status' => 'new', 'created_at' => oys_now() ) );
		$s = OYS_Schedule::get( OYS_Privates::offer( (int) $wpdb->insert_id, wp_date( 'Y-m-d', time() + 4 * DAY_IN_SECONDS ) . 'T10:00', 90, 700000, '', 'https://meet.example/y' ) );
		eq( 1, (int) $s->credits_allowed, 'a 90-minute session can be paid with a pass' );
		eq( 'yoga1_90', OYS_Bookings::credit_kind( $s ), 'with the 90-minute credits' );
		OYS_Passes::grant_product( $u, OYS_Products::get( 'y60-5' ) );
		eq( 0, OYS_Passes::available_for( $u, $s ), 'a 60-minute pass does not pay for 90 minutes' );
		$p90 = OYS_Passes::get( OYS_Passes::grant_product( $u, OYS_Products::get( 'y90-5' ) ) );
		eq( 5, OYS_Passes::available_for( $u, $s ), 'the 90-minute pass does' );
		// the studio cancels the private session: the 90-minute pass runs a week longer (as the group passes)
		OYS_Bookings::book_party( $u, $s, array( 'method' => 'credit', 'notify' => false ) );
		OYS_Schedule::cancel_session( $s->id, 'test' );
		eq( oys_ts( $p90->expires_at ) + 7 * DAY_IN_SECONDS, oys_ts( OYS_Passes::get( $p90->id )->expires_at ), 'studio cancels a private session: the pass is valid 7 days longer' );

		add_filter( 'oys_price_gift_line', '__return_true' );
		$html = OYS_Frontend::sc_pricing( array() );
		remove_filter( 'oys_price_gift_line', '__return_true' );
		ok( str_contains( $html, 'data-oys-variants' ) && str_contains( $html, 'data-len="60"' ) && str_contains( $html, 'data-len="90"' ), 'a length switch for the service in two lengths' );
		ok( 1 === substr_count( $html, 'Cheaper as a pair' ), '„Cheaper as a pair” only where there is a price for two' );
		ok( str_contains( $html, 'each</small>' ) && str_contains( $html, 'class="price__each"' ), 'the pair switch and cards say what it costs each (Olivia, 14 Nov)' );
		ok( str_contains( $html, '5 sessions × 90 minutes' ), 'the pass: 5 sessions × 90 minutes' );
		ok( str_contains( $html, 'per session, 60 minutes, for the two of you' ), 'the single for two' );
		ok( str_contains( $html, 'length=90' ), 'the 90-minute single asks for a 90-minute time' );
		ok( str_contains( $html, 'class="price__gift"' ), 'the gift card as its own line' );
		eq( 4, preg_match_all( '/data-len="60" data-people="1" data-on/', $html ), 'first shown: the 60-minute single and pass, alone' );
		ok( str_contains( OYS_Frontend::sc_private_box(), 'One-to-one yoga' ) && str_contains( OYS_Frontend::sc_private_box(), 'tab=private' ), 'the one-to-one box lists the services and asks for a time' );
		$form = OYS_I18n::with( 'en', function () {
			$_GET['topic'] = 'yoga1';
			$_GET['length'] = '90';
			$m = new ReflectionMethod( 'OYS_Frontend', 'private_form' );
			$m->setAccessible( true );
			$out = $m->invoke( null );
			unset( $_GET['topic'], $_GET['length'] );
			return $out;
		} );
		ok( str_contains( $form, 'data-lengths="60,90"' ) && str_contains( $form, 'id="oys-pr-length"' ) && str_contains( $form, '<option value="90" selected' ), 'the request form asks for the length, 90 minutes chosen' );
	} finally {
		update_option( OYS_Products::OPTION, $products );
		false === $services ? delete_option( OYS_Services::OPTION ) : update_option( OYS_Services::OPTION, $services );
	}
} );

test( 'timetable with only the days that have a class: the next ones, however far, and the one-to-one box (Olivia, 13 Nov)', function () {
	$far = make_session( array( 'format' => 'online', 'online_capacity' => 10, 'in_hours' => 24 * 30 ) );
	$all = do_shortcode( '[oys_schedule days="7"]' );
	ok( str_contains( $all, 'is-empty' ) && ! str_contains( $all, wp_date( oys_date_format( 'M j' ), oys_ts( $far->starts_at ) ) ), 'by default: every day of the week, empty ones too, nothing further' );
	OYS_Settings::update( array( 'schedule_empty_days' => 'no' ) );
	try {
		$html = do_shortcode( '[oys_schedule days="7" next="500"]' ); // the test site has many classes before it
		ok( ! str_contains( $html, 'is-empty' ), 'no empty days' );
		eq( 6, substr_count( do_shortcode( '[oys_schedule days="7"]' ), '<li class="session ' ), 'by default the next six classes' );
		ok( str_contains( $html, wp_date( oys_date_format( 'M j' ), oys_ts( $far->starts_at ) ) ), 'the class in 30 days is listed' );
	} finally {
		OYS_Settings::update( array( 'schedule_empty_days' => 'yes' ) );
		OYS_Schedule::cancel_session( $far->id, 'test' );
	}
} );

test( 'studio cancels a class: time-limited passes are valid a week longer', function () {
	$u    = make_user( 'Ella' );
	$pass = OYS_Passes::get( OYS_Passes::grant( $u, array( 'product_id' => 'online-5', 'name' => '5 online', 'kind' => 'online', 'credits' => 5, 'validity_days' => 56, 'order_id' => 0, 'source' => 'purchase' ) ) );
	$open = OYS_Passes::get( OYS_Passes::grant( $u, array( 'product_id' => 'x', 'name' => 'no expiry', 'kind' => 'online', 'credits' => 5, 'validity_days' => 0, 'order_id' => 0, 'source' => 'purchase' ) ) );
	$priv = OYS_Passes::get( OYS_Passes::grant( $u, array( 'product_id' => 'y', 'name' => 'private', 'kind' => 'private', 'credits' => 5, 'validity_days' => 56, 'order_id' => 0, 'source' => 'purchase' ) ) );
	$s    = make_session( array( 'format' => 'online', 'online_capacity' => 10, 'in_hours' => 30 ) );
	OYS_Schedule::cancel_session( $s->id, 'test' );
	eq( oys_ts( $pass->expires_at ) + 7 * DAY_IN_SECONDS, oys_ts( OYS_Passes::get( $pass->id )->expires_at ), 'the online pass: 7 days longer' );
	ok( null === OYS_Passes::get( $open->id )->expires_at, 'a pass without expiry stays so' );
	eq( $priv->expires_at, OYS_Passes::get( $priv->id )->expires_at, 'private credits are not touched by a group class' );
	OYS_Settings::update( array( 'cancel_extend_days' => 0 ) );
	$s2 = make_session( array( 'format' => 'online', 'online_capacity' => 10, 'in_hours' => 50 ) );
	OYS_Schedule::cancel_session( $s2->id, 'test' );
	eq( oys_ts( $pass->expires_at ) + 7 * DAY_IN_SECONDS, oys_ts( OYS_Passes::get( $pass->id )->expires_at ), 'setting 0: no extension' );
	OYS_Settings::update( array( 'cancel_extend_days' => 7 ) );
} );

test( 'upgrade: no dollar-priced default passes on a site in forints', function () {
	$products = get_option( OYS_Products::OPTION );
	$currency = OYS_Settings::get( 'currency' );
	try {
		update_option( OYS_Products::OPTION, array( 'online-5' => array( 'name' => '5', 'kind' => 'online_pack', 'credits' => 5, 'validity_days' => 56, 'price_cents' => 1300000, 'active' => 1 ) ) );
		OYS_Settings::update( array( 'currency' => 'huf' ) );
		OYS_Products::add_missing_kinds();
		eq( array( 'online-5' ), array_keys( get_option( OYS_Products::OPTION ) ), 'nothing added' );
	} finally {
		OYS_Settings::update( array( 'currency' => $currency ) );
		update_option( OYS_Products::OPTION, $products );
	}
} );

test( 'calendar and weekly classes keep donation and pay-at-the-studio settings', function () {
	wp_set_current_user( 1 );
	// nine days ahead: clear of the sessions other tests create in the next few days (on a Sunday night one landed at 04:52
	// two days ahead and overlapped)
	$date = wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS );
	$req  = new WP_REST_Request( 'POST', '/oys/v1/admin/sessions' );
	// 05:00: clear of the sample timetable (Thursday and Saturday 07:30), whatever day the tests run on.
	$req->set_body_params( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'date' => $date, 'start' => '05:00', 'duration' => 60, 'capacity' => 10, 'price' => 1000, 'pricing' => 'donation', 'pay_later' => 0, 'repeat' => 'weekly' ) );
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
	OYS_Bookings::book_manual( $u, $s, 'comp', false, true, 'online' );
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
	$req->set_body_params( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'date' => wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS ), 'start' => '06:30', 'duration' => 60, 'capacity' => 10, 'price' => 2500, 'min_people' => 4, 'decide_hours' => 12, 'repeat' => 'weekly' ) );
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

test( 'languages: addresses, translated texts and the switch back', function () {
	$home = untrailingslashit( get_option( 'home' ) );
	eq( $home . '/hu/about/', OYS_I18n::prefix( $home . '/about/', 'hu' ), 'Hungarian address' );
	eq( $home . '/about/', OYS_I18n::prefix( $home . '/es/about/', 'en' ), 'English has no prefix' );
	eq( 'es', OYS_I18n::lang_from_url( $home . '/es/book/?x=1' ), 'language read from an address' );
	eq( 'Óra foglalása', OYS_I18n::with( 'hu', fn() => __( 'Book a class', 'olivia-studio' ) ), 'plugin text in Hungarian' );
	eq( 'Reservar clase', OYS_I18n::with( 'es', fn() => __( 'Book a class', 'olivia-yoga' ) ), 'theme text in Spanish' );
	ok( str_contains( OYS_I18n::with( 'hu', fn() => home_url( '/about/' ) ), '/hu/about/' ), 'links inside Hungarian pages stay Hungarian' );
	ok( ! str_contains( OYS_I18n::with( 'hu', fn() => rest_url( 'oys/v1/' ) ), '/hu/' ), 'the API address has no language' );
	$parsed = OYS_I18n::with(
		'hu',
		function () {
			OYS_I18n::parsing_starts( true );
			$during = home_url( '/' );
			OYS_I18n::parsing_ends();
			return array( $during, home_url( '/' ) );
		}
	);
	ok( ! str_contains( $parsed[0], '/hu/' ), 'while WordPress parses the request the home address has no language (else /hu/ pages are 404 in a subdirectory install)' );
	ok( str_contains( $parsed[1], '/hu/' ), 'after parsing, links get the language again' );
	ok( str_contains( OYS_I18n::with( 'hu', fn() => OYS_I18n::parsing_starts( false ) ?: home_url( '/' ) ), '/hu/' ), 'a request WordPress does not parse leaves links alone' );
	eq( 'Book a class', __( 'Book a class', 'olivia-studio' ), 'back to English afterwards' );
	eq( 'en', oys_valid_lang( 'xx' ), 'unknown class language falls back to English' );
	$s = make_session();
	OYS_Schedule::save( array( 'lang' => 'hu' ), $s->id );
	eq( 'hu', OYS_Schedule::get( $s->id )->lang, 'a class remembers the language it is taught in' );
} );

test( 'languages: emails in the customer\'s language, texts edited per language', function () {
	$u = make_user( 'Kata' );
	update_user_meta( $u, 'oys_lang', 'hu' );
	$mails = sent_mails( fn() => OYS_Emails::welcome( $u ) );
	ok( $mails && str_contains( $mails[0]['subject'], 'Köszönöm a regisztrációt' ), 'welcome email in Hungarian' );
	ok( $mails && str_contains( $mails[0]['html'], 'Szia Kata' ), 'Hungarian greeting' );
	update_user_meta( $u, 'oys_lang', 'es' );
	$mails = sent_mails( fn() => OYS_Emails::welcome( $u ) );
	ok( $mails && str_contains( $mails[0]['subject'], 'Bienvenida' ), 'welcome email in Spanish' );
	eq( 'Foglalva: {class}, {date_short}', OYS_Email_Templates::get( 'booking_confirmed', 'hu' )['subject'], 'translated default subject' );
	OYS_Email_Templates::save( 'booking_confirmed', array( 'subject' => 'Saját tárgy {class}' ), 'hu' );
	eq( 'Saját tárgy {class}', OYS_Email_Templates::get( 'booking_confirmed', 'hu' )['subject'], 'Hungarian text edited' );
	eq( 'Booked: {class}, {date_short}', OYS_Email_Templates::get( 'booking_confirmed', 'en' )['subject'], 'English untouched' );
	ok( OYS_Email_Templates::get( 'booking_confirmed', 'hu' )['customized'] && ! OYS_Email_Templates::get( 'booking_confirmed', 'en' )['customized'], 'edited flag per language' );
	OYS_Email_Templates::save( 'booking_confirmed', array( 'enabled' => 0 ), 'hu' );
	ok( ! OYS_Email_Templates::get( 'booking_confirmed', 'en' )['enabled'], 'on/off is shared by all languages' );
	OYS_Email_Templates::reset( 'booking_confirmed', 'hu' );
	eq( 'Foglalva: {class}, {date_short}', OYS_Email_Templates::get( 'booking_confirmed', 'hu' )['subject'], 'reset to the translated original' );
	$new = make_user( 'Ana' );
	delete_user_meta( $new, 'oys_lang' );
	eq( 'en', OYS_I18n::lang_for( $new ), 'no language yet: the page language (English here)' );
} );

test( 'schedule: two classes cannot overlap', function () {
	$day   = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+13 days' );
	$start = oys_local_to_utc( $day->format( 'Y-m-d' ) . ' 10:00' );
	$s     = OYS_Schedule::get( OYS_Schedule::save( array( 'kind' => 'group', 'class_slug' => 'hatha-flow', 'starts_at' => $start, 'ends_at' => gmdate( 'Y-m-d H:i:s', oys_ts( $start ) + 3600 ), 'capacity' => 10, 'price_cents' => 2500, 'status' => 'scheduled' ) ) );
	$t     = oys_ts( $s->starts_at );
	ok( OYS_Schedule::overlap( gmdate( 'Y-m-d H:i:s', $t + 1800 ), gmdate( 'Y-m-d H:i:s', $t + 5400 ) ), 'half an hour later clashes' );
	ok( ! OYS_Schedule::overlap( gmdate( 'Y-m-d H:i:s', $t + 3600 ), gmdate( 'Y-m-d H:i:s', $t + 7200 ) ), 'right after it is fine' );
	ok( ! OYS_Schedule::overlap( $s->starts_at, $s->ends_at, 0, $s->id ), 'a class does not clash with itself' );
	$res = OYS_Calendar::rest_save( cal_req( array( 'kind' => 'group', 'class_slug' => 'slow-flow', 'date' => $day->format( 'Y-m-d' ), 'start' => '10:30', 'duration' => 60, 'capacity' => 10, 'format' => 'studio', 'price' => 2500 ) ) );
	ok( is_wp_error( $res ) && 409 === ( $res->get_error_data()['status'] ?? 0 ), 'the calendar refuses an overlapping class' );
	$tpl = OYS_Schedule::save_template( array( 'class_slug' => 'hatha-flow', 'weekday' => 7, 'start_time' => '05:00', 'duration_min' => 60, 'active' => 1 ) );
	ok( OYS_Schedule::template_overlap( 7, '05:30', 60 ), 'weekly classes clash too' );
	ok( ! OYS_Schedule::template_overlap( 7, '06:00', 60 ), 'back to back is fine' );
	ok( ! OYS_Schedule::template_overlap( 7, '05:00', 60, 0, $tpl ), 'a weekly class does not clash with itself' );
} );

test( 'content: three languages, no em dashes, past events and journal', function () {
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content.json' ), true );
	ok( ! str_contains( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), '—' ), 'no em dash anywhere in the copy' );
	foreach ( array( 'pages', 'classes', 'posts' ) as $kind ) {
		foreach ( $data[ $kind ] as $slug => $item ) {
			ok( ! empty( $item['hu']['title'] ) && ! empty( $item['es']['title'] ), "$kind/$slug has Hungarian and Spanish titles" );
		}
	}
	eq( 27, count( $data['moments'] ), 'past events' );
	$about = get_page_by_path( 'about' );
	if ( $about ) {
		eq( 'Rólam', OYS_I18n::with( 'hu', fn() => get_the_title( $about ) ), 'page title in Hungarian' );
		ok( str_contains( OYS_I18n::with( 'es', fn() => oy_post_content( $about ) ), 'clases grupales semanales' ), 'Spanish copy' );
	}
	$moments = oy_moments( 3 );
	ok( count( $moments ) === 3 && has_post_thumbnail( $moments[0] ), 'past events come with photos' );
	ok( str_contains( oy_localize_links( '<a href="' . home_url( '/contact/' ) . '">x</a>' ), '/contact/' ), 'links unchanged in English' );
	$hu = OYS_I18n::with( 'hu', fn() => oy_localize_links( '<a href="' . untrailingslashit( get_option( 'home' ) ) . '/contact/?topic=prenatal">x</a><img src="' . get_template_directory_uri() . '/assets/img/x.jpg">' ) );
	ok( str_contains( $hu, '/hu/contact/?topic=prenatal' ), 'links in Hungarian copy point to Hungarian pages' );
} );

test( 'one-language site: Hungarian only, no address prefix, forints', function () {
	$home = untrailingslashit( get_option( 'home' ) );
	eq( 'en', OYS_I18n::default_lang(), 'English is the main language by default' );
	eq( array( 'en', 'hu', 'es' ), array_keys( OYS_I18n::languages() ), 'three languages by default' );
	OYS_Settings::update( array( 'languages' => 'hu', 'default_lang' => 'hu', 'currency' => 'huf' ) );
	try {
		eq( array( 'hu' ), array_keys( OYS_I18n::languages() ), 'only Hungarian' );
		eq( 'hu', OYS_I18n::default_lang(), 'Hungarian is the main language' );
		eq( array(), OYS_I18n::others(), 'no language has a prefix' );
		eq( $home . '/about/', OYS_I18n::prefix( $home . '/about/', 'hu' ), 'Hungarian addresses have no prefix' );
		eq( $home . '/', OYS_I18n::prefix( $home . '/', 'hu' ), 'the home address is untouched' );
		eq( 'hu', OYS_I18n::lang_from_url( $home . '/rolam/' ), 'every address is Hungarian' );
		eq( "2\u{00A0}500\u{00A0}Ft", oys_money( 250000 ), 'forints: the sign after the number, a space between thousands' );
		eq( 250000, oys_cents_from_input( "2\u{00A0}500 Ft" ), 'forint input parsed' );
		eq( 'M. j., D G:i', OYS_I18n::with( 'hu', fn() => oys_date_format( 'D M j, g:i a' ) ), 'Hungarian date order and 24-hour clock' );
		eq( 'D M j, g:i a', oys_date_format( 'D M j, g:i a' ), 'English formats untouched' );
		OYS_Settings::update( array( 'online_only' => 1, 'online_price_cents' => 250000 ) );
		$pricing = do_shortcode( '[oys_pricing]' );
		ok( str_contains( $pricing, 'Live online class' ) && ! str_contains( $pricing, 'Teams &amp; companies' ), 'online only: the price list starts with the live online class, no teams card' );
		ok( str_contains( $pricing, "2\u{00A0}500\u{00A0}Ft" ), 'online drop-in price in forints' );
		// Olivia, 11.13: no language anywhere on a one-language site – the timetable has no „HU” chip
		$one = make_session( array( 'format' => 'online', 'online_capacity' => 10 ) );
		$tt  = do_shortcode( '[oys_schedule days="7"]' );
		ok( str_contains( $tt, 'session--online' ) && ! str_contains( $tt, 'session__lang' ), 'one language: no language chip on the timetable' );
		OYS_Settings::update( array( 'languages' => 'hu,en' ) );
		ok( str_contains( do_shortcode( '[oys_schedule days="7"]' ), 'session__lang' ), 'two languages: the chip is back' );
		$GLOBALS['wpdb']->delete( OYS_Install::table( 'sessions' ), array( 'id' => $one->id ) );
	} finally {
		OYS_Settings::update( array( 'languages' => 'en,hu,es', 'default_lang' => 'en', 'currency' => 'usd', 'online_only' => 0, 'online_price_cents' => 600 ) );
	}
	eq( 'en', OYS_I18n::default_lang(), 'back to English' );
} );

test( 'online edition: Hungarian addresses and its own copy', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	eq( 'fort-myers', oy_edition(), 'the Fort Myers site is the default edition' );
	eq( 'about', oy_page_slug( 'about' ), 'Fort Myers addresses unchanged' );
	update_option( 'oy_edition', 'hu-online' );
	try {
		eq( 'rolam', oy_page_slug( 'about' ), 'online edition: Hungarian address' );
		eq( 'schedule-pricing', oy_page_key( 'bejelentkezes-es-arak' ), 'and back to the theme\'s own slug' );
		eq( 'Olívia Jóga – Online', oy_site_name(), 'site name: Olívia with a long í (Olivia, 11.13)' );
		ok( '' === oy_areas_list(), 'no Fort Myers areas' );
		ok( str_starts_with( oy_alt( 'Olivia Kovacs in dancer pose in a living room' ), 'Kovács Olívia' ), 'photo alt texts: Hungarian name order' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	$data = json_decode( (string) file_get_contents( oy_content_file( 'hu-online' ) ), true );
	$text = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
	ok( is_array( $data ) && ! str_contains( $text, '—' ), 'online copy: valid, no em dash' );
	// the seller is Arovia Group LLC in Fort Myers, Florida (Áron, 11.13): its address belongs in the legal pages and the privacy notice
	// (the descriptions of Olívia's own photos say what is in them: a sand dune in Hungary is sand)
	$copy = wp_json_encode( array_diff_key( $data, array_flip( array( 'legal', 'privacy', 'uploads' ) ) ), JSON_UNESCAPED_UNICODE );
	ok( ! preg_match( '/Fort Myers|Florid|lanai|tenger|strand|homok|pickleball|tenisz|golf|klubház/iu', $copy ), 'online copy: written for Hungary (no Fort Myers, sea, beach, tennis, golf …)' );
	ok( str_contains( wp_json_encode( $data['legal'], JSON_UNESCAPED_UNICODE ), 'Arovia Group LLC' ) && str_contains( $data['privacy']['content'], 'Arovia Group LLC' ), 'the seller and controller is Arovia' );
	// Áron, 2026-11-14: „Nincs áfa. Mehet minden bele.” – the prices are final, the Arovia's EIN in the ÁSZF and Impresszum
	$legal = array_column( $data['legal'], 'content', 'slug' );
	ok( str_contains( $legal['aszf'], 'semmilyen adót vagy díjat nem számítunk fel' ) && ! preg_match( '/áfa/iu', $legal['aszf'] ), 'ÁSZF: the prices are final, no VAT line' );
	ok( str_contains( $legal['aszf'], 'EIN): 36-5185016' ) && str_contains( $legal['impresszum'], 'EIN): 36-5185016' ), 'the Arovia\'s EIN in the ÁSZF and the Impresszum' );
	ok( ! str_contains( $text, 'Kovacs' ), 'online copy: her name in Hungarian (Kovács Olívia)' );
	ok( ! preg_match( '/Olivi[aá]/u', $text ), 'online copy: Olívia with a long í everywhere (Olivia, 11.13)' );
	// Olivia, 11.13 (20:17): the prenatal modules wherever the prenatal class is mentioned, and why each one matters
	$mods = array( 'légzőtorna', 'gátizomtorna', 'keringésjavítás', 'kardiótréning', 'izomerősítés', 'gerinctréning', 'mobilizáció', 'jóga', 'relaxáció' );
	$faq  = implode( ' ', array_column( array_filter( $data['faqs'], fn( $f ) => str_starts_with( $f['q'], 'Várandós' ) ), 'a' ) );
	foreach ( array( 'excerpt' => $data['classes']['kismamatorna']['excerpt'], 'classes page' => $data['pages']['classes']['content'], 'FAQ' => $faq ) as $where => $html ) {
		ok( count( array_filter( $mods, fn( $m ) => str_contains( mb_strtolower( $html ), $m ) ) ) === count( $mods ), "prenatal modules listed: $where" );
	}
	$pre = $data['classes']['kismamatorna']['content'];
	$why = preg_match( '~<ul class="oj-modules oj-modules--why">(.*?)</ul>~s', $pre, $mm ) ? $mm[1] : '';
	ok( 9 === substr_count( $why, '<li><strong>' ) && 9 === substr_count( $why, '<span>' ) && ! str_contains( $pre, 'kettőt húzunk' ), 'prenatal page: nine modules, each with why, combined (not two per class)' );
	ok( str_contains( $pre, 'sportmúlt' ) && str_contains( $pre, 'felmérés' ), 'prenatal page: the sports background and the first session as an assessment' );
	ok( str_contains( $data['pages']['home']['content'], 'jógikus életvitellel' ), 'home intro: the yogic-life sentence as on oliviajoga.hu' );
	ok( empty( array_diff( array_keys( $data['pages'] ), array_keys( OY_PAGE_SLUGS ) ) ), 'online pages are theme pages' );
	foreach ( $data['sessions'] as $s ) {
		ok( isset( $data['classes'][ $s['class'] ] ) && 'online' === $s['format'], 'timetable: ' . $s['class'] . ' is an online class of this edition' );
	}
	preg_match_all( '/\{\{(page|class):([a-z0-9\-]+)\}\}/', $text, $m, PREG_SET_ORDER );
	foreach ( $m as $token ) {
		ok( isset( $data[ 'page' === $token[1] ? 'pages' : 'classes' ][ $token[2] ] ), 'link target exists in this edition: ' . $token[0] );
	}
	$kinds = array( 'online_pack', 'membership', 'private_single', 'private_pack', 'prenatal_single', 'prenatal_pack', 'prenatal_duo_pack' ); // prenatal_*: 1.5.0, read as private_* of the prenatal service
	foreach ( $data['setup']['products'] as $id => $p ) {
		ok( in_array( $p['kind'], $kinds, true ) && 0 === $p['price_cents'] % 100, "product $id: online kind, whole forints" );
	}
	eq( 'hu', $data['setup']['studio']['default_lang'], 'the edition switches the site to Hungarian' );
	// Olivia's doc of 12 November: one-to-one services, no slow flow, no Zoom, „Jógáról”
	foreach ( $data['setup']['services'] as $key => $svc ) {
		ok( isset( $data['classes'][ $svc['class'] ] ), "service $key has its class page" );
		ok( str_contains( $data['classes'][ $svc['class'] ]['content'], '{{private:' . $key . '}}' ), "service $key: the class page asks for a time for it" );
	}
	foreach ( $data['setup']['products'] as $id => $p ) {
		ok( empty( $p['service'] ) || isset( $data['setup']['services'][ $p['service'] ] ), "product $id: its service exists" );
	}
	ok( ! preg_match( '/slow flow|slow-flow|zoom/iu', wp_json_encode( array_diff_key( $data, array( '_note' => 1 ) ), JSON_UNESCAPED_UNICODE ) ), 'online copy: no slow flow, no Zoom' );
	eq( 'Jógáról', $data['menu']['journal'], 'the journal is „Jógáról”' );
	// first day live (2026-11-12): Hungarian post addresses, the old ones redirect; a privacy notice
	foreach ( array_keys( $data['posts'] ) as $slug ) {
		ok( ! preg_match( '/^(eight-limbs|hatha-class-elements|the-|what-|which-|yoga-|you-|music-|stop-|world-|do-you|meditation$)/', $slug ), "post address in Hungarian: $slug" );
	}
	eq( array_values( OY_ONLINE_POST_SLUGS ), array_keys( $data['posts'] ), 'every old post address has its new one, in order' );
	ok( ! empty( $data['privacy']['content'] ) && str_contains( $data['privacy']['content'], '{email}' ) && 'adatvedelem' === $data['privacy']['slug'], 'a privacy notice, with the contact address' );
	ok( ! preg_match( '/\b(Kft|Bt|adószám|cégjegyzék)\b/u', $data['privacy']['content'] ), 'the notice invents no company data' );
	eq( '2026-12-16', $data['sessions'][0]['valid_from'], 'the Wednesday class starts on 16 December' );
	eq( array( '2026-12-23' ), $data['sessions'][0]['skip'], 'no class on 23 December' );
	preg_match_all( '/\{\{private:([a-z0-9\-]+)\}\}/', $text, $m );
	foreach ( array_unique( $m[1] ) as $topic ) {
		ok( isset( $data['setup']['services'][ $topic ] ), "request link for an existing service: $topic" );
	}
	ok( str_contains( oy_resolve_tokens( '{{private:egyeni-joga}}' ), 'topic=egyeni-joga' ), 'service keys with a hyphen resolve' );
} );

test( 'online edition, first day live: English post addresses become Hungarian, WordPress samples go, a privacy page', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	$made = array();
	try {
		$old = get_page_by_path( 'meditation', OBJECT, 'post' ); // the imported journal of the test site: the old addresses
		$old = $old ? $old->ID : ( $made[] = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Meditáció', 'post_name' => 'meditation' ) ) );
		$made[] = $sample = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ez egy minta oldal', 'post_name' => 'ez-egy-minta-oldal' ) );
		$made[] = $draft = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Adatvédelmi irányelvek', 'post_name' => 'adatvedelmi-iranyelvek' ) );
		update_option( 'wp_page_for_privacy_policy', $draft );
		$log = oy_online_post_slugs();
		eq( 'meditacio', get_post( $old )->post_name, 'the old post keeps its id, gets the Hungarian address' );
		ok( $log && false === get_page_by_path( 'meditation', OBJECT, 'post' ) instanceof WP_Post, 'and not twice' );
		oy_remove_wp_samples();
		ok( null === get_post( $sample ), 'the Hungarian sample page is gone' );
		$data = json_decode( (string) file_get_contents( oy_content_file( 'hu-online' ) ), true );
		oy_import_privacy( $data );
		$pp = get_post( $draft );
		ok( 'publish' === $pp->post_status && 'adatvedelem' === $pp->post_name && (int) get_option( 'wp_page_for_privacy_policy' ) === $draft, "WordPress's draft policy page becomes ours, published" );
		ok( str_contains( $pp->post_content, 'mailto:' . OY_EMAIL ) && ! str_contains( $pp->post_content, '{' ), 'with the real contact address, no placeholders' );
		ok( str_contains( get_privacy_policy_url(), '/adatvedelem/' ), 'the footer link works' );
		wp_update_post( array( 'ID' => $draft, 'post_content' => 'Olivia edited it' ) );
		oy_import_privacy( $data );
		eq( 'Olivia edited it', get_post( $draft )->post_content, 'an edited notice is kept on the next setup' );
	} finally {
		foreach ( OY_ONLINE_POST_SLUGS as $en => $hu ) { // the test site's own (Fort Myers) posts get their addresses back
			$p = get_page_by_path( $hu, OBJECT, 'post' );
			if ( $p ) {
				wp_update_post( array( 'ID' => $p->ID, 'post_name' => $en ) );
			}
		}
		foreach ( $made as $id ) {
			wp_delete_post( $id, true );
		}
		delete_option( 'wp_page_for_privacy_policy' );
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition, live update: the legal pages again, and „Olívia” in the booking texts (14 Nov)', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	$waiver = OYS_Settings::get( 'waiver_text' );
	update_option( 'oy_edition', 'hu-online' );
	try {
		OYS_Settings::update( array( 'waiver_text' => 'Óra előtt szólok Oliviának. Írj: olivia.kovacs6@gmail.com' ) );
		delete_option( 'oy_legal_rev' );
		oy_migrate_legal();
		eq( 'Óra előtt szólok Olíviának. Írj: olivia.kovacs6@gmail.com', OYS_Settings::get( 'waiver_text' ), 'her name with a long í, the address untouched' );
		$aszf = get_page_by_path( 'aszf' );
		ok( $aszf && str_contains( $aszf->post_content, 'EIN): 36-5185016' ) && str_contains( $aszf->post_content, 'semmilyen adót' ), 'the ÁSZF with the EIN and the final prices' );
		eq( array(), oy_migrate_legal(), 'once per revision' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
		OYS_Settings::update( array( 'waiver_text' => $waiver ) );
	}
} );

test( 'online edition, live update: the prices of 14 November, only the „Árak” list of a class page changes', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$id = wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Stretching', 'post_name' => 'stretching', 'post_content' => "<p>Olívia saját szövege.</p>\n<h2>Árak</h2>\n<ul>\n<li><strong>60 perc:</strong> régi ár, 6 000 Ft</li>\n<li>A bérlet 6 hétig érvényes.</li>\n</ul>\n<p>Utána is az övé.</p>" ) );
		delete_option( 'oy_prices_rev' );
		oy_migrate_prices();
		// since 3.11.35 a class page's prices are cards from the booking products („legyenek szebben kategorizálva az árak”)
		$c = oy_price_section( get_post( $id )->post_content, 'stretching' );
		ok( str_contains( $c, '<h2>Árak</h2>' . "\n" . '[oy_prices service="stretching"]' ) && ! str_contains( $c, 'régi ár' ) && str_contains( $c, '<p class="oj-price-note">A bérlet 6 hétig érvényes.</p>' ), 'the prices on the class page: the cards from the booking products, her note under them' );
		$cards = oy_price_cards( 'stretching' );
		ok( 1 === substr_count( $cards, 'class="oj-price oj-price--solo"' ) && 1 === substr_count( $cards, 'class="oj-price oj-price--pair"' ) && 2 === substr_count( $cards, '<dt>' . esc_html__( '1 session', 'olivia-yoga' ) . '</dt>' ), '… alone and as a pair, one session and the pass in each' );
		ok( 4 === substr_count( oy_price_cards( 'egyeni-joga' ), 'class="oj-price oj-price--' ) && '' === oy_price_cards( 'nincs-ilyen' ), '… one-to-one yoga: 60 and 90 minutes, alone and as a pair; nothing for a service without products' );
		ok( str_contains( $c, 'Olívia saját szövege.' ) && str_contains( $c, 'Utána is az övé.' ), 'the rest of her page is untouched' );
		$p = OYS_Products::get( 'egyeni90-duo-1' );
		ok( $p && 1500000 === (int) $p['price_cents'] && 2 === (int) $p['people'] && 90 === (int) $p['duration_min'], 'pair yoga, 90 minutes: 15 000 Ft' );
		eq( 0, (int) OYS_Products::get( 'kismama-5' )['validity_days'], 'the prenatal pass has no expiry' );
		ok( (bool) OYS_Services::get( 'relax' )['duo'], 'relaxation can be booked as a pair' );
		ok( 2000000 === (int) OYS_Products::get( 'relax-5' )['price_cents'] && 3200000 === (int) OYS_Products::get( 'relax-duo-5' )['price_cents'], 'relaxation pass 20 000 Ft, the pair\'s 32 000 Ft (Olívia, chat)' );
		// (the test site may count in dollars, and without WordPress's Hungarian language pack the thousands are „4,000”:
		// only the digits and the words are checked)
		$plain = fn( $t ) => trim( (string) preg_replace( array( '/(?<=\d)(?:&nbsp;|[\s,.\x{00A0}\x{202F}])(?=\d{3}\b)/u', '/(&nbsp;|[\s\x{00A0}\x{202F}$]|Ft)+/u', '/\s+\)/' ), array( '', ' ', ')' ), $t ) );
		$per   = OYS_I18n::with( 'hu', fn() => array_map( fn( $id ) => $plain( OYS_Frontend::per_session( (array) OYS_Products::get( $id ) ) ), array( 'relax-5', 'egyeni-duo-5', 'relax-1' ) ) );
		eq( 'Egy óra: 4000', $per[0], 'a pass: what one session comes to (Olívia: „milyen összegre jön ki egy óra”)' );
		eq( 'Egy óra: 10400 (fejenként 5200)', $per[1], 'a pass for two: and what it is each' );
		eq( '', $per[2], 'a single session: nothing to work out' );
		eq( array(), oy_migrate_prices(), 'once' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition: a weekly class leaves out a date (23 December)', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	$day  = wp_date( 'N', time() + 9 * DAY_IN_SECONDS );
	// at 05:40: the test site's sample timetable has evening classes, and a weekly date never overlaps another class
	$tpl  = OYS_Schedule::save_template( array( 'class_slug' => 'hatha-flow', 'weekday' => (int) $day, 'start_time' => '05:40', 'duration_min' => 90, 'format' => 'online', 'online_capacity' => 20, 'active' => 1, 'valid_from' => wp_date( 'Y-m-d', time() + 8 * DAY_IN_SECONDS ) ) );
	OYS_Schedule::generate( 3 );
	$dates = fn( $status ) => array_map( fn( $r ) => wp_date( 'Y-m-d', oys_ts( $r->starts_at ) ), array_filter( OYS_Schedule::query( array( 'from' => oys_now(), 'status' => $status ) ), fn( $r ) => (int) $r->template_id === $tpl ) );
	eq( array( wp_date( 'Y-m-d', time() + 9 * DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + 16 * DAY_IN_SECONDS ) ), array_values( $dates( 'scheduled' ) ), 'nothing before the first date' );
	$skip = wp_date( 'Y-m-d', time() + 16 * DAY_IN_SECONDS );
	eq( 1, oy_import_skip_dates( $tpl, array( $skip ) ), 'the date is left out' );
	ok( ! in_array( $skip, $dates( 'scheduled' ), true ) && in_array( $skip, $dates( 'cancelled' ), true ), 'cancelled, not deleted' );
	OYS_Schedule::generate( 4 );
	ok( ! in_array( $skip, $dates( 'scheduled' ), true ), 'and not made again' );
	ok( in_array( wp_date( 'Y-m-d', time() + 23 * DAY_IN_SECONDS ), $dates( 'scheduled' ), true ), 'the week after goes on' );
} );

test( 'hathaflow edition: a neutral demo studio, none of Olivia\'s content', function () {
	update_option( 'oy_edition', 'hathaflow' );
	try {
		ok( oy_is_demo() && ! oy_online_only(), 'the demo edition' );
		eq( 'Hathaflow', oy_site_name(), 'site name' );
		eq( 'hello@example.com', oy_brand( 'email' ), 'a sample address, not Olivia\'s' );
		eq( '', oy_brand( 'instagram' ), 'no social links' );
		ok( '' === oy_areas_list(), 'no Fort Myers areas' );
		ok( ! str_contains( oy_title_line() . oy_brand( 'sub' ) . oy_brand( 'about' ), 'Olivia' ), 'labels: no Olivia' );
		eq( 'about', oy_page_slug( 'about' ), 'English addresses' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	$data = json_decode( (string) file_get_contents( oy_content_file( 'hathaflow' ) ), true );
	$text = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
	ok( is_array( $data ) && ! str_contains( $text, '—' ), 'demo copy: valid, no em dash' );
	ok( ! preg_match( '/Olivia|Olívia|Kovacs|Kovács|Fort Myers|Florid|Samadhi/u', $text ), 'demo copy: no Olivia, no Fort Myers' );
	ok( str_contains( $text, 'demo' ), 'demo copy: says it is a demo' );
	ok( empty( array_diff( array_keys( $data['pages'] ), array_keys( OY_PAGE_SLUGS ) ) ), 'demo pages are theme pages' );
	foreach ( $data['sessions'] as $s ) {
		ok( isset( $data['classes'][ $s['class'] ] ), 'timetable: ' . $s['class'] . ' is a class of this edition' );
	}
	preg_match_all( '/\{\{(page|class):([a-z0-9\-]+)\}\}/', $text, $m, PREG_SET_ORDER );
	foreach ( $m as $token ) {
		ok( isset( $data[ 'page' === $token[1] ? 'pages' : 'classes' ][ $token[2] ] ), 'link target exists in this edition: ' . $token[0] );
	}
	eq( 'en', $data['setup']['studio']['languages'], 'one language: English' );
	eq( 'usd', $data['setup']['studio']['currency'], 'dollars' );
} );

/* ---------- HathaFlow Live (LiveKit) ---------- */

// In-process LiveKit server API (Twirp): remembers what was asked, answers like the server.
$GLOBALS['lk_calls'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== $pre || ! OYS_Live::configured() || ! str_starts_with( $url, OYS_Live::api_base() . '/twirp/' ) ) {
		return $pre;
	}
	$method = substr( $url, strrpos( $url, '/' ) + 1 );
	$body   = json_decode( $args['body'] ?? '{}', true ) ?: array();
	$GLOBALS['lk_calls'][] = array( $method, $body, $args['headers']['Authorization'] ?? '' );
	$json = array();
	if ( 'ListParticipants' === $method ) {
		$json = array( 'participants' => $GLOBALS['lk_participants'] ?? array() );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( (object) $json ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 8, 3 );
function lk_calls( $method ) {
	return array_values( array_filter( $GLOBALS['lk_calls'], fn( $c ) => $c[0] === $method ) );
}
function live_on( array $more = array() ) {
	OYS_Settings::update( array_merge( array( 'livekit_url' => 'wss://olivia.livekit.test', 'livekit_api_key' => 'APItest', 'livekit_api_secret' => 'secret-for-tests', 'live_on' => 1, 'live_record' => 'group', 'live_summary' => 'auto', 'live_intro_minutes' => 10, 'live_chat_days' => 7, 'live_replay_days' => 7, 'live_keep_days' => 30, 'live_open_minutes' => 10, 'live_grid_max' => 25 ), $more ) );
	$GLOBALS['lk_calls'] = array();
}
function live_off() {
	OYS_Settings::update( array( 'livekit_url' => '', 'livekit_api_key' => '', 'livekit_api_secret' => '', 'ai_api_key' => '' ) );
	unset( $GLOBALS['lk_participants'] );
}
/** The claims of a LiveKit token, after checking its signature. */
function lk_claims( $jwt ) {
	[ $h, $p, $s ] = explode( '.', $jwt );
	$sig = rtrim( strtr( base64_encode( hash_hmac( 'sha256', "$h.$p", 'secret-for-tests', true ) ), '+/', '-_' ), '=' );
	return hash_equals( $sig, $s ) ? json_decode( base64_decode( strtr( $p, '-_', '+/' ) ), true ) : null;
}
/** A REST request as the logged-in teacher (the studio). */
function live_owner_req( $method, $path, $params = array(), $body = null ) {
	$req = new WP_REST_Request( $method, '/oys/v1' . $path );
	$req->set_query_params( $params );
	if ( null !== $body ) {
		$req->set_body( $body );
	}
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return array( $res->get_status(), $res->get_data() );
}

test( 'live: online people get their own live page, never Zoom; studio people none', function () {
	live_on();
	OYS_Settings::update( array( 'zoom_account_id' => 'acc', 'zoom_client_id' => 'id', 'zoom_client_secret' => 'secret', 'zoom_auto' => 1 ) );
	try {
		ok( OYS_Live::enabled() && ! OYS_Zoom::enabled(), 'our live classes on, Zoom stays out' );
		$s    = make_session( array( 'format' => 'hybrid', 'online_price' => 600 ) );
		$st   = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false ) );
		$on   = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false, false, 'online' ) );
		$link = OYS_Bookings::join_link( $on, $s );
		eq( '', OYS_Bookings::join_link( $st, $s ), 'studio booking: no link' );
		parse_str( (string) parse_url( $link, PHP_URL_QUERY ), $q );
		eq( (int) $s->id, (int) $q['live'], 'link: the class' );
		eq( (int) $on->id, (int) $q['b'], 'link: the booking' );
		eq( OYS_Live::sign( $s->id, $on->id ), $q['k'], 'link: signed' );
		ok( str_starts_with( $link, oys_page_url( 'account' ) ), 'on the account page' );
		eq( 0, count( $GLOBALS['lk_calls'] ), 'booking calls nobody: the room is made when people join' );
		$typed = make_session( array( 'format' => 'online', 'online_url' => 'https://meet.example.test/x' ) );
		$tb    = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $typed, 'comp', false ) );
		eq( 'https://meet.example.test/x', OYS_Bookings::join_link( $tb, $typed ), 'a link typed in by hand is kept' );
		ok( ! OYS_Live::who( $s, $st->id, OYS_Live::sign( $s->id, $st->id ) ), 'a studio booking cannot open the live room' );
		ok( ! OYS_Live::who( $s, $on->id, 'wrong' ), 'a forged link is refused' );
		$other = make_session( array( 'format' => 'online' ) );
		ok( ! OYS_Live::who( $other, $on->id, OYS_Live::sign( $other->id, $on->id ) ), 'a booking opens only its own class' );
		$who = OYS_Live::who( $s, $on->id, OYS_Live::sign( $s->id, $on->id ) );
		ok( $who && ! $who['owner'] && (int) $who['booking']->id === (int) $on->id, 'own link: participant' );
		OYS_Bookings::cancel( $on->id, array( 'by_studio' => true ) );
		ok( ! OYS_Live::who( $s, $on->id, OYS_Live::sign( $s->id, $on->id ) ), 'cancelled: the link no longer opens the room' );
	} finally {
		OYS_Settings::update( array( 'zoom_account_id' => '', 'zoom_client_id' => '', 'zoom_client_secret' => '' ) );
		live_off();
	}
} );

test( 'live: joining – only the people who booked, ten minutes before; the teacher goes live; tokens signed', function () {
	live_on();
	try {
		$s   = make_session( array( 'format' => 'online', 'in_hours' => 3 ) );
		$b   = OYS_Bookings::get( OYS_Bookings::book_manual( make_user( 'Anna' ), $s, 'comp', false ) );
		$who = OYS_Live::who( $s, $b->id, OYS_Live::sign( $s->id, $b->id ) );
		eq( 'before', OYS_Live::phase( $s ), 'three hours before: not open yet' );
		ok( is_wp_error( OYS_Live::join( $s, $who ) ), 'participants wait until the room opens' );
		$s = OYS_Schedule::get( OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( 12 * MINUTE_IN_SECONDS ), 'ends_at' => oys_utc_plus( 72 * MINUTE_IN_SECONDS ) ), $s->id ) );
		eq( 'before', OYS_Live::phase( $s ), 'twelve minutes before: still closed (it opens ten minutes before)' );
		eq( 'open', OYS_Live::phase( $s, true ), 'the teacher can go live an hour before' );
		$s = OYS_Schedule::get( OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( 8 * MINUTE_IN_SECONDS ), 'ends_at' => oys_utc_plus( 68 * MINUTE_IN_SECONDS ) ), $s->id ) );
		$j = OYS_Live::join( $s, $who );
		ok( ! is_wp_error( $j ) && 'wss://olivia.livekit.test' === $j['url'], 'eight minutes before: in, on the LiveKit server' );
		$c = lk_claims( $j['token'] );
		ok( is_array( $c ), 'the entry token is signed with the secret' );
		eq( 'APItest', $c['iss'], 'token: issued with the API key' );
		eq( 'b' . $b->id, $c['sub'], 'token: the booking is the identity' );
		eq( 'Anna', $c['name'], 'token: her name' );
		eq( OYS_Live::room_name( $s->id ), $c['video']['room'], 'token: only this class\'s room' );
		ok( true === $c['video']['roomJoin'] && empty( $c['video']['roomAdmin'] ), 'token: joins, no admin rights' );
		ok( $c['exp'] >= oys_ts( $s->ends_at ) && $c['exp'] <= oys_ts( $s->ends_at ) + 31 * MINUTE_IN_SECONDS, 'token: valid until half an hour after the class' );
		ok( true === $c['video']['canPublish'] && array( 'camera', 'microphone' ) === $c['video']['canPublishSources'], 'small class: students may turn their camera on' );
		ok( ! $j['owner'] && ! $j['record'] && null === $j['intro'], 'a participant does not record or transcribe' );
		eq( 'student', json_decode( $c['metadata'], true )['role'], 'token: a student' );

		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		wp_set_current_user( $admin );
		$owner = OYS_Live::who( $s );
		ok( $owner && $owner['owner'], 'the studio joins as the teacher' );
		$j = OYS_Live::join( $s, $owner );
		$c = lk_claims( $j['token'] );
		ok( str_starts_with( $c['sub'], 'teacher-' ) && true === $c['video']['canPublish'] && empty( $c['video']['canPublishSources'] ), 'the teacher sends picture, sound (and music) freely' );
		ok( $j['record'] && 10 === $j['intro']['minutes'] && 'en-US' === $j['intro']['lang'], 'the teacher records and writes down the opening words, in the class\'s language' );
		eq( oys_ts( $s->starts_at ) * 1000, $j['intro']['start'], 'from the start of the class' );

		$big = make_session( array( 'format' => 'online', 'capacity' => 40, 'in_hours' => 0.1 ) );
		$bb  = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $big, 'comp', false, true ) );
		wp_set_current_user( 0 );
		$c = lk_claims( OYS_Live::join( $big, OYS_Live::who( $big, $bb->id, OYS_Live::sign( $big->id, $bb->id ) ) )['token'] );
		ok( false === $c['video']['canPublish'] && true === $c['video']['canSubscribe'], 'big class: only the teacher is on screen' );
		wp_set_current_user( $admin );
		$p  = make_session( array( 'kind' => 'private', 'format' => 'online', 'in_hours' => 0.1 ) );
		$jp = OYS_Live::join( $p, OYS_Live::who( $p ) );
		ok( ! $jp['record'] && null === $jp['intro'] && $jp['twoWay'], 'private session: two-way, not recorded, no summary (by default)' );
		live_on( array( 'live_record' => 'none' ) );
		ok( ! OYS_Live::join( $s, $owner )['record'], 'recording can be switched off' );
		wp_set_current_user( 0 );

		OYS_Schedule::cancel_session( $s->id );
		ok( 1 === count( lk_calls( 'DeleteRoom' ) ) && OYS_Live::room_name( $s->id ) === lk_calls( 'DeleteRoom' )[0][1]['room'], 'cancelled class: the room closes' );
		$auth = lk_claims( substr( lk_calls( 'DeleteRoom' )[0][2], 7 ) );
		ok( ! empty( $auth['video']['roomCreate'] ), 'the server API is called with a signed server token' );
	} finally {
		wp_set_current_user( 0 );
		live_off();
	}
} );

test( 'live: the teacher mutes everyone (not herself)', function () {
	live_on();
	try {
		$s = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		$b = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false, true ) );
		OYS_Live::ensure_row( $s );
		$GLOBALS['lk_participants'] = array(
			array( 'identity' => 'teacher-1', 'tracks' => array( array( 'sid' => 'TR_t', 'type' => 'AUDIO', 'muted' => false ) ) ),
			array( 'identity' => 'b1', 'tracks' => array( array( 'sid' => 'TR_a', 'type' => 'AUDIO', 'muted' => false ), array( 'sid' => 'TR_v', 'type' => 'VIDEO', 'muted' => false ) ) ),
			array( 'identity' => 'b2', 'tracks' => array( array( 'sid' => 'TR_b', 'type' => 'AUDIO', 'muted' => true ) ) ),
		);
		list( $code ) = api( 'POST', '/live/' . $s->id . '/mute-all?b=' . $b->id . '&k=' . OYS_Live::sign( $s->id, $b->id ) );
		eq( 403, $code, 'a participant cannot mute the others' );
		list( $code, $data ) = live_owner_req( 'POST', '/live/' . $s->id . '/mute-all' );
		ok( 200 === $code && 1 === $data['muted'], 'one microphone muted' );
		$m = lk_calls( 'MutePublishedTrack' );
		ok( 1 === count( $m ) && 'b1' === $m[0][1]['identity'] && 'TR_a' === $m[0][1]['track_sid'] && true === $m[0][1]['muted'], 'the open student microphone, not the teacher, not the video' );
	} finally {
		live_off();
	}
} );

test( 'settings page: secrets (LiveKit, Stripe, Zoom) are never printed back into the page', function () {
	if ( ! class_exists( 'OYS_Admin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once OYS_DIR . 'includes/admin/class-admin.php';
	}
	$before = OYS_Settings::all();
	OYS_Settings::update( array( 'livekit_url' => 'wss://test-proj.livekit.cloud', 'livekit_api_key' => 'APIabc123', 'livekit_api_secret' => 'lk-secret-should-not-show', 'stripe_live_secret' => 'sk_live_should_not_show' ) );
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
	ob_start();
	OYS_Admin::page_settings();
	$html = ob_get_clean();
	wp_set_current_user( 0 );
	update_option( OYS_Settings::OPTION, $before );
	ok( str_contains( $html, 'name="s[livekit_api_secret]"' ) && str_contains( $html, 'name="s[livekit_url]"' ) && str_contains( $html, 'name="s[livekit_api_key]"' ), 'the LiveKit fields are on the settings page' );
	ok( ! str_contains( $html, 'lk-secret-should-not-show' ) && ! str_contains( $html, 'sk_live_should_not_show' ), 'the saved secrets are not in the page' );
	ok( str_contains( $html, 'wss://test-proj.livekit.cloud' ), 'the URL (not a secret) is shown' );
	ok( in_array( 'livekit_api_secret', OYS_Admin::SECRETS, true ) && in_array( 'stripe_live_secret', OYS_Admin::SECRETS, true ), 'an empty secret field keeps the saved one' );
} );

test( 'signed links are bound to the site (on a network one teacher\'s link must not open another\'s class)', function () {
	$a = array( OYS_Live::sign( 7, 3 ), OYS_Stripe::order_key( 5 ), OYS_Newsletter::unsubscribe_url( 9 ) );
	$keep = $GLOBALS['blog_id'];
	$GLOBALS['blog_id'] = 2;
	$b = array( OYS_Live::sign( 7, 3 ), OYS_Stripe::order_key( 5 ), OYS_Newsletter::unsubscribe_url( 9 ) );
	$GLOBALS['blog_id'] = $keep;
	ok( $a[0] !== $b[0] && $a[1] !== $b[1] && $a[2] !== $b[2], 'the same ids on another site give another signature' );
	eq( $a[0], OYS_Live::sign( 7, 3 ), 'stable on the same site' );
} );

test( 'live: the test class – open any time, test participants without bookings, never on the timetable or bookable', function () {
	live_on();
	try {
		$s = OYS_Live::open_test();
		ok( OYS_Live::is_test( $s ) && 'open' === OYS_Live::phase( $s ) && 'open' === OYS_Live::phase( $s, true ), 'open right away, for the teacher and the participants' );
		ok( ! in_array( (int) $s->id, array_map( fn( $x ) => (int) $x->id, OYS_Schedule::upcoming_public( 30 ) ), true ), 'not on the public timetable' );
		ok( '' !== OYS_Schedule::closed_reason( $s ), 'not bookable' );
		$links = OYS_Live::test_links();
		eq( OYS_Live::TEST_PEOPLE, count( $links['people'] ), 'a link for each test participant' );
		parse_str( (string) wp_parse_url( $links['people'][2], PHP_URL_QUERY ), $q );
		$who = OYS_Live::who( $s, (int) $q['b'], $q['k'] );
		ok( $who && ! $who['owner'] && str_contains( $who['name'], '2' ), 'the participant link lets test participant 2 in (no booking, no login)' );
		ok( null === OYS_Live::who( $s, (int) $q['b'], 'wrong' ), 'not with a wrong signature' );
		ok( null === OYS_Live::who( make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) ), (int) $q['b'], $q['k'] ), 'and a test link opens no other class' );
		$r = OYS_Live::join( $s, $who );
		ok( ! is_wp_error( $r ) && str_starts_with( $r['me'], 'b' ) && false === $r['owner'], 'the test participant gets a token' );
		ok( OYS_Live::post_message( $s, $who, 'Szia!' ) > 0, 'and can write in the chat' );
		list( $code, $data ) = api( 'GET', '/live/' . $s->id . '/chat?b=' . $q['b'] . '&k=' . $q['k'] );
		ok( 200 === $code && 1 === count( $data['messages'] ) && $data['messages'][0]['mine'], 'sees the message as their own' );
		$again = OYS_Live::open_test();
		eq( (int) $s->id, (int) $again->id, 'opening it again keeps the same class' );
		list( , $data ) = api( 'GET', '/live/' . $s->id . '/chat?b=' . $q['b'] . '&k=' . $q['k'] );
		eq( 0, count( $data['messages'] ), 'and starts afresh (the chat is empty)' );
	} finally {
		live_off();
	}
} );

test( 'live: raise hand – the teacher sees it in her chat poll and lowers it', function () {
	live_on();
	try {
		$s  = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		$b  = OYS_Bookings::get( OYS_Bookings::book_manual( make_user( 'Anna' ), $s, 'comp', false, true ) );
		$q  = '?b=' . $b->id . '&k=' . OYS_Live::sign( $s->id, $b->id );
		list( $code, $data ) = api( 'POST', '/live/' . $s->id . '/hand' . $q, array( 'up' => true ) );
		ok( 200 === $code && array( 'b' . $b->id ) === $data['hands'], 'the student raises a hand' );
		list( , $data ) = api( 'GET', '/live/' . $s->id . '/chat' . $q );
		ok( true === $data['hand'] && null === $data['hands'], 'the student sees only her own hand, not the others' );
		list( , $data ) = live_owner_req( 'GET', '/live/' . $s->id . '/chat' );
		$h = (array) $data['hands'];
		ok( isset( $h[ 'b' . $b->id ] ) && '' !== $h[ 'b' . $b->id ], 'the teacher gets the raised hand with the name' );
		list( $code ) = api( 'POST', '/live/' . $s->id . '/hand?b=' . $b->id . '&k=wrong', array( 'up' => true ) );
		eq( 403, $code, 'not with a wrong link' );
		live_owner_req( 'POST', '/live/' . $s->id . '/hand', array( 'up' => false, 'identity' => 'b' . $b->id ) );
		list( , $data ) = api( 'GET', '/live/' . $s->id . '/chat' . $q );
		ok( false === $data['hand'], 'the teacher lowered it' );
	} finally {
		live_off();
	}
} );

test( 'live: the recording – only the teacher records, pieces in order, the participants watch it, deleted later', function () {
	live_on();
	try {
		$s = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		$b = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false, true ) );
		$k = OYS_Live::sign( $s->id, $b->id );
		list( $code ) = api( 'POST', '/live/' . $s->id . '/rec?b=' . $b->id . '&k=' . $k, array( 'mime' => 'video/webm' ) );
		eq( 403, $code, 'a participant cannot record' );
		list( $code, $data ) = live_owner_req( 'POST', '/live/' . $s->id . '/rec', array( 'mime' => 'video/webm;codecs=vp8,opus' ) );
		ok( 200 === $code && preg_match( '/^[a-z0-9]{12}$/', $data['id'] ), 'the teacher starts a recording' );
		$rid = $data['id'];
		list( $code, $data ) = live_owner_req( 'POST', '/live/' . $s->id . '/rec/' . $rid . '/chunk', array( 'seq' => 0 ), 'AAAA' );
		ok( 200 === $code && 1 === $data['seq'], 'first piece saved' );
		list( $code, $data ) = live_owner_req( 'POST', '/live/' . $s->id . '/rec/' . $rid . '/chunk', array( 'seq' => 0 ), 'AAAA' );
		ok( 200 === $code && 1 === $data['seq'], 'the same piece again (a retry) is not saved twice' );
		list( $code, $data ) = live_owner_req( 'POST', '/live/' . $s->id . '/rec/' . $rid . '/chunk', array( 'seq' => 2 ), 'CCCC' );
		ok( 409 === $code && 1 === $data['data']['seq'], 'a gap is refused, with the piece expected' );
		live_owner_req( 'POST', '/live/' . $s->id . '/rec/' . $rid . '/chunk', array( 'seq' => 1 ), 'BBBB' );
		$row  = OYS_Live::row( $s->id );
		$recs = OYS_Live::recordings( $row );
		eq( 'AAAABBBB', file_get_contents( OYS_Live::rec_dir() . '/' . $recs[0]['file'] ), 'the file is the pieces in order' );
		ok( str_contains( OYS_Live::rec_dir(), '/oys-live-' ) && is_file( OYS_Live::rec_dir() . '/index.php' ), 'kept in a folder nobody can guess, not listed' );
		eq( array(), OYS_Live::watchable( $row ), 'not watchable while recording' );
		live_owner_req( 'POST', '/live/' . $s->id . '/rec/' . $rid . '/stop', array( 'seconds' => 3600 ) );
		$rec = OYS_Live::watchable( OYS_Live::row( $s->id ) );
		ok( 1 === count( $rec ) && 60 === $rec[0]['minutes'] && 'video/webm' === $rec[0]['mime'], 'stopped: watchable, 60 minutes' );
		list( $code ) = live_owner_req( 'POST', '/live/' . $s->id . '/rec/' . $rid . '/chunk', array( 'seq' => 2 ), 'DDDD' );
		eq( 400, $code, 'a closed recording takes no more pieces' );
		$url = OYS_Live::replay_url( $s->id, $rid, OYS_Live::who( $s, $b->id, $k ) );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		ok( (int) $q['oys_replay'] === (int) $s->id && $q['rec'] === $rid && $q['k'] === $k, 'the participant\'s replay address carries their own signed link' );

		// A recording left open (the teacher closed the page) is closed after the class.
		$s2 = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		list( , $d2 ) = live_owner_req( 'POST', '/live/' . $s2->id . '/rec', array( 'mime' => 'video/mp4' ) );
		live_owner_req( 'POST', '/live/' . $s2->id . '/rec/' . $d2['id'] . '/chunk', array( 'seq' => 0 ), 'MP4' );
		OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( -2 * HOUR_IN_SECONDS ), 'ends_at' => oys_utc_plus( -HOUR_IN_SECONDS ) ), $s2->id );
		OYS_Live::process();
		$r2 = OYS_Live::watchable( OYS_Live::row( $s2->id ) );
		ok( 1 === count( $r2 ) && 'video/mp4' === $r2[0]['mime'], 'closed and watchable after the class' );
		eq( 'done', OYS_Live::row( $s2->id )->state, 'processed' );

		// After `live_keep_days` the files go.
		$file = OYS_Live::rec_dir() . '/' . OYS_Live::recordings( OYS_Live::row( $s2->id ) )[0]['file'];
		OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( -40 * DAY_IN_SECONDS ), 'ends_at' => oys_utc_plus( -40 * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ), $s2->id );
		OYS_Live::clean_up();
		ok( ! is_file( $file ) && array() === OYS_Live::recordings( OYS_Live::row( $s2->id ) ), 'deleted after 30 days' );
		wp_delete_file( OYS_Live::rec_dir() . '/' . $recs[0]['file'] );
	} finally {
		live_off();
	}
} );

test( 'live: the class chat stays after class, people remove their own messages', function () {
	live_on();
	try {
		$s  = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		$a  = OYS_Bookings::get( OYS_Bookings::book_manual( make_user( 'Anna' ), $s, 'comp', false, true ) );
		$c  = OYS_Bookings::get( OYS_Bookings::book_manual( make_user( 'Csilla' ), $s, 'comp', false, true ) );
		$ka = OYS_Live::sign( $s->id, $a->id );
		list( $code, $data ) = api( 'POST', '/live/' . $s->id . '/chat?b=' . $a->id . '&k=' . $ka, array( 'body' => "Hi everyone!\nSee you on the mat" ) );
		eq( 200, $code, 'participant writes with their own link' );
		$mid = $data['id'];
		list( $code ) = api( 'POST', '/live/' . $s->id . '/chat?b=' . $a->id . '&k=' . $ka, array( 'body' => 'again' ) );
		eq( 400, $code, 'two seconds between messages' );
		list( $code ) = api( 'POST', '/live/' . $s->id . '/chat?b=' . $a->id . '&k=bad', array( 'body' => 'x' ) );
		eq( 403, $code, 'a forged link cannot write' );
		list( $code, $data ) = api( 'GET', '/live/' . $s->id . '/chat?b=' . $c->id . '&k=' . OYS_Live::sign( $s->id, $c->id ) );
		eq( 200, $code, 'the other participant reads' );
		eq( 1, count( $data['messages'] ), 'one message' );
		eq( "Hi everyone!\nSee you on the mat", $data['messages'][0]['body'], 'line breaks kept' );
		ok( ! $data['messages'][0]['mine'] && ! $data['messages'][0]['can_del'], 'not hers: she cannot remove it' );
		list( $code ) = api( 'DELETE', '/live/' . $s->id . '/chat/' . $mid . '?b=' . $c->id . '&k=' . OYS_Live::sign( $s->id, $c->id ) );
		eq( 403, $code, 'someone else\'s message stays' );

		// After class: still there, people can keep talking for `live_chat_days`.
		$s = OYS_Schedule::get( OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( -3 * DAY_IN_SECONDS ), 'ends_at' => oys_utc_plus( -3 * DAY_IN_SECONDS + HOUR_IN_SECONDS ) ), $s->id ) );
		eq( 'over', OYS_Live::phase( $s ), 'class over' );
		delete_transient( 'oys_live_chat_' . md5( $s->id . '|b' . $c->id ) );
		list( $code ) = api( 'POST', '/live/' . $s->id . '/chat?b=' . $c->id . '&k=' . OYS_Live::sign( $s->id, $c->id ), array( 'body' => 'Thank you, it was lovely' ) );
		eq( 200, $code, 'three days later: still talking' );
		list( , $data ) = api( 'GET', '/live/' . $s->id . '/chat?b=' . $a->id . '&k=' . $ka . '&after=' . $mid );
		eq( 1, count( $data['messages'] ), 'only the new message after the last one seen' );
		list( $code ) = api( 'DELETE', '/live/' . $s->id . '/chat/' . $mid . '?b=' . $a->id . '&k=' . $ka );
		eq( 200, $code, 'she removes her own message' );
		list( , $data ) = api( 'GET', '/live/' . $s->id . '/chat?b=' . $c->id . '&k=' . OYS_Live::sign( $s->id, $c->id ) . '&after=' . $mid );
		ok( in_array( $mid, $data['gone'], true ), 'the others see it go' );
		live_on( array( 'live_chat_days' => 2 ) );
		delete_transient( 'oys_live_chat_' . md5( $s->id . '|b' . $a->id ) );
		list( $code, $data ) = api( 'POST', '/live/' . $s->id . '/chat?b=' . $a->id . '&k=' . $ka, array( 'body' => 'late' ) );
		eq( 400, $code, 'after `live_chat_days` the chat closes' );
		list( $code, $data ) = api( 'GET', '/live/' . $s->id . '/chat?b=' . $a->id . '&k=' . $ka );
		ok( 200 === $code && false === $data['open'] && 1 === count( $data['messages'] ), 'but it can still be read' );
	} finally {
		live_off();
	}
} );


test( 'live: after class – the opening words, written down in the teacher\'s browser, summarised by the AI', function () {
	live_on();
	OYS_Settings::update( array( 'ai_api_key' => 'sk-ant-test', 'ai_model' => 'claude-opus-5', 'languages' => 'hu', 'default_lang' => 'hu' ) );
	$sent = null;
	$fake = function ( $pre, $args, $url ) use ( &$sent ) {
		if ( ! str_starts_with( $url, OYS_AI::api_url() ) ) {
			return $pre;
		}
		$sent  = json_decode( $args['body'], true );
		$reply = array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'summary' => 'Olivia arról beszélt, hogy a légzés vezeti a mozdulatot.' ) ) ) ) );
		return array( 'headers' => array(), 'body' => wp_json_encode( $reply ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $fake, 5, 3 );
	try {
		$s = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		$b = OYS_Bookings::get( OYS_Bookings::book_manual( make_user(), $s, 'comp', false, true ) );
		$k = OYS_Live::sign( $s->id, $b->id );
		list( $code ) = api( 'POST', '/live/' . $s->id . '/transcript?b=' . $b->id . '&k=' . $k, array( 'text' => 'hello' ) );
		eq( 403, $code, 'only the teacher\'s words are written down' );
		live_owner_req( 'POST', '/live/' . $s->id . '/transcript', array( 'text' => 'Sziasztok, ma a légzésre figyelünk.' ) );
		live_owner_req( 'POST', '/live/' . $s->id . '/transcript', array( 'text' => 'A légzés vezeti a mozdulatot.' ) );
		eq( 'Sziasztok, ma a légzésre figyelünk. A légzés vezeti a mozdulatot.', OYS_Live::row( $s->id )->transcript, 'the pieces add up' );
		$s = OYS_Schedule::get( OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( -2 * HOUR_IN_SECONDS ), 'ends_at' => oys_utc_plus( -HOUR_IN_SECONDS ) ), $s->id ) );
		ok( ! OYS_Live::add_transcript( $s, 'late words' ), 'nothing is added after the opening minutes' );
		OYS_Live::process();
		$row = OYS_Live::row( $s->id );
		eq( 'done', $row->state, 'processed' );
		eq( 'Olivia arról beszélt, hogy a légzés vezeti a mozdulatot.', $row->summary, 'summary stored' );
		eq( 'published', $row->summary_status, 'shown right away (auto)' );
		ok( str_contains( $sent['system'], 'Hungarian' ) && str_contains( $sent['system'], 'not quote her word for word' ), 'Hungarian, not word for word' );
		ok( str_contains( $sent['messages'][0]['content'], 'A légzés vezeti a mozdulatot.' ), 'the transcript is sent' );
		eq( 'json_schema', $sent['output_config']['format']['type'], 'structured answer' );

		$_GET = array( 'live' => $s->id, 'b' => $b->id, 'k' => $k );
		$page = OYS_Live::page();
		ok( str_contains( $page, 'a légzés vezeti a mozdulatot' ) && str_contains( $page, 'data-live-messages' ) && ! str_contains( $page, 'data-live-join' ), 'live page after class: summary and chat, the video is gone' );
		$_GET = array( 'live' => $s->id );
		ok( str_contains( OYS_Live::page(), 'oys-auth' ), 'without the link: log in' );

		// Review mode: the teacher reads it first.
		live_on( array( 'live_summary' => 'review' ) );
		$s2 = make_session( array( 'format' => 'online', 'in_hours' => 0.1 ) );
		live_owner_req( 'POST', '/live/' . $s2->id . '/transcript', array( 'text' => 'A légzés vezeti a mozdulatot.' ) );
		OYS_Schedule::save( array( 'starts_at' => oys_utc_plus( -2 * HOUR_IN_SECONDS ), 'ends_at' => oys_utc_plus( -HOUR_IN_SECONDS ) ), $s2->id );
		OYS_Live::process();
		eq( 'draft', OYS_Live::row( $s2->id )->summary_status, 'review: waits for the teacher' );
		$_GET = array( 'live' => $s2->id );
		ok( ! str_contains( OYS_Live::page(), 'légzés vezeti' ), 'review: participants do not see it yet' );
		$_GET = array();
	} finally {
		remove_filter( 'pre_http_request', $fake, 5 );
		OYS_Settings::update( array( 'languages' => 'en,hu,es', 'default_lang' => 'en' ) );
		live_off();
	}
} );

/* ---------- 1.8.1: sending server (SMTP), welcome email, Hungarian dates, shared mobile IPs ---------- */

test( 'emails: the studio\'s own SMTP server, its sender for WordPress\'s emails, and a reply-to address', function () {
	$before = OYS_Settings::all();
	try {
		OYS_Settings::update( array( 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'smtp_user' => 'login@example.test', 'smtp_pass' => 'key-123', 'email_from' => 'info@studio.test', 'email_from_name' => 'Studio', 'email_reply_to' => 'olivia@example.test' ) );
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		$m = new PHPMailer\PHPMailer\PHPMailer();
		OYS_Mailer::smtp( $m );
		eq( 'smtp', $m->Mailer, 'sends through SMTP' );
		ok( 'smtp.example.test' === $m->Host && 587 === (int) $m->Port && 'tls' === $m->SMTPSecure, 'server, port and STARTTLS' );
		ok( $m->SMTPAuth && 'login@example.test' === $m->Username && 'key-123' === $m->Password, 'with the login and key' );
		eq( 'info@studio.test', apply_filters( 'wp_mail_from', 'wordpress@studio.test' ), 'WordPress\'s own emails (password reset) come from the studio' );
		eq( 'someone@else.test', apply_filters( 'wp_mail_from', 'someone@else.test' ), 'another sender is left alone' );
		eq( 'Studio', apply_filters( 'wp_mail_from_name', 'WordPress' ), 'and with the studio\'s name' );
		$sent = array();
		$grab = function ( $args ) use ( &$sent ) {
			$sent[] = $args;
			return $args;
		};
		add_filter( 'wp_mail', $grab );
		OYS_Emails::send( 'anna@example.test', 'Hello', 'Hello', '<p>Hi</p>' );
		remove_filter( 'wp_mail', $grab );
		$h = implode( "\n", (array) ( $sent[0]['headers'] ?? array() ) );
		ok( str_contains( $h, 'From: Studio <info@studio.test>' ) && str_contains( $h, 'Reply-To: Studio <olivia@example.test>' ), 'customer emails: From the studio, answers to the reply-to address' );
		OYS_Settings::update( array( 'smtp_host' => '' ) );
		$m2 = new PHPMailer\PHPMailer\PHPMailer();
		OYS_Mailer::smtp( $m2 );
		eq( 'mail', $m2->Mailer, 'no server set: sends as before' );
		ok( str_contains( OYS_Mailer::describe(), 'spam' ), 'and the admin says what that means' );
	} finally {
		update_option( OYS_Settings::OPTION, $before );
	}
} );

test( 'emails page: the SMTP key is never printed back into the page', function () {
	if ( ! class_exists( 'OYS_Admin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once OYS_DIR . 'includes/admin/class-admin.php';
	}
	$before = OYS_Settings::all();
	OYS_Settings::update( array( 'smtp_host' => 'smtp-relay.brevo.com', 'smtp_user' => 'olivia-login', 'smtp_pass' => 'xsmtpsib-should-not-show' ) );
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
	ob_start();
	OYS_Admin::page_emails();
	$html = ob_get_clean();
	wp_set_current_user( 0 );
	update_option( OYS_Settings::OPTION, $before );
	ok( str_contains( $html, 'name="s[smtp_host]"' ) && str_contains( $html, 'name="s[smtp_pass]"' ) && str_contains( $html, 'name="s[email_reply_to]"' ), 'the SMTP and reply-to fields are on the emails page' );
	ok( ! str_contains( $html, 'xsmtpsib-should-not-show' ), 'the saved key is not in the page' );
	ok( str_contains( $html, 'smtp-relay.brevo.com' ) && str_contains( $html, 'olivia-login' ), 'the server and login are shown' );
	ok( str_contains( $html, 'oys-smtp-test' ), 'with a test button' );
	ok( in_array( 'smtp_pass', OYS_Admin::SECRETS, true ), 'listed as a secret' );
} );

test( 'welcome email: thanks for signing up and lists the next classes to book', function () {
	$u   = make_user( 'Vera' );
	$s   = make_session( array( 'in_hours' => 30 ) );
	$log = sent_mails( fn() => OYS_Emails::welcome( $u ) );
	ok( isset( $log[0] ) && str_contains( $log[0]['html'], 'Next classes' ), 'the next classes are in the email' );
	ok( isset( $log[0] ) && str_contains( $log[0]['html'], esc_url( oys_book_url( $s->id ) ) ), 'with a booking link' );
	OYS_Schedule::save( array( 'status' => 'cancelled' ), $s->id );
	ok( ! str_contains( OYS_Emails::upcoming_html( 50 ), esc_url( oys_book_url( $s->id ) ) ), 'a cancelled class is not offered' );
} );

test( 'join reminder: the "set up where the camera sees your mat" tip is there once', function () {
	$u   = make_user( 'Ida' );
	$s   = make_session( array( 'format' => 'online', 'online_url' => 'https://meet.example/x', 'in_hours' => 0.2 ) );
	$id  = OYS_Bookings::book_manual( $u, $s, 'comp', false, true, 'online' ); // forced: online booking closes shortly before
	$log = sent_mails( fn() => OYS_Emails::join_reminder( $id ) );
	eq( 1, substr_count( $log[0]['html'] ?? '', 'camera sees your mat' ), 'not repeated' );
	ok( str_contains( $log[0]['html'] ?? '', 'https://meet.example/x' ), 'the link is in it' );
} );

test( 'hungarian dates: a full stop after the short month, none before a suffix', function () {
	$hu = fn() => 'hu_HU';
	add_filter( 'locale', $hu );
	try {
		eq( 'M. j.', oys_date_format( 'M j' ), 'nov. 7.' );
		eq( 'Y. M. j.', oys_date_format( 'M j, Y' ), '2026. nov. 7.' );
		eq( '2026. november 20-ig érvényes', oys_hu_suffix( '2026. november 20.-ig érvényes' ), 'until: the date drops its full stop' );
		eq( 'dec. 16-án', oys_hu_suffix( 'dec. 16.-án' ), 'on: too' );
		eq( 'Mindenki 3. a sorban', oys_hu_suffix( 'Mindenki 3. a sorban' ), 'other text stays' );
	} finally {
		remove_filter( 'locale', $hu );
	}
	eq( '20.-ig', oys_hu_suffix( '20.-ig' ), 'not Hungarian: unchanged' );
} );

test( 'rate limiting: many people behind one mobile IP are not locked out together', function () {
	$_SERVER['REMOTE_ADDR'] = '100.64.9.' . wp_rand( 1, 250 );
	for ( $i = 0; $i < OYS_Security::MAX_LOGIN_FAILS + 4; $i++ ) {
		OYS_Security::record_failure( 'typo' . $i . '@example.test' );
	}
	ok( ! OYS_Security::login_locked( 'careful@example.test' ), 'ten mistyped logins from one network: the next person can still log in' );
	for ( $i = 0; $i < OYS_Security::MAX_IP_FAILS; $i++ ) {
		OYS_Security::record_failure( 'bot' . $i . '@example.test' );
	}
	ok( OYS_Security::login_locked( 'careful@example.test' ), 'a real attack from one address is still stopped' );
	ok( OYS_Security::MAX_SIGNUPS >= 10, 'up to ten sign-ups per address in 15 minutes' );
} );

test( 'emails page: only the emails of features in use are listed, all of them with ?all=1', function () {
	if ( ! class_exists( 'OYS_Admin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once OYS_DIR . 'includes/admin/class-admin.php';
	}
	$before   = OYS_Settings::all();
	$products = get_option( OYS_Products::OPTION );
	try {
		update_option( OYS_Products::OPTION, array( 'p5' => array( 'name' => '5 classes', 'kind' => 'online_pack', 'credits' => 5, 'price_cents' => 1300000, 'active' => 1, 'giftable' => 1 ) ) );
		OYS_Settings::update( array( 'birthday_on' => 0, 'raffle_period' => 'off', 'max_guests' => 0 ) );
		ok( ! OYS_Email_Templates::in_use( 'membership_started' ) && ! OYS_Email_Templates::in_use( 'birthday' ) && ! OYS_Email_Templates::in_use( 'raffle_winner' ) && ! OYS_Email_Templates::in_use( 'guest_invite' ), 'no memberships, birthdays, draws or guests: their emails are not in use' );
		ok( OYS_Email_Templates::in_use( 'booking_confirmed' ) && OYS_Email_Templates::in_use( 'pass_purchased' ) && OYS_Email_Templates::in_use( 'gift_card' ) && OYS_Email_Templates::in_use( 'join_reminder' ), 'bookings, passes, gifts, the join link: in use' );
		wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
		ob_start();
		OYS_Admin::page_emails();
		$html = ob_get_clean();
		$_GET['all'] = 1;
		ob_start();
		OYS_Admin::page_emails();
		$all = ob_get_clean();
		unset( $_GET['all'] );
		wp_set_current_user( 0 );
		ok( ! str_contains( $html, 'edit=membership_started' ) && str_contains( $html, 'edit=booking_confirmed' ), 'the page lists only those in use' );
		ok( str_contains( $html, 'all=1' ), 'with a link to show all' );
		ok( str_contains( $all, 'edit=membership_started' ), 'show all: every email is there' );
	} finally {
		update_option( OYS_Settings::OPTION, $before );
		update_option( OYS_Products::OPTION, $products );
	}
} );

test( 'admin in Hungarian: the emails page has no English left', function () {
	if ( ! class_exists( 'OYS_Admin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once OYS_DIR . 'includes/admin/class-admin.php';
	}
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
	$html = OYS_I18n::with( 'hu', function () {
		ob_start();
		OYS_Admin::page_emails();
		return ob_get_clean();
	} );
	wp_set_current_user( 0 );
	foreach ( array( 'Sent when', 'Edit text', 'Booking confirmation', 'Customer', 'Online: link to join', 'days before unused classes expire' ) as $en ) {
		ok( ! str_contains( $html, '>' . $en ), 'not in English: ' . $en );
	}
	ok( str_contains( $html, 'Mikor megy ki' ) && str_contains( $html, 'Szöveg szerkesztése' ), 'Hungarian column and button' );
} );

/** A small real JPEG in the media library (for the page editor test). */
function self_test_image() {
	$up   = wp_upload_dir();
	$file = $up['path'] . '/oye-test.jpg';
	$im   = imagecreatetruecolor( 40, 30 );
	imagejpeg( $im, $file );
	imagedestroy( $im );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'oye-test', 'post_status' => 'inherit' ), $file );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	return $id;
}

test( 'page editor: saved text and photo show to everyone, links, booking and prices stay as they are', function () {
	if ( ! function_exists( 'oye_pass' ) ) {
		require_once dirname( __DIR__, 2 ) . '/olivia-yoga/inc/page-editor.php';
	}
	delete_option( 'oye_edits' );
	$html = '<section><p class="hero__lead">Élő online jóga <b>minden</b> szerdán.</p><a class="way" href="/x"><h3>Csoportos jóga</h3><p>Szerdánként.</p></a>'
		. '<p><a class="btn" href="/book">Óra foglalása</a></p><div class="oys-schedule"><p>Szerda 18:30</p></div><h1><span class="hero__l1">Online</span> <mark>jóga</mark></h1>'
		. '<div class="frame"><img class="photo" src="/wp-content/themes/x/assets/img/online-yoga-living-room-1200.jpg" srcset="/a-600.jpg 600w" data-oyp="online-yoga-living-room" alt=""></div></section>';
	eq( $html, oye_pass( $html ), 'nothing saved: the page is untouched' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin );
	$img = self_test_image();
	$res = oye_apply(
		array(
			oye_text_key( 'Élő online jóga <b>minden</b> szerdán.' ) => array( 'v' => 'Új szöveg <script>x</script><em>itt</em>', 'def' => 'Élő online jóga <b>minden</b> szerdán.' ),
			oye_text_key( 'Csoportos jóga' ) => array( 'v' => 'Csoportos online jóga' ),
			oye_text_key( 'jóga' ) => array( 'v' => 'JÓGA!' ),
			oye_text_key( 'Óra foglalása' ) => array( 'v' => 'nem írható' ),
			oye_text_key( 'Szerda 18:30' ) => array( 'v' => 'nem írható' ),
		),
		array( 'i:online-yoga-living-room' => array( 'id' => $img, 'fx' => 30, 'fy' => 20 ) )
	);
	wp_set_current_user( 0 );
	ok( $res['ok'], 'saved' );
	$out = oye_pass( $html );
	ok( str_contains( $out, '<p class="hero__lead">Új szöveg x<em>itt</em></p>' ), 'the text, only with simple formatting (no script)' );
	ok( str_contains( $out, '<h3>Csoportos online jóga</h3>' ), 'a title inside a card link is text' );
	ok( str_contains( $out, '<mark>JÓGA!</mark>' ), 'a part of the big title' );
	ok( str_contains( $out, '>Óra foglalása</a>' ) && str_contains( $out, '<p>Szerda 18:30</p>' ), 'button labels and the booking plugin are not edited' );
	$copy = (int) get_post_meta( $img, 'oye_web_copy', true );
	ok( $copy && str_contains( $out, wp_get_attachment_image_url( $copy, 'large' ) ) && ! str_contains( $out, 'srcset' ) && str_contains( $out, 'object-position:30% 20%' ), 'the photo: a web copy of the new one, with its crop point' );
	$page = '<html><body><header><p>Fejléc</p></header><main id="main">' . $html . '</main><footer>x</footer></body></html>';
	$full = oye_filter_page( $page );
	ok( str_contains( $full, '<header><p>Fejléc</p></header>' ) && str_contains( $full, 'Csoportos online jóga' ), 'only <main> goes through the editor' );
	delete_option( 'oye_edits' );
	delete_option( 'oye_edits_meta' );
	delete_option( 'oye_edits_prev' );
} );

/** A real JPEG of the given size in the uploads folder (a sub-folder like „2026/11” if given), as an attachment. */
function self_test_photo( $name, $w, $h, $sub = '', $alt = '' ) {
	$up  = wp_upload_dir();
	$dir = '' === $sub ? $up['path'] : $up['basedir'] . '/' . $sub;
	wp_mkdir_p( $dir );
	$file = $dir . '/' . $name;
	$im   = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $im, 0, 0, (int) ( $w / 2 ), $h, imagecolorallocate( $im, 200, 120, 160 ) );
	imagejpeg( $im, $file, 90 );
	imagedestroy( $im );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_status' => 'inherit' ), $file );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return $id;
}

test( 'photos on the online edition: Hungarian names and texts with the keywords (Olívia, 14 November)', function () {
	$imgs = oy_images();
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true );
	$used = array( 'olivia-golden-backbend', 'olivia-golden-hour-arms', 'olivia-dancer-home', 'private-tree-pose', 'prenatal-ball-training', 'olivia-terrace-profile', 'olivia-riverside-profile', 'olivia-sunset-smile', 'olivia-sunlit-portrait' );
	array_walk_recursive( $data, function ( $v ) use ( &$used, $imgs ) {
		if ( is_string( $v ) && isset( $imgs[ $v ] ) ) {
			$used[] = $v;
		}
	} );
	$missing = array_values( array_filter( array_unique( $used ), fn( $k ) => empty( $imgs[ $k ]['hu']['file'] ) || empty( $imgs[ $k ]['hu']['alt'] ) ) );
	eq( array(), $missing, 'every photo of the online site has a Hungarian name and text' );
	$bad = array();
	$names = array();
	foreach ( $imgs as $k => $m ) {
		if ( empty( $m['hu'] ) ) {
			continue;
		}
		if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $m['hu']['file'] ) || preg_match( '/Olivia Kovacs|\b(the|with|in a)\b/', $m['hu']['alt'] ) ) {
			$bad[] = $k;
		}
		$names[] = $m['hu']['file'];
	}
	eq( array(), $bad, 'names: lower case, no accents, hyphens; texts: Hungarian' );
	eq( count( $names ), count( array_unique( $names ) ), 'every name once' );
	update_option( 'oy_edition', 'hu-online' );
	try {
		$html = oy_photo( 'olivia-dancer-home' );
		ok( str_contains( $html, 'alt="Kovács Olívia táncos pózban otthon, a nappaliban – online jóga otthonról"' ), 'a theme photo: its Hungarian text' );
		$att = self_test_photo( 'journal-test.jpg', 400, 300 );
		update_post_meta( $att, 'oy_key', 'journal-19' );
		ok( str_contains( oy_photo( $att ), 'alt="' . esc_attr( $imgs['journal-19']['hu']['alt'] ) . '"' ), 'a journal photo with no text of its own: the Hungarian one' );
		update_post_meta( $att, '_wp_attachment_image_alt', 'Olívia saját leírása' );
		ok( str_contains( oy_photo( $att ), 'alt="Olívia saját leírása"' ), 'a text someone wrote stays' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	ok( str_contains( oy_photo( 'olivia-dancer-home' ), 'alt="Olivia Kovacs in dancer pose in a living room"' ), 'the US site keeps its own text' );
} );

test( 'page editor: a photo Olívia puts on a page gets a web copy – 1920 px at most, Hungarian name and text, the original stays', function () {
	eq( 'img-7309.jpg', oye_file_name( 'IMG_7309.JPG' ), 'upload name: lower case' );
	eq( 'tukorpoz-2-olivia.jpeg', oye_file_name( 'Tükörpóz 2 – Olívia.jpeg' ), 'upload name: no accents, no spaces' );
	eq( 'kovacs-olivia-joga-elet', oye_slug( 'Kovács Olívia – a jóga és az élet' ), 'a name from a text: without the articles' );
	$big  = self_test_photo( 'IMG_7309.jpg', 2600, 1300 );
	$copy = oye_web_copy( $big, 'Kovács Olívia táncos pózban otthon – online jóga' );
	$meta = wp_get_attachment_metadata( $copy );
	ok( $copy && $copy !== $big, 'a new attachment' );
	ok( (bool) preg_match( '/^kovacs-olivia-tancos-pozban-otthon-online-joga(-\d+)?\.jpg$/', basename( (string) get_attached_file( $copy ) ) ), 'named after the text' );
	ok( 1920 === (int) $meta['width'] && 960 === (int) $meta['height'], '1920 px wide' );
	eq( 'Kovács Olívia táncos pózban otthon – online jóga', get_post_meta( $copy, '_wp_attachment_image_alt', true ), 'with the text' );
	ok( ! empty( $meta['sizes']['medium_large'] ) && ! empty( $meta['sizes']['large'] ), 'with the smaller sizes for phones' );
	ok( wp_attachment_is_image( $big ) && file_exists( (string) get_attached_file( $big ) ), 'the original stays in the media library' );
	eq( $copy, oye_web_copy( $big, 'más' ), 'once: the same copy next time' );
	eq( $copy, oye_web_copy( $copy, 'más' ), 'a copy is not copied again' );
	$own = self_test_photo( 'photo.jpg', 800, 600, '', 'online jóga csoportos egyéni' );
	ok( (bool) preg_match( '/^online-joga-csoportos-egyeni(-\d+)?\.jpg$/', basename( (string) get_attached_file( oye_web_copy( $own, 'a hely szövege' ) ) ) ), 'her own text wins over the place\'s' );
	$theme = self_test_photo( 'theme.jpg', 600, 400 );
	update_post_meta( $theme, 'oy_key', 'deck-down-dog' );
	eq( $theme, oye_web_copy( $theme, 'x' ), 'the theme\'s own photos are left as they are' );
	update_option( 'oy_edition', 'hu-online' );
	try {
		$hers = self_test_photo( 'instasize_250511180406.jpg', 1178, 1113, '2026/11' );
		$c    = oye_web_copy( $hers, 'Kovács Olívia jógaoktató oldalsó hátrahajlásban naplementekor – online hatha jóga' );
		ok( (bool) preg_match( '/^kovacs-olivia-tancos-poz-otthon-nappali-elo-online-jogaora(-\d+)?\.jpg$/', basename( (string) get_attached_file( $c ) ) ), 'her photo of 14 November: the name we gave it after looking at it' );
		eq( 'Kovács Olívia táncos pózban otthon, a nappaliban – élő online jógaóra otthonról', get_post_meta( $c, '_wp_attachment_image_alt', true ), '… and the text (it is not the backbend of the place)' );
		// saved in the editor: the page shows the copy, in the size the screen needs
		delete_option( 'oye_edits' );
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		wp_set_current_user( $admin );
		$up = self_test_photo( 'DSC_0042.jpg', 2400, 1600 );
		oye_apply( array(), array( 'i:olivia-golden-backbend' => array( 'id' => $up, 'alt' => '' ) ) );
		wp_set_current_user( 0 );
		$e   = oye_edits();
		$web = (int) $e['i']['i:olivia-golden-backbend']['id'];
		ok( $web !== $up && (int) get_post_meta( $web, 'oye_web', true ) === $up, 'saved: the web copy is on the page' );
		ok( (bool) preg_match( '/^kovacs-olivia-jogaoktato-oldalso-hatrahajlasban-naplementekor-online-hatha-joga(-\d+)?\.jpg$/', basename( (string) get_attached_file( $web ) ) ), 'no text of its own: named after the place\'s Hungarian text' );
		$out = oye_pass( '<div class="frame"><img class="photo" src="/x/olivia-golden-backbend-1200.jpg" sizes="(min-width: 960px) 52vw, 92vw" data-oyp="olivia-golden-backbend" alt="régi"></div>' );
		ok( str_contains( $out, 'srcset="' ) && str_contains( $out, '768w' ) && str_contains( $out, 'sizes="(min-width: 960px) 52vw, 92vw"' ), 'the page: srcset with the smaller sizes' );
		delete_option( 'oye_edits' );
		delete_option( 'oye_edits_meta' );
		delete_option( 'oye_edits_prev' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition, live update: the theme\'s photos get Hungarian names and texts, the photos Olívia put on the pages a web copy', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$imgs = oy_images();
		$att  = self_test_photo( 'olivia-dancer-home.jpg', 1200, 1133, '', $imgs['olivia-dancer-home']['alt'] );
		update_post_meta( $att, 'oy_key', 'olivia-dancer-home' );
		wp_update_post( array( 'ID' => $att, 'post_title' => $imgs['olivia-dancer-home']['alt'] ) );
		$old_url = wp_get_attachment_image_url( $att, 'medium' );
		$post    = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Képes írás', 'post_content' => '<p><img src="' . $old_url . '" alt=""></p>' ) );
		$kept = self_test_photo( 'olivia-childs-pose.jpg', 600, 800, '', 'Olívia saját szövege' );
		update_post_meta( $kept, 'oy_key', 'olivia-childs-pose' );
		$hers = self_test_photo( 'prenatal-training.jpg', 500, 750, '2026/11' );
		update_option( 'oye_edits', array( 't' => array(), 'i' => array( 'i:prenatal-ball-training' => array( 'id' => $hers ) ) ) );
		delete_option( 'oy_images_rev' );
		$log  = oy_migrate_images();
		$file = (string) get_attached_file( $att );
		ok( (bool) preg_match( '/^kovacs-olivia-tancos-poz-nappali-online-joga-otthon(-\d+)?\.jpg$/', basename( $file ) ) && file_exists( $file ), 'the theme photo: its Hungarian name' );
		$meta = wp_get_attachment_metadata( $att );
		$base = pathinfo( $file, PATHINFO_FILENAME );
		ok( ! empty( $meta['sizes'] ) && array() === array_filter( $meta['sizes'], fn( $s ) => ! str_starts_with( $s['file'], $base . '-' ) || ! file_exists( dirname( $file ) . '/' . $s['file'] ) ), 'its sizes too' );
		eq( $imgs['olivia-dancer-home']['hu']['alt'], get_post_meta( $att, '_wp_attachment_image_alt', true ), 'the Hungarian text' );
		eq( $imgs['olivia-dancer-home']['hu']['alt'], get_post_field( 'post_title', $att ), 'the Hungarian title' );
		ok( str_contains( get_post_field( 'post_content', $post ), wp_get_attachment_image_url( $att, 'medium' ) ) && ! str_contains( get_post_field( 'post_content', $post ), $old_url ), 'a post shows it at its new address' );
		eq( 'Olívia saját szövege', get_post_meta( $kept, '_wp_attachment_image_alt', true ), 'a text someone wrote stays' );
		$web = (int) oye_edits()['i']['i:prenatal-ball-training']['id'];
		ok( $web !== $hers && (bool) preg_match( '/^kismamatorna-online-fitneszlabda-kovacs-olivia(-\d+)?\.jpg$/', basename( (string) get_attached_file( $web ) ) ), 'her photo on the page: a web copy with the name we gave it' );
		ok( str_contains( implode( ' · ', $log ), 'web copies of 1 photos' ), 'logged' );
		eq( array(), oy_migrate_images(), 'once' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
		delete_option( 'oye_edits' );
	}
} );

test( 'online edition, Olívia\'s doc of 14 November: timetable cards, the hero button, no big name in the footer, line breaks', function () {
	require_once get_template_directory() . '/inc/typography.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$online = (object) array( 'format' => 'online', 'kind' => 'group', 'class_slug' => 'hatha-flow' );
		if ( function_exists( 'oys_is_online' ) && oys_is_online( $online ) ) {
			eq( 'Csoportos flow jóga', apply_filters( 'oys_card_title', 'Csoportos online flow jóga', $online ), 'a card: „online” is on its tag, not in the title' );
		}
		$flow = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
		eq( $flow ? get_the_title( $flow ) : '5 alkalmas bérlet', apply_filters( 'oys_price_card_title', '5 alkalmas bérlet', array( 'kind' => 'online_pack' ) ), 'a group pass card: the class above the price, like the one-to-one cards' );
		eq( 'Egyéni online jóga', apply_filters( 'oys_price_card_title', 'Egyéni online jóga', array( 'kind' => 'private_pack' ) ), 'a one-to-one card keeps its service' );
		$studio = (object) array( 'format' => 'studio', 'kind' => 'group', 'class_slug' => 'x' );
		eq( 'Online jóga a teremben', apply_filters( 'oys_card_title', 'Online jóga a teremben', $studio ), 'a class in a room keeps its title' );
		ob_start();
		get_template_part( 'template-parts/front-online' );
		$home = ob_get_clean();
		ok( str_contains( $home, 'oj-hero-cta' ) && ! str_contains( $home, 'oj-twins' ), 'the hero: one button (Bejelentkezés és árak)' );
		ob_start();
		load_template( get_template_directory() . '/footer.php', false );
		$foot = ob_get_clean();
		ok( str_contains( $foot, 'footer__bottom' ) && ! str_contains( $foot, 'footer__word' ), 'no big name in the footer' );
		$t = oy_typo_html( '<body><p>Élő jóga a nappalidból. Kezdőket is várok, 8 000 Ft, 90 perc – szerdán</p><script>var a = "a b";</script></body>' );
		ok( str_contains( $t, "a\u{00A0}nappalidból" ) && str_contains( $t, "Kezdőket\u{00A0}is" ) && str_contains( $t, "8\u{00A0}000\u{00A0}Ft" ) && str_contains( $t, "90\u{00A0}perc" ) && str_contains( $t, "perc\u{00A0}–" ), 'Hungarian line breaks: short words, numbers with units, no dash at a line start' );
		ok( str_contains( $t, 'var a = "a b";' ), 'scripts are left alone' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	ob_start();
	load_template( get_template_directory() . '/footer.php', false );
	ok( str_contains( ob_get_clean(), 'footer__word' ), 'the US site keeps its big name' );
} );

test( 'online edition, live update: the About page broken up – only while its text is still the theme\'s, every sentence of hers stays', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$old  = (string) file_get_contents( __DIR__ . '/fixtures/about-online-3.8.0.html' );
		$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
		$new  = $data['pages']['about']['content'];
		preg_match_all( '~<(?:p|li)>(.*?)</(?:p|li)>~s', $old, $m );
		// except what she later asked to take out (Olívia's doc, 14 November: the „_replace” pairs with nothing in their place)
		$out = array();
		foreach ( array_filter( $data['pages']['about']['_replace'] ?? array(), fn( $r ) => '' === $r[1] ) as $r ) {
			preg_match_all( '~<(?:p|li)>(.*?)</(?:p|li)>~s', $r[0], $mm );
			$out = array_merge( $out, $mm[1] );
		}
		$missing = array_filter( $m[1], fn( $x ) => ! str_contains( $new, $x ) && ! in_array( $x, $out, true ) );
		eq( array(), array_values( $missing ), 'every paragraph of the old page is in the new one, word for word, but those she asked out (her page-editor changes keep applying)' );
		foreach ( array( 'oj-chapter', 'oj-trainings', 'oj-note', 'oj-statement', 'oj-path', 'oj-areas', 'oj-places', 'oj-ways', 'oj-secret' ) as $c ) {
			ok( str_contains( $new, $c ), 'the new page has: ' . $c );
		}
		$page = get_page_by_path( oy_page_slug( 'about' ) );
		$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rólam', 'post_name' => oy_page_slug( 'about' ) ) );
		kses_remove_filters();
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $old ) ) );
		kses_init_filters();
		delete_option( 'oy_about_rev' );
		ok( in_array( 'about: the new layout', oy_migrate_about(), true ), 'the theme\'s text: the new layout' );
		$saved = get_post_field( 'post_content', $id );
		ok( str_contains( $saved, 'oj-path' ) && str_contains( $saved, 'Amiben ma segítek' ) && ! str_contains( $saved, '{{class:' ), 'saved, with her other areas and real links' );
		eq( array(), oy_migrate_about(), 'once' );
		wp_update_post( array( 'ID' => $id, 'post_content' => $old . '<p>Olívia saját bekezdése.</p>' ) );
		delete_option( 'oy_about_rev' );
		eq( array( 'about: kept as it is (changed on the site)' ), oy_migrate_about(), 'changed on the site: left as it is' );
		ok( str_contains( get_post_field( 'post_content', $id ), 'Olívia saját bekezdése.' ), '… her paragraph is there' );
		$fig = do_shortcode( '[oy_figure photo="olivia-dancer-home" class="oj-float"]' );
		ok( str_contains( $fig, '<figure class="oj-figure oj-float">' ) && str_contains( $fig, 'data-oyp="olivia-dancer-home"' ), 'a photo in the text, one the page editor can swap' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition, live update: her raw journal photos fill the frame, the prenatal kit „may be needed”', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$imgs = oy_images();
		ok( 1800 === (int) $imgs['journal-01']['h'] && file_exists( get_template_directory() . '/assets/img/' . oy_image_file( 'journal-01' ) . '-1200.jpg' ), 'the theme ships the raw photo' );
		$att = self_test_photo( 'hatha-jogaora-nyujto-gyakorlatok-forditott-harcos.jpg', 900, 849, '', $imgs['journal-06']['hu']['alt_prev'] );
		update_post_meta( $att, 'oy_key', 'journal-06' );
		$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A nyújtás hatása' ) );
		set_post_thumbnail( $post, $att );
		update_post_meta( $post, 'oy_art', '1' );
		$kit  = wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Online kismamatorna', 'post_name' => 'kismamatorna', 'post_content' => "<h2>Mire lesz szükséged?</h2>\n<ul>\n<li>Gumiszalag</li>\n</ul>\n<p>Ha valamelyik eszköz még nincs meg, írj, és megmutatom, mivel helyettesítheted otthon.</p>" ) );
		delete_option( 'oy_doc1114_rev' );
		$log  = oy_migrate_doc_1114();
		$meta = wp_get_attachment_metadata( $att );
		ok( 1200 === (int) $meta['width'] && 800 === (int) $meta['height'], 'the journal photo: her raw one (1200 × 800)' );
		eq( 'hatha-jogaora-nyujto-gyakorlatok-forditott-harcos.jpg', basename( (string) get_attached_file( $att ) ), '… under its Hungarian name' );
		eq( $imgs['journal-06']['hu']['alt'], get_post_meta( $att, '_wp_attachment_image_alt', true ), '… with the text of the new photo' );
		eq( '', get_post_meta( $post, 'oy_art', true ), 'the post fills the frame' );
		$c = get_post_field( 'post_content', $kit );
		ok( str_contains( $c, 'Mire lehet szükséged?' ) && str_contains( $c, 'Nem feltétlenül kell mindegyik' ) && str_contains( $c, '<li>Gumiszalag</li>' ), 'prenatal: „may be needed”, the list stays' );
		ok( (bool) preg_match( '/journal: [1-9]\d* raw photos, [1-9]\d* posts fill the frame/', implode( ' · ', $log ) ), 'logged' );
		eq( array(), oy_migrate_doc_1114(), 'once' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition, live update: 2015 on her timeline, the web copy of her Fitmummy photo gets our name and text', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$data  = json_decode( (string) file_get_contents( oy_content_file() ), true );
		$pair  = $data['pages']['about']['_insert'][0];
		$page  = get_page_by_path( oy_page_slug( 'about' ) );
		$id    = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rólam', 'post_name' => oy_page_slug( 'about' ) ) );
		wp_update_post( array( 'ID' => $id, 'post_content' => "<ol>\n" . $pair[0] . "\n<li><b>Ma</b> élő online órák.</li>\n</ol>" ) );
		$src  = self_test_photo( '877949486_122357927816006820_7146063380598239498_n.jpg', 900, 1200, '2026/11' );
		$copy = self_test_photo( '877949486-122357927816006820-7146063380598239498-n.jpg', 900, 1200, '2026/11' );
		update_post_meta( $copy, 'oye_web', $src );
		delete_option( 'oy_1114c_rev' );
		$log = oy_migrate_1114c();
		$c   = get_post_field( 'post_content', $id );
		ok( str_contains( $c, $pair[0] . "\n" . $pair[1] ), 'the 2015 line after the „19 évesen” one' );
		ok( (bool) preg_match( '/^fitmummy-pre-es-postnatalis-trenerkepzes-kovacs-olivia(-\d+)?\.jpg$/', basename( (string) get_attached_file( $copy ) ) ), 'the web copy: our name' );
		ok( str_starts_with( (string) get_post_meta( $copy, '_wp_attachment_image_alt', true ), 'Fitmummy pre- és postnatális trénerképzés' ), '… and our text' );
		ok( in_array( 'about: 2015 on the timeline', $log, true ), 'logged' );
		eq( array(), oy_migrate_1114c(), 'once' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition, live update: 2018 on her timeline, the kit list for every class, „charge your device” (Olívia, 14 November)', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		$data   = json_decode( (string) file_get_contents( oy_content_file() ), true );
		$pairs  = $data['pages']['about']['_insert'];
		$li2015 = $pairs[0][1];
		$li2018 = $pairs[1][1];
		ok( (bool) preg_match( '/2015-től.*\n<li><b>Otthon, online<\/b>[^\n]*\n' . preg_quote( $li2018, '/' ) . '/', $data['pages']['about']['content'] ), 'a fresh site: 2015, home practice, 2018' );
		$about = get_page_by_path( oy_page_slug( 'about' ) );
		$aid   = $about ? $about->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rólam', 'post_name' => oy_page_slug( 'about' ) ) );
		$home   = '<li><b>Otthon, online</b> a négy fal között kezdtem jógázni, és nem telt el nap gyakorlás nélkül.</li>';
		$li2023 = '<li><b>2023</b> hatha jógaoktató lettem (Yoga Alliance International), utána tibeti hangtál- és hangmasszázs-terapeuta.</li>';
		$gone   = array_column( array_filter( $data['pages']['about']['_replace'], fn( $r ) => '' === $r[1] ), 0 );
		wp_update_post( array( 'ID' => $aid, 'post_content' => "<ol>\n" . $pairs[0][0] . "\n" . $li2015 . "\n" . $home . "\n" . $li2023 . "\n<li><b>Ma</b> élő online órák.</li>\n</ol>\n" . implode( '', array_slice( $gone, 1 ) ) . '<p>Saját bekezdésem.</p>' ) );
		$kit    = $data['pages']['schedule-pricing']['_replace'];
		$prices = get_page_by_path( oy_page_slug( 'schedule-pricing' ) );
		$pid    = $prices ? $prices->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bejelentkezés és árak', 'post_name' => oy_page_slug( 'schedule-pricing' ) ) );
		$minr   = array_values( array_filter( $kit, fn( $r ) => str_contains( $r[0], 'legalább 3 fővel' ) ) )[0];
		wp_update_post( array( 'ID' => $pid, 'post_content' => "<ul>\n" . $kit[0][0] . "\n<li>Saját mondatom</li>\n" . $kit[1][0] . "\n</ul>\n<p>Kérlek, foglalj előre. " . $minr[0] . ' Kezdőket is várok.</p>' ) );
		$f    = array_values( array_filter( $data['faqs'], fn( $x ) => 'Mit kell előkészítenem az online jóga órára?' === ( $x['_prev']['q'] ?? '' ) ) )[0];
		$faq  = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $f['_prev']['q'], 'post_content' => wpautop( esc_html( $f['_prev']['a'] ) ) ) );
		$mine = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $f['_prev']['q'], 'post_content' => '<p>Ezt én írtam át.</p>' ) );
		$works = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => 'Tényleg működik a jóga online?', 'post_content' => '<p>Az ő válasza.</p>' ) );
		$gone  = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $data['faqs_removed'][0]['q'], 'post_content' => '<p>Gumiszalag.</p>' ) );
		$other = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => 'Várandós vagyok. Gyakorolhatok veled?', 'post_content' => '<p>Igen.</p>' ) );
		$gf    = array_values( array_filter( $data['faqs'], fn( $x ) => 'Ajándékozhatok jógát?' === ( $x['_prev']['q'] ?? '' ) ) )[0];
		$gift  = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $gf['_prev']['q'], 'post_content' => wpautop( esc_html( $gf['_prev']['a'] ) ) ) );
		$cf    = array_values( array_filter( $data['faqs'], fn( $x ) => 'Mi a lemondási szabály?' === $x['q'] ) )[0];
		$canc  = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => $cf['q'], 'post_content' => wpautop( esc_html( $cf['_prev']['a'] ) ) ) );
		$soon  = $data['classes']['egyeni-online-joga']['_replace'][0];
		$class = get_page_by_path( 'egyeni-online-joga', OBJECT, 'oy_class' );
		$cid   = $class ? $class->ID : wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Egyéni online jóga', 'post_name' => 'egyeni-online-joga' ) );
		wp_update_post( array( 'ID' => $cid, 'post_content' => '<p>Kérj időpontot. ' . $soon[0] . ', amit egy kattintással elfogadsz.</p>' ) );
		$img   = oy_images()['journal-01'];
		$att   = self_test_photo( 'hatha-jogaora-nyujto-gyakorlatok-forditott-harcos-x.jpg', 600, 900 );
		update_post_meta( $att, 'oy_key', 'journal-01' );
		update_post_meta( $att, 'oy_pos', $img['pos_prev'] );
		delete_option( 'oy_1114d_rev' );
		$log = oy_migrate_1114d();
		ok( str_contains( get_post_field( 'post_content', $cid ), 'Hamarosan visszaírok' ), 'one-to-one class: „soon”, not „within a day”' );
		eq( $img['pos'], get_post_meta( $att, 'oy_pos', true ), 'the journal photo: the focus lower, she is in the frame' );
		$c   = get_post_field( 'post_content', $aid );
		ok( 1 === substr_count( $c, '2018-tól' ), 'About: 2018 (massage) once' );
		ok( ! str_contains( $c, '19 évesen' ), 'About: „19 évesen” out of the timeline („ezt szedjük ki rólamnál”)' );
		ok( str_contains( $c, $li2015 . "\n" . $home . "\n" . $li2018 ), 'About: „Otthon, online” between 2015 and 2018, word for word (her edit of it stays)' );
		ok( str_contains( $c, $li2023 . "\n<li><b>2023–2026</b>" ), 'About: 2023–2026 (her events) after 2023' );
		ok( ! str_contains( $c, '2023 óta vagyok' ) && ! str_contains( $c, 'Évek személyi edzőként' ) && str_contains( $c, 'Saját bekezdésem.' ), 'About: the two parts she asked out are gone, the rest stays' );
		ok( ! str_contains( $c, 'oj-creds' ) && ! str_contains( $c, 'Tibeti hangtál és hangmasszázs operátor' ), 'About: the three training cards out too („Ezt a részt szedjük ki a rólamból”)' );
		$p = get_post_field( 'post_content', $pid );
		ok( str_contains( $p, $kit[0][1] ) && str_contains( $p, $kit[1][1] ) && str_contains( $p, 'Saját mondatom' ), 'prices page: the kit for every class and „charge it”, her own line stays' );
		ok( str_contains( $p, $minr[1] ) && str_contains( $minr[1], 'automatikusan lemondja' ), 'prices page: under three people the class is called off automatically 3 hours before' );
		ok( str_contains( get_post_field( 'post_content', $faq ), 'töltsd fel' ), 'the FAQ answer: charge your device' );
		eq( 'Mit kell előkészítenem az órára?', get_post_field( 'post_title', $faq ), 'the FAQ question: „Mit kell előkészítenem az órára?”' );
		$a = get_post_field( 'post_content', $faq );
		ok( str_contains( $a, 'Kismamatorna:' ) && str_contains( $a, 'magasabb ülőpárna' ) && str_contains( $a, 'egy könyv is pótolja' ) && str_contains( $a, 'kérdezz bátran' ), '… for each service, with her final list (yoga block or a book, a higher cushion, ask me)' );
		eq( 'trash', get_post_status( $gone ), 'the prenatal kit question: in the bin („Ez a kérdés pedig törlődhet”)' );
		eq( 'publish', get_post_status( $other ), '… the other questions stay' );
		ok( str_contains( get_post_field( 'post_content', $canc ), '3 órával a kezdés előtt kevesebben vagytok, a rendszer automatikusan lemondja' ), 'the cancellation FAQ: called off automatically 3 hours before, with an email' );
		ok( in_array( 'FAQ removed: ' . $data['faqs_removed'][0]['q'], $log, true ), '… logged' );
		eq( '<p>Ezt én írtam át.</p>', get_post_field( 'post_content', $mine ), 'an answer she rewrote stays hers' );
		eq( 'Mit kell előkészítenem az órára?', get_post_field( 'post_title', $mine ), '… under the question she asked for' );
		eq( 'Ajándékozhatok jógát, kismamatornát, stretchinget és mobilizációt vagy relaxációt és légzést?', get_post_field( 'post_title', $gift ), 'the gift question: every service' );
		ok( str_contains( get_post_field( 'post_content', $gift ), 'relaxációt és légzést is megveheted ajándékutalványként' ), '… and so the answer' );
		eq( 'Tényleg működik a csoportos jóga online?', get_post_field( 'post_title', $works ), 'the FAQ question: „csoportos”' );
		eq( '<p>Az ő válasza.</p>', get_post_field( 'post_content', $works ), '… its answer as it was' );
		ok( in_array( 'page: about', $log, true ) && in_array( 'page: schedule-pricing', $log, true ), 'logged' );
		eq( array(), oy_migrate_1114d(), 'once' );
		update_option( 'oy_edition', 'fort-myers' );
		delete_option( 'oy_1114d_rev' );
		eq( array(), oy_migrate_1114d(), 'only on the online edition' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'online edition, Olívia\'s doc of 14 November: „az óra visszanézhető még 1 hétig” – highlighted in more places', function () {
	update_option( 'oy_edition', 'hu-online' );
	$keep = array( 'live_record' => OYS_Settings::get( 'live_record' ), 'live_replay_days' => OYS_Settings::get( 'live_replay_days' ) );
	try {
		OYS_Settings::update( array( 'live_record' => 'group', 'live_replay_days' => 7 ) );
		eq( 7, oy_replay_days(), 'a week, from the booking settings (one place)' );
		$note = oy_replay_note();
		ok( str_contains( $note, 'class="oj-replay"' ) && str_contains( $note, esc_html__( 'You can watch it again for a week.', 'olivia-yoga' ) ), 'the highlighted note' );
		ok( str_contains( do_shortcode( '[oy_schedule]' ), 'oj-replay' ), '… under the timetables (prices page)' );
		ob_start();
		load_template( get_template_directory() . '/template-parts/front-online.php', false );
		$home = (string) ob_get_clean();
		ok( 1 === substr_count( $home, 'way__len--replay' ) && str_contains( $home, esc_html( oy_replay_label() ) ), 'home: a chip on the group class box' );
		ok( str_contains( $home, 'class="oj-replay"' ), 'home: … and the note under the timetable' );
		ok( str_contains( (string) file_get_contents( get_template_directory() . '/single-oy_class.php' ), "oy_replay_note( 'oj-replay--aside' )" ), 'the group class page: beside its button' );
		$group  = make_session( array( 'format' => 'online' ) );
		$priv   = make_session( array( 'format' => 'online', 'kind' => 'private' ) );
		$link   = make_session( array( 'format' => 'online', 'online_url' => 'https://example.com/room' ) );
		$studio = make_session();
		$card   = new ReflectionMethod( 'OYS_Frontend', 'session_card' );
		$card->setAccessible( true );
		ok( str_contains( $card->invoke( null, $group ), 'oys-replay-note' ), 'booking page: an online group class can be watched again' );
		ok( ! str_contains( $card->invoke( null, $priv ), 'oys-replay-note' ), '… not a one-to-one (not recorded)' );
		eq( '', OYS_Live::replay_text( $link ), '… not a class on a link typed in by hand' );
		eq( '', OYS_Live::replay_text( $studio ), '… not one in the studio' );
		$join = new ReflectionMethod( 'OYS_Emails', 'join_html' );
		$join->setAccessible( true );
		$b    = OYS_Bookings::get( OYS_Bookings::book_manual( make_user( 'Replay' ), $group, 'comp', false, false, 'online' ) );
		ok( $b && str_contains( $join->invoke( null, $b, $group ), esc_html( OYS_Live::replay_text( $group ) ) ), 'the confirmation and reminder emails: with the join link' );
		OYS_Settings::update( array( 'live_record' => 'none' ) );
		eq( '', oy_replay_note(), 'not recorded: no note' );
		eq( '', OYS_Live::replay_text( $group ), '… and nothing when booking' );
	} finally {
		OYS_Settings::update( $keep );
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'Olívia\'s doc of 14 November: the brush wave dissolves at its ends; every home page picture comes to colour under the cursor', function () {
	$d = oy_brush_d( 400, 100, 22, 30 );
	preg_match( '/^M([\d.]+) ([\d.]+) L([\d.]+) ([\d.]+)/', $d, $m );
	ok( (bool) preg_match( '/ L400\.0 50\.0 L400\.0 50\.0 /', $d ) || abs( (float) $m[2] - 50 ) < .01, 'the stroke tapers to nothing at its ends (no cut edge)' );
	$on = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $on, '.oj-brush.oj-blob, .oj-brush.oj-band { overflow: visible; -webkit-mask: linear-gradient(90deg, transparent' ), '… and fades out' );
	ok( str_contains( $on, '.home main .photo:hover, .home main .frame:hover img' ), 'home: every picture in colour under the cursor, linked or not' );
} );

test( 'Olívia\'s doc of 14 November: „legyen extrább, legyél nagyon kreatív” – the one-to-one block and the stretching / relaxation cards; only the group class can be watched again', function () {
	update_option( 'oy_edition', 'hu-online' );
	try {
		foreach ( array( 'stretching' => '60 perc', 'relaxacio-legzes' => '30 perc' ) as $slug => $len ) {
			$cl = get_page_by_path( $slug, OBJECT, 'oy_class' );
			$id = $cl ? $cl->ID : wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug ) );
			update_post_meta( $id, 'oy_duration', $len );
		}
		ob_start();
		load_template( get_template_directory() . '/template-parts/front-online.php', false );
		$home = (string) ob_get_clean();
		ok( 4 === substr_count( $home, 'class="oj-fit__item oj-fit__item--' ) && str_contains( $home, 'oj-fit__item--sun' ) && ! str_contains( $home, 'class="oj-bubble' ), 'one-to-one: four of the reasons as soft boxes in her four colours, not the long bubble list („túl hosszú ez a felsorolás”)' );
		ok( str_contains( $home, esc_url( oy_class_url( 'egyeni-online-joga' ) ) . '">' . esc_html__( 'More about one-to-one yoga', 'olivia-yoga' ) ), '… and a link to the one-to-one page, where the whole list is (the extra „Kinek ajánlom még?” link went with „túl katyvaszos”)' );
		// Olívia, 14 November: „Ez még itt túl katyvaszos, legyen letisztultabb” – one quiet line, the buttons, a small gift link
		ok( (bool) preg_match( '~<p class="oj-private__facts">[^<]+ · [^<]+ · [^<]+</p>~u', $home ), '… the length, the pair price and „from your living room” in one quiet line' );
		ok( ! str_contains( $home, 'class="oj-voucher"' ) && ! str_contains( $home, 'oj-fit__more' ), '… no voucher box and no extra link („túl katyvaszos”)' );
		ok( ! str_contains( $home, 'oj-private .oj-checklist' ) && ! str_contains( $home, 'and as a gift: surprise' ), '… no plain tick list' );
		ok( ! str_contains( $home, 'oj-more__card' ) && ! str_contains( $home, 'id="more-title"' ), 'stretching and relaxation are no longer on the home page („szedjük ki inkább a főoldalról”) – they have their own pages' );
		ok( str_contains( $home, esc_html__( 'I guide you in real time. Afterwards the class chat stays open, and a group class can be watched again for a week: only I am on the recording, nobody else.', 'olivia-yoga' ) ), 'step 4: only a group class, only she is on the recording' );
		$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
		ok( str_contains( $css, '.oj-voucher { position: relative;' ) && str_contains( $css, '.oj-more__stamp { position: absolute;' ), 'the styles are there' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'Olívia\'s doc of 14 November, evening: class types as tags in the kit answer, the gift cards soft and in groups with the chosen one shown, a long title beside its photo, „Rólam” in the footer, every article on one page', function () {
	update_option( 'oy_edition', 'hu-online' );
	try {
		$html = oy_faq_tags( "<p>Jóga: jógaszőnyeg.</p>\n<p>Kismamatorna: labda.</p>\n<p>Ha: Nap ☼</p>" );
		ok( str_contains( $html, '<span class="oj-faq-tag oj-faq-tag--lilac">Jóga</span>jógaszőnyeg.' ) && str_contains( $html, 'oj-faq-tag--sage">Kismamatorna</span>' ), 'the kit answer: each class type as a tag in its colour' );
		ok( str_contains( $html, '<p>Ha: Nap' ), '… other answers untouched' );
		ok( str_contains( oy_page_head( 'Ajándékutalvány', '', array(), 'gifts' ), 'page-head--word' ) && ! str_contains( oy_page_head( 'Rólam', '', array(), 'about' ), 'page-head--word' ), 'one long word in a title: smaller, beside the photo' );
		eq( 'About me', oy_brand( 'about' ), 'footer: „Rólam”' );
		$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
		eq( 'olivia-sunlit-portrait', $data['studio_pages']['gifts']['photo'], 'the gift card page: her photo, not the singing bowls' );
		$fn = (string) file_get_contents( get_template_directory() . '/functions.php' );
		ok( str_contains( $fn, "\$q->set( 'nopaging', true );" ), 'Jógáról: every article on one page' );
		ok( str_contains( (string) file_get_contents( get_template_directory() . '/home.php' ), 'array_merge( array_values( array_filter( $all' ), '… the eight limbs at the end' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	$keep_s = get_option( 'oys_services' );
	update_option( 'oys_services', array( 'prenatal' => array( 'label' => 'Online kismamatorna', 'class' => 'kismamatorna' ) ) );
	$groups = OYS_Frontend::gift_groups( array(
		array( 'id' => 'a', 'name' => 'Jegy', 'kind' => 'online_pack', 'price_cents' => 300000 ),
		array( 'id' => 'b', 'name' => 'Kismama', 'kind' => 'private_single', 'service' => 'prenatal', 'price_cents' => 800000 ),
		array( 'id' => 'c', 'name' => 'Valami', 'kind' => 'membership', 'price_cents' => 100 ),
		array( 'id' => 'd', 'name' => 'Bérlet', 'kind' => 'online_pack', 'price_cents' => 1300000 ),
	) );
	false === $keep_s ? delete_option( 'oys_services' ) : update_option( 'oys_services', $keep_s );
	eq( array( 'group', 'prenatal', 'other' ), array_keys( $groups ), 'gift cards: in groups by what they are for, the rest last' );
	eq( 'Online kismamatorna', $groups['prenatal']['label'], '… each one-to-one service under its own name' );
	eq( 2, count( $groups['group']['items'] ), '… the group yoga ones together' );
	$css = (string) file_get_contents( OYS_DIR . 'assets/oys.css' );
	ok( ! str_contains( $css, 'box-shadow: 6px 6px 0 var(--oys-ink)' ) && str_contains( $css, '.oys-gift-chosen {' ), '… softer, and the chosen one is shown above the form' );
	ok( str_contains( (string) file_get_contents( OYS_DIR . 'assets/oys.js' ), '[data-oys-gift-chosen]' ), '… updated when another is chosen' );
} );

test( 'Olívia\'s doc of 14 November, late evening: forints (not dollars) when the price changes on the booking form, no „buy a single ticket” option, the contact card without „Online, live”', function () {
	$keep = OYS_Settings::get( 'currency' );
	OYS_Settings::update( array( 'currency' => 'huf' ) );
	$m = new ReflectionMethod( 'OYS_Frontend', 'party_script' );
	$m->setAccessible( true );
	$js = $m->invoke( null );
	OYS_Settings::update( array( 'currency' => $keep ) );
	ok( str_contains( $js, '"\\u00a0Ft";}' ) && ! str_contains( $js, 'el.textContent="$"' ), 'booking form: the total in forints when guests are added' );
	$src = (string) file_get_contents( OYS_DIR . 'includes/class-frontend.php' );
	ok( str_contains( $src, "if ( \$price > 0 && (int) \$p['credits'] <= 1 && 'intro' !== \$p['kind'] ) {" ), 'no „Buy a 1-class ticket and use it” next to paying by card (the same thing)' );
	update_option( 'oy_edition', 'hu-online' );
	try {
		ok( ! str_contains( oy_page_aside( 'contact' ), esc_html__( 'Online, live', 'olivia-yoga' ) ), 'contact card: no „Online, élőben”' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	ok( str_contains( (string) file_get_contents( get_template_directory() . '/assets/site.css' ), '@media (min-width: 1000px) and (max-height: 740px)' ), 'the side card on a short screen: a slimmer photo, so all of it stays in view' );
} );

test( 'Áron, 16 November: the private offer email says a discount code can be used when paying', function () {
	$sent = array();
	$cap  = function ( $null, $atts ) use ( &$sent ) {
		$sent[] = $atts['message'];
		return true;
	};
	add_filter( 'pre_wp_mail', $cap, 10, 2 );
	try {
		global $wpdb;
		$uid = make_user( 'Ajánlat' );
		$wpdb->insert( OYS_Install::table( 'private_requests' ), array( 'user_id' => $uid, 'topic' => 'yoga', 'duration_min' => 60, 'people' => 1, 'location_type' => 'online', 'preferred' => 'Bármikor', 'status' => 'new', 'created_at' => oys_now() ) );
		$rid = (int) $wpdb->insert_id;
		$sid = OYS_Privates::offer( $rid, wp_date( 'Y-m-d H:i', time() + 3 * DAY_IN_SECONDS ), 60, 800000, 'Online' );
		$mail = implode( "\n", $sent );
		ok( ! is_wp_error( $sid ) && str_contains( $mail, 'If you have a discount code, you can use it when you pay' ), 'the offer email: „if you have a discount code, use it when you pay” (Áron: „rakd bele, hogyha kedvezménykódod van, akkor a fizetésnél érvényesíthető”)' );
	} finally {
		remove_filter( 'pre_wp_mail', $cap, 10 );
	}
} );

test( 'Áron, 16 November: a private request always says what it is for – the service and what was asked – in both emails, the admin and the account', function () {
	$sent = array();
	$cap  = function ( $null, $atts ) use ( &$sent ) {
		$sent[] = array( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'], $atts['subject'], $atts['message'] );
		return true;
	};
	add_filter( 'pre_wp_mail', $cap, 10, 2 );
	try {
		global $wpdb;
		$uid     = make_user( 'Kérő' );
		$offered = OYS_Services::offered();
		$topic   = $offered ? (string) array_key_first( $offered ) : 'yoga';
		$wpdb->insert( OYS_Install::table( 'private_requests' ), array( 'user_id' => $uid, 'topic' => $topic, 'duration_min' => 60, 'people' => 2, 'location_type' => 'online', 'preferred' => 'Kedd este 19 után', 'notes' => 'Fáj a derekam', 'status' => 'new', 'created_at' => oys_now() ) );
		$req   = OYS_Privates::get( (int) $wpdb->insert_id );
		$label = OYS_Privates::topic_label( $topic, 2 );
		$rows  = OYS_Privates::summary( $req );
		OYS_Emails::private_request_received( $req );
		$user  = get_userdata( $uid );
		$cust  = array_values( array_filter( $sent, fn( $m ) => $user->user_email === $m[0] ) );
		$staff = array_values( array_filter( $sent, fn( $m ) => $user->user_email !== $m[0] ) );
		ok( 6 === count( $rows ) && in_array( $label, $rows, true ) && in_array( 'Kedd este 19 után', $rows, true ), 'the request in one list: service, length, how many, where, the times that suit, the note' );
		ok( $cust && str_contains( $cust[0][2], esc_html( $label ) ) && str_contains( $cust[0][2], 'Kedd este 19 után' ) && str_contains( $cust[0][2], 'Fáj a derekam' ), '… the customer\'s email repeats what they asked for' );
		ok( ! OYS_Email_Templates::enabled( 'studio_private' ) || ( $staff && str_contains( $staff[0][1], $label ) && str_contains( $staff[0][2], esc_html( $label ) ) && str_contains( $staff[0][2], 'Kedd este 19 után' ) && str_contains( $staff[0][2], $user->user_email ) ), '… and the studio\'s email has the service in its subject and every detail in it, not only the admin' );
		$adm = (string) file_get_contents( OYS_DIR . 'includes/admin/class-admin.php' );
		$fe  = (string) file_get_contents( OYS_DIR . 'includes/class-frontend.php' );
		ok( str_contains( $adm, "'</td><td><b>' . esc_html( OYS_Privates::topic_label( \$r->topic ?? '', (int) \$r->people ) ) . '</b><br>'" ) && str_contains( $fe, "esc_html__( 'Preferred times', 'olivia-studio' ) . ': ' . esc_html( \$r->preferred )" ), '… the admin list leads with the service; the account shows the times asked for until an offer comes' );
	} finally {
		remove_filter( 'pre_wp_mail', $cap, 10 );
	}
} );

test( 'Olívia\'s doc of 14 November, night: a gift for two goes to two email addresses; Jógáról lists every article; page-head text clear of the photo wave', function () {
	$sent = array();
	$cap  = function ( $null, $atts ) use ( &$sent ) {
		$sent[] = array( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'], $atts['message'] );
		return true;
	};
	add_filter( 'pre_wp_mail', $cap, 10, 2 );
	$keep = get_option( OYS_Products::OPTION );
	$all  = OYS_Products::all();
	$all['pair-gift-test'] = array( 'id' => 'pair-gift-test', 'name' => 'Páros teszt', 'kind' => 'private_single', 'service' => '', 'people' => 2, 'credits' => 1, 'validity_days' => 30, 'price_cents' => 1200000, 'active' => 1, 'giftable' => 1 );
	update_option( OYS_Products::OPTION, $all );
	try {
		$buyer = make_user( 'Giver' );
		$oid   = OYS_Orders::create( array( 'user_id' => $buyer, 'type' => 'gift', 'product_id' => 'pair-gift-test', 'description' => 'x', 'amount_cents' => 1200000, 'meta' => array( 'recipient_name' => 'Kata', 'recipient_email' => 'kata@example.com', 'message' => '', 'recipient_name2' => 'Péter', 'recipient_email2' => 'peter@example.com' ) ) );
		OYS_Gifts::issue_for_order( OYS_Orders::get( $oid ) );
		$to = array_column( $sent, 0 );
		ok( in_array( 'kata@example.com', $to, true ) && in_array( 'peter@example.com', $to, true ), 'a gift for two: both get it by email' );
		$kata = array_values( array_filter( $sent, fn( $m ) => 'kata@example.com' === $m[0] ) )[0][1];
		$code = preg_match( '/OY-[A-Z0-9]{4}-[A-Z0-9]{4}/', $kata, $mm ) ? $mm[0] : '';
		$pet  = array_values( array_filter( $sent, fn( $m ) => 'peter@example.com' === $m[0] ) )[0][1];
		ok( '' !== $code && str_contains( $pet, $code ), '… with the same code (one of them redeems it, they book together)' );
		ok( str_contains( $kata, 'Péter' ) && str_contains( $pet, 'Kata' ), '… each email names the other one' );
		$src = (string) file_get_contents( OYS_DIR . 'includes/class-frontend.php' );
		ok( str_contains( $src, 'name="recipient_email2"' ) && str_contains( $src, 'data-people="' ), 'the gift form: a second address for a pair gift' );
	} finally {
		remove_filter( 'pre_wp_mail', $cap, 10 );
		update_option( OYS_Products::OPTION, $keep );
	}
	$cards = (string) file_get_contents( get_template_directory() . '/template-parts/cards.php' );
	ok( ! str_contains( $cards, '$posts = $args' ), 'the cards template no longer overwrites the main list (Jógáról showed only the eight limbs)' );
	ok( str_contains( (string) file_get_contents( get_template_directory() . '/assets/online.css' ), 'calc(53vw - var(--oj-bleed, 20px) - 96px)' ), 'page heads: the text never runs into the photo wave' );
} );

test( 'Olívia\'s doc of 14 November, night: the service boxes get soft, feminine line art on a peach patch with a pink curve', function () {
	foreach ( array( 'group', 'home', 'prenatal', 'mobility', 'lotus' ) as $n ) {
		$a = oy_way_art( $n );
		ok( str_contains( $a, 'class="wa-1"' ) && str_contains( $a, 'wa-breathe' ) && ! str_contains( $a, '<path' ), 'service box art: soft pastel circles, no drawing („gyerekes ez a rajzos piktogram”): ' . $n );
	}
	update_option( 'oy_edition', 'hu-online' );
	try {
		ob_start();
		load_template( get_template_directory() . '/template-parts/front-online.php', false );
		$home = (string) ob_get_clean();
		eq( 5, substr_count( $home, 'class="way__icon way__art"' ) + substr_count( $home, '<span class="way__pic">' ), 'home: all five boxes use it, or – since „ezeket a boxokat csináld meg olyasmi stílusban mint ezek” – the class photo with the wavy edge' );
		ok( str_contains( $home, '>' . esc_html__( 'Get to know me', 'olivia-yoga' ) . '<' ) && ! str_contains( $home, esc_html__( 'Meet Olivia', 'olivia-yoga' ) ), 'home: „Ismerj meg”, not „Ismerd meg Olíviát”' );
		ok( str_contains( (string) file_get_contents( get_template_directory() . '/assets/online.css' ), '.oj-modules:not(.oj-modules--why) li::before { position: absolute;' ), 'prenatal modules: a pastel circle peeking into each box\'s corner, not a dot' );
		$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
		ok( str_contains( $css, '.oj-modules:not(.oj-modules--why) li:nth-child(4n + 2) { --dot: var(--pink); }' ) && str_contains( $css, '.oj-modules:not(.oj-modules--why) li::after { display: none; }' ), '… in the site\'s own four colours, one circle each' );
		ok( str_contains( $css, '.oj-more__list li::before { -webkit-mask: none; mask: none;' ), 'the stretching / relaxation reasons: a pastel dot, not the wave' );
		ob_start();
		load_template( get_template_directory() . '/header.php', false );
		$head = (string) ob_get_clean();
		ok( (bool) preg_match( '~<a class="btn btn--orchid btn--sm header-cta" href="[^"]*">.*?' . preg_quote( esc_html__( 'My account', 'olivia-yoga' ), '~' ) . '</a>~s', $head ), 'the header button: „Fiókom”' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'Olívia\'s doc of 14 November, night: „Online órák” – the two blocks out, the classes as photo cards, „Melyik órát válasszam?” as colourful boxes, a new first sentence, waves instead of dashes', function () {
	update_option( 'oy_edition', 'hu-online' );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	update_option( 'oy_edition', 'fort-myers' );
	$pg   = $data['pages']['classes'];
	ok( ! str_contains( $pg['content'], 'Alapvetően minden hatha jóga óra' ) && ! str_contains( $pg['content'], 'Miért jó az online jógaóra?' ), 'the intro and „Miért jó az online jógaóra?” out („ez a 2 blokk jöjjön ki”)' );
	ok( 5 === substr_count( $pg['content'], 'class="oj-guide__box' ) && str_contains( $pg['content'], '{{class:relaxacio-legzes}}' ), '„Melyik órát válasszam?”: five boxes, the links kept' );
	ok( str_contains( $pg['content'], 'Egyéni órán csak rád figyelek' ) && ! str_contains( $pg['content'], 'Egyéni órán csak neked tanítok' ), 'a new first sentence for „Egyéni online órák”' );
	ok( ! str_contains( $data['classes']['hatha-flow']['content'], 'napüdvözlet' ), 'the group class: no sun salutations in its description („nem napüdvözletezünk”)' );
	ok( str_contains( oy_page_head( 'X', '', array(), 'x', '', '<img src="a.jpg" sizes="(min-width: 960px) 36vw, 90vw">' ), 'sizes="(min-width: 960px) 66vw, 100vw"' ), 'page-head photos: the large version is asked for, so they are not blurred („miért esik így szét a minősége?”)' );
	update_option( 'oy_edition', 'hu-online' );
	try {
		$page = get_page_by_path( oy_page_slug( 'yoga-classes' ) );
		$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Online jógaórák', 'post_name' => oy_page_slug( 'yoga-classes' ) ) );
		$old  = array_values( array_filter( $pg['_replace'], fn( $r ) => str_contains( $r[0], 'Melyik órát válasszam?' ) ) )[0];
		$first = array_values( array_filter( $pg['_replace'], fn( $r ) => str_contains( $r[1], 'csak rád figyelek' ) ) )[0];
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => oy_resolve_tokens( $old[0] ) . '<p>' . $first[0] . ' Saját mondatom.</p>' ) ) );
		delete_option( 'oy_1114d_rev' );
		oy_migrate_1114d();
		$c = get_post_field( 'post_content', $id );
		ok( str_contains( $c, 'class="oj-guide__box oj-guide__box--lilac" href="' . oy_class_url( 'hatha-flow' ) ) && str_contains( $c, 'csak rád figyelek' ) && str_contains( $c, 'Saját mondatom.' ), 'live update: the boxes in (links as on the site), the sentence changed, her own words stay' );
		ok( str_contains( oy_classes_list(), 'class="oj-class-cards"' ) && ! str_contains( oy_classes_list(), 'class-row__thumb' ), 'the classes as cards, the photo inside the card' );
		ok( str_contains( do_shortcode( '[oy_art name="lotus"]' ), 'way__art' ), '[oy_art]: the soft line drawing' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $css, '.entry ul:not([class]) li:not([class])::before { -webkit-mask: none; mask: none; left: .15rem; top: .62em; width: 9px;' ), 'lists: a soft pastel dot, not a dash („a gondolatjelek bénák”) and not a wave' );
} );

test( 'Olívia\'s doc of 14 November, late night: the group class – a short relaxation, one part of the body in focus, no start dates, the kit; „elfogadhatsz”; the five classes and the five questions laid out', function () {
	update_option( 'oy_edition', 'hu-online' );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	update_option( 'oy_edition', 'fort-myers' );
	$hf = $data['classes']['hatha-flow'];
	ok( ! str_contains( $hf['content'], 'hosszú relaxáció' ) && ! str_contains( $hf['excerpt'], 'hosszú relaxáció' ) && str_contains( $hf['content'], 'csend és relaxáció' ), 'the group class: the relaxation is not long („A relaxáció nem hosszú”)' );
	ok( ! str_contains( $data['pages']['classes']['content'], 'hosszú relaxáció' ) && ! str_contains( $data['pages']['about']['content'], 'hosszú relaxáció' ), '… nor on the classes page and the About page' );
	ok( str_contains( $hf['content'], 'a vállra, a csípőre vagy a gerincre' ), '… every class focuses on one part of the body („pl. váll, csípő, gerinc”)' );
	ok( ! str_contains( $hf['content'], 'december 16' ), '… the start dates out („ez a rész nem kell”)' );
	ok( false !== mb_stripos( $hf['content'], 'jógaheveder' ) && false !== mb_stripos( $hf['content'], 'jógatégl' ) && false !== mb_stripos( $hf['content'], 'pléd' ), '… the kit on the page („ide belefogalmazhatjuk az eszközöket”)' );
	foreach ( array( 'hatha-flow', 'egyeni-online-joga', 'stretching', 'relaxacio-legzes' ) as $k ) {
		$kc = $data['classes'][ $k ]['content'];
		ok( 1 === substr_count( $kc, '<h2>Mire lesz szükséged?</h2>' ) && str_contains( $kc, 'töltsd fel' ) && ! str_contains( $kc, 'Kell hozzá egy jógaszőnyeg' ), $k . ': what to have ready, in its own part („Minden szolgáltatás aloldalon külön bekezdésbe írd, hogy milyen eszköz szükséges”)' );
	}
	ok( str_contains( $data['classes']['kismamatorna']['content'], '<h2>Mire lehet szükséged?</h2>' ), '… the prenatal page has had its own already' );
	ok( str_contains( $data['classes']['relaxacio-legzes']['content'], 'illóolaj' ) && str_contains( $data['classes']['stretching']['content'], 'edzőszőnyeg' ), '… each with its own kit (from the FAQ answer)' );
	$wp  = (string) file_get_contents( get_template_directory() . '/assets/wp.css' );
	$sc  = (string) file_get_contents( get_template_directory() . '/assets/site.css' );
	ok( str_contains( $wp, '@media (min-width: 783px) { body.admin-bar { --wp-bar: 32px; } }' ) && str_contains( $sc, '.aside-sticky { position: sticky; top: calc(var(--header-h) + var(--wp-bar, 0px) + 24px);' ), 'logged in: the side box sticks under the admin bar too, its top is not cut off („kicsit levágódik itt is a box teteje”)' );
	$all = (string) wp_json_encode( array( $data['pages'], $data['classes'] ), JSON_UNESCAPED_UNICODE );
	ok( ! str_contains( preg_replace( '/"_replace":\[.*?\]\]/s', '', $all ), 'elfogadsz, és kifizeted' ) && str_contains( $data['pages']['classes']['content'], 'egy kattintással elfogadhatod, aztán kifizetheted, vagy lefoglalhatod a bérletedből' ), '„elfogadhatsz, ki tudod fizetni vagy a bérletedből foglalhatsz” – in natural Hungarian' );
	$texts = implode( ' ', array_map( fn( $c ) => $c['content'] . ( $c['excerpt'] ?? '' ), $data['classes'] ) ) . $data['pages']['classes']['content'];
	foreach ( array( 'fókuszál, például', 'a te idődben', 'És egy telefon', 'dolgozol, az orvosod', 'friss anyukaként' ) as $calque ) {
		ok( ! str_contains( $texts, $calque ), 'natural Hungarian, no „' . $calque . '” (Áron: „Nem magyaros amit csinálsz”)' );
	}
	$eg   = $data['classes']['egyeni-online-joga']['content'];
	$list = substr( $eg, (int) strpos( $eg, '<div class="oj-groups">' ) );
	$list = substr( $list, 0, (int) strpos( $list, '<h2>' ) );
	ok( str_contains( $eg, '<h2 id="kinek-szol">' ) && substr_count( $list, '<li>' ) >= 11 && str_contains( $list, 'hospitálnál' ), 'the one-to-one page: the whole list of whom it is for („majd csak az aloldalon kell ezt mind megjeleníteni”)' );
	ok( 4 === substr_count( $list, '<div class="oj-group oj-group--' ) && 4 === substr_count( $list, '<h3>' ) && ! str_contains( $eg, 'oj-cols' ), '… in four soft boxes by theme, not one long list („legyen szebben összeszedve”)' );
	$css0 = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $css0, '.entry .oj-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));' ), '… two by two' );
	$olds = array_column( $data['classes']['egyeni-online-joga']['_replace'], 0 );
	ok( (bool) array_filter( $olds, fn( $o ) => str_contains( $o, '<div class="oj-cols">' ) ), '… the live page changes from the two-column list too' );
	$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $css, '.oj-class-cards--lead > :first-child { grid-column: 1 / -1; flex-direction: row; }' ) && str_contains( $css, '@media (min-width: 1080px) { .oj-class-cards--lead { grid-template-columns: repeat(4, minmax(0, 1fr)); } }' ), 'the five classes: the group class wide on top, the four one-to-one ones in one row („az 5. egy új sorban van”)' );
	ok( str_contains( $css, 'grid-template-areas: "art q go" "art name go" "art text go";' ) && str_contains( $css, '.entry .oj-guide { grid-template-columns: 1fr; gap: .8rem; }' ), '„Melyik órát válasszam?”: one row per question, read from left to right („nem felhasználóbarát az elrendezés”)' );
	update_option( 'oy_edition', 'hu-online' );
	$five = 5 === count( oy_classes() );
	ok( $five === str_contains( oy_class_cards(), 'oj-class-cards--lead' ), 'the wide first card only when there are five classes' );
	$post    = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
	$made    = ! $post;
	$id      = $post ? $post->ID : wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Csoportos online flow jóga', 'post_name' => 'hatha-flow' ) );
	$keep    = $post ? array( $post->post_content, $post->post_excerpt ) : null;
	$olds    = array_values( array_map( fn( $r ) => $r[0], array_filter( $hf['_replace'], fn( $r ) => (bool) preg_match( '/megérkezzen a figyelme\.<\/p>$|hosszú relaxáció|december 16|Segíthet egy jógaheveder/u', $r[0] ) ) ) );
	try {
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => implode( "\n", $olds ) . "\n<p>Saját mondatom.</p>\n[oy_booking_link]", 'post_excerpt' => $hf['_excerpt_prev'] ) ) );
		delete_option( 'oy_1114d_rev' );
		oy_migrate_1114d();
		$c = get_post_field( 'post_content', $id );
		ok( str_contains( $c, 'rövid relaxáció' ) && str_contains( $c, 'a csípőre vagy a gerincre' ) && ! str_contains( $c, 'december 16' ) && str_contains( $c, 'Saját mondatom.' ), 'live update: the group class\'s text changed, her own words stay' );
		ok( 1 === substr_count( $c, '<h2>Mire lesz szükséged?</h2>' ) && false !== mb_stripos( $c, 'jógatégl' ) && strpos( $c, 'Mire lesz szükséged?' ) < strpos( $c, '[oy_booking_link]' ), 'live update: the kit in its own part, before the booking button' );
		delete_option( 'oy_1114d_rev' );
		oy_migrate_1114d();
		ok( 1 === substr_count( get_post_field( 'post_content', $id ), '<h2>Mire lesz szükséged?</h2>' ), '… only once, however often the update runs' );
		ok( get_post_field( 'post_excerpt', $id ) === $hf['excerpt'], 'live update: the card\'s short text too, while it is still the old one' );
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_excerpt' => 'Az én szövegem.' ) ) );
		delete_option( 'oy_1114d_rev' );
		oy_migrate_1114d();
		ok( 'Az én szövegem.' === get_post_field( 'post_excerpt', $id ), '… a short text she wrote herself stays' );
	} finally {
		if ( $made ) {
			wp_delete_post( $id, true );
		} else {
			wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $keep[0], 'post_excerpt' => $keep[1] ) ) );
		}
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'Olívia\'s doc of 14 November, past midnight: the prenatal modules three in a row; the one-to-one page\'s questions as cards, her own words kept', function () {
	$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $css, '.oj-modules--why { grid-template-columns: repeat(auto-fill, minmax(max(12.5rem, calc((100% - 1.5rem) / 3)), 1fr)); }' ), 'the nine prenatal modules: at most three in a row, never a lone ninth („Legyen 3 vagy 4 egy sorban”)' );
	ok( str_contains( $css, '.oj-modules--pairs { grid-template-columns: repeat(auto-fill, minmax(max(14rem, calc((100% - .75rem) / 2)), 1fr)); }' ), 'the one-to-one page\'s four questions two by two' );
	$h  = 'Miben segíthet az egyéni online jóga?';
	$in = "<p>Bevezető</p>\n<h2>$h</h2>\n<h3>Fáj a hátad?</h3>\n<p>A saját szövegem.</p>\n<h3>Fáj a derekad?</h3>\n<p>Második, <strong>vastagon</strong>.</p>\n<h2 id=\"kinek-szol\">Kinek szól</h2>\n<p>x</p>";
	$out = oy_h3_cards( $in, $h );
	ok( str_contains( $out, '<ul class="oj-modules oj-modules--why oj-modules--pairs">' ) && str_contains( $out, '<li><strong>Fáj a hátad?</strong><span>A saját szövegem.</span></li>' ) && str_contains( $out, '<span>Második, <strong>vastagon</strong>.</span>' ) && str_contains( $out, "<h2 id=\"kinek-szol\">Kinek szól</h2>\n<p>x</p>" ) && str_starts_with( $out, "<p>Bevezető</p>\n<h2>$h</h2>" ), 'the questions become cards from whatever is on the page, so the words she changed herself stay („legyen szebben összeszedve”)' );
	$odd = "<h2>$h</h2>\n<p>Bevezető</p>\n<h3>A</h3>\n<p>x</p>\n<h3>B</h3>\n<p>y</p>\n<h2>Z</h2>";
	$two = "<h2>$h</h2>\n<h3>A</h3>\n<p>x</p>\n<p>x2</p>\n<h3>B</h3>\n<p>y</p>\n<h2>Z</h2>";
	ok( oy_h3_cards( $odd, $h ) === $odd && oy_h3_cards( $two, $h ) === $two && oy_h3_cards( '<p>semmi</p>', $h ) === '<p>semmi</p>', '… a part that looks different is left as it is' );
	update_option( 'oy_edition', 'hu-online' );
	$post = get_page_by_path( 'egyeni-online-joga', OBJECT, 'oy_class' );
	$made = ! $post;
	$id   = $post ? $post->ID : wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Egyéni online jóga', 'post_name' => 'egyeni-online-joga' ) );
	$keep = $post ? $post->post_content : '';
	try {
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $in ) ) );
		delete_option( 'oy_1114d_rev' );
		oy_migrate_1114d();
		ok( str_contains( get_post_field( 'post_content', $id ), '<li><strong>Fáj a hátad?</strong><span>A saját szövegem.</span></li>' ), 'live update: the one-to-one page\'s questions as cards' );
	} finally {
		if ( $made ) {
			wp_delete_post( $id, true );
		} else {
			wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $keep ) ) );
		}
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'Olívia\'s doc of 14 November, past midnight: the home page\'s questions – „Sérülésem…” last, „Miért jó online jógázni?” off the home page', function () {
	update_option( 'oy_edition', 'hu-online' );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$home = array_values( array_map( fn( $f ) => $f['q'], array_filter( $data['faqs'], fn( $f ) => '1' === (string) ( $f['home'] ?? '' ) ) ) );
	ok( 'Sérülésem vagy egészségügyi problémám van. Jöhetek?' === end( $home ) && ! in_array( 'Miért jó online jógázni?', $home, true ), 'a fresh site: the injury question is the last on the home page, „Miért jó online jógázni?” only on the FAQ page' );
	ok( in_array( 'Mi van, ha késve érkezem, vagy akadozik a net?', $home, true ), '… and the late-arrival / bad-connection question on the home page too („ezt a kérdést még tedd a gyikhez a főoldalra”)' );
	$oc = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( ! in_array( 'Mennyire nehezek a csoportos jóga órák?', $home, true ), '… „Mennyire nehezek a csoportos jóga órák?” off the home page („így túl hosszú”)' );
	ok( str_contains( $oc, '.oj-facts svg { width: 18px; height: 18px; padding: 0; border-radius: 0; background: none; color: var(--orchid); }' ), 'the three facts without the dark circles („túl csicsás ez a box”)' );
	ok( str_contains( $oc, '.section__head h2, .entry > h2, .oj-split__text h2, .prose > h2 { line-height: 1.26; }' ), 'the big titles: room for the capitals\' accents („belelóg az é betű ékezete a vesszőbe”)' );
	ok( str_contains( $oc, '.oys-private-box p { max-width: none; font-size: .97rem; }' ) && str_contains( $oc, '.oys-private-box p { white-space: nowrap; }' ), 'the one-to-one box under the timetable: its sentence on one line („Ezt próbáljuk meg egy sorba írni”)' );
	ob_start();
	load_template( get_template_directory() . '/template-parts/front-online.php', false );
	$front = (string) ob_get_clean();
	ok( ! str_contains( $front, 'oj-voucher__go' ), 'the gift line without the little arrow („ez a nyilacska nem tetszik”)' );
	ok( ! str_contains( $front, esc_html__( 'Four reasons people choose live online yoga, and stay.', 'olivia-yoga' ) ), 'the line under „Miért jó online jógázni?” is gone („Ezt szedjük ki”)' );
	ok( ! str_contains( $front, esc_html__( 'A one-to-one session is shaped entirely around you. It is for you, for example:', 'olivia-yoga' ) ) && ! str_contains( $data['classes']['egyeni-online-joga']['content'], 'Neked szól például' ), '… and „Az egyéni óra teljesen hozzád igazodik. Neked szól például:” („ezt is szedjük ki”)' );
	$made = array();
	try {
		$made[] = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => 'Miért jó online jógázni?', 'menu_order' => 1 ) );
		$made[] = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => 'Utolsó a főoldalon eddig', 'menu_order' => 40 ) );
		$made[] = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => 'Sérülésem vagy egészségügyi problémám van. Jöhetek?', 'menu_order' => 17 ) );
		update_post_meta( $made[0], 'oy_home', '1' );
		update_post_meta( $made[1], 'oy_home', '1' );
		update_post_meta( $made[2], 'oy_home', '' );
		delete_option( 'oy_1114d_rev' );
		oy_migrate_1114d();
		ok( '' === get_post_meta( $made[0], 'oy_home', true ) && '1' === get_post_meta( $made[2], 'oy_home', true ), 'live update: on and off the home page' );
		ok( (int) get_post_field( 'menu_order', $made[2] ) > 40, '… and the injury question after every other home question' );
		$list = oy_faq_list( true );
		ok( strrpos( $list, 'Sérülésem vagy egészségügyi problémám van. Jöhetek?' ) > strrpos( $list, 'Utolsó a főoldalon eddig' ) && ! str_contains( $list, 'Miért jó online jógázni?' ), '… the home list shows it last' );
	} finally {
		foreach ( $made as $id ) {
			wp_delete_post( $id, true );
		}
		update_option( 'oy_edition', 'fort-myers' );
	}
} );

test( 'Olívia\'s doc of 14 November, past midnight: „Mire lesz szükséged?” up front on every class page, as cards', function () {
	update_option( 'oy_edition', 'hu-online' );
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	update_option( 'oy_edition', 'fort-myers' );
	foreach ( array( 'hatha-flow', 'egyeni-online-joga', 'stretching', 'relaxacio-legzes', 'kismamatorna' ) as $k ) {
		$c = $data['classes'][ $k ]['content'];
		preg_match_all( '~<h2[^>]*>(.*?)</h2>~', $c, $h );
		ok( isset( $h[1][1] ) && str_starts_with( $h[1][1], 'Mire le' ) && str_contains( $c, '<ul class="oj-kit">' ), $k . ': the kit is the second part of the page, as cards („kerüljön jóval előrébb! legyen egy kicsit látványosabb”)' );
	}
	$kit   = '<h2>Mire lesz szükséged?</h2><ul class="oj-kit"><li><b>Új</b></li></ul>';
	$page  = "<h2>Első</h2>\n<p>a</p>\n<h2>Második</h2>\n<p>b</p>\n<h2>Mire lesz szükséged?</h2>\n<p>régi</p>\n<h2>Árak</h2>\n<p>c</p>";
	$out   = oy_kit_up( $page, $kit );
	ok( ! str_contains( $out, 'régi' ) && strpos( $out, 'oj-kit' ) < strpos( $out, 'Második' ) && strpos( $out, 'oj-kit' ) > strpos( $out, 'Első' ) && 1 === substr_count( $out, 'Mire lesz szükséged?' ), 'live update: the old part goes, the cards come after the first part' );
	$her   = "<h2>Első</h2>\n<p>a</p>\n<h2>Más</h2>\n<h2>Mire lehet szükséged?</h2>\n<ul>\n<li>Saját eszközöm</li>\n</ul>\n<p>Saját mondatom.</p>\n<h2>Árak</h2>";
	$out   = oy_kit_up( $her, null );
	ok( str_contains( $out, "<ul class=\"oj-kit\">\n<li>Saját eszközöm</li>" ) && str_contains( $out, 'Saját mondatom.' ) && strpos( $out, 'Mire lehet' ) < strpos( $out, '<h2>Más</h2>' ), '… the prenatal page keeps her own list, only moved and in the card style' );
	ok( oy_kit_up( '<h2>A</h2><p>x</p>', null ) === '<h2>A</h2><p>x</p>', '… a page without that part and without new cards is left alone' );
	$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $css, '.entry ul.oj-kit { list-style: none;' ) && str_contains( $css, '.entry ul.oj-kit li::before {' ), 'the kit cards with the peeking pastel circles' );
} );

test( 'Olívia\'s doc of 14 November, past midnight: the price cards look the same on the prices page; the kit in the booking email; the training card on About', function () {
	$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $css, '.entry .price ul li:not([class])::before { content: ""; position: absolute; left: 0; top: .5em; width: 10px; height: 6px; border-radius: 0;' ), 'the prices page: the cards\' lists with their own ticks, like on the home page („pontosan ugyanúgy nézzen ki mint a főoldalon”)' );
	ok( str_contains( $css, '.entry .credential:not(.credential--aside) { position: relative;' ) && str_contains( $css, 'grid-template-columns: clamp(110px, 24%, 160px) minmax(0, 1fr);' ), 'About: the Fitmummy training as a card, the photo beside the caption („ez itt hülyén néz ki”)' );
	$page = "<h2>Első</h2>\n<h2>Mire lesz szükséged?</h2>\n<ul class=\"oj-kit\">\n<li><b>Jógaszőnyeg</b><span>ez mindenképp kell</span></li>\n<li>Gumiszalag</li>\n</ul>\n<p class=\"oj-kit-device\">Kell még egy kamerás telefon is.</p>\n<h2>Árak</h2>";
	$kit  = oy_kit_items( $page );
	ok( array( array( 'Jógaszőnyeg', 'ez mindenképp kell' ), array( 'Gumiszalag', '' ) ) === $kit['items'] && 'Kell még egy kamerás telefon is.' === $kit['note'], 'the kit of a class page as data: the cards and the line under them' );
	ok( array() === oy_kit_items( '<p>nincs</p>' ), '… nothing for a page without it' );
	$id = wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Teszt kit', 'post_name' => 'teszt-kit-ora', 'post_content' => $page ) );
	try {
		$html = OYS_Emails::kit_html( (object) array( 'class_slug' => 'teszt-kit-ora-duo', 'kind' => 'private' ) );
		ok( str_contains( $html, '<b>Jógaszőnyeg</b> – ez mindenképp kell' ) && str_contains( $html, '<b>Gumiszalag</b></li>' ) && str_contains( $html, 'Kell még egy kamerás telefon is.' ), 'the booking email: what to have ready, from the class\'s page, a pair session too („a visszaigazoló emailbe is írd!”)' );
		ok( '' === OYS_Emails::kit_html( (object) array( 'class_slug' => 'nincs-ilyen-ora', 'kind' => 'group' ) ), '… and nothing when the class has no kit' );
	} finally {
		wp_delete_post( $id, true );
	}
	$id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Csere', 'post_name' => 'email-csere-teszt', 'post_content' => 'Írj: <a href="mailto:Regi.Cim@example.com">regi.cim@example.com</a>' ) );
	$keep = OYS_Settings::get( 'notify_email' );
	$rep  = OYS_Settings::get( 'email_reply_to' );
	try {
		OYS_Settings::update( array( 'notify_email' => 'regi.cim@example.com', 'email_reply_to' => 'regi.cim@example.com' ) );
		oy_swap_email( 'regi.cim@example.com', 'uj@example.com', array( 'notify_email' ) );
		ok( 'Írj: <a href="mailto:uj@example.com">uj@example.com</a>' === get_post_field( 'post_content', $id ), 'the email address: the new one in the page text, the link too („e-mail címnél mindenhol írd át az info@oliviajoga.online-ra”)' );
		ok( 'uj@example.com' === OYS_Settings::get( 'email_reply_to' ) && 'regi.cim@example.com' === OYS_Settings::get( 'notify_email' ), '… and in the booking settings, but the booking notices still go where they went' );
	} finally {
		wp_delete_post( $id, true );
		OYS_Settings::update( array( 'notify_email' => $keep, 'email_reply_to' => $rep ) );
	}
	update_option( 'oy_edition', 'hu-online' );
	ok( '' === oy_pictogram( 'home', 'lilac' ) && '' === do_shortcode( '[oy_picto icon="group" tone="sage"]' ), 'online: no pictograms anywhere, in the templates nor in the page texts („Piktogramokat szedjük ki mindenhonnan”)' );
	$ed = (string) oy_brand( 'email' );
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_contains( oy_pictogram( 'home', 'lilac' ), 'class="picto' ), '… the other sites keep theirs' );
	$oc2 = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $oc2, '#intro .prose > p:not(.btn-row) { position: relative;' ) && str_contains( $oc2, '#intro .prose[data-reveal]:not(.is-visible):not(.is-in) > p:not(.btn-row) { opacity: 0;' ), 'home intro: her paragraphs as cards that come in one by one and lift under the mouse („legyen kicsit interaktívabb”)' );
	update_option( 'oy_edition', 'hu-online' );
	ob_start();
	load_template( get_template_directory() . '/template-parts/front-online.php', false );
	$fr = (string) ob_get_clean();
	update_option( 'oy_edition', 'fort-myers' );
	ok( ! str_contains( $fr, esc_html__( 'One-to-one sessions for a stiff body and an overloaded mind.', 'olivia-yoga' ) ), 'the line under „Stretching, relaxáció és légzés” is gone („Ezt szedjük ki”)' );
	ok( ! str_contains( $fr, 'oj-only-me' ), 'the home page: no „A képedet csak én látom” badge („szedjük ki mindenhonnan”)' );
	$p_pren = strpos( $fr, 'id="kismamatorna"' );
	$p_priv = strpos( $fr, 'id="egyeni"' );
	ok( false !== $p_pren && false !== $p_priv && $p_pren < $p_priv, 'home: the prenatal block comes before the one-to-one block („az egyéni online jógaórát és a kismamatornát a főoldalon cseréljük meg”)' );
	ok( str_contains( $fr, '<section class="section section--pale oj-prenatal"' ) && str_contains( $fr, '<section class="section oj-private"' ), '… each block keeps its own background (the green card of the one-to-one block stays on the light one)' );
	$w_pren = strpos( $fr, esc_html__( 'Prenatal fitness online', 'olivia-yoga' ) . '</h3>' );
	$w_priv = strpos( $fr, esc_html__( 'Individual online yoga', 'olivia-yoga' ) . '</h3>' );
	ok( false !== $w_pren && false !== $w_priv && $w_pren < $w_priv, '… and the boxes at the top in the same order' );
	ok( str_contains( $fr, esc_html__( 'Wednesday evenings together, everyone from home', 'olivia-yoga' ) ) && str_contains( $fr, 'class="steps__title"' ) && ! str_contains( $fr, esc_html__( 'A real class, just in your living room', 'olivia-yoga' ) ), 'home: the block above the next classes is about the live group class, with a short „Így működik” („ez a blokk inkább az élő csoportos online jógáról szóljon röviden”)' );
	ok( str_contains( $oc2, '.steps li::before { content: counter(s);' ) && str_contains( $oc2, '.steps li { grid-template-columns: 2.6rem 1fr; gap: 1rem; align-items: start; padding: .7rem 0; border-bottom: 0; }' ), '… plain numbers in soft circles, no lines between the steps („sima szám, nem 01, 02”)' );
	ok( ! str_contains( $oc2, 'width: 28px; height: 4px' ) && str_contains( $oc2, '.places li::before { content: ""; grid-column: 1; grid-row: 1;' ) && ! str_contains( $oc2, '.places li > div::before' ), '… and round marks instead of the short lines („A vonalak helyett … kerek, körös, letisztult megoldás”), before the title, not under its letters („nem tetszik, hogy belelóg a szövegbe a kör”)' );
	ok( preg_match( '#<h2 id="faq-title">([^<]*)</h2>#', $fr, $fq ) && esc_html__( 'Got a question?', 'olivia-yoga' ) === $fq[1] && ! str_contains( $fr, esc_html__( 'The question everyone asks first, and a few others.', 'olivia-yoga' ) ), 'home FAQ: not the first question as the title („ez kicsit erőltetett, ne ez legyen a fő kérdés”)' );
	$op = oy_opening( '<p>Fél óra, ami csak a megnyugvásról szól.<br>Légzőgyakorlatokkal segítek.</p><h2>Miért?</h2>', 'lilac' );
	ok( str_starts_with( $op, '<div class="oj-opening oj-opening--lilac"><p class="oj-opening__tag">Fél óra, ami csak a megnyugvásról szól.</p><p>Légzőgyakorlatokkal segítek.</p></div><h2>' ), 'class page: the opening lines in a soft box, the first sentence large („ezek az alcímek is legyenek egy kicsit megdesignolva”)' );
	$op2 = oy_opening( '<p class="iu-text_1">Egyéni óra.<br><br>Ez a 60 perc.<br><br>Minden órát felmérés előz meg.</p>', 'sage' );
	ok( 3 === substr_count( $op2, '<p' ) && str_contains( $op2, '<p>Minden órát felmérés előz meg.</p></div>' ), '… an empty line in her text starts a new paragraph in the box' );
	$op3 = oy_opening( '<p>Csoportos órán a csoportnak tanítok.<br>Egyéni órán neked: a te testedre.<br>Videón látlak.</p>', 'pink:2' );
	ok( str_starts_with( $op3, '<div class="oj-opening oj-opening--pink"><p class="oj-opening__pre">Csoportos órán a csoportnak tanítok.</p><p class="oj-opening__tag">Egyéni órán neked: a te testedre.</p><p>Videón látlak.</p></div>' ), '… one-to-one yoga: the „Egyéni órán neked” line is the large one, the line before it small above it („pont az egyéni legyen kiemelve”)' );
	ok( '<h2>Mi történik?</h2><p>Szöveg.</p>' === oy_opening( '<h2>Mi történik?</h2><p>Szöveg.</p>' ) && '<p>Egy mondat.</p>' === oy_opening( '<p>Egy mondat.</p>' ), '… a page that starts otherwise stays as it is' );
	update_option( 'oy_edition', 'hu-online' );
	$pd = oy_pricing_design( '<h2>Csoportos online flow jóga szerdánként</h2><p>Szerdánként 18:30-tól 20:00-ig, 90 percben. A gyakorlás alatt részed lesz légzésben.</p><p>Minden óra élő, itt az oldalon, a böngésződben. Nem kötelező kamerát kapcsolnod, de ha kapcsolsz, csak én látlak.</p><div class="block-wide">x</div>' );
	$pd2 = oy_pricing_design( '<h2>Csoportos</h2><p>A gyakorlás.</p><h2 id="gift-cards">Ajándékutalványok</h2><p>Ajándékozz jógát.</p><p class="btn-row"><a class="btn btn--primary" href="/ajandekutalvany/">Ajándékutalvány vásárlása</a></p><h2>Jó tudni</h2><p><strong>Lemondás:</strong> 12 órával előtte.</p>' . "\n" . '<p><strong>Új vagy a jógában?</strong> Olvasd el.</p>' );
	$ln2 = oy_lines( '<p>Egy.<br>Kettő.</p><p>Három.<br>Négy.</p><div class="oj-secret"><p>Titok.</p></div>' );
	$fa  = wp_insert_post( array( 'post_type' => 'oy_faq', 'post_status' => 'publish', 'post_title' => 'Mit kell előkészítenem az órára? (teszt)', 'post_content' => "Jóga: szőnyeg.\n\nKismamatorna: párna." ) );
	$fah = do_shortcode( '[oy_faq_answer q="Mit kell előkészítenem az órára? (teszt)"]' );
	wp_delete_post( $fa, true );
	$ln  = oy_lines( '<p>Egy.<br>Kettő.</p><div class="oj-note"><p>A.<br>B.</p></div><p class="btn-row">x<br>y</p><p>Csak egy.</p><p>Három.<br>Négy.<br></p>' );
	$fl  = oy_lines( '<h2>Hogyan működik?</h2><p>Kérj időpontot. Visszaírok. Az óra élő.</p><p class="btn-row">gomb</p><h2>Mit csinálunk egy órán?</h2><p>Átmelegítéssel kezdünk, utána nyújtunk, végül relaxáció. Minden alkalom az előzőre épít.</p><h2>Mi történik egy online flow jógaórán?</h2><p>Megérkezünk.<br>Bemelegítünk.</p><p>Lelassulunk.</p><h2>Mire lesz szükséged?</h2><h2>Hogyan zajlik egy élő online óra?</h2><p>Lefoglalod. Megkapod a linket. Böngészőben fut. Belépsz előtte. Látlak.</p>' );
	$an  = oy_answer_lines( '<div class="answer"><p>A tapasztalataim alapján is:<br>– alvászavarok<br>– túlgondolás</p></div><div class="answer"><p>Előbb az orvos.<br>Aztán szólj.</p></div>' );
	$cb = oy_callback_form();
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_contains( $pd, '<div class="oj-info"><ul class="oj-info__facts">' ) && str_contains( $pd, '<p>A gyakorlás alatt részed lesz légzésben.</p></div><div class="block-wide">' ) && ! str_contains( $pd, 'csak én látlak' ) && ! str_contains( $pd, '18:30-tól' ), 'Bejelentkezés és árak: the group facts as chips, her text under them, without what the chips repeat („ezek az infok is legyenek már kicsit megdesignolva”)' );
	$hupo = (string) file_get_contents( get_template_directory() . '/languages/hu_HU.po' );
	ok( str_contains( $pd2, '<span>book ahead</span>' ) && str_contains( $pd2, '<span>starts with at least 3 people</span>' ) && str_contains( $pd2, '<span>beginners welcome</span>' ) && str_contains( $hupo, "msgid \"book ahead\"\nmsgstr \"foglalj előre\"" ) && str_contains( $hupo, "msgstr \"legalább 3 fővel indul\"" ) && str_contains( $hupo, "msgstr \"kezdőket is szeretettel várok\"" ), 'prices page: the note under the timetable as chips next to „a kamera nem kötelező” („ezek az infók is jöhetnek ide”)' );
	ok( str_contains( $pd2, '</h2><div class="oj-gift"><span class="oj-gift__icon" aria-hidden="true">' ) && str_contains( $pd2, '<p>Ajándékozz jógát.</p><p class="btn-row">' ) && str_contains( $pd2, '</a></p></div></div><h2>Jó tudni</h2>' ), '… the gift vouchers as a card of their own, her words and the button inside („szebben feltüntetve”)' );
	ok( str_starts_with( $fah, '<div class="oj-faq-answer">' ) && str_contains( $fah, 'oj-faq-tag--lilac">Jóga</span>' ) && str_contains( $fah, 'oj-faq-tag--sage">Kismamatorna</span>' ), '… „Amire szükséged lesz”: the FAQ answer itself, by class, as on the FAQ page („pontosan ugyanazt, amit a gyiknél, külön szedve”)' );
	ok( str_starts_with( $ln, '<div class="oj-lines oj-lines--lilac"><p>Egy.</p><p>Kettő.</p></div><div class="oj-note"><p>A.<br>B.</p></div><p class="btn-row">x<br>y</p><p>Csak egy.</p><div class="oj-lines oj-lines--pink"><p>Három.</p><p>Négy.</p></div>' ), 'her sentences on lines of their own: a soft box per paragraph, the colour changing; inside a designed box or a button row they stay („Szépítsd ezeket, akár új boxxal”)' );
	ok( str_contains( $fl, '<h2>Hogyan működik?</h2><ol class="oj-flow oj-flow--n3"><li><span class="oj-flow__n" aria-hidden="true">1</span><p>Kérj időpontot.</p></li>' ) && str_contains( $fl, '<p>Az óra élő.</p></li></ol><p class="btn-row">gomb</p>' ) && str_contains( $fl, '<p>Átmelegítéssel kezdünk</p></li><li><span class="oj-flow__n" aria-hidden="true">2</span><p>Utána nyújtunk</p></li><li><span class="oj-flow__n" aria-hidden="true">3</span><p>Végül relaxáció.</p></li></ol><p class="oj-flow__note">Minden alkalom az előzőre épít.</p>' ) && str_contains( $fl, 'oj-flow--n3"><li><span class="oj-flow__n" aria-hidden="true">1</span><p>Megérkezünk.</p>' ) && str_contains( $fl, '<p>Lelassulunk.</p></li></ol><h2>Mire lesz szükséged?</h2>' ) && str_contains( $fl, '<h2>Hogyan zajlik egy élő online óra?</h2><ol class="oj-flow oj-flow--n5">' ) && 5 === substr_count( substr( $fl, (int) strpos( $fl, 'Hogyan zajlik' ) ), '<li>' ), '… „Hogyan működik?”, „Mit csinálunk…”, „Mi történik…”: numbered steps from her lines or sentences („folyamatábra vagy lépésről lépésre ábra az összes aloldalra”)' );
	ok( str_contains( $an, '<p>A tapasztalataim alapján is:</p><ul class="oj-dashes"><li>alvászavarok</li><li>túlgondolás</li></ul>' ) && str_contains( $an, '<p>Előbb az orvos.</p><p>Aztán szólj.</p>' ), '… in the FAQ answers the dashed lines are a list, the other lines paragraphs' );
	ok( str_contains( $pd2, '<div class="oj-keynotes"><div class="oj-keynote oj-keynote--sun"><span class="oj-keynote__icon" aria-hidden="true">' ) && str_contains( $pd2, '<p class="oj-keynote__label">Lemondás</p><p>12 órával előtte.</p>' ) && str_contains( $pd2, 'oj-keynote--lilac' ) && str_contains( $pd2, '<p class="oj-keynote__label">Új vagy a jógában?</p><p>Olvasd el.</p></div></div></div>' ), '… „Lemondás”, „Új vagy a jógában?” as cards of their own, side by side („ezek is külön legyenek szebben kiemelve”)' );
	ok( str_contains( $ln2, '<div class="oj-lines oj-lines--lilac">' ) && str_contains( $ln2, '<div class="oj-lines oj-lines--sage"><p>Három.</p>' ) && ! str_contains( $ln2, 'oj-lines--pink' ), '… a line box right before the pink note is not pink („ez legyen más színű”)' );
	ok( str_contains( $cb, 'name="action" value="oy_callback"' ) && str_contains( $cb, 'type="tel" name="phone"' ) && 1 === substr_count( $cb, '<input type="tel"' ) && '' === oy_callback_form(), 'contact: a „Visszahívást kérek” box with only a phone field, on the online site only' );
	update_option( 'oy_edition', 'hu-online' );
	oy_main_transform_set( 'opening', 'pink' );
	$mt = oy_main_transform( '<main><div class="main-col entry"><p>Egy mondat.<br>Még egy.</p><h2>X</h2></div><aside>a</aside></main>' );
	unset( $GLOBALS['oy_main_x'] );
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_contains( $mt, '<div class="main-col entry"><div class="oj-opening oj-opening--pink"><p class="oj-opening__tag">Egy mondat.</p><p>Még egy.</p></div><h2>X</h2></div><aside>a</aside>' ) || str_contains( $mt, '<div class="main-col entry">' . "\n" . '<div class="oj-opening oj-opening--pink">' ), 'the box is made on the finished page, after Olívia\'s own edits are in (they are keyed by the original text)' );
	ok( has_action( 'template_redirect' ) && str_contains( (string) file_get_contents( get_template_directory() . '/inc/helpers.php' ), "ob_start( 'oy_main_transform' ); // before the page editor's buffer (1000)" ) && ! str_contains( (string) file_get_contents( get_template_directory() . '/single-oy_class.php' ), 'oy_opening(' ), '… not inside the template, where her edits are not in yet' );
	$tail = substr( $oc2, (int) strpos( $oc2, '„ezek a hullámok az egész rólamban bénák, módosíts”' ) );
	$tail = (string) preg_replace( '/[^}]*oj-flow--wave[^}]*\}/', '', $tail ); // the flow class's wave (asked for later) is a line on purpose
	ok( str_contains( $tail, '.entry .oj-chapter::after { content: none; }' ) && str_contains( $tail, '-webkit-mask: none; mask: none; }' ) && ! preg_match( '/stroke=|M2 9C7 3/', $tail ), 'About: round marks instead of the small waves – before the chapters, around the statement, in the lists („ezek a hullámok az egész rólamban bénák”)' );
	$old = get_page_by_path( 'teachers' );
	$tp  = $old ? (int) $old->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Teachers', 'post_name' => 'teachers' ) );
	update_option( 'oy_edition', 'hu-online' );
	$nix = oy_noindex_page_ids();
	$sq  = apply_filters( 'wp_sitemaps_posts_query_args', array(), 'page' );
	update_option( 'oy_edition', 'fort-myers' );
	ok( in_array( $tp, $nix, true ) && in_array( $tp, (array) ( $sq['post__not_in'] ?? array() ), true ) && ! in_array( $tp, oy_noindex_page_ids(), true ), 'sitemap: the noindex pages (booking, account, and the „Teachers” page on the online site) are not in it' );
	if ( ! $old ) {
		wp_delete_post( $tp, true );
	}
	$fn = (string) file_get_contents( get_template_directory() . '/functions.php' );
	ok( str_contains( $fn, "oy_online_only() ? 'Cabin:ital,wght@0,400..700;1,400..700' : 'Quicksand:wght@400..700'" ) && str_contains( $oc2, ':root { --f-text: "Cabin",' ), 'the online site\'s text in Cabin instead of Quicksand („mindenhol ahol quicksand betűtípus van, Cabint szeretnék helyette”); the other sites keep Quicksand' );
	$sj2 = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	ok( str_contains( $sj2, '(function foldPrices() {' ) && str_contains( $sj2, "list.classList.add('oys-pricing--fold')" ) && str_contains( $oc2, '.oys-pricing--fold .price-group:not(.is-open) > :not(.price-group__head) { display: none; }' ), 'prices: each service folds under its title, on desktop and phone, one open at a time („az árak … legyen lenyitható, külön szolgáltatásokra. Egyszerre csak 1”)' );
	ok( str_contains( $fr, 'nem kell semmit letöltened' ) || str_contains( $fr, esc_html__( 'Your own link comes by email and is waiting in your account. It opens in your browser, there is nothing to download.', 'olivia-yoga' ) ), 'the link step says there is nothing to download („írjuk oda, hogy nem kell semmit letöltenie”)' );
	$cj = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true );
	ok( '' === ( $cj['faqs_home']['Tényleg működik a csoportos jóga online?'] ?? null ) && '' === $cj['faqs'][0]['home'], 'home FAQ: „Tényleg működik a csoportos jóga online?” is off the home page, on the live site too („ezt a kérdést szedjük ki a főoldalról”)' );
	ok( str_contains( $oc2, '.section--pale.oj-prenatal { background: var(--lilac-soft); }' ), 'home: the prenatal block on the pastel lilac of the site („a háttere a főoldalon lehet az a pasztell lila, amit használunk”)' );
	update_option( 'oy_edition', 'hu-online' );
	$pg = apply_filters( 'oys_pricing_groups', array() );
	update_option( 'oy_edition', 'fort-myers' );
	ok( isset( $pg['classes'][1] ) && ! str_contains( $pg['classes'][1], 'drop-in' ) && ! str_contains( $pg['classes'][1], 'alkalmi jeggyel' ), 'prices: the group classes without „Gyere alkalmi jeggyel, vagy spórolj bérlettel” („kiszedhetjük”)' );
	$ij = json_decode( (string) file_get_contents( get_template_directory() . '/assets/img/images.json' ), true );
	ok( 7 === count( array_filter( array( 'journal-04', 'journal-10', 'journal-16', 'journal-19', 'journal-20', 'journal-22', 'journal-24' ), fn( $k ) => '50% 22%' === ( $ij[ $k ]['pos_prev'] ?? '' ) && (int) explode( ' ', $ij[ $k ]['pos'] )[1] > 22 ) ), 'Jógáról: seven photos show more of their lower part, where she is („ezeket a képeket is csúsztassuk feljebb”), only where the focus is still the old one' );
	$po = oy_poses( 'tul' ) . oy_poses( 'alul' );
	ok( 14 === substr_count( $po, '<li class="oj-pose">' ) && str_contains( $po, 'Ha túlműködik a pajzsmirigy' ) && str_contains( $po, 'Ha alulműködik a pajzsmirigy' ) && str_contains( $po, '/assets/img/poses/fejenallas-900.jpg 900w' ) && '' === oy_poses( 'x' ), 'thyroid article: the poses for an over- and an underactive thyroid, as cards with her photos („hiányzik a lényeg, a kép”)' );
	ok( 28 === count( glob( get_template_directory() . '/assets/img/poses/*.jpg' ) ), '… the photos ship with the theme, two sizes each' );
	$tp2 = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Pajzsmirigy', 'post_name' => 'pajzsmirigy-harmonizalas-jogaval', 'post_content' => "<p>A jóga erre kiváló eszköz.</p>\n<p>Ezek az ászanák akkor is nagyon jó hatással vannak.</p>" ) );
	update_option( 'oy_edition', 'hu-online' );
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	update_option( 'oy_edition', 'fort-myers' );
	$tc = (string) get_post_field( 'post_content', $tp2 );
	ok( 1 === substr_count( $tc, '[oy_poses set="tul"]' ) && strpos( $tc, '[oy_poses set="alul"]' ) < strpos( $tc, 'Ezek az ászanák akkor is' ) && strpos( $tc, 'kiváló eszköz' ) < strpos( $tc, '[oy_poses' ), '… on the live site the two blocks go in once, before „Ezek az ászanák akkor is…”, her words stay' );
	wp_delete_post( $tp2, true );
	$scp = (string) file_get_contents( get_template_directory() . '/inc/shortcodes.php' );
	$sjs = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	ok( str_contains( $scp, "'<details class=\"oj-price oj-price--pair\"><summary class=\"oj-price__head\">'" ) && str_contains( $scp, "'<div class=\"oj-price oj-price--solo\"><div class=\"oj-price__head\">'" ) && ! str_contains( $scp, 'name="oj-prices-' ) && str_contains( $sjs, "window.matchMedia('(min-width: 700px)')" ) && str_contains( $sjs, "d.open = true; d.classList.add('is-static');" ) && str_contains( $oc2, '.entry details.oj-price > summary.oj-price__head { list-style: none; cursor: pointer; margin: 0; }' ), 'class pages: the price cards alone stay open, the pair cards fold on a phone and are open on a wider screen („a két egyedüli maradhat kinyitva, a párosok maradjanak csak lenyithatóak”)' );
	ok( str_contains( $oc2, '@media (min-width: 1100px) { .nav a { font-size: 1.02rem; } }' ), 'the menu a little larger on desktop („egy picit mehet nagyobb betűméretre a navigációs menü”)' );
	$ab_old = "<h2>Miért lettem jógaoktató?</h2>\n<h3 class=\"oj-chapter\">A kezdetek</h3>\n<p>Gyerekkor.</p>\n[oy_figures photos=\"korall-stretching-circuit,outdoor-group-bands\"]\n<p>Mellette.</p>\n<h3 class=\"oj-chapter\">Amikor jött a jóga</h3>\n<p>Stressz.</p>\n<div class=\"oj-path\">\n<h3>Az utam</h3>\n<ol>\n<li>Gyerekként</li>\n</ol>\n</div>\n<h2>Képzések és tanítás</h2>\n[oy_figure photo=\"olivia-side-plank-festival\" class=\"oj-float oj-float--left\"]\n<p>A 2023-as képzés.</p>\n<p>2026-ban pedig kismamák.</p>\n[oy_fitmummy]\n<div class=\"oj-places\">\n<h3>Ahol eddig tanítottam</h3>\n<p>Helyek.</p>\n</div>\n<h2>A matracon túl</h2>\n<p>Marketing.</p>\n<div class=\"oj-secret\"><p>Titok.</p></div>\n<p>[oy_booking_link label=\"Gyakorolj velem\"]</p>\n<h2>Gyakorolj velem élőben</h2>";
	$abp    = get_page_by_path( 'rolam' );
	$abid   = $abp ? $abp->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rólam', 'post_name' => 'rolam' ) );
	$ab_was = (string) get_post_field( 'post_content', $abid );
	wp_update_post( array( 'ID' => $abid, 'post_content' => $ab_old ) );
	update_option( 'oy_edition', 'hu-online' );
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	update_option( 'oy_edition', 'fort-myers' );
	$ab = (string) get_post_field( 'post_content', $abid );
	ok( str_starts_with( $ab, '<div class="oj-path">' ) && 1 === substr_count( $ab, 'oj-path' ) && str_contains( $ab, "A kezdetek</h3>\n[oy_figures photos=\"about-gym-class,outdoor-group-bands\"]\n<p>Gyerekkor.</p>" ) && str_contains( $ab, "Amikor jött a jóga</h3>\n[oy_figures photos=\"about-rock-warrior,about-meadow-stretch\"]" ) && str_contains( $ab, "<h2>A matracon túl</h2>\n[oy_figure photo=\"journal-15\" class=\"oj-figure--wide\"]" ) && ! str_contains( $ab, 'korall-stretching-circuit' ) && 3 === substr_count( $ab, '[oy_figures' ), 'About: the timeline first, the photos under „A kezdetek”, „Amikor jött a jóga” and „A matracon túl”, once („rólamot még kicsit módosítsuk sorrendben”)' );
	ok( 1 === substr_count( $ab, 'oj-trainings' ) && str_contains( $ab, "<div class=\"oj-trainings\">\n\n[oy_figure photo=\"olivia-side-plank-festival\"]\n\n[oy_fitmummy]\n\n<p>A 2023-as képzés.</p>\n\n<p>2026-ban pedig kismamák.</p>\n\n</div>" ) && ! str_contains( $ab, 'oj-float--left' ), '… the trainings in two columns: her photo over the 2023 training, the Fitmummy card over the 2026 one („ne legyenek üres helyek, legyen szépen eloszlatva”)' );
	ok( str_contains( $ab, "<h3>Ahol eddig tanítottam</h3>\n[oy_figure photo=\"park-class-seated\" class=\"oj-figure--wide\"]\n<p>Helyek.</p>" ) && 1 === substr_count( $ab, 'park-class-seated' ), '… „Ahol eddig tanítottam” gets her festival photo, wide, once' );
	$prid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bejelentkezés és árak', 'post_name' => 'orarend-es-arak', 'post_content' => "<h2>Csoportos</h2>\n[oy_schedule]\n<p>Kérlek, foglalj előre, hogy időben megkapd a linket. Az órák legalább 3 fővel indulnak.</p>\n<h2>Jó tudni</h2>\n<h3>Amire szükséged lesz</h3>\n<ul>\n<li>Szőnyeg</li>\n</ul>\n<p><strong>Bérlet:</strong> 8 hétig.</p>\n<p><strong>Lemondás:</strong> 12 óra.</p>" ) );
	$clid = get_page_by_path( 'online-jogaorak' ) ? 0 : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Online órák', 'post_name' => 'online-jogaorak', 'post_content' => '<span class="oj-guide__text">Fél óra. Egy hosszú nap végén is belefér.</span>' ) );
	$lnid = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Link teszt', 'post_content' => '<p><a href="' . home_url( '/orarend-es-arak/' ) . '">árak</a>, <a href="' . home_url( '/online-jogaorak/hatha-flow/' ) . '">óra</a>, <a href="/online-jogaorak/">órák</a>, a szóban: online-jogaorak/</p>' ) );
	update_option( 'oy_edition', 'hu-online' );
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	update_option( 'oy_edition', 'fort-myers' );
	$prc = (string) get_post_field( 'post_content', $prid );
	ok( 'bejelentkezes-es-arak' === get_post_field( 'post_name', $prid ) && ( ! $clid || 'online-orak' === get_post_field( 'post_name', $clid ) ), 'addresses: /orarend-es-arak/ → /bejelentkezes-es-arak/, /online-jogaorak/ → /online-orak/ („maradt az aloldal neve”)' );
	ok( ! str_contains( $prc, 'foglalj előre' ) && ! str_contains( $prc, 'Bérlet:' ) && str_contains( $prc, "<h3>Amire szükséged lesz</h3>\n[oy_faq_answer q=\"Mit kell előkészítenem\"]\n<p><strong>Lemondás:</strong>" ) && 1 === substr_count( $prc, 'oy_faq_answer' ), '… on the prices page: the note under the timetable out (it is in the chips), no pass paragraph, the kit as the FAQ answer, once' );
	ok( ! $clid || str_contains( (string) get_post_field( 'post_content', $clid ), 'Egy hosszú nap végén vagy a terápiádhoz kiegészítésként.' ), '… the relaxation box: „Egy hosszú nap végén vagy a terápiádhoz kiegészítésként.”' );
	$lnc = (string) get_post_field( 'post_content', $lnid );
	ok( str_contains( $lnc, home_url( '/bejelentkezes-es-arak/' ) ) && str_contains( $lnc, home_url( '/online-orak/hatha-flow/' ) ) && str_contains( $lnc, 'href="/online-orak/"' ) && str_contains( $lnc, 'a szóban: online-jogaorak/' ), '… the links to them on the site follow (only links, not the words)' );
	wp_delete_post( $prid, true );
	wp_delete_post( $lnid, true );
	if ( $clid ) {
		wp_delete_post( $clid, true );
	}
	update_option( 'oy_edition', 'hu-online' );
	ok( 'bejelentkezes-es-arak' === oy_page_slug( 'schedule-pricing' ) && 'online-orak' === oy_page_slug( 'yoga-classes' ) && array( 'page', 'schedule-pricing' ) === OY_ONLINE_MOVED['orarend-es-arak'] && array( 'page', 'yoga-classes' ) === OY_ONLINE_MOVED['online-jogaorak'], '… the theme uses the new addresses, the old ones redirect' );
	update_option( 'oy_edition', 'fort-myers' );
	update_option( 'oy_edition', 'hu-online' );
	$tr = apply_filters( 'the_content', "<div class=\"oj-trainings\">\n\n[oy_figure photo=\"olivia-side-plank-festival\"]\n\n[oy_fitmummy]\n\n<p>A.</p>\n\n<p>B.</p>\n\n</div>" );
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_contains( $tr, '<figure class="oj-figure ' ) && str_contains( $tr, '<figure class="credential' ) && ! preg_match( '~<p>\s*<figure|<br\s*/?>\s*<figure|</figure>\s*<br~', $tr ), '… the two pictures render as figures, not inside paragraphs' );
	$row = '[oy_figures photos="about-painting-embrace,about-painting-mask,about-painting-birds,about-sunset-river,about-henna-arm" class="oj-duo--row"]';
	ok( 1 === substr_count( $ab, $row ) && str_contains( $ab, "<p>Titok.</p></div>\n" . $row . "\n<p>[oy_booking_link" ), '… and „A matracon túl” ends with a row of five smaller photos in her order, after the little secret, before the button, once („a szöveg végére meg kisebben ezek a képek jöhetnek”)' );
	$row4 = '[oy_figures photos="about-painting-embrace,about-painting-birds,about-painting-mask,about-sunset-river" class="oj-duo--row"]';
	wp_update_post( array( 'ID' => $abid, 'post_content' => str_replace( $row, $row4, $ab ) ) );
	update_option( 'oy_edition', 'hu-online' );
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	update_option( 'oy_edition', 'fort-myers' );
	$ab4 = (string) get_post_field( 'post_content', $abid );
	ok( 1 === substr_count( $ab4, $row ) && ! str_contains( $ab4, $row4 ), '… the row that went out with four photos gets her henna as the fifth, in her order' );
	update_option( 'oy_edition', 'hu-online' );
	$rh = do_shortcode( $row );
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_contains( $rh, '<div class="oj-duo oj-duo--row oj-duo--n5">' ) && 5 === substr_count( $rh, '<figure' ) && str_contains( $rh, 'naplementében' ), '… the row is the photo grid with its own class, with Hungarian alt texts' );
	wp_update_post( array( 'ID' => $abid, 'post_content' => $ab_was ) );
	$ij2 = json_decode( (string) file_get_contents( get_template_directory() . '/assets/img/images.json' ), true );
	ok( 9 === count( array_filter( array( 'about-gym-class', 'about-rock-warrior', 'about-meadow-stretch', 'article-fish-pose', 'about-painting-embrace', 'about-painting-birds', 'about-painting-mask', 'about-sunset-river', 'about-henna-arm' ), fn( $k ) => isset( $ij2[ $k ]['hu']['alt'] ) && is_file( get_template_directory() . '/assets/img/' . $k . '-600.jpg' ) && is_file( get_template_directory() . '/assets/img/' . $k . '-1200.jpg' ) ) ), '… her new photos ship with the theme, with Hungarian alt texts' );
	$hcj = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true );
	$exc = array_map( fn( $c ) => (string) ( $c['excerpt'] ?? '' ), $hcj['classes'] );
	ok( str_ends_with( $exc['hatha-flow'], 'egy kis jógafilozófiával fűszerezve.' ) && ! str_contains( $exc['hatha-flow'], 'Kezdőket' ) && str_contains( $exc['egyeni-online-joga'], 'kezdőként tanulnál, gyorsabban fejlődnél vagy a stresszből keresel kiutat. Kezdőket is szeretettel várok.' ) && str_contains( $exc['kismamatorna'], 'jóga és relaxáció kombinációja a trimeszteredhez' ) && str_contains( $exc['stretching'], 'online dinamikus nyújtás és mobilizáció' ) && ! str_contains( $exc['relaxacio-legzes'], 'kíméletes' ) && 5 === count( array_filter( $hcj['classes'], fn( $c ) => ! empty( $c['_excerpt_prev'] ) ) ), 'class cards: her new short texts (philosophy, beginners to one-to-one, dynamic stretching, no gentle stretching), only where the old text is still there' );
	ok( ! str_contains( (string) wp_json_encode( array( $hcj['pages']['classes']['content'], $hcj['classes']['hatha-flow']['content'] ), JSON_UNESCAPED_UNICODE ), 'tudok segíteni a pózokban' ) && in_array( array( 'látlak, és tudok segíteni a pózokban.', 'látlak, és jobban tudlak korrigálni és segíteni.' ), $hcj['pages']['classes']['_replace'], true ), '… „jobban tudlak korrigálni és segíteni” instead of „tudok segíteni a pózokban”' );
	ok( str_contains( (string) $hcj['classes']['relaxacio-legzes']['content'], 'És oda-vissza működik: az idegrendszered állapota meglátszik a légzéseden' ) && str_contains( (string) $hcj['classes']['relaxacio-legzes']['content'], 'a hőérzetedre, a hangulatodra, a tempódra és a kitartásodra' ) && 1 === count( array_filter( (array) $hcj['classes']['relaxacio-legzes']['_replace'], fn( $r ) => str_starts_with( (string) $r[0], '<p>A légzés az egyetlen' ) ) ), 'relaxation: „Miért a légzés?” says it works both ways – calm or energy, warmth, mood, pace, stamina („írd bele szépen megfogalmazva”)' );
	ok( str_contains( (string) file_get_contents( get_template_directory() . '/footer.php' ), "\$link( 'schedule-pricing', oy_schedule_label() )" ), '… the footer link says „Bejelentkezés és árak” too („ezt is írjuk át bejelentkezés és árakra”)' );
	$fmid = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '„Te foglalkozol mással is, vagy csak a jógával?”', 'post_name' => 'foglalkozol-massal-is', 'post_content' => '<p>Legnagyobb meglepetésemre…</p>' ) );
	update_option( 'oy_edition', 'hu-online' );
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	delete_option( 'oy_1114d_rev' );
	oy_migrate_1114d();
	update_option( 'oy_edition', 'fort-myers' );
	$fmp = get_post( $fmid );
	ok( 'Törődni magaddal nem luxus, hanem példamutatás' === $fmp->post_title && str_contains( $fmp->post_content, 'A legtöbb mai népbetegség nem ott kezdődik, ahol fáj' ) && str_ends_with( trim( $fmp->post_content ), '<p><strong>Legyen több olyan gondolat, amiben jó lakni!</strong></p>' ) && ! str_contains( $fmp->post_content, 'boldog új évet' ) && 'torodni-magaddal-nem-luxus' === $fmp->post_name && has_post_thumbnail( $fmid ), 'journal: „Foglalkozol mással is?” becomes her post – her title, „Legyen több olyan gondolat…” at the end, the fish pose photo, the same address' );
	ok( 1 === substr_count( $fmp->post_content, '<ul class="oj-causes">' ) && 8 === substr_count( $fmp->post_content, '<span class="oj-causes__why">' ) && str_contains( $fmp->post_content, '<ol class="oj-chain"><li><span>Gondolat</span></li><li><span>Idegrendszer</span></li><li><span>Hormonrendszer</span></li><li><span>Immunválasz</span></li></ol>' ) && ! preg_match( '#\x{27A1}|\x{1F64F}|<blockquote#u', $fmp->post_content ) && str_contains( $fmp->post_content, 'talpreflexológiával kezdtem.)' ), '… its address follows the title and the emoji give way to the site\'s own cards and chain („itt is javítsd az url slugot és az emojik helyett legyen az oldal stílusához illeszkedő design”)' );
	wp_delete_post( $fmid, true );
	eq( oy_arrow_marks( '<ul>' . "\n" . '<li><strong>A</strong> ➡️ b</li>' . "\n" . '<li><strong>C</strong>➡ d</li>' . "\n" . '</ul>' . "\n" . '<p>Egy ➡️ jó mondat.</p><blockquote><p>x ➡️ y ➡️ z</p></blockquote><p>(Masszázzsal kezdtem 🙏)</p>' ), '<ul class="oj-causes">' . "\n" . '<li><span class="oj-causes__name"><strong>A</strong></span><span class="oj-causes__why">b</span></li>' . "\n" . '<li><span class="oj-causes__name"><strong>C</strong></span><span class="oj-causes__why">d</span></li>' . "\n" . '</ul>' . "\n" . '<p>Egy ➡️ jó mondat.</p><ol class="oj-chain"><li><span>X</span></li><li><span>Y</span></li><li><span>Z</span></li></ol><p>(Masszázzsal kezdtem.)</p>', 'the arrows: a list of „a ➡️ b” becomes cards, a line of two or more arrows a chain, one arrow in a sentence stays, the hands go' );
	$hcj2 = $hcj;
	ok( isset( $hcj2['posts']['torodni-magaddal-nem-luxus'] ) && ! isset( $hcj2['posts']['foglalkozol-massal-is'] ) && ! preg_match( '#\x{27A1}|\x{1F64F}#u', (string) $hcj2['posts']['torodni-magaddal-nem-luxus']['content'] ) && 'torodni-magaddal-nem-luxus' === OY_ONLINE_POST_MOVED['foglalkozol-massal-is'] && 'torodni-magaddal-nem-luxus' === OY_ONLINE_POST_SLUGS['do-you-do-anything-besides-yoga'], '… on a new site too, and both old addresses redirect to the new one in one step' );
	$hdr = (string) file_get_contents( get_template_directory() . '/header.php' );
	ok( str_contains( $hdr, "oy_online_only() ? '002E2C' : '2B5036'" ) && str_contains( $hdr, "oy_online_only() ? 'E63462' : 'EE3F9A'" ), 'the favicon: Evergreen with a Magenta Bloom circle like the logo on the online site, the studio site keeps its own („favicont is igazítsd a jelenlegi színekhez”)' );
	$ocss2 = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $ocss2, ':root { --sandy: #F7934C; --tangerine: #FFAC81; }' ) && str_contains( $ocss2, '.oj-hero { background: var(--sun); }' ) && str_contains( $ocss2, '.oj-hero .oj-trio .oj-breath--w1::before { background: var(--paper); }' ) && str_contains( $ocss2, '.oj-hero .oj-trio .oj-breath--w2::before { background: var(--pink); }' ) && str_contains( $ocss2, '.oj-hero .oj-trio .oj-breath--w3::before { background: var(--tangerine); }' ), 'the hero: Bright Gold, the words on Platinum, Magenta Bloom and Tangerine Dream („háttér egésze legyen ffdf22 színű, jóga legyen f0edee színű hátterű, kismamatorna e63462 és a mobilizáció ffac81”)' );
	update_option( 'oy_edition', 'hu-online' );
	$heads = array();
	foreach ( array( 'relaxacio-legzes', 'egyeni-online-joga', 'hatha-flow', 'kismamatorna' ) as $hs ) {
		preg_match( '/page-head--([a-z]+)/', oy_page_head( 'X', '', array(), 'c' . $hs ), $hm );
		$heads[ $hs ] = $hm[1] ?? '';
	}
	update_option( 'oy_edition', 'fort-myers' );
	eq( $heads, array( 'relaxacio-legzes' => 'cream', 'egyeni-online-joga' => 'one', 'hatha-flow' => 'flow', 'kismamatorna' => 'pre' ), 'the class page heads in each class\'s own colour: relaxation light yellow, one-to-one Tangerine Dream, group flow Evergreen, prenatal Magenta Bloom („az azonos témák legyenek azonos színűek”)' );
	update_option( 'oy_edition', 'hu-online' );
	$hues = array_map( 'oy_class_hue', array( 'hatha-flow', 'kismamatorna', 'egyeni-online-joga', 'stretching', 'relaxacio-legzes' ) );
	ob_start();
	get_template_part( 'template-parts/front-online' );
	$fo = (string) ob_get_clean();
	preg_match( '/page-head--([a-z]+)/', oy_page_head( 'X', '', array(), 'contact' ), $hc );
	preg_match( '/page-head--([a-z]+)/', oy_page_head( 'X', '', array(), 'faq' ), $hf );
	update_option( 'oy_edition', 'fort-myers' );
	eq( $hues, array( 'flow', 'pre', 'one', 'mob', 'rel' ), 'one colour per class on the online site („csoportos óra legyen a zöldünk fehér betűvel, kismamatorna magenta, egyéni online jóga tangerine, stretching … sötétzöld”)' );
	ok( str_contains( $fo, 'way way--flow' ) && str_contains( $fo, 'way way--pre' ) && str_contains( $fo, 'way way--one' ) && str_contains( $fo, 'way way--mob' ) && str_contains( $fo, 'way way--rel' ) && ( ! str_contains( $fo, 'way--photo' ) || str_contains( $fo, '<span class="way__pic">' ) ), '… the home cards wear them, with the class photo and the wavy edge of the page heads when there is one („ezeket a boxokat csináld meg olyasmi stílusban mint ezek”)' );
	eq( array( $hc[1] ?? '', $hf[1] ?? '' ), array( 'sun', 'cream' ), 'contact on Bright Gold, FAQ on the light yellow („itt is legyen a sárgánk”, „krémszínű vagy halvány sárga”)' );
	ok( 'gyakori-kerdesek' === OY_ONLINE_SLUGS['faq'] && array( 'page', 'faq' ) === OY_ONLINE_MOVED['gyik'] && 'csoportos-online-flow-joga' === OY_ONLINE_CLASS_PATHS['hatha-flow'] && 'online-kismamatorna' === OY_ONLINE_CLASS_PATHS['kismamatorna'] && 'online-stretching-es-mobilizacio' === OY_ONLINE_CLASS_PATHS['stretching'] && 'relaxacio-es-legzes' === OY_ONLINE_CLASS_PATHS['relaxacio-legzes'] && has_filter( 'post_type_link' ) && has_filter( 'request' ), 'the addresses follow the titles: FAQ and the four class pages (the classes keep their own name inside, the old addresses redirect) („aloldal urleket ellenőrizd mindenhol”)' );
	ok( str_contains( $fo, 'data-oyp="home-hero"' ) && ! preg_match( '/hero__photo">[^<]*<img[^>]*data-oyp="olivia-golden-backbend"/', $fo ), 'the hero photo has its own key in the page editor, apart from the group class photo („ide tehessek külön képet”)' );
	ok( isset( oy_images()['flow-group-lakeside-deck']['hu']['file'] ) && str_contains( (string) file_get_contents( get_template_directory() . '/inc/demo-import.php' ), "oy_import_image( 'flow-group-lakeside-deck' )" ) && str_contains( $ocss2, '.way--mob .way__pic img, .way--mob .way__pic .photo { object-position: 55% 116% !important;' ) && str_contains( $ocss2, '.price__each { font-size: .92rem; }' ), 'her lakeside deck photo for the group class everywhere, her head in the stretching card, the per person price as big as the per session tag' );
	ok( str_contains( $ocss2, '.entry ol.oj-flow--wave > li:nth-child(n) { --c: var(--sun); --s: var(--card); }' ) && str_contains( $ocss2, "stroke='%23002E2C' stroke-opacity='.55'" ) && str_contains( $ocss2, '.entry .oj-lines--lilac { --tone: var(--sun); --soft: var(--sun-soft); }' ), 'the flow wave in two colours only, the breath box yellow („jó hullám csak ne legyen ennyire színes”, „ez legyen sárga”)' );
	ok( str_contains( $ocss2, '.oj-price--solo { --bg: var(--sun-soft); --edge: var(--sun); }' ) && str_contains( $ocss2, '.price--lilac, .price--paper { --tone: var(--sun-soft);' ) && str_contains( $ocss2, '.page-head--sage { --tone: var(--orchid); }' ), 'the prices yellow on every page, the pair price magenta, the old sage heads magenta („minden aloldalon az árak legyenek sárgák és ez a szín vagy magenta”)' );
	ok( preg_match( '/@media \(max-width: 759px\) \{\s*\.btn-row \.text-link,[^}]*\.site-footer nav li a, \.footer__bottom a \{ display: inline-flex; align-items: center; min-height: 44px; \}/', $ocss2 ) && str_contains( $ocss2, '.oys-private-box__list a { display: flex; align-items: center; min-height: 44px;' ), 'on a phone every link and button outside running text is at least 44px tall („nézd meg az összes gombot”)' );
	$sp_page = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true )['pages']['schedule-pricing'];
	ok( str_contains( $sp_page['content'], 'időpontot tudsz kérni, és hamarosan visszaírok. Az árakat lent találod.</p>' ) && ! str_contains( $sp_page['content'], 'kismamatornába beépül' ) && in_array( array( 'időpontot kérsz, és hamarosan visszaírok. Az árakat lent találod.', 'időpontot tudsz kérni, és hamarosan visszaírok. Az árakat lent találod.' ), $sp_page['_replace'], true ), 'prices page: the second paragraph out, the first says she can ask for a time („a második bekezdést szedjük ki … időpontot tudsz kérni”)' );
	update_option( 'oy_edition', 'hu-online' );
	$lt = array( oy_login_text( 'x', 'Get New Password', 'default' ), oy_login_text( 'x', 'Username or Email Address', 'default' ), oy_login_text( 'x', 'Get New Password', 'olivia-studio' ), oy_login_text( 'x', '<strong>Error:</strong> The email could not be sent. Your site may not be correctly configured.', 'default' ) );
	update_option( 'oy_edition', 'fort-myers' );
	ok( 'Új jelszót kérek' === $lt[0] && 'E-mail cím' === $lt[1] && 'x' === $lt[2] && str_starts_with( $lt[3], '<strong>Hoppá:</strong>' ) && has_filter( 'login_headerurl' ) && has_filter( 'retrieve_password_notification_email' ) && ! empty( has_filter( 'login_display_language_dropdown' ) ), 'the forgotten password screens in the site\'s look and voice, the way back to the account page, the reset email in the studio\'s look („kérj elfelejtett jelszót stb.”)' );
	eq( trim( oy_md( '<h2>Árak</h2><p>Egy <strong>óra</strong> <a href="/rolam/">Rólam</a></p><ul><li>egy</li><li>kettő</li></ul><nav><a href="/x/">menü</a></nav><button>Gomb</button>' ) ), "## Árak\n\nEgy **óra** [Rólam](" . home_url( '/rolam/' ) . ")\n\n- egy\n- kettő", 'pages as Markdown for the language models: headings, emphasis, links, lists; no menus or buttons' );
	update_option( 'oy_edition', 'hu-online' );
	oy_llms_flush();
	$llms = oy_llms_txt();
	$full = oy_llms_full();
	$robots = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", true );
	$lgraph = oy_seo_online_graph( array( array( '@type' => 'Person', '@id' => 'x#olivia' ), array( '@type' => 'Organization', '@id' => 'x#business', 'makesOffer' => array() ) ), home_url( '/' ) );
	update_option( 'oy_edition', 'fort-myers' );
	oy_llms_flush();
	$sections = preg_split( '/^## .*$/m', $llms );
	$listed   = true;
	foreach ( array_slice( $sections, 1 ) as $sec ) {
		foreach ( array_filter( array_map( 'trim', explode( "\n", $sec ) ) ) as $line ) {
			$listed = $listed && (bool) preg_match( '/^- \[[^\]]+\]\([^)]+\)/', $line );
		}
	}
	ok( 1 === preg_match_all( '/^# /m', $llms ) && str_starts_with( $llms, '# ' ) && str_contains( $llms, "\n> " ) && $listed && str_contains( $llms, '## Online órák' ) && str_contains( $llms, "\n## Optional\n" ) && str_contains( $llms, 'online kismamatorna' ) && str_contains( $llms, 'online jóga' ) && str_contains( $llms, 'Yoga Alliance International' ) && str_contains( $llms, '/llms-full.txt' ) && ! str_contains( $llms, '?p=' ) && strlen( $llms ) < 12000, 'llms.txt as llmstxt.org describes it: one H1, a summary in a quote, facts without headings (with the main Hungarian searches), then H2 sections of links only, the secondary ones under Optional; small enough for a model\'s context' );
	ok( str_contains( $full, '# Teljes tartalom' ) && strlen( $full ) > strlen( $llms ), '… and llms-full.txt with the text of every class, page, question and post' );
	ok( str_contains( $robots, 'User-agent: OAI-SearchBot' ) && str_contains( $robots, 'User-agent: GPTBot' ) && str_contains( $robots, 'User-agent: ClaudeBot' ) && str_contains( $robots, 'User-agent: Claude-SearchBot' ) && str_contains( $robots, 'User-agent: Google-Extended' ) && str_contains( $robots, 'User-agent: PerplexityBot' ) && ! str_contains( $robots, 'anthropic-ai' ) && str_contains( $robots, '/llms.txt' ), 'robots.txt lets the AI crawlers in by name (OpenAI, Anthropic, Google, Perplexity …) and points to llms.txt' );
	ok( 'Jógaoktató, pre- és postnatális tréner' === $lgraph[0]['jobTitle'] && 3 === count( $lgraph[0]['hasCredential'] ) && isset( $lgraph[1]['hasOfferCatalog'] ) && ! isset( $lgraph[1]['makesOffer'] ), 'structured data in Hungarian: her real qualifications, the classes as a catalogue of services' );
	ok( (bool) preg_match( '/^[a-f0-9]{32}$/', oy_indexnow_key() ) && false === oy_indexnow_send( array( home_url( '/' ) ) ) && has_action( 'oy_indexnow_all' ), 'IndexNow: the site has its own key; a local or test copy never announces itself' );
	update_option( 'oy_edition', 'hu-online' );
	$icon  = get_site_icon_url( 32 );
	$thx   = oy_thanks();
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_ends_with( $icon, '/assets/img/favicon-online-32.png' ) && is_file( get_template_directory() . '/assets/img/og-online.jpg' ) && is_file( get_template_directory() . '/assets/img/apple-touch-icon-online.png' ), 'the online site has real icon files (not WordPress\'s logo at /favicon.ico) and a 1200×630 share picture' );
	$was = get_current_user_id();
	wp_set_current_user( 0 );
	$eps = apply_filters( 'rest_endpoints', array( '/wp/v2/users' => array(), '/wp/v2/users/(?P<id>[\d]+)' => array(), '/wp/v2/pages' => array() ) );
	wp_set_current_user( $was );
	ok( array( '/wp/v2/pages' ) === array_keys( $eps ), 'the REST user list is not public (it showed the login names)' );
	ok( str_contains( $thx, 'Amíg vársz' ) && str_contains( $thx, 'Vissza a főoldalra' ) && function_exists( 'oy_migrate_1116' ), 'a thank-you page after a call-back request or a message („nincs köszönő oldal”)' );
	update_option( 'oy_edition', 'hu-online' );
	$_GET['t'] = 'visszahivas';
	$thx2      = oy_thanks();
	unset( $_GET['t'] );
	ob_start();
	oy_consent_head();
	$gah = ob_get_clean();
	ob_start();
	oy_consent_bar();
	$gab = ob_get_clean();
	$gaf = oy_consent_footer_link();
	$ga_on = oy_ga4_id();
	$priv_was  = get_option( 'wp_page_for_privacy_policy' );
	$priv_id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Adatvédelem (teszt)', 'post_name' => 'adatvedelem-teszt' ) );
	update_option( 'wp_page_for_privacy_policy', $priv_id );
	wp_update_post( array( 'ID' => $priv_id, 'post_content' => "<p>Olívia saját bekezdése.</p>\n<h2>Sütik</h2>\n<p>Az oldal csak a működéséhez szükséges sütiket használja (például hogy bejelentkezve maradj a fiókodban). Reklám- vagy követő sütit nem használunk.</p>\n<h2>Kik férnek hozzá?</h2>\n<p>Az oktató, valamint azok a szolgáltatók, akik nélkül az oldal nem működne: a weboldal üzemeltetője és tárhelye (HelloProVision), a Stripe (fizetés), az Intuit QuickBooks (könyvelés) és a LiveKit (élő videó). Adatot nem adunk el, és reklámcélra nem adunk át senkinek.</p>\n<h2>A jogaid</h2>" ) );
	$ga_rev = get_option( 'oy_ga4_rev' );
	delete_option( 'oy_ga4_rev' );
	$galog = oy_migrate_ga4();
	$galog2 = oy_migrate_ga4();
	$gapriv = get_post( $priv_id )->post_content;
	wp_delete_post( $priv_id, true );
	update_option( 'wp_page_for_privacy_policy', $priv_was );
	false === $ga_rev ? delete_option( 'oy_ga4_rev' ) : update_option( 'oy_ga4_rev', $ga_rev );
	update_option( 'oy_edition', 'fort-myers' );
	$ga_other = oy_ga4_id();
	ok( 'G-WM1P5R67VJ' === $ga_on && '' === $ga_other, 'GA4 only where it was set up: the online site\'s measurement ID, nothing on the other editions' );
	ok( str_contains( $gah, "gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied'" ) && str_contains( $gah, '"G-WM1P5R67VJ"' ) && ! preg_match( '~<script[^>]+src=["\']https://www\.googletagmanager\.com~', $gah ) && str_contains( $gah, 'if (/^(all|stats|granted)$/.test(c)) { w.oyLoadGa(); }' ) && ! str_contains( $gah, 'ad_storage: \'granted\'' ) && str_contains( $gah, 'px = ""' ), 'Google Analytics only after a yes: Consent Mode v2 all denied by default, gtag.js not loaded until the visitor accepts, and a yes is for measuring only, never ads („sütibannerrel – hozzájárulás után töltődjön”)' );
	ok( str_contains( $gab, 'id="oy-consent"' ) && str_contains( $gab, '>Elfogadom</button>' ) && str_contains( $gab, '>Csak a szükségesek</button>' ) && str_contains( $gab, 'Adatkezelési tájékoztató</a>' ) && str_contains( $gab, "d.cookie = 'oy_consent=' + v" ) && ! str_contains( $gab, 'Beállítások</button>' ) && ! str_contains( $gab, 'Meta' ) && str_contains( $gaf, 'href="#cookie-settings">Sütibeállítások</a>' ), '… the cookie bar in Hungarian, as easy to say no as yes, and „Sütibeállítások” in the footer opens it again' );
	ok( str_contains( $thx2, 'gtag("event","generate_lead",{lead_type:"visszahivas"})' ) && str_contains( $thx2, 'event:"generate_lead"' ), '… the thank-you page counts a lead in GA4 („a köszönőoldal mérési eseménye maradjon”)' );
	ok( str_contains( $gapriv, 'Olívia saját bekezdése.' ) && str_contains( $gapriv, 'a Google Analytics (Google Ireland Limited) is méri' ) && str_contains( $gapriv, '<a href="#cookie-settings">Sütibeállítások</a>' ) && str_contains( $gapriv, 'ha hozzájárulsz, a Google Analytics (az oldal látogatottságának mérése)' ) && ! str_contains( $gapriv, 'Reklám- vagy követő sütit nem használunk' ) && 1 === count( $galog ) && array() === $galog2, '… and the privacy page tells about it: only its two paragraphs change, the rest of the page stays as Olívia left it' );
	update_option( 'oy_edition', 'hu-online' );
	update_option( 'oy_meta_pixel', '1234567890123456' );
	ob_start();
	oy_consent_head();
	$pxh = ob_get_clean();
	ob_start();
	oy_consent_bar();
	$pxb = ob_get_clean();
	$pxp = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Adatvédelem (pixel teszt)', 'post_name' => 'adatvedelem-pixel-teszt', 'post_content' => $gapriv ) );
	$px_was = get_option( 'wp_page_for_privacy_policy' );
	update_option( 'wp_page_for_privacy_policy', $pxp );
	$GLOBALS['post'] = get_post( $pxp );
	setup_postdata( $GLOBALS['post'] );
	$pxc = apply_filters( 'the_content', $gapriv );
	update_option( 'oy_meta_pixel', '' );
	$pxc0 = apply_filters( 'the_content', $gapriv );
	wp_reset_postdata();
	wp_delete_post( $pxp, true );
	update_option( 'wp_page_for_privacy_policy', $px_was );
	update_option( 'oy_edition', 'fort-myers' );
	$px_reg = get_registered_settings();
	ok( str_contains( $pxh, 'px = "1234567890123456"' ) && str_contains( $pxh, 'if (/^(all|ads)$/.test(c)) { w.oyLoadPixel(); }' ) && ! preg_match( '~<script[^>]+src=["\']https://connect\.facebook\.net~', $pxh ) && isset( $px_reg['oy_meta_pixel'] ) && ! empty( $px_reg['oy_meta_pixel']['show_in_rest'] ), 'Meta Pixel („csinálj meta pixelt Oliviának”): set by its ID through the REST settings; fbevents.js only after a yes to marketing – a statistics-only yes of before is never taken for one' );
	ok( str_contains( $pxb, 'name="oy-stats"' ) && str_contains( $pxb, 'name="oy-ads"' ) && str_contains( $pxb, '>Beállítások</button>' ) && str_contains( $pxb, 'a Meta (Facebook, Instagram) pedig azt, hogy mit érnek el a hirdetéseink' ) && str_contains( $pxb, '(px ? /^(all|stats|ads|denied)$/ : /^(all|stats|ads|denied|granted)$/)' ), '… the bar then names both, and „Beállítások” lets the visitor say yes to statistics and marketing one by one' );
	ok( str_contains( $pxc, 'Meta Pixel (Meta Platforms Ireland Limited)' ) && str_contains( $pxc, 'és a Meta (a hirdetéseink mérése)' ) && ! str_contains( $pxc, 'Reklámsütit nem használunk' ) && ! str_contains( $pxc0, 'Meta Pixel' ) && str_contains( $pxc0, 'Reklámsütit nem használunk' ) && str_contains( $thx2, 'fbq("track","Lead"' ), '… the privacy page tells about Meta only while the pixel is on, and the thank-you page sends a Lead to Meta' );
	$ft16 = (string) file_get_contents( get_template_directory() . '/footer.php' );
	$js16 = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	$oc17 = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $ft16, '<nav class="tabbar" aria-label="Gyors menü" data-action-bar data-tabbar>' ) && str_contains( $ft16, "array( oy_book_url(), 'calendar', 'Foglalás', 'book' === \$oy_here, true )" ) && str_contains( $ft16, 'class="tabbar__item tabbar__menu" type="button" aria-expanded="false" aria-controls="drawer" data-nav-toggle' ) && str_contains( $js16, "document.querySelectorAll('[data-nav-toggle]')" ) && str_contains( $oc17, '.site-header .nav-toggle, .site-header .header-cta { display: none; }' ) && str_contains( $oc17, '.tabbar__item--main { margin-top: -26px; }' ), 'on a phone the online site works like an app: only the logo on top, one bar at the bottom – Órák · Foglalás (raised) · Fiók · Menü, the menu opens and closes from there (Áron: „App-szerű legyen”)' );
	$fe2  = (string) file_get_contents( WP_PLUGIN_DIR . '/olivia-studio/includes/class-frontend.php' );
	$oc18 = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( str_contains( $fe2, '<div class="oys-account__hello"><h2>' ) && ! str_contains( $fe2, "'</a></nav><div class=\"oys-account__body\">'" ) && str_contains( (string) file_get_contents( get_template_directory() . '/page.php' ), "'account' === \$studio && is_user_logged_in()" ) && str_contains( $oc18, '.entry .oys-account__nav { display: flex; flex-wrap: nowrap; gap: .3rem; overflow-x: auto;' ) && str_contains( $oc18, '.entry .oys-card { border: 0; border-radius: 24px; background: #fff;' ) && ! preg_match( '/\.entry \.oys-[a-z_-]+[^{]*\{[^}]*box-shadow: \d+px \d+px 0 /', $oc18 ), 'the account in the site\'s look: a greeting card with „Kilépés” and the numbers, the tabs in one row, white rounded cards – no black frames or hard shadows in the booking screens (Áron: „Ez így csúnya nagyon … illeszkedjen mindenhez”)' );
	switch_to_locale( 'hu_HU' );
	$done_fmt = oys_date_format( 'l, F j \a\t g:i a' );
	restore_previous_locale();
	$fe = (string) file_get_contents( WP_PLUGIN_DIR . '/olivia-studio/includes/class-frontend.php' );
	ok( 'F j., l, G:i' === $done_fmt && str_contains( $fe, '<dl class="oys-done__ticket">' ) && str_contains( $fe, "'<span class=\"oys-done__badge\" aria-hidden=\"true\">' . oys_icon( 'check' )" ) && str_contains( (string) file_get_contents( get_template_directory() . '/page.php' ), "' layout--return'" ), 'after paying: a tick, the booking as a small ticket (class, when, where), the date in Hungarian („december 16., szerda, 18:30”, not „… at 18:30”), and no big head over it (Áron: „Nem tetszik”)' );
	$oc16 = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	$oc16 = substr( $oc16, (int) strpos( $oc16, 'Olívia, 16 November' ) );
	ok( str_contains( $oc16, '.oj-class-card--flow:hover, .oj-class-card--pre:hover, .oj-class-card--mob:hover' ) && str_contains( $oc16, '.oj-class-card:hover, .oj-class-card:focus-visible { color: var(--ink); }' ), 'Olívia, 16 November: the class cards keep their text colour on hover („ugyanolyan színű a felirat mint a háttér ha ráviszem a kurzort”)' );
	ok( false === apply_filters( 'wp_img_tag_add_auto_sizes', true ), '… pictures in the cards are chosen by our sizes, not „auto” (a wide photo cropped to 4:3 came out blurred: „kicsiben nagyon szétesett a kép minősége”)' );
	ok( str_contains( $oc16, '.entry ol.oj-flow--wave > li > .oj-flow__n { box-shadow: none !important; }' ) && str_contains( $oc16, 'top: 1.35rem; bottom: calc(-1rem - 1.35rem);' ), '… the wave runs on unbroken: the dots sit on it, and on a phone each piece goes from one dot\'s centre to the next („mobilon kicsit szakadozik a vonal”)' );
	ok( str_contains( $oc16, '.section--pale.oj-prenatal { background: var(--sun-soft); }' ) && str_contains( $oc16, '.oj-prenatal .oj-waved--soft > .oj-blob:not(.oj-brush) .oj-blob__front { fill: var(--tangerine); }' ), '… home, prenatal block: light yellow ground, Tangerine and light yellow behind the photo instead of green and yellow' );
	update_option( 'oy_edition', 'hu-online' );
	$cl_in  = '<h2 id="egyeni-orak">Egyéni online órák</h2><p>Csak rád figyelek.</p><ul><li>Kezdőknek</li></ul><p>Időpontot a fiókodban kérhetsz.</p><h2>Hogyan zajlik egy élő online óra?</h2><p>x</p>';
	$cl_out = oy_private_callout_html( $cl_in );
	$cl_off = oy_private_callout( $cl_in ); // not on the Online órák page: as it was
	update_option( 'oy_edition', 'fort-myers' );
	ok( str_contains( $cl_out, '<section class="oj-callout oj-callout--one" aria-labelledby="egyeni-orak"><h2 id="egyeni-orak">Egyéni online órák</h2><p>Csak rád figyelek.</p><ul class="oj-callout__list"><li>Kezdőknek</li></ul>' ) && str_contains( $cl_out, 'topic=egyeni-joga' ) && str_contains( $cl_out, '>Időpontot kérek</a>' ) && str_contains( $cl_out, 'Időpontot a fiókodban kérhetsz.</p><p class="btn-row oj-callout__cta">' ) && str_contains( $cl_out, '</section>' . "\n" . '<h2>Hogyan zajlik' ) && $cl_out === oy_private_callout_html( $cl_out ) && $cl_in === $cl_off && has_filter( 'the_content', 'oy_private_callout' ), '… the Online órák page: „Egyéni online órák” in a box of its own with „Időpontot kérek” („legyen szebben kiemelve és legyen cta”), her text untouched' );
	ok( str_contains( $ocss2, '.page-head--sandy { --tone: var(--sandy); }' ) && str_contains( $ocss2, '.page-head--forest { --tone: var(--forest); }' ) && str_contains( $ocss2, '.page-head--tangerine { --tone: var(--tangerine); }' ) && str_contains( $ocss2, '.page-head--forest h1, .page-head--forest .lead { color: #fff; }' ), '… on the Evergreen head the title and the text are white' );
	ok( str_contains( $ocss2, '.way--lilac { --tone: var(--lilac-soft); }' ) && str_contains( $ocss2, '.page-head--lilac { --tone: var(--lilac-soft); }' ) && ! str_contains( $ocss2, 'rgba(166, 235, 205' ), 'no mint anywhere: the old mint tone is Tangerine Dream, whole cards and heads its light peach („ezeket a kék háttereket hagyjuk el mindenhol, figyelj az új színekre”)' );
	ok( str_contains( $ocss2, '.oj-hero .hero__visual > .oj-blob:not(.oj-brush) .oj-blob__back { fill: var(--forest); }' ), 'the hero: the back wave behind the photo Evergreen („itt a hátsó hullám még legyen a zöld szín”)' );
	ok( str_contains( $ocss2, '.nav .sub-menu a:hover, .nav .sub-menu a:focus-visible { background: var(--sun); }' ), 'the classes dropdown: the line under the cursor in Bright Gold („legyen ugyanaz a sárga szín mint amit most küldtem a palettán”)' );
	ok( str_contains( (string) file_get_contents( get_template_directory() . '/template-parts/front-online.php' ), "oy_class_url( 'hatha-flow' ), 'group', 'tangerine', 'hatha-flow' )" ) && str_contains( $ocss2, '.way--tangerine { --tone: var(--tangerine); }' ) && str_contains( $ocss2, '.way--tangerine .way__art .wa-3 { fill: var(--sandy); }' ) && str_contains( $ocss2, '.section--pale.oj-prenatal { background: var(--sage-soft); }' ), 'the home page: the group flow card in Tangerine Dream, the prenatal section on the light sage („ez is legyen a tangerine dream színű a főoldalon”, „itt legyen valami semlegesebb, szebb színű háttér”)' );
	ok( str_contains( $ocss2, '.hatha__orb--moon span { color: var(--ink); transform: translateX(-8%); }' ), 'the moon of the home page: THA dark on the lit part, so it shows on Frozen Water („nem látszik a felirat”)' );
	ok( str_contains( $ocss2, '.oj-hero .hero__visual > .oj-blob:not(.oj-brush) .oj-blob__front { fill: var(--paper); }' ) && str_contains( $ocss2, '.oj-waved--soft > .oj-blob:not(.oj-brush) .oj-blob__front { fill: var(--forest); }' ) && str_contains( $ocss2, '.oj-brush .oj-blob__back { fill: var(--sun); }' ) && str_contains( $ocss2, '.session__time { background: var(--sun); }' ) && str_contains( $ocss2, 'body:has([data-action-bar]) .book-fab { display: none; }' ) && str_contains( $ocss2, '.drawer .sub-menu .current-menu-item > a { background: var(--sun);' ) && str_contains( $ocss2, '#intro .prose > p:not(.btn-row) { padding-right: 3.6rem; }' ), 'her long list: the waves in the palette, the hero\'s front wave Platinum, yellow time boxes, one booking button on a phone, yellow classes in the phone menu, About\'s text clear of the circles' );
	ok( str_contains( oy_flows( '<h2>Mi történik egy online flow jógaórán?</h2><p>Megérkezéssel kezdünk.<br>Aztán bemelegítünk.<br>Van, ami a légzéssel áramlik.<br>Az óra utolsó része lelassul.</p>' ), 'oj-flow--wave' ) && ! str_contains( oy_flows( '<h2>Hogyan működik?</h2><p>Egy.<br>Kettő.<br>Három.</p>' ), 'oj-flow--wave' ) && str_contains( $ocss2, '.entry ol.oj-flow--wave.oj-flow--n4 > li:nth-child(3) { --y: 26px; }' ), 'the flow class: its steps as a wave rising to the focus and down to the slow end, instead of a flow chart („folyamatábra helyett legyen valami kreatív hullám”)' );
	ok( str_contains( $ocss2, '.way__art .wa-3 { fill: var(--tangerine); opacity: 1; }' ) && str_contains( $ocss2, '.oj-fit__item::before, .oj-fit__item::after { display: none; }' ) && str_contains( $ocss2, '.section--pale.oj-prenatal .oj-modules li, .oj-modules:not(.oj-modules--why) li { background: #fff; }' ) && str_contains( $ocss2, '.oj-modules:not(.oj-modules--why) li:nth-child(3n) { --dot: var(--fern); }' ), 'the class cards\' circle in Tangerine Dream, the „who is it for” cards cleaner, the prenatal tiles in natural greens' );
	$ocss = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	$pal = substr( $ocss, (int) strpos( $ocss, '--forest: #002E2C;' ) - 40 );
	ok( str_contains( $pal, '--forest: #002E2C;' ) && str_contains( $pal, '--lilac-soft: #FFE2CC;' ) && str_contains( $pal, '--sun: #FFDF22;' ) && str_contains( $pal, '--pink: #E63462;' ) && str_contains( $pal, '--orchid: #DB2E5C;' ) && str_contains( $pal, '--paper: #F0EDEE;' ) && str_contains( $pal, '--sage-soft: #DEE5D3;' ) && ! preg_match( '/#D92B86|#9C6ADE|rgba\(217, 43, 134/i', substr( $ocss, 0, (int) strpos( $ocss, '--forest: #013220;' ) ) ) && str_contains( $ocss, ':root { --lilac: #FFAC81; --lilac-soft: #FFE2CC; --lilac-mark: #FFAC81; }' ) && ! preg_match( '/#(DAFFEF|EFFFF8|B9F0D8|A6EBCD)\b/i', substr( $ocss, (int) strpos( $ocss, '--forest: #002E2C;' ) ) ) && ! str_contains( $ocss, '.way--lilac { --tone: var(--lilac-mark); }' ), 'online site: her newest palette everywhere – Evergreen #002E2C, Platinum, Bright Gold, Magenta Bloom, Tangerine Dream, with the Frozen Water and light sage she kept („legyen ez az új paletta, ez alapján cserélj, de figyelj az előző szabályokra”)' );
	ok( str_contains( $ocss, '.oj-logo [fill="#D92B86"] { fill: var(--orchid); }' ) && str_contains( (string) file_get_contents( get_template_directory() . '/header.php' ), "oy_online_only() ? '#002E2C'" ), '… her logo and the browser colour too' );
	ok( str_contains( $ocss, '.oj-fit__item { padding: 1.3rem 1.4rem 1.35rem; border-radius: 24px; background: linear-gradient(' ), 'home: the one-to-one block\'s four cards a little nicer („ezek legyenek kicsit szebbek”)' );
	ok( str_contains( $ocss, '.oj-area { --soft: var(--lilac-soft);' ) && str_contains( $ocss, '.entry .oj-area h3 a::after { content: ""; position: absolute; inset: 0;' ), 'About, „Amiben ma segítek”: new cards – pastel, a circle in the corner, the whole card a link („az elosztás jó, de a design legyen más”)' );
	ok( str_contains( $ocss, '.section--pale.oj-prenatal .oj-modules li { background: var(--sage-soft); }' ), 'Home: the prenatal module tiles are pastel green on the lilac section („Ezeknél lehet az a pasztell zöld szín”)' );
	$sj = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	ok( str_contains( $sj, "window.matchMedia('(max-width: 699px)')" ) && str_contains( $sj, "ul.classList.add('oj-modules--fold')" ) && str_contains( $oc2, '.oj-modules--fold li > span { display: none; }' ), 'the prenatal cards fold on a phone, a tap opens one („ezek legyenek lenyithatóak mert mobilon így túl hosszú lesz”)' );
	ok( preg_match( '/prefers-reduced-motion: no-preference\) \{[^@]*\.hatha__orb--sun \{[^}]*animation: oj-ha 12s/', $oc2 ) && str_contains( $oc2, '@keyframes oj-ha {' ) && str_contains( $oc2, '@keyframes oj-tha-fill {' ) && str_contains( $fr, '<span class="hatha__word" aria-hidden="true">Hatha</span>' ), 'Ha–Tha: the sun and the moon drift into one circle with HATHA across it, and part again („egyik átmegy a másikba és oda-vissza”); only with motion allowed' );
	$ft = (string) file_get_contents( get_template_directory() . '/footer.php' );
	ok( str_contains( $ft, '<a href="https://aronritok.com/" target="_blank" rel="noopener">Ritók-Filip Áron</a>' ) && ! str_contains( $ft, '>HelloProVision</a>' ), 'online footer: made by Áron, linked to his own page („itt Áron neve legyen és Áron egyéni oldala legyen belinkelve”), in a new window (Olívia, 16 November)' );
	ok( str_contains( $ft, '<a href="<?php echo esc_url( $oy_l[1] ); ?>" target="_blank" rel="noopener">' ) && str_contains( $ft, "oy_page_link( 'privacy-policy' ) ); ?>\" target=\"_blank\" rel=\"noopener\">" ) && str_contains( (string) file_get_contents( get_template_directory() . '/inc/helpers.php' ), "add_action( 'wp_footer', 'oy_new_window_script', 99 );" ), 'the ÁSZF, impresszum and privacy links open in a new window, in the footer and in page texts (Olívia, 16 November)' );
	update_option( 'oy_edition', 'hu-online' );
	$cd = json_decode( (string) file_get_contents( oy_content_file() ), true );
	update_option( 'oy_edition', 'fort-myers' );
	ok( ! str_contains( (string) wp_json_encode( array( $cd['faqs'], $cd['pages'], $cd['classes'] ), JSON_UNESCAPED_UNICODE ), 'A képedet csak én látom' ), '… nowhere in the texts: live we can see each other, only the recording shows just Olívia' );
	$tid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Kamera', 'post_name' => 'kamera-teszt', 'post_content' => 'Ha bekapcsolod, igen. A képedet csak én látom, a többiek nem. Vége.' ) );
	oy_swap_text( 'A képedet csak én látom, a többiek nem.', 'Élőben a többiek is láthatnak.' );
	ok( 'Ha bekapcsolod, igen. Élőben a többiek is láthatnak. Vége.' === get_post_field( 'post_content', $tid ), 'live update: the sentence changes where it is written' );
	wp_delete_post( $tid, true );
	$lj = (string) file_get_contents( WP_PLUGIN_DIR . '/olivia-studio/assets/live.js' );
	ok( str_contains( $lj, 'function seesEveryone()' ) && str_contains( $lj, 'autoSubscribe: seesEveryone()' ) && 2 === substr_count( $lj, 'if (seesEveryone() || isTeacher(p)) {' ), 'the live room: where cameras may be on, everyone sees everyone („élőben láthassuk egymást”)' );
	ok( 'info@oliviajoga.online' === $ed, 'the online site\'s own address is info@oliviajoga.online' );
	$faq = oy_faq_list();
	$js  = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	ok( ( '' === $faq || substr_count( $faq, '<details name="oy-faq"' ) === substr_count( $faq, '<details' ) ) && str_contains( $js, "document.addEventListener('toggle'" ), 'the questions: only one open at a time („gyiknél egyszerre csak 1 legyen megnyitva”)' );
	$src = (string) file_get_contents( WP_PLUGIN_DIR . '/olivia-studio/includes/class-emails.php' );
	ok( str_contains( $src, '. self::kit_html( $s )' ) && substr_count( $src, 'self::kit_html( $s )' ) >= 2, '… in the confirmation and in a guest\'s invite' );
} );

test( 'Olívia\'s doc of 14 November: the side column is never cut off while scrolling; her two training photos in one line', function () {
	$css = (string) file_get_contents( get_template_directory() . '/assets/site.css' );
	ok( (bool) preg_match( '/\.aside-sticky \{ position: sticky; top: calc\(var\(--header-h\) \+ var\(--wp-bar, 0px\) \+ 24px\); align-self: start; \}/', $css ) && ! str_contains( $css, 'scrollbar-width: none' ), 'the side column: no hidden inner scroll that cuts its bottom off' );
	$js = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	ok( str_contains( $js, 'function fitAsides' ) && str_contains( $js, "el.style.position = 'static'" ) && ! str_contains( $js, "el.style.top = room + 'px'" ), '… it follows the page only when the whole of it fits; a taller one scrolls with the page (neither top nor bottom cut off)' );
	ok( str_contains( $js, "el.classList.add('is-compact')" ) && str_contains( $js, "el.classList.add('is-tight')" ) && strpos( $js, "el.classList.add('is-tight')" ) < strpos( $js, "el.style.position = 'static'" ), '… before giving up it makes itself smaller – the photos go – so it stays whole and in view („ez megint kicsit levágódik ahogy görgetek lejjebb”)' );
	ok( str_contains( $css, '.aside-sticky.is-compact .aside-card .frame { display: none; }' ) && str_contains( $css, '.aside-sticky.is-tight .credential img { display: none; }' ) && ! str_contains( $css, 'line-clamp: 3' ), '… the smaller steps hide photos, never cut a text short („lemaradt a vége”)' );
	update_option( 'oy_edition', 'hu-online' );
	$pol = OYS_Settings::get( 'cancel_policy' );
	try {
		OYS_Settings::update( array( 'cancel_policy' => 'A csoportos órát 12 órával előtte lemondhatod. A később lemondott óra elhasználódik. Az egyéni órát 24 órával előtte lehet áttenni vagy lemondani.' ) );
		$p = oy_cancel_note( 'x', 'private' );
		$g = oy_cancel_note( 'x', 'group' );
		ok( str_contains( $p, 'Az egyéni órát 24 órával' ) && ! str_contains( $p, 'csoportos' ) && str_contains( $g, 'A csoportos órát 12 órával' ) && str_contains( $g, 'elhasználódik' ) && ! str_contains( $g, 'egyéni' ), 'the side box: only the cancellation rule of that kind of class, so it fits whole' );
		OYS_Settings::update( array( 'cancel_policy' => 'Bármelyik órát 12 órával előtte lemondhatod.' ) );
		ok( str_contains( oy_cancel_note( 'x', 'private' ), 'Bármelyik órát' ), '… and all of it when no sentence is about one-to-one sessions' );
		$tpl = (string) file_get_contents( get_template_directory() . '/single-oy_class.php' );
		ok( str_contains( $tpl, '$aside_pics = ! oy_online_only();' ) && str_contains( $tpl, '$aside_photo && $aside_pics' ) && str_contains( $tpl, '$prenatal && $aside_pics' ), 'online: the side box without pictures on every class page, the prenatal one too („legyen ez is kép nélküli mint a többinél, egységesen és fixált”)' );
	} finally {
		OYS_Settings::update( array( 'cancel_policy' => $pol ) );
		update_option( 'oy_edition', 'fort-myers' );
	}
	ok( str_contains( $css, '@media (min-width: 1000px) and (max-height: 880px)' ), '… and on a short laptop screen it is tighter, so it fits' );
	$on = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
	ok( ! str_contains( $on, '.oj-duo .oj-figure:nth-child(2) { margin-top' ), 'About: the two photos in one line, no step' );
} );

test( 'online edition, Olívia\'s doc of 14 November, last points: one level for yoga, prenatal and mobility; no labels or breadcrumbs; who made the site', function () {
	update_option( 'oy_edition', 'hu-online' );
	try {
		eq( '', oy_kicker( 'Egyéni órák' ), 'no small label over the headings' );
		eq( '', oy_crumbs( array( array( 'Órák', home_url( '/' ) ), array( 'Egyéni online jóga', '' ) ) ), 'no breadcrumb over the page titles' );
		ob_start();
		load_template( get_template_directory() . '/template-parts/front-online.php', false );
		$home = (string) ob_get_clean();
		ok( (bool) preg_match( '/<h1[^>]*>.*oj-breath--w1.*oj-breath--w2.*oj-breath--w3.*<\/h1>/s', $home ), 'the hero: three equal marks' );
		ok( ! str_contains( $home, 'class="kicker"' ), 'home: no labels' );
		// since 16 November the intro too: „itt legyen halványsárga és magenta a hullám és ugyanolyan mint ezeknél”
		ok( 3 === substr_count( $home, 'oj-waved--soft' ) && 0 === substr_count( $home, 'oj-brush' ) && str_contains( $home, 'oj-intro-wave' ), 'one-to-one, prenatal and the intro: the hero\'s soft wave (pale yellow and magenta by the intro)' );
		ob_start();
		load_template( get_template_directory() . '/footer.php', false );
		$foot = (string) ob_get_clean();
		ok( str_contains( $foot, 'href="https://aronritok.com/"' ), 'footer: who made the site, linked (Áron\'s own page)' );
		$css = (string) file_get_contents( get_template_directory() . '/assets/online.css' );
		ok( ! str_contains( $css, '.ways--five .way:nth-child(2), .ways--five .way:nth-child(5) { top' ), 'the five boxes in straight lines' );
		ok( 9 === preg_match_all( '/\.oj-modules li:nth-child\(\d\) \{ --dot: (#[0-9A-F]{6}); \}/', $css, $m ) && 9 === count( array_unique( $m[1] ) ), 'nine modules, nine colours' );
		ok( str_contains( $home, 'section--breath oj-breath-peach' ) && ! str_contains( $home, 'Egy perc a foglalás' ), 'the breathing lotus on peach; no line under „Heti élő órák”' );
		ok( ! str_contains( $home, 'id="classes"' ), 'no „Online órák” list on the home page' );
		$note = oy_cancel_note();
		ok( str_contains( $note, 'oj-policy' ) && str_contains( str_replace( '<br>', ' ', $note ), esc_html( (string) OYS_Settings::get( 'cancel_policy' ) ) ), 'the cancellation rule, from the booking settings' );
		ok( str_contains( do_shortcode( '[oy_schedule]' ), 'oj-policy' ) && str_contains( $home, 'oj-policy' ), '… under the timetables (home page, prices page)' );
		$side = oy_page_aside( 'about' );
		ok( str_contains( $side, 'aside-card--practice' ) && ! str_contains( $side, 'aside-list"><li>' . oy_icon( 'check' ) ), 'About side column: the next class and the services, not the trainings again' );
		$duo = do_shortcode( '[oy_figures photos="korall-stretching-circuit,outdoor-group-bands"]' );
		ok( str_contains( $duo, 'class="oj-duo oj-duo--n2"' ) && 2 === substr_count( $duo, '<figure' ) && str_contains( $duo, 'gumiszalaggal' ), 'About: her two training photos side by side, with Hungarian text' );
		ok( (bool) preg_match( '/\[oys_schedule[^\]]*next="3"/', (string) file_get_contents( get_template_directory() . '/template-parts/front-online.php' ) ), 'the timetable: the next three classes' );
		$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
		eq( 10, (int) $data['setup']['studio']['weeks_ahead'], 'weekly classes made 10 weeks ahead' );
	} finally {
		update_option( 'oy_edition', 'fort-myers' );
	}
	eq( '<p class="kicker">X</p>', oy_kicker( 'X' ), 'the other editions keep their labels' );
} );

test( 'one-to-one request: every length on its own line, the 5-session passes, „a pair” and „with a friend” (Olívia, 14 November)', function () {
	$products = get_option( OYS_Products::OPTION );
	$services = get_option( OYS_Services::OPTION );
	try {
		update_option( OYS_Services::OPTION, array( 'egyeni-joga' => array( 'label' => 'Private yoga', 'class' => 'private-yoga', 'duration' => 60, 'duo' => 1, 'sort' => 10 ), 'prenatal' => array( 'label' => 'Prenatal', 'class' => 'prenatal', 'duration' => 60, 'duo' => 1, 'sort' => 20 ) ) );
		$mk = fn( $kind, $svc, $people, $min, $credits, $price ) => array( 'name' => "$svc $people $min $credits", 'kind' => $kind, 'credits' => $credits, 'validity_days' => 42, 'price_cents' => $price, 'service' => $svc, 'people' => $people, 'duration_min' => $min, 'active' => 1 );
		update_option( OYS_Products::OPTION, array(
			'a' => $mk( 'private_single', 'egyeni-joga', 1, 60, 1, 800000 ), 'b' => $mk( 'private_single', 'egyeni-joga', 2, 60, 1, 1200000 ),
			'c' => $mk( 'private_single', 'egyeni-joga', 1, 90, 1, 1000000 ), 'd' => $mk( 'private_pack', 'egyeni-joga', 1, 90, 5, 4300000 ),
			'e' => $mk( 'private_single', 'prenatal', 1, 60, 1, 800000 ), 'f' => $mk( 'private_single', 'prenatal', 2, 60, 1, 1200000 ),
		) );
		eq( 4300000, OYS_Services::pack_price( 'egyeni-joga', 1, 90 ), 'pass price by service, people and length' );
		eq( 0, OYS_Services::pack_price( 'egyeni-joga', 2, 90 ), '… 0 when not sold' );
		$m    = new ReflectionMethod( 'OYS_Frontend', 'private_form' );
		$m->setAccessible( true );
		$html = (string) $m->invoke( null );
		ok( (bool) preg_match( '/<li>90 minutes: [^<]+<\/li>/', $html ), 'the 90 minutes on a line of their own' );
		ok( str_contains( $html, '5-session pass:' ), 'the passes listed' );
		ok( str_contains( $html, 'as a pair' ) && str_contains( $html, 'with a friend' ), 'yoga: as a pair; prenatal: with a friend' );
	} finally {
		update_option( OYS_Products::OPTION, $products );
		update_option( OYS_Services::OPTION, $services );
	}
} );

test( 'Arovia payments through the CRM: the site registers itself, every Stripe call is signed, events come back signed', function () {
	$calls = array();
	$fake  = function ( $pre, $args, $url ) use ( &$calls ) {
		if ( ! str_starts_with( $url, OYS_HPV_Pay::base() ) ) {
			return $pre;
		}
		$path = substr( $url, strlen( OYS_HPV_Pay::base() ) );
		$body = (string) ( $args['body'] ?? '' );
		$in   = json_decode( $body, true );
		$json = array();
		$code = 200;
		if ( '/register' === $path ) {
			// the CRM asks the site to prove the registration came from it
			$c     = wp_generate_password( 32, false );
			$r     = new WP_REST_Request( 'GET', '/oys/v1/hpv-proof' );
			$r->set_param( 'c', $c );
			$proof = OYS_HPV_Pay::proof( $r )->get_data()['proof'] ?? '';
			$json  = hash_equals( hash_hmac( 'sha256', 'proof.' . $c, $in['secret'] ), $proof ) && home_url() === $in['url'] ? array( 'ok' => true, 'site' => 's_unit', 'mode' => 'test' ) : array( 'message' => 'no proof' );
			$code  = isset( $json['ok'] ) ? 200 : 403;
		} elseif ( '/stripe' === $path ) {
			$h  = $args['headers'];
			$ok = 's_unit' === $h['X-HPV-Site'] && hash_equals( hash_hmac( 'sha256', $h['X-HPV-Time'] . '.' . $body, OYS_HPV_Pay::state()['secret'] ), $h['X-HPV-Signature'] );
			$calls[] = array( $in['method'], $in['path'], $in['params'], $ok );
			if ( ! $ok ) {
				$code = 401;
			} elseif ( '/v1/checkout/sessions' === $in['path'] ) {
				$json = array( 'id' => 'cs_hpv_1', 'url' => 'https://checkout.stripe.test/arovia' );
			} elseif ( str_starts_with( $in['path'], '/v1/checkout/sessions/cs_hpv_1' ) ) {
				$json = array( 'id' => 'cs_hpv_1', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 3000, 'payment_intent' => array( 'id' => 'pi_hpv_1', 'latest_charge' => array( 'receipt_url' => 'https://pay.stripe.test/r' ) ) );
			} elseif ( '/v1/refunds' === $in['path'] ) {
				$json = array( 'id' => 're_hpv_1', 'amount' => (int) ( $in['params']['amount'] ?? 3000 ), 'payment_intent' => $in['params']['payment_intent'] );
			} else {
				$code = 403;
				$json = array( 'message' => 'This Stripe call is not allowed through the CRM.' );
			}
		}
		return array( 'headers' => array(), 'body' => wp_json_encode( $json ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $fake, 4, 3 );
	delete_option( OYS_HPV_Pay::OPTION );
	OYS_Settings::update( array( 'pay_provider' => 'hpv' ) );
	ok( OYS_HPV_Pay::active() && ! OYS_Settings::payments_ready(), 'CRM payments chosen, not connected yet' );
	ok( true === OYS_HPV_Pay::register() && OYS_Settings::payments_ready(), 'registered: the CRM checked the proof, no key typed in anywhere' );
	$stripe_before = count( $GLOBALS['stripe_calls'] );

	$u        = make_user();
	$s        = make_session();
	$order_id = OYS_Orders::create( array( 'user_id' => $u, 'type' => 'dropin', 'session_id' => $s->id, 'amount_cents' => 3000 ) );
	OYS_Bookings::hold( $u, $s, $order_id );
	$url = OYS_Stripe::start_checkout( $order_id, 'Hatha Flow', 'szerda' );
	eq( 'https://checkout.stripe.test/arovia', $url, 'the Stripe page comes through the CRM' );
	eq( $stripe_before, count( $GLOBALS['stripe_calls'] ), 'not a single direct Stripe call from the site' );
	ok( $calls && $calls[0][3] && ! isset( $calls[0][2]['customer'] ) && ! empty( $calls[0][2]['customer_email'] ), 'signed, with the buyer\'s e-mail, no saved Stripe customer' );

	// the CRM forwards the Stripe event, signed with the site's secret
	$event = array( 'id' => 'evt_hpv_' . wp_generate_password( 6, false ), 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'id' => 'cs_hpv_1', 'payment_status' => 'paid', 'status' => 'complete' ) ) );
	$send  = function ( $event, $secret ) {
		$payload = wp_json_encode( $event );
		$t       = time();
		$r       = new WP_REST_Request( 'POST', '/oys/v1/hpv-webhook' );
		$r->set_body( $payload );
		$r->set_header( 'x-hpv-site', 's_unit' );
		$r->set_header( 'x-hpv-time', (string) $t );
		$r->set_header( 'x-hpv-signature', hash_hmac( 'sha256', $t . '.' . $payload, $secret ) );
		return OYS_HPV_Pay::webhook( $r );
	};
	eq( 400, $send( $event, 'not-the-secret' )->get_status(), 'a forged event is refused' );
	eq( 'pending', OYS_Orders::get( $order_id )->status, 'and changes nothing' );
	eq( 200, $send( $event, OYS_HPV_Pay::state()['secret'] )->get_status(), 'the real one is accepted' );
	$o = OYS_Orders::get( $order_id );
	ok( 'paid' === $o->status && 'pi_hpv_1' === $o->stripe_payment_intent, 'the order is paid, with the payment intent for refunds' );

	$res = OYS_Stripe::refund( $order_id, 1000 );
	ok( ! is_wp_error( $res ) && 'partially_refunded' === OYS_Orders::get( $order_id )->status, 'a refund goes through the CRM too' );
	eq( 'pi_hpv_1', end( $calls )[2]['payment_intent'], 'refund of this payment' );
	ok( is_wp_error( OYS_Stripe::request( 'GET', '/v1/balance' ) ), 'anything else is refused by the CRM' );

	remove_filter( 'pre_http_request', $fake, 4 );
	OYS_Settings::update( array( 'pay_provider' => 'stripe' ) );
	delete_option( OYS_HPV_Pay::OPTION );
} );

test( 'terms of sale: the line under the pay buttons links the ÁSZF when the site has one', function () {
	eq( '', OYS_Frontend::terms_note(), 'no terms page, no line' );
	$f = fn() => 'https://example.test/aszf/';
	add_filter( 'oys_terms_url', $f );
	$html = OYS_I18n::with( 'hu', fn() => OYS_Frontend::terms_note() );
	remove_filter( 'oys_terms_url', $f );
	ok( str_contains( $html, 'href="https://example.test/aszf/"' ) && str_contains( $html, 'általános szerződési feltételeket' ), 'Hungarian line with the link' );
} );

test( 'customers from Motibro: every column kept, nobody emailed, an existing account keeps what was entered, a second run changes nothing', function () {
	$mails = 0;
	$cap   = function ( $r ) use ( &$mails ) {
		$mails++;
		return true;
	};
	add_filter( 'pre_wp_mail', $cap, 5 );
	$existing = OYS_Customers::find_or_create( 'mar.itt@example.test', 'Meglévő Vendég' );
	update_user_meta( $existing, 'oys_phone', '+36 1 111 1111' );
	$rows = array(
		array( 'Id' => '900001', 'Vezetéknév' => 'Teszt', 'Keresztnév' => 'Anna', 'Becenév' => 'Panni', 'Email' => 'Teszt.Anna@Example.test', 'Telefonszám' => '6301234567.0', 'Nem' => 'Nő', 'Születési idő' => '1985-03-04 00:00:00', 'Regisztráció időpontja' => '2025.08.29.', 'Első óralátogatás időpontja' => '2025-09-16 19:15:00', 'Utolsó óralátogatás időpontja' => '2026-11-11 18:30:00', 'Megjegyzés' => '', 'Kurzusok' => 'Hatha flow jóga 21. kerület, INGYENES hatha flow jóga', 'Aktív tanfolyamtagság' => '', 'Számlázási név' => 'Teszt Anna', 'Ország' => 'HU', 'Irányítószám' => '3534.0', 'Város' => 'Miskolc', 'Számlázási cím' => 'Fő utca 1.', 'Adószám' => '' ),
		array( 'Id' => '900002', 'Vezetéknév' => 'Email', 'Keresztnév' => 'Nélküli', 'Email' => '', 'Telefonszám' => '06201234567', 'Regisztráció időpontja' => '2024.01.02.' ),
		array( 'Id' => '900003', 'Vezetéknév' => 'Vendég', 'Keresztnév' => 'Meglévő', 'Email' => 'mar.itt@example.test', 'Telefonszám' => '06309999999', 'Város' => 'Budapest' ),
		array( 'Id' => '', 'Vezetéknév' => '', 'Keresztnév' => '', 'Email' => '' ),
	);
	$dry = OYS_Import::import( $rows, 'motibro', true );
	eq( array( 2, 1, 0, 1 ), array( $dry['created'], $dry['updated'], $dry['unchanged'], $dry['skipped'] ), 'check only: 2 new, 1 to fill, 1 empty row skipped' );
	ok( ! get_user_by( 'email', 'teszt.anna@example.test' ), '… and nothing written' );
	$res = OYS_Import::import( $rows );
	eq( array( 2, 1, 0, 1 ), array( $res['created'], $res['updated'], $res['unchanged'], $res['skipped'] ), 'import: 2 created, 1 filled, 1 skipped' );
	$u = get_user_by( 'email', 'teszt.anna@example.test' );
	$m = fn( $k ) => (string) get_user_meta( $u->ID, $k, true );
	ok( $u && in_array( 'oys_customer', $u->roles, true ) && 'Anna' === $u->first_name && 'Teszt' === $u->last_name && 'Panni' === $u->nickname, 'name, nickname, customer role' );
	eq( array( '06301234567', 'Nő', '1985-03-04', '2025-08-29', '2025-09-16 19:15', '2026-11-11 18:30', 'Hatha flow jóga 21. kerület, INGYENES hatha flow jóga', 'Teszt Anna', 'HU', '3534', 'Miskolc', 'Fő utca 1.', '900001', 'motibro' ), array( $m( 'oys_phone' ), $m( 'oys_gender' ), $m( 'oys_birth_date' ), $m( 'oys_registered' ), $m( 'oys_first_visit' ), $m( 'oys_last_visit' ), $m( 'oys_courses' ), $m( 'oys_billing_name' ), $m( 'oys_billing_country' ), $m( 'oys_billing_zip' ), $m( 'oys_billing_city' ), $m( 'oys_billing_address' ), $m( 'oys_ext_id' ), $m( 'oys_ext_source' ) ), 'every column kept (the phone the export wrote as a decimal back to 06…, the postcode without .0)' );
	ok( str_starts_with( $u->user_registered, '2025-08-29' ), 'the account is registered on the day of the Motibro registration' );
	ok( '' === $m( 'oys_marketing' ) && ! OYS_Customers::has_waiver( $u->ID ), 'not subscribed to the newsletter, no agreement marked accepted' );
	$no = get_users( array( 'meta_key' => 'oys_ext_id', 'meta_value' => '900002', 'fields' => 'all' ) );
	ok( $no && '' === $no[0]->user_email && 'motibro-900002' === $no[0]->user_login && '06201234567' === get_user_meta( $no[0]->ID, 'oys_phone', true ), 'a guest without an email is kept too' );
	ok( '+36 1 111 1111' === get_user_meta( $existing, 'oys_phone', true ) && 'Budapest' === get_user_meta( $existing, 'oys_billing_city', true ) && '900003' === get_user_meta( $existing, 'oys_ext_id', true ), 'an existing account keeps its phone, gets the imported data' );
	$again = OYS_Import::import( $rows );
	eq( array( 0, 0, 3 ), array( $again['created'], $again['updated'], $again['unchanged'] ), 'the same file again changes nothing' );
	eq( 0, $mails, 'nobody is emailed' );
	remove_filter( 'pre_wp_mail', $cap, 5 );
	$tmp = wp_tempnam( 'x.csv' );
	file_put_contents( $tmp, "\xEF\xBB\xBFId;Vezetéknév;Keresztnév;Email\n900004;Csv;Elek;csv.elek@example.test\n" );
	$csv = OYS_Import::read_file( $tmp, 'lista.csv' );
	unlink( $tmp );
	eq( array( array( 'Id' => '900004', 'Vezetéknév' => 'Csv', 'Keresztnév' => 'Elek', 'Email' => 'csv.elek@example.test' ) ), $csv, 'a .csv export (semicolons, BOM) is read too' );
	ok( false !== has_action( 'rest_api_init' ) && class_exists( 'ZipArchive' ), 'the REST route and the .xlsx reader are there' );
} );

test( 'going live: the manager can check the site from outside (X-OYS-Auth, /status), never a secret in the answer', function () {
	ok( has_filter( 'determine_current_user', array( 'OYS_Ops', 'header_auth' ) ) && 0 === OYS_Ops::header_auth( 0 ), 'the header login is there and does nothing without the header' );
	$_SERVER['HTTP_X_OYS_AUTH'] = base64_encode( 'nobody:wrong pass' );
	$_SERVER['HTTPS']           = 'on';
	$_SERVER['REQUEST_URI']     = '/wp-json/oys/v1/status';
	eq( 0, OYS_Ops::header_auth( 0 ), 'a wrong application password lets nobody in' );
	// The right one, base64 or plain "user:pass" (11.17: the same simple value on both sites), only for the REST API.
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	list( $pw ) = WP_Application_Passwords::create_new_application_password( $admin->ID, array( 'name' => 'header test' ) );
	add_filter( 'application_password_is_api_request', '__return_true' );
	$_SERVER['HTTP_X_OYS_AUTH'] = base64_encode( $admin->user_login . ':' . $pw );
	eq( $admin->ID, OYS_Ops::header_auth( 0 ), 'base64(user:pass) logs the admin in' );
	$_SERVER['HTTP_X_OYS_AUTH'] = $admin->user_login . ':' . $pw;
	eq( $admin->ID, OYS_Ops::header_auth( 0 ), 'plain user:pass too' );
	$_SERVER['HTTP_X_OYS_AUTH'] = $admin->user_login . ':nope';
	eq( 0, OYS_Ops::header_auth( 0 ), 'plain with a wrong password: nobody' );
	$_SERVER['HTTP_X_OYS_AUTH'] = $admin->user_login . ':' . $pw;
	$_SERVER['REQUEST_URI']     = '/fiokom/';
	eq( 0, OYS_Ops::header_auth( 0 ), 'not outside the REST API' );
	$_SERVER['REQUEST_URI']     = '/wp-json/oys/v1/status';
	remove_filter( 'application_password_is_api_request', '__return_true' );
	unset( $_SERVER['HTTP_X_OYS_AUTH'], $_SERVER['HTTPS'] );
	OYS_Settings::update( array( 'stripe_secret_key' => 'sk_test_hidden_123' ) );
	$st   = OYS_Ops::status();
	$json = wp_json_encode( $st );
	OYS_Settings::update( array( 'stripe_secret_key' => '' ) );
	ok( isset( $st['mail']['smtp'], $st['payments']['provider'], $st['site']['search_engines'], $st['schedule']['upcoming_60_days'], $st['people']['customers'] ) && ! str_contains( (string) $json, 'sk_test_hidden_123' ) && 'set' === $st['settings']['stripe_secret_key'], 'the status: mail, payments, search engines, schedule, customers – keys only as set or missing' );
} );

test( 'helpers: money and periods', function () {
	eq( '$25', oys_money( 2500, 'usd' ), 'whole dollars' );
	eq( '$25.50', oys_money( 2550, 'usd' ), 'cents' );
	eq( 2550, oys_cents_from_input( '$25.50' ), 'parse input' );
	eq( 'per month', OYS_Products::period_label( array( 'interval' => 'month', 'interval_count' => 1 ) ), 'monthly label' );
	eq( 'every 3 months', OYS_Products::period_label( array( 'interval' => 'month', 'interval_count' => 3 ) ), 'quarterly label' );
} );

test( 'Olivia, 16 November: a spot from the waitlist is not booked in by itself – everyone gets an email, first to book gets it', function () {
	eq( 0, (int) OYS_Settings::get( 'waitlist_auto' ), 'by default no automatic move-in' );
	ok( ! OYS_Email_Templates::in_use( 'waitlist_promoted' ) && OYS_Email_Templates::in_use( 'waitlist_spot_open' ), 'the Emails page shows only the „a spot opened” email' );
	$s     = make_session( array( 'capacity' => 1 ) );
	$first = make_user( 'Elso' );
	OYS_Passes::grant( $first, array( 'credits' => 1 ) );
	$bid    = OYS_Bookings::book_with_credit( $first, $s, false );
	$member = make_user( 'Tag' );
	make_membership( $member, 0 );
	$holder = make_user( 'Berletes' );
	OYS_Passes::grant( $holder, array( 'credits' => 5 ) );
	$plain = make_user( 'Sima' );
	foreach ( array( $member, $holder, $plain ) as $u ) {
		OYS_Bookings::join_waitlist( $u, $s->id );
	}
	$_GET['session'] = (int) $s->id;
	wp_set_current_user( $plain );
	try {
		$html = OYS_Frontend::sc_book();
	} finally {
		wp_set_current_user( 0 );
		unset( $_GET['session'] );
	}
	ok( str_contains( $html, 'on the waitlist. When a spot opens, we email everyone on the waitlist straight away, and whoever books first gets it.' ) && ! str_contains( $html, 'booked in automatically' ), 'the booking page says so (no „booked in automatically”)' );
	$log  = sent_mails( fn() => OYS_Bookings::cancel( $bid, array( 'notify' => false ) ) );
	ok( ! OYS_Bookings::active_for( $member, $s->id ) && ! OYS_Bookings::active_for( $holder, $s->id ), 'nobody is booked in by themselves (not the member, not the pass holder)' );
	eq( 1, OYS_Schedule::spots_left( OYS_Schedule::get( $s->id ) ), 'the spot stays free' );
	$to = array_map( fn( $m ) => is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'], $log );
	sort( $to );
	$want = array_map( fn( $u ) => get_userdata( $u )->user_email, array( $member, $holder, $plain ) );
	sort( $want );
	eq( $want, $to, 'all three on the waitlist get the email' );
	ok( str_contains( $log[0]['html'] ?? '', esc_url( oys_book_url( $s->id ) ) ) && str_contains( $log[0]['html'] ?? '', 'Book now' ), 'with a button to book the class' );
	eq( 3, count( OYS_Bookings::waitlist( $s->id ) ), 'everyone stays on the waitlist until they book' );
	ok( ! is_wp_error( OYS_Bookings::book_with_credit( $holder, OYS_Schedule::get( $s->id ), false ) ), 'the first to book gets the spot' );
	eq( 0, OYS_Bookings::waitlist_position( $holder, $s->id ), 'and leaves the waitlist' );
	ok( is_wp_error( OYS_Bookings::book_with_membership( $member, OYS_Schedule::get( $s->id ), false ) ), 'the next one is too late: the class is full again' );
	$again = sent_mails( fn() => OYS_Schedule::release_seats( $s->id, 0 ) );
	eq( 0, count( $again ), 'no second email to the same people within a quarter of an hour' );
	OYS_Settings::update( array( 'waitlist_auto' => 1 ) );
	ok( OYS_Email_Templates::in_use( 'waitlist_promoted' ), 'switched on in Settings: the „moved in” email is back on the Emails page' );
	OYS_Settings::update( array( 'waitlist_auto' => 0 ) );
} );

test( 'Olivia, 16 November: the pass extension when the studio cancels is a default – a private client can have their own number', function () {
	$default = make_user( 'Alap' );
	$own     = make_user( 'Sajat' );
	$none    = make_user( 'Nulla' );
	update_user_meta( $own, 'oys_cancel_extend_days', 14 );
	update_user_meta( $none, 'oys_cancel_extend_days', 0 );
	eq( 7, OYS_Passes::cancel_extend_days_for( $default ), 'nothing set: the default (7)' );
	eq( 14, OYS_Passes::cancel_extend_days_for( $own ), 'their own number' );
	eq( 0, OYS_Passes::cancel_extend_days_for( $none ), '0: no extension for them' );
	$run = function ( $u ) {
		$s    = make_session( array( 'kind' => 'private', 'capacity' => 1 ) );
		$pass = OYS_Passes::get( OYS_Passes::grant( $u, array( 'product_id' => 'p', 'name' => 'private', 'kind' => OYS_Bookings::credit_kind( $s ), 'credits' => 5, 'validity_days' => 56, 'order_id' => 0, 'source' => 'purchase' ) ) );
		$b    = OYS_Bookings::book_with_credit( $u, $s, false );
		ok( ! is_wp_error( $b ), 'booked with the private pass' );
		OYS_Schedule::cancel_session( $s->id, 'test' );
		return (int) round( ( oys_ts( OYS_Passes::get( $pass->id )->expires_at ) - oys_ts( $pass->expires_at ) ) / DAY_IN_SECONDS );
	};
	eq( 7, $run( $default ), 'the studio cancels: 7 days longer by default' );
	eq( 14, $run( $own ), 'with their own number: 14 days longer' );
	eq( 0, $run( $none ), 'with 0: not extended' );
	OYS_Settings::update( array( 'cancel_extend_days' => 0 ) );
	try {
		eq( 14, $run( $own ), 'the default switched off: their own number still counts' );
		eq( 0, $run( $default ), 'and the others get nothing' );
	} finally {
		OYS_Settings::update( array( 'cancel_extend_days' => 7 ) );
	}
} );

test( 'Áron, 16 November – the Hub: a customer writes, the studio hears once, answers, the customer gets it by email and in the account', function () {
	$u = make_user( 'Hubvendeg' );
	$t = OYS_Hub::thread_for( $u );
	ok( $t && (int) $t->user_id === $u, 'a conversation for the customer' );
	eq( (int) $t->id, (int) OYS_Hub::thread_for( $u )->id, 'one conversation per customer' );
	$log = sent_mails( fn() => OYS_Hub::add( $t, array( 'body' => 'Szia! Kérdésem van a térdemről.', 'from_studio' => false, 'author_id' => $u ) ) );
	eq( 1, count( $log ), 'the studio gets an email' );
	ok( str_contains( $log[0]['html'] ?? '', 'Kérdésem van a térdemről' ) && str_contains( $log[0]['html'] ?? '', 'page=oys#t' . $t->id ), 'with the message and a link to the conversation in the Hub' );
	eq( 1, (int) OYS_Hub::get( $t->id )->unread_studio, 'unread for the studio' );
	$again = sent_mails( fn() => OYS_Hub::add( $t, array( 'body' => 'És még valami.', 'from_studio' => false, 'author_id' => $u ) ) );
	eq( 0, count( $again ), 'a second message within a quarter hour: no second email' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin );
	try {
		ok( OYS_Hub::counts()['messages'] >= 1, 'counted on the Hub' );
		$items = OYS_Hub::inbox( 'unread' );
		ok( in_array( 't' . $t->id, array_column( $items, 'key' ), true ), 'in the Hub list among the unread' );
		$reply = sent_mails( fn() => OYS_Hub::add( $t, array( 'body' => 'Tegyél egy takarót a térded alá.', 'from_studio' => true, 'author_id' => $admin ) ) );
		eq( get_userdata( $u )->user_email, $reply[0]['to'] ?? '', 'the answer goes to the customer by email' );
		ok( str_contains( $reply[0]['html'] ?? '', 'Tegyél egy takarót' ), 'with the answer in it' );
		eq( 0, (int) OYS_Hub::get( $t->id )->unread_studio, 'answered: no longer unread for the studio' );
		eq( 1, OYS_Hub::customer_unread( $u ), 'the customer has one unread conversation' );
		$note = sent_mails( fn() => OYS_Hub::add( $t, array( 'body' => 'Belső: meniszkusz', 'from_studio' => true, 'kind' => 'note' ) ) );
		eq( 0, count( $note ), 'an internal note is not emailed' );
		wp_set_current_user( $u );
		$_GET['tab'] = 'messages';
		$html        = OYS_Frontend::sc_account();
		unset( $_GET['tab'] );
		ok( str_contains( $html, 'Tegyél egy takarót' ) && ! str_contains( $html, 'meniszkusz' ), 'the account shows the answer, not the internal note' );
		eq( 0, OYS_Hub::customer_unread( $u ), 'seen in the account: read' );
	} finally {
		wp_set_current_user( 0 );
	}
} );

test( 'Áron, 16 November – the Hub: the website form and a private request land in the conversation', function () {
	OYS_Hub::on_contact( array( 'name' => 'Web Látogató', 'email' => 'weblatogato@example.test', 'phone' => '+36 1 234', 'topic' => 'Kismama jóga', 'message' => 'Mikor kezdhetek?' ) );
	$t = OYS_Hub::thread_for( 0, 'weblatogato@example.test' );
	ok( $t && 0 === (int) $t->user_id && 'Web Látogató' === $t->name, 'a conversation by email address' );
	$m = OYS_Hub::messages( $t->id );
	ok( 'contact' === $m[0]->kind && str_contains( $m[0]->body, 'Mikor kezdhetek?' ) && str_contains( $m[0]->body, 'Kismama jóga' ), 'with the topic and the message' );
	$u = wp_insert_user( array( 'user_login' => 'weblatogato', 'user_email' => 'weblatogato@example.test', 'user_pass' => 'x-12345678', 'first_name' => 'Web', 'role' => 'oys_customer' ) );
	eq( (int) $t->id, (int) OYS_Hub::thread_for( $u )->id, 'when they register with that address, the conversation becomes theirs' );
	global $wpdb;
	$wpdb->insert( OYS_Install::table( 'private_requests' ), array( 'user_id' => $u, 'topic' => 'yoga', 'duration_min' => 60, 'people' => 1, 'location_type' => 'online', 'preferred' => 'Hétfő este', 'status' => 'new', 'created_at' => oys_now() ) );
	$r = OYS_Privates::get( (int) $wpdb->insert_id );
	OYS_Hub::on_private_request( $r );
	$last = array_values( array_filter( OYS_Hub::messages( $t->id ), fn( $m ) => 'request' === $m->kind ) );
	ok( $last && str_contains( $last[0]->body, 'Hétfő este' ) && (int) $last[0]->ref_id === (int) $r->id, 'the request with what was asked, linked to the request' );
} );

test( 'Áron, 16 November – the Hub: class chats with unread counts, an email about them outside the class, a photo in the chat', function () {
	$s = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => -30 ) );
	$u = make_user( 'Chatelo' );
	$who = array( 'owner' => false, 'booking' => (object) array( 'id' => 999999 ), 'user_id' => $u, 'name' => 'Chatelő K.' );
	$log = sent_mails( fn() => OYS_Live::post_message( $s, $who, 'Köszönöm az órát!' ) );
	eq( 1, count( $log ), 'a message after the class: the studio gets an email' );
	ok( str_contains( $log[0]['html'] ?? '', 'Köszönöm az órát!' ) && str_contains( $log[0]['html'] ?? '', 'page=oys#c' . $s->id ), 'with the message and a link to the class chat in the Hub' );
	delete_transient( 'oys_live_chat_' . md5( $s->id . '|b999999' ) );
	$again = sent_mails( fn() => OYS_Live::post_message( $s, $who, 'Még egy.' ) );
	eq( 0, count( $again ), 'one email per class per quarter hour' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin );
	try {
		$chat = array_values( array_filter( OYS_Hub::class_chats(), fn( $c ) => (int) $c['session']->id === (int) $s->id ) );
		eq( 2, $chat[0]['unread'] ?? -1, 'two unread messages in this class chat' );
		$id = OYS_Hub::post_to_class( $s, 'Nagyon örülök!' );
		ok( ! is_wp_error( $id ), 'the studio answers from the Hub' );
		$chat = array_values( array_filter( OYS_Hub::class_chats(), fn( $c ) => (int) $c['session']->id === (int) $s->id ) );
		eq( 0, $chat[0]['unread'], 'answered: read' );
		// a photo, resized and with a random name
		$img = imagecreatetruecolor( 2400, 1800 );
		$tmp = tempnam( sys_get_temp_dir(), 'oystest' ) . '.jpg';
		imagejpeg( $img, $tmp, 90 );
		$path = OYS_Hub::save_image( array( 'tmp_name' => $tmp, 'name' => 'IMG_1234.jpg', 'size' => filesize( $tmp ), 'error' => 0 ) );
		ok( is_string( $path ) && preg_match( '#^\d{4}/\d{2}/[0-9a-f]{32}\.jpg$#', $path ), 'saved under a random name' );
		$size = getimagesize( OYS_Hub::image_dir()[0] . '/' . $path );
		eq( 1600, max( $size[0], $size[1] ), 'resized to 1600 px' );
		$bad = tempnam( sys_get_temp_dir(), 'oystest' ) . '.jpg';
		file_put_contents( $bad, '<?php echo 1;' );
		ok( is_wp_error( OYS_Hub::save_image( array( 'tmp_name' => $bad, 'name' => 'x.jpg', 'size' => 13, 'error' => 0 ) ) ), 'not a photo: refused' );
		delete_transient( 'oys_live_chat_' . md5( $s->id . '|u' . $admin ) ); // the 2-second flood guard after the answer above
		$pid = OYS_Live::post_message( $s, array( 'owner' => true, 'booking' => null, 'user_id' => $admin, 'name' => 'Olívia' ), '', $path );
		$msg = array_values( array_filter( OYS_Live::messages( $s->id ), fn( $m ) => (int) $m->id === (int) $pid ) );
		ok( $msg && $msg[0]->image === $path && str_contains( OYS_Hub::shape_chat_msg( $msg[0] )['image'], '/oys-chat/' . $path ), 'a photo alone goes into the class chat' );
	} finally {
		wp_set_current_user( 0 );
	}
} );

test( 'Áron, 16 November – the Hub: only the studio and teachers, a teacher only their own classes; the REST routes refuse customers', function () {
	$u = make_user( 'Kivancsi' );
	wp_set_current_user( $u );
	try {
		$res = rest_do_request( new WP_REST_Request( 'GET', '/oys/v1/hub/inbox' ) );
		ok( $res->get_status() >= 401, 'a customer can\'t read the Hub' );
	} finally {
		wp_set_current_user( 0 );
	}
	$tid  = OYS_Teachers::save( array( 'name' => 'Hub Tanár', 'email' => 'hubtanar@example.test' ) );
	$tu   = wp_insert_user( array( 'user_login' => 'hubtanar', 'user_email' => 'hubtanar@example.test', 'user_pass' => 'x-12345678', 'role' => 'oys_teacher' ) );
	global $wpdb;
	$wpdb->update( OYS_Install::table( 'teachers' ), array( 'user_id' => $tu ), array( 'id' => $tid ) );
	OYS_Teachers::flush();
	$mine  = make_session( array( 'format' => 'online', 'teacher' => $tid, 'in_hours' => -40 ) );
	$other = make_session( array( 'format' => 'online', 'in_hours' => -41 ) );
	foreach ( array( $mine, $other ) as $s ) {
		$wpdb->insert( OYS_Install::table( 'live_chat' ), array( 'session_id' => $s->id, 'user_id' => 1, 'name' => 'X', 'body' => 'hello', 'created_at' => oys_now() ) );
	}
	wp_set_current_user( $tu );
	try {
		$ids = array_map( fn( $c ) => (int) $c['session']->id, OYS_Hub::class_chats() );
		ok( in_array( (int) $mine->id, $ids, true ) && ! in_array( (int) $other->id, $ids, true ), 'the teacher sees their own class chat only' );
		ok( is_wp_error( OYS_Hub::post_to_class( $other, 'x' ) ), 'and can\'t write in another teacher\'s' );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/oys/v1/hub/chat/' . $other->id ) );
		eq( 404, $res->get_status(), 'not even through the REST route' );
	} finally {
		wp_set_current_user( 0 );
	}
} );

test( 'Áron, 16 November: the price cards ask for a time or sell the pass; the calendar shows the bookable 6 weeks; the waiting room counts down', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	update_option( 'oy_edition', 'hu-online' );
	try {
		oy_migrate_prices();
		$cards = oy_price_cards( 'egyeni-joga' );
		ok( str_contains( $cards, 'topic=egyeni-joga' ) && str_contains( $cards, 'length=90' ) && str_contains( $cards, 'people=2' ), 'each card asks for a time with its service, length and number of people' );
		ok( 4 === substr_count( $cards, 'oj-price__act' ) && str_contains( $cards, 'product=' ), 'and buys its 5-session pass straight away' );
	} finally {
		delete_option( 'oy_edition' );
	}
	eq( 42, (int) OYS_Settings::get( 'booking_window_days' ), 'bookable 6 weeks ahead' );
	$in   = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => 24 * 10 ) );
	$out  = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => 24 * 50 ) );
	$priv = make_session( array( 'format' => 'online', 'kind' => 'private', 'capacity' => 1, 'in_hours' => 24 * 11 ) );
	$html = do_shortcode( '[oys_calendar]' );
	ok( str_contains( $html, 'class="oys-cal"' ) && str_contains( $html, esc_url( oys_book_url( $in->id ) ) ), 'the calendar: a class in 10 days links to its booking' );
	ok( ! str_contains( $html, esc_url( oys_book_url( $out->id ) ) ), 'nothing beyond the 6 weeks' );
	ok( ! str_contains( $html, esc_url( oys_book_url( $priv->id ) ) ), 'no private sessions' );
	// Áron, 16 November: „A naptár csúnya, legyen lapozható” – by month, with arrows, whole weeks in every month
	$panels = array_slice( explode( '<section class="oys-cal__month"', $html ), 1 );
	ok( count( $panels ) >= 2 && count( $panels ) <= 3, 'the six weeks as two or three months' );
	ok( ! array_filter( $panels, fn( $p ) => 0 !== substr_count( $p, 'class="oys-cal__day' ) % 7 ), 'every month in whole weeks, Monday to Sunday' );
	ok( 1 === substr_count( $html, ' data-start' ) && str_contains( $panels[0], 'data-cal-go="-1" disabled' ) && str_contains( end( $panels ), 'disabled aria-hidden="true"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"' ), 'it opens on one month; no arrow before the first or after the last' );
	$withclass = array_values( array_filter( $panels, fn( $p ) => str_contains( $p, esc_url( oys_book_url( $in->id ) ) ) ) );
	ok( $withclass && str_contains( $withclass[0], 'class="oys-cal__item' ) && str_contains( $withclass[0], 'class="session__book oys-cal__go' ), 'the class is listed under its month, with its button on its own' );
	// Áron, 16 November: „Foglalva vagy nem jo.” – a booked class says it once, with a tick, and offers the next step
	$guest = make_user( 'Naptar' );
	OYS_Bookings::book_manual( $guest, $in, 'comp', false, true, 'online' );
	$soon = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => 3 ) );
	OYS_Bookings::book_manual( $guest, $soon, 'comp', false, true, 'online' );
	wp_set_current_user( $guest );
	try {
		$html  = do_shortcode( '[oys_calendar]' );
		$items = array_values( array_filter( explode( '<li class="oys-cal__item', $html ), fn( $p ) => str_starts_with( $p, ' is-mine' ) ) );
		eq( 2, count( $items ), 'both booked classes are marked as the guest\'s' );
		$label = esc_html__( 'You\'re booked', 'olivia-studio' );
		ok( ! array_filter( $items, fn( $p ) => 1 !== substr_count( $p, $label ) || str_contains( $p, 'session__status' ) || ! str_contains( $p, 'class="oys-cal__ok"' ) ), 'it says „booked” once, with a tick – no second line, no grey pill' );
		$far = current( array_filter( $items, fn( $p ) => str_contains( $p, esc_html( wp_date( oys_date_format( 'M j' ), oys_ts( $in->starts_at ) ) ) ) ) );
		ok( $far && str_contains( $far, 'session__book--mine oys-cal__go" href="' . esc_url( oys_account_url() ) . '"' ), 'a class days away: the button goes to the guest\'s bookings' );
		ok( (bool) array_filter( $items, fn( $p ) => str_contains( $p, esc_html__( 'Join the class', 'olivia-studio' ) ) ), 'an online class in a few hours: the button is the way in' );
		ok( str_contains( $html, ' has-mine' ) && str_contains( $html, '<i class="is-mine"></i>' ), 'the day in the grid shows it is the guest\'s class' );
		$card = do_shortcode( '[oys_schedule days="14"]' );
		ok( str_contains( $card, 'session__status is-booked' ) && str_contains( $card, 'session__book--mine' ), 'the class card: the tick and the same next step' );
	} finally {
		wp_set_current_user( 0 );
		OYS_Schedule::cancel_session( $soon->id, 'test' );
	}
	foreach ( array( $in, $out, $priv ) as $s ) {
		OYS_Schedule::cancel_session( $s->id, 'test' );
	}
	$s   = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => 30 ) );
	$u   = make_user( 'Varo' );
	$bid = OYS_Bookings::book_manual( $u, $s, 'comp', false, true, 'online' );
	wp_set_current_user( $u );
	$_GET['live'] = $s->id;
	try {
		$page = OYS_Live::page();
	} finally {
		unset( $_GET['live'] );
		wp_set_current_user( 0 );
	}
	if ( OYS_Live::uses_live( $s ) ) {
		ok( str_contains( $page, 'data-live-count' ) && str_contains( $page, 'data-u="h"' ) && str_contains( $page, 'oys-lobby__opens' ), 'the waiting room: a countdown and when the room opens' );
	} else {
		ok( true, 'live rooms are off on this test site' );
	}
} );

test( 'Áron, 16 November – the Studio on the phone: its own link, an installable app with the logo, the guide', function () {
	eq( 'hub', OYS_Studio_App::match( '/studio/' ), '/studio/ is the Hub' );
	eq( 'hub', OYS_Studio_App::match( '/studio?open=inbox' ), 'also without the slash' );
	eq( 'guide', OYS_Studio_App::match( '/studio/utmutato/' ), '/studio/utmutato/ is the guide' );
	eq( 'guide', OYS_Studio_App::match( '/studio/' . rawurlencode( 'útmutató' ) . '/' ), 'with the accents too' );
	eq( 'manifest', OYS_Studio_App::match( '/', array( 'oys_app' => 'manifest' ) ), 'the manifest' );
	eq( 'sw', OYS_Studio_App::match( '/', array( 'oys_app' => 'sw' ) ), 'the service worker' );
	eq( '', OYS_Studio_App::match( '/studios/' ), 'other addresses are not ours' );
	eq( '', OYS_Studio_App::match( '/blog/studio/' ), 'not ours either' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	ok( str_ends_with( OYS_Studio_App::hub_target( 'inbox', $admin ), 'page=oys#inbox' ), '/studio/?open=inbox opens the messages' );
	ok( str_ends_with( OYS_Studio_App::hub_target( 't12', $admin ), 'page=oys#t12' ), 'and a conversation' );
	ok( str_ends_with( OYS_Studio_App::hub_target( '"><script>', $admin ), 'page=oys' ), 'anything else: just the Hub' );
	$m = OYS_Studio_App::manifest();
	ok( str_ends_with( $m['start_url'], '/studio/' ) && 'standalone' === $m['display'], 'the app starts at /studio/, in its own window' );
	eq( 3, count( $m['icons'] ), 'three icons, one maskable' );
	foreach ( array( 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'apple-touch-icon.png' ) as $f ) {
		$size = getimagesize( OYS_DIR . 'assets/app/' . $f );
		ok( $size && $size[0] === $size[1] && $size[0] >= 180, $f . ' is there, square' );
	}
	ok( str_contains( OYS_Studio_App::head_tags(), 'rel="manifest"' ) && str_contains( OYS_Studio_App::head_tags(), 'apple-touch-icon' ), 'the Hub page carries the manifest and the home-screen icon' );
	$sw = OYS_Studio_App::worker();
	ok( str_contains( $sw, "addEventListener('push'" ) && str_contains( $sw, 'notificationclick' ) && ! str_contains( $sw, "'fetch'" ), 'the worker shows notifications and never sits between the page and the server' );
	wp_set_current_user( $admin );
	try {
		ob_start();
		OYS_Studio_App::render_guide();
		$g = ob_get_clean();
	} finally {
		wp_set_current_user( 0 );
	}
	ok( str_contains( $g, 'Az első lépéseid' ) && str_contains( $g, 'Főképernyőhöz adás' ) && str_contains( $g, '/studio/' ), 'the guide: first steps, how to put it on the phone, the link' );
	ok( str_contains( $g, 'name="viewport"' ) && str_contains( $g, 'noindex' ), 'made for the phone, not for search engines' );
} );

test( 'Áron, 16 November – the Studio app: notifications on the phone about messages and class chats', function () {
	delete_option( OYS_Studio_App::SUBS );
	$v = OYS_Studio_App::vapid();
	ok( $v && 87 === strlen( $v['public'] ) && str_contains( $v['private'], 'PRIVATE KEY' ), 'a VAPID key pair is made on first use' );
	eq( $v['public'], OYS_Studio_App::vapid()['public'], 'and kept' );
	$jwt = explode( '.', OYS_Studio_App::jwt( 'https://push.example.test/abc', $v ) );
	eq( 3, count( $jwt ), 'a signed token for the push service' );
	eq( 64, strlen( base64_decode( strtr( $jwt[2], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $jwt[2] ) % 4 ) % 4 ) ) ), 'ES256: a 64-byte signature' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	ok( ! OYS_Studio_App::subscribe( $admin, 'http://evil.test/x' ), 'only https push addresses' );
	$ep = 'https://push.example.test/send/olivia-phone';
	ok( OYS_Studio_App::subscribe( $admin, $ep, 'iPhone' ), 'her phone is switched on' );
	eq( 1, OYS_Studio_App::devices( $admin ), 'one device' );
	$pings = array();
	$fake  = function ( $pre, $args, $url ) use ( &$pings ) {
		if ( str_starts_with( $url, 'https://push.example.test/' ) ) {
			$pings[] = array( $url, $args['headers'] );
			return array( 'response' => array( 'code' => str_contains( $url, 'gone' ) ? 410 : 201, 'message' => '' ), 'body' => '', 'headers' => array(), 'cookies' => array() );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $fake, 4, 3 );
	try {
		$u = make_user( 'Pushvendeg' );
		$t = OYS_Hub::thread_for( $u );
		OYS_Hub::add( $t, array( 'body' => 'Szia! Holnap tudok jönni?', 'from_studio' => false, 'author_id' => $u ) );
		$item = OYS_Studio_App::pull( $ep );
		eq( 1, count( $item ), 'a customer writes: a notification waits for her phone' );
		ok( str_contains( $item[0]['body'] ?? '', 'Holnap tudok jönni?' ) && str_ends_with( $item[0]['url'] ?? '', '/studio/?open=t' . $t->id ), 'with the message, opening the conversation' );
		eq( array(), OYS_Studio_App::pull( $ep ), 'taken once' );
		OYS_Studio_App::flush();
		ok( count( $pings ) >= 1 && str_starts_with( $pings[0][1]['Authorization'] ?? '', 'vapid t=' ) && 'high' === ( $pings[0][1]['Urgency'] ?? '' ), 'an empty push, signed, high urgency' );
		OYS_Hub::add( $t, array( 'body' => 'Persze, várlak!', 'from_studio' => true, 'author_id' => $admin ) );
		eq( array(), OYS_Studio_App::pull( $ep ), 'her own answer: no notification' );
		$s   = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => -30 ) );
		$who = array( 'owner' => false, 'booking' => (object) array( 'id' => 999998 ), 'user_id' => $u, 'name' => 'Push V.' );
		OYS_Live::post_message( $s, $who, 'Köszi a mai órát!' );
		$item = OYS_Studio_App::pull( $ep );
		ok( str_contains( $item[0]['body'] ?? '', 'Köszi a mai órát!' ) && str_ends_with( $item[0]['url'] ?? '', '/studio/?open=c' . $s->id ), 'a class chat message after the class: a notification' );
		$now = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => 0 ) );
		OYS_Studio_App::on_class_chat( 0, $now, $who );
		eq( array(), OYS_Studio_App::pull( $ep ), 'during the class: none' );
		$tt  = make_teacher();
		$tlu = wp_insert_user( array( 'user_login' => 'pushtanar', 'user_email' => 'pushtanar@example.test', 'user_pass' => 'x-12345678', 'role' => 'oys_teacher' ) );
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'teachers' ), array( 'user_id' => $tlu ), array( 'id' => $tt->id ) );
		OYS_Teachers::flush();
		OYS_Studio_App::subscribe( $tlu, 'https://push.example.test/send/teacher' );
		OYS_Hub::add( $t, array( 'body' => 'Még egy kérdés.', 'from_studio' => false, 'author_id' => $u ) );
		eq( array(), OYS_Studio_App::pull( 'https://push.example.test/send/teacher' ), 'a teacher hears only about their own conversations' );
		eq( 1, count( OYS_Studio_App::pull( $ep ) ), 'the studio does' );
		OYS_Studio_App::subscribe( $admin, 'https://push.example.test/send/gone' );
		OYS_Studio_App::ping( 'https://push.example.test/send/gone' );
		ok( ! isset( OYS_Studio_App::subs()[ md5( 'https://push.example.test/send/gone' ) ] ), 'a device the push service no longer knows is forgotten' );
		wp_set_current_user( $u );
		eq( 403, rest_do_request( new WP_REST_Request( 'GET', '/oys/v1/hub/push' ) )->get_status(), 'customers have no push settings' );
		wp_set_current_user( $admin );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/oys/v1/hub/push' ) );
		ok( 200 === $res->get_status() && $v['public'] === $res->get_data()['key'] && str_ends_with( $res->get_data()['link'], '/studio/' ), 'the Hub gets the key and the link' );
		$test = rest_do_request( new WP_REST_Request( 'POST', '/oys/v1/hub/push/test' ) );
		ok( $test->get_data()['devices'] >= 1, 'a test notification goes to her devices' );
		OYS_Studio_App::flush();
	} finally {
		wp_set_current_user( 0 );
		remove_filter( 'pre_http_request', $fake, 4 );
		remove_action( 'shutdown', array( 'OYS_Studio_App', 'flush' ) );
		delete_option( OYS_Studio_App::SUBS );
	}
} );

test( 'Áron, 16 November – the Hub\'s Today: valid passes, locked sessions, what expires soon', function () {
	$before = array_column( OYS_Hub::passes_summary(), 'value', 'label' );
	$u = make_user( 'Berletes' );
	$s = make_session( array( 'in_hours' => 50 ) );
	OYS_Passes::grant( $u, array( 'credits' => 5 ) );
	OYS_Passes::grant( $u, array( 'credits' => 2, 'validity_days' => 5 ) );
	$id = OYS_Bookings::book_party( $u, $s, array( 'method' => 'credit', 'guests' => array(), 'notify' => false ) );
	ok( is_int( $id ), 'booked from a pass' );
	$after = array_column( OYS_Hub::passes_summary(), 'value', 'label' );
	$valid = __( 'Valid passes', 'olivia-studio' );
	$lock  = __( 'Locked sessions', 'olivia-studio' );
	eq( (int) $before[ $valid ] + 2, (int) $after[ $valid ], 'two more valid passes' );
	eq( (int) $before[ $lock ] + 1, (int) $after[ $lock ], 'one more locked session (taken from a pass for a class ahead)' );
	$soon = array_values( array_filter( OYS_Hub::passes_summary(), fn( $r ) => __( 'Expiring in 14 days', 'olivia-studio' ) === $r['label'] ) );
	ok( $soon && 'warn' === $soon[0]['tone'], 'a pass expiring within 14 days is flagged' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	wp_set_current_user( $admin );
	try {
		ok( ! empty( OYS_Hub::today()['passes'] ), 'the studio sees it on the Hub\'s Today' );
	} finally {
		wp_set_current_user( 0 );
	}
	$tu = wp_insert_user( array( 'user_login' => 'berlettanar', 'user_email' => 'berlettanar@example.test', 'user_pass' => 'x-12345678', 'role' => 'oys_teacher' ) );
	wp_set_current_user( $tu );
	try {
		ok( empty( OYS_Hub::today()['passes'] ), 'a teacher does not' );
	} finally {
		wp_set_current_user( 0 );
	}
} );

test( 'Áron, 16 November (Motibro) – the Hub: the week, the attendance sheet after class, the live room fills it in, the reports', function () {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	$s     = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => -5 ) );
	$a     = make_user( 'Jelenlet A' );
	$b     = make_user( 'Jelenlet B' );
	$c     = make_user( 'Jelenlet C' );
	foreach ( array( $a, $b, $c ) as $u ) {
		OYS_Passes::grant( $u, array( 'credits' => 5, 'kind' => 'online' ) );
	}
	$ids = array();
	foreach ( array( $a, $b, $c ) as $u ) {
		$ids[ $u ] = OYS_Bookings::book_manual( $u, $s, 'comp', false, true, 'online' );
	}
	wp_set_current_user( $admin );
	try {
		$week = OYS_Hub_Classes::week( wp_date( 'Y-m-d', oys_ts( $s->starts_at ) ) );
		eq( 7, count( $week['days'] ), 'the week: Monday to Sunday' );
		$found = array_merge( ...array_map( fn( $d ) => array_column( $d['classes'], 'id' ), $week['days'] ) );
		ok( in_array( (int) $s->id, $found, true ), 'the class is in its week' );
		$card = OYS_Hub_Classes::card( $s );
		eq( 3, $card['open'], 'over, three people still to mark' );
		ok( in_array( (int) $s->id, array_column( OYS_Hub_Classes::to_mark(), 'id' ), true ), 'on Today under „after class”' );
		$sheet = OYS_Hub_Classes::sheet( $s );
		eq( 3, count( $sheet['people'] ), 'the sheet lists the three' );
		OYS_Hub_Classes::mark( $s, $ids[ $a ], 'attended' );
		OYS_Hub_Classes::mark( $s, $ids[ $b ], 'no_show' );
		eq( 'attended', OYS_Bookings::get( $ids[ $a ] )->status, 'one tap: here' );
		eq( 'no_show', OYS_Bookings::get( $ids[ $b ] )->status, 'one tap: did not come' );
		ok( is_wp_error( OYS_Hub_Classes::mark( $s, 999999, 'attended' ) ), 'a booking of another class is refused' );
		eq( 1, OYS_Hub_Classes::mark_rest( $s ), '„Everyone came” marks the one left' );
		eq( 'attended', OYS_Bookings::get( $ids[ $c ] )->status, 'the last one too' );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/oys/v1/hub/week' ) );
		eq( 200, $res->get_status(), 'the week over REST' );
		$rep = OYS_Hub_Classes::report( wp_date( 'Y-m', oys_ts( $s->starts_at ) ) );
		$k   = array_column( $rep['kpis'], 1, 0 );
		ok( (int) $k[ __( 'Came', 'olivia-studio' ) ] >= 2 && (int) $k[ __( 'Booked but did not come', 'olivia-studio' ) ] >= 1, 'the month: who came, who did not' );
		ok( str_contains( $rep['export'], 'oys_hub_export' ), 'an Excel export link' );
	} finally {
		wp_set_current_user( 0 );
	}
	// the live room fills the sheet in: who came in attended, who did not did not come
	$s2 = make_session( array( 'format' => 'online', 'capacity' => 8, 'in_hours' => -6 ) );
	$x  = OYS_Bookings::book_manual( $a, $s2, 'comp', false, true, 'online' );
	$y  = OYS_Bookings::book_manual( $b, $s2, 'comp', false, true, 'online' );
	OYS_Hub_Classes::on_join( $s2, array( 'owner' => false, 'booking' => OYS_Bookings::get( $x ) ) );
	OYS_Hub_Classes::on_join( $s2, array( 'owner' => false, 'booking' => OYS_Bookings::get( $x ) ) );
	eq( array( (int) $x ), OYS_Hub_Classes::joined( $s2->id ), 'the room remembers who came in, once' );
	OYS_Hub_Classes::auto_attendance();
	$live = OYS_Live::uses_live( $s2 );
	eq( 'attended', OYS_Bookings::get( $x )->status, 'in the room: here' );
	eq( $live ? 'no_show' : 'attended', OYS_Bookings::get( $y )->status, $live ? 'not in the room: did not come' : 'no live room on this site: everyone booked is here' );
	$u = make_user( 'Nemstudio' );
	wp_set_current_user( $u );
	try {
		eq( 403, rest_do_request( new WP_REST_Request( 'GET', '/oys/v1/hub/reports' ) )->get_status(), 'customers get no reports' );
	} finally {
		wp_set_current_user( 0 );
	}
} );

test( 'Áron, 16 November (Motibro automations) – pushes on bookings, „who came?” after class, the morning summary, retention emails, visits and quick add on the sheet', function () {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	$saved = get_option( OYS_Studio_App::SUBS );
	$got   = array();
	$spy   = function ( $user, $title, $body, $url, $tag, $n ) use ( &$got ) {
		$got[] = compact( 'user', 'title', 'body', 'url', 'tag', 'n' );
	};
	$fake  = fn( $pre, $args, $url ) => str_contains( $url, 'push.example.test' ) ? array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 201, 'message' => 'Created' ), 'cookies' => array() ) : $pre;
	add_action( 'oys_push_notified', $spy, 10, 6 );
	add_filter( 'pre_http_request', $fake, 4, 3 );
	try {
		update_option( OYS_Studio_App::SUBS, array(), false );
		OYS_Studio_App::subscribe( $admin, 'https://push.example.test/send/auto' );
		OYS_Hub::save_prefs( $admin, array() );
		$p = OYS_Hub::prefs( $admin );
		ok( $p['push_bookings'] && $p['push_sheet'] && $p['push_daily'], 'the three phone notifications are on by default' );

		// a customer books → the studio's phone
		$s = make_session( array( 'format' => 'online', 'capacity' => 2, 'in_hours' => 30 ) );
		$u = make_user( 'Pushfoglalo' );
		wp_set_current_user( $u );
		$bid = OYS_Bookings::book_manual( $u, $s, 'comp', false, true, 'online' );
		$hit = array_values( array_filter( $got, fn( $g ) => (int) $g['user'] === (int) $admin && str_contains( $g['title'], 'Pushfoglalo' ) ) );
		ok( $hit && str_contains( $hit[0]['url'], 'open=s' . $s->id ), 'a new booking: a push that opens the class' );
		$got = array();
		OYS_Bookings::cancel( $bid, array( 'notify' => false ) );
		ok( (bool) array_filter( $got, fn( $g ) => str_contains( $g['title'], 'Pushfoglalo' ) ), 'a cancellation: a push' );
		$got = array();
		OYS_Bookings::join_waitlist( $u, $s->id );
		ok( (bool) array_filter( $got, fn( $g ) => 'w' . $s->id === $g['tag'] ), 'someone on the waitlist: a push' );
		OYS_Bookings::leave_waitlist( $u, $s->id );
		// the studio books someone in itself → no push to itself
		wp_set_current_user( $admin );
		$got = array();
		OYS_Bookings::book_manual( make_user( 'Studiofoglal' ), $s, 'comp', false, true, 'online' );
		eq( array(), $got, 'what the studio does itself: no push' );
		// switched off
		OYS_Hub::save_prefs( $admin, array( 'push_bookings' => 0 ) );
		ok( ! OYS_Hub::prefs( $admin )['push_bookings'] && OYS_Hub::prefs( $admin )['push_daily'], 'one switch off, the others kept' );
		wp_set_current_user( make_user( 'Csendes' ) );
		$got = array();
		OYS_Bookings::book_manual( get_current_user_id(), $s, 'comp', false, true, 'online' );
		eq( array(), $got, 'bookings switched off: no push' );
		OYS_Hub::save_prefs( $admin, array( 'push_bookings' => 1 ) );
		wp_set_current_user( 0 );

		// „who came?” 10–70 minutes after the class, once
		$over = make_session( array( 'format' => 'online', 'in_hours' => -1.4 ) ); // ended about 25 minutes ago
		$v1   = make_user( 'Regi' );
		$v2   = make_user( 'Uj' );
		$old  = make_session( array( 'format' => 'online', 'in_hours' => -24 * 70 ) );
		$ob   = OYS_Bookings::book_manual( $v1, $old, 'comp', false, true, 'online' );
		OYS_Bookings::set_attendance( $ob, 'attended' );
		OYS_Bookings::book_manual( $v1, $over, 'comp', false, true, 'online' );
		OYS_Bookings::book_manual( $v2, $over, 'comp', false, true, 'online' );
		$got = array();
		OYS_Hub_Auto::sheet_reminders();
		$hit = array_values( array_filter( $got, fn( $g ) => 's' . $over->id === $g['tag'] ) );
		ok( $hit && str_contains( $hit[0]['url'], 'open=s' . $over->id ), 'after class: „who came?” opens the attendance sheet' );
		$got = array();
		OYS_Hub_Auto::sheet_reminders();
		eq( array(), array_filter( $got, fn( $g ) => 's' . $over->id === $g['tag'] ), 'only once' );
		ok( str_contains( OYS_Studio_App::hub_target( 's' . $over->id, $admin ), '#s' . $over->id ), 'the app link opens the sheet' );

		// the morning summary
		$text = OYS_Hub_Auto::summary( $admin );
		ok( '' !== $text, 'the morning: the day and what is waiting' );
		$got = array();
		eq( 1, OYS_Hub_Auto::morning_send(), 'the morning push goes to the phone' );
		update_option( 'oys_daily_push', wp_date( 'Y-m-d' ), false );
		$got = array();
		OYS_Hub_Auto::morning();
		eq( array(), $got, 'once a day' );

		// the sheet: visits, first class, quick add
		wp_set_current_user( $admin );
		$sheet = OYS_Hub_Classes::sheet( OYS_Schedule::get( $over->id ) );
		$by    = array_column( $sheet['people'], null, 'user' );
		ok( $by[ $v2 ]['first'], 'never came before: „First class”' );
		ok( ! $by[ $v1 ]['first'] && $by[ $v1 ]['away'] && str_contains( $by[ $v1 ]['visit'], '2' ), 'came before, long ago: class no. 2, back after a long time' );
		$v3 = make_user( 'Gyors' );
		OYS_Passes::grant( $v3, array( 'credits' => 3, 'kind' => 'online' ) );
		ok( true === OYS_Hub_Classes::add( OYS_Schedule::get( $over->id ), $v3 ), 'quick add after class' );
		$row = array_values( array_filter( OYS_Bookings::for_session( $over->id, array( 'attended' ) ), fn( $b ) => (int) $b->user_id === $v3 ) );
		ok( $row && 'credit' === $row[0]->paid_with, 'with their pass, marked as came' );
		ok( is_wp_error( OYS_Hub_Classes::add( OYS_Schedule::get( $over->id ), $v3 ) ), 'not twice' );
		$req = new WP_REST_Request( 'POST', '/oys/v1/hub/class/' . $over->id . '/add' );
		$req->set_body_params( array( 'user' => make_user( 'Restben' ) ) );
		eq( 200, rest_do_request( $req )->get_status(), 'quick add over REST' );
		eq( OYS_VERSION, OYS_Hub::rest_poll()->get_data()['v'], 'the open app learns the version (and reloads itself on a new one)' );
		wp_set_current_user( 0 );
	} finally {
		remove_action( 'oys_push_notified', $spy, 10 );
		remove_filter( 'pre_http_request', $fake, 4 );
		update_option( OYS_Studio_App::SUBS, $saved ?: array(), false );
		wp_set_current_user( 0 );
	}

	// the customer emails: off until the studio switches them on
	ok( ! OYS_Email_Templates::enabled( 'we_miss_you' ) && ! OYS_Email_Templates::enabled( 'weekly_digest' ), 'the two new customer emails start switched off' );
	$mails = array();
	$catch = function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $catch, 5, 2 );
	try {
		$d = make_user( 'Lemorzsolodo' );
		foreach ( array( -24 * 50, -24 * 55 ) as $h ) {
			$x = make_session( array( 'in_hours' => $h ) );
			OYS_Bookings::set_attendance( OYS_Bookings::book_manual( $d, $x, 'comp', false, true ), 'attended' );
		}
		$now = new DateTimeImmutable( 'next tuesday 11:00', wp_timezone() );
		eq( 0, OYS_Hub_Auto::retention_send( $now )['missed'], 'switched off: nothing goes' );
		OYS_Email_Templates::save( 'we_miss_you', array( 'enabled' => 1 ) );
		OYS_Email_Templates::save( 'weekly_digest', array( 'enabled' => 1 ) );
		$mails = array();
		ok( OYS_Hub_Auto::retention_send( $now )['missed'] >= 1, '„we miss you” to those drifting away' );
		ok( (bool) array_filter( $mails, fn( $m ) => str_contains( (string) $m['to'], 'lemorzsolodo' ) ), 'to her' );
		$mails = array();
		OYS_Hub_Auto::retention_send( $now );
		eq( array(), array_filter( $mails, fn( $m ) => str_contains( (string) $m['to'], 'lemorzsolodo' ) ), 'not again for 60 days' );
		// Saturday: next week's classes, to the newsletter subscribers
		$mon = new DateTimeImmutable( 'monday next week 18:00', wp_timezone() );
		make_session( array( 'in_hours' => ( $mon->getTimestamp() - time() ) / HOUR_IN_SECONDS ) );
		$sub = make_user( 'Hirlevel' );
		update_user_meta( $sub, 'oys_marketing', '1' );
		$no  = make_user( 'Nemkeri' );
		$mails = array();
		delete_option( 'oys_weekly_digest' );
		$sat = new DateTimeImmutable( 'saturday this week 10:30', wp_timezone() );
		ok( OYS_Hub_Auto::retention_send( $sat )['weekly'] >= 1, 'Saturday: the weekly classes go out' );
		$to_sub = array_values( array_filter( $mails, fn( $m ) => str_contains( (string) $m['to'], 'hirlevel' ) ) );
		ok( $to_sub && str_contains( $to_sub[0]['message'], 'oys_unsub' ), 'to the subscriber, with the unsubscribe link' );
		eq( array(), array_filter( $mails, fn( $m ) => str_contains( (string) $m['to'], 'nemkeri' ) ), 'not to those who did not ask for the newsletter' );
		$mails = array();
		OYS_Hub_Auto::retention_send( $sat );
		eq( array(), array_filter( $mails, fn( $m ) => str_contains( (string) $m['to'], 'hirlevel' ) ), 'once a week' );
	} finally {
		remove_filter( 'pre_wp_mail', $catch, 5 );
		OYS_Email_Templates::save( 'we_miss_you', array( 'enabled' => 0 ) );
		OYS_Email_Templates::save( 'weekly_digest', array( 'enabled' => 0 ) );
	}
} );

test( 'Áron, 16 November: Motibro valid passes and class visits → the customers (history, credits), nobody emailed, a second run changes nothing', function () {
	$mails = 0;
	$count = function () use ( &$mails ) {
		$mails++;
		return true;
	};
	$pushes = 0;
	$spy    = function () use ( &$pushes ) {
		$pushes++;
	};
	add_filter( 'pre_wp_mail', $count, 1 );
	add_action( 'oys_push_notified', $spy );
	try {
		$known = make_user( 'Ismert' );
		update_user_meta( $known, 'oys_ext_id', '990001' );
		update_user_meta( $known, 'oys_ext_source', 'motibro' );
		$kmail = get_userdata( $known )->user_email;
		$visit = fn( $id, $mail, $name, $when, $title = 'Hatha flow jóga 18. kerület', $place = 'Prána Jógaközpont', $used = 'Hatha flow jóga bérlet - 5 alkalom (60p)', $money = '2300.0' ) => array(
			'Vendég neve' => $name, 'Email' => $mail, 'Vendég ID' => $id, 'Óra időpontja' => $when, 'Óra elnevezése' => $title, 'Kurzus neve' => $title,
			'Tanfolyam neve' => '', 'Oktató' => 'Teszt Oktató', 'Helyszín' => $place, 'Terem' => 'kerti terem', 'Bérletfelhasználás' => $used,
			'Órára jutó bevétel (HUF)' => $money, 'Számla' => 'Igen', 'Megjegyzés' => '',
		);
		$rows = array(
			$visit( '990001', $kmail, 'Ismert Teszt', '2025-03-03 17:45' ),
			$visit( '990002', 'uj.vendeg.motibro@example.test', 'Újvendég Anna', '2025-03-03 17:45' ),
			$visit( '990001', $kmail, 'Ismert Teszt', '2025-03-10 17:45' ),
			$visit( '990001', $kmail, 'Ismert Teszt', '2025-04-01 18:00', 'Slow flow jóga 11. kerület', 'Budai PK', 'Slow flow jóga - 1 alkalom (90p)', '5000.0' ),
			$visit( '990003', '', 'Emailnelkuli Bea', '2025-03-10 17:45' ),
			$visit( '990001', $kmail, 'Ismert Teszt', '2026-01-31 10:00', 'HANGjóga 2026.01.31. - 18. kerület', 'Prána Jógaközpont', 'HANGjóga jegy', '8000.0' ),
		);
		eq( 'visits', OYS_Import::detect( $rows ), 'the visits export is recognised by its columns' );
		$dry = OYS_Import::import_any( $rows, 'motibro', true );
		ok( 4 === $dry['classes_created'] && 6 === $dry['visits_added'] && 2 === $dry['customers_created'], 'check only: 4 classes, 6 visits, 2 new customers' );
		eq( 0, (int) count( get_users( array( 'search' => 'uj.vendeg.motibro@example.test', 'search_columns' => array( 'user_email' ) ) ) ), 'check only changes nothing' );
		$res = OYS_Import::import_any( $rows, 'motibro', false );
		ok( 4 === $res['classes_created'] && 6 === $res['visits_added'] && 2 === $res['customers_created'] && 0 === $res['skipped'], 'imported: 4 classes, 6 visits, 2 new customers' );
		$new = get_user_by( 'email', 'uj.vendeg.motibro@example.test' );
		ok( $new && 'Anna' === $new->first_name && 'Újvendég' === $new->last_name && '990002' === get_user_meta( $new->ID, 'oys_ext_id', true ), 'a new guest: „Vezetéknév Keresztnév” split, the Motibro id kept' );
		ok( str_starts_with( $new->user_registered, '2025-03-0' ), 'registered on the day of their first class there (not a new customer of today)' );
		$hist = OYS_Bookings::for_user( $known, 'past', array( 'attended' ) );
		eq( 4, count( $hist ), 'the known guest has the 4 classes in the history' );
		ok( 'import' === $hist[0]->paid_with && str_contains( (string) $hist[0]->note, 'Motibro:' ), 'marked as from the earlier system, with the Motibro pass in the note' );
		global $wpdb;
		$slow = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE title = %s', 'Slow flow jóga 11. kerület' ) );
		ok( $slow && 90 * 60 === oys_ts( $slow->ends_at ) - oys_ts( $slow->starts_at ) && str_contains( $slow->location, 'Budai PK' ) && 'studio' === $slow->format, 'the class: 90 minutes for slow flow, the place kept' );
		$hang = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE title = %s', 'HANGjóga 2026.01.31. - 18. kerület' ) );
		eq( 'event', $hang->kind, 'a dated special is an event' );
		$first = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE title = %s AND starts_at = %s', 'Hatha flow jóga 18. kerület', oys_local_to_utc( '2025-03-03 17:45:00' ) ) );
		eq( 2, (int) $first->booked, 'two people in the first class' );
		// the history counts on the attendance sheet
		$s     = make_session( array( 'format' => 'online', 'in_hours' => 30 ) );
		$v     = OYS_Hub_Classes::visits( $s, array( $known ) );
		ok( ! $v[ $known ]['first'] && str_contains( $v[ $known ]['line'], '5' ), 'online, the known guest comes for the 5th time, not the first' );
		$again = OYS_Import::import_any( $rows, 'motibro', false );
		ok( 0 === $again['classes_created'] && 0 === $again['visits_added'] && 6 === $again['visits_there'], 'a second run changes nothing' );

		// valid passes (Olívia, 16 November): what is left is collected, not bookable online; redeemable by value
		$prow = fn( $mail, $used, $locked, $left, $exp ) => array( 'Vendég' => 'Ismert Teszt', 'Email' => $mail, 'Felhasznált alkalmak' => $used, 'Zárolt alkalmak' => $locked, 'Hátralévő alkalmak' => $left, 'Lejárat' => $exp, 'Bérlet neve' => 'Hatha flow jóga bérlet - 5 alkalom (60p)', 'Oktató' => 'Teszt Oktató' );
		$passes = array( $prow( $kmail, 2, 1, 2, '2027-01-15' ), $prow( 'uj.vendeg.motibro@example.test', 0, 0, 5, '2027-03-01' ), $prow( 'nincs.ilyen@example.test', 1, 0, 4, '2027-02-01' ) );
		eq( 'passes', OYS_Import::detect( $passes ), 'the passes export is recognised' );
		$p = OYS_Import::import_any( $passes, 'motibro', false );
		ok( 'held' === $p['mode'] && 3 === $p['created'] && 11 === $p['credits'] && 1 === $p['locked'], 'three passes collected, 11 sessions left, 1 locked in Motibro' );
		eq( 0, OYS_Passes::available_for( $known, $s, 'online' ), 'not bookable online' );
		$held = OYS_Held::for_user( $known );
		ok( 1 === count( $held ) && 2 === $held[0]['left'] && 230000 === $held[0]['per_cents'] && 460000 === OYS_Held::value_cents( $held[0] ), 'collected for the studio: 2 sessions, worth what they paid there (2 × 2 300 Ft)' );
		ok( (bool) get_user_by( 'email', 'nincs.ilyen@example.test' ), 'a pass holder not here yet becomes a customer' );
		[ $hn, $hs ] = OYS_Held::totals();
		ok( $hn >= 3 && $hs >= 11, 'the totals on the Hub' );
		wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
		ok( 2 === OYS_Hub::customer_card( $known )['held'][0]['left'], 'the Hub card shows it when the customer writes' );
		ok( (bool) array_filter( OYS_Hub::passes_summary(), fn( $t ) => 'tag' === $t['icon'] && str_contains( $t['value'], '11' ) ), 'and a tile on Today' );
		wp_set_current_user( 0 );
		$same = OYS_Import::import_any( $passes, 'motibro', false );
		eq( 3, $same['unchanged'], 'the same file again changes nothing' );
		$passes[0] = $prow( $kmail, 3, 0, 1, '2027-01-15' );
		$upd       = OYS_Import::import_any( $passes, 'motibro', false );
		ok( 1 === $upd['updated'] && 1 === OYS_Held::for_user( $known )[0]['left'], 'a newer export (one more used there) updates it' );
		// the end of November: what is left becomes online credits until 27 January (or the pass's own later day)
		$late = OYS_Import::import_any( array( $passes[0], $passes[1] ), 'motibro', false, 'passes', '2027-01-27' );
		ok( 'online' === $late['mode'] && 2 === $late['created'] && 1 === $late['missing'], 'online until 27 January; the pass no longer in the export is flagged' );
		eq( 1, OYS_Passes::available_for( $known, $s, 'online' ), 'now bookable online: the 1 left' );
		$pass = OYS_Passes::for_user( $known, true )[0];
		ok( '2027-01-27' === wp_date( 'Y-m-d', oys_ts( $pass->expires_at ) ) && 'motibro' === $pass->source && 1 === (int) $pass->expiry_notice_sent, 'until 27 January, no expiry email' );
		$newp = OYS_Passes::for_user( $new->ID, true )[0];
		eq( '2027-03-01', wp_date( 'Y-m-d', oys_ts( $newp->expires_at ) ), 'a pass valid longer keeps its own day' );
		eq( array(), OYS_Held::for_user( $known ), 'no longer in the list to redeem' );
		$again = OYS_Import::import_any( array( $passes[0], $passes[1] ), 'motibro', false, 'passes', '2027-01-27' );
		eq( 2, $again['unchanged'], 'the same late export again changes nothing' );
		// redeem by value; the earlier bookable passes moved into the list
		$third = OYS_Held::for_user( get_user_by( 'email', 'nincs.ilyen@example.test' )->ID )[0];
		ok( ! empty( $third['missing'] ), 'the one missing from the newer export is marked' );
		$r = OYS_Held::act( $third['key'], 'redeem', 'egyéni óra' );
		ok( 'redeemed' === $r['status'] && str_contains( $r['note'], 'egyéni óra' ), 'redeemed, with what for' );
		global $wpdb;
		$old = make_user( 'Regibérlet' );
		$wpdb->insert( OYS_Install::table( 'passes' ), array( 'user_id' => $old, 'product_id' => 'motibro-0123456789abcdef', 'name' => 'Hatha flow jóga bérlet - 5 alkalom (60p)', 'kind' => 'online', 'credits_total' => 5, 'credits_left' => 3, 'expires_at' => oys_utc_plus( 20 * DAY_IN_SECONDS ), 'source' => 'motibro', 'created_at' => oys_now() ) );
		$conv = OYS_Held::from_passes( 'motibro' );
		ok( $conv['held'] >= 1 && 0 === OYS_Passes::balance( $old, 'online' ) && 3 === OYS_Held::for_user( $old )[0]['left'], 'the passes imported as bookable earlier move into the list' );
	} finally {
		remove_filter( 'pre_wp_mail', $count, 1 );
		remove_action( 'oys_push_notified', $spy );
	}
	eq( 0, $mails, 'nobody was emailed' );
	eq( 0, $pushes, 'no phone notification' );
} );

test( 'Olívia, 17 November (chat): the one-to-one rule on its own line; the flow class tunes in first, arrives second, no „rövid”; „minden ászanához”', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true );
	$hf   = $data['classes']['hatha-flow']['content'];
	$flow = oy_flows( $hf );
	preg_match_all( '#<li><span class="oj-flow__n" aria-hidden="true">(\d)</span><p>([^<]*)#u', $flow, $st );
	ok( str_contains( $flow, 'oj-flow--n5 oj-flow--wave' ) && 5 === count( $st[0] ), 'five steps on the wave' );
	ok( str_starts_with( $st[2][0] ?? '', 'Ráhangolódunk az órára: pár percet beszélek arról a témáról, amit a jógafilozófiából hoztam' ) && str_contains( $st[2][0], 'ászanákban.' ), '1. tuning in: the theme she brings from yoga philosophy' );
	ok( str_starts_with( $st[2][1] ?? '', 'Megérkezünk: néhány perc légzés' ), '2. arriving: a few minutes of breathing' );
	ok( ! str_contains( $hf, 'rövid relaxáció' ) && str_contains( $hf, 'néhány pillanat csend és relaxáció.' ), 'no „rövid” before the relaxation' );
	ok( ! str_contains( $hf, 'pózhoz' ) && str_contains( $hf, 'minden ászanához adok könnyebb és erősebb változatot' ), '„minden ászanához”' );
	ok( str_contains( $data['pages']['schedule-pricing']['content'], 'visszakapod az alkalmat.<br>Az egyéni órát 24 órával' ), 'prices page: the one-to-one rule on its own line' );
	// the live site: the old text changes, and her own version of the paragraph (her lines, her words) follows it
	update_option( 'oy_edition', 'hu-online' );
	$p1   = 'Megérkezéssel kezdünk: néhány perc légzés, hogy a napot a nappali ajtaján kívül hagyd. Aztán fokozatosan melegítünk be: talajgyakorlatokkal.';
	$p2   = 'Az óra utolsó része lelassul: nyújtás, néhány pillanat csend és egy rövid relaxáció.';
	$p3   = 'Az óráimra kezdőket is szeretettel várok: minden pózhoz adok könnyebb és erősebb változatot.';
	$hers = 'Megérkezéssel kezdünk: néhány perc légzés, hogy a napot a nappali ajtaján kívül hagyd.<br>Aztán fokozatosan melegítünk be: talajgyakorlatok segítségével.';
	$post = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
	$id   = $post ? $post->ID : wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Csoportos online flow jóga', 'post_name' => 'hatha-flow' ) );
	$was  = $post ? $post->post_content : null;
	$e0   = get_option( 'oye_edits' );
	$m0   = get_option( 'oye_edits_meta' );
	try {
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => "<h2>Mi történik egy online flow jógaórán</h2>\n<p>$p1</p>\n<p>$p2</p>\n<h2>Kinek szól</h2>\n<p>$p3</p>" ) ) );
		update_option( 'oye_edits', array( 't' => array( oye_text_key( $p1 ) => $hers ), 'i' => array() ) );
		update_option( 'oye_edits_meta', array( oye_text_key( $p1 ) => array( 'def' => $p1, 'user' => 'Olívia' ) ) );
		delete_option( 'oy_1117_rev' );
		oy_migrate_1117();
		$c  = get_post_field( 'post_content', $id );
		$ed = oye_edits()['t'];
		$k1 = oye_text_key( oy_1117_swap( oy_1117_pairs()['hatha-flow']['content'], $p1 ) );
		ok( str_contains( $c, '<p>' . OY_1117_TUNE . '<br>Megérkezünk: néhány perc légzés' ) && str_contains( $c, 'csend és relaxáció.' ) && str_contains( $c, 'minden ászanához adok' ), 'live update: the page\'s own text changes' );
		ok( 1 === count( $ed ) && isset( $ed[ $k1 ] ) && $ed[ $k1 ] === OY_1117_TUNE . '<br>Megérkezünk: néhány perc légzés, hogy a napot a nappali ajtaján kívül hagyd.<br>Aztán fokozatosan melegítünk be: talajgyakorlatok segítségével.', 'her own version follows the paragraph: tuning in on a line of its own, her words kept' );
		ok( 'Olívia' === ( get_option( 'oye_edits_meta' )[ $k1 ]['user'] ?? '' ), '… and the editor still knows it as hers' );
		$page = oy_lines( oye_pass( apply_filters( 'the_content', $c ) ) );
		preg_match_all( '#<li><span class="oj-flow__n" aria-hidden="true">(\d)</span><p>([^<]*)#u', $page, $st2 );
		ok( 4 === count( $st2[0] ) && str_starts_with( $st2[2][0], 'Ráhangolódunk' ) && str_starts_with( $st2[2][1], 'Megérkezünk' ) && str_contains( $st2[2][2], 'talajgyakorlatok segítségével' ), 'on the page: 1. tuning in, 2. arriving, then her steps' );
		update_option( 'oye_edits', array( 't' => array(), 'i' => array() ) );
		$own = oy_lines( oye_pass( apply_filters( 'the_content', $c ) ) );
		preg_match_all( '#<li><span class="oj-flow__n" aria-hidden="true">(\\d)</span><p>([^<]*)#u', $own, $st3 );
		ok( 4 === count( $st3[0] ) && str_starts_with( $st3[2][0], 'Ráhangolódunk' ) && str_starts_with( $st3[2][1], 'Megérkezünk' ), 'without her version: the page\'s own lines, 1. tuning in, 2. arriving' );
		update_option( 'oye_edits', array( 't' => $ed, 'i' => array() ) );
		$n = count( $ed );
		oy_migrate_1117();
		ok( count( oye_edits()['t'] ) === $n && get_post_field( 'post_content', $id ) === $c, 'once only' );
	} finally {
		null === $was ? wp_delete_post( $id, true ) : wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $was ) ) );
		false === $e0 ? delete_option( 'oye_edits' ) : update_option( 'oye_edits', $e0 );
		false === $m0 ? delete_option( 'oye_edits_meta' ) : update_option( 'oye_edits_meta', $m0 );
		delete_option( 'oy_1117_rev' );
		delete_option( 'oy_edition' );
	}
	update_option( 'oy_edition', 'hu-online' );
	$keep = OYS_Settings::get( 'cancel_policy' );
	try {
		OYS_Settings::update( array( 'cancel_policy' => 'A csoportos órát 12 órával előtte lemondhatod. A később lemondott óra elhasználódik. Az egyéni órát 24 órával előtte lehet áttenni vagy lemondani.' ) );
		ok( str_contains( oy_cancel_note(), 'elhasználódik.<br>Az egyéni órát 24 órával' ), '„Lemondás:” under the timetable: the one-to-one rule starts a new line' );
		ok( ! str_contains( oy_cancel_note( 'x', 'private' ), '<br>' ) && ! str_contains( oy_cancel_note( 'x', 'group' ), 'egyéni' ), '… a class page still shows only its own rule' );
	} finally {
		OYS_Settings::update( array( 'cancel_policy' => $keep ) );
		delete_option( 'oy_edition' );
	}
} );

test( 'Olívia, 17 November (chat): the group class page has its prices like the others; no „-tól” on any price', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	$keep = get_option( OYS_Products::OPTION );
	update_option( 'oy_edition', 'hu-online' );
	try {
		$all = array(); // only these: the group card picks the single ticket and the biggest pass on sale
		$all['t-group-1'] = array( 'name' => '1 alkalmas online jóga jegy', 'kind' => 'online_pack', 'credits' => 1, 'validity_days' => 56, 'price_cents' => 300000, 'duration_min' => 90, 'active' => 1, 'sort' => 1 );
		$all['t-group-5'] = array( 'name' => '5 alkalmas bérlet', 'kind' => 'online_pack', 'credits' => 5, 'validity_days' => 56, 'price_cents' => 1300000, 'duration_min' => 90, 'active' => 1, 'sort' => 2 );
		$all['t-solo-60'] = array( 'name' => 'Egyéni 60', 'kind' => 'private_single', 'service' => 't-solo', 'people' => 1, 'credits' => 1, 'price_cents' => 800000, 'duration_min' => 60, 'active' => 1, 'sort' => 3 );
		update_option( OYS_Products::OPTION, $all );
		$g = do_shortcode( '[oy_prices service="group"]' );
		ok( str_contains( $g, 'oj-price oj-price--solo' ) && str_contains( $g, esc_html( oys_money( 300000 ) ) ) && str_contains( $g, esc_html( oys_money( 1300000 ) ) ) && str_contains( $g, esc_html( oys_money( 260000 ) ) ), 'the group card: one class, the 5-class pass and its price per class' );
		ok( str_contains( $g, 'product=t-group-5' ) && str_contains( $g, esc_url( oy_book_url() ) ) && str_contains( $g, esc_html__( 'In a group', 'olivia-yoga' ) ), '… book a class or buy the pass, „Csoportban”' );
		$solo = oy_price_cards( 't-solo' );
		ok( ! str_contains( $solo, 'oj-price__from' ) && ! str_contains( $g, 'oj-price__from' ) && str_contains( $solo, esc_html( oys_money( 800000 ) ) ), 'no price in a card\'s head (no „-tól”, and not twice): the rows say it' );
		$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true );
		ok( str_contains( $data['classes']['hatha-flow']['content'], "<h2>Árak</h2>\n[oy_prices service=\"group\"]\n[oy_booking_link]" ), 'the group class page: „Árak” before the booking button' );
		$post = get_page_by_path( 'hatha-flow', OBJECT, 'oy_class' );
		$id   = $post ? $post->ID : wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => 'Csoportos online flow jóga', 'post_name' => 'hatha-flow' ) );
		$was  = $post ? $post->post_content : null;
		try {
			wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => "<h2>Online, élőben</h2>\n<p>Élő.</p>\n[oy_booking_link]" ) ) );
			delete_option( 'oy_1117_rev' );
			oy_migrate_1117();
			$c = get_post_field( 'post_content', $id );
			ok( 1 === substr_count( $c, '<h2>Árak</h2>' ) && strpos( $c, '[oy_prices service="group"]' ) < strpos( $c, '[oy_booking_link]' ), 'live update: the prices on the group class page, once' );
		} finally {
			null === $was ? wp_delete_post( $id, true ) : wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $was ) ) );
			delete_option( 'oy_1117_rev' );
		}
	} finally {
		false === $keep ? delete_option( OYS_Products::OPTION ) : update_option( OYS_Products::OPTION, $keep );
		delete_option( 'oy_edition' );
	}
} );

test( 'Olívia and Áron, 17 November: stretching and relaxation explain how it works (payment too); the questions keep the tapped one in place; touch buttons 44 px', function () {
	require_once get_template_directory() . '/inc/demo-import.php';
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/demo/content-hu-online.json' ), true );
	foreach ( oy_1117_how() as $slug => $text ) {
		ok( str_contains( $data['classes'][ $slug ]['content'], '<p>' . $text . '</p>' ) && str_contains( $text, 'kifizetheted' ) && str_contains( $text, 'javaslattal' ), $slug . ': the steps with the answer, accepting and paying, in her word („javaslattal”)' );
	}
	update_option( 'oy_edition', 'hu-online' );
	$made = array();
	$e0   = get_option( 'oye_edits' );
	try {
		foreach ( array_keys( oy_1117_how() ) as $slug ) {
			$p = get_page_by_path( $slug, OBJECT, 'oy_class' );
			$made[ $slug ] = $p ? array( $p->ID, $p->post_content ) : array( wp_insert_post( array( 'post_type' => 'oy_class', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug ) ), null );
			wp_update_post( wp_slash( array( 'ID' => $made[ $slug ][0], 'post_content' => "<h2>Hogyan működik?</h2>\n<p>" . OY_1117_HOW_OLD . "</p>\n[oy_private_link]" ) ) );
		}
		update_option( 'oye_edits', array( 't' => array( oye_text_key( OY_1117_HOW_OLD ) => 'Kérj időpontot a fiókodban, és visszaírok egy javaslattal.' ), 'i' => array() ) );
		delete_option( 'oy_1117_rev' );
		oy_migrate_1117();
		foreach ( oy_1117_how() as $slug => $text ) {
			ok( str_contains( get_post_field( 'post_content', $made[ $slug ][0] ), '<p>' . $text . '</p>' ), 'live update: ' . $slug );
		}
		ok( ! isset( oye_edits()['t'][ oye_text_key( OY_1117_HOW_OLD ) ] ), '… and her edit of the old shared text goes' );
	} finally {
		foreach ( $made as $m ) {
			null === $m[1] ? wp_delete_post( $m[0], true ) : wp_update_post( wp_slash( array( 'ID' => $m[0], 'post_content' => $m[1] ) ) );
		}
		false === $e0 ? delete_option( 'oye_edits' ) : update_option( 'oye_edits', $e0 );
		delete_option( 'oy_1117_rev' );
		delete_option( 'oy_edition' );
	}
	ok( ! str_contains( (string) file_get_contents( get_template_directory() . '/functions.php' ), '>@oliivia_yoga<' ), 'the Instagram name without „@” („ide nem kell @”)' );
	$js  = (string) file_get_contents( get_template_directory() . '/assets/site.js' );
	ok( str_contains( $js, "closest('details > summary')" ) && str_contains( $js, 'pinned.el.getBoundingClientRect().top - pinned.top' ), 'a tapped folding title stays where it was when the open one above closes' );
	$css = (string) file_get_contents( get_template_directory() . '/assets/site.css' );
	ok( str_contains( $css, '@media (pointer: coarse), (max-width: 759px) { .btn, .btn--sm { min-height: 44px; } .oys-waiver > summary { display: flex; align-items: center; min-height: 44px; } }' ), 'buttons and the agreement\'s title at least 44 px high on a touch screen (tablets too)' );
} );

$t = $GLOBALS['oys_t'];
echo "\n{$t['pass']} assertions passed, {$t['fail']} failed\n";
exit( $t['fail'] ? 1 : 0 );
