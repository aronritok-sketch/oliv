// Sums up the suite's result files (and checks every link found once, from here): passed / failed, failures grouped.
// usage: node report.js <base> <result1.json> [result2.json …]
const fs = require('fs');
(async () => {
  const [base, ...files] = process.argv.slice(2);
  let results = [];
  const links = new Set();
  for (const f of files) { const d = JSON.parse(fs.readFileSync(f, 'utf8')); results = results.concat(d.results); d.links.forEach((l) => links.add(l)); }
  // every link once: does it answer?
  const list = [...links].filter((l) => !/^mailto:|^tel:/.test(l));
  const check = async (u) => {
    for (let i = 0; i < 2; i++) {
      try {
        const r = await fetch(u, { method: 'GET', redirect: 'follow', headers: { 'user-agent': 'Mozilla/5.0 (site check)' }, signal: AbortSignal.timeout(20000) });
        return r.status;
      } catch (e) { if (i) return 'error ' + e.message; }
    }
  };
  const own = list.filter((u) => u.startsWith(base));
  const ext = list.filter((u) => !u.startsWith(base));
  for (const u of own) { const s = await check(u); results.push({ device: '(link check)', page: u.replace(base, ''), test: 'link answers', ok: typeof s === 'number' && s < 400, detail: s }); }
  for (const u of ext) { const s = await check(u); results.push({ device: '(link check)', page: u, test: 'outside link answers', ok: typeof s === 'number' && (s < 400 || s === 403 || s === 429 || s === 999), detail: s }); }
  const pass = results.filter((r) => r.ok).length;
  console.log(`${results.length} tests: ${pass} passed, ${results.length - pass} failed`);
  const fail = results.filter((r) => !r.ok);
  const groups = {};
  fail.forEach((r) => { const k = r.test.replace(/\(\d+\)/, '') + ' @ ' + r.page; (groups[k] = groups[k] || []).push(r.device + ': ' + r.detail); });
  for (const [k, v] of Object.entries(groups)) { console.log('\n✗ ' + k + '  [' + v.length + ']'); v.slice(0, 4).forEach((x) => console.log('    ' + x)); }
  const byDev = {};
  results.forEach((r) => { byDev[r.device] = byDev[r.device] || [0, 0]; byDev[r.device][r.ok ? 0 : 1]++; });
  console.log('\nper device:'); for (const [d, [p, f]] of Object.entries(byDev)) console.log(`  ${d}: ${p} passed, ${f} failed`);
})();
