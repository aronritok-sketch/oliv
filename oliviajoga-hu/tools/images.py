#!/usr/bin/env python3
"""Pictures for the new oliviajoga.hu (round 2, Olivia's notes of 2026-11-05).

    python3 tools/images.py cutouts                 the "A jógáról - Tőlem" pictures and the post covers: the white
                                                    around them (made to blend into the old site's white page)
                                                    becomes transparent → old/<id>-800.webp (+ -1600.webp)
    python3 -I tools/images.py import <folder>      Olivia's photos from her Drive ("Főoldal kedvenc képek"):
                                                    <folder>/<name>.jpg → assets/img/new/<key>-800.jpg, -1600.jpg

Only white that touches the edge of the picture goes; white inside the photo (a shirt, the sky) stays.
"""
import json
import os
import sys

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
}


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
    files |= {'old/d1f4ce0eccf6'}  # home: "Miért jóga?" photo on white
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
    meta = {}
    for name, key in NEW.items():
        src = os.path.join(folder, name)
        if not os.path.isfile(src):
            print('missing', name)
            continue
        im = ImageOps.exif_transpose(Image.open(src)).convert('RGB')
        for w in (800, 1600):
            if w > im.width and w != 800:
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
    else:
        print(__doc__)
