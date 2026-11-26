# oliviajoga.hu – új frontend (Kovács Olívia, Jóga 18. és 21. kerület)

Olivia mostani magyar oldala egy Google Sites oldal (`oliviajoga.hu` → `sites.google.com/view/oliviajoga`), ami jól
szerepel a keresőben. Ez a mappa ugyanezt az oldalt adja új külsővel, az új Olivia Yoga dizájnban
(`wordpress/olivia-yoga`: színek, Anton + Quicksand, komponensek). Áron kérése (2026-11-05): „a meglévő link
struktúrát, SEO oldalakat és mindent megtartasz … minden úgy legyen elnevezve, hogy ott van, szövegezés ugyanaz
legyen, csak egy új UX-et kapjon”.

## Mi marad változatlan (SEO)

> **2026-11-25 (1.8.0), Áron:** „minden aloldal url slug legyen ékezet nélkül, kötőjellel” – a címek azóta ékezet nélküliek
> (`/rolam`, `/arak`, `/jogarol` …, `build.ascii_slug`); a régi ékezetes címek 301-gyel az újakra (a WordPress-témában
> `oj_moved_target`, a statikus változatban `.htaccess`). Az alább „ugyanazon az útvonalon” leírt állapot ez előtt volt így.
>
> **2026-11-26 (1.8.5): a képek fájlnevei kulcsszavasak** (Áron): `tools/image_names.py dist <after-build> [--apply]` az
> alt szövegből ad ASCII, kötőjeles nevet (`content/image-names.json`), és átnevezi a fájlokat. Új képnél: build, aztán ez
> a parancs `--apply`-jal (a meglévő nevek nem változnak). A build belül a régi kulcsokkal dolgozik (`old/<hash>`,
> `new/korszak-01`), a kiírt cím az új.

- **Címek:** mind a 33 oldal ugyanazon az útvonalon: `/rólam`, `/árak`, `/események`, `/jógáról`, `/jógaóra-cégeknek`,
  `/bejelentkezés-jóga-bérlet`, `/kapcsolat`, `/főoldal` és a 24 írás (`/a-nyújtás-hatása`, `/binge` …). Perjel nélkül, ahogy
  a Google Sites-on (a `.htaccess` átirányítás nélkül szolgálja ki a `rólam/index.html`-t).
- **`<title>`:** szó szerint a régi („Jóga 18. és 21. kerület | Kovács Olívia - Rólam”).
- **Szöveg:** minden címsor, bekezdés, lista és gomb szövege a régi, a címsorok szintje (h1/h2/h3) is; a kinézetet osztály
  adja, nem a tag. A régi elírásokat sem javítottuk.
- **Képek:** Olivia régi fotói a régi alt szövegekkel (a kulcsszavas altok is: „jóga 21. kerület jóga csepel …”).
- **Linkek:** Motibro (bejelentkezés, bérlet, ajándékutalvány), a Google Forms űrlapok, a térképek és a Motibro
  órarend-widget ugyanazok.

## Mi új

- Új UX a téma komponenseivel: hero, helyszínkártyák heti időpontokkal, GYIK-lenyitók, árkártyák, eseménynapló évenként,
  írások kártyákon, a végén „További írások”, mobilon lebegő „Bejelentkezés” gomb.
- Ami a régi oldalon **kép volt szöveggel**, most valódi szöveg: az árkártyák (1 alkalom 3000 Ft / 5 alkalom 14000 Ft,
  60 perc; 5000 Ft / 23000 Ft, 90 perc), a „Ti írtátok” vélemények és az „Ami nem szükséges / Ami szükséges” grafika
  (`build.py`: `PRICES`, `REVIEWS`, `NEEDS`). Kihagyva csak a dísz-elválasztók és az „Órák november 25-ig!” kép
  (`DROPPED`), mert a szövege ott van mellette.
- SEO-plusz: meta description (az oldal első bekezdéséből), canonical, Open Graph, JSON-LD (WebSite, Person,
  BlogPosting), `sitemap.xml`, `robots.txt`, 404 oldal, gyors statikus oldal.

## 3. kör: Olivia jegyzetei a meglévő dizájnon belül (2026-11-06)

A 2. kör (szó szerinti megvalósítás új logóval, új betűvel, sok új elemmel) Áron szerint rosszabb lett, visszavonva.
A 3. kör az 1. kör dizájnját tartja meg, és azon belül oldja meg Olivia meglátásait – a brief és a pontonkénti
megoldás: **`BRIEF.md`** (mesterek: Tschichold / Hey + Headspace / Dumbar; a lélegző „JÓGA” kiemelés).

- A szándékos törléseket a `build.py` sorolja fel (`dropped_section`, `REMOVED_FILES`, `REMOVED_EMBEDS`,
  `DROPPED_LENGTHS`); a `tools/check.py` ezeket kihagyja, minden más régi szöveg, kép-alt és cím ellenőrzött.
- Olivia Drive-fotói: `assets/img/new/` (`tools/images.py import`), az írásképek kivágása:
  `tools/images.py cutouts` (`content/cutouts.json`).
- Logó: Olivia a B változatot választotta, levélke nélkül, más hullámmal: `tools/logo.py <fonts>` →
  `assets/brand/logo.svg` (currentColor + orchidea hullám).

## 4. kör: Olivia üzenetei a CRM-chatben (2026-11-06, 1.2.0)

- `DELETED_PAGES` (`jógaóra-cégeknek`): nincs oldal, menü és sitemap; élesben 301 a főoldalra (`.htaccess`).
- Hullám a képek mögött (`wave()` a `build.py`-ban): a hősben sáv (`band=True`), máshol a végein elvékonyodó szalag.
- GYIK, Rólam: számok helyett hullámjel (`WAVE_MARK`); fizetési logók: `assets/img/barion-fizetes.svg` (Barion hivatalos sáv).
- Menüoldalak fejléce: a kép kitölti a sávot (≥ 900 px jobbra, hullámos széllel; alatta teljes szélességben).
- Új fotók: `IMG_6232.jpg` → `miert-joga`, `kovacs-olivia-hatha-joga.jpeg` → `gyakoroljunk` (`tools/images.py import`).
- 1.3.0: cikk-kártyák Olivia teljes fotóival (`tools/images.py cards <mappa>` → `assets/img/new/cikk-*`, a régi alt marad –
  `tools/check.py` ezt nézi); egységes kártyacím (`card_titles`); Kapcsolat-dobozok alja egy vonalban (`.oj-loc__foot`);
  hullámok: `blob()` (a kép oldalán kifut a képernyő szélére) és `band()` (teljes szélességű sáv a képsor mögött).

## 5. kör: az oliviajoga.hu redesign doksi (2026-11-12, 1.4.0)

Olivia doksija két részből áll: amit most kért, és amit **november 26-tól** (az utolsó helyszíni óra után).

- **Most:** zöld hős-gombok (`btn--forest`); ahol „november 25-ig tartom”, ott az élő online jóga is (szerdánként
  18:30–20:00) gombokkal a www.oliviajoga.online-ra (`online_words()`, `online_buttons()`, értesítősáv); minden hullám a
  logó ecsetvonása (`brush_d()` → `blob()`, `band()`, `WAVE_MARK`, a fejlécfotó széle); ONLINE ajándékutalvány az online
  oldal ajándék-checkoutjára (`online('gift')`); „Egy korszak lezárult” szekció a 18 fotóból (`korszak_section()`,
  `tools/images.py korszak <mappa>` → `assets/img/new/korszak-*`, alt: `content/korszak-alts.json`); az előlábléc
  mondata kiegészítve (`YOGIC_LIFE`); Rólam: még egy fotó az utolsó fejezetek mellett; a cikkek fejléce Olivia nyers
  fotója, a színes sávot alul-felül kitöltve.
- **November 26-tól** (`AFTER`, `build.py --after`; a WordPress-téma magától vált a dátumon, Budapest ideje szerint;
  előnézet: `?nov26=1`, vissza: `?nov26=0`): „tartottam”; órarend és térképek helyett a két online doboz (online jóga,
  online kismamatorna – idő, ár, gomb; `online_boxes()`), a helyszínek szavai megmaradnak (`was_strip()`); Árak:
  90 perces élő online jóga (3 000 / 13 000 Ft, 8 hét, lemondáskor kitolódik) és külön a kismamatorna (`prices_after()`);
  menü: „Bejelentkezés & jóga bérlet” → az online fiók, új „Online kismamatorna”; minden foglaló gomb az online oldalra,
  a kerületes gombfelirat „Online jóga bejelentkezés” (`after_href()`, `after_label()`); GYIK két válasza
  (`FAQ_AFTER`); a Barion-sáv helyett a Stripe fizetési módjainak logói (`assets/img/pay/`, MIT, Shopify
  payment_icons).
- **Online oldal címe:** `ONLINE` (élesben www.oliviajoga.online); a WordPress-témában `%%ONLINE%%`, a dev szerveren a
  foglaló dev-másolata, `OJ_ONLINE_URL` felülírja.
- **Ellenőrzés:** `tools/check.py <mappa> --after` (címek, régi címsorok, helyszín-szavak, nincs Motibro, térkép,
  Barion és „tartom”).
- **1.4.1 (a doksi kiegészítése):** az előlábléc őszibarack (`oj-pre--peach`, `--sun-soft`); az Árak alcíme „élő online,
  csoportos hatha flow jóga órák / és egyéni, privát online kismamatorna” (`PRICES_SUB`; a helyszín-chipek november
  26-án tűnnek el); telefonon a nyitott menü alatt az értesítősáv rejtve (a „Főoldal” nem csúszik a fejléc alá).
- **1.4.3 (Olivia Áronon át, 2026-11-12):** a fejléc fotója mögött újra az 1.3.0-s puha rózsaszín hullám („nem a gomb,
  hanem a háttér”; a gombok zöldek maradnak; `blob_soft`). Az „Egy korszak lezárult” rács mozog („mozogjon és legyen
  menő”, `assets/oliviajoga.js`): a fotók egymás után beúsznak és lassan lebegnek, az ecsetvonás kirajzolódik és
  folyamatosan hullámzik rajtuk, egyszerre egy fotó kiszíneződik orchidea kerettel (és amelyik fölött az egér van);
  csak amíg látszik, csökkentett mozgásnál semmi.
- **1.4.4 – SEO (Áron, 2026-11-12: „minden SEO követelménynek feleljen meg … ne veszítsen semmit”):** minden régi
  képnek valódi leírás az alt-ban, utána a régi alt kulcsszavai rendezve (`content/alt-descriptions.json`,
  `better_alt`; a check.py ellenőrzi, hogy egyetlen régi kulcsszó sem veszett el); oldalanként pontosan egy H1 (a régi
  tartalom további h1-jei `h2.oj-was-h1`, ugyanakkora); og:image minden oldalon (statikus és WordPress); a két rövid
  meta-leírás (Kapcsolat, Mit ad a jóga?) megírva; WordPressben nincs users-sitemap és szerzői oldal, a magyar
  „Helló Világ!” / „Ez egy minta oldal” is törlődik. A címek (title) a régi oldaléi maradnak (pozíció).
- **Képek helye (Áron):** WordPress → Megjelenés → Képek helye (`wp-theme/inc/image-position.php`): fotónként egy pont,
  aminek látszania kell; a vágás a dizájné marad. `oj_img_pos` opció, kulcs: a fájl útja méret nélkül (`new/hero`).
- **Facebook-képek:** a „Facebookra” mappa 18 fotója 1080 × 1350-ben, az oldal stílusában (`tools/facebook/`:
  `make_html.py` + `shoot.js`, Playwright); a demón: `/demo/oliviajoga-hu-6083/facebook/`. 2026-11-13: újragyártva
  web-cím nélkül (Olivia kérése), a kétszer szereplő IMG_5438 nélkül 17 kép.
- **Facebook-borító „online jóga”** (Áron, 2026-11-13): `tools/facebook/cover.py` + `cover.js` – oldal 1640 × 624
  (a telefon a középső 16:9-et mutatja, a szöveg és a fotó ott van), csoport 1640 × 856; az oliviajoga.online
  stílusában. A képek + borítók + posztszöveg zipben Olivia portálján (CRM-chat, 2026-11-13).
- **Olivia második kérése (portál, 2026-11-13 18:12–18:40):** a Facebook-képek teljes egészükben, csak a hullámmal,
  felirat nélkül (`tools/facebook/wave_only.py`, a fotó saját arányában, 2048 px), és **online órarend** a régi
  ORAREND.png mintájára (`tools/facebook/orarend.py`, 1080 × 1920: „online jóga ÓRAREND”, szerda 18.30–20.00 hatha flow,
  privát jóga és privát kismamatorna egyeztetett időpontban, „Bejelentkezés: oliviajoga.online”). Zipben a portálon.
- **Órarend, 2. kör (Olivia, 19:07):** „ugyanaz a kép legyen a háttér … inkább csinálj te hasonlót”. A nyers fotó
  nincs meg (Drive, portál, doksi), ezért a háttér az ő ORAREND.png-je a régi feliratok nélkül
  (`tools/facebook/orarend_bg.py`: betű-maszk + árnyék, kitöltés a környezetből; numpy + opencv-python-headless), a
  rózsaszín hullámos fátyol marad. Az új szöveg az ő méreteire, az ő betűivel (`orarend.py`, 1151 × 2048 → JPG
  1080 × 1920). Ha Olivia átküldi a nyers fotót, a háttér abból is készülhet.

- **1.5.2 (Olivia 11.15 és hu-11.12 doksi, 2026-11-16):**
  - Az Árak alcíme a csoportos órán túl minden új szolgáltatást felsorol (`PRICES_SUB`): egyéni online jóga, online
    kismamatorna, stretching és mobilizáció, relaxáció és légzés.
  - Az Árak oldalon „Egyéni online órák” rész, lenyílókban az oliviajoga.online árai (`ONLINE_SERVICES`,
    `services_html()`), „Időpontot kérek” (az online fiók kérés-űrlapja) és „Bővebben az óráról” gombbal. Most a
    csoportos árak alatt, november 26-tól a kismama-kártyák helyén.
  - A FONTOS! doboz címe a szöveggel egy vonalban, felül.
  - „Egy korszak lezárul” november 25-ig, utána „lezárult”, alatta a szolgáltatások sora.
  - November 26-tól (`AFTER`):
    - új menüpont az „Online órák” (→ /online-orak/);
    - a „Bejelentkezés & jóga bérlet” → oliviajoga.online/bejelentkezes-es-arak/;
    - a „Kapcsolat” → oliviajoga.online/kapcsolat/ (menü, lábléc, WordPress-átirányítás);
    - minden Motibro-link → az online főoldal.
  - A statikus Kapcsolat-oldal (tartalék) a mostani két helyszínes dobozt tartja meg:
    - a kerület, Prána és Korall marad, „tartottam” szöveggel;
    - szerda 18.30–20.00 és egyéni időpont az órarend helyett;
    - térkép nélkül, a gombok az online oldalra.

## Fájlok

| | |
|---|---|
| `content/pages.json` | a régi oldal tartalma oldalanként → szekció → oszlop → elem (szöveg, kép, gomb, beágyazás) |
| `assets/img/old/` | a régi fotók, 800 és 1600 px (webp, ahol átlátszó) |
| `assets/oliviajoga.css` | az új komponensek; a téma `site.css`-e és `site.js`-e mellé |
| `build.py` | a statikus oldal (`dist/`) |
| `tools/extract.py`, `tools/grab-old-site.js` | a régi oldal kinyerése |
| `tools/check.py` | ellenőrzés: minden cím, title, szöveg, kép-alt, beágyazás és belső link megvan |

## Használat

```bash
python3 build.py                                    # dist/ az oliviajoga.hu-ra (canonical, sitemap, .htaccess)
python3 tools/check.py dist                         # 2355 ellenőrzés
python3 build.py --preview /demo/<slug>/ --out D    # noindex előnézet alkönyvtárba (a linkek /-re végződnek)
python3 build.py --after --preview /demo/<slug>/nov26/ --online <online oldal> --out D/nov26   # a november 26-i állapot
python3 tools/check.py D/nov26 --base /demo/<slug>/nov26/ --after
```

A régi oldal újraolvasása (ha Olivia ott módosít): a Google a képek címét látogatásonként aláírja, ezért az oldalakat és
a képeket ugyanabban a böngészős látogatásban kell menteni:

```bash
NODE_PATH=… node tools/grab-old-site.js urls.txt <mappa>          # urls.txt: a régi oldal címei soronként
python3 -I tools/extract.py <mappa>/dom --images <mappa>
```

## Élesítés (nyitott)

1. **Tárhely:** az `oliviajoga.hu` ma az ELIN.hu-n van, és 301-gyel a Google Sites-ra mutat. A `dist/` tartalmát kell a
   domain gyökerébe tenni (Apache: a `.htaccess` kész; nginx: `try_files $uri $uri/index.html =404;`), a 301-et levenni.
2. **Search Console:** az `oliviajoga.hu` tulajdon, a `sitemap.xml` beküldése.
3. **A régi Google Site:** 301-et nem tud. Átmenetileg maradhat, minden oldala tetején egy linkkel az új címre, aztán
   visszavonható a közzététele. A rangsor a sites.google.com-os címeken van; az új oldalon ugyanazok az útvonalak,
   címek és szövegek segítik, hogy a Google gyorsan átvegye.
4. Házszabály szerint ügyféloldal WordPress (`olivia-yoga` téma): jóváhagyás után átvihető egy harmadik kiadásként
   ugyanezekkel a címekkel; addig ez a statikus változat is élesíthető.
