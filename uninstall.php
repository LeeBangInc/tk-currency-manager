<?php
// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Options used by the plugin
delete_option('teplo_klimat_currency_params');
delete_option('teplo_klimat_num_elements');

