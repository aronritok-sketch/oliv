#!/usr/bin/env python3
"""The Olívia jóga logo (round 2, from Olivia's moodboard: soft retro letters, a spaced "JÓGA" line).

"olívia" is set in Fraunces Black (SOFT 100, WONK 1, opsz 144), the accent of the í is a pink leaf, "JÓGA" is
Quicksand Bold with wide spacing, followed by a pink wave (flow). Everything is turned into SVG paths, so the
site needs no extra font for the logo.

    python3 tools/logo.py <fraunces.woff2> <quicksand.woff2>    → assets/brand/logo.svg, logo-light.svg,
                                                                   logo-inline.svg, favicon.svg

The two font files are subsets from Google Fonts (css2 API with &text=…):
  Fraunces:opsz,wght,SOFT,WONK@144,900,100,1  text "olíıva"   ·   Quicksand:wght@700  text "JÓGA"
Needs fonttools and brotli (pip install fonttools brotli).
"""
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from logo_paths import text_path  # noqa: E402

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FOREST, PAPER, ORCHID = '#1B3324', '#F3F2EC', '#EE3F9A'


def build(fraunces, quicksand):
    word, _, _ = text_path(fraunces, 'olıvia', 100)  # dotless ı: the leaf is the accent
    jo, jb, _ = text_path(quicksand, 'JÓGA', 21, tracking=10, x0=3, y0=34)
    cx, cy, L, W, a = 97.0, -60.5, 22, 9, math.radians(-58)

    def pt(u, v):
        return (cx + u * math.cos(a) - v * math.sin(a), cy + u * math.sin(a) + v * math.cos(a))
    p0, p1 = pt(-L / 2, 0), pt(L / 2, 0)
    c1, c2, c3, c4 = pt(-L / 4, -W), pt(L / 4, -W), pt(L / 4, W), pt(-L / 4, W)
    leaf = (f'M{p0[0]:.1f} {p0[1]:.1f}C{c1[0]:.1f} {c1[1]:.1f} {c2[0]:.1f} {c2[1]:.1f} {p1[0]:.1f} {p1[1]:.1f}'
            f'C{c3[0]:.1f} {c3[1]:.1f} {c4[0]:.1f} {c4[1]:.1f} {p0[0]:.1f} {p0[1]:.1f}Z')
    x1, x2, n, amp = jb[2] + 12, 238.0, 5, 6.5
    seg = (x2 - x1) / n
    wave = f'M{x1:.1f} 26.5' + ''.join(
        f'c{seg * .3:.2f} {(-amp if k % 2 == 0 else amp) * 1.3:.1f} {seg * .7:.2f} {(-amp if k % 2 == 0 else amp) * 1.3:.1f} {seg:.2f} 0' for k in range(n))

    def svg(fill, extra=''):
        return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -80 246 120" role="img" aria-label="Olívia jóga"{extra}>'
                f'<path fill="{fill}" d="{word}"/><path fill="{ORCHID}" d="{leaf}"/><path fill="{fill}" d="{jo}"/>'
                f'<path fill="none" stroke="{ORCHID}" stroke-width="3.4" stroke-linecap="round" d="{wave}"/></svg>')
    out = os.path.join(HERE, 'assets', 'brand')
    os.makedirs(out, exist_ok=True)
    open(os.path.join(out, 'logo.svg'), 'w').write(svg(FOREST))
    open(os.path.join(out, 'logo-light.svg'), 'w').write(svg(PAPER))
    open(os.path.join(out, 'logo-inline.svg'), 'w').write(svg('currentColor', ' class="brand__logo" focusable="false"'))
    o, _, _ = text_path(fraunces, 'o', 100)
    open(os.path.join(out, 'favicon.svg'), 'w').write(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="-14 -66 80 80"><rect x="-14" y="-66" width="80" height="80" rx="22" fill="{FOREST}"/>'
        f'<path fill="{PAPER}" d="{o}"/><path fill="{ORCHID}" d="{leaf}" transform="translate(-63 4)"/></svg>')
    print('logo →', out)


if __name__ == '__main__':
    if len(sys.argv) != 3:
        print(__doc__)
        sys.exit(1)
    build(sys.argv[1], sys.argv[2])
