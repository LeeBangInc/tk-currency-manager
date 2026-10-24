<?php
define('ABSPATH', __DIR__ . '/');
define('TKCM_OPT_CURRENCIES', 'currencies');
class WP_Error {
    public function __construct($code, $message) {}
}
function add_action($name, $callback, $priority = 10, $args = 1) {}
function __($message, $domain) { return $message; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal($value, $places) { return number_format($value, $places, '.', ''); }
function get_option($key, $default = []) {
    global $currencies;
    return $currencies;
}
require dirname(__DIR__) . '/includes/pricing.php';
$checks = 0;
function expect($ok) {
    global $checks;
    $checks++;
    if (!$ok) { throw new RuntimeException('Failed assertion ' . $checks); }
}
foreach (['-10', '1.2.3', '1e2', 'NaN', '1 000', [], true] as $value) {
    expect(is_wp_error(tkcm_sanitize_decimal($value)));
}
foreach (['0', '12.50', '12,50', '.5', ' 10 '] as $value) {
    expect(!is_wp_error(tkcm_sanitize_decimal($value)));
}
$currencies = [['name' => 'USD', 'val' => '40', 'desc' => '']];
$fields = ['tk_currency' => 'USD', 'tk_curr_price' => '10', 'tk_curr_sale' => '8'];
expect(tkcm_calculate_prices($fields) === ['regular' => '400.00', 'sale' => '320.00']);
$fields['tk_currency'] = 'EUR';
expect(is_wp_error(tkcm_calculate_prices($fields)));
$fields['tk_currency'] = '';
expect(tkcm_calculate_prices($fields)['regular'] === '400.00');
$fields['tk_curr_price'] = '';
expect(tkcm_calculate_prices($fields) === null);
$fields['tk_curr_price'] = '0';
$fields['tk_curr_sale'] = '';
expect(tkcm_calculate_prices($fields)['regular'] === '0.00');
$currencies[0]['val'] = '0.00000001';
expect(is_wp_error(tkcm_calculate_prices($fields)));
$currencies[0]['val'] = '40';
$currencies[] = $currencies[0];
expect(is_wp_error(tkcm_calculate_prices($fields)));
echo "PASS: $checks decimal and currency checks.\n";
