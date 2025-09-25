<?php
/**
 * Plugin Name: TK Currency Manager for WooCommerce
 * Description: Currency-based pricing helper: define currencies with coefficients, set product prices in a chosen currency, and recalculate WooCommerce prices.
 * Version: 0.1.0
 * Author: TK
 * Text Domain: tk-currency-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('TKCM_VERSION', '0.1.0');
define('TKCM_PLUGIN_FILE', __FILE__);
define('TKCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TKCM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Back-compat: reuse existing option/meta keys from the old theme code
define('TKCM_OPT_CURRENCIES', 'teplo_klimat_currency_params');
define('TKCM_OPT_COUNT', 'teplo_klimat_num_elements');

// Helper: check if WooCommerce is active
function tkcm_wc_active() {
    return class_exists('WooCommerce');
}

// Load textdomain
add_action('plugins_loaded', function () {
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
            $new = [];
            $error_code = '';
            for ($i = 0; $i < $max_id; $i++) {
                $name = isset($_POST["cur-name-id-$i"]) ? sanitize_text_field(wp_unslash($_POST["cur-name-id-$i"])) : '';
                $val = isset($_POST["cur-val-id-$i"]) ? wp_unslash($_POST["cur-val-id-$i"]) : '';
                $desc = isset($_POST["cur-desc-id-$i"]) ? sanitize_text_field(wp_unslash($_POST["cur-desc-id-$i"])) : '';

                if ($name === '' && $val === '' && $desc === '') {
                    continue; // skip empty row
                }

                // normalize: allow comma input, save with dot; require > 0
                $val_clean = str_replace(',', '.', trim((string)$val));
                if ($name === '' || $val_clean === '') {
                    $error_code = 'empty';
                } elseif (!preg_match('/^\d*(?:\.\d+)?$/', $val_clean)) {
                    $error_code = 'format';
                } else {
                    $num = (float) $val_clean;
                    if ($num <= 0) {
                        $error_code = 'non_positive';
                    } else {
                        // round to 6 decimals for financial stability
                        $val_clean = rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.');
                    }
                }

                $new[] = [
                    'name' => $name,
                    'val' => $val_clean,
                    'desc' => $desc,
                ];
            }

            if ($error_code === '') {
                update_option(TKCM_OPT_CURRENCIES, $new);
                update_option(TKCM_OPT_COUNT, count($new));
                $currencies = $new;
                $num_elements = count($new);
                echo '<div class="updated"><p>' . esc_html__('Settings saved', 'tk-currency-manager') . '</p></div>';
            } else {
                $msg = esc_html__('Please fill all required fields; "Rate" must be a number with dot as a decimal separator.', 'tk-currency-manager');
                if ($error_code === 'non_positive') {
                    $msg = esc_html__('Rate must be greater than zero.', 'tk-currency-manager');
                } elseif ($error_code === 'format') {
                    $msg = esc_html__('Rate must be a valid decimal number (dot as decimal separator).', 'tk-currency-manager');
                }
                echo '<div class="error"><p>' . $msg . '</p></div>';
            }
        }

        // Bulk recalc
        if (isset($_POST['tkcm_action']) && $_POST['tkcm_action'] === 'recalc') {
            if (!class_exists('WooCommerce')) {
                echo '<div class="error"><p>' . esc_html__('WooCommerce is not active.', 'tk-currency-manager') . '</p></div>';
            } else {
                $count = tkcm_recalculate_all_products();
                echo '<div class="updated"><p>' . sprintf(esc_html__('Products recalculated: %d', 'tk-currency-manager'), (int) $count) . '</p></div>';
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
                        <div><input type="text" name="cur-name-id-<?php echo (int)$i; ?>" value="<?php echo esc_attr($c['name']); ?>" size="5"></div>
                        <div><input type="text" name="cur-val-id-<?php echo (int)$i; ?>" value="<?php echo esc_attr($c['val']); ?>"></div>
                        <div><input type="text" name="cur-desc-id-<?php echo (int)$i; ?>" value="<?php echo esc_attr($c['desc']); ?>"></div>
                        <div><a href="#" class="tkcm-remove">×</a></div>
                    </li>
                <?php $i++; endforeach; endif; ?>
            </ul>
            <input type="hidden" name="element-max-id" value="<?php echo (int)$i; ?>" />

            <p><a href="#" id="tkcm-add-currency" class="button"><?php esc_html_e('Add currency', 'tk-currency-manager'); ?></a></p>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'tk-currency-manager'); ?></button>
            </p>
        </form>

        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('tkcm_save_settings', 'tkcm_nonce'); ?>
            <input type="hidden" name="tkcm_action" value="recalc" />
            <button type="submit" class="button"><?php esc_html_e('Recalculate prices', 'tk-currency-manager'); ?></button>
        </form>

        <ul id="tkcm-row-template" class="tkcm-list" style="display:none;">
            <li class="tkcm-row">
                <div class="tkcm-drag">&#8226;</div>
                <div><input type="text" name="cur-name-id" value="" size="5"></div>
                <div><input type="text" name="cur-val-id" value=""></div>
                <div><input type="text" name="cur-desc-id" value=""></div>
                <div><a href="#" class="tkcm-remove">×</a></div>
            </li>
        </ul>
    </div>
    <?php
}

// Product: add fields to General tab (avoid duplicate if legacy theme function exists)
if (tkcm_wc_active() && !function_exists('woo_add_custom_general_fields')) {
add_action('woocommerce_product_options_general_product_data', function () {
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    $options = [];
    if (!empty($currencies) && is_array($currencies)) {
        foreach ($currencies as $c) {
            if (!empty($c['name'])) {
                $options[$c['name']] = $c['name'];
            }
        }
    }

    echo '<div class="options_group">';
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
}

// Save product fields
if (tkcm_wc_active()) add_action('woocommerce_process_product_meta', function ($post_id) {
    if (!current_user_can('edit_product', $post_id)) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    $map = [
        'tk_currency' => ['sanitize' => 'sanitize_text_field'],
        'tk_curr_price' => ['sanitize' => 'tkcm_sanitize_decimal'],
        'tk_curr_sale' => ['sanitize' => 'tkcm_sanitize_decimal_allow_empty'],
    ];
    foreach ($map as $key => $rules) {
        if (isset($_POST[$key])) {
            $raw = wp_unslash($_POST[$key]);
            $val = call_user_func($rules['sanitize'], $raw);
            update_post_meta($post_id, $key, $val);
        }
    }

    // Recalculate prices for this product
    tkcm_recalculate_product($post_id);
});

function tkcm_sanitize_decimal($val)
{
    $val = str_replace(',', '.', trim((string)$val));
    if ($val === '') return '';
    return preg_replace('/[^0-9\.]/', '', $val);
}

function tkcm_sanitize_decimal_allow_empty($val)
{
    $v = tkcm_sanitize_decimal($val);
    return $v; // empty string allowed
}

// Variation fields: add meta inputs per variation
if (tkcm_wc_active()) add_action('woocommerce_product_after_variable_attributes', function($loop, $variation_data, $variation){
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    $options = [];
    if (!empty($currencies) && is_array($currencies)) {
        foreach ($currencies as $c) {
            if (!empty($c['name'])) {
                $options[$c['name']] = $c['name'];
            }
        }
    }
    $var_id = $variation->ID;
    $val_currency = get_post_meta($var_id, 'tk_currency', true);
    $val_price = get_post_meta($var_id, 'tk_curr_price', true);
    $val_sale = get_post_meta($var_id, 'tk_curr_sale', true);

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

// Save variation fields and recalc
if (tkcm_wc_active()) add_action('woocommerce_save_product_variation', function($variation_id, $i){
    $map = [
        'tk_currency' => ['sanitize' => 'sanitize_text_field'],
        'tk_curr_price' => ['sanitize' => 'tkcm_sanitize_decimal'],
        'tk_curr_sale' => ['sanitize' => 'tkcm_sanitize_decimal_allow_empty'],
    ];
    foreach ($map as $key => $rules) {
        if (isset($_POST[$key]) && isset($_POST[$key][$i])) {
            $raw = wp_unslash($_POST[$key][$i]);
            $val = call_user_func($rules['sanitize'], $raw);
            update_post_meta($variation_id, $key, $val);
        }
    }
    tkcm_recalculate_product($variation_id);
}, 10, 2);

// Recalc all products helper
function tkcm_recalculate_all_products()
{
    $paged = 1; $pp = 200; $count = 0;
    do {
        $q = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => $pp,
            'paged' => $paged,
        ]);
        if (!$q->have_posts()) { wp_reset_postdata(); break; }
        foreach ($q->posts as $pid) {
            tkcm_recalculate_product($pid);
            $count++;
        }
        wp_reset_postdata();
        $paged++;
    } while (true);
    return $count;
}

// Recalculate prices for a single product using current currency setup
function tkcm_recalculate_product($post_id)
{
    if (!class_exists('WooCommerce')) {
        return;
    }
    $product = wc_get_product($post_id);
    if (!$product) {
        return;
    }

    $compute_and_set = function($prod_id) {
        $regular_in_cur = get_post_meta($prod_id, 'tk_curr_price', true);
        $sale_in_cur = get_post_meta($prod_id, 'tk_curr_sale', true);
        $currency = get_post_meta($prod_id, 'tk_currency', true);

        $currencies = get_option(TKCM_OPT_CURRENCIES, []);
        $coef = 1.0;
        if (!empty($currencies) && is_array($currencies)) {
            $coef = isset($currencies[0]['val']) && $currencies[0]['val'] !== '' ? (float)$currencies[0]['val'] : 1.0;
            foreach ($currencies as $c) {
                if ($currency !== '' && isset($c['name']) && $currency === $c['name']) {
                    $coef = (float)$c['val'];
                    break;
                }
            }
        }

        $dec = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $regular_price = wc_format_decimal( (float)$regular_in_cur * (float)$coef, $dec );
        $sale_price = ($sale_in_cur === '' ? '' : wc_format_decimal( (float)$sale_in_cur * (float)$coef, $dec ));

        $p = wc_get_product($prod_id);
        if ($p) {
            $p->set_regular_price($regular_price);
            $p->set_sale_price($sale_price === '' ? '' : $sale_price);
            $p->save();
        }
    };

    if ($product->is_type('variation')) {
        $parent_id = $product->get_parent_id();
        $compute_and_set($post_id);
        if ($parent_id) {
            wc_delete_product_transients($parent_id);
        }
        return;
    }

    if ($product->is_type('variable')) {
        $children = $product->get_children();
        foreach ($children as $vid) {
            $compute_and_set($vid);
        }
        wc_delete_product_transients($product->get_id());
        return;
    }

    $compute_and_set($post_id);
}

// WP-CLI command: wp tk-currency recalc
if (defined('WP_CLI') && WP_CLI && tkcm_wc_active()) {
    WP_CLI::add_command('tk-currency recalc', function () {
        $count = tkcm_recalculate_all_products();
        WP_CLI::success(sprintf('Recalculated %d products', (int)$count));
    });
}

// Front: ensure we do NOT modify catalog price HTML for coupons (limit to cart/checkout if ever needed)
// Intentionally left out compared to old theme implementation.
