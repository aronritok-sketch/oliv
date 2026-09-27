<?php
/**
 * Newsletters to the customers who ticked "Email me the newsletter" (user meta oys_marketing).
 *
 * Written in Studio → Newsletter (by hand or drafted with AI, see OYS_AI), previewed, tested, then
 * sent in batches by the 5-minute cron (`newsletter_batch` emails per run), so a shared host
 * isn't asked to send hundreds of emails at once. Every email has a one-click unsubscribe link.
 * Use an SMTP / transactional email plugin (e.g. Brevo, Postmark, Amazon SES) on the live site.
 *
 * The text uses a tiny format: blank line = new paragraph, "## " heading, "- " bullet list,
 * **bold**, [link text](https://…); plain web addresses become links.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Newsletter {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_unsubscribe' ) );
	}

	/* ---------- Subscribers ---------- */

	/** @return int[] user ids */
	public static function subscribers() {
		return array_map( 'intval', get_users( array( 'fields' => 'ID', 'meta_key' => 'oys_marketing', 'meta_value' => '1' ) ) );
	}

	public static function unsubscribe_url( $user_id ) {
		return add_query_arg( 'oys_unsub', $user_id . '.' . self::sig( $user_id ), home_url( '/' ) );
	}

	private static function sig( $user_id ) {
		return substr( hash_hmac( 'sha256', 'unsub|' . (int) $user_id, wp_salt( 'auth' ) ), 0, 20 );
	}

	/** One click from the email: no login needed, the link is signed. */
	public static function maybe_unsubscribe() {
		if ( empty( $_GET['oys_unsub'] ) ) {
			return;
		}
		[ $uid, $sig ] = array_pad( explode( '.', sanitize_text_field( wp_unslash( $_GET['oys_unsub'] ) ), 2 ), 2, '' );
		if ( ! (int) $uid || ! hash_equals( self::sig( (int) $uid ), $sig ) ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid.', 'olivia-studio' ), '', array( 'response' => 400 ) );
		}
		update_user_meta( (int) $uid, 'oys_marketing', '' );
		wp_die( '<h1>' . esc_html__( 'You\'re unsubscribed', 'olivia-studio' ) . '</h1><p>' . esc_html__( 'You won\'t get the newsletter any more. Booking emails and reminders still arrive. Changed your mind? Tick the newsletter box in your account.', 'olivia-studio' ) . '</p><p><a href="' . esc_url( oys_account_url( 'profile' ) ) . '">' . esc_html__( 'My account', 'olivia-studio' ) . '</a></p>', esc_html__( 'Unsubscribed', 'olivia-studio' ), array( 'response' => 200 ) );
	}

	/* ---------- Newsletters ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . OYS_Install::table( 'newsletters' ) . ' WHERE id = %d', $id ) );
	}

	public static function query( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . OYS_Install::table( 'newsletters' ) . ' ORDER BY id DESC LIMIT ' . (int) $limit );
	}

	/** Create or update a draft. @return int id */
	public static function save( array $d, $id = 0 ) {
		global $wpdb;
		$row = array(
			'subject'      => sanitize_text_field( $d['subject'] ?? '' ),
			'preheader'    => sanitize_text_field( $d['preheader'] ?? '' ),
			'body'         => sanitize_textarea_field( $d['body'] ?? '' ),
			'button_label' => sanitize_text_field( $d['button_label'] ?? '' ),
			'button_url'   => esc_url_raw( $d['button_url'] ?? '' ),
		);
		if ( $id ) {
			$wpdb->update( OYS_Install::table( 'newsletters' ), $row, array( 'id' => (int) $id, 'status' => 'draft' ) );
			return (int) $id;
		}
		$wpdb->insert( OYS_Install::table( 'newsletters' ), array_merge( $row, array( 'status' => 'draft', 'created_by' => get_current_user_id(), 'created_at' => oys_now() ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( OYS_Install::table( 'newsletters' ), array( 'id' => (int) $id, 'status' => 'draft' ) );
	}

	/** The body format → email HTML (escaped). */
	public static function render( $text ) {
		$out = '';
		foreach ( preg_split( "/\n\s*\n/", str_replace( "\r", '', trim( (string) $text ) ) ) as $block ) {
			$lines = array_values( array_filter( array_map( 'rtrim', explode( "\n", $block ) ), 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			if ( str_starts_with( $lines[0], '## ' ) ) {
				$out .= '<h2 style="margin:26px 0 8px;font-size:20px;line-height:1.2;text-transform:uppercase;color:#2B5036">' . self::inline( substr( array_shift( $lines ), 3 ) ) . '</h2>';
				if ( ! $lines ) {
					continue;
				}
			}
			if ( array_filter( $lines, fn( $l ) => str_starts_with( ltrim( $l ), '- ' ) ) === $lines ) {
				$out .= '<ul style="margin:0 0 16px;padding-left:20px">' . implode( '', array_map( fn( $l ) => '<li style="margin:0 0 6px">' . self::inline( substr( ltrim( $l ), 2 ) ) . '</li>', $lines ) ) . '</ul>';
				continue;
			}
			$out .= '<p>' . implode( '<br>', array_map( array( __CLASS__, 'inline' ), $lines ) ) . '</p>';
		}
		return $out;
	}

	private static function inline( $s ) {
		$s = esc_html( $s );
		$s = preg_replace( '/\*\*(.+?)\*\*/', '<b>$1</b>', $s );
		$s = preg_replace_callback( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', fn( $m ) => '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '" style="color:#2B5036;font-weight:700">' . $m[1] . '</a>', $s );
		return preg_replace_callback( '/(?<!["=>])\bhttps?:\/\/[^\s<]+/', fn( $m ) => '<a href="' . esc_url( html_entity_decode( $m[0] ) ) . '" style="color:#2B5036">' . $m[0] . '</a>', $s );
	}

	/** The email as a subscriber sees it. @return array [ subject, html ] */
	public static function email_for( $n, $user_id = 0 ) {
		$u    = $user_id ? get_userdata( $user_id ) : null;
		$vars = array( 'first_name' => $u ? ( $u->first_name ?: $u->display_name ) : __( 'there', 'olivia-studio' ), 'studio' => OYS_Settings::get( 'email_from_name' ) );
		$body = self::render( OYS_Email_Templates::fill( $n->body, $vars ) );
		$cta  = $n->button_label && $n->button_url ? array( $n->button_label, $n->button_url ) : null;
		$foot = $user_id ? self::unsubscribe_url( $user_id ) : '#';
		return array( OYS_Email_Templates::fill( $n->subject, $vars ), OYS_Emails::newsletter_html( $n->subject, $n->preheader, $body, $cta, $foot ) );
	}

	/**
	 * Queue a draft for every subscriber; the cron sends it in batches.
	 * @return int|WP_Error how many will receive it
	 */
	public static function send( $id ) {
		global $wpdb;
		$n = self::get( $id );
		if ( ! $n || 'draft' !== $n->status ) {
			return new WP_Error( 'oys_newsletter', __( 'This newsletter was already sent.', 'olivia-studio' ) );
		}
		if ( '' === trim( $n->subject ) || '' === trim( $n->body ) ) {
			return new WP_Error( 'oys_newsletter', __( 'Write a subject and a message first.', 'olivia-studio' ) );
		}
		$t = OYS_Install::table( 'newsletters' );
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'sending' WHERE id = %d AND status = 'draft'", $id ) ) ) {
			return new WP_Error( 'oys_newsletter', __( 'This newsletter was already sent.', 'olivia-studio' ) );
		}
		$q     = OYS_Install::table( 'newsletter_queue' );
		$users = self::subscribers();
		foreach ( array_chunk( $users, 200 ) as $chunk ) {
			$values = implode( ',', array_map( fn( $u ) => $wpdb->prepare( '(%d, %d, %s)', $id, $u, 'queued' ), $chunk ) );
			$wpdb->query( "INSERT IGNORE INTO $q (newsletter_id, user_id, status) VALUES $values" ); // phpcs:ignore
		}
		$wpdb->update( $t, array( 'total' => count( $users ), 'queued_at' => oys_now() ), array( 'id' => $id ) );
		if ( ! $users ) {
			$wpdb->update( $t, array( 'status' => 'sent', 'sent_at' => oys_now() ), array( 'id' => $id ) );
		}
		return count( $users );
	}

	/** Cron: send the next batch. @return int emails sent */
	public static function process_queue( $batch = null ) {
		global $wpdb;
		$q     = OYS_Install::table( 'newsletter_queue' );
		$t     = OYS_Install::table( 'newsletters' );
		$batch = $batch ?: max( 1, (int) OYS_Settings::get( 'newsletter_batch' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $q WHERE status = 'queued' ORDER BY id LIMIT %d", $batch ) );
		$sent  = 0;
		$cache = array();
		foreach ( $rows as $r ) {
			// Claim the row, so two cron runs never send the same email twice.
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE $q SET status = 'sending' WHERE id = %d AND status = 'queued'", $r->id ) ) ) {
				continue;
			}
			$n = $cache[ $r->newsletter_id ] ??= self::get( $r->newsletter_id );
			$u = get_userdata( $r->user_id );
			$ok = false;
			if ( $n && $u && '1' === (string) get_user_meta( $u->ID, 'oys_marketing', true ) ) {
				[ $subject, $html ] = self::email_for( $n, $u->ID );
				$ok = OYS_Emails::send_raw( $u->user_email, $subject, $html, array( 'List-Unsubscribe: <' . self::unsubscribe_url( $u->ID ) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );
			}
			$wpdb->update( $q, array( 'status' => $ok ? 'sent' : 'skipped', 'sent_at' => oys_now() ), array( 'id' => $r->id ) );
			if ( $ok ) {
				$sent++;
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET sent = sent + 1 WHERE id = %d", $r->newsletter_id ) );
			}
		}
		// Newsletters with nothing left to send are done.
		$wpdb->query( $wpdb->prepare( "UPDATE $t n SET n.status = 'sent', n.sent_at = %s WHERE n.status = 'sending' AND NOT EXISTS ( SELECT 1 FROM $q q WHERE q.newsletter_id = n.id AND q.status IN ('queued','sending') )", oys_now() ) );
		return $sent;
	}

	/** One test email to the studio (or any address). */
	public static function send_test( $id, $to ) {
		$n = self::get( $id );
		if ( ! $n || ! is_email( $to ) ) {
			return false;
		}
		$u = get_user_by( 'email', $to );
		[ $subject, $html ] = self::email_for( $n, $u ? $u->ID : 0 );
		return OYS_Emails::send_raw( $to, '[Test] ' . $subject, $html );
	}

	/** The loyalty draw's announcement, from its email template, queued to every subscriber. */
	public static function announce_draw( $period, $tickets, $entrants, $product ) {
		$t = OYS_Email_Templates::get( 'raffle_announcement' );
		if ( ! $t || ! $t['enabled'] ) {
			return 0;
		}
		[ , , $end ] = OYS_Rewards::period();
		$vars = array(
			'period'   => $period,
			'prize'    => $product['name'] ?? __( 'a free pass', 'olivia-studio' ),
			'tickets'  => $tickets,
			'entrants' => $entrants,
			'next_draw' => wp_date( 'F j', oys_ts( $end ) ),
			'studio'   => OYS_Settings::get( 'email_from_name' ),
			'first_name' => '{first_name}',
		);
		$body = OYS_Email_Templates::fill( $t['message'], $vars ) . ( $t['closing'] ? "\n\n" . OYS_Email_Templates::fill( $t['closing'], $vars ) : '' );
		$id   = self::save( array(
			'subject'      => OYS_Email_Templates::fill( $t['subject'], $vars ),
			'preheader'    => OYS_Email_Templates::fill( $t['heading'], $vars ),
			'body'         => $body,
			'button_label' => $t['button'] ? OYS_Email_Templates::fill( $t['button'], $vars ) : '',
			'button_url'   => $t['button'] ? oys_page_url( 'book' ) : '',
		) );
		self::send( $id );
		return $id;
	}
}
