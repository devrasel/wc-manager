/**
 * WC Slides Admin JavaScript
 * Handles preset management and shortcode generation
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initColorPickers();
        setupPresetModal();
        setupShortcodeGenerator();
        setupPresetActions();
    });

    /**
     * Initialize color pickers
     */
    function initColorPickers() {
        $('.wc-slides-color-picker').wpColorPicker();
    }

    /**
     * Setup preset modal
     */
    function setupPresetModal() {
        // Initialize modal
        $('#wc-slides-preset-modal').dialog({
            autoOpen: false,
            modal: true,
            width: '90%',
            maxWidth: 800,
            height: 'auto',
            maxHeight: window.innerHeight * 0.9,
            title: 'Slider Preset',
            dialogClass: 'wc-slides-dialog',
            buttons: {
                'Save Preset': {
                    text: 'Save Preset',
                    class: 'button-primary',
                    click: savePreset
                },
                'Cancel': {
                    text: 'Cancel',
                    class: 'button',
                    click: function () {
                        $(this).dialog('close');
                    }
                }
            },
            open: function () {
                // Re-initialize color pickers in modal
                $('.wc-slides-color-picker').wpColorPicker();
            }
        });

        // Add new preset button
        $('#wc-slides-add-preset').on('click', function () {
            resetPresetForm();
            $('#wc-slides-preset-modal').dialog('option', 'title', 'Add New Preset').dialog('open');
        });
    }

    /**
     * Reset preset form
     */
    function resetPresetForm() {
        $('#wc-slides-preset-form')[0].reset();
        $('#preset-id').val('');
        $('#preset-arrows').prop('checked', true);
        $('#preset-dots').prop('checked', true);
        $('#preset-add-to-cart').prop('checked', true);

        // Reset color pickers
        $('#preset-primary-color').wpColorPicker('color', '#2271b1');
        $('#preset-arrow-color').wpColorPicker('color', '#2271b1');
        $('#preset-dot-color').wpColorPicker('color', '#2271b1');
    }

    /**
     * Save preset
     */
    function savePreset() {
        const presetName = $('#preset-name').val().trim();

        if (!presetName) {
            showNotification('Please enter a preset name.', 'error');
            return;
        }

        const formData = {
            action: 'wc_slides_save_preset',
            nonce: wcSlidesAdmin.nonce,
            preset_id: $('#preset-id').val(),
            name: presetName,
            products: $('#preset-products').val(),
            categories: $('#preset-categories').val(),
            tags: $('#preset-tags').val(),
            number: $('#preset-number').val(),
            mobile_columns: $('#preset-mobile-columns').val(),
            tablet_columns: $('#preset-tablet-columns').val(),
            desktop_columns: $('#preset-desktop-columns').val(),
            arrows: $('#preset-arrows').is(':checked') ? 1 : 0,
            arrow_position: $('#preset-arrow-position').val(),
            dots: $('#preset-dots').is(':checked') ? 1 : 0,
            autoplay_delay: $('#preset-autoplay').val(),
            primary_color: $('#preset-primary-color').val(),
            arrow_color: $('#preset-arrow-color').val(),
            dot_color: $('#preset-dot-color').val(),
            add_to_cart: $('#preset-add-to-cart').is(':checked') ? 1 : 0,
        };

        // Show loading state
        const $dialog = $('#wc-slides-preset-modal');
        $dialog.find('form').addClass('wc-slides-loading');

        $.post(wcSlidesAdmin.ajaxUrl, formData, function (response) {
            $dialog.find('form').removeClass('wc-slides-loading');

            if (response.success) {
                const presetId = response.data.preset_id;
                const preset = response.data.preset;

                // Close modal
                $dialog.dialog('close');

                // Show success notification
                showNotification('Preset saved successfully!', 'success');

                // Update or add row in table
                updatePresetRow(presetId, preset);

            } else {
                showNotification('Error saving preset: ' + (response.data || 'Unknown error'), 'error');
            }
        }).fail(function () {
            $dialog.find('form').removeClass('wc-slides-loading');
            showNotification('Network error. Please try again.', 'error');
        });
    }

    /**
     * Show notification message
     */
    function showNotification(message, type) {
        // Remove existing notifications
        $('.wc-slides-notification').remove();

        // Create notification
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible wc-slides-notification"><p>' + message + '</p></div>');

        // Add to page
        $('.wc-slides-admin h1').after($notice);

        // Make dismissible
        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>');

        // Handle dismiss
        $notice.find('.notice-dismiss').on('click', function () {
            $notice.fadeOut(function () {
                $(this).remove();
            });
        });

        // Auto-dismiss success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function () {
                $notice.fadeOut(function () {
                    $(this).remove();
                });
            }, 5000);
        }

        // Scroll to notification
        $('html, body').animate({
            scrollTop: $notice.offset().top - 100
        }, 300);
    }

    /**
     * Update preset row in table
     */
    function updatePresetRow(presetId, preset) {
        const shortcode = '[re_slides preset="' + presetId + '"]';
        const $existingRow = $('tr[data-preset-id="' + presetId + '"]');

        if ($existingRow.length) {
            // Update existing row
            $existingRow.find('strong').text(preset.name);
            $existingRow.find('code').text(shortcode);
            $existingRow.find('.wc-slides-copy-shortcode').data('shortcode', shortcode);

            // Highlight the updated row
            $existingRow.addClass('wc-slides-highlight');
            setTimeout(function () {
                $existingRow.removeClass('wc-slides-highlight');
            }, 2000);
        } else {
            // Add new row
            const $newRow = $('<tr data-preset-id="' + presetId + '">' +
                '<td><strong>' + preset.name + '</strong></td>' +
                '<td>' +
                '<code>' + shortcode + '</code> ' +
                '<button type="button" class="button button-small wc-slides-copy-shortcode" data-shortcode=\'' + shortcode + '\'>Copy</button>' +
                '</td>' +
                '<td>' +
                '<button type="button" class="button button-small wc-slides-edit-preset" data-preset-id="' + presetId + '">Edit</button> ' +
                '<button type="button" class="button button-small wc-slides-delete-preset" data-preset-id="' + presetId + '">Delete</button>' +
                '</td>' +
                '</tr>');

            // Remove "no presets" row if exists
            $('#no-presets-row').remove();

            // Add to table
            $('#wc-slides-presets-list').append($newRow);

            // Highlight the new row
            $newRow.addClass('wc-slides-highlight');
            setTimeout(function () {
                $newRow.removeClass('wc-slides-highlight');
            }, 2000);
        }
    }

    /**
     * Setup preset actions (edit, delete, copy)
     */
    function setupPresetActions() {
        // Edit preset
        $(document).on('click', '.wc-slides-edit-preset', function () {
            const presetId = $(this).data('preset-id');

            $.post(wcSlidesAdmin.ajaxUrl, {
                action: 'wc_slides_get_preset',
                nonce: wcSlidesAdmin.nonce,
                preset_id: presetId
            }, function (response) {
                if (response.success) {
                    loadPresetData(presetId, response.data);
                    $('#wc-slides-preset-modal').dialog('option', 'title', 'Edit Preset').dialog('open');
                } else {
                    alert('Error loading preset: ' + (response.data || 'Unknown error'));
                }
            });
        });

        // Delete preset
        $(document).on('click', '.wc-slides-delete-preset', function () {
            const presetId = $(this).data('preset-id');
            const $row = $(this).closest('tr');
            const presetName = $row.find('strong').text();

            if (!confirm('Are you sure you want to delete "' + presetName + '"?')) {
                return;
            }

            $.post(wcSlidesAdmin.ajaxUrl, {
                action: 'wc_slides_delete_preset',
                nonce: wcSlidesAdmin.nonce,
                preset_id: presetId
            }, function (response) {
                if (response.success) {
                    // Remove row with animation
                    $row.fadeOut(300, function () {
                        $(this).remove();

                        // Check if table is empty
                        if ($('#wc-slides-presets-list tr').length === 0) {
                            $('#wc-slides-presets-list').html(
                                '<tr id="no-presets-row"><td colspan="3"><em>No presets created yet.</em></td></tr>'
                            );
                        }
                    });

                    // Show success notification
                    showNotification('Preset "' + presetName + '" deleted successfully!', 'success');
                } else {
                    showNotification('Error deleting preset: ' + (response.data || 'Unknown error'), 'error');
                }
            }).fail(function () {
                showNotification('Network error. Please try again.', 'error');
            });
        });

        // Copy shortcode
        $(document).on('click', '.wc-slides-copy-shortcode', function () {
            const shortcode = $(this).data('shortcode');
            copyToClipboard(shortcode);

            const $button = $(this);
            const originalText = $button.text();
            $button.text('Copied!');
            setTimeout(function () {
                $button.text(originalText);
            }, 2000);
        });
    }

    /**
     * Load preset data into form
     */
    function loadPresetData(presetId, preset) {
        $('#preset-id').val(presetId);
        $('#preset-name').val(preset.name);

        // Filters
        if (preset.filters) {
            $('#preset-products').val(preset.filters.products ? preset.filters.products.join(',') : '');
            $('#preset-categories').val(preset.filters.categories ? preset.filters.categories.join(',') : '');
            $('#preset-tags').val(preset.filters.tags ? preset.filters.tags.join(',') : '');
            $('#preset-number').val(preset.filters.number || 10);
        }

        // Layout
        if (preset.layout) {
            $('#preset-mobile-columns').val(preset.layout.mobile_columns || 1);
            $('#preset-tablet-columns').val(preset.layout.tablet_columns || 2);
            $('#preset-desktop-columns').val(preset.layout.desktop_columns || 4);
        }

        // Navigation
        if (preset.navigation) {
            $('#preset-arrows').prop('checked', preset.navigation.arrows);
            $('#preset-arrow-position').val(preset.navigation.arrow_position || 'center');
            $('#preset-dots').prop('checked', preset.navigation.dots);
        }

        // Behavior
        if (preset.behavior) {
            $('#preset-autoplay').val(preset.behavior.autoplay_delay || 0);
        }

        // Style
        if (preset.style) {
            $('#preset-primary-color').wpColorPicker('color', preset.style.primary_color || '#2271b1');
            $('#preset-arrow-color').wpColorPicker('color', preset.style.arrow_color || '#2271b1');
            $('#preset-dot-color').wpColorPicker('color', preset.style.dot_color || '#2271b1');
        }

        // Display
        if (preset.display) {
            $('#preset-add-to-cart').prop('checked', preset.display.add_to_cart);
        }
    }

    /**
     * Setup shortcode generator
     */
    function setupShortcodeGenerator() {
        $('#wc-slides-generate-shortcode').on('click', function () {
            const parts = ['[re_slides'];

            const product = $('#gen-product').val().trim();
            if (product) parts.push('product="' + product + '"');

            const cat = $('#gen-cat').val().trim();
            if (cat) parts.push('cat="' + cat + '"');

            const tag = $('#gen-tag').val().trim();
            if (tag) parts.push('tag="' + tag + '"');

            const number = $('#gen-number').val();
            if (number && number != 10) parts.push('number=' + number);

            const add2cart = $('#gen-add2cart').val();
            if (add2cart != 'yes') parts.push('add2cart=' + add2cart);

            const arrow = $('#gen-arrow').val();
            if (arrow != 'yes') parts.push('arrow=' + arrow);

            const dots = $('#gen-dots').val();
            if (dots != 'yes') parts.push('dots=' + dots);

            const autoplay = $('#gen-autoplay').val();
            if (autoplay && autoplay != 0) parts.push('autoplay=' + autoplay);

            // Column settings
            const mobileColumns = $('#gen-mobile-columns').val();
            if (mobileColumns && mobileColumns != 1) parts.push('mobile_columns=' + mobileColumns);

            const tabletColumns = $('#gen-tablet-columns').val();
            if (tabletColumns && tabletColumns != 2) parts.push('tablet_columns=' + tabletColumns);

            const desktopColumns = $('#gen-desktop-columns').val();
            if (desktopColumns && desktopColumns != 4) parts.push('desktop_columns=' + desktopColumns);

            parts.push(']');

            const shortcode = parts.join(' ');
            $('#generated-shortcode').val(shortcode);
            $('.wc-slides-generated-shortcode').slideDown();
        });

        // Copy generated shortcode
        $('#copy-generated-shortcode').on('click', function () {
            const shortcode = $('#generated-shortcode').val();
            copyToClipboard(shortcode);

            const $button = $(this);
            const originalText = $button.text();
            $button.text('Copied!');
            setTimeout(function () {
                $button.text(originalText);
            }, 2000);
        });
    }

    /**
     * Copy text to clipboard
     */
    function copyToClipboard(text) {
        const $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(text).select();
        document.execCommand('copy');
        $temp.remove();
    }

})(jQuery);
