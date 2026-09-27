<?php
/**
 * JSON API for the mobile app (app/ in the repo), under /wp-json/oys/v1/app/.
 *
 * Authentication: POST /app/login with email + password returns a token; every other call
 * sends it as "Authorization: Bearer <token>" (or "X-OYS-Token: <token>" where a host strips the
 * Authorization header). Tokens are "<user id>.<secret>"; only a hash of the secret is kept, in
 * user meta, one per device, and they can be revoked (logout, or all of them from the admin).
 *
 * The app books with a pass, a membership or free spots directly; card payments return a Stripe
 * Checkout URL that the app opens in the browser (the same checkout as the website).
 */

defined( 'ABSPATH' ) || exit;

class OYS_App_API {

	const NS    = 'oys/v1';
	const META  = 'oys_app_tokens';
	const LIMIT = 10; // Devices per person; the oldest token is dropped.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_allowed_cors_headers', fn( $h ) => array_merge( $h, array( 'X-OYS-Token' ) ) );
	}

	public static function routes() {
		$open = '__return_true';
		$auth = array( __CLASS__, 'authenticate' );
		$r    = array(
			array( '/app/login', 'POST', 'login', $open ),
			array( '/app/logout', 'POST', 'logout', $auth ),
			array( '/app/me', 'GET', 'me', $auth ),
			array( '/app/waiver', 'POST', 'accept_waiver', $auth ),
			array( '/app/schedule', 'GET', 'schedule', $auth ),
			array( '/app/sessions/(?P<id>\d+)', 'GET', 'session', $auth ),
			array( '/app/sessions/(?P<id>\d+)/book', 'POST', 'book', $auth ),
			array( '/app/sessions/(?P<id>\d+)/waitlist', 'POST', 'waitlist', $auth ),
			array( '/app/bookings', 'GET', 'bookings', $auth ),
			array( '/app/bookings/(?P<id>\d+)/cancel', 'POST', 'cancel', $auth ),
			array( '/app/push-token', 'POST', 'push_token', $auth ),
			array( '/app/newsletter', 'POST', 'newsletter', $auth ),
		);
		foreach ( $r as $route ) {
			register_rest_route( self::NS, $route[0], array(
				'methods'             => $route[1],
				'callback'            => array( __CLASS__, $route[2] ),
				'permission_callback' => $route[3],
			) );
		}
	}

	/* ---------- Tokens ---------- */

	private static function hash( $secret ) {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/** New token for a user (one per device). */
	public static function issue_token( $user_id, $device = '' ) {
		$secret = wp_generate_password( 40, false );
		$tokens = get_user_meta( $user_id, self::META, true );
		$tokens = is_array( $tokens ) ? $tokens : array();
		$tokens[ self::hash( $secret ) ] = array( 'device' => mb_substr( sanitize_text_field( $device ), 0, 80 ), 'created' => time(), 'used' => time() );
		uasort( $tokens, fn( $a, $b ) => $b['used'] <=> $a['used'] );
		update_user_meta( $user_id, self::META, array_slice( $tokens, 0, self::LIMIT, true ) );
		return $user_id . '.' . $secret;
	}

	private static function token_from( WP_REST_Request $req ) {
		$h = (string) $req->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(\S+)$/i', $h, $m ) ) {
			return $m[1];
		}
		return (string) $req->get_header( 'x_oys_token' );
	}

	/** @return int user id, or 0 */
	public static function user_for_token( $token ) {
		if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]{20,})$/', (string) $token, $m ) ) {
			return 0;
		}
		$tokens = get_user_meta( (int) $m[1], self::META, true );
		$hash   = self::hash( $m[2] );
		if ( ! is_array( $tokens ) || ! isset( $tokens[ $hash ] ) ) {
			return 0;
		}
		if ( time() - (int) $tokens[ $hash ]['used'] > HOUR_IN_SECONDS ) {
			$tokens[ $hash ]['used'] = time();
			update_user_meta( (int) $m[1], self::META, $tokens );
		}
		return (int) $m[1];
	}

	public static function revoke( $token ) {
		if ( ! preg_match( '/^(\d+)\.(\S+)$/', (string) $token, $m ) ) {
			return;
		}
		$tokens = get_user_meta( (int) $m[1], self::META, true );
		if ( is_array( $tokens ) ) {
			unset( $tokens[ self::hash( $m[2] ) ] );
			update_user_meta( (int) $m[1], self::META, $tokens );
		}
	}

	public static function authenticate( WP_REST_Request $req ) {
		$user_id = self::user_for_token( self::token_from( $req ) );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'oys_auth', __( 'Please log in again.', 'olivia-studio' ), array( 'status' => 401 ) );
		}
		wp_set_current_user( $user_id );
		return true;
	}

	private static function error( WP_Error $e, $status = 400 ) {
		$data = $e->get_error_data();
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => is_array( $data ) && isset( $data['status'] ) ? $data['status'] : $status ) );
	}

	/* ---------- Account ---------- */

	public static function login( WP_REST_Request $req ) {
		$p     = $req->get_json_params() ?: $req->get_params();
		$email = sanitize_text_field( $p['email'] ?? '' );
		$user  = wp_authenticate( $email, (string) ( $p['password'] ?? '' ) );
		if ( is_wp_error( $user ) ) {
			$locked = 'oys_locked' === $user->get_error_code();
			return new WP_Error( $locked ? 'oys_locked' : 'oys_login', $locked ? $user->get_error_message() : __( 'The email or password is not right.', 'olivia-studio' ), array( 'status' => $locked ? 429 : 401 ) );
		}
		return rest_ensure_response( array(
			'token' => self::issue_token( $user->ID, $p['device'] ?? '' ),
			'me'    => self::me_data( $user->ID ),
		) );
	}

	public static function logout( WP_REST_Request $req ) {
		self::revoke( self::token_from( $req ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function me( WP_REST_Request $req ) {
		return rest_ensure_response( self::me_data( get_current_user_id() ) );
	}

	public static function accept_waiver( WP_REST_Request $req ) {
		OYS_Customers::record_waiver( get_current_user_id() );
		return rest_ensure_response( self::me_data( get_current_user_id() ) );
	}

	/** Profile, passes, membership and links the app shows. */
	public static function me_data( $user_id ) {
		$u      = get_userdata( $user_id );
		$passes = array();
		foreach ( OYS_Passes::for_user( $user_id, true ) as $p ) {
			$passes[] = array(
				'id'           => (int) $p->id,
				'name'         => $p->name,
				'kind'         => $p->kind,
				'credits_left' => (int) $p->credits_left,
				'credits_total'=> (int) $p->credits_total,
				'expires'      => $p->expires_at ? self::iso( $p->expires_at ) : null,
			);
		}
		$m = OYS_Memberships::current_for( $user_id );
		return array(
			'user'       => array(
				'id'         => (int) $user_id,
				'first_name' => $u->first_name,
				'last_name'  => $u->last_name,
				'name'       => $u->display_name,
				'email'      => $u->user_email,
				'phone'      => (string) get_user_meta( $user_id, 'oys_phone', true ),
				'newsletter' => '1' === (string) get_user_meta( $user_id, 'oys_marketing', true ),
			),
			'balances'   => array(
				'class'   => OYS_Passes::balance( $user_id, 'class' ),
				'online'  => OYS_Passes::balance( $user_id, 'online' ),
				'private' => OYS_Passes::balance( $user_id, 'private' ),
			),
			'passes'     => $passes,
			'membership' => $m ? array(
				'name'               => $m->name,
				'status'             => $m->status,
				'classes_per_period' => (int) $m->classes_per_period,
				'remaining'          => (int) $m->classes_per_period ? OYS_Memberships::remaining( $m ) : null,
				'period_end'         => $m->current_period_end ? self::iso( $m->current_period_end ) : null,
				'cancel_at_period_end' => (bool) $m->cancel_at_period_end,
			) : null,
			'waiver'     => array(
				'accepted' => OYS_Customers::has_waiver( $user_id ),
				'text'     => OYS_Settings::get( 'waiver_text' ),
			),
			'studio'     => array(
				'name'          => OYS_Settings::get( 'email_from_name' ),
				'cancel_policy' => OYS_Settings::get( 'cancel_policy' ),
				'currency'      => OYS_Settings::get( 'currency' ),
				'max_guests'    => (int) OYS_Settings::get( 'max_guests' ),
				'online_per_credit' => OYS_Passes::online_per_credit(),
			),
			'links'      => array(
				'account'    => oys_account_url(),
				'passes'     => oys_account_url( 'passes' ),
				'membership' => oys_account_url( 'membership' ),
				'private'    => oys_account_url( 'private' ),
				'pricing'    => home_url( '/schedule-pricing/' ),
				'gifts'      => oys_page_url( 'gifts' ),
				'website'    => home_url( '/' ),
				'password'   => wp_lostpassword_url(),
				'signup'     => oys_account_url(),
				'community'  => oys_fb_group_url(),
			),
			'raffle'     => OYS_Rewards::status( $user_id ),
			'coupons'    => array_map( fn( $c ) => array( 'code' => $c->code, 'label' => OYS_Coupons::label( $c ), 'expires' => $c->expires_at ? self::iso( $c->expires_at ) : null, 'birthday' => 'birthday' === $c->source ), OYS_Coupons::for_user( $user_id ) ),
		);
	}

	/* ---------- Classes ---------- */

	private static function iso( $utc ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', oys_ts( $utc ) );
	}

	/** A session for the app, with this customer's booking and waitlist place. */
	public static function session_data( $s, $user_id ) {
		$mine   = OYS_Bookings::active_for( $user_id, $s->id );
		$guests = $mine ? OYS_Bookings::guests_of( $mine->id, array( 'confirmed' ) ) : array();
		$online = OYS_Schedule::spots_left( $s, 'online' );
		$start  = oys_ts( $s->starts_at );
		return array(
			'id'              => (int) $s->id,
			'kind'            => $s->kind,
			'title'           => oys_session_title( $s ),
			'class_slug'      => $s->class_slug,
			'description'     => (string) $s->description,
			'note'            => (string) $s->note,
			'starts_at'       => self::iso( $s->starts_at ),
			'ends_at'         => self::iso( $s->ends_at ),
			'local'           => array(
				'date'  => wp_date( 'Y-m-d', $start ),
				'day'   => wp_date( 'D', $start ),
				'label' => wp_date( 'l, F j', $start ),
				'start' => wp_date( get_option( 'time_format' ), $start ),
				'end'   => wp_date( get_option( 'time_format' ), oys_ts( $s->ends_at ) ),
			),
			'duration'        => (int) round( ( oys_ts( $s->ends_at ) - $start ) / 60 ),
			'format'          => $s->format ?: 'studio',
			'location'        => oys_is_online( $s ) ? '' : (string) $s->location,
			'capacity'        => (int) $s->capacity,
			'spots_left'      => OYS_Schedule::spots_left( $s ),
			'online_spots_left' => oys_is_hybrid( $s ) ? ( PHP_INT_MAX === $online ? null : $online ) : null,
			'price_cents'     => (int) $s->price_cents,
			'online_price_cents' => oys_is_hybrid( $s ) ? (int) $s->online_price_cents : null,
			'credits_allowed' => (bool) $s->credits_allowed,
			'status'          => $s->status,
			'closed_reason'   => OYS_Schedule::closed_reason( $s ),
			'my_booking'      => $mine ? array(
				'id'         => (int) $mine->id,
				'mode'       => oys_mode_for( $s, $mine->mode ),
				'paid_with'  => OYS_Bookings::paid_with_labels()[ $mine->paid_with ] ?? $mine->paid_with,
				'guests'     => array_values( wp_list_pluck( $guests, 'guest_name' ) ),
				'can_cancel' => 'confirmed' === $mine->status && $start > time(),
				'in_window'  => OYS_Bookings::in_cancel_window( $mine, $s ),
				'join_url'   => self::join_url( $mine, $s ),
				'due_cents'  => array_sum( array_map( fn( $r ) => 'door' === $r->paid_with && '' === $r->collected_with ? (int) $r->due_cents : 0, array_merge( array( $mine ), $guests ) ) ),
			) : null,
			'pricing'         => oys_is_donation( $s ) ? 'donation' : 'fixed',
			'minimum'         => OYS_Locations::notice( $s ),
			'teacher'         => OYS_Teachers::public_data( OYS_Teachers::for_session( $s ) ),
			'waitlist_position' => $mine ? 0 : OYS_Bookings::waitlist_position( $user_id, $s->id ),
			'web_url'         => oys_book_url( $s->id ),
		);
	}

	/** The link to join online, from 60 minutes before the class until it ends (so it isn't passed around early). */
	private static function join_url( $booking, $s ) {
		if ( 'online' !== oys_mode_for( $s, $booking->mode ) || oys_ts( $s->ends_at ) < time() ) {
			return null;
		}
		if ( oys_ts( $s->starts_at ) - time() > HOUR_IN_SECONDS ) {
			return '';
		}
		return OYS_Bookings::join_link( $booking, $s ) ?: '';
	}

	public static function schedule( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$days    = min( 60, max( 1, (int) ( $req->get_param( 'days' ) ?: OYS_Settings::get( 'booking_window_days' ) ) ) );
		$from    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'from' ) ) ? oys_local_to_utc( $req->get_param( 'from' ) . 'T00:00' ) : oys_now();
		$from    = max( $from, oys_now() );
		$rows    = OYS_Schedule::query( array(
			'from'   => $from,
			'to'     => gmdate( 'Y-m-d H:i:s', oys_ts( $from ) + $days * DAY_IN_SECONDS ),
			'status' => 'scheduled',
		) );
		$out = array();
		foreach ( $rows as $s ) {
			if ( 'private' === $s->kind && ! OYS_Privates::may_book( $user_id, $s ) ) {
				continue;
			}
			$out[] = self::session_data( $s, $user_id );
		}
		return rest_ensure_response( array( 'sessions' => $out ) );
	}

	/** One class with the ways this customer can pay for it (?mode=online for hybrid classes). */
	public static function session( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$s       = OYS_Schedule::get( (int) $req['id'] );
		if ( ! $s || ! OYS_Privates::may_book( $user_id, $s ) ) {
			return new WP_Error( 'oys_missing', __( 'This class could not be found.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$mode    = oys_mode_for( $s, sanitize_key( $req->get_param( 'mode' ) ?: 'studio' ) );
		$price   = OYS_Schedule::price_for( $s, $mode );
		if ( oys_is_donation( $s ) ) {
			$price = max( $price, (int) OYS_Settings::get( 'donation_min_cents' ) ); // Suggested amount.
		}
		$kind    = OYS_Bookings::credit_kind( $s, $mode );
		$credits = $s->credits_allowed ? OYS_Passes::available_for( $user_id, $s, $mode ) : 0;
		$member  = OYS_Memberships::current_for( $user_id );
		$options = array();
		if ( $member && true === OYS_Memberships::covers( $member, $s, $mode ) ) {
			$options[] = array( 'method' => 'membership', 'label' => __( 'Use my membership', 'olivia-studio' ), 'detail' => (int) $member->classes_per_period ? sprintf( __( '%d left this period', 'olivia-studio' ), OYS_Memberships::remaining( $member ) ) : __( 'Unlimited', 'olivia-studio' ), 'price_cents' => 0 );
		}
		if ( $credits > 0 ) {
			$options[] = array( 'method' => 'credit', 'label' => __( 'Use my pass', 'olivia-studio' ), 'detail' => 'online' === $kind ? sprintf( _n( '%d online class', '%d online classes', $credits, 'olivia-studio' ), $credits ) : sprintf( _n( '%d class left', '%d classes left', $credits, 'olivia-studio' ), $credits ), 'price_cents' => 0, 'available' => $credits );
		}
		if ( $price > 0 && OYS_Settings::payments_ready() ) {
			$options[] = array( 'method' => 'card', 'label' => oys_is_donation( $s ) ? __( 'Give by card', 'olivia-studio' ) : ( 'online' === $mode ? __( 'Pay by card (online ticket)', 'olivia-studio' ) : __( 'Pay by card', 'olivia-studio' ) ), 'detail' => __( 'Secure Stripe checkout opens in your browser', 'olivia-studio' ), 'price_cents' => $price );
		} elseif ( ! $price ) {
			$options[] = array( 'method' => 'free', 'label' => __( 'Reserve my spot', 'olivia-studio' ), 'detail' => __( 'Free', 'olivia-studio' ), 'price_cents' => 0 );
		}
		if ( $price > 0 && true === OYS_Bookings::pay_later_allowed( $user_id, $s, $mode ) ) {
			$options[] = array( 'method' => 'door', 'label' => oys_is_donation( $s ) ? __( 'Give at the studio', 'olivia-studio' ) : __( 'Pay at the studio', 'olivia-studio' ), 'detail' => (string) OYS_Settings::get( 'pay_later_note' ), 'price_cents' => $price );
		}
		return rest_ensure_response( array(
			'donation'   => oys_is_donation( $s ) ? array( 'min_cents' => (int) OYS_Settings::get( 'donation_min_cents' ), 'amounts' => oys_donation_amounts(), 'suggested_cents' => $price ) : null,
			'session'    => self::session_data( $s, $user_id ),
			'mode'       => $mode,
			'spots_left' => OYS_Schedule::spots_left( $s, $mode ),
			'price_cents'=> $price,
			'options'    => $options,
			'max_guests' => 'private' === $s->kind ? 0 : (int) OYS_Settings::get( 'max_guests' ),
			'guest_email_required' => 'online' === $mode && OYS_Zoom::personal_links(),
			'waiver'     => OYS_Customers::has_waiver( $user_id ) ? null : OYS_Settings::get( 'waiver_text' ),
			'policy'     => OYS_Settings::get( 'cancel_policy' ),
		) );
	}

	/**
	 * Book: { mode, method: membership|credit|free|card|door, guests: [{name,email}], accept_waiver,
	 * amount_cents (donation classes: per person) }.
	 * Returns { status: 'booked', session } or { status: 'checkout', url, order_id }.
	 */
	public static function book( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$s       = OYS_Schedule::get( (int) $req['id'] );
		if ( ! $s || ! OYS_Privates::may_book( $user_id, $s ) ) {
			return new WP_Error( 'oys_missing', __( 'This class could not be found.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$p = $req->get_json_params() ?: $req->get_params();
		if ( ! OYS_Customers::has_waiver( $user_id ) ) {
			if ( empty( $p['accept_waiver'] ) ) {
				return new WP_Error( 'oys_waiver', __( 'Please accept the participation agreement first.', 'olivia-studio' ), array( 'status' => 409, 'waiver' => OYS_Settings::get( 'waiver_text' ) ) );
			}
			OYS_Customers::record_waiver( $user_id );
		}
		if ( 'paid' === OYS_Bookings::abandon_unfinished( $user_id, $s->id ) ) {
			return rest_ensure_response( array( 'status' => 'booked', 'session' => self::session_data( OYS_Schedule::get( $s->id ), $user_id ) ) );
		}
		$mode   = oys_mode_for( $s, sanitize_key( $p['mode'] ?? 'studio' ) );
		$method = sanitize_key( $p['method'] ?? '' );
		$guests = 'private' === $s->kind ? array() : OYS_Bookings::clean_guests( array_map( fn( $g ) => (string) ( $g['name'] ?? '' ), array_filter( (array) ( $p['guests'] ?? array() ), 'is_array' ) ), array_map( fn( $g ) => (string) ( $g['email'] ?? '' ), array_filter( (array) ( $p['guests'] ?? array() ), 'is_array' ) ) );
		$n      = count( $guests );
		if ( 'online' === $mode && $n && OYS_Zoom::personal_links() && array_filter( $guests, fn( $g ) => ! $g['email'] ) ) {
			return new WP_Error( 'oys_guest_email', __( 'Add an email for each online guest, so they get their own link to join.', 'olivia-studio' ), array( 'status' => 400 ) );
		}
		$price = OYS_Schedule::price_for( $s, $mode );
		if ( oys_is_donation( $s ) && in_array( $method, array( 'card', 'door' ), true ) ) {
			// Donation classes: what each person gives, at least the minimum.
			$min   = (int) OYS_Settings::get( 'donation_min_cents' );
			$price = min( 100000, (int) ( $p['amount_cents'] ?? max( OYS_Schedule::price_for( $s, $mode ), $min ) ) );
			if ( $price < max( 1, $min ) ) {
				return new WP_Error( 'oys_amount', sprintf( __( 'The minimum is %s per person.', 'olivia-studio' ), oys_money( $min ) ), array( 'status' => 400 ) );
			}
		}

		if ( 'door' === $method ) {
			$res = OYS_Bookings::book_party( $user_id, $s, array( 'method' => 'door', 'guests' => $guests, 'mode' => $mode, 'amount' => $price ) );
			if ( is_wp_error( $res ) ) {
				return self::error( $res, 409 );
			}
			return rest_ensure_response( array( 'status' => 'booked', 'session' => self::session_data( OYS_Schedule::get( $s->id ), $user_id ) ) );
		}

		if ( in_array( $method, array( 'credit', 'membership', 'free' ), true ) ) {
			if ( 'free' === $method && $price ) {
				return new WP_Error( 'oys_method', __( 'This class is not free.', 'olivia-studio' ), array( 'status' => 400 ) );
			}
			$res = OYS_Bookings::book_party( $user_id, $s, array( 'method' => $method, 'guests' => $guests, 'guest_method' => 'membership' === $method ? 'credit' : $method, 'mode' => $mode ) );
			if ( is_wp_error( $res ) ) {
				return self::error( $res, 409 );
			}
			return rest_ensure_response( array( 'status' => 'booked', 'session' => self::session_data( OYS_Schedule::get( $s->id ), $user_id ) ) );
		}

		if ( 'card' === $method ) {
			if ( $price < 1 || ! OYS_Settings::payments_ready() ) {
				return new WP_Error( 'oys_method', __( 'Card payment is not available for this class.', 'olivia-studio' ), array( 'status' => 400 ) );
			}
			$title = oys_session_title( $s ) . ( oys_is_hybrid( $s ) && 'online' === $mode ? ' ' . __( '(live online)', 'olivia-studio' ) : '' ) . ( oys_is_donation( $s ) ? ' ' . __( '(donation)', 'olivia-studio' ) : '' );
			$when  = oys_date( $s->starts_at, 'l, F j · g:i a' );
			$lines = array( array( $title, $when, $price, 1 ) );
			if ( $n ) {
				$lines[] = array( sprintf( __( 'Guest ticket: %s', 'olivia-studio' ), $title ), implode( ', ', wp_list_pluck( $guests, 'name' ) ) . ' · ' . $when, $price, $n );
			}
			$meta             = $n ? array( 'guests' => wp_list_pluck( $guests, 'name' ) ) : array();
			$meta['app']      = 1;
			$order_id         = OYS_Orders::create( array(
				'user_id'      => $user_id,
				'session_id'   => $s->id,
				'type'         => 'private' === $s->kind ? 'private' : 'dropin',
				'description'  => $title . ', ' . oys_date( $s->starts_at, 'M j, g:i a' ) . ( $n ? ' ' . sprintf( _n( '(+%d guest)', '(+%d guests)', $n, 'olivia-studio' ), $n ) : '' ),
				'amount_cents' => $price * ( 1 + $n ),
				'meta'         => $meta,
			) );
			$first = OYS_Bookings::hold( $user_id, $s, $order_id, $guests, true, 0, $mode );
			if ( is_wp_error( $first ) ) {
				OYS_Orders::update( $order_id, array( 'status' => 'failed' ) );
				return self::error( $first, 409 );
			}
			OYS_Orders::update( $order_id, array( 'booking_id' => $first ) );
			$url = OYS_Stripe::start_checkout( $order_id, $lines[0][0], $lines[0][1], $lines );
			if ( is_wp_error( $url ) ) {
				OYS_Orders::mark_unpaid( $order_id, 'failed' );
				return self::error( $url, 502 );
			}
			return rest_ensure_response( array( 'status' => 'checkout', 'url' => $url, 'order_id' => $order_id ) );
		}
		return new WP_Error( 'oys_method', __( 'Choose how to pay.', 'olivia-studio' ), array( 'status' => 400 ) );
	}

	public static function waitlist( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$s       = OYS_Schedule::get( (int) $req['id'] );
		if ( ! $s ) {
			return new WP_Error( 'oys_missing', __( 'This class could not be found.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$p = $req->get_json_params() ?: $req->get_params();
		if ( 'leave' === ( $p['do'] ?? '' ) ) {
			OYS_Bookings::leave_waitlist( $user_id, $s->id );
		} else {
			OYS_Bookings::join_waitlist( $user_id, $s->id );
		}
		return rest_ensure_response( array( 'session' => self::session_data( $s, $user_id ) ) );
	}

	/* ---------- My bookings ---------- */

	public static function bookings( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$when    = 'past' === $req->get_param( 'when' ) ? 'past' : 'upcoming';
		$out     = array();
		$rows    = OYS_Bookings::for_user( $user_id, $when, 'past' === $when ? array( 'confirmed', 'attended', 'no_show', 'late_cancelled' ) : array( 'confirmed' ) );
		foreach ( array_slice( $rows, 0, 50 ) as $b ) {
			$s     = OYS_Schedule::get( $b->session_id );
			$item  = self::session_data( $s, $user_id );
			$item['booking'] = array(
				'id'         => (int) $b->id,
				'status'     => $b->status,
				'mode'       => oys_mode_for( $s, $b->mode ),
				'paid_with'  => OYS_Bookings::paid_with_labels()[ $b->paid_with ] ?? $b->paid_with,
				'guests'     => array_map( fn( $g ) => array( 'id' => (int) $g->id, 'name' => $g->guest_name ), $b->guests ),
				'can_cancel' => 'confirmed' === $b->status && oys_ts( $s->starts_at ) > time(),
				'in_window'  => OYS_Bookings::in_cancel_window( $b, $s ),
				'join_url'   => self::join_url( $b, $s ),
			);
			$out[] = $item;
		}
		return rest_ensure_response( array( 'bookings' => $out ) );
	}

	public static function cancel( WP_REST_Request $req ) {
		$b = OYS_Bookings::get( (int) $req['id'] );
		if ( ! $b || (int) $b->user_id !== get_current_user_id() ) {
			return new WP_Error( 'oys_missing', __( 'Booking not found.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$res = OYS_Bookings::cancel( $b->id );
		if ( is_wp_error( $res ) ) {
			return self::error( $res, 409 );
		}
		$messages = array(
			'returned'   => __( 'Cancelled. The class is back on your pass.', 'olivia-studio' ),
			'credit'     => __( 'Cancelled. You have a class credit to use.', 'olivia-studio' ),
			'membership' => __( 'Cancelled. It won\'t count towards your membership.', 'olivia-studio' ),
			'late'       => __( 'Cancelled. It was inside the cancellation window, so the class counts as used.', 'olivia-studio' ),
			'none'       => __( 'Cancelled.', 'olivia-studio' ),
		);
		return rest_ensure_response( array( 'outcome' => $res, 'message' => $messages[ $res ] ?? $messages['none'] ) );
	}

	/** Expo push token for this device (notifications come in a later version). */
	/** Newsletter on/off from the app: { subscribe: bool }. */
	public static function newsletter( WP_REST_Request $req ) {
		$p = $req->get_json_params() ?: $req->get_params();
		update_user_meta( get_current_user_id(), 'oys_marketing', empty( $p['subscribe'] ) ? '' : '1' );
		return rest_ensure_response( self::me_data( get_current_user_id() ) );
	}

	public static function push_token( WP_REST_Request $req ) {
		$p     = $req->get_json_params() ?: $req->get_params();
		$token = sanitize_text_field( $p['token'] ?? '' );
		if ( ! preg_match( '/^(Expo|Exponent)PushToken\[[^\]]+\]$/', $token ) ) {
			return new WP_Error( 'oys_push', 'Invalid push token', array( 'status' => 400 ) );
		}
		$all           = get_user_meta( get_current_user_id(), 'oys_push_tokens', true );
		$all           = is_array( $all ) ? $all : array();
		$all[ $token ] = array( 'platform' => sanitize_key( $p['platform'] ?? '' ), 'updated' => time() );
		update_user_meta( get_current_user_id(), 'oys_push_tokens', array_slice( $all, -10, null, true ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
