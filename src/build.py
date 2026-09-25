#!/usr/bin/env python3
"""
Olivia Kovács Yoga — static site generator.

Builds from one source:
  1) site/            multi-page static site: clean URLs, one H1, unique title/description,
                      canonical, Open Graph, JSON-LD, breadcrumbs, sitemap.xml, robots.txt, 404.
  2) preview.html     single self-contained file with a hash router, for testing everything
                      (published as a claude.ai artifact).

Copy comes from content.json (exported from the WordPress theme's demo content), so the HTML
and the later WordPress build stay in sync.
"""
import html
import json
import os
import random
import re
import shutil
from datetime import date, timedelta

SRC = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.dirname(SRC)  # site/ and preview.html are written next to src/
SITE_URL = 'https://www.example.com'  # PLACEHOLDER: set the real domain before going live
BRAND = 'Olivia Kovács Yoga'
TEACHER = 'Olivia Kovács'
CITY, REGION = 'Fort Myers', 'FL'
EMAIL = 'olivia.kovacs6@gmail.com'
INSTAGRAM = 'https://www.instagram.com/oliivia_yoga/'
FACEBOOK = 'https://www.facebook.com/oliviajogaoktato'
AREAS = ['Downtown Fort Myers & the River District', 'McGregor Boulevard', 'Gateway', 'Whiskey Creek',
         'Fort Myers Beach', 'Sanibel & Captiva', 'Cape Coral', 'Estero', 'Bonita Springs', 'Lehigh Acres']

DATA = json.load(open(os.path.join(SRC, 'content.json')))
CSS = open(os.path.join(SRC, 'site.css')).read()
JS = open(os.path.join(SRC, 'site.js')).read()

e = html.escape

IMGS = json.load(open(os.path.join(SRC, 'img', 'images.json')))
USED_IMGS = set()
CLASS_PHOTO = {'hatha-flow': 'hatha-flow-studio-class', 'slow-flow': 'final-relaxation', 'private-yoga': 'olivia-golden-hour-prayer',
               'sound-yoga': 'sound-yoga-gong-bowls', 'office-yoga': 'evening-lawn-yoga', 'yoga-for-athletes': 'outdoor-bridge-pose',
               'online-yoga': 'small-group-class'}
POST_PHOTO = {'private-yoga-fort-myers-what-to-expect': 'tree-pose-meadow', 'hatha-flow-vs-slow-flow': 'evening-slow-flow',
              'sunrise-beach-yoga-fort-myers': 'dancer-pose-beach'}
CLASS_ASIDE = {'hatha-flow': 'group-class-chair-pose', 'slow-flow': 'evening-slow-flow', 'sound-yoga': 'garden-yoga-evening',
               'yoga-for-athletes': 'outdoor-class-garden', 'online-yoga': 'final-relaxation'}
TRANSPARENT = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=='


def photo(key, sizes='100vw', cls='', eager=False, alt=None):
    m = IMGS[key]
    USED_IMGS.add(key)
    a = e(m['alt'] if alt is None else alt, True)
    style = ' style="object-position:{}"'.format(m['pos'])
    if Ctx.mode == 'preview':
        return '<img class="photo {}" src="{}" data-img="{}" alt="{}" width="{}" height="{}"{} decoding="async">'.format(
            cls, TRANSPARENT, key, a, m['w'], m['h'], style)
    pre = '../' * Ctx.depth + 'assets/img/' + key
    return ('<img class="photo {c}" src="{p}-1200.jpg" srcset="{p}-600.jpg 600w, {p}-1200.jpg {w}w" sizes="{s}" alt="{a}" '
            'width="{w}" height="{h}"{st} {ld} decoding="async">').format(
        c=cls, p=pre, w=m['w'], h=m['h'], s=sizes, a=a, st=style,
        ld='fetchpriority="high"' if eager else 'loading="lazy"')


def gallery(keys, cls=''):
    shapes = ['g1', 'g2', 'g3', 'g4']
    items = ''.join('<figure class="gallery__item {}" data-reveal>{}</figure>'.format(
        shapes[i % 4], photo(k, '(min-width: 960px) 30vw, 50vw')) for i, k in enumerate(keys))
    return '<div class="gallery {}">{}</div>'.format(cls, items)

# --------------------------------------------------------------------------
# Routing
# --------------------------------------------------------------------------

PAGE_PATHS = {
    'home': '', 'about': 'about/', 'classes': 'yoga-classes/', 'private-yoga': 'private-yoga/',
    'schedule-pricing': 'schedule-pricing/', 'corporate-yoga': 'corporate-yoga/', 'events': 'events/',
    'faq': 'faq/', 'journal': 'journal/', 'contact': 'contact/',
}
CLASS_LINK_OVERRIDE = {'private-yoga': 'private-yoga', 'office-yoga': 'corporate-yoga'}
CLASS_PAGES = [s for s in DATA['classes'] if s not in CLASS_LINK_OVERRIDE]


class Ctx:
    mode = 'multi'   # or 'preview'
    depth = 0


def url(path, query='', anchor=''):
    """Internal link for the current build mode."""
    if Ctx.mode == 'preview':
        out = '#/' + path
        extra = []
        if query:
            extra.append(query)
        if anchor:
            extra.append('to=' + anchor)
        return out + ('?' + '&'.join(extra) if extra else '')
    prefix = '../' * Ctx.depth
    out = (prefix + path) if (prefix or path) else './'
    if query:
        out += '?' + query
    if anchor:
        out += '#' + anchor
    return out


def page_url(key, anchor=''):
    return url(PAGE_PATHS[key], anchor=anchor)


def class_url(slug):
    if slug in CLASS_LINK_OVERRIDE:
        return page_url(CLASS_LINK_OVERRIDE[slug])
    return url('yoga-classes/' + slug + '/')


def post_url(slug):
    return url('journal/' + slug + '/')


def contact_url(topic=''):
    return url('contact/', query=('topic=' + topic) if topic else '', anchor='book')


BOOK = lambda: contact_url('group')          # PLACEHOLDER until a booking system is chosen
PRIVATE = lambda: contact_url('private')


def resolve_tokens(text):
    def tok(m):
        kind, slug, anchor = m.group(1), m.group(2), (m.group(3) or '')[1:]
        if kind == 'page':
            return url(PAGE_PATHS[slug], anchor=anchor)
        if kind == 'class':
            return class_url(slug)
        if kind == 'contact':
            return contact_url(slug)
        return '#'
    text = re.sub(r'\{\{(page|class|contact):([a-z0-9\-]+)\}\}(#[a-z0-9\-]+)?', tok, text)
    return text.replace('{{private}}', PRIVATE())


# --------------------------------------------------------------------------
# Graphic language: flat colour blocks, a spinning sticker, a type marquee
# --------------------------------------------------------------------------

# Accent colours (CSS custom properties) rotated across tiles, page heads and price cards.
ACCENTS = ['lilac', 'pink', 'sun', 'sage']


def accent_for(seed):
    return ACCENTS[sum(ord(ch) for ch in str(seed)) % len(ACCENTS)]


def brand_mark():
    return ('<svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true">'
            '<circle cx="20" cy="20" r="19" fill="#2B5036"/>'
            '<path d="M9 25c4-3.5 8 2.5 12-1s7-2.5 10-.5" fill="none" stroke="#C6A3EE" stroke-width="2.6" stroke-linecap="round"/>'
            '<circle cx="26.5" cy="13" r="4" fill="#EE3F9A"/></svg>')


def sticker(text, cls=''):
    """Round, slowly spinning badge with text on a circle."""
    return ('<span class="sticker {c}" aria-hidden="true"><svg viewBox="0 0 120 120">'
            '<defs><path id="st-{i}" d="M60 60 m-44 0 a44 44 0 1 1 88 0 a44 44 0 1 1 -88 0"/></defs>'
            '<circle cx="60" cy="60" r="58"/>'
            '<text><textPath href="#st-{i}" textLength="272">{t}</textPath></text></svg>'
            '<span class="sticker__core">✺</span></span>').format(c=cls, i=sum(ord(ch) for ch in text + cls) % 9999, t=e(text))


def marquee(words, cls=''):
    run = ''.join('<span>{}</span><i aria-hidden="true">✺</i>'.format(e(w)) for w in words)
    return ('<div class="marquee {}" aria-hidden="true"><div class="marquee__track">'
            '<div>{r}</div><div>{r}</div></div></div>').format(cls, r=run)


def badge(icon_name, tone='lilac'):
    return '<span class="badge-icon badge-icon--{}">{}</span>'.format(tone, icon(icon_name))


def brush():
    return ''


def wave(frm, to, flip=False, seed=1):
    return ''


SVG_DEFS = ''


ICONS = {
    'sun': '<circle cx="12" cy="12" r="4.5"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
    'moon': '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/>',
    'home': '<path d="M3.5 11 12 4l8.5 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/>',
    'wave': '<path d="M2.5 15c2.5 0 2.5-2 5-2s2.5 2 5 2 2.5-2 5-2 2.5 2 4 2"/><path d="M2.5 19c2.5 0 2.5-2 5-2s2.5 2 5 2 2.5-2 5-2 2.5 2 4 2"/><circle cx="12" cy="7" r="3"/>',
    'building': '<rect x="4.5" y="3.5" width="15" height="17" rx="1"/><path d="M8.5 7.5h2M13.5 7.5h2M8.5 11.5h2M13.5 11.5h2M10.5 20.5v-4h3v4"/>',
    'screen': '<rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M9 20h6M12 16.5V20"/>',
    'clock': '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    'pin': '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="10" r="2.3"/>',
    'mail': '<rect x="3" y="5.5" width="18" height="13" rx="1.5"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
    'phone': '<path d="M5 3.5h3.5l1.5 4.5-2.2 1.4a11 11 0 0 0 6.8 6.8l1.4-2.2 4.5 1.5V19a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 3.5 5.1 1.5 1.5 0 0 1 5 3.5z"/>',
    'instagram': '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6" fill="currentColor"/>',
    'facebook': '<path d="M14.5 8.5H17V4.8h-2.5a4 4 0 0 0-4 4v2.2H8v3.6h2.5v6.9h3.6v-6.9h2.6l.6-3.6h-3.2V9.3a.8.8 0 0 1 .4-.8z"/>',
    'calendar': '<rect x="3.5" y="5" width="17" height="15.5" rx="1.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
    'gift': '<rect x="3.5" y="9" width="17" height="11.5" rx="1"/><path d="M3.5 12.5h17M12 9v11.5"/><path d="M12 9c-1.5-3.5-5.5-4-5.5-1.5S9.5 9 12 9zm0 0c1.5-3.5 5.5-4 5.5-1.5S14.5 9 12 9z"/>',
    'menu': '<path d="M4 8h16M4 16h16"/>',
    'close': '<path d="M6 6l12 12M18 6 6 18"/>',
    'plus': '<path d="M12 5v14M5 12h14"/>',
    'chev': '<path d="m6 9 6 6 6-6"/>',
    'users': '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.2c2.4.2 4.2 1.9 4.8 4.8"/>',
    'music': '<circle cx="7" cy="17.5" r="2.5"/><circle cx="17" cy="15.5" r="2.5"/><path d="M9.5 17.5V6l10-2v11.5"/><path d="M9.5 9.5l10-2"/>',
    'leaf': '<path d="M5 19C5 10 11 5 20 4c0 9-5 15-14 15z"/><path d="M5 19 13 11"/>',
    'check': '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    'arrow': '<path d="M4.5 12h15M13.5 6l6 6-6 6"/>',
}


def icon(name, cls='icon'):
    return '<svg class="{}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{}</svg>'.format(cls, ICONS[name])


# --------------------------------------------------------------------------
# Components
# --------------------------------------------------------------------------

def btn(href, label, style='primary', extra=''):
    return '<a class="btn btn--{}{}" href="{}">{}</a>'.format(style, (' ' + extra) if extra else '', e(href, True), e(label))


def time12(hm):
    h, m = [int(x) for x in hm.split(':')]
    suffix = 'am' if h < 12 else 'pm'
    return '{}:{:02d} {}'.format((h % 12) or 12, m, suffix)


def intensity(n):
    n = int(n)
    label = {1: 'Gentle', 2: 'Moderate', 3: 'Dynamic'}[n]
    dots_ = ''.join('<i class="{}"></i>'.format('on' if i < n else '') for i in range(3))
    return '<span class="intensity" title="{0}"><span class="sr-only">Intensity: {0}</span>{1}</span>'.format(label, dots_)


def classes_list(exclude=()):
    rows = []
    n = 0
    for slug, c in DATA['classes'].items():
        if slug in exclude:
            continue
        n += 1
        rows.append(
            '<li class="class-row class-row--{tone}"><a href="{href}"><span class="class-row__name">{t}</span>'
            '<span class="class-row__desc">{d}</span><span class="class-row__meta"><span>{du}</span><span>{lv}</span>{it}</span>'
            '<span class="class-row__thumb" aria-hidden="true">{ph}</span><span class="class-row__go" aria-hidden="true">{arr}</span></a></li>'.format(
                tone=ACCENTS[(n - 1) % len(ACCENTS)], href=e(class_url(slug), True), t=e(c['title']), d=e(c['excerpt']),
                du=e(c['duration']), lv=e(c['level']), it=intensity(c['intensity']),
                ph=photo(CLASS_PHOTO[slug], '200px', alt=''), arr=icon('arrow')))
    return '<ul class="class-list">' + ''.join(rows) + '</ul>'


DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']


def schedule():
    """Weekly timetable laid out like a studio poster: one row per day, big day letters, sessions across."""
    by_day = {}
    for s in DATA['sessions']:
        by_day.setdefault(int(s['day']), []).append(s)
    out = ['<div class="schedule">']
    for n, name in enumerate(DAYS, start=1):
        items = sorted(by_day.get(n, []), key=lambda s: s['start'].zfill(5))
        short = name[:3]
        if not items:
            out.append('<div class="day is-empty"><h3><abbr title="{}">{}</abbr></h3><p>Private sessions only — <a href="{}">ask for a time</a></p></div>'.format(
                name, short, e(PRIVATE(), True)))
            continue
        out.append('<div class="day"><h3><abbr title="{}">{}</abbr></h3><ul>'.format(name, short))
        for s in items:
            c = DATA['classes'][s['class']]
            out.append(
                '<li class="session session--{tone}"><span class="session__time">{st} – {en}</span>'
                '<span class="session__class"><a href="{cu}">{ct}</a></span><span class="session__place">{loc}</span>{note}'
                '<a class="session__book" href="{bu}">Book<span class="sr-only"> {ct}, {day} {st}</span> {arr}</a></li>'.format(
                    tone=ACCENTS[list(DATA['classes']).index(s['class']) % len(ACCENTS)],
                    st=time12(s['start']), en=time12(s['end']), cu=e(class_url(s['class']), True), ct=e(c['title']),
                    loc=e(s['location']), note=('<span class="session__note">{}</span>'.format(e(s['note'])) if s['note'] else ''),
                    bu=e(BOOK(), True), day=name, arr=icon('arrow')))
        out.append('</ul></div>')
    out.append('</div>')
    return ''.join(out)


def pricing():
    out = ['<div class="pricing">']
    tones = ['paper', 'forest', 'lilac', 'sun', 'pink']
    for i, p in enumerate(DATA['prices']):
        feats = [f for f in p['features'].split('\n') if f.strip()]
        u = p['url']
        if u == '{{private}}':
            href = PRIVATE()
        elif u.startswith('{{'):
            href = resolve_tokens(u)
        else:
            href = BOOK()
        featured = p['featured'] == '1'
        tone = 'forest' if featured else tones[i % len(tones)]
        out.append('<article class="price price--{}">{}<h3>{}</h3><p class="price__amount">{}</p><p class="price__unit">{}</p><ul>{}</ul>{}</article>'.format(
            tone, (sticker('Most popular ✺ Most popular ✺ ', 'sticker--price') + '<span class="sr-only">Most popular</span>') if featured else '',
            e(p['title']), e(p['price']), e(p['unit']),
            ''.join('<li>{}</li>'.format(e(f)) for f in feats),
            btn(href, p['cta'], 'orchid' if featured else 'dark')))
    out.append('</div>')
    return ''.join(out)


def faq(home_only=False):
    items = [f for f in DATA['faqs'] if (f['home'] == '1' or not home_only)]
    out = ['<div class="faq">']
    for i, f in enumerate(items):
        ans = ''.join('<p>{}</p>'.format(e(p)) for p in f['a'].split('\n\n'))
        out.append('<details{}><summary><span>{}</span>{}</summary><div class="answer">{}</div></details>'.format(
            ' open' if i == 0 else '', e(f['q']), icon('plus'), ans))
    out.append('</div>')
    return ''.join(out)





def events(limit=3):
    out = []
    for i, (slug, ev) in enumerate(DATA['events'].items()):
        d = date.today() + timedelta(days=30)
        title = ev['title'].replace(' (sample — edit before publishing)', '')
        out.append('<li class="event"><a href="{href}"><span class="event__date event__date--{tone}"><span>{mon}</span><b>{day}</b></span>'
                   '<span><span class="event__title">{t}<span class="badge">Sample</span></span>'
                   '<span class="event__meta">{wd}, {st} – {en} in {venue}. {price}</span></span><span class="event__go" aria-hidden="true">{arr}</span></a></li>'.format(
                       href=e(contact_url('event'), True), tone=['pink', 'lilac', 'sun'][i % 3], mon=d.strftime('%b'), day=d.day,
                       t=e(title), wd=d.strftime('%A'), st=time12(ev['start']), en=time12(ev['end']),
                       venue=e(ev['venue']), price=e(ev['price']), arr=icon('arrow')))
    return ('<ul class="events">' + ''.join(out[:limit]) + '</ul>'
            '<p class="note-demo">Sample listing so you can see the layout. Real events will be announced here and on Instagram first.</p>')


def areas():
    return '<ul class="areas">' + ''.join('<li class="area--{}">{}</li>'.format(ACCENTS[i % len(ACCENTS)], e(a)) for i, a in enumerate(AREAS)) + '</ul>'


def breath():
    return ('<div class="breath" data-breath><div class="breath__circle" aria-hidden="true">'
            '<span class="breath__ring breath__ring--1"></span><span class="breath__ring breath__ring--2"></span>'
            '<span class="breath__ring breath__ring--3"></span><span class="breath__core"></span></div><div>'
            '<p class="kicker">Try it now · 30 seconds</p>'
            '<h2>Before you decide, take three breaths with me</h2>'
            '<p class="lead">Four counts in, six counts out. A longer exhale is the quickest way to tell your nervous system it\'s safe to slow down.</p>'
            '<button type="button" class="btn btn--light" data-breath-start>Start breathing</button>'
            '<p class="breath__status" aria-live="polite" data-breath-status></p></div></div>')


TOPICS = [('group', 'Group class'), ('private', 'Private yoga session'), ('corporate', 'Corporate or team yoga'),
          ('athletes', 'Yoga for athletes'), ('event', 'Event or retreat'), ('gift', 'Gift card'), ('other', 'Something else')]


def contact_form():
    opts = ''.join('<option value="{}">{}</option>'.format(k, e(v)) for k, v in TOPICS)
    return ('<form class="form" id="book" action="#demo" method="post" novalidate data-contact>'
            '<p class="form__notice form__notice--ok" role="status" data-form-notice hidden></p>'
            '<div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            '<div class="form__row">'
            '<label class="field"><span>Your name</span><input type="text" name="name" required autocomplete="name"><span class="field__error" aria-live="polite"></span></label>'
            '<label class="field"><span>Email</span><input type="email" name="email" required autocomplete="email"><span class="field__error" aria-live="polite"></span></label>'
            '</div><div class="form__row">'
            '<label class="field"><span>Phone (optional)</span><input type="tel" name="phone" autocomplete="tel"></label>'
            '<label class="field"><span>I\'m interested in</span><select name="topic">{}</select></label>'
            '</div>'
            '<label class="field"><span>Your area (e.g. McGregor, Cape Coral, Fort Myers Beach)</span><input type="text" name="area"></label>'
            '<label class="field"><span>Message</span><textarea name="message" rows="5" required placeholder="Tell me a little about your practice, any injuries I should know about, and when you\'d like to start."></textarea><span class="field__error" aria-live="polite"></span></label>'
            '<button type="submit" class="btn btn--primary">Send message</button>'
            '<p class="form__small">I\'ll only use your details to reply to you.</p>'
            '</form>').format(opts)


def post_cards(exclude=()):
    out = ['<div class="cards">']
    for i, (slug, p) in enumerate(DATA['posts'].items()):
        if slug in exclude:
            continue
        out.append('<article class="card card--{}"><a href="{}"><div class="card__media">{}</div><div class="card__body">'
                   '<span class="card__cat">{}</span><h3 class="card__title">{}</h3><p class="card__excerpt">{}</p>'
                   '<span class="card__more">Read {}</span></div></a></article>'.format(
                       ACCENTS[i % len(ACCENTS)], e(post_url(slug), True), photo(POST_PHOTO[slug], '(min-width: 960px) 30vw, 92vw'),
                       e(p['category']), e(p['title']), e(p['excerpt']), icon('arrow')))
    out.append('</div>')
    return ''.join(out)


def section_head(title, text='', center=False, tag='h2', idattr='', kicker=''):
    return '<header class="section__head{}" data-reveal>{k}<{t}{i}>{}</{t}>{}</header>'.format(
        ' section__head--center' if center else '', e(title),
        '<p class="lead">{}</p>'.format(e(text)) if text else '', t=tag, i=(' id="%s"' % idattr) if idattr else '',
        k='<p class="kicker">{}</p>'.format(e(kicker)) if kicker else '')


SHORTCODES = {
    'oy_classes': lambda a: classes_list(),
    'oy_schedule': lambda a: schedule(),
    'oy_pricing': lambda a: pricing(),
    'oy_faq': lambda a: faq(),
    'oy_events': lambda a: events(),
    'oy_areas': lambda a: areas(),
    'oy_contact_form': lambda a: contact_form(),
    'oy_breath': lambda a: breath(),
    'oy_booking_link': lambda a: '<p class="btn-row">' + btn(BOOK(), a.get('label', 'Book a class')) + '</p>',
    'oy_private_link': lambda a: '<p class="btn-row">' + btn(PRIVATE(), a.get('label', 'Book a private session')) + '</p>',
    'oy_gift_link': lambda a: '<p class="btn-row">' + btn(contact_url('gift'), a.get('label', 'Buy a gift card')) + '</p>',
    'oy_button': lambda a: '<p class="btn-row">' + btn(a.get('url', '#'), a.get('label', 'Book')) + '</p>',
}
WIDE = {'oy_classes', 'oy_schedule', 'oy_pricing', 'oy_events'}


def render_content(raw, wide_ok=True):
    """Page copy -> blocks. Prose is wrapped in .prose; wide components break out of it."""
    raw = resolve_tokens(raw)
    raw = re.sub(r'<p>\s*(\[oy_[^\]]+\])\s*</p>', r'\1', raw)
    parts = re.split(r'(\[oy_[a-z_]+[^\]]*\])', raw)
    out, buf = [], []

    def flush():
        txt = ''.join(buf).strip()
        if txt:
            out.append('<div class="prose">' + txt + '</div>')
        buf.clear()

    for part in parts:
        m = re.match(r'\[(oy_[a-z_]+)([^\]]*)\]', part)
        if not m:
            buf.append(part)
            continue
        name = m.group(1)
        attrs = dict(re.findall(r'(\w+)="([^"]*)"', m.group(2)))
        if 'url' in attrs:
            attrs['url'] = html.unescape(attrs['url'])
        html_ = SHORTCODES.get(name, lambda a: '')(attrs)
        if name in WIDE and wide_ok:
            flush()
            out.append('<div class="block-wide">' + html_ + '</div>')
        else:
            buf.append(html_)
    flush()
    return '\n'.join(out)


# --------------------------------------------------------------------------
# Layout
# --------------------------------------------------------------------------

NAV = [('classes', 'Classes'), ('private-yoga', 'Private yoga'), ('schedule-pricing', 'Schedule & pricing'),
       ('corporate-yoga', 'Corporate'), ('events', 'Events'), ('about', 'About'), ('journal', 'Journal'), ('contact', 'Contact')]


def header(current=''):
    subs = ''.join('<li><a href="{}">{}</a></li>'.format(e(class_url(s), True), e(DATA['classes'][s]['title'])) for s in CLASS_PAGES)
    items = []
    for key, label in NAV:
        cur = ' aria-current="page"' if key == current else ''
        href = e(page_url(key), True)
        if key == 'classes':
            items.append('<li class="has-sub"><a href="{}" data-path="{}"{}>{}</a><button class="sub-toggle" type="button" aria-expanded="false" aria-label="Show classes">{}</button>'
                         '<ul class="sub"><li><a href="{}">All classes</a></li>{}</ul></li>'.format(
                             href, PAGE_PATHS[key], cur, label, icon('chev'), href, subs))
        else:
            items.append('<li><a href="{}" data-path="{}"{}>{}</a></li>'.format(href, PAGE_PATHS[key], cur, e(label)))
    drawer = ''.join('<li><a href="{}">{}</a></li>'.format(e(page_url(k), True), e(l)) for k, l in NAV)
    drawer_sub = ''.join('<li class="drawer__sub"><a href="{}">{}</a></li>'.format(e(class_url(s), True), e(DATA['classes'][s]['title'])) for s in CLASS_PAGES)
    drawer = drawer.replace('Classes</a></li>', 'Classes</a></li>' + drawer_sub, 1)
    return (
        '<a class="skip-link" href="#main">Skip to content</a>'
        '<div class="notice"><p><b>New in Fort Myers</b> Group flows, private sessions at your home or on the beach, and yoga for teams.</p></div>'
        '<header class="site-header" data-header><div class="container site-header__inner">'
        '<a class="brand" href="{home}" rel="home">{mark}<span><span class="brand__name">Olivia Kovács</span><span class="brand__sub">Flow yoga · Fort Myers</span></span></a>'
        '<nav class="nav" aria-label="Main"><ul>{items}</ul></nav>'
        '<a class="btn btn--primary btn--sm header-cta" href="{book}">Book a class</a>'
        '<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="drawer" data-nav-toggle>'
        '<span class="i-open">{menu}</span><span class="i-close">{close}</span><span class="sr-only">Menu</span></button>'
        '</div></header>'
        '<div class="drawer" id="drawer"><nav aria-label="Mobile"><ul>{drawer}</ul></nav>'
        '<p class="btn-row">{b1}{b2}</p><p class="drawer__contact"><a href="mailto:{mail}">{mail}</a></p></div>'
    ).format(home=e(url(''), True), mark=brand_mark(), items=''.join(items), book=e(BOOK(), True),
             menu=icon('menu'), close=icon('close'), drawer=drawer,
             b1=btn(BOOK(), 'Book a class', 'orchid'), b2=btn(PRIVATE(), 'Private yoga', 'line-light'), mail=EMAIL)


def footer():
    links = ''.join('<li><a href="{}">{}</a></li>'.format(e(page_url(k), True), e(l)) for k, l in
                    [('classes', 'Classes'), ('private-yoga', 'Private yoga'), ('schedule-pricing', 'Schedule & pricing'),
                     ('corporate-yoga', 'Corporate & athletes'), ('events', 'Events')])
    links2 = ''.join('<li><a href="{}">{}</a></li>'.format(e(page_url(k), True), e(l)) for k, l in
                     [('about', 'About Olivia'), ('faq', 'FAQ'), ('journal', 'Journal'), ('contact', 'Contact & booking')])
    links2 += '<li><a href="{}">Gift cards</a></li>'.format(e(page_url('schedule-pricing', 'gift-cards'), True))
    return (
        '<footer class="site-footer"><div class="container"><div class="footer__grid">'
        '<div class="footer__brand"><p class="footer__motto">Dynamic yet gentle — just enough of everything.</p>'
        '<p>Hatha flow, slow flow and private yoga in Fort Myers, FL and across Southwest Florida.</p>'
        '<p class="btn-row">{b}</p></div>'
        '<nav aria-label="Practice"><h2>Practice</h2><ul>{links}</ul></nav>'
        '<nav aria-label="More"><h2>More</h2><ul>{links2}</ul></nav>'
        '<div><h2>Say hello</h2><ul class="footer__contact"><li>{mi}<a href="mailto:{mail}">{mail}</a></li><li>{pi}<span>Fort Myers, FL</span></li></ul>'
        '<p class="social"><a href="{ig}" rel="noopener" target="_blank">{igi}<span class="sr-only">Instagram</span></a>'
        '<a href="{fb}" rel="noopener" target="_blank">{fbi}<span class="sr-only">Facebook</span></a></p></div>'
        '</div><p class="footer__word" aria-hidden="true">Olivia <span>Kovács</span></p>'
        '<div class="footer__bottom"><p>&copy; {yr} {brand}</p>'
        '<p>Yoga is not a substitute for medical care. If you have a health condition, talk to your doctor before starting a new practice.</p></div></div></footer>'
        '<nav class="action-bar" aria-label="Quick actions" data-action-bar><a href="{book}">{ci}Book</a><a href="{priv}">{hi}Private</a><a href="mailto:{mail}">{mi}Email</a></nav>'
    ).format(links=links, links2=links2, mail=EMAIL, mi=icon('mail'), pi=icon('pin'), b=btn(BOOK(), 'Book a class', 'orchid'),
             ig=INSTAGRAM, igi=icon('instagram'), fb=FACEBOOK, fbi=icon('facebook'), yr=date.today().year, brand=BRAND,
             book=e(BOOK(), True), priv=e(PRIVATE(), True), ci=icon('calendar'), hi=icon('home'))


def crumbs(trail):
    items = ['<li><a href="{}">Home</a></li>'.format(e(url(''), True))]
    for i, (name, href) in enumerate(trail):
        if i == len(trail) - 1:
            items.append('<li aria-current="page">{}</li>'.format(e(name)))
        else:
            items.append('<li><a href="{}">{}</a></li>'.format(e(href, True), e(name)))
    return '<nav class="crumbs" aria-label="Breadcrumb"><ol>' + ''.join(items) + '</ol></nav>'


def page_head(h1, lead, trail, seed, meta='', ph=None):
    tone = accent_for(seed)
    size = ' page-head--long' if len(h1) > 34 else ''
    lead_html = '<p class="lead">{}</p>'.format(e(lead)) if lead else ''
    if ph:
        return ('<header class="page-head page-head--{tone}{size}"><div class="container page-head__grid"><div class="page-head__text">{cr}<h1>{h1}</h1>{lead}{meta}</div>'
                '<div class="page-head__photo"><div class="frame">{img}</div>{st}</div></div></header>').format(
            tone=tone, size=size, cr=crumbs(trail), h1=e(h1), lead=lead_html, meta=meta,
            st=sticker('Olivia Kovács ✺ Fort Myers ✺ ', 'sticker--head'),
            img=photo(ph, '(min-width: 960px) 36vw, 90vw', eager=True))
    return ('<header class="page-head page-head--{tone}{size}"><div class="container"><div class="page-head__text">{cr}<h1>{h1}</h1>{lead}{meta}</div></div></header>').format(
        tone=tone, size=size, cr=crumbs(trail), h1=e(h1), lead=lead_html, meta=meta)


# --------------------------------------------------------------------------
# Pages
# --------------------------------------------------------------------------

PAGES = []  # dicts: path, key, title, desc, body(fn), crumbs(list of (name, path)), schema(list), ogtype


def add(path, key, title, desc, body, trail=None, schema=None, ogtype='website', h1=''):
    PAGES.append(dict(path=path, key=key, title=title, desc=desc, body=body, trail=trail or [], schema=schema or [], ogtype=ogtype, h1=h1))


def home_body():
    home = DATA['pages']['home']['content']
    ways = [('Group classes', 'Hatha flow and slow flow, every week in Fort Myers.', 'schedule-pricing', 'users', 'lilac'),
            ('Private yoga', 'One-on-one at your home, on your lanai or on the beach.', 'private-yoga', 'home', 'pink'),
            ('Teams & athletes', 'Office yoga, team building and mobility for sport.', 'corporate-yoga', 'building', 'sun')]
    ways_html = ''.join('<a class="way way--{}" href="{}">{}<h3>{}</h3><p>{}</p><span class="way__go" aria-hidden="true">{}</span></a>'.format(
        tone, e(page_url(k), True), icon(ic, 'icon way__icon'), e(t), e(d), icon('arrow'))
        for t, d, k, ic, tone in ways)
    places = [('home', 'Your home or lanai', 'No driving, no parking, no crowd. Just room for a mat and a little quiet.', 'lilac'),
              ('wave', 'On the beach', 'Sunrise or sunset on the sand at Fort Myers Beach, Sanibel or wherever your towel is.', 'sun'),
              ('building', 'Condo & HOA clubhouses', 'A regular class for your community, paced for your residents.', 'pink'),
              ('screen', 'Online', 'Live, one-on-one, from wherever you are — with the same attention to detail.', 'sage')]
    places_html = ''.join('<li>{}<div><h3>{}</h3><p>{}</p></div></li>'.format(badge(ic, tone), e(t), e(d)) for ic, t, d, tone in places)
    steps = [('Send a message', 'Tell me where you are and when suits you.'),
             ('A 15-minute call', 'About your body, your goals and anything to be careful with.'),
             ('Your first session', 'I bring the plan; you bring a mat if you have one.'),
             ('Keep going', 'Single sessions or a 5-session pack, at your rhythm.')]
    steps_html = ''.join('<li><div><b>{}</b><span>{}</span></div></li>'.format(e(a), e(b)) for a, b in steps)

    return ''.join([
        # Hero
        '<section class="hero" aria-labelledby="hero-title"><div class="container hero__grid"><div class="hero__text">',
        '<p class="kicker">Hatha flow · Slow flow · Private yoga</p>',
        '<h1 class="hero__title" id="hero-title"><span class="hero__l1">Flow</span> <span class="hero__l2"><mark>yoga</mark></span> <span class="hero__l3">in Fort Myers</span></h1>',
        '<p class="hero__lead">Dynamic yet gentle hatha flow classes with Olivia Kovács — group classes, private yoga at your home, lanai or on the beach, and yoga for teams across Southwest Florida.</p>',
        '<p class="btn-row">{}{}</p>'.format(btn(BOOK(), 'Book a class'), btn(PRIVATE(), 'Private yoga at your place', 'ghost')),
        '</div><div class="hero__visual">',
        '<div class="hero__block" aria-hidden="true"></div>',
        '<div class="frame hero__photo">{}</div>'.format(photo('olivia-golden-hour-prayer', '(min-width: 960px) 44vw, 92vw', eager=True)),
        '<p class="hero__tag"><b>Olivia Kovács</b> Certified hatha yoga teacher · Budapest → Fort Myers</p>',
        sticker('Beginners welcome ✺ in every class ✺ ', 'sticker--hero'),
        '</div></div></section>',
        marquee(['Hatha flow', 'Slow flow', 'Private yoga', 'Sound yoga', 'Beach sunrise', 'Yoga for teams', 'Fort Myers, FL']),
        # Ways
        '<section class="section section--tight" aria-label="Ways to practice"><div class="container"><div class="ways" data-reveal>{}</div></div></section>'.format(ways_html),
        # Intro
        '<section class="section" id="intro" aria-labelledby="intro-title"><div class="container"><div class="intro__grid">',
        '<div class="intro__visual" data-reveal><div class="frame">{}</div><p class="intro__caption">Certified hatha yoga teacher since 2023</p></div>'.format(
            photo('olivia-riverside-portrait', '(min-width: 960px) 36vw, 92vw')),
        '<div class="prose" data-reveal><p class="kicker">Meet your teacher</p>{}<p class="btn-row">{}{}</p></div>'.format(
            home.replace('<h2>', '<h2 id="intro-title">', 1),
            btn(page_url('about'), 'Meet Olivia', 'ghost'), '<a class="text-link" href="{}">See all classes</a>'.format(e(page_url('classes'), True))),
        '</div>',
        '<figure class="contrast" data-reveal><div><p class="contrast__word">Awareness</p><p>I notice. I see. I observe. I allow — and then I respond.</p></div>'
        '<div class="contrast__col--muted"><p class="contrast__word">Control</p><p>I want to steer it. I grip it, regulate it, push it down.</p></div>'
        '<figcaption>The most important difference yoga has taught me so far. — Olivia</figcaption></figure>',
        '</div></section>',
        # Classes
        '<section class="section section--tight" id="classes" aria-labelledby="classes-title"><div class="container">',
        section_head('Yoga classes in Fort Myers', 'Breathwork, strength, stretching, balance, a real flow state and a long relaxation — in every class. Choose the pace that suits you today.',
                     idattr='classes-title', kicker='Seven ways in'),
        classes_list(), '</div></section>',
        # Ha / Tha
        '<section class="section section--moss hatha" aria-labelledby="hatha-title"><div class="container hatha__grid">',
        '<div data-reveal><div class="hatha__pair"><div class="hatha__orb hatha__orb--sun"><span>Ha</span></div><div class="hatha__orb hatha__orb--moon"><span>Tha</span></div></div>'
        '<div class="hatha__caption"><span>sun: effort, heat, strength</span><span>moon: ease, cool, surrender</span></div></div>',
        '<div data-reveal><p class="kicker">What “hatha” means</p><h2 id="hatha-title">Ha is the sun. Tha is the moon.</h2>'
        '<p class="lead">Hatha yoga balances the active and the receptive in us: effort and ease, strength and surrender. When that balance tips, we feel it first as tension or restlessness.</p>'
        '<p>On the mat we use the body to find the balance again. The poses are a doorway to meditation — a more dynamic doorway — and only one of the eight limbs of yoga.</p>'
        '<p class="btn-row">{}</p></div>'.format(btn(page_url('faq'), 'Read more about hatha yoga', 'line-light')),
        '</div></section>',
        # Private
        '<section class="section section--pale" id="private" aria-labelledby="private-title"><div class="container private__grid">',
        '<div data-reveal><p class="kicker">Private yoga</p><h2 id="private-title">Your own class, wherever you breathe best</h2>',
        '<p class="lead">Private yoga in Fort Myers is built around one body: yours. We talk about how you move, where you hold tension and what you want to feel afterwards — then I plan the class for exactly that.</p>',
        '<ul class="places">{}</ul></div>'.format(places_html),
        '<div class="private__side" data-reveal><div class="frame">{}</div><ol class="steps">{}</ol><p class="btn-row">{}{}</p></div>'.format(
            photo('olivia-arms-raised-sunset', '(min-width: 960px) 34vw, 92vw'), steps_html,
            btn(PRIVATE(), 'Book a private session'), btn(page_url('private-yoga'), 'How it works', 'ghost')),
        '</div></section>',
        # Schedule
        '<section class="section section--tight" id="schedule" aria-labelledby="schedule-title"><div class="container">',
        '<header class="section__head section__head--row" data-reveal><div><p class="kicker">Timetable</p><h2 id="schedule-title">Weekly group classes</h2><p class="lead">Book in a minute. Beginners are welcome in every class.</p></div>{}</header>'.format(
            btn(page_url('schedule-pricing'), 'Schedule & pricing', 'ghost')),
        schedule(), '</div></section>',
        # Breath
        '<section class="section section--forest" aria-label="Breathing exercise"><div class="container">{}</div></section>'.format(breath()),
        # Pricing
        '<section class="section section--tight" id="pricing" aria-labelledby="pricing-title"><div class="container">',
        section_head('Simple pricing', 'Pay per class, save with a pass, or give someone a gift card.', idattr='pricing-title', kicker='Prices in USD'),
        pricing(), '</div></section>',
        # FAQ
        '<section class="section" id="faq" aria-labelledby="faq-title"><div class="container faq-grid">',
        '<header class="section__head" data-reveal><p class="kicker">FAQ</p><h2 id="faq-title">Am I flexible enough for yoga?</h2><p class="lead">The question everyone asks first — and a few others.</p><p><a class="text-link" href="{}">All questions</a></p></header>'.format(
            e(page_url('faq'), True)),
        faq(True), '</div></section>',
        # Events
        '<section class="section section--moss" aria-labelledby="events-title"><div class="container">',
        '<header class="section__head section__head--row" data-reveal><div><p class="kicker">Events</p><h2 id="events-title">Sound yoga, live music & beach flows</h2><p class="lead">Special classes where handpan, tongue drum or singing bowls lead the movement.</p></div>{}</header>'.format(
            btn(page_url('events'), 'All events', 'line-light')),
        events(), '</div></section>',
        # Gallery
        '<section class="section section--tight" aria-labelledby="gallery-title"><div class="container">',
        section_head('Moments on the mat', 'Studio flows, garden classes, festival pavilions and the sea. Every class looks a little different; the feeling afterwards is the same.',
                     idattr='gallery-title', kicker='@oliivia_yoga'),
        gallery(['dancer-pose-beach', 'hatha-flow-group-warrior', 'sound-yoga-gong-bowls', 'olivia-outdoor-class',
                 'festival-pavilion-class', 'garden-yoga-evening', 'dancer-pose-forest', 'final-relaxation']),
        '</div></section>',
        # Areas
        '<section class="section section--tight" aria-labelledby="areas-title"><div class="container areas-grid">',
        '<header class="section__head" data-reveal><p class="kicker">Southwest Florida</p><h2 id="areas-title">Where I teach around Fort Myers</h2><p class="lead">Group classes in Fort Myers; private sessions at homes, clubhouses, offices and beaches across Southwest Florida.</p></header>',
        areas(), '</div></section>',
        # Journal
        '<section class="section section--pale" aria-labelledby="journal-title"><div class="container">',
        '<header class="section__head section__head--row" data-reveal><div><p class="kicker">Journal</p><h2 id="journal-title">From the journal</h2></div>{}</header>'.format(
            btn(page_url('journal'), 'All articles', 'ghost')),
        post_cards(), '</div></section>',
        # CTA
        '<section class="section cta" aria-labelledby="cta-title"><div class="container"><div class="cta__inner" data-reveal>',
        sticker('Beginners very welcome ✺ ', 'sticker--cta'),
        '<h2 id="cta-title">Your mat is waiting</h2><p class="lead">Try a group class, book a private session, or just send a question. Beginners very welcome.</p>',
        '<p class="btn-row">{}{}</p></div></div></section>'.format(btn(BOOK(), 'Book a class'), btn(contact_url(), 'Send a message', 'ghost')),
    ])


def aside_book(title, text, href, label, seed, dark=True, ph=None):
    pic = '<div class="frame">{}</div>'.format(photo(ph, '340px')) if ph else ''
    return ('<div class="aside-card{}">{}<h2>{}</h2><p>{}</p>{}</div>'.format(
        ' aside-card--forest' if dark else '', pic, e(title), e(text), btn(href, label, 'orchid' if dark else 'primary')))


def inner(key, trail, aside='', meta='', h1=None, lead=None, extra_after='', ph=None):
    p = DATA['pages'][key]
    if callable(extra_after):
        extra_after = extra_after()
    body = render_content(p['content'])
    layout = 'layout layout--aside' if aside else 'layout'
    main = '<div class="main-col">{}</div>'.format(body)
    if aside:
        main += '<aside class="aside-sticky">{}</aside>'.format(aside)
    return (page_head(h1 or p['title'], p['excerpt'] if lead is None else lead, trail, key, meta, ph=ph) +
            '<div class="container"><div class="{}">{}</div></div>'.format(layout, main) + extra_after)


def build_pages():
    PAGES.clear()
    home = DATA['pages']
    add('', 'home', 'Yoga in Fort Myers, FL — Hatha Flow & Private Yoga | Olivia Kovács',
        'Hatha flow and slow flow yoga classes in Fort Myers, FL, plus private one-on-one yoga at your home, lanai or on the beach. Beginner-friendly. Book with Olivia Kovács.',
        home_body, h1='Flow yoga in Fort Myers')

    HEADS = {'about': 'olivia-studio-mat', 'private-yoga': 'dancer-pose-forest', 'schedule-pricing': 'group-class-chair-pose', 'corporate-yoga': 'team-yoga-outdoors', 'events': 'festival-pavilion-class', 'faq': 'small-group-class', 'contact': 'olivia-outdoor-class'}

    def P(key, trail_name, aside_fn=None, **kw):
        kw.setdefault('ph', HEADS.get(key))
        p = home[key]
        add(PAGE_PATHS[key], key, p.get('seo_title') or p['title'], p.get('seo_desc') or p['excerpt'],
            lambda: inner(key, [(trail_name, page_url(key))], aside=aside_fn() if aside_fn else '', **kw),
            trail=[(trail_name, PAGE_PATHS[key])], h1=p['title'])

    P('about', 'About', lambda: (
        '<div class="aside-card"><div class="frame">{}</div><h2>Training</h2><ul class="aside-list">'
        '<li>{c}Hatha yoga teacher certification, 2023</li><li>{c}Recognized by Yoga Alliance International</li>'
        '<li>{c}Samadhi Yoga Studio, Budapest</li><li>{c}Teacher training with Ádám Diószegi</li></ul>{b}</div>').format(
        photo('olivia-riverside-profile', '340px'), c=icon('check'), b=btn(BOOK(), 'Practice with me')))
    add(PAGE_PATHS['classes'], 'classes', home['classes']['seo_title'], home['classes']['seo_desc'],
        lambda: inner('classes', [('Classes', page_url('classes'))], ph='hatha-flow-group-warrior'), trail=[('Classes', PAGE_PATHS['classes'])], h1=home['classes']['title'])
    P('private-yoga', 'Private yoga', lambda: aside_book('From $95 per session', '60, 75 or 90 minutes at your home, lanai, clubhouse, on the beach or online.', PRIVATE(), 'Book a private session', 'priv-aside', ph='tree-pose-meadow')
      + '<div class="aside-card"><h2>Areas I travel to</h2>' + areas() + '</div>')
    P('schedule-pricing', 'Schedule & pricing')
    P('corporate-yoga', 'Corporate & athletes', lambda: aside_book('Plan a session for your team', 'Tell me your team size, location and goals. I\'ll send a proposal with pricing.', contact_url('corporate'), 'Request a proposal', 'corp-aside', ph='outdoor-class-garden'))
    P('events', 'Events', extra_after=lambda: '<section class="section section--pale"><div class="container">' + section_head('From past events') + gallery(['festival-pavilion-arms', 'festival-dance', 'teaching-in-pavilion', 'garden-yoga-evening', 'festival-seated', 'sound-yoga-gong-bowls']) + '</div></section>', aside_fn=lambda: aside_book('Hear about the next one', 'Sound yoga, live-music flows and beach sessions are announced here and on Instagram first.', contact_url('event'), 'Get notified', 'ev-aside', ph='festival-dance'))
    P('faq', 'FAQ', lambda: aside_book('Still wondering?', 'Send your question — I\'m happy to help you choose the right class.', contact_url(), 'Ask a question', 'faq-aside', ph='olivia-riverside-profile'))
    P('contact', 'Contact', lambda: (
        '<div class="aside-card aside-card--forest"><div class="frame">{}</div><h2>Say hello</h2><ul class="aside-list">'
        '<li>{m}<a href="mailto:{mail}">{mail}</a></li><li>{ig}<a href="{igu}" target="_blank" rel="noopener">@oliivia_yoga</a></li>'
        '<li>{fb}<a href="{fbu}" target="_blank" rel="noopener">Facebook</a></li><li>{pin}<span>Fort Myers, FL</span></li></ul>'
        '<p>Private sessions: tell me your area and preferred days, and I\'ll suggest times.</p></div>').format(
        photo('olivia-leading-garden-class', '340px'), m=icon('mail'), mail=EMAIL, ig=icon('instagram'),
        igu=INSTAGRAM, fb=icon('facebook'), fbu=FACEBOOK, pin=icon('pin')))

    # Journal index
    add(PAGE_PATHS['journal'], 'journal', home['journal']['seo_title'], home['journal']['seo_desc'],
        lambda: page_head('Journal', home['journal']['excerpt'], [('Journal', page_url('journal'))], 'journal', ph='olivia-riverside-profile') +
        '<div class="container"><div class="layout">{}</div></div>'.format(post_cards()),
        trail=[('Journal', PAGE_PATHS['journal'])], h1='Journal')

    # Class detail pages
    for slug in CLASS_PAGES:
        c = DATA['classes'][slug]

        def body(slug=slug, c=c):
            meta = '<div class="chips" style="margin-top:1.4rem"><span class="chip">{}{}</span><span class="chip">{}{}</span><span class="chip">{}</span></div>'.format(
                icon('clock'), e(c['duration']), icon('users'), e(c['level']), intensity(c['intensity']))
            aside = ('<div class="aside-card"><div class="frame">{}</div><h2>{}</h2><ul class="aside-list"><li>{}{}</li><li>{}{}</li></ul>{}</div>').format(
                photo(CLASS_ASIDE[slug], '340px'), e(c['title']), icon('clock'), e(c['duration']), icon('users'), e(c['level']),
                btn(BOOK(), 'Book this class'))
            more = ('<section class="section section--pale"><div class="container">{}{}</div></section>').format(
                section_head('Other classes'), classes_list(exclude=(slug,)))
            return (page_head(c['title'], c['excerpt'], [('Classes', page_url('classes')), (c['title'], class_url(slug))], 'c' + slug, meta, ph=CLASS_PHOTO[slug]) +
                    '<div class="container"><div class="layout layout--aside"><div class="main-col">{}<p class="btn-row">{}{}</p></div><aside class="aside-sticky">{}</aside></div></div>'.format(
                        render_content(c['content']), btn(BOOK(), 'Book this class'), btn(page_url('schedule-pricing'), 'See the schedule', 'ghost'), aside) +
                    wave('var(--paper)', 'var(--pale)', seed=30) + more + wave('var(--pale)', 'var(--paper)', flip=True, seed=31))
        path = 'yoga-classes/' + slug + '/'
        add(path, 'classes', c.get('seo_title') or (c['title'] + ' in Fort Myers | ' + BRAND), c['excerpt'], body,
            trail=[('Classes', PAGE_PATHS['classes']), (c['title'], path)],
            schema=[{'@type': 'Service', 'name': c['title'] + ' in Fort Myers', 'serviceType': 'Yoga class',
                     'description': c['excerpt'], 'provider': {'@id': SITE_URL + '/#business'},
                     'areaServed': {'@type': 'City', 'name': 'Fort Myers, FL'}}], h1=c['title'])

    # Blog posts
    for slug, p in DATA['posts'].items():
        def body(slug=slug, p=p):
            words = len(re.sub('<[^>]+>', ' ', p['content']).split())
            d = date.today() - timedelta(days=int(p['days_ago']))
            meta = '<div class="page-head__meta"><span>{}{}</span><span>{}{} min read</span><span>{}{}</span></div>'.format(
                icon('calendar'), d.strftime('%B %-d, %Y'), icon('clock'), max(1, round(words / 220)), icon('leaf'), e(p['category']))
            author = ('<aside class="author"><div class="frame">{}</div><div><b>Olivia Kovács</b><p>Hatha flow and slow flow teacher offering group classes and private yoga in Fort Myers, FL.</p></div></aside>').format(
                photo('olivia-studio-mat', '84px', alt='Olivia Kovács'))
            more = '<section class="section section--pale"><div class="container">{}{}</div></section>'.format(
                section_head('Keep reading'), post_cards(exclude=(slug,)))
            return (page_head(p['title'], p['excerpt'], [('Journal', page_url('journal')), (p['title'], post_url(slug))], 'p' + slug, meta, ph=POST_PHOTO[slug]) +
                    '<div class="container"><article class="layout">{}{}<p class="btn-row">{}</p></article></div>'.format(
                        render_content(p['content']), author, btn(BOOK(), 'Book a class')) +
                    wave('var(--paper)', 'var(--pale)', seed=40) + more + wave('var(--pale)', 'var(--paper)', flip=True, seed=41))
        path = 'journal/' + slug + '/'
        d = date.today() - timedelta(days=int(p['days_ago']))
        add(path, 'journal', p['title'] + ' | ' + BRAND, p['excerpt'], body, ogtype='article',
            trail=[('Journal', PAGE_PATHS['journal']), (p['title'], path)],
            schema=[{'@type': 'BlogPosting', 'headline': p['title'], 'description': p['excerpt'], 'datePublished': d.isoformat(),
                     'author': {'@id': SITE_URL + '/#olivia'}, 'publisher': {'@id': SITE_URL + '/#business'},
                     'mainEntityOfPage': SITE_URL + '/' + path, 'inLanguage': 'en-US'}], h1=p['title'])

    # 404
    add('404.html', '404', 'Page not found | ' + BRAND, 'This page could not be found.',
        lambda: page_head('This page has drifted downstream', 'The link may be old or mistyped. Take a breath and choose where to go next.', [('Not found', '#')], '404') +
        '<div class="container"><div class="layout"><p class="btn-row">{}{}</p></div></div>'.format(btn(url(''), 'Go to the home page'), btn(page_url('classes'), 'See the classes', 'ghost')),
        trail=[], h1='This page has drifted downstream')


# --------------------------------------------------------------------------
# SEO: head + JSON-LD
# --------------------------------------------------------------------------

def base_graph():
    return [
        {'@type': 'WebSite', '@id': SITE_URL + '/#website', 'url': SITE_URL + '/', 'name': BRAND, 'inLanguage': 'en-US',
         'publisher': {'@id': SITE_URL + '/#business'}},
        {'@type': ['LocalBusiness', 'HealthAndBeautyBusiness'], '@id': SITE_URL + '/#business', 'name': BRAND, 'url': SITE_URL + '/',
         'email': EMAIL, 'priceRange': '$$', 'sameAs': [INSTAGRAM, FACEBOOK],
         'description': 'Hatha flow, slow flow and private yoga in Fort Myers, FL.',
         'address': {'@type': 'PostalAddress', 'addressLocality': CITY, 'addressRegion': REGION, 'addressCountry': 'US'},
         'areaServed': [{'@type': 'City', 'name': 'Fort Myers, FL'}] + [{'@type': 'Place', 'name': a} for a in AREAS],
         'founder': {'@id': SITE_URL + '/#olivia'},
         'knowsAbout': ['Hatha yoga', 'Hatha flow yoga', 'Slow flow yoga', 'Private yoga', 'Corporate yoga', 'Yoga for athletes', 'Breathwork', 'Meditation']},
        {'@type': 'Person', '@id': SITE_URL + '/#olivia', 'name': TEACHER, 'jobTitle': 'Hatha yoga teacher',
         'worksFor': {'@id': SITE_URL + '/#business'}, 'url': SITE_URL + '/about/', 'sameAs': [INSTAGRAM, FACEBOOK],
         'hasCredential': {'@type': 'EducationalOccupationalCredential', 'name': 'Hatha yoga teacher certification (2023)',
                           'credentialCategory': 'certificate', 'recognizedBy': {'@type': 'Organization', 'name': 'Yoga Alliance International'}}},
    ]


def page_graph(p):
    full = SITE_URL + '/' + p['path'] if p['path'] != '404.html' else SITE_URL + '/404.html'
    g = base_graph()
    wp = {'@type': 'WebPage', '@id': full + '#webpage', 'url': full, 'name': p['title'], 'description': p['desc'],
          'isPartOf': {'@id': SITE_URL + '/#website'}, 'inLanguage': 'en-US'}
    if p['path'] == 'about/':
        wp['@type'] = 'AboutPage'
        wp['mainEntity'] = {'@id': SITE_URL + '/#olivia'}
    if p['path'] == 'contact/':
        wp['@type'] = 'ContactPage'
    if p['trail']:
        items = [{'@type': 'ListItem', 'position': 1, 'name': 'Home', 'item': SITE_URL + '/'}]
        for i, (name, path) in enumerate(p['trail']):
            items.append({'@type': 'ListItem', 'position': i + 2, 'name': name, 'item': SITE_URL + '/' + path})
        g.append({'@type': 'BreadcrumbList', '@id': full + '#breadcrumb', 'itemListElement': items})
        wp['breadcrumb'] = {'@id': full + '#breadcrumb'}
    g.append(wp)
    if p['path'] == 'faq/':
        g.append({'@type': 'FAQPage', 'mainEntity': [{'@type': 'Question', 'name': f['q'],
                  'acceptedAnswer': {'@type': 'Answer', 'text': f['a']}} for f in DATA['faqs']]})
    if p['path'] == 'private-yoga/':
        g.append({'@type': 'Service', 'name': 'Private yoga in Fort Myers', 'serviceType': 'Private yoga session',
                  'provider': {'@id': SITE_URL + '/#business'}, 'areaServed': [{'@type': 'Place', 'name': a} for a in AREAS],
                  'offers': {'@type': 'Offer', 'price': '95', 'priceCurrency': 'USD', 'description': '60-minute private session'}})
    g.extend(p['schema'])
    return {'@context': 'https://schema.org', '@graph': g}


FONTS = ('<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Archivo:wdth,wght@62..125,400..800&display=swap">')


def head(p, css_tag, og=None):
    full = SITE_URL + '/' + p['path']
    return ('<!doctype html><html lang="en-US"><head><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            '<title>{t}</title><meta name="description" content="{d}">'
            '{robots}<link rel="canonical" href="{u}">'
            '<meta property="og:locale" content="en_US"><meta property="og:type" content="{ot}"><meta property="og:site_name" content="{b}">'
            '<meta property="og:title" content="{t}"><meta property="og:description" content="{d}"><meta property="og:url" content="{u}">'
            '{ogimg}<meta name="twitter:card" content="summary_large_image"><meta name="geo.region" content="US-FL"><meta name="geo.placename" content="Fort Myers">'
            '<meta name="theme-color" content="#2B5036"><link rel="icon" href="data:image/svg+xml,{fav}">'
            '{fonts}{css}<script type="application/ld+json">{ld}</script></head>').format(
        ogimg=('<meta property="og:image" content="{0}/assets/img/{1}-1200.jpg"><meta property="og:image:width" content="{2}"><meta property="og:image:height" content="{3}"><meta property="og:image:alt" content="{4}">'.format(
            SITE_URL, og, IMGS[og]['w'], IMGS[og]['h'], e(IMGS[og]['alt'], True)) if og else ''),
        t=e(p['title']), d=e(p['desc']), u=e(full), ot=p['ogtype'], b=e(BRAND), fonts=FONTS, css=css_tag,
        robots='<meta name="robots" content="noindex">' if p['path'] == '404.html' else '',
        fav=e("%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='20' fill='%232B5036'/%3E%3Ccircle cx='31' cy='16' r='5' fill='%23EE3F9A'/%3E%3C/svg%3E"),
        ld=json.dumps(page_graph(p), ensure_ascii=False).replace('</', '<\\/'))


# --------------------------------------------------------------------------
# Builds
# --------------------------------------------------------------------------

def build_multi():
    Ctx.mode = 'multi'
    root = os.path.join(OUT, 'site')
    if os.path.exists(root):
        shutil.rmtree(root)
    os.makedirs(os.path.join(root, 'assets'))
    open(os.path.join(root, 'assets', 'site.css'), 'w').write(CSS)
    open(os.path.join(root, 'assets', 'site.js'), 'w').write(JS)
    os.makedirs(os.path.join(root, 'assets', 'img'))
    for f in os.listdir(os.path.join(SRC, 'img')):
        if f.endswith('.jpg'):
            shutil.copy(os.path.join(SRC, 'img', f), os.path.join(root, 'assets', 'img', f))
    build_pages()
    for p in list(PAGES):
        Ctx.depth = 0 if p['path'] in ('', '404.html') else p['path'].strip('/').count('/') + 1
        prefix = '../' * Ctx.depth
        build_pages_for_depth = PAGES  # links depend on depth; re-render body now
        body = p['body']()
        mo = re.search(r'assets/img/([a-z0-9\-]+)-1200\.jpg', body)
        doc = (head(p, '<link rel="stylesheet" href="{}assets/site.css">'.format(prefix), og=mo.group(1) if mo else None) +
               '<body>' + SVG_DEFS + header(p['key']) + '<main id="main">' + body + '</main>' + footer() +
               '<script src="{}assets/site.js" defer></script></body></html>'.format(prefix))
        target = os.path.join(root, p['path'] if p['path'].endswith('.html') else os.path.join(p['path'], 'index.html'))
        os.makedirs(os.path.dirname(target), exist_ok=True)
        open(target, 'w').write(doc)
    urls = [p['path'] for p in PAGES if p['path'] != '404.html']
    open(os.path.join(root, 'sitemap.xml'), 'w').write(
        '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
        ''.join('  <url><loc>{}/{}</loc><lastmod>{}</lastmod></url>\n'.format(SITE_URL, u, date.today().isoformat()) for u in urls) + '</urlset>\n')
    open(os.path.join(root, 'robots.txt'), 'w').write('User-agent: *\nAllow: /\n\nSitemap: {}/sitemap.xml\n'.format(SITE_URL))
    return len(PAGES)


ROUTER = r"""
(function(){
  var pages = document.querySelectorAll('[data-page]');
  var meta = JSON.parse(document.getElementById('oy-meta').textContent);
  var IMG = JSON.parse(document.getElementById('oy-img').textContent);
  var current = null;
  function route(){
    var h = location.hash || '#/';
    if (h.charAt(1) !== '/') {           // in-page anchor like #book
      var el = current && current.querySelector(h);
      if (el) el.scrollIntoView();
      return;
    }
    var raw = h.slice(2), q = '';
    var qi = raw.indexOf('?'); if (qi > -1) { q = raw.slice(qi + 1); raw = raw.slice(0, qi); }
    var target = null;
    for (var i = 0; i < pages.length; i++) if (pages[i].getAttribute('data-page') === raw) target = pages[i];
    if (!target) { target = document.querySelector('[data-page="404.html"]'); raw = '404.html'; }
    for (var j = 0; j < pages.length; j++) pages[j].hidden = pages[j] !== target;
    current = target;
    var m = meta[raw] || {};
    document.title = m.title || document.title;
    var d = document.querySelector('meta[name="description"]'); if (d && m.desc) d.setAttribute('content', m.desc);
    var section = raw.split('/')[0] + (raw ? '/' : '');
    document.querySelectorAll('.nav a[data-path]').forEach(function(a){
      var p = a.getAttribute('data-path');
      if (p && (raw === p || (p !== '' && raw.indexOf(p) === 0) || (p === 'yoga-classes/' && raw.indexOf('yoga-classes/') === 0))) a.setAttribute('aria-current','page'); else a.removeAttribute('aria-current');
    });
    var to = (q.match(/to=([a-z0-9\-]+)/) || [])[1];
    var anchor = to && target.querySelector('#' + to);
    if (anchor) { setTimeout(function(){ anchor.scrollIntoView(); }, 30); } else { window.scrollTo(0, 0); }
    target.querySelectorAll('img[data-img]').forEach(function(im){ var k = im.getAttribute('data-img'); if (IMG[k] && im.getAttribute('src') !== IMG[k]) im.src = IMG[k]; });
    if (window.OY) window.OY.refresh(target);
    var h1 = target.querySelector('h1'); if (h1 && document.activeElement && document.activeElement.tagName === 'A') { h1.setAttribute('tabindex','-1'); h1.focus({preventScroll:true}); }
  }
  window.addEventListener('hashchange', route);
  route();
})();
"""


def build_preview():
    Ctx.mode = 'preview'
    Ctx.depth = 0
    build_pages()
    home = PAGES[0]
    chunks, meta = [], {}
    USED_IMGS.clear()
    for p in PAGES:
        meta[p['path']] = {'title': p['title'], 'desc': p['desc']}
        chunks.append('<div data-page="{}"{}>{}</div>'.format(p['path'], '' if p['path'] == '' else ' hidden', p['body']()))
    import base64, io
    from PIL import Image
    imgmap = {}
    for k in sorted(USED_IMGS):
        im = Image.open(os.path.join(SRC, 'img', k + '-1200.jpg'))
        if im.width > 900:
            im = im.resize((900, round(im.height * 900 / im.width)), Image.LANCZOS)
        buf = io.BytesIO()
        im.save(buf, 'JPEG', quality=70, optimize=True, progressive=True)
        imgmap[k] = 'data:image/jpeg;base64,' + base64.b64encode(buf.getvalue()).decode()
    header_html = header('')
    footer_html = footer()
    full_head = head(home, '<style>' + CSS + '</style>')
    body = (SVG_DEFS + header_html +
            '<main id="main">' + ''.join(chunks) + '</main>' + footer_html +
            '<script type="application/json" id="oy-img">' + json.dumps(imgmap) + '</script>' +
            '<script type="application/json" id="oy-meta">' + json.dumps(meta, ensure_ascii=False).replace('</', '<\\/') + '</script>' +
            '<script>' + JS + '</script><script>' + ROUTER + '</script>')
    # Standalone, downloadable file: a complete document that opens straight from disk.
    open(os.path.join(OUT, 'olivia-kovacs-yoga.html'), 'w').write(full_head + '<body>' + body + '</body></html>')
    # The artifact host supplies <!doctype>, <html>, <head> and <body>; the preview carries only its own tags.
    top = full_head.replace('<!doctype html><html lang="en-US"><head><meta charset="utf-8">', '').replace('</head>', '')
    top = re.sub(r'<meta name="viewport"[^>]*>', '', top)
    doc = top + body
    open(os.path.join(OUT, 'preview.html'), 'w').write(doc)
    return len(PAGES), len(doc)


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    print('multi pages:', build_multi())
    print('preview:', build_preview())
