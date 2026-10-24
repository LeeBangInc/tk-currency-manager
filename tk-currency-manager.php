<?php
/**
 * Plugin Name: TK Currency Manager for WooCommerce
 * Description: Currency-based pricing helper: define currencies with coefficients, set product prices in a chosen currency, and recalculate WooCommerce prices.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Author: TK
 * Text Domain: tk-currency-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('TKCM_VERSION', '0.2.0');
define('TKCM_PLUGIN_FILE', __FILE__);
define('TKCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TKCM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Back-compat: reuse existing option/meta keys from the old theme code
define('TKCM_OPT_CURRENCIES', 'teplo_klimat_currency_params');
define('TKCM_OPT_COUNT', 'teplo_klimat_num_elements');

require_once TKCM_PLUGIN_DIR . 'includes/pricing.php';
require_once TKCM_PLUGIN_DIR . 'includes/product-admin.php';

// Helper: check if WooCommerce is active
function tkcm_wc_active() {
    return class_exists('WooCommerce');
}

// Load textdomain
add_action('init', function () {
    load_plugin_textdomain('tk-currency-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Admin menu: WooCommerce -> Currencies (TK)
add_action('admin_menu', function () {
    if (!tkcm_wc_active()) {
        return;
    }
    $cap = apply_filters('tkcm_manage_cap', 'manage_woocommerce');
    if (!current_user_can($cap)) {
        return;
    }
    add_submenu_page(
        'woocommerce',
        __('Currencies', 'tk-currency-manager') . ' (TK)',
        __('Currencies (TK)', 'tk-currency-manager'),
        $cap,
        'tk-currency-manager',
        'tkcm_render_settings_page'
    );
});

// Enqueue admin scripts/styles only on our page
add_action('admin_enqueue_scripts', function ($hook) {
    if (!tkcm_wc_active()) { return; }
    if (isset($_GET['page']) && $_GET['page'] === 'tk-currency-manager') {
        wp_enqueue_script('jquery-ui-sortable');
        $css_file = TKCM_PLUGIN_DIR . 'assets/admin.css';
        $js_file  = TKCM_PLUGIN_DIR . 'assets/admin.js';
        $css_ver = file_exists($css_file) ? filemtime($css_file) : TKCM_VERSION;
        $js_ver  = file_exists($js_file) ? filemtime($js_file) : TKCM_VERSION;
        wp_enqueue_style('tkcm-admin', TKCM_PLUGIN_URL . 'assets/admin.css', [], $css_ver);
        wp_enqueue_script('tkcm-admin', TKCM_PLUGIN_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable'], $js_ver, true);
        wp_localize_script('tkcm-admin', 'tkcmAdmin', [
            'nonce' => wp_create_nonce('tkcm_recalculate'),
            'progress' => __('Updated: %1$d; skipped: %2$d; errors: %3$d.', 'tk-currency-manager'),
            'complete' => __('Recalculation complete.', 'tk-currency-manager'),
            'failed' => __('Request failed. Some prices may already be updated. Fix the problem and run again.', 'tk-currency-manager'),
        ]);
    }
});

// Admin notice if WooCommerce is missing
add_action('admin_notices', function(){
    if (tkcm_wc_active()) { return; }
    if (!current_user_can('activate_plugins')) { return; }
    echo '<div class="notice notice-error"><p>' . esc_html__('TK Currency Manager requires WooCommerce to be active.', 'tk-currency-manager') . '</p></div>';
});

// Render settings page
function tkcm_render_settings_page()
{
    $cap = apply_filters('tkcm_manage_cap', 'manage_woocommerce');
    if (!current_user_can($cap)) {
        wp_die(esc_html__('You do not have permission to access this page.', 'tk-currency-manager'));
    }

    // Defaults
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    $num_elements = get_option(TKCM_OPT_COUNT, is_array($currencies) ? count($currencies) : 0);

    // Handle POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('tkcm_save_settings', 'tkcm_nonce');

        // Save form
        if (isset($_POST['tkcm_action']) && $_POST['tkcm_action'] === 'save') {
            $max_id = isset($_POST['element-max-id']) ? absint($_POST['element-max-id']) : 0;
            $rows = [];
            // Reject malformed/truncated tables rather than replacing settings partially.
            $valid_size = $max_id <= 200 && isset($_POST['element-max-id']) && is_string($_POST['element-max-id']) && ctype_digit($_POST['element-max-id']);
            $max_id = min($max_id, 200);
            for ($i = 0; $i < $max_id; $i++) {
                $row = [];
                foreach (['name', 'val', 'desc'] as $key) {
                    $field = "cur-$key-id-$i";
                    $row[$key] = isset($_POST[$field]) && is_string($_POST[$field]) ? wp_unslash($_POST[$field]) : null;
                }
                $rows[] = $row;
            }
            $new = $valid_size ? tkcm_validate_currencies($rows) : new WP_Error('tkcm_rows', __('Invalid table size (maximum 200 currencies).', 'tk-currency-manager'));
            if (!is_wp_error($new)) {
                update_option(TKCM_OPT_CURRENCIES, $new);
                update_option(TKCM_OPT_COUNT, count($new));
                $currencies = $new;
                $num_elements = count($new);
                echo '<div class="updated"><p>' . esc_html__('Settings saved', 'tk-currency-manager') . '</p></div>';
            } else {
                $msg = esc_html($new->get_error_message());
                echo '<div class="error"><p>' . $msg . '</p></div>';
            }
        }

    }

    // Re-read after potential save
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    $num_elements = is_array($currencies) ? count($currencies) : 0;

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Manage Currencies', 'tk-currency-manager'); ?></h1>
        <p class="description"><?php esc_html_e('The first currency in the list acts as default when a product has no specific currency set. You can also use a custom "currency" as a markup/discount coefficient.', 'tk-currency-manager'); ?></p>
        <p class="description"><?php esc_html_e('Tip: drag rows to reorder; the first item becomes the default currency.', 'tk-currency-manager'); ?></p>

        <form method="post">
            <?php wp_nonce_field('tkcm_save_settings', 'tkcm_nonce'); ?>
            <input type="hidden" name="tkcm_action" value="save" />
            <ul id="tkcm-currency-list" class="tkcm-list">
                <li class="tkcm-row tkcm-head">
                    <div></div>
                    <div><?php esc_html_e('Code', 'tk-currency-manager'); ?> *</div>
                    <div><?php esc_html_e('Rate', 'tk-currency-manager'); ?> *</div>
                    <div><?php esc_html_e('Description', 'tk-currency-manager'); ?></div>
                    <div></div>
                </li>
                <?php $i = 0; if (!empty($currencies) && is_array($currencies)) : foreach ($currencies as $c) : ?>
                    <li class="tkcm-row" id="tkcm-row-<?php echo (int)$i; ?>">
                        <div class="tkcm-drag">&#8226;</div>
                        <div><input type="text" aria-label="<?php esc_attr_e('Currency code', 'tk-currency-manager'); ?>" name="cur-name-id-<?php echo (int)$i; ?>" value="<?php echo esc_attr($c['name']); ?>" size="5"></div>
                        <div><input type="text" aria-label="<?php esc_attr_e('Conversion rate', 'tk-currency-manager'); ?>" name="cur-val-id-<?php echo (int)$i; ?>" value="<?php echo esc_attr($c['val']); ?>"></div>
                        <div><input type="text" aria-label="<?php esc_attr_e('Currency description', 'tk-currency-manager'); ?>" name="cur-desc-id-<?php echo (int)$i; ?>" value="<?php echo esc_attr($c['desc']); ?>"></div>
                        <div><a href="#" class="tkcm-remove" aria-label="<?php esc_attr_e('Remove currency', 'tk-currency-manager'); ?>">×</a></div>
                    </li>
                <?php $i++; endforeach; endif; ?>
            </ul>
            <input type="hidden" name="element-max-id" value="<?php echo (int)$i; ?>" />

            <p><a href="#" id="tkcm-add-currency" class="button"><?php esc_html_e('Add currency', 'tk-currency-manager'); ?></a></p>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'tk-currency-manager'); ?></button>
            </p>
        </form>

        <p><button type="button" id="tkcm-recalculate" class="button"><?php esc_html_e('Recalculate prices', 'tk-currency-manager'); ?></button></p>
        <p><?php esc_html_e('Processes 50 catalog records per request. Keep this page open; do not change the catalog or rates during a run.', 'tk-currency-manager'); ?></p>
        <noscript><?php esc_html_e('JavaScript is required for bulk recalculation. Alternatively use: wp tk-currency recalc', 'tk-currency-manager'); ?></noscript>
        <div id="tkcm-recalculate-status" role="status" aria-live="polite"></div>

        <ul id="tkcm-row-template" class="tkcm-list" style="display:none;">
            <li class="tkcm-row">
                <div class="tkcm-drag">&#8226;</div>
                <div><input type="text" aria-label="<?php esc_attr_e('Currency code', 'tk-currency-manager'); ?>" name="cur-name-id" value="" size="5"></div>
                <div><input type="text" aria-label="<?php esc_attr_e('Conversion rate', 'tk-currency-manager'); ?>" name="cur-val-id" value=""></div>
                <div><input type="text" aria-label="<?php esc_attr_e('Currency description', 'tk-currency-manager'); ?>" name="cur-desc-id" value=""></div>
                <div><a href="#" class="tkcm-remove" aria-label="<?php esc_attr_e('Remove currency', 'tk-currency-manager'); ?>">×</a></div>
            </li>
        </ul>
    </div>
    <?php
}

// Product: add fields to General tab (avoid duplicate if legacy theme function exists)
add_action('woocommerce_product_options_general_product_data', function () {
    wp_nonce_field('tkcm_product_fields', 'tkcm_product_nonce');
    if (function_exists('woo_add_custom_general_fields')) {
        return;
    }
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    $options = ['' => __('Default (first configured currency)', 'tk-currency-manager')];
    if (!empty($currencies) && is_array($currencies)) {
        foreach ($currencies as $c) {
            if (!empty($c['name'])) {
                $options[$c['name']] = $c['name'];
            }
        }
    }

    global $post;
    $selected = $post ? get_post_meta($post->ID, 'tk_currency', true) : '';
    if (is_string($selected) && $selected !== '' && !isset($options[$selected])) {
        $options[$selected] = sprintf(__('%s (not configured)', 'tk-currency-manager'), $selected);
    }
    echo '<div class="options_group show_if_simple">';
    woocommerce_wp_select([
        'id' => 'tk_currency',
        'label' => __('Currency', 'tk-currency-manager'),
        'description' => __('Currency of the input price', 'tk-currency-manager'),
        'desc_tip' => true,
        'options' => $options,
    ]);

    woocommerce_wp_text_input([
        'id' => 'tk_curr_price',
        'label' => __('Price in currency', 'tk-currency-manager'),
        'description' => __('Empty: keep store prices. Zero: free product.', 'tk-currency-manager'),
        'desc_tip' => true,
        'placeholder' => '0',
        'type' => 'text',
    ]);

    woocommerce_wp_text_input([
        'id' => 'tk_curr_sale',
        'label' => __('Sale price in currency', 'tk-currency-manager'),
        'placeholder' => '0',
        'type' => 'text',
    ]);
    echo '</div>';
});

// Variation fields: add meta inputs per variation
add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation){
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    $options = ['' => __('Default (first configured currency)', 'tk-currency-manager')];
    if (!empty($currencies) && is_array($currencies)) {
        foreach ($currencies as $c) {
            if (!empty($c['name'])) {
                $options[$c['name']] = $c['name'];
            }
        }
    }
    echo '<input type="hidden" name="tkcm_product_nonce" value="' . esc_attr(wp_create_nonce('tkcm_product_fields')) . '" />';
    $var_id = $variation->ID;
    $val_currency = get_post_meta($var_id, 'tk_currency', true);
    $val_price = get_post_meta($var_id, 'tk_curr_price', true);
    $val_sale = get_post_meta($var_id, 'tk_curr_sale', true);

    if (is_string($val_currency) && $val_currency !== '' && !isset($options[$val_currency])) {
        $options[$val_currency] = sprintf(__('%s (not configured)', 'tk-currency-manager'), $val_currency);
    }
    echo '<div class="form-row form-row-full">';
    woocommerce_wp_select([
        'id' => "tk_currency[{$loop}]",
        'name' => "tk_currency[{$loop}]",
        'value' => $val_currency,
        'label' => __('Currency', 'tk-currency-manager'),
        'description' => __('Currency of the input price', 'tk-currency-manager'),
        'desc_tip' => true,
        'options' => $options,
    ]);
    woocommerce_wp_text_input([
        'id' => "tk_curr_price[{$loop}]",
        'name' => "tk_curr_price[{$loop}]",
        'value' => $val_price,
        'label' => __('Price in currency', 'tk-currency-manager'),
        'description' => __('Empty: keep store prices. Zero: free product.', 'tk-currency-manager'),
        'desc_tip' => true,
        'placeholder' => '0',
        'type' => 'text',
    ]);
    woocommerce_wp_text_input([
        'id' => "tk_curr_sale[{$loop}]",
        'name' => "tk_curr_sale[{$loop}]",
        'value' => $val_sale,
        'label' => __('Sale price in currency', 'tk-currency-manager'),
        'placeholder' => '0',
        'type' => 'text',
    ]);
    echo '</div>';
}, 10, 3);

// Register only after all active plugins have loaded, including WooCommerce.
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI && tkcm_wc_active()) {
        WP_CLI::add_command('tk-currency recalc', function () {
            $result = tkcm_recalculate_all_products();
            foreach ($result['messages'] as $message) {
                WP_CLI::warning($message);
            }
            $summary = sprintf('Updated: %d; skipped: %d; errors: %d.', $result['updated'], $result['skipped'], $result['errors']);
            if ($result['errors']) {
                WP_CLI::error($summary);
            }
            WP_CLI::success($summary);
        });
    }
}, 20);
