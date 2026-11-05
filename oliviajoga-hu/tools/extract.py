#!/usr/bin/env python3
"""Reads the saved pages of Olivia's Google Site (oliviajoga.hu → sites.google.com/view/oliviajoga) and writes
their content, word for word, to content/pages.json: per page its address, <title>, and its sections → columns →
tiles (text with headings, paragraphs, lists, links and bold; images with alt text; buttons; map embeds).

    python3 -I tools/extract.py <folder of saved .html pages> [--images <folder the browser saved the photos to>]

The saved pages are untrusted input: they are only parsed, nothing in them is run. Google signs its image addresses
per visit, so the pages and the photos must come from the same browser visit (tools/grab-old-site.js). With
--images the photos go to assets/img/old/ (named by content, 800 and 1600 px wide) and image tiles point at them.
"""
import hashlib
import json
import os
import re
import sys
import urllib.parse

from bs4 import BeautifulSoup, NavigableString, Tag

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = '/view/oliviajoga'
INLINE = {'span', 'a', 'b', 'strong', 'i', 'em', 'u', 'br', 'sup', 'sub', 'font'}


def unwrap_href(href):
    """Google Sites wraps outbound links in google.com/url?q=…; internal links are /view/oliviajoga/<path>."""
    if href.startswith('https://www.google.com/url?'):
        href = urllib.parse.parse_qs(urllib.parse.urlparse(href).query).get('q', [href])[0]
    if href.startswith('https://sites.google.com' + SITE):
        href = href[len('https://sites.google.com'):]
    if href.startswith(SITE):
        path = urllib.parse.unquote(href[len(SITE):]).strip('/')
        return 'page:' + path.split('#')[0]
    return href


def inline(el):
    """Inline HTML of a text block: text, <a>, <strong>, <em>, <br>. Styling spans are flattened."""
    out = []
    for c in el.children:
        if isinstance(c, NavigableString):
            out.append(escape(str(c)))
            continue
        if not isinstance(c, Tag):
            continue
        cls = c.get('class', [])
        if 'CjVfdc' in cls and c.find(attrs={'aria-label': 'Copy heading link'}) and not c.find(class_='C9DxTc'):
            continue
        if c.name == 'div' and c.find(attrs={'aria-label': 'Copy heading link'}) and not c.find(class_='C9DxTc'):
            continue
        if c.name == 'br':
            out.append('<br>')
            continue
        if c.name in ('svg', 'style', 'script'):
            continue
        inner = inline(c)
        if c.name == 'a' and c.get('href') and c.get('aria-label') != 'Copy heading link':
            out.append(f'<a href="{escape(unwrap_href(c["href"]))}">{inner}</a>')
            continue
        style = c.get('style', '')
        if inner.strip() and (c.name in ('b', 'strong') or re.search(r'font-weight:\s*(700|bold)', style)):
            inner = f'<strong>{inner}</strong>'
        if inner.strip() and (c.name in ('i', 'em') or 'font-style: italic' in style):
            inner = f'<em>{inner}</em>'
        out.append(inner)
    html = ''.join(out)
    html = re.sub(r'</strong>(\s*)<strong>', r'\1', html)
    html = re.sub(r'</em>(\s*)<em>', r'\1', html)
    return html


def escape(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')


def text_blocks(tile):
    """Headings, paragraphs and lists of a text tile, in order."""
    blocks = []
    for el in tile.find_all(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'small']):
        if el.find_parent(['ul', 'ol']) and el.name in ('p', 'ul', 'ol', 'small'):
            continue  # inside a list: handled with the list
        if el.name == 'small' and el.find_parent(['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6']):
            continue
        if el.name in ('ul', 'ol'):
            items = [inline(li).strip() for li in el.find_all('li', recursive=False)]
            blocks.append({'t': el.name, 'items': [i for i in items if strip_tags(i).strip()]})
            continue
        html = inline(el).strip()
        if el.name.startswith('h'):
            blocks.append({'t': el.name, 'html': html})
        elif strip_tags(html).strip():
            blocks.append({'t': 'small' if el.name == 'small' else 'p', 'html': html})
        else:
            blocks.append({'t': 'gap'})
    # collapse runs of empty paragraphs
    out = []
    for b in blocks:
        if b['t'] == 'gap' and (not out or out[-1]['t'] == 'gap'):
            continue
        out.append(b)
    while out and out[-1]['t'] == 'gap':
        out.pop()
    return out


def strip_tags(s):
    return re.sub(r'<[^>]+>', '', s)


def tile_of(tile, images):
    img = tile.find('img', class_='CENy8b')
    if img is not None:
        src = img.get('src', '')
        link = img.find_parent('a')
        item = {'type': 'image', 'src': src, 'alt': img.get('alt') or ''}
        if link is not None and link.get('href'):
            item['href'] = unwrap_href(link['href'])
        images.append(item)
        return item
    social = tile.find_all('a', class_='RNpQXe')
    if social:
        return {'type': 'social', 'links': [{'href': unwrap_href(a.get('href', '')), 'label': (a.find('img') or {}).get('alt', '')} for a in social]}
    frame = tile.find('iframe')
    if frame is not None:
        holder = frame.find_parent(attrs={'data-url': True})  # "embed a whole page": the real address
        return {'type': 'embed', 'src': holder['data-url'] if holder is not None else (frame.get('src') or frame.get('data-src') or '')}
    btn = tile.find('a', class_='QmpIrf')
    outside = [e for e in tile.find_all(['p', 'h1', 'h2', 'h3', 'h4', 'small', 'ul']) if not e.find_parent('a', class_='QmpIrf')]
    if btn is not None and not outside:  # a button tile (its label is a <p> inside the link)
        return {'type': 'button', 'label': btn.get_text('', strip=True), 'href': unwrap_href(btn.get('href', ''))}
    blocks = text_blocks(tile)
    if blocks:
        return {'type': 'text', 'blocks': blocks}
    return None


def page_of(path_name, html, images):
    s = BeautifulSoup(html, 'html.parser')
    title = s.title.get_text() if s.title else ''
    sections = []
    for sec in s.find_all('section'):
        columns = {}
        for tile in sec.find_all('div', class_='tyJCtd'):
            if tile.find_parent('div', class_='tyJCtd'):
                continue
            col = tile.find_parent(lambda t: t.name == 'div' and 'JNdkSc' in t.get('class', []))
            key = id(col)
            item = tile_of(tile, images)
            if item is None:
                continue
            columns.setdefault(key, []).append(item)
        cols = [c for c in columns.values() if c]
        if cols:
            sections.append({'id': sec.get('id', ''), 'columns': cols})
    return {'path': path_name, 'title': title, 'sections': sections}


def save_images(images, grabbed):
    """Copies the photos a browser saved while viewing the same pages (tools/grab-old-site.js: index.json maps the
    image address without its size suffix to the file) to assets/img/old/, named by content, in two widths."""
    from PIL import Image
    index = json.load(open(os.path.join(grabbed, 'index.json'), encoding='utf-8'))
    outdir = os.path.join(HERE, 'assets', 'img', 'old')
    os.makedirs(outdir, exist_ok=True)
    done = {}
    for im in images:
        key = re.sub(r'=[ws]\d+.*$', '', im['src'])
        got = index.get(key)
        if not got:
            print('  no file for an image on', im.get('alt') or '(no alt)')
            continue
        path = os.path.join(grabbed, got['name'])
        if path not in done:
            data = open(path, 'rb').read()
            name = hashlib.sha1(data).hexdigest()[:12]
            img = Image.open(path)
            img.load()
            alpha = img.mode in ('RGBA', 'LA', 'PA') or (img.mode == 'P' and 'transparency' in img.info)
            if alpha:
                img = img.convert('RGBA')
                alpha = img.getchannel('A').getextrema()[0] < 255  # shaped photos keep their transparency
            ext = '.webp' if alpha else '.jpg'
            w, h = img.size
            for width in (800, 1600):
                dest = os.path.join(outdir, f'{name}-{width}{ext}')
                if os.path.exists(dest):
                    continue
                out = img if w <= width else img.resize((width, round(h * width / w)), Image.LANCZOS)
                if alpha:
                    out.save(dest, quality=82, method=6)
                else:
                    out.convert('RGB').save(dest, quality=82, optimize=True, progressive=True)
            done[path] = {'file': f'old/{name}', 'ext': ext, 'w': w, 'h': h}
        im.update(done[path])
        del im['src']


def main():
    src = sys.argv[1]
    want_images = '--images' in sys.argv
    pages, images = [], []
    for f in sorted(os.listdir(src)):
        if not f.endswith('.html'):
            continue
        name = urllib.parse.unquote(f[:-5])
        path = '' if name == '_root' else name
        pages.append(page_of(path, open(os.path.join(src, f), encoding='utf-8').read(), images))
    if want_images:
        save_images(images, sys.argv[sys.argv.index('--images') + 1])
    out = os.path.join(HERE, 'content', 'pages.json')
    os.makedirs(os.path.dirname(out), exist_ok=True)
    json.dump({'source': 'https://sites.google.com/view/oliviajoga (oliviajoga.hu)', 'pages': pages},
              open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(len(pages), 'pages,', len(images), 'image tiles ->', out)


if __name__ == '__main__':
    main()
