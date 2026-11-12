<?php
/**
 * Our own live streaming for online classes, on Daily (https://www.daily.co) – no Zoom (Olivia and Áron, 2026-11-12).
 *
 *  - Room: every online / hybrid class (and online private session) gets a private Daily room when it's first needed
 *    (someone opens the live page, or the hourly job a day before). A class with a link typed in by hand keeps it.
 *  - Live page: the account page with ?live=<class>. The video (Daily Prebuilt, in the browser, no app) and the class
 *    chat side by side. Booked participants get their own link (also works without logging in: it's signed with
 *    their booking); the teacher of the class and the studio join as owner.
 *  - Chat: ours (table oys_live_chat), not Daily's, so it stays after class and people can keep talking
 *    (`live_chat_days` after the class; readable as long as the page is).
 *  - Recording (`live_record`: group = group classes and events, all, none): the teacher's browser starts the cloud
 *    recording of the teacher only (layout single-participant) when the class starts, so the participants are never
 *    on it. Booked participants can watch it for `live_replay_days`.
 *  - Opening words: the teacher's first `live_intro_minutes` minutes are transcribed (only the teacher), and after
 *    class the AI writes a short summary – not word for word – in the class's language. `live_summary`: auto (shown
 *    on the live page right away), review (the teacher publishes it), off.
 *
 * Key: OYS_DAILY_API_KEY in wp-config.php (or Settings → Live online). OYS_DAILY_API_BASE for tests.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Live {

	const SEEN = array( 'confirmed', 'attended', 'no_show' );

	public static function init() {
		add_filter( 'oys_join_link', array( __CLASS__, 'join_link' ), 5, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'oys_session_saved', array( __CLASS__, 'on_session_saved' ), 10, 2 );
		add_action( 'oys_hourly', array( __CLASS__, 'prepare_upcoming' ) );
		add_action( 'oys_frequent', array( __CLASS__, 'process' ) );
	}

	/* ---------- Configuration ---------- */

	public static function key() {
		return defined( 'OYS_DAILY_API_KEY' ) ? (string) OYS_DAILY_API_KEY : (string) OYS_Settings::get( 'daily_api_key' );
	}

	public static function api_base() {
		return defined( 'OYS_DAILY_API_BASE' ) ? OYS_DAILY_API_BASE : 'https://api.daily.co/v1';
	}

	/** Our streaming is set up and on: online classes run on it (and Zoom stays out). */
	public static function enabled() {
		return '' !== trim( self::key() ) && (int) OYS_Settings::get( 'live_on' );
	}

	/** Does this class run in our live room? (Online or hybrid, scheduled or over, no link typed in by hand.) */
	public static function uses_live( $session ) {
		return self::enabled() && $session && oys_has_online( $session ) && in_array( $session->status, array( 'scheduled', 'completed' ), true ) && '' === (string) $session->online_url;
	}

	/** Is the teacher recorded in this class? Group classes and events by default, private sessions only with "all". */
	public static function records( $session ) {
		$mode = (string) OYS_Settings::get( 'live_record' );
		return 'all' === $mode || ( 'group' === $mode && 'private' !== $session->kind );
	}

	/** The opening words are summarised in group classes and events (never in private sessions). */
	public static function summarises( $session ) {
		return 'off' !== (string) OYS_Settings::get( 'live_summary' ) && 'private' !== $session->kind && (int) OYS_Settings::get( 'live_intro_minutes' ) > 0;
	}

	/** Language of the class (transcription, summary): the class's own, else the site's. */
	public static function lang( $session ) {
		$langs = array_keys( OYS_I18n::languages() );
		return count( $langs ) > 1 ? oys_valid_lang( $session->lang ?? 'en' ) : oys_valid_lang( $langs[0] ?? 'en' );
	}

	/* ---------- Daily API ---------- */

	public static function api( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . self::key(), 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::api_base() . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'oys_live', sprintf( __( 'Could not reach Daily: %s', 'olivia-studio' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$info = is_array( $data ) ? (string) ( $data['info'] ?? ( $data['error'] ?? '' ) ) : '';
			oys_log( 'Daily request failed', array( 'path' => strtok( $path, '?' ), 'status' => $code, 'info' => $info ) );
			return new WP_Error( 'oys_live', sprintf( __( 'Daily answered with an error (%1$d). %2$s', 'olivia-studio' ), $code, $info ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function test_connection() {
		$res = self::api( 'GET', '/rooms?limit=1' );
		return is_wp_error( $res ) ? $res : true;
	}

	/* ---------- Rooms ---------- */

	public static function table( $name = 'live' ) {
		return OYS_Install::table( $name );
	}

	public static function row( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_id = %d', (int) $session_id ) );
	}

	/** When the room opens (minutes before the start) and closes (after the end). */
	private static function window( $session ) {
		return array(
			oys_ts( $session->starts_at ) - max( 5, (int) OYS_Settings::get( 'live_open_minutes' ) ) * MINUTE_IN_SECONDS,
			oys_ts( $session->ends_at ) + 30 * MINUTE_IN_SECONDS,
		);
	}

	/** The class's Daily room, created the first time it's needed. @return object|WP_Error the oys_live row */
	public static function ensure_room( $session ) {
		$session = is_object( $session ) ? $session : OYS_Schedule::get( (int) $session );
		if ( ! self::uses_live( $session ) ) {
			return new WP_Error( 'oys_live', __( 'This class has no live room.', 'olivia-studio' ) );
		}
		$row = self::row( $session->id );
		if ( $row && $row->room_name ) {
			return $row;
		}
		list( $opens, $closes ) = self::window( $session );
		$props = array(
			'nbf'                          => $opens - HOUR_IN_SECONDS, // the teacher can come in an hour early
			'exp'                          => $closes,
			'eject_at_room_exp'            => true,
			'enable_chat'                  => false, // the class chat is ours: it stays after class
			'enable_prejoin_ui'            => true,
			'enable_knocking'              => false,
			'enable_transcription_storage' => true,
			'lang'                         => 'es' === self::lang( $session ) ? 'es' : 'en',
		);
		$room = self::api( 'POST', '/rooms', array(
			'name'       => 'oys-' . substr( md5( home_url() ), 0, 6 ) . '-' . (int) $session->id . '-' . strtolower( wp_generate_password( 6, false, false ) ),
			'privacy'    => 'private',
			'properties' => $props,
		) );
		if ( is_wp_error( $room ) ) {
			return $room;
		}
		if ( empty( $room['name'] ) || empty( $room['url'] ) ) {
			return new WP_Error( 'oys_live', __( 'Daily did not return a room.', 'olivia-studio' ) );
		}
		global $wpdb;
		$data = array(
			'session_id' => (int) $session->id,
			'room_name'  => sanitize_text_field( $room['name'] ),
			'room_url'   => esc_url_raw( $room['url'] ),
			'room_id'    => sanitize_text_field( (string) ( $room['id'] ?? '' ) ),
			'state'      => 'room',
			'created_at' => oys_now(),
		);
		$row ? $wpdb->update( self::table(), $data, array( 'session_id' => (int) $session->id ) ) : $wpdb->insert( self::table(), $data );
		return self::row( $session->id );
	}

	/** Moved: the room opens and closes with the class. Cancelled or no longer online: the room goes. */
	public static function on_session_saved( $session_id, $before = null ) {
		$row = self::row( $session_id );
		if ( ! $row || ! $row->room_name || ! self::enabled() ) {
			return;
		}
		$s = OYS_Schedule::get( $session_id );
		if ( ! $s || 'cancelled' === $s->status || ! oys_has_online( $s ) || '' !== (string) $s->online_url ) {
			self::api( 'DELETE', '/rooms/' . rawurlencode( $row->room_name ) );
			global $wpdb;
			$wpdb->update( self::table(), array( 'room_name' => '', 'room_url' => '', 'state' => 'closed' ), array( 'session_id' => (int) $session_id ) );
			return;
		}
		if ( $before && ( $before->starts_at !== $s->starts_at || $before->ends_at !== $s->ends_at ) ) {
			list( $opens, $closes ) = self::window( $s );
			self::api( 'POST', '/rooms/' . rawurlencode( $row->room_name ), array( 'properties' => array( 'nbf' => $opens - HOUR_IN_SECONDS, 'exp' => $closes ) ) );
		}
	}

	/** Hourly: rooms for the classes of the next day that people booked online. */
	public static function prepare_upcoming() {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( OYS_Schedule::query( array( 'from' => oys_now(), 'to' => oys_utc_plus( DAY_IN_SECONDS ), 'kind' => array( 'group', 'event', 'private' ) ) ) as $s ) {
			if ( self::uses_live( $s ) && ! self::row( $s->id ) && ( (int) $s->online_booked || 'private' === $s->kind || oys_is_online( $s ) && (int) $s->booked ) ) {
				self::ensure_room( $s );
			}
		}
	}

	/* ---------- Who is it ---------- */

	/** The signature of a participant's own link (their booking). */
	public static function sign( $session_id, $booking_id ) {
		return substr( hash_hmac( 'sha256', 'live|' . (int) $session_id . '|' . (int) $booking_id, wp_salt( 'auth' ) ), 0, 24 );
	}

	/** The live page of a class: for a participant their own link, for the teacher the plain one. */
	public static function url( $session_id, $booking_id = 0 ) {
		$args = array( 'live' => (int) $session_id );
		if ( $booking_id ) {
			$args['b'] = (int) $booking_id;
			$args['k'] = self::sign( $session_id, $booking_id );
		}
		return oys_page_url( 'account', $args );
	}

	/** Filter for OYS_Bookings::join_link(): the participant's own live page. */
	public static function join_link( $url, $booking, $session ) {
		if ( $url || ! self::uses_live( $session ) || ! in_array( $booking->status, self::SEEN, true ) ) {
			return $url;
		}
		return self::url( $session->id, $booking->id );
	}

	/** Teacher of the class, or the studio: joins as owner (records, can remove chat messages). */
	public static function is_owner( $session, $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'oys_manage' ) ) {
			return true;
		}
		$t = (int) $session->teacher_id ? OYS_Teachers::get( $session->teacher_id ) : null;
		return $t && (int) $t->user_id === (int) $user_id;
	}

	/**
	 * Who is asking: [ owner, booking, user_id, name ] or null. $b / $k = the signed booking of a participant's own link.
	 */
	public static function who( $session, $b = 0, $k = '' ) {
		if ( ! $session ) {
			return null;
		}
		$user_id = get_current_user_id();
		if ( self::is_owner( $session, $user_id ) ) {
			$u = get_userdata( $user_id );
			return array( 'owner' => true, 'booking' => null, 'user_id' => $user_id, 'name' => $u ? ( $u->display_name ?: $u->first_name ) : '' );
		}
		$booking = null;
		if ( $b && $k && hash_equals( self::sign( $session->id, $b ), (string) $k ) ) {
			$booking = OYS_Bookings::get( (int) $b );
		} elseif ( $user_id ) {
			$booking = OYS_Bookings::active_for( $user_id, $session->id );
		}
		if ( ! $booking || (int) $booking->session_id !== (int) $session->id || ! in_array( $booking->status, self::SEEN, true ) || 'online' !== oys_mode_for( $session, $booking->mode ) ) {
			return null;
		}
		if ( $booking->guest_of ) {
			$name = $booking->guest_name ?: __( 'Guest', 'olivia-studio' );
		} else {
			$u    = get_userdata( $booking->user_id );
			$name = $u ? trim( ( $u->first_name ?: $u->display_name ) . ( $u->last_name ? ' ' . mb_substr( $u->last_name, 0, 1 ) . '.' : '' ) ) : '';
		}
		return array( 'owner' => false, 'booking' => $booking, 'user_id' => (int) $booking->user_id, 'name' => $name ?: __( 'Guest', 'olivia-studio' ) );
	}

	/** before | open | over, for joining the video. */
	public static function phase( $session, $owner = false ) {
		list( $opens, $closes ) = self::window( $session );
		$now = time();
		if ( 'completed' === $session->status || $now > $closes ) {
			return 'over';
		}
		return $now >= $opens - ( $owner ? HOUR_IN_SECONDS : 0 ) ? 'open' : 'before';
	}

	/** People can write in the chat until `live_chat_days` after the class. */
	public static function chat_open( $session ) {
		return time() <= oys_ts( $session->ends_at ) + max( 0, (int) OYS_Settings::get( 'live_chat_days' ) ) * DAY_IN_SECONDS;
	}

	public static function replay_open( $session, $owner = false ) {
		return $owner || time() <= oys_ts( $session->ends_at ) + max( 0, (int) OYS_Settings::get( 'live_replay_days' ) ) * DAY_IN_SECONDS;
	}

	/* ---------- Joining ---------- */

	/** @return array|WP_Error what the live page needs to join the video */
	public static function join( $session, array $who ) {
		if ( 'open' !== self::phase( $session, $who['owner'] ) ) {
			return new WP_Error( 'oys_live', 'over' === self::phase( $session ) ? __( 'This class is over.', 'olivia-studio' ) : __( 'The room opens shortly before the class.', 'olivia-studio' ) );
		}
		$row = self::ensure_room( $session );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		list( , $closes ) = self::window( $session );
		$props = array(
			'room_name'          => $row->room_name,
			'user_name'          => mb_substr( $who['name'], 0, 60 ),
			'user_id'            => $who['owner'] ? 'teacher-' . (int) $who['user_id'] : 'booking-' . (int) $who['booking']->id,
			'is_owner'           => (bool) $who['owner'],
			'exp'                => $closes,
			'eject_at_token_exp' => true,
		);
		if ( $who['owner'] && self::records( $session ) ) {
			$props['enable_recording'] = 'cloud'; // only the teacher can record
		}
		$token = self::api( 'POST', '/meeting-tokens', array( 'properties' => $props ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( empty( $token['token'] ) ) {
			return new WP_Error( 'oys_live', __( 'Daily did not return an entry token.', 'olivia-studio' ) );
		}
		$lang = self::lang( $session );
		return array(
			'url'    => $row->room_url,
			'token'  => (string) $token['token'],
			'owner'  => (bool) $who['owner'],
			'record' => $who['owner'] && self::records( $session ),
			'intro'  => $who['owner'] && self::summarises( $session ) ? array(
				'start'    => oys_ts( $session->starts_at ) * 1000,
				'minutes'  => (int) OYS_Settings::get( 'live_intro_minutes' ),
				'language' => $lang,
				'model'    => 'nova-3',
			) : null,
			'start'  => oys_ts( $session->starts_at ) * 1000,
		);
	}

	/* ---------- Chat ---------- */

	public static function messages( $session_id, $after = 0, $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'live_chat' ) . ' WHERE session_id = %d AND id > %d AND deleted = 0 ORDER BY id ASC LIMIT %d', (int) $session_id, (int) $after, (int) $limit ) );
	}

	/** @return int|WP_Error the new message's id */
	public static function post_message( $session, array $who, $body ) {
		$body = trim( mb_substr( sanitize_textarea_field( (string) $body ), 0, 1000 ) );
		if ( '' === $body ) {
			return new WP_Error( 'oys_live', __( 'Write a message first.', 'olivia-studio' ) );
		}
		if ( ! $who['owner'] && ! self::chat_open( $session ) ) {
			return new WP_Error( 'oys_live', __( 'The chat of this class is closed.', 'olivia-studio' ) );
		}
		$who_key = $who['owner'] ? 'u' . $who['user_id'] : 'b' . $who['booking']->id;
		$flood   = 'oys_live_chat_' . md5( $session->id . '|' . $who_key );
		if ( get_transient( $flood ) ) {
			return new WP_Error( 'oys_live', __( 'One moment, then send it again.', 'olivia-studio' ) );
		}
		set_transient( $flood, 1, 2 );
		global $wpdb;
		$wpdb->insert( self::table( 'live_chat' ), array(
			'session_id' => (int) $session->id,
			'user_id'    => (int) $who['user_id'],
			'booking_id' => $who['booking'] ? (int) $who['booking']->id : 0,
			'name'       => mb_substr( $who['name'], 0, 100 ),
			'body'       => $body,
			'is_teacher' => $who['owner'] ? 1 : 0,
			'created_at' => oys_now(),
		) );
		return (int) $wpdb->insert_id;
	}

	/** The teacher removes any message, a participant their own. */
	public static function delete_message( $session, array $who, $message_id ) {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'live_chat' ) . ' WHERE id = %d AND session_id = %d', (int) $message_id, (int) $session->id ) );
		if ( ! $m || ( ! $who['owner'] && ( ! $who['booking'] || (int) $m->booking_id !== (int) $who['booking']->id ) ) ) {
			return false;
		}
		return (bool) $wpdb->update( self::table( 'live_chat' ), array( 'deleted' => 1 ), array( 'id' => (int) $m->id ) );
	}

	private static function shape_message( $m, array $who ) {
		return array(
			'id'      => (int) $m->id,
			'name'    => $m->name,
			'body'    => $m->body,
			'time'    => oys_date( $m->created_at, 'M j, g:i a' ),
			'teacher' => (bool) $m->is_teacher,
			'mine'    => $who['owner'] ? (bool) $m->is_teacher && (int) $m->user_id === (int) $who['user_id'] : ( $who['booking'] && (int) $m->booking_id === (int) $who['booking']->id ),
			'can_del' => $who['owner'] || ( $who['booking'] && (int) $m->booking_id === (int) $who['booking']->id ),
		);
	}

	/* ---------- After class: recording, opening words ---------- */

	/** Every 5 minutes: rooms of classes that ended at least 15 minutes ago get their recording and summary. */
	public static function process() {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT l.* FROM ' . self::table() . ' l JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = l.session_id WHERE l.state = 'room' AND l.room_name <> '' AND s.ends_at < %s ORDER BY s.ends_at ASC LIMIT 5",
			oys_utc_plus( -15 * MINUTE_IN_SECONDS )
		) );
		foreach ( $rows as $row ) {
			self::process_one( $row );
		}
	}

	public static function process_one( $row ) {
		$session = OYS_Schedule::get( $row->session_id );
		if ( ! $session ) {
			return;
		}
		$update = array( 'tries' => (int) $row->tries + 1, 'processed_at' => oys_now() );
		$wait   = false;

		$recs = self::recordings( $row );
		if ( is_wp_error( $recs ) ) {
			$wait = true;
		} else {
			$wait = $wait || in_array( 'in-progress', array_column( $recs, 'status' ), true );
			$done = array_values( array_filter( $recs, fn( $r ) => 'finished' === $r['status'] ) );
			$update['recordings'] = wp_json_encode( $done );
		}

		if ( self::summarises( $session ) && '' === (string) $row->summary ) {
			$t = self::fetch_transcript( $row );
			if ( is_wp_error( $t ) || 'pending' === $t['state'] ) {
				$wait = true;
			} elseif ( 'ready' === $t['state'] ) {
				$update['transcript'] = $t['text'];
				$sum                  = self::summarize( $session, $t['text'] );
				if ( is_wp_error( $sum ) ) {
					$wait = $wait || OYS_AI::ready();
				} elseif ( '' !== $sum ) {
					$update['summary']        = $sum;
					$update['summary_status'] = 'review' === OYS_Settings::get( 'live_summary' ) ? 'draft' : 'published';
				}
			}
		}
		// Daily needs a few minutes for the recording and the transcript; after about two hours we stop asking.
		$update['state'] = $wait && $update['tries'] < 24 ? 'room' : 'done';
		global $wpdb;
		$wpdb->update( self::table(), $update, array( 'session_id' => (int) $row->session_id ) );
	}

	/** Recordings of the room on Daily: [ id, status, minutes, start ]. */
	public static function recordings( $row ) {
		$list = self::api( 'GET', '/recordings?room_name=' . rawurlencode( $row->room_name ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$out = array();
		foreach ( (array) ( $list['data'] ?? array() ) as $r ) {
			if ( empty( $r['id'] ) || ( $r['room_name'] ?? $row->room_name ) !== $row->room_name ) {
				continue;
			}
			$out[] = array(
				'id'      => sanitize_text_field( $r['id'] ),
				'status'  => sanitize_key( $r['status'] ?? 'finished' ),
				'minutes' => max( 1, (int) round( (int) ( $r['duration'] ?? 0 ) / 60 ) ),
				'start'   => (int) ( $r['start_ts'] ?? 0 ),
			);
		}
		usort( $out, fn( $a, $b ) => $a['start'] <=> $b['start'] );
		return $out;
	}

	/** A short-lived link to watch a recording (Daily keeps the file). */
	public static function recording_link( $row, $recording_id ) {
		$known = array_column( (array) json_decode( (string) $row->recordings, true ), 'id' );
		if ( ! in_array( $recording_id, $known, true ) ) {
			return new WP_Error( 'oys_live', __( 'Recording not found.', 'olivia-studio' ) );
		}
		$link = self::api( 'GET', '/recordings/' . rawurlencode( $recording_id ) . '/access-link' );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		return empty( $link['download_link'] ) ? new WP_Error( 'oys_live', __( 'Daily did not return a link.', 'olivia-studio' ) ) : esc_url_raw( $link['download_link'] );
	}

	/** The transcript of the opening words: [ state => none|pending|ready, text ]. */
	public static function fetch_transcript( $row ) {
		if ( '' === (string) $row->room_id ) {
			return array( 'state' => 'none' );
		}
		$list = self::api( 'GET', '/transcript?roomId=' . rawurlencode( $row->room_id ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$items = array_values( array_filter( (array) ( $list['data'] ?? array() ), fn( $t ) => is_array( $t ) && (string) ( $t['roomId'] ?? $row->room_id ) === (string) $row->room_id ) );
		if ( ! $items ) {
			return array( 'state' => 'none' );
		}
		foreach ( $items as $t ) {
			if ( in_array( (string) ( $t['status'] ?? '' ), array( 't_in_progress', 'in_progress', 'started' ), true ) ) {
				return array( 'state' => 'pending' );
			}
		}
		usort( $items, fn( $a, $b ) => (int) ( $a['created'] ?? 0 ) <=> (int) ( $b['created'] ?? 0 ) );
		$parts = array();
		foreach ( $items as $t ) {
			if ( ! in_array( (string) ( $t['status'] ?? '' ), array( 't_finished', 'finished' ), true ) || ( isset( $t['isVttAvailable'] ) && ! $t['isVttAvailable'] ) ) {
				continue;
			}
			$link = self::api( 'GET', '/transcript/' . rawurlencode( (string) ( $t['transcriptId'] ?? ( $t['id'] ?? '' ) ) ) . '/access-link' );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$url = (string) ( $link['link'] ?? ( $link['download_link'] ?? '' ) );
			$res = $url ? wp_remote_get( $url, array( 'timeout' => 20 ) ) : null;
			if ( ! $res || is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return new WP_Error( 'oys_live', __( 'The transcript could not be downloaded.', 'olivia-studio' ) );
			}
			$parts[] = self::parse_vtt( (string) wp_remote_retrieve_body( $res ) );
		}
		$text = trim( implode( "\n", array_filter( $parts ) ) );
		return '' === $text ? array( 'state' => 'none' ) : array( 'state' => 'ready', 'text' => mb_substr( $text, 0, 20000 ) );
	}

	/** WebVTT → plain text, one line per cue (speaker names dropped: only the teacher is transcribed). */
	public static function parse_vtt( $vtt ) {
		$lines = array();
		$skip  = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $vtt ) as $raw ) {
			$line = trim( $raw );
			if ( '' === $line ) {
				$skip = false;
				continue;
			}
			if ( $skip || str_starts_with( $line, 'WEBVTT' ) || preg_match( '/^(NOTE|STYLE|REGION)\b/', $line ) ) {
				$skip = $skip || ! str_starts_with( $line, 'WEBVTT' );
				continue;
			}
			if ( str_contains( $line, '-->' ) || ctype_digit( $line ) || preg_match( '/^[a-z_-]+:\d+$/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^<v(?:\.[^\s>]*)?\s+[^>]+>(.*)$/u', $line, $m ) ) {
				$line = $m[1];
			} elseif ( preg_match( '/^(\p{L}[^:<>\[\]]{0,58}):(\S.*)$/u', $line, $m ) && ! preg_match( '#(^|\s)https?$#i', $m[1] ) ) {
				$line = $m[2]; // Daily writes "Name:text"
			}
			$text = trim( html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}
		return implode( ' ', $lines );
	}

	/** The AI's short summary of the opening words ('' when there was nothing to summarise). @return string|WP_Error */
	public static function summarize( $session, $transcript ) {
		if ( ! OYS_AI::ready() ) {
			return new WP_Error( 'oys_ai', __( 'No AI key: the summary is not written.', 'olivia-studio' ) );
		}
		$langs   = array( 'hu' => 'Hungarian, addressing the readers informally (tegezés)', 'es' => 'Spanish, addressing the readers informally (tú)', 'en' => 'English' );
		$teacher = (int) $session->teacher_id && OYS_Teachers::get( $session->teacher_id ) ? OYS_Teachers::get( $session->teacher_id )->name : (string) OYS_Settings::get( 'owner_name' );
		$system  = 'You summarise what a yoga teacher said at the start of a live online class (the speech-to-text transcript of her opening words), for the participants to read again after class. '
			. 'Write in ' . ( $langs[ self::lang( $session ) ] ?? 'English' ) . '. 2 to 5 short sentences, warm and simple, in the third person about the teacher (' . $teacher . '). '
			. 'Keep her thoughts and any practical advice; do not quote her word for word, do not add anything she did not say, no hype, no emoji. '
			. 'The transcript is machine-made and can have mistakes: ignore words that make no sense. If it holds nothing worth keeping (only greetings or technical talk), return an empty summary.';
		$out = OYS_AI::json( $system, 'Class: ' . oys_session_title( $session ) . ', ' . oys_date( $session->starts_at, 'M j, Y' ) . "\n\n<transcript>\n" . $transcript . "\n</transcript>", array(
			'type'                 => 'object',
			'properties'           => array( 'summary' => array( 'type' => 'string' ) ),
			'required'             => array( 'summary' ),
			'additionalProperties' => false,
		), 2000 );
		return is_wp_error( $out ) ? $out : sanitize_textarea_field( (string) ( $out['summary'] ?? '' ) );
	}

	/* ---------- REST: the live page talks to these ---------- */

	public static function routes() {
		$base = '/live/(?P<id>\d+)';
		$r    = fn( $path, $method, $cb ) => register_rest_route( 'oys/v1', $base . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => '__return_true' ) );
		$r( '/join', 'POST', 'rest_join' );
		$r( '/chat', 'GET', 'rest_chat' );
		$r( '/chat', 'POST', 'rest_post' );
		$r( '/chat/(?P<mid>\d+)', 'DELETE', 'rest_delete' );
		$r( '/recording', 'GET', 'rest_recording' );
		$r( '/summary', 'POST', 'rest_summary' );
	}

	/** [ session, who ] of a request, or a WP_Error. */
	private static function req( WP_REST_Request $req ) {
		$session = OYS_Schedule::get( (int) $req['id'] );
		$who     = $session && self::uses_live( $session ) ? self::who( $session, (int) $req->get_param( 'b' ), (string) $req->get_param( 'k' ) ) : null;
		return $who ? array( $session, $who ) : new WP_Error( 'oys_live', __( 'This live class is not in your bookings.', 'olivia-studio' ), array( 'status' => 403 ) );
	}

	private static function fail( WP_Error $e, $status = 400 ) {
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $e->get_error_data()['status'] ?? $status ) );
	}

	public static function rest_join( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = self::join( $r[0], $r[1] );
		return is_wp_error( $out ) ? self::fail( $out, 409 ) : rest_ensure_response( $out );
	}

	public static function rest_chat( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		list( $session, $who ) = $r;
		global $wpdb;
		$gone = (int) $req->get_param( 'after' ) ? $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'live_chat' ) . ' WHERE session_id = %d AND deleted = 1 AND id > %d', (int) $session->id, max( 0, (int) $req->get_param( 'after' ) - 400 ) ) ) : array();
		return rest_ensure_response( array(
			'messages' => array_map( fn( $m ) => self::shape_message( $m, $who ), self::messages( $session->id, (int) $req->get_param( 'after' ) ) ),
			'gone'     => array_map( 'intval', $gone ),
			'open'     => $who['owner'] || self::chat_open( $session ),
			'phase'    => self::phase( $session, $who['owner'] ),
		) );
	}

	public static function rest_post( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$id = self::post_message( $r[0], $r[1], (string) $req->get_param( 'body' ) );
		return is_wp_error( $id ) ? self::fail( $id ) : rest_ensure_response( array( 'id' => $id ) );
	}

	public static function rest_delete( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return self::delete_message( $r[0], $r[1], (int) $req['mid'] ) ? rest_ensure_response( array( 'ok' => true ) ) : new WP_Error( 'oys_live', __( 'You can\'t remove this message.', 'olivia-studio' ), array( 'status' => 403 ) );
	}

	public static function rest_recording( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		list( $session, $who ) = $r;
		$row = self::row( $session->id );
		if ( ! $row || ! self::replay_open( $session, $who['owner'] ) ) {
			return new WP_Error( 'oys_live', __( 'The recording is no longer available.', 'olivia-studio' ), array( 'status' => 410 ) );
		}
		$url = self::recording_link( $row, sanitize_text_field( (string) $req->get_param( 'rec' ) ) );
		return is_wp_error( $url ) ? self::fail( $url, 404 ) : rest_ensure_response( array( 'url' => $url ) );
	}

	/** The teacher edits, publishes or hides the summary of the opening words. */
	public static function rest_summary( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r[1]['owner'] ) {
			return new WP_Error( 'oys_live', __( 'Only the teacher can change this.', 'olivia-studio' ), array( 'status' => 403 ) );
		}
		$status = in_array( $req->get_param( 'status' ), array( 'published', 'hidden', 'draft' ), true ) ? $req->get_param( 'status' ) : 'published';
		global $wpdb;
		$wpdb->update( self::table(), array( 'summary' => sanitize_textarea_field( (string) $req->get_param( 'summary' ) ), 'summary_status' => $status ), array( 'session_id' => (int) $r[0]->id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- The live page ---------- */

	/** The account page with ?live=<class>: video, chat, and after class the recording and the opening words. */
	public static function page() {
		$session = OYS_Schedule::get( absint( $_GET['live'] ?? 0 ) );
		$b       = absint( $_GET['b'] ?? 0 );
		$k       = sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) );
		if ( ! $session || ! self::uses_live( $session ) ) {
			return '<div class="oys-card"><p>' . esc_html__( 'This live class can\'t be found.', 'olivia-studio' ) . '</p><p><a class="btn btn--primary" href="' . esc_url( oys_account_url() ) . '">' . esc_html__( 'My bookings', 'olivia-studio' ) . '</a></p></div>';
		}
		$who = self::who( $session, $b, $k );
		if ( ! $who ) {
			if ( ! is_user_logged_in() ) {
				return '<div class="oys-card oys-auth oys-auth--page"><p class="lead">' . esc_html__( 'Log in with the account you booked with, or open the link from your booking email.', 'olivia-studio' ) . '</p>'
					. OYS_Frontend::auth_forms( self::url( $session->id ) ) . '</div>';
			}
			return '<div class="oys-card"><p>' . esc_html__( 'This live class is not in your bookings.', 'olivia-studio' ) . '</p><p><a class="btn btn--primary" href="' . esc_url( oys_book_url( $session->id ) ) . '">' . esc_html__( 'Book this class', 'olivia-studio' ) . '</a></p></div>';
		}
		$row    = self::row( $session->id );
		$phase  = self::phase( $session, $who['owner'] );
		$teacher = (string) OYS_Settings::get( 'owner_name' );
		if ( (int) $session->teacher_id && OYS_Teachers::get( $session->teacher_id ) ) {
			$teacher = OYS_Teachers::get( $session->teacher_id )->name;
		}

		wp_enqueue_script( 'oys-live', OYS_URL . 'assets/live.js', array(), OYS_VERSION, true );
		$cfg = array(
			'api'      => esc_url_raw( rest_url( 'oys/v1/live/' . (int) $session->id ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'b'        => $b,
			'k'        => $k,
			'daily'    => OYS_URL . 'assets/vendor/daily.js?ver=0.92.2',
			'phase'    => $phase,
			'owner'    => $who['owner'],
			'opens'    => self::window( $session )[0] * 1000,
			'chatOpen' => $who['owner'] || self::chat_open( $session ),
			'i18n'     => array(
				'join'      => __( 'Join the class', 'olivia-studio' ),
				'joining'   => __( 'Joining…', 'olivia-studio' ),
				'left'      => __( 'You left the class. You can join again.', 'olivia-studio' ),
				'send'      => __( 'Send', 'olivia-studio' ),
				'remove'    => __( 'Remove', 'olivia-studio' ),
				'teacher'   => __( 'teacher', 'olivia-studio' ),
				'empty'     => __( 'No messages yet. Say hello!', 'olivia-studio' ),
				'error'     => __( 'Something went wrong. Try again in a moment.', 'olivia-studio' ),
				'recOn'     => __( 'Recording (only you are on it)', 'olivia-studio' ),
				'recOff'    => __( 'Not recording', 'olivia-studio' ),
				'recStart'  => __( 'Start recording', 'olivia-studio' ),
				'recStop'   => __( 'Stop recording', 'olivia-studio' ),
				'recSoon'   => __( 'The recording starts with the class.', 'olivia-studio' ),
				'saved'     => __( 'Saved.', 'olivia-studio' ),
				'opensIn'   => __( 'The room opens in %s.', 'olivia-studio' ),
				'min'       => __( 'min', 'olivia-studio' ),
				'openNow'   => __( 'The room is open.', 'olivia-studio' ),
			),
		);

		$when = oys_date( $session->starts_at, 'l, F j · g:i a' ) . '–' . oys_time( $session->ends_at );
		$out  = '<div class="oys-live" data-oys-live="' . esc_attr( wp_json_encode( $cfg ) ) . '">'
			. '<header class="oys-live__head"><p class="oys-live__kicker">' . esc_html__( 'Live online', 'olivia-studio' ) . '</p><h2>' . esc_html( oys_session_title( $session ) ) . '</h2>'
			. '<p class="oys-live__when">' . esc_html( $when ) . ' · ' . esc_html( $teacher ) . '</p></header>'
			. '<div class="oys-live__grid"><section class="oys-live__stage">';

		if ( 'over' === $phase ) {
			$out .= self::after_html( $session, $row, $who );
		} else {
			$out .= '<div class="oys-live__video" data-live-video hidden></div>'
				. '<div class="oys-live__lobby" data-live-lobby><p class="oys-live__status" data-live-status>'
				. ( 'open' === $phase ? esc_html__( 'The room is open.', 'olivia-studio' ) : esc_html( sprintf( __( 'The room opens %d minutes before the class.', 'olivia-studio' ), max( 5, (int) OYS_Settings::get( 'live_open_minutes' ) ) ) ) ) . '</p>'
				. '<button type="button" class="btn btn--primary" data-live-join' . ( 'open' === $phase ? '' : ' disabled' ) . '>' . esc_html__( 'Join the class', 'olivia-studio' ) . '</button>'
				. '<ul class="oys-live__tips"><li>' . esc_html__( 'In your browser, no app needed. On a phone or tablet, open this page in Chrome or Safari.', 'olivia-studio' ) . '</li>'
				. '<li>' . esc_html__( 'Allow the camera and the microphone when your browser asks. You can turn both off in the room.', 'olivia-studio' ) . '</li>'
				. ( self::records( $session ) ? '<li>' . esc_html( sprintf( __( 'The class is recorded, but only %s is on the recording: you are not.', 'olivia-studio' ), $teacher ) ) . '</li>' : '' )
				. '</ul></div>'
				. ( $who['owner'] ? '<div class="oys-live__owner" data-live-owner hidden><span data-live-rec-state></span> <button type="button" class="btn btn--ghost btn--sm" data-live-rec hidden></button></div>' : '' );
		}
		$out .= '</section>' . self::chat_html( $session, $who ) . '</div></div>';
		return $out;
	}

	private static function chat_html( $session, array $who ) {
		$open = $who['owner'] || self::chat_open( $session );
		return '<aside class="oys-live__chat" aria-label="' . esc_attr__( 'Class chat', 'olivia-studio' ) . '"><h3>' . esc_html__( 'Class chat', 'olivia-studio' ) . '</h3>'
			. '<p class="oys-small">' . esc_html( sprintf( _n( 'Stays here after the class: you can keep talking for %d day.', 'Stays here after the class: you can keep talking for %d days.', max( 1, (int) OYS_Settings::get( 'live_chat_days' ) ), 'olivia-studio' ), max( 1, (int) OYS_Settings::get( 'live_chat_days' ) ) ) ) . '</p>'
			. '<ol class="oys-live__messages" data-live-messages aria-live="polite"></ol>'
			. ( $open ? '<form class="oys-live__form" data-live-form><label class="screen-reader-text" for="oys-live-msg">' . esc_html__( 'Message', 'olivia-studio' ) . '</label><textarea id="oys-live-msg" name="body" rows="2" maxlength="1000" required placeholder="' . esc_attr__( 'Write to the class…', 'olivia-studio' ) . '"></textarea><button class="btn btn--primary btn--sm" type="submit">' . esc_html__( 'Send', 'olivia-studio' ) . '</button></form>'
				: '<p class="oys-small">' . esc_html__( 'The chat of this class is closed.', 'olivia-studio' ) . '</p>' )
			. '</aside>';
	}

	/** After class: the opening words (summary) and the recording. */
	private static function after_html( $session, $row, array $who ) {
		$out  = '';
		$sum  = $row ? (string) $row->summary : '';
		$stat = $row ? (string) $row->summary_status : '';
		if ( $who['owner'] && self::summarises( $session ) ) {
			$out .= '<div class="oys-live__summary oys-card"><h3>' . esc_html__( 'Your opening words', 'olivia-studio' ) . '</h3>'
				. ( '' === $sum ? '<p class="oys-small">' . esc_html( $row && 'done' === $row->state ? __( 'No summary: the opening words were not transcribed, or there was nothing to keep.', 'olivia-studio' ) : __( 'The summary is written a few minutes after the class.', 'olivia-studio' ) ) . '</p>' : '' )
				. '<form data-live-summary><textarea name="summary" rows="5" class="oys-live__summary-text">' . esc_textarea( $sum ) . '</textarea>'
				. '<p><label><input type="checkbox" name="published" value="1"' . checked( 'published', $stat, false ) . '> ' . esc_html__( 'Show it to the participants', 'olivia-studio' ) . '</label></p>'
				. '<button class="btn btn--primary btn--sm" type="submit">' . esc_html__( 'Save', 'olivia-studio' ) . '</button> <span class="oys-small" data-live-summary-note></span></form></div>';
		} elseif ( '' !== $sum && 'published' === $stat ) {
			$out .= '<div class="oys-live__summary oys-card"><h3>' . esc_html__( 'From the opening words', 'olivia-studio' ) . '</h3><p>' . nl2br( esc_html( $sum ) ) . '</p>'
				. '<p class="oys-small">' . esc_html__( 'A short summary written with the help of AI, not word for word.', 'olivia-studio' ) . '</p></div>';
		}
		$recs = $row ? array_values( (array) json_decode( (string) $row->recordings, true ) ) : array();
		if ( $recs && self::replay_open( $session, $who['owner'] ) ) {
			$out .= '<div class="oys-live__replay oys-card"><h3>' . esc_html__( 'Watch the class again', 'olivia-studio' ) . '</h3><div data-live-player></div><p>';
			foreach ( $recs as $i => $r ) {
				$out .= '<button type="button" class="btn btn--primary btn--sm" data-live-replay="' . esc_attr( $r['id'] ) . '">' . esc_html( count( $recs ) > 1 ? sprintf( __( 'Part %1$d (%2$d min)', 'olivia-studio' ), $i + 1, $r['minutes'] ) : sprintf( __( 'Play the recording (%d min)', 'olivia-studio' ), $r['minutes'] ) ) . '</button> ';
			}
			$days = (int) OYS_Settings::get( 'live_replay_days' );
			$out .= '</p>' . ( $who['owner'] ? '' : '<p class="oys-small">' . esc_html( sprintf( __( 'Available until %s.', 'olivia-studio' ), oys_date( gmdate( 'Y-m-d H:i:s', oys_ts( $session->ends_at ) + $days * DAY_IN_SECONDS ), 'M j' ) ) ) . '</p>' ) . '</div>';
		} elseif ( $row && 'room' === $row->state && self::records( $session ) ) {
			$out .= '<div class="oys-card"><p>' . esc_html__( 'The recording will be here shortly after the class.', 'olivia-studio' ) . '</p></div>';
		}
		return '<div class="oys-live__after"><p class="oys-live__status">' . esc_html__( 'The class is over. Thank you for practising together!', 'olivia-studio' ) . '</p>' . $out . '</div>';
	}

	/** For the admin's class box: state of the room, recording and summary. */
	public static function admin_status( $session ) {
		$row = self::row( $session->id );
		if ( ! $row || ! $row->room_name ) {
			return __( 'The live room is created when the first person books online, or a day before the class.', 'olivia-studio' );
		}
		$recs = (array) json_decode( (string) $row->recordings, true );
		$bits = array( __( 'Live room ready.', 'olivia-studio' ) );
		if ( 'done' === $row->state || $recs ) {
			$bits[] = $recs ? sprintf( _n( '%d recording.', '%d recordings.', count( $recs ), 'olivia-studio' ), count( $recs ) ) : __( 'No recording.', 'olivia-studio' );
			$bits[] = '' !== (string) $row->summary ? ( 'published' === $row->summary_status ? __( 'Summary shown to the participants.', 'olivia-studio' ) : __( 'Summary waiting for you.', 'olivia-studio' ) ) : '';
		}
		return implode( ' ', array_filter( $bits ) );
	}
}
