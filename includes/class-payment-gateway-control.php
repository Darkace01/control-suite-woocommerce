<?php
/**
 * Payment Gateway Control
 * Manages which payment gateways appear based on currency
 */

if (!defined('ABSPATH')) {
    exit;
}

class SER_Payment_Gateway_Control {
    
    private $option_name = 'ser_payment_gateway_settings';
    
    public function __construct() {
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_gateways_by_currency'), 999);
    }
    
    /**
     * Filter payment gateways based on currency settings
     */
    public function filter_gateways_by_currency($available_gateways) {
        if (is_admin() || !is_checkout()) {
            return $available_gateways;
        }
        
        $settings = $this->get_settings();
        $current_currency = get_woocommerce_currency();
        
        if (empty($settings['rules']) || !is_array($settings['rules'])) {
            return $available_gateways;
        }
        
        $allowed_gateways = array();
        
        // Check which gateways are allowed for current currency
        foreach ($settings['rules'] as $rule) {
            $rule_currencies = isset($rule['currencies']) ? $rule['currencies'] : (isset($rule['currency']) ? array($rule['currency']) : array());
            
            if (in_array($current_currency, $rule_currencies)) {
                if (isset($rule['gateways']) && is_array($rule['gateways'])) {
                    $allowed_gateways = array_merge($allowed_gateways, $rule['gateways']);
                }
            }
        }
        
        // If no specific rules for this currency, return all gateways
        if (empty($allowed_gateways)) {
            return $available_gateways;
        }
        
        // Filter gateways
        foreach ($available_gateways as $gateway_id => $gateway) {
            if (!in_array($gateway_id, $allowed_gateways)) {
                unset($available_gateways[$gateway_id]);
            }
        }
        
        return $available_gateways;
    }
    
    /**
     * Get all available payment gateways
     */
    public function get_available_gateways() {
        $gateways = WC()->payment_gateways->payment_gateways();
        $result = array();
        
        foreach ($gateways as $gateway) {
            $result[$gateway->id] = $gateway->get_title();
        }
        
        return $result;
    }
    
    /**
     * Get active currencies
     */
    public function get_active_currencies() {
        $currencies = get_woocommerce_currencies();
        $active = array(get_woocommerce_currency() => $currencies[get_woocommerce_currency()]);
        
        // Check for multi-currency plugin support
        if (class_exists('WOOCS')) {
            global $WOOCS;
            $woocs_currencies = $WOOCS->get_currencies();
            foreach ($woocs_currencies as $currency) {
                if (isset($currencies[$currency['name']])) {
                    $active[$currency['name']] = $currencies[$currency['name']];
                }
            }
        }
        
        return $active;
    }
    
    /**
     * Get settings
     */
    public function get_settings() {
        return get_option($this->option_name, array('rules' => array()));
    }
    
    /**
     * Update settings
     */
    public function update_settings($settings) {
        return update_option($this->option_name, $settings);
    }
    
    /**
     * Get statistics for dashboard
     */
    public function get_statistics() {
        $settings = $this->get_settings();
        $rules_count = isset($settings['rules']) ? count($settings['rules']) : 0;
        
        return array(
            'total_rules' => $rules_count,
            'active_currencies' => count($this->get_active_currencies()),
            'available_gateways' => count($this->get_available_gateways())
        );
    }
}
