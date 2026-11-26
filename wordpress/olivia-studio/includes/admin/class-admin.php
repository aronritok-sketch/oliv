<?php
/**
 * Studio dashboard in wp-admin: today, schedule and rosters, weekly timetable,
 * private requests, customers, orders, gift cards, prices and settings.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Admin {

	const CAP = 'oys_manage';

	/** Settings that are secrets: shown as empty password fields, an empty field keeps the saved value. */
	const SECRETS = array( 'stripe_test_secret', 'stripe_test_webhook', 'stripe_live_secret', 'stripe_live_webhook', 'zoom_client_secret', 'livekit_api_secret', 'smtp_pass' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( str_contains( $hook, 'oys' ) ) {
				wp_enqueue_style( 'oys-admin', OYS_URL . 'assets/admin.css', array(), OYS_VERSION );
			}
			if ( str_ends_with( $hook, '_page_oys-calendar' ) ) {
				OYS_Calendar::enqueue();
			}
		} );
		$actions = array( 'save_session', 'cancel_session', 'roster', 'save_template', 'delete_template', 'generate', 'private_offer', 'private_decline',
			'grant_pass', 'adjust_pass', 'refund', 'save_product', 'delete_product', 'save_settings', 'cancel_booking', 'membership', 'zoom_test', 'live_test', 'live_test_open', 'zoom_start', 'zoom_create', 'save_emails', 'save_email_template', 'reset_email_template', 'test_email', 'smtp_test', 'hpv_register', 'message', 'pay_later_reset', 'customer_extend', 'customer_newsletter', 'save_locations', 'coupon_create', 'coupon_delete', 'save_rewards', 'raffle_draw', 'newsletter_save', 'newsletter_ai', 'newsletter_test', 'newsletter_send', 'newsletter_delete', 'save_ai', 'import_customers', 'subscriber_add', 'subscriber_remove', 'subscribers_export' );
		foreach ( $actions as $a ) {
			add_action( 'admin_post_oys_admin_' . $a, array( __CLASS__, 'guard' ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	public static function menu() {
		$new  = count( OYS_Privates::query( 'new' ) );
		$wait = OYS_Hub::counts()['total']; // conversations waiting, unread class chats, new requests
		// Áron, 16 November: the Hub is the studio's first page – one screen for messages, class chats and today
		add_menu_page( __( 'Studio', 'olivia-studio' ), __( 'Studio', 'olivia-studio' ) . ( $wait ? ' <span class="awaiting-mod">' . $wait . '</span>' : '' ), self::CAP, 'oys', array( 'OYS_Hub_Admin', 'page' ), 'dashicons-universal-access', 3 );
		$pages = array(
			'oys'           => array( __( 'Hub', 'olivia-studio' ) . ( $wait ? ' <span class="awaiting-mod">' . $wait . '</span>' : '' ), array( 'OYS_Hub_Admin', 'page' ) ),
			'oys-today'     => array( __( 'Overview', 'olivia-studio' ), 'page_today' ),
			'oys-calendar'  => array( __( 'Calendar', 'olivia-studio' ), 'page_calendar' ),
			'oys-schedule'  => array( __( 'Rosters & list', 'olivia-studio' ), 'page_schedule' ),
			'oys-private'   => array( __( 'Private requests', 'olivia-studio' ) . ( $new ? ' <span class="awaiting-mod">' . $new . '</span>' : '' ), 'page_private' ),
			'oys-customers' => array( __( 'Customers', 'olivia-studio' ), 'page_customers' ),
			'oys-members'   => array( __( 'Memberships', 'olivia-studio' ), 'page_members' ),
			'oys-orders'    => array( __( 'Payments', 'olivia-studio' ), 'page_orders' ),
			'oys-gifts'     => array( __( 'Gift cards', 'olivia-studio' ), 'page_gifts' ),
			'oys-products'  => array( __( 'Prices & passes', 'olivia-studio' ), 'page_products' ),
			'oys-locations' => array( __( 'Locations', 'olivia-studio' ), 'page_locations' ),
			'oys-newsletter' => array( __( 'Newsletter', 'olivia-studio' ), 'page_newsletter' ),
			'oys-rewards'   => array( __( 'Coupons & rewards', 'olivia-studio' ), 'page_rewards' ),
			'oys-emails'    => array( __( 'Emails & reminders', 'olivia-studio' ), 'page_emails' ),
			'oys-settings'  => array( __( 'Settings', 'olivia-studio' ), 'page_settings' ),
		);
		foreach ( $pages as $slug => $p ) {
			add_submenu_page( 'oys', wp_strip_all_tags( $p[0] ), $p[0], self::CAP, $slug, is_array( $p[1] ) ? $p[1] : array( __CLASS__, $p[1] ) );
		}
		// The weekly timetable as a table; the calendar does the same with less typing.
		add_submenu_page( '', __( 'Weekly timetable', 'olivia-studio' ), '', self::CAP, 'oys-templates', array( __CLASS__, 'page_templates' ) );
	}

	public static function page_calendar() {
		OYS_Calendar::page();
	}

	public static function setup_notice() {
		if ( ! current_user_can( self::CAP ) || OYS_Settings::payments_ready() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && str_contains( $screen->id, 'oys' ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Online payments are off until you add your Stripe keys in <a href="%s">Studio → Settings</a>. Bookings with passes and free sessions still work.', 'olivia-studio' ), esc_url( admin_url( 'admin.php?page=oys-settings' ) ) ) ) . '</p></div>';
		}
	}

	/** Every admin action goes through here: capability + nonce, then the handler. */
	public static function guard() {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do this.', 'olivia-studio' ), 403 );
		}
		check_admin_referer( $action );
		$method = 'do_' . substr( $action, strlen( 'oys_admin_' ) );
		call_user_func( array( __CLASS__, $method ) );
	}

	private static function form( $action, $extra = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '><input type="hidden" name="action" value="oys_admin_' . esc_attr( $action ) . '">' . wp_nonce_field( 'oys_admin_' . $action, '_wpnonce', true, false );
	}

	private static function back( $page, $args = array(), $msg = '', $type = 'success' ) {
		if ( $msg ) {
			$args['oys_msg'] = rawurlencode( $msg );
		}
		if ( 'error' === $type ) {
			$args['oys_err'] = 1;
		}
		oys_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
	}

	private static function header( $title, $actions = '' ) {
		echo '<div class="wrap oys-wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<hr class="wp-header-end">'; // phpcs:ignore
		if ( ! empty( $_GET['oys_msg'] ) ) {
			echo '<div class="notice ' . ( empty( $_GET['oys_err'] ) ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( wp_unslash( $_GET['oys_msg'] ) ) . '</p></div>';
		}
	}

	/** Which language a class is taught in. */
	private static function lang_select( $current, $attrs = '' ) {
		$out = '<select name="lang" ' . $attrs . ' title="' . esc_attr__( 'Language of the class', 'olivia-studio' ) . '">';
		foreach ( OYS_I18n::LANGS as $code => $l ) {
			$out .= '<option value="' . esc_attr( $code ) . '"' . selected( oys_valid_lang( $current ), $code, false ) . '>' . esc_html( sprintf( __( 'In %s', 'olivia-studio' ), $l['name'] ) ) . '</option>';
		}
		return $out . '</select>';
	}

	private static function user_label( $user_id, $link = true ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return '#' . (int) $user_id;
		}
		$name = esc_html( $u->display_name ?: $u->user_email );
		return $link ? '<a href="' . esc_url( admin_url( 'admin.php?page=oys-customers&user=' . $u->ID ) ) . '">' . $name . '</a>' : $name;
	}

	/* ======================================================================
	   Today
	   ====================================================================== */

	public static function page_today() {
		global $wpdb;
		self::header( __( 'Studio · Today', 'olivia-studio' ) );
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'today', $tz ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$today = OYS_Schedule::query( array( 'from' => $start->format( 'Y-m-d H:i:s' ), 'to' => $start->modify( '+1 day' )->format( 'Y-m-d H:i:s' ), 'status' => 'scheduled' ) );
		$week  = OYS_Schedule::query( array( 'from' => oys_now(), 'to' => oys_utc_plus( 7 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) );
		$o     = OYS_Install::table( 'orders' );
		// Payments made to a teacher's own Stripe count with the studio's fee only.
		$rev30 = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT amount_cents, meta FROM $o WHERE status IN ('paid','partially_refunded') AND paid_at >= %s", oys_utc_plus( -30 * DAY_IN_SECONDS ) ) ) as $row ) {
			$meta   = json_decode( (string) $row->meta, true ) ?: array();
			$rev30 += empty( $meta['stripe_account'] ) ? (int) $row->amount_cents : (int) ( $meta['app_fee_cents'] ?? 0 );
		}
		$book7 = array_sum( array_map( fn( $s ) => (int) $s->booked, $week ) );
		$cap7  = array_sum( array_map( fn( $s ) => (int) $s->capacity, $week ) );
		$new   = OYS_Privates::query( 'new' );
		$cust  = count( get_users( array( 'role' => 'oys_customer', 'fields' => 'ID' ) ) );

		echo '<div class="oys-kpis">';
		$kpis = array(
			array( __( 'Revenue, last 30 days', 'olivia-studio' ), oys_money( $rev30 ) ),
			array( __( 'Booked, next 7 days', 'olivia-studio' ), $book7 . ' / ' . $cap7 ),
			array( __( 'Active members · MRR', 'olivia-studio' ), count( array_filter( OYS_Memberships::query(), fn( $m ) => in_array( $m->status, OYS_Memberships::BOOKABLE, true ) ) ) . ' · ' . oys_money( OYS_Memberships::mrr() ) ),
			array( __( 'New private requests', 'olivia-studio' ), count( $new ) ),
			array( __( 'Customers', 'olivia-studio' ), $cust ),
		);
		foreach ( $kpis as $k ) {
			echo '<div class="oys-kpi"><span>' . esc_html( $k[0] ) . '</span><b>' . esc_html( $k[1] ) . '</b></div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Today', 'olivia-studio' ) . '</h2>';
		if ( ! $today ) {
			echo '<p>' . esc_html__( 'No sessions today.', 'olivia-studio' ) . '</p>';
		}
		foreach ( $today as $s ) {
			self::roster_box( $s );
		}
		if ( $new ) {
			echo '<h2>' . esc_html__( 'Private requests waiting for an answer', 'olivia-studio' ) . '</h2><ul class="oys-plain">';
			foreach ( $new as $r ) {
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=oys-private&request=' . $r->id ) ) . '">' . self::user_label( $r->user_id, false ) . ' · ' . (int) $r->duration_min . ' min · ' . esc_html( oys_date( $r->created_at ) ) . '</a></li>'; // phpcs:ignore
			}
			echo '</ul>';
		}
		echo '<h2>' . esc_html__( 'Next 7 days', 'olivia-studio' ) . '</h2>';
		self::sessions_table( $week );
		echo '</div>';
	}

	private static function roster_box( $s ) {
		$bookings = OYS_Bookings::for_session( $s->id, array( 'confirmed', 'attended', 'no_show' ) );
		echo '<div class="oys-box"><h3>' . esc_html( oys_time( $s->starts_at ) . ' · ' . oys_session_title( $s ) ) . ' <small>' . (int) $s->booked . '/' . (int) $s->capacity . '</small> <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&session=' . $s->id ) ) . '">' . esc_html__( 'Roster', 'olivia-studio' ) . '</a></h3>';
		if ( $bookings ) {
			echo '<ol>';
			foreach ( $bookings as $b ) {
				$health = get_user_meta( $b->user_id, 'oys_health_notes', true );
				$health = $b->guest_of ? '' : $health;
				echo '<li>' . ( $b->guest_of ? '<span class="oys-guest-tag">' . esc_html__( 'Guest', 'olivia-studio' ) . '</span> ' . esc_html( OYS_Bookings::person_label( $b ) ) : self::user_label( $b->user_id ) ) . ( 'attended' === $b->status ? ' ✓' : '' ) . ( $health ? ' <span class="oys-flag" title="' . esc_attr( $health ) . '">' . esc_html__( 'health note', 'olivia-studio' ) . '</span>' : '' ) . '</li>'; // phpcs:ignore
			}
			echo '</ol>';
		}
		echo '</div>';
	}

	private static function sessions_table( $sessions ) {
		if ( ! $sessions ) {
			echo '<p>' . esc_html__( 'Nothing scheduled.', 'olivia-studio' ) . '</p>';
			return;
		}
		$kinds = OYS_Schedule::kinds();
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'When', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Session', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Type', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Booked', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Waitlist', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Price', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $sessions as $s ) {
			$wl = count( OYS_Bookings::waitlist( $s->id ) );
			echo '<tr' . ( 'cancelled' === $s->status ? ' class="is-cancelled"' : '' ) . '><td>' . esc_html( oys_date( $s->starts_at, 'D M j · g:i a' ) ) . '</td><td><b>' . esc_html( oys_session_title( $s ) ) . '</b><br><small>' . esc_html( $s->location ) . '</small></td><td>' . esc_html( $kinds[ $s->kind ] ?? $s->kind ) . '</td>'
				. '<td><span class="oys-fill"><span style="width:' . (int) ( $s->capacity ? min( 100, 100 * $s->booked / $s->capacity ) : 0 ) . '%"></span></span> ' . (int) $s->booked . '/' . (int) $s->capacity . '</td><td>' . ( $wl ? (int) $wl : '—' ) . '</td><td>' . esc_html( $s->price_cents ? oys_money( $s->price_cents ) : '—' ) . '</td><td>' . esc_html( $s->status ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&session=' . $s->id ) ) . '">' . esc_html__( 'Roster', 'olivia-studio' ) . '</a> <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&edit=' . $s->id ) ) . '">' . esc_html__( 'Edit', 'olivia-studio' ) . '</a></td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table>';
	}

	/* ======================================================================
	   Schedule, session edit, roster
	   ====================================================================== */

	public static function page_schedule() {
		if ( isset( $_GET['session'] ) ) {
			self::page_roster( (int) $_GET['session'] );
			return;
		}
		if ( isset( $_GET['edit'] ) ) {
			self::page_session_edit( (int) $_GET['edit'] );
			return;
		}
		self::header( __( 'Rosters & list', 'olivia-studio' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-calendar' ) ) . '">' . esc_html__( 'Open the calendar', 'olivia-studio' ) . '</a> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&edit=0' ) ) . '">' . esc_html__( 'Add session or event', 'olivia-studio' ) . '</a> <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-templates' ) ) . '">' . esc_html__( 'Weekly timetable (table)', 'olivia-studio' ) . '</a>' );
		$view = sanitize_key( $_GET['view'] ?? 'upcoming' );
		echo '<ul class="subsubsub"><li><a href="' . esc_url( admin_url( 'admin.php?page=oys-schedule' ) ) . '"' . ( 'past' !== $view ? ' class="current"' : '' ) . '>' . esc_html__( 'Upcoming', 'olivia-studio' ) . '</a> | </li><li><a href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&view=past' ) ) . '"' . ( 'past' === $view ? ' class="current"' : '' ) . '>' . esc_html__( 'Past', 'olivia-studio' ) . '</a></li></ul><br class="clear">';
		$sessions = 'past' === $view
			? OYS_Schedule::query( array( 'to' => oys_now(), 'desc' => true, 'limit' => 100 ) )
			: OYS_Schedule::query( array( 'from' => oys_utc_plus( -3 * HOUR_IN_SECONDS ), 'limit' => 200 ) );
		self::sessions_table( $sessions );
		echo '</div>';
	}

	private static function page_session_edit( $id ) {
		$s = $id ? OYS_Schedule::get( $id ) : null;
		self::header( $s ? __( 'Edit session', 'olivia-studio' ) : __( 'Add session or event', 'olivia-studio' ) );
		$v = $s ?: (object) array( 'kind' => 'event', 'class_slug' => '', 'title' => '', 'description' => '', 'starts_at' => '', 'ends_at' => '', 'capacity' => 20, 'location' => '', 'format' => 'studio', 'online_url' => '', 'price_cents' => 3500, 'credits_allowed' => 0, 'note' => '', 'status' => 'scheduled' );
		$dur = $s ? (int) round( ( oys_ts( $s->ends_at ) - oys_ts( $s->starts_at ) ) / 60 ) : 90;
		echo self::form( 'save_session', 'class="oys-form"' ) . '<input type="hidden" name="id" value="' . (int) $id . '"><table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Type', 'olivia-studio' ) . '</th><td><select name="kind">';
		foreach ( OYS_Schedule::kinds() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $v->kind, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr><tr><th>' . esc_html__( 'Class', 'olivia-studio' ) . '</th><td><select name="class_slug"><option value="">' . esc_html__( '— none (use title) —', 'olivia-studio' ) . '</option>';
		foreach ( oys_class_options() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $v->class_slug, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Title (events)', 'olivia-studio' ) . '</th><td><input class="regular-text" name="title" value="' . esc_attr( $v->title ) . '" placeholder="Live-Music Slow Flow with Handpan"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Description', 'olivia-studio' ) . '</th><td><textarea name="description" rows="4" class="large-text">' . esc_textarea( $v->description ) . '</textarea></td></tr>';
		echo '<tr><th>' . esc_html__( 'Starts', 'olivia-studio' ) . '</th><td><input type="datetime-local" name="starts" required value="' . esc_attr( oys_utc_to_local_input( $v->starts_at ) ) . '"> <label>' . esc_html__( 'Length (min)', 'olivia-studio' ) . ' <input type="number" name="duration" min="15" step="5" value="' . (int) $dur . '" class="small-text"></label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Capacity', 'olivia-studio' ) . '</th><td><input type="number" name="capacity" min="1" value="' . (int) $v->capacity . '" class="small-text"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Where', 'olivia-studio' ) . '</th><td><select name="format">' . implode( '', array_map( fn( $k, $l ) => '<option value="' . esc_attr( $k ) . '"' . selected( $v->format ?: 'studio', $k, false ) . '>' . esc_html( $l ) . '</option>', array_keys( OYS_Schedule::formats() ), OYS_Schedule::formats() ) ) . '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Location', 'olivia-studio' ) . '</th><td><input class="regular-text" name="location" value="' . esc_attr( $v->location ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Online link (Zoom etc.)', 'olivia-studio' ) . '</th><td><input class="regular-text" type="url" name="online_url" value="' . esc_attr( $v->online_url ) . '"><p class="description">' . esc_html__( 'Only shown to people who booked.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Price', 'olivia-studio' ) . '</th><td><input name="price" value="' . esc_attr( $v->price_cents / 100 ) . '" class="small-text"> ' . esc_html( strtoupper( OYS_Settings::get( 'currency' ) ) ) . ' <label><input type="checkbox" name="credits_allowed" value="1"' . checked( 1, (int) $v->credits_allowed, false ) . '> ' . esc_html__( 'Class passes can be used', 'olivia-studio' ) . '</label>'
			. '<br><label><input type="checkbox" name="donation" value="1"' . checked( 'donation', $v->pricing ?? 'fixed', false ) . '> ' . esc_html__( 'By donation (the price is the suggested amount)', 'olivia-studio' ) . '</label>'
			. '<br><label><input type="checkbox" name="pay_later" value="1"' . checked( 1, (int) ( $v->pay_later ?? 1 ), false ) . '> ' . esc_html__( 'People can pay at the studio', 'olivia-studio' ) . '</label></td></tr>';
		$teachers = OYS_Teachers::all();
		if ( $teachers ) {
			echo '<tr><th>' . esc_html__( 'Teacher', 'olivia-studio' ) . '</th><td><select name="teacher_id"><option value="0">' . esc_html( OYS_Settings::get( 'owner_name' ) ) . '</option>';
			foreach ( $teachers as $t ) {
				echo '<option value="' . (int) $t->id . '"' . selected( (int) ( $v->teacher_id ?? 0 ), (int) $t->id, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Short note', 'olivia-studio' ) . '</th><td><input class="regular-text" name="note" value="' . esc_attr( $v->note ) . '" placeholder="Sunrise on the sand, weather permitting"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Taught in', 'olivia-studio' ) . '</th><td>' . self::lang_select( $v->lang ?? 'en' ) . '</td></tr>'; // phpcs:ignore
		echo '</table>';
		submit_button( $s ? __( 'Save session', 'olivia-studio' ) : __( 'Create session', 'olivia-studio' ) );
		echo '</form>';
		if ( $s && 'scheduled' === $s->status ) {
			echo '<hr><h2>' . esc_html__( 'Cancel this session', 'olivia-studio' ) . '</h2><p>' . esc_html__( 'Everyone booked is emailed. Pass bookings get their class back; card payments become a class credit (refund in Payments if you prefer).', 'olivia-studio' ) . '</p>'
				. self::form( 'cancel_session', 'onsubmit="return confirm(\'' . esc_js( __( 'Cancel the session and email everyone?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $s->id . '"><p><input class="regular-text" name="reason" placeholder="' . esc_attr__( 'Reason shown in the email (optional)', 'olivia-studio' ) . '"></p>'; // phpcs:ignore
			submit_button( __( 'Cancel session', 'olivia-studio' ), 'delete' );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function do_save_session() {
		$id    = (int) $_POST['id'];
		$start = oys_local_to_utc( sanitize_text_field( wp_unslash( $_POST['starts'] ) ) );
		$dur   = max( 15, (int) $_POST['duration'] );
		$kinds = OYS_Schedule::kinds();
		$kind  = sanitize_key( $_POST['kind'] );
		$data  = array(
			'kind'            => isset( $kinds[ $kind ] ) ? $kind : 'event',
			'class_slug'      => sanitize_title( wp_unslash( $_POST['class_slug'] ) ),
			'title'           => sanitize_text_field( wp_unslash( $_POST['title'] ) ),
			'description'     => sanitize_textarea_field( wp_unslash( $_POST['description'] ) ),
			'starts_at'       => $start,
			'ends_at'         => gmdate( 'Y-m-d H:i:s', oys_ts( $start ) + $dur * MINUTE_IN_SECONDS ),
			'capacity'        => max( 1, (int) $_POST['capacity'] ),
			'location'        => sanitize_text_field( wp_unslash( $_POST['location'] ) ),
			'format'          => in_array( $_POST['format'] ?? '', array( 'online', 'hybrid' ), true ) ? sanitize_key( $_POST['format'] ) : 'studio',
			'online_url'      => esc_url_raw( wp_unslash( $_POST['online_url'] ) ),
			'price_cents'     => oys_cents_from_input( wp_unslash( $_POST['price'] ) ),
			'credits_allowed' => empty( $_POST['credits_allowed'] ) ? 0 : 1,
			'pricing'         => empty( $_POST['donation'] ) ? 'fixed' : 'donation',
			'pay_later'       => empty( $_POST['pay_later'] ) ? 0 : 1,
			'note'            => sanitize_text_field( wp_unslash( $_POST['note'] ) ),
			'teacher_id'      => OYS_Teachers::valid_id( $_POST['teacher_id'] ?? 0 ),
			'lang'            => oys_valid_lang( $_POST['lang'] ?? 'en' ),
		);
		$clash = OYS_Schedule::overlap( $data['starts_at'], $data['ends_at'], $data['teacher_id'], $id );
		if ( $clash ) {
			self::back( 'oys-schedule', $id ? array( 'session' => $id ) : array( 'new' => 1 ), sprintf( __( 'Not saved: this overlaps with %s. One teacher can only lead one class at a time.', 'olivia-studio' ), OYS_Schedule::clash_label( $clash ) ), 'error' );
		}
		if ( ! $id ) {
			$data['status'] = 'scheduled';
		}
		$id = OYS_Schedule::save( $data, $id );
		self::back( 'oys-schedule', array( 'session' => $id ), __( 'Session saved.', 'olivia-studio' ) );
	}

	private static function do_cancel_session() {
		$n = OYS_Schedule::cancel_session( (int) $_POST['id'], sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::back( 'oys-schedule', array(), sprintf( __( 'Session cancelled. %d people were emailed.', 'olivia-studio' ), $n ) );
	}

	private static function page_roster( $id ) {
		$s = OYS_Schedule::get( $id );
		if ( ! $s ) {
			self::header( __( 'Session not found', 'olivia-studio' ) );
			echo '</div>';
			return;
		}
		OYS_Schedule::recount( $id );
		$s = OYS_Schedule::get( $id );
		self::header( oys_session_title( $s ) . ' · ' . oys_date( $s->starts_at, 'D M j, g:i a' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-calendar&week=' . wp_date( 'Y-m-d', oys_ts( $s->starts_at ) ) . '&open=' . $id ) ) . '">' . esc_html__( 'Edit in calendar', 'olivia-studio' ) . '</a>' );
		echo '<p>' . ( OYS_Teachers::name_for( $s ) ? esc_html( sprintf( __( 'Teacher: %s', 'olivia-studio' ), OYS_Teachers::name_for( $s ) ) ) . ' · ' : '' ) . esc_html( oys_is_online( $s ) ? __( 'Online', 'olivia-studio' ) : $s->location ) . ' · ' . sprintf( esc_html__( '%1$d of %2$d booked', 'olivia-studio' ), (int) $s->booked, (int) $s->capacity )
			. ( oys_is_hybrid( $s ) ? ' · ' . sprintf( esc_html__( '%1$d online (%2$s)', 'olivia-studio' ), (int) $s->online_booked, (int) $s->online_capacity ? sprintf( esc_html__( 'of %d', 'olivia-studio' ), (int) $s->online_capacity ) : esc_html__( 'no limit', 'olivia-studio' ) ) : '' )
			. ' · ' . esc_html( $s->status )
			. ( oys_is_donation( $s ) ? ' · ' . esc_html__( 'by donation', 'olivia-studio' ) : '' )
			. ( ( $due = OYS_Bookings::due_at_studio( $id ) ) ? ' · <b>' . sprintf( esc_html__( 'To collect at the studio: %s', 'olivia-studio' ), esc_html( oys_money( $due ) ) ) . '</b>' : '' ) . '</p>';
		echo self::live_box( $s ); // phpcs:ignore
		$bookings = OYS_Bookings::for_session( $id );
		$st       = OYS_Bookings::statuses();
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Name', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Phone', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Paid with', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Health notes', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Attendance', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $bookings as $b ) {
			if ( 'expired' === $b->status ) {
				continue;
			}
			$active = in_array( $b->status, array( 'confirmed', 'attended', 'no_show' ), true );
			$labels = OYS_Bookings::paid_with_labels();
			$name   = $b->guest_of
				? '<span class="oys-guest-tag">' . esc_html__( 'Guest', 'olivia-studio' ) . '</span> <b>' . esc_html( $b->guest_name ) . '</b><br><small>' . sprintf( esc_html__( 'with %s', 'olivia-studio' ), self::user_label( $b->user_id ) ) . ( $b->guest_email ? ' · ' . esc_html( $b->guest_email ) : '' ) . '</small>'
				: self::user_label( $b->user_id );
			if ( oys_is_hybrid( $s ) && 'online' === $b->mode ) {
				$name .= ' <span class="oys-online-tag">' . esc_html__( 'Online', 'olivia-studio' ) . '</span>';
			}
			echo '<tr' . ( $b->guest_of ? ' class="oys-guest-row"' : '' ) . '><td>' . $name . '</td><td>' . esc_html( $b->guest_of ? '' : get_user_meta( $b->user_id, 'oys_phone', true ) ) . '</td><td>' . self::paid_cell( $b, $id ) . '</td><td class="oys-health">' . esc_html( $b->guest_of ? '' : get_user_meta( $b->user_id, 'oys_health_notes', true ) ) . '</td><td>' . esc_html( $st[ $b->status ] ?? $b->status ) . '</td><td>'; // phpcs:ignore
			if ( $active ) {
				echo self::form( 'roster', 'class="oys-inline"' ) . '<input type="hidden" name="session" value="' . (int) $id . '"><input type="hidden" name="booking" value="' . (int) $b->id . '">' // phpcs:ignore
					. '<button name="do" value="attended" class="button button-small' . ( 'attended' === $b->status ? ' button-primary' : '' ) . '">' . esc_html__( 'Here', 'olivia-studio' ) . '</button> '
					. '<button name="do" value="no_show" class="button button-small">' . esc_html__( 'No-show', 'olivia-studio' ) . '</button></form> ';
				if ( 'confirmed' === $b->status ) {
					echo self::form( 'cancel_booking', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Cancel this booking and give the class back?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="session" value="' . (int) $id . '"><input type="hidden" name="booking" value="' . (int) $b->id . '"><button class="button button-small button-link-delete">' . esc_html__( 'Cancel', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
				}
			}
			echo '</td></tr>';
		}
		if ( ! $bookings ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No bookings yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add someone', 'olivia-studio' ) . '</h2>' . self::form( 'roster', 'class="oys-inline-form"' ) . '<input type="hidden" name="session" value="' . (int) $id . '"><input type="hidden" name="do" value="add">' // phpcs:ignore
			. '<input type="email" name="email" required placeholder="' . esc_attr__( 'Email', 'olivia-studio' ) . '"> <input type="text" name="name" placeholder="' . esc_attr__( 'Name (new customers)', 'olivia-studio' ) . '"> '
			. '<select name="paid_with"><option value="credit">' . esc_html__( 'Use their pass', 'olivia-studio' ) . '</option><option value="cash">' . esc_html__( 'Paid at the door', 'olivia-studio' ) . '</option><option value="door">' . esc_html__( 'Will pay at the studio', 'olivia-studio' ) . '</option><option value="comp">' . esc_html__( 'Complimentary', 'olivia-studio' ) . '</option></select> '
			. '<label><input type="checkbox" name="notify" value="1" checked> ' . esc_html__( 'Email confirmation', 'olivia-studio' ) . '</label> <button class="button button-primary">' . esc_html__( 'Add to roster', 'olivia-studio' ) . '</button></form>';

		self::message_box( $s );

		$wl = OYS_Bookings::waitlist( $id );
		if ( $wl ) {
			echo '<h2>' . esc_html__( 'Waitlist', 'olivia-studio' ) . '</h2><ol>';
			foreach ( $wl as $w ) {
				echo '<li>' . self::user_label( $w->user_id ) . ' · ' . esc_html( oys_date( $w->created_at ) ) . ( $w->notified_at ? ' · ' . esc_html__( 'notified', 'olivia-studio' ) : '' ) . '</li>'; // phpcs:ignore
			}
			echo '</ol>';
		}
		echo '</div>';
	}

	/** Roster "Paid with" cell; pay-at-the-studio bookings get "Paid" buttons until collected. */
	private static function paid_cell( $b, $session_id ) {
		$labels = OYS_Bookings::paid_with_labels();
		if ( 'door' !== $b->paid_with ) {
			return esc_html( $labels[ $b->paid_with ] ?? $b->paid_with );
		}
		if ( $b->collected_with ) {
			return esc_html( sprintf( __( 'Paid at the studio (%s)', 'olivia-studio' ), 'cash' === $b->collected_with ? __( 'cash', 'olivia-studio' ) : __( 'other', 'olivia-studio' ) ) ) . ' · ' . esc_html( oys_money( (int) $b->due_cents ) );
		}
		$out = '<b class="oys-due-tag">' . sprintf( esc_html__( 'Pays at the studio: %s', 'olivia-studio' ), esc_html( oys_money( (int) $b->due_cents ) ) ) . '</b>';
		if ( in_array( $b->status, array( 'confirmed', 'attended', 'no_show' ), true ) ) {
			$out .= '<br>' . self::form( 'roster', 'class="oys-inline"' ) . '<input type="hidden" name="session" value="' . (int) $session_id . '"><input type="hidden" name="booking" value="' . (int) $b->id . '">'
				. '<button name="do" value="collect_cash" class="button button-small">' . esc_html__( 'Paid: cash', 'olivia-studio' ) . '</button> '
				. '<button name="do" value="collect_other" class="button button-small">' . esc_html__( 'Paid: other', 'olivia-studio' ) . '</button></form>';
		}
		return $out;
	}

	/** Roster: write to everyone booked, and what was sent before. */
	private static function message_box( $s ) {
		echo '<h2 id="message">' . esc_html__( 'Message everyone booked', 'olivia-studio' ) . '</h2>';
		if ( 'scheduled' === $s->status && oys_ts( $s->ends_at ) > time() - DAY_IN_SECONDS ) {
			echo self::form( 'message', 'class="oys-form oys-message"' ) . '<input type="hidden" name="session" value="' . (int) $s->id . '">' // phpcs:ignore
				. '<p><label>' . esc_html__( 'Subject', 'olivia-studio' ) . '<br><input class="large-text" name="subject" required value="' . esc_attr( sprintf( __( 'About %1$s on %2$s', 'olivia-studio' ), '{class}', '{day} {date_short}' ) ) . '"></label></p>'
				. '<p><label>' . esc_html__( 'Message', 'olivia-studio' ) . '<br><textarea class="large-text" name="body" rows="5" required placeholder="' . esc_attr__( 'Hi {first_name}, …', 'olivia-studio' ) . '"></textarea></label></p>'
				. '<p class="description">' . esc_html__( 'Everyone gets their own email, with the class details and a link to their bookings. You can use {first_name}, {class}, {day}, {date_short}, {time}, {location} and {teacher}.', 'olivia-studio' ) . '</p>'
				. '<p><label><input type="checkbox" name="guests" value="1" checked> ' . esc_html__( 'Also guests who gave an email', 'olivia-studio' ) . '</label> &nbsp; <label><input type="checkbox" name="waitlist" value="1"> ' . esc_html__( 'Also the waitlist', 'olivia-studio' ) . '</label></p>'
				. '<p><button class="button button-primary">' . esc_html__( 'Send the message', 'olivia-studio' ) . '</button></p></form>';
		}
		$sent = OYS_Messages::for_session( $s->id );
		if ( $sent ) {
			echo '<ul class="oys-plain oys-sent">';
			foreach ( $sent as $m ) {
				echo '<li><b>' . esc_html( $m->subject ) . '</b> · ' . esc_html( oys_date( $m->created_at, 'M j, g:i a' ) ) . ' · ' . esc_html( sprintf( _n( '%d person', '%d people', (int) $m->recipients, 'olivia-studio' ), (int) $m->recipients ) ) . '<br><small>' . esc_html( wp_trim_words( $m->body, 30 ) ) . '</small></li>';
			}
			echo '</ul>';
		}
	}

	private static function do_message() {
		$session_id = (int) $_POST['session'];
		$res        = OYS_Messages::send_to_session( $session_id, wp_unslash( $_POST['subject'] ?? '' ), wp_unslash( $_POST['body'] ?? '' ), array( 'guests' => ! empty( $_POST['guests'] ), 'waitlist' => ! empty( $_POST['waitlist'] ) ) );
		self::back( 'oys-schedule', array( 'session' => $session_id ), is_wp_error( $res ) ? $res->get_error_message() : sprintf( _n( 'Message sent to %d person.', 'Message sent to %d people.', $res, 'olivia-studio' ), $res ) );
	}

	/** Customer page: their own pass extension when the studio cancels their private session (empty = the default). */
	private static function do_customer_extend() {
		$user_id = (int) $_POST['user'];
		$days    = trim( (string) wp_unslash( $_POST['days'] ?? '' ) );
		'' === $days ? delete_user_meta( $user_id, 'oys_cancel_extend_days' ) : update_user_meta( $user_id, 'oys_cancel_extend_days', max( 0, (int) $days ) );
		self::back( 'oys-customers', array( 'user' => $user_id ), __( 'Saved.', 'olivia-studio' ) );
	}

	private static function do_customer_newsletter() {
		$user_id = (int) $_POST['user'];
		OYS_Newsletter::set_customer( $user_id, ! empty( $_POST['on'] ) );
		self::back( 'oys-customers', array( 'user' => $user_id ), empty( $_POST['on'] ) ? __( 'The newsletter is off for them.', 'olivia-studio' ) : __( 'They get the newsletter again.', 'olivia-studio' ) );
	}

	private static function do_pay_later_reset() {
		$user_id = (int) $_POST['user'];
		update_user_meta( $user_id, 'oys_pay_later_reset', oys_now() );
		self::back( 'oys-customers', array( 'user' => $user_id ), __( 'They can pay at the studio again.', 'olivia-studio' ) );
	}

	/** Online / hybrid class: the Zoom meeting (start as host, create) or the link typed in. */
	public static function live_box( $s ) {
		if ( ! oys_has_online( $s ) || 'scheduled' !== $s->status ) {
			return '';
		}
		$out = '<div class="oys-box oys-live"><h3>' . esc_html__( 'Live online', 'olivia-studio' ) . '</h3>';
		if ( $s->online_url ) {
			return $out . '<p>' . esc_html__( 'Link set on the class:', 'olivia-studio' ) . ' <a href="' . esc_url( $s->online_url ) . '" target="_blank" rel="noopener">' . esc_html( $s->online_url ) . '</a></p></div>';
		}
		if ( OYS_Live::uses_live( $s ) ) {
			return $out . '<p><a class="button button-primary" href="' . esc_url( OYS_Live::url( $s->id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the live room (teacher)', 'olivia-studio' ) . '</a></p>'
				. '<p class="description">' . esc_html( OYS_Live::admin_status( $s ) ) . ' ' . esc_html__( 'People joining online get their own link by email and in their account.', 'olivia-studio' ) . '</p></div>';
		}
		if ( ! OYS_Zoom::enabled() ) {
			return $out . '<p>' . wp_kses_post( sprintf( __( 'Add a link to the class in the calendar, or connect Zoom in <a href="%s">Settings</a> to create meetings automatically.', 'olivia-studio' ), esc_url( admin_url( 'admin.php?page=oys-settings#zoom' ) ) ) ) . '</p></div>';
		}
		$start = wp_nonce_url( admin_url( 'admin-post.php?action=oys_admin_zoom_start&session=' . (int) $s->id ), 'oys_admin_zoom_start' );
		if ( OYS_Zoom::has_meeting( $s ) ) {
			return $out . '<p><a class="button button-primary" href="' . esc_url( $start ) . '" target="_blank" rel="noopener">' . esc_html__( 'Start the Zoom class (host)', 'olivia-studio' ) . '</a> '
				. sprintf( esc_html__( 'Meeting %s', 'olivia-studio' ), '<code>' . esc_html( $s->zoom_meeting_id ) . '</code>' ) . ( $s->zoom_password ? ' · ' . esc_html__( 'passcode', 'olivia-studio' ) . ' <code>' . esc_html( $s->zoom_password ) . '</code>' : '' ) . '</p>'
				. '<p class="description">' . esc_html__( 'People joining online get their link by email and in their account.', 'olivia-studio' ) . '</p></div>';
		}
		return $out . '<p>' . esc_html__( 'The Zoom meeting is created when the first person books online, or a day before the class.', 'olivia-studio' ) . '</p>'
			. self::form( 'zoom_create', 'class="oys-inline"' ) . '<input type="hidden" name="session" value="' . (int) $s->id . '"><button class="button">' . esc_html__( 'Create the Zoom meeting now', 'olivia-studio' ) . '</button></form></div>';
	}

	private static function do_roster() {
		$session_id = (int) $_POST['session'];
		$do         = sanitize_key( $_POST['do'] ?? '' );
		if ( 'add' === $do ) {
			$user_id = OYS_Customers::find_or_create( wp_unslash( $_POST['email'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
			if ( is_wp_error( $user_id ) ) {
				self::back( 'oys-schedule', array( 'session' => $session_id ), $user_id->get_error_message() );
			}
			$notify = ! empty( $_POST['notify'] );
			$paid   = sanitize_key( $_POST['paid_with'] ?? 'comp' );
			$res    = 'credit' === $paid ? OYS_Bookings::book_with_credit( $user_id, $session_id, $notify ) : OYS_Bookings::book_manual( $user_id, $session_id, in_array( $paid, array( 'cash', 'comp', 'door' ), true ) ? $paid : 'comp', $notify, true );
			self::back( 'oys-schedule', array( 'session' => $session_id ), is_wp_error( $res ) ? $res->get_error_message() : __( 'Added to the roster.', 'olivia-studio' ) );
		}
		if ( in_array( $do, array( 'collect_cash', 'collect_other' ), true ) ) {
			OYS_Bookings::collect( (int) $_POST['booking'], substr( $do, 8 ) );
			self::back( 'oys-schedule', array( 'session' => $session_id ), __( 'Marked as paid.', 'olivia-studio' ) );
		}
		OYS_Bookings::set_attendance( (int) $_POST['booking'], $do );
		self::back( 'oys-schedule', array( 'session' => $session_id ) );
	}

	private static function do_cancel_booking() {
		$res = OYS_Bookings::cancel( (int) $_POST['booking'], array( 'by_studio' => true ) );
		self::back( 'oys-schedule', array( 'session' => (int) $_POST['session'] ), is_wp_error( $res ) ? $res->get_error_message() : __( 'Booking cancelled; the customer was emailed.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Weekly timetable
	   ====================================================================== */

	public static function page_templates() {
		self::header( __( 'Weekly timetable', 'olivia-studio' ) );
		echo '<p>' . sprintf( esc_html__( 'Each row repeats every week. Sessions are created automatically %d weeks ahead; change one date (or cancel it) under Schedule & rosters.', 'olivia-studio' ), (int) OYS_Settings::get( 'weeks_ahead' ) ) . '</p>';
		$days = OYS_Schedule::weekdays();
		$cls  = oys_class_options();
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Day', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Start', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Min', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Class', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Spots', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Drop-in', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Location / online link / note', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Active', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		$rows   = OYS_Schedule::templates();
		$rows[] = (object) array( 'id' => 0, 'class_slug' => '', 'weekday' => 1, 'start_time' => '18:00', 'duration_min' => 60, 'capacity' => 12, 'location' => '', 'online_url' => '', 'price_cents' => 2500, 'lang' => 'en', 'note' => '', 'active' => 1 );
		foreach ( $rows as $t ) {
			$fid = 'tpl-' . (int) $t->id;
			echo '<tr' . ( $t->id ? '' : ' class="oys-new-row"' ) . '><td><select form="' . $fid . '" name="weekday">';
			foreach ( $days as $n => $d ) {
				echo '<option value="' . (int) $n . '"' . selected( (int) $t->weekday, $n, false ) . '>' . esc_html( $d ) . '</option>';
			}
			echo '</select></td><td><input form="' . $fid . '" type="time" name="start_time" value="' . esc_attr( $t->start_time ) . '"></td><td><input form="' . $fid . '" type="number" name="duration_min" class="small-text" value="' . (int) $t->duration_min . '"></td><td><select form="' . $fid . '" name="class_slug">';
			foreach ( $cls as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $t->class_slug, $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></td><td><input form="' . $fid . '" type="number" name="capacity" class="small-text" value="' . (int) $t->capacity . '"></td><td><input form="' . $fid . '" name="price" class="small-text" value="' . esc_attr( $t->price_cents / 100 ) . '"></td>'
				. '<td><input form="' . $fid . '" name="location" value="' . esc_attr( $t->location ) . '" placeholder="' . esc_attr__( 'Location', 'olivia-studio' ) . '"><br><input form="' . $fid . '" type="url" name="online_url" value="' . esc_attr( $t->online_url ) . '" placeholder="https://zoom.us/…"><br><input form="' . $fid . '" name="note" value="' . esc_attr( $t->note ) . '" placeholder="' . esc_attr__( 'Note', 'olivia-studio' ) . '"><br>' . self::lang_select( $t->lang ?? 'en', 'form="' . $fid . '"' ) . '</td>'
				. '<td><input form="' . $fid . '" type="checkbox" name="active" value="1"' . checked( 1, (int) $t->active, false ) . '></td><td>';
			echo self::form( 'save_template', 'id="' . $fid . '"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="button button-primary button-small">' . ( $t->id ? esc_html__( 'Save', 'olivia-studio' ) : esc_html__( 'Add', 'olivia-studio' ) ) . '</button></form>'; // phpcs:ignore
			if ( $t->id ) {
				echo ' ' . self::form( 'delete_template', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove this weekly class? Already created dates stay until you cancel them.', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="button button-small button-link-delete">' . esc_html__( 'Remove', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>' . self::form( 'generate' ); // phpcs:ignore
		submit_button( __( 'Create upcoming dates now', 'olivia-studio' ), 'secondary' );
		echo '</form></div>';
	}

	private static function do_save_template() {
		$data = array(
			'class_slug'   => wp_unslash( $_POST['class_slug'] ?? '' ),
			'weekday'      => $_POST['weekday'] ?? 1,
			'start_time'   => sanitize_text_field( $_POST['start_time'] ?? '' ),
			'duration_min' => $_POST['duration_min'] ?? 60,
			'capacity'     => $_POST['capacity'] ?? 12,
			'location'     => wp_unslash( $_POST['location'] ?? '' ),
			'online_url'   => wp_unslash( $_POST['online_url'] ?? '' ),
			'price_cents'  => oys_cents_from_input( wp_unslash( $_POST['price'] ?? '0' ) ),
			'note'         => wp_unslash( $_POST['note'] ?? '' ),
			'lang'         => $_POST['lang'] ?? 'en',
			'active'       => $_POST['active'] ?? 0,
		);
		$tid   = (int) $_POST['id'];
		$old   = $tid ? OYS_Schedule::template( $tid ) : null;
		$clash = ! empty( $data['active'] ) ? OYS_Schedule::template_overlap( min( 7, max( 1, (int) $data['weekday'] ) ), (string) $data['start_time'], max( 15, (int) $data['duration_min'] ), (int) ( $old->teacher_id ?? 0 ), $tid ) : null;
		if ( $clash ) {
			self::back( 'oys-templates', array(), sprintf( __( 'Not saved: this overlaps with %s. One teacher can only lead one class at a time.', 'olivia-studio' ), OYS_Schedule::clash_label( $clash ) ), 'error' );
		}
		$id = OYS_Schedule::save_template( $data, $tid );
		$made = OYS_Schedule::generate();
		self::back( 'oys-templates', array(), sprintf( __( 'Saved. %d new dates created.', 'olivia-studio' ), $made ) );
	}

	private static function do_delete_template() {
		OYS_Schedule::delete_template( (int) $_POST['id'] );
		self::back( 'oys-templates', array(), __( 'Weekly class removed.', 'olivia-studio' ) );
	}

	private static function do_generate() {
		self::back( 'oys-templates', array(), sprintf( __( '%d new dates created.', 'olivia-studio' ), OYS_Schedule::generate() ) );
	}

	/* ======================================================================
	   Private requests
	   ====================================================================== */

	public static function page_private() {
		$types = OYS_Privates::location_types();
		$st    = OYS_Privates::statuses();
		if ( ! empty( $_GET['request'] ) ) {
			$r = OYS_Privates::get( (int) $_GET['request'] );
			if ( $r ) {
				$u = get_userdata( $r->user_id );
				self::header( sprintf( __( 'Private request from %s', 'olivia-studio' ), $u ? $u->display_name : '' ) );
				echo '<div class="oys-box"><p><b>' . self::user_label( $r->user_id ) . '</b> · ' . esc_html( $u ? $u->user_email : '' ) . ' · ' . esc_html( get_user_meta( $r->user_id, 'oys_phone', true ) ) . '</p>' // phpcs:ignore
					. '<p><b>' . esc_html( OYS_Privates::topic_label( $r->topic ?? '', (int) $r->people ) ) . '</b> · ' . (int) $r->duration_min . ' min · ' . (int) $r->people . ' ' . esc_html__( 'people', 'olivia-studio' ) . ' · ' . esc_html( $types[ $r->location_type ] ?? '' ) . ' · ' . esc_html( $r->address ) . '</p>'
					. '<p><b>' . esc_html__( 'Preferred times', 'olivia-studio' ) . '</b><br>' . nl2br( esc_html( $r->preferred ) ) . '</p>'
					. '<p><b>' . esc_html__( 'Notes', 'olivia-studio' ) . '</b><br>' . nl2br( esc_html( $r->notes ) ) . '</p>'
					. '<p><b>' . esc_html__( 'Health notes on profile', 'olivia-studio' ) . '</b><br>' . nl2br( esc_html( get_user_meta( $r->user_id, 'oys_health_notes', true ) ) ) . '</p>'
					. '<p>' . esc_html__( 'Status', 'olivia-studio' ) . ': <b>' . esc_html( $st[ $r->status ] ?? $r->status ) . '</b></p></div>';
				if ( in_array( $r->status, array( 'new', 'offered' ), true ) ) {
					$s     = $r->session_id ? OYS_Schedule::get( $r->session_id ) : null;
					$svc   = OYS_Services::get( (string) ( $r->topic ?? '' ) );
					$price = $r->price_cents ?: ( $svc ? OYS_Services::price( $svc['key'], (int) $r->people, (int) $r->duration_min ) : OYS_Products::private_price_for( $r->duration_min, 'online' === $r->location_type ) );
					$durs  = array_unique( array_merge( $svc ? $svc['durations'] : array(), array( 60, 75, 90 ) ) );
					echo '<h2>' . ( $s ? esc_html__( 'Change the offer', 'olivia-studio' ) : esc_html__( 'Send an offer', 'olivia-studio' ) ) . '</h2>' . self::form( 'private_offer' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><table class="form-table">' // phpcs:ignore
						. '<tr><th>' . esc_html__( 'Date and time', 'olivia-studio' ) . '</th><td><input type="datetime-local" name="starts" required value="' . esc_attr( $s ? oys_utc_to_local_input( $s->starts_at ) : ( ! empty( $r->slot_at ) ? oys_utc_to_local_input( $r->slot_at ) : '' ) ) . '"></td></tr>'
						. '<tr><th>' . esc_html__( 'Length (min)', 'olivia-studio' ) . '</th><td><select name="duration">' . implode( '', array_map( fn( $d ) => '<option value="' . $d . '"' . selected( (int) $r->duration_min, $d, false ) . '>' . $d . '</option>', $durs ) ) . '</select></td></tr>'
						. '<tr><th>' . esc_html__( 'Time to talk through goals', 'olivia-studio' ) . '</th><td><label><input type="checkbox" name="extra" value="1"' . checked( $s ? oys_ts( $s->ends_at ) - oys_ts( $s->starts_at ) > (int) $r->duration_min * 60 : OYS_Privates::is_first( $r->user_id, $r->id ), true, false ) . '> ' . sprintf( esc_html__( 'Block %s extra minutes (not charged)', 'olivia-studio' ), '<input type="number" name="extra_min" min="5" step="5" class="small-text" value="' . esc_attr( max( 5, (int) OYS_Settings::get( 'private_first_extra_min' ) ) ) . '">' ) . '</label><p class="description">' . esc_html( OYS_Privates::is_first( $r->user_id, $r->id ) ? __( 'First private session with this person.', 'olivia-studio' ) : __( 'They have had a private session before.', 'olivia-studio' ) ) . '</p></td></tr>'
						. '<tr><th>' . esc_html__( 'Price', 'olivia-studio' ) . '</th><td><input name="price" class="small-text" value="' . esc_attr( $price / 100 ) . '"> ' . esc_html( strtoupper( OYS_Settings::get( 'currency' ) ) ) . '<p class="description">' . esc_html__( 'Small groups: set the total for the group.', 'olivia-studio' ) . '</p></td></tr>'
						. '<tr><th>' . esc_html__( 'Location', 'olivia-studio' ) . '</th><td><input class="regular-text" name="location" value="' . esc_attr( $s ? $s->location : $r->address ) . '"></td></tr>'
						. '<tr><th>' . esc_html__( 'Online link', 'olivia-studio' ) . '</th><td><input class="regular-text" type="url" name="online_url" value="' . esc_attr( $s ? $s->online_url : '' ) . '"></td></tr>'
						. '<tr><th>' . esc_html__( 'Message', 'olivia-studio' ) . '</th><td><textarea name="message" rows="3" class="large-text">' . esc_textarea( $r->admin_message ) . '</textarea></td></tr></table>';
					submit_button( __( 'Send offer by email', 'olivia-studio' ) );
					echo '</form>' . self::form( 'private_decline', 'onsubmit="return confirm(\'' . esc_js( __( 'Decline this request?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><p><input class="regular-text" name="message" placeholder="' . esc_attr__( 'Message (optional)', 'olivia-studio' ) . '"> <button class="button button-link-delete">' . esc_html__( 'Decline', 'olivia-studio' ) . '</button></p></form>'; // phpcs:ignore
				}
				echo '</div>';
				return;
			}
		}
		self::header( __( 'Private requests', 'olivia-studio' ) );
		$rows = OYS_Privates::query();
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Received', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Customer', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Request', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Session', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$s = $r->session_id ? OYS_Schedule::get( $r->session_id ) : null;
			echo '<tr><td>' . esc_html( oys_date( $r->created_at, 'M j, g:i a' ) ) . '</td><td>' . self::user_label( $r->user_id ) . '</td><td><b>' . esc_html( OYS_Privates::topic_label( $r->topic ?? '', (int) $r->people ) ) . '</b><br>' . (int) $r->duration_min . ' ' . esc_html__( 'min', 'olivia-studio' ) . ' · ' . (int) $r->people . ' ' . esc_html__( 'people', 'olivia-studio' ) . ' · ' . esc_html( $types[ $r->location_type ] ?? '' ) . '<br><small>' . esc_html( wp_trim_words( $r->preferred, 12 ) ) . '</small></td><td>' . ( $s ? esc_html( oys_date( $s->starts_at, 'D M j, g:i a' ) . ' · ' . oys_money( $r->price_cents ) ) : '—' ) . '</td><td><span class="oys-status oys-status--' . esc_attr( $r->status ) . '">' . esc_html( $st[ $r->status ] ?? $r->status ) . '</span></td><td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-private&request=' . $r->id ) ) . '">' . esc_html__( 'Open', 'olivia-studio' ) . '</a></td></tr>'; // phpcs:ignore
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No requests yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function do_private_offer() {
		$id  = (int) $_POST['id'];
		$res = OYS_Privates::offer( $id, sanitize_text_field( wp_unslash( $_POST['starts'] ) ), max( 30, (int) $_POST['duration'] ), oys_cents_from_input( wp_unslash( $_POST['price'] ) ), sanitize_text_field( wp_unslash( $_POST['location'] ) ), esc_url_raw( wp_unslash( $_POST['online_url'] ) ), sanitize_textarea_field( wp_unslash( $_POST['message'] ) ), empty( $_POST['extra'] ) ? 0 : max( 0, (int) ( $_POST['extra_min'] ?? 0 ) ) );
		self::back( 'oys-private', array( 'request' => $id ), is_wp_error( $res ) ? $res->get_error_message() : __( 'Offer sent to the customer.', 'olivia-studio' ) );
	}

	private static function do_private_decline() {
		OYS_Privates::decline( (int) $_POST['id'], sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );
		self::back( 'oys-private', array(), __( 'Request declined; the customer was emailed.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Customers
	   ====================================================================== */

	public static function page_customers() {
		if ( ! empty( $_GET['user'] ) ) {
			self::page_customer( (int) $_GET['user'] );
			return;
		}
		self::header( __( 'Customers', 'olivia-studio' ) );
		$q     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$users = get_users( array( 'role__in' => array( 'oys_customer' ), 'search' => $q ? '*' . $q . '*' : '', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 3000 ) );
		echo '<details class="oys-box"><summary><b>' . esc_html__( 'Import customers (Motibro export, .xlsx or .csv)', 'olivia-studio' ) . '</b></summary><p class="description">' . esc_html__( 'Every column of the export is kept with the customer. Nobody is emailed or subscribed to the newsletter; customers who already have an account keep what they entered, only empty fields are filled. The same file can be imported again safely.', 'olivia-studio' ) . '</p><p class="description">' . esc_html__( 'Three Motibro exports are recognised by their columns: Customers; Valid passes (what is left becomes online credits until the same day – a newer export updates them); Class visits (every class and visit becomes the customer\'s history).', 'olivia-studio' ) . '</p>'
			. str_replace( '<form ', '<form enctype="multipart/form-data" ', self::form( 'import_customers' ) ) . '<input type="file" name="file" accept=".xlsx,.csv" required> <label>' . esc_html__( 'Passes: bookable online until', 'olivia-studio' ) . ' <input type="date" name="online_until"></label> <label><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html__( 'Check only (change nothing)', 'olivia-studio' ) . '</label> <button class="button button-primary">' . esc_html__( 'Import', 'olivia-studio' ) . '</button></form></details>'; // phpcs:ignore
		echo OYS_Held::admin_box(); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<form method="get"><input type="hidden" name="page" value="oys-customers"><p class="search-box"><input type="search" name="s" value="' . esc_attr( $q ) . '"> <button class="button">' . esc_html__( 'Search', 'olivia-studio' ) . '</button></p></form>';
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Name', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Email', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Phone', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Classes left', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Private left', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Joined', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			echo '<tr><td>' . self::user_label( $u->ID ) . '</td><td>' . esc_html( $u->user_email ) . '</td><td>' . esc_html( get_user_meta( $u->ID, 'oys_phone', true ) ) . '</td><td>' . (int) OYS_Passes::balance( $u->ID, 'class' ) . '</td><td>' . (int) OYS_Passes::balance( $u->ID, 'private' ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $u->user_registered ) ) . '</td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div>';
	}

	private static function page_customer( $user_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			self::header( __( 'Customer not found', 'olivia-studio' ) );
			echo '</div>';
			return;
		}
		self::header( $u->display_name );
		$m = fn( $k ) => esc_html( get_user_meta( $user_id, $k, true ) );
		echo '<div class="oys-cols"><div class="oys-box"><h2>' . esc_html__( 'Profile', 'olivia-studio' ) . '</h2><p>' . esc_html( $u->user_email ) . '<br>' . $m( 'oys_phone' ) . '<br>' . $m( 'oys_area' ) . '</p>' // phpcs:ignore
			. '<p><b>' . esc_html__( 'Emergency', 'olivia-studio' ) . ':</b> ' . $m( 'oys_emergency_name' ) . ' ' . $m( 'oys_emergency_phone' ) . '</p>' // phpcs:ignore
			. '<p><b>' . esc_html__( 'Health notes', 'olivia-studio' ) . ':</b><br>' . nl2br( $m( 'oys_health_notes' ) ) . '</p>' // phpcs:ignore
			. '<p><b>' . esc_html__( 'Agreement', 'olivia-studio' ) . ':</b> ' . ( OYS_Customers::has_waiver( $user_id ) ? esc_html( sprintf( __( 'accepted %s', 'olivia-studio' ), oys_date( get_user_meta( $user_id, 'oys_waiver_at', true ) ) ) ) : esc_html__( 'not accepted (current version)', 'olivia-studio' ) ) . '</p>'
			. '<p><b>' . esc_html__( 'Newsletter', 'olivia-studio' ) . ':</b> ' . ( get_user_meta( $user_id, 'oys_marketing', true ) ? esc_html__( 'yes', 'olivia-studio' ) : esc_html__( 'no', 'olivia-studio' ) ) . ' '
			. self::form( 'customer_newsletter', 'class="oys-inline"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '"><input type="hidden" name="on" value="' . ( get_user_meta( $user_id, 'oys_marketing', true ) ? '0' : '1' ) . '"><button class="button button-small">' . esc_html( get_user_meta( $user_id, 'oys_marketing', true ) ? __( 'Switch it off', 'olivia-studio' ) : __( 'Switch it on (they asked for it)', 'olivia-studio' ) ) . '</button></form></p>'
			. self::pay_later_status( $user_id ) . '</div>';
		$imported = array_filter( array_map( fn( $k ) => (string) get_user_meta( $user_id, 'oys_' . $k, true ), array_combine( OYS_Import::FIELDS, OYS_Import::FIELDS ) ), 'strlen' );
		if ( $imported ) {
			echo '<div class="oys-box"><h2>' . esc_html__( 'Customer data', 'olivia-studio' ) . '</h2><table class="widefat oys-table"><tbody>';
			if ( '' !== (string) get_user_meta( $user_id, 'nickname', true ) ) {
				echo '<tr><th>' . esc_html__( 'Nickname', 'olivia-studio' ) . '</th><td>' . esc_html( get_user_meta( $user_id, 'nickname', true ) ) . '</td></tr>';
			}
			foreach ( OYS_Import::labels() as $k => $label ) {
				if ( isset( $imported[ $k ] ) ) {
					echo '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( 'ext_source' === $k ? ucfirst( $imported[ $k ] ) : $imported[ $k ] ) ) . '</td></tr>';
				}
			}
			echo '</tbody></table></div>';
		}

		echo '<div class="oys-box"><h2>' . esc_html__( 'Passes', 'olivia-studio' ) . '</h2><table class="widefat oys-table"><thead><tr><th>' . esc_html__( 'Pass', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Left', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Expires', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( OYS_Passes::for_user( $user_id ) as $p ) {
			echo '<tr><td>' . esc_html( $p->name ) . '<br><small>' . esc_html( $p->kind . ' · ' . $p->source ) . '</small></td><td colspan="3">' . self::form( 'adjust_pass', 'class="oys-inline"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '"><input type="hidden" name="pass" value="' . (int) $p->id . '">' // phpcs:ignore
				. '<input type="number" name="credits_left" min="0" class="small-text" value="' . (int) $p->credits_left . '"> / ' . (int) $p->credits_total . ' <input type="date" name="expires" value="' . esc_attr( $p->expires_at ? wp_date( 'Y-m-d', oys_ts( $p->expires_at ) ) : '' ) . '"> <button class="button button-small">' . esc_html__( 'Update', 'olivia-studio' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>' . OYS_Held::customer_box( $user_id ) . '<h3>' . esc_html__( 'Give classes', 'olivia-studio' ) . '</h3>' . self::form( 'grant_pass', 'class="oys-inline-form"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '">' // phpcs:ignore
			. '<select name="kind"><option value="class">' . esc_html__( 'Group classes', 'olivia-studio' ) . '</option><option value="private">' . esc_html__( 'Private sessions', 'olivia-studio' ) . '</option></select> '
			. '<input type="number" name="credits" min="1" value="1" class="small-text"> ' . esc_html__( 'valid for', 'olivia-studio' ) . ' <input type="number" name="days" min="1" value="90" class="small-text"> ' . esc_html__( 'days', 'olivia-studio' )
			. ' <input name="name" placeholder="' . esc_attr__( 'Label, e.g. Paid in cash', 'olivia-studio' ) . '"> <button class="button button-primary">' . esc_html__( 'Add', 'olivia-studio' ) . '</button></form>';
		// Olivia, 16 November: the default pass extension (Settings), and her own number for a private client.
		$own = (string) get_user_meta( $user_id, 'oys_cancel_extend_days', true );
		echo '<h3 id="extend">' . esc_html__( 'If you cancel their private session', 'olivia-studio' ) . '</h3>' . self::form( 'customer_extend', 'class="oys-inline-form"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '">' // phpcs:ignore
			. sprintf( esc_html__( 'their private passes are valid longer by %s days', 'olivia-studio' ), '<input type="number" name="days" min="0" class="small-text" value="' . esc_attr( $own ) . '" placeholder="' . (int) OYS_Settings::get( 'cancel_extend_days' ) . '">' )
			. ' <button class="button">' . esc_html__( 'Save', 'olivia-studio' ) . '</button>'
			. '<p class="description">' . sprintf( esc_html__( 'Empty = the default (%d days, in Settings). 0 = no extension.', 'olivia-studio' ), (int) OYS_Settings::get( 'cancel_extend_days' ) ) . '</p></form></div></div>';

		$ms = OYS_Memberships::for_user( $user_id );
		if ( $ms ) {
			echo '<h2>' . esc_html__( 'Memberships', 'olivia-studio' ) . '</h2>';
			self::members_table( $ms, false );
		}
		$st = OYS_Bookings::statuses();
		echo '<h2>' . esc_html__( 'Bookings', 'olivia-studio' ) . '</h2><table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Date', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Session', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Paid with', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( OYS_Bookings::for_user( $user_id, 'all' ) as $b ) {
			if ( 'expired' === $b->status ) {
				continue;
			}
			echo '<tr><td>' . esc_html( oys_date( $b->starts_at, 'M j, Y g:i a' ) ) . '</td><td><a href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&session=' . $b->session_id ) ) . '">' . esc_html( $b->title ?: oys_class_title( $b->class_slug ) ) . '</a></td><td>' . esc_html( $b->paid_with ) . '</td><td>' . esc_html( $st[ $b->status ] ?? $b->status ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Payments', 'olivia-studio' ) . '</h2>';
		self::orders_table( OYS_Orders::for_user( $user_id ) );
		echo '</div>';
	}

	/** Customer page: missed pay-at-the-studio bookings, and the button to allow it again. */
	private static function pay_later_status( $user_id ) {
		$missed = OYS_Bookings::pay_later_no_shows( $user_id );
		$max    = (int) OYS_Settings::get( 'pay_later_max_no_shows' );
		$out    = '<p><b>' . esc_html__( 'Pay at the studio', 'olivia-studio' ) . ':</b> ';
		if ( $max && $missed >= $max ) {
			return $out . sprintf( esc_html__( 'paused: %d missed classes that weren\'t paid', 'olivia-studio' ), $missed ) . '</p>'
				. self::form( 'pay_later_reset', 'class="oys-inline"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '"><button class="button button-small">' . esc_html__( 'Allow paying at the studio again', 'olivia-studio' ) . '</button></form>';
		}
		return $out . ( $missed ? sprintf( esc_html__( 'allowed (%d missed so far)', 'olivia-studio' ), $missed ) : esc_html__( 'allowed', 'olivia-studio' ) ) . '</p>';
	}

	private static function do_import_customers() {
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::back( 'oys-customers', array(), __( 'Choose the file to import.', 'olivia-studio' ), 'error' );
		}
		$rows = OYS_Import::read_file( $f['tmp_name'], sanitize_file_name( $f['name'] ) );
		if ( is_wp_error( $rows ) ) {
			self::back( 'oys-customers', array(), $rows->get_error_message(), 'error' );
		}
		$dry = ! empty( $_POST['dry_run'] );
		$until = sanitize_text_field( wp_unslash( $_POST['online_until'] ?? '' ) );
		$res   = OYS_Import::import_any( $rows, 'motibro', $dry, '', $until );
		if ( 'visits' === $res['kind'] ) {
			$msg = sprintf( $dry ? __( 'Check only – nothing changed. Class visits: %1$d classes would be added (%2$d already here), %3$d visits (%4$d already here), %5$d new customers, %6$d skipped.', 'olivia-studio' ) : __( 'Class visits imported: %1$d classes added (%2$d already here), %3$d visits (%4$d already here), %5$d new customers, %6$d skipped.', 'olivia-studio' ), $res['classes_created'], $res['classes_found'], $res['visits_added'], $res['visits_there'], $res['customers_created'], $res['skipped'] );
			self::back( 'oys-customers', array(), $msg );
		}
		if ( 'passes' === $res['kind'] ) {
			$msg = sprintf( $dry ? __( 'Check only – nothing changed. Passes: %1$d would be added, %2$d updated, %3$d unchanged, %4$d skipped; %5$d sessions left on them.', 'olivia-studio' ) : __( 'Passes imported: %1$d added, %2$d updated, %3$d unchanged, %4$d skipped; %5$d sessions left on them.', 'olivia-studio' ), $res['created'], $res['updated'], $res['unchanged'], $res['skipped'], $res['credits'] )
				. ' ' . ( 'online' === $res['mode'] ? sprintf( __( 'Bookable online until %s.', 'olivia-studio' ), $res['online_until'] ) : __( 'Not bookable online: collected under „Passes from Motibro”.', 'olivia-studio' ) );
			self::back( 'oys-customers', array(), $msg );
		}
		$msg = sprintf( $dry ? __( 'Check only – nothing changed. Would be created: %1$d, updated: %2$d, unchanged: %3$d, skipped: %4$d.', 'olivia-studio' ) : __( 'Imported. Created: %1$d, updated: %2$d, unchanged: %3$d, skipped: %4$d.', 'olivia-studio' ), $res['created'], $res['updated'], $res['unchanged'], $res['skipped'] );
		self::back( 'oys-customers', array(), $msg );
	}

	private static function do_grant_pass() {
		$user_id = (int) $_POST['user'];
		$kind    = 'private' === ( $_POST['kind'] ?? '' ) ? 'private' : 'class';
		OYS_Passes::grant( $user_id, array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ?: ( 'private' === $kind ? __( 'Private sessions', 'olivia-studio' ) : __( 'Class credits', 'olivia-studio' ) ),
			'kind'          => $kind,
			'credits'       => max( 1, (int) $_POST['credits'] ),
			'validity_days' => max( 1, (int) $_POST['days'] ),
			'source'        => 'admin',
		) );
		self::back( 'oys-customers', array( 'user' => $user_id ), __( 'Classes added.', 'olivia-studio' ) );
	}

	private static function do_adjust_pass() {
		$exp = sanitize_text_field( wp_unslash( $_POST['expires'] ?? '' ) );
		OYS_Passes::adjust( (int) $_POST['pass'], (int) $_POST['credits_left'], $exp ? oys_local_to_utc( $exp . ' 23:59:59' ) : '' );
		self::back( 'oys-customers', array( 'user' => (int) $_POST['user'] ), __( 'Pass updated.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Memberships
	   ====================================================================== */

	public static function page_members() {
		self::header( __( 'Memberships', 'olivia-studio' ) );
		$all    = OYS_Memberships::query();
		$active = array_filter( $all, fn( $m ) => in_array( $m->status, OYS_Memberships::BOOKABLE, true ) );
		echo '<div class="oys-kpis"><div class="oys-kpi"><span>' . esc_html__( 'Active members', 'olivia-studio' ) . '</span><b>' . count( $active ) . '</b></div>'
			. '<div class="oys-kpi"><span>' . esc_html__( 'Monthly recurring revenue', 'olivia-studio' ) . '</span><b>' . esc_html( oys_money( OYS_Memberships::mrr() ) ) . '</b></div>'
			. '<div class="oys-kpi"><span>' . esc_html__( 'Payment problems', 'olivia-studio' ) . '</span><b>' . count( array_filter( $all, fn( $m ) => 'past_due' === $m->status ) ) . '</b></div>'
			. '<div class="oys-kpi"><span>' . esc_html__( 'Ending (cancel scheduled)', 'olivia-studio' ) . '</span><b>' . count( array_filter( $active, fn( $m ) => $m->cancel_at_period_end ) ) . '</b></div></div>';
		echo '<p class="description">' . esc_html__( 'Plans and prices: Studio → Prices & passes. Billing details, invoices and disputes are in your Stripe dashboard; changes made there appear here automatically.', 'olivia-studio' ) . '</p>';
		self::members_table( $all, true );
		echo '</div>';
	}

	private static function members_table( $rows, $with_user ) {
		$st = OYS_Memberships::statuses();
		echo '<table class="widefat striped oys-table"><thead><tr>' . ( $with_user ? '<th>' . esc_html__( 'Member', 'olivia-studio' ) . '</th>' : '' ) . '<th>' . esc_html__( 'Plan', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Used this period', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Period', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Since', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $m ) {
			$bookable = in_array( $m->status, OYS_Memberships::BOOKABLE, true );
			$status   = ( $st[ $m->status ] ?? $m->status ) . ( $bookable && $m->cancel_at_period_end ? ' · ' . __( 'ends at period end', 'olivia-studio' ) : '' );
			echo '<tr>' . ( $with_user ? '<td>' . self::user_label( $m->user_id ) . '</td>' : '' ) . '<td>' . esc_html( $m->name ) . '</td><td><span class="oys-status oys-status--' . esc_attr( $m->status ) . '">' . esc_html( $status ) . '</span></td>' // phpcs:ignore
				. '<td>' . (int) OYS_Memberships::used_in_period( $m ) . ( (int) $m->classes_per_period ? ' / ' . (int) $m->classes_per_period : '' ) . '</td>'
				. '<td>' . esc_html( $m->current_period_start ? oys_date( $m->current_period_start, 'M j' ) . ' – ' . oys_date( $m->current_period_end, 'M j, Y' ) : '—' ) . '</td><td>' . esc_html( oys_date( $m->created_at, 'M j, Y' ) ) . '</td><td>';
			if ( $bookable ) {
				$f = fn( $do, $label, $cls = '', $confirm = '' ) => self::form( 'membership', 'class="oys-inline"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) ) . '<input type="hidden" name="id" value="' . (int) $m->id . '"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="back" value="' . ( $with_user ? 'list' : (int) $m->user_id ) . '"><button class="button button-small ' . $cls . '">' . esc_html( $label ) . '</button></form> ';
				echo $m->cancel_at_period_end ? $f( 'resume', __( 'Resume', 'olivia-studio' ) ) : $f( 'cancel', __( 'Cancel at period end', 'olivia-studio' ), '', __( 'The member keeps access until the end of the paid period and is emailed. Continue?', 'olivia-studio' ) ); // phpcs:ignore
				echo $f( 'end', __( 'End now', 'olivia-studio' ), 'button-link-delete', __( 'End the membership immediately? No refund is made automatically; future classes booked with it are cancelled.', 'olivia-studio' ) ); // phpcs:ignore
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No memberships yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function do_membership() {
		$m    = OYS_Memberships::get( (int) $_POST['id'] );
		$back = sanitize_key( $_POST['back'] ?? 'list' );
		$res  = null;
		if ( $m ) {
			$do  = sanitize_key( $_POST['do'] ?? '' );
			$res = 'end' === $do ? OYS_Memberships::cancel_now( $m ) : OYS_Memberships::set_cancel_at_period_end( $m, 'cancel' === $do );
		}
		$msg = is_wp_error( $res ) ? $res->get_error_message() . ' ' . ( $res->get_error_data()['stripe'] ?? '' ) : __( 'Membership updated.', 'olivia-studio' );
		'list' === $back ? self::back( 'oys-members', array(), $msg ) : self::back( 'oys-customers', array( 'user' => (int) $back ), $msg );
	}

	/* ======================================================================
	   Payments
	   ====================================================================== */

	public static function page_orders() {
		self::header( __( 'Payments', 'olivia-studio' ) );
		$status = sanitize_key( $_GET['status'] ?? '' );
		echo '<ul class="subsubsub">';
		$links = array( '' => __( 'All', 'olivia-studio' ) ) + OYS_Orders::statuses();
		$i     = 0;
		foreach ( $links as $k => $l ) {
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( admin_url( 'admin.php?page=oys-orders' . ( $k ? '&status=' . $k : '' ) ) ) . '"' . ( $k === $status ? ' class="current"' : '' ) . '>' . esc_html( $l ) . '</a></li>';
		}
		echo '</ul><br class="clear">';
		self::orders_table( OYS_Orders::query( $status ), true );
		echo '<p class="description">' . esc_html__( 'Card payments, payouts and disputes are also in your Stripe dashboard. Refunds made in Stripe are picked up here automatically.', 'olivia-studio' ) . '</p></div>';
	}

	private static function orders_table( $orders, $with_user = false ) {
		$st    = OYS_Orders::statuses();
		$types = OYS_Orders::types();
		echo '<table class="widefat striped oys-table"><thead><tr><th>#</th><th>' . esc_html__( 'Date', 'olivia-studio' ) . '</th>' . ( $with_user ? '<th>' . esc_html__( 'Customer', 'olivia-studio' ) . '</th>' : '' ) . '<th>' . esc_html__( 'What', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Amount', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $orders as $o ) {
			echo '<tr><td>' . (int) $o->id . '</td><td>' . esc_html( oys_date( $o->paid_at ?: $o->created_at, 'M j, Y g:i a' ) ) . '</td>' . ( $with_user ? '<td>' . self::user_label( $o->user_id ) . '</td>' : '' ) // phpcs:ignore
				. '<td>' . esc_html( ( $types[ $o->type ] ?? $o->type ) . ': ' . $o->description ) . '</td><td>' . esc_html( oys_money( $o->amount_cents, $o->currency ) )
				. ( ! empty( $o->meta['stripe_account'] ) ? '<br><small class="oys-direct">' . esc_html( sprintf( __( 'to %1$s\'s Stripe · your fee %2$s', 'olivia-studio' ), ( OYS_Teachers::get( $o->meta['teacher_id'] ?? 0 )->name ?? __( 'the teacher', 'olivia-studio' ) ), oys_money( (int) ( $o->meta['app_fee_cents'] ?? 0 ), $o->currency ) ) ) . '</small>' : '' )
				. ( ! empty( $o->meta['refunded_cents'] ) ? '<br><small>' . esc_html( sprintf( __( 'refunded %s', 'olivia-studio' ), oys_money( $o->meta['refunded_cents'], $o->currency ) ) ) . '</small>' : '' ) . '</td>'
				. '<td><span class="oys-status oys-status--' . esc_attr( $o->status ) . '">' . esc_html( $st[ $o->status ] ?? $o->status ) . '</span></td><td>';
			if ( $o->receipt_url ) {
				echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $o->receipt_url ) . '">' . esc_html__( 'Receipt', 'olivia-studio' ) . '</a> ';
			}
			if ( in_array( $o->status, array( 'paid', 'partially_refunded' ), true ) && $o->stripe_payment_intent ) {
				$left = (int) $o->amount_cents - (int) ( $o->meta['refunded_cents'] ?? 0 );
				echo self::form( 'refund', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Refund this payment to the customer\'s card?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="order" value="' . (int) $o->id . '"><input name="amount" class="small-text" value="' . esc_attr( $left / 100 ) . '"> <button class="button button-small">' . esc_html__( 'Refund', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
			}
			echo '</td></tr>';
		}
		if ( ! $orders ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No payments yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function do_refund() {
		$order  = OYS_Orders::get( (int) $_POST['order'] );
		$amount = oys_cents_from_input( wp_unslash( $_POST['amount'] ?? '' ) );
		$left   = $order ? (int) $order->amount_cents - (int) ( $order->meta['refunded_cents'] ?? 0 ) : 0;
		$full   = $amount >= $left;
		$res    = OYS_Stripe::refund( (int) $_POST['order'], $full && ! ( $order->meta['refunded_cents'] ?? 0 ) ? 0 : min( $amount, $left ) );
		self::back( 'oys-orders', array(), is_wp_error( $res ) ? $res->get_error_message() . ' ' . ( $res->get_error_data()['stripe'] ?? '' ) : __( 'Refund issued.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Gift cards
	   ====================================================================== */

	public static function page_gifts() {
		self::header( __( 'Gift cards', 'olivia-studio' ) );
		echo '<p>' . sprintf( wp_kses_post( __( 'Customers buy gift cards on the <a href="%s">Gift cards page</a>.', 'olivia-studio' ) ), esc_url( oys_page_url( 'gifts' ) ) ) . '</p>';
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Code', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Gift', 'olivia-studio' ) . '</th><th>' . esc_html__( 'From', 'olivia-studio' ) . '</th><th>' . esc_html__( 'To', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Redeemed by', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( OYS_Gifts::query() as $g ) {
			$p = OYS_Products::get( $g->product_id );
			echo '<tr><td><code>' . esc_html( $g->code ) . '</code></td><td>' . esc_html( $p ? $p['name'] : $g->product_id ) . '</td><td>' . self::user_label( $g->purchaser_id ) . '</td><td>' . esc_html( $g->recipient_name . ' <' . $g->recipient_email . '>' ) . '</td><td>' . esc_html( $g->status ) . '</td><td>' . ( $g->redeemed_by ? self::user_label( $g->redeemed_by ) . ' · ' . esc_html( oys_date( $g->redeemed_at, 'M j' ) ) : '—' ) . '</td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div>';
	}

	/* ======================================================================
	   Prices & passes
	   ====================================================================== */

	public static function page_products() {
		self::header( __( 'Prices & passes', 'olivia-studio' ) );
		echo '<p>' . esc_html__( 'Drop-in prices are set per class in the weekly timetable (and per event). Here you set passes, private-session prices and the intro offer.', 'olivia-studio' ) . '</p>';
		$kinds = OYS_Products::kinds();
		$all   = OYS_Products::all();
		$all['__new'] = array( 'id' => '', 'name' => '', 'kind' => 'pack', 'credits' => 10, 'validity_days' => 180, 'price_cents' => 0, 'description' => '', 'features' => '', 'featured' => 0, 'giftable' => 1, 'active' => 1, 'duration_min' => 0, 'sort' => 50 );
		foreach ( $all as $key => $p ) {
			$new = '__new' === $key;
			echo '<div class="oys-box oys-product' . ( empty( $p['active'] ) ? ' is-inactive' : '' ) . '"><h2>' . ( $new ? esc_html__( 'Add a pass', 'olivia-studio' ) : esc_html( $p['name'] ) . ' <code>' . esc_html( $p['id'] ) . '</code>' ) . '</h2>' . self::form( 'save_product' ) . '<table class="form-table">'; // phpcs:ignore
			echo '<tr><th>' . esc_html__( 'ID', 'olivia-studio' ) . '</th><td><input name="id" value="' . esc_attr( $p['id'] ) . '"' . ( $new ? ' required pattern="[a-z0-9\-]+" placeholder="pack-10"' : ' readonly' ) . '></td></tr>';
			echo '<tr><th>' . esc_html__( 'Name', 'olivia-studio' ) . '</th><td><input class="regular-text" name="name" required value="' . esc_attr( $p['name'] ) . '"></td></tr>';
			echo '<tr><th>' . esc_html__( 'Type', 'olivia-studio' ) . '</th><td><select name="kind">';
			foreach ( $kinds as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $p['kind'], $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><th>' . esc_html__( 'Price', 'olivia-studio' ) . '</th><td><input name="price" class="small-text" value="' . esc_attr( $p['price_cents'] / 100 ) . '"> ' . esc_html( strtoupper( OYS_Settings::get( 'currency' ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Classes / sessions', 'olivia-studio' ) . '</th><td><input type="number" name="credits" min="1" class="small-text" value="' . (int) $p['credits'] . '"> ' . esc_html__( 'valid for', 'olivia-studio' ) . ' <input type="number" name="validity_days" min="0" class="small-text" value="' . (int) $p['validity_days'] . '"> ' . esc_html__( 'days (0 = no expiry)', 'olivia-studio' ) . '</td></tr>';
			$svcs = '<option value="">' . esc_html__( '(none: private yoga)', 'olivia-studio' ) . '</option>';
			foreach ( OYS_Services::all() as $sk => $sv ) {
				$svcs .= '<option value="' . esc_attr( $sk ) . '"' . selected( $p['service'] ?? '', $sk, false ) . '>' . esc_html( OYS_Services::label( $sk ) ) . '</option>';
			}
			echo '<tr><th>' . esc_html__( 'One-to-one service', 'olivia-studio' ) . '</th><td><select name="service">' . $svcs . '</select> <select name="people"><option value="1">' . esc_html__( 'one person', 'olivia-studio' ) . '</option><option value="2"' . selected( (int) ( $p['people'] ?? 1 ), 2, false ) . '>' . esc_html__( 'two people (with a friend)', 'olivia-studio' ) . '</option></select> <span class="description">' . esc_html__( 'Private session products only: their credits pay only for this service.', 'olivia-studio' ) . '</span></td></tr>'; // phpcs:ignore
			echo '<tr><th>' . esc_html__( 'Session length (private)', 'olivia-studio' ) . '</th><td><input type="number" name="duration_min" min="0" class="small-text" value="' . (int) $p['duration_min'] . '"> min · ' . esc_html__( 'online price', 'olivia-studio' ) . ' <input name="online_price" class="small-text" value="' . esc_attr( (int) $p['online_price_cents'] ? $p['online_price_cents'] / 100 : '' ) . '"> <span class="description">' . esc_html__( 'Single private sessions only: price when the session is online (empty = same as in person).', 'olivia-studio' ) . '</span></td></tr>';
			echo '<tr><th>' . esc_html__( 'Membership billing', 'olivia-studio' ) . '</th><td>' . esc_html__( 'every', 'olivia-studio' ) . ' <input type="number" name="interval_count" min="1" class="small-text" value="' . (int) $p['interval_count'] . '"> <select name="interval"><option value="month"' . selected( $p['interval'], 'month', false ) . '>' . esc_html__( 'month(s)', 'olivia-studio' ) . '</option><option value="year"' . selected( $p['interval'], 'year', false ) . '>' . esc_html__( 'year(s)', 'olivia-studio' ) . '</option></select> · ' . esc_html__( 'classes per period', 'olivia-studio' ) . ' <input type="number" name="classes_per_period" min="0" class="small-text" value="' . (int) $p['classes_per_period'] . '"> <span class="description">' . esc_html__( '0 = unlimited. Only used for memberships; a price change applies to new members (existing members keep their price in Stripe).', 'olivia-studio' ) . '</span></td></tr>';
			echo '<tr><th>' . esc_html__( 'Short description', 'olivia-studio' ) . '</th><td><input class="large-text" name="description" value="' . esc_attr( $p['description'] ) . '"></td></tr>';
			echo '<tr><th>' . esc_html__( 'Bullet points (one per line)', 'olivia-studio' ) . '</th><td><textarea name="features" rows="3" class="large-text">' . esc_textarea( $p['features'] ) . '</textarea></td></tr>';
			echo '<tr><th>' . esc_html__( 'Options', 'olivia-studio' ) . '</th><td><label><input type="checkbox" name="active" value="1"' . checked( 1, (int) $p['active'], false ) . '> ' . esc_html__( 'On sale', 'olivia-studio' ) . '</label> &nbsp; <label><input type="checkbox" name="giftable" value="1"' . checked( 1, (int) $p['giftable'], false ) . '> ' . esc_html__( 'Can be bought as a gift', 'olivia-studio' ) . '</label> &nbsp; <label><input type="checkbox" name="featured" value="1"' . checked( 1, (int) $p['featured'], false ) . '> ' . esc_html__( 'Highlight as most popular', 'olivia-studio' ) . '</label> &nbsp; ' . esc_html__( 'Order', 'olivia-studio' ) . ' <input type="number" name="sort" class="small-text" value="' . (int) $p['sort'] . '"></td></tr>';
			echo '</table>';
			submit_button( $new ? __( 'Add pass', 'olivia-studio' ) : __( 'Save', 'olivia-studio' ), 'primary', 'submit', false );
			echo '</form>';
			if ( ! $new ) {
				echo ' ' . self::form( 'delete_product', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this pass? Passes customers already bought are kept.', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . esc_attr( $p['id'] ) . '"><button class="button button-link-delete">' . esc_html__( 'Delete', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function do_save_product() {
		$id    = sanitize_title( wp_unslash( $_POST['id'] ?? '' ) );
		$kinds = OYS_Products::kinds();
		$kind  = sanitize_key( $_POST['kind'] ?? 'pack' );
		if ( ! $id ) {
			self::back( 'oys-products', array(), __( 'Give the pass an ID.', 'olivia-studio' ) );
		}
		OYS_Products::save( $id, array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'kind'          => isset( $kinds[ $kind ] ) ? $kind : 'pack',
			'credits'       => max( 1, (int) ( $_POST['credits'] ?? 1 ) ),
			'validity_days' => max( 0, (int) ( $_POST['validity_days'] ?? 0 ) ),
			'price_cents'   => oys_cents_from_input( wp_unslash( $_POST['price'] ?? '0' ) ),
			'description'   => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'features'      => sanitize_textarea_field( wp_unslash( $_POST['features'] ?? '' ) ),
			'featured'      => empty( $_POST['featured'] ) ? 0 : 1,
			'giftable'      => empty( $_POST['giftable'] ) ? 0 : 1,
			'active'        => empty( $_POST['active'] ) ? 0 : 1,
			'duration_min'  => max( 0, (int) ( $_POST['duration_min'] ?? 0 ) ),
			'service'       => isset( OYS_Services::all()[ sanitize_key( $_POST['service'] ?? '' ) ] ) ? sanitize_key( $_POST['service'] ) : '',
			'people'        => 2 === (int) ( $_POST['people'] ?? 1 ) ? 2 : 1,
			'sort'          => (int) ( $_POST['sort'] ?? 50 ),
			'interval'           => 'year' === ( $_POST['interval'] ?? '' ) ? 'year' : 'month',
			'interval_count'     => max( 1, (int) ( $_POST['interval_count'] ?? 1 ) ),
			'classes_per_period' => max( 0, (int) ( $_POST['classes_per_period'] ?? 0 ) ),
			'online_price_cents' => '' === trim( (string) ( $_POST['online_price'] ?? '' ) ) ? 0 : oys_cents_from_input( wp_unslash( $_POST['online_price'] ) ),
		) );
		self::back( 'oys-products', array(), __( 'Saved.', 'olivia-studio' ) );
	}

	private static function do_delete_product() {
		OYS_Products::delete( sanitize_title( wp_unslash( $_POST['id'] ?? '' ) ) );
		self::back( 'oys-products', array(), __( 'Deleted.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Settings
	   ====================================================================== */

	public static function page_settings() {
		self::header( __( 'Studio settings', 'olivia-studio' ) );
		$s   = OYS_Settings::all();
		// A secret is never printed back into the page: the field stays empty, and empty keeps the saved one.
		$f   = fn( $k, $type = 'text', $cls = 'regular-text', $extra = '' ) => 'password' === $type
			? '<input type="password" name="s[' . $k . ']" value="" class="' . $cls . '" ' . ( '' !== (string) $s[ $k ] ? 'autocomplete="new-password" placeholder="••••••••••"' : $extra ) . '>' . ( '' !== (string) $s[ $k ] ? '<p class="description">' . esc_html__( 'A key is saved. Leave empty to keep it.', 'olivia-studio' ) . '</p>' : '' )
			: '<input type="' . $type . '" name="s[' . $k . ']" value="' . esc_attr( $s[ $k ] ) . '" class="' . $cls . '" ' . $extra . '>';
		$hook = rest_url( 'oys/v1/stripe-webhook' );
		echo self::form( 'save_settings' ) . '<h2>' . esc_html__( 'Payments (Stripe)', 'olivia-studio' ) . '</h2><table class="form-table">'; // phpcs:ignore
		// Who takes the card payments: the studio's own Stripe, or Arovia's through the HelloProVision CRM (class-hpv-pay.php)
		$hpv = OYS_HPV_Pay::state();
		echo '<tr><th>' . esc_html__( 'Payments go to', 'olivia-studio' ) . '</th><td><select name="s[pay_provider]"' . ( defined( 'OYS_PAY_PROVIDER' ) ? ' disabled' : '' ) . '><option value="stripe"' . selected( OYS_Settings::pay_provider(), 'stripe', false ) . '>' . esc_html__( 'The studio\'s own Stripe account (the keys below)', 'olivia-studio' ) . '</option><option value="hpv"' . selected( OYS_Settings::pay_provider(), 'hpv', false ) . '>' . esc_html__( 'Arovia Group LLC, through the HelloProVision CRM (Stripe + QuickBooks)', 'olivia-studio' ) . '</option></select>';
		if ( OYS_HPV_Pay::active() ) {
			echo '<p class="description">' . ( OYS_HPV_Pay::ready()
				? '✅ ' . esc_html( sprintf( __( 'Connected to the CRM since %1$s (%2$s). No keys are needed here.', 'olivia-studio' ), wp_date( 'Y-m-d H:i', (int) $hpv['registered'] ), 'live' === $hpv['mode'] ? __( 'live payments', 'olivia-studio' ) : __( 'test payments', 'olivia-studio' ) ) )
				: '⏳ ' . esc_html__( 'Connecting to the CRM…', 'olivia-studio' ) . ( '' !== $hpv['error'] ? ' ' . esc_html( sprintf( __( 'Last answer: %s', 'olivia-studio' ), $hpv['error'] ) ) : '' ) ) . ' <button class="button" form="oys-hpv-register">' . esc_html__( 'Connect now', 'olivia-studio' ) . '</button></p>';
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Mode', 'olivia-studio' ) . '</th><td><select name="s[stripe_mode]"><option value="test"' . selected( $s['stripe_mode'], 'test', false ) . '>' . esc_html__( 'Test (no real charges)', 'olivia-studio' ) . '</option><option value="live"' . selected( $s['stripe_mode'], 'live', false ) . '>' . esc_html__( 'Live', 'olivia-studio' ) . '</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Test secret key', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_test_secret', 'password', 'regular-text', 'autocomplete="off" placeholder="sk_test_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Test webhook signing secret', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_test_webhook', 'password', 'regular-text', 'autocomplete="off" placeholder="whsec_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Live secret key', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_live_secret', 'password', 'regular-text', 'autocomplete="off" placeholder="sk_live_… or rk_live_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Live webhook signing secret', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_live_webhook', 'password', 'regular-text', 'autocomplete="off" placeholder="whsec_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Webhook URL', 'olivia-studio' ) . '</th><td><code>' . esc_html( $hook ) . '</code><p class="description">' . esc_html__( 'In Stripe → Developers → Webhooks, add this URL with the events: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, charge.refunded, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed. For memberships also turn on the Customer portal (Settings → Billing → Customer portal).', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Currency', 'olivia-studio' ) . '</th><td>' . $f( 'currency', 'text', 'small-text', 'maxlength="3"' ) . '</td></tr>'; // phpcs:ignore
		echo '</table><h2>' . esc_html__( 'Languages', 'olivia-studio' ) . '</h2><table class="form-table">';
		$site_langs = OYS_I18n::languages();
		echo '<tr><th>' . esc_html__( 'The site is offered in', 'olivia-studio' ) . '</th><td>';
		foreach ( OYS_I18n::LANGS as $code => $l ) {
			echo '<label style="margin-right:1em"><input type="checkbox" name="oys_langs[]" value="' . esc_attr( $code ) . '"' . checked( isset( $site_langs[ $code ] ), true, false ) . '> ' . esc_html( $l['native'] ) . '</label>';
		}
		echo '</td></tr><tr><th>' . esc_html__( 'Main language', 'olivia-studio' ) . '</th><td><select name="s[default_lang]">';
		foreach ( OYS_I18n::LANGS as $code => $l ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( OYS_I18n::default_lang(), $code, false ) . '>' . esc_html( $l['native'] ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Its addresses have no language prefix, and the site\'s own pages and posts are written in it. The other languages get /hu/ or /es/ addresses; with a single language there is no language switcher.', 'olivia-studio' ) . '</p></td></tr>';
		echo '</table><h2>' . esc_html__( 'Booking rules', 'olivia-studio' ) . '</h2><table class="form-table">';
		$nums = array(
			'cancel_hours'          => __( 'Free cancellation until (hours before a group class)', 'olivia-studio' ),
			'private_cancel_hours'  => __( 'Free cancellation until (hours before a private session)', 'olivia-studio' ),
			'dropin_credit_days'    => __( 'Credit for a cancelled drop-in is valid for (days)', 'olivia-studio' ),
			'cancel_extend_days'    => __( 'When the studio cancels a class, passes are valid longer by (days, 0 = off)', 'olivia-studio' ),
			'booking_window_days'   => __( 'Show classes this many days ahead', 'olivia-studio' ),
			'booking_close_minutes' => __( 'Online booking closes (minutes before start)', 'olivia-studio' ),
			'weeks_ahead'           => __( 'Create weekly classes this many weeks ahead', 'olivia-studio' ),
			'hold_minutes'          => __( 'Hold a spot during card payment (minutes, 30 or more)', 'olivia-studio' ),
			'max_guests'            => __( 'Guests a customer can bring per booking (0 = off)', 'olivia-studio' ),
			'auto_attendance_hours' => __( 'The attendance sheet fills itself in this many hours after a class (0 = off)', 'olivia-studio' ),
		);
		foreach ( $nums as $k => $l ) {
			echo '<tr><th>' . esc_html( $l ) . '</th><td>' . $f( $k, 'number', 'small-text', 'min="0"' ) . ( 'cancel_extend_days' === $k ? '<p class="description">' . esc_html__( 'The default. For a private client you can set another number on their customer page.', 'olivia-studio' ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore
		}
		// Olivia, 16 November: no automatic move-in from the waitlist, an email to everyone, first to book gets the spot.
		echo '<tr><th>' . esc_html__( 'Waitlist', 'olivia-studio' ) . '</th><td><label><input type="hidden" name="s[waitlist_auto]" value="0"><input type="checkbox" name="s[waitlist_auto]" value="1"' . checked( 1, (int) $s['waitlist_auto'], false ) . '> ' . esc_html__( 'Book people with a pass or membership in by themselves when a spot opens', 'olivia-studio' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Off: everyone on the waitlist gets an email with a button to book, and whoever books first gets the spot.', 'olivia-studio' ) . '</p>'
			. '<p>' . sprintf( esc_html__( 'When it is on, this stops %s hours before the start.', 'olivia-studio' ), $f( 'waitlist_cutoff_hours', 'number', 'small-text', 'min="0"' ) ) . '</p></td></tr>'; // phpcs:ignore
		echo '</table><h2 id="pay-later">' . esc_html__( 'Paying at the studio and donations', 'olivia-studio' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Who can book now and pay at the studio', 'olivia-studio' ) . '</th><td><select name="s[pay_later]">'
			. '<option value="all"' . selected( $s['pay_later'], 'all', false ) . '>' . esc_html__( 'Everyone', 'olivia-studio' ) . '</option>'
			. '<option value="first"' . selected( $s['pay_later'], 'first', false ) . '>' . esc_html__( 'Only for their first class', 'olivia-studio' ) . '</option>'
			. '<option value="off"' . selected( $s['pay_later'], 'off', false ) . '>' . esc_html__( 'Nobody (pay in advance)', 'olivia-studio' ) . '</option></select>'
			. '<p class="description">' . esc_html__( 'For in-person group classes and events. Each class can switch it off in the calendar (e.g. workshops). Mark the payment on the roster with "Paid: cash" or "Paid: other".', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Pay in advance after this many missed classes', 'olivia-studio' ) . '</th><td>' . $f( 'pay_later_max_no_shows', 'number', 'small-text', 'min="0"' ) . '<p class="description">' . esc_html__( 'Classes they booked to pay at the studio and didn\'t come to (marked "No-show" on the roster). 0 = never. You can allow it again on the customer\'s page.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'How to pay at the studio (shown when booking and in emails)', 'olivia-studio' ) . '</th><td>' . $f( 'pay_later_note', 'text', 'large-text' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Donation classes: minimum per person', 'olivia-studio' ) . '</th><td><input name="donation_min" class="small-text" value="' . esc_attr( $s['donation_min_cents'] / 100 ) . '"> ' . esc_html( strtoupper( $s['currency'] ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Suggested donation amounts', 'olivia-studio' ) . '</th><td>' . $f( 'donation_suggestions', 'text', 'regular-text', 'placeholder="5,10,15,20"' ) . '<p class="description">' . esc_html__( 'Separated by commas. Make a class "by donation" in the calendar; people choose one of these or type their own amount, and pay by card or at the studio.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '</table><h2>' . esc_html__( 'Private sessions', 'olivia-studio' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Extra time for a first session (minutes)', 'olivia-studio' ) . '</th><td>' . $f( 'private_first_extra_min', 'number', 'small-text', 'min="0" step="5"' ) . '<p class="description">' . esc_html__( 'Added to the time you block when you send an offer to someone new, to talk through their goals. The price stays the same.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Note shown with private sessions', 'olivia-studio' ) . '</th><td><textarea name="s[private_note]" rows="3" class="large-text">' . esc_textarea( $s['private_note'] ) . '</textarea><p class="description">' . esc_html__( 'Shown on the private session request form, prices and booking page, and in the offer email.', 'olivia-studio' ) . '</p></td></tr>';
		echo '</table><h2>' . esc_html__( 'Community', 'olivia-studio' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Facebook group link', 'olivia-studio' ) . '</th><td>' . $f( 'fb_group_url', 'url', 'large-text' ) . '<p class="description">' . esc_html__( 'Shown next to the newsletter sign-up, after booking, in the account, the app and at the bottom of every email. Leave empty to hide it.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '</table><h2>' . esc_html__( 'Online classes', 'olivia-studio' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Online only', 'olivia-studio' ) . '</th><td><label><input type="hidden" name="s[online_only]" value="0"><input type="checkbox" name="s[online_only]" value="1"' . checked( 1, (int) $s['online_only'], false ) . '> ' . esc_html__( 'Only live online classes (no studio): the price list shows the online drop-in and online passes', 'olivia-studio' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Drop-in price for an online class', 'olivia-studio' ) . '</th><td><input name="online_price" class="small-text" value="' . esc_attr( $s['online_price_cents'] / 100 ) . '"> ' . esc_html( strtoupper( $s['currency'] ) ) . '<p class="description">' . esc_html__( 'Used for new online classes in the calendar; each class can still have its own price.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'One class on a studio pass covers', 'olivia-studio' ) . '</th><td>' . $f( 'online_per_credit', 'number', 'small-text', 'min="1"' ) . ' ' . esc_html__( 'online classes', 'olivia-studio' ) . '<p class="description">' . esc_html__( 'When someone books an online class with a studio pass, one class from the pass turns into this many online classes (same expiry date); one is used and the rest stay in their account. Online passes are used first. Memberships include online classes, and they don\'t count towards a monthly class limit.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		$check = fn( $k, $label ) => '<label><input type="hidden" name="s[' . $k . ']" value="0"><input type="checkbox" name="s[' . $k . ']" value="1"' . checked( 1, (int) $s[ $k ], false ) . '> ' . esc_html( $label ) . '</label>';
		echo '</table><h2 id="live">' . esc_html__( 'Live online classes (HathaFlow Live)', 'olivia-studio' ) . '</h2>';
		echo '<p class="description" style="max-width:760px">' . esc_html__( 'Online classes run on the site itself: only the people who booked get in, from their account or the link in their emails, in the browser, nothing to download, no Zoom. The class chat stays after the class. The video goes through LiveKit (livekit.io, LiveKit Cloud): create a project there and copy its URL (wss://…), API key and secret here, or put them in wp-config.php as OYS_LIVEKIT_URL, OYS_LIVEKIT_API_KEY and OYS_LIVEKIT_API_SECRET.', 'olivia-studio' ) . '</p><table class="form-table">';
		foreach ( array( 'url' => array( __( 'LiveKit URL', 'olivia-studio' ), 'text', 'placeholder="wss://….livekit.cloud"' ), 'api_key' => array( __( 'LiveKit API key', 'olivia-studio' ), 'text', 'autocomplete="off"' ), 'api_secret' => array( __( 'LiveKit API secret', 'olivia-studio' ), 'password', 'autocomplete="off"' ) ) as $k => $l ) {
			$const = 'OYS_LIVEKIT_' . strtoupper( $k );
			echo '<tr><th>' . esc_html( $l[0] ) . '</th><td>' . ( defined( $const ) ? '<code>' . esc_html( $const ) . '</code> ' . esc_html__( '(set in wp-config.php)', 'olivia-studio' ) : $f( 'livekit_' . $k, $l[1], 'regular-text', $l[2] ) ) . '</td></tr>'; // phpcs:ignore
		}
		echo '<tr><th>' . esc_html__( 'Live classes', 'olivia-studio' ) . '</th><td>' . $check( 'live_on', __( 'Online classes run in our own live room (not Zoom)', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'The room opens (minutes before the class)', 'olivia-studio' ) . '</th><td>' . $f( 'live_open_minutes', 'number', 'small-text', 'min="5"' ) . '<p class="description">' . esc_html__( 'The teacher can go live an hour before.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Students can turn their camera on up to', 'olivia-studio' ) . '</th><td>' . $f( 'live_grid_max', 'number', 'small-text', 'min="0"' ) . ' ' . esc_html__( 'online places', 'olivia-studio' ) . '<p class="description">' . esc_html__( 'In smaller classes everyone who turns their camera on is seen by all, in a grid; in bigger ones only the teacher is on screen. Private sessions are always two-way.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Recording', 'olivia-studio' ) . '</th><td><select name="s[live_record]">'
			. '<option value="group"' . selected( $s['live_record'], 'group', false ) . '>' . esc_html__( 'Group classes and events', 'olivia-studio' ) . '</option>'
			. '<option value="all"' . selected( $s['live_record'], 'all', false ) . '>' . esc_html__( 'Every online class, private sessions too', 'olivia-studio' ) . '</option>'
			. '<option value="none"' . selected( $s['live_record'], 'none', false ) . '>' . esc_html__( 'No recording', 'olivia-studio' ) . '</option></select>'
			. '<p class="description">' . esc_html__( 'Only the teacher is on the recording, never the participants: it is made in the teacher\'s browser from her own camera and microphone, starts by itself when the class starts and is saved on this server while she teaches.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Participants can watch the recording for (days)', 'olivia-studio' ) . '</th><td>' . $f( 'live_replay_days', 'number', 'small-text', 'min="0"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Recordings are deleted after (days)', 'olivia-studio' ) . '</th><td>' . $f( 'live_keep_days', 'number', 'small-text', 'min="1"' ) . '<p class="description">' . esc_html__( 'Until then the teacher can download them from the live page.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Summary of the opening words', 'olivia-studio' ) . '</th><td><select name="s[live_summary]">'
			. '<option value="auto"' . selected( $s['live_summary'], 'auto', false ) . '>' . esc_html__( 'Shown to the participants right away', 'olivia-studio' ) . '</option>'
			. '<option value="review"' . selected( $s['live_summary'], 'review', false ) . '>' . esc_html__( 'The teacher reads it first and shows it', 'olivia-studio' ) . '</option>'
			. '<option value="off"' . selected( $s['live_summary'], 'off', false ) . '>' . esc_html__( 'No summary', 'olivia-studio' ) . '</option></select> '
			. $f( 'live_intro_minutes', 'number', 'small-text', 'min="0"' ) . ' ' . esc_html__( 'minutes from the start', 'olivia-studio' )
			. '<p class="description">' . esc_html__( 'The teacher\'s first minutes are written down in her browser (speech recognition, best in Chrome) and the AI writes a short summary, not word for word, in the class\'s language. It needs the Anthropic API key (Newsletter → AI settings). The teacher can edit or hide it on the live page.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'People can keep writing in the class chat for (days)', 'olivia-studio' ) . '</th><td>' . $f( 'live_chat_days', 'number', 'small-text', 'min="0"' ) . '</td></tr>'; // phpcs:ignore
		$live = OYS_Live::enabled() ? __( 'On: online classes run in our live room.', 'olivia-studio' ) : ( OYS_Live::configured() ? __( 'Switched off.', 'olivia-studio' ) : __( 'No LiveKit project connected yet.', 'olivia-studio' ) );
		echo '<tr><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><td>' . esc_html( $live ) . ( OYS_Live::configured() ? ' <button class="button" form="oys-live-test">' . esc_html__( 'Test the connection', 'olivia-studio' ) . '</button>' : '' ) . '</td></tr>';
		// The test class (Áron, 2026-11-13): open any time, try everything – nothing is booked, paid or emailed.
		echo '<tr id="live-test"><th>' . esc_html__( 'Test class', 'olivia-studio' ) . '</th><td>';
		if ( OYS_Live::enabled() ) {
			$ts   = OYS_Schedule::get( (int) get_option( OYS_Live::TEST_OPTION ) ); // made by the first „Open”, not by looking
			$open = OYS_Live::is_test( $ts ) && 'scheduled' === $ts->status && time() < oys_ts( $ts->ends_at );
			echo '<p><button class="button button-primary" form="oys-live-test-open">' . esc_html( $open ? __( 'Open it again (fresh, for two hours)', 'olivia-studio' ) : __( 'Open the test class now (for two hours)', 'olivia-studio' ) ) . '</button></p>';
			if ( $open ) {
				$links = OYS_Live::test_links();
				/* translators: %s: time the test class closes */
				echo '<p>' . esc_html( sprintf( __( 'Open until %s.', 'olivia-studio' ), wp_date( get_option( 'time_format' ), oys_ts( $ts->ends_at ) ) ) ) . '</p>';
				echo '<p><strong>' . esc_html__( 'The teacher (you, logged in):', 'olivia-studio' ) . '</strong><br><a href="' . esc_url( $links['teacher'] ) . '" target="_blank" rel="noopener">' . esc_html( $links['teacher'] ) . '</a></p>';
				echo '<p><strong>' . esc_html__( 'Participants (no login needed – open them on a phone or in another browser):', 'olivia-studio' ) . '</strong></p><ol>';
				foreach ( $links['people'] as $u ) {
					echo '<li><input type="text" class="large-text code" readonly value="' . esc_attr( $u ) . '" onclick="this.select()"></li>';
				}
				echo '</ol>';
			}
			echo '<p class="description">' . esc_html__( 'A class that is not on the timetable and can\'t be booked: go live, let the test participants in, try the camera, the chat, raising a hand, muting everyone and the recording. Opening it again starts afresh (the earlier test\'s chat and recording are deleted).', 'olivia-studio' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=oys-live-lab' ) ) . '">' . esc_html__( 'Live class test: every feature, step by step', 'olivia-studio' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Connect the LiveKit project above first.', 'olivia-studio' ) . '</p>';
		}
		echo '</td></tr>';
		echo '</table><h2 id="zoom">' . esc_html__( 'Zoom (live online classes)', 'olivia-studio' ) . '</h2>';
		if ( OYS_Live::enabled() ) {
			echo '<p class="description">' . esc_html__( 'Not used while our own live streaming is on.', 'olivia-studio' ) . '</p>';
		}
		echo '<p class="description" style="max-width:760px">' . esc_html__( 'With Zoom connected, online and hybrid classes get their Zoom meeting automatically; people joining online get the link in their emails and account, and you start the class from the calendar or the roster. In the Zoom App Marketplace create a "Server-to-Server OAuth" app, add the scopes to create, update and delete meetings and to manage registrants, activate it and copy its Account ID, Client ID and Client Secret here. A class with an online link typed in by hand keeps that link.', 'olivia-studio' ) . '</p><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Account ID', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_account_id', 'text', 'regular-text', 'autocomplete="off"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Client ID', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_client_id', 'text', 'regular-text', 'autocomplete="off"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Client Secret', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_client_secret', 'password', 'regular-text', 'autocomplete="off"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Meetings belong to', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_host', 'text', 'regular-text', 'placeholder="me"' ) . '<p class="description">' . esc_html__( '"me" = the account owner, or the email of the Zoom user who teaches.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Options', 'olivia-studio' ) . '</th><td>' . $check( 'zoom_auto', __( 'Create Zoom meetings automatically for online and hybrid classes', 'olivia-studio' ) ) . '<br>'
			. $check( 'zoom_personal', __( 'Personal link for everyone who joins online (Zoom registration; online guests need an email)', 'olivia-studio' ) ) . '<br>'
			. $check( 'zoom_waiting_room', __( 'Waiting room (you let people in)', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		$status = OYS_Zoom::configured() ? ( OYS_Zoom::enabled() ? __( 'Connected details saved; meetings are created automatically.', 'olivia-studio' ) : __( 'Details saved; automatic meetings are off.', 'olivia-studio' ) ) : __( 'Not connected.', 'olivia-studio' );
		echo '<tr><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><td>' . esc_html( $status ) . ( OYS_Zoom::configured() ? ' <button class="button" form="oys-zoom-test">' . esc_html__( 'Test the connection', 'olivia-studio' ) . '</button>' : '' ) . '</td></tr>';
		echo '</table><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Cancellation policy (shown at booking and in emails)', 'olivia-studio' ) . '</th><td><textarea name="s[cancel_policy]" rows="3" class="large-text">' . esc_textarea( $s['cancel_policy'] ) . '</textarea></td></tr>';
		echo '</table><h2>' . esc_html__( 'Participation agreement (waiver)', 'olivia-studio' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Text', 'olivia-studio' ) . '</th><td><textarea name="s[waiver_text]" rows="6" class="large-text">' . esc_textarea( $s['waiver_text'] ) . '</textarea><p class="description">' . esc_html__( 'Have this checked by a lawyer before going live.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Version', 'olivia-studio' ) . '</th><td>' . $f( 'waiver_version', 'text', 'small-text' ) . '<p class="description">' . esc_html__( 'Change the version after editing the text: everyone is asked to accept the new version at their next booking.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '</table><h2>' . esc_html__( 'Emails', 'olivia-studio' ) . '</h2><p>' . wp_kses_post( sprintf( __( 'Sender, reminders and the text of every automatic email are in <a href="%s">Studio → Emails &amp; reminders</a>.', 'olivia-studio' ), esc_url( admin_url( 'admin.php?page=oys-emails' ) ) ) ) . '</p>';
		submit_button();
		echo '</form>' . self::form( 'hpv_register', 'id="oys-hpv-register"' ) . '</form>' . self::form( 'zoom_test', 'id="oys-zoom-test"' ) . '</form>' . self::form( 'live_test', 'id="oys-live-test"' ) . '</form>' . self::form( 'live_test_open', 'id="oys-live-test-open"' ) . '</form></div>'; // phpcs:ignore
	}

	/* ======================================================================
	   Locations and minimum numbers
	   ====================================================================== */

	public static function page_locations() {
		self::header( __( 'Locations', 'olivia-studio' ) );
		$s = OYS_Settings::all();
		echo '<p style="max-width:780px">' . esc_html__( 'Each place can have its own minimum number of people. At the decision time before a class, if fewer people are booked, the class is cancelled automatically: everyone gets their class back and an email with other dates (the next one at the same place, other classes in the next days and a live online class). If enough people are booked, you get a "class is on" email. A class in the calendar can set its own minimum and decision time; a class at a place not listed here uses the defaults below.', 'olivia-studio' ) . '</p>';
		echo self::form( 'save_locations' ) . '<table class="widefat striped oys-table oys-locations"><thead><tr><th>' . esc_html__( 'Name (as typed on the class)', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Address', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Minimum people', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Decide (hours before)', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Classes in the next 4 weeks', 'olivia-studio' ) . '</th></tr></thead><tbody>'; // phpcs:ignore
		$rows   = array_values( OYS_Locations::all() );
		$rows[] = array( 'id' => '', 'name' => '', 'address' => '', 'min_people' => (int) $s['min_people_default'], 'decide_hours' => (int) $s['decide_hours_default'] );
		$rows[] = $rows[ count( $rows ) - 1 ];
		$count  = array();
		foreach ( OYS_Schedule::query( array( 'from' => oys_now(), 'to' => oys_utc_plus( 28 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) ) as $x ) {
			$l = OYS_Locations::for_session( $x );
			$k = $l ? $l['id'] : '';
			$count[ $k ] = ( $count[ $k ] ?? 0 ) + 1;
		}
		foreach ( $rows as $i => $l ) {
			$online = 'online' === $l['id'];
			echo '<tr><td><input type="hidden" name="loc[' . $i . '][id]" value="' . esc_attr( $l['id'] ) . '">'
				. ( $online ? '<b>' . esc_html__( 'Online classes', 'olivia-studio' ) . '</b><input type="hidden" name="loc[' . $i . '][name]" value="' . esc_attr( $l['name'] ) . '">' : '<input class="regular-text" name="loc[' . $i . '][name]" value="' . esc_attr( $l['name'] ) . '" placeholder="' . esc_attr__( 'e.g. Riverside studio', 'olivia-studio' ) . '">' ) . '</td>'
				. '<td>' . ( $online ? '—' : '<input class="regular-text" name="loc[' . $i . '][address]" value="' . esc_attr( $l['address'] ) . '">' ) . '</td>'
				. '<td><input type="number" min="0" class="small-text" name="loc[' . $i . '][min_people]" value="' . (int) $l['min_people'] . '"></td>'
				. '<td><input type="number" min="1" class="small-text" name="loc[' . $i . '][decide_hours]" value="' . (int) $l['decide_hours'] . '"></td>'
				. '<td>' . ( $l['id'] ? (int) ( $count[ $l['id'] ] ?? 0 ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Minimum 0 = the class always goes ahead. To remove a place, clear its name. Empty rows add new places.', 'olivia-studio' ) . '</p>';
		if ( ! empty( $count[''] ) ) {
			echo '<p>' . sprintf( esc_html__( '%d upcoming classes are at a place that isn\'t listed; they use the defaults below.', 'olivia-studio' ), (int) $count[''] ) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Defaults', 'olivia-studio' ) . '</h2><table class="form-table">'
			. '<tr><th>' . esc_html__( 'Minimum people (places not listed)', 'olivia-studio' ) . '</th><td><input type="number" min="0" class="small-text" name="min_people_default" value="' . (int) $s['min_people_default'] . '"></td></tr>'
			. '<tr><th>' . esc_html__( 'Decide (hours before, places not listed)', 'olivia-studio' ) . '</th><td><input type="number" min="1" class="small-text" name="decide_hours_default" value="' . (int) $s['decide_hours_default'] . '"></td></tr>'
			. '<tr><th>' . esc_html__( '"Bring a friend" email', 'olivia-studio' ) . '</th><td>' . sprintf( esc_html__( '%s hours before the decision, to the people booked when a class is short (0 = off)', 'olivia-studio' ), '<input type="number" min="0" class="small-text" name="min_nudge_hours" value="' . (int) $s['min_nudge_hours'] . '">' ) . '</td></tr></table>';
		submit_button( __( 'Save locations', 'olivia-studio' ) );
		echo '</form></div>';
	}

	private static function do_save_locations() {
		OYS_Locations::save_all( array_values( (array) wp_unslash( $_POST['loc'] ?? array() ) ) );
		OYS_Settings::update( array(
			'min_people_default'   => max( 0, (int) ( $_POST['min_people_default'] ?? 2 ) ),
			'decide_hours_default' => max( 1, (int) ( $_POST['decide_hours_default'] ?? 3 ) ),
			'min_nudge_hours'      => max( 0, (int) ( $_POST['min_nudge_hours'] ?? 0 ) ),
		) );
		self::back( 'oys-locations', array(), __( 'Locations saved.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Coupons, birthday gift, loyalty draw
	   ====================================================================== */

	public static function page_rewards() {
		self::header( __( 'Coupons & rewards', 'olivia-studio' ) );
		$s = OYS_Settings::all();
		// Loyalty draw.
		echo '<h2>' . esc_html__( 'Loyalty draw', 'olivia-studio' ) . '</h2>';
		$r = OYS_Rewards::period_months() ? OYS_Rewards::period() : null;
		if ( $r ) {
			$top = OYS_Rewards::standings( $r[1], $r[2], 10 );
			$all = array_sum( array_map( fn( $x ) => (int) $x->tickets, OYS_Rewards::standings( $r[1], $r[2] ) ) );
			echo '<p>' . sprintf( esc_html__( 'Now: %1$s · %2$d tickets so far · drawn on %3$s.', 'olivia-studio' ), esc_html( $r[3] ), (int) $all, esc_html( wp_date( 'M j, Y', oys_ts( $r[2] ) ) ) ) . '</p>';
			if ( $top ) {
				echo '<table class="widefat striped oys-table" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Customer', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Tickets (classes)', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Chance', 'olivia-studio' ) . '</th></tr></thead><tbody>';
				foreach ( $top as $x ) {
					echo '<tr><td>' . self::user_label( $x->user_id ) . '</td><td>' . (int) $x->tickets . '</td><td>' . esc_html( $all ? round( 100 * $x->tickets / $all ) . '%' : '' ) . '</td></tr>'; // phpcs:ignore
				}
				echo '</tbody></table>';
			}
		}
		$draws = OYS_Rewards::draws();
		if ( $draws ) {
			echo '<h3>' . esc_html__( 'Past draws', 'olivia-studio' ) . '</h3><ul class="oys-plain">';
			foreach ( $draws as $d ) {
				echo '<li><b>' . esc_html( $d->label ) . '</b> · ' . ( $d->winner_id ? sprintf( esc_html__( 'won by %1$s (%2$d of %3$d tickets)', 'olivia-studio' ), self::user_label( $d->winner_id ), (int) $d->winner_tickets, (int) $d->tickets_total ) : esc_html__( 'no tickets', 'olivia-studio' ) ) . '</li>'; // phpcs:ignore
			}
			echo '</ul>';
		}
		echo self::form( 'save_rewards' ) . '<table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Draw every', 'olivia-studio' ) . '</th><td><select name="raffle_period">';
		foreach ( array( 'half' => __( 'Half-year (Jan–Jun, Jul–Dec)', 'olivia-studio' ), 'year' => __( 'Year', 'olivia-studio' ), 'quarter' => __( 'Quarter', 'olivia-studio' ), 'off' => __( 'Off', 'olivia-studio' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['raffle_period'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Every class a customer comes to is one ticket. When the period ends, one ticket is drawn; the winner gets the prize in their account and an email.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Prize', 'olivia-studio' ) . '</th><td><select name="raffle_prize">';
		foreach ( OYS_Products::all() as $p ) {
			if ( in_array( $p['kind'], array( 'pack', 'online_pack', 'private_pack', 'private_single' ), true ) ) {
				echo '<option value="' . esc_attr( $p['id'] ) . '"' . selected( $s['raffle_prize'], $p['id'], false ) . '>' . esc_html( $p['name'] ) . '</option>';
			}
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Announcement', 'olivia-studio' ) . '</th><td><label><input type="hidden" name="raffle_announce" value="0"><input type="checkbox" name="raffle_announce" value="1"' . checked( 1, (int) $s['raffle_announce'], false ) . '> ' . esc_html__( 'Send a newsletter to the subscribers after the draw (without the name): who won stays private, and the new round starts.', 'olivia-studio' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Birthday gift', 'olivia-studio' ) . '</th><td><label><input type="hidden" name="birthday_on" value="0"><input type="checkbox" name="birthday_on" value="1"' . checked( 1, (int) $s['birthday_on'], false ) . '> ' . esc_html__( 'Send a gift code on customers\' birthdays', 'olivia-studio' ) . '</label><br>'
			. sprintf( esc_html__( '%1$s%% off, valid for %2$s days', 'olivia-studio' ), '<input type="number" name="birthday_percent" min="1" max="100" class="small-text" value="' . (int) $s['birthday_percent'] . '">', '<input type="number" name="birthday_days" min="1" class="small-text" value="' . (int) $s['birthday_days'] . '">' )
			. '<p class="description">' . sprintf( esc_html__( 'Customers can add their birthday (optional) in their profile. %d have so far.', 'olivia-studio' ), count( get_users( array( 'fields' => 'ID', 'meta_key' => 'oys_birthday', 'meta_compare' => 'EXISTS' ) ) ) ) . '</p></td></tr></table>';
		submit_button( __( 'Save', 'olivia-studio' ) );
		echo '</form>';
		if ( $r ) {
			echo self::form( 'raffle_draw', 'onsubmit="return confirm(\'' . esc_js( __( 'Draw the last finished period now (if it hasn\'t been drawn)?', 'olivia-studio' ) ) . '\')"' ) . '<p><button class="button">' . esc_html__( 'Draw the last period now', 'olivia-studio' ) . '</button> <span class="description">' . esc_html__( 'Normally this happens by itself, the hour after the period ends.', 'olivia-studio' ) . '</span></p></form>'; // phpcs:ignore
		}

		// Coupons.
		echo '<h2 id="coupons">' . esc_html__( 'Discount codes', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( 'For card payments on passes and drop-in classes. Customers type the code before paying; their own codes (birthday) are filled in for them.', 'olivia-studio' ) . '</p>';
		echo self::form( 'coupon_create', 'class="oys-inline-form"' ) // phpcs:ignore
			. '<input name="code" placeholder="' . esc_attr__( 'Code (empty = random)', 'olivia-studio' ) . '" style="text-transform:uppercase"> '
			. '<input type="number" name="value" min="1" value="10" class="small-text" required> <select name="kind"><option value="percent">%</option><option value="amount">' . esc_html( strtoupper( $s['currency'] ) ) . '</option></select> '
			. '<select name="applies">' . implode( '', array_map( fn( $k, $l ) => '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>', array_keys( OYS_Coupons::applies_options() ), OYS_Coupons::applies_options() ) ) . '</select> '
			. '<label>' . esc_html__( 'Uses', 'olivia-studio' ) . ' <input type="number" name="max_uses" min="0" value="1" class="small-text"></label> '
			. '<label>' . esc_html__( 'Until', 'olivia-studio' ) . ' <input type="date" name="expires"></label> '
			. '<input name="note" placeholder="' . esc_attr__( 'Note', 'olivia-studio' ) . '"> <button class="button button-primary">' . esc_html__( 'Create code', 'olivia-studio' ) . '</button></form>'
			. '<p class="description">' . esc_html__( 'Uses 0 = unlimited.', 'olivia-studio' ) . '</p>';
		$rows = OYS_Coupons::query();
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Code', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Discount', 'olivia-studio' ) . '</th><th>' . esc_html__( 'For', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Used', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Until', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Customer', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			echo '<tr><td><code>' . esc_html( $c->code ) . '</code><br><small>' . esc_html( $c->source . ( $c->note ? ' · ' . $c->note : '' ) ) . '</small></td><td>' . esc_html( OYS_Coupons::label( $c ) ) . '</td><td>' . esc_html( OYS_Coupons::applies_options()[ $c->applies ] ?? $c->applies ) . '</td><td>' . (int) $c->used . ' / ' . ( (int) $c->max_uses ?: '∞' ) . '</td><td>' . esc_html( $c->expires_at ? oys_date( $c->expires_at, 'M j, Y' ) : '—' ) . '</td><td>' . ( $c->user_id ? self::user_label( $c->user_id ) : esc_html__( 'anyone', 'olivia-studio' ) ) . '</td><td>' // phpcs:ignore
				. self::form( 'coupon_delete', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this code?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $c->id . '"><button class="button button-small button-link-delete">' . esc_html__( 'Delete', 'olivia-studio' ) . '</button></form></td></tr>'; // phpcs:ignore
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No codes yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function do_save_rewards() {
		OYS_Settings::update( array(
			'raffle_period'    => in_array( $_POST['raffle_period'] ?? '', array( 'half', 'year', 'quarter', 'off' ), true ) ? sanitize_key( $_POST['raffle_period'] ) : 'half',
			'raffle_prize'     => sanitize_key( $_POST['raffle_prize'] ?? 'pack-5' ),
			'raffle_announce'  => empty( $_POST['raffle_announce'] ) ? 0 : 1,
			'birthday_on'      => empty( $_POST['birthday_on'] ) ? 0 : 1,
			'birthday_percent' => min( 100, max( 1, (int) ( $_POST['birthday_percent'] ?? 20 ) ) ),
			'birthday_days'    => max( 1, (int) ( $_POST['birthday_days'] ?? 30 ) ),
		) );
		self::back( 'oys-rewards', array(), __( 'Saved.', 'olivia-studio' ) );
	}

	private static function do_raffle_draw() {
		$d = OYS_Rewards::run_draw();
		self::back( 'oys-rewards', array(), $d ? __( 'Drawn.', 'olivia-studio' ) : __( 'The last period has already been drawn.', 'olivia-studio' ) );
	}

	private static function do_coupon_create() {
		$kind  = 'amount' === ( $_POST['kind'] ?? '' ) ? 'amount' : 'percent';
		$value = 'amount' === $kind ? oys_cents_from_input( wp_unslash( $_POST['value'] ?? '0' ) ) : (int) ( $_POST['value'] ?? 0 );
		$until = sanitize_text_field( wp_unslash( $_POST['expires'] ?? '' ) );
		$res   = OYS_Coupons::create( array(
			'code'     => wp_unslash( $_POST['code'] ?? '' ),
			'kind'     => $kind,
			'value'    => $value,
			'applies'  => sanitize_key( $_POST['applies'] ?? 'all' ),
			'max_uses' => (int) ( $_POST['max_uses'] ?? 1 ),
			'expires'  => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ? oys_local_to_utc( $until . 'T23:59' ) : null,
			'note'     => wp_unslash( $_POST['note'] ?? '' ),
		) );
		self::back( 'oys-rewards', array(), is_wp_error( $res ) ? $res->get_error_message() : sprintf( __( 'Code %s created.', 'olivia-studio' ), OYS_Coupons::get( $res )->code ) );
	}

	private static function do_coupon_delete() {
		OYS_Coupons::delete( (int) $_POST['id'] );
		self::back( 'oys-rewards', array(), __( 'Code deleted.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Newsletter (with AI drafts)
	   ====================================================================== */

	public static function page_newsletter() {
		$id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : -1;
		if ( $id >= 0 ) {
			self::page_newsletter_edit( $id );
			return;
		}
		$subs = count( OYS_Newsletter::subscribers() );
		$list = count( OYS_Newsletter::list_recipients() );
		self::header( __( 'Newsletter', 'olivia-studio' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-newsletter&edit=0' ) ) . '">' . esc_html__( 'Write a newsletter', 'olivia-studio' ) . '</a>' );
		echo '<p>' . sprintf( esc_html( _n( 'A newsletter goes to %d person:', 'A newsletter goes to %d people:', $subs + $list, 'olivia-studio' ) ), (int) ( $subs + $list ) ) . ' ' . sprintf( esc_html__( '%1$d customers who ticked the newsletter box and %2$d addresses from the signup forms.', 'olivia-studio' ), (int) $subs, (int) $list ) . ' ' . esc_html__( 'Emails go out in small batches every few minutes. Every email has an unsubscribe link.', 'olivia-studio' ) . ' <a href="#feliratkozok">' . esc_html__( 'Subscribers', 'olivia-studio' ) . '</a></p>';
		$rows = OYS_Newsletter::query();
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Subject', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Sent', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Date', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		$st = array( 'draft' => __( 'Draft', 'olivia-studio' ), 'sending' => __( 'Sending…', 'olivia-studio' ), 'sent' => __( 'Sent', 'olivia-studio' ) );
		foreach ( $rows as $n ) {
			echo '<tr><td><b>' . esc_html( $n->subject ?: __( '(no subject)', 'olivia-studio' ) ) . '</b></td><td>' . esc_html( $st[ $n->status ] ?? $n->status ) . '</td><td>' . ( 'draft' === $n->status ? '—' : (int) $n->sent . ' / ' . (int) $n->total ) . '</td><td>' . esc_html( oys_date( $n->sent_at ?: ( $n->queued_at ?: $n->created_at ), 'M j, Y g:i a' ) ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-newsletter&edit=' . $n->id ) ) . '">' . esc_html( 'draft' === $n->status ? __( 'Edit', 'olivia-studio' ) : __( 'View', 'olivia-studio' ) ) . '</a></td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No newsletters yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::newsletter_subscribers();
		// AI settings.
		$s = OYS_Settings::all();
		echo '<h2 id="ai">' . esc_html__( 'AI settings', 'olivia-studio' ) . '</h2><p class="description" style="max-width:760px">' . esc_html__( 'The "Write it with AI" button uses Claude from Anthropic. Create an API key at console.anthropic.com (Settings → API keys) and paste it here. A newsletter draft costs a few cents.', 'olivia-studio' ) . '</p>'
			. self::form( 'save_ai' ) . '<table class="form-table"><tr><th>' . esc_html__( 'Anthropic API key', 'olivia-studio' ) . '</th><td>' // phpcs:ignore
			. ( defined( 'OYS_ANTHROPIC_API_KEY' ) ? esc_html__( 'Set in wp-config.php.', 'olivia-studio' ) : '<input type="password" name="ai_api_key" class="regular-text" autocomplete="off" placeholder="' . esc_attr( OYS_AI::ready() ? '••••••••••' : 'sk-ant-…' ) . '"><p class="description">' . esc_html( OYS_AI::ready() ? __( 'A key is saved. Leave empty to keep it.', 'olivia-studio' ) : __( 'Not set yet.', 'olivia-studio' ) ) . '</p>' )
			. '</td></tr><tr><th>' . esc_html__( 'Model', 'olivia-studio' ) . '</th><td><input name="ai_model" class="regular-text" value="' . esc_attr( $s['ai_model'] ) . '"><p class="description">' . esc_html__( 'Default: claude-opus-5.', 'olivia-studio' ) . '</p></td></tr></table>';
		submit_button( __( 'Save AI settings', 'olivia-studio' ) );
		echo '</form></div>';
	}

	private static function page_newsletter_edit( $id ) {
		$n    = $id ? OYS_Newsletter::get( $id ) : null;
		$v    = $n ?: (object) array( 'id' => 0, 'subject' => '', 'preheader' => '', 'body' => __( 'Hi {first_name},', 'olivia-studio' ) . "\n\n", 'button_label' => __( 'Book a class', 'olivia-studio' ), 'button_url' => oys_page_url( 'book' ), 'status' => 'draft', 'sent' => 0, 'total' => 0 );
		$edit = 'draft' === $v->status;
		self::header( $n ? ( $edit ? __( 'Edit newsletter', 'olivia-studio' ) : __( 'Newsletter', 'olivia-studio' ) ) : __( 'Write a newsletter', 'olivia-studio' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-newsletter' ) ) . '">' . esc_html__( 'All newsletters', 'olivia-studio' ) . '</a>' );
		echo '<div class="oys-nl">';
		if ( $edit ) {
			echo '<div class="oys-nl__ai oys-box"><h2>' . esc_html__( 'Write it with AI', 'olivia-studio' ) . '</h2>';
			if ( OYS_AI::ready() ) {
				echo self::form( 'newsletter_ai' ) . '<input type="hidden" name="id" value="' . (int) $v->id . '">' // phpcs:ignore
					. '<p><textarea name="brief" rows="4" class="large-text" placeholder="' . esc_attr__( 'e.g. New Sunday sunrise class on the beach from next month; the full moon flow on the 17th; thank everyone for a lovely summer.', 'olivia-studio' ) . '"></textarea></p>'
					. '<p class="description">' . esc_html__( 'A few words about what to say. It knows your timetable and events for the next three weeks and writes as you. With a draft below, it improves that draft following your note.', 'olivia-studio' ) . '</p>'
					. '<p><button class="button button-primary" name="mode" value="new">' . esc_html__( 'Write a new draft', 'olivia-studio' ) . '</button> ' . ( $n && trim( $v->body ) ? '<button class="button" name="mode" value="improve">' . esc_html__( 'Improve my draft', 'olivia-studio' ) . '</button>' : '' ) . '</p></form>';
			} else {
				echo '<p>' . wp_kses_post( sprintf( __( 'Add an Anthropic API key under <a href="%s">AI settings</a> to draft newsletters with AI.', 'olivia-studio' ), esc_url( admin_url( 'admin.php?page=oys-newsletter#ai' ) ) ) ) . '</p>';
			}
			echo '</div>';
			echo self::form( 'newsletter_save', 'class="oys-form oys-nl__form"' ) . '<input type="hidden" name="id" value="' . (int) $v->id . '">' // phpcs:ignore
				. '<p><label><b>' . esc_html__( 'Subject', 'olivia-studio' ) . '</b><br><input name="subject" class="large-text" value="' . esc_attr( $v->subject ) . '" required></label></p>'
				. '<p><label>' . esc_html__( 'Preview line (shown next to the subject in the inbox)', 'olivia-studio' ) . '<br><input name="preheader" class="large-text" value="' . esc_attr( $v->preheader ) . '"></label></p>'
				. '<p><label><b>' . esc_html__( 'Message', 'olivia-studio' ) . '</b><br><textarea name="body" rows="16" class="large-text code">' . esc_textarea( $v->body ) . '</textarea></label></p>'
				. '<p class="description">' . esc_html__( 'Blank line = new paragraph · "## " heading · "- " list · **bold** · [link text](https://…) · {first_name} = each reader\'s name.', 'olivia-studio' ) . '</p>'
				. '<p><label>' . esc_html__( 'Button', 'olivia-studio' ) . ' <input name="button_label" value="' . esc_attr( $v->button_label ) . '" placeholder="' . esc_attr__( 'Book a class', 'olivia-studio' ) . '"></label> <label>' . esc_html__( 'links to', 'olivia-studio' ) . ' <input type="url" name="button_url" class="regular-text" value="' . esc_attr( $v->button_url ) . '"></label></p>'
				. '<p><button class="button button-primary">' . esc_html__( 'Save draft', 'olivia-studio' ) . '</button> <button class="button" name="and_test" value="1">' . esc_html( sprintf( __( 'Save and send a test to %s', 'olivia-studio' ), wp_get_current_user()->user_email ) ) . '</button></p></form>';
		}
		if ( $n ) {
			[ $subject, $html ] = OYS_Newsletter::email_for( $n, get_current_user_id(), true ); // the unsubscribe link of the preview does nothing
			echo '<h2>' . esc_html__( 'Preview', 'olivia-studio' ) . '</h2><p><b>' . esc_html( $subject ) . '</b>' . ( $n->preheader ? ' — <span class="description">' . esc_html( $n->preheader ) . '</span>' : '' ) . '</p>'
				. '<iframe class="oys-preview" title="' . esc_attr__( 'Email preview', 'olivia-studio' ) . '" srcdoc="' . esc_attr( $html ) . '" style="width:100%;max-width:640px;height:640px;border:1px solid #dcdcde;background:#fff"></iframe>';
			if ( $edit ) {
				$me = wp_get_current_user()->user_email;
				echo '<p>' . self::form( 'newsletter_test', 'class="oys-inline"' ) . '<input type="hidden" name="id" value="' . (int) $n->id . '"><input type="email" name="to" value="' . esc_attr( $me ) . '"> <button class="button">' . esc_html__( 'Send a test', 'olivia-studio' ) . '</button></form></p>'; // phpcs:ignore
				self::newsletter_pick( $n );
				echo '<p>' . self::form( 'newsletter_delete', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Delete this draft?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $n->id . '"><button class="button button-link-delete">' . esc_html__( 'Delete draft', 'olivia-studio' ) . '</button></form></p>'; // phpcs:ignore
			} else {
				echo '<p>' . sprintf( esc_html__( 'Sent to %1$d of %2$d subscribers.', 'olivia-studio' ), (int) $n->sent, (int) $n->total ) . '</p>';
			}
		}
		echo '</div></div>';
	}

	/**
	 * Who gets it (Áron, 26 November: „ki tudja választani az e-mail megfogalmazását követően, hogy kiknek küldi ki magát a
	 * levelet, és lássuk mellette, hogy hány alkalma is van”): every guest and signup address with a tick – those who asked
	 * for the newsletter ticked at first – the sessions they have left next to them, a search and quick choices.
	 */
	private static function newsletter_pick( $n ) {
		$people = OYS_Newsletter::pick_list();
		$day    = fn( $ymd ) => $ymd ? wp_date( get_option( 'date_format' ), strtotime( $ymd . ' 12:00:00 UTC' ) ) : '';
		$rows   = '';
		$on     = 0;
		foreach ( $people as $p ) {
			$total = (int) $p['online'] + (int) $p['held'];
			$parts = array();
			if ( $p['online'] ) {
				$parts[] = $p['until'] ? sprintf( __( 'online %1$d, until %2$s', 'olivia-studio' ), (int) $p['online'], $day( $p['until'] ) ) : sprintf( __( 'online %d', 'olivia-studio' ), (int) $p['online'] );
			}
			if ( $p['held'] ) {
				$parts[] = sprintf( __( 'from Motibro %d, to redeem', 'olivia-studio' ), (int) $p['held'] );
			}
			$on   += $p['subscribed'] ? 1 : 0;
			$rows .= '<label class="oys-pick__row" data-sub="' . ( $p['subscribed'] ? 1 : 0 ) . '" data-sessions="' . $total . '" data-q="' . esc_attr( mb_strtolower( remove_accents( $p['name'] . ' ' . $p['email'] ) ) ) . '">'
				. '<input type="checkbox" name="' . ( 'user' === $p['type'] ? 'pick_users[]' : 'pick_subs[]' ) . '" value="' . (int) $p['id'] . '"' . checked( $p['subscribed'], true, false ) . '>'
				. '<span class="oys-pick__who"><b>' . esc_html( $p['name'] ?: $p['email'] ) . '</b><small>' . esc_html( $p['email'] ) . '</small></span>'
				. '<span class="oys-pick__nl' . ( $p['subscribed'] ? ' is-on' : '' ) . '">' . esc_html( $p['subscribed'] ? ( 'sub' === $p['type'] ? __( 'newsletter (signup form)', 'olivia-studio' ) : __( 'newsletter', 'olivia-studio' ) ) : __( 'no newsletter', 'olivia-studio' ) ) . '</span>'
				. '<span class="oys-pick__n"><b>' . esc_html( $total ? sprintf( _n( '%d session', '%d sessions', $total, 'olivia-studio' ), $total ) : '—' ) . '</b>' . ( $parts ? '<small>' . esc_html( implode( ' · ', $parts ) ) . '</small>' : '' ) . '</span></label>';
		}
		echo '<h2 id="kinek">' . esc_html__( 'Who gets it?', 'olivia-studio' ) . '</h2>'
			. '<p class="description" style="max-width:760px">' . esc_html__( 'Tick the people this letter goes to. Ticked at first: everyone who asked for the newsletter. Next to each name: the sessions they have left (on online passes, and from Motibro still to redeem).', 'olivia-studio' ) . ' '
			. esc_html__( 'Someone without the newsletter tick did not ask for offers: send them only what concerns their pass or their bookings.', 'olivia-studio' ) . '</p>';
		if ( ! $people ) {
			echo '<p>' . esc_html__( 'No guests or subscribers yet.', 'olivia-studio' ) . '</p>';
			return;
		}
		echo self::form( 'newsletter_send', 'class="oys-pick" data-oys-pick data-ask="' . esc_attr( __( 'Send this letter to %d people now?', 'olivia-studio' ) ) . '"' ) // phpcs:ignore
			. '<input type="hidden" name="id" value="' . (int) $n->id . '"><input type="hidden" name="picked" value="1">'
			. '<div class="oys-pick__tools"><input type="search" data-oys-pick-q placeholder="' . esc_attr__( 'Search by name or email', 'olivia-studio' ) . '"> '
			. '<span class="oys-pick__quick">' . esc_html__( 'Tick:', 'olivia-studio' ) . ' <button type="button" class="button button-small" data-oys-pick-set="sub">' . esc_html__( 'who asked for the newsletter', 'olivia-studio' ) . '</button> <button type="button" class="button button-small" data-oys-pick-set="sessions">' . esc_html__( 'who has sessions left', 'olivia-studio' ) . '</button> <button type="button" class="button button-small" data-oys-pick-set="all">' . esc_html__( 'everyone', 'olivia-studio' ) . '</button> <button type="button" class="button button-small" data-oys-pick-set="none">' . esc_html__( 'nobody', 'olivia-studio' ) . '</button></span></div>'
			. '<div class="oys-pick__list">' . $rows . '</div>' // phpcs:ignore
			. '<p class="oys-pick__go"><button class="button button-primary button-hero">' . esc_html__( 'Send to the people ticked', 'olivia-studio' ) . ' (<span data-oys-pick-n>' . (int) $on . '</span>)</button></p></form>';
		?>
		<script>
		( function () {
			var f = document.querySelector( '[data-oys-pick]' );
			if ( ! f ) { return; }
			var rows = [].slice.call( f.querySelectorAll( '.oys-pick__row' ) ), n = f.querySelector( '[data-oys-pick-n]' );
			var count = function () { n.textContent = f.querySelectorAll( '.oys-pick__row input:checked' ).length; };
			f.addEventListener( 'change', count );
			f.querySelector( '[data-oys-pick-q]' ).addEventListener( 'input', function () {
				var q = this.value.toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).trim();
				rows.forEach( function ( r ) { r.hidden = q && r.dataset.q.indexOf( q ) < 0; } );
			} );
			[].forEach.call( f.querySelectorAll( '[data-oys-pick-set]' ), function ( b ) {
				b.addEventListener( 'click', function () {
					var w = b.dataset.oysPickSet;
					rows.forEach( function ( r ) {
						r.querySelector( 'input' ).checked = 'all' === w || ( 'sub' === w && '1' === r.dataset.sub ) || ( 'sessions' === w && +r.dataset.sessions > 0 );
					} );
					count();
				} );
			} );
			f.addEventListener( 'submit', function ( e ) {
				var k = f.querySelectorAll( '.oys-pick__row input:checked' ).length;
				if ( ! k || ! window.confirm( f.dataset.ask.replace( '%d', k ) ) ) { e.preventDefault(); }
			} );
		} )();
		</script>
		<?php
	}

	private static function newsletter_fields() {
		return array(
			'subject'      => wp_unslash( $_POST['subject'] ?? '' ),
			'preheader'    => wp_unslash( $_POST['preheader'] ?? '' ),
			'body'         => wp_unslash( $_POST['body'] ?? '' ),
			'button_label' => wp_unslash( $_POST['button_label'] ?? '' ),
			'button_url'   => wp_unslash( $_POST['button_url'] ?? '' ),
		);
	}

	private static function do_newsletter_save() {
		$id = OYS_Newsletter::save( self::newsletter_fields(), (int) $_POST['id'] );
		if ( ! empty( $_POST['and_test'] ) ) { // phpcs:ignore
			$me = wp_get_current_user()->user_email;
			$ok = OYS_Newsletter::send_test( $id, $me );
			self::back( 'oys-newsletter', array( 'edit' => $id ), $ok ? sprintf( __( 'Draft saved, and a test went to %s.', 'olivia-studio' ), $me ) : __( 'Draft saved, but the test could not be sent.', 'olivia-studio' ), $ok ? 'success' : 'error' );
		}
		self::back( 'oys-newsletter', array( 'edit' => $id ), __( 'Draft saved.', 'olivia-studio' ) );
	}

	private static function do_newsletter_ai() {
		$id    = (int) $_POST['id'];
		$n     = $id ? OYS_Newsletter::get( $id ) : null;
		$draft = 'improve' === ( $_POST['mode'] ?? '' ) && $n ? "Subject: {$n->subject}\n\n{$n->body}" : '';
		$res   = OYS_AI::draft_newsletter( wp_unslash( $_POST['brief'] ?? '' ), $draft );
		if ( is_wp_error( $res ) ) {
			self::back( 'oys-newsletter', array( 'edit' => $id ), $res->get_error_message() );
		}
		$fields = array(
			'subject'      => $res['subject'],
			'preheader'    => $res['preheader'],
			'body'         => $res['body'],
			'button_label' => $res['button_label'],
			'button_url'   => $res['button_label'] ? ( $n && $n->button_url ? $n->button_url : oys_page_url( 'book' ) ) : '',
		);
		$id = OYS_Newsletter::save( $fields, $id );
		self::back( 'oys-newsletter', array( 'edit' => $id ), __( 'Draft written. Read it through, change anything you like, and send a test to yourself.', 'olivia-studio' ) );
	}

	private static function do_newsletter_test() {
		$id = (int) $_POST['id'];
		$ok = OYS_Newsletter::send_test( $id, sanitize_email( wp_unslash( $_POST['to'] ?? '' ) ) );
		self::back( 'oys-newsletter', array( 'edit' => $id ), $ok ? __( 'Test sent.', 'olivia-studio' ) : __( 'The test could not be sent.', 'olivia-studio' ) );
	}

	private static function do_newsletter_send() {
		$id   = (int) $_POST['id'];
		$pick = empty( $_POST['picked'] ) ? null : array( // phpcs:ignore
			'users' => array_map( 'intval', (array) wp_unslash( $_POST['pick_users'] ?? array() ) ), // phpcs:ignore
			'subs'  => array_map( 'intval', (array) wp_unslash( $_POST['pick_subs'] ?? array() ) ), // phpcs:ignore
		);
		$res  = OYS_Newsletter::send( $id, $pick );
		self::back( 'oys-newsletter', array( 'edit' => $id ), is_wp_error( $res ) ? $res->get_error_message() : sprintf( _n( 'Sending to %d subscriber: it goes out over the next few minutes.', 'Sending to %d subscribers: it goes out over the next few minutes.', $res, 'olivia-studio' ), $res ) );
	}

	private static function do_newsletter_delete() {
		OYS_Newsletter::delete( (int) $_POST['id'] );
		self::back( 'oys-newsletter', array(), __( 'Draft deleted.', 'olivia-studio' ) );
	}

	/**
	 * The addresses from the signup forms (Áron, 26 November: the forms on oliviajoga.hu and in the footers): who, from
	 * where, confirmed or waiting; add one by hand, remove one, the list as CSV.
	 */
	private static function newsletter_subscribers() {
		$rows    = OYS_Newsletter::list_query( '', 1000 );
		$sources = array( 'hu-esemenyek' => 'oliviajoga.hu – ' . __( 'Events', 'olivia-studio' ), 'hu-fooldal' => 'oliviajoga.hu – ' . __( 'Home page', 'olivia-studio' ), 'hu-lablec' => 'oliviajoga.hu – ' . __( 'Footer', 'olivia-studio' ), 'online-lablec' => 'oliviajoga.online – ' . __( 'Footer', 'olivia-studio' ), 'admin' => __( 'Added by hand', 'olivia-studio' ) );
		$states  = array( 'pending' => __( 'Waiting for confirmation', 'olivia-studio' ), 'confirmed' => __( 'Subscribed', 'olivia-studio' ), 'unsubscribed' => __( 'Unsubscribed', 'olivia-studio' ) );
		$count   = array_count_values( array_map( fn( $r ) => $r->status, $rows ) );
		echo '<h2 id="feliratkozok">' . esc_html__( 'Subscribers from the signup forms', 'olivia-studio' ) . '</h2><p class="description" style="max-width:760px">'
			. esc_html( sprintf( __( '%1$d subscribed, %2$d waiting for confirmation, %3$d unsubscribed. Someone who signs up gets an email with a link; they only get the newsletter after clicking it. Customers who ticked the box in their account are counted above and are not listed here.', 'olivia-studio' ), (int) ( $count['confirmed'] ?? 0 ), (int) ( $count['pending'] ?? 0 ), (int) ( $count['unsubscribed'] ?? 0 ) ) )
			. '</p>';
		echo self::form( 'subscriber_add', 'style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px"' ) // phpcs:ignore
			. '<input type="email" name="email" required placeholder="' . esc_attr__( 'Email', 'olivia-studio' ) . '"> <input type="text" name="name" placeholder="' . esc_attr__( 'Name (optional)', 'olivia-studio' ) . '"> '
			. '<button class="button">' . esc_html__( 'Add a subscriber', 'olivia-studio' ) . '</button> <span class="description">' . esc_html__( 'Only someone who asked for the newsletter.', 'olivia-studio' ) . '</span></form>';
		echo self::form( 'subscribers_export', 'style="margin:0 0 12px"' ) . '<button class="button">' . esc_html__( 'Download the list (CSV)', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
		echo '<table class="widefat striped oys-table"><thead><tr><th>' . esc_html__( 'Email', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Name', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Signed up on', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Date', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $r->name ?: '—' ) . '</td><td>' . esc_html( $sources[ $r->source ] ?? ( $r->source ?: '—' ) ) . '</td><td>' . esc_html( $states[ $r->status ] ?? $r->status ) . '</td><td>' . esc_html( oys_date( $r->confirmed_at ?: $r->created_at, 'M j, Y' ) ) . '</td><td>'
				. self::form( 'subscriber_remove', 'onsubmit="return confirm(\'' . esc_js( __( 'Remove this address from the list?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><button class="button button-small">' . esc_html__( 'Remove', 'olivia-studio' ) . '</button></form></td></tr>'; // phpcs:ignore
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Nobody has signed up with the form yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function do_subscriber_add() {
		$res = OYS_Newsletter::list_add( wp_unslash( $_POST['email'] ?? '' ), wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore
		self::back( 'oys-newsletter', array(), is_wp_error( $res ) ? $res->get_error_message() : __( 'Subscriber added.', 'olivia-studio' ), is_wp_error( $res ) ? 'error' : 'success' );
	}

	private static function do_subscriber_remove() {
		OYS_Newsletter::list_delete( (int) ( $_POST['id'] ?? 0 ) ); // phpcs:ignore
		self::back( 'oys-newsletter', array(), __( 'Removed from the list.', 'olivia-studio' ) );
	}

	private static function do_subscribers_export() {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="hirlevel-feliratkozok-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'email', 'name', 'source', 'status', 'signed_up', 'confirmed', 'unsubscribed' ), ',', '"', '\\' );
		foreach ( OYS_Newsletter::list_query( '', 100000 ) as $r ) {
			fputcsv( $out, array( $r->email, $r->name, $r->source, $r->status, $r->created_at, (string) $r->confirmed_at, (string) $r->unsub_at ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function do_save_ai() {
		$v = array( 'ai_model' => sanitize_text_field( wp_unslash( $_POST['ai_model'] ?? '' ) ) ?: OYS_AI::DEFAULT_MODEL );
		$k = trim( sanitize_text_field( wp_unslash( $_POST['ai_api_key'] ?? '' ) ) );
		if ( '' !== $k ) {
			$v['ai_api_key'] = $k;
		}
		OYS_Settings::update( $v );
		self::back( 'oys-newsletter', array(), __( 'AI settings saved.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Emails & reminders
	   ====================================================================== */

	public static function page_emails() {
		$key = sanitize_key( $_GET['edit'] ?? '' );
		if ( $key && OYS_Email_Templates::get( $key ) ) {
			self::page_email_edit( $key );
			return;
		}
		self::header( __( 'Emails & reminders', 'olivia-studio' ) );
		$st  = OYS_Settings::all();
		$num = fn( $k, $unit ) => '<input type="number" min="0" class="small-text" name="s[' . $k . ']" value="' . (int) $st[ $k ] . '"> ' . esc_html( $unit );
		echo self::form( 'save_emails' ) . '<h2>' . esc_html__( 'Sender', 'olivia-studio' ) . '</h2><table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Sender name', 'olivia-studio' ) . '</th><td><input type="text" class="regular-text" name="s[email_from_name]" value="' . esc_attr( $st['email_from_name'] ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Sender email', 'olivia-studio' ) . '</th><td><input type="email" class="regular-text" name="s[email_from]" value="' . esc_attr( $st['email_from'] ) . '"><p class="description">' . esc_html__( 'An address on your own domain (e.g. info@yourdomain), the one your sending service has verified – then emails land in the inbox.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Replies go to', 'olivia-studio' ) . '</th><td><input type="email" class="regular-text" name="s[email_reply_to]" value="' . esc_attr( $st['email_reply_to'] ) . '"><p class="description">' . esc_html__( 'When someone answers an email, the answer goes here (e.g. your own mailbox). Empty: to the sender address.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Studio notifications go to', 'olivia-studio' ) . '</th><td><input type="email" class="regular-text" name="s[notify_email]" value="' . esc_attr( $st['notify_email'] ) . '"></td></tr></table>';
		self::smtp_fields( $st );
		echo '<h2>' . esc_html__( 'Reminders', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( '0 turns a reminder off. They go out automatically; people who book after a reminder\'s time just get their confirmation.', 'olivia-studio' ) . '</p><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Class reminder', 'olivia-studio' ) . '</th><td>' . $num( 'reminder_hours', __( 'hours before the class', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Second reminder', 'olivia-studio' ) . '</th><td>' . $num( 'reminder2_hours', __( 'hours before the class (e.g. 2 on the day)', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Online: link to join', 'olivia-studio' ) . '</th><td>' . $num( 'join_reminder_minutes', (int) $st['online_only'] ? __( 'minutes before the class', 'olivia-studio' ) : __( 'minutes before an online or hybrid class', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Pass about to expire', 'olivia-studio' ) . '</th><td>' . $num( 'pass_expiry_days', __( 'days before unused classes expire', 'olivia-studio' ) ) . '</td></tr></table>'; // phpcs:ignore

		$to       = array( 'customer' => __( 'Customer', 'olivia-studio' ), 'guest' => __( 'Guest / recipient', 'olivia-studio' ), 'studio' => __( 'You', 'olivia-studio' ) );
		$show_all = ! empty( $_GET['all'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$hidden   = array_filter( array_keys( OYS_Email_Templates::registry() ), fn( $k ) => ! OYS_Email_Templates::in_use( $k ) );
		if ( $hidden ) {
			echo '<p class="description">' . ( $show_all
				? esc_html__( 'All emails are listed, also those of features you don\'t use.', 'olivia-studio' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=oys-emails' ) ) . '">' . esc_html__( 'Show only the ones in use', 'olivia-studio' ) . '</a>'
				: esc_html( sprintf( _n( '%d email is hidden: its feature is not in use (e.g. memberships).', '%d emails are hidden: their features are not in use (e.g. memberships).', count( $hidden ), 'olivia-studio' ), count( $hidden ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=oys-emails&all=1' ) ) . '">' . esc_html__( 'Show all', 'olivia-studio' ) . '</a>' ) . '</p>';
		}
		foreach ( OYS_Email_Templates::groups() as $group => $label ) {
			$rows = array_filter( OYS_Email_Templates::registry(), fn( $d, $k ) => $group === $d['group'] && ( $show_all || OYS_Email_Templates::in_use( $k ) ), ARRAY_FILTER_USE_BOTH );
			if ( ! $rows ) {
				continue;
			}
			echo '<h2>' . esc_html( $label ) . '</h2><table class="widefat striped oys-table oys-emails"><thead><tr><th style="width:70px">' . esc_html__( 'On', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Email', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Sent when', 'olivia-studio' ) . '</th><th>' . esc_html__( 'To', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $k => $d ) {
				$t = OYS_Email_Templates::get( $k );
				echo '<tr' . ( $t['enabled'] ? '' : ' class="is-off"' ) . '><td><input type="hidden" name="on[' . esc_attr( $k ) . ']" value="0"><label class="oys-switch"><input type="checkbox" name="on[' . esc_attr( $k ) . ']" value="1"' . checked( $t['enabled'], true, false ) . ' aria-label="' . esc_attr( $d['label'] ) . '"><span></span></label></td>'
					. '<td><b>' . esc_html( $d['label'] ) . '</b>' . ( $d['editable'] ? '<br><small>' . esc_html( OYS_Email_Templates::fill( $t['subject'], OYS_Emails::sample_vars() ) ) . '</small>' : '' ) . ( $t['customized'] ? ' <span class="oys-status oys-status--offered">' . esc_html__( 'edited', 'olivia-studio' ) . '</span>' : '' ) . '</td>'
					. '<td>' . esc_html( $d['when'] ) . '</td><td>' . esc_html( $to[ $d['to'] ] ) . '</td>'
					. '<td>' . ( $d['editable'] ? '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-emails&edit=' . $k ) ) . '">' . esc_html__( 'Edit text', 'olivia-studio' ) . '</a>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		submit_button( __( 'Save emails and reminders', 'olivia-studio' ) );
		echo '</form>' . self::form( 'smtp_test', 'id="oys-smtp-test"' ) . '</form></div>'; // phpcs:ignore
	}

	/** Sending server (SMTP): host, port, encryption, user, password (never shown), a test button and the last error. */
	private static function smtp_fields( array $st ) {
		$locked = OYS_Mailer::locked();
		$lock   = fn( $k ) => in_array( $k, $locked, true ) ? ' disabled' : '';
		$note   = '<p class="description">' . esc_html__( 'Set in wp-config.php on the server.', 'olivia-studio' ) . '</p>';
		echo '<h2 id="smtp">' . esc_html__( 'Sending server (SMTP)', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( 'The login of your email sending service (e.g. Brevo: smtp-relay.brevo.com, port 587, STARTTLS, the login and SMTP key from SMTP & API). Leave the server empty to send without it (or with an SMTP plugin).', 'olivia-studio' ) . '</p><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'SMTP server', 'olivia-studio' ) . '</th><td><input type="text" class="regular-text" name="s[smtp_host]" placeholder="smtp-relay.brevo.com" value="' . esc_attr( $st['smtp_host'] ) . '"' . $lock( 'host' ) . '>' . ( $lock( 'host' ) ? $note : '' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Port', 'olivia-studio' ) . '</th><td><input type="number" min="1" max="65535" class="small-text" name="s[smtp_port]" value="' . (int) $st['smtp_port'] . '"' . $lock( 'port' ) . '></td></tr>'; // phpcs:ignore
		$sec = array( 'tls' => __( 'STARTTLS (port 587)', 'olivia-studio' ), 'ssl' => __( 'SSL (port 465)', 'olivia-studio' ), 'none' => __( 'None', 'olivia-studio' ) );
		echo '<tr><th>' . esc_html__( 'Encryption', 'olivia-studio' ) . '</th><td><select name="s[smtp_secure]"' . $lock( 'secure' ) . '>'; // phpcs:ignore
		foreach ( $sec as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $st['smtp_secure'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Login', 'olivia-studio' ) . '</th><td><input type="text" class="regular-text" name="s[smtp_user]" autocomplete="off" value="' . esc_attr( $st['smtp_user'] ) . '"' . $lock( 'user' ) . '></td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Password / SMTP key', 'olivia-studio' ) . '</th><td><input type="password" class="regular-text" name="s[smtp_pass]" autocomplete="new-password" value=""' . $lock( 'pass' ) . '>' // phpcs:ignore
			. ( '' !== (string) $st['smtp_pass'] || $lock( 'pass' ) ? '<p class="description">' . esc_html( $lock( 'pass' ) ? __( 'Set in wp-config.php on the server.', 'olivia-studio' ) : __( 'A key is saved. Leave empty to keep it.', 'olivia-studio' ) ) . '</p>' : '' ) . '</td></tr>';
		$err = OYS_Mailer::last_error();
		echo '<tr><th>' . esc_html__( 'Now', 'olivia-studio' ) . '</th><td><p>' . esc_html( OYS_Mailer::describe() ) . '</p>'
			. ( $err ? '<p class="oys-status oys-status--cancelled" style="display:inline-block">' . esc_html( sprintf( __( 'Last error (%1$s): %2$s', 'olivia-studio' ), wp_date( 'Y-m-d H:i', $err['time'] ), $err['message'] ) ) . '</p>' : '' )
			. '<p><button type="submit" form="oys-smtp-test" class="button">' . esc_html( sprintf( __( 'Send a test email to %s', 'olivia-studio' ), wp_get_current_user()->user_email ) ) . '</button></p><p class="description">' . esc_html__( 'Save first, then test.', 'olivia-studio' ) . '</p></td></tr></table>';
	}

	/** The email language being edited (?lang=hu); English by default. */
	private static function email_lang( $from = null ) {
		$lang = sanitize_key( $from ?? ( $_GET['lang'] ?? 'en' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return isset( OYS_I18n::languages()[ $lang ] ) ? $lang : 'en';
	}

	private static function page_email_edit( $key ) {
		$lang = self::email_lang();
		$t    = OYS_Email_Templates::get( $key, $lang );
		self::header( sprintf( __( 'Email: %s', 'olivia-studio' ), $t['label'] ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-emails' ) ) . '">' . esc_html__( '← All emails', 'olivia-studio' ) . '</a>' );
		[ , $html ] = OYS_I18n::with( $lang, fn() => OYS_Emails::preview( $key ) );
		$tabs = '';
		foreach ( OYS_I18n::languages() as $code => $l ) {
			$tabs .= '<a class="nav-tab' . ( $code === $lang ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=oys-emails&edit=' . $key . '&lang=' . $code ) ) . '">' . esc_html( $l['name'] ) . ( OYS_Email_Templates::get( $key, $code )['customized'] ? ' •' : '' ) . '</a>';
		}
		echo '<p>' . esc_html( $t['when'] ) . '.</p><nav class="nav-tab-wrapper">' . $tabs . '</nav><p class="description">' . esc_html__( 'Customers get this email in the language they use on the site. Each language has its own text; the original texts are already translated.', 'olivia-studio' ) . '</p><div class="oys-email-edit"><div>'; // phpcs:ignore
		$lang_field = '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		echo self::form( 'save_email_template' ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">' . $lang_field . '<table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Send this email', 'olivia-studio' ) . '</th><td><input type="hidden" name="enabled" value="0"><label><input type="checkbox" name="enabled" value="1"' . checked( $t['enabled'], true, false ) . '> ' . esc_html__( 'On', 'olivia-studio' ) . '</label></td></tr>';
		echo '<tr><th><label for="oys-e-subject">' . esc_html__( 'Subject', 'olivia-studio' ) . '</label></th><td><input type="text" id="oys-e-subject" class="large-text" name="subject" value="' . esc_attr( $t['subject'] ) . '"></td></tr>';
		echo '<tr><th><label for="oys-e-heading">' . esc_html__( 'Heading', 'olivia-studio' ) . '</label></th><td><input type="text" id="oys-e-heading" class="large-text" name="heading" value="' . esc_attr( $t['heading'] ) . '"></td></tr>';
		echo '<tr><th><label for="oys-e-message">' . esc_html__( 'Message', 'olivia-studio' ) . '</label></th><td><textarea id="oys-e-message" class="large-text" rows="5" name="message">' . esc_textarea( $t['message'] ) . '</textarea><p class="description">' . esc_html__( 'Shown before the automatic details (class, time, place, guests, join link, receipt…). An empty line starts a new paragraph.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th><label for="oys-e-closing">' . esc_html__( 'Closing note', 'olivia-studio' ) . '</label></th><td><textarea id="oys-e-closing" class="large-text" rows="3" name="closing">' . esc_textarea( $t['closing'] ) . '</textarea><p class="description">' . esc_html__( 'After the details. Can be empty.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th><label for="oys-e-button">' . esc_html__( 'Button', 'olivia-studio' ) . '</label></th><td><input type="text" id="oys-e-button" class="regular-text" name="button" value="' . esc_attr( $t['button'] ) . '"><p class="description">' . esc_html__( 'Empty = no button.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Placeholders', 'olivia-studio' ) . '</th><td><p class="oys-vars">' . implode( ' ', array_map( fn( $v ) => '<code>{' . esc_html( $v ) . '}</code>', $t['vars'] ) ) . '</p></td></tr></table>';
		submit_button( __( 'Save', 'olivia-studio' ), 'primary', 'submit', false );
		echo '</form><p class="oys-email-actions">' . self::form( 'test_email', 'class="oys-inline"' ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">' . $lang_field . '<button class="button">' . esc_html( sprintf( __( 'Send a test to %s', 'olivia-studio' ), wp_get_current_user()->user_email ) ) . '</button></form> ' // phpcs:ignore
			. ( $t['customized'] ? self::form( 'reset_email_template', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Go back to the original text?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">' . $lang_field . '<button class="button button-link-delete">' . esc_html__( 'Reset to the original text', 'olivia-studio' ) . '</button></form>' : '' ) . '</p></div>'; // phpcs:ignore
		echo '<div class="oys-email-preview"><p class="description">' . esc_html__( 'Preview with sample details (save to update):', 'olivia-studio' ) . '</p><iframe title="' . esc_attr__( 'Email preview', 'olivia-studio' ) . '" srcdoc="' . esc_attr( $html ) . '"></iframe></div></div></div>';
	}

	private static function do_save_emails() {
		$in    = wp_unslash( $_POST['s'] ?? array() );
		$clean = array(
			'email_from_name' => sanitize_text_field( $in['email_from_name'] ?? '' ),
			'email_from'      => sanitize_email( $in['email_from'] ?? '' ),
			'notify_email'    => sanitize_email( $in['notify_email'] ?? '' ),
			'email_reply_to'  => sanitize_email( $in['email_reply_to'] ?? '' ),
		);
		if ( isset( $in['smtp_host'] ) ) {
			$clean['smtp_host'] = preg_replace( '/[^a-z0-9.\-]/i', '', (string) $in['smtp_host'] );
		}
		if ( isset( $in['smtp_port'] ) ) {
			$clean['smtp_port'] = max( 1, min( 65535, (int) $in['smtp_port'] ) );
		}
		if ( isset( $in['smtp_secure'] ) ) {
			$clean['smtp_secure'] = in_array( $in['smtp_secure'], array( 'tls', 'ssl', 'none' ), true ) ? $in['smtp_secure'] : 'tls';
		}
		if ( isset( $in['smtp_user'] ) ) {
			$clean['smtp_user'] = sanitize_text_field( $in['smtp_user'] );
		}
		if ( '' !== trim( (string) ( $in['smtp_pass'] ?? '' ) ) ) { // empty keeps the saved key
			$clean['smtp_pass'] = trim( (string) $in['smtp_pass'] );
		}
		foreach ( array( 'reminder_hours', 'reminder2_hours', 'join_reminder_minutes', 'pass_expiry_days' ) as $k ) {
			$clean[ $k ] = max( 0, (int) ( $in[ $k ] ?? 0 ) );
		}
		OYS_Settings::update( $clean );
		foreach ( (array) ( $_POST['on'] ?? array() ) as $k => $v ) {
			$k = sanitize_key( $k );
			if ( OYS_Email_Templates::get( $k ) ) {
				OYS_Email_Templates::save( $k, array( 'enabled' => (int) $v ? 1 : 0 ) );
			}
		}
		self::back( 'oys-emails', array(), __( 'Saved.', 'olivia-studio' ) );
	}

	private static function do_save_email_template() {
		$key = sanitize_key( $_POST['key'] ?? '' );
		$t   = OYS_Email_Templates::get( $key );
		if ( ! $t || ! $t['editable'] ) {
			self::back( 'oys-emails' );
		}
		$in   = wp_unslash( $_POST );
		$lang = self::email_lang( $in['lang'] ?? 'en' );
		OYS_Email_Templates::save( $key, array(
			'enabled' => empty( $in['enabled'] ) ? 0 : 1,
			'subject' => sanitize_text_field( $in['subject'] ?? '' ),
			'heading' => sanitize_text_field( $in['heading'] ?? '' ),
			'message' => sanitize_textarea_field( $in['message'] ?? '' ),
			'closing' => sanitize_textarea_field( $in['closing'] ?? '' ),
			'button'  => sanitize_text_field( $in['button'] ?? '' ),
		), $lang );
		self::back( 'oys-emails', array( 'edit' => $key, 'lang' => $lang ), __( 'Saved.', 'olivia-studio' ) );
	}

	private static function do_reset_email_template() {
		$key  = sanitize_key( $_POST['key'] ?? '' );
		$lang = self::email_lang( wp_unslash( $_POST['lang'] ?? 'en' ) );
		OYS_Email_Templates::reset( $key, $lang );
		self::back( 'oys-emails', array( 'edit' => $key, 'lang' => $lang ), __( 'Back to the original text.', 'olivia-studio' ) );
	}

	private static function do_test_email() {
		$key = sanitize_key( $_POST['key'] ?? '' );
		$to   = wp_get_current_user()->user_email;
		$lang = self::email_lang( wp_unslash( $_POST['lang'] ?? 'en' ) );
		$ok   = OYS_Email_Templates::get( $key ) && OYS_I18n::with( $lang, fn() => OYS_Emails::send_test( $key, $to ) );
		self::back( 'oys-emails', array( 'edit' => $key, 'lang' => $lang ), $ok ? sprintf( __( 'Test sent to %s.', 'olivia-studio' ), $to ) : __( 'The test could not be sent. Check the sending server (SMTP) on the Emails page.', 'olivia-studio' ) );
	}

	private static function do_hpv_register() {
		$res = OYS_HPV_Pay::register();
		self::back( 'oys-settings', array(), is_wp_error( $res ) ? sprintf( __( 'The CRM did not accept the connection: %s', 'olivia-studio' ), $res->get_error_message() ) : __( 'Connected to the CRM: card payments go to Arovia.', 'olivia-studio' ), is_wp_error( $res ) ? 'error' : 'success' );
	}

	private static function do_smtp_test() {
		$to  = wp_get_current_user()->user_email;
		$res = OYS_Mailer::test( $to );
		self::back( 'oys-emails', array(), is_wp_error( $res ) ? sprintf( __( 'The test email could not be sent: %s', 'olivia-studio' ), $res->get_error_message() ) : sprintf( __( 'Test sent to %s. Check the inbox (and the spam folder).', 'olivia-studio' ), $to ), is_wp_error( $res ) ? 'error' : 'success' );
	}

	private static function do_live_test_open() {
		OYS_Live::open_test();
		self::back( 'oys-settings', array( 'test' => 1 ), __( 'The test class is open for two hours.', 'olivia-studio' ) );
	}

	private static function do_live_test() {
		$res = OYS_Live::test_connection();
		self::back( 'oys-settings', array(), is_wp_error( $res ) ? $res->get_error_message() : __( 'LiveKit is connected.', 'olivia-studio' ) );
	}

	private static function do_zoom_test() {
		$res = OYS_Zoom::test_connection();
		self::back( 'oys-settings', array(), is_wp_error( $res ) ? sprintf( __( 'Zoom: %s', 'olivia-studio' ), $res->get_error_message() ) : sprintf( __( 'Zoom is connected (%s).', 'olivia-studio' ), $res ) );
	}

	/** Start the class as host: Zoom gives a fresh start link each time. */
	private static function do_zoom_start() {
		$url = OYS_Zoom::start_url( (int) ( $_REQUEST['session'] ?? 0 ) );
		if ( is_wp_error( $url ) || ! $url ) {
			self::back( 'oys-schedule', array( 'session' => (int) ( $_REQUEST['session'] ?? 0 ) ), is_wp_error( $url ) ? $url->get_error_message() : __( 'Zoom did not return a start link.', 'olivia-studio' ) );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- zoom.us
		exit;
	}

	private static function do_zoom_create() {
		$res = OYS_Zoom::ensure_meeting( (int) ( $_REQUEST['session'] ?? 0 ) );
		self::back( 'oys-schedule', array( 'session' => (int) ( $_REQUEST['session'] ?? 0 ) ), is_wp_error( $res ) ? $res->get_error_message() : __( 'Zoom meeting ready.', 'olivia-studio' ) );
	}

	private static function do_save_settings() {
		$in    = wp_unslash( $_POST['s'] ?? array() );
		$clean = array();
		foreach ( OYS_Settings::defaults() as $k => $default ) {
			if ( ! isset( $in[ $k ] ) || ( in_array( $k, self::SECRETS, true ) && '' === trim( (string) $in[ $k ] ) ) ) {
				continue; // a secret left empty keeps the saved one
			}
			if ( is_int( $default ) ) {
				$clean[ $k ] = max( 0, (int) $in[ $k ] );
			} elseif ( 'fb_group_url' === $k ) {
				$clean[ $k ] = esc_url_raw( trim( $in[ $k ] ) );
			} elseif ( in_array( $k, array( 'waiver_text', 'cancel_policy', 'private_note' ), true ) ) {
				$clean[ $k ] = sanitize_textarea_field( $in[ $k ] );
			} elseif ( in_array( $k, array( 'email_from', 'notify_email' ), true ) ) {
				$clean[ $k ] = sanitize_email( $in[ $k ] );
			} else {
				$clean[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		$clean['stripe_mode'] = 'live' === ( $clean['stripe_mode'] ?? '' ) ? 'live' : 'test';
		if ( isset( $clean['pay_provider'] ) ) {
			$clean['pay_provider'] = 'hpv' === $clean['pay_provider'] ? 'hpv' : 'stripe';
		}
		$clean['currency']    = strtolower( substr( preg_replace( '/[^a-z]/i', '', $clean['currency'] ?? 'usd' ), 0, 3 ) ) ?: 'usd';
		$clean['hold_minutes'] = max( 30, (int) ( $clean['hold_minutes'] ?? 30 ) );
		$clean['online_per_credit'] = max( 1, (int) ( $clean['online_per_credit'] ?? 4 ) );
		if ( isset( $clean['live_record'] ) && ! in_array( $clean['live_record'], array( 'group', 'all', 'none' ), true ) ) {
			$clean['live_record'] = 'group';
		}
		if ( isset( $clean['live_summary'] ) && ! in_array( $clean['live_summary'], array( 'auto', 'review', 'off' ), true ) ) {
			$clean['live_summary'] = 'auto';
		}
		foreach ( array( 'livekit_url', 'livekit_api_key', 'livekit_api_secret' ) as $k ) {
			if ( isset( $clean[ $k ] ) ) {
				$clean[ $k ] = trim( $clean[ $k ] );
			}
		}
		if ( isset( $clean['live_open_minutes'] ) ) {
			$clean['live_open_minutes'] = max( 5, $clean['live_open_minutes'] );
		}
		foreach ( array( 'zoom_account_id', 'zoom_client_id', 'zoom_client_secret' ) as $k ) {
			if ( isset( $clean[ $k ] ) && OYS_Settings::get( $k ) !== $clean[ $k ] ) {
				delete_transient( OYS_Zoom::TOKEN );
			}
		}
		if ( isset( $_POST['online_price'] ) ) {
			$clean['online_price_cents'] = oys_cents_from_input( wp_unslash( $_POST['online_price'] ) );
		}
		if ( isset( $_POST['donation_min'] ) ) {
			$clean['donation_min_cents'] = oys_cents_from_input( wp_unslash( $_POST['donation_min'] ) );
		}
		if ( isset( $clean['pay_later'] ) ) {
			$clean['pay_later'] = in_array( $clean['pay_later'], array( 'all', 'first', 'off' ), true ) ? $clean['pay_later'] : 'all';
		}
		if ( isset( $_POST['oys_langs'] ) ) {
			$langs              = array_values( array_intersect( array_keys( OYS_I18n::LANGS ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['oys_langs'] ) ) ) );
			$clean['languages'] = implode( ',', $langs ?: array_keys( OYS_I18n::LANGS ) );
		}
		if ( isset( $clean['default_lang'] ) ) {
			$langs                 = explode( ',', $clean['languages'] ?? implode( ',', array_keys( OYS_I18n::languages() ) ) );
			$clean['default_lang'] = in_array( $clean['default_lang'], $langs, true ) ? $clean['default_lang'] : $langs[0];
		}
		OYS_Settings::update( $clean );
		self::back( 'oys-settings', array(), __( 'Settings saved.', 'olivia-studio' ) );
	}
}
