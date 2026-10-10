/* Bestell-Button im Block-Checkout: exakt "Zahlungspflichtig bestellen" (§ 312j Abs. 3 BGB). */
(function () {
	'use strict';
	var label = (window.LL16_CHECKOUT && window.LL16_CHECKOUT.buttonLabel) || 'Zahlungspflichtig bestellen';
	function register() {
		if (!window.wc || !window.wc.blocksCheckout || !window.wc.blocksCheckout.registerCheckoutFilters) { return false; }
		window.wc.blocksCheckout.registerCheckoutFilters('lindenweg16-legal', {
			placeOrderButtonLabel: function () { return label; }
		});
		return true;
	}
	if (!register()) { document.addEventListener('DOMContentLoaded', register); }
})();
