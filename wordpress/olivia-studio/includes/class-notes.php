<?php
/**
 * Notes and reminders for the studio (Áron, 26 November: „meg kellene emlékeztető neki, mert például egy sorsolásról
 * csinált most, hogy a fél évben a legtöbbet, akik jártak órára, legyen egy emlékeztető, hogy júliusban sorsolnia kell.
 * Egy ilyen jegyzetszerű valami, ami utána küldi értesítést Oliviának. Dolgozz ki, legyen jó.”)
 *
 * A note is a title and a few lines. Given a day (and a time, 9:00 if none), it reminds: at that moment it arrives on the
 * phone of the person it is for (push, the studio app) and in their inbox, and it shows on the Hub's first page from a
 * week before. It can come back every month, every half year or every year (the raffle: „félévente sorsolok”). Ticked
 * „done”, it moves to the bottom; nothing is lost.
 *
 * @package olivia-studio
 */

defined( 'ABSPATH' ) || exit;

class OYS_Notes {

	const OPT     = 'oys_notes';
	const CAP     = 'oys_manage';
	const REPEATS = array( 'month' => '+1 month', 'half' => '+6 months', 'year' => '+1 year' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_oys_note_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_oys_note_do', array( __CLASS__, 'handle_do' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ---------- the notes ---------- */

	/** @return array[] id => note */
	public static function all() {
		$n = get_option( self::OPT, array() );
		return is_array( $n ) ? $n : array();
	}

	public static function get( $id ) {
		return self::all()[ (int) $id ] ?? null;
	}

	private static function put( array $notes ) {
		update_option( self::OPT, $notes, false );
	}

	/** Site-local 'Y-m-d H:i' / 'Y-m-d\TH:i' / 'Y-m-d' (then 9:00) → UTC 'Y-m-d H:i:s'; '' when empty or not a date. */
	public static function utc( $local ) {
		$local = trim( str_replace( 'T', ' ', (string) $local ) );
		if ( '' === $local ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $local ) ) {
			$local .= ' 09:00';
		}
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', substr( $local, 0, 16 ), wp_timezone() );
		return $d ? $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	/** UTC → site-local 'Y-m-d\TH:i' (for the form). */
	public static function local( $utc ) {
		return $utc ? wp_date( 'Y-m-d\TH:i', oys_ts( $utc ) ) : '';
	}

	/**
	 * Create or change a note. $d: title, text, due (site-local date and time, or ''), repeat ('', month, half, year),
	 * user (who is reminded; default: whoever writes it), url (where the reminder leads). @return int id
	 */
	public static function save( array $d, $id = 0 ) {
		$all = self::all();
		$id  = (int) $id && isset( $all[ (int) $id ] ) ? (int) $id : ( $all ? max( array_keys( $all ) ) + 1 : 1 );
		$old = $all[ $id ] ?? array();
		$due = array_key_exists( 'due', $d ) ? self::utc( (string) $d['due'] ) : (string) ( $old['due'] ?? '' );
		$who = (int) ( $d['user'] ?? 0 ) ?: (int) ( $old['user'] ?? 0 ) ?: get_current_user_id();
		$all[ $id ] = array(
			'id'      => $id,
			'title'   => sanitize_text_field( (string) ( $d['title'] ?? ( $old['title'] ?? '' ) ) ) ?: __( 'Note', 'olivia-studio' ),
			'text'    => sanitize_textarea_field( (string) ( $d['text'] ?? ( $old['text'] ?? '' ) ) ),
			'due'     => $due,
			'repeat'  => isset( self::REPEATS[ $d['repeat'] ?? '' ] ) ? $d['repeat'] : ( array_key_exists( 'repeat', $d ) ? '' : (string) ( $old['repeat'] ?? '' ) ),
			'user'    => $who,
			'url'     => esc_url_raw( (string) ( $d['url'] ?? ( $old['url'] ?? '' ) ) ),
			'done'    => '',
			// a new time is a new reminder; the same time was already sent (or not)
			'sent'    => $due === (string) ( $old['due'] ?? '' ) ? (string) ( $old['sent'] ?? '' ) : '',
			'created' => (string) ( $old['created'] ?? oys_now() ),
			'by'      => (int) ( $old['by'] ?? get_current_user_id() ),
		);
		self::put( $all );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ (int) $id ] );
		self::put( $all );
	}

	/** Done (or back to open). */
	public static function done( $id, $done = true ) {
		$all = self::all();
		if ( isset( $all[ (int) $id ] ) ) {
			$all[ (int) $id ]['done'] = $done ? oys_now() : '';
			self::put( $all );
		}
	}

	/** The next time of a repeating reminder (in the site's time zone, so 9:00 stays 9:00 across summer time). */
	public static function next( $utc, $repeat ) {
		$step = self::REPEATS[ $repeat ] ?? '';
		if ( ! $step || ! $utc ) {
			return '';
		}
		$d = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );
		do {
			$d = $d->modify( $step );
		} while ( $d->getTimestamp() <= time() );
		return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Open notes: those with a time first (soonest first), then the ones without. */
	public static function open() {
		$n = array_filter( self::all(), fn( $x ) => '' === (string) $x['done'] );
		uasort( $n, fn( $a, $b ) => array( '' === $a['due'], $a['due'], $a['id'] ) <=> array( '' === $b['due'], $b['due'], $b['id'] ) );
		return $n;
	}

	/** For the Hub's first page: reminders already due, and those in the next 7 days. */
	public static function soon( $user_id = 0 ) {
		$until = oys_utc_plus( 7 * DAY_IN_SECONDS );
		$out   = array();
		foreach ( self::open() as $n ) {
			if ( '' === $n['due'] || $n['due'] > $until || ( $user_id && (int) $n['user'] !== (int) $user_id && ! user_can( $user_id, 'manage_options' ) ) ) {
				continue;
			}
			$out[] = array( 'id' => (int) $n['id'], 'title' => $n['title'], 'when' => self::when( $n ), 'due' => $n['due'] <= oys_now() );
		}
		return $out;
	}

	/** „júl. 1., sze 9:00 · félévente” */
	public static function when( array $n ) {
		if ( '' === $n['due'] ) {
			return '';
		}
		$labels = self::repeat_labels();
		return oys_date( $n['due'], 'D M j, g:i a' ) . ( $n['repeat'] ? ' · ' . $labels[ $n['repeat'] ] : '' );
	}

	public static function repeat_labels() {
		return array( '' => __( 'Once', 'olivia-studio' ), 'month' => __( 'Every month', 'olivia-studio' ), 'half' => __( 'Every half year', 'olivia-studio' ), 'year' => __( 'Every year', 'olivia-studio' ) );
	}

	/* ---------- reminding ---------- */

	/** Cron (every few minutes): every reminder whose time has come, once; a repeating one moves on to its next time. */
	public static function run() {
		$all     = self::all();
		$now     = oys_now();
		$changed = false;
		foreach ( $all as $id => $n ) {
			if ( '' !== (string) $n['done'] || '' === (string) $n['due'] || $n['due'] > $now || $n['sent'] === $n['due'] ) {
				continue;
			}
			// claim it first, so two cron runs never remind twice
			$all[ $id ]['sent'] = $n['due'];
			if ( $n['repeat'] ) {
				$all[ $id ]['due'] = self::next( $n['due'], $n['repeat'] );
			}
			self::put( $all );
			$changed = true;
			self::remind( $n );
		}
		return $changed;
	}

	/** The reminder: on the phone (push) and by email, to the person it is for. */
	public static function remind( array $n ) {
		$u    = get_userdata( (int) $n['user'] );
		$to   = $u ? $u->user_email : (string) OYS_Settings::get( 'notify_email' );
		$link = $n['url'] ?: admin_url( 'admin.php?page=oys-notes#note-' . (int) $n['id'] );
		$push = 0;
		if ( $u && class_exists( 'OYS_Studio_App' ) ) {
			$push = OYS_Studio_App::notify( $u->ID, '⏰ ' . $n['title'], $n['text'] ?: __( 'Your reminder is due.', 'olivia-studio' ), $link, 'note-' . (int) $n['id'] );
		}
		$mail = false;
		if ( is_email( $to ) ) {
			$mail = OYS_I18n::with( OYS_I18n::lang_for( $to ), function () use ( $n, $to, $link ) {
				$body = ( '' !== trim( $n['text'] ) ? OYS_Email_Templates::paragraphs( $n['text'], array() ) : '' )
					. '<p style="color:#53635A">' . esc_html( sprintf( __( 'Reminder you set for %s.', 'olivia-studio' ), oys_date( $n['due'], 'M j, Y g:i a' ) ) ) . '</p>';
				return OYS_Emails::send( $to, sprintf( __( 'Reminder: %s', 'olivia-studio' ), $n['title'] ), $n['title'], $body, array(), array( __( 'Open my notes', 'olivia-studio' ), $link ) );
			} );
		}
		do_action( 'oys_note_reminded', $n, $push, $mail );
		return array( $push, $mail );
	}

	/* ---------- the page (Studio → Notes & reminders; the Hub links here) ---------- */

	public static function menu() {
		add_submenu_page( 'oys', __( 'Notes & reminders', 'olivia-studio' ), __( 'Notes & reminders', 'olivia-studio' ), self::CAP, 'oys-notes', array( __CLASS__, 'page' ) );
	}

	private static function form_open( $action, $extra = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '><input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false );
	}

	/** The people who can be reminded: the studio's own accounts. */
	private static function staff() {
		return get_users( array( 'capability__in' => array( 'oys_manage', 'manage_options' ), 'fields' => array( 'ID', 'display_name' ) ) );
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$edit = isset( $_GET['edit'] ) ? self::get( (int) $_GET['edit'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$v    = $edit ?: array( 'id' => 0, 'title' => '', 'text' => '', 'due' => '', 'repeat' => '', 'user' => get_current_user_id(), 'url' => '' );
		$date = $v['due'] ? wp_date( 'Y-m-d', oys_ts( $v['due'] ) ) : '';
		$time = $v['due'] ? wp_date( 'H:i', oys_ts( $v['due'] ) ) : '09:00';
		echo '<div class="wrap oys-wrap oys-notes"><h1>' . esc_html__( 'Notes & reminders', 'olivia-studio' ) . '</h1>';
		if ( ! empty( $_GET['oys_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['oys_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p class="description" style="max-width:720px">' . esc_html__( 'Write down anything you want to remember. Give it a day and it reminds you then: a notification on your phone (the studio app) and an email. From a week before, it is also on the Hub\'s first page.', 'olivia-studio' ) . '</p>';
		$labels = self::repeat_labels();
		$who    = '';
		foreach ( self::staff() as $s ) {
			$who .= '<option value="' . (int) $s->ID . '"' . selected( (int) $v['user'], (int) $s->ID, false ) . '>' . esc_html( $s->display_name ) . '</option>';
		}
		$rep = '';
		foreach ( $labels as $k => $l ) {
			$rep .= '<option value="' . esc_attr( $k ) . '"' . selected( $v['repeat'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '<div class="oys-box oys-notes__form"><h2>' . esc_html( $edit ? __( 'Change the note', 'olivia-studio' ) : __( 'New note', 'olivia-studio' ) ) . '</h2>'
			. self::form_open( 'oys_note_save', 'class="oys-form"' ) . '<input type="hidden" name="id" value="' . (int) $v['id'] . '">'
			. '<p><label><b>' . esc_html__( 'What', 'olivia-studio' ) . '</b><br><input name="title" class="large-text" required value="' . esc_attr( $v['title'] ) . '" placeholder="' . esc_attr__( 'e.g. The half-year draw: a private class', 'olivia-studio' ) . '"></label></p>'
			. '<p><label>' . esc_html__( 'Note (optional)', 'olivia-studio' ) . '<br><textarea name="text" rows="4" class="large-text">' . esc_textarea( $v['text'] ) . '</textarea></label></p>'
			. '<p class="oys-notes__when"><label>' . esc_html__( 'Remind me on', 'olivia-studio' ) . '<br><input type="date" name="due_date" value="' . esc_attr( $date ) . '"></label> '
			. '<label>' . esc_html__( 'at', 'olivia-studio' ) . '<br><input type="time" name="due_time" value="' . esc_attr( $time ) . '"></label> '
			. '<label>' . esc_html__( 'Repeat', 'olivia-studio' ) . '<br><select name="repeat">' . $rep . '</select></label> '
			. '<label>' . esc_html__( 'Remind', 'olivia-studio' ) . '<br><select name="user">' . $who . '</select></label></p>'
			. '<p class="description">' . esc_html__( 'No day: it stays a note, without a reminder.', 'olivia-studio' ) . '</p>'
			. '<p><button class="button button-primary">' . esc_html__( 'Save', 'olivia-studio' ) . '</button>' . ( $edit ? ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=oys-notes' ) ) . '">' . esc_html__( 'Cancel', 'olivia-studio' ) . '</a>' : '' ) . '</p></form></div>';

		$open  = self::open();
		$timed = array_filter( $open, fn( $n ) => '' !== $n['due'] );
		$plain = array_filter( $open, fn( $n ) => '' === $n['due'] );
		$done  = array_filter( self::all(), fn( $n ) => '' !== (string) $n['done'] );
		echo '<h2>' . esc_html__( 'Reminders', 'olivia-studio' ) . '</h2>' . ( $timed ? '<ul class="oys-notes__list">' . implode( '', array_map( array( __CLASS__, 'row' ), $timed ) ) . '</ul>' : '<p class="description">' . esc_html__( 'No reminders yet.', 'olivia-studio' ) . '</p>' );
		if ( $plain ) {
			echo '<h2>' . esc_html__( 'Notes without a day', 'olivia-studio' ) . '</h2><ul class="oys-notes__list">' . implode( '', array_map( array( __CLASS__, 'row' ), $plain ) ) . '</ul>';
		}
		if ( $done ) {
			echo '<details class="oys-notes__done"><summary>' . esc_html( sprintf( __( 'Done (%d)', 'olivia-studio' ), count( $done ) ) ) . '</summary><ul class="oys-notes__list">' . implode( '', array_map( array( __CLASS__, 'row' ), $done ) ) . '</ul></details>';
		}
		echo '</div>';
	}

	private static function row( array $n ) {
		$u     = get_userdata( (int) $n['user'] );
		$late  = '' === (string) $n['done'] && '' !== $n['due'] && $n['due'] <= oys_now();
		$act   = function ( $do, $label, $cls = 'button button-small' ) use ( $n ) {
			return self::form_open( 'oys_note_do', 'class="oys-inline"' ) . '<input type="hidden" name="id" value="' . (int) $n['id'] . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><button class="' . esc_attr( $cls ) . '"' . ( 'delete' === $do ? ' onclick="return confirm(\'' . esc_js( __( 'Delete this note?', 'olivia-studio' ) ) . '\')"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
		};
		return '<li class="oys-notes__item' . ( $late ? ' is-due' : '' ) . ( $n['done'] ? ' is-done' : '' ) . '" id="note-' . (int) $n['id'] . '">'
			. '<div class="oys-notes__head"><b>' . esc_html( $n['title'] ) . '</b>' . ( $late ? ' <span class="oys-status oys-status--pending">' . esc_html__( 'Due', 'olivia-studio' ) . '</span>' : '' ) . '</div>'
			. ( $n['due'] ? '<div class="oys-notes__meta">⏰ ' . esc_html( self::when( $n ) ) . ( $u ? ' · ' . esc_html( sprintf( __( 'for %s', 'olivia-studio' ), $u->display_name ) ) : '' ) . '</div>' : '' )
			. ( '' !== trim( $n['text'] ) ? '<div class="oys-notes__text">' . nl2br( esc_html( $n['text'] ) ) . '</div>' : '' )
			. ( $n['url'] ? '<div><a href="' . esc_url( $n['url'] ) . '">' . esc_html__( 'Open the link', 'olivia-studio' ) . ' →</a></div>' : '' )
			. '<div class="oys-notes__do">' . ( $n['done']
				? $act( 'undo', __( 'Back to open', 'olivia-studio' ) ) . ' ' . $act( 'delete', __( 'Delete', 'olivia-studio' ), 'button-link button-link-delete' )
				: $act( 'done', __( 'Done ✓', 'olivia-studio' ), 'button button-small button-primary' ) . ' <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=oys-notes&edit=' . (int) $n['id'] ) ) . '">' . esc_html__( 'Change', 'olivia-studio' ) . '</a> ' . $act( 'delete', __( 'Delete', 'olivia-studio' ), 'button-link button-link-delete' ) )
			. '</div></li>';
	}

	public static function handle_save() {
		check_admin_referer( 'oys_note_save' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do this.', 'olivia-studio' ), 403 );
		}
		$p    = wp_unslash( $_POST );
		$date = sanitize_text_field( (string) ( $p['due_date'] ?? '' ) );
		$time = sanitize_text_field( (string) ( $p['due_time'] ?? '' ) ) ?: '09:00';
		$id   = self::save( array(
			'title'  => $p['title'] ?? '',
			'text'   => $p['text'] ?? '',
			'due'    => $date ? $date . ' ' . $time : '',
			'repeat' => sanitize_key( (string) ( $p['repeat'] ?? '' ) ),
			'user'   => (int) ( $p['user'] ?? 0 ),
		), (int) ( $p['id'] ?? 0 ) );
		$n = self::get( $id );
		oys_redirect( add_query_arg( 'oys_msg', rawurlencode( $n['due'] ? sprintf( __( 'Saved. It reminds you on %s.', 'olivia-studio' ), oys_date( $n['due'], 'M j, Y g:i a' ) ) : __( 'Saved.', 'olivia-studio' ) ), admin_url( 'admin.php?page=oys-notes' ) ) . '#note-' . $id );
	}

	public static function handle_do() {
		check_admin_referer( 'oys_note_do' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You don\'t have permission to do this.', 'olivia-studio' ), 403 );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		if ( 'done' === $do || 'undo' === $do ) {
			self::done( $id, 'done' === $do );
		} elseif ( 'delete' === $do ) {
			self::delete( $id );
		}
		oys_redirect( admin_url( 'admin.php?page=oys-notes' ) );
	}

	/* ---------- REST (the studio's tools, the Hub) ---------- */

	public static function routes() {
		$can = fn() => current_user_can( self::CAP ) || current_user_can( 'manage_options' );
		register_rest_route( 'oys/v1', '/hub/notes', array(
			array( 'methods' => 'GET', 'permission_callback' => $can, 'callback' => fn() => rest_ensure_response( array_values( self::all() ) ) ),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $r ) {
					$p  = (array) ( $r->get_json_params() ?: $r->get_params() );
					$id = self::save( array_intersect_key( $p, array_flip( array( 'title', 'text', 'due', 'repeat', 'user', 'url' ) ) ), (int) ( $p['id'] ?? 0 ) );
					return rest_ensure_response( self::get( $id ) );
				},
			),
		) );
	}
}

OYS_Notes::init();
