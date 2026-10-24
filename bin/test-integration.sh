#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Only this throwaway Compose project is removed; no DDEV/shared containers.
project="tkcm-tests-$$"
compose=(docker compose -p "$project" -f tests/compose.yaml)
cleanup() {
  if [[ "${TKCM_KEEP_TEST_STORE:-0}" == 1 ]]; then
    echo "Retained test store: $project (remove with docker compose -p $project -f tests/compose.yaml down --volumes)"
  else
    "${compose[@]}" down --volumes --remove-orphans >/dev/null
  fi
}
trap cleanup EXIT
"${compose[@]}" up -d --wait db wordpress
wp() { "${compose[@]}" run --rm -T cli php -d memory_limit=512M /usr/local/bin/wp "$@"; }
# Pin the tested versions; override to run a separate compatibility matrix.
wp core download --version="${TKCM_WP_VERSION:-7.1.3}" --force
wp core install --url=http://wordpress --title='TKCM test store' --admin_user=tkcm-test --admin_password='disposable-test-only' --admin_email=test@example.org --skip-email
wp plugin install woocommerce --version="${TKCM_WC_VERSION:-11.2.1}" --activate
wp plugin activate tk-currency-manager
wp option update active_plugins '["woocommerce/woocommerce.php","tk-currency-manager/tk-currency-manager.php"]' --format=json
wp eval-file wp-content/plugins/tk-currency-manager/tests/integration.php
wp tk-currency recalc
error_id=$(wp eval '$p = new WC_Product_Simple(); $p->set_name("CLI error fixture"); $p->set_regular_price("123"); $id = $p->save(); update_post_meta($id, "tk_curr_price", "10"); update_post_meta($id, "tk_currency", "REMOVED"); echo $id;')
if wp tk-currency recalc; then
  echo 'Expected CLI failure for unknown currency' >&2
  exit 1
fi
wp post delete "$error_id" --force
# Exercise the reverse active-plugin load order in a fresh WordPress process.
wp option update active_plugins '["tk-currency-manager/tk-currency-manager.php","woocommerce/woocommerce.php"]' --format=json
wp eval-file wp-content/plugins/tk-currency-manager/tests/integration.php
wp plugin deactivate woocommerce --skip-plugins=woocommerce
wp eval 'if (!has_action("woocommerce_process_product_meta")) { throw new Exception("Missing late-load hooks"); } echo "WooCommerce absent: plugin loads without fatal.\n";'
