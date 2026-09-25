/* Olivia Kovács Yoga — interactions. No dependencies. */
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
		document.querySelectorAll('.has-sub.is-open').forEach(function (li) { li.classList.remove('is-open'); });
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a');
		if (a && body.classList.contains('nav-open')) setNav(false);
		var t = e.target.closest('.sub-toggle');
		if (t) {
			var li = t.closest('.has-sub');
			var open = !li.classList.contains('is-open');
			li.classList.toggle('is-open', open);
			t.setAttribute('aria-expanded', open ? 'true' : 'false');
		} else if (!e.target.closest('.has-sub')) {
			document.querySelectorAll('.has-sub.is-open').forEach(function (li) { li.classList.remove('is-open'); });
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

	/* Breathing pacer: 4 in, 6 out, three rounds */
	function initBreath(root) {
		(root || document).querySelectorAll('[data-breath]').forEach(function (breath) {
			if (breath.dataset.ready) return;
			breath.dataset.ready = '1';
			var btn = breath.querySelector('[data-breath-start]');
			var status = breath.querySelector('[data-breath-status]');
			var label = btn.textContent;
			var timers = [];
			function stop(msg) {
				timers.forEach(clearTimeout); timers = [];
				breath.classList.remove('is-in', 'is-out');
				btn.textContent = label; status.textContent = msg || '';
				breath.dataset.running = '';
			}
			btn.addEventListener('click', function () {
				if (breath.dataset.running) { stop(''); return; }
				breath.dataset.running = '1';
				btn.textContent = 'Stop';
				for (var i = 0; i < 3; i++) {
					(function (n) {
						timers.push(setTimeout(function () {
							breath.classList.remove('is-out'); breath.classList.add('is-in');
							status.textContent = 'Breathe in… (' + (n + 1) + ' of 3)';
						}, n * 10000));
						timers.push(setTimeout(function () {
							breath.classList.remove('is-in'); breath.classList.add('is-out');
							status.textContent = 'And slowly out…';
						}, n * 10000 + 4000));
					})(i);
				}
				timers.push(setTimeout(function () { stop('Welcome back. That is how every class begins.'); }, 30000));
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
			var sel = form.querySelector('select[name="topic"]');
			if (topic && sel && sel.querySelector('option[value="' + topic + '"]')) sel.value = topic;
			if (form.dataset.ready) return;
			form.dataset.ready = '1';
			form.addEventListener('submit', function (e) {
				var ok = true;
				form.querySelectorAll('[required]').forEach(function (f) {
					var bad = !f.value.trim() || (f.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.value));
					f.setAttribute('aria-invalid', bad ? 'true' : 'false');
					var err = f.parentNode.querySelector('.field__error');
					if (err) err.textContent = bad ? (f.type === 'email' ? 'Enter an email address like name@example.com.' : 'Fill in this field.') : '';
					if (bad && ok) { f.focus(); ok = false; }
				});
				if (!ok || form.getAttribute('action') === '#demo') {
					e.preventDefault();
				}
				if (ok && form.getAttribute('action') === '#demo') {
					var notice = form.querySelector('[data-form-notice]');
					notice.hidden = false;
					notice.textContent = 'Thank you, ' + form.querySelector('[name="name"]').value.trim() + ' — your message is on its way. (Preview: nothing was actually sent.)';
					form.reset();
					notice.scrollIntoView({ block: 'center', behavior: reduce.matches ? 'auto' : 'smooth' });
				}
			});
		});
	}
	initForms();

	onScroll();

	/* Expose for the single-file preview router */
	window.OY = {
		refresh: function (root) {
			observeAll(root); collectFloats(); initBreath(root); initForms(root); onScroll(); renderFloats();
		}
	};
})();
