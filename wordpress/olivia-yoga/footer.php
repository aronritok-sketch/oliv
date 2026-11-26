<?php defined( 'ABSPATH' ) || exit; ?>
</main>
<?php
$link = fn( $slug, $label ) => '<li><a href="' . esc_url( oy_page_link( $slug ) ) . '">' . esc_html( $label ) . '</a></li>';
$gift = function_exists( 'oys_page_url' ) ? oys_page_url( 'gifts' ) : oy_contact_url( 'gift' );
?>
<footer class="site-footer">
	<div class="container">
		<div class="footer__grid">
			<div class="footer__brand">
				<?php if ( oy_online_only() ) : ?><a class="oj-foot-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( oy_site_name() ); ?>"><?php echo oy_online_logo(); // phpcs:ignore ?></a><?php endif; ?>
				<p class="footer__motto"><?php echo esc_html( oy_is_demo() ? __( 'Book, pay and practice in one place.', 'olivia-yoga' ) : __( 'Dynamic yet gentle. Just enough of everything.', 'olivia-yoga' ) ); ?></p>
				<?php if ( ! oy_online_only() ) : // Áron, 26 November (the online footer): „most jó a footer csak ezt szedd ki” – the title line ?><p><?php echo esc_html( oy_title_line() ); ?></p><?php endif; ?>
				<?php echo oy_online_only() ? oy_specialities_text() : oy_specialities_line( 'specialities specialities--footer' ); // phpcs:ignore ?>
				<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ); // phpcs:ignore ?></p>
			</div>
			<nav aria-label="<?php esc_attr_e( 'Practice', 'olivia-yoga' ); ?>"><h2><?php esc_html_e( 'Practice', 'olivia-yoga' ); ?></h2><ul>
				<?php echo oy_is_demo() ? $link( 'yoga-classes', __( 'Classes', 'olivia-yoga' ) ) . $link( 'schedule-pricing', __( 'Schedule & pricing', 'olivia-yoga' ) ) . $link( 'private-yoga', __( 'Private sessions', 'olivia-yoga' ) ) : ( oy_online_only() ? $link( 'yoga-classes', __( 'Online classes', 'olivia-yoga' ) ) . $link( 'schedule-pricing', oy_schedule_label() ) . '<li><a href="' . esc_url( oy_page_link( 'yoga-classes', 'egyeni-orak' ) ) . '">' . esc_html__( 'Individual online sessions', 'olivia-yoga' ) . '</a></li>' : $link( 'yoga-classes', __( 'Classes', 'olivia-yoga' ) ) . $link( 'private-yoga', __( 'Private yoga', 'olivia-yoga' ) ) . $link( 'schedule-pricing', __( 'Schedule & pricing', 'olivia-yoga' ) ) . $link( 'corporate-yoga', __( 'Teams & athletes', 'olivia-yoga' ) ) . $link( 'events', __( 'Events', 'olivia-yoga' ) ) ); // phpcs:ignore ?>
			</ul></nav>
			<nav aria-label="<?php esc_attr_e( 'More', 'olivia-yoga' ); ?>"><h2><?php esc_html_e( 'More', 'olivia-yoga' ); ?></h2><ul>
				<?php echo $link( 'about', oy_brand( 'about' ) ) . $link( 'faq', __( 'FAQ', 'olivia-yoga' ) ) . $link( 'journal', oy_online_only() ? __( 'About yoga', 'olivia-yoga' ) : __( 'Journal', 'olivia-yoga' ) ) . $link( 'contact', __( 'Contact', 'olivia-yoga' ) ); // phpcs:ignore ?>
				<li><a href="<?php echo esc_url( $gift ); ?>"><?php esc_html_e( 'Gift cards', 'olivia-yoga' ); ?></a></li>
				<li><a href="<?php echo esc_url( oy_account_url() ); ?>"><?php esc_html_e( 'My account', 'olivia-yoga' ); ?></a></li>
			</ul></nav>
			<div><h2><?php esc_html_e( 'Say hello', 'olivia-yoga' ); ?></h2>
				<ul class="footer__contact"><li><?php echo oy_icon( 'mail' ); // phpcs:ignore ?><a href="mailto:<?php echo esc_attr( OY_EMAIL ); ?>"><?php echo esc_html( OY_EMAIL ); ?></a></li><li><?php echo oy_icon( oy_online_only() ? 'globe' : 'pin' ); // phpcs:ignore ?><span><?php echo esc_html( oy_brand( 'place' ) ); ?></span></li><?php if ( function_exists( 'oys_fb_group_url' ) && oys_fb_group_url() ) : ?><li><?php echo oy_icon( 'facebook' ); // phpcs:ignore ?><a href="<?php echo esc_url( oys_fb_group_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Join our Facebook group', 'olivia-yoga' ); ?></a></li><?php endif; ?></ul>
				<?php if ( OY_INSTAGRAM || OY_FACEBOOK ) : ?><p class="social"><a href="<?php echo esc_url( OY_INSTAGRAM ); ?>" rel="noopener" target="_blank"><?php echo oy_icon( 'instagram' ); // phpcs:ignore ?><span class="sr-only">Instagram</span></a><a href="<?php echo esc_url( OY_FACEBOOK ); ?>" rel="noopener" target="_blank"><?php echo oy_icon( 'facebook' ); // phpcs:ignore ?><span class="sr-only">Facebook</span></a></p><?php endif; ?>
				<?php echo oy_lang_switcher( 'langs langs--footer' ); // phpcs:ignore ?>
			</div>
		</div>
		<?php if ( oy_online_only() && class_exists( 'OYS_Newsletter' ) ) : // Áron, 26 November: the newsletter signup in the footer too ?>
		<div class="footer__nl"><?php echo OYS_Newsletter::form_html( array( 'source' => 'online-lablec', 'title' => __( 'Newsletter', 'olivia-yoga' ), 'text' => __( 'Be the first to hear about new events and workshops.', 'olivia-yoga' ), 'back' => home_url( add_query_arg( array() ) ) ) ); // phpcs:ignore ?></div>
		<?php endif; ?>
		<?php if ( ! oy_online_only() ) : // Olívia, 14 November: the big name in the footer „szedjük ki mindenhonnan” ?>
		<p class="footer__word" aria-hidden="true"><?php echo preg_replace( '/ (.+)$/', ' <span>$1</span>', esc_html( oy_teacher_name() ) ); // phpcs:ignore ?></p>
		<?php endif; ?>
		<div class="footer__bottom">
			<p>&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . oy_site_name() ); ?><?php foreach ( oy_legal_pages() as $oy_l ) : ?> · <a href="<?php echo esc_url( $oy_l[1] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( 'Általános szerződési feltételek' === $oy_l[0] ? 'ÁSZF' : $oy_l[0] ); ?></a><?php endforeach; ?> · <a href="<?php echo esc_url( get_privacy_policy_url() ?: oy_page_link( 'privacy-policy' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy', 'olivia-yoga' ); ?></a><?php echo oy_consent_footer_link(); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php if ( ! oy_is_demo() ) : // the brand rule's maker line (Áron, 19 November: „helloprovision csinálta a webet, a megszokott módon”; until then Áron's name, 15 November) ?> · <span class="footer__credit"><?php esc_html_e( 'Made by', 'olivia-yoga' ); ?> <a href="https://helloprovision.com/" target="_blank" rel="noopener">HelloProVision</a></span><?php endif; ?></p>
			<p><?php esc_html_e( 'Yoga is not a substitute for medical care. If you have a health condition or are pregnant, talk to your doctor before starting a new practice.', 'olivia-yoga' ); ?></p>
		</div>
	</div>
</footer>
<?php if ( oy_online_only() ) : // Áron, 16 November: „App-szerű legyen” – on a phone one bar at the bottom, like an app: the classes, booking (in the middle, raised), the account and the menu; the header above is only the logo
	$oy_here = oy_is_studio_page();
	$oy_tabs = array(
		array( oy_page_link( 'yoga-classes' ), 'lotus', 'Órák', is_page( oy_page_slug( 'yoga-classes' ) ) || is_singular( 'oy_class' ) ),
		array( oy_book_url(), 'calendar', 'Foglalás', 'book' === $oy_here, true ),
		array( oy_account_url(), 'user', 'Fiók', 'account' === $oy_here ),
	);
	?>
<nav class="tabbar" aria-label="Gyors menü" data-action-bar data-tabbar>
	<?php foreach ( $oy_tabs as $oy_t ) : ?><a class="tabbar__item<?php echo ! empty( $oy_t[4] ) ? ' tabbar__item--main' : ''; ?><?php echo $oy_t[3] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $oy_t[0] ); ?>"<?php echo $oy_t[3] ? ' aria-current="page"' : ''; ?>><?php echo ! empty( $oy_t[4] ) ? '<span class="tabbar__dot">' . oy_icon( $oy_t[1] ) . '</span>' : oy_icon( $oy_t[1] ); // phpcs:ignore ?><span class="tabbar__label"><?php echo esc_html( $oy_t[2] ); ?></span></a><?php endforeach; ?>
	<button class="tabbar__item tabbar__menu" type="button" aria-expanded="false" aria-controls="drawer" data-nav-toggle><span class="i-open"><?php echo oy_icon( 'menu' ); // phpcs:ignore ?></span><span class="i-close"><?php echo oy_icon( 'close' ); // phpcs:ignore ?></span><span class="tabbar__label"><span class="i-open">Menü</span><span class="i-close">Bezár</span></span></button>
</nav>
<?php else : ?>
<nav class="action-bar" aria-label="<?php esc_attr_e( 'Quick actions', 'olivia-yoga' ); ?>" data-action-bar>
	<a href="<?php echo esc_url( oy_book_url() ); ?>"><?php echo oy_icon( 'calendar' ); // phpcs:ignore ?><?php esc_html_e( 'Book', 'olivia-yoga' ); ?></a>
	<a href="<?php echo esc_url( oy_private_url() ); ?>"><?php echo oy_icon( 'home', 'icon' ); // phpcs:ignore ?><?php echo esc_html( oy_online_only() ? __( 'Individual', 'olivia-yoga' ) : __( 'Private', 'olivia-yoga' ) ); ?></a>
	<a href="<?php echo esc_url( oy_account_url() ); ?>"><?php echo oy_icon( 'user' ); // phpcs:ignore ?><?php esc_html_e( 'Account', 'olivia-yoga' ); ?></a>
</nav>
<?php endif; ?>
<?php if ( ! in_array( oy_is_studio_page(), array( 'book', 'account' ), true ) ) : ?><a class="book-fab" href="<?php echo esc_url( oy_book_url() ); ?>" data-book-fab><?php echo oy_icon( 'calendar' ); // phpcs:ignore ?><?php esc_html_e( 'Book a class', 'olivia-yoga' ); ?></a><?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
