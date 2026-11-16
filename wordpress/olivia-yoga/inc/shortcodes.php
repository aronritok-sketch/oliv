<?php
/**
 * Shortcodes used in the page copy. Booking-related ones hand over to the Olivia Studio plugin.
 */

defined( 'ABSPATH' ) || exit;

const OY_AREAS = array( 'Downtown Fort Myers & the River District', 'McGregor Boulevard', 'Gateway', 'Whiskey Creek', 'Fort Myers Beach', 'Sanibel & Captiva', 'Cape Coral', 'Estero', 'Bonita Springs', 'Lehigh Acres' );

/** A bundled photo inside a page's text, framed; „oj-float” puts it beside the text on a wide screen. */
function oy_figure( $key, $cls = '' ) {
	$img = '' !== $key ? oy_photo( $key, '(min-width: 960px) 300px, 92vw' ) : '';
	$cls = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $cls ) ) ) );
	return '' === $img ? '' : '<figure class="oj-figure ' . esc_attr( $cls ) . '"><div class="frame">' . $img . '</div></figure>';
}

/**
 * A paragraph of an answer that starts with a class type („Jóga: …”, „Kismamatorna: …”) gets that type as a small tag in
 * its colour (Olívia, 14 November: „Itt az óratípusok legyenek valahogy kiemelve”). Online edition only.
 */
function oy_faq_tags( $html ) {
	if ( ! oy_online_only() ) {
		return $html;
	}
	$tones = array( 'Jóga' => 'lilac', 'Kismamatorna' => 'sage', 'Stretching és mobilizáció' => 'sun', 'Relaxáció és légzés' => 'pink' );
	return (string) preg_replace_callback(
		'~<p>(' . implode( '|', array_map( fn( $k ) => preg_quote( $k, '~' ), array_keys( $tones ) ) ) . '):\s*~u',
		fn( $m ) => '<p class="oj-typed"><span class="oj-faq-tag oj-faq-tag--' . $tones[ $m[1] ] . '">' . esc_html( $m[1] ) . '</span>',
		$html
	);
}

function oy_classes_list( $exclude = '' ) {
	if ( oy_online_only() ) {
		return oy_class_cards( $exclude );
	}
	$rows = '';
	$n    = 0;
	foreach ( oy_classes() as $c ) {
		if ( $c->post_name === $exclude ) {
			continue;
		}
		$tone  = OY_ACCENTS[ $n++ % 4 ];
		$thumb = oy_post_photo( $c->ID, '', '200px' );
		$meta  = '';
		foreach ( array( 'clock' => oy_class_meta( $c->ID, 'oy_duration' ), 'globe' => oy_online_only() ? '' : oy_class_meta( $c->ID, 'oy_lang' ) ) as $icon => $value ) {
			$meta .= $value ? '<span>' . oy_icon( $icon ) . esc_html( $value ) . '</span>' : '';
		}
		$rows .= '<li class="class-row class-row--' . $tone . '"><a href="' . esc_url( oy_class_url( $c->post_name ) ) . '"><span class="class-row__name">' . esc_html( get_the_title( $c ) ) . '</span>'
			. '<span class="class-row__desc">' . esc_html( get_the_excerpt( $c ) ) . '</span><span class="class-row__meta">' . $meta . '</span>'
			. '<span class="class-row__thumb" aria-hidden="true">' . $thumb . '</span><span class="class-row__go" aria-hidden="true">' . oy_icon( 'arrow' ) . '</span></a></li>';
	}
	return '<ul class="class-list">' . $rows . '</ul>';
}

/**
 * The online site's classes as cards: the photo on top, inside the card (Olívia, 14 November: „a kép sem jó, eltakarja a
 * szöveget és ez mobilon nem néz ki jól. legyen máshogy”), the card in the colour of its home page box.
 */
/**
 * A service's prices as cards – alone and as a pair, by length – straight from the booking products, so the page always
 * shows what the checkout charges. Olívia, 14 November: „Az összes szolgáltatásnál legyenek szebben kategorizálva az árak”.
 */
function oy_price_cards( $service ) {
	if ( '' === (string) $service || ! class_exists( 'OYS_Products' ) || ! function_exists( 'oys_money' ) ) {
		return '';
	}
	$groups = array();
	foreach ( OYS_Products::all( true ) as $p ) {
		if ( $p['service'] !== $service || ! in_array( $p['kind'], array( 'private_single', 'private_pack' ), true ) ) {
			continue;
		}
		$key = sprintf( '%d-%03d', (int) $p['people'], (int) $p['duration_min'] );
		$groups[ $key ][ 'private_single' === $p['kind'] ? 'single' : 'pack' ] = $p;
	}
	ksort( $groups );
	$out = '';
	foreach ( $groups as $g ) {
		$any  = $g['single'] ?? $g['pack'];
		$pair = 2 === (int) $any['people'];
		$len  = (int) $any['duration_min'];
		$rows = '';
		if ( ! empty( $g['single'] ) ) {
			$rows .= '<div><dt>' . esc_html__( '1 session', 'olivia-yoga' ) . '</dt><dd>' . esc_html( oys_money( (int) $g['single']['price_cents'] ) ) . '</dd></div>';
		}
		if ( ! empty( $g['pack'] ) ) {
			$n     = max( 1, (int) $g['pack']['credits'] );
			$rows .= '<div><dt>' . esc_html( sprintf( __( '%d sessions', 'olivia-yoga' ), $n ) ) . '<small>' . esc_html( sprintf( __( '%s per session', 'olivia-yoga' ), oys_money( (int) round( (int) $g['pack']['price_cents'] / $n ) ) ) ) . '</small></dt><dd>' . esc_html( oys_money( (int) $g['pack']['price_cents'] ) ) . '</dd></div>';
		}
		// Olívia, 15 November: „az ilyen árak is legyenek inkább lenyithatók”, then „a két egyedüli maradhat kinyitva, a párosok
		// maradjanak csak lenyithatóak és csak mobilon kell, asztalin látszódhat minden” – the cards alone are always open; the
		// pair cards fold under their head on a phone (site.js opens them on a wider screen)
		$from = (int) ( $g['single'] ?? $g['pack'] )['price_cents']; // one session, or the pass where there is no single
		$head = '<span class="oj-price__who">' . ( $pair ? esc_html__( 'As a pair', 'olivia-yoga' ) : esc_html__( 'Alone', 'olivia-yoga' ) ) . '</span>'
			. ( $len ? '<span class="oj-price__len">' . oy_icon( 'clock' ) . esc_html( sprintf( __( '%d minutes', 'olivia-yoga' ), $len ) ) . '</span>' : '' )
			. '<span class="oj-price__from">' . esc_html( sprintf( __( 'from %s', 'olivia-yoga' ), oys_money( $from ) ) ) . '</span>';
		$body = '<dl class="oj-price__rows">' . $rows . '</dl>' . ( $pair ? '<p class="oj-price__for">' . esc_html__( 'for the two of you', 'olivia-yoga' ) . '</p>' : '' );
		$out .= $pair
			? '<details class="oj-price oj-price--pair"><summary class="oj-price__head">' . $head . '<span class="oj-fold__icon" aria-hidden="true"></span></summary>' . $body . '</details>'
			: '<div class="oj-price oj-price--solo"><div class="oj-price__head">' . $head . '</div>' . $body . '</div>';
	}
	return '' === $out ? '' : '<div class="oj-prices">' . $out . '</div>';
}

function oy_class_cards( $exclude = '' ) {
	$tones = array( 'hatha-flow' => 'lilac', 'egyeni-online-joga' => 'pink', oy_prenatal_slug() => 'sage', 'stretching' => 'sun', 'relaxacio-legzes' => 'lilac' );
	$out   = '';
	$n     = 0;
	$all   = array_values( array_filter( oy_classes(), fn( $c ) => $c->post_name !== $exclude ) );
	// Olívia, 14 November: „nem jó az elrendezés, hogy az 5. egy új sorban van” – with five, the group class (the one that
	// is every week) opens the row wide, and the four one-to-one sessions sit in one row under it
	$lead  = 5 === count( $all );
	foreach ( $all as $c ) {
		$tone = oy_class_hue( $c->post_name ) ?: ( $tones[ $c->post_name ] ?? OY_ACCENTS[ $n % 4 ] );
		$wide = $lead && 0 === $n;
		$n++;
		$len  = oy_class_meta( $c->ID, 'oy_duration' );
		$out .= '<a class="oj-class-card oj-class-card--' . esc_attr( $tone ) . '" href="' . esc_url( oy_class_url( $c->post_name ) ) . '">'
			. '<span class="oj-class-card__photo">' . oy_post_photo( $c->ID, '', $wide ? '(min-width: 960px) 50vw, 92vw' : '(min-width: 960px) 30vw, 92vw' ) . '</span>'
			. '<span class="oj-class-card__body"><span class="oj-class-card__name">' . esc_html( get_the_title( $c ) ) . '</span>'
			. '<span class="oj-class-card__desc">' . esc_html( get_the_excerpt( $c ) ) . '</span>'
			. '<span class="oj-class-card__foot">' . ( $len ? '<span class="oj-class-card__len">' . oy_icon( 'clock' ) . esc_html( $len ) . '</span>' : '<span></span>' ) . '<span class="oj-class-card__go" aria-hidden="true">' . oy_icon( 'arrow' ) . '</span></span></span></a>';
	}
	return '<div class="oj-class-cards' . ( $lead ? ' oj-class-cards--lead' : '' ) . '">' . $out . '</div>';
}

function oy_faq_list( $home_only = false ) {
	$args = array( 'post_type' => 'oy_faq', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' );
	if ( $home_only ) {
		$args['meta_key']   = 'oy_home';
		$args['meta_value'] = '1';
	}
	// Olívia, 14 November: „gyiknél egyszerre csak 1 legyen megnyitva, a többi csukódjon vissza” – one name for the whole list
	// (the browser closes the others; site.js does it where the browser does not know this yet)
	$out = '<div class="faq">';
	foreach ( get_posts( $args ) as $i => $f ) {
		$out .= '<details name="oy-faq"' . ( 0 === $i ? ' open' : '' ) . '><summary><span>' . esc_html( get_the_title( $f ) ) . '</span>' . oy_icon( 'plus' ) . '</summary><div class="answer">' . oy_faq_tags( wpautop( wp_kses_post( oy_post_content( $f ) ) ) ) . '</div></details>';
	}
	return $out . '</div>';
}

/**
 * One answer of the FAQ, exactly as the FAQ page shows it (Olívia, 15 November, on the prices page: „az mire szükséges lesz
 * résznél pedig tegyük be pontosan ugyanazt, amit a gyiknél, külön szedve”) – read from the FAQ itself, so it stays the same.
 */
function oy_faq_answer( $q ) {
	$q = trim( (string) $q );
	if ( '' === $q ) {
		return '';
	}
	foreach ( get_posts( array( 'post_type' => 'oy_faq', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $f ) {
		if ( str_starts_with( get_the_title( $f ), $q ) ) {
			return '<div class="oj-faq-answer">' . oy_faq_tags( wpautop( wp_kses_post( oy_post_content( $f ) ) ) ) . '</div>';
		}
	}
	return '';
}

function oy_areas_list() {
	if ( 'fort-myers' !== oy_edition() ) {
		return '';
	}
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
		$out  .= '<li class="moment moment--' . OY_ACCENTS[ $i % 4 ] . '" data-reveal><figure class="moment__photo">' . oy_post_photo( $m->ID, '', '(min-width: 960px) 30vw, 92vw' ) . '</figure>'
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
		'notice' => __( 'Notice the sensations', 'olivia-yoga' ),
	);
	return '<div class="breath" data-breath data-words="' . esc_attr( wp_json_encode( $words ) ) . '">'
		. '<div class="breath__text">'
		. ( oy_online_only() ? '' : oy_kicker( __( 'Try it now · 30 seconds', 'olivia-yoga' ) ) ) // Olivia, 13 November: „ezt szedjük ki”
		. '<h2>' . esc_html__( 'Before you decide, take three breaths with me', 'olivia-yoga' ) . '</h2>'
		. '<p class="lead">' . esc_html__( 'A longer exhale is the quickest way to tell your nervous system it is safe to slow down. Follow the lotus: it opens as you breathe in and folds as you breathe out.', 'olivia-yoga' ) . '</p>'
		. '<ol class="breath__guide"><li><b>4</b><span>' . esc_html__( 'counts in, through the nose', 'olivia-yoga' ) . '</span></li><li><b>6</b><span>' . esc_html__( 'counts out, slow and soft', 'olivia-yoga' ) . '</span></li><li><b>3</b><span>' . sprintf( esc_html__( 'rounds, then %s', 'olivia-yoga' ), '<em class="breath__notice-word">' . esc_html( mb_strtolower( $words['notice'] ) ) . '</em>' ) . '</span></li></ol>'
		. '<button type="button" class="btn btn--light" data-breath-start>' . esc_html__( 'Start breathing', 'olivia-yoga' ) . '</button>'
		. '<p class="breath__status" aria-live="polite" data-breath-status></p></div>'
		. '<div class="breath__stage" aria-hidden="true"><div class="breath__glow"></div><div class="breath__lotus">' . $petals . '</div>'
		. '<svg class="breath__ring" viewBox="0 0 200 200"><circle class="breath__track" cx="100" cy="100" r="94"/><circle class="breath__progress" cx="100" cy="100" r="94" pathLength="100"/></svg>'
		. '<div class="breath__center"><span class="breath__phase" data-breath-phase>' . esc_html( $words['ready'] ) . '</span><b class="breath__count" data-breath-count>4·6</b></div>'
		. '<ol class="breath__dots"><li></li><li></li><li></li></ol></div>'
		. '</div>';
}

function oy_contact_topics() {
	if ( oy_is_demo() ) {
		return array(
			'group'   => __( 'Group class', 'olivia-yoga' ),
			'private' => __( 'Private session', 'olivia-yoga' ),
			'gift'    => __( 'Gift card', 'olivia-yoga' ),
			'other'   => __( 'Something else', 'olivia-yoga' ),
		);
	}
	if ( oy_online_only() ) {
		return array(
			'group'    => __( 'Live online class', 'olivia-yoga' ),
			'private'  => __( 'Private online session', 'olivia-yoga' ),
			'prenatal' => __( 'Prenatal fitness', 'olivia-yoga' ),
			'gift'     => __( 'Gift card', 'olivia-yoga' ),
			'other'    => __( 'Something else', 'olivia-yoga' ),
		);
	}
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
	return oy_callback_form() . '<form class="form form--contact" id="book" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" data-contact data-errors="' . esc_attr( wp_json_encode( $err ) ) . '">' . $notice
		. '<input type="hidden" name="action" value="oy_contact">' . wp_nonce_field( 'oy_contact', '_wpnonce', true, false )
		. '<input type="hidden" name="lang" value="' . esc_attr( oy_lang() ) . '">'
		. '<div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
		. '<fieldset class="topics"><legend>' . esc_html__( 'What would you like to talk about?', 'olivia-yoga' ) . '</legend><div class="topics__list">' . $chips . '</div></fieldset>'
		. '<div class="form__row"><label class="field"><span class="field__label">' . esc_html__( 'Your name', 'olivia-yoga' ) . '</span><input type="text" name="name" id="oy-c-name" required autocomplete="name"><span class="field__error" aria-live="polite"></span></label>'
		. '<label class="field"><span class="field__label">' . esc_html__( 'Email', 'olivia-yoga' ) . '</span><input type="email" name="email" id="oy-c-email" required autocomplete="email"><span class="field__error" aria-live="polite"></span></label></div>'
		. '<div class="form__row"><label class="field"><span class="field__label">' . esc_html__( 'Phone (optional)', 'olivia-yoga' ) . '</span><input type="tel" name="phone" id="oy-c-phone" autocomplete="tel"></label>'
		. ( 'fort-myers' !== oy_edition() ? '</div>' : '<label class="field"><span class="field__label">' . esc_html__( 'Your area (optional)', 'olivia-yoga' ) . '</span><input type="text" name="area" id="oy-c-area" placeholder="' . esc_attr__( 'McGregor, Cape Coral, Fort Myers Beach…', 'olivia-yoga' ) . '"></label></div>' )
		. '<label class="field"><span class="field__label">' . esc_html__( 'Message', 'olivia-yoga' ) . '</span><textarea name="message" id="oy-c-message" rows="5" required placeholder="' . esc_attr__( 'Tell me a little about your practice, any injuries or a pregnancy I should know about, and when you would like to start.', 'olivia-yoga' ) . '"></textarea><span class="field__error" aria-live="polite"></span></label>'
		. '<div class="form__foot"><button type="submit" class="btn btn--orchid">' . esc_html__( 'Send message', 'olivia-yoga' ) . oy_icon( 'arrow' ) . '</button>'
		. '<p class="form__small">' . esc_html__( 'I only use your details to reply to you.', 'olivia-yoga' ) . '</p></div></form>';
}

/**
 * Olívia, 14 November: „kapcsolatnál legyen simán egy visszahívást kérek gomb, ahova csak leírja a telefonszámát” – on
 * the online site, above the message form: one field for the phone number and a „Visszahívást kérek” button; the number
 * goes to Olívia by email.
 */
function oy_callback_form() {
	if ( ! oy_online_only() ) {
		return '';
	}
	$notice = '';
	if ( isset( $_GET['oy_called'] ) ) {
		$notice = '1' === $_GET['oy_called']
			? '<p class="form__notice form__notice--ok" role="status">' . esc_html__( 'Thank you, I will call you back soon.', 'olivia-yoga' ) . '</p>'
			: '<p class="form__notice oys-notice--error" role="alert">' . esc_html__( 'Please enter a phone number I can call.', 'olivia-yoga' ) . '</p>';
	}
	return '<form class="oj-callback" id="visszahivas" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">'
		. '<input type="hidden" name="action" value="oy_callback">' . wp_nonce_field( 'oy_callback', '_wpnonce', true, false )
		. '<div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
		. '<p class="oj-callback__text"><b>' . esc_html__( 'Would you rather talk?', 'olivia-yoga' ) . '</b> ' . esc_html__( 'Leave your phone number and I will call you back.', 'olivia-yoga' ) . '</p>'
		. '<div class="oj-callback__row"><label class="sr-only" for="oy-cb-phone">' . esc_html__( 'Your phone number', 'olivia-yoga' ) . '</label>'
		. '<input type="tel" name="phone" id="oy-cb-phone" required autocomplete="tel" inputmode="tel" placeholder="' . esc_attr__( 'Your phone number', 'olivia-yoga' ) . '">'
		. '<button type="submit" class="btn btn--orchid">' . esc_html__( 'Call me back', 'olivia-yoga' ) . '</button></div>' . $notice . '</form>';
}

add_action( 'admin_post_nopriv_oy_callback', 'oy_handle_callback' );
add_action( 'admin_post_oy_callback', 'oy_handle_callback' );
function oy_handle_callback() {
	check_admin_referer( 'oy_callback' );
	$back = preg_replace( '/#.*$/', '', remove_query_arg( 'oy_called', wp_get_referer() ?: oy_page_link( 'contact' ) ) );
	$done = fn( $ok ) => wp_safe_redirect( $ok && oy_thanks_url( 'visszahivas' ) ? oy_thanks_url( 'visszahivas' ) : add_query_arg( 'oy_called', $ok ? '1' : '0', $back ) . '#visszahivas' );
	if ( ! empty( $_POST['website'] ) ) {
		$done( true ); // honeypot: pretend success
		exit;
	}
	$phone  = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
	$digits = preg_replace( '/\D/', '', $phone );
	if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 || preg_match( '/[^\d\s+\-\/().]/', $phone ) ) {
		$done( false );
		exit;
	}
	$key = 'oy_cb_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	if ( ! get_transient( $key ) ) { // one call-back request a minute from the same address
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		$to = class_exists( 'OYS_Settings' ) ? OYS_Settings::get( 'notify_email' ) : get_option( 'admin_email' );
		/* translators: %s: phone number */
		wp_mail( $to, sprintf( __( 'Call-back request: %s', 'olivia-yoga' ), $phone ), sprintf( __( "Someone asked for a call back on the website.\n\nPhone number: %s", 'olivia-yoga' ), $phone ) );
	}
	$done( true );
	exit;
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
	$subject = 'Website message: ' . $name;
	if ( oy_online_only() ) { // Olívia reads it in Hungarian
		$topics  = oy_contact_topics();
		$topic   = sanitize_key( $_POST['topic'] ?? '' );
		$subject = 'Üzenet a weboldalról: ' . $name;
		$body    = sprintf( "Név: %s\nE-mail: %s\nTelefon: %s\nTéma: %s\n\n%s\n\n(Válaszolj erre a levélre, és az üzenet írójának megy.)", $name, $email, sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) ?: '–', $topics[ $topic ] ?? '–', $message );
	}
	$to = class_exists( 'OYS_Settings' ) ? OYS_Settings::get( 'notify_email' ) : get_option( 'admin_email' );
	wp_mail( $to, $subject, $body, array( 'Reply-To: ' . $name . ' <' . $email . '>' ) );
	wp_safe_redirect( oy_thanks_url( 'uzenet' ) ?: add_query_arg( 'oy_sent', '1', $back ) . '#book' );
	exit;
}

/**
 * Áron, 16 November: „a telefonszám megadásakor, mikor visszahívást kérek, nincs köszönő oldal” – on the online site a
 * request (a call back, a message) ends on its own thank-you page: what happens next, and where to look meanwhile. The
 * page (/koszonjuk/) is made once by the theme's setup, is not indexed, and fires a „generate_lead” event for analytics.
 */
function oy_thanks_url( $type ) {
	if ( ! oy_online_only() ) {
		return '';
	}
	$page = get_page_by_path( 'koszonjuk' );
	return $page && 'publish' === $page->post_status ? add_query_arg( 't', sanitize_key( $type ), get_permalink( $page ) ) : '';
}

function oy_thanks() {
	$t     = sanitize_key( $_GET['t'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	$texts = array(
		'visszahivas' => array( 'Megkaptam a telefonszámodat.', 'Hamarosan visszahívlak, általában egy napon belül. Ha addig kérdésed van, írj nyugodtan: ' ),
		'uzenet'      => array( 'Megkaptam az üzenetedet.', 'Hamarosan válaszolok e-mailben, általában egy napon belül. Ha nem találod a levelemet, nézd meg a spam mappát is, vagy írj ide: ' ),
	);
	$x     = $texts[ $t ] ?? array( 'Köszönöm, hogy írtál.', 'Hamarosan jelentkezem. Ha addig kérdésed van: ' );
	$fb    = function_exists( 'oys_fb_group_url' ) ? oys_fb_group_url() : '';
	$links = array(
		array( oy_page_link( 'yoga-classes' ), 'Online órák', 'csoportos flow jóga, egyéni jóga, kismamatorna, stretching, relaxáció' ),
		array( oy_page_link( 'schedule-pricing' ), 'Bejelentkezés és árak', 'órarend, bérletek, foglalás' ),
		array( oy_page_link( 'faq' ), 'Gyakori kérdések', 'hogyan működik egy élő online óra' ),
	);
	if ( $fb ) {
		$links[] = array( $fb, 'Facebook-csoportunk', 'beszélgetések, hírek az órákról' );
	}
	$list = '';
	foreach ( $links as $l ) {
		$list .= '<li><a href="' . esc_url( $l[0] ) . '"><b>' . esc_html( $l[1] ) . '</b></a> – ' . esc_html( $l[2] ) . '</li>';
	}
	$event = in_array( $t, array( 'visszahivas', 'uzenet' ), true ) ? '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push({event:"generate_lead",lead_type:' . wp_json_encode( $t ) . '});if(window.gtag){gtag("event","generate_lead",{lead_type:' . wp_json_encode( $t ) . '});}if(window.oyPixelLoaded&&window.fbq){fbq("track","Lead",{content_category:' . wp_json_encode( $t ) . '});}</script>' : '';
	return '<div class="oj-opening oj-opening--sun oj-thanks"><p class="oj-opening__tag">' . esc_html( $x[0] ) . '</p><p>' . esc_html( $x[1] ) . '<a href="mailto:' . esc_attr( OY_EMAIL ) . '">' . esc_html( OY_EMAIL ) . '</a>.</p></div>'
		. '<h2>Amíg vársz</h2><ul class="oj-thanks__links">' . $list . '</ul>'
		. '<p>' . oy_btn( home_url( '/' ), 'Vissza a főoldalra' ) . '</p>' . $event;
}

function oy_btn_row( $html ) {
	return '<p class="btn-row">' . $html . '</p>';
}

add_action( 'init', function () {
	$studio = fn( $sc, $fallback ) => shortcode_exists( $sc ) ? do_shortcode( '[' . $sc . ']' ) : $fallback;
	add_shortcode( 'oy_classes', fn() => '<div class="block-wide">' . oy_classes_list() . '</div>' );
	add_shortcode( 'oy_faq', fn() => oy_faq_list() );
	add_shortcode( 'oy_faq_answer', fn( $a ) => oy_faq_answer( $a['q'] ?? '' ) );
	add_shortcode( 'oy_art', fn( $a ) => function_exists( 'oy_way_art' ) ? oy_way_art( sanitize_key( $a['name'] ?? '' ) ) : '' );
	add_shortcode( 'oy_prices', fn( $a ) => oy_price_cards( sanitize_key( $a['service'] ?? '' ) ) );
	add_shortcode( 'oy_poses', fn( $a ) => oy_poses( sanitize_key( $a['set'] ?? '' ) ) );
	add_shortcode( 'oy_areas', fn() => oy_areas_list() );
	add_shortcode( 'oy_breath', fn() => oy_breath() );
	add_shortcode( 'oy_contact_form', fn() => oy_contact_form() );
	add_shortcode( 'oy_thanks', fn() => oy_thanks() );
	add_shortcode( 'oy_moments', fn() => '<div class="block-wide">' . oy_moments_grid() . '</div>' );
	add_shortcode( 'oy_schedule', fn() => '<div class="block-wide">' . $studio( 'oys_schedule', '<p>' . esc_html__( 'The timetable is coming soon.', 'olivia-yoga' ) . '</p>' ) . oy_replay_note() . oy_cancel_note() . '</div>' );
	add_shortcode( 'oy_pricing', fn() => '<div class="block-wide">' . $studio( 'oys_pricing', '' ) . '</div>' );
	add_shortcode( 'oy_events', fn() => '<div class="block-wide oy-events-dark">' . $studio( 'oys_events', '' ) . '</div>' );
	add_shortcode( 'oy_booking_link', fn( $a ) => oy_btn_row( oy_btn( oy_book_url(), oy_t( $a['label'] ?? '' ) ?: __( 'Book a class', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_private_link', fn( $a ) => oy_btn_row( oy_btn( oy_private_url(), oy_t( $a['label'] ?? '' ) ?: __( 'Book a private session', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_gift_link', fn( $a ) => oy_btn_row( oy_btn( function_exists( 'oys_page_url' ) ? oys_page_url( 'gifts' ) : oy_contact_url( 'gift' ), oy_t( $a['label'] ?? '' ) ?: __( 'Buy a gift card', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_button', fn( $a ) => oy_btn_row( oy_btn( oy_resolve_tokens( html_entity_decode( $a['url'] ?? '#' ) ), oy_t( $a['label'] ?? '' ) ?: __( 'Book', 'olivia-yoga' ) ) ) );
	add_shortcode( 'oy_fitmummy', fn() => oy_fitmummy() );
	// a photo in a page's text, by its key (the About page, Olívia 14 Nov: „törjük meg a folyószöveget”)
	add_shortcode( 'oy_figure', fn( $a ) => oy_figure( sanitize_key( $a['photo'] ?? '' ), (string) ( $a['class'] ?? '' ) ) );
	// two (or more) photos side by side, between paragraphs – nothing floats beside the text (Olívia, 14 November: „az
	// elején nagyon belelóg a szöveg a képbe, legyen inkább ez a 2 kép valahogy elosztva”)
	// photos side by side; class="oj-duo--row" makes a row of smaller ones (Olívia, 15 November, „A matracon túl”: „a szöveg
	// végére meg kisebben ezek a képek jöhetnek”)
	add_shortcode( 'oy_figures', function ( $a ) {
		$figs = array_filter( array_map( fn( $k ) => oy_figure( sanitize_key( $k ) ), array_filter( array_map( 'trim', explode( ',', (string) ( $a['photos'] ?? '' ) ) ) ) ) );
		$cls  = trim( 'oj-duo ' . implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) ( $a['class'] ?? '' ) ) ) ) ) );
		return '<div class="' . esc_attr( $cls . ' oj-duo--n' . count( $figs ) ) . '">' . implode( '', $figs ) . '</div>';
	} );
	add_shortcode( 'oy_picto', fn( $a ) => oy_pictogram( sanitize_key( $a['icon'] ?? 'lotus' ), sanitize_key( $a['tone'] ?? 'lilac' ) ) );
} );

/**
 * The poses of an article, as cards with Olívia's own photos (Olívia, 15 November, on „Pajzsmirigy harmonizálás jógával”:
 * „Ebből a cikkből hiányzik a lényeg, a kép … szerkessz egyet a weboldal stílusához illeszkedve”). Her photos had the
 * names written on them; that lettering is retouched out and the names are set in the site's own type under each photo.
 */
function oy_pose_sets() {
	return array(
		'tul'  => array(
			'title' => 'Ha túlműködik a pajzsmirigy',
			'lead'  => 'Nyugodt, lassú légzőgyakorlatok és gyakorlatsorok, nyugtató és stresszoldó ászanák.',
			'tone'  => 'lilac',
			'poses' => array(
				array( 'halpoz', 'Halpóz', 'Matszjászana' ),
				array( 'alacsony-kobra', 'Alacsony kobra póz', 'Budzsangászana' ),
				array( 'macskapoz', 'Macskapóz', 'Mardzsari' ),
				array( 'babapoz', 'Babapóz', 'Sasankászana' ),
				array( 'hullapoz', 'Hullapóz', 'Savászana' ),
				array( 'napudvozlet-lassan', 'Napüdvözlet, lassan', 'Szúrjanamaszkár' ),
			),
		),
		'alul' => array(
			'title' => 'Ha alulműködik a pajzsmirigy',
			'lead'  => 'Dinamikusabb, élénkítő, keringést fokozó ászanák és gyakorlatsorok, fordított testhelyzetek.',
			'tone'  => 'sun',
			'poses' => array(
				array( 'gyertyaallas', 'Gyertyaállás', 'Szarvangászana' ),
				array( 'ekepoz', 'Ekepóz', 'Halászana' ),
				array( 'fejenallas', 'Fejenállás', 'Sirsászana' ),
				array( 'kobra', 'Kobra póz', 'Budzsangászana' ),
				array( 'felfele-nezo-kutya', 'Felfelé néző kutya', 'Úrdhva-mukha-svanászana' ),
				array( 'kis-jogahid', 'Kis jógahíd', 'Kandarászana' ),
				array( 'tevepoz', 'Tevepóz', 'Ustrászana' ),
				array( 'napudvozlet-gyorsan', 'Napüdvözlet, gyorsabban', 'Szúrjanamaszkár' ),
			),
		),
	);
}

function oy_poses( $set ) {
	$sets = oy_pose_sets();
	if ( ! isset( $sets[ $set ] ) ) {
		return '';
	}
	$s    = $sets[ $set ];
	$base = get_template_directory_uri() . '/assets/img/poses/';
	$out  = '<section class="oj-poses oj-poses--' . esc_attr( $s['tone'] ) . '"><h2 class="oj-poses__title">' . esc_html( $s['title'] ) . '</h2><p class="oj-poses__lead">' . esc_html( $s['lead'] ) . '</p><ul class="oj-poses__list">';
	foreach ( $s['poses'] as $p ) {
		$alt  = sprintf( 'Kovács Olívia: %1$s (%2$s)', mb_strtolower( $p[1] ), $p[2] );
		$out .= '<li class="oj-pose"><img src="' . esc_url( $base . $p[0] . '-600.jpg' ) . '" srcset="' . esc_url( $base . $p[0] . '-600.jpg' ) . ' 600w, ' . esc_url( $base . $p[0] . '-900.jpg' ) . ' 900w" sizes="(min-width: 960px) 260px, (min-width: 600px) 45vw, 92vw" width="900" height="598" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async">'
			. '<span class="oj-pose__name">' . esc_html( $p[1] ) . '</span><span class="oj-pose__sanskrit">' . esc_html( $p[2] ) . '</span></li>';
	}
	return $out . '</ul></section>';
}

/** The Fitmummy prenatal & postnatal trainer mark, as a small credential card. */
function oy_fitmummy( $cls = '' ) {
	return '<figure class="credential ' . esc_attr( $cls ) . '"><img src="' . esc_url( get_template_directory_uri() . '/assets/img/fitmummy.svg' ) . '" alt="Fitmummy" width="220" height="68" loading="lazy">'
		. '<figcaption>' . esc_html__( 'Prenatal & postnatal fitness training: Fitmummy method', 'olivia-yoga' ) . '</figcaption></figure>';
}

/**
 * Olívia, 16 November: „ez a szekció az online órák alatt is legyen szebben kiemelve és legyen cta, hogy időpontot
 * kérhetsz” – on the Online órák page the „Egyéni online órák” part (its heading up to the next one) becomes a box in
 * the one-to-one colour, with „Időpontot kérek” and the way to the one-to-one page, like on the home page. Her text
 * stays as she wrote it (the box is put round it while the page is shown).
 */
function oy_private_callout( $html ) {
	return oy_online_only() && is_page( oy_page_slug( 'yoga-classes' ) ) ? oy_private_callout_html( $html ) : $html;
}

function oy_private_callout_html( $html ) {
	if ( str_contains( $html, 'oj-callout' ) ) {
		return $html;
	}
	return preg_replace_callback( '~(<h2[^>]*\bid="egyeni-orak"[^>]*>.*?</h2>)(.*?)(?=<h2\b|$)~su', function ( $m ) {
		$cta = '<p class="btn-row oj-callout__cta">' . oy_btn( add_query_arg( 'topic', 'egyeni-joga', oy_private_url() ), 'Időpontot kérek', 'orchid' )
			. '<a class="text-link" href="' . esc_url( oy_class_url( 'egyeni-online-joga' ) ) . '">Bővebben az egyéni óráról</a></p>';
		$body = preg_replace( '~<ul>~', '<ul class="oj-callout__list">', trim( $m[2] ), 1 ); // its own dots, not the page's list waves (Olívia, 16 November: „itt még nem néznek ki jól a pöttyök”)
		return '<section class="oj-callout oj-callout--one" aria-labelledby="egyeni-orak">' . $m[1] . $body . $cta . '</section>' . "\n";
	}, $html, 1 );
}
add_filter( 'the_content', 'oy_private_callout', 20 );

// Shortcodes on their own line come wrapped in <p>; unwrap them so blocks aren't nested in paragraphs.
add_filter( 'the_content', function ( $content ) {
	return preg_replace( '#<p>\s*(\[(?:oy|oys)_[^\]]+\])\s*</p>#', '$1', $content );
}, 9 );
