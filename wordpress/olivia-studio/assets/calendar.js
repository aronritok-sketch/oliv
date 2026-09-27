/* Olivia Studio — admin calendar.
 * Week (or day, on small screens) view of every session. Click an empty slot to add a class,
 * click a class to edit it, drag it to move it, drag its bottom edge to change its length.
 * Talks to the REST routes in includes/admin/class-calendar.php.
 */
(function () {
  'use strict';
  const C = window.OYS_CAL;
  const root = document.getElementById('oys-cal');
  if (!C || !root) return;

  const HOUR = 64;          // px per hour
  const SNAP = 15;          // minutes
  const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const TONES = ['forest', 'lilac', 'sun', 'sage', 'sky'];
  const classKeys = Object.keys(C.classes);

  const state = { week: mondayOf(C.week || C.today), sessions: [], showCancelled: false, day: null, busy: false };

  /* ---------- Dates (all in studio local time, as plain strings) ---------- */
  function parse(d) { const [y, m, dd] = d.split('-').map(Number); return new Date(Date.UTC(y, m - 1, dd)); }
  function fmt(dt) { return dt.toISOString().slice(0, 10); }
  function addDays(d, n) { const x = parse(d); x.setUTCDate(x.getUTCDate() + n); return fmt(x); }
  function mondayOf(d) { const x = parse(d); const wd = (x.getUTCDay() + 6) % 7; x.setUTCDate(x.getUTCDate() - wd); return fmt(x); }
  function weekday(d) { return (parse(d).getUTCDay() + 6) % 7; }
  function toMin(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m; }
  function toTime(min) { min = Math.max(0, Math.min(23 * 60 + 45, min)); return String(Math.floor(min / 60)).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0'); }
  function clock(t) { let [h, m] = t.split(':').map(Number); const ap = h >= 12 ? 'pm' : 'am'; h = h % 12 || 12; return h + (m ? ':' + String(m).padStart(2, '0') : '') + ' ' + ap; }
  function longDate(d) { return parse(d).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }); }
  function shortDate(d) { return parse(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' }); }
  function money(c) { return (c / 100).toLocaleString(undefined, { style: 'currency', currency: C.currency, minimumFractionDigits: c % 100 ? 2 : 0 }); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch])); }
  function tone(s) {
    if (s.kind === 'event') return 'pink';
    if (s.kind === 'private') return 'peach';
    const i = classKeys.indexOf(s.class_slug);
    return TONES[(i < 0 ? 0 : i) % TONES.length];
  }
  function isNarrow() { return window.matchMedia('(max-width: 782px)').matches; }

  /* ---------- Server ---------- */
  function url(path) {
    // Works with pretty permalinks (/wp-json/…) and plain ones (?rest_route=…).
    const [p, q] = path.split('?');
    return C.rest.indexOf('rest_route=') >= 0 ? C.rest + p + (q ? '&' + q : '') : C.rest + path;
  }

  async function api(path, body) {
    const res = await fetch(url(path), {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: Object.assign({ 'X-WP-Nonce': C.nonce }, body ? { 'Content-Type': 'application/json' } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.message || 'Something went wrong (' + res.status + ').');
    return json;
  }

  async function load() {
    const data = await api('calendar?from=' + state.week + '&days=7');
    state.sessions = data.sessions;
    render();
  }

  /* ---------- Rendering ---------- */
  function days() { return [0, 1, 2, 3, 4, 5, 6].map(i => addDays(state.week, i)); }

  function hourRange(list) {
    let a = 6, b = 21;
    list.forEach(s => { a = Math.min(a, Math.floor(toMin(s.start) / 60)); b = Math.max(b, Math.ceil((toMin(s.start) + s.duration) / 60)); });
    return [a, Math.min(24, b)];
  }

  /** Side-by-side columns for overlapping sessions within a day. */
  function layout(list) {
    const items = list.slice().sort((x, y) => toMin(x.start) - toMin(y.start) || y.duration - x.duration);
    let cluster = [], end = -1;
    const flush = () => {
      const cols = [];
      cluster.forEach(it => {
        let c = cols.findIndex(last => last <= toMin(it.start));
        if (c < 0) { c = cols.length; cols.push(0); }
        cols[c] = toMin(it.start) + it.duration;
        it._col = c;
      });
      cluster.forEach(it => { it._cols = cols.length; });
      cluster = [];
    };
    items.forEach(it => {
      if (toMin(it.start) >= end) { flush(); end = -1; }
      cluster.push(it);
      end = Math.max(end, toMin(it.start) + it.duration);
    });
    flush();
    return items;
  }

  function render() {
    const all = days();
    const visible = state.sessions.filter(s => state.showCancelled || s.status !== 'cancelled');
    const narrow = isNarrow();
    if (narrow && (!state.day || all.indexOf(state.day) < 0)) state.day = all.indexOf(C.today) >= 0 ? C.today : all[0];
    const shown = narrow ? [state.day] : all;
    const [h0, h1] = hourRange(visible);
    const total = state.sessions.filter(s => s.status === 'scheduled');
    const booked = total.reduce((n, s) => n + s.booked, 0);
    const seats = total.reduce((n, s) => n + s.capacity, 0);

    let html = '<div class="oys-cal__bar">'
      + '<div class="oys-cal__nav"><button type="button" class="button" data-go="-7" aria-label="Previous week">‹</button>'
      + '<button type="button" class="button" data-go="today">Today</button>'
      + '<button type="button" class="button" data-go="7" aria-label="Next week">›</button>'
      + '<h2 class="oys-cal__title">' + esc(shortDate(all[0])) + ' – ' + esc(shortDate(all[6])) + '</h2></div>'
      + '<div class="oys-cal__tools"><span class="oys-cal__stat">' + total.length + ' sessions · ' + booked + '/' + seats + ' booked</span>'
      + '<label class="oys-cal__toggle"><input type="checkbox" data-cancelled' + (state.showCancelled ? ' checked' : '') + '> Show cancelled</label>'
      + '<button type="button" class="button button-primary" data-new>+ New class</button></div></div>';

    if (narrow) {
      html += '<div class="oys-cal__daytabs" role="tablist">' + all.map((d, i) => '<button type="button" role="tab" aria-selected="' + (d === state.day) + '" data-day="' + d + '"' + (d === C.today ? ' class="is-today"' : '') + '><span>' + DAY_NAMES[i] + '</span><b>' + parse(d).getUTCDate() + '</b></button>').join('') + '</div>';
    }

    html += '<div class="oys-cal__grid" style="--cols:' + shown.length + ';--hour:' + HOUR + 'px">';
    html += '<div class="oys-cal__corner"></div>';
    shown.forEach(d => {
      html += '<div class="oys-cal__dayhead' + (d === C.today ? ' is-today' : '') + '"><span>' + DAY_NAMES[weekday(d)] + '</span><b>' + parse(d).getUTCDate() + '</b></div>';
    });
    html += '<div class="oys-cal__hours">';
    for (let h = h0; h < h1; h++) html += '<div style="height:' + HOUR + 'px"><span>' + clock(toTime(h * 60)) + '</span></div>';
    html += '</div>';
    shown.forEach(d => {
      const list = layout(visible.filter(s => s.date === d));
      html += '<div class="oys-cal__col' + (d < C.today ? ' is-past' : '') + '" data-date="' + d + '" data-h0="' + h0 + '" style="height:' + ((h1 - h0) * HOUR) + 'px">';
      for (let h = h0; h < h1; h++) html += '<div class="oys-cal__slot" style="top:' + ((h - h0) * HOUR) + 'px;height:' + HOUR + 'px"></div>';
      if (d === C.today) {
        const now = new Date();
        const mins = now.getHours() * 60 + now.getMinutes();
        if (mins >= h0 * 60 && mins <= h1 * 60) html += '<div class="oys-cal__now" style="top:' + ((mins - h0 * 60) / 60 * HOUR) + 'px"></div>';
      }
      list.forEach(s => { html += eventHtml(s, h0); });
      html += '</div>';
    });
    html += '</div>';
    html += '<p class="oys-cal__hint">Click an empty time to add a class. Drag a class to move it, drag its bottom edge to change its length. <a href="' + esc(C.listUrl) + '">List view</a></p>';
    root.innerHTML = html;
    if (!visible.length) {
      root.querySelector('.oys-cal__grid').insertAdjacentHTML('beforeend', '<p class="oys-cal__empty">Nothing this week yet. Click a time to add a class.</p>');
    }
  }

  function eventHtml(s, h0) {
    const top = (toMin(s.start) - h0 * 60) / 60 * HOUR;
    const height = Math.max(22, s.duration / 60 * HOUR - 2);
    const w = 100 / (s._cols || 1);
    const full = s.booked >= s.capacity;
    const cls = ['oys-ev', 'oys-ev--' + tone(s)];
    if (s.status === 'cancelled') cls.push('is-cancelled');
    if (s.format === 'online') cls.push('is-online');
    if (s.format === 'hybrid') cls.push('is-hybrid');
    if (s.past) cls.push('is-past');
    if (full) cls.push('is-full');
    if (height < 44) cls.push('is-short');
    const end = toTime(toMin(s.start) + s.duration);
    const badges = (s.series ? '<i class="oys-ev__rep" title="' + esc(s.series.label) + '">↻</i>' : '')
      + (s.format === 'online' ? '<i class="oys-ev__tag">Online</i>' : '')
      + (s.format === 'hybrid' ? '<i class="oys-ev__tag">+ Live ' + s.online_booked + '</i>' : '')
      + (s.kind === 'event' ? '<i class="oys-ev__tag">Event</i>' : '')
      + (s.kind === 'private' ? '<i class="oys-ev__tag">Private</i>' : '')
      + (s.pricing === 'donation' ? '<i class="oys-ev__tag" title="By donation">♡</i>' : '');
    return '<div class="' + cls.join(' ') + '" data-id="' + s.id + '" tabindex="0" role="button" aria-label="' + esc(s.label + ', ' + longDate(s.date) + ' ' + clock(s.start)) + '"'
      + ' style="top:' + top + 'px;height:' + height + 'px;left:calc(' + (s._col || 0) * w + '% + 2px);width:calc(' + w + '% - 4px)">'
      + '<span class="oys-ev__time">' + clock(s.start) + ' – ' + clock(end) + '</span>'
      + '<span class="oys-ev__name">' + esc(s.label) + '</span>'
      + '<span class="oys-ev__meta">' + badges + (s.status === 'cancelled' ? '<i class="oys-ev__tag">Cancelled</i>' : '<b>' + s.booked + '/' + s.capacity + '</b>' + (s.waitlist ? ' +' + s.waitlist + ' waiting' : '')) + '</span>'
      + (s.status === 'scheduled' && !s.past ? '<span class="oys-ev__resize" aria-hidden="true"></span>' : '')
      + '</div>';
  }

  /* ---------- Toasts and dialogs ---------- */
  function toast(msg, bad) {
    let box = document.querySelector('.oys-toasts');
    if (!box) { box = document.createElement('div'); box.className = 'oys-toasts'; document.body.appendChild(box); }
    const t = document.createElement('div');
    t.className = 'oys-toast' + (bad ? ' is-bad' : '');
    t.setAttribute('role', bad ? 'alert' : 'status');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => t.remove(), bad ? 7000 : 4000);
  }

  /** Small modal: resolves with the value of the clicked button (null when dismissed). */
  function dialog(title, bodyHtml, buttons) {
    return new Promise(resolve => {
      const wrap = document.createElement('div');
      wrap.className = 'oys-modal';
      wrap.innerHTML = '<div class="oys-modal__box" role="dialog" aria-modal="true" aria-labelledby="oys-modal-t"><h2 id="oys-modal-t">' + esc(title) + '</h2><div class="oys-modal__body">' + bodyHtml + '</div><div class="oys-modal__actions">'
        + buttons.map(b => '<button type="button" class="button' + (b.primary ? ' button-primary' : '') + (b.danger ? ' oys-danger' : '') + '" data-v="' + esc(b.value) + '">' + esc(b.label) + '</button>').join('')
        + '</div></div>';
      document.body.appendChild(wrap);
      const close = v => { const body = wrap.querySelector('.oys-modal__body'); const out = v == null ? null : { value: v, form: body }; wrap.remove(); document.removeEventListener('keydown', key); resolve(out); };
      const key = e => { if (e.key === 'Escape') close(null); };
      document.addEventListener('keydown', key);
      wrap.addEventListener('click', e => {
        if (e.target === wrap) close(null);
        const b = e.target.closest('[data-v]');
        if (b) close(b.dataset.v);
      });
      (wrap.querySelector('.button-primary') || wrap.querySelector('button')).focus();
    });
  }

  /** Before changing a session: which dates (weekly class) and whether to email people booked. */
  async function confirmChange(s, what) {
    const needsNotice = s.booked > 0 && what !== 'details';
    if (!s.series && !needsNotice) return { scope: 'one', notify: false };
    let body = '';
    if (s.series) body += '<p>This is a weekly class (' + esc(s.series.label) + ').</p>';
    if (needsNotice) body += '<p><label><input type="checkbox" name="notify" checked> Email the ' + s.booked + ' ' + (s.booked === 1 ? 'person' : 'people') + ' booked about the change</label></p>';
    const buttons = s.series
      ? [{ label: 'Cancel', value: 'x' }, { label: 'Only this date', value: 'one' }, { label: 'This and following weeks', value: 'series', primary: true }]
      : [{ label: 'Cancel', value: 'x' }, { label: 'Save change', value: 'one', primary: true }];
    const r = await dialog(s.series ? 'Change which dates?' : 'Save change?', body, buttons);
    if (!r || r.value === 'x') return null;
    const n = r.form.querySelector('[name=notify]');
    return { scope: r.value, notify: !!(n && n.checked) };
  }

  /* ---------- Drawer (add / edit) ---------- */
  function field(label, inner, cls) { return '<label class="oys-f' + (cls ? ' ' + cls : '') + '"><span>' + label + '</span>' + inner + '</label>'; }

  function openDrawer(s, preset) {
    closeDrawer();
    const isNew = !s;
    const v = s || Object.assign({ kind: 'group', class_slug: classKeys[0] || '', title: '', description: '', date: C.today, start: '18:00', duration: 60, capacity: 12, location: '', format: 'studio', online_url: '', price: C.prices.group, credits_allowed: true, pricing: 'fixed', pay_later: true, note: '', status: 'scheduled', booked: 0, people: [] }, preset || {});
    const locked = !isNew && (v.status !== 'scheduled' || v.past);
    const d = document.createElement('aside');
    d.className = 'oys-drawer';
    d.setAttribute('role', 'dialog');
    d.setAttribute('aria-label', isNew ? 'New class' : v.label);
    const kinds = { group: 'Class', event: 'Event / workshop', private: 'Private' };
    let html = '<div class="oys-drawer__head"><h2>' + (isNew ? 'New class' : esc(v.label)) + '</h2><button type="button" class="oys-drawer__x" aria-label="Close">×</button></div><div class="oys-drawer__body">';
    if (!isNew) {
      html += '<p class="oys-drawer__when">' + esc(longDate(v.date)) + ' · ' + clock(v.start) + ' – ' + clock(toTime(toMin(v.start) + v.duration)) + (v.status === 'cancelled' ? ' · <b class="oys-chip is-bad">Cancelled</b>' : '') + '</p>';
      if (v.series) html += '<p class="oys-drawer__series">↻ ' + esc(v.series.label) + (v.series.active ? '' : ' (stopped)') + '</p>';
      html += '<div class="oys-drawer__people"><div class="oys-meter"><span style="width:' + Math.min(100, v.booked / v.capacity * 100) + '%"></span></div><p><b>' + v.booked + ' / ' + v.capacity + '</b> ' + (v.format === 'hybrid' ? 'in the studio · <b>' + v.online_booked + (v.online_capacity ? ' / ' + v.online_capacity : '') + '</b> online' : 'booked') + (v.held ? ' · ' + v.held + ' paying now' : '') + (v.waitlist ? ' · ' + v.waitlist + ' on the waitlist' : '') + '</p>'
        + (v.people.length ? '<ul>' + v.people.map(p => '<li>' + esc(p) + '</li>').join('') + '</ul>' : '')
        + (v.due ? '<p class="oys-drawer__due">To collect at the studio: <b>' + money(v.due) + '</b></p>' : '')
        + '<p class="oys-drawer__links"><a class="button" href="' + esc(C.rosterUrl + v.id) + '">Roster &amp; attendance</a> ' + (v.booked && v.status === 'scheduled' ? '<a class="button" href="' + esc(C.rosterUrl + v.id) + '#message">Message everyone</a> ' : '') + '<a class="button-link" href="' + esc(C.bookUrl + v.id) + '" target="_blank" rel="noopener">Booking page ↗</a></p>'
        + (v.zoom && v.status === 'scheduled' ? '<p class="oys-drawer__zoom"><a class="button button-primary" href="' + esc(v.zoom.start) + '" target="_blank" rel="noopener">Start the Zoom class</a> <span>Meeting ' + esc(v.zoom.id) + '</span></p>' : '')
        + '</div>';
    }
    html += '<form class="oys-drawer__form"' + (locked ? ' inert' : '') + '>';
    html += '<div class="oys-seg" role="radiogroup" aria-label="Type">' + Object.keys(kinds).map(k => '<label><input type="radio" name="kind" value="' + k + '"' + (v.kind === k ? ' checked' : '') + (isNew ? '' : ' disabled') + '><span>' + kinds[k] + '</span></label>').join('') + '</div>';
    html += field('Class', '<select name="class_slug"><option value="">— none —</option>' + classKeys.map(k => '<option value="' + esc(k) + '"' + (v.class_slug === k ? ' selected' : '') + '>' + esc(C.classes[k]) + '</option>').join('') + '</select>', 'f-class');
    html += field('Title', '<input type="text" name="title" value="' + esc(v.title) + '" placeholder="e.g. Full Moon Flow with live music">', 'f-title');
    html += '<div class="oys-row">' + field('Date', '<input type="date" name="date" required value="' + esc(v.date) + '">') + field('Starts', '<input type="time" name="start" step="300" required value="' + esc(v.start) + '">') + field('Minutes', '<input type="number" name="duration" min="15" max="600" step="5" value="' + v.duration + '">') + '</div>';
    const formats = { studio: 'In person', online: 'Online', hybrid: 'Both' };
    html += '<div class="oys-seg" role="radiogroup" aria-label="Where">' + Object.keys(formats).map(k => '<label><input type="radio" name="format" value="' + k + '"' + ((v.format || 'studio') === k ? ' checked' : '') + '><span>' + formats[k] + '</span></label>').join('') + '</div>';
    html += '<p class="description f-hybrid-note">In the studio and streamed live: people choose how they join.</p>';
    html += field('Location', '<input type="text" name="location" value="' + esc(v.location) + '" placeholder="Studio or address">', 'f-loc');
    html += field(C.zoom ? 'Online link (leave empty: a Zoom meeting is created automatically)' : 'Online link (Zoom, Meet…)', '<input type="url" name="online_url" value="' + esc(v.online_url) + '" placeholder="https://">', 'f-url');
    html += '<div class="oys-row f-online">' + field('Online spots (0 = no limit)', '<input type="number" name="online_capacity" min="0" value="' + (v.online_capacity || 0) + '">') + field('Online ticket (' + esc(C.currency) + ')', '<input type="number" name="online_price" min="0" step="0.01" value="' + ((v.format === 'hybrid' ? v.online_price : C.prices.online) / 100) + '">') + '</div>';
    html += '<div class="oys-seg oys-seg--sm" role="radiogroup" aria-label="Price">' + [['fixed', 'Fixed price'], ['donation', 'By donation']].map(p => '<label><input type="radio" name="pricing" value="' + p[0] + '"' + ((v.pricing || 'fixed') === p[0] ? ' checked' : '') + '><span>' + p[1] + '</span></label>').join('') + '</div>';
    html += '<div class="oys-row">' + field('Spots', '<input type="number" name="capacity" min="1" value="' + v.capacity + '">') + field('<span class="f-price-label">Drop-in price</span> (' + esc(C.currency) + ')', '<input type="number" name="price" min="0" step="0.01" value="' + (v.price / 100) + '">') + '</div>';
    html += '<p class="description f-donation-note">People choose what to give (from ' + money(C.donationMin) + '), by card or at the studio. The price above is the suggested amount.</p>';
    html += '<label class="oys-check"><input type="checkbox" name="credits_allowed"' + (v.credits_allowed ? ' checked' : '') + '> Passes and memberships can be used</label>';
    html += '<label class="oys-check f-paylater"><input type="checkbox" name="pay_later"' + (v.pay_later !== false ? ' checked' : '') + '> People can book now and pay at the studio' + (C.payLater === 'off' ? ' <small>(switched off in Settings)</small>' : '') + '</label>';
    html += field('Short note (shown on the timetable)', '<input type="text" name="note" value="' + esc(v.note) + '" placeholder="e.g. Bring a towel">');
    html += field('Description', '<textarea name="description" rows="3">' + esc(v.description) + '</textarea>', 'f-desc');
    if (isNew) html += field('Repeat', '<select name="repeat"><option value="none">Just this date</option><option value="weekly">Every week on ' + DAY_NAMES[weekday(v.date)] + '</option></select>', 'f-repeat');
    html += '</form></div><div class="oys-drawer__foot">';
    if (!locked) html += '<button type="button" class="button button-primary" data-save>' + (isNew ? 'Create' : 'Save changes') + '</button>';
    if (!isNew && v.status === 'scheduled' && !v.past) html += '<button type="button" class="button oys-danger" data-cancel>Cancel class…</button>';
    html += '</div>';
    d.innerHTML = html;
    document.body.appendChild(d);
    document.body.classList.add('oys-drawer-open');
    const form = d.querySelector('form');

    const sync = () => {
      const kind = form.kind.value;
      const online = form.format.value === 'online';
      const hybrid = form.format.value === 'hybrid';
      d.querySelector('.f-title').hidden = kind === 'group';
      d.querySelector('.f-desc').hidden = kind === 'group';
      d.querySelector('.f-loc').hidden = online;
      d.querySelector('.f-url').hidden = !online && !hybrid;
      d.querySelector('.f-online').hidden = !hybrid;
      d.querySelector('.f-hybrid-note').hidden = !hybrid;
      const donation = (form.querySelector('[name=pricing]:checked') || {}).value === 'donation';
      d.querySelector('.f-donation-note').hidden = !donation;
      d.querySelector('.f-price-label').textContent = donation ? 'Suggested amount' : 'Drop-in price';
      d.querySelector('.f-paylater').hidden = online || kind === 'private';
      const rep = d.querySelector('.f-repeat');
      if (rep) {
        rep.hidden = kind !== 'group';
        rep.querySelector('option[value=weekly]').textContent = 'Every week on ' + DAY_NAMES[weekday(form.date.value || C.today)];
      }
    };
    form.addEventListener('change', e => {
      if (e.target.name === 'format' && isNew) {
        // New online classes default to the online price, in-person ones to the drop-in price.
        form.price.value = (form.format.value === 'online' ? C.prices.online : C.prices.group) / 100;
        form.online_price.value = C.prices.online / 100;
      }
      if (e.target.name === 'kind' && isNew) {
        if (form.kind.value === 'private') { form.capacity.value = 1; form.credits_allowed.checked = false; }
        if (form.kind.value === 'event') { form.credits_allowed.checked = false; form.capacity.value = 20; }
      }
      sync();
    });
    sync();
    d.querySelector('.oys-drawer__x').addEventListener('click', closeDrawer);
    const save = d.querySelector('[data-save]');
    if (save) save.addEventListener('click', () => submit(v, form, isNew, save));
    const cancel = d.querySelector('[data-cancel]');
    if (cancel) cancel.addEventListener('click', () => cancelSession(v));
    (form.querySelector(isNew ? '[name=start]' : '[name=capacity]') || form).focus();
  }

  function closeDrawer() {
    const d = document.querySelector('.oys-drawer');
    if (d) d.remove();
    document.body.classList.remove('oys-drawer-open');
  }

  function formData(form) {
    return {
      kind: (form.querySelector('[name=kind]:checked') || {}).value || 'group',
      class_slug: form.class_slug.value,
      title: form.title.value.trim(),
      description: form.description.value,
      date: form.date.value,
      start: form.start.value,
      duration: parseInt(form.duration.value, 10) || 60,
      format: form.format.value,
      location: form.location.value.trim(),
      online_url: form.online_url.value.trim(),
      online_capacity: parseInt(form.online_capacity.value, 10) || 0,
      online_price: Math.round(parseFloat(form.online_price.value || '0') * 100),
      capacity: parseInt(form.capacity.value, 10) || 1,
      price: Math.round(parseFloat(form.price.value || '0') * 100),
      credits_allowed: form.credits_allowed.checked,
      pricing: (form.querySelector('[name=pricing]:checked') || {}).value || 'fixed',
      pay_later: form.pay_later.checked,
      note: form.note.value.trim(),
      repeat: form.repeat ? form.repeat.value : 'none',
    };
  }

  async function submit(v, form, isNew, btn) {
    if (!form.reportValidity()) return;
    const data = formData(form);
    if (!isNew) {
      const timeChanged = data.date !== v.date || data.start !== v.start || data.duration !== v.duration || data.format !== v.format || data.location !== v.location || data.online_url !== v.online_url;
      const choice = await confirmChange(v, timeChanged ? 'time' : 'details');
      if (!choice) return;
      Object.assign(data, choice);
    }
    btn.disabled = true;
    try {
      const r = await api(isNew ? 'sessions' : 'sessions/' + v.id, data);
      toast(r.message);
      closeDrawer();
      if (r.session.date < state.week || r.session.date > addDays(state.week, 6)) state.week = mondayOf(r.session.date);
      await load();
    } catch (e) {
      toast(e.message, true);
      btn.disabled = false;
    }
  }

  async function cancelSession(v) {
    let body = '<p>Everyone booked gets an email. Pass and membership bookings get their class back; card payments become a class credit (you can refund in Payments instead).</p>'
      + '<p><label class="oys-f"><span>Reason in the email (optional)</span><input type="text" name="reason" class="regular-text" placeholder="e.g. Olivia is unwell"></label></p>';
    const buttons = v.series
      ? [{ label: 'Keep', value: 'x' }, { label: 'Cancel this date', value: 'one', danger: true }, { label: 'Stop the weekly class', value: 'series', danger: true }]
      : [{ label: 'Keep', value: 'x' }, { label: 'Cancel class', value: 'one', danger: true }];
    if (v.series) body += '<p class="description">“Stop the weekly class” cancels this and every following date and stops new dates being added.</p>';
    const r = await dialog('Cancel ' + v.label + ', ' + shortDate(v.date) + '?', body, buttons);
    if (!r || r.value === 'x') return;
    try {
      const res = await api('sessions/' + v.id + '/cancel', { scope: r.value, reason: r.form.querySelector('[name=reason]').value });
      toast(res.message);
      closeDrawer();
      await load();
    } catch (e) { toast(e.message, true); }
  }

  /* ---------- Drag to move / resize ---------- */
  let drag = null;

  function colAt(x, y) {
    const els = document.elementsFromPoint(x, y);
    return els.find(el => el.classList && el.classList.contains('oys-cal__col')) || null;
  }

  root.addEventListener('pointerdown', e => {
    const ev = e.target.closest('.oys-ev');
    if (!ev || e.button !== 0) return;
    const s = state.sessions.find(x => x.id === +ev.dataset.id);
    if (!s || s.status !== 'scheduled' || s.past) return;
    const col = ev.closest('.oys-cal__col');
    drag = { s, ev, col, resize: e.target.classList.contains('oys-ev__resize'), x0: e.clientX, y0: e.clientY, top0: ev.offsetTop, h0: ev.offsetHeight, moved: false, h0hour: +col.dataset.h0 };
    ev.setPointerCapture(e.pointerId);
  });

  root.addEventListener('pointermove', e => {
    if (!drag) return;
    const dy = e.clientY - drag.y0, dx = e.clientX - drag.x0;
    if (!drag.moved && Math.abs(dy) < 5 && Math.abs(dx) < 5) return;
    drag.moved = true;
    drag.ev.classList.add('is-dragging');
    const step = SNAP / 60 * HOUR;
    if (drag.resize) {
      const h = Math.max(step, Math.round((drag.h0 + dy) / step) * step);
      drag.ev.style.height = h + 'px';
      drag.duration = Math.round(h / HOUR * 60 / SNAP) * SNAP;
      return;
    }
    const top = Math.max(0, Math.round((drag.top0 + dy) / step) * step);
    drag.ev.style.top = top + 'px';
    drag.start = toTime(drag.h0hour * 60 + Math.round(top / HOUR * 60 / SNAP) * SNAP);
    const over = colAt(e.clientX, e.clientY);
    if (over && over !== drag.ev.parentElement) over.appendChild(drag.ev);
    drag.date = (over || drag.ev.parentElement).dataset.date;
    drag.ev.querySelector('.oys-ev__time').textContent = clock(drag.start) + ' – ' + clock(toTime(toMin(drag.start) + drag.s.duration));
  });

  root.addEventListener('pointerup', async () => {
    if (!drag) return;
    const d = drag;
    drag = null;
    if (!d.moved) { openDrawer(d.s); return; }
    const change = { date: d.date || d.s.date, start: d.start || d.s.start, duration: d.duration || d.s.duration };
    if (change.date === d.s.date && change.start === d.s.start && change.duration === d.s.duration) { render(); return; }
    const choice = await confirmChange(d.s, 'time');
    if (!choice) { render(); return; }
    const body = Object.assign({}, d.s, change, choice);
    try {
      const r = await api('sessions/' + d.s.id, body);
      toast(r.message);
    } catch (err) { toast(err.message, true); }
    await load();
  });

  /* ---------- Clicks ---------- */
  root.addEventListener('click', e => {
    const go = e.target.closest('[data-go]');
    if (go) {
      state.week = go.dataset.go === 'today' ? mondayOf(C.today) : addDays(state.week, +go.dataset.go);
      if (go.dataset.go === 'today') state.day = C.today;
      load().catch(err => toast(err.message, true));
      return;
    }
    const day = e.target.closest('[data-day]');
    if (day) { state.day = day.dataset.day; render(); return; }
    if (e.target.closest('[data-new]')) {
      const base = state.day || (days().indexOf(C.today) >= 0 ? C.today : state.week);
      openDrawer(null, { date: base });
      return;
    }
    const slot = e.target.closest('.oys-cal__slot, .oys-cal__col');
    if (slot && !e.target.closest('.oys-ev')) {
      const col = e.target.closest('.oys-cal__col');
      const rect = col.getBoundingClientRect();
      const mins = +col.dataset.h0 * 60 + Math.floor((e.clientY - rect.top) / HOUR * 60 / 30) * 30;
      if (col.dataset.date < C.today) { toast('That day has passed.', true); return; }
      openDrawer(null, { date: col.dataset.date, start: toTime(mins) });
    }
  });
  root.addEventListener('keydown', e => {
    const ev = e.target.closest('.oys-ev');
    if (ev && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      openDrawer(state.sessions.find(x => x.id === +ev.dataset.id));
    }
  });
  root.addEventListener('change', e => {
    if (e.target.matches('[data-cancelled]')) { state.showCancelled = e.target.checked; render(); }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !document.querySelector('.oys-modal')) closeDrawer(); });
  let lastNarrow = isNarrow();
  window.addEventListener('resize', () => { if (isNarrow() !== lastNarrow) { lastNarrow = isNarrow(); render(); } });

  load().then(() => {
    if (+C.open) {
      const s = state.sessions.find(x => x.id === +C.open);
      if (s) openDrawer(s);
    }
  }).catch(err => { root.innerHTML = '<div class="notice notice-error"><p>' + esc(err.message) + '</p></div>'; });
})();
