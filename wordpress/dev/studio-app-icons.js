// The Studio app icon (Hub on the phone): Evergreen square, Olívia's wordmark (logo-online.svg) in cream with its
// magenta wave, „stúdió” under it. Renders icon-512, icon-192, apple-touch (180, no transparency) and a maskable 512
// (everything inside the central safe zone).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
(async () => {
  const dir = process.argv[2], out = process.argv[3];
  const svg = fs.readFileSync(path.join(dir, 'logo-online.svg'), 'utf8').replace('<svg ', '<svg class="logo" ');
  const page = (scale) => `<!doctype html><html><head><style>
    @import url('https://fonts.googleapis.com/css2?family=Quicksand:wght@700&display=block');
    html,body{margin:0;width:512px;height:512px;background:#002E2C;}
    .wrap{width:512px;height:512px;display:grid;place-items:center;}
    .in{display:grid;justify-items:center;gap:${18 * scale}px;transform:translate(${-4 * scale}px, ${8 * scale}px)}
    .logo{width:${410 * scale}px;height:auto;color:#F6F1E7;display:block}
    .t{font:700 ${34 * scale}px/1 'Quicksand',system-ui,sans-serif;letter-spacing:.32em;text-transform:uppercase;color:#FFD23F;padding-left:.32em}
  </style></head><body><div class="wrap"><div class="in">${svg}</div></div></body></html>`;
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 512, height: 512 }, deviceScaleFactor: 1 });
  for (const [name, scale] of [['icon', 1], ['icon-maskable', 0.78]]) {
    await p.setContent(page(scale), { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(out, name + '-512.png') });
  }
  await b.close();
})();
