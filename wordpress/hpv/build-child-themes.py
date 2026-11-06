#!/usr/bin/env python3
"""The Olivia themes as iu_theme child themes for the HelloProVision delivery (hlprv repo, deploy/README.md).

    python3 wordpress/hpv/build-child-themes.py <hlprv folder>

writes <hlprv>/oliviayoga/wp-theme/oliviayoga-us/      Olivia Kovacs Yoga – the US (Fort Myers) booking site
       <hlprv>/oliviayoga/wp-theme/oliviayoga-booking/ Olivia Jóga – Online, the Hungarian online booking site
       <hlprv>/hathaflow/wp-theme/hathaflow/           Hathaflow – the booking system as a product demo
       <hlprv>/oliviayoga/wp-theme/oliviayoga/         oliviajoga.hu, the new Hungarian frontend (from oliviajoga-hu)

The first three are the olivia-yoga theme (wordpress/olivia-yoga) with a fixed edition: the template directory calls
become stylesheet ones (a child theme's files are its own), functions.php gets the edition and inc/hpv.php (iu_theme
compatibility + hpv_child_setup). Hathaflow ships none of Olivia's photos or copy: neutral pictures are drawn here.
The booking plugin (olivia-studio) is installed next to them from wordpress/dist/olivia-studio.zip (projects.json).
The version of each child theme is the source theme's version. Run after every change, then commit in hlprv.
"""
import hashlib
import json
import math
import os
import re
import shutil
import subprocess
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SRC = os.path.join(ROOT, 'wordpress', 'olivia-yoga')
HPV = os.path.join(ROOT, 'wordpress', 'hpv', 'hpv.php')

VARIANTS = {
    'oliviayoga-us': {
        'project': 'oliviayoga', 'edition': 'fort-myers', 'name': 'Olivia Kovacs Yoga (US)', 'content': 'content.json',
        'desc': 'Olivia Kovacs Yoga, Fort Myers (FL): the US booking site in English, Hungarian and Spanish – classes, private yoga, passes, memberships, online classes. iu_theme child; booking: Olivia Studio plugin.',
    },
    'oliviayoga-booking': {
        'project': 'oliviayoga', 'edition': 'hu-online', 'name': 'Olivia Jóga – Online', 'content': 'content-hu-online.json',
        'desc': 'Olivia Jóga – Online: élő online jógaórák magyarul, foglalással és fizetéssel (forint). iu_theme child; foglalás: Olivia Studio bővítmény.',
    },
    'hathaflow': {
        'project': 'hathaflow', 'edition': 'hathaflow', 'name': 'Hathaflow', 'content': 'content-hathaflow.json',
        'desc': 'Hathaflow: yoga studio booking system demo – timetable, online booking and payment, passes, memberships, private and online sessions, gift cards. Sample studio and data. iu_theme child; booking: Olivia Studio plugin.',
    },
}
CONTENT_FILES = ('content.json', 'content-hu-online.json', 'content-hathaflow.json')

# Neutral pictures for Hathaflow: the theme's colours, soft waves (no people).
PALETTES = [('#C6A3EE', '#F3E9FF', '#2B5036'), ('#FF72B6', '#FFE3F0', '#2B5036'), ('#A9BFA0', '#EEF3EA', '#1B3324'),
            ('#FF8C42', '#FFE9DA', '#2B5036'), ('#5B8560', '#DEE7D6', '#12231A'), ('#2B5036', '#5B8560', '#C6A3EE')]


def hexrgb(h):
    return tuple(int(h[i:i + 2], 16) for i in (1, 3, 5))


def neutral_picture(key, w, h, out):
    seed = int(hashlib.md5(key.encode()).hexdigest(), 16)
    a, b, c = (hexrgb(x) for x in PALETTES[seed % len(PALETTES)])
    t = np.linspace(0, .35, h)[:, None, None]  # soft vertical gradient from the light tone to a touch of the main tone
    rows = np.array(b, float)[None, None, :] + (np.array(a, float) - np.array(b, float))[None, None, :] * t
    im = Image.fromarray(np.repeat(rows, w, axis=1).round().astype('uint8'), 'RGB')
    d = ImageDraw.Draw(im, 'RGBA')
    phase = (seed >> 8) % 628 / 100
    for k, (tone, alpha, base, amp) in enumerate([(a, 150, .58, .07), (a, 235, .72, .06), (c, 60, .86, .05)]):
        pts = [(x, h * base + h * amp * math.sin(x / w * 2 * math.pi * (1.1 + .25 * k) + phase + k)) for x in range(0, w + 8, 8)]
        d.polygon(pts + [(w, h), (0, h)], fill=tone + (alpha,))
    r = min(w, h) * (.16 + (seed >> 16) % 10 / 100)
    cx, cy = w * (.25 + (seed >> 20) % 50 / 100), h * (.22 + (seed >> 24) % 20 / 100)
    d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=(255, 255, 255, 120))
    im = im.filter(ImageFilter.GaussianBlur(.6))
    im.save(out, 'JPEG', quality=78, optimize=True, progressive=True)


def php_files(folder):
    for base, _, files in os.walk(folder):
        for f in files:
            if f.endswith('.php'):
                yield os.path.join(base, f)


def build_variant(slug, v, hlprv):
    dst = os.path.join(hlprv, v['project'], 'wp-theme', slug)
    if os.path.isdir(dst):
        shutil.rmtree(dst)
    shutil.copytree(SRC, dst, ignore=shutil.ignore_patterns('.DS_Store', '*.zip'))
    version = re.search(r'Version:\s*([0-9.]+)', open(os.path.join(SRC, 'style.css'), encoding='utf-8').read()).group(1)
    # A child theme's own files: stylesheet directory, not template (= iu_theme).
    for p in php_files(dst):
        s = open(p, encoding='utf-8').read()
        s2 = s.replace('get_template_directory_uri()', 'get_stylesheet_directory_uri()').replace('get_template_directory()', 'get_stylesheet_directory()')
        if s2 != s:
            open(p, 'w', encoding='utf-8').write(s2)
    fn = os.path.join(dst, 'functions.php')
    body = open(fn, encoding='utf-8').read()
    body = re.sub(r'^<\?php\s*/\*\*.*?\*/\s*', '', body, count=1, flags=re.S)
    body = body.replace("defined( 'ABSPATH' ) || exit;\n", '', 1)
    head = ('<?php\n/**\n * ' + v['name'] + ' – iu_theme child theme (HelloProVision delivery, hlprv deploy/README.md).\n'
            ' * Built from the oliv repo (wordpress/olivia-yoga, edition "' + v['edition'] + '") by wordpress/hpv/build-child-themes.py:\n'
            ' * edit the source there and rebuild, not here.\n */\n\ndefined( \'ABSPATH\' ) || exit;\n\n'
            "defined( 'OY_EDITION' ) || define( 'OY_EDITION', '" + v['edition'] + "' );\n"
            "require_once get_stylesheet_directory() . '/inc/hpv.php';\n\n")
    open(fn, 'w', encoding='utf-8').write(head + body)
    shutil.copy(HPV, os.path.join(dst, 'inc', 'hpv.php'))
    for f in CONTENT_FILES:  # only this edition's copy
        if f != v['content']:
            os.remove(os.path.join(dst, 'demo', f))
    css = os.path.join(dst, 'style.css')
    s = open(css, encoding='utf-8').read()
    s = re.sub(r'/\*.*?\*/', ('/*\nTheme Name: ' + v['name'] + '\nTemplate: iu_theme\nAuthor: HelloProVision\nAuthor URI: https://helloprovision.com/\n'
                              'Description: ' + v['desc'] + '\nVersion: ' + version + '\nRequires at least: 6.4\nRequires PHP: 8.1\nText Domain: olivia-yoga\n'
                              'License: GPL-2.0-or-later\n*/'), s, count=1, flags=re.S)
    open(css, 'w', encoding='utf-8').write(s)
    if slug == 'hathaflow':
        img = os.path.join(dst, 'assets', 'img')
        meta = json.load(open(os.path.join(img, 'images.json'), encoding='utf-8'))
        for f in sorted(os.listdir(img)):
            m = re.match(r'(.+)-(\d+)\.jpg$', f)
            if m:
                with Image.open(os.path.join(img, f)) as im:
                    size = im.size
                neutral_picture(m.group(1), *size, os.path.join(img, f))
        for k in meta:
            meta[k]['alt'] = ''  # decorative
            meta[k]['pos'] = '50% 50%'
        json.dump(meta, open(os.path.join(img, 'images.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        os.remove(os.path.join(img, 'fitmummy.svg'))
        neutral_picture('hathaflow-screenshot', 1200, 900, os.path.join(dst, 'screenshot.png'))
        shot = Image.open(os.path.join(dst, 'screenshot.png'))
        d = ImageDraw.Draw(shot)
        try:
            font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 120)
        except OSError:
            font = ImageFont.load_default()
        d.text((90, 330), 'Hathaflow', fill=(18, 35, 26), font=font)
        shot.save(os.path.join(dst, 'screenshot.png'), 'PNG')
    return dst, version


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(1)
    hlprv = os.path.abspath(sys.argv[1])
    for slug, v in VARIANTS.items():
        dst, version = build_variant(slug, v, hlprv)
        size = sum(os.path.getsize(os.path.join(b, f)) for b, _, fs in os.walk(dst) for f in fs)
        print(f'{slug} {version} → {os.path.relpath(dst, hlprv)} ({size / 1e6:.1f} MB)')
    hu = os.path.join(ROOT, 'oliviajoga-hu', 'build.py')
    subprocess.run([sys.executable, hu, '--wp', os.path.join(hlprv, 'oliviayoga', 'wp-theme', 'oliviayoga')], check=True)


if __name__ == '__main__':
    main()
