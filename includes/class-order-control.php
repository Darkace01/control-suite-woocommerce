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
    }
    
    /**
     * Check if orders are currently enabled
     */
    public function are_orders_enabled() {
        $settings = $this->get_settings();
        
        if (!isset($settings['enable_orders']) || !$settings['enable_orders']) {
            return false;
        }
        
        // Check timeframe if enabled
        if (isset($settings['enable_timeframe']) && $settings['enable_timeframe']) {
            return $this->is_within_timeframe($settings);
        }
        
        return true;
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
                       __('Orders are currently disabled. Please try again later.', 'shipping-event-receiver');
            
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
                       __('Orders are currently disabled. Please try again later.', 'shipping-event-receiver');
            
            $errors->add('orders_disabled', $message);
        }
    }
    
    /**
     * Get settings
     */
    public function get_settings() {
        return get_option($this->option_name, array(
            'enable_orders' => true,
            'enable_timeframe' => false,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'disabled_message' => __('Orders are currently disabled. Please try again later.', 'shipping-event-receiver')
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
