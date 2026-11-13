"""The background of Olivia's own timetable (ORAREND.png, portal chat 2026-11-13), without its old text: „ugyanaz a kép
legyen a háttér”. Her raw photo is not in the Drive or the portal, so the old lettering is taken out of her picture:
the white title, the pink „jóga”, the dark place lines, the white times (each with its soft shadow) and the pink
button. The photo under it is blurred anyway, so the holes are filled from their surroundings (normalized
convolution, coarse to fine). The pink veil with the wavy edges stays as she made it.

    python3 -I orarend_bg.py ORAREND.png orarend-hatter.png      (needs numpy + opencv-python-headless)
"""
import sys

import cv2
import numpy as np


def main():
    src, out = sys.argv[1], sys.argv[2]
    bgr = cv2.imread(src)
    h, w = bgr.shape[:2]
    if (w, h) != (1151, 2048):
        sys.exit(f'{src}: {w}x{h} – the boxes below are measured on her 1151 x 2048 original')
    rgb = bgr[:, :, ::-1].astype(int)
    r, g = rgb[..., 0], rgb[..., 1]
    mx, mn = rgb.max(-1), rgb.min(-1)
    white = (mn > 222) & (mx - mn < 30)
    dark = mx < 95
    pink = (r - g > 90) & (r > 170)
    every = np.ones((h, w), bool)

    def region(x0, y0, x1, y1, sel, grow):
        m = np.zeros((h, w), np.uint8)
        box = np.zeros((h, w), bool)
        box[y0:y1, x0:x1] = True
        m[box & sel] = 255
        return cv2.dilate(m, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (grow, grow)))

    def fill(img, mask, sigmas, feather):
        valid = (mask == 0).astype(np.float32)
        f = np.zeros_like(img)
        for i, s in enumerate(sigmas):
            num = cv2.GaussianBlur(img * valid[..., None], (0, 0), s)
            den = cv2.GaussianBlur(valid, (0, 0), s)
            ok = den > (0.02 if i == 0 else 0.12)
            f[ok] = (num / np.maximum(den, 1e-5)[..., None])[ok]
        f = cv2.GaussianBlur(f, (0, 0), 3)
        a = np.clip(cv2.GaussianBlur(mask.astype(np.float32) / 255, (0, 0), feather)[..., None] * 1.15, 0, 1)
        return img * (1 - a) + f * a

    img = bgr.astype(np.float32)
    small = np.maximum.reduce([
        region(180, 735, 975, 975, white | dark, 31),      # 21. kerület | Korall, address, Tuesday
        region(195, 1145, 965, 1465, white | dark, 31),    # 18. kerület | Prána, address, Wednesdays
        region(196, 1792, 956, 1898, every, 45),           # the pink button
    ])
    img = fill(img, small, (192, 96, 48, 24, 12, 6), 6)
    title = region(36, 92, 1116, 372, white | pink, 39)    # ÓRAREND + jóga, with the wide soft shadow
    img = fill(img, title, (128, 64, 32, 16), 8)
    cv2.imwrite(out, np.clip(img, 0, 255).astype(np.uint8))
    print(out)


if __name__ == '__main__':
    main()
