<?php
/**
 * The Hub: one screen for everything people write to the studio (Áron, 16 November: „az adminon belül kéne egy hub,
 * ahol látja a beszélgetéseket, akik beírnak neki chatbe, órai chatbe, meg arról kapjon értesítőt is az órai chatről,
 * … ahol valóban egybe tud kezelni mindent”).
 *
 * - Conversations (tables `threads` / `thread_msgs`): one per customer (or email address) and teacher. Customers write
 *   from their account (Messages tab), the website's contact form lands here, a private request adds its summary.
 *   The studio answers in the Hub and the customer gets the answer by email and in their account.
 * - Class chats (OYS_Live, `live_chat`): every class chat with its unread messages; the studio answers from the Hub.
 * - Images: in conversations and class chats (resized, random file names under uploads/oys-chat).
 * - Notifications: an email to the studio (or the class's teacher) about a new message or class chat message, at most
 *   one per conversation / class per quarter hour, none while someone has the Hub open, none for a class chat while
 *   the class is on.
 * - Teachers (role oys_teacher) see their own classes' chats and their own conversations.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Hub {

	const NS            = 'oys/v1';
	const IMAGE_MAX     = 10485760; // 10 MB upload
	const IMAGE_EDGE    = 1600;     // longest side after resizing
	const CHAT_DAYS     = 45;       // class chats listed in the Hub
	const NOTIFY_MINUTE = 15;       // one email per conversation / class per this many minutes

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'oys_live_chat_posted', array( __CLASS__, 'on_class_chat' ), 10, 3 );
		add_action( 'oys_private_requested', array( __CLASS__, 'on_private_request' ) );
		add_action( 'oys_contact_message', array( __CLASS__, 'on_contact' ) );
		add_action( 'admin_post_oys_msg', array( __CLASS__, 'handle_customer_post' ) );
	}

	public static function t( $name ) {
		return OYS_Install::table( $name );
	}

	/* ---------- Who sees what ---------- */

	/** The studio (manager) sees everything; a teacher their own classes and conversations. */
	public static function can( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		return $user_id && ( user_can( $user_id, 'oys_manage' ) || user_can( $user_id, 'oys_teach' ) );
	}

	/** null = everything (studio), else the teacher's id (0 when a teacher login has no teacher profile). */
	public static function scope( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( user_can( $user_id, 'oys_manage' ) ) {
			return null;
		}
		$t = OYS_Teachers::by_user( $user_id );
		return $t ? (int) $t->id : -1;
	}

	public static function can_thread( $thread, $user_id = 0 ) {
		$scope = self::scope( $user_id );
		return $thread && ( null === $scope || (int) $thread->teacher_id === $scope );
	}

	public static function can_session( $session, $user_id = 0 ) {
		$scope = self::scope( $user_id );
		return $session && ( null === $scope || (int) $session->teacher_id === $scope );
	}

	/* ---------- Conversations ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'threads' ) . ' WHERE id = %d', (int) $id ) );
	}

	public static function messages( $thread_id, $after = 0 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'thread_msgs' ) . ' WHERE thread_id = %d AND id > %d ORDER BY id ASC LIMIT 500', (int) $thread_id, (int) $after ) );
	}

	/** The customer's conversation with the studio (teacher 0) or a teacher; made when missing. */
	public static function thread_for( $user_id, $email = '', $name = '', $teacher_id = 0 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id && $email ) {
			$u       = get_user_by( 'email', $email );
			$user_id = $u ? (int) $u->ID : 0;
		}
		if ( $user_id ) {
			$u     = get_userdata( $user_id );
			$email = $u ? $u->user_email : $email;
			$name  = $u ? trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name : $name;
			$id    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'threads' ) . ' WHERE user_id = %d AND teacher_id = %d ORDER BY id LIMIT 1', $user_id, (int) $teacher_id ) );
			if ( ! $id && $email ) { // a contact-form conversation from before the account: it becomes theirs
				$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'threads' ) . ' WHERE user_id = 0 AND email = %s AND teacher_id = %d ORDER BY id LIMIT 1', $email, (int) $teacher_id ) );
				if ( $id ) {
					$wpdb->update( self::t( 'threads' ), array( 'user_id' => $user_id, 'name' => mb_substr( $name, 0, 190 ) ), array( 'id' => (int) $id ) );
				}
			}
		} else {
			$id = $email ? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'threads' ) . ' WHERE user_id = 0 AND email = %s AND teacher_id = %d ORDER BY id LIMIT 1', $email, (int) $teacher_id ) ) : 0;
		}
		if ( $id ) {
			return self::get( $id );
		}
		if ( ! $user_id && ! is_email( $email ) ) {
			return null;
		}
		$wpdb->insert( self::t( 'threads' ), array(
			'user_id'    => $user_id,
			'email'      => mb_substr( (string) $email, 0, 190 ),
			'name'       => mb_substr( (string) $name, 0, 190 ),
			'teacher_id' => (int) $teacher_id,
			'status'     => 'open',
			'last_at'    => oys_now(),
			'created_at' => oys_now(),
		) );
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * A message in a conversation. $m: body, image (path from save_image), from_studio, author_id, kind
	 * ('' | 'contact' | 'request' | 'note'), notify (default true). @return int|WP_Error the message id
	 */
	public static function add( $thread, array $m ) {
		$thread = is_object( $thread ) ? $thread : self::get( $thread );
		if ( ! $thread ) {
			return new WP_Error( 'oys_hub', __( 'This conversation no longer exists.', 'olivia-studio' ) );
		}
		$body  = trim( mb_substr( sanitize_textarea_field( (string) ( $m['body'] ?? '' ) ), 0, 5000 ) );
		$image = (string) ( $m['image'] ?? '' );
		if ( '' === $body && '' === $image ) {
			return new WP_Error( 'oys_hub', __( 'Write a message first.', 'olivia-studio' ) );
		}
		$studio = ! empty( $m['from_studio'] );
		$kind   = sanitize_key( $m['kind'] ?? '' );
		global $wpdb;
		$wpdb->insert( self::t( 'thread_msgs' ), array(
			'thread_id'   => (int) $thread->id,
			'author_id'   => (int) ( $m['author_id'] ?? get_current_user_id() ),
			'from_studio' => $studio ? 1 : 0,
			'body'        => $body,
			'image'       => $image,
			'kind'        => $kind,
			'created_at'  => oys_now(),
		) );
		$id   = (int) $wpdb->insert_id;
		$last = '' !== $body ? $body : '📷 ' . __( 'Photo', 'olivia-studio' );
		$row  = array( 'last_at' => oys_now(), 'last_from' => $studio ? 'studio' : 'customer', 'last_body' => mb_substr( preg_replace( '/\s+/', ' ', $last ), 0, 250 ) );
		if ( $studio && 'note' !== $kind ) {
			$row['unread_customer'] = 1;
			$row['unread_studio']   = 0;
		} elseif ( ! $studio ) {
			$row['unread_studio'] = 1;
			$row['status']        = 'open';
		}
		$wpdb->update( self::t( 'threads' ), $row, array( 'id' => (int) $thread->id ) );
		if ( $m['notify'] ?? true ) {
			$studio ? ( 'note' !== $kind && self::email_customer( self::get( $thread->id ), $body, $image ) ) : self::notify_studio( $thread, $body, $image );
		}
		do_action( 'oys_hub_message', $id, $thread, $studio );
		return $id;
	}

	public static function set_status( $thread_id, $status ) {
		global $wpdb;
		return (bool) $wpdb->update( self::t( 'threads' ), array( 'status' => 'done' === $status ? 'done' : 'open', 'unread_studio' => 0 ), array( 'id' => (int) $thread_id ) );
	}

	public static function mark_read( $thread_id, $side = 'studio' ) {
		global $wpdb;
		$wpdb->update( self::t( 'threads' ), array( 'studio' === $side ? 'unread_studio' : 'unread_customer' => 0 ), array( 'id' => (int) $thread_id ) );
	}

	/** The customer's own conversations (account → Messages). */
	public static function customer_threads( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'threads' ) . ' WHERE user_id = %d ORDER BY last_at DESC', (int) $user_id ) );
	}

	public static function customer_unread( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'threads' ) . ' WHERE user_id = %d AND unread_customer = 1', (int) $user_id ) );
	}

	/* ---------- Class chats ---------- */

	/** The studio's „read up to” message id per class chat (shared by everyone who answers). */
	private static function chat_seen() {
		$seen = get_option( 'oys_hub_chat_seen', array() );
		return is_array( $seen ) ? $seen : array();
	}

	public static function mark_chat_read( $session_id, $last_id ) {
		$seen = self::chat_seen();
		if ( (int) ( $seen[ (int) $session_id ] ?? 0 ) < (int) $last_id ) {
			$seen[ (int) $session_id ] = (int) $last_id;
			if ( count( $seen ) > 400 ) {
				$seen = array_slice( $seen, -300, null, true );
			}
			update_option( 'oys_hub_chat_seen', $seen, false );
		}
	}

	/** Class chats with messages in the last CHAT_DAYS days: [ session, last message, unread, count ]. */
	public static function class_chats( $user_id = 0 ) {
		global $wpdb;
		$c     = self::t( 'live_chat' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT session_id, MAX(id) AS last_id, COUNT(*) AS n FROM $c WHERE deleted = 0 AND created_at > %s GROUP BY session_id ORDER BY last_id DESC LIMIT 60", oys_utc_plus( -self::CHAT_DAYS * DAY_IN_SECONDS ) ) );
		$seen  = self::chat_seen();
		$out   = array();
		foreach ( $rows as $r ) {
			$s = OYS_Schedule::get( (int) $r->session_id );
			if ( ! $s || ! self::can_session( $s, $user_id ) ) {
				continue;
			}
			$last   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE id = %d", (int) $r->last_id ) );
			$unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $c WHERE session_id = %d AND deleted = 0 AND is_teacher = 0 AND id > %d", (int) $r->session_id, (int) ( $seen[ (int) $r->session_id ] ?? 0 ) ) );
			$out[]  = array( 'session' => $s, 'last' => $last, 'unread' => $unread, 'count' => (int) $r->n );
		}
		return $out;
	}

	/** Posts in a class chat as the teacher / studio (from the Hub). @return int|WP_Error */
	public static function post_to_class( $session, $body, $image = '' ) {
		if ( ! self::can_session( $session ) ) {
			return new WP_Error( 'oys_hub', __( 'You can\'t write in this class chat.', 'olivia-studio' ) );
		}
		$u   = wp_get_current_user();
		$who = array( 'owner' => true, 'booking' => null, 'user_id' => (int) $u->ID, 'name' => self::chat_name( $u->ID ) );
		$id  = OYS_Live::post_message( $session, $who, $body, $image );
		if ( ! is_wp_error( $id ) ) {
			self::mark_chat_read( $session->id, $id );
			self::email_chat_reply( $session, $body, $image );
		}
		return $id;
	}

	/** The name the studio writes under in class chats: the teacher's own setting, else their first name. */
	public static function chat_name( $user_id ) {
		$p = self::prefs( $user_id );
		if ( '' !== $p['chat_name'] ) {
			return $p['chat_name'];
		}
		$u = get_userdata( $user_id );
		return $u ? ( $u->first_name ?: $u->display_name ) : '';
	}

	/* ---------- The teacher's own settings (Hub → Settings) ---------- */

	public static function prefs( $user_id = 0 ) {
		$p = get_user_meta( $user_id ?: get_current_user_id(), 'oys_hub_prefs', true );
		$p = is_array( $p ) ? $p : array();
		return array(
			'email_messages' => isset( $p['email_messages'] ) ? (int) $p['email_messages'] : 1,
			'email_chat'     => isset( $p['email_chat'] ) ? (int) $p['email_chat'] : 1,
			'chat_name'      => (string) ( $p['chat_name'] ?? '' ),
			'replies'        => array_values( array_filter( array_map( 'strval', (array) ( $p['replies'] ?? self::default_replies() ) ) ) ),
			'signature'      => (string) ( $p['signature'] ?? '' ),
			// pushes to the phone app (OYS_Hub_Auto): bookings/cancellations/waitlist, „who came?” after class, the morning summary
			'push_bookings'  => isset( $p['push_bookings'] ) ? (int) $p['push_bookings'] : 1,
			'push_sheet'     => isset( $p['push_sheet'] ) ? (int) $p['push_sheet'] : 1,
			'push_daily'     => isset( $p['push_daily'] ) ? (int) $p['push_daily'] : 1,
			// everything else on the website (OYS_Activity): new accounts, payments, newsletter signups, call-back requests…
			'push_activity'  => isset( $p['push_activity'] ) ? (int) $p['push_activity'] : 1,
		);
	}

	public static function default_replies() {
		return array(
			__( 'Thank you for your message! I\'ll get back to you soon.', 'olivia-studio' ),
			__( 'Thank you for coming to class today, see you next time!', 'olivia-studio' ),
			__( 'You\'ll find the link to join in your account, under Bookings.', 'olivia-studio' ),
		);
	}

	public static function save_prefs( $user_id, array $in ) {
		$old     = self::prefs( $user_id );
		$keep    = fn( $k ) => array_key_exists( $k, $in ) ? ( empty( $in[ $k ] ) ? 0 : 1 ) : $old[ $k ];
		$replies = array_slice( array_values( array_filter( array_map( fn( $r ) => mb_substr( sanitize_textarea_field( (string) $r ), 0, 500 ), (array) ( $in['replies'] ?? array() ) ) ) ), 0, 12 );
		update_user_meta( $user_id, 'oys_hub_prefs', array(
			'email_messages' => empty( $in['email_messages'] ) ? 0 : 1,
			'email_chat'     => empty( $in['email_chat'] ) ? 0 : 1,
			'chat_name'      => mb_substr( sanitize_text_field( (string) ( $in['chat_name'] ?? '' ) ), 0, 60 ),
			'replies'        => $replies,
			'signature'      => mb_substr( sanitize_textarea_field( (string) ( $in['signature'] ?? '' ) ), 0, 300 ),
			'push_bookings'  => $keep( 'push_bookings' ),
			'push_sheet'     => $keep( 'push_sheet' ),
			'push_daily'     => $keep( 'push_daily' ),
			'push_activity'  => $keep( 'push_activity' ),
		) );
		return self::prefs( $user_id );
	}

	/* ---------- Images ---------- */

	public static function image_dir() {
		$up = wp_upload_dir( null, false );
		return array( trailingslashit( $up['basedir'] ) . 'oys-chat', trailingslashit( $up['baseurl'] ) . 'oys-chat' );
	}

	public static function image_url( $rel ) {
		if ( '' === (string) $rel ) {
			return '';
		}
		return self::image_dir()[1] . '/' . ltrim( $rel, '/' );
	}

	/**
	 * An uploaded photo ($_FILES entry) → resized JPEG (longest side 1600 px, without the camera's data) under
	 * uploads/oys-chat/YYYY/MM with a random name. @return string|WP_Error the path relative to oys-chat
	 */
	public static function save_image( $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'oys_img', __( 'The photo could not be uploaded.', 'olivia-studio' ) );
		}
		if ( (int) $file['size'] > self::IMAGE_MAX ) {
			return new WP_Error( 'oys_img', __( 'The photo is too large (10 MB at most).', 'olivia-studio' ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic' ) );
		$info  = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $check['type'] && ! $info ) {
			return new WP_Error( 'oys_img', __( 'Only photos can be attached (JPG, PNG, WebP, GIF).', 'olivia-studio' ) );
		}
		list( $dir ) = self::image_dir();
		$sub         = gmdate( 'Y/m' );
		wp_mkdir_p( $dir . '/' . $sub );
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$name   = bin2hex( random_bytes( 16 ) );
		$editor = wp_get_image_editor( $file['tmp_name'] );
		if ( ! is_wp_error( $editor ) ) {
			$size = $editor->get_size();
			if ( max( (int) $size['width'], (int) $size['height'] ) > self::IMAGE_EDGE ) {
				$editor->resize( self::IMAGE_EDGE, self::IMAGE_EDGE, false );
			}
			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$editor->maybe_exif_rotate();
			}
			$editor->set_quality( 80 );
			$saved = $editor->save( $dir . '/' . $sub . '/' . $name . '.jpg', 'image/jpeg' );
			if ( ! is_wp_error( $saved ) ) {
				return $sub . '/' . $name . '.jpg';
			}
		}
		// no image library could read it (e.g. HEIC without Imagick): keep a GIF/PNG/JPEG/WebP as it is
		$ext = array( IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' )[ $info[2] ?? 0 ] ?? '';
		if ( ! $ext ) {
			return new WP_Error( 'oys_img', __( 'This photo format can\'t be opened. Try a JPG or PNG.', 'olivia-studio' ) );
		}
		if ( ! @copy( $file['tmp_name'], $dir . '/' . $sub . '/' . $name . '.' . $ext ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'oys_img', __( 'The photo could not be saved.', 'olivia-studio' ) );
		}
		return $sub . '/' . $name . '.' . $ext;
	}

	/** How much the chat photos take now (bytes, number of files): Settings shows it with the free disk space. */
	public static function image_usage() {
		list( $dir ) = self::image_dir();
		$bytes       = 0;
		$n           = 0;
		if ( is_dir( $dir ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
				if ( $f->isFile() && 'index.php' !== $f->getFilename() ) {
					$bytes += $f->getSize();
					$n++;
				}
			}
		}
		return array( $bytes, $n );
	}

	/* ---------- Notifications ---------- */

	/** Someone with the Hub open: no emails about what they see anyway. */
	private static function hub_open( $teacher_id ) {
		return (bool) get_transient( 'oys_hub_active_' . ( null === $teacher_id ? 's' : (int) $teacher_id ) );
	}

	public static function mark_active() {
		$scope = self::scope();
		set_transient( 'oys_hub_active_' . ( null === $scope ? 's' : (int) $scope ), 1, 2 * MINUTE_IN_SECONDS );
	}

	/** Who hears about a conversation or a class: [ email, user id or 0, teacher id or null ]. */
	private static function recipient( $teacher_id ) {
		$t = (int) $teacher_id > 0 ? OYS_Teachers::get( (int) $teacher_id ) : null;
		if ( $t && $t->email ) {
			return array( $t->email, (int) $t->user_id, (int) $t->id );
		}
		return array( (string) OYS_Settings::get( 'notify_email' ), 0, null );
	}

	private static function notify_studio( $thread, $body, $image ) {
		list( $to, $uid, $tid ) = self::recipient( $thread->teacher_id );
		if ( ! $to || self::hub_open( $tid ) || ( $uid && ! self::prefs( $uid )['email_messages'] ) || ( ! $uid && ! OYS_Email_Templates::enabled( 'studio_message' ) ) ) {
			return;
		}
		$key = 'oys_hub_n_t' . (int) $thread->id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::NOTIFY_MINUTE * MINUTE_IN_SECONDS );
		$who  = $thread->name ?: $thread->email;
		$html = '<p><b>' . esc_html( $who ) . '</b>' . ( $thread->email ? ' · <a href="mailto:' . esc_attr( $thread->email ) . '">' . esc_html( $thread->email ) . '</a>' : '' ) . '</p>'
			. self::quote_html( $body, $image );
		OYS_Emails::send( $to, sprintf( __( 'New message: %s', 'olivia-studio' ), $who ), __( 'New message', 'olivia-studio' ), $html, array(), array( __( 'Answer in the Hub', 'olivia-studio' ), self::hub_url( 't' . (int) $thread->id, (bool) $uid && ! user_can( $uid, 'oys_manage' ) ) ) );
	}

	/** A participant wrote in a class chat: an email to the teacher / studio, not while the class is on. */
	public static function on_class_chat( $message_id, $session, $who ) {
		if ( ! empty( $who['owner'] ) || ! empty( $who['test'] ) ) {
			return;
		}
		$start = oys_ts( $session->starts_at ) - 15 * MINUTE_IN_SECONDS;
		if ( time() >= $start && time() <= oys_ts( $session->ends_at ) ) {
			return; // she's teaching: the chat is on her screen
		}
		list( $to, $uid, $tid ) = self::recipient( $session->teacher_id );
		if ( ! $to || self::hub_open( $tid ) || ( $uid && ! self::prefs( $uid )['email_chat'] ) || ( ! $uid && ! OYS_Email_Templates::enabled( 'studio_class_chat' ) ) ) {
			return;
		}
		$key = 'oys_hub_n_c' . (int) $session->id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::NOTIFY_MINUTE * MINUTE_IN_SECONDS );
		global $wpdb;
		$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'live_chat' ) . ' WHERE id = %d', (int) $message_id ) );
		$html = '<p>' . esc_html( sprintf( __( '%1$s wrote in the chat of %2$s.', 'olivia-studio' ), $who['name'], oys_session_title( $session ) . ', ' . oys_date( $session->starts_at, 'M j' ) ) ) . '</p>'
			. self::quote_html( $m ? $m->body : '', $m ? (string) $m->image : '' );
		OYS_Emails::send( $to, sprintf( __( 'Class chat: %1$s – %2$s', 'olivia-studio' ), oys_session_title( $session ), $who['name'] ), __( 'New message in a class chat', 'olivia-studio' ), $html, array(), array( __( 'Answer in the Hub', 'olivia-studio' ), self::hub_url( 'c' . (int) $session->id, (bool) $uid && ! user_can( $uid, 'oys_manage' ) ) ) );
	}

	private static function quote_html( $body, $image ) {
		return ( '' !== (string) $body ? '<div style="margin:0 0 14px;padding:12px 14px;border-radius:12px;background:#F4F1EC;white-space:pre-wrap">' . nl2br( esc_html( $body ) ) . '</div>' : '' )
			. ( $image ? '<p><img src="' . esc_url( self::image_url( $image ) ) . '" alt="" style="max-width:100%;border-radius:12px"></p>' : '' );
	}

	/** The studio answered: the customer gets it by email (and sees it in their account). */
	private static function email_customer( $thread, $body, $image ) {
		$to = $thread->user_id ? ( get_userdata( $thread->user_id )->user_email ?? $thread->email ) : $thread->email;
		if ( ! $to ) {
			return;
		}
		$send = function () use ( $thread, $body, $image, $to ) {
			if ( ! OYS_Email_Templates::enabled( 'message_reply' ) ) {
				return;
			}
			$vars = OYS_Email_Templates::vars_for( (int) $thread->user_id );
			if ( empty( $vars['first_name'] ) ) {
				$vars['first_name'] = $thread->name ? explode( ' ', $thread->name )[0] : '';
			}
			$vars['teacher'] = (int) $thread->teacher_id && OYS_Teachers::get( $thread->teacher_id ) ? OYS_Teachers::get( $thread->teacher_id )->name : (string) OYS_Settings::get( 'owner_name' );
			OYS_Emails::message_reply_mail( $to, $vars, self::quote_html( $body, $image ), $thread->user_id ? oys_account_url( 'messages' ) : '' );
		};
		$thread->user_id ? OYS_I18n::with( OYS_I18n::lang_for( (int) $thread->user_id ), $send ) : $send();
	}

	/** The studio answered in a class chat from the Hub after the class: the people who wrote there hear about it. */
	private static function email_chat_reply( $session, $body, $image ) {
		if ( time() <= oys_ts( $session->ends_at ) || ! OYS_Email_Templates::enabled( 'chat_reply' ) ) {
			return;
		}
		global $wpdb;
		$users = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM ' . self::t( 'live_chat' ) . ' WHERE session_id = %d AND is_teacher = 0 AND deleted = 0 AND user_id > 0', (int) $session->id ) );
		foreach ( $users as $uid ) {
			$key = 'oys_hub_r_' . (int) $session->id . '_' . (int) $uid;
			if ( get_transient( $key ) || ! get_userdata( (int) $uid ) ) {
				continue;
			}
			set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );
			$booking = OYS_Bookings::active_for( (int) $uid, $session->id );
			OYS_I18n::with( OYS_I18n::lang_for( (int) $uid ), function () use ( $uid, $session, $body, $image, $booking ) {
				OYS_Emails::chat_reply_mail( get_userdata( (int) $uid )->user_email, OYS_Email_Templates::vars_for( (int) $uid, $session ), self::quote_html( $body, $image ), OYS_Live::url( $session->id, $booking ? (int) $booking->id : 0 ) );
			} );
		}
	}

	/* ---------- Where messages come from ---------- */

	/** A private request: its summary goes into the customer's conversation, so the whole story is in one place. */
	public static function on_private_request( $request ) {
		if ( ! $request || ! $request->user_id ) {
			return;
		}
		$thread = self::thread_for( (int) $request->user_id );
		if ( ! $thread ) {
			return;
		}
		$lines = array( __( 'Private session request', 'olivia-studio' ) );
		foreach ( OYS_Privates::summary( $request ) as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		// the studio gets its own email about the request already (OYS_Emails::private_request_received)
		self::add( $thread, array( 'body' => implode( "\n", $lines ), 'from_studio' => false, 'author_id' => (int) $request->user_id, 'kind' => 'request', 'notify' => false ) );
		global $wpdb;
		$wpdb->update( self::t( 'thread_msgs' ), array( 'ref_id' => (int) $request->id ), array( 'thread_id' => (int) $thread->id, 'kind' => 'request', 'ref_id' => 0 ) );
	}

	/** The website's contact form (theme): the message also lands in the Hub. $m: name, email, phone, topic, message. */
	public static function on_contact( $m ) {
		$email  = sanitize_email( (string) ( $m['email'] ?? '' ) );
		$thread = self::thread_for( 0, $email, sanitize_text_field( (string) ( $m['name'] ?? '' ) ) );
		if ( ! $thread ) {
			return;
		}
		if ( ! empty( $m['phone'] ) && ! $thread->user_id ) {
			global $wpdb;
			$wpdb->update( self::t( 'threads' ), array( 'phone' => mb_substr( sanitize_text_field( (string) $m['phone'] ), 0, 40 ) ), array( 'id' => (int) $thread->id ) );
		}
		$body = trim( ( ! empty( $m['topic'] ) ? $m['topic'] . "\n\n" : '' ) . (string) ( $m['message'] ?? '' ) );
		// the contact form sends its own email to the studio
		self::add( $thread, array( 'body' => $body, 'from_studio' => false, 'author_id' => (int) $thread->user_id, 'kind' => 'contact', 'notify' => false ) );
	}

	/** Account → Messages: the customer writes (with an optional photo). */
	public static function handle_customer_post() {
		check_admin_referer( 'oys_msg' );
		$user_id = get_current_user_id();
		$back    = oys_account_url( 'messages' );
		if ( ! $user_id ) {
			oys_redirect( $back );
		}
		$teacher = max( 0, (int) ( $_POST['teacher'] ?? 0 ) );
		$thread  = self::thread_for( $user_id, '', '', $teacher && OYS_Teachers::get( $teacher ) ? $teacher : 0 );
		$image  = '';
		if ( ! empty( $_FILES['image']['name'] ) ) {
			$image = self::save_image( $_FILES['image'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $image ) ) {
				oys_flash( $image->get_error_message(), 'error' );
				oys_redirect( $back );
			}
		}
		$flood = 'oys_msg_' . $user_id;
		if ( get_transient( $flood ) ) {
			oys_flash( __( 'One moment, then send it again.', 'olivia-studio' ), 'error' );
			oys_redirect( $back );
		}
		set_transient( $flood, 1, 5 );
		$id = $thread ? self::add( $thread, array( 'body' => wp_unslash( $_POST['body'] ?? '' ), 'image' => $image, 'from_studio' => false, 'author_id' => $user_id ) ) : new WP_Error( 'oys_hub', 'x' );
		is_wp_error( $id ) ? oys_flash( $id->get_error_message(), 'error' ) : oys_flash( __( 'Message sent. You\'ll get the answer by email and here.', 'olivia-studio' ) );
		oys_redirect( $back . '#oys-msg-end' );
	}

	/* ---------- For the screens ---------- */

	/** The Hub (the studio's, or a teacher's own under Teaching), opened at a conversation „t12” or class chat „c34”. */
	public static function hub_url( $open = '', $teacher = false ) {
		return admin_url( 'admin.php?page=' . ( $teacher ? 'oys-hub' : 'oys' ) . ( $open ? '#' . $open : '' ) );
	}

	/** Unread items for the menu badge: conversations waiting for an answer + unread class chat messages. */
	public static function unread_count( $user_id = 0 ) {
		global $wpdb;
		$scope = self::scope( $user_id );
		$n     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'threads' ) . ' WHERE unread_studio = 1' . ( null === $scope ? '' : $wpdb->prepare( ' AND teacher_id = %d', $scope ) ) );
		foreach ( self::class_chats( $user_id ) as $c ) {
			$n += $c['unread'] ? 1 : 0;
		}
		return $n;
	}

	private static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$i     = mb_strtoupper( mb_substr( $parts[0] ?? '', 0, 1 ) . mb_substr( $parts[1] ?? '', 0, 1 ) );
		return '' !== $i ? $i : '?';
	}

	private static function ago( $utc ) {
		$ts = oys_ts( $utc );
		if ( wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d' ) ) {
			return oys_time( $utc );
		}
		return time() - $ts < 6 * DAY_IN_SECONDS ? oys_date( $utc, 'D' ) : oys_date( $utc, 'M j' );
	}

	/** Every conversation, class chat and open request in one list, newest first. */
	public static function inbox( $filter = 'all', $q = '' ) {
		global $wpdb;
		$scope = self::scope();
		$items = array();
		if ( in_array( $filter, array( 'all', 'unread', 'messages', 'done' ), true ) ) {
			$where = null === $scope ? '1=1' : $wpdb->prepare( 'teacher_id = %d', $scope );
			$where .= 'done' === $filter ? " AND status = 'done'" : ( 'unread' === $filter ? ' AND unread_studio = 1' : " AND status <> 'done'" );
			if ( '' !== $q ) {
				$like   = '%' . $wpdb->esc_like( $q ) . '%';
				$where .= $wpdb->prepare( ' AND ( name LIKE %s OR email LIKE %s OR last_body LIKE %s )', $like, $like, $like );
			}
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t( 'threads' ) . " WHERE $where ORDER BY last_at DESC LIMIT 200" ) as $t ) { // phpcs:ignore
				$items[] = array(
					'key'     => 't' . $t->id,
					'type'    => 'thread',
					'title'   => $t->name ?: $t->email,
					'initial' => self::initials( $t->name ?: $t->email ),
					'sub'     => ( 'studio' === $t->last_from ? __( 'You:', 'olivia-studio' ) . ' ' : '' ) . $t->last_body,
					'time'    => self::ago( $t->last_at ),
					'ts'      => oys_ts( $t->last_at ),
					'unread'  => (int) $t->unread_studio,
					'done'    => 'done' === $t->status,
					'tag'     => $t->user_id ? '' : __( 'Website', 'olivia-studio' ),
				);
			}
		}
		if ( in_array( $filter, array( 'all', 'unread', 'chats' ), true ) ) {
			foreach ( self::class_chats() as $c ) {
				if ( 'unread' === $filter && ! $c['unread'] ) {
					continue;
				}
				$s     = $c['session'];
				$title = oys_date( $s->starts_at, 'M j' ) . ' · ' . oys_session_title( $s );
				if ( '' !== $q && false === mb_stripos( $title . ' ' . $c['last']->body . ' ' . $c['last']->name, $q ) ) {
					continue;
				}
				$items[] = array(
					'key'     => 'c' . $s->id,
					'type'    => 'chat',
					'title'   => $title,
					'initial' => '💬',
					'sub'     => $c['last']->name . ': ' . ( '' !== (string) $c['last']->body ? $c['last']->body : '📷 ' . __( 'Photo', 'olivia-studio' ) ),
					'time'    => self::ago( $c['last']->created_at ),
					'ts'      => oys_ts( $c['last']->created_at ),
					'unread'  => (int) $c['unread'],
					'tag'     => __( 'Class chat', 'olivia-studio' ),
				);
			}
		}
		if ( in_array( $filter, array( 'all', 'requests' ), true ) && null === $scope ) {
			foreach ( OYS_Privates::query( 'new' ) as $r ) {
				$u    = get_userdata( (int) $r->user_id );
				$name = $u ? trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name : '';
				if ( '' !== $q && false === mb_stripos( $name, $q ) ) {
					continue;
				}
				$items[] = array(
					'key'     => 'r' . $r->id,
					'type'    => 'request',
					'title'   => $name,
					'initial' => self::initials( $name ),
					'sub'     => OYS_Privates::topic_label( $r->topic ?? '', (int) $r->people ) . ', ' . (int) $r->duration_min . ' min' . ( ! empty( $r->slot_at ) ? ' · ' . oys_date( $r->slot_at, 'M j, g:i a' ) : '' ),
					'time'    => self::ago( $r->created_at ),
					'ts'      => oys_ts( $r->created_at ) - 1,
					'unread'  => 1,
					'tag'     => __( 'Private request', 'olivia-studio' ),
					'url'     => admin_url( 'admin.php?page=oys-private&request=' . (int) $r->id ),
				);
			}
		}
		usort( $items, fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
		return $items;
	}

	public static function counts() {
		$scope   = self::scope();
		global $wpdb;
		$threads = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'threads' ) . ' WHERE unread_studio = 1' . ( null === $scope ? '' : $wpdb->prepare( ' AND teacher_id = %d', $scope ) ) );
		$chats   = 0;
		$chat_n  = 0;
		foreach ( self::class_chats() as $c ) {
			$chats  += $c['unread'] ? 1 : 0;
			$chat_n += $c['unread'];
		}
		$requests = null === $scope ? count( OYS_Privates::query( 'new' ) ) : 0;
		return array( 'messages' => $threads, 'chats' => $chats, 'chat_messages' => $chat_n, 'requests' => $requests, 'total' => $threads + $chats + $requests );
	}

	public static function shape_thread_msg( $m ) {
		$author = $m->from_studio && $m->author_id ? get_userdata( (int) $m->author_id ) : null;
		return array(
			'id'      => (int) $m->id,
			'studio'  => (bool) $m->from_studio,
			'who'     => $author ? ( $author->first_name ?: $author->display_name ) : '',
			'body'    => (string) $m->body,
			'image'   => self::image_url( (string) $m->image ),
			'kind'    => (string) $m->kind,
			'time'    => oys_date( $m->created_at, 'M j, g:i a' ),
			'request' => 'request' === $m->kind && (int) ( $m->ref_id ?? 0 ) ? admin_url( 'admin.php?page=oys-private&request=' . (int) $m->ref_id ) : '',
		);
	}

	public static function shape_chat_msg( $m ) {
		return array(
			'id'      => (int) $m->id,
			'studio'  => (bool) $m->is_teacher,
			'who'     => (string) $m->name,
			'body'    => (string) $m->body,
			'image'   => self::image_url( (string) ( $m->image ?? '' ) ),
			'time'    => oys_date( $m->created_at, 'M j, g:i a' ),
		);
	}

	/** Everything about a customer on one card: contact, health note, passes, next classes, requests. */
	public static function customer_card( $user_id, $thread = null ) {
		$u = $user_id ? get_userdata( (int) $user_id ) : null;
		if ( ! $u ) {
			return $thread ? array( 'name' => $thread->name, 'email' => $thread->email, 'phone' => (string) ( $thread->phone ?? '' ), 'guest' => true ) : null;
		}
		$passes = array();
		foreach ( OYS_Passes::for_user( $u->ID, true ) as $p ) {
			$passes[] = array( 'name' => $p->name, 'left' => (int) $p->credits_left, 'total' => (int) $p->credits_total, 'expires' => $p->expires_at ? oys_date( $p->expires_at, 'M j, Y' ) : '' );
		}
		$next = array();
		foreach ( array_slice( OYS_Bookings::for_user( $u->ID, 'upcoming', array( 'confirmed' ) ), 0, 4 ) as $b ) {
			$next[] = array( 'title' => $b->title ?: oys_class_title( $b->class_slug ), 'when' => oys_date( $b->starts_at, 'D M j, g:i a' ) );
		}
		$requests = array();
		foreach ( array_slice( array_filter( OYS_Privates::query( '' ), fn( $r ) => (int) $r->user_id === (int) $u->ID ), 0, 3 ) as $r ) {
			$requests[] = array( 'label' => OYS_Privates::topic_label( $r->topic ?? '', (int) $r->people ) . ', ' . (int) $r->duration_min . ' min', 'status' => OYS_Privates::statuses()[ $r->status ] ?? $r->status, 'url' => admin_url( 'admin.php?page=oys-private&request=' . (int) $r->id ) );
		}
		$visits = count( OYS_Bookings::for_user( $u->ID, 'past', array( 'attended', 'confirmed' ) ) );
		return array(
			'id'       => (int) $u->ID,
			'name'     => trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name,
			'email'    => $u->user_email,
			'phone'    => (string) get_user_meta( $u->ID, 'oys_phone', true ),
			'health'   => (string) get_user_meta( $u->ID, 'oys_health_notes', true ),
			'since'    => oys_date( $u->user_registered, 'M j, Y' ),
			'visits'   => $visits,
			'passes'   => $passes,
			'held'     => self::can_manage() ? OYS_Held::card_lines( $u->ID ) : array(),
			'next'     => $next,
			'requests' => $requests,
			'url'      => self::can_manage() ? admin_url( 'admin.php?page=oys-customers&user=' . (int) $u->ID ) : '',
		);
	}

	public static function can_manage() {
		return current_user_can( 'oys_manage' );
	}

	/** The Today tab: the next classes (today and tomorrow, else the next three), the numbers, what needs her. */
	public static function today() {
		global $wpdb;
		$scope = self::scope();
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'today', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$list  = OYS_Schedule::query( array( 'from' => $start->format( 'Y-m-d H:i:s' ), 'to' => $start->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), 'status' => 'scheduled' ) );
		$list  = array_values( array_filter( $list, fn( $s ) => oys_ts( $s->ends_at ) > time() - HOUR_IN_SECONDS && self::can_session( $s ) ) );
		if ( count( $list ) < 3 ) {
			$more = array_filter( OYS_Schedule::query( array( 'from' => $start->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), 'to' => oys_utc_plus( 21 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) ), fn( $s ) => self::can_session( $s ) );
			$list = array_merge( $list, array_slice( array_values( $more ), 0, 3 - count( $list ) ) );
		}
		$classes = array();
		foreach ( $list as $s ) {
			$people = array();
			foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed', 'attended', 'no_show' ) ) as $b ) {
				$health   = $b->guest_of ? '' : (string) get_user_meta( $b->user_id, 'oys_health_notes', true );
				$people[] = array( 'name' => OYS_Bookings::person_label( $b ), 'user' => $b->guest_of ? 0 : (int) $b->user_id, 'health' => $health, 'here' => 'attended' === $b->status );
			}
			$online    = oys_has_online( $s );
			$classes[] = array(
				'id'       => (int) $s->id,
				'title'    => oys_session_title( $s ),
				'day'      => wp_date( 'Y-m-d', oys_ts( $s->starts_at ) ) === wp_date( 'Y-m-d' ) ? __( 'Today', 'olivia-studio' ) : ( wp_date( 'Y-m-d', oys_ts( $s->starts_at ) ) === wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ? __( 'Tomorrow', 'olivia-studio' ) : oys_date( $s->starts_at, 'D M j' ) ),
				'time'     => oys_time( $s->starts_at ) . '–' . oys_time( $s->ends_at ),
				'booked'   => (int) $s->booked + (int) $s->online_booked,
				'capacity' => oys_is_online( $s ) ? (int) $s->capacity : (int) $s->capacity + (int) $s->online_capacity,
				'kind'     => (string) $s->kind,
				'now'      => time() >= oys_ts( $s->starts_at ) - 15 * MINUTE_IN_SECONDS && time() <= oys_ts( $s->ends_at ),
				'live'     => $online && OYS_Live::uses_live( $s ) ? OYS_Live::url( $s->id ) : ( $online ? (string) $s->online_url : '' ),
				'roster'   => admin_url( 'admin.php?page=' . ( self::can_manage() ? 'oys-schedule' : 'oys-teach' ) . '&session=' . (int) $s->id ),
				'people'   => $people,
				'waitlist' => count( OYS_Bookings::waitlist( $s->id ) ),
			);
		}
		$out = array( 'classes' => $classes, 'counts' => self::counts(), 'tomark' => class_exists( 'OYS_Hub_Classes' ) ? OYS_Hub_Classes::to_mark() : array() );
		// the studio's reminders due now or this week (Áron, 26 November: „legyen egy emlékeztető, hogy júliusban sorsolnia kell”)
		$out['notes'] = class_exists( 'OYS_Notes' ) && self::can_manage() ? OYS_Notes::soon( get_current_user_id() ) : array();
		if ( null === $scope ) {
			$o    = self::t( 'orders' );
			$rev  = 0;
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT amount_cents, meta FROM $o WHERE status IN ('paid','partially_refunded') AND paid_at >= %s", oys_utc_plus( -30 * DAY_IN_SECONDS ) ) ) as $row ) {
				$meta = json_decode( (string) $row->meta, true ) ?: array();
				$rev += empty( $meta['stripe_account'] ) ? (int) $row->amount_cents : (int) ( $meta['app_fee_cents'] ?? 0 );
			}
			$week = OYS_Schedule::query( array( 'from' => oys_now(), 'to' => oys_utc_plus( 7 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) );
			$out['kpis'] = array(
				array( __( 'Revenue, 30 days', 'olivia-studio' ), oys_money( $rev ) ),
				array( __( 'Booked, next 7 days', 'olivia-studio' ), array_sum( array_map( fn( $s ) => (int) $s->booked + (int) $s->online_booked, $week ) ) . ' / ' . array_sum( array_map( fn( $s ) => oys_is_online( $s ) ? (int) $s->capacity : (int) $s->capacity + (int) $s->online_capacity, $week ) ) ),
				array( __( 'Customers', 'olivia-studio' ), count( get_users( array( 'role' => 'oys_customer', 'fields' => 'ID' ) ) ) ),
			);
			$recent = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $o WHERE status IN ('paid','partially_refunded') AND paid_at >= %s ORDER BY paid_at DESC LIMIT 5", oys_utc_plus( -14 * DAY_IN_SECONDS ) ) ) as $row ) {
				$u        = get_userdata( (int) $row->user_id );
				$recent[] = array( 'who' => $u ? ( trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ) : (string) $row->email, 'what' => (string) $row->description, 'amount' => oys_money( (int) $row->amount_cents, $row->currency ), 'when' => self::ago( $row->paid_at ) );
			}
			$out['payments'] = $recent;
			$out['passes']   = self::passes_summary();
		}
		return $out;
	}

	/**
	 * The passes at a glance on the Hub's Today (Áron, 16 November: „kell egy a hubnak a főoldalára egy összesítő, hogy
	 * mennyi érvényes bérlet van még, meg annyi zárolt, meg ilyenek”). Valid = sessions left and not expired; locked =
	 * sessions already taken from a pass for a class still ahead (they come back if the booking is cancelled in time).
	 * @return array[] { icon, label, value, note }
	 */
	public static function passes_summary() {
		global $wpdb;
		$p   = self::t( 'passes' );
		$b   = self::t( 'bookings' );
		$s   = self::t( 'sessions' );
		$now = oys_now();
		$tally = array( 'group' => array( 'n' => 0, 'c' => 0 ), 'one' => array( 'n' => 0, 'c' => 0 ) );
		$people = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $p WHERE credits_left > 0 AND ( expires_at IS NULL OR expires_at > %s )", $now ) );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) n, SUM(credits_left) c FROM $p WHERE credits_left > 0 AND ( expires_at IS NULL OR expires_at > %s ) GROUP BY kind", $now ) ) as $r ) {
			$k                  = in_array( $r->kind, array( 'class', 'online' ), true ) ? 'group' : 'one';
			$tally[ $k ]['n'] += (int) $r->n;
			$tally[ $k ]['c'] += (int) $r->c;
		}
		list( $group, $one ) = array( $tally['group'], $tally['one'] );
		$locked  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.status = 'confirmed' AND b.paid_with = 'credit' AND s.status = 'scheduled' AND s.starts_at > %s", $now ) );
		$ahead   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.status = 'confirmed' AND s.status = 'scheduled' AND s.starts_at > %s", $now ) );
		$holds   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE status = 'pending' AND hold_expires IS NOT NULL AND hold_expires > %s", $now ) );
		$soon    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(credits_left), 0) c FROM $p WHERE credits_left > 0 AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s", $now, oys_utc_plus( 14 * DAY_IN_SECONDS ) ) );
		$lapsed  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(credits_left), 0) c FROM $p WHERE credits_left > 0 AND expires_at IS NOT NULL AND expires_at <= %s AND expires_at > %s", $now, oys_utc_plus( -30 * DAY_IN_SECONDS ) ) );
		$members = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'memberships' ) . " WHERE status IN ('active','trialing','past_due')" );
		$gifts   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'gift_cards' ) . " WHERE status = 'active'" );
		$sess    = fn( $c ) => sprintf( _n( '%d session', '%d sessions', $c, 'olivia-studio' ), $c );
		$pass    = fn( $c ) => sprintf( _n( '%d pass', '%d passes', $c, 'olivia-studio' ), $c );
		$cust    = fn( $c ) => sprintf( _n( '%d customer', '%d customers', $c, 'olivia-studio' ), $c );
		$rows    = array(
			array( 'tag', __( 'Valid passes', 'olivia-studio' ), (string) ( $group['n'] + $one['n'] ), sprintf( __( '%1$s left · %2$s', 'olivia-studio' ), $sess( $group['c'] + $one['c'] ), $cust( $people ) ), 'hot' ),
			array( 'lock', __( 'Locked sessions', 'olivia-studio' ), (string) $locked, __( 'taken from passes for classes still ahead', 'olivia-studio' ), '' ),
			array( 'users', __( 'Group and online', 'olivia-studio' ), $sess( $group['c'] ), $pass( $group['n'] ), '' ),
			array( 'star', __( 'One-to-one', 'olivia-studio' ), $sess( $one['c'] ), $pass( $one['n'] ), '' ),
			array( 'clock', __( 'Expiring in 14 days', 'olivia-studio' ), $pass( (int) $soon->n ), sprintf( __( '%s still on them', 'olivia-studio' ), $sess( (int) $soon->c ) ), (int) $soon->n ? 'warn' : '' ),
			array( 'calendar', __( 'Booked classes ahead', 'olivia-studio' ), (string) $ahead, __( 'all bookings, any payment', 'olivia-studio' ), '' ),
			array( 'x', __( 'Expired unused, 30 days', 'olivia-studio' ), $sess( (int) $lapsed->c ), $pass( (int) $lapsed->n ), '' ),
			array( 'gift', __( 'Gift cards not redeemed', 'olivia-studio' ), (string) $gifts, '', '' ),
		);
		if ( $members ) {
			$rows[] = array( 'heart', __( 'Active memberships', 'olivia-studio' ), (string) $members, '', '' );
		}
		if ( $holds ) {
			$rows[] = array( 'card', __( 'Seats held during payment', 'olivia-studio' ), (string) $holds, __( 'free again if the payment is not finished', 'olivia-studio' ), '' );
		}
		[ $held_n, $held_s, $held_v ] = OYS_Held::totals();
		if ( $held_n ) { // Olívia, 16 November: not bookable online, redeemable on request
			$rows[] = array( 'tag', __( 'From Motibro, to redeem', 'olivia-studio' ), sprintf( _n( '%d session', '%d sessions', $held_s, 'olivia-studio' ), $held_s ), sprintf( __( '%1$s · %2$d customers · not bookable online', 'olivia-studio' ), oys_money( $held_v ), $held_n ), '' );
		}
		return array_map( fn( $r ) => array( 'icon' => $r[0], 'label' => $r[1], 'value' => $r[2], 'note' => $r[3], 'tone' => $r[4] ), $rows );
	}

	/** Customers to start a conversation with (a teacher: the people booked into their classes). */
	public static function search_customers( $q ) {
		$q     = trim( (string) $q );
		$scope = self::scope();
		$args  = array( 'role__in' => array( 'oys_customer' ), 'number' => 12, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) );
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
		}
		if ( null !== $scope ) {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT b.user_id FROM ' . self::t( 'bookings' ) . ' b JOIN ' . self::t( 'sessions' ) . ' s ON s.id = b.session_id WHERE s.teacher_id = %d AND b.user_id > 0', $scope ) );
			$args['include'] = $ids ?: array( 0 );
		}
		$out = array();
		foreach ( get_users( $args ) as $u ) {
			$first = get_user_meta( $u->ID, 'first_name', true );
			$last  = get_user_meta( $u->ID, 'last_name', true );
			$out[] = array( 'id' => (int) $u->ID, 'name' => trim( $first . ' ' . $last ) ?: $u->display_name, 'email' => $u->user_email );
		}
		return $out;
	}

	/* ---------- REST ---------- */

	public static function routes() {
		$perm = fn() => self::can();
		$r    = fn( $path, $method, $cb ) => register_rest_route( self::NS, '/hub' . $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $perm ) );
		$r( '/inbox', 'GET', 'rest_inbox' );
		$r( '/today', 'GET', 'rest_today' );
		$r( '/poll', 'GET', 'rest_poll' );
		$r( '/thread/(?P<id>\d+)', 'GET', 'rest_thread' );
		$r( '/thread/(?P<id>\d+)', 'POST', 'rest_thread_post' );
		$r( '/thread/(?P<id>\d+)/status', 'POST', 'rest_thread_status' );
		$r( '/threads', 'POST', 'rest_thread_start' );
		$r( '/chat/(?P<id>\d+)', 'GET', 'rest_chat' );
		$r( '/chat/(?P<id>\d+)', 'POST', 'rest_chat_post' );
		$r( '/chat/(?P<id>\d+)/(?P<mid>\d+)', 'DELETE', 'rest_chat_delete' );
		$r( '/customers', 'GET', 'rest_customers' );
		$r( '/me', 'GET', 'rest_me' );
		$r( '/me', 'POST', 'rest_me_save' );
	}

	private static function err( $e, $status = 400 ) {
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $status ) );
	}

	public static function rest_inbox( WP_REST_Request $req ) {
		self::mark_active();
		$filter = in_array( $req->get_param( 'filter' ), array( 'all', 'unread', 'messages', 'chats', 'requests', 'done' ), true ) ? $req->get_param( 'filter' ) : 'all';
		return rest_ensure_response( array( 'items' => self::inbox( $filter, sanitize_text_field( (string) $req->get_param( 'q' ) ) ), 'counts' => self::counts() ) );
	}

	public static function rest_today() {
		self::mark_active();
		return rest_ensure_response( self::today() );
	}

	public static function rest_poll() {
		self::mark_active();
		$items = self::inbox( 'unread' );
		return rest_ensure_response( array( 'v' => OYS_VERSION, 'counts' => self::counts(), 'activity' => self::can_manage() ? OYS_Activity::unread() : 0, 'latest' => array_slice( array_map( fn( $i ) => array( 'key' => $i['key'], 'title' => $i['title'], 'sub' => $i['sub'], 'ts' => $i['ts'] ), $items ), 0, 5 ) ) );
	}

	private static function thread_req( WP_REST_Request $req ) {
		$t = self::get( (int) $req['id'] );
		return $t && self::can_thread( $t ) ? $t : new WP_Error( 'oys_hub', __( 'This conversation no longer exists.', 'olivia-studio' ), array( 'status' => 404 ) );
	}

	public static function rest_thread( WP_REST_Request $req ) {
		$t = self::thread_req( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		self::mark_active();
		$msgs = self::messages( $t->id, (int) $req->get_param( 'after' ) );
		if ( $t->unread_studio ) {
			self::mark_read( $t->id, 'studio' );
		}
		return rest_ensure_response( array(
			'id'       => (int) $t->id,
			'title'    => $t->name ?: $t->email,
			'status'   => $t->status,
			'seen'     => ! (int) $t->unread_customer,
			'email'    => (string) $t->email,
			'messages' => array_map( array( __CLASS__, 'shape_thread_msg' ), $msgs ),
			'customer' => (int) $req->get_param( 'after' ) ? null : self::customer_card( (int) $t->user_id, $t ),
		) );
	}

	/** A photo sent with the request (multipart field „image”): saved, or the error. */
	private static function req_image( WP_REST_Request $req ) {
		$files = $req->get_file_params();
		return empty( $files['image']['name'] ) ? '' : self::save_image( $files['image'] );
	}

	public static function rest_thread_post( WP_REST_Request $req ) {
		$t = self::thread_req( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$image = self::req_image( $req );
		if ( is_wp_error( $image ) ) {
			return self::err( $image );
		}
		$id = self::add( $t, array( 'body' => (string) $req->get_param( 'body' ), 'image' => $image, 'from_studio' => true, 'kind' => $req->get_param( 'note' ) ? 'note' : '' ) );
		return is_wp_error( $id ) ? self::err( $id ) : rest_ensure_response( array( 'id' => $id ) );
	}

	public static function rest_thread_status( WP_REST_Request $req ) {
		$t = self::thread_req( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		self::set_status( $t->id, (string) $req->get_param( 'status' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_thread_start( WP_REST_Request $req ) {
		$user_id = (int) $req->get_param( 'user' );
		$scope   = self::scope();
		if ( ! $user_id || ! get_userdata( $user_id ) || ( null !== $scope && ! in_array( $user_id, array_column( self::search_customers( get_userdata( $user_id )->user_email ), 'id' ), true ) ) ) {
			return new WP_Error( 'oys_hub', __( 'Choose someone to write to.', 'olivia-studio' ), array( 'status' => 400 ) );
		}
		$t = self::thread_for( $user_id, '', '', null === $scope ? 0 : max( 0, $scope ) );
		if ( '' !== trim( (string) $req->get_param( 'body' ) ) ) {
			$id = self::add( $t, array( 'body' => (string) $req->get_param( 'body' ), 'from_studio' => true ) );
			if ( is_wp_error( $id ) ) {
				return self::err( $id );
			}
		}
		return rest_ensure_response( array( 'key' => 't' . (int) $t->id ) );
	}

	private static function chat_req( WP_REST_Request $req ) {
		$s = OYS_Schedule::get( (int) $req['id'] );
		return $s && self::can_session( $s ) ? $s : new WP_Error( 'oys_hub', __( 'This class no longer exists.', 'olivia-studio' ), array( 'status' => 404 ) );
	}

	public static function rest_chat( WP_REST_Request $req ) {
		$s = self::chat_req( $req );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		self::mark_active();
		$after = (int) $req->get_param( 'after' );
		$msgs  = OYS_Live::messages( $s->id, $after, 500 );
		if ( $msgs ) {
			self::mark_chat_read( $s->id, (int) end( $msgs )->id );
		}
		$out = array(
			'id'       => (int) $s->id,
			'title'    => oys_session_title( $s ),
			'when'     => oys_date( $s->starts_at, 'l, M j, g:i a' ),
			'messages' => array_map( array( __CLASS__, 'shape_chat_msg' ), $msgs ),
		);
		if ( ! $after ) {
			$people = array();
			foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed', 'attended', 'no_show' ) ) as $b ) {
				$people[] = array( 'name' => OYS_Bookings::person_label( $b ), 'user' => $b->guest_of ? 0 : (int) $b->user_id );
			}
			$out['session'] = array(
				'booked'   => (int) $s->booked + (int) $s->online_booked,
				'people'   => $people,
				'live'     => OYS_Live::uses_live( $s ) ? OYS_Live::url( $s->id ) : '',
				'roster'   => admin_url( 'admin.php?page=' . ( self::can_manage() ? 'oys-schedule' : 'oys-teach' ) . '&session=' . (int) $s->id ),
				'chatOpen' => OYS_Live::chat_open( $s ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function rest_chat_post( WP_REST_Request $req ) {
		$s = self::chat_req( $req );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$image = self::req_image( $req );
		if ( is_wp_error( $image ) ) {
			return self::err( $image );
		}
		$id = self::post_to_class( $s, (string) $req->get_param( 'body' ), $image );
		return is_wp_error( $id ) ? self::err( $id ) : rest_ensure_response( array( 'id' => $id ) );
	}

	public static function rest_chat_delete( WP_REST_Request $req ) {
		$s = self::chat_req( $req );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$who = array( 'owner' => true, 'booking' => null, 'user_id' => get_current_user_id(), 'name' => '' );
		return OYS_Live::delete_message( $s, $who, (int) $req['mid'] ) ? rest_ensure_response( array( 'ok' => true ) ) : new WP_Error( 'oys_hub', __( 'You can\'t remove this message.', 'olivia-studio' ), array( 'status' => 403 ) );
	}

	public static function rest_customers( WP_REST_Request $req ) {
		return rest_ensure_response( self::search_customers( sanitize_text_field( (string) $req->get_param( 'q' ) ) ) );
	}

	public static function rest_me() {
		return rest_ensure_response( self::prefs() + array( 'name' => self::chat_name( get_current_user_id() ) ) );
	}

	public static function rest_me_save( WP_REST_Request $req ) {
		return rest_ensure_response( self::save_prefs( get_current_user_id(), (array) $req->get_json_params() ?: $req->get_params() ) );
	}
}

OYS_Hub::init();
