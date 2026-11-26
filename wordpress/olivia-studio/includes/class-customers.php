<?php
/**
 * Customer accounts: sign up, log in, profile, liability waiver.
 * Customers are WordPress users with the `oys_customer` role; they never see wp-admin.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Customers {

	const PROFILE_FIELDS = array( 'oys_phone', 'oys_emergency_name', 'oys_emergency_phone', 'oys_health_notes', 'oys_area', 'oys_marketing' );

	/** A profile photo: JPG, PNG or WebP, at most this many bytes. */
	const AVATAR_MAX = 5242880;

	/**
	 * Áron, 19 November: „az adatok kitöltése kötelező legyen” – what every customer gives: name, phone number, town. The
	 * emergency contact, health notes and birthday stay optional (health data is only ever given by choice).
	 * @return array field => label of what is still missing
	 */
	public static function missing( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return array();
		}
		$have = array(
			'last_name'  => $u->last_name,
			'first_name' => $u->first_name,
			'oys_phone'  => get_user_meta( $u->ID, 'oys_phone', true ),
			'oys_area'   => get_user_meta( $u->ID, 'oys_area', true ),
		);
		$labels = array(
			'last_name'  => __( 'Last name', 'olivia-studio' ),
			'first_name' => __( 'First name', 'olivia-studio' ),
			'oys_phone'  => __( 'Phone number', 'olivia-studio' ),
			'oys_area'   => __( 'Town or city', 'olivia-studio' ),
		);
		$out = array();
		foreach ( $have as $k => $v ) {
			if ( '' === trim( (string) $v ) ) {
				$out[ $k ] = $labels[ $k ];
			}
		}
		// Áron, 26 November (the guests from Motibro activating their accounts): „a részvételi nyilatkozatot legyen kötelező
		// elfogadni, és a hírlevélre feliratkozást is … amikor elkészül a fiókja” – the agreement, and a yes or no on the
		// newsletter (a consent can't be forced, but the question must be answered)
		if ( ! self::has_waiver( $u->ID ) ) {
			$out['waiver'] = __( 'Participation agreement', 'olivia-studio' );
		}
		if ( ! self::marketing_answered( $u->ID ) ) {
			$out['oys_marketing'] = __( 'Newsletter (yes or no)', 'olivia-studio' );
		}
		if ( ! oys_is_hu() && isset( $out['last_name'], $out['first_name'] ) ) {
			$out = array( 'first_name' => $out['first_name'], 'last_name' => $out['last_name'] ) + $out;
		}
		return $out;
	}

	/**
	 * Has the customer said yes or no to the newsletter? Those who signed up on the site did (the box on the form); a guest
	 * brought over from Motibro answers when they complete their account.
	 */
	public static function marketing_answered( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, 'oys_marketing', true )
			|| '' !== (string) get_user_meta( (int) $user_id, 'oys_marketing_at', true )
			|| '' === (string) get_user_meta( (int) $user_id, 'oys_imported_at', true );
	}

	/** The customer's own yes or no to the newsletter (kept with when it was said). */
	public static function set_marketing( $user_id, $on ) {
		update_user_meta( (int) $user_id, 'oys_marketing', $on ? '1' : '' );
		update_user_meta( (int) $user_id, 'oys_marketing_at', oys_now() );
	}

	/** The name as it is said: „Kovács Olívia” in Hungarian, „Olivia Kovacs” otherwise. */
	public static function full_name( $first, $last ) {
		return trim( oys_is_hu() ? $last . ' ' . $first : $first . ' ' . $last );
	}

	/** The customer's profile photo (an attachment id), 0 without one. */
	public static function avatar_id( $user_id ) {
		$id = (int) get_user_meta( (int) $user_id, 'oys_avatar_id', true );
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** Saves an uploaded profile photo ($_FILES entry) for the customer; the earlier one goes. @return int|WP_Error */
	public static function set_avatar( $user_id, array $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) || ( ! is_uploaded_file( $file['tmp_name'] ) && ! apply_filters( 'oys_avatar_trust_file', false, $file ) ) ) {
			return new WP_Error( 'oys_avatar', __( 'The photo could not be uploaded.', 'olivia-studio' ) );
		}
		if ( (int) $file['size'] > self::AVATAR_MAX ) {
			return new WP_Error( 'oys_avatar', __( 'The photo is too large (5 MB at most).', 'olivia-studio' ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
		$info  = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $check['type'] || ! $info ) {
			return new WP_Error( 'oys_avatar', __( 'The profile photo must be a JPG, PNG or WebP image.', 'olivia-studio' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$named = array_merge( $file, array( 'name' => 'profil-' . (int) $user_id . '-' . wp_generate_password( 6, false ) . '.' . $check['ext'], 'type' => $check['type'] ) );
		$id    = media_handle_sideload( $named, 0, __( 'Profile photo', 'olivia-studio' ), array( 'post_author' => (int) $user_id ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::remove_avatar( $user_id );
		update_user_meta( (int) $user_id, 'oys_avatar_id', (int) $id );
		update_post_meta( (int) $id, '_oys_avatar', (int) $user_id ); // kept out of the media library
		return (int) $id;
	}

	/** The customers' photos stay out of Olivia's media library (they are not site images). */
	public static function hide_avatars( $query ) {
		$meta   = (array) ( $query['meta_query'] ?? array() );
		$meta[] = array( 'key' => '_oys_avatar', 'compare' => 'NOT EXISTS' );
		$query['meta_query'] = $meta;
		return $query;
	}

	public static function hide_avatars_list( $query ) {
		global $pagenow;
		if ( is_admin() && 'upload.php' === $pagenow && $query->is_main_query() ) {
			$query->set( 'meta_query', self::hide_avatars( array( 'meta_query' => (array) $query->get( 'meta_query' ) ) )['meta_query'] );
		}
	}

	public static function remove_avatar( $user_id ) {
		$old = (int) get_user_meta( (int) $user_id, 'oys_avatar_id', true );
		if ( $old ) {
			wp_delete_attachment( $old, true );
		}
		delete_user_meta( (int) $user_id, 'oys_avatar_id' );
	}

	/** get_avatar() everywhere (the Hub, the rosters, the chats) shows the customer's own photo. */
	public static function avatar_data( $args, $id_or_email ) {
		$uid = 0;
		if ( is_numeric( $id_or_email ) ) {
			$uid = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$uid = (int) $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Post ) {
			$uid = (int) $id_or_email->post_author;
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$uid = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u   = get_user_by( 'email', $id_or_email );
			$uid = $u ? (int) $u->ID : 0;
		}
		$aid = $uid ? self::avatar_id( $uid ) : 0;
		$url = $aid ? wp_get_attachment_image_url( $aid, (int) ( $args['size'] ?? 96 ) > 150 ? 'medium' : 'thumbnail' ) : '';
		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}
		return $args;
	}

	public static function init() {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar_data' ), 10, 2 );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'hide_avatars' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_avatars_list' ) );
		add_action( 'delete_user', array( __CLASS__, 'remove_avatar' ) );
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
		return $user && $user->exists() && ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'oys_manage' ) && ! user_can( $user, 'oys_teach' );
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
		if ( $user instanceof WP_User && user_can( $user, 'oys_teach' ) && ! user_can( $user, 'oys_manage' ) && ( ! $requested || str_contains( $requested, 'wp-admin/' ) && ! str_contains( $requested, 'page=' ) ) ) {
			return admin_url( 'admin.php?page=oys-teach' );
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

	/** What is wrong with a sign-up (name, phone number and town are required; Áron, 19 November). */
	public static function register_errors( $first, $last, $email, $phone, $area, $pass ) {
		$errors = array();
		if ( ! $first || ! $last ) {
			$errors[] = __( 'Enter your first and last name.', 'olivia-studio' );
		}
		if ( ! $phone ) {
			$errors[] = __( 'Enter your phone number.', 'olivia-studio' );
		}
		if ( ! $area ) {
			$errors[] = __( 'Enter your town or city.', 'olivia-studio' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Enter an email address like name@example.com.', 'olivia-studio' );
		} elseif ( email_exists( $email ) ) {
			$errors[] = sprintf( __( 'There is already an account for %s. Log in instead, or reset your password.', 'olivia-studio' ), esc_html( $email ) );
		}
		if ( strlen( $pass ) < 8 ) {
			$errors[] = __( 'Choose a password with at least 8 characters.', 'olivia-studio' );
		}
		return $errors;
	}

	public static function handle_register() {
		check_admin_referer( 'oys_register' );
		$back  = self::back();
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$area  = sanitize_text_field( wp_unslash( $_POST['area'] ?? '' ) );
		$pass  = (string) wp_unslash( $_POST['password'] ?? '' );

		// Áron, 26 November („hülyebiztosra”): someone who already has an account here (a guest from Motibro, or who forgot)
		// and signs up again gets the link to choose a password, not an error
		$known = is_email( $email ) && empty( $_POST['website'] ) ? get_user_by( 'email', $email ) : false;
		if ( $known && self::is_customer_only( $known ) ) {
			OYS_Welcome::send_new( $known, true );
			oys_redirect( add_query_arg( 'oys_view', 'login', $back ) );
		}
		$errors = self::register_errors( $first, $last, $email, $phone, $area, $pass );
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
			oys_redirect( add_query_arg( array( 'oys_view' => 'register', 'oys_email' => rawurlencode( $email ), 'oys_first' => rawurlencode( $first ), 'oys_last' => rawurlencode( $last ), 'oys_phone' => rawurlencode( $phone ), 'oys_area' => rawurlencode( $area ) ), $back ) );
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => self::full_name( $first, $last ),
			'role'         => 'oys_customer',
		) );
		if ( is_wp_error( $user_id ) ) {
			oys_flash( $user_id->get_error_message(), 'error' );
			oys_redirect( add_query_arg( 'oys_view', 'register', $back ) );
		}
		OYS_Security::record_signup();
		update_user_meta( $user_id, 'oys_phone', $phone );
		update_user_meta( $user_id, 'oys_area', $area );
		self::set_marketing( $user_id, ! empty( $_POST['marketing'] ) );
		self::record_waiver( $user_id );
		OYS_Activity::on_signup( $user_id );
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
		$before  = self::missing( $user_id );
		$missing = self::save_profile( $user_id, wp_unslash( $_POST ), $_FILES ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		OYS_Activity::on_profile( $user_id, $before && ! $missing );
		if ( $before && ! $missing ) {
			// Áron, 26 November („hülyebiztosra”): the account is complete – straight on to booking, with one message
			oys_render_flash();
			oys_flash( __( 'Everything is there. Pick a class and book!', 'olivia-studio' ) );
			oys_redirect( oys_page_url( 'book' ) );
		}
		oys_redirect( oys_account_url( 'profile' ) );
	}

	/** Saves the profile form (already unslashed) and says what is still missing. */
	public static function save_profile( $user_id, array $post, array $files = array() ) {
		$first = sanitize_text_field( $post['first_name'] ?? '' );
		$last  = sanitize_text_field( $post['last_name'] ?? '' );
		$u     = get_userdata( $user_id );
		// a required field left empty keeps what was there (and says so)
		$first = '' !== $first ? $first : (string) $u->first_name;
		$last  = '' !== $last ? $last : (string) $u->last_name;
		wp_update_user( array( 'ID' => $user_id, 'first_name' => $first, 'last_name' => $last, 'display_name' => self::full_name( $first, $last ) ) );
		if ( ! empty( $post['oys_avatar_remove'] ) ) {
			self::remove_avatar( $user_id );
		}
		if ( ! empty( $files['oys_avatar']['name'] ) ) {
			$saved = self::set_avatar( $user_id, $files['oys_avatar'] );
			if ( is_wp_error( $saved ) ) {
				oys_flash( $saved->get_error_message(), 'error' );
			}
		}
		foreach ( self::PROFILE_FIELDS as $key ) {
			if ( 'oys_marketing' === $key ) {
				continue; // a yes or a no, below
			}
			$raw = (string) ( $post[ $key ] ?? '' );
			$val = 'oys_health_notes' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			if ( '' === $val && in_array( $key, array( 'oys_phone', 'oys_area' ), true ) && '' !== (string) get_user_meta( $user_id, $key, true ) ) {
				continue; // required: an empty field does not wipe it
			}
			update_user_meta( $user_id, $key, $val );
		}
		if ( isset( $post['oys_marketing'] ) && in_array( (string) $post['oys_marketing'], array( '0', '1' ), true ) ) {
			self::set_marketing( $user_id, '1' === (string) $post['oys_marketing'] );
		}
		if ( ! empty( $post['waiver'] ) && ! self::has_waiver( $user_id ) ) {
			self::record_waiver( $user_id );
		}
		if ( isset( $post['bday_month'] ) ) {
			OYS_Rewards::set_birthday( $user_id, $post['bday_month'], $post['bday_day'] ?? 0 );
		}
		$new_pass = (string) ( $post['new_password'] ?? '' );
		if ( $new_pass ) {
			if ( strlen( $new_pass ) < 8 ) {
				oys_flash( __( 'Your new password needs at least 8 characters. Nothing else was changed about your password.', 'olivia-studio' ), 'error' );
			} else {
				wp_set_password( $new_pass, $user_id );
				wp_set_auth_cookie( $user_id, true, is_ssl() );
			}
		}
		$missing = self::missing( $user_id );
		if ( $missing ) {
			oys_flash( sprintf( __( 'Please fill in: %s.', 'olivia-studio' ), implode( ', ', $missing ) ), 'error' );
		} else {
			oys_flash( __( 'Profile saved.', 'olivia-studio' ) );
		}
		return $missing;
	}

	public static function handle_waiver() {
		check_admin_referer( 'oys_waiver' );
		if ( is_user_logged_in() && ! empty( $_POST['waiver'] ) ) {
			self::record_waiver( get_current_user_id() );
			OYS_Activity::log( 'waiver', array( 'title' => sprintf( __( 'Participation agreement accepted: %s', 'olivia-studio' ), OYS_Activity::name( get_current_user_id() ) ), 'user' => get_current_user_id(), 'email' => wp_get_current_user()->user_email, 'url' => admin_url( 'admin.php?page=oys-customers&user=' . get_current_user_id() ) ) );
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
