<?php
/**
 * Small shared helpers: time, money, URLs, notices.
 * All datetimes are stored in UTC ('Y-m-d H:i:s') and shown in the site time zone.
 */

defined( 'ABSPATH' ) || exit;

function oys_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

function oys_utc_plus( $seconds ) {
	return gmdate( 'Y-m-d H:i:s', time() + (int) $seconds );
}

function oys_ts( $utc ) {
	return $utc ? strtotime( $utc . ' UTC' ) : 0;
}

/** Format a UTC datetime in the site time zone. */
function oys_date( $utc, $format = null ) {
	if ( ! $utc ) {
		return '';
	}
	$format = $format ?: get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	return wp_date( $format, oys_ts( $utc ) );
}

function oys_time( $utc ) {
	return oys_date( $utc, 'g:i a' );
}

/** Local date + time (site time zone) to UTC string. */
function oys_local_to_utc( $local ) {
	$dt = date_create( $local, wp_timezone() );
	if ( ! $dt ) {
		return '';
	}
	return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function oys_utc_to_local_input( $utc ) {
	return $utc ? wp_date( 'Y-m-d\TH:i', oys_ts( $utc ) ) : '';
}

function oys_money( $cents, $currency = null ) {
	$currency = strtoupper( $currency ?: OYS_Settings::get( 'currency' ) );
	$symbols  = array( 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'HUF' => 'Ft ' );
	$sym      = $symbols[ $currency ] ?? $currency . ' ';
	$amount   = $cents / 100;
	$str      = fmod( $amount, 1.0 ) === 0.0 ? number_format_i18n( $amount ) : number_format_i18n( $amount, 2 );
	return $sym . $str;
}

function oys_cents_from_input( $value ) {
	$value = str_replace( array( '$', ',', ' ' ), '', (string) $value );
	return max( 0, (int) round( (float) $value * 100 ) );
}

function oys_page_url( $key, $args = array() ) {
	$id  = (int) get_option( 'oys_page_' . $key );
	$url = $id ? get_permalink( $id ) : home_url( '/' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

function oys_book_url( $session_id ) {
	return oys_page_url( 'book', array( 'session' => (int) $session_id ) );
}

function oys_account_url( $tab = '' ) {
	return oys_page_url( 'account', $tab ? array( 'tab' => $tab ) : array() );
}

/** Class display names come from the theme's class posts; fall back to a tidy slug. */
function oys_class_title( $slug ) {
	$titles = apply_filters( 'oys_class_titles', array() );
	if ( isset( $titles[ $slug ] ) ) {
		return $titles[ $slug ];
	}
	return ucwords( str_replace( '-', ' ', (string) $slug ) );
}

function oys_class_options() {
	$titles = apply_filters( 'oys_class_titles', array() );
	return $titles ?: array( 'hatha-flow' => 'Hatha Flow', 'slow-flow' => 'Slow Flow' );
}

/** Online-only class (joined through the online link), not in the studio. */
function oys_is_online( $session ) {
	return $session && 'online' === ( $session->format ?? 'studio' );
}

/** In the studio and streamed live at the same time: people choose how they join. */
function oys_is_hybrid( $session ) {
	return $session && 'hybrid' === ( $session->format ?? 'studio' );
}

/** Can people join this session online (online-only or hybrid)? */
function oys_has_online( $session ) {
	return oys_is_online( $session ) || oys_is_hybrid( $session );
}

/** How someone takes part: 'online' or 'studio'. Online-only classes are always online. */
function oys_mode_for( $session, $mode = 'studio' ) {
	if ( oys_is_online( $session ) ) {
		return 'online';
	}
	return oys_is_hybrid( $session ) && 'online' === $mode ? 'online' : 'studio';
}

function oys_session_title( $session ) {
	if ( ! $session ) {
		return '';
	}
	return $session->title ?: oys_class_title( $session->class_slug );
}

/** "Pay what you like" class: the price is only a suggestion, with a minimum for card payments. */
function oys_is_donation( $session ) {
	return $session && 'donation' === ( $session->pricing ?? 'fixed' );
}

/** Suggested donation amounts in cents (Studio → Settings), never below the minimum. */
function oys_donation_amounts() {
	$min = (int) OYS_Settings::get( 'donation_min_cents' );
	$out = array();
	foreach ( explode( ',', (string) OYS_Settings::get( 'donation_suggestions' ) ) as $v ) {
		$c = oys_cents_from_input( trim( $v ) );
		if ( $c >= $min && $c > 0 ) {
			$out[] = $c;
		}
	}
	$out = array_values( array_unique( $out ) );
	sort( $out );
	return $out ?: array( max( $min, 500 ) );
}

/** Price as shown on the timetable: "$25", "By donation" or "Free". */
function oys_price_label( $session, $mode = 'studio' ) {
	if ( oys_is_donation( $session ) ) {
		return __( 'By donation', 'olivia-studio' );
	}
	$price = OYS_Schedule::price_for( $session, $mode );
	return $price ? oys_money( $price ) : __( 'Free', 'olivia-studio' );
}

/** The studio's Facebook group (Studio → Settings), or ''. */
function oys_fb_group_url() {
	return esc_url_raw( (string) OYS_Settings::get( 'fb_group_url' ) );
}

/** "Join our Facebook group" link for forms, booking pages and the account. */
function oys_fb_group_link( $text = '' ) {
	$url = oys_fb_group_url();
	if ( ! $url ) {
		return '';
	}
	$text = $text ?: __( 'Join our Facebook group: class news, photos and a friendly community', 'olivia-studio' );
	return '<p class="oys-fb"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . oys_icon( 'facebook' ) . '<span>' . esc_html( $text ) . '</span></a></p>';
}

/** One-shot notices shown on the next page view (per user or per browser). */
function oys_flash( $message, $type = 'ok' ) {
	$key = oys_flash_key();
	$all = get_transient( $key ) ?: array();
	$all[] = array( 'm' => $message, 't' => $type );
	set_transient( $key, $all, 300 );
}

function oys_flash_key() {
	if ( is_user_logged_in() ) {
		return 'oys_flash_u' . get_current_user_id();
	}
	if ( empty( $_COOKIE['oys_fk'] ) ) {
		$k = wp_generate_password( 20, false );
		if ( ! headers_sent() ) {
			setcookie( 'oys_fk', $k, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE['oys_fk'] = $k;
	}
	return 'oys_flash_g' . preg_replace( '/[^A-Za-z0-9]/', '', $_COOKIE['oys_fk'] );
}

function oys_render_flash() {
	$key = oys_flash_key();
	$all = get_transient( $key );
	if ( ! $all ) {
		return '';
	}
	delete_transient( $key );
	$out = '';
	foreach ( $all as $n ) {
		$out .= '<p class="oys-notice oys-notice--' . esc_attr( $n['t'] ) . '" role="status">' . wp_kses_post( $n['m'] ) . '</p>';
	}
	return $out;
}

function oys_redirect( $url ) {
	wp_safe_redirect( $url );
	exit;
}

function oys_icon( $name ) {
	$icons = array(
		'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		'pin'      => '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="10" r="2.3"/>',
		'users'    => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.2c2.4.2 4.2 1.9 4.8 4.8"/>',
		'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="1.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
		'check'    => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		'arrow'    => '<path d="M4.5 12h15M13.5 6l6 6-6 6"/>',
		'screen'   => '<rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M9 20h6M12 16.5V20"/>',
		'ticket'   => '<path d="M3.5 8.5a2 2 0 0 0 0 4v3.5h17v-3.5a2 2 0 0 1 0-4V5h-17z"/><path d="M14 5v11"/>',
		'gift'     => '<rect x="3.5" y="9" width="17" height="11.5" rx="1"/><path d="M3.5 12.5h17M12 9v11.5"/><path d="M12 9c-1.5-3.5-5.5-4-5.5-1.5S9.5 9 12 9zm0 0c1.5-3.5 5.5-4 5.5-1.5S14.5 9 12 9z"/>',
		'user'     => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
		'facebook' => '<path d="M14.5 8.5h2.5V5h-2.5C12 5 10.5 6.6 10.5 9v2H8v3.5h2.5V21H14v-6.5h2.6l.4-3.5h-3V9.3c0-.5.2-.8.5-.8z"/>',
		'heart'    => '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.4 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>',
		'mail'     => '<rect x="3.5" y="5.5" width="17" height="13" rx="1.5"/><path d="m4 7 8 6 8-6"/>',
	);
	return '<svg class="oys-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $icons[ $name ] ?? '' ) . '</svg>';
}

/** Log to the PHP error log when WP_DEBUG is on. */
function oys_log( $message, $context = array() ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[olivia-studio] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
	}
}
