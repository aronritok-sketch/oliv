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
 *
 * @package olivia-yoga
 */

defined( 'ABSPATH' ) || exit;

const OY_GA4_ONLINE = 'G-WM1P5R67VJ';

function oy_ga4_id() {
	$id = (string) apply_filters( 'oy_ga4_id', (string) get_option( 'oy_ga4_id', oy_online_only() ? OY_GA4_ONLINE : '' ) );
	return preg_match( '/^G-[A-Z0-9]{6,12}$/', $id ) ? $id : '';
}

/** Ads Connect shows its own cookie bar: its decision counts, ours is not shown. */
function oy_consent_by_hpvac() {
	return function_exists( 'hpvac_banner_enabled' ) && hpvac_banner_enabled();
}

/** Our bar is shown (and the footer link) when there is something to ask for and no other bar asks. */
function oy_consent_bar_on() {
	return '' !== oy_ga4_id() && ! oy_consent_by_hpvac() && ! is_admin();
}

add_action( 'wp_head', 'oy_consent_head', 1 );
add_action( 'wp_footer', 'oy_consent_bar', 50 );

/** Consent Mode v2, all denied; gtag.js only after a yes. */
function oy_consent_head() {
	$id = oy_ga4_id();
	if ( '' === $id || is_admin() ) {
		return;
	}
	$cookie = oy_consent_by_hpvac() ? 'hpvac_choice' : 'oy_consent';
	?>
<script>
window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 });
gtag('js', new Date());
gtag('config', <?php echo wp_json_encode( $id ); ?>);
(function (w, d) {
	w.oyLoadGa = function () {
		if (w.oyGaLoaded) { return; }
		w.oyGaLoaded = true;
		gtag('consent', 'update', { analytics_storage: 'granted' });
		var s = d.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + <?php echo wp_json_encode( rawurlencode( $id ) ); ?>;
		d.head.appendChild(s);
	};
	if (/(?:^|; )<?php echo esc_js( $cookie ); ?>=granted/.test(d.cookie)) { w.oyLoadGa(); }
<?php if ( 'hpvac_choice' === $cookie ) : ?>
	d.addEventListener('hpvac:consent', function (e) {
		if (e.detail && e.detail.marketing) { w.oyLoadGa(); } else if (w.oyGaLoaded) { gtag('consent', 'update', { analytics_storage: 'denied' }); }
	});
<?php endif; ?>
})(window, document);
</script>
	<?php
}

/** The bar: „Elfogadom” / „Csak a szükségesek”, until one of them is chosen. */
function oy_consent_bar() {
	if ( ! oy_consent_bar_on() ) {
		return;
	}
	$privacy = get_privacy_policy_url() ?: oy_page_link( 'privacy-policy' );
	?>
<div class="oy-consent" id="oy-consent" role="dialog" aria-live="polite" aria-labelledby="oy-consent-title" hidden>
	<p class="oy-consent__text"><b id="oy-consent-title">Sütik</b> A működéshez szükséges sütik mindig működnek. Ha hozzájárulsz, a Google Analytics is méri, hogyan használják az oldalt, hogy jobbá tehessük. <a href="<?php echo esc_url( $privacy ); ?>">Adatkezelési tájékoztató</a></p>
	<p class="oy-consent__buttons"><button type="button" class="btn btn--orchid" data-consent="granted">Elfogadom</button> <button type="button" class="btn btn--ghost" data-consent="denied">Csak a szükségesek</button></p>
</div>
<style>
.oy-consent { position: fixed; z-index: 1000; left: 16px; right: 16px; bottom: 16px; max-width: 620px; margin: 0 auto; padding: 18px 20px; border-radius: 22px; background: var(--card, #FAF8F9); color: var(--text, #1E3432); box-shadow: 0 10px 40px rgba(7, 38, 36, .22); }
.oy-consent[hidden] { display: none; }
.oy-consent__text { margin: 0 0 12px; font-size: .95rem; line-height: 1.55; }
.oy-consent__text b { display: block; margin-bottom: 2px; font-size: 1.02rem; }
.oy-consent__text a { color: inherit; text-decoration: underline; text-underline-offset: .2em; }
.oy-consent__buttons { display: flex; flex-wrap: wrap; gap: 10px; margin: 0; }
.oy-consent__buttons .btn { min-height: 44px; }
.oy-consent .btn--ghost { color: var(--forest, #002E2C); border-color: currentColor; }
@media (max-width: 759px) { .oy-consent { left: 10px; right: 10px; bottom: 10px; padding: 14px 16px; border-radius: 18px; } body:has([data-action-bar]) .oy-consent { bottom: calc(80px + env(safe-area-inset-bottom)); } .oy-consent__text { margin-bottom: 10px; font-size: .86rem; line-height: 1.45; } .oy-consent__buttons { flex-wrap: nowrap; gap: 8px; } .oy-consent__buttons .btn { flex: 1 1 0; min-width: 0; padding: .7em .6em; font-size: .74rem; letter-spacing: .04em; white-space: normal; } }
</style>
<script>
(function (d, w) {
	var bar = d.getElementById('oy-consent');
	if (!bar) { return; }
	if (!/(?:^|; )oy_consent=(granted|denied)/.test(d.cookie)) { bar.hidden = false; }
	function choose(v) {
		var exp = new Date(Date.now() + 365 * 864e5).toUTCString();
		d.cookie = 'oy_consent=' + v + '; expires=' + exp + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		bar.hidden = true;
		if (v === 'granted') { w.oyLoadGa && w.oyLoadGa(); } else if (w.oyGaLoaded) { w.gtag('consent', 'update', { analytics_storage: 'denied' }); }
	}
	bar.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-consent]');
		if (b) { choose(b.getAttribute('data-consent')); }
	});
	d.addEventListener('click', function (e) {
		var l = e.target.closest && e.target.closest('a[href$="#cookie-settings"]');
		if (l) { e.preventDefault(); bar.hidden = false; var f = bar.querySelector('button'); if (f) { f.focus({ preventScroll: true }); } }
	});
})(document, window);
</script>
	<?php
}

/** „Sütibeállítások” among the legal links of the footer (Ads Connect's bar opens on the same link). */
function oy_consent_footer_link() {
	return '' !== oy_ga4_id() ? ' · <a href="#cookie-settings">Sütibeállítások</a>' : '';
}

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
