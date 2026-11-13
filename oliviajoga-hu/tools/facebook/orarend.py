"""Online timetable story (Olivia, portal chat 2026-11-13): „egy nagyon hasonló órarendet szeretnék” – her ORAREND.png
(1151 x 2048: blurred class photo, a pink veil with wavy edges, white ÓRAREND with a pink script word, places in dark
capitals, times in white with an italic „hatha flow”, a pink pill at the bottom) for the online classes: „online jóga
órarend”, Wednesday 18.30–20.00, no places, private yoga and private prenatal by appointment, oliviajoga.online.
Readable, not too much on it. 1080 x 1920 (Instagram / Facebook story).

    python3 -I orarend.py <photo> <html folder>
    node cover.js <html folder> <png folder>
"""
import math
import os
import sys

W, H = 1080, 1920


def wave_band(w, top, bottom, amp, periods=1.3, phase=0.0, n=120):
    """A band from `top` to `bottom` whose upper and lower edges are soft waves (the veil of her design)."""
    up = [(w * i / n, top + amp * math.sin(2 * math.pi * periods * i / n + phase)) for i in range(n + 1)]
    lo = [(w * i / n, bottom + amp * math.sin(2 * math.pi * (periods * .8) * i / n + phase + 1.7)) for i in range(n, -1, -1)]
    return 'M' + ' L'.join(f'{x:.1f} {y:.1f}' for x, y in up + lo) + 'Z'


def main():
    photo, out = sys.argv[1], sys.argv[2]
    os.makedirs(out, exist_ok=True)
    veil = wave_band(W, 420, 1560, 34, phase=.6)
    html = f'''<!doctype html><html lang="hu" data-w="{W}" data-h="{H}"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Cormorant+Garamond:ital,wght@1,600;1,700&display=swap">
<style>
*{{margin:0;box-sizing:border-box}} html,body{{width:{W}px;height:{H}px;overflow:hidden}}
body{{position:relative;font-family:Montserrat,sans-serif;background:#c9a7ae}}
.bg{{position:absolute;inset:-40px;background:url("file://{photo}") 50% 55%/cover;filter:blur(7px) saturate(.95) brightness(.92)}}
.tint{{position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,236,242,.10),rgba(255,214,228,.18))}}
svg{{position:absolute;inset:0;width:{W}px;height:{H}px}}
.veil{{fill:rgba(236,150,190,.50)}}
.title{{position:absolute;left:0;right:0;top:118px;text-align:center}}
.title b{{display:block;font:800 178px/1 Montserrat;letter-spacing:-.01em;color:#fff;text-shadow:0 6px 26px rgba(70,20,40,.35)}}
.title i{{position:absolute;right:92px;top:-70px;font:italic 700 118px/1 "Cormorant Garamond",serif;color:#E2368F;text-shadow:0 3px 14px rgba(255,255,255,.55)}}
.block{{position:absolute;left:70px;right:70px;text-align:center;color:#1F1A1C}}
.h{{text-shadow:0 1px 12px rgba(255,255,255,.35);font:800 62px/1.08 Montserrat;letter-spacing:.005em;text-transform:uppercase}}
.h span{{display:inline-block;width:3px;height:56px;margin:0 22px;background:#1F1A1C;vertical-align:-6px;opacity:.75}}
.s{{margin-top:12px;font:600 36px/1.25 Montserrat;color:#2A1E24}}
.t{{margin-top:34px;font:700 70px/1.12 Montserrat;color:#fff;text-shadow:0 2px 4px rgba(50,10,30,.6),0 4px 22px rgba(50,10,30,.45)}}
.t em{{font:italic 700 74px/1 "Cormorant Garamond",serif;color:#fff}}
.li{{margin-top:22px;font:700 64px/1.15 Montserrat;color:#fff;text-shadow:0 2px 4px rgba(50,10,30,.6),0 4px 22px rgba(50,10,30,.45)}}
.pill{{position:absolute;left:50%;bottom:150px;transform:translateX(-50%);white-space:nowrap;padding:22px 44px;border-radius:26px;background:#E2368F;color:#fff;font:700 44px/1 Montserrat;letter-spacing:.02em;box-shadow:0 10px 28px rgba(120,20,70,.28)}}
</style></head><body>
<div class="bg"></div><div class="tint"></div>
<svg viewBox="0 0 {W} {H}"><path class="veil" d="{veil}"/></svg>
<div class="title"><i>online jóga</i><b>ÓRAREND</b></div>
<div class="block" style="top:620px">
  <p class="h">Csoportos<span></span>élő online</p>
  <p class="s">(első óra: december 16.)</p>
  <p class="t">• szerda 18.30–20.00</p>
  <p class="t" style="margin-top:6px"><em>hatha flow</em></p>
</div>
<div class="block" style="top:1100px">
  <p class="h">Privát órák<span></span>online</p>
  <p class="s">(egyénileg egyeztetett időpontban)</p>
  <p class="li">• privát jóga</p>
  <p class="li">• privát kismamatorna</p>
</div>
<div class="pill">BEJELENTKEZÉS: OLIVIAJOGA.ONLINE</div>
</body></html>'''
    open(os.path.join(out, 'online-joga-orarend.html'), 'w', encoding='utf-8').write(html)
    print('online-joga-orarend')


if __name__ == '__main__':
    main()
