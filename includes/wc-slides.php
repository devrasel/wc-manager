<?php
/**
 * WC Slides - WooCommerce Product Slider
 * 
 * Provides shortcode-based product sliders with extensive customization options
 */

if (!defined('ABSPATH')) exit;

class WC_Slides_Manager {
    
    public function __construct() {
        // Register shortcode
        add_shortcode('re_slides', [$this, 'render_slider']);
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_wc_slides_save_preset', [$this, 'ajax_save_preset']);
        add_action('wp_ajax_wc_slides_delete_preset', [$this, 'ajax_delete_preset']);
        add_action('wp_ajax_wc_slides_get_preset', [$this, 'ajax_get_preset']);
    }
    
    /**
     * Sanitize color value to prevent CSS injection
     * 
     * @param string $color Color value to sanitize
     * @param string $default Default color if invalid
     * @return string Sanitized color value
     */
    private function sanitize_color($color, $default = '#2271b1') {
        // Allow hex colors (3 or 6 digits)
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            return $color;
        }
        // Allow rgb/rgba colors
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)$/', $color)) {
            return $color;
        }
        // Return default if invalid
        return $default;
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_frontend_scripts() {
        // Enqueue Swiper CSS
        wp_enqueue_style(
            'swiper-css',
            'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css',
            [],
            '11.0.0'
        );
        
        // Enqueue custom CSS
        wp_enqueue_style(
            'wc-slides-css',
            plugin_dir_url(dirname(__FILE__)) . 'assets/wc-slides.css',
            ['swiper-css'],
            '1.0.5'
        );
        
        // Enqueue Swiper JS
        wp_enqueue_script(
            'swiper-js',
            'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js',
            [],
            '11.0.0',
            true
        );
        
        // Enqueue custom JS
        wp_enqueue_script(
            'wc-slides-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/wc-slides.js',
            ['jquery', 'swiper-js'],
            '1.0.6',
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('wc-slides-js', 'wcSlidesData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_slides_nonce'),
            'addToCartText' => __('Add to Cart', 'wc-manager'),
            'addedToCartText' => __('Added!', 'wc-manager'),
        ]);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'wc-manager_page_wc-slides') {
            return;
        }
        
        // Enqueue jQuery UI Dialog
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        // Enqueue color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        wp_enqueue_style(
            'wc-slides-admin-css',
            plugin_dir_url(dirname(__FILE__)) . 'assets/wc-slides-admin.css',
            [],
            '1.0.4'
        );
        
        wp_enqueue_script(
            'wc-slides-admin-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/wc-slides-admin.js',
            ['jquery', 'jquery-ui-dialog', 'wp-color-picker'],
            '1.0.3',
            true
        );
        
        wp_localize_script('wc-slides-admin-js', 'wcSlidesAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_slides_admin_nonce'),
        ]);
    }
    
    /**
     * Render slider shortcode
     */
    public function render_slider($atts) {
        // Store original user-provided attributes before defaults are applied
        $user_atts = $atts;
        
        $atts = shortcode_atts([
            'type' => '',
            'product' => '',
            'variation' => '',
            'cat' => '',
            'tag' => '',
            'number' => 10,
            'add2cart' => 'yes',
            'arrow' => 'yes',
            'dots' => 'yes',
            'autoplay' => 0,
            'preset' => '',
            'mobile_columns' => 1,
            'tablet_columns' => 2,
            'desktop_columns' => 4,
            'arrow_position' => 'center',
            'primary_color' => '#2271b1',
            'arrow_color' => '#2271b1',
            'dot_color' => '#2271b1',
        ], $atts, 're_slides');
        
        // Load preset if specified
        if (!empty($atts['preset'])) {
            $preset = $this->get_preset($atts['preset']);
            if ($preset) {
                // Start with defaults
                $defaults = [
                    'type' => '',
                    'product' => '',
                    'variation' => '',
                    'cat' => '',
                    'tag' => '',
                    'number' => 10,
                    'add2cart' => 'yes',
                    'arrow' => 'yes',
                    'dots' => 'yes',
                    'autoplay' => 0,
                    'mobile_columns' => 1,
                    'tablet_columns' => 2,
                    'desktop_columns' => 4,
                    'arrow_position' => 'center',
                    'primary_color' => '#2271b1',
                    'arrow_color' => '#2271b1',
                    'dot_color' => '#2271b1',
                ];
                
                // Merge: defaults < preset values < user-provided shortcode attributes
                $atts = array_merge($defaults, $this->flatten_preset($preset), $user_atts);
            }
        }
        
        // Normalize boolean-like string values to ensure consistency
        $atts['arrow'] = strtolower($atts['arrow']) === 'no' ? 'no' : 'yes';
        $atts['dots'] = strtolower($atts['dots']) === 'no' ? 'no' : 'yes';
        $atts['add2cart'] = strtolower($atts['add2cart']) === 'no' ? 'no' : 'yes';
        
        // Get products
        $products = $this->get_products($atts);
        
        if (empty($products)) {
            return '<p>' . __('No products found.', 'wc-manager') . '</p>';
        }
        
        // Generate unique ID for this slider
        static $slider_count = 0;
        $slider_count++;
        $slider_id = 'wc-slides-' . $slider_count;
        
        // Build slider HTML
        ob_start();
        ?>
        <div class="wc-slides-wrapper wc-slides-loading" id="<?php echo esc_attr($slider_id); ?>" 
             data-config='<?php echo esc_attr(json_encode($this->get_slider_config($atts))); ?>'>
            
            <!-- Loading Spinner -->
            <div class="wc-slides-loader">
                <div class="wc-slides-spinner"></div>
            </div>
            
            <div class="swiper wc-slides-swiper">
                <div class="swiper-wrapper">
                    <?php foreach ($products as $product) : ?>
                        <?php $this->render_product_slide($product, $atts); ?>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($atts['arrow'] === 'yes') : ?>
                    <div class="swiper-button-prev wc-slides-arrow wc-slides-arrow-<?php echo esc_attr($atts['arrow_position']); ?>"></div>
                    <div class="swiper-button-next wc-slides-arrow wc-slides-arrow-<?php echo esc_attr($atts['arrow_position']); ?>"></div>
                <?php endif; ?>
                
                <?php if ($atts['dots'] === 'yes') : ?>
                    <div class="swiper-pagination"></div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            #<?php echo esc_attr($slider_id); ?> .swiper-button-prev,
            #<?php echo esc_attr($slider_id); ?> .swiper-button-next {
                color: <?php echo esc_attr($this->sanitize_color($atts['arrow_color'])); ?>;
            }
            #<?php echo esc_attr($slider_id); ?> .swiper-pagination-bullet-active {
                background: <?php echo esc_attr($this->sanitize_color($atts['dot_color'])); ?>;
            }
            #<?php echo esc_attr($slider_id); ?> .wc-slides-add-to-cart {
                background-color: <?php echo esc_attr($this->sanitize_color($atts['primary_color'])); ?>;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render individual product slide
     */
    private function render_product_slide($product, $atts) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        ?>
        <div class="swiper-slide">
            <div class="wc-slides-product" data-product-id="<?php echo esc_attr($product->get_id()); ?>">
                <a href="<?php echo esc_url($product->get_permalink()); ?>" class="wc-slides-product-link">
                    <div class="wc-slides-product-image">
                        <?php echo $product->get_image('medium'); ?>
                    </div>
                    <h3 class="wc-slides-product-title"><?php echo esc_html($product->get_name()); ?></h3>
                    <div class="wc-slides-product-price">
                        <?php echo $product->get_price_html(); ?>
                    </div>
                </a>
                
                <?php if ($atts['add2cart'] === 'yes') : ?>
                    <div class="wc-slides-product-actions">
                        <?php if ($product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock()) : ?>
                            <button class="wc-slides-add-to-cart" 
                                    data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                                    data-quantity="1">
                                <?php echo __('Add to Cart', 'wc-manager'); ?>
                            </button>
                        <?php elseif ($product->is_type('variable')) : ?>
                            <a href="<?php echo esc_url($product->get_permalink()); ?>" class="wc-slides-select-options">
                                <?php echo __('Select Options', 'wc-manager'); ?>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url($product->get_permalink()); ?>" class="wc-slides-view-product">
                                <?php echo __('View Product', 'wc-manager'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get products based on filters
     */
    private function get_products($atts) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => intval($atts['number']),
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        
        // Handle product type (latest or popular)
        if (!empty($atts['type'])) {
            $type = strtolower($atts['type']);
            if ($type === 'latest') {
                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
            } elseif ($type === 'popular') {
                $args['meta_key'] = 'total_sales';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = 'DESC';
            }
        }
        
        // Filter by specific product IDs
        if (!empty($atts['product'])) {
            $product_ids = array_map('intval', explode(',', $atts['product']));
            $args['post__in'] = $product_ids;
        }
        
        // Tax query for categories and tags
        $tax_query = [];
        
        if (!empty($atts['cat'])) {
            $cat_ids = array_map('intval', explode(',', $atts['cat']));
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $cat_ids,
            ];
        }
        
        if (!empty($atts['tag'])) {
            $tags = array_map('trim', explode(',', $atts['tag']));
            $tax_query[] = [
                'taxonomy' => 'product_tag',
                'field' => 'slug',
                'terms' => $tags,
            ];
        }
        
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }
        
        // Handle variations
        if (!empty($atts['variation'])) {
            $variation_ids = array_map('intval', explode(',', $atts['variation']));
            $args['post_type'] = ['product', 'product_variation'];
            if (isset($args['post__in'])) {
                $args['post__in'] = array_merge($args['post__in'], $variation_ids);
            } else {
                $args['post__in'] = $variation_ids;
            }
        }
        
        $query = new WP_Query($args);
        $products = [];
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                if ($product) {
                    $products[] = $product;
                }
            }
            wp_reset_postdata();
        }
        
        return $products;
    }
    
    /**
     * Get slider configuration for JavaScript
     */
    private function get_slider_config($atts) {
        return [
            'slidesPerView' => 1,
            'spaceBetween' => 20,
            'loop' => true,
            'autoplay' => intval($atts['autoplay']) > 0 ? [
                'delay' => intval($atts['autoplay']),
                'disableOnInteraction' => false,
                'pauseOnMouseEnter' => true,
            ] : false,
            'navigation' => $atts['arrow'] === 'yes' ? [
                'nextEl' => '.swiper-button-next',
                'prevEl' => '.swiper-button-prev',
            ] : false,
            'pagination' => $atts['dots'] === 'yes' ? [
                'el' => '.swiper-pagination',
                'clickable' => true,
            ] : false,
            'breakpoints' => [
                320 => [
                    'slidesPerView' => intval($atts['mobile_columns']),
                    'spaceBetween' => 10,
                ],
                768 => [
                    'slidesPerView' => intval($atts['tablet_columns']),
                    'spaceBetween' => 15,
                ],
                1024 => [
                    'slidesPerView' => intval($atts['desktop_columns']),
                    'spaceBetween' => 20,
                ],
            ],
        ];
    }
    
    /**
     * Get preset by ID
     */
    private function get_preset($preset_id) {
        $presets = get_option('wc_slides_presets', []);
        return isset($presets[$preset_id]) ? $presets[$preset_id] : null;
    }
    
    /**
     * Flatten preset structure for shortcode attributes
     */
    private function flatten_preset($preset) {
        $flat = [];
        
        if (isset($preset['filters'])) {
            $flat['number'] = $preset['filters']['number'] ?? 10;
            if (!empty($preset['filters']['type'])) {
                $flat['type'] = $preset['filters']['type'];
            }
            if (!empty($preset['filters']['products'])) {
                $flat['product'] = implode(',', $preset['filters']['products']);
            }
            if (!empty($preset['filters']['categories'])) {
                $flat['cat'] = implode(',', $preset['filters']['categories']);
            }
            if (!empty($preset['filters']['tags'])) {
                $flat['tag'] = implode(',', $preset['filters']['tags']);
            }
        }
        
        if (isset($preset['layout'])) {
            $flat['mobile_columns'] = $preset['layout']['mobile_columns'] ?? 1;
            $flat['tablet_columns'] = $preset['layout']['tablet_columns'] ?? 2;
            $flat['desktop_columns'] = $preset['layout']['desktop_columns'] ?? 4;
        }
        
        if (isset($preset['navigation'])) {
            $flat['arrow'] = ($preset['navigation']['arrows'] ?? true) ? 'yes' : 'no';
            $flat['arrow_position'] = $preset['navigation']['arrow_position'] ?? 'center';
            $flat['dots'] = ($preset['navigation']['dots'] ?? true) ? 'yes' : 'no';
        }
        
        if (isset($preset['behavior'])) {
            $flat['autoplay'] = $preset['behavior']['autoplay_delay'] ?? 0;
        }
        
        if (isset($preset['style'])) {
            $flat['primary_color'] = $preset['style']['primary_color'] ?? '#2271b1';
            $flat['arrow_color'] = $preset['style']['arrow_color'] ?? '#2271b1';
            $flat['dot_color'] = $preset['style']['dot_color'] ?? '#2271b1';
        }
        
        if (isset($preset['display'])) {
            $flat['add2cart'] = ($preset['display']['add_to_cart'] ?? true) ? 'yes' : 'no';
        }
        
        return $flat;
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page_html() {
        $presets = get_option('wc_slides_presets', []);
        $nonce = wp_create_nonce('wc_slides_admin_nonce');
        ?>
        <div class="wrap wc-slides-admin">
            <h1><?php echo __('WC Slides - Product Slider', 'wc-manager'); ?></h1>
            
            <div class="wc-slides-admin-container">
                <!-- Presets List -->
                <div class="wc-slides-section">
                    <h2><?php echo __('Saved Presets', 'wc-manager'); ?></h2>
                    <button type="button" class="button button-primary" id="wc-slides-add-preset">
                        <?php echo __('Add New Preset', 'wc-manager'); ?>
                    </button>
                    
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th><?php echo __('Preset Name', 'wc-manager'); ?></th>
                                <th><?php echo __('Shortcode', 'wc-manager'); ?></th>
                                <th><?php echo __('Actions', 'wc-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="wc-slides-presets-list">
                            <?php if (empty($presets)) : ?>
                                <tr id="no-presets-row">
                                    <td colspan="3"><em><?php echo __('No presets created yet.', 'wc-manager'); ?></em></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($presets as $preset_id => $preset) : ?>
                                    <tr data-preset-id="<?php echo esc_attr($preset_id); ?>">
                                        <td><strong><?php echo esc_html($preset['name']); ?></strong></td>
                                        <td>
                                            <code>[re_slides preset="<?php echo esc_attr($preset_id); ?>"]</code>
                                            <button type="button" class="button button-small wc-slides-copy-shortcode" 
                                                    data-shortcode='[re_slides preset="<?php echo esc_attr($preset_id); ?>"]'>
                                                <?php echo __('Copy', 'wc-manager'); ?>
                                            </button>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small wc-slides-edit-preset" 
                                                    data-preset-id="<?php echo esc_attr($preset_id); ?>">
                                                <?php echo __('Edit', 'wc-manager'); ?>
                                            </button>
                                            <button type="button" class="button button-small wc-slides-delete-preset" 
                                                    data-preset-id="<?php echo esc_attr($preset_id); ?>">
                                                <?php echo __('Delete', 'wc-manager'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Shortcode Generator -->
                <div class="wc-slides-section">
                    <h2><?php echo __('Shortcode Generator', 'wc-manager'); ?></h2>
                    <p><?php echo __('Use this tool to generate custom shortcodes without saving a preset.', 'wc-manager'); ?></p>
                    
                    <div class="wc-slides-generator">
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Product Type', 'wc-manager'); ?></label>
                            <select id="gen-type">
                                <option value=""><?php echo __('None (Manual Selection)', 'wc-manager'); ?></option>
                                <option value="latest"><?php echo __('Latest Products', 'wc-manager'); ?></option>
                                <option value="popular"><?php echo __('Popular Products', 'wc-manager'); ?></option>
                            </select>
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Product IDs (comma-separated)', 'wc-manager'); ?></label>
                            <input type="text" id="gen-product" placeholder="68,69,70">
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Category IDs (comma-separated)', 'wc-manager'); ?></label>
                            <input type="text" id="gen-cat" placeholder="1205">
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Tags (comma-separated)', 'wc-manager'); ?></label>
                            <input type="text" id="gen-tag" placeholder="new,featured">
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Number of Products', 'wc-manager'); ?></label>
                            <input type="number" id="gen-number" value="10" min="1">
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Add to Cart Button', 'wc-manager'); ?></label>
                            <select id="gen-add2cart">
                                <option value="yes"><?php echo __('Yes', 'wc-manager'); ?></option>
                                <option value="no"><?php echo __('No', 'wc-manager'); ?></option>
                            </select>
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Navigation Arrows', 'wc-manager'); ?></label>
                            <select id="gen-arrow">
                                <option value="yes"><?php echo __('Yes', 'wc-manager'); ?></option>
                                <option value="no"><?php echo __('No', 'wc-manager'); ?></option>
                            </select>
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Pagination Dots', 'wc-manager'); ?></label>
                            <select id="gen-dots">
                                <option value="yes"><?php echo __('Yes', 'wc-manager'); ?></option>
                                <option value="no"><?php echo __('No', 'wc-manager'); ?></option>
                            </select>
                        </div>
                        
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Autoplay (ms, 0 = off)', 'wc-manager'); ?></label>
                            <input type="number" id="gen-autoplay" value="0" min="0" step="1000">
                        </div>
                        
                        <h4 style="margin-top: 20px; margin-bottom: 10px;"><?php echo __('Responsive Columns', 'wc-manager'); ?></h4>
                        
                        <div class="wc-slides-form-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                            <div class="wc-slides-form-row">
                                <label><?php echo __('Mobile Columns', 'wc-manager'); ?></label>
                                <input type="number" id="gen-mobile-columns" value="1" min="1" max="3">
                            </div>
                            
                            <div class="wc-slides-form-row">
                                <label><?php echo __('Tablet Columns', 'wc-manager'); ?></label>
                                <input type="number" id="gen-tablet-columns" value="2" min="1" max="4">
                            </div>
                            
                            <div class="wc-slides-form-row">
                                <label><?php echo __('Desktop Columns', 'wc-manager'); ?></label>
                                <input type="number" id="gen-desktop-columns" value="4" min="1" max="6">
                            </div>
                        </div>
                        
                        <button type="button" class="button button-primary" id="wc-slides-generate-shortcode">
                            <?php echo __('Generate Shortcode', 'wc-manager'); ?>
                        </button>
                        
                        <div class="wc-slides-generated-shortcode" style="display: none; margin-top: 15px;">
                            <label><?php echo __('Generated Shortcode:', 'wc-manager'); ?></label>
                            <input type="text" id="generated-shortcode" readonly>
                            <button type="button" class="button" id="copy-generated-shortcode">
                                <?php echo __('Copy', 'wc-manager'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Documentation -->
                <div class="wc-slides-section">
                    <h2><?php echo __('Documentation', 'wc-manager'); ?></h2>
                    <h3><?php echo __('Shortcode Attributes', 'wc-manager'); ?></h3>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th><?php echo __('Attribute', 'wc-manager'); ?></th>
                                <th><?php echo __('Description', 'wc-manager'); ?></th>
                                <th><?php echo __('Example', 'wc-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>type</code></td>
                                <td><?php echo __('Product type filter (latest/popular)', 'wc-manager'); ?></td>
                                <td><code>type="latest"</code> or <code>type="popular"</code></td>
                            </tr>
                            <tr>
                                <td><code>product</code></td>
                                <td><?php echo __('Specific product IDs (comma-separated)', 'wc-manager'); ?></td>
                                <td><code>product="68,69,70"</code></td>
                            </tr>
                            <tr>
                                <td><code>variation</code></td>
                                <td><?php echo __('Specific variation IDs (comma-separated)', 'wc-manager'); ?></td>
                                <td><code>variation="451"</code></td>
                            </tr>
                            <tr>
                                <td><code>cat</code></td>
                                <td><?php echo __('Category IDs (comma-separated)', 'wc-manager'); ?></td>
                                <td><code>cat="1205"</code></td>
                            </tr>
                            <tr>
                                <td><code>tag</code></td>
                                <td><?php echo __('Product tags (comma-separated)', 'wc-manager'); ?></td>
                                <td><code>tag="new,featured"</code></td>
                            </tr>
                            <tr>
                                <td><code>number</code></td>
                                <td><?php echo __('Number of products to display', 'wc-manager'); ?></td>
                                <td><code>number=20</code></td>
                            </tr>
                            <tr>
                                <td><code>add2cart</code></td>
                                <td><?php echo __('Show add to cart button (yes/no)', 'wc-manager'); ?></td>
                                <td><code>add2cart=yes</code></td>
                            </tr>
                            <tr>
                                <td><code>arrow</code></td>
                                <td><?php echo __('Show navigation arrows (yes/no)', 'wc-manager'); ?></td>
                                <td><code>arrow=yes</code></td>
                            </tr>
                            <tr>
                                <td><code>dots</code></td>
                                <td><?php echo __('Show pagination dots (yes/no)', 'wc-manager'); ?></td>
                                <td><code>dots=yes</code></td>
                            </tr>
                            <tr>
                                <td><code>autoplay</code></td>
                                <td><?php echo __('Autoplay delay in milliseconds (0 = off)', 'wc-manager'); ?></td>
                                <td><code>autoplay=3000</code></td>
                            </tr>
                            <tr>
                                <td><code>preset</code></td>
                                <td><?php echo __('Load saved preset configuration', 'wc-manager'); ?></td>
                                <td><code>preset="featured_slider"</code></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <h3><?php echo __('Usage Examples', 'wc-manager'); ?></h3>
                    <pre><code>[re_slides]</code> - <?php echo __('Show all products', 'wc-manager'); ?></pre>
                    <pre><code>[re_slides type="latest" number=10]</code> - <?php echo __('Show 10 latest products', 'wc-manager'); ?></pre>
                    <pre><code>[re_slides type="popular" number=8]</code> - <?php echo __('Show 8 most popular products', 'wc-manager'); ?></pre>
                    <pre><code>[re_slides product="68,69,70"]</code> - <?php echo __('Show specific products', 'wc-manager'); ?></pre>
                    <pre><code>[re_slides cat="1205" number=10]</code> - <?php echo __('Show 10 products from category', 'wc-manager'); ?></pre>
                    <pre><code>[re_slides tag="new" autoplay=3000]</code> - <?php echo __('Show products with tag, autoplay every 3 seconds', 'wc-manager'); ?></pre>
                    <pre><code>[re_slides preset="featured_slider"]</code> - <?php echo __('Use saved preset', 'wc-manager'); ?></pre>
                </div>
            </div>
        </div>
        
        <!-- Preset Editor Modal -->
        <div id="wc-slides-preset-modal" style="display: none;">
            <form id="wc-slides-preset-form">
                <input type="hidden" id="preset-id" name="preset_id">
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('General Settings', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Preset Name', 'wc-manager'); ?></label>
                        <input type="text" id="preset-name" name="name" required>
                    </div>
                </div>
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('Product Filters', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Product Type', 'wc-manager'); ?></label>
                        <select id="preset-type" name="type">
                            <option value=""><?php echo __('None (Manual Selection)', 'wc-manager'); ?></option>
                            <option value="latest"><?php echo __('Latest Products', 'wc-manager'); ?></option>
                            <option value="popular"><?php echo __('Popular Products', 'wc-manager'); ?></option>
                        </select>
                    </div>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Product IDs (comma-separated)', 'wc-manager'); ?></label>
                        <input type="text" id="preset-products" name="products" placeholder="68,69,70">
                    </div>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Category IDs (comma-separated)', 'wc-manager'); ?></label>
                        <input type="text" id="preset-categories" name="categories" placeholder="1205">
                    </div>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Tags (comma-separated)', 'wc-manager'); ?></label>
                        <input type="text" id="preset-tags" name="tags" placeholder="new,featured">
                    </div>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Number of Products', 'wc-manager'); ?></label>
                        <input type="number" id="preset-number" name="number" value="10" min="1">
                    </div>
                </div>
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('Layout Settings', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-grid">
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Mobile Columns', 'wc-manager'); ?></label>
                            <input type="number" id="preset-mobile-columns" name="mobile_columns" value="1" min="1" max="3">
                        </div>
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Tablet Columns', 'wc-manager'); ?></label>
                            <input type="number" id="preset-tablet-columns" name="tablet_columns" value="2" min="1" max="4">
                        </div>
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Desktop Columns', 'wc-manager'); ?></label>
                            <input type="number" id="preset-desktop-columns" name="desktop_columns" value="4" min="1" max="6">
                        </div>
                    </div>
                </div>
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('Navigation Settings', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-grid">
                        <div class="wc-slides-form-row">
                            <label>
                                <input type="checkbox" id="preset-arrows" name="arrows" value="1" checked>
                                <?php echo __('Show Arrows', 'wc-manager'); ?>
                            </label>
                        </div>
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Arrow Position', 'wc-manager'); ?></label>
                            <select id="preset-arrow-position" name="arrow_position">
                                <option value="top"><?php echo __('Top', 'wc-manager'); ?></option>
                                <option value="center" selected><?php echo __('Center', 'wc-manager'); ?></option>
                                <option value="bottom"><?php echo __('Bottom', 'wc-manager'); ?></option>
                            </select>
                        </div>
                        <div class="wc-slides-form-row">
                            <label>
                                <input type="checkbox" id="preset-dots" name="dots" value="1" checked>
                                <?php echo __('Show Pagination Dots', 'wc-manager'); ?>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('Behavior Settings', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-row">
                        <label><?php echo __('Autoplay Delay (ms, 0 = off)', 'wc-manager'); ?></label>
                        <input type="number" id="preset-autoplay" name="autoplay_delay" value="0" min="0" step="1000">
                    </div>
                </div>
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('Style Settings', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-grid">
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Primary Color', 'wc-manager'); ?></label>
                            <input type="text" id="preset-primary-color" name="primary_color" value="#2271b1" class="wc-slides-color-picker">
                        </div>
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Arrow Color', 'wc-manager'); ?></label>
                            <input type="text" id="preset-arrow-color" name="arrow_color" value="#2271b1" class="wc-slides-color-picker">
                        </div>
                        <div class="wc-slides-form-row">
                            <label><?php echo __('Dot Color', 'wc-manager'); ?></label>
                            <input type="text" id="preset-dot-color" name="dot_color" value="#2271b1" class="wc-slides-color-picker">
                        </div>
                    </div>
                </div>
                
                <div class="wc-slides-modal-section">
                    <h3><?php echo __('Display Settings', 'wc-manager'); ?></h3>
                    <div class="wc-slides-form-row">
                        <label>
                            <input type="checkbox" id="preset-add-to-cart" name="add_to_cart" value="1" checked>
                            <?php echo __('Show Add to Cart Button', 'wc-manager'); ?>
                        </label>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * AJAX: Save preset
     */
    public function ajax_save_preset() {
        check_ajax_referer('wc_slides_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $preset_id = sanitize_text_field($_POST['preset_id'] ?? '');
        if (empty($preset_id)) {
            $preset_id = 'preset_' . time();
        }
        
        $preset_data = [
            'name' => sanitize_text_field($_POST['name']),
            'filters' => [
                'type' => sanitize_text_field($_POST['type'] ?? ''),
                'products' => !empty($_POST['products']) ? array_map('intval', explode(',', $_POST['products'])) : [],
                'categories' => !empty($_POST['categories']) ? array_map('intval', explode(',', $_POST['categories'])) : [],
                'tags' => !empty($_POST['tags']) ? array_map('trim', explode(',', $_POST['tags'])) : [],
                'number' => intval($_POST['number'] ?? 10),
            ],
            'layout' => [
                'mobile_columns' => intval($_POST['mobile_columns'] ?? 1),
                'tablet_columns' => intval($_POST['tablet_columns'] ?? 2),
                'desktop_columns' => intval($_POST['desktop_columns'] ?? 4),
            ],
            'navigation' => [
                'arrows' => isset($_POST['arrows']),
                'arrow_position' => sanitize_text_field($_POST['arrow_position'] ?? 'center'),
                'dots' => isset($_POST['dots']),
            ],
            'behavior' => [
                'autoplay_delay' => intval($_POST['autoplay_delay'] ?? 0),
            ],
            'style' => [
                'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? '#2271b1'),
                'arrow_color' => sanitize_hex_color($_POST['arrow_color'] ?? '#2271b1'),
                'dot_color' => sanitize_hex_color($_POST['dot_color'] ?? '#2271b1'),
            ],
            'display' => [
                'add_to_cart' => isset($_POST['add_to_cart']),
            ],
        ];
        
        $presets = get_option('wc_slides_presets', []);
        $presets[$preset_id] = $preset_data;
        update_option('wc_slides_presets', $presets);
        
        wp_send_json_success([
            'preset_id' => $preset_id,
            'preset' => $preset_data,
        ]);
    }
    
    /**
     * AJAX: Delete preset
     */
    public function ajax_delete_preset() {
        check_ajax_referer('wc_slides_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $preset_id = sanitize_text_field($_POST['preset_id']);
        $presets = get_option('wc_slides_presets', []);
        
        if (isset($presets[$preset_id])) {
            unset($presets[$preset_id]);
            update_option('wc_slides_presets', $presets);
            wp_send_json_success();
        } else {
            wp_send_json_error('Preset not found');
        }
    }
    
    /**
     * AJAX: Get preset
     */
    public function ajax_get_preset() {
        check_ajax_referer('wc_slides_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $preset_id = sanitize_text_field($_POST['preset_id']);
        $preset = $this->get_preset($preset_id);
        
        if ($preset) {
            wp_send_json_success($preset);
        } else {
            wp_send_json_error('Preset not found');
        }
    }
}
