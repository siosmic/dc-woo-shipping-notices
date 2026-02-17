=== Shipping Destination Notices for WooCommerce ===
Contributors: dcorradini
Tags: woocommerce, shipping, checkout, notices
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allow store admins to show warnings or block shipping to specific countries or states in WooCommerce.

== Description ==

**Shipping Destination Notices for WooCommerce** lets you create rules that display warning messages or completely block checkout based on the customer's shipping destination.

= Features =

* **Warning mode** — show an informational notice (e.g. customs duties warning) and let the customer proceed.
* **Block mode** — prevent the order from being placed for specific countries or states.
* **Modal dialog** — an instant modal appears when the customer selects a restricted country, with "Continue" or "Change country" buttons.
* **Block & classic checkout** — fully compatible with both the WooCommerce block-based checkout and the legacy shortcode checkout.
* **WPML support** — per-language message fields in the admin and automatic language detection on the frontend.
* **Priority & stop-on-match** — fine-grained control over rule evaluation order.
* **WooCommerce Settings integration** — rules are managed in WooCommerce > Settings > Shipping Notices.

== Installation ==

1. Upload the `dc-woo-shipping-notices` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce > Settings > Shipping Notices** to create your first rule.

= Requirements =

* WordPress 6.0 or later
* WooCommerce 8.0 or later
* PHP 7.4 or later

== Frequently Asked Questions ==

= Can I block a country? =

Yes, choose "Disable shipping" mode. The customer will see a message and will not be able to complete the order.

= Can I show a warning but still allow shipping? =

Yes, choose "Warning only" mode. The customer will see a notice or modal but can still complete the order.

= Does it work with the block-based checkout? =

Yes. The plugin fully supports WooCommerce's block checkout. Rules are evaluated client-side for the modal and enforced server-side via the Store API.

= Does it work with WPML? =

Yes. When WPML is active with multiple languages, the rule editor shows one message field per language. The correct translation is displayed automatically based on the visitor's current language.

== Screenshots ==

1. Admin interface — rule list in WooCommerce Settings.
2. Checkout warning example — modal on the checkout page.

== Changelog ==

= 1.0.0 =
* Initial release.
* Rule management in WooCommerce Settings.
* Allow/block modes with checkout notices.
* Modal dialog on country selection and Place Order.
* Block and classic checkout support.
* Server-side Store API validation for block checkout.
* WPML multilingual support.

== Upgrade Notice ==

= 1.0.0 =
First release.
