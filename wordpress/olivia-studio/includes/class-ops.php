<?php
/**
 * Running the site from outside, as the studio's manager (Áron, 16 November: „nézd meg mindent, hogy mi kell az
 * élesítéshez … ezzel ellenőrizz mindent”):
 *
 * - Some servers drop the Authorization header before PHP sees it, so an application password never reaches WordPress.
 *   The same application password is accepted in an X-OYS-Auth header (base64 of „user:password”), only over HTTPS and
 *   only for the REST API; it is checked by WordPress's own application password check, so nothing else gets in.
 * - GET /wp-json/oys/v1/status (managers only): what is set up and what is missing before going live – mail, payments,
 *   the live room, pages, schedule, cron, debug, search engines. Never a secret: keys and passwords only as „set” or
 *   „missing”.
 */

defined( 'ABSPATH' ) || exit;

class OYS_Ops {

	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'header_auth' ), 25 );
		add_action( 'rest_api_init', function () {
			register_rest_route( 'oys/v1', '/status', array(
				'methods'             => 'GET',
				'permission_callback' => fn() => current_user_can( 'oys_manage' ) || current_user_can( 'manage_options' ),
				'callback'            => array( __CLASS__, 'status' ),
			) );
		} );
	}

	/** X-OYS-Auth: base64(user:application password) or plain user:password → the user, when the Authorization header did not arrive. */
	public static function header_auth( $user_id ) {
		if ( $user_id || empty( $_SERVER['HTTP_X_OYS_AUTH'] ) || ! is_ssl() || ! function_exists( 'wp_authenticate_application_password' ) ) {
			return $user_id;
		}
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( ! str_contains( $uri, '/wp-json/' ) && ! str_contains( $uri, 'rest_route=' ) ) {
			return $user_id;
		}
		// base64(user:pass), or plainly "user:pass" (Áron, 2026-11-17: the same simple value on both Olívia sites)
		$given = trim( (string) wp_unslash( $_SERVER['HTTP_X_OYS_AUTH'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw   = str_contains( $given, ':' ) ? $given : base64_decode( $given, true );
		if ( ! $raw || ! str_contains( $raw, ':' ) ) {
			return $user_id;
		}
		list( $login, $pass ) = explode( ':', $raw, 2 );
		$user = wp_authenticate_application_password( null, $login, $pass );
		return $user instanceof WP_User ? $user->ID : $user_id;
	}

	/** What a manager needs to know before going live. Secrets only as set / missing. */
	public static function status() {
		global $wp_version;
		$secret = fn( $k ) => (bool) preg_match( '/(secret|pass|key|token|signing|webhook)/i', $k );
		$settings = array();
		foreach ( OYS_Settings::all() as $k => $v ) {
			if ( $secret( $k ) ) {
				$settings[ $k ] = '' === (string) ( is_array( $v ) ? implode( '', $v ) : $v ) ? 'missing' : 'set';
			} else {
				$settings[ $k ] = is_scalar( $v ) ? ( is_string( $v ) ? mb_substr( $v, 0, 120 ) : $v ) : ( is_array( $v ) ? count( $v ) . ' items' : gettype( $v ) );
			}
		}
		$pages = array();
		foreach ( array( 'book', 'account', 'checkout', 'gift' ) as $k ) {
			$id          = (int) get_option( 'oys_page_' . $k );
			$pages[ $k ] = $id ? ( get_post_status( $id ) . ' ' . get_permalink( $id ) ) : 'missing';
		}
		$cron = _get_cron_array();
		$next = $cron ? (int) min( array_keys( $cron ) ) : 0;
		$sessions = OYS_Schedule::upcoming_public( 60 );
		$theme    = wp_get_theme();
		return array(
			'versions' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'plugin' => OYS_VERSION, 'theme' => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ), 'parent' => $theme->parent() ? $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) : '' ),
			'site'     => array(
				'home'            => home_url( '/' ),
				'search_engines'  => get_option( 'blog_public' ) ? 'allowed' : 'BLOCKED',
				'permalinks'      => get_option( 'permalink_structure' ),
				'timezone'        => wp_timezone_string(),
				'language'        => get_locale(),
				'admin_email'     => get_option( 'admin_email' ),
				'users_can_register' => (bool) get_option( 'users_can_register' ),
				'privacy_page'    => get_privacy_policy_url() ?: 'missing',
				'site_icon'       => has_site_icon() ? 'set' : 'missing',
				'debug'           => array( 'WP_DEBUG' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null, 'SCRIPT_DEBUG' => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ),
				'environment'     => wp_get_environment_type(),
			),
			'mail'     => array(
				'smtp'        => OYS_Mailer::active() ? 'on (' . OYS_Mailer::config()['host'] . ':' . OYS_Mailer::config()['port'] . ', ' . OYS_Mailer::config()['secure'] . ')' : 'off (server mail)',
				'smtp_user'   => '' !== OYS_Mailer::config()['user'] ? 'set' : 'missing',
				'smtp_pass'   => '' !== OYS_Mailer::config()['pass'] ? 'set' : 'missing',
				'from'        => OYS_Settings::get( 'email_from_name' ) . ' <' . OYS_Settings::get( 'email_from' ) . '>',
				'reply_to'    => OYS_Settings::get( 'email_reply_to' ),
				'notify'      => OYS_Settings::get( 'notify_email' ),
				'last_error'  => get_option( OYS_Mailer::ERROR ) ?: '',
			),
			'payments' => array(
				'provider'     => OYS_Settings::pay_provider(),
				'ready'        => OYS_Settings::payments_ready(),
				'hpv_pay'      => get_option( OYS_HPV_Pay::OPTION ) ? 'registered' : 'not registered',
				'currency'     => OYS_Settings::get( 'currency' ),
			),
			'pages'    => $pages,
			'schedule' => array(
				'upcoming_60_days' => count( $sessions ),
				'next'             => $sessions ? oys_session_title( $sessions[0] ) . ' ' . oys_date( $sessions[0]->starts_at, 'Y-m-d H:i' ) : '',
				'products'         => count( OYS_Products::all( true ) ),
			),
			'people'   => array(
				'customers' => count( get_users( array( 'role' => 'oys_customer', 'fields' => 'ID', 'number' => 100000 ) ) ),
				'admins'    => count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ),
				'imported'  => count( get_users( array( 'meta_key' => 'oys_ext_source', 'fields' => 'ID', 'number' => 100000 ) ) ),
			),
			'cron'     => array(
				'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_due' => $next ? gmdate( 'c', $next ) : '',
				'overdue'  => $next && $next < time() - 15 * MINUTE_IN_SECONDS,
			),
			'plugins'  => array_values( (array) get_option( 'active_plugins' ) ),
			'storage'  => self::storage(),
			'settings' => $settings,
		);
	}

	/**
	 * What the site's own files take and how much room is left (Áron, 16 November: „számold el, hogy mennyi fog kelleni,
	 * mennyit kell még venni” – the chat photos and the class recordings), in megabytes.
	 */
	public static function storage() {
		$size = function ( $dir ) {
			$bytes = 0;
			$n     = 0;
			if ( is_dir( $dir ) ) {
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
					if ( $f->isFile() && 'index.php' !== $f->getFilename() ) {
						$bytes += $f->getSize();
						$n++;
					}
				}
			}
			return array( round( $bytes / 1048576, 1 ), $n );
		};
		$up    = wp_upload_dir( null, false )['basedir'];
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $up ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$total = function_exists( 'disk_total_space' ) ? @disk_total_space( $up ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		list( $chat_mb, $chat_n ) = $size( OYS_Hub::image_dir()[0] );
		list( $rec_mb, $rec_n )   = $size( OYS_Live::rec_dir() );
		list( $up_mb )            = $size( $up );
		return array(
			'disk_free_mb'  => false === $free ? null : round( $free / 1048576 ),
			'disk_total_mb' => false === $total ? null : round( $total / 1048576 ),
			'uploads_mb'    => $up_mb,
			'chat_photos'   => array( 'mb' => $chat_mb, 'files' => $chat_n ),
			'recordings'    => array( 'mb' => $rec_mb, 'files' => $rec_n, 'keep_days' => (int) OYS_Settings::get( 'live_keep_days' ) ),
		);
	}
}
