<?php
/**
 * Every page: the old site's page (content from the editor, the rest from data/site.json).
 */

defined( 'ABSPATH' ) || exit;
$oj_path = oj_path();
$oj_page = '404' === $oj_path ? array( 'cls' => 'is-page', 'prefooter' => oj_data()['pages']['']['prefooter'] ?? '' ) : oj_page( $oj_path );
if ( '404' === $oj_path ) {
	status_header( 404 );
}
?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='20' fill='%232B5036'/%3E%3Ccircle cx='31' cy='16' r='5' fill='%23EE3F9A'/%3E%3C/svg%3E">
<?php wp_head(); ?>
</head>
<body <?php body_class( $oj_page['cls'] ); ?>>
<?php wp_body_open(); ?>
<?php echo oj_header( $oj_path ); // phpcs:ignore -- built HTML ?>
<main id="main">
<?php echo oj_main(); // phpcs:ignore -- built HTML ?>
</main>
<?php echo oj_fill( $oj_page['prefooter'] ?? '' ); // phpcs:ignore -- built HTML ?>
<?php echo oj_fill( oj_data()['footer'] ?? '' ); // phpcs:ignore -- built HTML ?>
<?php wp_footer(); ?>
</body>
</html>
