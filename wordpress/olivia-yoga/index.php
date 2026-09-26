<?php
/**
 * Fallback for archives and search.
 */
defined( 'ABSPATH' ) || exit;
get_header();
$title = is_search() ? sprintf( __( 'Search: %s', 'olivia-yoga' ), get_search_query() ) : wp_strip_all_tags( get_the_archive_title() );
echo oy_page_head( $title, '', array( array( $title, '#' ) ), 'archive' ); // phpcs:ignore
global $wp_query;
?>
<div class="container"><div class="layout">
	<?php if ( have_posts() ) : ?>
		<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $wp_query->posts ) ); ?>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing found.', 'olivia-yoga' ); ?></p>
	<?php endif; ?>
</div></div>
<?php
get_footer();
