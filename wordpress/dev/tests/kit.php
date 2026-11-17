<?php
/**
 * A HelloProVision élesítési csomag (wordpress/hpv/hpv-kit.php) próbája egy valódi WordPressen:
 *   WP_DIR=/path/to/wordpress HPV_SETUP_SRC=/path/to/hlprv php wordpress/dev/tests/kit.php
 * A beállító varázslót a hlprv repóból csomagolja (ugyanúgy, mint a kiadás: bundle/mu-plugins), a GitHubot nem éri el
 * (a kérések helyben hibával térnek vissza), és a végén mindent levesz, amit feltett. Nem fut a run.php-val együtt:
 * fájlokat ír a bővítmények és a mu-plugins mappába.
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

// A varázsló „kiadása”: a hlprv plugins/helloprovision-setup + bundle/mu-plugins (mint a plugin-releases.yml).
$zip = sys_get_temp_dir() . '/hpv-kit-test-' . getmypid() . '.zip';
$z   = new ZipArchive();
$z->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$src = $hlprv . '/plugins/helloprovision-setup';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$z->addFile( $f->getPathname(), 'helloprovision-setup/' . substr( $f->getPathname(), strlen( $src ) + 1 ) );
}
foreach ( glob( $hlprv . '/mu-plugins/*.php' ) as $f ) {
	$z->addFile( $f, 'helloprovision-setup/bundle/mu-plugins/' . basename( $f ) );
}
$z->close();
putenv( 'HPV_KIT_TEST_ZIP=' . $zip );

// Hálózat nincs: a GitHub-kérések (kiadások listája) helyben hibával térnek vissza.
add_filter( 'pre_http_request', fn( $pre, $args, $url ) => str_contains( (string) $url, 'github' ) ? new WP_Error( 'offline', 'a próbában nincs hálózat' ) : $pre, 10, 3 );

$mu      = WPMU_PLUGIN_DIR;
$had_mu  = array_filter( array( 'helloprovision-leads.php', 'helloprovision-seo.php', '0-hpv-config.php' ), fn( $f ) => is_file( $mu . '/' . $f ) );
$cleanup = function () use ( $mu, $had_mu, $zip ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	deactivate_plugins( HPV_KIT_SETUP, true );
	foreach ( array( 'helloprovision-leads.php', 'helloprovision-seo.php', '0-hpv-config.php' ) as $f ) {
		if ( ! in_array( $f, $had_mu, true ) && is_file( $mu . '/' . $f ) ) {
			unlink( $mu . '/' . $f );
		}
	}
	WP_Filesystem();
	global $wp_filesystem;
	$wp_filesystem->delete( WP_PLUGIN_DIR . '/helloprovision-setup', true );
	foreach ( array( 'hpv_kit_log', 'hpv_kit_tries', 'hpv_setup_mode', 'hpv_setup_mu_wanted' ) as $o ) {
		delete_option( $o );
	}
	wp_clear_scheduled_hook( 'hpv_kit_retry' );
	delete_transient( 'hpv_setup_redirect' );
	wp_clean_plugins_cache( false );
	@unlink( $zip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
};
if ( is_dir( WP_PLUGIN_DIR . '/helloprovision-setup' ) ) {
	fwrite( STDERR, "A helloprovision-setup már fent van ebben a WordPressben – a próba nem nyúl hozzá.\n" );
	exit( 2 );
}

try {
	// Nem élő környezetben semmi nem történik.
	putenv( 'HPV_KIT_TEST_ENV=dev' );
	$ok( array() === hpv_kit_run() && ! is_dir( WP_PLUGIN_DIR . '/helloprovision-setup' ), 'dev: a csomag nem fut' );
	putenv( 'HPV_KIT_TEST_ENV=live' );

	// Első kör az éles oldalon.
	$log = hpv_kit_run();
	echo '  napló: ' . implode( ' | ', $log ) . "\n";
	$ok( is_file( WP_PLUGIN_DIR . '/' . HPV_KIT_SETUP ), 'a varázsló települt' );
	$ok( is_plugin_active( HPV_KIT_SETUP ), 'a varázsló be van kapcsolva' );
	$ok( 'site' === get_option( 'hpv_setup_mode' ), 'weboldal mód' );
	$ok( false === get_transient( 'hpv_setup_redirect' ), 'az admin nem ugrik a varázslóba' );
	$ok( (bool) preg_grep( '/^beállító varázsló: 9\.9\.9 telepítve$/u', $log ), 'a napló: telepítve (9.9.9)' );
	$ok( is_file( $mu . '/helloprovision-leads.php' ) && is_file( $mu . '/helloprovision-seo.php' ), 'a Leads és a SEO mu-plugin kint van' );
	$ok( ! is_file( $mu . '/helloprovision-site.php' ), 'a helloprovision.com mu-pluginja nem kerül ki' );
	$ok( (bool) preg_grep( '/^helloprovision-hotpot-connect: hiba: /u', $log ) && (bool) preg_grep( '/^helloprovision-ads-connect: hiba: /u', $log ), 'GitHub nélkül a két Connect hibát jelez' );
	$st = get_option( 'hpv_kit_log' );
	$ok( is_array( $st ) && false === $st['ok'] && $st['log'] === $log, 'a napló elmentve, hibával' );
	$ok( (bool) wp_next_scheduled( 'hpv_kit_retry' ), 'egy óra múlva újra' );
	$ok( 1 === (int) get_option( 'hpv_kit_tries' ), 'első próbálkozás' );
	$s = hpv_kit_status();
	$ok( true === $s['helloprovision-setup'] && true === $s['helloprovision-leads.php'] && false === $s['helloprovision-hotpot-connect'], 'állapot' );
	$ok( false === $s['hotpot_paired'], 'nincs párosítva' );
	$ok( ! preg_match( '/test-token|secret|pass/i', wp_json_encode( array_diff_key( $s, array( 'last' => 1 ) ) ) ), 'az állapotban nincs titok' );

	// Második kör: a varázslót nem teszi fel újra, a mu-pluginokhoz nem nyúl.
	$log2 = hpv_kit_run();
	$ok( ! preg_grep( '/^beállító varázsló/u', $log2 ), 'a második körben a varázslót nem telepíti újra' );
	$ok( ! preg_grep( '/mu|\.php: /u', $log2 ), 'a mu-pluginok maradnak' );
	$ok( 2 === (int) get_option( 'hpv_kit_tries' ), 'második próbálkozás' );

	// Kikapcsolva (HPV_KIT false) – ezt a próbában nem definiálhatjuk újra, ezért csak a zárat nézzük: fut-e kétszer egyszerre.
	set_transient( 'hpv_kit_lock', 1, 60 );
	$ok( array( 'már fut' ) === hpv_kit_run(), 'egyszerre csak egy kör' );
	delete_transient( 'hpv_kit_lock' );
} catch ( Throwable $e ) {
	$fail++;
	echo '  FAIL kivétel: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
} finally {
	$cleanup();
}
$ok( ! is_dir( WP_PLUGIN_DIR . '/helloprovision-setup' ), 'takarítás: a varázsló lekerült' );

echo "\n$pass assertions passed, $fail failed\n";
exit( $fail ? 1 : 0 );
