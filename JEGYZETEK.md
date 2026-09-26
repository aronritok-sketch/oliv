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
- Tesztelve helyben (WordPress 6.8.3 + MariaDB + Stripe-szimulátor): `wordpress/dev/e2e.js`, 30/30 ellenőrzés sikeres.
- Élesítéshez kell: tárhely, Stripe fiók + kulcsok + webhook, SMTP, cron, valós órarend/árak, ügyvéd a nyilatkozathoz.

## Következő kör – nyitott pontok / ötletek
- Admin felület magyarul (fordítási fájl), ha Olivia így kényelmesebb.
- Havi korlátlan tagság (Stripe előfizetés), ha kell.
- Főoldal szövegeinek szerkeszthetővé tétele a Customizerben.
- Ügyfél-visszajelzés a színarányokra (mennyi pink / lila / barack).
- Hero: címsor mérete, a matrica és a névkártya elhelyezése.
- Mobil finomhangolás (hero, órarend, árkártyák).
- Élesítés előtt továbbra is: SITE_URL, foglalási rendszer, valós órarend és árak (most MINTA adatok).
