<?php
/**
 * Content in three languages.
 *
 * The Olivia Studio plugin decides the language from the address (/hu/…, /es/…, English without a
 * prefix) and switches the locale, so every text in the templates comes from languages/*.mo. This
 * file adds the content: each page, class, FAQ, journal post and past event can carry a Hungarian
 * and a Spanish version of its title, summary, text and SEO fields (post meta `oy_{lang}_{field}`,
 * edited in the "Translations" box). Where a translation is missing, the English original shows.
 */

defined( 'ABSPATH' ) || exit;

const OY_TR_FIELDS = array( 'title', 'excerpt', 'content', 'seo_title', 'seo_desc', 'duration', 'lang', 'place' );

function oy_lang() {
	return function_exists( 'oys_lang' ) ? oys_lang() : 'en';
}

function oy_languages() {
	return function_exists( 'oys_languages' ) ? oys_languages() : array( 'en' => array( 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'short' => 'EN', 'html' => 'en-US' ) );
}

/** Translate a text that is stored as data (alt texts, class lengths…) through the theme's .mo file. */
function oy_t( $text ) {
	$text = (string) $text;
	if ( '' === $text || 'en' === oy_lang() ) {
		return $text;
	}
	return translate( $text, 'olivia-yoga' ); // phpcs:ignore WordPress.WP.I18n
}

function oy_tr_key( $field, $lang ) {
	return 'oy_' . $lang . '_' . $field;
}

/** A translated field of a post in the current language ('' when English or not translated). */
function oy_tr( $post_id, $field, $lang = null ) {
	$lang = $lang ?? oy_lang();
	if ( 'en' === $lang || ! $post_id ) {
		return '';
	}
	return (string) get_post_meta( (int) $post_id, oy_tr_key( $field, $lang ), true );
}

/** Raw post content in the current language. */
function oy_post_content( $post ) {
	$post = get_post( $post );
	return $post ? ( oy_tr( $post->ID, 'content' ) ?: $post->post_content ) : '';
}

/** Is a Hungarian or Spanish visitor looking at the public site? */
function oy_translating() {
	return 'en' !== oy_lang() && ! ( is_admin() && ! wp_doing_ajax() ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_GET['context'] ) && 'edit' === $_GET['context'] ); // phpcs:ignore
}

add_filter( 'the_title', function ( $title, $post_id = 0 ) {
	return oy_translating() ? ( oy_tr( $post_id, 'title' ) ?: $title ) : $title;
}, 5, 2 );

add_filter( 'single_post_title', function ( $title, $post ) {
	return oy_translating() && $post ? ( oy_tr( $post->ID, 'title' ) ?: $title ) : $title;
}, 5, 2 );

add_filter( 'get_the_excerpt', function ( $excerpt, $post ) {
	if ( ! oy_translating() || ! $post ) {
		return $excerpt;
	}
	$own = oy_tr( $post->ID, 'excerpt' );
	if ( $own ) {
		return $own;
	}
	$text = oy_tr( $post->ID, 'content' );
	return $text ? wp_trim_words( wp_strip_all_tags( strip_shortcodes( $text ) ), 28, '…' ) : $excerpt;
}, 5, 2 );

add_filter( 'the_content', function ( $content ) {
	if ( ! oy_translating() ) {
		return $content;
	}
	$id = get_the_ID();
	return $id ? ( oy_tr( $id, 'content' ) ?: $content ) : $content;
}, 0 );

// Menu labels: entries that use the page's own title follow its translation; others go through the .mo file.
add_filter( 'wp_nav_menu_objects', function ( $items ) {
	if ( ! oy_translating() ) {
		return $items;
	}
	foreach ( $items as $item ) {
		$original = 'post_type' === $item->type ? get_post_field( 'post_title', (int) $item->object_id ) : '';
		if ( $original && ( '' === (string) $item->post_title || $item->post_title === $original ) ) {
			$item->title = oy_tr( (int) $item->object_id, 'title' ) ?: oy_t( $original );
		} else {
			$item->title = oy_t( $item->title );
		}
	}
	return $items;
} );

/** EN · HU · ES links to this page in the other languages. */
function oy_lang_switcher( $cls = 'langs' ) {
	$langs = oy_languages();
	if ( count( $langs ) < 2 || ! function_exists( 'oys_url_in' ) ) {
		return '';
	}
	$out = '';
	foreach ( $langs as $code => $l ) {
		$here = $code === oy_lang();
		$out .= '<li><a href="' . esc_url( oys_url_in( $code ) ) . '" hreflang="' . esc_attr( $l['html'] ) . '" lang="' . esc_attr( $l['html'] ) . '"' . ( $here ? ' aria-current="true"' : '' ) . ' title="' . esc_attr( $l['native'] ) . '">' . esc_html( $l['short'] ) . '</a></li>';
	}
	return '<nav class="' . esc_attr( $cls ) . '" aria-label="' . esc_attr__( 'Language', 'olivia-yoga' ) . '"><ul>' . $out . '</ul></nav>';
}

// Search engines: the same page in each language.
add_action( 'wp_head', function () {
	if ( ! function_exists( 'oys_url_in' ) || is_404() || is_search() ) {
		return;
	}
	foreach ( oy_languages() as $code => $l ) {
		echo '<link rel="alternate" hreflang="' . esc_attr( $l['html'] ) . '" href="' . esc_url( oys_url_in( $code ) ) . '">' . "\n";
	}
	echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( oys_url_in( 'en' ) ) . '">' . "\n";
}, 2 );

/** Links inside translated copy point to the same page in the visitor's language. */
function oy_localize_links( $html ) {
	if ( ! oy_translating() || ! class_exists( 'OYS_I18n' ) ) {
		return $html;
	}
	$home = untrailingslashit( (string) get_option( 'home' ) );
	$lang = oy_lang();
	return preg_replace_callback( '#href=(["\'])(' . preg_quote( $home, '#' ) . ')(/[^"\']*)?\1#', function ( $m ) use ( $lang ) {
		$path = $m[3] ?? '/';
		if ( preg_match( '#^/(wp-|hu/|es/|hu$|es$)#', $path ) || preg_match( '#\.(jpe?g|png|webp|gif|svg|pdf|ics)(\?|$)#i', $path ) ) {
			return $m[0];
		}
		return 'href=' . $m[1] . OYS_I18n::prefix( $m[2] . $path, $lang ) . $m[1];
	}, $html );
}
add_filter( 'the_content', 'oy_localize_links', 99 );

// Category names: Hungarian and Spanish names are stored as term meta.
add_filter( 'get_term', function ( $term ) {
	if ( $term instanceof WP_Term && 'category' === $term->taxonomy && oy_translating() ) {
		$name = (string) get_term_meta( $term->term_id, 'oy_' . oy_lang() . '_name', true );
		if ( '' !== $name ) {
			$term->name = $name;
		}
	}
	return $term;
} );
