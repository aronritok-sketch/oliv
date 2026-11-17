<?php
/**
 * Levélküldés (Áron, 2026-11-17: „szerintem nem küld emailt még a rendszer … küldjön!!!”).
 *
 * A VPS-en a PHP saját levélküldése nem kézbesít, ezért a WordPress a szerver postafiókján át küld: a
 * `hpv-mail wp-link <cím> oliviajoga-hu` az oldal titokfájljába írja az OYS_SMTP_* konstansokat (HOST, PORT, SECURE,
 * USER, PASS, FROM – ugyanaz, mint az oliviajoga.online-on; a jelszó a szerveren marad, a repóba nem kerül). Konstansok
 * nélkül minden marad a régiben. A WordPress saját leveleinek (új fiók, jelszó) feladója „Olívia jóga <OYS_SMTP_FROM>”
 * a „WordPress <wordpress@…>” helyett – a postafiók-szerver csak a saját címével enged küldeni.
 */

defined( 'ABSPATH' ) || exit;

function oj_smtp_on(): bool {
	return defined( 'OYS_SMTP_HOST' ) && '' !== trim( (string) OYS_SMTP_HOST );
}

add_action( 'phpmailer_init', function ( $m ) {
	if ( ! oj_smtp_on() ) {
		return;
	}
	$secure = defined( 'OYS_SMTP_SECURE' ) ? (string) OYS_SMTP_SECURE : 'tls';
	$secure = in_array( $secure, array( 'tls', 'ssl', 'none' ), true ) ? $secure : 'tls';
	$user   = defined( 'OYS_SMTP_USER' ) ? (string) OYS_SMTP_USER : '';
	$m->isSMTP();
	$m->Host        = trim( (string) OYS_SMTP_HOST );
	$m->Port        = defined( 'OYS_SMTP_PORT' ) && (int) OYS_SMTP_PORT ? (int) OYS_SMTP_PORT : ( 'ssl' === $secure ? 465 : 587 );
	$m->SMTPSecure  = 'none' === $secure ? '' : $secure;
	$m->SMTPAutoTLS = 'none' !== $secure;
	$m->SMTPAuth    = '' !== $user;
	$m->Username    = $user;
	$m->Password    = defined( 'OYS_SMTP_PASS' ) ? (string) OYS_SMTP_PASS : '';
	$m->Timeout     = 15;
}, 5 );

add_filter( 'wp_mail_from', function ( $email ) {
	$own = defined( 'OYS_SMTP_FROM' ) ? (string) OYS_SMTP_FROM : '';
	return oj_smtp_on() && is_email( $own ) && 0 === stripos( (string) $email, 'wordpress@' ) ? $own : $email;
}, 5 );

add_filter( 'wp_mail_from_name', fn( $name ) => oj_smtp_on() && 'WordPress' === $name ? 'Olívia jóga' : $name, 5 );

add_action( 'wp_mail_failed', function ( $error ) {
	if ( is_wp_error( $error ) ) {
		set_transient( 'oj_mail_last_error', array( 'message' => wp_strip_all_tags( $error->get_error_message() ), 'time' => time() ), WEEK_IN_SECONDS );
	}
} );

/** A távirányító állapotához: be van-e állítva, és az utolsó hiba (cím és jelszó nélkül). */
function oj_mail_status(): array {
	$e = get_transient( 'oj_mail_last_error' );
	return array(
		'smtp'       => oj_smtp_on() ? 'set' : 'missing',
		'from'       => defined( 'OYS_SMTP_FROM' ) ? (string) OYS_SMTP_FROM : '',
		'last_error' => is_array( $e ) ? array( 'message' => $e['message'], 'when' => gmdate( 'c', (int) $e['time'] ) ) : null,
	);
}
