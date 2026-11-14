/* Szerkesztés a weboldalon – Olivia oldalai (olivia-yoga inc/page-editor.php), a Védőburok 0.13.0 / Kassai-témák
 * szerkesztőjének mintájára – csak bejelentkezve, ?szerkesztes=1.
 * Szöveg: kattintás → helyben írható (félkövér, dőlt, sortörés), „Eredeti szöveg”. Kép: kattintás → Kép cseréje (Médiatár),
 * Kivágás (a pont, ami mindig látszik), Eredeti kép. Alul a sáv: változások száma, Mentés, Elvetés, Képek, Lista, Kilépés. */
(() => {
  const C = window.OYEDIT;
  if (!C) return;
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const SAFE = new Set(['BR', 'B', 'STRONG', 'EM', 'I', 'SMALL', 'SUP', 'SUB', 'U']);
  const ch = { t: {}, i: {}, reset: new Set() };
  let active = null, activeImg = null, focusMode = false;
  document.body.classList.add('oye-on');
  document.querySelectorAll('[data-reveal]').forEach(el => el.classList.add('is-in'));

  const cur = k => (ch.t[k] !== undefined ? (ch.t[k] === '' ? C.t[k].def : ch.t[k]) : C.t[k].cur);
  const count = () => Object.keys(ch.t).length + Object.keys(ch.i).length + ch.reset.size;

  function clean(html) {
    const t = document.createElement('template');
    t.innerHTML = html;
    const walk = node => {
      [...node.childNodes].forEach(c => {
        if (c.nodeType === 1) {
          walk(c);
          if (c.tagName === 'DIV' || c.tagName === 'P') { c.replaceWith(document.createElement('br'), ...c.childNodes); return; }
          if (!SAFE.has(c.tagName)) { c.replaceWith(...c.childNodes); return; }
          [...c.attributes].forEach(a => c.removeAttribute(a.name));
        } else if (c.nodeType !== 3) c.remove();
      });
    };
    walk(t.content);
    return t.innerHTML.replace(/ /g, ' ').replace(/^(<br>\s*)+|(<br>\s*)+$/gi, '').trim();
  }

  /* ── alsó sáv ── */
  const bar = document.createElement('div');
  bar.className = 'oye-bar';
  bar.innerHTML = `<span class="oye-bar__t"><b>Szerkesztés</b> – kattints egy szövegre vagy képre.${C.post ? ` <a href="${C.post}">Az oldal a WordPress szerkesztőjében ↗</a>` : ''}</span>
    <span class="oye-bar__n" id="oyeN">Nincs változás</span>
    <button type="button" class="oye-b" id="oyeImgs">Képek a lapon</button>
    <a class="oye-b" href="${C.admin}">Lista és súgó</a>
    <button type="button" class="oye-b" id="oyeDrop">Elvetés</button>
    <button type="button" class="oye-b oye-b--main" id="oyeSave">Mentés</button>
    <a class="oye-b oye-b--ghost" id="oyeExit" href="${C.exit}">Kilépés</a>`;
  document.body.appendChild(bar);
  const upd = () => { const n = count(); $('#oyeN').textContent = n ? `${n} változás – még nincs mentve` : 'Nincs változás'; bar.classList.toggle('is-dirty', n > 0); };
  const toast = (msg, ok) => { const t = document.createElement('div'); t.className = 'oye-toast' + (ok ? ' is-ok' : ''); t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 3600); };

  /* ── eszköztár ── */
  const tb = document.createElement('div');
  tb.className = 'oye-tb';
  tb.hidden = true;
  document.body.appendChild(tb);
  function place(el) {
    const r = el.getBoundingClientRect();
    tb.hidden = false;
    const top = r.top + scrollY - tb.offsetHeight - 10;
    tb.style.top = Math.max(scrollY + 8, top < scrollY + 8 ? r.bottom + scrollY + 10 : top) + 'px';
    tb.style.left = Math.max(8, Math.min(innerWidth - tb.offsetWidth - 8, r.left + scrollX)) + 'px';
  }

  /* ── szöveg ── */
  function openText(el) {
    closeAll();
    const k = el.dataset.ke;
    if (!C.t[k]) return;
    active = el;
    el.dataset.oyeOrig = cur(k);
    el.innerHTML = el.dataset.oyeOrig;
    el.contentEditable = 'true';
    el.spellcheck = true;
    el.classList.add('oye-active');
    el.focus();
    const sel = getSelection(), rg = document.createRange();
    rg.selectNodeContents(el); rg.collapse(false); sel.removeAllRanges(); sel.addRange(rg);
    tb.innerHTML = `<button type="button" data-c="bold" title="Félkövér"><b>B</b></button><button type="button" data-c="italic" title="Dőlt"><i>I</i></button>
      <span class="oye-tb__sep"></span><button type="button" data-c="orig">Eredeti szöveg</button><button type="button" data-c="done" class="is-main">Kész</button>`;
    place(el);
  }
  function closeText() {
    if (!active) return;
    const el = active, k = el.dataset.ke;
    const v = clean(el.innerHTML);
    el.contentEditable = 'false';
    el.classList.remove('oye-active');
    if (v !== clean(el.dataset.oyeOrig || '')) {
      ch.t[k] = v === clean(C.t[k].def) ? '' : v;
      el.classList.add('oye-changed');
      el.innerHTML = v || C.t[k].def;
    }
    active = null;
    upd();
  }

  /* ── kép ── */
  function openImg(img) {
    closeAll();
    const k = img.dataset.ki;
    if (!C.i[k]) return;
    activeImg = img;
    img.classList.add('oye-active');
    tb.innerHTML = `<button type="button" data-c="pick" class="is-main">Kép cseréje</button><button type="button" data-c="focus">Kivágás</button><button type="button" data-c="iorig">Eredeti kép</button><button type="button" data-c="done">Kész</button>`;
    place(img);
  }
  function closeImg() {
    if (!activeImg) return;
    activeImg.classList.remove('oye-active');
    activeImg = null;
    stopFocus();
  }
  function pick(img) {
    const k = img.dataset.ki;
    const fr = wp.media({ title: 'Kép kiválasztása vagy feltöltése', library: { type: 'image' }, multiple: false, button: { text: 'Ezt a képet választom' } });
    fr.on('select', () => {
      const a = fr.state().get('selection').first().toJSON();
      const s = a.sizes || {};
      img.removeAttribute('srcset');
      img.src = (s.large || s.full || a).url;
      img.style.objectPosition = '50% 50%';
      ch.i[k] = { id: a.id, fx: 50, fy: 50 };
      ch.reset.delete(k);
      img.classList.add('oye-changed');
      upd();
      startFocus(img);
    });
    fr.open();
  }
  const ov = document.createElement('div');
  ov.className = 'oye-focus';
  ov.hidden = true;
  ov.innerHTML = '<span>Kattints arra a pontra, ami mindig látszódjon (pl. egy arc). <button type="button">Kész</button></span><i></i>';
  document.body.appendChild(ov);
  function startFocus(img) {
    focusMode = true;
    const r = img.getBoundingClientRect();
    Object.assign(ov.style, { top: r.top + scrollY + 'px', left: r.left + scrollX + 'px', width: r.width + 'px', height: r.height + 'px' });
    ov.hidden = false;
    ov._img = img;
    const k = img.dataset.ki, e = ch.i[k] || C.i[k] || {};
    const dot = $('i', ov);
    dot.style.left = (e.fx ?? 50) + '%'; dot.style.top = (e.fy ?? 50) + '%';
  }
  function stopFocus() { focusMode = false; ov.hidden = true; }
  ov.addEventListener('click', e => {
    if (e.target.closest('button')) { stopFocus(); return; }
    const img = ov._img, r = ov.getBoundingClientRect(), k = img.dataset.ki;
    const x = Math.round((e.clientX - r.left) / r.width * 100), y = Math.round((e.clientY - r.top) / r.height * 100);
    img.style.objectPosition = `${x}% ${y}%`;
    const base = ch.i[k] || { id: C.i[k].id || 0 };
    ch.i[k] = { ...base, fx: x, fy: y };
    ch.reset.delete(k);
    $('i', ov).style.left = x + '%'; $('i', ov).style.top = y + '%';
    img.classList.add('oye-changed');
    upd();
  });

  function closeAll() { closeText(); closeImg(); tb.hidden = true; }

  tb.addEventListener('mousedown', e => e.preventDefault());
  tb.addEventListener('click', e => {
    const b = e.target.closest('button');
    if (!b) return;
    const c = b.dataset.c;
    if (c === 'bold' || c === 'italic') { document.execCommand(c); return; }
    if (c === 'orig' && active) { active.innerHTML = C.t[active.dataset.ke].def; return; }
    if (c === 'pick' && activeImg) { pick(activeImg); return; }
    if (c === 'focus' && activeImg) { startFocus(activeImg); return; }
    if (c === 'iorig' && activeImg) {
      const k = activeImg.dataset.ki;
      delete ch.i[k]; ch.reset.add(k);
      activeImg.removeAttribute('srcset'); activeImg.src = C.i[k].def; activeImg.style.objectPosition = '';
      activeImg.classList.add('oye-changed'); upd(); return;
    }
    if (c === 'done') closeAll();
  });

  /* ── kattintás: szerkesztés módban a linkek nem nyílnak meg; a fülek, a lenyíló kérdések működnek ── */
  document.addEventListener('click', e => {
    if (e.target.closest('.oye-bar, .oye-tb, .oye-focus, .oye-panel, .media-modal, #wpadminbar')) return;
    const t = e.target.closest('[data-ke]');
    if (t) {
      e.preventDefault(); e.stopPropagation();
      if (t !== active) openText(t);
      return;
    }
    const im = e.target.closest('[data-ki]');
    if (im) { e.preventDefault(); e.stopPropagation(); openImg(im); return; }
    if (active || activeImg) closeAll();
    const a = e.target.closest('a[href]');
    if (a && !a.getAttribute('href').startsWith('#')) { e.preventDefault(); toast('Szerkesztés közben a linkek nem nyílnak meg. Előbb Mentés, aztán Kilépés.'); }
  }, true);
  document.addEventListener('keydown', e => {
    if (!active) { if (e.key === 'Escape') closeAll(); return; }
    if (e.key === 'Enter') { e.preventDefault(); document.execCommand('insertLineBreak') || document.execCommand('insertHTML', false, '<br><br>'); }
    if (e.key === 'Escape') { e.preventDefault(); closeAll(); }
  });
  document.addEventListener('paste', e => {
    if (!active) return;
    e.preventDefault();
    document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
  });
  addEventListener('scroll', () => { if (!tb.hidden) place(active || activeImg || tb); if (focusMode && ov._img) startFocus(ov._img); }, { passive: true });

  /* ── képek a lapon ── */
  $('#oyeImgs').addEventListener('click', () => {
    let p = $('.oye-panel');
    if (p) { p.remove(); return; }
    p = document.createElement('div');
    p.className = 'oye-panel';
    p.innerHTML = '<div class="oye-panel__h"><b>Képek ezen a lapon</b><button type="button" aria-label="Bezárás">✕</button></div><p class="oye-panel__p">Kattints arra, amelyiket cserélnéd.</p><div class="oye-panel__g"></div>';
    const g = $('.oye-panel__g', p);
    $$('img[data-ki]').forEach(img => {
      const b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = `<img src="${img.currentSrc || img.src}" alt="">`;
      b.addEventListener('click', () => { p.remove(); img.scrollIntoView({ block: 'center' }); setTimeout(() => { openImg(img); pick(img); }, 350); });
      g.appendChild(b);
    });
    $('.oye-panel__h button', p).addEventListener('click', () => p.remove());
    document.body.appendChild(p);
  });

  /* ── mentés ── */
  async function save() {
    closeAll();
    if (!count()) { toast('Nincs mit menteni.'); return; }
    const btn = $('#oyeSave');
    btn.disabled = true; btn.textContent = 'Mentés…';
    try {
      const r = await fetch(C.rest, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
        body: JSON.stringify({ t: Object.fromEntries(Object.entries(ch.t).map(([k, v]) => [k, { v, def: C.t[k].def }])), i: Object.fromEntries(Object.entries(ch.i).map(([k, v]) => [k, { ...v, def: C.i[k].def, alt: C.i[k].alt || '' }])), reset: [...ch.reset], where: C.where }) });
      const j = await r.json();
      if (!r.ok || !j.ok) throw new Error(j.message || r.status);
      ch.t = {}; ch.i = {}; ch.reset.clear(); upd();
      toast('Mentve. Az oldal frissül…', true);
      setTimeout(() => location.reload(), 800);
    } catch (err) {
      toast('Nem sikerült menteni: ' + err.message);
    } finally { btn.disabled = false; btn.textContent = 'Mentés'; }
  }
  $('#oyeSave').addEventListener('click', save);
  $('#oyeDrop').addEventListener('click', () => { if (!count() || confirm('Elveted a még nem mentett változásokat?')) { ch.t = {}; ch.i = {}; ch.reset.clear(); location.reload(); } });
  $('#oyeExit').addEventListener('click', e => { if (count() && !confirm('Van még nem mentett változás. Kilépsz mentés nélkül?')) e.preventDefault(); else { ch.t = {}; ch.i = {}; ch.reset.clear(); } });
  addEventListener('beforeunload', e => { if (count()) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); } });
  upd();
})();
