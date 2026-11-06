#!/usr/bin/env python3
"""Logo options for Olivia (her note: „Logót cseréljük ki … inspók a Moodboardban”). Three directions from the
moodboard, each turned into SVG paths (no extra font on the site):

  A – soft retro lowercase (Fraunces Black, SOFT/WONK), pink leaf accent, spaced JÓGA, wave
  B – the site's own display letter (Anton) in capitals, pink leaf accent on the Í, spaced JÓGA – closest to the design
  C – full, round lowercase (Bagel Fat One), pink leaf accent, small spaced JÓGA

    python3 tools/logo.py <fonts folder>   → assets/brand/options/logo-a.svg, logo-b.svg, logo-c.svg

The fonts folder holds Google Fonts subsets (css2 API with &text=…): fr2.woff2 (Fraunces:opsz,wght,SOFT,WONK@144,900,
100,1, "olíıva"), an.woff2 (Anton, "OLIVAJÓG"), bg.woff2 (Bagel Fat One, "olıvia"), qs.woff2 (Quicksand:wght@700,
"JÓGA"). Needs fonttools and brotli.
"""
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from logo_paths import text_path  # noqa: E402

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ORCHID = '#EE3F9A'


def leaf(cx, cy, L=22, W=9, deg=-58):
    a = math.radians(deg)

    def pt(u, v):
        return (cx + u * math.cos(a) - v * math.sin(a), cy + u * math.sin(a) + v * math.cos(a))
    p0, p1 = pt(-L / 2, 0), pt(L / 2, 0)
    c1, c2, c3, c4 = pt(-L / 4, -W), pt(L / 4, -W), pt(L / 4, W), pt(-L / 4, W)
    return (f'M{p0[0]:.1f} {p0[1]:.1f}C{c1[0]:.1f} {c1[1]:.1f} {c2[0]:.1f} {c2[1]:.1f} {p1[0]:.1f} {p1[1]:.1f}'
            f'C{c3[0]:.1f} {c3[1]:.1f} {c4[0]:.1f} {c4[1]:.1f} {p0[0]:.1f} {p0[1]:.1f}Z')


def wave(x1, x2, y, n=5, amp=6.5):
    seg = (x2 - x1) / n
    return f'M{x1:.1f} {y}' + ''.join(
        f'c{seg * .3:.2f} {(-amp if k % 2 == 0 else amp) * 1.3:.1f} {seg * .7:.2f} {(-amp if k % 2 == 0 else amp) * 1.3:.1f} {seg:.2f} 0' for k in range(n))


def svg(vb, parts):
    body = ''.join(f'<path fill="{"currentColor" if c == "ink" else c}" d="{d}"/>' if c != 'stroke' else
                   f'<path fill="none" stroke="{ORCHID}" stroke-width="3.4" stroke-linecap="round" d="{d}"/>' for c, d in parts)
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" role="img" aria-label="Olívia jóga">{body}</svg>'


def build(fonts):
    f = lambda n: os.path.join(fonts, n)  # noqa: E731
    out = os.path.join(HERE, 'assets', 'brand', 'options')
    os.makedirs(out, exist_ok=True)
    # A – soft retro lowercase
    word, _, _ = text_path(f('fr2.woff2'), 'olıvia', 100)
    jo, jb, _ = text_path(f('qs.woff2'), 'JÓGA', 21, tracking=10, x0=3, y0=34)
    a = svg('-2 -80 246 120', [('ink', word), (ORCHID, leaf(97, -60.5)), ('ink', jo), ('stroke', wave(jb[2] + 12, 238, 26.5))])
    # B – Anton capitals (the site's display letter); the leaf is the accent of the Í
    word, wb, ww = text_path(f('an.woff2'), 'OLIVIA', 100, tracking=2)
    # x of the first I: advance of "OL"
    _, _, wol = text_path(f('an.woff2'), 'OL', 100, tracking=2)
    _, ib, wi = text_path(f('an.woff2'), 'I', 100)
    ix = wol + 2 + (ib[0] + ib[2]) / 2
    jo, jb, _ = text_path(f('qs.woff2'), 'JÓGA', 23, tracking=12, x0=2, y0=38)
    b = svg(f'-2 -122 {ww + 6:.0f} 170', [('ink', word), (ORCHID, leaf(ix + 4, -114, L=26, W=10, deg=-55)), ('ink', jo),
                                          ('stroke', wave(jb[2] + 14, ww - 2, 29.5, n=3))])
    # C – full, round lowercase
    word, cb, cw = text_path(f('bg.woff2'), 'olıvia', 100)
    _, _, wol = text_path(f('bg.woff2'), 'ol', 100)
    _, ib, _ = text_path(f('bg.woff2'), 'ı', 100)
    ix = wol + (ib[0] + ib[2]) / 2
    jo, jb, _ = text_path(f('qs.woff2'), 'JÓGA', 19, tracking=12, x0=4, y0=40)
    c = svg(f'-2 -96 {cw + 6:.0f} 146', [('ink', word), (ORCHID, leaf(ix + 5, -78, L=26, W=11, deg=-50)), ('ink', jo),
                                         ('stroke', wave(jb[2] + 14, cw - 4, 32.5, n=4))])
    for k, v in (('a', a), ('b', b), ('c', c)):
        open(os.path.join(out, f'logo-{k}.svg'), 'w').write(v)
    print('logo options →', out)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(1)
    build(sys.argv[1])
