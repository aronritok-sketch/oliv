/**
 * The live page of an online class (OYS_Live, HathaFlow Live): the video over LiveKit, the class chat that stays after
 * class, and for the teacher the recording of herself only and the transcript of the opening words.
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
			if (j.hands && typeof window.oysLiveHands === 'function') {
				window.oysLiveHands(j.hands);
			}
			if (typeof j.hand === 'boolean' && typeof window.oysLiveMyHand === 'function') {
				window.oysLiveMyHand(j.hand); // the teacher may have lowered it
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

	// The recording is served by the site itself (only for the people who booked): play it in place.
	root.querySelectorAll('[data-live-replay]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var box = q('[data-live-player]');
			box.innerHTML = '';
			var v = document.createElement('video');
			v.controls = true;
			v.playsInline = true;
			v.preload = 'metadata';
			v.className = 'oys-live__player';
			var src = document.createElement('source');
			src.src = btn.getAttribute('data-live-replay');
			src.type = (btn.getAttribute('data-mime') || 'video/webm').split(';')[0];
			v.appendChild(src);
			box.appendChild(v);
			v.play().catch(function () {});
		});
	});

	/* ---------- Video (LiveKit) ---------- */

	var joinBtn = q('[data-live-join]');
	if (!joinBtn) {
		return;
	}
	var lobby = q('[data-live-lobby]');
	var stage = q('[data-live-video]');
	var mainEl = q('[data-live-main]');
	var tilesEl = q('[data-live-tiles]');
	var barEl = q('[data-live-bar]');
	var noteEl = q('[data-live-note]');
	var status = q('[data-live-status]');
	var ownerBar = q('[data-live-owner]');
	var recBtn = q('[data-live-rec]');
	var recState = q('[data-live-rec-state]');
	var introEl = q('[data-live-intro]');
	var LK = null;
	if (cfg.owner && lobby && policyBlocks()) { // say it before she tries: the browser will not even ask
		var warn = document.createElement('p');
		warn.className = 'oys-live__warn';
		warn.textContent = t.blocked;
		lobby.insertBefore(warn, lobby.firstChild);
	}
	var lkP = null;
	var room = null;
	var join = null;
	var timers = [];
	var hands = {};
	var myHand = false;
	var handAt = 0; // a tap on the hand button wins over a poll that was already on its way

	function loadLK() {
		if (window.LivekitClient) {
			return Promise.resolve(window.LivekitClient);
		}
		if (!lkP) {
			lkP = new Promise(function (ok, no) {
				var s = document.createElement('script');
				s.src = cfg.lk;
				s.async = true;
				s.onload = function () { window.LivekitClient ? ok(window.LivekitClient) : no(new Error(t.error)); };
				s.onerror = function () { lkP = null; no(new Error(t.error)); };
				document.head.appendChild(s);
			});
		}
		return lkP;
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
		timers.push(setTimeout(fn, Math.max(0, ms - Date.now())));
	}

	function note(text, ms) {
		noteEl.textContent = text || '';
		noteEl.hidden = !text;
		if (text && ms) {
			timers.push(setTimeout(function () { if (noteEl.textContent === text) { noteEl.hidden = true; } }, ms));
		}
	}

	function isTeacher(p) {
		return !!p && (String(p.identity || '').indexOf('teacher-') === 0);
	}

	function button(label, cls, onClick) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'oys-live__btn ' + (cls || '');
		b.textContent = label;
		b.addEventListener('click', onClick);
		barEl.appendChild(b);
		return b;
	}

	/* Tiles: the teacher big for the students (and her own picture for her); the students in a grid for the teacher. */

	function tileFor(p, local) {
		var id = 'oys-tile-' + p.identity.replace(/[^a-z0-9_-]/gi, '');
		var el = document.getElementById(id);
		if (!el) {
			el = document.createElement('figure');
			el.id = id;
			el.className = 'oys-live__tile';
			var cap = document.createElement('figcaption');
			cap.textContent = local ? t.you : (p.name || p.identity);
			el.appendChild(cap);
			var hand = document.createElement(cfg.owner ? 'button' : 'span');
			hand.className = 'oys-live__hand';
			hand.textContent = '✋';
			hand.hidden = !hands[p.identity];
			if (cfg.owner) { // the teacher lowers a hand with a tap
				hand.type = 'button';
				hand.title = t.handLower || '';
				hand.setAttribute('aria-label', (t.handLower || '') + ' – ' + (p.name || p.identity));
				hand.addEventListener('click', function () {
					setHand(p.identity, false);
					api('/hand', { method: 'POST', body: { up: false, identity: p.identity } }).catch(function () {});
				});
			}
			el.appendChild(hand);
		}
		return el;
	}

	function placeFor(p, local) {
		if (isTeacher(p)) {
			return mainEl; // the teacher is always the big picture (for herself: her own preview)
		}
		return tilesEl;
	}

	function attachVideo(track, p, local) {
		var el = tileFor(p, local);
		var place = placeFor(p, local);
		if (el.parentNode !== place) {
			place.appendChild(el);
		}
		var old = el.querySelector('video');
		if (old) {
			old.remove();
		}
		var v = track.attach();
		v.muted = true;
		v.playsInline = true;
		if (local) {
			v.classList.add('is-mirror');
		}
		el.insertBefore(v, el.firstChild);
		el.classList.add('has-video');
	}

	function detachVideo(p) {
		var el = document.getElementById('oys-tile-' + p.identity.replace(/[^a-z0-9_-]/gi, ''));
		if (el) {
			var v = el.querySelector('video');
			if (v) {
				v.remove();
			}
			el.classList.remove('has-video');
		}
	}

	function ensureTile(p, local) {
		var el = tileFor(p, local);
		var place = placeFor(p, local);
		if (el.parentNode !== place) {
			place.appendChild(el);
		}
	}

	function removeTile(p) {
		var el = document.getElementById('oys-tile-' + p.identity.replace(/[^a-z0-9_-]/gi, ''));
		if (el) {
			el.remove();
		}
	}

	function refresh() {
		if (!room) {
			return;
		}
		var teacherHere = cfg.owner || Array.from(room.remoteParticipants.values()).some(isTeacher);
		mainEl.classList.toggle('is-waiting', !teacherHere);
		mainEl.setAttribute('data-waiting', teacherHere ? '' : t.waiting);
		if (cfg.owner && ownerBar) {
			var people = ownerBar.querySelector('.oys-live__people');
			if (!people) {
				people = document.createElement('span');
				people.className = 'oys-live__people';
				ownerBar.insertBefore(people, ownerBar.firstChild);
			}
			people.textContent = (t.people || '%d').replace('%d', room.remoteParticipants.size);
		}
	}

	// the teacher's chat poll brings the raised hands: { identity: name }
	window.oysLiveHands = function (list) {
		Object.keys(hands).forEach(function (id) {
			if (hands[id] && !(id in list)) {
				setHand(id, false);
			}
		});
		Object.keys(list).forEach(function (id) {
			if (!hands[id]) {
				setHand(id, true, list[id]);
			}
		});
	};

	function setHand(identity, up, name) {
		hands[identity] = up;
		var el = document.getElementById('oys-tile-' + identity.replace(/[^a-z0-9_-]/gi, ''));
		if (el) {
			el.querySelector('.oys-live__hand').hidden = !up;
		}
		if (cfg.owner && up) {
			note((t.handUp || '%s').replace('%s', name || identity), 8000);
		}
	}

	/* Recording: the teacher's own camera and microphone, in her browser, uploaded in pieces while she teaches. */

	var rec = { on: false, recorder: null, id: '', seq: 0, queue: [], busy: false, started: 0, wanted: false };

	function recMime() {
		var list = ['video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
		for (var i = 0; i < list.length; i++) {
			if (window.MediaRecorder && MediaRecorder.isTypeSupported(list[i])) {
				return list[i];
			}
		}
		return '';
	}

	function setRec(on, text) {
		if (!recState) {
			return;
		}
		recState.textContent = text || (on ? t.recOn : (Date.now() < join.start ? t.recSoon : t.recOff));
		recState.className = on ? 'oys-live__rec is-on' : 'oys-live__rec';
		recBtn.hidden = false;
		recBtn.textContent = on ? t.recStop : t.recStart;
	}

	function upload() {
		if (rec.busy || !rec.queue.length) {
			return;
		}
		rec.busy = true;
		var item = rec.queue[0];
		var url = cfg.api + '/rec/' + item.id + '/chunk?seq=' + item.seq;
		var headers = { 'Content-Type': 'application/octet-stream' };
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}
		fetch(url, { method: 'POST', headers: headers, credentials: 'same-origin', body: item.blob }).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok && !(j && j.data && typeof j.data.seq === 'number' && j.data.seq > item.seq)) {
					throw new Error('upload');
				}
			});
		}).then(function () {
			rec.queue.shift();
			rec.busy = false;
			if (item.last) {
				api('/rec/' + item.id + '/stop', { method: 'POST', body: { seconds: item.seconds } }).catch(function () {});
				if (!rec.on) {
					setRec(false);
				}
			}
			upload();
		}, function () {
			rec.busy = false;
			timers.push(setTimeout(upload, 4000)); // try the same piece again
		});
	}

	/* Why the camera or microphone did not start: the site's server forbids them (Permissions-Policy), the browser
	   was told no, there is none, or another program holds the camera. */
	function policyBlocks() {
		var pp = document.permissionsPolicy || document.featurePolicy;
		try {
			return !!(pp && pp.allowsFeature && (!pp.allowsFeature('camera') || !pp.allowsFeature('microphone')));
		} catch (e) {
			return false;
		}
	}

	function deviceError(err) {
		if (policyBlocks()) {
			return t.blocked;
		}
		var kind = LK && LK.MediaDeviceFailure && LK.MediaDeviceFailure.getFailure ? LK.MediaDeviceFailure.getFailure(err) : '';
		var name = (err && err.name) || '';
		if (kind === 'PermissionDenied' || name === 'NotAllowedError' || name === 'SecurityError') {
			return t.denied;
		}
		if (kind === 'NotFound' || name === 'NotFoundError' || name === 'OverconstrainedError') {
			return t.notFound;
		}
		if (kind === 'DeviceInUse' || name === 'NotReadableError' || name === 'AbortError') {
			return t.inUse;
		}
		return t.noDevice;
	}

	function startRec() {
		if (rec.on || !room) {
			return;
		}
		if (!window.MediaRecorder) {
			setRec(false, t.recNoSup);
			return;
		}
		var lp = room.localParticipant;
		var cam = lp.getTrackPublication(LK.Track.Source.Camera);
		var mic = lp.getTrackPublication(LK.Track.Source.Microphone);
		var tracks = [];
		if (cam && cam.track && cam.track.mediaStreamTrack) {
			tracks.push(cam.track.mediaStreamTrack);
		}
		if (mic && mic.track && mic.track.mediaStreamTrack) {
			tracks.push(mic.track.mediaStreamTrack);
		}
		if (!tracks.length) {
			setRec(false, policyBlocks() ? t.blocked : t.recNoAv);
			return;
		}
		var mime = recMime();
		var recorder;
		try {
			recorder = new MediaRecorder(new MediaStream(tracks), mime ? { mimeType: mime, videoBitsPerSecond: 1200000, audioBitsPerSecond: 96000 } : {});
		} catch (e) {
			setRec(false, t.recNoSup);
			return;
		}
		rec.on = true;
		rec.wanted = true;
		rec.started = Date.now();
		setRec(true);
		api('/rec', { method: 'POST', body: { mime: recorder.mimeType || mime || 'video/webm' } }).then(function (j) {
			rec.id = j.id;
			rec.seq = 0;
			var id = j.id;
			recorder.ondataavailable = function (e) {
				if (e.data && e.data.size) {
					rec.queue.push({ id: id, seq: rec.seq++, blob: e.data });
					upload();
				}
			};
			recorder.onstop = function () {
				rec.on = false;
				rec.recorder = null;
				var seconds = Math.round((Date.now() - rec.started) / 1000);
				// the last piece arrives before onstop; mark it so the recording is closed after it
				if (rec.queue.length) {
					rec.queue[rec.queue.length - 1].last = true;
					rec.queue[rec.queue.length - 1].seconds = seconds;
					setRec(false, t.recUp);
				} else {
					api('/rec/' + id + '/stop', { method: 'POST', body: { seconds: seconds } }).catch(function () {});
					setRec(false);
				}
			};
			recorder.start(10000);
			rec.recorder = recorder;
		}, function (err) {
			rec.on = false;
			setRec(false, err.message);
		});
	}

	function stopRec() {
		rec.wanted = false;
		if (rec.recorder && rec.recorder.state !== 'inactive') {
			rec.recorder.stop();
		}
	}

	window.addEventListener('beforeunload', function (e) {
		if (rec.on || rec.queue.length) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	/* Opening words: speech recognition in the teacher's browser, for the first minutes of the class. */

	function startIntro(intro) {
		var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
		if (!SR || !intro) {
			return;
		}
		var until = intro.start + intro.minutes * 60000;
		var buffer = '';
		var sr = null;
		var flush = function () {
			if (buffer.trim()) {
				var text = buffer;
				buffer = '';
				api('/transcript', { method: 'POST', body: { text: text } }).catch(function () { buffer = text + ' ' + buffer; });
			}
		};
		var run = function () {
			if (Date.now() >= until || !room) {
				return;
			}
			sr = new SR();
			sr.lang = intro.lang;
			sr.continuous = true;
			sr.interimResults = false;
			sr.onresult = function (e) {
				for (var i = e.resultIndex; i < e.results.length; i++) {
					if (e.results[i].isFinal) {
						buffer += ' ' + e.results[i][0].transcript;
					}
				}
			};
			sr.onend = function () {
				if (Date.now() < until && room) {
					setTimeout(run, 300); // it stops after silence: go on
				}
			};
			try {
				sr.start();
				introEl && (introEl.textContent = t.listening);
			} catch (e) { /* already running */ }
		};
		at(intro.start, run);
		var iv = setInterval(flush, 20000);
		timers.push(iv);
		at(until, function () {
			clearInterval(iv);
			try { sr && sr.stop(); } catch (e) { /* stopped */ }
			flush();
			introEl && (introEl.textContent = '');
		});
	}

	/* Joining and leaving */

	/* While in the class the room fills the browser window (small laptops: everything on one screen, no scrolling),
	   and the full screen button hides the browser too. */
	var head = q('.oys-live__head');
	var fsBtn = null;
	function fsOn() {
		return !!(document.fullscreenElement || document.webkitFullscreenElement);
	}
	function fsLabel() {
		if (fsBtn) {
			fsBtn.textContent = fsOn() ? t.fullExit : t.full;
		}
	}
	if (head && (root.requestFullscreen || root.webkitRequestFullscreen)) {
		fsBtn = document.createElement('button');
		fsBtn.type = 'button';
		fsBtn.className = 'oys-live__fs';
		fsBtn.hidden = true;
		fsBtn.addEventListener('click', function () {
			if (fsOn()) {
				(document.exitFullscreen || document.webkitExitFullscreen).call(document);
			} else {
				var go = root.requestFullscreen || root.webkitRequestFullscreen;
				var r = go.call(root);
				if (r && r.catch) {
					r.catch(function () {});
				}
			}
		});
		document.addEventListener('fullscreenchange', fsLabel);
		document.addEventListener('webkitfullscreenchange', fsLabel);
		fsLabel();
		head.appendChild(fsBtn);
	}
	function stageMode(on) {
		root.classList.toggle('is-live', on);
		document.documentElement.classList.toggle('oys-live-open', on);
		if (fsBtn) {
			fsBtn.hidden = !on;
		}
		if (!on && fsOn()) {
			(document.exitFullscreen || document.webkitExitFullscreen).call(document);
		}
	}

	function showLobby(msg) {
		stageMode(false);
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

	function leave() {
		timers.forEach(function (x) { clearTimeout(x); clearInterval(x); });
		timers = [];
		stopRec();
		if (room) {
			var r = room;
			room = null;
			r.disconnect();
		}
		mainEl.innerHTML = '';
		tilesEl.innerHTML = '';
		barEl.innerHTML = '';
	}

	function controls() {
		barEl.innerHTML = '';
		var lp = room.localParticipant;
		var canSend = cfg.owner || join.twoWay;
		if (canSend) {
			var mic = button(t.micOn, 'is-mic', function () {
				lp.setMicrophoneEnabled(!lp.isMicrophoneEnabled).then(sync, function (e) { note(deviceError(e), 12000); });
			});
			var cam = button(t.camOn, 'is-cam', function () {
				lp.setCameraEnabled(!lp.isCameraEnabled).then(sync, function (e) { note(deviceError(e), 12000); });
			});
			var sync = function () {
				mic.textContent = lp.isMicrophoneEnabled ? t.micOn : t.micOff;
				mic.classList.toggle('is-off', !lp.isMicrophoneEnabled);
				cam.textContent = lp.isCameraEnabled ? t.camOn : t.camOff;
				cam.classList.toggle('is-off', !lp.isCameraEnabled);
				if (!lp.isCameraEnabled) {
					detachVideo(lp);
				}
			};
			sync();
			room.on(LK.RoomEvent.LocalTrackPublished, sync).on(LK.RoomEvent.LocalTrackUnpublished, sync).on(LK.RoomEvent.TrackMuted, sync).on(LK.RoomEvent.TrackUnmuted, sync);
		}
		if (!cfg.owner) {
			var showHand = function () {
				hand.textContent = myHand ? t.handDown : t.hand;
				hand.classList.toggle('is-on', myHand);
			};
			var hand = button(t.hand, 'is-hand', function () {
				myHand = !myHand;
				handAt = Date.now();
				showHand();
				// through our server, like the chat: the teacher sees it within seconds, even if she comes in later
				api('/hand', { method: 'POST', body: { up: myHand } }).catch(function () {});
			});
			window.oysLiveMyHand = function (up) {
				if (up !== myHand && Date.now() - handAt > 10000) {
					myHand = up;
					showHand();
				}
			};
		} else {
			button(t.muteAll, 'is-muteall', function () {
				api('/mute-all', { method: 'POST' }).then(function () { note(t.mutedAll, 4000); }, function (err) { note(err.message, 6000); });
			});
		}
		button(t.leave, 'is-leave', function () {
			leave();
			showLobby(t.left);
		});
	}

	function onTrack(track, pub, p) {
		if (track.kind === 'video') {
			attachVideo(track, p, false);
		} else if (track.kind === 'audio') {
			var a = track.attach();
			a.setAttribute('data-for', p.identity);
			stage.appendChild(a);
		}
		refresh();
	}

	function subscribeTeacher(p) {
		// the students only take the teacher's picture and sound (big classes: nobody else's)
		if (!cfg.owner && isTeacher(p)) {
			p.trackPublications.forEach(function (pub) {
				pub.setSubscribed(true);
			});
		}
	}

	joinBtn.addEventListener('click', function () {
		joinBtn.disabled = true;
		joinBtn.textContent = t.joining;
		api('/join', { method: 'POST' }).then(function (j) {
			join = j;
			return loadLK();
		}).then(function (L) {
			LK = L;
			leave();
			room = new LK.Room({
				adaptiveStream: true,
				dynacast: true,
				videoCaptureDefaults: { resolution: LK.VideoPresets.h720.resolution },
				publishDefaults: { simulcast: true }
			});
			room
				.on(LK.RoomEvent.TrackSubscribed, onTrack)
				.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, p) {
					track.detach().forEach(function (el) { el.remove(); });
					if (track.kind === 'video') {
						detachVideo(p);
					}
				})
				.on(LK.RoomEvent.TrackPublished, function (pub, p) {
					subscribeTeacher(p);
				})
				.on(LK.RoomEvent.ParticipantConnected, function (p) {
					if (cfg.owner || isTeacher(p)) {
						ensureTile(p, false);
					}
					subscribeTeacher(p);
					refresh();
				})
				.on(LK.RoomEvent.ParticipantDisconnected, function (p) {
					removeTile(p);
					refresh();
				})
				.on(LK.RoomEvent.LocalTrackPublished, function (pub) {
					if (pub.track && pub.track.kind === 'video') {
						attachVideo(pub.track, room.localParticipant, true);
					}
					if (cfg.owner && join.record && rec.wanted && !rec.on && Date.now() >= join.start) {
						startRec(); // the camera came back on: the recording goes on in a new part
					}
				})

				.on(LK.RoomEvent.Reconnecting, function () { note(t.reconnect); })
				.on(LK.RoomEvent.Reconnected, function () { note(''); })
				.on(LK.RoomEvent.Disconnected, function () {
					if (room) {
						leave();
						showLobby(t.left);
					}
				});
			return room.connect(join.url, join.token, { autoSubscribe: !!cfg.owner }).then(function () {
				lobby.hidden = true;
				stage.hidden = false;
				stageMode(true);
				room.startAudio().catch(function () {});
				room.remoteParticipants.forEach(function (p) {
					if (cfg.owner || isTeacher(p)) {
						ensureTile(p, false);
					}
					subscribeTeacher(p);
				});
				if (cfg.owner) {
					ensureTile(room.localParticipant, true);
				}
				controls();
				refresh();
				var publish = cfg.owner ? room.localParticipant.enableCameraAndMicrophone() : Promise.resolve();
				return publish.catch(function (e) { note(deviceError(e)); }).then(function () {
					if (cfg.owner) {
						ownerBar.hidden = false;
						if (join.record) {
							setRec(false);
							rec.wanted = true;
							at(join.start, startRec);
							recBtn.onclick = function () {
								rec.on ? stopRec() : startRec();
							};
						}
						startIntro(join.intro);
					}
				});
			});
		}).catch(function (err) {
			leave();
			showLobby(err.message || t.error);
		});
	});
})();
