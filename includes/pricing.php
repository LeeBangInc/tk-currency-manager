<?php
/** Source-price validation and WooCommerce persistence. */
if (!defined('ABSPATH')) {
    exit;
}

/** Return a decimal string, an empty value, or WP_Error; never repair bad input. */
function tkcm_sanitize_decimal($value) {
    if (!is_scalar($value) || is_bool($value)) {
        return new WP_Error('tkcm_decimal', __('Enter a non-negative decimal number.', 'tk-currency-manager'));
    }
    $value = str_replace(',', '.', trim((string) $value));
    if ($value === '') {
        return '';
    }
    if (strlen($value) > 32 || !preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)$/D', $value) || !is_finite((float) $value)) {
        return new WP_Error('tkcm_decimal', __('Enter a non-negative decimal number without thousands separators.', 'tk-currency-manager'));
    }
    return $value;
}

/** Validate the entire settings table before writing either legacy option. */
function tkcm_validate_currencies($rows) {
    $result = [];
    $names = [];
    foreach ($rows as $row) {
        // Legacy theme options may store a numeric rate rather than a string.
        if (is_array($row) && isset($row['val']) && (is_int($row['val']) || is_float($row['val']))) {
            $row['val'] = (string) $row['val'];
        }
        foreach (['name', 'val', 'desc'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key])) {
                return new WP_Error('tkcm_currency', __('Invalid currency row.', 'tk-currency-manager'));
            }
        }
        $name = sanitize_text_field($row['name']);
        $desc = sanitize_text_field($row['desc']);
        $rate = tkcm_sanitize_decimal($row['val']);
        if ($name === '' && $row['val'] === '' && $desc === '') {
            continue;
        }
        if ($name === '' || isset($names[$name]) || is_wp_error($rate) || $rate === '') {
            return new WP_Error('tkcm_currency', __('Use unique currency codes and valid positive rates.', 'tk-currency-manager'));
        }
        $rate = rtrim(rtrim(number_format((float) $rate, 6, '.', ''), '0'), '.');
        if ((float) $rate <= 0) {
            return new WP_Error('tkcm_rate', __('Rates must remain greater than zero at six decimal places.', 'tk-currency-manager'));
        }
        $names[$name] = true;
        $result[] = ['name' => $name, 'val' => $rate, 'desc' => $desc];
    }
    return $result;
}

function tkcm_source_fields($id) {
    return [
        'tk_currency' => get_post_meta($id, 'tk_currency', true),
        'tk_curr_price' => get_post_meta($id, 'tk_curr_price', true),
        'tk_curr_sale' => get_post_meta($id, 'tk_curr_sale', true),
    ];
}

/** Empty source price opts out. Empty currency alone explicitly uses the first row. */
function tkcm_calculate_prices($fields) {
    $regular = tkcm_sanitize_decimal($fields['tk_curr_price']);
    $sale = tkcm_sanitize_decimal($fields['tk_curr_sale']);
    if (is_wp_error($regular)) {
        return $regular;
    }
    if (is_wp_error($sale)) {
        return $sale;
    }
    if ($regular === '') {
        return null;
    }
    $currencies = get_option(TKCM_OPT_CURRENCIES, []);
    if (!is_array($currencies)) {
        return new WP_Error('tkcm_currency', __('Invalid currency settings.', 'tk-currency-manager'));
    }
    $currencies = tkcm_validate_currencies($currencies);
    if (is_wp_error($currencies) || !$currencies) {
        return new WP_Error('tkcm_currency', __('Configure valid currencies before recalculating.', 'tk-currency-manager'));
    }
    $rate = null;
    foreach ($currencies as $row) {
        if ($fields['tk_currency'] === '' || $row['name'] === $fields['tk_currency']) {
            $rate = (float) $row['val'];
            break;
        }
    }
    if ($rate === null) {
        return new WP_Error('tkcm_currency', __('Source currency is no longer configured. Select a valid currency.', 'tk-currency-manager'));
    }
    $regular_value = (float) $regular * $rate;
    $sale_value = $sale === '' ? null : (float) $sale * $rate;
    if (!is_finite($regular_value) || ($sale_value !== null && !is_finite($sale_value))) {
        return new WP_Error('tkcm_overflow', __('Converted price is too large.', 'tk-currency-manager'));
    }
    $decimals = wc_get_price_decimals();
    $regular = wc_format_decimal($regular_value, $decimals);
    $sale = $sale_value === null ? '' : wc_format_decimal($sale_value, $decimals);
    if ($sale !== '' && (float) $sale >= (float) $regular) {
        return new WP_Error('tkcm_sale', __('Converted sale price must be lower than the regular price.', 'tk-currency-manager'));
    }
    return ['regular' => $regular, 'sale' => $sale];
}

function tkcm_empty_result() {
    return ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
}

function tkcm_merge_results($a, $b) {
    foreach (['updated', 'skipped', 'errors'] as $key) {
        $a[$key] += $b[$key];
    }
    $a['messages'] = array_slice(array_merge($a['messages'], $b['messages']), 0, 10);
    return $a;
}

/** Report individual leaf products; variable containers have no source price. */
function tkcm_recalculate_product($id) {
    $result = tkcm_empty_result();
    if (!tkcm_wc_active()) {
        $result['errors']++;
        $result['messages'][] = __('WooCommerce is not active.', 'tk-currency-manager');
        return $result;
    }
    $product = wc_get_product($id);
    if (!$product) {
        $result['errors']++;
        $result['messages'][] = sprintf(__('Product #%d was not found.', 'tk-currency-manager'), $id);
        return $result;
    }
    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $child_id) {
            $result = tkcm_merge_results($result, tkcm_recalculate_product($child_id));
        }
        return $result;
    }
    if (!$product->is_type(['simple', 'variation'])) {
        $result['skipped']++;
        return $result;
    }
    $prices = tkcm_calculate_prices(tkcm_source_fields($id));
    if (is_wp_error($prices)) {
        $result['errors']++;
        $result['messages'][] = sprintf('#%d: %s', $id, $prices->get_error_message());
        return $result;
    }
    if ($prices === null) {
        $result['skipped']++;
        return $result;
    }
    try {
        $product->set_regular_price($prices['regular']);
        $product->set_sale_price($prices['sale']);
        // WooCommerce's data store maintains the active price, sale dates and lookup table.
        $product->save();
        if ($product->is_type('variation') && $product->get_parent_id()) {
            WC_Product_Variable::sync($product->get_parent_id());
            wc_delete_product_transients($product->get_parent_id());
        }
        $result['updated']++;
    } catch (Exception $error) {
        $result['errors']++;
        $result['messages'][] = sprintf('#%d: %s', $id, $error->getMessage());
    }
    return $result;
}

/** One bounded request: variations are queried directly, not recursively per parent. */
function tkcm_recalculate_batch($page = 1) {
    $query = new WP_Query([
        'post_type' => ['product', 'product_variation'],
        'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
        'fields' => 'ids',
        'posts_per_page' => 50,
        'paged' => max(1, (int) $page),
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);
    $result = tkcm_empty_result();
    foreach ($query->posts as $id) {
        $product = wc_get_product($id);
        if ($product && $product->is_type('variable')) {
            continue;
        }
        $result = tkcm_merge_results($result, tkcm_recalculate_product($id));
    }
    $result['done'] = count($query->posts) < 50;
    return $result;
}

function tkcm_recalculate_all_products() {
    $result = tkcm_empty_result();
    $page = 1;
    do {
        $batch = tkcm_recalculate_batch($page++);
        $result = tkcm_merge_results($result, $batch);
    } while (!$batch['done']);
    return $result;
}

// WooCommerce clears an expired sale's dates and store price. Clear the source
// sale too, so a later rate recalculation cannot revive that expired discount.
add_action('wc_after_products_ending_sales', function ($ids) {
    foreach ($ids as $id) {
        if (get_post_meta($id, 'tk_curr_price', true) !== '') {
            update_post_meta($id, 'tk_curr_sale', '');
        }
    }
});
