# Levélküldés az oliviajoga.online-ról a saját VPS-ről (2026-11-13)

Áron: „nem saját Gmail van, a VPS-re rakunk föl Olivia Jógás levélküldő rendszert … add ki a főcsetnek, rakja fel a
VPS-re”. Ez a fő chatnek szóló feladat (a VPS-csomag az övé: hlprv `deploy/vps/`), és Áron saját lépései.

## Helyzet (ellenőrizve, 2026-11-13 17:20 UTC)

- Az éles oliviajoga.online (`/srv/hpv/sites/oliviajoga-online`, a VPS-en) **most egy levelet sem küld ki**: nincs
  SMTP, a VPS levelezőszervere nem fut (`hpv-vps.py mail list` → „a levelezőszerver nem fut”, `health` → `mail: none`).
  A dev-elfogó mu-plugin (`0-hpv-dev.php`) élesen nincs fent, az oldal indexelhető.
- A levelezőcsomag kész: `deploy/vps/mail/` (docker-mailserver: Postfix, Rspamd + DKIM, Roundcube), `hpv-mail`
  (add, alias, dkim, dns, crm-link). Indítani csak a konzolról lehet: `install-mail.sh` (szándékosan, nem távirányítóból).
- **`mail.helloprovision.com` most a régi levelezőre mutat (A `66.223.49.89`), nem a VPS-re** (`37.148.205.64`). A
  `helloprovision.com` MX-e `mailserver.businessidentity.llc`. Ha a `mail.helloprovision.com` átkerül a VPS-re, akkor
  a régi szerver IMAP/SMTP-jét használók levelezése megszakad. Ezért külön gépnév kell.
- GoDaddy VPS: a kimenő 25-ös port zárva, PTR nem állítható → **relé kell** (`mail.env` `DEFAULT_RELAY_HOST`).
- Az oliviajoga.online DNS-e a GoDaddynél van (Áron). Már van rajta DMARC: `v=DMARC1; p=quarantine; adkim=r; aspf=r;
  rua=mailto:dmarc_rua@onsecureserver.net;` → **DKIM-aláírás nélkül minden levél a spambe megy**. A DKIM kötelező.
- olivia-studio **1.8.1** (oliv 9a1b84f, 377544b): saját SMTP a bővítményben, amit a wp-config.php vagy egy mu-plugin
  `OYS_SMTP_HOST`, `OYS_SMTP_PORT`, `OYS_SMTP_SECURE` (`tls`/`ssl`/`none`), `OYS_SMTP_USER`, `OYS_SMTP_PASS`
  konstansai felülírnak. Admin: Stúdió → E-mailek → „Levélküldő szerver (SMTP)” és „Próbalevél küldése” gomb (a
  PHPMailer hibáját is kiírja). A feladó az online kiadásban `info@oliviajoga.online`, a válaszcím (Reply-To) és a
  stúdió-értesítések Olivia címe (`olivia.kovacs6@gmail.com`). A téma (3.4.4) ezt egyszer, csak az alapérték helyére
  állítja be élesen.

## Feladat a fő chatnek

1. **Gépnév a VPS levelezőjének:** `smtp.helloprovision.com` (A → `37.148.205.64`, AAAA nélkül). A `compose.yml`,
   `install-mail.sh`, `webmail.conf` és `hpv-mail` most a `mail.helloprovision.com`-ot égeti be: legyen `MAIL_HOST`
   (`/etc/hpv/hpv.conf`) a forrás, alapból `smtp.helloprovision.com`. A `mail.helloprovision.com`-hoz ne nyúljatok,
   amíg a helloprovision-levelezés át nem költözik.
2. **Relé (kimenő):** mára a GoDaddy reléje, fiók nélkül: `DEFAULT_RELAY_HOST=[n1smtpout.europe.secureserver.net]:25`
   a `mail.env`-ben (napi kb. 5000 levél). Az install után próbáljátok ki, hogy a VPS-ről eléri-e. Ha nem, vagy
   gyenge a kézbesítés: Amazon SES (eu-central-1, 587, `setup relay add-auth`). Ehhez Áronnak AWS-fiók kell.
3. **Kiadás:** a változtatások a telepítő ágra, utána `hpv-vps.py self-update`. Szólj Áronnak, hogy futtassa a
   konzolon: `sudo bash /opt/hpv/hlprv/deploy/vps/mail/install-mail.sh`. Ehhez előbb élnie kell a DNS-ben az
   `smtp.helloprovision.com` A-rekordnak (a tanúsítványhoz).
4. **Az oliviajoga.online domain** (ezek a távirányítóból is mennek: `hpv-site mail …`):
   - `hpv-vps.py mail dkim oliviajoga.online`
   - `hpv-vps.py mail add info@oliviajoga.online` (ez a WordPress belépője, és egyben valódi postafiók)
   - `hpv-vps.py mail alias postmaster@oliviajoga.online info@oliviajoga.online`
   - `hpv-vps.py mail dns oliviajoga.online` → a rekordok Áronnak (GoDaddy). **A meglévő DMARC-ot ne cseréljétek**
     (p=quarantine, laza illesztés: a DKIM-mel átmegy). Kell: SPF TXT (`v=spf1 mx a:smtp.helloprovision.com
     include:secureserver.net ~all`), DKIM TXT (`mail._domainkey`), MX → `smtp.helloprovision.com` (hogy az
     info@ és a postmaster@ fogadjon is).
5. **A WordPress bekötése a jelszó kiadása nélkül:** új `hpv-mail wp-link <cím> <élő-oldal>` (a `crm-link` mintájára),
   ami a `/srv/hpv/sites/<oldal>/wp-content/mu-plugins/0-hpv-smtp.php`-ba (az oldal felhasználójáé, 640) írja:
   ```php
   <?php // HelloProVision VPS: a levelek a saját levelezőn mennek (hpv-mail wp-link). Ne szerkeszd kézzel.
   defined( 'OYS_SMTP_HOST' ) || define( 'OYS_SMTP_HOST', 'smtp.helloprovision.com' );
   defined( 'OYS_SMTP_PORT' ) || define( 'OYS_SMTP_PORT', 587 );
   defined( 'OYS_SMTP_SECURE' ) || define( 'OYS_SMTP_SECURE', 'tls' );
   defined( 'OYS_SMTP_USER' ) || define( 'OYS_SMTP_USER', 'info@oliviajoga.online' );
   defined( 'OYS_SMTP_PASS' ) || define( 'OYS_SMTP_PASS', '<a /etc/hpv/mail/<cím>.pass tartalma>' );
   ```
   Futtatás: `hpv-vps.py mail wp-link info@oliviajoga.online oliviajoga-online`. (Más, nem olivia-studios oldalhoz
   ugyanez egy általános `phpmailer_init`-tel bővíthető.) A jelszó így sosem jön ki a gépről.
6. **Az 1.8.1-es bővítmény és a 3.4.4-es téma élesre:** a hlprv kiadás fut (`site-releases.yml`, a
   `claude/nifty-ramanujan-3zifiu` ág, 3.4.4-es címkék). A `deploy/sites.json`-ben az `oliviayoga-booking` live pinje
   3.4.4-re, utána `GET https://oliviajoga.online/?rest_route=/iu/v1/update`. Ellenőrzés: `oys.css?ver=1.8.1` a
   forrásban. A téma a frissítéskor feltelepíti a vele jött bővítményt. Ha Áron addig kézzel feltölti a
   `wordpress/dist/olivia-studio.zip`-et, az is jó.
7. **Próba:** admin → Stúdió → E-mailek → „Próbalevél küldése”. A Gmailben az „Eredeti megjelenítése” alatt
   `SPF: PASS`, `DKIM: PASS (oliviajoga.online)`, `DMARC: PASS` kell. Utána egy próbaregisztráció: megjön-e a
   köszönőlevél a következő órákkal.

## Áron lépései

1. Ezt a fájlt (vagy a fenti „Feladat a fő chatnek” részt) add át a fő chatnek.
2. DNS, a helloprovision.com szolgáltatójánál: `smtp.helloprovision.com` A → `37.148.205.64` (AAAA nélkül).
3. Ha a fő chat szól: a VPS konzolján rootként, egyszer:
   `sudo bash /opt/hpv/hlprv/deploy/vps/mail/install-mail.sh`
4. GoDaddy, oliviajoga.online DNS: a fő chattől kapott SPF, DKIM (`mail._domainkey`) és MX rekord. A meglévő DMARC
   marad.
5. A végén: oliviajoga.online admin → Stúdió → E-mailek → „Próbalevél küldése”. Megjött, és nem a spambe?

## Milyen levelek mennek ki (az online oldalon csak ezek látszanak az adminban)

- **Regisztráció:** „Köszönöm a regisztrációt” + a következő órák foglalás-linkkel (mindenkinek).
- **Foglalás:** visszaigazolás naptárfájllal és a saját élő-óra linkkel; emlékeztető 24 órával előtte; a belépő link
  30 perccel előtte; lemondás; az óra elmarad (Olivia mondja le, vagy 3 főnél kevesebben vannak); változott az időpont;
  várólista.
- **Vásárlás:** bérlet, ajándékutalvány (a megajándékozottnak és a vevőnek), bérlet lejár.
- **Egyéni órák:** kérés beérkezett, ajánlat, elutasítás.
- **Oliviának:** új fizetés, új egyéni óra kérés, lemondások, minimum-döntés, problémák.
- **WordPress:** elfelejtett jelszó. Ennek a feladója is a stúdió, nem „WordPress”.
- **Az első óráról (dec. 16.)** hírlevél: Stúdió → Hírlevél → „Hírlevél írása” (vagy „Írd meg MI-vel”). Csak annak
  megy, aki regisztrációkor bepipálta: „Kérem a hírlevelet”. A regisztrációs levélben az első óra mindenkinél ott van.
