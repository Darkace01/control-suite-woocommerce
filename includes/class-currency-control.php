<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class CommerceControlSuiteCurrencyControl
 *
 * Handles currency switching, rates, and product-specific pricing.
 */
class CommerceControlSuiteCurrencyControl {

    /**
     * The single instance of the class.
     *
     * @var CommerceControlSuiteCurrencyControl
     */
    protected static $_instance = null;

    /**
     * Main CommerceControlSuiteCurrencyControl Instance.
     *
     * Ensures only one instance of CommerceControlSuiteCurrencyControl is loaded or can be loaded.
     *
     * @static
     * @return CommerceControlSuiteCurrencyControl - Main instance.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private $option_name = 'commerce_control_suite_currency_settings';
    private $settings;

    /**
     * CommerceControlSuiteCurrencyControl Constructor.
     */
    public function __construct() {
        add_action('admin_init', array($this, 'registerSettings'));
        $this->settings = $this->getSettings();

        if ($this->settings['enable_currency_switcher']) {
            add_action('init', array($this, 'init_session'));
            add_action('init', array($this, 'set_currency_from_url'));
            add_action('add_meta_boxes', array($this, 'add_product_currency_metabox'));
            add_action('save_post', array($this, 'save_product_currency_prices'));

            add_filter('woocommerce_get_price_html', array($this, 'get_price_html'), 10, 2);
            
            add_filter('woocommerce_product_get_price', array($this, 'convert_price'), 10, 2);
            add_filter('woocommerce_product_get_regular_price', array($this, 'convert_price'), 10, 2);
            add_filter('woocommerce_product_get_sale_price', array($this, 'convert_price'), 10, 2);

            add_filter('woocommerce_currency_symbol', array($this, 'get_currency_symbol'), 10, 2);
        }
    }

    public function get_price_html($price, $product){
        if ( is_admin() ) {
            return $price;
        }

        $currency_code = $this->get_current_currency();
        $prices = get_post_meta($product->get_id(), '_currency_prices', true);

        if (!empty($prices[$currency_code])) {
            $price = wc_price($prices[$currency_code], array('currency' => $currency_code));
        }

        return $price;
    }


    /**
     * Convert price based on currency.
     *
     * @param float $price
     * @param WC_Product $product
     * @return float
     */
    public function convert_price($price, $product) {
        if ( is_admin() ) {
            return $price;
        }

        $currency_code = $this->get_current_currency();
        
        if ($currency_code === $this->settings['default_currency']) {
            return $price;
        }

        $prices = get_post_meta($product->get_id(), '_currency_prices', true);

        if (!empty($prices[$currency_code])) {
            return $prices[$currency_code];
        }

        $rate = 0;
        foreach ($this->settings['currencies'] as $currency) {
            if ($currency['code'] === $currency_code) {
                $rate = $currency['rate'];
                break;
            }
        }

        if ($rate > 0) {
            return $price * $rate;
        }

        return $price;
    }

    /**
     * Get currency symbol.
     *
     * @param string $symbol
     * @param string $currency_code
     * @return string
     */
    public function get_currency_symbol($symbol, $currency_code) {
        if ( is_admin() ) {
            return $symbol;
        }
        
        $current_currency = $this->get_current_currency();

        if ($currency_code === $current_currency) {
            foreach ($this->settings['currencies'] as $currency) {
                if ($currency['code'] === $currency_code) {
                    return $currency['symbol'];
                }
            }
        }

        return $symbol;
    }

    /**
     * Add product currency metabox.
     */
    public function add_product_currency_metabox() {
        add_meta_box(
            'commerce-control-suite-currency-prices',
            'Currency Pricing',
            array($this, 'render_product_currency_metabox'),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Render product currency metabox.
     *
     * @param WP_Post $post
     */
    public function render_product_currency_metabox($post) {
        wp_nonce_field('commerce_control_suite_save_currency_prices', 'commerce_control_suite_currency_prices_nonce');

        $prices = get_post_meta($post->ID, '_currency_prices', true);
        if (!is_array($prices)) {
            $prices = array();
        }

        echo '<p>Set specific prices for each currency. Leave blank to use the exchange rate.</p>';

        foreach ($this->settings['currencies'] as $currency) {
            $code = $currency['code'];
            $symbol = $currency['symbol'];
            $value = isset($prices[$code]) ? $prices[$code] : '';

            echo '<p>';
            echo '<label for="currency_price_' . esc_attr($code) . '">' . esc_html($symbol . ' (' . $code . ')') . '</label>';
            echo '<input type="text" id="currency_price_' . esc_attr($code) . '" name="currency_prices[' . esc_attr($code) . ']" value="' . esc_attr($value) . '" class="short wc_input_price" />';
            echo '</p>';
        }
    }

    /**
     * Save product currency prices.
     *
     * @param int $post_id
     */
    public function save_product_currency_prices($post_id) {
        if (!isset($_POST['commerce_control_suite_currency_prices_nonce']) || !wp_verify_nonce($_POST['commerce_control_suite_currency_prices_nonce'], 'commerce_control_suite_save_currency_prices')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['currency_prices']) && is_array($_POST['currency_prices'])) {
            $prices = array();
            foreach ($_POST['currency_prices'] as $code => $price) {
                $prices[sanitize_key($code)] = wc_format_decimal($price);
            }
            update_post_meta($post_id, '_currency_prices', $prices);
        }
    }

    /**
     * Initialize session.
     */
    public function init_session() {
        if (!session_id()) {
            session_start();
        }
    }

    /**
     * Set currency from URL.
     */
    public function set_currency_from_url() {
        if (isset($_GET['currency'])) {
            $currency = sanitize_text_field($_GET['currency']);
            $available_currencies = $this->get_available_currencies();
            if (array_key_exists($currency, $available_currencies)) {
                $_SESSION['commerce_control_suite_currency'] = $currency;
            }
        }
    }

    /**
     * Get the current currency.
     *
     * @return string
     */
    public function get_current_currency() {
        if (isset($_SESSION['commerce_control_suite_currency'])) {
            $currency = sanitize_text_field($_SESSION['commerce_control_suite_currency']);
            $available_currencies = $this->get_available_currencies();
            if (array_key_exists($currency, $available_currencies)) {
                return $currency;
            }
        }
        return $this->settings['default_currency'];
    }

    /**
     * Get available currencies.
     *
     * @return array
     */
    public function get_available_currencies() {
        $currencies = array(
            $this->settings['default_currency'] => $this->settings['default_currency']
        );
        foreach ($this->settings['currencies'] as $currency) {
            $currencies[$currency['code']] = $currency['code'];
        }
        return $currencies;
    }

    /**
     * Get settings from the database.
     *
     * @return array
     */
    public function getSettings() {
        $defaults = array(
            'enable_currency_switcher' => false,
            'default_currency' => get_option('woocommerce_currency'),
            'currencies' => array(),
        );

        $settings = get_option($this->option_name, array());
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Register settings.
     */
    public function registerSettings() {
        register_setting($this->option_name, $this->option_name, array($this, 'sanitizeSettings'));

        add_settings_section(
            'currency_control_general',
            'General Settings',
            null,
            'commerce-currency-control'
        );

        add_settings_field(
            'enable_currency_switcher',
            'Enable Currency Switcher',
            array($this, 'renderEnableField'),
            'commerce-currency-control',
            'currency_control_general'
        );

        add_settings_field(
            'default_currency',
            'Default Currency',
            array($this, 'renderDefaultCurrencyField'),
            'commerce-currency-control',
            'currency_control_general'
        );

        add_settings_section(
            'currency_control_rates',
            'Currency Rates',
            null,
            'commerce-currency-control'
        );

        add_settings_field(
            'currencies',
            'Currencies',
            array($this, 'renderCurrenciesField'),
            'commerce-currency-control',
            'currency_control_rates'
        );
    }

    /**
     * Render the settings page.
     */
    public function renderSettingsPage() {
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-money-alt"></span> Currency Control Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('commerce-currency-control');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render enable checkbox.
     */
    public function renderEnableField() {
        echo '<label>';
        echo '<input type="checkbox" name="' . esc_attr($this->option_name) . '[enable_currency_switcher]" value="1" ' . checked($this->settings['enable_currency_switcher'], true, false) . ' />';
        echo ' Enable the currency switcher.';
        echo '</label>';
    }

    /**
     * Render default currency dropdown.
     */
    public function renderDefaultCurrencyField() {
        $currencies = get_woocommerce_currencies();
        echo '<select name="' . esc_attr($this->option_name) . '[default_currency]">';
        foreach ($currencies as $code => $name) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($this->settings['default_currency'], $code, false) . '>' . esc_html($name . ' (' . $code . ')') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">The default currency of your store.</p>';
    }

    /**
     * Render currencies repeater field.
     */
    public function renderCurrenciesField() {
        ?>
        <table id="currency-rates-table" class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th style="width: 20%;">Currency Code</th>
                <th style="width: 20%;">Currency Symbol</th>
                <th style="width: 20%;">Exchange Rate</th>
                <th style="width: 10%;">Remove</th>
            </tr>
            </thead>
            <tbody id="currency-rates-body">
            <?php
            if (!empty($this->settings['currencies'])) {
                foreach ($this->settings['currencies'] as $index => $currency) {
                    $this->renderCurrencyRow($index, $currency);
                }
            }
            ?>
            </tbody>
        </table>
        <button type="button" class="button" id="add-currency-rate" data-option-name="<?php echo esc_attr($this->option_name); ?>">Add Currency</button>
        <?php
    }

    private function renderCurrencyRow($index, $currency) {
        ?>
        <tr class="currency-rate-row">
            <td><input type="text" name="<?php echo esc_attr($this->option_name); ?>[currencies][<?php echo esc_attr($index); ?>][code]" value="<?php echo esc_attr($currency['code']); ?>" class="regular-text" /></td>
            <td><input type="text" name="<?php echo esc_attr($this->option_name); ?>[currencies][<?php echo esc_attr($index); ?>][symbol]" value="<?php echo esc_attr($currency['symbol']); ?>" class="regular-text" /></td>
            <td><input type="number" step="0.0001" name="<?php echo esc_attr($this->option_name); ?>[currencies][<?php echo esc_attr($index); ?>][rate]" value="<?php echo esc_attr($currency['rate']); ?>" class="regular-text" /></td>
            <td><button type="button" class="button remove-currency-rate">Remove</button></td>
        </tr>
        <?php
    }

    /**
     * Sanitize settings.
     *
     * @param array $input
     * @return array
     */
    public function sanitizeSettings($input) {
        $sanitized = array();

        if (isset($input['enable_currency_switcher'])) {
            $sanitized['enable_currency_switcher'] = (bool) $input['enable_currency_switcher'];
        }

        if (isset($input['default_currency'])) {
            $sanitized['default_currency'] = sanitize_text_field($input['default_currency']);
        }

        if (isset($input['currencies']) && is_array($input['currencies'])) {
            foreach ($input['currencies'] as $currency) {
                if (empty($currency['code'])) {
                    continue;
                }
                $sanitized['currencies'][] = array(
                    'code' => sanitize_text_field($currency['code']),
                    'symbol' => sanitize_text_field($currency['symbol']),
                    'rate' => floatval($currency['rate']),
                );
            }
        }

        return $sanitized;
    }
}
