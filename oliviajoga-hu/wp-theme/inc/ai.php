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
