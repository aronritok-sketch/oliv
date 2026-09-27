<?php
/**
 * Customer accounts: sign up, log in, profile, liability waiver.
 * Customers are WordPress users with the `oys_customer` role; they never see wp-admin.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Customers {

	const PROFILE_FIELDS = array( 'oys_phone', 'oys_emergency_name', 'oys_emergency_phone', 'oys_health_notes', 'oys_area', 'oys_marketing' );

	public static function init() {
		add_action( 'admin_post_nopriv_oys_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_oys_register', array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_nopriv_oys_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_oys_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_oys_profile', array( __CLASS__, 'handle_profile' ) );
		add_action( 'admin_post_oys_waiver', array( __CLASS__, 'handle_waiver' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	public static function is_customer_only( $user = null ) {
		$user = $user ?: wp_get_current_user();
		return $user && $user->exists() && ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'oys_manage' );
	}

	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || str_contains( $_SERVER['SCRIPT_NAME'] ?? '', 'admin-post.php' ) ) {
			return;
		}
		if ( self::is_customer_only() ) {
			oys_redirect( oys_account_url() );
		}
	}

	public static function admin_bar( $show ) {
		return self::is_customer_only() ? false : $show;
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_customer_only( $user ) ) {
			return oys_account_url();
		}
		return $to;
	}

	private static function back( $fallback = '' ) {
		$to = wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ?? '' ), $fallback ?: oys_account_url() );
		return $to;
	}

	public static function has_waiver( $user_id ) {
		return get_user_meta( $user_id, 'oys_waiver_version', true ) === OYS_Settings::get( 'waiver_version' );
	}

	public static function record_waiver( $user_id ) {
		update_user_meta( $user_id, 'oys_waiver_version', OYS_Settings::get( 'waiver_version' ) );
		update_user_meta( $user_id, 'oys_waiver_at', oys_now() );
		update_user_meta( $user_id, 'oys_waiver_ip', sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	public static function handle_register() {
		check_admin_referer( 'oys_register' );
		$back  = self::back();
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$pass  = (string) wp_unslash( $_POST['password'] ?? '' );

		$errors = array();
		if ( ! $first ) {
			$errors[] = __( 'Enter your first name.', 'olivia-studio' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Enter an email address like name@example.com.', 'olivia-studio' );
		} elseif ( email_exists( $email ) ) {
			$errors[] = sprintf( __( 'There is already an account for %s. Log in instead, or reset your password.', 'olivia-studio' ), esc_html( $email ) );
		}
		if ( strlen( $pass ) < 8 ) {
			$errors[] = __( 'Choose a password with at least 8 characters.', 'olivia-studio' );
		}
		if ( empty( $_POST['waiver'] ) ) {
			$errors[] = __( 'Please read and accept the participation agreement.', 'olivia-studio' );
		}
		if ( ! empty( $_POST['website'] ) ) {
			$errors[] = __( 'Something went wrong. Please try again.', 'olivia-studio' ); // Honeypot.
		}
		if ( ! OYS_Security::signup_allowed() ) {
			$errors = array( __( 'Too many new accounts from this connection. Please try again later or contact us.', 'olivia-studio' ) );
		}
		if ( $errors ) {
			foreach ( $errors as $err ) {
				oys_flash( $err, 'error' );
			}
			oys_redirect( add_query_arg( array( 'oys_view' => 'register', 'oys_email' => rawurlencode( $email ), 'oys_first' => rawurlencode( $first ) ), $back ) );
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
			'role'         => 'oys_customer',
		) );
		if ( is_wp_error( $user_id ) ) {
			oys_flash( $user_id->get_error_message(), 'error' );
			oys_redirect( add_query_arg( 'oys_view', 'register', $back ) );
		}
		OYS_Security::record_signup();
		update_user_meta( $user_id, 'oys_phone', $phone );
		update_user_meta( $user_id, 'oys_marketing', empty( $_POST['marketing'] ) ? '' : '1' );
		self::record_waiver( $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		OYS_Emails::welcome( $user_id );
		oys_flash( sprintf( __( 'Welcome, %s! Your account is ready.', 'olivia-studio' ), esc_html( $first ) ) );
		oys_redirect( $back );
	}

	public static function handle_login() {
		check_admin_referer( 'oys_login' );
		$back = self::back();
		$user = wp_signon( array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['email'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['password'] ?? '' ),
			'remember'      => true,
		), is_ssl() );
		if ( is_wp_error( $user ) ) {
			oys_flash( 'oys_locked' === $user->get_error_code() ? $user->get_error_message() : __( 'That email and password don\'t match. Try again or reset your password.', 'olivia-studio' ), 'error' );
			oys_redirect( add_query_arg( 'oys_view', 'login', $back ) );
		}
		oys_redirect( $back );
	}

	public static function handle_profile() {
		check_admin_referer( 'oys_profile' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			oys_redirect( oys_account_url() );
		}
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ) );
		foreach ( self::PROFILE_FIELDS as $key ) {
			$raw = wp_unslash( $_POST[ $key ] ?? '' );
			update_user_meta( $user_id, $key, 'oys_health_notes' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) );
		}
		if ( isset( $_POST['bday_month'] ) ) {
			OYS_Rewards::set_birthday( $user_id, $_POST['bday_month'], $_POST['bday_day'] ?? 0 );
		}
		$new_pass = (string) wp_unslash( $_POST['new_password'] ?? '' );
		if ( $new_pass ) {
			if ( strlen( $new_pass ) < 8 ) {
				oys_flash( __( 'Your new password needs at least 8 characters. Nothing else was changed about your password.', 'olivia-studio' ), 'error' );
			} else {
				wp_set_password( $new_pass, $user_id );
				wp_set_auth_cookie( $user_id, true, is_ssl() );
			}
		}
		oys_flash( __( 'Profile saved.', 'olivia-studio' ) );
		oys_redirect( oys_account_url( 'profile' ) );
	}

	public static function handle_waiver() {
		check_admin_referer( 'oys_waiver' );
		if ( is_user_logged_in() && ! empty( $_POST['waiver'] ) ) {
			self::record_waiver( get_current_user_id() );
		}
		oys_redirect( self::back() );
	}

	/** Staff: find or create a customer by email (for adding walk-ins to a roster). */
	public static function find_or_create( $email, $name = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'oys_email', __( 'Enter a valid email address.', 'olivia-studio' ) );
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return $user->ID;
		}
		$parts = preg_split( '/\s+/', trim( $name ), 2 );
		return wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 20 ),
			'first_name'   => $parts[0] ?? '',
			'last_name'    => $parts[1] ?? '',
			'display_name' => trim( $name ) ?: $email,
			'role'         => 'oys_customer',
		) );
	}
}
