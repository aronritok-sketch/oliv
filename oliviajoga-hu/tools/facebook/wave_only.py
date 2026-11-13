"""Facebook images, Olivia's second wish (portal chat, 2026-11-13): „csak sima hullámmal … az összes képet teljes
egészében, nem kell felirat” – every photo whole (its own shape, nothing cut off), only the brush wave of her logo along
the bottom, no text, no logo.

    python3 -I wave_only.py <prepared folder> <html folder>
    node cover.js <html folder> <png folder>        (then JPEG: the caller converts)
"""
import math
import os
import sys

from PIL import Image

LONG = 2048  # the long side of the result


def brush(w, h, amp, th, periods=1.25, phase=0.0, n=160, y0=None):
    y0 = h / 2 if y0 is None else y0
    top, bot = [], []
    for i in range(n + 1):
        t = i / n
        x = w * t
        y = y0 + amp * math.sin(2 * math.pi * periods * t + phase)
        d = (.1 + .9 * math.sin(math.pi * t) ** 1.15) * th / 2
        top.append(f'{x:.1f} {y - d:.1f}')
        bot.append(f'{x:.1f} {y + d:.1f}')
    return 'M' + ' L'.join(top) + ' L' + ' L'.join(reversed(bot)) + 'Z'


def main():
    src, out = sys.argv[1], sys.argv[2]
    os.makedirs(out, exist_ok=True)
    skip = {'fb13-img5438.jpg'}  # the same photo as fb04 (it was twice in her folder)
    n = 0
    for f in sorted(os.listdir(src)):
        if f in skip:
            continue
        n += 1
        w0, h0 = Image.open(os.path.join(src, f)).size
        k = LONG / max(w0, h0)
        w, h = round(w0 * k), round(h0 * k)
        bh = round(min(w, h) * .2)      # the wave's strip
        mid = h - round(bh * .3)         # along the bottom edge: the photo stays whole
        ph = n * .9
        ww = w + 120
        under = brush(ww, bh, bh * .16, bh * .34, phase=ph + 2.1, periods=1.1)
        back = brush(ww, bh, bh * .15, bh * .26, phase=ph)
        front = brush(ww, bh, bh * .14, bh * .075, phase=ph + .6)
        html = f'''<!doctype html><html lang="hu" data-w="{w}" data-h="{h}"><head><meta charset="utf-8"><style>
*{{margin:0;box-sizing:border-box}} html,body{{width:{w}px;height:{h}px;overflow:hidden;background:#F3F2EC}}
img{{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}}
svg{{position:absolute;left:-60px;top:{mid - round(bh * .5)}px;width:{ww}px;height:{bh}px}}
.u{{fill:#FFD3E7;opacity:.75}} .b{{fill:rgba(255,255,255,.62)}} .f{{fill:#D92B86}}
</style></head><body><img src="file://{os.path.join(src, f)}" alt="">
<svg viewBox="0 0 {ww} {bh}" preserveAspectRatio="none"><path class="u" d="{under}"/><path class="b" d="{back}"/><path class="f" d="{front}"/></svg>
</body></html>'''
        name = f'olivia-joga-{n:02d}'
        open(os.path.join(out, name + '.html'), 'w', encoding='utf-8').write(html)
        print(name, f, w, h)


if __name__ == '__main__':
    main()
