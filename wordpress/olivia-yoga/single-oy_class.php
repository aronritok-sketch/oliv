<?php
/**
 * A class type: details, booking and upcoming dates for just this class.
 * The page head uses the same photo as the class list, so the two always match.
 */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$id   = get_the_ID();
	$slug = get_post_field( 'post_name' );
	echo oy_page_head( get_the_title(), get_the_excerpt(), array( array( __( 'Classes', 'olivia-yoga' ), oy_page_link( 'yoga-classes' ) ), array( get_the_title(), get_permalink() ) ), 'c' . $slug, oy_class_facts( $id, 'chips page-head__chips' ), oy_post_photo( $id, '', '(min-width: 960px) 36vw, 90vw', true ) ); // phpcs:ignore
	$aside_photo = (int) get_post_meta( $id, 'oy_aside_photo', true );
	$private     = in_array( $slug, array( 'prenatal-fitness', 'singing-bowl-massage' ), true );
	?>
	<div class="container">
		<div class="layout layout--aside">
			<div class="main-col entry">
				<?php the_content(); ?>
				<?php if ( shortcode_exists( 'oys_schedule' ) && ! $private ) : ?>
					<h2><?php esc_html_e( 'Next dates', 'olivia-yoga' ); ?></h2>
					<div class="block-wide"><?php echo do_shortcode( '[oys_schedule days="21" class="' . esc_attr( $slug ) . '" empty_days="no"]' ); // phpcs:ignore ?></div>
				<?php endif; ?>
			</div>
			<aside class="aside-sticky"><div class="aside-card aside-card--class">
				<?php if ( $aside_photo ) : ?><div class="frame"><?php echo oy_photo( $aside_photo, '340px' ); // phpcs:ignore ?></div><?php endif; ?>
				<h2><?php the_title(); ?></h2>
				<?php echo oy_class_facts( $id, 'aside-facts' ); // phpcs:ignore ?>
				<?php if ( 'prenatal-fitness' === $slug ) : ?><?php echo oy_fitmummy( 'credential--aside' ); // phpcs:ignore ?><?php endif; ?>
				<?php echo $private ? oy_btn( oy_contact_url( 'prenatal-fitness' === $slug ? 'prenatal' : 'massage' ), __( 'Ask for a session', 'olivia-yoga' ), 'orchid' ) : oy_btn( oy_book_url(), __( 'Book this class', 'olivia-yoga' ), 'orchid' ); // phpcs:ignore ?>
			</div></aside>
		</div>
	</div>
	<section class="section section--pale"><div class="container">
		<?php echo oy_section_head( __( 'Other classes', 'olivia-yoga' ) ) . oy_classes_list( $slug ); // phpcs:ignore ?>
	</div></section>
	<?php
endwhile;
get_footer();
