<?php
/**
 * HathaFlow Live – live online classes on the site itself, tied to the booking (HathaFlow Platform Plan, Oct 2026,
 * „HathaFlow Live”, pp. 11–13; Áron, 2026-11-12: „nincs Zoom … a Hathaflow-nak a rendszerére hagyatkozz”).
 * The engine (this plugin) carries it, so Olivia's site and every HathaFlow teacher get the same thing.
 *
 *  - Video: LiveKit (WebRTC, two-way; the picture reaches viewers over the provider's network, quality adapts to each
 *    viewer). Rooms are made by LiveKit when the first person joins; the server only signs entry tokens (JWT).
 *    Keys: OYS_LIVEKIT_URL (wss://…), OYS_LIVEKIT_API_KEY, OYS_LIVEKIT_API_SECRET (or Settings → Live online).
 *  - Only those who booked get in: their own signed link (account or email), nothing to download, the room opens
 *    `live_open_minutes` (10) before the class. The teacher (or the studio) presses one button: „Go live”.
 *  - Small classes (online seats up to `live_grid_max`) and private sessions: students may turn their camera on, and
 *    everyone sees everyone in a grid (Olívia, 14 November: „élőben láthassuk egymást”). Bigger classes: only the
 *    teacher is on screen. Chat, raise hand, one-tap mute; the
 *    teacher can mute everyone.
 *  - Chat: ours (table oys_live_chat), it stays after class (`live_chat_days`).
 *  - Recording: the teacher only, recorded in her own browser from her own camera and microphone (nobody else is on
 *    it), uploaded in pieces while she teaches, kept on this server. The participants can watch it for
 *    `live_replay_days`; the files are deleted after `live_keep_days`. `live_record`: group | all | none.
 *  - Opening words: the teacher's first `live_intro_minutes` are written down in her browser (speech recognition in
 *    the class's language); after class the AI writes a short summary, not word for word (`live_summary`).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Live {

	const SEEN = array( 'confirmed', 'attended', 'no_show' );

	public static function init() {
		add_filter( 'oys_join_link', array( __CLASS__, 'join_link' ), 5, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'oys_session_saved', array( __CLASS__, 'on_session_saved' ), 10, 2 );
		add_action( 'oys_hourly', array( __CLASS__, 'clean_up' ) );
		add_action( 'oys_frequent', array( __CLASS__, 'process' ) );
		add_action( 'init', array( __CLASS__, 'maybe_stream_replay' ), 20 );
	}

	/* ---------- Configuration ---------- */

	private static function conf( $key ) {
		$const = 'OYS_LIVEKIT_' . strtoupper( $key );
		return trim( defined( $const ) ? (string) constant( $const ) : (string) OYS_Settings::get( 'livekit_' . $key ) );
	}

	/** wss://<project>.livekit.cloud – where the browsers connect. */
	public static function ws_url() {
		$url = self::conf( 'url' );
		return '' === $url ? '' : preg_replace( '#^http(s?)://#', 'ws$1://', $url );
	}

	/** https://… – the same server's API (Twirp). */
	public static function api_base() {
		return defined( 'OYS_LIVEKIT_API_BASE' ) ? OYS_LIVEKIT_API_BASE : preg_replace( '#^ws(s?)://#', 'http$1://', self::ws_url() );
	}

	public static function configured() {
		return '' !== self::ws_url() && '' !== self::conf( 'api_key' ) && '' !== self::conf( 'api_secret' );
	}

	/** Our live classes are set up and on: online classes run in them (and Zoom stays out). */
	public static function enabled() {
		return self::configured() && (int) OYS_Settings::get( 'live_on' );
	}

	/** Does this class run in our live room? (Online or hybrid, scheduled, no link typed in by hand.) */
	public static function uses_live( $session ) {
		return self::enabled() && $session && oys_has_online( $session ) && in_array( $session->status, array( 'scheduled', 'completed' ), true ) && '' === (string) $session->online_url;
	}

	/** Is the teacher recorded in this class? Group classes and events by default, private sessions only with "all". */
	public static function records( $session ) {
		$mode = (string) OYS_Settings::get( 'live_record' );
		return 'all' === $mode || ( 'group' === $mode && 'private' !== $session->kind );
	}

	/**
	 * Days a booked class can be watched again (0: not recorded, or not in our live room). Olívia, 14 November: „Az, hogy az
	 * óra visszanézhető még 1 hétig, jelenjen meg több helyen is kiemelve!” – on the booking page and in the emails.
	 */
	public static function replay_days( $session ) {
		if ( ! $session || ! oys_has_online( $session ) || '' !== (string) $session->online_url || ! self::records( $session ) ) {
			return 0;
		}
		return max( 0, (int) OYS_Settings::get( 'live_replay_days' ) );
	}

	/** „You can watch this class again for a week …” for people booking it; '' when it can't be watched again. */
	public static function replay_text( $session ) {
		$days = self::replay_days( $session );
		if ( ! $days ) {
			return '';
		}
		return 7 === $days
			? __( 'You can watch this class again for a week: the recording (only the teacher is on it) waits in your account.', 'olivia-studio' )
			: sprintf( _n( 'You can watch this class again for %d day: the recording (only the teacher is on it) waits in your account.', 'You can watch this class again for %d days: the recording (only the teacher is on it) waits in your account.', $days, 'olivia-studio' ), $days );
	}

	/** The opening words are summarised in group classes and events (never in private sessions). */
	public static function summarises( $session ) {
		return 'off' !== (string) OYS_Settings::get( 'live_summary' ) && 'private' !== $session->kind && (int) OYS_Settings::get( 'live_intro_minutes' ) > 0;
	}

	/** Students may send video and sound: private sessions, and group classes up to `live_grid_max` online seats. */
	public static function two_way( $session ) {
		if ( 'private' === $session->kind ) {
			return true;
		}
		$seats = oys_is_online( $session ) ? (int) $session->capacity : (int) $session->online_capacity;
		return $seats > 0 && $seats <= (int) OYS_Settings::get( 'live_grid_max' );
	}

	/** Language of the class (speech recognition, summary): the class's own, else the site's. */
	public static function lang( $session ) {
		$langs = array_keys( OYS_I18n::languages() );
		return count( $langs ) > 1 ? oys_valid_lang( $session->lang ?? 'en' ) : oys_valid_lang( $langs[0] ?? 'en' );
	}

	/* ---------- The test class (Áron, 2026-11-13: „egy teszt jóga órát, amit bármikor meg tudunk nyitni”) ---------- */

	const TEST_OPTION = 'oys_live_test_session';
	const TEST_BASE   = 4000000000; // the test participants' links: booking ids nobody has (TEST_BASE + 1 …)
	const TEST_PEOPLE = 3;

	public static function is_test( $session ) {
		return $session && 'test' === $session->kind;
	}

	/** The hidden test class (kind „test”: not on the timetable, not bookable), made the first time. */
	public static function test_session() {
		$s = OYS_Schedule::get( (int) get_option( self::TEST_OPTION ) );
		if ( $s && self::is_test( $s ) ) {
			return $s;
		}
		$now = time();
		$id  = OYS_Schedule::save( array(
			'kind'        => 'test',
			'class_slug'  => '',
			'title'       => __( 'Test class', 'olivia-studio' ),
			'starts_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'ends_at'     => gmdate( 'Y-m-d H:i:s', $now + 2 * HOUR_IN_SECONDS ),
			'capacity'    => 10,
			'format'      => 'online',
			'location'    => 'Online',
			'price_cents' => 0,
			'status'      => 'scheduled',
			'lang'        => oys_valid_lang( array_keys( OYS_I18n::languages() )[0] ?? 'en' ),
		) );
		update_option( self::TEST_OPTION, (int) $id, false );
		return OYS_Schedule::get( (int) $id );
	}

	/** Opens the test class now, for two hours, fresh: the earlier test's chat, recordings and summary go. */
	public static function open_test() {
		$s   = self::test_session();
		$now = time();
		OYS_Schedule::save( array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $now ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $now + 2 * HOUR_IN_SECONDS ), 'status' => 'scheduled' ), $s->id );
		global $wpdb;
		$row = self::row( $s->id );
		if ( $row ) {
			foreach ( self::recordings( $row ) as $r ) {
				if ( ! empty( $r['file'] ) && is_file( self::rec_dir() . '/' . $r['file'] ) ) {
					wp_delete_file( self::rec_dir() . '/' . $r['file'] );
				}
			}
			$wpdb->delete( self::table(), array( 'session_id' => (int) $s->id ) );
		}
		$wpdb->delete( self::table( 'live_chat' ), array( 'session_id' => (int) $s->id ) );
		delete_transient( self::hands_key( $s->id ) );
		return OYS_Schedule::get( $s->id );
	}

	/** The links of the test class: the teacher's and one for each test participant (no login needed). */
	public static function test_links() {
		$s     = self::test_session();
		$links = array( 'teacher' => self::url( $s->id ), 'people' => array() );
		for ( $n = 1; $n <= self::TEST_PEOPLE; $n++ ) {
			$links['people'][ $n ] = self::url( $s->id, self::TEST_BASE + $n );
		}
		return $links;
	}

	/* ---------- LiveKit: tokens and the server API ---------- */

	private static function b64( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** A LiveKit access token (JWT, HS256). $grant: the „video” grant (room, roomJoin, canPublish …). */
	public static function token( $identity, $name, array $grant, $ttl = 600, $metadata = '' ) {
		$now    = time();
		$claims = array_filter( array(
			'iss'      => self::conf( 'api_key' ),
			'sub'      => (string) $identity,
			'name'     => (string) $name,
			'nbf'      => $now - 10,
			'exp'      => $now + max( 60, (int) $ttl ),
			'jti'      => (string) $identity . '-' . $now,
			'video'    => $grant,
			'metadata' => (string) $metadata,
		), fn( $v ) => '' !== $v && array() !== $v );
		$seg = self::b64( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) ) . '.' . self::b64( wp_json_encode( $claims ) );
		return $seg . '.' . self::b64( hash_hmac( 'sha256', $seg, self::conf( 'api_secret' ), true ) );
	}

	/** RoomService call (Twirp, JSON). @return array|WP_Error */
	public static function api( $method, array $body, array $grant = array() ) {
		$auth = self::token( 'oys-server', '', $grant ?: array( 'roomCreate' => true, 'roomList' => true, 'roomAdmin' => true, 'room' => (string) ( $body['room'] ?? '' ) ), 300 );
		$res  = wp_remote_post( rtrim( self::api_base(), '/' ) . '/twirp/livekit.RoomService/' . $method, array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $auth, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( (object) $body ),
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'oys_live', sprintf( __( 'Could not reach the live video service: %s', 'olivia-studio' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$info = is_array( $data ) ? (string) ( $data['msg'] ?? ( $data['code'] ?? '' ) ) : '';
			oys_log( 'LiveKit request failed', array( 'method' => $method, 'status' => $code, 'info' => $info ) );
			return new WP_Error( 'oys_live', sprintf( __( 'The live video service answered with an error (%1$d). %2$s', 'olivia-studio' ), $code, $info ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function test_connection() {
		$res = self::api( 'ListRooms', array(), array( 'roomList' => true ) );
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

	public static function room_name( $session_id ) {
		return 'oys-' . substr( md5( home_url() ), 0, 8 ) . '-' . (int) $session_id;
	}

	/** When the room opens (minutes before the start) and closes (after the end). */
	private static function window( $session ) {
		return array(
			oys_ts( $session->starts_at ) - max( 5, (int) OYS_Settings::get( 'live_open_minutes' ) ) * MINUTE_IN_SECONDS,
			oys_ts( $session->ends_at ) + 30 * MINUTE_IN_SECONDS,
		);
	}

	/** The class's row (room, recordings, opening words), made the first time someone joins. */
	public static function ensure_row( $session ) {
		$row = self::row( $session->id );
		if ( $row && $row->room_name ) {
			return $row;
		}
		global $wpdb;
		$data = array( 'session_id' => (int) $session->id, 'room_name' => self::room_name( $session->id ), 'room_url' => self::ws_url(), 'room_id' => '', 'state' => 'room', 'created_at' => oys_now() );
		$row ? $wpdb->update( self::table(), $data, array( 'session_id' => (int) $session->id ) ) : $wpdb->insert( self::table(), $data );
		return self::row( $session->id );
	}

	/** Cancelled or no longer online: the room closes (anyone still in it leaves). */
	public static function on_session_saved( $session_id, $before = null ) {
		$row = self::row( $session_id );
		if ( ! $row || ! $row->room_name || ! self::configured() ) {
			return;
		}
		$s = OYS_Schedule::get( $session_id );
		if ( ! $s || 'cancelled' === $s->status || ! oys_has_online( $s ) || '' !== (string) $s->online_url ) {
			self::api( 'DeleteRoom', array( 'room' => $row->room_name ) );
			global $wpdb;
			$wpdb->update( self::table(), array( 'state' => 'closed' ), array( 'session_id' => (int) $session_id ) );
		}
	}

	/* ---------- Who is it ---------- */

	/** The signature of a participant's own link (their booking). */
	public static function sign( $session_id, $booking_id ) {
		return oys_sig( 'live|' . (int) $session_id . '|' . (int) $booking_id, 24 );
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

	/** Teacher of the class, or the studio: joins as the teacher (goes live, records, mutes everyone). */
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

	/** Who is asking: [ owner, booking, user_id, name ] or null. $b / $k = the signed booking of a participant's own link. */
	public static function who( $session, $b = 0, $k = '' ) {
		if ( ! $session ) {
			return null;
		}
		$user_id = get_current_user_id();
		$n       = (int) $b - self::TEST_BASE;
		if ( self::is_test( $session ) && $n >= 1 && $n <= self::TEST_PEOPLE && $k && hash_equals( self::sign( $session->id, $b ), (string) $k ) ) {
			// a test participant: a booking that isn't in the database (nothing is booked, nothing paid, no emails)
			$booking = (object) array( 'id' => (int) $b, 'session_id' => (int) $session->id, 'user_id' => 0, 'status' => 'confirmed', 'mode' => 'online', 'guest_of' => 0, 'guest_name' => '' );
			/* translators: %d: number of the test participant (1–3) */
			return array( 'owner' => false, 'booking' => $booking, 'user_id' => 0, 'name' => sprintf( __( 'Test participant %d', 'olivia-studio' ), $n ), 'test' => true );
		}
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

	/** before | open | over, for joining the video. The teacher can come in an hour early. */
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

	/** @return array|WP_Error what the live page needs to connect: the server, a token, and the teacher's tasks */
	public static function join( $session, array $who ) {
		if ( 'open' !== self::phase( $session, $who['owner'] ) ) {
			return new WP_Error( 'oys_live', 'over' === self::phase( $session ) ? __( 'This class is over.', 'olivia-studio' ) : __( 'The room opens shortly before the class.', 'olivia-studio' ) );
		}
		$row = self::ensure_row( $session );
		list( , $closes ) = self::window( $session );
		$two      = self::two_way( $session );
		$identity = $who['owner'] ? 'teacher-' . (int) $who['user_id'] : 'b' . (int) $who['booking']->id;
		$grant    = array( 'room' => $row->room_name, 'roomJoin' => true, 'canSubscribe' => true, 'canPublishData' => true );
		if ( $who['owner'] ) {
			$grant['canPublish'] = true;
		} else {
			$grant['canPublish'] = $two;
			if ( $two ) {
				$grant['canPublishSources'] = array( 'camera', 'microphone' );
			}
		}
		$lang = self::lang( $session );
		return array(
			'url'    => self::ws_url(),
			'token'  => self::token( $identity, mb_substr( $who['name'], 0, 60 ), $grant, $closes - time(), wp_json_encode( array( 'role' => $who['owner'] ? 'teacher' : 'student' ) ) ),
			'me'     => $identity,
			'owner'  => (bool) $who['owner'],
			'twoWay' => $two,
			'record' => $who['owner'] && self::records( $session ),
			'intro'  => $who['owner'] && self::summarises( $session ) ? array(
				'start'   => oys_ts( $session->starts_at ) * 1000,
				'minutes' => (int) OYS_Settings::get( 'live_intro_minutes' ),
				'lang'    => OYS_I18n::LANGS[ $lang ]['html'] ?? 'en-US',
			) : null,
			'start'  => oys_ts( $session->starts_at ) * 1000,
		);
	}

	/** The teacher mutes everyone's microphone. @return int|WP_Error how many were muted */
	public static function mute_all( $session ) {
		$row = self::row( $session->id );
		if ( ! $row ) {
			return 0;
		}
		$list = self::api( 'ListParticipants', array( 'room' => $row->room_name ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$n = 0;
		foreach ( (array) ( $list['participants'] ?? array() ) as $p ) {
			if ( str_starts_with( (string) ( $p['identity'] ?? '' ), 'teacher-' ) ) {
				continue;
			}
			foreach ( (array) ( $p['tracks'] ?? array() ) as $t ) {
				$audio = 'AUDIO' === ( $t['type'] ?? '' ) || 1 === ( $t['type'] ?? null ) || 'MICROPHONE' === ( $t['source'] ?? '' );
				if ( $audio && empty( $t['muted'] ) ) {
					$res = self::api( 'MutePublishedTrack', array( 'room' => $row->room_name, 'identity' => $p['identity'], 'track_sid' => $t['sid'], 'muted' => true ) );
					$n  += is_wp_error( $res ) ? 0 : 1;
				}
			}
		}
		return $n;
	}

	/* ---------- Raised hands (through this server, like the chat: works on every network) ---------- */

	private static function hands_key( $session_id ) {
		return 'oys_live_hands_' . (int) $session_id;
	}

	/** Raised hands of a class: [ identity => name ]. */
	public static function hands( $session_id ) {
		$h = get_transient( self::hands_key( $session_id ) );
		return is_array( $h ) ? $h : array();
	}

	/** A participant raises or lowers their hand; the teacher lowers anyone's ($identity). */
	public static function set_hand( $session, array $who, $up, $identity = '' ) {
		$h  = self::hands( $session->id );
		$id = $who['owner'] ? sanitize_key( $identity ) : 'b' . (int) $who['booking']->id;
		if ( '' === $id ) {
			return $h;
		}
		if ( $up && ! $who['owner'] ) {
			$h[ $id ] = $who['name'];
		} else {
			unset( $h[ $id ] );
		}
		set_transient( self::hands_key( $session->id ), $h, 3 * HOUR_IN_SECONDS );
		return $h;
	}

	/* ---------- Recording: the teacher's own camera, uploaded in pieces ---------- */

	/** The folder of the recordings (inside uploads, with a name nobody can guess; served only through replay()). */
	public static function rec_dir() {
		$dir = wp_upload_dir( null, false )['basedir'] . '/oys-live-' . substr( hash_hmac( 'sha256', 'oys-live-recordings', wp_salt( 'auth' ) ), 0, 20 );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence." );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	public static function recordings( $row ) {
		return $row ? array_values( array_filter( (array) json_decode( (string) $row->recordings, true ), 'is_array' ) ) : array();
	}

	private static function save_recordings( $session_id, array $recs ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'recordings' => wp_json_encode( array_values( $recs ) ) ), array( 'session_id' => (int) $session_id ) );
	}

	/** A new recording (the teacher's browser starts one when the class starts). @return array|WP_Error */
	public static function rec_start( $session, $mime ) {
		if ( ! self::records( $session ) ) {
			return new WP_Error( 'oys_live', __( 'This class is not recorded.', 'olivia-studio' ) );
		}
		$mime = preg_match( '#^video/(webm|mp4)\b#', (string) $mime, $m ) ? $m[0] : 'video/webm';
		$row  = self::ensure_row( $session );
		$recs = self::recordings( $row );
		$id   = strtolower( wp_generate_password( 12, false, false ) );
		$recs[] = array( 'id' => $id, 'file' => $id . '-' . strtolower( wp_generate_password( 16, false, false ) ) . ( 'video/mp4' === $mime ? '.mp4' : '.webm' ), 'mime' => $mime, 'status' => 'recording', 'seq' => 0, 'bytes' => 0, 'started' => time(), 'last' => time(), 'minutes' => 0 );
		self::save_recordings( $session->id, $recs );
		return array( 'id' => $id );
	}

	/** One piece of a recording, in order. @return array|WP_Error [ seq => the next one expected ] */
	public static function rec_chunk( $session, $rec_id, $seq, $data ) {
		$row  = self::row( $session->id );
		$recs = self::recordings( $row );
		foreach ( $recs as $i => $r ) {
			if ( $r['id'] !== $rec_id ) {
				continue;
			}
			if ( 'recording' !== $r['status'] ) {
				return new WP_Error( 'oys_live', __( 'This recording is closed.', 'olivia-studio' ) );
			}
			if ( (int) $seq < (int) $r['seq'] ) {
				return array( 'seq' => (int) $r['seq'] ); // sent twice (a retry): already here
			}
			if ( (int) $seq > (int) $r['seq'] ) {
				return new WP_Error( 'oys_live_seq', __( 'A piece of the recording is missing.', 'olivia-studio' ), array( 'status' => 409, 'seq' => (int) $r['seq'] ) );
			}
			$len = strlen( (string) $data );
			if ( ! $len || $len > 16 * MB_IN_BYTES ) {
				return new WP_Error( 'oys_live', __( 'This piece of the recording is empty or too big.', 'olivia-studio' ) );
			}
			if ( false === file_put_contents( self::rec_dir() . '/' . $r['file'], $data, FILE_APPEND | LOCK_EX ) ) {
				return new WP_Error( 'oys_live', __( 'The recording could not be saved on the server.', 'olivia-studio' ), array( 'status' => 500 ) );
			}
			$recs[ $i ]['seq']     = (int) $r['seq'] + 1;
			$recs[ $i ]['bytes']   = (int) $r['bytes'] + $len;
			$recs[ $i ]['last']    = time();
			$recs[ $i ]['minutes'] = max( 1, (int) round( ( time() - (int) $r['started'] ) / 60 ) );
			self::save_recordings( $session->id, $recs );
			return array( 'seq' => $recs[ $i ]['seq'] );
		}
		return new WP_Error( 'oys_live', __( 'Recording not found.', 'olivia-studio' ), array( 'status' => 404 ) );
	}

	/** The teacher stops a recording (or the class is over): it can be watched. */
	public static function rec_stop( $session, $rec_id, $seconds = 0 ) {
		$recs = self::recordings( self::row( $session->id ) );
		foreach ( $recs as $i => $r ) {
			if ( $r['id'] === $rec_id && 'recording' === $r['status'] ) {
				$recs[ $i ]['status']  = (int) $r['bytes'] > 0 ? 'finished' : 'empty';
				$recs[ $i ]['minutes'] = max( 1, (int) round( ( (int) $seconds ?: (int) $r['last'] - (int) $r['started'] ) / 60 ) );
			}
		}
		self::save_recordings( $session->id, $recs );
		return true;
	}

	/** Recordings people can watch. */
	public static function watchable( $row ) {
		return array_values( array_filter( self::recordings( $row ), fn( $r ) => 'finished' === $r['status'] && is_file( self::rec_dir() . '/' . $r['file'] ) ) );
	}

	/**
	 * ?oys_replay=<class>&rec=<id> (+ b, k of the participant's own link): the recording, for the people who booked
	 * (for `live_replay_days`) and the teacher (also as a download: &dl=1). Seeking works (HTTP ranges).
	 */
	public static function maybe_stream_replay() {
		if ( empty( $_GET['oys_replay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$session = OYS_Schedule::get( absint( $_GET['oys_replay'] ) );
		$who     = $session && self::uses_live( $session ) ? self::who( $session, absint( $_GET['b'] ?? 0 ), sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) ) ) : null;
		$rec     = null;
		foreach ( $who ? self::watchable( self::row( $session->id ) ) : array() as $r ) {
			if ( $r['id'] === sanitize_key( $_GET['rec'] ?? '' ) ) {
				$rec = $r;
			}
		}
		if ( ! $rec || ! self::replay_open( $session, $who['owner'] ) ) {
			status_header( 404 );
			exit;
		}
		self::send_file( self::rec_dir() . '/' . $rec['file'], $rec['mime'], $who['owner'] && ! empty( $_GET['dl'] ) ? sanitize_title( oys_session_title( $session ) ) . '-' . wp_date( 'Y-m-d', oys_ts( $session->starts_at ) ) . ( 'video/mp4' === $rec['mime'] ? '.mp4' : '.webm' ) : '' );
		exit;
	}

	private static function send_file( $path, $mime, $download = '' ) {
		$size  = filesize( $path );
		$start = 0;
		$end   = $size - 1;
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $download ) {
			header( 'Content-Disposition: attachment; filename="' . $download . '"' );
		}
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m ) ) {
			$start = '' === $m[1] ? max( 0, $size - (int) $m[2] ) : (int) $m[1];
			$end   = '' === $m[1] || '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				return;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$fh = fopen( $path, 'rb' );
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$buf   = fread( $fh, (int) min( 1048576, $left ) );
			$left -= strlen( $buf );
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput -- video bytes
			flush();
		}
		fclose( $fh );
	}

	/** The address a recording is watched from. */
	public static function replay_url( $session_id, $rec_id, array $who, $download = false ) {
		$args = array( 'oys_replay' => (int) $session_id, 'rec' => $rec_id );
		if ( $who['booking'] ) {
			$args['b'] = (int) $who['booking']->id;
			$args['k'] = self::sign( $session_id, $who['booking']->id );
		}
		if ( $download ) {
			$args['dl'] = 1;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/* ---------- Opening words ---------- */

	/** A piece of the teacher's opening words, written down in her browser. */
	public static function add_transcript( $session, $text ) {
		$text = trim( sanitize_textarea_field( (string) $text ) );
		if ( '' === $text || time() > oys_ts( $session->starts_at ) + ( (int) OYS_Settings::get( 'live_intro_minutes' ) + 10 ) * MINUTE_IN_SECONDS ) {
			return false;
		}
		$row  = self::ensure_row( $session );
		$full = trim( (string) $row->transcript . ' ' . $text );
		global $wpdb;
		$wpdb->update( self::table(), array( 'transcript' => mb_substr( $full, 0, 20000 ) ), array( 'session_id' => (int) $session->id ) );
		return true;
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

	/* ---------- After class ---------- */

	/** Every 5 minutes: classes that ended 15 minutes ago get their recordings closed and the summary written. */
	public static function process() {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT l.* FROM ' . self::table() . ' l JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = l.session_id WHERE l.state = 'room' AND s.ends_at < %s ORDER BY s.ends_at ASC LIMIT 5",
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
		foreach ( self::recordings( $row ) as $r ) {
			if ( 'recording' === $r['status'] ) {
				self::rec_stop( $session, $r['id'] ); // the teacher closed the page without stopping
			}
		}
		$update = array( 'tries' => (int) $row->tries + 1, 'processed_at' => oys_now() );
		$wait   = false;
		if ( self::summarises( $session ) && '' === (string) $row->summary && '' !== trim( (string) $row->transcript ) ) {
			$sum = self::summarize( $session, (string) $row->transcript );
			if ( is_wp_error( $sum ) ) {
				$wait = OYS_AI::ready();
			} elseif ( '' !== $sum ) {
				$update['summary']        = $sum;
				$update['summary_status'] = 'review' === OYS_Settings::get( 'live_summary' ) ? 'draft' : 'published';
			}
		}
		$update['state'] = $wait && $update['tries'] < 24 ? 'room' : 'done';
		global $wpdb;
		$wpdb->update( self::table(), $update, array( 'session_id' => (int) $row->session_id ) );
	}

	/** Hourly: recordings older than `live_keep_days` are deleted from the server. */
	public static function clean_up() {
		global $wpdb;
		$days = max( 1, (int) OYS_Settings::get( 'live_keep_days' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT l.* FROM ' . self::table() . ' l JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = l.session_id WHERE l.recordings LIKE %s AND s.ends_at < %s LIMIT 50",
			'%"file"%', oys_utc_plus( -$days * DAY_IN_SECONDS )
		) );
		foreach ( $rows as $row ) {
			foreach ( self::recordings( $row ) as $r ) {
				if ( ! empty( $r['file'] ) && is_file( self::rec_dir() . '/' . $r['file'] ) ) {
					wp_delete_file( self::rec_dir() . '/' . $r['file'] );
				}
			}
			self::save_recordings( $row->session_id, array() );
		}
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
		$r( '/summary', 'POST', 'rest_summary' );
		$r( '/mute-all', 'POST', 'rest_mute_all' );
		$r( '/rec', 'POST', 'rest_rec_start' );
		$r( '/rec/(?P<rec>[a-z0-9]+)/chunk', 'POST', 'rest_rec_chunk' );
		$r( '/rec/(?P<rec>[a-z0-9]+)/stop', 'POST', 'rest_rec_stop' );
		$r( '/transcript', 'POST', 'rest_transcript' );
		$r( '/hand', 'POST', 'rest_hand' );
	}

	/** [ session, who ] of a request, or a WP_Error. $owner: only the teacher. */
	private static function req( WP_REST_Request $req, $owner = false ) {
		$session = OYS_Schedule::get( (int) $req['id'] );
		$who     = $session && self::uses_live( $session ) ? self::who( $session, (int) $req->get_param( 'b' ), (string) $req->get_param( 'k' ) ) : null;
		if ( ! $who ) {
			return new WP_Error( 'oys_live', __( 'This live class is not in your bookings.', 'olivia-studio' ), array( 'status' => 403 ) );
		}
		if ( $owner && ! $who['owner'] ) {
			return new WP_Error( 'oys_live', __( 'Only the teacher can change this.', 'olivia-studio' ), array( 'status' => 403 ) );
		}
		return array( $session, $who );
	}

	private static function fail( WP_Error $e, $status = 400 ) {
		$data = (array) $e->get_error_data();
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array_merge( $data, array( 'status' => $data['status'] ?? $status ) ) );
	}

	public static function rest_join( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = self::join( $r[0], $r[1] );
		return is_wp_error( $out ) ? self::fail( $out, 409 ) : rest_ensure_response( $out );
	}

	public static function rest_mute_all( WP_REST_Request $req ) {
		$r = self::req( $req, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$n = self::mute_all( $r[0] );
		return is_wp_error( $n ) ? self::fail( $n, 502 ) : rest_ensure_response( array( 'muted' => $n ) );
	}

	public static function rest_rec_start( WP_REST_Request $req ) {
		$r = self::req( $req, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = self::rec_start( $r[0], (string) $req->get_param( 'mime' ) );
		return is_wp_error( $out ) ? self::fail( $out ) : rest_ensure_response( $out );
	}

	public static function rest_rec_chunk( WP_REST_Request $req ) {
		$r = self::req( $req, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = self::rec_chunk( $r[0], (string) $req['rec'], (int) $req->get_param( 'seq' ), $req->get_body() );
		return is_wp_error( $out ) ? self::fail( $out ) : rest_ensure_response( $out );
	}

	public static function rest_rec_stop( WP_REST_Request $req ) {
		$r = self::req( $req, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::rec_stop( $r[0], (string) $req['rec'], (int) $req->get_param( 'seconds' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_hand( WP_REST_Request $req ) {
		$r = self::req( $req );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'hands' => array_keys( self::set_hand( $r[0], $r[1], (bool) $req->get_param( 'up' ), (string) $req->get_param( 'identity' ) ) ) ) );
	}

	public static function rest_transcript( WP_REST_Request $req ) {
		$r = self::req( $req, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'ok' => self::add_transcript( $r[0], (string) $req->get_param( 'text' ) ) ) );
	}

	/** The teacher edits, publishes or hides the summary of the opening words. */
	public static function rest_summary( WP_REST_Request $req ) {
		$r = self::req( $req, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$status = in_array( $req->get_param( 'status' ), array( 'published', 'hidden', 'draft' ), true ) ? $req->get_param( 'status' ) : 'published';
		self::ensure_row( $r[0] );
		global $wpdb;
		$wpdb->update( self::table(), array( 'summary' => sanitize_textarea_field( (string) $req->get_param( 'summary' ) ), 'summary_status' => $status ), array( 'session_id' => (int) $r[0]->id ) );
		return rest_ensure_response( array( 'ok' => true ) );
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
			'hands'    => $who['owner'] ? (object) self::hands( $session->id ) : null,
			'hand'     => $who['owner'] ? null : isset( self::hands( $session->id )[ 'b' . (int) $who['booking']->id ] ),
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
			'lk'       => OYS_URL . 'assets/vendor/livekit-client.umd.js?ver=2.23.0',
			'phase'    => $phase,
			'owner'    => $who['owner'],
			'opens'    => self::window( $session )[0] * 1000,
			'chatOpen' => $who['owner'] || self::chat_open( $session ),
			'i18n'     => array(
				'join'      => $who['owner'] ? __( 'Go live', 'olivia-studio' ) : __( 'Join the class', 'olivia-studio' ),
				'joining'   => __( 'Joining…', 'olivia-studio' ),
				'left'      => __( 'You left the class. You can join again.', 'olivia-studio' ),
				'send'      => __( 'Send', 'olivia-studio' ),
				'remove'    => __( 'Remove', 'olivia-studio' ),
				'teacher'   => __( 'teacher', 'olivia-studio' ),
				'empty'     => __( 'No messages yet. Say hello!', 'olivia-studio' ),
				'error'     => __( 'Something went wrong. Try again in a moment.', 'olivia-studio' ),
				'recOn'     => __( 'Recording (only you are on it)', 'olivia-studio' ),
				'recUp'     => __( 'Saving the recording…', 'olivia-studio' ),
				'micOn'     => __( 'Mute', 'olivia-studio' ),
				'micOff'    => __( 'Unmute', 'olivia-studio' ),
				'camOn'     => __( 'Camera off', 'olivia-studio' ),
				'camOff'    => __( 'Camera on', 'olivia-studio' ),
				'hand'      => __( 'Raise hand', 'olivia-studio' ),
				'handDown'  => __( 'Lower hand', 'olivia-studio' ),
				'handLower' => __( 'Lower the hand', 'olivia-studio' ),
				'handUp'    => __( '%s raised a hand', 'olivia-studio' ),
				'muteAll'   => __( 'Mute everyone', 'olivia-studio' ),
				'mutedAll'  => __( 'Everyone is muted.', 'olivia-studio' ),
				'leave'     => __( 'Leave', 'olivia-studio' ),
				'waiting'   => __( 'The teacher will be here in a moment.', 'olivia-studio' ),
				'you'       => __( 'You', 'olivia-studio' ),
				'people'    => __( 'In the class: %d', 'olivia-studio' ),
				'reconnect' => __( 'Connection lost, reconnecting…', 'olivia-studio' ),
				'noDevice'  => __( 'Your camera or microphone could not be started. Check that the browser may use them.', 'olivia-studio' ),
				'listening' => __( 'Your opening words are being written down.', 'olivia-studio' ),
				'recOff'    => __( 'Not recording', 'olivia-studio' ),
				'recStart'  => __( 'Start recording', 'olivia-studio' ),
				'recStop'   => __( 'Stop recording', 'olivia-studio' ),
				'recSoon'   => __( 'The recording starts with the class.', 'olivia-studio' ),
				'saved'     => __( 'Saved.', 'olivia-studio' ),
				'opensIn'   => __( 'The room opens in %s.', 'olivia-studio' ),
				'min'       => __( 'min', 'olivia-studio' ),
				'openNow'   => __( 'The room is open.', 'olivia-studio' ),
				'full'      => __( 'Full screen', 'olivia-studio' ),
				'fullExit'  => __( 'Exit full screen', 'olivia-studio' ),
				'blocked'   => __( 'This website does not allow the camera and microphone (the server sends a Permissions-Policy header that turns them off). The browser can\'t even ask. The site\'s host has to allow them: camera=(self), microphone=(self).', 'olivia-studio' ),
				'denied'    => __( 'The browser does not allow the camera or microphone. Click the icon left of the address bar, set Camera and Microphone to Allow, then reload the page.', 'olivia-studio' ),
				'notFound'  => __( 'No camera or microphone was found. Plug one in, or check that it is switched on.', 'olivia-studio' ),
				'inUse'     => __( 'Another program is using the camera (e.g. Zoom, Teams, FaceTime). Close it, then try again.', 'olivia-studio' ),
				'recNoAv'   => __( 'Nothing to record yet: turn on your camera or microphone.', 'olivia-studio' ),
				'recNoSup'  => __( 'This browser can\'t record. Use Chrome, Edge or Safari on a computer.', 'olivia-studio' ),
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
			$out .= '<div class="oys-live__video" data-live-video hidden><div class="oys-live__main" data-live-main></div><div class="oys-live__tiles" data-live-tiles></div>'
				. '<p class="oys-live__note" data-live-note hidden></p><div class="oys-live__bar" data-live-bar></div></div>'
				. '<div class="oys-live__lobby" data-live-lobby><p class="oys-live__status" data-live-status>'
				. ( 'open' === $phase ? esc_html__( 'The room is open.', 'olivia-studio' ) : esc_html( sprintf( __( 'The room opens %d minutes before the class.', 'olivia-studio' ), max( 5, (int) OYS_Settings::get( 'live_open_minutes' ) ) ) ) ) . '</p>'
				. '<button type="button" class="btn btn--primary" data-live-join' . ( 'open' === $phase ? '' : ' disabled' ) . '>' . esc_html( $who['owner'] ? __( 'Go live', 'olivia-studio' ) : __( 'Join the class', 'olivia-studio' ) ) . '</button>'
				. '<ul class="oys-live__tips"><li>' . esc_html__( 'In your browser, no app needed. On a phone or tablet, open this page in Chrome or Safari.', 'olivia-studio' ) . '</li>'
				. ( $who['owner'] ? '' : '<li>' . esc_html__( 'Charge your device or plug it in, so it does not run out during the class.', 'olivia-studio' ) . '</li>' ) // Olívia, 14 November: „ne felejtse el feltölteni az eszközét”
				. '<li>' . esc_html( $who['owner'] || self::two_way( $session ) ? __( 'Allow the camera and the microphone when your browser asks. You can turn both off in the room.', 'olivia-studio' ) : sprintf( __( 'You see and hear %s; your camera and microphone stay off. Questions go in the chat.', 'olivia-studio' ), $teacher ) ) . '</li>'
				. ( self::records( $session ) ? '<li>' . esc_html( sprintf( __( 'The class is recorded, but only %s is on the recording: you are not.', 'olivia-studio' ), $teacher ) ) . '</li>' : '' )
				. '</ul></div>'
				. ( $who['owner'] ? '<div class="oys-live__owner" data-live-owner hidden><span data-live-rec-state></span> <button type="button" class="btn btn--ghost btn--sm" data-live-rec hidden></button> <span class="oys-small" data-live-intro></span></div>' : '' );
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
		$recs = self::watchable( $row );
		if ( $recs && self::replay_open( $session, $who['owner'] ) ) {
			$out .= '<div class="oys-live__replay oys-card"><h3>' . esc_html__( 'Watch the class again', 'olivia-studio' ) . '</h3><div data-live-player></div><p>';
			foreach ( $recs as $i => $r ) {
				$out .= '<button type="button" class="btn btn--primary btn--sm" data-live-replay="' . esc_url( self::replay_url( $session->id, $r['id'], $who ) ) . '" data-mime="' . esc_attr( $r['mime'] ) . '">' . esc_html( count( $recs ) > 1 ? sprintf( __( 'Part %1$d (%2$d min)', 'olivia-studio' ), $i + 1, $r['minutes'] ) : sprintf( __( 'Play the recording (%d min)', 'olivia-studio' ), $r['minutes'] ) ) . '</button> ';
				if ( $who['owner'] ) {
					$out .= '<a class="btn btn--ghost btn--sm" href="' . esc_url( self::replay_url( $session->id, $r['id'], $who, true ) ) . '">' . esc_html__( 'Download', 'olivia-studio' ) . '</a> ';
				}
			}
			$days = (int) OYS_Settings::get( 'live_replay_days' );
			$out .= '</p>' . ( $who['owner']
				? '<p class="oys-small">' . esc_html( sprintf( __( 'Participants can watch it for %1$d days; it is deleted from the server after %2$d days.', 'olivia-studio' ), $days, (int) OYS_Settings::get( 'live_keep_days' ) ) ) . '</p>'
				: '<p class="oys-small">' . esc_html( sprintf( __( 'Available until %s.', 'olivia-studio' ), oys_date( gmdate( 'Y-m-d H:i:s', oys_ts( $session->ends_at ) + $days * DAY_IN_SECONDS ), 'M j' ) ) ) . '</p>' ) . '</div>';
		}
		return '<div class="oys-live__after"><p class="oys-live__status">' . esc_html__( 'The class is over. Thank you for practising together!', 'olivia-studio' ) . '</p>' . $out . '</div>';
	}

	/** For the admin's class box: recordings and summary. */
	public static function admin_status( $session ) {
		$row = self::row( $session->id );
		if ( ! $row ) {
			return __( 'Open the live room a few minutes before the class and press „Go live”.', 'olivia-studio' );
		}
		$recs   = self::watchable( $row );
		$bits   = array( $recs ? sprintf( _n( '%d recording.', '%d recordings.', count( $recs ), 'olivia-studio' ), count( $recs ) ) : '' );
		$bits[] = '' !== (string) $row->summary ? ( 'published' === $row->summary_status ? __( 'Summary shown to the participants.', 'olivia-studio' ) : __( 'Summary waiting for you.', 'olivia-studio' ) ) : '';
		return implode( ' ', array_filter( $bits ) );
	}
}
