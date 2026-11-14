<?php
/**
 * Hungarian line breaks on the online site. Olívia, 2026-11-14: „Figyelj a szövegtördelésre mindenhol” (she moved
 * „Kezdőket is szeretettel várok.” to its own line herself). The browser decides where a line ends; this keeps together
 * what a Hungarian reader expects to stay together: a short word (a, az, s, és, egy …) with the word after it, „is” with
 * the word before it, a number with its unit (8 000 Ft, 90 perc) and the groups of a number, and a dash with the word
 * before it (no line starts with „–”). The CSS (online.css) adds text-wrap: pretty / balance.
 *
 * Only the page's text between tags changes (scripts, styles, form fields and code are skipped), after the page editor has
 * done its work (its keys are the original texts), and not while someone is editing the page.
 */

defined( 'ABSPATH' ) || exit;

const OY_TYPO_BEFORE = 'a|az|s|és|e|egy|ha|de|ne';
const OY_TYPO_UNITS  = 'Ft|forint|perc|perces|percet|alkalom|alkalmas|alkalomra|alkalmat|óra|órás|órát|hét|hétig|hete|nap|napig|év|éve|évig|fő|főre|%';

/** One piece of text: the no-break spaces. */
function oy_typo_text( $t ) {
	$nb = "\u{00A0}";
	$t  = (string) preg_replace( '/(?<![\p{L}\p{N}])(' . OY_TYPO_BEFORE . ')[ \t\r\n]+(?=[\p{L}\p{N}„(])/iu', '$1' . $nb, $t );
	$t  = (string) preg_replace( '/(?<=[\p{L}\p{N}.,!?…”)])[ \t\r\n]+(is)(?![\p{L}\p{N}])/iu', $nb . '$1', $t );
	$t  = (string) preg_replace( '/(?<=\d)[ \t](?=\d{3}(?!\d))/u', $nb, $t );
	$t  = (string) preg_replace( '/(?<=\d)[ \t\r\n]+(?=(?:' . OY_TYPO_UNITS . ')(?![\p{L}]))/iu', $nb, $t );
	return (string) preg_replace( '/(?<=[\p{L}\p{N}.,!?…”)])[ \t\r\n]+([–—])(?=\s)/u', $nb . '$1', $t );
}

/** A whole page: only the text between the tags of <body>, nothing inside script, style, textarea, pre, code or svg. */
function oy_typo_html( $html ) {
	$at = stripos( $html, '<body' );
	if ( false === $at ) {
		return $html;
	}
	$head  = substr( $html, 0, $at );
	$parts = preg_split( '/(<!--.*?-->|<(script|style|textarea|pre|code|svg)\b.*?<\/\2\s*>|<[^>]+>)/is', substr( $html, $at ), -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $html;
	}
	$out  = '';
	$skip = false;
	foreach ( $parts as $i => $p ) {
		if ( $skip ) { // the tag name the split captured after each delimiter
			$skip = false;
			continue;
		}
		if ( '' === $p ) {
			continue;
		}
		if ( '<' === $p[0] ) {
			$out .= $p;
			$skip = (bool) preg_match( '/^<(script|style|textarea|pre|code|svg)\b/i', $p );
			continue;
		}
		$out .= oy_typo_text( $p );
	}
	return $head . $out;
}

add_action( 'template_redirect', function () {
	if ( ! oy_online_only() || is_admin() || is_feed() || is_robots() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( function_exists( 'oye_editing' ) && oye_editing() ) {
		return;
	}
	// before the page editor's buffer (priority 1000): output buffers run inside out, so this one gets the page after it
	ob_start( 'oy_typo_html' );
}, 900 );
