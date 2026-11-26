<?php
/**
 * Test mode (Áron, 2026-11-19: „minden egyes funkcióra egy teszt gombot … ami indítható Olivia által … ahol mindent le
 * tudunk tesztelni, minden folyamat működését, e-mailezés, ügyfélimporttól kezdve … kapcsolható legyen, és élesben
 * menjenek ki az üzenetek, induljon a live kit, induljon minden”).
 *
 * Studio → Test mode. Switched on, every process runs with one tap, through the same code a real guest goes through:
 * the emails really go out (to the test guests, who are the teacher's own mailbox with +test1, +test2 …), the pushes
 * reach her phone, the test class runs in the real live room (LiveKit). Only money does not move: the payment is
 * simulated (the order is marked paid, no card is charged); the pass, the booking and every email are the same as
 * after a real payment.
 *
 * Everything it makes is listed in the option (test guests also carry the `oys_test` user meta, their names start with
 * „TESZT”); the test classes are hidden from the public timetable (only the studio sees them), and „Delete the test
 * data” (or switching it off) removes all of it. Each run is logged with the emails and pushes it sent.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Testbench {

	const OPT  = 'oys_testbench';
	const LOGN = 40;

	/** Emails and pushes of the running scenario. */
	private static $mails  = array();
	private static $pushes = array();
	private static $steps  = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_oys_testbench', array( __CLASS__, 'handle' ) );
		add_filter( 'oys_hidden_session_ids', array( __CLASS__, 'hidden' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ---------- State ---------- */

	public static function state() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), array(
			'on'       => 0,
			'email'    => '',
			'users'    => array(),
			'sessions' => array(), // role => id (live, small, past)
			'orders'   => array(),
			'requests' => array(),
			'coupons'  => array(),
			'log'      => array(),
		) );
	}

	private static function save( array $s ) {
		update_option( self::OPT, $s, false );
	}

	public static function on() {
		return ! empty( self::state()['on'] );
	}

	/** The mailbox the test guests' emails arrive in (the studio's notification address by default). */
	public static function base_email() {
		$e = (string) self::state()['email'];
		return is_email( $e ) ? $e : (string) OYS_Settings::get( 'notify_email' );
	}

	/** name+test1@domain – the same mailbox, a different address for WordPress. */
	public static function address( $n ) {
		$base = self::base_email();
		if ( ! str_contains( $base, '@' ) ) {
			return '';
		}
		[ $local, $domain ] = explode( '@', $base, 2 );
		$local = preg_replace( '/\+.*$/', '', $local );
		return $local . '+teszt' . (int) $n . '@' . $domain;
	}

	/** Test classes are the studio's: on the public timetable (and in llms.txt, the schema…) they are not there. */
	public static function hidden( $ids ) {
		$own = array_values( array_map( 'intval', (array) self::state()['sessions'] ) );
		if ( ! $own || current_user_can( 'oys_manage' ) || is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $ids;
		}
		return array_merge( (array) $ids, $own );
	}

	/* ---------- Test guests and classes ---------- */

	/** Test guest n (1, 2 …): a real customer account with the +testN address. */
	public static function guest( $n, $welcome = false ) {
		$email = self::address( $n );
		$user  = get_user_by( 'email', $email );
		if ( $user ) {
			return $user->ID;
		}
		$id = OYS_Customers::find_or_create( $email, sprintf( 'TESZT Vendég %d', $n ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( $id, 'oys_test', 1 );
		update_user_meta( $id, 'oys_marketing', '1' ); // subscribed: the newsletter and the weekly email reach them too
		$s          = self::state();
		$s['users'] = array_values( array_unique( array_merge( $s['users'], array( (int) $id ) ) ) );
		self::save( $s );
		if ( $welcome ) {
			OYS_Emails::welcome( $id );
		}
		return (int) $id;
	}

	/** The next real group class: the test class copies its class, price and format, so the emails look the same. */
	private static function model() {
		$own = array_map( 'intval', (array) self::state()['sessions'] );
		foreach ( OYS_Schedule::query( array( 'kind' => array( 'group' ), 'from' => oys_now(), 'status' => 'scheduled', 'limit' => 20 ) ) as $s ) {
			if ( ! in_array( (int) $s->id, $own, true ) ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * A test class. live: starts in a few minutes, the live room is open at once; small: in three days, one seat
	 * (booking, waitlist, cancelling); past: ended four hours ago (the review link needs a class the guest came to).
	 */
	public static function session( $role ) {
		$s   = self::state();
		$cur = isset( $s['sessions'][ $role ] ) ? OYS_Schedule::get( (int) $s['sessions'][ $role ] ) : null;
		if ( $cur && 'scheduled' === $cur->status && ( 'past' === $role || oys_ts( $cur->ends_at ) > time() + 10 * MINUTE_IN_SECONDS ) ) {
			return $cur;
		}
		$m     = self::model();
		$open  = max( 2, (int) OYS_Settings::get( 'live_open_minutes' ) );
		$start = array(
			'live'  => time() + max( 1, $open - 2 ) * MINUTE_IN_SECONDS,
			'small' => time() + 3 * DAY_IN_SECONDS,
			'past'  => time() - 5 * HOUR_IN_SECONDS,
		)[ $role ] ?? time() + DAY_IN_SECONDS;
		$minutes = $m ? max( 30, (int) round( ( oys_ts( $m->ends_at ) - oys_ts( $m->starts_at ) ) / 60 ) ) : 60;
		$seats   = 'small' === $role ? 1 : 10;
		$online  = ! $m || oys_has_online( $m );
		$id      = OYS_Schedule::save( array(
			'kind'               => 'group',
			'class_slug'         => $m ? $m->class_slug : '',
			'title'              => 'TESZT – ' . ( $m ? oys_session_title( $m ) : __( 'Group class', 'olivia-studio' ) ),
			'starts_at'          => gmdate( 'Y-m-d H:i:s', $start ),
			'ends_at'            => gmdate( 'Y-m-d H:i:s', $start + $minutes * MINUTE_IN_SECONDS ),
			'capacity'           => $seats,
			'online_capacity'    => $online ? $seats : 0,
			'format'             => $online ? 'online' : 'studio',
			'location'           => $m ? (string) $m->location : '',
			'price_cents'        => $m ? (int) $m->price_cents : 0,
			'online_price_cents' => $m ? (int) $m->online_price_cents : 0,
			'credits_allowed'    => 1,
			'teacher_id'         => $m ? (int) $m->teacher_id : 0,
			'lang'               => $m ? (string) $m->lang : '',
			'note'               => __( 'Test class (Studio → Test mode): only the studio sees it.', 'olivia-studio' ),
			'status'             => 'scheduled',
		) );
		$s                      = self::state();
		$s['sessions'][ $role ] = $id;
		self::save( $s );
		if ( 'past' !== $role && class_exists( 'OYS_Live' ) && OYS_Live::uses_live( OYS_Schedule::get( $id ) ) ) {
			OYS_Live::ensure_row( OYS_Schedule::get( $id ) );
		}
		return OYS_Schedule::get( $id );
	}

	/** A pass for the guest, bought (simulated payment) if they have no class on it for this class. */
	private static function ensure_pass( $uid, $session ) {
		$mode = oys_mode_for( $session, 'online' );
		if ( OYS_Passes::available_for( $uid, $session, $mode ) > 0 ) {
			return true;
		}
		$order = self::buy( $uid, $session );
		return ! is_wp_error( $order ) && OYS_Passes::available_for( $uid, $session, $mode ) > 0;
	}

	/**
	 * The pass the purchase test buys: one that pays for the test class (an online pass for an online class), the
	 * cheapest of those.
	 */
	public static function product( $session = null ) {
		$session = $session ?: self::session( 'live' );
		$need    = OYS_Bookings::credit_kind( $session, oys_mode_for( $session, 'online' ) );
		$best    = null;
		foreach ( OYS_Products::all( true ) as $p ) {
			if ( ! in_array( $p['kind'], array( 'pack', 'online_pack', 'dropin', 'online_drop', 'intro' ), true ) || OYS_Products::credit_kind( $p ) !== $need ) {
				continue;
			}
			if ( ! $best || (int) $p['price_cents'] < (int) $best['price_cents'] ) {
				$best = $p;
			}
		}
		return $best;
	}

	/** A paid order the way the payment page leaves it, without a card: order → paid → pass, emails, push. */
	private static function buy( $uid, $session = null ) {
		$p = self::product( $session );
		if ( ! $p ) {
			return new WP_Error( 'oys_test', __( 'There is no active class pass to buy (Studio → Prices & passes).', 'olivia-studio' ) );
		}
		$order = OYS_Orders::create( array(
			'user_id'      => $uid,
			'type'         => 'pack',
			'product_id'   => $p['id'],
			'description'  => 'TESZT – ' . $p['name'],
			'amount_cents' => (int) $p['price_cents'],
			'meta'         => array( 'test' => 1 ),
		) );
		$s           = self::state();
		$s['orders'] = array_values( array_unique( array_merge( $s['orders'], array( $order ) ) ) );
		self::save( $s );
		OYS_Orders::mark_paid( $order );
		self::step( true, sprintf( __( 'Bought: %1$s (%2$s), payment simulated – no card was charged.', 'olivia-studio' ), $p['name'], oys_money( (int) $p['price_cents'] ) ) );
		return $order;
	}

	private static function booking_of( $uid, $session ) {
		$b = OYS_Bookings::active_for( $uid, $session->id );
		return $b && 'confirmed' === $b->status ? $b : null;
	}

	/** Books the guest with a class from their pass (as on the booking page), even when booking has closed. */
	private static function book( $uid, $session ) {
		$have = self::booking_of( $uid, $session );
		if ( $have ) {
			return $have;
		}
		if ( ! self::ensure_pass( $uid, $session ) ) {
			return new WP_Error( 'oys_test', __( 'No pass to book with.', 'olivia-studio' ) );
		}
		$r = OYS_Bookings::book_party( $uid, $session, array( 'method' => 'credit', 'mode' => oys_mode_for( $session, 'online' ), 'force' => true, 'notify' => true ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return self::booking_of( $uid, $session );
	}

	/* ---------- Scenarios ---------- */

	/** key => [ title, what it does, callback ] – in the order a guest meets them. */
	public static function scenarios() {
		$list = array(
			'account'    => array( __( 'Sign-up', 'olivia-studio' ), __( 'A new guest account (TESZT Vendég 1) and the welcome email, as after signing up on the site.', 'olivia-studio' ), array( __CLASS__, 'run_account' ) ),
			'password'   => array( __( 'Forgotten password', 'olivia-studio' ), __( 'The „new password” email of the test guest, with the link to the site\'s own password page.', 'olivia-studio' ), array( __CLASS__, 'run_password' ) ),
			'buy'        => array( __( 'Buying a pass', 'olivia-studio' ), __( 'The test guest buys a pass: the receipt email to them, the „new order” email and push to you. The payment is simulated, no card is charged.', 'olivia-studio' ), array( __CLASS__, 'run_buy' ) ),
			'book'       => array( __( 'Booking a class', 'olivia-studio' ), __( 'The test guest books the test class (it starts in a few minutes) with a class from the pass: the confirmation email with the calendar file, the push to you.', 'olivia-studio' ), array( __CLASS__, 'run_book' ) ),
			'remind'     => array( __( 'Reminders', 'olivia-studio' ), __( 'The reminder email and the „join the live class” email with the guest\'s own link to the live room.', 'olivia-studio' ), array( __CLASS__, 'run_remind' ) ),
			'live'       => array( __( 'Live class (LiveKit)', 'olivia-studio' ), __( 'Opens the live room of the test class: your link (the teacher) and the test guest\'s link. Open one on your phone, the other on a computer.', 'olivia-studio' ), array( __CLASS__, 'run_live' ) ),
			'waitlist'   => array( __( 'Full class, waitlist', 'olivia-studio' ), __( 'A one-seat test class in three days: guest 1 books it, guest 2 joins the waitlist (push to you).', 'olivia-studio' ), array( __CLASS__, 'run_waitlist' ) ),
			'cancel'     => array( __( 'Cancelling a booking', 'olivia-studio' ), __( 'Guest 1 cancels in time: the cancellation email, the class back on the pass, the push to you, and guest 2 gets the „a spot opened up” email.', 'olivia-studio' ), array( __CLASS__, 'run_cancel' ) ),
			'class_off'  => array( __( 'You cancel a class', 'olivia-studio' ), __( 'You call off the one-seat test class: everyone booked gets the „class cancelled” email, the class goes back on their pass.', 'olivia-studio' ), array( __CLASS__, 'run_class_off' ) ),
			'private'    => array( __( 'One-to-one session', 'olivia-studio' ), __( 'The test guest asks for a one-to-one session (the request reaches you), and you send an offer for tomorrow at 10:00 (the offer email with the payment link).', 'olivia-studio' ), array( __CLASS__, 'run_private' ) ),
			'gift'       => array( __( 'Gift card', 'olivia-studio' ), __( 'Guest 1 buys a gift card for guest 2: the gift email with the code, the receipt to the buyer, the „new order” email to you. Payment simulated.', 'olivia-studio' ), array( __CLASS__, 'run_gift' ) ),
			'review'     => array( __( 'Asking for a review', 'olivia-studio' ), __( 'The review request email after a class (the guest came to a test class four hours ago). Write a review with the link: it waits for your approval in Studio → Reviews.', 'olivia-studio' ), array( __CLASS__, 'run_review' ) ),
			'retention'  => array( __( '„We miss you” and the week', 'olivia-studio' ), __( 'The email to guests who haven\'t come for a while, and the weekly „this week\'s classes” email.', 'olivia-studio' ), array( __CLASS__, 'run_retention' ) ),
			'birthday'   => array( __( 'Birthday coupon', 'olivia-studio' ), __( 'The birthday email with a personal, one-time discount code.', 'olivia-studio' ), array( __CLASS__, 'run_birthday' ) ),
			'expiring'   => array( __( 'Pass running out', 'olivia-studio' ), __( 'The „your pass expires soon” email.', 'olivia-studio' ), array( __CLASS__, 'run_expiring' ) ),
			'newsletter' => array( __( 'Newsletter', 'olivia-studio' ), __( 'Your latest newsletter to the test guest\'s mailbox (with the unsubscribe link).', 'olivia-studio' ), array( __CLASS__, 'run_newsletter' ) ),
			'push'       => array( __( 'Phone notifications', 'olivia-studio' ), __( 'A test push and your morning summary to your phone (the Studio app, with notifications allowed).', 'olivia-studio' ), array( __CLASS__, 'run_push' ) ),
			'import'     => array( __( 'Customer import', 'olivia-studio' ), __( 'Two test customers imported the way a Motibro export is: name, email, phone, birthday. They are marked as imported.', 'olivia-studio' ), array( __CLASS__, 'run_import' ) ),
		);
		/** More scenarios (the theme adds the contact form): key => [ title, description, callable returning nothing ]. */
		return (array) apply_filters( 'oys_testbench_scenarios', $list );
	}

	/** One line of the result. */
	public static function step( $ok, $text ) {
		self::$steps[] = array( (bool) $ok, (string) $text );
	}

	private static function fail( $e ) {
		self::step( false, is_wp_error( $e ) ? $e->get_error_message() : (string) $e );
		return false;
	}

	public static function run_account() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		OYS_Emails::welcome( $uid );
		self::step( true, sprintf( __( 'Guest account: %s', 'olivia-studio' ), self::address( 1 ) ) );
	}

	public static function run_password() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$r = retrieve_password( get_userdata( $uid )->user_login );
		return is_wp_error( $r ) ? self::fail( $r ) : self::step( true, __( 'Password email requested.', 'olivia-studio' ) );
	}

	public static function run_buy() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$o = self::buy( $uid );
		if ( is_wp_error( $o ) ) {
			return self::fail( $o );
		}
		self::step( true, sprintf( __( 'Classes on the test guest\'s pass: %d', 'olivia-studio' ), OYS_Passes::balance( $uid, 'online' ) + OYS_Passes::balance( $uid, 'class' ) ) );
	}

	public static function run_book() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$s = self::session( 'live' );
		$b = self::book( $uid, $s );
		if ( is_wp_error( $b ) || ! $b ) {
			return self::fail( $b ?: __( 'The booking did not go through.', 'olivia-studio' ) );
		}
		self::step( true, sprintf( __( 'Booked: %1$s, %2$s', 'olivia-studio' ), oys_session_title( $s ), wp_date( 'M j, H:i', oys_ts( $s->starts_at ) ) ) );
	}

	public static function run_remind() {
		$uid = self::guest( 1 );
		$s   = self::session( 'live' );
		$b   = is_wp_error( $uid ) ? $uid : self::book( $uid, $s );
		if ( is_wp_error( $b ) || ! $b ) {
			return self::fail( $b ?: __( 'The booking did not go through.', 'olivia-studio' ) );
		}
		OYS_Emails::reminder( $b->id );
		if ( oys_has_online( $s ) ) {
			OYS_Emails::join_reminder( $b->id );
		}
		self::step( true, __( 'Reminders sent for the test class.', 'olivia-studio' ) );
	}

	public static function run_live() {
		if ( ! class_exists( 'OYS_Live' ) || ! OYS_Live::enabled() ) {
			return self::fail( __( 'The live room is not set up (Settings → Live online).', 'olivia-studio' ) );
		}
		$uid = self::guest( 1 );
		$s   = self::session( 'live' );
		$b   = is_wp_error( $uid ) ? $uid : self::book( $uid, $s );
		if ( is_wp_error( $b ) || ! $b ) {
			return self::fail( $b ?: __( 'The booking did not go through.', 'olivia-studio' ) );
		}
		OYS_Live::ensure_row( $s );
		$ok = OYS_Live::test_connection();
		self::step( ! is_wp_error( $ok ) && false !== $ok, is_wp_error( $ok ) ? $ok->get_error_message() : __( 'LiveKit answers: the room can start.', 'olivia-studio' ) );
		self::step( true, __( 'Your link (teacher):', 'olivia-studio' ) . ' ' . OYS_Live::url( $s->id ) );
		self::step( true, __( 'The test guest\'s link:', 'olivia-studio' ) . ' ' . OYS_Live::url( $s->id, $b->id ) );
		self::step( true, __( 'Every live class feature, step by step:', 'olivia-studio' ) . ' ' . admin_url( 'admin.php?page=oys-live-lab' ) );
	}

	public static function run_waitlist() {
		$u1 = self::guest( 1 );
		$u2 = self::guest( 2 );
		if ( is_wp_error( $u1 ) || is_wp_error( $u2 ) ) {
			return self::fail( is_wp_error( $u1 ) ? $u1 : $u2 );
		}
		$s = self::session( 'small' );
		$b = self::book( $u1, $s );
		if ( is_wp_error( $b ) || ! $b ) {
			return self::fail( $b ?: __( 'The booking did not go through.', 'olivia-studio' ) );
		}
		$s = OYS_Schedule::get( $s->id );
		self::step( OYS_Schedule::spots_left( $s, oys_mode_for( $s, 'online' ) ) < 1, __( 'The one-seat class is full.', 'olivia-studio' ) );
		OYS_Bookings::join_waitlist( $u2, $s->id );
		self::step( true, sprintf( __( 'On the waitlist: %s', 'olivia-studio' ), self::address( 2 ) ) );
	}

	public static function run_cancel() {
		$u1 = self::guest( 1 );
		$s  = self::session( 'small' );
		$b  = is_wp_error( $u1 ) ? $u1 : self::booking_of( $u1, $s );
		if ( ! $b ) {
			self::run_waitlist();
			$b = self::booking_of( $u1, $s );
		}
		if ( is_wp_error( $b ) || ! $b ) {
			return self::fail( $b ?: __( 'The booking did not go through.', 'olivia-studio' ) );
		}
		$r = OYS_Bookings::cancel( $b->id );
		return is_wp_error( $r ) ? self::fail( $r ) : self::step( true, sprintf( __( 'Cancelled (%s).', 'olivia-studio' ), (string) $r ) );
	}

	public static function run_class_off() {
		$u1 = self::guest( 1 );
		$s  = self::session( 'small' );
		$b  = is_wp_error( $u1 ) ? $u1 : self::book( $u1, $s );
		if ( is_wp_error( $b ) || ! $b ) {
			return self::fail( $b ?: __( 'The booking did not go through.', 'olivia-studio' ) );
		}
		$r = OYS_Schedule::cancel_session( $s->id, __( 'Test: the teacher calls off the class.', 'olivia-studio' ) );
		return is_wp_error( $r ) ? self::fail( $r ) : self::step( true, __( 'The one-seat test class is cancelled.', 'olivia-studio' ) );
	}

	public static function run_private() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$offered = OYS_Services::offered();
		$service = $offered ? reset( $offered ) : null;
		global $wpdb;
		$wpdb->insert( OYS_Install::table( 'private_requests' ), array(
			'user_id'       => $uid,
			'topic'         => $service ? $service['key'] : 'yoga',
			'duration_min'  => $service ? (int) $service['duration'] : 60,
			'people'        => 1,
			'location_type' => 'online',
			'address'       => '',
			'preferred'     => __( 'Test: weekday mornings', 'olivia-studio' ),
			'notes'         => __( 'This is a test request from Studio → Test mode.', 'olivia-studio' ),
			'status'        => 'new',
			'created_at'    => oys_now(),
		) );
		$rid           = (int) $wpdb->insert_id;
		$s             = self::state();
		$s['requests'] = array_values( array_unique( array_merge( $s['requests'], array( $rid ) ) ) );
		self::save( $s );
		$request = OYS_Privates::get( $rid );
		OYS_Emails::private_request_received( $request );
		do_action( 'oys_private_requested', $request );
		self::step( true, __( 'Request sent (it is in your conversations too).', 'olivia-studio' ) );
		$tomorrow = ( new DateTimeImmutable( 'tomorrow 10:00', wp_timezone() ) )->format( 'Y-m-d H:i' );
		$price    = $service && ! empty( $service['price_cents'] ) ? (int) $service['price_cents'] : 0;
		if ( ! $price ) {
			foreach ( OYS_Products::all( true ) as $p ) {
				if ( 'private_single' === $p['kind'] ) {
					$price = (int) $p['price_cents'];
					break;
				}
			}
		}
		$sid = OYS_Privates::offer( $rid, $tomorrow, (int) $request->duration_min, $price, '', '', __( 'Test offer from Studio → Test mode.', 'olivia-studio' ) );
		if ( is_wp_error( $sid ) ) {
			return self::fail( $sid );
		}
		$s             = self::state();
		$s['sessions'] = array_merge( $s['sessions'], array( 'private' . $rid => (int) $sid ) );
		self::save( $s );
		self::step( true, sprintf( __( 'Offer sent: tomorrow 10:00, %s.', 'olivia-studio' ), oys_money( $price ) ) );
	}

	public static function run_gift() {
		$u1 = self::guest( 1 );
		if ( is_wp_error( $u1 ) ) {
			return self::fail( $u1 );
		}
		$p = null;
		foreach ( OYS_Products::all( true ) as $x ) {
			if ( ! empty( $x['giftable'] ) ) {
				$p = $x;
				break;
			}
		}
		if ( ! $p ) {
			return self::fail( __( 'No gift card is on sale (Studio → Prices & passes: „Can be given as a gift”).', 'olivia-studio' ) );
		}
		$order = OYS_Orders::create( array(
			'user_id'      => $u1,
			'type'         => 'gift',
			'product_id'   => $p['id'],
			'description'  => 'TESZT – ' . sprintf( __( 'Gift card: %s', 'olivia-studio' ), $p['name'] ),
			'amount_cents' => (int) $p['price_cents'],
			'meta'         => array( 'recipient_name' => 'TESZT Vendég 2', 'recipient_email' => self::address( 2 ), 'message' => __( 'Happy practice! (test gift)', 'olivia-studio' ), 'test' => 1 ),
		) );
		$s           = self::state();
		$s['orders'] = array_values( array_unique( array_merge( $s['orders'], array( $order ) ) ) );
		self::save( $s );
		OYS_Orders::mark_paid( $order );
		self::step( true, sprintf( __( 'Gift card: %1$s for %2$s, payment simulated.', 'olivia-studio' ), $p['name'], self::address( 2 ) ) );
	}

	public static function run_review() {
		if ( ! class_exists( 'OYS_Reviews' ) ) {
			return self::fail( __( 'Reviews are not available.', 'olivia-studio' ) );
		}
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$s = self::session( 'past' );
		if ( ! OYS_Bookings::active_for( $uid, $s->id ) ) {
			OYS_Bookings::book_manual( $uid, $s, 'admin', false, true, oys_mode_for( $s, 'online' ) );
		}
		$b = OYS_Bookings::active_for( $uid, $s->id );
		if ( $b ) {
			global $wpdb;
			$wpdb->update( OYS_Install::table( 'bookings' ), array( 'status' => 'attended' ), array( 'id' => $b->id ) );
		}
		delete_user_meta( $uid, '_oys_review_asked' );
		OYS_Emails::review_request( $uid, OYS_Reviews::url( $uid ) );
		update_user_meta( $uid, '_oys_review_asked', time() );
		self::step( true, __( 'Review link:', 'olivia-studio' ) . ' ' . OYS_Reviews::url( $uid ) );
	}

	public static function run_retention() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		update_user_meta( $uid, 'oys_marketing', '1' );
		foreach ( array( 'we_miss_you', 'weekly_digest' ) as $k ) {
			if ( ! OYS_Email_Templates::enabled( $k ) ) {
				self::step( false, sprintf( __( '„%s” is switched off in Studio → Emails & reminders, so it does not go out on the site either.', 'olivia-studio' ), OYS_Email_Templates::get( $k )['subject'] ?? $k ) );
				continue;
			}
			if ( 'weekly_digest' === $k && '' === OYS_Emails::week_html() ) {
				self::step( false, __( 'There is no class next week, so the weekly email has nothing to show (on the site it does not go out either).', 'olivia-studio' ) );
				continue;
			}
			$sent = 'we_miss_you' === $k ? OYS_Emails::we_miss_you( $uid ) : OYS_Emails::weekly_digest( $uid );
			self::step( (bool) $sent, $sent ? sprintf( __( 'Sent: %s', 'olivia-studio' ), OYS_Email_Templates::get( $k )['subject'] ?? $k ) : __( 'The email did not go out.', 'olivia-studio' ) );
		}
	}

	public static function run_birthday() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$percent = max( 1, min( 100, (int) OYS_Settings::get( 'birthday_percent' ) ?: 10 ) );
		$id      = OYS_Coupons::create( array( 'kind' => 'percent', 'value' => $percent, 'applies' => 'all', 'max_uses' => 1, 'user_id' => $uid, 'expires' => oys_utc_plus( 14 * DAY_IN_SECONDS ), 'source' => 'birthday', 'prefix' => 'TESZT', 'note' => 'Test mode' ) );
		if ( is_wp_error( $id ) ) {
			return self::fail( $id );
		}
		$s            = self::state();
		$s['coupons'] = array_values( array_unique( array_merge( $s['coupons'], array( (int) $id ) ) ) );
		self::save( $s );
		OYS_Emails::birthday( $uid, OYS_Coupons::get( $id ) );
		self::step( true, sprintf( __( 'Coupon: %s', 'olivia-studio' ), OYS_Coupons::get( $id )->code ) );
	}

	public static function run_expiring() {
		$uid = self::guest( 1 );
		if ( is_wp_error( $uid ) ) {
			return self::fail( $uid );
		}
		$passes = OYS_Passes::for_user( $uid, true );
		if ( ! $passes ) {
			$o = self::buy( $uid );
			if ( is_wp_error( $o ) ) {
				return self::fail( $o );
			}
			$passes = OYS_Passes::for_user( $uid, true );
			if ( ! $passes ) {
				return self::fail( __( 'No pass to book with.', 'olivia-studio' ) );
			}
		}
		$pass = reset( $passes );
		OYS_Emails::pass_expiring( $pass->id );
		self::step( true, __( 'Sent for the test guest\'s pass.', 'olivia-studio' ) );
	}

	public static function run_newsletter() {
		$uid  = self::guest( 1 );
		$list = OYS_Newsletter::query( 1 );
		if ( ! $list ) {
			return self::fail( __( 'There is no newsletter yet (Studio → Newsletter): write one, then try again.', 'olivia-studio' ) );
		}
		$n  = reset( $list );
		$ok = OYS_Newsletter::send_test( $n->id, self::address( 1 ) );
		return $ok ? self::step( true, sprintf( __( 'Sent: %s', 'olivia-studio' ), (string) ( $n->subject ?? '' ) ) ) : self::fail( __( 'The newsletter did not go out.', 'olivia-studio' ) );
	}

	public static function run_push() {
		$me = get_current_user_id();
		$n  = OYS_Studio_App::notify( $me, __( 'Test mode', 'olivia-studio' ), __( 'Notifications work on this device. 🌿', 'olivia-studio' ), OYS_Studio_App::url(), 'test' );
		self::step( $n > 0, $n > 0 ? sprintf( _n( 'Sent to %d device.', 'Sent to %d devices.', $n, 'olivia-studio' ), $n ) : __( 'No device of yours allows notifications: open the Studio app on your phone and allow them (Hub → Notifications).', 'olivia-studio' ) );
		$text = OYS_Hub_Auto::summary( $me );
		if ( $text ) {
			OYS_Studio_App::notify( $me, sprintf( __( 'Good morning, %s!', 'olivia-studio' ), OYS_Hub::chat_name( $me ) ), $text, OYS_Studio_App::url( 'today' ), 'daily' );
			self::step( true, __( 'Morning summary sent.', 'olivia-studio' ) );
		}
	}

	public static function run_import() {
		$rows = array();
		foreach ( array( 3, 4 ) as $n ) {
			$rows[] = array(
				'Vezetéknév'      => 'TESZT',
				'Keresztnév'      => sprintf( 'Import %d', $n - 2 ),
				'Email'           => self::address( $n ),
				'Telefonszám'     => '+36 30 000 000' . $n,
				'Születési idő'   => '1990-0' . $n . '-1' . $n,
				'Megjegyzés'      => 'TESZT (Stúdió → Tesztüzem)',
			);
		}
		$r = OYS_Import::import_any( $rows, 'teszt' );
		if ( is_wp_error( $r ) ) {
			return self::fail( $r );
		}
		$s = self::state();
		foreach ( array( 3, 4 ) as $n ) {
			$u = get_user_by( 'email', self::address( $n ) );
			if ( $u ) {
				update_user_meta( $u->ID, 'oys_test', 1 );
				$s['users'][] = $u->ID;
			}
		}
		$s['users'] = array_values( array_unique( array_map( 'intval', $s['users'] ) ) );
		self::save( $s );
		$c = (array) ( $r['counts'] ?? $r );
		self::step( ! empty( $c['created'] ) || ! empty( $c['updated'] ) || ! empty( $c['unchanged'] ), sprintf( __( 'Import: %1$d new, %2$d updated, %3$d unchanged, %4$d skipped.', 'olivia-studio' ), (int) ( $c['created'] ?? 0 ), (int) ( $c['updated'] ?? 0 ), (int) ( $c['unchanged'] ?? 0 ), (int) ( $c['skipped'] ?? 0 ) ) );
	}

	/* ---------- Running ---------- */

	/** Runs one scenario and logs what it did: its steps, the emails (to, subject) and the pushes. */
	public static function run( $key ) {
		$all = self::scenarios();
		if ( ! self::on() || ! isset( $all[ $key ] ) ) {
			return null;
		}
		self::$mails  = array();
		self::$pushes = array();
		self::$steps  = array();
		$mail = function ( $a ) {
			self::$mails[] = array( implode( ', ', (array) ( $a['to'] ?? array() ) ), (string) ( $a['subject'] ?? '' ) );
			return $a;
		};
		$fail = function ( $e ) {
			self::$steps[] = array( false, sprintf( __( 'An email did not go out: %s', 'olivia-studio' ), $e->get_error_message() ) );
		};
		$push = function ( $uid, $title, $body, $url, $tag, $n ) {
			self::$pushes[] = array( (int) $uid, (string) $title, (int) $n );
		};
		add_filter( 'wp_mail', $mail, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $fail );
		add_action( 'oys_push_notified', $push, 10, 6 );
		try {
			call_user_func( $all[ $key ][2] );
		} catch ( Throwable $e ) {
			self::step( false, $e->getMessage() );
		}
		remove_filter( 'wp_mail', $mail, PHP_INT_MAX );
		remove_action( 'wp_mail_failed', $fail );
		remove_action( 'oys_push_notified', $push, 10 );
		$entry = array(
			'key'    => $key,
			'title'  => $all[ $key ][0],
			'at'     => time(),
			'by'     => get_current_user_id(),
			'steps'  => self::$steps,
			'mails'  => self::$mails,
			'pushes' => self::$pushes,
			'ok'     => ! in_array( false, array_column( self::$steps, 0 ), true ),
		);
		$s        = self::state();
		$s['log'] = array_slice( array_merge( array( $entry ), (array) $s['log'] ), 0, self::LOGN );
		self::save( $s );
		return $entry;
	}

	/** Removes everything the test mode made: guests and their bookings, passes, orders, requests, coupons, reviews, classes. */
	public static function cleanup() {
		global $wpdb;
		$s     = self::state();
		$users = array_map( 'intval', (array) $s['users'] );
		foreach ( get_users( array( 'meta_key' => 'oys_test', 'meta_value' => 1, 'fields' => 'ID' ) ) as $u ) {
			$users[] = (int) $u;
		}
		$users    = array_values( array_unique( array_filter( $users ) ) );
		$sessions = array_values( array_unique( array_map( 'intval', (array) $s['sessions'] ) ) );
		$orders   = array_map( 'intval', (array) $s['orders'] );
		$t        = fn( $n ) => OYS_Install::table( $n );
		$in       = fn( array $ids ) => implode( ',', array_map( 'intval', $ids ) ?: array( 0 ) );
		foreach ( array( 'private_requests', 'orders', 'passes', 'memberships', 'waitlist', 'coupons' ) as $table ) {
			if ( $users ) {
				$wpdb->query( "DELETE FROM {$t( $table )} WHERE user_id IN ({$in( $users )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints
			}
		}
		if ( $orders ) {
			$wpdb->query( "DELETE FROM {$t( 'gift_cards' )} WHERE order_id IN ({$in( $orders )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$t( 'orders' )} WHERE id IN ({$in( $orders )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $users ) {
			$wpdb->query( "DELETE FROM {$t( 'bookings' )} WHERE user_id IN ({$in( $users )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$t( 'gift_cards' )} WHERE purchaser_id IN ({$in( $users )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $s['coupons'] ) {
			$wpdb->query( "DELETE FROM {$t( 'coupons' )} WHERE id IN ({$in( (array) $s['coupons'] )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $sessions ) {
			$wpdb->query( "DELETE FROM {$t( 'bookings' )} WHERE session_id IN ({$in( $sessions )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$t( 'waitlist' )} WHERE session_id IN ({$in( $sessions )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( array( 'live', 'live_chat' ) as $table ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t( $table ) ) ) ) {
					$wpdb->query( "DELETE FROM {$t( $table )} WHERE session_id IN ({$in( $sessions )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			$wpdb->query( "DELETE FROM {$t( 'sessions' )} WHERE id IN ({$in( $sessions )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $users ) {
			$reviews = get_posts( array( 'post_type' => 'oys_review', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'author__in' => $users ) );
			foreach ( $reviews as $p ) {
				wp_delete_post( $p, true );
			}
			// the Hub's conversations of the test guests (their requests and messages)
			$threads = $wpdb->get_col( "SELECT id FROM {$t( 'threads' )} WHERE user_id IN ({$in( $users )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $threads ) {
				$wpdb->query( "DELETE FROM {$t( 'thread_msgs' )} WHERE thread_id IN ({$in( $threads )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$t( 'threads' )} WHERE id IN ({$in( $threads )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( "DELETE FROM {$t( 'live_chat' )} WHERE user_id IN ({$in( $users )})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			do_action( 'oys_testbench_cleanup', $users );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $users as $u ) {
				if ( get_user_meta( $u, 'oys_test', true ) && ! user_can( $u, 'oys_manage' ) ) {
					wp_delete_user( $u );
				}
			}
		}
		$s['users']    = array();
		$s['sessions'] = array();
		$s['orders']   = array();
		$s['requests'] = array();
		$s['coupons']  = array();
		self::save( $s );
		return count( $users );
	}

	/* ---------- The page ---------- */

	public static function menu() {
		add_submenu_page( 'oys', __( 'Test mode', 'olivia-studio' ), __( 'Test mode', 'olivia-studio' ) . ( self::on() ? ' <span class="awaiting-mod">' . esc_html__( 'on', 'olivia-studio' ) . '</span>' : '' ), 'oys_manage', 'oys-test', array( __CLASS__, 'page' ) );
	}

	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::on() || ! current_user_can( 'oys_manage' ) || ( $screen && str_contains( (string) $screen->id, 'oys-test' ) ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Test mode is on: test guests and test classes exist (only you see them).', 'olivia-studio' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=oys-test' ) ) . '">' . esc_html__( 'Test mode', 'olivia-studio' ) . '</a></p></div>';
	}

	public static function handle() {
		if ( ! current_user_can( 'oys_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'olivia-studio' ) );
		}
		check_admin_referer( 'oys_testbench' );
		$do  = sanitize_key( $_POST['do'] ?? '' );
		$s   = self::state();
		$msg = '';
		if ( 'switch' === $do ) {
			$on = ! empty( $_POST['on'] );
			if ( ! $on ) {
				$n   = self::cleanup();
				$msg = sprintf( __( 'Test mode is off. Test data deleted (%d test guests).', 'olivia-studio' ), $n );
			}
			$s          = self::state();
			$s['on']    = $on ? 1 : 0;
			$email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$s['email'] = is_email( $email ) ? $email : '';
			self::save( $s );
			$msg = $msg ?: ( $on ? __( 'Test mode is on.', 'olivia-studio' ) : '' );
		} elseif ( 'cleanup' === $do ) {
			$msg = sprintf( __( 'Test data deleted (%d test guests).', 'olivia-studio' ), self::cleanup() );
		} elseif ( 'all' === $do ) {
			foreach ( array_keys( self::scenarios() ) as $k ) {
				if ( ! in_array( $k, array( 'class_off', 'push' ), true ) ) {
					self::run( $k );
				}
			}
			$msg = __( 'Everything ran: see the results below and your mailbox.', 'olivia-studio' );
		} elseif ( isset( self::scenarios()[ $do ] ) ) {
			$e   = self::run( $do );
			$msg = $e ? ( $e['ok'] ? __( 'Done: see the result below.', 'olivia-studio' ) : __( 'Something did not work: see below.', 'olivia-studio' ) ) : '';
		}
		if ( $msg ) {
			oys_flash( $msg );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=oys-test' . ( isset( self::scenarios()[ $do ] ) ? '#r-' . $do : '' ) ) );
		exit;
	}

	private static function button( $do, $label, $class = 'button' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. wp_nonce_field( 'oys_testbench', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="oys_testbench"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function page() {
		$s   = self::state();
		$on  = ! empty( $s['on'] );
		$log = array();
		foreach ( (array) $s['log'] as $e ) {
			$log[ $e['key'] ] = $log[ $e['key'] ] ?? $e; // the latest run of each
		}
		echo '<div class="wrap oys-wrap oys-test"><h1>' . esc_html__( 'Test mode', 'olivia-studio' ) . '</h1>';
		if ( function_exists( 'oys_render_flash' ) ) {
			echo oys_render_flash(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p class="oys-test__lead">' . esc_html__( 'Every process of the site with one tap, for real: the emails go out, the pushes arrive, the live room starts. The test guests are your own mailbox with +teszt1, +teszt2 … in the address, so everything arrives to you. Money does not move: payments are simulated.', 'olivia-studio' ) . '</p>';
		// the switch
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oys-test__switch">' . wp_nonce_field( 'oys_testbench', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="oys_testbench"><input type="hidden" name="do" value="switch">'
			. '<label><input type="checkbox" name="on" value="1"' . checked( $on, true, false ) . '> <strong>' . esc_html__( 'Test mode on', 'olivia-studio' ) . '</strong></label> '
			. '<label>' . esc_html__( 'Test emails go to:', 'olivia-studio' ) . ' <input type="email" name="email" value="' . esc_attr( self::base_email() ) . '" class="regular-text"></label> '
			. '<button class="button button-primary">' . esc_html__( 'Save', 'olivia-studio' ) . '</button>'
			. '<p class="description">' . esc_html( sprintf( __( 'Test guests: %1$s, %2$s … Switching it off deletes all test data.', 'olivia-studio' ), self::address( 1 ), self::address( 2 ) ) ) . '</p></form>';
		if ( ! $on ) {
			echo '</div>';
			return;
		}
		echo '<p class="oys-test__all">' . self::button( 'all', __( 'Run everything in order', 'olivia-studio' ), 'button button-primary button-hero' ) . ' ' . self::button( 'cleanup', __( 'Delete the test data', 'olivia-studio' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="oys-test__grid">';
		foreach ( self::scenarios() as $key => $sc ) {
			$e = $log[ $key ] ?? null;
			echo '<section class="oys-test__card' . ( $e ? ( $e['ok'] ? ' is-ok' : ' is-bad' ) : '' ) . '" id="r-' . esc_attr( $key ) . '"><h2>' . ( $e ? ( $e['ok'] ? '✅ ' : '⚠️ ' ) : '' ) . esc_html( $sc[0] ) . '</h2><p>' . esc_html( $sc[1] ) . '</p>';
			echo self::button( $key, $e ? __( 'Run again', 'olivia-studio' ) : __( 'Run test', 'olivia-studio' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $e ) {
				echo '<div class="oys-test__res"><p class="oys-test__when">' . esc_html( wp_date( 'M j, H:i', $e['at'] ) ) . '</p><ul>';
				foreach ( $e['steps'] as $st ) {
					echo '<li>' . ( $st[0] ? '✓ ' : '✗ ' ) . make_clickable( esc_html( $st[1] ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				foreach ( $e['mails'] as $m ) {
					echo '<li>✉️ ' . esc_html( $m[1] ) . ' <span class="oys-test__to">→ ' . esc_html( $m[0] ) . '</span></li>';
				}
				foreach ( $e['pushes'] as $p ) {
					echo '<li>📱 ' . esc_html( $p[1] ) . ' <span class="oys-test__to">(' . esc_html( sprintf( _n( '%d device', '%d devices', $p[2], 'olivia-studio' ), $p[2] ) ) . ')</span></li>';
				}
				if ( ! $e['mails'] && ! in_array( $key, array( 'live', 'push', 'import', 'waitlist' ), true ) ) {
					echo '<li>' . esc_html__( 'No email went out: is this email switched off in Studio → Emails & reminders?', 'olivia-studio' ) . '</li>';
				}
				echo '</ul></div>';
			}
			echo '</section>';
		}
		echo '</div></div>';
		echo '<style>.oys-test__lead{max-width:70ch;font-size:14px}.oys-test__switch{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;margin:14px 0}.oys-test__switch label{margin-right:14px;display:inline-block;margin-bottom:6px}.oys-test__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}.oys-test__card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:14px 16px}.oys-test__card.is-ok{border-color:#68b984}.oys-test__card.is-bad{border-color:#d63638}.oys-test__card h2{font-size:16px;margin:0 0 6px}.oys-test__card p{margin:0 0 10px;color:#50575e}.oys-test__res{margin-top:10px;border-top:1px solid #f0f0f1;padding-top:8px}.oys-test__res ul{margin:0;padding:0;list-style:none}.oys-test__res li{margin:4px 0;word-break:break-word}.oys-test__to,.oys-test__when{color:#787c82;font-size:12px}.oys-test__all .button{margin:0 8px 8px 0}@media(max-width:600px){.oys-test__grid{grid-template-columns:1fr}.oys-test__switch input.regular-text{width:100%}}</style>';
	}
}

OYS_Testbench::init();
