#!/usr/bin/env python3
"""Pictures for the new oliviajoga.hu (round 2, Olivia's notes of 2026-11-05).

    python3 tools/images.py cutouts                 the "A jógáról - Tőlem" pictures and the post covers: the white
                                                    around them (made to blend into the old site's white page)
                                                    becomes transparent → old/<id>-800.webp (+ -1600.webp)
    python3 -I tools/images.py cards <folder>       the journal cards: Olivia's full photos (white edges cut) → new/cikk-*
    python3 -I tools/images.py import <folder>      Olivia's photos from her Drive ("Főoldal kedvenc képek"):
                                                    <folder>/<name>.jpg → assets/img/new/<key>-800.jpg, -1600.jpg

Only white that touches the edge of the picture goes; white inside the photo (a shirt, the sky) stays.
"""
import json
import os
import sys
import unicodedata

import numpy as np
from PIL import Image, ImageFilter, ImageOps
from scipy import ndimage

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG = os.path.join(HERE, 'assets', 'img')

# Drive file name → key and size limit. hero.JPG and diszito2.JPG are only 800 px wide on Drive.
NEW = {
    'hero.jpg': 'hero', 'diszito.jpg': 'diszito', 'diszito2.jpg': 'diszito2', 'diszito3.jpg': 'diszito3',
    'diszito4.jpg': 'diszito4', 'diszito5.jpg': 'diszito5', 'diszito6.jpg': 'diszito6',
    'Olivia3.jpg': 'olivia3', 'private-yoga.jpg': 'private-yoga',
    # 2026-11-06, from Olivia in the CRM chat: the two home photos instead of the old round cut-outs
    'IMG_6232.jpg': 'miert-joga', 'kovacs-olivia-hatha-joga.jpeg': 'gyakoroljunk',
}


# 2026-11-06 (Olivia, CRM chat): the journal cards get her full photos instead of the old graphics ("csatolom a teljes
# képeket, nyilván a fehéreket vágjuk le"); where she named a file after the article, that is the new picture.
# Drive folder 1pumxZ42VhuVxd2hfqxHUoJV_-BcMJqId → <folder>/<name>; card path → file name.
CARDS = {
    'milyen-elemekből-áll-1': '1.png', 'milyen-elemekből-áll-2': 'instasize_230811234536.png',
    'milyen-elemekből-áll-3': '101404324_1107670682948871.jpg', 'milyen-elemekből-áll-4': '2.png',
    'milyen-elemekből-áll-5': 'instasize_230812150809.png', 'a-nyújtás-hatása': 'a nyújtás hatásai.JPG',
    'jóga-pozitív-gondolkodás': '101318045_583284935900288.jpg', 'a-lelki-egészség-világnapja-ősszel': 'IMG_20230913_162534_242.jpg',
    'binge': 'szerinted melyik az egészséges.jpg', 'légzés': 'Polish_20230919_165457400.jpg', 'zene': 'te hogy szereted.JPG',
    'állj-meg': 'állj meg egy pillanatra.jpg', 'pajzsmirigy-harmonizálás': 'hatha jóga pestszentlőrinc kobra.jpg',
    'mit-ad-a-jóga': 'Polish_20231112_232918931.jpg', 'munka': 'Messenger_creation_afa34cca.jpeg', 'meditáció': '28992.JPG',
    'yama': '3.png', 'niyama': '4.png', 'ászana': '5.png', 'pránajáma': '6.png', 'pratyahara': 'Olívia (18).jpg',
    'dhárana': '8.png', 'dhjána': '9.png', 'szamádhi': '10.png',
}


def card_key(path):
    return 'cikk-' + unicodedata.normalize('NFKD', path).encode('ascii', 'ignore').decode()


def trim_white(im):
    """Cut the white (or near-white) bands around a photo."""
    a = np.asarray(im.convert('RGB')).astype(np.int16)
    content = a.min(axis=2) < 238
    rows, cols = np.where(content.mean(axis=1) > .02)[0], np.where(content.mean(axis=0) > .02)[0]
    if not len(rows) or not len(cols):
        return im
    return im.crop((int(cols[0]), int(rows[0]), int(cols[-1]) + 1, int(rows[-1]) + 1))


def import_cards(folder):
    mp = os.path.join(HERE, 'content', 'new-photos.json')
    meta = json.load(open(mp))
    for path, name in CARDS.items():
        im = trim_white(ImageOps.exif_transpose(Image.open(os.path.join(folder, name))).convert('RGB'))
        key = card_key(path)
        for w in (800, 1600):
            if w == 1600 and im.width <= 800:
                continue
            x = im if im.width <= w else im.resize((w, round(im.height * w / im.width)), Image.LANCZOS)
            if x is not im:
                x = x.filter(ImageFilter.UnsharpMask(radius=1.2, percent=40, threshold=2))
            x.save(os.path.join(IMG, 'new', f'{key}-{w}.jpg'), 'JPEG', quality=82, optimize=True, progressive=True)
        big = min(im.width, 1600)
        meta[key] = {'w': big, 'h': round(im.height * big / im.width)}
        print(key, im.size)
    json.dump(meta, open(mp, 'w'), indent=1)


# The thyroid poster has white text panels that touch the edge: it stays as it is.
KEEP_WHITE = {'old/1639ee8b4695'}


def cutout(src, dst):
    im = Image.open(src).convert('RGB')
    a = np.asarray(im).astype(np.int16)
    lo, hi = a.min(axis=2), a.max(axis=2)
    white = (lo >= 234) & (hi - lo <= 16)
    lab, n = ndimage.label(white)
    edge = set(np.unique(np.concatenate([lab[0], lab[-1], lab[:, 0], lab[:, -1]]))) - {0}
    mask = np.isin(lab, list(edge))
    if mask.mean() < 0.03:
        return False
    alpha = np.where(mask, 0, 255).astype(np.float32)
    ring = ndimage.binary_dilation(mask, iterations=2) & ~mask  # soft edge: near-white pixels next to the cut
    soft = np.clip((252 - lo) * 255 / 46, 0, 255)
    alpha[ring] = np.minimum(alpha[ring], soft[ring])
    rgba = np.dstack([a.astype(np.uint8), alpha.astype(np.uint8)])
    out = Image.fromarray(rgba, 'RGBA')
    out.save(dst, 'WEBP', quality=86, method=6)
    return True


def cutouts():
    data = json.load(open(os.path.join(HERE, 'content', 'pages.json'), encoding='utf-8'))
    pages = {p['path']: p for p in data['pages']}
    files = set()
    jp = pages['jógáról']
    posts = set()
    for s in jp['sections']:
        for c in s['columns']:
            for t in c:
                if t['type'] == 'image' and t['ext'] == '.jpg':
                    files.add(t['file'])
                    if t.get('href', '').startswith('page:'):
                        posts.add(t['href'][5:])
    for path in posts:
        for s in pages[path]['sections'][1:]:
            imgs = [t for c in s['columns'] for t in c if t['type'] == 'image' and t['ext'] == '.jpg' and t['w'] / max(t['h'], 1) < 3]
            if imgs:
                files.add(imgs[0]['file'])
                break
    done = []
    for f in sorted(files - KEEP_WHITE):
        ok = False
        for w in (800, 1600):
            src = os.path.join(IMG, f'{f}-{w}.jpg')
            if os.path.isfile(src):
                ok = cutout(src, os.path.join(IMG, f'{f}-{w}.webp')) or ok
        if ok:
            done.append(f)
    json.dump(sorted(done), open(os.path.join(HERE, 'content', 'cutouts.json'), 'w'), indent=1)
    print(f'{len(done)} of {len(files)} pictures cut out')


def import_photos(folder):
    os.makedirs(os.path.join(IMG, 'new'), exist_ok=True)
    mp = os.path.join(HERE, 'content', 'new-photos.json')
    meta = json.load(open(mp)) if os.path.isfile(mp) else {}  # photos imported earlier stay
    for name, key in NEW.items():
        src = os.path.join(folder, name)
        if not os.path.isfile(src):
            continue
        im = ImageOps.exif_transpose(Image.open(src)).convert('RGB')
        for w in (800, 1600):
            if w == 1600 and im.width <= 800:  # a 801–1600 px photo keeps its own width as the large one
                continue
            x = im if im.width <= w else im.resize((w, round(im.height * w / im.width)), Image.LANCZOS)
            if x is not im:
                x = x.filter(ImageFilter.UnsharpMask(radius=1.2, percent=40, threshold=2))
            x.save(os.path.join(IMG, 'new', f'{key}-{w}.jpg'), 'JPEG', quality=82, optimize=True, progressive=True)
        big = min(im.width, 1600)
        meta[key] = {'w': big, 'h': round(im.height * big / im.width)}
    json.dump(meta, open(os.path.join(HERE, 'content', 'new-photos.json'), 'w'), indent=1)
    print(len(meta), 'photos imported')


if __name__ == '__main__':
    if sys.argv[1:2] == ['cutouts']:
        cutouts()
    elif sys.argv[1:2] == ['import']:
        import_photos(sys.argv[2])
    elif sys.argv[1:2] == ['cards']:
        import_cards(sys.argv[2])
    else:
        print(__doc__)
