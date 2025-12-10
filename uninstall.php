<?php
/**
 * WC Manager Uninstall
 * 
 * Cleans up plugin options when the plugin is deleted from WordPress.
 * 
 * @package WC_Manager
 */

// If uninstall not called from WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/**
 * Delete all plugin options from the database
 */
function wc_manager_uninstall_cleanup() {
    // Main plugin options
    delete_option('wcccf_custom_fields');
    delete_option('wcccf_disabled_fields');
    delete_option('wcccf_field_order');
    delete_option('wcccf_field_order_billing');
    delete_option('wcccf_field_order_shipping');
    delete_option('wcccf_field_order_account');
    delete_option('wcccf_field_order_order');
    delete_option('wcccf_field_labels');
    delete_option('wcccf_quantity_selector_enabled');
    
    // Auto discount options
    delete_option('wcccf_discount_rules');
    delete_option('wcccf_auto_discount_enabled');
    
    // Branch selector options
    delete_option('wc_branch_selector_enabled');
    delete_option('wc_branch_selector_pages');
    delete_option('wc_branch_selector_branches');
    
    // WC Slides options
    delete_option('wc_slides_presets');
    
    // Clear any transients
    delete_transient('wc_manager_cache');
}

// Run cleanup
wc_manager_uninstall_cleanup();
