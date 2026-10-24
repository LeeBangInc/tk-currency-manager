<?php
/** Minimal hook probes; real WooCommerce behavior is tested separately. */
$mode = $argv[1] ?? 'absent';
define('ABSPATH', __DIR__ . '/');
$hooks = [];
function add_action($name, $callback, $priority = 10, $args = 1) {
    global $hooks;
    $hooks[$name][] = $callback;
}
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.org/plugin/'; }
if ($mode === 'before') {
    eval('class WooCommerce {}');
}
require dirname(__DIR__) . '/tk-currency-manager.php';
if ($mode === 'after') {
    eval('class WooCommerce {}');
}
foreach (['woocommerce_product_options_general_product_data', 'woocommerce_process_product_meta', 'woocommerce_save_product_variation', 'woocommerce_product_after_variable_attributes'] as $name) {
    if (empty($hooks[$name])) {
        throw new RuntimeException('Hook not registered: ' . $name);
    }
}
if (tkcm_wc_active() !== ($mode !== 'absent')) {
    throw new RuntimeException('Dependency detection mismatch');
}
echo "PASS: WooCommerce $mode plugin load.\n";
