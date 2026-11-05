<?php
/**
 * Home page of the online edition (Olivia Jóga – Online): live online classes in Hungarian, from home.
 * The "Meet your teacher" copy comes from the Home page's content in the editor.
 */

defined( 'ABSPATH' ) || exit;

$studio = fn( $sc ) => shortcode_exists( $sc ) ? do_shortcode( '[' . $sc . ']' ) : '';
$ways   = array(
	array( __( 'Live group classes', 'olivia-yoga' ), __( 'Hatha flow and slow flow every week, live on Zoom, in Hungarian time.', 'olivia-yoga' ), oy_page_link( 'schedule-pricing' ), 'group', 'lilac' ),
	array( __( 'Private online sessions', 'olivia-yoga' ), __( 'One-on-one on video, planned around your body, your goals and your week.', 'olivia-yoga' ), oy_page_link( 'private-yoga' ), 'home', 'pink' ),
	array( __( 'Prenatal fitness online', 'olivia-yoga' ), __( 'Safe, joyful movement for moms-to-be, privately or in a small group.', 'olivia-yoga' ), oy_class_url( 'prenatal-fitness' ), 'prenatal', 'sage' ),
	array( __( 'Journal', 'olivia-yoga' ), __( 'Breathing, the eight limbs of yoga and practice notes to read between classes.', 'olivia-yoga' ), oy_page_link( 'journal' ), 'lotus', 'sun' ),
);
$needs  = array(
	array( 'home', __( 'A quiet corner', 'olivia-yoga' ), __( 'Room for a mat, and a door you can close for an hour.', 'olivia-yoga' ), 'lilac' ),
	array( 'lotus', __( 'A mat, a cushion and a blanket', 'olivia-yoga' ), __( 'The cushion and blanket are for the long relaxation at the end.', 'olivia-yoga' ), 'pink' ),
	array( 'online', __( 'A phone, tablet or laptop', 'olivia-yoga' ), __( 'Any device with a camera that runs Zoom. A bigger screen is easier to follow.', 'olivia-yoga' ), 'sage' ),
	array( 'group', __( 'Camera on, if you like', 'olivia-yoga' ), __( 'If I can see you, I can help you with the poses. Only I see your picture.', 'olivia-yoga' ), 'sun' ),
);
$steps  = array(
	array( __( 'Choose a class', 'olivia-yoga' ), __( 'Book from the timetable with a drop-in, a pass or a membership.', 'olivia-yoga' ) ),
	array( __( 'Get the link', 'olivia-yoga' ), __( 'The Zoom link comes by email and is waiting in your account.', 'olivia-yoga' ) ),
	array( __( 'Roll out your mat', 'olivia-yoga' ), __( 'Join a few minutes early so we can say hello before we start.', 'olivia-yoga' ) ),
	array( __( 'Practice together, live', 'olivia-yoga' ), __( 'I guide you in real time and adjust the class to the people in it.', 'olivia-yoga' ) ),
);
?>
<section class="hero" aria-labelledby="hero-title">
	<div class="container hero__grid">
		<div class="hero__text">
			<?php echo oy_kicker( __( 'Live online yoga · in Hungarian', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<h1 class="hero__title" id="hero-title"><span class="hero__l1"><?php echo esc_html_x( 'Online', 'online hero title, line 1', 'olivia-yoga' ); ?></span> <span class="hero__l2"><mark><?php echo esc_html_x( 'yoga', 'online hero title, line 2', 'olivia-yoga' ); ?></mark></span> <span class="hero__l3"><?php echo esc_html_x( 'live, from home', 'online hero title, line 3', 'olivia-yoga' ); ?></span></h1>
			<p class="hero__lead"><?php esc_html_e( 'Live online yoga classes in Hungarian with Olivia Kovacs: dynamic yet gentle hatha flow, slow flow and prenatal fitness from your living room, on Zoom. Beginners very welcome.', 'olivia-yoga' ); ?></p>
			<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid', 'btn--lg' ) . oy_btn( oy_page_link( 'schedule-pricing' ), __( 'Schedule & pricing', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
		</div>
		<div class="hero__visual">
			<div class="hero__block" aria-hidden="true"></div>
			<div class="frame hero__photo"><?php echo oy_photo( 'online-yoga-living-room', '(min-width: 960px) 52vw, 92vw', true ); // phpcs:ignore ?></div>
			<?php echo oy_name_card( 'hero__card' ); // phpcs:ignore ?>
		</div>
	</div>
</section>

<section class="section section--tight" aria-label="<?php esc_attr_e( 'Ways to practice', 'olivia-yoga' ); ?>"><div class="container"><div class="ways" data-reveal>
	<?php foreach ( $ways as $w ) : ?>
		<a class="way way--<?php echo esc_attr( $w[4] ); ?>" href="<?php echo esc_url( $w[2] ); ?>"><?php echo oy_icon( $w[3], 'way__icon' ); // phpcs:ignore ?><h3><?php echo esc_html( $w[0] ); ?></h3><p><?php echo esc_html( $w[1] ); ?></p><span class="way__go" aria-hidden="true"><?php echo oy_icon( 'arrow' ); // phpcs:ignore ?></span></a>
	<?php endforeach; ?>
</div></div></section>

<section class="section" id="intro" aria-labelledby="intro-title"><div class="container">
	<div class="intro__grid">
		<div class="intro__visual" data-reveal><div class="frame"><?php echo oy_photo( 'olivia-golden-hour-arms', '(min-width: 960px) 36vw, 92vw' ); // phpcs:ignore ?></div></div>
		<div class="prose" data-reveal>
			<?php echo oy_kicker( __( 'Meet your teacher', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<?php
			while ( have_posts() ) {
				the_post();
				echo preg_replace( '/<h2>/', '<h2 id="intro-title">', apply_filters( 'the_content', get_the_content() ), 1 ); // phpcs:ignore
			}
			?>
			<p class="btn-row"><?php echo oy_btn( oy_page_link( 'about' ), __( 'Meet Olivia', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?><a class="text-link" href="<?php echo esc_url( oy_page_link( 'yoga-classes' ) ); ?>"><?php esc_html_e( 'See all classes', 'olivia-yoga' ); ?></a></p>
		</div>
	</div>
	<figure class="contrast" data-reveal>
		<div><p class="contrast__word"><?php esc_html_e( 'Awareness', 'olivia-yoga' ); ?></p><p><?php esc_html_e( 'I notice. I see. I observe. I allow. And then I respond.', 'olivia-yoga' ); ?></p></div>
		<div class="contrast__col--muted"><p class="contrast__word"><?php esc_html_e( 'Control', 'olivia-yoga' ); ?></p><p><?php esc_html_e( 'I want to steer it. I grip it, regulate it, push it down.', 'olivia-yoga' ); ?></p></div>
		<figcaption><?php esc_html_e( 'The most important difference yoga has taught me so far.', 'olivia-yoga' ); ?></figcaption>
	</figure>
</div></section>

<section class="section section--tight" id="classes" aria-labelledby="classes-title"><div class="container">
	<?php echo oy_section_head( __( 'Online classes', 'olivia-yoga' ), __( 'Breathwork, strength, stretching, balance, a real flow state and a long relaxation in every class, live on Zoom. Choose the pace that suits you today.', 'olivia-yoga' ), __( 'Classes', 'olivia-yoga' ), 'classes-title' ); // phpcs:ignore ?>
	<?php echo oy_classes_list(); // phpcs:ignore ?>
</div></section>

<section class="section section--pale" id="how" aria-labelledby="how-title"><div class="container private__grid">
	<div data-reveal>
		<?php echo oy_kicker( __( 'How online classes work', 'olivia-yoga' ) ); // phpcs:ignore ?>
		<h2 id="how-title"><?php esc_html_e( 'A real class, just in your living room', 'olivia-yoga' ); ?></h2>
		<p class="lead"><?php esc_html_e( 'This is not a video to follow. We practice together, at the same time: I watch, guide and adjust, and you can ask anything before and after class.', 'olivia-yoga' ); ?></p>
		<ul class="places"><?php foreach ( $needs as $n ) : ?><li><?php echo oy_pictogram( $n[0], $n[3] ); // phpcs:ignore ?><div><h3><?php echo esc_html( $n[1] ); ?></h3><p><?php echo esc_html( $n[2] ); ?></p></div></li><?php endforeach; ?></ul>
	</div>
	<div class="private__side" data-reveal>
		<div class="frame"><?php echo oy_photo( 'olivia-dancer-home', '(min-width: 960px) 34vw, 92vw' ); // phpcs:ignore ?></div>
		<ol class="steps"><?php foreach ( $steps as $s ) : ?><li><div><b><?php echo esc_html( $s[0] ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></div></li><?php endforeach; ?></ol>
		<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ) . oy_btn( oy_page_link( 'faq' ), __( 'Questions about online classes', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
	</div>
</div></section>

<section class="section section--tight" id="schedule" aria-labelledby="schedule-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'This week', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="schedule-title"><?php esc_html_e( 'Weekly live classes', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'Times are in Hungarian time. Book in a minute; beginners are welcome in every class.', 'olivia-yoga' ); ?></p></div><?php echo oy_btn( oy_page_link( 'schedule-pricing' ), __( 'Full schedule & pricing', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php echo shortcode_exists( 'oys_schedule' ) ? do_shortcode( '[oys_schedule days="7"]' ) : ''; // phpcs:ignore ?>
</div></section>

<section class="section section--moss hatha" aria-labelledby="hatha-title"><div class="container hatha__grid">
	<div data-reveal><div class="hatha__pair"><div class="hatha__orb hatha__orb--sun"><span>Ha</span></div><div class="hatha__orb hatha__orb--moon"><span>Tha</span></div></div><div class="hatha__caption"><span><?php esc_html_e( 'sun: effort, heat, strength', 'olivia-yoga' ); ?></span><span><?php esc_html_e( 'moon: ease, cool, surrender', 'olivia-yoga' ); ?></span></div></div>
	<div data-reveal>
		<?php echo oy_kicker( __( 'What hatha means', 'olivia-yoga' ) ); // phpcs:ignore ?>
		<h2 id="hatha-title"><?php esc_html_e( 'Ha is the sun. Tha is the moon.', 'olivia-yoga' ); ?></h2>
		<p class="lead"><?php esc_html_e( 'Hatha yoga balances the active and the receptive in us: effort and ease, strength and surrender. When that balance tips, we feel it first as tension or restlessness.', 'olivia-yoga' ); ?></p>
		<p><?php esc_html_e( 'On the mat we use the body to find the balance again. The poses are a doorway to meditation, a more dynamic doorway, and only one of the eight limbs of yoga.', 'olivia-yoga' ); ?></p>
		<p class="btn-row"><?php echo oy_btn( oy_page_link( 'faq' ), __( 'Read more about hatha yoga', 'olivia-yoga' ), 'line-light' ); // phpcs:ignore ?></p>
	</div>
</div></section>

<section class="section section--forest section--breath" aria-label="<?php esc_attr_e( 'Breathing exercise', 'olivia-yoga' ); ?>"><div class="container"><?php echo oy_breath(); // phpcs:ignore ?></div></section>

<section class="section section--tight" id="pricing" aria-labelledby="pricing-title"><div class="container">
	<?php echo oy_section_head( __( 'Simple pricing', 'olivia-yoga' ), __( 'Pay per class, save with an online pass or membership, or give someone a gift card.', 'olivia-yoga' ), __( 'Prices in forints', 'olivia-yoga' ), 'pricing-title' ); // phpcs:ignore ?>
	<?php echo $studio( 'oys_pricing' ); // phpcs:ignore ?>
</div></section>

<section class="section" id="faq" aria-labelledby="faq-title"><div class="container faq-grid">
	<header class="section__head" data-reveal><?php echo oy_kicker( __( 'FAQ', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="faq-title"><?php esc_html_e( 'Can yoga really work online?', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'The question everyone asks first, and a few others.', 'olivia-yoga' ); ?></p><p><a class="text-link" href="<?php echo esc_url( oy_page_link( 'faq' ) ); ?>"><?php esc_html_e( 'All questions', 'olivia-yoga' ); ?></a></p></header>
	<?php echo oy_faq_list( true ); // phpcs:ignore ?>
</div></section>

<?php $journal = get_posts( array( 'numberposts' => 3 ) ); ?>
<?php if ( $journal ) : ?>
<section class="section section--pale" aria-labelledby="journal-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'Journal', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="journal-title"><?php esc_html_e( 'From the journal', 'olivia-yoga' ); ?></h2></div><?php echo oy_btn( oy_page_link( 'journal' ), __( 'All articles', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $journal ) ); ?>
</div></section>
<?php endif; ?>

<section class="section cta" aria-labelledby="cta-title"><div class="container"><div class="cta__inner" data-reveal>
	<h2 id="cta-title"><?php esc_html_e( 'Your mat is waiting', 'olivia-yoga' ); ?></h2>
	<p class="lead"><?php esc_html_e( 'Join a live online class, request a private session, or just send a question. Beginners very welcome.', 'olivia-yoga' ); ?></p>
	<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid', 'btn--lg' ) . oy_btn( oy_contact_url(), __( 'Send a message', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
</div></div></section>
