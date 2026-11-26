<?php
/**
 * The live class test lab (Áron, 2026-11-26: „A LiveKit-re szeretnék egy teljes környezetet, tesztkörnyezetet, hogy tudjunk
 * mindent tesztelni, éles kulcsokkal, mindennel együtt. Minden funkció, ami bent van, szeretnék tesztelni.”).
 *
 * Studio → Live class test. It runs on the site's own LiveKit project (the real keys), in rooms nobody can book or see:
 *  - an automatic check: the keys, the LiveKit server (a room made and deleted), the entry tokens, the chat, raised hands,
 *    the recording storage, the PHP limits, the camera/microphone header of the live page, the background jobs, the AI;
 *  - three test rooms that behave like the real classes: a small group (everyone may turn their camera on, recorded), a big
 *    group (only the teacher on screen, recorded) and a one-to-one session (two-way, not recorded, no summary). Each can be
 *    put into any moment of a class: open now, starting in 3 minutes (the recording starts by itself), the waiting room,
 *    over (the replay and the summary), or started afresh;
 *  - the teacher's link and four participants' links (no login, a QR code for phones), sent by email on request;
 *  - who is in the room right now, with their camera and microphone (from LiveKit itself), muting and removing people;
 *  - „Check this device” on every phone and computer (LiveKit's own connection test: server, WebRTC, TURN, reconnecting,
 *    sending sound and picture, UDP/TCP), the results collected here;
 *  - a checklist of every feature, ticked and noted, kept until a new round starts.
 * Nothing is booked, paid or emailed to anyone (the test participants are no customers), and the rooms are not on any
 * timetable (kind „test”).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Live_Lab {

	const OPT    = 'oys_live_lab';
	const PEOPLE = 4;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_oys_live_lab', array( __CLASS__, 'handle' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ---------- State ---------- */

	public static function state() {
		return wp_parse_args( (array) get_option( self::OPT, array() ), array(
			'rooms'   => array(), // profile => session id
			'run'     => array(), // the latest automatic check
			'checks'  => array(), // checklist key => [ s, n, by, at ]
			'devices' => array(), // device reports, newest first
			'rounds'  => array(), // earlier rounds: [ at, ok, bad, total ]
		) );
	}

	private static function save( array $s ) {
		update_option( self::OPT, $s, false );
	}

	/** @return array profile => [ title, what it is like ] */
	public static function profiles() {
		$grid = (int) OYS_Settings::get( 'live_grid_max' );
		return array(
			'small'   => array( __( 'Small group', 'olivia-studio' ), sprintf( __( 'Like a group class of at most %d online places: the participants may turn their camera and microphone on and everyone sees everyone. Recorded (only the teacher), with the summary of the opening words.', 'olivia-studio' ), $grid ) ),
			'big'     => array( __( 'Big group', 'olivia-studio' ), sprintf( __( 'Like a group class of more than %d online places: the participants see and hear only the teacher, their camera and microphone stay off; questions go in the chat or with a raised hand. Recorded.', 'olivia-studio' ), $grid ) ),
			'private' => array( __( 'One-to-one', 'olivia-studio' ), __( 'Like a one-to-one session: both see and hear each other. No recording and no summary.', 'olivia-studio' ) ),
		);
	}

	/** The profile of a test room ('' for any other class). */
	public static function profile_of( $session_id ) {
		$k = array_search( (int) $session_id, array_map( 'intval', (array) self::state()['rooms'] ), true );
		return false === $k ? '' : (string) $k;
	}

	/** A test room, if it was made already. */
	public static function room( $profile ) {
		$id = (int) ( self::state()['rooms'][ $profile ] ?? 0 );
		$s  = $id ? OYS_Schedule::get( $id ) : null;
		return $s && OYS_Live::is_test( $s ) ? $s : null;
	}

	/** The test room of a profile, made the first time (open for 90 minutes from now). */
	public static function ensure_room( $profile ) {
		$s = self::room( $profile );
		if ( $s ) {
			return $s;
		}
		$p   = self::profiles();
		$cap = array( 'small' => 6, 'big' => max( 30, (int) OYS_Settings::get( 'live_grid_max' ) + 5 ), 'private' => 2 );
		$now = time();
		$id  = OYS_Schedule::save( array(
			'kind'        => 'test',
			'class_slug'  => '',
			/* translators: %s: Small group / Big group / One-to-one */
			'title'       => sprintf( __( 'Test – %s', 'olivia-studio' ), $p[ $profile ][0] ),
			'starts_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'ends_at'     => gmdate( 'Y-m-d H:i:s', $now + 90 * MINUTE_IN_SECONDS ),
			'capacity'    => $cap[ $profile ],
			'format'      => 'online',
			'location'    => 'Online',
			'price_cents' => 0,
			'status'      => 'scheduled',
			'lang'        => oys_valid_lang( array_keys( OYS_I18n::languages() )[0] ?? 'en' ),
		) );
		$st                      = self::state();
		$st['rooms'][ $profile ] = (int) $id;
		self::save( $st );
		return OYS_Schedule::get( (int) $id );
	}

	/**
	 * Puts a test room into a moment of a class. open: started now (the recording starts at once); soon: starts in
	 * 3 minutes (the room is open, the recording starts by itself); wait: the waiting room (opens in 5 minutes, the
	 * teacher may already go in); over: ended (everyone leaves the video, the recordings close and the summary is written
	 * now); fresh: open now, with the earlier chat, recordings, opening words and summary deleted.
	 */
	public static function set_phase( $profile, $phase ) {
		if ( ! isset( self::profiles()[ $profile ] ) ) {
			return new WP_Error( 'oys_lab', 'profile' );
		}
		$s    = self::ensure_room( $profile );
		$now  = time();
		$open = max( 5, (int) OYS_Settings::get( 'live_open_minutes' ) );
		switch ( $phase ) {
			case 'soon':
				$start = $now + 3 * MINUTE_IN_SECONDS;
				break;
			case 'wait':
				$start = $now + ( $open + 5 ) * MINUTE_IN_SECONDS;
				break;
			case 'over':
				$start = $now - 91 * MINUTE_IN_SECONDS;
				break;
			case 'fresh':
				OYS_Live::wipe( $s->id );
				$st            = self::state();
				$st['devices'] = array_values( array_filter( (array) $st['devices'], fn( $d ) => (int) ( $d['session'] ?? 0 ) !== (int) $s->id ) );
				self::save( $st );
				$start = $now;
				break;
			case 'open':
				$start = $now;
				break;
			default:
				return new WP_Error( 'oys_lab', 'phase' );
		}
		$end = 'over' === $phase ? $now - 31 * MINUTE_IN_SECONDS : $start + 90 * MINUTE_IN_SECONDS;
		OYS_Schedule::save( array( 'starts_at' => gmdate( 'Y-m-d H:i:s', $start ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $end ), 'status' => 'scheduled' ), $s->id );
		$row = OYS_Live::row( $s->id );
		if ( 'over' === $phase ) {
			if ( $row && $row->room_name && OYS_Live::configured() ) {
				OYS_Live::api( 'DeleteRoom', array( 'room' => $row->room_name ) ); // everyone still in the video leaves
			}
			if ( $row ) {
				OYS_Live::process_one( $row ); // what the background job does 15 minutes after a class: now
			}
		} elseif ( $row ) {
			global $wpdb;
			$wpdb->update( OYS_Live::table(), array( 'state' => 'room' ), array( 'session_id' => (int) $s->id ) );
		}
		return OYS_Schedule::get( $s->id );
	}

	/** The links of a test room: the teacher's and the participants' (signed, no login). */
	public static function links( $session ) {
		$out = array( 'teacher' => OYS_Live::url( $session->id ), 'people' => array() );
		for ( $n = 1; $n <= self::PEOPLE; $n++ ) {
			$out['people'][ $n ] = OYS_Live::url( $session->id, OYS_Live::TEST_BASE + $n );
		}
		return $out;
	}

	/** waiting | open | live | over – where a test room is now (for the participants). */
	public static function moment( $session ) {
		$phase = OYS_Live::phase( $session );
		if ( 'open' === $phase && time() >= oys_ts( $session->starts_at ) && time() <= oys_ts( $session->ends_at ) ) {
			return 'live';
		}
		return 'before' === $phase ? 'waiting' : $phase;
	}

	/* ---------- The automatic check ---------- */

	/** A test participant of a test room, as the live page sees them. */
	private static function participant( $session, $n = 1 ) {
		$b = OYS_Live::TEST_BASE + $n;
		return OYS_Live::who( $session, $b, OYS_Live::sign( $session->id, $b ) );
	}

	/** The teacher (the person running the check). */
	private static function teacher() {
		$u = wp_get_current_user();
		return array( 'owner' => true, 'booking' => null, 'user_id' => (int) $u->ID, 'name' => $u->ID ? ( $u->display_name ?: $u->first_name ) : __( 'Teacher', 'olivia-studio' ) );
	}

	/** LiveKit's own answer on an entry token: [ ok|bad|warn, detail ]. */
	public static function validate_token( $token ) {
		$res = wp_remote_get( rtrim( OYS_Live::api_base(), '/' ) . '/rtc/validate?access_token=' . rawurlencode( $token ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'bad', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) ) );
		if ( 200 === $code ) {
			return array( 'ok', '' );
		}
		if ( 401 === $code || 403 === $code ) {
			return array( 'bad', mb_substr( $body, 0, 160 ) );
		}
		return array( 'warn', sprintf( __( 'LiveKit did not say (%d).', 'olivia-studio' ), $code ) );
	}

	/**
	 * Every part of the live classes the server can check by itself, in order. $with_ai: the AI writes a summary of a
	 * sample of opening words too (a few forints). @return array[] [ ok|warn|bad, title, detail ]
	 */
	public static function auto_check( $with_ai = false ) {
		$steps = array();
		$add   = function ( $state, $title, $detail = '' ) use ( &$steps ) {
			$steps[] = array( $state, (string) $title, (string) $detail );
		};
		if ( ! OYS_Live::configured() ) {
			$add( 'bad', __( 'No LiveKit project is connected', 'olivia-studio' ), __( 'Settings → Live online: the address, the API key and the secret.', 'olivia-studio' ) );
			return self::store_run( $steps );
		}
		$add( 'ok', __( 'LiveKit project', 'olivia-studio' ), OYS_Live::ws_url() . ' · ' . sprintf( __( 'API key %s', 'olivia-studio' ), OYS_Live::key_hint() ) );
		$add( OYS_Live::enabled() ? 'ok' : 'warn', __( 'Live classes switched on', 'olivia-studio' ), OYS_Live::enabled() ? __( 'The online classes run in the site\'s own live room.', 'olivia-studio' ) : __( 'Off: the real classes do not use it (the test rooms work).', 'olivia-studio' ) );

		// the server
		$t0    = microtime( true );
		$rooms = OYS_Live::api( 'ListRooms', array(), array( 'roomList' => true ) );
		$ms    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $rooms ) ) {
			$add( 'bad', __( 'The LiveKit server does not answer', 'olivia-studio' ), $rooms->get_error_message() );
		} else {
			$add( $ms < 1500 ? 'ok' : 'warn', __( 'The LiveKit server answers', 'olivia-studio' ), sprintf( __( '%1$d ms · rooms open now: %2$d', 'olivia-studio' ), $ms, count( (array) ( $rooms['rooms'] ?? array() ) ) ) );
			// a room of its own: made, found, changed, asked, deleted
			$name = 'oys-lab-check-' . strtolower( wp_generate_password( 6, false, false ) );
			$made = OYS_Live::api( 'CreateRoom', array( 'name' => $name, 'empty_timeout' => 60, 'max_participants' => 20 ), array( 'roomCreate' => true ) );
			if ( is_wp_error( $made ) ) {
				$add( 'bad', __( 'A room could not be made', 'olivia-studio' ), $made->get_error_message() );
			} else {
				$list  = OYS_Live::api( 'ListRooms', array( 'names' => array( $name ) ), array( 'roomList' => true ) );
				$found = ! is_wp_error( $list ) && in_array( $name, array_column( (array) ( $list['rooms'] ?? array() ), 'name' ), true );
				$meta  = OYS_Live::api( 'UpdateRoomMetadata', array( 'room' => $name, 'metadata' => 'oys-lab' ), array( 'roomAdmin' => true, 'room' => $name ) );
				$parts = OYS_Live::api( 'ListParticipants', array( 'room' => $name ), array( 'roomAdmin' => true, 'room' => $name ) );
				$gone  = OYS_Live::api( 'DeleteRoom', array( 'room' => $name ), array( 'roomCreate' => true ) );
				$ok    = $found && ! is_wp_error( $meta ) && ! is_wp_error( $parts ) && ! is_wp_error( $gone );
				$add( $ok ? 'ok' : 'bad', __( 'A room made, changed and deleted', 'olivia-studio' ), $ok ? $name : implode( ' · ', array_filter( array( $found ? '' : 'ListRooms', is_wp_error( $meta ) ? $meta->get_error_message() : '', is_wp_error( $parts ) ? $parts->get_error_message() : '', is_wp_error( $gone ) ? $gone->get_error_message() : '' ) ) ) );
			}
		}

		// the three rooms: who may do what, and LiveKit accepts their entry tokens
		foreach ( self::profiles() as $profile => $p ) {
			$s     = self::ensure_room( $profile );
			$room  = OYS_Live::room_name( $s->id );
			$t     = OYS_Live::grant( $s, self::teacher(), $room );
			$who   = self::participant( $s );
			$g     = $who ? OYS_Live::grant( $s, $who, $room ) : array();
			$want  = array( 'small' => true, 'big' => false, 'private' => true )[ $profile ];
			$right = $who && ! empty( $t['canPublish'] ) && (bool) ( $g['canPublish'] ?? false ) === $want;
			$v1    = OYS_Live::token( 'oys-lab-teacher', 'Teacher', $t, 120 );
			$v2    = OYS_Live::token( 'oys-lab-b1', 'Participant', $g ?: array( 'room' => $room, 'roomJoin' => true ), 120 );
			$a     = self::validate_token( $v1 );
			$b     = self::validate_token( $v2 );
			$state = ! $right || 'bad' === $a[0] || 'bad' === $b[0] ? 'bad' : ( 'warn' === $a[0] || 'warn' === $b[0] ? 'warn' : 'ok' );
			$what  = array(
				$want ? __( 'participants: camera and microphone', 'olivia-studio' ) : __( 'participants: watch and listen only', 'olivia-studio' ),
				OYS_Live::records( $s ) ? __( 'recorded', 'olivia-studio' ) : __( 'not recorded', 'olivia-studio' ),
				OYS_Live::summarises( $s ) ? __( 'summary', 'olivia-studio' ) : __( 'no summary', 'olivia-studio' ),
			);
			$add( $state, sprintf( __( '%s: entry for the teacher and the participants', 'olivia-studio' ), $p[0] ), implode( ' · ', $what ) . ( 'ok' === $state ? '' : ' · ' . trim( $a[1] . ' ' . $b[1] ) ) );
		}

		// our own parts, on the small room: the chat, a raised hand, a recording
		$s   = self::ensure_room( 'small' );
		$who = self::participant( $s, self::PEOPLE );
		$me  = self::teacher();
		if ( OYS_Live::chat_open( $s ) && $who ) {
			delete_transient( 'oys_live_chat_' . md5( $s->id . '|b' . $who['booking']->id ) );
			$mid  = OYS_Live::post_message( $s, $who, __( '[Check] test message', 'olivia-studio' ) );
			$seen = ! is_wp_error( $mid ) && in_array( (int) $mid, array_map( fn( $m ) => (int) $m->id, OYS_Live::messages( $s->id, (int) $mid - 1, 5 ) ), true );
			$del  = ! is_wp_error( $mid ) && OYS_Live::delete_message( $s, $me, (int) $mid );
			$add( $seen && $del ? 'ok' : 'bad', __( 'Class chat: written, read, removed', 'olivia-studio' ), is_wp_error( $mid ) ? $mid->get_error_message() : '' );
		} else {
			$add( 'warn', __( 'Class chat', 'olivia-studio' ), __( 'The small group room is over: open it and check again.', 'olivia-studio' ) );
		}
		if ( $who ) {
			$up   = OYS_Live::set_hand( $s, $who, true );
			$down = OYS_Live::set_hand( $s, $me, false, 'b' . $who['booking']->id );
			$add( isset( $up[ 'b' . $who['booking']->id ] ) && ! isset( $down[ 'b' . $who['booking']->id ] ) ? 'ok' : 'bad', __( 'Raised hand: up, seen, lowered by the teacher', 'olivia-studio' ) );
		}
		// the recording: a piece stored, read and deleted
		$dir   = OYS_Live::rec_dir();
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$rec   = OYS_Live::records( $s ) ? OYS_Live::rec_start( $s, 'video/webm' ) : new WP_Error( 'oys_lab', __( 'Recording is switched off (Settings → Live online).', 'olivia-studio' ) );
		if ( is_wp_error( $rec ) ) {
			$add( 'warn', __( 'Recording', 'olivia-studio' ), $rec->get_error_message() );
		} else {
			$piece = str_repeat( 'OYS-LAB-RECORDING-CHECK ', 64 );
			$one   = OYS_Live::rec_chunk( $s, $rec['id'], 0, $piece );
			OYS_Live::rec_stop( $s, $rec['id'], 60 );
			$w    = array_values( array_filter( OYS_Live::watchable( OYS_Live::row( $s->id ) ), fn( $r ) => $r['id'] === $rec['id'] ) );
			$same = $w && file_get_contents( $dir . '/' . $w[0]['file'] ) === $piece; // phpcs:ignore WordPress.WP.AlternativeFunctions
			OYS_Live::drop_recording( $s->id, $rec['id'] );
			$add( $same && ! is_wp_error( $one ) ? 'ok' : 'bad', __( 'Recording: a piece saved on the server, read back, deleted', 'olivia-studio' ), false !== $free ? sprintf( __( 'free space: %s', 'olivia-studio' ), size_format( $free ) ) : '' );
			if ( false !== $free && $free < 2 * GB_IN_BYTES ) {
				$add( 'warn', __( 'Little free space for recordings', 'olivia-studio' ), __( 'An hour of class is about 0.5–1 GB.', 'olivia-studio' ) );
			}
		}
		$post = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$add( $post >= 8 * MB_IN_BYTES || $post <= 0 ? 'ok' : 'bad', __( 'Size of the recording pieces the server takes', 'olivia-studio' ), sprintf( 'post_max_size %s · upload_max_filesize %s', ini_get( 'post_max_size' ), ini_get( 'upload_max_filesize' ) ) );

		// the live page: the browser may use the camera and the microphone
		$res = wp_remote_head( OYS_Live::url( $s->id ), array( 'timeout' => 15, 'redirection' => 0 ) );
		if ( is_wp_error( $res ) ) {
			$add( 'warn', __( 'Camera and microphone allowed on the live page', 'olivia-studio' ), __( 'The site could not be asked from itself:', 'olivia-studio' ) . ' ' . $res->get_error_message() );
		} else {
			$pp      = strtolower( (string) wp_remote_retrieve_header( $res, 'permissions-policy' ) );
			$blocked = preg_match( '/\b(camera|microphone)=\(\)/', $pp );
			$add( $blocked ? 'bad' : 'ok', __( 'Camera and microphone allowed on the live page', 'olivia-studio' ), $pp ? 'Permissions-Policy: ' . mb_substr( $pp, 0, 160 ) : __( 'no Permissions-Policy header', 'olivia-studio' ) );
		}

		// the background jobs (recordings closed, summaries written 15 minutes after a class)
		$next = wp_next_scheduled( 'oys_frequent' );
		$add( $next && $next < time() + 15 * MINUTE_IN_SECONDS ? 'ok' : 'warn', __( 'Background jobs', 'olivia-studio' ), $next ? sprintf( __( 'next run: %s', 'olivia-studio' ), wp_date( 'H:i', $next ) ) : __( 'not scheduled', 'olivia-studio' ) );

		// the AI (the summary of the opening words)
		if ( ! OYS_AI::ready() ) {
			$add( 'warn', __( 'AI summary', 'olivia-studio' ), __( 'No Anthropic API key (Newsletter → AI settings): no summary after class.', 'olivia-studio' ) );
		} elseif ( $with_ai ) {
			$sum = OYS_Live::summarize( $s, __( 'Good evening everyone, welcome. Today we work on the hips and the lower back, slowly. If your knee hurts, put a blanket under it. Breathe out long in every forward bend, and we finish with a long relaxation.', 'olivia-studio' ) );
			$add( is_wp_error( $sum ) || '' === trim( (string) $sum ) ? 'bad' : 'ok', __( 'AI summary of sample opening words', 'olivia-studio' ), is_wp_error( $sum ) ? $sum->get_error_message() : (string) $sum );
		} else {
			$add( 'ok', __( 'AI summary', 'olivia-studio' ), __( 'The key is set (tick „with the AI summary” to try it).', 'olivia-studio' ) );
		}
		return self::store_run( $steps );
	}

	private static function store_run( array $steps ) {
		$st        = self::state();
		$st['run'] = array( 'at' => time(), 'by' => wp_get_current_user()->display_name, 'steps' => $steps );
		self::save( $st );
		return $steps;
	}

	/* ---------- Who is in the rooms ---------- */

	private static function shape_participant( array $p ) {
		$tracks = array();
		foreach ( (array) ( $p['tracks'] ?? array() ) as $t ) {
			$type   = (string) ( $t['type'] ?? '' );
			$source = (string) ( $t['source'] ?? '' );
			$kind   = 'AUDIO' === $type || 0 === ( $t['type'] ?? null ) || 'MICROPHONE' === $source ? 'audio' : 'video';
			$tracks[] = array( 'sid' => (string) ( $t['sid'] ?? '' ), 'kind' => $kind, 'source' => strtolower( $source ?: $kind ), 'muted' => ! empty( $t['muted'] ), 'size' => ! empty( $t['width'] ) ? (int) $t['width'] . '×' . (int) $t['height'] : '' );
		}
		$joined = (int) ( $p['joined_at'] ?? ( $p['joinedAt'] ?? 0 ) );
		return array(
			'identity' => (string) ( $p['identity'] ?? '' ),
			'name'     => (string) ( $p['name'] ?? '' ),
			'teacher'  => str_starts_with( (string) ( $p['identity'] ?? '' ), 'teacher-' ),
			'state'    => strtolower( (string) ( $p['state'] ?? '' ) ),
			'since'    => $joined ? wp_date( 'H:i', $joined ) : '',
			'region'   => (string) ( $p['region'] ?? '' ),
			'tracks'   => $tracks,
		);
	}

	/** The three rooms right now, and every room open in the LiveKit project. */
	public static function monitor() {
		$out = array( 'rooms' => array(), 'project' => array(), 'time' => wp_date( 'H:i:s' ) );
		foreach ( array_keys( self::profiles() ) as $profile ) {
			$s = self::room( $profile );
			if ( ! $s ) {
				continue;
			}
			$row   = OYS_Live::row( $s->id );
			$parts = array();
			$err   = '';
			if ( $row && $row->room_name && OYS_Live::configured() ) {
				$res = OYS_Live::api( 'ListParticipants', array( 'room' => $row->room_name ), array( 'roomAdmin' => true, 'room' => $row->room_name ) );
				if ( is_wp_error( $res ) ) {
					$err = $res->get_error_message();
				} else {
					$parts = array_map( array( __CLASS__, 'shape_participant' ), (array) ( $res['participants'] ?? array() ) );
				}
			}
			$recs = array_map( fn( $r ) => array( 'status' => $r['status'], 'minutes' => (int) $r['minutes'], 'size' => size_format( (int) $r['bytes'] ) ), OYS_Live::recordings( $row ) );
			$out['rooms'][ $profile ] = array(
				'moment'       => self::moment( $s ),
				'starts'       => wp_date( 'H:i', oys_ts( $s->starts_at ) ),
				'ends'         => wp_date( 'H:i', oys_ts( $s->ends_at ) ),
				'people'       => $parts,
				'error'        => $err,
				'hands'        => array_values( OYS_Live::hands( $s->id ) ),
				'chat'         => count( OYS_Live::messages( $s->id, 0, 500 ) ),
				'recordings'   => $recs,
				'transcript'   => $row ? mb_strlen( (string) $row->transcript ) : 0,
				'summary'      => $row ? (string) $row->summary : '',
			);
		}
		if ( OYS_Live::configured() ) {
			$res = OYS_Live::api( 'ListRooms', array(), array( 'roomList' => true ) );
			foreach ( is_wp_error( $res ) ? array() : (array) ( $res['rooms'] ?? array() ) as $r ) {
				$sid   = preg_match( '/-(\d+)$/', (string) ( $r['name'] ?? '' ), $m ) ? (int) $m[1] : 0;
				$s     = $sid && str_starts_with( (string) $r['name'], 'oys-' ) && ! str_starts_with( (string) $r['name'], 'oys-lab' ) ? OYS_Schedule::get( $sid ) : null;
				$out['project'][] = array( 'name' => (string) ( $r['name'] ?? '' ), 'class' => $s ? oys_session_title( $s ) : '', 'people' => (int) ( $r['num_participants'] ?? ( $r['numParticipants'] ?? 0 ) ) );
			}
		}
		return $out;
	}

	/** Mute someone's microphone, or take them out of the room. */
	public static function act( $profile, $do, $identity ) {
		$s   = self::room( $profile );
		$row = $s ? OYS_Live::row( $s->id ) : null;
		if ( ! $row || ! $row->room_name ) {
			return new WP_Error( 'oys_lab', __( 'Nobody is in this room.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		if ( 'mute_all' === $do ) {
			return OYS_Live::mute_all( $s );
		}
		$identity = sanitize_text_field( (string) $identity );
		if ( 'remove' === $do ) {
			return OYS_Live::api( 'RemoveParticipant', array( 'room' => $row->room_name, 'identity' => $identity ), array( 'roomAdmin' => true, 'room' => $row->room_name ) );
		}
		$p = OYS_Live::api( 'GetParticipant', array( 'room' => $row->room_name, 'identity' => $identity ), array( 'roomAdmin' => true, 'room' => $row->room_name ) );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$n = 0;
		foreach ( self::shape_participant( $p )['tracks'] as $t ) {
			if ( 'audio' === $t['kind'] && ! $t['muted'] ) {
				$r  = OYS_Live::api( 'MutePublishedTrack', array( 'room' => $row->room_name, 'identity' => $identity, 'track_sid' => $t['sid'], 'muted' => true ), array( 'roomAdmin' => true, 'room' => $row->room_name ) );
				$n += is_wp_error( $r ) ? 0 : 1;
			}
		}
		return $n;
	}

	/* ---------- Device check ---------- */

	/** A token for LiveKit's own connection test, in a room of its own (the class is not disturbed). */
	public static function diag_token( $session, $identity ) {
		$room = 'oys-lab-net-' . (int) $session->id . '-' . substr( md5( $identity . wp_salt( 'nonce' ) ), 0, 8 );
		return OYS_Live::token( 'diag-' . $identity . '-' . strtolower( wp_generate_password( 4, false, false ) ), 'diag', array( 'room' => $room, 'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => true, 'canPublishData' => true ), HOUR_IN_SECONDS );
	}

	/** „Check this device” (the live page of a test room and the lab page). */
	public static function diag_html( $session, array $who, $b = 0, $k = '' ) {
		wp_enqueue_script( 'oys-live-diag', OYS_URL . 'assets/live-diag.js', array(), OYS_VERSION, true );
		$cfg = array(
			'url'    => OYS_Live::ws_url(),
			'token'  => self::diag_token( $session, OYS_Live::identity( $who ) ),
			'report' => esc_url_raw( rest_url( 'oys/v1/live/' . (int) $session->id . '/diag' ) ),
			'nonce'  => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'b'      => (int) $b,
			'k'      => (string) $k,
			'lk'     => OYS_URL . 'assets/vendor/livekit-client.umd.js?ver=2.23.0',
			'i18n'   => self::diag_i18n(),
		);
		return '<section class="oys-diag" data-oys-diag="' . esc_attr( wp_json_encode( $cfg ) ) . '"><h3 class="oys-diag__h">' . esc_html__( 'Check this device', 'olivia-studio' ) . '</h3>'
			. '<p class="oys-diag__lead">' . esc_html__( 'The test of the live video service itself: does this phone or computer reach it, can it send sound and picture, and does it get back in after an interruption. Allow the camera and the microphone when asked. It takes about half a minute; the result goes to the test page.', 'olivia-studio' ) . '</p>'
			. '<p><button type="button" class="btn btn--ghost button" data-diag-run>' . esc_html__( 'Check this device', 'olivia-studio' ) . '</button></p>'
			. '<p class="oys-diag__sum" data-diag-sum aria-live="polite"></p><ol class="oys-diag__list" data-diag-list></ol></section>';
	}

	private static function diag_i18n() {
		return array(
			'running' => __( 'Checking… (allow the camera and the microphone)', 'olivia-studio' ),
			'allOk'   => __( 'Everything works on this device.', 'olivia-studio' ),
			'someBad' => __( 'Something does not work on this device: see the red lines.', 'olivia-studio' ),
			'sent'    => __( 'The result is on the test page.', 'olivia-studio' ),
			'error'   => __( 'The check could not start. Try again in a moment.', 'olivia-studio' ),
			'again'   => __( 'Check again', 'olivia-studio' ),
			'yes'     => __( 'yes', 'olivia-studio' ),
			'no'      => __( 'no', 'olivia-studio' ),
			'caps'    => array(
				'secure'     => __( 'Secure connection (https)', 'olivia-studio' ),
				'webrtc'     => __( 'Live video in this browser (WebRTC)', 'olivia-studio' ),
				'media'      => __( 'Camera and microphone access', 'olivia-studio' ),
				'recorder'   => __( 'Recording in this browser (teacher)', 'olivia-studio' ),
				'speech'     => __( 'Speech recognition for the opening words (teacher)', 'olivia-studio' ),
				'fullscreen' => __( 'Full screen', 'olivia-studio' ),
			),
			'checks'  => array(
				'Connecting to signal connection via WebSocket' => __( 'Reaching the live video server (WebSocket)', 'olivia-studio' ),
				'Establishing WebRTC connection'                => __( 'Video connection (WebRTC)', 'olivia-studio' ),
				'Can connect via TURN'                          => __( 'Connection through a firewall (TURN)', 'olivia-studio' ),
				'Resuming connection after interruption'        => __( 'Getting back in after an interruption', 'olivia-studio' ),
				'Can publish audio'                             => __( 'Sending sound (microphone)', 'olivia-studio' ),
				'Can publish video'                             => __( 'Sending picture (camera)', 'olivia-studio' ),
				'Connection via UDP vs TCP'                     => __( 'Type of connection (UDP or TCP)', 'olivia-studio' ),
				'Cloud regions'                                 => __( 'The nearest LiveKit data centre', 'olivia-studio' ),
			),
		);
	}

	/** A device's result (from its own test link): kept on the lab page, newest first, 40 at most. */
	public static function add_report( $session, array $who, array $r ) {
		$caps = array();
		foreach ( array( 'secure', 'webrtc', 'media', 'speech', 'fullscreen' ) as $c ) {
			$caps[ $c ] = ! empty( $r['caps'][ $c ] );
		}
		$caps['recorder'] = mb_substr( sanitize_text_field( (string) ( $r['caps']['recorder'] ?? '' ) ), 0, 60 );
		$checks           = array();
		foreach ( array_slice( (array) ( $r['checks'] ?? array() ), 0, 12 ) as $c ) {
			$status   = sanitize_key( (string) ( $c['status'] ?? '' ) );
			$checks[] = array(
				'name'   => mb_substr( sanitize_text_field( (string) ( $c['name'] ?? '' ) ), 0, 90 ),
				'status' => in_array( $status, array( 'success', 'failed', 'skipped', 'running', 'idle' ), true ) ? $status : 'idle',
				'note'   => mb_substr( sanitize_text_field( (string) ( $c['note'] ?? '' ) ), 0, 200 ),
			);
		}
		$failed = array_filter( $checks, fn( $c ) => 'failed' === $c['status'] );
		$entry  = array(
			'at'      => time(),
			'session' => (int) $session->id,
			'profile' => self::profile_of( $session->id ),
			'who'     => mb_substr( (string) $who['name'], 0, 60 ),
			'device'  => mb_substr( sanitize_text_field( (string) ( $r['device'] ?? '' ) ), 0, 80 ),
			'ok'      => ! $failed && $caps['webrtc'] && $caps['media'],
			'checks'  => $checks,
			'caps'    => $caps,
		);
		$st            = self::state();
		$st['devices'] = array_slice( array_merge( array( $entry ), (array) $st['devices'] ), 0, 40 );
		self::save( $st );
		return $entry;
	}

	/* ---------- REST ---------- */

	public static function routes() {
		$admin = fn() => current_user_can( 'oys_manage' );
		register_rest_route( 'oys/v1', '/live-lab', array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => fn() => rest_ensure_response( self::monitor() ) ) );
		register_rest_route( 'oys/v1', '/live-lab/act', array(
			'methods'             => 'POST',
			'permission_callback' => $admin,
			'callback'            => function ( WP_REST_Request $r ) {
				$res = self::act( sanitize_key( (string) $r->get_param( 'profile' ) ), sanitize_key( (string) $r->get_param( 'do' ) ), (string) $r->get_param( 'identity' ) );
				return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true, 'n' => is_int( $res ) ? $res : 0 ) );
			},
		) );
		register_rest_route( 'oys/v1', '/live/(?P<id>\d+)/diag', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$s   = OYS_Schedule::get( (int) $r['id'] );
				$who = $s && OYS_Live::is_test( $s ) ? OYS_Live::who( $s, (int) $r->get_param( 'b' ), (string) $r->get_param( 'k' ) ) : null;
				if ( ! $who ) {
					return new WP_Error( 'oys_lab', __( 'Only for the test rooms.', 'olivia-studio' ), array( 'status' => 403 ) );
				}
				$flood = 'oys_lab_diag_' . md5( (string) OYS_Security::ip() );
				if ( (int) get_transient( $flood ) >= 30 ) {
					return new WP_Error( 'oys_lab', __( 'Too many checks: try again later.', 'olivia-studio' ), array( 'status' => 429 ) );
				}
				set_transient( $flood, (int) get_transient( $flood ) + 1, HOUR_IN_SECONDS );
				$e = self::add_report( $s, $who, (array) $r->get_json_params() );
				return rest_ensure_response( array( 'ok' => $e['ok'] ) );
			},
		) );
	}

	/* ---------- The checklist ---------- */

	/** group => [ title, [ key => [ what, how ] ] ] */
	public static function checklist() {
		return array(
			'in'      => array( __( 'Getting in', 'olivia-studio' ), array(
				'teacher_join' => array( __( 'The teacher goes live', 'olivia-studio' ), __( 'Open the teacher link (logged in) on a computer, tap „Go live”, allow the camera and the microphone.', 'olivia-studio' ) ),
				'part_join'    => array( __( 'A participant joins without logging in', 'olivia-studio' ), __( 'Open a participant link on a phone (or in another browser) and tap „Join the class”.', 'olivia-studio' ) ),
				'waiting'      => array( __( 'Waiting room and countdown', 'olivia-studio' ), __( 'Set a room to „Waiting room”: the participant link counts down and can\'t join yet; the teacher can already go in.', 'olivia-studio' ) ),
				'over'         => array( __( 'After the class', 'olivia-studio' ), __( 'Set the room to „Over”: everyone leaves the video; the chat and the recording stay on the page.', 'olivia-studio' ) ),
				'bad_link'     => array( __( 'A wrong link does not get in', 'olivia-studio' ), __( 'Change the last letter of a participant link: the page must not let you in.', 'olivia-studio' ) ),
				'same_link'    => array( __( 'The same link on two devices', 'olivia-studio' ), __( 'Open the same participant link on two devices and join on both: the newer one stays in the room, the older one leaves.', 'olivia-studio' ) ),
			) ),
			'av'      => array( __( 'Picture and sound', 'olivia-studio' ), array(
				'small_grid'  => array( __( 'Small group: everyone sees everyone', 'olivia-studio' ), __( 'In the small group room a participant turns the camera on: the teacher and the other participants see them.', 'olivia-studio' ) ),
				'big_teacher' => array( __( 'Big group: only the teacher is on screen', 'olivia-studio' ), __( 'In the big group room the participants see and hear only the teacher and have no camera or microphone button.', 'olivia-studio' ) ),
				'private_two' => array( __( 'One-to-one: two-way picture and sound', 'olivia-studio' ), __( 'In the one-to-one room both see and hear each other.', 'olivia-studio' ) ),
				'own_mute'    => array( __( 'Own microphone and camera off and on', 'olivia-studio' ), __( 'The teacher and a participant switch their own microphone and camera off and on again.', 'olivia-studio' ) ),
				'mute_all'    => array( __( 'Mute everyone', 'olivia-studio' ), __( 'With participants talking, the teacher taps „Mute everyone”: every participant\'s microphone goes off.', 'olivia-studio' ) ),
				'sound_phone' => array( __( 'Sound on phones', 'olivia-studio' ), __( 'On an iPhone (silent switch on and off) and on an Android phone the teacher can be heard.', 'olivia-studio' ) ),
				'fullscreen'  => array( __( 'Full screen', 'olivia-studio' ), __( '„Full screen” on a phone and on a computer, and back.', 'olivia-studio' ) ),
				'quality'     => array( __( 'Picture quality', 'olivia-studio' ), __( 'The teacher\'s picture is sharp on a computer; on a weak connection it gets blurrier but keeps going.', 'olivia-studio' ) ),
			) ),
			'together' => array( __( 'Chat and raised hands', 'olivia-studio' ), array(
				'chat_send'   => array( __( 'Chat messages', 'olivia-studio' ), __( 'A participant and the teacher write: the messages appear for everyone within a few seconds.', 'olivia-studio' ) ),
				'chat_photo'  => array( __( 'A photo in the chat', 'olivia-studio' ), __( 'A participant sends a photo from the phone; it appears in the chat.', 'olivia-studio' ) ),
				'chat_delete' => array( __( 'Removing a message', 'olivia-studio' ), __( 'The teacher removes a message: it disappears for everyone.', 'olivia-studio' ) ),
				'hand'        => array( __( 'Raising a hand', 'olivia-studio' ), __( 'A participant raises a hand: the teacher sees it at once and can lower it; the participant can lower it too.', 'olivia-studio' ) ),
				'monitor'     => array( __( 'Who is in the room', 'olivia-studio' ), __( 'On this page, under the room, the people in it appear within a few seconds, with their camera and microphone.', 'olivia-studio' ) ),
			) ),
			'rec'     => array( __( 'Recording and summary', 'olivia-studio' ), array(
				'rec_auto'    => array( __( 'The recording starts with the class', 'olivia-studio' ), __( 'Set a room to „Starts in 3 minutes” and go live as the teacher: the recording starts by itself at the start.', 'olivia-studio' ) ),
				'rec_toggle'  => array( __( 'Stopping and restarting the recording', 'olivia-studio' ), __( 'The teacher stops the recording and starts it again: both parts are saved.', 'olivia-studio' ) ),
				'rec_teacher' => array( __( 'Only the teacher is on the recording', 'olivia-studio' ), __( 'Watch the recording: only the teacher\'s picture and sound are on it.', 'olivia-studio' ) ),
				'replay'      => array( __( 'Watching it again', 'olivia-studio' ), __( 'After „Over” the participant link shows the recording and it plays, on a phone and on a computer.', 'olivia-studio' ) ),
				'download'    => array( __( 'Downloading the recording', 'olivia-studio' ), __( 'The teacher can download the recording from the live page.', 'olivia-studio' ) ),
				'summary'     => array( __( 'Summary of the opening words', 'olivia-studio' ), __( 'The teacher talks for a minute at the start (in Chrome). After „Over” the short summary is on the page.', 'olivia-studio' ) ),
				'private_rec' => array( __( 'No recording in a one-to-one session', 'olivia-studio' ), __( 'In the one-to-one room there is no recording and no summary.', 'olivia-studio' ) ),
			) ),
			'net'     => array( __( 'Connection', 'olivia-studio' ), array(
				'diag_all'  => array( __( 'Device check on every device', 'olivia-studio' ), __( 'Run „Check this device” on every phone and computer you test with: every line green.', 'olivia-studio' ) ),
				'reconnect' => array( __( 'Getting back in', 'olivia-studio' ), __( 'Reload the page, or switch from Wi-Fi to mobile data: you get back into the room.', 'olivia-studio' ) ),
				'rejoin'    => array( __( 'Leaving and coming back', 'olivia-studio' ), __( '„Leave”, then join again with the same link.', 'olivia-studio' ) ),
			) ),
			'devices' => array( __( 'Devices', 'olivia-studio' ), array(
				'dev_iphone'  => array( 'iPhone · Safari', __( 'As a participant, and as the teacher too if Olívia ever teaches from a phone.', 'olivia-studio' ) ),
				'dev_android' => array( 'Android · Chrome', __( 'As a participant.', 'olivia-studio' ) ),
				'dev_ipad'    => array( 'iPad · Safari', __( 'As a participant.', 'olivia-studio' ) ),
				'dev_mac'     => array( 'Mac · Safari', __( 'As the teacher and as a participant.', 'olivia-studio' ) ),
				'dev_chrome'  => array( 'Windows / Mac · Chrome', __( 'As the teacher (recording, opening words) and as a participant.', 'olivia-studio' ) ),
				'dev_edge'    => array( 'Windows · Edge', __( 'As a participant.', 'olivia-studio' ) ),
				'dev_firefox' => array( 'Firefox', __( 'As a participant.', 'olivia-studio' ) ),
			) ),
		);
	}

	/** Saves the ticks and notes that changed. */
	public static function save_checks( array $post ) {
		$st  = self::state();
		$by  = wp_get_current_user()->display_name;
		$all = array();
		foreach ( self::checklist() as $g ) {
			$all += $g[1];
		}
		foreach ( array_keys( $all ) as $key ) {
			$s    = sanitize_key( (string) ( $post['s'][ $key ] ?? '' ) );
			$s    = in_array( $s, array( 'ok', 'bad' ), true ) ? $s : '';
			$n    = mb_substr( sanitize_text_field( (string) ( $post['n'][ $key ] ?? '' ) ), 0, 300 );
			$have = $st['checks'][ $key ] ?? array( 's' => '', 'n' => '' );
			if ( $have['s'] !== $s || $have['n'] !== $n ) {
				$st['checks'][ $key ] = array( 's' => $s, 'n' => $n, 'by' => $by, 'at' => time() );
			}
		}
		self::save( $st );
	}

	/** [ ok, bad, total ] */
	public static function tally() {
		$st    = self::state();
		$total = 0;
		$ok    = 0;
		$bad   = 0;
		foreach ( self::checklist() as $g ) {
			foreach ( array_keys( $g[1] ) as $key ) {
				++$total;
				$s    = $st['checks'][ $key ]['s'] ?? '';
				$ok  += 'ok' === $s ? 1 : 0;
				$bad += 'bad' === $s ? 1 : 0;
			}
		}
		return array( $ok, $bad, $total );
	}

	/** A new round: the ticks start empty, the old round is remembered by its numbers. */
	public static function new_round() {
		list( $ok, $bad, $total ) = self::tally();
		$st             = self::state();
		$st['rounds']   = array_slice( array_merge( array( array( 'at' => time(), 'ok' => $ok, 'bad' => $bad, 'total' => $total ) ), (array) $st['rounds'] ), 0, 10 );
		$st['checks']   = array();
		$st['devices']  = array();
		self::save( $st );
	}

	/* ---------- The admin page ---------- */

	public static function menu() {
		add_submenu_page( 'oys', __( 'Live class test', 'olivia-studio' ), __( 'Live class test', 'olivia-studio' ), 'oys_manage', 'oys-live-lab', array( __CLASS__, 'page' ) );
	}

	public static function handle() {
		if ( ! current_user_can( 'oys_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'olivia-studio' ) );
		}
		check_admin_referer( 'oys_live_lab' );
		$do   = sanitize_key( $_POST['do'] ?? '' );
		$hash = '';
		$msg  = '';
		if ( 'phase' === $do ) {
			$profile = sanitize_key( $_POST['profile'] ?? '' );
			$res     = self::set_phase( $profile, sanitize_key( $_POST['phase'] ?? '' ) );
			$msg     = is_wp_error( $res ) ? $res->get_error_message() : __( 'Done: the links below work accordingly.', 'olivia-studio' );
			$hash    = 'room-' . $profile;
		} elseif ( 'check' === $do ) {
			$steps = self::auto_check( ! empty( $_POST['ai'] ) );
			$bad   = count( array_filter( $steps, fn( $x ) => 'bad' === $x[0] ) );
			$msg   = $bad ? sprintf( _n( 'The check found %d problem: see below.', 'The check found %d problems: see below.', $bad, 'olivia-studio' ), $bad ) : __( 'The check is done: everything the server can check works.', 'olivia-studio' );
			$hash  = 'check';
		} elseif ( 'checks' === $do ) {
			self::save_checks( wp_unslash( $_POST ) );
			$msg  = __( 'Saved.', 'olivia-studio' );
			$hash = 'list';
		} elseif ( 'round' === $do ) {
			self::new_round();
			$msg  = __( 'A new round started: the ticks and the device results are empty.', 'olivia-studio' );
			$hash = 'list';
		} elseif ( 'mail' === $do ) {
			$msg  = self::mail_links() ? sprintf( __( 'The links are in your mailbox (%s).', 'olivia-studio' ), wp_get_current_user()->user_email ) : __( 'The email could not be sent.', 'olivia-studio' );
			$hash = 'rooms';
		}
		if ( $msg && function_exists( 'oys_flash' ) ) {
			oys_flash( $msg );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=oys-live-lab' ) . ( $hash ? '#' . $hash : '' ) );
		exit;
	}

	/** Every link of the three rooms to the studio's own mailbox (to open them on phones). */
	public static function mail_links() {
		$html = '';
		foreach ( self::profiles() as $profile => $p ) {
			$s     = self::ensure_room( $profile );
			$l     = self::links( $s );
			$html .= '<h3 style="margin:22px 0 6px">' . esc_html( $p[0] ) . '</h3><p><b>' . esc_html__( 'Teacher (log in first):', 'olivia-studio' ) . '</b><br><a href="' . esc_url( $l['teacher'] ) . '">' . esc_html( $l['teacher'] ) . '</a></p><ol>';
			foreach ( $l['people'] as $u ) {
				$html .= '<li><a href="' . esc_url( $u ) . '">' . esc_html( $u ) . '</a></li>';
			}
			$html .= '</ol>';
		}
		$intro = '<p>' . esc_html__( 'The links of the live class test rooms. Open a participant link on each phone or computer you test with; the teacher link only works logged in.', 'olivia-studio' ) . '</p>';
		return OYS_Emails::send( wp_get_current_user()->user_email, __( 'Live class test: the links', 'olivia-studio' ), __( 'Live class test', 'olivia-studio' ), $intro . $html );
	}

	private static function form( $do, $inner, $label, $class = 'button', $extra = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oys-lab__inline"' . $extra . '>' . wp_nonce_field( 'oys_live_lab', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="oys_live_lab"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . $inner
			. '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function page() {
		$st = self::state();
		echo '<div class="wrap oys-wrap oys-lab"><h1>' . esc_html__( 'Live class test', 'olivia-studio' ) . '</h1>';
		if ( function_exists( 'oys_render_flash' ) ) {
			echo oys_render_flash(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p class="oys-lab__lead">' . esc_html__( 'Every part of the live classes, for real: on the site\'s own LiveKit project with its real keys, in three test rooms nobody can book or see on the timetable. Nothing is booked, paid or emailed to guests.', 'olivia-studio' ) . '</p>';
		if ( ! OYS_Live::configured() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No LiveKit project is connected yet: Settings → Live online (the address, the API key and the secret).', 'olivia-studio' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=oys-settings#live' ) ) . '">' . esc_html__( 'Settings', 'olivia-studio' ) . '</a></p></div></div>';
			return;
		}
		list( $ok, $bad, $total ) = self::tally();
		echo '<nav class="oys-lab__nav"><a href="#check">1. ' . esc_html__( 'Automatic check', 'olivia-studio' ) . '</a><a href="#rooms">2. ' . esc_html__( 'Test rooms', 'olivia-studio' ) . '</a><a href="#devices">3. ' . esc_html__( 'Devices', 'olivia-studio' ) . '</a><a href="#list">4. ' . esc_html( sprintf( __( 'Checklist (%1$d/%2$d)', 'olivia-studio' ), $ok, $total ) ) . '</a></nav>';

		// 1. the automatic check
		echo '<section class="oys-lab__card" id="check"><h2>1. ' . esc_html__( 'Automatic check', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( 'What the server can try by itself, on the real LiveKit project: the keys, a room made and deleted, the entry tokens of the three rooms, the chat, a raised hand, a piece of recording, the camera permission of the live page, the background jobs, the AI.', 'olivia-studio' ) . '</p>'
			. self::form( 'check', '<label class="oys-lab__ai"><input type="checkbox" name="ai" value="1"> ' . esc_html__( 'with the AI summary (a few forints)', 'olivia-studio' ) . '</label> ', __( 'Run the check', 'olivia-studio' ), 'button button-primary' );
		if ( ! empty( $st['run']['steps'] ) ) {
			echo '<p class="oys-lab__when">' . esc_html( sprintf( __( 'Last run: %1$s (%2$s)', 'olivia-studio' ), wp_date( 'M j, H:i', (int) $st['run']['at'] ), $st['run']['by'] ) ) . '</p><ul class="oys-lab__steps">';
			foreach ( $st['run']['steps'] as $x ) {
				echo '<li class="is-' . esc_attr( $x[0] ) . '"><b>' . ( 'ok' === $x[0] ? '✓' : ( 'warn' === $x[0] ? '!' : '✗' ) ) . ' ' . esc_html( $x[1] ) . '</b>' . ( '' !== $x[2] ? '<small>' . esc_html( $x[2] ) . '</small>' : '' ) . '</li>';
			}
			echo '</ul>';
		}
		$s = OYS_Settings::all();
		echo '<p class="description">' . esc_html( sprintf( __( 'Settings now: the room opens %1$d minutes before · grid up to %2$d places · recording: %3$s · watchable for %4$d days, deleted after %5$d · opening words: %6$d minutes, summary: %7$s.', 'olivia-studio' ), (int) $s['live_open_minutes'], (int) $s['live_grid_max'], $s['live_record'], (int) $s['live_replay_days'], (int) $s['live_keep_days'], (int) $s['live_intro_minutes'], $s['live_summary'] ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=oys-settings#live' ) ) . '">' . esc_html__( 'Change', 'olivia-studio' ) . '</a></p></section>';

		// 2. the rooms
		echo '<section class="oys-lab__card" id="rooms"><h2>2. ' . esc_html__( 'Test rooms', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( 'Put a room into any moment of a class with the buttons, then open the teacher link here (you are logged in) and the participant links on phones or in other browsers (no login: scan the QR code). Who is in the room shows below it within seconds, straight from LiveKit.', 'olivia-studio' ) . '</p>'
			. '<p>' . self::form( 'mail', '', __( 'Email me every link', 'olivia-studio' ) ) . '</p><div class="oys-lab__rooms">';
		$names = array( 'waiting' => __( 'Waiting room', 'olivia-studio' ), 'open' => __( 'Open, the class starts soon', 'olivia-studio' ), 'live' => __( 'The class is on', 'olivia-studio' ), 'over' => __( 'Over', 'olivia-studio' ) );
		foreach ( self::profiles() as $profile => $p ) {
			$sess = self::room( $profile );
			echo '<article class="oys-lab__room" id="room-' . esc_attr( $profile ) . '" data-lab-room="' . esc_attr( $profile ) . '"><h3>' . esc_html( $p[0] ) . '</h3><p class="oys-lab__what">' . esc_html( $p[1] ) . '</p>';
			if ( $sess ) {
				$m = self::moment( $sess );
				echo '<p class="oys-lab__state is-' . esc_attr( $m ) . '" data-lab-state><b>' . esc_html( $names[ $m ] ?? $m ) . '</b> · ' . esc_html( wp_date( 'H:i', oys_ts( $sess->starts_at ) ) . '–' . wp_date( 'H:i', oys_ts( $sess->ends_at ) ) ) . '</p>';
			}
			echo '<div class="oys-lab__phases">';
			foreach ( array( 'open' => __( 'Start now', 'olivia-studio' ), 'soon' => __( 'Starts in 3 minutes', 'olivia-studio' ), 'wait' => __( 'Waiting room', 'olivia-studio' ), 'over' => __( 'Over', 'olivia-studio' ), 'fresh' => __( 'Start afresh', 'olivia-studio' ) ) as $ph => $label ) {
				$confirm = 'fresh' === $ph ? ' onsubmit="return confirm(\'' . esc_js( __( 'The chat, the recordings and the summary of this room are deleted. Go on?', 'olivia-studio' ) ) . '\')"' : '';
				echo self::form( 'phase', '<input type="hidden" name="profile" value="' . esc_attr( $profile ) . '"><input type="hidden" name="phase" value="' . esc_attr( $ph ) . '">', $label, 'open' === $ph ? 'button button-primary' : 'button', $confirm ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
			if ( $sess ) {
				$l = self::links( $sess );
				echo '<p class="oys-lab__teacher"><a class="button button-primary" href="' . esc_url( $l['teacher'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open as the teacher', 'olivia-studio' ) . '</a></p><ol class="oys-lab__people">';
				foreach ( $l['people'] as $n => $u ) {
					/* translators: %d: number of the test participant */
					echo '<li><span>' . esc_html( sprintf( __( 'Test participant %d', 'olivia-studio' ), $n ) ) . '</span> <a class="button button-small" href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open link', 'olivia-studio' ) . '</a> <button type="button" class="button button-small" data-lab-copy="' . esc_attr( $u ) . '">' . esc_html__( 'Copy', 'olivia-studio' ) . '</button> <button type="button" class="button button-small" data-lab-qr="' . esc_attr( $u ) . '">QR</button><span class="oys-lab__qr" hidden></span></li>';
				}
				echo '</ol><div class="oys-lab__live" data-lab-live><p class="description">' . esc_html__( 'Who is in the room: loading…', 'olivia-studio' ) . '</p></div>';
			} else {
				echo '<p class="description">' . esc_html__( 'Start the room to get its links.', 'olivia-studio' ) . '</p>';
			}
			echo '</article>';
		}
		echo '</div><div class="oys-lab__project" data-lab-project></div></section>';

		// 3. devices
		echo '<section class="oys-lab__card" id="devices"><h2>3. ' . esc_html__( 'Devices', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( '„Check this device” is on every test link\'s page (under the class), and here for this computer. The results of every device arrive here.', 'olivia-studio' ) . '</p>';
		$small = self::room( 'small' );
		if ( $small ) {
			echo self::diag_html( $small, self::teacher() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$devices = (array) $st['devices'];
		if ( $devices ) {
			$labels = self::diag_i18n()['checks'];
			echo '<table class="widefat striped oys-table oys-lab__devices"><thead><tr><th>' . esc_html__( 'When', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Device', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Result', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Details', 'olivia-studio' ) . '</th></tr></thead><tbody>';
			foreach ( $devices as $d ) {
				$bad_lines = array_filter( $d['checks'], fn( $c ) => 'failed' === $c['status'] );
				$details   = array_map( fn( $c ) => ( 'success' === $c['status'] ? '✓ ' : ( 'failed' === $c['status'] ? '✗ ' : '– ' ) ) . ( $labels[ $c['name'] ] ?? $c['name'] ) . ( 'failed' === $c['status'] && '' !== $c['note'] ? ': ' . $c['note'] : '' ), $d['checks'] );
				$details[] = ( $d['caps']['recorder'] ? '✓ ' : '– ' ) . __( 'Recording in this browser (teacher)', 'olivia-studio' );
				$details[] = ( $d['caps']['speech'] ? '✓ ' : '– ' ) . __( 'Speech recognition for the opening words (teacher)', 'olivia-studio' );
				echo '<tr><td>' . esc_html( wp_date( 'M j, H:i', (int) $d['at'] ) ) . '</td><td><b>' . esc_html( $d['device'] ) . '</b><br><small>' . esc_html( $d['who'] ) . '</small></td><td>' . ( $d['ok'] ? '✅ ' . esc_html__( 'works', 'olivia-studio' ) : '⚠️ ' . esc_html( sprintf( _n( '%d problem', '%d problems', max( 1, count( $bad_lines ) ), 'olivia-studio' ), max( 1, count( $bad_lines ) ) ) ) ) . '</td><td><details><summary>' . esc_html__( 'Show', 'olivia-studio' ) . '</summary><ul>';
				foreach ( $details as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul></details></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'No device has been checked yet.', 'olivia-studio' ) . '</p>';
		}
		echo '</section>';

		// 4. the checklist
		echo '<section class="oys-lab__card" id="list"><h2>4. ' . esc_html__( 'Checklist', 'olivia-studio' ) . '</h2><p class="description">' . esc_html( sprintf( __( '%1$d of %2$d works, %3$d needs fixing. Tick what you tried; write what you saw if something is wrong.', 'olivia-studio' ), $ok, $total, $bad ) ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'oys_live_lab', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="oys_live_lab"><input type="hidden" name="do" value="checks">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( self::checklist() as $g ) {
			echo '<h3>' . esc_html( $g[0] ) . '</h3><ul class="oys-lab__list">';
			foreach ( $g[1] as $key => $it ) {
				$c   = $st['checks'][ $key ] ?? array( 's' => '', 'n' => '' );
				$val = $c['s'];
				echo '<li class="is-' . esc_attr( $val ?: 'todo' ) . '"><div class="oys-lab__item"><b>' . esc_html( $it[0] ) . '</b><small>' . esc_html( $it[1] ) . '</small>'
					. ( ! empty( $c['at'] ) ? '<small class="oys-lab__by">' . esc_html( sprintf( __( '%1$s, %2$s', 'olivia-studio' ), $c['by'], wp_date( 'M j, H:i', (int) $c['at'] ) ) ) . '</small>' : '' ) . '</div>'
					. '<div class="oys-lab__tick"><label><input type="radio" name="s[' . esc_attr( $key ) . ']" value="ok"' . checked( $val, 'ok', false ) . '> ✓ ' . esc_html__( 'works', 'olivia-studio' ) . '</label>'
					. '<label><input type="radio" name="s[' . esc_attr( $key ) . ']" value="bad"' . checked( $val, 'bad', false ) . '> ✗ ' . esc_html__( 'problem', 'olivia-studio' ) . '</label>'
					. '<label><input type="radio" name="s[' . esc_attr( $key ) . ']" value=""' . checked( $val, '', false ) . '> ' . esc_html__( 'not yet', 'olivia-studio' ) . '</label>'
					. '<input type="text" name="n[' . esc_attr( $key ) . ']" value="' . esc_attr( $c['n'] ) . '" placeholder="' . esc_attr__( 'Note (what happened, on which device)', 'olivia-studio' ) . '"></div></li>';
			}
			echo '</ul>';
		}
		echo '<p class="oys-lab__save"><button class="button button-primary button-hero">' . esc_html__( 'Save the checklist', 'olivia-studio' ) . '</button></p></form>';
		echo '<p>' . self::form( 'round', '', __( 'Start a new round', 'olivia-studio' ), 'button', ' onsubmit="return confirm(\'' . esc_js( __( 'The ticks, the notes and the device results start empty (the numbers of this round are kept). Go on?', 'olivia-studio' ) ) . '\')"' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! empty( $st['rounds'] ) ) {
			echo '<p class="description">' . esc_html__( 'Earlier rounds:', 'olivia-studio' ) . ' ' . esc_html( implode( ' · ', array_map( fn( $r ) => sprintf( __( '%1$s: %2$d/%3$d works, %4$d problems', 'olivia-studio' ), wp_date( 'M j', (int) $r['at'] ), (int) $r['ok'], (int) $r['total'], (int) $r['bad'] ), (array) $st['rounds'] ) ) ) . '</p>';
		}
		echo '</section></div>';

		wp_enqueue_script( 'oys-qr', OYS_URL . 'assets/vendor/qrcode-generator.js', array(), '1.4.4', true );
		wp_enqueue_script( 'oys-live-lab', OYS_URL . 'assets/live-lab.js', array( 'oys-qr' ), OYS_VERSION, true );
		wp_localize_script( 'oys-live-lab', 'oysLab', array(
			'api'   => esc_url_raw( rest_url( 'oys/v1/live-lab' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'  => array(
				'nobody'   => __( 'Nobody is in the room.', 'olivia-studio' ),
				'in'       => __( 'In the room now: %d', 'olivia-studio' ),
				'teacher'  => __( 'teacher', 'olivia-studio' ),
				'since'    => __( 'since %s', 'olivia-studio' ),
				'cam'      => __( 'camera', 'olivia-studio' ),
				'mic'      => __( 'microphone', 'olivia-studio' ),
				'off'      => __( 'off', 'olivia-studio' ),
				'none'     => __( 'no camera, no microphone', 'olivia-studio' ),
				'mute'     => __( 'Mute', 'olivia-studio' ),
				'remove'   => __( 'Take out', 'olivia-studio' ),
				'muteAll'  => __( 'Mute everyone', 'olivia-studio' ),
				'hands'    => __( 'Raised hands: %s', 'olivia-studio' ),
				'chat'     => __( 'Chat messages: %d', 'olivia-studio' ),
				'recs'     => __( 'Recordings: %s', 'olivia-studio' ),
				'words'    => __( 'Opening words written down: %d characters', 'olivia-studio' ),
				'summary'  => __( 'Summary: %s', 'olivia-studio' ),
				'project'  => __( 'Rooms open in the LiveKit project now:', 'olivia-studio' ),
				'people'   => __( '%d people', 'olivia-studio' ),
				'copied'   => __( 'Copied', 'olivia-studio' ),
				'error'    => __( 'LiveKit did not answer: %s', 'olivia-studio' ),
				'updated'  => __( 'updated %s', 'olivia-studio' ),
				'state'    => array( 'recording' => __( 'recording', 'olivia-studio' ), 'finished' => __( 'saved', 'olivia-studio' ), 'empty' => __( 'empty', 'olivia-studio' ) ),
			),
		) );
		echo '<style>.oys-lab__lead{max-width:75ch;font-size:14px}.oys-lab__nav{display:flex;flex-wrap:wrap;gap:6px 16px;margin:10px 0 14px}.oys-lab__nav a{text-decoration:none;font-weight:600}.oys-lab__card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px 18px;margin:0 0 16px}.oys-lab__card h2{margin:0 0 6px}.oys-lab__inline{display:inline-block;margin:0 6px 6px 0}.oys-lab__ai{margin-right:8px}.oys-lab__when{color:#646970;margin:10px 0 4px}.oys-lab__steps{margin:0;padding:0;list-style:none}.oys-lab__steps li{display:grid;padding:7px 10px;border-left:3px solid #68b984;background:#f6fbf7;margin:0 0 4px;border-radius:0 8px 8px 0}.oys-lab__steps li.is-warn{border-color:#dba617;background:#fcf9e8}.oys-lab__steps li.is-bad{border-color:#d63638;background:#fcf0f1}.oys-lab__steps small{color:#50575e;overflow-wrap:anywhere}.oys-lab__rooms{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:14px}.oys-lab__room{border:1px solid #dcdcde;border-radius:12px;padding:14px}.oys-lab__room h3{margin:0 0 4px}.oys-lab__what{color:#50575e;margin:0 0 8px}.oys-lab__state{margin:0 0 8px;padding:6px 10px;border-radius:8px;background:#f0f0f1}.oys-lab__state.is-live{background:#e6f4ea}.oys-lab__state.is-open{background:#eef4fb}.oys-lab__state.is-waiting{background:#fcf9e8}.oys-lab__phases{margin:0 0 8px}.oys-lab__people{margin:8px 0 8px 18px}.oys-lab__people li{margin:0 0 6px}.oys-lab__people li>span:first-child{display:inline-block;min-width:9.5em}.oys-lab__qr{display:block;margin:6px 0}.oys-lab__qr img{width:180px;height:180px;image-rendering:pixelated}.oys-lab__live{border-top:1px solid #f0f0f1;padding-top:8px}.oys-lab__live ul{margin:4px 0;padding:0;list-style:none}.oys-lab__live li{padding:6px 0;border-bottom:1px dashed #f0f0f1}.oys-lab__live li small{display:block;color:#646970}.oys-lab__live .button-link{margin-left:8px}.oys-lab__project{margin-top:12px;color:#50575e}.oys-lab__devices td{vertical-align:top}.oys-lab__devices ul{margin:6px 0 0}.oys-lab__list{margin:0;padding:0;list-style:none}.oys-lab__list li{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:8px 16px;padding:10px 12px;border:1px solid #f0f0f1;border-left:4px solid #dcdcde;border-radius:0 8px 8px 0;margin:0 0 6px}.oys-lab__list li.is-ok{border-left-color:#68b984}.oys-lab__list li.is-bad{border-left-color:#d63638;background:#fcf0f1}.oys-lab__item{display:grid;gap:2px}.oys-lab__item small{color:#50575e}.oys-lab__by{font-style:italic}.oys-lab__tick label{margin-right:12px;white-space:nowrap}.oys-lab__tick input[type=text]{width:100%;margin-top:6px}.oys-lab__save{position:sticky;bottom:0;background:#fff;padding:10px 0;margin:0}.oys-diag{border:1px solid #dcdcde;border-radius:12px;padding:12px 14px;margin:0 0 12px}.oys-diag__h{margin:0 0 4px}.oys-diag__list{margin:8px 0 0 18px}.oys-diag__list li{margin:3px 0}.oys-diag__list .is-ok{color:#007017}.oys-diag__list .is-bad{color:#b32d2e}.oys-diag__list .is-skip{color:#646970}@media(max-width:782px){.oys-lab__list li{grid-template-columns:1fr}.oys-lab__rooms{grid-template-columns:1fr}.oys-lab__tick label{display:inline-block;margin:0 10px 6px 0}}</style>';
	}
}

OYS_Live_Lab::init();
