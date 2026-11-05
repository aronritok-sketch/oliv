/* oliviajoga.hu: a little motion on top of the theme's site.js (Olivia: "vigyünk bele egy kis animálást").
   - Gyakori kérdések: one answer open at a time.
   - [data-drift]: photos move a little slower than the page while scrolling.
   Nothing moves when the visitor asks for reduced motion. */
(function () {
	'use strict';
	var faq = document.querySelector('.oj-faq');
	if (faq) {
		faq.addEventListener('toggle', function (e) {
			var d = e.target;
			if (!d.open) return;
			faq.querySelectorAll('details[open]').forEach(function (o) { if (o !== d) o.open = false; });
		}, true);
	}
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
	var drift = [].slice.call(document.querySelectorAll('[data-drift]'));
	if (!drift.length) return;
	var ticking = false;
	function update() {
		var vh = window.innerHeight;
		drift.forEach(function (el) {
			var r = el.getBoundingClientRect();
			if (r.bottom < 0 || r.top > vh) return;
			var k = parseFloat(el.getAttribute('data-drift')) || 0.06;
			var off = Math.max(-14, Math.min(14, (r.top + r.height / 2 - vh / 2) * -k));
			el.style.setProperty('--drift', off.toFixed(1) + 'px');
		});
		ticking = false;
	}
	window.addEventListener('scroll', function () {
		if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
	}, { passive: true });
	update();
}());
