<?php
/**
 * The studio guide (/studio/utmutato/), Hungarian – for Olívia: the first steps, the app on the phone, the Hub, and the
 * whole admin page by page (Áron, 16 November: „Csinálj dokumentációt a stúdió működéséről”, „Ne csak mobilos
 * útmutatót … hanem a teljes adminról már legyen, mert kell”). Readable on the phone and on a computer.
 * Rendered by OYS_Studio_App::render_guide(); $app holds the links.
 *
 * @var array $app name, link, guide, hub, head, css, icon, manage
 */

defined( 'ABSPATH' ) || exit;

$link   = esc_html( preg_replace( '#^https?://#', '', $app['link'] ) );
$admin  = fn( $page ) => esc_url( admin_url( 'admin.php?page=' . $page ) );
$groups = array(
	'Kezdés'              => array( 'teendok' => 'Az első lépéseid', 'app' => 'A Stúdió a telefonodon' ),
	'A Hub'               => array( 'hub' => 'A Hub fülei', 'heti-naptar' => 'Heti naptár', 'jelenleti-iv' => 'Óra után: jelenléti ív', 'kimutatasok' => 'Kimutatások', 'berletek-osszesito' => 'Bérletek és alkalmak', 'uzenetek' => 'Üzenetek', 'ertesitesek' => 'Értesítések' ),
	'Az admin, menüpontonként' => array(
		'menu'         => 'Az admin felépítése',
		'attekintes'   => 'Áttekintés',
		'naptar'       => 'Naptár',
		'nevsorok'     => 'Névsorok és lista',
		'keresek'      => 'Egyéni óra kérések',
		'vendegek'     => 'Vendégek',
		'tagsagok'     => 'Tagságok',
		'fizetesek'    => 'Fizetések',
		'ajandek'      => 'Ajándékutalványok',
		'arak'         => 'Árak és bérletek',
		'helyszinek'   => 'Helyszínek',
		'hirlevel'     => 'Hírlevél',
		'kuponok'      => 'Kuponok és jutalmak',
		'emailek'      => 'E-mailek és emlékeztetők',
		'beallitasok'  => 'Beállítások',
		'oktatok'      => 'Oktatók',
	),
	'A weboldal tartalma' => array( 'szerkesztes' => 'Szerkesztés a weboldalon', 'tartalmak' => 'Óratípusok, GYIK, cikkek, oldalak', 'ne' => 'Amihez ne nyúlj' ),
	'Hogyan működik'      => array( 'szabalyok' => 'Foglalás és lemondás', 'elo' => 'Az élő óra', 'egyeni' => 'Egyéni órák', 'segitseg' => 'Ha elakadsz' ),
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
		<p class="g-lead">Neked írtam, Olívia: az egész admin benne van, menüpontonként, hogy mindent egy helyen megtalálj. Telefonon és gépen is olvasható. Kérlek, olvasd végig figyelmesen, a saját tempódban. Ha valami nem világos, vagy másképp szeretnéd, írd meg, és átalakítom. <span class="g-sign">– Áron</span></p>
	</section>

	<nav class="g-toc" aria-label="Tartalom">
		<p class="g-toc__h">Tartalom</p>
		<?php foreach ( $groups as $group => $parts ) : ?>
			<p class="g-toc__group"><?php echo esc_html( $group ); ?></p>
			<ol>
				<?php foreach ( $parts as $id => $title ) : ?>
					<li><a href="#<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		<?php endforeach; ?>
	</nav>

	<!-- ====================================================================== Kezdés -->
	<section class="g-sec g-sec--todo" id="teendok">
		<h2>Az első lépéseid</h2>
		<ol class="g-todo">
			<li><b>Olvasd el ezt az útmutatót figyelmesen, végig.</b> Ez a térképed: minden funkció benne van, és hogy mire való.</li>
			<li><b>Tedd ki a Stúdiót a telefonod kezdőképernyőjére</b>, és kapcsold be az értesítéseket (<a href="#app">lent leírom, hogyan</a>).</li>
			<li><b>Nézd át a Hubot és az admin minden menüpontját:</b> nyugodtan kattints végig mindent, nem tudsz elrontani semmit. Amíg nem nyomsz „Mentés”, „Küldés” vagy „Lemondás” gombot, semmi nem változik.</li>
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
					<li>A kezdőképernyőn megjelenik a Stúdió ikonja. <b>Innen nyisd meg</b>, lépj be újra (az app külön emlékszik a belépésre), és a Hubban menj a <b>Beállításaim</b> fülre: <b>„Értesítések bekapcsolása”</b>, majd <b>„Engedélyezés”</b>.</li>
				</ol>
				<p class="g-small">iPhone-on az értesítés csak a kezdőképernyőre tett appból kapcsolható be, és iOS 16.4 vagy újabb kell hozzá (Beállítások → Általános → Szoftverfrissítés).</p>
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
		<p>Az értesítésre koppintva egyből az adott beszélgetés nyílik meg. A <b>„Próba értesítés”</b> gombbal bármikor kipróbálhatod. Eszközönként kapcsolod be, így a telefonodon és a gépeden is bekapcsolhatod. Ha egyszer letiltottad az értesítést, a telefon beállításaiban tudod újra engedélyezni.</p>
	</section>

	<!-- ====================================================================== A Hub -->
	<section class="g-sec" id="hub">
		<h2>A Hub fülei</h2>
		<p>A Hub a stúdiód központja (Stúdió → Hub, ez nyílik meg elsőként). Itt látod a következő óráidat, a bérletek állását és mindenkit, aki írt neked, és innen válaszolsz is. Telefonon alul, gépen bal oldalt öt fül van:</p>
		<dl class="g-tabs">
			<dt>Ma</dt>
			<dd>
				<ul>
					<li><b>Rád vár:</b> hány új üzenet, olvasatlan órai chat és egyéni kérés van. Koppints rá, és odavisz.</li>
					<li><b>A következő órák:</b> mikor, hányan foglaltak, ki jön (egészségügyi megjegyzéssel, ha van), és hányan várnak a várólistán. A kártyán: <b>„Belépés a terembe”</b> (az óra idején <b>„Óra indítása”</b>), <b>„Névsor”</b>, <b>„Chat”</b>. Egy névre koppintva egyből írhatsz neki.</li>
					<li><b>Óra után: ki jött el?</b> Az elmúlt két nap órái, ahol még van jelöletlen vendég: koppints rá, és kitöltöd a jelenléti ívet (<a href="#jelenleti-iv">lent</a>).</li>
					<li><b>Bérletek és alkalmak:</b> az összesítő (<a href="#berletek-osszesito">lent részletesen</a>).</li>
					<li><b>A számok:</b> az elmúlt 30 nap bevétele, a következő 7 nap foglaltsága, a vendégek száma és a legutóbbi befizetések.</li>
				</ul>
			</dd>
			<dt>Üzenetek</dt>
			<dd>Minden beszélgetés és órai chat egy listában (<a href="#uzenetek">lent</a>).</dd>
			<dt>Naptár</dt>
			<dd>A heti naptár: a hét minden órája, napról napra (<a href="#heti-naptar">lent</a>).</dd>
			<dt>Több</dt>
			<dd>A <b>Kimutatások</b>, ez az útmutató, az admin minden oldala egy koppintással (<a href="#menu">lent a lista</a>), a weboldal szerkesztése, a cikkek, a GYIK, az óraoldalak, a képek, a weboldal megnyitása és a kilépés. Így az appban nincs szükség a WordPress menüjére.</dd>
			<dt>Beállításaim</dt>
			<dd>
				<ul>
					<li><b>A Stúdió a telefonodon:</b> a saját linked (másolás gombbal), az app telepítése, az értesítések be- és kikapcsolása, próba-értesítés.</li>
					<li><b>E-mailt kérek:</b> ha valaki ír neked, és ha valaki az órai chatbe ír (az óra alatt nem).</li>
					<li><b>A nevem az órai chatekben:</b> ezen a néven jelennek meg a válaszaid.</li>
					<li><b>A gyors válaszaim:</b> soronként egy mondat; a beszélgetésben a villám ikonnal egy koppintással beszúrod.</li>
				</ul>
				Ne felejtsd el a <b>„Mentés”</b> gombot.
			</dd>
		</dl>
		<p>A többi stúdióoldal is telefonra van igazítva: alul ugyanez a sáv látszik (Ma, Üzenetek, Naptár, Névsorok, Több), a táblázatok pedig kártyák, soronként egy. Telefonon és az appban a Hub fölött nincs WordPress-fejléc: úgy néz ki, mint egy app.</p>
	</section>

	<section class="g-sec" id="heti-naptar">
		<h2>Heti naptár</h2>
		<p>A Hub <b>Naptár</b> fülén a hét minden napja látszik, hétfőtől vasárnapig, minden órával: mikor kezdődik, hányan foglaltak, és ha már véget ért, hogy kitöltötted-e a jelenléti ívet (<i>„3 jelöletlen”</i> vagy <i>„4-ból 4 eljött”</i>). A mai nap ki van emelve.</p>
		<ul>
			<li>Felül a nyilakkal lapozol a hetek között; az <b>„Ez a hét”</b> gomb visszahoz.</li>
			<li>Egy órára koppintva megnyílik a <a href="#jelenleti-iv">jelenléti íve</a>.</li>
			<li>Új órát, áthelyezést, lemondást az <b>„Új óra, áthelyezés, lemondás”</b> gomb nyit meg (a nagy naptár, <a href="#naptar">lent</a>).</li>
		</ul>
	</section>

	<section class="g-sec" id="jelenleti-iv">
		<h2>Óra után: jelenléti ív</h2>
		<p>Minden órának van jelenléti íve: a Ma fülön az óra kártyáján a <b>„Névsor”</b>, a Naptárban az órára koppintva, vagy óra után a Ma fülön az <b>„Óra után: ki jött el?”</b> résznél nyílik meg.</p>
		<ul>
			<li>Felül a számok: hányan jöttek el, hányan nem, és hány vendég jelöletlen még.</li>
			<li>Mindenki mellett két gomb: <b>✓ Itt volt</b> és <b>✕ Nem jött el</b>. Egy koppintás, és kész; ha mellényúltál, koppints újra.</li>
			<li><b>„Mindenki itt volt”</b>: aki még jelöletlen, az mind eljött. A legtöbbször ez az egy koppintás elég, és utána csak azt jelölöd, aki nem jött.</li>
			<li>Minden vendégnél látod, hogyan fizetett (bérlet, kártya, helyszínen), <b>hány alkalom maradt a bérletén</b>, és ha van, az egészségügyi megjegyzését.</li>
			<li><b>Hányadik órája:</b> mindenki mellett ott van, hányadik órája nálad („3. óra”). Akinek ez az első, annál egy rózsaszín <b>„Első óra”</b> címke áll, akinél 45 napnál több telt el az előző óta, annál egy sárga <b>„Rég nem járt”</b>: nekik jólesik egy személyes szó az óra elején.</li>
			<li><b>Valaki hozzáadása:</b> a lista alatt a <b>„Valaki hozzáadása”</b> gombbal bárkit felveszel az órára, óra előtt vagy után is (pl. ha valaki foglalás nélkül jött be). Írd be a nevét vagy az e-mail-címét, koppints rá, és kész: ha van bérlete, abból vonódik le, ha nincs, „a helyszínen fizet” lesz, és a <b>„Fizetve”</b> gombbal jelölöd, ha kifizette. Óra után hozzáadva egyből „itt volt”.</li>
			<li><b>Élő online óránál</b> azt is látod, ki volt bent a teremben (<i>„bent volt a teremben”</i> / <i>„nem lépett be a terembe”</i>).</li>
			<li><b>Magától is kitöltődik:</b> ha nem jelölöd, 3 órával az óra után a rendszer kitölti: aki bent volt az élő teremben, az „itt volt”, aki nem, az „nem jött el”. Amit te jelöltél, az úgy marad. (Beállítások → Foglalási szabályok, 0 = kikapcsolva.)</li>
			<li><b>Foglalt, de nem jött el:</b> a bérletes alkalom ilyenkor is elhasználódik (a foglaláskor vonódik le), ahogy eddig is. A „Nem jött el” jelölés a kimutatásokba kerül.</li>
			<li>Ha valaki a helyszínen fizet, mellette a <b>„Fizetve”</b> gombbal jelölöd, hogy kifizette. A kártyás befizetések számlája automatikusan elkészül, ehhez nem kell semmit tenned.</li>
			<li>Alul: <b>Belépés a terembe</b>, <b>Chat</b>, és a <b>Teljes névsor</b> (ott vehetsz fel valakit kézzel, és írhatsz mindenkinek).</li>
		</ul>
	</section>

	<section class="g-sec" id="kimutatasok">
		<h2>Kimutatások</h2>
		<p>A Hub <b>Több</b> fülén (és a Ma fülön a számok alatt) a <b>Kimutatások</b>: egy hónap egy képernyőn, a nyilakkal lapozol a hónapok között.</p>
		<ul>
			<li><b>A hónap számai:</b> órák, foglalások, ki jött el, ki foglalt, de nem jött el, a megjelenés aránya, hány különböző vendég járt, ki mondta le időben, a bevétel, az eladott vagy adott bérletek, az új vendégek.</li>
			<li><b>Óránként:</b> melyik órára hányan foglaltak és jöttek el, átlagosan hány fő volt egy órán.</li>
			<li><b>Mai születésnapok:</b> akinek ma van a születésnapja (ha megadta a fiókjában), egy „Felköszöntöm” gombbal.</li>
			<li><b>Törzsvendégek (90 nap):</b> a tíz leghűségesebb vendéged.</li>
			<li><b>Lemorzsolódók:</b> akik rendszeresen jártak, de az elmúlt 30 napban nem, és nincs foglalásuk. Egy kedves üzenet gyakran visszahozza őket; az <b>„Üzenet”</b> gombbal egyből írhatsz nekik.</li>
			<li><b>A hónap letöltése (Excel):</b> minden foglalás a hónapból (nap, óra, vendég, e-mail, fizetés, eljött-e), Excelben megnyitható fájlban.</li>
		</ul>
	</section>

	<section class="g-sec" id="berletek-osszesito">
		<h2>Bérletek és alkalmak</h2>
		<p>A Hub Ma fülén egy pillantással látod, hogy áll a stúdió a bérletekkel:</p>
		<ul>
			<li><b>Érvényes bérletek:</b> hány bérletben van még alkalom, és nem járt le; mellette, hogy összesen hány alkalom van rajtuk, és hány vendégnél.</li>
			<li><b>Zárolt alkalmak:</b> amiket a vendégek már lefoglaltak a bérletükből, de az óra még előttük van. Ezek le vannak vonva; ha időben lemondják az órát, visszakapják.</li>
			<li><b>Csoportos és online</b>, illetve <b>Egyéni órák:</b> a hátralévő alkalmak a két fajta bérletben külön.</li>
			<li><b>14 napon belül lejár:</b> azok a bérletek, amelyek két héten belül lejárnak, és még van rajtuk alkalom. Ha sárga, érdemes szólni a vendégeknek.</li>
			<li><b>Előttünk álló foglalások:</b> minden foglalás a következő órákra, bármilyen fizetéssel.</li>
			<li><b>Lejárt, fel nem használt (30 nap):</b> az elmúlt hónapban lejárt bérletek, amelyeken maradt alkalom.</li>
			<li><b>Be nem váltott ajándékutalványok</b>, és ha van: <b>aktív tagságok</b>, <b>fizetés alatt tartott helyek</b>.</li>
		</ul>
		<p>Alatta a „Minden vendég bérletei” link a Vendégek oldalra visz, ahol vendégenként látod a részleteket.</p>
	</section>

	<section class="g-sec" id="uzenetek">
		<h2>Üzenetek</h2>
		<p>Minden beszélgetés egy listában van, a legújabb felül, az olvasatlanok kiemelve. Szűrők: <b>Mind</b>, <b>Olvasatlan</b>, <b>Beszélgetések</b>, <b>Órai chatek</b>, <b>Kérések</b>, <b>Kész</b>. Felül kereshetsz névre és szövegre.</p>
		<h3>Honnan jönnek az üzenetek?</h3>
		<ul>
			<li><b>A vendég fiókjából:</b> a fiókjában van egy <b>„Üzenetek”</b> fül, ott bármikor írhat neked, akár fotóval. A válaszodat ott látja, és e-mailben is megkapja.</li>
			<li><b>A weboldal kapcsolati űrlapjáról:</b> „Weboldal” címkével. Ha a vendégnek még nincs fiókja, a válaszod e-mailben megy ki neki.</li>
			<li><b>Egyéni kérésekből:</b> a kérés összefoglalója bekerül a vendég beszélgetésébe, <b>„Ajánlat küldése”</b> gombbal.</li>
			<li><b>Az órai chatekből:</b> minden óra chatje külön sor, az olvasatlan üzenetek számával.</li>
		</ul>
		<h3>Válaszolás</h3>
		<ul>
			<li>Írd be a választ, és nyomd meg a küldés gombot.</li>
			<li><b>Kamera ikon:</b> fotót csatolsz (például egy póz helyes beállítását).</li>
			<li><b>Villám ikon:</b> a gyors válaszaid.</li>
			<li><b>Belső jegyzet:</b> ha bepipálod, amit írsz, csak te látod (például: „a bal térdére figyelni”).</li>
			<li><b>Kész:</b> lezárod a beszélgetést. Ha a vendég újra ír, magától visszakerül.</li>
			<li><b>A vendég kártyája</b> (telefonon az <b>„i”</b> gombbal): telefonszám (hívás gombbal), e-mail, egészségügyi megjegyzés, bérletek, foglalt órák, egyéni kérések, és egy gomb az adatlapjához.</li>
			<li><b>Új üzenet:</b> a <b>„+”</b> gombbal bármelyik vendégednek írhatsz.</li>
		</ul>
		<h3>Órai chat</h3>
		<ul>
			<li>Az óra chatje az óra után <b>7 napig</b> nyitva marad a résztvevőknek, fotóval is. Neked később is írható.</li>
			<li>Ha óra után a Hubból írsz bele, azok, akik oda írtak, e-mailt kapnak róla.</li>
			<li>Bármelyik üzenetet törölheted az <b>„Eltávolítás”</b> gombbal.</li>
		</ul>
	</section>

	<section class="g-sec" id="ertesitesek">
		<h2>Értesítések</h2>
		<ul>
			<li><b>A telefonodon (app):</b> minden új üzenetről, kérésről és az órán kívüli órai chatről, azonnal (<a href="#app">bekapcsolás fent</a>). És ezekről is, maguktól:
				<ul>
					<li><b>Új foglalás, lemondás, várólista:</b> ha valaki foglal, lemond vagy várólistára jelentkezik (amit te magad csinálsz, arról nem jön). Koppintásra megnyílik az óra jelenléti íve.</li>
					<li><b>Óra után: „ki jött el?”</b> – az óra vége után 10 perccel, ha még van jelöletlen vendég. Koppintásra egyből a jelenléti ív nyílik meg, és egy-egy koppintással kész.</li>
					<li><b>Minden reggel 7:30 körül:</b> a napod egy mondatban – a mai órák és hányan foglaltak, mi vár rád (üzenet, órai chat, kérés, kitöltendő jelenléti ív), és hányan jöttek el tegnap. Ha nincs semmi, nem jön.</li>
				</ul>
				Ezeket egyenként kikapcsolhatod: Hub → Beállításaim → <b>Értesítések a telefonon</b>.</li>
			<li><b>E-mailben:</b> ha valaki ír neked, vagy az órán kívül ír egy órai chatbe. Beszélgetésenként legfeljebb 15 percenként egyet, és egyet sem, amíg a Hub nyitva van, vagy amíg az óra tart. A Beállításaimban kikapcsolhatod.</li>
			<li>A stúdió többi levele (foglalás, emlékeztető, lemondás, ajánlat, ajándékutalvány…) az <a href="#emailek">E-mailek és emlékeztetők</a> oldalon kapcsolható ki-be.</li>
			<li><b>Két új levél a vendégeknek</b> (az Emlékeztetők között, <b>alapból kikapcsolva</b> – te döntöd el, kéred-e, és a szövegüket is átírhatod):
				<ul>
					<li><b>Hiányzol (aki rég nem járt):</b> annak, aki rendszeresen járt, de az elmúlt 30 napban nem, és nincs foglalása – egy kedves sor tőled és a következő órák. Egy vendég legfeljebb 60 naponta egyszer kapja.</li>
					<li><b>A jövő heti órák (hétvégén):</b> szombaton a hírlevél-feliratkozóknak a következő hét órái, foglalás gombbal (akinél kevés a hely, ott az is látszik). Csak annak megy, aki kérte a hírlevelet, és benne van a leiratkozó link.</li>
				</ul></li>
		</ul>
	</section>

	<!-- ====================================================================== Az admin -->
	<section class="g-sec" id="menu">
		<h2>Az admin felépítése</h2>
		<p>Az adminba az <b>oliviajoga.online/wp-admin</b> címen (vagy a saját linkeden) lépsz be. Bal oldalt (telefonon a bal felső ☰ gombbal) a menü. Neked ezek a fontosak:</p>
		<ul>
			<li><b>Stúdió</b> – minden, ami az órákról, vendégekről és fizetésről szól: Hub, Áttekintés, Naptár, Névsorok és lista, Egyéni óra kérések, Vendégek, Tagságok, Fizetések, Ajándékutalványok, Árak és bérletek, Helyszínek, Hírlevél, Kuponok és jutalmak, E-mailek és emlékeztetők, Beállítások, Oktatók. A „Stúdió” mellett a szám azt mutatja, hány dolog vár rád.</li>
			<li><b>Bejegyzések</b> – a cikkek (Jógáról).</li>
			<li><b>Média</b> – a feltöltött képek.</li>
			<li><b>Oldalak</b> – a weboldal oldalai.</li>
			<li><b>Óratípusok</b>, <b>GYIK</b>, <b>Korábbi események</b> – az óraoldalak, a kérdések és az események szövegei.</li>
			<li><b>Megjelenés → Szövegek és képek</b> – a weboldalon átírt szövegek és képek listája.</li>
		</ul>
		<p>A Stúdió minden oldala telefonon is használható: alul a sáv, a táblázatok kártyákká alakulnak.</p>
	</section>

	<section class="g-sec" id="attekintes">
		<h2>Áttekintés</h2>
		<p><a href="<?php echo $admin( 'oys-today' ); // phpcs:ignore ?>">Stúdió → Áttekintés</a>. A régi „Ma” oldal, táblázatosan:</p>
		<ul>
			<li><b>Ma:</b> a mai órák.</li>
			<li><b>Válaszra váró egyéni óra kérések.</b></li>
			<li><b>Következő 7 nap:</b> minden óra (mikor, milyen, hányan foglaltak, várólista, ár, állapot) <b>„Névsor”</b> és <b>„Szerkesztés”</b> gombbal.</li>
		</ul>
	</section>

	<section class="g-sec" id="naptar">
		<h2>Naptár</h2>
		<p><a href="<?php echo $admin( 'oys-calendar' ); // phpcs:ignore ?>">Stúdió → Naptár</a>. Gépen egy hét látszik, telefonon egy nap (fent a napok fülei). Felül: <b>‹ Ma ›</b> a hetek között, hány óra és foglalás van a héten, <b>„Lemondottak is”</b> és <b>„+ Új óra”</b>.</p>
		<ul>
			<li><b>Új óra:</b> kattints egy üres időpontra, vagy a „+ Új óra” gombra. Kiválasztod a típust (Óra, Esemény / workshop, Egyéni), az órát, a napot, a kezdést és a hosszt, hogy személyesen, online vagy mindkettő, a helyek számát és az árat. <b>Ismétlés: „Minden héten”</b> – így lesz belőle heti óra, ami magától létrejön hetekkel előre.</li>
			<li><b>Áthelyezés:</b> fogd meg az órát, és húzd át egy másik időpontra. <b>Hossz:</b> az óra alját húzd. Heti óránál megkérdezi: <b>„Csak ez a nap”</b> vagy <b>„Ez és a következő hetek”</b>; ha már van foglalás, bepipálhatod, hogy a foglalók e-mailt kapjanak.</li>
			<li><b>Egy órára kattintva</b> jobbra kinyílik: hányan foglaltak és kik, a minimális létszám állása, és a gombok: <b>„Névsor és jelenlét”</b>, <b>„Üzenet mindenkinek”</b>, <b>„Foglalási oldal”</b>. Itt is átírhatsz bármit, aztán <b>„Változások mentése”</b>.</li>
			<li><b>Lemondás:</b> az óránál <b>„Óra lemondása…”</b>. Minden foglaló e-mailt kap (megadhatsz indoklást), a bérletesek visszakapják az alkalmat. Heti óránál választhatsz: <b>„Ezt a napot lemondom”</b> vagy <b>„A heti óra leállítása”</b> (ezt és minden későbbit).</li>
			<li><b>Minimális létszám:</b> óránként is beállítható (üresen a helyszín alapértéke érvényes; <a href="#helyszinek">Helyszínek</a>).</li>
			<li>További lehetőségek az óránál: <b>„Bérlettel és tagsággal is foglalható”</b>, <b>„Adomány alapú”</b> ár, rövid megjegyzés az órarendhez, oktató.</li>
		</ul>
	</section>

	<section class="g-sec" id="nevsorok">
		<h2>Névsorok és lista</h2>
		<p><a href="<?php echo $admin( 'oys-schedule' ); // phpcs:ignore ?>">Stúdió → Névsorok és lista</a>. Az órák listája: <b>Közelgő</b> és <b>Korábbiak</b>. Egy órára (<b>„Névsor”</b>) kattintva:</p>
		<ul>
			<li><b>Ki jön:</b> név, telefon, fizetés módja, egészségügyi megjegyzés, állapot. Jelenlét: <b>„Itt van”</b> vagy <b>„Nem jött el”</b>. Egy foglalást le is mondhatsz (az alkalom visszajár).</li>
			<li><b>Valaki hozzáadása:</b> e-mail vagy név alapján felveszel valakit kézzel: a bérletéből, „a helyszínen fizetett”, „a helyszínen fog fizetni” vagy ajándékba; kérhetsz visszaigazoló e-mailt.</li>
			<li><b>Üzenet minden foglalónak:</b> tárgy és üzenet, és választhatod, hogy a várólista is megkapja.</li>
			<li><b>Várólista:</b> kik várnak, és kaptak-e már értesítést.</li>
			<li>Online óránál: <b>„Az élő terem megnyitása (oktató)”</b>.</li>
		</ul>
	</section>

	<section class="g-sec" id="keresek">
		<h2>Egyéni óra kérések</h2>
		<p><a href="<?php echo $admin( 'oys-private' ); // phpcs:ignore ?>">Stúdió → Egyéni óra kérések</a>. Minden kérés: mikor jött, ki küldte, mit kér, állapot. Egy kérést megnyitva látod, mire, milyen hosszan, egyedül vagy párban, mikor ér rá, a megjegyzését és az egészségügyi megjegyzését.</p>
		<ul>
			<li><b>Ajánlat küldése:</b> nap és időpont, hossz, ár, online link (ha kell), üzenet. Az első óránál a rendszer felajánl plusz perceket a célok átbeszélésére (nem kerül pénzbe). <b>„Ajánlat küldése e-mailben”</b> – a vendég egy kattintással elfogadja, majd kifizeti vagy a bérletéből foglal.</li>
			<li><b>Ajánlat módosítása</b>, ha közben változik valami.</li>
			<li><b>Elutasítás</b> (üzenettel): a vendég e-mailt kap.</li>
			<li>Ugyanez a Hubból is megy: a kérés a vendég beszélgetésében is ott van.</li>
		</ul>
	</section>

	<section class="g-sec" id="vendegek">
		<h2>Vendégek</h2>
		<p><a href="<?php echo $admin( 'oys-customers' ); // phpcs:ignore ?>">Stúdió → Vendégek</a>. A vendégek listája (név, e-mail, telefon, hátralévő órák, hátralévő egyéni órák, mióta van fiókja), kereséssel. Egy vendégre kattintva az <b>adatlapja</b>:</p>
		<ul>
			<li><b>Adatok:</b> elérhetőség, vészhelyzeti kontakt, egészségügyi megjegyzés, részvételi nyilatkozat, hírlevél.</li>
			<li><b>Bérletek:</b> mindegyik bérlete, hány alkalom maradt, meddig érvényes – itt át is írhatod (<b>„Frissítés”</b>).</li>
			<li><b>Órák adása:</b> csoportos vagy egyéni alkalmak, érvényességgel és megjegyzéssel (pl. „Készpénzzel fizetve”, ajándék).</li>
			<li><b>„Ha te mondod le az egyéni óráját”:</b> hány nappal legyen tovább érvényes a bérlete (üres = az alap 7 nap, 0 = nincs hosszabbítás).</li>
			<li><b>Tagságok, foglalások, befizetések</b> listája.</li>
			<li><b>Helyszíni fizetés:</b> ha valaki többször nem jött el egy „helyszínen fizetek” foglalásra, a rendszer előre fizetésre állítja; itt újra engedélyezheted.</li>
		</ul>
		<p><b>Importálás:</b> a lista tetején a Motibro exportjai (.xlsx vagy .csv) beolvashatók, a rendszer az oszlopaikról ismeri fel őket:</p>
		<ul>
			<li><b>Vendégek:</b> minden adatuk (név, e-mail, telefon, születésnap, számlázási adatok, megjegyzés…).</li>
			<li><b>Érvényes bérletek:</b> ami a bérleten még hátravan, online alkalomként kerül a vendég fiókjába, ugyanaddig a napig érvényesen. A Motibróban zárolt alkalmak (amikre ott már foglaltak) ott maradnak. Ha később egy frissebb exportot olvasol be, ugyanazokat a bérleteket frissíti, nem duplázza.</li>
			<li><b>Óralátogatások:</b> minden korábbi óra és látogatás a vendég történetébe kerül (eljött, „Korábbi rendszer”). Így a jelenléti íven a „hányadik órája” a régi órákat is számolja, és a törzsvendégek, a lemorzsolódók és a havi kimutatások is a teljes múltat mutatják.</li>
		</ul>
		<p>Előbb a <b>„Csak ellenőrzés”</b> megmutatja, mi változna. Senki nem kap e-mailt vagy értesítést, és ugyanaz a fájl többször is beolvasható.</p>
	</section>

	<section class="g-sec" id="tagsagok">
		<h2>Tagságok</h2>
		<p><a href="<?php echo $admin( 'oys-members' ); // phpcs:ignore ?>">Stúdió → Tagságok</a>. Havidíjas tagságok (ha bevezeted őket): ki a tag, melyik csomag, állapot, mennyit használt ebben az időszakban. Egy tagság <b>az időszak végén megszüntethető</b>, <b>azonnal leállítható</b>, vagy <b>folytatható</b>. A csomagok és árak az <a href="#arak">Árak és bérletek</a> oldalon vannak.</p>
	</section>

	<section class="g-sec" id="fizetesek">
		<h2>Fizetések</h2>
		<p><a href="<?php echo $admin( 'oys-orders' ); // phpcs:ignore ?>">Stúdió → Fizetések</a>. Minden befizetés: dátum, vendég, mit vett, összeg, állapot. Szűrők: Mind, Fizetés elkezdve, Fizetve, Félbehagyva, Sikertelen, Visszatérítve, Részben visszatérítve.</p>
		<ul>
			<li><b>Nyugta:</b> a befizetés nyugtája megnyitható, kinyomtatható.</li>
			<li><b>Visszatérítés:</b> a befizetésnél a <b>„Visszatérítés”</b> gomb (megerősítés után a pénz visszamegy a vendég kártyájára).</li>
			<li>A „Félbehagyva” azt jelenti, hogy valaki elkezdte a fizetést, de nem fejezte be; a helyét a rendszer felszabadította.</li>
		</ul>
	</section>

	<section class="g-sec" id="ajandek">
		<h2>Ajándékutalványok</h2>
		<p><a href="<?php echo $admin( 'oys-gifts' ); // phpcs:ignore ?>">Stúdió → Ajándékutalványok</a>. Minden eladott utalvány: kód, mit ajándékoztak, ki vette, kinek szól, állapot, ki váltotta be. A vendégek az oldalon veszik meg; a megajándékozott e-mailben kapja a kódot, és a fiókjában váltja be.</p>
	</section>

	<section class="g-sec" id="arak">
		<h2>Árak és bérletek</h2>
		<p><a href="<?php echo $admin( 'oys-products' ); // phpcs:ignore ?>">Stúdió → Árak és bérletek</a>. Minden, amit meg lehet venni: alkalmi jegyek, bérletek (csoportos, egyéni, páros, kismama, stretching, relaxáció), 60 és 90 perces változatok.</p>
		<ul>
			<li>Bérletenként átírhatod a nevét, az árát, az alkalmak számát és az érvényességét.</li>
			<li><b>„Megvásárolható”</b> – látszik-e az oldalon; <b>„Ajándékba is megvehető”</b>; <b>„Kiemelés: legnépszerűbb”</b>.</li>
			<li><b>„Bérlet hozzáadása”</b> új bérlethez, <b>„Törlés”</b> a régihez (a már eladott bérletek megmaradnak).</li>
			<li>Amit itt átírsz és elmentesz, az a weboldalon és a fizetésnél is azonnal érvényes.</li>
		</ul>
	</section>

	<section class="g-sec" id="helyszinek">
		<h2>Helyszínek</h2>
		<p><a href="<?php echo $admin( 'oys-locations' ); // phpcs:ignore ?>">Stúdió → Helyszínek</a>. Az online órák is egy „helyszín”. Helyszínenként: <b>minimális létszám</b> és hogy <b>hány órával előtte</b> döntsön a rendszer. Ha addig kevesebben vannak, az óra magától elmarad, mindenki e-mailt kap más időpontokkal, és visszakapja az alkalmat. Most: online órákon legalább 3 fő, a döntés 3 órával előtte.</p>
	</section>

	<section class="g-sec" id="hirlevel">
		<h2>Hírlevél</h2>
		<p><a href="<?php echo $admin( 'oys-newsletter' ); // phpcs:ignore ?>">Stúdió → Hírlevél</a>. Hírlevelet írhatsz azoknak, akik feliratkoztak rá. Megírhatod magad, vagy a mesterséges intelligencia megírja a vázlatot (MI-beállítások: a hangnem, amit tudnia kell rólad). Előnézet és próbalevél után küldöd; kis adagokban megy ki, minden levélben van leiratkozó link.</p>
	</section>

	<section class="g-sec" id="kuponok">
		<h2>Kuponok és jutalmak</h2>
		<p><a href="<?php echo $admin( 'oys-rewards' ); // phpcs:ignore ?>">Stúdió → Kuponok és jutalmak</a>.</p>
		<ul>
			<li><b>Kedvezménykódok:</b> százalékos vagy összegre szóló kód, lejárattal és felhasználási korláttal (<b>„Kód létrehozása”</b>). A vendég a fizetésnél váltja be; a listában látod, hányszor használták.</li>
			<li><b>Születésnapi ajándékkód:</b> ha be van kapcsolva, a vendég a születésnapján kap egy személyes kedvezménykódot.</li>
			<li><b>Hűségsorsolás:</b> ha be van kapcsolva, minden óra egy sorsjegy; az időszak végén egy vendég ingyen bérletet nyer.</li>
		</ul>
	</section>

	<section class="g-sec" id="emailek">
		<h2>E-mailek és emlékeztetők</h2>
		<p><a href="<?php echo $admin( 'oys-emails' ); // phpcs:ignore ?>">Stúdió → E-mailek és emlékeztetők</a>. Minden levél, amit a stúdió küld, csoportosítva: emlékeztetők, foglalások, fiók/bérletek/ajándékok, egyéni órák, értesítések neked.</p>
		<ul>
			<li>Levelenként <b>ki-be kapcsolható</b>, látod, mikor megy ki és kinek.</li>
			<li><b>„Szöveg szerkesztése”:</b> átírhatod a tárgyat, a címet, az üzenetet, a záró megjegyzést és a gomb feliratát. <b>„Próba küldése”</b> – megkapod magadnak, hogy lásd.</li>
			<li>Az <b>Emlékeztetők</b> között két új levél van, alapból kikapcsolva: <b>Hiányzol (aki rég nem járt)</b> és <b>A jövő heti órák (hétvégén)</b>. Ha kéred őket, kapcsold be, és küldj magadnak egy próbát (<a href="#ertesitesek">részletek</a>).</li>
			<li>Fent: a feladó neve és címe, és a levélküldő szerver (ehhez ne nyúlj).</li>
		</ul>
	</section>

	<section class="g-sec" id="beallitasok">
		<h2>Beállítások</h2>
		<p><a href="<?php echo $admin( 'oys-settings' ); // phpcs:ignore ?>">Stúdió → Beállítások</a>. Szakaszok:</p>
		<ul>
			<li><b>Fizetés:</b> a kártyás fizetés beállítása (ehhez ne nyúlj).</li>
			<li><b>Foglalási szabályok:</b> meddig lehet ingyen lemondani (csoportos 12, egyéni 24 óra), meddig foglalható előre (6 hét), mikor zár a foglalás, hány nappal tolódik a bérlet, ha te mondasz le, várólista.</li>
			<li><b>Helyszíni fizetés és adomány:</b> ki foglalhat most és fizethet a helyszínen.</li>
			<li><b>Egyéni órák:</b> plusz idő az első órához, megjegyzés a kérő űrlapon.</li>
			<li><b>Közösség:</b> a Facebook-csoport linkje (a levelek alján és a fiókban is látszik).</li>
			<li><b>Online órák / Élő online órák:</b> mikor nyílik a terem, kamerák, felvétel és meddig nézhető vissza. Itt van a <b>„Próbaóra megnyitása most”</b> gomb is, ha ki szeretnéd próbálni a termet.</li>
			<li><b>Részvételi nyilatkozat</b> és <b>E-mailek</b>.</li>
		</ul>
		<p>Ne felejtsd el alul a <b>„Módosítások mentése”</b> gombot. Ha bizonytalan vagy egy beállításban, kérdezz előbb.</p>
	</section>

	<section class="g-sec" id="oktatok">
		<h2>Oktatók</h2>
		<p><a href="<?php echo $admin( 'oys-teachers' ); // phpcs:ignore ?>">Stúdió → Oktatók</a>. Ha később más oktató is tart órát, itt adhatsz neki belépést, és beállíthatod a részesedését. Ő a saját Hubját látja: csak a saját óráinak a chatjeit és a saját beszélgetéseit; a „Tanítás” menüben a saját óráit, profilját és elszámolását. Ő is a telefonjára teheti a Stúdiót ugyanezzel a linkkel.</p>
	</section>

	<!-- ====================================================================== A weboldal -->
	<section class="g-sec" id="szerkesztes">
		<h2>Szerkesztés a weboldalon</h2>
		<p>A weboldal szövegeit és képeit <b>magán az oldalon</b> írod át, ahogy egy dokumentumot:</p>
		<ol>
			<li>Belépve nyisd meg az oldalt, és nyomd meg a <b>„✎ Oldal szerkesztése”</b> gombot (fent az admin sávban vagy a lap sarkában).</li>
			<li>Kattints egy címre, bekezdésre vagy listára, és írd át (félkövér, dőlt, sortörés is megy).</li>
			<li>Képre kattintva a Médiatárból cserélheted, és beállíthatod, a kép melyik része látsszon. Az <b>„Eredeti”</b> visszaállítja.</li>
			<li>Mentés: alul a sávban, vagy Ctrl+S.</li>
		</ol>
		<p>Az átírt szövegek listája: <b>Megjelenés → Szövegek és képek</b>; itt egyenként visszaállíthatod őket, vagy visszaléphetsz az előző mentésre. Ami adat (órarend, árak, foglalás, bérletek, űrlapok), az nem itt, hanem a Stúdió menüben változik.</p>
	</section>

	<section class="g-sec" id="tartalmak">
		<h2>Óratípusok, GYIK, cikkek, oldalak</h2>
		<ul>
			<li><b>Óratípusok:</b> az óraoldalak (csoportos flow jóga, egyéni, kismamatorna, stretching, relaxáció) szövegei és képei.</li>
			<li><b>GYIK:</b> a gyakori kérdések; újat a <b>„Kérdés hozzáadása”</b> gombbal írhatsz.</li>
			<li><b>Korábbi események:</b> a régi események képei és leírása.</li>
			<li><b>Bejegyzések:</b> a cikkek (Jógáról). Új cikk: <b>„Bejegyzés hozzáadása”</b>; a kategória dönti el, melyik téma alá kerül (és milyen színű).</li>
			<li><b>Oldalak</b> és <b>Média:</b> az oldalak és a feltöltött képek. Képet ide is feltölthetsz.</li>
			<li><b>Megjelenés → Menük:</b> a weboldal menüje.</li>
		</ul>
	</section>

	<section class="g-sec" id="ne">
		<h2>Amihez ne nyúlj</h2>
		<p>Ezek a weboldal működéséhez kellenek; ha itt kell valamit változtatni, szólj nekem:</p>
		<ul>
			<li><b>Bővítmények</b>, <b>Megjelenés → Sablonok, Template-ek, Pattern-ek, HelloProVision frissítés</b>.</li>
			<li><b>Beállítások</b> (a WordPress sajátjai, főleg a <b>Közvetlen hivatkozások</b>), <b>Eszközök</b>.</li>
			<li><b>Stúdió → Beállítások → Fizetés</b> és a levélküldő szerver az E-mailek oldalon.</li>
			<li><b>Felhasználók:</b> vendéget ne törölj innen; a vendégeket a Stúdió → Vendégek oldalon kezeld.</li>
		</ul>
	</section>

	<!-- ====================================================================== Hogyan működik -->
	<section class="g-sec" id="szabalyok">
		<h2>Foglalás és lemondás</h2>
		<ul>
			<li><b>Foglalás:</b> a vendégek <b>6 héttel előre</b> foglalhatnak. A „Bejelentkezés és árak” oldalon egy naptár is ezt a 6 hetet mutatja.</li>
			<li><b>Legalább 3 fő:</b> ha 3 órával a kezdés előtt kevesebben vannak, a rendszer lemondja az órát, mindenkinek e-mailt küld, és visszaadja az alkalmat.</li>
			<li><b>Várólista:</b> ha egy óra megtelt, fel lehet iratkozni. Ha felszabadul egy hely, mindenki egyszerre kap e-mailt egy foglalás gombbal, és azé a hely, aki elsőként foglal. Senki nem kerül be magától.</li>
			<li><b>Lemondás:</b> csoportos órát 12, egyéni órát 24 órával előtte lehet ingyen lemondani; az alkalom visszajár.</li>
			<li><b>Ha te mondasz le egy órát:</b> a bérletek 7 nappal tovább érvényesek; egyéni órás vendégnél ez vendégenként állítható (Vendégek → adatlap).</li>
			<li><b>Kedvezménykód:</b> a fizetésnél váltható be.</li>
		</ul>
	</section>

	<section class="g-sec" id="elo">
		<h2>Az élő óra</h2>
		<ul>
			<li>A terem a vendégeknek <b>10 perccel</b> a kezdés előtt nyílik, neked egy órával korábban. A Hub Ma fülén a kártyán <b>„Belépés a terembe”</b>, ott pedig <b>„Élő adás indítása”</b>.</li>
			<li>A vendégek addig a <b>váróban</b> vannak: visszaszámlálást látnak, és egy rövid listát arról, mire figyeljenek.</li>
			<li><b>Felvétel:</b> a csoportos óráról felvétel készül, de azon csak te látszol és hallatszol. A vendégek egy hétig nézhetik vissza a fiókjukban.</li>
			<li><b>Az óra eleji gondolataid:</b> a rendszer leírja és röviden összefoglalja őket az óra chatjébe. Az óra után átírhatod vagy elrejtheted.</li>
		</ul>
	</section>

	<section class="g-sec" id="egyeni">
		<h2>Egyéni órák</h2>
		<ul>
			<li>A vendég a fiókjában kér időpontot: mire (egyéni jóga, kismamatorna, stretching, relaxáció és légzés), milyen hosszan, egyedül vagy párban, mikor ér rá. E-mailt és értesítést kapsz, és bekerül a Hubba.</li>
			<li>Az <b>„Ajánlat küldése”</b> gombbal megadod a napot, az időpontot és az árat. A vendég e-mailben egy kattintással elfogadja, majd kifizeti, vagy a bérletéből foglalja le.</li>
			<li>Az óraoldalakon minden ár mellett van <b>„Időpontot kérek”</b> és <b>„Bérletet veszek”</b> gomb.</li>
		</ul>
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
