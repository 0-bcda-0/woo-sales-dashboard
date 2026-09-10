# Woo Sales Dashboard V2 Commission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend Woo Sales Dashboard V1 with a lightweight Commission tab, stable VIP/Bundle classification snapshots, monthly costs, dual-period charts, and a manually generated employer report that can be previewed, emailed, and saved as PDF.

**Architecture:** Keep the existing WooCommerce month query and extend the same order/line-item aggregation pass with commission classification. Put deterministic commission math, plugin-owned settings/snapshots, and report composition into small focused services so dashboard, email, and PDF/print output share one normalized payload and never duplicate formulas. Preserve V1 caching and admin-only asset loading; costs/settings stay outside the expensive order aggregate.

**Tech Stack:** PHP 8+, WordPress/WooCommerce CRUD APIs, WordPress REST API/options/meta/wp_mail, vanilla JavaScript, CSS, custom SVG charts, browser-native print-to-PDF for the lightweight PDF path, lightweight PHP regression harnesses.

**Spec:** `docs/superpowers/specs/2026-09-10-woo-sales-dashboard-v2-commission-design.md`

## Global Constraints

- Keep plugin slug `woo-sales-dashboard` and the existing top-level **Sales Dashboard** page.
- Permissions remain exactly V1: every V2 read/write/report action requires `view_woocommerce_reports`.
- Successful commission orders remain `processing` and `completed`; existing V1 refund/month semantics remain authoritative.
- Commissionable revenue is net product line revenue including product tax and excluding shipping/shipping tax.
- VIP role slug is exactly `nishman_vip`; VIP rate is 20%.
- Bundle rate is 20%; Bundle classification is per line item; VIP always wins over Bundle.
- Standard commission must preserve the explicit spreadsheet formula: `VPC = S / 1.4`; `commission = (S - VPC) + (VPC * 0.20)`.
- `Commission to Pay = Standard Commission + VIP Commission + Bundle Commission`.
- `Net Earnings = Commission to Pay - Marketing - Other Costs`; costs never reduce Commission to Pay.
- Shipping earns no commission and must not be subtracted a second time.
- New V2 orders/items use immutable plugin-owned classification snapshots; pre-V2 data falls back to current VIP role/current Bundle SKU list.
- Reuse the existing monthly WooCommerce order query and a single aggregation pass; never add a second full month scan for Commission.
- Current-month aggregate cache remains short-lived; historical cache remains long-lived and targeted.
- Cost edits must not invalidate/re-run expensive order aggregation.
- Continue custom SVG charts; no Chart.js, external assets, telemetry, background jobs, polling, or automatic email.
- CSS/JS load only on the Sales Dashboard admin page; no storefront asset enqueue or expensive storefront reads.
- Report sending is manual only; no PDF archive; keep only last-send timestamp + recipient per month.
- HPOS and legacy order storage remain supported through WooCommerce APIs; do not add direct order-table/postmeta analytics SQL.
- Plugin runtime remains dependency-light. For PDF, use the authenticated print-optimized report view and browser-native **Save as PDF** rather than bundling Dompdf/TCPDF unless execution proves the browser path cannot satisfy the approved workflow.

---

## File Map

### Existing files to modify

- `woo-sales-dashboard/woo-sales-dashboard.php` — bump plugin version/description for V2.
- `woo-sales-dashboard/includes/class-plugin.php` — load/wire new services and lifecycle hooks.
- `woo-sales-dashboard/includes/class-dashboard-service.php` — retain one order pass and expose line-level net values to Commission aggregation.
- `woo-sales-dashboard/includes/class-cache.php` — version aggregate cache and support classification-config revision without cost-driven invalidation.
- `woo-sales-dashboard/includes/class-rest-controller.php` — expand month payload and add narrow V2 settings/cost/report actions.
- `woo-sales-dashboard/includes/class-admin-page.php` — add Sales/Commission tabs, Settings modal, report preview/print view shell.
- `woo-sales-dashboard/assets/js/dashboard.js` — dual-series charts, tab state, costs/settings CRUD, report actions.
- `woo-sales-dashboard/assets/css/dashboard.css` — Commission layout, modal, tables, dual-line charts, report/print styling.
- `woo-sales-dashboard/README.md` — V2 features, commission semantics, storage/privacy/performance notes.
- `woo-sales-dashboard/readme.txt` — version/changelog.
- `woo-sales-dashboard/tests/test-dashboard-service.php` — preserve V1 regressions and cover combined aggregation behavior.

### New focused files

- `woo-sales-dashboard/includes/class-commission-service.php` — pure commission formulas and bucket aggregation.
- `woo-sales-dashboard/includes/class-snapshot-service.php` — VIP order + Bundle line-item snapshot read/write/fallback.
- `woo-sales-dashboard/includes/class-settings-store.php` — Bundle SKUs, report email, month costs, send audit, classification revision.
- `woo-sales-dashboard/includes/class-report-service.php` — normalize one report payload and render email-safe/print HTML from that payload.
- `woo-sales-dashboard/tests/test-commission-service.php` — formulas and bucket invariants.
- `woo-sales-dashboard/tests/test-snapshot-service.php` — immutable snapshot/fallback behavior.
- `woo-sales-dashboard/tests/test-settings-store.php` — month-keyed costs, Bundle config, send audit/revision behavior.
- `woo-sales-dashboard/tests/test-report-service.php` — report payload parity and escaping/rendering checks.

## Data Contracts

`WSD_Commission_Service::calculate(float $standardSales, float $vipSales, float $bundleSales, float $marketing = 0.0, float $otherCosts = 0.0): array`

Returns:

```php
[
    'productSales' => float,
    'standardSales' => float,
    'vipSales' => float,
    'bundleSales' => float,
    'standardVpc' => float,
    'standardCommission' => float,
    'vipCommission' => float,
    'bundleCommission' => float,
    'commissionToPay' => float,
    'marketing' => float,
    'otherCosts' => float,
    'netEarnings' => float,
]
```

`WSD_Snapshot_Service::is_vip_order(WC_Order $order): bool` and `WSD_Snapshot_Service::is_bundle_item(WC_Order $order, WC_Order_Item_Product $item): bool` return snapshotted values when present; otherwise they use the accepted historical fallback rules.

Use plugin-owned meta keys:

```php
const VIP_META = '_wsd_vip_at_order_time';       // '1' or '0'
const BUNDLE_META = '_wsd_bundle_at_order_time'; // line item '1' or '0'
```

`WSD_Settings_Store` uses compact options, not a custom DB table:

```php
wsd_v2_settings = [
    'bundle_skus' => ['SKU-1', 'SKU-2'],
    'report_email' => 'recipient@example.com',
    'classification_revision' => 1,
]

wsd_v2_months = [
    '2026-09' => [
        'marketing' => 0.0,
        'other_costs' => 0.0,
        'last_sent_at' => '',
        'last_sent_to' => '',
    ],
]
```

The month aggregate stores Commission source data but not mutable personal costs. REST/report composition merges costs afterward.

---

### Task 1: Pure Commission Engine

**Files:**
- Create: `woo-sales-dashboard/includes/class-commission-service.php`
- Create: `woo-sales-dashboard/tests/test-commission-service.php`

**Interfaces:**
- Produces `WSD_Commission_Service::calculate(...)` and `WSD_Commission_Service::bucket_commission(string $bucket, float $sales): float`.
- Later tasks must call this class for monthly totals and each daily bucket; JavaScript must never reproduce formulas.

- [ ] **Step 1: Write failing formula tests**

Cover explicit spreadsheet parity and non-negative inputs:

```php
$service = new WSD_Commission_Service();
$r = $service->calculate(1400.0, 100.0, 200.0, 50.0, 25.0);
wsd_assert(abs($r['standardVpc'] - 1000.0) < 0.001, 'VPC');
wsd_assert(abs($r['standardCommission'] - 600.0) < 0.001, 'standard commission');
wsd_assert(abs($r['vipCommission'] - 20.0) < 0.001, 'VIP commission');
wsd_assert(abs($r['bundleCommission'] - 40.0) < 0.001, 'Bundle commission');
wsd_assert(abs($r['commissionToPay'] - 660.0) < 0.001, 'gross payable');
wsd_assert(abs($r['netEarnings'] - 585.0) < 0.001, 'net earnings');
```

Also assert zero sales, negative costs being rejected/clamped according to server validation policy, and that costs do not change `commissionToPay`.

- [ ] **Step 2: Run test and verify RED**

Run: `php woo-sales-dashboard/tests/test-commission-service.php`

Expected: FAIL because `WSD_Commission_Service` does not exist.

- [ ] **Step 3: Implement minimal pure service**

Use the explicit formula, no rounded percentage shortcut:

```php
public function calculate(float $standardSales, float $vipSales, float $bundleSales, float $marketing = 0.0, float $otherCosts = 0.0): array {
    $s = max(0.0, $standardSales);
    $v = max(0.0, $vipSales);
    $b = max(0.0, $bundleSales);
    $vpc = $s / 1.4;
    $standard = ($s - $vpc) + ($vpc * 0.20);
    $vip = $v * 0.20;
    $bundle = $b * 0.20;
    $payable = $standard + $vip + $bundle;
    return [/* exact contract above */ 'netEarnings' => $payable - max(0.0,$marketing) - max(0.0,$otherCosts)];
}
```

Do not round business values internally beyond normal floating-point arithmetic; formatting/decimal presentation happens at the boundary.

- [ ] **Step 4: Run test and verify GREEN**

Run: `php woo-sales-dashboard/tests/test-commission-service.php`

Expected: `PASS commission service`.

- [ ] **Step 5: Commit**

```bash
git add woo-sales-dashboard/includes/class-commission-service.php woo-sales-dashboard/tests/test-commission-service.php
git commit -m "feat: add commission calculation service"
```

---

### Task 2: Settings, Costs, Bundle Validation and Send Audit

**Files:**
- Create: `woo-sales-dashboard/includes/class-settings-store.php`
- Create: `woo-sales-dashboard/tests/test-settings-store.php`

**Interfaces:**
- Produces `get_bundle_skus(): array`, `save_bundle_skus(array $skus): array`, `get_report_email(): string`, `save_report_email(string $email): string`, `get_month(string $month): array`, `save_costs(string $month,float $marketing,float $otherCosts): array`, `mark_sent(string $month,string $recipient,string $timestamp): array`, `classification_revision(): int`.

- [ ] **Step 1: Write failing store tests**

Stub `get_option/update_option/wc_get_product_id_by_sku/wc_get_product` and assert:

```php
$store->save_costs('2026-09', 125.50, 20.00);
$m = $store->get_month('2026-09');
wsd_assert($m['marketing'] === 125.50, 'marketing persisted');
wsd_assert($m['other_costs'] === 20.00, 'other costs persisted');
```

Assert missing month defaults to zeros; invalid `YYYY-MM` rejected; invalid/duplicate/empty SKU rejected or normalized; product and variation SKUs are accepted; report email passes `is_email`; `mark_sent()` changes only audit fields; Bundle-list change increments `classification_revision`; recipient/cost changes do not.

- [ ] **Step 2: Run test and verify RED**

Run: `php woo-sales-dashboard/tests/test-settings-store.php`

- [ ] **Step 3: Implement compact option-backed store**

Use two small non-autoload-sensitive plugin options where supported (`update_option(..., false)` for new options). Normalize SKUs with `sanitize_text_field`, trim, preserve canonical SKU text, de-duplicate case-insensitively while returning the WooCommerce canonical SKU if available.

Do not create a table or one option per month.

- [ ] **Step 4: Run test and verify GREEN**

Run: `php woo-sales-dashboard/tests/test-settings-store.php`

- [ ] **Step 5: Commit**

```bash
git add woo-sales-dashboard/includes/class-settings-store.php woo-sales-dashboard/tests/test-settings-store.php
git commit -m "feat: add commission settings and monthly costs store"
```

---

### Task 3: VIP and Bundle Snapshot Service

**Files:**
- Create: `woo-sales-dashboard/includes/class-snapshot-service.php`
- Create: `woo-sales-dashboard/tests/test-snapshot-service.php`

**Interfaces:**
- Consumes `WSD_Settings_Store` Bundle SKUs.
- Produces `snapshot_order(WC_Order $order): void`, `is_vip_order(WC_Order $order): bool`, `is_bundle_item(WC_Order $order, WC_Order_Item_Product $item): bool`.

- [ ] **Step 1: Write failing snapshot tests**

Test these exact cases:

```php
// Existing '1' snapshot remains VIP even if current user role is no longer VIP.
// Existing '0' snapshot remains non-VIP even if user later becomes VIP.
// Missing snapshot falls back to current user's roles and matches nishman_vip exactly.
// Guest/missing historical user => false.
// Bundle item snapshot '1'/'0' overrides later Bundle SKU config changes.
// Missing Bundle snapshot falls back to current SKU list.
```

Also assert `snapshot_order()` never overwrites existing snapshot meta.

- [ ] **Step 2: Run test and verify RED**

Run: `php woo-sales-dashboard/tests/test-snapshot-service.php`

- [ ] **Step 3: Implement snapshot/fallback logic**

At snapshot time resolve VIP via `$order->get_user()` / `get_user_by()` and role array. Resolve line SKU from the order item's variation/product object when present, with order-item SKU/product fallback that does not require the product to still exist later.

Write only plugin-owned order/item meta and call `$order->save()` / `$item->save()` only when a snapshot was actually missing.

- [ ] **Step 4: Register narrow lifecycle hooks**

In `class-plugin.php`, call the snapshot service on order creation/checkout completion and use an idempotent fallback hook on first successful-status transition. Never snapshot from storefront page-view hooks.

- [ ] **Step 5: Run tests and commit**

```bash
php woo-sales-dashboard/tests/test-snapshot-service.php
git add woo-sales-dashboard/includes/class-snapshot-service.php woo-sales-dashboard/includes/class-plugin.php woo-sales-dashboard/tests/test-snapshot-service.php
git commit -m "feat: snapshot VIP and bundle classifications"
```

---

### Task 4: Extend the Existing Single-Pass Aggregator

**Files:**
- Modify: `woo-sales-dashboard/includes/class-dashboard-service.php`
- Modify: `woo-sales-dashboard/tests/test-dashboard-service.php`
- Modify: `woo-sales-dashboard/includes/class-plugin.php`

**Interfaces:**
- `WSD_Dashboard_Service` receives `WSD_Commission_Service` and `WSD_Snapshot_Service`.
- `aggregate_month()` adds `commission`, daily commission fields, and `specialSales` while preserving every V1 field.

Target aggregate shape additions:

```php
'commissionSource' => [
  'productSales'=>0.0,
  'standardSales'=>0.0,
  'vipSales'=>0.0,
  'bundleSales'=>0.0,
],
'specialSales' => ['vip'=>[], 'bundle'=>[]],
// each daily bucket additionally has:
'standardSales'=>0.0,'vipSales'=>0.0,'bundleSales'=>0.0,'commissionToPay'=>0.0
```

- [ ] **Step 1: Extend fixtures before production code**

Upgrade test order/item doubles with `get_user_id`, `get_order_number`, customer display data, SKU/meta access, and product/variation SKU behavior.

- [ ] **Step 2: Add failing mixed-classification tests**

Cover one Standard line + one Bundle line, a VIP order containing a configured Bundle SKU, refund-adjusted line revenue, shipping exclusion, deleted product fallback, and special-sales rows.

Example invariant:

```php
wsd_assert(abs($a['commissionSource']['productSales'] - (
  $a['commissionSource']['standardSales'] +
  $a['commissionSource']['vipSales'] +
  $a['commissionSource']['bundleSales']
)) < 0.001, 'each product euro belongs to one commission bucket');
```

- [ ] **Step 3: Add failing daily/monthly consistency test**

Sum every daily `commissionToPay`; require equality with monthly Commission service output for the same month, within `0.001`.

- [ ] **Step 4: Implement line-level classification inside the existing loop**

Calculate `$netLine` once per line. Determine `$isVip` once per order; if VIP, route all lines to VIP. Otherwise call `is_bundle_item()` per line and route only matching lines to Bundle; all others Standard. Do not issue another month query or second order iteration.

Accumulate VIP special rows at order level (one row per VIP order with summed VIP product revenue). Accumulate Bundle special rows per Bundle line with date/order/product/SKU/revenue. Do not create Bundle special rows for VIP orders.

- [ ] **Step 5: Compute daily commission through the pure service**

After aggregation, for each day call `calculate(day standard, day vip, day bundle)` and set `commissionToPay`. Compute monthly source totals through the same service with zero costs.

- [ ] **Step 6: Preserve all V1 tests and run GREEN**

Run:

```bash
php woo-sales-dashboard/tests/test-dashboard-service.php
php woo-sales-dashboard/tests/test-commission-service.php
```

- [ ] **Step 7: Commit**

```bash
git commit -am "feat: aggregate commission in existing order pass"
```

---

### Task 5: Cache Revision and Expanded Month REST Payload

**Files:**
- Modify: `woo-sales-dashboard/includes/class-cache.php`
- Modify: `woo-sales-dashboard/includes/class-rest-controller.php`
- Modify: `woo-sales-dashboard/includes/class-plugin.php`
- Create or extend REST/cache regression harness as appropriate.

**Interfaces:**
- Month endpoint stays `GET /woo-sales-dashboard/v1/month` for compatibility.
- Response adds `previousDaily`, `commission`, `specialSales`, `costs`, and `reportAudit`.
- Settings mutation routes live under the same namespace and all use `view_woocommerce_reports`.

- [ ] **Step 1: Write failing cache-key revision test**

Require aggregate cache key to include a schema version and Bundle classification revision, e.g. `wsd_v2_r{revision}_2026_09`. This makes historical fallback data naturally miss after Bundle config changes without scanning/deleting all transients.

- [ ] **Step 2: Implement revised cache key**

Inject/read `classification_revision` without coupling costs/email to aggregate cache. Existing order hooks continue to delete the affected month's current revision key.

- [ ] **Step 3: Add previous-day series to one month response**

The endpoint already loads selected + previous aggregate; return aligned previous daily values from that same work instead of any extra request. For current month limit both series to day `wp_date('j')`; for historical months align by day position and stop previous values at selected-month length.

- [ ] **Step 4: Merge cheap mutable values after aggregate retrieval**

Call:

```php
$costs = $settings->get_month($month);
$commission = $commissionService->calculate(
    $selected['commissionSource']['standardSales'],
    $selected['commissionSource']['vipSales'],
    $selected['commissionSource']['bundleSales'],
    $costs['marketing'],
    $costs['other_costs']
);
```

This is the only month response value affected by Marketing/Other Costs; saving costs must not delete aggregate transients.

- [ ] **Step 5: Add narrow authenticated mutation routes**

Implement routes equivalent to:

```text
POST /v1/costs       {month, marketing, otherCosts}
GET  /v1/settings
POST /v1/settings    {bundleSkus, reportEmail}
POST /v1/test-email  {recipient?}
```

Validate capability through `permission_callback`; nonce remains WordPress REST nonce. Validate month/SKU/email/numbers on server.

- [ ] **Step 6: Verify failure/security paths**

Require 400 for invalid/future month, invalid SKU/email/numeric input; 403 for missing capability/nonce through normal REST auth. Ensure settings save returns normalized values.

- [ ] **Step 7: Commit**

```bash
git add woo-sales-dashboard/includes/class-cache.php woo-sales-dashboard/includes/class-rest-controller.php woo-sales-dashboard/includes/class-plugin.php woo-sales-dashboard/tests
git commit -m "feat: expose commission data and settings API"
```

---

### Task 6: Sales/Commission Tabs, Settings Modal and Dual-Series SVG Charts

**Files:**
- Modify: `woo-sales-dashboard/includes/class-admin-page.php`
- Modify: `woo-sales-dashboard/assets/js/dashboard.js`
- Modify: `woo-sales-dashboard/assets/css/dashboard.css`

**Interfaces:**
- Frontend consumes one month payload plus narrow mutation actions.
- Same shared month picker controls both tabs.

- [ ] **Step 1: Add accessible tab and modal markup**

Add `Sales | Commission` segmented tabs above content and a Settings button opening a dialog/modal containing Bundle rows and report email. Preserve V1 Sales markup/IDs or update JavaScript atomically so no regression exists between commits.

Commission view must include hero `Commission to Pay`, Product/Standard/VIP/Bundle Sales, Standard/VIP/Bundle Commission, Marketing, Other Costs, Net Earnings, special-sales tables, report action bar, and last-send audit text.

- [ ] **Step 2: Extend chart renderer to two series**

Refactor current `chart(card,key,formatter)` into a renderer accepting current and previous arrays. Use one shared Y scale across both lines, a solid current path and a CSS-dashed previous path:

```css
.wsd-chart path.line--previous{stroke:var(--wsd-muted);stroke-dasharray:5 5;opacity:.65}
```

Only current series gets the subtle area fill. Tooltip displays selected-month and previous-month values for the same day position.

- [ ] **Step 3: Apply dual series to existing Sales charts**

Revenue/Orders/Items/AOV/Items per Order all receive dashed previous-month lines. Keep Shipping without a trend only if no daily shipping series exists; do not add extra backend work solely to force that chart.

- [ ] **Step 4: Render Commission daily chart**

Plot `commissionToPay` current + previous. Ensure the partial-current-month graph stops at today's day for both series.

- [ ] **Step 5: Implement costs UX without aggregate reload**

On cost save, POST costs and update local payload's `commission.marketing`, `otherCosts`, and `netEarnings` from the server response. Do not force `refresh=1` and do not reload Woo orders.

- [ ] **Step 6: Implement Bundle CRUD and settings email UX**

Add/edit/remove rows client-side, submit complete normalized list to server, show inline SKU validation errors, and reload current month once after a successful Bundle-list change because fallback classification may have changed. Email-only changes should not reload month aggregates.

- [ ] **Step 7: Render VIP/Bundle audit tables safely**

Use `textContent` for customer/product/SKU values. Provide compact mobile card/table overflow behavior without turning this into an Orders UI.

- [ ] **Step 8: Manual UI verification**

Verify 320/375/430px, tablet, desktop; keyboard tabs/modal close/Escape; touch tooltip; zero data; long customer/product names; costs save; Bundle CRUD; current/historical month switch.

- [ ] **Step 9: Commit**

```bash
git add woo-sales-dashboard/includes/class-admin-page.php woo-sales-dashboard/assets/js/dashboard.js woo-sales-dashboard/assets/css/dashboard.css
git commit -m "feat: add commission dashboard and comparison charts"
```

---

### Task 7: Shared Report Payload, Preview and Email

**Files:**
- Create: `woo-sales-dashboard/includes/class-report-service.php`
- Create: `woo-sales-dashboard/tests/test-report-service.php`
- Modify: `woo-sales-dashboard/includes/class-rest-controller.php`
- Modify: `woo-sales-dashboard/includes/class-plugin.php`
- Modify: `woo-sales-dashboard/assets/js/dashboard.js`
- Modify: `woo-sales-dashboard/assets/css/dashboard.css`

**Interfaces:**
- `WSD_Report_Service::build_payload(string $month,array $monthResponse): array`
- `render_html(array $payload,string $mode = 'preview'): string`
- Report API consumes the already-normalized same month composition logic; no independent commission formula implementation.

- [ ] **Step 1: Write failing parity test**

Given a known month response, assert report payload fields equal dashboard fields exactly for `commissionToPay`, bucket sales/commission, costs, net earnings, Orders/Items/AOV, shipping context, daily series and special-sales rows.

- [ ] **Step 2: Write escaping/render tests**

Use customer/product names containing `<script>`/ampersands/quotes and require escaped HTML. Email mode must not include JavaScript, CSS Grid/Flex dependency for critical layout, remote images, or external URLs.

- [ ] **Step 3: Implement one normalized payload**

Include title/month/generated timestamp, currency context, all Commission metrics, sales performance, composition, dual daily values, VIP/Bundle detail, costs, net earnings and last-send audit.

- [ ] **Step 4: Implement preview HTML**

Render a polished admin modal/view from the same payload. Use simple server-rendered HTML for report content, with a lightweight inline SVG/HTML bar visualization generated from payload numbers—not a new chart library.

- [ ] **Step 5: Implement email-safe HTML**

Use a centered ~600px table-based layout with inline styles. Include the payable amount prominently, calculation table, KPIs, simple email-safe bars/table values, VIP/Bundle audit rows, costs/net, generated timestamp. The body must be useful without any attachment.

- [ ] **Step 6: Add explicit send + test-email actions**

Use `wp_mail()` with `Content-Type: text/html; charset=UTF-8`. Real send accepts selected month and recipient; rebuilds/uses the same report payload server-side. Only after successful `wp_mail()` update `last_sent_at` and `last_sent_to`. Test email must be clearly labeled test and must not alter month report audit metadata.

- [ ] **Step 7: Implement frontend Preview and Send flows**

Preview before sending; allow one-send recipient override without silently replacing saved settings email. Display success/failure and updated audit metadata.

- [ ] **Step 8: Run report tests and commit**

```bash
php woo-sales-dashboard/tests/test-report-service.php
git add woo-sales-dashboard/includes/class-report-service.php woo-sales-dashboard/includes/class-rest-controller.php woo-sales-dashboard/includes/class-plugin.php woo-sales-dashboard/assets/js/dashboard.js woo-sales-dashboard/assets/css/dashboard.css woo-sales-dashboard/tests/test-report-service.php
git commit -m "feat: add monthly commission reports and email"
```

---

### Task 8: Lightweight PDF Workflow via Authenticated Print View

**Files:**
- Modify: `woo-sales-dashboard/includes/class-admin-page.php`
- Modify: `woo-sales-dashboard/includes/class-report-service.php`
- Modify: `woo-sales-dashboard/assets/js/dashboard.js`
- Modify: `woo-sales-dashboard/assets/css/dashboard.css`

**Interfaces:**
- `Download PDF` opens an authenticated, print-optimized report generated from the same report payload; browser print is invoked so the user can choose **Save as PDF**.

- [ ] **Step 1: Add protected print/report entry path**

Use an admin page action or nonce-protected admin URL requiring `view_woocommerce_reports`, selected month, and valid nonce. Do not create a public HTML report URL.

- [ ] **Step 2: Reuse `render_html($payload, 'print')`**

No new calculations. Add `@media print` styles with A4-friendly margins, page-break rules for audit tables, visible month/generated timestamp, and no WordPress admin chrome.

- [ ] **Step 3: Wire the `Download PDF` action**

Open the print view in a new tab and call `window.print()` after rendering. Button/helper copy should make clear that the browser's **Save as PDF** creates the file. This avoids adding a multi-megabyte PDF library and keeps V2 lightweight.

- [ ] **Step 4: Manual PDF acceptance check**

Save as PDF from Chrome/Edge; verify Commission to Pay, all formulas, VIP/Bundle tables, costs/net, Croatian customer/product characters, page breaks, and that the values match Preview exactly.

- [ ] **Step 5: Commit**

```bash
git add woo-sales-dashboard/includes/class-admin-page.php woo-sales-dashboard/includes/class-report-service.php woo-sales-dashboard/assets/js/dashboard.js woo-sales-dashboard/assets/css/dashboard.css
git commit -m "feat: add lightweight PDF save workflow"
```

---

### Task 9: Versioning, Performance Audit, Packaging and Release Verification

**Files:**
- Modify: `woo-sales-dashboard/woo-sales-dashboard.php`
- Modify: `woo-sales-dashboard/README.md`
- Modify: `woo-sales-dashboard/readme.txt`
- Rebuild: `dist/woo-sales-dashboard.zip`

**Interfaces:** Produces release-ready V2 source + installable ZIP.

- [ ] **Step 1: Update release metadata**

Bump version to `2.0.0`, update description/changelog, document snapshots, Bundle Manager, costs, report workflow, print-to-PDF behavior, permissions and the fact that V2 writes only plugin-owned options/order/item meta plus transients.

- [ ] **Step 2: Run complete regression suite**

```bash
php woo-sales-dashboard/tests/smoke.php
php woo-sales-dashboard/tests/test-dashboard-service.php
php woo-sales-dashboard/tests/test-commission-service.php
php woo-sales-dashboard/tests/test-snapshot-service.php
php woo-sales-dashboard/tests/test-settings-store.php
php woo-sales-dashboard/tests/test-report-service.php
```

Every command must PASS before release.

- [ ] **Step 3: Run syntax checks**

Run `php -l` across every shipped PHP file and `node --check woo-sales-dashboard/assets/js/dashboard.js` where Node is available.

- [ ] **Step 4: Perform performance/source audit**

Verify by code inspection/search:

```text
- exactly one WooCommerce monthly order retrieval per uncached month aggregate
- no second Commission order scan
- no direct wp_posts/wp_postmeta/order-table analytics SQL
- no cron/schedule/background polling
- no external JS/CSS/fonts/telemetry
- assets enqueue only when admin hook === Sales Dashboard hook
- cost/email/audit saves do not delete aggregate cache
- Bundle config change only advances classification revision
- order/item snapshot writes are idempotent and lifecycle-only
```

- [ ] **Step 5: Manual WordPress/WooCommerce acceptance test**

Test processing/completed orders, refunds, a VIP user, mixed Bundle+Standard order, VIP+Bundle priority, role change after snapshot, Bundle-list change after snapshot, historical fallback, mobile Commission UI, dashed previous lines, costs, Settings modal, preview, test email, real email, last-send audit, and PDF save.

- [ ] **Step 6: Verify no storefront regression**

Load several public shop/product/cart/checkout pages with and without dashboard use. Confirm no V2 CSS/JS is enqueued and no V2 month aggregation runs from normal storefront page views.

- [ ] **Step 7: Build ZIP**

Create `dist/woo-sales-dashboard.zip` with one `woo-sales-dashboard/` root, excluding tests/docs/VCS/dist from the runtime plugin archive. List archive contents and verify version `2.0.0` bootstrap is present.

- [ ] **Step 8: Final spec coverage review**

Cross-check every section 1–22 of the approved V2 spec against implemented tests/manual checks. Fix any gap before claiming completion.

- [ ] **Step 9: Commit release artifact**

```bash
git add woo-sales-dashboard dist/woo-sales-dashboard.zip
git commit -m "release: prepare Woo Sales Dashboard 2.0.0"
```

## Definition of Done

- V1 Sales dashboard still works and all V1 regression tests pass.
- Commission values reproduce the approved spreadsheet rules with shipping excluded from commissionable revenue.
- Every commissionable product euro belongs to exactly one Standard/VIP/Bundle bucket; VIP wins over Bundle.
- New VIP/Bundle snapshots remain historically stable; pre-V2 fallback works as approved.
- Costs are month-specific, cheap to update, and never trigger Woo order aggregation.
- Existing and Commission charts display solid selected-month + dashed previous-month series with aligned day tooltips.
- Commission to Pay has a daily comparison graph.
- Report Preview, HTML email, and PDF-save view all consume one normalized report payload and agree numerically.
- Report sending is manual; last-send metadata is stored; no automatic scheduler/archive exists.
- Settings modal supports Bundle SKU add/edit/remove and report recipient/test email.
- All V2 actions use `view_woocommerce_reports` and nonce-authenticated/protected endpoints.
- Plugin remains HPOS-compatible, dependency-light, admin-only in normal operation, and performant.
- `dist/woo-sales-dashboard.zip` installs as V2.0.0 and contains only required runtime files.
