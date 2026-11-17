<?php
/**
 * Távirányító – oliviajoga.hu (Áron, 2026-11-17: „Csináljunk az oliviajoga.hu-ra egy távirányítót, hogy tudjunk mindent
 * is intézni”).
 *
 * Az éles oldalon a szerver távirányítója szándékosan nem futtat WP-CLI-t, ezért az oldal saját REST-felülete a
 * távirányító. Belépni egy adminisztrátor alkalmazásjelszavával lehet (Felhasználók → Profil → Alkalmazásjelszavak).
 *
 * - A cím: https://oliviajoga.hu/?rest_route=/oj/v1/… (az iu_theme a /wp-json/ előtagot szándékosan elrejti).
 * - X-OYS-Auth fejléc: base64(„felhasználó:alkalmazásjelszó”), vagy maga a „felhasználó:alkalmazásjelszó”. Ugyanaz, mint
 *   az oliviajoga.online-on (a szerver az Authorization fejlécet nem mindig engedi át). Csak HTTPS-en, csak a REST-hez,
 *   és a WordPress saját alkalmazásjelszó-ellenőrzésével, így más nem jut be vele. Ezzel a WordPress teljes REST-je
 *   elérhető az admin nevében: oldalak, bejegyzések, média, menük, beállítások, felhasználók.
 * - GET  oj/v1/status – verziók, beállítások, oldalak (szerkesztette-e valaki), a szerkesztő eltérései, a november 26-i
 *   váltás, cron, hibakeresés, keresők, a HelloProVision-csomag állapota. Titok soha.
 * - GET  oj/v1/edits  – a weboldali szerkesztő eltérései: eredeti → mostani szöveg, hol, ki, mikor.
 * - POST oj/v1/edits  {"t": {"<kulcs vagy az eredeti szöveg>": "<új szöveg>" | ""}} – eltérés beállítása vagy törlése a
 *   szerkesztő saját mentésén át (az előző állapot az adminban visszaállítható).
 * - POST oj/v1/setup  – a téma beállító lépése újra: oldalak a kódból, de csak ott, ahol senki nem szerkesztette.
 * - POST oj/v1/kit    – a HelloProVision élesítési csomag most (inc/hpv-kit.php: varázsló, Ads és HotPot Connect, mu-pluginok).
 * - POST oj/v1/mail-test – próbalevél a belépett admin saját címére (inc/mail.php).
 * - POST oj/v1/flush  – gyorsítótár és az állandó hivatkozások ürítése.
 * A csomag után a HelloProVision szabványos távirányítója is itt van: hpv-setup/v1/remote/… (kulcsok, párosítás, mentés).
 *
 * Minden végpont csak adminisztrátornak (manage_options).
 */

defined( 'ABSPATH' ) || exit;

/** X-OYS-Auth: „felhasználó:alkalmazásjelszó” (vagy base64-ben) → a felhasználó, ha az Authorization fejléc nem jött meg. */
function oj_remote_header_auth( $user_id ) {
	if ( $user_id || empty( $_SERVER['HTTP_X_OYS_AUTH'] ) || ! is_ssl() || ! function_exists( 'wp_authenticate_application_password' ) ) {
		return $user_id;
	}
	// Csak a REST-hez. Az iu_theme elrejti a /wp-json/ előtagot (rest_url_prefix), ezért a valódi előtagot nézzük, és a
	// mindig működő /?rest_route=… alakot.
	$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
	if ( ! str_contains( $uri, '/' . rest_get_url_prefix() . '/' ) && ! str_contains( $uri, 'rest_route=' ) ) {
		return $user_id;
	}
	$given = trim( (string) wp_unslash( $_SERVER['HTTP_X_OYS_AUTH'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$raw   = str_contains( $given, ':' ) ? $given : base64_decode( $given, true );
	if ( ! $raw || ! str_contains( $raw, ':' ) ) {
		return $user_id;
	}
	list( $login, $pass ) = explode( ':', $raw, 2 );
	$user = wp_authenticate_application_password( null, $login, $pass );
	return $user instanceof WP_User ? $user->ID : $user_id;
}
add_filter( 'determine_current_user', 'oj_remote_header_auth', 25 );

add_action( 'rest_api_init', function () {
	$admin = fn() => current_user_can( 'manage_options' );
	register_rest_route( 'oj/v1', '/status', array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => 'oj_remote_status' ) );
	register_rest_route( 'oj/v1', '/edits', array(
		array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => 'oj_remote_edits' ),
		array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => 'oj_remote_edits_save' ),
	) );
	register_rest_route( 'oj/v1', '/setup', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => fn() => array( 'ok' => true, 'log' => oj_setup() ) ) );
	register_rest_route( 'oj/v1', '/flush', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => 'oj_remote_flush' ) );
	// próbalevél a belépett admin saját címére (máshová nem küld)
	register_rest_route( 'oj/v1', '/mail-test', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => function () {
		$to = wp_get_current_user()->user_email;
		$ok = wp_mail( $to, 'Próbalevél – oliviajoga.hu', "Ha ez megérkezett (nem a spambe), az oliviajoga.hu levélküldése működik.\n" );
		return array( 'sent' => (bool) $ok, 'mail' => oj_mail_status() );
	} ) );
	register_rest_route( 'oj/v1', '/kit', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => fn() => function_exists( 'hpv_kit_run' ) ? array( 'log' => hpv_kit_run(), 'kit' => hpv_kit_status() ) : new WP_Error( 'oj_remote', 'A csomag nincs a témában.', array( 'status' => 500 ) ) ) );
} );

/** Az oldal állapota egy pillantásra. Titok soha: kulcs és jelszó nem kerül bele. */
function oj_remote_status() {
	global $wp_version;
	$theme = wp_get_theme();
	$data  = oj_data();
	$md5   = (array) get_option( 'oj_setup_md5', array() );
	$pages = array();
	foreach ( (array) ( $data['pages'] ?? array() ) as $path => $p ) {
		$post = '' === $path ? get_post( (int) get_option( 'page_on_front' ) ) : get_page_by_path( $path );
		$key  = '' === $path ? 'home' : $path;
		$pages[] = array(
			'path'     => '/' . $path,
			'id'       => $post ? $post->ID : 0,
			'title'    => $post ? get_the_title( $post ) : '',
			'status'   => $post ? $post->post_status : 'missing',
			'modified' => $post ? $post->post_modified : '',
			// szerkesztette-e valaki a WordPressben azóta, hogy a beállító utoljára írta (akkor az új kiadás nem írja felül)
			'edited'   => $post && isset( $md5[ $key ] ) ? md5( $post->post_content ) !== $md5[ $key ] : null,
		);
	}
	$edits = function_exists( 'oye_edits' ) ? oye_edits() : array( 't' => array(), 'i' => array() );
	$cron  = _get_cron_array();
	$roles = array();
	foreach ( count_users()['avail_roles'] ?? array() as $role => $n ) {
		if ( $n ) {
			$roles[ $role ] = $n;
		}
	}
	return array(
		'versions' => array(
			'wordpress' => $wp_version,
			'php'       => PHP_VERSION,
			'theme'     => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'parent'    => $theme->parent() ? $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) : '',
			'plugins'   => array_values( array_map( fn( $f ) => dirname( $f ) ?: $f, (array) get_option( 'active_plugins', array() ) ) ),
		),
		'site'     => array(
			'home'           => home_url( '/' ),
			'name'           => get_option( 'blogname' ),
			'search_engines' => get_option( 'blog_public' ) ? 'allowed' : 'BLOCKED',
			'permalinks'     => get_option( 'permalink_structure' ),
			'timezone'       => wp_timezone_string(),
			'language'       => get_locale(),
			'front_page'     => (int) get_option( 'page_on_front' ),
			'users'          => $roles,
			'site_icon'      => has_site_icon() ? 'set' : 'missing',
			'debug'          => array( 'WP_DEBUG' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null ),
			'cron_next'      => $cron ? gmdate( 'c', (int) min( array_keys( $cron ) ) ) : '',
			'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		),
		// Olívia, 11.12: „november 26-án” – a második fele magától vált, ezen a napon
		'after'    => array( 'from' => $data['after']['from'] ?? '', 'active' => oj_after() ),
		'pages'    => $pages,
		'editor'   => array( 'texts' => count( $edits['t'] ?? array() ), 'images' => count( $edits['i'] ?? array() ) ),
		'kit'      => function_exists( 'hpv_kit_status' ) ? hpv_kit_status() : null,
		'mail'     => function_exists( 'oj_mail_status' ) ? oj_mail_status() : null,
	);
}

/** A weboldali szerkesztő eltérései: kulcs, eredeti, mostani szöveg, hol, ki, mikor. */
function oj_remote_edits() {
	$e    = function_exists( 'oye_edits' ) ? oye_edits() : array( 't' => array(), 'i' => array() );
	$meta = get_option( 'oye_edits_meta', array() );
	$meta = is_array( $meta ) ? $meta : array();
	$out  = array( 'texts' => array(), 'images' => array() );
	foreach ( (array) $e['t'] as $k => $v ) {
		$m               = (array) ( $meta[ $k ] ?? array() );
		$out['texts'][] = array( 'key' => $k, 'original' => (string) ( $m['def'] ?? '' ), 'now' => (string) $v, 'where' => (string) ( $m['url'] ?? '' ), 'page' => (string) ( $m['title'] ?? '' ), 'who' => (string) ( $m['user'] ?? '' ), 'when' => ! empty( $m['time'] ) ? gmdate( 'c', (int) $m['time'] ) : '' );
	}
	foreach ( (array) $e['i'] as $k => $v ) {
		$m                = (array) ( $meta[ $k ] ?? array() );
		$out['images'][] = array( 'key' => $k, 'attachment' => (int) ( $v['id'] ?? 0 ), 'focus' => isset( $v['fx'] ) ? array( $v['fx'], $v['fy'] ) : null, 'where' => (string) ( $m['url'] ?? '' ), 'who' => (string) ( $m['user'] ?? '' ), 'when' => ! empty( $m['time'] ) ? gmdate( 'c', (int) $m['time'] ) : '' );
	}
	return $out;
}

/** Eltérés beállítása / törlése: {"t": {"<kulcs vagy eredeti szöveg>": "<új szöveg>" | ""}}. */
function oj_remote_edits_save( WP_REST_Request $r ) {
	if ( ! function_exists( 'oye_apply' ) || ! function_exists( 'oye_text_key' ) ) {
		return new WP_Error( 'oj_remote', 'A weboldali szerkesztő nincs betöltve.', array( 'status' => 500 ) );
	}
	$t = array();
	foreach ( (array) $r->get_param( 't' ) as $k => $v ) {
		$k = (string) $k;
		if ( preg_match( '/^t[a-f0-9]{10}$/', $k ) ) {
			$t[ $k ] = array( 'v' => (string) $v );
		} elseif ( '' !== trim( $k ) ) {
			$t[ oye_text_key( $k ) ] = array( 'v' => (string) $v, 'def' => $k );
		}
	}
	if ( ! $t ) {
		return new WP_Error( 'oj_remote', 'Nincs mit menteni: {"t": {"<kulcs vagy eredeti szöveg>": "<új szöveg>"}}.', array( 'status' => 400 ) );
	}
	return oye_apply( $t, array(), array(), array( 'url' => (string) $r->get_param( 'url' ), 'title' => 'távirányító' ) );
}

function oj_remote_flush() {
	wp_cache_flush();
	flush_rewrite_rules( false );
	return array( 'ok' => true );
}
