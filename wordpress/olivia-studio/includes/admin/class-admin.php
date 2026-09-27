<?php
/**
 * Studio dashboard in wp-admin: today, schedule and rosters, weekly timetable,
 * private requests, customers, orders, gift cards, prices and settings.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Admin {

	const CAP = 'oys_manage';

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
			'grant_pass', 'adjust_pass', 'refund', 'save_product', 'delete_product', 'save_settings', 'cancel_booking', 'membership', 'zoom_test', 'zoom_start', 'zoom_create', 'save_emails', 'save_email_template', 'reset_email_template', 'test_email', 'message', 'pay_later_reset' );
		foreach ( $actions as $a ) {
			add_action( 'admin_post_oys_admin_' . $a, array( __CLASS__, 'guard' ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	public static function menu() {
		$new = count( OYS_Privates::query( 'new' ) );
		add_menu_page( __( 'Studio', 'olivia-studio' ), __( 'Studio', 'olivia-studio' ) . ( $new ? ' <span class="awaiting-mod">' . $new . '</span>' : '' ), self::CAP, 'oys', array( __CLASS__, 'page_today' ), 'dashicons-universal-access', 3 );
		$pages = array(
			'oys'           => array( __( 'Today', 'olivia-studio' ), 'page_today' ),
			'oys-calendar'  => array( __( 'Calendar', 'olivia-studio' ), 'page_calendar' ),
			'oys-schedule'  => array( __( 'Rosters & list', 'olivia-studio' ), 'page_schedule' ),
			'oys-private'   => array( __( 'Private requests', 'olivia-studio' ) . ( $new ? ' <span class="awaiting-mod">' . $new . '</span>' : '' ), 'page_private' ),
			'oys-customers' => array( __( 'Customers', 'olivia-studio' ), 'page_customers' ),
			'oys-members'   => array( __( 'Memberships', 'olivia-studio' ), 'page_members' ),
			'oys-orders'    => array( __( 'Payments', 'olivia-studio' ), 'page_orders' ),
			'oys-gifts'     => array( __( 'Gift cards', 'olivia-studio' ), 'page_gifts' ),
			'oys-products'  => array( __( 'Prices & passes', 'olivia-studio' ), 'page_products' ),
			'oys-emails'    => array( __( 'Emails & reminders', 'olivia-studio' ), 'page_emails' ),
			'oys-settings'  => array( __( 'Settings', 'olivia-studio' ), 'page_settings' ),
		);
		foreach ( $pages as $slug => $p ) {
			add_submenu_page( 'oys', $p[0], $p[0], self::CAP, $slug, array( __CLASS__, $p[1] ) );
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

	private static function back( $page, $args = array(), $msg = '' ) {
		if ( $msg ) {
			$args['oys_msg'] = rawurlencode( $msg );
		}
		oys_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
	}

	private static function header( $title, $actions = '' ) {
		echo '<div class="wrap oys-wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<hr class="wp-header-end">'; // phpcs:ignore
		if ( ! empty( $_GET['oys_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['oys_msg'] ) ) . '</p></div>';
		}
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
		$rev30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount_cents) FROM $o WHERE status IN ('paid','partially_refunded') AND paid_at >= %s", oys_utc_plus( -30 * DAY_IN_SECONDS ) ) );
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
		echo '<tr><th>' . esc_html__( 'Short note', 'olivia-studio' ) . '</th><td><input class="regular-text" name="note" value="' . esc_attr( $v->note ) . '" placeholder="Sunrise on the sand, weather permitting"></td></tr>';
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
		);
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
		echo '<p>' . esc_html( oys_is_online( $s ) ? __( 'Online', 'olivia-studio' ) : $s->location ) . ' · ' . sprintf( esc_html__( '%1$d of %2$d booked', 'olivia-studio' ), (int) $s->booked, (int) $s->capacity )
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
				. '<p class="description">' . esc_html__( 'Everyone gets their own email, with the class details and a link to their bookings. You can use {first_name}, {class}, {day}, {date_short}, {time} and {location}.', 'olivia-studio' ) . '</p>'
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
		$rows[] = (object) array( 'id' => 0, 'class_slug' => '', 'weekday' => 1, 'start_time' => '18:00', 'duration_min' => 60, 'capacity' => 12, 'location' => '', 'online_url' => '', 'price_cents' => 2500, 'note' => '', 'active' => 1 );
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
				. '<td><input form="' . $fid . '" name="location" value="' . esc_attr( $t->location ) . '" placeholder="' . esc_attr__( 'Location', 'olivia-studio' ) . '"><br><input form="' . $fid . '" type="url" name="online_url" value="' . esc_attr( $t->online_url ) . '" placeholder="https://zoom.us/…"><br><input form="' . $fid . '" name="note" value="' . esc_attr( $t->note ) . '" placeholder="' . esc_attr__( 'Note', 'olivia-studio' ) . '"></td>'
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
		$id = OYS_Schedule::save_template( array(
			'class_slug'   => wp_unslash( $_POST['class_slug'] ?? '' ),
			'weekday'      => $_POST['weekday'] ?? 1,
			'start_time'   => sanitize_text_field( $_POST['start_time'] ?? '' ),
			'duration_min' => $_POST['duration_min'] ?? 60,
			'capacity'     => $_POST['capacity'] ?? 12,
			'location'     => wp_unslash( $_POST['location'] ?? '' ),
			'online_url'   => wp_unslash( $_POST['online_url'] ?? '' ),
			'price_cents'  => oys_cents_from_input( wp_unslash( $_POST['price'] ?? '0' ) ),
			'note'         => wp_unslash( $_POST['note'] ?? '' ),
			'active'       => $_POST['active'] ?? 0,
		), (int) $_POST['id'] );
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
					. '<p>' . (int) $r->duration_min . ' min · ' . (int) $r->people . ' ' . esc_html__( 'people', 'olivia-studio' ) . ' · ' . esc_html( $types[ $r->location_type ] ?? '' ) . ' · ' . esc_html( $r->address ) . '</p>'
					. '<p><b>' . esc_html__( 'Preferred times', 'olivia-studio' ) . '</b><br>' . nl2br( esc_html( $r->preferred ) ) . '</p>'
					. '<p><b>' . esc_html__( 'Notes', 'olivia-studio' ) . '</b><br>' . nl2br( esc_html( $r->notes ) ) . '</p>'
					. '<p><b>' . esc_html__( 'Health notes on profile', 'olivia-studio' ) . '</b><br>' . nl2br( esc_html( get_user_meta( $r->user_id, 'oys_health_notes', true ) ) ) . '</p>'
					. '<p>' . esc_html__( 'Status', 'olivia-studio' ) . ': <b>' . esc_html( $st[ $r->status ] ?? $r->status ) . '</b></p></div>';
				if ( in_array( $r->status, array( 'new', 'offered' ), true ) ) {
					$s     = $r->session_id ? OYS_Schedule::get( $r->session_id ) : null;
					$price = $r->price_cents ?: OYS_Products::private_price_for( $r->duration_min, 'online' === $r->location_type );
					echo '<h2>' . ( $s ? esc_html__( 'Change the offer', 'olivia-studio' ) : esc_html__( 'Send an offer', 'olivia-studio' ) ) . '</h2>' . self::form( 'private_offer' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><table class="form-table">' // phpcs:ignore
						. '<tr><th>' . esc_html__( 'Date and time', 'olivia-studio' ) . '</th><td><input type="datetime-local" name="starts" required value="' . esc_attr( $s ? oys_utc_to_local_input( $s->starts_at ) : '' ) . '"></td></tr>'
						. '<tr><th>' . esc_html__( 'Length (min)', 'olivia-studio' ) . '</th><td><select name="duration">' . implode( '', array_map( fn( $d ) => '<option value="' . $d . '"' . selected( (int) $r->duration_min, $d, false ) . '>' . $d . '</option>', array( 60, 75, 90 ) ) ) . '</select></td></tr>'
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
			echo '<tr><td>' . esc_html( oys_date( $r->created_at, 'M j, g:i a' ) ) . '</td><td>' . self::user_label( $r->user_id ) . '</td><td>' . (int) $r->duration_min . ' min · ' . esc_html( $types[ $r->location_type ] ?? '' ) . '<br><small>' . esc_html( wp_trim_words( $r->preferred, 12 ) ) . '</small></td><td>' . ( $s ? esc_html( oys_date( $s->starts_at, 'D M j, g:i a' ) . ' · ' . oys_money( $r->price_cents ) ) : '—' ) . '</td><td><span class="oys-status oys-status--' . esc_attr( $r->status ) . '">' . esc_html( $st[ $r->status ] ?? $r->status ) . '</span></td><td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-private&request=' . $r->id ) ) . '">' . esc_html__( 'Open', 'olivia-studio' ) . '</a></td></tr>'; // phpcs:ignore
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
		$users = get_users( array( 'role__in' => array( 'oys_customer' ), 'search' => $q ? '*' . $q . '*' : '', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 300 ) );
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
			. '<p><b>' . esc_html__( 'Newsletter', 'olivia-studio' ) . ':</b> ' . ( get_user_meta( $user_id, 'oys_marketing', true ) ? esc_html__( 'yes', 'olivia-studio' ) : esc_html__( 'no', 'olivia-studio' ) ) . '</p>'
			. self::pay_later_status( $user_id ) . '</div>';

		echo '<div class="oys-box"><h2>' . esc_html__( 'Passes', 'olivia-studio' ) . '</h2><table class="widefat oys-table"><thead><tr><th>' . esc_html__( 'Pass', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Left', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Expires', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( OYS_Passes::for_user( $user_id ) as $p ) {
			echo '<tr><td>' . esc_html( $p->name ) . '<br><small>' . esc_html( $p->kind . ' · ' . $p->source ) . '</small></td><td colspan="3">' . self::form( 'adjust_pass', 'class="oys-inline"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '"><input type="hidden" name="pass" value="' . (int) $p->id . '">' // phpcs:ignore
				. '<input type="number" name="credits_left" min="0" class="small-text" value="' . (int) $p->credits_left . '"> / ' . (int) $p->credits_total . ' <input type="date" name="expires" value="' . esc_attr( $p->expires_at ? wp_date( 'Y-m-d', oys_ts( $p->expires_at ) ) : '' ) . '"> <button class="button button-small">' . esc_html__( 'Update', 'olivia-studio' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table><h3>' . esc_html__( 'Give classes', 'olivia-studio' ) . '</h3>' . self::form( 'grant_pass', 'class="oys-inline-form"' ) . '<input type="hidden" name="user" value="' . (int) $user_id . '">' // phpcs:ignore
			. '<select name="kind"><option value="class">' . esc_html__( 'Group classes', 'olivia-studio' ) . '</option><option value="private">' . esc_html__( 'Private sessions', 'olivia-studio' ) . '</option></select> '
			. '<input type="number" name="credits" min="1" value="1" class="small-text"> ' . esc_html__( 'valid for', 'olivia-studio' ) . ' <input type="number" name="days" min="1" value="90" class="small-text"> ' . esc_html__( 'days', 'olivia-studio' )
			. ' <input name="name" placeholder="' . esc_attr__( 'Label, e.g. Paid in cash', 'olivia-studio' ) . '"> <button class="button button-primary">' . esc_html__( 'Add', 'olivia-studio' ) . '</button></form></div></div>';

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
				. '<td>' . esc_html( ( $types[ $o->type ] ?? $o->type ) . ': ' . $o->description ) . '</td><td>' . esc_html( oys_money( $o->amount_cents, $o->currency ) ) . ( ! empty( $o->meta['refunded_cents'] ) ? '<br><small>' . esc_html( sprintf( __( 'refunded %s', 'olivia-studio' ), oys_money( $o->meta['refunded_cents'], $o->currency ) ) ) . '</small>' : '' ) . '</td>'
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
		$f   = fn( $k, $type = 'text', $cls = 'regular-text', $extra = '' ) => '<input type="' . $type . '" name="s[' . $k . ']" value="' . esc_attr( $s[ $k ] ) . '" class="' . $cls . '" ' . $extra . '>';
		$hook = rest_url( 'oys/v1/stripe-webhook' );
		echo self::form( 'save_settings' ) . '<h2>' . esc_html__( 'Payments (Stripe)', 'olivia-studio' ) . '</h2><table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Mode', 'olivia-studio' ) . '</th><td><select name="s[stripe_mode]"><option value="test"' . selected( $s['stripe_mode'], 'test', false ) . '>' . esc_html__( 'Test (no real charges)', 'olivia-studio' ) . '</option><option value="live"' . selected( $s['stripe_mode'], 'live', false ) . '>' . esc_html__( 'Live', 'olivia-studio' ) . '</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Test secret key', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_test_secret', 'password', 'regular-text', 'autocomplete="off" placeholder="sk_test_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Test webhook signing secret', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_test_webhook', 'password', 'regular-text', 'autocomplete="off" placeholder="whsec_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Live secret key', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_live_secret', 'password', 'regular-text', 'autocomplete="off" placeholder="sk_live_… or rk_live_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Live webhook signing secret', 'olivia-studio' ) . '</th><td>' . $f( 'stripe_live_webhook', 'password', 'regular-text', 'autocomplete="off" placeholder="whsec_…"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Webhook URL', 'olivia-studio' ) . '</th><td><code>' . esc_html( $hook ) . '</code><p class="description">' . esc_html__( 'In Stripe → Developers → Webhooks, add this URL with the events: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, charge.refunded, customer.subscription.updated, customer.subscription.deleted, invoice.paid, invoice.payment_failed. For memberships also turn on the Customer portal (Settings → Billing → Customer portal).', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Currency', 'olivia-studio' ) . '</th><td>' . $f( 'currency', 'text', 'small-text', 'maxlength="3"' ) . '</td></tr>'; // phpcs:ignore
		echo '</table><h2>' . esc_html__( 'Booking rules', 'olivia-studio' ) . '</h2><table class="form-table">';
		$nums = array(
			'cancel_hours'          => __( 'Free cancellation until (hours before a group class)', 'olivia-studio' ),
			'private_cancel_hours'  => __( 'Free cancellation until (hours before a private session)', 'olivia-studio' ),
			'dropin_credit_days'    => __( 'Credit for a cancelled drop-in is valid for (days)', 'olivia-studio' ),
			'booking_window_days'   => __( 'Show classes this many days ahead', 'olivia-studio' ),
			'booking_close_minutes' => __( 'Online booking closes (minutes before start)', 'olivia-studio' ),
			'waitlist_cutoff_hours' => __( 'Waitlist stops moving people in (hours before start)', 'olivia-studio' ),
			'weeks_ahead'           => __( 'Create weekly classes this many weeks ahead', 'olivia-studio' ),
			'hold_minutes'          => __( 'Hold a spot during card payment (minutes, 30 or more)', 'olivia-studio' ),
			'max_guests'            => __( 'Guests a customer can bring per booking (0 = off)', 'olivia-studio' ),
		);
		foreach ( $nums as $k => $l ) {
			echo '<tr><th>' . esc_html( $l ) . '</th><td>' . $f( $k, 'number', 'small-text', 'min="0"' ) . '</td></tr>'; // phpcs:ignore
		}
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
		echo '<tr><th>' . esc_html__( 'Drop-in price for an online class', 'olivia-studio' ) . '</th><td><input name="online_price" class="small-text" value="' . esc_attr( $s['online_price_cents'] / 100 ) . '"> ' . esc_html( strtoupper( $s['currency'] ) ) . '<p class="description">' . esc_html__( 'Used for new online classes in the calendar; each class can still have its own price.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'One class on a studio pass covers', 'olivia-studio' ) . '</th><td>' . $f( 'online_per_credit', 'number', 'small-text', 'min="1"' ) . ' ' . esc_html__( 'online classes', 'olivia-studio' ) . '<p class="description">' . esc_html__( 'When someone books an online class with a studio pass, one class from the pass turns into this many online classes (same expiry date); one is used and the rest stay in their account. Online passes are used first. Memberships include online classes, and they don\'t count towards a monthly class limit.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		echo '</table><h2 id="zoom">' . esc_html__( 'Zoom (live online classes)', 'olivia-studio' ) . '</h2>';
		echo '<p class="description" style="max-width:760px">' . esc_html__( 'With Zoom connected, online and hybrid classes get their Zoom meeting automatically; people joining online get the link in their emails and account, and you start the class from the calendar or the roster. In the Zoom App Marketplace create a "Server-to-Server OAuth" app, add the scopes to create, update and delete meetings and to manage registrants, activate it and copy its Account ID, Client ID and Client Secret here. A class with an online link typed in by hand keeps that link.', 'olivia-studio' ) . '</p><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Account ID', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_account_id', 'text', 'regular-text', 'autocomplete="off"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Client ID', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_client_id', 'text', 'regular-text', 'autocomplete="off"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Client Secret', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_client_secret', 'password', 'regular-text', 'autocomplete="off"' ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Meetings belong to', 'olivia-studio' ) . '</th><td>' . $f( 'zoom_host', 'text', 'regular-text', 'placeholder="me"' ) . '<p class="description">' . esc_html__( '"me" = the account owner, or the email of the Zoom user who teaches.', 'olivia-studio' ) . '</p></td></tr>'; // phpcs:ignore
		$check = fn( $k, $label ) => '<label><input type="hidden" name="s[' . $k . ']" value="0"><input type="checkbox" name="s[' . $k . ']" value="1"' . checked( 1, (int) $s[ $k ], false ) . '> ' . esc_html( $label ) . '</label>';
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
		echo '</form>' . self::form( 'zoom_test', 'id="oys-zoom-test"' ) . '</form></div>'; // phpcs:ignore
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
		echo '<tr><th>' . esc_html__( 'Sender email', 'olivia-studio' ) . '</th><td><input type="email" class="regular-text" name="s[email_from]" value="' . esc_attr( $st['email_from'] ) . '"><p class="description">' . esc_html__( 'Use an address on your own domain, and an SMTP plugin, so emails land in the inbox.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Studio notifications go to', 'olivia-studio' ) . '</th><td><input type="email" class="regular-text" name="s[notify_email]" value="' . esc_attr( $st['notify_email'] ) . '"></td></tr></table>';
		echo '<h2>' . esc_html__( 'Reminders', 'olivia-studio' ) . '</h2><p class="description">' . esc_html__( '0 turns a reminder off. They go out automatically; people who book after a reminder\'s time just get their confirmation.', 'olivia-studio' ) . '</p><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Class reminder', 'olivia-studio' ) . '</th><td>' . $num( 'reminder_hours', __( 'hours before the class', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Second reminder', 'olivia-studio' ) . '</th><td>' . $num( 'reminder2_hours', __( 'hours before the class (e.g. 2 on the day)', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Online: link to join', 'olivia-studio' ) . '</th><td>' . $num( 'join_reminder_minutes', __( 'minutes before an online or hybrid class', 'olivia-studio' ) ) . '</td></tr>'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Pass about to expire', 'olivia-studio' ) . '</th><td>' . $num( 'pass_expiry_days', __( 'days before unused classes expire', 'olivia-studio' ) ) . '</td></tr></table>'; // phpcs:ignore

		$to = array( 'customer' => __( 'Customer', 'olivia-studio' ), 'guest' => __( 'Guest / recipient', 'olivia-studio' ), 'studio' => __( 'You', 'olivia-studio' ) );
		foreach ( OYS_Email_Templates::groups() as $group => $label ) {
			echo '<h2>' . esc_html( $label ) . '</h2><table class="widefat striped oys-table oys-emails"><thead><tr><th style="width:70px">' . esc_html__( 'On', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Email', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Sent when', 'olivia-studio' ) . '</th><th>' . esc_html__( 'To', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( OYS_Email_Templates::registry() as $k => $d ) {
				if ( $group !== $d['group'] ) {
					continue;
				}
				$t = OYS_Email_Templates::get( $k );
				echo '<tr' . ( $t['enabled'] ? '' : ' class="is-off"' ) . '><td><input type="hidden" name="on[' . esc_attr( $k ) . ']" value="0"><label class="oys-switch"><input type="checkbox" name="on[' . esc_attr( $k ) . ']" value="1"' . checked( $t['enabled'], true, false ) . ' aria-label="' . esc_attr( $d['label'] ) . '"><span></span></label></td>'
					. '<td><b>' . esc_html( $d['label'] ) . '</b>' . ( $d['editable'] ? '<br><small>' . esc_html( OYS_Email_Templates::fill( $t['subject'], OYS_Emails::sample_vars() ) ) . '</small>' : '' ) . ( $t['customized'] ? ' <span class="oys-status oys-status--offered">' . esc_html__( 'edited', 'olivia-studio' ) . '</span>' : '' ) . '</td>'
					. '<td>' . esc_html( $d['when'] ) . '</td><td>' . esc_html( $to[ $d['to'] ] ) . '</td>'
					. '<td>' . ( $d['editable'] ? '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-emails&edit=' . $k ) ) . '">' . esc_html__( 'Edit text', 'olivia-studio' ) . '</a>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		submit_button( __( 'Save emails and reminders', 'olivia-studio' ) );
		echo '</form></div>';
	}

	private static function page_email_edit( $key ) {
		$t = OYS_Email_Templates::get( $key );
		self::header( sprintf( __( 'Email: %s', 'olivia-studio' ), $t['label'] ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-emails' ) ) . '">' . esc_html__( '← All emails', 'olivia-studio' ) . '</a>' );
		[ , $html ] = OYS_Emails::preview( $key );
		echo '<p>' . esc_html( $t['when'] ) . '.</p><div class="oys-email-edit"><div>';
		echo self::form( 'save_email_template' ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Send this email', 'olivia-studio' ) . '</th><td><input type="hidden" name="enabled" value="0"><label><input type="checkbox" name="enabled" value="1"' . checked( $t['enabled'], true, false ) . '> ' . esc_html__( 'On', 'olivia-studio' ) . '</label></td></tr>';
		echo '<tr><th><label for="oys-e-subject">' . esc_html__( 'Subject', 'olivia-studio' ) . '</label></th><td><input type="text" id="oys-e-subject" class="large-text" name="subject" value="' . esc_attr( $t['subject'] ) . '"></td></tr>';
		echo '<tr><th><label for="oys-e-heading">' . esc_html__( 'Heading', 'olivia-studio' ) . '</label></th><td><input type="text" id="oys-e-heading" class="large-text" name="heading" value="' . esc_attr( $t['heading'] ) . '"></td></tr>';
		echo '<tr><th><label for="oys-e-message">' . esc_html__( 'Message', 'olivia-studio' ) . '</label></th><td><textarea id="oys-e-message" class="large-text" rows="5" name="message">' . esc_textarea( $t['message'] ) . '</textarea><p class="description">' . esc_html__( 'Shown before the automatic details (class, time, place, guests, join link, receipt…). An empty line starts a new paragraph.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th><label for="oys-e-closing">' . esc_html__( 'Closing note', 'olivia-studio' ) . '</label></th><td><textarea id="oys-e-closing" class="large-text" rows="3" name="closing">' . esc_textarea( $t['closing'] ) . '</textarea><p class="description">' . esc_html__( 'After the details. Can be empty.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th><label for="oys-e-button">' . esc_html__( 'Button', 'olivia-studio' ) . '</label></th><td><input type="text" id="oys-e-button" class="regular-text" name="button" value="' . esc_attr( $t['button'] ) . '"><p class="description">' . esc_html__( 'Empty = no button.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Placeholders', 'olivia-studio' ) . '</th><td><p class="oys-vars">' . implode( ' ', array_map( fn( $v ) => '<code>{' . esc_html( $v ) . '}</code>', $t['vars'] ) ) . '</p></td></tr></table>';
		submit_button( __( 'Save', 'olivia-studio' ), 'primary', 'submit', false );
		echo '</form><p class="oys-email-actions">' . self::form( 'test_email', 'class="oys-inline"' ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><button class="button">' . esc_html( sprintf( __( 'Send a test to %s', 'olivia-studio' ), wp_get_current_user()->user_email ) ) . '</button></form> ' // phpcs:ignore
			. ( $t['customized'] ? self::form( 'reset_email_template', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Go back to the original text?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><button class="button button-link-delete">' . esc_html__( 'Reset to the original text', 'olivia-studio' ) . '</button></form>' : '' ) . '</p></div>'; // phpcs:ignore
		echo '<div class="oys-email-preview"><p class="description">' . esc_html__( 'Preview with sample details (save to update):', 'olivia-studio' ) . '</p><iframe title="' . esc_attr__( 'Email preview', 'olivia-studio' ) . '" srcdoc="' . esc_attr( $html ) . '"></iframe></div></div></div>';
	}

	private static function do_save_emails() {
		$in    = wp_unslash( $_POST['s'] ?? array() );
		$clean = array(
			'email_from_name' => sanitize_text_field( $in['email_from_name'] ?? '' ),
			'email_from'      => sanitize_email( $in['email_from'] ?? '' ),
			'notify_email'    => sanitize_email( $in['notify_email'] ?? '' ),
		);
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
		$in = wp_unslash( $_POST );
		OYS_Email_Templates::save( $key, array(
			'enabled' => empty( $in['enabled'] ) ? 0 : 1,
			'subject' => sanitize_text_field( $in['subject'] ?? '' ),
			'heading' => sanitize_text_field( $in['heading'] ?? '' ),
			'message' => sanitize_textarea_field( $in['message'] ?? '' ),
			'closing' => sanitize_textarea_field( $in['closing'] ?? '' ),
			'button'  => sanitize_text_field( $in['button'] ?? '' ),
		) );
		self::back( 'oys-emails', array( 'edit' => $key ), __( 'Saved.', 'olivia-studio' ) );
	}

	private static function do_reset_email_template() {
		$key = sanitize_key( $_POST['key'] ?? '' );
		OYS_Email_Templates::reset( $key );
		self::back( 'oys-emails', array( 'edit' => $key ), __( 'Back to the original text.', 'olivia-studio' ) );
	}

	private static function do_test_email() {
		$key = sanitize_key( $_POST['key'] ?? '' );
		$to  = wp_get_current_user()->user_email;
		$ok  = OYS_Email_Templates::get( $key ) && OYS_Emails::send_test( $key, $to );
		self::back( 'oys-emails', array( 'edit' => $key ), $ok ? sprintf( __( 'Test sent to %s.', 'olivia-studio' ), $to ) : __( 'The test could not be sent. Check the email set-up (SMTP plugin).', 'olivia-studio' ) );
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
			if ( ! isset( $in[ $k ] ) ) {
				continue;
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
		$clean['currency']    = strtolower( substr( preg_replace( '/[^a-z]/i', '', $clean['currency'] ?? 'usd' ), 0, 3 ) ) ?: 'usd';
		$clean['hold_minutes'] = max( 30, (int) ( $clean['hold_minutes'] ?? 30 ) );
		$clean['online_per_credit'] = max( 1, (int) ( $clean['online_per_credit'] ?? 4 ) );
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
		OYS_Settings::update( $clean );
		self::back( 'oys-settings', array(), __( 'Settings saved.', 'olivia-studio' ) );
	}
}
