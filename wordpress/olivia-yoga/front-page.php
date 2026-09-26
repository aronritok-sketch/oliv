<?php
/**
 * Home page. The "Meet your teacher" copy comes from the Home page's content in the editor.
 */

defined( 'ABSPATH' ) || exit;
get_header();

$studio = fn( $sc ) => shortcode_exists( $sc ) ? do_shortcode( '[' . $sc . ']' ) : '';
$ways   = array(
	array( __( 'Group classes', 'olivia-yoga' ), __( 'Hatha flow and slow flow, every week in Fort Myers.', 'olivia-yoga' ), oy_page_link( 'schedule-pricing' ), 'users', 'lilac' ),
	array( __( 'Private yoga', 'olivia-yoga' ), __( 'One-on-one at your home, on your lanai or on the beach.', 'olivia-yoga' ), oy_page_link( 'private-yoga' ), 'home', 'pink' ),
	array( __( 'Teams & athletes', 'olivia-yoga' ), __( 'Office yoga, team building and mobility for sport.', 'olivia-yoga' ), oy_page_link( 'corporate-yoga' ), 'building', 'sun' ),
);
$places = array(
	array( 'home', __( 'Your home or lanai', 'olivia-yoga' ), __( 'No driving, no parking, no crowd. Just room for a mat and a little quiet.', 'olivia-yoga' ), 'lilac' ),
	array( 'wave', __( 'On the beach', 'olivia-yoga' ), __( 'Sunrise or sunset on the sand at Fort Myers Beach, Sanibel or wherever your towel is.', 'olivia-yoga' ), 'sun' ),
	array( 'building', __( 'Condo & HOA clubhouses', 'olivia-yoga' ), __( 'A regular class for your community, paced for your residents.', 'olivia-yoga' ), 'pink' ),
	array( 'screen', __( 'Online', 'olivia-yoga' ), __( 'Live, one-on-one, from wherever you are — with the same attention to detail.', 'olivia-yoga' ), 'sage' ),
);
$steps = array(
	array( __( 'Send a request', 'olivia-yoga' ), __( 'Tell me where you are and which days suit you. It takes two minutes.', 'olivia-yoga' ) ),
	array( __( 'Get a time and price', 'olivia-yoga' ), __( 'I reply within a day with a suggested session, ready to confirm.', 'olivia-yoga' ) ),
	array( __( 'Confirm online', 'olivia-yoga' ), __( 'Pay by card or use a private-session credit. You get a calendar invite.', 'olivia-yoga' ) ),
	array( __( 'Keep going', 'olivia-yoga' ), __( 'Single sessions or a 5-session pack, at your rhythm.', 'olivia-yoga' ) ),
);
?>
<section class="hero" aria-labelledby="hero-title">
	<div class="container hero__grid">
		<div class="hero__text">
			<?php echo oy_kicker( 'Hatha flow · Slow flow · Private yoga' ); // phpcs:ignore ?>
			<h1 class="hero__title" id="hero-title"><span class="hero__l1">Flow</span> <span class="hero__l2"><mark>yoga</mark></span> <span class="hero__l3">in Fort Myers</span></h1>
			<p class="hero__lead"><?php esc_html_e( 'Dynamic yet gentle hatha flow classes with Olivia Kovács — group classes, private yoga at your home, lanai or on the beach, and yoga for teams across Southwest Florida.', 'olivia-yoga' ); ?></p>
			<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ) ) . oy_btn( oy_private_url(), __( 'Private yoga at your place', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
		</div>
		<div class="hero__visual">
			<div class="hero__block" aria-hidden="true"></div>
			<div class="frame hero__photo"><?php echo oy_photo( 'olivia-golden-hour-prayer', '(min-width: 960px) 44vw, 92vw', true ); // phpcs:ignore ?></div>
			<p class="hero__tag"><b>Olivia Kovács</b> <?php esc_html_e( 'Certified hatha yoga teacher · Budapest → Fort Myers', 'olivia-yoga' ); ?></p>
			<?php echo oy_sticker( 'Beginners welcome ✺ in every class ✺ ', 'sticker--hero' ); // phpcs:ignore ?>
		</div>
	</div>
</section>
<?php echo oy_marquee( array( 'Hatha flow', 'Slow flow', 'Private yoga', 'Sound yoga', 'Beach sunrise', 'Yoga for teams', 'Fort Myers, FL' ) ); // phpcs:ignore ?>

<section class="section section--tight" aria-label="<?php esc_attr_e( 'Ways to practice', 'olivia-yoga' ); ?>"><div class="container"><div class="ways" data-reveal>
	<?php foreach ( $ways as $w ) : ?>
		<a class="way way--<?php echo esc_attr( $w[4] ); ?>" href="<?php echo esc_url( $w[2] ); ?>"><?php echo oy_icon( $w[3], 'icon way__icon' ); // phpcs:ignore ?><h3><?php echo esc_html( $w[0] ); ?></h3><p><?php echo esc_html( $w[1] ); ?></p><span class="way__go" aria-hidden="true"><?php echo oy_icon( 'arrow' ); // phpcs:ignore ?></span></a>
	<?php endforeach; ?>
</div></div></section>

<section class="section" id="intro" aria-labelledby="intro-title"><div class="container">
	<div class="intro__grid">
		<div class="intro__visual" data-reveal><div class="frame"><?php echo oy_photo( 'olivia-riverside-portrait', '(min-width: 960px) 36vw, 92vw' ); // phpcs:ignore ?></div><p class="intro__caption"><?php esc_html_e( 'Certified hatha yoga teacher since 2023', 'olivia-yoga' ); ?></p></div>
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
		<div><p class="contrast__word">Awareness</p><p>I notice. I see. I observe. I allow — and then I respond.</p></div>
		<div class="contrast__col--muted"><p class="contrast__word">Control</p><p>I want to steer it. I grip it, regulate it, push it down.</p></div>
		<figcaption>The most important difference yoga has taught me so far. — Olivia</figcaption>
	</figure>
</div></section>

<section class="section section--tight" id="classes" aria-labelledby="classes-title"><div class="container">
	<?php echo oy_section_head( __( 'Yoga classes in Fort Myers', 'olivia-yoga' ), __( 'Breathwork, strength, stretching, balance, a real flow state and a long relaxation — in every class. Choose the pace that suits you today.', 'olivia-yoga' ), __( 'Seven ways in', 'olivia-yoga' ), 'classes-title' ); // phpcs:ignore ?>
	<?php echo oy_classes_list(); // phpcs:ignore ?>
</div></section>

<section class="section section--moss hatha" aria-labelledby="hatha-title"><div class="container hatha__grid">
	<div data-reveal><div class="hatha__pair"><div class="hatha__orb hatha__orb--sun"><span>Ha</span></div><div class="hatha__orb hatha__orb--moon"><span>Tha</span></div></div><div class="hatha__caption"><span>sun: effort, heat, strength</span><span>moon: ease, cool, surrender</span></div></div>
	<div data-reveal>
		<?php echo oy_kicker( 'What “hatha” means' ); // phpcs:ignore ?>
		<h2 id="hatha-title">Ha is the sun. Tha is the moon.</h2>
		<p class="lead">Hatha yoga balances the active and the receptive in us: effort and ease, strength and surrender. When that balance tips, we feel it first as tension or restlessness.</p>
		<p>On the mat we use the body to find the balance again. The poses are a doorway to meditation — a more dynamic doorway — and only one of the eight limbs of yoga.</p>
		<p class="btn-row"><?php echo oy_btn( oy_page_link( 'faq' ), __( 'Read more about hatha yoga', 'olivia-yoga' ), 'line-light' ); // phpcs:ignore ?></p>
	</div>
</div></section>

<section class="section section--pale" id="private" aria-labelledby="private-title"><div class="container private__grid">
	<div data-reveal>
		<?php echo oy_kicker( __( 'Private yoga', 'olivia-yoga' ) ); // phpcs:ignore ?>
		<h2 id="private-title"><?php esc_html_e( 'Your own class, wherever you breathe best', 'olivia-yoga' ); ?></h2>
		<p class="lead"><?php esc_html_e( 'Private yoga in Fort Myers is built around one body: yours. We talk about how you move, where you hold tension and what you want to feel afterwards — then I plan the class for exactly that.', 'olivia-yoga' ); ?></p>
		<ul class="places"><?php foreach ( $places as $p ) : ?><li><?php echo oy_badge( $p[0], $p[3] ); // phpcs:ignore ?><div><h3><?php echo esc_html( $p[1] ); ?></h3><p><?php echo esc_html( $p[2] ); ?></p></div></li><?php endforeach; ?></ul>
	</div>
	<div class="private__side" data-reveal>
		<div class="frame"><?php echo oy_photo( 'olivia-arms-raised-sunset', '(min-width: 960px) 34vw, 92vw' ); // phpcs:ignore ?></div>
		<ol class="steps"><?php foreach ( $steps as $s ) : ?><li><div><b><?php echo esc_html( $s[0] ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></div></li><?php endforeach; ?></ol>
		<p class="btn-row"><?php echo oy_btn( oy_private_url(), __( 'Request a private session', 'olivia-yoga' ) ) . oy_btn( oy_page_link( 'private-yoga' ), __( 'How it works', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
	</div>
</div></section>

<section class="section section--tight" id="schedule" aria-labelledby="schedule-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'This week', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="schedule-title"><?php esc_html_e( 'Weekly group classes', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'Book in a minute. Beginners are welcome in every class.', 'olivia-yoga' ); ?></p></div><?php echo oy_btn( oy_page_link( 'schedule-pricing' ), __( 'Full schedule & pricing', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php echo shortcode_exists( 'oys_schedule' ) ? do_shortcode( '[oys_schedule days="7"]' ) : ''; // phpcs:ignore ?>
</div></section>

<section class="section section--forest" aria-label="<?php esc_attr_e( 'Breathing exercise', 'olivia-yoga' ); ?>"><div class="container"><?php echo oy_breath(); // phpcs:ignore ?></div></section>

<section class="section section--tight" id="pricing" aria-labelledby="pricing-title"><div class="container">
	<?php echo oy_section_head( __( 'Simple pricing', 'olivia-yoga' ), __( 'Pay per class, save with a pass, or give someone a gift card.', 'olivia-yoga' ), __( 'Prices in USD', 'olivia-yoga' ), 'pricing-title' ); // phpcs:ignore ?>
	<?php echo $studio( 'oys_pricing' ); // phpcs:ignore ?>
</div></section>

<section class="section" id="faq" aria-labelledby="faq-title"><div class="container faq-grid">
	<header class="section__head" data-reveal><?php echo oy_kicker( 'FAQ' ); // phpcs:ignore ?><h2 id="faq-title"><?php esc_html_e( 'Am I flexible enough for yoga?', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'The question everyone asks first — and a few others.', 'olivia-yoga' ); ?></p><p><a class="text-link" href="<?php echo esc_url( oy_page_link( 'faq' ) ); ?>"><?php esc_html_e( 'All questions', 'olivia-yoga' ); ?></a></p></header>
	<?php echo oy_faq_list( true ); // phpcs:ignore ?>
</div></section>

<section class="section section--moss" aria-labelledby="events-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'Events', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="events-title"><?php esc_html_e( 'Sound yoga, live music & beach flows', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'Special classes where handpan, tongue drum or singing bowls lead the movement.', 'olivia-yoga' ); ?></p></div><?php echo oy_btn( oy_page_link( 'events' ), __( 'All events', 'olivia-yoga' ), 'line-light' ); // phpcs:ignore ?></header>
	<?php echo $studio( 'oys_events' ); // phpcs:ignore ?>
</div></section>

<section class="section section--tight" aria-labelledby="gallery-title"><div class="container">
	<?php echo oy_section_head( __( 'Moments on the mat', 'olivia-yoga' ), __( 'Studio flows, garden classes, festival pavilions and the sea. Every class looks a little different; the feeling afterwards is the same.', 'olivia-yoga' ), '@oliivia_yoga', 'gallery-title' ); // phpcs:ignore ?>
	<?php echo oy_gallery( array( 'dancer-pose-beach', 'hatha-flow-group-warrior', 'sound-yoga-gong-bowls', 'olivia-outdoor-class', 'festival-pavilion-class', 'garden-yoga-evening', 'dancer-pose-forest', 'final-relaxation' ) ); // phpcs:ignore ?>
</div></section>

<section class="section section--tight" aria-labelledby="areas-title"><div class="container areas-grid">
	<header class="section__head" data-reveal><?php echo oy_kicker( __( 'Southwest Florida', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="areas-title"><?php esc_html_e( 'Where I teach around Fort Myers', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'Group classes in Fort Myers; private sessions at homes, clubhouses, offices and beaches across Southwest Florida.', 'olivia-yoga' ); ?></p></header>
	<?php echo oy_areas_list(); // phpcs:ignore ?>
</div></section>

<?php $journal = get_posts( array( 'numberposts' => 3 ) ); ?>
<?php if ( $journal ) : ?>
<section class="section section--pale" aria-labelledby="journal-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'Journal', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="journal-title"><?php esc_html_e( 'From the journal', 'olivia-yoga' ); ?></h2></div><?php echo oy_btn( oy_page_link( 'journal' ), __( 'All articles', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $journal ) ); ?>
</div></section>
<?php endif; ?>

<section class="section cta" aria-labelledby="cta-title"><div class="container"><div class="cta__inner" data-reveal>
	<?php echo oy_sticker( 'Beginners very welcome ✺ ', 'sticker--cta' ); // phpcs:ignore ?>
	<h2 id="cta-title"><?php esc_html_e( 'Your mat is waiting', 'olivia-yoga' ); ?></h2>
	<p class="lead"><?php esc_html_e( 'Try a group class, request a private session, or just send a question. Beginners very welcome.', 'olivia-yoga' ); ?></p>
	<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ) ) . oy_btn( oy_contact_url(), __( 'Send a message', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
</div></div></section>
<?php
get_footer();
