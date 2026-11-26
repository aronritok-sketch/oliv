/*
 * The live class test lab (Áron, 2026-11-26): who is in each test room right now (from LiveKit itself, every few seconds
 * while the page is open), muting and taking people out, copying the links and their QR codes for phones.
 */
(function () {
	'use strict';
	var L = window.oysLab;
	if (!L) { return; }
	var t = L.i18n;

	function fmt(s) {
		var a = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(s).replace(/%[sd]/g, function () { return a[i++]; });
	}

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined) { e.textContent = text; }
		return e;
	}

	function api(path, body) {
		return fetch(L.api + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': L.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error((j && j.message) || r.status); }
				return j;
			});
		});
	}

	// copy and QR
	document.querySelectorAll('[data-lab-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var v = b.getAttribute('data-lab-copy');
			var done = function () { var o = b.textContent; b.textContent = t.copied; setTimeout(function () { b.textContent = o; }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(v).then(done, function () { window.prompt('', v); });
			} else {
				window.prompt('', v);
			}
		});
	});
	document.querySelectorAll('[data-lab-qr]').forEach(function (b) {
		b.addEventListener('click', function () {
			var box = b.parentNode.querySelector('.oys-lab__qr');
			if (!box) { return; }
			if (!box.hidden) { box.hidden = true; return; }
			if (!box.firstChild && typeof window.qrcode === 'function') {
				var q = window.qrcode(0, 'M');
				q.addData(b.getAttribute('data-lab-qr'));
				q.make();
				box.innerHTML = q.createImgTag(5, 8);
			}
			box.hidden = false;
		});
	});

	function tracksText(p) {
		var cam = null, mic = null;
		p.tracks.forEach(function (tr) {
			if (tr.kind === 'audio') { mic = tr; } else if (tr.source !== 'screen_share') { cam = tr; }
		});
		if (!cam && !mic) { return t.none; }
		var parts = [];
		if (cam) { parts.push(t.cam + (cam.muted ? ' (' + t.off + ')' : '') + (cam.size ? ' ' + cam.size : '')); }
		if (mic) { parts.push(t.mic + (mic.muted ? ' (' + t.off + ')' : '')); }
		return parts.join(' · ');
	}

	function render(profile, r) {
		var box = document.querySelector('[data-lab-room="' + profile + '"] [data-lab-live]');
		if (!box) { return; }
		box.innerHTML = '';
		if (r.error) { box.appendChild(el('p', 'description', fmt(t.error, r.error))); }
		var head = el('p', '', r.people.length ? fmt(t.in, r.people.length) : t.nobody);
		box.appendChild(head);
		if (r.people.length) {
			var all = el('button', 'button button-small', t.muteAll);
			all.type = 'button';
			all.addEventListener('click', function () { all.disabled = true; api('/act', { profile: profile, 'do': 'mute_all' }).then(poll, poll); });
			head.appendChild(document.createTextNode(' '));
			head.appendChild(all);
			var ul = el('ul');
			r.people.forEach(function (p) {
				var li = el('li');
				li.appendChild(el('b', '', (p.name || p.identity) + (p.teacher ? ' (' + t.teacher + ')' : '')));
				li.appendChild(el('small', '', tracksText(p) + (p.since ? ' · ' + fmt(t.since, p.since) : '') + (p.region ? ' · ' + p.region : '')));
				if (!p.teacher) {
					[['mute', t.mute], ['remove', t.remove]].forEach(function (a) {
						var b = el('button', 'button-link', a[1]);
						b.type = 'button';
						b.addEventListener('click', function () { b.disabled = true; api('/act', { profile: profile, 'do': a[0], identity: p.identity }).then(poll, poll); });
						li.appendChild(b);
					});
				}
				ul.appendChild(li);
			});
			box.appendChild(ul);
		}
		var facts = [];
		if (r.hands.length) { facts.push(fmt(t.hands, r.hands.join(', '))); }
		facts.push(fmt(t.chat, r.chat));
		if (r.recordings.length) {
			facts.push(fmt(t.recs, r.recordings.map(function (x) { return (t.state[x.status] || x.status) + ' ' + x.minutes + "' " + x.size; }).join(', ')));
		}
		if (r.transcript) { facts.push(fmt(t.words, r.transcript)); }
		facts.forEach(function (f) { box.appendChild(el('small', '', f)); box.appendChild(el('br')); });
		if (r.summary) { box.appendChild(el('p', '', fmt(t.summary, r.summary))); }
		var st = document.querySelector('[data-lab-room="' + profile + '"] [data-lab-state]');
		if (st) { st.className = 'oys-lab__state is-' + r.moment; }
	}

	var timer = null;
	function poll() {
		clearTimeout(timer);
		if (document.hidden) { timer = setTimeout(poll, 8000); return; }
		api('').then(function (j) {
			Object.keys(j.rooms || {}).forEach(function (k) { render(k, j.rooms[k]); });
			var pr = document.querySelector('[data-lab-project]');
			if (pr) {
				pr.innerHTML = '';
				pr.appendChild(el('p', '', t.project + ' ' + ((j.project || []).length ? j.project.map(function (x) {
					return (x.class || x.name) + ' (' + fmt(t.people, x.people) + ')';
				}).join(' · ') : '—') + ' · ' + fmt(t.updated, j.time)));
			}
		}).catch(function (e) {
			document.querySelectorAll('[data-lab-live]').forEach(function (b) { b.innerHTML = ''; b.appendChild(el('p', 'description', fmt(t.error, e.message))); });
		}).then(function () {
			timer = setTimeout(poll, 4000);
		});
	}
	if (document.querySelector('[data-lab-live]')) { poll(); }
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
