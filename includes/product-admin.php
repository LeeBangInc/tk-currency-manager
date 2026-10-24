<?php
/** Admin writes only run with this plugin's nonce and per-product authorization. */
if (!defined('ABSPATH')) {
    exit;
}

function tkcm_can_save_source_fields($id) {
    return current_user_can('edit_product', $id)
        && !(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        && !wp_is_post_revision($id)
        && isset($_POST['tkcm_product_nonce'])
        && is_string($_POST['tkcm_product_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tkcm_product_nonce'])), 'tkcm_product_fields');
}

/** Validate a complete candidate before changing any source metadata. */
function tkcm_save_source_fields($id, $posted) {
    $fields = tkcm_source_fields($id);
    $changed = false;
    foreach (array_keys($fields) as $key) {
        if (!array_key_exists($key, $posted)) {
            continue;
        }
        $changed = true;
        if (!is_string($posted[$key])) {
            return new WP_Error('tkcm_input', __('Invalid source-price field.', 'tk-currency-manager'));
        }
        $value = wp_unslash($posted[$key]);
        $value = $key === 'tk_currency' ? sanitize_text_field($value) : tkcm_sanitize_decimal($value);
        if (is_wp_error($value)) {
            return $value;
        }
        $fields[$key] = $value;
    }
    if (!$changed) {
        return tkcm_empty_result();
    }
    $prices = tkcm_calculate_prices($fields);
    if (is_wp_error($prices)) {
        return $prices;
    }
    foreach ($fields as $key => $value) {
        update_post_meta($id, $key, $value);
    }
    return tkcm_recalculate_product($id);
}

function tkcm_show_save_result($result) {
    if (is_wp_error($result)) {
        WC_Admin_Meta_Boxes::add_error($result->get_error_message());
    } elseif ($result['errors']) {
        foreach ($result['messages'] as $message) {
            WC_Admin_Meta_Boxes::add_error($message);
        }
    }
}

add_action('woocommerce_process_product_meta', function ($id) {
    if (!tkcm_can_save_source_fields($id)) {
        return;
    }
    // The parent variable product only contains array-valued variation fields.
    $product = wc_get_product($id);
    if (!$product || !$product->is_type('simple')) {
        return;
    }
    tkcm_show_save_result(tkcm_save_source_fields($id, $_POST));
}, 20);

add_action('woocommerce_save_product_variation', function ($id, $index) {
    if (!tkcm_can_save_source_fields($id)) {
        return;
    }
    $posted = [];
    foreach (['tk_currency', 'tk_curr_price', 'tk_curr_sale'] as $key) {
        if (isset($_POST[$key]) && is_array($_POST[$key]) && array_key_exists($index, $_POST[$key])) {
            $posted[$key] = $_POST[$key][$index];
        }
    }
    tkcm_show_save_result(tkcm_save_source_fields($id, $posted));
}, 20, 2);

add_action('wp_ajax_tkcm_recalculate', function () {
    if (!current_user_can(apply_filters('tkcm_manage_cap', 'manage_woocommerce'))) {
        wp_send_json_error(['message' => __('Permission denied.', 'tk-currency-manager')], 403);
    }
    check_ajax_referer('tkcm_recalculate', 'nonce');
    if (!tkcm_wc_active()) {
        wp_send_json_error(['message' => __('WooCommerce is not active.', 'tk-currency-manager')], 400);
    }
    $page = isset($_POST['page']) && is_scalar($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
    wp_send_json_success(tkcm_recalculate_batch($page));
});
