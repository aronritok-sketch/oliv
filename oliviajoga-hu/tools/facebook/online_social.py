"""The timetable story and the Facebook images in the look of oliviajoga.online (Olívia, portál-chat, 2026-11-16: „az
órarend sztorit és a facebook képeket szerkeszd újra légyszi a jelenlegi oliviajoga.online design alapján! az órarendet
rád bízom, de a jelenlegi háttér maradjon. A képek teljes egészében legyenek, nem kell rájuk szöveg, de kis design
jöhet”).

The site's look: Anton capitals, Cabin text, words on colour blocks (Bright Gold, Magenta Bloom, Tangerine), Evergreen
ink and buttons, light cards with soft shadows, the organic blobs of the site (the same paths), the four palette dots.

- The story (1080 x 1920): the background stays her picture without its old text (orarend_bg.py → orarend-hatter.png);
  over it the logo, the title on colour blocks and two cards: the group class (Wednesday 18:30–20:00, 90 minutes) and the
  one-to-one sessions (by appointment), the address in a dark button.
- The Facebook images: every photo whole (nothing cut off), with rounded corners, on a palette background with two of
  the site's blobs behind it and the four dots; no text, no logo. Five colour sets in turn.

    python3 -I online_social.py story <orarend-hatter.png> <logo.svg> <html folder>
    python3 -I online_social.py photos <prepared photo folder> <html folder>
    node cover.js <html folder> <png folder>        (then JPEG: the caller converts)
"""
import os
import sys

from PIL import Image

FOREST, INK, PINK, SUN, TANGERINE = '#002E2C', '#072624', '#E63462', '#FFDF22', '#FFAC81'
PAPER, CARD, SAGE_SOFT, TANG_SOFT, SUN_SOFT = '#F0EDEE', '#FAF8F9', '#DEE5D3', '#FFE2CC', '#FFF4C2'
FONTS = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Cabin:wght@500;600;700&display=swap">'

# the site's own blob (olivia-yoga: .oj-blob, viewBox 0 0 100 100)
BACK = 'M0 6C14 0 34 2 50 8C70 15 92 22 96 40C100 56 86 66 90 80C94 94 76 100 56 98C38 96 18 90 0 94Z'
FRONT = 'M0 30C18 22 40 28 56 38C74 49 80 64 72 76C64 88 42 90 24 86C14 84 6 84 0 86Z'


def blob(x, y, w, h, back, front, flip=False, rot=0):
    m = 'matrix(-1 0 0 1 100 0)' if flip else 'matrix(1 0 0 1 0 0)'
    return (f'<svg class="blob" style="left:{x}px;top:{y}px;width:{w}px;height:{h}px;transform:rotate({rot}deg)" viewBox="0 0 100 100" '
            f'preserveAspectRatio="none"><g transform="{m}"><path d="{BACK}" fill="{back}"/><path d="{FRONT}" fill="{front}"/></g></svg>')


def dots(x, y, r, gap, vertical=False):
    out = ''
    for i, c in enumerate((TANGERINE, PINK, '#8FB08A', SUN)):  # the site's four dots (sage a shade deeper, so it shows on light grounds)
        dx, dy = (0, i * gap) if vertical else (i * gap, 0)
        out += f'<i class="dot" style="left:{x + dx}px;top:{y + dy}px;width:{2 * r}px;height:{2 * r}px;background:{c}"></i>'
    return out


def story(bg, logo_svg, out):
    W, H = 1080, 1920
    logo = open(logo_svg, encoding='utf-8').read().replace('class="oj-logo"', 'class="logo"')
    html = f'''<!doctype html><html lang="hu" data-w="{W}" data-h="{H}"><head><meta charset="utf-8">{FONTS}<style>
*{{margin:0;box-sizing:border-box}} html,body{{width:{W}px;height:{H}px;overflow:hidden}}
body{{position:relative;font-family:Cabin,sans-serif;color:{INK};background:url("file://{bg}") center/cover no-repeat}}
.blob{{position:absolute;overflow:visible}} .dot{{position:absolute;border-radius:50%;display:block}}
.tag{{position:absolute;left:64px;top:64px;padding:18px 26px 14px;border-radius:26px;background:{CARD};box-shadow:0 14px 34px rgba(0,46,44,.18)}}
.logo{{display:block;width:210px;height:auto;color:{FOREST}}}
.title{{position:absolute;left:64px;top:300px}}
.title b{{display:table;font:400 108px/1 Anton,sans-serif;text-transform:uppercase;letter-spacing:.005em;padding:10px 26px 6px;margin:0 0 14px}}
.title .t1{{background:{SUN};color:{FOREST}}}
.title .t2{{background:{PINK};color:{FOREST};font-size:184px;padding:14px 30px 8px}}
.card{{position:absolute;left:64px;right:64px;background:{CARD};border-radius:40px;padding:46px 54px 48px;box-shadow:0 22px 50px rgba(0,46,44,.22)}}
.kick{{display:flex;align-items:center;gap:16px;font:700 30px/1 Cabin,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:{PINK};margin-bottom:22px}}
.kick i{{width:16px;height:16px;border-radius:50%;background:{PINK};display:block}}
.big{{font:400 112px/1 Anton,sans-serif;text-transform:uppercase;color:{FOREST};letter-spacing:.005em}}
.time{{display:inline-block;margin-top:16px;padding:10px 24px 4px;background:{TANGERINE};font:400 92px/1 Anton,sans-serif;color:{FOREST}}}
.line{{margin-top:26px;font:600 42px/1.25 Cabin,sans-serif;color:{INK}}}
.first{{display:inline-flex;align-items:center;gap:14px;margin-top:22px;padding:12px 24px;border-radius:999px;background:{SUN_SOFT};font:700 32px/1 Cabin,sans-serif;color:{FOREST}}}
.first i{{width:14px;height:14px;border-radius:50%;background:{SUN};box-shadow:0 0 0 3px {FOREST};display:block}}
.mid{{font:400 78px/1.02 Anton,sans-serif;text-transform:uppercase;color:{FOREST}}}
ul{{list-style:none;margin-top:28px;display:grid;grid-template-columns:1fr 1fr;gap:18px 26px}}
li{{display:flex;align-items:center;gap:16px;font:600 38px/1.15 Cabin,sans-serif;color:{INK}}}
li i{{flex:0 0 22px;height:22px;border-radius:50%;display:block}}
.btn{{position:absolute;left:50%;bottom:70px;transform:translateX(-50%);white-space:nowrap;padding:34px 56px;border-radius:999px;background:{FOREST};color:#fff;
  font:700 34px/1 Cabin,sans-serif;letter-spacing:.14em;text-transform:uppercase;box-shadow:0 18px 40px rgba(0,46,44,.3)}}
.btn span{{color:{SUN}}}
</style></head><body>
{blob(700, -120, 520, 520, FOREST, SUN, flip=True, rot=8)}
{blob(-190, 1610, 560, 420, PINK, TANGERINE, rot=-6)}
<div class="tag">{logo}</div>
{dots(842, 120, 13, 40)}
<div class="title"><b class="t1">Online jóga</b><b class="t2">Órarend</b></div>
<div class="card" style="top:748px">
  <p class="kick"><i></i>Csoportos · élő online</p>
  <p class="big">Szerda</p>
  <p class="time">18:30–20:00</p>
  <p class="line">Online flow jóga · 90 perc</p>
  <p class="first"><i></i>Első óra: december 16.</p>
</div>
<div class="card" style="top:1316px">
  <p class="kick"><i style="background:{FOREST}"></i>Egyéni órák · online</p>
  <p class="mid">Egyeztetett időpontban</p>
  <ul>
    <li><i style="background:{SUN}"></i>Privát jóga</li>
    <li><i style="background:{PINK}"></i>Kismamatorna</li>
    <li><i style="background:{TANGERINE}"></i>Mobilizáció</li>
    <li><i style="background:{FOREST}"></i>Relaxáció &amp; légzés</li>
  </ul>
</div>
<p class="btn">Bejelentkezés: <span>oliviajoga.online</span></p>
</body></html>'''
    os.makedirs(out, exist_ok=True)
    open(os.path.join(out, 'online-joga-orarend.html'), 'w', encoding='utf-8').write(html)
    print('online-joga-orarend', W, H)


# background, the big blob (back, front), the small blob (back, front)
SETS = [
    (SUN, (FOREST, PINK), (PINK, TANGERINE)),
    (PAPER, (PINK, SUN), (FOREST, SAGE_SOFT)),
    (SAGE_SOFT, (FOREST, TANGERINE), (SUN, PINK)),
    (TANG_SOFT, (PINK, FOREST), (SUN, TANGERINE)),
    (FOREST, (SUN, TANGERINE), (PINK, SUN)),
]


def photos(src, out):
    os.makedirs(out, exist_ok=True)
    skip = {'fb13-img5438.jpg'}  # the same photo as fb04 (it was twice in her folder)
    n = 0
    for f in sorted(os.listdir(src)):
        if f in skip or not f.lower().endswith(('.jpg', '.jpeg', '.png')):
            continue
        n += 1
        w0, h0 = Image.open(os.path.join(src, f)).size
        long_ = 1760
        k = long_ / max(w0, h0)
        pw, ph = round(w0 * k), round(h0 * k)
        pad = 150
        W, H = pw + 2 * pad, ph + 2 * pad
        bg, (b1, f1), (b2, f2) = SETS[(n - 1) % len(SETS)]
        corner = (n - 1) % 2  # the big blob bottom right, or top right, in turn
        big = round(max(W, H) * .62)
        small = round(max(W, H) * .34)
        if corner == 0:
            blobs = blob(W - big * .78, H - big * .74, big, big * .82, b1, f1, flip=True, rot=-4) + blob(-small * .3, -small * .28, small, small * .8, b2, f2, rot=12)
            d = dots(pad + 8, H - pad + 54, 15, 46)
        else:
            blobs = blob(W - big * .74, -big * .3, big, big * .82, b1, f1, flip=True, rot=172) + blob(-small * .32, H - small * .58, small, small * .8, b2, f2, rot=-10)
            d = dots(W - pad - 8 - 3 * 46 - 30, pad - 84, 15, 46)
        html = f'''<!doctype html><html lang="hu" data-w="{W}" data-h="{H}"><head><meta charset="utf-8"><style>
*{{margin:0;box-sizing:border-box}} html,body{{width:{W}px;height:{H}px;overflow:hidden;background:{bg}}}
.blob{{position:absolute;overflow:visible}} .dot{{position:absolute;border-radius:50%;display:block}}
.ph{{position:absolute;left:{pad}px;top:{pad}px;width:{pw}px;height:{ph}px;border-radius:38px;overflow:hidden;
  box-shadow:0 26px 60px rgba(0,46,44,.28),0 0 0 10px {CARD}}}
.ph img{{width:100%;height:100%;display:block;object-fit:cover}}
</style></head><body>{blobs}{d}<div class="ph"><img src="file://{os.path.join(src, f)}" alt=""></div></body></html>'''
        name = f'olivia-joga-{n:02d}'
        open(os.path.join(out, name + '.html'), 'w', encoding='utf-8').write(html)
        print(name, f, W, H)


if __name__ == '__main__':
    if sys.argv[1] == 'story':
        story(sys.argv[2], sys.argv[3], sys.argv[4])
    else:
        photos(sys.argv[2], sys.argv[3])
