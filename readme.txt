=== TK Currency Manager for WooCommerce ===
Contributors: tk
Tags: woocommerce, currency, pricing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Maintain source-currency prices and convert them into the WooCommerce store currency.

== Description ==

Define currency coefficients under WooCommerce → Currencies (TK). Set source regular and optional sale prices on simple products or individual variations. Save to recalculate, or use the admin bulk tool / `wp tk-currency recalc` after changing rates.

The plugin writes normal WooCommerce prices through CRUD. It does not provide customer currency switching, automatic exchange-rate feeds or multi-currency checkout.

* Empty source regular price opts out and preserves store prices; zero is an intentional free price.
* Empty currency uses the first configured rate; unknown named currencies cause an error.
* Invalid prices/rates are rejected. Empty source sale price clears the managed sale price.
* Bulk admin requests process 50 catalog records with updated/skipped/error counts.
* Uninstall retains shared legacy settings, source metadata and converted prices.

== Installation ==
1. Upload the plugin ZIP or copy tk-currency-manager into wp-content/plugins.
2. Activate WooCommerce and this plugin.
3. Configure currencies before entering source prices.
4. Test on staging and back up the database before bulk price updates.

== Frequently Asked Questions ==

= Are variations supported? =
Yes, each variation has independent source fields. Variable parent price ranges are synchronized; source fields are not inherited. Grouped, external and custom product types are skipped.

= Can a bulk run be interrupted? =
Yes. Earlier updates remain saved; rerun after correcting errors. Keep the admin page open and avoid concurrent catalog/rate changes. Use WP-CLI for large catalogs. The run is not transactional.

= What happens to legacy data on uninstall? =
Nothing is deleted. Options teplo_klimat_currency_params / teplo_klimat_num_elements and metadata tk_currency / tk_curr_price / tk_curr_sale are retained. Remove conflicting old theme pricing hooks when migrating.

== Changelog ==
= 0.2.0 =
* Preserve prices of unmanaged products and variations.
* Register product hooks independently of WooCommerce file load order.
* Validate source decimals, rates, duplicate codes and unknown currencies.
* Protect form saves and process bulk requests in bounded batches.
* Synchronize variation ranges; clear expired source sales; retain data on uninstall.
* Add regression/integration checks and update documentation.

= 0.1.0 =
* Initial public release.
