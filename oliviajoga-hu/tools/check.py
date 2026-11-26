#!/usr/bin/env python3
"""Checks a build against the old site (content/pages.json): every old address has a page, every <title> is the
same, every heading, paragraph, list item and button of the old page is on the new one word for word, every old
photo is there with its alt text, and every internal link leads to a page of the build.

    python3 tools/check.py [dist folder] [--base /demo/<slug>/] [--after]

--after: the site from 26 November (build.py --after, Olivia 11.12): the timetables, maps and Motibro are gone on
purpose, so it checks the titles, the old headings, the place words, that nothing links to Motibro or shows a map,
that no page says „tartom” any more, and the internal links.
"""
import html
import json
import os
import re
import sys
import unicodedata
import urllib.parse

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HERE)
import build  # noqa: E402  (the same rules for decorative images and the picture-only price cards)

NEW_PHOTOS = json.load(open(os.path.join(HERE, 'content', 'new-photos.json'), encoding='utf-8'))


def norm(s):
    s = html.unescape(re.sub(r'<[^>]+>', ' ', s)).replace('\xa0', ' ')
    s = s.replace('„', '"').replace('”', '"').replace('“', '"')
    return re.sub(r'\s+', ' ', s).strip()


def visible(doc):
    body = doc[doc.index('<body'):]
    body = re.sub(r'(?s)<(script|style)\b.*?</\1>', ' ', body)
    return norm(body)


def main():
    args = sys.argv[1:]
    base = args[args.index('--base') + 1] if '--base' in args else '/'
    out = next((a for a in args if not a.startswith('--') and a != base), os.path.join(HERE, 'dist'))
    data = json.load(open(os.path.join(HERE, 'content', 'pages.json'), encoding='utf-8'))
    fails, checks = [], 0

    def ok(cond, msg):
        nonlocal checks
        checks += 1
        if not cond:
            fails.append(msg)

    if '--after' in args:
        return after_checks(data, out, base)
    files = {}
    data['pages'] = [p for p in data['pages'] if p['path'] not in build.DELETED_PAGES]  # deleted on purpose (Olivia)
    for p in data['pages']:
        for s in p['sections']:  # what Olivia asked to take out (build.dropped_section, REMOVED_*)
            if build.dropped_section(p['path'], s):
                s['columns'] = []
        # the addresses without accents (Áron, 25 November): /rólam → /rolam
        f = os.path.join(out, build.ascii_slug(p['path']), 'index.html') if p['path'] else os.path.join(out, 'index.html')
        ok(os.path.isfile(f), f'missing page: /{build.ascii_slug(p["path"])} (/{p["path"]})')
        if not os.path.isfile(f):
            continue
        doc = open(f, encoding='utf-8').read()
        files[p['path']] = doc
        title = re.search(r'<title>(.*?)</title>', doc, re.S)
        ok(title and html.unescape(title.group(1)) == p['title'], f'/{p["path"]}: title differs')
        text = visible(doc)
        for s in p['sections']:
            for c in s['columns']:
                for t in c:
                    if t['type'] == 'text':
                        for b in t['blocks']:
                            parts = b.get('items') or [b.get('html', '')]
                            for part in parts:
                                n = norm(part)
                                if n in build.REPLACED_TEXT or n.startswith(build.REPLACED_TEXT_START):
                                    continue  # replaced on purpose (Olivia 11.12: the online gift card)
                                if n:
                                    ok(n in text, f'/{p["path"]}: text missing: {n[:80]}')
                    elif t['type'] == 'button':
                        ok(norm(t['label']) in text, f'/{p["path"]}: button missing: {t["label"]}')
                    elif t['type'] == 'image' and not build.is_divider(t) and t['file'] not in build.PRICES and t['file'] not in build.DROPPED and t['file'] not in build.REMOVED_FILES:
                        if t['w'] == 1000 and t['h'] == 400 or t['file'] == 'old/49fd21489bf3':
                            continue  # the reviews and "Ami nem szükséges": rebuilt as text
                        old_alt = t.get('alt', '')
                        src = t['file']  # a journal card's new photo (tools/images.py cards) keeps the old picture's alt text
                        if t.get('href', '').startswith('page:') and build.card_key(t['href'][5:]) in NEW_PHOTOS:
                            src = build.card_key(t['href'][5:])
                        # the alt: the picture described, then every keyword of the old alt (build.better_alt, 2026-11-12)
                        alt = html.escape(build.better_alt(src, old_alt), quote=True)
                        # the file under its keyword name (Áron, 26 November: build.IMG_NAMES)
                        named = lambda key: build.img_rel('img/' + key + '.jpg')[4:-4]  # noqa: E731 (old/<hash> → old/<keyword name>)
                        fname = named(src if '/' in src else 'new/' + src)
                        found = re.search(r'<img[^>]*' + re.escape(fname) + r'-[^>]*alt="' + re.escape(alt) + '"', doc)
                        if not found and build.card_key(p['path']) in NEW_PHOTOS:  # a post's head: her raw photo (11.12)
                            ck = build.card_key(p['path'])
                            found = re.search(r'<img[^>]*' + re.escape(named('new/' + ck)) + r'-[^>]*alt="' + re.escape(html.escape(build.better_alt(ck, old_alt), quote=True)) + '"', doc)
                        bare = lambda x: unicodedata.normalize('NFKD', x.lower()).encode('ascii', 'ignore').decode()  # noqa: E731 (kovacs = Kovács)
                        # every old keyword stays – except the 11th district's (Áron, 25 November: build.drop_xi)
                        words = [w for w in re.findall(r'\w+', bare(build.drop_xi(old_alt))) if len(w) > 2]
                        ok(all(w in bare(build.better_alt(src, old_alt)) for w in words), f'/{p["path"]}: an old alt keyword lost: {old_alt[:60]}')
                        ok(not any(re.search(w, build.better_alt(src, old_alt), re.I) for w in build.XI_WORDS),
                           f'/{p["path"]}: an 11th-district word in the alt: {build.better_alt(src, old_alt)[:80]}')
                        ok(found, f'/{p["path"]}: photo missing or alt changed: {t["file"]} "{t.get("alt", "")[:40]}"')
                    elif t['type'] == 'embed' and not any(r in t['src'] for r in build.REMOVED_EMBEDS):
                        ok(html.escape(t['src'], quote=True) in doc, f'/{p["path"]}: embed missing: {t["src"][:60]}')
        ok('href="page:' not in doc, f'/{p["path"]}: unresolved old link')
    # Áron, 26 November: „kép fájlnevek tartalmazzák a szükséges kulcsszavakat ékezet nélkül, kötőjellel, az alt textek pedig
    # szintén tartalmazzanak kulcsszavakat ékezetesen, spaceszel, a képet is leírva”
    for path, doc in files.items():
        for src, alt in re.findall(r'<img[^>]*\bsrc="[^"]*/assets/img/(?:old|new|theme)/([^"/]+)"[^>]*\balt="([^"]*)"', doc):
            name = re.sub(r'(-(?:600|800|1200|1600))?\.\w+$', '', src)
            ok(re.fullmatch(r'[a-z0-9]+(-[a-z0-9]+){3,}', name) is not None and not re.fullmatch(r'[0-9a-f]{12}', name),
               f'/{path}: a picture without a keyword file name: {src}')
            a = html.unescape(alt)
            ok(len(a.split()) >= 5 and re.search(r'jóga|Kovács Olívia|kismama|hangfürdő|relaxáció|Fitmummy|Motibro', a, re.I) is not None,
               f'/{path}: an alt without a description or keyword: {a[:70]}')
    # Áron, 25–26 November: no 11th-district words in any picture's alt; the home page invites to the online group class
    for path, doc in files.items():
        for alt in re.findall(r'<img[^>]*\balt="([^"]*)"', doc):
            ok(not any(re.search(w, html.unescape(alt), re.I) for w in build.XI_WORDS), f'/{path}: an 11th-district word in an alt: {alt[:80]}')
    home = files.get('', '')
    ok('11. kerületi' not in home and re.search(r'szeretettel az <a href="[^"]*/online-orak/hatha-flow-joga-online/"[^>]*>online, csoportos jóga órákon!</a>', home),
       '/: „várlak szeretettel az online, csoportos jóga órákon” → the online group class page (Áron, 26 Nov)')
    # internal links
    for path, doc in files.items():
        for href in re.findall(r'href="([^"#?]+)', doc):
            h = html.unescape(href)
            if not h.startswith(base) or h.startswith(base + 'assets/'):
                continue
            ok(re.fullmatch(r'[a-z0-9/-]*', h[len(base):]) is not None, f'/{path}: an address with accents or capitals: {h}')
            rel = urllib.parse.unquote(h[len(base):]).strip('/')
            target = os.path.join(out, rel, 'index.html') if rel else os.path.join(out, 'index.html')
            ok(os.path.isfile(target), f'/{path}: broken link {h}')
    # structured data for search engines and AI assistants (Áron, 11.18: „az AI-ok ajánlják Oliviát”)
    crumbs = {}
    for path, doc in files.items():
        nodes = []
        for b in re.findall(r'<script type="application/ld\+json"[^>]*>(.*?)</script>', doc, re.S):
            try:
                nodes += json.loads(b).get('@graph', [])
            except ValueError:
                ok(False, f'/{path}: JSON-LD does not parse')
        types = {n.get('@type') if isinstance(n.get('@type'), str) else 'x' for n in nodes}
        ok({'WebSite', 'Organization', 'Person'} <= types, f'/{path}: JSON-LD without WebSite / Organization / Person')
        person = next((n for n in nodes if n.get('@type') == 'Person'), {})
        ok(len(person.get('hasCredential', [])) >= 3 and person.get('jobTitle') and person.get('url') == 'https://oliviajoga.online/rolam/',
           f'/{path}: Person without her credentials or the online site (her main address since 25 November)')
        biz = next((n for n in nodes if n.get('@type') == 'Organization'), {})
        ok(biz.get('url') == 'https://oliviajoga.online/', f'/{path}: the business is not linked to the online site (Áron, 25 Nov)')
        if path not in ('', 'főoldal', '404'):  # /főoldal is the old address of the front page
            bc = next((n for n in nodes if n.get('@type') == 'BreadcrumbList'), None)
            ok(bc is not None, f'/{path}: no BreadcrumbList')
            if bc:
                crumbs[path] = bc['itemListElement'][-1]['name']
    # where to practise with her now: the articles and Rólam link to the online site's class pages (Áron, 11.19)
    for path, doc in files.items():
        if path == 'rólam' or 'class="aside-sticky oj-more"' in doc:  # Rólam and every article
            ok('class="oj-online-note"' in doc and doc.count('href="https://oliviajoga.online/online-orak/') >= 3,
               f'/{path}: no „Gyakorolj velem online” note with links to the online classes')
    ok(len(set(crumbs.values())) == len(crumbs) and not any('kerület' in v for v in crumbs.values() if v not in ('Kapcsolat',)),
       'breadcrumb names: each page its own (not the shared „Jóga 18. és 21. kerület” title start)')
    nl_checks(files, ok)
    for f in fails[:40]:
        print('FAIL', f)
    print(f'{checks - len(fails)} checks passed, {len(fails)} failed')
    sys.exit(1 if fails else 0)


def nl_checks(files, ok):
    """The newsletter signup (Áron, 26 November): the events page, the home page's events and every footer, posting to the
    online site (which sends the letters), consent required, a link to the online site's privacy notice."""
    want = {'': ('hirlevel', 'hu-fooldal'), 'események': ('hirlevel-esemenyek', 'hu-esemenyek')}
    for path, doc in files.items():
        forms = re.findall(r'<form class="oj-nl[^"]*" id="([a-z-]+)" method="post" action="([^"]+)" data-oj-nl>(.*?)</form>', doc, re.S)
        ids = [f[0] for f in forms]
        ok('hirlevel-lablec' in ids, f'/{path}: no newsletter signup in the footer')
        if path in want:
            ok(want[path][0] in ids and f'name="source" value="{want[path][1]}"' in doc, f'/{path}: newsletter signup missing')
        for fid, action, inner in forms:
            ok(action == build.ONLINE + '/wp-admin/admin-post.php', f'/{path} #{fid}: the signup does not post to the online site')
            ok('name="action" value="oys_nl_subscribe"' in inner and 'name="consent" value="1" required' in inner and 'name="email" required' in inner
               and 'name="website" tabindex="-1"' in inner and build.ONLINE + '/adatvedelem/' in inner, f'/{path} #{fid}: the signup form is incomplete')
        ok(len(ids) == len(set(ids)), f'/{path}: two forms with the same id')
    priv = files.get(build.PRIVACY_PATH, '')
    if priv:
        ok('<strong>Hírlevél:</strong>' in priv and 'oliviajoga.online oldalra kerül' in priv, f'/{build.PRIVACY_PATH}: the newsletter is missing from the privacy notice')


def after_checks(data, out, base):
    fails, checks = [], 0

    def ok(cond, msg):
        nonlocal checks
        checks += 1
        if not cond:
            fails.append(msg)
    files = {}
    for p in data['pages']:
        if p['path'] in build.DELETED_PAGES:
            continue
        # the addresses without accents (Áron, 25 November): /rólam → /rolam
        f = os.path.join(out, build.ascii_slug(p['path']), 'index.html') if p['path'] else os.path.join(out, 'index.html')
        ok(os.path.isfile(f), f'missing page: /{build.ascii_slug(p["path"])} (/{p["path"]})')
        if not os.path.isfile(f):
            continue
        doc = files[p['path']] = open(f, encoding='utf-8').read()
        title = re.search(r'<title>(.*?)</title>', doc, re.S)
        ok(title and html.unescape(title.group(1)) == p['title'], f'/{p["path"]}: title differs')
        text = visible(doc)
        ok('motibro' not in doc.lower(), f'/{p["path"]}: links to Motibro')
        ok('google.com/maps' not in doc, f'/{p["path"]}: shows a map')
        ok('tartom' not in doc, f'/{p["path"]}: still says „tartom”')
        ok('Barion' not in doc, f'/{p["path"]}: Barion logos')
        if p['path'] in ('árak', 'bejelentkezés-jóga-bérlet'):
            continue
        for s in p['sections']:
            if build.dropped_section(p['path'], s):
                continue
            for c in s['columns']:
                for t in c:
                    for b in t.get('blocks', []) if t['type'] == 'text' else []:
                        if b['t'] in ('h1', 'h2', 'h3') and norm(b['html']) and not re.match(r'\d+ perc$', norm(b['html'])) \
                                and not norm(b['html']).startswith(build.REPLACED_TEXT_START):
                            ok(norm(b['html']) in text, f'/{p["path"]}: heading missing: {norm(b["html"])[:80]}')
    old_text = {p['path']: norm(json.dumps(p['sections'], ensure_ascii=False)) for p in data['pages']}
    for path in ('', 'kapcsolat'):
        text = visible(files.get(path, '<body>'))
        for w in ('18. kerület', '21. kerület', 'Csepel', 'Prána', 'Korall'):  # the place words stay where they were (Olivia)
            if w in old_text.get(path, ''):
                ok(w in text, f'/{path}: „{w}” missing')
        for w in ('szerda', '18:30', '20:00', 'Online kismamatorna'):
            ok(w in text, f'/{path}: „{w}” missing')
    ok('tartottam' in visible(files.get('', '<body>')), '/: „tartottam” missing')
    nl_checks(files, ok)
    for path, doc in files.items():
        for href in re.findall(r'href="([^"#?]+)', doc):
            h = html.unescape(href)
            if not h.startswith(base) or h.startswith(base + 'assets/'):
                continue
            rel = urllib.parse.unquote(h[len(base):]).strip('/')
            target = os.path.join(out, rel, 'index.html') if rel else os.path.join(out, 'index.html')
            ok(os.path.isfile(target), f'/{path}: broken link {h}')
    for f in fails[:40]:
        print('FAIL', f)
    print(f'{checks - len(fails)} checks passed, {len(fails)} failed (after 25 November)')
    sys.exit(1 if fails else 0)


if __name__ == '__main__':
    main()
