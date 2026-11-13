"""Online timetable story (Olivia, portal chat 2026-11-13).

First wish: „egy nagyon hasonló órarendet szeretnék” – her ORAREND.png, for the online classes: „online jóga órarend”,
Wednesday 18.30–20.00, no places, private yoga and private prenatal by appointment, oliviajoga.online, readable.
Second wish (on the first version): „ugyanaz a kép legyen a háttér … inkább csinálj te hasonlót, finomítsunk rajta”.

So this is her own picture: the background is her ORAREND.png with the old text taken out (orarend_bg.py), at her
size (1151 x 2048, 9:16), and the new text sits where hers did, in her type: white Montserrat ÓRAREND with a pink
italic script over it, the lines in dark capitals with a thin divider, the times in white with an italic class name,
the pink button at the bottom. Measured on her original (pixels): title x 57–1090, baseline 346; the script 118–276;
first block 740 / 805 / 892; second block 1151 / 1215 / 1299 / 1383; the button 1814–1877.

    python3 -I orarend_bg.py ORAREND.png <png folder>/orarend-hatter.png
    python3 -I orarend.py <png folder>/orarend-hatter.png <html folder>
    node cover.js <html folder> <png folder>
"""
import os
import sys

W, H = 1151, 2048
PINK = '#E539A3'
INK = '#1D1D1D'


def main():
    bg, out = sys.argv[1], sys.argv[2]
    os.makedirs(out, exist_ok=True)
    html = f'''<!doctype html><html lang="hu" data-w="{W}" data-h="{H}"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Cormorant+Garamond:ital,wght@1,500;1,600&display=swap">
<style>
*{{margin:0;box-sizing:border-box}} html,body{{width:{W}px;height:{H}px;overflow:hidden}}
body{{position:relative;font-family:Montserrat,sans-serif;background:url("file://{bg}") 0 0/{W}px {H}px no-repeat}}
.line{{position:absolute;left:0;right:0;text-align:center;white-space:nowrap}}
.title{{top:150px;height:200px}}
.title b{{position:absolute;left:57px;bottom:0;font:800 200px/1 Montserrat;letter-spacing:-.012em;color:#fff;
  text-shadow:3px 6px 14px rgba(40,15,25,.32)}}
.title i{{position:absolute;right:120px;top:-48px;font:italic 600 142px/1 "Cormorant Garamond",serif;color:{PINK};
  text-shadow:2px 4px 10px rgba(40,15,25,.22)}}
.h{{font:800 64px/1 Montserrat;color:{INK};letter-spacing:-.005em;text-shadow:0 3px 8px rgba(255,235,242,.55)}}
.h span{{display:inline-block;width:3px;height:60px;margin:0 30px;background:{INK};vertical-align:-6px}}
.s{{font:600 40px/1 Montserrat;color:{INK};letter-spacing:-.02em;text-transform:uppercase;text-shadow:0 2px 8px rgba(255,235,242,.55)}}
.t{{font:700 64px/1 Montserrat;color:#fff;text-shadow:0 2px 3px rgba(60,20,40,.45),0 4px 14px rgba(60,20,40,.4)}}
.t em{{font:italic 500 74px/1 "Cormorant Garamond",serif;margin-left:14px}}
.pill{{position:absolute;left:50%;top:1814px;transform:translateX(-50%);height:63px;padding:0 26px;border-radius:14px;
  background:{PINK};color:#fff;font:500 40px/63px Montserrat;letter-spacing:-.005em;white-space:nowrap;
  box-shadow:0 6px 18px rgba(90,20,60,.22)}}
</style></head><body>
<div class="line title"><b id="t">ÓRAREND</b><i>online jóga</i></div>

<p class="line h" style="top:740px">CSOPORTOS<span></span>ÉLŐ ONLINE</p>
<p class="line s" style="top:808px">(első óra: december 16.)</p>
<p class="line t" style="top:892px">• szerda 18.30–20.00<em>hatha flow</em></p>

<p class="line h" style="top:1151px">PRIVÁT ÓRÁK<span></span>ONLINE</p>
<p class="line s" style="top:1219px">(egyénileg egyeztetett időpontban)</p>
<p class="line t" style="top:1299px">• privát<em>jóga</em></p>
<p class="line t" style="top:1383px">• privát<em>kismamatorna</em></p>

<p class="pill">BEJELENTKEZÉS: OLIVIAJOGA.ONLINE</p>
<script>
// ÓRAREND as wide as hers (x 57–1090)
document.fonts.ready.then(() => {{
  const t = document.getElementById('t'); const want = 1090 - 57;
  t.style.fontSize = (200 * want / t.getBoundingClientRect().width) + 'px';
}});
</script>
</body></html>'''
    open(os.path.join(out, 'online-joga-orarend.html'), 'w', encoding='utf-8').write(html)
    print('online-joga-orarend')


if __name__ == '__main__':
    main()
