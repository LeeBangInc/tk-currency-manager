<?php
/** Run only in the disposable store: wp eval-file .../tests/integration.php */
if (!defined('WP_CLI') || !WP_CLI || get_bloginfo('name') !== 'TKCM test store') {
    throw new RuntimeException('Use bin/test-integration.sh: this suite creates and deletes fixtures.');
}

global $checks, $ids;
$checks = 0;
$ids = [];
function tkcm_test($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function tkcm_test_product($price = '123') {
    global $ids;
    $p = new WC_Product_Simple();
    $p->set_name('TKCM fixture');
    $p->set_status('publish');
    $p->set_regular_price($price);
    $p->save();
    $ids[] = $p->get_id();
    return $p->get_id();
}
function tkcm_test_fields($id, $price, $sale = '', $currency = 'USD') {
    foreach (['tk_curr_price' => $price, 'tk_curr_sale' => $sale, 'tk_currency' => $currency] as $key => $value) {
        update_post_meta($id, $key, $value);
    }
}
function tkcm_test_price($id, $expected, $context = 'price') {
    tkcm_test((string) wc_get_product($id)->get_price() === $expected, $context . ' #' . $id);
}
function tkcm_test_request($user, $action, $nonce_action = 'tkcm_recalculate', $extra = []) {
    wp_set_current_user($user);
    $token = WP_Session_Tokens::get_instance($user)->create(time() + 300);
    $logged = wp_generate_auth_cookie($user, time() + 300, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $logged;
    $body = $action === 'settings'
        ? ['tkcm_nonce' => wp_create_nonce($nonce_action)]
        : ['action' => $action, 'nonce' => wp_create_nonce($nonce_action)];
    $path = $action === 'settings' ? '/wp-admin/admin.php?page=tk-currency-manager' : '/wp-admin/admin-ajax.php';
    $response = wp_remote_post('http://wordpress' . $path, [
        'timeout' => 60,
        'cookies' => [
            AUTH_COOKIE => wp_generate_auth_cookie($user, time() + 300, 'auth', $token),
            LOGGED_IN_COOKIE => $logged,
        ],
        'body' => array_merge($body, $extra),
    ]);
    unset($_COOKIE[LOGGED_IN_COOKIE]);
    WP_Session_Tokens::get_instance($user)->destroy($token);
    tkcm_test(!is_wp_error($response), 'HTTP request succeeds');
    return $response;
}

try {
    // Fresh WooCommerce installs redirect the first non-AJAX admin request.
    delete_transient('_wc_activation_redirect');
    update_option(TKCM_OPT_CURRENCIES, [['name' => 'USD', 'val' => '40', 'desc' => 'US dollar']]);
    update_option(TKCM_OPT_COUNT, 1);
    update_option('woocommerce_currency', 'UAH');
    update_option('woocommerce_price_num_decimals', 2);
    tkcm_test((bool) has_action('woocommerce_process_product_meta'), 'product save registered');
    tkcm_test((bool) has_action('woocommerce_save_product_variation'), 'variation save registered');

    foreach (['-10', '1.2.3', '12abc', '1 000', '1,234.56', '1e3', ['10']] as $bad) {
        tkcm_test(is_wp_error(tkcm_sanitize_decimal($bad)), 'invalid decimal rejected');
    }
    tkcm_test(tkcm_sanitize_decimal(' 12,50 ') === '12.50', 'comma and outer whitespace');
    tkcm_test(tkcm_sanitize_decimal('0') === '0', 'zero preserved');
    foreach (['0', '-1', '0.0000001'] as $bad_rate) {
        tkcm_test(is_wp_error(tkcm_validate_currencies([['name' => 'USD', 'val' => $bad_rate, 'desc' => '']])), 'bad rate rejected');
    }
    tkcm_test(is_wp_error(tkcm_validate_currencies([
        ['name' => 'USD', 'val' => '1', 'desc' => ''], ['name' => 'USD', 'val' => '2', 'desc' => ''],
    ])), 'duplicate codes rejected');

    $plain = tkcm_test_product();
    tkcm_test(tkcm_recalculate_product($plain)['skipped'] === 1, 'unmanaged product skipped');
    tkcm_test_price($plain, '123', 'unmanaged price preserved');
    $managed = tkcm_test_product();
    tkcm_test_fields($managed, '10', '8');
    tkcm_test(tkcm_recalculate_product($managed)['updated'] === 1, 'managed product updated');
    tkcm_test_price($managed, '320.00', 'active sale price');
    tkcm_test(wc_get_product($managed)->get_regular_price() === '400.00', 'regular price');
    global $wpdb;
    $lookup = $wpdb->get_row($wpdb->prepare("SELECT min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", $managed));
    tkcm_test((float) $lookup->min_price === 320.0 && (float) $lookup->max_price === 320.0, 'Woo lookup price');
    tkcm_test_fields($managed, '10', '');
    tkcm_recalculate_product($managed);
    tkcm_test_price($managed, '400.00', 'sale cleared');
    tkcm_test(wc_get_product($managed)->get_sale_price() === '', 'sale metadata cleared');
    tkcm_test_fields($managed, '10', '8', 'REMOVED');
    tkcm_test(tkcm_recalculate_product($managed)['errors'] === 1, 'unknown currency reported');
    tkcm_test_price($managed, '400.00', 'unknown currency cannot change price');
    tkcm_test_fields($managed, '10', '', '');
    tkcm_recalculate_product($managed);
    tkcm_test_price($managed, '400.00', 'explicit empty currency uses first row');
    tkcm_test_fields($managed, '0');
    tkcm_recalculate_product($managed);
    tkcm_test_price($managed, '0.00', 'intentional free product');

    $before = tkcm_source_fields($managed);
    tkcm_test(is_wp_error(tkcm_save_source_fields($managed, ['tk_curr_price' => '-10', 'tk_currency' => 'EUR'])), 'invalid save reported');
    tkcm_test(tkcm_source_fields($managed) === $before, 'source metadata unchanged atomically');
    tkcm_test_price($managed, '0.00', 'invalid save preserves Woo price');
    tkcm_test(is_wp_error(tkcm_save_source_fields($managed, ['tk_curr_price' => '10', 'tk_curr_sale' => '11'])), 'sale >= regular rejected');
    tkcm_save_source_fields($managed, ['tk_curr_price' => '1,234', 'tk_curr_sale' => '']);
    tkcm_test_price($managed, '49.36', 'price rounding');
    tkcm_save_source_fields($managed, ['tk_curr_price' => '']);
    tkcm_test_price($managed, '49.36', 'clearing source opts out without erasing price');

    $admin = get_user_by('login', 'tkcm-test')->ID;
    wp_set_current_user($admin);
    $_POST = ['tk_curr_price' => '5', 'tk_currency' => 'USD', 'tk_curr_sale' => ''];
    do_action('woocommerce_process_product_meta', $plain);
    tkcm_test(get_post_meta($plain, 'tk_curr_price', true) === '', 'save without nonce ignored');
    $_POST['tkcm_product_nonce'] = wp_create_nonce('tkcm_product_fields');
    do_action('woocommerce_process_product_meta', $plain);
    tkcm_test_price($plain, '200.00', 'product save hook');
    $subscriber = wp_create_user('tkcm-subscriber', 'disposable-test-only', 'subscriber@example.org');
    wp_set_current_user($subscriber);
    $_POST['tk_curr_price'] = '9';
    $_POST['tkcm_product_nonce'] = wp_create_nonce('tkcm_product_fields');
    do_action('woocommerce_process_product_meta', $plain);
    tkcm_test_price($plain, '200.00', 'subscriber cannot save prices');
    $_POST = [];

    $parent = new WC_Product_Variable();
    $parent->set_name('Variable fixture');
    $parent->set_status('publish');
    $parent->save();
    $ids[] = $parent->get_id();
    $variations = [];
    foreach (['77', '88'] as $price) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($parent->get_id());
        $v->set_status('publish');
        $v->set_regular_price($price);
        $v->save();
        $variations[] = $v->get_id();
        $ids[] = $v->get_id();
    }
    tkcm_test_fields($variations[0], '2');
    $r = tkcm_recalculate_product($parent->get_id());
    tkcm_test($r['updated'] === 1 && $r['skipped'] === 1, 'mixed variable leaves');
    tkcm_test_price($variations[0], '80.00', 'managed variation');
    tkcm_test_price($variations[1], '88', 'unmanaged variation preserved');
    $range = wc_get_product($parent->get_id())->get_variation_prices();
    tkcm_test(min($range['price']) == 80 && max($range['price']) == 88, 'parent range synchronized');
    wp_set_current_user($admin);
    $_POST = ['tkcm_product_nonce' => wp_create_nonce('tkcm_product_fields'), 'tk_curr_price' => [0 => '3']];
    do_action('woocommerce_save_product_variation', $variations[0], 0);
    tkcm_test_price($variations[0], '120.00', 'variation save hook');
    $_POST = [];

    $scheduled = tkcm_test_product();
    $p = wc_get_product($scheduled);
    $p->set_date_on_sale_from(time() + DAY_IN_SECONDS);
    $p->set_date_on_sale_to(time() + 2 * DAY_IN_SECONDS);
    $p->save();
    tkcm_test_fields($scheduled, '10', '8');
    tkcm_recalculate_product($scheduled);
    tkcm_test_price($scheduled, '400.00', 'future sale inactive');
    tkcm_test(wc_get_product($scheduled)->get_sale_price() === '320.00', 'future sale retained');

    $p = wc_get_product($scheduled);
    $p->set_date_on_sale_from(time() - DAY_IN_SECONDS);
    $p->set_date_on_sale_to(time() + DAY_IN_SECONDS);
    $p->save();
    // Move an active sale's end into the past, then run WooCommerce's real cron.
    update_post_meta($scheduled, '_sale_price_dates_to', time() - 60);
    wc_scheduled_sales();
    tkcm_test(get_post_meta($scheduled, 'tk_curr_sale', true) === '', 'expired source sale cleared by Woo cron');
    tkcm_test_price($scheduled, '400.00', 'expired sale ended');
    tkcm_recalculate_product($scheduled);
    tkcm_test_price($scheduled, '400.00', 'recalculation cannot revive expired sale');

    WC()->initialize_session();
    WC()->initialize_cart();
    WC()->cart->empty_cart();
    $cart_key = WC()->cart->add_to_cart($plain, 2);
    tkcm_test((bool) $cart_key, 'converted product purchasable');
    WC()->cart->calculate_totals();
    tkcm_test((float) WC()->cart->get_subtotal() === 400.0, 'cart uses converted price');
    WC()->cart->empty_cart();

    for ($i = 0; $i < 52; $i++) {
        tkcm_test_fields(tkcm_test_product(), '1');
    }
    $batch = tkcm_recalculate_batch(1);
    tkcm_test(!$batch['done'] && $batch['updated'] + $batch['skipped'] + $batch['errors'] <= 50, 'bounded first batch');
    $result = tkcm_recalculate_all_products();
    tkcm_test($result['errors'] === 0 && $result['updated'] >= 55, 'all pages including variations');

    $http = tkcm_test_request($subscriber, 'tkcm_recalculate');
    tkcm_test(wp_remote_retrieve_response_code($http) === 403, 'AJAX capability enforced');
    $http = tkcm_test_request($admin, 'tkcm_recalculate', 'wrong-action');
    tkcm_test(wp_remote_retrieve_response_code($http) === 403, 'AJAX nonce enforced');
    $http = tkcm_test_request($admin, 'tkcm_recalculate');
    $json = json_decode(wp_remote_retrieve_body($http), true);
    tkcm_test(wp_remote_retrieve_response_code($http) === 200 && !empty($json['success']) && !$json['data']['done'], 'authorized AJAX processes a batch');

    $table = ['tkcm_action' => 'save', 'element-max-id' => '1', 'cur-name-id-0' => 'USD', 'cur-val-id-0' => '41,5', 'cur-desc-id-0' => 'US dollar'];
    $http = tkcm_test_request($admin, 'settings', 'tkcm_save_settings', $table);
    tkcm_test(wp_remote_retrieve_response_code($http) === 200, 'settings HTTP page loads');
    wp_cache_flush();
    tkcm_test(strpos(wp_remote_retrieve_body($http), 'Manage Currencies') !== false, 'settings form rendered: ' . wp_strip_all_tags(substr(wp_remote_retrieve_body($http), 0, 200)));
    tkcm_test(get_option(TKCM_OPT_CURRENCIES)[0]['val'] === '41.5', 'settings save persists normalized rate; notices: ' . (preg_match('/class="(?:error|updated)"[^>]*>(.*?)<\/div>/s', wp_remote_retrieve_body($http), $notice) ? wp_strip_all_tags($notice[1]) : 'none'));
    $table['element-max-id'] = '2';
    $table += ['cur-name-id-1' => 'USD', 'cur-val-id-1' => '42', 'cur-desc-id-1' => 'Duplicate'];
    tkcm_test_request($admin, 'settings', 'tkcm_save_settings', $table);
    wp_cache_flush();
    tkcm_test(count(get_option(TKCM_OPT_CURRENCIES)) === 1 && get_option(TKCM_OPT_CURRENCIES)[0]['val'] === '41.5', 'invalid settings leave full previous table intact');
    update_option(TKCM_OPT_CURRENCIES, [['name' => 'USD', 'val' => '40', 'desc' => 'US dollar']]);

    define('WP_UNINSTALL_PLUGIN', 'tk-currency-manager/tk-currency-manager.php');
    require dirname(__DIR__) . '/uninstall.php';
    tkcm_test(get_option(TKCM_OPT_COUNT) == 1 && get_post_meta($plain, 'tk_curr_price', true) === '5', 'uninstall preserves shared data');
    echo sprintf("PASS: %d checks; WordPress %s; WooCommerce %s; PHP %s; first plugin: %s\n", $checks, get_bloginfo('version'), WC_VERSION, PHP_VERSION, get_option('active_plugins')[0]);
} finally {
    $_POST = [];
    wp_set_current_user(1);
    foreach (array_reverse($ids) as $id) {
        wp_delete_post($id, true);
    }
    if (isset($subscriber)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($subscriber);
    }
}
