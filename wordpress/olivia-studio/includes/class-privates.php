<?php
/**
 * Private sessions: the customer sends a request (where, how long, when suits them),
 * Olivia answers with a concrete time and price, the customer confirms by paying
 * (card or a private-session credit). The offer is a one-person session only the
 * requesting customer can book.
 *
 * status: new → offered → booked | declined | cancelled
 */

defined( 'ABSPATH' ) || exit;

class OYS_Privates {

	public static function init() {
		add_action( 'admin_post_oys_private_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'oys_booking_confirmed', array( __CLASS__, 'on_booking_confirmed' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'private_requests' ) . ' WHERE id = %d', $id ) );
	}

	public static function for_session( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'private_requests' ) . ' WHERE session_id = %d ORDER BY id DESC LIMIT 1', $session_id ) );
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'private_requests' ) . ' WHERE user_id = %d ORDER BY created_at DESC', $user_id ) );
	}

	public static function query( $status = '' ) {
		global $wpdb;
		$t = OYS_Install::table( 'private_requests' );
		return $status ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = %s ORDER BY created_at DESC", $status ) ) : $wpdb->get_results( "SELECT * FROM $t ORDER BY FIELD(status,'new','offered','booked','declined','cancelled'), created_at DESC LIMIT 300" );
	}

	public static function update( $id, array $row ) {
		global $wpdb;
		$row['updated_at'] = oys_now();
		$wpdb->update( OYS_Install::table( 'private_requests' ), $row, array( 'id' => (int) $id ) );
	}

	public static function location_types() {
		return array(
			'home'   => __( 'My home or lanai', 'olivia-studio' ),
			'beach'  => __( 'On the beach', 'olivia-studio' ),
			'club'   => __( 'Condo / HOA clubhouse', 'olivia-studio' ),
			'office' => __( 'Office', 'olivia-studio' ),
			'online' => __( 'Online', 'olivia-studio' ),
		);
	}

	public static function handle_request() {
		check_admin_referer( 'oys_private_request' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			oys_redirect( oys_account_url() );
		}
		$types    = self::location_types();
		$type     = sanitize_key( $_POST['location_type'] ?? 'home' );
		$duration = (int) ( $_POST['duration_min'] ?? 60 );
		$row      = array(
			'user_id'       => $user_id,
			'duration_min'  => in_array( $duration, array( 60, 75, 90 ), true ) ? $duration : 60,
			'people'        => min( 10, max( 1, (int) ( $_POST['people'] ?? 1 ) ) ),
			'location_type' => isset( $types[ $type ] ) ? $type : 'home',
			'address'       => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'preferred'     => sanitize_textarea_field( wp_unslash( $_POST['preferred'] ?? '' ) ),
			'notes'         => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'status'        => 'new',
			'created_at'    => oys_now(),
		);
		if ( ! $row['preferred'] ) {
			oys_flash( __( 'Tell me a few days and times that suit you.', 'olivia-studio' ), 'error' );
			oys_redirect( wp_get_referer() ?: oys_account_url( 'private' ) );
		}
		global $wpdb;
		$wpdb->insert( OYS_Install::table( 'private_requests' ), $row );
		$request = self::get( (int) $wpdb->insert_id );
		OYS_Emails::private_request_received( $request );
		oys_flash( __( 'Request sent. I\'ll reply within one working day with a time and price.', 'olivia-studio' ) );
		oys_redirect( oys_account_url( 'private' ) );
	}

	/**
	 * Staff offers a concrete time and price. Creates (or updates) the one-person session.
	 * @return int|WP_Error session id
	 */
	public static function offer( $request_id, $starts_local, $duration_min, $price_cents, $location, $online_url = '', $message = '', $extra_min = 0 ) {
		$request = self::get( $request_id );
		if ( ! $request ) {
			return new WP_Error( 'oys_missing', __( 'Request not found.', 'olivia-studio' ) );
		}
		$start = oys_local_to_utc( $starts_local );
		if ( ! $start ) {
			return new WP_Error( 'oys_time', __( 'Choose a date and time.', 'olivia-studio' ) );
		}
		$data = array(
			'kind'            => 'private',
			'class_slug'      => 'private-yoga',
			'title'           => sprintf( __( 'Private session (%d min)', 'olivia-studio' ), $duration_min ),
			'starts_at'       => $start,
			// Extra minutes (first session: talking through goals) are blocked in the calendar, not charged.
			'ends_at'         => gmdate( 'Y-m-d H:i:s', oys_ts( $start ) + ( $duration_min + max( 0, (int) $extra_min ) ) * MINUTE_IN_SECONDS ),
			'note'            => $extra_min > 0 ? sprintf( __( 'Includes about %d extra minutes to talk through your goals.', 'olivia-studio' ), (int) $extra_min ) : '',
			'capacity'        => 1,
			'location'        => $location,
			'format'          => 'online' === $request->location_type || ( $online_url && ! $location ) ? 'online' : 'studio',
			'online_url'      => $online_url,
			'price_cents'     => $price_cents,
			// A private credit covers a 60-minute session.
			'credits_allowed' => 60 === (int) $duration_min ? 1 : 0,
			'status'          => 'scheduled',
		);
		$session_id = OYS_Schedule::save( $data, $request->session_id ?: 0 );
		self::update( $request_id, array(
			'status'        => 'offered',
			'session_id'    => $session_id,
			'price_cents'   => $price_cents,
			'duration_min'  => $duration_min,
			'admin_message' => $message,
		) );
		OYS_Emails::private_offer( self::get( $request_id ), OYS_Schedule::get( $session_id ) );
		return $session_id;
	}

	/** Is this the customer's first private session (no private session booked before)? */
	public static function is_first( $user_id, $except_request = 0 ) {
		global $wpdb;
		return ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . OYS_Install::table( 'private_requests' ) . " WHERE user_id = %d AND status = 'booked' AND id <> %d LIMIT 1", $user_id, $except_request ) );
	}

	public static function decline( $request_id, $message = '' ) {
		$request = self::get( $request_id );
		if ( ! $request ) {
			return;
		}
		self::update( $request_id, array( 'status' => 'declined', 'admin_message' => $message ) );
		if ( $request->session_id ) {
			OYS_Schedule::save( array( 'status' => 'cancelled' ), $request->session_id );
		}
		OYS_Emails::private_declined( $request, $message );
	}

	public static function mark_paid( $request_id, $order_id ) {
		self::update( $request_id, array( 'status' => 'booked', 'order_id' => $order_id ) );
	}

	/** Booked with a private credit: mark the request as booked too. */
	public static function on_booking_confirmed( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = $b ? OYS_Schedule::get( $b->session_id ) : null;
		if ( $s && 'private' === $s->kind ) {
			$r = self::for_session( $s->id );
			if ( $r && 'offered' === $r->status ) {
				self::update( $r->id, array( 'status' => 'booked' ) );
			}
		}
	}

	/** Only the customer who requested a private session may book it. */
	public static function may_book( $user_id, $session ) {
		if ( 'private' !== $session->kind ) {
			return true;
		}
		$r = self::for_session( $session->id );
		return $r && (int) $r->user_id === (int) $user_id;
	}

	public static function statuses() {
		return array(
			'new'       => __( 'New', 'olivia-studio' ),
			'offered'   => __( 'Offer sent', 'olivia-studio' ),
			'booked'    => __( 'Booked', 'olivia-studio' ),
			'declined'  => __( 'Declined', 'olivia-studio' ),
			'cancelled' => __( 'Cancelled', 'olivia-studio' ),
		);
	}
}

OYS_Privates::init();
