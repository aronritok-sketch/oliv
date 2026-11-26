<?php
/**
 * Notifications (Értesítések): everything that happens on the website, in one list – a tab in the Hub (wp-admin and
 * the phone app), Studio → Notifications, and a bell with the number in the admin bar. Áron, 26 November: „Szeretnék egy
 * értesítések fület az adminba is, meg a mobilapra is, hogyha történik valami, mert most például azt látom, hogy
 * Analytics-ben 5 perce egy form submitted volt, de én arról nem látok semmit, hogy hol küldték be, meg mi volt, szóval ez
 * legyen mindenhol. Minden ilyen tevékenységről.”
 *
 * Every form a visitor sends is listed, with who, what and where: a message, a call-back request, a private request, a
 * review, a new account, a login (and a failed one), a new password asked for and set, the profile, the participation
 * agreement, a booking, a cancellation, the waitlist, a checkout started, paid, failed, abandoned or refunded, a gift card
 * redeemed, a membership, the newsletter (signup, confirmed, unsubscribed). What the studio does itself is not listed.
 *
 * The important ones also come as a push to the phone (Hub → My settings, „Everything else on the website”); messages,
 * requests and bookings already had their own push, so they are only listed. Kept for 180 days. Only for the studio
 * (oys_manage): payments and logins are not a teacher's business.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Activity {

	const CAP      = 'oys_manage';
	const SEEN     = 'oys_activity_seen';
	const KEEP     = 180;
	const BACKFILL = 'oys_activity_backfill';
	const PAGE     = 60;

	/** type => [ category, icon, push by default ] */
	const TYPES = array(
		'contact'        => array( 'messages', 'mail', false ),     // the Hub pushes the conversation already
		'callback'       => array( 'messages', 'phone', true ),
		'private'        => array( 'messages', 'star', false ),     // the Hub pushes the request already
		'hubmsg'         => array( 'messages', 'chat', false ),
		'review'         => array( 'messages', 'heart', false ),    // OYS_Reviews pushes it already
		'signup'         => array( 'accounts', 'user', true ),
		'login'          => array( 'accounts', 'lock', false ),
		'login_failed'   => array( 'accounts', 'lock', false ),
		'reset_request'  => array( 'accounts', 'lock', false ),
		'password_set'   => array( 'accounts', 'lock', true ),
		'completed'      => array( 'accounts', 'check', true ),
		'profile'        => array( 'accounts', 'user', false ),
		'waiver'         => array( 'accounts', 'check', false ),
		'booked'         => array( 'bookings', 'calendar', false ), // OYS_Hub_Auto pushes it already
		'cancelled'      => array( 'bookings', 'x', false ),
		'waitlist'       => array( 'bookings', 'clock', false ),
		'checkout'       => array( 'payments', 'card', false ),
		'paid'           => array( 'payments', 'card', true ),
		'failed'         => array( 'payments', 'card', true ),
		'abandoned'      => array( 'payments', 'card', false ),
		'refunded'       => array( 'payments', 'card', false ),
		'gift_redeemed'  => array( 'payments', 'gift', true ),
		'membership'     => array( 'payments', 'star', true ),
		'membership_end' => array( 'payments', 'star', false ),
		'nl_signup'      => array( 'newsletter', 'send', true ),
		'nl_confirmed'   => array( 'newsletter', 'send', false ),
		'nl_unsub'       => array( 'newsletter', 'send', false ),
	);

	/** @var bool while the history is filled in: no push, nobody skipped */
	private static $backfilling = false;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 75 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'bar_css' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'bar_css' ) );
		add_action( 'init', array( __CLASS__, 'maybe_backfill' ), 30 );
		add_action( 'oys_hourly', array( __CLASS__, 'prune' ) );
		// the texts are written in the studio's language, whatever the request (a webhook, a cron job, a form)
		// the plugin's own events
		self::listen( 'oys_hub_message', 'on_hub_message', 20, 3 );
		self::listen( 'oys_callback_request', 'on_callback', 10, 1 );
		self::listen( 'oys_booking_confirmed', 'on_booked', 20, 1 );
		self::listen( 'oys_booking_cancelled', 'on_cancelled', 20, 2 );
		self::listen( 'oys_waitlist_joined', 'on_waitlist', 20, 2 );
		self::listen( 'oys_order_created', 'on_checkout', 10, 1 );
		self::listen( 'oys_order_paid', 'on_paid', 20, 1 );
		self::listen( 'oys_order_unpaid', 'on_unpaid', 10, 2 );
		self::listen( 'oys_order_refunded', 'on_refunded', 10, 2 );
		self::listen( 'oys_gift_redeemed', 'on_gift_redeemed', 10, 2 );
		self::listen( 'oys_membership_started', 'on_membership', 10, 1 );
		self::listen( 'oys_membership_ended', 'on_membership_end', 10, 1 );
		self::listen( 'oys_review_submitted', 'on_review', 10, 2 );
		// WordPress's own: logins and passwords
		self::listen( 'wp_login', 'on_login', 20, 2 );
		self::listen( 'wp_login_failed', 'on_login_failed', 10, 1 );
		self::listen( 'retrieve_password', 'on_reset_request', 10, 1 );
		self::listen( 'after_password_reset', 'on_password_set', 10, 1 );
	}

	private static function listen( $hook, $method, $priority, $args ) {
		add_action( $hook, function ( ...$a ) use ( $method ) {
			self::in_lang( fn() => call_user_func_array( array( __CLASS__, $method ), $a ) );
		}, $priority, $args );
	}

	/** Runs $fn in the studio's language (the site's main one), so that a payment from a webhook is listed in it too. */
	public static function in_lang( callable $fn ) {
		$want = OYS_I18n::locale_of( OYS_I18n::default_lang() );
		if ( get_locale() === $want || ! switch_to_locale( $want ) ) {
			return $fn();
		}
		try {
			return $fn();
		} finally {
			restore_previous_locale();
		}
	}

	public static function table() {
		return OYS_Install::table( 'activity' );
	}

	public static function categories() {
		return array(
			'messages'   => __( 'Messages & requests', 'olivia-studio' ),
			'accounts'   => __( 'Accounts', 'olivia-studio' ),
			'bookings'   => __( 'Bookings', 'olivia-studio' ),
			'payments'   => __( 'Payments', 'olivia-studio' ),
			'newsletter' => __( 'Newsletter', 'olivia-studio' ),
		);
	}

	/* ---------- writing ---------- */

	/**
	 * Something happened on the website.
	 * @param array $a title, detail, user, email, url (an admin page), open (a Hub key: t12, s5), ref (once only),
	 *                 at (UTC, for the history), push (null: the type's default)
	 * @return int the entry's id (0: not listed)
	 */
	public static function log( $type, array $a = array() ) {
		global $wpdb;
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return 0;
		}
		$a = $a + array( 'title' => '', 'detail' => '', 'user' => 0, 'email' => '', 'url' => '', 'open' => '', 'ref' => '', 'at' => '', 'push' => null );
		// what the studio does itself is not news to it
		if ( ! self::$backfilling && self::is_staff( get_current_user_id() ) ) {
			return 0;
		}
		if ( (int) $a['user'] && self::is_staff( (int) $a['user'] ) ) {
			return 0;
		}
		$ref = substr( (string) $a['ref'], 0, 40 );
		if ( '' !== $ref && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE ref = %s LIMIT 1', $ref ) ) ) {
			return 0;
		}
		$uid  = (int) $a['user'];
		$test = $uid && get_user_meta( $uid, 'oys_test', true ) ? 1 : 0;
		$wpdb->insert( self::table(), array(
			'created_at' => $a['at'] ?: oys_now(),
			'type'       => $type,
			'cat'        => self::TYPES[ $type ][0],
			'title'      => mb_substr( wp_strip_all_tags( (string) $a['title'] ), 0, 255 ),
			'detail'     => mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $a['detail'] ) ) ), 0, 500 ),
			'user_id'    => $uid,
			'email'      => mb_substr( (string) $a['email'], 0, 190 ),
			'url'        => mb_substr( (string) $a['url'], 0, 255 ),
			'open'       => preg_match( '/^[tcs]\d+$/', (string) $a['open'] ) ? (string) $a['open'] : '',
			'ref'        => $ref,
			'test'       => $test,
		) );
		$id = (int) $wpdb->insert_id;
		if ( $id && ! self::$backfilling && ! $test && ( null === $a['push'] ? self::TYPES[ $type ][2] : (bool) $a['push'] ) ) {
			self::push( $id, $a );
		}
		return $id;
	}

	private static function push( $id, array $a ) {
		if ( ! class_exists( 'OYS_Studio_App' ) || ! OYS_Studio_App::subs() ) {
			return;
		}
		$url = OYS_Studio_App::url( $a['open'] ?: 'alerts' );
		// one person's steps (a password, then the profile) replace each other on the phone
		$tag = (int) $a['user'] ? 'act-u' . (int) $a['user'] : 'act-' . (int) $id;
		foreach ( OYS_Studio_App::subscribers() as $uid ) {
			if ( user_can( $uid, self::CAP ) && ! empty( OYS_Hub::prefs( $uid )['push_activity'] ) ) {
				OYS_Studio_App::notify( $uid, $a['title'], $a['detail'], $url, $tag );
			}
		}
	}

	private static function is_staff( $uid ) {
		return $uid && ( user_can( $uid, 'oys_manage' ) || user_can( $uid, 'oys_teach' ) || user_can( $uid, 'manage_options' ) );
	}

	public static function name( $uid, $email = '' ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		if ( $u ) {
			return trim( $u->first_name . ' ' . $u->last_name ) ?: ( $u->display_name ?: $u->user_email );
		}
		return (string) $email;
	}

	private static function customer_url( $uid ) {
		return $uid ? admin_url( 'admin.php?page=oys-customers&user=' . (int) $uid ) : '';
	}

	private static function person( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		return array( 'user' => (int) $uid, 'email' => $u ? $u->user_email : '', 'url' => self::customer_url( $uid ) );
	}

	/* ---------- the events ---------- */

	/** A customer or a website visitor wrote (a message from the contact page, a private request, a reply in the Hub). */
	public static function on_hub_message( $message_id, $thread, $studio ) {
		global $wpdb;
		if ( $studio || ! $thread ) {
			return;
		}
		$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT body, kind FROM ' . OYS_Hub::t( 'thread_msgs' ) . ' WHERE id = %d', (int) $message_id ) );
		$kind = $m ? (string) $m->kind : '';
		self::log( self::message_type( $kind ), self::message_entry( $thread, $m ? (string) $m->body : '', $kind ) + array( 'ref' => 'm' . (int) $message_id ) );
	}

	private static function message_type( $kind ) {
		return 'contact' === $kind ? 'contact' : ( 'request' === $kind ? 'private' : 'hubmsg' );
	}

	private static function message_entry( $thread, $body, $kind ) {
		$who   = $thread->name ?: $thread->email;
		$title = 'contact' === $kind ? sprintf( __( 'Website message: %s', 'olivia-studio' ), $who )
			: ( 'request' === $kind ? sprintf( __( 'Private session request: %s', 'olivia-studio' ), $who ) : sprintf( __( 'New message: %s', 'olivia-studio' ), $who ) );
		return array(
			'title'  => $title,
			'detail' => '' !== trim( $body ) ? wp_trim_words( $body, 30, '…' ) : '📷 ' . __( 'Photo', 'olivia-studio' ),
			'user'   => (int) $thread->user_id,
			'email'  => (string) $thread->email,
			'open'   => 't' . (int) $thread->id,
		);
	}

	/** The theme's call-back form (the contact page): only a phone number. */
	public static function on_callback( $phone ) {
		self::log( 'callback', array(
			'title'  => sprintf( __( 'Call-back request: %s', 'olivia-studio' ), sanitize_text_field( (string) $phone ) ),
			'detail' => __( 'Someone asked for a call back on the contact page. Only the phone number was given.', 'olivia-studio' ),
		) );
	}

	private static function class_line( $s ) {
		return $s ? oys_session_title( $s ) . ' · ' . oys_date( $s->starts_at, 'M j, D' ) . ' ' . oys_time( $s->starts_at ) : '';
	}

	public static function on_booked( $booking_id ) {
		$b = OYS_Bookings::get( (int) $booking_id );
		if ( ! $b || $b->guest_of ) {
			return;
		}
		$s      = OYS_Schedule::get( $b->session_id );
		$guests = count( OYS_Bookings::guests_of( $b->id, array( 'confirmed' ) ) );
		self::log( 'booked', array(
			'title'  => sprintf( __( 'New booking: %s', 'olivia-studio' ), self::name( $b->user_id ) ),
			'detail' => self::class_line( $s ) . ( $guests ? ' · ' . sprintf( _n( '+%d guest', '+%d guests', $guests, 'olivia-studio' ), $guests ) : '' ) . ' · ' . self::paid_with( $b ),
			'open'   => $s ? 's' . (int) $s->id : '',
			'ref'    => 'b' . (int) $b->id,
		) + self::person( $b->user_id ) );
	}

	private static function paid_with( $b ) {
		$map = array(
			'pass'       => __( 'with a pass', 'olivia-studio' ),
			'membership' => __( 'with a membership', 'olivia-studio' ),
			'stripe'     => __( 'paid online', 'olivia-studio' ),
			'free'       => __( 'free', 'olivia-studio' ),
			'pay_later'  => __( 'pays at the studio', 'olivia-studio' ),
		);
		return $map[ (string) $b->paid_with ] ?? (string) $b->paid_with;
	}

	public static function on_cancelled( $booking_id, $outcome = '' ) {
		$b = OYS_Bookings::get( (int) $booking_id );
		if ( ! $b || $b->guest_of ) {
			return;
		}
		$s = OYS_Schedule::get( $b->session_id );
		self::log( 'cancelled', array(
			'title'  => sprintf( __( 'Cancelled: %s', 'olivia-studio' ), self::name( $b->user_id ) ),
			'detail' => self::class_line( $s ) . ( 'late' === $outcome ? ' · ' . __( 'late: the session is used up', 'olivia-studio' ) : '' ),
			'open'   => $s ? 's' . (int) $s->id : '',
			'ref'    => 'bc' . (int) $b->id,
		) + self::person( $b->user_id ) );
	}

	public static function on_waitlist( $user_id, $session_id ) {
		$s = OYS_Schedule::get( (int) $session_id );
		self::log( 'waitlist', array(
			'title'  => sprintf( __( 'On the waitlist: %s', 'olivia-studio' ), self::name( $user_id ) ),
			'detail' => self::class_line( $s ),
			'open'   => $s ? 's' . (int) $s->id : '',
		) + self::person( $user_id ) );
	}

	private static function order_entry( $order, $title, $extra = '' ) {
		$o = is_object( $order ) ? $order : OYS_Orders::get( (int) $order );
		if ( ! $o ) {
			return null;
		}
		return array(
			'title'  => sprintf( $title, self::name( $o->user_id, $o->email ?? '' ) ),
			'detail' => trim( $o->description . ' · ' . oys_money( (int) $o->amount_cents, $o->currency ) . ( $extra ? ' · ' . $extra : '' ), ' ·' ),
			'url'    => admin_url( 'admin.php?page=oys-orders' ),
		) + array_diff_key( self::person( $o->user_id ), array( 'url' => 1 ) );
	}

	public static function on_checkout( $order_id ) {
		$o = OYS_Orders::get( (int) $order_id );
		if ( ! $o || 'pending' !== $o->status || (int) $o->amount_cents <= 0 ) {
			return;
		}
		$e = self::order_entry( $o, __( 'Checkout started: %s', 'olivia-studio' ), __( 'on the payment page now', 'olivia-studio' ) );
		$e && self::log( 'checkout', $e + array( 'ref' => 'oc' . (int) $o->id ) );
	}

	public static function on_paid( $order_id ) {
		$e = self::order_entry( (int) $order_id, __( 'Paid: %s', 'olivia-studio' ) );
		$e && self::log( 'paid', $e + array( 'ref' => 'op' . (int) $order_id ) );
	}

	public static function on_unpaid( $order_id, $status ) {
		$failed = 'failed' === $status;
		$e      = self::order_entry( (int) $order_id, $failed ? __( 'Payment failed: %s', 'olivia-studio' ) : __( 'Payment abandoned: %s', 'olivia-studio' ), $failed ? __( 'the bank did not accept the payment', 'olivia-studio' ) : __( 'left the payment page without paying', 'olivia-studio' ) );
		$e && self::log( $failed ? 'failed' : 'abandoned', $e + array( 'ref' => 'ou' . (int) $order_id ) );
	}

	public static function on_refunded( $order_id, $cents ) {
		$o = OYS_Orders::get( (int) $order_id );
		$e = $o ? self::order_entry( $o, __( 'Refunded: %s', 'olivia-studio' ), sprintf( __( 'refunded: %s', 'olivia-studio' ), oys_money( (int) $cents, $o->currency ) ) ) : null;
		$e && self::log( 'refunded', $e + array( 'ref' => 'or' . (int) $order_id . '-' . (int) $cents ) );
	}

	public static function on_gift_redeemed( $gift, $user_id ) {
		$p = $gift ? OYS_Products::get( $gift->product_id ) : null;
		self::log( 'gift_redeemed', array(
			'title'  => sprintf( __( 'Gift card redeemed: %s', 'olivia-studio' ), self::name( $user_id ) ),
			'detail' => ( $p ? $p['name'] . ' · ' : '' ) . (string) ( $gift->code ?? '' ),
			'ref'    => 'g' . (int) ( $gift->id ?? 0 ),
		) + self::person( $user_id ) );
	}

	public static function on_membership( $id ) {
		$m = OYS_Memberships::get( (int) $id );
		if ( $m ) {
			self::log( 'membership', array( 'title' => sprintf( __( 'New membership: %s', 'olivia-studio' ), self::name( $m->user_id ) ), 'detail' => (string) ( $m->name ?? '' ), 'ref' => 'ms' . (int) $id ) + self::person( $m->user_id ) );
		}
	}

	public static function on_membership_end( $id ) {
		$m = OYS_Memberships::get( (int) $id );
		if ( $m ) {
			self::log( 'membership_end', array( 'title' => sprintf( __( 'Membership ended: %s', 'olivia-studio' ), self::name( $m->user_id ) ), 'detail' => (string) ( $m->name ?? '' ), 'ref' => 'me' . (int) $id ) + self::person( $m->user_id ) );
		}
	}

	public static function on_review( $post_id, $user_id ) {
		self::log( 'review', array(
			'title'  => sprintf( __( 'New review: %s', 'olivia-studio' ), self::name( $user_id ) ),
			'detail' => wp_trim_words( (string) get_post_field( 'post_content', (int) $post_id ), 30, '…' ),
			'url'    => admin_url( 'edit.php?post_type=oys_review&post_status=pending' ),
			'user'   => (int) $user_id,
			'ref'    => 'rv' . (int) $post_id . '-' . time(),
		) );
	}

	/** The login form on the website (a studio login is not listed). */
	public static function on_login( $login, $user ) {
		if ( ! $user instanceof WP_User || self::is_staff( $user->ID ) ) {
			return;
		}
		self::log( 'login', array(
			'title'  => sprintf( __( 'Logged in: %s', 'olivia-studio' ), self::name( $user->ID ) ),
			'detail' => self::device(),
		) + self::person( $user->ID ) );
	}

	/** Only the website's own login form: bots trying wp-login.php are not news. */
	public static function on_login_failed( $login ) {
		if ( 'oys_login' !== sanitize_key( $_POST['action'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$login = sanitize_text_field( (string) $login );
		$u     = get_user_by( 'email', $login ) ?: get_user_by( 'login', $login );
		if ( $u && self::is_staff( $u->ID ) ) {
			return;
		}
		self::log( 'login_failed', array(
			'title'  => sprintf( __( 'Login failed: %s', 'olivia-studio' ), $u ? self::name( $u->ID ) : $login ),
			'detail' => $u ? __( 'Wrong password. If it happens again, the password link helps (Fiókom → Elfelejtett jelszó).', 'olivia-studio' ) : __( 'No account with this email address.', 'olivia-studio' ),
		) + ( $u ? self::person( $u->ID ) : array( 'email' => is_email( $login ) ? $login : '' ) ) );
	}

	public static function on_reset_request( $login ) {
		$u = get_user_by( 'login', (string) $login ) ?: get_user_by( 'email', (string) $login );
		if ( ! $u ) {
			return;
		}
		self::log( 'reset_request', array(
			'title'  => sprintf( __( 'Asked for a new password: %s', 'olivia-studio' ), self::name( $u->ID ) ),
			'detail' => __( 'The link to set a password went out by email.', 'olivia-studio' ),
		) + self::person( $u->ID ) );
	}

	public static function on_password_set( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$motibro = (bool) get_user_meta( $user->ID, 'oys_imported_at', true );
		self::log( 'password_set', array(
			'title'  => sprintf( __( 'Set a password: %s', 'olivia-studio' ), self::name( $user->ID ) ),
			'detail' => $motibro ? __( 'A guest from Motibro: the account is active now.', 'olivia-studio' ) : __( 'Can log in with the new password.', 'olivia-studio' ),
		) + self::person( $user->ID ) );
	}

	/** The customer forms of OYS_Customers. */
	public static function on_signup( $uid ) {
		$u = get_userdata( (int) $uid );
		if ( ! $u ) {
			return;
		}
		$town = (string) get_user_meta( $u->ID, 'oys_area', true );
		self::log( 'signup', array(
			'title'  => sprintf( __( 'New account: %s', 'olivia-studio' ), self::name( $u->ID ) ),
			'detail' => $u->user_email . ( '' !== $town ? ' · ' . $town : '' ) . ' · ' . ( '1' === (string) get_user_meta( $u->ID, 'oys_marketing', true ) ? __( 'asked for the newsletter', 'olivia-studio' ) : __( 'no newsletter', 'olivia-studio' ) ),
			'ref'    => 'u' . (int) $u->ID,
		) + self::person( $u->ID ) );
	}

	public static function on_profile( $uid, $completed ) {
		self::log( $completed ? 'completed' : 'profile', array(
			'title'  => sprintf( $completed ? __( 'Account complete: %s', 'olivia-studio' ) : __( 'Profile saved: %s', 'olivia-studio' ), self::name( $uid ) ),
			'detail' => $completed ? __( 'Accepted the participation agreement and answered the newsletter question: can book now.', 'olivia-studio' ) : '',
		) + self::person( $uid ) );
	}

	/* ---------- reading ---------- */

	public static function seen( $uid = 0 ) {
		return (int) get_user_meta( $uid ?: get_current_user_id(), self::SEEN, true );
	}

	public static function unread( $uid = 0 ) {
		global $wpdb;
		$uid = $uid ?: get_current_user_id();
		if ( ! user_can( $uid, self::CAP ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE id > %d', self::seen( $uid ) ) );
	}

	public static function mark_seen( $upto, $uid = 0 ) {
		$uid = $uid ?: get_current_user_id();
		if ( (int) $upto > self::seen( $uid ) ) {
			update_user_meta( $uid, self::SEEN, (int) $upto );
		}
	}

	/**
	 * The list, newest first.
	 * @return array { items, more, unread, cats: { category: unread } }
	 */
	public static function feed( $cat = '', $before = 0, $limit = self::PAGE ) {
		global $wpdb;
		$t     = self::table();
		$seen  = self::seen();
		$where = array( '1=1' );
		$args  = array();
		if ( isset( self::categories()[ $cat ] ) ) {
			$where[] = 'cat = %s';
			$args[]  = $cat;
		}
		if ( (int) $before ) {
			$where[] = 'id < %d';
			$args[]  = (int) $before;
		}
		$args[] = (int) $limit + 1;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args ) ); // phpcs:ignore
		$more   = count( $rows ) > (int) $limit;
		$rows   = array_slice( $rows, 0, (int) $limit );
		$cats   = array_fill_keys( array_keys( self::categories() ), 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT cat, COUNT(*) AS n FROM $t WHERE id > %d GROUP BY cat", $seen ) ) as $c ) {
			$cats[ $c->cat ] = (int) $c->n;
		}
		return array(
			'items'  => array_map( fn( $r ) => self::item( $r, $seen ), $rows ),
			'more'   => $more,
			'unread' => array_sum( $cats ),
			'cats'   => $cats,
		);
	}

	private static function item( $r, $seen ) {
		$ts = oys_ts( $r->created_at );
		return array(
			'id'     => (int) $r->id,
			'type'   => (string) $r->type,
			'cat'    => (string) $r->cat,
			'icon'   => self::TYPES[ $r->type ][1] ?? 'bell',
			'title'  => (string) $r->title,
			'detail' => (string) $r->detail,
			'day'    => wp_date( 'Y-m-d', $ts ),
			'dayLabel' => self::day_label( $ts ),
			'time'   => wp_date( 'H:i', $ts ),
			'url'    => (string) $r->url,
			'open'   => (string) $r->open,
			'test'   => (bool) $r->test,
			'new'    => (int) $r->id > $seen,
		);
	}

	private static function day_label( $ts ) {
		$d = wp_date( 'Y-m-d', $ts );
		if ( wp_date( 'Y-m-d' ) === $d ) {
			return __( 'Today', 'olivia-studio' );
		}
		if ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $d ) {
			return __( 'Yesterday', 'olivia-studio' );
		}
		return wp_date( __( 'l, F j', 'olivia-studio' ), $ts );
	}

	/** Where from: phone or computer, and the browser (no address is kept). */
	private static function device() {
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' === $ua ) {
			return '';
		}
		$dev = preg_match( '/iPhone|Android.+Mobile|Mobile Safari/i', $ua ) ? __( 'on a phone', 'olivia-studio' ) : ( preg_match( '/iPad|Tablet|Android/i', $ua ) ? __( 'on a tablet', 'olivia-studio' ) : __( 'on a computer', 'olivia-studio' ) );
		$br  = preg_match( '/Edg\//', $ua ) ? 'Edge' : ( preg_match( '/Firefox\//', $ua ) ? 'Firefox' : ( preg_match( '/Chrome\/|CriOS\//', $ua ) ? 'Chrome' : ( preg_match( '/Safari\//', $ua ) ? 'Safari' : '' ) ) );
		return $dev . ( $br ? ' (' . $br . ')' : '' );
	}

	/* ---------- REST, the admin menu, the admin bar ---------- */

	public static function routes() {
		$perm = fn() => current_user_can( self::CAP );
		register_rest_route( OYS_Hub::NS, '/hub/activity', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => fn( WP_REST_Request $r ) => rest_ensure_response( self::feed( sanitize_key( (string) $r->get_param( 'cat' ) ), (int) $r->get_param( 'before' ), min( 200, max( 1, (int) ( $r->get_param( 'limit' ) ?: self::PAGE ) ) ) ) ),
		) );
		register_rest_route( OYS_Hub::NS, '/hub/activity/seen', array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $r ) {
				self::mark_seen( (int) $r->get_param( 'upto' ) );
				return rest_ensure_response( array( 'unread' => self::unread() ) );
			},
		) );
	}

	/** Studio → Notifications: the Hub, opened on its Notifications tab. */
	public static function menu() {
		$n = self::unread();
		add_submenu_page( 'oys', __( 'Notifications', 'olivia-studio' ), __( 'Notifications', 'olivia-studio' ) . ( $n ? ' <span class="awaiting-mod">' . $n . '</span>' : '' ), self::CAP, 'oys-activity', array( 'OYS_Hub_Admin', 'page' ), 1 );
	}

	/** A bell with the number, on every admin page and on the website for the studio. */
	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) {
			return;
		}
		$n = self::unread();
		$bar->add_node( array(
			'id'    => 'oys-activity',
			'title' => '<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="ab-label">' . ( $n ? (int) $n : '' ) . '</span><span class="screen-reader-text">' . esc_html__( 'Notifications', 'olivia-studio' ) . '</span>',
			'href'  => admin_url( 'admin.php?page=oys-activity' ),
			'meta'  => array( 'title' => $n ? sprintf( _n( '%d new notification', '%d new notifications', $n, 'olivia-studio' ), $n ) : __( 'Notifications', 'olivia-studio' ), 'class' => $n ? 'oys-has-new' : '' ),
		) );
	}

	/** The bell: a dashicon, the number on a magenta dot; on a phone too (WordPress hides unknown items there). */
	public static function bar_css() {
		if ( ! is_admin_bar_showing() || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_add_inline_style( 'admin-bar', '#wpadminbar #wp-admin-bar-oys-activity .ab-icon:before{content:"\f16d";top:2px}'
			. '#wpadminbar #wp-admin-bar-oys-activity.oys-has-new .ab-label{display:inline-block;min-width:9px;margin-left:2px;padding:0 5px;border-radius:9px;background:#db2e5c;color:#fff;font-size:11px;font-weight:700;line-height:17px;text-align:center}'
			. '@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-oys-activity{display:block;position:relative}#wpadminbar #wp-admin-bar-oys-activity.oys-has-new .ab-label{position:absolute;top:5px;right:2px}}' );
	}

	public static function prune() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', oys_utc_plus( -self::KEEP * DAY_IN_SECONDS ) ) );
	}

	/* ---------- the last 30 days, from what the site already knows (once) ---------- */

	public static function maybe_backfill() {
		global $wpdb;
		if ( '1' === get_option( self::BACKFILL ) || get_option( 'oys_db_version' ) !== OYS_DB_VERSION ) {
			return;
		}
		update_option( self::BACKFILL, '1', false );
		if ( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ) ) {
			return;
		}
		self::in_lang( fn() => self::backfill() );
	}

	/** @return int entries written */
	public static function backfill( $days = 30 ) {
		global $wpdb;
		$cut  = oys_utc_plus( -$days * DAY_IN_SECONDS );
		$cutT = time() - $days * DAY_IN_SECONDS;
		$rows = array();
		$add  = function ( $type, $at, array $a ) use ( &$rows, $cut ) {
			if ( $at && $at >= $cut ) {
				$rows[] = array( $type, $at, $a );
			}
		};
		// new accounts (not the guests brought over from Motibro), their logins and passwords
		foreach ( get_users( array( 'role' => 'oys_customer', 'number' => 3000 ) ) as $u ) {
			$p = self::person( $u->ID );
			if ( ! get_user_meta( $u->ID, 'oys_imported_at', true ) ) {
				$add( 'signup', $u->user_registered, array( 'title' => sprintf( __( 'New account: %s', 'olivia-studio' ), self::name( $u->ID ) ), 'detail' => $u->user_email, 'ref' => 'u' . $u->ID ) + $p );
			}
			foreach ( (array) get_user_meta( $u->ID, 'session_tokens', true ) as $tok ) {
				if ( ! empty( $tok['login'] ) && (int) $tok['login'] >= $cutT ) {
					$add( 'login', gmdate( 'Y-m-d H:i:s', (int) $tok['login'] ), array( 'title' => sprintf( __( 'Logged in: %s', 'olivia-studio' ), self::name( $u->ID ) ), 'ref' => 'l' . $u->ID . '-' . (int) $tok['login'] ) + $p );
				}
			}
			$key = (string) $u->user_activation_key;
			if ( preg_match( '/^(\d{9,}):/', $key, $m ) && (int) $m[1] >= $cutT ) {
				$add( 'reset_request', gmdate( 'Y-m-d H:i:s', (int) $m[1] ), array( 'title' => sprintf( __( 'Asked for a new password: %s', 'olivia-studio' ), self::name( $u->ID ) ), 'detail' => __( 'The link to set a password went out by email.', 'olivia-studio' ), 'ref' => 'r' . $u->ID . '-' . $m[1] ) + $p );
			}
			$act = (string) get_user_meta( $u->ID, 'oys_activated_at', true );
			if ( '' !== $act ) {
				$at = ctype_digit( $act ) ? gmdate( 'Y-m-d H:i:s', (int) $act ) : $act;
				$add( 'password_set', $at, array( 'title' => sprintf( __( 'Set a password: %s', 'olivia-studio' ), self::name( $u->ID ) ), 'detail' => __( 'A guest from Motibro: the account is active now.', 'olivia-studio' ), 'ref' => 'a' . $u->ID ) + $p );
			}
		}
		// payments
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'orders' ) . ' WHERE created_at >= %s OR paid_at >= %s', $cut, $cut ) ) as $o ) {
			if ( (int) $o->amount_cents > 0 && ( $e = self::order_entry( $o, __( 'Checkout started: %s', 'olivia-studio' ) ) ) ) {
				$add( 'checkout', $o->created_at, $e + array( 'ref' => 'oc' . $o->id ) );
			}
			if ( $o->paid_at && in_array( $o->status, array( 'paid', 'refunded', 'partially_refunded' ), true ) && ( $e = self::order_entry( $o, __( 'Paid: %s', 'olivia-studio' ) ) ) ) {
				$add( 'paid', $o->paid_at, $e + array( 'ref' => 'op' . $o->id ) );
			}
			if ( in_array( $o->status, array( 'failed', 'expired' ), true ) && ( $e = self::order_entry( $o, 'failed' === $o->status ? __( 'Payment failed: %s', 'olivia-studio' ) : __( 'Payment abandoned: %s', 'olivia-studio' ) ) ) ) {
				$add( 'failed' === $o->status ? 'failed' : 'abandoned', $o->created_at, $e + array( 'ref' => 'ou' . $o->id ) );
			}
		}
		// bookings and cancellations (not the visits brought over from Motibro)
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'bookings' ) . " WHERE guest_of = 0 AND paid_with <> 'import' AND ( created_at >= %s OR cancelled_at >= %s )", $cut, $cut ) ) as $b ) {
			if ( self::is_staff( (int) $b->user_id ) ) {
				continue;
			}
			$s = OYS_Schedule::get( $b->session_id );
			$p = self::person( $b->user_id ) + array( 'open' => $s ? 's' . (int) $s->id : '' );
			if ( in_array( $b->status, array( 'confirmed', 'attended', 'no_show', 'cancelled' ), true ) ) {
				$add( 'booked', $b->created_at, array( 'title' => sprintf( __( 'New booking: %s', 'olivia-studio' ), self::name( $b->user_id ) ), 'detail' => self::class_line( $s ), 'ref' => 'b' . $b->id ) + $p );
			}
			if ( $b->cancelled_at ) {
				$add( 'cancelled', $b->cancelled_at, array( 'title' => sprintf( __( 'Cancelled: %s', 'olivia-studio' ), self::name( $b->user_id ) ), 'detail' => self::class_line( $s ), 'ref' => 'bc' . $b->id ) + $p );
			}
		}
		// the newsletter list
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'subscribers' ) . ' WHERE created_at >= %s OR confirmed_at >= %s OR unsub_at >= %s', $cut, $cut, $cut ) ) as $r ) {
			$who = $r->name ? $r->name . ' (' . $r->email . ')' : $r->email;
			if ( 'admin' !== $r->source ) {
				$add( 'nl_signup', $r->created_at, array( 'title' => sprintf( __( 'Newsletter signup: %s', 'olivia-studio' ), $who ), 'detail' => self::nl_where( $r->source, $r->page ), 'email' => $r->email, 'ref' => 'ns' . $r->id ) );
			}
			$add( 'nl_confirmed', $r->confirmed_at, array( 'title' => sprintf( __( 'Newsletter confirmed: %s', 'olivia-studio' ), $who ), 'email' => $r->email, 'ref' => 'nc' . $r->id ) );
			$add( 'nl_unsub', $r->unsub_at, array( 'title' => sprintf( __( 'Unsubscribed from the newsletter: %s', 'olivia-studio' ), $who ), 'email' => $r->email, 'ref' => 'nu' . $r->id ) );
		}
		// messages, requests, replies in the Hub
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT m.id, m.body, m.kind, m.created_at, m.thread_id FROM ' . OYS_Hub::t( 'thread_msgs' ) . " m WHERE m.from_studio = 0 AND m.kind IN ('', 'contact', 'request') AND m.created_at >= %s", $cut ) ) as $m ) {
			$th = OYS_Hub::get( (int) $m->thread_id );
			if ( $th ) {
				$add( self::message_type( $m->kind ), $m->created_at, self::message_entry( $th, (string) $m->body, (string) $m->kind ) + array( 'ref' => 'm' . $m->id ) );
			}
		}
		usort( $rows, fn( $a, $b ) => strcmp( $a[1], $b[1] ) );
		self::$backfilling = true;
		$n = 0;
		foreach ( $rows as $r ) {
			$n += self::log( $r[0], $r[2] + array( 'at' => $r[1] ) ) ? 1 : 0;
		}
		self::$backfilling = false;
		// what was there before the tab existed is not „new”
		$max = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::table() );
		foreach ( get_users( array( 'capability' => self::CAP, 'fields' => 'ID' ) ) as $uid ) {
			self::mark_seen( $max, (int) $uid );
		}
		return $n;
	}

	/** „From the footer on oliviajoga.hu” – where a newsletter signup came from. */
	public static function nl_where( $source, $page ) {
		$host = (string) wp_parse_url( (string) $page, PHP_URL_HOST );
		$path = (string) wp_parse_url( (string) $page, PHP_URL_PATH );
		$from = $host ? preg_replace( '/^www\./', '', $host ) . ( $path && '/' !== $path ? rawurldecode( $path ) : '' ) : '';
		$src  = '' !== (string) $source ? str_replace( '_', ' ', (string) $source ) : '';
		return trim( ( $from ? sprintf( __( 'from %s', 'olivia-studio' ), $from ) : '' ) . ( $src ? ' (' . $src . ')' : '' ) );
	}
}

OYS_Activity::init();
