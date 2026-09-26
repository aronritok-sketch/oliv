# Olivia Yoga – iOS app

Az aktív vendégek appja: órarend, foglalás (bérlet, tagság vagy kártya), élő online óra indítása, saját órák, bérletek, profil. A WordPress `olivia-studio` plugin mobil API-ját használja (`/wp-json/oys/v1/app/*`, lásd `wordpress/DEVELOPER.md` 6.14).

- **Expo SDK 57**, Expo Router (fájl-alapú útvonalak a `src/app/` alatt), TypeScript, React Compiler
- iOS-en natív fülsáv (SF Symbols, iOS 26-on Liquid Glass), weben saját fülsáv (csak előnézet és teszt)
- Márka: Anton + Archivo betűk, a weboldal színei (`src/constants/theme.ts`)

## Felépítés

```
src/
├── app/                      Képernyők (Expo Router)
│   ├── _layout.tsx           Betűk, splash, bejelentkezés-kapu (Stack.Protected), deep link
│   ├── login.tsx             Belépés
│   ├── (tabs)/_layout.tsx    Fülek
│   ├── (tabs)/index.tsx      Órarend (összes / stúdió / online szűrő)
│   ├── (tabs)/bookings.tsx   Saját óráim (közelgő / múlt), Join gomb
│   ├── (tabs)/passes.tsx     Egyenlegek, bérletek, tagság, vásárlás (web)
│   ├── (tabs)/profile.tsx    Profil, nyilatkozat, linkek, kilépés
│   └── class/[id].tsx        Óra: módválasztó (hibrid), fizetés, vendégek, nyilatkozat, várólista, lemondás
├── components/               UI elemek (gomb, kártya, címke…), fülsáv (iOS + web), óra-kártya, Join gomb
├── constants/theme.ts        Színek, betűk, térközök
└── lib/
    ├── api.ts                API-kliens és típusok
    ├── auth.tsx              Bejelentkezés állapota, token a Keychainben
    ├── storage(.web).ts      expo-secure-store / localStorage
    ├── format.ts             Pénz, napok, elérhetőség
    ├── use-load.ts           Betöltés fókuszkor, húzásos frissítés
    └── __tests__/            Jest unit tesztek
e2e/app.e2e.js                Playwright végpont-teszt a webes build ellen
```

## Futtatás fejlesztéshez

```bash
cd app
npm install
EXPO_PUBLIC_API_BASE=http://<gép-IP>:8080 npx expo start   # a telefon a gép IP-jén éri el a WordPresst
```

A kiírt QR-kódot az iPhone kamerájával beolvasva az app az **Expo Go**-ban nyílik meg. Mac nélkül is működik. Böngészős előnézet: `w` billentyű vagy `npm run web`.

Ellenőrzés commit előtt:

```bash
npm run typecheck && npm run lint && npm test
```

Végpont-teszt a helyi WordPress ellen (ugyanaz a környezet, mint a `wordpress/dev/e2e.js`-hez):

```bash
EXPO_PUBLIC_API_BASE=http://127.0.0.1:8080 npx expo export --platform web
WP_DIR=/útvonal/a/wordpresshez SHOTS=/tmp/app-shots node e2e/app.e2e.js
```

## Beállítás

| Mi | Hol |
|---|---|
| A weboldal címe (API) | `app.json` → `expo.extra.apiBase` (**most minta: `https://oliviakovacsyoga.com` – élesítés előtt a valódi domain kell**); fejlesztéskor `EXPO_PUBLIC_API_BASE` felülírja |
| App neve, verzió | `app.json` → `name`, `version` (a build-szám EAS-ben automatikusan nő) |
| Bundle ID | `com.oliviakovacsyoga.app` (`app.json` → `ios.bundleIdentifier`) – az App Store Connectben ugyanez kell |
| Deep link séma | `oliviayoga://` – a webes fizetés „Back to the app” gombja ezt nyitja |
| Ikon, splash | `assets/images/icon.png` (1024×1024), `splash-icon.png` – most ideiglenes „OK YOGA” jel |

## Kiadás: TestFlight és App Store

iOS buildhez Mac nem kell: az **EAS Build** a felhőben fordít.

1. **Apple Developer Program** tagság (99 USD/év) Olivia nevére vagy cégére. App Store Connectben új app: név, a fenti bundle ID, elsődleges nyelv English.
2. **Expo fiók** (ingyenes): `npx eas-cli@latest login`, majd az `app/` mappában `npx eas-cli@latest init`. Ez beírja a projekt-azonosítót az `app.json`-ba.
3. **Build:** `npx eas-cli@latest build -p ios --profile production`. Első alkalommal az EAS kéri az Apple belépést, és maga készíti el a tanúsítványt és a provisioning profile-t.
4. **Feltöltés TestFlightba:** `npx eas-cli@latest submit -p ios --latest`. Pár perc feldolgozás után a TestFlight appban meghívhatók a tesztelők, legfeljebb 100 belső tesztelő, review nélkül.
5. **App Store:** képernyőképek (6,9" és 6,5"), leírás, kulcsszavak, adatvédelmi címkék (e-mail, név, vásárlási előzmény, app-funkcióhoz, nem követésre), privacy policy URL, support URL, demó fiók a review-hoz (bérlettel), majd beküldés.

Frissítés: `version` emelése az `app.json`-ban, új build és submit. Csak JS-változáshoz később bekapcsolható az `eas update` (OTA).

### App Store szabályok, amikre figyelni kell

- **Fizetés:** jógaóra és bérlet „fizikai szolgáltatás”, ezért mehet a Stripe-on át (3.1.3(e)); ezt használjuk, a fizetés a weboldalon történik. Ha később *digitális* tartalom lesz (felvett órák, videótár), azt Apple in-app vásárlással kell árulni.
- **Fiók törlése (5.1.1(v)):** ha az appból elérhető a regisztráció, az appban fióktörlést is kell kínálni. **Beküldés előtt be kell építeni** (API + gomb a Profilban). Ez a fő nyitott pont.
- A review-hoz működő, éles vagy staging szerver kell, valós órákkal.

## Tervezett következő lépések

- Fióktörlés az appban (App Store feltétel).
- Push értesítések: a tokenek már tárolódnak (`/push-token`), hiányzik az engedélykérés az appban (`expo-notifications`) és a küldés a pluginból (emlékeztető, „indul az óra”, várólistás hely).
- Bérletvásárlás natív képernyővel (most a weboldal nyílik).
- Élő óra az appon belül (Zoom Meeting SDK) – most a Zoom app vagy a böngésző nyílik.
