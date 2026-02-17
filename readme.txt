=== DC Woo Shipping Notices ===
Contributors: unprintedch
Tags: woocommerce, shipping, checkout, notices, country restrictions
Requires at least: 6.6
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display checkout notices or block orders based on shipping destination country/state. Supports WooCommerce block checkout, classic checkout, and WPML.

== Description ==

**DC Woo Shipping Notices** lets you create rules that display warning messages or completely block checkout based on the customer's shipping destination.

= Features =

* **Allow with message** — show an informational notice (e.g. customs duties warning) and let the customer proceed.
* **Block with message** — prevent the order from being placed for specific countries or states.
* **Modal dialog** — an instant modal appears when the customer selects a restricted country, with "Continue" or "Change country" buttons.
* **Block & classic checkout** — fully compatible with both the WooCommerce block-based checkout and the legacy shortcode checkout.
* **WPML support** — per-language message fields in the admin and automatic language detection on the frontend.
* **Priority & stop-on-match** — fine-grained control over rule evaluation order.
* **WooCommerce Settings integration** — rules are managed in WooCommerce → Settings → Shipping Notices.

= How it works =

1. Create rules in **WooCommerce → Settings → Shipping Notices**.
2. Each rule targets one or more countries (and optionally US states).
3. Choose a mode: *Allow with message* or *Block with message*.
4. When a customer's shipping destination matches a rule, a notice or modal is displayed.
5. Blocking rules are enforced server-side via the WooCommerce Store API (block checkout) and classic validation hooks.

== Installation ==

1. Upload the `dc-woo-shipping-notices` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → Shipping Notices** to create your first rule.

= Requirements =

* WordPress 6.6 or later
* WooCommerce 8.0 or later
* PHP 8.2 or later

== Frequently Asked Questions ==

= Does it work with the block-based checkout? =

Yes. The plugin fully supports WooCommerce's block checkout. Rules are evaluated client-side for the modal and enforced server-side via the Store API.

= Does it work with WPML? =

Yes. When WPML is active with multiple languages, the rule editor shows one message field per language. The correct translation is displayed automatically based on the visitor's current language.

= Can I allow an order but still show a warning? =

Yes. Use the "Allow with message" mode. The customer will see a notice or modal but can still complete the order.

= Can I block orders to specific countries? =

Yes. Use the "Block with message" mode. The modal will only show a "Change country" button (no "Continue"), and the order is also blocked server-side.

== Screenshots ==

1. Rule list in WooCommerce Settings.
2. Rule editor with WPML multi-language fields.
3. Warning modal on the checkout page.
4. Blocking modal on the checkout page.

== Changelog ==

= 1.2.0 =
* Client-side rule matching — instant modal, no AJAX delay.
* WPML multilingual support for rule messages and modal labels.
* WooCommerce feature compatibility declarations (HPOS, Cart/Checkout Blocks).
* Translatable UI strings via WPML String Translation.

= 1.0.0 =
* Initial release.
* Rule management in WooCommerce Settings.
* Allow/block modes with checkout notices.
* Modal dialog on country selection and Place Order.
* Block and classic checkout support.
* Server-side Store API validation for block checkout.

== Upgrade Notice ==

= 1.2.0 =
Faster modal (no more AJAX), WPML support, and WooCommerce feature compatibility.
