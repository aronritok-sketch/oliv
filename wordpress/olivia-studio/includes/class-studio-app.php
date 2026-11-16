<?php
/**
 * The Studio as an app on the phone (Áron, 16 November: „legyen egy egyedi link ehhez”, „adj egy olyan izét … mint a
 * HelloProVisionnek a telefonjára, egy appot logóval mindennel együtt, hogy onnan elérje és kapjon értesítőt is”).
 *
 * - /studio/ – the Hub's own address: asks to sign in if needed, then opens the Hub (a teacher's own Hub for a
 *   teacher). /studio/?open=inbox|today|more|settings|t<id>|c<id> opens that part.
 * - /studio/utmutato/ – the studio guide, made for the phone (signed-in studio users and teachers).
 * - An installable web app: manifest, icons with the logo (assets/app/), a service worker. „Add to Home Screen” puts
 *   the Studio on the phone's home screen; it opens in its own window, like an app.
 * - Push notifications, per device (Hub → My settings): every new message, private request, contact form message and
 *   class chat message (not while the class is on) arrives as a notification, also when the app is closed. The push
 *   itself is empty; the service worker fetches the waiting notification with the device's push address – the same
 *   model as the HelloProVision CRM (helloprovision-portal/includes/pwa.php). The VAPID key is made on first use and
 *   kept in an option (never in the repository).
 */

defined( 'ABSPATH' ) || exit;

class OYS_Studio_App {

	const SUBS   = 'oys_push_subs';  // md5(endpoint) => { user, endpoint, ua, at, queue[] }
	const VAPID  = 'oys_push_vapid'; // { private (PEM), public (base64url) }
	const MAX    = 10;               // devices per person
	const QUEUED = 5;                // waiting notifications per device
	const THEME  = '#002E2C';        // Evergreen

	/** @var string[] push addresses to ping at the end of the request */
	private static $outbox = array();

	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'route' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_head' ), 1 );
		add_action( 'login_head', array( __CLASS__, 'login_head' ), 1 );
		add_action( 'oys_hub_message', array( __CLASS__, 'on_message' ), 10, 3 );
		add_action( 'oys_live_chat_posted', array( __CLASS__, 'on_class_chat' ), 10, 3 );
	}

	/* ---------- Addresses ---------- */

	public static function url( $open = '' ) {
		return home_url( '/studio/' ) . ( $open ? '?open=' . rawurlencode( $open ) : '' );
	}

	public static function guide_url() {
		return home_url( '/studio/utmutato/' );
	}

	/** What a request asks for: 'hub', 'guide', 'manifest', 'sw', 'pull' or '' (not ours). */
	public static function match( $uri, $query = array() ) {
		$app = sanitize_key( (string) ( $query['oys_app'] ?? '' ) );
		if ( in_array( $app, array( 'manifest', 'sw', 'pull' ), true ) ) {
			return $app;
		}
		$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		$path = rawurldecode( $path );
		if ( 'studio' === $path ) {
			return 'hub';
		}
		if ( in_array( $path, array( 'studio/utmutato', 'studio/útmutató', 'studio/guide' ), true ) ) {
			return 'guide';
		}
		return '';
	}

	/** Where /studio/?open=… leads (only the Hub's own views and conversations). */
	public static function hub_target( $open, $user_id = 0 ) {
		$open = (string) $open;
		$open = preg_match( '/^(today|inbox|calendar|reports|more|settings|[tcs]\d+)$/', $open ) ? $open : '';
		return OYS_Hub::hub_url( $open, ! user_can( $user_id ?: get_current_user_id(), 'oys_manage' ) );
	}

	public static function route() {
		// phpcs:disable WordPress.Security.NonceVerification
		$what = self::match( $_SERVER['REQUEST_URI'] ?? '', $_GET );
		if ( '' === $what ) {
			return;
		}
		if ( 'manifest' === $what ) {
			nocache_headers();
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}
		if ( 'sw' === $what ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: /' );
			echo self::worker(); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		if ( 'pull' === $what ) {
			if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				status_header( 405 );
				exit;
			}
			$body = json_decode( (string) file_get_contents( 'php://input' ), true );
			nocache_headers();
			wp_send_json( array( 'items' => self::pull( (string) ( $body['endpoint'] ?? '' ) ) ) );
		}
		nocache_headers();
		$self = 'guide' === $what ? self::guide_url() : self::url( sanitize_text_field( wp_unslash( (string) ( $_GET['open'] ?? '' ) ) ) );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $self ) );
			exit;
		}
		if ( ! OYS_Hub::can() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		if ( 'guide' === $what ) {
			self::render_guide();
			exit;
		}
		wp_safe_redirect( self::hub_target( sanitize_text_field( wp_unslash( (string) ( $_GET['open'] ?? '' ) ) ) ) );
		exit;
		// phpcs:enable
	}

	/* ---------- The installable app ---------- */

	public static function icon( $name ) {
		return OYS_URL . 'assets/app/' . $name;
	}

	public static function app_name() {
		$site = trim( (string) preg_replace( '/\s*[–—-].*$/u', '', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		$first = $site ? strtok( $site, ' ' ) : '';
		return array(
			'name'  => trim( $site . ' ' . __( 'Studio', 'olivia-studio' ) ),
			'short' => trim( $first . ' ' . __( 'Studio', 'olivia-studio' ) ),
		);
	}

	public static function manifest() {
		$n    = self::app_name();
		$path = fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ) . ( wp_parse_url( $u, PHP_URL_QUERY ) ? '?' . wp_parse_url( $u, PHP_URL_QUERY ) : '' );
		return apply_filters( 'oys_app_manifest', array(
			'name'             => $n['name'],
			'short_name'       => $n['short'],
			'description'      => __( 'Your studio on the phone: messages, class chats, today\'s classes.', 'olivia-studio' ),
			'lang'             => substr( get_locale(), 0, 2 ),
			'id'               => $path( home_url( '/studio/' ) ),
			'start_url'        => $path( home_url( '/studio/' ) ),
			'scope'            => $path( home_url( '/' ) ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => self::THEME,
			'theme_color'      => self::THEME,
			'icons'            => array(
				array( 'src' => self::icon( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon( 'icon-maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
			'shortcuts'        => array(
				array( 'name' => __( 'Messages', 'olivia-studio' ), 'url' => $path( self::url( 'inbox' ) ) ),
				array( 'name' => __( 'Today', 'olivia-studio' ), 'url' => $path( self::url( 'today' ) ) ),
				array( 'name' => __( 'Studio guide', 'olivia-studio' ), 'url' => $path( self::guide_url() ) ),
			),
		) );
	}

	/** The tags that make a page installable (Hub, Studio pages, the guide, the login before the Studio). */
	public static function head_tags() {
		$n = self::app_name();
		return '<link rel="manifest" href="' . esc_url( home_url( '/?oys_app=manifest' ) ) . '">' . "\n"
			. '<meta name="theme-color" content="' . esc_attr( self::THEME ) . '">' . "\n"
			. '<meta name="mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
			. '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
			. '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $n['short'] ) . '">' . "\n"
			. '<link rel="apple-touch-icon" href="' . esc_url( self::icon( 'apple-touch-icon.png' ) ) . '">' . "\n";
	}

	public static function admin_head() {
		$page = sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( str_starts_with( $page, 'oys' ) && OYS_Hub::can() ) {
			self::own_icon( 'admin_head' );
			echo self::head_tags(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function login_head() {
		$to = (string) ( $_REQUEST['redirect_to'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( str_contains( $to, '/studio/' ) ) {
			self::own_icon( 'login_head' );
			echo self::head_tags(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** The site's own icon (a second apple-touch-icon) would win on the iPhone's home screen: not on the Studio's pages. */
	private static function own_icon( $hook ) {
		foreach ( array( 10, 99 ) as $prio ) {
			remove_action( $hook, 'wp_site_icon', $prio );
		}
	}

	/** The service worker: notifications only (no fetch handler – it never gets between the page and the server). */
	public static function worker() {
		$js = <<<'JS'
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('push', (e) => {
	e.waitUntil((async () => {
		let item = null;
		try {
			const sub = await self.registration.pushManager.getSubscription();
			const res = await fetch(PULL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub ? sub.endpoint : '' }) });
			if (res.ok) item = ((await res.json()).items || [])[0] || null;
		} catch (err) { /* no network: a general notification */ }
		item = item || { title: TITLE, body: FALLBACK, url: START };
		// always shown: an iPhone switches off a device that gets a push without a notification
		await self.registration.showNotification(item.title, { body: item.body, icon: ICON, badge: BADGE, tag: item.tag || undefined, renotify: !!item.tag, data: { url: item.url || START } });
	})());
});
self.addEventListener('notificationclick', (e) => {
	e.notification.close();
	const url = new URL((e.notification.data && e.notification.data.url) || START, self.location.origin).href;
	e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ws) => {
		const w = ws.find((x) => new URL(x.url).origin === self.location.origin);
		if (w) return w.focus().then((f) => (f && f.navigate ? f.navigate(url).catch(() => self.clients.openWindow(url)) : self.clients.openWindow(url)));
		return self.clients.openWindow(url);
	}));
});
JS;
		$n = self::app_name();
		return '/* ' . OYS_VERSION . " */\n"
			. 'const ICON = ' . wp_json_encode( self::icon( 'icon-192.png' ) ) . ";\n"
			. 'const BADGE = ' . wp_json_encode( self::icon( 'icon-192.png' ) ) . ";\n"
			. 'const PULL = ' . wp_json_encode( home_url( '/?oys_app=pull' ) ) . ";\n"
			. 'const START = ' . wp_json_encode( self::url() ) . ";\n"
			. 'const TITLE = ' . wp_json_encode( $n['name'], JSON_UNESCAPED_UNICODE ) . ";\n"
			. 'const FALLBACK = ' . wp_json_encode( __( 'Someone wrote to you.', 'olivia-studio' ), JSON_UNESCAPED_UNICODE ) . ";\n"
			. $js;
	}

	/* ---------- Push ---------- */

	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/** The VAPID key pair (made on first use). @return array|null { private, public } */
	public static function vapid() {
		$v = get_option( self::VAPID );
		if ( is_array( $v ) && ! empty( $v['private'] ) ) {
			return $v;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}
		$key = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
			return null;
		}
		$d = openssl_pkey_get_details( $key )['ec'];
		$v = array( 'private' => $pem, 'public' => self::b64url( "\x04" . str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['y'], 32, "\0", STR_PAD_LEFT ) ) );
		update_option( self::VAPID, $v, false );
		return $v;
	}

	/** ES256 signature: DER → raw r||s (64 bytes). */
	public static function der_to_raw( $der ) {
		$off = 3 + ( ord( $der[1] ) & 0x80 ? ord( $der[1] ) & 0x7f : 0 );
		$rl  = ord( $der[ $off ] );
		$r   = substr( $der, $off + 1, $rl );
		$off = $off + 1 + $rl + 1;
		$s   = substr( $der, $off + 1, ord( $der[ $off ] ) );
		$fix = fn( $x ) => str_pad( substr( ltrim( $x, "\0" ), -32 ), 32, "\0", STR_PAD_LEFT );
		return $fix( $r ) . $fix( $s );
	}

	public static function jwt( $endpoint, array $v ) {
		$p     = wp_parse_url( $endpoint );
		$head  = self::b64url( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$email = (string) OYS_Settings::get( 'notify_email' ) ?: (string) get_option( 'admin_email' );
		$claim = self::b64url( wp_json_encode( array( 'aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . $email ) ) );
		openssl_sign( $head . '.' . $claim, $der, openssl_pkey_get_private( $v['private'] ), OPENSSL_ALGO_SHA256 );
		return $head . '.' . $claim . '.' . self::b64url( self::der_to_raw( $der ) );
	}

	public static function subs() {
		return (array) get_option( self::SUBS, array() );
	}

	private static function save_subs( array $subs ) {
		update_option( self::SUBS, $subs, false );
	}

	public static function valid_endpoint( $endpoint ) {
		return (bool) preg_match( '#^https://[a-z0-9.-]+(:\d+)?/#i', (string) $endpoint ) && strlen( (string) $endpoint ) < 1000;
	}

	public static function subscribe( $user_id, $endpoint, $ua = '' ) {
		if ( ! self::valid_endpoint( $endpoint ) ) {
			return false;
		}
		$subs  = self::subs();
		$key   = md5( $endpoint );
		$queue = $subs[ $key ]['queue'] ?? array();
		unset( $subs[ $key ] );
		$subs[ $key ] = array( 'user' => (int) $user_id, 'endpoint' => $endpoint, 'ua' => mb_substr( sanitize_text_field( (string) $ua ), 0, 120 ), 'at' => time(), 'queue' => $queue );
		$mine = array_filter( $subs, fn( $s ) => (int) $s['user'] === (int) $user_id );
		uasort( $mine, fn( $a, $b ) => $b['at'] <=> $a['at'] );
		foreach ( array_slice( array_keys( $mine ), self::MAX ) as $old ) {
			unset( $subs[ $old ] );
		}
		self::save_subs( $subs );
		return true;
	}

	public static function unsubscribe( $user_id, $endpoint ) {
		$subs = self::subs();
		$key  = md5( (string) $endpoint );
		if ( isset( $subs[ $key ] ) && ( ! $user_id || (int) $subs[ $key ]['user'] === (int) $user_id ) ) {
			unset( $subs[ $key ] );
			self::save_subs( $subs );
		}
	}

	public static function devices( $user_id ) {
		return count( array_filter( self::subs(), fn( $s ) => (int) $s['user'] === (int) $user_id ) );
	}

	/** @return int[] the users with at least one device */
	public static function subscribers() {
		return array_values( array_unique( array_map( fn( $s ) => (int) $s['user'], self::subs() ) ) );
	}

	/**
	 * A notification on every device of the person (queued; the empty push goes out at the end of the request).
	 * @return int devices
	 */
	public static function notify( $user_id, $title, $body = '', $url = '', $tag = '' ) {
		if ( ! apply_filters( 'oys_push_allowed', true, $user_id ) ) {
			return 0;
		}
		$subs = self::subs();
		$n    = 0;
		foreach ( $subs as $key => $s ) {
			if ( (int) $s['user'] !== (int) $user_id ) {
				continue;
			}
			$q   = (array) ( $s['queue'] ?? array() );
			$q[] = array( 'title' => mb_substr( (string) $title, 0, 120 ), 'body' => mb_substr( (string) $body, 0, 240 ), 'url' => (string) $url, 'tag' => (string) $tag, 'at' => time() );
			$subs[ $key ]['queue'] = array_slice( $q, -self::QUEUED );
			self::$outbox[]        = $s['endpoint'];
			$n++;
		}
		if ( $n ) {
			self::save_subs( $subs );
			if ( ! has_action( 'shutdown', array( __CLASS__, 'flush' ) ) ) {
				add_action( 'shutdown', array( __CLASS__, 'flush' ) );
			}
		}
		do_action( 'oys_push_notified', $user_id, $title, $body, $url, $tag, $n );
		return $n;
	}

	/** At the end of the request (after the answer, where the server allows it): the pushes. */
	public static function flush() {
		$out          = array_unique( self::$outbox );
		self::$outbox = array();
		if ( ! $out ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) && ! defined( 'WP_CLI' ) && ! defined( 'OYS_TESTING' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $out as $endpoint ) {
			self::ping( $endpoint );
		}
	}

	/** An empty push, „high” urgency so a sleeping phone gets it at once. @return int HTTP code (0: error) */
	public static function ping( $endpoint ) {
		$v = self::vapid();
		if ( ! $v || ! self::valid_endpoint( $endpoint ) ) {
			return 0;
		}
		$res  = wp_remote_post( $endpoint, array(
			'timeout' => 8,
			'headers' => array( 'TTL' => '86400', 'Urgency' => 'high', 'Authorization' => 'vapid t=' . self::jwt( $endpoint, $v ) . ', k=' . $v['public'], 'Content-Length' => '0' ),
			'body'    => '',
		) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 404, 410 ), true ) ) { // the device unsubscribed / expired
			self::unsubscribe( 0, $endpoint );
		}
		return $code;
	}

	/** The service worker takes the oldest waiting notification (one push = one notification). */
	public static function pull( $endpoint ) {
		$subs = self::subs();
		$key  = md5( (string) $endpoint );
		if ( '' === (string) $endpoint || empty( $subs[ $key ]['queue'] ) ) {
			return array();
		}
		$item                 = array_shift( $subs[ $key ]['queue'] );
		$subs[ $key ]['last'] = time();
		self::save_subs( $subs );
		return array( array_intersect_key( $item, array_flip( array( 'title', 'body', 'url', 'tag' ) ) ) );
	}

	/* ---------- What makes a notification ---------- */

	/** A customer wrote (account, website form, private request): everyone who sees that conversation and has a device. */
	public static function on_message( $message_id, $thread, $studio ) {
		if ( $studio || ! $thread || ! self::subs() ) {
			return;
		}
		global $wpdb;
		$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT body, image, kind FROM ' . OYS_Hub::t( 'thread_msgs' ) . ' WHERE id = %d', (int) $message_id ) );
		$who  = $thread->name ?: $thread->email;
		$kind = $m ? (string) $m->kind : '';
		$text = $m && '' !== (string) $m->body ? preg_replace( '/\s+/', ' ', (string) $m->body ) : '📷 ' . __( 'Photo', 'olivia-studio' );
		$title = 'request' === $kind ? sprintf( __( 'Private session request: %s', 'olivia-studio' ), $who )
			: ( 'contact' === $kind ? sprintf( __( 'Website: %s', 'olivia-studio' ), $who ) : $who );
		foreach ( self::subscribers() as $uid ) {
			if ( OYS_Hub::can( $uid ) && OYS_Hub::can_thread( $thread, $uid ) ) {
				self::notify( $uid, $title, $text, self::url( 't' . (int) $thread->id ), 't' . (int) $thread->id );
			}
		}
	}

	/** Someone wrote in a class chat – not while the class is on (she's teaching, the chat is on her screen). */
	public static function on_class_chat( $message_id, $session, $who ) {
		if ( ! empty( $who['owner'] ) || ! empty( $who['test'] ) || ! $session || ! self::subs() ) {
			return;
		}
		if ( time() >= oys_ts( $session->starts_at ) - 15 * MINUTE_IN_SECONDS && time() <= oys_ts( $session->ends_at ) ) {
			return;
		}
		global $wpdb;
		$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT body, image FROM ' . OYS_Hub::t( 'live_chat' ) . ' WHERE id = %d', (int) $message_id ) );
		$text = $m && '' !== (string) $m->body ? preg_replace( '/\s+/', ' ', (string) $m->body ) : '📷 ' . __( 'Photo', 'olivia-studio' );
		foreach ( self::subscribers() as $uid ) {
			if ( OYS_Hub::can( $uid ) && OYS_Hub::can_session( $session, $uid ) ) {
				self::notify( $uid, sprintf( __( 'Class chat: %s', 'olivia-studio' ), oys_session_title( $session ) . ', ' . oys_date( $session->starts_at, 'M j' ) ), ( $who['name'] ?? '' ) . ': ' . $text, self::url( 'c' . (int) $session->id ), 'c' . (int) $session->id );
			}
		}
	}

	/* ---------- REST (Hub → My settings) ---------- */

	public static function routes() {
		$can = fn() => OYS_Hub::can();
		register_rest_route( OYS_Hub::NS, '/hub/push', array(
			array( 'methods' => 'GET', 'permission_callback' => $can, 'callback' => fn() => self::state() ),
			array( 'methods' => 'POST', 'permission_callback' => $can, 'callback' => function ( WP_REST_Request $r ) {
				if ( ! self::vapid() ) {
					return new WP_Error( 'oys_push', __( 'Notifications are not available on this server.', 'olivia-studio' ), array( 'status' => 500 ) );
				}
				if ( ! self::subscribe( get_current_user_id(), (string) $r->get_param( 'endpoint' ), (string) $r->get_param( 'ua' ) ) ) {
					return new WP_Error( 'oys_push', __( 'This device could not be switched on.', 'olivia-studio' ), array( 'status' => 400 ) );
				}
				return self::state();
			} ),
			array( 'methods' => 'DELETE', 'permission_callback' => $can, 'callback' => function ( WP_REST_Request $r ) {
				self::unsubscribe( get_current_user_id(), (string) $r->get_param( 'endpoint' ) );
				return self::state();
			} ),
		) );
		register_rest_route( OYS_Hub::NS, '/hub/push/test', array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function () {
				$n = self::notify( get_current_user_id(), self::app_name()['name'], __( 'Notifications work on this device. 🌿', 'olivia-studio' ), self::url( 'settings' ), 'test' );
				return array( 'devices' => $n );
			},
		) );
	}

	private static function state() {
		$v = self::vapid();
		return array( 'key' => $v ? $v['public'] : '', 'devices' => self::devices( get_current_user_id() ), 'link' => self::url(), 'guide' => self::guide_url() );
	}

	/* ---------- The guide ---------- */

	public static function render_guide() {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$lang = substr( get_locale(), 0, 2 );
		$file = OYS_DIR . 'includes/views/studio-guide-' . $lang . '.php';
		$file = is_readable( $file ) ? $file : OYS_DIR . 'includes/views/studio-guide-hu.php';
		$app  = array(
			'name'   => self::app_name(),
			'link'   => self::url(),
			'guide'  => self::guide_url(),
			'hub'    => self::hub_target( '' ),
			'head'   => self::head_tags(),
			'css'    => OYS_URL . 'assets/guide.css?ver=' . OYS_VERSION,
			'icon'   => self::icon( 'icon-192.png' ),
			'manage' => current_user_can( 'oys_manage' ),
		);
		include $file;
	}
}

OYS_Studio_App::init();
