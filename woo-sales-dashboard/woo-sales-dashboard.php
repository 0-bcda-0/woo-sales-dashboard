<?php
/**
 * Plugin Name: Woo Sales Dashboard
 * Description: Lightweight WooCommerce sales and commission dashboard with manual monthly reporting.
 * Version: 2.0.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Jan Jurjec
 * Text Domain: woo-sales-dashboard
 */

defined('ABSPATH') || exit;

define('WSD_VERSION', '2.0.2');
define('WSD_FILE', __FILE__);
define('WSD_PATH', plugin_dir_path(__FILE__));
define('WSD_URL', plugin_dir_url(__FILE__));

require_once WSD_PATH . 'includes/class-plugin.php';

add_action('before_woocommerce_init', static function (): void {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    WSD_Plugin::instance()->boot();
});
