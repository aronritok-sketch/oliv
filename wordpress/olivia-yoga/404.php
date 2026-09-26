<?php
defined( 'ABSPATH' ) || exit;
get_header();
echo oy_page_head( __( 'This page has drifted downstream', 'olivia-yoga' ), __( 'The link may be old or mistyped. Take a breath and choose where to go next.', 'olivia-yoga' ), array( array( __( 'Not found', 'olivia-yoga' ), '#' ) ), '404' ); // phpcs:ignore
?>
<div class="container"><div class="layout"><p class="btn-row"><?php echo oy_btn( home_url( '/' ), __( 'Go to the home page', 'olivia-yoga' ) ) . oy_btn( oy_page_link( 'yoga-classes' ), __( 'See the classes', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p></div></div>
<?php
get_footer();
