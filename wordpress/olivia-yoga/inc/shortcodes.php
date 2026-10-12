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
		$meta  = '';
		foreach ( array( 'clock' => oy_class_meta( $c->ID, 'oy_duration' ), 'globe' => oy_class_meta( $c->ID, 'oy_lang' ) ) as $icon => $value ) {
			$meta .= $value ? '<span>' . oy_icon( $icon ) . esc_html( $value ) . '</span>' : '';
		}
		$rows .= '<li class="class-row class-row--' . $tone . '"><a href="' . esc_url( oy_class_url( $c->post_name ) ) . '"><span class="class-row__name">' . esc_html( get_the_title( $c ) ) . '</span>'
			. '<span class="class-row__desc">' . esc_html( get_the_excerpt( $c ) ) . '</span><span class="class-row__meta">' . $meta . '</span>'
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
		$out .= '<details' . ( 0 === $i ? ' open' : '' ) . '><summary><span>' . esc_html( get_the_title( $f ) ) . '</span>' . oy_icon( 'plus' ) . '</summary><div class="answer">' . wpautop( wp_kses_post( oy_post_content( $f ) ) ) . '</div></details>';
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

/** Past events as a photo grid, newest first, grouped by year. */
function oy_moments_grid( $limit = 60 ) {
	$items = oy_moments( $limit );
	if ( ! $items ) {
		return '';
	}
	$out  = '';
	$year = '';
	foreach ( $items as $i => $m ) {
		$date = (string) get_post_meta( $m->ID, 'oy_date', true );
		$y    = substr( $date, 0, 4 );
		if ( $y !== $year ) {
			$out .= ( $year ? '</ul>' : '' ) . '<h3 class="moments__year">' . esc_html( $y ) . '</h3><ul class="moments">';
			$year = $y;
		}
		$when  = $date ? wp_date( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) ) : '';
		$place = oy_tr( $m->ID, 'place' ) ?: oy_t( (string) get_post_meta( $m->ID, 'oy_place', true ) );
		$out  .= '<li class="moment moment--' . OY_ACCENTS[ $i % 4 ] . '" data-reveal><figure class="moment__photo">' . oy_post_photo( $m->ID, '', '(min-width: 960px) 30vw, 92vw', false, '' ) . '</figure>'
			. '<div class="moment__body"><p class="moment__meta">' . esc_html( $when ) . ( $place ? ' · ' . esc_html( $place ) : '' ) . '</p><h4 class="moment__title">' . esc_html( get_the_title( $m ) ) . '</h4>'
			. '<p class="moment__text">' . esc_html( get_the_excerpt( $m ) ) . '</p></div></li>';
	}
	return '<div class="moments-wrap">' . $out . '</ul></div>';
}

/** Breathing pacer: a lotus that opens on the inhale and folds on the exhale, three rounds. */
function oy_breath() {
	$petals = '';
	for ( $i = 0; $i < 8; $i++ ) {
		$petals .= '<span class="breath__petal" style="--i:' . $i . '"></span>';
	}
	$words = array(
		'in'    => __( 'Breathe in', 'olivia-yoga' ),
		'out'   => __( 'Breathe out', 'olivia-yoga' ),
		'ready' => __( 'Ready?', 'olivia-yoga' ),
		'round' => __( 'Breath %1$d of %2$d', 'olivia-yoga' ),
		'done'  => __( 'Welcome back. Notice how you feel now. That is how every class begins.', 'olivia-yoga' ),
		'stop'  => __( 'Stop', 'olivia-yoga' ),
	);
	return '<div class="breath" data-breath data-words="' . esc_attr( wp_json_encode( $words ) ) . '">'
		. '<div class="breath__text">'
		. oy_kicker( __( 'Try it now · 30 seconds', 'olivia-yoga' ) )
		. '<h2>' . esc_html__( 'Before you decide, take three breaths with me', 'olivia-yoga' ) . '</h2>'
		. '<p class="lead">' . esc_html__( 'A longer exhale is the quickest way to tell your nervous system it is safe to slow down. Follow the lotus: it opens as you breathe in and folds as you breathe out.', 'olivia-yoga' ) . '</p>'
		. '<ol class="breath__guide"><li><b>4</b><span>' . esc_html__( 'counts in, through the nose', 'olivia-yoga' ) . '</span></li><li><b>6</b><span>' . esc_html__( 'counts out, slow and soft', 'olivia-yoga' ) . '</span></li><li><b>3</b><span>' . esc_html__( 'rounds, then notice the difference', 'olivia-yoga' ) . '</span></li></ol>'
		. '<button type="button" class="btn btn--light" data-breath-start>' . esc_html__( 'Start breathing', 'olivia-yoga' ) . '</button>'
		. '<p class="breath__status" aria-live="polite" data-breath-status></p></div>'
		. '<div class="breath__stage" aria-hidden="true"><div class="breath__glow"></div><div class="breath__lotus">' . $petals . '</div>'
		. '<svg class="breath__ring" viewBox="0 0 200 200"><circle class="breath__track" cx="100" cy="100" r="94"/><circle class="breath__progress" cx="100" cy="100" r="94" pathLength="100"/></svg>'
		. '<div class="breath__center"><span class="breath__phase" data-breath-phase>' . esc_html( $words['ready'] ) . '</span><b class="breath__count" data-breath-count>4·6</b></div>'
		. '<ol class="breath__dots"><li></li><li></li><li></li></ol></div>'
		. '</div>';
}

function oy_contact_topics() {
	return array(
		'group'     => __( 'Group class', 'olivia-yoga' ),
		'private'   => __( 'Private yoga', 'olivia-yoga' ),
		'prenatal'  => __( 'Prenatal fitness', 'olivia-yoga' ),
		'massage'   => __( 'Singing bowl massage', 'olivia-yoga' ),
		'corporate' => __( 'Teams & companies', 'olivia-yoga' ),
		'athletes'  => __( 'Athletes & mobility', 'olivia-yoga' ),
		'event'     => __( 'Event or retreat', 'olivia-yoga' ),
		'gift'      => __( 'Gift card', 'olivia-yoga' ),
		'other'     => __( 'Something else', 'olivia-yoga' ),
	);
}

function oy_contact_form() {
	$chips = '';
	foreach ( oy_contact_topics() as $k => $v ) {
		$chips .= '<label class="topic"><input type="radio" name="topic" value="' . esc_attr( $k ) . '"' . checked( 'group', $k, false ) . '><span>' . esc_html( $v ) . '</span></label>';
	}
	$notice = '';
	if ( isset( $_GET['oy_sent'] ) ) {
		$notice = '1' === $_GET['oy_sent']
			? '<p class="form__notice form__notice--ok" role="status">' . esc_html__( 'Thank you, your message is on its way. I will reply as soon as I can, usually within a day.', 'olivia-yoga' ) . '</p>'
			: '<p class="form__notice oys-notice--error" role="alert">' . esc_html__( 'Please fill in your name, a valid email and a message.', 'olivia-yoga' ) . '</p>';
	}
	$err = array(
		'required' => __( 'Fill in this field.', 'olivia-yoga' ),
		'email'    => __( 'Enter an email address like name@example.com.', 'olivia-yoga' ),
	);
	return '<form class="form form--contact" id="book" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" data-contact data-errors="' . esc_attr( wp_json_encode( $err ) ) . '">' . $notice
		. '<input type="hidden" name="action" value="oy_contact">' . wp_nonce_field( 'oy_contact', '_wpnonce', true, false )
		. '<input type="hidden" name="lang" value="' . esc_attr( oy_lang() ) . '">'
		. '<div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
		. '<fieldset class="topics"><legend>' . esc_html__( 'What would you like to talk about?', 'olivia-yoga' ) . '</legend><div class="topics__list">' . $chips . '</div></fieldset>'
		. '<div class="form__row"><label class="field"><span class="field__label">' . esc_html__( 'Your name', 'olivia-yoga' ) . '</span><input type="text" name="name" id="oy-c-name" required autocomplete="name"><span class="field__error" aria-live="polite"></span></label>'
		. '<label class="field"><span class="field__label">' . esc_html__( 'Email', 'olivia-yoga' ) . '</span><input type="email" name="email" id="oy-c-email" required autocomplete="email"><span class="field__error" aria-live="polite"></span></label></div>'
		. '<div class="form__row"><label class="field"><span class="field__label">' . esc_html__( 'Phone (optional)', 'olivia-yoga' ) . '</span><input type="tel" name="phone" id="oy-c-phone" autocomplete="tel"></label>'
		. '<label class="field"><span class="field__label">' . esc_html__( 'Your area (optional)', 'olivia-yoga' ) . '</span><input type="text" name="area" id="oy-c-area" placeholder="' . esc_attr__( 'McGregor, Cape Coral, Fort Myers Beach…', 'olivia-yoga' ) . '"></label></div>'
		. '<label class="field"><span class="field__label">' . esc_html__( 'Message', 'olivia-yoga' ) . '</span><textarea name="message" id="oy-c-message" rows="5" required placeholder="' . esc_attr__( 'Tell me a little about your practice, any injuries or a pregnancy I should know about, and when you would like to start.', 'olivia-yoga' ) . '"></textarea><span class="field__error" aria-live="polite"></span></label>'
		. '<div class="form__foot"><button type="submit" class="btn btn--orchid">' . esc_html__( 'Send message', 'olivia-yoga' ) . oy_icon( 'arrow' ) . '</button>'
		. '<p class="form__small">' . esc_html__( 'I only use your details to reply to you.', 'olivia-yoga' ) . '</p></div></form>';
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
	$langs = function_exists( 'oys_languages' ) ? oys_languages() : array( 'en' => array( 'name' => 'English' ) );
	$lang  = sanitize_key( $_POST['lang'] ?? 'en' );
	$body  = sprintf( "Name: %s\nEmail: %s\nPhone: %s\nTopic: %s\nArea: %s\nLanguage: %s\n\n%s", $name, $email, sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), sanitize_key( $_POST['topic'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['area'] ?? '' ) ), $langs[ $lang ]['name'] ?? 'English', $message );
	$to    = class_exists( 'OYS_Settings' ) ? OYS_Settings::get( 'notify_email' ) : get_option( 'admin_email' );
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
	add_shortcode( 'oy_moments', fn() => '<div class="block-wide">' . oy_moments_grid() . '</div>' );
	add_shortcode( 'oy_schedule', fn() => '<div class="block-wide">' . $studio( 'oys_schedule', '<p>' . esc_html__( 'The timetable is coming soon.', 'olivia-yoga' ) . '</p>' ) . '</div>' );
	add_shortcode( 'oy_pricing', fn() => '<div class="block-wide">' . $studio( 'oys_pricing', '' ) . '</div>' );
	add_shortcode( 'oy_events', fn() => '<div class="block-wide oy-events-dark">' . $studio( 'oys_events', '' ) . '</div>' );
	add_shortcode( 'oy_booking_link', fn( $a ) => oy_btn_row( oy_btn( oy_book_url(), oy_t( $a['label'] ?? '' ) ?: __( 'Book a class', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_private_link', fn( $a ) => oy_btn_row( oy_btn( oy_private_url(), oy_t( $a['label'] ?? '' ) ?: __( 'Book a private session', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_gift_link', fn( $a ) => oy_btn_row( oy_btn( function_exists( 'oys_page_url' ) ? oys_page_url( 'gifts' ) : oy_contact_url( 'gift' ), oy_t( $a['label'] ?? '' ) ?: __( 'Buy a gift card', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_button', fn( $a ) => oy_btn_row( oy_btn( oy_resolve_tokens( html_entity_decode( $a['url'] ?? '#' ) ), oy_t( $a['label'] ?? '' ) ?: __( 'Book', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_fitmummy', fn() => oy_fitmummy() );
	add_shortcode( 'oy_picto', fn( $a ) => oy_pictogram( sanitize_key( $a['icon'] ?? 'lotus' ), sanitize_key( $a['tone'] ?? 'lilac' ) ) );
} );

/** The Fitmummy prenatal & postnatal trainer mark, as a small credential card. */
function oy_fitmummy( $cls = '' ) {
	return '<figure class="credential ' . esc_attr( $cls ) . '"><img src="' . esc_url( get_template_directory_uri() . '/assets/img/fitmummy.svg' ) . '" alt="Fitmummy" width="220" height="68" loading="lazy">'
		. '<figcaption>' . esc_html__( 'Prenatal & postnatal fitness training: Fitmummy method', 'olivia-yoga' ) . '</figcaption></figure>';
}

// Shortcodes on their own line come wrapped in <p>; unwrap them so blocks aren't nested in paragraphs.
add_filter( 'the_content', function ( $content ) {
	return preg_replace( '#<p>\s*(\[(?:oy|oys)_[^\]]+\])\s*</p>#', '$1', $content );
}, 9 );
