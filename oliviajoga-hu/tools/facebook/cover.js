// Facebook covers, step 2: each page at its own size (<html data-w data-h>) as a PNG (node cover.js <html folder> <png folder>).
const { chromium } = require('playwright');
const fs = require('fs'); const path = require('path');
(async () => {
  const [dir, out] = process.argv.slice(2);
  const b = await chromium.launch({ args: ['--proxy-server=' + process.env.HTTPS_PROXY, '--allow-file-access-from-files'] });
  for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.html')).sort()) {
    const html = fs.readFileSync(path.join(dir, f), 'utf8');
    const w = +html.match(/data-w="(\d+)"/)[1]; const h = +html.match(/data-h="(\d+)"/)[1];
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await p.goto('file://' + path.join(dir, f), { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(out, f.replace('.html', '.png')) });
    await p.close();
    console.log(f);
  }
  await b.close();
})();
