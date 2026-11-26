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
- **3.11.75:** „legyen ez az új paletta, ez alapján cserélj, de figyelj az előző szabályokra, amiket mondtam”.
  - Az új paletta: Evergreen `#002E2C`, Platinum `#F0EDEE`, Bright Gold `#FFDF22`, Magenta Bloom `#E63462`, Tangerine
    Dream `#FFAC81`.
  - Hova került:
    - az Evergreen a sötét felületekre, a szövegre és a vonalakra;
    - a Platinum az oldal hátterére;
    - a Magenta Bloom a régi Sweet Salmon helyére (pöttyök, szélek, a hero hulláma);
    - a régi Reddish Brown helyére is Magenta került, kicsit mélyebben (`#DB2E5C`, fehér szöveg 4,6:1): gombok, címkék,
      a logó hulláma, a fejlécek fotójának vonala;
    - a kezdőlap egyéni jóga kártyája Magenta, fehér betűvel.
  - A korábbi szabályai maradtak:
    - a főcím három színe;
    - a relaxáció Frozen Waterje és a menta;
    - az óraoldalak fejlécszínei;
    - a mandarin körök és menü-kiemelés;
    - a természetes zöld kismama-csempék;
    - a kismama-szekció zsályazöldje;
    - a letisztult „kinek” kártyák.
  - A favicon és a böngésző színe az új Evergreen.
  - Tesztek: 1313/0.
- **3.11.76:** Olívia doksija, az új palettához:
  - „háttér egésze legyen ffdf22 színű, jóga legyen f0edee színű hátterű, kismamatorna e63462 és a mobilizáció ffac81”:
    a kezdőlap hero része Bright Gold, a három szó Platinum, Magenta Bloom és Tangerine Dream.
  - „itt ha ráviszem a kurzort legyen ugyanaz a sárga szín mint amit most küldtem a palettán”: a lenyíló menü kiemelése
    Bright Gold (eddig mandarin volt).
  - „ezeket a kék háttereket hagyjuk el mindenhol”, „az ilyen kékeseket hagyjuk el” (az ajándékutalvány színátmenete és
    a GYIK címkéi):
    - a menta tónus Tangerine Dream lett, a halvány változata a barack `#FFE2CC`;
    - ahol egész kártyát vagy fejlécet töltött ki (a relaxáció kártyája és oldala, a kapcsolat, a GYIK), ott a halvány
      barack;
    - böngészőben átvizsgáltam a fő oldalakat, menta háttér sehol nem maradt.
  - Olívia a doksiban közben leírta, majd kitörölte, hogy az egyéni jóga mandarin, a flow Evergreen legyen. Ezekhez nem
    nyúltam.
  - Tesztek: 1314/0.
- **3.11.77:** „hallod így nagyon jól néz ki, itt a hátsó hullám még legyen a zöld szín”:
  - A kezdőlapi hero fotója mögötti hátsó hullám Evergreen lett, az elülső magenta maradt.
  - „favicont is igazítsd a jelenlegi színekhez”: Evergreen kör, benne Magenta Bloom pötty, mint a logó hulláma.
  - Tesztek: 1315/0.
- **3.11.78:** Olívia 11.15-i esti hosszú listája.
  - **Egy szín minden órához, mindenhol** (`oy_class_hue()`, „az azonos témák legyenek azonos színűek. A főoldalon is
    ugyanúgy”).
    - A színek:
      - csoportos flow: Evergreen, fehér betűvel;
      - kismamatorna: Magenta Bloom, fehér betűvel;
      - egyéni: Tangerine Dream;
      - stretching: sötétzöld (`--fern`), fehér betűvel;
      - relaxáció: a kezdőlapi kártyán a kért zsálya, a fejlécen és az óra-kártyán halványsárga.
    - Hol jelenik meg:
      - a kezdőlapi kártyákon;
      - az óra-kártyákon (`oj-class-card`);
      - az óraoldalak fejlécén;
      - a „Melyik órát válasszam?” dobozokon (a link alapján).
  - **Kezdőlapi kártyák** („ezeket a boxokat csináld meg olyasmi stílusban mint ezek”): felül az óra fotója, alatta az
    oldalfejlécek hullámos széle (világosabb sáv, magenta vonal, a magenta kártyán sárga).
  - **Fejlécek:** a Kapcsolat fejléce Bright Gold, a GYIK-é halványsárga.
  - **Paletta mindenhol:** zsálya helyett Tangerine, Magenta, Bright Gold és Evergreen. Érintett elemek:
    - a „kinek szól” kártyák és csoportok;
    - a „miben segíthet” pöttyök;
    - az eszközlista;
    - a kismama-csempék;
    - a Rólam dobozainak sarkai (és a szöveg nem lóg bele a körbe);
    - az árkártyák.
  - **Hullámok a kezdőlapon:**
    - a bemutatkozó fotó ecsetvonása magenta és sárga;
    - a kismama és az egyéni blokk fotója mögött sárga és zöld;
    - a hero elülső hulláma Platinum.
  - **Egyéb:**
    - az órarend időpont-dobozai sárgák;
    - a naplókártyák címkéje témánként azonos színű (a kezdőlapon is);
    - mobilon a menüben sárga a kiválasztott/megérintett óra;
    - mobilon csak egy foglalás-gomb van (az alsó sáv mellett nincs lebegő gomb).
  - **„Itt folyamatábra helyett legyen valami kreatív hullám”** (a flow óra „Mi történik…” része): egy hullám fut a
    lépések fölött, ahogy az óra halad. Csendes érkezés, emelkedés a bemelegítésen és a flow-n át, a figyelem a tetőn,
    lecsendesedés a végén. A számok a hullámon ülnek, telefonon a hullám oldalt fut lefelé.
  - **Következő kör (3.11.79):** „aloldal urleket ellenőrizd mindenhol hogy átírtad-e arra ami az oldalcím/h1”.
    - Eltérők élesben: `gyik`, `aszf`, `adatvedelem`, és az óraoldalak (`hatha-flow`, `kismamatorna`, `stretching`,
      `relaxacio-legzes`).
    - Az óraoldalak címét a kód sok helyen kulcsként használja (a bővítmény szolgáltatásai is), ezért ezt külön, óvatos
      körben kell átírni, a régi címekről 301-gyel.
  - Tesztek: 1320/0.
- **3.11.79:** „aloldal urleket ellenőrizd mindenhol hogy átírtad-e arra ami az oldalcím/h1”.
  - GYIK: `/gyik/` → `/gyakori-kerdesek/` (a migráció átnevezi és a linkeket is átírja, rev `2026-11-15q`).
  - Óraoldalak, nyilvános címek (`OY_ONLINE_CLASS_PATHS`):
    - `/online-orak/csoportos-online-flow-joga/`
    - `/online-orak/online-kismamatorna/`
    - `/online-orak/online-stretching-es-mobilizacio/`
    - `/online-orak/relaxacio-es-legzes/`
  - Az órák belső neve (post_name) nem változott: a foglalások és a bővítmény ezen ismerik őket (`class_slug` az
    adatbázisban). Csak a permalink (`post_type_link`) és a kérés-feldolgozás (`request`) fordít.
  - A régi címek 301-gyel átirányítanak, a régi `/online-jogaorak/{óra}/` is egy lépésben.
  - Az `aszf` és az `adatvedelem` maradt: rövid, bevett jogi címek, és e-mailek, valamint a fizetés hivatkozik rájuk.
  - A naplóbejegyzések rövidített címei maradtak.
  - Tesztek: 1321/0.
- **3.11.80:** Olívia doksija.
  - „ide tehessek külön képet és ne ugyanaz legyen mint amit a csoportos órához teszek be”: a kezdőlapi hero fotója
    saját kulcsot kapott az oldalszerkesztőben (`data-oyp="home-hero"`), így már nincs összekötve a csoportos óra
    képével.
  - „csoportos flow jóga szolgáltatáshoz mindenhova ezt a képet tedd be”: az ő tóparti teraszos fotója
    (`flow-group-lakeside-deck`) lett a csoportos flow óra képe. A migráció egyszer beállítja (`oy_flow_photo_set`),
    így a kártyákon, a fejlécen és a kezdőlapon is ez látszik.
  - „itt legyen benne a fejem”: a kezdőlapi stretching kártyán a fotó feljebb kerül, a feje a hullám fölött látszik,
    telefonon is.
  - „ugyanakkora betűméretek”: a „fejenként” ár akkora, mint az „Egy óra: …” címke.
  - Tesztek: 1323/0.
- **3.11.81:** Olívia chatje („tesztelj mindent … kérj elfelejtett jelszót”) és a doksi újabb pontjai.
  - Felhasználói teszt (telefon, tablet, gép): regisztráció → fiók → kilépés → belépés → elfelejtett jelszó végig
    működik. Új `inc/login.php`: a jelszóképernyők (`wp-login.php`) az oldal kinézetét kapják (logó, paletta, Cabin).
    Az online oldalon tegezve szólnak, a „Vissza” a Fiókomra visz, nincs nyelvválasztó. A kérés után a „Nézd meg a
    postafiókod” oldal jön (`lostpassword_redirect` üres), nem némán a Fiókom. Maga a levél is a stúdió levélkinézetét
    kapja (`retrieve_password_notification_email`).
  - Mobil: a szövegen kívüli linkek és gombok legalább 44 px magasak (lábléc, „Összes óra”, Rólam kártyák,
    Kapcsolat linkek, fülek, összecsukók).
  - „jó hullám csak ne legyen ennyire színes”: a flow óra hulláma két színű lett: zöld vonal, sárga pöttyök, fehér
    kártyák. Öt lépésnél a szöveg kisebb, és a hosszú szavak törnek.
  - „ez legyen sárga”: a relaxációs oldal légzéses doboza (`oj-lines--lilac`) sárga.
  - „minden aloldalon az árak legyenek sárgák és ez a szín vagy magenta”:
    - az óraoldalakon az egyéni ár sárga, a páros magenta maradt;
    - a „Bejelentkezés és árak” oldalon minden ár sárga, a csoportos bérlet zöld maradt;
    - a régi zsályazöld fejlécek (Online órák, Rólam, Jógáról, Impresszum, néhány cikk) magentát kaptak, fehér
      betűvel.
  - „a második bekezdést szedjük ki … időpontot tudsz kérni”: az árak oldalán kikerült a kismamatornás bekezdés, az
    első mondat „időpontot tudsz kérni” lett. Ezt a migráció (`2026-11-15s`) élesen is átírja.
  - Tesztek: 1328/0.
- **3.11.82:** Áron: „a nagy nyelvi modelleknek … LLM-es mappet … Gemini, OpenAI és Claude megismerje … a fő kulcsszavakra
  Magyarországon … Oliviát ajánlják online”. Új modul: `inc/llms.php`, csak az online oldalon.
  - `/llms.txt` (llmstxt.org): mindig az oldal adataiból épül, egy óráig gyorsítótárban, mentéskor ürül. Tartalma:
    - ki Olívia (YAI 2023, Fitmummy, hangtál, 2015-től edző);
    - mit kínál, hogyan működik az óra, mennyibe kerül;
    - **„Mikor ajánld”**: a fő magyar kulcsszavak, például online jóga, élő online jógaóra, online kismamatorna,
      kismama torna, várandós torna, egyéni online jóga, stretching, mobilizáció, légzőgyakorlatok, relaxáció;
    - minden óra címmel, leírással és árakkal (a bővítmény termékeiből);
    - a következő élő csoportos órák foglalási linkkel;
    - az oldalak, a GYIK, az írások és egy angol összefoglaló.
  - `/llms-full.txt`: ugyanez, és minden óra, oldal és írás teljes szövege Markdownban.
  - `.md` tükrök: bármelyik oldal címe után `.md` (a főoldal: `/index.md`) a kész oldalt adja Markdownban, az
    oldalszerkesztős átírásokkal együtt. A fejlécben `<link rel="alternate" type="text/markdown">` mutat rá.
  - robots.txt: név szerint beengedi az MI-robotokat (OpenAI, Anthropic, Google-Extended, Perplexity, Apple,
    DuckDuckGo, Meta, Mistral, Amazon, Common Crawl, Cohere), és megadja az llms.txt címét.
  - Strukturált adat magyarul:
    - Person: valós végzettségek, `knowsAbout` magyar kulcsszavakkal;
    - Organization: `hasOfferCatalog`;
    - minden óra Service + AggregateOffer HUF-ban; az óraoldalakon BreadcrumbList;
    - a következő 4 csoportos óra Event-ként (`OnlineEventAttendanceMode`).
  - IndexNow (Bing → ChatGPT-keresés, Copilot; Yandex, Seznam, Naver):
    - a saját kulcs az adatbázisban van, a kulcsfájlt az oldal szolgálja ki;
    - közzétételkor és frissítéskor azonnal beküldi a címet;
    - verzióváltáskor egyszer az egész oldalt;
    - helyi vagy teszt példány nem küld.
  - Tesztek: 1334/0.
- **3.11.83:** Áron: „Kutasd le, hogy mi kell ebbe az LLMS-be … és ez legyen csak benne, és azonnal küldd be.”
  - Kutatás:
    - Az llmstxt.org formátum: egy H1 (a név, ez az egyetlen kötelező), egy rövid összefoglaló idézetként, címsor
      nélküli tényblokk, aztán H2 szakaszok, de azokban csak `[név](url): leírás` linklista lehet. Az `## Optional` alá
      a kihagyható linkek kerülnek. A fájl legyen kicsi, a részletek a linkek mögött. Felfedezéshez a
      `rel="describedby"` mutat az llms.txt-re, a `rel="alternate" type="text/markdown"` a .md-re.
    - A 2026-os mérések szerint a nagy MI-robotok ritkán kérik le az llms.txt-t. A válaszokba kerüléshez a keresőrobotok
      beengedése a fontos (az OpenAI szerint az OAI-SearchBot kell a ChatGPT-kereséshez). A Claude-SearchBot és a
      ClaudeBot külön robot. Az `anthropic-ai` elavult. Ezen kívül sitemap, olvasható HTML és strukturált adat kell.
  - Az llms.txt átírva (21 KB → 5 KB):
    - H1, összefoglaló, tények (oktató, forma, árak, a következő óra, nyelv, kapcsolat), egy mondat a fő keresésekkel;
    - `## Online órák` (5 link, rövid leírás „…Ft-tól”), `## Információ`, `## Optional` (Jógáról, teljes szöveg, jogi
      oldalak).
  - Kikerült: a nem linkekből álló H2 szakaszok („Röviden”, „Mikor ajánld”), a GYIK-válaszok, a cikklista, az órarend és
    az angol rész.
  - robots.txt: `anthropic-ai` ki. A fejlécben `rel="describedby"` mutat az llms.txt-re.
  - `/wp-json/oy/v1/indexnow`: a kulcsfájl címe és az utolsó beküldés eredménye.
  - Tesztek: 1334/0.
- **3.11.84:** ellenőrzés (Áron: „ellenőrizd, mert a sitemap például be van küldve”).
  - Google: a domain DNS TXT-vel hitelesítve, a sitemapot Áron beküldte a Search Console-ba.
  - Sitemap: 44 cím, mind 200-as válasz, egyiken sincs noindex.
  - Javítás: a Jógáról oldal és a 4 kategória kapott canonical címkét (a WordPress csak egyes oldalakra írt).
  - Bing-hitelesítésnek nincs nyoma (meta, BingSiteAuth.xml).
  - A friss domain még nem látszik a keresőkben.
  - Az oliviajoga.hu (ELIN.hu) nem linkel az oliviajoga.online-ra.
  - Tesztek: 1334/0.
- **olivia-studio 1.11.0 / téma 3.11.85:** Áron: Olívia Motibro-vendéglistája („importáld őket … minden mező feleljen meg
  a listának”). Új: `includes/class-import.php`.
  - A Motibro „Vendégek” export mind a 20 oszlopa átjön:
    - a név, a becenév, az e-mail és a telefon a WordPress és a bővítmény saját mezőibe;
    - a többi `oys_*` metaként: Motibro-azonosító, nem, születési idő, regisztráció, első és utolsó óra, megjegyzés,
      kurzusok, tanfolyamtagság, számlázási név, ország, irányítószám, város, cím, adószám.
    - A fiók regisztrációs dátuma a Motibro-regisztráció napja.
  - Az admin vendéglapján új „Vendégadatok” doboz mutatja ezeket. A vendéglista 300 helyett 3000 sort mutat.
  - Az import nem küld e-mailt, nem iratkoztat fel hírlevélre (a listában nincs hozzájárulás), és a részvételi
    nyilatkozatot sem jelöli elfogadottnak. A vendég a „Elfelejtetted a jelszavad?” úton lép be először.
  - Meglévő fiók: amit a vendég megadott, megmarad, csak az üres mezők töltődnek ki. Admin vagy menedzser fiókhoz nem
    nyúl.
  - E-mail nélküli vendég is bekerül (`motibro-<id>` login).
  - Az újrafuttatás nem változtat semmin: a Motibro-azonosító alapján megtalálja a vendéget.
  - Javítás: a Motibro 88 telefonszámot tizedesként exportált („6301234567.0”). Ezek visszakapják a 06-os alakot. Az
    irányítószámokról lekerül a „.0”.
  - Két út, a személyes adat egyiken sem megy át a repón:
    - feltöltés: Stúdió → Vendégek → „Vendégek importálása” (.xlsx vagy .csv, „csak ellenőrzés” móddal);
    - REST: `POST /wp-json/oys/v1/customers/import` (rows, dry_run; csak `oys_manage` jogosultsággal).
  - Olívia listája: 286 vendég, 278 e-mail, 271 telefon. A helyi próbafuttatás eredménye: 286 új, 0 kihagyott.
  - Tesztek: 1347/0.
- **olivia-studio 1.12.0 / téma 3.11.86:** élesítés előtti átnézés (Áron: „nézd meg mindent … a telefonszám megadásakor
  nincs köszönő oldal … mindennek tökéletesnek kell lenni”).
  - Köszönőoldal (`/koszonjuk/`, noindex): a visszahívás és az üzenet után ide visz, és egy `generate_lead` eseményt
    küld a dataLayerbe. A migráció egyszer hozza létre (`oy_migrate_1116`, `hpv/hpv.php`).
  - Az Olíviának menő üzenetértesítő magyar lett („Üzenet a weboldalról”, Név, E-mail, Telefon, Téma).
  - Favicon: valódi fájlok (32, 512, apple-touch 180) a `get_site_icon_url`-en át. Eddig a `/favicon.ico` a WordPress
    szürke logójára irányított.
  - Alapértelmezett megosztási kép (`og-online.jpg`, 1200×630) a saját kép nélküli oldalakon.
  - Az admin felhasználónév nem szivárog ki:
    - a `?author=1` a WordPress kanonikus átirányítása előtt a főoldalra visz;
    - a `/wp/v2/users` bejelentkezés nélkül nem érhető el.
  - `OYS_Ops`:
    - X-OYS-Auth fejléc az alkalmazásjelszóhoz, mert a szerver eldobja az Authorization fejlécet;
    - `GET /wp-json/oys/v1/status`: levelezés, fizetés, oldalak, órarend, cron, debug, keresők; a titkok csak
      „set” vagy „missing” formában.
  - Tesztek: 1353/0.
  - Olívia 285 vendége bekerült élesben (REST, X-OYS-Auth). A 286. sor Olívia saját címe, ezt a személyzeti fiók miatt
    kihagyta. Az újrafuttatás 285-ször „változatlan”-t adott, vagyis minden mező a helyén van.
- **téma 3.11.87:** az élesítési átnézés javításai.
  - A `/favicon.ico` a saját ikonra visz (`do_faviconico`). A `get_site_icon_url` a WordPress tartalékcímét is lecseréli.
  - HSTS fejléc (1 év).
  - Rövidebb főoldali cím: „Online jóga és kismamatorna élőben, otthonról | Olívia Jóga”.
  - A hosszú cikkcímeknél elmarad az oldalnév.
  - A leírásokat a 160 karakter alatti utolsó egész mondatnál vágja. A Jógáról oldal és a kategóriák saját leírást
    kapnak.
  - Tesztek: 1353/0.
- **téma 3.11.88:** Google Analytics 4 (G-WM1P5R67VJ, a laptopon készült), csak hozzájárulással.
  - Consent Mode v2: alapból minden „denied”. A gtag.js csak az „Elfogadom” után töltődik; a hozzájárulás csak a
    mérésre szól (analytics_storage), reklámra nem.
  - Saját sütisáv („Elfogadom” / „Csak a szükségesek”), a döntés az `oy_consent` sütiben (1 év). A láblécben
    „Sütibeállítások” link (`#cookie-settings`), ami újranyitja a sávot.
  - Ha az Ads Connect bővítmény saját sávja be van kapcsolva (Meta Pixel), a mi sávunk eltűnik, és a GA a `hpvac_choice`
    sütit, illetve a `hpvac:consent` eseményt követi.
  - A köszönőoldal `generate_lead` eseménye a GA4-be is megy (`gtag('event', …)`).
  - Az adatkezelési tájékoztatóban élesben csak a „Sütik” és a „Kik férnek hozzá?” bekezdés cserélődik
    (`oy_migrate_ga4`); Olívia többi szövege marad.
  - Tesztek: 1358/0.
- **téma 3.11.89:** Meta Pixel előkészítve (Áron: „csinálj meta pixelt Oliviának”). Egy beállítás kapcsolja be:
  `oy_meta_pixel`, REST-ből: `POST /wp-json/wp/v2/settings {"oy_meta_pixel":"…"}`.
  - A Pixelt nem tudtuk létrehozni az Ads hubból. A HelloProVision.com hirdetési fiókján (act_1136444968940656) már van
    egy Pixel, és a Meta ezt adja: „#6200 A pixel already exists for this account”. A hubon létrejött Olívia profilja
    (2090002, szüneteltetve, fiók nélkül); a HPV-fiókot csak a próbára kötöttük hozzá, utána rögtön levettük.
  - Ha az azonosító be van állítva, a sáv két célt kér: Statisztika (GA) és Marketing (Meta). A „Beállítások” gombbal
    külön-külön is választható.
  - Süti: `oy_consent` = all / stats / ads / denied. A 3.11.88-as `granted` csak statisztikát jelent, ezért a sáv
    újra kérdez.
  - Az `fbevents.js` csak marketing-hozzájárulás után töltődik. A köszönőoldal `Lead` eseményt küld.
  - Az adatkezelési oldalon a Meta-bekezdés csak bekapcsolt Pixelnél jelenik meg (`the_content`); az adatbázisban nincs
    tárolva.
  - Tesztek: 1361/0.
- **téma 3.11.90:** Olívia doksija, 11.16. („oliviajoga.online módosítások 11.16.”, 7 pont).
  - Óratípus-kártyák: egérrel fölé víve megmarad a szöveg színe. Eddig a linkek magenta hover-színe eltüntette a
    feliratot a magenta kismamatorna-kártyán.
  - Képminőség: `wp_img_tag_add_auto_sizes` ki. A WordPress „sizes: auto” értéke miatt a 4:3-ra vágott fekvő fotóhoz túl
    kicsi kép jött (a stretching 300 px-es változata).
  - Kismamatorna-fotó: Olívia eredetijéből (IMG_9679, 4160×6240) 1600 px-es webes változat. REST-ből feltöltve (média
    255), és ez lett a kismamatorna kiemelt képe; a régi 500 px-es volt.
  - Online órák oldal: az „Egyéni online órák” rész tangerine-barack dobozba kerül, „Időpontot kérek” gombbal és az
    egyéni óra linkjével (`oy_private_callout`, `the_content`). Olívia szövege nem változik.
  - Hullámvonal: a pöttyök körül nincs kivágott gyűrű. Mobilon minden vonaldarab az egyik pötty közepétől a következőéig
    fut, így egybefüggő.
  - Főoldal, kismamatorna-blokk: halványsárga háttér; a kép mögött a zöld helyett tangerine, a sárga helyett halványsárga.
  - Tesztek: 1366/0.
- **téma 3.11.91:** Olívia 11.16-os doksija, új pont: „itt még nem néznek ki jól a pöttyök” (az Egyéni online órák
  doboz listája).
  - A lista saját osztályt kap (`oj-callout__list`), így az oldal általános listastílusa (`.entry ul:not([class])`) nem
    teszi a pöttyöt a fehér kártya sarkára.
  - Tangerine pötty finom gyűrűvel, az első sor közepéhez igazítva.
  - Tesztek: 1366/0.
- **olivia-studio 1.12.1 + téma 3.11.92:** a fizetés utáni visszaigazolás (Áron: „Nem tetszik” – képernyőkép a
  „Foglalva vagy!” kártyáról).
  - A kártya felépítése: pipás jelvény, „Foglalva vagy!”, alatta jegyszerű blokk (Óra / Mikor / Hol, és ha vannak, a
    vendégek), egy mondat a visszaigazoló levélről, majd a gombok. Eddig az egészet egy hosszú mondat írta le.
  - A dátum magyarul: „december 16., szerda, 18:30”, nem „… at 18:30”. Ehhez új bejegyzés került az
    `oys_date_format`-ba.
  - Nyugta: kis ikonos link. Facebook-csoport: saját sor, a márka színeiben, nyíllal (az `oys_fb_group_link` mindenhol
    nyilat kap).
  - Online téma: a `?oys_order` visszatérő nézeten nincs nagy „Foglalás” fejléc (`layout--return`). A kártya fehér,
    kerekített, a sarkában halványsárga kör; a pötty sárga, az összefoglaló platina hátterű. Telefonon a gombok teljes
    szélesek.
  - Új fordítások (hu, es): Guests, Live online, in your browser, A confirmation with a calendar invite is on its way to
    your inbox.
  - Tesztek: 1367/0.
- **téma 3.11.93:** app-szerű mobilnavigáció (Áron: „App-szerű legyen”). A négy bemutatott változat (A letisztult
  fejléc, B app-szerű alsó menü, C lebegő sziget, D fejléc + foglalósáv) közül a B-t választotta.
  - Telefonon (759 px alatt), csak az online kiadásban: fent csak a logó, középre igazítva, nagyobban (58 px). A fejléc
    nem ragad, görgetéskor kimegy a képből. A kerek menügomb és a régi lebegő alsó sáv megszűnt.
  - Alul fix menüsor: Órák · Foglalás (középen, kiemelt magenta kör) · Fiók · Menü. A Menü nyitja és zárja a
    fiókmenüt (ilyenkor „Bezár”). Az aktuális oldal gombja magenta, felül kis jelzővel.
  - `site.js`: minden `[data-nav-toggle]` gomb nyitja és zárja a menüt.
  - A menüsoron megmaradt a `data-action-bar` attribútum, így a sütisáv és a foglalás-gomb régi szabályai is
    működnek. Az élő óra teljes képernyős nézete fölötte van.
  - Tesztek: 1368/0.
- **olivia-studio 1.12.2 + téma 3.11.94:** a fiók és a foglalási képernyők az oldal stílusában (Áron: „Ez így csúnya
  nagyon. Csináld meg, hogy normális legyen a felhasználói fiók felület. Illeszkedjen mindenhez.”).
  - Fiók, bejelentkezve: nincs nagy „Fiókom” fejléc. Helyette Evergreen üdvözlő kártya: „Szia, …”, mellette
    „Kilépés”, alatta áttetsző számkártyák (bérlet, közelgő), színes pöttyel.
  - A „Kilépés” a köszönés mellé került, már nem a fülek közt van. A fülek egy sorban vannak, telefonon oldalra
    görgethetők, és a nyitott fül magától a látható részre gördül (`site.js`).
  - A listák, bérletek, táblázatok, üres állapotok és a lemondási feltétel fehér vagy halványsárga, kerek kártyák lettek,
    puha árnyékkal. A dátumdoboz halványsárga.
  - Minden foglalási képernyőn (`.oys-card`, összegző, fizetési módok, vendégek, nyilatkozat, figyelmeztetések, összeg
    választó, regisztráció/belépés fülek) eltűnt a fekete keret és a kemény árnyék. A Facebook-sor mindenhol az új
    stílusban van.
  - Telefonon a fizetési módnál az ár a szöveg alá kerül. A „Hozol barátokat?” felirat a dobozon belül van.
  - Tesztek: 1369/0.
- **olivia-studio 1.12.3 (téma 3.11.95-tel):** az egyéni kéréseknél mindig látszik a szolgáltatás és az, hogy mit kért
  a vendég, a levelekben is (Áron: „Egyéni kérések esetén mindig legyen meg, hogy mi a szolgáltatás, meg mire kért, és
  az e-mailbe is menjen ki, ne csak az adminban legyen látható.”).
  - `OYS_Privates::summary()` / `summary_html()`: Szolgáltatás, Időtartam, Létszám, Helyszín, Megfelelő időpontok,
    Megjegyzés.
  - Az admin értesítő levele: tárgy „Új egyéni kérés: Egyéni online jóga, 60 perc – Név”. A törzsben a név,
    kattintható e-mail és telefon, és a teljes összefoglaló. Eddig hiányzott belőle a szolgáltatás, a helyszín pedig
    nyers kulcsként jelent meg.
  - A vendég visszaigazoló levele („Megkaptam a kérésed”): bekerült „A kérésed” és ugyanez az összefoglaló.
  - Admin, Egyéni kérések lista: elöl félkövérrel a szolgáltatás, utána a hossz, a létszám és a helyszín.
  - Fiók, Kéréseid: a „Megfelelő időpontok” is látszik, amíg nincs ajánlat.
  - Új fordítások (hu, es): Service, Group size, Your request, New private request: %1$s – %2$s.
  - Tesztek: 1373/0.
- **olivia-studio 1.12.4 (téma 3.11.96-tal):** az egyéni óra ajánlatlevele („Az egyéni órád”) kiírja: „Ha
  kedvezménykódod van, a fizetésnél érvényesítheted: a fizetési oldalon nyisd le a „Van kedvezménykódod?” részt.”
  Halványsárga dobozban, az ár alatt, csak fizetős ajánlatnál. Áron: „Ebbe az e-mailbe rakd bele, hogyha
  kedvezménykódod van, akkor a fizetésnél érvényesíthető.”
  - A kódmező ott van az egyéni óra fizetésénél is (`coupon_field`, ha az ár > 0 és a fizetés be van állítva).
  - Tesztek: 1374/0.
- **olivia-studio 1.12.5 (téma 3.11.97-tel) – Olívia 11.16-os doksijának két új pontja:**
  - **Várólista: nincs automatikus beléptetés.** „Várólistára ne automatikus beléptetés legyen, csak kapjon egy értesítést,
    hogy felszabadult hely és be tudjon jelentkezni egy gombbal. Aki onnan előbb bejelentkezik, azé a felszabadult hely.”
    - Ha felszabadul egy hely, mindenki, aki a várólistán van, egyszerre kap e-mailt egy „Foglalok most” gombbal.
    - Aki elsőként foglal, azé a hely. A foglalással lekerül a várólistáról, a többiek rajta maradnak.
    - Az értesítés addig megy ki, amíg az óra online foglalható. Egy ember negyedóránként legfeljebb egy levelet kap
      (pl. ha egy foglalás és a vendégei egymás után mondódnak le).
    - Új beállítás: `waitlist_auto` (alapból 0). Bekapcsolva a régi működés tér vissza: a bérletes vagy tagsággal
      rendelkező vendég magától bekerül. Ilyenkor számít a `waitlist_cutoff_hours` határidő is.
    - A foglalási oldal szövege a beállítást követi. Az E-mailek oldalon a „Bekerült a várólistáról” levél csak
      bekapcsolt automatikus beléptetésnél látszik. A hathaflow demó GYIK-szövege is ennek megfelelően változott.
  - **Bérlet-hosszabbítás, ha a stúdió mond le egy órát: a beállítás csak alapértelmezés.** Az egyéni óra vendégeinél
    vendégenként felülírható: „ez a rész legyen csak alapértelmezett és tudjam beállítani az egyéni óra vendégeinél.”
    - Vendégek → a vendég adatlapja → „Ha te mondod le az egyéni óráját … nap”.
    - Üresen az alapértelmezés (7 nap) érvényes, 0 esetén nincs hosszabbítás.
    - A szám a `oys_cancel_extend_days` user meta. A saját szám akkor is érvényes, ha az alapértelmezés 0.
    - A csoportos órák lemondásánál továbbra is az alapértelmezés számít.
  - Tesztek: 1400/0.
- **olivia-studio 1.13.0 (téma 3.11.98-cal) – Hub az adminban.** Áron, 11.16: „az adminon belül kéne egy hub, ahol látja a
  beszélgetéseket, akik beírnak neki chatbe, órai chatbe, meg arról kapjon értesítőt is az órai chatről … egybe tud kezelni
  mindent … telefonra legyen leoptimalizálva”; „A hubot meg készítsed adminra. !! Azzal haladjál”.
  - **Stúdió → Hub** a Stúdió első oldala (`OYS_Hub_Admin`, `assets/hub.js` + `hub.css`). A régi „Ma” oldal neve
    „Áttekintés” lett (`oys-today`), a menüjelvény a Hubra váró dolgokat számolja.
    - Telefonon egy panel látszik, alul fülsor: Ma · Üzenetek · Naptár · Több · Beállításaim.
    - Asztali gépen: sáv, lista, beszélgetés és a vendég kártyája egymás mellett.
    - A Hub nyitásakor a WP-menü ikonokra csukódik.
  - **Ma:** üdvözlés és „Rád vár” (új üzenetek, olvasatlan órai chatek, egyéni kérések).
    - A következő órák kártyán: foglaltság, ki jön (egészségügyi jelzéssel), „Belépés a terembe” / „Óra indítása”,
      Névsor, Chat.
    - Alatta számok és a legutóbbi befizetések.
  - **Üzenetek:** egy listában a beszélgetések, az órai chatek és az új egyéni kérések, szűrőkkel (Mind, Olvasatlan,
    Beszélgetések, Órai chatek, Kérések, Kész) és kereséssel.
    - Beszélgetésben: fotó, gyors válaszok, belső jegyzet (a vendég nem látja), „Kész”.
    - Mellette a vendég kártyája: telefon, e-mail, egészségügyi megjegyzés, bérletek, foglalt órák, egyéni kérések.
    - „+”: új beszélgetés bármelyik vendéggel.
  - **Honnan jönnek az üzenetek** (új táblák: `oys_threads`, `oys_thread_msgs`; DB 13):
    - a vendég fiókjából: új „Üzenetek” fül, fotóval, olvasatlan-jelvénnyel;
    - a weboldal kapcsolati űrlapjáról (`do_action('oys_contact_message')` a témában); ha később ugyanazzal az e-maillel
      regisztrál, a beszélgetés az övé lesz;
    - az egyéni kérésekből (`oys_private_requested`): az összefoglaló bekerül a vendég beszélgetésébe, „Ajánlat küldése”
      gombbal;
    - az órai chatekből (`live_chat`), olvasatlanság-számlálással (`oys_hub_chat_seen`).
  - **Értesítések:**
    - E-mail Olíviának (vagy az óra oktatójának), ha valaki ír neki, vagy az órán kívül ír az órai chatbe.
    - Beszélgetésenként vagy óránként legfeljebb 15 percenként egy levél; amíg a Hub nyitva van, egy sem; az óra alatt
      egy sem.
    - Új e-mail-sablonok: `studio_message`, `studio_class_chat`, `message_reply` (a válasz e-mailben megy a vendégnek),
      `chat_reply` (óra utáni Hub-válasz azoknak, akik írtak a chatbe, legfeljebb félóránként).
    - Amíg a Hub nyitva van (háttérfülön is), böngészőértesítést is ad; ezt a Beállításaimban lehet bekapcsolni.
  - **Fotó az órai chatben** („teremnek a csetjébe lehessen képeket csatolni”): kamera gomb az élő terem chatjében.
    - Hubból, fiókból, órai chatből is lehet fotót küldeni.
    - Átméretezve (leghosszabb oldal 1600 px, JPEG 80%), véletlen fájlnévvel, az `uploads/oys-chat/` alá kerül.
  - **Oktatók:** a „Tanítás” menü első pontja a Hub, csak a saját óráik chatjeivel és a saját beszélgetéseikkel.
    - Személyre szabható (Beállításaim): e-mail értesítés be/ki, név az órai chatekben, saját gyors válaszok.
  - Tesztek: 1433/0.
- **olivia-studio 1.14.0 (téma 3.11.99-cel) – Áron listája és Olívia 11.16-os doksija, este.**
  - **Árak mellett közvetlen gomb** („ahol van, mennyibe kerül egy-egy óra, ott legyen egy közvetlen foglalás gomb, vagy
    kérés küldése gomb”):
    - az óraoldalak árkártyáin (`oy_price_cards`) „Időpontot kérek” gomb, előre kitöltött szolgáltatással, hosszal és
      létszámmal (`topic`, `length`, `people`);
    - mellette „Bérletet veszek (5 alkalom)”, ami egyenesen a bérlet fizetéséhez visz.
  - **Naptár** („a heti élő órák … következő 3 … utána legyen egy naptárnézet … 6 héttel előre foglalható, és csak annyira
    látszódjon a naptár is”):
    - új `[oys_calendar]` shortcode: hétfőtől vasárnapig tartó sorok a foglalási ablak végéig;
    - asztali gépen az órák kis címkék a napokban, foglalás linkkel;
    - telefonon pöttyök a napokon, alatta lista Foglalás gombbal.
    - A „Bejelentkezés és árak” oldalon az órarend alatt (`#naptar`); a főoldal következő 3 órája alatt „További órák
      a naptárban” gomb vezet ide.
    - `booking_window_days` alapból 42 nap (6 hét). A DB 14-es frissítés az élő oldal 28 napját 42-re emeli.
  - **Váró új dizájn** („a váró … írja, hogy mennyi idő múlva nyílik … szarul néz ki”):
    - sötétzöld kártya, élő visszaszámlálás (nap, óra, perc, mp), nyitás időpontja, magenta „Belépek az órára” gomb,
      amely nyitáskor lüktet;
    - alatta az „Az óra előtt” teendők kártyákon, ikonokkal; a chat is lekerekített kártya lett;
    - a régi „724:50 h” formátum megszűnt, és az élő oldal fölött nincs „Fiókom” fejléc.
  - **Olívia 11.16, este:**
    - „ezek a képek is legyenek alapból fekete-fehérek, a főoldalon, és amikor ráviszem a kurzort, akkor színes”: a
      főoldali órakártyák fotói is fekete-fehérek, egérrel vagy koppintásra színesek.
    - „itt legyen halványsárga és magenta a hullám és ugyanolyan mint ezeknél”: a bemutatkozó fotó mögött is a kismama
      blokk lágy hulláma van, halványsárga + magenta színben.
    - „ez a szekció a bejelentkezés és árak alatt is legyen szebben kiemelve és legyen cta”: az árak oldal „Egyéni online
      órák” része ugyanaz a kiemelt doboz, „Időpontot kérek” gombbal.
    - „Bővebben az egyéni óráról … ezt szedjük ki”: a kiemelt dobozból kikerült.
    - „a gyakorlás és az élet és elme kategória színe most ugyanolyan”: a témák a cikkszám sorrendjében kapnak színt
      (`oy_cat_tone`), négy színből: magenta, barack, sárga, sötétzöld (`card--forest`).
  - Tesztek: 1441/0.
- **olivia-studio 1.14.1 (téma 3.11.100-zal) – a Stúdió admin telefonon** („Az admint optimalizáld le teljesen
  telefonra”):
  - Minden Stúdió- és Tanítás-oldalon (a Hubon kívül) telefonon alul a Hub sávja látszik: Ma · Üzenetek (jelvénnyel) ·
    Naptár · Névsorok · Több (`OYS_Hub_Admin::appbar`).
  - A táblázatok kártyákká alakulnak: soronként egy kártya, cellánként címkézett sor (`assets/admin-mobile.js`
    `data-label`, `admin.css`). Az oszlopok nem lógnak ki, nincs vízszintes görgetés (9 oldalon ellenőrizve, 390 px).
  - Nagyobb gombok, 16 px-es mezők (az iPhone nem nagyít rájuk), egymás alá rendezett dobozok és KPI-k. A WP-frissítés
    figyelmeztetése és a lábléc el van rejtve.
  - Tárhely a `/oys/v1/status`-ban (`storage`): szabad és teljes lemezhely, uploads, chatfotók, felvételek
    (`OYS_Ops::storage`).
  - Tesztek: 1441/0.
- **oliviajoga.hu 1.5.2 (téma: oliviayoga 1.5.2, élesben a saját tűjével) – Olívia .hu-s pontjai
  (11.15 és hu-11.12 doksi):**
  - „az oliviajoga.hu-n írjuk bele az összes új szolgáltatást, ne csak a csoportos órát”: az Árak alcíme és a
    „korszak” szekció is felsorolja: egyéni online jóga, online kismamatorna, stretching és mobilizáció, relaxáció és
    légzés.
  - „Árakat frissítsd … lenyílóba ugyanúgy az összes többi szolgáltatás árát is, ahogy az oliviajoga.onlineon”: az Árak
    oldalon az „Egyéni online órák” lenyílói az élő árakkal. Minden lenyílóban „Időpontot kérek” és „Bővebben az
    óráról” gomb.
  - „itt a fontos ne legyen feljebb, mint a többi szöveg”: a FONTOS! cím a szöveggel egy vonalban.
  - November 26-tól:
    - új menüpont az „Online órák”;
    - a „Bejelentkezés & jóga bérlet” menüpont → oliviajoga.online/bejelentkezes-es-arak/;
    - a „Kapcsolat” → oliviajoga.online/kapcsolat/;
    - minden bejelentkezés-CTA → az online főoldal; a GYIK két válasza már az új.
  - A .hu ellenőrzője 2288/0 (most), 1219/0 (november 26-tól).
- **olivia-studio 1.15.0 (téma 3.11.101-gyel) – a Stúdió a telefonon: saját link, app logóval, értesítések, útmutató**
  (Áron, 2026-11-16: „legyen egy egyedi link ehhez”, „adj egy olyan izét … mint a HelloProVisionnek a telefonjára, egy
  appot logóval mindennel együtt, hogy onnan elérje és kapjon értesítőt is”, „legyen mobilra optimalizált”):
  - **Saját link:** `oliviajoga.online/studio/` – ha kell, belépés, utána a Hub (oktatónak a saját Hubja).
    `/studio/?open=inbox|today|more|settings|t<id>|c<id>` az adott részt nyitja (`OYS_Studio_App`,
    `includes/class-studio-app.php`).
  - **App (PWA):**
    - manifest `/?oys_app=manifest`: „Olívia Jóga Stúdió” / „Olívia Stúdió”, Evergreen szín, ikonok;
    - ikonok: Olívia logója (krém OLÍVIA, magenta hullám) sötétzöld alapon, `assets/app/` – 192, 512, maskable,
      apple-touch; a generáló: `dev/studio-app-icons.js`;
    - service worker: `/?oys_app=sw`, csak értesítéshez, fetch-kezelő nélkül (a HelloProVision CRM mintája).
    - A Hub és minden Stúdió-oldal fejében ott a manifest, a főképernyő-ikon és a téma-szín, a belépőoldalon is, ha a
      /studio/-ra visz.
  - **Push-értesítés, eszközönként** (Hub → Beállításaim → „A Stúdió a telefonodon”):
    - Mire jön: új üzenet, egyéni kérés, weboldalas üzenet, órai chat (az óra alatt nem).
    - Akkor is jön, ha az app zárva van. Koppintásra az adott beszélgetés nyílik.
    - Ha a Hub éppen elöl van, nem jön külön.
    - Az üres push után a service worker a készülék push-címével kéri le a szöveget (`/?oys_app=pull`).
    - A VAPID kulcs első használatkor készül, az `oys_push_vapid` opcióban van (nem a repóban) – ne töröld.
    - Oktató csak a saját beszélgetéseiről kap értesítést. A lejárt eszközt (404/410) a rendszer elfelejti.
    - REST: `oys/v1/hub/push` (GET/POST/DELETE), `hub/push/test`.
  - **A Beállításaim kártyája:** a saját link másolás gombbal, telepítés („App telepítése” Androidon, lépések
    iPhone-ra), értesítés be/ki, próba-értesítés. iPhone-on az értesítés a főképernyőre tett appból kapcsolható be
    (iOS 16.4+).
  - **Útmutató mobilra, saját linkkel:** `oliviajoga.online/studio/utmutato/` (`includes/views/studio-guide-hu.php`,
    `assets/guide.css`; belépés kell, noindex).
    - Tartalma: az első lépései, az app és az értesítések, a Hub minden füle és funkciója, üzenetek, értesítések,
      órák, élő óra, egyéni órák, bérletek, vendégek, oktatók.
    - A Hub „Több” fülén az első csempe.
  - Tesztek: 1482/0.
- **téma 3.11.102 – a telefonos menü újratervezve** (Áron, 2026-11-16, fotóval a mobilmenüről: „Ezt itt szar”):
  - A hibák: az „ONLINE ÓRÁK” cím a logó alá csúszott (a menü 40 px-nél kezdődött, a fejléc 75 px magas), vegyes
    stílus volt (sötét pillek és képernyőszéles óriásbetűk), és a menü nem fért ki (866 px a 664-ből).
  - A menü most a fejléc alatt kezdődik. Az „Online órák” kis sárga felirat egy lekerekített lista fölött: az öt óra
    soronként, a paletta színes pöttyével és nyíllal; a kijelölt óra sárga.
  - A „Bejelentkezés és árak” egy széles sárga gomb. A Rólam, GYIK, Jógáról és Kapcsolat 2 × 2-es rácsban, a címbetűvel;
    a megnyitott oldal világos.
  - A menü saját gombjai és az e-mail-cím telefonon rejtve: az alsó sávban ott a Foglalás és a Fiók.
  - iPhone 13-on görgetés nélkül kifér; iPhone SE-n sűrűbb sorokkal, legfeljebb 20 px görgetéssel.
- **olivia-studio 1.16.0 (téma 3.11.103-mal) – Áron, 2026-11-16 este** („Nem működik az app”, „kell egy a hubnak a
  főoldalára egy összesítő …”, „a teljes adminról már legyen”):
  - **A Hub Ma fülén „Bérletek és alkalmak” összesítő** (`OYS_Hub::passes_summary`, csak a stúdiónak, oktatónak nem):
    - érvényes bérletek (darab, hátralévő alkalom, hány vendégnél);
    - zárolt alkalmak: bérletből lefoglalt, még előttünk álló órák (`paid_with = credit`), időben lemondva visszajárnak;
    - csoportos/online és egyéni alkalmak külön;
    - 14 napon belül lejáró bérletek (sárga, ha van);
    - előttünk álló foglalások, a 30 napon belül lejárt, fel nem használt alkalmak;
    - be nem váltott ajándékutalványok, aktív tagságok, fizetés alatt tartott helyek;
    - link: „Minden vendég bérletei” → Vendégek.
  - **App, iPhone-javítások:**
    - Az értesítés-engedélyt a koppintásra rögtön kéri: az iPhone csak így kérdez, hálózati kérés után már nem.
    - A Stúdió oldalain és a /studio/ belépőoldalán csak a logós ikon van; korábban a webhely kis ikonja nyert a
      kezdőképernyőn.
    - A push mindig látható értesítést ad: az iPhone a néma push után letiltja az eszközt.
    - Ha a készüléken be van kapcsolva a push, a nyitott Hub nem ad második értesítést.
    - `apple-mobile-web-app-status-bar-style: default`.
  - **A Naptár magyarul:**
    - Az `assets/calendar.js` szövegei eddig angolul voltak („+ New class”, „Show cancelled”). Most a nyelvi fájlból
      jönnek: `OYS_Calendar::strings`, `_()` / `f()`.
    - 24 órás idő és magyar dátum.
    - A párbeszédablak bezáró gombja „Mégse”, hogy ne keveredjen az óra lemondásával.
  - **Az útmutató a teljes adminról** (`/studio/utmutato/`):
    - kezdés, a Hub és a bérlet-összesítő;
    - minden Stúdió-menüpont: Áttekintés, Naptár, Névsorok, Egyéni óra kérések, Vendégek (adatlap, importálás),
      Tagságok, Fizetések, Ajándékutalványok, Árak és bérletek, Helyszínek, Hírlevél, Kuponok és jutalmak, E-mailek,
      Beállítások, Oktatók;
    - a weboldal tartalma: szerkesztés a weboldalon, Szövegek és képek, Óratípusok, GYIK, cikkek, oldalak, menük;
    - amihez ne nyúljon; a foglalás és lemondás szabályai, élő óra, egyéni órák.
  - **A Hub fülei** telefonon nem lógnak ki („Beállításaim”).
  - Tesztek: 1488/0.
- **olivia-studio 1.17.0 (téma 3.11.104-gyel) – Motibro legjobb funkciói a Hubban** (Áron, 2026-11-16 este:
  „Nézd meg a Motibróba a funkciókat, óra utáni … jelentés, hogy ki jött el, meg ki nem”, „a hubból tudja kezelni óra
  után nagyon egyszerűen”, „heti nézetes naptár is legyen”, „ott van a WordPress … fejléc, azt tüntesd el”).
  - **Kutatás:** a Motibro funkciólistája, árai és jóga-stúdiós oldala (get.motibro.com: teljes-funkciolista, arak,
    megoldasaink/joga-pilates-studio). A jelenléti íven egy érintéssel jelölhető a vendég, és látszik a bérlet és a
    tartozás. További funkciók:
    - „Foglalt, de nem jött el”, automatikus bérletlevonás;
    - havi jelenlét- és bevétel-kimutatás, toplisták, lemorzsolódók, születésnap-jelző, Excel-export;
    - Billingo / Számlázz.hu számlázás, várólista, minimális létszám, lejárat-emlékeztető;
    - oktatói elszámolás, QR-os jelenlét.
  - **Heti naptár a Hubban** (Naptár fül, `OYS_Hub_Classes::week`, REST `hub/week`):
    - hétfőtől vasárnapig, lapozással;
    - óránként a foglaltság és a jelenléti ív állása;
    - a „nagy” naptár egy gombnyira.
  - **Jelenléti ív** (`hub/class/{id}`, `/mark`, `/collect`; a Ma kártyán a „Névsor” is ezt nyitja):
    - „Itt volt” / „Nem jött el” egy koppintással, és „Mindenki itt volt”;
    - vendégenként a fizetés módja, a bérlet hátralévő alkalmai, az egészségügyi megjegyzés, és hogy bent volt-e az
      élő teremben;
    - a helyszíni fizetésnél egy „Fizetve” gomb.
    - A Ma fülön az „Óra után: ki jött el?” rész az elmúlt két nap jelöletlen óráit mutatja.
  - **Automatikus jelenlét:**
    - Az élő terem megjegyzi, ki lépett be: `oys_live_joined` hook, `oys_live_joined_<session>` opció, 30 nap után
      törlődik.
    - `auto_attendance_hours` (alap: 3, a Beállítások → Foglalási szabályok alatt) órával az óra után, óránkénti
      cronnal tölt: aki bent volt, az „itt volt”, aki nem, az „nem jött el”.
    - Élő terem nélküli óránál mindenki „itt volt”. A kézi jelölés megmarad.
  - **Kimutatások** (Hub → Több / Ma, `hub/reports`, csak a stúdiónak):
    - a hónap számai: órák, foglalások, eljött, foglalt de nem jött el, megjelenés %, különböző vendég, időben
      lemondta, bevétel, bérletek, új vendégek;
    - óránkénti bontás, mai születésnapok, törzsvendégek (90 nap), lemorzsolódók üzenet gombbal;
    - a hónap foglalásai CSV-ben, Excelhez (UTF-8 BOM, pontosvessző; `admin_post_oys_hub_export`).
  - **Számlázás:** a kártyás befizetéseket a CRM könyveli a QuickBooksba (`OYS_HPV_Pay`). A jelenléti íven a fizetés
    módja látszik, és a helyszíni fizetés jelölhető. Billingo / Számlázz.hu nincs bekötve.
  - **App WordPress-fejléc nélkül:**
    - Telefonon és telepített appban (`display-mode: standalone`) a Hubon nincs admin sáv és WP-menü.
    - A többi Stúdió-oldalon az appban nincs admin sáv: az alsó sáv vezet.
    - A Hub „Több” fülén csempe lett a weboldal szerkesztéséhez, a cikkekhez, a GYIK-hez, az óraoldalakhoz és a
      képekhez.
  - Az útmutató új részei: Heti naptár, Óra után: jelenléti ív, Kimutatások. Tesztek: 1505/0.
- **olivia-studio 1.18.0 (téma 3.11.105-tel) – a Motibro automatizmusai** (Áron, 2026-11-16: „Telefonos értesítés,
  ez jó, ez már megvan … És akkor mehet a kutatás, dolgozz tovább.”).
  - **Kutatás:** help.motibro.com, az Automatizációk (16 db) és a SMART Jelenléti Ív cikkei. Ami még hiányzott:
    lezáratlan jelenléti ív értesítő az oktatónak, napi összefoglaló a vezetőnek, értesítés foglalásról, lemondásról és
    várólistáról, emlékeztető a lemorzsolódó vendégeknek, hétvégi „heti kedvcsináló”, egyéni látogatási adatok a jelenléti
    íven, korai lemorzsolódás jelző, új vendég gyors felvétele óra előtt vagy után.
  - **Push a telefonra** (`OYS_Hub_Auto`, `includes/class-hub-auto.php`):
    - új foglalás, lemondás, várólista (`oys_booking_confirmed`, `oys_booking_cancelled`, új `oys_waitlist_joined`
      hook); amit a stúdió maga csinál, arról nem jön, vendégsorról sem;
    - óra után 10–70 perccel „Ki jött el?”, ha van jelöletlen vendég; egyszer, `oys_sheet_pushed_<id>` opció (14 nap
      után törlődik); koppintásra a jelenléti ív nyílik (`/studio/?open=s<id>`, a `hub_target` már engedi az
      `s\d+`, `calendar`, `reports` célokat is);
    - reggel 7:30 és 10:00 között egyszer (`oys_daily_push`): a mai órák a foglalások számával, ami vár (üzenet, órai
      chat, kérés, kitöltendő jelenléti ív), tegnap hányan jöttek el; ha nincs mit mondani, nem jön;
    - mindhárom kikapcsolható: Hub → Beállításaim → „Értesítések a telefonon” (`push_bookings`, `push_sheet`,
      `push_daily`, alap: be; a mentés, ami nem küldi őket, megtartja).
  - **Két új vendég-e-mail** az Emlékeztetők között, **alapból kikapcsolva** (új `off` jelző a sablonban: amíg a stúdió
    nem kapcsolja be, nem megy):
    - `we_miss_you`: a lemorzsolódóknak (`OYS_Hub_Classes::drifting()`), a következő órákkal, vendégenként legfeljebb
      60 naponta (`oys_missed_sent`), naponta egyszer 10 és 19 óra között;
    - `weekly_digest`: szombaton a hírlevél-feliratkozóknak a jövő hét órái (`OYS_Emails::week_html()`, kevés hely
      jelzéssel), leiratkozó linkkel és List-Unsubscribe fejléccel, hetente egyszer (`oys_weekly_digest`).
    - A próbaküldés és az előnézet a valódi óralistát mutatja.
  - **Jelenléti ív:**
    - mindenkinél „N. óra” (eddigi eljövetelek + 1), „Első óra” címke, és „Rég nem járt”, ha 45 napnál több telt el az
      előző óta („3 hónap után újra itt”);
    - „Valaki hozzáadása”: vendégkereső, `hub/class/{id}/add`; bérlettel, ha van, különben helyszíni fizetés (ár
      esedékes, „Fizetve” gomb), ingyenes óránál ajándék; óra után egyből „itt volt”, előtte megy a foglalási e-mail;
      oktató csak a saját vendégeit adhatja hozzá; az óra vége után 2 napig lehet.
  - Útmutató: Értesítések (a három push, a két új levél), Óra után: jelenléti ív (látogatási adatok, hozzáadás),
    E-mailek. Fordítások hu + es. Tesztek: 1533/0.
- **olivia-studio 1.18.1 (téma 3.11.106-tal) – az appban sehol nincs WordPress-fejléc, és magától frissül** (Áron,
  2026-11-16, iPhone-képernyőképpel: „Fent meg bent van a wp admin header”).
  - Ok: a kép 1.17.0 élesítése előtt készült. Az iPhone a kezdőképernyős app oldalát a memóriában tartja, és újranyitáskor
    nem tölti újra, így a régi változat maradt. Ellenőrizve WordPress 7.1.4-en (az élőn ez fut) iPhone-nézetben: a Hubon
    nincs sáv.
  - Új: az app minden Stúdió-oldalon kap egy `oys-app` osztályt a `<html>`-en (`navigator.standalone` iPhone-on,
    különben `display-mode: standalone`), így a WP-sáv akkor is eltűnik, ha az iOS nem jelzi a standalone módot a
    CSS-nek.
  - Új: a Hub `poll` válasza és a boot adat hordozza a verziót (`v`). Ha élesben új változat van, a nyitott app
    újratölti magát: ha senki nem gépel és nincs nyitott lap, azonnal, különben amikor legközelebb előtérbe kerül.
  - Tesztek: 1534/0.
- **olivia-studio 1.19.0 (téma 3.11.107-tel) – a Motibro bérletei és óralátogatásai a vendégekhez** (Áron,
  2026-11-16, két exporttal: „Érvényes bérletek 2026-11-16”, „Óralátogatások 2024-02-10 – 2026-11-16”: „Ezt meg a
  vendégekhez dobd hozzá.”).
  - Az import (Stúdió → Vendégek → Importálás, illetve a `customers/import` REST-útvonal) az oszlopairól ismeri fel a
    három Motibro-exportot: Vendégek, Érvényes bérletek, Óralátogatások (`OYS_Import::detect` / `import_any`).
  - **Óralátogatások:**
    - minden óra (időpont + név + helyszín) múltbeli óra lesz itt: a Motibro óraneve, a helyszín és a terem;
      Online → online; Slow flow 90 perc, a többi 60 perc; dátumos különlegesség (HANGjóga, workshop, hangfürdő,
      Nőnapi…) esemény;
    - minden látogatás egy „eljött” foglalás `paid_with = import` („Korábbi rendszer”), a jegyzetben a Motibro bérlet és
      az órára jutó bevétel;
    - közvetlenül a táblákba megy: nincs e-mail, push, helyfoglalás vagy bérletlevonás;
    - a vendéget a Motibro-azonosító (`oys_ext_id`), különben az e-mail-cím alapján találja meg; ha nincs még itt,
      létrehozza (a „Vezetéknév Keresztnév” sorrendet szétbontja), és a regisztrációja az első ottani órájának napja
      lesz.
  - **Érvényes bérletek:**
    - ami hátravan, online alkalom lesz (`kind = online`, `source = motibro`) ugyanaddig a napig;
    - az összes alkalom = felhasznált + zárolt + hátralévő;
    - a Motibróban zárolt alkalmak (ott foglalt órák) ott maradnak;
    - nincs „lejár a bérleted” levél (`expiry_notice_sent = 1`);
    - egy frissebb export ugyanazokat a bérleteket frissíti (kulcs: `product_id = motibro-<hash>`), nem duplázza.
  - A futás eredménye: 239 óra, 1556 látogatás, 16 bérlet 34 hátralévő alkalommal. Ugyanaz a fájl másodszor nem
    változtat semmit.
  - Vendégadat a repóba nem került (a teszt kitalált adatokkal fut). Tesztek: 1556/0.
- **olivia-studio 1.20.0 (téma 3.11.108-cal) – a Motibro bérletek nem foglalhatók online, beváltható lista az adminban**
  (Olívia, 2026-11-16, portál-chat: „november végén küldöm az új exportot … ha abban az exportban még marad alkalmuk,
  hosszabbítsd meg a bérletüket január 27-ig és a maradék alkalmukat tudják felhasználni online addig. Van több olyan
  vendég, akinek van még alkalom a bérletén, ezeket ne hozd át, ne foglalhassanak online, de valahova gyűjtsd össze nekem
  az adminon belül, hogy ha esetleg írnak nekem, akkor tudjuk beváltani őket akár egyénire az értéknek megfelelően!”).
  - **Tárolás:** `OYS_Held` (`includes/class-held.php`), `oys_held_credits` opció.
    - Bejegyzésenként: vendég, bérlet, felhasznált, zárolt és hátralévő alkalom, lejárat ott, az export napja.
    - Egy alkalom értéke: a vendég utolsó ilyen bérletes látogatásának ára az importált jegyzetből (`· 2800 Ft`),
      különben 2 800 Ft.
    - Állapot: beváltható / online foglalható / beváltva; jegyzet.
  - **A passes import alapból a listába gyűjt** (nem foglalható). „Online foglalható eddig” dátummal (admin
    feltöltés, REST `online_until`) a hátralévő alkalom online alkalom lesz addig, vagy a bérlet saját későbbi napjáig;
    nincs lejárati levél. Ez a november végi exporthoz kell, 2027-01-27-tel. Ami egy frissebb exportban már nincs
    benne, azt megjelöli („nézd meg a Motibróban”).
  - **A már átvitt 16 foglalható bérlet** a `customers/held/from-passes` útvonallal került át a listába, utána az
    eredeti exporttal frissítve (felhasznált, zárolt).
  - **Az adminban:**
    - Stúdió → Vendégek tetején: „Motibro bérletek: beváltható maradék alkalmak” (összesen alkalom és érték),
      soronként „Beváltva” (mire) és „Online foglalhatóvá” (dátum);
    - a vendég adatlapján a Bérletek alatt is;
    - a Hubban a beszélgetés melletti vendégkártyán („Motibróból (online nem foglalható)”), és egy csempe a Ma fülön.
  - Tesztek: 1568/0.
- **téma 3.11.109 – a jogi oldalak, a készítő és a közösségi linkek új ablakban** (Olívia, 2026-11-16, portál-chat: „az
  oliviajoga.onlineon az aronritok.com, az impresszum, az ászf és az adatkezelési meg a social platformok külön ablakban
  nyíljanak meg”).
  - A láblécben (ÁSZF, Impresszum, Adatvédelem, „Készítette: Ritók-Filip Áron”) és a sütisávban (Adatkezelési
    tájékoztató) `target="_blank" rel="noopener"`. A közösségi ikonok és a Facebook-csoport eddig is így nyíltak.
  - Minden más helyen (oldalszövegek, cikkek, GYIK) egy kis script (`oy_new_window_script`, `wp_footer`) teszi ugyanezt:
    - a jogi oldalakra és az adatvédelmi oldalra mutató linkekre;
    - az aronritok.com, facebook, instagram, youtube, tiktok, linkedin, pinterest és threads linkekre.
  - Az oldalon belüli ugrások (#…, pl. „Ugrás a tartalomra”, „Sütibeállítások”) maradnak. A fizetés alatti ÁSZF-link
    (plugin) eddig is új ablakban nyílt.
- **Órarend-sztori és Facebook-képek az oliviajoga.online mostani dizájnjával** (Olívia, 2026-11-16, portál-chat:
  „az órarend sztorit és a facebook képeket szerkeszd újra … a jelenlegi háttér maradjon. A képek teljes egészében
  legyenek, nem kell rájuk szöveg, de kis design jöhet”).
  - Generátor: `oliviajoga-hu/tools/facebook/online_social.py` (story / photos), a kép a `cover.js`-sel készül.
  - **A sztori (1080×1920):** a háttér a régi (`orarend_bg.py` → `orarend-hatter.png`). Rajta:
    - a weboldal logója kártyán;
    - „ONLINE JÓGA” sárga és „ÓRAREND” magenta blokkon (Anton);
    - két kártya: szerda 18:30–20:00, online flow jóga, 90 perc, első óra december 16.; egyéni órák egyeztetett
      időpontban (privát jóga, kismamatorna, mobilizáció, relaxáció és légzés);
    - sötétzöld gomb: „Bejelentkezés: oliviajoga.online”;
    - a weboldal foltjai és négy pöttye.
  - **17 Facebook-kép:** a fotó egészben, lekerekítve, krém kerettel, öt színkészlet váltakozva (sárga, krém, zsálya,
    mandarin, sötétzöld háttér), a weboldal két foltja és a négy pötty; nincs szöveg és logó.
  - Elküldve a portálba: 9148779 (sztori), 9148780 (zip). A fotók nem kerülnek a repóba.
  - **Második kör** (Olívia, 9148781: „a facebook képek nem az igaziak, legyél kreatívabb, egyben legyenek letisztultak.
    az órarendben a logó legyen átlátszó és ne feltűnő, az első óra nem kell bele!”):
    - A Facebook-képek a weboldal nyitóképét idézik: egy háttérszín (sárga, krém vagy sötétzöld váltakozva), mögötte a
      hero saját foltja (hátsó + elülső hullám, felváltva jobbról és balról), a fotó egészben, lekerekítve, árnyékkal.
      Nincs pötty, keret, szöveg.
    - Színes és fekete-fehér változat is készült (a főoldali képek is fekete-fehérek).
    - Sztori: a logó kártya nélkül, 150 px, 78% átlátszóság; „Első óra” kikerült; a térközök kiegyenlítve.
    - Elküldve: 9148782 (sztori), 9148783 (előnézet), 9148784 (zip).
- **olivia-studio 1.21.0 + téma 3.11.110 – lapozható naptár, nem ugráló árak, nem széteső órakártya** (Áron, 2026-11-16
  17:29, képekkel és videóval: „A naptár csúnya legyen lapozható, az áraknál … így ugrál össze-vissza, szar helyre ugrik,
  az legyen jó, meg ott van egy másik, ami szarul néz ki, szétesik a frontend.”).
  - **Naptár hónaponként** (`[oys_calendar]`): fejléc a hónap nevével (Anton, „2026. DECEMBER”), két kerek nyíl lapoz
    (az első előtt és az utolsó után letiltva).
    - Egész hetek hétfőtől vasárnapig; a más hónaphoz tartozó napok halványak.
    - A következő óra hónapján nyílik (élesen: december).
    - Alatta, telefonon, a hónap órái.
    - Egy órás napra koppintva kiemeli az órát a listában, és csak akkor görget (finoman), ha nem látszik.
    - JS nélkül minden hónap egymás alatt látszik.
  - **Az órakártya telefonon:** rácsban a napdoboz és a név egymás mellett, a Foglalás gomb alatta teljes szélességben.
    Eddig egy sorban voltak, a gomb összenyomta a nevet és rácsúszott az időre.
  - **Az árak lenyílói:** az oldal sima görgetésű (`scroll-behavior: smooth`), ezért a korrekció átcsúszott az árakon.
    - Most a korrekció azonnali, a megérintett cím helyben marad.
    - Ha a cím a képernyő alsó részén van, egyetlen nyugodt mozdulattal felkerül a tetejére. Csak a képernyőn lévő fejlécet
      veszi figyelembe; telefonon a fejléc elgördül, ez okozta a túlgörgetést.
  - Tesztek: 1572/0.
- **olivia-studio 1.22.0 + téma 3.11.111 – a lefoglalt óra egyszer, érthetően mondja, és van hova tovább lépni** (Áron,
  2026-11-16, képpel: „Foglalva vagy nem jo.”). A naptár listájában kétszer állt (egy kis sor és egy szürke kapszula),
  a „Foglalva vagy” pedig magyarul is döcögött.
  - **Szöveg:** „You're booked” → „Lefoglaltad” (a foglalás utáni képernyőn „Lefoglaltad!”), mindenhol, e-mailben is.
  - **A naptár listájában** a lefoglalt órának:
    - magenta a napdoboza;
    - a név és az idő alatt egyetlen sor áll: magenta körös pipa + „Lefoglaltad”;
    - alatta körvonalas gomb: „Foglalásaim” (a fiókba, ahol látja és lemondhatja). Online órán, ha 12 órán belül kezdődik,
      a gomb „Belépek az órára”, és a saját belépőlinkjére visz.
  - **A rácsban:** a napja magenta számmal, a pöttye gyűrűs; asztali gépen a csipben pipa, és ugyanoda visz, mint a gomb.
  - **Az órakártyán** (következő órák, órarend): a pipás „Lefoglaltad” mellett ugyanez a gomb.
  - A lezárt óránál is egyszer áll a „Foglalás lezárva” (csak a kapszula, a kis sor nem).
  - Tesztek: 1578/0.
- **téma 3.11.112 – Olívia 11.17-i chatje** (három üzenet a portálon).
  - **„a lemondásnál az egyéni órát írjuk új sorba”:** a „Lemondás:” sor az órarendek alatt és az árak oldal „Lemondás”
    bekezdése: az egyéni óráról szóló mondat új sorban.
  - **A csoportos flow óra lépései** („toljunk egy lépéssel mindent”):
    1. Ráhangolódunk az órára: pár percet beszél a jógafilozófiából hozott témáról, ez köszön vissza az ászanákban.
    2. Megérkezünk: néhány perc légzés. A többi változatlan, csak a relaxáció elől kikerült a „rövid”.
  - **„minden pózhoz” helyett „minden ászanához”.**
  - **Olívia saját szövegei:** a flow-bekezdést élesben a szerkesztővel átírta (a saját soraival, „talajgyakorlatok
    segítségével”). A szerkesztő az eredeti szöveg kulcsán tárolja, ezért a frissítés (`oy_migrate_1117`) az ő változatát
    is átviszi az új kulcsra, és ugyanazt a változtatást írja bele. Az ő sorain a ráhangolódás külön sor, külön lépés.
  - Az oldal saját szövege is soronként tagolt, így a hullámon öt lépés marad akkor is, ha nincs saját változat.
  - Tesztek: 1592/0. (Új konténer: a MariaDB-t újra kellett indítani; a tesztfuttató adatbázis nélkül is 0-val lépett ki,
    ezért a kiadás előtt az „N passed, 0 failed” sort is ellenőrzöm.)

- **téma 3.11.113 – árak a csoportos óra aloldalán, „-tól” nélkül** (Olívia, 11.17, chat: „A csoportos óra aloldalára is
  tegyük be az árakat, ugyanúgy ahogy a többi óránál, illetve ez a -tól nem kell egyiknél se”).
  - **A csoportos flow óra oldalán** a foglalás gomb előtt „Árak” rész, ugyanolyan kártyával, mint az egyéni óráknál:
    - fejléc: Csoportban, 90 perc, 3 000 Ft;
    - sorok: 1 alkalom 3 000 Ft; 5 alkalom 13 000 Ft (alkalmanként 2 600 Ft);
    - gombok: „Óra foglalása” és „Bérletet veszek (5 alkalom)”.
  - Az adatok a csoportos bérletekből jönnek (az egyalkalmas jegy és a legnagyobb bérlet), az ár tehát egy helyen él.
  - **Az élő oldalra** a frissítés (`oy_migrate_1117`, rev b) egyszer teszi be, csak ha még nincs „Árak” az oldalon.
  - **Minden árkártya fejlécében** maga az ár áll, „-tól” nélkül.
  - Tesztek: 1597/0.

- **téma 3.11.114 – a lenyílók nem ugranak el; nagyobb gombok; részletesebb „Hogyan működik?”** (Áron, 11.17: „főoldali
  gyiknél, hogyha lenyitom, leugrik az olvass a jógáról részhez … csinálj egy 1000-1200 tesztet minden eszközről”;
  Olívia, chat, képernyőfelvétellel: „Ellenőrizd az összes ilyet, nem jó hogy így felugrik”).
  - **Az ok:** egy kérdés lenyitásakor a fölötte nyitott válasz becsukódik, a lap rövidül, a görgetés helye viszont marad.
    A megérintett kérdés így kicsúszott felül, és a következő rész (élesben „Olvass a jógáról”) látszott. iPhone-on
    (WebKit) és Androidon (Chromium) egyaránt előjött.
  - **Javítás (`site.js`):** minden lenyíló címnél (`details > summary`) a megérintett cím pontosan ott marad a képernyőn,
    ahol volt. A korrekció azonnali és láthatatlan, csak az érintés utáni két képkockában; utána az olvasó saját
    görgetéséhez nem nyúl.
  - **Gombok érintőképernyőn legalább 44 px magasak** (az árkártyák kis gombjai 37 px-esek voltak).
  - **Olívia, chat:** „A mobilizáció és a relaxáció aloldalon a hogyan működik résznél legyen kicsit részletesebb … pl a
    fizetés nincs benne”.
    - Mindkét oldalon ugyanaz a három lépés, mint az egyéni óránál: mit írj meg; visszaírok egy javaslattal, egy
      kattintással elfogadhatod, kifizetheted vagy a bérletedből foglalhatod; élő óra, mi kell hozzá.
    - A „javaslattal” az ő szava a szerkesztőből. A régi közös szövegre tett szerkesztése törlődött, mert már semmi sem
      mutatja.
  - **Eszközös tesztsor** (scratchpad `qa/suite.js` + `qa/report.js`, Playwright):
    - 11 eszköz (WebKit: iPhone SE/13/15 Pro Max, iPad Mini/Pro 11, Safari 1440; Chromium: Pixel 7, Galaxy S9+,
      Galaxy Tab S4, Chrome 1366; Firefox 1920) × 19 oldal.
    - Oldalanként: betöltés, szkripthiba, hibás fájl, oldalirányú görgetés, képek és alt, 40 px-es érintési célok,
      minden lenyíló (a képernyő 60%-ánál megérintve a helyén marad-e és vált-e), menü, naptár, sütisáv, linkek.
    - Minden talált link egyszer, HTTP-válaszra.
  - Tesztek: 1604/0.

- **téma 3.11.115 – Olívia 11.17-i képernyőképei** (chat).
  - **„jobb felső sarokban nem kell a 3000 Ft … és az összes többinél sem kell az 1 alkalmas összeg hisz ott van
    mégegyszer”:** az árkártyák fejlécében nincs ár, csak a sorokban (csoportos, egyéni, páros kártyák).
  - **„ide nem kell @”:** a kapcsolat oldalon az Instagram neve „oliivia_yoga”, „@” nélkül.
  - **Kiadás:** a 3.11.114 élő verziót rögzítő feltöltését elutasította a GitHub, mert közben más is feltöltött az ágra.
    A szkript mégis „shipped”-et írt. Most a feltöltés után ellenőrzi, hogy a rögzítés tényleg fent van-e; ha nincs,
    hibával áll le, és a `pin.sh OLD NEW` csak a rögzítést ismétli meg a friss ágon.
  - Tesztek: 1605/0.

- **téma 3.11.116 – az eszközös tesztsor első teljes futása élesben (3.11.115)** (Áron, 11.17: „utolsó kör, utána zárjuk
  a fejlesztést”).
  - **Eredmény:** 2693 ellenőrzés, 2603 sikeres; telefonokon (iPhone SE/13/15 Pro Max, Pixel 7, Galaxy S9+) egy hiba
    sem, a 91 link mind válaszol.
  - **Egy valódi hiba volt:** iPaden és tableten a „Részvételi nyilatkozat” lenyíló címe 26 px magas volt. Most
    érintőképernyőn mindenhol legalább 44 px.
  - **A többi a tesztsor tévedése volt:**
    - tableten és gépen a kártyák címe nem kapcsoló (a szöveg nyitva van, nincs plusz jel);
    - a páros árkártyák ott mindig nyitva vannak;
    - a Firefox által megszakított képletöltés nem hibás fájl.
  - Tesztek: 1605/0.
  - **Második teljes futás élesben a 3.11.116-on: 2615/2615, 0 hiba.** Eszközönként:
    - iPhone SE/13/15 Pro Max, Pixel 7, Galaxy S9+: 229-229;
    - iPad Mini és Pro 11: 254-254;
    - Galaxy Tab S4: 217;
    - Chrome, Safari, Firefox gépen: 218-218;
    - a 91 link mind válaszol.
  - **Ezzel ez a fejlesztési kör lezárva** (Áron, 11.17: „utolsó kör, utána zárjuk a fejlesztést”).

- **oliviajoga.hu ÉLES (2026-11-17)** (Áron: „rakd ki a szerverre! és javítsd mindent amit kell, cseréltem a dns-eket”).
  - **DNS az ELIN-nél (Áron):** csak az `@` és a `www` A-rekord változott, a 37.148.205.64-re (VPS). Az MX, az
    `mx1`/`mx2`/`mail`, az autoconfig/autodiscover, a TXT és a CAA maradt; Olívia levelezése az ELIN-en fut tovább.
  - **A szerveren** az éles oldal (`oliviajoga-hu`) már fent volt (a fő chat előkészítette az `oliviajoga-hu-src` dev
    oldalból). Innen a távirányítóval (`services/deploy/hpv-vps.py ssl-site oliviajoga-hu`) Let's Encrypt-tanúsítvány
    az oliviajoga.hu-ra és a www-re, 2027-02-15-ig, automatikus megújítással.
    - http → https, www → oliviajoga.hu.
    - Indexelhető, a canonical és az og:url a https://oliviajoga.hu.
    - Az oldaltérképben 31 oldal, mind 200.
    - Nincs dev- és Google Sites-link; a PHP-napló üres.
  - **Eszközös teszt (`dev/qa`) a 31 oldalon, három valódi hibával:**
    1. **A főoldal iPhone-on (WebKit) néhány másodperc után összeomlott.** Az ok a „korszak” fotórácsban a képek saját
       fekete-fehér szűrőjének áttűnése (`transition: filter`) a kigyúláskor.
       - Kizárással megtalálva: szűrő vagy áttűnés nélkül nem omlik össze, a lebegő animáció nem számít.
       - Most a fotó színes, fölötte egy szürke, „saturation” keverésű réteg; kigyúláskor csak ennek az átlátszósága
         változik. Ugyanaz a látvány, kétszer egymás után sem omlik össze.
    2. **„Rólam”: telefonon oldalra görgethető volt** (a fotó mögötti ecsetvonás kilógott). Most `body { overflow-x: clip }`.
    3. **„Bejelentkezés online” gomb:** 37 px magas volt. Érintőképernyőn minden gomb legalább 44 px.
  - Téma 1.5.3; a `tools/check.py` 2288/0.
  - **Teljes futás az 1.5.3-on: 4033 ellenőrzés, 4025 sikeres.** A főoldal iPhone-on már nem omlik össze.
    - A „Rólam” oldalon a `body` levágása mellett a dokumentum még szélesebb volt (az ecsetvonás 118%). iPhone-on ujjal
      ez még elhúzható.
    - **1.5.4:** `html, body { overflow-x: clip }`, és 900 px alatt a bal oldali ecsetvonás a képernyő bal szélétől a
      fotó jobb széléig ér. Öt telefonon (iPhone SE/13/15 Pro Max, Pixel 7, Galaxy S9+) pontosan képernyőszélességű.
    - **Külső hibák (nem a mieink):**
      - a kapcsolat oldalon a Google Maps-kártya hálózati hibája (Safari, a proxy miatt);
      - Firefoxban a Motibro-widget süti-figyelmeztetése (nov. 26-tól a Motibro kikerül);
      - a terhelt tesztfutás alatt egyszer 503 a főoldalon (utána háromszor 200).
  - **Záró futás az 1.5.4-en: 4033 ellenőrzés, 4032 sikeres.**
    - Mind a 8 telefon/tablet 383/383, a gépek 290/290 (a Firefox 289/290), a 99 link mind válaszol.
    - Az egyetlen hiba a Motibro-widget saját süti-figyelmeztetése Firefoxban.
    - A főoldal iPhone-on végig görgetve sem omlik össze.
  - **Nyitva (Áron/Olívia):**
    - Search Console-ban az oliviajoga.hu oldaltérképe: `https://oliviajoga.hu/wp-sitemap.xml` (a domain a DNS-ben már
      hitelesítve).
    - A régi Google Site (`sites.google.com/view/oliviajoga`) még nyilvános; leállítható, vagy átirányítható az új
      oldalra.
    - Az ELIN-en csak a levelezés maradt: a webtárhely lemondható, a levelezés nem.

- **Kutatás: „egyéni jóga” vagy „privát jóga”?** (Áron, 2026-11-17: „Csinálj kutatást, hogy privát jógára, vagy
  egyéni jógára van nagyobb kereslet, SEO-szempontból, és küldd el Olíviának”). Az eredményt elküldtem Olíviának a portálon.
  - **Forrás:** SEO OS 16-os projekt („Online jóga Magyarország – piackutatás”), DataForSEO → Google Ads keresési mennyiség,
    Magyarország / magyar. 38 kifejezés, ebből 25 új (kézzel felvéve); a lekérdezés a 46855-ös feladat. Plusz a Google
    keresési javaslatai (hl=hu, gl=hu).
  - **Havi keresések:**
    - „egyéni jógaóra” 10, „egyéni jógaóra árak” 10 (CPC 0,30 / 0,06 USD);
    - „jóga magánóra” 10, „magán jógaóra” 10;
    - minden „privát” változat (privát jóga, -óra, -óra, online, budapest, ár, oktatás, oktató, kismamatorna) a
      küszöb alatt (nincs adat);
    - összevetésnek: „online jóga” 110, „jógaóra” 70, „jóga budapest” 720.
  - **A Google javaslatai:**
    - „egyéni jóga” → 6 (árak, jógaóra, oktatás, város);
    - „privát jóga” → 4 (óra, budapest).
  - **Javaslat:**
    - A fő név maradjon az „egyéni (online) jóga” a címben, a menüben és az URL-ben (az .online-on már így van).
    - A szövegben egyszer-kétszer a „privát” és a „magánóra” is szerepeljen.
    - Az árak maradjanak kint.

- **HelloProVision-csomag + HotPot + távirányító – oliviajoga.hu 1.5.5, oliviajoga.online 3.11.117** (Áron, 2026-11-17:
  „Csináljunk az oliviajoga.hu-ra egy távirányítót …”; „rakjuk be … az összes HelloProVision-os marketing eszközt, hogy
  elérjük ezeket az oldalakat, meg a hotpotot is kössük be. … Történjen mindig ugyanaz.”).
  - **Élesítési csomag (`wordpress/hpv/hpv-kit.php`, mindkét témában `inc/hpv-kit.php`).**
    - Az éles oldalon, a téma minden beállító körében (minden kiadásnál) magától felteszi ezeket:
      - a beállító varázslót (`helloprovision-setup`, GitHub-kiadás, „weboldal” mód);
      - az Ads Connectet és a HotPot Connectet;
      - a Leads és a SEO mu-plugint.
    - Ami fent van, ahhoz nem nyúl. Hibánál óránként újrapróbál (legfeljebb 24-szer).
    - Az admin nem ugrik át a varázslóba.
    - Kulcsot nem tartalmaz. A HotPot-kapcsolati kód a varázsló távirányítóján megy be (`hpv-setup/v1/remote/keys`).
    - Dev és staging oldalon nem fut.
    - Próba: `WP_DIR=… HPV_SETUP_SRC=<hlprv> php wordpress/dev/tests/kit.php` → 28/28 (1.5.6 / 3.11.118).
    - **1.5.6 / 3.11.118.** Az 1.5.5 / 3.11.117 élesen feltette a varázslót, az Ads Connectet és a mu-pluginokat. A HotPot
      Connect „nincs kiadás” hibát kapott: a varázsló csak a legújabb 1000 kiadást látja, a hlprv-ben pedig 1794 van.
      - A csomag azóta minden bővítményt közvetlenül a GitHub-címkéjéről tesz fel.
      - Ha egy kör félbeszakad, az újrapróba akkor is megmarad.
      - Naponta egyszer megnézi, hiányzik-e valami, és ha igen, visszateszi.
    - **olivia-studio 1.22.1 / 3.11.119:** az .online `X-OYS-Auth` fejléce is elfogadja a sima `felhasználó:jelszó` alakot,
      nem csak a base64-et. Így mindkét oldalra ugyanaz az egyszerű érték mehet. Teszt: 1609/0.
      - A VPS-en az `Authorization` fejléc nem jut el a WordPressig, ezért kell az `X-OYS-Auth`.
  - **oliviajoga.hu 1.5.7 – levélküldés** (Áron: „szerintem nem küld emailt még a rendszer … küldjön!!!”).
    - Az új admin fiók jelszólinkje nem érkezett meg, mert a VPS-en a PHP saját levélküldése nem kézbesít.
    - Új `inc/mail.php`: a WordPress az `OYS_SMTP_*` konstansokkal az SMTP-n küld. A konstansokat a
      `hpv-mail wp-link info@oliviajoga.online oliviajoga-hu` írja a szerveren; a jelszó a szerveren marad.
    - Feladó: „Olívia jóga <info@oliviajoga.online>”.
    - A távirányító állapotában „mail”, próbalevél: `POST oj/v1/mail-test` (csak a belépett admin saját címére).
    - Helyben próbálva, ál-SMTP-szerverrel.
    - Áron fiókja a .hu-n: `aronritok` (#3, administrator), a `site-user` paranccsal.
  - **oliviajoga.hu 1.5.8:** a távirányító `X-OYS-Auth` fejléce bármilyen alakot elfogad:
    - `felhasználó:jelszó`, `e-mail:jelszó`, base64, `Bearer`/`Basic` előtaggal;
    - vagy csak az alkalmazásjelszót (ilyenkor az adminok alkalmazásjelszavai között keres).
    - Mindig a WordPress saját ellenőrzésével. Helyben próbálva: 6 helyes alak beenged, a rossz jelszó nem.
    - Ok: Áron elmentette a jelszót, de az 1.5.7 nem fogadta el; az érték pontos alakját nem látjuk.
  - **oliviajoga.hu 1.5.9:** az alkalmazásjelszó-ellenőrzés REST-kérésként fut akkor is, ha egy bővítmény a kérés elején
    lekéri a felhasználót (`application_password_is_api_request` erre az egy hívásra). Helyben a hibát nem sikerült
    előidézni, ez csak óvatosság.
  - **oliviajoga.hu 1.5.10 → 1.5.11:** a távirányító az `X-OJ-Remote` fejlécet is elfogadja.
    - Az 1.5.10-ből kimaradt egy segédfüggvény: fejléces REST-kérésnél hibával állt le, de kiadás nem készült belőle.
    - Az 1.5.11 javítja. Helyben 5 helyes alak beenged, a rossz és a hiányzó nem; hibanapló üres.
  - **A .hu párosítva a HotPotban** (11.17, 16:08): a képernyőképről levett jelszóval, `X-OJ-Remote` fejléccel.
    - A 4. olvasat volt a jó: egy „0” valójában „O”.
    - A HotPot jelentése: WordPress 7.1.4, állapot 86%, 2 bővítmény-frissítés vár.
  - **olivia-studio 1.22.2 / 3.11.120:** az .online ugyanazt a fejléc-belépést kapta, mint a .hu (`X-OJ-Remote`, bármilyen
    alak, REST-kérésként). Teszt: 1613/0.
  - **Áron nem tudott belépni az .online-ra** (11.17).
    - A `site-user oliviajoga-online aronritok@gmail.com administrator` paranccsal: a fiók (#219) megvan, admin;
      jelszó-visszaállító linket küldtünk.
    - **olivia-studio 1.22.3 / 3.11.121:** a jelszócsere azonnal feloldja a belépési zárat (6 hibás próba után 15 perc).
      A hibaüzenet eddig is ezt ígérte, de nem így működött. Teszt: 1615/0.
  - **Sütisáv, GA4, adatkezelési tájékoztató a .hu-n – oliviajoga.hu 1.6.0, oliviajoga.online 3.11.122** (Áron, 11.17:
    „Az Arovia legyen minden, igen. A GA4 oké, most beküldtem a sitemapet a .hu-ra is.”).
    - Új `wp-theme/inc/consent.php`, ugyanúgy, mint az .online-on:
      - Consent Mode v2, a mérés G-WM1P5R67VJ, a `gtag.js` csak az „Elfogadom” után töltődik be;
      - a döntés az `oy_consent` sütiben; ha az Ads Connect saját sávot mutat, a miénk félreáll;
      - a „Sütibeállítások” újranyitja a sávot.
    - Átjárás a két oldal között (`linker`): mindkét oldalon.
    - Új oldal: `/adatkezelési-tájékoztató`.
      - Adatkezelő az Arovia Group LLC, kapcsolat: info@oliviajoga.online.
      - Csak az .online tájékoztatójának tényei kerültek bele, plusz: a .hu-n nincs fiók és fizetés; a Bejelentkezés
        november 25-ig a Motibróba visz, utána az .online-ra.
      - A téma beállító lépése WordPress-adatvédelmi oldalnak jelöli.
    - A láblécben (csak a WordPress-témában): „Adatkezelési tájékoztató · Sütibeállítások”.
    - Helyben próbálva:
      - a sáv megjelenik, „Elfogadom” előtt nincs Google-kérés, utána van;
      - a süti `stats`, a link újranyitja a sávot;
      - a tájékoztató oldal 200-zal jön, Aroviával.
    - Search Console: Áron beküldte a .hu sitemapjét.
  - **HotPot mindkét oldalon párosítva** (11.17).
    - Az .online-t a `claude` felhasználó alkalmazásjelszavával párosítottam (képernyőkép, `X-OJ-Remote`, első olvasatra jó).
    - Mindkét oldal jelentést küld:
      - .hu: állapot 72%, 2 frissítés vár;
      - .online: állapot 80%, 5 frissítés vár;
      - sebezhetőség egyiken sincs.
    - A kapcsolati kódok elhasználva, a scratchpadből törölve.
    - A két alkalmazásjelszó csak a munkamenet scratchpadjében van (600-as jogosultsággal). Mindkettő chatben ment át,
      ezért ha végeztünk, vissza kell vonni őket.
    - A munkamenet hálózati titka az `X-OYS-Auth`-ot felülírja, és az űrlap a szóközös alkalmazásjelszót nem fogadta el.
    - Áron a jelszót képernyőképen küldte; csak a scratchpadben van (600-as jogosultsággal), a repóban nincs.
  - **HotPot:** a két oldal felvéve Olívia ügyfélkartonjához (4070003): oliviajoga.hu = 150002, oliviajoga.online = 150003.
    - A kapcsolati kódok 7 napig érvényesek. Nincsenek a repóban; ha lejárnak, a HotPotban újat lehet kérni.
  - **SEO OS:** két új projekt, „draft”, Olívia ügyfele alatt.
    - 22: oliviajoga.hu, élesítés 11.17.
    - 23: oliviajoga.online, élesítés 11.12.
    - Kezdő kulcsszónak csak a 16-os kutatásban mért kifejezések kerültek be.
  - **.hu távirányító (`inc/remote.php`):**
    - A cím `https://oliviajoga.hu/?rest_route=/oj/v1/…`, mert az iu_theme szándékosan elrejti a `/wp-json/` előtagot.
    - Belépés: `X-OYS-Auth` fejléc, admin alkalmazásjelszóval, csak HTTPS-en.
    - Útvonalak: `status` (a csomag állapotával), `edits`, `setup`, `kit`, `flush`.
    - Helyben kipróbálva: mindkét címalak beenged, jelszó nélkül és rossz jelszóval 401.
  - **Nyitva (Áron):**
    - Egy-egy admin alkalmazásjelszó a két éles oldalon. Ezzel párosítom a HotPotot, és ez a távirányító is.
    - Az Ads hubban Olívia profilja, ha lesz hirdetés.
    - GA4 és Search Console: Google-hozzáférés kell. A .hu-hoz sütisáv és adatkezelési tájékoztató is.
  - **A fő ágnak:** hogy minden ügyféloldalon így legyen, a csomagot az iu_theme-be vagy a `hpv-migrate`-be kell tenni
    (DONTESEK 2026-11-17).
  - **„Még a régi oldalt nyitja meg”** (Áron, 11.17). A régi oliviajoga.hu (ELIN) 301-gyel, végleges átirányítással
    küldött a Google Site-ra, és a böngészők ezt megjegyzik.
    - Az 1.5.5-től az új oldal az első látogatáskor egyszer kiüríti a böngésző tárolt adatait erre az oldalra
      (`Clear-Site-Data: "cache"`, `oj_fresh` technikai süti).
    - Ehhez a böngészőnek egyszer el kell érnie egy új címet, pl. `https://oliviajoga.hu/?uj`.
    - A régi Google Site-ra kell egy gomb erre a címre (Áron vagy Olívia).
    - **11.18, a Google Site törlése után** („nem található az oldal”):
      - A DNS jó (@ és www → 37.148.205.64), mind a négy címforma az új oldalra visz; az oliviayoga.hu (y-nal) nem létezik.
      - A hiba a böngészők megjegyzett 301-es átirányítása a már törölt Google Site-ra.
      - Próbával mérve:
        - Chromium: a DNS-csere után is a régi helyre megy, egy `/?uj` látogatás végleg megjavítja;
        - Firefox: nem jegyzi meg, rendben van;
        - WebKit: a `Clear-Site-Data` nem törli, de a böngésző újraindítása után már az új oldal jön.
      - Valódi iPhone-Safarin nem próbáltuk: ott a Beállítások → Safari → Haladó → Webhelyadatok → oliviajoga törlése biztos.
      - Mindenki másnak: a törölt Google Site visszaállítása (Olívia fiókja, Drive → Kuka) egyetlen gombbal:
        https://oliviajoga.hu/?uj

- **Vendégvélemények – olivia-studio 1.23.0, oliviajoga.online 3.12.0** (Olívia, 11.17: „szeretnék valamilyen formában
  véleménykérést … Lehessen névvel és név nélkül véleményezni. Szólj Áronnak …”; Áron: „Mindkettő” – saját vélemények és
  Google).
  - Új `includes/class-reviews.php`.
  - **Ki írhat:** aki már volt órán. A levélben saját, aláírt linket kap (belépés nélkül működik), vagy belépve írhat.
    Vendégenként egy vélemény, később módosítható.
  - **Megjelenés:**
    - a vendég választ: teljes név, csak keresztnév vagy név nélkül („Egy vendég”), és hozzájárul;
    - e-mail-cím soha nem jelenik meg;
    - jóváhagyás nélkül semmi nem kerül ki: Stúdió → Vélemények (Megjelenítés / Levétel), és push-értesítés Olíviának.
  - **Az oldalon:**
    - a főoldalon a GYIK előtt, kártyákon, alattuk a mondat, hogy olyan vendégek írták, akik már voltak órán;
    - amíg nincs jóváhagyott vélemény, a blokk nem jelenik meg.
  - **Kérés:**
    - a `review_request` levél (Stúdió → E-mailek, alapból bekapcsolva), vendégenként egyszer, az első óra után, 10–19 óra
      között;
    - csak a bekapcsolás utáni órákra, így a régi vendégek nem kapnak tömeges levelet.
  - **Google:** a köszönő oldalon mindenkinek felajánlja a Google-értékelést, szűrés nélkül.
    - Csak akkor jelenik meg, ha az `oys_google_review_url` be van állítva (REST: /wp/v2/settings).
    - Olívia Google Cégprofil-linkjét a portálon elkértük.
  - Az űrlap oldala `/velemeny/`: a bővítmény hozza létre, nincs a keresőben és a sitemapben.
  - Az adatvédelmi oldalon a hírlevél után egy „Vélemény” sor jelenik meg, futás közben hozzáadva, az oldal szövegéhez nem nyúl.
  - Tesztek: 1641/0. Helyben próbálva telefonnézetben: 3 kártya, az űrlap 3 névválasztással, a küldés köszönő oldalra visz.

- **2026-11-18 – AI-ajánlás mindkét Olívia-oldalon (oliviajoga.hu 1.7.0, oliviajoga.online 3.12.1).** Áron: „az AI-ok
  szeretném, hogy ajánlják Oliviát … csináld meg ugyanazt … mint a Hello Provision-nél”.
  - **Az alap:** ugyanaz az MI-láthatósági csomag, mint a helloprovision.com-on és az aronritok.com-on: llms.txt,
    llms-full.txt, .md tükrök, MI-robotok a robots.txt-ben, IndexNow és schema.
  - **oliviajoga.hu:**
    - Az llms.txt eddig az iu_theme alapja volt, összefolyt morzsamenüs leírásokkal és ismétlődő címekkel.
    - Most a build.py kézzel válogatott szövege jön (`inc/ai.php`, `iu_llms_txt` szűrő); november 26-tól az „after” változat.
    - Tartalma:
      - ki Olívia, és a végzettségei;
      - az órák és a szerdai időpont;
      - a termes órák 11.25-ig;
      - a kapcsolat;
      - az oldalak a saját leírásukkal;
      - az online oldal órái;
      - a cikkek, a sorozatok részcímével („Milyen elemekből áll … 1. Nyújtó gyakorlatok”, „A jóga 8 ága ॐ 4. Pránajáma”).
    - llms-full.txt: az oldalak szövege a morzsamenü nélkül (`iu_llms_page_html`).
    - Schema:
      - WebSite (publisher);
      - Organization (sameAs: az online oldal, Instagram, Facebook; a szolgáltatások katalógusa, a linkek az online oldalra);
      - Person (jobTitle, 3 végzettség, knowsAbout, sameAs: az online Rólam is, worksFor);
      - BreadcrumbList minden aloldalon, az oldal saját nevével.
    - `tools/check.py`: JSON-LD-ellenőrzés minden oldalon (2383/0, az „after” változat 1219/0).
  - **oliviajoga.online:**
    - Az iu_theme és a téma saját llms-e egyszerre futott, ezért a `<head>` linkjei és a robots.txt MI-csoportja kétszer
      szerepelt.
    - Ahol a téma sajátja fut, ott az iu_theme-é ki van kapcsolva (`iu_llms_enabled`); a US oldalon és a demón az iu_theme-é fut.
    - A schemában Olívia és a stúdió sameAs-ában ott van az oliviajoga.hu, így a két oldal egy embernek látszik.
    - Tesztek: 1643/0.
  - **Áron teendői (kézi):**
    - Bing Webmaster Tools → „Importálás a Google Search Console-ból” mindkét oldalra; a ChatGPT-keresés és a Copilot a
      Bingből dolgozik.
    - A GSC-ben „Indexelés kérése” a fő oldalakra.
    - Google Cégprofil Olíviának; ez kell a Google-értékelésekhez is.

- **2026-11-18 – Foglalás oldal (/foglalas/): bevezető mondat és lenyíló naptár (oliviajoga.online 3.12.2, olivia-studio 1.23.1).**
  Olívia a portálon (9148907): „írd át ezt a mondatot, hogy Ha csoportosan jógáznál, válassz órát az alábbi órarendből. A
  kezdős mondat nem kell, majd a foglalás alá tedd be a további órák a naptárban cta-t, amire nyíljon le a naptár, alatta
  meg maradjon az egyéni órás szekció”.
  - A mondat: „Ha csoportosan jógáznál, válassz órát az alábbi órarendből.”, a kezdőkről szóló rész nélkül.
  - Az órák alatt egy „További órák a naptárban” gomb (`<details id="naptar">`): koppintásra ott helyben lenyílik a lapozható
    havi naptár, a nyíl megfordul. A `/foglalas/#naptar` link nyitva hozza. Az egyéni órák doboza alatta marad.
  - Tesztek: 1647/0. Telefonon (390 px) csukva és nyitva is próbálva, oldalra nem csúszik.

- **2026-11-19 – Indexelés: IndexNow-javítás a .hu-n (1.7.1), Search Console állapot.** Áron: „Kész az llm csomag?
  Automata indexálás gscbe?”
  - **IndexNow (Bing → ChatGPT-keresés, Copilot; Yandex):**
    - A .online beküldése rendben (43 cím, 200).
    - A .hu-é 403 („UserForbiddedToAccessSite”) volt, pedig a kulcsfájl jó. A DNS tiszta: nincs AAAA rekord, az IP
      ugyanaz, mint a .online-é. A domain addig a Google Sites-ra mutatott, és a Bing a sikertelen kulcs-ellenőrzést
      megjegyezte.
    - Javítás (`inc/ai.php`): ha az utolsó beküldés 403, a téma új kulcsot készít és újraküld. Naponta legfeljebb egyszer
      csinálja, összesen 3 kulcscserével. Kézzel: `POST oj/v1/indexnow`; állapot: `oj/v1/status` → `indexnow`.
    - Helyben a Bing válaszát szimulálva próbálva.
  - **Google:**
    - Google-nél nincs IndexNow. Az Indexing API csak állásajánlatra és élő közvetítésre való, általános oldalra nem.
    - A legitim automata út a sitemap: a CRM napi `hpv_gsc_manage` köre a bekötött Google-fiókok tulajdonain újraküldi a
      sitemapeket.
    - Olívia két oldala nincs köztük: a bekötött fiókok nem látják őket, és az ügyfélkartonon (4070003) üres a
      `gsc_property`.
    - Áron 11.19-én újrakötötte a Google-t a CRM-ben, és a fő fiók most látja az `sc-domain:oliviajoga.hu`-t és az
      `sc-domain:oliviajoga.online`-t.
    - Az ügyfélkartonra (4070003) beírtam: `gsc_property = sc-domain:oliviajoga.hu`.
    - A `hpv_gsc_manage` kör (`POST hpv/v1/pm/gsc-manage`) mindkettőn sikeresen újraküldte a sitemapet:
      - .online: `wp-sitemap.xml`, 40 cím;
      - .hu: `www.oliviajoga.hu/wp-sitemap.xml`, 31 cím.
    - Ezentúl naponta magától fut.
  - **Még Áronnál:**
    - A .hu GSC-jében van egy hibás „sitemap”: `https://www.oliviajoga.hu/rolam` (oldal, nem sitemap; 1 hiba). Törölni
      kell, és felvenni a `https://oliviajoga.hu/wp-sitemap.xml`-t (www nélkül).
    - A GA4 (`G-WM1P5R67VJ`, mindkét oldal ezt használja) egyik bekötött fiókból sem látszik. Áron kérésére feladat
      Olíviának a portálon (projekt 7350003, feladat 186228610, „Ügyfélre vár”, határidő 11.24., 7 pontos lista):
      - hozzáférés: aronritok@gmail.com Megtekintőként;
      - 14 hónapos adatmegőrzés;
      - a két domain;
      - `checkout.stripe.com` nem kívánt hivatkozásnak;
      - `generate_lead` kulcseseménynek;
      - Search Console-kapcsolat;
      - időzóna és HUF.
      Ha kész, a kartonra a `ga4_property`.
    - Mellékes: az `olivia.ideastyle.hu@gmail.com` extra fiókon (9 tulajdon, pl. helloprovision.com, mandala.hu) a
      sitemap-újraküldés nem megy; ehhez a fiókot írási (webmasters) joggal újra kell kötni (`reconnect: true`).

- **2026-11-19 – Lábléc: „Készítette: HelloProVision” mindkét oldalon (oliviajoga.online 3.12.3, oliviajoga.hu 1.7.2).**
  Áron: „mindkét webe cseréld a footerbe: helloprovision csinálta a webet, a megszokott módon helyezd el”.
  - .online: az alsó sor végén „Készítette: HelloProVision” (helloprovision.com, új ablakban), Áron neve helyett; a
    demón nincs.
  - .hu: ugyanez az alsó sor végén (`© 2026 Kovács Olívia · Adatkezelési tájékoztató · Sütibeállítások · Készítette:
    HelloProVision`), november 26. után is.
  - Tesztek: 1647/0, .hu 2383/0 (után: 1219/0).

- **2026-11-19 – Tesztüzem az oliviajoga.online-on (olivia-studio 1.24.0, téma 3.12.4).** Áron: „minden egyes funkcióra
  egy teszt gombot … indítható Olivia által … kapcsolható legyen, és élesben menjenek ki az üzenetek, induljon a live
  kit, induljon minden”.
  - **Hol:** Stúdió → Tesztüzem (`admin.php?page=oys-test`); a telefonos appban: Több → Tesztüzem.
  - **Működés:**
    - Bekapcsoló, és egy mező, hogy melyik postafiókba menjenek a tesztlevelek (alapból a stúdió értesítési címe).
    - A tesztvendégek ugyanaz a postafiók `+teszt1`, `+teszt2` … címmel, `oys_test` jelöléssel és „TESZT Vendég”
      névvel.
  - **18 gomb, mindegyik ugyanazon a kódon fut, mint az igazi vendégnél:**
    1. regisztráció és üdvözlő levél;
    2. elfelejtett jelszó;
    3. bérletvásárlás;
    4. foglalás;
    5. emlékeztetők és élő link;
    6. élő óra (LiveKit, oktatói és vendéglinkkel);
    7. telt ház és várólista;
    8. lemondás és „felszabadult egy hely”;
    9. Olívia lemond egy órát;
    10. egyéni óra: kérés és ajánlat;
    11. ajándékutalvány;
    12. véleménykérés;
    13. „Hiányzol” és a heti levél;
    14. születésnapi kupon;
    15. lejáró bérlet;
    16. hírlevél;
    17. push és reggeli összefoglaló;
    18. ügyfélimport (Motibro-formátum).
    - Külön gomb: „Minden lefuttatása sorban”.
  - **Pénz nem mozdul:** a fizetés szimulált, a rendelés „kifizetve” lesz kártya nélkül, a levelek és a bérlet ugyanazok.
  - **Napló:** minden futás után a kártyán látszanak a lépések (✓/✗), a kiment levelek (tárgy → cím) és a push-ok.
    - Ha egy levél ki van kapcsolva az E-mailek alatt, ezt kiírja, nem küldi ki erőből.
  - **A tesztórák:**
    - „élő”: pár perc múlva kezdődik, az élő terem rögtön nyitva;
    - „egyhelyes”: 3 nap múlva, várólistához és lemondáshoz;
    - „elmúlt”: a véleménylinkhez.
    - Csak a stúdió látja őket: a nyilvános órarendben, az llms.txt-ben és a schemában nincsenek
      (`oys_hidden_session_ids` szűrő az `OYS_Schedule::query`-ben).
  - **Takarítás:** a „Tesztadatok törlése” és a kikapcsolás mindent visz (vendégek, foglalások, bérletek, rendelések,
    ajándékkártyák, kérések, kuponok, vélemények, beszélgetések, órák).
  - **HathaFlow (több tanáros hálózat):** a véleménylink is oldalhoz kötött aláírást kapott (`oys_sig`, mint 1.7.2 óta a
    belépő- és rendeléslinkek, a LiveKit-teremnév pedig a `home_url` hash-ét tartalmazza). A régi linkek egy oldalas
    telepítésen továbbra is működnek.
  - **Tesztek és helyi próba:**
    - 1675/0: minden gomb lefut, a levelek a `+teszt` címekre mennek, a tesztóra rejtett, a takarítás után nem marad
      semmi.
    - Helyben telefonnézetben próbálva: 18 kártya, magyarul; a bérletvásárlás és a foglalás ✅.

- **2026-11-19 – Linkek az oliviajoga.hu-ról az oliviajoga.online-ra (1.7.3), a .online sitemapje a Search Console-ban.**
  A főchat végrehajtó-sorából (Áron: „5. mehet”, CRM-feladat 186228556).
  - **Sitemap:** az `sc-domain:oliviajoga.online` sitemapje (`wp-sitemap.xml`, 40 cím) be van küldve, a Google 11.19-én
    letöltötte, 0 hiba. A CRM napi köre (`hpv_gsc_manage`) innentől magától újraküldi.
  - **Linkek:**
    - Eddig a .hu 32 oldala a .online-ra szinte csak a lábléc gombjával linkelt; tartalmi link csak a főoldalon és az
      Árakban volt.
    - Most a 24 cikk végén és a Rólam alján egy rövid „Gyakorolj velem online” bekezdés áll (`online_note()`), Olívia
      hangján, a .online valós tényeivel. Linkjei: csoportos online flow jóga (szerdánként 18:30–20:00), egyéni online
      jóga, online kismamatorna, oliviajoga.online.
    - November 26. után is így marad.
  - `check.py`: 2408/0 (az „after” változat 1219/0); telefonon próbálva.
  - **„Indexelés kérése”:** API-ból nem megy; Áronnak a listát a jelentés adja.
  - **8 túl hosszú cikkcím (oliviajoga.online 3.12.5):** a keresőcím (`<title>`) 55 karakternél hosszabb volt:
    - a hatha-sorozat 1., 2., 3. és 5. része;
    - „Zene a jóga órán”;
    - „A jóga 8 ága” Ászana, Pránájáma és Pratyahara része.
    - Rövidebb SEO-címet kapnak (`oy_seo_title`, legfeljebb 60 karakter), például „Hatha jógaóra elemei 3: vinyásza,
      napüdvözlet | Olívia Jóga”. A hatha-sorozat 4. része az egységesség miatt kap ilyet.
    - Az oldalon látható cím nem változik. Ha Olívia már adott meg saját SEO-címet, az marad.
    - Egyszer fut (`oy_migrate_titles_1119`), a tartalomfájlban `seo_title`. Tesztek: 1676/0.
  - **Olívia Instagram/Facebook-bio linkje:** Áron kéri meg rá Olíviát (a CRM-feladat szerint).

- **2026-11-19 – Olívia 9148956: naptár, lemondás, mobil Főoldal (oliviajoga.online 3.12.6, olivia-studio 1.24.1).**
  Olívia portálüzenete (9148956), hat pont.
  - **Flow-oldal:** a csoportos online flow jóga oldalán is ott a „További órák a naptárban” lenyíló az órák alatt
    (`OYS_Frontend::more_calendar()` az óra slugjával).
  - **Csak a kiválasztott nap:** a naptár alatt nem ismétlődik az összes következő óra. Előbb egy rövid tipp látszik
    („Koppints egy napra a naptárban, és itt látod az órát.”), a napra koppintva csak annak a napnak az órája jelenik meg
    (billentyűzettel is: Enter/szóköz). Mindenhol így működik, ahol a naptár van.
  - **Bejelentkezés és árak:** a naptár ott is lenyíló (`details#naptar`), alapból csukva. A főoldali gomb
    (`/bejelentkezes-es-arak/#naptar`) nyitva hozza.
  - **Lemondás:**
    - a „visszanézheted egy hétig” alatti lemondási sor kikerült (alul úgyis ott van);
    - az alsó lemondási szabály nagy betűvel kezdődik, és ugyanolyan dizájnt kapott, mint a visszanézés-sor (ikon +
      félkövér „Lemondás:”).
  - **Mindenhol a lemondás mellé:** „Ha befizetted a csoportos órát, de nem tudsz ott lenni, ugyanúgy visszanézheted egy
    hétig, és utólag a közös beszélgetésbe is bekapcsolódhatsz.” (`oy_noshow_text()`, `oy_with_noshow()`):
    - árak oldal (alsó lemondási szabály), főoldal, csoportos óraoldalak lemondási doboza;
    - GYIK „Mi a lemondási szabály?” válasza (egyszer futó migráció: `oy_migrate_noshow_1119`).
    - Az egyéni órára nem vonatkozik, ott nincs közös felvétel.
    - Az ÁSZF jogi szöveg, azt nem írtuk át: Áron dönt róla.
  - **Mobil menü:** a menü első eleme „Főoldal”.
  - **Áronnak továbbítva, döntésre:** privát óra kérése a naptárban kiválasztott napra/időpontra; páros privát óránál
    meghívó a társnak. Amíg Áron nem dönt, nem épül.
  - Tesztek: 1686/0. Helyben telefonnézetben próbálva (naptár, lemondás, menü, nincs vízszintes görgetés).

- **2026-11-19 – Olívia további kérései aznap délelőtt (oliviajoga.online 3.12.7, olivia-studio 1.24.2).**
  Portálüzenetek 9148960–9148966, képernyőképekkel.
  - **„Mire jelentkezel?” (egyéni óra kérése, „itt ez lehetne picit szebb?”):** a lenyíló helyett koppintható kártyák
    (egy-egy óra), a kiválasztott sötétzöld, sárga pipával. Alatta csak a kiválasztott óra árai látszanak, az időtartam
    és a létszám is ehhez igazodik. JavaScript nélkül minden ár látszik, és ugyanúgy működik.
  - **Órák oldal, „Melyik órát válasszam?”:** a csoportos flow jóga dobozából kikerült a „Kezdőket is szeretettel
    várok.” (máshol marad, ott nem kérte).
  - **„Hogyan zajlik egy élő online óra?”:**
    - új 5. lépés a „Pár perccel a kezdés előtt lépj be…” után: „Az egyéni óra mindig egy rövid felméréssel,
      beszélgetéssel kezdődik.” (Olívia szavaival, kicsit simítva);
    - a felvétel-doboz első sora: „A csoportos óráról felvétel készül, amit egy hétig visszanézhetsz, de azon csak én
      látszom és hallatszom.”, a második: „Az óra chatje az óra után is megmarad: …”.
    - Élesen egyszer futó migráció (`oy_migrate_classes_1119`). Olívia szerkesztőben átírt bekezdései ugyanígy
      változnak és az övéi maradnak (`oy_1117_apply`; a mentett nem törő szóközöket is kezeli).
  - **Lemondás a foglalási bővítmény saját helyein is:** foglalási űrlap, fiók, egyéni óra kérése, e-mailek.
    - A „Ha befizetted a csoportos órát, … visszanézheted egy hétig …” mondat az egyéni órás rész elé kerül.
    - Ehhez a bővítményben `OYS_Settings::cancel_policy()` és az `oys_cancel_policy` szűrő kell, amire a téma ráköt.
  - **3.12.8 – Olívia saját felvétel-sorai:** élesen kiderült, hogy a felvétel-dobozt közben a szerkesztőben maga is
    átírta („… hallatszom. Ezt egy hétig visszanézheted.” / „Az órák chatje az óra után is megmarad …”). A migráció erre
    rátette az „amit egy hétig visszanézhetsz” részt, így a hét kétszer szerepelt. Egy egyszer futó javítás
    (`oy_migrate_classes_1119b`) pontosan az ő szövegét állítja vissza.
  - **Távoli állapotlekérés javítása (`OYS_Ops::header_try`):** egy puszta alkalmazásjelszó kb. tizenötből egyszer
    base64-ként „valami:valami”-nak olvasódott, és ilyenkor nem engedett be. Ha így nem megy, most magában, jelszóként is
    kipróbálja. Ezt egy véletlenszerűen elbukó teszt hozta elő; az új teszt mindig ilyen jelszóval próbál.
  - Tesztek: 1704/0 (a 3.12.7 előtt 1702/0, háromszor egymás után). Helyben telefonnézetben próbálva (kártyák, csak a választott ár, 6 lépés,
    nincs vízszintes görgetés).

- **2026-11-19 – Átnevezett óratípusok: a duplikátumok eltűnnek, az ő nevei és webcímei maradnak (oliviajoga.online 3.12.9).**
  Olívia, 9148967–9148972 (11:58–12:12 magyar idő szerint).
  - **Mi történt:**
    - 10:44 körül átnevezte az öt óratípust: Csoportos flow jóga, Egyéni jóga, Privát kismamatorna, Egyéni stretching és
      mobilizáció, Privát relaxáció és légzés („az online nem kellett bele, mert a főmenüben úgy nyílik le, hogy online
      órák”).
    - Kettőnek a webcímét is átírta: `hatha-flow` → `hatha-flow-joga-online`, `stretching` → `stretching-mobility`.
    - Belül azonban ez a név a kulcs: az órarend, a foglalások és a bővítmény ezen ismeri az órát.
    - A 3.12.6-os frissítés (`oy_import_all`) ezért hiányzónak látta ezt a kettőt, és újra létrehozta őket a régi
      címekkel. Így lett 7 kártya.
  - **Javítás, egyszer fut (`oy_migrate_classes_1119c` → `oy_class_copies_fix`):**
    - a két másolat törlődik;
    - az ő órái (címük, szövegük, képeik) visszakapják a belső nevüket;
    - az általa adott webcím lesz a nyilvános cím (`oy_class_paths` opció);
    - a régi címek (`csoportos-online-flow-joga`, `online-stretching-es-mobilizacio`, a belső név) 301-gyel átirányítanak.
    - Az egyéni óra szolgáltatásai (kérőűrlap, árak) felveszik az új óraneveket, ha még a régit viselték
      (`oy_class_service_names`, a tartalomfájl `title_prev` mezője alapján).
  - **Hogy ne forduljon elő újra:**
    - ha egy óratípus webcímét a szerkesztőben átírja, az új cím lesz a nyilvános, a belső név marad
      (`wp_insert_post_data` szűrő). A korábbi címek mind átirányítanak (`oy_class_paths_old`).
    - Az import a nyilvános cím alapján is megtalálja az órát (`oy_import_target`).
  - **A tartalomfájlban is az ő nevei vannak** (óratípusok és egyéni szolgáltatások).
  - **3.12.10:** az órák oldal „Melyik órát válasszam?” dobozaiban is az új nevek állnak (`oy_class_guide_names`; a javítás
    `d` revízióval még egyszer fut, a korábbi lépései ilyenkor már nem csinálnak semmit). Tesztek: 1720/0.
  - **Főoldal, „Így működik” 1. lépés:** „Foglald le az időpontot alkalmi jeggyel vagy bérlettel.”
  - **Bejelentkezés és árak, a csoportos óra dobozában:**
    - kikerült „A csoportos gyakorlás alatt részed lesz …” mondat („ez a mondat nem is kell ide”);
    - két új piktogram: „1 hétig visszanézhető” és „később is kapcsolódhatunk a közös chatben”.
  - Tesztek: 1719/0. Helyben lejátszva az élő helyzet (átnevezés + régi frissítés → 7 óra), a javítás után 5 óra, a
    címek és átirányítások jók, egy újabb frissítés nem hoz létre semmit.

- **2026-11-19 – Foglalási űrlap: látható vendégmezők, levegő a gomb körül (oliviajoga.online 3.12.11).**
  Olívia, 9148973–9148975.
  - „nem látszanak a rublikák”: a „Hozol barátokat?” szürke dobozában a vendégek név- és e-mail-mezője ugyanolyan szürke
    volt, mint a doboz. Most fehérek, vékony kerettel (a nyilatkozat mezője is).
  - „ez picit egybelóg foglalásnál”: a lemondási doboz, a „Tovább” gomb és az ÁSZF-sor között most van hely.
  - Tesztek: 1721/0. Helyben telefonnézetben próbálva.

- **2026-11-19 – Privát időpont a naptárból, páros meghívó, ÁSZF: visszanézés, GoDaddy-tárhely (oliviajoga.online 3.12.12,
  olivia-studio 1.25.0, oliviajoga.hu 1.7.4).**
  Áron döntései Olívia két kérdésére, és a jogi szövegek.
  - **Privát időpont a naptárból:**
    - a naptárban minden jövőbeli nap kiválasztható, nem csak az, amelyiken csoportos óra van;
    - alatta egy visszafogott ajánlat jelenik meg: „Más időpont lenne jó? Ha egyénileg vagy párban jönnél, kérj egyéni órát
      erre a napra”, időpontválasztóval (7:00–20:30, félóránként, vagy „Bármikor jó”);
    - a gomb a kérőűrlapra visz (`?tab=private&pday=…&ptime=…`), ahol a „Neked megfelelő napok és időpontok” mező már
      így kezdődik: „november 20., péntek, 18:00”;
    - óra nélküli napon: „Erre a napra nincs csoportos óra.”;
    - kikapcsolható az `oys_calendar_private` szűrővel.
    - **Csapda:** a `day` paraméter a WordPress dátum-archívuma (`?day=…` egy oldalon 404), ezért `pday` / `ptime`.
  - **Páros meghívó (e-mail alapján, mint a csoportos vendégeknél):**
    - két főnél a kérőűrlapon kötelező a társ neve és e-mail-címe (DB 15: `partner_name`, `partner_email`);
    - amikor a foglalás megtörténik (kártya vagy bérlet), a társ a foglalás vendégsora lesz („párban fizetve”, `pair`);
    - ugyanúgy megkapja a vendégmeghívót (részletek, kellékek, saját belépőlink, naptárfájl, nyilatkozat), és ugyanígy kap
      emlékeztetőt;
    - a kérés összefoglalójában (e-mail, Hub, fiók) ott a társ;
    - egyszer fut, nem duplikál.
  - **ÁSZF (hatályos 11.19-től):**
    - 3. pont: a páros óra társa;
    - 5. pont: befizetett, de kihagyott csoportos óra: egy hétig visszanézhető, utólag a chatbe is be lehet szállni;
    - 8. pont „Felvételek, visszanézés, szerzői jog”: csak az oktató van a felvételen, a foglalók 7 napig nézhetik vissza
      (akkor is, ha nem voltak ott), ugyaneddig írhatnak a chatbe, a felvétel 30 nap után törlődik, egyéni óráról nincs
      felvétel.
    - Élesen a meglévő módon (`oy_migrate_legal`, új revízió) cserélődik.
  - **Adatkezelés:**
    - az adatkezelő mindkét oldalon eddig is az Arovia Group LLC volt;
    - új „Hol vannak az adataid?”: GoDaddy virtuális szerver (VPS), EU-s adatközpont, a HelloProVision üzemelteti. A cím
      mindkét domainnél a GoDaddy secureserver.net-re (Host Europe, Strasbourg) mutat.
    - A „Kik férnek hozzá?” között ott a tárhely; a vendégek mellett a páros óra társa is.
    - Élesen csak a téma saját mondatai cserélődnek (`oy_migrate_privacy_1119`). Az impresszum tárhely-sora is pontosabb.
  - Tesztek: 1738/0. A .hu ellenőrzés: 2408/0, `--after` 1219/0. Helyben telefonon végigpróbálva: napválasztás,
    időpont, előtöltött kérőűrlap, páros társ-mezők.

- **2026-11-19 – Mindenhol a következő 3 óra, „…és foglald le a helyed!” (oliviajoga.online 3.12.13, olivia-studio 1.25.1).**
  Olívia, 9148980–9148982.
  - **„itt mindig 3 következő óra látszon … minden oldalon ahol van”:** az órarend-listák mindenhol a következő 3 órát
    mutatják: árak oldal, foglalás oldal, óraoldalak (a főoldal eddig is). A többi a „További órák a naptárban” alatt, 6
    hétre előre. Ehhez a bővítményben az `oys_schedule_next` szűrő kell, a téma az online kiadásban 3-ra állítja; más
    oldalon marad 6.
  - **Naptár-tipp:** „Koppints egy napra a naptárban, itt látod az órát, és foglald le a helyed!”
  - Tesztek: 1739/0.

- **2026-11-19 – Profilkép, kötelező adatok, magyar Adatlap; koppintós időpontválasztó (oliviajoga.online 3.12.14–3.12.15, olivia-studio 1.26.0–1.26.1).**
  Áron: „lehessen profilképet feltölteni, az adatok kitöltése kötelező legyen és magyarosíts, pl a környék nem jó”; „a
  bal oldali »A« időpont választó legyen”; „az »itt látod az órát« vegyük ki”.
  - **Profilkép:**
    - az Adatlapon feltölthető (JPG, PNG vagy WebP, legfeljebb 5 MB), mentés előtt látszik az előnézet;
    - cserélhető és törölhető (a régi kép törlődik);
    - a köszöntés mellett is ott van (rákoppintva az Adatlapra visz), és mindenhol látszik, ahol a WordPress profilképet
      mutat (Hub, névsor, admin);
    - csak a böngészőből valóban feltöltött képet fogadja el;
    - nem kerül Olívia médiatárába, és a fiók törlésekor vele együtt törlődik.
  - **Kötelező:** vezetéknév, keresztnév, telefonszám, település – a regisztrációnál és az Adatlapon is.
    - Amíg valami hiányzik, a fiók minden lapján sárga sáv jelzi („Kérlek, töltsd ki az adataidat: …”, „Kitöltöm” link).
    - Üresen hagyott kötelező mező nem törli a meglévő adatot.
    - A vészhelyzeti kapcsolattartó, az egészségügyi megjegyzés és a születésnap szándékosan nem kötelező
      (egészségügyi adatot csak önként adhat meg).
    - Foglalást nem tiltunk le hiányzó adat miatt, csak emlékeztetünk.
  - **Magyarosítás:**
    - Vezetéknév elöl, a megjelenő név „Kovács Olívia”;
    - „Környék” helyett „Település”, továbbá „Telefonszám”;
    - „Kit értesítsünk vészhelyzetben? (nem kötelező)” / „Az ő telefonszáma”.
  - **Adatkezelési tájékoztató:** a fiókadatok között a település és (ha van) a profilkép. Élesen ez is
    `oy_migrate_privacy_1119` (rev `2026-11-19b`, egy új mondatpár).
  - **Időpontválasztó (Áron „A” terve):**
    - a naptár alatt nincs legördülő menü, 14:00 és 21:00 között félóránként koppintható gombok vannak, „Délután” és
      „Este” bontásban (a sáv az `oys_calendar_ask_hours` szűrővel állítható);
    - a „Bármikor jó 14 és 21 óra között” az alapértelmezett;
    - a gomb azt írja: „Időpontot kérek: nov. 20., 18:30”;
    - a link a napot és az időt is átadja a kérőűrlapnak.
  - **Naptár-tipp:** „Koppints egy napra a naptárban, és foglald le a helyed!” (az „itt látod az órát” kikerült).
  - **Óra nélküli nap (3.12.15, olivia-studio 1.26.1):** „Ezen a napon nincs csoportos óra.” (Áron; eddig „Erre a
    napra …”).
  - Tesztek: 1774/0. Helyben telefonon: regisztráció, Adatlap, képfeltöltés/-törlés, időpont-koppintás.

- **2026-11-19 – A fiók menüje minden kijelzőn egyben (oliviajoga.online 3.12.16, olivia-studio 1.27.0).**
  Áron: „a menü férjen bele minden kijelzőn egyben és legyen felhasználóbarát!”
  - **Fiókmenü:** eddig telefonon oldalra görgetett, és az első fül levágódott. Most minden fül ikonnal és névvel látszik:
    - telefonon (≤ 640 px) csempék, soronként 4, a második sor középre igazítva;
    - nagyobb kijelzőn egy sorban;
    - az aktív fül sötétzöld, sárga ikonnal;
    - az Üzenetek olvasatlan-jelvénye az ikonon ül.
  - Ikonok: Foglalások (naptár), Bérletek (jegy), Tagság (szív), Egyéni órák (két ember), Üzenetek (buborék),
    Előzmények (óra visszafelé), Fizetések (kártya), Adatlap (ember).
  - Ellenőrizve 320–1440 px között, 8 szélességen: mind a 7 fül látszik, nincs oldalgörgetés, minden fül legalább 62 px
    magas, és egy felirat sincs levágva. Tesztek: 1776/0.

- **2026-11-19 – A főmenü minden kijelzőn egyben; születésnapi megjegyzés (oliviajoga.online 3.12.17, olivia-studio 1.27.1).**
  Áron: „az oldal főmenüjére gondoltam”; „a vészhelyzeti kapcsolattartó, az egészségügyi megjegyzés és a születésnap nem
  kell, hogy kötelező legyen. a születésnaphoz írd le megjegyzésbe, hogy érdemes kitölteni, mert születésnapodkor egy kis
  ajándékkal fogok kedveskedni.”
  - **Tablet és kisebb laptop (760–1279 px):** eddig csak a „Fiókom” gomb és egy hamburger-ikon látszott, a menüpontok el
    voltak rejtve. Most az egész menü kint van a fejlécben, egy sorban, jobbra igazítva. A betű és a közök a szélességgel
    nőnek. Az „Online órák” almenü koppintásra (tablet) és egérre (laptop) is lenyílik, és a képernyőn marad.
  - **Telefon (az alsó sáv „Menü” gombja):** kis kijelzőn (320×568, 375×667, például iPhone SE) a „Jógáról” és a
    „Kapcsolat” az alsó sáv mögé csúszott. Most minden telefonon egy képernyőn elfér, görgetés nélkül:
    - felül az órák listája, alatta a sárga „Bejelentkezés és árak”;
    - utána az oldalak hármasával (Főoldal, Rólam, GYIK / Jógáról, Kapcsolat);
    - a sorok magassága a kijelzőhöz igazodik, minden gomb legalább 44 px;
    - nagyon alacsony kijelzőn (≤ 620 px) a menü a logót is eltakarja.
  - Ellenőrizve:
    - a fejléc 8 szélességen (320–1920 px);
    - a telefonos menü 6 méretben (320×568-tól 700×1000-ig).
  - **Születésnap:** az Adatlapon „Nem kötelező, de érdemes kitölteni, mert születésnapodkor egy kis ajándékkal fogok
    kedveskedni.” (`oys_birthday_note` szűrő, a téma az online kiadásban állítja).
    - A bővítmény automatikus születésnapi kódja (20%, 30 napig) élesben ki van kapcsolva; ezt Áron dönti el.
    - A vészhelyzeti kapcsolattartó, az egészségügyi megjegyzés és a születésnap nem kötelező (Áron döntése).
  - Tesztek: 1779/0.

- **2026-11-19 – Privát időpontok a naptárból: megmaradnak regisztrációnál és belépésnél, több egyszerre, a foglalt sávok nem választhatók; háttér nélküli ikon (oliviajoga.online 3.12.18, olivia-studio 1.28.0, DB 16).**
  Áron:
  - „privát naptárfoglalás esetén, ha új fiókot hozok létre … eltűnik a foglalni kívánt időpont és nem tudok kicheckoutolni”;
  - „ugyanez ha van fiókja és belép, akkor is mentse”;
  - „lehessen egyszerre több időpontot befoglalni ne csak egyet”;
  - „érzékelje azt, hogyha be van foglalva egy idősáv, ott az ne legyen foglalható”;
  - „csináld meg hogy itt háttér nélküli legyen a logó” (a Chrome kezdőlapja).
  - **Megmarad az időpont:** a fiókoldal regisztráció és belépés után ugyanarra a címre visz vissza (`OYS_Frontend::here()`,
    az űrlap maradványai nélkül), így a kérőűrlapon ott vannak a választott időpontok.
    - Belépés előtt egy sárga doboz mutatja, melyik időpontok várnak elküldésre.
    - Eddig a fiókoldal a sima `/fiokom/`-ra irányított vissza, és ott elveszett minden.
  - **Több időpont egyszerre:** a naptárban több időpont kijelölhető, akár több napon is (legfeljebb 10).
    - „Bármikor jó 14 és 21 óra között” is választható egy napra.
    - A kijelöltek alul egy listában látszanak (×-szel törölhetők), a kijelölt napokon sárga pötty jelzi őket.
    - A gomb: „Időpontokat kérek (3)”. A link: `?tab=private&pslots=2026-11-21T15:00,…`; a régi `pday`/`ptime` is működik.
    - A kérőűrlapon „A választott időpontjaid” lista (egyenként levehető, „Még több időpont a naptárban” link). Ilyenkor a
      „Más napok és időpontok” mező nem kötelező.
    - Küldéskor minden időpont külön kérés lesz, közös `batch_id`-vel: Olívia egyenként igazolja vissza, a vendég egyenként
      fizeti ki.
    - A vendég és a stúdió egy-egy e-mailt kap, benne minden időponttal, és a Hubban is egy bejegyzés lesz.
  - **Foglalt sávok:** áthúzva, nem választható minden félóra, amelyikre egy 60 perces óra ráfedne:
    - bármely meghirdetett óra (csoportos vagy egyéni);
    - más vendég még meg nem válaszolt időpontkérése;
    - a 2 órán belüli időpontok.
    - A naptár naponként kapja a foglalt időket (`data-busy`).
    - A már kijelölt időpontra ráfedő időket sem lehet választani.
    - Küldéskor a szerver újra ellenőrzi a választott hosszal: ha közben foglalt lett, vagy két választott időpont egymásra
      lóg, név szerint szól.
  - **Olívia:** az admin ajánlat-űrlapján a dátum és az idő a kérésből előre ki van töltve (`slot_at`), a Hub listájában is
    látszik az időpont.
  - **Ikon:** az Apple-ikon (amit a Chrome a kezdőlapi csempén mutat) eddig világos négyzeten ült. Most háttér nélküli: csak
    a sötétzöld kör a pöttyel (`apple-touch-icon-online-clear.png`, új név, hogy a böngészők ne a régit mutassák).
  - **Ellenőrzés:**
    - tesztek: 1803/0;
    - helyben telefonon végigvitt út: 3 időpont 2 napon → regisztráció → a kérőűrlapon mind a 3 → elküldve → 3 kérés;
    - a következő látogatónál ezek a sávok áthúzva jelennek meg;
    - visszatérő vendég: időpont → belépés → kérőűrlap az időponttal.

- **2026-11-19 – Az e-mailek az oldal színeiben (oliviajoga.online 3.12.19, olivia-studio 1.28.1).**
  Áron: „Az emailek színe igazodjon a jelenlegi oldal színeihez”.
  - Az e-mailek eddig a stúdió első palettáját használták (erdőzöld #2B5036, lila csík #C6A3EE, rózsaszín ajándékkód
    #FF72B6, halványzöld és lila dobozok).
  - Most egy közös lépés (`OYS_Emails::recolor`, `oys_email_palette` szűrő) küldés előtt egy menetben átszínez minden
    e-mailt: a keretet, a gombokat, a dobozokat, az ajándékkódot, a hírlevelet és a próbaleveleket is.
  - Az online kiadás palettája:
    - Evergreen (#002E2C): fejléc, gombok, linkek;
    - Bright Gold (#FFDF22): a csík a fejléc alatt, a „Belépés az órára” gomb, az ajándékkód;
    - Platinum (#F0EDEE): háttér; fehér kártya;
    - halvány mandarin (#FFE2CC) és halvány arany (#FFF4C2): dobozok;
    - az oldal tinta- és szürke színei a szövegben.
  - Más oldalakon marad a régi.
  - Tesztek: 1807/0. Mintalevelek (foglalás, ajándék, egyéni ajánlat) helyben renderelve, rendben.

- **2026-11-19 – Óraoldalak telefonon: egy gomb az elején, egy a végén; árak oldal: két címke ki (oliviajoga.online 3.12.20).**
  Áron (telefonos fotó a kismamatorna oldalról):
  - „mobilon az egyik cta minden szolgáltatás aloldalon legyen az elején! a másik maradjon a végén.”;
  - „ezt a 2-t szedd ki a bejelentkezés és áraknál mert ott van az infó más módon”.
  - **Telefonon és tableten (1000 px alatt)** eddig mindkét gomb a lap alján volt (a szövegé és az oldalsáv kártyájáé).
    - Most a kártya gombja a fejlécben van, a leírás és a hossz alatt, sárga gombként, az első képernyőn: „Kérj időpontot”,
      a csoportos órán „Foglald le ezt az órát”.
    - A végén csak a szöveg gombja marad („Időpontot kérek” / „Óra foglalása”).
    - A kártya alul a szabályokkal (lemondás, visszanézés) megmarad, de második gomb nélkül.
    - Nagy kijelzőn minden a régi (oldalsó kártya a gombbal).
    - Ellenőrizve mind az 5 óraoldalon 360, 390, 768 és 1280 px-en.
  - **Bejelentkezés és árak:** az információs dobozból kikerült a „Szerdánként 18:30–20:00” és a „90 perc” címke (a nap és
    az idő az órarendben és a naptárban, a hossz az árkártyákon látszik); a többi címke marad.
  - Tesztek: 1810/0.

- **2026-11-20 – Search Console: miért nem indexelt a két oldal, és javítás (oliviajoga.hu 1.7.5).**
  Áron: „a Search Console-ba valamiért az aloldalakat nem sikerült a Google-nek indexáltatni, kérlek nézz utána, old meg”;
  „.hu-t is ellenőrizd”.
  - **oliviajoga.online (44 cím):** technikailag minden rendben, Googlebotként ellenőrizve:
    - 200-as válasz, nincs noindex vagy X-Robots-Tag;
    - a canonical saját magára mutat, a www/http/perjel nélküli cím 301-gyel átirányít;
    - a sitemapben lastmod is van; a válaszidő 0,3–0,4 mp; a főoldal HTML-jében minden aloldal linkje benne van.
    - Search Console (a CRM `pm/gsc-diag` URL-vizsgálatával): a sitemapet csak 11.19-én 18:22-kor küldtük be, a Google
      letöltötte (40 cím, 0 hiba).
    - 34 vizsgált címből 6 már indexelt (főoldal, Bejelentkezés és árak, Kapcsolat, Jógáról, Kismamatorna, egy cikk), a
      többit a Google még nem dolgozta fel. Új domainnél (egy hét) ez a szokásos menet; a Google most is aktívan jár rajta.
  - **oliviajoga.hu (32 cím): valódi hiba volt.**
    - A WordPress az ékezetes címeket kisbetűs kódolással írta a sitemapbe és néhány linkbe (/r%c3%b3lam), a canonical és a
      menü viszont nagybetűvel (/r%C3%B3lam), a Google is így indexeli őket.
    - A Search Console a sitemap címeit „ismeretlennek” látta: 28/32 ismeretlen, 3 felfedezett, de nem indexelt.
    - Javítás: `oj_upper_escapes` a .hu témában – az oldal- és cikklinkek, az adatkezelési link és a sitemap is a canonical
      nagybetűs írásmódját kapja.
    - Helyben ellenőrizve: 32 sitemap-cím, egy kisbetűs sem maradt. A .hu ellenőrzés: 2408/0.
  - **A .hu Search Console-jában hibás bejegyzés:** egy oldalt (`https://www.oliviajoga.hu/rolam`) sitemapként küldtek be
    (1 hiba), és a www-s sitemap is be van küldve. Ezt a felületen kell törölni (Sitemapek → a sor → Sitemap eltávolítása).
  - Utána: a sitemapek újraküldése a CRM-ből (`pm/gsc-manage`), és az „Indexelés kérése” a kulcsoldalakra a Search
    Console-ban (ezt az API nem tudja, csak kézzel).

- **2026-11-23 – „Jóga • Kismamatorna • Mobilizáció • Relaxáció és légzés” sor ki (oliviajoga.online 3.12.21).**
  Áron (képernyőkép a sorról): „ez szedjük ki”. A képen a cikkek alján, a szerzői dobozban lévő sor volt; ugyanez a sor a
  láblécben is ott volt a logó alatt. Az online kiadásban mindkét helyről kikerült (`oy_specialities()` üres, a sor nem
  íródik ki); más oldalakon marad. Tesztek: 1811/0.

- **2026-11-25 – Lábléc: a szakterület-sor vissza szövegként; favicon a találatokban (oliviajoga.online 3.12.22).**
  Áron: „footerben viszont maradjon szövegként az, amit legutóbb kivettünk” – a láblécben a címsor alatt újra ott a
  „Jóga • Kismamatorna • Mobilizáció • Relaxáció és légzés”, sima szövegként (`oy_specialities_text()`, `.footer__spec`);
  a cikkek szerzői dobozában és a főoldali névkártyán nincs. Áron: „mindenhol legyen favicon a találatokban” (a Google a
  WordPress-logót mutatta): külön 48 / 96 / 192 px-es ikonfájl, a fejlécben a 48 és 96 px-es sor is
  (`site_icon_meta_tags`), a `/favicon.ico` valódi .ico (16/32/48 px, 200-zal, nem átirányítás). A Google a favicont a
  főoldal újrajárásakor frissíti (napok–hetek). Tesztek: 1817/0.

- **2026-11-25 – oliviajoga.hu 1.8.0: ékezet nélküli címek, 11. kerület ki az altokból, favicon; mindkét oldal az
  .online-t mondja elsődlegesnek (MI-ajánlás) – oliviajoga.online 3.12.23.**
  - Áron: „minden aloldal url slug legyen ékezet nélkül, kötőjellel. a seo irányelveknek is feleljen meg minden”. Minden
    oldal új címe a régi ékezet nélkül (`build.ascii_slug`: /rólam → /rolam, /bejelentkezés-jóga-bérlet →
    /bejelentkezes-joga-berlet, /főoldal → /). A régi cím minden alakja (ékezettel, %C3/%c3, perjellel, query-vel) egy
    lépésben 301-gyel az újra (`oj_moved_target`, az adat `moved` térképe; a statikus változatban `.htaccess`); a setup a
    meglévő oldalakat átnevezi (`post_name`), a szerkesztetlenség-md5 vele költözik; ha Olívia kézzel szerkesztett egy oldalt,
    a benne maradt régi belső linkeket megjelenítéskor az újra írja (`oj_fill`). Canonical, menü, belső linkek, sitemap,
    breadcrumb, schema, llms.txt mind az új címekkel. A törölt oldal (jógaóra-cégeknek) mindkét alakja a főoldalra.
  - Áron: „a képek alt textjei maradjanak a régiek, csak olyanokat vegyünk ki, ami a 11. kerületre vonatkozik”: a régi
    altokból kikerül a 11. kerület, Újbuda, Gellért(-hegy), Bartók, Móricz, Mányoki, slow flow, Buda/budai, budai pk, BPK
    (`build.XI_WORDS`, `drop_xi`); minden más régi kulcsszó marad (a 18., 20., 21. kerület, Csepel is). check.py: 3469/0,
    `--after` 1219/0.
  - Áron: „mindenhol legyen favicon a találatokban” (a .hu-n a Google a WordPress-logót mutatta: az ikon csak beágyazott SVG
    volt, a /favicon.ico a WP logójára vitt): valódi fájlok a régi rajzzal (zöld kör, rózsaszín pötty) – SVG, 48/96/192 px
    PNG, 180 px apple-touch, `/favicon.ico` 200-zal (`oj_icon_links`, `do_faviconico`).
  - Áron (képernyőkép egy MI-válaszról, benne „Zenamu foglalási rendszer”): „A Kovács Olívia Jógastúdiójára a .online
    legyen linkelve, az az elsődleges oldal. a .hu csak a seo miatt él illetve amiatt, hogy a régi vendégek odataláljanak
    a .onlinera. A Budai Perinatális Központ csapatában is csak voltam, már nem, de múltba beleírhatjuk.” – .hu schema:
    a vállalkozás és Olívia címe az .online (`url`), a .hu csak `sameAs`; a .hu és az .online llms.txt-je kimondja: az
    .online az elsődleges oldal, foglalás csak ott, saját fiókkal, más foglalási rendszert nem használ; a BPK múlt időben.
    A Zenamu egyik oldalunkon sem szerepel (külső forrásból jön). Az .online-ra mutató .hu-linkek a mostani címekre
    (nincs 301-es kitérő). Online tesztek: 1818/0.
  - **1.8.1 (javítás, élesben észrevéve):** az élő oldalon a WordPress telepítéskor készített egy „Adatkezelési tájékoztató”
    vázlatot pont a /adatkezelesi-tajekoztato címen, ezért az átnevezés ott kimaradt, és a beállítás a vázlatra állt át
    (az új cím 404, a sütisáv a régi címre linkelt). Most egy nem közzétett oldal félreáll (`…-wp-vazlat`), az adatvédelmi
    oldal csak közzétett lehet, a sütisáv tartalék címe ékezet nélküli. Helyben az élő helyzettel kipróbálva.

- **2026-11-26 – Online lábléc: a címsor ki (oliviajoga.online 3.12.24).** Áron: „.online oldalon most jó a footer csak
  ezt szedd ki” (képernyőkép: „Jógaoktató | Pre- és postnatális tréner | Mozgás- és wellness coach”). Az online lábléc
  logó, mottó, „Jóga • Kismamatorna • Mobilizáció • Relaxáció és légzés”, gomb; a többi oldal láblécében marad a címsor.
  Tesztek: 1819/0.

- **2026-11-26 – oliviajoga.hu 1.8.2: a főoldalon a kismamatorna-doboz helyén a privát jóga árai.** Áron (képernyőkép a
  „Kismamatorna online, egyénileg” dobozról): „a .hu-n ennek a helyére a főoldalon inkább a privát jóga árait tegyük be”.
  „Privát online jóga” / „Privát jóga online, egyénileg”, egyéni időpontban, 60 vagy 90 perc; 1 alkalom és 5 alkalmas bérlet
  egyedül és párban (8 000 / 35 000, 10 000 / 43 000, 12 000 / 52 000, 15 000 / 65 000 Ft – az .online árai,
  `ONLINE_SERVICES`, `private_prices()`); gomb: az .online privát jóga oldala. 390 és 1280 px-en nincs túlcsordulás.
  check.py: 3469/0, `--after` 1219/0.

- **2026-11-26 – oliviajoga.hu 1.8.3: a főoldali meghívó az online csoportos órára.** Áron (képernyőkép: „…várlak
  szeretettel a 11. kerületi, 18. kerületi és 21. kerületi csoportos jóga órákon!”): „ide pedig ne a kerületeket írjuk,
  hanem hogy online, csoportos jóga órákon és a .online csoportos jóga aloldalára vigyen a szöveg” → „…várlak szeretettel
  az online, csoportos jóga órákon!”, a link az .online csoportos flow jóga oldalára, új lapon (`OLD_INVITE`,
  `fix_links`). A két új fotó (miért jóga, gyakoroljunk) altjából is kikerült a 11. kerület / Újbuda / Móricz; check.py
  most minden kép altját nézi. check.py: 3735/0, `--after` 1217/0.

- **2026-11-26 – oliviajoga.hu 1.8.4: az oldalak alján (előlábléc) is a privát jóga.** Áron (képernyőkép az „Online
  kismamatorna – egyéni időpontban (60 perc)” dobozról): „ide is inkább a privát jógát tegyük, mellé bal oldalra pedig azt
  írjuk címnek, hogy Online csoportos flow jóga” → bal: „Online csoportos flow jóga” (szerda 18:30 – 20:00, 90 perc,
  „Online jóga bejelentkezés”), jobb: „Privát online jóga” (egyéni időpontban, 60 vagy 90 perc, gomb az .online privát
  jóga oldalára). check.py: 3735/0, `--after` 1217/0.

- **2026-11-26 – Képek: kulcsszavas fájlnevek és leíró, kulcsszavas altok mindkét oldalon (oliviajoga.hu 1.8.5,
  oliviajoga.online 3.12.25).** Áron: „A kép alt textek is legyenek rendben mindkét oldalon (kép fájlnevek tartalmazzák a
  szükséges kulcsszavakat ékezet nélkül, kötőjellel, az alt textek pedig szintén tartalmazzanak kulcsszavakat ékezetesen,
  spaceszel, a képet is leírva!” – és: „Csináld meg te az indexeléseket és a sitemapet.”
  - .hu: 86 kép új néven (160 fájl, `git mv`): az alt szövegéből ASCII, kötőjeles név (`tools/image_names.py`,
    `content/image-names.json`; pl. old/18bbeec02e8a → old/alkalmas-joga-ajandekutalvany-nyomtatott-kartyan-csepel-18-kerulet,
    new/korszak-01 → new/csoportos-jogaora-18-kerulet-pestszentlorincen-prana-jogakozpontban). A build belül a régi
    kulcsokkal dolgozik, a kiírt cím az új (`img_rel` → `asset`). Az élő szerveren a régi nevű fotók megvannak: a setup
    helyben átmásolja őket az új nevekre (nem tölt le, nincs törött kép), és az adminban beállított képhelyek
    (`oj_img_pos`) is átkerülnek az új kulcsokra (`img_renamed`). Altok: ahol csak leírás vagy csak kulcsszó volt
    (korszak-*, díszítő fotók, hero, miért jóga, gyakoroljunk, privát jóga, cikk-munka, téma-fotók), most mindkettő
    („mit mutat – kulcsszavak”). check.py: minden kép neve kulcsszavas, ékezet nélküli, minden alt ≥ 5 szó és van benne
    kulcsszó; 4215/0, `--after` 1217/0; helyben 161 kép tölt be, 0 törött.
  - .online: a pajzsmirigy-cikk 14 pózfotója új néven (`halpoz-matszjaszana-joga-pajzsmirigy-kovacs-olivia-600.jpg`,
    `oy_pose_file`), alt: „Halpóz (Matszjászana) – Kovács Olívia mutatja be, jógaászana pajzsmirigy-túlműködésre”. A
    médiatárba írt vékonyabb alt („Kovács Olívia - online jóga otthonról”), ha minden szava benne van a leíróban, átadja a
    helyét a leírónak; akinek saját szavai vannak, az marad. A többi .online kép neve és altja már rendben volt. Tesztek:
    1821/0. **3.12.26 / .hu 1.8.6:** ugyanez a szabály az oldalszerkesztőben (Olívia által cserélt kép: `oye_thinner`) – a
    „Bejelentkezés és árak” fejlécképén még a vékony alt látszott. Élesben ellenőrizve: a két oldalon 727 kép, mind
    kulcsszavas néven, ≥ 5 szavas alttal; a .hu 163 képe betölt, 0 törött. Tesztek: 1822/0.
  - Indexelés: a Google „Indexelés kérése” gombja API-n nem érhető el (az Indexing API csak álláshirdetésre és élő
    közvetítésre való), ezt csak a Search Console felületén lehet. Amit lehet: sitemapek újraküldve (CRM `pm/gsc-manage`),
    a .hu IndexNow-n beküldte mind a 33 új címet (Bing, Yandex). A hibás `www.oliviajoga.hu/rolam` sitemap-bejegyzést a
    CRM nem tudja törölni (ehhez a hub-bővítmény kiadása kellene) – az indexelést nem zavarja.

- **2026-11-26 – oliviajoga.hu 1.8.7: a pajzsmirigy-cikk olyan, mint az .online-on.** Áron: „A .hu-n a pajzsmirigy
  harmonizálás aloldal legyen ugyanolyan mint az oliviajoga.online-on.” A fejlécben ugyanaz a bevezető; az első három
  bekezdés után a 14 pózkártya két csoportban („Ha túlműködik / alulműködik a pajzsmirigy”, Olívia fotóival, ugyanazokkal a
  kulcsszavas fájlnevekkel és altokkal, mint az .online-on – `POSE_SETS`, `poses_html`, a fotók a téma `img/theme/poses`
  mappájában); a két tipp listában; a végén az orvosi megjegyzés. A régi ászana-grafika és a „ne erőlködj” mondat kikerült,
  ahogy az .online-on is (`REMOVED_FILES`, `REPLACED_TEXT_START`). Helyben 390 és 1280 px: 14 kártya, 0 törött kép, nincs
  túlcsordulás. check.py: 4222/0, `--after` 1217/0.

## Következő kör – nyitott pontok / ötletek
- Admin felület magyarul (fordítási fájl), ha Olivia így kényelmesebb (a látogatói felület már 3 nyelvű).
- Tagság: csomagváltás (upgrade/downgrade), szüneteltetés, próbaidőszak – ha kell.
- Valódi Stripe teszt-fiókkal végigpróbálni (a fejlesztői környezetből a Stripe nem érhető el, szimulátorral tesztelt).
- Főoldal szövegeinek szerkeszthetővé tétele a Customizerben.
- Ügyfél-visszajelzés a színarányokra (mennyi pink / lila / barack).
- Mobil finomhangolás (hero, órarend, árkártyák).
- Élesítés előtt továbbra is: SITE_URL, foglalási rendszer, valós órarend és árak (most MINTA adatok).
