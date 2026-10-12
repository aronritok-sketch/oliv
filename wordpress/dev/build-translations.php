<?php
/**
 * Compile the translation sources (.po) of the theme and the plugin into the files WordPress
 * loads: .mo and the faster .l10n.php (WordPress 6.5+).
 *
 *   WP_DIR=/path/to/wordpress php wordpress/dev/build-translations.php
 *
 * Edit the .po files (by hand or in Poedit), then run this and commit all three files.
 */

$wp = getenv( 'WP_DIR' ) ?: '/tmp/wp-ci';
if ( ! is_file( "$wp/wp-includes/pomo/po.php" ) ) {
	fwrite( STDERR, "Set WP_DIR to a WordPress folder (needs wp-includes/pomo and l10n).\n" );
	exit( 1 );
}
define( 'ABSPATH', rtrim( $wp, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/pomo/po.php';
require ABSPATH . WPINC . '/pomo/mo.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-file.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-file-mo.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-file-php.php';

$root  = dirname( __DIR__ );
$files = array_merge( glob( "$root/olivia-yoga/languages/*.po" ), glob( "$root/olivia-studio/languages/*.po" ) );
$fail  = 0;
foreach ( $files as $po_file ) {
	$po = new PO();
	if ( ! $po->import_from_file( $po_file ) ) {
		fwrite( STDERR, "Could not read $po_file\n" );
		$fail = 1;
		continue;
	}
	// Untranslated entries are left out, so WordPress shows the English original.
	foreach ( $po->entries as $key => $entry ) {
		if ( ! array_filter( $entry->translations, 'strlen' ) ) {
			unset( $po->entries[ $key ] );
		}
	}
	$mo = new MO();
	$mo->set_headers( $po->headers );
	foreach ( $po->entries as $entry ) {
		$mo->add_entry( $entry );
	}
	$mo_file = substr( $po_file, 0, -3 ) . '.mo';
	$mo->export_to_file( $mo_file );
	$php = WP_Translation_File::transform( $mo_file, 'php' );
	if ( false === $php ) {
		fwrite( STDERR, "Could not convert $mo_file\n" );
		$fail = 1;
		continue;
	}
	file_put_contents( substr( $po_file, 0, -3 ) . '.l10n.php', $php );
	printf( "%s: %d strings\n", basename( $po_file ), count( $po->entries ) );
}
exit( $fail );
