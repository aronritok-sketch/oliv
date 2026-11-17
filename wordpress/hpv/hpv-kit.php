<?php
/**
 * HelloProVision élesítési csomag (Áron, 2026-11-17: „rakjuk be … az összes HelloProVision-os marketing eszközt, hogy
 * elérjük ezeket az oldalakat, meg a hotpotot is kössük be. … Történjen mindig ugyanaz.”).
 *
 * Az éles oldalon (iu_theme: Site::env() === 'live') a gyerektéma minden beállító körében – élesítéskor és minden új
 * kiadás után – ugyanazt teszi, kézi lépés nélkül:
 *   1. a beállító varázsló (helloprovision-setup) a hlprv GitHub-kiadásából, „weboldal” módban, bekapcsolva – ez a
 *      HelloProVision távirányítója is (hpv-setup/v1/remote/…: állapot, kulcsok, párosítás, bővítmények, mentés);
 *   2. a varázsló weboldal-bővítményei: Ads Connect (hirdetési konverziók → Ads hub) és HotPot Connect (karbantartás);
 *   3. a weboldal mu-pluginjai: űrlapok → CRM (Leads) és a SEO schema javítások.
 * Ami már fent van, ahhoz nem nyúl (a frissítést a bővítmények saját GitHub-frissítője végzi). Kulcsot, kapcsolati
 * kódot nem tartalmaz és nem kér: a párosítás a varázsló távirányítóján megy (POST hpv-setup/v1/remote/keys
 * {"HPV_HOTPOT_PAIR_CODE": "…"}), vagy az adminban (Beállítások → HelloProVision HotPot).
 *
 * A bővítményeket a saját GitHub-címkéjükről teszi fel (az iu_theme frissítőjével: git/matching-refs/tags + a kiadás
 * csatolmánya), nem a varázsló kiadáslistájából: az csak a legújabb 1000 kiadást látja, a hlprv-ben pedig 1700 fölött
 * vannak, így a ritkán frissülő HotPot Connect „nincs kiadás” lett (2026-11-17, mindkét Olívia-oldalon).
 *
 * Dev és staging oldalon nem fut; kikapcsolás: define( 'HPV_KIT', false ). Ha valami nem sikerült (pl. a GitHub épp nem
 * válaszolt, vagy a kör félbeszakadt), óránként újrapróbálja, legfeljebb 24-szer; naponta egyszer megnézi, hiányzik-e
 * valami (pl. valaki kikapcsolta), és ha igen, visszateszi. Az utolsó kör: get_option( 'hpv_kit_log' ).
 *
 * Közös fájl: az oliv repó wordpress/hpv/hpv-kit.php-ja kerül minden Olívia-témába (build-child-themes.py, és az
 * oliviajoga-hu/build.py --wp) – itt szerkeszd.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'HPV_KIT_PLUGINS' ) ) {
	define( 'HPV_KIT_PLUGINS', array( 'helloprovision-ads-connect', 'helloprovision-hotpot-connect' ) );
	define( 'HPV_KIT_MU', array( 'helloprovision-leads.php', 'helloprovision-seo.php' ) );
	define( 'HPV_KIT_SETUP', 'helloprovision-setup/helloprovision-setup.php' );
}

add_action( 'hpv_child_setup', 'hpv_kit_run', 90, 0 ); // a téma saját beállítása után
add_action( 'hpv_kit_retry', 'hpv_kit_run', 10, 0 );
add_action( 'hpv_kit_daily', function () {
	if ( hpv_kit_missing() ) {
		hpv_kit_run();
	}
} );
add_action( 'init', function () { // a napi önellenőrzés ütemezése (olcsó: csak a cron-listát nézi; a futás dönti el, kell-e)
	if ( ! wp_next_scheduled( 'hpv_kit_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hpv_kit_daily' );
	}
} );

/** Fusson-e itt: csak az éles oldalon, az iu_theme frissítőjével (tőle jön a GitHub-hozzáférés). */
function hpv_kit_on(): bool {
	if ( defined( 'HPV_KIT' ) && ! HPV_KIT ) {
		return false;
	}
	if ( ! class_exists( '\IUTheme\Site' ) || ! class_exists( '\IUTheme\Updater' ) || '' === \IUTheme\Updater::token() ) {
		return false;
	}
	return 'live' === \IUTheme\Site::env();
}

/** A csomag egy köre. Visszaadja a naplót (sorok); a hibás sorok „hiba:”-val kezdődnek. */
function hpv_kit_run(): array {
	if ( ! hpv_kit_on() ) {
		return array();
	}
	if ( get_transient( 'hpv_kit_lock' ) ) {
		return array( 'már fut' );
	}
	set_transient( 'hpv_kit_lock', 1, 10 * MINUTE_IN_SECONDS );
	// Ha a kör félbeszakad (időkorlát), az újrapróba így is megmarad; sikeres kör végén törlődik.
	if ( (int) get_option( 'hpv_kit_tries', 0 ) < 24 && ! wp_next_scheduled( 'hpv_kit_retry' ) ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'hpv_kit_retry' );
	}
	$log = array();
	$bad = false;
	try {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! get_option( 'hpv_setup_mode' ) ) {
			update_option( 'hpv_setup_mode', 'site', false ); // ügyféloldal – a varázsló ne találgasson
		}

		// 1. A beállító varázsló.
		// 1–2. A beállító varázsló, aztán a weboldal-bővítmények: a GitHub-címkéjükről, majd bekapcsolva.
		foreach ( array_merge( array( 'helloprovision-setup' ), HPV_KIT_PLUGINS ) as $slug ) {
			$main  = $slug . '/' . $slug . '.php';
			$label = 'helloprovision-setup' === $slug ? 'beállító varázsló' : $slug;
			if ( ! is_file( WP_PLUGIN_DIR . '/' . $main ) ) {
				$res   = hpv_kit_install_release( $slug );
				$log[] = $label . ': ' . $res;
				$bad   = $bad || str_starts_with( $res, 'hiba' );
			}
			if ( is_file( WP_PLUGIN_DIR . '/' . $main ) && ! is_plugin_active( $main ) ) {
				$act = activate_plugin( $main );
				if ( 'helloprovision-setup' === $slug ) {
					delete_transient( 'hpv_setup_redirect' ); // az admin ne ugorjon a varázslóba a következő belépéskor
				}
				$log[] = is_wp_error( $act ) ? 'hiba: ' . $label . ' nem kapcsolható be – ' . $act->get_error_message() : $label . ' bekapcsolva';
				$bad   = $bad || is_wp_error( $act );
			}
		}

		// 3. A weboldal mu-pluginjai (ügyféloldalon alapból egyik sincs kijelölve – a csomag kijelöli őket).
		if ( function_exists( 'hpv_setup_install_mu' ) && function_exists( 'hpv_setup_mu_wanted' ) && function_exists( 'hpv_setup_mu_set_wanted' ) ) {
			$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
			hpv_setup_mu_set_wanted( array_values( array_unique( array_merge( hpv_setup_mu_wanted(), HPV_KIT_MU ) ) ) );
			if ( array_filter( HPV_KIT_MU, fn( $f ) => ! is_file( $dir . '/' . $f ) ) ) {
				foreach ( hpv_setup_install_mu() as $file => $res ) {
					$log[] = $file . ': ' . ( 'ok' === $res ? 'kirakva' : ( 'removed' === $res ? 'levéve' : 'hiba: ' . $res ) );
					$bad   = $bad || ! in_array( $res, array( 'ok', 'removed' ), true );
				}
			}
		}
	} catch ( Throwable $e ) {
		$log[] = 'hiba: ' . $e->getMessage();
		$bad   = true;
	} finally {
		delete_transient( 'hpv_kit_lock' );
	}

	if ( ! $log ) {
		$log[] = 'minden fent van';
	}
	$tries = $bad ? (int) get_option( 'hpv_kit_tries', 0 ) + 1 : 0;
	update_option( 'hpv_kit_tries', $tries, false );
	update_option( 'hpv_kit_log', array( 'time' => time(), 'ok' => ! $bad, 'log' => $log ), false );
	if ( ! $bad || $tries >= 24 ) {
		wp_clear_scheduled_hook( 'hpv_kit_retry' );
	}
	return $log;
}

/** Hiányzik-e valami a csomagból (a napi önellenőrzéshez; hálózat nélkül). */
function hpv_kit_missing(): bool {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	foreach ( array_merge( array( 'helloprovision-setup' ), HPV_KIT_PLUGINS ) as $slug ) {
		if ( ! is_plugin_active( $slug . '/' . $slug . '.php' ) ) {
			return true;
		}
	}
	return (bool) array_filter( HPV_KIT_MU, fn( $f ) => ! is_file( $dir . '/' . $f ) );
}

/** Egy HelloProVision bővítmény legújabb kiadása a címkéjéről („<slug>-v<verzió>”, csatolmány: „<slug>.zip”); a letöltést
 *  az iu_theme frissítője hitelesíti a tokennel. */
function hpv_kit_install_release( string $slug ): string {
	$all = \IUTheme\Site::versions( $slug );
	$ver = (string) end( $all );
	$url = '' !== $ver ? \IUTheme\Site::asset( $slug, $ver ) : '';
	if ( '' === $url ) {
		return 'hiba: nincs elérhető kiadás a GitHubon (HPV_GITHUB_TOKEN?)';
	}
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	if ( ! WP_Filesystem() ) {
		return 'hiba: a WordPress nem tud fájlt írni a bővítmények mappájába (FS_METHOD)';
	}
	$skin = new Automatic_Upgrader_Skin();
	$ok   = ( new Plugin_Upgrader( $skin ) )->install( $url );
	wp_clean_plugins_cache( false );
	if ( is_wp_error( $ok ) ) {
		return 'hiba: ' . $ok->get_error_message();
	}
	return $ok ? $ver . ' telepítve' : 'hiba: ' . wp_strip_all_tags( implode( ' ', (array) $skin->get_upgrade_messages() ) );
}

/** Röviden, titok nélkül: mi van fent a csomagból (a távirányítók állapotához). */
function hpv_kit_status(): array {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$out = array( 'on' => hpv_kit_on(), 'helloprovision-setup' => is_plugin_active( HPV_KIT_SETUP ) );
	foreach ( HPV_KIT_PLUGINS as $s ) {
		$out[ $s ] = is_plugin_active( $s . '/' . $s . '.php' );
	}
	foreach ( HPV_KIT_MU as $f ) {
		$out[ $f ] = is_file( $dir . '/' . $f );
	}
	$out['hotpot_paired'] = function_exists( 'hpvhc_connection' ) ? (bool) hpvhc_connection() : false;
	$out['last']          = get_option( 'hpv_kit_log', null );
	return $out;
}
