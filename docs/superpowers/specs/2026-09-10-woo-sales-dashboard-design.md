# Woo Sales Dashboard — Design Specification

Date: 2026-09-10
Status: Approved in chat; awaiting written-spec review before implementation planning
Repository: `0-bcda-0/woo-sales-dashboard`
Plugin slug: `woo-sales-dashboard`

## 1. Goal

Build a production-ready, lightweight, read-only WooCommerce sales dashboard for WordPress admin. The dashboard must be fast, mobile-first, visually modern, compatible with both WooCommerce HPOS and legacy order storage, and must not modify WooCommerce or WordPress business data.

The plugin will expose a single top-level WordPress admin page named **Sales Dashboard**. All dashboard information appears on one screen. The product is intentionally focused on monthly sales analytics only.

## 2. Scope

### Included in v1

- Monthly sales dashboard with month picker.
- Current month selected by default.
- Any previous month selectable.
- Future months disabled.
- One-screen layout.
- Mobile-first responsive UI.
- Main KPIs:
  - Total Sales
  - Orders
  - Items Sold
  - Shipping
  - Average Order Value
  - Items per Order
- Previous-period percentage comparison on the main KPI metrics where applicable.
- Daily trend sparklines/area charts where useful.
- Top 5 Products with `Quantity | Revenue` toggle.
- Manual refresh for the current month.
- `Updated HH:mm` indicator for the current month.
- Skeleton loading state.
- Inline error state with Retry action.
- Graceful zero-data state.
- Plugin-owned caching.
- HPOS + legacy WooCommerce storage compatibility.

### Explicitly excluded from v1

- Weekly views.
- Yearly views.
- Custom date ranges.
- Coupons/discount analytics.
- Separate refund card.
- Best-day card.
- Payment-method breakdown.
- New vs returning customers.
- Separate VAT/tax card.
- Custom role/permission system.
- Settings page.
- External telemetry.
- External APIs or SaaS dependencies.
- React, Vue, Angular, Chart.js, or other large frontend frameworks/charting libraries.
- Multi-currency support beyond the store's WooCommerce currency formatting.

The architecture should remain modular enough to add additional metrics such as new-vs-returning customers later without redesigning the entire plugin.

## 3. Business Rules

### 3.1 Order date attribution

All monthly attribution uses the WooCommerce order `date_created` value, interpreted in the WordPress/WooCommerce site timezone.

An order belongs to the month in which it was created, regardless of later payment, completion, status transition, or refund date.

### 3.2 Successful sales statuses

Successful sales metrics include only orders with status:

- `processing`
- `completed`

Guest and registered-customer orders are treated identically.

### 3.3 Unsuccessful/negative order count

The Orders card also shows a secondary count for orders with status:

- `cancelled`
- `failed`
- `refunded`

`pending` and `on-hold` orders are excluded from both the main successful count and this secondary count in v1.

### 3.4 Total Sales

Total Sales represents the amount paid by customers for successful orders, including:

- product totals,
- VAT/tax,
- shipping,

and reduced by any applicable partial or full refunds.

The result is therefore net customer-paid revenue for orders created in the selected month.

### 3.5 Orders

Main value: number of successful orders (`processing + completed`).

Secondary value: number of `cancelled + failed + refunded` orders.

The daily trend uses successful orders only.

### 3.6 Items Sold

Items Sold is the sum of line-item quantities from successful orders.

Example: 3 shampoos + 2 gels = 5 items sold.

It is not a count of distinct products.

When WooCommerce records a partial line-item quantity refund, Items Sold is reduced by the refunded quantity so that this metric represents net sold quantity. A fully refunded order is already excluded from successful sales when its order status is `refunded`.

### 3.7 Shipping

Shipping card main value: total actual WooCommerce shipping amount charged on successful orders during the selected month, reduced by any recorded shipping refund so that the metric remains net.

Secondary values:

- count of successful orders with net paid shipping,
- count of successful orders with zero net shipping.

The plugin must read actual order shipping totals and must not hardcode the store's current €5 / free-above-threshold shipping rule.

### 3.8 Average Order Value

`AOV = Net Total Sales / Successful Order Count`

If there are zero successful orders, AOV is `0` and division-by-zero must be avoided.

### 3.9 Items per Order

`Items per Order = Total Net Items Sold / Successful Order Count`

If there are zero successful orders, the metric is `0`.

### 3.10 Top Products

Show the top 5 parent products for the selected month.

Variable-product variations are aggregated under the parent product. Variations are not shown separately in v1.

Two ranking modes:

- Quantity
- Revenue

Each product row shows:

- product name,
- net sold quantity,
- product revenue.

No product images are shown.

Product revenue is the product-line amount including tax, excluding shipping, and reduced by applicable product-line refunds. Product quantity is likewise reduced by WooCommerce-recorded quantity refunds where available.

## 4. Comparison Rules

For the current partial month, compare only the same elapsed day range in the previous month.

Example:

- Sep 1–10 vs Aug 1–10

For a completed historical month, compare the full selected month with the full immediately preceding month.

Main KPI comparison percentages should be available for:

- Total Sales
- Orders
- Items Sold
- AOV
- Items per Order

Shipping may show comparison only if it improves the final visual hierarchy; it is not required as a primary comparison KPI.

Comparison behavior must handle a previous-period zero value safely and avoid misleading infinite percentages. When percentage change is mathematically undefined, the UI should show a neutral state rather than `∞%`.

## 5. Architecture

### 5.1 Chosen approach

Use official WooCommerce order CRUD/query APIs and aggregate the selected month in PHP.

Do not query `wp_posts` / `wp_postmeta` directly.

Do not depend on WooCommerce Analytics lookup tables as the primary source of truth.

Rationale:

- strongest compatibility with HPOS and legacy storage,
- easiest path to source-of-truth accuracy,
- low maintenance risk,
- store volume is approximately 100 orders/month, so a direct monthly aggregation on cache miss is comfortably within scope,
- caching prevents repeated aggregation work.

### 5.2 Backend responsibilities

Backend components should be separated by responsibility:

1. Plugin bootstrap / compatibility declaration.
2. Admin page registration.
3. REST endpoint/controller.
4. Order-data provider using WooCommerce APIs.
5. Dashboard aggregation service.
6. Cache service.
7. Cache invalidation hooks.

The implementation should remain small and avoid unnecessary abstraction, while still keeping data access, aggregation, transport, and presentation concerns separated.

### 5.3 Frontend responsibilities

Frontend responsibilities:

- render dashboard shell immediately,
- display skeletons while loading,
- issue one authenticated request for the selected month,
- render all KPI cards from the single response,
- render custom SVG daily charts,
- support hover and touch/tap tooltips,
- update month without full-page reload,
- support Retry on failure,
- allow current-month manual Refresh,
- render Top Products ranking and Quantity/Revenue toggle.

No frontend build step is required for v1. Assets should be plain shipped CSS and vanilla JavaScript.

## 6. Data Flow

1. User opens **Sales Dashboard**.
2. WordPress renders the lightweight admin shell and loads plugin CSS/JS only on this page.
3. Frontend requests one payload for the selected `YYYY-MM` month.
4. REST endpoint:
   - verifies nonce,
   - verifies capability,
   - validates month,
   - rejects future months,
   - checks plugin cache.
5. If cached monthly aggregate exists, return it.
6. On cache miss:
   - query orders belonging to the selected month,
   - aggregate all required successful/secondary-order metrics in a single logical pass,
   - collect daily series,
   - collect parent-product quantity/revenue aggregates,
   - cache the monthly aggregate.
7. For comparison values, obtain the previous month's aggregate and apply current-vs-historical comparison rules.
8. Return one compact JSON dashboard payload.
9. Frontend updates all widgets atomically from that payload.

No card should trigger its own server request.

## 7. Caching and Performance

### 7.1 Current month

Current-month monthly aggregate cache TTL: **5 minutes**.

The UI additionally exposes a manual **Refresh** action. Refresh bypasses or invalidates the relevant current-month cache and recomputes the aggregate immediately.

The header shows `Updated HH:mm` using the site/user-relevant display time returned or derived consistently by the plugin.

### 7.2 Completed months

Completed historical months use long-lived persistent plugin cache entries.

They remain valid until an order affecting that month changes.

### 7.3 Invalidation strategy

Order-related WooCommerce hooks should invalidate only affected monthly aggregate cache entries.

Changes that can require invalidation include at minimum:

- order creation,
- order update,
- status changes,
- refunds,
- deletion/trashing where relevant.

Because comparison values are derived from independent monthly aggregates, the plugin should cache monthly source aggregates rather than a fully composed selected-month-plus-comparison response. This prevents dependency bugs: changing August invalidates the August aggregate, while a September dashboard request naturally recomputes its comparison using the new August aggregate.

The cache is plugin-owned and must not mutate any WooCommerce business entity.

### 7.4 Asset performance

- Load CSS/JS only on the dashboard page.
- No external CDN requests.
- No web fonts shipped or fetched.
- No large charting dependency.
- No automatic polling/autorefresh.
- No background jobs.
- No request-per-card architecture.

## 8. REST/API Contract

Use a dedicated namespaced WordPress REST endpoint for dashboard data.

Example conceptual route:

`/woo-sales-dashboard/v1/month`

Expected request parameters:

- `month=YYYY-MM`
- optional `refresh=1` for authorized current-month manual refresh

The exact implementation may adjust naming, but must preserve one-request-per-dashboard-load behavior.

Response should contain logically grouped data such as:

- selected month metadata,
- comparison metadata,
- update timestamp,
- KPI values and deltas,
- daily series,
- order secondary counts,
- shipping paid/free counts,
- Top Products aggregate data.

The payload should contain raw numeric values where possible; presentation formatting belongs primarily to the frontend, except where WooCommerce currency helpers are needed for locale/store consistency.

## 9. Security and Permissions

Access is restricted to users with WooCommerce's existing report-viewing capability. The implementation should use the current supported WooCommerce capability, expected to be `view_woocommerce_reports`, and verify this against the installed WooCommerce API during implementation.

Requirements:

- REST nonce verification.
- Capability check on the server for every dashboard request.
- Strict `YYYY-MM` validation.
- Future-month rejection.
- Sanitize all input.
- Escape all server-rendered output.
- Treat product/order data returned to JavaScript as untrusted presentation data and escape safely.
- No telemetry.
- No external service calls.
- No modification of orders, products, customers, stock, coupons, shipping configuration, tax configuration, or WooCommerce settings.

The plugin may write only its own operational data such as cache entries.

## 10. WooCommerce Compatibility

The plugin must support:

- WooCommerce HPOS.
- Legacy WordPress post-based WooCommerce order storage.

Use WooCommerce CRUD/query APIs rather than storage-specific SQL.

Declare HPOS compatibility using WooCommerce's supported feature-declaration mechanism when available.

If WooCommerce is inactive or unavailable, the plugin must fail gracefully rather than producing PHP fatals.

## 11. UI / Visual Design

### 11.1 Design language

Modern, premium, Material-3-inspired rather than a literal stock Material UI implementation.

Characteristics:

- generous spacing,
- modern border radii,
- strong numeric typography,
- subtle surface hierarchy,
- minimal borders,
- light, restrained shadows only if needed,
- restrained accent usage,
- smooth but brief micro-interactions,
- no dense old-style WordPress admin boxes,
- no visually noisy gradients or decorative clutter.

The dashboard should feel designed as a cohesive product, not as a collection of default WordPress metaboxes.

### 11.2 Responsive layout

Mobile-first.

Typical mobile:

- 2 KPI cards per row.

Extremely narrow viewport:

- automatically collapse to 1 KPI card per row.

Desktop:

- up to 4 KPI cards per row.

Top Products should use a responsive ranking-list presentation instead of forcing a horizontally scrolling desktop table on mobile.

### 11.3 Header

Header contains:

- `Sales Dashboard` title,
- month picker,
- current-month Refresh control,
- current-month `Updated HH:mm` status.

The header must reflow cleanly on smaller screens.

### 11.4 Charts

Use custom lightweight SVG mini area charts/sparklines.

Requirements:

- smooth path,
- subtle area fill,
- no heavy chart axes,
- hover tooltip on pointer devices,
- tap/touch tooltip on mobile,
- tooltip includes date and value,
- accessible fallback text/value context where practical.

Charts must not require Chart.js or another general-purpose visualization library.

### 11.5 Loading, errors, and empty data

Initial load:

- shell renders immediately,
- KPI/content regions show skeleton placeholders.

Error:

- dashboard layout remains visible,
- compact inline error message,
- Retry action.

No sales:

- valid dashboard state,
- numeric metrics show `0`,
- charts render empty/flat appropriately,
- Top Products displays a clear no-data state,
- no error banner.

## 12. Month Picker

The picker supports monthly granularity only.

Rules:

- current month is the initial value,
- previous months are available,
- future months are disabled,
- changing month updates the dashboard asynchronously without full-page reload.

The control should prioritize accessible, reliable mobile behavior over ornamental complexity.

## 13. Currency and Formatting

The store currently operates in EUR and v1 assumes the WooCommerce store currency context rather than implementing multi-currency analytics.

Currency output should follow WooCommerce formatting conventions and site locale where appropriate.

The plugin must not hardcode visual strings such as `€` into aggregation logic.

Dashboard interface copy is English-only in v1. Translation packs are not required.

## 14. Proposed Dashboard Hierarchy

Primary KPI region:

1. Total Sales
2. Orders
3. Items Sold
4. Shipping
5. Average Order Value
6. Items / Order

Recommended visual emphasis:

- Total Sales receives strongest hierarchy.
- Orders and Items Sold are next-tier operational metrics.
- Shipping, AOV, and Items/Order are supporting KPIs.

Top Products follows the KPI area as a wide section.

The implementation may use asymmetric card sizing if it materially improves modern responsive design, but must preserve the approved metrics and mobile clarity.

## 15. Suggested Code Structure

The exact file names can evolve during implementation planning, but the plugin should remain approximately structured as:

```text
woo-sales-dashboard/
├── woo-sales-dashboard.php
├── includes/
│   ├── class-plugin.php
│   ├── class-admin-page.php
│   ├── class-rest-controller.php
│   ├── class-dashboard-service.php
│   ├── class-order-data-provider.php
│   └── class-cache.php
├── assets/
│   ├── css/dashboard.css
│   └── js/dashboard.js
├── tests/
├── README.md
└── readme.txt
```

Avoid overengineering. If implementation proves a class can be eliminated cleanly without mixing responsibilities, prefer the simpler structure.

## 16. Testing Requirements

Implementation planning must include tests for business logic and edge cases, particularly:

- successful-status filtering,
- unsuccessful secondary counts,
- order `date_created` month attribution,
- net revenue after refunds,
- net item quantity after quantity refunds,
- net shipping after shipping refunds,
- shipping paid/free classification,
- zero-order AOV/items-per-order behavior,
- current partial-month comparisons,
- completed historical-month comparisons,
- previous-period zero handling,
- variation-to-parent product aggregation,
- Top Products ranking by quantity,
- Top Products ranking by revenue,
- future-month validation,
- cache key/invalidation behavior.

Verification should also cover:

- PHP syntax,
- WordPress/WooCommerce activation without fatal errors,
- HPOS compatibility declaration,
- endpoint authorization,
- assets loading only on the plugin page,
- responsive mobile behavior,
- touch chart interactions,
- ZIP contents and installability.

## 17. Distribution Requirements

Final implementation deliverables:

- source committed to public repository `0-bcda-0/woo-sales-dashboard`,
- implementation/design documentation in the repository,
- installable plugin ZIP,
- ZIP also committed to the repository (for example under `dist/woo-sales-dashboard.zip`),
- separate downloadable ZIP supplied directly to the user.

The installable ZIP must contain the plugin directory itself and exclude development-only artifacts that WordPress does not need at runtime where practical.

## 18. Success Criteria

v1 is successful when:

1. The plugin can be installed and activated on a current WooCommerce store without fatal errors.
2. It works with HPOS and legacy order storage.
3. It does not mutate WooCommerce business data.
4. Opening Sales Dashboard displays the current month's analytics with one frontend data request.
5. Month changes happen without full-page reload.
6. Current month can be manually refreshed.
7. Cached loads are fast and old-month data avoids unnecessary recomputation.
8. All approved metrics follow the exact business rules in this spec.
9. The UI is modern, cohesive, and highly usable on mobile.
10. Plugin CSS/JS does not load across unrelated WordPress admin pages.
11. The final ZIP installs normally through WordPress Plugins > Add Plugin > Upload Plugin.
