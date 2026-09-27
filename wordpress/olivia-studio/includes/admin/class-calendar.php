<?php
/**
 * The admin calendar: a week view of every class, event and private session, where the
 * studio adds, edits, moves (drag and drop), repeats and cancels sessions.
 *
 * The page is rendered by assets/calendar.js and talks to these REST routes
 * (cookie auth + wp_rest nonce, `oys_manage` capability):
 *
 *   GET  /oys/v1/admin/calendar?from=Y-m-d&days=7   sessions of the week, in studio local time
 *   POST /oys/v1/admin/sessions                      create (optionally as a new weekly class)
 *   POST /oys/v1/admin/sessions/{id}                 update one date, or this and the following
 *                                                    dates of its weekly class (scope=series)
 *   POST /oys/v1/admin/sessions/{id}/cancel          cancel one date or the rest of the series
 */

defined( 'ABSPATH' ) || exit;

class OYS_Calendar {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$perm = fn() => current_user_can( 'oys_manage' );
		register_rest_route( 'oys/v1', '/admin/calendar', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_week' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( 'oys/v1', '/admin/sessions', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_save' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( 'oys/v1', '/admin/sessions/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_save' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( 'oys/v1', '/admin/sessions/(?P<id>\d+)/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_cancel' ),
			'permission_callback' => $perm,
		) );
	}

	/* ---------- Page ---------- */

	public static function enqueue() {
		wp_enqueue_style( 'oys-calendar', OYS_URL . 'assets/calendar.css', array(), OYS_VERSION );
		wp_enqueue_script( 'oys-calendar', OYS_URL . 'assets/calendar.js', array(), OYS_VERSION, true );
		wp_localize_script( 'oys-calendar', 'OYS_CAL', array(
			'rest'      => esc_url_raw( rest_url( 'oys/v1/admin/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'today'     => wp_date( 'Y-m-d' ),
			'week'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['week'] ?? '' ) ? $_GET['week'] : '',
			'open'      => (int) ( $_GET['open'] ?? 0 ),
			'classes'   => oys_class_options(),
			'currency'  => strtoupper( OYS_Settings::get( 'currency' ) ),
			'prices'    => array(
				'group'  => (int) apply_filters( 'oys_dropin_display_price', 2500 ),
				'online' => (int) OYS_Settings::get( 'online_price_cents' ),
			),
			'zoom'      => OYS_Zoom::enabled(),
			'donationMin' => (int) OYS_Settings::get( 'donation_min_cents' ),
			'payLater'  => OYS_Settings::get( 'pay_later' ),
			'locations' => array_values( OYS_Locations::all() ),
			'minDefault'    => (int) OYS_Settings::get( 'min_people_default' ),
			'decideDefault' => (int) OYS_Settings::get( 'decide_hours_default' ),
			'locationsUrl' => admin_url( 'admin.php?page=oys-locations' ),
			'teachers'  => array_map( fn( $t ) => array( 'id' => (int) $t->id, 'name' => $t->name ), OYS_Teachers::all( true ) ),
			'ownerName' => OYS_Settings::get( 'owner_name' ),
			'teachersUrl' => admin_url( 'admin.php?page=oys-teachers' ),
			'rosterUrl' => admin_url( 'admin.php?page=oys-schedule&session=' ),
			'listUrl'   => admin_url( 'admin.php?page=oys-schedule&view=list' ),
			'bookUrl'   => oys_page_url( 'book', array( 'session' => '' ) ),
		) );
	}

	public static function page() {
		echo '<div class="wrap oys-wrap oys-cal-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Calendar', 'olivia-studio' ) . '</h1>'
			. ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=oys-schedule&view=list' ) ) . '">' . esc_html__( 'List view', 'olivia-studio' ) . '</a>'
			. '<hr class="wp-header-end"><div id="oys-cal" class="oys-cal" aria-live="polite"><p class="oys-cal__loading">' . esc_html__( 'Loading the calendar…', 'olivia-studio' ) . '</p></div>'
			. '<noscript><p>' . esc_html__( 'The calendar needs JavaScript. Use the list view instead.', 'olivia-studio' ) . '</p></noscript></div>';
	}

	/* ---------- Reading ---------- */

	public static function rest_week( WP_REST_Request $req ) {
		$tz   = wp_timezone();
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'from' ) ) ? $req->get_param( 'from' ) : wp_date( 'Y-m-d' );
		$days = min( 31, max( 1, (int) ( $req->get_param( 'days' ) ?: 7 ) ) );
		$a    = new DateTimeImmutable( $from . ' 00:00', $tz );
		$b    = $a->modify( "+$days days" );
		$utc  = new DateTimeZone( 'UTC' );
		$rows = OYS_Schedule::query( array(
			'from' => $a->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to'   => $b->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		) );
		return rest_ensure_response( array(
			'from'     => $from,
			'days'     => $days,
			'sessions' => array_map( array( __CLASS__, 'out' ), $rows ),
		) );
	}

	/** A session as the calendar sees it (studio local time, minutes, cents). */
	public static function out( $s ) {
		$tz     = wp_timezone();
		$start  = ( new DateTimeImmutable( $s->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$people = array();
		$online = 0;
		foreach ( OYS_Bookings::for_session( $s->id, array( 'confirmed', 'attended', 'no_show' ) ) as $b ) {
			$online += oys_is_hybrid( $s ) && 'online' === $b->mode ? 1 : 0;
			$label    = $b->guest_of ? sprintf( __( '%s (guest)', 'olivia-studio' ), $b->guest_name ) : OYS_Bookings::person_label( $b );
			$people[] = oys_is_hybrid( $s ) && 'online' === $b->mode ? $label . ' · ' . __( 'online', 'olivia-studio' ) : $label;
		}
		$tpl    = $s->template_id ? OYS_Schedule::template( $s->template_id ) : null;
		$series = null;
		if ( $tpl ) {
			$series = array(
				'id'      => (int) $tpl->id,
				'active'  => (bool) $tpl->active,
				'label'   => sprintf( __( 'Every %1$s at %2$s', 'olivia-studio' ), OYS_Schedule::weekdays()[ (int) $tpl->weekday ] ?? '', wp_date( get_option( 'time_format' ), strtotime( '2020-01-01 ' . $tpl->start_time . ' UTC' ), new DateTimeZone( 'UTC' ) ) ),
			);
		}
		return array(
			'id'              => (int) $s->id,
			'kind'            => $s->kind,
			'class_slug'      => $s->class_slug,
			'title'           => $s->title,
			'label'           => oys_session_title( $s ),
			'description'     => (string) $s->description,
			'date'            => $start->format( 'Y-m-d' ),
			'start'           => $start->format( 'H:i' ),
			'duration'        => (int) round( ( oys_ts( $s->ends_at ) - oys_ts( $s->starts_at ) ) / 60 ),
			'capacity'        => (int) $s->capacity,
			'booked'          => count( $people ) - $online,
			'held'            => max( 0, (int) $s->booked - ( count( $people ) - $online ) ),
			'waitlist'        => count( OYS_Bookings::waitlist( $s->id ) ),
			'people'          => $people,
			'location'        => $s->location,
			'format'          => $s->format ?: 'studio',
			'online_url'      => $s->online_url,
			'price'           => (int) $s->price_cents,
			'online_capacity' => (int) $s->online_capacity,
			'online_booked'   => $online,
			'online_price'    => (int) $s->online_price_cents,
			'zoom'            => OYS_Zoom::has_meeting( $s ) ? array( 'id' => $s->zoom_meeting_id, 'join' => $s->zoom_join_url, 'start' => wp_nonce_url( admin_url( 'admin-post.php?action=oys_admin_zoom_start&session=' . (int) $s->id ), 'oys_admin_zoom_start' ) ) : null,
			'credits_allowed' => (bool) $s->credits_allowed,
			'pricing'         => $s->pricing ?: 'fixed',
			'pay_later'       => (bool) $s->pay_later,
			'min_people'      => null === $s->min_people ? null : (int) $s->min_people,
			'decide_hours'    => null === $s->decide_hours ? null : (int) $s->decide_hours,
			'minimum'         => self::minimum_out( $s ),
			'due'             => OYS_Bookings::due_at_studio( $s->id ),
			'teacher_id'      => (int) $s->teacher_id,
			'teacher'         => OYS_Teachers::name_for( $s ),
			'note'            => $s->note,
			'status'          => $s->status,
			'past'            => oys_ts( $s->ends_at ) < time(),
			'series'          => $series,
		);
	}

	/** The go-ahead rule as the calendar shows it. */
	private static function minimum_out( $s ) {
		[ $people, $hours ] = OYS_Locations::rule( $s );
		$at = OYS_Locations::decide_at( $s );
		return array(
			'people' => $people,
			'hours'  => $hours,
			'state'  => (int) $s->min_state,
			'at'     => $at ? wp_date( 'D g:i a', $at ) : '',
			'short'  => $people && 'scheduled' === $s->status && ! $s->min_state && OYS_Locations::people( $s ) < $people,
		);
	}

	/* ---------- Writing ---------- */

	/** Validated session fields from the request, or WP_Error. */
	private static function input( WP_REST_Request $req, $current = null ) {
		$p     = $req->get_json_params() ?: $req->get_params();
		$kinds = OYS_Schedule::kinds();
		$kind  = sanitize_key( $p['kind'] ?? ( $current->kind ?? 'group' ) );
		$kind  = isset( $kinds[ $kind ] ) ? $kind : 'group';
		$date  = (string) ( $p['date'] ?? '' );
		$time  = (string) ( $p['start'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new WP_Error( 'oys_time', __( 'Choose a date and a start time.', 'olivia-studio' ), array( 'status' => 400 ) );
		}
		$slug  = sanitize_title( $p['class_slug'] ?? '' );
		$title = sanitize_text_field( $p['title'] ?? '' );
		if ( 'group' === $kind && ! $slug ) {
			return new WP_Error( 'oys_class', __( 'Choose which class this is.', 'olivia-studio' ), array( 'status' => 400 ) );
		}
		if ( 'group' !== $kind && ! $slug && ! $title ) {
			return new WP_Error( 'oys_title', __( 'Give the session a title.', 'olivia-studio' ), array( 'status' => 400 ) );
		}
		$dur    = min( 600, max( 15, (int) ( $p['duration'] ?? 60 ) ) );
		$start  = oys_local_to_utc( $date . 'T' . $time );
		$format = in_array( $p['format'] ?? '', array( 'online', 'hybrid' ), true ) ? $p['format'] : 'studio';
		return array(
			'kind'            => $kind,
			'class_slug'      => $slug,
			'title'           => $title,
			'description'     => sanitize_textarea_field( $p['description'] ?? '' ),
			'starts_at'       => $start,
			'ends_at'         => gmdate( 'Y-m-d H:i:s', oys_ts( $start ) + $dur * MINUTE_IN_SECONDS ),
			'capacity'        => max( 1, (int) ( $p['capacity'] ?? 12 ) ),
			'location'        => sanitize_text_field( $p['location'] ?? '' ),
			'format'          => $format,
			'online_url'      => esc_url_raw( $p['online_url'] ?? '' ),
			'price_cents'     => max( 0, (int) ( $p['price'] ?? 0 ) ),
			'online_capacity'    => 'hybrid' === $format ? max( 0, (int) ( $p['online_capacity'] ?? 0 ) ) : 0,
			'online_price_cents' => 'hybrid' === $format ? max( 0, (int) ( $p['online_price'] ?? OYS_Settings::get( 'online_price_cents' ) ) ) : 0,
			'credits_allowed' => array_key_exists( 'credits_allowed', $p ) ? ( empty( $p['credits_allowed'] ) ? 0 : 1 ) : 1,
			'pricing'         => 'donation' === ( $p['pricing'] ?? ( $current->pricing ?? 'fixed' ) ) ? 'donation' : 'fixed',
			'pay_later'       => array_key_exists( 'pay_later', $p ) ? ( empty( $p['pay_later'] ) ? 0 : 1 ) : (int) ( $current->pay_later ?? 1 ),
			'min_people'      => array_key_exists( 'min_people', $p ) ? OYS_Schedule::nullable_int( $p['min_people'] ) : ( $current->min_people ?? null ),
			'decide_hours'    => array_key_exists( 'decide_hours', $p ) ? OYS_Schedule::nullable_int( $p['decide_hours'], 1 ) : ( $current->decide_hours ?? null ),
			'teacher_id'      => array_key_exists( 'teacher_id', $p ) ? OYS_Teachers::valid_id( $p['teacher_id'] ) : (int) ( $current->teacher_id ?? 0 ),
			'note'            => sanitize_text_field( $p['note'] ?? '' ),
			// Helpers for the caller, not columns.
			'_date'           => $date,
			'_time'           => $time,
			'_dur'            => $dur,
		);
	}

	private static function columns( array $d ) {
		return array_diff_key( $d, array_flip( array( '_date', '_time', '_dur' ) ) );
	}

	public static function rest_save( WP_REST_Request $req ) {
		$id      = (int) $req->get_param( 'id' );
		$current = $id ? OYS_Schedule::get( $id ) : null;
		if ( $id && ! $current ) {
			return new WP_Error( 'oys_missing', __( 'This session no longer exists.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$d = self::input( $req, $current );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$p      = $req->get_json_params() ?: $req->get_params();
		$notify = ! empty( $p['notify'] );
		$note   = '';

		if ( ! $current ) {
			if ( oys_ts( $d['starts_at'] ) < time() - HOUR_IN_SECONDS ) {
				return new WP_Error( 'oys_past', __( 'That time has already passed.', 'olivia-studio' ), array( 'status' => 400 ) );
			}
			if ( 'weekly' === ( $p['repeat'] ?? '' ) && 'group' === $d['kind'] ) {
				$tpl = OYS_Schedule::save_template( self::template_fields( $d, true ) );
				OYS_Schedule::generate();
				global $wpdb;
				$sid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . OYS_Install::table( 'sessions' ) . ' WHERE template_id = %d ORDER BY starts_at LIMIT 1', $tpl ) );
				// Credits and description aren't part of a weekly template; keep what was entered on the dates.
				$wpdb->update( OYS_Install::table( 'sessions' ), array( 'credits_allowed' => $d['credits_allowed'], 'description' => $d['description'] ), array( 'template_id' => $tpl ) );
				return self::done( $sid, sprintf( __( 'Weekly class created: %d dates added.', 'olivia-studio' ), self::count_series( $tpl ) ) );
			}
			$sid = OYS_Schedule::save( array_merge( self::columns( $d ), array( 'status' => 'scheduled' ) ) );
			return self::done( $sid, __( 'Session created.', 'olivia-studio' ) );
		}

		$booked = (int) $current->booked;
		if ( $d['capacity'] < $booked ) {
			$d['capacity'] = $booked;
			$note          = sprintf( __( 'Capacity kept at %d: that many people are already booked.', 'olivia-studio' ), $booked );
		}

		if ( 'series' === ( $p['scope'] ?? '' ) && $current->template_id ) {
			$n = self::update_series( $current, $d, $notify );
			return self::done( $id, trim( sprintf( _n( 'Weekly class updated (%d date).', 'Weekly class updated (%d dates).', $n, 'olivia-studio' ), $n ) . ' ' . $note ) );
		}

		$moved = $d['starts_at'] !== $current->starts_at || $d['ends_at'] !== $current->ends_at || $d['location'] !== $current->location || $d['format'] !== ( $current->format ?: 'studio' ) || $d['online_url'] !== $current->online_url;
		OYS_Schedule::save( self::columns( $d ), $id );
		$sent = $moved && $notify ? self::notify_change( $id, $current ) : 0;
		$msg  = __( 'Saved.', 'olivia-studio' );
		if ( $sent ) {
			$msg .= ' ' . sprintf( _n( '%d person was emailed.', '%d people were emailed.', $sent, 'olivia-studio' ), $sent );
		}
		return self::done( $id, trim( $msg . ' ' . $note ) );
	}

	private static function done( $session_id, $message ) {
		return rest_ensure_response( array( 'ok' => true, 'message' => $message, 'session' => self::out( OYS_Schedule::get( $session_id ) ) ) );
	}

	private static function template_fields( array $d, $new = false ) {
		$f = array(
			'class_slug'   => $d['class_slug'],
			'weekday'      => (int) ( new DateTimeImmutable( $d['_date'], wp_timezone() ) )->format( 'N' ),
			'start_time'   => $d['_time'],
			'duration_min' => $d['_dur'],
			'capacity'     => $d['capacity'],
			'location'     => $d['location'],
			'format'       => $d['format'],
			'online_url'   => $d['online_url'],
			'price_cents'  => $d['price_cents'],
			'online_capacity'    => $d['online_capacity'],
			'online_price_cents' => $d['online_price_cents'],
			'pricing'      => $d['pricing'],
			'pay_later'    => $d['pay_later'],
			'min_people'   => $d['min_people'],
			'decide_hours' => $d['decide_hours'],
			'teacher_id'   => $d['teacher_id'],
			'note'         => $d['note'],
			'active'       => 1,
		);
		if ( $new ) {
			$f['valid_from'] = $d['_date'];
		}
		return $f;
	}

	private static function count_series( $template_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OYS_Install::table( 'sessions' ) . " WHERE template_id = %d AND status = 'scheduled' AND starts_at >= %s", $template_id, oys_now() ) );
	}

	/**
	 * Change a weekly class from this date on: the template and every following date move to the
	 * new weekday and time (keeping each date's week), and take the new details.
	 * @return int dates changed
	 */
	private static function update_series( $current, array $d, $notify ) {
		global $wpdb;
		$tz       = wp_timezone();
		$old_day  = new DateTimeImmutable( wp_date( 'Y-m-d', oys_ts( $current->starts_at ) ), $tz );
		$new_day  = new DateTimeImmutable( $d['_date'], $tz );
		$shift    = (int) $old_day->diff( $new_day )->format( '%r%a' );
		$tpl      = OYS_Schedule::template( $current->template_id );
		$fields   = self::template_fields( $d );
		$fields['valid_from'] = $tpl ? $tpl->valid_from : null;
		OYS_Schedule::save_template( $fields, (int) $current->template_id );

		$t     = OYS_Install::table( 'sessions' );
		$dates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE template_id = %d AND starts_at >= %s ORDER BY starts_at", $current->template_id, $current->starts_at ) );
		$n     = 0;
		foreach ( $dates as $s ) {
			$local = ( new DateTimeImmutable( wp_date( 'Y-m-d', oys_ts( $s->starts_at ) ), $tz ) )->modify( ( $shift >= 0 ? '+' : '' ) . $shift . ' days' );
			$start = oys_local_to_utc( $local->format( 'Y-m-d' ) . 'T' . $d['_time'] );
			$row   = array_merge( self::columns( $d ), array(
				'starts_at' => $start,
				'ends_at'   => gmdate( 'Y-m-d H:i:s', oys_ts( $start ) + $d['_dur'] * MINUTE_IN_SECONDS ),
				'tpl_slot'  => $start,
				'capacity'  => max( $d['capacity'], 'scheduled' === $s->status ? (int) $s->booked : 0 ),
			) );
			unset( $row['description'] );
			if ( 'scheduled' !== $s->status ) {
				// Cancelled dates only follow the new slot, so they aren't re-created at the new time.
				$row = array( 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'tpl_slot' => $start );
			}
			$moved = $start !== $s->starts_at || $row['ends_at'] !== $s->ends_at || ( isset( $row['location'] ) && ( $row['location'] !== $s->location || $row['format'] !== ( $s->format ?: 'studio' ) || $row['online_url'] !== $s->online_url ) );
			OYS_Schedule::save( $row, $s->id );
			if ( 'scheduled' === $s->status ) {
				$n++;
				if ( $moved && $notify ) {
					self::notify_change( $s->id, $s );
				}
			}
		}
		OYS_Schedule::generate();
		return $n;
	}

	/** Email everyone booked (the person who booked, and guests with an email) about a new time or place. */
	private static function notify_change( $session_id, $before ) {
		$s    = OYS_Schedule::get( $session_id );
		$sent = 0;
		if ( oys_ts( $s->ends_at ) < time() ) {
			return 0;
		}
		foreach ( OYS_Bookings::for_session( $session_id, array( 'confirmed' ) ) as $b ) {
			if ( $b->guest_of && ! $b->guest_email ) {
				continue;
			}
			OYS_Emails::session_changed( $b, $s, $before );
			$sent++;
		}
		return $sent;
	}

	public static function rest_cancel( WP_REST_Request $req ) {
		$s = OYS_Schedule::get( (int) $req->get_param( 'id' ) );
		if ( ! $s ) {
			return new WP_Error( 'oys_missing', __( 'This session no longer exists.', 'olivia-studio' ), array( 'status' => 404 ) );
		}
		$p      = $req->get_json_params() ?: $req->get_params();
		$reason = sanitize_text_field( $p['reason'] ?? '' );
		if ( 'series' === ( $p['scope'] ?? '' ) && $s->template_id ) {
			global $wpdb;
			$wpdb->update( OYS_Install::table( 'templates' ), array( 'active' => 0 ), array( 'id' => $s->template_id ) );
			$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . OYS_Install::table( 'sessions' ) . " WHERE template_id = %d AND status = 'scheduled' AND starts_at >= %s", $s->template_id, $s->starts_at ) );
			$people = 0;
			foreach ( $ids as $id ) {
				$people += OYS_Schedule::cancel_session( $id, $reason );
			}
			return rest_ensure_response( array( 'ok' => true, 'message' => sprintf( _n( 'Weekly class stopped: %1$d date cancelled', 'Weekly class stopped: %1$d dates cancelled', count( $ids ), 'olivia-studio' ), count( $ids ) ) . ', ' . sprintf( _n( '%d booking cancelled and emailed.', '%d bookings cancelled and emailed.', $people, 'olivia-studio' ), $people ), 'session' => self::out( OYS_Schedule::get( $s->id ) ) ) );
		}
		$people = OYS_Schedule::cancel_session( $s->id, $reason );
		return rest_ensure_response( array( 'ok' => true, 'message' => sprintf( _n( 'Session cancelled; %d booking was cancelled and emailed.', 'Session cancelled; %d bookings were cancelled and emailed.', $people, 'olivia-studio' ), $people ), 'session' => self::out( OYS_Schedule::get( $s->id ) ) ) );
	}
}
