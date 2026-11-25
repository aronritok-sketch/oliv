<?php
/**
 * Every page: the old site's page (content from the editor, the rest from data/site.json).
 */

defined( 'ABSPATH' ) || exit;
$oj_path = oj_path();
$oj_page = '404' === $oj_path ? array( 'cls' => 'is-page', 'prefooter' => oj_page( '' )['prefooter'] ?? '' ) : oj_page( $oj_path );
if ( '404' === $oj_path ) {
	status_header( 404 );
}
?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php echo oj_icon_links(); // phpcs:ignore -- built from the theme's own addresses ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( $oj_page['cls'] ); ?>>
<?php wp_body_open(); ?>
<?php echo oj_header( $oj_path ); // phpcs:ignore -- built HTML ?>
<main id="main">
<?php echo oj_main(); // phpcs:ignore -- built HTML ?>
</main>
<?php echo oj_fill( $oj_page['prefooter'] ?? '' ); // phpcs:ignore -- built HTML ?>
<?php echo oj_fill( oj_chrome( 'footer' ) ); // phpcs:ignore -- built HTML ?>
<?php wp_footer(); ?>
</body>
</html>
