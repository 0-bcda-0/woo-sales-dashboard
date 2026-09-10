# Woo Sales Dashboard

Lightweight WooCommerce sales and commission dashboard for WordPress admin.

## V2 features

- Existing monthly Sales dashboard with net sales, orders, items, shipping, AOV and Top Products.
- Solid selected-month + dashed previous-month daily SVG trends, aligned by day.
- Commission tab with Product, Standard, VIP and Bundle sales and commission breakdown.
- Standard commission formula preserved exactly: `VPC = S / 1.4`; commission = `(S - VPC) + (VPC * 0.20)`.
- VIP (`nishman_vip`) and Bundle sales use a 20% rate; VIP always takes priority over Bundle.
- Bundle SKU Manager in Settings with add/edit/remove and WooCommerce SKU validation.
- Stable V2 order/item classification snapshots, with current-role/current-SKU fallback for pre-V2 history.
- Month-specific Marketing and Other Costs; these affect Net Earnings only, never Commission to Pay.
- VIP and Bundle audit breakdowns.
- Manual monthly report preview, HTML email, test email, send audit and print/Save-as-PDF workflow.
- No scheduled emails, PDF archive, external APIs, telemetry, CDNs, chart libraries or frontend frameworks.

## Performance

Commission is calculated in the same order/line-item pass already used for monthly Sales aggregation. Uncached months use the existing paginated WooCommerce order query; Commission does not run a second full scan. Current-month aggregate cache TTL is 5 minutes and historical cache is long-lived with order-driven invalidation. Bundle classification revision is stored inside the same per-month transient payload, avoiding orphaned cache-key variants. Monthly costs and report email live in compact plugin-owned options and do not invalidate order aggregates.

Plugin CSS and JavaScript are enqueued only on **Sales Dashboard** in WordPress admin. No dashboard assets or analytics queries run on storefront page views.

## Installation

1. Upload `woo-sales-dashboard.zip` through **Plugins > Add New > Upload Plugin**.
2. Activate **Woo Sales Dashboard**.
3. Open **Sales Dashboard** in WordPress admin.
4. Use **Settings** to add Bundle SKUs and the default report email.

WooCommerce must be active. Access and all V2 actions use WooCommerce's `view_woocommerce_reports` capability.

## Commission semantics

Only `processing` and `completed` orders are commissionable. Product line revenue includes product tax, is net of recorded line-item refunds and excludes shipping/shipping tax. Every commissionable product euro belongs to exactly one bucket: VIP, Bundle or Standard. VIP wins over Bundle.

`Commission to Pay = Standard Commission + VIP Commission + Bundle Commission`.

`Net Earnings = Commission to Pay - Marketing - Other Costs`.

Shipping earns no commission and is shown only for context in the report.

## Data written by V2

V2 writes only plugin-owned data: WordPress options/transients plus `_wsd_vip_at_order_time` order meta and `_wsd_bundle_at_order_time` order-item meta. It does not edit product prices, stock, customers, coupons, shipping or WooCommerce business settings. HPOS and legacy order storage are supported through WooCommerce CRUD/query APIs.
