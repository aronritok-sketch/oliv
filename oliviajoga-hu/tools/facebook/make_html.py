"""Facebook images, step 2: one page per photo in the site's design (1080 x 1350): the photo in a rounded frame,
the brush wave of her logo over it and the logo (Olivia, 11.12; no web address: „a www-t ki tudjuk szedni? a teljes feliratot”).

    python3 -I make_html.py <prepared folder> <html folder> ../../assets/brand/logo.svg
    node shoot.js <html folder> <jpg folder>
"""
import math, os, sys
from PIL import Image
prep, out, logo_path = sys.argv[1], sys.argv[2], sys.argv[3]
logo = open(logo_path, encoding='utf-8').read().strip().replace('<svg ', '<svg class="logo" ', 1)
TONES = [('#FFE0EE', '#FF72B6'), ('#ECE1FA', '#C6A3EE'), ('#FFE2CC', '#FF8C42'), ('#E6EEE1', '#A9BFA0')]

def brush(w, h, amp, th, periods=1.25, phase=0.0, n=120, y0=None):
    y0 = h / 2 if y0 is None else y0
    top, bot = [], []
    for i in range(n + 1):
        t = i / n
        x = w * t
        y = y0 + amp * math.sin(2 * math.pi * periods * t + phase)
        d = (.1 + .9 * math.sin(math.pi * t) ** 1.15) * th / 2
        top.append(f'{x:.1f} {y - d:.1f}'); bot.append(f'{x:.1f} {y + d:.1f}')
    return 'M' + ' L'.join(top) + ' L' + ' L'.join(reversed(bot)) + 'Z'

for n, f in enumerate(sorted(os.listdir(prep))):
    w, h = Image.open(os.path.join(prep, f)).size
    soft, strong = TONES[n % 4]
    land = w / h > 1.15
    fw, fh = (940, 705) if land else (940, 1000)
    fy = 150 if land else 70
    fb = fy + fh
    ph = n * 0.9
    back = brush(1160, 220, 34, 58, phase=ph)
    front = brush(1160, 220, 30, 17, phase=ph + .6)
    under = brush(1160, 220, 40, 70, phase=ph + 2.1, periods=1.1)
    head = ('<p class="hl">Élő online jóga</p>' if land else '')
    html = f'''<!doctype html><html lang="hu"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=Quicksand:wght@500;700&display=swap">
<style>
*{{box-sizing:border-box;margin:0}} html,body{{width:1080px;height:1350px;overflow:hidden}}
body{{background:{soft};font-family:Quicksand,sans-serif;color:#12231A;position:relative}}
.under{{position:absolute;left:-40px;top:{fb - 150}px;width:1160px;height:220px;z-index:0}}
.under path{{fill:{strong};opacity:.55}}
.frame{{position:absolute;left:70px;top:{fy}px;width:{fw}px;height:{fh}px;border-radius:30px;overflow:hidden;z-index:1;background:#DEE7D6}}
.frame img{{width:100%;height:100%;object-fit:cover;object-position:50% 40%;display:block}}
.over{{position:absolute;left:-40px;top:{fb - 175}px;width:1160px;height:220px;z-index:2}}
.over .b{{fill:rgba(255,255,255,.6)}} .over .f{{fill:#D92B86}}
.foot{{position:absolute;left:70px;right:70px;bottom:{70 if land else 64}px;z-index:3;display:flex;align-items:flex-end;justify-content:space-between;gap:30px}}
.logo{{width:{250 if land else 270}px;height:auto;color:#12231A;display:block}}
.go{{text-align:right}}
.k{{font:700 22px/1.2 Quicksand;letter-spacing:.2em;text-transform:uppercase;margin-bottom:10px}}
.u{{font:700 34px/1.1 Quicksand;color:#B51F6F;letter-spacing:.01em}}
.hl{{position:absolute;left:70px;top:{fb + 70}px;z-index:3;font:400 112px/1 Anton;text-transform:uppercase;letter-spacing:.005em}}
.top{{position:absolute;left:70px;right:70px;top:58px;display:flex;justify-content:space-between;align-items:center;font:700 22px/1 Quicksand;letter-spacing:.2em;text-transform:uppercase}}
.top i{{display:inline-block;width:46px;height:2px;background:#D92B86;margin-right:14px;vertical-align:middle}}
</style></head><body>
{'<p class="top"><span><i></i>Kovács Olívia</span><span>Hatha flow</span></p>' if land else ''}
<svg class="under" viewBox="0 0 1160 220" preserveAspectRatio="none"><path d="{under}"/></svg>
<div class="frame"><img src="file://{os.path.join(prep, f)}" alt=""></div>
<svg class="over" viewBox="0 0 1160 220" preserveAspectRatio="none"><path class="b" d="{back}"/><path class="f" d="{front}"/></svg>
{head}
<div class="foot">{logo}<div class="go">{'' if land else '<p class="k">Élő online jóga</p>'}</div></div>
</body></html>'''
    name = f'olivia-joga-facebook-{n + 1:02d}'
    open(os.path.join(out, name + '.html'), 'w', encoding='utf-8').write(html)
    print(name, 'landscape' if land else 'portrait', f)
