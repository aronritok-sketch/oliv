<?php
/**
 * The Hub screen in wp-admin (Studio → Hub, the first page; for a teacher Teaching → Hub). One app-like screen for
 * the phone and the desktop: Today (the next classes with who's coming, the room, the numbers), Conversations
 * (messages, class chats, requests in one list, the customer's card beside the conversation), More (every other
 * Studio page as a tile) and the teacher's own settings. The data comes from OYS_Hub's REST routes.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Hub_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'teacher_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'appbar' ) );
		// on the Hub the WordPress menu folds to icons: the Hub has its own navigation
		add_filter( 'admin_body_class', function ( $c ) {
			return self::is_hub_screen() ? $c . ' oys-hub-screen folded' : $c;
		} );
	}

	public static function is_hub_screen() {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return is_admin() && in_array( $page, array( 'oys', 'oys-hub' ), true );
	}

	/** A teacher who isn't the studio gets the Hub first under Teaching. */
	public static function teacher_menu() {
		if ( current_user_can( 'oys_teach' ) && ! current_user_can( 'oys_manage' ) ) {
			add_submenu_page( 'oys-teach', __( 'Hub', 'olivia-studio' ), __( 'Hub', 'olivia-studio' ), 'oys_teach', 'oys-hub', array( __CLASS__, 'page' ), 0 );
		}
	}

	/** Any Studio / Teaching page (not the Hub itself). */
	public static function is_studio_screen() {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return is_admin() && str_starts_with( $page, 'oys' ) && ! self::is_hub_screen();
	}

	/**
	 * Áron, 16 November: „Az admint optimalizáld le teljesen telefonra” – on every Studio page a phone gets the Hub's
	 * bottom bar (Hub, messages, calendar, rosters, more) and its tables turn into cards (admin-mobile.js + admin.css).
	 */
	public static function appbar() {
		if ( ! self::is_studio_screen() || ! OYS_Hub::can() ) {
			return;
		}
		$manage = current_user_can( 'oys_manage' );
		$hub    = admin_url( 'admin.php?page=' . ( $manage ? 'oys' : 'oys-hub' ) );
		$page   = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$n      = OYS_Hub::counts()['total'];
		$svg    = fn( $d ) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
		$items  = array(
			array( $hub . '#today', __( 'Today', 'olivia-studio' ), '<path d="M4 11 12 4l8 7v8.5a1 1 0 0 1-1 1h-4.5V15h-5v5.5H5a1 1 0 0 1-1-1z"/>', false ),
			array( $hub . '#inbox', __( 'Messages', 'olivia-studio' ), '<path d="M4.5 6.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H11l-4.5 3.5V16.5a2 2 0 0 1-2-2z"/>', false, $n ),
			$manage ? array( admin_url( 'admin.php?page=oys-calendar' ), __( 'Calendar', 'olivia-studio' ), '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>', 'oys-calendar' === $page ) : array( admin_url( 'admin.php?page=oys-teach' ), __( 'My classes', 'olivia-studio' ), '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>', 'oys-teach' === $page ),
			$manage ? array( admin_url( 'admin.php?page=oys-schedule' ), __( 'Rosters', 'olivia-studio' ), '<path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01"/>', 'oys-schedule' === $page ) : null,
			array( $hub . '#more', __( 'More', 'olivia-studio' ), '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>', ! in_array( $page, array( 'oys-calendar', 'oys-schedule', 'oys-teach' ), true ) ),
		);
		echo '<nav class="oys-appbar" aria-label="' . esc_attr__( 'Studio', 'olivia-studio' ) . '">';
		foreach ( array_filter( $items ) as $it ) {
			echo '<a href="' . esc_url( $it[0] ) . '"' . ( $it[3] ? ' class="is-on" aria-current="page"' : '' ) . '>' . $svg( $it[2] ) . esc_html( $it[1] ) . ( ! empty( $it[4] ) ? '<i>' . (int) $it[4] . '</i>' : '' ) . '</a>'; // phpcs:ignore
		}
		echo '</nav>';
	}

	public static function enqueue() {
		if ( self::is_studio_screen() ) {
			wp_enqueue_script( 'oys-admin-mobile', OYS_URL . 'assets/admin-mobile.js', array(), OYS_VERSION, true );
		}
		if ( ! self::is_hub_screen() ) {
			return;
		}
		wp_enqueue_style( 'oys-hub', OYS_URL . 'assets/hub.css', array(), OYS_VERSION );
		wp_enqueue_script( 'oys-hub', OYS_URL . 'assets/hub.js', array(), OYS_VERSION, true );
		wp_localize_script( 'oys-hub', 'OYS_HUB', self::boot() );
	}

	private static function boot() {
		$manage = current_user_can( 'oys_manage' );
		$u      = wp_get_current_user();
		$a      = fn( $page, $args = '' ) => admin_url( 'admin.php?page=' . $page . $args );
		$links  = $manage ? array(
			array( 'calendar', __( 'Calendar', 'olivia-studio' ), $a( 'oys-calendar' ), __( 'Classes, move, add', 'olivia-studio' ) ),
			array( 'list', __( 'Rosters & list', 'olivia-studio' ), $a( 'oys-schedule' ), __( 'Who is coming, check-in', 'olivia-studio' ) ),
			array( 'star', __( 'Private requests', 'olivia-studio' ), $a( 'oys-private' ), __( 'Offers, one-to-one', 'olivia-studio' ) ),
			array( 'users', __( 'Customers', 'olivia-studio' ), $a( 'oys-customers' ), __( 'Passes, history, notes', 'olivia-studio' ) ),
			array( 'card', __( 'Payments', 'olivia-studio' ), $a( 'oys-orders' ), __( 'Receipts, refunds', 'olivia-studio' ) ),
			array( 'tag', __( 'Prices & passes', 'olivia-studio' ), $a( 'oys-products' ), __( 'What people can buy', 'olivia-studio' ) ),
			array( 'gift', __( 'Gift cards', 'olivia-studio' ), $a( 'oys-gifts' ), '' ),
			array( 'percent', __( 'Coupons & rewards', 'olivia-studio' ), $a( 'oys-rewards' ), '' ),
			array( 'send', __( 'Newsletter', 'olivia-studio' ), $a( 'oys-newsletter' ), '' ),
			array( 'mail', __( 'Emails & reminders', 'olivia-studio' ), $a( 'oys-emails' ), '' ),
			array( 'chart', __( 'Overview', 'olivia-studio' ), $a( 'oys-today' ), __( 'Numbers, next 7 days', 'olivia-studio' ) ),
			array( 'teacher', __( 'Teachers', 'olivia-studio' ), $a( 'oys-teachers' ), '' ),
			array( 'gear', __( 'Settings', 'olivia-studio' ), $a( 'oys-settings' ), '' ),
			array( 'globe', __( 'Open the website', 'olivia-studio' ), home_url( '/' ), '' ),
		) : array(
			array( 'calendar', __( 'My classes', 'olivia-studio' ), $a( 'oys-teach' ), __( 'Rosters, write to the class', 'olivia-studio' ) ),
			array( 'user', __( 'My profile', 'olivia-studio' ), $a( 'oys-teach-profile' ), __( 'Photos, introduction', 'olivia-studio' ) ),
			array( 'card', __( 'My statement', 'olivia-studio' ), $a( 'oys-teach-statement' ), '' ),
			array( 'globe', __( 'Open the website', 'olivia-studio' ), home_url( '/' ), '' ),
		);
		return array(
			'root'    => esc_url_raw( rest_url( OYS_Hub::NS . '/hub/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'manage'  => $manage,
			'name'    => $u->first_name ?: $u->display_name,
			'today'   => oys_date( oys_now(), 'l, F j' ),
			'links'   => $links,
			'logout'  => wp_logout_url( home_url( '/' ) ),
			'emails'  => $manage ? $a( 'oys-emails' ) : '',
			'privUrl' => $a( 'oys-private', '&request=' ),
			'boot'    => array( 'today' => OYS_Hub::today(), 'me' => OYS_Hub::prefs() + array( 'name' => OYS_Hub::chat_name( $u->ID ) ) ),
			'i18n'    => self::strings(),
		);
	}

	private static function strings() {
		return array(
			'hub'           => __( 'Hub', 'olivia-studio' ),
			'hi'            => __( 'Hi, %s!', 'olivia-studio' ),
			'today'         => __( 'Today', 'olivia-studio' ),
			'inbox'         => __( 'Messages', 'olivia-studio' ),
			'calendar'      => __( 'Calendar', 'olivia-studio' ),
			'more'          => __( 'More', 'olivia-studio' ),
			'settings'      => __( 'My settings', 'olivia-studio' ),
			'all'           => __( 'All', 'olivia-studio' ),
			'unread'        => __( 'Unread', 'olivia-studio' ),
			'messages'      => __( 'Conversations', 'olivia-studio' ),
			'chats'         => __( 'Class chats', 'olivia-studio' ),
			'requests'      => __( 'Requests', 'olivia-studio' ),
			'done'          => __( 'Done', 'olivia-studio' ),
			'search'        => __( 'Search names, messages…', 'olivia-studio' ),
			'empty'         => __( 'Nothing here. When someone writes, it shows up here.', 'olivia-studio' ),
			'pick'          => __( 'Choose a conversation on the left.', 'olivia-studio' ),
			'newMsg'        => __( 'New message', 'olivia-studio' ),
			'to'            => __( 'To whom?', 'olivia-studio' ),
			'findCustomer'  => __( 'Type a name or email', 'olivia-studio' ),
			'write'         => __( 'Write a message…', 'olivia-studio' ),
			'writeNote'     => __( 'A note only you see…', 'olivia-studio' ),
			'send'          => __( 'Send', 'olivia-studio' ),
			'photo'         => __( 'Photo', 'olivia-studio' ),
			'addPhoto'      => __( 'Add a photo', 'olivia-studio' ),
			'removePhoto'   => __( 'Remove the photo', 'olivia-studio' ),
			'quick'         => __( 'Quick answers', 'olivia-studio' ),
			'note'          => __( 'Note', 'olivia-studio' ),
			'noteHint'      => __( 'Internal note: the customer doesn\'t see it', 'olivia-studio' ),
			'markDone'      => __( 'Done', 'olivia-studio' ),
			'reopen'        => __( 'Reopen', 'olivia-studio' ),
			'back'          => __( 'Back', 'olivia-studio' ),
			'info'          => __( 'Details', 'olivia-studio' ),
			'you'           => __( 'You', 'olivia-studio' ),
			'seen'          => __( 'Read', 'olivia-studio' ),
			'sent'          => __( 'Sent', 'olivia-studio' ),
			'website'       => __( 'From the website', 'olivia-studio' ),
			'request'       => __( 'Private request', 'olivia-studio' ),
			'makeOffer'     => __( 'Send an offer', 'olivia-studio' ),
			'openRequest'   => __( 'Open the request', 'olivia-studio' ),
			'internal'      => __( 'Note', 'olivia-studio' ),
			'classChat'     => __( 'Class chat', 'olivia-studio' ),
			'room'          => __( 'Go to the room', 'olivia-studio' ),
			'roomNow'       => __( 'Start the class', 'olivia-studio' ),
			'roster'        => __( 'Roster', 'olivia-studio' ),
			'chat'          => __( 'Chat', 'olivia-studio' ),
			'booked'        => __( '%1$d of %2$d booked', 'olivia-studio' ),
			'waitlist'      => __( '%d on the waitlist', 'olivia-studio' ),
			'nobody'        => __( 'Nobody has booked yet.', 'olivia-studio' ),
			'health'        => __( 'Health note', 'olivia-studio' ),
			'noClasses'     => __( 'No classes in the next days.', 'olivia-studio' ),
			'nextClasses'   => __( 'Next classes', 'olivia-studio' ),
			'needsYou'      => __( 'Waiting for you', 'olivia-studio' ),
			'newMessages'   => __( 'New messages', 'olivia-studio' ),
			'chatUnread'    => __( 'Class chats', 'olivia-studio' ),
			'newRequests'   => __( 'Private requests', 'olivia-studio' ),
			'allClear'      => __( 'All answered. 🌿', 'olivia-studio' ),
			'numbers'       => __( 'Numbers', 'olivia-studio' ),
			'payments'      => __( 'Latest payments', 'olivia-studio' ),
			'customer'      => __( 'Customer', 'olivia-studio' ),
			'passes'        => __( 'Passes', 'olivia-studio' ),
			'noPasses'      => __( 'No valid pass.', 'olivia-studio' ),
			'left'          => __( '%1$d of %2$d left', 'olivia-studio' ),
			'until'         => __( 'until %s', 'olivia-studio' ),
			'upcoming'      => __( 'Booked classes', 'olivia-studio' ),
			'noUpcoming'    => __( 'No upcoming class.', 'olivia-studio' ),
			'privates'      => __( 'Private requests', 'olivia-studio' ),
			'since'         => __( 'Customer since %s', 'olivia-studio' ),
			'visits'        => __( '%d classes so far', 'olivia-studio' ),
			'openCustomer'  => __( 'Open the customer page', 'olivia-studio' ),
			'call'          => __( 'Call', 'olivia-studio' ),
			'email'         => __( 'Email', 'olivia-studio' ),
			'noAccount'     => __( 'No account yet: wrote on the website. Your answer goes by email.', 'olivia-studio' ),
			'people'        => __( 'In this class', 'olivia-studio' ),
			'chatClosed'    => __( 'The chat is closed for the participants; you can still write.', 'olivia-studio' ),
			'deleteMsg'     => __( 'Remove', 'olivia-studio' ),
			'confirmDelete' => __( 'Remove this message from the class chat?', 'olivia-studio' ),
			'error'         => __( 'Something went wrong. Try again.', 'olivia-studio' ),
			'offline'       => __( 'No connection. Retrying…', 'olivia-studio' ),
			'notifyOn'      => __( 'Turn on notifications', 'olivia-studio' ),
			'notifyIsOn'    => __( 'Notifications are on on this device', 'olivia-studio' ),
			'notifyHelp'    => __( 'While the Hub is open (also in a background tab), this device shows a notification when someone writes.', 'olivia-studio' ),
			'notifyTitle'   => __( 'New in the Hub', 'olivia-studio' ),
			'emailMe'       => __( 'Email me', 'olivia-studio' ),
			'emailMessages' => __( 'when someone writes to me', 'olivia-studio' ),
			'emailChat'     => __( 'when someone writes in a class chat (not during the class)', 'olivia-studio' ),
			'emailNote'     => __( 'At most one email per conversation every 15 minutes, and none while the Hub is open.', 'olivia-studio' ),
			'studioEmails'  => __( 'The studio\'s emails are switched on and off under Emails & reminders.', 'olivia-studio' ),
			'chatName'      => __( 'My name in the class chats', 'olivia-studio' ),
			'replies'       => __( 'My quick answers', 'olivia-studio' ),
			'repliesHelp'   => __( 'One per line. In a conversation, tap „Quick answers” and choose one.', 'olivia-studio' ),
			'save'          => __( 'Save', 'olivia-studio' ),
			'saved'         => __( 'Saved.', 'olivia-studio' ),
			'logout'        => __( 'Log out', 'olivia-studio' ),
			'loadMore'      => __( 'Earlier messages', 'olivia-studio' ),
			'justNow'       => __( 'now', 'olivia-studio' ),
		);
	}

	public static function page() {
		echo '<div class="oys-hub-wrap"><div id="oys-hub" class="hub" aria-live="polite"><p class="hub__loading">' . esc_html__( 'Loading…', 'olivia-studio' ) . '</p></div>'
			. '<noscript><p>' . esc_html__( 'The Hub needs JavaScript.', 'olivia-studio' ) . '</p></noscript></div>';
	}
}

OYS_Hub_Admin::init();
