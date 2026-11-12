// Facebook images, step 3: each page as a 1080 x 1350 JPEG (node shoot.js <html folder> <jpg folder>).
const { chromium } = require('playwright');
const fs = require('fs'); const path = require('path');
(async () => {
  const [dir, out] = process.argv.slice(2);
  const b = await chromium.launch({ args: ['--proxy-server=' + process.env.HTTPS_PROXY, '--allow-file-access-from-files'] });
  const p = await b.newPage({ viewport: { width: 1080, height: 1350 }, deviceScaleFactor: 1 });
  for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.html')).sort()) {
    await p.goto('file://' + path.join(dir, f), { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(out, f.replace('.html', '.jpg')), type: 'jpeg', quality: 92 });
    console.log(f);
  }
  await b.close();
})();
