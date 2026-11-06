# oliviajoga.hu – új frontend (Kovács Olívia, Jóga 18. és 21. kerület)

Olivia mostani magyar oldala egy Google Sites oldal (`oliviajoga.hu` → `sites.google.com/view/oliviajoga`), ami jól
szerepel a keresőben. Ez a mappa ugyanezt az oldalt adja új külsővel, az új Olivia Yoga dizájnban
(`wordpress/olivia-yoga`: színek, Anton + Quicksand, komponensek). Áron kérése (2026-11-05): „a meglévő link
struktúrát, SEO oldalakat és mindent megtartasz … minden úgy legyen elnevezve, hogy ott van, szövegezés ugyanaz
legyen, csak egy új UX-et kapjon”.

## Mi marad változatlan (SEO)

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
