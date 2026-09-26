<?php
/**
 * Pages: coloured page head with the featured image, content, and a side column on some pages.
 */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$slug   = get_post_field( 'post_name' );
	$studio = oy_is_studio_page();
	$aside  = $studio ? '' : oy_page_aside( $slug );
	$photo  = $studio && 'gifts' !== $studio ? '' : oy_post_photo( get_the_ID(), '', '(min-width: 960px) 36vw, 90vw', true );
	echo oy_page_head( get_the_title(), has_excerpt() ? get_the_excerpt() : '', array( array( get_the_title(), get_permalink() ) ), $slug, '', $photo ); // phpcs:ignore
	?>
	<div class="container">
		<div class="layout<?php echo $aside ? ' layout--aside' : ''; ?><?php echo $studio ? ' layout--studio' : ''; ?>">
			<div class="main-col entry"><?php the_content(); ?></div>
			<?php if ( $aside ) : ?><aside class="aside-sticky"><?php echo $aside; // phpcs:ignore ?></aside><?php endif; ?>
		</div>
	</div>
	<?php
endwhile;
get_footer();
