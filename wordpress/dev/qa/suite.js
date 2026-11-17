// oliviajoga.online – every page on every device: loads, errors, overflow, images, tap targets, every folding title
// (does the tapped one stay on the screen and change state), the menu, the calendar, the consent banner, links.
// Áron, 17 November: „ezeket a gombokat kurvára teszteld … csinálj egy 1000-1200 tesztet minden eszközről”.
// Read-only: no form is sent, nothing is booked or bought.
// usage: node suite.js <base> <out.json> [device indexes, e.g. 0,1,2]
const pw = require('playwright');
const fs = require('fs');

const D = pw.devices;
const DEVICES = [
  { name: 'iPhone SE', engine: 'webkit', opt: D['iPhone SE'] },
  { name: 'iPhone 13', engine: 'webkit', opt: D['iPhone 13'] },
  { name: 'iPhone 15 Pro Max', engine: 'webkit', opt: D['iPhone 15 Pro Max'] },
  { name: 'iPad Mini', engine: 'webkit', opt: D['iPad Mini'] },
  { name: 'iPad Pro 11', engine: 'webkit', opt: D['iPad Pro 11'] },
  { name: 'Pixel 7', engine: 'chromium', opt: D['Pixel 7'] },
  { name: 'Galaxy S9+', engine: 'chromium', opt: D['Galaxy S9+'] },
  { name: 'Galaxy Tab S4', engine: 'chromium', opt: D['Galaxy Tab S4'] },
  { name: 'Desktop Chrome 1366', engine: 'chromium', opt: { viewport: { width: 1366, height: 768 } } },
  { name: 'Desktop Safari 1440', engine: 'webkit', opt: { viewport: { width: 1440, height: 900 } } },
  { name: 'Desktop Firefox 1920', engine: 'firefox', opt: { viewport: { width: 1920, height: 1080 } } },
];
const PAGES = ['/', '/online-orak/', '/online-orak/csoportos-online-flow-joga/', '/online-orak/egyeni-online-joga/',
  '/online-orak/online-kismamatorna/', '/online-orak/online-stretching-es-mobilizacio/', '/online-orak/relaxacio-es-legzes/',
  '/bejelentkezes-es-arak/', '/rolam/', '/gyakori-kerdesek/', '/jogarol/', '/hatha-jogaora-elemei-vinyasza/', '/kapcsolat/',
  '/ajandekutalvany/', '/foglalas/', '/fiokom/', '/aszf/', '/impresszum/', '/adatvedelem/'];
// the folding titles of the site
const FOLDS = 'details:not(.is-static) > summary, .price-group__toggle, .oj-fold:not([tabindex="-1"]), .sub-toggle';

(async () => {
  const [base, out, pick] = process.argv.slice(2);
  const which = pick ? pick.split(',').map(Number) : DEVICES.map((_, i) => i);
  const results = [];
  const links = new Set();
  const add = (device, page, test, ok, detail = '') => results.push({ device, page, test, ok: !!ok, detail: String(detail).slice(0, 300) });
  for (const di of which) {
    const dev = DEVICES[di];
    // Firefox keeps its own certificate store: a profile with the machine's trusted certificates (no check is turned off)
    let browser = null, ctx;
    if (dev.engine === 'firefox' && fs.existsSync(require('path').join(__dirname, 'ffprofile', 'cert9.db'))) {
      const dir = require('path').join(__dirname, 'ffprofile-run-' + process.pid);
      require('fs').mkdirSync(dir, { recursive: true });
      for (const f of ['cert9.db', 'key4.db', 'pkcs11.txt']) require('fs').copyFileSync(require('path').join(__dirname, 'ffprofile', f), require('path').join(dir, f));
      ctx = await pw.firefox.launchPersistentContext(dir, { ...dev.opt, locale: 'hu-HU' });
    } else {
      browser = await pw[dev.engine].launch();
      ctx = await browser.newContext({ ...dev.opt, locale: 'hu-HU' });
    }
    const touch = !!dev.opt.hasTouch;
    const mobile = (dev.opt.viewport || {}).width < 760;
    let consentDone = false;
    for (const path of PAGES) {
      const page = await ctx.newPage();
      const errs = [];
      const bad = [];
      page.on('pageerror', (e) => errs.push(e.message));
      page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/i.test(m.text())) errs.push('console: ' + m.text()); });
      page.on('response', (r) => { const u = r.url(); if (u.startsWith(base) && r.status() >= 400) bad.push(r.status() + ' ' + u); });
      page.on('requestfailed', (r) => { const u = r.url(); const why = (r.failure() || {}).errorText || ''; if (u.startsWith(base) && !/beacon|collect/.test(u) && !/ABORTED|cancelled|canceled/i.test(why)) bad.push('failed ' + (r.failure() || {}).errorText + ' ' + u); });
      const T = (test, ok, detail) => add(dev.name, path, test, ok, detail);
      let resp;
      try {
        resp = await page.goto(base + path, { waitUntil: 'load', timeout: 60000 });
      } catch (e) {
        T('loads', false, e.message);
        await page.close();
        continue;
      }
      T('loads (HTTP ' + (resp ? resp.status() : '?') + ')', resp && resp.status() < 400, resp && resp.status());
      await page.waitForTimeout(500);

      // the consent banner: once per device, with its own buttons
      if (!consentDone) {
        const banner = await page.$('.oy-consent');
        const vis = banner && await banner.isVisible();
        if (vis) {
          const btns = await banner.$$('button');
          T('consent: banner has buttons', btns.length >= 2, btns.length);
          const sizes = await Promise.all(btns.map((b) => b.boundingBox()));
          T('consent: buttons big enough to tap', sizes.every((s) => s && s.height >= 40), JSON.stringify(sizes.map((s) => s && Math.round(s.height))));
          const ess = btns[btns.length - 1];
          try { await (touch ? ess.tap() : ess.click()); } catch (e) {}
          await page.waitForTimeout(400);
          T('consent: a choice closes the banner', !(await banner.isVisible().catch(() => false)));
        }
        consentDone = true;
      }
      await page.evaluate(() => { const c = document.querySelector('.oy-consent'); if (c && c.getBoundingClientRect().height > 0) c.remove(); }); // a hidden one stays (the settings link opens it)

      // read the page through (lazy images, reveal animations)
      await page.evaluate(async () => {
        const step = Math.max(300, innerHeight * 0.8);
        for (let y = 0; y < document.documentElement.scrollHeight; y += step) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 90)); }
        window.scrollTo(0, 0);
      });
      await page.waitForTimeout(600);

      // no sideways scrolling
      const ov = await page.evaluate(() => {
        const w = document.documentElement.clientWidth;
        const sw = document.documentElement.scrollWidth;
        let worst = null;
        if (sw > w + 1) {
          for (const el of document.querySelectorAll('body *')) {
            const r = el.getBoundingClientRect();
            if (r.right > w + 1 && r.width > 0 && getComputedStyle(el).position !== 'fixed') { worst = (el.className && el.className.baseVal === undefined ? el.className : el.tagName) + ' →' + Math.round(r.right); break; }
          }
        }
        return { w, sw, worst };
      });
      T('no sideways scroll', ov.sw <= ov.w + 1, JSON.stringify(ov));

      // images: the lazy ones loaded first (as a reader scrolling past them would), then checked
      await page.evaluate(async () => {
        const lazy = [...document.images].filter((i) => !i.complete || !i.naturalWidth);
        lazy.forEach((i) => { i.loading = 'eager'; });
        await Promise.race([Promise.all(lazy.map((i) => i.decode ? i.decode().catch(() => {}) : null)), new Promise((r) => setTimeout(r, 8000))]);
      });
      const imgs = await page.evaluate(() => [...document.images].filter((i) => i.getBoundingClientRect().width > 0).map((i) => ({ src: i.currentSrc || i.src, ok: i.complete && i.naturalWidth > 0, alt: i.hasAttribute('alt') })));
      const broken = imgs.filter((i) => !i.ok);
      T('images load (' + imgs.length + ')', !broken.length, broken.map((i) => i.src).join(' '));
      T('images have alt text', imgs.every((i) => i.alt), imgs.filter((i) => !i.alt).map((i) => i.src).join(' '));

      // tap targets on touch screens: buttons and button-like links at least 40 px high (inline text links are exempt)
      if (touch) {
        const small = await page.evaluate(() => [...document.querySelectorAll('button, .btn, summary, .session__book, .price-group__toggle, .oj-fold, [role=button], input[type=submit], .oys-cal__arrow, .nav-toggle, [data-nav-toggle]')]
          .filter((el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[hidden], .screen-reader-text, .sr-only, details.is-static') && el.getAttribute('tabindex') !== '-1'; })
          .filter((el) => { const r = el.getBoundingClientRect(); return r.height < 40 || r.width < 40; })
          .map((el) => (el.textContent.trim() || el.getAttribute('aria-label') || el.className).slice(0, 40) + ' ' + Math.round(el.getBoundingClientRect().width) + 'x' + Math.round(el.getBoundingClientRect().height)));
        T('tap targets ≥ 40 px', !small.length, small.slice(0, 8).join(' | '));
      }

      // every folding title: tapped where a reader would be (at 60% of the screen), it stays on the screen and opens/closes
      const n = await page.$$eval(FOLDS, (els) => els.filter((e) => e.getBoundingClientRect().height > 0).length);
      for (let i = 0; i < Math.min(n, 14); i++) {
        const el = (await page.$$(FOLDS)).filter(Boolean)[i];
        if (!el) break;
        const vis = await el.isVisible().catch(() => false);
        if (!vis) continue;
        const label = (await el.evaluate((e) => (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 45))) || 'fold ' + i;
        const state = () => el.evaluate((e) => { const d = e.closest('details'); if (e.tagName === 'SUMMARY' && d) return d.open ? 'open' : 'closed'; const a = e.getAttribute('aria-expanded'); if (a) return a; const li = e.closest('li, .price-group'); return li && li.classList.contains('is-open') ? 'open' : 'closed'; });
        await el.evaluate((e) => { const r = e.getBoundingClientRect(); const root = document.documentElement; const was = root.style.scrollBehavior; root.style.scrollBehavior = 'auto'; window.scrollTo(0, window.scrollY + r.top - innerHeight * 0.6); root.style.scrollBehavior = was; });
        await page.waitForTimeout(250);
        const s0 = await state();
        const top0 = await el.evaluate((e) => e.getBoundingClientRect().top);
        try { await (touch ? el.tap({ timeout: 4000 }) : el.click({ timeout: 4000 })); } catch (e) { T('fold: ' + label, false, 'cannot tap: ' + e.message.split('\n')[0]); continue; }
        await page.waitForTimeout(700);
        const s1 = await state();
        const box = await el.evaluate((e) => { const r = e.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), h: innerHeight }; });
        const onScreen = box.top >= -2 && box.bottom <= box.h + 2;
        const moved = Math.round(box.top - top0);
        const desktopStatic = s0 === s1 && /is-static/.test(await el.evaluate((e) => (e.closest('details') || {}).className || ''));
        T('fold: ' + label, (s0 !== s1 || desktopStatic) && onScreen, `state ${s0}→${s1}, title ${Math.round(top0)}→${box.top} (moved ${moved}) of ${box.h}`);
      }

      // the menu on a phone/tablet
      const tog = await page.$('[data-nav-toggle]');
      if (tog && await tog.isVisible().catch(() => false)) {
        try {
          await (touch ? tog.tap() : tog.click());
          await page.waitForTimeout(500);
          const open = await page.evaluate(() => document.body.classList.contains('nav-open'));
          const navLinks = await page.evaluate(() => [...document.querySelectorAll('.nav a, nav a, .menu a')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.top < innerHeight && r.bottom > 0; }).length);
          T('menu opens with its links', open && navLinks >= 4, `open=${open} links=${navLinks}`);
          await page.keyboard.press('Escape');
          await page.waitForTimeout(400);
          T('menu closes', !(await page.evaluate(() => document.body.classList.contains('nav-open'))));
        } catch (e) { T('menu opens with its links', false, e.message.split('\n')[0]); }
      }

      // the calendar: paging and a day
      if (await page.$('[data-oys-cal]')) {
        const vis = () => page.$$eval('.oys-cal__month', (ms) => ms.filter((m) => !m.hidden).map((m) => m.querySelector('.oys-cal__title').textContent).join());
        const m0 = await vis();
        const next = await page.$('.oys-cal__month:not([hidden]) .oys-cal__arrow--next:not([disabled])');
        if (next) {
          await next.scrollIntoViewIfNeeded();
          await (touch ? next.tap() : next.click());
          await page.waitForTimeout(400);
          const m1 = await vis();
          T('calendar: next month', m1 && m1 !== m0, m0 + ' → ' + m1);
          const prev = await page.$('.oys-cal__month:not([hidden]) .oys-cal__arrow--prev:not([disabled])');
          if (prev) { await (touch ? prev.tap() : prev.click()); await page.waitForTimeout(400); T('calendar: previous month', (await vis()) === m0, await vis()); }
        }
        const day = await page.$('.oys-cal__month:not([hidden]) .oys-cal__day[data-day]');
        if (day && mobile) {
          await day.scrollIntoViewIfNeeded();
          await (touch ? day.tap() : day.click());
          await page.waitForTimeout(700);
          const hl = await page.$('.oys-cal__item.is-hl');
          const inView = hl && await hl.evaluate((e) => { const r = e.getBoundingClientRect(); return r.top >= -2 && r.bottom <= innerHeight + 2; });
          T('calendar: a day shows its class', !!hl && inView, hl ? 'in view: ' + inView : 'no highlighted line');
        }
      }

      // links on the page: a real address; same-page anchors land somewhere; legal and social ones open in a new window
      const ls = await page.evaluate(() => [...document.querySelectorAll('a[href]')].filter((a) => !a.closest('#wpadminbar')).map((a) => ({ href: a.getAttribute('href'), abs: a.href, blank: a.target === '_blank', text: (a.textContent || a.getAttribute('aria-label') || '').trim().slice(0, 30) })));
      const empty = ls.filter((l) => !l.href || l.href === '#' || /^javascript:/i.test(l.href));
      T('links have a real address (' + ls.length + ')', !empty.length, empty.map((l) => l.text).join(' | '));
      const anchors = await page.evaluate(() => [...document.querySelectorAll('a[href^="#"]')].map((a) => a.getAttribute('href')).filter((h) => h.length > 1 && h !== '#cookie-settings' && !document.getElementById(decodeURIComponent(h.slice(1)))));
      T('same-page links have a target', !anchors.length, anchors.join(' '));
      const wantBlank = ls.filter((l) => /aronritok|facebook|instagram|youtube|tiktok|linkedin|\/aszf\/|\/impresszum\/|\/adatvedelem\//.test(l.abs) && !l.abs.startsWith(base + path));
      const notBlank = wantBlank.filter((l) => !l.blank);
      if (wantBlank.length) T('legal & social links open in a new window', !notBlank.length, notBlank.map((l) => l.abs).join(' '));
      ls.forEach((l) => { if (/^https?:/.test(l.abs)) links.add(l.abs.split('#')[0]); });

      // the „Süti-beállítások” link opens the banner again (on the privacy page)
      if (path === '/adatvedelem/') {
        const cs = await page.$('a[href="#cookie-settings"]');
        if (cs) {
          await cs.scrollIntoViewIfNeeded();
          await (touch ? cs.tap() : cs.click());
          await page.waitForTimeout(500);
          const b = await page.$('.oy-consent');
          T('cookie settings link opens the banner', b && await b.isVisible(), b ? 'shown' : 'no banner');
        }
      }
      T('no script errors', !errs.length, errs.slice(0, 3).join(' | '));
      T('no broken files', !bad.length, bad.slice(0, 4).join(' | '));
      await page.close();
    }
    await (browser ? browser.close() : ctx.close());
    process.stderr.write(dev.name + ' done\n');
  }
  fs.writeFileSync(out, JSON.stringify({ results, links: [...links] }, null, 1));
})();
