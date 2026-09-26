<?php
/**
 * Journal cards. $args['posts'] = WP_Post[]
 */
defined( 'ABSPATH' ) || exit;
$posts = $args['posts'] ?? array();
?>
<div class="cards">
	<?php foreach ( $posts as $i => $p ) : $cat = get_the_category( $p->ID ); ?>
	<article class="card card--<?php echo esc_attr( OY_ACCENTS[ $i % 4 ] ); ?>"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>">
		<div class="card__media"><?php echo oy_post_photo( $p->ID, 'olivia-riverside-profile', '(min-width: 960px) 30vw, 92vw' ); // phpcs:ignore ?></div>
		<div class="card__body">
			<?php if ( $cat ) : ?><span class="card__cat"><?php echo esc_html( $cat[0]->name ); ?></span><?php endif; ?>
			<h3 class="card__title"><?php echo esc_html( get_the_title( $p ) ); ?></h3>
			<p class="card__excerpt"><?php echo esc_html( get_the_excerpt( $p ) ); ?></p>
			<span class="card__more"><?php esc_html_e( 'Read', 'olivia-yoga' ); ?> <?php echo oy_icon( 'arrow' ); // phpcs:ignore ?></span>
		</div>
	</a></article>
	<?php endforeach; ?>
</div>
