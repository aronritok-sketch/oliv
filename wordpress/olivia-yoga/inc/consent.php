<?php
/**
 * Google Analytics 4 only with consent, and the cookie bar that asks for it (Áron, 16 November: „GA4 + Meta Pixel: KELL,
 * sütibannerrel – hozzájárulás után töltődjön; a köszönőoldal mérési eseménye maradjon”; the GA4 property was made on
 * his laptop: G-WM1P5R67VJ).
 *
 * - Google Consent Mode v2: everything „denied” by default, and gtag.js is not even loaded until the visitor accepts.
 *   Our bar asks for measuring only (analytics_storage); nothing for ads – there are none yet.
 * - The choice is kept in the oy_consent cookie (granted / denied, a year), apart from Ads Connect's hpvac_choice: that
 *   one is a consent to ads (Google, Meta), which our bar never asked for.
 * - When the HelloProVision Ads Connect plugin shows its own bar (for the Meta Pixel), ours steps aside and Google
 *   Analytics follows that bar's decision (its text covers „a hirdetéseink mérése (Google, Meta)”) – also at once, on
 *   its „hpvac:consent” event.
 * - „Sütibeállítások” in the footer (and any #cookie-settings link) opens the bar again.
 * - The measurement ID: the option oy_ga4_id, or on the online site G-WM1P5R67VJ. Without one nothing is printed.
 * - Meta Pixel (Áron, 16 November: „csinálj meta pixelt Oliviának”): the option oy_meta_pixel (digits only; settable
 *   through the REST settings, /wp-json/wp/v2/settings). Only once it is set: the bar asks for two things – statistics
 *   (Google Analytics) and marketing (Meta) – with „Beállítások” to choose them one by one; fbevents.js loads only after
 *   a yes to marketing, the thank-you page sends a „Lead”, and the privacy page tells about Meta too. A yes given before
 *   (oy_consent=granted, the statistics-only bar of 3.11.88) is never taken for a yes to marketing: the bar asks again.
 *   Cookie values: all · stats · ads · denied (granted = stats).
 *
 * @package olivia-yoga
 */

defined( 'ABSPATH' ) || exit;

const OY_GA4_ONLINE = 'G-WM1P5R67VJ';

function oy_ga4_id() {
	$id = (string) apply_filters( 'oy_ga4_id', (string) get_option( 'oy_ga4_id', oy_online_only() ? OY_GA4_ONLINE : '' ) );
	return preg_match( '/^G-[A-Z0-9]{6,12}$/', $id ) ? $id : '';
}

/** The Meta Pixel's ID (digits), or ''. */
function oy_meta_pixel_id() {
	$id = preg_replace( '/\D/', '', (string) apply_filters( 'oy_meta_pixel_id', (string) get_option( 'oy_meta_pixel', '' ) ) );
	return strlen( $id ) >= 10 && strlen( $id ) <= 20 ? $id : '';
}

add_action( 'init', function () {
	register_setting( 'oy_measure', 'oy_meta_pixel', array(
		'type'              => 'string',
		'description'       => 'Meta Pixel ID (oliviajoga.online)',
		'default'           => '',
		'show_in_rest'      => true,
		'sanitize_callback' => fn( $v ) => preg_replace( '/\D/', '', (string) $v ),
	) );
} );

/** Something to ask a consent for: Google Analytics or the Meta Pixel. */
function oy_consent_wanted() {
	return '' !== oy_ga4_id() || '' !== oy_meta_pixel_id();
}

/** Ads Connect shows its own cookie bar: its decision counts, ours is not shown. */
function oy_consent_by_hpvac() {
	return function_exists( 'hpvac_banner_enabled' ) && hpvac_banner_enabled();
}

/** Our bar is shown (and the footer link) when there is something to ask for and no other bar asks. */
function oy_consent_bar_on() {
	return oy_consent_wanted() && ! oy_consent_by_hpvac() && ! is_admin();
}

add_action( 'wp_head', 'oy_consent_head', 1 );
add_action( 'wp_footer', 'oy_consent_bar', 50 );

/** Consent Mode v2, all denied; gtag.js only after a yes to statistics, fbevents.js only after a yes to marketing. */
function oy_consent_head() {
	$id    = oy_ga4_id();
	$hpvac = oy_consent_by_hpvac();
	$pixel = $hpvac ? '' : oy_meta_pixel_id(); // with Ads Connect's bar the pixel is Ads Connect's
	if ( ( '' === $id && '' === $pixel ) || is_admin() ) {
		return;
	}
	?>
<script>
window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 });
(function (w, d) {
	var ga = <?php echo wp_json_encode( $id ); ?>, px = <?php echo wp_json_encode( $pixel ); ?>;
	w.oyLoadGa = function () {
		if (w.oyGaLoaded || !ga) { return; }
		w.oyGaLoaded = true;
		gtag('consent', 'update', { analytics_storage: 'granted' });
		gtag('js', new Date());
		gtag('config', ga);
		var s = d.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga);
		d.head.appendChild(s);
	};
	w.oyLoadPixel = function () {
		if (w.oyPixelLoaded || !px) { return; }
		w.oyPixelLoaded = true;
		if (!w.fbq) {
			var n = w.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
			if (!w._fbq) { w._fbq = n; }
			n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
			var t = d.createElement('script'); t.async = true; t.src = 'https://connect.facebook.net/en_US/fbevents.js';
			d.head.appendChild(t);
		}
		w.fbq('consent', 'grant');
		w.fbq('init', px);
		w.fbq('track', 'PageView');
	};
<?php if ( $hpvac ) : ?>
	if (/(?:^|; )hpvac_choice=granted/.test(d.cookie)) { w.oyLoadGa(); }
	d.addEventListener('hpvac:consent', function (e) {
		if (e.detail && e.detail.marketing) { w.oyLoadGa(); } else if (w.oyGaLoaded) { gtag('consent', 'update', { analytics_storage: 'denied' }); }
	});
<?php else : ?>
	var m = /(?:^|; )oy_consent=([a-z]+)/.exec(d.cookie), c = m ? m[1] : '';
	if (/^(all|stats|granted)$/.test(c)) { w.oyLoadGa(); }
	if (/^(all|ads)$/.test(c)) { w.oyLoadPixel(); }
<?php endif; ?>
})(window, document);
</script>
	<?php
}

/**
 * The bar: „Elfogadom” / „Csak a szükségesek”, until one of them is chosen. With the Meta Pixel set, also
 * „Beállítások”: statistics and marketing one by one.
 */
function oy_consent_bar() {
	if ( ! oy_consent_bar_on() ) {
		return;
	}
	$privacy = get_privacy_policy_url() ?: oy_page_link( 'privacy-policy' );
	$ga      = '' !== oy_ga4_id();
	$px      = '' !== oy_meta_pixel_id();
	if ( $ga && $px ) {
		$what = 'Ha hozzájárulsz, a Google Analytics méri, hogyan használják az oldalt, a Meta (Facebook, Instagram) pedig azt, hogy mit érnek el a hirdetéseink.';
	} elseif ( $px ) {
		$what = 'Ha hozzájárulsz, a Meta (Facebook, Instagram) méri, hogy mit érnek el a hirdetéseink.';
	} else {
		$what = 'Ha hozzájárulsz, a Google Analytics is méri, hogyan használják az oldalt, hogy jobbá tehessük.';
	}
	?>
<div class="oy-consent" id="oy-consent" role="dialog" aria-live="polite" aria-labelledby="oy-consent-title" data-ga="<?php echo $ga ? '1' : '0'; ?>" data-px="<?php echo $px ? '1' : '0'; ?>" hidden>
	<p class="oy-consent__text"><b id="oy-consent-title">Sütik</b> A működéshez szükséges sütik mindig működnek. <?php echo esc_html( $what ); ?> <a href="<?php echo esc_url( $privacy ); ?>">Adatkezelési tájékoztató</a></p>
<?php if ( $px ) : ?>
	<div class="oy-consent__opts" hidden>
		<label class="oy-consent__opt"><input type="checkbox" checked disabled> <span><b>Szükséges</b> belépés, foglalás, biztonság – mindig működik</span></label>
<?php if ( $ga ) : ?>
		<label class="oy-consent__opt"><input type="checkbox" name="oy-stats"> <span><b>Statisztika</b> Google Analytics: hogyan használják az oldalt</span></label>
<?php endif; ?>
		<label class="oy-consent__opt"><input type="checkbox" name="oy-ads"> <span><b>Marketing</b> Meta (Facebook, Instagram): mit érnek el a hirdetéseink</span></label>
	</div>
<?php endif; ?>
	<p class="oy-consent__buttons"><button type="button" class="btn btn--orchid" data-consent="all">Elfogadom</button> <button type="button" class="btn btn--ghost" data-consent="denied">Csak a szükségesek</button><?php if ( $px ) : ?> <button type="button" class="btn btn--ghost oy-consent__more" data-consent="settings">Beállítások</button><button type="button" class="btn btn--ghost oy-consent__save" data-consent="save" hidden>Mentés</button><?php endif; ?></p>
</div>
<style>
.oy-consent { position: fixed; z-index: 1000; left: 16px; right: 16px; bottom: 16px; max-width: 620px; margin: 0 auto; padding: 18px 20px; border-radius: 22px; background: var(--card, #FAF8F9); color: var(--text, #1E3432); box-shadow: 0 10px 40px rgba(7, 38, 36, .22); }
.oy-consent[hidden], .oy-consent [hidden] { display: none; }
.oy-consent__text { margin: 0 0 12px; font-size: .95rem; line-height: 1.55; }
.oy-consent__text b { display: block; margin-bottom: 2px; font-size: 1.02rem; }
.oy-consent__text a { color: inherit; text-decoration: underline; text-underline-offset: .2em; }
.oy-consent__opts { display: grid; gap: 8px; margin: 0 0 12px; }
.oy-consent__opt { display: flex; gap: 10px; align-items: flex-start; font-size: .9rem; line-height: 1.4; cursor: pointer; }
.oy-consent__opt input { width: 20px; height: 20px; margin: 1px 0 0; flex: none; accent-color: var(--orchid, #DB2E5C); }
.oy-consent__opt b { display: block; }
.oy-consent__buttons { display: flex; flex-wrap: wrap; gap: 10px; margin: 0; }
.oy-consent__buttons .btn { min-height: 44px; }
.oy-consent .btn--ghost { color: var(--forest, #002E2C); border-color: currentColor; }
@media (max-width: 759px) { .oy-consent { left: 10px; right: 10px; bottom: 10px; padding: 14px 16px; border-radius: 18px; } body:has([data-action-bar]) .oy-consent { bottom: calc(80px + env(safe-area-inset-bottom)); } .oy-consent__text { margin-bottom: 10px; font-size: .86rem; line-height: 1.45; } .oy-consent__buttons { gap: 8px; } .oy-consent__buttons .btn { flex: 1 1 0; min-width: 0; padding: .7em .6em; font-size: .74rem; letter-spacing: .04em; white-space: normal; } .oy-consent__buttons .oy-consent__more, .oy-consent__buttons .oy-consent__save { flex-basis: 100%; } .oy-consent__opt { font-size: .84rem; } }
</style>
<script>
(function (d, w) {
	var bar = d.getElementById('oy-consent');
	if (!bar) { return; }
	var ga = bar.getAttribute('data-ga') === '1', px = bar.getAttribute('data-px') === '1';
	var opts = bar.querySelector('.oy-consent__opts'), more = bar.querySelector('.oy-consent__more'), save = bar.querySelector('.oy-consent__save');
	var stats = bar.querySelector('[name="oy-stats"]'), ads = bar.querySelector('[name="oy-ads"]');
	function cur() { var m = /(?:^|; )oy_consent=([a-z]+)/.exec(d.cookie); return m ? m[1] : ''; }
	// decided: any answer; with the pixel, a statistics-only yes of before (granted) is no answer to marketing yet
	if (!(px ? /^(all|stats|ads|denied)$/ : /^(all|stats|ads|denied|granted)$/).test(cur())) { bar.hidden = false; }
	function settings(on) {
		if (!opts) { return; }
		var c = cur();
		if (stats) { stats.checked = /^(all|stats|granted)$/.test(c); }
		if (ads) { ads.checked = /^(all|ads)$/.test(c); }
		opts.hidden = !on; save.hidden = !on; more.hidden = on;
	}
	function choose(v) {
		var exp = new Date(Date.now() + 365 * 864e5).toUTCString();
		d.cookie = 'oy_consent=' + v + '; expires=' + exp + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		bar.hidden = true;
		settings(false);
		if (/^(all|stats)$/.test(v)) { w.oyLoadGa && w.oyLoadGa(); } else if (w.oyGaLoaded) { w.gtag('consent', 'update', { analytics_storage: 'denied' }); }
		if (/^(all|ads)$/.test(v)) { w.oyLoadPixel && w.oyLoadPixel(); } else if (w.oyPixelLoaded && w.fbq) { w.fbq('consent', 'revoke'); }
	}
	bar.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-consent]');
		if (!b) { return; }
		var v = b.getAttribute('data-consent');
		if (v === 'settings') { settings(true); if (stats || ads) { (stats || ads).focus({ preventScroll: true }); } return; }
		if (v === 'all') { v = px ? 'all' : 'stats'; }
		if (v === 'save') {
			var s = !!(stats && stats.checked), a = !!(ads && ads.checked);
			v = s && a ? 'all' : s ? 'stats' : a ? (ga ? 'ads' : 'all') : 'denied';
		}
		choose(v);
	});
	d.addEventListener('click', function (e) {
		var l = e.target.closest && e.target.closest('a[href$="#cookie-settings"]');
		if (l) { e.preventDefault(); bar.hidden = false; settings(px); var f = bar.querySelector('button:not([hidden]), input:not([disabled])'); if (f) { f.focus({ preventScroll: true }); } }
	});
})(document, window);
</script>
	<?php
}

/** „Sütibeállítások” among the legal links of the footer (Ads Connect's bar opens on the same link). */
function oy_consent_footer_link() {
	return oy_consent_wanted() ? ' · <a href="#cookie-settings">Sütibeállítások</a>' : '';
}

/**
 * The privacy page while the Meta Pixel is on: a paragraph about it in „Sütik”, and Meta among those who get data. Added
 * on the fly (not stored), so the page is right whether the pixel is on or off; Olívia's own text is not touched.
 */
add_filter( 'the_content', function ( $html ) {
	$page = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( '' === oy_meta_pixel_id() || ! $page || get_the_ID() !== $page || str_contains( $html, 'Meta Pixel' ) ) {
		return $html;
	}
	$meta = '<p>Ha a „Marketing” sütikhez is hozzájárulsz, a Meta Pixel (Meta Platforms Ireland Limited) is betöltődik. Így a Meta látja, hogy a Facebookon vagy az Instagramon megjelenő hirdetésünk után megnézted-e az oldalt, és küldtél-e üzenetet; ebből méri és javítja a hirdetéseinket. Ehhez az <code>_fbp</code> és <code>_fbc</code> sütiket használja, legfeljebb 90 napig. A Meta az adatokat a saját szabályai szerint, az Egyesült Államokban is kezelheti. Hozzájárulás nélkül a Meta Pixel be sem töltődik.</p>';
	$html = preg_replace( '~ ?Reklámsütit nem használunk\.~u', '', $html );
	$html = preg_replace( '~(<p>A döntésedet bármikor megváltoztathatod)~u', $meta . "\n$1", $html, 1, $n );
	if ( ! $n ) {
		$html = preg_replace( '~(<h2>Meddig őrizzük)~u', $meta . "\n$1", $html, 1 );
	}
	return preg_replace( '~a Google Analytics \(az oldal látogatottságának mérése\)\. Adatot nem adunk el, és reklámcélra nem adunk át senkinek\.~u', 'a Google Analytics (az oldal látogatottságának mérése) és a Meta (a hirdetéseink mérése). Adatot nem adunk el; a Metához is csak akkor jut adat, ha a „Marketing” sütikhez hozzájárulsz.', $html );
}, 5 );

/**
 * The privacy page on a live site (the site's privacy policy page, from the content file's „privacy”): the „Sütik” section and „Kik férnek hozzá?” tell about Google Analytics. Only the
 * two paragraphs the theme wrote are replaced – anything Olivia changed on the page stays.
 */
function oy_migrate_ga4() {
	$rev = '2026-11-16a';
	if ( 'hu-online' !== oy_edition() || get_option( 'oy_ga4_rev' ) === $rev ) {
		return array();
	}
	update_option( 'oy_ga4_rev', $rev );
	$page = get_post( (int) get_option( 'wp_page_for_privacy_policy' ) ) ?: get_page_by_path( 'adatvedelem' );
	if ( ! $page ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( oy_content_file() ), true );
	$new  = (string) ( $data['privacy']['content'] ?? '' );
	$pick = fn( $html, $h ) => preg_match( '~<h2>' . preg_quote( $h, '~' ) . '</h2>.*?(?=<h2>|$)~su', $html, $m ) ? trim( $m[0] ) : '';
	$html = $page->post_content;
	$done = array();
	foreach ( array(
		'Sütik'             => '<h2>Sütik</h2>\s*<p>Az oldal csak a működéséhez szükséges sütiket használja[^<]*</p>',
		'Kik férnek hozzá?' => '<h2>Kik férnek hozzá\?</h2>\s*<p>Az oktató, valamint azok a szolgáltatók, akik nélkül az oldal nem működne:[^<]*LiveKit \(élő videó\)\. Adatot nem adunk el, és reklámcélra nem adunk át senkinek\.</p>',
	) as $h => $old ) {
		$to = $pick( $new, $h );
		if ( '' !== $to && preg_match( '~' . $old . '~u', $html ) ) {
			$html   = preg_replace( '~' . $old . '~u', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $to ), $html, 1 );
			$done[] = $h;
		}
	}
	if ( ! $done ) {
		return array();
	}
	kses_remove_filters();
	wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_content' => $html ) ) );
	kses_init_filters();
	return array( 'privacy: Google Analytics (' . implode( ', ', $done ) . ')' );
}
