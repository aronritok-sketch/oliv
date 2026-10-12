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
				<p class="footer__motto"><?php esc_html_e( 'Dynamic yet gentle. Just enough of everything.', 'olivia-yoga' ); ?></p>
				<p><?php echo esc_html( oy_title_line() ); ?></p>
				<?php echo oy_specialities_line( 'specialities specialities--footer' ); // phpcs:ignore ?>
				<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ); // phpcs:ignore ?></p>
			</div>
			<nav aria-label="<?php esc_attr_e( 'Practice', 'olivia-yoga' ); ?>"><h2><?php esc_html_e( 'Practice', 'olivia-yoga' ); ?></h2><ul>
				<?php echo $link( 'yoga-classes', __( 'Classes', 'olivia-yoga' ) ) . $link( 'private-yoga', __( 'Private yoga', 'olivia-yoga' ) ) . $link( 'schedule-pricing', __( 'Schedule & pricing', 'olivia-yoga' ) ) . $link( 'corporate-yoga', __( 'Teams & athletes', 'olivia-yoga' ) ) . $link( 'events', __( 'Events', 'olivia-yoga' ) ); // phpcs:ignore ?>
			</ul></nav>
			<nav aria-label="<?php esc_attr_e( 'More', 'olivia-yoga' ); ?>"><h2><?php esc_html_e( 'More', 'olivia-yoga' ); ?></h2><ul>
				<?php echo $link( 'about', __( 'About Olivia', 'olivia-yoga' ) ) . $link( 'faq', __( 'FAQ', 'olivia-yoga' ) ) . $link( 'journal', __( 'Journal', 'olivia-yoga' ) ) . $link( 'contact', __( 'Contact', 'olivia-yoga' ) ); // phpcs:ignore ?>
				<li><a href="<?php echo esc_url( $gift ); ?>"><?php esc_html_e( 'Gift cards', 'olivia-yoga' ); ?></a></li>
				<li><a href="<?php echo esc_url( oy_account_url() ); ?>"><?php esc_html_e( 'My account', 'olivia-yoga' ); ?></a></li>
			</ul></nav>
			<div><h2><?php esc_html_e( 'Say hello', 'olivia-yoga' ); ?></h2>
				<ul class="footer__contact"><li><?php echo oy_icon( 'mail' ); // phpcs:ignore ?><a href="mailto:<?php echo esc_attr( OY_EMAIL ); ?>"><?php echo esc_html( OY_EMAIL ); ?></a></li><li><?php echo oy_icon( 'pin' ); // phpcs:ignore ?><span>Fort Myers, FL</span></li><?php if ( function_exists( 'oys_fb_group_url' ) && oys_fb_group_url() ) : ?><li><?php echo oy_icon( 'facebook' ); // phpcs:ignore ?><a href="<?php echo esc_url( oys_fb_group_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Join our Facebook group', 'olivia-yoga' ); ?></a></li><?php endif; ?></ul>
				<p class="social"><a href="<?php echo esc_url( OY_INSTAGRAM ); ?>" rel="noopener" target="_blank"><?php echo oy_icon( 'instagram' ); // phpcs:ignore ?><span class="sr-only">Instagram</span></a><a href="<?php echo esc_url( OY_FACEBOOK ); ?>" rel="noopener" target="_blank"><?php echo oy_icon( 'facebook' ); // phpcs:ignore ?><span class="sr-only">Facebook</span></a></p>
				<?php echo oy_lang_switcher( 'langs langs--footer' ); // phpcs:ignore ?>
			</div>
		</div>
		<p class="footer__word" aria-hidden="true">Olivia <span>Kovacs</span></p>
		<div class="footer__bottom">
			<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> Olivia Kovacs Yoga · <a href="<?php echo esc_url( get_privacy_policy_url() ?: oy_page_link( 'privacy-policy' ) ); ?>"><?php esc_html_e( 'Privacy', 'olivia-yoga' ); ?></a></p>
			<p><?php esc_html_e( 'Yoga is not a substitute for medical care. If you have a health condition or are pregnant, talk to your doctor before starting a new practice.', 'olivia-yoga' ); ?></p>
		</div>
	</div>
</footer>
<nav class="action-bar" aria-label="<?php esc_attr_e( 'Quick actions', 'olivia-yoga' ); ?>" data-action-bar>
	<a href="<?php echo esc_url( oy_book_url() ); ?>"><?php echo oy_icon( 'calendar' ); // phpcs:ignore ?><?php esc_html_e( 'Book', 'olivia-yoga' ); ?></a>
	<a href="<?php echo esc_url( oy_private_url() ); ?>"><?php echo oy_icon( 'home', 'icon' ); // phpcs:ignore ?><?php esc_html_e( 'Private', 'olivia-yoga' ); ?></a>
	<a href="<?php echo esc_url( oy_account_url() ); ?>"><?php echo oy_icon( 'user' ); // phpcs:ignore ?><?php esc_html_e( 'Account', 'olivia-yoga' ); ?></a>
</nav>
<?php if ( ! is_page( array( 'book', 'account' ) ) ) : ?><a class="book-fab" href="<?php echo esc_url( oy_book_url() ); ?>" data-book-fab><?php echo oy_icon( 'calendar' ); // phpcs:ignore ?><?php esc_html_e( 'Book a class', 'olivia-yoga' ); ?></a><?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
