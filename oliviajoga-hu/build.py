#!/usr/bin/env python3
"""Builds the new oliviajoga.hu: Olivia's Google Site (Jóga 18. és 21. kerület | Kovács Olívia) in the Olivia Yoga
design, as a static site.

Everything Google already knows stays the same: every page keeps its address (/rólam, /árak, /a-nyújtás-hatása …),
its <title>, its headings (with their h1/h2/h3 level) and its words, and every photo keeps its alt text. Only the
layout and the experience are new. The words come from content/pages.json (tools/extract.py), the design from the
WordPress theme (../wordpress/olivia-yoga/assets: site.css, site.js, photos) plus assets/oliviajoga.css.

    python3 build.py                                  → dist/ for oliviajoga.hu (canonical, sitemap, .htaccess)
    python3 build.py --preview /demo/<slug>/ --out D  → a noindex preview under a sub-folder (links end in /)
    python3 build.py --wp <folder>                    → the WordPress theme "oliviayoga" (iu_theme child, wp-theme/ +
                                                        data/site.json; HelloProVision delivery: hlprv oliviayoga/wp-theme/)
"""
import html
import json
import math
import os
import re
import shutil
import sys
import unicodedata
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
THEME = os.path.normpath(os.path.join(HERE, '..', 'wordpress', 'olivia-yoga'))
ORIGIN = 'https://oliviajoga.hu'
MOTIBRO = 'https://oliviajoga.motibro.com/'
EMAIL = 'olivia.kovacs6@gmail.com'
INSTAGRAM = 'https://www.instagram.com/oliivia_yoga/'
FACEBOOK = 'https://www.facebook.com/oliviajogaoktato'
SITE_TITLE = 'Jóga 18. és 21. kerület | Kovács Olívia'
NOTICE = 'Óráimat 2026. november 25-ig tartom!'  # the site's announcement banner
# Olivia, „oliviajoga.hu módosítások 11.12.”: after 25 November she teaches live online yoga on oliviajoga.online (ours).
ONLINE = 'https://oliviajoga.online'  # Olivia: the final domain, without www
ONLINE_PATHS = {'home': '/', 'yoga': '/online-orak/hatha-flow-joga-online/', 'prenatal': '/online-orak/online-kismamatorna/',
                'account': '/fiokom/', 'gift': '/ajandekutalvany/', 'classes': '/online-orak/', 'request': '/fiokom/?tab=private',
                'private': '/online-orak/egyeni-online-joga/', 'stretching': '/online-orak/stretching-mobility/',
                'relax': '/online-orak/relaxacio-es-legzes/', 'prices': '/bejelentkezes-es-arak/', 'contact': '/kapcsolat/'}
ONLINE_TIME = 'szerdánként 18:30-tól 20:00-ig'
# The second half of her document („NOVEMBER 26-ÁN”): --after builds it; the WordPress theme switches on AFTER_FROM.
AFTER = False
AFTER_FROM = '2026-11-26'
VERSION = '1.8.3'
# "Jógaóra cégeknek" left the menu and the home page (Olivia, 2026-11-05); the page stays at its address.
NAV = [
    ('', 'Főoldal'), ('rólam', 'Rólam'), ('események', 'Események'), ('árak', 'Árak'), ('jógáról', 'Jógáról'),
    ('bejelentkezés-jóga-bérlet', 'Bejelentkezés & jóga bérlet'), ('kapcsolat', 'Kapcsolat'),
]
# Olivia's own photos from her Drive (tools/images.py import): key → Hungarian alt text.
NEW_PHOTOS = {
    'hero': 'Hatha flow jóga óra egy tóparti teraszon, lefelé néző kutya pózban',
    'diszito': 'Csoportos jóga egy fesztivál pavilonjában, felnyújtott karokkal',
    'diszito2': 'Gyermekpóz egy szabadtéri csoportos jógaórán',
    'diszito3': 'Kovács Olívia felnyújtott karokkal vezeti a szabadtéri csoportos jógaórát',
    'diszito4': 'Kovács Olívia harcos pózban egy réten, naplementekor',
    'diszito5': 'Szabadtéri csoportos jógaóra kitörésben',
    'diszito6': 'Jógázó hátulról, fordított namaszte kéztartással, mögötte csoportos óra',
    'olivia3': 'Kovács Olívia guggoló pózban, összetett kézzel egy fesztivál teraszán',
    'private-yoga': 'Fa póz egy világos jógateremben',
    # 2026-11-06 (Olivia, CRM chat): instead of the old round cut-outs; they keep the old pictures' alt text
    'miert-joga': 'jóga 21. kerület jóga 18. kerület jóga 11. kerület jóga csepel flow kovács olívia jógaoktató',
    'gyakoroljunk': 'jóga 11. kerület újbuda mórcz xxi. kerület jóga 18. kerület jóga csepel jóga 21. kerület kovács olívia jógaoktató',
}
# Olivia (2026-11-06): „töröljük teljesen a jógaóra cégeknek mindenhonnan” – no page, the old address goes home (301)
DELETED_PAGES = {'jógaóra-cégeknek'}
NEW_META = {}
CUTOUTS = set()  # pictures whose white edge became transparent (tools/images.py cutouts)
# Taken out on purpose (Olivia's notes): tools/check.py skips these.
REMOVED_FILES = {
    'old/775328016a39', 'old/085e2f02ccb4', 'old/244f5915632a', 'old/c61fb20665ba', 'old/3c84eb08b35c',
    'old/5214bbe1d570',  # Rólam: the small drawings above the story
    'old/3cf216ddede1',  # Kapcsolat: the picture next to the form
    'old/d1f4ce0eccf6', 'old/ed90bdb67eca',  # home: the old round cut-outs, Olivia sent new photos for them
    'old/4242cda89684',  # the payment logos: blurry, replaced by Barion's official acceptance banner (SVG)
}
REMOVED_EMBEDS = {'docs.google.com/forms'}
# Olivia (11.12): the gift card is an online one now, bought on the online site – the old steps (write to me, Motibro
# invitation) are replaced; tools/check.py skips these.
# Olivia (hu-11.12, 16 November): „áraknál ugyanúgy ahogy az oliviajoga.onlineon, az alcímbe írj bele minden szolgáltatást”
PRICES_SUB = ('élő online, csoportos hatha flow jóga órák', 'egyéni online jóga, online kismamatorna, stretching és mobilizáció, relaxáció és légzés')
# Olivia (11.15 doc, 16 November): „az oliviajoga.hu-n írjuk bele az összes új szolgáltatást, ne csak a csoportos órát.
# Legalább egyszer említsük meg” – and on the prices page (hu-11.12): every service's prices in a fold, as on the online site.
# The prices are the online site's (oliviajoga.online, 16 November): (name, online key, request topic, [(who, length,
# one session, 5 sessions)]).
ONLINE_SERVICES = [
    ('Egyéni online jóga', 'private', 'egyeni-joga', [('Egyedül', '60 perc', '8 000 Ft', '35 000 Ft'), ('Egyedül', '90 perc', '10 000 Ft', '43 000 Ft'),
                                                       ('Párban', '60 perc', '12 000 Ft', '52 000 Ft'), ('Párban', '90 perc', '15 000 Ft', '65 000 Ft')]),
    ('Online kismamatorna', 'prenatal', 'prenatal', [('Egyedül', '60 perc', '8 000 Ft', '35 000 Ft'), ('A barátnőddel', '60 perc', '12 000 Ft', '52 000 Ft')]),
    ('Online stretching és mobilizáció', 'stretching', 'stretching', [('Egyedül', '60 perc', '7 000 Ft', '30 000 Ft'), ('Párban', '60 perc', '10 500 Ft', '45 000 Ft')]),
    ('Relaxáció és légzés', 'relax', 'relax', [('Egyedül', '30 perc', '5 000 Ft', '20 000 Ft'), ('Párban', '30 perc', '7 500 Ft', '32 000 Ft')]),
]
SERVICES_LINE = ('Élő online, a saját otthonodból: csoportos hatha flow jóga szerdánként 18:30-tól 20:00-ig, és egyéni '
                 'időpontban egyéni online jóga, online kismamatorna, stretching és mobilizáció, relaxáció és légzés.')
REPLACED_TEXT = {'csoportos hatha flow jóga órák Budapest 18. kerület Budapest 21. kerület', '1 órás vagy 5 alkalmas jóga ajándékutalvány', 'Ezekre az információkra lesz szükségem az ajándékutalvány kiállításához:'}
REPLACED_TEXT_START = ('Vásárlás után kérlek, vedd fel velem a kapcsolatot', 'Ahhoz, hogy az ajándékozott tudjon online foglalni',
                       'Ha szeretnéd megtapasztalni azt, hogy milyen az elfogadás')  # the last: fix_links, OLD_INVITE (Áron, 26 Nov)  # Kapcsolat: the message form
DROPPED_LENGTHS = {'90 perc'}  # Árak: there are no 90-minute classes any more
# Photos from the theme (Olivia's own, round 8) for the page heads; Hungarian alt text.
THEME_PHOTOS = {
    'olivia-golden-hour-arms': 'Kovács Olívia felnyújtott karokkal a meleg esti fényben',
    'olivia-sunset-smile': 'Kovács Olívia jógaoktató mosolyog naplementekor',
    'olivia-mandala-lunge': 'Kovács Olívia magas kitörésben egy mandala előtt',
    'olivia-terrace-profile': 'Kovács Olívia egy teraszon naplementekor, a távolba néz',
    'team-tree-pose-gym': 'Csapat fa pózban egy sportcsarnokban',
    'studio-plants-class': 'Csoportos óra egy világos, növényekkel teli teremben',
    'white-studio-class': 'Ülő csoportos óra egy fehér teremben',
}
PAGE_PHOTO = {
    'rólam': 'olivia-sunset-smile', 'árak': 'new:private-yoga', 'jógáról': 'new:diszito2',
    'jógaóra-cégeknek': 'team-tree-pose-gym', 'bejelentkezés-jóga-bérlet': 'studio-plants-class',
    'kapcsolat': 'new:olivia3',
}


def dropped_section(path, sec):
    """Sections Olivia asked to take out: the teams/athletes offer and its message button on the home page."""
    if path in ('', 'főoldal'):
        txt = section_text(sec)
        return ('cégeknek' in txt and 'sportolóknak' in txt) or txt == 'Üzenet küldése'
    return False
TONES = ['lilac', 'pink', 'sun', 'sage']
# The price cards were pictures on the old site; their words, read from the pictures.
PRICES = {
    'old/76142db7c616': ('1 alkalom', '3000 Ft', '60 perc'),
    'old/7d137c0e1325': ('5 alkalom', '14000 Ft', '60 perc'),
    'old/5e2c9133fae6': ('1 alkalom', '5000 Ft', '90 perc'),
    'old/6fc73f7270d6': ('5 alkalom', '23000 Ft', '90 perc'),
}
# "Ti írtátok": the reviews were pictures too; their words.
REVIEWS = [
    ('K. M. Erzsébet', 'Kedves, szép hangú, szakmailag felkészült oktató. Nagyon átmozgató óra volt. Köszönöm.'),
    ('F. Erika', 'Köszönöm szépen, hogy részt vehettem. Sok gyakorlatnál nem tudtam elképzelni, hogyan fogok kijönni a pózból, de sikerült. Biztosan nem szabályosan és nem mindent tudtam megcsinálni, de szuperül éreztem magam.'),
    ('Á. Petra', 'Nagyon jó első óra volt, feltöltött testileg - lelkileg. Nem mondom, hogy nincs izomlázam 😅 de alig várom a következőt!!! 🧘‍♀️'),
    ('T. Veronika', 'Első jóga órám volt, köszönöm az elfogadást, biztatást!'),
    ('G. Barbara', 'Átmozgatott, jól esett.'),
    ('I. Gergely', 'A munkahelyi stressz levezetése végett kezdtem rendszeresen jógázni. Korábban kipróbáltam több küzdősportot és a mozgás mindig az életem része volt, de ilyen hatékony technikára korábban nem leltem. 1,5 éve rendszeresen jógázom és alapos kutatás után több kiváló tanár kezei között tanulhattam. Eddig a város másik végébe kellett órára járnom és örültem mikor közel megtaláltam a Prána központot. Olíviát is csak ajánlani tudom, szakértelme, figyelmessége, elkötelezettsége a jóga, a tanítványok és a tanítás mellett kiemelkedő.'),
    ('Cs. Anita', 'Számomra tökéletes volt, tetszett, hogy dinamikus volt és nekem teljesen új gyakorlatokat ismerhettem meg :-)'),
    ('F. Eszter', 'Nagyon jó volt, tetszett.😊'),
]
# Pictures left out on purpose: they only repeated words that are on the page anyway.
DROPPED = {'old/9d3d41cc2f4b'}  # "Órák november 25-ig!" next to "Fontos! Óráimat 2026. november 25-ig tartom."
# "Ami nem szükséges / Ami szükséges": a picture on the home page; its words.
NEEDS = (['jógás tapasztalat', 'drága jógaeszköz', 'extra hajlékonyság', 'szép jógás ruha', 'elvárások magad felé'],
         ['éld meg a pillanatot'])

BASE = '/'
PREVIEW = False
OUT = os.path.join(HERE, 'dist')
PAGES = {}

ICON = {
    'arrow': '<path d="M4.5 12h15M13.5 6l6 6-6 6"/>',
    'plus': '<path d="M12 5v14M5 12h14"/>',
    'cal': '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
    'clock': '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    'pin': '<path d="M12 21s-6.5-6.2-6.5-11a6.5 6.5 0 0 1 13 0c0 4.8-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/>',
    'mail': '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.5 7 8.5 6.3L20.5 7"/>',
    'ig': '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6" fill="currentColor"/>',
    'fb': '<path d="M14.5 8.5H17V4.8h-2.5a4 4 0 0 0-4 4v2.2H8v3.6h2.5v6.9h3.6v-6.9h2.6l.6-3.6h-3.2V9.3a.8.8 0 0 1 .4-.8z"/>',
    'menu': '<path d="M4 8h16M4 16h16"/>',
    'close': '<path d="M6 6l12 12M18 6 6 18"/>',
    'user': '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
    'quote': '<path d="M9.5 7C6.5 8 5 10.4 5 13.6V17h4.6v-4.4H7.4c0-2 .8-3.4 2.6-4.1zm8.5 0c-3 1-4.5 3.4-4.5 6.6V17h4.6v-4.4h-2.2c0-2 .8-3.4 2.6-4.1z"/>',
}


def icon(name, cls='icon'):
    return (f'<svg class="{cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{ICON[name]}</svg>')


def brush_d(w, h, amp, th, periods=1.5, phase=0.0, n=96):
    """The wave of her logo as a path (tools/logo.py): a brush stroke along a sine, thin at both ends, thick in the
    middle. Olivia (11.12): every wave on the site in this style."""
    top, bot = [], []
    for i in range(n + 1):
        t = i / n
        x = w * t
        y = h / 2 + amp * math.sin(2 * math.pi * periods * t + phase)
        d = (.1 + .9 * math.sin(math.pi * t) ** 1.15) * th / 2
        top.append(f'{x:.1f} {y - d:.1f}')
        bot.append(f'{x:.1f} {y + d:.1f}')
    return 'M' + ' L'.join(top) + ' L' + ' L'.join(reversed(bot)) + 'Z'


BRUSH_BACK = brush_d(400, 100, 22, 30)
BRUSH_FRONT = brush_d(400, 100, 18, 12, phase=.7)
BRUSH_LONG_BACK = brush_d(1000, 100, 24, 30, periods=2.5)
BRUSH_LONG_FRONT = brush_d(1000, 100, 20, 11, periods=2.5, phase=.8)


def blob(tone='pink', side='left'):
    """Two brush strokes behind a picture, like the wave in her logo: a broad one in the tone, a thin orchid one."""
    return (f'<svg class="oj-blob oj-brush oj-blob--{side} oj-tone--{tone}" viewBox="0 0 400 100" preserveAspectRatio="none" aria-hidden="true" '
            f'focusable="false"><path class="oj-blob__back" d="{BRUSH_BACK}"/><path class="oj-blob__front" d="{BRUSH_FRONT}"/></svg>')


# The hero keeps its first, soft wave behind the photo (Olivia, 2026-11-12: „nem a gomb, hanem a háttér” – the brush
# stroke of 1.4.0 is everywhere else, not there).
SOFT_BACK = 'M0 6C14 0 34 2 50 8C70 15 92 22 96 40C100 56 86 66 90 80C94 94 76 100 56 98C38 96 18 90 0 94Z'
SOFT_FRONT = 'M0 30C18 22 40 28 56 38C74 49 80 64 72 76C64 88 42 90 24 86C14 84 6 84 0 86Z'


def blob_soft(tone='pink', side='left'):
    """The soft wave shape of 1.3.0 behind a picture, running off the screen on the picture's side."""
    flip = ' transform="matrix(-1 0 0 1 100 0)"' if side == 'right' else ''
    return (f'<svg class="oj-blob oj-blob--{side} oj-tone--{tone}" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" '
            f'focusable="false"><g{flip}><path class="oj-blob__back" d="{SOFT_BACK}"/><path class="oj-blob__front" d="{SOFT_FRONT}"/></g></svg>')


def band(tone='lilac', flow=False):
    """A long brush stroke behind a row of pictures; flow: it moves (assets/oliviajoga.js)."""
    cls = ' oj-band--flow' if flow else ''
    return (f'<svg class="oj-band oj-brush{cls} oj-tone--{tone}" viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            f'<path class="oj-blob__back" d="{BRUSH_LONG_BACK}"/><path class="oj-blob__front" d="{BRUSH_LONG_FRONT}"/></svg>')


WAVE_MARK = ('<svg class="oj-wavemark" viewBox="0 0 60 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="'
             + brush_d(60, 16, 3.6, 4.6) + '"/></svg>')


def esc(s):
    return html.escape(s, quote=True)


def text_of(h):
    return html.unescape(re.sub(r'<[^>]+>', ' ', h)).replace('\xa0', ' ')


def norm(s):
    return re.sub(r'\s+', ' ', s).strip()


# ---------------------------------------------------------------- addresses

HU_ASCII = str.maketrans('áéíóöőúüűÁÉÍÓÖŐÚÜŰ', 'aeiooouuuAEIOOOUUU')


def ascii_slug(path):
    """A page's address without accents, words joined by hyphens (Áron, 25 November: „minden aloldal url slug legyen ékezet
    nélkül, kötőjellel”): /rólam → /rolam, /bejelentkezés-jóga-bérlet → /bejelentkezes-joga-berlet. The old accented
    addresses answer with a 301 to these (wp-theme: the data's „moved” map)."""
    s = unicodedata.normalize('NFKD', path.strip('/').translate(HU_ASCII).lower()).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', s).strip('-')


def link(path):
    """The address of a page: /rolam on oliviajoga.hu (no trailing slash, as before), …/rolam/ in a preview."""
    path = path.strip('/')
    if AFTER and path == 'bejelentkezés-jóga-bérlet':
        return online('prices')  # Olivia (16 Nov): from 26 November to „Bejelentkezés és árak” on the online site
    if AFTER and path == 'kapcsolat':
        return online('contact')  # Olivia (16 Nov): „A kapcsolatnál irányítson át az oliviajoga.online/kapcsolat/-ra”
    if path == 'főoldal':
        path = ''  # the same page as the home page
    if not path:
        return BASE
    return BASE + ascii_slug(path) + ('/' if PREVIEW else '')


WP = False  # --wp: the WordPress theme (oliviayoga); addresses and files become placeholders the theme fills in


def online(key='home'):
    """An address on Olivia's online site (oliviajoga.online; in the WordPress theme a placeholder it fills in)."""
    return ('%%ONLINE%%' if WP else ONLINE.rstrip('/')) + ONLINE_PATHS[key]


def book_url():
    """Where „Bejelentkezés” goes: Motibro until 25 November, the online site after (Olivia)."""
    return online('home') if AFTER else MOTIBRO


def online_note():
    """Under the articles and the Rólam page: a few words, in her voice, on where to practise with her now – with links to
    the online site's class pages (Áron, 11.19: „linkek az oliviajoga.hu-ról az .online-ra, ahol természetes”; the facts are
    the online site's own)."""
    a = lambda key, text: f'<a href="{esc(online(key))}"{ext_attr(online(key))}>{text}</a>'
    return (f'<aside class="oj-online-note" data-reveal><p class="kicker">Gyakorolj velem online</p>'
            f'<p>Élő, magyar nyelvű online órákat tartok, a saját otthonodból: {a("yoga", "csoportos online flow jóga")} {ONLINE_TIME}, '
            f'egyéni időpontban pedig {a("private", "egyéni online jóga")} és {a("prenatal", "online kismamatorna")}. '
            f'Időpontok, bérletek és foglalás: {a("home", "oliviajoga.online")}.</p></aside>')


def asset(rel):
    if WP:  # the photos come from the media folder (VPS: media_dir from this repo), the rest from the theme
        if rel.startswith('img/theme/'):
            return '%%THEME%%/assets/' + rel
        if rel.startswith('img/'):
            return '%%MEDIA%%/' + rel[4:]
        return '%%THEME%%/assets/' + rel
    return f'{BASE}assets/{rel}'


# Áron, 26 November (a screenshot of the home page's line): „ide pedig ne a kerületeket írjuk, hanem hogy online, csoportos
# jóga órákon és a .online csoportos jóga aloldalára vigyen a szöveg”
OLD_INVITE = 'szeretettel a 11. kerületi, 18. kerületi és 21. kerületi <a href="page:kapcsolat">csoportos jóga órákon!</a>'


def fix_links(h):
    """page:<path> links from the old site → new addresses; outside links open in a new tab."""
    if OLD_INVITE in h:
        u = online('yoga')  # a new tab, as every link to the online site (rep below adds it to the http ones)
        h = h.replace(OLD_INVITE, f'szeretettel az <a href="{esc(u)}"{ext_attr(u) if u.startswith("%%") else ""}>online, csoportos jóga órákon!</a>')

    def rep(m):
        href = html.unescape(m.group(1))
        if href.startswith('page:'):
            return f'<a href="{esc(link(href[5:]))}"'
        href = after_href(href)
        if href.startswith('http') and 'oliviajoga.hu' not in href:
            return f'<a href="{esc(href)}" target="_blank" rel="noopener"'
        return f'<a href="{esc(href)}"'
    return re.sub(r'<a href="([^"]*)"', rep, h)


def after_href(h):
    """From 26 November (Olivia): every booking link goes to the online site's home page, the passes to the online
    yoga page, „Bejelentkezés & jóga bérlet” to „Bejelentkezés és árak” there."""
    if not AFTER:
        return h
    if 'motibro.com' in h:  # Olivia, 16 November: „minden cta, ami a bejelentkezésre vonatkozik, irányítson a … főoldalára”
        return online('home')
    return h


PLACE_WORDS = re.compile(r'kerület|csepel|pestszentl|lőrinc|prána|korall', re.I)


def after_label(label):
    """From 26 November: a booking button that names a place says „Online jóga bejelentkezés” (Olivia)."""
    if AFTER and 'bejelentkez' in label.lower() and PLACE_WORDS.search(text_of(label)):
        return 'Online jóga bejelentkezés'
    return label


def href_of(h):
    if h.startswith('page:'):
        return link(h[5:])
    return after_href(h)


def ext_attr(h):
    return ' target="_blank" rel="noopener"' if (h.startswith('http') and 'oliviajoga.hu' not in h) or h.startswith('%%ONLINE%%') else ''


# ---------------------------------------------------------------- pictures

# Alt texts (Áron, 2026-11-12: „alt-textek, képleírások, minden legyen kurva jó … ne veszítsen semmit”): what the picture
# shows (content/alt-descriptions.json), then the old site's keywords, tidied up – every old keyword stays.
ALT_DESC = {k: v for k, v in json.load(open(os.path.join(HERE, 'content', 'alt-descriptions.json'), encoding='utf-8')).items()
            if not k.startswith('_')}
ALT_NAMES = [(r'\bkovács[- ]olívia\b', 'Kovács Olívia'), (r'\bkovacs olivia\b', 'Kovács Olívia'), (r'\bjogaoktato\b', 'jógaoktató'),
             (r'\bcsepel\b', 'Csepel'), (r'\bújbud(a)?\b', 'Újbuda'), (r'\bpestszentlőrinc\b', 'Pestszentlőrinc'),
             (r'\bpesterzsébet\b', 'Pesterzsébet'), (r'\bkorall\b', 'Korall'), (r'\bmóri?cz\b', 'Móricz'), (r'\bgellért\b', 'Gellért'),
             (r'\bbartók\b', 'Bartók'), (r'\bmányoki\b', 'Mányoki'), (r'\bprána\b', 'Prána'), (r'\bcsili\b', 'Csili'),
             (r'\bmanas\b', 'Manas'), (r'\bmicroasis\b', 'Microasis'), (r'\bösszetartozás háza\b', 'Összetartozás Háza'),
             (r'\bfitness expo\b', 'Fitness Expo'), (r'\bbudapest\b', 'Budapest'), (r'\bxxi\.', 'XXI.'), (r'\bxx\.', 'XX.'),
             (r'\bjóga-(?=\s)', 'jóga')]


# Áron, 25 November: „a képek alt textjei maradjanak a régiek, csak olyanokat vegyünk ki, ami a 11. kerületre vonatkozik
# (11. kerület, újbuda, gellért-hegy, bartók, móricz, slow flow, buda, budai pk stb)” – she no longer teaches there.
XI_WORDS = [r'\b11\.\s*kerület\b', r'\bújbud(a|ai)?\b', r'\bgellért(-?hegy)?\b', r'\bbartók\b', r'\bmóri?cz\b',
            r'\bmányoki\b', r'\bslow\s+flow\b', r'\bbudai\s+pk\b', r'\bbudai\s+perinatális(\s+központ)?\b', r'\bbudai\b',
            r'\bbuda\b', r'\bbpk\b']


def drop_xi(alt):
    """An old alt without the words of the 11th district (the rest, in its order, stays)."""
    s = alt or ''
    for w in XI_WORDS:
        s = re.sub(w, ' ', s, flags=re.I)
    s = re.sub(r'\bjóga(\s+jóga\b)+', 'jóga', ' '.join(s.split()), flags=re.I)  # „jóga 11. kerület jóga csepel” → „jóga csepel”
    return re.sub(r'\s*\bjóga\s*$', '', s).strip(' ,') if s.strip().lower() != 'jóga' else ''


def alt_keywords(old):
    """The old alt's keywords, readable: names capitalised, the „jóga …” phrases once each, comma-separated."""
    s = ' '.join(drop_xi(old).split())
    for a, b in ALT_NAMES:
        s = re.sub(a, b, s, flags=re.I)
    seen = []
    for part in re.split(r'\s+(?=jóga\b)', s):
        part = part.strip(' ,')
        if part and part.lower() != 'jóga' and part.lower() not in (x.lower() for x in seen):
            seen.append(part)
    return ', '.join(seen)


def better_alt(key, old):
    """The alt text of an old-site picture (key: its file name, or a journal card's photo key)."""
    desc = ALT_DESC.get(key.split('/')[-1], '')
    kw = alt_keywords(old or '')
    if not desc:
        return drop_xi(old)
    if 'Kovács Olívia' in desc:
        kw = re.sub(r'\s*Kovács Olívia,?', '', kw).strip(' ,')
    return f'{desc} – {kw}' if kw else desc


def pic(t, sizes='(min-width: 960px) 40vw, 92vw', eager=False, alt=None, cls='photo'):
    f, ext, w, h = t['file'], t['ext'], t['w'], t['h']
    if f in CUTOUTS:
        ext = '.webp'
    a = better_alt(f, t.get('alt', '')) if alt is None else alt
    W = min(w, 1600)
    H = round(h * W / w)
    small, big = asset(f'img/{f}-800{ext}'), asset(f'img/{f}-1600{ext}')
    srcset = f' srcset="{small} 800w, {big} {W}w" sizes="{sizes}"' if w > 800 else ''
    load = ' fetchpriority="high"' if eager else ' loading="lazy"'
    return f'<img class="{cls}" src="{big if w > 800 else small}"{srcset} alt="{esc(a)}" width="{W}" height="{H}"{load} decoding="async">'


THEME_META = {}


def new_pic(key, sizes='(min-width: 960px) 36vw, 90vw', eager=False, cls='photo', pos='50% 50%'):
    """One of Olivia's Drive photos (assets/img/new)."""
    m = NEW_META[key]
    small, big = asset(f'img/new/{key}-800.jpg'), asset(f'img/new/{key}-1600.jpg')
    srcset = f' srcset="{small} 800w, {big} {m["w"]}w" sizes="{sizes}"' if m['w'] > 800 else ''
    load = ' fetchpriority="high"' if eager else ' loading="lazy"'
    return (f'<img class="{cls}" src="{big if m["w"] > 800 else small}"{srcset} alt="{esc(drop_xi(NEW_PHOTOS[key]))}" width="{m["w"]}" '
            f'height="{m["h"]}" style="object-position:{pos}"{load} decoding="async">')


def card_key(path):
    return 'cikk-' + unicodedata.normalize('NFKD', path).encode('ascii', 'ignore').decode()


# Olivia (2026-11-06, CRM chat): the journal cards show her full photos (tools/images.py cards) instead of the old
# graphics; the old picture's alt text stays (tools/check.py: REPLACED_FILES).
REPLACED_FILES = {}


def card_pic(path, t, sizes='(min-width: 960px) 30vw, 92vw', eager=False):
    """The card picture of a journal post: the new photo if there is one, with the old picture's alt text."""
    key = card_key(path)
    if key not in NEW_META:
        return pic(t, sizes)
    REPLACED_FILES[t['file']] = key
    m = NEW_META[key]
    small, big = asset(f'img/new/{key}-800.jpg'), asset(f'img/new/{key}-1600.jpg')
    srcset = f' srcset="{small} 800w, {big} {m["w"]}w" sizes="{sizes}"' if m['w'] > 800 else ''
    return (f'<img class="photo" src="{big if m["w"] > 800 else small}"{srcset} alt="{esc(better_alt(key, t.get("alt", "")))}" width="{m["w"]}" '
            f'height="{m["h"]}"{" fetchpriority=high" if eager else " loading=lazy"} decoding="async">')


def card_titles(body):
    """One title style on every journal card (Olivia: „egységesítsük a cikkek címeinek formázását”): the first block is
    the title, a heading right after it the subtitle – whatever tag the old page used (the levels stay for Google)."""
    m = re.match(r'\s*<(h[1-6]|p)>', body)
    if not m:
        return body
    body = body[:m.start(1) - 1] + f'<{m.group(1)} class="oj-card__title">' + body[m.end():]
    return re.sub(r'(</(?:h[1-6]|p)>\s*)<(h[1-6])>', r'\1<\2 class="oj-card__sub">', body, count=1)


def theme_pic(key, sizes='(min-width: 960px) 36vw, 90vw', eager=False):
    if key.startswith('new:'):
        return new_pic(key[4:], sizes, eager)
    m = THEME_META[key]
    used.add(key)
    pos = m.get('pos', '50% 50%')
    return (f'<img class="photo" src="{asset("img/theme/" + key + "-1200.jpg")}" srcset="{asset("img/theme/" + key + "-600.jpg")} 600w, '
            f'{asset("img/theme/" + key + "-1200.jpg")} 1200w" sizes="{sizes}" alt="{esc(THEME_PHOTOS[key])}" width="{m["w"]}" '
            f'height="{m["h"]}" style="object-position:{pos}"{" fetchpriority=high" if eager else " loading=lazy"} decoding="async">')


used = set()


def is_divider(t):
    return t['type'] == 'image' and t['w'] / max(t['h'], 1) > 15


# ---------------------------------------------------------------- text blocks

def heading(b, cls=''):
    tag = b['t']
    long = len(norm(text_of(b['html']))) > 70
    classes = ' '.join(c for c in (cls, 'is-long' if long else '') if c)
    attr = f' class="{classes}"' if classes else ''
    return f'<{tag}{attr}>{fix_links(b["html"])}</{tag}>'


def only_link(b):
    """A paragraph that is just one link (the old site's text buttons): (href, label) or None."""
    if b['t'] not in ('p', 'small'):
        return None
    m = re.fullmatch(r'\s*(?:<strong>)?\s*<a href="([^"]*)">(.*?)</a>\s*(?:</strong>)?\s*', b['html'], re.S)
    if not m:
        return None
    return html.unescape(m.group(1)), m.group(2)


def button(href, label, style='orchid', extra=''):
    h = href_of(href)
    return f'<a class="btn btn--{style}{extra}" href="{esc(h)}"{ext_attr(h)}>{after_label(label)}</a>'


TRAILING_BR = re.compile(r'(\s*<br\s*/?>\s*)+$')


def blocks_html(blocks, buttons=True, hcls=''):
    out, row = [], []
    styles = ['orchid', 'ghost']

    def flush():
        if row:
            out.append('<p class="btn-row">' + ''.join(row) + '</p>')
            row.clear()
    for b in blocks:
        t = b['t']
        if t == 'gap':
            continue
        ol = only_link(b) if buttons else None
        if ol:
            row.append(button(ol[0], ol[1], styles[min(len(row), 1)]))
            continue
        flush()
        if t in ('ul', 'ol'):
            items = [TRAILING_BR.sub('', i) for i in b['items']]
            out.append(f'<{t}>' + ''.join(f'<li>{fix_links(i)}</li>' for i in items) + f'</{t}>')
        elif t == 'small':
            out.append(f'<p class="small">{fix_links(b["html"])}</p>')
        elif t == 'p':
            out.append(f'<p>{fix_links(b["html"])}</p>')
        else:
            out.append(heading(b, hcls))
    flush()
    return '\n'.join(out)


def heading_lines(b):
    """The lines of a heading written with <br> (the old site's keyword headings), as plain text."""
    parts = re.split(r'<br\s*/?>', b['html'])
    return [x for x in (norm(text_of(p)) for p in parts) if x]


def seo_heading(b, cls, first_plain=True):
    """A keyword heading (same tag, same words) laid out as a label line and place chips: "csoportos hatha flow jóga
    órák / Budapest 18. kerület / Budapest 21. kerület". Districts are bold, each place line gets a pin."""
    lines = heading_lines(b)
    out = []
    for n, ln in enumerate(lines):
        if n == 0 and first_plain:
            out.append(f'<span class="seo-h__lead">{esc(ln)}</span>')
            continue
        m = re.match(r'(.*?)(\d+\.\s*kerület)(.*)$', ln)
        if m:
            pre, dist, post = m.groups()
            inner = f'{esc(pre)}<strong>{esc(dist)}</strong>{esc(post)}'
        else:
            inner = esc(ln)
        out.append(f'<span class="seo-h__place">{icon("pin")}<span>{inner}</span></span>')
    return f'<{b["t"]} class="seo-h {cls}">' + ' '.join(out) + f'</{b["t"]}>'


def tiles_blocks(tiles):
    """The words of some tiles, in order; a button tile becomes a paragraph that is one link."""
    out = []
    for t in tiles:
        if t['type'] == 'text':
            out.extend(t['blocks'])
        elif t['type'] == 'button':
            out.append({'t': 'p', 'html': f'<a href="{esc(t["href"])}">{esc(t["label"])}</a>'})
    return out


def first_heading(sec):
    for c in sec['columns']:
        for t in c:
            if t['type'] == 'text':
                for b in t['blocks']:
                    if b['t'].startswith('h'):
                        return b
    return None


def only_heading(sec):
    """A section that is just one heading (a section title on the old site)."""
    items = [t for c in sec['columns'] for t in c if not is_divider(t)]
    if len(items) != 1 or items[0]['type'] != 'text':
        return None
    bs = [b for b in items[0]['blocks'] if b['t'] != 'gap']
    return bs[0] if len(bs) == 1 and bs[0]['t'].startswith('h') else None


def title_of(sec):
    """The first section of an old page: its title, a heading or (on some posts) a paragraph."""
    items = [t for c in sec['columns'] for t in c if not is_divider(t)]
    if len(items) == 1 and items[0]['type'] == 'text':
        bs = [b for b in items[0]['blocks'] if b['t'] != 'gap']
        if len(bs) == 1 and b_is_text(bs[0]):
            return bs[0]
    return None


def b_is_text(b):
    return b['t'].startswith('h') or b['t'] == 'p'


def section_text(sec):
    return norm(' '.join(text_of(b.get('html', '') or ' '.join(b.get('items', []))) for b in tiles_blocks([t for c in sec['columns'] for t in c])))


def is_prefooter(sec):
    return any(t['type'] == 'social' for c in sec['columns'] for t in c)


def is_badges(sec):
    imgs = [t for c in sec['columns'] for t in c if t['type'] == 'image']
    return imgs and all(t['file'] in BADGES for t in imgs)


BADGES = set()


# ---------------------------------------------------------------- generic sections

def embed_html(src, title):
    tall = 'docs.google.com/forms' in src or 'motibro' in src
    cls = 'oj-embed oj-embed--form' if tall else 'oj-embed oj-embed--map'
    return f'<div class="{cls}"><iframe src="{esc(src)}" title="{esc(title)}" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>'


def column_html(tiles, sizes, title='Térkép'):
    out, row = [], []

    def flush():
        if row:
            out.append('<p class="btn-row">' + ''.join(row) + '</p>')
            row.clear()
    for t in tiles:
        if is_divider(t) or t['type'] == 'social':
            continue
        if t['type'] == 'button':
            row.append(button(t['href'], t['label'], 'orchid' if not row else 'ghost'))
            continue
        flush()
        if t['type'] == 'image':
            img = pic(t, sizes)
            if t.get('href'):
                h = href_of(t['href'])
                img = f'<a href="{esc(h)}"{ext_attr(h)}>{img}</a>'
            out.append(f'<figure class="oj-figure{" oj-figure--wide" if t["w"] / t["h"] > 2.2 else ""}">{img}</figure>')
        elif t['type'] == 'embed':
            out.append(embed_html(t['src'], title))
        else:
            out.append(f'<div class="oj-prose">{blocks_html(t["blocks"])}</div>')
    flush()
    return '\n'.join(out)


def col_kind(c):
    types = {t['type'] for t in c}
    bs = [b for b in tiles_blocks(c) if b['t'] != 'gap']
    if 'image' in types and types & {'text', 'button'}:
        return 'pair'
    if types <= {'text'} and len(bs) <= 2 and any(b['t'] in ('h1', 'h2') for b in bs):
        return 'quote'
    if types <= {'text', 'button'}:
        return 'text'
    if types == {'image'}:
        return 'media'
    return 'embed' if 'embed' in types else 'other'


def pair_html(c, n, i):
    """One old column with a picture and words: a small icon goes above the words, a photo beside them."""
    img = next(t for t in c if t['type'] == 'image')
    words = [t for t in c if t is not img]
    if img['w'] / img['h'] > 2.5 or img['w'] < 300:
        return f'<div class="oj-story__item" data-reveal><div class="oj-story__icon">{pic(img, "220px")}</div>{column_html(words, "92vw")}</div>', True
    flip = ' is-flip' if (i + n) % 2 else ''
    return (f'<div class="oj-split{flip}" data-reveal><div class="oj-split__media">{column_html([img], "(min-width: 960px) 44vw, 92vw")}</div>'
            f'<div class="oj-split__text">{column_html(words, "92vw")}</div></div>'), False


def generic_section(sec, i):
    cols = [[t for t in c if not is_divider(t) and t['type'] != 'social'] for c in sec['columns']]
    cols = [c for c in cols if c]
    if not cols:
        return ''
    h = only_heading({'columns': cols})
    if h:
        return f'<div class="oj-title" data-reveal>{heading(h, "oj-title__h")}</div>'
    flat = [t for c in cols for t in c]
    if len(flat) == 1 and flat[0]['type'] == 'image':
        return column_html(flat, '(min-width: 1240px) 1100px, 92vw')
    kinds = [col_kind(c) for c in cols]
    # two columns, one of them a map, a form or a picture: side by side
    if len(cols) == 2 and any(k in ('embed', 'media') for k in kinds) and 'quote' not in kinds:
        m = 0 if kinds[0] in ('embed', 'media') else 1
        flip = ' is-flip' if m == 1 and kinds[0] == 'pair' else ''
        return (f'<div class="oj-split oj-split--even{flip}" data-reveal><div class="oj-split__media">{column_html(cols[m], "(min-width: 960px) 44vw, 92vw")}</div>'
                f'<div class="oj-split__text">{column_html(cols[1 - m], "(min-width: 960px) 30vw, 92vw")}</div></div>')
    # otherwise: neighbouring columns of the same kind go together, in the old order
    out, n = [], 0
    groups = []
    for c, k in zip(cols, kinds):
        if groups and groups[-1][0] == k:
            groups[-1][1].append(c)
        else:
            groups.append((k, [c]))
    for k, cs in groups:
        if k == 'pair':
            items = [pair_html(c, n + j, i) for j, c in enumerate(cs)]
            n += len(cs)
            story = [x for x, small in items if small]
            if story:
                out.append(f'<div class="oj-story" style="--n:{min(len(story), 2)}">' + ''.join(story) + '</div>')
            out.extend(x for x, small in items if not small)
        elif k == 'quote':
            out.append(f'<div class="oj-quotes" style="--n:{len(cs)}">' + ''.join(f'<div class="oj-col" data-reveal>{column_html(c, "45vw")}</div>' for c in cs) + '</div>')
        elif k == 'text' and len(cs) > 1 and all(len(bl) == 1 and only_link(bl[0]) for bl in ([b for b in tiles_blocks(c) if b['t'] != 'gap'] for c in cs)):
            links = [only_link([b for b in tiles_blocks(c) if b['t'] != 'gap'][0]) for c in cs]
            out.append('<p class="btn-row" data-reveal>' + ''.join(button(h_, l_, 'orchid' if j == 0 else 'ghost') for j, (h_, l_) in enumerate(links)) + '</p>')
        elif len(cs) > 1:
            out.append(f'<div class="oj-cols" style="--n:{len(cs)}">' + ''.join(f'<div class="oj-col" data-reveal>{column_html(c, "(min-width: 960px) 30vw, 92vw")}</div>' for c in cs) + '</div>')
        else:
            wide = ' oj-block--wide' if any(t['type'] == 'embed' for t in cs[0]) else ''
            out.append(f'<div class="oj-block{wide}" data-reveal>{column_html(cs[0], "(min-width: 1240px) 1100px, 92vw")}</div>')
    return '\n'.join(out)


# ---------------------------------------------------------------- page chrome

def page_head(title_block, path, crumbs, lead_html='', photo_html='', tone='lilac', long=False, extra=''):
    cr = ''.join(f'<li><a href="{esc(link(p))}">{esc(n)}</a></li>' for p, n in crumbs)
    tag = title_block['t']
    title = f'<{tag} class="page-head__title">{fix_links(title_block["html"])}</{tag}>'
    photo = f'<div class="page-head__photo"><div class="frame">{photo_html}</div></div>' if photo_html else ''
    return (f'<header class="page-head page-head--{tone}{" page-head--long" if long else ""}{" " + extra if extra else ""}"><div class="container page-head__grid'
            f'{"" if photo else " oj-head--solo"}"><div class="page-head__text"><nav class="crumbs" aria-label="Morzsamenü"><ol>{cr}'
            f'<li aria-current="page">{esc(norm(text_of(title_block["html"])))}</li></ol></nav>{title}{lead_html}</div>{photo}</div></header>')


def prefooter(p):
    """The block every old page ends with: the weekly classes, social links, the Fitmummy badge and payment logos."""
    secs = p['sections']
    pre = next((s for s in secs if is_prefooter(s)), None)
    badges = next((s for s in secs if is_badges(s)), None)
    if not pre:
        return ''
    slots, statement = [], ''
    for c in pre['columns']:
        texts = [t for t in c if t['type'] == 'text']
        if not texts:
            continue
        bs = [b for b in tiles_blocks(texts) if b['t'] != 'gap']
        if len(bs) == 1:
            # Olivia (11.12): „ide fogalmazzuk bele” – the yogic way of life, under the big sentence
            statement = heading(bs[0], 'oj-pre__statement') + f'<p class="oj-pre__more">{esc(YOGIC_LIFE)}</p>'
            continue
        h = bs[0]
        rows = []
        for b in bs[1:]:
            ol = only_link(b)
            if ol:
                rows.append(f'<p class="oj-pre__go">{button(ol[0], ol[1], "dark", " btn--sm")}</p>')
            else:
                rows.append(f'<p class="oj-pre__time">{icon("clock")}<span>{fix_links(b["html"])}</span></p>')
        slots.append(f'<div class="oj-pre__slot">{heading(h)}{"".join(rows)}</div>')
    if AFTER:  # the weekly classes are online now; the old places stay in words
        was = ' · '.join(norm(text_of(re.search(r'<h\d[^>]*>(.*?)</h\d>', x).group(1))) for x in slots if re.search(r'<h\d', x))
        slots = [f'<div class="oj-pre__slot"><h3>Élő online hatha flow jóga</h3><p class="oj-pre__time">{icon("clock")}<span>szerda 18:30 – 20:00 (90 perc)</span></p>'
                 f'<p class="oj-pre__go">{button(online("yoga"), "Online jóga bejelentkezés", "dark", " btn--sm")}</p></div>',
                 f'<div class="oj-pre__slot"><h3>Online kismamatorna</h3><p class="oj-pre__time">{icon("clock")}<span>egyéni időpontban (60 perc)</span></p>'
                 f'<p class="oj-pre__go">{button(online("prenatal"), "Online kismamatorna", "dark", " btn--sm")}</p></div>']
        if was:
            slots.append(f'<p class="oj-pre__was">2026. november 25-ig: {esc(was)}</p>')
    social = (f'<p class="social"><a href="{INSTAGRAM}" target="_blank" rel="noopener">{icon("ig")}<span class="sr-only">Instagram</span></a>'
              f'<a href="{FACEBOOK}" target="_blank" rel="noopener">{icon("fb")}<span class="sr-only">Facebook</span></a>'
              f'<a href="mailto:{EMAIL}">{icon("mail")}<span class="sr-only">Email</span></a></p>')
    badge_html = ''
    if badges:
        imgs = [t for c in badges['columns'] for t in c if t['type'] == 'image']
        figs = ''.join(f'<figure>{pic(t, "360px")}</figure>' for t in imgs if t['file'] not in REMOVED_FILES)
        if AFTER:  # Olivia: the logos of every way to pay that Stripe allows (the online site pays with Stripe)
            figs += ('<figure class="oj-paywith"><figcaption>Biztonságos online fizetés</figcaption><ul class="oj-paylist">'
                     + ''.join(f'<li><img src="{asset("img/pay/" + k + ".svg")}" width="38" height="24" alt="{esc(n)}" loading="lazy"></li>' for k, n in PAY_LOGOS)
                     + '</ul></figure>')
        else:
            figs += (f'<figure><img class="oj-pay" src="{asset("img/barion-fizetes.svg")}" width="756" height="108" loading="lazy" '
                     f'alt="Fizetés Barionnal: bankkártya, Apple Pay, Google Pay"></figure>')
        badge_html = '<div class="oj-badges">' + figs + '</div>'
    badges_sec = f'<section class="oj-badges-wrap" aria-label="Partnerek"><div class="container">{badge_html}</div></section>' if badge_html else ''
    return (f'<section class="section section--tight oj-pre oj-pre--peach" aria-label="Órarend"><div class="container">'
            f'<div class="oj-pre__grid"><div class="oj-pre__lead">{statement}{social}</div><div class="oj-pre__slots">{"".join(slots)}</div></div>'
            f'</div></section>{badges_sec}')


PAY_LOGOS = [('visa', 'Visa'), ('master', 'Mastercard'), ('maestro', 'Maestro'), ('american_express', 'American Express'),
             ('jcb', 'JCB'), ('unionpay', 'UnionPay'), ('apple_pay', 'Apple Pay'), ('google_pay', 'Google Pay'), ('stripe', 'Stripe')]


def nav_items():
    """The menu: after 25 November „Bejelentkezés & jóga bérlet” opens „Bejelentkezés és árak” on the online site and „Online
    kismamatorna” is new (Olivia)."""
    if not AFTER:
        return [(link(p), n) for p, n in NAV]
    items = [(link(p), n) for p, n in NAV]  # link(): „Bejelentkezés & jóga bérlet” and „Kapcsolat” go to the online site
    items.insert(len(items) - 1, (online('classes'), 'Online órák'))  # Olivia, 16 November: „az online órák is legyen új menüpont”
    items.insert(len(items) - 1, (online('prenatal'), 'Online kismamatorna'))
    return items


def notice_html():
    words = ('Óráimat 2026. november 25-ig tartottam. Élő online jógán gyakorolhatsz velem ' if AFTER
             else NOTICE + ' Utána élő online jógán gyakorolhatsz velem ') + ONLINE_TIME + '.'
    return (f'<div class="oj-notice-bar"><div class="container"><p>{esc(words)} '
            f'<a class="oj-notice-bar__go" href="{esc(online())}" target="_blank" rel="noopener">oliviajoga.online {icon("arrow")}</a></p></div></div>')


def header(path):
    here = link(path if path != 'főoldal' else '')

    def item(href, n):
        cur = ' aria-current="page"' if href == here else ''
        ext = '' if href.startswith(BASE) or href.startswith('%%HOME%%') else ' target="_blank" rel="noopener"'
        return f'<li><a href="{esc(href)}"{cur}{ext}>{esc(n)}</a></li>'
    desk = ''.join(item(h, n) for h, n in nav_items()[1:])
    mob = ''.join(item(h, n) for h, n in nav_items())
    return f'''<a class="skip-link" href="#main">Ugrás a tartalomra</a>
{notice_html()}
<header class="site-header" data-header>
	<div class="container site-header__inner">
		<a class="brand oj-brand" href="{esc(link(''))}" rel="home" aria-label="Olívia jóga – Kovács Olívia, főoldal">{logo()}</a>
		<nav class="nav" aria-label="Fő"><ul class="menu">{desk}</ul></nav>
		<a class="btn btn--orchid btn--sm header-cta" href="{esc(book_url())}" target="_blank" rel="noopener">Bejelentkezés</a>
		<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="drawer" data-nav-toggle>
			<span class="i-open">{icon("menu")}</span><span class="i-close">{icon("close")}</span><span class="sr-only">Menü</span>
		</button>
	</div>
</header>
<div class="drawer" id="drawer">
	<nav aria-label="Mobil"><ul class="menu">{mob}</ul></nav>
	<p class="btn-row"><a class="btn btn--orchid" href="{esc(book_url())}" target="_blank" rel="noopener">Bejelentkezés</a></p>
	<p class="drawer__contact"><a href="mailto:{EMAIL}">{EMAIL}</a></p>
</div>'''


LOGO_SVG = ''


def logo():
    """Olivia's logo (assets/brand/logo.svg, tools/logo.py): inline, so it takes the text colour."""
    return LOGO_SVG.replace('<svg ', '<svg class="oj-logo" aria-hidden="true" focusable="false" ', 1).replace(' role="img" aria-label="Olívia jóga"', '')


def footer():
    links = ''.join(f'<li><a href="{esc(h)}">{esc(n)}</a></li>' for h, n in nav_items())
    # WordPress theme: the privacy notice and the cookie settings (the bar is the theme's, inc/consent.php)
    legal = (f' · <a href="{esc(link(PRIVACY_PATH))}">Adatkezelési tájékoztató</a> · <a href="#cookie-settings">Sütibeállítások</a>'
             if WP else '')
    return f'''<footer class="site-footer">
	<div class="container">
		<div class="footer__grid">
			<div class="footer__brand">
				<a class="oj-foot-logo" href="{esc(link(''))}" aria-label="Olívia jóga – főoldal">{logo()}</a>
				<p class="btn-row"><a class="btn btn--orchid" href="{esc(book_url())}" target="_blank" rel="noopener">Bejelentkezés</a></p>
			</div>
			<nav aria-label="Oldalak" class="oj-foot-nav"><h2>Oldalak</h2><ul>{links}</ul></nav>
			<div><h2>Kapcsolat</h2>
				<ul class="footer__contact"><li>{icon("mail")}<a href="mailto:{EMAIL}">{EMAIL}</a></li><li>{icon("ig")}<a href="{INSTAGRAM}" target="_blank" rel="noopener">Instagram</a></li><li>{icon("fb")}<a href="{FACEBOOK}" target="_blank" rel="noopener">Facebook</a></li></ul>
			</div>
		</div>
		<div class="footer__bottom"><p>&copy; 2026 Kovács Olívia{legal} · <span class="footer__credit">Készítette: <a href="https://helloprovision.com/" target="_blank" rel="noopener">HelloProVision</a></span></p></div>
	</div>
</footer>
<a class="book-fab" href="{esc(book_url())}" target="_blank" rel="noopener" data-book-fab>{icon("cal")}Bejelentkezés</a>'''


# Pages whose old text has no sentence long enough for a search snippet (the old site had no meta descriptions).
DESC = {
    'kapcsolat': ('Írj nekem, ha kérdésed van a jógaórákról, a bérletekről, az egyéni óráról vagy az ajándékutalványról: '
                  'Kovács Olívia jógaoktató, Budapest 18. és 21. kerület.'),
    'mit-ad-a-jóga': ('Mit ad a jóga? Ráhangolódás, flow, erő, nyújtás, légzőgyakorlatok, relaxáció és meditáció – amit egy '
                      'átlagos jógagyakorlás tanít nekem.'),
}
DESC_AFTER = {
    'kapcsolat': ('Írj nekem, ha kérdésed van az élő online jógaórákról, az online kismamatornáról, az egyéni óráról vagy az '
                  'ajándékutalványról: Kovács Olívia jógaoktató.'),
}


def description(p):
    if AFTER and p['path'] in DESC_AFTER:
        return DESC_AFTER[p['path']]
    if p['path'] in DESC:
        return DESC[p['path']]
    for s in p['sections']:
        for c in s['columns']:
            for t in c:
                if t['type'] != 'text':
                    continue
                for b in t['blocks']:
                    if b['t'] == 'p':
                        tx = norm(text_of(b['html']))
                        if AFTER and 'november 25-ig tartom' in tx:  # the „Fontos!” words of before 26 November
                            return ('Óráimat 2026. november 25-ig tartottam. Élő online jógán gyakorolhatsz velem '
                                    + ONLINE_TIME + ', otthonról.')
                        if len(tx) > 60:
                            if len(tx) <= 158:
                                return tx
                            cut = tx[:155].rsplit(' ', 1)[0].rstrip(',;:–-')
                            return cut + '…'
    return SITE_TITLE


def document(p, body, cls, schema, image=None):
    path = p['path']
    canon = ORIGIN + '/' + ascii_slug(path if path != 'főoldal' else '')
    desc = description(p)
    robots = '<meta name="robots" content="noindex, nofollow">\n' if PREVIEW else ''
    image = image or first_image(body)
    if image.startswith('/'):
        image = ORIGIN + image
    og_img = f'<meta property="og:image" content="{esc(image)}">\n' if image else ''
    v = VERSION
    return f'''<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{esc(p["title"])}</title>
<meta name="description" content="{esc(desc)}">
<link rel="canonical" href="{esc(canon)}">
{robots}<meta name="theme-color" content="#2B5036">
<meta property="og:site_name" content="{esc(SITE_TITLE)}"><meta property="og:locale" content="hu_HU"><meta property="og:type" content="{"article" if cls == "is-post" else "website"}">
<meta property="og:title" content="{esc(p["title"])}"><meta property="og:description" content="{esc(desc)}"><meta property="og:url" content="{esc(canon)}">
{og_img}<link rel="icon" href="{asset("icons/favicon.svg")}" type="image/svg+xml"><link rel="icon" href="{asset("icons/favicon-48.png")}" sizes="48x48" type="image/png"><link rel="icon" href="{asset("icons/favicon-96.png")}" sizes="96x96" type="image/png"><link rel="icon" href="{asset("icons/favicon-192.png")}" sizes="192x192" type="image/png"><link rel="apple-touch-icon" href="{asset("icons/apple-touch-icon.png")}">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&amp;family=Quicksand:wght@400..700&amp;display=swap">
<link rel="stylesheet" href="{asset("site.css")}?v={v}">
<link rel="stylesheet" href="{asset("oliviajoga.css")}?v={v}">
<script type="application/ld+json">{json.dumps(schema, ensure_ascii=False)}</script>
</head>
<body class="{cls}">
{header(path)}
<main id="main">
{body}
</main>
{prefooter(p)}
{footer()}
<script src="{asset("site.js")}?v={v}" defer></script>
<script src="{asset("oliviajoga.js")}?v={v}" defer></script>
</body>
</html>
'''


# Kovács Olívia – the same facts as on the online site (wordpress/olivia-yoga/inc/seo.php, Person), so the language models
# and search engines see one person on both domains (Áron, 11.18: „az AI-ok … ajánlják Oliviát”).
OLIVIA = {
    'job': 'Jógaoktató, pre- és postnatális tréner',
    'desc': ('Hatha jógaoktató (Yoga Alliance International, 2023), pre- és postnatális tréner (Fitmummy), tibeti hangtál- és '
             'hangmasszázs-terapeuta; 2015-től főállású edző, 2018-tól masszőr. Élő online jógaórákat és online kismamatornát '
             'tart magyarul.'),
    'knows': ['Online jóga', 'Hatha jóga', 'Flow jóga', 'Kismamatorna', 'Várandós torna', 'Gátizomtorna', 'Stretching',
              'Mobilizáció', 'Légzőgyakorlatok', 'Relaxáció', 'Meditáció', 'Yoga', 'Prenatal fitness'],
    'cred': [{'@type': 'EducationalOccupationalCredential', 'name': 'Hatha jógaoktató (2023)', 'credentialCategory': 'certificate',
              'recognizedBy': {'@type': 'Organization', 'name': 'Yoga Alliance International'}},
             {'@type': 'EducationalOccupationalCredential', 'name': 'Pre- és postnatális tréner', 'credentialCategory': 'certificate',
              'recognizedBy': {'@type': 'Organization', 'name': 'Fitmummy'}},
             {'@type': 'EducationalOccupationalCredential', 'name': 'Tibeti hangtál- és hangmasszázs-terapeuta', 'credentialCategory': 'certificate'}],
}
# The classes (all on the online site); name → address there.
SERVICES = [('Csoportos online flow jóga', 'yoga'), ('Egyéni online jóga', 'private'), ('Online kismamatorna', 'prenatal'),
            ('Online stretching és mobilizáció', 'stretching'), ('Relaxáció és légzés', 'relax')]
BUSINESS_DESC = ('Kovács Olívia jógaórái: élő, magyar nyelvű online jógaórák az oliviajoga.online oldalon (csoportos online flow '
                 'jóga, egyéni online jóga, online kismamatorna, stretching és mobilizáció, relaxáció és légzés); 2026. november '
                 '25-ig csoportos hatha flow jóga Budapest XVIII. és XXI. kerületében.')


def schema_base(p, extra=None):
    person = {'@type': 'Person', '@id': ORIGIN + '/#olivia', 'name': 'Kovács Olívia', 'jobTitle': OLIVIA['job'],
              'description': OLIVIA['desc'], 'knowsAbout': OLIVIA['knows'], 'hasCredential': OLIVIA['cred'],
              'url': ONLINE + '/rolam/', 'email': EMAIL, 'knowsLanguage': 'hu',
              'sameAs': [INSTAGRAM, FACEBOOK, ORIGIN + '/' + ascii_slug('rólam')], 'worksFor': {'@id': ORIGIN + '/#business'}}
    # Áron, 25 November (an AI answer linked her studio to the old site and to a booking system she does not use): „A Kovács
    # Olívia Jógastúdiójára a .online legyen linkelve, az az elsődleges oldal. a .hu csak a seo miatt él illetve amiatt, hogy
    # a régi vendégek odataláljanak a .onlinera” – the business's address is the online site, this one is its old site.
    business = {'@type': 'Organization', '@id': ORIGIN + '/#business', 'name': 'Olívia jóga',
                'alternateName': ['Kovács Olívia jóga', 'Olívia Jóga – Online'], 'url': ONLINE + '/',
                'email': EMAIL, 'description': BUSINESS_DESC, 'founder': {'@id': ORIGIN + '/#olivia'},
                'areaServed': {'@type': 'Country', 'name': 'Hungary'}, 'knowsLanguage': 'hu',
                'sameAs': [ORIGIN + '/', INSTAGRAM, FACEBOOK],
                'hasOfferCatalog': {'@type': 'OfferCatalog', 'name': 'Jógaórák', 'itemListElement': [
                    {'@type': 'Offer', 'itemOffered': {'@type': 'Service', 'name': n, 'url': ONLINE.rstrip('/') + ONLINE_PATHS[k],
                                                        'provider': {'@id': ORIGIN + '/#olivia'}, 'availableChannel': {
                                                            '@type': 'ServiceChannel', 'serviceUrl': ONLINE.rstrip('/') + ONLINE_PATHS[k]}}}
                    for n, k in SERVICES]}}
    graph = [{'@type': 'WebSite', '@id': ORIGIN + '/#website', 'url': ORIGIN + '/', 'name': SITE_TITLE, 'inLanguage': 'hu-HU',
              'publisher': {'@id': ORIGIN + '/#business'}}, business, person]
    path = (p or {}).get('path', '')
    if path not in ('', 'főoldal', '404'):
        t = (p or {}).get('title', '')
        own = re.search(r'Kovács Olívia\s*[-–]\s*(.+)$', t)  # „Jóga 18. és 21. kerület | Kovács Olívia - Pránajáma”: the page's own part
        name = dict(NAV).get(path) or norm(re.sub(r'\s+\?$', '?', own.group(1)) if own else re.sub(r'\s*\|.*$', '', t))
        graph.append({'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': 1, 'name': 'Főoldal', 'item': ORIGIN + '/'},
            {'@type': 'ListItem', 'position': 2, 'name': name, 'item': ORIGIN + '/' + ascii_slug(path)}]})
    if extra:
        graph.append(extra)
    return {'@context': 'https://schema.org', '@graph': graph}


def llms_txt(journal_cards):
    """/llms.txt for the WordPress theme (llmstxt.org; iu_theme's iu_llms_txt filter): who Olivia is, the facts, the pages
    with their own descriptions, the articles once each, the online site's classes. Placeholders: the theme fills them in.
    Only facts that are on the two sites."""
    t = norm(BUSINESS_DESC)
    lines = ['# Olívia jóga – Kovács Olívia jógaoktató', '',
             '> Kovács Olívia hatha flow jógaoktató és pre- és postnatális tréner. Élő, magyar nyelvű online jógaórákat tart '
             'az oliviajoga.online oldalon.' + ('' if AFTER else ' 2026. november 25-ig csoportos hatha flow jógaórákat tart '
                                                 'Budapest XVIII. és XXI. kerületében.'), '',
             # Áron, 25 November: „A Kovács Olívia Jógastúdiójára a .online legyen linkelve, az az elsődleges oldal. a .hu csak
             # a seo miatt él illetve amiatt, hogy a régi vendégek odataláljanak a .onlinera.”
             ('- Elsődleges, aktuális oldala: ' + ONLINE + '/ – az órák, az időpontok, a bérletek, a foglalás és a bejelentkezés '
              'csak ott (saját fiókkal; más foglalási rendszert nem használ). Az oliviajoga.hu a korábbi oldala: a jógáról szóló '
              'írásai és a régi vendégeinek útmutató az online oldalra.' if AFTER else
              '- Elsődleges, aktuális oldala: ' + ONLINE + '/ – az online órák, időpontok, bérletek és a foglalás ott. Az '
              'oliviajoga.hu a korábbi oldala: a jógáról szóló írásai és a régi vendégeinek útmutató az online oldalra.'),
             '- Végzettség: hatha jógaoktató (Yoga Alliance International, 2023), pre- és postnatális tréner (Fitmummy), tibeti '
             'hangtál- és hangmasszázs-terapeuta.',
             '- Órák (online, magyarul): ' + ', '.join(n.lower() if i else n for i, (n, _) in enumerate(SERVICES)) + '. Foglalás: '
             + online('home'),
             ('- Csoportos online flow jóga: ' + ONLINE_TIME + '.'),
             ]
    if not AFTER:
        lines.append('- Termes órák 2026. november 25-ig: Budapest XVIII. kerület (Prána Jóga és Életmód Központ) és XXI. kerület '
                     '(Korall Sportsziget).')
    # Áron, 25 November: „A Budai Perinatális Központ csapatában is csak voltam, már nem, de múltba beleírhatjuk.”
    lines.append('- Korábban: termes csoportos órák Budapesten 2026. november 25-ig (XVIII. kerület: Prána Jóga és Életmód Központ, '
                 'XXI. kerület: Korall Sportsziget); régebben a Budai Perinatális Központ csapatában is tanított. Ma csak online '
                 'tanít, az oliviajoga.online-on.' if AFTER else
                 '- Korábban a Budai Perinatális Központ csapatában is tanított.')
    lines += ['- Kapcsolat: ' + EMAIL + ' · Instagram: ' + INSTAGRAM + ' · Facebook: ' + FACEBOOK, '- Nyelv: magyar.', '',
              '## Oldalak']
    line = lambda title, url, desc: f'- [{title.replace("[", "(").replace("]", ")")}]({url}): {desc}'.rstrip(': ')
    for path, name in NAV:
        if path == '' or path in DELETED_PAGES or path not in PAGES:
            continue
        pg = dict(PAGES[path], path=path)
        lines.append(line(name, link(path), description(pg) or ''))
    lines += ['', '## Online órák (oliviajoga.online)']
    for n, k in SERVICES:
        lines.append(line(n, online(k), ''))
    lines += [line('Bejelentkezés és árak', online('prices'), ''), line('Kapcsolat', online('contact'), ''), '',
              '## Jógáról – cikkek']
    seen = {}
    for path, head, _ in journal_cards:
        if path not in PAGES or path in DELETED_PAGES:
            continue
        pg = dict(PAGES[path], path=path)
        title = norm(head) or norm(re.sub(r'\s*\|.*$', '', pg['title']))
        part = re.search(r'<strong>(\d+\.\s[^<]+?)\s*</strong>', json.dumps(PAGES[path], ensure_ascii=False))
        if part and norm(part.group(1)) not in title:  # a series part („1. Milyen elemekből…”, „A jóga 8 ága ॐ”): its topic too
            title = re.sub(r'^\d+\.\s*', '', title) + ' ' + norm(part.group(1))
        seen[title] = seen.get(title, 0) + 1
        lines.append((path, title, description(pg) or ''))
    out = []
    for item in lines:
        if isinstance(item, tuple):
            path, title, desc = item
            if seen[title] > 1:  # the same title on several articles (A jóga 8 ága …): the address tells them apart
                title += ' – ' + path.replace('-', ' ').capitalize()
            item = line(title, link(path), desc)
        out.append(item)
    out += ['', '## Optional', line('Adatkezelési tájékoztató', link(PRIVACY_PATH), '')]
    return '\n'.join(out) + '\n'


def wrap(inner, cls='section'):
    return f'<section class="{cls}"><div class="container">{inner}</div></section>'


# ---------------------------------------------------------------- pages

def find(p, pred):
    for i, s in enumerate(p['sections']):
        if pred(s):
            return i, s
    return None, None


def by_heading(p, text):
    return find(p, lambda s: (first_heading(s) or {}).get('html') and text in norm(text_of(first_heading(s)['html'])))


GIFT_ONLINE = ('Az ajándékutalványt online, bankkártyával vásárolhatod meg. E-mailben kapja meg az, akinek szánod, egy kóddal, '
               'amit a saját fiókjában vált be, és élő online jógaórára foglalhat vele.')
FAQ_AFTER = {  # Olivia (11.12), from 26 November
    'Mit kell hoznom a jóga órára?': ['Csak egy jógaszőnyegre, esetleg egy kis vízre és kényelmes ruhára van szükség. Ha van jógahevedered, '
                                      'használd bátran, ha pedig nincs, egy vékonyabb anyagú törölköző segítségedre lehet.'],
    'Mivel tudom fizetni a jóga órát?': ['Online foglaláskor bankkártyával tudsz fizetni.'],
}
KORSZAK_TEXT = ('2026 decemberétől, 3 év után egy korszak lezárult, rengeteg szép jógás kapcsolódással és emlékkel, '
                'és innentől más formában folytathatjuk.')
YOGIC_LIFE = 'Közben hétköznapi példákon keresztül, elméleti és gyakorlati síkon is jobban megismerkedhetsz a jógikus életvitellel.'
YOGA_90 = ('A gyakorlás alatt részed lesz légzőgyakorlatban, erősítésben, nyújtásban, egyensúlyozásban, flow élményben és relaxációban '
           'egyaránt, közben pedig hétköznapi példákon keresztül, elméleti és gyakorlati síkon is jobban megismerkedhetsz a jógikus '
           'életvitellel. Az órák csoportosak, de senkinek nem kell „szerepelnie”: ha szeretnéd, beszélgethetünk és kapcsolódhatunk is.')
PRENATAL_TEXT = ('Az online kismamatorna egyéni óra, de jöhetsz akár a barátnőddel is. Ez a 60 perc minden kismamánál és minden '
                 'alkalommal egyénre szabott: a jelenlegi állapotodtól és hangulatodtól is függ, és a gyakorlatokat az is meghatározza, '
                 'éppen hányadik trimeszterben, akár hányadik hétben jársz. Minden órát egy pár perces felmérés, beszélgetés előz meg '
                 'arról, hogyan érzed magad, milyen az állapotod.')
NB = '\u00a0'
YOGA_PRICES = [('1 alkalom', f'3{NB}000 Ft', '90 perc', [('Napi online jóga jegy vásárlás', 'yoga'), ('1 alkalmas online jóga ajándékutalvány vásárlás', 'gift')]),
               ('5 alkalom', f'13{NB}000 Ft', '90 perc · 8 hétig érvényes', [('5 alkalmas online jóga bérlet vásárlás', 'yoga'), ('5 alkalmas online jóga ajándékutalvány vásárlás', 'gift')])]
# „Árakat frissítsd” (Olivia, 16 November): the online site's prices
PRENATAL_PRICES = [('1 alkalom', f'8{NB}000 Ft', '60 perc, egyéni'), ('5 alkalmas bérlet', f'35{NB}000 Ft', 'lejárat nélkül'),
                   ('1 alkalom barátnővel', f'12{NB}000 Ft', 'kettőtöknek'), ('5 alkalom barátnővel', f'52{NB}000 Ft', 'kettőtöknek, lejárat nélkül')]


def services_html(title='Egyéni online órák'):
    """Every one-to-one online service with its prices in a fold (as on oliviajoga.online's prices page), each with
    „Időpontot kérek” (her account's request form, the service filled in) and the class page."""
    out = ''
    for name, key, topic, rows in ONLINE_SERVICES:
        lens = sorted({r[1] for r in rows}, key=lambda x: int(x.split()[0]))
        frm = min(int(r[2].replace('\u00a0', '').replace(' ', '').replace('Ft', '')) for r in rows)
        trs = ''.join(f'<tr><th scope="row">{esc(w)}<small>{esc(l)}</small></th><td>{esc(one)}</td><td>{esc(five)}</td></tr>' for w, l, one, five in rows)
        ask = online('request') + '&topic=' + topic
        out += (f'<details class="oj-svc"><summary><span class="oj-svc__name">{esc(name)}</span>'
                f'<span class="oj-svc__meta">{esc(" / ".join(lens))} · {f"{frm:,}".replace(",", NB)} Ft-tól</span><span class="oj-svc__plus" aria-hidden="true"></span></summary>'
                f'<div class="oj-svc__body"><table class="oj-svc__table"><thead><tr><th></th><th>1 alkalom</th><th>5 alkalom</th></tr></thead><tbody>{trs}</tbody></table>'
                f'<p class="btn-row">{button(ask, "Időpontot kérek", "orchid")}'
                f'<a class="text-link" href="{esc(online(key))}"{ext_attr(online(key))}>Bővebben az óráról</a></p></div></details>')
    return (f'<section class="price-group oj-svcs" data-reveal><header class="price-group__head"><p class="kicker">oliviajoga.online</p>'
            f'<h3>{esc(title)}</h3><p class="price-group__text">Négyszemközt vagy párban, élőben, online. Időpontot a fiókodban kérhetsz, és hamarosan visszaírok.</p></header>'
            f'<div class="oj-svcs__list">{out}</div></section>')


def online_words():
    lead = 'Élő online jógán gyakorolhatsz velem' if AFTER else 'A továbbiakban élő online jógán gyakorolhatsz velem'
    return f'<p class="oj-online__words">{lead}: <strong>{ONLINE_TIME}</strong>, otthonról, a saját jógaszőnyegeden.</p>'


def online_buttons(extra=''):
    return (f'<p class="btn-row oj-online__go">{button(online("yoga"), "Élő online jóga", "orchid", " btn--lg")}'
            f'{button(online(), "oliviajoga.online " + icon("arrow"), "ghost", " btn--lg")}{extra}</p>')


def online_boxes():
    """From 26 November (Olivia): instead of the places and their timetables, the online yoga and the online prenatal
    class, with time, prices and a button."""
    yoga_prices = ''.join(f'<li><b>{a}</b><span>{esc(c)}{" · " + esc(u.split(" · ")[1]) if " · " in u else ""}</span></li>' for c, a, u, _ in YOGA_PRICES)
    return (f'<article class="oj-loc oj-loc--pink oj-loc--online" data-reveal><p class="oj-loc__name">Élő online jóga</p>'
            f'<p class="oj-loc__seo">Hatha flow jóga online</p>'
            f'<ul class="oj-slots"><li class="oj-slot"><span>szerda</span><span>18:30 – 20:00</span><span>90 perc</span></li></ul>'
            f'<ul class="oj-loc__prices">{yoga_prices}</ul>'
            f'<p class="btn-row">{button(online("yoga"), "Online jóga bejelentkezés", "dark")}</p></article>'
            # Áron, 26 November (the prenatal box on the home page): „a .hu-n ennek a helyére a főoldalon inkább a privát jóga
            # árait tegyük be” – the online site's one-to-one yoga prices (ONLINE_SERVICES), alone and in pairs, 60 and 90 min
            f'<article class="oj-loc oj-loc--lilac oj-loc--online" data-reveal><p class="oj-loc__name">Privát online jóga</p>'
            f'<p class="oj-loc__seo">Privát jóga online, egyénileg</p>'
            f'<ul class="oj-slots"><li class="oj-slot"><span>egyéni időpontban</span><span>60 vagy 90 perc</span></li></ul>'
            f'<ul class="oj-loc__prices">{private_prices()}</ul>'
            f'<p class="btn-row">{button(online("private"), "Privát online jóga", "dark")}</p></article>')


def private_prices():
    """The one-to-one online yoga's prices, from ONLINE_SERVICES: one session and the 5-session pass, alone and in pairs."""
    rows = []
    for who, length, one, five in next(x for x in ONLINE_SERVICES if x[1] == 'private')[3]:
        pair = who == 'Párban'
        rows.append(('1 alkalom' + (' párban' if pair else ''), one, length))
        rows.append(('5 alkalom párban' if pair else '5 alkalmas bérlet', five, length))
    return ''.join(f'<li><b>{a.replace(" ", NB)}</b><span>{esc(c)} · {esc(u.replace(" ", NB))}</span></li>' for c, a, u in rows)


def was_strip(heads_html):
    """Where the classes were until 25 November – the old headings (18. és 21. kerület, Csepel, Prána, Korall) stay."""
    return (f'<div class="oj-was" data-reveal><p class="oj-was__lead">2026. november 25-ig itt tartottam az óráimat:</p>'
            f'<div class="oj-was__places">{heads_html}</div>'
            f'<p class="oj-was__lead">Innentől élő online jógán folytathatjuk, otthonról.</p></div>')


def korszak_section():
    """Olivia (11.12): „egy korszak lezárult” – her photos of three years of classes, the wave over them, and the way on."""
    keys = sorted(k for k in NEW_META if k.startswith('korszak-'))
    cells = ''.join(f'<figure class="oj-korszak__cell oj-korszak__cell--{n % 7}" style="--i:{n}">{new_pic(k, "(min-width: 960px) 20vw, 33vw")}</figure>'
                    for n, k in enumerate(keys))
    return wrap(f'<div class="oj-korszak" data-reveal><div class="oj-korszak__text"><p class="kicker">3 év közös gyakorlás</p>'
                f'<h2 class="oj-h2">Egy korszak {"lezárult" if AFTER else "lezárul"}</h2><p class="oj-lead">{esc(KORSZAK_TEXT if AFTER else KORSZAK_TEXT.replace("lezárult", "lezárul"))}</p>'
                f'<p class="oj-korszak__svcs">{esc(SERVICES_LINE)}</p>'
                f'<p class="btn-row">{button(online(), "Jóga online", "orchid", " btn--lg")}</p>'
                f'</div>'
                f'<div class="oj-korszak__grid">{cells}{band("pink", True)}</div></div>', 'section oj-korszak-wrap')


def home(p):
    secs = p['sections']
    used_idx = set()

    def take(i):
        used_idx.add(i)
        return secs[i]

    i0, s0 = find(p, lambda s: only_heading(s) and 'jóga' in text_of(only_heading(s)['html']).lower() and 'Flow' in text_of(only_heading(s)['html']))
    title_b = only_heading(take(i0))
    words = norm(text_of(title_b['html'])).split(' ')
    i1 = i0 + 1
    hero_links = [only_link(b) for b in tiles_blocks([t for c in take(i1)['columns'] for t in c])]
    # the two places are equal: the same button for both (Olivia)
    # Olivia (11.12): the two buttons green; from 26 November: online booking and the online prenatal class
    if AFTER:
        btns = button(online(), 'Online jóga bejelentkezés', 'forest', ' btn--lg') + button(online('prenatal'), 'Online kismamatorna', 'forest', ' btn--lg')
    else:
        btns = ''.join(button(h, l, 'forest', ' btn--lg') for h, l in (x for x in hero_links if x))
    hero = f'''<section class="hero oj-hero" aria-labelledby="hero-title">
	<div class="container hero__grid">
		<div class="hero__text">
			<p class="kicker">{esc(SITE_TITLE)}</p>
			<{title_b["t"]} class="hero__title" id="hero-title"><span class="hero__l1">{esc(words[0])}</span> <span class="hero__l2"><mark class="oj-breath">{esc(" ".join(words[1:]))}</mark></span></{title_b["t"]}>
			<p class="btn-row oj-twins">{btns}</p>
		</div>
		<div class="hero__visual">
			{blob_soft("pink", "right")}
			<div class="frame hero__photo oj-hero-photo">{new_pic("hero", "(min-width: 960px) 50vw, 92vw", True, pos="50% 55%")}</div>
			<div class="namecard hero__card"><p class="namecard__name">Kovács Olívia</p><p class="namecard__spec">Hatha flow<i aria-hidden="true">•</i>Budapest 18. kerület<i aria-hidden="true">•</i>21. kerület</p></div>
		</div>
	</div>
</section>'''
    out = [hero]
    # Fontos! (the picture next to it only repeated the date)
    iF, sF = by_heading(p, 'Fontos!')
    take(iF)
    if AFTER:
        alert = '<h2>Fontos!</h2><p>Óráimat 2026. november 25-ig tartottam.</p>'
    else:
        alert = blocks_html(tiles_blocks([t for c in sF["columns"] for t in c]))
    out.append(wrap(f'<aside class="oj-alert oj-alert--online" data-reveal><div class="oj-alert__text">{alert}{online_words()}</div>{online_buttons()}</aside>',
                    'section section--tight oj-alert-wrap'))
    out.append(korszak_section())
    # group classes: title, the two places with their weekly times, what you need
    iG, sG = by_heading(p, 'Csoportos jóga órák')
    take(iG)
    iL, sL = find(p, lambda s: 'Korall' in section_text(s) and 'Prána' in section_text(s) and any(b['t'] == 'h1' for b in tiles_blocks([t for c in s['columns'] for t in c])))
    take(iL)
    for j in range(iG + 1, iL):
        if all(is_divider(t) or t['type'] == 'image' for c in secs[j]['columns'] for t in c):
            take(j)  # the "Ami nem szükséges" picture, rebuilt below as text
    groups, cur = [], None
    for b in tiles_blocks([t for c in sL['columns'] for t in c]):
        if b['t'] == 'gap':
            continue
        if b['t'] == 'h3':
            cur = {'name': b, 'rest': []}
            groups.append(cur)
        elif cur:
            cur['rest'].append(b)
    book = [x for x in hero_links if x]
    cards = []
    for n, g in enumerate(groups):
        rows = []
        for b in g['rest']:
            if b['t'] == 'p':
                parts = [x for x in re.split(r'\s{2,}', text_of(b['html']).strip()) if x]
                rows.append('<li class="oj-slot">' + ''.join(f'<span>{esc(x)}</span>' for x in parts) + '</li>')
            else:
                rows.append(heading(b, 'oj-loc__seo'))
        heads = ''.join(r for r in rows if not r.startswith('<li'))
        slots = ''.join(r for r in rows if r.startswith('<li'))
        cta = button(book[n][0], book[n][1], 'dark') if n < len(book) else ''
        cards.append(f'<article class="oj-loc oj-loc--{["pink", "lilac"][n % 2]}" data-reveal>{heading(g["name"], "oj-loc__name")}{heads}<ul class="oj-slots">{slots}</ul><p class="btn-row">{cta}</p></article>')
    needs = (f'<div class="oj-needs" data-reveal><div class="oj-needs__no"><h3>Ami nem szükséges</h3><ul>' + ''.join(f'<li>{esc(x)}</li>' for x in NEEDS[0])
             + f'</ul></div><div class="oj-needs__yes"><h3>Ami szükséges</h3><ul>' + ''.join(f'<li>{esc(x)}</li>' for x in NEEDS[1]) + '</ul></div></div>')
    was = ''
    if AFTER:  # Olivia: no timetables and maps any more – the online yoga and the online prenatal class instead
        cards = online_boxes()
        # the map headings („csoportos hatha flow jóga Budapest 21. kerület”) stay too, with their place, without the map
        map_heads = [only_heading(sx) for j, sx in enumerate(secs) if only_heading(sx) and j + 1 < len(secs)
                     and any(t['type'] == 'embed' for c in secs[j + 1]['columns'] for t in c)]

        def district(b):
            m = re.search(r'(\d+)\. kerület', text_of(b['html']))
            return m.group(1) if m else None
        was = was_strip(''.join(f'<div class="oj-was__place">{heading(g["name"], "oj-was__name")}'
                                + ''.join(heading(r, 'oj-was__h') for r in g['rest'] if r['t'] != 'p')
                                + ''.join(heading(h, 'oj-was__h oj-was__h--small') for h in map_heads
                                          if district(h) and district(h) in {district(r) for r in g['rest'] if r['t'] != 'p'})
                                + '</div>' for g in groups))
    else:
        cards = ''.join(cards)
    out.append(wrap(f'<header class="section__head" data-reveal><p class="kicker">Hatha flow jóga</p>{heading(only_heading(sG) or first_heading(sG), "oj-h2")}</header>'
                    f'<div class="oj-locs">{cards}</div>{was}{needs}', 'section section--tight'))
    # Olivia's favourite photos ("Főoldal kedvenc képek"), diszito3 the big one, in the theme's frames
    row = [('diszito3', 'big', '50% 40%', 'pink'), ('diszito6', 'tall', '50% 30%', 'lilac'), ('diszito4', 'tall', '50% 35%', 'sun')]
    photos = ''.join(f'<figure class="oj-photo oj-photo--{k}" data-reveal style="--i:{n}"><div class="frame">{new_pic(key, "(min-width: 960px) 50vw, 92vw" if k == "big" else "(min-width: 960px) 25vw, 46vw", pos=pos)}</div></figure>'
                     for n, (key, k, pos, tone) in enumerate(row))
    out.append(wrap(f'<div class="oj-photos">{band("lilac")}{photos}</div>', 'section section--tight oj-photos-wrap'))
    # upcoming events
    iU, sU = find(p, lambda s: section_text(s) == 'Közelgő események')
    if sU:
        take(iU)
        up = [secs[iU + 1], secs[iU + 2]]
        used_idx.update({iU + 1, iU + 2})
        txt = tiles_blocks([t for c in up[0]['columns'] for t in c])
        lnk = [only_link(b) for b in tiles_blocks([t for c in up[1]['columns'] for t in c])]
        lnk = next((x for x in lnk if x), None)
        out.append(wrap(f'<div class="oj-upcoming" data-reveal><div><p class="kicker">{esc(section_text(sU))}</p>{blocks_html(txt)}</div>'
                        f'{button(lnk[0], lnk[1], "ghost") if lnk else ""}</div>', 'section section--tight oj-upcoming-wrap'))
    # Miért jóga?
    iW, sW = by_heading(p, 'Miért jóga?')
    take(iW)
    img = next(t for c in sW['columns'] for t in c if t['type'] == 'image')
    out.append(wrap(f'<div class="intro__grid"><div class="intro__visual" data-reveal><div class="oj-waved">{blob("pink", "left")}<div class="frame">{new_pic("miert-joga", "(min-width: 960px) 36vw, 92vw")}</div></div></div>'
                    f'<div class="oj-prose oj-prose--intro" data-reveal>{blocks_html(tiles_blocks([t for c in sW["columns"] for t in c]))}</div></div>'))
    # Gyakoroljunk együtt! with Tudatosság / Kontroll
    iP, sP = by_heading(p, 'Gyakoroljunk együtt!')
    take(iP)
    img = next(t for c in sP['columns'] for t in c if t['type'] == 'image')
    bs = [b for b in tiles_blocks([t for c in sP['columns'] for t in c]) if b['t'] != 'gap']
    contrast, rest = '', []
    for b in bs:
        tx = text_of(b['html']) if 'html' in b else ''
        if not contrast and 'Tudatosság' in tx and 'Kontroll' in tx:
            parts = [x.strip() for x in re.split(r'<br\s*/?>', b['html']) if x.strip()]
            cols = []
            for k, part in enumerate(parts):
                word, _, rest_ = text_of(part).partition('=')
                cols.append(f'<div{" class=contrast__col--muted" if k else ""}><p class="contrast__word">{esc(word.strip())}</p><p>= {esc(rest_.strip())}</p></div>')
            contrast = ''.join(cols)
            continue
        rest.append(b)
    title = rest.pop(0) if rest and rest[0]['t'].startswith('h') else None
    cap = rest.pop(0) if rest and 'különbség' in text_of(rest[0].get('html', '')) else None
    fig = f'<figure class="contrast" data-reveal>{contrast}' + (f'<figcaption>{fix_links(cap["html"])}</figcaption>' if cap else '') + '</figure>'
    # one paragraph style (Olivia: the sizes and weights jumped): a lead, the paragraphs, the invitation in the quote box
    ps = [b for b in rest if b['t'] == 'p' and not only_link(b)]
    invite = [b for b in rest if b['t'].startswith('h')]
    go = [only_link(b) for b in rest if only_link(b)]
    words_html = ''.join(f'<p{" class=oj-lead" if n == 0 else ""}>{fix_links(b["html"])}</p>' for n, b in enumerate(ps))
    if invite or go:
        invite = [dict(b, html=re.sub(r'\s*<br\s*/?>\s*', ' ', b['html'])) for b in invite]  # one line of text, not a broken one
        words_html += (f'<div class="oj-invite">{"".join(heading(b, "oj-invite__h") for b in invite)}'
                       f'<p class="btn-row">{"".join(button(h, l, "orchid") for h, l in go)}</p></div>')
    out.append(wrap(f'<div class="oj-together"><div class="oj-together__text oj-prose" data-reveal>{heading(title, "oj-h2") if title else ""}{words_html}</div>'
                    f'<div class="oj-together__art" data-reveal><div class="oj-waved">{blob("lilac", "right")}<div class="frame">{new_pic("gyakoroljunk", "(min-width: 960px) 36vw, 92vw")}</div></div></div></div>{fig}', 'section section--pale'))
    # questions: each section is one question
    qs = []
    for j, s in enumerate(secs):
        if j in used_idx:
            continue
        h = first_heading(s)
        if h and h['t'] == 'h3' and norm(text_of(h['html'])).endswith('?') and j > iP:
            take(j)
            bs = [b for b in tiles_blocks([t for c in s['columns'] for t in c]) if b['t'] != 'gap']
            q, ans = bs[0], bs[1:]
            new_answer = FAQ_AFTER.get(norm(text_of(q['html']))) if AFTER else None
            if new_answer:
                ans = [{'t': 'p', 'html': esc(x)} for x in new_answer]
            qs.append(f'<details><summary><span class="oj-q__n">{WAVE_MARK}</span><h3 class="oj-q">{fix_links(q["html"])}</h3>{icon("plus")}</summary>'
                      f'<div class="answer oj-prose">{blocks_html(ans)}</div></details>')
    # compact: numbered questions in two columns, all closed (the answers stay in the page for Google)
    out.append(wrap(f'<header class="section__head oj-faq-head" data-reveal><div><p class="kicker">GYIK</p><p class="oj-display">Gyakori kérdések</p></div>'
                    f'<p><a class="text-link" href="{esc(link("jógáról"))}">A jógáról - Tőlem</a></p></header><div class="faq oj-faq">{"".join(qs)}</div>'))
    # gift card
    iA, sA = by_heading(p, 'ajándékutalvány')
    take(iA)
    sA2 = secs[iA + 1]
    take(iA + 1)
    img = next(t for c in sA2['columns'] for t in c if t['type'] == 'image')
    pic_html = pic(img, '(min-width: 960px) 30vw, 92vw')
    if img.get('href'):
        pic_html = f'<a href="{esc(href_of(img["href"]))}">{pic_html}</a>'
    gb = [b for b in tiles_blocks([t for c in sA2['columns'] for t in c]) if b['t'] != 'gap']
    g_go = [only_link(b) for b in gb if only_link(b)]
    g_ps = [b for b in gb if not only_link(b)]
    sub, intro, steps = (g_ps[0] if g_ps else None), (g_ps[1] if len(g_ps) > 1 else None), g_ps[2:]
    # Olivia (11.12): an ONLINE gift card, bought on the online site (the card is e-mailed with a code)
    sub_html = esc('1 alkalmas vagy 5 alkalmas online jóga ajándékutalvány')
    gift = (f'<div class="oj-gift__body"><p class="oj-gift__badge">ONLINE ajándékutalvány</p>{heading(only_heading(sA), "oj-h2")}'
            + (f'<p class="oj-gift__sub">{sub_html}{WAVE_MARK}</p>' if sub_html else '')
            + f'<p class="oj-gift__intro">{esc(GIFT_ONLINE)}</p>'
            + f'<p class="btn-row">{"".join(button(online("gift"), l, "orchid") for h, l in g_go[:1])}</p></div>')
    out.append(wrap(f'<div class="oj-gift" data-reveal><div class="oj-gift__art keep-color">{pic_html}</div>{gift}</div>', 'section section--tight'))
    # teams and athletes: out of the home page (Olivia); the "Jógaóra cégeknek" page still has it
    for j, sx in enumerate(secs):
        if dropped_section(p['path'], sx):
            take(j)
    # reviews
    iR, sR = find(p, lambda s: section_text(s) == 'Ti írtátok')
    if sR:
        take(iR)
        for j in range(iR + 1, len(secs)):
            if all(t['type'] == 'image' for c in secs[j]['columns'] for t in c) and not is_badges(secs[j]):
                take(j)
            else:
                break
        longest = max(range(len(REVIEWS)), key=lambda n: len(REVIEWS[n][1]))
        feature = (f'<figure class="oj-review-feature" data-reveal>{icon("quote", "oj-review-feature__mark")}<blockquote><p>„{esc(REVIEWS[longest][1])}”</p></blockquote>'
                   f'<figcaption>{esc(REVIEWS[longest][0])}</figcaption></figure>')
        cards = ''.join(f'<figure class="oj-review oj-review--{TONES[n % 4]}" data-reveal>{icon("quote", "oj-review__mark")}<blockquote><p>„{esc(q)}”</p></blockquote>'
                        f'<figcaption>{esc(who)}</figcaption></figure>' for n, (who, q) in enumerate(REVIEWS) if n != longest)
        out.append(wrap(f'<header class="section__head section__head--center" data-reveal><p class="kicker">Vélemények</p><p class="oj-display">{esc(section_text(sR))}</p></header>'
                        f'{feature}<div class="oj-reviews">{cards}</div>', 'section section--pale'))
    # the two places on the map
    maps = []
    for j, s in enumerate(secs):
        h = only_heading(s)
        if h and j + 1 < len(secs) and any(t['type'] == 'embed' for c in secs[j + 1]['columns'] for t in c):
            take(j)
            take(j + 1)
            src = next(t['src'] for c in secs[j + 1]['columns'] for t in c if t['type'] == 'embed')
            maps.append(f'<div class="oj-map" data-reveal>{heading(h, "oj-map__title")}{embed_html(src, norm(text_of(h["html"])))}</div>')
    if maps and not AFTER:
        out.append(wrap(f'<div class="oj-maps">{"".join(maps)}</div>', 'section section--tight'))
    # anything not placed above, in its old order
    left = [generic_section(s, j) for j, s in enumerate(secs) if j not in used_idx and not is_prefooter(s) and not is_badges(s)]
    left = [x for x in left if x]
    if left:
        out.append(wrap('\n'.join(left)))
    return '\n'.join(out)


def generic_page(p, tone='lilac', photo=None, sub=False):
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    rest = secs[1:]
    lead = ''
    if sub and rest and only_heading(rest[0]):  # "Rólam" → "Miért lettem jógaoktató?"
        lead = heading(only_heading(rest[0]), 'oj-head__sub')
        rest = rest[1:]
    photo_html = theme_pic(photo, eager=True) if photo else ''
    head = page_head(title, p['path'], [('', 'Főoldal')], lead, photo_html, tone)
    body = '\n'.join(x for x in (generic_section(s, i) for i, s in enumerate(rest)) if x)
    return head + f'<div class="container oj-flow">{body}</div>'


def slot_rows(bs):
    """"szerda 18.30 - 19.30" paragraphs as timetable rows (day | time)."""
    rows = []
    for b in bs:
        tx = norm(text_of(b['html']))
        m = re.match(r'(\S+)\s+(\d.*)$', tx)
        if m:
            rows.append(f'<li class="oj-slot oj-slot--2"><span>{esc(m.group(1))}</span><span>{esc(m.group(2))}</span></li>')
    return f'<ul class="oj-slots">{"".join(rows)}</ul>' if rows else ''


def booking_page(p):
    """Bejelentkezés: the Motibro widget in the middle, tall enough not to scroll on its own (Olivia); the keyword
    heading under it as the two places."""
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    head = page_head(title, p['path'], [('', 'Főoldal')], '', theme_pic(PAGE_PHOTO['bejelentkezés-jóga-bérlet'], eager=True), 'pink')
    if AFTER:  # the WordPress theme sends this address straight to „Bejelentkezés és árak” there (Olivia); a fallback page
        return head + (f'<div class="container oj-flow"><div class="oj-alert oj-alert--online" data-reveal><div class="oj-alert__text">'
                       f'<p>A bejelentkezés, a bérleted és a foglalásaid az oliviajoga.online oldalon vannak.</p>{online_words()}</div>'
                       f'<p class="btn-row oj-online__go">{button(online("prices"), "Bejelentkezés és árak", "orchid", " btn--lg")}'
                       f'{button(online(), "oliviajoga.online " + icon("arrow"), "ghost", " btn--lg")}</p></div></div>')
    out, i = [], 1
    while i < len(secs):
        s = secs[i]
        flat = [t for c in s['columns'] for t in c if not is_divider(t)]
        emb = next((t for t in flat if t['type'] == 'embed'), None)
        h = only_heading(s)
        if emb and 'motibro' in emb['src'] and len(flat) == 1:
            out.append(f'<div class="oj-booking" data-reveal><div class="oj-embed oj-embed--motibro"><iframe src="{esc(emb["src"])}" title="Órarend és bejelentkezés" '
                       f'loading="lazy" scrolling="no" referrerpolicy="no-referrer-when-downgrade"></iframe></div></div>')
        elif h and h['t'] == 'h1' and '<br' in h['html']:
            btns = ''
            nxt = secs[i + 1] if i + 1 < len(secs) else None
            if nxt and all(t['type'] == 'button' for c in nxt['columns'] for t in c if not is_divider(t)):
                btns = '<p class="btn-row">' + ''.join(button(t['href'], t['label'], 'orchid' if n == 0 else 'ghost', ' btn--lg')
                                                       for n, t in enumerate(t for c in nxt['columns'] for t in c if t['type'] == 'button')) + '</p>'
                i += 1
            out.append(f'<div class="oj-places-card" data-reveal>{seo_heading(h, "oj-places")}{btns}</div>')
        else:
            g = generic_section(s, i)
            if g:
                out.append(g)
        i += 1
    return head + f'<div class="container oj-flow">{"".join(out)}</div>'


# Kapcsolat: instead of the form (Olivia: "helyette lehet valami kedves ösztönző szöveg")
CONTACT_INVITE = ('Írj bátran, ha kérdésed van az órákról, a bérletekről vagy az ajándékutalványról, vagy ha még nem tudod, '
                  'melyik óra lenne neked való. Kezdőként is szeretettel várlak: nem kell hozzá se tapasztalat, se hajlékonyság, '
                  'csak egy kis kíváncsiság. A többit együtt csináljuk.')


def contact_page(p):
    """Kapcsolat: the two places in two coloured boxes with their times and map (as on the home page); the form and
    the picture beside it are gone, a few warm words instead (Olivia)."""
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    head = page_head(title, p['path'], [('', 'Főoldal')], '', theme_pic(PAGE_PHOTO['kapcsolat'], eager=True), 'sage')
    boxes, out, i = [], [], 1
    while i < len(secs):
        s = secs[i]
        h = next((b for b in tiles_blocks([t for c in s['columns'] for t in c if not is_divider(t)]) if b['t'] == 'h1'), None)
        nxt = secs[i + 1] if i + 1 < len(secs) else None
        if h and nxt and any(t['type'] == 'embed' for c in nxt['columns'] for t in c):
            emb = next(t for c in nxt['columns'] for t in c if t['type'] == 'embed')
            words = [t for c in nxt['columns'] for t in c if t['type'] in ('text', 'button')]
            bs = [b for b in tiles_blocks(words) if b['t'] != 'gap']
            place = next((b for b in bs if b['t'].startswith('h')), None)
            times = [b for b in bs if b['t'] == 'p' and re.match(r'\s*\S+\s+\d', text_of(b['html'])) and not only_link(b)]
            notes = [b for b in bs if b['t'] == 'p' and b not in times and not only_link(b)]
            links = [only_link(b) for b in bs if only_link(b)]
            tone = ['pink', 'lilac'][len(boxes) % 2]
            notes_html = ''.join('<p class="oj-loc__note">' + fix_links(b['html']) + '</p>' for b in notes)
            slots, mapped = slot_rows(times), embed_html(emb["src"], norm(text_of(place["html"])) if place else "Térkép")
            if AFTER:
                # Olivia (11.12, „november 26-án”): the Kapcsolat page stays exactly as it is – the same two boxes with the
                # district, Prána and Korall – only the old timetable gives way to the Wednesday class and the one-to-one
                # option, the map goes, and the buttons take you to the online site.
                slots = (f'<p class="oj-loc__note oj-loc__was">2026. november 25-ig itt tartottam az óráimat. '
                         f'Innentől élő online jógán folytathatjuk, otthonról:</p>'
                         f'<ul class="oj-slots"><li class="oj-slot oj-slot--2"><span>szerda</span><span>18.30 - 20.00</span></li>'
                         f'<li class="oj-slot oj-slot--2 oj-slot--words"><span>egyéni</span><span>egyeztetett időpontban</span></li></ul>')
                mapped = ''
                links = [(hh, 'Online jóga bejelentkezés' if re.match(r'\s*bejelentkezés', text_of(ll), re.I) else ll) for hh, ll in links]
            boxes.append(f'<article class="oj-loc oj-loc--{tone} oj-loc--contact" data-reveal>{seo_heading(h, "oj-loc__kw")}'
                         f'{heading(place, "oj-loc__place") if place else ""}{slots}'
                         # Olivia (2026-11-06): the buttons and maps of the two boxes in one line → this block sits at the bottom
                         f'<div class="oj-loc__foot">{notes_html}'
                         f'<p class="btn-row">{"".join(button(hh, ll, "dark" if n == 0 else "ghost") for n, (hh, ll) in enumerate(links))}</p>'
                         f'{mapped}</div></article>')
            i += 2
            continue
        if boxes:
            out.append(contact_boxes(boxes))
            boxes = []
        name = only_heading(s)
        if name:
            # "Kovács Olívia" + the e-mail / Facebook buttons + the words instead of the form
            btns = []
            for s2 in secs[i + 1:]:
                for c in s2['columns']:
                    for t in c:
                        if t['type'] == 'button':
                            btns.append(t)
            name = dict(name, html=re.sub(r'<strong>\s+', '<strong>', re.sub(r'\s+</strong>', '</strong>', name['html'])))
            out.append(f'<section class="oj-reach" data-reveal><div class="oj-reach__text">{heading(name, "oj-reach__h")}'
                       f'<p class="oj-reach__invite">{esc(CONTACT_INVITE)}</p>'
                       f'<p class="btn-row">{"".join(button(t["href"], t["label"], "orchid" if n == 0 else "ghost", " btn--lg") for n, t in enumerate(btns))}</p></div></section>')
            break
        g = generic_section(s, i)
        if g:
            out.append(g)
        i += 1
    if boxes:
        out.append(contact_boxes(boxes))
    return head + f'<div class="container oj-flow">{"".join(out)}</div>'


def contact_boxes(boxes):
    return f'<div class="oj-locs oj-locs--contact">{"".join(boxes)}</div>'


def rolam_page(p):
    """Rólam (Olivia: the small drawings out, the design rethought): book mode (Tschichold) – a calm reading column
    with numbered chapters, a portrait beside the first ones, the big sentences as full-width colour blocks between
    them. Same words, same headings."""
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    rest = secs[1:]
    lead = ''
    if rest and only_heading(rest[0]):
        lead = heading(only_heading(rest[0]), 'oj-head__sub')
        rest = rest[1:]
    head = page_head(title, p['path'], [('', 'Főoldal')], lead, theme_pic(PAGE_PHOTO['rólam'], eager=True), 'lilac')
    out, chapters, statements, tail, n = [], [], [], [], 0

    reads = []

    def flush():
        if chapters:
            first = not reads
            aside = ''
            if first:
                aside = f'<aside class="oj-read__aside"><div class="oj-waved">{blob("lilac", "left")}<div class="frame oj-read__photo">{theme_pic("olivia-golden-hour-arms", "(min-width: 960px) 28vw, 92vw")}</div></div></aside>'
            reads.append(len(out))
            out.append((first, aside, f'<ol class="oj-chapters" start="{n - len(chapters) + 1}">{"".join(chapters)}</ol>'))
            chapters.clear()
        if statements:
            out.append(f'<div class="oj-quotes oj-statements" style="--n:{len(statements)}">' + ''.join(statements) + '</div>')
            statements.clear()
    for s in rest:
        for c in s['columns']:
            tiles = [t for t in c if not is_divider(t) and not (t['type'] == 'image' and t['file'] in REMOVED_FILES)]
            bs = [b for b in tiles_blocks(tiles) if b['t'] != 'gap']
            if not bs:
                continue
            if len(bs) == 1 and only_link(bs[0]):
                tail.append(bs[0])
                continue
            if bs[0]['t'] in ('h1', 'h2'):
                if chapters:
                    flush()
                statements.append(f'<div class="oj-col" data-reveal>{blocks_html(bs)}</div>')
                continue
            if statements:
                flush()
            n += 1
            first, more = bs[0], bs[1:]
            if first['t'] == 'p' and len(norm(text_of(first['html']))) <= 200:  # a short opening sentence leads
                body = f'<p class="oj-chapter__lead">{fix_links(first["html"])}</p>' + blocks_html(more)
            else:
                body = blocks_html(bs)
            chapters.append(f'<li class="oj-chapter" data-reveal><span class="oj-chapter__n">{WAVE_MARK}</span><div class="oj-prose oj-chapter__text">{body}</div></li>')
    flush()
    if len(reads) > 1:  # the last chapters: a photo of her beside them, too
        first, _, ol = out[reads[-1]]
        out[reads[-1]] = (first, f'<aside class="oj-read__aside oj-read__aside--end"><div class="oj-waved">{blob("pink", "left")}<div class="frame oj-read__photo">'
                                 f'{new_pic("korszak-03", "(min-width: 960px) 28vw, 92vw", pos="50% 35%")}</div></div></aside>', ol)
    out = [f'<div class="oj-read{" oj-read--first" if x[0] else ""}{" oj-read--end" if x[1] and not x[0] else ""}">{x[1]}{x[2]}</div>' if isinstance(x, tuple) else x for x in out]
    btn = ''.join(f'<p class="btn-row oj-read__go">{button(*only_link(b), "orchid", " btn--lg")}</p>' for b in tail)
    return head + f'<div class="container oj-flow oj-about">{"".join(out)}{btn}{online_note()}</div>'


def prices_after(head):
    """Árak from 26 November (Olivia, 11.12): the 90-minute live online yoga and the online prenatal class, apart."""
    def card(n, count, amount, unit, links, cta, more=''):
        lis = ''.join(f'<li><a href="{esc(online(k))}"{ext_attr(online(k))}>{esc(l)}</a></li>' for l, k in links)
        return (f'<article class="price price--{["paper", "forest"][n % 2]}"><h4>{esc(count)}</h4><p class="price__amount">{esc(amount)}</p>'
                f'<p class="price__unit">{esc(unit)}</p>{more}<ul class="oj-price-links">{lis}</ul>'
                f'{button(online(cta[1]), cta[0], ["dark", "orchid"][n % 2])}</article>')
    yoga = ''.join(card(n, c, a, u, links, ('Online jóga bejelentkezés', 'home'),
                        '<p class="price__note">Ha én mondok le egy órát, a bérlet érvényessége annyival kitolódik.</p>' if n else '')
                   for n, (c, a, u, links) in enumerate(YOGA_PRICES))
    pre = [(c, a, u) for c, a, u in PRENATAL_PRICES]
    duo = (card(0, '1 alkalom', pre[0][1], '60 perc, egyéni', [], ('Online kismamatorna', 'prenatal'),
                f'<p class="price__duo">Barátnőddel: <b>{esc(pre[2][1])}</b> kettőtöknek</p>')
           + card(1, '5 alkalmas bérlet', pre[1][1], 'lejárat nélkül', [], ('Online kismamatorna', 'prenatal'),
                  f'<p class="price__duo">Barátnőddel: <b>{esc(pre[3][1])}</b> kettőtöknek, lejárat nélkül</p>'))
    groups = (f'<section class="price-group price-group--text" data-reveal><header class="price-group__head"><p class="kicker">Élő online jóga</p><h3>90 perc</h3>'
              f'<p class="price-group__text">{esc(YOGA_90)}</p><p class="price-group__when">Szerdánként 18:30-tól 20:00-ig, élőben, online.</p></header>'
              f'<div class="price-group__cards" style="--n:2">{yoga}</div></section>'
              + services_html())  # the prenatal class and every other one-to-one service in a fold (Olivia, 16 November)
    return head + f'<div class="container oj-flow"><div class="pricing oj-pricing">{groups}</div></div>'


def prices_page(p):
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    sub = only_heading(secs[1])
    if sub:  # Olivia (11.12): „élő online, csoportos hatha flow jóga órák és egyéni, privát online kismamatorna”
        lines = heading_lines(sub)
        places = '' if AFTER else ''.join(f'<span class="seo-h__place">{icon("pin")}<span>{esc(m.group(1))}<strong>{esc(m.group(2))}</strong></span></span>'
                                          for ln in lines[1:] for m in [re.match(r'(.*?)(\d+\.\s*kerület.*)$', ln)] if m)
        sub_html = (f'<{sub["t"]} class="seo-h oj-head__sub"><span class="seo-h__lead">{esc(PRICES_SUB[0])}</span>'
                    f'<span class="seo-h__lead seo-h__lead--2">{esc(PRICES_SUB[1])}</span> {places}</{sub["t"]}>')
    head = page_head(title, p['path'], [('', 'Főoldal')], sub_html if sub else '', theme_pic(PAGE_PHOTO['árak'], eager=True), 'sun')
    if AFTER:
        return prices_after(head)
    groups, note, book = [], None, None
    for s in secs[2:]:
        bs = tiles_blocks([t for c in s['columns'] for t in c])
        imgs = [t for c in s['columns'] for t in c if t['type'] == 'image' and t['file'] in PRICES]
        if imgs and PRICES[imgs[0]['file']][2] in DROPPED_LENGTHS:
            note, book = None, None  # no 90-minute classes any more (Olivia)
            continue
        if imgs:
            cards = []
            for n, c in enumerate(s['columns']):
                im = next((t for t in c if t['type'] == 'image' and t['file'] in PRICES), None)
                if not im:
                    continue
                count, amount, length = PRICES[im['file']]
                links = ''
                for b in tiles_blocks(c):
                    if b['t'] != 'p':
                        continue
                    ol = only_link(b)
                    hh = href_of(ol[0]) if ol else href_of(im.get('href') or MOTIBRO)
                    if 'ajándékutalvány' in (ol[1] if ol else text_of(b['html'])):
                        hh = online('gift')  # Olivia (11.12): an online gift card, on the online site
                    links += f'<li><a href="{esc(hh)}"{ext_attr(hh)}>{ol[1] if ol else fix_links(b["html"])}</a></li>'
                cards.append(f'<article class="price price--{["paper", "forest"][n % 2]}"><h4>{esc(count)}</h4><p class="price__amount">{esc(amount)}</p>'
                             f'<p class="price__unit">{esc(length)}</p><ul class="oj-price-links">{links}</ul>'
                             f'<a class="btn btn--{["dark", "orchid"][n % 2]}" href="{esc(href_of(im.get("href") or MOTIBRO))}" target="_blank" rel="noopener">Bejelentkezés</a></article>')
            length = PRICES[imgs[0]['file']][2]
            top = ''
            if note:
                top += (f'<div class="oj-alert oj-alert--slim">{blocks_html([note])}{online_words()}'
                        f'<p><a class="text-link" href="{esc(online())}" target="_blank" rel="noopener">oliviajoga.online {icon("arrow")}</a></p></div>')
            groups.append(f'<section class="price-group" data-reveal><header class="price-group__head"><h3>{esc(length)}</h3>{top}</header>'
                          f'<div class="price-group__cards" style="--n:{len(cards)}">{"".join(cards)}</div></section>')  # no extra button below (Olivia)
            note, book = None, None
            continue
        for b in bs:
            if b['t'] == 'gap':
                continue
            ol = only_link(b)
            if 'FONTOS' in text_of(b.get('html', '')):
                note = b
            elif ol or text_of(b.get('html', '')).strip() == 'Bejelentkezés':
                label = ol[1] if ol else fix_links(b['html'])
                book = f'<p class="btn-row">{button(ol[0] if ol else MOTIBRO, label, "ghost")}</p>'
    body = f'<div class="container oj-flow"><div class="pricing oj-pricing">{"".join(groups)}{services_html("Élő online órák: egyéni online órák")}</div></div>'
    return head + body


def journal_page(p):
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    head = page_head(title, p['path'], [('', 'Főoldal')], '', theme_pic(PAGE_PHOTO['jógáról'], eager=True), 'sage')
    cards, n = [], 0
    for s in secs[1:]:
        for c in s['columns']:
            im = next((t for t in c if t['type'] == 'image'), None)
            bs = [b for b in tiles_blocks(c) if b['t'] != 'gap']
            if not bs:
                continue
            more = next((only_link(b) for b in bs if only_link(b)), None)
            body = card_titles(blocks_html([b for b in bs if not only_link(b)], buttons=False))
            target = href_of(im['href']) if im and im.get('href') else (href_of(more[0]) if more else '#')
            photo = card_pic(im['href'][5:], im) if im and im.get('href', '').startswith('page:') else (pic(im, "(min-width: 960px) 30vw, 92vw") if im else '')
            media = f'<a class="oj-card__media" href="{esc(target)}" tabindex="-1">{photo}</a>' if im else ''
            go = f'<a class="card__more" href="{esc(href_of(more[0]))}">{more[1]} {icon("arrow")}</a>' if more else ''
            cards.append(f'<article class="oj-card oj-card--{TONES[n % 4]}" data-reveal>{media}<div class="oj-card__body oj-prose">{body}{go}</div></article>')
            n += 1
    return head + f'<div class="container oj-flow"><div class="oj-cards oj-cards--journal">{"".join(cards)}</div></div>'


def events_page(p):
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    first = next(t for s in secs for c in s['columns'] for t in c if t['type'] == 'image' and not is_divider(t))
    head = page_head(title, p['path'], [('', 'Főoldal')], '', pic(first, '(min-width: 960px) 40vw, 90vw', True), 'pink', extra='oj-head--landscape')
    out, year, open_ul = [], None, False
    i = 1
    while i < len(secs):
        s = secs[i]
        h = only_heading(s)
        nxt = secs[i + 1] if i + 1 < len(secs) else None
        if h and nxt and any(t['type'] == 'image' for c in nxt['columns'] for t in c) and any(t['type'] == 'text' for c in nxt['columns'] for t in c):
            m = re.search(r'(20\d\d)\.\d\d\.\d\d', text_of(h['html']))
            y = m.group(1) if m else ''
            if y and y != year:
                out.append(('</div>' if open_ul else '') + f'<h3 class="moments__year">{y}</h3><div class="oj-events">')
                open_ul, year = True, y
            im = next(t for c in nxt['columns'] for t in c if t['type'] == 'image')
            bs = [b for b in tiles_blocks([t for c in nxt['columns'] for t in c]) if b['t'] != 'gap']
            more = next((only_link(b) for b in bs if only_link(b)), None)
            body = blocks_html([b for b in bs if not only_link(b)], buttons=False)
            target = href_of(im['href']) if im.get('href') else (href_of(more[0]) if more else '')
            media = pic(im, '(min-width: 960px) 34vw, 92vw')
            if target:
                media = f'<a href="{esc(target)}"{ext_attr(target)} tabindex="-1">{media}</a>'
            go = f'<p class="oj-event__go"><a class="text-link" href="{esc(href_of(more[0]))}"{ext_attr(href_of(more[0]))}>{more[1]}</a></p>' if more else ''
            out.append(f'<article class="oj-event oj-event--{TONES[len(out) % 4]}" data-reveal><div class="oj-event__media">{media}</div>'
                       f'<div class="oj-event__body oj-prose">{heading(h, "oj-event__title")}{body}{go}</div></article>')
            i += 2
            continue
        tb = title_of(s)
        if tb and i == 1:
            out.append(f'<p class="kicker oj-events__kicker">{fix_links(tb["html"])}</p>' if tb['t'] == 'p' else heading(tb, 'oj-title__h'))
        else:
            g = generic_section(s, i)
            if g:
                out.append(('</div>' if open_ul else '') + g + ('<div class="oj-events">' if open_ul else ''))
        i += 1
    if open_ul:
        out.append('</div>')
    return head + f'<div class="container oj-flow"><div class="oj-events-all">{"".join(out)}</div></div>'


def post_page(p, journal_cards):
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    cover, body_blocks, extra = None, [], []
    for s in secs[1:]:
        for c in s['columns']:
            for t in c:
                if t['type'] == 'image' and not is_divider(t) and cover is None:
                    cover = t
                elif t['type'] == 'image' and not is_divider(t):
                    extra.append(f'<figure class="oj-figure">{pic(t, "(min-width: 960px) 60vw, 92vw")}</figure>')
                elif t['type'] in ('text', 'button'):
                    body_blocks.extend(tiles_blocks([t]))
    raw = cover and card_key(p['path']) in NEW_META
    art = 'has-art-photo' if cover and cover['file'] in CUTOUTS and not raw else ''
    photo = (card_pic(p['path'], cover, '(min-width: 960px) 47vw, 100vw', eager=True) if raw else pic(cover, '(min-width: 960px) 36vw, 90vw', True)) if cover else ''
    head = page_head(title, p['path'], [('', 'Főoldal'), ('jógáról', 'Jógáról')], '', photo, TONES[sum(map(ord, p['path'])) % 4], True, extra=art + (' oj-head--raw' if raw else ''))
    others = [c for c in journal_cards if c[0] != p['path']]
    k = [x[0] for x in journal_cards].index(p['path']) if p['path'] in [x[0] for x in journal_cards] else 0
    pick = (others[k % len(others):] + others)[:3] if others else []
    more = ''.join(f'<li><a href="{esc(link(path))}"><span class="oj-more__photo">{img}</span><span class="oj-more__title">{esc(t)}</span></a></li>' for path, t, img in pick)
    aside = (f'<aside class="aside-sticky oj-more"><p class="kicker">Jógáról</p><ul>{more}</ul>'
             f'<p class="btn-row"><a class="btn btn--ghost" href="{esc(link("jógáról"))}">A jógáról - Tőlem</a></p></aside>')
    return head + f'<div class="container"><article class="layout layout--aside"><div class="entry oj-prose">{blocks_html(body_blocks)}{"".join(extra)}{online_note()}</div>{aside}</article></div>'


# ---------------------------------------------------------------- build

def write(path, content):
    rel = path if path == '404' else ascii_slug(path)
    target = os.path.join(OUT, rel, 'index.html') if rel else os.path.join(OUT, 'index.html')
    os.makedirs(os.path.dirname(target), exist_ok=True)
    open(target, 'w', encoding='utf-8').write(content)


# Adatkezelési tájékoztató (Áron, 11.17: „Az Arovia legyen minden”): only in the WordPress theme, with the cookie bar and
# Google Analytics (wp-theme/inc/consent.php). The facts are the online site's own notice (wordpress/olivia-yoga, privacy).
PRIVACY_PATH = 'adatkezelési-tájékoztató'
PRIVACY_EMAIL = 'info@oliviajoga.online'


def privacy_page():
    m = f'<a href="mailto:{PRIVACY_EMAIL}">{PRIVACY_EMAIL}</a>'
    body = f"""<p>Ez a tájékoztató arról szól, milyen adataidat kezeljük, amikor az oliviajoga.hu oldalt nézed, vagy írsz nekünk.</p>
<h2>Ki kezeli az adataidat?</h2>
<p>Az adatkezelő az Arovia Group LLC (HelloProVision), 9813 Quinta Artesa Way, Unit 105, Fort Myers, FL 33908, USA: az oldal üzemeltetője. Az órákat Kovács Olívia tartja. Kérdésed vagy kérésed van az adataiddal kapcsolatban? Írj a {m} címre.</p>
<h2>Milyen adatokat kezelünk, és miért?</h2>
<ul>
<li><strong>Ha írsz nekünk</strong> (e-mailben, Instagramon vagy Facebookon): a neved, az elérhetőséged és az üzeneted, csak hogy válaszolni tudjunk.</li>
<li><strong>Foglalás:</strong> ezen az oldalon nem nyitsz fiókot és nem fizetsz. A „Bejelentkezés” 2026. november 25-ig a Motibro foglalórendszerébe visz (oliviajoga.motibro.com), ott a Motibro tájékoztatója érvényes; november 26-tól az oliviajoga.online oldalra, ahol az <a href="https://oliviajoga.online/adatvedelem/">ottani adatvédelmi tájékoztató</a> érvényes.</li>
</ul>
<h2>Sütik</h2>
<p>A működéshez szükséges sütik (például hogy megjegyezzük, mit döntöttél a sütikről) mindig működnek, ezekhez nem kell hozzájárulás.</p>
<p>Ha az oldal alján megjelenő sávon hozzájárulsz, a Google Analytics (Google Ireland Limited) is méri, hogyan használják az oldalt: melyik oldalakat nézik meg, honnan érkeznek a látogatók, és milyen eszközről. Ehhez a <code>_ga</code> kezdetű sütiket használja, legfeljebb 2 évig. Ugyanez a mérés fut az oliviajoga.online oldalon is, így látjuk, ha onnan ide vagy innen oda lépsz át. Az eredményt csak összesítve, név nélkül látjuk. A Google az adatokat az Egyesült Államokban is kezelheti. Ha nem járulsz hozzá, a Google Analytics be sem töltődik. Reklámsütit nem használunk.</p>
<p>A döntésedet bármikor megváltoztathatod az oldal alján, a <a href="#cookie-settings">Sütibeállítások</a> linkkel.</p>
<h2>Hol vannak az adataid?</h2>
<p>Az oldal a GoDaddy virtuális szerverén (VPS) fut, európai uniós adatközpontban; a szervert a HelloProVision üzemelteti.</p>
<h2>Adataid az Egyesült Államokban</h2>
<p>Az Arovia Group LLC amerikai cég, ezért az adataid egy részét ott is kezeljük. Csak a fenti célokra és ideig használjuk őket, ugyanazzal a gondossággal, mint az Európai Unióban.</p>
<h2>Kik férnek hozzá?</h2>
<p>Az oktató, valamint azok a szolgáltatók, akik nélkül az oldal nem működne: a weboldal üzemeltetője (HelloProVision), a tárhely (GoDaddy virtuális szerver), valamint ha hozzájárulsz, a Google Analytics (az oldal látogatottságának mérése). Adatot nem adunk el, és reklámcélra nem adunk át senkinek.</p>
<h2>A jogaid</h2>
<p>Kérheted, hogy megmutassuk, milyen adatot tárolunk rólad, kijavítsuk vagy töröljük őket, korlátozzuk a kezelésüket, vagy géppel olvasható formában kiadjuk neked. Írj a {m} címre, és válaszolunk.</p>
<p>Ha úgy érzed, hogy nem jól kezeljük az adataidat, panaszt tehetsz a Nemzeti Adatvédelmi és Információszabadság Hatóságnál (NAIH, <a href="https://naih.hu">naih.hu</a>).</p>"""
    head = page_head({'t': 'h1', 'html': 'Adatkezelési tájékoztató'}, PRIVACY_PATH, [('', 'Főoldal')], '', '', 'lilac')
    return {'name': 'Adatkezelési tájékoztató', 'title': f'Adatkezelési tájékoztató | {SITE_TITLE}',
            'desc': 'Milyen adataidat kezeljük az oliviajoga.hu oldalon, mire használjuk őket, milyen sütiket használunk, és milyen jogaid vannak.',
            'cls': 'is-page', 'schema': '', 'content': head + f'<div class="container"><div class="entry oj-prose oj-legal">{body}</div></div>',
            'prefooter': '', 'image': ''}


def wp_entry(p, body, cls, schema):
    """One page for the WordPress theme: the editable content (main) and what the theme puts around it."""
    path = p['path']
    h1 = re.search(r'<h1[^>]*>(.*?)</h1>', body, re.S)
    name = dict(NAV).get(path) or (norm(text_of(h1.group(1))) if h1 else p['title'])
    return {'name': 'Főoldal' if path in ('', 'főoldal') else name, 'title': p['title'], 'desc': description(p), 'cls': cls,
            'schema': json.dumps(schema, ensure_ascii=False), 'content': body, 'prefooter': prefooter(p), 'image': first_image(body)}


def wp_theme(pages, versions):
    """--wp <folder>: the oliviayoga WordPress theme (iu_theme child) – wp-theme/ (PHP) + data/site.json + assets.

    The pages are created by the theme's setup (hpv_child_setup) at the same addresses; the photos are not in the theme
    (27 MB): the VPS puts oliviajoga-hu/assets/img into uploads/oliviajoga-img (projects.json media_dir)."""
    src = os.path.join(HERE, 'wp-theme')
    if os.path.isdir(OUT):
        shutil.rmtree(OUT)
    shutil.copytree(src, OUT)
    css = os.path.join(OUT, 'style.css')
    head = open(css, encoding='utf-8').read().replace('Version: 0.0.0', 'Version: ' + VERSION)
    open(css, 'w', encoding='utf-8').write(head)
    nf = {'path': '404', 'title': f'Az oldal nem található | {SITE_TITLE}', 'sections': []}
    chrome = {
        'version': VERSION, 'site_title': SITE_TITLE, 'nav': NAV, 'deleted': sorted(DELETED_PAGES | {ascii_slug(x) for x in DELETED_PAGES}),
        'header': versions['header'], 'footer': versions['footer'], 'after': versions['after'],
        '404': page_head({'t': 'h1', 'html': 'Az oldal nem található'}, '404', [('', 'Főoldal')], '', '', 'lilac')
               + f'<div class="container oj-flow"><p class="btn-row"><a class="btn btn--orchid" href="{esc(link(""))}">Főoldal</a></p></div>',
        '404_title': nf['title'], 'pages': pages, 'llms': versions.get('llms', ''), 'moved': versions.get('moved', {}),
    }
    os.makedirs(os.path.join(OUT, 'data'), exist_ok=True)
    json.dump(chrome, open(os.path.join(OUT, 'data', 'site.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
    os.makedirs(os.path.join(OUT, 'assets', 'img', 'theme'), exist_ok=True)
    shutil.copy(os.path.join(THEME, 'assets', 'site.css'), os.path.join(OUT, 'assets', 'site.css'))
    shutil.copy(os.path.join(THEME, 'assets', 'site.js'), os.path.join(OUT, 'assets', 'site.js'))
    shutil.copy(os.path.join(HERE, 'assets', 'oliviajoga.css'), os.path.join(OUT, 'assets', 'oliviajoga.css'))
    shutil.copy(os.path.join(HERE, 'assets', 'oliviajoga.js'), os.path.join(OUT, 'assets', 'oliviajoga.js'))
    shutil.copytree(os.path.join(HERE, 'assets', 'icons'), os.path.join(OUT, 'assets', 'icons'))  # favicon (Áron, 25 Nov)
    # Szerkesztés a weboldalon (Olivia, 11.13): the same editor as the online site (wordpress/olivia-yoga)
    shutil.copy(os.path.join(THEME, 'inc', 'page-editor.php'), os.path.join(OUT, 'inc', 'page-editor.php'))
    # HelloProVision élesítési csomag (Áron, 11.17: „Történjen mindig ugyanaz”) – a közös fájl, wordpress/hpv/hpv-kit.php
    shutil.copy(os.path.join(THEME, '..', 'hpv', 'hpv-kit.php'), os.path.join(OUT, 'inc', 'hpv-kit.php'))
    shutil.copy(os.path.join(THEME, 'assets', 'edit.js'), os.path.join(OUT, 'assets', 'edit.js'))
    shutil.copy(os.path.join(THEME, 'assets', 'edit.css'), os.path.join(OUT, 'assets', 'edit.css'))
    for key in used:
        for w in (600, 1200):
            shutil.copy(os.path.join(THEME, 'assets', 'img', f'{key}-{w}.jpg'), os.path.join(OUT, 'assets', 'img', 'theme', f'{key}-{w}.jpg'))
    print(f'{len(pages)} pages → WordPress theme {OUT} ({VERSION})')


def fix_headings(body):
    """Exactly one h1 per page (the old site's own h1s inside the content become h2s that look the same, class
    oj-was-h1); a page head without an h1 gets one."""
    if '<h1' not in body:
        body = re.sub(r'<(h[2-6]|p) class="page-head__title">(.*?)</\1>', r'<h1 class="page-head__title">\2</h1>', body, count=1, flags=re.S)
    seen = [0]

    def one(m):
        seen[0] += 1
        if seen[0] == 1:
            return m.group(0)
        attrs = m.group(1) or ''
        attrs = attrs.replace('class="', 'class="oj-was-h1 ', 1) if 'class="' in attrs else ' class="oj-was-h1"' + attrs
        return f'<h2{attrs}>{m.group(2)}</h2>'
    return re.sub(r'<h1(\s[^>]*)?>(.*?)</h1>', one, body, flags=re.S)


def first_image(body):
    """The page's first photo, big (for og:image)."""
    m = re.search(r'<img[^>]*\bsrc="([^"]+)"', body)
    return m.group(1) if m else ''


def render_all(journal_cards):
    """Every page: (path, page, body, class, structured data)."""
    special = {'': home, 'főoldal': home, 'árak': prices_page, 'jógáról': journal_page, 'események': events_page,
               'rólam': rolam_page, 'bejelentkezés-jóga-bérlet': booking_page, 'kapcsolat': contact_page}
    tones = {'rólam': 'lilac', 'kapcsolat': 'sage', 'jógaóra-cégeknek': 'sun', 'bejelentkezés-jóga-bérlet': 'pink'}
    posts = {pp for pp, _, _ in journal_cards}
    out = []
    for path, pg in PAGES.items():
        if path in DELETED_PAGES:
            continue
        if path in special:
            body = special[path](pg)
            cls = 'is-home' if path in ('', 'főoldal') else 'is-page'
        elif path in posts:
            body = post_page(pg, journal_cards)
            cls = 'is-post'
        else:
            body = generic_page(pg, tones.get(path, 'lilac'), PAGE_PHOTO.get(path), sub=path == 'rólam')
            cls = 'is-page'
        extra = None
        if cls == 'is-post':
            t = only_heading(pg['sections'][0])
            extra = {'@type': 'BlogPosting', 'headline': norm(text_of(t['html'])) if t else pg['title'], 'inLanguage': 'hu-HU',
                     'author': {'@id': ORIGIN + '/#olivia'}, 'mainEntityOfPage': ORIGIN + '/' + ascii_slug(path)}
        out.append((path, pg, fix_headings(body), cls, schema_base(pg, extra)))
    return out


def main():
    global BASE, PREVIEW, OUT, AFTER, ONLINE
    if '--after' in sys.argv[1:]:
        AFTER = True  # the site from 26 November (Olivia, 11.12)
    if '--online' in sys.argv[1:]:  # the preview links to the online site's dev copy
        ONLINE = sys.argv[sys.argv.index('--online') + 1]
    args = sys.argv[1:]
    if '--preview' in args:
        BASE = '/' + args[args.index('--preview') + 1].strip('/') + '/'
        PREVIEW = True
    if '--out' in args:
        OUT = os.path.abspath(args[args.index('--out') + 1])
    global WP, ORIGIN
    if '--wp' in args:
        WP = True
        OUT = os.path.abspath(args[args.index('--wp') + 1])
        BASE, ORIGIN = '%%HOME%%/', '%%HOME%%'
    data = json.load(open(os.path.join(HERE, 'content', 'pages.json'), encoding='utf-8'))
    for pg in data['pages']:
        PAGES[pg['path']] = pg
    THEME_META.update(json.load(open(os.path.join(THEME, 'assets', 'img', 'images.json'), encoding='utf-8')))
    NEW_META.update(json.load(open(os.path.join(HERE, 'content', 'new-photos.json'), encoding='utf-8')))
    NEW_PHOTOS.update(json.load(open(os.path.join(HERE, 'content', 'korszak-alts.json'), encoding='utf-8')))
    CUTOUTS.update(json.load(open(os.path.join(HERE, 'content', 'cutouts.json'), encoding='utf-8')))
    global LOGO_SVG
    LOGO_SVG = open(os.path.join(HERE, 'assets', 'brand', 'logo.svg'), encoding='utf-8').read().strip()
    # the badge row (Fitmummy, payment logos) ends every page
    for pg in data['pages']:
        if pg['sections']:
            last = pg['sections'][-1]
            if not is_prefooter(last):
                for c in last['columns']:
                    for t in c:
                        if t['type'] == 'image':
                            BADGES.add(t['file'])
    if os.path.isdir(OUT):
        shutil.rmtree(OUT)
    os.makedirs(OUT)

    # journal cards: post path, title, picture (for "more from the journal" on each post)
    jp = PAGES['jógáról']
    journal_cards = []
    for s in jp['sections']:
        for c in s['columns']:
            im = next((t for t in c if t['type'] == 'image' and t.get('href', '').startswith('page:')), None)
            if im:
                h = next((b for b in tiles_blocks(c) if b['t'].startswith('h') or b['t'] == 'p'), None)
                journal_cards.append((im['href'][5:], norm(text_of(h['html'])) if h else '', card_pic(im['href'][5:], im, '120px')))

    if WP:  # both versions: until 25 November and from 26 November (the theme switches by the date, Olivia 11.12)
        AFTER = False
        wp_pages = {ascii_slug(path): wp_entry(pg, body, cls, schema) for path, pg, body, cls, schema in render_all(journal_cards)}
        wp_pages[ascii_slug(PRIVACY_PATH)] = privacy_page()
        llms_before = llms_txt(journal_cards)
        chrome = {'header': header(''), 'footer': footer()}
        AFTER = True
        after = {ascii_slug(path): {'content': body, 'prefooter': prefooter(pg), 'desc': description(pg)} for path, pg, body, cls, schema in render_all(journal_cards)}
        chrome['llms'] = llms_before
        chrome['after'] = {'from': AFTER_FROM, 'header': header(''), 'footer': footer(), 'pages': after, 'llms': llms_txt(journal_cards),
                           'redirect': {ascii_slug('bejelentkezés-jóga-bérlet'): online('prices'), 'kapcsolat': online('contact')}}
        # the old accented addresses → the new ones (301; the setup renames the pages; „főoldal” is the home page)
        chrome['moved'] = {pth: ('' if pth == 'főoldal' else ascii_slug(pth)) for pth in list(PAGES) + [PRIVACY_PATH]
                           if pth and ascii_slug(pth) != pth and pth not in DELETED_PAGES}
        slugs = [ascii_slug(pth) for pth in PAGES if pth not in DELETED_PAGES]
        assert len(slugs) == len(set(slugs)), "two pages would get the same address"
        AFTER = False
        return wp_theme(wp_pages, chrome)
    for path, pg, body, cls, schema in render_all(journal_cards):
        write(path, document(pg, body, cls, schema))

    # assets: the theme's styles, script and the photos used; the old site's photos
    os.makedirs(os.path.join(OUT, 'assets', 'img', 'theme'))
    shutil.copy(os.path.join(THEME, 'assets', 'site.css'), os.path.join(OUT, 'assets', 'site.css'))
    shutil.copy(os.path.join(THEME, 'assets', 'site.js'), os.path.join(OUT, 'assets', 'site.js'))
    shutil.copy(os.path.join(HERE, 'assets', 'oliviajoga.css'), os.path.join(OUT, 'assets', 'oliviajoga.css'))
    shutil.copy(os.path.join(HERE, 'assets', 'oliviajoga.js'), os.path.join(OUT, 'assets', 'oliviajoga.js'))
    for key in used:
        for w in (600, 1200):
            shutil.copy(os.path.join(THEME, 'assets', 'img', f'{key}-{w}.jpg'), os.path.join(OUT, 'assets', 'img', 'theme', f'{key}-{w}.jpg'))
    shutil.copytree(os.path.join(HERE, 'assets', 'img', 'old'), os.path.join(OUT, 'assets', 'img', 'old'))
    shutil.copytree(os.path.join(HERE, 'assets', 'img', 'new'), os.path.join(OUT, 'assets', 'img', 'new'))
    shutil.copy(os.path.join(HERE, 'assets', 'img', 'barion-fizetes.svg'), os.path.join(OUT, 'assets', 'img', 'barion-fizetes.svg'))
    shutil.copytree(os.path.join(HERE, 'assets', 'img', 'pay'), os.path.join(OUT, 'assets', 'img', 'pay'))
    shutil.copytree(os.path.join(HERE, 'assets', 'icons'), os.path.join(OUT, 'assets', 'icons'))
    shutil.copy(os.path.join(HERE, 'assets', 'icons', 'favicon.ico'), os.path.join(OUT, 'favicon.ico'))

    # 404, robots, sitemap, server rules
    nf = {'path': '404', 'title': f'Az oldal nem található | {SITE_TITLE}', 'sections': []}
    write('404', document(nf, page_head({'t': 'h1', 'html': 'Az oldal nem található'}, '404', [('', 'Főoldal')], '', '', 'lilac')
                          + f'<div class="container oj-flow"><p class="btn-row"><a class="btn btn--orchid" href="{esc(link(""))}">Főoldal</a></p></div>', 'is-page', schema_base(nf)))
    shutil.move(os.path.join(OUT, '404', 'index.html'), os.path.join(OUT, '404.html'))
    os.rmdir(os.path.join(OUT, '404'))
    if PREVIEW:
        open(os.path.join(OUT, 'robots.txt'), 'w').write('User-agent: *\nDisallow: /\n')
    else:
        open(os.path.join(OUT, 'robots.txt'), 'w').write(f'User-agent: *\nAllow: /\n\nSitemap: {ORIGIN}/sitemap.xml\n')
        urls = ''.join(f'<url><loc>{esc(ORIGIN + "/" + ascii_slug(pth))}</loc></url>' for pth in PAGES if pth != 'főoldal' and pth not in DELETED_PAGES)
        open(os.path.join(OUT, 'sitemap.xml'), 'w', encoding='utf-8').write(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n')
        gone = ''.join(f'RedirectMatch 301 ^/{x}/?$ /\n' for pth in sorted(DELETED_PAGES) for x in sorted({pth, ascii_slug(pth)}))
        gone += ''.join(f'RedirectMatch 301 ^/{pth}/?$ /{ascii_slug(pth) if pth != "főoldal" else ""}\n'
                        for pth in sorted(PAGES) if pth and ascii_slug(pth) != pth and pth not in DELETED_PAGES)
        open(os.path.join(OUT, '.htaccess'), 'w', encoding='utf-8').write(HTACCESS + gone)
    print(f'{len(PAGES)} pages → {OUT} ({"preview " + BASE if PREVIEW else ORIGIN})')


# Apache: the old addresses have no trailing slash; serve /rólam from rólam/index.html without a redirect.
HTACCESS = '''AddDefaultCharset utf-8
DirectorySlash Off
ErrorDocument 404 /404.html
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]
RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]
RewriteCond %{REQUEST_FILENAME}/index.html -f
RewriteRule ^(.*?)/?$ $1/index.html [L]
<IfModule mod_expires.c>
ExpiresActive On
ExpiresByType image/jpeg "access plus 1 year"
ExpiresByType image/webp "access plus 1 year"
ExpiresByType text/css "access plus 1 month"
ExpiresByType application/javascript "access plus 1 month"
</IfModule>
'''

if __name__ == '__main__':
    main()
