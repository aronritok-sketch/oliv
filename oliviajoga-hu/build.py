#!/usr/bin/env python3
"""Builds the new oliviajoga.hu: Olivia's Google Site (Jóga 18. és 21. kerület | Kovács Olívia) in the Olivia Yoga
design, as a static site.

Everything Google already knows stays the same: every page keeps its address (/rólam, /árak, /a-nyújtás-hatása …),
its <title>, its headings (with their h1/h2/h3 level) and its words, and every photo keeps its alt text. Only the
layout and the experience are new. The words come from content/pages.json (tools/extract.py), the design from the
WordPress theme (../wordpress/olivia-yoga/assets: site.css, site.js, photos) plus assets/oliviajoga.css.

    python3 build.py                                  → dist/ for oliviajoga.hu (canonical, sitemap, .htaccess)
    python3 build.py --preview /demo/<slug>/ --out D  → a noindex preview under a sub-folder (links end in /)
"""
import html
import json
import os
import re
import shutil
import sys
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
VERSION = '1.1.1'
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
}
NEW_META = {}
CUTOUTS = set()  # pictures whose white edge became transparent (tools/images.py cutouts)
# Taken out on purpose (Olivia's notes): tools/check.py skips these.
REMOVED_FILES = {
    'old/775328016a39', 'old/085e2f02ccb4', 'old/244f5915632a', 'old/c61fb20665ba', 'old/3c84eb08b35c',
    'old/5214bbe1d570',  # Rólam: the small drawings above the story
    'old/3cf216ddede1',  # Kapcsolat: the picture next to the form
}
REMOVED_EMBEDS = {'docs.google.com/forms'}  # Kapcsolat: the message form
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


def esc(s):
    return html.escape(s, quote=True)


def text_of(h):
    return html.unescape(re.sub(r'<[^>]+>', ' ', h)).replace('\xa0', ' ')


def norm(s):
    return re.sub(r'\s+', ' ', s).strip()


# ---------------------------------------------------------------- addresses

def link(path):
    """The address of an old page: /rólam on oliviajoga.hu (the server serves rólam/index.html), …/rólam/ in a preview."""
    path = path.strip('/')
    if path == 'főoldal':
        path = ''  # the same page as the home page
    if not path:
        return BASE
    return BASE + urllib.parse.quote(path) + ('/' if PREVIEW else '')


def asset(rel):
    return f'{BASE}assets/{rel}'


def fix_links(h):
    """page:<path> links from the old site → new addresses; outside links open in a new tab."""
    def rep(m):
        href = html.unescape(m.group(1))
        if href.startswith('page:'):
            return f'<a href="{esc(link(href[5:]))}"'
        if href.startswith('http') and 'oliviajoga.hu' not in href:
            return f'<a href="{esc(href)}" target="_blank" rel="noopener"'
        return f'<a href="{esc(href)}"'
    return re.sub(r'<a href="([^"]*)"', rep, h)


def href_of(h):
    if h.startswith('page:'):
        return link(h[5:])
    return h


def ext_attr(h):
    return ' target="_blank" rel="noopener"' if h.startswith('http') and 'oliviajoga.hu' not in h else ''


# ---------------------------------------------------------------- pictures

def pic(t, sizes='(min-width: 960px) 40vw, 92vw', eager=False, alt=None, cls='photo'):
    f, ext, w, h = t['file'], t['ext'], t['w'], t['h']
    if f in CUTOUTS:
        ext = '.webp'
    a = t.get('alt', '') if alt is None else alt
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
    return (f'<img class="{cls}" src="{big if m["w"] > 800 else small}"{srcset} alt="{esc(NEW_PHOTOS[key])}" width="{m["w"]}" '
            f'height="{m["h"]}" style="object-position:{pos}"{load} decoding="async">')


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
    return f'<a class="btn btn--{style}{extra}" href="{esc(h)}"{ext_attr(h)}>{label}</a>'


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
            statement = heading(bs[0], 'oj-pre__statement')
            continue
        h = bs[0]
        rows = []
        for b in bs[1:]:
            ol = only_link(b)
            if ol:
                rows.append(f'<p class="oj-pre__go">{button(ol[0], ol[1], "light", " btn--sm")}</p>')
            else:
                rows.append(f'<p class="oj-pre__time">{icon("clock")}<span>{fix_links(b["html"])}</span></p>')
        slots.append(f'<div class="oj-pre__slot">{heading(h)}{"".join(rows)}</div>')
    social = (f'<p class="social"><a href="{INSTAGRAM}" target="_blank" rel="noopener">{icon("ig")}<span class="sr-only">Instagram</span></a>'
              f'<a href="{FACEBOOK}" target="_blank" rel="noopener">{icon("fb")}<span class="sr-only">Facebook</span></a>'
              f'<a href="mailto:{EMAIL}">{icon("mail")}<span class="sr-only">Email</span></a></p>')
    badge_html = ''
    if badges:
        imgs = [t for c in badges['columns'] for t in c if t['type'] == 'image']
        badge_html = '<div class="oj-badges">' + ''.join(f'<figure>{pic(t, "360px")}</figure>' for t in imgs) + '</div>'
    badges_sec = f'<section class="oj-badges-wrap" aria-label="Partnerek"><div class="container">{badge_html}</div></section>' if badge_html else ''
    return (f'<section class="section section--tight section--moss oj-pre" aria-label="Órarend"><div class="container">'
            f'<div class="oj-pre__grid"><div class="oj-pre__lead">{statement}{social}</div><div class="oj-pre__slots">{"".join(slots)}</div></div>'
            f'</div></section>{badges_sec}')


def header(path):
    def item(p, n, cls=''):
        cur = ' aria-current="page"' if (p or '') == (path if path != 'főoldal' else '') else ''
        return f'<li{cls}><a href="{esc(link(p))}"{cur}>{esc(n)}</a></li>'
    desk = ''.join(item(p, n) for p, n in NAV[1:])
    mob = ''.join(item(p, n) for p, n in NAV)
    mark = ('<svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#2B5036"/>'
            '<path d="M9 25c4-3.5 8 2.5 12-1s7-2.5 10-.5" fill="none" stroke="#C6A3EE" stroke-width="2.2" stroke-linecap="round"/>'
            '<circle cx="26.5" cy="13" r="4" fill="#EE3F9A"/></svg>')
    return f'''<a class="skip-link" href="#main">Ugrás a tartalomra</a>
<div class="oj-notice-bar"><div class="container"><p>{esc(NOTICE)}</p></div></div>
<header class="site-header" data-header>
	<div class="container site-header__inner">
		<a class="brand" href="{esc(link(''))}" rel="home">{mark}<span><span class="brand__name">Kovács Olívia</span><span class="brand__sub">Jóga 18. és 21. kerület</span></span></a>
		<nav class="nav" aria-label="Fő"><ul class="menu">{desk}</ul></nav>
		<a class="btn btn--orchid btn--sm header-cta" href="{MOTIBRO}" target="_blank" rel="noopener">Bejelentkezés</a>
		<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="drawer" data-nav-toggle>
			<span class="i-open">{icon("menu")}</span><span class="i-close">{icon("close")}</span><span class="sr-only">Menü</span>
		</button>
	</div>
</header>
<div class="drawer" id="drawer">
	<nav aria-label="Mobil"><ul class="menu">{mob}</ul></nav>
	<p class="btn-row"><a class="btn btn--orchid" href="{MOTIBRO}" target="_blank" rel="noopener">Bejelentkezés</a></p>
	<p class="drawer__contact"><a href="mailto:{EMAIL}">{EMAIL}</a></p>
</div>'''


def footer():
    links = ''.join(f'<li><a href="{esc(link(p))}">{esc(n)}</a></li>' for p, n in NAV)
    return f'''<footer class="site-footer">
	<div class="container">
		<div class="footer__grid">
			<div class="footer__brand">
				<p class="footer__motto">Kovács Olívia</p>
				<p class="btn-row"><a class="btn btn--orchid" href="{MOTIBRO}" target="_blank" rel="noopener">Bejelentkezés</a></p>
			</div>
			<nav aria-label="Oldalak" class="oj-foot-nav"><h2>Oldalak</h2><ul>{links}</ul></nav>
			<div><h2>Kapcsolat</h2>
				<ul class="footer__contact"><li>{icon("mail")}<a href="mailto:{EMAIL}">{EMAIL}</a></li><li>{icon("ig")}<a href="{INSTAGRAM}" target="_blank" rel="noopener">Instagram</a></li><li>{icon("fb")}<a href="{FACEBOOK}" target="_blank" rel="noopener">Facebook</a></li></ul>
			</div>
		</div>
		<div class="footer__bottom"><p>&copy; 2026 Kovács Olívia</p></div>
	</div>
</footer>
<a class="book-fab" href="{MOTIBRO}" target="_blank" rel="noopener" data-book-fab>{icon("cal")}Bejelentkezés</a>'''


def description(p):
    for s in p['sections']:
        for c in s['columns']:
            for t in c:
                if t['type'] != 'text':
                    continue
                for b in t['blocks']:
                    if b['t'] == 'p':
                        tx = norm(text_of(b['html']))
                        if len(tx) > 60:
                            if len(tx) <= 158:
                                return tx
                            cut = tx[:155].rsplit(' ', 1)[0].rstrip(',;:–-')
                            return cut + '…'
    return SITE_TITLE


def document(p, body, cls, schema, image=None):
    path = p['path']
    canon = ORIGIN + '/' + urllib.parse.quote(path if path != 'főoldal' else '')
    desc = description(p)
    robots = '<meta name="robots" content="noindex, nofollow">\n' if PREVIEW else ''
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
{og_img}<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='20' fill='%232B5036'/%3E%3Ccircle cx='31' cy='16' r='5' fill='%23EE3F9A'/%3E%3C/svg%3E">
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
</body>
</html>
'''


def schema_base(p, extra=None):
    person = {'@type': 'Person', '@id': ORIGIN + '/#olivia', 'name': 'Kovács Olívia', 'jobTitle': 'jógaoktató',
              'url': ORIGIN + '/', 'email': EMAIL, 'sameAs': [INSTAGRAM, FACEBOOK]}
    graph = [{'@type': 'WebSite', '@id': ORIGIN + '/#website', 'url': ORIGIN + '/', 'name': SITE_TITLE, 'inLanguage': 'hu-HU'}, person]
    if extra:
        graph.append(extra)
    return {'@context': 'https://schema.org', '@graph': graph}


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
    btns = ''.join(button(h, l, 'orchid', ' btn--lg') for h, l in (x for x in hero_links if x))
    hero = f'''<section class="hero oj-hero" aria-labelledby="hero-title">
	<div class="container hero__grid">
		<div class="hero__text">
			<p class="kicker">{esc(SITE_TITLE)}</p>
			<{title_b["t"]} class="hero__title" id="hero-title"><span class="hero__l1">{esc(words[0])}</span> <span class="hero__l2"><mark class="oj-breath">{esc(" ".join(words[1:]))}</mark></span></{title_b["t"]}>
			<p class="btn-row oj-twins">{btns}</p>
		</div>
		<div class="hero__visual">
			<div class="hero__block" aria-hidden="true"></div>
			<div class="frame hero__photo oj-hero-photo">{new_pic("hero", "(min-width: 960px) 50vw, 92vw", True, pos="50% 55%")}</div>
			<div class="namecard hero__card"><p class="namecard__name">Kovács Olívia</p><p class="namecard__spec">Hatha flow<i aria-hidden="true">•</i>Budapest 18. kerület<i aria-hidden="true">•</i>21. kerület</p></div>
		</div>
	</div>
</section>'''
    out = [hero]
    # Fontos! (the picture next to it only repeated the date)
    iF, sF = by_heading(p, 'Fontos!')
    take(iF)
    out.append(wrap(f'<aside class="oj-alert" data-reveal>{blocks_html(tiles_blocks([t for c in sF["columns"] for t in c]))}</aside>', 'section section--tight oj-alert-wrap'))
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
    out.append(wrap(f'<header class="section__head" data-reveal><p class="kicker">Hatha flow jóga</p>{heading(only_heading(sG) or first_heading(sG), "oj-h2")}</header>'
                    f'<div class="oj-locs">{"".join(cards)}</div>{needs}', 'section section--tight'))
    # Olivia's favourite photos ("Főoldal kedvenc képek"), diszito3 the big one, in the theme's frames
    row = [('diszito3', 'big', '50% 40%', 'pink'), ('diszito6', 'tall', '50% 30%', 'lilac'), ('diszito4', 'tall', '50% 35%', 'sun')]
    photos = ''.join(f'<figure class="oj-photo oj-photo--{k} oj-photo--{tone}" data-reveal style="--i:{n}"><div class="frame">{new_pic(key, "(min-width: 960px) 50vw, 92vw" if k == "big" else "(min-width: 960px) 25vw, 46vw", pos=pos)}</div></figure>'
                     for n, (key, k, pos, tone) in enumerate(row))
    out.append(wrap(f'<div class="oj-photos">{photos}</div>', 'section section--tight oj-photos-wrap'))
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
    out.append(wrap(f'<div class="intro__grid"><div class="intro__visual" data-reveal><div class="oj-art">{pic(img, "(min-width: 960px) 36vw, 92vw")}</div></div>'
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
                    f'<div class="oj-together__art" data-reveal><div class="oj-art">{pic(img, "(min-width: 960px) 36vw, 92vw")}</div></div></div>{fig}', 'section section--pale'))
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
            qs.append(f'<details><summary><span class="oj-q__n" aria-hidden="true">{len(qs) + 1:02d}</span><h3 class="oj-q">{fix_links(q["html"])}</h3>{icon("plus")}</summary>'
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
    gift = (f'<div class="oj-gift__body">{heading(only_heading(sA), "oj-h2")}'
            + (f'<p class="oj-ticket">{fix_links(sub["html"])}</p>' if sub else '')
            + (f'<p class="oj-gift__intro">{fix_links(intro["html"])}</p>' if intro else '')
            + ('<ol class="oj-steps">' + ''.join(f'<li><p>{fix_links(b["html"])}</p></li>' for b in steps) + '</ol>' if steps else '')
            + f'<p class="btn-row">{"".join(button(h, l, "orchid") for h, l in g_go)}</p></div>')
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
    if maps:
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
            boxes.append(f'<article class="oj-loc oj-loc--{tone} oj-loc--contact" data-reveal>{seo_heading(h, "oj-loc__kw")}'
                         f'{heading(place, "oj-loc__place") if place else ""}{slot_rows(times)}{notes_html}'
                         f'<p class="btn-row">{"".join(button(hh, ll, "dark" if n == 0 else "ghost") for n, (hh, ll) in enumerate(links))}</p>'
                         f'{embed_html(emb["src"], norm(text_of(place["html"])) if place else "Térkép")}</article>')
            i += 2
            continue
        if boxes:
            out.append(f'<div class="oj-locs oj-locs--contact">{"".join(boxes)}</div>')
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
        out.append(f'<div class="oj-locs oj-locs--contact">{"".join(boxes)}</div>')
    return head + f'<div class="container oj-flow">{"".join(out)}</div>'


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

    def flush():
        if chapters:
            aside = ''
            if not any('oj-read__aside' in x for x in out):
                aside = f'<aside class="oj-read__aside"><div class="frame oj-read__photo">{theme_pic("olivia-golden-hour-arms", "(min-width: 960px) 28vw, 92vw")}</div></aside>'
            out.append(f'<div class="oj-read{" oj-read--first" if aside else ""}">{aside}<ol class="oj-chapters" start="{n - len(chapters) + 1}">{"".join(chapters)}</ol></div>')
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
            chapters.append(f'<li class="oj-chapter" data-reveal><span class="oj-chapter__n" aria-hidden="true">{n:02d}</span><div class="oj-prose oj-chapter__text">{body}</div></li>')
    flush()
    btn = ''.join(f'<p class="btn-row oj-read__go">{button(*only_link(b), "orchid", " btn--lg")}</p>' for b in tail)
    return head + f'<div class="container oj-flow oj-about">{"".join(out)}{btn}</div>'


def prices_page(p):
    secs = [s for s in p['sections'] if not is_prefooter(s) and not is_badges(s)]
    title = title_of(secs[0])
    sub = only_heading(secs[1])
    head = page_head(title, p['path'], [('', 'Főoldal')], seo_heading(sub, 'oj-head__sub') if sub else '', theme_pic(PAGE_PHOTO['árak'], eager=True), 'sun')
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
                    links += f'<li><a href="{esc(hh)}"{ext_attr(hh)}>{ol[1] if ol else fix_links(b["html"])}</a></li>'
                cards.append(f'<article class="price price--{["paper", "forest"][n % 2]}"><h4>{esc(count)}</h4><p class="price__amount">{esc(amount)}</p>'
                             f'<p class="price__unit">{esc(length)}</p><ul class="oj-price-links">{links}</ul>'
                             f'<a class="btn btn--{["dark", "orchid"][n % 2]}" href="{esc(href_of(im.get("href") or MOTIBRO))}" target="_blank" rel="noopener">Bejelentkezés</a></article>')
            length = PRICES[imgs[0]['file']][2]
            top = ''
            if note:
                top += f'<div class="oj-alert oj-alert--slim">{blocks_html([note])}</div>'
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
    body = f'<div class="container oj-flow"><div class="pricing oj-pricing">{"".join(groups)}</div></div>'
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
            body = blocks_html([b for b in bs if not only_link(b)], buttons=False)
            target = href_of(im['href']) if im and im.get('href') else (href_of(more[0]) if more else '#')
            media = f'<a class="oj-card__media" href="{esc(target)}" tabindex="-1">{pic(im, "(min-width: 960px) 30vw, 92vw")}</a>' if im else ''
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
    art = 'has-art-photo' if cover and cover['file'] in CUTOUTS else ''
    head = page_head(title, p['path'], [('', 'Főoldal'), ('jógáról', 'Jógáról')], '', pic(cover, '(min-width: 960px) 36vw, 90vw', True) if cover else '', TONES[sum(map(ord, p['path'])) % 4], True, extra=art)
    others = [c for c in journal_cards if c[0] != p['path']]
    k = [x[0] for x in journal_cards].index(p['path']) if p['path'] in [x[0] for x in journal_cards] else 0
    pick = (others[k % len(others):] + others)[:3] if others else []
    more = ''.join(f'<li><a href="{esc(link(path))}"><span class="oj-more__photo">{img}</span><span class="oj-more__title">{esc(t)}</span></a></li>' for path, t, img in pick)
    aside = (f'<aside class="aside-sticky oj-more"><p class="kicker">Jógáról</p><ul>{more}</ul>'
             f'<p class="btn-row"><a class="btn btn--ghost" href="{esc(link("jógáról"))}">A jógáról - Tőlem</a></p></aside>')
    return head + f'<div class="container"><article class="layout layout--aside"><div class="entry oj-prose">{blocks_html(body_blocks)}{"".join(extra)}</div>{aside}</article></div>'


# ---------------------------------------------------------------- build

LOGO_OPTIONS = [
    ('a', 'A – Puha retró', 'A moodboard hullámos, kerek betűi felől: lágy kisbetűk, az í ékezete egy pink levél, alatta ritkított JÓGA és a flow hulláma.'),
    ('b', 'B – A mostani dizájn betűjével', 'Az oldal címbetűje (Anton) nagybetűvel, ugyanazzal a levél-ékezettel és hullámmal. Ez változtat a legkevesebbet az oldalon.'),
    ('c', 'C – Telt és játékos', 'A moodboard játékos, kerek logóinak vonala: telt kisbetűk, barátságos és kis méretben is jól olvasható.'),
]


def logo_options_page():
    """Preview-only page with the three logo options in the real header and footer colours."""
    cards = ''
    for key, name, text in LOGO_OPTIONS:
        svg = open(os.path.join(HERE, 'assets', 'brand', 'options', f'logo-{key}.svg'), encoding='utf-8').read()
        svg = svg.replace('<svg ', '<svg class="oj-logo-opt__svg" ', 1)
        cards += (f'<article class="oj-logo-opt" data-reveal><h2 class="oj-logo-opt__name">{esc(name)}</h2><p>{esc(text)}</p>'
                  f'<div class="oj-logo-opt__bar">{svg}<span class="oj-logo-opt__nav">Rólam · Események · Árak · Jógáról · Kapcsolat</span>'
                  f'<span class="btn btn--orchid btn--sm">Bejelentkezés</span></div>'
                  f'<div class="oj-logo-opt__foot">{svg}</div></article>')
    pg = {'path': 'logo-javaslatok', 'title': f'Logójavaslatok | {SITE_TITLE}', 'sections': []}
    head = page_head({'t': 'h1', 'html': 'Logójavaslatok'}, 'logo-javaslatok', [('', 'Főoldal')],
                     '<p class="oj-head__sub">Olivia moodboardja alapján – a választott kerül a fejlécbe és a láblécbe.</p>', '', 'lilac')
    return document(pg, head + f'<div class="container oj-flow"><div class="oj-logo-opts">{cards}</div></div>', 'is-page', schema_base(pg))


def write(path, content):
    rel = path.strip('/')
    target = os.path.join(OUT, rel, 'index.html') if rel else os.path.join(OUT, 'index.html')
    os.makedirs(os.path.dirname(target), exist_ok=True)
    open(target, 'w', encoding='utf-8').write(content)


def main():
    global BASE, PREVIEW, OUT
    args = sys.argv[1:]
    if '--preview' in args:
        BASE = '/' + args[args.index('--preview') + 1].strip('/') + '/'
        PREVIEW = True
    if '--out' in args:
        OUT = os.path.abspath(args[args.index('--out') + 1])
    data = json.load(open(os.path.join(HERE, 'content', 'pages.json'), encoding='utf-8'))
    for pg in data['pages']:
        PAGES[pg['path']] = pg
    THEME_META.update(json.load(open(os.path.join(THEME, 'assets', 'img', 'images.json'), encoding='utf-8')))
    NEW_META.update(json.load(open(os.path.join(HERE, 'content', 'new-photos.json'), encoding='utf-8')))
    CUTOUTS.update(json.load(open(os.path.join(HERE, 'content', 'cutouts.json'), encoding='utf-8')))
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
                journal_cards.append((im['href'][5:], norm(text_of(h['html'])) if h else '', pic(im, '120px')))

    special = {'': home, 'főoldal': home, 'árak': prices_page, 'jógáról': journal_page, 'események': events_page,
               'rólam': rolam_page, 'bejelentkezés-jóga-bérlet': booking_page, 'kapcsolat': contact_page}
    tones = {'rólam': 'lilac', 'kapcsolat': 'sage', 'jógaóra-cégeknek': 'sun', 'bejelentkezés-jóga-bérlet': 'pink'}
    posts = {pp for pp, _, _ in journal_cards}
    for path, pg in PAGES.items():
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
                     'author': {'@id': ORIGIN + '/#olivia'}, 'mainEntityOfPage': ORIGIN + '/' + urllib.parse.quote(path)}
        write(path, document(pg, body, cls, schema_base(pg, extra)))

    # assets: the theme's styles, script and the photos used; the old site's photos
    os.makedirs(os.path.join(OUT, 'assets', 'img', 'theme'))
    shutil.copy(os.path.join(THEME, 'assets', 'site.css'), os.path.join(OUT, 'assets', 'site.css'))
    shutil.copy(os.path.join(THEME, 'assets', 'site.js'), os.path.join(OUT, 'assets', 'site.js'))
    shutil.copy(os.path.join(HERE, 'assets', 'oliviajoga.css'), os.path.join(OUT, 'assets', 'oliviajoga.css'))
    for key in used:
        for w in (600, 1200):
            shutil.copy(os.path.join(THEME, 'assets', 'img', f'{key}-{w}.jpg'), os.path.join(OUT, 'assets', 'img', 'theme', f'{key}-{w}.jpg'))
    shutil.copytree(os.path.join(HERE, 'assets', 'img', 'old'), os.path.join(OUT, 'assets', 'img', 'old'))
    shutil.copytree(os.path.join(HERE, 'assets', 'img', 'new'), os.path.join(OUT, 'assets', 'img', 'new'))

    # logo options for Olivia: only in the preview (her choice goes into the header afterwards)
    if PREVIEW:
        write('logo-javaslatok', logo_options_page())
        shutil.copytree(os.path.join(HERE, 'assets', 'brand'), os.path.join(OUT, 'assets', 'brand'))

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
        urls = ''.join(f'<url><loc>{esc(ORIGIN + "/" + urllib.parse.quote(pth))}</loc></url>' for pth in PAGES if pth != 'főoldal')
        open(os.path.join(OUT, 'sitemap.xml'), 'w', encoding='utf-8').write(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n')
        open(os.path.join(OUT, '.htaccess'), 'w').write(HTACCESS)
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
