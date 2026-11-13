<?php
/**
 * Sending: the studio's own SMTP server (e.g. Brevo, Postmark, Amazon SES, Google Workspace) without an extra plugin.
 *
 * Studio → Emails → "Sending server (SMTP)", or constants in wp-config.php, which win:
 *   OYS_SMTP_HOST, OYS_SMTP_PORT, OYS_SMTP_SECURE ('tls' = STARTTLS, 'ssl', 'none'), OYS_SMTP_USER, OYS_SMTP_PASS.
 * With no host the site sends as before (the server's mail, or an SMTP plugin, which still wins: it configures later).
 * WordPress's own emails (password reset) get the studio as the sender instead of "WordPress <wordpress@…>".
 */

defined( 'ABSPATH' ) || exit;

class OYS_Mailer {

	const ERROR = 'oys_mail_last_error';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'smtp' ), 5 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from' ), 5 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 5 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed' ) );
	}

	/** host, port, secure (tls|ssl|none), user, pass – the wp-config constants win over the saved settings. */
	public static function config() {
		$c = array();
		foreach ( array( 'host', 'port', 'secure', 'user', 'pass' ) as $k ) {
			$const   = 'OYS_SMTP_' . strtoupper( $k );
			$c[ $k ] = defined( $const ) ? (string) constant( $const ) : (string) OYS_Settings::get( 'smtp_' . $k );
		}
		$c['host']   = trim( $c['host'] );
		$c['port']   = (int) $c['port'] ?: ( 'ssl' === $c['secure'] ? 465 : 587 );
		$c['secure'] = in_array( $c['secure'], array( 'tls', 'ssl', 'none' ), true ) ? $c['secure'] : 'tls';
		return $c;
	}

	public static function active() {
		return '' !== self::config()['host'];
	}

	/** Which settings come from wp-config.php (shown as such in the admin). */
	public static function locked() {
		return array_values( array_filter( array( 'host', 'port', 'secure', 'user', 'pass' ), fn( $k ) => defined( 'OYS_SMTP_' . strtoupper( $k ) ) ) );
	}

	public static function smtp( $phpmailer ) {
		$c = self::config();
		if ( '' === $c['host'] ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host        = $c['host'];
		$phpmailer->Port        = $c['port'];
		$phpmailer->SMTPSecure  = 'none' === $c['secure'] ? '' : $c['secure'];
		$phpmailer->SMTPAutoTLS = 'none' !== $c['secure'];
		$phpmailer->SMTPAuth    = '' !== $c['user'];
		$phpmailer->Username    = $c['user'];
		$phpmailer->Password    = $c['pass'];
		$phpmailer->Timeout     = 15;
	}

	/** WordPress's default sender (wordpress@domain) becomes the studio's address. */
	public static function from( $email ) {
		$own = (string) OYS_Settings::get( 'email_from' );
		return $own && is_email( $own ) && 0 === stripos( (string) $email, 'wordpress@' ) ? $own : $email;
	}

	public static function from_name( $name ) {
		$own = (string) OYS_Settings::get( 'email_from_name' );
		return $own && 'WordPress' === $name ? $own : $name;
	}

	/** The studio's From and Reply-To headers for wp_mail(). */
	public static function headers() {
		$h    = array();
		$from = (string) OYS_Settings::get( 'email_from' );
		if ( $from ) {
			$h[] = sprintf( 'From: %s <%s>', OYS_Settings::get( 'email_from_name' ), $from );
		}
		$reply = (string) OYS_Settings::get( 'email_reply_to' );
		if ( $reply && is_email( $reply ) && strtolower( $reply ) !== strtolower( $from ) ) {
			$h[] = sprintf( 'Reply-To: %s <%s>', OYS_Settings::get( 'email_from_name' ), $reply );
		}
		return $h;
	}

	/** The last sending error, for the admin (no addresses or passwords in it beyond what PHPMailer reports). */
	public static function failed( $error ) {
		if ( is_wp_error( $error ) ) {
			set_transient( self::ERROR, array( 'message' => wp_strip_all_tags( $error->get_error_message() ), 'time' => time() ), WEEK_IN_SECONDS );
		}
	}

	public static function last_error() {
		$e = get_transient( self::ERROR );
		return is_array( $e ) ? $e : null;
	}

	/** A short test email to $to; true or a WP_Error with the server's answer. */
	public static function test( $to ) {
		delete_transient( self::ERROR );
		$html = OYS_Emails::wrap_public(
			__( 'Email test', 'olivia-studio' ),
			'<p>' . esc_html__( 'This is a test from your website. If it arrived in your inbox (not in spam), the email set-up works.', 'olivia-studio' ) . '</p>'
			. '<p style="font-size:13px;color:#53635A">' . esc_html( self::describe() ) . '</p>'
		);
		$ok = wp_mail( $to, sprintf( __( 'Email test – %s', 'olivia-studio' ), OYS_Settings::get( 'email_from_name' ) ), $html, array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), self::headers() ) );
		if ( $ok ) {
			return true;
		}
		$e = self::last_error();
		return new WP_Error( 'oys_mail', $e ? $e['message'] : __( 'The server did not accept the email.', 'olivia-studio' ) );
	}

	/** "Sending through smtp-relay.brevo.com:587 (STARTTLS) as …" – or how it sends without SMTP. */
	public static function describe() {
		$c = self::config();
		if ( '' === $c['host'] ) {
			return __( 'No SMTP server is set: emails go out through the web server\'s own mail (or an SMTP plugin) and may land in spam.', 'olivia-studio' );
		}
		$sec = array( 'tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => __( 'no encryption', 'olivia-studio' ) );
		return sprintf( __( 'Sending through %1$s:%2$d (%3$s) as %4$s.', 'olivia-studio' ), $c['host'], $c['port'], $sec[ $c['secure'] ], OYS_Settings::get( 'email_from' ) );
	}
}
