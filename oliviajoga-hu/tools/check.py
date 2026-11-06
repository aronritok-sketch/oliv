#!/usr/bin/env python3
"""Checks a build against the old site (content/pages.json): every old address has a page, every <title> is the
same, every heading, paragraph, list item and button of the old page is on the new one word for word, every old
photo is there with its alt text, and every internal link leads to a page of the build.

    python3 tools/check.py [dist folder] [--base /demo/<slug>/]
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
                                if n:
                                    ok(n in text, f'/{p["path"]}: text missing: {n[:80]}')
                    elif t['type'] == 'button':
                        ok(norm(t['label']) in text, f'/{p["path"]}: button missing: {t["label"]}')
                    elif t['type'] == 'image' and not build.is_divider(t) and t['file'] not in build.PRICES and t['file'] not in build.DROPPED and t['file'] not in build.REMOVED_FILES:
                        if t['w'] == 1000 and t['h'] == 400 or t['file'] == 'old/49fd21489bf3':
                            continue  # the reviews and "Ami nem szükséges": rebuilt as text
                        alt = html.escape(t.get('alt', ''), quote=True)
                        found = re.search(r'<img[^>]*' + re.escape(t['file']) + r'-[^>]*alt="' + re.escape(alt) + '"', doc)
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


if __name__ == '__main__':
    main()
