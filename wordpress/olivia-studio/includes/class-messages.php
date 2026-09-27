<?php
/**
 * Messages from the studio (or the class's teacher) to the people booked into a class:
 * "Message everyone" on the roster, in the calendar and in Teaching. Each message goes out as an email (with {first_name} filled in per person)
 * and is kept in the `messages` table, so the roster shows what was sent and when.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Messages {

	/**
	 * Who gets a message for a class: everyone booked (their own email), guests who gave an email,
	 * and optionally the waitlist. One entry per email address.
	 * @return array[] [ ['email', 'name', 'user_id'] ]
	 */
	public static function recipients( $session_id, $guests = true, $waitlist = false ) {
		$out = array();
		foreach ( OYS_Bookings::for_session( $session_id, array( 'confirmed', 'attended' ) ) as $b ) {
			if ( $b->guest_of ) {
				if ( $guests && is_email( $b->guest_email ) ) {
					$out[ strtolower( $b->guest_email ) ] = array( 'email' => $b->guest_email, 'name' => $b->guest_name, 'user_id' => 0 );
				}
				continue;
			}
			$u = get_userdata( $b->user_id );
			if ( $u ) {
				$out[ strtolower( $u->user_email ) ] = array( 'email' => $u->user_email, 'name' => $u->first_name ?: $u->display_name, 'user_id' => (int) $u->ID );
			}
		}
		if ( $waitlist ) {
			foreach ( OYS_Bookings::waitlist( $session_id ) as $w ) {
				$u = get_userdata( $w->user_id );
				if ( $u && ! isset( $out[ strtolower( $u->user_email ) ] ) ) {
					$out[ strtolower( $u->user_email ) ] = array( 'email' => $u->user_email, 'name' => $u->first_name ?: $u->display_name, 'user_id' => (int) $u->ID );
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Email everyone booked into a class.
	 * $opts: guests (bool, default true), waitlist (bool, default false), sender_id (int),
	 *        reply_to (email: answers go to the teacher who wrote)
	 * @return int|WP_Error how many people were emailed
	 */
	public static function send_to_session( $session_id, $subject, $body, array $opts = array() ) {
		$opts    = wp_parse_args( $opts, array( 'guests' => true, 'waitlist' => false, 'sender_id' => get_current_user_id(), 'reply_to' => '' ) );
		$session = OYS_Schedule::get( $session_id );
		$subject = trim( sanitize_text_field( $subject ) );
		$body    = trim( sanitize_textarea_field( $body ) );
		if ( ! $session ) {
			return new WP_Error( 'oys_missing', __( 'This class could not be found.', 'olivia-studio' ) );
		}
		if ( '' === $subject || '' === $body ) {
			return new WP_Error( 'oys_message', __( 'Write a subject and a message.', 'olivia-studio' ) );
		}
		$people = self::recipients( $session_id, $opts['guests'], $opts['waitlist'] );
		if ( ! $people ) {
			return new WP_Error( 'oys_message', __( 'Nobody is booked into this class yet.', 'olivia-studio' ) );
		}
		$sent = 0;
		foreach ( $people as $p ) {
			$vars = array_merge( OYS_Email_Templates::vars_for( 0, $session ), array( 'first_name' => $p['name'] ) );
			if ( OYS_Emails::class_message( $p['email'], $session, OYS_Email_Templates::fill( $subject, $vars ), OYS_Email_Templates::paragraphs( $body, $vars ), $opts['reply_to'] ) ) {
				$sent++;
			}
		}
		global $wpdb;
		$wpdb->insert( OYS_Install::table( 'messages' ), array(
			'session_id' => (int) $session_id,
			'sender_id'  => (int) $opts['sender_id'],
			'subject'    => OYS_Email_Templates::fill( $subject, OYS_Email_Templates::vars_for( 0, $session ) ),
			'body'       => $body,
			'recipients' => $sent,
			'created_at' => oys_now(),
		) );
		do_action( 'oys_class_message_sent', (int) $session_id, $subject, $body, $people );
		return $sent;
	}

	/** Messages already sent for a class, newest first. */
	public static function for_session( $session_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'messages' ) . ' WHERE session_id = %d ORDER BY id DESC', $session_id ) );
	}
}
