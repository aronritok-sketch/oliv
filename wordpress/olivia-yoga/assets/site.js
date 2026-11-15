/* Olivia Kovacs Yoga: interactions. No dependencies. */
(function () {
	'use strict';
	var doc = document.documentElement;
	var body = document.body;
	doc.classList.add('js');
	var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
	var desktop = window.matchMedia('(min-width: 960px) and (hover: hover) and (pointer: fine)');

	/* Header shadow + mobile action bar */
	var header = document.querySelector('[data-header]');
	var bar = document.querySelector('[data-action-bar]');
	function onScroll() {
		var y = window.scrollY;
		if (header) header.classList.toggle('is-scrolled', y > 8);
		if (bar) {
			var nearBottom = window.innerHeight + y >= document.body.scrollHeight - 60;
			bar.classList.toggle('is-visible', y > window.innerHeight * 0.55 && !nearBottom);
		}
	}
	window.addEventListener('scroll', onScroll, { passive: true });

	/* Mobile drawer */
	var toggle = document.querySelector('[data-nav-toggle]');
	function setNav(open) {
		body.classList.toggle('nav-open', open);
		if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
	}
	if (toggle) toggle.addEventListener('click', function () { setNav(!body.classList.contains('nav-open')); });
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		if (body.classList.contains('nav-open')) { setNav(false); toggle && toggle.focus(); }
		document.querySelectorAll('.has-sub.is-open, .menu-item-has-children.is-open').forEach(function (li) { li.classList.remove('is-open'); });
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a');
		if (a && body.classList.contains('nav-open')) setNav(false);
		var t = e.target.closest('.sub-toggle');
		if (t) {
			var li = t.closest('.has-sub, .menu-item-has-children');
			var open = !li.classList.contains('is-open');
			li.classList.toggle('is-open', open);
			t.setAttribute('aria-expanded', open ? 'true' : 'false');
		} else if (!e.target.closest('.has-sub, .menu-item-has-children')) {
			document.querySelectorAll('.has-sub.is-open, .menu-item-has-children.is-open').forEach(function (li) { li.classList.remove('is-open'); });
		}
	});

	/* Reveal on scroll + brush underline drawing */
	var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
		entries.forEach(function (en) {
			if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
		});
	}, { rootMargin: '0px 0px -10% 0px' }) : null;
	function observeAll(root) {
		(root || document).querySelectorAll('[data-reveal], .brush').forEach(function (el) {
			if (io && !reduce.matches) io.observe(el); else el.classList.add('is-in');
		});
	}
	observeAll();

	/* Painted shapes drift with the pointer and scroll (desktop only) */
	var floats = [];
	var mx = 0, my = 0, ticking = false;
	function collectFloats() { floats = Array.prototype.slice.call(document.querySelectorAll('[data-float]')); }
	collectFloats();
	function renderFloats() {
		ticking = false;
		if (!desktop.matches || reduce.matches) return;
		var sy = window.scrollY;
		for (var i = 0; i < floats.length; i++) {
			var el = floats[i];
			if (!el.offsetParent) continue;
			var d = parseFloat(el.getAttribute('data-float')) || 1;
			var x = mx * 14 * d, y = my * 14 * d - sy * 0.06 * d;
			el.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0)';
		}
	}
	function queue() { if (!ticking) { ticking = true; requestAnimationFrame(renderFloats); } }
	window.addEventListener('pointermove', function (e) {
		mx = e.clientX / window.innerWidth - 0.5;
		my = e.clientY / window.innerHeight - 0.5;
		queue();
	}, { passive: true });
	window.addEventListener('scroll', queue, { passive: true });

	/* Breathing pacer: a lotus opens for 4 counts in and folds for 6 counts out, three rounds */
	function initBreath(root) {
		(root || document).querySelectorAll('[data-breath]').forEach(function (breath) {
			if (breath.dataset.ready) return;
			breath.dataset.ready = '1';
			var words = {};
			try { words = JSON.parse(breath.getAttribute('data-words') || '{}'); } catch (e) { words = {}; }
			var btn = breath.querySelector('[data-breath-start]');
			var status = breath.querySelector('[data-breath-status]');
			var phase = breath.querySelector('[data-breath-phase]');
			var count = breath.querySelector('[data-breath-count]');
			var stage = breath.querySelector('.breath__stage');
			var ring = breath.querySelector('.breath__progress');
			var dots = breath.querySelectorAll('.breath__dots li');
			var label = btn.textContent;
			var idle = { phase: phase.textContent, count: count.textContent };
			var timers = [];
			var ROUNDS = 3, IN = 4, OUT = 6;
			function later(fn, ms) { timers.push(setTimeout(fn, ms)); }
			function sweep(seconds, from, to) {
				if (!ring) return;
				ring.style.transition = 'none';
				ring.style.strokeDashoffset = from;
				ring.getBoundingClientRect();
				ring.style.transition = 'stroke-dashoffset ' + seconds + 's linear';
				ring.style.strokeDashoffset = to;
			}
			function stop(msg) {
				timers.forEach(clearTimeout); timers = [];
				breath.classList.remove('is-in', 'is-out', 'is-notice');
				stage.style.setProperty('--dur', '1.2s');
				btn.textContent = label;
				status.textContent = msg || '';
				phase.textContent = idle.phase; count.textContent = idle.count;
				if (ring) { ring.style.transition = 'stroke-dashoffset .6s'; ring.style.strokeDashoffset = 100; }
				if (!msg) dots.forEach(function (d) { d.classList.remove('is-done', 'is-now'); });
				breath.dataset.running = '';
			}
			btn.addEventListener('click', function () {
				if (breath.dataset.running) { stop(''); return; }
				breath.dataset.running = '1';
				timers.forEach(clearTimeout); timers = [];
				breath.classList.remove('is-notice');
				btn.textContent = words.stop || 'Stop';
				dots.forEach(function (d) { d.classList.remove('is-done', 'is-now'); });
				for (var i = 0; i < ROUNDS; i++) {
					(function (n) {
						var t0 = n * (IN + OUT) * 1000;
						later(function () {
							stage.style.setProperty('--dur', IN + 's');
							breath.classList.remove('is-out'); breath.classList.add('is-in');
							dots.forEach(function (d, j) { d.classList.toggle('is-now', j === n); d.classList.toggle('is-done', j < n); });
							status.textContent = (words.round || 'Breath %1$d of %2$d').replace('%1$d', n + 1).replace('%2$d', ROUNDS);
							sweep(IN, 100, 0);
							phase.textContent = words['in'] || 'Breathe in'; count.textContent = 1;
							for (var s = 1; s < IN; s++) { (function (k) { timers.push(setTimeout(function () { count.textContent = k + 1; }, k * 1000)); })(s); }
						}, t0);
						later(function () {
							stage.style.setProperty('--dur', OUT + 's');
							breath.classList.remove('is-in'); breath.classList.add('is-out');
							sweep(OUT, 0, 100);
							phase.textContent = words.out || 'Breathe out'; count.textContent = OUT;
							for (var s = 1; s < OUT; s++) { (function (k) { timers.push(setTimeout(function () { count.textContent = OUT - k; }, k * 1000)); })(s); }
						}, t0 + IN * 1000);
					})(i);
				}
				later(function () {
					dots.forEach(function (d) { d.classList.remove('is-now'); d.classList.add('is-done'); });
					stop(words.done || 'Welcome back.');
					// after the third round the lotus rests and asks you to notice (Olivia, 13 November)
					if (words.notice) {
						breath.classList.add('is-notice');
						phase.textContent = words.notice; count.textContent = '';
						later(function () { breath.classList.remove('is-notice'); phase.textContent = idle.phase; count.textContent = idle.count; }, 9000);
					}
				}, ROUNDS * (IN + OUT) * 1000);
			});
		});
	}
	initBreath();

	/* Contact form: preselect topic from URL, validate, confirm */
	function topicFromUrl() {
		var q = location.search || '';
		var h = location.hash || '';
		var m = (q + '&' + h).match(/topic=([a-z]+)/);
		return m ? m[1] : '';
	}
	function initForms(root) {
		(root || document).querySelectorAll('form[data-contact]').forEach(function (form) {
			var topic = topicFromUrl();
			var radio = topic && form.querySelector('input[name="topic"][value="' + topic + '"]');
			if (radio) radio.checked = true;
			var sel = form.querySelector('select[name="topic"]');
			if (topic && sel && sel.querySelector('option[value="' + topic + '"]')) sel.value = topic;
			if (form.dataset.ready) return;
			form.dataset.ready = '1';
			var errors = {};
			try { errors = JSON.parse(form.getAttribute('data-errors') || '{}'); } catch (e) { errors = {}; }
			form.addEventListener('submit', function (e) {
				var ok = true;
				form.querySelectorAll('[required]').forEach(function (f) {
					var bad = !f.value.trim() || (f.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.value));
					f.setAttribute('aria-invalid', bad ? 'true' : 'false');
					var err = f.parentNode.querySelector('.field__error');
					if (err) err.textContent = bad ? (f.type === 'email' ? (errors.email || 'Enter an email address like name@example.com.') : (errors.required || 'Fill in this field.')) : '';
					if (bad && ok) { f.focus(); ok = false; }
				});
				if (!ok || form.getAttribute('action') === '#demo') {
					e.preventDefault();
				}
			});
		});
	}
	initForms();

	/* Floating "Book a class" on phones: out of the way over the footer and booking sections */
	var fab = document.querySelector('[data-book-fab]');
	if (fab && 'IntersectionObserver' in window) {
		var covering = new Set();
		var fio = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) { if (e.isIntersecting) covering.add(e.target); else covering.delete(e.target); });
			fab.classList.toggle('is-hidden', covering.size > 0);
		});
		document.querySelectorAll('.site-footer, .oys-schedule, .oys-checkout, .cta').forEach(function (el) { fio.observe(el); });
	}

	/* The side column follows the page only when the whole of it fits in the window; a taller one (a short laptop screen)
	   simply scrolls with the page, so neither its top nor its bottom is ever cut off (Olívia, 14 November: „levágódik
	   a box alja”, then „most a teteje tűnik el”) */
	var asides = document.querySelectorAll('.aside-sticky');
	/* A side box taller than the window would be cut off while scrolling (Olívia: „ez megint kicsit levágódik ahogy
	   görgetek lejjebb”), so it first makes itself smaller: its photo goes, then the training photo and some air – never
	   any text („lemaradt a vége”). Only if it still does not fit does it scroll with the page. */
	function fitAsides() {
		asides.forEach(function (el) {
			el.style.position = '';
			el.classList.remove('is-compact', 'is-tight');
			var cs = getComputedStyle(el);
			if (cs.position !== 'sticky') return;
			var room = window.innerHeight - (parseFloat(cs.top) || 0) - 16;
			if (el.offsetHeight <= room) return;
			el.classList.add('is-compact');
			if (el.offsetHeight <= room) return;
			el.classList.add('is-tight');
			if (el.offsetHeight <= room) return;
			el.style.position = 'static';
		});
	}
	if (asides.length) {
		fitAsides();
		window.addEventListener('resize', fitAsides, { passive: true });
		window.addEventListener('load', fitAsides);
		if ('ResizeObserver' in window) {
			var aro = new ResizeObserver(function () { fitAsides(); });
			asides.forEach(function (el) { aro.observe(el); });
		}
	}

	/* The questions: only one open at a time (Olívia: „egyszerre csak 1 legyen megnyitva, a többi csukódjon vissza”). Newer
	   browsers do it from the shared name; this covers the older ones. */
	document.addEventListener('toggle', function (e) {
		var d = e.target;
		if (!d || d.tagName !== 'DETAILS' || !d.open || !d.parentElement) return;
		var name = d.getAttribute('name');
		if (!d.parentElement.classList.contains('faq') && !name) return;
		// the questions, and anything else that shares a name (the price cards of a class page)
		Array.prototype.forEach.call(d.parentElement.children, function (o) { if (o !== d && o.tagName === 'DETAILS' && o.open && o.getAttribute('name') === name) o.open = false; });
	}, true);

	/* Olívia, 15 November, the price cards of a class page: „a két egyedüli maradhat kinyitva, a párosok maradjanak csak
	   lenyithatóak és csak mobilon kell, asztalin látszódhat minden” – on a wider screen the pair cards are open and stay
	   open; on a phone they fold (closed at first). */
	(function () {
		var pairs = document.querySelectorAll('details.oj-price--pair');
		if (!pairs.length || !window.matchMedia) return;
		var wide = window.matchMedia('(min-width: 700px)');
		function apply() {
			Array.prototype.forEach.call(pairs, function (d) {
				if (wide.matches) { d.open = true; d.classList.add('is-static'); }
				else if (d.classList.contains('is-static')) { d.open = false; d.classList.remove('is-static'); }
			});
		}
		apply();
		if (wide.addEventListener) wide.addEventListener('change', apply); else if (wide.addListener) wide.addListener(apply);
		document.addEventListener('click', function (e) {
			var s = e.target.closest && e.target.closest('details.oj-price.is-static > summary');
			if (s) e.preventDefault();
		});
	})();

	/* Olívia, 14 November: „ezek legyenek lenyithatóak mert mobilon így túl hosszú lesz” – on a phone the cards with a
	   longer text under the title (the prenatal modules, who one-to-one is for) show only their titles, a tap opens one.
	   On a wider screen they stay as they are, open. */
	/* Olívia, 15 November: „az árak iszonyat hosszú mobilon, úgyhogy asztalin és mobilon is legyen lenyitható, külön
	   szolgáltatásokra. Egyszerre csak 1 legyen itt is lenyitva” – on the online site each service of the price list folds
	   under its title; the first one is open, opening another closes it. */
	(function foldPrices() {
		if (!document.getElementById('oy-online-css')) return;
		Array.prototype.forEach.call(document.querySelectorAll('.oys-pricing'), function (list, li) {
			var groups = Array.prototype.filter.call(list.children, function (g) { return g.classList.contains('price-group'); });
			if (groups.length < 2) return;
			var all = [];
			groups.forEach(function (g, gi) {
				var h = g.querySelector('.price-group__head h3');
				if (!h) return;
				var id = 'oj-prices-' + li + '-' + gi;
				g.id = g.id || id;
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'price-group__toggle';
				b.setAttribute('aria-controls', g.id);
				while (h.firstChild) b.appendChild(h.firstChild);
				var ic = document.createElement('span');
				ic.className = 'oj-fold__icon';
				ic.setAttribute('aria-hidden', 'true');
				b.appendChild(ic);
				h.appendChild(b);
				all.push([g, b]);
				b.addEventListener('click', function () {
					var open = !g.classList.contains('is-open');
					var top = b.getBoundingClientRect().top; // the one tapped stays where it was when the open one above closes
					all.forEach(function (x) { x[0].classList.remove('is-open'); x[1].setAttribute('aria-expanded', 'false'); });
					if (open) { g.classList.add('is-open'); b.setAttribute('aria-expanded', 'true'); }
					var moved = b.getBoundingClientRect().top - top;
					if (Math.abs(moved) > 1) window.scrollBy(0, moved);
				});
			});
			if (!all.length) return;
			all.forEach(function (x, i) { x[0].classList.toggle('is-open', 0 === i); x[1].setAttribute('aria-expanded', 0 === i ? 'true' : 'false'); });
			list.classList.add('oys-pricing--fold');
		});
	})();

	(function foldCards() {
		var lists = document.querySelectorAll('.oj-modules--why');
		if (!lists.length || !window.matchMedia) return;
		var mq = window.matchMedia('(max-width: 699px)');
		var buttons = [];
		var kid = function (el, tag) { for (var i = 0; i < el.children.length; i++) { if (el.children[i].tagName === tag) return el.children[i]; } return null; };
		Array.prototype.forEach.call(lists, function (ul) {
			Array.prototype.forEach.call(ul.children, function (li) {
				var st = kid(li, 'STRONG'), sp = kid(li, 'SPAN');
				if (!st || !sp) return;
				sp.id = sp.id || 'oj-fold-' + (buttons.length + 1);
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'oj-fold';
				b.setAttribute('aria-controls', sp.id);
				li.insertBefore(b, st);
				b.appendChild(st);
				var ic = document.createElement('span');
				ic.className = 'oj-fold__icon';
				ic.setAttribute('aria-hidden', 'true');
				b.appendChild(ic);
				b.addEventListener('click', function () {
					if (!mq.matches) return;
					var open = li.classList.toggle('is-open');
					b.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
				buttons.push(b);
			});
			ul.classList.add('oj-modules--fold');
		});
		var sync = function () {
			buttons.forEach(function (b) {
				if (mq.matches) {
					b.removeAttribute('tabindex');
					b.setAttribute('aria-expanded', b.parentElement.classList.contains('is-open') ? 'true' : 'false');
				} else {
					b.setAttribute('tabindex', '-1');
					b.removeAttribute('aria-expanded');
				}
			});
		};
		sync();
		if (mq.addEventListener) mq.addEventListener('change', sync); else if (mq.addListener) mq.addListener(sync);
	})();

	onScroll();

	/* Expose for the single-file preview router */
	window.OY = {
		refresh: function (root) {
			observeAll(root); collectFloats(); initBreath(root); initForms(root); onScroll(); renderFloats();
		}
	};
})();
