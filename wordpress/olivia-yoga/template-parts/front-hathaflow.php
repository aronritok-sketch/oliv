<?php
/**
 * Home page of the Hathaflow demo: a made-up yoga studio that shows the booking system – timetable,
 * booking and payment, passes, memberships, private and online sessions, gift cards. The "About the
 * studio" copy comes from the Home page's content in the editor.
 */

defined( 'ABSPATH' ) || exit;

$studio = fn( $sc ) => shortcode_exists( $sc ) ? do_shortcode( '[' . $sc . ']' ) : '';
$ways   = array(
	array( __( 'Group classes', 'olivia-yoga' ), __( 'Hatha flow and slow flow every week in the studio, with places counted live.', 'olivia-yoga' ), oy_page_link( 'schedule-pricing' ), 'group', 'lilac' ),
	array( __( 'Live online classes', 'olivia-yoga' ), __( 'The same classes on Zoom: the link arrives by email after booking.', 'olivia-yoga' ), oy_page_link( 'schedule-pricing' ), 'online', 'pink' ),
	array( __( 'Private sessions', 'olivia-yoga' ), __( 'Request a time, get an offer with a price, pay with one click.', 'olivia-yoga' ), oy_page_link( 'private-yoga' ), 'home', 'sage' ),
	array( __( 'Gift cards', 'olivia-yoga' ), __( 'A class pass or a private session as a gift, sent by email with a code.', 'olivia-yoga' ), function_exists( 'oys_page_url' ) ? oys_page_url( 'gifts' ) : oy_page_link( 'schedule-pricing' ), 'lotus', 'sun' ),
);
$steps  = array(
	array( __( 'Pick a class', 'olivia-yoga' ), __( 'The timetable shows the free places in real time.', 'olivia-yoga' ) ),
	array( __( 'Pay or use your pass', 'olivia-yoga' ), __( 'Card, Apple Pay or Google Pay; a pass or membership books in one click.', 'olivia-yoga' ) ),
	array( __( 'Get a reminder', 'olivia-yoga' ), __( 'Confirmation with a calendar file, then a reminder before class.', 'olivia-yoga' ) ),
	array( __( 'Change of plans?', 'olivia-yoga' ), __( 'Cancel in your account in time and the class goes back on your pass.', 'olivia-yoga' ) ),
);
?>
<section class="hero" aria-labelledby="hero-title">
	<div class="container hero__grid">
		<div class="hero__text">
			<?php echo oy_kicker( __( 'Yoga studio · demo', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<h1 class="hero__title" id="hero-title"><span class="hero__l1"><?php echo esc_html_x( 'Book', 'demo hero title, line 1', 'olivia-yoga' ); ?></span> <span class="hero__l2"><mark><?php echo esc_html_x( 'your', 'demo hero title, line 2', 'olivia-yoga' ); ?></mark></span> <span class="hero__l3"><?php echo esc_html_x( 'mat', 'demo hero title, line 3', 'olivia-yoga' ); ?></span></h1>
			<p class="hero__lead"><?php esc_html_e( 'Hathaflow is a sample studio that shows the booking system: a live timetable, online booking and payment, class passes, memberships, private and online sessions and gift cards.', 'olivia-yoga' ); ?></p>
			<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid', 'btn--lg' ) . oy_btn( oy_page_link( 'schedule-pricing' ), __( 'Schedule & pricing', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
		</div>
		<div class="hero__visual">
			<div class="hero__block" aria-hidden="true"></div>
			<div class="frame hero__photo"><?php echo oy_photo( 'studio-group-flow', '(min-width: 960px) 52vw, 92vw', true ); // phpcs:ignore ?></div>
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
			<?php echo oy_kicker( __( 'About the studio', 'olivia-yoga' ) ); // phpcs:ignore ?>
			<?php
			while ( have_posts() ) {
				the_post();
				echo preg_replace( '/<h2>/', '<h2 id="intro-title">', apply_filters( 'the_content', get_the_content() ), 1 ); // phpcs:ignore
			}
			?>
			<p class="btn-row"><?php echo oy_btn( oy_page_link( 'about' ), __( 'About the studio', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?><a class="text-link" href="<?php echo esc_url( oy_page_link( 'yoga-classes' ) ); ?>"><?php esc_html_e( 'See all classes', 'olivia-yoga' ); ?></a></p>
		</div>
	</div>
</div></section>

<section class="section section--tight" id="classes" aria-labelledby="classes-title"><div class="container">
	<?php echo oy_section_head( __( 'Classes', 'olivia-yoga' ), __( 'Every class type has its own page, its own price and its own next dates, in the studio and online.', 'olivia-yoga' ), __( 'Classes', 'olivia-yoga' ), 'classes-title' ); // phpcs:ignore ?>
	<?php echo oy_classes_list(); // phpcs:ignore ?>
</div></section>

<section class="section section--tight" id="schedule" aria-labelledby="schedule-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'This week', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="schedule-title"><?php esc_html_e( 'Weekly timetable', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'Free places update as people book. Online classes are marked.', 'olivia-yoga' ); ?></p></div><?php echo oy_btn( oy_page_link( 'schedule-pricing' ), __( 'Full schedule', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php echo shortcode_exists( 'oys_schedule' ) ? do_shortcode( '[oys_schedule days="7"]' ) : ''; // phpcs:ignore ?>
</div></section>

<section class="section section--pale" id="how" aria-labelledby="how-title"><div class="container private__grid">
	<div data-reveal>
		<?php echo oy_kicker( __( 'How booking works', 'olivia-yoga' ) ); // phpcs:ignore ?>
		<h2 id="how-title"><?php esc_html_e( 'Two clicks from the timetable to the mat', 'olivia-yoga' ); ?></h2>
		<p class="lead"><?php esc_html_e( 'Students book and pay on the site, keep their passes and bookings in their account, and get every confirmation and reminder by email. The studio sees the class lists, the payments and the income in one place.', 'olivia-yoga' ); ?></p>
		<ol class="steps"><?php foreach ( $steps as $s ) : ?><li><div><b><?php echo esc_html( $s[0] ); ?></b><span><?php echo esc_html( $s[1] ); ?></span></div></li><?php endforeach; ?></ol>
		<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Try booking a class', 'olivia-yoga' ), 'orchid' ) . oy_btn( oy_account_url(), __( 'My account', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
	</div>
	<div class="private__side" data-reveal>
		<div class="frame"><?php echo oy_photo( 'olivia-dancer-home', '(min-width: 960px) 34vw, 92vw' ); // phpcs:ignore ?></div>
	</div>
</div></section>

<section class="section section--forest section--breath" aria-label="<?php esc_attr_e( 'Breathing exercise', 'olivia-yoga' ); ?>"><div class="container"><?php echo oy_breath(); // phpcs:ignore ?></div></section>

<section class="section section--tight" id="pricing" aria-labelledby="pricing-title"><div class="container">
	<?php echo oy_section_head( __( 'Simple pricing', 'olivia-yoga' ), __( 'Drop in, save with a class pass or a membership, or give someone a gift card. Sample prices.', 'olivia-yoga' ), __( 'Pricing', 'olivia-yoga' ), 'pricing-title' ); // phpcs:ignore ?>
	<?php echo $studio( 'oys_pricing' ); // phpcs:ignore ?>
</div></section>

<section class="section" id="faq" aria-labelledby="faq-title"><div class="container faq-grid">
	<header class="section__head" data-reveal><?php echo oy_kicker( __( 'FAQ', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="faq-title"><?php esc_html_e( 'Before your first class', 'olivia-yoga' ); ?></h2><p class="lead"><?php esc_html_e( 'What to bring, how passes work and what happens if you need to cancel.', 'olivia-yoga' ); ?></p><?php echo oy_btn( oy_page_link( 'faq' ), __( 'All questions', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php echo oy_faq_list( true ); // phpcs:ignore ?>
</div></section>

<?php $journal = get_posts( array( 'numberposts' => 3 ) ); ?>
<?php if ( $journal ) : ?>
<section class="section section--pale" aria-labelledby="journal-title"><div class="container">
	<header class="section__head section__head--row" data-reveal><div><?php echo oy_kicker( __( 'Journal', 'olivia-yoga' ) ); // phpcs:ignore ?><h2 id="journal-title"><?php esc_html_e( 'From the journal', 'olivia-yoga' ); ?></h2></div><?php echo oy_btn( oy_page_link( 'journal' ), __( 'All posts', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></header>
	<?php get_template_part( 'template-parts/cards', null, array( 'posts' => $journal ) ); ?>
</div></section>
<?php endif; ?>

<section class="section cta" aria-labelledby="cta-title"><div class="container"><div class="cta__inner" data-reveal>
	<h2 id="cta-title"><?php esc_html_e( 'Your mat is waiting', 'olivia-yoga' ); ?></h2>
	<p class="lead"><?php esc_html_e( 'Book a class, request a private session, or send a question. Beginners always welcome.', 'olivia-yoga' ); ?></p>
	<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid', 'btn--lg' ) . oy_btn( oy_contact_url(), __( 'Send a message', 'olivia-yoga' ), 'ghost' ); // phpcs:ignore ?></p>
</div></div></section>
