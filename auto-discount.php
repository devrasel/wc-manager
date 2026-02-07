<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WCCCF_Auto_Discount {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_fixed_discount_for_multiple_products'], 20, 1);
        add_filter('woocommerce_package_rates', [$this, 'apply_role_based_free_shipping'], 10, 2);
        add_action('admin_init', [$this, 'register_discount_settings']);
        add_action('wp_ajax_wcccf_delete_discount_rule', [$this, 'ajax_delete_discount_rule']);
        add_action('wp_ajax_wcccf_add_discount_rule', [$this, 'ajax_add_discount_rule']);
        add_action('wp_ajax_wcccf_update_discount_rule', [$this, 'ajax_update_discount_rule']);
        add_action('wp_ajax_wcccf_get_discount_rule', [$this, 'ajax_get_discount_rule']);
    }

    public function register_discount_settings() {
        register_setting(
            'wcccf_auto_discount_group',
            'wcccf_discount_rules',
            ['type' => 'array', 'default' => [], 'sanitize_callback' => [$this, 'sanitize_discount_rules']]
        );
        register_setting(
            'wcccf_auto_discount_group',
            'wcccf_auto_discount_enabled',
            ['type' => 'integer', 'default' => 1]
        );
    }

    public function sanitize_discount_rules($rules) {
        // Start with an empty array to avoid duplicating rules
        $sanitized_rules = [];
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                $sanitized_rule = $this->sanitize_single_rule($rule);
                if (isset($sanitized_rule['type'])) { // Only add if type is set, indicating a valid rule
                    $sanitized_rules[] = $sanitized_rule;
                }
            }
        }
        return $sanitized_rules;
    }

    private function sanitize_single_rule($rule) {
        $sanitized_rule = [];
        
        if (isset($rule['type'])) {
            $sanitized_rule['type'] = sanitize_text_field($rule['type']);
        }
        if (isset($rule['product_id'])) {
            $sanitized_rule['product_id'] = intval($rule['product_id']);
        }
        if (isset($rule['amount'])) {
            $sanitized_rule['amount'] = floatval($rule['amount']);
        }
        if (isset($rule['percentage'])) {
            $sanitized_rule['percentage'] = floatval($rule['percentage']);
        }
        if (isset($rule['min_qty'])) {
            $sanitized_rule['min_qty'] = intval($rule['min_qty']);
        }
        if (isset($rule['max_qty'])) {
            $sanitized_rule['max_qty'] = intval($rule['max_qty']);
        }
        if (isset($rule['categories'])) {
            $sanitized_rule['categories'] = array_map('intval', (array)$rule['categories']);
        }
        if (isset($rule['tags'])) {
            $sanitized_rule['tags'] = array_map('intval', (array)$rule['tags']);
        }
        if (isset($rule['roles'])) {
            $sanitized_rule['roles'] = array_map('sanitize_text_field', (array)$rule['roles']);
        }
        if (isset($rule['buy_product_id'])) {
            $sanitized_rule['buy_product_id'] = intval($rule['buy_product_id']);
        }
        if (isset($rule['buy_qty'])) {
            $sanitized_rule['buy_qty'] = intval($rule['buy_qty']);
        }
        if (isset($rule['get_product_id'])) {
            $sanitized_rule['get_product_id'] = intval($rule['get_product_id']);
        }
        if (isset($rule['get_qty'])) {
            $sanitized_rule['get_qty'] = intval($rule['get_qty']);
        }
        
        return $sanitized_rule;
    }

    public function ajax_delete_discount_rule() {
        check_ajax_referer('wcccf_discount_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $rule_index = isset($_POST['rule_index']) ? intval($_POST['rule_index']) : -1;
        
        if ($rule_index < 0) {
            wp_send_json_error('Invalid rule index');
        }
        
        $rules = get_option('wcccf_discount_rules', []);
        
        if (!is_array($rules)) {
            wp_send_json_error('No rules found');
        }
        
        if (!isset($rules[$rule_index])) {
            wp_send_json_error('Rule not found');
        }
        
        // Remove the rule
        unset($rules[$rule_index]);
        
        // Reindex array to avoid gaps
        $rules = array_values($rules);
        
        // Save to database
        update_option('wcccf_discount_rules', $rules);
        
        wp_send_json_success([
            'message' => 'Rule deleted successfully',
            'remaining_count' => count($rules)
        ]);
    }

    public function ajax_get_discount_rule() {
        check_ajax_referer('wcccf_discount_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $rule_index = isset($_POST['rule_index']) ? intval($_POST['rule_index']) : -1;
        
        if ($rule_index < 0) {
            wp_send_json_error('Invalid rule index');
        }
        
        $rules = get_option('wcccf_discount_rules', []);
        
        if (!isset($rules[$rule_index])) {
            wp_send_json_error('Rule not found');
        }
        
        wp_send_json_success($rules[$rule_index]);
    }

    public function ajax_add_discount_rule() {
        check_ajax_referer('wcccf_discount_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $new_rule = isset($_POST['rule']) ? $_POST['rule'] : [];
        
        if (empty($new_rule) || !isset($new_rule['type'])) {
            wp_send_json_error('Invalid rule data');
        }
        
        // Sanitize the rule using existing sanitization logic
        $sanitized_rule = $this->sanitize_single_rule($new_rule);
        
        if (!isset($sanitized_rule['type'])) {
            wp_send_json_error('Invalid rule type');
        }
        
        $rules = get_option('wcccf_discount_rules', []);
        if (!is_array($rules)) {
            $rules = [];
        }
        
        $rules[] = $sanitized_rule;
        update_option('wcccf_discount_rules', $rules);
        
        wp_send_json_success([
            'message' => 'Rule added successfully',
            'rule' => $sanitized_rule,
            'index' => count($rules) - 1
        ]);
    }

    public function ajax_update_discount_rule() {
        check_ajax_referer('wcccf_discount_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $rule_index = isset($_POST['rule_index']) ? intval($_POST['rule_index']) : -1;
        $updated_rule = isset($_POST['rule']) ? $_POST['rule'] : [];
        
        if ($rule_index < 0 || empty($updated_rule)) {
            wp_send_json_error('Invalid data');
        }
        
        $rules = get_option('wcccf_discount_rules', []);
        
        if (!isset($rules[$rule_index])) {
            wp_send_json_error('Rule not found');
        }
        
        // Sanitize the rule
        $sanitized_rule = $this->sanitize_single_rule($updated_rule);
        
        if (!isset($sanitized_rule['type'])) {
            wp_send_json_error('Invalid rule type');
        }
        
        $rules[$rule_index] = $sanitized_rule;
        update_option('wcccf_discount_rules', $rules);
        
        wp_send_json_success([
            'message' => 'Rule updated successfully',
            'rule' => $sanitized_rule
        ]);
    }
    
    public function settings_page_html() {
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            add_settings_error('wcccf_messages', 'wcccf_message', __('Settings saved successfully.', 'wc-manager'), 'updated');
        }
        
        settings_errors('wcccf_messages');
        $all_products = $this->get_all_products_for_select();
        $all_categories = $this->get_all_product_categories_for_select();
        $all_tags = $this->get_all_product_tags_for_select();
        $all_roles = $this->get_all_user_roles_for_select();
        ?>
        <div class="wrap">
            <h1>Discount Manager</h1>
            <p>Configure rules to automatically apply discounts based on various conditions.</p>

            <form method="post" action="options.php">
                <?php
                settings_fields('wcccf_auto_discount_group');
                $rules = get_option('wcccf_discount_rules', []);
                $is_enabled = get_option('wcccf_auto_discount_enabled', 1);
                ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row" class="titledesc">
                            <label for="wcccf_auto_discount_enabled">Enable Discounts</label>
                        </th>
                        <td class="forminp">
                            <input type="checkbox" name="wcccf_auto_discount_enabled" id="wcccf_auto_discount_enabled" value="1" <?php checked($is_enabled, 1); ?>>
                            <p class="description">Enable or disable the discount functionality.</p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top: 30px;">Discount Rules</h2>
                
                <div id="discount-rules-wrapper">
                    <table class="wp-list-table widefat fixed striped" id="discount-rules-table">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Type</th>
                                <th style="width: 40%;">Condition</th>
                                <th style="width: 20%;">Value</th>
                                <th style="width: 10%;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="rules-list">
                            <?php if (!empty($rules)) : ?>
                                <?php foreach ($rules as $index => $rule) : ?>
                                    <tr>
                                        <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $rule['type'] ?? ''))); ?></td>
                                        <td>
                                            <?php
                                            if (isset($rule['product_id'])) {
                                                $product = wc_get_product($rule['product_id']);
                                                echo $product ? esc_html($product->get_formatted_name()) : __('Product not found', 'wc-manager');
                                            } elseif (isset($rule['categories']) && !empty($rule['categories'])) {
                                                $cat_names = array_map(function($cat_id) use ($all_categories) {
                                                    return $all_categories[$cat_id] ?? '';
                                                }, $rule['categories']);
                                                echo __('Categories: ', 'wc-manager') . implode(', ', array_filter($cat_names));
                                            } elseif (isset($rule['tags']) && !empty($rule['tags'])) {
                                                $tag_names = array_map(function($tag_id) use ($all_tags) {
                                                    $term = get_term($tag_id, 'product_tag');
                                                    return $term ? $term->name : '';
                                                }, $rule['tags']);
                                                echo __('Tags: ', 'wc-manager') . implode(', ', array_filter($tag_names));
                                            } elseif (isset($rule['roles']) && !empty($rule['roles'])) {
                                                $role_names = array_map(function($role_key) use ($all_roles) {
                                                    return $all_roles[$role_key] ?? $role_key;
                                                }, $rule['roles']);
                                                echo __('Roles: ', 'wc-manager') . implode(', ', $role_names);
                                            } elseif ($rule['type'] === 'bogo') {
                                                $buy_product = wc_get_product($rule['buy_product_id']);
                                                $get_product = wc_get_product($rule['get_product_id']);
                                                echo sprintf(__('Buy %s x %s, Get %s x %s Free', 'wc-manager'), esc_html($rule['buy_qty']), $buy_product ? esc_html($buy_product->get_name()) : '', esc_html($rule['get_qty']), $get_product ? esc_html($get_product->get_name()) : '');
                                            }
                                            if (isset($rule['min_qty']) || isset($rule['max_qty'])) {
                                                echo '<br>' . __('Quantity: ', 'wc-manager');
                                                if (isset($rule['min_qty'])) echo 'Min ' . esc_html($rule['min_qty']);
                                                if (isset($rule['max_qty'])) echo ' Max ' . esc_html($rule['max_qty']);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            if (isset($rule['amount'])) {
                                                echo get_woocommerce_currency_symbol() . esc_html($rule['amount']);
                                            } elseif (isset($rule['percentage'])) {
                                                echo esc_html($rule['percentage']) . '%';
                                            } elseif ($rule['type'] === 'bogo') {
                                                echo __('Free items', 'wc-manager');
                                            } elseif ($rule['type'] === 'bogo') {
                                                echo __('Free items', 'wc-manager');
                                            } elseif ($rule['type'] === 'role_based_free_shipping') {
                                                echo __('Free Shipping', 'wc-manager');
                                                if (isset($rule['amount'])) echo ' + ' . get_woocommerce_currency_symbol() . esc_html($rule['amount']) . ' off';
                                                if (isset($rule['percentage'])) echo ' + ' . esc_html($rule['percentage']) . '% off';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small edit-rule-btn" data-rule-index="<?php echo $index; ?>">Edit</button>
                                            <button type="button" class="button button-small button-danger delete-rule-btn">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="no-rules-row" style="<?php echo !empty($rules) ? 'display: none;' : ''; ?>">
                                <td colspan="4"><em>No discount rules configured yet.</em></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="add-rule-form" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                    <h3>Add New Rule</h3>
                    <div class="wcccf-form-row">
                        <label for="rule-type">Discount Type</label>
                        <select id="rule-type" name="rule_type">
                            <option value="fixed_product">Fixed Product Discount (e.g., $5 off specific product)</option>
                            <option value="percentage_product">Percentage Product Discount (e.g., 10% off specific product)</option>
                            <option value="tiered_product">Tiered Product Discount (e.g., $5 off 2-4 qty, $10 off 5+ qty)</option>
                            <option value="category_discount">Category Discount (e.g., 10% off products in a category)</option>
                            <option value="tag_discount">Tag Discount (e.g., $5 off products with a specific tag)</option>
                            <option value="bogo">Buy X Get Y Free</option>
                            <option value="role_based_free_shipping">Role Based Free Shipping & Discount</option>
                        </select>
                    </div>
                    
                    <div id="rule-role-fields" class="wcccf-rule-fields" style="display:none;">
                        <div class="wcccf-form-row">
                            <label for="add-role-search">User Roles</label>
                            <select id="add-role-search" multiple="multiple" class="wc-role-search" data-placeholder="Select user roles..." style="width: 350px;">
                                <?php foreach ($all_roles as $role_key => $role_name) : ?>
                                    <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div id="rule-bogo-fields" class="wcccf-rule-fields" style="display:none;">
                        <div class="wcccf-form-row">
                            <label for="add-bogo-buy-product">Buy Product</label>
                            <select id="add-bogo-buy-product" class="wc-product-search" data-placeholder="Search for 'Buy' product..." style="width: 350px;"></select>
                        </div>
                        <div class="wcccf-form-row">
                            <label for="add-bogo-buy-qty">Buy Quantity (X)</label>
                            <input type="number" id="add-bogo-buy-qty" placeholder="Quantity to buy" class="short" min="1">
                        </div>
                        <div class="wcccf-form-row">
                            <label for="add-bogo-get-product">Get Product</label>
                            <select id="add-bogo-get-product" class="wc-product-search" data-placeholder="Search for 'Get' product..." style="width: 350px;"></select>
                        </div>
                        <div class="wcccf-form-row">
                            <label for="add-bogo-get-qty">Get Quantity (Y)</label>
                            <input type="number" id="add-bogo-get-qty" placeholder="Quantity to get free" class="short" min="1">
                        </div>
                    </div>

                    <div id="rule-product-fields" class="wcccf-rule-fields">
                        <div class="wcccf-form-row">
                            <label for="add-product-search">Product</label>
                            <select id="add-product-search" class="wc-product-search" data-placeholder="Search for a product..." style="width: 350px;"></select>
                        </div>
                    </div>

                    <div id="rule-category-fields" class="wcccf-rule-fields" style="display:none;">
                        <div class="wcccf-form-row">
                            <label for="add-category-search">Categories</label>
                            <select id="add-category-search" multiple="multiple" class="wc-category-search" data-placeholder="Select categories..." style="width: 350px;">
                                <?php foreach ($all_categories as $cat_id => $cat_name) : ?>
                                    <option value="<?php echo esc_attr($cat_id); ?>"><?php echo esc_html($cat_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="rule-tag-fields" class="wcccf-rule-fields" style="display:none;">
                        <div class="wcccf-form-row">
                            <label for="add-tag-search">Tags</label>
                            <select id="add-tag-search" multiple="multiple" class="wc-tag-search" data-placeholder="Select tags..." style="width: 350px;">
                                <?php foreach ($all_tags as $tag_id => $tag_name) : ?>
                                    <option value="<?php echo esc_attr($tag_id); ?>"><?php echo esc_html($tag_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="rule-quantity-fields" class="wcccf-rule-fields">
                        <div class="wcccf-form-row" style="display: flex; flex-direction: row; gap: 10px;">
                            <div style="flex: 1;">
                                <label for="add-min-qty">Minimum Quantity</label>
                                <input type="number" id="add-min-qty" placeholder="Min Qty" class="short" min="1">
                            </div>
                            <div style="flex: 1;">
                                <label for="add-max-qty">Maximum Quantity (optional)</label>
                                <input type="number" id="add-max-qty" placeholder="Max Qty" class="short" min="1">
                            </div>
                        </div>
                    </div>

                    <div id="rule-amount-fields" class="wcccf-rule-fields">
                        <div class="wcccf-form-row">
                            <label for="add-discount-amount">Discount Amount (<?php echo get_woocommerce_currency_symbol(); ?>)</label>
                            <input type="number" id="add-discount-amount" placeholder="Amount" step="0.01" class="short">
                        </div>
                    </div>

                    <div id="rule-percentage-fields" class="wcccf-rule-fields" style="display:none;">
                        <div class="wcccf-form-row">
                            <label for="add-discount-percentage">Discount Percentage (%)</label>
                            <input type="number" id="add-discount-percentage" placeholder="Percentage" step="0.01" min="0" max="100" class="short">
                        </div>
                    </div>

                    <button type="button" class="button button-primary" id="add-rule-btn" style="margin-top: 10px;">Add Rule</button>
                </div>
                
                <?php // submit_button('Save Rules'); // Removed - using auto-save via AJAX ?>
            </form>
        </div>

        <style>
            .wcccf-form-row {
                margin-bottom: 15px;
            }
            .wcccf-form-row label {
                display: block;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .wcccf-form-row select,
            .wcccf-form-row input[type="number"],
            .wcccf-form-row input[type="text"] {
                width: 100%;
                max-width: 350px;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .wcccf-form-row.inline-fields {
                display: flex;
                gap: 15px;
            }
            .wcccf-form-row.inline-fields > div {
                flex: 1;
            }
            .select2-container {
                width: 350px !important;
            }
        </style>

        <script type="text/javascript">
            var wcccf_discount_vars = {
                ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce: '<?php echo wp_create_nonce('wcccf_discount_nonce'); ?>'
            };
        </script>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Initialize Select2 for product, category, and tag searches
                if (typeof($().select2) !== 'undefined') {
                    $('#add-product-search, #add-bogo-buy-product, #add-bogo-get-product').select2({
                        ajax: {
                            url: ajaxurl,
                            dataType: 'json',
                            delay: 250,
                            data: function (params) {
                                return {
                                    term: params.term,
                                    action: 'woocommerce_json_search_products_and_variations',
                                    security: '<?php echo wp_create_nonce("search-products"); ?>'
                                };
                            },
                            processResults: function (data) {
                                var terms = [];
                                if (data) {
                                    $.each(data, function (id, text) {
                                        terms.push({id: id, text: text});
                                    });
                                }
                                return {
                                    results: terms
                                };
                            },
                            cache: true
                        },
                        minimumInputLength: 2
                    });

                    $('#add-category-search').select2();
                    $('#add-tag-search').select2();
                    $('#add-role-search').select2();
                }

                function showRuleFields(type) {
                    $('.wcccf-rule-fields').hide();
                    // Reset values when switching types
                    $('#add-product-search').val(null).trigger('change');
                    $('#add-category-search').val(null).trigger('change');
                    $('#add-tag-search').val(null).trigger('change');
                    $('#add-role-search').val(null).trigger('change');
                    $('#add-discount-amount').val('');
                    $('#add-discount-percentage').val('');
                    $('#add-min-qty').val('');
                    $('#add-max-qty').val('');
                    $('#add-bogo-buy-product').val(null).trigger('change');
                    $('#add-bogo-buy-qty').val('');
                    $('#add-bogo-get-product').val(null).trigger('change');
                    $('#add-bogo-get-qty').val('');
 
                      if (type === 'fixed_product') {
                        $('#rule-product-fields').show();
                        $('#rule-amount-fields').show();
                      } else if (type === 'percentage_product') {
                        $('#rule-product-fields').show();
                        $('#rule-percentage-fields').show();
                      } else if (type === 'tiered_product') {
                          $('#rule-product-fields').show();
                          $('#rule-quantity-fields').show();
                          $('#rule-amount-fields').show();
                      } else if (type === 'category_discount') {
                          $('#rule-category-fields').show();
                          $('#rule-amount-fields').show(); // Can be fixed or percentage
                          $('#rule-percentage-fields').show();
                      } else if (type === 'tag_discount') {
                          $('#rule-tag-fields').show();
                          $('#rule-amount-fields').show(); // Can be fixed or percentage
                          $('#rule-percentage-fields').show();
                      } else if (type === 'bogo') {
                          $('#rule-bogo-fields').show();
                      } else if (type === 'role_based_free_shipping') {
                          $('#rule-role-fields').show();
                          $('#rule-amount-fields').show();
                          $('#rule-percentage-fields').show();
                      }
                  }
 
                  // Initial display based on default selected type
                  showRuleFields($('#rule-type').val());

                $('#rule-type').on('change', function() {
                    showRuleFields($(this).val());
                });

                // Edit mode tracking
                var editingRuleIndex = null;

                // Edit button handler
                $(document).on('click', '.edit-rule-btn', function() {
                    var ruleIndex = $(this).data('rule-index');
                    editingRuleIndex = ruleIndex;
                    
                    // Change button to "Update Rule" and add cancel button
                    $('#add-rule-btn').text('Update Rule').data('editing', true);
                    if ($('#cancel-edit-btn').length === 0) {
                        $('#add-rule-btn').after(' <button type="button" class="button" id="cancel-edit-btn">Cancel</button>');
                    }
                    
                    // Scroll to form
                    $('html, body').animate({
                        scrollTop: $('.add-rule-form').offset().top - 50
                    }, 500);
                    
                    // Load rule data
                    $.ajax({
                        url: wcccf_discount_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wcccf_get_discount_rule',
                            nonce: wcccf_discount_vars.nonce,
                            rule_index: ruleIndex
                        },
                        success: function(response) {
                            if (response.success) {
                                populateFormWithRule(response.data);
                            } else {
                                alert('Error loading rule: ' + (response.data || 'Unknown error'));
                            }
                        },
                        error: function() {
                            alert('Error loading rule data');
                        }
                    });
                });

                // Cancel edit button handler
                $(document).on('click', '#cancel-edit-btn', function() {
                    resetFormToAddMode();
                });

                // Helper: Reset form to add mode
                function resetFormToAddMode() {
                    editingRuleIndex = null;
                    $('#add-rule-btn').text('Add Rule').data('editing', false);
                    $('#cancel-edit-btn').remove();
                    showRuleFields($('#rule-type').val());
                }

                // Helper: Populate form with rule data
                function populateFormWithRule(rule) {
                    $('#rule-type').val(rule.type).trigger('change');
                    
                    setTimeout(function() {
                        // Product fields
                        if (rule.product_id) {
                            var productName = 'Product #' + rule.product_id;
                            var option = new Option(productName, rule.product_id, true, true);
                            $('#add-product-search').append(option).trigger('change');
                        }
                        // Categories
                        if (rule.categories) {
                            $('#add-category-search').val(rule.categories).trigger('change');
                        }
                        // Tags
                        if (rule.tags) {
                            $('#add-tag-search').val(rule.tags).trigger('change');
                        }
                        // Roles
                        if (rule.roles) {
                            $('#add-role-search').val(rule.roles).trigger('change');
                        }
                        // Amounts
                        if (rule.amount) {
                            $('#add-discount-amount').val(rule.amount);
                        }
                        if (rule.percentage) {
                            $('#add-discount-percentage').val(rule.percentage);
                        }
                        // Quantities
                        if (rule.min_qty) {
                            $('#add-min-qty').val(rule.min_qty);
                        }
                        if (rule.max_qty) {
                            $('#add-max-qty').val(rule.max_qty);
                        }
                        // BOGO fields
                        if (rule.buy_product_id) {
                            var buyOption = new Option('Product #' + rule.buy_product_id, rule.buy_product_id, true, true);
                            $('#add-bogo-buy-product').append(buyOption).trigger('change');
                            $('#add-bogo-buy-qty').val(rule.buy_qty || '');
                        }
                        if (rule.get_product_id) {
                            var getOption = new Option('Product #' + rule.get_product_id, rule.get_product_id, true, true);
                            $('#add-bogo-get-product').append(getOption).trigger('change');
                            $('#add-bogo-get-qty').val(rule.get_qty || '');
                        }
                    }, 300);
                }

                // Add/Update Rule Button Handler
                $('#add-rule-btn').on('click', function() {
                    var isEditing = $(this).data('editing');
                    var ruleType = $('#rule-type').val();
                    var rule = { type: ruleType };
                    var isValid = true;

                    // Validation logic
                    switch (ruleType) {
                        case 'fixed_product':
                        case 'percentage_product':
                            var productData = $('#add-product-search').select2('data');
                            if (!productData || productData.length === 0) {
                                alert('Please select a product.');
                                isValid = false;
                            } else {
                                rule.product_id = productData[0].id;
                                if (ruleType === 'fixed_product') {
                                    rule.amount = $('#add-discount-amount').val();
                                    if (!rule.amount) { alert('Please enter a discount amount.'); isValid = false; }
                                } else {
                                    rule.percentage = $('#add-discount-percentage').val();
                                    if (!rule.percentage) { alert('Please enter a discount percentage.'); isValid = false; }
                                }
                            }
                            break;
                        case 'tiered_product':
                            var productData = $('#add-product-search').select2('data');
                            if (!productData || productData.length === 0) {
                                alert('Please select a product.');
                                isValid = false;
                            } else {
                                rule.product_id = productData[0].id;
                                rule.min_qty = $('#add-min-qty').val();
                                rule.max_qty = $('#add-max-qty').val();
                                rule.amount = $('#add-discount-amount').val();
                                if (!rule.min_qty) { alert('Please enter a minimum quantity.'); isValid = false; }
                                if (!rule.amount) { alert('Please enter a discount amount.'); isValid = false; }
                            }
                            break;
                        case 'category_discount':
                            rule.categories = $('#add-category-search').val();
                            if (!rule.categories || rule.categories.length === 0) {
                                alert('Please select at least one category.');
                                isValid = false;
                            }
                            var catAmount = $('#add-discount-amount').val();
                            var catPercentage = $('#add-discount-percentage').val();
                            // Only set if values are provided
                            if (catAmount && catAmount.trim() !== '') {
                                rule.amount = catAmount;
                            }
                            if (catPercentage && catPercentage.trim() !== '') {
                                rule.percentage = catPercentage;
                            }
                            // Validate: need at least one, but not both
                            if (!rule.amount && !rule.percentage) {
                                alert('Please enter either a discount amount or a percentage.');
                                isValid = false;
                            } else if (rule.amount && rule.percentage) {
                                alert('Please enter either a discount amount OR a percentage, not both.');
                                isValid = false;
                            }
                            break;
                        case 'tag_discount':
                            rule.tags = $('#add-tag-search').val();
                            if (!rule.tags || rule.tags.length === 0) {
                                alert('Please select at least one tag.');
                                isValid = false;
                            }
                            var tagAmount = $('#add-discount-amount').val();
                            var tagPercentage = $('#add-discount-percentage').val();
                            // Only set if values are provided
                            if (tagAmount && tagAmount.trim() !== '') {
                                rule.amount = tagAmount;
                            }
                            if (tagPercentage && tagPercentage.trim() !== '') {
                                rule.percentage = tagPercentage;
                            }
                            // Validate: need at least one, but not both
                            if (!rule.amount && !rule.percentage) {
                                alert('Please enter either a discount amount or a percentage.');
                                isValid = false;
                            } else if (rule.amount && rule.percentage) {
                                alert('Please enter either a discount amount OR a percentage, not both.');
                                isValid = false;
                            }
                            break;
                        case 'bogo':
                            var buyProductData = $('#add-bogo-buy-product').select2('data');
                            var getProductData = $('#add-bogo-get-product').select2('data');
                            rule.buy_product_id = buyProductData.length > 0 ? buyProductData[0].id : '';
                            rule.buy_qty = $('#add-bogo-buy-qty').val();
                            rule.get_product_id = getProductData.length > 0 ? getProductData[0].id : '';
                            rule.get_qty = $('#add-bogo-get-qty').val();
                            if (!rule.buy_product_id || !rule.buy_qty || !rule.get_product_id || !rule.get_qty) {
                                alert('Please fill in all BOGO fields.');
                                isValid = false;
                            }
                            break;
                        case 'role_based_free_shipping':
                            rule.roles = $('#add-role-search').val();
                            if (!rule.roles || rule.roles.length === 0) {
                                alert('Please select at least one user role.');
                                isValid = false;
                            }
                            // Amount and percentage are optional for role-based (free shipping is main benefit)
                            var roleAmount = $('#add-discount-amount').val();
                            var rolePercentage = $('#add-discount-percentage').val();
                            
                            // Only set if values are provided
                            if (roleAmount && roleAmount.trim() !== '') {
                                rule.amount = roleAmount;
                            }
                            if (rolePercentage && rolePercentage.trim() !== '') {
                                rule.percentage = rolePercentage;
                            }
                            
                            // Validate: can have both empty (free shipping only), but not both filled
                            if (rule.amount && rule.percentage) {
                                alert('Please enter either a discount amount OR a percentage, not both.');
                                isValid = false;
                            }
                            break;
                        default:
                            isValid = false;
                            break;
                    }

                    if (!isValid) return;

                    // AJAX save
                    var actionName = isEditing ? 'wcccf_update_discount_rule' : 'wcccf_add_discount_rule';
                    var ajaxData = {
                        action: actionName,
                        nonce: wcccf_discount_vars.nonce,
                        rule: rule
                    };
                    
                    if (isEditing) {
                        ajaxData.rule_index = editingRuleIndex;
                    }
                    
                    $(this).prop('disabled', true).text(isEditing ? 'Updating...' : 'Adding...');
                    
                    $.ajax({
                        url: wcccf_discount_vars.ajax_url,
                        type: 'POST',
                        data: ajaxData,
                        success: function(response) {
                            if (response.success) {
                                // Reload page to refresh rules list
                                location.reload();
                            } else {
                                alert('Error: ' + (response.data || 'Failed to save rule'));
                                $('#add-rule-btn').prop('disabled', false).text(isEditing ? 'Update Rule' : 'Add Rule');
                            }
                        },
                        error: function() {
                            alert('Error: Failed to save rule. Please try again.');
                            $('#add-rule-btn').prop('disabled', false).text(isEditing ? 'Update Rule' : 'Add Rule');
                        }
                    });
                });

                $(document).on('click', '.delete-rule-btn', function() {
                    if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this discount rule?', 'wc-manager')); ?>')) {
                        return;
                    }
                    
                    var $row = $(this).closest('tr');
                    var $allRows = $('#rules-list tr:not(#no-rules-row)');
                    var ruleIndex = $allRows.index($row);
                    
                    if (ruleIndex < 0) {
                        alert('Error: Could not determine rule index');
                        return;
                    }
                    
                    // Show loading state
                    var $deleteBtn = $(this);
                    $deleteBtn.prop('disabled', true).text('Deleting...');
                    
                    $.ajax({
                        url: wcccf_discount_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wcccf_delete_discount_rule',
                            nonce: wcccf_discount_vars.nonce,
                            rule_index: ruleIndex
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(300, function() {
                                    $(this).remove();
                                    
                                    if ($('#rules-list tr:not(#no-rules-row)').length === 0) {
                                        $('#no-rules-row').show();
                                    }
                                });
                            } else {
                                alert('Error: ' + (response.data || 'Failed to delete rule'));
                                $deleteBtn.prop('disabled', false).text('Delete');
                            }
                        },
                        error: function() {
                            alert('Error: Failed to delete rule. Please try again.');
                            $deleteBtn.prop('disabled', false).text('Delete');
                        }
                    });
                });
            });
        </script>
        <?php
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

    public function get_all_product_tags_for_select() {
        $tags = [];
        $product_tags = get_terms([
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
        ]);

        if (!empty($product_tags) && !is_wp_error($product_tags)) {
            foreach ($product_tags as $tag) {
                $tags[$tag->term_id] = $tag->name;
            }
        }
        return $tags;
    }

    public function get_all_user_roles_for_select() {
        global $wp_roles;
        $roles = [];
        if (isset($wp_roles->roles) && is_array($wp_roles->roles)) {
            foreach ($wp_roles->roles as $role_key => $role_data) {
                $roles[$role_key] = $role_data['name'];
            }
        }
        return $roles;
    }
 
     public function apply_fixed_discount_for_multiple_products($cart) {
         if (is_admin() && !defined('DOING_AJAX')) return;
 
         if (!get_option('wcccf_auto_discount_enabled', 1)) {
             return;
         }
 
         $discount_rules = get_option('wcccf_discount_rules', []);
         if (empty($discount_rules) || !is_array($discount_rules)) {
             return;
         }
 
         foreach ($discount_rules as $rule) {
             $discount_details = $this->_calculate_discount_for_rule($rule, $cart);
             if ($discount_details['amount'] > 0) {
                 $cart->add_fee($discount_details['label'], -$discount_details['amount']);
             }
         }
     }
 
      private function _calculate_discount_for_rule($rule, $cart) {
          $discount_amount = 0;
          $discount_label = __('Discount', 'wc-manager');
 
          if (!isset($rule['type'])) {
              return ['amount' => 0, 'label' => $discount_label];
          }
 
          $cart_items = $cart->get_cart();
 
          switch ($rule['type']) {
              case 'fixed_product':
                  if (isset($rule['product_id']) && isset($rule['amount'])) {
                      $product_id = intval($rule['product_id']);
                      $amount = floatval($rule['amount']);
                      $quantity = 0;
                      foreach ($cart_items as $cart_item) {
                          if ($cart_item['product_id'] == $product_id) {
                              $quantity += $cart_item['quantity'];
                          }
                      }
                      if ($quantity > 0) { // Apply if product is in cart
                          $product = wc_get_product($product_id);
                          $discount_label = sprintf(__('Discount for %s', 'wc-manager'), $product ? $product->get_name() : '');
                          $discount_amount = $amount;
                      }
                  }
                  break;
 
              case 'percentage_product':
                  if (isset($rule['product_id']) && isset($rule['percentage'])) {
                      $product_id = intval($rule['product_id']);
                      $percentage = floatval($rule['percentage']);
                      $product_total = 0;
                      foreach ($cart_items as $cart_item) {
                          if ($cart_item['product_id'] == $product_id) {
                              $product_total += $cart_item['line_total'];
                          }
                      }
                      if ($product_total > 0) {
                          $product = wc_get_product($product_id);
                          $discount_label = sprintf(__('Percentage Discount for %s', 'wc-manager'), $product ? $product->get_name() : '');
                          $discount_amount = ($product_total * $percentage) / 100;
                      }
                  }
                  break;
 
              case 'tiered_product':
                  if (isset($rule['product_id']) && isset($rule['amount']) && isset($rule['min_qty'])) {
                      $product_id = intval($rule['product_id']);
                      $amount = floatval($rule['amount']);
                      $min_qty = intval($rule['min_qty']);
                      $max_qty = isset($rule['max_qty']) ? intval($rule['max_qty']) : PHP_INT_MAX;
 
                      $quantity = 0;
                      foreach ($cart_items as $cart_item) {
                          if ($cart_item['product_id'] == $product_id) {
                              $quantity += $cart_item['quantity'];
                          }
                      }
 
                      if ($quantity >= $min_qty && $quantity <= $max_qty) {
                          $product = wc_get_product($product_id);
                          $discount_label = sprintf(__('Tiered Discount for %s', 'wc-manager'), $product ? $product->get_name() : '');
                          $discount_amount = $amount;
                      }
                  }
                  break;
 
              case 'category_discount':
                  if (isset($rule['categories']) && !empty($rule['categories']) && (isset($rule['amount']) || isset($rule['percentage']))) {
                      $target_category_ids = array_map('intval', (array)$rule['categories']);
                      $category_total = 0;
                      foreach ($cart_items as $cart_item) {
                          $product_categories = wc_get_product_term_ids($cart_item['product_id'], 'product_cat');
                          if (array_intersect($target_category_ids, $product_categories)) {
                               $category_total += $cart_item['line_total'];
                          }
                      }
 
                      if ($category_total > 0) {
                          $discount_label = __('Category Discount', 'wc-manager');
                          if (isset($rule['amount'])) {
                               $discount_amount = floatval($rule['amount']);
                          } elseif (isset($rule['percentage'])) {
                               $percentage = floatval($rule['percentage']);
                               $discount_amount = ($category_total * $percentage) / 100;
                          }
                      }
                  }
                  break;
 
              case 'tag_discount':
                  if (isset($rule['tags']) && !empty($rule['tags']) && (isset($rule['amount']) || isset($rule['percentage']))) {
                      $target_tag_ids = array_map('intval', (array)$rule['tags']);
                      $tag_total = 0;
                      foreach ($cart_items as $cart_item) {
                          $product_tags = wc_get_product_term_ids($cart_item['product_id'], 'product_tag');
                          if (array_intersect($target_tag_ids, $product_tags)) {
                               $tag_total += $cart_item['line_total'];
                          }
                      }
 
                      if ($tag_total > 0) {
                          $discount_label = __('Tag Discount', 'wc-manager');
                          if (isset($rule['amount'])) {
                               $discount_amount = floatval($rule['amount']);
                          } elseif (isset($rule['percentage'])) {
                               $percentage = floatval($rule['percentage']);
                               $discount_amount = ($tag_total * $percentage) / 100;
                          }
                      }
                  }
                  break;
              case 'bogo':
                  if (isset($rule['buy_product_id']) && isset($rule['buy_qty']) && isset($rule['get_product_id']) && isset($rule['get_qty'])) {
                      $buy_product_id = intval($rule['buy_product_id']);
                      $buy_qty = intval($rule['buy_qty']);
                      $get_product_id = intval($rule['get_product_id']);
                      $get_qty = intval($rule['get_qty']);
 
                      $buy_product_in_cart_qty = 0;
                      $get_product_in_cart_qty = 0;
                      $get_product_price = 0;
 
                      foreach ($cart_items as $cart_item) {
                          if ($cart_item['product_id'] == $buy_product_id) {
                              $buy_product_in_cart_qty += $cart_item['quantity'];
                          }
                          if ($cart_item['product_id'] == $get_product_id) {
                              $get_product_in_cart_qty += $cart_item['quantity'];
                              $get_product_price = $cart_item['data']->get_price();
                          }
                      }
 
                      if ($buy_product_in_cart_qty >= $buy_qty && $get_product_in_cart_qty >= $get_qty && $get_product_price > 0) {
                          $discount_label = sprintf(__('BOGO: Buy %s Get %s Free', 'wc-manager'), wc_get_product($buy_product_id)->get_name(), wc_get_product($get_product_id)->get_name());
                          $discount_amount = $get_product_price * $get_qty;
                      }
                  }
                  break;
              case 'role_based_free_shipping':
                  if (isset($rule['roles']) && !empty($rule['roles'])) {
                       if (!is_user_logged_in()) break;
                       $user = wp_get_current_user();
                       $user_roles = (array) $user->roles;
                       
                       if (array_intersect($user_roles, $rule['roles'])) {
                           // User matches role - free shipping applies automatically
                           $cart_total = $cart->get_subtotal();
                           
                           // Check if additional discount amount/percentage is set
                           if (isset($rule['amount']) && floatval($rule['amount']) > 0) {
                              $discount_amount = floatval($rule['amount']);
                              $discount_label = __('Role Discount + Free Shipping', 'wc-manager');
                           } elseif (isset($rule['percentage']) && floatval($rule['percentage']) > 0) {
                              $percentage = floatval($rule['percentage']);
                              $discount_amount = ($cart_total * $percentage) / 100;
                              $discount_label = __('Role Discount + Free Shipping', 'wc-manager');
                           }
                           // Note: Even if $discount_amount = 0, free shipping still applies via the separate hook
                       }
                  }
                  break;
          }
 
          return ['amount' => $discount_amount, 'label' => $discount_label];
      }
      public function apply_role_based_free_shipping($rates, $package) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $rates;
        }

        if (!get_option('wcccf_auto_discount_enabled', 1)) {
            return $rates;
        }

        if (!is_user_logged_in()) {
            return $rates;
        }

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $discount_rules = get_option('wcccf_discount_rules', []);

        if (empty($discount_rules) || !is_array($discount_rules)) {
            return $rates;
        }

        foreach ($discount_rules as $rule) {
            if (isset($rule['type']) && $rule['type'] === 'role_based_free_shipping' && isset($rule['roles']) && !empty($rule['roles'])) {
                // Check if user has any of the allowed roles
                if (array_intersect($user_roles, $rule['roles'])) {
                    // Start strict Free Shipping logic:
                    // 1. If we are here, the user qualifies for free shipping.
                    // 2. We should set ALL shipping rates to 0.
                    // 3. Or, maybe we just want to offer a free shipping option?
                    // User request: "role based discount as well that will be free shipping"
                    // Usually this means "Free Shipping" becomes available or existing methods become free.
                    // Let's make all available methods free to be generous and simple.
                    
                    foreach ($rates as $rate_id => $rate) {
                        $rates[$rate_id]->cost = 0;
                        // $rates[$rate_id]->label .= ' (Free)'; // Optional: Modify label
                    }
                    break; // Stop after first matching rule
                }
            }
        }

        return $rates;
    }
 }
