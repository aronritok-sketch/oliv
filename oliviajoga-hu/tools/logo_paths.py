#!/usr/bin/env python3
# Text → SVG path data from a font file (advance widths only, no kerning or shaping).
import sys
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.pens.boundsPen import BoundsPen

def text_path(font_file, text, size, tracking=0.0, x0=0.0, y0=0.0):
    f = TTFont(font_file)
    gs = f.getGlyphSet(); cmap = f.getBestCmap(); upm = f['head'].unitsPerEm
    sc = size / upm
    pen = SVGPathPen(gs)
    bp = BoundsPen(gs)
    x = 0.0
    for ch in text:
        g = cmap[ord(ch)]
        t = TransformPen(pen, (sc, 0, 0, -sc, x0 + x, y0))
        gs[g].draw(t)
        gs[g].draw(TransformPen(bp, (sc, 0, 0, -sc, x0 + x, y0)))
        x += gs[g].width * sc + tracking
    return pen.getCommands(), bp.bounds, x - tracking

if __name__ == '__main__':
    d, b, w = text_path(sys.argv[1], sys.argv[2], float(sys.argv[3]))
    print(b, w, len(d))
