Olivia Kovács Yoga – HTML verzió, a teljes fotócsomaggal

site/   Kész statikus oldal (19 oldal). Tesztelés: cd site && python3 -m http.server 8000
        Képek: site/assets/img (600 és 1200 px széles változat, srcset + lazy loading).
src/    Generátor: cd src && python3 build.py  (a site/ és a preview.html a projekt gyökerébe kerül)
        Képek kiosztása és alt szövegei: src/img/images.json + a build.py elején
        (CLASS_PHOTO, CLASS_ASIDE, POST_PHOTO). Új kép: tedd az src/img-be {név}-600.jpg / -1200.jpg néven.

Élesítés előtt: SITE_URL (build.py), foglalási rendszer, órarend, árak (MINTA adatok).

Dizájn: "Urban flow" (2. kör)
  Zöldek megtartva (#2B5036 erdő, #1B3324 moha, zsálya, halvány zöld) + új akcentek:
  lila #C6A3EE, pink #FF72B6 / #D92B86, barack #FF8C42.
  Betűk: Anton (plakát-címek, nagybetűs) + Archivo (szöveg, széles változat a címkékhez).
  Színek és elemek: src/site.css eleje (:root), színrotáció: ACCENTS a build.py-ban.
