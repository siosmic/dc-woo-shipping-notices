/**
 * DC Woo Shipping Notices — Checkout modal.
 *
 * Rules are embedded in the page by PHP (no AJAX needed).
 * Detects country changes via multiple strategies:
 *  1. wp.data store subscribe (Block checkout)
 *  2. DOM change events + jQuery events (Classic checkout)
 *  3. MutationObserver + polling (Block checkout fallback)
 *  4. Intercepts Place Order button click for last-chance validation.
 *
 * @package DC_Shipping_Destination_Notices
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
	/*  Country selectors (Classic + Block checkout)                    */
	/*                                                                  */
	/*  Classic checkout uses underscores: billing_country              */
	/*  Block checkout uses dashes:        billing-country              */
	/*  We try both everywhere.                                          */
	/* ================================================================ */

	var SHIP_COUNTRY_SELECTORS = [
		'select[name="shipping_country"]', '#shipping_country', // classic
		'select[name="shipping-country"]', '#shipping-country', // block
	];
	var BILL_COUNTRY_SELECTORS = [
		'select[name="billing_country"]',  '#billing_country',  // classic
		'select[name="billing-country"]',  '#billing-country',  // block
	];
	var STATE_SELECTORS = [
		'select[name="shipping_state"]', '#shipping_state',
		'select[name="billing_state"]',  '#billing_state',
		'select[name="shipping-state"]', '#shipping-state',
		'select[name="billing-state"]',  '#billing-state',
	];

	function init() {
		// Sync initial prev* values from the current DOM state.
		syncPrevFromDom();
		// The modal is triggered ONLY when the user clicks "Commander".
		interceptPlaceOrder();
		// Inline WC notices are managed by JS after each checkout AJAX update.
		setupInlineNotices();
	}

	/* ================================================================ */
	/*  Inline WC notice management (classic checkout)                  */
	/*                                                                  */
	/*  Listens to WooCommerce's updated_checkout jQuery event and      */
	/*  injects notice HTML directly into .woocommerce-notices-wrapper  */
	/*  based on the current country, bypassing WC's session system.    */
	/* ================================================================ */

	function setupInlineNotices() {
		if (typeof jQuery === 'undefined') return;

		jQuery(document.body).on('updated_checkout', function () {
			updateInlineNotice();
		});

		// Also run once at startup in case WC's first update fires before DOMContentLoaded.
		updateInlineNotice();
	}

	/**
	 * Read the current effective country directly from the DOM for inline notices.
	 *
	 * Does NOT use change-detection (prev* variables) — always returns the current
	 * visible value. Checks billing and shipping and returns the one that matches
	 * a rule (billing checked first since it's what the user fills out).
	 */
	function readCurrentCountryForNotice() {
		var billEl  = findEl(BILL_COUNTRY_SELECTORS);
		var shipEl  = findEl(SHIP_COUNTRY_SELECTORS);
		var stateEl = findEl(STATE_SELECTORS);

		var bill  = billEl  ? (billEl.value  || '').toUpperCase() : '';
		var ship  = shipEl  ? (shipEl.value  || '').toUpperCase() : '';
		var state = stateEl ? (stateEl.value || '')               : '';

		// Prefer billing when it matches a rule (the user changes billing directly).
		if (fallback && bill) {
			var billResult = matchRules(bill, state);
			if (billResult.matched.length > 0) {
				return { country: bill, state: state, result: billResult };
			}
		}

		// Fallback to shipping.
		if (ship) {
			var shipResult = matchRules(ship, state);
			if (shipResult.matched.length > 0) {
				return { country: ship, state: state, result: shipResult };
			}
		}

		// No match — return current effective country and empty result.
		var country = (fallback ? bill : '') || ship;
		return { country: country, state: state, result: { matched: [], hasBlock: false } };
	}

	function updateInlineNotice() {
		var wrapper = document.querySelector('.woocommerce-notices-wrapper');
		if (!wrapper) return;

		var detected = readCurrentCountryForNotice();

		if (!detected.country || detected.result.matched.length === 0) {
			wrapper.innerHTML = '';
			return;
		}

		// Build WC-compatible notice HTML.
		var html = '';
		for (var i = 0; i < detected.result.matched.length; i++) {
			var rule = detected.result.matched[i];
			if (rule.mode === 'BLOCK_WITH_MESSAGE') {
				html += '<ul class="woocommerce-error" role="alert"><li>' + rule.message + '</li></ul>';
			} else {
				html += '<ul class="woocommerce-info" role="alert"><li>' + rule.message + '</li></ul>';
			}
		}

		wrapper.innerHTML = html;
	}

	/* ================================================================ */
	/*  Block checkout: wp.data subscribe                               */
	/* ================================================================ */

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
			prevShippingCountry = ((data.shippingAddress && data.shippingAddress.country) || '').toUpperCase();
			prevShippingState   = (data.shippingAddress && data.shippingAddress.state)   || '';
			prevBillingCountry  = ((data.billingAddress  && data.billingAddress.country)  || '').toUpperCase();
			prevBillingState    = (data.billingAddress  && data.billingAddress.state)    || '';
		} else {
			syncPrevFromDom();
		}

		wp.data.subscribe(function () {
			if (modalOpen) return;

			var s = wp.data.select('wc/store/cart');
			if (!s || !s.getCustomerData) return;

			var d = s.getCustomerData();
			if (!d) return;

			var shipCountry = ((d.shippingAddress && d.shippingAddress.country) || '').toUpperCase();
			var shipState   = (d.shippingAddress && d.shippingAddress.state)   || '';
			var billCountry = ((d.billingAddress  && d.billingAddress.country)  || '').toUpperCase();
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

	/* ================================================================ */
	/*  Classic checkout: DOM change events                              */
	/* ================================================================ */

	function tryClassicCheckout() {
		// Sync initial values once at startup only.
		syncPrevFromDom();

		// Native DOM change event.
		document.addEventListener('change', function (e) {
			handleClassicChange(e.target);
		});

		// jQuery events fired by WooCommerce / Select2 / SelectWoo.
		// Only country_to_state_changed (genuine user change),
		// NOT updated_checkout (AJAX fragment refresh).
		if (typeof jQuery !== 'undefined') {
			jQuery(document.body).on('country_to_state_changed', function () {
				var ship = document.getElementById('shipping_country');
				if (ship && ship.value) {
					handleClassicChange(ship);
				} else if (fallback) {
					var bill = document.getElementById('billing_country');
					if (bill) handleClassicChange(bill);
				}
			});
		}
	}

	/**
	 * Shared logic for classic change detection.
	 * Compares el.value against stored previous country.
	 * Triggers modal only when country actually changed.
	 */
	function handleClassicChange(el) {
		if (modalOpen || !el) return;

		// Accept both underscore (classic) and dash (block) ID/name formats.
		var isShipping = (
			el.id === 'shipping_country' || el.name === 'shipping_country' ||
			el.id === 'shipping-country' || el.name === 'shipping-country'
		);
		var isBilling = (
			el.id === 'billing_country'  || el.name === 'billing_country' ||
			el.id === 'billing-country'  || el.name === 'billing-country'
		);

		if (!isShipping && !isBilling) return;

		var country = (el.value || '').toUpperCase();
		if (!country) return;

		var stateEl = findEl(STATE_SELECTORS);
		var state   = stateEl ? stateEl.value : '';

		var prev = isShipping ? prevShippingCountry : prevBillingCountry;

		if (country === prev) return; // No real change.

		var result = matchRules(country, state);

		if (result.matched.length > 0) {
			showModal(result.matched, result.hasBlock);
			// prev is NOT updated here — only updated when user clicks "Continue".
		} else {
			// No rule matched: silently accept this new country.
			if (isShipping) {
				prevShippingCountry = country;
				prevShippingState   = state;
			} else {
				prevBillingCountry = country;
				prevBillingState   = state;
			}
		}
	}

	/**
	 * Sync prev* vars from the current DOM state.
	 * Only called once at init — NEVER on AJAX events.
	 */
	function syncPrevFromDom() {
		var shipC = findEl(SHIP_COUNTRY_SELECTORS);
		var billC = findEl(BILL_COUNTRY_SELECTORS);
		var stateEl = findEl(STATE_SELECTORS);

		if (shipC) prevShippingCountry = (shipC.value || '').toUpperCase();
		if (billC) prevBillingCountry  = (billC.value || '').toUpperCase();
		if (stateEl) prevShippingState = stateEl.value;
	}

	/* ================================================================ */
	/*  Block checkout: MutationObserver + polling fallback             */
	/* ================================================================ */

	/**
	 * Watch Block checkout country selects via MutationObserver + interval.
	 *
	 * WooCommerce Block checkout renders React-controlled <select> elements
	 * that may not fire standard DOM 'change' events. We observe the
	 * checkout wrapper and poll every 600ms as a safety net.
	 */
	function observeBlockCheckoutFields() {
		if (typeof MutationObserver === 'undefined') return;

		function readBlockCountry() {
			var shipEl = findEl(SHIP_COUNTRY_SELECTORS);
			var billEl = findEl(BILL_COUNTRY_SELECTORS);
			return {
				ship: shipEl ? (shipEl.value || '').toUpperCase() : '',
				bill: billEl ? (billEl.value || '').toUpperCase() : '',
			};
		}

		function checkForCountryChange() {
			if (modalOpen) return;

			var cur = readBlockCountry();

			// Use the same change-detection as readCountryFromDom:
			// pick whichever field actually changed from the last accepted value.
			var shipChanged = cur.ship !== '' && cur.ship !== prevShippingCountry;
			var billChanged = cur.bill !== '' && cur.bill !== prevBillingCountry;

			var effectiveCountry;
			if (shipChanged) {
				effectiveCountry = cur.ship;
			} else if (billChanged && fallback) {
				effectiveCountry = cur.bill;
			} else {
				effectiveCountry = cur.ship || (fallback ? cur.bill : '');
			}

			var prevEffective = prevShippingCountry || (fallback ? prevBillingCountry : '');

			if (effectiveCountry && effectiveCountry !== prevEffective) {
				var stateEl = findEl(STATE_SELECTORS);
				var state   = stateEl ? stateEl.value : '';

				var result = matchRules(effectiveCountry, state);

				if (result.matched.length > 0) {
					showModal(result.matched, result.hasBlock);
				} else {
					prevShippingCountry = cur.ship;
					prevBillingCountry  = cur.bill;
				}
			}
		}

		var container = document.querySelector('.wp-block-woocommerce-checkout, .woocommerce-checkout, form.checkout, #wc-block-checkout__main');
		var target    = container || document.body;

		var observer = new MutationObserver(function () {
			checkForCountryChange();
		});

		observer.observe(target, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });

		// Polling fallback: React updates .value directly without triggering MutationObserver.
		var lastPollShipping = '';
		var lastPollBilling  = '';

		setInterval(function () {
			if (modalOpen) return;
			var cur = readBlockCountry();
			if (cur.ship !== lastPollShipping || cur.bill !== lastPollBilling) {
				lastPollShipping = cur.ship;
				lastPollBilling  = cur.bill;
				checkForCountryChange();
			}
		}, 600);
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

			// Read country directly from the DOM first — the most reliable source
			// since it always reflects what the user currently sees in the form.
			// The wp.data store can be stale (cached shipping address from previous session).
			var domResult = readCountryFromDom();
			var country   = domResult.country;
			var state     = domResult.state;

			// Fall back to wp.data store if DOM gave nothing.
			if (!country && typeof wp !== 'undefined' && wp.data && wp.data.select) {
				var s = wp.data.select('wc/store/cart');
				if (s && s.getCustomerData) {
					var d = s.getCustomerData();
					if (d) {
						var shipC = ((d.shippingAddress && d.shippingAddress.country) || '').toUpperCase();
						var billC = ((d.billingAddress  && d.billingAddress.country)  || '').toUpperCase();
						country = shipC || (fallback ? billC : '');
						if (shipC) {
							state = (d.shippingAddress && d.shippingAddress.state) || '';
						} else if (fallback && billC) {
							state = (d.billingAddress && d.billingAddress.state) || '';
						}
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

	/**
	 * Read the current effective country from the DOM.
	 *
	 * Uses change-detection to pick the correct field:
	 *  - If shipping changed from prev → use shipping.
	 *  - Else if billing changed from prev AND fallback enabled → use billing.
	 *  - Else → shipping if available, then billing fallback.
	 *
	 * This handles WooCommerce Block checkout where the user changes
	 * billing_country but shipping_country retains its previous (stale) value.
	 *
	 * @returns {{ country: string, state: string }}
	 */
	function readCountryFromDom() {
		var shipEl  = findEl(SHIP_COUNTRY_SELECTORS);
		var billEl  = findEl(BILL_COUNTRY_SELECTORS);
		var stateEl = findEl(STATE_SELECTORS);

		var shipCountry = shipEl  ? (shipEl.value  || '').toUpperCase() : '';
		var billCountry = billEl  ? (billEl.value  || '').toUpperCase() : '';
		var state       = stateEl ? (stateEl.value || '')               : '';

		var shipChanged = shipCountry !== '' && shipCountry !== prevShippingCountry;
		var billChanged = billCountry !== '' && billCountry !== prevBillingCountry;

		var country;
		if (shipChanged) {
			// Shipping was explicitly changed — use it.
			country = shipCountry;
		} else if (billChanged && fallback) {
			// Only billing changed and fallback is enabled — use billing.
			country = billCountry;
		} else {
			// No change detected — return current preferred value.
			country = shipCountry || (fallback ? billCountry : '');
		}

		return { country: country, state: state };
	}

	/**
	 * Return the first element matching any of the given CSS selectors that has a value.
	 *
	 * @param {string[]} selectors
	 * @returns {Element|null}
	 */
	function findEl(selectors) {
		for (var i = 0; i < selectors.length; i++) {
			var el = document.querySelector(selectors[i]);
			if (el && el.value) return el;
		}
		return null;
	}

	/* ================================================================ */
	/*  Accept new country (update stored previous values)               */
	/* ================================================================ */

	function acceptNewCountry() {
		// Block checkout: read from wp.data store.
		if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
			var s = wp.data.select('wc/store/cart');
			if (s && s.getCustomerData) {
				var d = s.getCustomerData();
				if (d) {
					prevShippingCountry = ((d.shippingAddress && d.shippingAddress.country) || '').toUpperCase();
					prevShippingState   = (d.shippingAddress && d.shippingAddress.state)   || '';
					prevBillingCountry  = ((d.billingAddress  && d.billingAddress.country)  || '').toUpperCase();
					prevBillingState    = (d.billingAddress  && d.billingAddress.state)    || '';
					return;
				}
			}
		}

		// Classic checkout: read from DOM.
		var dom = readCountryFromDom();
		prevShippingCountry = dom.country;
		prevShippingState   = dom.state;

		var billEl = findEl(['select[name="billing_country"]', '#billing_country']);
		if (billEl) prevBillingCountry = (billEl.value || '').toUpperCase();
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
			// No automatic re-check: modal is only triggered by Commander click.
		}, 300);
	}

	/**
	 * Re-evaluate the currently selected country against accepted prev*.
	 * Triggers a new modal if the destination changed while a modal was open.
	 */
	function recheckCurrentCountry() {
		var dom     = readCountryFromDom();
		var country = dom.country;
		var state   = dom.state;

		// Supplement with wp.data if DOM gave nothing.
		if (!country && typeof wp !== 'undefined' && wp.data && wp.data.select) {
			var s = wp.data.select('wc/store/cart');
			if (s && s.getCustomerData) {
				var d = s.getCustomerData();
				if (d) {
					var shipC = ((d.shippingAddress && d.shippingAddress.country) || '').toUpperCase();
					var billC = ((d.billingAddress  && d.billingAddress.country)  || '').toUpperCase();
					country   = shipC || (fallback ? billC : '');
					state     = shipC ? (d.shippingAddress.state || '') : (fallback && billC ? (d.billingAddress.state || '') : '');
				}
			}
		}

		if (!country) return;

		var prevEffective = prevShippingCountry || (fallback ? prevBillingCountry : '');

		if (country !== prevEffective) {
			var result = matchRules(country, state);
			if (result.matched.length > 0) {
				showModal(result.matched, result.hasBlock);
			} else {
				prevShippingCountry = country;
				prevShippingState   = state;
			}
		}
	}

	/* ================================================================ */
	/*  Reset country to previous value                                  */
	/* ================================================================ */

	function resetCountry() {
		// Always update the DOM first so readCountryFromDom() immediately sees
		// the previous values on the next Commander click.
		var ship = findEl(SHIP_COUNTRY_SELECTORS);
		if (ship) {
			ship.value = prevShippingCountry;
		}
		var bill = findEl(BILL_COUNTRY_SELECTORS);
		if (bill) {
			bill.value = prevBillingCountry;
		}

		// Also update the WooCommerce Block store if available.
		if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
			var d = wp.data.dispatch('wc/store/cart');
			if (d && d.setShippingAddress) {
				d.setShippingAddress({ country: prevShippingCountry, state: prevShippingState });
			}
			if (d && d.setBillingAddress) {
				d.setBillingAddress({ country: prevBillingCountry, state: prevBillingState });
			}
		}

		// Trigger WooCommerce native events so the form UI updates (states list, totals).
		if (ship) {
			if (typeof jQuery !== 'undefined') jQuery(ship).trigger('change');
			else ship.dispatchEvent(new Event('change', { bubbles: true }));
		}
		if (bill) {
			if (typeof jQuery !== 'undefined') jQuery(bill).trigger('change');
			else bill.dispatchEvent(new Event('change', { bubbles: true }));
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
