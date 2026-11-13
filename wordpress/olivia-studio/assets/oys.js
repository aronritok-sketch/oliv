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
		var topic = form.querySelector('select[name="topic"]');
		var length = form.querySelector('[data-oys-length]');
		var people = form.querySelector('[data-oys-people]');
		if (!topic) return;
		function sync() {
			var o = topic.options[topic.selectedIndex];
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
		}
		topic.addEventListener('change', sync);
		sync();
	});
})();
