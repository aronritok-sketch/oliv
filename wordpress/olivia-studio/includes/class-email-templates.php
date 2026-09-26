<?php
/**
 * The texts of every automatic email, editable in Studio → Emails.
 *
 * Each email has a subject, a heading, a message (shown before the automatic details such as
 * the class, guests or join link), an optional closing note and the button label. They can
 * use placeholders like {first_name} or {class}; unknown placeholders are left as they are.
 * Every email can be switched off. Saved texts live in the `oys_email_templates` option;
 * anything not saved falls back to the defaults below.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Email_Templates {

	const OPTION = 'oys_email_templates';

	const COMMON = array( 'first_name', 'studio', 'class', 'date', 'date_short', 'day', 'time', 'location' );

	/**
	 * key => [ label, group, to (customer|guest|studio), when, subject, heading, message, closing, button, vars ]
	 * Studio notices only have label/group/to/when (no editable text).
	 */
	public static function registry() {
		$r = array(
			// Bookings
			'booking_confirmed' => array( __( 'Booking confirmation', 'olivia-studio' ), 'bookings', 'customer', __( 'Right after booking or paying; with a calendar file and the join link for online', 'olivia-studio' ),
				'Booked: {class}, {date_short}', "You're booked", "Hi {first_name}, you're booked. See you on the mat!", 'Please arrive 10 minutes early. Bring a mat if you have one, water, and a warm layer for the relaxation.', 'Manage booking' ),
			'guests_added'      => array( __( 'Guests added', 'olivia-studio' ), 'bookings', 'customer', __( 'When someone adds guests to their booking later', 'olivia-studio' ),
				'Guests added: {class}, {date_short}', 'Guests added', 'Hi {first_name}, your guests are booked in with you.', '', 'Manage booking' ),
			'guest_invite'      => array( __( 'Invitation to a guest', 'olivia-studio' ), 'bookings', 'guest', __( 'To a guest who was given an email address', 'olivia-studio' ),
				'{host} booked you into {class}', "You're coming to yoga", 'Hi {guest_name}, {host} booked you a spot. See you on the mat!', 'New to yoga? Tell Olivia before class about any injuries or health conditions.', 'About the classes', array( 'guest_name', 'host' ) ),
			'booking_cancelled' => array( __( 'Booking cancelled by the customer', 'olivia-studio' ), 'bookings', 'customer', __( 'When someone cancels their booking or removes a guest', 'olivia-studio' ),
				'Cancelled: {class}, {date_short}', 'Booking cancelled', 'Your booking has been cancelled.', '', 'Book another class' ),
			'class_cancelled'   => array( __( 'Class cancelled by the studio', 'olivia-studio' ), 'bookings', 'customer', __( 'When you cancel a class or a booking; the reason you type is added', 'olivia-studio' ),
				'Cancelled: {class}, {date_short}', 'Class cancelled', "I'm sorry, this session has been cancelled. {reason}", '', 'Book another class', array( 'reason' ) ),
			'guest_cancelled'   => array( __( 'Cancellation to a guest', 'olivia-studio' ), 'bookings', 'guest', __( 'To a guest with an email when their spot is cancelled', 'olivia-studio' ),
				'Cancelled: {class}, {date_short}', 'Class cancelled', 'Hi {guest_name}, your spot in this class has been cancelled.', '', '', array( 'guest_name' ) ),
			'session_changed'   => array( __( 'Class time or place changed', 'olivia-studio' ), 'bookings', 'customer', __( 'When you move a class in the calendar and tick "email the people booked"', 'olivia-studio' ),
				'Changed: {class}, {day} {date_short}, {time}', 'Your class has changed', "Hi {first_name}, there's a change to your class. Your spot is kept, nothing to do if the new details work for you.", "Can't make the new time? Cancel in your account and your class goes back on your pass (or you get a class credit).", 'My bookings' ),
			'waitlist_promoted' => array( __( 'Moved in from the waitlist', 'olivia-studio' ), 'bookings', 'customer', __( 'When a spot opens and someone with a pass or membership is booked in automatically', 'olivia-studio' ),
				"You're in: {class}, {date_short}", "You're off the waitlist", "Good news: a spot opened up and you're in. {how}", "Can't make it after all? Cancel from your account so the next person can come.", 'Manage booking', array( 'how' ) ),
			'waitlist_spot_open' => array( __( 'A spot opened (waitlist)', 'olivia-studio' ), 'bookings', 'customer', __( "When a spot opens for someone on the waitlist who can't be booked in automatically", 'olivia-studio' ),
				'A spot opened: {class}, {date_short}', 'A spot is free', "A spot just opened in a class you're waiting for. It goes to whoever books first.", '', 'Book now' ),
			// Reminders
			'reminder'          => array( __( 'Class reminder', 'olivia-studio' ), 'reminders', 'customer', __( 'Before the class, at the times set under Reminders', 'olivia-studio' ),
				'Reminder: {class}, {day} {time}', 'See you soon', 'Hi {first_name}, a quick reminder about your class.', "Can't come? Please cancel in your account so someone on the waitlist can take your spot.", 'My bookings' ),
			'join_reminder'     => array( __( 'Online: link before the class', 'olivia-studio' ), 'reminders', 'customer', __( 'To everyone joining online, shortly before the class starts', 'olivia-studio' ),
				'Starting soon: {class} (live online), {time}', 'Your class starts soon', "Hi {first_name}, the live class starts at {time}. Here's your link to join.", 'Set up where the camera sees your mat (or keep it off) and keep your microphone muted.', '' ),
			'pass_expiring'     => array( __( 'Pass about to expire', 'olivia-studio' ), 'reminders', 'customer', __( 'When unused classes on a pass are about to expire (days set under Reminders)', 'olivia-studio' ),
				'Your {pass} expires on {expires}', 'Use your classes', "Hi {first_name}, you still have {classes_left} on your {pass}, valid until {expires}. Book a class so they don't go to waste.", '', 'Book a class', array( 'pass', 'classes_left', 'expires' ) ),
			// Passes, gifts, account
			'welcome'           => array( __( 'Welcome (new account)', 'olivia-studio' ), 'account', 'customer', __( 'When someone creates an account', 'olivia-studio' ),
				'Welcome to {studio}', 'Welcome', 'Hi {first_name}, welcome! Your account is ready.', 'From your account you can book and cancel classes, see your passes and receipts, and request private sessions.', 'Go to my account' ),
			'pass_purchased'    => array( __( 'Pass bought', 'olivia-studio' ), 'account', 'customer', __( 'After buying a class pass', 'olivia-studio' ),
				'Your pass is ready', 'Your pass is ready', 'Thank you! Your pass is ready to use.', '', 'Book a class', array( 'pass' ) ),
			'gift_card'         => array( __( 'Gift card to the recipient', 'olivia-studio' ), 'account', 'guest', __( 'To the person who receives a gift card', 'olivia-studio' ),
				'{from} sent you a yoga gift', 'A gift for you', 'Hi {recipient}, {from} sent you a gift: {gift}.', 'Create a free account (or log in), open "Passes" and enter the code. Then book whenever you like.', 'Redeem my gift', array( 'recipient', 'from', 'gift' ) ),
			'gift_receipt'      => array( __( 'Gift card receipt', 'olivia-studio' ), 'account', 'customer', __( 'To the buyer of a gift card', 'olivia-studio' ),
				'Your gift card was sent', 'Gift sent', 'Thank you! Your gift card was sent to {recipient_email}.', '', '', array( 'recipient_email' ) ),
			// Private sessions
			'private_request_received' => array( __( 'Private request received', 'olivia-studio' ), 'private', 'customer', __( 'When someone sends a private session request', 'olivia-studio' ),
				'Your private session request', 'Request received', "Thank you for your request. I'll reply within one working day with a suggested time and price. You can pay and confirm from the email or your account.", '', 'View my requests' ),
			'private_offer'     => array( __( 'Private session offer', 'olivia-studio' ), 'private', 'customer', __( 'When you send an offer with a time and price', 'olivia-studio' ),
				'Your private session: please confirm', 'Your private session', 'Here is your private session. Confirm it by paying below (or with a private session credit if you have one).', '', 'Confirm and pay' ),
			'private_declined'  => array( __( 'Private request declined', 'olivia-studio' ), 'private', 'customer', __( 'When you decline a request; your message is added', 'olivia-studio' ),
				'About your private session request', 'About your request', "Thank you for your request. Unfortunately I can't offer a session for it this time.", '', '' ),
			// Memberships
			'membership_started' => array( __( 'Membership started', 'olivia-studio' ), 'membership', 'customer', __( 'When a membership becomes active', 'olivia-studio' ),
				'Your membership is active', 'Welcome, member', 'Welcome to the membership, {first_name}! You can book group classes straight away: choose "Use my membership" when you book.', 'You can cancel any time in your account; you keep access until the end of the paid period.', 'Book a class' ),
			'membership_cancel_scheduled' => array( __( 'Membership will end', 'olivia-studio' ), 'membership', 'customer', __( 'When a membership is cancelled at the end of the period', 'olivia-studio' ),
				'Your membership will end', 'Membership cancelled', "Your membership won't renew. You can keep booking classes until {ends}.", 'Changed your mind? Resume it in your account before that date.', 'Resume membership', array( 'ends' ) ),
			'membership_ended'  => array( __( 'Membership ended', 'olivia-studio' ), 'membership', 'customer', __( 'When a membership has ended', 'olivia-studio' ),
				'Your membership has ended', 'Membership ended', 'Your membership has ended. Thank you for practicing with me! You can join again or buy a class pass any time.', '', 'See prices' ),
			'membership_payment_failed' => array( __( 'Membership payment failed', 'olivia-studio' ), 'membership', 'customer', __( 'When a monthly payment is declined', 'olivia-studio' ),
				'Action needed: membership payment failed', 'Payment failed', "We couldn't take this month's membership payment. Your card may have expired or been declined. Stripe will try again over the next few days; please update your card so your membership continues.", '', 'Update my card' ),
			// Studio notifications (on/off only)
			'studio_payment'      => array( __( 'New payment', 'olivia-studio' ), 'studio', 'studio', __( 'Every payment received', 'olivia-studio' ) ),
			'studio_private'      => array( __( 'New private request', 'olivia-studio' ), 'studio', 'studio', __( 'Every private session request', 'olivia-studio' ) ),
			'studio_cancellation' => array( __( 'Customer cancellations', 'olivia-studio' ), 'studio', 'studio', __( 'When a customer cancels', 'olivia-studio' ) ),
			'studio_membership'   => array( __( 'Memberships', 'olivia-studio' ), 'studio', 'studio', __( 'New, cancelled and failed memberships', 'olivia-studio' ) ),
			'studio_alerts'       => array( __( 'Problems that need you', 'olivia-studio' ), 'studio', 'studio', __( 'A class over capacity, a Zoom meeting that could not be created', 'olivia-studio' ) ),
		);
		$out = array();
		foreach ( $r as $key => $d ) {
			$out[ $key ] = array(
				'label'    => $d[0],
				'group'    => $d[1],
				'to'       => $d[2],
				'when'     => $d[3],
				'editable' => 'studio' !== $d[2],
				'subject'  => $d[4] ?? '',
				'heading'  => $d[5] ?? '',
				'message'  => $d[6] ?? '',
				'closing'  => $d[7] ?? '',
				'button'   => $d[8] ?? '',
				'vars'     => array_merge( self::COMMON, $d[9] ?? array() ),
			);
		}
		return apply_filters( 'oys_email_templates', $out );
	}

	public static function groups() {
		return array(
			'bookings'   => __( 'Bookings', 'olivia-studio' ),
			'reminders'  => __( 'Reminders', 'olivia-studio' ),
			'account'    => __( 'Account, passes and gifts', 'olivia-studio' ),
			'private'    => __( 'Private sessions', 'olivia-studio' ),
			'membership' => __( 'Membership', 'olivia-studio' ),
			'studio'     => __( 'Notifications to the studio', 'olivia-studio' ),
		);
	}

	/** The email as it will be sent: saved texts over the defaults, plus `enabled`. */
	public static function get( $key ) {
		$all = self::registry();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) && isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
		$t     = $all[ $key ];
		foreach ( array( 'subject', 'heading', 'message', 'closing', 'button' ) as $f ) {
			if ( isset( $saved[ $f ] ) && ( '' !== trim( $saved[ $f ] ) || in_array( $f, array( 'closing', 'button' ), true ) ) ) {
				$t[ $f ] = $saved[ $f ];
			}
		}
		$t['enabled']    = ! isset( $saved['enabled'] ) || (int) $saved['enabled'];
		$t['customized'] = (bool) array_intersect_key( $saved, array_flip( array( 'subject', 'heading', 'message', 'closing', 'button' ) ) );
		return $t;
	}

	public static function enabled( $key ) {
		$t = self::get( $key );
		return $t && $t['enabled'];
	}

	public static function save( $key, array $fields ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved[ $key ] = array_merge( $saved[ $key ] ?? array(), $fields );
		update_option( self::OPTION, $saved, false );
	}

	/** Back to the default texts (keeps on/off). */
	public static function reset( $key ) {
		$saved = get_option( self::OPTION, array() );
		if ( isset( $saved[ $key ] ) ) {
			$saved[ $key ] = array_intersect_key( $saved[ $key ], array( 'enabled' => 1 ) );
			update_option( self::OPTION, $saved, false );
		}
	}

	/** Placeholder values that most emails share. */
	public static function vars_for( $user_id = 0, $session = null ) {
		$v = array( 'studio' => OYS_Settings::get( 'email_from_name' ) );
		if ( $user_id ) {
			$u               = get_userdata( $user_id );
			$v['first_name'] = $u ? ( $u->first_name ?: $u->display_name ) : '';
		}
		if ( $session ) {
			$v['class']      = oys_session_title( $session );
			$v['date']       = oys_date( $session->starts_at, 'l, F j' );
			$v['date_short'] = oys_date( $session->starts_at, 'M j' );
			$v['day']        = oys_date( $session->starts_at, 'D' );
			$v['time']       = oys_time( $session->starts_at );
			$v['location']   = oys_is_online( $session ) ? __( 'Online', 'olivia-studio' ) : (string) $session->location;
		}
		return $v;
	}

	/** Fill {placeholders}. */
	public static function fill( $text, array $vars ) {
		return trim( preg_replace_callback( '/\{([a-z_]+)\}/', fn( $m ) => array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0], (string) $text ) );
	}

	/** Plain text with blank lines between paragraphs → HTML paragraphs (escaped). */
	public static function paragraphs( $text, array $vars ) {
		$text = self::fill( $text, $vars );
		if ( '' === $text ) {
			return '';
		}
		$out = '';
		foreach ( preg_split( "/\n\s*\n/", str_replace( "\r", '', $text ) ) as $p ) {
			$out .= '<p>' . nl2br( esc_html( trim( $p ) ) ) . '</p>';
		}
		return $out;
	}
}
