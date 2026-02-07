<?php
/*
Plugin Name: WC Manager
Plugin URI: https://yoursite.com/wc-manager
Description: Advanced custom checkout fields with field positioning, field management, and built-in field controls.
Version: 2.0.1
Author: Rasel Ahmed
Text Domain: wc-manager
Domain Path: /languages
Requires at least: 5.8
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 9.0
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin version constant
define('WC_MANAGER_VERSION', '2.0.1');
define('WC_MANAGER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if WooCommerce is active
 */
function wc_manager_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_manager_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display admin notice if WooCommerce is not active
 */
function wc_manager_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong><?php _e('WC Manager', 'wc-manager'); ?></strong> <?php _e('requires WooCommerce to be installed and active. Please install and activate WooCommerce.', 'wc-manager'); ?></p>
    </div>
    <?php
}

/**
 * Declare HPOS (High-Performance Order Storage) Compatibility
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
});

// Only load the plugin if WooCommerce is active
add_action('plugins_loaded', function() {
    if (!wc_manager_check_woocommerce()) {
        return;
    }
    new WCCCF_Enhanced();
}, 11);

class WCCCF_Enhanced {
    
    /** @var WCCCF_Auto_Discount Auto discount handler */
    public $auto_discount;
    
    /** @var WC_Branch_Selector_Manager Branch selector handler */
    public $branch_selector;
    
    /** @var WC_Slides_Manager Slides manager handler */
    public $slides_manager;
    
    public function __construct() {
        // Get active modules
        $active_modules = get_option('wc_manager_active_modules', [
            'checkout_fields' => 1,
            'discount_manager' => 1,
            'wc_popups' => 0,
            'wc_slides' => 0,
        ]);

        // Load Discount Manager if active
        if (!empty($active_modules['discount_manager'])) {
            require_once plugin_dir_path(__FILE__) . 'includes/auto-discount.php';
            $this->auto_discount = new WCCCF_Auto_Discount();
        }

        // Always load quantity selector (not a separate module)
        require_once plugin_dir_path(__FILE__) . 'includes/quantity-selector.php';
        new WCCCF_Quantity_Selector();

        // Load WC Popups (Branch Selection) if active
        if (!empty($active_modules['wc_popups'])) {
            require_once plugin_dir_path(__FILE__) . 'includes/wc-branch-selection.php';
            $this->branch_selector = new WC_Branch_Selector_Manager();
        }

        // Load WC Slides if active
        if (!empty($active_modules['wc_slides'])) {
            require_once plugin_dir_path(__FILE__) . 'includes/wc-slides.php';
            $this->slides_manager = new WC_Slides_Manager();
        }

        // Core menu and settings - always load
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_export_import']);
        
        // Checkout Fields functionality - only if active
        if (!empty($active_modules['checkout_fields'])) {
            // AJAX handlers
            add_action('wp_ajax_wcccf_delete_field', [$this, 'ajax_delete_field']);
            add_action('wp_ajax_wcccf_get_field', [$this, 'ajax_get_field']);
            add_action('wp_ajax_wcccf_save_field', [$this, 'ajax_save_field']);

            add_filter('woocommerce_checkout_fields', [$this, 'add_custom_checkout_fields']);
            add_filter('woocommerce_checkout_fields', [$this, 'modify_default_fields'], 20);
            add_action('woocommerce_checkout_process', [$this, 'validate_custom_checkout_fields']);
            add_action('woocommerce_checkout_update_order_meta', [$this, 'save_custom_checkout_fields']);
            
            // Display hooks
            add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'show_field_in_admin']);
            add_action('woocommerce_order_details_after_order_table', [$this, 'show_field_on_thank_you_page']);
            add_action('woocommerce_view_order', [$this, 'show_field_on_customer_order_page'], 20);
            add_action('woocommerce_email_order_meta', [$this, 'add_fields_to_email'], 20, 3);
            add_filter('woocommerce_form_field_multiselect', [$this, 'render_multiselect_field'], 10, 4);

            // Enqueue frontend scripts for checkout
            add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        }

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
    }

    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-custom-checkout-field') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function frontend_scripts() {
        if (is_checkout()) {
            wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js', ['jquery'], '4.1.0', true);
            wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css', [], '4.1.0');
            
            wp_add_inline_script('select2', "
                jQuery(document).ready(function($) {
                    if (typeof($.fn.select2) !== 'undefined') {
                        $('.wcccf-multiselect-input').select2({
                            width: '100%'
                        });
                    }
                });
            ");
            
            $custom_css = ".select2-container .select2-selection--multiple .select2-selection__rendered { margin: 0; }";
            wp_add_inline_style('select2', $custom_css);
        }
    }

    public function admin_scripts($hook) {
        // Target our main settings page, the auto-discount page, and the branch selector page.
        $is_wcccf_page = ($hook === 'wc-manager_page_wcccf-auto-discount' || $hook === 'toplevel_page_wc-custom-checkout-field' || $hook === 'wc-manager_page_wc-popup' || $hook === 'wc-manager_page_wc-slides');

        if (!$is_wcccf_page) return;
        
        // Enqueue scripts for the main settings page (modal)
        if ($hook === 'toplevel_page_wc-custom-checkout-field') {
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_style('wp-jquery-ui-dialog');
            // We will load sortable manually to ensure it works
        }

        // Enqueue Select2 for relevant pages
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css', [], '4.1.0');

        // WooCommerce product search script
        wp_enqueue_script('wc-product-search');
    }

    public function add_settings_page() {
        // Get active modules
        $active_modules = get_option('wc_manager_active_modules', [
            'checkout_fields' => 1,
            'discount_manager' => 1,
            'wc_popups' => 0,
            'wc_slides' => 0,
        ]);

        // Determine the callback for the main menu item
        // If checkout_fields is active, use it; otherwise use module settings
        $main_callback = !empty($active_modules['checkout_fields']) 
            ? [$this, 'settings_page_html'] 
            : [$this, 'module_settings_page_html'];

        add_menu_page(
            'WC Manager Settings',
            'WC Manager',
            'manage_options',
            'wc-custom-checkout-field',
            $main_callback,
            'dashicons-cart',
            55
        );

        // Checkout Fields - Show if active
        if (!empty($active_modules['checkout_fields'])) {
            add_submenu_page(
                'wc-custom-checkout-field',
                'Checkout Fields',
                'Checkout Fields',
                'manage_options',
                'wc-custom-checkout-field',
                [$this, 'settings_page_html']
            );
        }

        // Discount Manager - Show if active
        if (!empty($active_modules['discount_manager'])) {
            add_submenu_page(
                'wc-custom-checkout-field',
                'Discount Manager',
                'Discount Manager',
                'manage_options',
                'wcccf-auto-discount',
                [$this, 'auto_discount_page_html']
            );
        }

        // WC Popup - Show if active
        if (!empty($active_modules['wc_popups']) && isset($this->branch_selector)) {
            add_submenu_page(
                'wc-custom-checkout-field',
                'WC Popup',
                'WC Popup',
                'manage_options',
                'wc-popup',
                [$this->branch_selector, 'branch_selector_admin_page_html']
            );
        }

        // WC Slides - Show if active
        if (!empty($active_modules['wc_slides']) && isset($this->slides_manager)) {
            add_submenu_page(
                'wc-custom-checkout-field',
                'WC Slides',
                'WC Slides',
                'manage_options',
                'wc-slides',
                [$this->slides_manager, 'admin_page_html']
            );
        }

        // Module Settings - Always show
        add_submenu_page(
            'wc-custom-checkout-field',
            'Modules',
            'Modules',
            'manage_options',
            'wc-manager-modules',
            [$this, 'module_settings_page_html']
        );

        // Import/Export Settings - Always show
        add_submenu_page(
            'wc-custom-checkout-field',
            'Settings',
            'Settings',
            'manage_options',
            'wcccf-settings',
            [$this, 'import_export_page_html']
        );
    }

    public function register_settings() {
        register_setting('wcccf_settings_group', 'wcccf_custom_fields');
        
        // Register module settings
        register_setting('wc_manager_modules_group', 'wc_manager_active_modules', [
            'sanitize_callback' => [$this, 'sanitize_module_settings']
        ]);
        
        // Register disabled fields with its own callback
        register_setting('wcccf_default_fields_group', 'wcccf_disabled_fields', [$this, 'sanitize_disabled_fields']);
        
        // Register field labels with array map sanitization
        register_setting('wcccf_default_fields_group', 'wcccf_field_labels', [$this, 'sanitize_field_labels']);

        // Register specific field requirements and types
        register_setting('wcccf_default_fields_group', 'wcccf_required_fields', [$this, 'sanitize_disabled_fields']); 
        register_setting('wcccf_default_fields_group', 'wcccf_field_types', [$this, 'sanitize_field_labels']); 
        register_setting('wcccf_default_fields_group', 'wcccf_field_placeholders', [$this, 'sanitize_field_labels']); 
        register_setting('wcccf_default_fields_group', 'wcccf_field_widths', [$this, 'sanitize_field_labels']);

        // Register section order options individually
        $sections = ['billing', 'shipping', 'account', 'order'];
        foreach ($sections as $section) {
            register_setting('wcccf_default_fields_group', 'wcccf_field_order_' . $section, 'sanitize_text_field');
        }
        
        // Keep legacy for backward compat if needed, or remove. Keeping safe.
        register_setting('wcccf_default_fields_group', 'wcccf_field_order', 'sanitize_text_field');

        register_setting('wcccf_settings_group', 'wcccf_quantity_selector_enabled', [
            'sanitize_callback' => [$this, 'sanitize_quantity_selector_setting']
        ]);
    }

    public function sanitize_disabled_fields($input) {
        // Removed add_settings_error to verify if it conflicts with options.php redirect
        return is_array($input) ? array_map('intval', $input) : [];
    }

    public function sanitize_field_labels($input) {
        if (is_array($input)) {
            return array_map('sanitize_text_field', $input);
        }
        return [];
    }

    // Deprecated/Renamed
    public function sanitize_default_fields($input) {
        return $this->sanitize_disabled_fields($input);
    }

    public function sanitize_quantity_selector_setting($input) {
        add_settings_error(
            'wcccf_settings_group',
            'settings_updated',
            __('Settings saved.', 'wc-manager'),
            'updated'
        );
        return $input;
    }

    public function sanitize_module_settings($input) {
        if (!is_array($input)) {
            $input = [];
        }
        
        // Ensure all modules have a value (0 or 1)
        $sanitized = [
            'checkout_fields' => !empty($input['checkout_fields']) ? 1 : 0,
            'discount_manager' => !empty($input['discount_manager']) ? 1 : 0,
            'wc_popups' => !empty($input['wc_popups']) ? 1 : 0,
            'wc_slides' => !empty($input['wc_slides']) ? 1 : 0,
        ];
        
        add_settings_error(
            'wc_manager_modules_group',
            'settings_updated',
            __('Module settings saved successfully.', 'wc-manager'),
            'updated'
        );
        
        return $sanitized;
    }

    public function ajax_delete_field() {
        check_ajax_referer('wcccf_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $field_id = intval($_POST['field_id']);
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        if (isset($custom_fields[$field_id])) {
            unset($custom_fields[$field_id]);
            // Reindex array
            $custom_fields = array_values($custom_fields);
            update_option('wcccf_custom_fields', $custom_fields);
        }
        
        wp_send_json_success();
    }

    public function ajax_get_field() {
        check_ajax_referer('wcccf_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $field_id = intval($_POST['field_id']);
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        if (isset($custom_fields[$field_id])) {
            wp_send_json_success($custom_fields[$field_id]);
        } else {
            wp_send_json_error('Field not found');
        }
    }

    public function ajax_save_field() {
        // Check nonce
        if (!check_ajax_referer('wcccf_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed. Please refresh the page and try again.');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
        }

        // Validate required fields
        if (empty($_POST['label'])) {
            wp_send_json_error('Field label is required');
        }

        try {
            // Handle required field properly
            $required = 0;
            if (isset($_POST['required'])) {
                $required = intval($_POST['required']);
            }

            $field_data = [
                'label' => sanitize_text_field($_POST['label']),
                'type' => sanitize_text_field($_POST['type'] ?? 'text'),
                'options' => sanitize_textarea_field($_POST['options'] ?? ''),
                'products' => array_map('intval', (array)($_POST['products'] ?? [])),
                'included_categories' => array_map('intval', (array)($_POST['included_categories'] ?? [])),
                'excluded_categories' => array_map('intval', (array)($_POST['excluded_categories'] ?? [])),
                'location' => sanitize_text_field($_POST['location'] ?? 'billing'),
                'required' => $required
            ];

            $custom_fields = get_option('wcccf_custom_fields', []);
            
            // Ensure it's an array
            if (!is_array($custom_fields)) {
                $custom_fields = [];
            }
            
            if (isset($_POST['field_id']) && $_POST['field_id'] !== '') {
                // Edit existing field
                $field_id = intval($_POST['field_id']);
                if (isset($custom_fields[$field_id])) {
                    $custom_fields[$field_id] = $field_data;
                } else {
                    wp_send_json_error('Field not found');
                }
            } else {
                // Add new field
                $custom_fields[] = $field_data;
            }
            
            update_option('wcccf_custom_fields', $custom_fields);
            
            wp_send_json_success('Field saved successfully');
            
        } catch (Exception $e) {
            wp_send_json_error('An error occurred while saving the field.');
        }
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
        <?php
             // Display settings errors - remove group filter to show all messages
             settings_errors();
        ?>
        <?php
        $custom_fields = get_option('wcccf_custom_fields', []);
        $disabled_fields = get_option('wcccf_disabled_fields', []);
        $required_fields = get_option('wcccf_required_fields', []);
        $field_types = get_option('wcccf_field_types', []);
        $field_placeholders = get_option('wcccf_field_placeholders', []);
        $field_widths = get_option('wcccf_field_widths', []);
        $field_labels = get_option('wcccf_field_labels', []);
        $all_products = $this->get_all_products_for_select();
        $all_categories = $this->get_all_product_categories_for_select();
        $nonce = wp_create_nonce('wcccf_nonce');
        ?>
            <h1>Enhanced Checkout Field Settings</h1>
            
            <!-- Custom Fields Management -->
            <div class="card">
                <h2>Custom Fields Management</h2>
                <button type="button" class="button button-primary" id="add-new-field">Add New Field</button>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th>Field Label</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Required</th>
                            <th>Products</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fields-list">
                        <?php if (!empty($custom_fields)) : ?>
                            <?php foreach ($custom_fields as $index => $field) : ?>
                                <tr data-field-id="<?php echo $index; ?>">
                                    <td><strong><?php echo esc_html($field['label']); ?></strong></td>
                                    <td><?php echo esc_html(ucfirst($field['type'])); ?></td>
                                    <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $field['location'] ?? 'billing'))); ?></td>
                                    <td><?php echo isset($field['required']) && $field['required'] ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo count($field['products'] ?? []) . ' product(s)'; ?></td>
                                    <td>
                                        <button type="button" class="button button-small edit-field" data-field-id="<?php echo $index; ?>">Edit</button>
                                        <button type="button" class="button button-small delete-field" data-field-id="<?php echo $index; ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr id="no-fields-row">
                                <td colspan="6"><em>No custom fields created yet.</em></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <style>
                .wcccf-sortable-table input.regular-text {
                    width: 100%;
                    max-width: 150px; /* Constrain inputs */
                }
                .wcccf-sortable-table select {
                    max-width: 120px;
                }
                .wcccf-sortable-table th {
                    white-space: nowrap;
                    padding: 10px 5px;
                }
                .wcccf-sortable-table td {
                    padding: 10px 5px;
                    vertical-align: middle;
                }
                /* Specific column adjustments */
                .wcccf-sortable-table th.col-label, 
                .wcccf-sortable-table th.col-placeholder {
                    width: 160px;
                }
                .wcccf-badge-custom {
                    background: #2271b1;
                    color: white;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-size: 10px;
                    text-transform: uppercase;
                    margin-left: 5px;
                }
                
                /* Toast Notification */
                #wcccf-toast {
                    visibility: hidden;
                    min-width: 250px;
                    margin-left: -125px;
                    background-color: #333;
                    color: #fff;
                    text-align: center;
                    border-radius: 4px;
                    padding: 16px;
                    position: fixed;
                    z-index: 10000;
                    left: 50%;
                    bottom: 30px;
                    font-size: 15px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                }

                #wcccf-toast.show {
                    visibility: visible;
                    -webkit-animation: fadein 0.5s, fadeout 0.5s 2.5s;
                    animation: fadein 0.5s, fadeout 0.5s 2.5s;
                }
                
                #wcccf-toast.success { background-color: #46b450; }
                #wcccf-toast.error { background-color: #dc3232; }

                @-webkit-keyframes fadein {
                    from {bottom: 0; opacity: 0;} 
                    to {bottom: 30px; opacity: 1;}
                }

                @keyframes fadein {
                    from {bottom: 0; opacity: 0;}
                    to {bottom: 30px; opacity: 1;}
                }

                @-webkit-keyframes fadeout {
                    from {bottom: 30px; opacity: 1;} 
                    to {bottom: 0; opacity: 0;}
                }

                @keyframes fadeout {
                    from {bottom: 30px; opacity: 1;}
                    to {bottom: 0; opacity: 0;}
                }
            </style>
            
            <div id="wcccf-toast"></div>

            <!-- Default Fields Control Section -->
            <!-- Default Fields Control Section -->
            <div class="card" style="margin-top: 20px;">
                <h2>Default WooCommerce Fields Control</h2>
                
                <h2 class="nav-tab-wrapper wcccf-tabs">
                    <a href="#tab-billing" class="nav-tab nav-tab-active">Billing</a>
                    <a href="#tab-shipping" class="nav-tab">Shipping</a>
                    <a href="#tab-account" class="nav-tab">Account</a>
                    <a href="#tab-order" class="nav-tab">Order & Additional</a>
                </h2>

                <form method="post" action="options.php">
                    <?php settings_fields('wcccf_default_fields_group'); ?>
                    
                    <?php
                    $all_default_fields = $this->get_all_default_checkout_fields();
                    $sections = [
                        'billing' => 'Billing Details',
                        'shipping' => 'Shipping Details',
                        'account' => 'Account Details',
                        'order' => 'Order Notes'
                    ];

                    // Merge Custom Fields into Sections for Sorting
                    if (!empty($custom_fields)) {
                        foreach ($custom_fields as $index => $field) {
                            if (empty($field['label'])) continue; // Skip invalid
                            
                            $field_key = 'wcccf_field_' . $index;
                            $location = $field['location'] ?? 'billing';
                            
                            // Map location to section
                            $section = 'billing';
                            if ($location === 'shipping') $section = 'shipping';
                            if ($location === 'order') $section = 'order';
                            
                            // Add to the list if not already there (though keys shouldn't conflict with WC defaults)
                            if (!isset($all_default_fields[$section][$field_key])) {
                                $all_default_fields[$section][$field_key] = [
                                    'label' => $field['label'],
                                    'type' => $field['type'] ?? 'text',
                                    'required' => isset($field['required']) && $field['required'],
                                    'placeholder' => '', // placeholders handled via overrides or custom definition
                                    'is_custom' => true // flag to identify
                                ];
                            }
                        }
                    }
                    ?>

                    <?php foreach ($sections as $section_key => $section_label) : ?>
                        <div id="tab-<?php echo $section_key; ?>" class="wcccf-tab-content" style="<?php echo $section_key === 'billing' ? '' : 'display:none;'; ?>">
                            <table class="form-table wcccf-sortable-table" data-section="<?php echo $section_key; ?>">
                                <thead>
                                    <tr>
                                        <th style="width: 20px;"></th>
                                        <th class="col-label">Field Label</th>
                                        <th class="col-placeholder">Placeholder</th>
                                        <th>Width</th>
                                        <th>Type</th>
                                        <th>Original ID</th>
                                        <th>Required</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $section_fields = $all_default_fields[$section_key] ?? [];
                                $saved_order_raw = get_option('wcccf_field_order_' . $section_key, '');
                                
                                // Normalize to array
                                if (is_string($saved_order_raw)) {
                                    $saved_order = array_filter(array_map('trim', explode(',', $saved_order_raw)));
                                } else {
                                    $saved_order = (array) $saved_order_raw;
                                }
                                
                                if (!empty($saved_order)) {
                                    // Merge saved order with new fields
                                    $ordered_keys = array_unique(array_merge($saved_order, array_keys($section_fields)));
                                } else {
                                    $ordered_keys = array_keys($section_fields);
                                }

                                foreach ($ordered_keys as $field_key) {
                                    if (!isset($section_fields[$field_key])) continue;
                                    
                                    // Handle array or string structure (backward compatibility)
                                    $field_data = $section_fields[$field_key];
                                    $default_label = is_array($field_data) ? ($field_data['label'] ?? $field_key) : $field_data;
                                    $field_type = is_array($field_data) ? ($field_data['type'] ?? 'text') : 'text';
                                    $is_default_required = is_array($field_data) ? ($field_data['required'] ?? false) : false;
                                    $is_custom = is_array($field_data) && isset($field_data['is_custom']) && $field_data['is_custom'];
                                    
                                    // Determine if currently required (check saved option, fallback to default)
                                    // Note: Logic is tricky. If saved option exists, use it. If not, use default?
                                    // Usually users want to toggle. Let's populate checking based on default if not saved yet.
                                    // BUT, for new installs, we might not have `wcccf_required_fields` saved.
                                    if (isset($required_fields[$field_key])) {
                                        $is_required = $required_fields[$field_key];
                                    } else {
                                        $is_required = $is_default_required;
                                    }
                                    // Determine type (saved > default)
                                    $current_type = isset($field_types[$field_key]) ? $field_types[$field_key] : $field_type;
                                    
                                    // Available types
                                    $available_types = [
                                        'default' => 'Default (Let WooCommerce Decide)',
                                        'text' => 'Text', 
                                        'password' => 'Password', 
                                        'email' => 'Email', 
                                        'tel' => 'Phone', 
                                        'textarea' => 'Textarea', 
                                        'select' => 'Select', 
                                        'radio' => 'Radio', 
                                        'checkbox' => 'Checkbox',
                                        'state' => 'State / County',
                                        'country' => 'Country'
                                    ];
                                    // Determine placeholder and width
                                    $current_placeholder = isset($field_placeholders[$field_key]) ? $field_placeholders[$field_key] : (is_array($field_data) ? ($field_data['placeholder'] ?? '') : '');
                                    $current_width = isset($field_widths[$field_key]) ? $field_widths[$field_key] : 'wide';
                                    
                                    // Width options
                                    $width_options = [
                                        'wide'  => 'Full Width',
                                        'first' => 'Half Width (Left)',
                                        'last'  => 'Half Width (Right)'
                                    ];
                                    ?>
                                    <tr data-field-key="<?php echo esc_attr($field_key); ?>" style="cursor: move;">
                                        <td class="wcccf-draggable-handle"><span class="dashicons dashicons-menu"></span></td>
                                        <td>
                                            <?php if ($is_custom) : ?>
                                                <input type="text" value="<?php echo esc_attr($default_label); ?>" class="regular-text" disabled title="Edit in Custom Fields section">
                                                <input type="hidden" name="wcccf_field_labels[<?php echo $field_key; ?>]" value="<?php echo esc_attr($default_label); ?>">
                                            <?php else : ?>
                                                <input type="text" name="wcccf_field_labels[<?php echo $field_key; ?>]" value="<?php echo esc_attr($field_labels[$field_key] ?? $default_label); ?>" placeholder="<?php echo esc_attr($default_label); ?>" class="regular-text">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" name="wcccf_field_placeholders[<?php echo $field_key; ?>]" value="<?php echo esc_attr($current_placeholder); ?>" placeholder="Placeholder" class="regular-text">
                                        </td>
                                        <td>
                                            <select name="wcccf_field_widths[<?php echo $field_key; ?>]">
                                                <?php foreach ($width_options as $width_key => $width_label) : ?>
                                                    <option value="<?php echo esc_attr($width_key); ?>" <?php selected($current_width, $width_key); ?>><?php echo esc_html($width_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php if ($is_custom) : ?>
                                                 <span class="description"><?php echo esc_html(ucfirst($field_type)); ?></span>
                                            <?php else : ?>
                                                <select name="wcccf_field_types[<?php echo $field_key; ?>]">
                                                    <?php foreach ($available_types as $type_slug => $type_name) : ?>
                                                        <option value="<?php echo esc_attr($type_slug); ?>" <?php selected($current_type, $type_slug); ?>><?php echo esc_html($type_name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html($field_key); ?></code>
                                            <?php if ($is_custom) : ?>
                                                <span class="wcccf-badge-custom">Custom</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                              <label>
                                                <?php if ($is_custom) : ?>
                                                     <input type="checkbox" disabled <?php checked($is_required); ?>>
                                                <?php else : ?>
                                                    <input type="hidden" name="wcccf_required_fields[<?php echo $field_key; ?>]" value="0">
                                                    <input type="checkbox" name="wcccf_required_fields[<?php echo $field_key; ?>]" value="1" <?php checked($is_required); ?>>
                                                <?php endif; ?>
                                            </label>
                                        </td>
                                        <td>
                                            <?php if (!$is_custom) : ?>
                                            <label>
                                                <input type="checkbox" name="wcccf_disabled_fields[<?php echo $field_key; ?>]" value="1" <?php checked(isset($disabled_fields[$field_key]) && $disabled_fields[$field_key]); ?>>
                                                Hide
                                            </label>
                                            <?php else: ?>
                                                <span class="description">Manage above</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php 
                                } 
                                ?>
                                </tbody>
                            </table>
                            <input type="hidden" name="wcccf_field_order_<?php echo $section_key; ?>" class="wcccf-field-order-input" value="<?php echo esc_attr(implode(',', $ordered_keys)); ?>">
                        </div>
                    <?php endforeach; ?>

                    <?php submit_button('Save Default Fields Settings'); ?>
                </form>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Tab Switching
                $('.wcccf-tabs .nav-tab').on('click', function(e) {
                    e.preventDefault();
                    $('.wcccf-tabs .nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.wcccf-tab-content').hide();
                    $($(this).attr('href')).show();
                });

                // Sortable
                $('.wcccf-sortable-table tbody').sortable({
                    handle: '.wcccf-draggable-handle',
                    stop: function(event, ui) {
                        var section = $(this).closest('table').data('section');
                        var order = [];
                        $(this).find('tr').each(function() {
                            order.push($(this).data('field-key'));
                        });
                        $('input[name="wcccf_field_order_' + section + '"]').val(order.join(','));
                    }
                });
            });
            </script>
        </div>

        <!-- Field Edit/Add Modal -->
        <div id="field-modal" title="Field Settings" style="display: none;">
            <form id="field-form">
                <input type="hidden" id="field-id" name="field_id">
                <div class="wcccf-modal-content">
                    <div class="wcccf-form-grid">
                        <div class="wcccf-form-row">
                            <label for="field-label">Field Label *</label>
                            <input type="text" id="field-label" name="label" class="regular-text" required>
                        </div>
                        
                        <div class="wcccf-form-row">
                            <label for="field-type">Field Type</label>
                            <select id="field-type" name="type">
                                <option value="text">Text</option>
                                <option value="textarea">Textarea</option>
                                <option value="select">Dropdown</option>
                                <option value="multiselect">Multiselect</option>
                                <option value="checkbox">Checkbox</option>
                                <option value="email">Email</option>
                                <option value="tel">Phone</option>
                                <option value="number">Number</option>
                            </select>
                        </div>

                        <div class="wcccf-form-row">
                            <label for="field-location">Field Location</label>
                            <select id="field-location" name="location">
                                <option value="billing">Billing Section</option>
                                <option value="shipping">Shipping Section</option>
                                <option value="order">Order Section</option>
                            </select>
                        </div>

                        <div class="wcccf-form-row">
                            <label for="field-required">Required Field</label>
                            <label class="wcccf-checkbox-label">
                                <input type="checkbox" id="field-required" name="required" value="1">
                                Make this field required
                            </label>
                        </div>
                    </div>

                    <div id="options-row" class="wcccf-form-row" style="display: none;">
                        <label for="field-options">Field Options</label>
                        <textarea id="field-options" name="options" rows="5" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                        <p class="description">One option per line (used for Dropdown and Multiselect fields)</p>
                    </div>

                    <div class="wcccf-form-row">
                        <label for="field-products">Applicable Products</label>
                        <select id="field-products" name="products[]" multiple="multiple" class="wc-product-search" data-placeholder="Select products (leave empty for all orders)">
                            <?php foreach ($all_products as $product_id => $product_name) : ?>
                                <option value="<?php echo esc_attr($product_id); ?>"><?php echo esc_html($product_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Leave empty to show field for all orders, or select specific products</p>
                    </div>

                   <div class="wcccf-form-row">
                       <label for="field-included-categories">Included Categories</label>
                       <select id="field-included-categories" name="included_categories[]" multiple="multiple" class="wc-category-search" data-placeholder="Select categories (leave empty for all)">
                           <?php foreach ($all_categories as $category_id => $category_name) : ?>
                               <option value="<?php echo esc_attr($category_id); ?>"><?php echo esc_html($category_name); ?></option>
                           <?php endforeach; ?>
                       </select>
                       <p class="description">Show field only if products from these categories are in cart.</p>
                   </div>

                   <div class="wcccf-form-row">
                       <label for="field-excluded-categories">Excluded Categories</label>
                       <select id="field-excluded-categories" name="excluded_categories[]" multiple="multiple" class="wc-category-search" data-placeholder="Select categories (leave empty for none)">
                           <?php foreach ($all_categories as $category_id => $category_name) : ?>
                               <option value="<?php echo esc_attr($category_id); ?>"><?php echo esc_html($category_name); ?></option>
                           <?php endforeach; ?>
                       </select>
                       <p class="description">Hide field if products from these categories are in cart (takes priority over included categories).</p>
                   </div>
                </div>
            </form>
        </div>

        <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js" integrity="sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU=" crossorigin="anonymous"></script>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var wcccfNonce = '<?php echo $nonce; ?>';

                // Make the default fields table sortable
                if (typeof $().sortable === 'function') {
                    $('#default-fields-sortable').sortable({
                        handle: '.wcccf-draggable-handle',
                        update: function(event, ui) {
                            var fieldOrder = $(this).sortable('toArray', { attribute: 'data-field-key' });
                            $('#wcccf_field_order').val(fieldOrder.join(','));
                        }
                    });
                } else {
                    console.error("jQuery UI Sortable is not loaded.");
                }

                // Toast Notification Function
                function showToast(message, type = 'success') {
                    var x = document.getElementById("wcccf-toast");
                    x.className = "show " + type;
                    x.textContent = message;
                    setTimeout(function(){ x.className = x.className.replace("show", ""); }, 3000);
                }
                
                // Check LocalStorage for pending messages (after reload)
                var pendingMessage = localStorage.getItem('wcccf_toast_message');
                var pendingType = localStorage.getItem('wcccf_toast_type');
                
                if (pendingMessage) {
                    showToast(pendingMessage, pendingType || 'success');
                    localStorage.removeItem('wcccf_toast_message');
                    localStorage.removeItem('wcccf_toast_type');
                }

                // Initialize modal with full width
                $('#field-modal').dialog({
                    autoOpen: false,
                    modal: true,
                    width: '90%',
                    maxWidth: 1200,
                    height: 'auto',
                    resizable: true,
                    position: { my: "center", at: "center", of: window },
                    buttons: {
                        'Save Field': {
                            text: 'Save Field',
                            class: 'button-primary',
                            click: function() {
                                saveField();
                            }
                        },
                        'Cancel': {
                            text: 'Cancel',
                            class: 'button',
                            click: function() {
                                $(this).dialog('close');
                            }
                        }
                    },
                    open: function() {
                        // Initialize select2 when modal opens
                        $('#field-products').select2({
                            dropdownParent: $('#field-modal'),
                            width: '100%'
                        });
                        $('#field-included-categories, #field-excluded-categories').select2({
                            dropdownParent: $('#field-modal'),
                            width: '100%'
                        });
                    }
                });

                // Show/hide options field based on type
                $('#field-type').change(function() {
                    var currentType = $(this).val();
                    if (currentType === 'select' || currentType === 'multiselect') {
                        $('#options-row').show();
                    } else {
                        $('#options-row').hide();
                    }
                });

                // Add new field
                $('#add-new-field').click(function() {
                    $('#field-form')[0].reset();
                    $('#field-id').val('');
                    $('#options-row').hide();
                    $('#field-modal').dialog('option', 'title', 'Add New Field').dialog('open');
                });

                // Edit field
                $(document).on('click', '.edit-field', function() {
                    var fieldId = $(this).data('field-id');
                    
                    $.post(ajaxurl, {
                        action: 'wcccf_get_field',
                        field_id: fieldId,
                        nonce: wcccfNonce
                    }, function(response) {
                        if (response.success) {
                            var field = response.data;
                            console.log('Field data received:', field);
                            
                            $('#field-id').val(fieldId);
                            $('#field-label').val(field.label);
                            $('#field-type').val(field.type).trigger('change');
                            $('#field-location').val(field.location || 'billing');
                            
                            // Handle required checkbox properly
                            var isRequired = field.required == 1 || field.required === true || field.required === 'true';
                            $('#field-required').prop('checked', isRequired);
                            console.log('Setting required to:', isRequired, 'from value:', field.required);
                            
                            $('#field-options').val(field.options || '');
                            
                            $('#field-modal').dialog('option', 'title', 'Edit Field').dialog('open');
                            
                            // Set products and categories after modal is open
                            setTimeout(function() {
                                $('#field-products').val(field.products || []).trigger('change');
                                $('#field-included-categories').val(field.included_categories || []).trigger('change');
                                $('#field-excluded-categories').val(field.excluded_categories || []).trigger('change');
                            }, 100);
                        } else {
                            alert('Error loading field data: ' + (response.data || 'Unknown error'));
                        }
                    }).fail(function(xhr, status, error) {
                        console.log('Edit field AJAX error:', xhr.responseText);
                        alert('Network error while loading field data.');
                    });
                });

                // Delete field
                $(document).on('click', '.delete-field', function() {
                    var fieldId = $(this).data('field-id');
                    var fieldLabel = $(this).closest('tr').find('td:first strong').text();
                    
                    if (confirm('Are you sure you want to delete the field "' + fieldLabel + '"? This action cannot be undone.')) {
                        $.post(ajaxurl, {
                            action: 'wcccf_delete_field',
                            field_id: fieldId,
                            nonce: wcccfNonce
                        }, function(response) {
                            if (response.success) {
                                localStorage.setItem('wcccf_toast_message', 'Field deleted successfully.');
                                localStorage.setItem('wcccf_toast_type', 'success');
                                location.reload();
                            } else {
                                alert('Error deleting field. Please try again.');
                            }
                        });
                    }
                });

                function saveField() {
                    // Debug: Log that function is called
                    console.log('saveField() called');
                    
                    // Validate required fields
                    var label = $('#field-label').val().trim();
                    if (!label) {
                        alert('Please enter a field label.');
                        $('#field-label').focus();
                        return;
                    }

                    // Get checkbox state properly
                    var isRequired = $('#field-required').is(':checked');
                    console.log('Required checkbox state:', isRequired);

                    // Collect form data
                    var formData = {
                        action: 'wcccf_save_field',
                        nonce: wcccfNonce,
                        field_id: $('#field-id').val(),
                        label: label,
                        type: $('#field-type').val(),
                        location: $('#field-location').val(),
                        required: isRequired ? 1 : 0,
                        options: $('#field-options').val(),
                        products: $('#field-products').val() || [],
                        included_categories: $('#field-included-categories').val() || [],
                        excluded_categories: $('#field-excluded-categories').val() || []
                    };
                    
                    // Debug: Log form data
                    console.log('Form data:', formData);
                    console.log('AJAX URL:', ajaxurl);
                    
                    $.post(ajaxurl, formData, function(response) {
                        console.log('AJAX Response:', response);
                        if (response.success) {
                            $('#field-modal').dialog('close');
                            localStorage.setItem('wcccf_toast_message', 'Field saved successfully.');
                            localStorage.setItem('wcccf_toast_type', 'success');
                            location.reload();
                        } else {
                            alert('Error saving field: ' + (response.data || 'Unknown error'));
                        }
                    }).fail(function(xhr, status, error) {
                        console.log('AJAX Error:', xhr, status, error);
                        console.log('Response Text:', xhr.responseText);
                        alert('Network error. Please check your connection and try again.\nError: ' + error);
                    });
                }
            });
        </script>

        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                width: 100%;
                max-width: 100%;
            }
            .card h2 {
                margin-top: 0;
            }
            
            /* Modal Styles */
            #default-fields-table .wcccf-draggable-handle {
                width: 20px;
                padding-right: 10px;
                color: #888;
            }
            #default-fields-table input[type="text"] {
                width: 100%;
            }
            .ui-dialog {
                max-width: 850px;
                font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
            }
            .ui-dialog-titlebar-close {
                display: none !important;
            }
            
            .wcccf-modal-content {
                padding: 0;
            }
            
            .wcccf-form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 20px;
            }
            
            .wcccf-form-row {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .wcccf-form-row label {
                font-weight: 600;
                color: #1d2327;
            }
            
            .wcccf-form-row input,
            .wcccf-form-row select,
            .wcccf-form-row textarea {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            
            .wcccf-form-row textarea {
                resize: vertical;
                min-height: 60px;
            }
            
            .wcccf-checkbox-label {
                display: flex !important;
                align-items: center;
                gap: 8px;
                font-weight: normal !important;
                cursor: pointer;
            }
            
            .wcccf-checkbox-label input[type="checkbox"] {
                width: auto !important;
                margin: 0 !important;
            }
            
            .description {
                font-style: italic;
                color: #666;
                font-size: 12px;
                margin: 5px 0 0 0;
            }
            
            /* Select2 in modal */
            .select2-container {
                width: 100% !important;
            }
            
            .select2-dropdown {
                z-index: 9999 !important;
            }
            
            /* Full width for single column items */
            #options-row,
            .wcccf-form-row:has(#field-products),
            .wcccf-form-row:has(#field-included-categories),
            .wcccf-form-row:has(#field-excluded-categories) {
                grid-column: 1 / -1;
            }
            
            /* Button styles */
            .ui-dialog-buttonset .button-primary {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }
            
            .ui-dialog-buttonset .button {
                margin-left: 10px;
            }
            
            @media (max-width: 768px) {
                .wcccf-form-grid {
                    grid-template-columns: 1fr;
                }
                
                .ui-dialog {
                    width: 95% !important;
                    margin: 10px auto !important;
                }
            }
        </style>
        <?php
    }

    public function add_custom_checkout_fields($fields) {
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        // Ensure we have an array
        if (!is_array($custom_fields)) {
            return $fields;
        }
        
        foreach ($custom_fields as $index => $field) {
            // Ensure field is an array
            if (!is_array($field) || empty($field['label'])) {
                continue;
            }
            
            if ($this->is_field_applicable($field)) {
                $field_name = 'wcccf_field_' . $index;
                $label = esc_html($field['label']);
                $field_type = esc_attr($field['type'] ?? 'text');
                $location = $field['location'] ?? 'billing';
                $required = isset($field['required']) && $field['required'];
                
                $args = [
                    'type'              => $field_type === 'multiselect' ? 'multiselect' : $field_type,
                    'class'             => ['form-row-wide'],
                    'required'          => $required,
                    'label'             => $label,
                    'priority'          => 25 + $index,
                    'custom_attributes' => [],
                    'placeholder'       => '',
                ];

                if ($field_type === 'select' || $field_type === 'multiselect') {
                    $options = [];
                    if (!empty($field['options'])) {
                        $option_lines = array_filter(array_map('trim', explode("\n", $field['options'])));
                        if (!empty($option_lines)) {
                            if ($field_type === 'multiselect') {
                                foreach ($option_lines as $option) {
                                    $options[$option] = $option;
                                }
                            } else {
                                $options = array('' => 'Select an option');
                                foreach ($option_lines as $option) {
                                    $options[$option] = $option;
                                }
                            }
                        }
                    }
                    $args['options'] = $options;
                    if ($field_type === 'multiselect') {
                        $args['custom_attributes'] = array_merge(
                            $args['custom_attributes'],
                            ['multiple' => 'multiple']
                        );
                        $args['input_class'] = ['wcccf-multiselect-input', 'wc-enhanced-select'];
                        $args['class'][] = 'wcccf-multiselect-field';
                        if (empty($args['placeholder'])) {
                            $args['placeholder'] = __('Select option(s)', 'woocommerce');
                        }
                    }
                }
                
                // Determine field location
                $section = 'billing'; // default
                if ($location === 'shipping') {
                    $section = 'shipping';
                } elseif ($location === 'order') {
                    $section = 'order';
                }
                
                if (!isset($fields[$section])) {
                    $fields[$section] = [];
                }
                
                $fields[$section][$field_name] = $args;
            }
        }
        return $fields;
    }

    public function modify_default_fields($fields) {
        try {
            $disabled_fields = get_option('wcccf_disabled_fields', []);
            $field_labels = get_option('wcccf_field_labels', []);
            $required_fields = get_option('wcccf_required_fields', []);
            $field_types = get_option('wcccf_field_types', []);
            $field_placeholders = get_option('wcccf_field_placeholders', []);
            $field_widths = get_option('wcccf_field_widths', []);
            
            // Ensure we have arrays
            if (!is_array($disabled_fields)) $disabled_fields = [];
            if (!is_array($field_labels)) $field_labels = [];
            if (!is_array($required_fields)) $required_fields = [];
            if (!is_array($field_types)) $field_types = [];
            if (!is_array($field_placeholders)) $field_placeholders = [];
            if (!is_array($field_widths)) $field_widths = [];
            
            // Sections to process
            $sections = ['billing', 'shipping', 'account', 'order'];
            
            foreach ($sections as $section) {
                // Apply Ordering
                $saved_order = get_option('wcccf_field_order_' . $section, '');
                
                // Critical fix: Ensure saved_order is a string before exploding
                if (is_array($saved_order)) {
                    $saved_order = implode(',', $saved_order);
                }
                
                $field_order = array_map('trim', explode(',', (string)$saved_order));
                
                if (isset($fields[$section]) && !empty($field_order) && !empty(array_filter($field_order))) {
                    $section_fields = $fields[$section];
                    $ordered_fields = [];
                    
                    // Add fields in saved order and update priority
                $priority = 10;
                foreach ($field_order as $field_key) {
                    if (isset($section_fields[$field_key])) {
                        $section_fields[$field_key]['priority'] = $priority;
                        $ordered_fields[$field_key] = $section_fields[$field_key];
                        unset($section_fields[$field_key]);
                        $priority += 10;
                    }
                }
                
                // Add remaining fields (newly added or not yet sorted) with higher priority
                foreach ($section_fields as $key => $field) {
                    $section_fields[$key]['priority'] = $priority;
                    $priority += 10;
                }
                
                $fields[$section] = array_merge($ordered_fields, $section_fields);
            }

                // Apply Labels, Disable, and Required
                if (isset($fields[$section])) {
                    foreach ($fields[$section] as $field_key => $field_args) {
                        // Rename label
                        if (isset($field_labels[$field_key]) && !empty($field_labels[$field_key])) {
                            $fields[$section][$field_key]['label'] = sanitize_text_field($field_labels[$field_key]);
                        }
                        
                        // Set Required
                        if (isset($required_fields[$field_key])) {
                            $fields[$section][$field_key]['required'] = (bool) $required_fields[$field_key];
                        }

                        // Set Type
                        if (isset($field_types[$field_key]) && !empty($field_types[$field_key])) {
                            $type_val = sanitize_text_field($field_types[$field_key]);
                            if ($type_val !== 'default') {
                                $fields[$section][$field_key]['type'] = $type_val;
                            }
                        }

                        // Set Placeholder
                        if (isset($field_placeholders[$field_key])) {
                            $fields[$section][$field_key]['placeholder'] = sanitize_text_field($field_placeholders[$field_key]);
                        }

                        // Set Width (Class merging)
                        if (isset($field_widths[$field_key]) && !empty($field_widths[$field_key])) {
                            // Map simple keys to WC classes
                            $width_map = [
                                'wide' => ['form-row-wide'],
                                'first' => ['form-row-first'],
                                'last' => ['form-row-last']
                            ];
                            
                            $width_key = $field_widths[$field_key];
                            $new_classes = $width_map[$width_key] ?? ['form-row-wide'];
                            
                            // Remove existing sizing classes to avoid conflicts
                            $existing_classes = $fields[$section][$field_key]['class'] ?? [];
                            $existing_classes = array_diff($existing_classes, ['form-row-wide', 'form-row-first', 'form-row-last']);
                            
                            $fields[$section][$field_key]['class'] = array_merge($existing_classes, $new_classes);
                        }
                        
                        // Remove if disabled
                        if (isset($disabled_fields[$field_key]) && $disabled_fields[$field_key]) {
                            unset($fields[$section][$field_key]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Silently fail to prevent site crash, but log could be added here if allowed
            // error_log('WC Manager Error: ' . $e->getMessage());
        }
        
        return $fields;
    }

    private function is_field_applicable($field) {
        // Ensure field is an array
        if (!is_array($field)) {
            return false;
        }
        
        // Default to true if no product or category restrictions are set
        $has_product_restrictions = isset($field['products']) && is_array($field['products']) && !empty($field['products']);
        $has_included_category_restrictions = isset($field['included_categories']) && is_array($field['included_categories']) && !empty($field['included_categories']);
        $has_excluded_category_restrictions = isset($field['excluded_categories']) && is_array($field['excluded_categories']) && !empty($field['excluded_categories']);

        if (!$has_product_restrictions && !$has_included_category_restrictions && !$has_excluded_category_restrictions) {
            return true; // No restrictions, so applicable to all
        }

        if (!WC()->cart) {
            return false; // No cart, so not applicable
        }

        $cart_product_ids = [];
        $cart_category_ids = [];

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $cart_product_ids[] = $product_id;

            $product_categories = wc_get_product_term_ids($product_id, 'product_cat');
            $cart_category_ids = array_merge($cart_category_ids, $product_categories);
        }
        $cart_category_ids = array_unique($cart_category_ids);

        // Check excluded categories first (priority)
        if ($has_excluded_category_restrictions) {
            foreach ($cart_category_ids as $cart_cat_id) {
                if (in_array($cart_cat_id, $field['excluded_categories'])) {
                    return false; // Product in an excluded category, so not applicable
                }
            }
        }

        // Check included categories
        if ($has_included_category_restrictions) {
            $found_in_included_category = false;
            foreach ($cart_category_ids as $cart_cat_id) {
                if (in_array($cart_cat_id, $field['included_categories'])) {
                    $found_in_included_category = true;
                    break;
                }
            }
            if (!$found_in_included_category) {
                return false; // No product in any included category, so not applicable
            }
        }

        // Check specific products
        if ($has_product_restrictions) {
            $found_specific_product = false;
            foreach ($cart_product_ids as $cart_prod_id) {
                if (in_array($cart_prod_id, $field['products'])) {
                    $found_specific_product = true;
                    break;
                }
            }
            if (!$found_specific_product) {
                return false; // No specific product found, so not applicable
            }
        }
        
        return true; // All checks passed, field is applicable
    }
 
     public function validate_custom_checkout_fields() {
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        // Ensure we have an array
        if (!is_array($custom_fields)) {
            return;
        }
        
        foreach ($custom_fields as $index => $field) {
            // Ensure field is an array
            if (!is_array($field)) {
                continue;
            }
            
                if ($this->is_field_applicable($field) && isset($field['required']) && $field['required']) {
                    $field_name = 'wcccf_field_' . $index;
                    $posted_value = isset($_POST[$field_name]) ? $_POST[$field_name] : null;
                    $is_empty = false;
                    if (is_array($posted_value)) {
                        $filtered = array_filter(array_map('trim', $posted_value), 'strlen');
                        $is_empty = empty($filtered);
                    } else {
                        $posted_value = is_null($posted_value) ? '' : (string) $posted_value;
                        $is_empty = trim($posted_value) === '';
                    }
                    if ($is_empty) {
                        wc_add_notice(sprintf(__('Please fill in the "%s" field.', 'woocommerce'), $field['label'] ?? 'Custom field'), 'error');
                    }
                }
            }
        }

    public function save_custom_checkout_fields($order_id) {
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        // Ensure we have an array
        if (!is_array($custom_fields)) {
            return;
        }
        
        foreach ($custom_fields as $index => $field) {
            $field_name = 'wcccf_field_' . $index;
            if (isset($_POST[$field_name])) {
                $raw_value = $_POST[$field_name];
                if (is_array($raw_value)) {
                    $sanitized_values = array_map('sanitize_text_field', $raw_value);
                    $sanitized_values = array_filter($sanitized_values, 'strlen');
                    $value = implode(', ', $sanitized_values);
                } else {
                    $value = sanitize_text_field($raw_value);
                }
                update_post_meta($order_id, '_' . $field_name, $value);
                update_post_meta($order_id, '_' . $field_name . '_label', $field['label']);
            }
        }
    }

    public function show_field_in_admin($order) {
        echo '<div class="wcccf-admin-fields">';
        echo '<h3>' . __('Custom Checkout Fields', 'woocommerce') . '</h3>';
        
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        // Ensure we have an array
        if (!is_array($custom_fields)) {
            $custom_fields = [];
        }
        
        $found_fields = false;
        
        foreach ($custom_fields as $index => $field) {
            $field_name = 'wcccf_field_' . $index;
            $value = $order->get_meta('_' . $field_name);
            if (!$value) {
                $value = get_post_meta($order->get_id(), '_' . $field_name, true);
            }
            
            if ($value) {
                $found_fields = true;
                echo '<p><strong>' . esc_html($field['label']) . ':</strong> ' . esc_html($value) . '</p>';
            }
        }
        
        if (!$found_fields) {
            echo '<p><em>' . __('No custom fields data found for this order.', 'woocommerce') . '</em></p>';
        }
        
        echo '</div>';
    }

    public function show_field_on_thank_you_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $this->display_fields_table($order, __('Additional Information', 'woocommerce'));
    }

    public function show_field_on_customer_order_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $this->display_fields_table($order, __('Additional Information', 'woocommerce'), true);
    }

    private function display_fields_table($order, $title, $customer_page = false) {
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        // Ensure we have an array
        if (!is_array($custom_fields)) {
            return;
        }
        
        $fields_html = '';
        
        foreach ($custom_fields as $index => $field) {
            $field_name = 'wcccf_field_' . $index;
            $value = $order->get_meta('_' . $field_name);
            if (!$value) {
                $value = get_post_meta($order->get_id(), '_' . $field_name, true);
            }
            
            if ($value) {
                $fields_html .= '<tr><th>' . esc_html($field['label']) . ':</th><td>' . esc_html($value) . '</td></tr>';
            }
        }

        if ($fields_html) {
            if ($customer_page) {
                echo '<section class="woocommerce-customer-details">';
            }
            echo '<h2>' . $title . '</h2>';
            echo '<table class="woocommerce-table shop_table' . ($customer_page ? ' customer_details' : '') . '"><tbody>' . $fields_html . '</tbody></table>';
            if ($customer_page) {
                echo '</section>';
            }
        }
    }

    public function add_fields_to_email($order, $sent_to_admin, $plain_text) {
        $custom_fields = get_option('wcccf_custom_fields', []);
        
        // Ensure we have an array
        if (!is_array($custom_fields)) {
            return;
        }
        
        $fields_data = [];
        
        foreach ($custom_fields as $index => $field) {
            $field_name = 'wcccf_field_' . $index;
            $value = $order->get_meta('_' . $field_name);
            if (!$value) {
                $value = get_post_meta($order->get_id(), '_' . $field_name, true);
            }
            
            if ($value) {
                $fields_data[] = [
                    'label' => $field['label'],
                    'value' => $value
                ];
            }
        }

        if (!empty($fields_data)) {
            if ($plain_text) {
                echo "\n" . __('Additional Information:', 'woocommerce') . "\n";
                foreach ($fields_data as $field_data) {
                    echo esc_html($field_data['label']) . ': ' . esc_html($field_data['value']) . "\n";
                }
            } else {
                echo '<h2>' . __('Additional Information', 'woocommerce') . '</h2>';
                echo '<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">';
                foreach ($fields_data as $field_data) {
                    echo '<tr>';
                    echo '<th scope="row" style="text-align:left; border: 1px solid #eee; padding: 12px;">' . esc_html($field_data['label']) . '</th>';
                    echo '<td style="text-align:left; border: 1px solid #eee; padding: 12px;">' . esc_html($field_data['value']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        }
    }

    public function render_multiselect_field($field, $key, $args, $value) {
        if (($args['type'] ?? '') !== 'multiselect' || empty($args['options']) || !is_array($args['options'])) {
            return $field;
        }

        $classes        = is_array($args['class'] ?? []) ? $args['class'] : array_filter([$args['class'] ?? '']);
        $label_classes  = is_array($args['label_class'] ?? []) ? $args['label_class'] : array_filter([$args['label_class'] ?? '']);
        $input_classes  = is_array($args['input_class'] ?? []) ? $args['input_class'] : array_filter([$args['input_class'] ?? '']);
        $priority       = isset($args['priority']) ? $args['priority'] : '';
        $placeholder    = !empty($args['placeholder']) ? $args['placeholder'] : __('Select option(s)', 'woocommerce');
        $description    = $args['description'] ?? '';

        $custom_attributes     = [];
        $existing_attribute_map = [];

        $raw_custom_attributes = array_filter((array)($args['custom_attributes'] ?? []), 'strlen');
        foreach ($raw_custom_attributes as $attribute => $attribute_value) {
            $existing_attribute_map[strtolower($attribute)] = true;
            $custom_attributes[] = esc_attr($attribute) . '="' . esc_attr($attribute_value) . '"';
        }

        if (!empty($args['required'])) {
            if (!in_array('required_field', $label_classes, true)) {
                $label_classes[] = 'required_field';
            }
            if (!in_array('validate-required', $classes, true)) {
                $classes[] = 'validate-required';
            }
            if (!isset($existing_attribute_map['aria-required'])) {
                $custom_attributes[] = 'aria-required="true"';
            }
            $required_indicator = '&nbsp;<span class="required" aria-hidden="true">*</span>';
        } else {
            $required_indicator = '&nbsp;<span class="optional">(' . esc_html__('optional', 'woocommerce') . ')</span>';
        }

        if (!isset($existing_attribute_map['multiple'])) {
            $custom_attributes[] = 'multiple="multiple"';
        }

        if ($description && !isset($existing_attribute_map['aria-describedby'])) {
            $custom_attributes[] = 'aria-describedby="' . esc_attr($args['id']) . '-description"';
        }

        if ($placeholder && !isset($existing_attribute_map['data-placeholder'])) {
            $custom_attributes[] = 'data-placeholder="' . esc_attr($placeholder) . '"';
        }

        $selected_values = [];
        if (is_array($value)) {
            $selected_values = array_map('strval', $value);
        } elseif (!empty($value)) {
            $selected_values = array_map('strval', array_map('trim', explode(',', (string)$value)));
        }

        $options_html = '';
        foreach ($args['options'] as $option_key => $option_text) {
            $selected = in_array((string)$option_key, $selected_values, true) ? ' selected="selected"' : '';
            $options_html .= sprintf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($option_key),
                $selected,
                esc_html($option_text)
            );
        }

        $attribute_string = trim(implode(' ', array_filter($custom_attributes)));
        $input_class_attr = trim(implode(' ', array_filter($input_classes)));

        $field_html = '';
        if (!empty($args['label'])) {
            $field_html .= sprintf(
                '<label for="%1$s" class="%2$s">%3$s%4$s</label>',
                esc_attr($args['id']),
                esc_attr(trim(implode(' ', array_filter($label_classes)))),
                wp_kses_post($args['label']),
                $required_indicator
            );
        }

        $field_html .= '<span class="woocommerce-input-wrapper">';
        $field_html .= sprintf(
            '<select name="%1$s[]" id="%2$s" class="select %3$s"%4$s>%5$s</select>',
            esc_attr($key),
            esc_attr($args['id']),
            esc_attr($input_class_attr),
            $attribute_string ? ' ' . $attribute_string : '',
            $options_html
        );

        if ($description) {
            $field_html .= sprintf(
                '<span class="description" id="%1$s-description" aria-hidden="true">%2$s</span>',
                esc_attr($args['id']),
                wp_kses_post($description)
            );
        }

        $field_html .= '</span>';

        $field = sprintf(
            '<p class="form-row %1$s" id="%2$s" data-priority="%3$s">%4$s</p>',
            esc_attr(trim(implode(' ', array_filter($classes)))),
            esc_attr($args['id']) . '_field',
            esc_attr($priority),
            $field_html
        );

        return $field;
    }

    public function get_all_products_for_select() {
        $products = [];
        $args = [
            'status' => 'publish',
            'limit' => -1,
        ];
        $wc_products = wc_get_products($args);

        foreach ($wc_products as $product) {
            $products[$product->get_id()] = $product->get_name();
        }
        return $products;
    }

    public function get_all_product_categories_for_select() {
        $categories = [];
        $product_categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (!empty($product_categories) && !is_wp_error($product_categories)) {
            foreach ($product_categories as $category) {
                $categories[$category->term_id] = $category->name;
            }
        }
        return $categories;
    }

    public function auto_discount_page_html() {
        $this->auto_discount->settings_page_html();
    }

    public function get_all_default_checkout_fields() {
        $fields = [
            'billing'   => [],
            'shipping'  => [],
            'account'   => [],
            'order'     => []
        ];

        try {
            // Use global WC() object if possible, fall back to new instantiation if needed
            if (function_exists('WC') && isset(WC()->countries)) {
                $country_handler = WC()->countries;
            } elseif (class_exists('WC_Countries')) {
                $country_handler = new WC_Countries();
            } else {
                return $fields; // Bail if WC not loaded
            }
            
            $base_country = $country_handler->get_base_country();
            
            $billing = $country_handler->get_address_fields( $base_country, 'billing_' );
            $shipping = $country_handler->get_address_fields( $base_country, 'shipping_' );

            if (is_array($billing)) {
                foreach ($billing as $key => $field) {
                    $fields['billing'][$key] = $field;
                }
            }

            if (is_array($shipping)) {
                foreach ($shipping as $key => $field) {
                    $fields['shipping'][$key] = $field;
                }
            }
        } catch (Throwable $e) {
            // Prevent fatal error in admin area
        }

        // Account fields
        $fields['account']['account_username'] = [
            'label' => 'Account Username',
            'type' => 'text',
            'required' => true
        ];
        $fields['account']['account_password'] = [
            'label' => 'Account Password',
            'type' => 'password',
            'required' => true
        ];
        
        // Order fields
        $fields['order']['order_comments'] = [
            'label' => 'Order Notes',
            'type' => 'textarea',
            'required' => false
        ];

        return $fields;
    }

    public function module_settings_page_html() {
        $active_modules = get_option('wc_manager_active_modules', [
            'checkout_fields' => 1,
            'discount_manager' => 1,
            'wc_popups' => 0,
            'wc_slides' => 0,
        ]);
        ?>
        <div class="wrap">
            <h1><?php _e('WC Manager - Module Settings', 'wc-manager'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <div class="card">
                <h2><?php _e('Active Modules', 'wc-manager'); ?></h2>
                <p><?php _e('Enable or disable modules to show/hide their menu items. Inactive modules will not appear in the WC Manager submenu.', 'wc-manager'); ?></p>
                
                <form method="post" action="options.php">
                    <?php settings_fields('wc_manager_modules_group'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Checkout Fields', 'wc-manager'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_manager_active_modules[checkout_fields]" value="1" <?php checked(!empty($active_modules['checkout_fields'])); ?>>
                                    <?php _e('Enable Checkout Fields module', 'wc-manager'); ?>
                                </label>
                                <p class="description"><?php _e('Manage custom checkout fields and default field settings.', 'wc-manager'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Discount Manager', 'wc-manager'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_manager_active_modules[discount_manager]" value="1" <?php checked(!empty($active_modules['discount_manager'])); ?>>
                                    <?php _e('Enable Discount Manager module', 'wc-manager'); ?>
                                </label>
                                <p class="description"><?php _e('Create and manage automatic discount rules.', 'wc-manager'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('WC Popups', 'wc-manager'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_manager_active_modules[wc_popups]" value="1" <?php checked(!empty($active_modules['wc_popups'])); ?>>
                                    <?php _e('Enable WC Popups module', 'wc-manager'); ?>
                                </label>
                                <p class="description"><?php _e('Manage popups and branch selectors.', 'wc-manager'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('WC Slides', 'wc-manager'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_manager_active_modules[wc_slides]" value="1" <?php checked(!empty($active_modules['wc_slides'])); ?>>
                                    <?php _e('Enable WC Slides module', 'wc-manager'); ?>
                                </label>
                                <p class="description"><?php _e('Create product sliders with customizable settings and presets.', 'wc-manager'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save Module Settings', 'wc-manager')); ?>
                </form>
            </div>
            
            <style>
                .form-table th {
                    width: 200px;
                    font-weight: 600;
                }
                .form-table td label {
                    font-weight: 500;
                }
                .form-table .description {
                    color: #646970;
                    font-style: italic;
                }
            </style>
        </div>
        <?php
    }

    public function import_export_page_html() {
        ?>
        <div class="wrap">
            <h1>Settings</h1>

            <?php settings_errors(); ?>

            <div class="card" style="margin-bottom: 20px;">
                <h2>General Settings</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('wcccf_settings_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Checkout Quantity Selector</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wcccf_quantity_selector_enabled" value="1" <?php checked(get_option('wcccf_quantity_selector_enabled'), 1); ?>>
                                    Enable quantity selector on checkout page
                                </label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>

            <div class="card">
                <h2>Export Settings</h2>
                <p>Export the plugin settings for this site as a .json file. This allows you to easily import the configuration into another site.</p>
                <form method="post">
                    <input type="hidden" name="wcccf_action" value="export_settings" />
                    <?php wp_nonce_field('wcccf_export_nonce', 'wcccf_export_nonce'); ?>
                    <?php submit_button('Export'); ?>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Import Settings</h2>
                <p>Import the plugin settings from a .json file. This will overwrite the current settings.</p>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="wcccf_action" value="import_settings" />
                    <input type="file" name="wcccf_import_file" accept=".json" />
                    <?php wp_nonce_field('wcccf_import_nonce', 'wcccf_import_nonce'); ?>
                    <?php submit_button('Import'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function handle_export_import() {
        if (isset($_POST['wcccf_action'])) {
            if ($_POST['wcccf_action'] === 'export_settings') {
                if (!isset($_POST['wcccf_export_nonce']) || !wp_verify_nonce($_POST['wcccf_export_nonce'], 'wcccf_export_nonce')) {
                    wp_die('Security check failed.');
                }
                if (!current_user_can('manage_options')) {
                    wp_die('You do not have permission to export settings.');
                }

                $settings = [
                    'wcccf_custom_fields' => get_option('wcccf_custom_fields', []),
                    'wcccf_disabled_fields' => get_option('wcccf_disabled_fields', []),
                ];

                header('Content-disposition: attachment; filename=wcccf-settings-export-' . date('Y-m-d') . '.json');
                header('Content-type: application/json');
                echo json_encode($settings);
                die();
            }

            if ($_POST['wcccf_action'] === 'import_settings') {
                if (!isset($_POST['wcccf_import_nonce']) || !wp_verify_nonce($_POST['wcccf_import_nonce'], 'wcccf_import_nonce')) {
                    wp_die('Security check failed.');
                }
                if (!current_user_can('manage_options')) {
                    wp_die('You do not have permission to import settings.');
                }
                if (isset($_FILES['wcccf_import_file']) && $_FILES['wcccf_import_file']['error'] === 0) {
                    $file = $_FILES['wcccf_import_file']['tmp_name'];
                    $content = file_get_contents($file);
                    $settings = json_decode($content, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        if (isset($settings['wcccf_custom_fields'])) {
                            update_option('wcccf_custom_fields', $settings['wcccf_custom_fields']);
                        }
                        if (isset($settings['wcccf_disabled_fields'])) {
                            update_option('wcccf_disabled_fields', $settings['wcccf_disabled_fields']);
                        }
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-success is-dismissible"><p>Settings imported successfully.</p></div>';
                        });
                    } else {
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-error is-dismissible"><p>Error importing settings: Invalid JSON file.</p></div>';
                        });
                    }
                } else {
                     add_action('admin_notices', function() {
                        echo '<div class="notice notice-error is-dismissible"><p>Error importing settings: No file uploaded or upload error.</p></div>';
                    });
                }
            }
        }
    }
}
