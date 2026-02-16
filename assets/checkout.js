/**
 * DC Woo Shipping Notices — Checkout modal.
 *
 * Subscribes to the WooCommerce Block checkout data store (wc/store/cart)
 * to detect country changes and display a modal when rules match.
 *
 * Falls back to jQuery change events for classic (shortcode) checkout.
 *
 * @package DC_Woo_Shipping_Notices
 */
(function () {
	'use strict';

	var config   = window.dcsnCheckout || {};
	var ajaxUrl  = config.ajax_url;
	var nonce    = config.nonce;
	var fallback = config.fallback_to_billing;
	var i18n     = config.i18n || {};

	var prevShippingCountry = '';
	var prevShippingState   = '';
	var prevBillingCountry  = '';
	var prevBillingState    = '';
	var modalOpen  = false;
	var checking   = false;
	var bypassNext = false; // when true, let the next Place Order click through

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
	 * Returns true if successfully hooked.
	 */
	function tryBlockCheckout() {
		if (typeof wp === 'undefined' || !wp.data || !wp.data.select || !wp.data.subscribe) {
			return false;
		}

		var store = wp.data.select('wc/store/cart');
		if (!store || !store.getCustomerData) {
			return false;
		}

		// Seed previous values.
		var data = store.getCustomerData();
		if (data) {
			prevShippingCountry = (data.shippingAddress && data.shippingAddress.country) || '';
			prevShippingState   = (data.shippingAddress && data.shippingAddress.state)   || '';
			prevBillingCountry  = (data.billingAddress  && data.billingAddress.country)  || '';
			prevBillingState    = (data.billingAddress  && data.billingAddress.state)    || '';
		}

		wp.data.subscribe(function () {
			if (modalOpen || checking) return;

			var s = wp.data.select('wc/store/cart');
			if (!s || !s.getCustomerData) return;

			var d = s.getCustomerData();
			if (!d) return;

			var shipCountry = (d.shippingAddress && d.shippingAddress.country) || '';
			var shipState   = (d.shippingAddress && d.shippingAddress.state)   || '';
			var billCountry = (d.billingAddress  && d.billingAddress.country)  || '';
			var billState   = (d.billingAddress  && d.billingAddress.state)    || '';

			// Determine effective country (shipping first, billing fallback).
			var effectiveCountry = shipCountry || (fallback ? billCountry : '');
			var effectiveState   = shipCountry ? shipState : (fallback ? billState : '');
			var prevEffective    = prevShippingCountry || (fallback ? prevBillingCountry : '');

			if (effectiveCountry && effectiveCountry !== prevEffective) {
				checkDestination(effectiveCountry, effectiveState);
			} else {
				// Keep tracking even if no modal triggered.
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
			if (modalOpen || checking) return;

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
				checkDestination(country, state);
			}
		});
	}

	/* ================================================================ */
	/*  Intercept Place Order button                                     */
	/* ================================================================ */

	/**
	 * Selectors for the Place Order button (block + classic checkout).
	 */
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

			// If the bypass flag is set, let it through.
			if (bypassNext) {
				bypassNext = false;
				return;
			}

			// Get current destination.
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

			// Classic checkout fallback.
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

			if (!country) return; // no country, let WC handle validation

			// Block the click while we check.
			e.preventDefault();
			e.stopPropagation();

			var formData = new FormData();
			formData.append('action', 'dcsn_check_destination');
			formData.append('nonce', nonce);
			formData.append('country', country);
			formData.append('state', state || '');

			fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json.success && json.data.rules && json.data.rules.length > 0) {
						showModal(json.data.rules, json.data.has_block, function () {
							// "Continue" callback — reclick the button.
							bypassNext = true;
							btn.click();
						});
					} else {
						// No rules matched — proceed with order.
						bypassNext = true;
						btn.click();
					}
				})
				.catch(function () {
					// AJAX error — let the order go through.
					bypassNext = true;
					btn.click();
				});
		}, true); // capture phase to fire before React/jQuery handlers
	}

	/* ================================================================ */
	/*  AJAX: check destination against rules                            */
	/* ================================================================ */

	function checkDestination(country, state) {
		checking = true;

		var formData = new FormData();
		formData.append('action', 'dcsn_check_destination');
		formData.append('nonce', nonce);
		formData.append('country', country);
		formData.append('state', state || '');

		fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				checking = false;
				if (json.success && json.data.rules && json.data.rules.length > 0) {
					showModal(json.data.rules, json.data.has_block);
				} else {
					acceptNewCountry();
				}
			})
			.catch(function () {
				checking = false;
				acceptNewCountry();
			});
	}

	/**
	 * Update stored previous values to the current store state.
	 */
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
	 * @param {Array}    rules       Matched rules from AJAX.
	 * @param {boolean}  hasBlock    True if any BLOCK rule matched.
	 * @param {Function} [onContinue] Optional callback when "Continue" is clicked
	 *                                (used by Place Order interceptor).
	 */
	function showModal(rules, hasBlock, onContinue) {
		modalOpen = true;

		// Build combined messages.
		var messagesHtml = '';
		for (var i = 0; i < rules.length; i++) {
			messagesHtml += '<div class="dcsn-modal__message">' + rules[i].message + '</div>';
		}

		var isBlock   = !!hasBlock;
		var titleText = isBlock
			? (i18n.title_block || 'Livraison impossible')
			: (i18n.title_allow || 'Information de livraison');

		// Overlay.
		var overlay = document.createElement('div');
		overlay.className = 'dcsn-modal-overlay' + (isBlock ? ' dcsn-modal-overlay--block' : ' dcsn-modal-overlay--allow');

		// Dialog.
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
		var lblContinue = i18n.btn_continue || 'Continuer';
		var lblChange   = i18n.btn_change   || 'Changer de pays';

		if (!isBlock) {
			html += '<button type="button" class="dcsn-modal__btn dcsn-modal__btn--continue">' + lblContinue + '</button>';
		}
		html += '<button type="button" class="dcsn-modal__btn dcsn-modal__btn--change">' + lblChange + '</button>';
		html += '</div>';

		dialog.innerHTML = html;
		overlay.appendChild(dialog);
		document.body.appendChild(overlay);
		document.body.classList.add('dcsn-modal-open');

		// Animate in.
		requestAnimationFrame(function () {
			overlay.classList.add('dcsn-modal-overlay--visible');
		});

		// Focus the primary action button.
		var firstBtn = dialog.querySelector('.dcsn-modal__btn');
		if (firstBtn) firstBtn.focus();

		/* ---- Event handlers ---- */

		var continueBtn = dialog.querySelector('.dcsn-modal__btn--continue');
		var changeBtn   = dialog.querySelector('.dcsn-modal__btn--change');

		function handleContinue() {
			acceptNewCountry();
			closeModal(overlay);
			if (typeof onContinue === 'function') {
				// Small delay to let the modal close before re-triggering.
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

		// Overlay click — ALLOW only.
		if (!isBlock) {
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					handleContinue();
				}
			});
		}

		// Escape key — ALLOW only.
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
	/*  Reset country to previous value via wp.data                      */
	/* ================================================================ */

	function resetCountry() {
		// Block checkout: dispatch to store.
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

		// Classic checkout: set select value.
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
