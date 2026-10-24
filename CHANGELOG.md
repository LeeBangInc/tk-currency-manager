# Changelog

## [0.2.0] — Unreleased

### Fixed

- Products and variations without a source regular price keep their existing prices. An explicit zero remains valid.
- Product hooks register regardless of whether WooCommerce loads before or after this plugin; CLI registration waits for dependencies.
- Reject malformed/negative decimals, duplicate currency codes, non-positive rounded rates, unknown currencies and invalid sale prices. Validate the complete source-field candidate before writing metadata.
- Protect product/variation form saves with nonces and per-product permissions; ignore unrelated save requests.
- Synchronize variable parent price ranges after variation updates.
- Clear the source sale when WooCommerce's scheduled task ends a sale, preventing a later rate change from reviving the discount.
- Preserve legacy/shared currency settings and source metadata on uninstall.

### Changed

- Admin bulk recalculation uses sequential requests of 50 catalog records and reports updated/skipped/errors. CLI uses the same batching and returns a failure status when products fail.
- Recalculation helpers return structured result arrays instead of an ambiguous processed-product count.
- Separate validation/pricing and protected admin handlers from the bootstrap/UI.
- Document conversion rules, migration, limitations, data retention and the actual verification matrix; add screenshots from a synthetic store.
- Package from an explicit allowlist; retain the `bin/build-zip.sh` entrypoint as a wrapper.
- Add standalone checks, disposable-store integration tests and GitHub Actions configuration. Remote CI has not run before publication.

### Verification — 2026-10-24

- PHP 7.4, 8.2 and 8.4: lint, 19 decimal/currency checks and three dependency-load probes each.
- WordPress 7.1.3 / WooCommerce 11.2.1 / PHP 8.3.33: 63 integration checks with each active-plugin order.
- WordPress 6.8.3 / WooCommerce 10.3.5 / PHP 8.3.33: the same 63 checks with each order.
- Integration covers CRUD prices, sale clearing/scheduling/expiry, lookup tables, variations and parent ranges, cart totals, batch boundaries, HTTP settings/AJAX, permissions/nonces and uninstall retention.
- Browser check: add and save a comma-decimal rate; run two AJAX batches over 53 synthetic products (52 updated, 1 skipped, 0 errors), without console errors/warnings. No live store or payment gateway was used.
- JavaScript/shell syntax and installable ZIP contents checked locally. CI configuration is ready for the first push.

## [0.1.0] — 2024-09-25

- Initial public release.
