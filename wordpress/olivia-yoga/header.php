<?php defined( 'ABSPATH' ) || exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2B5036">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='20' fill='%232B5036'/%3E%3Ccircle cx='31' cy='16' r='5' fill='%23EE3F9A'/%3E%3C/svg%3E">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'olivia-yoga' ); ?></a>
<div class="notice"><p><b><?php esc_html_e( 'New in Fort Myers', 'olivia-yoga' ); ?></b> <?php esc_html_e( 'Group flows, private sessions at your home or on the beach, and yoga for teams.', 'olivia-yoga' ); ?></p></div>
<?php
$account_label = is_user_logged_in() ? __( 'My account', 'olivia-yoga' ) : __( 'Log in', 'olivia-yoga' );
$brand         = '<a class="brand" href="' . esc_url( home_url( '/' ) ) . '" rel="home"><svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#2B5036"/><path d="M9 25c4-3.5 8 2.5 12-1s7-2.5 10-.5" fill="none" stroke="#C6A3EE" stroke-width="2.6" stroke-linecap="round"/><circle cx="26.5" cy="13" r="4" fill="#EE3F9A"/></svg><span><span class="brand__name">Olivia Kovács</span><span class="brand__sub">Flow yoga · Fort Myers</span></span></a>';
?>
<header class="site-header" data-header>
	<div class="container site-header__inner">
		<?php echo $brand; // phpcs:ignore ?>
		<nav class="nav" aria-label="<?php esc_attr_e( 'Main', 'olivia-yoga' ); ?>"><?php echo oy_menu(); // phpcs:ignore ?></nav>
		<a class="header-account" href="<?php echo esc_url( oy_account_url() ); ?>"><?php echo oy_icon( 'user' ); // phpcs:ignore ?><span><?php echo esc_html( $account_label ); ?></span></a>
		<a class="btn btn--primary btn--sm header-cta" href="<?php echo esc_url( oy_book_url() ); ?>"><?php esc_html_e( 'Book a class', 'olivia-yoga' ); ?></a>
		<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="drawer" data-nav-toggle>
			<span class="i-open"><?php echo oy_icon( 'menu' ); // phpcs:ignore ?></span><span class="i-close"><?php echo oy_icon( 'close' ); // phpcs:ignore ?></span><span class="sr-only"><?php esc_html_e( 'Menu', 'olivia-yoga' ); ?></span>
		</button>
	</div>
</header>
<div class="drawer" id="drawer">
	<nav aria-label="<?php esc_attr_e( 'Mobile', 'olivia-yoga' ); ?>"><?php echo oy_menu( true ); // phpcs:ignore ?></nav>
	<p class="btn-row"><?php echo oy_btn( oy_book_url(), __( 'Book a class', 'olivia-yoga' ), 'orchid' ) . oy_btn( oy_account_url(), $account_label, 'line-light' ); // phpcs:ignore ?></p>
	<p class="drawer__contact"><a href="mailto:<?php echo esc_attr( OY_EMAIL ); ?>"><?php echo esc_html( OY_EMAIL ); ?></a></p>
</div>
<main id="main">
