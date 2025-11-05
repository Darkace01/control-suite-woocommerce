<?php
/**
 * Plugin Name: Shipping Event Receiver
 * Plugin URI: https://example.com/shipping-event-receiver
 * Description: Receives event notifications for orders from third-party shipping platforms and logs all requests
 * Version: 1.0.2
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

// Prevent duplicate class declaration
if (class_exists('Shipping_Event_Receiver')) {
    return;
}

class Shipping_Event_Receiver {
    
    private $log_table = 'shipping_event_logs';
    private $option_name = 'shipping_event_receiver_settings';
    
    public function __construct() {
        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_endpoint'));
        
        // Create database table on plugin activation
        register_activation_hook(__FILE__, array($this, 'create_log_table'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        
        // Register AJAX handlers
        add_action('wp_ajax_get_log_details', array($this, 'ajax_get_log_details'));
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
        
        // Also add under Settings for easy access
        add_options_page(
            'Shipping Event Receiver',
            'Shipping Events',
            'manage_options',
            'shipping-event-receiver',
            array($this, 'render_settings_page')
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
        $settings_link = '<a href="options-general.php?page=shipping-event-receiver">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
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

// Initialize the plugin only once
if (!isset($GLOBALS['shipping_event_receiver_instance'])) {
    $GLOBALS['shipping_event_receiver_instance'] = new Shipping_Event_Receiver();
}
