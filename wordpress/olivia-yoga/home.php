<?php
/**
 * Journal (posts page).
 */
defined( 'ABSPATH' ) || exit;
get_header();
$page_id = (int) get_option( 'page_for_posts' );
$title   = $page_id ? get_the_title( $page_id ) : __( 'Journal', 'olivia-yoga' );
$lead    = $page_id ? ( oy_tr( $page_id, 'excerpt' ) ?: get_post_field( 'post_excerpt', $page_id ) ) : '';
echo oy_page_head( $title, $lead, array( array( $title, $page_id ? get_permalink( $page_id ) : home_url( '/' ) ) ), 'journal', '', $page_id ? oy_post_photo( $page_id, 'olivia-terrace-profile', '(min-width: 960px) 36vw, 90vw', true ) : '' ); // phpcs:ignore
global $wp_query;
?>
<?php $limbs = is_paged() ? array() : oy_limbs_posts(); // the eight limbs of yoga together, first (Olivia, 13 November) ?>
<?php $all = $wp_query->posts; // the list below, taken before anything else renders ?>
<div class="container"><div class="layout">
	<?php if ( $limbs ) : ?>
	<section class="oj-limbs" id="<?php echo esc_attr( OY_LIMBS_CATEGORY ); ?>" aria-labelledby="limbs-title">
		<header class="section__head"><h2 id="limbs-title"><?php echo esc_html( get_term_by( 'slug', OY_LIMBS_CATEGORY, 'category' )->name ); ?></h2><?php if ( ! oy_online_only() ) : ?><p class="lead"><?php esc_html_e( 'All eight limbs, one article each, in order.', 'olivia-yoga' ); ?></p><?php endif; // Olívia, 14 November: „ez nem kell” ?></header>
		<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $limbs ) ); ?>
	</section>
	<h2 class="oj-all-title"><?php esc_html_e( 'All articles', 'olivia-yoga' ); ?></h2>
	<?php endif; ?>
	<?php
	if ( oy_online_only() && $limbs ) { // the other articles first (newest first), then the eight limbs in their order
		$ids = wp_list_pluck( $limbs, 'ID' );
		$all = array_merge( array_values( array_filter( $all, fn( $p ) => ! in_array( $p->ID, $ids, true ) ) ), $limbs );
	}
	get_template_part( 'template-parts/cards', null, array( 'posts' => $all ) );
	?>
	<?php the_posts_pagination( array( 'mid_size' => 1 ) ); ?>
</div></div>
<?php
get_footer();
