# Olivia Kovács Yoga – WordPress (téma + foglalás és fizetés)

Két rész, mindkettő a WordPress adminban tölthető fel ZIP-ként (`dist/`):

| Csomag | Mi ez |
|---|---|
| `olivia-yoga` (téma) | Az „Urban flow” dizájn: főoldal, aloldalak, óratípusok, napló, GYIK, kapcsolat. Egy kattintásos tartalombetöltővel. |
| `olivia-studio` (plugin) | Online foglalás, fizetés (Stripe), bérletek, várólista, magánórák, ajándékkártya, ügyfélfiók, e-mailek, admin. |

## Mit tud

**Ügyfélnek**
- Órarend (heti ismétlődő órák + egyedi események), szabad helyek száma, „Book” gomb.
- Foglalás: bérletből 1 kattintással · egyedi óra kártyával (drop-in) · „vegyél bérletet és foglald le ezt az órát” egy lépésben · bevezető ajánlat új diákoknak (alapból kikapcsolva).
- Fizetés Stripe Checkouttal (kártya, Apple Pay, Google Pay, Link) – a kártyaadat sosem jár a honlapon.
- Fizetés közben a hely 30 percig foglalva van; ha a vendég visszalép, azonnal felszabadul.
- Telt ház → várólista. Ha felszabadul egy hely: aki bérlettel vár, **automatikusan bekerül** (és e-mailt kap), a többiek értesítést kapnak.
- Lemondás a fiókból: határidőn belül a bérletes alkalom visszajár, kártyás drop-in helyett óra-kredit jár; határidő után az alkalom elhasználtnak számít (szabály és szöveg a beállításokban).
- Magánóra: kérés (hely, hossz, időpontok, megjegyzés) → Olivia ajánlatot küld konkrét időponttal és árral → az ügyfél egy kattintással fizet (vagy magánóra-kredittel).
- Ajándékkártya: vásárlás → a megajándékozott e-mailben kódot kap → a fiókjában beváltja, a bérlet jóváíródik.
- Ügyfélfiók: közelgő foglalások (naptárba tétel, lemondás, online link), bérletek és egyenleg, magánórák, előzmények, fizetések nyugtával, profil (telefon, vészhelyzeti kontakt, egészségügyi megjegyzés), jelszó.
- Részvételi nyilatkozat (waiver) elfogadása regisztrációkor; új verziónál újra kéri.
- E-mailek arculattal: visszaigazolás .ics naptármelléklettel, emlékeztető 24 órával előtte, lemondás, várólista, bérlet, ajándék, magánóra-ajánlat, üdvözlő levél.

**Oliviának (wp-admin → Studio)**
- Ma: bevétel (30 nap), telítettség (7 nap), új magánóra-kérések, mai jelenléti listák.
- Órarend és névsorok: jelenlét (itt volt / nem jött), valaki hozzáadása (bérletből, készpénz, ajándék), foglalás lemondása, egész óra lemondása (mindenki e-mailt kap, kreditet visszakap).
- Heti órarend (sablonok) – ebből hetekre előre automatikusan készülnek az alkalmak.
- Magánóra-kérések: ajánlat küldése / elutasítás.
- Ügyfelek: profil, egészségügyi megjegyzés, nyilatkozat, bérletek (módosítás, kredit adása), foglalások, fizetések.
- Fizetések: visszatérítés részben vagy egészben (a Stripe-ban indított visszatérítést is átveszi).
- Ajándékkártyák, Árak és bérletek, Beállítások (Stripe, szabályok, nyilatkozat, e-mail).
- „Studio manager” szerepkör: más is kezelheti a stúdiót teljes admin jog nélkül.

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
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`.
   A „Signing secret” (`whsec_…`) → Settings (vagy `OYS_STRIPE_WEBHOOK_SECRET`).
4. *Settings → Payment methods:* Apple Pay / Google Pay / Link bekapcsolása; *Branding*: logó és színek (#2B5036); *Emails*: sikeres fizetés nyugta bekapcsolása.
5. Teszt: Test módban `4242 4242 4242 4242` kártyával végigfoglalni egy órát, bérletet, ajándékkártyát; utána **Mode: Live**.

## Élesítés előtti lista

- [ ] Valós órarend, árak, bérlet-érvényesség (*Studio → Weekly timetable, Prices & passes*). A mostani adatok a korábbi MINTA adatok.
- [ ] A minta esemény („Live-Music Slow Flow…”) törlése vagy valódira cserélése.
- [ ] Részvételi nyilatkozat és lemondási szabály szövegét ügyvéd nézze át (*Studio → Settings*).
- [ ] Adatvédelmi oldal (*Beállítások → Adatvédelem*) – a plugin javasolt szöveget ad hozzá.
- [ ] Sales tax: Floridában a tiszta oktatás (jógaóra) általában nem adóköteles, de ezt könyvelő erősítse meg.
- [ ] Stripe élesítés, webhook, SMTP, cron (fent).
- [ ] Biztonság: erős admin jelszó + 2FA, biztonsági bővítmény (pl. Wordfence) a bejelentkezések korlátozására, napi mentés.
- [ ] SEO: a téma ad címet, leírást, Open Graph-ot és LocalBusiness/FAQ schemát; ha Yoast/Rank Math kerül fel, a téma ezt automatikusan átadja nekik. Google Business Profile összekötése.

## Fejlesztés és tesztek

Részletes fejlesztői dokumentáció (architektúra, adatmodell, folyamatok, hookok, tesztelés, kiadás): **[DEVELOPER.md](DEVELOPER.md)**.

`dev/` – helyi futtatáshoz (élesre nem kell):

- `mock-stripe.php` – Stripe-szimulátor (API + „fizetős oldal” + aláírt webhook).
- `mu-plugins/dev-mail-catcher.php` – küldés helyett fájlba írja a leveleket.
- `router.php` – PHP beépített szerverhez.
- `e2e.js` – böngészős végpont-teszt (Playwright), 30 ellenőrzés: regisztráció, kártyás foglalás, bérlet, kreditfoglalás, lemondás és kredit-visszaadás, várólista automatikus beléptetése, magánóra-ajánlat és fizetés, ajándékkártya vétel és beváltás (kétszer nem megy), webhook előtti visszatérés, dupla webhook, megszakított fizetés, visszatérítés, hamis webhook elutasítása.

```
# wp-config.php (csak helyben): define( 'OYS_STRIPE_API_BASE', 'http://127.0.0.1:8090' );
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 router.php      # WordPress mappában
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8090 dev/mock-stripe.php
WP_DIR=/path/to/wordpress node dev/e2e.js
```

## Hogyan épül fel (fejlesztőknek)

- Plugin: saját táblák (`wp_oys_*`): sessions, templates, bookings, waitlist, passes, orders, gift_cards, private_requests, stripe_events.
- Helyfoglalás atomikus (`UPDATE … SET booked = booked + 1 WHERE booked < capacity`), két ember nem kaphatja meg ugyanazt az utolsó helyet.
- Fizetés teljesítése pontosan egyszer: a rendelés `pending → paid` feltételes UPDATE-tel vált; webhook és visszatérő oldal is indíthatja. A webhook esemény-azonosítókat is tárolja (idempotencia), aláírást ellenőriz (HMAC-SHA256, 5 perc tolerancia).
- A téma szűrőkön keresztül adja a pluginnak az óratípusok nevét és linkjét (`oys_class_titles`, `oys_class_url`), így a plugin más témával is működik.
