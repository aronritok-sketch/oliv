<?php
/**
 * Transactional emails in the site's look, with a calendar (.ics) attachment for bookings.
 * Use an SMTP / transactional mail plugin in production so these reach inboxes.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Emails {

	public static function send( $to, $subject, $heading, $body_html, $attachments = array(), $cta = null ) {
		return self::send_raw( $to, $subject, self::wrap( $heading, $body_html, $cta ), array(), $attachments );
	}

	/** Send ready HTML (already wrapped) with the studio as the sender. */
	public static function send_raw( $to, $subject, $html, array $extra_headers = array(), $attachments = array() ) {
		$from_name = OYS_Settings::get( 'email_from_name' );
		$from      = OYS_Settings::get( 'email_from' );
		$headers   = array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), $extra_headers );
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from );
		}
		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		foreach ( $attachments as $file ) {
			if ( str_contains( $file, 'oys-tmp' ) ) {
				wp_delete_file( $file );
			}
		}
		do_action( 'oys_email_sent', $to, $subject, $html );
		return $sent;
	}

	/** A newsletter in the studio's email look, with a hidden preview line and an unsubscribe link. */
	public static function newsletter_html( $heading, $preheader, $body, $cta, $unsubscribe_url ) {
		$pre  = $preheader ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . esc_html( $preheader ) . '</div>' : '';
		$foot = '<br><a href="' . esc_url( $unsubscribe_url ) . '" style="color:#53635A">' . esc_html__( 'Unsubscribe from the newsletter', 'olivia-studio' ) . '</a>';
		return str_replace( '<body style="', '<body data-newsletter="1" style="', self::wrap( $heading, $body, $cta, $foot, $pre ) );
	}

	private static function wrap( $heading, $body, $cta, $footer_extra = '', $preheader = '' ) {
		$button = '';
		if ( $cta ) {
			$button = '<p style="margin:28px 0 8px"><a href="' . esc_url( $cta[1] ) . '" style="display:inline-block;background:#2B5036;color:#F3F2EC;text-decoration:none;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:13px;padding:14px 22px;border-radius:999px">' . esc_html( $cta[0] ) . '</a></p>';
		}
		return '<!doctype html><html><body style="margin:0;background:#F3F2EC;font-family:Arial,Helvetica,sans-serif;color:#26352B">' . $preheader
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F2EC;padding:24px 12px"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FBFBF8;border:2px solid #12231A">'
			. '<tr><td style="background:#2B5036;padding:18px 24px;color:#F3F2EC;font-weight:700;font-size:20px;letter-spacing:.04em;text-transform:uppercase">' . esc_html( OYS_Settings::get( 'email_from_name' ) ) . '</td></tr>'
			. '<tr><td style="height:8px;background:#C6A3EE;font-size:0;line-height:0">&nbsp;</td></tr>'
			. '<tr><td style="padding:28px 24px 30px;font-size:16px;line-height:1.55">'
			. '<h1 style="margin:0 0 16px;font-size:26px;line-height:1.1;text-transform:uppercase;color:#12231A">' . esc_html( $heading ) . '</h1>'
			. $body . $button
			. '</td></tr><tr><td style="padding:16px 24px;border-top:2px solid #12231A;font-size:12px;color:#53635A">'
			. esc_html( get_bloginfo( 'name' ) ) . ' · <a href="' . esc_url( home_url( '/' ) ) . '" style="color:#2B5036">' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a> · <a href="' . esc_url( oys_account_url() ) . '" style="color:#2B5036">' . esc_html__( 'My account', 'olivia-studio' ) . '</a>'
			. ( oys_fb_group_url() ? ' · <a href="' . esc_url( oys_fb_group_url() ) . '" style="color:#2B5036">' . esc_html__( 'Facebook group', 'olivia-studio' ) . '</a>' : '' )
			. $footer_extra
			. '</td></tr></table></td></tr></table></body></html>';
	}

	private static function session_block( $session ) {
		$rows = array(
			__( 'Class', 'olivia-studio' ) => oys_session_title( $session ),
			__( 'When', 'olivia-studio' )  => oys_date( $session->starts_at, 'l, F j · g:i a' ) . ' – ' . oys_time( $session->ends_at ),
		);
		if ( OYS_Teachers::name_for( $session ) ) {
			$rows[ __( 'Teacher', 'olivia-studio' ) ] = OYS_Teachers::name_for( $session );
		}
		if ( oys_is_online( $session ) ) {
			$rows[ __( 'Where', 'olivia-studio' ) ] = __( 'Online (live)', 'olivia-studio' );
		} elseif ( $session->location ) {
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
		$b     = OYS_Bookings::get( $booking_id );
		$join  = $b ? OYS_Bookings::join_link( $b, $session ) : '';
		$live  = $b && 'online' === oys_mode_for( $session, $b->mode );
		if ( $join ) {
			$desc .= "\nJoin online: " . $join;
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
			'LOCATION:' . $esc( $live ? ( $join ?: 'Online' ) : $session->location ),
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

	/** The join link (or a note that it's coming) for someone taking part online; '' in the studio. */
	private static function join_html( $booking, $session ) {
		if ( ! $booking || 'online' !== oys_mode_for( $session, $booking->mode ) ) {
			return '';
		}
		$url = OYS_Bookings::join_link( $booking, $session );
		if ( ! $url ) {
			return '<p><b>' . esc_html__( 'You\'re joining online.', 'olivia-studio' ) . '</b> ' . esc_html__( 'The link to join comes in your reminder email and is in your account before the class.', 'olivia-studio' ) . '</p>';
		}
		$html = '<p style="margin:22px 0 6px"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#C6A3EE;color:#12231A;text-decoration:none;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:13px;padding:13px 20px;border-radius:999px;border:2px solid #12231A">' . esc_html__( 'Join the live class', 'olivia-studio' ) . '</a></p>'
			. '<p style="font-size:13px;color:#53635A;word-break:break-all">' . esc_html( $url ) . '</p>';
		if ( $session->zoom_password && ! str_contains( $url, 'pwd=' ) ) {
			$html .= '<p style="font-size:13px;color:#53635A">' . esc_html__( 'Passcode:', 'olivia-studio' ) . ' <b>' . esc_html( $session->zoom_password ) . '</b></p>';
		}
		return $html . '<p style="font-size:13px;color:#53635A">' . esc_html__( 'Join a few minutes early, set up where the camera sees your mat (or leave it off), and keep your microphone muted.', 'olivia-studio' ) . '</p>';
	}

	/** "To pay at the studio: $25" for bookings that are paid on the day. */
	private static function due_html( array $rows ) {
		$due = array_sum( array_map( fn( $r ) => $r && 'door' === $r->paid_with && '' === $r->collected_with ? (int) $r->due_cents : 0, $rows ) );
		if ( ! $due ) {
			return '';
		}
		return '<p style="margin:18px 0;padding:12px 14px;background:#DEE7D6;border-left:4px solid #2B5036"><b>' . sprintf( esc_html__( 'To pay at the studio: %s', 'olivia-studio' ), esc_html( oys_money( $due ) ) ) . '</b><br>' . esc_html( OYS_Settings::get( 'pay_later_note' ) ) . '</p>';
	}

	/* ---------- Customer emails ---------- */

	/** "You + Bea, Cora" list for emails. */
	private static function guest_list_html( array $rows, $title ) {
		if ( ! $rows ) {
			return '';
		}
		$labels = OYS_Bookings::paid_with_labels();
		$html   = '<p style="margin:18px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#53635A">' . esc_html( $title ) . '</p><ul style="margin:0 0 12px;padding-left:18px">';
		foreach ( $rows as $g ) {
			$html .= '<li><b>' . esc_html( $g->guest_name ) . '</b> <span style="color:#53635A">· ' . esc_html( $labels[ $g->paid_with ] ?? $g->paid_with ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Send one of the editable emails (Studio → Emails): its message, then $blocks (the automatic
	 * details: class, guests, join link…), then its closing note, then $after. Nothing is sent when
	 * the email is switched off; attachments are removed either way.
	 */
	private static function compose( $key, $to, array $vars, $blocks = '', array $attachments = array(), $cta_url = '', $after = '', $closing = true ) {
		$t = OYS_Email_Templates::get( $key );
		if ( ! $t || ! $t['enabled'] || ! $to ) {
			foreach ( $attachments as $file ) {
				wp_delete_file( $file );
			}
			return false;
		}
		$body = OYS_Email_Templates::paragraphs( $t['message'], $vars ) . $blocks . ( $closing ? OYS_Email_Templates::paragraphs( $t['closing'], $vars ) : '' ) . $after;
		$cta  = $t['button'] && $cta_url ? array( OYS_Email_Templates::fill( $t['button'], $vars ), $cta_url ) : null;
		return self::send( $to, OYS_Email_Templates::fill( $t['subject'], $vars ), OYS_Email_Templates::fill( $t['heading'], $vars ), $body, $attachments, $cta );
	}

	private static function policy_html() {
		return '<p style="font-size:14px;color:#53635A">' . esc_html( OYS_Settings::get( 'cancel_policy' ) ) . '</p>';
	}

	/**
	 * $ids = the rows just confirmed (the customer's own and/or guests'). When only guests were
	 * added, the email says so.
	 */
	public static function booking_confirmed( $booking_id, array $ids = array() ) {
		$host = OYS_Bookings::get( $booking_id );
		if ( ! $host ) {
			return;
		}
		$s      = OYS_Schedule::get( $host->session_id );
		$ids    = $ids ?: array( (int) $host->id );
		$rows   = array_map( array( 'OYS_Bookings', 'get' ), $ids );
		$new_g  = array_values( array_filter( $rows, fn( $r ) => $r && $r->guest_of ) );
		$only_g = ! in_array( (int) $host->id, array_map( 'intval', $ids ), true );
		$all_g  = OYS_Bookings::guests_of( $host->id, array( 'confirmed' ) );
		$key    = $only_g ? 'guests_added' : 'booking_confirmed';
		$online = 'online' === oys_mode_for( $s, $host->mode );
		$blocks = self::session_block( $s )
			. self::guest_list_html( $all_g, sprintf( _n( 'Your guest (%d)', 'Your guests (%d)', count( $all_g ), 'olivia-studio' ), count( $all_g ) ) )
			. self::due_html( array_merge( array( $host ), $all_g ) )
			. self::join_html( $host, $s );
		if ( OYS_Email_Templates::enabled( $key ) ) {
			self::compose( $key, self::user_email( $host->user_id ), OYS_Email_Templates::vars_for( $host->user_id, $s ), $blocks, array( self::ics_file( $s, $host->id ) ), oys_account_url(), self::policy_html(), ! $online && 'private' !== $s->kind );
		}
		foreach ( $new_g as $g ) {
			if ( $g->guest_email ) {
				self::guest_invite( $g, $s );
			}
		}
		self::teacher_notice( $s, sprintf( __( 'New booking: %s', 'olivia-studio' ), oys_session_title( $s ) . ', ' . oys_date( $s->starts_at, 'D M j, g:i a' ) ),
			sprintf( _n( '%1$s booked (%2$d person). %3$d of %4$d spots are taken.', '%1$s booked (%2$d people). %3$d of %4$d spots are taken.', count( $rows ), 'olivia-studio' ), OYS_Bookings::person_label( $host ), count( $rows ), (int) $s->booked, (int) $s->capacity ) );
	}

	/** A guest who gave an email gets the details and a calendar invite (no account needed). */
	public static function guest_invite( $g, $s ) {
		if ( ! OYS_Email_Templates::enabled( 'guest_invite' ) ) {
			return;
		}
		$vars = array_merge( OYS_Email_Templates::vars_for( 0, $s ), array( 'guest_name' => $g->guest_name, 'host' => self::first_name( $g->user_id ), 'first_name' => $g->guest_name ) );
		$note = 'online' === oys_mode_for( $s, $g->mode ) ? '' : '<p>' . esc_html__( 'Please arrive 10 minutes early.', 'olivia-studio' ) . '</p>';
		self::compose( 'guest_invite', $g->guest_email, $vars, self::session_block( $s ) . self::join_html( $g, $s ) . $note, array( self::ics_file( $s, $g->id ) ), home_url( '/' ), '<p style="font-size:14px;color:#53635A">' . esc_html( OYS_Settings::get( 'waiver_text' ) ) . '</p>' );
	}

	/**
	 * $ids = every row cancelled together (the customer's own and their guests').
	 */
	public static function booking_cancelled( $booking_id, $outcome, $by_studio = false, $reason = '', array $ids = array(), $key = '', $extra = '' ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		$rows   = array_filter( array_map( array( 'OYS_Bookings', 'get' ), $ids ?: array( $booking_id ) ) );
		$guests = array_values( array_filter( $rows, fn( $r ) => $r->guest_of ) );
		$vars   = array_merge( OYS_Email_Templates::vars_for( $b->user_id, $s ), array( 'reason' => $reason ) );
		$blocks = '';
		if ( ! $by_studio && $b->guest_of ) {
			$blocks .= '<p>' . sprintf( esc_html__( 'Your guest %s has been removed from this class.', 'olivia-studio' ), '<b>' . esc_html( $b->guest_name ) . '</b>' ) . '</p>';
		}
		$blocks .= self::session_block( $s );
		if ( $guests && ! $b->guest_of ) {
			$blocks .= self::guest_list_html( $guests, __( 'Guests cancelled with you', 'olivia-studio' ) );
		}
		$messages = array(
			'returned'   => count( $rows ) > 1 ? __( 'The classes are back on your pass.', 'olivia-studio' ) : __( 'The class is back on your pass.', 'olivia-studio' ),
			'credit'     => sprintf( __( 'You have a class credit to use within %d days.', 'olivia-studio' ), (int) OYS_Settings::get( 'dropin_credit_days' ) ),
			'membership' => __( 'This class won\'t count towards your membership.', 'olivia-studio' ),
			'refunded'   => __( 'Your card payment is being refunded; it usually shows on your statement within 5–10 days.', 'olivia-studio' ),
			'late'       => __( 'Because this was inside the cancellation window, the class counts as used.', 'olivia-studio' ),
			'none'       => '',
		);
		if ( ! empty( $messages[ $outcome ] ) ) {
			$blocks .= '<p><b>' . esc_html( $messages[ $outcome ] ) . '</b></p>';
		}
		self::compose( $key ?: ( $by_studio ? 'class_cancelled' : 'booking_cancelled' ), self::user_email( $b->user_id ), $vars, $blocks . $extra, array(), oys_page_url( 'book' ) );
		foreach ( $rows as $r ) {
			if ( $r->guest_of && $r->guest_email ) {
				self::compose( 'guest_cancelled', $r->guest_email, array_merge( $vars, array( 'guest_name' => $r->guest_name, 'first_name' => $r->guest_name ) ), self::session_block( $s ) . $extra );
			}
		}
		if ( ! $by_studio ) {
			self::teacher_notice( $s, sprintf( __( 'Cancellation: %s', 'olivia-studio' ), oys_session_title( $s ) . ', ' . oys_date( $s->starts_at, 'D M j, g:i a' ) ), sprintf( _n( '%1$s cancelled (%2$d person).', '%1$s cancelled (%2$d people).', count( $rows ), 'olivia-studio' ), self::first_name( $b->user_id ), count( $rows ) ) );
			self::admin_notice( sprintf( __( 'Cancellation: %s', 'olivia-studio' ), oys_session_title( $s ) ), sprintf( '%s cancelled %s (%d %s, %s).', self::first_name( $b->user_id ), oys_session_title( $s ) . ' ' . oys_date( $s->starts_at ), count( $rows ), _n( 'person', 'people', count( $rows ), 'olivia-studio' ), $outcome ), 'studio_cancellation' );
		}
	}

	public static function waitlist_promoted( $booking_id ) {
		if ( ! OYS_Email_Templates::enabled( 'waitlist_promoted' ) ) {
			return;
		}
		$b    = OYS_Bookings::get( $booking_id );
		$s    = OYS_Schedule::get( $b->session_id );
		$vars = array_merge( OYS_Email_Templates::vars_for( $b->user_id, $s ), array( 'how' => 'membership' === $b->paid_with ? __( 'It\'s covered by your membership.', 'olivia-studio' ) : __( 'One class was taken from your pass.', 'olivia-studio' ) ) );
		self::compose( 'waitlist_promoted', self::user_email( $b->user_id ), $vars, self::session_block( $s ) . self::join_html( $b, $s ), array( self::ics_file( $s, $booking_id ) ), oys_account_url() );
	}

	public static function waitlist_spot_open( $user_id, $session ) {
		self::compose( 'waitlist_spot_open', self::user_email( $user_id ), OYS_Email_Templates::vars_for( $user_id, $session ), self::session_block( $session ), array(), oys_book_url( $session->id ) );
	}

	/** The studio moved a class (new time, day or place): to the person who booked, or to a guest with an email. */
	public static function session_changed( $booking, $session, $before ) {
		if ( ! OYS_Email_Templates::enabled( 'session_changed' ) ) {
			return;
		}
		$guest  = (bool) $booking->guest_of;
		$to     = $guest ? $booking->guest_email : self::user_email( $booking->user_id );
		$vars   = OYS_Email_Templates::vars_for( $booking->user_id, $session );
		if ( $guest ) {
			$vars['first_name'] = $booking->guest_name;
		}
		$blocks = '<p style="color:#53635A">' . esc_html__( 'Before:', 'olivia-studio' ) . ' <s>' . esc_html( oys_date( $before->starts_at, 'l, F j · g:i a' ) . ( oys_is_online( $before ) ? ' · ' . __( 'Online', 'olivia-studio' ) : ( $before->location ? ' · ' . $before->location : '' ) ) ) . '</s></p>'
			. '<p><b>' . esc_html__( 'Now:', 'olivia-studio' ) . '</b></p>' . self::session_block( $session ) . self::join_html( $booking, $session );
		self::compose( 'session_changed', $to, $vars, $blocks, array( self::ics_file( $session, $booking->id ) ), $guest ? '' : oys_account_url(), '', ! $guest );
	}

	public static function reminder( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		$blocks = self::session_block( $s ) . self::guest_list_html( OYS_Bookings::guests_of( $b->id, array( 'confirmed' ) ), __( 'Coming with you', 'olivia-studio' ) ) . self::join_html( $b, $s );
		return self::compose( 'reminder', self::user_email( $b->user_id ), OYS_Email_Templates::vars_for( $b->user_id, $s ), $blocks, array(), oys_account_url() );
	}

	/** Shortly before an online or hybrid class: the join link, to the customer or a guest with an email. */
	public static function join_reminder( $booking_id ) {
		$b = OYS_Bookings::get( $booking_id );
		$s = OYS_Schedule::get( $b->session_id );
		$link = OYS_Bookings::join_link( $b, $s );
		if ( ! $link ) {
			return false;
		}
		$vars = OYS_Email_Templates::vars_for( $b->user_id, $s );
		if ( $b->guest_of ) {
			$vars['first_name'] = $b->guest_name;
		}
		return self::compose( 'join_reminder', $b->guest_of ? $b->guest_email : self::user_email( $b->user_id ), $vars, self::join_html( $b, $s ), array(), '', '', true );
	}

	/** Unused classes on a pass are about to expire. */
	public static function pass_expiring( $pass_id ) {
		$p = OYS_Passes::get( $pass_id );
		if ( ! $p ) {
			return false;
		}
		$vars = array_merge( OYS_Email_Templates::vars_for( $p->user_id ), array(
			'pass'         => $p->name,
			'classes_left' => sprintf( _n( '%d class', '%d classes', (int) $p->credits_left, 'olivia-studio' ), (int) $p->credits_left ),
			'expires'      => oys_date( $p->expires_at, get_option( 'date_format' ) ),
		) );
		return self::compose( 'pass_expiring', self::user_email( $p->user_id ), $vars, '', array(), home_url( '/schedule-pricing/' ) );
	}

	public static function pass_purchased( $order_id, $pass_id ) {
		$o      = OYS_Orders::get( $order_id );
		$p      = OYS_Passes::get( $pass_id );
		$blocks = '<p><b>' . esc_html( $p->name ) . '</b><br>' . sprintf( esc_html__( '%1$d classes · valid until %2$s', 'olivia-studio' ), (int) $p->credits_total, esc_html( $p->expires_at ? oys_date( $p->expires_at, get_option( 'date_format' ) ) : __( 'no expiry', 'olivia-studio' ) ) ) . '</p>'
			. '<p>' . sprintf( esc_html__( 'Paid: %s', 'olivia-studio' ), esc_html( oys_money( $o->amount_cents, $o->currency ) ) ) . ( $o->receipt_url ? ' · <a href="' . esc_url( $o->receipt_url ) . '">' . esc_html__( 'Receipt', 'olivia-studio' ) . '</a>' : '' ) . '</p>';
		self::compose( 'pass_purchased', self::user_email( $o->user_id ), array_merge( OYS_Email_Templates::vars_for( $o->user_id ), array( 'pass' => $p->name ) ), $blocks, array(), home_url( '/schedule-pricing/' ) );
	}

	public static function welcome( $user_id ) {
		self::compose( 'welcome', self::user_email( $user_id ), OYS_Email_Templates::vars_for( $user_id ), '', array(), oys_account_url() );
	}

	public static function gift_card( $gift ) {
		$product = OYS_Products::get( $gift->product_id );
		$vars    = array_merge( OYS_Email_Templates::vars_for(), array(
			'recipient'  => $gift->recipient_name ?: __( 'there', 'olivia-studio' ),
			'first_name' => $gift->recipient_name ?: __( 'there', 'olivia-studio' ),
			'from'       => self::first_name( $gift->purchaser_id ),
			'gift'       => $product ? $product['name'] : $gift->product_id,
		) );
		$blocks = ( $gift->message ? '<blockquote style="margin:18px 0;padding:14px 18px;background:#ECE1FA;border:2px solid #12231A">' . nl2br( esc_html( $gift->message ) ) . '</blockquote>' : '' )
			. '<p>' . esc_html__( 'Your gift code:', 'olivia-studio' ) . '</p><p style="font-size:26px;font-weight:700;letter-spacing:.12em;background:#FF72B6;display:inline-block;padding:8px 14px;border:2px solid #12231A">' . esc_html( $gift->code ) . '</p>';
		self::compose( 'gift_card', $gift->recipient_email, $vars, $blocks, array(), oys_account_url( 'passes' ) );
	}

	public static function gift_receipt( $gift, $order ) {
		$blocks = '<p>' . esc_html__( 'Code:', 'olivia-studio' ) . ' <b>' . esc_html( $gift->code ) . '</b> · ' . esc_html( oys_money( $order->amount_cents, $order->currency ) ) . ( $order->receipt_url ? ' · <a href="' . esc_url( $order->receipt_url ) . '">' . esc_html__( 'Receipt', 'olivia-studio' ) . '</a>' : '' ) . '</p>';
		self::compose( 'gift_receipt', self::user_email( $order->user_id ), array_merge( OYS_Email_Templates::vars_for( $order->user_id ), array( 'recipient_email' => $gift->recipient_email ) ), $blocks );
	}

	public static function private_request_received( $request ) {
		self::compose( 'private_request_received', self::user_email( $request->user_id ), OYS_Email_Templates::vars_for( $request->user_id ), '', array(), oys_account_url( 'private' ) );
		if ( ! OYS_Email_Templates::enabled( 'studio_private' ) ) {
			return;
		}
		$u     = get_userdata( $request->user_id );
		$admin = '<p><b>' . esc_html( $u->display_name ) . '</b> (' . esc_html( $u->user_email ) . ', ' . esc_html( get_user_meta( $u->ID, 'oys_phone', true ) ) . ')</p>'
			. '<p>' . esc_html( $request->duration_min ) . ' min · ' . esc_html( $request->people ) . ' ' . esc_html__( 'people', 'olivia-studio' ) . ' · ' . esc_html( $request->location_type ) . ' · ' . esc_html( $request->address ) . '</p>'
			. '<p><b>' . esc_html__( 'Preferred times', 'olivia-studio' ) . ':</b><br>' . nl2br( esc_html( $request->preferred ) ) . '</p>'
			. '<p><b>' . esc_html__( 'Notes', 'olivia-studio' ) . ':</b><br>' . nl2br( esc_html( $request->notes ) ) . '</p>';
		self::send( OYS_Settings::get( 'notify_email' ), __( 'New private session request', 'olivia-studio' ), __( 'New private request', 'olivia-studio' ), $admin, array(), array( __( 'Open in dashboard', 'olivia-studio' ), admin_url( 'admin.php?page=oys-private&request=' . (int) $request->id ) ) );
	}

	public static function private_offer( $request, $session ) {
		$blocks = self::session_block( $session ) . '<p><b>' . esc_html( oys_money( $request->price_cents ) ) . '</b></p>'
			. ( $request->admin_message ? '<p>' . nl2br( esc_html( $request->admin_message ) ) . '</p>' : '' )
			. ( $session->note ? '<p><b>' . esc_html( $session->note ) . '</b></p>' : '' )
			. ( OYS_Settings::get( 'private_note' ) ? '<p style="font-size:14px;color:#53635A">' . esc_html( OYS_Settings::get( 'private_note' ) ) . '</p>' : '' );
		self::compose( 'private_offer', self::user_email( $request->user_id ), OYS_Email_Templates::vars_for( $request->user_id, $session ), $blocks, array(), oys_book_url( $session->id ) );
	}

	public static function private_declined( $request, $message = '' ) {
		self::compose( 'private_declined', self::user_email( $request->user_id ), OYS_Email_Templates::vars_for( $request->user_id ), $message ? '<p>' . nl2br( esc_html( $message ) ) . '</p>' : '' );
	}

	/* ---------- Memberships ---------- */

	private static function membership_block( $m ) {
		$p     = OYS_Products::get( $m->product_id );
		$price = $p ? oys_money( $p['price_cents'] ) . ' ' . OYS_Products::period_label( $p ) : '';
		$what  = (int) $m->classes_per_period ? sprintf( _n( '%d group class per period', '%d group classes per period', (int) $m->classes_per_period, 'olivia-studio' ), (int) $m->classes_per_period ) : __( 'Unlimited group classes', 'olivia-studio' );
		return '<p><b>' . esc_html( $m->name ) . '</b><br>' . esc_html( $what ) . ( $price ? ' · ' . esc_html( $price ) : '' ) . '</p>';
	}

	public static function membership_started( $membership_id ) {
		$m      = OYS_Memberships::get( $membership_id );
		$blocks = self::membership_block( $m ) . ( $m->current_period_end ? '<p>' . sprintf( esc_html__( 'Next renewal: %s.', 'olivia-studio' ), esc_html( oys_date( $m->current_period_end, get_option( 'date_format' ) ) ) ) . '</p>' : '' );
		self::compose( 'membership_started', self::user_email( $m->user_id ), OYS_Email_Templates::vars_for( $m->user_id ), $blocks, array(), oys_page_url( 'book' ) );
		self::admin_notice( __( 'New membership', 'olivia-studio' ), sprintf( '%s joined: %s', self::first_name( $m->user_id ), $m->name ), 'studio_membership' );
	}

	public static function membership_cancel_scheduled( $membership_id ) {
		$m    = OYS_Memberships::get( $membership_id );
		$vars = array_merge( OYS_Email_Templates::vars_for( $m->user_id ), array( 'ends' => oys_date( $m->current_period_end, get_option( 'date_format' ) ) ) );
		self::compose( 'membership_cancel_scheduled', self::user_email( $m->user_id ), $vars, self::membership_block( $m ), array(), oys_account_url( 'membership' ) );
		self::admin_notice( __( 'Membership cancelled', 'olivia-studio' ), sprintf( '%s cancelled %s (ends %s).', self::first_name( $m->user_id ), $m->name, oys_date( $m->current_period_end ) ), 'studio_membership' );
	}

	public static function membership_ended( $membership_id ) {
		$m = OYS_Memberships::get( $membership_id );
		self::compose( 'membership_ended', self::user_email( $m->user_id ), OYS_Email_Templates::vars_for( $m->user_id ), self::membership_block( $m ), array(), home_url( '/schedule-pricing/' ) );
	}

	public static function membership_payment_failed( $membership_id, $invoice_url = '' ) {
		$m      = OYS_Memberships::get( $membership_id );
		$blocks = self::membership_block( $m ) . ( $invoice_url ? '<p><a href="' . esc_url( $invoice_url ) . '">' . esc_html__( 'Pay the invoice now', 'olivia-studio' ) . '</a></p>' : '' );
		self::compose( 'membership_payment_failed', self::user_email( $m->user_id ), OYS_Email_Templates::vars_for( $m->user_id ), $blocks, array(), oys_account_url( 'membership' ) );
		self::admin_notice( __( 'Membership payment failed', 'olivia-studio' ), sprintf( '%s — %s', self::first_name( $m->user_id ), $m->name ), 'studio_membership' );
	}

	/* ---------- Studio emails ---------- */

	/** A note to the studio; $type is its switch in Studio → Emails. */
	/** Birthday code (from the `birthday` template). */
	public static function birthday( $user_id, $coupon ) {
		$vars   = array_merge( OYS_Email_Templates::vars_for( $user_id ), array(
			'percent' => (int) $coupon->value . '%',
			'code'    => $coupon->code,
			'expires' => $coupon->expires_at ? oys_date( $coupon->expires_at, 'F j' ) : '',
		) );
		$blocks = '<p style="margin:22px 0;padding:16px;text-align:center;background:#DEE7D6;border:2px dashed #2B5036;font-size:22px;font-weight:700;letter-spacing:.12em">' . esc_html( $coupon->code ) . '</p>'
			. '<p style="font-size:14px;color:#53635A">' . esc_html__( 'It\'s filled in for you when you buy a pass or pay for a class by card while logged in.', 'olivia-studio' ) . '</p>';
		return self::compose( 'birthday', self::user_email( $user_id ), $vars, $blocks, array(), oys_page_url( 'book' ) );
	}

	/** The loyalty draw's winner (from the `raffle_winner` template). */
	public static function raffle_winner( $user_id, $product, $tickets, $period ) {
		$vars = array_merge( OYS_Email_Templates::vars_for( $user_id ), array(
			'prize'   => $product['name'] ?? __( 'a free pass', 'olivia-studio' ),
			'tickets' => $tickets,
			'period'  => $period,
		) );
		return self::compose( 'raffle_winner', self::user_email( $user_id ), $vars, '', array(), oys_account_url( 'passes' ) );
	}

	/** "Bring a friend": the class needs $missing more people by the decision time. */
	public static function minimum_nudge( $booking, $session, $missing, $decide_at ) {
		$vars = array_merge( OYS_Email_Templates::vars_for( $booking->user_id, $session ), array(
			'missing'  => sprintf( _n( '%d more person', '%d more people', $missing, 'olivia-studio' ), $missing ),
			'deadline' => wp_date( 'l g:i a', $decide_at ),
		) );
		$blocks = self::session_block( $session ) . '<p style="font-size:14px;color:#53635A">' . esc_html__( 'Share this link with a friend, or add them as your guest from your booking:', 'olivia-studio' ) . '<br><a href="' . esc_url( oys_book_url( $session->id ) ) . '" style="color:#2B5036;word-break:break-all">' . esc_html( oys_book_url( $session->id ) ) . '</a></p>';
		return self::compose( 'minimum_nudge', self::user_email( $booking->user_id ), $vars, $blocks, array(), oys_book_url( $session->id ) );
	}

	/** A message the studio wrote to everyone in a class (roster → "Message everyone"). */
	public static function class_message( $to, $session, $subject, $body_html, $reply_to = '' ) {
		$headers = is_email( $reply_to ) ? array( 'Reply-To: ' . $reply_to ) : array();
		return self::send_raw( $to, $subject, self::wrap( $subject, $body_html . self::session_block( $session ), array( __( 'My bookings', 'olivia-studio' ), oys_account_url() ) ), $headers );
	}

	public static function admin_notice( $subject, $text, $type = 'studio_alerts' ) {
		if ( ! OYS_Email_Templates::enabled( $type ) ) {
			return false;
		}
		return self::send( OYS_Settings::get( 'notify_email' ), '[Studio] ' . $subject, $subject, '<p>' . esc_html( $text ) . '</p>' );
	}

	/** A short note to the teacher of a class (if it has one and they want these emails). */
	public static function teacher_notice( $session, $subject, $text ) {
		$t = OYS_Teachers::for_session( $session );
		if ( ! $t || ! (int) $t->notify || ! is_email( $t->email ) ) {
			return false;
		}
		return self::send( $t->email, '[' . OYS_Settings::get( 'email_from_name' ) . '] ' . $subject, $subject, '<p>' . esc_html( $text ) . '</p>' . self::session_block( $session ), array(), array( __( 'Open your classes', 'olivia-studio' ), admin_url( 'admin.php?page=oys-teach&session=' . (int) $session->id ) ) );
	}

	/** Welcome for a new teacher login: set a password, then Teaching in the dashboard. */
	public static function teacher_access( $t, $password_url ) {
		$studio = OYS_Settings::get( 'email_from_name' );
		$body   = '<p>' . sprintf( esc_html__( 'Hi %s,', 'olivia-studio' ), esc_html( strtok( $t->name, ' ' ) ) ) . '</p>'
			. '<p>' . sprintf( esc_html__( 'You now have a teacher login at %s. There you can see who is booked into your classes, write to them, keep your profile and photos up to date, connect your Stripe account for card payments and see your monthly statement.', 'olivia-studio' ), esc_html( $studio ) ) . '</p>'
			. '<p>' . esc_html__( 'Set your password with the button below, then log in. Your classes are under Teaching.', 'olivia-studio' ) . '</p>';
		return self::send( $t->email, sprintf( __( 'Your teacher login at %s', 'olivia-studio' ), $studio ), __( 'Welcome to the team', 'olivia-studio' ), $body, array(), array( __( 'Set your password', 'olivia-studio' ), $password_url ) );
	}

	public static function admin_new_order( $order_id ) {
		if ( ! OYS_Email_Templates::enabled( 'studio_payment' ) ) {
			return;
		}
		$o = OYS_Orders::get( $order_id );
		$u = get_userdata( $o->user_id );
		self::send( OYS_Settings::get( 'notify_email' ), sprintf( '[Studio] %s — %s', oys_money( $o->amount_cents, $o->currency ), $o->description ), __( 'New payment', 'olivia-studio' ),
			'<p><b>' . esc_html( $o->description ) . '</b><br>' . esc_html( $u ? $u->display_name . ' · ' . $u->user_email : '' ) . '<br>' . esc_html( oys_money( $o->amount_cents, $o->currency ) ) . '</p>'
			. ( ! empty( $o->meta['stripe_account'] ) ? '<p>' . esc_html( sprintf( __( 'Paid to the teacher\'s own Stripe account; your fee: %s.', 'olivia-studio' ), oys_money( (int) ( $o->meta['app_fee_cents'] ?? 0 ), $o->currency ) ) ) . '</p>' : '' ),
			array(), array( __( 'Open orders', 'olivia-studio' ), admin_url( 'admin.php?page=oys-orders' ) ) );
	}

	/* ---------- Preview and test (Studio → Emails) ---------- */

	/** Sample values for previews and test emails. */
	public static function sample_vars() {
		$user = wp_get_current_user();
		return array(
			'first_name' => $user && $user->first_name ? $user->first_name : 'Emma', 'studio' => OYS_Settings::get( 'email_from_name' ),
			'class' => 'Slow Flow', 'date' => wp_date( 'l, F j', time() + 2 * DAY_IN_SECONDS ), 'date_short' => wp_date( 'M j', time() + 2 * DAY_IN_SECONDS ),
			'day' => wp_date( 'D', time() + 2 * DAY_IN_SECONDS ), 'time' => '6:00 pm', 'location' => 'Fort Myers', 'teacher' => OYS_Settings::get( 'owner_name' ), 'host' => 'Emma', 'guest_name' => 'Sofia',
			'reason' => 'Olivia is unwell.', 'how' => 'One class was taken from your pass.', 'pass' => '5-class pass', 'classes_left' => '2 classes',
			'expires' => wp_date( get_option( 'date_format' ), time() + 7 * DAY_IN_SECONDS ), 'recipient' => 'Sofia', 'from' => 'Emma', 'gift' => '5-class pass',
			'recipient_email' => 'sofia@example.com', 'ends' => wp_date( get_option( 'date_format' ), time() + 20 * DAY_IN_SECONDS ),
		);
	}

	/** The full email with sample values: [ subject, html ]. $t overrides the saved texts (unsaved edits). */
	public static function preview( $key, array $t = array() ) {
		$t    = array_merge( OYS_Email_Templates::get( $key ), $t );
		$vars = self::sample_vars();
		$demo = (object) array( 'title' => '', 'class_slug' => 'slow-flow', 'starts_at' => gmdate( 'Y-m-d 22:00:00', time() + 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'Y-m-d 23:00:00', time() + 2 * DAY_IN_SECONDS ), 'location' => 'Fort Myers', 'format' => 'studio' );
		$details = in_array( $key, array( 'booking_confirmed', 'guests_added', 'guest_invite', 'booking_cancelled', 'class_cancelled', 'guest_cancelled', 'session_changed', 'waitlist_promoted', 'waitlist_spot_open', 'reminder', 'private_offer' ), true )
			? self::session_block( $demo ) : '';
		if ( 'join_reminder' === $key ) {
			$details = '<p style="margin:22px 0 6px"><a href="#" style="display:inline-block;background:#C6A3EE;color:#12231A;text-decoration:none;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:13px;padding:13px 20px;border-radius:999px;border:2px solid #12231A">' . esc_html__( 'Join the live class', 'olivia-studio' ) . '</a></p>';
		}
		$body = OYS_Email_Templates::paragraphs( $t['message'], $vars ) . $details . OYS_Email_Templates::paragraphs( $t['closing'], $vars );
		$cta  = $t['button'] ? array( OYS_Email_Templates::fill( $t['button'], $vars ), home_url( '/' ) ) : null;
		return array( OYS_Email_Templates::fill( $t['subject'], $vars ), self::wrap( OYS_Email_Templates::fill( $t['heading'], $vars ), $body, $cta ) );
	}

	public static function send_test( $key, $to ) {
		[ $subject, $html ] = self::preview( $key );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( OYS_Settings::get( 'email_from' ) ) {
			$headers[] = sprintf( 'From: %s <%s>', OYS_Settings::get( 'email_from_name' ), OYS_Settings::get( 'email_from' ) );
		}
		$sent = wp_mail( $to, '[Test] ' . $subject, $html, $headers );
		do_action( 'oys_email_sent', $to, '[Test] ' . $subject, $html );
		return $sent;
	}
}
