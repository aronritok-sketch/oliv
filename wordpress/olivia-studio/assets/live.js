/**
 * The live page of an online class (OYS_Live): the video in Daily Prebuilt, the class chat that stays after class,
 * and for the teacher the recording of the teacher only and the transcript of the opening words.
 */
(function () {
	'use strict';
	var root = document.querySelector('[data-oys-live]');
	if (!root) {
		return;
	}
	var cfg = JSON.parse(root.getAttribute('data-oys-live') || '{}');
	var t = cfg.i18n || {};
	var q = function (sel) { return root.querySelector(sel); };

	function api(path, opts) {
		opts = opts || {};
		var params = new URLSearchParams(opts.query || {});
		if (cfg.b) {
			params.set('b', cfg.b);
			params.set('k', cfg.k);
		}
		var url = cfg.api + path;
		var qs = params.toString();
		if (qs) {
			url += (url.indexOf('?') < 0 ? '?' : '&') + qs;
		}
		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		return fetch(url, {
			method: opts.method || 'GET',
			headers: headers,
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) {
					throw new Error((j && j.message) || t.error);
				}
				return j;
			});
		});
	}

	/* ---------- Chat ---------- */

	var list = q('[data-live-messages]');
	var form = q('[data-live-form]');
	var last = 0;
	var timer = null;

	function nearBottom() {
		return list.scrollHeight - list.scrollTop - list.clientHeight < 80;
	}

	function emptyNote() {
		var e = list.querySelector('.oys-live__empty');
		var has = list.querySelector('[data-id]');
		if (!has && !e) {
			e = document.createElement('li');
			e.className = 'oys-live__empty';
			e.textContent = t.empty;
			list.appendChild(e);
		} else if (has && e) {
			e.remove();
		}
	}

	function add(m) {
		if (list.querySelector('[data-id="' + m.id + '"]')) {
			return;
		}
		var li = document.createElement('li');
		li.className = 'oys-live__msg' + (m.teacher ? ' is-teacher' : '') + (m.mine ? ' is-mine' : '');
		li.setAttribute('data-id', m.id);
		var head = document.createElement('p');
		head.className = 'oys-live__msg-head';
		var who = document.createElement('strong');
		who.textContent = m.name;
		head.appendChild(who);
		if (m.teacher) {
			var badge = document.createElement('span');
			badge.className = 'oys-live__badge';
			badge.textContent = t.teacher;
			head.appendChild(badge);
		}
		var time = document.createElement('time');
		time.textContent = m.time;
		head.appendChild(time);
		if (m.can_del) {
			var del = document.createElement('button');
			del.type = 'button';
			del.className = 'oys-live__del';
			del.textContent = t.remove;
			del.addEventListener('click', function () {
				del.disabled = true;
				api('/chat/' + m.id, { method: 'DELETE' }).then(function () { li.remove(); emptyNote(); }, function () { del.disabled = false; });
			});
			head.appendChild(del);
		}
		var body = document.createElement('p');
		body.className = 'oys-live__msg-body';
		body.textContent = m.body;
		li.appendChild(head);
		li.appendChild(body);
		list.appendChild(li);
		last = Math.max(last, m.id);
	}

	function poll() {
		clearTimeout(timer);
		var stick = nearBottom();
		api('/chat', { query: { after: last } }).then(function (j) {
			(j.messages || []).forEach(add);
			(j.gone || []).forEach(function (id) {
				var el = list.querySelector('[data-id="' + id + '"]');
				if (el) {
					el.remove();
				}
			});
			emptyNote();
			if (stick && (j.messages || []).length) {
				list.scrollTop = list.scrollHeight;
			}
			if (j.phase) {
				cfg.phase = j.phase;
			}
		}).catch(function () {}).then(next);
	}

	function next() {
		var ms = document.hidden ? 30000 : (cfg.phase === 'open' ? 4000 : 15000);
		timer = setTimeout(poll, ms);
	}

	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			poll();
		}
	});

	if (form) {
		var area = form.querySelector('textarea');
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var text = area.value.trim();
			if (!text) {
				return;
			}
			var btn = form.querySelector('button');
			btn.disabled = true;
			api('/chat', { method: 'POST', body: { body: text } }).then(function () {
				area.value = '';
				list.scrollTop = list.scrollHeight;
				poll();
			}, function (err) {
				window.alert(err.message || t.error);
			}).then(function () { btn.disabled = false; area.focus(); });
		});
		area.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
			}
		});
	}
	poll();

	/* ---------- After class: summary (teacher), recording ---------- */

	var sumForm = q('[data-live-summary]');
	if (sumForm) {
		sumForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var note = sumForm.querySelector('[data-live-summary-note]');
			api('/summary', { method: 'POST', body: {
				summary: sumForm.querySelector('textarea').value,
				status: sumForm.querySelector('[name="published"]').checked ? 'published' : 'hidden'
			} }).then(function () { note.textContent = t.saved; }, function (err) { note.textContent = err.message || t.error; });
		});
	}

	root.querySelectorAll('[data-live-replay]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			api('/recording', { query: { rec: btn.getAttribute('data-live-replay') } }).then(function (j) {
				var box = q('[data-live-player]');
				box.innerHTML = '';
				var v = document.createElement('video');
				v.controls = true;
				v.playsInline = true;
				v.preload = 'metadata';
				v.src = j.url;
				v.className = 'oys-live__player';
				box.appendChild(v);
				v.play().catch(function () {});
			}, function (err) {
				window.alert(err.message || t.error);
			}).then(function () { btn.disabled = false; });
		});
	});

	/* ---------- Video ---------- */

	var joinBtn = q('[data-live-join]');
	if (!joinBtn) {
		return;
	}
	var lobby = q('[data-live-lobby]');
	var stage = q('[data-live-video]');
	var status = q('[data-live-status]');
	var ownerBar = q('[data-live-owner]');
	var recBtn = q('[data-live-rec]');
	var recState = q('[data-live-rec-state]');
	var frame = null;
	var closing = Promise.resolve();
	var dailyP = null;
	var timers = [];

	function loadDaily() {
		if (window.Daily) {
			return Promise.resolve(window.Daily);
		}
		if (!dailyP) {
			dailyP = new Promise(function (ok, no) {
				var s = document.createElement('script');
				s.src = cfg.daily;
				s.async = true;
				s.onload = function () { window.Daily ? ok(window.Daily) : no(new Error(t.error)); };
				s.onerror = function () { dailyP = null; no(new Error(t.error)); };
				document.head.appendChild(s);
			});
		}
		return dailyP;
	}

	// Before the room opens: a countdown, then the button.
	var opensAt = cfg.opens - (cfg.owner ? 3600000 : 0);
	function tick() {
		var left = opensAt - Date.now();
		if (left <= 0) {
			joinBtn.disabled = false;
			if (cfg.phase === 'before') {
				cfg.phase = 'open';
				status.textContent = t.openNow;
			}
			return;
		}
		var min = Math.ceil(left / 60000);
		var label = min >= 120 ? Math.floor(min / 60) + ':' + ('0' + (min % 60)).slice(-2) + ' h' : min + ' ' + (t.min || 'min');
		status.textContent = (t.opensIn || '%s').replace('%s', label);
		setTimeout(tick, 30000);
	}
	if (cfg.phase === 'before') {
		tick();
	}

	function at(ms, fn) {
		var id = setTimeout(fn, Math.max(0, ms - Date.now()));
		timers.push(id);
	}

	function close() {
		timers.forEach(clearTimeout);
		timers = [];
		if (!frame) {
			return closing;
		}
		var f = frame;
		frame = null;
		try {
			var r = f.destroy();
			if (r && r.then) {
				closing = Promise.race([r, new Promise(function (ok) { setTimeout(ok, 3000); })]).then(function () {}, function () {});
			}
		} catch (e) { /* already closed */ }
		return closing;
	}

	function showLobby(msg) {
		stage.hidden = true;
		lobby.hidden = false;
		if (ownerBar) {
			ownerBar.hidden = true;
		}
		joinBtn.disabled = false;
		joinBtn.textContent = t.join;
		if (msg) {
			status.textContent = msg;
		}
	}

	function setRec(on) {
		if (!recState) {
			return;
		}
		recState.textContent = on ? t.recOn : (Date.now() < cfg.start ? t.recSoon : t.recOff);
		recState.className = on ? 'oys-live__rec is-on' : 'oys-live__rec';
		recBtn.hidden = false;
		recBtn.textContent = on ? t.recStop : t.recStart;
		recBtn.setAttribute('data-on', on ? '1' : '');
	}

	function ownerTools(f, join) {
		var local = (f.participants() || {}).local || {};
		var me = local.session_id;
		if (join.record && ownerBar) {
			ownerBar.hidden = false;
			setRec(false);
			var startRec = function () {
				try {
					// Only the teacher is on the recording: the participants never are.
					f.startRecording({ layout: { preset: 'single-participant', session_id: me } });
				} catch (e) { /* not allowed, or already running */ }
			};
			at(join.start, startRec);
			recBtn.onclick = function () {
				try {
					recBtn.getAttribute('data-on') ? f.stopRecording() : startRec();
				} catch (e) { /* ignore */ }
			};
			f.on('recording-started', function () { setRec(true); });
			f.on('recording-stopped', function () { setRec(false); });
			f.on('recording-error', function () { setRec(false); });
		}
		if (join.intro) {
			var until = join.intro.start + join.intro.minutes * 60000;
			var opts = { language: join.intro.language, model: join.intro.model, punctuate: true, participants: [me] };
			var fellBack = false;
			var start = function () {
				if (Date.now() >= until) {
					return;
				}
				try {
					var r = f.startTranscription(opts);
					if (r && r.catch) {
						r.catch(function () {});
					}
				} catch (e) { /* already running */ }
			};
			f.on('transcription-error', function () {
				if (fellBack || !frame) {
					return;
				}
				fellBack = true;
				opts = Object.assign({}, opts, { model: 'nova-2-general' });
				start();
			});
			at(join.intro.start, start);
			at(until, function () {
				try {
					f.stopTranscription();
				} catch (e) { /* not running */ }
			});
		}
	}

	joinBtn.addEventListener('click', function () {
		joinBtn.disabled = true;
		joinBtn.textContent = t.joining;
		var join;
		api('/join', { method: 'POST' }).then(function (j) {
			join = j;
			return loadDaily();
		}).then(function (Daily) {
			return close().then(function () { return Daily; });
		}).then(function (Daily) {
			lobby.hidden = true;
			stage.hidden = false;
			var f = Daily.createFrame(stage, {
				iframeStyle: { width: '100%', height: '100%', border: '0' },
				showLeaveButton: true,
				showFullscreenButton: true
			});
			frame = f;
			if (join.owner) {
				f.on('joined-meeting', function () { ownerTools(f, join); });
			}
			f.on('left-meeting', function () {
				setTimeout(function () {
					close();
					showLobby(t.left);
				}, 0);
			});
			f.on('error', function (ev) {
				close();
				showLobby((ev && ev.errorMsg) || t.error);
			});
			return f.join({ url: join.url, token: join.token });
		}).catch(function (err) {
			close();
			showLobby(err.message || t.error);
		});
	});
})();
