<?php
/**
 * The first login without thinking (Áron, 26 November: „Hülye biztosat csináld meg. Hülye biztosra.”).
 *
 * A guest brought over from Motibro gets a personal button in their email („Jelszót állítok be”). One tap opens a page that
 * says which email address is theirs and asks only for a password; saving it logs them in and takes them to what is still
 * missing (the participation agreement, yes or no to the newsletter), then to the timetable. No address to type, no second
 * email, no separate login.
 *
 * - The link holds no email address and lands on a clean address (the key moves into a short-lived cookie), so nothing
 *   personal or secret ends up in analytics or the browser history.
 * - It works for 30 days and once. An old or used link still helps: it offers a new link with one tap, or the login with
 *   the address already filled in.
 * - WordPress's own „forgotten password” link comes here too (customers only; the studio keeps WordPress's screen), so there
 *   is one way to set a password and it always ends logged in.
 * - Signing up again with an address that is already a guest's (Motibro) sends the link instead of an error.
 *
 * @package olivia-studio
 */

defined( 'ABSPATH' ) || exit;

class OYS_Welcome {

	const META   = 'oys_welcome';
	const COOKIE = 'oys_welcome';
	const DAYS   = 30;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'arrive' ), 15 );
		add_action( 'login_init', array( __CLASS__, 'from_reset_link' ), 1 );
		add_action( 'admin_post_nopriv_oys_setpass', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_oys_setpass', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_oys_welcome_again', array( __CLASS__, 'handle_again' ) );
		add_action( 'admin_post_oys_welcome_again', array( __CLASS__, 'handle_again' ) );
	}

	/* ---------- the link ---------- */

	/** A new personal key for the user: "<id>.<key>", good for $days days and one password. */
	public static function issue( $user_id, $days = self::DAYS ) {
		$user_id = (int) $user_id;
		$key     = wp_generate_password( 32, false );
		$list    = array_filter( (array) get_user_meta( $user_id, self::META, true ), fn( $t ) => is_array( $t ) && (int) ( $t['until'] ?? 0 ) > time() - 90 * DAY_IN_SECONDS );
		$list[]  = array( 'h' => self::hash( $key ), 'until' => time() + (int) $days * DAY_IN_SECONDS, 'used' => 0 );
		update_user_meta( $user_id, self::META, array_slice( array_values( $list ), -5 ) );
		return $user_id . '.' . $key;
	}

	/** The button's address. $preview: the studio's own test copy, which only says it is a preview (no key is made). */
	public static function link( $user_id, $preview = false ) {
		return add_query_arg( 'oys_welcome', $preview ? 'preview' : self::issue( $user_id ), home_url( '/' ) );
	}

	private static function hash( $key ) {
		return hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) );
	}

	/** [ 'ok' | 'used' | 'expired' | 'bad', WP_User|null ] – a wrong key says nothing about whose it would be. */
	public static function check( $value ) {
		if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]{20,64})$/', (string) $value, $m ) ) {
			return array( 'bad', null );
		}
		$u = get_userdata( (int) $m[1] );
		if ( ! $u ) {
			return array( 'bad', null );
		}
		$h = self::hash( $m[2] );
		foreach ( (array) get_user_meta( $u->ID, self::META, true ) as $t ) {
			if ( is_array( $t ) && isset( $t['h'] ) && hash_equals( (string) $t['h'], $h ) ) {
				if ( ! empty( $t['used'] ) ) {
					return array( 'used', $u );
				}
				return array( (int) $t['until'] > time() ? 'ok' : 'expired', $u );
			}
		}
		return array( 'bad', null );
	}

	private static function mark_used( $value ) {
		if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]{20,64})$/', (string) $value, $m ) ) {
			return;
		}
		$h    = self::hash( $m[2] );
		$list = (array) get_user_meta( (int) $m[1], self::META, true );
		foreach ( $list as $i => $t ) {
			if ( is_array( $t ) && isset( $t['h'] ) && hash_equals( (string) $t['h'], $h ) ) {
				$list[ $i ]['used'] = time();
			}
		}
		update_user_meta( (int) $m[1], self::META, $list );
	}

	private static function cookie_value() {
		return isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
	}

	private static function set_cookie( $value ) {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $value, $value ? time() + 2 * HOUR_IN_SECONDS : time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
		if ( $value ) {
			$_COOKIE[ self::COOKIE ] = $value;
		} else {
			unset( $_COOKIE[ self::COOKIE ] );
		}
	}

	/** The page where the password is chosen (the account page, its „set your password” view). */
	public static function page_url() {
		return add_query_arg( 'oys_view', 'setpass', oys_account_url() ) . '#oys-setpass-box';
	}

	/** Where a guest goes once logged in: what is missing first, otherwise the timetable. */
	public static function next_url( $user_id ) {
		$missing = OYS_Customers::missing( $user_id );
		if ( ! $missing ) {
			return oys_page_url( 'book' );
		}
		// straight to the two things at the top of the profile, no scrolling to find them
		return oys_account_url( 'profile' ) . ( isset( $missing['waiver'] ) || isset( $missing['oys_marketing'] ) ? '#oys-p-todo' : '' );
	}

	/* ---------- arriving ---------- */

	/** The button in the email: the key goes into a cookie, the guest onto the clean address of the page. */
	public static function arrive() {
		if ( ! isset( $_GET['oys_welcome'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		$value = sanitize_text_field( wp_unslash( $_GET['oys_welcome'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'preview' === $value ) {
			oys_flash( __( 'This is only a preview. In the guest\'s email this button opens the page where they choose a password and are logged in at once.', 'olivia-studio' ) );
			oys_redirect( oys_account_url() );
		}
		[ $state, $u ] = self::check( $value );
		if ( is_user_logged_in() ) {
			if ( $u && get_current_user_id() === $u->ID ) {
				oys_redirect( self::next_url( $u->ID ) );
			}
			oys_flash( __( 'You are logged in with another account. Log out, then tap the button in the email again.', 'olivia-studio' ), 'error' );
			oys_redirect( oys_account_url() );
		}
		self::set_cookie( 'bad' === $state ? '' : $value );
		if ( 'bad' === $state ) {
			oys_flash( __( 'This link doesn\'t work. Log in below, or tap „Forgot your password?” and we\'ll send you a new one.', 'olivia-studio' ), 'error' );
			oys_redirect( add_query_arg( 'oys_view', 'login', oys_account_url() ) );
		}
		oys_redirect( self::page_url() );
	}

	/** WordPress's „forgotten password” link of a customer: the same page (the studio keeps WordPress's own screen). */
	public static function from_reset_link() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $action, array( 'rp', 'resetpass' ), true ) || empty( $_GET['key'] ) || empty( $_GET['login'] ) || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$u = check_password_reset_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ), sanitize_text_field( wp_unslash( $_GET['login'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $u instanceof WP_User || ! OYS_Customers::is_customer_only( $u ) ) {
			return; // a link that has run out: WordPress's own „ask for a new one”
		}
		nocache_headers();
		self::set_cookie( self::issue( $u->ID, 1 ) );
		oys_redirect( self::page_url() );
	}

	/* ---------- the page ---------- */

	/** The account page's „set your password” view (logged out). */
	public static function page() {
		[ $state, $u ] = self::check( self::cookie_value() );
		if ( 'ok' === $state && ! OYS_Customers::is_customer_only( $u ) ) {
			$state = 'bad';
		}
		$back = '<p class="oys-small"><a class="text-link" href="' . esc_url( add_query_arg( 'oys_view', 'login', oys_account_url() ) ) . '">' . esc_html__( 'Log in with your password instead', 'olivia-studio' ) . '</a></p>';
		if ( 'ok' === $state ) {
			$name = $u->first_name ?: $u->display_name;
			return '<div class="oys-card oys-auth oys-auth--page oys-setpass" id="oys-setpass-box">'
				. '<h2>' . esc_html__( 'Choose your password', 'olivia-studio' ) . '</h2>'
				. '<p class="lead">' . esc_html( sprintf( __( 'Hi %s! Choose a password and you\'re in. Nothing else to do here.', 'olivia-studio' ), $name ) ) . '</p>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oys-form" id="oys-setpass">'
				. '<input type="hidden" name="action" value="oys_setpass">' . wp_nonce_field( 'oys_setpass', '_wpnonce', true, false )
				. '<label class="field"><span>' . esc_html__( 'Your email address – you log in with this', 'olivia-studio' ) . '</span><input type="email" name="username" value="' . esc_attr( $u->user_email ) . '" readonly autocomplete="username"></label>'
				. '<label class="field"><span>' . esc_html__( 'Your new password (at least 8 characters)', 'olivia-studio' ) . '</span><input type="password" name="password" id="oys-setpass-password" required minlength="8" autocomplete="new-password" autocapitalize="off" spellcheck="false"></label>'
				. '<label class="oys-check"><input type="checkbox" id="oys-setpass-show" checked> <span>' . esc_html__( 'Show the password while I type', 'olivia-studio' ) . '</span></label>'
				. '<button class="btn btn--primary" type="submit">' . esc_html__( 'Save and log in', 'olivia-studio' ) . '</button></form>'
				// shown while typing (no typo to find out about later); hidden again on saving, so the browser offers to keep it
				. '<script>(function(){var p=document.getElementById("oys-setpass-password"),s=document.getElementById("oys-setpass-show"),f=document.getElementById("oys-setpass");if(!p||!s||!f){return;}function t(){p.type=s.checked?"text":"password";}s.addEventListener("change",t);t();f.addEventListener("submit",function(){p.type="password";});p.focus();})();</script>'
				. '</div>';
		}
		if ( 'used' === $state || 'expired' === $state ) {
			$text = 'used' === $state
				? __( 'You have already set your password with this link. Log in with your email address and that password:', 'olivia-studio' )
				: __( 'This link has run out (it works for 30 days). Tap the button and we\'ll send a new one to your email address at once:', 'olivia-studio' );
			$again = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oys-form oys-setpass__again">'
				. '<input type="hidden" name="action" value="oys_welcome_again">' . wp_nonce_field( 'oys_welcome_again', '_wpnonce', true, false )
				. '<button class="btn ' . ( 'expired' === $state ? 'btn--primary' : 'btn--ghost' ) . '" type="submit">' . esc_html( 'expired' === $state ? __( 'Send me a new link', 'olivia-studio' ) : __( 'I don\'t remember it – send me a new link', 'olivia-studio' ) ) . '</button></form>';
			// the address filled in on the login form (on the page only, never in the address bar)
			$login = 'used' === $state ? self::login_forms( $u->user_email ) : '';
			return '<div class="oys-card oys-auth oys-auth--page oys-setpass" id="oys-setpass-box"><h2>' . esc_html( 'used' === $state ? __( 'Log in', 'olivia-studio' ) : __( 'This link has run out', 'olivia-studio' ) ) . '</h2>'
				. '<p class="lead">' . esc_html( $text ) . ' <b>' . esc_html( $u->user_email ) . '</b></p>' . ( 'used' === $state ? $login . $again : $again . $back ) . '</div>';
		}
		return '<div class="oys-card oys-auth oys-auth--page oys-setpass" id="oys-setpass-box"><p class="lead">' . esc_html__( 'This link doesn\'t work any more. Log in below, or tap „Forgot your password?” and we\'ll send you a new one.', 'olivia-studio' ) . '</p>' . self::login_forms() . '</div>';
	}

	/** The account page's login and sign-up, on the login tab (with the address filled in). */
	private static function login_forms( $email = '' ) {
		$was = array( $_GET['oys_view'] ?? null, $_GET['oys_email'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification
		$_GET['oys_view']  = 'login';
		$_GET['oys_email'] = $email;
		$html              = OYS_Frontend::auth_forms( oys_account_url() );
		foreach ( array( 'oys_view', 'oys_email' ) as $i => $k ) {
			if ( null === $was[ $i ] ) {
				unset( $_GET[ $k ] );
			} else {
				$_GET[ $k ] = $was[ $i ];
			}
		}
		return $html;
	}

	/* ---------- saving ---------- */

	/** „Save and log in”: the password, logged in at once, then what is missing (or the timetable). */
	public static function handle() {
		check_admin_referer( 'oys_setpass' );
		$value = self::cookie_value();
		[ $state, $u ] = self::check( $value );
		if ( 'ok' !== $state || ! OYS_Customers::is_customer_only( $u ) ) {
			oys_redirect( self::page_url() ); // the page says what happened to the link
		}
		$pass = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strlen( $pass ) < 8 ) {
			oys_flash( __( 'Your password needs at least 8 characters.', 'olivia-studio' ), 'error' );
			oys_redirect( self::page_url() );
		}
		reset_password( $u, $pass );
		self::mark_used( $value );
		self::set_cookie( '' );
		wp_set_current_user( $u->ID );
		wp_set_auth_cookie( $u->ID, true, is_ssl() );
		update_user_meta( $u->ID, 'oys_activated_at', oys_now() );
		oys_flash( OYS_Customers::missing( $u->ID )
			? __( 'Done, you\'re logged in! One last step: fill in what\'s missing below and tap „Save”. Then you can book.', 'olivia-studio' )
			: __( 'Done, you\'re logged in! Pick a class and book.', 'olivia-studio' ) );
		oys_redirect( self::next_url( $u->ID ) );
	}

	/** „Send me a new link”: to the address of the link's owner (at most once in 10 minutes). */
	public static function handle_again() {
		check_admin_referer( 'oys_welcome_again' );
		[ $state, $u ] = self::check( self::cookie_value() );
		if ( ! $u || ! in_array( $state, array( 'used', 'expired' ), true ) ) {
			oys_redirect( self::page_url() );
		}
		self::send_new( $u );
		oys_redirect( add_query_arg( 'oys_view', 'login', oys_account_url() ) );
	}

	/** A fresh link by email (the „Új jelszó” email, whose button comes to this page), with what to look for. */
	public static function send_new( WP_User $u, $signup = false ) {
		$wait = 'oys_welcome_sent_' . $u->ID;
		if ( ! get_transient( $wait ) ) {
			set_transient( $wait, 1, 10 * MINUTE_IN_SECONDS );
			retrieve_password( $u->user_login );
		}
		oys_flash( ( $signup ? sprintf( __( 'You already have an account with %s.', 'olivia-studio' ), $u->user_email ) . ' ' : '' )
			. sprintf( __( 'We\'ve sent a link to %s. Open the email „New password” (look in the spam folder too) and tap its button: you choose a password and you\'re in.', 'olivia-studio' ), $u->user_email ) );
	}
}

OYS_Welcome::init();
