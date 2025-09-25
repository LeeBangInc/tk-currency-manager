TK Currency Manager for WooCommerce
===================================

Currency-based pricing helper for WooCommerce: define currencies with coefficients, set product prices in a chosen currency, and recalculate product prices.

Features
--------
- Admin page under `WooCommerce → Currencies (TK)` to manage currencies (code, rate, description) with drag & drop order.
- Product meta (General tab): currency selector, price and sale price in that currency.
- Price recalculation on product save and bulk recalc tool.
- Backward-compatible with legacy keys:
  - Options: `teplo_klimat_currency_params`, `teplo_klimat_num_elements`
  - Product meta: `tk_currency`, `tk_curr_price`, `tk_curr_sale`
- Safe: capability checks, nonce, sanitized inputs; loads admin JS/CSS only on its page.
- WP-CLI: `wp tk-currency recalc`.

Install
-------
1. Copy the `tk-currency-manager` folder to `wp-content/plugins/`.
2. Activate “TK Currency Manager for WooCommerce”.

Usage
-----
- Define currencies at `WooCommerce → Currencies (TK)`.
  - The first currency acts as default when a product has no specific currency.
- Edit a product → General tab:
  - Set Currency, Price in currency, and optional Sale price in currency.
- On save, plugin recalculates WooCommerce prices via CRUD.
- Use the “Пересчитать цены” button to bulk-update all products if you change rates.
- CLI: `wp tk-currency recalc` for bulk recalculation.

Variation Pricing
-----------------
- Variation rows get the same fields: Currency, Price in currency, Sale price in currency.
- Saving the product recalculates each variation that has these fields set, writing WooCommerce prices via CRUD.
- Bulk recalc also updates all variations.
- Note: by default, empty variation fields are not inherited from the parent product; enable this later if needed.

Notes
-----
- The plugin does NOT alter catalog price HTML for coupons (only WooCommerce default logic applies on cart/checkout).
- Template overrides are not needed; this is theme-agnostic.

Localization
------------
- Text domain: `tk-currency-manager`
- Template: `languages/tk-currency-manager.pot`
- Provided translations: `ru_RU.po`, `uk_UA.po`
- Build `.mo` files (for WordPress to load), for example:
  - `msgfmt languages/ru_RU.po -o languages/ru_RU.mo`
  - `msgfmt languages/uk_UA.po -o languages/uk_UA.mo`

Roadmap
-------
- Optional dynamic pricing via filters (without writing `_price`).
- Variations support.
- Import rates from external sources (opt-in).

Capabilities & Uninstall
------------------------
- Capability: filtered via `tkcm_manage_cap` (default `manage_woocommerce`).
- Uninstall: removes options `teplo_klimat_currency_params` and `teplo_klimat_num_elements`.

License
-------
GPL-2.0-or-later
