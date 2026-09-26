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

## Következő kör – nyitott pontok / ötletek
- Admin felület magyarul (fordítási fájl), ha Olivia így kényelmesebb.
- Tagság: csomagváltás (upgrade/downgrade), szüneteltetés, próbaidőszak – ha kell.
- Valódi Stripe teszt-fiókkal végigpróbálni (a fejlesztői környezetből a Stripe nem érhető el, szimulátorral tesztelt).
- Főoldal szövegeinek szerkeszthetővé tétele a Customizerben.
- Ügyfél-visszajelzés a színarányokra (mennyi pink / lila / barack).
- Hero: címsor mérete, a matrica és a névkártya elhelyezése.
- Mobil finomhangolás (hero, órarend, árkártyák).
- Élesítés előtt továbbra is: SITE_URL, foglalási rendszer, valós órarend és árak (most MINTA adatok).
