=== Woo Sales Dashboard ===
Contributors: 0-bcda-0
Tags: woocommerce, sales, dashboard, analytics, commission
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 2.0.2
License: GPLv2 or later

A lightweight monthly WooCommerce sales and commission dashboard with manual reporting.

== Description ==

Woo Sales Dashboard adds a top-level Sales Dashboard page with Sales and Commission tabs. V2 adds Standard/VIP/Bundle commission calculation, historical classification snapshots, monthly Marketing and Other Costs, Bundle SKU settings, previous-month comparison charts, special-sales audit details, report preview, HTML email, test email and a print/Save-as-PDF workflow.

The current development branch also adds lightweight current-month Projected Total Sales and Projected Net Earnings cards. Forecasting uses compact historical daily Sales/Commission summaries with recent-trend, same-month seasonal and weekday weighting. It does not add an external service, ML dependency, cron job, separate frontend request or dedicated WooCommerce order-query path. Historical warm-up is capped at two missing months per current-month request, prioritizing the same month from the previous year.

Forecast Net Earnings uses a saved non-zero current-month Marketing value when present, otherwise a recency-weighted historical Marketing estimate. Other Costs are intentionally excluded from forecast Net Earnings only; actual Commission-tab Net Earnings continues to include both Marketing and Other Costs.

The plugin uses WooCommerce APIs, supports HPOS and legacy order storage, loads assets only on its admin page, and has no external dependencies, telemetry, scheduled reports or chart libraries.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate Woo Sales Dashboard.
3. Open Sales Dashboard in WordPress admin.
4. Configure Bundle SKUs and report email through Settings.

All dashboard and V2 actions use the `view_woocommerce_reports` capability.

== Changelog ==

= Unreleased =
* Added current-month Projected Total Sales and Projected Net Earnings cards with expected ranges.
* Added lightweight deterministic forecasting using recent history, same-month seasonality, weekday behavior and bounded month-to-date pace.
* Added compact schema-versioned forecast history with month-scoped invalidation and at most two missing historical months warmed per current-month request.
* Forecast Marketing uses the saved current-month value when non-zero, otherwise historical weighted Marketing; Other Costs are excluded from forecast only.
* Preserved the existing single frontend month request and single WooCommerce order-query path.

= 2.0.2 =
* Fixed commission override failures caused by stale V2 monthly cache payloads missing order/item identifiers.
* Added an internal aggregate cache schema marker so future payload-shape changes invalidate cleanly without accumulating transient keys.

= 2.0.1 =
* Fix report Preview/Send/PDF with admin-ajax fallback when REST report routes are unavailable.
* Fix clipped KPI values on desktop and mobile.
* Add per-order VIP and per-line Bundle Count as Standard commission overrides.

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