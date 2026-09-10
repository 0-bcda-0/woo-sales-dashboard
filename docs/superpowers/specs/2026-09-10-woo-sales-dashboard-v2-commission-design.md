# Woo Sales Dashboard V2 — Commission & Monthly Report Design

Date: 2026-09-10
Status: Approved and implemented as V2.0.0; production hardening/release reconciliation tracked in `docs/PROJECT-HANDOFF.md` and `docs/RELEASE-CHECKLIST.md`
Repository: `0-bcda-0/woo-sales-dashboard`
Plugin slug: `woo-sales-dashboard`

## Post-production note

This document is the approved V2 product/business design. It is no longer an implementation-planning draft. For future work, treat the locked rules below as the intended semantics, but also read `docs/PROJECT-HANDOFF.md` for implementation lessons and current repository-state warnings, `docs/ROADMAP.md` for prepared future features, and `docs/RELEASE-CHECKLIST.md` before creating any ZIP. Production testing after the initial V2 implementation exposed regressions around report routing, responsive KPI clipping, and stale cached payload/data shape; these are permanent release-regression checks. Never assume a production ZIP hotfix is canonical unless the exact source is committed and versioned in GitHub.

## 1. Goal

Extend V1 with a lightweight Commission tab that reproduces the agreed commission logic from `Obracun place.xlsx`, automatically classifies normal, VIP and Bundle product revenue, stores stable historical snapshots, accepts monthly personal costs, and produces a polished monthly report that can be previewed, emailed manually, or downloaded as PDF.

V2 must preserve the V1 performance philosophy: no storefront assets, no background jobs, no automatic emails, no telemetry, no chart library, HPOS-compatible WooCommerce CRUD/query APIs, minimal queries, and month-level caching.

## 2. Navigation and permissions

The existing top-level **Sales Dashboard** remains one WordPress admin page. Inside it, add two primary views:

- **Sales** — existing V1 dashboard.
- **Commission** — new V2 commission dashboard.

A **Settings** button opens a modal for Bundle SKU management and report email settings.

Permissions remain exactly as V1: `view_woocommerce_reports` controls viewing and all V2 actions, including editing monthly costs, managing Bundle SKUs, previewing/downloading reports and sending report/test emails. No new capability split is introduced.

## 3. Commission source values

Commission is calculated only from successful WooCommerce orders (`processing` and `completed`) using the same month/date attribution and refund semantics as V1.

Shipping is not commissionable revenue. Commissionable product revenue is the net line-item product total including product tax, net of recorded line-item refunds, but excluding shipping and shipping tax.

Every commissionable line item belongs to exactly one bucket:

1. **VIP**
2. **Bundle**
3. **Standard**

VIP has priority over Bundle. Therefore, if a VIP customer buys a configured Bundle SKU, that line item is counted only as VIP and never twice.

## 4. VIP classification and snapshot

WooCommerce role slug: `nishman_vip`.

For new orders after V2 is active, the plugin stores its own immutable-at-calculation-time VIP snapshot on the order. The snapshot records whether the customer had the `nishman_vip` role for commission classification.

For historical orders that predate the snapshot, classification falls back to the customer's current WooCommerce role. This fallback is inherently less historically exact and is accepted for pre-V2 data.

Guest orders are not VIP unless a future requirement explicitly changes this rule.

Changing a customer's role after a V2 snapshot exists must not retroactively change that order's VIP classification.

## 5. Bundle SKU Manager and snapshot

The Settings modal contains a lightweight Bundle SKU Manager. Users can add, edit, and remove Bundle SKUs. SKU entries are validated against WooCommerce products/variations before saving. The plugin stores only its own Bundle configuration and does not modify WooCommerce products.

For new order line items after V2 is active, the plugin stores a snapshot of whether that line item was classified as Bundle at order time. Later changes to the Bundle SKU list must not alter historical V2 calculations. For historical line items without a snapshot, the current Bundle SKU list is used as fallback.

If an order contains a Bundle SKU plus normal products, only the Bundle line item receives Bundle treatment. Normal line items remain Standard unless the entire order is VIP.

## 6. Commission formulas

Let `S` = Standard product sales, `V` = VIP product sales, `B` = Bundle product sales, `M` = Marketing, and `O` = Other Costs.

`Standard VPC = S / 1.4`

`Standard Commission = (S - Standard VPC) + (Standard VPC * 0.20)`

This is mathematically equivalent to approximately 42.8571429% of Standard product sales, but implementation should preserve the explicit formula for auditability and spreadsheet parity.

`VIP Commission = V * 0.20`

`Bundle Commission = B * 0.20`

`Commission to Pay / Ukupna Brutto zarada = Standard Commission + VIP Commission + Bundle Commission`

This is the amount the employer should pay to the user. Marketing and Other Costs do **not** reduce Commission to Pay; they are personal/post-payment costs and informational to the employer.

`Net Earnings / Netto zarada = Commission to Pay - Marketing - Other Costs`

## 7. Shipping

Shipping remains visible in Sales and may be shown in the Commission report for context, but it is never included in commissionable product revenue and is not subtracted a second time after commission calculation. This intentionally replaces the spreadsheet's mechanical `MPC including shipping -> subtract shipping` presentation with the cleaner equivalent rule: calculate commission only on product revenue.

## 8. Monthly manual costs

Commission contains two editable values scoped by `YYYY-MM`: **Marketing** and **Other Costs**. Both default to zero. Store them as lightweight plugin-owned data. Changing either value must update Net Earnings without triggering unnecessary WooCommerce order re-querying.

## 9. Commission dashboard UI

The Commission view follows the same visual language as V1 and remains mobile-first. The primary hierarchy is Month, hero **Commission to Pay**, Product Sales, Standard/VIP/Bundle Sales, Standard/VIP/Bundle Commission, Marketing, Other Costs, and **Net Earnings**. The calculation breakdown must be transparent enough for the employer to audit how Commission to Pay was derived.

## 10. Charts and previous-month comparison

Continue using the existing custom SVG chart implementation; do not add Chart.js or another chart dependency. Relevant Sales and Commission charts show selected/current month as a primary solid line and previous month as a secondary dashed line, aligned by day number.

For the current partial month, both series stop at the current elapsed day. For completed historical months, render the selected month across its full length. Tooltip shows both values. Commission to Pay receives its own daily chart using the same classification/formula rules as monthly commission.

## 11. Special Sales Breakdown

Commission and the report include compact auditable VIP and Bundle lists. VIP rows show at minimum date, order number, customer and VIP product revenue. Bundle rows show date, order number, product name, SKU and Bundle product revenue. VIP priority prevents duplicate Bundle rows.

## 12. Monthly report

The report is manually generated and must use the exact same normalized data/calculation engine as the Commission dashboard. Dashboard, preview, email and PDF must never independently recalculate formulas in different ways.

Report hierarchy: brand/month; dominant Commission to Pay; product and Standard/VIP/Bundle breakdown; commission calculation; useful Orders/Items/AOV; daily/composition visuals where useful; VIP/Bundle audit; Marketing; Other Costs; Net Earnings; generated timestamp.

## 13. Report workflow

`Select month -> verify/edit costs -> Preview Report -> Send Email and/or Download/Save PDF`.

There is no cron, automatic monthly generation or automatic sending. Preview uses the same report payload. Settings stores a default recipient and provides **Send test email**. Email contains useful report content directly in email-safe HTML. PDF/print is on demand and not permanently archived. Production routing must be tested end-to-end; use a compatible authenticated fallback when a target WordPress environment does not expose the preferred report route reliably.

## 14. Report send audit

Do not build a PDF archive/report-history subsystem. Persist only lightweight last-sent timestamp and recipient per month. Re-sending updates the metadata.

## 15. Performance architecture

Reuse the existing monthly WooCommerce order query and single aggregation pass. Do not perform a second full scan solely for Commission. Keep one authenticated month-data request where practical; settings/write/report actions may use narrow endpoints. Preserve current-month short cache and historical long-lived cache. Monthly cost edits must not force order aggregation. Load CSS/JS only on Sales Dashboard. No storefront expensive reads/assets, external assets, telemetry, polling or auto-refresh.

## 16. API/data boundaries

Order provider fetches monthly orders. Dashboard service aggregates V1 sales and reusable line values. Commission service owns deterministic formulas. Snapshot service owns VIP/Bundle persistence/fallback. Settings store owns Bundle configuration, recipient, month costs/audit. REST/controller layer owns authenticated transport. Report service consumes the normalized report object. UI renders/interacts only and does not duplicate formulas.

## 17. Cache invalidation

Existing order changes invalidate affected months. Use one cache key per month. Store/check Bundle `classification_revision` inside the cached payload; revision mismatch is a cache miss and overwrite of that same key. Do not put revisions into cache keys. Marketing/Other Costs should refresh only cheap dependent state, recipient changes do not invalidate aggregates, and sending updates audit only. Any future cached payload shape change requires explicit cache-schema invalidation/migration.

## 18. Refunds and edge cases

Continue V1 net refund handling. Clamp net line revenue at zero if necessary. Missing/deleted products must not break history; use order-item data where possible. Historical snapshotted Bundle items remain valid even if products/SKUs later change. Missing historical VIP customer means fallback cannot infer VIP, so treat as non-VIP. Zero previous-month values use neutral comparison.

## 19. Security

All V2 actions require `view_woocommerce_reports`. Use WordPress nonce/authentication, server-side validation for month/SKU/email/numeric costs, and context-appropriate escaping. Email sending is explicit. Reports containing order/customer details must not be publicly accessible.

## 20. Testing and acceptance criteria

Automated/service tests cover Standard formula parity, VIP/Bundle 20%, mixed order, VIP-over-Bundle, snapshot stability, pre-V2 fallback, shipping exclusion, costs affecting only Net Earnings, refund-adjusted revenue, daily/monthly consistency, historical day alignment, report/dashboard parity, send audit, and permission/nonce rejection.

Manual verification covers desktop/mobile layout, Settings CRUD, report preview, test/real email, print/PDF, and absence of storefront work. Permanent regression tests/checks additionally cover report-route 404 behavior, narrow-width KPI clipping, and warm-cache upgrade/data-shape compatibility.

## 21. Out of scope for V2

Automatic report sending, PDF archive, external ad integrations, automatic Marketing import, multiple commission recipients, configurable commission formulas, guest VIP inference, WooCommerce product/customer editing, and third-party chart libraries.

## 22. Final agreed rules summary

VIP role is `nishman_vip`; VIP and Bundle are 20%; Standard uses `(S - S/1.4) + 20% * (S/1.4)`; VIP wins over Bundle; Bundle is line-level; shipping earns no commission; Commission to Pay is employer payout; costs reduce only Net Earnings; V2 snapshots stabilize history with pre-V2 fallbacks; Bundle list is SKU CRUD; reporting is manual; employer can see audit/cost/net data; no PDF archive; charts compare previous month; plugin remains fast, small and admin-only in normal operation.