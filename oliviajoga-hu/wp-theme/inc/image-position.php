<?php
/**
 * Képek helye (Áron, 2026-11-12: „hol helyezkedjen el a kép, melyik része látszódjon”): a vágás (a keret mérete és
 * aránya) a dizájné marad, az adminban azt lehet kijelölni, a fotó melyik pontja maradjon mindig látható.
 *
 * Megjelenés → Képek helye: az oldal összes fotója oldalanként; a fotóra kattintva a pont kijelölhető, mellette a
 * keretek előnézete. Tárolás: `oj_img_pos` opció (kép-kulcs → "40% 30%"); a kép-kulcs a fájl útja méret nélkül
 * (`new/hero`, `old/49fd21489bf3`, `theme/olivia-sunset-smile`), így ugyanaz a fotó minden oldalon ugyanott áll.
 * Kiírás: `oj_fill()` → `oj_apply_positions()` az <img> `object-position` stílusát írja át.
 */

defined( 'ABSPATH' ) || exit;

/** A kép kulcsa a beépített HTML src-jéből (%%MEDIA%%/new/hero-1600.jpg → new/hero). */
function oj_image_key( $src ) {
	$src = (string) strtok( (string) $src, '?' );
	if ( str_starts_with( $src, '%%MEDIA%%/' ) ) {
		$rel = substr( $src, 10 );
	} elseif ( false !== ( $i = strpos( $src, '/assets/img/theme/' ) ) ) {
		$rel = 'theme/' . substr( $src, $i + 18 );
	} else {
		return '';
	}
	$rel = rawurldecode( $rel );
	return (string) preg_replace( '/-(600|800|1200|1600)\.(jpe?g|webp|png)$/i', '', $rel );
}

/** "40% 30%" vagy '' (érvénytelen). */
function oj_pos_clean( $v ) {
	return preg_match( '/^\s*(\d{1,3})%\s+(\d{1,3})%\s*$/', (string) $v, $m ) && (int) $m[1] <= 100 && (int) $m[2] <= 100 ? (int) $m[1] . '% ' . (int) $m[2] . '%' : '';
}

function oj_image_positions() {
	static $pos = null;
	if ( null === $pos ) {
		$pos = (array) get_option( 'oj_img_pos', array() );
	}
	return $pos;
}

/** A kijelölt pontok a beépített HTML képeire (még a %%…%% helyőrzős alakon). */
function oj_apply_positions( $html ) {
	$pos = oj_image_positions();
	if ( ! $pos || ! str_contains( (string) $html, '<img' ) ) {
		return $html;
	}
	return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( $pos ) {
		$tag = $m[0];
		if ( ! preg_match( '/\ssrc="([^"]+)"/', $tag, $s ) ) {
			return $tag;
		}
		$key = oj_image_key( $s[1] );
		if ( '' === $key || empty( $pos[ $key ] ) ) {
			return $tag;
		}
		$rule = 'object-position:' . $pos[ $key ];
		if ( preg_match( '/\sstyle="([^"]*)"/', $tag, $st ) ) {
			$style = trim( (string) preg_replace( '/object-position\s*:[^;"]*;?/i', '', $st[1] ), '; ' );
			return str_replace( $st[0], ' style="' . esc_attr( ( $style ? $style . ';' : '' ) . $rule ) . '"', $tag );
		}
		return preg_replace( '/<img\b/i', '<img style="' . esc_attr( $rule ) . '"', $tag, 1 );
	}, (string) $html );
}

/** Az oldal fotói oldalanként: [ oldal neve => [ kulcs => [src, alt, alap pozíció] ] ], és hol szerepel egy kép. */
function oj_site_images() {
	$data   = oj_data();
	$groups = array();
	$seen   = array();
	$where  = array();
	$scan   = function ( $html, $page ) use ( &$groups, &$seen, &$where ) {
		preg_match_all( '/<img\b[^>]*>/i', (string) $html, $tags );
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\ssrc="([^"]+)"/', $tag, $s ) ) {
				continue;
			}
			$key = oj_image_key( $s[1] );
			if ( '' === $key || str_ends_with( $key, '.svg' ) ) {
				continue; // logók
			}
			$where[ $key ][ str_replace( ' (november 26-tól)', '', $page ) ] = true;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			preg_match( '/\salt="([^"]*)"/', $tag, $a );
			preg_match( '/object-position\s*:\s*([^;"]+)/i', $tag, $p );
			$groups[ $page ][ $key ] = array(
				'src' => $s[1],
				'alt' => html_entity_decode( $a[1] ?? '', ENT_QUOTES ),
				'def' => oj_pos_clean( $p[1] ?? '' ) ?: '50% 50%',
			);
		}
	};
	// sorrend: a főoldal, a menü oldalai, aztán a többi (cikkek)
	$pages = (array) ( $data['pages'] ?? array() );
	$order = array_unique( array_merge( array( '' ), array_map( fn( $n ) => (string) $n[0], (array) ( $data['nav'] ?? array() ) ), array_map( 'strval', array_keys( $pages ) ) ) );
	foreach ( $order as $path ) {
		$p = $pages[ $path ] ?? null;
		if ( null === $p || in_array( (string) $path, array( 'főoldal', 'fooldal' ), true ) ) {
			continue; // ugyanaz, mint a főoldal
		}
		$name = '' === (string) $path ? 'Főoldal' : ( $p['name'] ?? $path );
		$scan( $p['content'] ?? '', $name );
		$scan( $data['after']['pages'][ $path ]['content'] ?? '', $name . ' (november 26-tól)' );
	}
	return array( $groups, $where );
}

add_action( 'admin_menu', function () {
	add_theme_page( 'Képek helye', 'Képek helye', 'edit_theme_options', 'oj-kepek-helye', 'oj_image_positions_page' );
} );

add_action( 'admin_post_oj_image_positions', function () {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'oj_image_positions' );
	$in  = (array) wp_unslash( $_POST['pos'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$def = (array) wp_unslash( $_POST['def'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$out = (array) get_option( 'oj_img_pos', array() );
	foreach ( $in as $key => $v ) {
		$key = (string) $key;
		if ( ! preg_match( '#^[\w./-]+$#u', $key ) ) {
			continue;
		}
		$v = oj_pos_clean( $v );
		if ( '' === $v || oj_pos_clean( $def[ $key ] ?? '' ) === $v ) {
			unset( $out[ $key ] ); // a dizájn alapértéke
		} else {
			$out[ $key ] = $v;
		}
	}
	update_option( 'oj_img_pos', $out, true );
	wp_safe_redirect( add_query_arg( array( 'page' => 'oj-kepek-helye', 'saved' => 1 ), admin_url( 'themes.php' ) ) );
	exit;
} );

function oj_image_positions_page() {
	[ $groups, $where ] = oj_site_images();
	$pos                = oj_image_positions();
	echo '<div class="wrap oj-kepek"><h1>Képek helye</h1>';
	if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success"><p>Elmentve.</p></div>';
	}
	echo '<p style="max-width:760px">Kattints a fotón arra a pontra, aminek mindig látszania kell (például az arcra). A keret mérete és '
		. 'vágása marad; a jobb oldali kis képek mutatják, hogyan látszik a fotó a különböző keretekben. Egy fotó minden oldalon '
		. 'ugyanott áll. Végül: <b>Mentés</b>.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'oj_image_positions' );
	echo '<input type="hidden" name="action" value="oj_image_positions">';
	echo '<p><button class="button button-primary">Mentés</button></p>';
	foreach ( $groups as $page => $imgs ) {
		echo '<h2>' . esc_html( $page ) . '</h2><div class="oj-kepek__grid">';
		foreach ( $imgs as $key => $img ) {
			$cur  = $pos[ $key ] ?? $img['def'];
			[ $x, $y ] = array_map( 'intval', explode( ' ', str_replace( '%', '', $cur ) ) );
			$src  = oj_fill( str_replace( array( '-1600.', '-1200.' ), array( '-800.', '-600.' ), $img['src'] ) );
			$also = array_values( array_diff( array_keys( $where[ $key ] ?? array() ), array( str_replace( ' (november 26-tól)', '', $page ) ) ) );
			if ( count( $also ) > 3 ) {
				$also = array_merge( array_slice( $also, 0, 3 ), array( 'és még ' . ( count( $also ) - 3 ) . ' oldalon' ) );
			}
			echo '<div class="oj-focus" data-oj-focus data-def="' . esc_attr( $img['def'] ) . '">'
				. '<div class="oj-focus__pick" title="Kattints a fontos pontra"><img src="' . esc_url( $src ) . '" alt="" loading="lazy"><span class="oj-focus__dot" style="left:' . $x . '%;top:' . $y . '%"></span></div>'
				. '<div class="oj-focus__side"><div class="oj-focus__prevs">'
				. '<span class="oj-focus__prev oj-focus__prev--sq"><img src="' . esc_url( $src ) . '" alt="" loading="lazy" style="object-position:' . esc_attr( $cur ) . '"></span>'
				. '<span class="oj-focus__prev oj-focus__prev--tall"><img src="' . esc_url( $src ) . '" alt="" loading="lazy" style="object-position:' . esc_attr( $cur ) . '"></span>'
				. '<span class="oj-focus__prev oj-focus__prev--wide"><img src="' . esc_url( $src ) . '" alt="" loading="lazy" style="object-position:' . esc_attr( $cur ) . '"></span></div>'
				. '<p class="oj-focus__alt">' . esc_html( $img['alt'] ?: $key ) . '</p>'
				. ( $also ? '<p class="oj-focus__also">Még itt: ' . esc_html( implode( ', ', $also ) ) . '</p>' : '' )
				. '<p><input type="text" class="oj-focus__val small-text" name="pos[' . esc_attr( $key ) . ']" value="' . esc_attr( $cur ) . '" style="width:7.5em">'
				. '<input type="hidden" name="def[' . esc_attr( $key ) . ']" value="' . esc_attr( $img['def'] ) . '"> '
				. '<button type="button" class="button-link oj-focus__reset">Alaphelyzet</button></p></div></div>';
		}
		echo '</div>';
	}
	echo '<p><button class="button button-primary">Mentés</button></p></form></div>';
	?>
<style>
.oj-kepek__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));gap:16px;margin:8px 0 24px}
.oj-focus{display:grid;grid-template-columns:200px 1fr;gap:14px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px}
.oj-focus__pick{position:relative;cursor:crosshair;line-height:0;align-self:start}
.oj-focus__pick img{width:100%;height:auto;border-radius:3px}
.oj-focus__dot{position:absolute;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;border:3px solid #fff;background:#d92b86;box-shadow:0 0 0 1px rgba(0,0,0,.4);pointer-events:none}
.oj-focus__prevs{display:flex;gap:6px;align-items:flex-end}
.oj-focus__prev{display:block;overflow:hidden;border-radius:3px;background:#eee}
.oj-focus__prev img{width:100%;height:100%;object-fit:cover;display:block}
.oj-focus__prev--sq{width:64px;height:64px}.oj-focus__prev--tall{width:56px;height:70px}.oj-focus__prev--wide{width:112px;height:63px}
.oj-focus__alt{margin:8px 0 2px;font-size:12px;color:#50575e}.oj-focus__also{margin:0;font-size:12px;color:#787c82}
</style>
<script>
(function () {
	function set(box, x, y) {
		x = Math.max(0, Math.min(100, Math.round(x))); y = Math.max(0, Math.min(100, Math.round(y)));
		var v = x + '% ' + y + '%';
		box.querySelector('.oj-focus__val').value = v;
		var d = box.querySelector('.oj-focus__dot'); d.style.left = x + '%'; d.style.top = y + '%';
		box.querySelectorAll('.oj-focus__prev img').forEach(function (i) { i.style.objectPosition = v; });
	}
	document.addEventListener('click', function (e) {
		var pick = e.target.closest('.oj-focus__pick');
		if (pick) { var r = pick.getBoundingClientRect(); set(pick.closest('[data-oj-focus]'), (e.clientX - r.left) / r.width * 100, (e.clientY - r.top) / r.height * 100); }
		var reset = e.target.closest('.oj-focus__reset');
		if (reset) { var b = reset.closest('[data-oj-focus]'), p = b.getAttribute('data-def').replace(/%/g, '').split(' '); set(b, +p[0], +p[1]); }
	});
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('oj-focus__val')) return;
		var m = e.target.value.match(/^\s*(\d{1,3})%?\s+(\d{1,3})%?\s*$/);
		if (m) set(e.target.closest('[data-oj-focus]'), +m[1], +m[2]);
	});
})();
</script>
	<?php
}
