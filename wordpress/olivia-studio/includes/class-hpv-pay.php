<?php
/**
 * Payments in the name of Arovia Group LLC, through the HelloProVision CRM (Áron, 2026-11-13: „az Arovia Group LLC
 * fogja számlázni, szóval Stripe kell hozzá, meg QuickBooks”).
 *
 * The Stripe key and the QuickBooks connection stay in the CRM (hlprv: plugins/helloprovision-portal/includes/
 * shop-pay.php). With the provider set to „hpv” every Stripe call of this plugin (OYS_Stripe::request) goes to the CRM,
 * signed with this site's own secret; the CRM lets through only what a shop needs (Checkout in payment mode, reading
 * and expiring its own sessions, refunds of its own payments), books every payment in QuickBooks and forwards the
 * Stripe events of this site, signed, to /wp-json/oys/v1/hpv-webhook.
 *
 * The secret is made here and never typed in by anyone: the site sends it to the CRM (/shop/register, over HTTPS), and
 * the CRM calls back /wp-json/oys/v1/hpv-proof to check that it was really this site (the address must be on the CRM's
 * list). Registration runs by itself (cron) until it succeeds; Studio → Settings shows the state and a button.
 */

defined( 'ABSPATH' ) || exit;

class OYS_HPV_Pay {

	const OPTION = 'oys_hpv_pay';

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'oys/v1', '/hpv-proof', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'proof' ),
				'permission_callback' => '__return_true', // answers only with an HMAC of the CRM's challenge
			) );
			register_rest_route( 'oys/v1', '/hpv-webhook', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // authenticated by the signature
			) );
		} );
		add_action( 'oys_hpv_register', array( __CLASS__, 'register' ) );
		add_action( 'oys_frequent', array( __CLASS__, 'maybe_register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_register' ) );
	}

	public static function active() {
		return 'hpv' === OYS_Settings::pay_provider();
	}

	public static function base() {
		return defined( 'OYS_HPV_PAY_URL' ) ? untrailingslashit( OYS_HPV_PAY_URL ) : 'https://crm.helloprovision.com/wp-json/hpv/v1/shop';
	}

	public static function state() {
		$o = get_option( self::OPTION, array() );
		return ( is_array( $o ) ? $o : array() ) + array( 'secret' => '', 'pending' => '', 'site' => '', 'mode' => '', 'registered' => 0, 'error' => '', 'tried' => 0 );
	}

	private static function save( array $o ) {
		update_option( self::OPTION, $o, false );
	}

	public static function ready() {
		$o = self::state();
		return '' !== $o['secret'] && '' !== $o['site'];
	}

	/** Not connected yet: try again, at most every 10 minutes (cron, or when someone opens the admin). */
	public static function maybe_register() {
		if ( ! self::active() || self::ready() || ( time() - (int) self::state()['tried'] ) < 10 * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( ! wp_next_scheduled( 'oys_hpv_register' ) ) {
			wp_schedule_single_event( time(), 'oys_hpv_register' );
		}
	}

	/** @return true|WP_Error */
	public static function register() {
		$o            = self::state();
		$o['pending'] = wp_generate_password( 48, false, false );
		$o['tried']   = time();
		self::save( $o );
		$res  = wp_remote_post( self::base() . '/register', array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'url' => home_url(), 'secret' => $o['pending'], 'name' => get_bloginfo( 'name' ) ) ),
		) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$o    = self::state();
		if ( 200 !== $code || empty( $data['ok'] ) || empty( $data['site'] ) ) {
			$o['error']   = is_wp_error( $res ) ? $res->get_error_message() : (string) ( $data['message'] ?? 'HTTP ' . $code );
			$o['pending'] = '';
			self::save( $o );
			return new WP_Error( 'oys_hpv_register', $o['error'] );
		}
		$o = array_merge( $o, array( 'secret' => $o['pending'], 'pending' => '', 'site' => (string) $data['site'], 'mode' => (string) ( $data['mode'] ?? '' ), 'registered' => time(), 'error' => '' ) );
		self::save( $o );
		return true;
	}

	/** The CRM's check that the registration came from this site: HMAC of its challenge with the new secret. */
	public static function proof( WP_REST_Request $r ) {
		$o = self::state();
		$s = '' !== $o['pending'] ? $o['pending'] : $o['secret'];
		$c = (string) $r->get_param( 'c' );
		if ( '' === $s || ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $c ) ) {
			return new WP_REST_Response( array( 'error' => 'not registering' ), 404 );
		}
		return new WP_REST_Response( array( 'proof' => hash_hmac( 'sha256', 'proof.' . $c, $s ) ), 200 );
	}

	public static function sign( $body, $time ) {
		return hash_hmac( 'sha256', $time . '.' . $body, self::state()['secret'] );
	}

	/**
	 * A Stripe call through the CRM (same shape as the Stripe API's answer).
	 * @return array|WP_Error
	 */
	public static function stripe( $method, $path, array $params = array(), $idempotency_key = '' ) {
		if ( ! self::ready() ) {
			self::maybe_register();
			return new WP_Error( 'oys_stripe_keys', __( 'Online payments are not set up yet.', 'olivia-studio' ) );
		}
		$o    = self::state();
		$body = wp_json_encode( array( 'method' => $method, 'path' => $path, 'params' => $params, 'idem' => (string) $idempotency_key ) );
		$t    = time();
		$res  = wp_remote_post( self::base() . '/stripe', array(
			'timeout' => 40,
			'headers' => array( 'Content-Type' => 'application/json', 'X-HPV-Site' => $o['site'], 'X-HPV-Time' => (string) $t, 'X-HPV-Signature' => self::sign( $body, $t ) ),
			'body'    => $body,
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'oys_stripe_http', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code ) {
			// the CRM no longer knows this secret (e.g. the connection was removed there): register again
			$o['secret'] = '';
			$o['site']   = '';
			$o['tried']  = 0;
			self::save( $o );
			self::maybe_register();
		}
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			oys_log( 'HPV payment call failed', array( 'path' => $path, 'code' => $code, 'error' => is_array( $data ) ? ( $data['message'] ?? '' ) : '' ) );
			return new WP_Error( 'oys_stripe', is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'The payment service is not available right now. Please try again in a few minutes.', 'olivia-studio' ) );
		}
		return $data;
	}

	/** Stripe events of this site, forwarded and signed by the CRM. */
	public static function webhook( WP_REST_Request $r ) {
		$o    = self::state();
		$body = (string) $r->get_body();
		$t    = (int) $r->get_header( 'x_hpv_time' );
		if ( '' === $o['secret'] || $r->get_header( 'x_hpv_site' ) !== $o['site'] || ! $t || abs( time() - $t ) > 300 || ! hash_equals( self::sign( $body, $t ), (string) $r->get_header( 'x_hpv_signature' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 400 );
		}
		$event = json_decode( $body, true );
		if ( empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'bad payload' ), 400 );
		}
		return OYS_Stripe::handle_event( $event );
	}
}
