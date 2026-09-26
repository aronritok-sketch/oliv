<?php
/**
 * A class type: details, booking and upcoming dates for just this class.
 */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$id   = get_the_ID();
	$slug = get_post_field( 'post_name' );
	$meta = '<div class="chips" style="margin-top:1.4rem"><span class="chip">' . oy_icon( 'clock' ) . esc_html( get_post_meta( $id, 'oy_duration', true ) ) . '</span><span class="chip">' . oy_icon( 'users' ) . esc_html( get_post_meta( $id, 'oy_level', true ) ) . '</span><span class="chip">' . oy_intensity( get_post_meta( $id, 'oy_intensity', true ) ) . '</span></div>';
	echo oy_page_head( get_the_title(), get_the_excerpt(), array( array( __( 'Classes', 'olivia-yoga' ), oy_page_link( 'yoga-classes' ) ), array( get_the_title(), get_permalink() ) ), 'c' . $slug, $meta, oy_post_photo( $id, '', '(min-width: 960px) 36vw, 90vw', true ) ); // phpcs:ignore
	$aside_photo = (int) get_post_meta( $id, 'oy_aside_photo', true );
	?>
	<div class="container">
		<div class="layout layout--aside">
			<div class="main-col entry">
				<?php the_content(); ?>
				<?php if ( shortcode_exists( 'oys_schedule' ) ) : ?>
					<h2><?php esc_html_e( 'Next dates', 'olivia-yoga' ); ?></h2>
					<div class="block-wide"><?php echo do_shortcode( '[oys_schedule days="21" class="' . esc_attr( $slug ) . '" empty_days="no"]' ); // phpcs:ignore ?></div>
				<?php endif; ?>
			</div>
			<aside class="aside-sticky"><div class="aside-card">
				<?php if ( $aside_photo ) : ?><div class="frame"><?php echo oy_photo( $aside_photo, '340px' ); // phpcs:ignore ?></div><?php endif; ?>
				<h2><?php the_title(); ?></h2>
				<ul class="aside-list"><li><?php echo oy_icon( 'clock' ) . esc_html( get_post_meta( $id, 'oy_duration', true ) ); // phpcs:ignore ?></li><li><?php echo oy_icon( 'users' ) . esc_html( get_post_meta( $id, 'oy_level', true ) ); // phpcs:ignore ?></li></ul>
				<?php echo oy_btn( oy_book_url(), __( 'Book this class', 'olivia-yoga' ) ); // phpcs:ignore ?>
			</div></aside>
		</div>
	</div>
	<section class="section section--pale"><div class="container">
		<?php echo oy_section_head( __( 'Other classes', 'olivia-yoga' ) ) . oy_classes_list( $slug ); // phpcs:ignore ?>
	</div></section>
	<?php
endwhile;
get_footer();
