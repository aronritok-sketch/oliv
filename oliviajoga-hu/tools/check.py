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
        f = os.path.join(out, p['path'], 'index.html') if p['path'] else os.path.join(out, 'index.html')
        ok(os.path.isfile(f), f'missing page: /{p["path"]}')
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
                        alt = html.escape(t.get('alt', ''), quote=True)
                        src = t['file']  # a journal card's new photo (tools/images.py cards) keeps the old picture's alt text
                        if t.get('href', '').startswith('page:') and build.card_key(t['href'][5:]) in NEW_PHOTOS:
                            src = build.card_key(t['href'][5:])
                        found = re.search(r'<img[^>]*' + re.escape(src) + r'-[^>]*alt="' + re.escape(alt) + '"', doc)
                        if not found and build.card_key(p['path']) in NEW_PHOTOS:  # a post's head: her raw photo (11.12)
                            found = re.search(r'<img[^>]*' + re.escape(build.card_key(p['path'])) + r'-[^>]*alt="' + re.escape(alt) + '"', doc)
                        ok(found, f'/{p["path"]}: photo missing or alt changed: {t["file"]} "{t.get("alt", "")[:40]}"')
                    elif t['type'] == 'embed' and not any(r in t['src'] for r in build.REMOVED_EMBEDS):
                        ok(html.escape(t['src'], quote=True) in doc, f'/{p["path"]}: embed missing: {t["src"][:60]}')
        ok('href="page:' not in doc, f'/{p["path"]}: unresolved old link')
    # internal links
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
    print(f'{checks - len(fails)} checks passed, {len(fails)} failed')
    sys.exit(1 if fails else 0)


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
        f = os.path.join(out, p['path'], 'index.html') if p['path'] else os.path.join(out, 'index.html')
        ok(os.path.isfile(f), f'missing page: /{p["path"]}')
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
                        if b['t'] in ('h1', 'h2', 'h3') and norm(b['html']) and not re.match(r'\d+ perc$', norm(b['html'])):
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
