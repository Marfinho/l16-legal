/*! Lindenweg16 Legal Suite – Consent-Banner (Vanilla JS, keine Abhängigkeiten) */
(function () {
	'use strict';
	var C = window.LL16_CONSENT;
	if (!C) { return; }

	var state = null;       // { v, t, c: [], id }
	var banner = null;
	var lastFocus = null;

	function uuid() {
		if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
		var b = new Uint8Array(16); crypto.getRandomValues(b);
		b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
		var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
		return h.replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
	}

	function readCookie() {
		var m = document.cookie.match(new RegExp('(?:^|; )' + C.cookie + '=([^;]*)'));
		if (!m) { return null; }
		try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
	}

	function writeCookie(s) {
		var exp = new Date(Date.now() + C.maxAgeDays * 864e5).toUTCString();
		document.cookie = C.cookie + '=' + encodeURIComponent(JSON.stringify(s)) +
			'; expires=' + exp + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}

	function valid(s) {
		return s && s.v === C.version && s.id && Array.isArray(s.c) && (Date.now() - s.t) < C.maxAgeDays * 864e5;
	}

	function allowed(cat) { return cat === 'necessary' || !!(state && state.c.indexOf(cat) > -1); }

	function serviceCategory(id) {
		for (var i = 0; i < C.services.length; i++) { if (C.services[i].id === id) { return C.services[i].category; } }
		return null;
	}

	/* ---- Aktivierung blockierter Inhalte ---- */
	function activateScripts() {
		var nodes = document.querySelectorAll('script[type="text/plain"][data-ll16-service]');
		Array.prototype.forEach.call(nodes, function (old) {
			var cat = serviceCategory(old.getAttribute('data-ll16-service'));
			if (!cat || !allowed(cat)) { return; }
			var s = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (a) {
				if (['type', 'data-src', 'data-ll16-service', 'data-ll16-module'].indexOf(a.name) === -1) { s.setAttribute(a.name, a.value); }
			});
			if (old.getAttribute('data-ll16-module')) { s.type = 'module'; }
			if (old.getAttribute('data-src')) { s.src = old.getAttribute('data-src'); } else { s.text = old.text; }
			old.parentNode.replaceChild(s, old);
		});
		Array.prototype.forEach.call(document.querySelectorAll('[data-ll16-embed]'), function (box) {
			var cat = serviceCategory(box.getAttribute('data-ll16-embed'));
			if (cat && allowed(cat)) { loadEmbed(box); }
		});
	}

	function loadEmbed(box) {
		var tpl = box.querySelector('template');
		if (!tpl) { return; }
		var f = tpl.content.firstElementChild.cloneNode(true);
		f.src = f.getAttribute('data-ll16-src');
		f.removeAttribute('data-ll16-src');
		box.parentNode.replaceChild(f, box);
	}

	/* ---- Cookies bereinigen, wenn Kategorien nicht erlaubt sind ---- */
	function cleanCookies() {
		var names = [];
		C.services.forEach(function (s) {
			if (!allowed(s.category)) { names = names.concat(s.cookies); }
		});
		document.cookie.split('; ').forEach(function (pair) {
			var n = pair.split('=')[0];
			var hit = names.some(function (p) {
				return p.slice(-1) === '*' ? n.indexOf(p.slice(0, -1)) === 0 : n === p;
			});
			if (!hit) { return; }
			var parts = location.hostname.split('.');
			for (var i = 0; i < parts.length - 1; i++) {
				var d = parts.slice(i).join('.');
				document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=' + d;
				document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=.' + d;
			}
			document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
		});
	}

	/* ---- Entscheidung speichern ---- */
	function decide(action, cats) {
		var id = (state && state.id) || uuid();
		state = { v: C.version, t: Date.now(), c: cats, id: id };
		writeCookie(state);
		try {
			var body = JSON.stringify({ id: id, action: action, categories: cats });
			if (navigator.sendBeacon) {
				navigator.sendBeacon(C.rest, new Blob([body], { type: 'application/json' }));
			} else {
				fetch(C.rest, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true });
			}
		} catch (e) { /* Nachweis ist best effort, die Entscheidung gilt trotzdem */ }
		closeBanner();
		cleanCookies();
		activateScripts();
		document.dispatchEvent(new CustomEvent('ll16:consent', { detail: { categories: cats } }));
	}

	var optional = C.categories.filter(function (c) { return c.key !== 'necessary'; }).map(function (c) { return c.key; });

	/* ---- Banner ---- */
	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { e.appendChild(c); });
		return e;
	}

	function link(href, text) { return el('a', { href: href, text: text }); }

	function openBanner(showDetails) {
		if (banner) { return; }
		lastFocus = document.activeElement;
		var title = el('h2', { id: 'll16-title', text: 'Deine Privatsphäre' });
		var intro = el('p', { id: 'll16-desc' });
		intro.appendChild(document.createTextNode(
			'Wir nutzen nur technisch notwendige Speicherungen. Für weitere Dienste (z. B. Kartenanzeige) fragen wir dich vorher. Du kannst deine Auswahl jederzeit über „Cookie-Einstellungen" ändern. Mehr in der '));
		intro.appendChild(link(C.privacyUrl, 'Datenschutzerklärung'));
		intro.appendChild(document.createTextNode(' und der '));
		intro.appendChild(link(C.cookiesUrl, 'Cookie-Übersicht'));
		intro.appendChild(document.createTextNode('.'));

		var details = el('div', { class: 'll16-details', hidden: '' });
		var boxes = {};
		C.categories.forEach(function (cat) {
			var cb = el('input', { type: 'checkbox', id: 'll16-cat-' + cat.key });
			if (cat.key === 'necessary') { cb.checked = true; cb.disabled = true; } else { cb.checked = allowed(cat.key); boxes[cat.key] = cb; }
			var list = el('ul', { class: 'll16-services' });
			C.services.filter(function (s) { return s.category === cat.key; }).forEach(function (s) {
				list.appendChild(el('li', { text: s.name + ' – ' + s.provider + '. ' + s.purpose }));
			});
			details.appendChild(el('div', { class: 'll16-cat' }, [
				el('label', { for: 'll16-cat-' + cat.key }, [cb, el('strong', { text: ' ' + cat.label })]),
				el('p', { text: cat.description }), list
			]));
		});

		var accept = el('button', { type: 'button', class: 'll16-btn', text: 'Alle akzeptieren' });
		var reject = el('button', { type: 'button', class: 'll16-btn', text: 'Ablehnen' });
		var more = el('button', { type: 'button', class: 'll16-btn', text: 'Einstellungen', 'aria-expanded': 'false' });
		var save = el('button', { type: 'button', class: 'll16-btn', text: 'Auswahl speichern', hidden: '' });

		accept.onclick = function () { decide('accept', optional); };
		reject.onclick = function () { decide('reject', []); };
		save.onclick = function () {
			decide('custom', optional.filter(function (k) { return boxes[k] && boxes[k].checked; }));
		};
		more.onclick = function () {
			var open = details.hasAttribute('hidden');
			if (open) { details.removeAttribute('hidden'); save.removeAttribute('hidden'); } else { details.setAttribute('hidden', ''); save.setAttribute('hidden', ''); }
			more.setAttribute('aria-expanded', String(open));
		};

		banner = el('div', { class: 'll16-banner', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'll16-title', 'aria-describedby': 'll16-desc' }, [
			title, intro, details,
			el('div', { class: 'll16-actions' }, [reject, accept, more, save])
		]);
		document.body.appendChild(banner);
		banner.addEventListener('keydown', trap);
		if (showDetails) { more.click(); }
		reject.focus();
	}

	function trap(ev) {
		if (ev.key === 'Escape') { ev.preventDefault(); decide('reject', []); return; }  // ESC = Ablehnen
		if (ev.key !== 'Tab') { return; }
		var f = banner.querySelectorAll('a[href], button:not([hidden]):not([disabled]), input:not([disabled])');
		var vis = Array.prototype.filter.call(f, function (n) { return !n.closest('[hidden]'); });
		if (!vis.length) { return; }
		var first = vis[0], last = vis[vis.length - 1];
		if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
		else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
	}

	function closeBanner() {
		if (banner) { banner.parentNode.removeChild(banner); banner = null; }
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* ignorieren */ } }
	}

	/* ---- Start ---- */
	state = readCookie();
	if (!valid(state)) { state = null; }

	// Globales Opt-out-Signal (GPC) = Ablehnung, ohne Banner.
	if (!state && navigator.globalPrivacyControl === true) { decide('gpc', []); }

	function init() {
		document.addEventListener('click', function (ev) {
			var t = ev.target.closest && ev.target.closest('[data-ll16-open]');
			if (t) { ev.preventDefault(); openBanner(true); return; }
			var b = ev.target.closest && ev.target.closest('[data-ll16-load]');
			if (b) { loadEmbed(b.closest('[data-ll16-embed]')); } // Zwei-Klick: nur dieser Inhalt, ohne Dauer-Einwilligung
		});
		var fl = el('button', { type: 'button', class: 'll16-float', 'data-ll16-open': '', text: 'Cookie-Einstellungen' });
		document.body.appendChild(fl);
		if (state) { cleanCookies(); activateScripts(); } else { openBanner(false); }
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
	window.LL16 = { open: function () { openBanner(true); }, allowed: allowed };
})();
