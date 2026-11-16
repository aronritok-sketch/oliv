/**
 * The Hub (Studio → Hub): Today, Conversations (messages, class chats, requests), More, My settings.
 * Plain JavaScript, no build step. Talks to OYS_Hub's REST routes (OYS_HUB.root) with the WordPress REST nonce.
 * Phone: one panel at a time, tabs at the bottom; desktop: list, conversation and the customer's card side by side.
 */
( function () {
	'use strict';
	const B = window.OYS_HUB;
	const root = document.getElementById( 'oys-hub' );
	if ( ! B || ! root ) {
		return;
	}
	const T = B.i18n;

	/* ---------- helpers ---------- */
	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const fmt = ( s, ...a ) => { let i = 0; return String( s ).replace( /%(\d\$)?[sd]/g, ( m, n ) => a[ n ? parseInt( n, 10 ) - 1 : i++ ] ); };
	const linkify = ( s ) => esc( s ).replace( /(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>' ).replace( /\n/g, '<br>' );
	const $ = ( sel, el ) => ( el || root ).querySelector( sel );
	const $$ = ( sel, el ) => Array.from( ( el || root ).querySelectorAll( sel ) );
	const icon = ( name ) => '<svg class="hub-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( ICONS[ name ] || '' ) + '</svg>';
	const ICONS = {
		home: '<path d="M4 11 12 4l8 7v8.5a1 1 0 0 1-1 1h-4.5V15h-5v5.5H5a1 1 0 0 1-1-1z"/>',
		chat: '<path d="M4.5 6.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H11l-4.5 3.5V16.5h0a2 2 0 0 1-2-2z"/>',
		calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/>',
		grid: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
		gear: '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2-1.2L14.2 3h-4.4l-.4 2.6a7 7 0 0 0-2 1.2l-2.3-.9-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-.9a7 7 0 0 0 2 1.2l.4 2.6h4.4l.4-2.6a7 7 0 0 0 2-1.2l2.3.9 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/>',
		back: '<path d="M15 5l-7 7 7 7"/>',
		send: '<path d="M4 12 20 4l-5 16-3-7z"/><path d="m12 13 8-9"/>',
		camera: '<path d="M4 8.5h3.2l1.6-2.3h6.4l1.6 2.3H20v10.5H4z"/><circle cx="12" cy="13.4" r="3.3"/>',
		bolt: '<path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>',
		info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.2"/>',
		check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		search: '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/>',
		x: '<path d="M6 6l12 12M18 6 6 18"/>',
		video: '<rect x="3.5" y="6.5" width="12" height="11" rx="2"/><path d="m15.5 10.5 5-3v9l-5-3z"/>',
		list: '<path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01"/>',
		star: '<path d="m12 4 2.4 5 5.4.6-4 3.7 1.1 5.4L12 16l-4.9 2.7 1.1-5.4-4-3.7 5.4-.6z"/>',
		users: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.2c2.4.2 4.2 1.9 4.8 4.8"/>',
		user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.8 3.6-6 7-6s6.2 2.2 7 6"/>',
		card: '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
		tag: '<path d="M3.5 12.5V4.5h8l9 9-8 8z"/><circle cx="8" cy="9" r="1.4"/>',
		gift: '<rect x="3.5" y="9" width="17" height="11.5" rx="1"/><path d="M3.5 12.5h17M12 9v11.5"/><path d="M12 9c-1.5-3.5-5.5-4-5.5-1.5S9.5 9 12 9zm0 0c1.5-3.5 5.5-4 5.5-1.5S14.5 9 12 9z"/>',
		percent: '<path d="M6 18 18 6"/><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/>',
		mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="1.5"/><path d="m4 7 8 6 8-6"/>',
		chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		teacher: '<circle cx="12" cy="7" r="3"/><path d="M6 20v-3.5A3.5 3.5 0 0 1 9.5 13h5a3.5 3.5 0 0 1 3.5 3.5V20"/>',
		globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.5 3.5 5.4 3.5 8.5s-1 6-3.5 8.5c-2.5-2.5-3.5-5.4-3.5-8.5s1-6 3.5-8.5z"/>',
		bell: '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
		phone: '<path d="M6.5 3.5h3l1.5 4-2 1.5a10 10 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>',
		note: '<path d="M5 4.5h14v10l-5 5H5z"/><path d="M14 19.5v-5h5"/>',
		heart: '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.4 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>',
		logout: '<path d="M14 4.5h4.5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H14M10 16l-4-4 4-4M6 12h10"/>',
		book: '<path d="M4.5 5.5c2.5-1 5-1 7.5.5v13c-2.5-1.5-5-1.5-7.5-.5zM19.5 5.5c-2.5-1-5-1-7.5.5v13c2.5-1.5 5-1.5 7.5-.5z"/>',
		mobile: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 17.5h2"/>',
		link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7L11.5 6.8"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.5-1.5"/>',
	};
	const api = async ( path, opts = {} ) => {
		const o = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } };
		if ( opts.form ) {
			o.body = opts.form;
		} else if ( opts.json ) {
			o.headers[ 'Content-Type' ] = 'application/json';
			o.body = JSON.stringify( opts.json );
		}
		const r = await fetch( B.root + path, o );
		const data = await r.json().catch( () => ( {} ) );
		if ( ! r.ok ) {
			throw new Error( data && data.message ? data.message : T.error );
		}
		return data;
	};
	const toast = ( msg, bad ) => {
		let t = $( '.hub-toast' );
		if ( ! t ) {
			t = document.createElement( 'div' );
			t.className = 'hub-toast';
			t.setAttribute( 'role', 'status' );
			root.appendChild( t );
		}
		t.textContent = msg;
		t.classList.toggle( 'is-bad', !! bad );
		t.classList.add( 'is-on' );
		clearTimeout( toast.t );
		toast.t = setTimeout( () => t.classList.remove( 'is-on' ), 3200 );
	};

	/* ---------- state ---------- */
	const S = {
		view: 'today',
		filter: 'all',
		q: '',
		items: [],
		counts: ( B.boot.today && B.boot.today.counts ) || { total: 0 },
		today: B.boot.today,
		me: B.boot.me,
		open: '', // „t12” / „c34”
		conv: null,
		lastId: 0,
		info: false,
		note: false,
		image: null,
	};
	const isPhone = () => window.matchMedia( '(max-width: 899px)' ).matches;

	/* ---------- shell ---------- */
	function shell() {
		const tabs = [
			[ 'today', 'home', T.today ],
			[ 'inbox', 'chat', T.inbox ],
			[ 'calendar', 'calendar', T.calendar ],
			[ 'more', 'grid', T.more ],
		];
		root.innerHTML =
			'<nav class="hub-nav" aria-label="Hub">' +
			'<div class="hub-nav__brand"><span class="hub-nav__logo">' + icon( 'heart' ) + '</span><b>' + esc( T.hub ) + '</b></div>' +
			tabs.map( ( t ) => '<button type="button" class="hub-nav__tab" data-view="' + t[ 0 ] + '">' + icon( t[ 1 ] ) + '<span>' + esc( t[ 2 ] ) + '</span>' + ( 'inbox' === t[ 0 ] ? '<i class="hub-badge" data-badge hidden></i>' : '' ) + '</button>' ).join( '' ) +
			'<button type="button" class="hub-nav__tab hub-nav__tab--settings" data-view="settings">' + icon( 'gear' ) + '<span>' + esc( T.settings ) + '</span></button>' +
			'</nav>' +
			'<main class="hub-main">' +
			'<section class="hub-view hub-today" data-panel="today"></section>' +
			'<section class="hub-view hub-inbox" data-panel="inbox">' +
			'<div class="hub-list"><header class="hub-list__head"><h2>' + esc( T.inbox ) + '</h2><button type="button" class="hub-btn hub-btn--round" data-new title="' + esc( T.newMsg ) + '" aria-label="' + esc( T.newMsg ) + '">' + icon( 'plus' ) + '</button></header>' +
			'<label class="hub-search">' + icon( 'search' ) + '<input type="search" placeholder="' + esc( T.search ) + '" data-q></label>' +
			'<div class="hub-chips" role="tablist">' + [ [ 'all', T.all ], [ 'unread', T.unread ], [ 'messages', T.messages ], [ 'chats', T.chats ] ].concat( B.manage ? [ [ 'requests', T.requests ] ] : [] ).concat( [ [ 'done', T.done ] ] ).map( ( c ) => '<button type="button" role="tab" class="hub-chip" data-filter="' + c[ 0 ] + '">' + esc( c[ 1 ] ) + '<i data-chip-n></i></button>' ).join( '' ) + '</div>' +
			'<ul class="hub-items" data-items></ul></div>' +
			'<div class="hub-conv" data-conv><div class="hub-conv__empty">' + icon( 'chat' ) + '<p>' + esc( T.pick ) + '</p></div></div>' +
			'<aside class="hub-ctx" data-ctx></aside>' +
			'</section>' +
			'<section class="hub-view hub-more" data-panel="more"></section>' +
			'<section class="hub-view hub-settings" data-panel="settings"></section>' +
			'</main>';
		$$( '[data-view]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
			if ( 'calendar' === b.dataset.view ) {
				const cal = B.links.find( ( l ) => 'calendar' === l[ 0 ] );
				if ( cal ) {
					window.location.href = cal[ 2 ];
				}
				return;
			}
			go( b.dataset.view );
		} ) );
		$$( '[data-filter]' ).forEach( ( b ) => b.addEventListener( 'click', () => {
			S.filter = b.dataset.filter;
			loadInbox();
		} ) );
		let qt;
		$( '[data-q]' ).addEventListener( 'input', ( e ) => {
			clearTimeout( qt );
			qt = setTimeout( () => {
				S.q = e.target.value.trim();
				loadInbox();
			}, 250 );
		} );
		$( '[data-new]' ).addEventListener( 'click', newConversation );
	}

	function go( view, push = true ) {
		S.view = view;
		root.dataset.view = view;
		$$( '[data-view]' ).forEach( ( b ) => b.classList.toggle( 'is-on', b.dataset.view === view ) );
		$$( '[data-panel]' ).forEach( ( p ) => ( p.hidden = p.dataset.panel !== view ) );
		if ( 'today' === view ) {
			renderToday();
			refreshToday();
		} else if ( 'inbox' === view ) {
			loadInbox();
		} else if ( 'more' === view ) {
			renderMore();
		} else if ( 'settings' === view ) {
			renderSettings();
		}
		if ( push ) {
			history.replaceState( null, '', '#' + ( 'inbox' === view && S.open ? S.open : view ) );
		}
		window.scrollTo( 0, 0 );
	}

	function setBadges( c ) {
		S.counts = c || S.counts;
		const n = S.counts.total || 0;
		const b = $( '[data-badge]' );
		if ( b ) {
			b.hidden = ! n;
			b.textContent = n > 99 ? '99+' : n;
		}
		$$( '[data-filter]' ).forEach( ( el ) => {
			const k = { unread: S.counts.total, messages: S.counts.messages, chats: S.counts.chats, requests: S.counts.requests }[ el.dataset.filter ];
			const i = $( '[data-chip-n]', el );
			i.textContent = k ? k : '';
			el.classList.toggle( 'is-on', el.dataset.filter === S.filter );
			el.setAttribute( 'aria-selected', el.dataset.filter === S.filter ? 'true' : 'false' );
		} );
		document.title = ( n ? '(' + n + ') ' : '' ) + document.title.replace( /^\(\d+\+?\)\s*/, '' );
	}

	/* ---------- Today ---------- */
	function renderToday() {
		const d = S.today || { classes: [], counts: {} };
		const c = d.counts || {};
		const wait = [
			[ 'messages', c.messages, T.newMessages, 'chat' ],
			[ 'chats', c.chats, T.chatUnread, 'users' ],
		].concat( B.manage ? [ [ 'requests', c.requests, T.newRequests, 'star' ] ] : [] );
		const any = wait.some( ( w ) => w[ 1 ] );
		let h = '<header class="hub-hello"><p class="hub-hello__date">' + esc( B.today ) + '</p><h1>' + esc( fmt( T.hi, B.name ) ) + '</h1></header>';
		h += '<section class="hub-block"><h2 class="hub-h">' + esc( T.needsYou ) + '</h2>';
		h += any ? '<div class="hub-wait">' + wait.map( ( w ) => '<button type="button" class="hub-wait__item' + ( w[ 1 ] ? ' is-hot' : '' ) + '" data-goto="' + w[ 0 ] + '">' + icon( w[ 3 ] ) + '<b>' + ( w[ 1 ] || 0 ) + '</b><span>' + esc( w[ 2 ] ) + '</span></button>' ).join( '' ) + '</div>' : '<p class="hub-clear">' + esc( T.allClear ) + '</p>';
		h += '</section><section class="hub-block"><h2 class="hub-h">' + esc( T.nextClasses ) + '</h2>';
		if ( ! d.classes.length ) {
			h += '<p class="hub-muted">' + esc( T.noClasses ) + '</p>';
		}
		h += '<div class="hub-classes">' + d.classes.map( classCard ).join( '' ) + '</div></section>';
		if ( d.kpis ) {
			h += '<section class="hub-block"><h2 class="hub-h">' + esc( T.numbers ) + '</h2><div class="hub-kpis">' + d.kpis.map( ( k ) => '<div class="hub-kpi"><span>' + esc( k[ 0 ] ) + '</span><b>' + esc( k[ 1 ] ) + '</b></div>' ).join( '' ) + '</div>';
			if ( d.payments && d.payments.length ) {
				h += '<h3 class="hub-h3">' + esc( T.payments ) + '</h3><ul class="hub-pay">' + d.payments.map( ( p ) => '<li><span><b>' + esc( p.who ) + '</b><small>' + esc( p.what ) + '</small></span><span class="hub-pay__amt">' + esc( p.amount ) + '<small>' + esc( p.when ) + '</small></span></li>' ).join( '' ) + '</ul>';
			}
			h += '</section>';
		}
		const p = $( '[data-panel="today"]' );
		p.innerHTML = h;
		$$( '[data-goto]', p ).forEach( ( b ) => b.addEventListener( 'click', () => {
			S.filter = b.dataset.goto;
			go( 'inbox' );
		} ) );
		$$( '[data-chat]', p ).forEach( ( b ) => b.addEventListener( 'click', () => openItem( 'c' + b.dataset.chat ) ) );
		$$( '[data-person]', p ).forEach( ( b ) => b.addEventListener( 'click', () => startWith( parseInt( b.dataset.person, 10 ) ) ) );
	}

	function classCard( s ) {
		const pct = s.capacity ? Math.min( 100, Math.round( ( s.booked / s.capacity ) * 100 ) ) : 0;
		return '<article class="hub-class' + ( s.now ? ' is-now' : '' ) + '">' +
			'<header><span class="hub-class__when"><b>' + esc( s.day ) + '</b> ' + esc( s.time ) + '</span>' + ( s.now ? '<span class="hub-live-dot">LIVE</span>' : '' ) + '</header>' +
			'<h3>' + esc( s.title ) + '</h3>' +
			'<div class="hub-bar" role="img" aria-label="' + esc( fmt( T.booked, s.booked, s.capacity ) ) + '"><i style="width:' + pct + '%"></i></div>' +
			'<p class="hub-class__n">' + esc( fmt( T.booked, s.booked, s.capacity ) ) + ( s.waitlist ? ' · ' + esc( fmt( T.waitlist, s.waitlist ) ) : '' ) + '</p>' +
			( s.people.length ? '<ul class="hub-people">' + s.people.map( ( p ) => '<li>' + ( p.user ? '<button type="button" data-person="' + p.user + '">' : '<span>' ) + esc( p.name ) + ( p.health ? ' <i class="hub-flag" title="' + esc( p.health ) + '">' + esc( T.health ) + '</i>' : '' ) + ( p.here ? ' ✓' : '' ) + ( p.user ? '</button>' : '</span>' ) + '</li>' ).join( '' ) + '</ul>' : '<p class="hub-muted">' + esc( T.nobody ) + '</p>' ) +
			'<div class="hub-class__act">' +
			( s.live ? '<a class="hub-btn' + ( s.now ? ' hub-btn--hot' : ' hub-btn--primary' ) + '" href="' + esc( s.live ) + '" target="_blank" rel="noopener">' + icon( 'video' ) + esc( s.now ? T.roomNow : T.room ) + '</a>' : '' ) +
			'<a class="hub-btn" href="' + esc( s.roster ) + '">' + icon( 'list' ) + esc( T.roster ) + '</a>' +
			( s.live ? '<button type="button" class="hub-btn" data-chat="' + s.id + '">' + icon( 'chat' ) + esc( T.chat ) + '</button>' : '' ) +
			'</div></article>';
	}

	async function refreshToday() {
		try {
			S.today = await api( 'today' );
			setBadges( S.today.counts );
			if ( 'today' === S.view ) {
				renderToday();
			}
		} catch ( e ) {}
	}

	/* ---------- Inbox ---------- */
	async function loadInbox() {
		setBadges();
		try {
			const d = await api( 'inbox?filter=' + encodeURIComponent( S.filter ) + '&q=' + encodeURIComponent( S.q ) );
			S.items = d.items;
			setBadges( d.counts );
			renderItems();
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	function renderItems() {
		const ul = $( '[data-items]' );
		if ( ! S.items.length ) {
			ul.innerHTML = '<li class="hub-items__empty">' + esc( T.empty ) + '</li>';
			return;
		}
		ul.innerHTML = S.items.map( ( i ) => '<li><button type="button" class="hub-item' + ( i.unread ? ' is-unread' : '' ) + ( i.key === S.open ? ' is-open' : '' ) + ' hub-item--' + i.type + '" data-key="' + esc( i.key ) + '"' + ( i.url ? ' data-url="' + esc( i.url ) + '"' : '' ) + '>' +
			'<span class="hub-av hub-av--' + i.type + '">' + esc( i.initial ) + '</span>' +
			'<span class="hub-item__main"><span class="hub-item__top"><b>' + esc( i.title ) + '</b><time>' + esc( i.time ) + '</time></span>' +
			'<span class="hub-item__sub">' + ( i.tag ? '<em>' + esc( i.tag ) + '</em>' : '' ) + esc( i.sub ) + '</span></span>' +
			( i.unread ? '<i class="hub-dot" aria-label="' + esc( T.unread ) + '">' + ( 'chat' === i.type && i.unread > 1 ? i.unread : '' ) + '</i>' : '' ) +
			'</button></li>' ).join( '' );
		$$( '[data-key]', ul ).forEach( ( b ) => b.addEventListener( 'click', () => {
			if ( b.dataset.url && b.dataset.key.startsWith( 'r' ) ) {
				window.location.href = b.dataset.url;
				return;
			}
			openItem( b.dataset.key );
		} ) );
	}

	function openItem( key ) {
		S.open = key;
		S.conv = null;
		S.lastId = 0;
		S.info = false;
		S.note = false;
		S.image = null;
		if ( 'inbox' !== S.view ) {
			go( 'inbox', false );
		}
		history.replaceState( null, '', '#' + key );
		root.classList.add( 'is-conv' );
		$$( '.hub-item' ).forEach( ( b ) => b.classList.toggle( 'is-open', b.dataset.key === key ) );
		$( '[data-conv]' ).innerHTML = '<div class="hub-conv__loading"><span></span><span></span><span></span></div>';
		$( '[data-ctx]' ).innerHTML = '';
		loadConv( true );
	}

	function closeConv() {
		S.open = '';
		S.conv = null;
		root.classList.remove( 'is-conv', 'is-info' );
		history.replaceState( null, '', '#inbox' );
		loadInbox();
	}

	async function loadConv( first ) {
		const key = S.open;
		if ( ! key ) {
			return;
		}
		const kind = key[ 0 ];
		const id = key.slice( 1 );
		try {
			const d = await api( ( 't' === kind ? 'thread/' : 'chat/' ) + id + ( first ? '' : '?after=' + S.lastId ) );
			if ( key !== S.open ) {
				return;
			}
			if ( first ) {
				S.conv = d;
				S.conv.kind = kind;
				renderConv();
				refreshCountsSoon();
			} else if ( d.messages.length ) {
				S.conv.messages = S.conv.messages.concat( d.messages );
				appendMessages( d.messages );
			}
			if ( d.messages.length ) {
				S.lastId = d.messages[ d.messages.length - 1 ].id;
			}
		} catch ( e ) {
			if ( first ) {
				$( '[data-conv]' ).innerHTML = '<div class="hub-conv__empty"><p>' + esc( e.message ) + '</p></div>';
			}
		}
	}

	function msgHtml( m ) {
		const kind = m.kind || '';
		const cls = 'hub-msg' + ( m.studio ? ' hub-msg--me' : '' ) + ( kind ? ' hub-msg--' + kind : '' );
		let top = '';
		if ( 'request' === kind ) {
			top = '<span class="hub-msg__tag">' + icon( 'star' ) + esc( T.request ) + '</span>';
		} else if ( 'contact' === kind ) {
			top = '<span class="hub-msg__tag">' + icon( 'globe' ) + esc( T.website ) + '</span>';
		} else if ( 'note' === kind ) {
			top = '<span class="hub-msg__tag">' + icon( 'note' ) + esc( T.noteHint ) + '</span>';
		}
		const who = m.studio ? ( m.who || T.you ) : ( m.who || '' );
		return '<li class="' + cls + '" data-mid="' + m.id + '"><div class="hub-msg__bubble">' + top +
			( m.body ? '<div class="hub-msg__text">' + linkify( m.body ) + '</div>' : '' ) +
			( m.image ? '<a class="hub-msg__img" href="' + esc( m.image ) + '" data-lightbox><img src="' + esc( m.image ) + '" alt="' + esc( T.photo ) + '" loading="lazy"></a>' : '' ) +
			( m.request ? '<a class="hub-btn hub-btn--primary hub-btn--sm" href="' + esc( m.request ) + '">' + esc( T.makeOffer ) + '</a>' : '' ) +
			'</div><span class="hub-msg__meta">' + ( who ? esc( who ) + ' · ' : '' ) + esc( m.time ) +
			( 'c' === S.open[ 0 ] ? ' · <button type="button" class="hub-link" data-del="' + m.id + '">' + esc( T.deleteMsg ) + '</button>' : '' ) + '</span></li>';
	}

	function renderConv() {
		const c = S.conv;
		const isChat = 'c' === c.kind;
		const sub = isChat ? c.when : ( c.customer && c.customer.email ) || c.email || '';
		const conv = $( '[data-conv]' );
		conv.innerHTML =
			'<header class="hub-conv__head">' +
			'<button type="button" class="hub-btn hub-btn--icon hub-conv__back" data-back aria-label="' + esc( T.back ) + '">' + icon( 'back' ) + '</button>' +
			'<span class="hub-av hub-av--' + ( isChat ? 'chat' : 'thread' ) + '">' + ( isChat ? '💬' : esc( initials( c.title ) ) ) + '</span>' +
			'<span class="hub-conv__title"><b>' + esc( c.title ) + '</b><small>' + esc( isChat ? T.classChat + ' · ' + sub : sub ) + '</small></span>' +
			( ! isChat ? '<button type="button" class="hub-btn hub-btn--sm' + ( 'done' === c.status ? '' : ' hub-btn--ghost' ) + '" data-done>' + icon( 'check' ) + '<span>' + esc( 'done' === c.status ? T.reopen : T.markDone ) + '</span></button>' : '' ) +
			'<button type="button" class="hub-btn hub-btn--icon" data-info aria-label="' + esc( T.info ) + '">' + icon( 'info' ) + '</button>' +
			'</header>' +
			'<ol class="hub-msgs" data-msgs>' + c.messages.map( msgHtml ).join( '' ) + '</ol>' +
			( ! isChat && c.seen && c.messages.length && c.messages[ c.messages.length - 1 ].studio ? '<p class="hub-seen">' + esc( T.seen ) + '</p>' : '' ) +
			composer( isChat );
		bindConv( conv );
		renderCtx();
		scrollDown( true );
	}

	function initials( s ) {
		const p = String( s || '' ).trim().split( /\s+/ );
		return ( ( p[ 0 ] || '' ).charAt( 0 ) + ( p[ 1 ] || '' ).charAt( 0 ) ).toUpperCase() || '?';
	}

	function composer( isChat ) {
		const replies = ( S.me && S.me.replies ) || [];
		return '<form class="hub-compose' + ( S.note ? ' is-note' : '' ) + '" data-compose>' +
			( isChat && S.conv.session && ! S.conv.session.chatOpen ? '<p class="hub-compose__hint">' + esc( T.chatClosed ) + '</p>' : '' ) +
			'<div class="hub-compose__preview" data-preview hidden></div>' +
			'<div class="hub-compose__row">' +
			'<label class="hub-btn hub-btn--icon" title="' + esc( T.addPhoto ) + '">' + icon( 'camera' ) + '<span class="screen-reader-text">' + esc( T.addPhoto ) + '</span><input type="file" accept="image/*" data-file hidden></label>' +
			( replies.length ? '<button type="button" class="hub-btn hub-btn--icon" data-quick title="' + esc( T.quick ) + '" aria-label="' + esc( T.quick ) + '">' + icon( 'bolt' ) + '</button>' : '' ) +
			'<textarea rows="1" data-body placeholder="' + esc( S.note ? T.writeNote : T.write ) + '" aria-label="' + esc( T.write ) + '"></textarea>' +
			'<button class="hub-btn hub-btn--send" aria-label="' + esc( T.send ) + '">' + icon( 'send' ) + '</button>' +
			'</div>' +
			( ! isChat ? '<label class="hub-compose__note"><input type="checkbox" data-note' + ( S.note ? ' checked' : '' ) + '> ' + esc( T.noteHint ) + '</label>' : '' ) +
			'<ul class="hub-quick" data-quicklist hidden>' + replies.map( ( r, i ) => '<li><button type="button" data-reply="' + i + '">' + esc( r ) + '</button></li>' ).join( '' ) + '</ul>' +
			'</form>';
	}

	function bindConv( conv ) {
		$( '[data-back]', conv ).addEventListener( 'click', closeConv );
		$( '[data-info]', conv ).addEventListener( 'click', () => {
			S.info = ! S.info;
			root.classList.toggle( 'is-info', S.info );
		} );
		const done = $( '[data-done]', conv );
		if ( done ) {
			done.addEventListener( 'click', async () => {
				const next = 'done' === S.conv.status ? 'open' : 'done';
				try {
					await api( 'thread/' + S.conv.id + '/status', { method: 'POST', json: { status: next } } );
					S.conv.status = next;
					if ( 'done' === next ) {
						closeConv();
					} else {
						renderConv();
					}
				} catch ( e ) {
					toast( e.message, true );
				}
			} );
		}
		bindMessages( conv );
		const form = $( '[data-compose]', conv );
		const body = $( '[data-body]', form );
		const grow = () => {
			body.style.height = 'auto';
			body.style.height = Math.min( 180, body.scrollHeight ) + 'px';
		};
		body.addEventListener( 'input', grow );
		body.addEventListener( 'keydown', ( e ) => {
			if ( 'Enter' === e.key && ( e.metaKey || e.ctrlKey || ( ! isPhone() && ! e.shiftKey ) ) ) {
				e.preventDefault();
				form.requestSubmit();
			}
		} );
		const file = $( '[data-file]', form );
		file.addEventListener( 'change', () => {
			S.image = file.files[ 0 ] || null;
			const pv = $( '[data-preview]', form );
			if ( ! S.image ) {
				pv.hidden = true;
				return;
			}
			pv.hidden = false;
			pv.innerHTML = '<img alt="" src="' + URL.createObjectURL( S.image ) + '"><button type="button" class="hub-btn hub-btn--icon" data-unphoto aria-label="' + esc( T.removePhoto ) + '">' + icon( 'x' ) + '</button>';
			$( '[data-unphoto]', pv ).addEventListener( 'click', () => {
				S.image = null;
				file.value = '';
				pv.hidden = true;
			} );
		} );
		const quick = $( '[data-quick]', form );
		const list = $( '[data-quicklist]', form );
		if ( quick ) {
			quick.addEventListener( 'click', () => ( list.hidden = ! list.hidden ) );
			$$( '[data-reply]', list ).forEach( ( b ) => b.addEventListener( 'click', () => {
				body.value = ( body.value ? body.value + '\n' : '' ) + S.me.replies[ parseInt( b.dataset.reply, 10 ) ];
				list.hidden = true;
				grow();
				body.focus();
			} ) );
		}
		const note = $( '[data-note]', form );
		if ( note ) {
			note.addEventListener( 'change', () => {
				S.note = note.checked;
				form.classList.toggle( 'is-note', S.note );
				body.placeholder = S.note ? T.writeNote : T.write;
			} );
		}
		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			const text = body.value.trim();
			if ( ! text && ! S.image ) {
				body.focus();
				return;
			}
			const fd = new FormData();
			fd.append( 'body', text );
			if ( S.image ) {
				fd.append( 'image', S.image );
			}
			if ( S.note ) {
				fd.append( 'note', '1' );
			}
			const btn = $( '.hub-btn--send', form );
			btn.disabled = true;
			form.classList.add( 'is-sending' );
			try {
				await api( ( 't' === S.conv.kind ? 'thread/' : 'chat/' ) + S.conv.id, { method: 'POST', form: fd } );
				body.value = '';
				grow();
				S.image = null;
				file.value = '';
				$( '[data-preview]', form ).hidden = true;
				await loadConv( false );
				scrollDown( true );
				const seen = $( '.hub-seen' );
				if ( seen ) {
					seen.remove();
				}
			} catch ( err ) {
				toast( err.message, true );
			}
			btn.disabled = false;
			form.classList.remove( 'is-sending' );
			if ( ! isPhone() ) {
				body.focus();
			}
		} );
	}

	function bindMessages( el ) {
		$$( '[data-lightbox]', el ).forEach( ( a ) => {
			if ( a.dataset.bound ) {
				return;
			}
			a.dataset.bound = 1;
			a.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				lightbox( a.getAttribute( 'href' ) );
			} );
		} );
		$$( '[data-del]', el ).forEach( ( b ) => {
			if ( b.dataset.bound ) {
				return;
			}
			b.dataset.bound = 1;
			b.addEventListener( 'click', async () => {
				if ( ! window.confirm( T.confirmDelete ) ) {
					return;
				}
				try {
					await api( 'chat/' + S.conv.id + '/' + b.dataset.del, { method: 'DELETE' } );
					const li = b.closest( 'li' );
					if ( li ) {
						li.remove();
					}
				} catch ( e ) {
					toast( e.message, true );
				}
			} );
		} );
	}

	function appendMessages( msgs ) {
		const ol = $( '[data-msgs]' );
		if ( ! ol ) {
			return;
		}
		const near = ol.scrollHeight - ol.scrollTop - ol.clientHeight < 160;
		ol.insertAdjacentHTML( 'beforeend', msgs.map( msgHtml ).join( '' ) );
		bindMessages( ol );
		if ( near ) {
			scrollDown();
		}
	}

	function scrollDown( now ) {
		const ol = $( '[data-msgs]' );
		if ( ol ) {
			requestAnimationFrame( () => ol.scrollTo( { top: ol.scrollHeight, behavior: now ? 'auto' : 'smooth' } ) );
		}
	}

	function lightbox( src ) {
		const d = document.createElement( 'div' );
		d.className = 'hub-lightbox';
		d.innerHTML = '<img src="' + esc( src ) + '" alt=""><button type="button" class="hub-btn hub-btn--icon" aria-label="' + esc( T.back ) + '">' + icon( 'x' ) + '</button>';
		d.addEventListener( 'click', () => d.remove() );
		document.body.appendChild( d );
	}

	function renderCtx() {
		const c = S.conv;
		const box = $( '[data-ctx]' );
		let h = '<button type="button" class="hub-btn hub-btn--icon hub-ctx__close" data-ctx-close aria-label="' + esc( T.back ) + '">' + icon( 'x' ) + '</button>';
		if ( 'c' === c.kind ) {
			const s = c.session || {};
			h += '<h3 class="hub-ctx__name">' + esc( c.title ) + '</h3><p class="hub-muted">' + esc( c.when ) + '</p>';
			h += '<div class="hub-ctx__act">' + ( s.live ? '<a class="hub-btn hub-btn--primary" href="' + esc( s.live ) + '" target="_blank" rel="noopener">' + icon( 'video' ) + esc( T.room ) + '</a>' : '' ) + '<a class="hub-btn" href="' + esc( s.roster ) + '">' + icon( 'list' ) + esc( T.roster ) + '</a></div>';
			h += '<h4>' + esc( T.people ) + ' (' + ( s.people || [] ).length + ')</h4><ul class="hub-ctx__list">' + ( s.people || [] ).map( ( p ) => '<li>' + ( p.user ? '<button type="button" class="hub-link" data-person="' + p.user + '">' + esc( p.name ) + '</button>' : esc( p.name ) ) + '</li>' ).join( '' ) + '</ul>';
		} else {
			const u = c.customer || {};
			h += '<div class="hub-ctx__who"><span class="hub-av hub-av--lg">' + esc( initials( u.name || c.title ) ) + '</span><h3 class="hub-ctx__name">' + esc( u.name || c.title ) + '</h3>' +
				( u.since ? '<p class="hub-muted">' + esc( fmt( T.since, u.since ) ) + ( u.visits ? ' · ' + esc( fmt( T.visits, u.visits ) ) : '' ) + '</p>' : '' ) + '</div>';
			h += '<div class="hub-ctx__act">' + ( u.phone ? '<a class="hub-btn" href="tel:' + esc( u.phone.replace( /[^\d+]/g, '' ) ) + '">' + icon( 'phone' ) + esc( T.call ) + '</a>' : '' ) + ( u.email ? '<a class="hub-btn" href="mailto:' + esc( u.email ) + '">' + icon( 'mail' ) + esc( T.email ) + '</a>' : '' ) + '</div>';
			if ( u.guest ) {
				h += '<p class="hub-note">' + esc( T.noAccount ) + '</p>';
			}
			if ( u.health ) {
				h += '<div class="hub-health"><b>' + esc( T.health ) + '</b><p>' + esc( u.health ) + '</p></div>';
			}
			if ( ! u.guest ) {
				h += '<h4>' + esc( T.passes ) + '</h4>' + ( u.passes && u.passes.length ? '<ul class="hub-ctx__list">' + u.passes.map( ( p ) => '<li><b>' + esc( p.name ) + '</b><small>' + esc( fmt( T.left, p.left, p.total ) ) + ( p.expires ? ' · ' + esc( fmt( T.until, p.expires ) ) : '' ) + '</small></li>' ).join( '' ) + '</ul>' : '<p class="hub-muted">' + esc( T.noPasses ) + '</p>' );
				h += '<h4>' + esc( T.upcoming ) + '</h4>' + ( u.next && u.next.length ? '<ul class="hub-ctx__list">' + u.next.map( ( n ) => '<li><b>' + esc( n.title ) + '</b><small>' + esc( n.when ) + '</small></li>' ).join( '' ) + '</ul>' : '<p class="hub-muted">' + esc( T.noUpcoming ) + '</p>' );
				if ( u.requests && u.requests.length ) {
					h += '<h4>' + esc( T.privates ) + '</h4><ul class="hub-ctx__list">' + u.requests.map( ( r ) => '<li><a href="' + esc( r.url ) + '"><b>' + esc( r.label ) + '</b><small>' + esc( r.status ) + '</small></a></li>' ).join( '' ) + '</ul>';
				}
				if ( u.url ) {
					h += '<p><a class="hub-btn hub-btn--ghost hub-btn--block" href="' + esc( u.url ) + '">' + icon( 'user' ) + esc( T.openCustomer ) + '</a></p>';
				}
			}
		}
		box.innerHTML = h;
		$( '[data-ctx-close]', box ).addEventListener( 'click', () => {
			S.info = false;
			root.classList.remove( 'is-info' );
		} );
		$$( '[data-person]', box ).forEach( ( b ) => b.addEventListener( 'click', () => startWith( parseInt( b.dataset.person, 10 ) ) ) );
	}

	/* ---------- new conversation ---------- */
	async function startWith( userId ) {
		try {
			const d = await api( 'threads', { method: 'POST', json: { user: userId } } );
			openItem( d.key );
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	function newConversation() {
		const d = document.createElement( 'div' );
		d.className = 'hub-sheet';
		d.innerHTML = '<div class="hub-sheet__box" role="dialog" aria-modal="true" aria-label="' + esc( T.newMsg ) + '"><header><h3>' + esc( T.newMsg ) + '</h3><button type="button" class="hub-btn hub-btn--icon" data-x aria-label="' + esc( T.back ) + '">' + icon( 'x' ) + '</button></header>' +
			'<label class="hub-search">' + icon( 'search' ) + '<input type="search" placeholder="' + esc( T.findCustomer ) + '" data-find aria-label="' + esc( T.to ) + '"></label><ul class="hub-found" data-found></ul></div>';
		document.body.appendChild( d );
		const close = () => d.remove();
		d.addEventListener( 'click', ( e ) => {
			if ( e.target === d ) {
				close();
			}
		} );
		$( '[data-x]', d ).addEventListener( 'click', close );
		const input = $( '[data-find]', d );
		const list = $( '[data-found]', d );
		const find = async () => {
			try {
				const r = await api( 'customers?q=' + encodeURIComponent( input.value.trim() ) );
				list.innerHTML = r.map( ( u ) => '<li><button type="button" data-u="' + u.id + '"><span class="hub-av">' + esc( initials( u.name ) ) + '</span><span><b>' + esc( u.name ) + '</b><small>' + esc( u.email ) + '</small></span></button></li>' ).join( '' ) || '<li class="hub-muted">—</li>';
				$$( '[data-u]', list ).forEach( ( b ) => b.addEventListener( 'click', () => {
					close();
					startWith( parseInt( b.dataset.u, 10 ) );
				} ) );
			} catch ( e ) {
				toast( e.message, true );
			}
		};
		let t;
		input.addEventListener( 'input', () => {
			clearTimeout( t );
			t = setTimeout( find, 250 );
		} );
		find();
		input.focus();
	}

	/* ---------- More ---------- */
	function renderMore() {
		$( '[data-panel="more"]' ).innerHTML = '<h1 class="hub-title">' + esc( T.more ) + '</h1><div class="hub-tiles">' +
			B.links.map( ( l ) => '<a class="hub-tile" href="' + esc( l[ 2 ] ) + '"' + ( 'globe' === l[ 0 ] ? ' target="_blank" rel="noopener"' : '' ) + '>' + icon( l[ 0 ] ) + '<b>' + esc( l[ 1 ] ) + '</b>' + ( l[ 3 ] ? '<small>' + esc( l[ 3 ] ) + '</small>' : '' ) + '</a>' ).join( '' ) +
			'<a class="hub-tile hub-tile--quiet" href="' + esc( B.logout ) + '">' + icon( 'logout' ) + '<b>' + esc( T.logout ) + '</b></a></div>';
	}

	/* ---------- My settings ---------- */
	function renderSettings() {
		const m = S.me || {};
		$( '[data-panel="settings"]' ).innerHTML = '<h1 class="hub-title">' + esc( T.settings ) + '</h1>' +
			'<section class="hub-card hub-app" data-app></section>' +
			'<form class="hub-form" data-prefs>' +
			'<section class="hub-card"><h2 class="hub-h">' + icon( 'mail' ) + esc( T.emailMe ) + '</h2>' +
			'<label class="hub-switch"><input type="checkbox" name="email_messages"' + ( m.email_messages ? ' checked' : '' ) + '><span></span>' + esc( T.emailMessages ) + '</label>' +
			'<label class="hub-switch"><input type="checkbox" name="email_chat"' + ( m.email_chat ? ' checked' : '' ) + '><span></span>' + esc( T.emailChat ) + '</label>' +
			'<p class="hub-muted">' + esc( T.emailNote ) + ( B.manage && B.emails ? ' <a href="' + esc( B.emails ) + '">' + esc( T.studioEmails ) + '</a>' : '' ) + '</p></section>' +
			'<section class="hub-card"><h2 class="hub-h">' + icon( 'user' ) + esc( T.chatName ) + '</h2><input type="text" name="chat_name" value="' + esc( m.chat_name || '' ) + '" placeholder="' + esc( m.name || B.name ) + '"></section>' +
			'<section class="hub-card"><h2 class="hub-h">' + icon( 'bolt' ) + esc( T.replies ) + '</h2><p class="hub-muted">' + esc( T.repliesHelp ) + '</p><textarea name="replies" rows="6">' + esc( ( m.replies || [] ).join( '\n' ) ) + '</textarea></section>' +
			'<p><button class="hub-btn hub-btn--primary hub-btn--block">' + esc( T.save ) + '</button></p></form>';
		const form = $( '[data-prefs]' );
		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			const f = new FormData( form );
			try {
				S.me = Object.assign( S.me || {}, await api( 'me', { method: 'POST', json: {
					email_messages: f.get( 'email_messages' ) ? 1 : 0,
					email_chat: f.get( 'email_chat' ) ? 1 : 0,
					chat_name: f.get( 'chat_name' ),
					replies: String( f.get( 'replies' ) || '' ).split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean ),
				} } ) );
				toast( T.saved );
			} catch ( err ) {
				toast( err.message, true );
			}
		} );
		renderApp();
	}

	/* ---------- the app on the phone: install, notifications on this device (OYS_Studio_App) ---------- */
	const A = B.app || {};
	let installPrompt = null;
	window.addEventListener( 'beforeinstallprompt', ( e ) => {
		e.preventDefault();
		installPrompt = e;
		if ( 'settings' === S.view ) {
			renderApp();
		}
	} );
	const standalone = () => window.matchMedia( '(display-mode: standalone)' ).matches || true === window.navigator.standalone;
	const isIos = () => /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( 'MacIntel' === navigator.platform && navigator.maxTouchPoints > 1 );
	const pushable = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
	const keyBytes = ( b64 ) => {
		const raw = atob( ( b64 + '='.repeat( ( 4 - ( b64.length % 4 ) ) % 4 ) ).replace( /-/g, '+' ).replace( /_/g, '/' ) );
		return Uint8Array.from( raw, ( c ) => c.charCodeAt( 0 ) );
	};
	async function worker() {
		if ( ! ( 'serviceWorker' in navigator ) || ! A.sw ) {
			return null;
		}
		try {
			const reg = await navigator.serviceWorker.register( A.sw, { scope: A.scope } );
			// ready can wait forever when the worker never activates: give it a few seconds
			return await Promise.race( [ navigator.serviceWorker.ready, new Promise( ( ok ) => setTimeout( () => ok( reg.active ? reg : null ), 4000 ) ) ] );
		} catch ( e ) {
			return null;
		}
	}
	async function currentSub() {
		const reg = pushable() ? await worker() : null;
		return reg ? reg.pushManager.getSubscription() : null;
	}
	async function pushOn() {
		const st = await api( 'push' );
		if ( ! st.key ) {
			throw new Error( T.pushNo );
		}
		if ( 'granted' !== await Notification.requestPermission() ) {
			throw new Error( T.pushDenied );
		}
		const reg = await worker();
		if ( ! reg ) {
			throw new Error( T.pushNo );
		}
		let sub = await reg.pushManager.getSubscription();
		if ( ! sub ) {
			sub = await reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: keyBytes( st.key ) } );
		}
		return api( 'push', { method: 'POST', json: { endpoint: sub.endpoint, ua: navigator.userAgent } } );
	}
	async function pushOff() {
		const sub = await currentSub();
		if ( sub ) {
			await api( 'push', { method: 'DELETE', json: { endpoint: sub.endpoint } } ).catch( () => null );
			await sub.unsubscribe().catch( () => null );
		}
	}
	async function renderApp() {
		const box = $( '[data-app]' );
		if ( ! box ) {
			return;
		}
		const sub = await currentSub().catch( () => null );
		const on = !! sub && 'Notification' in window && 'granted' === Notification.permission;
		let install = '';
		if ( standalone() ) {
			install = '<p class="hub-ok">' + icon( 'check' ) + esc( T.appInstalled ) + '</p>';
		} else if ( installPrompt ) {
			install = '<button type="button" class="hub-btn hub-btn--primary" data-install>' + icon( 'mobile' ) + esc( T.appInstall ) + '</button>';
		} else {
			install = '<ol class="hub-steps">' + ( isIos() ? T.appStepsIos : T.appStepsAndroid ).map( ( l ) => '<li>' + esc( l ) + '</li>' ).join( '' ) + '</ol>';
		}
		let push = '';
		if ( ! pushable() ) {
			push = '<p class="hub-muted">' + esc( isIos() && ! standalone() ? T.pushIosFirst : T.pushNo ) + '</p>';
		} else if ( on ) {
			push = '<p class="hub-ok">' + icon( 'check' ) + esc( T.pushIsOn ) + '</p><p class="hub-row"><button type="button" class="hub-btn" data-push-test>' + icon( 'bell' ) + esc( T.pushTest ) + '</button>' +
				'<button type="button" class="hub-btn hub-btn--ghost" data-push-off>' + esc( T.pushOff ) + '</button></p>';
		} else {
			push = '<p class="hub-muted">' + esc( T.pushHelp ) + '</p><button type="button" class="hub-btn hub-btn--primary" data-push-on>' + icon( 'bell' ) + esc( T.pushOn ) + '</button>';
		}
		box.innerHTML = '<h2 class="hub-h">' + icon( 'mobile' ) + esc( T.appTitle ) + '</h2>' +
			'<p class="hub-muted">' + esc( T.appHelp ) + '</p>' +
			'<p class="hub-applink"><a href="' + esc( A.link ) + '">' + icon( 'link' ) + esc( ( A.link || '' ).replace( /^https?:\/\//, '' ) ) + '</a>' +
			'<button type="button" class="hub-btn hub-btn--ghost hub-btn--sm" data-copy>' + esc( T.copy ) + '</button></p>' +
			install +
			'<h3 class="hub-h hub-h--sub">' + icon( 'bell' ) + esc( T.pushTitle ) + '</h3>' + push +
			( A.guide ? '<p><a class="hub-btn hub-btn--ghost" href="' + esc( A.guide ) + '">' + icon( 'book' ) + esc( T.guide ) + '</a></p>' : '' );
		const on1 = ( sel, fn ) => {
			const b = $( sel, box );
			if ( b ) {
				b.addEventListener( 'click', async () => {
					b.disabled = true;
					try {
						await fn();
					} catch ( err ) {
						toast( err.message || T.error, true );
					}
					renderApp();
				} );
			}
		};
		on1( '[data-install]', async () => {
			installPrompt.prompt();
			await installPrompt.userChoice.catch( () => null );
			installPrompt = null;
		} );
		on1( '[data-push-on]', async () => {
			await pushOn();
			toast( T.pushIsOn );
		} );
		on1( '[data-push-off]', async () => {
			await pushOff();
			toast( T.saved );
		} );
		on1( '[data-push-test]', async () => {
			const r = await api( 'push/test', { method: 'POST' } );
			toast( r.devices ? T.pushSent : T.pushNo, ! r.devices );
		} );
		on1( '[data-copy]', async () => {
			await navigator.clipboard.writeText( A.link );
			toast( T.copied );
		} );
	}
	// a device that already said yes keeps its subscription fresh on the server (it can change or get lost)
	( async () => {
		if ( pushable() && 'granted' === Notification.permission ) {
			const sub = await currentSub().catch( () => null );
			if ( sub ) {
				api( 'push', { method: 'POST', json: { endpoint: sub.endpoint, ua: navigator.userAgent } } ).catch( () => null );
			}
		}
	} )();

	/* ---------- polling and notifications ---------- */
	let lastTotal = S.counts.total || 0;
	let lastKeys = new Set();
	async function poll() {
		try {
			const d = await api( 'poll' );
			root.classList.remove( 'is-offline' );
			const fresh = ( d.latest || [] ).filter( ( i ) => ! lastKeys.has( i.key + ':' + i.ts ) );
			if ( d.counts.total > lastTotal && fresh.length && 'Notification' in window && 'granted' === Notification.permission && ( document.hidden || fresh[ 0 ].key !== S.open ) ) {
				const n = new Notification( fresh[ 0 ].title || T.notifyTitle, { body: fresh[ 0 ].sub || '', tag: 'oys-hub-' + fresh[ 0 ].key } );
				n.onclick = () => {
					window.focus();
					openItem( fresh[ 0 ].key );
					n.close();
				};
			}
			lastKeys = new Set( ( d.latest || [] ).map( ( i ) => i.key + ':' + i.ts ) );
			lastTotal = d.counts.total;
			const changed = JSON.stringify( d.counts ) !== JSON.stringify( S.counts );
			setBadges( d.counts );
			if ( changed && 'inbox' === S.view ) {
				loadInbox();
			} else if ( changed && 'today' === S.view ) {
				refreshToday();
			}
		} catch ( e ) {
			root.classList.add( 'is-offline' );
		}
	}
	function refreshCountsSoon() {
		setTimeout( poll, 600 );
	}

	/* ---------- start ---------- */
	shell();
	setBadges( S.counts );
	const hash = window.location.hash.replace( '#', '' );
	if ( /^[tc]\d+$/.test( hash ) ) {
		go( 'inbox', false );
		openItem( hash );
	} else if ( [ 'today', 'inbox', 'more', 'settings' ].includes( hash ) ) {
		go( hash, false );
	} else {
		go( 'today', false );
	}
	poll();
	setInterval( poll, 20000 );
	setInterval( () => {
		if ( S.open && ! document.hidden ) {
			loadConv( false );
		}
	}, 6000 );
	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden ) {
			poll();
		}
	} );
	window.addEventListener( 'keydown', ( e ) => {
		if ( 'Escape' === e.key ) {
			const lb = document.querySelector( '.hub-lightbox, .hub-sheet' );
			if ( lb ) {
				lb.remove();
			} else if ( S.info ) {
				S.info = false;
				root.classList.remove( 'is-info' );
			}
		}
	} );
} )();
