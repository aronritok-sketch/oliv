/*
 * „Check this device” (the live class test lab, Áron, 2026-11-26): LiveKit's own connection test on this phone or
 * computer – the server, WebRTC, TURN, getting back in, sending sound and picture, UDP/TCP, the nearest data centre – and
 * what the browser can do (camera, recording, speech recognition, full screen). The result goes to the test page.
 */
(function () {
	'use strict';
	var boxes = document.querySelectorAll('[data-oys-diag]');
	if (!boxes.length) { return; }

	function loadLK(src) {
		if (window.LivekitClient) { return Promise.resolve(window.LivekitClient); }
		return new Promise(function (ok, no) {
			var s = document.createElement('script');
			s.src = src;
			s.onload = function () { window.LivekitClient ? ok(window.LivekitClient) : no(new Error('LiveKit')); };
			s.onerror = function () { no(new Error('LiveKit')); };
			document.head.appendChild(s);
		});
	}

	function device() {
		var ua = navigator.userAgent, m, os, br;
		var ipad = /iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		os = /iPhone/.test(ua) ? 'iPhone' : ipad ? 'iPad' : /Android/.test(ua) ? 'Android' : /Mac OS X/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /CrOS/.test(ua) ? 'ChromeOS' : /Linux/.test(ua) ? 'Linux' : '';
		if ((m = /EdgA?\/(\d+)|EdgiOS\/(\d+)/.exec(ua))) { br = 'Edge ' + (m[1] || m[2]); }
		else if ((m = /SamsungBrowser\/(\d+)/.exec(ua))) { br = 'Samsung Internet ' + m[1]; }
		else if ((m = /CriOS\/(\d+)/.exec(ua))) { br = 'Chrome ' + m[1]; }
		else if ((m = /FxiOS\/(\d+)|Firefox\/(\d+)/.exec(ua))) { br = 'Firefox ' + (m[1] || m[2]); }
		else if ((m = /Chrome\/(\d+)/.exec(ua))) { br = 'Chrome ' + m[1]; }
		else if ((m = /Version\/(\d+(\.\d+)?).*Safari/.exec(ua))) { br = 'Safari ' + m[1]; }
		else { br = 'Browser'; }
		return (os ? os + ' · ' : '') + br;
	}

	function caps() {
		var types = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4;codecs=avc1,mp4a', 'video/mp4'];
		var rec = window.MediaRecorder ? types.filter(function (x) { try { return window.MediaRecorder.isTypeSupported(x); } catch (e) { return false; } }) : [];
		return {
			secure: !!window.isSecureContext,
			webrtc: !!window.RTCPeerConnection,
			media: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
			recorder: rec.length ? rec[0] : '',
			speech: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
			fullscreen: !!(document.fullscreenEnabled || document.webkitFullscreenEnabled || (document.createElement('video').webkitEnterFullscreen))
		};
	}

	boxes.forEach(function (box) {
		var cfg;
		try { cfg = JSON.parse(box.getAttribute('data-oys-diag')); } catch (e) { return; }
		var t = cfg.i18n || {};
		var btn = box.querySelector('[data-diag-run]'), list = box.querySelector('[data-diag-list]'), sum = box.querySelector('[data-diag-sum]');
		if (!btn || !list) { return; }

		function line(text, state, note) {
			var li = document.createElement('li');
			li.className = 'is-' + state;
			li.textContent = (state === 'ok' ? '✓ ' : state === 'bad' ? '✗ ' : state === 'run' ? '… ' : '– ') + text + (note ? ' – ' + note : '');
			list.appendChild(li);
			return li;
		}

		function statusOf(n, LK) {
			var S = LK.CheckStatus || {};
			return n === S.SUCCESS ? 'success' : n === S.FAILED ? 'failed' : n === S.SKIPPED ? 'skipped' : n === S.RUNNING ? 'running' : 'idle';
		}

		function lastLog(info) {
			var logs = (info && info.logs) || [], i;
			for (i = logs.length - 1; i >= 0; i--) {
				if (logs[i] && logs[i].level === 'error') { return String(logs[i].message || ''); }
			}
			return logs.length ? String(logs[logs.length - 1].message || '') : '';
		}

		function send(report) {
			var headers = { 'Content-Type': 'application/json' };
			if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
			var url = cfg.report + (cfg.report.indexOf('?') < 0 ? '?' : '&') + 'b=' + encodeURIComponent(cfg.b || '') + '&k=' + encodeURIComponent(cfg.k || '');
			return fetch(url, { method: 'POST', headers: headers, credentials: 'same-origin', body: JSON.stringify(report) }).then(function (r) {
				return r.ok;
			}, function () { return false; });
		}

		btn.addEventListener('click', function () {
			btn.disabled = true;
			list.innerHTML = '';
			sum.textContent = t.running || '…';
			var c = caps();
			var report = { device: device(), caps: c, checks: [] };
			Object.keys(t.caps || {}).forEach(function (k) {
				var v = c[k];
				var must = k === 'secure' || k === 'webrtc' || k === 'media';
				line(t.caps[k], v ? 'ok' : (must ? 'bad' : 'skip'), k === 'recorder' && v ? String(v).split(';')[0] : (v ? '' : (t.no || 'no')));
			});
			var rows = {};
			loadLK(cfg.lk).then(function (LK) {
				var cc = new LK.ConnectionCheck(cfg.url, cfg.token);
				cc.on('checkUpdate', function (id, info) {
					var label = (t.checks && t.checks[info.description]) || info.description || info.name || '';
					var st = statusOf(info.status, LK);
					var li = rows[id] || (rows[id] = line(label, 'run'));
					li.className = 'is-' + (st === 'success' ? 'ok' : st === 'failed' ? 'bad' : st === 'running' ? 'run' : 'skip');
					li.textContent = (st === 'success' ? '✓ ' : st === 'failed' ? '✗ ' : st === 'running' ? '… ' : '– ') + label + (st === 'failed' || st === 'success' ? (lastLog(info) ? ' – ' + lastLog(info) : '') : '');
				});
				var seq = ['checkWebsocket', 'checkWebRTC', 'checkTURN', 'checkReconnect', 'checkPublishAudio', 'checkPublishVideo', 'checkConnectionProtocol', 'checkCloudRegion'];
				return seq.reduce(function (p, m) {
					return p.then(function () {
						return typeof cc[m] === 'function' ? cc[m]().catch(function () {}) : null;
					});
				}, Promise.resolve()).then(function () {
					(cc.getResults() || []).forEach(function (info) {
						report.checks.push({ name: info.description || info.name || '', status: statusOf(info.status, LK), note: lastLog(info).slice(0, 200) });
					});
				});
			}).then(function () {
				var failed = report.checks.some(function (x) { return x.status === 'failed'; });
				report.ok = !failed && c.webrtc && c.media;
				sum.textContent = report.ok ? t.allOk : t.someBad;
				return send(report).then(function (ok) {
					if (ok && t.sent) { sum.textContent += ' ' + t.sent; }
				});
			}).catch(function () {
				sum.textContent = t.error || 'Error';
				report.checks.push({ name: 'LiveKit', status: 'failed', note: 'could not start' });
				report.ok = false;
				send(report);
			}).then(function () {
				btn.disabled = false;
				btn.textContent = t.again || btn.textContent;
			});
		});
	});
})();
