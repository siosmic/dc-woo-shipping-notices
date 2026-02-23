/**
 * DC Woo Shipping Notices — Checkout modal.
 *
 * Rules are embedded in the page by PHP (no AJAX needed).
 * Subscribes to the WooCommerce Block checkout data store (wc/store/cart)
 * to detect country changes and display a modal when rules match.
 * Falls back to change events for classic (shortcode) checkout.
 *
 * @package DC_Shipping_Notices
 */
(function () {
	'use strict';

	var config   = window.dcsnCheckout || {};
	var fallback = config.fallback_to_billing;
	var rules    = config.rules || [];
	var i18n     = config.i18n || {};

	var prevShippingCountry = '';
	var prevShippingState   = '';
	var prevBillingCountry  = '';
	var prevBillingState    = '';
	var modalOpen  = false;
	var bypassNext = false;

	/* ================================================================ */
	/*  Client-side rule matching (no AJAX)                              */
	/* ================================================================ */

	/**
	 * Match embedded rules against a country/state.
	 *
	 * @param {string} country ISO-2 country code.
	 * @param {string} state   State code (optional).
	 * @returns {{ matched: Array, hasBlock: boolean }}
	 */
	function matchRules(country, state) {
		var matched  = [];
		var hasBlock = false;

		country = (country || '').toUpperCase();
		state   = (state || '').toUpperCase();

		for (var i = 0; i < rules.length; i++) {
			var rule = rules[i];

			if (!rule.countries || rule.countries.indexOf(country) === -1) {
				continue;
			}

			if (country === 'US' && rule.states && rule.states.length > 0) {
				if (rule.states.indexOf(state) === -1) {
					continue;
				}
			}

			matched.push(rule);

			if (rule.mode === 'BLOCK_WITH_MESSAGE') {
				hasBlock = true;
			}

			if (rule.stop_on_match) {
				break;
			}
		}

		return { matched: matched, hasBlock: hasBlock };
	}

	/* ================================================================ */
	/*  Initialisation                                                   */
	/* ================================================================ */

	function init() {
		if (tryBlockCheckout()) {
			interceptPlaceOrder();
			return;
		}
		tryClassicCheckout();
		interceptPlaceOrder();
	}

	/**
	 * Block checkout: subscribe to wp.data store.
	 */
	function tryBlockCheckout() {
		if (typeof wp === 'undefined' || !wp.data || !wp.data.select || !wp.data.subscribe) {
			return false;
		}

		var store = wp.data.select('wc/store/cart');
		if (!store || !store.getCustomerData) {
			return false;
		}

		var data = store.getCustomerData();
		if (data) {
			prevShippingCountry = (data.shippingAddress && data.shippingAddress.country) || '';
			prevShippingState   = (data.shippingAddress && data.shippingAddress.state)   || '';
			prevBillingCountry  = (data.billingAddress  && data.billingAddress.country)  || '';
			prevBillingState    = (data.billingAddress  && data.billingAddress.state)    || '';
		}

		wp.data.subscribe(function () {
			if (modalOpen) return;

			var s = wp.data.select('wc/store/cart');
			if (!s || !s.getCustomerData) return;

			var d = s.getCustomerData();
			if (!d) return;

			var shipCountry = (d.shippingAddress && d.shippingAddress.country) || '';
			var shipState   = (d.shippingAddress && d.shippingAddress.state)   || '';
			var billCountry = (d.billingAddress  && d.billingAddress.country)  || '';
			var billState   = (d.billingAddress  && d.billingAddress.state)    || '';

			var effectiveCountry = shipCountry || (fallback ? billCountry : '');
			var effectiveState   = shipCountry ? shipState : (fallback ? billState : '');
			var prevEffective    = prevShippingCountry || (fallback ? prevBillingCountry : '');

			if (effectiveCountry && effectiveCountry !== prevEffective) {
				var result = matchRules(effectiveCountry, effectiveState);
				if (result.matched.length > 0) {
					showModal(result.matched, result.hasBlock);
				} else {
					acceptNewCountry();
				}
			} else {
				prevShippingCountry = shipCountry;
				prevShippingState   = shipState;
				prevBillingCountry  = billCountry;
				prevBillingState    = billState;
			}
		});

		return true;
	}

	/**
	 * Classic checkout: listen for country select changes.
	 */
	function tryClassicCheckout() {
		document.addEventListener('change', function (e) {
			if (modalOpen) return;

			var el = e.target;
			var isShippingCountry = (el.id === 'shipping_country' || el.name === 'shipping_country');
			var isBillingCountry  = (el.id === 'billing_country'  || el.name === 'billing_country');

			if (!isShippingCountry && !isBillingCountry) return;

			var country = el.value || '';
			var state   = '';

			if (isShippingCountry) {
				var ss = document.getElementById('shipping_state');
				state = ss ? ss.value : '';
			} else {
				var bs = document.getElementById('billing_state');
				state = bs ? bs.value : '';
			}

			if (country) {
				var result = matchRules(country, state);
				if (result.matched.length > 0) {
					showModal(result.matched, result.hasBlock);
				}
			}
		});
	}

	/* ================================================================ */
	/*  Intercept Place Order button                                     */
	/* ================================================================ */

	var PLACE_ORDER_SELECTORS = [
		'.wc-block-components-checkout-place-order-button',
		'.wc-block-checkout__actions button[type="submit"]',
		'#place_order',
		'button[name="woocommerce_checkout_place_order"]'
	].join(',');

	function interceptPlaceOrder() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest(PLACE_ORDER_SELECTORS);
			if (!btn) return;

			if (bypassNext) {
				bypassNext = false;
				return;
			}

			var country = '';
			var state   = '';

			if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
				var s = wp.data.select('wc/store/cart');
				if (s && s.getCustomerData) {
					var d = s.getCustomerData();
					if (d) {
						country = (d.shippingAddress && d.shippingAddress.country) || '';
						state   = (d.shippingAddress && d.shippingAddress.state)   || '';
						if (!country && fallback) {
							country = (d.billingAddress && d.billingAddress.country) || '';
							state   = (d.billingAddress && d.billingAddress.state)   || '';
						}
					}
				}
			}

			if (!country) {
				var shipSel = document.getElementById('shipping_country');
				if (shipSel && shipSel.value) {
					country = shipSel.value;
					var shipSt = document.getElementById('shipping_state');
					state = shipSt ? shipSt.value : '';
				} else {
					var billSel = document.getElementById('billing_country');
					if (billSel && billSel.value) {
						country = billSel.value;
						var billSt = document.getElementById('billing_state');
						state = billSt ? billSt.value : '';
					}
				}
			}

			if (!country) return;

			var result = matchRules(country, state);

			if (result.matched.length > 0) {
				e.preventDefault();
				e.stopPropagation();
				showModal(result.matched, result.hasBlock, function () {
					bypassNext = true;
					btn.click();
				});
			}
		}, true);
	}

	/* ================================================================ */
	/*  Accept new country (update stored previous values)               */
	/* ================================================================ */

	function acceptNewCountry() {
		if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
			var s = wp.data.select('wc/store/cart');
			if (s && s.getCustomerData) {
				var d = s.getCustomerData();
				if (d) {
					prevShippingCountry = (d.shippingAddress && d.shippingAddress.country) || '';
					prevShippingState   = (d.shippingAddress && d.shippingAddress.state)   || '';
					prevBillingCountry  = (d.billingAddress  && d.billingAddress.country)  || '';
					prevBillingState    = (d.billingAddress  && d.billingAddress.state)    || '';
				}
			}
		}
	}

	/* ================================================================ */
	/*  Modal: build, show, close                                        */
	/* ================================================================ */

	/**
	 * @param {Array}    matchedRules Matched rules.
	 * @param {boolean}  hasBlock     True if any BLOCK rule matched.
	 * @param {Function} [onContinue] Optional callback when "Continue" is clicked.
	 */
	function showModal(matchedRules, hasBlock, onContinue) {
		modalOpen = true;

		var messagesHtml = '';
		for (var i = 0; i < matchedRules.length; i++) {
			messagesHtml += '<div class="dcsn-modal__message">' + matchedRules[i].message + '</div>';
		}

		var isBlock   = !!hasBlock;
		var titleText = isBlock
			? (i18n.title_block || 'Shipping not available')
			: (i18n.title_allow || 'Shipping information');

		var overlay = document.createElement('div');
		overlay.className = 'dcsn-modal-overlay' + (isBlock ? ' dcsn-modal-overlay--block' : ' dcsn-modal-overlay--allow');

		var dialog = document.createElement('div');
		dialog.className = 'dcsn-modal' + (isBlock ? ' dcsn-modal--block' : ' dcsn-modal--allow');
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-label', titleText);

		var html = '';
		html += '<div class="dcsn-modal__icon">' + (isBlock ? iconBlock() : iconWarning()) + '</div>';
		html += '<h3 class="dcsn-modal__title">' + titleText + '</h3>';
		html += '<div class="dcsn-modal__body">' + messagesHtml + '</div>';
		html += '<div class="dcsn-modal__actions">';

		var lblContinue = i18n.btn_continue || 'Continue';
		var lblChange   = i18n.btn_change   || 'Change country';

		if (!isBlock) {
			html += '<button type="button" class="dcsn-modal__btn dcsn-modal__btn--continue">' + lblContinue + '</button>';
		}
		html += '<button type="button" class="dcsn-modal__btn dcsn-modal__btn--change">' + lblChange + '</button>';
		html += '</div>';

		dialog.innerHTML = html;
		overlay.appendChild(dialog);
		document.body.appendChild(overlay);
		document.body.classList.add('dcsn-modal-open');

		requestAnimationFrame(function () {
			overlay.classList.add('dcsn-modal-overlay--visible');
		});

		var firstBtn = dialog.querySelector('.dcsn-modal__btn');
		if (firstBtn) firstBtn.focus();

		var continueBtn = dialog.querySelector('.dcsn-modal__btn--continue');
		var changeBtn   = dialog.querySelector('.dcsn-modal__btn--change');

		function handleContinue() {
			acceptNewCountry();
			closeModal(overlay);
			if (typeof onContinue === 'function') {
				setTimeout(onContinue, 50);
			}
		}

		if (continueBtn) {
			continueBtn.addEventListener('click', handleContinue);
		}

		changeBtn.addEventListener('click', function () {
			resetCountry();
			closeModal(overlay);
		});

		if (!isBlock) {
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					handleContinue();
				}
			});
		}

		function onEsc(e) {
			if (e.key === 'Escape' && !isBlock) {
				handleContinue();
				document.removeEventListener('keydown', onEsc);
			}
		}
		document.addEventListener('keydown', onEsc);
	}

	function closeModal(overlay) {
		overlay.classList.remove('dcsn-modal-overlay--visible');
		overlay.classList.add('dcsn-modal-overlay--closing');

		setTimeout(function () {
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
			document.body.classList.remove('dcsn-modal-open');
			modalOpen = false;
		}, 300);
	}

	/* ================================================================ */
	/*  Reset country to previous value                                  */
	/* ================================================================ */

	function resetCountry() {
		if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
			var d = wp.data.dispatch('wc/store/cart');
			if (d && d.setShippingAddress) {
				d.setShippingAddress({ country: prevShippingCountry, state: prevShippingState });
			}
			if (d && d.setBillingAddress && fallback) {
				d.setBillingAddress({ country: prevBillingCountry, state: prevBillingState });
			}
			return;
		}

		var ship = document.getElementById('shipping_country');
		if (ship) {
			ship.value = prevShippingCountry;
			ship.dispatchEvent(new Event('change', { bubbles: true }));
		}
		var bill = document.getElementById('billing_country');
		if (bill && fallback) {
			bill.value = prevBillingCountry;
			bill.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	/* ================================================================ */
	/*  SVG icons                                                        */
	/* ================================================================ */

	function iconWarning() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			+ '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
			+ '<line x1="12" y1="9" x2="12" y2="13"/>'
			+ '<line x1="12" y1="17" x2="12.01" y2="17"/>'
			+ '</svg>';
	}

	function iconBlock() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			+ '<circle cx="12" cy="12" r="10"/>'
			+ '<line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>'
			+ '</svg>';
	}

	/* ================================================================ */
	/*  Boot                                                             */
	/* ================================================================ */

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
