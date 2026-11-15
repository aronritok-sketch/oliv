# Olivia Kovács Yoga – munkajegyzet

## Hol tartunk (2026-09-25)
- **2. kör: „Urban flow” redizájn** – az ügyfél szerint jó irány, a következő körben a dizájnt finomhangoljuk.
- A felépítés (szekciók sorrendje, 19 oldal, szövegek) nem változott; csak a megjelenés.

## Linkek
- Új előnézet (v2, urbánus): https://claude.ai/artifact/2WdjpzEKoZV6E7yuDejYja
- Régi előnézet (v1, organikus/festett): https://claude.ai/artifact/1EBXutisTQ8sCXr5X411iU
- Letölthető egyfájlos verzió: `olivia-kovacs-yoga.html` (a repó gyökerében)

## Dizájn döntések
- **Zöldek megtartva** (ügyfél kérése): erdő `#2B5036`, moha `#1B3324`, páfrány `#5B8560`, zsálya `#A9BFA0`, halvány `#DEE7D6`.
- **Új akcentek** az inspókból: lila `#C6A3EE` (Workshop-poszter), pink `#FF72B6` / gomb-pink `#D92B86` (Plasztik poharak), barack `#FF8C42` (naplemente, „Tickets out now”).
- **Betűk:** Anton (plakát-címek, nagybetűs) + Archivo (szöveg; széles változat a kis címkékhez).
- **Elemek:** éles fotók színes árnyékblokkal, vastag fekete vonalak, futószalag (marquee), forgó kerek matricák, színes csempék, Órarend-inspó szerinti heti órarend (nagy MON/TUE…).
- Színrotáció: `ACCENTS` a `src/build.py`-ban; minden szín token a `src/site.css` elején (`:root`).

## Munkafolyamat
- Generálás: `cd src && python3 build.py` → `site/` (többoldalas), `preview.html` (artifact), `olivia-kovacs-yoga.html` (letölthető).
- A preview-hoz Pillow kell: `pip install pillow`.
- Az artifact frissítése: a `preview.html`-t kell újra publikálni a v2 linkre (`url` paraméterrel), hogy a link ne változzon.

## WordPress verzió (2026-09-26)
- `wordpress/olivia-yoga` – téma (az Urban flow dizájn), `wordpress/olivia-studio` – foglalás + fizetés plugin.
- Telepíthető csomagok: `wordpress/dist/*.zip`; teljes leírás és élesítési lista: `wordpress/README.md`.
- Tudja: órarend és helyfoglalás, Stripe fizetés (kártya/Apple Pay/Google Pay), bérletek és kreditek, várólista automatikus beléptetéssel,
  lemondási szabály, részvételi nyilatkozat, magánóra kérés → ajánlat → fizetés, ajándékkártya, ügyfélfiók, e-mailek naptármelléklettel,
  emlékeztető, admin (napi nézet, névsor/jelenlét, ügyfelek, fizetések és visszatérítés, árak, beállítások).
- Tesztelve helyben (WordPress 6.8.3 + MariaDB + Stripe-szimulátor).
- Fejlesztői dokumentáció: `wordpress/DEVELOPER.md`.
- Élesítéshez kell: tárhely, Stripe fiók + kulcsok + webhook, SMTP, cron, valós órarend/árak, ügyvéd a nyilatkozathoz.

## 3. kör: tagság, vendégek, tesztek (2026-09-26)
- **Havi tagság** Stripe előfizetéssel: korlátlan ($119/hó) és havi 4 óra ($85/hó) – MINTA árak. Fiókban keret, következő terhelés,
  Stripe ügyfélportál, lemondás periódus végére / visszavonás; admin: Studio → Memberships, MRR.
- **Vendégek:** foglaláskor vagy utólag több vendég (alap max. 4); bérletből fejenként 1 alkalom, kártyánál egy fizetés, számlán „Guest ticket × N”;
  névsoron, leveleken, emlékeztetőn név szerint; vendég-meghívó e-mail; vendég külön lemondható, a foglaló lemondása viszi a vendégeket is.
- **Biztonság:** belépési próbálkozások korlátozása, regisztráció-korlát.
- **Tesztek:** 77 integrációs + 93 böngészős ellenőrzés, `wordpress/dev/ci.sh` nulláról; GitHub Actions minden pushnál.

## 4. kör: hibajavítás, online órák, naptár (2026-09-26)
- **Hiba javítva:** kártyás fizetés elkezdve, de nem befejezve (böngésző Vissza gomb) → az óra „foglaltnak” látszott. Most nem számít foglalásnak;
  „Continue to payment” gomb, vagy újrafoglalás (a régi Stripe fizetés lezárul, dupla terhelés nincs).
- **Online órák:** $6 drop-in (beállítás), online bérlet 10 óra $50 (MINTA), 1 stúdióalkalom = 4 online óra (beállítás: Studio → Settings → Online classes),
  tagságban benne van és nem számít a havi keretbe; online magánóra olcsóbb (60/75/90 perc: $65/$80/$95 MINTA).
- **Admin naptár:** Studio → Calendar – kattintós, húzható heti nézet, heti ismétlés, „csak ez a nap / ez és a következő hetek”, e-mail a foglalóknak.
- Tesztek: 120 integrációs + 128 böngészős ellenőrzés.

## 5. kör: hibrid órák, Zoom, e-mailek (2026-09-26)
- **Hibrid óra:** stúdióóra élőben is követhető; foglaláskor választ (stúdió $25 / online $6), külön helyek; online jegy online kreditből vagy átváltással.
- **Zoom automatikusan:** Server-to-Server OAuth app kell (Studio → Settings → Zoom). Meeting az első online foglaláskor jön létre; áthelyezésnél
  mozog, lemondásnál törlődik; Olivia a névsorból / naptárból indítja; opcionálisan személyes link mindenkinek. Élő Zoommal még NEM teszteltük.
- **Emails & reminders oldal:** minden levél ki/be, szöveg szerkeszthető, előnézet, tesztlevél; emlékeztetők: óra előtt (2 időpont), online link, bérletlejárat.
- Tesztek: 192 integrációs + 150 böngészős ellenőrzés.
- Következő nagy lépés: mobilapp az aktív felhasználóknak (API + élő óra az appban) – a hibrid/Zoom rész erre már felkészült.

## 6. kör: iOS app (2026-09-26)
- **Mobil API** a pluginban (`oys/v1/app/*`): tokenes belépés, órarend, foglalás, várólista, lemondás, bérletek, profil.
- **iOS app** az `app/` mappában (Expo / React Native): órarend, foglalás bérlettel / tagsággal / kártyával (Stripe a böngészőben),
  hibrid óra online módban, „Join the live class” gomb 60 perccel óra előtt, saját órák, bérletek, profil. Márkaszínek, Anton + Archivo.
- Kiadás: EAS Build → TestFlight (Mac nem kell). Kell hozzá: Apple Developer fiók (99 USD/év), Expo fiók, valódi domain az `app.json`-ban.
- **App Store előtt még kell:** fióktörlés az appban; végleges ikon; képernyőképek; adatvédelmi szöveg.
- Tesztek: 227 integrációs + 150 böngészős + 17 app unit + 36 app végpont-ellenőrzés (iPhone-méretű ablakban).
- Következő ötletek: push értesítések (emlékeztető, várólistás hely), natív bérletvásárlás, Zoom az appon belül.

## 7. kör: Olivia kérései, 1. rész (2026-09-27)
- **Fizetés a helyszínen:** mindenki foglalhat most, és fizethet a helyszínen (cash / Venmo / Zelle); 2 kihagyott, ki nem fizetett óra után csak előre. A névsorban „Paid: cash / other”.
- **Adomány-alapú óra:** óránként bekapcsolható, min. $5, javasolt összegek; kártyával vagy a helyszínen.
- **Üzenet a bejelentkezetteknek** egy gombbal (névsor, naptár), napló a névsorban.
- **Magánóra:** jól látható szöveg, hogy az első alkalom tovább tart; ajánlatnál +15 perc blokkolva (nem számlázva).
- **Facebook-csoport** link: hírlevél-pipa, foglalás után, fiók, levelek alja, lábléc, app. Hírlevél-kapcsoló az appban.
- **2. rész – helyszínek és minimum létszám:** Studio → Locations: helyszínenként minimum és döntési idő (alap 2 fő, 3 órával előtte; online külön). Óránként felülírható a naptárban (pl. reggeli óra 12 órával előtte). Kevés jelentkező → automatikus lemondás, mindenki visszakapja az óráját, és levelet kap más időpontokkal + „hozz egy barátot” tippel; előtte (alap 12 órával) „hozz egy barátot” levél. Olivia „Class is on” / „Cancelled automatically” levelet kap.
- **3. rész – kuponok, szülinap, sorsolás, hírlevél:** kedvezménykódok (%, $; bérletre/órára; használatszám, lejárat); szülinapi kód automatikusan (alap 20%, 30 napig; a születésnap opcionális a profilban); hűség-sorsolás félévente (minden óra egy sorsjegy, a nyertes 5 alkalmas bérletet kap, név nélküli hírlevél mindenkinek); hírlevél az adminból, AI-vázlattal (Claude, API-kulcs kell: console.anthropic.com), tesztlevél, kötegelt küldés, egykattintásos leiratkozás.
- **4. rész – oktatók és bevétel-megosztás:** Studio → Teachers: oktató felvétele (név, rövid leírás, bemutatkozás, fotók, részesedés %), weboldali Teachers oldal, órára kiosztás a naptárban („with Anna” az órarendben, foglalóoldalon, levelekben, appban). Az oktató saját belépést kap: látja a saját óráira bejelentkezetteket, jelöli a jelenlétet és a helyszíni fizetést, üzenetet küld nekik (a válasz hozzá megy), szerkeszti a profilját, és havi elszámolást lát. **Pénz:** ha az oktató összeköti a saját Stripe-fiókját, a kártyás óradíj egyenesen hozzá megy (ő az eladó), Olivia része automatikusan díjként jön Oliviának – így Olivia nem számláz és nem vall be más bevételét. Bérlet/tagság/készpénz: havi elszámolás (ki mennyivel tartozik, CSV a könyvelőnek). Könyvelővel egyeztetni (1099-K / 1099-NEC).
- Tesztek: 460 integrációs + 236 böngészős + 18 app unit + 49 app végpont-ellenőrzés.

## 8. kör: Olivia módosításai (Google Doc) + saját fotók + 3 nyelv (2026-10-12)
- **Nyelvek:** angol, magyar, spanyol. Címek: `/` (EN), `/hu/`, `/es/`; nyelvválasztó fejlécben, menüben, láblécben; hreflang. Saját megoldás (nem WPML/Polylang). Oldalak, órák, cikkek, GYIK, események fordítása a szerkesztő „Translations” dobozában. Felület: `.po` fájlok (`languages/`). Az ügyfél-e-mailek a vendég nyelvén mennek; az e-mail szövegek nyelvenként szerkeszthetők (fülek). Az admin angol marad.
- **Titulus:** „Yoga Teacher | Movement & Wellness Coach” + „Yoga • Mobility • Prenatal Fitness • Meditation • Private Singing Bowl Massage” (hero névkártya, lábléc, szerzői doboz, Person schema).
- **Tipográfia, levegősség:** Anton címek maradnak, szöveg: Quicksand (finomabb, kerekebb). Nagyobb sorköz, puhább kártyák, vékonyabb vonalak.
- **Kivéve:** forgó „Beginners welcome” matrica, futószalag, „Certified hatha yoga teacher since 2023” felirat, szint-sávok és szintek, csillag-felsorolásjelek, minden nagykötőjel (—) a szövegekből.
- **Fotók:** csak Olivia képei (Drive + a régi Google Sites eseményfotói), a moodboard szerint színkorrigálva; az órák listaképe = az óra oldalának képe.
- **Órák:** Hatha 60–90, Slow flow 90 (minden szint), Sportolóknak 60/75/90, Online 60/75/90, Kismamatorna 30/45/60; szint helyett az óra nyelve (privátnál „English or Hungarian”). Új: Prenatal Fitness, Private Singing Bowl Massage; „Sound Yoga & Meditation”. Művészi piktogramok négyzetek nélkül.
- **Órarend-sor újratervezve** (időblokk, nyelv-chip, kiemelt Book gomb). **Átfedés-tiltás:** két óra nem lehet egyszerre (naptár, admin, heti generálás). Telefonon lebegő „Book a class” gomb.
- **About:** szakmaibb (személyi edző, masszőr, SMR, kismama-fitnesz képzés, Fitmummy logó) + személyes történet. Nevek: Kovacs (ékezet nélkül), Prana, Buda Perinatal Center.
- **Events:** 27 korábbi esemény fotóval, évenként; főoldalon csík. **Journal:** Olivia 24 írása a saját grafikáival (magyar eredeti + angol, spanyol fordítás).
- **Légzés-szekció** újragondolva: lótusz, ami belégzésre kinyílik, kilégzésre összecsukódik (4 be, 6 ki, 3 kör); mobilon a szöveg az animáció fölött.
- **Kapcsolat űrlap** elegánsabb (téma-chipek), **árak** csoportosítva (csoportos / tagság / privát és csapat), privát oldalsáv elfér desktopon.
- Tesztek: 540 integrációs + 252 böngészős ellenőrzés (új: nyelvek, átfedés-tiltás, nagykötőjel nélküli oldalak, lebegő gomb) + 18 app unit.
- **Teendők:** a dev oldalon Megjelenés → Olivia setup → *Refresh the content*; a régi, egymást fedő hétfő 18:00-s Hatha törlése a naptárból; a spanyol szövegeket és a magyar fordításokat Olivia nézze át; a Fitmummy felirat pontos szövege („Prenatal & postnatal fitness training: Fitmummy method”) Oliviával egyeztetendő.

## 9. kör: „Olivia Jóga – Online”, magyar online kiadás (2026-11-05)
- **Döntés (Áron):** első körben csak Magyarországon indulunk, online; a mostani (Fort Myers, 3 nyelvű) szerkezet megmarad, ahhoz akkor térünk vissza, amikor Fort Myersben is lesz élő oktatás.
- **Új kiadás** ugyanabban a témában és pluginban: `hu-online` (Megjelenés → Olivia setup → Edition). Csak magyar, előtag nélkül, magyar címekkel (`/orarend-es-arak/`, `/online-jogaorak/` …), forintban, budapesti idő szerint, csak élő online órák és online magánóra. Tartalom: `olivia-yoga/demo/content-hu-online.json`. Részletek: `wordpress/DEVELOPER.md` 23. fejezet.
- **Plugin 1.4.0:** nyelvek beállítása (egy nyelv, nem angol fő nyelv), *Online only*, forint (`2 500 Ft`), magyar dátumformátum; javítás: almappás telepítésnél a /hu/ és /es/ oldalak 404-et adtak (1.3.1).
- **Téma 3.1.0:** online főoldal, online menü, lábléc, SEO; a témafotók alt szövege a látogató nyelvén; a „Napló” menüpont nem aktív az óraoldalakon.
- **MINTA:** heti 5 online óra (H 18:00 Hatha, K 19:00 Slow, Sze 18:30 Kismama, Cs 18:00 Hatha, Szo 16:00 Slow); árak: alkalmi 2 500 Ft, 5 alkalmas 11 000 Ft, 10 alkalmas 20 000 Ft, korlátlan havi 12 900 Ft, havi 4 óra 8 400 Ft, magánóra 60/75/90 perc 12 000 / 14 500 / 17 000 Ft, 5 magánóra 55 000 Ft (a piackutatás szerinti sávban). Olivia véglegesíti.
- **Teendők:** Olivia nézze át a szövegeket, az órarendet és az árakat; Zoom-fiók; domain (pl. oliviajoga-online); a VPS dev oldal (`dev.helloprovision.com/<slug>`) a `projects.json`-ba felveendő, amikor a VPS él.

## 10. kör: online kiadás Olivia régi oldalának szövegeivel (2026-11-05)
- **Kérés (Áron):** magyar közönségnek, magyar helyszínhez illő szöveg, a régi oldal (oliviajoga.hu → sites.google.com/view/oliviajoga) szövegeiből.
- **Szövegek:** a Rólam oldal Olivia saját „Miért lettem jógaoktató?” szövege (csak elírásokat javítottam), a főoldal és az órák bevezetője, a GYIK válaszai (hatha jóga, „Elég ügyes vagyok?”, „Mennyire nehezek?”, fizikai és lelki gondok listája, mit kell előkészíteni) szintén a régi oldalról; az online-specifikus kérdések (link, kamera, késés, lemondás) maradtak.
- **Név:** a magyar kiadásban mindenhol **Kovács Olívia** (téma: `oy_teacher_name()`; fotók alt szövege, névkártya, szerzői doboz, lábléc, SEO).
- **Árak (a régi oldal / Motibro szerint):** alkalmi jegy 3 000 Ft, 5 alkalmas bérlet 14 000 Ft, **lejárat nélkül**, ajándékutalványként is; a 10 alkalmas bérlet és a havi tagságok kikerültek. Az órák legalább 3 fővel indulnak.
- **Órarend:** az ő mostani hatha flow idősávjai, online: K 20:00–21:00, Sze 18:30–19:30, Sze 20:00–21:00. **MINTA maradt:** Cs 19:00 Slow Flow és a magánóra-árak (12 000 / 14 500 / 17 000 Ft, 5 alkalom 55 000 Ft). A kismamatorna egyeztetett időpontban, négyszemközt vagy kiscsoportban (nincs heti fix óra).
- **Téma 3.1.1.**

## 11. kör: oliviajoga.hu új frontend (2026-11-05)
- **Kérés (Áron):** Olivia mostani magyar oldalának (oliviajoga.hu → Google Sites) új frontend az új dizájnban; a linkstruktúra, a SEO-oldalak, az elnevezések és a szöveg maradjon, csak új UX.
- **Kész:** `oliviajoga-hu/` – statikus oldal mind a 33 régi címen (`/rólam`, `/árak`, `/a-nyújtás-hatása` …), szó szerinti title-ökkel, szöveggel, címsorszintekkel és kép-altokkal; Motibro, Google Forms, térképek, Motibro-widget változatlan. A képként tárolt árak, vélemények és az „Ami nem szükséges” grafika valódi szöveg lett. Ellenőrzés: `oliviajoga-hu/tools/check.py` (2355 ellenőrzés). Részletek: `oliviajoga-hu/README.md`.
- **Nyitott:** tárhely (ma ELIN.hu, 301 a Google Sites-ra), Search Console, a régi Google Site kivezetése; jóváhagyás után WordPressre (olivia-yoga téma) ugyanezekkel a címekkel.

## 12. kör: SEO OS kutatás (16-os projekt) + versenytárs-összefoglaló Oliviának (2026-11-05)
- **Kérés (Áron):** a félbemaradt SEO OS kutatás folytatása; összefoglaló Oliviának a magyar online jógás versenytársakról, linkkel a SEO OS-ből, CRM-csetben.
- **SEO OS 16 („Online jóga Magyarország – piackutatás”):** 19 versenytárs, szolgáltatások és üzleti profil beállítva, állapot: *researching*. A 1 435 kulcsszóból 537 kizárva, okkal (visszavonható a Kulcsszavak fülön): 277 nincs keresési adat, 72 más jelentés („joga” = jog, fitnesz-edzés, külföldi), 58 termék, 61 helyi és 35 stúdiókeresés (nem online), 31 kis keresés, 3 angol / near me. Aktív: 898.
- **Az AI-elemzés nem fut le:** a worker kétszer is a klaszterezésnél (embedding) állt le, 3-3 próbálkozás után („A feladat többször megszakadt”, 33649: 1 435 kw, 33684: 898 kw). A 14-es projekt (1 390 kw) is 0 elemzettnél áll. SEO OS-hiba (hlprv), valószínűleg memória; a kutatás lépés addig nyitott.
- **Dokumentumok a projektben:** 47 – novemberi piackutatás (2026-11-03); 48 – „Versenytársak – online jóga Magyarországon” (5 oldal: élő online órák árai, videótárak, ki van elöl a Google-ben, mit jelent ez Oliviának, fő keresési témák). Olivia a 48-as linkjét CRM-csetben kapta (Áron nevében).
- **DataForSEO:** a 2026-11-05-i SERP-lekérés hibás adatot adott (csak google.com), és 30 napig gyorsítótárban marad; a versenytárs-adatok a 11-03-i SERP-ből vannak.

## 13. kör: oliviajoga.hu – Olivia módosításai (2026-11-05) – VISSZAVONVA
- **Forrás:** Olivia Google Doc-ja („Módosítások oliviajoga.hu”, 30 pont, képernyőképekkel); képek a Drive-járól („Főoldal kedvenc képek”, Moodboard).
- **Mi történt:** a 30 pontot szó szerint megcsináltuk (d791581: új logó, fekete-fehér főoldal, animációk, új Rólam/Kapcsolat …). Áron (2026-11-06): „szarabb lett” → visszaállítva az 1. körre; **csak a háttér-javítás maradt** (az átlátszó képek – Fitmummy-logó, rajzok, kivágott fotók – mögött nincs halványzöld helykitöltő szín). Oldal: 1.0.1, előnézet ugyanott, 2355/2355 ellenőrzés.
- **Tanulság (Áron):** az ügyfél jegyzetei meglátások, nem szó szerinti feladatlista. Előbb prompt/terv belőlük, azt Áronnal megbeszéljük, csak utána építünk.
- A 2. kör kódja és Olivia letöltött képei a d791581 commitban visszakereshetők (`tools/images.py`, `tools/logo.py`, `assets/img/new`).

## 14. kör: oliviajoga.hu – Olivia jegyzetei a meglévő dizájnon belül (2026-11-06)
- **Kérés (Áron):** Olivia szerkesztette a doksit; „vedd komolyan … használd a mesterek eszközeit … maradjon meg a dizájn … de erősíts”.
- **Módszer:** webdesign skill (hlprv): brief a mesterek módszerével (`oliviajoga-hu/BRIEF.md` – Tschichold / Hey + Headspace / Dumbar, metafora: a hatha flow légzése, aláírás: a lélegző „JÓGA” kiemelés), tiltólista (új betű, futó szósáv – Olivia korábban: „ez szar” –, forgó felirat, új díszcsalád), önkritika 390 / 768 / 1440 px képernyőképekkel.
- **Kész (1.1.0):** Olivia „hero” képe; fekete-fehér főoldali fotók (kivéve az ajándékutalványt és Olivia saját fekete-fehér/pink rajzait); egyforma bejelentkezés-gombok; képsor a kedvencekből (diszito3) a téma kereteivel; sorköz az ékezetekhez; egy bekezdés-stílus a „Gyakoroljunk együtt”-ben; GYIK számozva, csukva, két hasábban; ajándékutalvány jegy-alcímmel és lépésekkel; csapatépítő rész ki (főoldal + menü, az oldal a címén marad); Gergely hosszú véleménye kiemelt, kéthasábos idézet; lábléc név nélkül; Rólam könyv-módban (olvasóhasáb, számozott fejezetek, tapadó portré, nagy mondatok színblokkban); Árak (90 perc és alsó gomb ki, private yoga kép, helyszín-címkék); Jógáról (diszito2, átlátszó fehér); cikkek (rendes cím, gomb); Események (képek nem nagyítva); Bejelentkezés (Motibro középen, görgetés nélkül, helyszínek formázva); Kapcsolat (Olivia3, két színes helyszíndoboz térképpel, űrlap helyett biztató szöveg).
- **Logó:** három javaslat az előnézetben (`/logo-javaslatok/`): A puha retró, B a mostani dizájn betűjével (Anton), C telt és játékos – Olivia választ, utána kerül a fejlécbe.
- **Nyitott:** logóválasztás; a Kapcsolat biztató szövege tőlünk van (Olivia átírhatja); a `/jógaóra-cégeknek` oldal sorsa.
- **Áron (2026-11-06): „csak azok legyenek megváltoztatva, ami le lett írva … az alapdizájn maradjon meg, ez a színkombináció, meg minden”.** Ezért kivéve a saját extrák: címsor-betűköz és kiegyensúlyozott tördelés (csak a kért sorköz maradt), bekezdés-tördelés és elválasztás, a lila kiemelés színváltása (csak a „lélegzés” mozgás maradt, a kért animációként), a beúszások időzítése, a „Miért jóga?” kép átalakítása. Szabály: ügyfél-módosításnál csak a kért pontok; a színek és az alap-komponensek nem változnak.

## 15. kör: oliviajoga.hu – Olivia üzenetei a CRM-chatben (2026-11-06, 1.2.0)
- **Kérés (Áron):** „közben jönnek üzenetek oliviától. azokat is csináld” – Olivia 12 pontja a chatben (420001-es csatorna).
- **Kész:** a „jógaóra cégeknek” mindenhonnan törölve (oldal, menü, sitemap; élesben 301 a főoldalra); logó: a B változat, levélke nélkül (az igazi Í), más hullámmal (ecsetvonás a név alatt, a végein vékony) – fejléc és lábléc; a képek mögött színes doboz helyett hullám (két réteg, mint a referenciaképén): a hősben széles hullámsáv, a többi kép mögött hullámszalag, ami a végein elvékonyodik; a főoldali képsor egy sorban, kitöltve, hullámmal; a két új fotó (IMG_6232, kovacs-olivia-hatha-joga) a régi kerek kivágások helyén; GYIK és Rólam számok helyett kis hullámjellel; az ajándékutalvány „jegy”-alcíme ki (sima alcím hullámjellel); fizetési logók: a Barion hivatalos, éles SVG-sávja; a menüoldalak fejlécképe kitölti a sávot fentről lefelé, hullámos széllel; Események: a kép kitölti a keretet, jó minőségben; az írások képeinek fehérje az oldal hátterébe olvad.
- **Megjegyzés:** a Barion hivatalos sávjában Diners és Discover van (Maestro/Amex nincs).
- **1.3.0 (Olivia további üzenetei, 19:01–19:06):** a cikk-kártyákon az ő teljes fotói (Drive-mappa, fehér szél levágva, `tools/images.py cards`; ahol cikkcímmel nevezte el, az az új kép), a régi kép alt-szövegével; egységes kártyacím (cím + zöld alcím, a címszintek maradnak); Kapcsolat: a két doboz gombjai és térképei egy vonalban; a hullámok újra („béna a kivitelezés”): nagy, lágy, kétrétegű ívek, amelyek a kép oldalán kifutnak a képernyő szélére (hős, Miért jóga, Gyakoroljunk, Rólam), a képsor mögött teljes szélességű hullámsáv – nincs hegyes vég, egyenes él. WordPress (`oliviayoga` 1.3.0): a setup a hiányzó új képeket a GitHubról tölti le a médiamappába.
- **Nyitott:** Olivia visszajelzése; a Kapcsolat biztató szövege.

## 16. kör: négy WordPress (iu_theme-gyerektéma) a HelloProVision VPS-devre (2026-11-06)
- **Kérés (fő chat, Áron jóváhagyásával: „oké, mehet”):** „Az Oliviajógának sok verziója van … a Hathaflow (eladható foglalásrendszer), az amerikai foglalási oldal, a magyar oldal új frontendje és a magyar online foglalás – mindegyikből csinálj WordPresst.”
- **Kész:** `wordpress/hpv/build-child-themes.py` → hlprv `oliviayoga/wp-theme/{oliviayoga,oliviayoga-booking,oliviayoga-us}` és `hathaflow/wp-theme/hathaflow` (ág: `claude/nifty-ramanujan-3zifiu`), mind iu_theme-gyerek, `hpv_child_setup`-pal (idempotens, kétszer futtatva sem változtat).
  - `olivia-yoga` téma 3.2.0: új kiadás **hathaflow** (`oy_brand()`, `oy_is_demo()`, saját főoldal `template-parts/front-hathaflow.php`, `demo/content-hathaflow.json`, „Demo site” sáv; fejléc, lábléc, SEO, oldalsávok, kapcsolati űrlap kiadásfüggő). A Fort Myers és a magyar online oldal változatlan.
  - `oliviajoga-hu/build.py --wp` + `oliviajoga-hu/wp-theme/`: az oliviajoga.hu WordPressen – ugyanazok a címek (ékezettel, perjel nélkül: `/rólam`), címek, leírások, képek; az oldal tartalma HTML-blokk; a képek a VPS `media_dir`-jéből (`uploads/oliviajoga-img`).
  - Helyben kipróbálva mind a négy (friss WordPress + iu_theme + gyerektéma + bővítmény, `maybe_setup --force` kétszer); hlprv `deploy/vps/projects.json` (a régi `oliviayoga` = most az új frontend; új: `oliviayoga-us`, `oliviayoga-booking`, `hathaflow`), `deploy/sites.json`, dev-címek a tudásbázisban.
- **Nyitott:** a bővítmény neve az adminban „Olivia Studio” (Hathaflow fehér címke); Hathaflow saját fotók / magyar változat; az US és a booking téma ~21 MB (a képek a témában).
- **Ügyfélnek** (Olivia) a devről semmi, amíg a fő chat nem szól.

## 17. kör: oliviajoga.hu redesign doksi + oliviajoga.online (2026-11-12)
- **Kérés (Áron):** „ez az oliviajoga.hu redesign. csináld meg a deven a módosításokat”, „ha kész írj Olivianak”; az oliviajoga.online „a mi domainünk”.
- **oliviajoga.hu 1.4.0** (`oliviajoga-hu/README.md`, 5. kör): a doksi első része most él, a második (november 26-tól) a WordPress-témában a dátumon magától vált; előnézet `?nov26=1`. Demó: `/demo/oliviajoga-hu-6083/` és `/nov26/`, Facebook-képek: `/facebook/`.
- **olivia-studio 1.5.0:** kismamatorna saját bérlettípusokkal (`prenatal_pack`, `prenatal_duo_pack`, `prenatal_single`; a kredit csak kismamatornára jó, magánjógára nem), a magánóra-kérésben „Mire jelentkezel?” (`topic`, DB 11), ha a stúdió lemond egy csoportos órát, a lejáró bérletek `cancel_extend_days` (7) nappal tovább érvényesek, a dolláros alaptermékek nem kerülnek forintos oldalra.
- **olivia-yoga 3.3.0** (online kiadás): 5 alkalmas 13 000 Ft / 8 hét, 1 alkalmas ajándék, kismamatorna-árak, szerda 18:30–20:00 (90 perc) az egyetlen heti óra, `/online-jogaorak/kismamatorna/` (a régi `prenatal-fitness` 301), egyszeri frissítés a meglévő oldalon (`oy_migrate_online()`, `oy_online_rev`).
- **Kiegészítés (doksi + Áron, 2026-11-12):** oliviajoga.hu 1.4.1 – őszibarack előlábléc, új Árak-alcím, telefonos menü javítva; **Képek helye** az adminban: az oliviajoga.hu-témában Megjelenés → Képek helye (fotónként a látható pont), a foglaló-/US-témában (3.3.1) Média → kép → „Kép helye” (`oy_pos` meta, `inc/image-position.php`).
- **Nyitott:** a dev foglalóoldalán a bővítményt a VPS-en újra kell telepíteni (a `hpv-site update` nem teszi fel a `plugin_zips`-et); az oliviajoga.online domain élesítése (most parkolt oldal); a magánóra-árak továbbra is MINTA.

- **18. kör (Olivia „Módosítások online jóga” doksija + Áron, 2026-11-12):** nincs Zoom: **saját élő közvetítés** (olivia-studio 1.6.0, `OYS_Live`; első változat Daily-vel): élő oldal a fiókban, saját link minden résztvevőnek, a chat az óra után is megmarad, felvétel csak Oliviáról (a résztvevők soha), 7 napig visszanézhető, az óra eleji szavak AI-összefoglalója. Az online oldal (olivia-yoga 3.4.0): csoportos online flow jóga szerdánként (első dec. 16., dec. 23. kimarad), egyéni online jóga, online kismamatorna (hosszabb oldal, eszközlista), online stretching és mobilizáció, relaxáció és légzés (saját árak, bérletek), „miért online” horgok (SEO-kutatás: „egyéni” > „privát”, „kismama torna” 720/hó, „stretching” 1600/hó, „légzőgyakorlatok” 590/hó), slow flow és Zoom ki, Napló → Jógáról, az oliviajoga.hu arculata és logója kisbetűs „online”-nal. (A Daily-t ugyanazon a napon kivettük, lásd lent.)

- **18b. kör (Áron, 2026-11-12 este):** „ne a dailyt … ami a Hathaflow-nak a tervében van, azt a fajta streaminget
  használjuk … Stripe-os fizetéstől kezdve minden ugyanaz … a hathaflós doksiból dolgozz.” → **HathaFlow Live LiveKittel**
  (olivia-studio 1.7.0, DEVELOPER 6.13b), a HathaFlow Platform Plan 11–13. oldala szerint: csak a foglalók, saját link, a
  terem 10 perccel előtte nyit, „Élő adás indítása”, kis órán a tanulók kamerája rácsban, nagyon csak az oktató; chat,
  kézfeltartás, mindenki némítása; felvétel csak az oktatóról (a böngészőjéből, darabokban a saját szerverre), 7 napig
  visszanézhető, 30 nap után törlődik; az óra eleji szavak AI-összefoglalója. Helyben kétböngészős próbával (LiveKit dev
  szerver) végigment. **Teendő az élesítéshez:** LiveKit Cloud projekt (Áron) → `OYS_LIVEKIT_URL`, `OYS_LIVEKIT_API_KEY`,
  `OYS_LIVEKIT_API_SECRET` (Áron az adminban adja meg). Olivia oldalán nincs HathaFlow-platformdíj (Áron jóváhagyta).

- **18c. kör (2026-11-12 este, élesítés):** az oliviajoga.online ÉLES a VPS-en (a fő chat, `from_dev`); olivia-studio
  1.7.1 (titokmezők nem íródnak vissza, Áron az adminban adja a kulcsokat), 1.7.2 (aláírt linkek az oldalhoz kötve –
  HathaFlow-jelzés). olivia-yoga 3.4.2: WP-mintatartalom törlése, adatvédelmi tájékoztató (`/adatvedelem/`), magyar
  bejegyzéscímek (a régiek átirányítanak), nincs users-sitemap / szerzői oldal. oliviajoga.hu 1.4.3: a fejléc hulláma
  vissza, a korszak-rács animált. Nyitott: admin-fiók Áronnak és Oliviának az élesen (a távirányító élesen nem futtat
  WP-CLI-t), a bővítmény frissítése élesen (GitHub-kiadás alapú frissítő kell), SMTP / küldő cím.

- **18d. kör (Áron, 2026-11-13, „ma élesítem … sokan fognak regisztrálni”):** olivia-studio **1.8.1** – saját
  **SMTP** (Studio → Emails → „Levélküldő szerver (SMTP)”, vagy `OYS_SMTP_*` a wp-config.php-ban; a kulcs titokmező;
  próbalevél gomb, utolsó hiba), **Reply-To** cím, a WordPress saját levelei (jelszó-visszaállítás) a stúdió nevében;
  a regisztrációs levél **megköszöni** a regisztrációt és **felsorolja a következő órákat** foglalás-linkkel; magyar
  dátum „dec. 16.”, „20-ig” (nem „20.-ig”); névelők („az egyéni órád”); a belépés előtti levélben nincs dupla tipp; a
  mobilhálózatok közös IP-je nem zár ki mindenkit (IP-n 20 hibás belépés, címenként 6; IP-nként 10 regisztráció /
  15 perc). olivia-yoga **3.4.4:** az online kiadás feladója `info@oliviajoga.online`, a válasz és a stúdió-értesítés
  Olivia címére (élesen egyszer, csak ha még az alapérték áll ott). Tesztek 846/0.

- **18e. kör (Olivia doksija 11.13 + Áron: „vizuális szerkesztő”):** olivia-yoga **3.5.0** – a hős az oliviajoga.hu
  stílusában (lélegző lila blokk, lágy rózsaszín hullám, zöld gombok, a JÓGA ékezete a blokkban), névkártya: „Jógaoktató |
  Pre- és postnatális tréner | Mozgás- és wellness coach”, „Jóga • Kismamatorna • Mobilizáció • Relaxáció és légzés”; új
  hős-szöveg; öt külön szolgáltatás-doboz eltolva; „Nem kell utaznod” gyertyás mondattal; „magyarul” sehol; „Bejelentkezés
  és árak”; kismamatorna: a kilenc mozgásmodul (az „óránként kettő” tévedés volt, lásd a 20:17-es kiegészítést). Élesen egyszeri tartalomfrissítés (`oy_migrate_1113`).
  **Szerkesztés a weboldalon** mindkét oldalon (DEVELOPER 6.13e); oliviayoga (oliviajoga.hu) **1.5.0**. Tesztek 867/0.
- **Fizetés (Áron, 11.13 este):** az Arovia Group LLC számláz → Stripe (Arovia) + QuickBooks. A kulcsok a CRM-ben vannak,
  és onnan nem jönnek ki (a CRM így készült; a QuickBooks-token is csak egy helyen élhet) → az oldal a CRM-en át fizettet.
  olivia-studio **1.9.0** (DEVELOPER 6.13f) + olivia-yoga **3.5.1**; a CRM-modul a hlprv-ben (`shop-pay.php`), a fő
  chatnek kell beolvasztania és kiadnia. Tesztek 879/0; a CRM-modul helyi próbája 25/25.
- **ÁSZF és impresszum (olivia-yoga 3.5.2, olivia-studio 1.9.1):** `/aszf/`, `/impresszum/` az Arovia Group LLC-vel mint
  eladóval (a cím a helloprovision.com nyilvános feltételeiből), az órákat Kovács Olívia tartja; elállás (14 nap a
  bérletre / utalványra, időponthoz kötött órára nincs – 45/2014. Korm. r.), panasz, irányadó jog (Florida, a fogyasztó
  kötelező szabályai megmaradnak). Az adatvédelmi tájékoztató adatkezelője az Arovia (Stripe, QuickBooks, USA). Lábléc:
  ÁSZF · Impresszum · Adatvédelem; a fizetés gombjai alatt „A vásárlással elfogadod az ÁSZF-et”. **Nyitott (Áron):**
  az Arovia EIN-je / floridai nyilvántartási száma az impresszumba, uniós képviselő (GDPR 27. cikk), OSS-ÁFA.

- **Olivia doksija 11.13, 20:17-es kiegészítés (olivia-yoga 3.5.3, olivia-studio 1.9.2):** „Olívia” hosszú í-vel
  mindenhol (márkanév, címek, fordítások; az élő oldal neve és a levelek feladója is, ha még a régi); a 4. „miért online”
  doboz „Valódi oktatói jelenlét”; az „Ismerd meg az oktatód” felirat ki; a bevezetőben a .hu jógikus-életvitel mondata;
  a gomb és az „Összes óra” egy vonalban; Tudatosság / Kontroll a .hu mintájára (zsályás sáv, „= …”, „Számomra ez a
  legfontosabb különbség…”); egynyelvű oldalon nincs nyelvjelzés az órarendben. **Kismamatorna (javítás):** NEM „óránként
  kettő” – a kilenc modul kombinációja adja a felkészülést; a modulok felsorolva mindenhol, ahol a kismamatorna szerepel
  (főoldali doboz, óra-kivonat, Online órák, Bejelentkezés és árak, GYIK), az aloldalon modulonként, miért kell a szüléshez,
  plusz a sportmúlt és az első alkalom mint felmérés. Élesen `oy_migrate_1113` rev c. Tesztek 891/0. Nyitott (Olíviától
  kérdezzük): „Az első kép legyen inkább ez” (nincs kép a doksiban) és „Ide ezt a képet szeretném” (a csoportos óra sora).
- **Olivia doksija 11.13, 21:35-ös kiegészítés (olivia-yoga 3.6.0, olivia-studio 1.10.0, DEVELOPER 6.13g):** nyitókép =
  „Olívia (36)” (napnyugtás hátrahajlás), az óra-sorok képei: csoportos flow = ugyanaz, stretching = „27067” (homok),
  relaxáció = az újbudai gyermekpózos csoportkép. Szövegek: gyertyás mondat, „vezetlek” (figyellek/igazítok ki), „a
  relaxációhoz” (hosszú ki), kamera: „Nem kötelező” + kiemelve „A képedet csak én látom”, „aktivitás” az erőfeszítés
  helyett, „Árak” cím (forintban-felirat ki), légzés: kicker ki, „3 kör, majd figyeld meg az érzeteket” (narancs, a
  végén a lótuszban is). Órarend: csak a szerdák + külön „Egyéni órák” doboz (főoldal, Foglalás). Hatha gomb →
  Jógáról#a-joga-8-aga. Árak: „Legnépszerűbb” ki, kiemelt ajándékutalvány-sor, csoportos cím „Csoportos, élő online flow
  jóga órák” + 90 perc; egyéni jóga 60/90 perc kapcsolóval (90 perc: 7 000 / 30 000 Ft), minden 5 alkalmas egyéni
  bérlet 6 hét (+1 hét oktatói lemondásnál), „Párban olcsóbb” kapcsoló (kismamatornánál; egyéni jógához kérdeztük a
  párban árat). Fizetési oldal: a bérletkártya nem ragad. Tesztek 919/0.
- **Áfa és cégadatok (2026-11-14, olivia-yoga 3.6.1):** Áron: „Nincs áfa. Mehet minden bele.” (a fő chaten át, itt
  megerősítve: „Igen. Megerősítem”). ÁSZF: „Az árak … végösszegek: ezen felül semmilyen adót vagy díjat nem számítunk
  fel” (áfamentességet nem írunk ki); az Arovia EIN-je (36-5185016, a CRM `company_ein`) az ÁSZF-ben és az
  impresszumban. Élesen csak a jogi oldalak frissülnek (`oy_migrate_legal`, rev 2026-11-14a) – Olívia már maga
  szerkeszti az oldalt, a teljes tartalom nem töltődik újra. **Hiányzik:** a floridai cégjegyzékszám (Sunbiz Document
  Number) és a GDPR 27. cikk szerinti uniós képviselő (ki legyen?) – Árontól. Két napfüggő teszt javítva. 3.6.2: a részvételi nyilatkozatban is „Olíviának” (a bővítmény beállításaiban maradt rövid i). Tesztek 924/0.
- **Olivia chatüzenetei 11.14 (olivia-yoga 3.7.0, olivia-studio 1.10.1):** új árak – egyéni jóga 60 perc 8 000 /
  35 000 Ft, 90 perc 10 000 / 43 000 Ft; páros jóga 60 perc 12 000 / 52 000 Ft, 90 perc 15 000 / 65 000 Ft;
  kismamatorna bérlet 35 000 Ft, **lejárat nélkül** (az alkalmi 5 000 Ft-tal ez drágább lett volna öt alkalmi jegynél →
  alkalmi 8 000 Ft, párban 12 000 / 52 000 Ft, az egyéni jóga szerint – Olíviától megerősítést kértünk); stretching
  7 000 / 30 000 Ft; relaxáció 5 000 / 18 000 Ft. „Párban olcsóbb” minden egyéni szolgáltatásnál (a csoportoson kívül),
  látványosabb kapcsolóval („fejenként … Ft-tól”, a kártyán „fejenként …”); a stretching és a relaxáció páros ára
  javaslat (×1,5: 10 500 / 45 000 és 7 500 / 27 000 Ft). Főoldal: új blokkok – egyéni órák Olívia listájával („jöhetsz
  …”), külön kismamatorna-blokk (modulok, első alkalom), stretching + relaxáció kártyák. Élesen `oy_migrate_prices`:
  csak a termékek és az óraoldalak „Árak” listája cserélődik. Tesztek 947/0.
- **Olívia chatje 11.14 délután (olivia-yoga 3.8.0, oliviayoga 1.5.1):** a kismamatorna egyéni 8 000 Ft és a páros
  árak maradnak; stretching és relaxáció párban a javasolt áron; **relaxáció bérlet 20 000 Ft, párban 32 000 Ft**
  (`oy_migrate_prices` rev 2026-11-14b). **Képek:** „az alt textek és kép elnevezések angolul vannak … legyen mindegyik
  magyar és az összes releváns kulcsszó legyen benne … ékezet nélkül, kötőjellel” → `images.json` „hu” (név + alt) az
  online oldal 44 képének (a 24 blogkép is, megnézve leírva); a gyerektéma buildje a témafájlokat a magyar nevükön
  szállítja („file”), élesen `oy_migrate_images()` a Médiatár témaképeit helyben átnevezi (méretekkel, a cikkekben a
  címet is) és magyar alt/címet ad (amit valaki átírt, marad). „Amiket én cserélek képek, abból csinálj egy másolatot
  megfelelő képmérettel … és fájlelnevezéssel” – „frissítsd folyamatosan” → a szerkesztő (`oye_web_copy`) minden cserénél
  webes másolatot tesz a lapra (≤1920 px, 82-es JPEG, név a leírásból, az eredeti marad), a lapon srcset; a feltöltések
  neve kisbetű/ékezet nélkül; WordPress nagykép-határ 1920 px. Olívia mai 4 képe (nyitókép = táncos póz a nappaliban,
  IMG_7309 = kitörés a nappaliban, prenatal-training, 27067) megnézve, saját leírással (`content-hu-online.json` →
  `uploads`). Tesztek 982/0.
- **Olívia doksija „oliviajoga.online módosítások 11.14.” (olivia-yoga 3.9.0, olivia-studio 1.10.2):** az órarend-kártyán
  nincs rózsaszín vonal (a lap szövegének lista-jele csúszott rá: `.entry ul:not([class]) li` → csak osztály nélküli
  tételek), a címben nincs „online” (`oys_card_title`, az ONLINE címke mondja); a nyitórészben csak a „Bejelentkezés és
  árak” gomb; a lábléc nagy „KOVÁCS OLÍVIA” felirata kikerült (online kiadás); tördelés: `text-wrap: pretty/balance` és
  `inc/typography.php` (a, az, s, és, egy … a következő szóhoz, „is” az előzőhöz, szám a mértékegységgel, nincs sor
  eleji gondolatjel – a szerkesztő után fut, a kulcsai az eredeti szövegek). Rólam: fejezetek (01–03), úszó fotók,
  kiemelt dobozok, aláhúzott mondat, „Az utam” idővonal, „Amiben ma segítek” (az öt terület), kártyás képzések,
  „Ahol eddig tanítottam”, számozott „Hogyan tanítok”, „kis titok” cetli; minden régi bekezdés betűre megmaradt, így
  Olívia szerkesztései továbbra is érvényesek; élesen `oy_migrate_about` csak akkor írja át, ha a tartalom még a témáé
  (md5), a bevezetőt és a keresőleírást is csak, ha még az eredeti. Árlista: minden bérleten „Egy óra: X Ft” (párban
  „fejenként” is), egységes dobozok (a szolgáltatás az ár fölött, `oys_price_card_title`), hangsúlyosabb „alkalom ×
  perc”, az ajándéksor felirata hoverre nem tűnik el, belépve (admin sáv) a ragadós szolgáltatásnév teteje látszik.
  Olívia kérte: „mehet minden módosítás egyből az élesre” – élesre Áron engedélyével. Tesztek 1016/0.
- **A doksi későbbi pontjai (olivia-yoga 3.10.0):** a cikkek képei Olívia nyers fotói (az oliviajoga.hu buildjéből,
  `oliviajoga-hu/assets/img/new/cikk-*`), kitöltik a keretet (nincs többé „art” jelzés); élesen `oy_migrate_doc_1114`
  a Médiatárban helyben cseréli őket (ugyanaz a melléklet és név, új méretek), az öt eltérő fotó új magyar altot kap
  (06, 09, 11, 12, 13). A főoldalon a fekete-fehér fotó egérrel színes. A kismamatorna eszközei „kellhetnek”, nem
  kötelezők (oldal + GYIK, csak ahol még az eredeti szöveg áll). A nagy címek sortávja 1,16 (az ékezet nem lóg bele a
  fölötte lévő sorba). Mobilmenü: az „Online órák” alatti szolgáltatások 48 px-es, gombszerű sorok. Rólam: számozás
  helyett hullámvonal (fejezetek) és rajzos ikonok („Hogyan tanítok”). Víziós összefoglaló Olíviának a chaten (2. változat a
  főoldalra, a kismamatorna részletesebben a posztja alapján). Tesztek 1025/0.
- **A doksi utolsó pontjai (olivia-yoga 3.11.0, olivia-studio 1.10.3; a 3.10.1 is benne: 2015 az idővonalon):**
  nyitókép: „ONLINE / JÓGA / KISMAMATORNA / MOBILIZÁCIÓ / ÉLŐBEN, OTTHONRÓL” – a három egy szinten, mindegyik a lenti
  dobozának színében (lila, zsálya, narancs), egymás után „lélegeznek”. Az öt szolgáltatás-doboz egyenes sorokban (nincs
  cikcakk; alul kettő kitölti a szélességet). Kikerült minden kis „vonal + nagybetűs” címke a címek fölül (`oy_kicker`
  online üres) és az aloldalak morzsamenüje is (`oy_crumbs` online üres; a bővítmény kártyáin a címke marad, vonal
  nélkül). Az egyéni órás lista saját megfogalmazásban (nem Olívia szövegének másolata), pipák helyett a logó kis
  hulláma színenként váltakozva, az első sorhoz igazítva. Egyéni és kismamatorna blokk: a nyitókép puha hulláma a kép
  mögött (lila / rózsaszín), a képernyő széléig fut, nem lóg a szövegbe. A kilenc kismama-modul mind más színű pöttyel.
  „Amire szükséged lesz” általános (matrac + amit az óra kér; a GYIK-ban és az árlapon is), és „töltsd fel az eszközöd”:
  főoldal, árlap, GYIK, a foglalási e-mail tippje, az óra előtti e-mail, az élő óra belépő képernyője. Rólam: „2018-tól
  masszőrként is tevékenykedtem, … nyirokmasszázst és talpreflexológiát is”. Lábléc: „Készítette: HelloProVision”
  (helloprovision.com). Élesen `oy_migrate_1114d()` (rev 2026-11-14d): `_insert` és `_replace` párok a lapokon, GYIK
  `_prev` – csak ahol még a régi szöveg áll szó szerint. Teszt: a naptár-kapacitás teszt délelőtt ütközött a „72 óra
  múlva” órákkal → 9:00 vagy 20:00, az órától függően. Tesztek 1047/0.
- **A doksi 15:39-es pontjai (olivia-yoga 3.11.1, olivia-studio 1.10.4):** a főoldal „Heti élő órák” alatti sora
  kikerült (Olívia már élesen átírta „Egy perc a foglalás…”-ra, most az egész sor megy); „egy napon belül” helyett
  „hamarosan” visszaírok/válaszolok mindenhol az online oldalon (órarend egyéni doboza, Órák, árlap, Kapcsolat, egyéni
  óra lapja, a kapcsolati űrlap köszönője, az időpontkérés visszaigazolása és e-mailje, az aside kártya; az ÁSZF marad);
  a lótuszos légzés barack háttéren (sötét szöveg, zöld gomb, rózsaszín haladásjelző); a „Hatha jógaóra elemei: nyújtás”
  képe (journal-01) lejjebb fókuszál (50% 62%), hogy Olívia látsszon, ne a fák teteje. Élesen `oy_migrate_1114d` rev
  2026-11-14e: a lapok mellett az órák (`classes`) `_replace` párjai is, és a fotó fókusza, ha még a régi (`pos_prev`).
  Teszt: a naptár-kapacitás teszt saját tanárt kap (az időponttól nem függ többé). Tesztek 1050/0.
- **3.11.2 (élesítés, Áron: „Csinald es minden mehet”):** a 3.11.0/3.11.1 témába a régi bővítmény-zip (1.10.2) került új
  verziószámmal, mert kimaradt a `dist/*.zip` újraépítése – élesen és a deven is 1.10.2 maradt. A zipek újraépítve; a
  `build-child-themes.py` mostantól leáll, ha a `dist/olivia-studio.zip` verziója nem egyezik a forráséval.
- **A doksi 16:20-as pontjai (olivia-yoga 3.11.3, olivia-studio 1.10.5; Áron: „az élest azonnal mehet minden”):** a
  főoldal órarendje mindig a következő 3 órát mutatja (`next="3"`; a heti órák 10 hétre előre készülnek, élesen
  `oy_migrate_1114d` rev f emeli a `weeks_ahead`-et és legenerálja az új dátumokat); a főoldal „Online órák” listája
  kikerült, az időtartam az öt szolgáltatás-dobozon van (az óra saját „Hossz” mezőjéből); a Fiókom › Egyéni órák
  árlistája időtartamonként külön sorban, az 5 alkalmas bérletekkel, jógánál „párban”, kismamatornánál „a barátnőddel”
  (`OYS_Services::pack_price`). Percenkénti figyelés: `scratchpad/watch/watch.sh` (Monitor) a nyilvános doksikat és a
  chatet nézi. Tesztek 1058/0.
- **Rólam, a doksi 16:40 utáni pontjai (olivia-yoga 3.11.4–3.11.6):** az idővonalról kikerült a „19 évesen”; az
  „Otthon, online” a 2015 és a 2018 közé került (szó szerint, így a szerkesztős átírása érvényes marad); új pont: „2023–2026
  megvalósítottam az álmaimat, … jógás eseményt szerveztem …”; kikerült a „2023 óta vagyok jógaoktató…” bekezdés és a
  képzések negyedik kártyája („Évek személyi edzőként…”, az idővonalon már benne van); az öt terület és a három képzés
  páratlan utolsó doboza teljes szélességű. Élesen `oy_migrate_1114d` rev i (`_insert` / `_replace`, csak szó szerint
  egyező szövegen). Tesztek 1062/0.
- **A doksi 3.11.10–3.11.13 pontjai (olivia-studio 1.10.6–1.10.7; Áron: „az élest azonnal mehet minden”):** lemondási
  szabály több helyen (órarendek alatt, órák oldalán, egyéni kérés űrlapján); GYIK: „Tényleg működik a csoportos jóga
  online?”, „Mit kell előkészítenem az órára?” (szolgáltatásonként, Olívia végleges listájával: jógatégla vagy könyv,
  magasabb ülőpárna, „kérdezz bátran”), az ajándékos kérdés minden szolgáltatással; a „Mire lehet szükségem a
  kismamatornához?” a kukába került (`faqs_removed`, visszaállítható). „3 órával előtte, minimum alatt automatikus
  lemondás + e-mail”: a bővítményben már megvolt (online helyszín: 3 fő, 3 óra), most a GYIK és az árlap is így írja.
  „Egy hétig visszanézheted” kiemelve: főoldali csoportos doboz, órarendek alatt, csoportos óra oldala, foglalási lap,
  foglalási / emlékeztető e-mail (`live_replay_days`-ből; a felvételhez a LiveKit-kulcsok kellenek élesen). A ragadós
  oldaldoboz (GYIK, Rólam) alacsony ablakban sem vágódik le (site.js negatív sticky top); a Rólam két edzéses képe egy
  vonalban. A GYIK-kérdés átnevezése mostantól akkor is megtörténik, ha a választ Olívia átírta (a válasza marad).
  Tesztek 1093/0.
- **3.11.14:** a Rólam képzés-kártyái (`oj-creds`, a három tanúsítvány) kikerültek („Ezt a részt szedjük ki a rólamból”);
  élesen `oy_migrate_1114d` rev o, a teljes blokk szó szerinti `_replace` párjával.
- **3.11.15–3.11.19 (olivia-studio 1.10.8–1.10.10; Áron: „Mindet az éles oldalon módosítsd folyamatosan” – nincs
  dev-várakozás):** a ragadós oldaldoboz csak akkor ragad, ha egészében kifér, alacsony laptopon tömörebb; a főoldali
  ecsetvonás vége elhalványul; minden főoldali kép színes egérrel; az egyéni órák blokkja buborékok + három tény +
  ajándék-szelvény, a stretching/relaxáció kártyák színesek, ecsetvonással és kerek hossz-pecséttel; „csak a csoportos
  óra nézhető vissza, csak én szerepelek rajta”; GYIK: az óratípusok színes címkék, a főoldali GYIK-cím nem ragad;
  ajándékutalvány: napfényes portré, a hosszú cím kisebb, lágy kártyák kategóriánként, a választott kiírva az űrlap
  fölött; Jógáról: minden írás egy oldalon, a 8 ág a végén; lábléc „Rólam”; foglalás: a vendéghozásnál a JS „$”-t írt
  forint helyett (javítva), az „1 alkalmas jegy megvétele” opció kikerült; Kapcsolat: „Online, élőben” ki. Üzenet
  Olíviának: 9148655. Tesztek 1122/0.
- **3.11.20 (olivia-studio 1.10.11):** páros ajándékutalvány két címre (ugyanaz a kód, az e-mail a másikat is
  megnevezi; rendelés-meta `recipient_name2/email2`); a Jógáról „Összes írás” végre minden cikket mutat (a
  `template-parts/cards.php` `$posts` változója a fő lekérdezés listáját írta felül – referenciával kötött globális!);
  a fejlécszöveg nem ér a fotó hullámába. Minta ajándékutalvány-e-mail képként Olíviának (chat 9148656). Tesztek 1128/0.
- **3.11.21–3.11.26:** a szolgáltatás-dobozok képei végül lágy, egymásba olvadó pasztell körök (lassan „lélegeznek”;
  a rajzos változatot Olívia gyerekesnek találta), `oy_way_art()`; a kismama-modulok sarkába belógó pasztell kör az oldal
  négy színével; a stretching/relaxáció kártyákon nincs ecsetvonás és szaggatott pecsét, a perc a sarokkörben; az
  „Online órák” lapról kikerült a bevezető és a „Miért jó…” blokk, az órák fotós kártyák (`oy_class_cards()`), a
  „Melyik órát válasszam?” öt színes doboz (`[oy_art]`), új első mondat az egyéni óráknál; a szöveges listákban pasztell
  pötty; a fejléc gombja „Fiókom”. Az 1114d `_insert`/`_replace` párok mostantól feloldják a `{{class:…}}` linkeket.
  **Nyitott (Áron dönt):** Olívia a lábléc készítő-sorába Áron nevét és egyéni oldalát kérte a „Készítette:
  HelloProVision” helyett – márkaszabály-ütközés, nem változtattam. Üzenet Olíviának: 9148658. Tesztek 1155/0.
- **3.11.27–3.11.31:** „Ismerj meg” gomb; a csoportos óra leírása Olívia szavaival (nincs napüdvözlet, talajgyakorlatokkal
  indul, rövid relaxáció, minden óra egy testrészre fókuszál), a kezdő dátumok bekezdése ki, az „Online, élőben” részbe az
  eszközök; „elfogadhatsz, majd ki tudod fizetni, vagy a bérletedből foglalhatsz”; élesebb fotók (a kivágott képek `sizes`
  értéke); az „Online órák” lapon a csoportos óra széles kártyaként felül, alatta egy sorban a négy egyéni (nincs árva 5.),
  a „Melyik órát válasszam?” soronként egy kérdés; a főoldali egyéni blokkban a hosszú buborék-lista helyett négy lágy doboz,
  a teljes lista az egyéni óra oldalán (`#kinek-szol`, két hasáb). 1114d rev t: a kártyák rövid szövege (`_excerpt_prev`)
  is frissül, ha még a régi. Tesztek 1172/0.
- **3.11.32:** az órák oldaldoboza nem vágódik le görgetéskor („ez megint kicsit levágódik”): ha nem fér az ablakba,
  előbb a fotó tűnik el (`is-compact`), aztán a hosszú megjegyzések 3 sorra rövidülnek (`is-tight`, kattintásra kinyílnak),
  és csak ha így sem fér el, görög a lappal. Tesztek 1174/0.
- **3.11.33:** az egyéni óra oldalán a „Kinek szól” lista négy témás pasztell dobozban („Ha tanulnál, fejlődnél”, „Ha a
  tested kéri”, „Ha a lelked kéri”, „Kettesben, a te idődben”) – Olívia: „legyen szebben összeszedve”. 1114d rev u. Tesztek 1177/0.
- **3.11.34:** a kismamatorna kilenc modulja hármasával (3×3, nincs árva kilencedik); az egyéni óra „Miben segíthet…?”
  kérdései kártyákon, kettesével – élesen a lapon lévő szövegből épül újra (`oy_h3_cards()`), mert Olívia maga is átírta. 1114d rev v.
  Tesztek 1182/0.
- **3.11.35:** az egyéni órák (egyéni jóga, kismama, stretching, relaxáció) árai kártyákon: Egyedül / Párban × hossz, 1 alkalom
  és a bérlet (alkalmankénti árral) – `[oy_prices service=…]`, a foglalórendszer termékeiből, így mindig a pénztár szerinti ár
  látszik; Olívia megjegyzései (bérlet érvényessége) alatta maradnak (`oy_price_section()`). 1114d rev w. Tesztek 1184/0.
- **3.11.36:** minden óra oldalán külön „Mire lesz szükséged?” rész (a GYIK eszközlistájából, órafajtánként; a kismamánál már
  volt), a csoportos óránál az eszközök kikerültek az „Online, élőben” bekezdésből; új `_before` pár a tartalomfájlban (egyszer
  szúr be, jelölő alapján). Bejelentkezve (admin sáv) az oldaldoboz a sáv alá is számol (`--wp-bar`), nem vágódik le a teteje.
  1114d rev x. Tesztek 1193/0.
- **3.11.37:** a „Melyik órát válasszam?” sorok a lap teljes szélességében (kérdés + óra | leírás | gomb); az „Ajándéknak is”
  sávból kikerült a jegy-kivágás, a szaggatott keret, a dőlés és a nagybetűs cím (az átmenet maradt); a zöld kismama-blokkon
  nincs zöld sarokkör (sárga helyette); „A képedet csak én látom.”: pipa helyett sarokba belógó pasztell kör. Tesztek 1193/0.
- **3.11.40:** főoldali GYIK: a „Miért jó online jógázni?” kikerült (a GYIK-lapon marad), bekerült a „Mi van, ha késve
  érkezem…”, az utolsó a „Sérülésem vagy egészségügyi problémám van…” (`faqs_home` a tartalomfájlban; élesen a jelölő és a
  sorrend). Kikerült a „Négy ok, amiért…” és az „Az egyéni óra teljesen hozzád igazodik…” sor. 1114d rev 2026-11-15a. Tesztek 1200/0.
- **3.11.41:** az órarend alatti „Egyéni órák” doboz mondata egy sorban (asztali nézetben). Tesztek 1201/0.
- **3.11.42:** Áron: „Nem magyaros amit csinálsz” – a napi új szövegek természetes magyarul (összpontosít, „Kettesben vagy
  más napon”, „ha nemrég szültél…”, „egy kattintással elfogadhatod, aztán kifizetheted…”, „Kell még egy kamerás telefon…”,
  „Fájdalomra, feszülésre”, „kettőtöknek összesen, fejenként olcsóbb”); Olívia pontjai: az egyéni blokk tényei sötét körök
  nélkül, az ajándéksáv nyila ki, a nagy címek sortávja nagyobb (ékezet ≠ vessző), a főoldali GYIK-ből a „Mennyire nehezek…”
  ki. 1114d rev 2026-11-15c. Tesztek 1210/0.
- **3.11.43:** a „Mire lesz szükséged?” minden óra oldalán előre került (az első rész után), kártyákon (eszköz + rövid megjegyzés,
  sarokkör), a kamerás eszköz külön sávban; a kismamánál Olívia saját listája csak átkerült és kártyás lett (`oy_kit_up()`,
  `_kit` a tartalomfájlban). 1114d rev 2026-11-15d. Tesztek 1219/0.
- **3.11.44:** az órák oldaldoboza az online oldalon kép nélküli (a kismamáé is, Fitmummy-kép nélkül), és csak az adott órára
  vonatkozó lemondási szabályt mutatja (egyéni órán az egyénit, a csoportoson a csoportosat); a szűkítés soha nem vág le szöveget
  (nincs „…”), csak képet és térközt. Minden mért ablakméretben fixált marad. Tesztek 1222/0.
- **3.11.45 (olivia-studio 1.10.12):** a „Bejelentkezés és árak” árkártyái ugyanúgy néznek ki, mint a főoldalon (saját pipák, nem a
  szöveg pasztell pöttyei); a foglalás visszaigazoló e-mailjében (és a vendég meghívójában) „Mire lesz szükséged az órán?” doboz
  az óra oldaláról vett eszközlistával (`oys_session_kit` szűrő → `oy_kit_items()`); a Rólam oldalon a Fitmummy-képzés kártya:
  kép balra, felirat mellette. Tesztek 1229/0.
- **3.11.46:** a GYIK-ben egyszerre csak egy kérdés van nyitva (`<details name="oy-faq">`, régebbi böngészőn a site.js zárja
  a többit). Tesztek 1230/0.
- **3.11.47:** az online oldal e-mail-címe mindenhol info@oliviajoga.online (fejléc, lábléc, kapcsolat, ÁSZF, impresszum,
  adatvédelem, válaszcím) – élesen az `email_swap` lépés cseréli a régi címet minden szövegben és beállításban; **a foglalási
  értesítések (notify_email) továbbra is Olívia Gmailjére mennek** (ha az info@-t is nézi, át lehet állítani). Tesztek 1233/0.
- **3.11.48:** az online oldalon nincs piktogram (`oy_pictogram()` üres, így az `[oy_picto]` is); a pontokba szedett listák
  sima szövegek, a címek fölött rövid színes vonal. Tesztek 1235/0.
- **3.11.49–50 (olivia-studio 1.10.13):** főoldal: „Az egyéni jógáról bővebben” link; kikerült a „Stretching, relaxáció és légzés”
  blokk és alóla az „Egyéni órák a merev testnek…” sor; a „Dinamikus, mégis gyengéd” bemutatkozás bekezdései kártyák, egymás után
  úsznak be, egérre kicsit megemelkednek. „A képedet csak én látom” mindenhonnan ki (a GYIK-válasz élesen `text_swap`-pel):
  élőben a résztvevők is látják egymást, ha bekapcsolják a kamerát – **az élő teremben is így lett**: kis órán és egyéni órán
  mindenki látja mindenkit (live.js `seesEveryone()`), nagy órán csak az oktató. A felvételen továbbra is csak Olívia. Tesztek 1239/0.
- **3.11.51:** a lábléc készítő-sora „Készítette: Ritók-Filip Áron”, az aronritok.com-ra linkelve (Olívia kérése; Áron 11.15-én
  engedélyezte: „Csinálj meg mindent az élesbe”; a tudásbázisban kivételként rögzítve). Tesztek 1240/0.
- **3.11.52:** a főoldali Ha–Tha kép mozog: a Nap lassan izzik, a Hold fázist vált, és görgetéskor beúsznak (Olívia:
  „legyen animálva”; aki a rendszerében kikapcsolta a mozgást, annak áll). A főoldalon a kismamatorna került az egyéni
  jóga elé, a blokkokban és a felső dobozokban is („az egyéni online jógaórát és a kismamatornát a főoldalon cseréljük meg”).
- **3.11.53:** Olívia 11.14-i doksijának újabb pontjai, élesben:
  - főoldal: a „Hogyan működik” blokk most az élő csoportos óráról szól, röviden; a lépéseken sima szám áll pasztell
    körben, vonalak nélkül; a pontok előtti vonalkák helyett is kerek jel van;
  - a GYIK főcíme „Kérdésed van?” lett (az első kérdés túl erőltetett volt főcímnek);
  - kismamatorna: mobilon a modulkártyák lenyithatók;
  - az óraoldalak nyitó sorai az óra színében, dobozban jelennek meg; ez megjelenítéskor készül, Olívia szövege
    szerkeszthető marad;
  - „Bejelentkezés és árak”: a csoportos óra adatai kis kerek címkékben; az egyéni órák része ugyanolyan dobozsor,
    mint az „Online órák” oldalon;
  - Kapcsolat: „Visszahívást kérek” doboz, csak egy telefonszám-mezővel; a szám e-mailben megy Olíviának.
- **3.11.54:**
  - Javítás: a szövegből épülő dizájnok (az óraoldalak nyitódoboza, az árak oldal infói és dobozai) a kész oldalon,
    Olívia saját szerkesztései (oldalszerkesztő) után készülnek. A 3.11.53-ban előtte készültek, ezért az árak oldalon
    két általa törölt mondat visszajött, az óraoldalakon pedig nem jelent meg a doboz.
  - Ha–Tha: a Nap és a Hold összeúszik egy félig narancs, félig lila körbe, rajta HATHA felirattal, aztán szétválnak
    („egyik átmegy a másikba és oda-vissza”).
  - Főoldal, egyéni jóga: egy halk tényezősor, a két gomb és egy kis „Ajándékba is adhatod” link („túl katyvaszos”).
- **3.11.55:** Rólam: a kis hullámok helyett kerek jelek („ezek a hullámok az egész rólamban bénák”). A fejezetcímek előtt
  és a kiemelt mondat fölött-alatt két kis, egymásba érő kör áll; a fejezetcím utáni vonal elmaradt. A felsorolások
  pontjai az egész oldalon kis színes körök.
- **3.11.56:** sitemap a Search Console-hoz (Áron, 11.15.). A „noindex” oldalak kimaradnak a `wp-sitemap.xml`-ből
  (Foglalás, Fiókom), különben a GSC hibát jelez. Az online oldalon a bővítmény angol „Teachers” oldala is noindex lett,
  és kikerült a sitemapből. Két dátumfüggő teszt (egy vasárnap éjjeli átfedés) 9 nappal előre foglal.
- **3.11.57:** Olívia 11.15-i doksija: a pontok előtti kerek jel nem lóg bele a cím szövegébe, a cím előtt áll külön
  („az irány jó, de nem tetszik, hogy belelóg a szövegbe a kör, telón se néz ki jól”). A főoldal „Miért jó online” és
  „Így működik” listáján is így van.
- **3.11.58:** az online oldal szövegbetűje Cabin a Quicksand helyett, mindenhol („mindenhol ahol quicksand betűtípus van,
  Cabint szeretnék helyette”). A címek Anton betűje maradt, a többi oldal Quicksandje is.
- **3.11.59:** Olívia 11.15-i doksija:
  - az árlista (főoldal és Bejelentkezés és árak) szolgáltatásonként lenyitható, asztalin és mobilon is; egyszerre egy
    van nyitva, az első alapból; a megérintett cím a helyén marad, ha a fölötte lévő bezárul;
  - a „Megkapod a linket” lépés: „…a böngésződben nyílik meg, nem kell semmit letöltened”;
  - a „Tényleg működik a csoportos jóga online?” kérdés lekerült a főoldalról (a GYIK-oldalon megmarad; migráció
    2026-11-15g).
- **3.11.60:** a főoldali kismamatorna-blokk háttere az oldal pasztell lilája (`--lilac-soft`) („lehet az a pasztell lila,
  amit használunk”).
- **3.11.61:** az árlistán a csoportos órák alatt csak „90 perces órák szerdánként 18:30-tól 20:00-ig.”, a „Gyere alkalmi
  jeggyel, vagy spórolj bérlettel” kikerült („kiszedhetjük”).
- **3.11.62:** Jógáról: hét cikk-kártya képén lejjebb került a kivágás, hogy Olívia látsszon („ezeket a képeket is csúsztassuk
  feljebb”): Egyensúly 55%, Légzés 85%, Meditáció 65%, Ászana 42%, Pránájáma 55%, Dhárana 58%, Szamádhi 60%. Csak ott változik,
  ahol a kivágás még a régi (50% 22%); migráció 2026-11-15h.
- **3.11.63:** Olívia 11.15-i doksija:
  - „Pajzsmirigy harmonizálás jógával”: bekerült a lényeg, két pózblokk az oldal stílusában (túlműködésre 6 nyugtató,
    alulműködésre 8 élénkítő ászana). Olívia saját fotóiról kiretusáltam a ráégetett feliratokat, a nevek az oldal betűjével
    állnak alattuk. Képek: `assets/img/poses/` (600/900 px); shortcode: `[oy_poses set="tul|alul"]`; migráció: 2026-11-15i,
    egyszer szúrja be.
  - Az óraoldalak árkártyái lenyithatók (a fejlécben: egyedül/párban, hossz, „…-tól” ár), egyszerre egy van nyitva.
  - Asztali nézetben nagyobb a menü betűje (1100 px felett 1,02rem).
- **3.11.64:** Olívia 11.15-i doksija, Rólam („rólamot még kicsit módosítsuk sorrendben és egy-két képet hozzácsatolva”):
  - Az „Az utam” idővonal került a lap elejére.
  - „A kezdetek” alatt az új tornatermi kép és a szabadtéri csoport, „Amikor jött a jóga” alatt a sziklás harcos és a réti
    nyújtás, „A matracon túl” elején egy széles kép (journal-15).
  - A Fitmummy-oklevél kártyája nagyobb (42% széles, 4:5-ös kép).
  - Új képek: `about-gym-class`, `about-rock-warrior`, `about-meadow-stretch`, `article-fish-pose` (a halpóz a következő
    cikkhez); migráció: 2026-11-15j, egyszer rendez át.
- **3.11.65:** Rólam, „A matracon túl”: „a szöveg végére meg kisebben ezek a képek jöhetnek” – négy kisebb kép egy sorban
  (mobilon 2×2) a titok-doboz után, a gomb előtt: Olívia három festménye és egy naplementés fotója. Shortcode:
  `[oy_figures photos="…" class="oj-duo--row"]`; képek: `about-painting-embrace`, `about-painting-birds`,
  `about-painting-mask`, `about-sunset-river`; migráció: 2026-11-15k, egyszer szúrja be.
- **3.11.66:** Olívia 11.15-i doksija, délutáni pontok:
  - Új címek: `/orarend-es-arak/` → `/bejelentkezes-es-arak/`, `/online-jogaorak/` → `/online-orak/` (az órák is:
    `/online-orak/{óra}/`). A régiek 301-gyel átirányítanak, a paraméterekkel együtt. A tartalomban lévő linkeket a
    migráció átírja. Az oldalszerkesztő a szövegeket link nélkül kulcsolja, így Olívia átírásai megmaradnak.
  - **Sortöréses bekezdések:** amit Olívia új sorokba tördelt, az a szöveg hasábjában pasztell doboz lesz (`oy_lines`).
    A „Hogyan működik? / Mi történik…? / Mit csinálunk egy órán?” alatt számozott lépés-ábra (`oy_flows`). A GYIK-ben a
    gondolatjeles sorokból lista lesz. Mind a kész oldalon fut, az ő szerkesztései után.
  - **Bejelentkezés és árak oldal:**
    - a csoportos doboz címkéi kiegészültek: foglalj előre, legalább 3 fővel indul, kezdők is (az órarend alatti
      bekezdés kikerült);
    - az ajándékutalvány saját kártyát kapott;
    - a bérletes bekezdés kikerült;
    - az „Amire szükséged lesz” a GYIK válasza (`[oy_faq_answer]`);
    - az árkártyákon pipák vannak, nem színes pöttyök.
  - **Óraoldalak:**
    - az egyedüli árkártyák mindig nyitva vannak, a párosak mobilon lenyithatók, asztalon nyitva;
    - az egyéni óra nyitó dobozában az „Egyéni órán neked…” a kiemelt sor;
    - a kártyaszövegek Olívia kérése szerint változtak (jógafilozófia, „Kezdőket is szeretettel várok” az egyénihez,
      dinamikus nyújtás, „kíméletes nyújtás” ki, „sokaknak” ki, „Persze, ha erre van szükséged.”);
    - „jobban tudlak korrigálni és segíteni”;
    - a relaxáció „Miért a légzés?” része oda-vissza hatásról szól.
  - **Rólam:**
    - a képzések két oszlopban (fotó + 2023, Fitmummy + 2026);
    - az „Ahol eddig tanítottam” dobozban széles fotó (`park-class-seated`);
    - az „Amiben ma segítek” új kártyákat kapott (pasztell, sarokkör, nyíl);
    - „A matracon túl” végén ötödik kép is van (henna).
  - **Főoldal:** a kismama-csempék pasztellzöldek, az egyéni blokk négy kártyája szebb, a lábléc „Bejelentkezés és árak”.
  - Migráció: 2026-11-15n. Tesztek: 1297/0.
- **3.11.67:** Online órák, „Hogyan zajlik egy élő online óra?” („itt is legyen meg a szokásos folyamatábra, az jó lett”):
  ez is lépés-ábra lett. Négynél több lépésnél asztalon is egymás alatt marad, idővonalként, mert egymás mellett túl keskeny
  lenne. Tesztek: 1297/0.
- **3.11.68:**
  - Bejelentkezés és árak, „Jó tudni”: a „Lemondás:” és az „Új vagy a jógában?” bekezdés saját kártyát kapott
    (ikon, címke, szöveg), asztalon egymás mellett („ezek is külön legyenek szebben kiemelve”).
  - A sortöréses doboz nem lehet rózsaszín közvetlenül a rózsaszín „titok” cetli előtt, ilyenkor zöld („ez legyen más
    színű”).
  - Tesztek: 1299/0.
- **3.11.69 – új színpaletta** (Olívia, 11.15: „meggondoltam magam és szeretnék módosítani a színpalettán!!! ez alapján legyen
  minden!!! ha kell, akkor még azokat a világosakat, amit szeretek hozzárakhatod!”):
  - Paletta: Evergreen #013220, Frozen Water #DAFFEF, Bright Gold #FFDF22, Sweet Salmon #EB9486, Reddish Brown #A44A3F;
    a két világos, amit szeret, megmaradt: halvány zsálya #DEE5D3, barack #FFE2CC.
  - Csak az online kiadásban (`online.css` végén `:root`): a tokenek neve marad, a téma és a bővítmény is ezeket olvassa.
    `forest`/`ink` → Evergreen, `lilac` → menta (Frozen Water), `pink` → Sweet Salmon (`pink-soft` → barack),
    `sun` → Bright Gold, `orchid` (a fehér feliratú gombok) → Reddish Brown (fehér rajta 5,8:1).
  - A beégetett színek (pöttyök, árnyékok, SVG-k, logó, böngészőszín) is átkerültek. A kismama-csempék barackszínűek a
    menta háttéren. A közepes zsálya #A9BFA0 maradt, átvezet a zöldek között.
  - Tesztek: 1301/0.
- **3.11.70:** A „Foglalkozol mással is?” cikk helyére Olívia Facebook-bejegyzése került (chaten küldte el):
  - A cím az ő mondatából: „Törődni magaddal nem luxus, hanem példamutatás”. A cikk címe (URL) marad:
    `/foglalkozol-massal-is/`.
  - A végére bekerült a „Legyen több olyan gondolat, amiben jó lakni!” mondat. A kép a halpóz (`article-fish-pose`).
  - A betegségek rendes lista lettek, a „Gondolat ➡️ idegrendszer ➡️ …” sor kiemelt idézet, és két alcímet kapott az ő
    mondataiból.
  - Javított elírások: immunrendszer, Crohn, Hashimoto, YouTube, szív- és érrendszeri, anyagcserezavarok.
  - Migráció: 2026-11-15o, csak ha még a régi szöveg van ott. Tesztek: 1302/0.
- **3.11.71:** „ez nem az a kék mint a színpalettában”:
  - Ahol a menta felületet tölt ki (kártyák, a hero szava, dobozok, fejlécek, szekcióhátterek), ott pontosan a Frozen Water
    `#DAFFEF` van.
  - Az apró jelek (pöttyök, körök, szélek, lépésszámok) a mélyebb `--lilac-mark` `#8FD9BC`-t kapják, hogy a világos
    háttéren is látsszanak.
  - „a bal oldali zöld szín megmaradhat dominánsnak”: az Evergreen marad a meghatározó szín, nincs teendő (a „két szélső
    barna” mondatot Olívia átírta erre).
  - Tesztek: 1302/0.
- **3.11.72:** Olívia 11.15-i késő esti doksipontjai:
  - „itt is javítsd az url slugot és az emojik helyett legyen az oldal stílusához illeszkedő design”: a cikk címe
    `/torodni-magaddal-nem-luxus/` lett, a régi `/foglalkozol-massal-is/` és az angol cím is egy lépésben 301-gyel ide
    visz (`OY_ONLINE_POST_MOVED`). A betegséglista fehér kártyák lettek (a betegség félkövéren, alatta az oldal saját
    nyila után a magyarázat), a „Gondolat → idegrendszer → hormonrendszer → immunválasz” sor zöldülő pirulák lánca
    (telefonon oszlopban). A 🙏 helyére pont került. A migráció a meglévő szöveget alakítja (`oy_arrow_marks()`), nem
    írja felül.
  - „favicont is cseréld a megfelelő színűre”: Evergreen kör, Tangerine Dream pötty (csak az online oldalon).
  - „ez a 3 szín legyen a főcímben”: jóga – Bright Gold, kismamatorna – Sandy Brown `#F7934C`, mobilizáció –
    Tangerine Dream `#FFAC81`.
  - „ez a szín legyen”: az órakártyák kis lélegző köre Tangerine Dream.
  - „ez túl színes, legyenek a színek naturálisabbak”: a kismama-modulok fehér csempék, a sarokkörök csak zsályazöld,
    menta és páfrányzöld.
  - „ez is kicsit túl gyerekes, legyen letisztultabb de az irány tetszik”: a „Kezdőknek / Haladóknak / …” kártyák
    majdnem fehérek, a színük csak finom keretben és a kis pöttyben maradt, a sarokkörök eltűntek.
  - Migráció: 2026-11-15p. Tesztek: 1308/0.
- **3.11.73:** Olívia 11.15-i doksija, az óraoldalak fejlécszínei (`OY_ONLINE_HEAD_TONES`, eddig a cím
  alapján véletlenszerűen jött a szín):
  - „Ez maradjon meg a frozen water színűnek”: Relaxáció és légzés – Frozen Water (eddig sárga volt).
  - „az egyéni online jógánál legyen ez a szín: sandy brown”: Egyéni online jóga – Sandy Brown `#F7934C`. Előbb egy
    Reddish Brown színmintát tett be, aztán kivette. A `page-head--orchid` tónus (fehér betű, mandarin vonal a kép
    hullámán) megmaradt a témában, de most nem használja oldal.
  - „csoportos flow: evergreen”: Csoportos online flow jóga – Evergreen, fehér címmel és szöveggel.
  - „kismamatorna: tangerine dream”: Online kismamatorna – Tangerine Dream.
  - A Stretching oldalról nem írt, az maradt sárga.
  - „itt ha ráviszem a kurzort legyen a tangerine dream szín”: az „Online órák” lenyíló menüben a kurzor alatti sor
    Tangerine Dream.
  - Tesztek: 1311/0, tiszta teszt-adatbázison. A helyi teszt-adatbázisban korábbi futásokból maradt órák az üdvözlő
    e-mail tesztjét elbuktatták, ezért újratelepítettem, ahogy a CI is teszi.
- **3.11.74:** Olívia 11.15-i doksija, kezdőlap:
  - „ez is legyen a tangerine dream színű a főoldalon”: a Csoportos online flow jóga kártyája Tangerine Dream lett (eddig
    menta volt, mint a relaxációé). A kis lélegző köre Sandy Brown, hogy látsszon a mandarinon.
  - „itt legyen valami semlegesebb, szebb színű háttér”: a kismamatorna-szekció háttere a paletta világos zsályazöldje
    (`--sage-soft`) a menta helyett. A fehér csempék maradtak.
  - „nem látszik a felirat”: a Nap–Hold blokkban a hold világos része Frozen Water lett, és rajta eltűnt a fehér
    „THA”. Most sötét, mint a „HA”, és a hold világos részére került.
  - Tesztek: 1313/0.
## Következő kör – nyitott pontok / ötletek
- Admin felület magyarul (fordítási fájl), ha Olivia így kényelmesebb (a látogatói felület már 3 nyelvű).
- Tagság: csomagváltás (upgrade/downgrade), szüneteltetés, próbaidőszak – ha kell.
- Valódi Stripe teszt-fiókkal végigpróbálni (a fejlesztői környezetből a Stripe nem érhető el, szimulátorral tesztelt).
- Főoldal szövegeinek szerkeszthetővé tétele a Customizerben.
- Ügyfél-visszajelzés a színarányokra (mennyi pink / lila / barack).
- Mobil finomhangolás (hero, órarend, árkártyák).
- Élesítés előtt továbbra is: SITE_URL, foglalási rendszer, valós órarend és árak (most MINTA adatok).
