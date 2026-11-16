<?php
/**
 * The studio guide (/studio/utmutato/), Hungarian – for Olívia, made for the phone (Áron, 16 November: „Csinálj
 * dokumentációt a stúdió működéséről”, „Írtam még mindent a hubról … legyen mobilra optimalizált. És legyen egy egyedi
 * link ehhez.”). Rendered by OYS_Studio_App::render_guide(); $app holds the links.
 *
 * @var array $app name, link, guide, hub, head, css, icon, manage
 */

defined( 'ABSPATH' ) || exit;

$link  = esc_html( preg_replace( '#^https?://#', '', $app['link'] ) );
$parts = array(
	'teendok'     => 'Az első lépéseid',
	'app'         => 'A Stúdió a telefonodon',
	'hub'         => 'A Hub',
	'uzenetek'    => 'Üzenetek',
	'ertesitesek' => 'Értesítések',
	'orak'        => 'Órák, naptár, foglalás',
	'elo'         => 'Az élő óra',
	'egyeni'      => 'Egyéni órák',
	'berletek'    => 'Bérletek, árak, kedvezmények',
	'vendegek'    => 'Vendégek és fizetések',
	'oktatok'     => 'Oktatók',
	'segitseg'    => 'Ha elakadsz',
);
?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Stúdió útmutató – <?php echo esc_html( $app['name']['name'] ); ?></title>
<?php echo $app['head']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
<link rel="stylesheet" href="<?php echo esc_url( $app['css'] ); ?>">
</head>
<body class="g">
<header class="g-bar">
	<a class="g-bar__brand" href="<?php echo esc_url( $app['hub'] ); ?>"><img src="<?php echo esc_url( $app['icon'] ); ?>" alt="" width="36" height="36"><span>Stúdió</span></a>
	<a class="g-btn g-btn--light" href="<?php echo esc_url( $app['hub'] ); ?>">A Hub megnyitása</a>
</header>

<main class="g-main">
	<section class="g-hero">
		<p class="g-kicker">Stúdió útmutató</p>
		<h1>Így működik a stúdiód az oliviajoga.online-on</h1>
		<p class="g-lead">Neked írtam, Olívia, hogy mindent egy helyen megtalálj. Kérlek, olvasd végig figyelmesen, a saját tempódban. Ha valami nem világos, vagy másképp szeretnéd, írd meg, és átalakítom. <span class="g-sign">– Áron</span></p>
	</section>

	<nav class="g-toc" aria-label="Tartalom">
		<p class="g-toc__h">Tartalom</p>
		<ol>
			<?php foreach ( $parts as $id => $title ) : ?>
				<li><a href="#<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<section class="g-sec g-sec--todo" id="teendok">
		<h2>Az első lépéseid</h2>
		<ol class="g-todo">
			<li><b>Olvasd el ezt az útmutatót figyelmesen, végig.</b> Ez a térképed: minden funkció benne van, és hogy mire való.</li>
			<li><b>Tedd ki a Stúdiót a telefonod kezdőképernyőjére</b>, és kapcsold be az értesítéseket (<a href="#app">lent leírom, hogyan</a>).</li>
			<li><b>Nézd át a Hubot és minden funkciót:</b> a Ma fület, az Üzeneteket, a Naptárt, a Több alatti oldalakat és a Beállításaimat. Nyugodtan kattints végig mindent, nem tudsz elrontani semmit.</li>
			<li><b>Utána nyiss egy új Google-doksit</b>, és írd bele az összes kérésedet, ötletedet és kérdésedet: mi hiányzik, mi működjön másképp. Oszd meg linkkel („Bárki, aki rendelkezik a linkkel”, megtekintő), és küldd el nekem a chatben.</li>
		</ol>
	</section>

	<section class="g-sec" id="app">
		<h2>A Stúdió a telefonodon</h2>
		<div class="g-link">
			<p>A saját linked a Hubhoz:</p>
			<a href="<?php echo esc_url( $app['link'] ); ?>"><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
			<p class="g-small">Ha nem vagy belépve, előbb a belépés jön, utána egyből a Hub.</p>
		</div>
		<p>A Stúdió <b>alkalmazásként</b> is a telefonodra tehető: saját ikonja van a logóddal, külön ablakban nyílik, és <b>értesítést küld</b>, ha valaki ír neked. Ehhez nem kell semmit letöltened az App Store-ból vagy a Google Playről.</p>
		<div class="g-cols">
			<div class="g-box">
				<h3>iPhone</h3>
				<ol>
					<li>Nyisd meg a <b><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput ?></b> linket a <b>Safariban</b>, és lépj be.</li>
					<li>Alul koppints a <b>Megosztás</b> gombra (a négyzet a felfelé mutató nyíllal).</li>
					<li>Válaszd a <b>„Főképernyőhöz adás”</b> lehetőséget, majd a <b>„Hozzáadás”</b> gombot.</li>
					<li>A kezdőképernyőn megjelenik a Stúdió ikonja. <b>Innen nyisd meg</b>, és a Hubban menj a <b>Beállításaim</b> fülre: <b>„Értesítések bekapcsolása”</b>, majd engedélyezd.</li>
				</ol>
				<p class="g-small">iPhone-on az értesítés csak a kezdőképernyőre tett appból kapcsolható be (iOS 16.4 vagy újabb kell hozzá).</p>
			</div>
			<div class="g-box">
				<h3>Android</h3>
				<ol>
					<li>Nyisd meg a <b><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput ?></b> linket a <b>Chrome-ban</b>, és lépj be.</li>
					<li>A Hubban a <b>Beállításaim</b> fülön koppints az <b>„App telepítése”</b> gombra. Ha nincs ilyen gomb: jobb felül a menü (⋮) → <b>„Alkalmazás telepítése”</b> vagy <b>„Hozzáadás a kezdőképernyőhöz”</b>.</li>
					<li>Nyisd meg a Stúdiót az új ikonnal, és a <b>Beállításaim</b> fülön kapcsold be az értesítéseket.</li>
				</ol>
			</div>
		</div>
		<h3>Miről kapsz értesítést a telefonodra?</h3>
		<ul>
			<li>Ha egy vendég <b>üzenetet ír</b> neked a fiókjából (fotóval is).</li>
			<li>Ha valaki a weboldal <b>kapcsolati űrlapján</b> ír.</li>
			<li>Ha valaki <b>egyéni órára kér időpontot</b>.</li>
			<li>Ha valaki az <b>órai chatbe</b> ír, de <b>nem az óra alatt</b> (akkor a chat úgyis előtted van).</li>
		</ul>
		<p>Az értesítésre koppintva egyből az adott beszélgetés nyílik meg. Ha éppen nyitva van előtted a Hub, nem csörög külön: ott látod. A <b>„Próba értesítés”</b> gombbal bármikor kipróbálhatod. Eszközönként kapcsolod be, így bekapcsolhatod a telefonodon és a gépeden is.</p>
	</section>

	<section class="g-sec" id="hub">
		<h2>A Hub</h2>
		<p>A Hub a stúdiód központja: itt látod a következő óráidat, és mindenkit, aki írt neked. Innen válaszolsz is. Telefonra tervezték, de a gépen is ugyanez, csak szélesebben: a lista, a beszélgetés és a vendég adatai egymás mellett.</p>
		<p>Alul (gépen bal oldalt) öt fül van:</p>
		<dl class="g-tabs">
			<dt>Ma</dt>
			<dd>
				<ul>
					<li><b>Rád vár:</b> hány új üzenet, olvasatlan órai chat és egyéni kérés van. Koppints rá, és odavisz.</li>
					<li><b>A következő órák:</b> mikor, hányan foglaltak, ki jön (egészségügyi megjegyzéssel, ha van), és hányan várnak a várólistán.</li>
					<li>A kártyán a gombok: <b>„Belépés a terembe”</b> (az óra idején <b>„Óra indítása”</b>), <b>„Névsor”</b> és <b>„Chat”</b>.</li>
					<li>Ha egy vendég nevére koppintasz, egyből írhatsz neki.</li>
					<li>Alul a számok: az elmúlt 30 nap bevétele, a következő 7 nap foglaltsága és a legutóbbi befizetések.</li>
				</ul>
			</dd>
			<dt>Üzenetek</dt>
			<dd>Minden beszélgetés és órai chat egy listában (<a href="#uzenetek">részletesen lent</a>).</dd>
			<dt>Naptár</dt>
			<dd>Az órák naptára: új óra, áthelyezés, lemondás (<a href="#orak">lent</a>).</dd>
			<dt>Több</dt>
			<dd>Minden más stúdióoldal egy koppintással: ez az útmutató, a Naptár, a Névsorok, az Egyéni kérések, a Vendégek, a Fizetések, az Árak és bérletek, az Ajándékutalványok, a Kuponok és jutalmak, a Hírlevél, az E-mailek és emlékeztetők, az Áttekintés, az Oktatók, a Beállítások, a weboldal megnyitása és a kilépés.</dd>
			<dt>Beállításaim</dt>
			<dd>
				<ul>
					<li><b>A Stúdió a telefonodon:</b> a saját linked (másolás gombbal), az app telepítése, az értesítések bekapcsolása és a próba.</li>
					<li><b>E-mailt kérek:</b> ha valaki ír neked, és ha valaki az órai chatbe ír (az óra alatt nem).</li>
					<li><b>A nevem az órai chatekben:</b> ezen a néven jelennek meg a válaszaid.</li>
					<li><b>A gyors válaszaim:</b> soronként egy mondat; a beszélgetésben a villám ikonnal egy koppintással beszúrod.</li>
				</ul>
				Ne felejtsd el a <b>„Mentés”</b> gombot.
			</dd>
		</dl>
		<p>A többi stúdióoldal is telefonra van igazítva: alul ugyanez a sáv látszik (Ma, Üzenetek, Naptár, Névsorok, Több), a táblázatok pedig kártyák, soronként egy.</p>
	</section>

	<section class="g-sec" id="uzenetek">
		<h2>Üzenetek</h2>
		<p>Minden beszélgetés egy listában van, a legújabb felül, az olvasatlanok kiemelve. A szűrők: <b>Mind</b>, <b>Olvasatlan</b>, <b>Beszélgetések</b>, <b>Órai chatek</b>, <b>Kérések</b>, <b>Kész</b>. Felül kereshetsz névre és szövegre.</p>
		<h3>Honnan jönnek az üzenetek?</h3>
		<ul>
			<li><b>A vendég fiókjából:</b> a fiókjában van egy <b>„Üzenetek”</b> fül, ott bármikor írhat neked, akár fotóval. A válaszodat ott látja, és e-mailben is megkapja.</li>
			<li><b>A weboldal kapcsolati űrlapjáról:</b> ezek „Weboldal” címkét kapnak. Ha a vendégnek még nincs fiókja, a válaszod e-mailben megy ki neki.</li>
			<li><b>Egyéni kérésekből:</b> a kérés összefoglalója (szolgáltatás, hossz, egyedül vagy párban, mikor ér rá, megjegyzés) bekerül a vendég beszélgetésébe, <b>„Ajánlat küldése”</b> gombbal.</li>
			<li><b>Az órai chatekből:</b> minden óra chatje külön sor, az olvasatlan üzenetek számával.</li>
		</ul>
		<h3>Válaszolás</h3>
		<ul>
			<li>Írd be a választ, és nyomd meg a küldés gombot.</li>
			<li><b>Kamera ikon:</b> fotót csatolsz (például egy póz helyes beállítását). A képet a rendszer kicsinyíti, hogy gyorsan betöltsön.</li>
			<li><b>Villám ikon:</b> a gyors válaszaid.</li>
			<li><b>Belső jegyzet:</b> ha bepipálod, amit írsz, csak te látod (például: „a bal térdére figyelni”).</li>
			<li><b>Kész:</b> lezárod a beszélgetést, és kikerül a listából. Ha a vendég újra ír, magától visszakerül.</li>
			<li><b>A vendég kártyája:</b> a beszélgetés mellett (telefonon az <b>„i”</b> gombbal): telefonszám (hívás gombbal), e-mail, egészségügyi megjegyzés, bérletek, foglalt órák, egyéni kérések, és egy gomb az adatlapjához.</li>
			<li><b>Új üzenet:</b> a <b>„+”</b> gombbal bármelyik vendégednek írhatsz: kezdd el beírni a nevét vagy az e-mail-címét.</li>
		</ul>
		<h3>Órai chat</h3>
		<ul>
			<li>Az óra chatje az óra után <b>7 napig</b> nyitva marad a résztvevőknek, és onnan is lehet fotót küldeni. Utána neked továbbra is írható.</li>
			<li>Ha óra után a Hubból írsz bele, azok, akik oda írtak, e-mailt kapnak róla.</li>
			<li>Bármelyik üzenetet törölheted az <b>„Eltávolítás”</b> gombbal.</li>
		</ul>
	</section>

	<section class="g-sec" id="ertesitesek">
		<h2>Értesítések</h2>
		<ul>
			<li><b>A telefonodon (app):</b> minden új üzenetről, kérésről és az órán kívüli órai chatről, azonnal (<a href="#app">bekapcsolás fent</a>).</li>
			<li><b>E-mailben:</b> ha valaki ír neked, vagy az órán kívül ír egy órai chatbe. Beszélgetésenként legfeljebb 15 percenként egyet, és egyet sem, amíg a Hub nyitva van, vagy amíg az óra tart. A Beállításaimban kikapcsolhatod.</li>
			<li>A stúdió többi e-mailjét (foglalás, emlékeztető, lemondás, ajánlat, ajándékutalvány…) az <b>E-mailek és emlékeztetők</b> oldalon kapcsolhatod ki-be, és a szövegüket is átírhatod.</li>
		</ul>
	</section>

	<section class="g-sec" id="orak">
		<h2>Órák, naptár, foglalás</h2>
		<ul>
			<li><b>Naptár</b> (Stúdió → Naptár): itt teszel fel új órát, helyezel át vagy mondasz le egyet. A heti órák (szerda 18:30–20:00) maguktól jönnek létre hetekkel előre.</li>
			<li><b>Foglalás:</b> a vendégek <b>6 héttel előre</b> foglalhatnak. A weboldal „Bejelentkezés és árak” oldalán egy naptár is pont ezt a 6 hetet mutatja.</li>
			<li><b>Legalább 3 fő:</b> ha 3 órával a kezdés előtt kevesebben vannak, a rendszer lemondja az órát, mindenkinek e-mailt küld, és visszaadja az alkalmat.</li>
			<li><b>Várólista:</b> ha egy óra megtelt, fel lehet iratkozni. Ha felszabadul egy hely, mindenki egyszerre kap e-mailt egy foglalás gombbal, és azé a hely, aki elsőként foglal. Senki nem kerül be magától.</li>
			<li><b>Lemondás:</b> csoportos órát 12, egyéni órát 24 órával előtte lehet ingyen lemondani.</li>
			<li><b>Névsor:</b> a Névsorok oldalon óránként látod, ki jön. Itt jelölöd, ki volt ott („Itt van”), felvehetsz valakit kézzel, és írhatsz mindenkinek, aki foglalt.</li>
		</ul>
	</section>

	<section class="g-sec" id="elo">
		<h2>Az élő óra</h2>
		<ul>
			<li>A terem a vendégeknek <b>10 perccel</b> a kezdés előtt nyílik, neked egy órával korábban. A Hub Ma fülén a kártyán nyomd meg a <b>„Belépés a terembe”</b>, ott pedig az <b>„Élő adás indítása”</b> gombot.</li>
			<li>A vendégek addig a <b>váróban</b> vannak: visszaszámlálást látnak, és egy rövid listát arról, mire figyeljenek (töltő, kamera, böngésző).</li>
			<li><b>Felvétel:</b> a csoportos óráról felvétel készül, de azon csak te látszol és hallatszol. A vendégek egy hétig nézhetik vissza a fiókjukban.</li>
			<li><b>Az óra eleji gondolataid:</b> a rendszer leírja és röviden összefoglalja őket, az összefoglaló az óra chatjében jelenik meg. Az óra után átírhatod vagy elrejtheted.</li>
		</ul>
	</section>

	<section class="g-sec" id="egyeni">
		<h2>Egyéni órák</h2>
		<ul>
			<li>A vendég a fiókjában kér időpontot: kiválasztja, mire (egyéni jóga, kismamatorna, stretching, relaxáció és légzés), milyen hosszan, egyedül vagy párban, és mikor ér rá. A kérést e-mailben is megkapod, a telefonodra értesítés jön, és bekerül a Hubba.</li>
			<li><b>Ajánlat:</b> a kérésnél az <b>„Ajánlat küldése”</b> gombbal megadod a napot, az időpontot és az árat. A vendég e-mailt kap, egy kattintással elfogadja, majd kifizeti, vagy a bérletéből foglalja le. A levélben az is benne van, hogy a kedvezménykódját a fizetésnél beválthatja.</li>
			<li>Az egyéni órát 24 órával előtte lehet áttenni vagy lemondani.</li>
		</ul>
	</section>

	<section class="g-sec" id="berletek">
		<h2>Bérletek, árak, kedvezmények</h2>
		<ul>
			<li>Az <b>Árak és bérletek</b> oldalon vannak az árak és a bérletek. Amit itt átírsz, az a weboldalon és a fizetésnél is azonnal érvényes.</li>
			<li>Az óraoldalakon minden ár mellett van <b>„Időpontot kérek”</b> és <b>„Bérletet veszek”</b> gomb, így a vendégek egyből kérhetnek vagy vásárolhatnak.</li>
			<li><b>Ha te mondasz le egy órát:</b> a bérletek alapból 7 nappal tovább érvényesek (Beállítások). Egyéni órás vendégnél ezt vendégenként is beállíthatod: Vendégek → az adatlapja → „Ha te mondod le az egyéni óráját”. Ha üresen hagyod, a 7 nap érvényes, ha 0-t írsz, nincs hosszabbítás.</li>
			<li><b>Kuponok és jutalmak:</b> kedvezménykódot készíthetsz (százalékos vagy összegre szóló, lejárattal, felhasználási korláttal).</li>
			<li><b>Ajándékutalvány:</b> a vendégek az oldalon vásárolják meg, a megajándékozott e-mailben kapja meg a kódot, és a fiókjában váltja be.</li>
		</ul>
	</section>

	<section class="g-sec" id="vendegek">
		<h2>Vendégek és fizetések</h2>
		<ul>
			<li><b>Vendégek:</b> minden vendégnek van adatlapja: adatok, egészségügyi megjegyzés, bérletek, foglalások, befizetések. Itt adhatsz neki órákat (ajándékba vagy készpénzes fizetéskor), és itt módosíthatod a bérlete lejáratát.</li>
			<li><b>Fizetések:</b> minden befizetés itt van. Visszatérítés: Fizetések → a befizetésnél a <b>„Visszatérítés”</b> gomb.</li>
			<li>A fizetés kártyával megy, a számla automatikusan elkészül.</li>
		</ul>
	</section>

	<section class="g-sec" id="oktatok">
		<h2>Oktatók</h2>
		<p>Ha később más oktató is tart órát, az <b>Oktatók</b> oldalon adhatsz neki belépést. Ő a saját Hubját látja: csak a saját óráinak a chatjeit és a saját beszélgetéseit. Ő is a telefonjára teheti a Stúdiót ugyanezzel a linkkel, beállíthatja a saját értesítéseit, a nevét a chatben és a gyors válaszait, és írhat a tanítványainak.</p>
	</section>

	<section class="g-sec" id="segitseg">
		<h2>Ha elakadsz</h2>
		<p>Írj nekem a portálon, ahogy eddig. A kéréseidet és ötleteidet pedig gyűjtsd egy Google-doksiba (lásd <a href="#teendok">az első lépéseidet</a>): így semmi nem vész el, és egyben meg tudom csinálni őket.</p>
		<p class="g-cta"><a class="g-btn" href="<?php echo esc_url( $app['hub'] ); ?>">A Hub megnyitása</a></p>
	</section>
</main>
<footer class="g-foot"><p>Stúdió útmutató · <?php echo esc_html( $app['name']['name'] ); ?> · ez az oldal: <?php echo esc_html( preg_replace( '#^https?://#', '', $app['guide'] ) ); ?></p></footer>
</body>
</html>
