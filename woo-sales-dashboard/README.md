# Woo Sales Dashboard

A lightweight, read-only monthly WooCommerce sales dashboard for WordPress admin.

## Features

- Total Sales, Orders, Items Sold, Shipping, AOV, and Items / Order
- Daily lightweight SVG trends
- Same-period previous-month comparisons
- Top 5 Products ranked by Quantity or Revenue
- Variable products aggregated to their parent
- Net refund-aware revenue, item quantity, shipping, and product revenue
- Mobile-first responsive admin UI
- 5-minute current-month cache and manual Refresh
- Long-lived historical cache with targeted invalidation
- WooCommerce HPOS and legacy order-storage compatibility
- No external APIs, telemetry, CDNs, chart libraries, or frontend frameworks

## Installation

1. Download `woo-sales-dashboard.zip`.
2. In WordPress open **Plugins > Add New > Upload Plugin**.
3. Upload and activate the ZIP.
4. Open **Sales Dashboard** in the WordPress admin menu.

WooCommerce must be active. Access uses WooCommerce's `view_woocommerce_reports` capability.

## Metric semantics

Successful sales are orders currently in `processing` or `completed`. Orders in `cancelled`, `failed`, or `refunded` are shown as a secondary negative count. `pending` and `on-hold` are ignored in v1. Orders belong to the month of their WooCommerce `date_created` in the site timezone.

Total Sales is net customer-paid order revenue including tax and shipping after recorded refunds. Top Product revenue includes product line tax, excludes shipping, and is net of line refunds.

## Data safety

The plugin does not modify orders, products, customers, inventory, coupons, shipping, taxes, or WooCommerce settings. It writes only its own WordPress transient cache entries.
