<?php
/**
 * Journal (posts page).
 */
defined( 'ABSPATH' ) || exit;
get_header();
$page_id = (int) get_option( 'page_for_posts' );
$title   = $page_id ? get_the_title( $page_id ) : __( 'Journal', 'olivia-yoga' );
$lead    = $page_id ? get_post_field( 'post_excerpt', $page_id ) : '';
echo oy_page_head( $title, $lead, array( array( $title, $page_id ? get_permalink( $page_id ) : home_url( '/' ) ) ), 'journal', '', $page_id ? oy_post_photo( $page_id, 'olivia-riverside-profile', '(min-width: 960px) 36vw, 90vw', true ) : '' ); // phpcs:ignore
global $wp_query;
?>
<div class="container"><div class="layout">
	<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $wp_query->posts ) ); ?>
	<?php the_posts_pagination( array( 'mid_size' => 1 ) ); ?>
</div></div>
<?php
get_footer();
