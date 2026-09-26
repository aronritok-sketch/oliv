# Olivia Kovács Yoga – WordPress (téma + foglalás és fizetés)

Két rész, mindkettő a WordPress adminban tölthető fel ZIP-ként (`dist/`):

| Csomag | Mi ez |
|---|---|
| `olivia-yoga` (téma) | Az „Urban flow” dizájn: főoldal, aloldalak, óratípusok, napló, GYIK, kapcsolat. Egy kattintásos tartalombetöltővel. |
| `olivia-studio` (plugin) | Online foglalás vendégekkel, fizetés (Stripe), bérletek, havi tagság (előfizetés), várólista, magánórák, ajándékkártya, ügyfélfiók, e-mailek, admin. |

## Mit tud

**Ügyfélnek**
- Órarend (heti ismétlődő órák + egyedi események), szabad helyek száma, „Book” gomb.
- Foglalás: bérletből 1 kattintással · tagsággal · egyedi óra kártyával (drop-in) · „vegyél bérletet és foglald le ezt az órát” egy lépésben · bevezető ajánlat új diákoknak (alapból kikapcsolva).
- **Vendégek hozása:** foglaláskor (vagy utólag) több vendég is (alapból max. 4, beállítható). Bérletnél fejenként 1 alkalom vonódik le a saját bérletből; kártyánál egy fizetésben fizeti ki mindenkit, a Stripe-számlán külön sorban „Guest ticket × N”. A vendégek név szerint szerepelnek a névsoron, a visszaigazolásban, az emlékeztetőben; ha van e-mailjük, saját meghívót kapnak naptármelléklettel. Vendég külön is lemondható; ha a foglaló lemond, a vendégei is.
- **Online órák:** saját, alacsonyabb ár (alap $6), online bérlet (minta: 10 óra $50). A stúdióbérletből 1 alkalom = 4 online óra (állítható): az első online foglaláskor 1 alkalom átváltódik 4 online órára, a maradék a fiókban marad. A tagság tartalmazza az online órákat, és nem számítanak a havi keretbe. Online magánóra olcsóbban (minta: 60 perc $65).
- **Félbehagyott fizetés:** ha valaki elindítja a kártyás fizetést, de nem fejezi be, nem számít foglaltnak; az óra oldalán folytathatja a fizetést, vagy újra foglalhat (a régi Stripe fizetés automatikusan lezárul, dupla terhelés nincs).
- **Havi tagság (Stripe előfizetés):** korlátlan vagy „havi X óra” csomag, automatikus megújítással. A tag egy kattintással foglal, a fiókjában látja a keretet és a következő terhelést, a Stripe ügyfélportálon kártyát cserél és számlát tölt le, lemondhat a periódus végére (és visszavonhatja). Sikertelen terhelésnél e-mail; ha véget ér a tagság, a jövőbeli foglalásai lemondódnak.
- Fizetés Stripe Checkouttal (kártya, Apple Pay, Google Pay, Link) – a kártyaadat sosem jár a honlapon.
- Fizetés közben a hely 30 percig foglalva van; ha a vendég visszalép, azonnal felszabadul.
- Telt ház → várólista. Ha felszabadul egy hely: aki bérlettel vár, **automatikusan bekerül** (és e-mailt kap), a többiek értesítést kapnak.
- Lemondás a fiókból: határidőn belül a bérletes alkalom visszajár, kártyás drop-in helyett óra-kredit jár; határidő után az alkalom elhasználtnak számít (szabály és szöveg a beállításokban).
- Magánóra: kérés (hely, hossz, időpontok, megjegyzés) → Olivia ajánlatot küld konkrét időponttal és árral → az ügyfél egy kattintással fizet (vagy magánóra-kredittel).
- Ajándékkártya: vásárlás → a megajándékozott e-mailben kódot kap → a fiókjában beváltja, a bérlet jóváíródik.
- Ügyfélfiók: közelgő foglalások vendégekkel (naptárba tétel, lemondás, vendég hozzáadása/eltávolítása, online link), bérletek és egyenleg, tagság, magánórák, előzmények, fizetések nyugtával, profil (telefon, vészhelyzeti kontakt, egészségügyi megjegyzés), jelszó.
- Részvételi nyilatkozat (waiver) elfogadása regisztrációkor; új verziónál újra kéri.
- E-mailek arculattal: visszaigazolás .ics naptármelléklettel, emlékeztető 24 órával előtte, lemondás, várólista, bérlet, ajándék, magánóra-ajánlat, üdvözlő levél.

**Oliviának (wp-admin → Studio)**
- Ma: bevétel (30 nap), telítettség (7 nap), aktív tagok és havi ismétlődő bevétel (MRR), új magánóra-kérések, mai jelenléti listák (vendégekkel).
- **Naptár** (Studio → Calendar): heti nézet, telefonon napi. Üres időpontra kattintva új óra, órára kattintva szerkesztés, húzással áthelyezés, az alján húzva hosszabbítás. „Minden héten” ismétlés; heti óránál választható, hogy csak az adott napot vagy a következő heteket is módosítja; a bejelentkezettek e-mailt kaphatnak az új időpontról. Személyes / online kapcsoló.
- Órarend és névsorok: jelenlét (itt volt / nem jött), valaki hozzáadása (bérletből, készpénz, ajándék), foglalás lemondása, egész óra lemondása (mindenki e-mailt kap, kreditet visszakap).
- Heti órarend (sablonok) – ebből hetekre előre automatikusan készülnek az alkalmak.
- Magánóra-kérések: ajánlat küldése / elutasítás.
- Tagok: állapot, keret, fizetési problémák; lemondás a periódus végére / visszavonás / azonnali megszüntetés.
- Ügyfelek: profil, tagság, egészségügyi megjegyzés, nyilatkozat, bérletek (módosítás, kredit adása), foglalások, fizetések.
- Fizetések: visszatérítés részben vagy egészben (a Stripe-ban indított visszatérítést is átveszi).
- Ajándékkártyák, Árak és bérletek, Beállítások (Stripe, szabályok, nyilatkozat, e-mail).
- „Studio manager” szerepkör: más is kezelheti a stúdiót teljes admin jog nélkül.
- Belépés-védelem: 6 hibás jelszó után 15 perc tiltás (IP és fiók), regisztráció-korlát IP-nként.

## Telepítés

1. WordPress 6.4+ és PHP 8.1+, HTTPS-sel. Időzóna: *Beállítások → Általános → America/New_York*.
2. **Bővítmények → Új → Feltöltés:** `dist/olivia-studio.zip` → Bekapcsolás.
   (Létrehozza a táblákat és a *Book*, *My account*, *Gift cards* oldalakat.)
3. **Megjelenés → Témák → Feltöltés:** `dist/olivia-yoga.zip` → Bekapcsolás.
4. **Megjelenés → Olivia setup → „Set up the site content”.** Betölti az oldalakat, óratípusokat, GYIK-et, cikkeket, képeket a médiatárba, a menüt és a heti órarendet. Többször is futtatható, meglévőt nem ír felül.
5. **Beállítások → Közvetlen hivatkozások:** „Bejegyzés neve”.
6. **E-mail:** telepíts egy SMTP-bővítményt (pl. *WP Mail SMTP*, *FluentSMTP*) és egy tranzakciós levelezőt (Postmark, Brevo, Amazon SES), különben a levelek spambe mennek.
7. **Cron:** a tárhelyen állíts be valódi cront 5 percenként: `wget -q -O - https://DOMAIN/wp-cron.php?doing_wp_cron` és a `wp-config.php`-ba `define( 'DISABLE_WP_CRON', true );` – így a helyfoglalások lejárata és az emlékeztetők pontosan futnak.

## Stripe beállítása

1. Stripe fiók (Olivia nevére, amerikai cég vagy egyéni vállalkozó adataival), bankszámla a kifizetésekhez.
2. *Developers → API keys:* a **Secret key** (vagy korlátozott „restricted key”) → *Studio → Settings*. Tesztnél `sk_test_…`, élesben `sk_live_…`.
   Biztonságosabb: `wp-config.php`-ba `define( 'OYS_STRIPE_SECRET_KEY', 'sk_live_…' );`
3. *Developers → Webhooks → Add endpoint:* URL a Settings oldalon látható (`https://DOMAIN/wp-json/oys/v1/stripe-webhook`), események:
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`,
   `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed` (az utolsó négy a tagsághoz kell).
   A „Signing secret” (`whsec_…`) → Settings (vagy `OYS_STRIPE_WEBHOOK_SECRET`).
4. *Settings → Billing → Customer portal:* bekapcsolás (kártyacsere, számlák, lemondás); ezt nyitja meg a tag a fiókjából.
5. *Settings → Payment methods:* Apple Pay / Google Pay / Link bekapcsolása; *Branding*: logó és színek (#2B5036); *Emails*: sikeres fizetés nyugta bekapcsolása.
6. Teszt: Test módban `4242 4242 4242 4242` kártyával végigfoglalni egy órát vendéggel, bérletet, tagságot, ajándékkártyát; a tagság megújítását a Stripe *Test clocks* funkciójával lehet kipróbálni. Utána **Mode: Live**.

## Élesítés előtti lista

- [ ] Valós órarend (*Studio → Calendar*), árak, bérlet-érvényesség, tagsági csomagok, online ár és átváltás (1 alkalom = hány online óra), vendég-limit (*Prices & passes, Settings*). A mostani adatok MINTA adatok (tagság: $119 korlátlan, $85 havi 4 óra).
- [ ] A minta esemény („Live-Music Slow Flow…”) törlése vagy valódira cserélése.
- [ ] Részvételi nyilatkozat és lemondási szabály szövegét ügyvéd nézze át (*Studio → Settings*).
- [ ] Adatvédelmi oldal (*Beállítások → Adatvédelem*) – a plugin javasolt szöveget ad hozzá.
- [ ] Sales tax: Floridában a tiszta oktatás (jógaóra) általában nem adóköteles, de ezt könyvelő erősítse meg.
- [ ] Stripe élesítés, webhook, SMTP, cron (fent).
- [ ] Biztonság: erős admin jelszó + 2FA, napi mentés. A plugin korlátozza a belépési próbálkozásokat; teljes tűzfalhoz Wordfence vagy Cloudflare ajánlott.
- [ ] SEO: a téma ad címet, leírást, Open Graph-ot és LocalBusiness/FAQ schemát; ha Yoast/Rank Math kerül fel, a téma ezt automatikusan átadja nekik. Google Business Profile összekötése.

## Fejlesztés és tesztek

Részletes fejlesztői dokumentáció (architektúra, adatmodell, folyamatok, hookok, tesztelés, kiadás): **[DEVELOPER.md](DEVELOPER.md)**.

`dev/` – helyi futtatáshoz és tesztekhez (élesre nem kell):

- `mock-stripe.php` – Stripe-szimulátor (fizetés, előfizetés, ügyfélportál, aláírt webhookok, megújítás / sikertelen terhelés szimulálása).
- `mu-plugins/dev-mail-catcher.php` – küldés helyett fájlba írja a leveleket. `router.php` – PHP beépített szerverhez.
- `tests/run.php` – integrációs tesztek (120 ellenőrzés, visszagörgetett tranzakciókban, az oldalon nem hagynak nyomot).
- `e2e.js` – böngészős végpont-teszt (Playwright), 128 ellenőrzés: foglalás minden fizetési móddal, félbehagyott fizetés, online órák és átváltás, admin naptár (kattintás, húzás, heti ismétlés), vendégek (kártya, bérlet, utólag, eltávolítás, lemondás), tagság (csatlakozás, keret, megújítás, sikertelen terhelés, lemondás, véget érés), várólista, magánóra, ajándékkártya, webhook-hibák, visszatérítés, belépés-zár, mobil nézet.
- `ci.sh` – minden egyben, nulláról (WordPress letöltése, telepítés, tesztek). A GitHub Actions minden pushnál ezt futtatja (`.github/workflows/ci.yml`).

```
DB_NAME=oywp_ci DB_USER=root DB_PASSWORD= DB_HOST=127.0.0.1 WP_DIR=/tmp/wp-ci wordpress/dev/ci.sh
```

## Hogyan épül fel (fejlesztőknek)

- Plugin: saját táblák (`wp_oys_*`): sessions, templates, bookings (egy sor = egy ember, a vendég is), waitlist, passes, orders, gift_cards, private_requests, memberships, stripe_events.
- Helyfoglalás atomikus (`UPDATE … SET booked = booked + N WHERE booked + N <= capacity`), két ember nem kaphatja meg ugyanazt az utolsó helyet, egy társaság vagy mind bekerül, vagy senki.
- Fizetés teljesítése pontosan egyszer: a rendelés `pending → paid` feltételes UPDATE-tel vált; webhook és visszatérő oldal is indíthatja. A webhook esemény-azonosítókat is tárolja (idempotencia), aláírást ellenőriz (HMAC-SHA256, 5 perc tolerancia).
- A téma szűrőkön keresztül adja a pluginnak az óratípusok nevét és linkjét (`oys_class_titles`, `oys_class_url`), így a plugin más témával is működik.
