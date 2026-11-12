"""Facebook images, step 1: the photos upright (EXIF) and at most 2400 px.

    python3 -I prep.py <downloaded photos> <prepared folder>
"""
import sys, os
from PIL import Image, ImageOps
src, out = sys.argv[1], sys.argv[2]
for f in sorted(os.listdir(src)):
    im = Image.open(os.path.join(src, f)); im = ImageOps.exif_transpose(im).convert('RGB')
    im.thumbnail((2400, 2400), Image.LANCZOS)
    im.save(os.path.join(out, f), quality=90)
    print(f, im.size)
