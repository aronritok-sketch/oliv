<?php
/**
 * Teachers in wp-admin.
 *
 * Studio → Teachers (capability `oys_manage`): profiles, photos, share of the income, login,
 * Stripe connection and the monthly statements (all teachers, one teacher, CSV).
 *
 * Teaching (capability `oys_teach`, role `oys_teacher`): the teacher's own classes and rosters
 * (attendance, payments at the studio, "Message everyone"), their profile and Stripe, and their
 * statement. A teacher only ever sees classes assigned to them.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Teachers_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			if ( str_contains( $hook, 'oys-teach' ) ) {
				wp_enqueue_style( 'oys-admin', OYS_URL . 'assets/admin.css', array(), OYS_VERSION );
			}
		} );
		$studio = array( 'save', 'photo', 'access', 'connect', 'connect_check', 'disconnect', 'csv', 'settings', 'page' );
		foreach ( $studio as $a ) {
			add_action( 'admin_post_oys_teachers_' . $a, array( __CLASS__, 'guard_studio' ) );
		}
		$teach = array( 'roster', 'message', 'profile', 'photo', 'connect', 'csv' );
		foreach ( $teach as $a ) {
			add_action( 'admin_post_oys_teach_' . $a, array( __CLASS__, 'guard_teach' ) );
		}
		// Teachers land on their classes, not the WordPress dashboard.
		add_action( 'load-index.php', function () {
			if ( current_user_can( 'oys_teach' ) && ! current_user_can( 'oys_manage' ) && ! current_user_can( 'edit_posts' ) ) {
				oys_redirect( admin_url( 'admin.php?page=oys-teach' ) );
			}
		} );
	}

	public static function menu() {
		add_submenu_page( 'oys', __( 'Teachers', 'olivia-studio' ), __( 'Teachers', 'olivia-studio' ), 'oys_manage', 'oys-teachers', array( __CLASS__, 'page_teachers' ) );
		if ( current_user_can( 'oys_teach' ) ) {
			add_menu_page( __( 'Teaching', 'olivia-studio' ), __( 'Teaching', 'olivia-studio' ), 'oys_teach', 'oys-teach', array( __CLASS__, 'page_teach' ), 'dashicons-groups', 3 );
			add_submenu_page( 'oys-teach', __( 'My classes', 'olivia-studio' ), __( 'My classes', 'olivia-studio' ), 'oys_teach', 'oys-teach', array( __CLASS__, 'page_teach' ) );
			add_submenu_page( 'oys-teach', __( 'My profile', 'olivia-studio' ), __( 'My profile', 'olivia-studio' ), 'oys_teach', 'oys-teach-profile', array( __CLASS__, 'page_teach_profile' ) );
			add_submenu_page( 'oys-teach', __( 'My statement', 'olivia-studio' ), __( 'My statement', 'olivia-studio' ), 'oys_teach', 'oys-teach-statement', array( __CLASS__, 'page_teach_statement' ) );
		}
	}

	/* ---------- Guards and small helpers ---------- */

	public static function guard_studio() {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		if ( ! current_user_can( 'oys_manage' ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do this.', 'olivia-studio' ), 403 );
		}
		check_admin_referer( $action );
		call_user_func( array( __CLASS__, 'studio_' . substr( $action, strlen( 'oys_teachers_' ) ) ) );
	}

	public static function guard_teach() {
		$action = sanitize_key( $_REQUEST['action'] ?? '' );
		$t      = OYS_Teachers::current();
		if ( ! $t ) {
			wp_die( esc_html__( 'You don\'t have permission to do this.', 'olivia-studio' ), 403 );
		}
		check_admin_referer( $action );
		call_user_func( array( __CLASS__, 'teach_' . substr( $action, strlen( 'oys_teach_' ) ) ), $t );
	}

	private static function form( $action, $extra = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '><input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false );
	}

	private static function back( $page, $args = array(), $msg = '', $error = false ) {
		if ( $msg ) {
			$args[ $error ? 'oys_err' : 'oys_msg' ] = rawurlencode( $msg );
		}
		oys_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
	}

	private static function header( $title, $actions = '' ) {
		echo '<div class="wrap oys-wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<hr class="wp-header-end">'; // phpcs:ignore
		foreach ( array( 'oys_msg' => 'success', 'oys_err' => 'error' ) as $key => $type ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( wp_unslash( $_GET[ $key ] ) ) . '</p></div>';
			}
		}
	}

	private static function month_param() {
		$m = sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) );
		return preg_match( '/^\d{4}-\d{2}$/', $m ) ? $m : wp_date( 'Y-m' );
	}

	private static function month_picker( $page, array $args = array() ) {
		$cur  = self::month_param();
		$tz   = wp_timezone();
		$out  = '<form method="get" class="oys-inline-form"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		foreach ( $args as $k => $v ) {
			$out .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$out .= '<label>' . esc_html__( 'Month', 'olivia-studio' ) . ' <select name="month" onchange="this.form.submit()">';
		for ( $i = 0; $i < 13; $i++ ) {
			$d    = ( new DateTimeImmutable( 'first day of this month', $tz ) )->modify( "-$i months" );
			$out .= '<option value="' . esc_attr( $d->format( 'Y-m' ) ) . '"' . selected( $cur, $d->format( 'Y-m' ), false ) . '>' . esc_html( $d->format( 'F Y' ) ) . '</option>';
		}
		return $out . '</select></label> <noscript><button class="button">' . esc_html__( 'Show', 'olivia-studio' ) . '</button></noscript></form>';
	}

	/* ======================================================================
	   Studio → Teachers
	   ====================================================================== */

	public static function page_teachers() {
		if ( isset( $_GET['edit'] ) ) {
			self::page_edit( (int) $_GET['edit'] );
			return;
		}
		if ( isset( $_GET['statement'] ) ) {
			self::page_statement( (int) $_GET['statement'] );
			return;
		}
		self::header( __( 'Teachers', 'olivia-studio' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-teachers&edit=0' ) ) . '">' . esc_html__( 'Add a teacher', 'olivia-studio' ) . '</a>' );
		echo '<p class="description oys-lead">' . esc_html__( 'Teachers get a profile on the website, their name on their classes, a login where they see their rosters and write to their students, and a monthly statement. If they connect their own Stripe account, card payments for their classes go straight to them and your part comes to you automatically, so you never take in (or invoice) their money.', 'olivia-studio' ) . '</p>';
		$list = OYS_Teachers::all();
		if ( ! $list ) {
			echo '<p>' . esc_html__( 'No teachers yet. Classes without a teacher are yours.', 'olivia-studio' ) . '</p>';
		} else {
			echo '<table class="widefat striped oys-table"><thead><tr><th></th><th>' . esc_html__( 'Teacher', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Their share', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Next 30 days', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Login', 'olivia-studio' ) . '</th><th>Stripe</th><th></th></tr></thead><tbody>';
			foreach ( $list as $t ) {
				$n   = count( OYS_Schedule::query( array( 'teacher' => $t->id, 'from' => oys_now(), 'to' => oys_utc_plus( 30 * DAY_IN_SECONDS ), 'status' => 'scheduled' ) ) );
				$img = OYS_Teachers::photo( $t, 'thumbnail' );
				echo '<tr' . ( $t->active ? '' : ' class="is-cancelled"' ) . '><td style="width:48px">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="" width="40" height="40" style="border-radius:50%;object-fit:cover">' : '' ) . '</td>'
					. '<td><b>' . esc_html( $t->name ) . '</b>' . ( $t->active ? '' : ' <small>(' . esc_html__( 'hidden', 'olivia-studio' ) . ')</small>' ) . '<br><small>' . esc_html( $t->headline ?: $t->email ) . '</small></td>'
					. '<td>' . (int) $t->share_percent . '%</td><td>' . esc_html( sprintf( _n( '%d class', '%d classes', $n, 'olivia-studio' ), $n ) ) . '</td>'
					. '<td>' . ( $t->user_id ? '✓' : '—' ) . '</td><td>' . esc_html( OYS_Connect::status_label( $t ) ) . '</td>'
					. '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-teachers&edit=' . $t->id ) ) . '">' . esc_html__( 'Edit', 'olivia-studio' ) . '</a> <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-teachers&statement=' . $t->id ) ) . '">' . esc_html__( 'Statement', 'olivia-studio' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
			self::summary();
		}

		echo '<h2>' . esc_html__( 'Settings', 'olivia-studio' ) . '</h2>' . self::form( 'oys_teachers_settings', 'class="oys-form"' ) . '<table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Your name on your own classes', 'olivia-studio' ) . '</th><td><input name="owner_name" value="' . esc_attr( OYS_Settings::get( 'owner_name' ) ) . '" class="regular-text"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Share for new teachers', 'olivia-studio' ) . '</th><td><input type="number" name="teacher_share_default" min="0" max="100" value="' . (int) OYS_Settings::get( 'teacher_share_default' ) . '" class="small-text"> % <p class="description">' . esc_html__( 'The part of the class income that goes to the teacher. Each teacher can have their own.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Value of a membership visit', 'olivia-studio' ) . '</th><td><input name="settle_membership" value="' . esc_attr( OYS_Settings::get( 'settle_membership_cents' ) / 100 ) . '" class="small-text"> ' . esc_html( strtoupper( OYS_Settings::get( 'currency' ) ) ) . ' <p class="description">' . esc_html__( 'Memberships are unlimited, so for the statement a member\'s visit counts as this amount.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Stripe Connect webhook secret', 'olivia-studio' ) . '</th><td><input name="connect_webhook" value="' . esc_attr( OYS_Settings::is_live() ? OYS_Settings::get( 'stripe_live_connect_webhook' ) : OYS_Settings::get( 'stripe_test_connect_webhook' ) ) . '" class="regular-text" autocomplete="off" placeholder="whsec_…"> <small>(' . esc_html( OYS_Settings::is_live() ? 'live' : 'test' ) . ')</small>'
			. '<p class="description">' . esc_html( sprintf( __( 'In Stripe → Developers → Webhooks, add an endpoint that listens to events on connected accounts, with this address: %s — events: checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, charge.refunded, account.updated.', 'olivia-studio' ), rest_url( 'oys/v1/stripe-webhook' ) ) ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Save settings', 'olivia-studio' ) );
		echo '</form>';

		$page = self::teachers_page();
		echo '<h2>' . esc_html__( 'Teachers page', 'olivia-studio' ) . '</h2>';
		if ( $page ) {
			echo '<p>' . esc_html__( 'The teachers are shown on', 'olivia-studio' ) . ' <a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>. ' . esc_html__( 'Add it to the menu in Appearance → Menus if it isn\'t there yet.', 'olivia-studio' ) . '</p>';
		} else {
			echo self::form( 'oys_teachers_page' ) . '<p>' . esc_html__( 'Show the teachers on the website: this creates a "Teachers" page with everyone\'s photos, bio and next classes (shortcode [oys_teachers]).', 'olivia-studio' ) . '</p><p><button class="button">' . esc_html__( 'Create the Teachers page', 'olivia-studio' ) . '</button></p></form>'; // phpcs:ignore
		}
		echo '</div>';
	}

	private static function teachers_page() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[oys_teachers%' LIMIT 1" );
	}

	/** This month's balance per teacher. */
	private static function summary() {
		$month = self::month_param();
		echo '<h2>' . esc_html__( 'Statements', 'olivia-studio' ) . '</h2>' . self::month_picker( 'oys-teachers' ); // phpcs:ignore
		echo '<table class="widefat striped oys-table oys-statement-summary"><thead><tr><th>' . esc_html__( 'Teacher', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Classes', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Class income', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Paid to their Stripe', 'olivia-studio' ) . '</th><th>' . esc_html__( 'You owe them', 'olivia-studio' ) . '</th><th>' . esc_html__( 'They owe you', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Balance', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( OYS_Teachers::all() as $t ) {
			$st  = OYS_Connect::statement( $t->id, $month );
			$tot = $st['totals'];
			if ( ! $st['classes'] && ! $t->active ) {
				continue;
			}
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=oys-teachers&statement=' . $t->id . '&month=' . $month ) ) . '">' . esc_html( $t->name ) . '</a></td><td>' . (int) $st['classes'] . '</td><td>' . esc_html( oys_money( $tot['value'] ) ) . '</td><td>' . esc_html( oys_money( $tot['direct'] ) ) . '</td><td>' . esc_html( oys_money( $tot['studio_owes'] ) ) . '</td><td>' . esc_html( oys_money( $tot['teacher_owes'] ) ) . '</td><td><b>' . esc_html( self::balance_text( $tot['balance'] ) ) . '</b></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function balance_text( $cents, $for_teacher = false ) {
		if ( ! $cents ) {
			return __( 'Settled', 'olivia-studio' );
		}
		if ( $for_teacher ) {
			return $cents > 0 ? sprintf( __( 'The studio pays you %s', 'olivia-studio' ), oys_money( $cents ) ) : sprintf( __( 'You pay the studio %s', 'olivia-studio' ), oys_money( -$cents ) );
		}
		return $cents > 0 ? sprintf( __( 'You pay %s', 'olivia-studio' ), oys_money( $cents ) ) : sprintf( __( 'They pay you %s', 'olivia-studio' ), oys_money( -$cents ) );
	}

	private static function page_edit( $id ) {
		$t = $id ? OYS_Teachers::get( $id ) : null;
		if ( $t && ! empty( $_GET['oys_connect'] ) ) {
			$ready = OYS_Connect::refresh( $t->id );
			$t     = OYS_Teachers::get( $id );
			$_GET['oys_msg'] = true === $ready ? __( 'Stripe is connected: card payments for their classes now go to their account.', 'olivia-studio' ) : __( 'Stripe isn\'t finished yet. They can continue any time from their Teaching → My profile page.', 'olivia-studio' );
		}
		self::header( $t ? $t->name : __( 'Add a teacher', 'olivia-studio' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-teachers' ) ) . '">' . esc_html__( 'All teachers', 'olivia-studio' ) . '</a>' );
		$v = $t ?: (object) array( 'name' => '', 'email' => '', 'headline' => '', 'bio' => '', 'share_percent' => OYS_Settings::get( 'teacher_share_default' ), 'cash_by' => 'teacher', 'notify' => 1, 'active' => 1, 'sort' => 0 );
		echo self::form( 'oys_teachers_save', 'class="oys-form"' ) . '<input type="hidden" name="id" value="' . (int) $id . '"><table class="form-table">'; // phpcs:ignore
		echo '<tr><th><label for="t-name">' . esc_html__( 'Name', 'olivia-studio' ) . '</label></th><td><input id="t-name" name="name" required class="regular-text" value="' . esc_attr( $v->name ) . '"></td></tr>';
		echo '<tr><th><label for="t-email">' . esc_html__( 'Email', 'olivia-studio' ) . '</label></th><td><input id="t-email" type="email" name="email" class="regular-text" value="' . esc_attr( $v->email ) . '"><p class="description">' . esc_html__( 'For their login and booking notices. Not shown on the website.', 'olivia-studio' ) . '</p></td></tr>';
		echo '<tr><th><label for="t-headline">' . esc_html__( 'Headline', 'olivia-studio' ) . '</label></th><td><input id="t-headline" name="headline" class="large-text" value="' . esc_attr( $v->headline ) . '" placeholder="Yin, restorative and breathwork"></td></tr>';
		echo '<tr><th><label for="t-bio">' . esc_html__( 'About', 'olivia-studio' ) . '</label></th><td><textarea id="t-bio" name="bio" rows="7" class="large-text">' . esc_textarea( (string) $v->bio ) . '</textarea></td></tr>';
		echo '<tr><th><label for="t-share">' . esc_html__( 'Their share', 'olivia-studio' ) . '</label></th><td><input id="t-share" type="number" name="share_percent" min="0" max="100" class="small-text" value="' . (int) $v->share_percent . '"> % ' . esc_html__( 'of the income of their classes', 'olivia-studio' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Payments at the studio', 'olivia-studio' ) . '</th><td><label><input type="radio" name="cash_by" value="teacher"' . checked( $v->cash_by, 'teacher', false ) . '> ' . esc_html__( 'The teacher collects them at their classes (and pays your part in the statement)', 'olivia-studio' ) . '</label><br><label><input type="radio" name="cash_by" value="studio"' . checked( $v->cash_by, 'studio', false ) . '> ' . esc_html__( 'You collect them', 'olivia-studio' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Options', 'olivia-studio' ) . '</th><td><label><input type="checkbox" name="notify" value="1"' . checked( 1, (int) $v->notify, false ) . '> ' . esc_html__( 'Email them about bookings, cancellations and go/no-go decisions of their classes', 'olivia-studio' ) . '</label><br><label><input type="checkbox" name="active" value="1"' . checked( 1, (int) $v->active, false ) . '> ' . esc_html__( 'Show on the website and in the calendar', 'olivia-studio' ) . '</label></td></tr>';
		echo '<tr><th><label for="t-sort">' . esc_html__( 'Order', 'olivia-studio' ) . '</label></th><td><input id="t-sort" type="number" name="sort" class="small-text" value="' . (int) $v->sort . '"></td></tr>';
		echo '</table>';
		submit_button( $t ? __( 'Save teacher', 'olivia-studio' ) : __( 'Add teacher', 'olivia-studio' ) );
		echo '</form>';
		if ( ! $t ) {
			echo '</div>';
			return;
		}
		self::photos_box( $t, 'oys_teachers_photo' );

		echo '<h2>' . esc_html__( 'Login', 'olivia-studio' ) . '</h2>';
		if ( $t->user_id && get_userdata( $t->user_id ) ) {
			$u = get_userdata( $t->user_id );
			echo '<p>' . sprintf( esc_html__( 'They log in as %s and see Teaching in the dashboard: their classes, rosters, messages, profile and statement.', 'olivia-studio' ), '<b>' . esc_html( $u->user_email ) . '</b>' ) . '</p>'
				. self::form( 'oys_teachers_access', 'class="oys-inline"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button name="do" value="resend" class="button">' . esc_html__( 'Send the login email again', 'olivia-studio' ) . '</button> <button name="do" value="remove" class="button button-link-delete">' . esc_html__( 'Remove their login', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
		} else {
			echo '<p>' . esc_html__( 'With a login they can see who is booked into their classes and write to them. They get an email to set their password.', 'olivia-studio' ) . '</p>'
				. self::form( 'oys_teachers_access' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button name="do" value="give" class="button button-primary">' . esc_html__( 'Give them a login', 'olivia-studio' ) . '</button></form>'; // phpcs:ignore
		}

		echo '<h2>' . esc_html__( 'Card payments (Stripe)', 'olivia-studio' ) . '</h2><p><b>' . esc_html( OYS_Connect::status_label( $t ) ) . '</b></p>';
		echo '<p class="description">' . esc_html( sprintf( __( 'When connected, a drop-in or donation paid by card for their class is charged on their own Stripe account; your %d%% comes to your Stripe as a fee. Passes and memberships are always paid to you and settled in the statement. They can connect from their own login too.', 'olivia-studio' ), 100 - (int) $t->share_percent ) ) . '</p>';
		echo self::form( 'oys_teachers_connect', 'class="oys-inline"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="button">' . esc_html( OYS_Connect::ready( $t ) ? __( 'Open their Stripe setup', 'olivia-studio' ) : __( 'Set up Stripe with them now', 'olivia-studio' ) ) . '</button></form> '; // phpcs:ignore
		if ( $t->stripe_account ) {
			echo self::form( 'oys_teachers_connect_check', 'class="oys-inline"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="button">' . esc_html__( 'Check status', 'olivia-studio' ) . '</button></form> ' // phpcs:ignore
				. self::form( 'oys_teachers_disconnect', 'class="oys-inline" onsubmit="return confirm(\'' . esc_js( __( 'Card payments for their classes will go to your account again. Continue?', 'olivia-studio' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="button button-link-delete">' . esc_html__( 'Disconnect', 'olivia-studio' ) . '</button></form>'
				. '<p class="description">' . esc_html__( 'Account', 'olivia-studio' ) . ' <code>' . esc_html( $t->stripe_account ) . '</code></p>';
		}
		echo '</div>';
	}

	/** Photos with remove / make main buttons, and an upload form. */
	private static function photos_box( $t, $action ) {
		echo '<h2>' . esc_html__( 'Photos', 'olivia-studio' ) . '</h2><div class="oys-photos">';
		foreach ( OYS_Teachers::photo_ids( $t ) as $i => $pid ) {
			$url = wp_get_attachment_image_url( $pid, 'medium' );
			if ( ! $url ) {
				continue;
			}
			echo '<figure class="oys-photo"><img src="' . esc_url( $url ) . '" alt="">' . self::form( $action, 'class="oys-inline"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><input type="hidden" name="photo" value="' . (int) $pid . '">' // phpcs:ignore
				. ( $i ? '<button name="do" value="main" class="button button-small">' . esc_html__( 'Make main', 'olivia-studio' ) . '</button> ' : '<span class="oys-pill">' . esc_html__( 'Main photo', 'olivia-studio' ) . '</span> ' )
				. '<button name="do" value="remove" class="button button-small button-link-delete">' . esc_html__( 'Remove', 'olivia-studio' ) . '</button></form></figure>';
		}
		echo '</div>' . self::form( $action, 'enctype="multipart/form-data" class="oys-inline-form"' ) . '<input type="hidden" name="id" value="' . (int) $t->id . '"><input type="hidden" name="do" value="add">' // phpcs:ignore
			. '<input type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required> <button class="button">' . esc_html__( 'Upload photos', 'olivia-studio' ) . '</button></form>'
			. '<p class="description">' . esc_html__( 'The first photo is the main one (on class pages and in the app). Up to 8.', 'olivia-studio' ) . '</p>';
	}

	private static function page_statement( $id ) {
		$t = OYS_Teachers::get( $id );
		if ( ! $t ) {
			self::header( __( 'Teacher not found', 'olivia-studio' ) );
			echo '</div>';
			return;
		}
		$st = OYS_Connect::statement( $t->id, self::month_param() );
		self::header( sprintf( __( 'Statement: %1$s, %2$s', 'olivia-studio' ), $t->name, $st['label'] ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-teachers' ) ) . '">' . esc_html__( 'All teachers', 'olivia-studio' ) . '</a>' );
		echo self::month_picker( 'oys-teachers', array( 'statement' => $t->id ) ); // phpcs:ignore
		self::statement_html( $st, false, wp_nonce_url( admin_url( 'admin-post.php?action=oys_teachers_csv&id=' . $t->id . '&month=' . $st['month'] ), 'oys_teachers_csv' ) );
		echo '</div>';
	}

	/** The statement table and totals (studio view, or the teacher's own). */
	private static function statement_html( array $st, $for_teacher, $csv_url ) {
		$t   = $st['teacher'];
		$tot = $st['totals'];
		$how = OYS_Connect::how_labels();
		echo '<div class="oys-kpis">';
		$kpis = array(
			array( __( 'Classes', 'olivia-studio' ), (int) $st['classes'] ),
			array( __( 'Class income', 'olivia-studio' ), oys_money( $tot['value'] ) ),
			array( sprintf( __( 'Teacher\'s part (%d%%)', 'olivia-studio' ), (int) $t->share_percent ), oys_money( $tot['teacher'] ) ),
			array( __( 'Balance', 'olivia-studio' ), self::balance_text( $tot['balance'], $for_teacher ) ),
		);
		foreach ( $kpis as $k ) {
			echo '<div class="oys-kpi"><span>' . esc_html( $k[0] ) . '</span><b>' . esc_html( $k[1] ) . '</b></div>';
		}
		echo '</div><ul class="oys-plain">'
			. '<li>' . esc_html( sprintf( __( 'Paid by card straight to the teacher\'s Stripe: %1$s (the studio\'s %2$s was taken as a fee automatically, nothing to settle).', 'olivia-studio' ), oys_money( $tot['direct'] ), oys_money( $tot['direct_fee'] ) ) ) . '</li>'
			. '<li>' . esc_html( sprintf( __( 'The studio owes the teacher: %s (their part of passes, memberships and card payments the studio received).', 'olivia-studio' ), oys_money( $tot['studio_owes'] ) ) ) . '</li>'
			. '<li>' . esc_html( sprintf( __( 'The teacher owes the studio: %s (the studio\'s part of payments the teacher collected at class).', 'olivia-studio' ), oys_money( $tot['teacher_owes'] ) ) ) . '</li>'
			. ( $tot['uncollected'] ? '<li>' . esc_html( sprintf( __( 'Still to collect at the studio: %s (counted once marked as paid).', 'olivia-studio' ), oys_money( $tot['uncollected'] ) ) ) . '</li>' : '' )
			. '</ul>';
		if ( ! $st['rows'] ) {
			echo '<p>' . esc_html__( 'No finished classes with bookings this month.', 'olivia-studio' ) . '</p>';
			return;
		}
		echo '<p><a class="button" href="' . esc_url( $csv_url ) . '">' . esc_html__( 'Download CSV', 'olivia-studio' ) . '</a></p>';
		echo '<table class="widefat striped oys-table oys-statement"><thead><tr><th>' . esc_html__( 'Class', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Person', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Paid', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Value', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Teacher', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Studio', 'olivia-studio' ) . '</th><th>' . esc_html__( 'To settle', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		$st_labels = OYS_Bookings::statuses();
		foreach ( $st['rows'] as $r ) {
			$settle = $r['owed'] ? ( $r['owed'] > 0 ? '→ ' . oys_money( $r['owed'] ) : '← ' . oys_money( -$r['owed'] ) ) : '—';
			echo '<tr><td>' . esc_html( $r['date'] ) . '<br><small>' . esc_html( $r['class'] ) . '</small></td><td>' . esc_html( $r['person'] ) . ( 'attended' !== $r['status'] ? ' <small>(' . esc_html( $st_labels[ $r['status'] ] ?? $r['status'] ) . ')</small>' : '' ) . '</td><td>' . esc_html( $how[ $r['how'] ] ?? $r['how'] ) . '</td><td>' . esc_html( oys_money( $r['value'] ) ) . '</td><td>' . esc_html( oys_money( $r['teacher_part'] ) ) . '</td><td>' . esc_html( oys_money( $r['studio_part'] ) ) . '</td><td>' . esc_html( $settle ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( '→ the studio pays the teacher · ← the teacher pays the studio. A pass class is worth what the customer paid per class; free passes and gifts from the studio count at the class price.', 'olivia-studio' ) . '</p>';
	}

	private static function send_csv( array $st ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="statement-' . sanitize_file_name( $st['teacher']->slug . '-' . $st['month'] ) . '.csv"' );
		echo OYS_Connect::csv( $st ); // phpcs:ignore
		exit;
	}

	/* ---------- Studio actions ---------- */

	private static function studio_save() {
		$id  = (int) $_POST['id'];
		$res = OYS_Teachers::save( array(
			'name'          => wp_unslash( $_POST['name'] ?? '' ),
			'email'         => wp_unslash( $_POST['email'] ?? '' ),
			'headline'      => wp_unslash( $_POST['headline'] ?? '' ),
			'bio'           => wp_unslash( $_POST['bio'] ?? '' ),
			'share_percent' => (int) ( $_POST['share_percent'] ?? 50 ),
			'cash_by'       => sanitize_key( $_POST['cash_by'] ?? 'teacher' ),
			'notify'        => ! empty( $_POST['notify'] ),
			'active'        => ! empty( $_POST['active'] ),
			'sort'          => (int) ( $_POST['sort'] ?? 0 ),
		), $id );
		if ( is_wp_error( $res ) ) {
			self::back( 'oys-teachers', array( 'edit' => $id ), $res->get_error_message(), true );
		}
		self::back( 'oys-teachers', array( 'edit' => $res ), __( 'Teacher saved.', 'olivia-studio' ) );
	}

	private static function photo_action( $id, $page, array $args ) {
		$do = sanitize_key( $_POST['do'] ?? '' );
		if ( 'add' === $do ) {
			$res = OYS_Teachers::add_photos( $id, $_FILES['photos'] ?? array() ); // phpcs:ignore
			self::back( $page, $args, is_wp_error( $res ) ? $res->get_error_message() : sprintf( _n( '%d photo added.', '%d photos added.', $res, 'olivia-studio' ), $res ), is_wp_error( $res ) );
		}
		if ( 'remove' === $do ) {
			OYS_Teachers::remove_photo( $id, (int) $_POST['photo'] );
		}
		if ( 'main' === $do ) {
			OYS_Teachers::main_photo( $id, (int) $_POST['photo'] );
		}
		self::back( $page, $args, __( 'Photos updated.', 'olivia-studio' ) );
	}

	private static function studio_photo() {
		$id = (int) $_POST['id'];
		self::photo_action( $id, 'oys-teachers', array( 'edit' => $id ) );
	}

	private static function studio_access() {
		$id = (int) $_POST['id'];
		if ( 'remove' === ( $_POST['do'] ?? '' ) ) {
			OYS_Teachers::remove_access( $id );
			self::back( 'oys-teachers', array( 'edit' => $id ), __( 'Login removed. Their classes and statements stay.', 'olivia-studio' ) );
		}
		$res = OYS_Teachers::give_access( $id );
		self::back( 'oys-teachers', array( 'edit' => $id ), is_wp_error( $res ) ? $res->get_error_message() : __( 'Done: they got an email to set their password.', 'olivia-studio' ), is_wp_error( $res ) );
	}

	private static function studio_connect() {
		$id  = (int) $_POST['id'];
		$url = OYS_Connect::onboarding_url( $id, admin_url( 'admin.php?page=oys-teachers&edit=' . $id ) );
		if ( is_wp_error( $url ) ) {
			self::back( 'oys-teachers', array( 'edit' => $id ), $url->get_error_message(), true );
		}
		wp_redirect( $url ); // phpcs:ignore -- Stripe's hosted onboarding.
		exit;
	}

	private static function studio_connect_check() {
		$id  = (int) $_POST['id'];
		$res = OYS_Connect::refresh( $id );
		self::back( 'oys-teachers', array( 'edit' => $id ), is_wp_error( $res ) ? $res->get_error_message() : ( $res ? __( 'Stripe is connected: card payments for their classes go to their account.', 'olivia-studio' ) : __( 'Stripe isn\'t finished yet.', 'olivia-studio' ) ), is_wp_error( $res ) );
	}

	private static function studio_disconnect() {
		$id = (int) $_POST['id'];
		OYS_Connect::disconnect( $id );
		self::back( 'oys-teachers', array( 'edit' => $id ), __( 'Disconnected: card payments for their classes go to your account again.', 'olivia-studio' ) );
	}

	private static function studio_csv() {
		self::send_csv( OYS_Connect::statement( (int) $_GET['id'], sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) ) );
	}

	private static function studio_settings() {
		$key = OYS_Settings::is_live() ? 'stripe_live_connect_webhook' : 'stripe_test_connect_webhook';
		OYS_Settings::update( array(
			'owner_name'              => sanitize_text_field( wp_unslash( $_POST['owner_name'] ?? '' ) ) ?: 'Olivia',
			'teacher_share_default'   => min( 100, max( 0, (int) $_POST['teacher_share_default'] ) ),
			'settle_membership_cents' => oys_cents_from_input( wp_unslash( $_POST['settle_membership'] ?? '0' ) ),
			$key                      => sanitize_text_field( wp_unslash( $_POST['connect_webhook'] ?? '' ) ),
		) );
		self::back( 'oys-teachers', array(), __( 'Settings saved.', 'olivia-studio' ) );
	}

	private static function studio_page() {
		if ( ! self::teachers_page() ) {
			wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => __( 'Teachers', 'olivia-studio' ), 'post_name' => 'teachers', 'post_content' => "<p>Meet the teachers who share the mat with us.</p>\n[oys_teachers]" ) );
		}
		self::back( 'oys-teachers', array(), __( 'The Teachers page is ready.', 'olivia-studio' ) );
	}

	/* ======================================================================
	   Teaching (the teacher's own area)
	   ====================================================================== */

	public static function page_teach() {
		$t = OYS_Teachers::current();
		if ( ! $t ) {
			self::header( __( 'Teaching', 'olivia-studio' ) );
			echo '<p>' . esc_html__( 'Your login isn\'t linked to a teacher profile yet. Please ask the studio.', 'olivia-studio' ) . '</p></div>';
			return;
		}
		if ( isset( $_GET['session'] ) ) {
			self::teach_roster_page( $t, (int) $_GET['session'] );
			return;
		}
		self::header( sprintf( __( 'Hi %s', 'olivia-studio' ), strtok( $t->name, ' ' ) ) );
		$next = OYS_Schedule::query( array( 'teacher' => $t->id, 'from' => oys_utc_plus( -3 * HOUR_IN_SECONDS ), 'to' => oys_utc_plus( 60 * DAY_IN_SECONDS ) ) );
		echo '<h2>' . esc_html__( 'Your next classes', 'olivia-studio' ) . '</h2>';
		self::teach_table( $next, __( 'No classes assigned to you yet. The studio adds you to classes in its calendar.', 'olivia-studio' ) );
		$past = OYS_Schedule::query( array( 'teacher' => $t->id, 'from' => oys_utc_plus( -45 * DAY_IN_SECONDS ), 'to' => oys_utc_plus( -3 * HOUR_IN_SECONDS ), 'desc' => true ) );
		if ( $past ) {
			echo '<h2>' . esc_html__( 'Recent classes', 'olivia-studio' ) . '</h2>';
			self::teach_table( $past, '' );
		}
		echo '</div>';
	}

	private static function teach_table( array $sessions, $empty ) {
		if ( ! $sessions ) {
			echo '<p>' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<table class="widefat striped oys-table oys-teach-classes"><thead><tr><th>' . esc_html__( 'When', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Class', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Booked', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $sessions as $s ) {
			[ $min ] = OYS_Locations::rule( $s );
			$state   = 'cancelled' === $s->status ? __( 'Cancelled', 'olivia-studio' ) : ( 1 === (int) $s->min_state ? __( 'Confirmed', 'olivia-studio' ) : ( $min ? sprintf( __( 'Needs %d to go ahead', 'olivia-studio' ), $min ) : __( 'Scheduled', 'olivia-studio' ) ) );
			echo '<tr' . ( 'cancelled' === $s->status ? ' class="is-cancelled"' : '' ) . '><td>' . esc_html( oys_date( $s->starts_at, 'D M j · g:i a' ) ) . '</td><td><b>' . esc_html( oys_session_title( $s ) ) . '</b><br><small>' . esc_html( oys_is_online( $s ) ? __( 'Online', 'olivia-studio' ) : $s->location ) . '</small></td>'
				. '<td>' . (int) $s->booked . '/' . (int) $s->capacity . ( oys_is_hybrid( $s ) ? ' + ' . (int) $s->online_booked . ' ' . esc_html__( 'online', 'olivia-studio' ) : '' ) . '</td><td>' . esc_html( $state ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-teach&session=' . $s->id ) ) . '">' . esc_html__( 'Roster', 'olivia-studio' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function teach_roster_page( $t, $id ) {
		$s = OYS_Schedule::get( $id );
		if ( ! OYS_Teachers::owns( $t, $s ) ) {
			self::header( __( 'Class not found', 'olivia-studio' ) );
			echo '<p>' . esc_html__( 'This class isn\'t one of yours.', 'olivia-studio' ) . '</p></div>';
			return;
		}
		self::header( oys_session_title( $s ) . ' · ' . oys_date( $s->starts_at, 'D M j, g:i a' ), ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-teach' ) ) . '">' . esc_html__( 'All my classes', 'olivia-studio' ) . '</a>' );
		$due = OYS_Bookings::due_at_studio( $id );
		echo '<p>' . esc_html( oys_is_online( $s ) ? __( 'Online', 'olivia-studio' ) : $s->location ) . ' · ' . sprintf( esc_html__( '%1$d of %2$d booked', 'olivia-studio' ), (int) $s->booked, (int) $s->capacity )
			. ( $due ? ' · <b>' . sprintf( esc_html__( 'To collect at the studio: %s', 'olivia-studio' ), esc_html( oys_money( $due ) ) ) . '</b>' : '' ) . '</p>';
		if ( oys_has_online( $s ) && 'scheduled' === $s->status ) {
			$link = $s->online_url ?: $s->zoom_join_url;
			if ( $link ) {
				echo '<p>' . esc_html__( 'Online link:', 'olivia-studio' ) . ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $link ) . '</a></p>';
			}
		}
		$bookings = OYS_Bookings::for_session( $id, array( 'confirmed', 'attended', 'no_show' ) );
		$labels   = OYS_Bookings::paid_with_labels();
		echo '<table class="widefat striped oys-table oys-teach-roster"><thead><tr><th>' . esc_html__( 'Name', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Paid with', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Health notes', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Attendance', 'olivia-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $bookings as $b ) {
			$name = $b->guest_of ? '<span class="oys-guest-tag">' . esc_html__( 'Guest', 'olivia-studio' ) . '</span> ' . esc_html( OYS_Bookings::person_label( $b ) ) : '<b>' . esc_html( OYS_Bookings::person_label( $b ) ) . '</b>';
			if ( ! $b->guest_of && self::first_visit( $b ) ) {
				$name .= ' <span class="oys-pill">' . esc_html__( 'First class', 'olivia-studio' ) . '</span>';
			}
			if ( oys_is_hybrid( $s ) && 'online' === $b->mode ) {
				$name .= ' <span class="oys-online-tag">' . esc_html__( 'Online', 'olivia-studio' ) . '</span>';
			}
			$paid = esc_html( $labels[ $b->paid_with ] ?? $b->paid_with );
			if ( 'door' === $b->paid_with ) {
				$paid = $b->collected_with ? esc_html( sprintf( __( 'Paid at the studio · %s', 'olivia-studio' ), oys_money( (int) $b->due_cents ) ) ) : '<b class="oys-due-tag">' . esc_html( sprintf( __( 'Pays at the studio: %s', 'olivia-studio' ), oys_money( (int) $b->due_cents ) ) ) . '</b><br>'
					. self::form( 'oys_teach_roster', 'class="oys-inline"' ) . '<input type="hidden" name="session" value="' . (int) $id . '"><input type="hidden" name="booking" value="' . (int) $b->id . '"><button name="do" value="collect_cash" class="button button-small">' . esc_html__( 'Paid: cash', 'olivia-studio' ) . '</button> <button name="do" value="collect_other" class="button button-small">' . esc_html__( 'Paid: other', 'olivia-studio' ) . '</button></form>';
			}
			echo '<tr><td>' . $name . '</td><td>' . $paid . '</td><td class="oys-health">' . esc_html( $b->guest_of ? '' : get_user_meta( $b->user_id, 'oys_health_notes', true ) ) . '</td><td>' // phpcs:ignore
				. self::form( 'oys_teach_roster', 'class="oys-inline"' ) . '<input type="hidden" name="session" value="' . (int) $id . '"><input type="hidden" name="booking" value="' . (int) $b->id . '">' // phpcs:ignore
				. '<button name="do" value="attended" class="button button-small' . ( 'attended' === $b->status ? ' button-primary' : '' ) . '">' . esc_html__( 'Here', 'olivia-studio' ) . '</button> '
				. '<button name="do" value="no_show" class="button button-small' . ( 'no_show' === $b->status ? ' button-primary' : '' ) . '">' . esc_html__( 'No-show', 'olivia-studio' ) . '</button></form></td></tr>';
		}
		if ( ! $bookings ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No bookings yet.', 'olivia-studio' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2 id="message">' . esc_html__( 'Message everyone booked', 'olivia-studio' ) . '</h2>';
		if ( 'scheduled' === $s->status && oys_ts( $s->ends_at ) > time() - DAY_IN_SECONDS ) {
			echo self::form( 'oys_teach_message', 'class="oys-form oys-message"' ) . '<input type="hidden" name="session" value="' . (int) $id . '">' // phpcs:ignore
				. '<p><label>' . esc_html__( 'Subject', 'olivia-studio' ) . '<br><input class="large-text" name="subject" required value="' . esc_attr( sprintf( __( 'About %1$s on %2$s', 'olivia-studio' ), '{class}', '{day} {date_short}' ) ) . '"></label></p>'
				. '<p><label>' . esc_html__( 'Message', 'olivia-studio' ) . '<br><textarea class="large-text" name="body" rows="5" required placeholder="' . esc_attr__( 'Hi {first_name}, …', 'olivia-studio' ) . '"></textarea></label></p>'
				. '<p class="description">' . esc_html( sprintf( __( 'Everyone gets their own email from the studio with the class details; replies come to you (%s).', 'olivia-studio' ), $t->email ) ) . '</p>'
				. '<p><label><input type="checkbox" name="guests" value="1" checked> ' . esc_html__( 'Also guests who gave an email', 'olivia-studio' ) . '</label></p>'
				. '<p><button class="button button-primary">' . esc_html__( 'Send the message', 'olivia-studio' ) . '</button></p></form>';
		}
		$sent = OYS_Messages::for_session( $id );
		if ( $sent ) {
			echo '<ul class="oys-plain oys-sent">';
			foreach ( $sent as $m ) {
				echo '<li><b>' . esc_html( $m->subject ) . '</b> · ' . esc_html( oys_date( $m->created_at, 'M j, g:i a' ) ) . ' · ' . esc_html( sprintf( _n( '%d person', '%d people', (int) $m->recipients, 'olivia-studio' ), (int) $m->recipients ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/** First time at the studio: no earlier class attended or booked before this one. */
	private static function first_visit( $b ) {
		global $wpdb;
		return ! $wpdb->get_var( $wpdb->prepare(
			'SELECT b.id FROM ' . OYS_Install::table( 'bookings' ) . ' b JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = b.session_id WHERE b.user_id = %d AND b.guest_of = 0 AND b.id <> %d AND b.status IN ('confirmed','attended','no_show') AND s.starts_at < ( SELECT starts_at FROM " . OYS_Install::table( 'sessions' ) . ' WHERE id = %d ) LIMIT 1',
			$b->user_id, $b->id, $b->session_id
		) );
	}

	public static function page_teach_profile() {
		$t = OYS_Teachers::current();
		if ( ! $t ) {
			self::header( __( 'My profile', 'olivia-studio' ) );
			echo '</div>';
			return;
		}
		if ( ! empty( $_GET['oys_connect'] ) ) {
			$ready = OYS_Connect::refresh( $t->id );
			$t     = OYS_Teachers::get( $t->id );
			$_GET['oys_msg'] = true === $ready ? __( 'Your Stripe account is connected. Card payments for your classes now go straight to you.', 'olivia-studio' ) : __( 'Stripe isn\'t finished yet. You can continue any time.', 'olivia-studio' );
		}
		self::header( __( 'My profile', 'olivia-studio' ) );
		echo '<p class="description oys-lead">' . esc_html__( 'This is what students see on the website and in the app, next to your classes.', 'olivia-studio' ) . '</p>';
		echo self::form( 'oys_teach_profile', 'class="oys-form"' ) . '<table class="form-table">'; // phpcs:ignore
		echo '<tr><th>' . esc_html__( 'Name', 'olivia-studio' ) . '</th><td><b>' . esc_html( $t->name ) . '</b> <small>(' . esc_html__( 'the studio can change it', 'olivia-studio' ) . ')</small></td></tr>';
		echo '<tr><th><label for="t-headline">' . esc_html__( 'Headline', 'olivia-studio' ) . '</label></th><td><input id="t-headline" name="headline" class="large-text" value="' . esc_attr( $t->headline ) . '"></td></tr>';
		echo '<tr><th><label for="t-bio">' . esc_html__( 'About you', 'olivia-studio' ) . '</label></th><td><textarea id="t-bio" name="bio" rows="8" class="large-text">' . esc_textarea( (string) $t->bio ) . '</textarea></td></tr>';
		echo '<tr><th>' . esc_html__( 'Emails', 'olivia-studio' ) . '</th><td><label><input type="checkbox" name="notify" value="1"' . checked( 1, (int) $t->notify, false ) . '> ' . esc_html__( 'Email me about bookings, cancellations and whether my classes go ahead', 'olivia-studio' ) . '</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Save my profile', 'olivia-studio' ) );
		echo '</form>';
		self::photos_box( $t, 'oys_teach_photo' );

		echo '<h2>' . esc_html__( 'Card payments', 'olivia-studio' ) . '</h2><p><b>' . esc_html( OYS_Connect::status_label( $t ) ) . '</b></p>';
		echo '<p class="description">' . esc_html( sprintf( __( 'Connect your own Stripe account and card payments for your drop-in classes go straight to you: you are the seller, and the studio\'s %d%% is taken automatically as a fee. Passes and memberships are paid to the studio and settled in your monthly statement.', 'olivia-studio' ), 100 - (int) $t->share_percent ) ) . '</p>';
		if ( OYS_Settings::payments_ready() ) {
			echo self::form( 'oys_teach_connect' ) . '<p><button class="button button-primary">' . esc_html( OYS_Connect::ready( $t ) ? __( 'Open my Stripe setup', 'olivia-studio' ) : ( $t->stripe_account ? __( 'Continue with Stripe', 'olivia-studio' ) : __( 'Connect Stripe', 'olivia-studio' ) ) ) . '</button></p></form>'; // phpcs:ignore
		}
		echo '</div>';
	}

	public static function page_teach_statement() {
		$t = OYS_Teachers::current();
		if ( ! $t ) {
			self::header( __( 'My statement', 'olivia-studio' ) );
			echo '</div>';
			return;
		}
		$st = OYS_Connect::statement( $t->id, self::month_param() );
		self::header( sprintf( __( 'My statement: %s', 'olivia-studio' ), $st['label'] ) );
		echo self::month_picker( 'oys-teach-statement' ); // phpcs:ignore
		self::statement_html( $st, true, wp_nonce_url( admin_url( 'admin-post.php?action=oys_teach_csv&month=' . $st['month'] ), 'oys_teach_csv' ) );
		echo '</div>';
	}

	/* ---------- Teacher actions (always their own classes only) ---------- */

	private static function teach_roster( $t ) {
		$session_id = (int) $_POST['session'];
		$b          = OYS_Bookings::get( (int) $_POST['booking'] );
		if ( ! $b || (int) $b->session_id !== $session_id || ! OYS_Teachers::owns( $t, OYS_Schedule::get( $session_id ) ) ) {
			wp_die( esc_html__( 'This class isn\'t one of yours.', 'olivia-studio' ), 403 );
		}
		$do = sanitize_key( $_POST['do'] ?? '' );
		if ( in_array( $do, array( 'collect_cash', 'collect_other' ), true ) ) {
			OYS_Bookings::collect( $b->id, substr( $do, 8 ) );
			self::back( 'oys-teach', array( 'session' => $session_id ), __( 'Marked as paid.', 'olivia-studio' ) );
		}
		OYS_Bookings::set_attendance( $b->id, $do );
		self::back( 'oys-teach', array( 'session' => $session_id ) );
	}

	private static function teach_message( $t ) {
		$session_id = (int) $_POST['session'];
		if ( ! OYS_Teachers::owns( $t, OYS_Schedule::get( $session_id ) ) ) {
			wp_die( esc_html__( 'This class isn\'t one of yours.', 'olivia-studio' ), 403 );
		}
		$res = OYS_Messages::send_to_session( $session_id, wp_unslash( $_POST['subject'] ?? '' ), wp_unslash( $_POST['body'] ?? '' ), array( 'guests' => ! empty( $_POST['guests'] ), 'reply_to' => $t->email ) );
		self::back( 'oys-teach', array( 'session' => $session_id ), is_wp_error( $res ) ? $res->get_error_message() : sprintf( _n( 'Message sent to %d person.', 'Message sent to %d people.', $res, 'olivia-studio' ), $res ), is_wp_error( $res ) );
	}

	private static function teach_profile( $t ) {
		OYS_Teachers::save_profile( $t->id, array(
			'headline' => wp_unslash( $_POST['headline'] ?? '' ),
			'bio'      => wp_unslash( $_POST['bio'] ?? '' ),
			'notify'   => ! empty( $_POST['notify'] ),
		) );
		self::back( 'oys-teach-profile', array(), __( 'Profile saved.', 'olivia-studio' ) );
	}

	private static function teach_photo( $t ) {
		self::photo_action( $t->id, 'oys-teach-profile', array() );
	}

	private static function teach_connect( $t ) {
		$url = OYS_Connect::onboarding_url( $t->id, admin_url( 'admin.php?page=oys-teach-profile' ) );
		if ( is_wp_error( $url ) ) {
			self::back( 'oys-teach-profile', array(), $url->get_error_message(), true );
		}
		wp_redirect( $url ); // phpcs:ignore -- Stripe's hosted onboarding.
		exit;
	}

	private static function teach_csv( $t ) {
		self::send_csv( OYS_Connect::statement( $t->id, sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) ) );
	}
}
