/*
 * oliviajoga.hu – „Egy korszak lezárult” in motion (Áron, 2026-11-12: „mozogjon és legyen menő”):
 * the brush stroke of her logo flows across the photos, and the photos light up in colour one by one.
 * Only while the grid is on screen; nothing moves for people who asked for less motion.
 */
(function () {
	'use strict';
	if (!('IntersectionObserver' in window)) { return; }
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }

	// the same stroke as the build (build.py brush_d): thin at both ends, thick in the middle
	function brush(w, h, amp, th, periods, phase, n) {
		var top = [], bot = [], i, t, x, y, d;
		for (i = 0; i <= n; i++) {
			t = i / n;
			x = w * t;
			y = h / 2 + amp * Math.sin(2 * Math.PI * periods * t + phase);
			d = (0.1 + 0.9 * Math.pow(Math.sin(Math.PI * t), 1.15)) * th / 2;
			top.push(x.toFixed(1) + ' ' + (y - d).toFixed(1));
			bot.push(x.toFixed(1) + ' ' + (y + d).toFixed(1));
		}
		return 'M' + top.join(' L') + ' L' + bot.reverse().join(' L') + 'Z';
	}

	document.querySelectorAll('.oj-korszak').forEach(function (box) {
		var svg = box.querySelector('.oj-band--flow');
		var back = svg && svg.querySelector('.oj-blob__back');
		var front = svg && svg.querySelector('.oj-blob__front');
		var cells = Array.prototype.slice.call(box.querySelectorAll('.oj-korszak__cell'));
		var on = false, raf = 0, timer = 0, t0 = 0, lit = null;

		function frame(now) {
			if (!t0) { t0 = now; }
			var s = (now - t0) / 1000;
			back.setAttribute('d', brush(1000, 100, 24 + 5 * Math.sin(s * 0.6), 30, 2.5, -s * 0.9, 72));
			front.setAttribute('d', brush(1000, 100, 20 + 4 * Math.sin(s * 0.8 + 1), 11, 2.5, 0.8 - s * 1.25, 72));
			raf = window.requestAnimationFrame(frame);
		}

		function light() { // one photo in colour at a time, in a random order (the visible ones)
			var shown = cells.filter(function (c) { return c.offsetParent !== null && c !== lit; });
			if (!shown.length) { return; }
			if (lit) { lit.classList.remove('is-lit'); }
			lit = shown[Math.floor(Math.random() * shown.length)];
			lit.classList.add('is-lit');
		}

		new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (e.isIntersecting && !on) {
					on = true;
					if (back && front) { raf = window.requestAnimationFrame(frame); }
					window.setTimeout(light, 1200);
					timer = window.setInterval(light, 1800);
				} else if (!e.isIntersecting && on) {
					on = false;
					window.cancelAnimationFrame(raf);
					window.clearInterval(timer);
				}
			});
		}, { threshold: 0.12 }).observe(box);
	});
}());
