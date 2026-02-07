<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCCCF_Quantity_Selector {
    public function __construct() {
        if (get_option('wcccf_quantity_selector_enabled')) {
            add_filter('woocommerce_checkout_cart_item_quantity', [$this, 'enable_quantity_selector_on_checkout'], 10, 3);
            add_action('wp_footer', [$this, 'checkout_quantity_update_script']);
            add_action('wp_ajax_update_checkout_qty', [$this, 'ajax_update_checkout_qty']);
            add_action('wp_ajax_nopriv_update_checkout_qty', [$this, 'ajax_update_checkout_qty']);
        }
    }

    public function enable_quantity_selector_on_checkout($quantity_html, $cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        $quantity = $cart_item['quantity'];

        if (!$product->is_sold_individually()) {
            $max_value = $product->get_max_purchase_quantity();
            $min_value = 1;
            
            // Custom HTML with plus/minus buttons
            $quantity_html = sprintf(
                '<div class="wcccf-quantity-wrapper">
                    <button type="button" class="wcccf-qty-btn wcccf-qty-minus" data-cart-key="%s">−</button>
                    <input type="number" 
                           name="checkout_cart_qty[%s]" 
                           class="input-text qty text wcccf-qty-input" 
                           value="%s" 
                           min="%s" 
                           max="%s" 
                           step="1" 
                           data-cart-key="%s"
                           inputmode="numeric" />
                    <button type="button" class="wcccf-qty-btn wcccf-qty-plus" data-cart-key="%s">+</button>
                </div>',
                esc_attr($cart_item_key),
                esc_attr($cart_item_key),
                esc_attr($quantity),
                esc_attr($min_value),
                esc_attr($max_value),
                esc_attr($cart_item_key),
                esc_attr($cart_item_key)
            );
        }

        return $quantity_html;
    }

    public function checkout_quantity_update_script() {
        if (!is_checkout()) return;
        $nonce = wp_create_nonce('wcccf_qty_update_nonce');
        ?>
        <style type="text/css">
            .wcccf-quantity-wrapper {
                display: inline-flex;
                align-items: center;
                gap: 0;
                border: 1px solid #ddd;
                border-radius: 4px;
                overflow: hidden;
                background: #fff;
            }
            
            .wcccf-qty-btn {
                background: #f7f7f7;
                border: none;
                color: #333;
                width: 32px;
                height: 36px;
                font-size: 18px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0;
                line-height: 1;
                user-select: none;
            }
            
            .wcccf-qty-btn:hover:not(:disabled) {
                background: #2271b1;
                color: #fff;
            }
            
            .wcccf-qty-btn:active:not(:disabled) {
                background: #135e96;
                transform: scale(0.95);
            }
            
            .wcccf-qty-btn:disabled {
                opacity: 0.4;
                cursor: not-allowed;
            }
            
            .wcccf-qty-input {
                border: none !important;
                width: 50px !important;
                height: 36px !important;
                text-align: center !important;
                font-size: 14px !important;
                font-weight: 600 !important;
                padding: 0 5px !important;
                margin: 0 !important;
                -moz-appearance: textfield;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            
            .wcccf-qty-input::-webkit-outer-spin-button,
            .wcccf-qty-input::-webkit-inner-spin-button {
                -webkit-appearance: none;
                margin: 0;
            }
            
            .wcccf-qty-input:focus {
                outline: none !important;
                box-shadow: none !important;
            }
            
            /* Responsive adjustments */
            @media (max-width: 768px) {
                .wcccf-qty-btn {
                    width: 36px;
                    height: 40px;
                    font-size: 20px;
                }
                
                .wcccf-qty-input {
                    width: 60px !important;
                    height: 40px !important;
                    font-size: 16px !important;
                }
            }
        </style>
        
        <script type="text/javascript">
        jQuery(function($){
            // Handle plus/minus button clicks
            $(document).on('click', '.wcccf-qty-btn', function(e){
                e.preventDefault();
                
                var $button = $(this);
                var $input = $button.siblings('.wcccf-qty-input');
                var currentVal = parseInt($input.val()) || 1;
                var minVal = parseInt($input.attr('min')) || 1;
                var maxVal = parseInt($input.attr('max')) || 999999;
                var cartKey = $button.data('cart-key');
                
                var newVal = currentVal;
                
                if ($button.hasClass('wcccf-qty-plus')) {
                    newVal = currentVal + 1;
                    if (maxVal && newVal > maxVal) {
                        newVal = maxVal;
                    }
                } else if ($button.hasClass('wcccf-qty-minus')) {
                    newVal = currentVal - 1;
                    if (newVal < minVal) {
                        newVal = minVal;
                    }
                }
                
                if (newVal !== currentVal) {
                    $input.val(newVal);
                    $input.trigger('change');
                }
                
                // Update button states
                updateButtonStates($input);
            });
            
            // Handle direct input changes
            $(document).on('change', '.wcccf-qty-input', function(){
                var $input = $(this);
                var currentVal = parseInt($input.val()) || 1;
                var minVal = parseInt($input.attr('min')) || 1;
                var maxVal = parseInt($input.attr('max')) || 999999;
                
                // Validate input
                if (currentVal < minVal) {
                    $input.val(minVal);
                    currentVal = minVal;
                }
                if (maxVal && currentVal > maxVal) {
                    $input.val(maxVal);
                    currentVal = maxVal;
                }
                
                // Update button states
                updateButtonStates($input);
                
                // Send AJAX request to update cart
                var cartKey = $input.data('cart-key');
                var data = {
                    action: 'update_checkout_qty',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    cart_item_key: cartKey,
                    quantity: currentVal
                };
                
                $.post(wc_checkout_params.ajax_url, data, function() {
                    $('body').trigger('update_checkout');
                });
            });
            
            // Function to update button disabled states
            function updateButtonStates($input) {
                var currentVal = parseInt($input.val()) || 1;
                var minVal = parseInt($input.attr('min')) || 1;
                var maxVal = parseInt($input.attr('max')) || 999999;
                var $wrapper = $input.closest('.wcccf-quantity-wrapper');
                
                // Update minus button
                if (currentVal <= minVal) {
                    $wrapper.find('.wcccf-qty-minus').prop('disabled', true);
                } else {
                    $wrapper.find('.wcccf-qty-minus').prop('disabled', false);
                }
                
                // Update plus button
                if (maxVal && currentVal >= maxVal) {
                    $wrapper.find('.wcccf-qty-plus').prop('disabled', true);
                } else {
                    $wrapper.find('.wcccf-qty-plus').prop('disabled', false);
                }
            }
            
            // Initialize button states on page load
            $('.wcccf-qty-input').each(function(){
                updateButtonStates($(this));
            });
            
            // Re-initialize after checkout updates
            $(document.body).on('updated_checkout', function(){
                $('.wcccf-qty-input').each(function(){
                    updateButtonStates($(this));
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_update_checkout_qty() {
        // Verify nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcccf_qty_update_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
        $quantity = intval($_POST['quantity']);

        if ($quantity && $cart_item_key) {
            WC()->cart->set_quantity($cart_item_key, $quantity, true);
            wp_send_json_success();
        }

        wp_send_json_error('Invalid data');
    }
}