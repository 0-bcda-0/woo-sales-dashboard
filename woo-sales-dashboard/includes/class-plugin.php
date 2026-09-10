<?php

defined('ABSPATH') || exit;

final class WSD_Plugin {
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        if ($this->booted) return;
        $this->booted = true;

        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        foreach (['class-cache.php','class-order-data-provider.php','class-dashboard-service.php','class-rest-controller.php','class-admin-page.php'] as $file) {
            require_once WSD_PATH . 'includes/' . $file;
        }

        $cache = new WSD_Cache();
        $provider = new WSD_Order_Data_Provider();
        $service = new WSD_Dashboard_Service();
        (new WSD_REST_Controller($cache, $provider, $service))->register();
        (new WSD_Admin_Page())->register();
        $cache->register_invalidation_hooks();
    }

    public function woocommerce_notice(): void {
        if (! current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p>' . esc_html__('Woo Sales Dashboard requires WooCommerce to be active.', 'woo-sales-dashboard') . '</p></div>';
    }
}
