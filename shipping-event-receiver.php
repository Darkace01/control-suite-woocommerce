<?php
/**
 * Plugin Name: Shipping Event Receiver
 * Plugin URI: https://example.com/shipping-event-receiver
 * Description: Receives event notifications for orders from third-party shipping platforms and logs all requests
 * Version: 1.0.7
 * Author: Kazeem Quadri
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shipping-event-receiver
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin file constant
if (!defined('SHIPPING_EVENT_RECEIVER_FILE')) {
    define('SHIPPING_EVENT_RECEIVER_FILE', __FILE__);
}

// Prevent duplicate class declaration
if (!class_exists('Shipping_Event_Receiver')) {

class Shipping_Event_Receiver {
    
    private $log_table = 'shipping_event_logs';
    private $option_name = 'shipping_event_receiver_settings';
    private $plugin_file;
    private $order_control;
    private $payment_gateway_control;
    
    public function __construct() {
        $this->plugin_file = SHIPPING_EVENT_RECEIVER_FILE;
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize sub-modules
        $this->order_control = new SER_Order_Control();
        $this->payment_gateway_control = new SER_Payment_Gateway_Control();
        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_endpoint'));
        
        // Create database table on plugin activation
        register_activation_hook($this->plugin_file, array($this, 'create_log_table'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), array($this, 'add_settings_link'));
        
        // Register AJAX handlers
        add_action('wp_ajax_get_log_details', array($this, 'ajax_get_log_details'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once plugin_dir_path($this->plugin_file) . 'includes/class-order-control.php';
        require_once plugin_dir_path($this->plugin_file) . 'includes/class-payment-gateway-control.php';
    }
    
    /**
     * Get endpoint slug from settings
     */
    private function get_endpoint() {
        $settings = get_option($this->option_name);
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        return sanitize_title($endpoint);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Add top-level menu in sidebar
        add_menu_page(
            'Shipping Event Receiver',
            'Shipping Events',
            'manage_options',
            'shipping-event-receiver',
            array($this, 'render_settings_page'),
            'dashicons-upload',
            56
        );
        
        // Add submenu for Logs (rename the first item)
        add_submenu_page(
            'shipping-event-receiver',
            'Event Logs',
            'Event Logs',
            'manage_options',
            'shipping-event-receiver',
            array($this, 'render_settings_page')
        );
        
        // Add submenu for Order Control
        add_submenu_page(
            'shipping-event-receiver',
            'Order Control',
            'Order Control',
            'manage_options',
            'shipping-order-control',
            array($this, 'render_order_control_page')
        );
        
        // Add submenu for Payment Gateway Control
        add_submenu_page(
            'shipping-event-receiver',
            'Payment Gateway Control',
            'Payment Gateway',
            'manage_options',
            'shipping-payment-gateway',
            array($this, 'render_payment_gateway_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting($this->option_name, $this->option_name, array($this, 'sanitize_settings'));
        
        add_settings_section(
            'shipping_event_general',
            'General Settings',
            array($this, 'render_section_info'),
            'shipping-event-receiver'
        );
        
        add_settings_field(
            'endpoint_slug',
            'Endpoint Slug',
            array($this, 'render_endpoint_field'),
            'shipping-event-receiver',
            'shipping_event_general'
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['endpoint_slug'])) {
            $sanitized['endpoint_slug'] = sanitize_title($input['endpoint_slug']);
        }
        
        // Flush rewrite rules after changing endpoint
        flush_rewrite_rules();
        
        return $sanitized;
    }
    
    /**
     * Render settings section info
     */
    public function render_section_info() {
        echo '<p>Configure your shipping webhook endpoint settings.</p>';
    }
    
    /**
     * Render endpoint field
     */
    public function render_endpoint_field() {
        $settings = get_option($this->option_name);
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        $full_url = rest_url('shipping/v1/' . $endpoint);
        
        echo '<input type="text" name="' . $this->option_name . '[endpoint_slug]" value="' . esc_attr($endpoint) . '" class="regular-text" />';
        echo '<p class="description">Enter the endpoint slug (e.g., "shipping-webhook"). The full URL will be: <br><strong>' . esc_url($full_url) . '</strong></p>';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = get_option($this->option_name);
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        $full_url = rest_url('shipping/v1/' . $endpoint);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><strong>Current Webhook URL:</strong> <code><?php echo esc_url($full_url); ?></code></p>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('shipping-event-receiver');
                submit_button('Save Settings');
                ?>
            </form>
            
            <hr>
            
            <h2>Recent Logs</h2>
            <?php $this->render_logs_table(); ?>
        </div>
        <?php
    }
    
    /**
     * Render logs table
     */
    private function render_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $this->log_table;
        $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 20");
        
        if (empty($logs)) {
            echo '<p>No logs found yet.</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Processed At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log->id); ?></td>
                        <td><?php echo esc_html($log->ip_address); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($log->status); ?>">
                                <?php echo esc_html($log->status); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log->created_at); ?></td>
                        <td><?php echo esc_html($log->processed_at); ?></td>
                        <td>
                            <button class="button button-small view-log-details" data-log-id="<?php echo esc_attr($log->id); ?>">View Details</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Modal HTML -->
        <div id="log-details-modal" style="display:none;">
            <div class="log-modal-overlay"></div>
            <div class="log-modal-content">
                <div class="log-modal-header">
                    <h2>Log Details</h2>
                    <button class="log-modal-close">&times;</button>
                </div>
                <div class="log-modal-body">
                    <div class="log-detail-loading">Loading...</div>
                    <div class="log-detail-content" style="display:none;">
                        <table class="widefat">
                            <tr>
                                <th>Log ID:</th>
                                <td><span id="log-detail-id"></span></td>
                            </tr>
                            <tr>
                                <th>IP Address:</th>
                                <td><span id="log-detail-ip"></span></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td><span id="log-detail-status"></span></td>
                            </tr>
                            <tr>
                                <th>Created At:</th>
                                <td><span id="log-detail-created"></span></td>
                            </tr>
                            <tr>
                                <th>Processed At:</th>
                                <td><span id="log-detail-processed"></span></td>
                            </tr>
                        </table>
                        
                        <h3>Request Body</h3>
                        <pre id="log-detail-body" class="log-code-block"></pre>
                        
                        <h3>Request Parameters</h3>
                        <pre id="log-detail-params" class="log-code-block"></pre>
                        
                        <h3>Request Headers</h3>
                        <pre id="log-detail-headers" class="log-code-block"></pre>
                        
                        <h3>Response Data</h3>
                        <pre id="log-detail-response" class="log-code-block"></pre>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .status-success { color: #46b450; font-weight: bold; }
            .status-error { color: #dc3232; font-weight: bold; }
            .status-pending { color: #ffb900; font-weight: bold; }
            
            .log-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
                z-index: 100000;
            }
            
            .log-modal-content {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                border-radius: 4px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
                z-index: 100001;
                width: 90%;
                max-width: 900px;
                max-height: 90vh;
                display: flex;
                flex-direction: column;
            }
            
            .log-modal-header {
                padding: 20px;
                border-bottom: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .log-modal-header h2 {
                margin: 0;
                font-size: 20px;
            }
            
            .log-modal-close {
                background: none;
                border: none;
                font-size: 30px;
                cursor: pointer;
                color: #666;
                padding: 0;
                width: 30px;
                height: 30px;
                line-height: 30px;
            }
            
            .log-modal-close:hover {
                color: #000;
            }
            
            .log-modal-body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }
            
            .log-code-block {
                background: #f5f5f5;
                border: 1px solid #ddd;
                border-radius: 3px;
                padding: 15px;
                overflow-x: auto;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.5;
                max-height: 300px;
                overflow-y: auto;
            }
            
            .log-modal-body h3 {
                margin-top: 20px;
                margin-bottom: 10px;
                font-size: 16px;
            }
            
            .log-modal-body table {
                margin-bottom: 20px;
            }
            
            .log-modal-body table th {
                width: 150px;
                text-align: left;
                font-weight: bold;
                padding: 8px;
            }
            
            .log-modal-body table td {
                padding: 8px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var modal = $('#log-details-modal');
            
            // Open modal and load log details
            $('.view-log-details').on('click', function() {
                var logId = $(this).data('log-id');
                
                // Show modal
                modal.show();
                $('.log-detail-loading').show();
                $('.log-detail-content').hide();
                
                // Make AJAX request
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_log_details',
                        log_id: logId,
                        nonce: '<?php echo wp_create_nonce('shipping_event_logs'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var log = response.data;
                            
                            // Populate modal
                            $('#log-detail-id').text(log.id);
                            $('#log-detail-ip').text(log.ip_address);
                            $('#log-detail-status').html('<span class="status-' + log.status + '">' + log.status + '</span>');
                            $('#log-detail-created').text(log.created_at);
                            $('#log-detail-processed').text(log.processed_at || 'N/A');
                            
                            // Format JSON data
                            $('#log-detail-body').text(log.request_body || 'No data');
                            
                            try {
                                var params = JSON.parse(log.request_params);
                                $('#log-detail-params').text(JSON.stringify(params, null, 2));
                            } catch(e) {
                                $('#log-detail-params').text(log.request_params || 'No data');
                            }
                            
                            try {
                                var headers = JSON.parse(log.request_headers);
                                $('#log-detail-headers').text(JSON.stringify(headers, null, 2));
                            } catch(e) {
                                $('#log-detail-headers').text(log.request_headers || 'No data');
                            }
                            
                            try {
                                var responseData = JSON.parse(log.response_data);
                                $('#log-detail-response').text(JSON.stringify(responseData, null, 2));
                            } catch(e) {
                                $('#log-detail-response').text(log.response_data || 'No data');
                            }
                            
                            $('.log-detail-loading').hide();
                            $('.log-detail-content').show();
                        } else {
                            alert('Error: ' + response.data);
                            modal.hide();
                        }
                    },
                    error: function() {
                        alert('Failed to load log details');
                        modal.hide();
                    }
                });
            });
            
            // Close modal
            $('.log-modal-close, .log-modal-overlay').on('click', function() {
                modal.hide();
            });
            
            // Prevent modal content click from closing
            $('.log-modal-content').on('click', function(e) {
                e.stopPropagation();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Add settings link on plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=shipping-event-receiver') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Render Order Control page
     */
    public function render_order_control_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if (isset($_POST['ser_order_control_nonce']) && wp_verify_nonce($_POST['ser_order_control_nonce'], 'ser_order_control_save')) {
            $settings = array(
                'enable_orders' => isset($_POST['enable_orders']) ? true : false,
                'enable_timeframe' => isset($_POST['enable_timeframe']) ? true : false,
                'start_time' => sanitize_text_field($_POST['start_time']),
                'end_time' => sanitize_text_field($_POST['end_time']),
                'disabled_message' => sanitize_textarea_field($_POST['disabled_message'])
            );
            $this->order_control->update_settings($settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        $settings = $this->order_control->get_settings();
        $stats = $this->order_control->get_statistics();
        
        ?>
        <div class="wrap">
            <h1>Order Control Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>Current Status:</strong> <span style="color: <?php echo $stats['current_status'] === 'active' ? 'green' : 'red'; ?>;"><?php echo ucfirst($stats['current_status']); ?></span></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('ser_order_control_save', 'ser_order_control_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Orders</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_orders" value="1" <?php checked($settings['enable_orders'], true); ?> />
                                Allow customers to place orders
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Enable Timeframe Restrictions</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_timeframe" value="1" <?php checked($settings['enable_timeframe'], true); ?> />
                                Only allow orders during specific times
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Start Time</th>
                        <td>
                            <input type="time" name="start_time" value="<?php echo esc_attr($settings['start_time']); ?>" />
                            <p class="description">Orders will be allowed starting from this time</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">End Time</th>
                        <td>
                            <input type="time" name="end_time" value="<?php echo esc_attr($settings['end_time']); ?>" />
                            <p class="description">Orders will be blocked after this time</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Disabled Message</th>
                        <td>
                            <textarea name="disabled_message" rows="3" class="large-text"><?php echo esc_textarea($settings['disabled_message']); ?></textarea>
                            <p class="description">Message shown to customers when orders are disabled</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render Payment Gateway Control page
     */
    public function render_payment_gateway_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if (isset($_POST['ser_payment_gateway_nonce']) && wp_verify_nonce($_POST['ser_payment_gateway_nonce'], 'ser_payment_gateway_save')) {
            $rules = array();
            
            if (isset($_POST['rules']) && is_array($_POST['rules'])) {
                foreach ($_POST['rules'] as $rule) {
                    if (!empty($rule['currencies'])) {
                        $rules[] = array(
                            'currencies' => array_map('sanitize_text_field', $rule['currencies']),
                            'gateways' => isset($rule['gateways']) ? array_map('sanitize_text_field', $rule['gateways']) : array()
                        );
                    }
                }
            }
            
            $settings = array('rules' => $rules);
            $this->payment_gateway_control->update_settings($settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        $settings = $this->payment_gateway_control->get_settings();
        $available_gateways = $this->payment_gateway_control->get_available_gateways();
        $currencies = $this->payment_gateway_control->get_active_currencies();
        $stats = $this->payment_gateway_control->get_statistics();
        
        ?>
        <div class="wrap">
            <h1>Payment Gateway Control</h1>
            
            <div class="notice notice-info">
                <p>
                    <strong>Total Rules:</strong> <?php echo $stats['total_rules']; ?> | 
                    <strong>Active Currencies:</strong> <?php echo $stats['active_currencies']; ?> | 
                    <strong>Available Gateways:</strong> <?php echo $stats['available_gateways']; ?>
                </p>
            </div>
            
            <form method="post" action="" id="payment-gateway-form">
                <?php wp_nonce_field('ser_payment_gateway_save', 'ser_payment_gateway_nonce'); ?>
                
                <h2>Currency-Gateway Rules</h2>
                <p>Configure which payment gateways should be available for selected currencies.</p>
                
                <div id="gateway-rules">
                    <?php
                    if (!empty($settings['rules'])) {
                        foreach ($settings['rules'] as $index => $rule) {
                            $this->render_gateway_rule_row($index, $rule, $currencies, $available_gateways);
                        }
                    } else {
                        $this->render_gateway_rule_row(0, array('currencies' => array(), 'gateways' => array()), $currencies, $available_gateways);
                    }
                    ?>
                </div>
                
                <p>
                    <button type="button" class="button" id="add-rule">Add New Rule</button>
                </p>
                
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var ruleIndex = <?php echo !empty($settings['rules']) ? count($settings['rules']) : 1; ?>;
            
            $('#add-rule').on('click', function() {
                var newRow = `
                    <div class="gateway-rule" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd;">
                        <h3>Rule ${ruleIndex + 1} <button type="button" class="button remove-rule" style="float: right;">Remove</button></h3>
                        <table class="form-table">
                            <tr>
                                <th>Currencies</th>
                                <td>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                        <?php foreach ($currencies as $code => $name): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input type="checkbox" name="rules[${ruleIndex}][currencies][]" value="<?php echo esc_attr($code); ?>" />
                                            <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">Select one or more currencies for this rule</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Allowed Gateways</th>
                                <td>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                        <?php foreach ($available_gateways as $gateway_id => $gateway_name): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input type="checkbox" name="rules[${ruleIndex}][gateways][]" value="<?php echo esc_attr($gateway_id); ?>" />
                                            <?php echo esc_html($gateway_name); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">Select which payment gateways to show for the selected currencies</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                `;
                $('#gateway-rules').append(newRow);
                ruleIndex++;
            });
            
            $(document).on('click', '.remove-rule', function() {
                $(this).closest('.gateway-rule').remove();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render a single gateway rule row
     */
    private function render_gateway_rule_row($index, $rule, $currencies, $available_gateways) {
        $selected_currencies = isset($rule['currencies']) ? $rule['currencies'] : array();
        ?>
        <div class="gateway-rule" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd;">
            <h3>Rule <?php echo ($index + 1); ?> 
                <?php if ($index > 0): ?>
                <button type="button" class="button remove-rule" style="float: right;">Remove</button>
                <?php endif; ?>
            </h3>
            <table class="form-table">
                <tr>
                    <th>Currencies</th>
                    <td>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                            <?php foreach ($currencies as $code => $name): ?>
                            <label style="display: block; margin: 5px 0;">
                                <input type="checkbox" name="rules[<?php echo $index; ?>][currencies][]" value="<?php echo esc_attr($code); ?>" 
                                    <?php checked(in_array($code, $selected_currencies)); ?> />
                                <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">Select one or more currencies for this rule</p>
                    </td>
                </tr>
                <tr>
                    <th>Allowed Gateways</th>
                    <td>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                            <?php foreach ($available_gateways as $gateway_id => $gateway_name): ?>
                            <label style="display: block; margin: 5px 0;">
                                <input type="checkbox" name="rules[<?php echo $index; ?>][gateways][]" value="<?php echo esc_attr($gateway_id); ?>" 
                                    <?php checked(in_array($gateway_id, isset($rule['gateways']) ? $rule['gateways'] : array())); ?> />
                                <?php echo esc_html($gateway_name); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">Select which payment gateways to show for the selected currencies</p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * AJAX handler to get log details
     */
    public function ajax_get_log_details() {
        check_ajax_referer('shipping_event_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        
        if (!$log_id) {
            wp_send_json_error('Invalid log ID');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . $this->log_table;
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $log_id));
        
        if (!$log) {
            wp_send_json_error('Log not found');
            return;
        }
        
        // Format the response
        $response = array(
            'id' => $log->id,
            'ip_address' => $log->ip_address,
            'status' => $log->status,
            'created_at' => $log->created_at,
            'processed_at' => $log->processed_at,
            'request_body' => $log->request_body,
            'request_params' => $log->request_params,
            'request_headers' => $log->request_headers,
            'response_data' => $log->response_data
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Register the REST API endpoint
     */
    public function register_endpoint() {
        $endpoint = $this->get_endpoint();
        
        register_rest_route('shipping/v1', '/' . $endpoint, array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true', // Adjust security as needed
        ));
    }
    
    /**
     * Handle incoming webhook requests
     */
    public function handle_webhook(WP_REST_Request $request) {
        global $wpdb;
        
        // Get request data
        $body = $request->get_body();
        $params = $request->get_json_params();
        $headers = $request->get_headers();
        
        // Log the request
        $log_id = $this->log_request($body, $params, $headers);
        
        // Process the event
        try {
            $response_data = $this->process_event($params);
            
            // Update log with success status
            $this->update_log_status($log_id, 'success', $response_data);
            
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Event received and processed',
                'log_id' => $log_id,
                'data' => $response_data
            ), 200);
            
        } catch (Exception $e) {
            // Update log with error status
            $this->update_log_status($log_id, 'error', array('error' => $e->getMessage()));
            
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Error processing event',
                'error' => $e->getMessage(),
                'log_id' => $log_id
            ), 500);
        }
    }
    
    /**
     * Process the shipping event
     */
    private function process_event($data) {
        // Extract common fields (adjust based on your shipping platform's format)
        $order_id = isset($data['order_id']) ? sanitize_text_field($data['order_id']) : null;
        $tracking_number = isset($data['tracking_number']) ? sanitize_text_field($data['tracking_number']) : null;
        $status = isset($data['status']) ? sanitize_text_field($data['status']) : null;
        $event_type = isset($data['event_type']) ? sanitize_text_field($data['event_type']) : null;
        
        // Add your custom processing logic here
        // For example: update order meta, send notifications, etc.
        
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                // Update order meta with shipping info
                if ($tracking_number) {
                    $order->update_meta_data('_shipping_tracking_number', $tracking_number);
                }
                if ($status) {
                    $order->add_order_note(sprintf('Shipping status updated: %s', $status));
                }
                $order->save();
            }
        }
        
        // Hook for custom actions
        do_action('shipping_event_received', $data);
        
        return array(
            'order_id' => $order_id,
            'tracking_number' => $tracking_number,
            'status' => $status,
            'event_type' => $event_type,
            'processed_at' => current_time('mysql')
        );
    }
    
    /**
     * Log the incoming request
     */
    private function log_request($body, $params, $headers) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $this->log_table;
        
        $wpdb->insert(
            $table_name,
            array(
                'request_body' => $body,
                'request_params' => json_encode($params),
                'request_headers' => json_encode($headers),
                'ip_address' => $this->get_client_ip(),
                'created_at' => current_time('mysql'),
                'status' => 'pending'
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update log status after processing
     */
    private function update_log_status($log_id, $status, $response_data = array()) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $this->log_table;
        
        $wpdb->update(
            $table_name,
            array(
                'status' => $status,
                'response_data' => json_encode($response_data),
                'processed_at' => current_time('mysql')
            ),
            array('id' => $log_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }
    
    /**
     * Create database table for logging
     */
    public function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $this->log_table;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_body longtext NOT NULL,
            request_params longtext,
            request_headers longtext,
            ip_address varchar(45),
            status varchar(20) DEFAULT 'pending',
            response_data longtext,
            created_at datetime NOT NULL,
            processed_at datetime,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

} // End if class_exists check

// Initialize the plugin only once
function shipping_event_receiver_init() {
    if (!isset($GLOBALS['shipping_event_receiver_instance']) && class_exists('Shipping_Event_Receiver')) {
        $GLOBALS['shipping_event_receiver_instance'] = new Shipping_Event_Receiver();
    }
}

// Always run initialization on plugins_loaded
add_action('plugins_loaded', 'shipping_event_receiver_init');
