<?php
/**
 * Journal cards. $args['posts'] = WP_Post[]
 */
defined( 'ABSPATH' ) || exit;
// not $posts: in a template that is the main query's list (by reference), and overwriting it emptied „Összes írás”
$oy_cards = $args['posts'] ?? array();
?>
<div class="cards">
	<?php
	foreach ( $oy_cards as $i => $p ) :
		$cat  = get_the_category( $p->ID );
		$tone = OY_ACCENTS[ $i % 4 ];
		// the online site, Olívia, 15 November: „az azonos témák legyenek azonos színűek. A főoldalon is ugyanúgy” – the colour
		// follows the topic (category), so every „A jóga 8 ága” card wears the same one, in the palette's three colours
		if ( oy_online_only() && $cat ) {
			$tone = array( 'lilac', 'pink', 'sun' )[ absint( crc32( $cat[0]->slug ) ) % 3 ];
		}
		?>
	<article class="card card--<?php echo esc_attr( $tone ); ?><?php echo get_post_meta( $p->ID, 'oy_art', true ) ? ' card--art' : ''; ?>"><a href="<?php echo esc_url( get_permalink( $p ) ); ?>">
		<div class="card__media"><?php echo oy_post_photo( $p->ID, 'olivia-terrace-profile', '(min-width: 960px) 30vw, 92vw' ); // phpcs:ignore ?></div>
		<div class="card__body">
			<?php if ( $cat ) : ?><span class="card__cat"><?php echo esc_html( $cat[0]->name ); ?></span><?php endif; ?>
			<h3 class="card__title"><?php echo esc_html( get_the_title( $p ) ); ?></h3>
			<p class="card__excerpt"><?php echo esc_html( get_the_excerpt( $p ) ); ?></p>
			<span class="card__more"><?php esc_html_e( 'Read', 'olivia-yoga' ); ?> <?php echo oy_icon( 'arrow' ); // phpcs:ignore ?></span>
		</div>
	</a></article>
	<?php endforeach; ?>
</div>
