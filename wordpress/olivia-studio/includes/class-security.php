<?php
/**
 * Brute-force protection for the customer log-in and sign-up forms (and wp-login.php):
 * after too many failures from one IP (or for one email) further attempts are refused
 * for a while. Counters live in transients, so this needs no extra tables.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Security {

	const MAX_LOGIN_FAILS  = 6;   // per email, within the window
	const MAX_IP_FAILS     = 20;  // per IP: mobile networks put many people behind one address (CGNAT)
	const MAX_SIGNUPS      = 10;  // new accounts per IP, within the window (an Instagram post brings many at once)
	const WINDOW           = 900; // 15 minutes

	public static function init() {
		// wp-login.php and our own form both end in wp_signon → these hooks cover both.
		// Priority 99: after WordPress has checked the password, so the lock can't be bypassed.
		add_filter( 'authenticate', array( __CLASS__, 'block_if_locked' ), 99, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failure' ) );
		add_action( 'wp_login', array( __CLASS__, 'clear_on_success' ), 10, 1 );
		// The lock message says "or reset your password" – so a reset really unlocks (Áron, 2026-11-17: locked out of
		// the admin, the reset link alone did not let him in for 15 minutes).
		add_action( 'after_password_reset', array( __CLASS__, 'clear_on_reset' ), 10, 1 );
	}

	/** A new password: the account's and this connection's failures are forgotten (login name and email both count). */
	public static function clear_on_reset( $user ) {
		if ( $user instanceof WP_User ) {
			delete_transient( self::key( 'user', $user->user_login ) );
			delete_transient( self::key( 'user', $user->user_email ) );
		}
		delete_transient( self::key( 'ip', self::ip() ) );
	}

	public static function ip() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip );
	}

	private static function key( $kind, $value ) {
		return 'oys_rl_' . $kind . '_' . md5( strtolower( (string) $value ) );
	}

	private static function count( $key ) {
		return (int) get_transient( $key );
	}

	private static function bump( $key ) {
		set_transient( $key, self::count( $key ) + 1, self::WINDOW );
	}

	public static function login_locked( $login = '' ) {
		return self::count( self::key( 'ip', self::ip() ) ) >= self::MAX_IP_FAILS
			|| ( $login && self::count( self::key( 'user', $login ) ) >= self::MAX_LOGIN_FAILS );
	}

	public static function block_if_locked( $user, $username ) {
		if ( $username && self::login_locked( $username ) ) {
			return new WP_Error( 'oys_locked', __( 'Too many failed attempts. Please wait 15 minutes, or reset your password.', 'olivia-studio' ) );
		}
		return $user;
	}

	public static function record_failure( $username ) {
		self::bump( self::key( 'ip', self::ip() ) );
		if ( $username ) {
			self::bump( self::key( 'user', $username ) );
		}
	}

	public static function clear_on_success( $username ) {
		delete_transient( self::key( 'user', $username ) );
	}

	/** Sign-ups per IP. Returns true when this IP may create another account. */
	public static function signup_allowed() {
		return self::count( self::key( 'signup', self::ip() ) ) < self::MAX_SIGNUPS;
	}

	public static function record_signup() {
		self::bump( self::key( 'signup', self::ip() ) );
	}
}

OYS_Security::init();
