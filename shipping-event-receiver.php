<?php
/**
 * Plugin Name: Commerce Control Suite
 * Plugin URI: https://github.com/Darkace01/commerce-control-suite
 * Description: Comprehensive control suite for WooCommerce to manage order restrictions, payment gateway rules, shipping event webhooks, and advanced currency switching.
 * Version: 1.2.7
 * Author: Kazeem Quadri
 * Author URI: https://github.com/Darkace01
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: commerce-control-suite
 * Requires at least: 6.2
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * Tested up to: 6.9
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin file constant
if (!defined('COMMERCE_CONTROL_SUITE_FILE')) {
    define('COMMERCE_CONTROL_SUITE_FILE', __FILE__);
}

// Define plugin constants
if (!defined('COMMERCE_CONTROL_SUITE_VERSION')) {
    define('COMMERCE_CONTROL_SUITE_VERSION', '1.2.7');
}
if (!defined('COMMERCE_CONTROL_SUITE_PLUGIN_DIR')) {
    define('COMMERCE_CONTROL_SUITE_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('COMMERCE_CONTROL_SUITE_PLUGIN_URL')) {
    define('COMMERCE_CONTROL_SUITE_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Prevent duplicate class declaration
if (!class_exists('CommerceControlSuite')) {
class CommerceControlSuite {
    
    const PAGE_DASHBOARD = 'commerce-control-suite';
    const PAGE_LOGS = 'commerce-event-logs';
    const PAGE_ORDER_CONTROL = 'commerce-order-control';
    const PAGE_PAYMENT_GATEWAY = 'commerce-payment-gateway';
    const PAGE_CURRENCY_CONTROL = 'commerce-currency-control';
    
    const LABEL_ORDER_CONTROL = 'Order Control';
    const LABEL_PAYMENT_GATEWAY = 'Payment Gateway';
    const LABEL_CURRENCY_CONTROL = 'Currency Control';
    
    private $logTable = 'shipping_event_logs';
    private $optionName = 'shipping_event_receiver_settings';
    private $pluginFile;
    private $orderControl;
    private $paymentGatewayControl;
	private $currencyControl;
    
    public function __construct() {
        $this->pluginFile = COMMERCE_CONTROL_SUITE_FILE;
        
        // Load dependencies
        $this->loadDependencies();
        
        // Initialize sub-modules
        $this->orderControl = new CommerceControlSuiteOrderControl();
        $this->paymentGatewayControl = new CommerceControlSuitePaymentGatewayControl();
		$this->currencyControl = CommerceControlSuiteCurrencyControl::instance();
        
        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'registerEndpoint'));
        
        // Create database table on plugin activation
        register_activation_hook($this->pluginFile, array($this, 'createLogTable'));
        
        // Check and create table if it doesn't exist (fallback)
        add_action('admin_init', array($this, 'checkDatabaseTable'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_init', array($this, 'registerSettings'));
        
        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename($this->pluginFile), array($this, 'addSettingsLink'));
        
        // Register AJAX handlers
        add_action('wp_ajax_commerce_control_suite_get_log_details', array($this, 'ajaxGetLogDetails'));

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, self::PAGE_DASHBOARD) === false) {
            return;
        }

        wp_enqueue_style('commerce-control-suite-admin', plugins_url('assets/css/admin.css', $this->pluginFile), array(), COMMERCE_CONTROL_SUITE_VERSION);
        wp_enqueue_script('commerce-control-suite-admin', plugins_url('assets/js/admin.js', $this->pluginFile), array('jquery'), COMMERCE_CONTROL_SUITE_VERSION, true);

        wp_localize_script('commerce-control-suite-admin', 'commerceControlSuiteAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('shipping_event_logs')
        ));
    }
    
    private function loadDependencies() {
        require_once plugin_dir_path($this->pluginFile) . 'includes/class-order-control.php';
        require_once plugin_dir_path($this->pluginFile) . 'includes/class-payment-gateway-control.php';
		require_once plugin_dir_path($this->pluginFile) . 'includes/class-currency-control.php';
    }
    
    /**
     * Handle old page slug redirects for backward compatibility
     */
    public function handleOldPageSlug() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (is_admin() && isset($_GET['page'])) {
            $oldToNew = array(
                'shipping-event-receiver' => self::PAGE_DASHBOARD,
                'shipping-event-logs' => self::PAGE_LOGS,
                'shipping-order-control' => self::PAGE_ORDER_CONTROL,
                'shipping-payment-gateway' => self::PAGE_PAYMENT_GATEWAY
            );
            
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $currentPage = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
            
            if (isset($oldToNew[$currentPage])) {
                $newPage = $oldToNew[$currentPage];
                $redirectUrl = add_query_arg('page', $newPage, admin_url('admin.php'));
                wp_safe_redirect($redirectUrl);
                exit;
            }
        }
    }
    
    private function getEndpoint() {
        $settings = get_option($this->optionName, array());
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        return sanitize_title($endpoint);
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        // Handle old slug for backward compatibility
        add_action('admin_init', array($this, 'handleOldPageSlug'));
        
        // Add top-level menu in sidebar
        add_menu_page(
            'Commerce Control Suite',
            'Commerce Control Suite',
            'manage_options',
            self::PAGE_DASHBOARD,
            array($this, 'renderDashboardPage'),
            'dashicons-admin-generic',
            56
        );
        
        // Add submenu for Dashboard
        add_submenu_page(
            self::PAGE_DASHBOARD,
            'Dashboard',
            'Dashboard',
            'manage_options',
            self::PAGE_DASHBOARD,
            array($this, 'renderDashboardPage')
        );
        
        // Add submenu for Event Logs
        add_submenu_page(
            self::PAGE_DASHBOARD,
            'Shipping Event Logs',
            'Event Logs',
            'manage_options',
            self::PAGE_LOGS,
            array($this, 'renderSettingsPage')
        );
        
        // Add submenu for Order Control
        add_submenu_page(
            self::PAGE_DASHBOARD,
            self::LABEL_ORDER_CONTROL,
            self::LABEL_ORDER_CONTROL,
            'manage_options',
            self::PAGE_ORDER_CONTROL,
            array($this, 'renderOrderControlPage')
        );
        
        // Add submenu for Payment Gateway Control
        add_submenu_page(
            self::PAGE_DASHBOARD,
            self::LABEL_PAYMENT_GATEWAY,
            self::LABEL_PAYMENT_GATEWAY,
            'manage_options',
            self::PAGE_PAYMENT_GATEWAY,
            array($this, 'render_payment_gateway_page')
        );

        // Add submenu for Currency Control
        add_submenu_page(
            self::PAGE_DASHBOARD,
            self::LABEL_CURRENCY_CONTROL,
            self::LABEL_CURRENCY_CONTROL,
            'manage_options',
            self::PAGE_CURRENCY_CONTROL,
            array($this, 'renderCurrencyControlPage')
        );
    }
    
    /**
     * Register settings
     */
    public function registerSettings() {
        register_setting($this->optionName, $this->optionName, array($this, 'sanitizeSettings'));
        
        add_settings_section(
            'shipping_event_general',
            'General Settings',
            array($this, 'renderSectionInfo'),
            self::PAGE_DASHBOARD
        );
        
        add_settings_field(
            'endpoint_slug',
            'Endpoint Slug',
            array($this, 'renderEndpointField'),
            self::PAGE_DASHBOARD,
            'shipping_event_general'
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitizeSettings($input) {
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
    public function renderSectionInfo() {
        echo '<p>' . esc_html__('Configure your shipping webhook endpoint settings.', 'commerce-control-suite') . '</p>';
    }
    
    /**
     * Render endpoint field
     */
    public function renderEndpointField() {
        $settings = get_option($this->optionName, array());
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        $fullUrl = rest_url('shipping/v1/' . $endpoint);
        
        printf(
            '<input type="text" name="%s[endpoint_slug]" value="%s" class="regular-text" />',
            esc_attr($this->optionName),
            esc_attr($endpoint)
        );
        echo '<p class="description">' . wp_kses(
            sprintf(
                /* translators: %s: The full URL of the shipping event webhook. */
                __('Enter the endpoint slug (e.g., "shipping-webhook"). The full URL will be: <br><strong>%s</strong>', 'commerce-control-suite'),
                esc_url($fullUrl)
            ),
            array('br' => array(), 'strong' => array())
        ) . '</p>';
    }
    
    /**
     * Render Dashboard page
     */
    public function renderDashboardPage() {
        global $wpdb;
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get statistics with caching
        $logTable    = $wpdb->prefix . $this->logTable;
        $cache_group = 'commerce_control_suite_stats';
        $totalLogs   = wp_cache_get( 'total_logs', $cache_group );
        $successLogs = wp_cache_get( 'success_logs', $cache_group );
        $errorLogs   = wp_cache_get( 'error_logs', $cache_group );
        $recentLogs  = wp_cache_get( 'recent_logs', $cache_group );

        if ( false === $totalLogs || false === $successLogs || false === $errorLogs || false === $recentLogs ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $totalLogs = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $logTable ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $successLogs = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'success'", $logTable ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $errorLogs = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'error'", $logTable ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $recentLogs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC LIMIT 5", $logTable ) );

            wp_cache_set( 'total_logs', $totalLogs, $cache_group, 300 );
            wp_cache_set( 'success_logs', $successLogs, $cache_group, 300 );
            wp_cache_set( 'error_logs', $errorLogs, $cache_group, 300 );
            wp_cache_set( 'recent_logs', $recentLogs, $cache_group, 300 );
        }
        
        $orderStats = $this->orderControl->getStatistics();
        $paymentStats = $this->paymentGatewayControl->getStatistics();
        
        $settings = get_option($this->optionName, array());
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        $fullUrl = rest_url('shipping/v1/' . $endpoint);
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Commerce Control Suite - Dashboard', 'commerce-control-suite'); ?></h1>
            
            <div class="dashboard-widgets" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
                
                <!-- Webhook Info -->
                <div class="dashboard-widget" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                    <h2 style="margin-top: 0;"><span class="dashicons dashicons-admin-links" style="color: #2271b1;"></span> <?php esc_html_e('Shipping Webhook', 'commerce-control-suite'); ?></h2>
                    <p><strong><?php esc_html_e('URL:', 'commerce-control-suite'); ?></strong></p>
                    <input type="text" value="<?php echo esc_url($fullUrl); ?>" readonly class="large-text" style="background: #f5f5f5;" />
                    <p style="margin-top: 10px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_LOGS)); ?>" class="button"><?php esc_html_e('View Logs', 'commerce-control-suite'); ?></a>
                    </p>
                </div>
                
                <!-- Event Logs Stats -->
                <div class="dashboard-widget" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                    <h2 style="margin-top: 0;"><span class="dashicons dashicons-list-view" style="color: #2271b1;"></span> <?php esc_html_e('Event Logs', 'commerce-control-suite'); ?></h2>
                    <p><strong><?php esc_html_e('Total:', 'commerce-control-suite'); ?></strong> <?php echo esc_html(number_format($totalLogs)); ?></p>
                    <p><strong><?php esc_html_e('Success:', 'commerce-control-suite'); ?></strong> <span style="color: green;"><?php echo esc_html(number_format($successLogs)); ?></span></p>
                    <p><strong><?php esc_html_e('Errors:', 'commerce-control-suite'); ?></strong> <span style="color: red;"><?php echo esc_html(number_format($errorLogs)); ?></span></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_LOGS)); ?>" class="button"><?php esc_html_e('View All Logs', 'commerce-control-suite'); ?></a>
                    </p>
                </div>
                
                <!-- Order Control Stats -->
                <div class="dashboard-widget" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                    <h2 style="margin-top: 0;"><span class="dashicons dashicons-cart" style="color: #2271b1;"></span> <?php esc_html_e('Order Control', 'commerce-control-suite'); ?></h2>
                    <p><strong><?php esc_html_e('Status:', 'commerce-control-suite'); ?></strong> <span style="color: <?php echo esc_attr($orderStats['current_status'] === 'active' ? 'green' : 'red'); ?>; font-weight: bold;"><?php echo esc_html(ucfirst($orderStats['current_status'])); ?></span></p>
                    <p><strong><?php esc_html_e('Orders Enabled:', 'commerce-control-suite'); ?></strong> <?php echo $orderStats['orders_enabled'] ? esc_html__('Yes', 'commerce-control-suite') : esc_html__('No', 'commerce-control-suite'); ?></p>
                    <p><strong><?php esc_html_e('Timeframe Enabled:', 'commerce-control-suite'); ?></strong> <?php echo $orderStats['timeframe_enabled'] ? esc_html__('Yes', 'commerce-control-suite') : esc_html__('No', 'commerce-control-suite'); ?></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_ORDER_CONTROL)); ?>" class="button"><?php esc_html_e('Manage Orders', 'commerce-control-suite'); ?></a>
                    </p>
                </div>
                
                <!-- Payment Gateway Stats -->
                <div class="dashboard-widget" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                    <h2 style="margin-top: 0;"><span class="dashicons dashicons-money-alt" style="color: #2271b1;"></span> <?php esc_html_e('Payment Gateways', 'commerce-control-suite'); ?></h2>
                    <p><strong><?php esc_html_e('Total Rules:', 'commerce-control-suite'); ?></strong> <?php echo esc_html(number_format($paymentStats['total_rules'])); ?></p>
                    <p><strong><?php esc_html_e('Active Currencies:', 'commerce-control-suite'); ?></strong> <?php echo esc_html(number_format($paymentStats['active_currencies'])); ?></p>
                    <p><strong><?php esc_html_e('Available Gateways:', 'commerce-control-suite'); ?></strong> <?php echo esc_html(number_format($paymentStats['available_gateways'])); ?></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_PAYMENT_GATEWAY)); ?>" class="button"><?php esc_html_e('Manage Gateways', 'commerce-control-suite'); ?></a>
                    </p>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 5px; margin-top: 20px;">
                <h2><span class="dashicons dashicons-clock" style="color: #2271b1;"></span> <?php esc_html_e('Recent Event Logs', 'commerce-control-suite'); ?></h2>
                <?php if (!empty($recentLogs)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'commerce-control-suite'); ?></th>
                            <th><?php esc_html_e('IP Address', 'commerce-control-suite'); ?></th>
                            <th><?php esc_html_e('Status', 'commerce-control-suite'); ?></th>
                            <th><?php esc_html_e('Created At', 'commerce-control-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->id); ?></td>
                            <td><?php echo esc_html($log->ip_address); ?></td>
                            <td>
                                <span style="color: <?php echo esc_attr($log->status === 'success' ? 'green' : 'red'); ?>; font-weight: bold;">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p><?php esc_html_e('No recent logs found.', 'commerce-control-suite'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function renderSettingsPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = get_option($this->optionName, array());
        $endpoint = isset($settings['endpoint_slug']) ? $settings['endpoint_slug'] : 'shipping-webhook';
        $fullUrl = rest_url('shipping/v1/' . $endpoint);
        
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Shipping Event Logs', 'commerce-control-suite'); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php esc_html_e('Current Webhook URL:', 'commerce-control-suite'); ?></strong> <code><?php echo esc_url($fullUrl); ?></code></p>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields($this->optionName);
                do_settings_sections(self::PAGE_DASHBOARD);
                submit_button(__('Save Settings', 'commerce-control-suite'));
                ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Recent Logs', 'commerce-control-suite'); ?></h2>
            <?php $this->renderLogsTable(); ?>
        </div>
        <?php
    }
    
    /**
     * Render logs table
     */
    private function renderLogsTable() {
        global $wpdb;
        
        $tableName = $wpdb->prefix . $this->logTable;
        $cache_key = 'recent_logs_20';
        $cache_group = 'commerce_control_suite_logs';
        
        $logs = wp_cache_get( $cache_key, $cache_group );
        
        if ( false === $logs ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC LIMIT 20", $tableName ) );
            wp_cache_set( $cache_key, $logs, $cache_group, 300 );
        }
        
        if (empty($logs)) {
            echo '<p>' . esc_html__('No logs found yet.', 'commerce-control-suite') . '</p>';
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
        <?php
    }
    
    public function addSettingsLink($links) {
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_DASHBOARD)) . '">' . esc_html__('Settings', 'commerce-control-suite') . '</a>';
        array_unshift($links, $settingsLink);
        return $links;
    }
    
    public function renderOrderControlPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if ( isset( $_POST['ser_order_control_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ser_order_control_nonce'] ) ), 'ser_order_control_save' ) ) {
            $this->handleOrderControlSubmission();
        }
        
        $settings = $this->orderControl->getSettings();
        $stats = $this->orderControl->getStatistics();
        
        // Get categories and products for dropdowns
        $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
        $products = get_posts(array('post_type' => 'product', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-cart"></span> <?php echo esc_html(self::LABEL_ORDER_CONTROL); ?> Settings</h1>
            
            <div class="notice notice-info">
                <p><strong><?php esc_html_e('Current Status:', 'commerce-control-suite'); ?></strong> <span style="color: <?php echo esc_attr($stats['current_status'] === 'active' ? 'green' : 'red'); ?>; font-weight: bold;"><?php echo esc_html(ucfirst($stats['current_status'])); ?></span></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('ser_order_control_save', 'ser_order_control_nonce'); ?>
                
                <h2>General Settings</h2>
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
                        <th scope="row">Restriction Type</th>
                        <td>
                            <select name="restriction_type" id="restriction_type" class="regular-text">
                                <option value="all" <?php selected($settings['restriction_type'], 'all'); ?>>All Products</option>
                                <option value="categories" <?php selected($settings['restriction_type'], 'categories'); ?>>Specific Categories</option>
                                <option value="products" <?php selected($settings['restriction_type'], 'products'); ?>>Specific Products</option>
                            </select>
                            <p class="description">Choose what products should be affected by order restrictions</p>
                        </td>
                    </tr>
                    
                    <tr id="categories_row" >
                        <th scope="row">Restricted Categories</th>
                        <td>
                            <?php if (!empty($categories) && !is_wp_error($categories)): ?>
                            <select name="restricted_categories[]" multiple size="10" style="width: 100%; max-width: 500px;">
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category->term_id); ?>" <?php selected(in_array($category->term_id, $settings['restricted_categories'])); ?>>
                                    <?php echo esc_html($category->name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Hold Ctrl/Cmd to select multiple categories</p>
                            <?php else: ?>
                            <p style="color: #d63638;"><strong>No product categories found.</strong> Please create at least one category first.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr id="products_row">
                        <th scope="row">Restricted Products</th>
                        <td>
                            <?php if (!empty($products)): ?>
                            <select name="restricted_products[]" multiple size="10" style="width: 100%; max-width: 500px;">
                                <?php foreach ($products as $product): ?>
                                <option value="<?php echo esc_attr($product->ID); ?>" <?php selected(in_array($product->ID, $settings['restricted_products'])); ?>>
                                    <?php echo esc_html($product->post_title); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Hold Ctrl/Cmd to select multiple products</p>
                            <?php else: ?>
                            <p style="color: #d63638;"><strong>No products found.</strong> Please create at least one product first.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <h2>Time Restrictions</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Time Restrictions</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_timeframe" value="1" <?php checked($settings['enable_timeframe'], true); ?> />
                                Only allow orders during specific times of day
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
                </table>
                
                <h2>Date/Time Range Restrictions</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Date/Time Range</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_date_range" value="1" <?php checked($settings['enable_date_range'], true); ?> />
                                Restrict orders to a specific date and time range
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Start Date & Time</th>
                        <td>
                            <input type="datetime-local" name="start_datetime" value="<?php echo esc_attr($settings['start_datetime']); ?>" class="regular-text" />
                            <p class="description">Orders will be allowed starting from this date and time (Format: YYYY-MM-DDTHH:MM)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">End Date & Time</th>
                        <td>
                            <input type="datetime-local" name="end_datetime" value="<?php echo esc_attr($settings['end_datetime']); ?>" class="regular-text" />
                            <p class="description">Orders will be blocked after this date and time (Format: YYYY-MM-DDTHH:MM)</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Redirect & Messages</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Redirect URL</th>
                        <td>
                            <input type="url" name="redirect_url" value="<?php echo esc_attr($settings['redirect_url']); ?>" class="large-text" placeholder="<?php echo esc_url(home_url()); ?>" />
                            <p class="description"><?php esc_html_e('Redirect customers to this URL when they try to access checkout (leave empty for homepage)', 'commerce-control-suite'); ?></p>
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
                
                <?php submit_button(__('Save Settings', 'commerce-control-suite')); ?>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                function toggleRestrictionFields() {
                    var restrictionType = $('#restriction_type').val();
                    $('#categories_row, #products_row').hide();
                    
                    if (restrictionType === 'categories') {
                        $('#categories_row').show();
                    } else if (restrictionType === 'products') {
                        $('#products_row').show();
                    }
                }
                
                // Show/hide fields when dropdown changes
                $('#restriction_type').on('change', toggleRestrictionFields);
                
                // Initialize on page load - show fields if needed
                toggleRestrictionFields();
            });
            </script>
        </div>
        <?php
    }

    /**
     * Handle Order Control form submission
     */
    private function handleOrderControlSubmission() {
        if ( ! isset( $_POST['ser_order_control_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ser_order_control_nonce'] ) ), 'ser_order_control_save' ) ) {
            return;
        }

        $settings = array(
            'enable_orders'         => isset( $_POST['enable_orders'] ),
            'restriction_type'      => isset( $_POST['restriction_type'] ) ? sanitize_text_field( wp_unslash( $_POST['restriction_type'] ) ) : 'all',
            'restricted_categories' => isset( $_POST['restricted_categories'] ) ? array_map( 'intval', wp_unslash( $_POST['restricted_categories'] ) ) : array(),
            'restricted_products'   => isset( $_POST['restricted_products'] ) ? array_map( 'intval', wp_unslash( $_POST['restricted_products'] ) ) : array(),
            'enable_timeframe'      => isset( $_POST['enable_timeframe'] ),
            'start_time'           => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '00:00',
            'end_time'             => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '23:59',
            'enable_date_range'     => isset( $_POST['enable_date_range'] ),
            'start_datetime'       => isset( $_POST['start_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['start_datetime'] ) ) : '',
            'end_datetime'         => isset( $_POST['end_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['end_datetime'] ) ) : '',
            'redirect_url'          => isset( $_POST['redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) ) : '',
            'disabled_message'      => isset( $_POST['disabled_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['disabled_message'] ) ) : '',
        );

        $this->orderControl->updateSettings( $settings );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully!', 'commerce-control-suite' ) . '</p></div>';
    }
    
    /**
     * Render Payment Gateway Control page
     */
    public function render_payment_gateway_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle delete action
        if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_rule' ) ) {
            $this->handlePaymentGatewayRuleDeletion();
        }
        
        // Handle toggle enabled/disabled
        if ( isset( $_GET['action'] ) && 'toggle' === $_GET['action'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'toggle_rule' ) ) {
            $this->handlePaymentGatewayRuleToggle();
        }
        
        // Handle add/edit rule submission
        if ( isset( $_POST['ser_payment_gateway_rule_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ser_payment_gateway_rule_nonce'] ) ), 'ser_payment_gateway_rule_save' ) ) {
            $this->handlePaymentGatewayRuleSubmission();
        }
        
        $settings = $this->paymentGatewayControl->getSettings();
        $available_gateways = $this->paymentGatewayControl->getAvailableGateways();
        $currencies = $this->paymentGatewayControl->getActiveCurrencies();
        $stats = $this->paymentGatewayControl->getStatistics();
        
        // Check if we're in edit/add mode
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $edit_mode = isset($_GET['action']) && ($_GET['action'] === 'edit' || $_GET['action'] === 'add');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $edit_rule_id = isset($_GET['rule_id']) ? intval($_GET['rule_id']) : null;
        $edit_rule = ($edit_mode && $edit_rule_id !== null && isset($settings['rules'][$edit_rule_id])) ? $settings['rules'][$edit_rule_id] : array();
        
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-money-alt"></span> Payment Gateway Control</h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Total Rules:', 'commerce-control-suite'); ?></strong> <?php echo esc_html($stats['total_rules']); ?> | 
                    <strong><?php esc_html_e('Active Currencies:', 'commerce-control-suite'); ?></strong> <?php echo esc_html($stats['active_currencies']); ?> | 
                    <strong><?php esc_html_e('Available Gateways:', 'commerce-control-suite'); ?></strong> <?php echo esc_html($stats['available_gateways']); ?>
                </p>
            </div>
            
            <?php if ($edit_mode): ?>
                <!-- Edit/Add Rule Form -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 5px; margin-bottom: 20px;">
                    <h2><?php echo ( isset( $_GET['action'] ) && $_GET['action'] === 'add' ) ? 'Add New Rule' : 'Edit Rule'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></h2>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field('ser_payment_gateway_rule_save', 'ser_payment_gateway_rule_nonce'); ?>
                        <?php if ($edit_rule_id !== null && isset( $_GET['action'] ) && $_GET['action'] === 'edit'): // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                            <input type="hidden" name="rule_id" value="<?php echo esc_attr($edit_rule_id); ?>" />
                        <?php endif; ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="rule_name">Rule Name</label></th>
                                <td>
                                    <input type="text" id="rule_name" name="rule_name" value="<?php echo esc_attr(isset($edit_rule['name']) ? $edit_rule['name'] : ''); ?>" class="regular-text" required />
                                    <p class="description">Give this rule a descriptive name</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">Currencies</th>
                                <td>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                        <?php foreach ($currencies as $code => $name): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input type="checkbox" name="currencies[]" value="<?php echo esc_attr($code); ?>" 
                                                <?php checked(in_array($code, isset($edit_rule['currencies']) ? $edit_rule['currencies'] : array())); ?> />
                                            <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">Select currencies for this rule</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">Allowed Gateways</th>
                                <td>
                                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                                        <?php foreach ($available_gateways as $gateway_id => $gateway_name): ?>
                                        <label style="display: block; margin: 5px 0;">
                                            <input type="checkbox" name="gateways[]" value="<?php echo esc_attr($gateway_id); ?>" 
                                                <?php checked(in_array($gateway_id, isset($edit_rule['gateways']) ? $edit_rule['gateways'] : array())); ?> />
                                            <?php echo esc_html($gateway_name); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">Select payment gateways for these currencies</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">Status</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enabled" value="1" <?php checked(!isset($edit_rule['enabled']) || $edit_rule['enabled']); ?> />
                                        Enable this rule
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="submit" class="button button-primary" value="Save Rule" />
                            <a href="<?php echo esc_url(admin_url('admin.php?page=commerce-payment-gateway')); ?>" class="button">Cancel</a>
                        </p>
                    </form>
                </div>
            <?php else: ?>
                <!-- Rules List Table -->
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=commerce-payment-gateway&action=add')); ?>" class="button button-primary">Add New Rule</a>
                </p>
                
                <?php if (!empty($settings['rules'])): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Rule Name</th>
                            <th>Currencies</th>
                            <th>Gateways</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings['rules'] as $index => $rule): ?>
                        <tr>
                            <td><?php echo esc_html($index + 1); ?></td>
                            <td><strong><?php echo esc_html(isset($rule['name']) ? $rule['name'] : 'Rule ' . ($index + 1)); ?></strong></td>
                            <td>
                                <?php 
                                $rule_currencies = isset($rule['currencies']) ? $rule['currencies'] : array();
                                echo esc_html(implode(', ', $rule_currencies));
                                ?>
                            </td>
                            <td>
                                <?php 
                                $rule_gateways = isset($rule['gateways']) ? $rule['gateways'] : array();
                                $gateway_names = array();
                                foreach ($rule_gateways as $gw_id) {
                                    if (isset($available_gateways[$gw_id])) {
                                        $gateway_names[] = $available_gateways[$gw_id];
                                    }
                                }
                                echo esc_html(implode(', ', $gateway_names));
                                ?>
                            </td>
                            <td>
                                <?php 
                                $is_enabled = !isset($rule['enabled']) || $rule['enabled'];
                                echo $is_enabled ? '<span style="color: green;">●</span> ' . esc_html__('Enabled', 'commerce-control-suite') : '<span style="color: red;">●</span> ' . esc_html__('Disabled', 'commerce-control-suite');
                                ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=commerce-payment-gateway&action=edit&rule_id=' . $index), 'edit_rule')); ?>" class="button button-small">Edit</a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=commerce-payment-gateway&action=toggle&rule_id=' . $index), 'toggle_rule')); ?>" class="button button-small">
                                    <?php echo $is_enabled ? 'Disable' : 'Enable'; ?>
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=commerce-payment-gateway&action=delete&rule_id=' . $index), 'delete_rule')); ?>" 
                                   class="button button-small" 
                                   onclick="return confirm('Are you sure you want to delete this rule?');">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="notice notice-warning">
                    <p>No payment gateway rules configured yet. <a href="<?php echo esc_url(admin_url('admin.php?page=commerce-payment-gateway&action=add')); ?>">Add your first rule</a>.</p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Currency Control page
     */
    public function renderCurrencyControlPage() {
        $this->currencyControl->renderSettingsPage();
    }

    /**
     * Handle payment gateway rule deletion
     */
    private function handlePaymentGatewayRuleDeletion() {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_rule' ) ) {
            return;
        }

        $settings = $this->paymentGatewayControl->getSettings();
        $rule_id  = isset( $_GET['rule_id'] ) ? intval( $_GET['rule_id'] ) : -1;
        if ( isset( $settings['rules'][ $rule_id ] ) ) {
            unset( $settings['rules'][ $rule_id ] );
            $settings['rules'] = array_values( $settings['rules'] ); // Reindex array
            $this->paymentGatewayControl->updateSettings( $settings );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule deleted successfully!', 'commerce-control-suite' ) . '</p></div>';
        }
    }

    /**
     * Handle payment gateway rule toggle
     */
    private function handlePaymentGatewayRuleToggle() {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'toggle_rule' ) ) {
            return;
        }

        $settings = $this->paymentGatewayControl->getSettings();
        $rule_id  = isset( $_GET['rule_id'] ) ? intval( $_GET['rule_id'] ) : -1;
        if ( isset( $settings['rules'][ $rule_id ] ) ) {
            $settings['rules'][ $rule_id ]['enabled'] = ! isset( $settings['rules'][ $rule_id ]['enabled'] ) || $settings['rules'][ $rule_id ]['enabled'] ? false : true;
            $this->paymentGatewayControl->updateSettings( $settings );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule status updated!', 'commerce-control-suite' ) . '</p></div>';
        }
    }

    /**
     * Handle payment gateway rule form submission
     */
    private function handlePaymentGatewayRuleSubmission() {
        if ( ! isset( $_POST['ser_payment_gateway_rule_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ser_payment_gateway_rule_nonce'] ) ), 'ser_payment_gateway_rule_save' ) ) {
            return;
        }

        $settings = $this->paymentGatewayControl->getSettings();

        $rule = array(
            'currencies' => isset( $_POST['currencies'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['currencies'] ) ) : array(),
            'gateways'   => isset( $_POST['gateways'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['gateways'] ) ) : array(),
            'enabled'    => isset( $_POST['enabled'] ),
            'name'       => isset( $_POST['rule_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_name'] ) ) : '',
        );

        if ( isset( $_POST['rule_id'] ) && '' !== $_POST['rule_id'] ) {
            // Edit existing rule
            $rule_id = intval( $_POST['rule_id'] );
            $settings['rules'][ $rule_id ] = $rule;
        } else {
            // Add new rule
            if ( ! isset( $settings['rules'] ) ) {
                $settings['rules'] = array();
            }
            $settings['rules'][] = $rule;
        }

        $this->paymentGatewayControl->updateSettings( $settings );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rule saved successfully!', 'commerce-control-suite' ) . '</p></div>';
    }
    
    /**
     * AJAX handler to get log details
     */
    public function ajaxGetLogDetails() {
        check_ajax_referer('shipping_event_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $logId = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        
        if (!$logId) {
            wp_send_json_error('Invalid log ID');
            return;
        }
        
        global $wpdb;
        $tableName = $wpdb->prefix . $this->logTable;
        $cache_key = 'log_detail_' . $logId;
        $cache_group = 'commerce_control_suite_logs';
        
        $log = wp_cache_get( $cache_key, $cache_group );
        
        if ( false === $log ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $tableName, $logId ) );
            if ( $log ) {
                wp_cache_set( $cache_key, $log, $cache_group, 3600 );
            }
        }
        
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
    public function registerEndpoint() {
        $endpoint = $this->getEndpoint();
        
        register_rest_route('shipping/v1', '/' . $endpoint, array(
            'methods' => 'POST',
            'callback' => array($this, 'handleWebhook'),
            'permission_callback' => '__return_true', // Adjust security as needed
        ));
    }
    
    public function handleWebhook(WP_REST_Request $request) {
        // Get request data
        $body = $request->get_body();
        $params = $request->get_json_params();
        $headers = $request->get_headers();
        
        // Log the request
        $logId = $this->logRequest($body, $params, $headers);
        
        // Process the event
        try {
            $responseData = $this->processEvent($params);
            
            // Update log with success status
            $this->updateLogStatus($logId, 'success', $responseData);
            
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Event received and processed',
                'log_id' => $logId,
                'data' => $responseData
            ), 200);
            
        } catch (Exception $e) {
            // Update log with error status
            $this->updateLogStatus($logId, 'error', array('error' => $e->getMessage()));
            
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Error processing event',
                'error' => $e->getMessage(),
                'log_id' => $logId
            ), 500);
        }
    }
    
    private function processEvent($data) {
        // Extract common fields (adjust based on your shipping platform's format)
        $orderId = isset($data['order_id']) ? sanitize_text_field($data['order_id']) : null;
        $trackingNumber = isset($data['tracking_number']) ? sanitize_text_field($data['tracking_number']) : null;
        $status = isset($data['status']) ? sanitize_text_field($data['status']) : null;
        $eventType = isset($data['event_type']) ? sanitize_text_field($data['event_type']) : null;
        
        // Add your custom processing logic here
        // For example: update order meta, send notifications, etc.
        
        if ($orderId && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order) {
                // Update order meta with shipping info
                if ($trackingNumber) {
                    $order->update_meta_data('_shipping_tracking_number', $trackingNumber);
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
            'order_id' => $orderId,
            'tracking_number' => $trackingNumber,
            'status' => $status,
            'event_type' => $eventType,
            'processed_at' => current_time('mysql')
        );
    }
    
    private function logRequest($body, $params, $headers) {
        global $wpdb;
        
        $tableName = $wpdb->prefix . $this->logTable;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $tableName,
            array(
                'request_body' => $body,
                'request_params' => wp_json_encode($params),
                'request_headers' => wp_json_encode($headers),
                'ip_address' => $this->getClientIp(),
                'created_at' => current_time('mysql'),
                'status' => 'pending'
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        $insert_id = $wpdb->insert_id;
        
        // Clear related caches
        wp_cache_delete( 'total_logs', 'commerce_control_suite_stats' );
        wp_cache_delete( 'recent_logs', 'commerce_control_suite_stats' );
        wp_cache_delete( 'recent_logs_20', 'commerce_control_suite_logs' );
        
        return $insert_id;
    }
    
    private function updateLogStatus($logId, $status, $responseData = array()) {
        global $wpdb;
        
        $tableName = $wpdb->prefix . $this->logTable;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $tableName,
            array(
                'status' => $status,
                'response_data' => wp_json_encode($responseData),
                'processed_at' => current_time('mysql')
            ),
            array('id' => $logId),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        // Clear related caches
        wp_cache_delete( 'success_logs', 'commerce_control_suite_stats' );
        wp_cache_delete( 'error_logs', 'commerce_control_suite_stats' );
        wp_cache_delete( 'recent_logs', 'commerce_control_suite_stats' );
        wp_cache_delete( 'recent_logs_20', 'commerce_control_suite_logs' );
        wp_cache_delete( 'log_detail_' . $logId, 'commerce_control_suite_logs' );
    }
    
    private function getClientIp() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return $ip;
    }
    
    public function checkDatabaseTable() {
        global $wpdb;
        
        $tableName = $wpdb->prefix . $this->logTable;
        
        // Check if table exists (cached for 24 hours as schema doesn't change often)
        $cache_key = 'table_exists_' . $tableName;
        $exists = wp_cache_get( $cache_key, 'commerce_control_suite_schema' );
        
        if ( false === $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tableName ) );
            wp_cache_set( $cache_key, $exists, 'commerce_control_suite_schema', DAY_IN_SECONDS );
        }

        if ($exists != $tableName) {
            $this->createLogTable();
        }
    }
    
    public function createLogTable() {
        global $wpdb;
        
        $tableName = $wpdb->prefix . $this->logTable;
        $charsetCollate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $tableName (
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
        ) $charsetCollate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Clear table existence cache
        wp_cache_delete( 'table_exists_' . $tableName, 'commerce_control_suite_schema' );
    }
}
} // End if class_exists check

// Initialize the plugin only once
if (!function_exists('commerce_control_suite_init')) {
function commerce_control_suite_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('Commerce Control Suite requires WooCommerce to be installed and active.', 'commerce-control-suite'); ?></p>
            </div>
            <?php
        });
        return;
    }

    if (!isset($GLOBALS['commerceControlSuiteInstance']) && class_exists('CommerceControlSuite')) {
        $GLOBALS['commerceControlSuiteInstance'] = new CommerceControlSuite();
    }
}
}

// Always run initialization on plugins_loaded
add_action('plugins_loaded', 'commerce_control_suite_init');
