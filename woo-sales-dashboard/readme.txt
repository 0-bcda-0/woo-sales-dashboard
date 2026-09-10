=== Woo Sales Dashboard ===
Contributors: 0-bcda-0
Tags: woocommerce, sales, dashboard, analytics, commission
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later

A lightweight monthly WooCommerce sales and commission dashboard with manual reporting.

== Description ==

Woo Sales Dashboard adds a top-level Sales Dashboard page with Sales and Commission tabs. V2 adds Standard/VIP/Bundle commission calculation, historical classification snapshots, monthly Marketing and Other Costs, Bundle SKU settings, previous-month comparison charts, special-sales audit details, report preview, HTML email, test email and a print/Save-as-PDF workflow.

The plugin uses WooCommerce APIs, supports HPOS and legacy order storage, loads assets only on its admin page, and has no external dependencies, telemetry, scheduled reports or chart libraries.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate Woo Sales Dashboard.
3. Open Sales Dashboard in WordPress admin.
4. Configure Bundle SKUs and report email through Settings.

All dashboard and V2 actions use the `view_woocommerce_reports` capability.

== Changelog ==

= 2.0.0 =
* Added Commission tab with Standard, VIP and Bundle commission logic.
* Added VIP and Bundle classification snapshots with historical fallback.
* Added Bundle SKU Manager, monthly Marketing and Other Costs, and Net Earnings.
* Added dashed previous-month series to relevant Sales and Commission charts.
* Added VIP/Bundle audit breakdown and shared monthly report payload.
* Added manual HTML report email, test email, send audit and browser Save-as-PDF workflow.
* Preserved single-pass monthly aggregation, targeted caching and admin-only assets.

= 1.0.0 =
* Initial monthly sales dashboard release.
