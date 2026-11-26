<?php
/**
 * Sessions left on passes from the earlier system (Motibro), kept aside: not bookable online, collected for the studio
 * with their value, redeemable on request (Olívia, 16 November: „Van több olyan vendég, akinek van még alkalom a
 * bérletén, ezeket ne hozd át, ne foglalhassanak online, de valahova gyűjtsd össze nekem az adminon belül, hogy ha
 * esetleg írnak nekem, akkor tudjuk beváltani őket akár egyénire az értéknek megfelelően!”).
 *
 * And the end of November: „november végén küldöm az új exportot … ha abban az exportban még marad alkalmuk,
 * hosszabbítsd meg a bérletüket január 27-ig és a maradék alkalmukat tudják felhasználni online addig.” – importing that
 * export with „online until” turns what is left into online credits until that day (or the pass's own later day).
 *
 * Each entry: the customer, the pass, the sessions (used / locked / left) as of the export, the value of one session (the
 * price the customer paid for it there, from the imported visits) and what became of it: held, online, redeemed.
 * Kept in one option (a handful of passes); the customer sees nothing of it.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Held {

	const OPTION = 'oys_held_credits';

	/** A session of the 5-class Motibro pass when the visits say nothing (14 000 Ft / 5). */
	const DEFAULT_CENTS = 280000;

	public static function init() {
		add_action( 'admin_post_oys_held', array( __CLASS__, 'handle' ) );
		add_action( 'rest_api_init', function () {
			$can = fn() => current_user_can( 'oys_manage' ) || current_user_can( 'manage_options' );
			register_rest_route( 'oys/v1', '/customers/held', array(
				array( 'methods' => 'GET', 'permission_callback' => $can, 'callback' => fn() => rest_ensure_response( array_values( self::with_passes() ) ) ),
			) );
			// a word to one guest from the studio, with the one-tap password button where {password_button} stands (Áron,
			// 26 November: „a Bajzák-Faragó Viviennek 3 órája maradt … erről küldj neki egy e-mailt”); `test_to`: a copy to
			// the studio first (the button only says it is a preview); `note_key`: noted on that Motibro row
			register_rest_route( 'oys/v1', '/customers/(?P<id>\d+)/email', array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $r ) {
					$p = (array) ( $r->get_json_params() ?: $r->get_params() );
					$res = self::email_customer( (int) $r['id'], (string) ( $p['subject'] ?? '' ), (string) ( $p['heading'] ?? '' ), (string) ( $p['body'] ?? '' ), sanitize_key( (string) ( $p['note_key'] ?? '' ) ), sanitize_email( (string) ( $p['test_to'] ?? '' ) ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'sent' => $res ) );
				},
			) );
			register_rest_route( 'oys/v1', '/customers/held/close-rest', array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => fn() => rest_ensure_response( self::close_rest() ),
			) );
			register_rest_route( 'oys/v1', '/customers/held/from-passes', array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => fn( WP_REST_Request $r ) => rest_ensure_response( self::from_passes( 'motibro', (bool) $r->get_param( 'dry_run' ) ) ),
			) );
			register_rest_route( 'oys/v1', '/customers/held/(?P<key>[a-z0-9-]+)', array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $r ) {
					$res = self::act( (string) $r['key'], sanitize_key( (string) $r->get_param( 'do' ) ), sanitize_textarea_field( (string) $r->get_param( 'note' ) ), sanitize_text_field( (string) $r->get_param( 'until' ) ) );
					return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
				},
			) );
		} );
	}

	/** Everything not moved online is closed (Áron, 26 November: „akit nem hozott át most Olivia online-ra, azok kuka”). */
	public static function close_rest() {
		$closed = array();
		foreach ( self::all() as $k => $e ) {
			if ( 'held' === $e['status'] ) {
				self::act( $k, 'close' );
				$closed[] = $k;
			}
		}
		return array( 'closed' => $closed );
	}

	/** The list with each online row's pass as it is now (to check that what was moved online is really there). */
	public static function with_passes() {
		global $wpdb;
		$out = array();
		foreach ( self::all() as $k => $e ) {
			$p = 'online' === $e['status'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, kind, credits_left, credits_total, expires_at FROM ' . OYS_Install::table( 'passes' ) . ' WHERE user_id = %d AND product_id = %s ORDER BY id DESC LIMIT 1', (int) $e['user'], (string) $k ) ) : null;
			$u = get_userdata( (int) $e['user'] );
			$out[ $k ] = $e + array( 'pass' => $p ? array( 'id' => (int) $p->id, 'kind' => $p->kind, 'left' => (int) $p->credits_left, 'total' => (int) $p->credits_total, 'expires' => $p->expires_at ) : null, 'guest' => $u ? $u->display_name : '' );
		}
		return $out;
	}

	/** A word to one guest (see the route). @return bool|WP_Error */
	public static function email_customer( $user_id, $subject, $heading, $body, $note_key = '', $test_to = '' ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u || '' === trim( $subject ) || '' === trim( $body ) ) {
			return new WP_Error( 'oys_email', 'A guest, a subject and a text are needed.', array( 'status' => 400 ) );
		}
		$to = $test_to ?: $u->user_email;
		$ok = OYS_I18n::with( OYS_I18n::lang_for( $u->ID ), function () use ( $u, $to, $subject, $heading, $body, $test_to ) {
			$vars = array( 'first_name' => $u->first_name ?: $u->display_name, 'studio' => OYS_Settings::get( 'email_from_name' ) );
			$html = OYS_Newsletter::body_for( $body, $vars, $u->ID, '' === $test_to );
			$subj = OYS_Email_Templates::fill( sanitize_text_field( $subject ), $vars );
			return OYS_Emails::send( $to, ( $test_to ? __( '[Test]', 'olivia-studio' ) . ' ' : '' ) . $subj, OYS_Email_Templates::fill( sanitize_text_field( $heading ?: $subject ), $vars ), $html );
		} );
		if ( $ok && ! $test_to && $note_key && self::get( $note_key ) ) {
			$all              = self::all();
			$all[ $note_key ]['note'] = trim( $all[ $note_key ]['note'] . "\n" . sprintf( __( 'Email to the guest: „%1$s” (%2$s)', 'olivia-studio' ), sanitize_text_field( $subject ), wp_date( 'Y-m-d' ) ) );
			self::save( $all );
		}
		return (bool) $ok;
	}

	/* ---------- the list ---------- */

	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	private static function save( array $all ) {
		update_option( self::OPTION, $all, false );
	}

	public static function get( $key ) {
		return self::all()[ $key ] ?? null;
	}

	/** The customer's entries still to redeem (held, something left). */
	public static function for_user( $user_id ) {
		return array_values( array_filter( self::all(), fn( $e ) => (int) $e['user'] === (int) $user_id && 'held' === $e['status'] && (int) $e['left'] > 0 ) );
	}

	public static function value_cents( array $e ) {
		return (int) $e['left'] * (int) $e['per_cents'];
	}

	/** Totals of what is held: [ entries, sessions, value ]. */
	public static function totals() {
		$held = array_filter( self::all(), fn( $e ) => 'held' === $e['status'] && (int) $e['left'] > 0 );
		return array( count( $held ), array_sum( array_map( fn( $e ) => (int) $e['left'], $held ) ), array_sum( array_map( array( __CLASS__, 'value_cents' ), $held ) ) );
	}

	/**
	 * What one session was worth for this customer: the price per class of their latest visit on this kind of pass (the
	 * imported visits keep it in the note: „Motibro: <pass> · 2800 Ft”); else the default.
	 */
	public static function per_cents( $user_id, $pass_name ) {
		global $wpdb;
		$like  = '%' . $wpdb->esc_like( (string) $pass_name ) . '%';
		$notes = $wpdb->get_col( $wpdb->prepare( 'SELECT b.note FROM ' . OYS_Install::table( 'bookings' ) . ' b JOIN ' . OYS_Install::table( 'sessions' ) . " s ON s.id = b.session_id WHERE b.user_id = %d AND b.paid_with = 'import' AND b.note LIKE %s ORDER BY s.starts_at DESC LIMIT 1", (int) $user_id, $like ) );
		if ( $notes && preg_match( '/·\s*(\d+)\s*Ft/u', (string) $notes[0], $m ) && (int) $m[1] > 0 ) {
			return (int) $m[1] * 100;
		}
		return self::DEFAULT_CENTS;
	}

	/** Add or update an entry from an export row (the status of an entry already redeemed or moved online stays). */
	public static function put( $key, $user_id, array $d, $dry_run = false ) {
		$all  = self::all();
		$have = $all[ $key ] ?? null;
		$row  = array(
			'key'       => $key,
			'user'      => (int) $user_id,
			'name'      => (string) $d['name'],
			'used'      => (int) $d['used'],
			'locked'    => (int) $d['locked'],
			'left'      => (int) $d['left'],
			'expires'   => (string) $d['expires'],
			'per_cents' => (int) ( $have['per_cents'] ?? 0 ) ?: self::per_cents( $user_id, $d['name'] ),
			'snapshot'  => (string) ( $d['snapshot'] ?? wp_date( 'Y-m-d' ) ),
			'status'    => (string) ( $have['status'] ?? 'held' ),
			'note'      => (string) ( $have['note'] ?? '' ),
			'source'    => (string) ( $d['source'] ?? 'motibro' ),
			'missing'   => false,
			'updated'   => current_time( 'mysql', true ),
		);
		if ( $have && array_intersect_key( $have, array_flip( array( 'used', 'locked', 'left', 'expires', 'name' ) ) ) == array_intersect_key( $row, array_flip( array( 'used', 'locked', 'left', 'expires', 'name' ) ) ) && empty( $have['missing'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			return 'unchanged';
		}
		if ( ! $dry_run ) {
			$all[ $key ] = $row;
			self::save( $all );
		}
		return $have ? 'updated' : 'created';
	}

	/** Entries of this source not in a newer export: flagged (used up or expired there – check in Motibro). */
	public static function flag_missing( array $seen_keys, $source = 'motibro' ) {
		$all = self::all();
		$n   = 0;
		foreach ( $all as $k => $e ) {
			if ( $e['source'] === $source && 'held' === $e['status'] && ! in_array( $k, $seen_keys, true ) && empty( $e['missing'] ) ) {
				$all[ $k ]['missing'] = true;
				$n++;
			}
		}
		if ( $n ) {
			self::save( $all );
		}
		return $n;
	}

	/**
	 * Turn what is left into online credits until $until (or the pass's own later day). The pass keeps the entry's key, so
	 * a newer export updates it. @return int the pass id
	 */
	public static function to_online( $key, $until ) {
		global $wpdb;
		$all = self::all();
		$e   = $all[ $key ] ?? null;
		if ( ! $e ) {
			return new WP_Error( 'oys_held', __( 'Not found.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$t       = OYS_Install::table( 'passes' );
		$day     = max( (string) $until, (string) $e['expires'] );
		$expires = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? oys_local_to_utc( $day . ' 23:59:59' ) : null;
		$total   = max( 1, (int) $e['used'] + (int) $e['locked'] + (int) $e['left'] );
		$have    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND product_id = %s LIMIT 1", (int) $e['user'], $key ) );
		if ( $have ) {
			$wpdb->update( $t, array( 'credits_left' => (int) $e['left'], 'credits_total' => $total, 'expires_at' => $expires ), array( 'id' => (int) $have->id ) );
			$id = (int) $have->id;
		} else {
			$wpdb->insert( $t, array(
				'user_id'            => (int) $e['user'],
				'product_id'         => $key,
				'name'               => (string) $e['name'],
				'kind'               => 'online',
				'credits_total'      => $total,
				'credits_left'       => (int) $e['left'],
				'expires_at'         => $expires,
				'order_id'           => 0,
				'source'             => (string) $e['source'],
				'expiry_notice_sent' => 1,
				'created_at'         => oys_now(),
			) );
			$id = (int) $wpdb->insert_id;
		}
		$all[ $key ]['status']  = 'online';
		$all[ $key ]['until']   = $day;
		$all[ $key ]['note']    = trim( $all[ $key ]['note'] . "\n" . sprintf( __( 'Online until %s', 'olivia-studio' ), $day ) );
		$all[ $key ]['updated'] = current_time( 'mysql', true );
		self::save( $all );
		return $id;
	}

	/** held → redeemed (with what for), → online (until a day), or back to held. */
	public static function act( $key, $do, $note = '', $until = '' ) {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return new WP_Error( 'oys_held', __( 'Not found.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		if ( 'online' === $do ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
				return new WP_Error( 'oys_held', __( 'Give the last day they can use them online.', 'olivia-studio' ), array( 'status' => 400 ) );
			}
			$res = self::to_online( $key, $until );
			return is_wp_error( $res ) ? $res : self::get( $key );
		}
		if ( 'redeem' === $do ) {
			$all[ $key ]['status'] = 'redeemed';
			$all[ $key ]['note']   = trim( $all[ $key ]['note'] . "\n" . wp_date( 'Y-m-d' ) . ': ' . ( '' !== $note ? $note : __( 'redeemed', 'olivia-studio' ) ) );
		} elseif ( 'hold' === $do ) {
			$all[ $key ]['status'] = 'held';
		} elseif ( 'close' === $do ) {
			// Áron, 26 November: „akit nem hozott át most Olivia online-ra, azok kuka” – kept as a record, gone from every
			// count, list and from the guest's account
			$all[ $key ]['status'] = 'closed';
			$all[ $key ]['note']   = trim( $all[ $key ]['note'] . "\n" . wp_date( 'Y-m-d' ) . ': ' . ( '' !== $note ? $note : __( 'closed – not moved online', 'olivia-studio' ) ) );
		} else {
			return new WP_Error( 'oys_held', 'do: redeem | online | hold | close', array( 'status' => 400 ) );
		}
		$all[ $key ]['updated'] = current_time( 'mysql', true );
		self::save( $all );
		return $all[ $key ];
	}

	/**
	 * The passes imported earlier as bookable online credits → held entries (not bookable). A pass already used for an
	 * online booking is left as it is (reported). @return array what happened
	 */
	public static function from_passes( $source = 'motibro', $dry_run = false ) {
		global $wpdb;
		$t   = OYS_Install::table( 'passes' );
		$b   = OYS_Install::table( 'bookings' );
		$out = array( 'held' => 0, 'kept_used_online' => 0, 'sessions' => 0, 'dry_run' => $dry_run );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE source = %s AND product_id LIKE %s", $source, $wpdb->esc_like( $source . '-' ) . '%' ) ) as $p ) {
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $b WHERE pass_id = %d", (int) $p->id ) ) ) {
				$out['kept_used_online']++;
				continue;
			}
			$left = (int) $p->credits_left;
			$out['held']++;
			$out['sessions'] += $left;
			if ( $dry_run ) {
				continue;
			}
			self::put( $p->product_id, (int) $p->user_id, array(
				'name'     => $p->name,
				'used'     => max( 0, (int) $p->credits_total - $left ),
				'locked'   => 0,
				'left'     => $left,
				'expires'  => $p->expires_at ? wp_date( 'Y-m-d', oys_ts( $p->expires_at ) ) : '',
				'snapshot' => wp_date( 'Y-m-d', oys_ts( $p->created_at ) ),
				'source'   => $source,
			) );
			$wpdb->delete( $t, array( 'id' => (int) $p->id ) );
		}
		return $out;
	}

	/* ---------- the admin: a box on Customers, a line on the customer's page ---------- */

	private static function form( $key, $do, $inner, $button, $class = 'button', $after = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oys-inline"><input type="hidden" name="action" value="oys_held">'
			. wp_nonce_field( 'oys_held', '_wpnonce', true, false ) . '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. $inner . ' <button class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button>' . $after . '</form>';
	}

	public static function handle() {
		if ( ! current_user_can( 'oys_manage' ) || ! check_admin_referer( 'oys_held' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'olivia-studio' ), '', array( 'response' => 403 ) );
		}
		$key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$until = sanitize_text_field( wp_unslash( $_POST['until'] ?? '' ) );
		$back  = wp_get_referer() ?: admin_url( 'admin.php?page=oys-customers' );
		$say   = function ( $msg, $err = false ) use ( $back ) {
			oys_redirect( add_query_arg( array( 'oys_msg' => rawurlencode( $msg ) ) + ( $err ? array( 'oys_err' => 1 ) : array() ), remove_query_arg( array( 'oys_msg', 'oys_err' ), $back ) ) . '#held' );
		};
		// Áron, 26 November: „legyen egy ilyen tesztküldési funkció, ami önmagunknak küld egy levelet” – the guest's email to me first
		if ( ! empty( $_POST['test'] ) ) {
			$me = wp_get_current_user()->user_email;
			$ok = self::get( $key ) && OYS_Emails::held_online( $key, $me, $until );
			$say( $ok ? sprintf( __( 'Test sent to %s: this is what the guest gets.', 'olivia-studio' ), $me ) : __( 'The test could not be sent.', 'olivia-studio' ), ! $ok );
			return;
		}
		if ( 'notify' === sanitize_key( $_POST['do'] ?? '' ) ) {
			$ok = self::notify( $key );
			$say( $ok ? __( 'Email sent to the guest.', 'olivia-studio' ) : __( 'The email could not be sent (is the email „Sessions from Motibro bookable online” switched on?).', 'olivia-studio' ), ! $ok );
			return;
		}
		$res = self::act( $key, sanitize_key( $_POST['do'] ?? '' ), sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), $until );
		if ( ! is_wp_error( $res ) && 'online' === sanitize_key( $_POST['do'] ?? '' ) && ! empty( $_POST['notify'] ) ) {
			$ok = self::notify( $key );
			$say( $ok ? __( 'Bookable online, and the guest got the email.', 'olivia-studio' ) : __( 'Bookable online, but the email could not be sent.', 'olivia-studio' ), ! $ok );
			return;
		}
		oys_redirect( add_query_arg( array( 'oys_msg' => rawurlencode( is_wp_error( $res ) ? $res->get_error_message() : __( 'Saved.', 'olivia-studio' ) ) ) + ( is_wp_error( $res ) ? array( 'oys_err' => 1 ) : array() ), remove_query_arg( array( 'oys_msg', 'oys_err' ), $back ) ) . '#held' );
	}

	/** The email to the guest about their sessions now online (once more is fine: it says the same); noted on the entry. */
	public static function notify( $key ) {
		$e = self::get( $key );
		if ( ! $e || 'online' !== $e['status'] || ! OYS_Emails::held_online( $key ) ) {
			return false;
		}
		$all                    = self::all();
		$all[ $key ]['note']    = trim( $all[ $key ]['note'] . "\n" . sprintf( __( 'Email sent to the guest: %s', 'olivia-studio' ), wp_date( 'Y-m-d' ) ) );
		$all[ $key ]['mailed']  = wp_date( 'Y-m-d' );
		$all[ $key ]['updated'] = current_time( 'mysql', true );
		self::save( $all );
		return true;
	}

	private static function row( array $e, $with_name = true ) {
		$u     = get_userdata( (int) $e['user'] );
		$name  = $u ? ( trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ) : '#' . (int) $e['user'];
		$who   = $with_name ? '<a href="' . esc_url( admin_url( 'admin.php?page=oys-customers&user=' . (int) $e['user'] ) ) . '"><b>' . esc_html( $name ) . '</b></a><br>' : '';
		$state = array(
			'held'     => __( 'to redeem', 'olivia-studio' ),
			'online'   => __( 'moved online', 'olivia-studio' ),
			'redeemed' => __( 'redeemed', 'olivia-studio' ),
			'closed'   => __( 'closed', 'olivia-studio' ),
		)[ $e['status'] ] ?? $e['status'];
		$flags = ( $e['missing'] ? ' · <span class="oys-status oys-status--offered">' . esc_html__( 'not in the newer export – check in Motibro', 'olivia-studio' ) . '</span>' : '' )
			. ( $e['expires'] && $e['expires'] < wp_date( 'Y-m-d' ) ? ' · ' . esc_html__( 'expired there', 'olivia-studio' ) : '' );
		$test  = ' <button class="button-link" name="test" value="1" title="' . esc_attr( sprintf( __( 'The email the guest would get, to %s', 'olivia-studio' ), wp_get_current_user()->user_email ) ) . '">' . esc_html__( 'Test email to me', 'olivia-studio' ) . '</button>';
		$do    = 'held' === $e['status']
			? self::form( $e['key'], 'redeem', '<input name="note" placeholder="' . esc_attr__( 'What for, e.g. a private session on 3 Dec', 'olivia-studio' ) . '">', __( 'Redeemed', 'olivia-studio' ) )
				. ' ' . self::form( $e['key'], 'online', '<input type="date" name="until" value="2027-01-27"> <label class="oys-held__mail"><input type="checkbox" name="notify" value="1"> ' . esc_html__( 'email the guest', 'olivia-studio' ) . '</label>', __( 'Make bookable online', 'olivia-studio' ), 'button', $test )
			: ( 'online' === $e['status']
				? self::form( $e['key'], 'notify', '', empty( $e['mailed'] ) ? __( 'Email the guest', 'olivia-studio' ) : __( 'Email the guest again', 'olivia-studio' ), 'button button-small', $test ) . ' '
				: '' )
				. self::form( $e['key'], 'hold', '', __( 'Back to the list', 'olivia-studio' ), 'button-link' );
		return '<tr><td>' . $who . esc_html( $e['name'] ) . '<br><small>' . esc_html( sprintf( __( 'as of %1$s: used %2$d, locked there %3$d · valid there until %4$s', 'olivia-studio' ), $e['snapshot'], (int) $e['used'], (int) $e['locked'], $e['expires'] ?: '—' ) ) . $flags . '</small></td>'
			. '<td><b>' . (int) $e['left'] . '</b></td><td>' . esc_html( oys_money( self::value_cents( $e ) ) ) . '<br><small>' . esc_html( sprintf( __( '%s / session', 'olivia-studio' ), oys_money( (int) $e['per_cents'] ) ) ) . '</small></td>'
			. '<td>' . esc_html( $state ) . ( '' !== trim( $e['note'] ) ? '<br><small>' . nl2br( esc_html( trim( $e['note'] ) ) ) . '</small>' : '' ) . '</td><td>' . $do . '</td></tr>'; // phpcs:ignore
	}

	private static function table( array $entries, $with_name = true ) {
		return '<table class="widefat oys-table"><thead><tr><th>' . esc_html( $with_name ? __( 'Customer and pass', 'olivia-studio' ) : __( 'Pass', 'olivia-studio' ) ) . '</th><th>' . esc_html__( 'Sessions left', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Value', 'olivia-studio' ) . '</th><th>' . esc_html__( 'Status', 'olivia-studio' ) . '</th><th></th></tr></thead><tbody>'
			. implode( '', array_map( fn( $e ) => self::row( $e, $with_name ), $entries ) ) . '</tbody></table>';
	}

	/** On Studio → Customers: everything from the earlier system, what is still to redeem first. */
	public static function admin_box() {
		$all = array_filter( self::all(), fn( $e ) => ( (int) $e['left'] > 0 || 'held' !== $e['status'] ) && 'closed' !== $e['status'] );
		if ( ! $all ) {
			return '';
		}
		uasort( $all, fn( $a, $b ) => [ 'held' !== $a['status'], $a['expires'] ] <=> [ 'held' !== $b['status'], $b['expires'] ] );
		[ $n, $sessions, $value ] = self::totals();
		return '<details class="oys-box" id="held"' . ( $n ? ' open' : '' ) . '><summary><b>' . esc_html__( 'Passes from Motibro: sessions left to redeem', 'olivia-studio' ) . '</b> – ' . esc_html( sprintf( __( '%1$d passes, %2$d sessions, %3$s', 'olivia-studio' ), $n, $sessions, oys_money( $value ) ) ) . '</summary>'
			. '<p class="description">' . esc_html__( 'These sessions are not bookable online. If the customer writes, redeem them by their value (for example for a private session) and mark them redeemed, or make them bookable online until a day.', 'olivia-studio' ) . '</p>'
			. '<p class="description">' . esc_html__( '„Make bookable online”: what is left becomes an online pass in the guest\'s account, valid until the day you give; from then on they book the live classes with it. No email goes out unless you tick „email the guest” (or send it later with „Email the guest”). „Test email to me” sends you exactly that email first.', 'olivia-studio' ) . '</p>'
			. self::table( array_values( $all ) ) . '</details>';
	}

	/** On the customer's page, under Passes. */
	public static function customer_box( $user_id ) {
		$mine = array_values( array_filter( self::all(), fn( $e ) => (int) $e['user'] === (int) $user_id ) );
		return $mine ? '<h3 id="held">' . esc_html__( 'From Motibro (not bookable online)', 'olivia-studio' ) . '</h3>' . self::table( $mine, false ) : '';
	}

	/** For the Hub's customer card: what is still to redeem, in a line each. */
	public static function card_lines( $user_id ) {
		return array_map( fn( $e ) => array(
			'name'  => (string) $e['name'],
			'left'  => (int) $e['left'],
			'value' => oys_money( self::value_cents( $e ) ),
		), self::for_user( $user_id ) );
	}
}

OYS_Held::init();
