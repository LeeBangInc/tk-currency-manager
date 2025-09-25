=== TK Currency Manager for WooCommerce ===
Contributors: tk
Tags: woocommerce, currency, pricing
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

Define currencies with coefficients, set product prices in a chosen currency, and recalculate WooCommerce prices.

= Features =
* Admin page under WooCommerce → Currencies (TK) to manage currencies (code, rate, description) with drag & drop order.
* Product meta (General tab): currency selector, price and sale price in that currency.
* Price recalculation on product save and bulk tool.
* WP‑CLI: `wp tk-currency recalc`.
* Safe: capability checks, nonce, sanitized inputs; loads admin JS/CSS only on its page.
* i18n: English source, Russian and Ukrainian translations bundled.

== Installation ==
1. Upload `tk-currency-manager` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce → Currencies (TK) to add currencies.
4. Edit products: set Currency, Price in currency, and (optional) Sale price in currency.
5. Use the recalc button or WP‑CLI to bulk update prices when rates change.

== Screenshots ==
1. Currency settings page
2. Product meta fields

== Changelog ==
= 0.1.0 =
* Initial public release.

== Variation Pricing ==
* Variation rows include the same meta: Currency, Price in currency, Sale price in currency.
* Saving the product recalculates variations that have these fields set.
* Bulk recalc updates all variations as well.
* Note: empty variation fields are not inherited from parent by default.
