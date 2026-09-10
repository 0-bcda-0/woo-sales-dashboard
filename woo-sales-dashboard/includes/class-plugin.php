<?php

defined('ABSPATH') || exit;

final class WSD_Plugin {
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self { return self::$instance ??= new self(); }

    public function boot(): void {
        if ($this->booted) return;
        $this->booted = true;

        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_notice']);
            return;
        }

        foreach ([
            'class-forecast-history-store.php',
            'class-cache.php',
            'class-order-data-provider.php',
            'class-commission-service.php',
            'class-settings-store.php',
            'class-snapshot-service.php',
            'class-dashboard-service.php',
            'class-forecast-service.php',
            'class-report-service.php',
            'class-rest-controller.php',
            'class-admin-page.php',
        ] as $file) {
            require_once WSD_PATH . 'includes/' . $file;
        }

        $forecastHistory = new WSD_Forecast_History_Store();
        $cache = new WSD_Cache($forecastHistory);
        $provider = new WSD_Order_Data_Provider();
        $settings = new WSD_Settings_Store();
        $commission = new WSD_Commission_Service();
        $snapshots = new WSD_Snapshot_Service($settings);
        $dashboard = new WSD_Dashboard_Service($commission, $snapshots);
        $forecast = new WSD_Forecast_Service();
        $reports = new WSD_Report_Service();

        (new WSD_REST_Controller($cache, $provider, $dashboard, $commission, $settings, $reports, $forecastHistory, $forecast))->register();
        (new WSD_Admin_Page())->register();
        $cache->register_invalidation_hooks();

        add_action('woocommerce_checkout_order_processed', [$snapshots, 'snapshot_order_by_id'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$snapshots, 'snapshot_order_by_id'], 20, 1);
        add_action('woocommerce_order_status_changed', [$snapshots, 'snapshot_on_status'], 20, 3);
    }

    public function woocommerce_notice(): void {
        if (! current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p>' . esc_html__('Woo Sales Dashboard requires WooCommerce to be active.', 'woo-sales-dashboard') . '</p></div>';
    }
}
