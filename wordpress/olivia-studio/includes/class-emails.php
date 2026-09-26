<?php
/**
 * Transactional emails in the site's look, with a calendar (.ics) attachment for bookings.
 * Use an SMTP / transactional mail plugin in production so these reach inboxes.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Emails {

	public static function send( $to, $subject, $heading, $body_html, $attachments = array(), $cta = null ) {
		$from_name = OYS_Settings::get( 'email_from_name' );
		$from      = OYS_Settings::get( 'email_from' );
		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from );
		}
		$html = self::wrap( $heading, $body_html, $cta );
		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		foreach ( $attachments as $file ) {
			if ( str_contains( $file, 'oys-tmp' ) ) {
				wp_delete_file( $file );
			}
		}
		do_action( 'oys_email_sent', $to, $subject, $html );
		return $sent;
	}

	private static function wrap( $heading, $body, $cta ) {
		$button = '';
		if ( $cta ) {
			$button = '<p style="margin:28px 0 8px"><a href="' . esc_url( $cta[1] ) . '" style="display:inline-block;background:#2B5036;color:#F3F2EC;text-decoration:none;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:13px;padding:14px 22px;border-radius:999px">' . esc_html( $cta[0] ) . '</a></p>';
		}
		return '<!doctype html><html><body style="margin:0;background:#F3F2EC;font-family:Arial,Helvetica,sans-serif;color:#26352B">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F2EC;padding:24px 12px"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FBFBF8;border:2px solid #12231A">'
			. '<tr><td style="background:#2B5036;padding:18px 24px;color:#F3F2EC;font-weight:700;font-size:20px;letter-spacing:.04em;text-transform:uppercase">' . esc_html( OYS_Settings::get( 'email_from_name' ) ) . '</td></tr>'
			. '<tr><td style="height:8px;background:#C6A3EE;font-size:0;line-height:0">&nbsp;</td></tr>'
			. '<tr><td style="padding:28px 24px 30px;font-size:16px;line-height:1.55">'
			. '<h1 style="margin:0 0 16px;font-size:26px;line-height:1.1;text-transform:uppercase;color:#12231A">' . esc_html( $heading ) . '</h1>'
			. $body . $button
			. '</td></tr><tr><td style="padding:16px 24px;border-top:2px solid #12231A;font-size:12px;color:#53635A">'
			. esc_html( get_bloginfo( 'name' ) ) . ' · <a href="' . esc_url( home_url( '/' ) ) . '" style="color:#2B5036">' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a> · <a href="' . esc_url( oys_account_url() ) . '" style="color:#2B5036">' . esc_html__( 'My account', 'olivia-studio' ) . '</a>'
			. '</td></tr></table></td></tr></table></body></html>';
	}

	private static function session_block( $session ) {
		$rows = array(
			__( 'Class', 'olivia-studio' ) => oys_session_title( $session ),
			__( 'When', 'olivia-studio' )  => oys_date( $session->starts_at, 'l, F j · g:i a' ) . ' – ' . oys_time( $session->ends_at ),
		);
		if ( $session->location ) {
			$rows[ __( 'Where', 'olivia-studio' ) ] = $session->location;
		}
		$html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-top:2px solid #12231A;margin:18px 0">';
		foreach ( $rows as $k => $v ) {
			$html .= '<tr><td style="padding:9px 12px 9px 0;border-bottom:1px solid #d8d8d0;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#53635A;width:90px;vertical-align:top">' . esc_html( $k ) . '</td><td style="padding:9px 0;border-bottom:1px solid #d8d8d0;font-weight:700">' . esc_html( $v ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	private static function user_email( $user_id ) {
		$u = get_userdata( $user_id );
		return $u ? $u->user_email : '';
	}

	private static function first_name( $user_id ) {
		$u = get_userdata( $user_id );
		return $u ? ( $u->first_name ?: $u->display_name ) : '';
	}

	/* ---------- Calendar file ---------- */

	public static function ics( $session, $booking_id ) {
		$esc   = fn( $s ) => str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), (string) $s );
		$desc  = oys_session_title( $session ) . ' with ' . OYS_Settings::get( 'email_from_name' );
		if ( $session->online_url ) {
			$desc .= "\nJoin online: " . $session->online_url;
		}
		$desc .= "\nManage your booking: " . oys_account_url();
		$lines = array(
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Olivia Studio//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:oys-booking-' . (int) $booking_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', oys_ts( $session->starts_at ) ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', oys_ts( $session->ends_at ) ),
			'SUMMARY:' . $esc( oys_session_title( $session ) . ' — ' . OYS_Settings::get( 'email_from_name' ) ),
			'LOCATION:' . $esc( $session->location ?: ( $session->online_url ? 'Online' : '' ) ),
			'DESCRIPTION:' . $esc( $desc ),
			'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:Yoga in 2 hours', 'END:VALARM',
			'END:VEVENT', 'END:VCALENDAR',
		);
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function ics_file( $session, $booking_id ) {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'oys-tmp';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' );
		}
		$file = $dir . '/yoga-' . (int) $booking_id . '-' . wp_generate_password( 8, false ) . '.ics';
		file_put_contents( $file, self::ics( $session, $booking_id ) );
		return $file;
	}

	/* ---------- Customer emails ---------- */

	public static function booking_confirmed( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		$body  = '<p>' . sprintf( esc_html__( 'Hi %s, you\'re booked. See you on the mat!', 'olivia-studio' ), esc_html( self::first_name( $b->user_id ) ) ) . '</p>';
		$body .= self::session_block( $s );
		if ( $s->online_url ) {
			$body .= '<p><b>' . esc_html__( 'Online class link:', 'olivia-studio' ) . '</b> <a href="' . esc_url( $s->online_url ) . '">' . esc_html( $s->online_url ) . '</a></p>';
		}
		if ( 'private' !== $s->kind ) {
			$body .= '<p>' . esc_html__( 'Please arrive 10 minutes early. Bring a mat if you have one, water, and a warm layer for the relaxation.', 'olivia-studio' ) . '</p>';
		}
		$body .= '<p style="font-size:14px;color:#53635A">' . esc_html( OYS_Settings::get( 'cancel_policy' ) ) . '</p>';
		self::send( self::user_email( $b->user_id ), sprintf( __( 'Booked: %s', 'olivia-studio' ), oys_session_title( $s ) . ', ' . oys_date( $s->starts_at, 'M j, g:i a' ) ), __( 'You\'re booked', 'olivia-studio' ), $body, array( self::ics_file( $s, $booking_id ) ), array( __( 'Manage booking', 'olivia-studio' ), oys_account_url() ) );
	}

	public static function booking_cancelled( $booking_id, $outcome, $by_studio = false, $reason = '' ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		if ( $by_studio ) {
			$body = '<p>' . esc_html__( 'I\'m sorry, this session has been cancelled.', 'olivia-studio' ) . ( $reason ? ' ' . esc_html( $reason ) : '' ) . '</p>';
		} else {
			$body = '<p>' . esc_html__( 'Your booking has been cancelled.', 'olivia-studio' ) . '</p>';
		}
		$body .= self::session_block( $s );
		$messages = array(
			'returned' => __( 'The class is back on your pass.', 'olivia-studio' ),
			'credit'   => sprintf( __( 'You have a class credit to use within %d days.', 'olivia-studio' ), (int) OYS_Settings::get( 'dropin_credit_days' ) ),
			'late'     => __( 'Because this was inside the cancellation window, the class counts as used.', 'olivia-studio' ),
			'none'     => '',
		);
		if ( ! empty( $messages[ $outcome ] ) ) {
			$body .= '<p><b>' . esc_html( $messages[ $outcome ] ) . '</b></p>';
		}
		self::send( self::user_email( $b->user_id ), sprintf( __( 'Cancelled: %s', 'olivia-studio' ), oys_session_title( $s ) . ', ' . oys_date( $s->starts_at, 'M j' ) ), $by_studio ? __( 'Class cancelled', 'olivia-studio' ) : __( 'Booking cancelled', 'olivia-studio' ), $body, array(), array( __( 'Book another class', 'olivia-studio' ), oys_page_url( 'book' ) ) );
		if ( ! $by_studio ) {
			self::admin_notice( sprintf( __( 'Cancellation: %s', 'olivia-studio' ), oys_session_title( $s ) ), sprintf( '%s cancelled %s (%s).', self::first_name( $b->user_id ), oys_session_title( $s ) . ' ' . oys_date( $s->starts_at ), $outcome ) );
		}
	}

	public static function waitlist_promoted( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		$body  = '<p>' . esc_html__( 'Good news: a spot opened up and you\'re in. One class was taken from your pass.', 'olivia-studio' ) . '</p>';
		$body .= self::session_block( $s );
		$body .= '<p>' . esc_html__( 'Can\'t make it after all? Cancel from your account so the next person can come.', 'olivia-studio' ) . '</p>';
		self::send( self::user_email( $b->user_id ), sprintf( __( 'You\'re in: %s', 'olivia-studio' ), oys_session_title( $s ) ), __( 'You\'re off the waitlist', 'olivia-studio' ), $body, array( self::ics_file( $s, $booking_id ) ), array( __( 'Manage booking', 'olivia-studio' ), oys_account_url() ) );
	}

	public static function waitlist_spot_open( $user_id, $session ) {
		$body  = '<p>' . esc_html__( 'A spot just opened in a class you\'re waiting for. It goes to whoever books first.', 'olivia-studio' ) . '</p>';
		$body .= self::session_block( $session );
		self::send( self::user_email( $user_id ), sprintf( __( 'A spot opened: %s', 'olivia-studio' ), oys_session_title( $session ) ), __( 'A spot is free', 'olivia-studio' ), $body, array(), array( __( 'Book now', 'olivia-studio' ), oys_book_url( $session->id ) ) );
	}

	public static function reminder( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		$body  = '<p>' . sprintf( esc_html__( 'Hi %s, a quick reminder about your class.', 'olivia-studio' ), esc_html( self::first_name( $b->user_id ) ) ) . '</p>';
		$body .= self::session_block( $s );
		if ( $s->online_url ) {
			$body .= '<p><b>' . esc_html__( 'Join online:', 'olivia-studio' ) . '</b> <a href="' . esc_url( $s->online_url ) . '">' . esc_html( $s->online_url ) . '</a></p>';
		}
		$body .= '<p>' . esc_html__( 'Can\'t come? Please cancel in your account so someone on the waitlist can take your spot.', 'olivia-studio' ) . '</p>';
		self::send( self::user_email( $b->user_id ), sprintf( __( 'Tomorrow: %s', 'olivia-studio' ), oys_session_title( $s ) . ', ' . oys_time( $s->starts_at ) ), __( 'See you soon', 'olivia-studio' ), $body, array(), array( __( 'My bookings', 'olivia-studio' ), oys_account_url() ) );
	}

	public static function pass_purchased( $order_id, $pass_id ) {
		$o = OYS_Orders::get( $order_id );
		$p = OYS_Passes::get( $pass_id );
		$body  = '<p>' . esc_html__( 'Thank you! Your pass is ready to use.', 'olivia-studio' ) . '</p>';
		$body .= '<p><b>' . esc_html( $p->name ) . '</b><br>' . sprintf( esc_html__( '%1$d classes · valid until %2$s', 'olivia-studio' ), (int) $p->credits_total, esc_html( $p->expires_at ? oys_date( $p->expires_at, get_option( 'date_format' ) ) : __( 'no expiry', 'olivia-studio' ) ) ) . '</p>';
		$body .= '<p>' . sprintf( esc_html__( 'Paid: %s', 'olivia-studio' ), esc_html( oys_money( $o->amount_cents, $o->currency ) ) ) . ( $o->receipt_url ? ' · <a href="' . esc_url( $o->receipt_url ) . '">' . esc_html__( 'Receipt', 'olivia-studio' ) . '</a>' : '' ) . '</p>';
		self::send( self::user_email( $o->user_id ), __( 'Your pass is ready', 'olivia-studio' ), __( 'Your pass is ready', 'olivia-studio' ), $body, array(), array( __( 'Book a class', 'olivia-studio' ), home_url( '/schedule-pricing/' ) ) );
	}

	public static function welcome( $user_id ) {
		$body  = '<p>' . sprintf( esc_html__( 'Hi %s, welcome! Your account is ready.', 'olivia-studio' ), esc_html( self::first_name( $user_id ) ) ) . '</p>';
		$body .= '<p>' . esc_html__( 'From your account you can book and cancel classes, see your passes and receipts, and request private sessions.', 'olivia-studio' ) . '</p>';
		self::send( self::user_email( $user_id ), __( 'Welcome to Olivia Kovács Yoga', 'olivia-studio' ), __( 'Welcome', 'olivia-studio' ), $body, array(), array( __( 'Go to my account', 'olivia-studio' ), oys_account_url() ) );
	}

	public static function gift_card( $gift ) {
		$product = OYS_Products::get( $gift->product_id );
		$from    = self::first_name( $gift->purchaser_id );
		$body    = '<p>' . sprintf( esc_html__( 'Hi %1$s, %2$s sent you a gift: %3$s.', 'olivia-studio' ), esc_html( $gift->recipient_name ?: __( 'there', 'olivia-studio' ) ), esc_html( $from ), '<b>' . esc_html( $product ? $product['name'] : $gift->product_id ) . '</b>' ) . '</p>';
		if ( $gift->message ) {
			$body .= '<blockquote style="margin:18px 0;padding:14px 18px;background:#ECE1FA;border:2px solid #12231A">' . nl2br( esc_html( $gift->message ) ) . '</blockquote>';
		}
		$body .= '<p>' . esc_html__( 'Your gift code:', 'olivia-studio' ) . '</p><p style="font-size:26px;font-weight:700;letter-spacing:.12em;background:#FF72B6;display:inline-block;padding:8px 14px;border:2px solid #12231A">' . esc_html( $gift->code ) . '</p>';
		$body .= '<p>' . esc_html__( 'Create a free account (or log in), open "Passes" and enter the code. Then book whenever you like.', 'olivia-studio' ) . '</p>';
		self::send( $gift->recipient_email, sprintf( __( '%s sent you a yoga gift', 'olivia-studio' ), $from ), __( 'A gift for you', 'olivia-studio' ), $body, array(), array( __( 'Redeem my gift', 'olivia-studio' ), oys_account_url( 'passes' ) ) );
	}

	public static function gift_receipt( $gift, $order ) {
		$body = '<p>' . sprintf( esc_html__( 'Thank you! Your gift card was sent to %s.', 'olivia-studio' ), esc_html( $gift->recipient_email ) ) . '</p>'
			. '<p>' . esc_html__( 'Code:', 'olivia-studio' ) . ' <b>' . esc_html( $gift->code ) . '</b> · ' . esc_html( oys_money( $order->amount_cents, $order->currency ) ) . ( $order->receipt_url ? ' · <a href="' . esc_url( $order->receipt_url ) . '">' . esc_html__( 'Receipt', 'olivia-studio' ) . '</a>' : '' ) . '</p>';
		self::send( self::user_email( $order->user_id ), __( 'Your gift card was sent', 'olivia-studio' ), __( 'Gift sent', 'olivia-studio' ), $body );
	}

	public static function private_request_received( $request ) {
		$body = '<p>' . esc_html__( 'Thank you for your request. I\'ll reply within one working day with a suggested time and price. You can pay and confirm from the email or your account.', 'olivia-studio' ) . '</p>';
		self::send( self::user_email( $request->user_id ), __( 'Your private session request', 'olivia-studio' ), __( 'Request received', 'olivia-studio' ), $body, array(), array( __( 'View my requests', 'olivia-studio' ), oys_account_url( 'private' ) ) );
		$u     = get_userdata( $request->user_id );
		$admin = '<p><b>' . esc_html( $u->display_name ) . '</b> (' . esc_html( $u->user_email ) . ', ' . esc_html( get_user_meta( $u->ID, 'oys_phone', true ) ) . ')</p>'
			. '<p>' . esc_html( $request->duration_min ) . ' min · ' . esc_html( $request->people ) . ' ' . esc_html__( 'people', 'olivia-studio' ) . ' · ' . esc_html( $request->location_type ) . ' · ' . esc_html( $request->address ) . '</p>'
			. '<p><b>' . esc_html__( 'Preferred times', 'olivia-studio' ) . ':</b><br>' . nl2br( esc_html( $request->preferred ) ) . '</p>'
			. '<p><b>' . esc_html__( 'Notes', 'olivia-studio' ) . ':</b><br>' . nl2br( esc_html( $request->notes ) ) . '</p>';
		self::send( OYS_Settings::get( 'notify_email' ), __( 'New private session request', 'olivia-studio' ), __( 'New private request', 'olivia-studio' ), $admin, array(), array( __( 'Open in dashboard', 'olivia-studio' ), admin_url( 'admin.php?page=oys-private&request=' . (int) $request->id ) ) );
	}

	public static function private_offer( $request, $session ) {
		$body  = '<p>' . esc_html__( 'Here is your private session. Confirm it by paying below (or with a private session credit if you have one).', 'olivia-studio' ) . '</p>';
		$body .= self::session_block( $session );
		$body .= '<p><b>' . esc_html( oys_money( $request->price_cents ) ) . '</b></p>';
		if ( $request->admin_message ) {
			$body .= '<p>' . nl2br( esc_html( $request->admin_message ) ) . '</p>';
		}
		self::send( self::user_email( $request->user_id ), __( 'Your private session: please confirm', 'olivia-studio' ), __( 'Your private session', 'olivia-studio' ), $body, array(), array( __( 'Confirm and pay', 'olivia-studio' ), oys_book_url( $session->id ) ) );
	}

	/* ---------- Studio emails ---------- */

	public static function admin_notice( $subject, $text ) {
		self::send( OYS_Settings::get( 'notify_email' ), '[Studio] ' . $subject, $subject, '<p>' . esc_html( $text ) . '</p>' );
	}

	public static function admin_new_order( $order_id ) {
		$o = OYS_Orders::get( $order_id );
		$u = get_userdata( $o->user_id );
		self::send( OYS_Settings::get( 'notify_email' ), sprintf( '[Studio] %s — %s', oys_money( $o->amount_cents, $o->currency ), $o->description ), __( 'New payment', 'olivia-studio' ),
			'<p><b>' . esc_html( $o->description ) . '</b><br>' . esc_html( $u ? $u->display_name . ' · ' . $u->user_email : '' ) . '<br>' . esc_html( oys_money( $o->amount_cents, $o->currency ) ) . '</p>',
			array(), array( __( 'Open orders', 'olivia-studio' ), admin_url( 'admin.php?page=oys-orders' ) ) );
	}
}
