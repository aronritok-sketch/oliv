/* Olivia Studio – small front-end helpers (no dependencies).
 * 1. Price list: a one-to-one service's switches – the length (60 / 90 minutes) and „with a friend” – show the
 *    matching cards. Without JavaScript every variant stays listed.
 * 2. Private request form: the length and „how many people” fields only for the services that have them. */
(function () {
	'use strict';
	document.querySelectorAll('[data-oys-variants]').forEach(function (group) {
		var cards = group.querySelectorAll('.price[data-len]');
		var seg = group.querySelectorAll('.oys-seg [data-len]');
		var duo = group.querySelector('[data-duo]');
		var len = seg.length ? seg[0].getAttribute('data-len') : (cards[0] && cards[0].getAttribute('data-len'));
		function show() {
			var people = duo && duo.checked ? '2' : '1';
			var any = false;
			cards.forEach(function (c) {
				var on = c.getAttribute('data-len') === len && c.getAttribute('data-people') === people;
				c.hidden = !on;
				any = any || on;
			});
			// a length without a price for two (or the other way round): fall back to what there is
			if (!any) cards.forEach(function (c) { c.hidden = c.getAttribute('data-people') !== people; });
			seg.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-len') === len ? 'true' : 'false'); });
		}
		seg.forEach(function (b) { b.addEventListener('click', function () { len = b.getAttribute('data-len'); show(); }); });
		if (duo) duo.addEventListener('change', show);
		group.classList.add('is-live');
		show();
	});
	document.querySelectorAll('form[data-oys-private]').forEach(function (form) {
		var topics = form.querySelectorAll('input[name="topic"]');
		var length = form.querySelector('[data-oys-length]');
		var people = form.querySelector('[data-oys-people]');
		var prices = form.querySelectorAll('.oys-pr-services > li[data-topic]');
		var partner = form.querySelector('[data-oys-partner]');
		var pair = function () {
			if (!partner) return;
			var n = people && !people.hidden ? parseInt(people.querySelector('input').value || '1', 10) : 1;
			partner.hidden = n !== 2;
			partner.querySelectorAll('input').forEach(function (i) { i.required = n === 2; });
		};
		if (people) people.querySelector('input').addEventListener('input', pair);
		if (!topics.length) { pair(); return; }
		function sync() {
			var o = form.querySelector('input[name="topic"]:checked') || topics[0];
			// only the chosen class's prices under the cards (Olívia, 19 November)
			var has = Array.prototype.some.call(prices, function (li) { return li.getAttribute('data-topic') === o.value; });
			Array.prototype.forEach.call(prices, function (li) { li.hidden = has && li.getAttribute('data-topic') !== o.value; });
			var lens = (o && o.getAttribute('data-lengths') || '').split(',').filter(Boolean);
			if (length) {
				length.hidden = lens.length < 2;
				var sel = length.querySelector('select');
				Array.prototype.forEach.call(sel.options, function (op) { op.hidden = lens.indexOf(op.value) < 0; });
				if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = lens[0] || sel.value;
			}
			if (people && o && o.hasAttribute('data-duo')) {
				people.hidden = o.getAttribute('data-duo') !== '1';
				if (people.hidden) people.querySelector('input').value = 1;
			}
			pair();
		}
		Array.prototype.forEach.call(topics, function (t) { t.addEventListener('change', sync); });
		sync();
	});

	/* Gift cards: the chosen one is written above the form, and brought into view (Olívia, 14 November) */
	document.querySelectorAll('[data-oys-gift-form]').forEach(function (form) {
		var box = form.querySelector('[data-oys-gift-chosen]');
		if (!box) return;
		form.addEventListener('change', function (e) {
			var r = e.target;
			if (!r || r.name !== 'product' || !r.checked) return;
			box.querySelector('[data-gift-name]').textContent = r.getAttribute('data-name') || '';
			box.querySelector('[data-gift-price]').textContent = r.getAttribute('data-price') || '';
			box.classList.remove('is-new'); void box.offsetWidth; box.classList.add('is-new');
			var second = form.querySelector('[data-oys-gift-second]');
			if (second) second.hidden = parseInt(r.getAttribute('data-people') || '1', 10) < 2;
			var rect = box.getBoundingClientRect();
			if (rect.top < 0 || rect.bottom > window.innerHeight) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
		});
	});
})();

/* Account → Messages: the chosen photo's name on the button (the Hub). */
(function () {
	document.querySelectorAll('[data-oys-photo]').forEach(function (input) {
		input.addEventListener('change', function () {
			var label = input.closest('label');
			var span = label && label.querySelector('span');
			var f = input.files && input.files[0];
			if (label) {
				label.classList.toggle('has-file', !!f);
			}
			if (span) {
				span.dataset.text = span.dataset.text || span.textContent;
				span.textContent = f ? f.name : span.dataset.text;
			}
		});
	});
	var list = document.querySelector('.oys-msgs__list');
	if (list) {
		list.scrollTop = list.scrollHeight;
	}
})();

/* The calendar by month (Áron, 16 November: „A naptár … legyen lapozható”): one month at a time, the arrows page; it
   opens on the month of the next class. A tap on a day with a class marks that class in the list under it. */
(function () {
	document.querySelectorAll('[data-oys-cal]').forEach(function (cal) {
		var months = Array.prototype.slice.call(cal.querySelectorAll('.oys-cal__month'));
		if (months.length < 1) return;
		var start = months.findIndex(function (m) { return m.hasAttribute('data-start'); });
		var ask = cal.querySelector('[data-oys-cal-ask]');
		var show = function (i) {
			months.forEach(function (m, j) { m.hidden = j !== i; });
			if (ask) ask.hidden = true;
		};
		// a one-to-one session on the chosen day: the link carries the day and the time (Olívia, 19 Nov); the time is a
		// tap on one of the half hours, „any time” among them (Áron, 19 Nov: no drop-down)
		var askLink = function () {
			if (!ask) return;
			var u = new URL(ask.getAttribute('data-url'), location.href);
			u.searchParams.set('pday', ask.getAttribute('data-day') || '');
			var on = ask.querySelector('[data-ask-time][aria-pressed="true"]');
			var t = on ? on.getAttribute('data-ask-time') : '';
			if (t) u.searchParams.set('ptime', t); else u.searchParams.delete('ptime');
			var go = ask.querySelector('[data-ask-go]');
			go.href = u.toString();
			var label = go.getAttribute('data-label');
			if (label && ask.getAttribute('data-short')) go.textContent = label.replace('%s', ask.getAttribute('data-short') + (t ? ', ' + on.textContent : ''));
		};
		if (ask) ask.addEventListener('click', function (e) {
			var b = e.target.closest('[data-ask-time]');
			if (!b) return;
			ask.querySelectorAll('[data-ask-time]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			askLink();
		});
		cal.classList.add('is-paged');
		show(start < 0 ? 0 : start);
		cal.addEventListener('click', function (e) {
			var go = e.target.closest('[data-cal-go]');
			if (go && !go.disabled) {
				var i = parseInt(go.getAttribute('data-cal-go'), 10);
				if (months[i]) {
					show(i);
					var f = months[i].querySelector('[data-cal-go]:not([disabled])');
					if (f) f.focus({ preventScroll: true });
				}
				return;
			}
			var day = e.target.closest('.oys-cal__day[data-day]');
			if (!day || e.target.closest('a')) return;
			pick(day);
		});
		// Enter or space on a day with a class (the days are focusable)
		cal.addEventListener('keydown', function (e) {
			var day = e.target.closest && e.target.closest('.oys-cal__day[data-day]');
			if (!day || (e.key !== 'Enter' && e.key !== ' ')) return;
			e.preventDefault();
			pick(day);
		});
		// only the chosen day's classes show under the calendar (Olívia, 19 Nov)
		function pick(day) {
			var m = day.closest('.oys-cal__month');
			m.querySelectorAll('.oys-cal__item.is-hl, .oys-cal__day.is-hl').forEach(function (x) { x.classList.remove('is-hl'); });
			m.classList.add('has-pick');
			day.classList.add('is-hl');
			var first = null;
			m.querySelectorAll('.oys-cal__item[data-day="' + day.getAttribute('data-day') + '"]').forEach(function (x) { x.classList.add('is-hl'); first = first || x; });
			var none = m.querySelector('.oys-cal__none');
			if (none) none.hidden = !!first;
			if (ask) {
				ask.setAttribute('data-day', day.getAttribute('data-day'));
				ask.setAttribute('data-short', day.getAttribute('data-short') || '');
				ask.querySelector('[data-ask-day]').textContent = day.getAttribute('data-label') || '';
				ask.hidden = false;
				askLink();
				if (!first) first = none || ask;
			}
			if (first && first.offsetParent) {
				var r = first.getBoundingClientRect();
				if (r.bottom > window.innerHeight - 90 || r.top < 80) first.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
		}
	});
})();


/* „More classes in the calendar” on the booking page (Olívia, 18 Nov): a link to #naptar opens it. */
(function () {
	var open = function () {
		var d = document.getElementById('naptar');
		if (d && 'DETAILS' === d.tagName && '#naptar' === location.hash) d.open = true;
	};
	open();
	window.addEventListener('hashchange', open);
})();

/* Profile photo: the chosen picture shows in the circle before saving (Áron, 19 Nov). */
(function () {
	document.querySelectorAll('[data-oys-avatar]').forEach(function (input) {
		input.addEventListener('change', function () {
			var f = input.files && input.files[0];
			var box = document.querySelector('[data-oys-avatar-preview]');
			if (!f || !box || !/^image\//.test(f.type)) return;
			var img = document.createElement('img');
			img.className = 'oys-avatar__img';
			img.alt = '';
			img.src = URL.createObjectURL(f);
			box.innerHTML = '';
			box.appendChild(img);
		});
	});
})();
