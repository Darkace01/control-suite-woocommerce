=== Commerce Control Suite ===
Contributors: kazeemquadri
Tags: woocommerce, order control, payment gateway, shipping, webhooks
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.2.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==

Commerce Control Suite is a comprehensive WordPress plugin that gives you complete control over your WooCommerce store operations. Manage order restrictions, payment gateway rules, and shipping event webhooks all from one powerful dashboard.

== Features ==

### 📦 Order Control

- **Enable/Disable Orders** - Turn order placement on or off globally
- **Time Restrictions** - Allow orders only during specific times of day
- **Date/Time Range** - Set specific date and time ranges for order availability
- **Conditional Restrictions:**
  - All Products
  - Specific Categories
  - Individual Products
- **Auto-hide Add to Cart Buttons** - Automatically hides purchase options when orders are disabled
- **Custom Messages** - Display custom messages to customers when orders are disabled
- **Redirect URLs** - Redirect customers attempting checkout when orders are disabled

### 💳 Payment Gateway Control

- **Currency-Based Rules** - Control which payment gateways appear for specific currencies
- **Table View Management** - Easy-to-use table interface for managing rules
- **Add/Edit/Delete Rules** - Full CRUD operations for payment rules
- **Enable/Disable Toggle** - Quickly enable or disable rules without deleting
- **Multi-Currency Support** - Select multiple currencies per rule
- **Multi-Gateway Support** - Select multiple payment gateways per rule

### 🔗 Shipping Event Webhooks

- **REST API Endpoint** - Receive event notifications from shipping platforms
- **Comprehensive Logging** - All requests logged to database
- **Detailed Log Viewer** - View request body, parameters, headers, and responses
- **Real-time Processing** - Automatically process and update orders
- **WooCommerce Integration** - Updates order meta and adds order notes

### 📊 Dashboard

- **Overview Statistics** - Quick view of all plugin features
- **Recent Activity** - View recent webhook events
- **Status Indicators** - Visual indicators for enabled/disabled features
- **Quick Links** - Easy navigation to all settings

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woocommerce-control-suite/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **WC Control Suite** in the WordPress admin menu
4. Configure your settings in each section

== Configuration ==

### Order Control Settings

1. Go to **WC Control Suite > Order Control**
2. Enable/disable order placement
3. Choose restriction type (All Products, Categories, or Specific Products)
4. Set time restrictions if needed
5. Configure date/time range if needed
6. Set redirect URL and custom message
7. Save settings

### Payment Gateway Control

1. Go to **WC Control Suite > Payment Gateway**
2. Click **Add New Rule**
3. Give your rule a name
4. Select currencies for the rule
5. Select payment gateways to show for those currencies
6. Enable the rule
7. Save

### Shipping Webhooks

1. Go to **WC Control Suite > Event Logs**
2. Configure your webhook endpoint slug
3. Copy the webhook URL
4. Provide it to your shipping platform
5. View logs as events are received

## Webhook URL Format

```
https://yoursite.com/wp-json/shipping/v1/your-endpoint-slug
```

== Frequently Asked Questions ==

### Can I restrict orders for specific products only?

Yes! Use the "Restriction Type" setting in Order Control to select specific products or categories.

### Do payment gateway rules work on the cart page?

Yes, the rules apply to both cart and checkout pages.

### What happens when orders are disabled?

- Add to Cart buttons are hidden
- Custom message is displayed on product pages
- Checkout page redirects to your specified URL
- Error messages shown if customer attempts to place order

### Can I have multiple payment gateway rules?

Yes, you can create as many rules as needed. Rules can be enabled/disabled individually.

### Is the webhook endpoint secure?

You can implement additional security by:

- Using HTTPS
- Validating request headers
- IP whitelisting (custom implementation)

== Changelog ==

### 1.2.7

- Fixed "Trying to access array offset on null" warning on fresh installations.
- Added strict dependency check for WooCommerce to prevent fatal errors.
- Improved plugin initialization with admin notices for missing dependencies.
- Synchronized version constants across the plugin.

### 1.2.1

- Renamed plugin to "Commerce Control Suite"
- Updated text domain to `commerce-control-suite`
- Added dashicons to admin pages
- Improved UI/UX with visual indicators

### 1.2.0

- Added advanced order control features
- Implemented hide add to cart functionality
- Added date/time range restrictions
- Added conditional restrictions by product/category
- Added redirect URL feature

### 1.1.2

- Fixed syntax error in payment gateway control

### 1.1.1

- Implemented table view for payment gateway rules
- Added edit/delete/enable/disable functionality
- Added rule names and status indicators

### 1.1.0

- Added dashboard with statistics
- Restructured menu navigation

### 1.0.x

- Initial releases with basic features

== Support ==

For support, feature requests, or bug reports, please contact the plugin author.

== Credits ==

Developed by Kazeem Quadri

== License ==

This plugin is licensed under the GPL v2 or later.
