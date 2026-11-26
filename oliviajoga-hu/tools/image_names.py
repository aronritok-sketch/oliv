"""Keyword file names for the pictures (Áron, 26 November: „kép fájlnevek tartalmazzák a szükséges kulcsszavakat ékezet
nélkül, kötőjellel”). Reads the built pages (dist/ and an --after build), takes each picture's alt text (what it shows +
the searched words), makes an ASCII, hyphenated name of it and writes content/image-names.json: old key → new key
(old/18bbeec02e8a → old/joga-ajandekutalvany-nyomtatott-kartyan-csepel-18-kerulet). Names already in the file stay as they
are (a picture's address should not change twice); --apply renames the files in assets/img (git mv, every size).

    python3 tools/image_names.py dist <after-dir> [--apply]
"""
import collections
import html
import json
import os
import re
import subprocess
import sys
import unicodedata

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(HERE, 'content', 'image-names.json')
STOP = {'a', 'az', 'egy', 'es', 'is', 'mogotte', 'kozben', 'utan', 'alatt', 'folott', 'mellett', 'kozott', 'teli', 'ket', 'sajat',
        'vagy', 'meg', 'mar', 'pedig', 'nagy', 'felett', 'ele', 'elott', 'tartott'}


def slug(alt, limit=70):
    s = unicodedata.normalize('NFKD', alt.replace('–', ' ').lower()).encode('ascii', 'ignore').decode()
    s = re.sub(r'\b(\d+)\.?\s*kerulet\w*', r'\1kerulet', s)
    s = re.sub(r'\bxxi?\.?\s*kerulet\w*|\bxxi?\b', '', s)
    out = []
    for t in re.findall(r'[a-z0-9]+', s):
        t = re.sub(r'^(\d+)kerulet$', r'\1-kerulet', t)
        if t not in STOP and len(t) > 1 and t not in out:
            out.append(t)
    res = []
    for t in out:
        if len('-'.join(res + [t])) > limit:
            break
        res.append(t)
    while res and res[-1] in ('kovacs', 'joga', 'jogaora', 'csoportos', 'resztvevok'):  # no half name, no dangling word
        if res[-1] == 'joga' and len(res) > 1 and res[-2] in ('flow', 'hatha', 'online'):
            break
        res.pop()
    return '-'.join(res)


def main():
    dirs = [a for a in sys.argv[1:] if not a.startswith('--')]
    alts = collections.OrderedDict()
    for d in dirs:
        for root, _, files in os.walk(d):
            for f in sorted(files):
                if not f.endswith('.html'):
                    continue
                for tag in re.findall(r'<img\b[^>]*>', open(os.path.join(root, f), encoding='utf-8').read()):
                    src = re.search(r'\bsrc="([^"]+)"', tag)
                    alt = re.search(r'\balt="([^"]*)"', tag)
                    m = re.search(r'/assets/img/((?:old|new|theme)/[^/]+?)(-(?:600|800|1200|1600))?(\.\w+)$', src.group(1) if src else '')
                    if m and alt:
                        alts.setdefault(m.group(1), []).append(html.unescape(alt.group(1)))
    names = json.load(open(OUT, encoding='utf-8')) if os.path.isfile(OUT) else {}
    names.pop('_note', None)
    done = set(names.values())
    for key, al in alts.items():
        if key in names or key in done:
            continue
        name = slug(max(al, key=len))
        base, n = name, 2
        while key.split('/')[0] + '/' + name in done:
            name, n = f'{base}-{n}', n + 1
        names[key] = key.split('/')[0] + '/' + name
        done.add(names[key])
    json.dump(dict({'_note': __doc__.strip().split('\n\n')[0]}, **names), open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(len(names), 'names →', OUT)
    if '--apply' in sys.argv:
        img = os.path.join(HERE, 'assets', 'img')
        moved = 0
        for key, new in names.items():
            if key.startswith('theme/'):
                continue  # the theme's photos are renamed when they are copied (build.py)
            d, b = key.split('/')
            for f in os.listdir(os.path.join(img, d)):
                m = re.fullmatch(re.escape(b) + r'((?:-(?:600|800|1200|1600))?\.\w+)', f)
                if m:
                    subprocess.run(['git', 'mv', os.path.join(img, d, f), os.path.join(img, new + m.group(1))], check=True, cwd=HERE)
                    moved += 1
        print(moved, 'files renamed')


if __name__ == '__main__':
    main()
