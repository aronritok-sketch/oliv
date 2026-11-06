# oliviajoga.hu – 3. kör: art direction brief (2026-11-06)

Áron: „vedd komolyan ezt a projektet, használd a mesterek eszközeit, emellett maradjon meg a dizájn … de erősíts,
legyen erősebb.” A 2. kör (új logó, új betű, fekete-fehér minden, futó szósáv, forgó felirat, sok új elem) „szarabb
lett” – visszavonva. Tanulság (webdesign skill, kritika.md: Imperial Kitchens, LV3D): **ráncfelvarrásnál a meglévő
design a rendszer**; Olivia jegyzetei meglátások, nem szó szerinti feladatlista.

## Brief

| | |
|---|---|
| **Kinek** | Olivia vendégei és leendő vendégei: 25–55 éves, főleg nők Pestszentlőrinc (Prána) és Csepel (Korall) környékén, telefonról keresnek („jóga 18. kerület”, „jóga csepel”); sok kezdő, aki attól tart, hogy „nem elég ügyes” |
| **Mit érezzen** | „Ide nyugodtan elmehetek, nem fognak kinézni – és két kattintással bejelentkezem.” |
| **3 jelző** | napfényes · befogadó · határozott |
| **Metafora** | a hatha flow légzése: belégzés (tartalmas, sűrű blokk) – kilégzés (levegős: egy kép, egy mondat); a lap ritmusa így váltakozik |
| **Gerinc** | **Tschichold** – két üzemmód: plakát-mód csak a hősben, könyv-mód a hosszú szövegnél (Rólam, írások, GYIK): 60–70 karakteres hasáb, 1.65 sorköz, egy bekezdés-stílus; a régi oldal „címsorral kiemelt” mondatai egyetlen kiemelés-stílust kapnak (nem váltakozó méret és vastagság). Anton címek a magyar ékezetekhez igazított sorközzel, `text-wrap: balance`. |
| **Hang** | **Hey** – egy erős vizuális szabály: a főoldalon Olivia fotói fekete-fehérek, a szín a meglévő színblokkokból és keretekből jön (a kép a valóság, a szín az öröm). **Headspace** – meleg, nem misztikus; a meglévő organikus foltformák maradnak, nincs új díszcsalád. |
| **Mozgás** | **Dumbar** – egy mozgásszabály, a légzés tempója (`--breath-in: 4s`, `--breath-out: 6s`, `--ease-breath`): a hős lila „JÓGA” kiemelése lélegzik; a beúszások elemenként, kilégzés-időzítéssel; `prefers-reduced-motion` alatt minden áll. |
| **Aláírás-pillanat / csavar** | a „FLOW JÓGA” kiemelése Olivia légzésritmusában tágul és szűkül a fekete-fehér hero fotó mellett – lélegző címet még egyik oldalunkon sem csináltunk |
| **Rendszer** | marad: Anton + Quicksand, a téma színei (forest, orchid, pink, lilac, sun, sage + soft), sarkok, offset árnyékos keretek, a téma típus- és térközskálája |
| **Tiltólista** | új betűcsalád; futó szövegsáv (Olivia korábban: „ez szar”); forgó felirat; új díszkomponens-család; az egész oldal átszínezése; mindent középre zárni; minden elem ugyanúgy beúszik |
| **Fő cselekvés** | Bejelentkezés (Motibro): fejléc-gomb, a két helyszín egyenrangú gombja, mobilon a lebegő gomb |
| **80 / 20** | 80%: nyugodt rendszer, a meglévő komponensek; 20%: a lélegző hős, a fotók, a Rólam története |

## Olivia 30 meglátása → megoldás a meglévő dizájnon belül

| # | Meglátás | Megoldás |
|---|---|---|
| 1 | logócsere a moodboard alapján | 3 logójavaslat külön lapon (`/logo-javaslatok/`, csak az előnézetben); a fejléc Olivia választása után cserélődik |
| 2 | „hero” kép a főoldalra; a főoldalon minden fotó fekete-fehér, kivéve az ajándékutalványt | a hős fotója az ő képe; a főoldal fotói fekete-fehérek, a színblokkok maradnak |
| 3 | Csepel és 18. kerület gombja egyenrangú | ugyanaz a gomb mindkettőnek (hős és helyszínkártyák) |
| 4 | a címsoroknál összelógnak az ékezetek | nagyobb sorköz a többsoros Anton címeken, kiegyensúlyozott tördelés |
| 5 | egy kis animálás | a légzés-mozgásszabály (fent) |
| 6 | több kép a kedvencekből, a diszito3 mindenképp | képsor a meglévő keret-komponenssel (diszito3 nagyban + 2 kép), a helyszínek után |
| 7, 13 | a háttér nélküli képek maradjanak háttér nélküliek | kész (1.0.1): nincs helykitöltő szín az átlátszó képek mögött |
| 8 | furán váltakozó betűméret, vastagság a folyószövegben | egy bekezdés-stílus, egy bevezető, a meghívó-mondat a meglévő idézet-dobozban |
| 9 | GYIK: kreatívabb, kisebb, ne legyen unalmas | számozott, csukott kérdések két hasábban (a válaszok a lapon maradnak a Google-nek) |
| 10 | ajándékutalvány: alcím és szöveg kreatívabban | az alcím „jegy”-címke, a két teendő számozott lépés |
| 11 | csapatépítő rész ki a főoldalról és a menüből | kész; a `/jógaóra-cégeknek` oldal a címén megmarad |
| 12 | a hosszú vélemény legyen esztétikus | Gergely véleménye kiemelt, széles idézet-kártya; a rövidek kiegyensúlyozott rácsban |
| 14, 18 | a neve túl sokszor a láblécben | a nagy név és a „Jóga 18. és 21. kerület \| Kovács Olívia” sor kikerül |
| 15, 16 | Rólam: a rajzok ki, a design újragondolva, szöveg-elrendezés jobban | könyv-mód: olvasóhasáb számozott fejezetekkel, mellette tapadó portré; a nagy mondatok színblokkban, teljes szélességben |
| 17, 19 | széteső képminőség (események) | a képek eredeti arányban, nem nagyítva; a fejléckép fekvő keretben |
| 20 | az alcím (helyszínek) szebben | címke-sor: „csoportos hatha flow jóga órák” + helyszín-címkék (gombostűvel) |
| 21 | 90 perces óra ki | kész |
| 22 | az alsó Bejelentkezés gomb nem kell | kész |
| 23 | Árak: „private yoga” kép | kész |
| 24 | A jógáról - Tőlem: „diszito2” kép | kész |
| 25 | az írások képeinek fehérje legyen átlátszó | a szélhez érő fehér átlátszó (`tools/images.py cutouts`) |
| 26 | kilóg a gombból a szöveg | javítva |
| 27 | szebb cikkformázás, főleg a cím | a cikk címe rendes címsor (Anton), bevezető, szoros listák, könyv-mód hasáb |
| 28 | Motibro középre, külön görgetés nélkül | középen, a tartalom magasságával |
| 29 | alatta szebben formázva | a két helyszín címke-sorban, a gombok alatta |
| 30–33 | Kapcsolat: „Olivia3” kép; két színes doboz helyszínnel, órarenddel, térképpel; űrlap és kép ki, helyette kedves ösztönző szöveg | a főoldali helyszínkártya-komponens térképpel; a szöveg tőlünk, Olivia átírhatja |
