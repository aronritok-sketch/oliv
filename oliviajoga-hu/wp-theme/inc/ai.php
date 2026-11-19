<?php
/**
 * Hogy a nyelvi modellek (ChatGPT, Claude, Gemini, Perplexity, Copilot) ismerjék és ajánlják Olíviát (Áron, 2026-11-18:
 * „az AI-ok szeretném, hogy ajánlják Oliviát … ugyanazt, mint a HelloProVision-nél”).
 *
 * Az alap (robots.txt, /llms.txt, /llms-full.txt, .md tükrök, IndexNow) az iu_theme-é (inc/llms.php). Ez a fájl csak a
 * saját anyagot adja:
 * - /llms.txt: a build.py kézzel válogatott szövege (data/site.json „llms”, november 26-tól az „after” változat): ki
 *   Olívia, a tények (végzettség, órák, időpont, kapcsolat), az oldalak a saját leírásukkal, a cikkek egyszer-egyszer,
 *   az online oldal órái. Csak olyan tény, ami a két oldalon szerepel.
 * - /llms-full.txt: az oldalak szövege a morzsamenü nélkül (az összefolyt „FőoldalJógáról…” helyett).
 * - A strukturált adat (Person, Organization, szolgáltatások, BreadcrumbList) a build.py schema_base-éből jön.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'iu_llms_txt', function ( $txt ) {
	$d = oj_data();
	$t = oj_after() && ! empty( $d['after']['llms'] ) ? $d['after']['llms'] : ( $d['llms'] ?? '' );
	return '' !== trim( (string) $t ) ? oj_fill( $t ) : $txt;
} );

add_filter( 'iu_llms_page_html', function ( $html, $post ) {
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return $html;
	}
	$body = (string) $post->post_content;
	$path = rawurldecode( (string) get_page_uri( $post ) );
	$after = oj_data()['after']['pages'][ $path ] ?? null;
	$md5   = (array) get_option( 'oj_setup_md5', array() );
	if ( oj_after() && $after && ( ! isset( $md5[ $path ] ) || md5( $body ) === $md5[ $path ] ) ) {
		$body = $after['content']; // november 26-tól az új változat, ahogy az oldalon is
	}
	$body = preg_replace( '~<nav class="crumbs".*?</nav>~s', '', $body );
	$body = preg_replace( '/<!-- \/?wp:html -->\s*/', '', (string) $body );
	return oj_fill( $body );
}, 10, 2 );

/*
 * IndexNow önjavítás (2026-11-19). A Bing (és vele a ChatGPT-keresés, a Copilot) az iu_theme beküldését 403-mal
 * („UserForbiddedToAccessSite”) utasította el, pedig a kulcsfájl jó: a domain addig a Google Sites-ra mutatott, és a
 * Bing a sikertelen kulcs-ellenőrzést megjegyezte. Új kulcs → új ellenőrzés. Ha az utolsó beküldés 403 volt, új kulcs és
 * újraküldés; naponta legfeljebb egyszer, összesen 3 kulcscsere (utána csak naponta újrapróbál). Kézzel: POST oj/v1/indexnow.
 */
add_action( 'oj_indexnow_heal', 'oj_indexnow_heal' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'oj_indexnow_heal' ) ) {
		wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'daily', 'oj_indexnow_heal' ); // a verzióváltás utáni beküldés után
	}
} );

function oj_indexnow_heal() {
	if ( ! class_exists( '\IUTheme\Llms' ) || ! \IUTheme\Llms::live() ) {
		return oj_indexnow_status();
	}
	$code = (int) ( get_option( 'iu_indexnow_last', array() )['http'] ?? 0 );
	if ( 200 === $code || 202 === $code ) {
		return oj_indexnow_status();
	}
	$n = (int) get_option( 'oj_indexnow_rotations', 0 );
	if ( 403 === $code && $n < 3 ) {
		delete_option( 'iu_indexnow_key' ); // Llms::key() újat készít; a kulcsfájl (/<kulcs>.txt) az iu_theme-é, azonnal él
		update_option( 'oj_indexnow_rotations', $n + 1, false );
	}
	\IUTheme\Llms::indexnow_all();
	return oj_indexnow_status();
}

function oj_indexnow_status() {
	$on = class_exists( '\IUTheme\Llms' );
	return array(
		'live'      => $on && \IUTheme\Llms::live(),
		'key'       => $on ? home_url( '/' . \IUTheme\Llms::key() . '.txt' ) : '',
		'last'      => get_option( 'iu_indexnow_last' ),
		'rotations' => (int) get_option( 'oj_indexnow_rotations', 0 ),
		'next'      => wp_next_scheduled( 'oj_indexnow_heal' ),
	);
}
