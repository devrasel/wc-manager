/**
 * WC Slides Frontend JavaScript
 * Initializes Swiper sliders and handles add to cart functionality
 */

(function ($) {
    'use strict';

    // Initialize sliders when DOM is ready
    $(document).ready(function () {
        initializeSliders();
        setupAddToCart();
    });

    // Re-initialize when Elementor preview is loaded
    $(window).on('elementor/frontend/init', function () {
        elementorFrontend.hooks.addAction('frontend/element_ready/widget', function ($scope) {
            initializeSliders();
        });
    });

    /**
     * Initialize all Swiper sliders on the page
     */
    function initializeSliders() {
        $('.wc-slides-wrapper').each(function () {
            const $wrapper = $(this);
            const config = $wrapper.data('config');
            const sliderId = $wrapper.attr('id');

            // Check if Swiper is available
            if (typeof Swiper === 'undefined') {
                console.warn('WC Slides: Swiper library not loaded yet for', sliderId);
                // Remove loading state to prevent infinite loading
                $wrapper.removeClass('wc-slides-loading');
                return;
            }

            if (!config) {
                console.error('WC Slides: No configuration found for slider', sliderId);
                $wrapper.removeClass('wc-slides-loading');
                return;
            }

            // Find the swiper container within this wrapper
            const swiperContainer = $wrapper.find('.wc-slides-swiper')[0];

            if (!swiperContainer) {
                console.error('WC Slides: Swiper container not found for', sliderId);
                $wrapper.removeClass('wc-slides-loading');
                return;
            }

            // Check if already initialized
            if (swiperContainer.swiper) {
                console.log('WC Slides: Slider already initialized', sliderId);
                $wrapper.removeClass('wc-slides-loading');
                return;
            }

            // Update navigation selectors to be scoped to this slider
            if (config.navigation) {
                config.navigation = {
                    nextEl: '#' + sliderId + ' .swiper-button-next',
                    prevEl: '#' + sliderId + ' .swiper-button-prev',
                };
            }

            if (config.pagination) {
                config.pagination = {
                    el: '#' + sliderId + ' .swiper-pagination',
                    clickable: true,
                };
            }

            // Add init callback to remove loading state
            config.on = {
                init: function () {
                    // Remove loading class when slider is initialized
                    $wrapper.removeClass('wc-slides-loading');
                }
            };

            // Fallback: Remove loading state after 3 seconds if not initialized
            setTimeout(function () {
                if ($wrapper.hasClass('wc-slides-loading')) {
                    console.warn('WC Slides: Timeout - removing loading state for', sliderId);
                    $wrapper.removeClass('wc-slides-loading');
                }
            }, 3000);

            // Initialize Swiper
            try {
                new Swiper(swiperContainer, config);
            } catch (error) {
                console.error('WC Slides: Error initializing slider', sliderId, error);
                // Remove loading class even on error
                $wrapper.removeClass('wc-slides-loading');
            }
        });
    }

    /**
     * Setup add to cart functionality
     */
    function setupAddToCart() {
        $(document).on('click', '.wc-slides-add-to-cart', function (e) {
            e.preventDefault();

            const $button = $(this);
            const productId = $button.data('product-id');
            const quantity = $button.data('quantity') || 1;

            if ($button.hasClass('loading')) {
                return;
            }

            // Add loading state
            $button.addClass('loading');
            const originalText = $button.text();
            $button.text(wcSlidesData.addingText || 'Adding...');

            // Add to cart via AJAX
            $.ajax({
                url: wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart'),
                type: 'POST',
                data: {
                    product_id: productId,
                    quantity: quantity,
                },
                success: function (response) {
                    if (response.error) {
                        alert(response.error);
                        $button.removeClass('loading');
                        $button.text(originalText);
                        return;
                    }

                    // Update button state
                    $button.removeClass('loading').addClass('added');
                    $button.text(wcSlidesData.addedToCartText || 'Added!');

                    // Trigger WooCommerce events
                    $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);

                    // Reset button after 2 seconds
                    setTimeout(function () {
                        $button.removeClass('added');
                        $button.text(originalText);
                    }, 2000);
                },
                error: function () {
                    alert('Error adding product to cart. Please try again.');
                    $button.removeClass('loading');
                    $button.text(originalText);
                }
            });
        });
    }

})(jQuery);
