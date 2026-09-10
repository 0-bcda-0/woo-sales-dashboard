<?php

defined('ABSPATH') || exit;

final class WSD_Admin_Page {
    private string $hook = '';

    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        $this->hook = add_menu_page('Sales Dashboard', 'Sales Dashboard', 'view_woocommerce_reports', 'woo-sales-dashboard', [$this, 'render'], 'dashicons-chart-area', 56);
    }

    public function assets(string $hook): void {
        if ($hook !== $this->hook) return;
        wp_enqueue_style('wsd-dashboard', WSD_URL . 'assets/css/dashboard.css', [], WSD_VERSION);
        wp_enqueue_script('wsd-dashboard', WSD_URL . 'assets/js/dashboard.js', [], WSD_VERSION, true);
        wp_localize_script('wsd-dashboard', 'WSD_CONFIG', [
            'restUrl' => esc_url_raw(rest_url('woo-sales-dashboard/v1/month')),
            'nonce' => wp_create_nonce('wp_rest'),
            'currentMonth' => wp_date('Y-m'),
            'locale' => str_replace('_', '-', get_locale()),
            'currency' => get_woocommerce_currency(),
            'currencySymbol' => get_woocommerce_currency_symbol(),
        ]);
    }

    public function render(): void {
        if (! current_user_can('view_woocommerce_reports')) wp_die(esc_html__('You do not have permission to view this dashboard.', 'woo-sales-dashboard'));
        ?>
        <div class="wrap wsd-dashboard" id="wsd-dashboard">
            <header class="wsd-header">
                <div><p class="wsd-eyebrow">WooCommerce</p><h1>Sales Dashboard</h1><p class="wsd-subtitle">A clear view of this month’s performance.</p></div>
                <div class="wsd-controls">
                    <label class="screen-reader-text" for="wsd-month">Month</label><input id="wsd-month" type="month" max="<?php echo esc_attr(wp_date('Y-m')); ?>" value="<?php echo esc_attr(wp_date('Y-m')); ?>">
                    <button class="button wsd-refresh" id="wsd-refresh" type="button">Refresh</button>
                    <span class="wsd-updated" id="wsd-updated" aria-live="polite"></span>
                </div>
            </header>
            <div class="wsd-error" id="wsd-error" hidden><span>Dashboard data could not be loaded.</span><button class="button-link" id="wsd-retry" type="button">Retry</button></div>
            <main>
                <section class="wsd-grid" aria-label="Sales metrics">
                    <?php foreach ([['sales','Total Sales'],['orders','Orders'],['items','Items Sold'],['shipping','Shipping'],['aov','Average Order Value'],['itemsPerOrder','Items / Order']] as [$key,$label]) : ?>
                        <article class="wsd-card <?php echo $key === 'sales' ? 'wsd-card--hero' : ''; ?>" data-card="<?php echo esc_attr($key); ?>">
                            <div class="wsd-card-head"><span><?php echo esc_html($label); ?></span><span class="wsd-delta" data-delta></span></div>
                            <div class="wsd-value wsd-skeleton" data-value>—</div><div class="wsd-meta" data-meta>&nbsp;</div><div class="wsd-chart" data-chart></div>
                        </article>
                    <?php endforeach; ?>
                </section>
                <section class="wsd-products">
                    <div class="wsd-section-head"><div><p class="wsd-eyebrow">Product performance</p><h2>Top Products</h2></div><div class="wsd-segment" role="group" aria-label="Rank products by"><button type="button" class="is-active" data-rank="quantity">Quantity</button><button type="button" data-rank="revenue">Revenue</button></div></div>
                    <div id="wsd-products-list" class="wsd-products-list"><div class="wsd-product-placeholder wsd-skeleton"></div><div class="wsd-product-placeholder wsd-skeleton"></div><div class="wsd-product-placeholder wsd-skeleton"></div></div>
                </section>
            </main>
        </div>
        <?php
    }
}
