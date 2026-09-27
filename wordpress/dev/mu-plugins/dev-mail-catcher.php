<?php
/**
 * Local development only: instead of sending, write every email to wp-content/mail-log/.
 * Do not install on a live site.
 */
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$dir = WP_CONTENT_DIR . '/mail-log';
	wp_mkdir_p( $dir );
	// Names sort in the order the emails were sent (to the microsecond), so tests can take "the emails since".
	$now  = microtime( true );
	$name = gmdate( 'Ymd-His', (int) $now ) . sprintf( '-%06d-', (int) ( ( $now - floor( $now ) ) * 1000000 ) ) . substr( md5( wp_json_encode( $atts ) . $now ), 0, 6 );
	$to   = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'];
	file_put_contents( "$dir/$name.html", "<!-- to: $to -->\n<!-- subject: {$atts['subject']} -->\n" . $atts['message'] );
	foreach ( (array) $atts['attachments'] as $file ) {
		if ( is_file( $file ) ) {
			copy( $file, "$dir/$name-" . basename( $file ) );
		}
	}
	return true;
}, 10, 2 );
