<?php
/**
 * WC Manager WC Popup
 * Description: Always-show branch selection modal for WooCommerce, with admin page search and selection persistence.
 * Author: Rasel Ahmed
 * Version: 2.0
 * Text Domain: wc-manager
 */

if (!defined('ABSPATH')) exit;

// Removed the global add_action('admin_menu') from here as it's now handled by the main plugin file.

class WC_Branch_Selector_Manager {

    public function __construct() {
        add_action('admin_init', [$this, 'register_branch_selector_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wcccf_get_popup', [$this, 'ajax_get_popup']);
        add_action('wp_ajax_wcccf_add_popup', [$this, 'ajax_add_popup']);
        add_action('wp_ajax_wcccf_update_popup', [$this, 'ajax_update_popup']);
        add_action('wp_ajax_wcccf_delete_popup', [$this, 'ajax_delete_popup']);
        add_action('wp_footer', [$this, 'render_popups_modal']);
    }

    public function register_branch_selector_settings() {
        register_setting('wc_branch_selector_group', 'wc_popups', [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_popups']
        ]);
    }

    public function sanitize_popups($popups) {
        $sanitized_popups = [];
        if (is_array($popups)) {
            foreach ($popups as $popup) {
                $sanitized_popup = $this->sanitize_single_popup($popup);
                if (isset($sanitized_popup['name']) && !empty($sanitized_popup['name'])) {
                    $sanitized_popups[] = $sanitized_popup;
                }
            }
        }
        return $sanitized_popups;
    }

    private function sanitize_single_popup($popup) {
        $sanitized = [];
        
        if (isset($popup['name'])) {
            $sanitized['name'] = sanitize_text_field($popup['name']);
        }
        $sanitized['enabled'] = isset($popup['enabled']) ? 1 : 0;
        if (isset($popup['content'])) {
            $sanitized['content'] = wp_kses_post($popup['content']);
        }
        if (isset($popup['pages'])) {
            $sanitized['pages'] = array_map('intval', (array)$popup['pages']);
        } else {
            $sanitized['pages'] = [];
        }
        $sanitized['show_once'] = isset($popup['show_once']) ? 1 : 0;
        
        return $sanitized;
    }
     
    public function enqueue_admin_scripts($hook_suffix) {
        if ('wc-manager_page_wc-popup' === $hook_suffix) { // Adjusted hook for the new submenu slug
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0-rc.0');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0-rc.0', true);
        }
    }

    public function ajax_get_popup() {
        check_ajax_referer('wc_popup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $popup_index = isset($_POST['popup_index']) ? intval($_POST['popup_index']) : -1;
        
        if ($popup_index < 0) {
            wp_send_json_error('Invalid popup index');
        }
        
        $popups = get_option('wc_popups', []);
        
        if (!isset($popups[$popup_index])) {
            wp_send_json_error('Popup not found');
        }
        
        wp_send_json_success($popups[$popup_index]);
    }

    public function ajax_add_popup() {
        check_ajax_referer('wc_popup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $new_popup = isset($_POST['popup']) ? $_POST['popup'] : [];
        
        if (empty($new_popup) || !isset($new_popup['name'])) {
            wp_send_json_error('Invalid popup data');
        }
        
        $sanitized_popup = $this->sanitize_single_popup($new_popup);
        
        if (!isset($sanitized_popup['name']) || empty($sanitized_popup['name'])) {
            wp_send_json_error('Popup name is required');
        }
        
        $popups = get_option('wc_popups', []);
        if (!is_array($popups)) {
            $popups = [];
        }
        
        $popups[] = $sanitized_popup;
        update_option('wc_popups', $popups);
        
        wp_send_json_success([
            'message' => 'Popup added successfully',
            'popup' => $sanitized_popup,
            'index' => count($popups) - 1
        ]);
    }

    public function ajax_update_popup() {
        check_ajax_referer('wc_popup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $popup_index = isset($_POST['popup_index']) ? intval($_POST['popup_index']) : -1;
        $updated_popup = isset($_POST['popup']) ? $_POST['popup'] : [];
        
        if ($popup_index < 0 || empty($updated_popup)) {
            wp_send_json_error('Invalid data');
        }
        
        $popups = get_option('wc_popups', []);
        
        if (!isset($popups[$popup_index])) {
            wp_send_json_error('Popup not found');
        }
        
        $sanitized_popup = $this->sanitize_single_popup($updated_popup);
        
        if (!isset($sanitized_popup['name']) || empty($sanitized_popup['name'])) {
            wp_send_json_error('Popup name is required');
        }
        
        $popups[$popup_index] = $sanitized_popup;
        update_option('wc_popups', $popups);
        
        wp_send_json_success([
            'message' => 'Popup updated successfully',
            'popup' => $sanitized_popup
        ]);
    }

    public function ajax_delete_popup() {
        check_ajax_referer('wc_popup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $popup_index = isset($_POST['popup_index']) ? intval($_POST['popup_index']) : -1;
        
        if ($popup_index < 0) {
            wp_send_json_error('Invalid popup index');
        }
        
        $popups = get_option('wc_popups', []);
        
        if (!is_array($popups)) {
            wp_send_json_error('No popups found');
        }
        
        if (!isset($popups[$popup_index])) {
            wp_send_json_error('Popup not found');
        }
        
        unset($popups[$popup_index]);
        $popups = array_values($popups);
        update_option('wc_popups', $popups);
        
        wp_send_json_success([
            'message' => 'Popup deleted successfully',
            'remaining_count' => count($popups)
        ]);
    }
    
    public function branch_selector_admin_page_html() {
        $popups = get_option('wc_popups', []);
        $all_pages = get_pages();
        ?>
        <div class="wrap">
            <h1><?php _e('WC Manager Popups','wc-manager');?></h1>
            
            <h2 style="margin-top: 30px;"><?php _e('Existing Popups','wc-manager');?></h2>
            
            <table class="wp-list-table widefat fixed striped" id="popups-table">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php _e('Name','wc-manager');?></th>
                        <th style="width: 20%;"><?php _e('Pages','wc-manager');?></th>
                        <th style="width: 15%;"><?php _e('Show Once','wc-manager');?></th>
                        <th style="width: 15%;"><?php _e('Status','wc-manager');?></th>
                        <th style="width: 25%;"><?php _e('Actions','wc-manager');?></th>
                    </tr>
                </thead>
                <tbody id="popups-list">
                   <?php if (empty($popups)): ?>
                    <tr id="no-popups-row">
                        <td colspan="5" style="text-align: center;"><?php _e('No popups configured yet. Add your first popup below.','wc-manager');?></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($popups as $index => $popup): ?>
                        <tr>
                            <td><strong><?php echo esc_html($popup['name']); ?></strong></td>
                            <td>
                                <?php 
                                if (empty($popup['pages'])) {
                                    _e('All pages', 'wc-manager');
                                } else {
                                    echo count($popup['pages']) . ' ' . _n('page', 'pages', count($popup['pages']), 'wc-manager');
                                }
                                ?>
                            </td>
                            <td><?php echo $popup['show_once'] ? __('Yes', 'wc-manager') : __('No', 'wc-manager'); ?></td>
                            <td>
                                <?php if ($popup['enabled']): ?>
                                    <span style="color: green;">✓ <?php _e('Enabled', 'wc-manager'); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">✗ <?php _e('Disabled', 'wc-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small edit-popup-btn" data-popup-index="<?php echo $index; ?>"><?php _e('Edit', 'wc-manager'); ?></button>
                                <button type="button" class="button button-small button-danger delete-popup-btn"><?php _e('Delete', 'wc-manager'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 40px;"><?php _e('Add New Popup','wc-manager');?></h2>
            
            <div class="add-popup-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="popup-name"><?php _e('Popup Name','wc-manager');?></label></th>
                        <td>
                            <input type="text" id="popup-name" class="regular-text" placeholder="<?php _e('e.g., Welcome Offer, Sale Alert','wc-manager'); ?>" />
                            <p class="description"><?php _e('Give this popup a descriptive name for easy identification.','wc-manager');?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="popup-enabled"><?php _e('Enable Popup','wc-manager');?></label></th>
                        <td>
                            <input type="checkbox" id="popup-enabled" value="1" checked />
                            <p class="description"><?php _e('Enable or disable this popup.','wc-manager');?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="popup-pages"><?php _e('Show On Pages','wc-manager');?></label></th>
                        <td>
                            <select id="popup-pages" multiple="multiple" class="popup-pages-select2" style="width:400px;">
                                <?php foreach($all_pages as $page): ?>
                                    <option value="<?php echo $page->ID; ?>"><?php echo esc_html($page->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Select pages where this popup should appear. Leave empty for all pages.','wc-manager');?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="popup-show-once"><?php _e('Show Once Per Session','wc-manager');?></label></th>
                        <td>
                            <input type="checkbox" id="popup-show-once" value="1" checked />
                            <p class="description"><?php _e('If enabled, this popup will only show once per browser session.','wc-manager');?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="popup-content-editor"><?php _e('Popup Content','wc-manager');?></label></th>
                        <td>
                            <?php 
                            wp_editor('', 'popup_content_editor', [
                                'textarea_rows' => 10,
                                'media_buttons' => true,
                                'teeny' => false,
                                'tinymce' => true,
                                'quicktags' => true
                            ]); 
                            ?>
                            <p class="description"><?php _e('Add your custom HTML content, images, or text for the popup.','wc-manager');?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" id="add-popup-btn" class="button button-primary"><?php _e('Add Popup', 'wc-manager'); ?></button>
                    <button type="button" id="cancel-edit-btn" class="button" style="display:none;"><?php _e('Cancel', 'wc-manager'); ?></button>
                </p>
            </div>
        </div>
        
        <style>
            .button-danger { background: #dc3232; border-color: #dc3232; color: #fff; }
            .button-danger:hover { background: #c23030; border-color: #c23030; color: #fff; }
        </style>
        
        <script>
        var wc_popup_vars = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('wc_popup_nonce'); ?>'
        };
        </script>
        
        <script>
        jQuery(document).ready(function($){
            if($.fn.select2){ $('.popup-pages-select2').select2({placeholder:'<?php _e('Search pages...', 'wc-manager'); ?>'});
            }
            var editingPopupIndex = null;
            $(document).on('click', '.edit-popup-btn', function() {
                var popupIndex = $(this).data('popup-index');
                editingPopupIndex = popupIndex;
                $('#add-popup-btn').text('<?php _e('Update Popup', 'wc-manager'); ?>').data('editing', true);
                $('#cancel-edit-btn').show();
                $('html, body').animate({ scrollTop: $('.add-popup-form').offset().top - 50 }, 500);
                $.ajax({
                    url: wc_popup_vars.ajax_url, type: 'POST',
                    data: { action: 'wcccf_get_popup', nonce: wc_popup_vars.nonce, popup_index: popupIndex },
                    success: function(response) {
                        if (response.success) {
                            var popup = response.data;
                            $('#popup-name').val(popup.name);
                            $('#popup-enabled').prop('checked', popup.enabled == 1);
                            $('#popup-pages').val(popup.pages).trigger('change');
                            $('#popup-show-once').prop('checked', popup.show_once == 1);
                            if (typeof tinymce !== 'undefined') { tinymce.get('popup_content_editor').setContent(popup.content || ''); }
                        } else { alert('Error: ' + (response.data || 'Failed to load popup')); }
                    }
                });
            });
            $('#cancel-edit-btn').on('click', function() {
                editingPopupIndex = null;
                $('#add-popup-btn').text('<?php _e('Add Popup', 'wc-manager'); ?>').data('editing', false);
                $(this).hide();
                $('#popup-name').val('');
                $('#popup-enabled').prop('checked', true);
                $('#popup-pages').val(null).trigger('change');
                $('#popup-show-once').prop('checked', true);
                if (typeof tinymce !== 'undefined') { tinymce.get('popup_content_editor').setContent(''); }
            });
            $('#add-popup-btn').on('click', function() {
                var isEditing = $(this).data('editing');
                var popupName = $('#popup-name').val();
                var popupContent = typeof tinymce !== 'undefined' ? tinymce.get('popup_content_editor').getContent() : '';
                if (!popupName) { alert('<?php _e('Please enter a popup name', 'wc-manager'); ?>'); return; }
                var popup = {
                    name: popupName,
                    enabled: $('#popup-enabled').is(':checked') ? 1 : 0,
                    pages: $('#popup-pages').val() || [],
                    show_once: $('#popup-show-once').is(':checked') ? 1 : 0,
                    content: popupContent
                };
                var actionName = isEditing ? 'wcccf_update_popup' : 'wcccf_add_popup';
                var ajaxData = { action: actionName, nonce: wc_popup_vars.nonce, popup: popup };
                if (isEditing) { ajaxData.popup_index = editingPopupIndex; }
                $(this).prop('disabled', true).text(isEditing ? '<?php _e('Updating...', 'wc-manager'); ?>' : '<?php _e('Adding...', 'wc-manager'); ?>');
                $.ajax({
                    url: wc_popup_vars.ajax_url, type: 'POST', data: ajaxData,
                    success: function(response) {
                        if (response.success) { location.reload(); } else {
                            alert('Error: ' + (response.data || 'Failed to save popup'));
                            $('#add-popup-btn').prop('disabled', false).text(isEditing ? '<?php _e('Update Popup', 'wc-manager'); ?>' : '<?php _e('Add Popup', 'wc-manager'); ?>');
                        }
                    }
                });
            });
            $(document).on('click', '.delete-popup-btn', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to delete this popup?', 'wc-manager')); ?>')) { return; }
                var $row = $(this).closest('tr');
                var $allRows = $('#popups-list tr:not(#no-popups-row)');
                var popupIndex = $allRows.index($row);
                if (popupIndex < 0) { alert('Error: Could not determine popup index'); return; }
                var $deleteBtn = $(this);
                $deleteBtn.prop('disabled', true).text('<?php _e('Deleting...', 'wc-manager'); ?>');
                $.ajax({
                    url: wc_popup_vars.ajax_url, type: 'POST',
                    data: { action: 'wcccf_delete_popup', nonce: wc_popup_vars.nonce, popup_index: popupIndex },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                if ($('#popups-list tr:not(#no-popups-row)').length === 0) {
                                    $('#popups-list').html('<tr id="no-popups-row"><td colspan="5" style="text-align: center;"><?php _e('No popups configured yet. Add your first popup below.','wc-manager');?></td></tr>');
                                }
                            });
                        } else { alert('Error: ' + (response.data || 'Failed to delete popup')); $deleteBtn.prop('disabled', false).text('<?php _e('Delete', 'wc-manager'); ?>'); }
                    }
                });
            });
        });
        </script>
        <?php
    }



    public function render_popups_modal(){
        $popups = get_option('wc_popups', []);
        if (empty($popups)) return;
        
        $current_page = get_the_ID();
        
        // Filter popups that should show on this page
        $popups_to_show = [];
        foreach ($popups as $index => $popup) {
            if (!$popup['enabled']) continue;
            
            // Check if popup should show on this page
            if (!empty($popup['pages']) && !in_array($current_page, $popup['pages'])) {
                continue;
            }
            
            $popups_to_show[] = [
                'popup' => $popup,
                'index' => $index
            ];
        }
        
        if (empty($popups_to_show)) return;
        
        // Render each popup
        foreach ($popups_to_show as $data) {
            $this->render_single_popup($data['popup'], $data['index'], $current_page);
        }
        
        // Always render queue management script (handles single or multiple popups)
        $this->render_popup_queue_script($popups_to_show);
    }
    
    private function render_single_popup($popup, $index, $current_page) {
        $popup_id = 'wcPopup_' . $index;
        $session_key = 'wc_popup_shown_' . $current_page . '_' . $index;
        $show_once = $popup['show_once'];
        ?>
        <style>
            #<?php echo $popup_id; ?>{
                position:fixed;
                top:0;
                left:0;
                width:100%;
                height:100%;
                background:rgba(0,0,0,0.6);
                z-index:<?php echo 9999 + $index; ?>;
                display:none;
            }
            #<?php echo $popup_id; ?> .wc-popup-content-wrapper{
                background:#fff;
                padding:30px;
                max-width:600px;
                max-height:80vh;
                overflow-y:auto;
                margin:5% auto;
                border-radius:8px;
                position:relative;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            #<?php echo $popup_id; ?> .wc-popup-close{
                position:absolute;
                top:10px;
                right:15px;
                font-size:28px;
                font-weight:bold;
                color:#aaa;
                cursor:pointer;
                background:none;
                border:none;
                line-height:20px;
                padding:0;
            }
            #<?php echo $popup_id; ?> .wc-popup-close:hover{
                color:#000;
            }
            #<?php echo $popup_id; ?> .wc-popup-content{
                margin-top:10px;
            }
            #<?php echo $popup_id; ?> .wc-popup-content img{
                max-width:100%;
                height:auto;
            }
        </style>
        <div id="<?php echo $popup_id; ?>" class="wc-popup-modal" data-popup-index="<?php echo $index; ?>">
            <div class="wc-popup-content-wrapper">
                <button class="wc-popup-close" title="Close">&times;</button>
                <div class="wc-popup-content">
                    <?php echo wp_kses_post($popup['content']); ?>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($){
            var showOnce = <?php echo $show_once ? 'true' : 'false'; ?>;
            var sessionKey = '<?php echo esc_js($session_key); ?>';
            var popupId = '<?php echo $popup_id; ?>';
            
            function showPopup<?php echo $index; ?>() {
                $('#' + popupId).fadeIn(300);
            }
            
            function closePopup<?php echo $index; ?>() {
                $('#' + popupId).fadeOut(300);
                if (showOnce) {
                    sessionStorage.setItem(sessionKey, 'shown');
                }
                // Trigger next popup in queue if exists
                $(document).trigger('wcPopupClosed', [<?php echo $index; ?>]);
            }
            
            // Store show function for queue management
            window.wcShowPopup<?php echo $index; ?> = showPopup<?php echo $index; ?>;
            
            // Check if should show this popup
            var shouldShow = true;
            if (showOnce && sessionStorage.getItem(sessionKey)) {
                shouldShow = false;
            }
            
            // Mark for queue
            if (shouldShow) {
                if (typeof window.wcPopupQueue === 'undefined') {
                    window.wcPopupQueue = [];
                }
                window.wcPopupQueue.push(<?php echo $index; ?>);
            }
            
            // Close button handler
            $('#' + popupId + ' .wc-popup-close').on('click', function() {
                closePopup<?php echo $index; ?>();
            });
            
            // Close on overlay click
            $('#' + popupId).on('click', function(e){
                if (e.target.id === popupId) {
                    closePopup<?php echo $index; ?>();
                }
            });
            
            // Close on ESC key
            $(document).on('keydown', function(e){
                if(e.key === 'Escape' && $('#' + popupId).is(':visible')){
                    closePopup<?php echo $index; ?>();
                }
            });
        });
        </script>
        <?php
    }
    
    private function render_popup_queue_script($popups_to_show) {
        ?>
        <script>
        jQuery(document).ready(function($){
            // Popup queue management - show one at a time
            if (typeof window.wcPopupQueue !== 'undefined' && window.wcPopupQueue.length > 0) {
                var currentPopupIndex = 0;
                
                function showNextPopup() {
                    if (currentPopupIndex < window.wcPopupQueue.length) {
                        var popupIndex = window.wcPopupQueue[currentPopupIndex];
                        var showFunc = window['wcShowPopup' + popupIndex];
                        if (typeof showFunc === 'function') {
                            showFunc();
                        }
                    }
                }
                
                // Listen for popup close events
                $(document).on('wcPopupClosed', function(e, closedIndex) {
                    // Check if the closed popup is the current one
                    if (window.wcPopupQueue[currentPopupIndex] === closedIndex) {
                        currentPopupIndex++;
                        setTimeout(showNextPopup, 500); // Small delay between popups
                    }
                });
                
                // Show first popup
                showNextPopup();
            }
        });
        </script>
        <?php
    }
}
