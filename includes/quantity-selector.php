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
            $quantity_html = woocommerce_quantity_input(array(
                'input_name'  => "checkout_cart_qty[{$cart_item_key}]",
                'input_value' => $quantity,
                'max_value'   => $product->get_max_purchase_quantity(),
                'min_value'   => 1,
                'product_name'=> $product->get_name(),
            ), $product, false);
        }

        return $quantity_html;
    }

    public function checkout_quantity_update_script() {
        if (!is_checkout()) return;
        $nonce = wp_create_nonce('wcccf_qty_update_nonce');
        ?>
        <script type="text/javascript">
        jQuery(function($){
            $('form.checkout').on('change', 'input.qty', function(){
                let data = {
                    action: 'update_checkout_qty',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    cart_item_key: $(this).attr('name').match(/\[(.*?)\]/)[1],
                    quantity: $(this).val()
                };
                $.post(wc_checkout_params.ajax_url, data, function() {
                    $('body').trigger('update_checkout');
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