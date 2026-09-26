<?php
/**
 * Shortcodes used in the page copy. Booking-related ones hand over to the Olivia Studio plugin.
 */

defined( 'ABSPATH' ) || exit;

const OY_AREAS = array( 'Downtown Fort Myers & the River District', 'McGregor Boulevard', 'Gateway', 'Whiskey Creek', 'Fort Myers Beach', 'Sanibel & Captiva', 'Cape Coral', 'Estero', 'Bonita Springs', 'Lehigh Acres' );

function oy_classes_list( $exclude = '' ) {
	$rows = '';
	$n    = 0;
	foreach ( oy_classes() as $c ) {
		if ( $c->post_name === $exclude ) {
			continue;
		}
		$tone  = OY_ACCENTS[ $n++ % 4 ];
		$thumb = oy_post_photo( $c->ID, '', '200px', false, '' );
		$rows .= '<li class="class-row class-row--' . $tone . '"><a href="' . esc_url( oy_class_url( $c->post_name ) ) . '"><span class="class-row__name">' . esc_html( $c->post_title ) . '</span>'
			. '<span class="class-row__desc">' . esc_html( get_the_excerpt( $c ) ) . '</span><span class="class-row__meta"><span>' . esc_html( get_post_meta( $c->ID, 'oy_duration', true ) ) . '</span><span>' . esc_html( get_post_meta( $c->ID, 'oy_level', true ) ) . '</span>' . oy_intensity( get_post_meta( $c->ID, 'oy_intensity', true ) ) . '</span>'
			. '<span class="class-row__thumb" aria-hidden="true">' . $thumb . '</span><span class="class-row__go" aria-hidden="true">' . oy_icon( 'arrow' ) . '</span></a></li>';
	}
	return '<ul class="class-list">' . $rows . '</ul>';
}

function oy_faq_list( $home_only = false ) {
	$args = array( 'post_type' => 'oy_faq', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' );
	if ( $home_only ) {
		$args['meta_key']   = 'oy_home';
		$args['meta_value'] = '1';
	}
	$out = '<div class="faq">';
	foreach ( get_posts( $args ) as $i => $f ) {
		$out .= '<details' . ( 0 === $i ? ' open' : '' ) . '><summary><span>' . esc_html( $f->post_title ) . '</span>' . oy_icon( 'plus' ) . '</summary><div class="answer">' . wpautop( wp_kses_post( $f->post_content ) ) . '</div></details>';
	}
	return $out . '</div>';
}

function oy_areas_list() {
	$out = '<ul class="areas">';
	foreach ( OY_AREAS as $i => $a ) {
		$out .= '<li class="area--' . OY_ACCENTS[ $i % 4 ] . '">' . esc_html( $a ) . '</li>';
	}
	return $out . '</ul>';
}

function oy_gallery( array $keys ) {
	$shapes = array( 'g1', 'g2', 'g3', 'g4' );
	$out    = '<div class="gallery">';
	foreach ( $keys as $i => $k ) {
		$out .= '<figure class="gallery__item ' . $shapes[ $i % 4 ] . '" data-reveal>' . oy_photo( $k, '(min-width: 960px) 25vw, 50vw' ) . '</figure>';
	}
	return $out . '</div>';
}

function oy_breath() {
	return '<div class="breath" data-breath><div class="breath__circle" aria-hidden="true"><span class="breath__ring breath__ring--1"></span><span class="breath__ring breath__ring--2"></span><span class="breath__ring breath__ring--3"></span><span class="breath__core"></span></div><div>'
		. oy_kicker( __( 'Try it now · 30 seconds', 'olivia-yoga' ) )
		. '<h2>' . esc_html__( 'Before you decide, take three breaths with me', 'olivia-yoga' ) . '</h2>'
		. '<p class="lead">' . esc_html__( 'Four counts in, six counts out. A longer exhale is the quickest way to tell your nervous system it\'s safe to slow down.', 'olivia-yoga' ) . '</p>'
		. '<button type="button" class="btn btn--light" data-breath-start>' . esc_html__( 'Start breathing', 'olivia-yoga' ) . '</button>'
		. '<p class="breath__status" aria-live="polite" data-breath-status></p></div></div>';
}

function oy_contact_form() {
	$topics = array( 'group' => 'Group class', 'private' => 'Private yoga session', 'corporate' => 'Corporate or team yoga', 'athletes' => 'Yoga for athletes', 'event' => 'Event or retreat', 'gift' => 'Gift card', 'other' => 'Something else' );
	$opts   = '';
	foreach ( $topics as $k => $v ) {
		$opts .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
	}
	$notice = '';
	if ( isset( $_GET['oy_sent'] ) ) {
		$notice = '1' === $_GET['oy_sent']
			? '<p class="form__notice form__notice--ok" role="status">' . esc_html__( 'Thank you — your message is on its way. I\'ll reply as soon as I can, usually within a day.', 'olivia-yoga' ) . '</p>'
			: '<p class="form__notice oys-notice--error" role="alert">' . esc_html__( 'Please fill in your name, a valid email and a message.', 'olivia-yoga' ) . '</p>';
	}
	return '<form class="form" id="book" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" data-contact>' . $notice
		. '<input type="hidden" name="action" value="oy_contact">' . wp_nonce_field( 'oy_contact', '_wpnonce', true, false )
		. '<div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
		. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Your name', 'olivia-yoga' ) . '</span><input type="text" name="name" id="oy-c-name" required autocomplete="name"><span class="field__error" aria-live="polite"></span></label>'
		. '<label class="field"><span>' . esc_html__( 'Email', 'olivia-yoga' ) . '</span><input type="email" name="email" id="oy-c-email" required autocomplete="email"><span class="field__error" aria-live="polite"></span></label></div>'
		. '<div class="form__row"><label class="field"><span>' . esc_html__( 'Phone (optional)', 'olivia-yoga' ) . '</span><input type="tel" name="phone" id="oy-c-phone" autocomplete="tel"></label>'
		. '<label class="field"><span>' . esc_html__( 'I\'m interested in', 'olivia-yoga' ) . '</span><select name="topic" id="oy-c-topic">' . $opts . '</select></label></div>'
		. '<label class="field"><span>' . esc_html__( 'Your area (e.g. McGregor, Cape Coral, Fort Myers Beach)', 'olivia-yoga' ) . '</span><input type="text" name="area" id="oy-c-area"></label>'
		. '<label class="field"><span>' . esc_html__( 'Message', 'olivia-yoga' ) . '</span><textarea name="message" id="oy-c-message" rows="5" required placeholder="' . esc_attr__( 'Tell me a little about your practice, any injuries I should know about, and when you\'d like to start.', 'olivia-yoga' ) . '"></textarea><span class="field__error" aria-live="polite"></span></label>'
		. '<button type="submit" class="btn btn--primary">' . esc_html__( 'Send message', 'olivia-yoga' ) . '</button>'
		. '<p class="form__small">' . esc_html__( 'I\'ll only use your details to reply to you.', 'olivia-yoga' ) . '</p></form>';
}

add_action( 'admin_post_nopriv_oy_contact', 'oy_handle_contact' );
add_action( 'admin_post_oy_contact', 'oy_handle_contact' );
function oy_handle_contact() {
	check_admin_referer( 'oy_contact' );
	$back = remove_query_arg( 'oy_sent', wp_get_referer() ?: oy_page_link( 'contact' ) );
	$back = preg_replace( '/#.*$/', '', $back );
	if ( ! empty( $_POST['website'] ) ) {
		wp_safe_redirect( add_query_arg( 'oy_sent', '1', $back ) . '#book' ); // Honeypot: pretend success.
		exit;
	}
	$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
	if ( ! $name || ! is_email( $email ) || ! $message ) {
		wp_safe_redirect( add_query_arg( 'oy_sent', '0', $back ) . '#book' );
		exit;
	}
	$body = sprintf( "Name: %s\nEmail: %s\nPhone: %s\nTopic: %s\nArea: %s\n\n%s", $name, $email, sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), sanitize_key( $_POST['topic'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['area'] ?? '' ) ), $message );
	$to   = class_exists( 'OYS_Settings' ) ? OYS_Settings::get( 'notify_email' ) : get_option( 'admin_email' );
	wp_mail( $to, 'Website message: ' . $name, $body, array( 'Reply-To: ' . $name . ' <' . $email . '>' ) );
	wp_safe_redirect( add_query_arg( 'oy_sent', '1', $back ) . '#book' );
	exit;
}

function oy_btn_row( $html ) {
	return '<p class="btn-row">' . $html . '</p>';
}

add_action( 'init', function () {
	$studio = fn( $sc, $fallback ) => shortcode_exists( $sc ) ? do_shortcode( '[' . $sc . ']' ) : $fallback;
	add_shortcode( 'oy_classes', fn() => '<div class="block-wide">' . oy_classes_list() . '</div>' );
	add_shortcode( 'oy_faq', fn() => oy_faq_list() );
	add_shortcode( 'oy_areas', fn() => oy_areas_list() );
	add_shortcode( 'oy_breath', fn() => oy_breath() );
	add_shortcode( 'oy_contact_form', fn() => oy_contact_form() );
	add_shortcode( 'oy_schedule', fn() => '<div class="block-wide">' . $studio( 'oys_schedule', '<p>' . esc_html__( 'The timetable is coming soon.', 'olivia-yoga' ) . '</p>' ) . '</div>' );
	add_shortcode( 'oy_pricing', fn() => '<div class="block-wide">' . $studio( 'oys_pricing', '' ) . '</div>' );
	add_shortcode( 'oy_events', fn() => '<div class="block-wide oy-events-dark">' . $studio( 'oys_events', '' ) . '</div>' );
	add_shortcode( 'oy_booking_link', fn( $a ) => oy_btn_row( oy_btn( oy_book_url(), $a['label'] ?? __( 'Book a class', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_private_link', fn( $a ) => oy_btn_row( oy_btn( oy_private_url(), $a['label'] ?? __( 'Book a private session', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_gift_link', fn( $a ) => oy_btn_row( oy_btn( function_exists( 'oys_page_url' ) ? oys_page_url( 'gifts' ) : oy_contact_url( 'gift' ), $a['label'] ?? __( 'Buy a gift card', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_button', fn( $a ) => oy_btn_row( oy_btn( oy_resolve_tokens( html_entity_decode( $a['url'] ?? '#' ) ), $a['label'] ?? __( 'Book', 'olivia-yoga' ) ) ) );
} );

// Shortcodes on their own line come wrapped in <p>; unwrap them so blocks aren't nested in paragraphs.
add_filter( 'the_content', function ( $content ) {
	return preg_replace( '#<p>\s*(\[(?:oy|oys)_[^\]]+\])\s*</p>#', '$1', $content );
}, 9 );
