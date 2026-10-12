<?php
/**
 * Journal article.
 */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$words   = str_word_count( wp_strip_all_tags( get_the_content() ) );
	$cat     = get_the_category();
	$journal = (int) get_option( 'page_for_posts' );
	$meta    = '<div class="page-head__meta"><span>' . oy_icon( 'calendar' ) . esc_html( get_the_date() ) . '</span><span>' . oy_icon( 'clock' ) . esc_html( sprintf( __( '%d min read', 'olivia-yoga' ), max( 1, round( $words / 220 ) ) ) ) . '</span>' . ( $cat ? '<span>' . oy_icon( 'leaf' ) . esc_html( $cat[0]->name ) . '</span>' : '' ) . '</div>';
	echo oy_page_head( get_the_title(), get_the_excerpt(), array( array( __( 'Journal', 'olivia-yoga' ), $journal ? get_permalink( $journal ) : home_url( '/' ) ), array( get_the_title(), get_permalink() ) ), 'p' . get_post_field( 'post_name' ), $meta, oy_post_photo( get_the_ID(), 'olivia-riverside-profile', '(min-width: 960px) 36vw, 90vw', true ) ); // phpcs:ignore
	?>
	<div class="container"><article class="layout">
		<div class="entry"><?php the_content(); ?></div>
		<aside class="author"><div class="frame"><?php echo oy_photo( 'olivia-sunset-smile', '84px', false, 'Olivia Kovacs' ); // phpcs:ignore ?></div><div><b>Olivia Kovacs</b><p><?php echo esc_html( oy_title_line() ); ?></p><?php echo oy_specialities_line( 'specialities specialities--small' ); // phpcs:ignore ?></div></aside>
		<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ) ); // phpcs:ignore ?></p>
	</article></div>
	<?php $more = get_posts( array( 'numberposts' => 3, 'exclude' => array( get_the_ID() ) ) ); ?>
	<?php if ( $more ) : ?>
	<section class="section section--pale"><div class="container"><?php echo oy_section_head( __( 'Keep reading', 'olivia-yoga' ) ); // phpcs:ignore ?><?php get_template_part( 'template-parts/cards', null, array( 'posts' => $more ) ); ?></div></section>
	<?php endif; ?>
	<?php
endwhile;
get_footer();
