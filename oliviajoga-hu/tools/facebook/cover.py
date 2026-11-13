"""Facebook cover images for the online yoga (Áron, 2026-11-13: „borítókép, amin rá van írva, hogy online jóga”),
in the oliviajoga.online look: cream, Anton headline with the lilac highlight, the pink brush wave, her photo in black
and white. Two sizes: the page cover (1640 x 624; phones show the middle 16:9, so the text and photo stay inside
x 270–1370) and the group cover (1640 x 856). No web address on the picture (Olivia, 11.12).

    python3 -I cover.py <html folder>
    node cover.js <html folder> <png folder>
"""
import math
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(HERE)))
LOGO = os.path.join(ROOT, 'wordpress', 'olivia-yoga', 'assets', 'img', 'logo-online.svg')
PHOTO = os.path.join(ROOT, 'wordpress', 'olivia-yoga', 'assets', 'img', 'online-yoga-living-room-1200.jpg')


def brush(w, h, amp, th, periods=1.25, phase=0.0, n=140, y0=None):
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


SIZES = {
    # name: width, height, headline px, photo box (x, y, w, h), text left, text top, wave top
    'facebook-borito-oldal-1640x624': (1640, 624, 124, (930, 56, 440, 462), 285, 62, 400),
    'facebook-borito-csoport-1640x856': (1640, 856, 168, (960, 92, 480, 640), 230, 110, 630),
}


def page(name, w, h, hl, box, tx, ty, wy):
    bx, by, bw, bh = box
    logo = open(LOGO, encoding='utf-8').read().strip().replace('<svg ', '<svg class="logo" ', 1)
    back = brush(w + 120, 240, 34, 66, phase=.4)
    front = brush(w + 120, 240, 30, 19, phase=1.0)
    under = brush(w + 120, 240, 40, 80, phase=2.5, periods=1.1)
    return f'''<!doctype html><html lang="hu" data-w="{w}" data-h="{h}"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Quicksand:wght@500;600;700&display=swap">
<style>
*{{box-sizing:border-box;margin:0}} html,body{{width:{w}px;height:{h}px;overflow:hidden}}
body{{background:#F3F2EC;font-family:Quicksand,sans-serif;color:#12231A;position:relative}}
.under{{position:absolute;left:-60px;top:{wy - 10}px;width:{w + 120}px;height:240px;z-index:0}} .under path{{fill:#FFD3E7;opacity:.9}}
.frame{{position:absolute;left:{bx}px;top:{by}px;width:{bw}px;height:{bh}px;border-radius:28px;overflow:hidden;z-index:1;background:#DEE7D6}}
.frame img{{width:100%;height:100%;object-fit:cover;object-position:58% 50%;display:block;filter:grayscale(1) contrast(1.05)}}
.over{{position:absolute;left:-60px;top:{wy + 12}px;width:{w + 120}px;height:240px;z-index:2}}
.over .b{{fill:rgba(255,255,255,.65)}} .over .f{{fill:#D92B86}}
.text{{position:absolute;left:{tx}px;top:{ty}px;z-index:3}}
.kicker{{font:700 {round(hl * .13)}px/1 Quicksand;letter-spacing:.22em;text-transform:uppercase;display:flex;align-items:center;gap:16px;margin-bottom:{round(hl * .16)}px}}
.kicker i{{display:inline-block;width:46px;height:2px;background:#12231A}}
.hl{{font:400 {hl}px/1.04 Anton;text-transform:uppercase;letter-spacing:.005em}}
.hl span{{display:inline-block;background:#C6A3EE;padding:.02em .08em 0;margin:.11em 0 0 -.08em;line-height:1}}
.sub{{font:400 {round(hl * .42)}px/1 Anton;text-transform:uppercase;color:#2B5036;margin-top:{round(hl * .1)}px;letter-spacing:.01em}}
.when{{font:600 {round(hl * .17)}px/1.3 Quicksand;margin-top:{round(hl * .17)}px;color:#26352B}}
.when b{{color:#B51F6F;font-weight:700}}
.card{{position:absolute;z-index:3;left:{bx + 22}px;top:{by + bh - round(hl * .62)}px;background:#FBFBF8;border-radius:18px;padding:{round(hl * .12)}px {round(hl * .16)}px;box-shadow:0 18px 40px rgba(18,35,26,.14)}}
.card .n{{font:400 {round(hl * .26)}px/1 Anton;text-transform:uppercase}}
.card .r{{font:700 {round(hl * .115)}px/1.2 Quicksand;color:#2B5036;margin-top:6px}}
.logo{{position:absolute;z-index:3;right:{w - (bx + bw)}px;top:{by + 14}px;width:{round(hl * .9)}px;height:auto;color:#12231A;background:rgba(251,251,248,.92);border-radius:14px;padding:10px 14px;display:none}}
</style></head><body>
<svg class="under" viewBox="0 0 {w + 120} 240" preserveAspectRatio="none"><path d="{under}"/></svg>
<div class="frame"><img src="file://{PHOTO}" alt=""></div>
<svg class="over" viewBox="0 0 {w + 120} 240" preserveAspectRatio="none"><path class="b" d="{back}"/><path class="f" d="{front}"/></svg>
<div class="text">
<p class="kicker"><i></i>Élő online jóga · magyarul</p>
<p class="hl">Online<br><span>jóga</span></p>
<p class="sub">Élőben, otthonról</p>
<p class="when">Csoportos flow jóga <b>minden szerdán 18:30–20:00</b></p>
</div>
<div class="card"><p class="n">Kovács Olívia</p><p class="r">Jógaoktató · Mozgás- és wellness coach</p></div>
{logo}
</body></html>'''


def main():
    out = sys.argv[1]
    os.makedirs(out, exist_ok=True)
    for name, spec in SIZES.items():
        open(os.path.join(out, name + '.html'), 'w', encoding='utf-8').write(page(name, *spec))
        print(name)


if __name__ == '__main__':
    main()
