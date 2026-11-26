<?php
/**
 * Szerkesztés a weboldalon – Olivia oldalain (oliviajoga.online és oliviajoga.hu). Áron, 2026-11-13: „a főnökasszony kér
 * egy olyan felületet is, ahol tudja az oldalakat szerkeszteni … legyen egy ilyen vizuális szerkesztő”. A Kassai-témák
 * és a Védőburok (0.13.0) szerkesztőjének mintájára (hlprv: vedoburok/wp-theme/vedoburok/inc/edit.php).
 *
 * Belépve „✎ Oldal szerkesztése” (az admin sávban, a lap sarkában, vagy ?szerkesztes=1): a címekre, bekezdésekre,
 * listákra kattintva helyben írható (félkövér, dőlt, sortörés), a képre kattintva a Médiatárból cserélhető, a kivágás
 * pontja állítható, „Eredeti” visszaállít. Mentés: alul a sáv vagy Ctrl+S. Admin: Megjelenés → Szövegek és képek (a
 * megváltoztatott elemek listája, egyenként vissza, előző mentés vissza).
 *
 * Működés: a kész oldal <main> részén egy DOM-lépés megjelöli a szerkeszthető elemeket, és ráírja a mentett eltérést.
 * A szöveg kulcsa az eredeti szöveg rövid hash-e – ugyanaz a mondat mindenhol ugyanúgy változik (pl. egy GYIK-válasz a
 * főoldalon és a GYIK oldalon). A kép kulcsa a fotó neve (data-oyp, vagy a fájlnévből). Csak az eltérés tárolódik
 * (oye_edits), az előző mentés az oye_edits_prev-ben, hogy mit hol írtak át: oye_edits_meta. Ami adat (órarend, árak,
 * foglalás, bérletek, űrlapok), az nem itt, hanem a Stúdió menüben szerkeszthető; a linkek és gombok szövege sem itt.
 *
 * Mindkét téma ugyanezt a fájlt használja (az oliviajoga-hu/build.py --wp másolja át); a téma a betöltés előtt
 * megadhatja: OYE_URL (az assets/edit.* helye), OYE_VERSION.
 */

defined( 'ABSPATH' ) || exit;

const OYE_CAP    = 'edit_others_pages';
const OYE_PARAM  = 'szerkesztes';
const OYE_TAGS   = array( 'h1', 'h2', 'h3', 'h4', 'p', 'li', 'figcaption', 'summary', 'dt', 'dd', 'blockquote', 'span', 'mark' );
const OYE_INLINE = array( 'br', 'b', 'strong', 'em', 'i', 'small', 'sup', 'sub', 'u' );
/** Ezeken belül nincs helyben szerkesztés (a class eleje): a foglaló bővítmény, árak, órarend, légzőgyakorlat, űrlapok. */
const OYE_SKIP_PREFIX = array( 'oys', 'price', 'session', 'breath', 'oj-pay', 'wpcf7', 'comment' );

function oye_enabled() {
	return (bool) apply_filters( 'oye_enabled', true );
}

function oye_edits() {
	$o = get_option( 'oye_edits', array() );
	$o = is_array( $o ) ? $o : array();
	return $o + array( 't' => array(), 'i' => array() );
}

function oye_editing() {
	static $on = null;
	if ( null === $on ) {
		$on = oye_enabled() && ! is_admin() && isset( $_GET[ OYE_PARAM ] ) && current_user_can( OYE_CAP ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	return $on;
}

/** A lapon talált elemek: kulcs → alapérték (a szerkesztő JS-nek). */
function &oye_registry() {
	static $r = array( 't' => array(), 'i' => array() );
	return $r;
}

function oye_kses( $h ) {
	$h = wp_kses( (string) $h, array_fill_keys( OYE_INLINE, array() ) );
	return trim( (string) preg_replace( '/(<br\s*\/?>\s*)+$/i', '', str_replace( "\xC2\xA0", ' ', $h ) ) );
}

function oye_text_key( $def ) {
	return 't' . substr( md5( oye_kses( $def ) ), 0, 10 );
}

/** A kép kulcsa: data-oyp, vagy a fájlnév méret és kiterjesztés nélkül (…/new/hero-1600.jpg → hero). */
function oye_image_key( DOMElement $img ) {
	$k = $img->getAttribute( 'data-oyp' );
	if ( '' === $k ) {
		$src = (string) strtok( $img->getAttribute( 'src' ), '?' );
		$k   = (string) preg_replace( array( '/\.(jpe?g|png|webp|gif|avif)$/i', '/-(\d{2,4}x\d{2,4}|\d{3,4}|scaled)$/i' ), '', rawurldecode( basename( $src ) ) );
	}
	$k = strtolower( (string) preg_replace( '/[^a-z0-9_-]+/i', '-', $k ) );
	return '' === trim( $k, '-' ) ? '' : 'i:' . trim( $k, '-' );
}

function oye_inner( DOMNode $n ) {
	$h = '';
	foreach ( $n->childNodes as $c ) {
		$h .= $n->ownerDocument->saveHTML( $c );
	}
	return $h;
}

/** Csak szöveg és a megengedett formázás van benne? */
function oye_is_leaf( DOMNode $n ) {
	foreach ( $n->childNodes as $c ) {
		if ( $c instanceof DOMElement ) {
			if ( ! in_array( strtolower( $c->tagName ), OYE_INLINE, true ) || ! oye_is_leaf( $c ) ) {
				return false;
			}
		} elseif ( ! $c instanceof DOMText ) {
			return false;
		}
	}
	return true;
}

function oye_skipped( DOMElement $el ) {
	// a title or paragraph inside a card that is one big link (the service boxes, article cards) is text, not a link label
	$block = in_array( strtolower( $el->tagName ), array( 'h1', 'h2', 'h3', 'h4', 'p' ), true );
	for ( $p = $el; $p instanceof DOMElement; $p = $p->parentNode ) {
		$tag = strtolower( $p->tagName );
		if ( 'a' === $tag && $block && $p !== $el ) {
			continue;
		}
		if ( in_array( $tag, array( 'a', 'button', 'nav', 'form', 'dialog', 'label', 'select', 'svg', 'script', 'style', 'template', 'noscript', 'table' ), true ) || $p->hasAttribute( 'data-noedit' ) ) {
			return true;
		}
		foreach ( preg_split( '/\s+/', trim( $p->getAttribute( 'class' ) ) ) as $cls ) {
			foreach ( OYE_SKIP_PREFIX as $pre ) {
				if ( '' !== $cls && 0 === strpos( $cls, $pre ) ) {
					return true;
				}
			}
		}
	}
	return false;
}

/** span / mark csak egy cím részeként (pl. a hős „Online” / „jóga” / „élőben, otthonról” sora). */
function oye_in_heading( DOMElement $el ) {
	for ( $p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode ) {
		if ( in_array( strtolower( $p->tagName ), array( 'h1', 'h2', 'h3' ), true ) ) {
			return true;
		}
	}
	return false;
}

/** A <main> HTML-jének átírása: a mentett szövegek és képek, szerkesztés módban a jelölők. */
function oye_pass( $html ) {
	$E       = oye_edits();
	$editing = oye_editing();
	if ( ! $editing && ! $E['t'] && ! $E['i'] ) {
		return $html;
	}
	$doc  = new DOMDocument( '1.0', 'UTF-8' );
	$prev = libxml_use_internal_errors( true );
	$ok   = $doc->loadHTML( '<?xml encoding="utf-8"?><div id="oye-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$root = $ok ? $doc->getElementById( 'oye-root' ) : null;
	if ( ! $root ) {
		return $html;
	}
	$reg     = &oye_registry();
	$xp      = new DOMXPath( $doc );
	$changed = false;
	foreach ( iterator_to_array( $xp->query( './/' . implode( ' | .//', OYE_TAGS ), $root ) ) as $el ) {
		$tag = strtolower( $el->tagName );
		if ( ( 'span' === $tag || 'mark' === $tag ) && ! oye_in_heading( $el ) ) {
			continue;
		}
		if ( ! oye_is_leaf( $el ) || oye_skipped( $el ) ) {
			continue;
		}
		$def   = trim( oye_inner( $el ) );
		$plain = trim( html_entity_decode( wp_strip_all_tags( $def ), ENT_QUOTES, 'UTF-8' ) );
		if ( mb_strlen( $plain ) < 2 ) {
			continue;
		}
		$key = oye_text_key( $def );
		$cur = $def;
		if ( isset( $E['t'][ $key ] ) && is_string( $E['t'][ $key ] ) && '' !== $E['t'][ $key ] ) {
			$cur = $E['t'][ $key ];
			while ( $el->firstChild ) {
				$el->removeChild( $el->firstChild );
			}
			$frag = new DOMDocument( '1.0', 'UTF-8' );
			libxml_use_internal_errors( true );
			$frag->loadHTML( '<?xml encoding="utf-8"?><div>' . oye_kses( $cur ) . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
			libxml_clear_errors();
			foreach ( iterator_to_array( $frag->documentElement->childNodes ) as $c ) {
				$el->appendChild( $doc->importNode( $c, true ) );
			}
			$changed = true;
		}
		if ( $editing ) {
			$el->setAttribute( 'data-ke', $key );
			$reg['t'][ $key ] = array( 'def' => oye_kses( $def ), 'cur' => oye_kses( $cur ), 'tag' => $tag );
		}
	}
	foreach ( iterator_to_array( $xp->query( './/img', $root ) ) as $img ) {
		if ( oye_skipped( $img ) ) {
			continue;
		}
		$key = oye_image_key( $img );
		if ( '' === $key ) {
			continue;
		}
		$def = $img->getAttribute( 'src' );
		$alt = $img->getAttribute( 'alt' ); // the slot's own text: a web copy of a new photo is named after it
		$e   = $E['i'][ $key ] ?? null;
		if ( $e ) {
			$id = (int) ( $e['id'] ?? 0 );
			$u  = $id ? wp_get_attachment_image_url( $id, 'large' ) : '';
			if ( $u ) {
				$img->setAttribute( 'src', $u );
				// the browser picks the size it needs (a phone does not load the desktop picture)
				$set = wp_get_attachment_image_srcset( $id, 'large' );
				if ( $set && '' !== $img->getAttribute( 'sizes' ) ) {
					$img->setAttribute( 'srcset', $set );
				} else {
					$img->removeAttribute( 'srcset' );
					$img->removeAttribute( 'sizes' );
				}
				$meta = wp_get_attachment_metadata( $id );
				if ( ! empty( $meta['width'] ) ) {
					$img->setAttribute( 'width', (string) (int) $meta['width'] );
					$img->setAttribute( 'height', (string) (int) $meta['height'] );
				}
				$own = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
				// Áron, 26 November (the alt describes the photo and has the keywords): a thinner text whose every word is in the
				// place's own description (the same photo, described less) leaves that one
				if ( '' !== $own && ! oye_thinner( $own, $alt ) ) {
					$img->setAttribute( 'alt', $own );
				}
				if ( $img->parentNode instanceof DOMElement && 'picture' === strtolower( $img->parentNode->tagName ) ) {
					foreach ( iterator_to_array( $img->parentNode->getElementsByTagName( 'source' ) ) as $s ) {
						$s->parentNode->removeChild( $s );
					}
				}
			}
			if ( isset( $e['fx'], $e['fy'] ) ) {
				$style = (string) preg_replace( '/object-position\s*:[^;]*;?/i', '', $img->getAttribute( 'style' ) );
				$img->setAttribute( 'style', trim( $style . ';object-position:' . (float) $e['fx'] . '% ' . (float) $e['fy'] . '%', '; ' ) );
			}
			$changed = true;
		}
		if ( $editing ) {
			$img->setAttribute( 'data-ki', $key );
			$reg['i'][ $key ] = array( 'def' => $def, 'url' => $img->getAttribute( 'src' ), 'id' => (int) ( $e['id'] ?? 0 ), 'fx' => $e['fx'] ?? null, 'fy' => $e['fy'] ?? null, 'alt' => $alt );
		}
	}
	return ( $changed || $editing ) ? oye_inner( $root ) : $html;
}

/** A teljes oldal: csak a <main> rész megy át a DOM-lépésen (a fejléc, a lábléc, a szkriptek érintetlenek). */
function oye_filter_page( $html ) {
	$a = stripos( $html, '<main' );
	$b = strripos( $html, '</main>' );
	if ( false === $a || false === $b || $b < $a ) {
		return $html;
	}
	$open = strpos( $html, '>', $a );
	if ( false === $open || $open > $b ) {
		return $html;
	}
	$inner = substr( $html, $open + 1, $b - $open - 1 );
	$out   = substr( $html, 0, $open + 1 ) . oye_pass( $inner ) . substr( $html, $b );
	if ( oye_editing() ) {
		$reg  = oye_registry();
		$cfg  = array(
			'rest'  => esc_url_raw( rest_url( 'oye/v1/edit' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			't'     => (object) $reg['t'],
			'i'     => (object) $reg['i'],
			'admin' => admin_url( 'themes.php?page=oye-szovegek' ),
			'exit'  => remove_query_arg( OYE_PARAM ),
			'post'  => is_singular() ? (string) get_edit_post_link( get_queried_object_id(), 'raw' ) : '',
			'where' => array( 'url' => remove_query_arg( OYE_PARAM ), 'title' => is_front_page() ? 'Főoldal' : ( is_singular() ? get_the_title( get_queried_object_id() ) : wp_get_document_title() ) ),
		);
		$js   = '<script>window.OYEDIT = ' . wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . ';</script>' . "\n";
		// before the editor's own script (it reads the config when it runs), else before </body>
		$at = false;
		if ( preg_match( '/<script[^>]*id=["\']oye-edit-js["\'][^>]*>/i', $out, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1];
		}
		$at  = false === $at ? strripos( $out, '</body>' ) : $at;
		$out = false === $at ? $out . $js : substr( $out, 0, $at ) . $js . substr( $out, $at );
	}
	return $out;
}

add_action( 'template_redirect', function () {
	if ( ! oye_enabled() || is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$E = oye_edits();
	if ( oye_editing() || $E['t'] || $E['i'] ) {
		if ( oye_editing() ) {
			nocache_headers();
		}
		ob_start( 'oye_filter_page' );
	}
}, 1000 );

/** Mentés: $t = [kulcs => html] (üres = eredeti), $i = [kulcs => ['id', 'fx', 'fy']], $reset = visszaállítandó képek. */
function oye_apply( array $t, array $i, array $reset = array(), array $where = array() ) {
	$o = oye_edits();
	update_option( 'oye_edits_prev', $o, false );
	$meta = get_option( 'oye_edits_meta', array() );
	$meta = is_array( $meta ) ? $meta : array();
	$who  = wp_get_current_user()->display_name;
	$at   = array( 'url' => esc_url_raw( (string) ( $where['url'] ?? '' ) ), 'title' => sanitize_text_field( (string) ( $where['title'] ?? '' ) ), 'time' => time(), 'user' => $who );
	$n    = 0;
	foreach ( $t as $k => $v ) {
		$k = (string) $k;
		if ( ! preg_match( '/^t[a-f0-9]{10}$/', $k ) ) {
			continue;
		}
		$v = is_array( $v ) ? $v : array( 'v' => $v );
		$h = oye_kses( (string) ( $v['v'] ?? '' ) );
		if ( '' === $h ) {
			unset( $o['t'][ $k ], $meta[ $k ] );
		} else {
			$o['t'][ $k ] = $h;
			$meta[ $k ]   = $at + array( 'def' => oye_kses( (string) ( $v['def'] ?? ( $meta[ $k ]['def'] ?? '' ) ) ) );
		}
		$n++;
	}
	foreach ( $i as $k => $v ) {
		$k = (string) $k;
		if ( ! preg_match( '/^i:[a-z0-9_-]+$/', $k ) || ! is_array( $v ) ) {
			continue;
		}
		$id = (int) ( $v['id'] ?? ( $o['i'][ $k ]['id'] ?? 0 ) );
		if ( $id && ! wp_attachment_is_image( $id ) ) {
			$id = 0;
		}
		if ( $id ) {
			$id = oye_web_copy( $id, oye_slot_alt( $k, (string) ( $v['alt'] ?? '' ) ) );
		}
		$e = array( 'id' => $id );
		if ( isset( $v['fx'], $v['fy'] ) && '' !== $v['fx'] && '' !== $v['fy'] && null !== $v['fx'] ) {
			$e['fx'] = max( 0, min( 100, round( (float) $v['fx'], 1 ) ) );
			$e['fy'] = max( 0, min( 100, round( (float) $v['fy'], 1 ) ) );
		}
		if ( ! $id && ! isset( $e['fx'] ) ) {
			unset( $o['i'][ $k ], $meta[ $k ] );
		} else {
			$o['i'][ $k ] = $e;
			$meta[ $k ]   = $at + array( 'def' => esc_url_raw( (string) ( $v['def'] ?? ( $meta[ $k ]['def'] ?? '' ) ) ) );
		}
		$n++;
	}
	foreach ( $reset as $k ) {
		unset( $o['i'][ (string) $k ], $meta[ (string) $k ] );
		$n++;
	}
	update_option( 'oye_edits', $o, true );
	update_option( 'oye_edits_meta', $meta, false );
	do_action( 'oye_saved', $o );
	return array( 'ok' => true, 'changed' => $n );
}

/* ─── Képek: a weboldalra szánt másolat ───────────────────────────────────── */

/*
 * Olívia, 2026-11-14: „amiket én cserélek képek, abból csinálj egy másolatot megfelelő képmérettel hogy gyors legyen az
 * oldal és fájlelnevezéssel” – „frissítsd folyamatosan”. Amikor a szerkesztőben képet cserél, a választott fotóból
 * másolat készül, és a lapon az jelenik meg: legfeljebb 1920 px, 82-es JPEG-minőség, a neve a leírásából (kisbetű,
 * ékezet nélkül, kötőjellel: kovacs-olivia-tancos-pozban-otthon.jpg), a leírása (alt) magyar – a sajátja, ha adott neki,
 * különben azé a képé, amelyiknek a helyére került. Az eredeti a Médiatárban marad. A böngésző a másolatnak csak a
 * képernyőhöz illő méretét tölti le (srcset).
 */
const OYE_IMG_MAX  = 1920;
const OYE_STOPWORD = array( 'a', 'az', 'egy', 'es', 's', 'is', 'meg', 'de', 'the', 'and', 'of', 'in', 'on', 'at', 'with' );

/** Fájlnév egy leírásból: kisbetű, ékezet nélkül, kötőjellel, a névelők nélkül, legfeljebb 9 szó. */
function oye_slug( $text, $words = 9 ) {
	$s = strtolower( remove_accents( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
	$w = array_values( array_filter( preg_split( '/[^a-z0-9]+/', $s ), fn( $x ) => '' !== $x && ! in_array( $x, OYE_STOPWORD, true ) ) );
	return implode( '-', array_slice( $w, 0, $words ) );
}

/** Is $a a thinner text than $b, all its words in $b („Kovács Olívia - online jóga otthonról” in „Kovács Olívia táncos pózban otthon … – online jóga otthonról”)? */
function oye_thinner( $a, $b ) {
	$words = fn( $t ) => array_filter( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $t ) ), fn( $w ) => mb_strlen( $w ) > 2 );
	return '' !== trim( (string) $b ) && mb_strlen( (string) $a ) < mb_strlen( (string) $b ) && ! array_diff( $words( $a ), $words( $b ) );
}

/** Egy képhely leírása: amit a lap mutatott, vagy amit a téma tud róla (oye_slot_alt szűrő). */
function oye_slot_alt( $key, $alt = '' ) {
	$alt = trim( wp_strip_all_tags( (string) $alt ) );
	return trim( (string) apply_filters( 'oye_slot_alt', $alt, (string) $key ) );
}

/**
 * Egy feltöltött fotó webes másolata (új melléklet; ha már van, azt adja). A téma saját fotóihoz (oy_key) és a már kész
 * másolatokhoz nem nyúl, a GIF-hez (animáció) sem. Ha bármi nem sikerül, az eredeti marad a lapon.
 */
function oye_web_copy( $id, $alt = '' ) {
	$id = (int) $id;
	if ( ! $id || ! wp_attachment_is_image( $id ) || get_post_meta( $id, 'oye_web', true ) || get_post_meta( $id, 'oy_key', true ) ) {
		return $id;
	}
	$have = (int) get_post_meta( $id, 'oye_web_copy', true );
	if ( $have && wp_attachment_is_image( $have ) ) {
		return $have;
	}
	$src = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $id ) : '';
	$src = '' !== $src && file_exists( $src ) ? $src : (string) get_attached_file( $id );
	$ft  = wp_check_filetype( $src );
	if ( '' === $src || ! file_exists( $src ) || 'image/gif' === $ft['type'] ) {
		return $id;
	}
	$own  = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	$alt  = trim( (string) apply_filters( 'oye_image_alt', '' !== $own ? $own : trim( wp_strip_all_tags( (string) $alt ) ), $id ) );
	$name = oye_slug( (string) apply_filters( 'oye_image_name', '', $id, $alt ) );
	$name = '' !== $name ? $name : oye_slug( '' !== $alt ? $alt : get_the_title( $id ) );
	$name = '' !== $name ? $name : 'kep';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ed = wp_get_image_editor( $src );
	if ( is_wp_error( $ed ) ) {
		return $id;
	}
	if ( method_exists( $ed, 'maybe_exif_rotate' ) ) {
		$ed->maybe_exif_rotate(); // a phone photo stands the right way up
	}
	$size = $ed->get_size();
	if ( max( (int) $size['width'], (int) $size['height'] ) > OYE_IMG_MAX ) {
		$ed->resize( OYE_IMG_MAX, OYE_IMG_MAX, false );
	}
	$ed->set_quality( 82 );
	$keep = in_array( $ft['type'], array( 'image/png', 'image/webp' ), true ); // a photo becomes a JPEG, a graphic keeps its format
	$mime = $keep ? $ft['type'] : 'image/jpeg';
	$up   = wp_upload_dir();
	$file = trailingslashit( $up['path'] ) . wp_unique_filename( $up['path'], $name . '.' . ( $keep ? $ft['ext'] : 'jpg' ) );
	$out  = $ed->save( $file, $mime );
	if ( is_wp_error( $out ) || empty( $out['path'] ) ) {
		return $id;
	}
	$new = wp_insert_attachment(
		array(
			'post_mime_type' => $out['mime-type'],
			'post_title'     => '' !== $alt ? $alt : $name,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $up['url'] ) . basename( $out['path'] ),
		),
		$out['path'],
		0,
		true
	);
	if ( is_wp_error( $new ) || ! $new ) {
		wp_delete_file( $out['path'] );
		return $id;
	}
	wp_update_attachment_metadata( $new, wp_generate_attachment_metadata( $new, $out['path'] ) );
	if ( '' !== $alt ) {
		update_post_meta( $new, '_wp_attachment_image_alt', $alt );
	}
	update_post_meta( $new, 'oye_web', $id );
	update_post_meta( $id, 'oye_web_copy', $new );
	return (int) $new;
}

/** A korábbi cserék (amikor még nem készült másolat): mindegyikből webes másolat, és a lapon az jelenik meg. */
function oye_web_copies() {
	$o = oye_edits();
	$n = 0;
	foreach ( $o['i'] as $k => $e ) {
		$id  = (int) ( $e['id'] ?? 0 );
		$new = $id ? oye_web_copy( $id, oye_slot_alt( $k ) ) : 0;
		if ( $new && $new !== $id ) {
			$o['i'][ $k ]['id'] = $new;
			$n++;
		}
	}
	if ( $n ) {
		update_option( 'oye_edits', $o, true );
	}
	return $n ? array( sprintf( 'web copies of %d photos on the pages', $n ) ) : array();
}

/** Feltöltéskor a kép neve kisbetű, ékezet és szóköz nélkül (IMG_7309.JPG → img-7309.jpg, Tükörpóz 2.jpg → tukorpoz-2.jpg). */
function oye_file_name( $name ) {
	$ext  = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	$base = '' === $ext ? (string) $name : substr( (string) $name, 0, -strlen( $ext ) - 1 );
	$base = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $base ) ) ), '-' );
	return ( '' === $base ? 'kep' : $base ) . ( '' === $ext ? '' : '.' . $ext );
}

foreach ( array( 'wp_handle_upload_prefilter', 'wp_handle_sideload_prefilter' ) as $oye_hook ) {
	add_filter( $oye_hook, function ( $file ) {
		if ( ! empty( $file['name'] ) && preg_match( '/\.(jpe?g|png|webp|avif|heic|heif)$/i', (string) $file['name'] ) ) {
			$file['name'] = oye_file_name( $file['name'] );
		}
		return $file;
	} );
}
// a nagy fotóból WordPress 1920 px-es változatot tesz a lapra (alapból 2560), az eredeti megmarad
add_filter( 'big_image_size_threshold', fn() => OYE_IMG_MAX );

add_action( 'rest_api_init', function () {
	register_rest_route( 'oye/v1', '/edit', array(
		'methods'             => 'POST',
		'permission_callback' => fn() => oye_enabled() && current_user_can( OYE_CAP ),
		'callback'            => fn( WP_REST_Request $r ) => oye_apply( (array) ( $r['t'] ?? array() ), (array) ( $r['i'] ?? array() ), array_map( 'strval', (array) ( $r['reset'] ?? array() ) ), (array) ( $r['where'] ?? array() ) ),
	) );
} );

/* ─── A weboldalon ──────────────────────────────────────────────────────── */

function oye_asset( $file ) {
	$base = defined( 'OYE_URL' ) ? OYE_URL : get_stylesheet_directory_uri() . '/assets';
	return trailingslashit( $base ) . $file;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! oye_editing() ) {
		return;
	}
	$ver = defined( 'OYE_VERSION' ) ? OYE_VERSION : null;
	wp_enqueue_media();
	wp_enqueue_style( 'oye-edit', oye_asset( 'edit.css' ), array(), $ver );
	wp_enqueue_script( 'oye-edit', oye_asset( 'edit.js' ), array( 'media-editor' ), $ver, true );
}, 200 );

/** Belépve: „✎ Oldal szerkesztése” az admin sávban és a lap sarkában. */
add_action( 'admin_bar_menu', function ( WP_Admin_Bar $bar ) {
	if ( ! oye_enabled() || ! current_user_can( OYE_CAP ) ) {
		return;
	}
	$url = is_admin() ? add_query_arg( OYE_PARAM, '1', home_url( '/' ) ) : add_query_arg( OYE_PARAM, '1' );
	$bar->add_node( array( 'id' => 'oye-edit', 'title' => '✎ Oldal szerkesztése', 'href' => esc_url( $url ) ) );
	if ( ! is_admin() ) {
		$bar->remove_node( 'edit' ); // WordPress's own „Oldal szerkesztése” (the block editor) – inside our editor it is a link in the bar
	}
}, 90 );

add_action( 'wp_footer', function () {
	if ( ! oye_enabled() || ! current_user_can( OYE_CAP ) || oye_editing() || is_404() ) {
		return;
	}
	echo '<a class="oye-editbtn" href="' . esc_url( add_query_arg( OYE_PARAM, '1' ) ) . '">✎ Oldal szerkesztése</a>'
		. '<style>.oye-editbtn{position:fixed;left:16px;bottom:16px;z-index:99990;display:inline-flex;align-items:center;gap:.4em;padding:.75em 1.15em;border-radius:999px;'
		. 'background:#2B5036;color:#fff;font:600 15px/1.2 system-ui,sans-serif;text-decoration:none;box-shadow:0 6px 24px rgba(0,0,0,.25)}.oye-editbtn:hover{background:#1B3324;color:#fff}'
		. '@media (max-width:700px){.oye-editbtn{bottom:84px}}</style>';
} );

/* ─── Admin: Megjelenés → Szövegek és képek ───────────────────────────────── */

add_action( 'admin_menu', function () {
	if ( ! oye_enabled() ) {
		return;
	}
	add_theme_page( 'Szövegek és képek', 'Szövegek és képek', OYE_CAP, 'oye-szovegek', 'oye_admin_page' );
} );

add_action( 'admin_post_oye_save', function () {
	if ( ! current_user_can( OYE_CAP ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'oye_save' );
	if ( ! empty( $_POST['undo'] ) ) {
		$prev = get_option( 'oye_edits_prev' );
		if ( is_array( $prev ) ) {
			update_option( 'oye_edits_prev', get_option( 'oye_edits', array() ), false );
			update_option( 'oye_edits', $prev, true );
		}
		wp_safe_redirect( admin_url( 'themes.php?page=oye-szovegek&visszaallitva=1' ) );
		exit;
	}
	$t     = array();
	$reset = array();
	foreach ( (array) wp_unslash( $_POST['t'] ?? array() ) as $k => $v ) {
		$t[ $k ] = array( 'v' => (string) $v );
	}
	foreach ( array_keys( array_filter( (array) wp_unslash( $_POST['orig'] ?? array() ) ) ) as $k ) {
		if ( 0 === strpos( (string) $k, 'i:' ) ) {
			$reset[] = (string) $k;
		} else {
			$t[ $k ] = array( 'v' => '' );
		}
	}
	oye_apply( $t, array(), $reset );
	wp_safe_redirect( admin_url( 'themes.php?page=oye-szovegek&mentve=1' ) );
	exit;
} );

function oye_admin_page() {
	$E    = oye_edits();
	$meta = (array) get_option( 'oye_edits_meta', array() );
	echo '<div class="wrap oye-adm"><h1>Szövegek és képek</h1>';
	if ( ! empty( $_GET['mentve'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p><b>Mentve.</b> A weboldalon azonnal látszik.</p></div>';
	}
	if ( ! empty( $_GET['visszaallitva'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>Az előző mentés visszaállítva.</p></div>';
	}
	echo '<div class="oye-adm__tip"><p><b>A legegyszerűbb a weboldalon szerkeszteni:</b> <a class="button button-primary" href="' . esc_url( add_query_arg( OYE_PARAM, '1', home_url( '/' ) ) ) . '">✎ Szerkesztés a weboldalon</a></p>'
		. '<p>Nyisd meg bármelyik oldalt, és kattints a „✎ Oldal szerkesztése” gombra (bal alul, vagy fent az admin sávban). A szövegre kattintva írhatsz, a képre kattintva cserélhetsz, alul a Mentés. '
		. 'Az órarendet, az árakat, a bérleteket és a foglalást a <b>Stúdió</b> menüben állítod. Itt alább azok az elemek vannak, amiket már átírtál – egyenként visszaállíthatók.</p></div>';
	if ( ! $E['t'] && ! $E['i'] ) {
		echo '<p><em>Még nincs átírt szöveg vagy lecserélt kép.</em></p>';
	} else {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'oye_save' );
		echo '<input type="hidden" name="action" value="oye_save"><table class="widefat striped oye-adm__t"><thead><tr><th>Hol</th><th>Eredeti</th><th>Most</th><th></th></tr></thead><tbody>';
		foreach ( $E['t'] as $k => $v ) {
			$m = $meta[ $k ] ?? array();
			echo '<tr><td>' . oye_where( $m ) . '</td><td class="oye-adm__def">' . wp_kses( (string) ( $m['def'] ?? '' ), array_fill_keys( OYE_INLINE, array() ) ) . '</td>'
				. '<td><textarea name="t[' . esc_attr( $k ) . ']" rows="' . ( mb_strlen( (string) $v ) > 110 ? 3 : 1 ) . '">' . esc_textarea( (string) $v ) . '</textarea></td>'
				. '<td><label><input type="checkbox" name="orig[' . esc_attr( $k ) . ']" value="1"> eredeti</label></td></tr>';
		}
		foreach ( $E['i'] as $k => $v ) {
			$m   = $meta[ $k ] ?? array();
			$now = ! empty( $v['id'] ) ? wp_get_attachment_image_url( (int) $v['id'], 'thumbnail' ) : (string) ( $m['def'] ?? '' );
			echo '<tr><td>' . oye_where( $m ) . '</td><td>' . ( ! empty( $m['def'] ) ? '<img src="' . esc_url( $m['def'] ) . '" alt="" class="oye-adm__img">' : esc_html( substr( $k, 2 ) ) ) . '</td>'
				. '<td>' . ( $now ? '<img src="' . esc_url( $now ) . '" alt="" class="oye-adm__img">' : '' ) . ( isset( $v['fx'] ) ? '<br><small>kivágás: ' . esc_html( $v['fx'] . '% ' . $v['fy'] . '%' ) . '</small>' : '' ) . '</td>'
				. '<td><label><input type="checkbox" name="orig[' . esc_attr( $k ) . ']" value="1"> eredeti kép</label></td></tr>';
		}
		echo '</tbody></table><p class="oye-adm__save"><button class="button button-primary button-hero">Mentés</button> ';
		if ( false !== get_option( 'oye_edits_prev' ) ) {
			echo '<button class="button" name="undo" value="1" onclick="return confirm(\'Visszaállítod az előző mentés állapotát?\')">Előző mentés visszaállítása</button>';
		}
		echo ' <span>Formázás: &lt;b&gt;félkövér&lt;/b&gt;, &lt;em&gt;dőlt&lt;/em&gt;, &lt;br&gt; sortörés.</span></p></form>';
	}
	echo '<h2>Súgó</h2><ol class="oye-adm__help">'
		. '<li>Lépj be, és nyisd meg az oldalt, amit javítanál. Bal alul: <b>✎ Oldal szerkesztése</b>.</li>'
		. '<li>A szerkeszthető szöveg fölé víve zöld keret jelenik meg. Kattints bele, és írj. <b>B</b> = félkövér, <i>I</i> = dőlt, Enter = új sor. „Eredeti szöveg”: visszaáll.</li>'
		. '<li>Kép: kattints rá → <b>Kép cseréje</b> (feltölthetsz újat, vagy választhatsz a Médiatárból) → kattints a képen arra a pontra, ami mindig látszódjon (pl. egy arc).</li>'
		. '<li>Alul <b>Mentés</b> (vagy Ctrl+S). Utána <b>Kilépés</b>. A változás azonnal látszik mindenkinek.</li>'
		. '<li>Ugyanaz a mondat mindenhol egyszerre változik (pl. egy GYIK-válasz a főoldalon és a GYIK oldalon is).</li>'
		. '<li>Ha valami nem jó: itt pipáld be az „eredeti”-t, vagy „Előző mentés visszaállítása”.</li></ol>';
	echo '</div><style>.oye-adm__tip{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2B5036;padding:4px 14px;margin:14px 0;max-width:980px}'
		. '.oye-adm__t td{vertical-align:top}.oye-adm__t textarea{width:100%;min-width:260px}.oye-adm__def{color:#50575e;max-width:360px}.oye-adm__img{width:120px;height:80px;object-fit:cover;border-radius:6px}'
		. '.oye-adm__save{position:sticky;bottom:0;background:#f0f0f1;padding:12px 0}.oye-adm__help{max-width:820px;line-height:1.6}</style>';
}

function oye_where( array $m ) {
	if ( empty( $m['url'] ) ) {
		return '–';
	}
	$t = '' !== (string) ( $m['title'] ?? '' ) ? $m['title'] : $m['url'];
	return '<a href="' . esc_url( add_query_arg( OYE_PARAM, '1', $m['url'] ) ) . '" target="_blank">' . esc_html( wp_html_excerpt( (string) $t, 48, '…' ) ) . '</a>'
		. ( ! empty( $m['time'] ) ? '<br><small>' . esc_html( wp_date( 'Y. m. d. H:i', (int) $m['time'] ) . ( ! empty( $m['user'] ) ? ' · ' . $m['user'] : '' ) ) . '</small>' : '' );
}
