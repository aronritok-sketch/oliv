<?php
// Router for PHP's built-in server so WordPress pretty permalinks work locally.
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $path !== '/' && file_exists( __DIR__ . $path ) && ! is_dir( __DIR__ . $path ) ) {
	return false;
}
if ( is_dir( __DIR__ . $path ) && file_exists( rtrim( __DIR__ . $path, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
	require rtrim( __DIR__ . $path, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
