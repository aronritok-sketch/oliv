# Eszközös tesztsor (oliviajoga.online)

Áron, 2026-11-17: „ezeket a gombokat kurvára teszteld … csinálj egy 1000-1200 tesztet minden eszközről”.

Playwright, csak olvas: nem küld űrlapot, nem foglal, nem vásárol.

- **11 eszköz:**
  - WebKit: iPhone SE, iPhone 13, iPhone 15 Pro Max, iPad Mini, iPad Pro 11, Safari 1440;
  - Chromium: Pixel 7, Galaxy S9+, Galaxy Tab S4, Chrome 1366;
  - Firefox 1920.
- **19 oldal**, oldalanként ezeket nézi:
  - betöltés, szkripthiba, hibás fájl;
  - oldalirányú görgetés;
  - képek és alt szöveg;
  - érintési célok (legalább 40 px);
  - minden lenyíló: a képernyő 60%-ánál megérintve a helyén marad-e, és vált-e;
  - menü, naptár, sütisáv és a „Süti-beállítások” link;
  - linkek.
- **A végén** minden talált link egyszer, HTTP-válaszra.

```
bash dev/qa/run-all.sh https://oliviajoga.online <címke>   # → report-<címke>.txt
```

- A WebKit és a Firefox böngészőt egyszer telepíteni kell, ugyanahhoz a Playwright-verzióhoz:
  `node <playwright>/cli.js install webkit firefox` és `install-deps webkit`.
- Ha a hálózat proxyn megy, a Firefoxnak a gép tanúsítványtára kell. Ehhez `dev/qa/ffprofile/` alá másold a
  `~/.pki/nssdb` `cert9.db`, `key4.db` és `pkcs11.txt` fájlját. Ezek nem kerülnek a repóba.
