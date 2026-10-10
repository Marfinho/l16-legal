/*
 * Block-Checkout:
 *  1) Bestell-Button exakt "Zahlungspflichtig bestellen" (§ 312j Abs. 3 BGB) über den offiziellen Filter.
 *  2) Rechtlicher Hinweis direkt über dem Bestell-Button. Der Block-Checkout rendert den Button-Bereich
 *     per React; serverseitig eingefügtes Markup geht dabei verloren. Der Hinweis wird deshalb nach dem
 *     Rendern eingefügt und bei Neurendern wiederhergestellt.
 */
(function () {
	'use strict';
	var cfg = window.LL16_CHECKOUT || {};
	var label = cfg.buttonLabel || 'Zahlungspflichtig bestellen';

	function register() {
		if (!window.wc || !window.wc.blocksCheckout || !window.wc.blocksCheckout.registerCheckoutFilters) { return false; }
		window.wc.blocksCheckout.registerCheckoutFilters('lindenweg16-legal', {
			placeOrderButtonLabel: function () { return label; }
		});
		return true;
	}
	if (!register()) { document.addEventListener('DOMContentLoaded', register); }

	function ensureNotice() {
		if (!cfg.noticeHtml) { return; }
		var actions = document.querySelector('.wc-block-checkout__actions');
		if (!actions || !actions.parentNode) { return; }
		var prev = actions.previousElementSibling;
		if (prev && prev.classList && prev.classList.contains('ll16-checkout-terms')) { return; }
		var old = document.querySelectorAll('.ll16-checkout-terms');
		Array.prototype.forEach.call(old, function (n) { n.parentNode && n.parentNode.removeChild(n); });
		var holder = document.createElement('div');
		holder.innerHTML = cfg.noticeHtml;
		actions.parentNode.insertBefore(holder.firstElementChild, actions);
		window.__ll16NoticeInserts = ( window.__ll16NoticeInserts || 0 ) + 1;
	}

	var timer = null;
	function schedule() { if (timer) { return; } timer = setTimeout(function () { timer = null; ensureNotice(); }, 50); }
	function start() {
		ensureNotice();
		new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
