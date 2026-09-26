<?php
/**
 * Zoom meetings for online and hybrid classes, created automatically.
 *
 * Uses a Zoom "Server-to-Server OAuth" app (Account ID, Client ID, Client Secret) with the
 * scopes to create, update and delete meetings and to add and cancel registrants.
 *
 * - A class's meeting is created the first time someone joining online needs the link
 *   (their confirmation email), or by the hourly job for classes starting within a day, or by
 *   the "Create Zoom meeting" button. Classes with a link typed in by hand are left alone.
 * - Moving the class updates the meeting; cancelling it, or making it in-person only, deletes it.
 * - Optional personal links (`zoom_personal`): every online participant is registered and gets
 *   their own join link, so a link passed on doesn't let a second person in. Needs a paid
 *   Zoom plan (registration) and an email for every online guest.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Zoom {

	const TOKEN = 'oys_zoom_token';

	public static function init() {
		add_filter( 'oys_join_link', array( __CLASS__, 'join_link' ), 10, 3 );
		add_action( 'oys_session_saved', array( __CLASS__, 'on_session_saved' ), 10, 2 );
		add_action( 'oys_booking_cancelled', array( __CLASS__, 'on_booking_cancelled' ), 10, 2 );
		add_action( 'oys_hourly', array( __CLASS__, 'prepare_upcoming' ) );
	}

	/* ---------- Configuration ---------- */

	private static function credential( $key ) {
		$const = 'OYS_ZOOM_' . strtoupper( $key );
		return defined( $const ) ? constant( $const ) : (string) OYS_Settings::get( 'zoom_' . $key );
	}

	public static function configured() {
		return self::credential( 'account_id' ) && self::credential( 'client_id' ) && self::credential( 'client_secret' );
	}

	/** Zoom is set up and meetings should be created automatically. */
	public static function enabled() {
		return self::configured() && (int) OYS_Settings::get( 'zoom_auto' );
	}

	/** Everyone joining online gets their own registration link. */
	public static function personal_links() {
		return self::enabled() && (int) OYS_Settings::get( 'zoom_personal' );
	}

	public static function api_base() {
		return defined( 'OYS_ZOOM_API_BASE' ) ? OYS_ZOOM_API_BASE : 'https://api.zoom.us/v2';
	}

	private static function oauth_url() {
		return defined( 'OYS_ZOOM_OAUTH_URL' ) ? OYS_ZOOM_OAUTH_URL : 'https://zoom.us/oauth/token';
	}

	/** Does this class get a Zoom meeting? (Online or hybrid, scheduled, no link typed in by hand.) */
	public static function uses_zoom( $session ) {
		return self::enabled() && $session && oys_has_online( $session ) && 'scheduled' === $session->status && '' === (string) $session->online_url;
	}

	/* ---------- API ---------- */

	private static function token( $fresh = false ) {
		$cached = get_transient( self::TOKEN );
		if ( $cached && ! $fresh ) {
			return $cached;
		}
		$res = wp_remote_post( add_query_arg( array( 'grant_type' => 'account_credentials', 'account_id' => self::credential( 'account_id' ) ), self::oauth_url() ), array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( self::credential( 'client_id' ) . ':' . self::credential( 'client_secret' ) ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = $body['reason'] ?? ( $body['error_description'] ?? ( $body['error'] ?? 'no token' ) );
			oys_log( 'Zoom auth error', array( 'code' => wp_remote_retrieve_response_code( $res ), 'message' => $msg ) );
			return new WP_Error( 'oys_zoom_auth', sprintf( __( 'Zoom did not accept the app credentials: %s', 'olivia-studio' ), $msg ) );
		}
		set_transient( self::TOKEN, $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 ) );
		return $body['access_token'];
	}

	/** @return array|WP_Error decoded JSON (empty array for 204 No Content) */
	public static function request( $method, $path, $body = null, $retry = true ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::api_base() . $path, $args );
		if ( is_wp_error( $res ) ) {
			oys_log( 'Zoom request failed', array( 'path' => $path, 'error' => $res->get_error_message() ) );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code && $retry ) {
			delete_transient( self::TOKEN );
			return self::request( $method, $path, $body, false );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			oys_log( 'Zoom error', array( 'path' => $path, 'code' => $code, 'body' => $json ) );
			return new WP_Error( 'oys_zoom', $json['message'] ?? sprintf( 'Zoom error %d', $code ), array( 'status' => $code ) );
		}
		return is_array( $json ) ? $json : array();
	}

	/** Checks the credentials; returns the account user's email or WP_Error. */
	public static function test_connection() {
		delete_transient( self::TOKEN );
		$me = self::request( 'GET', '/users/' . rawurlencode( self::host() ) );
		return is_wp_error( $me ) ? $me : (string) ( $me['email'] ?? __( 'connected', 'olivia-studio' ) );
	}

	private static function host() {
		return (string) OYS_Settings::get( 'zoom_host' ) ?: 'me';
	}

	/* ---------- Meetings ---------- */

	private static function meeting_fields( $s ) {
		return array(
			'topic'      => mb_substr( oys_session_title( $s ) . ' — ' . OYS_Settings::get( 'email_from_name' ), 0, 190 ),
			'type'       => 2,
			'start_time' => gmdate( 'Y-m-d\TH:i:s\Z', oys_ts( $s->starts_at ) ),
			'duration'   => max( 15, (int) round( ( oys_ts( $s->ends_at ) - oys_ts( $s->starts_at ) ) / 60 ) ),
			'timezone'   => wp_timezone_string(),
		);
	}

	private static function store( $session_id, array $row ) {
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'sessions' ), $row, array( 'id' => (int) $session_id ) );
	}

	/**
	 * The class's meeting, created if it doesn't exist yet.
	 * @return object|WP_Error the session with its zoom_* fields filled
	 */
	public static function ensure_meeting( $session ) {
		$session = is_object( $session ) ? $session : OYS_Schedule::get( $session );
		if ( ! self::uses_zoom( $session ) ) {
			return new WP_Error( 'oys_zoom_off', __( 'This class doesn\'t use Zoom.', 'olivia-studio' ) );
		}
		if ( self::has_meeting( $session ) ) {
			return $session;
		}
		// Don't hammer Zoom on every page view after a failure.
		if ( get_transient( 'oys_zoom_fail_' . $session->id ) ) {
			return new WP_Error( 'oys_zoom_wait', __( 'Creating the Zoom meeting failed a moment ago; it will be retried shortly.', 'olivia-studio' ) );
		}
		// Two requests at once (the Stripe webhook and the customer's return page) must not create two
		// meetings: whoever claims the class creates it, the other waits for its id.
		if ( ! self::claim( $session->id ) ) {
			for ( $i = 0; $i < 20; $i++ ) {
				usleep( 250000 );
				$fresh = OYS_Schedule::get( $session->id );
				if ( self::has_meeting( $fresh ) ) {
					return $fresh;
				}
			}
			return new WP_Error( 'oys_zoom_wait', __( 'The Zoom meeting is being created; the link will be ready in a moment.', 'olivia-studio' ) );
		}
		$personal = (int) OYS_Settings::get( 'zoom_personal' );
		$body     = array_merge( self::meeting_fields( $session ), array(
			'agenda'   => __( 'Live yoga class. Set up where the camera sees your mat (or keep it off), and keep your microphone muted.', 'olivia-studio' ),
			'settings' => array(
				'host_video'        => true,
				'participant_video' => false,
				'join_before_host'  => false,
				'mute_upon_entry'   => true,
				'waiting_room'      => (bool) (int) OYS_Settings::get( 'zoom_waiting_room' ),
				'approval_type'     => $personal ? 0 : 2,
				'registration_type' => 1,
				'auto_recording'    => 'none',
				'audio'             => 'both',
			),
		) );
		$res = self::request( 'POST', '/users/' . rawurlencode( self::host() ) . '/meetings', $body );
		if ( is_wp_error( $res ) || empty( $res['id'] ) ) {
			self::store( $session->id, array( 'zoom_meeting_id' => '' ) );
			set_transient( 'oys_zoom_fail_' . $session->id, 1, 10 * MINUTE_IN_SECONDS );
			OYS_Emails::admin_notice( __( 'Zoom meeting could not be created', 'olivia-studio' ), sprintf( __( '%1$s on %2$s: %3$s. Check Studio → Settings → Zoom, or paste a link on the class in the calendar.', 'olivia-studio' ), oys_session_title( $session ), oys_date( $session->starts_at ), is_wp_error( $res ) ? $res->get_error_message() : 'no meeting id' ) );
			return is_wp_error( $res ) ? $res : new WP_Error( 'oys_zoom', 'No meeting id' );
		}
		self::store( $session->id, array(
			'zoom_meeting_id' => (string) $res['id'],
			'zoom_join_url'   => (string) ( $res['join_url'] ?? '' ),
			'zoom_password'   => (string) ( $res['password'] ?? '' ),
		) );
		do_action( 'oys_zoom_meeting_created', (int) $session->id, $res );
		return OYS_Schedule::get( $session->id );
	}

	/** A real meeting id (not the short-lived "being created" marker). */
	public static function has_meeting( $session ) {
		return $session && '' !== (string) $session->zoom_meeting_id && ! str_starts_with( (string) $session->zoom_meeting_id, 'creating:' );
	}

	/** Atomically mark the class as "meeting being created"; a marker older than a minute is taken over. */
	private static function claim( $session_id ) {
		global $wpdb;
		$t = OYS_Install::table( 'sessions' );
		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE $t SET zoom_meeting_id = %s WHERE id = %d AND ( zoom_meeting_id = '' OR ( zoom_meeting_id LIKE 'creating:%%' AND CAST( SUBSTRING( zoom_meeting_id, 10 ) AS UNSIGNED ) < %d ) )",
			'creating:' . time(), $session_id, time() - 60
		) );
		return 1 === (int) $n;
	}

	/** The link to start the class as host (Zoom issues a fresh one each time). */
	public static function start_url( $session ) {
		$session = self::ensure_meeting( $session );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$m = self::request( 'GET', '/meetings/' . rawurlencode( $session->zoom_meeting_id ) );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		return (string) ( $m['start_url'] ?? '' );
	}

	public static function delete_meeting( $session ) {
		if ( ! self::has_meeting( $session ) ) {
			return;
		}
		$res = self::request( 'DELETE', '/meetings/' . rawurlencode( $session->zoom_meeting_id ) );
		if ( is_wp_error( $res ) && 404 !== (int) ( $res->get_error_data()['status'] ?? 0 ) ) {
			return; // Keep the id so it can be tried again.
		}
		self::store( $session->id, array( 'zoom_meeting_id' => '', 'zoom_join_url' => '', 'zoom_password' => '' ) );
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'bookings' ), array( 'join_url' => '', 'zoom_registrant_id' => '' ), array( 'session_id' => (int) $session->id ) );
	}

	/** Keep the meeting in step with the class: new time or title → update; cancelled or no longer online → delete. */
	public static function on_session_saved( $session_id, $before ) {
		$s = OYS_Schedule::get( $session_id );
		if ( ! self::has_meeting( $s ) || ! self::configured() ) {
			return;
		}
		if ( 'scheduled' !== $s->status || ! oys_has_online( $s ) || '' !== (string) $s->online_url ) {
			self::delete_meeting( $s );
			return;
		}
		if ( $before && ( $before->starts_at !== $s->starts_at || $before->ends_at !== $s->ends_at || $before->title !== $s->title || $before->class_slug !== $s->class_slug ) ) {
			self::request( 'PATCH', '/meetings/' . rawurlencode( $s->zoom_meeting_id ), self::meeting_fields( $s ) );
		}
	}

	/* ---------- People ---------- */

	/**
	 * Filter for OYS_Bookings::join_link(): the class's Zoom link, or the participant's own
	 * registration link when personal links are on.
	 */
	public static function join_link( $url, $booking, $session ) {
		if ( $url || ! self::uses_zoom( $session ) || ! in_array( $booking->status, array( 'confirmed', 'attended', 'no_show' ), true ) ) {
			return $url;
		}
		if ( $booking->join_url ) {
			return $booking->join_url;
		}
		$session = self::ensure_meeting( $session );
		if ( is_wp_error( $session ) ) {
			return '';
		}
		if ( ! self::personal_links() ) {
			return $session->zoom_join_url;
		}
		$user  = get_userdata( $booking->user_id );
		$email = $booking->guest_of ? $booking->guest_email : ( $user ? $user->user_email : '' );
		if ( ! $email ) {
			return $session->zoom_join_url; // Leads to Zoom's registration page.
		}
		$name = $booking->guest_of ? $booking->guest_name : ( $user ? ( $user->first_name ?: $user->display_name ) : '' );
		$last = $booking->guest_of ? '' : ( $user ? (string) $user->last_name : '' );
		$res  = self::request( 'POST', '/meetings/' . rawurlencode( $session->zoom_meeting_id ) . '/registrants', array_filter( array(
			'email'      => $email,
			'first_name' => $name ?: $email,
			'last_name'  => $last,
		) ) );
		if ( is_wp_error( $res ) || empty( $res['join_url'] ) ) {
			return $session->zoom_join_url;
		}
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'bookings' ), array( 'join_url' => $res['join_url'], 'zoom_registrant_id' => (string) ( $res['registrant_id'] ?? ( $res['id'] ?? '' ) ) ), array( 'id' => (int) $booking->id ) );
		return $res['join_url'];
	}

	/** A cancelled online booking loses its personal link. */
	public static function on_booking_cancelled( $booking_id, $outcome ) {
		$b = OYS_Bookings::get( $booking_id );
		if ( ! $b || ! $b->zoom_registrant_id || ! self::configured() ) {
			return;
		}
		$s = OYS_Schedule::get( $b->session_id );
		if ( self::has_meeting( $s ) ) {
			$user  = get_userdata( $b->user_id );
			$email = $b->guest_of ? $b->guest_email : ( $user ? $user->user_email : '' );
			self::request( 'PUT', '/meetings/' . rawurlencode( $s->zoom_meeting_id ) . '/registrants/status', array(
				'action'      => 'cancel',
				'registrants' => array( array( 'id' => $b->zoom_registrant_id, 'email' => $email ) ),
			) );
		}
		global $wpdb;
		$wpdb->update( OYS_Install::table( 'bookings' ), array( 'join_url' => '', 'zoom_registrant_id' => '' ), array( 'id' => (int) $b->id ) );
	}

	/** Hourly: classes starting within a day that people join online get their meeting (and links) ready. */
	public static function prepare_upcoming() {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$s   = OYS_Install::table( 'sessions' );
		$b   = OYS_Install::table( 'bookings' );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT s.id FROM $s s JOIN $b b ON b.session_id = s.id AND b.mode = 'online' AND b.status = 'confirmed'
			 WHERE s.status = 'scheduled' AND s.format IN ('online','hybrid') AND s.online_url = '' AND s.starts_at BETWEEN %s AND %s",
			oys_now(), oys_utc_plus( 26 * HOUR_IN_SECONDS )
		) );
		foreach ( $ids as $id ) {
			$session = self::ensure_meeting( (int) $id );
			if ( is_wp_error( $session ) || ! self::personal_links() ) {
				continue;
			}
			foreach ( OYS_Bookings::for_session( $session->id, array( 'confirmed' ) ) as $row ) {
				if ( 'online' === $row->mode && ! $row->join_url ) {
					self::join_link( '', OYS_Bookings::get( $row->id ), $session );
				}
			}
		}
	}
}
