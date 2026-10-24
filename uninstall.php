<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// These keys are shared with legacy theme code. Preserve them, product source
// metadata and the last calculated WooCommerce prices on uninstall. Removal is
// an explicit store migration, not a side effect of deleting this plugin.
