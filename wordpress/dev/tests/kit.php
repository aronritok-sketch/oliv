<?php
/**
 * A HelloProVision élesítési csomag (wordpress/hpv/hpv-kit.php) próbája egy valódi WordPressen:
 *   WP_DIR=/path/to/wordpress HPV_SETUP_SRC=/path/to/hlprv php wordpress/dev/tests/kit.php
 * A bővítményeket a hlprv repóból csomagolja (ugyanúgy, mint a kiadás: a varázslóba bundle/mu-plugins), hálózatot nem
 * ér el (minden külső kérés helyben hibával tér vissza), és a végén mindent levesz, amit feltett. Nem fut a run.php-val
 * együtt: fájlokat ír a bővítmények és a mu-plugins mappába.
 */

$wp_dir = getenv( 'WP_DIR' ) ?: '';
$hlprv  = getenv( 'HPV_SETUP_SRC' ) ?: '';
if ( ! $wp_dir || ! file_exists( $wp_dir . '/wp-load.php' ) || ! is_dir( $hlprv . '/plugins/helloprovision-setup' ) ) {
	fwrite( STDERR, "Set WP_DIR (WordPress) and HPV_SETUP_SRC (the hlprv repo).\n" );
	exit( 2 );
}
$_SERVER['REMOTE_ADDR'] = '10.9.8.7';
$_SERVER['HTTP_HOST']   = '127.0.0.1:8080';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
require $wp_dir . '/wp-load.php';
require_once __DIR__ . '/fixtures/iu-theme-fake.php';
require_once dirname( __DIR__, 2 ) . '/hpv/hpv-kit.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$pass = 0;
$fail = 0;
$ok   = function ( $cond, $msg ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL $msg\n";
	}
};

$slugs = array( 'helloprovision-setup', 'helloprovision-ads-connect', 'helloprovision-hotpot-connect' );
foreach ( $slugs as $slug ) {
	if ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) ) {
		fwrite( STDERR, "A $slug már fent van ebben a WordPressben – a próba nem nyúl hozzá.\n" );
		exit( 2 );
	}
}

// A „kiadások”: a hlprv plugins/<slug> mappái zipben; a varázslóba a bundle/mu-plugins is (mint a plugin-releases.yml).
$zips = array();
$pack = function ( string $slug ) use ( $hlprv, &$zips ) {
	$src = $hlprv . '/plugins/' . $slug;
	$zip = sys_get_temp_dir() . '/hpv-kit-test-' . getmypid() . '-' . $slug . '.zip';
	$z   = new ZipArchive();
	$z->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$z->addFile( $f->getPathname(), $slug . '/' . substr( $f->getPathname(), strlen( $src ) + 1 ) );
	}
	if ( 'helloprovision-setup' === $slug ) {
		foreach ( glob( $hlprv . '/mu-plugins/*.php' ) as $f ) {
			$z->addFile( $f, $slug . '/bundle/mu-plugins/' . basename( $f ) );
		}
	}
	$z->close();
	$zips[] = $zip;
	return $zip;
};
$env = fn( string $slug ) => 'HPV_KIT_TEST_ZIP_' . strtoupper( str_replace( '-', '_', $slug ) );

// Hálózat nincs: minden külső kérés helyben hibával tér vissza.
add_filter( 'pre_http_request', fn( $pre, $args, $url ) => str_contains( (string) $url, '127.0.0.1' ) ? $pre : new WP_Error( 'offline', 'a próbában nincs hálózat' ), 10, 3 );

$mu      = WPMU_PLUGIN_DIR;
$had_mu  = array_filter( array( 'helloprovision-leads.php', 'helloprovision-seo.php', '0-hpv-config.php' ), fn( $f ) => is_file( $mu . '/' . $f ) );
$cleanup = function () use ( $mu, $had_mu, &$zips, $slugs ) {
	foreach ( array_reverse( $slugs ) as $slug ) {
		$main = $slug . '/' . $slug . '.php';
		if ( is_file( WP_PLUGIN_DIR . '/' . $main ) ) {
			deactivate_plugins( $main, true );
			if ( is_file( WP_PLUGIN_DIR . '/' . $slug . '/uninstall.php' ) ) {
				uninstall_plugin( $main ); // a saját táblái, beállításai, ütemezései
			}
		}
	}
	foreach ( array( 'helloprovision-leads.php', 'helloprovision-seo.php', '0-hpv-config.php' ) as $f ) {
		if ( ! in_array( $f, $had_mu, true ) && is_file( $mu . '/' . $f ) ) {
			unlink( $mu . '/' . $f );
		}
	}
	WP_Filesystem();
	global $wp_filesystem;
	foreach ( $slugs as $slug ) {
		$wp_filesystem->delete( WP_PLUGIN_DIR . '/' . $slug, true );
	}
	foreach ( array( 'hpv_kit_log', 'hpv_kit_tries', 'hpv_setup_mode', 'hpv_setup_mu_wanted' ) as $o ) {
		delete_option( $o );
	}
	wp_clear_scheduled_hook( 'hpv_kit_retry' );
	wp_clear_scheduled_hook( 'hpv_kit_daily' );
	delete_transient( 'hpv_setup_redirect' );
	wp_clean_plugins_cache( false );
	foreach ( $zips as $z ) {
		@unlink( $z ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
};

try {
	// Nem élő környezetben semmi nem történik.
	putenv( 'HPV_KIT_TEST_ENV=dev' );
	putenv( $env( 'helloprovision-setup' ) . '=' . $pack( 'helloprovision-setup' ) );
	$ok( array() === hpv_kit_run() && ! is_dir( WP_PLUGIN_DIR . '/helloprovision-setup' ), 'dev: a csomag nem fut' );
	putenv( 'HPV_KIT_TEST_ENV=live' );

	// 1. kör: csak a varázslónak van kiadása – a két Connect „nincs kiadás”.
	$log = hpv_kit_run();
	echo '  1. kör: ' . implode( ' | ', $log ) . "\n";
	$ok( is_plugin_active( HPV_KIT_SETUP ), 'a varázsló települt és be van kapcsolva' );
	$ok( 'site' === get_option( 'hpv_setup_mode' ), 'weboldal mód' );
	$ok( false === get_transient( 'hpv_setup_redirect' ), 'az admin nem ugrik a varázslóba' );
	$ok( (bool) preg_grep( '/^beállító varázsló: 9\.9\.9 telepítve$/u', $log ), 'a napló: telepítve (9.9.9)' );
	$ok( is_file( $mu . '/helloprovision-leads.php' ) && is_file( $mu . '/helloprovision-seo.php' ), 'a Leads és a SEO mu-plugin kint van' );
	$ok( ! is_file( $mu . '/helloprovision-site.php' ), 'a helloprovision.com mu-pluginja nem kerül ki' );
	$ok( (bool) preg_grep( '/^helloprovision-hotpot-connect: hiba: nincs elérhető kiadás/u', $log ), 'kiadás nélkül a HotPot Connect hibát jelez' );
	$st = get_option( 'hpv_kit_log' );
	$ok( is_array( $st ) && false === $st['ok'] && $st['log'] === $log, 'a napló elmentve, hibával' );
	$ok( (bool) wp_next_scheduled( 'hpv_kit_retry' ), 'egy óra múlva újra' );
	$ok( 1 === (int) get_option( 'hpv_kit_tries' ), 'első próbálkozás' );
	$ok( hpv_kit_missing(), 'hiányzik valami' );

	// 2. kör: megjött a két Connect kiadása – feltelepülnek, a varázslóhoz és a mu-pluginokhoz nem nyúl.
	putenv( $env( 'helloprovision-ads-connect' ) . '=' . $pack( 'helloprovision-ads-connect' ) );
	putenv( $env( 'helloprovision-hotpot-connect' ) . '=' . $pack( 'helloprovision-hotpot-connect' ) );
	$log2 = hpv_kit_run();
	echo '  2. kör: ' . implode( ' | ', $log2 ) . "\n";
	$ok( ! preg_grep( '/^beállító varázsló/u', $log2 ), 'a varázslót nem telepíti újra' );
	$ok( ! preg_grep( '/\.php: /u', $log2 ), 'a mu-pluginok maradnak' );
	$ok( is_plugin_active( 'helloprovision-ads-connect/helloprovision-ads-connect.php' ), 'az Ads Connect be van kapcsolva' );
	$ok( is_plugin_active( 'helloprovision-hotpot-connect/helloprovision-hotpot-connect.php' ), 'a HotPot Connect be van kapcsolva' );
	$ok( true === get_option( 'hpv_kit_log' )['ok'] && 0 === (int) get_option( 'hpv_kit_tries' ), 'hiba nélkül, számláló nullázva' );
	$ok( ! wp_next_scheduled( 'hpv_kit_retry' ), 'nincs több újrapróba' );
	$ok( ! hpv_kit_missing(), 'semmi nem hiányzik' );
	$s = hpv_kit_status();
	$ok( true === $s['helloprovision-setup'] && true === $s['helloprovision-hotpot-connect'] && true === $s['helloprovision-leads.php'], 'állapot' );
	$ok( false === $s['hotpot_paired'], 'nincs párosítva' );
	$ok( ! preg_match( '/test-token|secret|pass/i', wp_json_encode( array_diff_key( $s, array( 'last' => 1 ) ) ) ), 'az állapotban nincs titok' );

	// 3. kör: minden fent – nem csinál semmit.
	$ok( array( 'minden fent van' ) === hpv_kit_run(), 'a harmadik körben nincs teendő' );

	// Napi önellenőrzés: valaki kikapcsolta a HotPot Connectet → visszakapcsolja.
	deactivate_plugins( 'helloprovision-hotpot-connect/helloprovision-hotpot-connect.php', true );
	$ok( hpv_kit_missing(), 'a kikapcsolt HotPot Connect hiányként látszik' );
	do_action( 'hpv_kit_daily' );
	$ok( is_plugin_active( 'helloprovision-hotpot-connect/helloprovision-hotpot-connect.php' ), 'a napi kör visszakapcsolta' );
	do_action( 'init' );
	$ok( (bool) wp_next_scheduled( 'hpv_kit_daily' ), 'a napi kör ütemezve' );

	// Egyszerre csak egy kör.
	set_transient( 'hpv_kit_lock', 1, 60 );
	$ok( array( 'már fut' ) === hpv_kit_run(), 'egyszerre csak egy kör' );
	delete_transient( 'hpv_kit_lock' );
} catch ( Throwable $e ) {
	$fail++;
	echo '  FAIL kivétel: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
} finally {
	$cleanup();
}
$ok( ! is_dir( WP_PLUGIN_DIR . '/helloprovision-setup' ) && ! is_dir( WP_PLUGIN_DIR . '/helloprovision-hotpot-connect' ), 'takarítás: a bővítmények lekerültek' );

echo "\n$pass assertions passed, $fail failed\n";
exit( $fail ? 1 : 0 );
