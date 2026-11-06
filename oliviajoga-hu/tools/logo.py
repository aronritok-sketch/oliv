#!/usr/bin/env python3
"""Olivia's logo (2026-11-06): option B of the three earlier options (the site's own display letter, Anton, in capitals,
spaced JÓGA), changed as she asked in the CRM chat: no leaf as accent (the real Í) and a different wave – a brush
stroke under the name, thin at both ends.

    python3 tools/logo.py <fonts folder>   → assets/brand/logo.svg (currentColor + the theme's orchid)

The fonts folder holds Google Fonts subsets (css2 API with &text=…): an2.woff2 (Anton, "OLÍVIAJÓG") and qs.woff2
(Quicksand:wght@700, "JÓGA"). Needs fonttools and brotli.
"""
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from logo_paths import text_path  # noqa: E402

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ORCHID = '#D92B86'  # the theme's orchid


def build(fonts):
    """Olivia chose B (2026-11-06) – without the leaf as accent (the real Í) and with a different wave: a brush
    stroke under the name, thin at both ends, thick in the middle. → assets/brand/logo.svg (currentColor)."""
    f = lambda n: os.path.join(fonts, n)  # noqa: E731
    word, wb, ww = text_path(f('an2.woff2'), 'OLÍVIA', 100, tracking=2)
    n, y, amp = 64, 24, 8
    top, bot = [], []
    for i in range(n + 1):
        t = i / n
        x = ww * t
        yy = y + amp * math.sin(t * math.pi * 3)
        th = .8 + 7.5 * math.sin(math.pi * t) ** 1.15
        top.append((x, yy - th / 2))
        bot.append((x, yy + th / 2))
    brush = ('M' + ' L'.join(f'{x:.1f} {yy:.1f}' for x, yy in top) + ' L' +
             ' L'.join(f'{x:.1f} {yy:.1f}' for x, yy in reversed(bot)) + ' Z')
    _, jb, jw = text_path(f('qs.woff2'), 'JÓGA', 27, tracking=15)
    jo, _, _ = text_path(f('qs.woff2'), 'JÓGA', 27, tracking=15, x0=(ww - jw) / 2, y0=72)
    out = os.path.join(HERE, 'assets', 'brand')
    os.makedirs(out, exist_ok=True)
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -132 {ww + 4:.0f} 210" role="img" aria-label="Olívia jóga">'
           f'<path fill="currentColor" d="{word}"/><path fill="{ORCHID}" d="{brush}"/><path fill="currentColor" d="{jo}"/></svg>')
    open(os.path.join(out, 'logo.svg'), 'w').write(svg)
    print('logo →', out)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(1)
    build(sys.argv[1])
