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
        $base = rest_url('woo-sales-dashboard/v1/');
        wp_localize_script('wsd-dashboard', 'WSD_CONFIG', [
            'restUrl' => esc_url_raw($base . 'month'),
            'restBase' => esc_url_raw($base),
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
                <div><p class="wsd-eyebrow">WooCommerce</p><h1>Sales Dashboard</h1><p class="wsd-subtitle">Sales performance and monthly commission in one lightweight view.</p></div>
                <div class="wsd-controls">
                    <label class="screen-reader-text" for="wsd-month">Month</label>
                    <input id="wsd-month" type="month" max="<?php echo esc_attr(wp_date('Y-m')); ?>" value="<?php echo esc_attr(wp_date('Y-m')); ?>">
                    <button class="button wsd-refresh" id="wsd-refresh" type="button">Refresh</button>
                    <button class="button" id="wsd-settings-open" type="button">Settings</button>
                    <span class="wsd-updated" id="wsd-updated" aria-live="polite"></span>
                </div>
            </header>

            <div class="wsd-segment wsd-tabs" role="tablist" aria-label="Dashboard view">
                <button type="button" class="is-active" role="tab" aria-selected="true" data-tab="sales">Sales</button>
                <button type="button" role="tab" aria-selected="false" data-tab="commission">Commission</button>
            </div>

            <div class="wsd-error" id="wsd-error" hidden><span>Dashboard data could not be loaded.</span><button class="button-link" id="wsd-retry" type="button">Retry</button></div>

            <main>
                <section id="wsd-sales-view" data-view="sales">
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
                </section>

                <section id="wsd-commission-view" data-view="commission" hidden>
                    <section class="wsd-grid wsd-commission-grid" aria-label="Commission metrics">
                        <?php foreach ([
                            ['commissionToPay','Commission to Pay',true],['productSales','Product Sales',false],['standardSales','Standard Sales',false],['vipSales','VIP Sales',false],['bundleSales','Bundle Sales',false],
                            ['standardCommission','Standard Commission',false],['vipCommission','VIP Commission',false],['bundleCommission','Bundle Commission',false],['netEarnings','Net Earnings',true]
                        ] as [$key,$label,$hero]) : ?>
                            <article class="wsd-card <?php echo $hero ? 'wsd-card--hero' : ''; ?>" data-commission-card="<?php echo esc_attr($key); ?>">
                                <div class="wsd-card-head"><span><?php echo esc_html($label); ?></span><span class="wsd-delta" data-commission-delta></span></div>
                                <div class="wsd-value wsd-skeleton" data-value>—</div><div class="wsd-meta" data-meta>&nbsp;</div><div class="wsd-chart" data-chart></div>
                            </article>
                        <?php endforeach; ?>
                    </section>

                    <section class="wsd-products wsd-costs">
                        <div class="wsd-section-head"><div><p class="wsd-eyebrow">Personal costs</p><h2>Monthly Costs</h2></div><span class="wsd-meta">Costs affect Net Earnings only.</span></div>
                        <div class="wsd-form-row">
                            <label>Marketing <input id="wsd-marketing" type="number" min="0" step="0.01" inputmode="decimal"></label>
                            <label>Other Costs <input id="wsd-other-costs" type="number" min="0" step="0.01" inputmode="decimal"></label>
                            <button class="button button-primary" id="wsd-save-costs" type="button">Save Costs</button>
                            <span id="wsd-cost-status" class="wsd-meta" aria-live="polite"></span>
                        </div>
                    </section>

                    <section class="wsd-products">
                        <div class="wsd-section-head"><div><p class="wsd-eyebrow">Audit</p><h2>Special Sales Breakdown</h2></div></div>
                        <div class="wsd-special-columns">
                            <div><h3>VIP Sales</h3><div id="wsd-vip-sales" class="wsd-audit-list"></div></div>
                            <div><h3>Bundle Sales</h3><div id="wsd-bundle-sales" class="wsd-audit-list"></div></div>
                        </div>
                    </section>

                    <section class="wsd-products wsd-report-actions">
                        <div><p class="wsd-eyebrow">Monthly report</p><h2>Employer Report</h2><p id="wsd-report-audit" class="wsd-meta">Not sent yet.</p></div>
                        <div class="wsd-action-row">
                            <button class="button" id="wsd-preview-report" type="button">Preview Report</button>
                            <button class="button button-primary" id="wsd-send-report" type="button">Send Report</button>
                            <button class="button" id="wsd-pdf-report" type="button">Download PDF</button>
                        </div>
                    </section>
                </section>
            </main>

            <dialog id="wsd-settings-modal" class="wsd-dialog">
                <form method="dialog" class="wsd-dialog-card" id="wsd-settings-form">
                    <div class="wsd-section-head"><div><p class="wsd-eyebrow">Settings</p><h2>Commission Settings</h2></div><button class="button-link" value="cancel" aria-label="Close settings">Close</button></div>
                    <label class="wsd-field">Report Email<input id="wsd-report-email" type="email" autocomplete="email"></label>
                    <div class="wsd-section-head wsd-subhead"><div><h3>Bundle SKU Manager</h3><p class="wsd-meta">Only matching line items use the 20% Bundle rate.</p></div><button class="button" id="wsd-add-bundle" type="button">Add SKU</button></div>
                    <div id="wsd-bundle-list" class="wsd-bundle-list"></div>
                    <div id="wsd-settings-status" class="wsd-meta" aria-live="polite"></div>
                    <div class="wsd-action-row"><button class="button" id="wsd-test-email" type="button">Send Test Email</button><button class="button button-primary" id="wsd-save-settings" type="button">Save Settings</button></div>
                </form>
            </dialog>

            <dialog id="wsd-report-modal" class="wsd-dialog wsd-report-dialog">
                <div class="wsd-dialog-card">
                    <div class="wsd-section-head"><div><p class="wsd-eyebrow">Preview</p><h2>Monthly Report</h2></div><button class="button-link" id="wsd-report-close" type="button">Close</button></div>
                    <div id="wsd-report-preview" class="wsd-report-preview"></div>
                    <div class="wsd-form-row wsd-report-send-row"><label>Send to <input id="wsd-send-recipient" type="email"></label><button class="button button-primary" id="wsd-send-preview" type="button">Send Email</button><span id="wsd-send-status" class="wsd-meta" aria-live="polite"></span></div>
                </div>
            </dialog>
        </div>
        <?php
    }
}
