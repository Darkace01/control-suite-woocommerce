<?php
/**
 * Order Control Manager
 * Handles enabling/disabling WooCommerce orders and timeframe settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class SER_Order_Control {
    
    private $option_name = 'ser_order_control_settings';
    
    public function __construct() {
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_order_submission'), 10, 2);
        add_action('woocommerce_checkout_process', array($this, 'block_checkout_if_disabled'));
        
        // Hide add to cart buttons when orders are disabled
        add_filter('woocommerce_is_purchasable', array($this, 'disable_purchasable'), 10, 2);
        add_action('woocommerce_single_product_summary', array($this, 'show_disabled_message'), 31);
        
        // Handle redirects
        add_action('template_redirect', array($this, 'handle_checkout_redirect'));
    }
    
    /**
     * Check if a specific product can be ordered
     */
    public function can_order_product($product_id) {
        $settings = $this->get_settings();
        
        // Check if orders are globally enabled
        if (!isset($settings['enable_orders']) || !$settings['enable_orders']) {
            return false;
        }
        
        // Check restriction type
        $restriction_type = isset($settings['restriction_type']) ? $settings['restriction_type'] : 'all';
        
        switch ($restriction_type) {
            case 'all':
                // All products affected
                return $this->is_within_allowed_period($settings);
                
            case 'categories':
                // Check if product belongs to restricted categories
                if ($this->is_product_in_restricted_categories($product_id, $settings)) {
                    return $this->is_within_allowed_period($settings);
                }
                return true;
                
            case 'products':
                // Check if product is in restricted list
                $restricted_products = isset($settings['restricted_products']) ? $settings['restricted_products'] : array();
                if (in_array($product_id, $restricted_products)) {
                    return $this->is_within_allowed_period($settings);
                }
                return true;
                
            default:
                return true;
        }
    }
    
    /**
     * Check if product is in restricted categories
     */
    private function is_product_in_restricted_categories($product_id, $settings) {
        $restricted_categories = isset($settings['restricted_categories']) ? $settings['restricted_categories'] : array();
        if (empty($restricted_categories)) {
            return false;
        }
        
        $product_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        return !empty(array_intersect($product_categories, $restricted_categories));
    }
    
    /**
     * Check if current time/date is within allowed period
     */
    private function is_within_allowed_period($settings) {
        // Check date range if enabled
        if (isset($settings['enable_date_range']) && $settings['enable_date_range']) {
            $current_datetime = current_time('timestamp');
            
            if (!empty($settings['start_datetime'])) {
                $start_datetime = strtotime($settings['start_datetime']);
                if ($current_datetime < $start_datetime) {
                    return false;
                }
            }
            
            if (!empty($settings['end_datetime'])) {
                $end_datetime = strtotime($settings['end_datetime']);
                if ($current_datetime > $end_datetime) {
                    return false;
                }
            }
        }
        
        // Check time range if enabled
        if (isset($settings['enable_timeframe']) && $settings['enable_timeframe']) {
            return $this->is_within_timeframe($settings);
        }
        
        return true;
    }
    
    /**
     * Check if orders are currently enabled (backward compatibility)
     */
    public function are_orders_enabled() {
        $settings = $this->get_settings();
        
        if (!isset($settings['enable_orders']) || !$settings['enable_orders']) {
            return false;
        }
        
        return $this->is_within_allowed_period($settings);
    }
    
    /**
     * Check if current time is within allowed timeframe
     */
    private function is_within_timeframe($settings) {
        if (empty($settings['start_time']) || empty($settings['end_time'])) {
            return true;
        }
        
        $current_time = current_time('H:i');
        $start = $settings['start_time'];
        $end = $settings['end_time'];
        
        // Handle overnight timeframes (e.g., 22:00 to 06:00)
        if ($start <= $end) {
            return ($current_time >= $start && $current_time <= $end);
        } else {
            return ($current_time >= $start || $current_time <= $end);
        }
    }
    
    /**
     * Block checkout if orders are disabled
     */
    public function block_checkout_if_disabled() {
        if (!$this->are_orders_enabled()) {
            $settings = $this->get_settings();
            $message = isset($settings['disabled_message']) ? $settings['disabled_message'] : 
                       __('Orders are currently disabled. Please try again later.', 'commerce-control-suite');
            
            wc_add_notice($message, 'error');
        }
    }
    
    /**
     * Validate order submission
     */
    public function validate_order_submission($data, $errors) {
        if (!$this->are_orders_enabled()) {
            $settings = $this->get_settings();
            $message = isset($settings['disabled_message']) ? $settings['disabled_message'] : 
                       __('Orders are currently disabled. Please try again later.', 'commerce-control-suite');
            
            $errors->add('orders_disabled', $message);
        }
    }
    
    /**
     * Disable purchasable status for products when orders are disabled
     */
    public function disable_purchasable($is_purchasable, $product) {
        if (!$this->can_order_product($product->get_id())) {
            return false;
        }
        return $is_purchasable;
    }
    
    /**
     * Show custom message on product page when orders are disabled
     */
    public function show_disabled_message() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        if (!$this->can_order_product($product->get_id())) {
            $settings = $this->get_settings();
            $message = isset($settings['disabled_message']) ? $settings['disabled_message'] : 
                       __('Orders are currently disabled. Please try again later.', 'commerce-control-suite');
            
            echo '<div class="woocommerce-info" style="margin: 20px 0;">' . esc_html($message) . '</div>';
        }
    }
    
    /**
     * Handle checkout page redirect when orders are disabled
     */
    public function handle_checkout_redirect() {
        if (is_checkout() && !$this->are_orders_enabled()) {
            $settings = $this->get_settings();
            $redirect_url = isset($settings['redirect_url']) ? $settings['redirect_url'] : home_url();
            
            if (!empty($redirect_url)) {
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
    
    /**
     * Get settings
     */
    public function get_settings() {
        return get_option($this->option_name, array(
            'enable_orders' => true,
            'enable_timeframe' => false,
            'enable_date_range' => false,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'start_datetime' => '',
            'end_datetime' => '',
            'restriction_type' => 'all',
            'restricted_categories' => array(),
            'restricted_products' => array(),
            'redirect_url' => '',
            'disabled_message' => __('Orders are currently disabled. Please try again later.', 'commerce-control-suite')
        ));
    }
    
    /**
     * Update settings
     */
    public function update_settings($settings) {
        return update_option($this->option_name, $settings);
    }
    
    /**
     * Get order statistics
     */
    public function get_statistics() {
        $settings = $this->get_settings();
        
        return array(
            'orders_enabled' => $settings['enable_orders'],
            'timeframe_enabled' => isset($settings['enable_timeframe']) ? $settings['enable_timeframe'] : false,
            'current_status' => $this->are_orders_enabled() ? 'active' : 'disabled',
            'start_time' => isset($settings['start_time']) ? $settings['start_time'] : '',
            'end_time' => isset($settings['end_time']) ? $settings['end_time'] : ''
        );
    }
}
