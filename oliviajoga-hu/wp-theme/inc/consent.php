<?php
/**
 * Sütisáv és Google Analytics 4 – csak hozzájárulással (Áron, 2026-11-17: „Az Arovia legyen minden … A GA4 oké”).
 *
 * Ugyanaz, mint az oliviajoga.online-on (wordpress/olivia-yoga/inc/consent.php), ugyanazzal a méréssel (G-WM1P5R67VJ),
 * és a két oldal között átjárással (linker: oliviajoga.hu ↔ oliviajoga.online):
 * - Google Consent Mode v2: alapból minden „denied”, a gtag.js be sem töltődik, amíg a látogató el nem fogadja.
 * - A döntés az oy_consent sütiben (stats / denied, egy évig) – ugyanaz a név, mint az online oldalon.
 * - Ha a HelloProVision Ads Connect saját sávot mutat, a miénk félreáll, és a GA annak döntését követi.
 * - „Sütibeállítások” a láblécben (és minden #cookie-settings link) újra megnyitja a sávot.
 * - A mérés azonosítója: az oj_ga4_id beállítás, különben G-WM1P5R67VJ; üresre állítva semmi nem kerül ki.
 * Az adatkezelési tájékoztató: az „adatkezelési-tájékoztató” oldal (a téma beállító lépése hozza létre).
 */

defined( 'ABSPATH' ) || exit;

const OJ_GA4 = 'G-WM1P5R67VJ';

function oj_ga4_id(): string {
	$id = (string) apply_filters( 'oj_ga4_id', (string) get_option( 'oj_ga4_id', OJ_GA4 ) );
	return preg_match( '/^G-[A-Z0-9]{6,12}$/', $id ) ? $id : '';
}

/** Az Ads Connect saját sávja: annak döntése számít, a miénk nem jelenik meg. */
function oj_consent_by_hpvac(): bool {
	return function_exists( 'hpvac_banner_enabled' ) && hpvac_banner_enabled();
}

add_action( 'wp_head', function () {
	$id = oj_ga4_id();
	if ( '' === $id || is_admin() ) {
		return;
	}
	$hpvac = oj_consent_by_hpvac();
	?>
<script>
window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
gtag('consent', 'default', { ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500 });
(function (w, d) {
	var ga = <?php echo wp_json_encode( $id ); ?>;
	w.oyLoadGa = function () {
		if (w.oyGaLoaded || !ga) { return; }
		w.oyGaLoaded = true;
		gtag('consent', 'update', { analytics_storage: 'granted' });
		gtag('js', new Date());
		gtag('config', ga, { linker: { domains: ['oliviajoga.hu', 'oliviajoga.online'] } });
		var s = d.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga);
		d.head.appendChild(s);
	};
<?php if ( $hpvac ) : ?>
	if (/(?:^|; )hpvac_choice=granted/.test(d.cookie)) { w.oyLoadGa(); }
	d.addEventListener('hpvac:consent', function (e) {
		if (e.detail && e.detail.marketing) { w.oyLoadGa(); } else if (w.oyGaLoaded) { gtag('consent', 'update', { analytics_storage: 'denied' }); }
	});
<?php else : ?>
	var m = /(?:^|; )oy_consent=([a-z]+)/.exec(d.cookie);
	if (m && /^(all|stats|granted)$/.test(m[1])) { w.oyLoadGa(); }
<?php endif; ?>
})(window, document);
</script>
	<?php
}, 1 );

add_action( 'wp_footer', function () {
	if ( '' === oj_ga4_id() || oj_consent_by_hpvac() || is_admin() ) {
		return;
	}
	$privacy = get_privacy_policy_url() ?: home_url( '/adatkezelesi-tajekoztato' );
	?>
<div class="oy-consent" id="oy-consent" role="dialog" aria-live="polite" aria-labelledby="oy-consent-title" hidden>
	<p class="oy-consent__text"><b id="oy-consent-title">Sütik</b> A működéshez szükséges sütik mindig működnek. Ha hozzájárulsz, a Google Analytics is méri, hogyan használják az oldalt, hogy jobbá tehessük. <a href="<?php echo esc_url( $privacy ); ?>" target="_blank" rel="noopener">Adatkezelési tájékoztató</a></p>
	<p class="oy-consent__buttons"><button type="button" class="btn btn--orchid" data-consent="stats">Elfogadom</button> <button type="button" class="btn btn--ghost" data-consent="denied">Csak a szükségesek</button></p>
</div>
<style>
.oy-consent { position: fixed; z-index: 1000; left: 16px; right: 16px; bottom: 16px; max-width: 620px; margin: 0 auto; padding: 18px 20px; border-radius: 22px; background: #FFFDFB; color: #1E3432; box-shadow: 0 10px 40px rgba(7, 38, 36, .22); }
.oy-consent[hidden] { display: none; }
.oy-consent__text { margin: 0 0 12px; font-size: .95rem; line-height: 1.55; }
.oy-consent__text b { display: block; margin-bottom: 2px; font-size: 1.02rem; }
.oy-consent__text a { color: inherit; text-decoration: underline; text-underline-offset: .2em; }
.oy-consent__buttons { display: flex; flex-wrap: wrap; gap: 10px; margin: 0; }
.oy-consent__buttons .btn { min-height: 44px; }
@media (max-width: 759px) { .oy-consent { left: 10px; right: 10px; bottom: calc(10px + env(safe-area-inset-bottom)); padding: 14px 16px; border-radius: 18px; } body:has([data-book-fab]) .oy-consent { bottom: calc(76px + env(safe-area-inset-bottom)); } .oy-consent__text { margin-bottom: 10px; font-size: .86rem; line-height: 1.45; } .oy-consent__buttons { gap: 8px; } .oy-consent__buttons .btn { flex: 1 1 0; min-width: 0; padding: .7em .6em; font-size: .74rem; white-space: normal; } }
</style>
<script>
(function (d, w) {
	var bar = d.getElementById('oy-consent');
	if (!bar) { return; }
	var m = /(?:^|; )oy_consent=([a-z]+)/.exec(d.cookie);
	if (!m || !/^(all|stats|ads|denied|granted)$/.test(m[1])) { bar.hidden = false; }
	function choose(v) {
		var exp = new Date(Date.now() + 365 * 864e5).toUTCString();
		d.cookie = 'oy_consent=' + v + '; expires=' + exp + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		bar.hidden = true;
		if (v === 'stats') { w.oyLoadGa && w.oyLoadGa(); } else if (w.oyGaLoaded) { w.gtag('consent', 'update', { analytics_storage: 'denied' }); }
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
}, 50 );
