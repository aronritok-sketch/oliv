// Opens each page of the old Google Site like a visitor and saves the photos the browser loads.
const { chromium } = require('playwright');
const fs = require('fs');
const crypto = require('crypto');
(async () => {
  const [urlsFile, outDir] = process.argv.slice(2);
  const urls = fs.readFileSync(urlsFile, 'utf8').split('\n').filter(Boolean);
  const browser = await chromium.launch({ args: ['--proxy-server=' + process.env.HTTPS_PROXY] });
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const saved = {};
  page.on('response', async (r) => {
    const u = r.url();
    if (!u.includes('sitesv-images-rt')) return;
    const ct = r.headers()['content-type'] || '';
    if (!ct.startsWith('image/')) return;
    const key = u.replace(/=w\d+.*$/, '').replace(/=s\d+.*$/, '');
    try {
      const body = await r.body();
      if (!saved[key] || saved[key].size < body.length) {
        const name = crypto.createHash('sha1').update(key).digest('hex').slice(0, 12) + (ct.includes('png') ? '.png' : ct.includes('webp') ? '.webp' : '.jpg');
        fs.writeFileSync(`${outDir}/${name}`, body);
        saved[key] = { name, size: body.length, ct };
      }
    } catch (e) {}
  });
  for (const u of urls) {
    try {
      await page.goto(u, { waitUntil: 'networkidle', timeout: 90000 });
      for (let y = 0; y < 30; y++) { await page.mouse.wheel(0, 900); await page.waitForTimeout(250); }
      await page.waitForTimeout(1500);
      const name = (u.replace('https://sites.google.com/view/oliviajoga', '').replace(/^\//, '') || '_root');
      fs.mkdirSync(`${outDir}/dom`, { recursive: true });
      fs.writeFileSync(`${outDir}/dom/${name}.html`, await page.content());
      console.log('OK', decodeURIComponent(u));
    } catch (e) { console.log('ERR', u, e.message.split('\n')[0]); }
  }
  fs.writeFileSync(`${outDir}/index.json`, JSON.stringify(saved, null, 1));
  console.log(Object.keys(saved).length, 'images');
  await browser.close();
})();
