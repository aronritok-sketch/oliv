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
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( ! str_starts_with( $url, OYS_Stripe::api_base() ) ) {
		return $pre;
	}
	$path = parse_url( $url, PHP_URL_PATH );
	parse_str( is_string( $args['body'] ?? null ) ? $args['body'] : '', $body );
	$GLOBALS['stripe_calls'][] = array( $args['method'], $path, $body );
	$json = array( 'id' => 'x_' . count( $GLOBALS['stripe_calls'] ) );
	if ( '/v1/customers' === $path ) {
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
register_shutdown_function( function () use ( $original_settings ) {
	update_option( OYS_Settings::OPTION, $original_settings );
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
	$start = time() + ( $args['in_hours'] ?? 72 ) * HOUR_IN_SECONDS;
	return OYS_Schedule::get( OYS_Schedule::save( array(
		'kind'            => $args['kind'] ?? 'group',
		'class_slug'      => 'hatha-flow',
		'starts_at'       => gmdate( 'Y-m-d H:i:s', $start ),
		'ends_at'         => gmdate( 'Y-m-d H:i:s', $start + 3600 ),
		'capacity'        => $args['capacity'] ?? 10,
		'format'          => $args['format'] ?? 'studio',
		'price_cents'     => $args['price'] ?? 2500,
		'credits_allowed' => $args['credits_allowed'] ?? 1,
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
