# Woo Sales Dashboard V2 — Commission & Monthly Report Design

Date: 2026-09-10
Status: Approved in chat; awaiting written-spec review before implementation planning
Repository: `0-bcda-0/woo-sales-dashboard`
Plugin slug: `woo-sales-dashboard`

## 1. Goal

Extend V1 with a lightweight Commission tab that reproduces the agreed commission logic from `Obracun place.xlsx`, automatically classifies normal, VIP and Bundle product revenue, stores stable historical snapshots, accepts monthly personal costs, and produces a polished monthly report that can be previewed, emailed manually, or downloaded as PDF.

V2 must preserve the V1 performance philosophy: no storefront assets, no background jobs, no automatic emails, no telemetry, no chart library, HPOS-compatible WooCommerce CRUD/query APIs, minimal queries, and month-level caching.

## 2. Navigation and permissions

The existing top-level **Sales Dashboard** admin page remains. Inside it, add two primary views:

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

The Settings modal contains a lightweight Bundle SKU Manager. Users can:

- Add a Bundle SKU.
- Edit an existing Bundle SKU row.
- Remove a Bundle SKU row.

SKU entries are validated against WooCommerce products/variations before saving. The plugin stores only its own Bundle configuration and does not modify WooCommerce products.

For new order line items after V2 is active, the plugin stores a snapshot of whether that line item was classified as Bundle at order time. Later changes to the Bundle SKU list must not alter historical V2 calculations.

For historical line items without a snapshot, the current Bundle SKU list is used as fallback.

If an order contains a Bundle SKU plus normal products, only the Bundle line item receives Bundle treatment. Normal line items remain Standard unless the entire order is VIP.

## 6. Commission formulas

The spreadsheet logic is reproduced as follows, with shipping removed from commissionable product sales from the start.

Let:

- `S` = Standard product sales
- `V` = VIP product sales
- `B` = Bundle product sales
- `M` = Marketing
- `O` = Other Costs

### Standard commission

`Standard VPC = S / 1.4`

`Standard Commission = (S - Standard VPC) + (Standard VPC * 0.20)`

This is mathematically equivalent to approximately 42.8571429% of Standard product sales, but implementation should preserve the explicit formula for auditability and spreadsheet parity.

### VIP commission

`VIP Commission = V * 0.20`

### Bundle commission

`Bundle Commission = B * 0.20`

### Amount payable

`Commission to Pay / Ukupna Brutto zarada = Standard Commission + VIP Commission + Bundle Commission`

This is the amount the employer should pay to the user.

Marketing and Other Costs do **not** reduce Commission to Pay. They are personal/post-payment costs and are informational to the employer.

### Net earnings

`Net Earnings / Netto zarada = Commission to Pay - Marketing - Other Costs`

## 7. Shipping

Shipping remains visible in Sales and may be shown in the Commission report for context, but it is never included in commissionable product revenue and is not subtracted a second time after commission calculation.

This intentionally replaces the spreadsheet's mechanical `MPC including shipping -> subtract shipping` presentation with the cleaner equivalent rule: calculate commission only on product revenue.

## 8. Monthly manual costs

Commission contains two editable values scoped by `YYYY-MM`:

- **Marketing**
- **Other Costs**

Both default to zero when no value exists. Values are persisted independently for each month and can be edited later.

Use a lightweight plugin-owned persistence mechanism optimized for tiny month-keyed data. The implementation plan may choose a compact option structure rather than a custom table unless scale or atomicity analysis shows a table is justified. Avoid unnecessary schema creation.

Changing either value must update Net Earnings immediately and invalidate only the data/report state that depends on that month; it must not trigger unnecessary WooCommerce order re-querying.

## 9. Commission dashboard UI

The Commission view follows the same visual language as V1 and remains mobile-first.

Primary hierarchy:

- Month selector shared with/consistent with Sales.
- Hero **Commission to Pay** card — the dominant number.
- Product Sales.
- Standard Sales.
- VIP Sales.
- Bundle Sales.
- Standard Commission.
- VIP Commission.
- Bundle Commission.
- Marketing editable value.
- Other Costs editable value.
- **Net Earnings**.

The calculation breakdown must be transparent enough that the employer can understand how Commission to Pay was derived.

## 10. Charts and previous-month comparison

Continue using the existing custom SVG chart implementation; do not add Chart.js or another chart dependency.

Relevant Sales and Commission charts show two series:

- Selected/current month: primary solid line.
- Previous month: secondary dashed line.

Comparison is aligned by day number: day 1 vs day 1, day 2 vs day 2, etc.

For the current partial month, both series stop at the current elapsed day. Example: on September 10, compare September 1–10 with August 1–10.

For completed historical months, render the selected month across its full length. Previous-month data is aligned to the selected month's available day positions; unmatched extra days are not artificially extended.

Tooltip shows both values for the hovered day.

Commission to Pay receives its own daily chart with the same previous-month dashed comparison. Daily commission uses the same classification and formula rules as monthly commission.

## 11. Special Sales Breakdown

The Commission view and monthly report include a compact auditable list of special-rate sales.

### VIP Sales

Show at minimum:

- Date
- Order number
- Customer
- VIP product revenue

### Bundle Sales

Show at minimum:

- Date
- Order number
- Product name
- SKU
- Bundle product revenue

VIP priority prevents duplicate Bundle rows for VIP-classified revenue.

The UI should remain concise; this is an audit breakdown, not a replacement for WooCommerce Orders.

## 12. Monthly report

The report is manually generated for the selected month and must use the exact same report data object/calculation engine as the Commission dashboard. Dashboard, preview, email and PDF must never independently recalculate formulas in different ways.

Report hierarchy:

1. Brand/title and month.
2. Large **Commission to Pay** amount with clear wording that this is the amount payable.
3. Product sales and Standard/VIP/Bundle breakdown.
4. Commission calculation breakdown.
5. Performance metrics such as Orders, Items Sold and AOV where useful.
6. Daily performance chart(s).
7. Standard/VIP/Bundle sales composition visualization where useful.
8. VIP and Bundle special-sales audit breakdown.
9. Marketing.
10. Other Costs.
11. **Net Earnings**.
12. Generated timestamp.

Marketing, Other Costs and Net Earnings are visible to the employer but are clearly separated from the payable Commission to Pay amount.

## 13. Report workflow

Report delivery is intentionally manual:

`Select month -> verify/edit costs -> Preview Report -> Send Email and/or Download PDF`

There is no cron, automatic monthly generation or automatic sending.

### Preview

Preview displays the report before sending and uses the same underlying report payload as email/PDF.

### Email

Settings stores a default employer/report recipient email. The send flow may allow changing the recipient before an individual send without forcing a permanent settings change.

Provide **Send test email**.

The email is a polished HTML email visually consistent with the dashboard while using email-safe markup/styles. It contains the useful report content directly in the email body; the recipient should not need to open the PDF to see Commission to Pay.

### PDF

Provide **Download PDF** for the same month/report. PDF content must match the report payload. Generation happens on demand only; generated PDFs are not permanently archived by default.

Implementation should prefer a lightweight, maintainable PDF strategy and avoid adding a large runtime dependency if a smaller reliable solution meets the visual/report requirements.

## 14. Report send audit

Do not build a PDF archive or report-history subsystem.

Persist only lightweight send metadata, sufficient to display information such as:

`September 2026 — last sent 3 Oct 2026 10:42 to recipient@example.com`

At minimum store last-sent timestamp and recipient per month. Re-sending updates the last-send metadata.

## 15. Performance architecture

V2 must remain lightweight.

- Reuse the existing monthly WooCommerce order query.
- Extend the existing single aggregation pass over orders/line items to calculate commission buckets and daily commission data.
- Do not perform a second full WooCommerce order scan solely for Commission.
- Keep one authenticated month-data request where practical; settings/write/report actions may use separate narrow endpoints.
- Preserve current-month short cache and historical long-lived cache behavior.
- Cache only derived data that benefits from caching.
- Bundle configuration changes invalidate only months whose fallback classification may depend on the current Bundle list; snapshotted V2 data should remain stable.
- VIP role changes must not invalidate snapshotted orders.
- Monthly cost edits should not force expensive order aggregation to be recomputed.
- Load CSS/JS only on the Sales Dashboard admin page.
- No storefront hooks that enqueue assets or perform expensive reads.
- Snapshot writes occur only as needed around order lifecycle/classification, never on storefront page views.
- No external assets, telemetry, background polling or auto-refresh.

## 16. API/data boundaries

Keep responsibilities isolated:

- **Order data provider**: fetch monthly orders via WooCommerce APIs.
- **Dashboard aggregation service**: V1 sales aggregation plus reusable line-level net values.
- **Commission service**: classification and commission formulas; deterministic/testable.
- **Snapshot service**: VIP order and Bundle line-item snapshot persistence/fallback.
- **Settings/cost store**: Bundle SKU configuration, report recipient and month-keyed costs/audit metadata.
- **REST controller(s)**: authenticated reads/writes/actions with capability and nonce checks.
- **Report service**: consumes a normalized report data object and feeds preview/email/PDF renderers.
- **UI**: rendering/interactions only; no business-formula duplication in JavaScript.

Exact class/file boundaries may be adjusted during implementation planning to fit the existing plugin without creating unnecessary classes.

## 17. Cache invalidation

Existing order changes invalidate affected sales months as in V1.

V2 adds targeted invalidation:

- Order/line changes: invalidate affected month aggregate.
- Bundle configuration changes: invalidate classification-dependent fallback caches while preserving snapshot semantics.
- Marketing/Other Costs: invalidate/report-refresh only the selected month's cheap cost/report layer, not the expensive order aggregate.
- Report recipient changes: no sales/commission aggregate invalidation.
- Sending a report: update audit metadata only.

## 18. Refunds and edge cases

- Continue V1 net refund handling for line-item quantities/revenue.
- Fully refunded/negative-status behavior remains consistent with V1 unless WooCommerce's recorded line refunds provide a more precise line-level net result.
- Commission values cannot become negative solely because a line's refund exceeds its original line amount; clamp net line revenue at zero as V1 does.
- Missing/deleted products must not break historical aggregation; use order-item data where possible.
- SKU validation is required when adding/editing Bundle rules, but historical snapshotted Bundle items remain valid even if the product/SKU later changes or disappears.
- Missing historical VIP customer account means the fallback cannot infer VIP; treat as non-VIP and avoid guessing.
- Zero previous-month values display neutral comparison rather than divide-by-zero percentages.

## 19. Security

- All V2 routes/actions require `view_woocommerce_reports` to match V1 permissions.
- Use WordPress REST nonce/authentication for admin requests.
- Sanitize and validate month, SKU, email and numeric cost inputs server-side.
- Escape all report/UI output appropriate to context.
- Email sending must be explicit user action; no arbitrary public mail endpoint.
- PDF/report generation must not expose order/customer details to unauthenticated users.

## 20. Testing and acceptance criteria

Automated/service-level tests must cover at least:

- Standard commission formula parity with spreadsheet examples.
- VIP 20% calculation.
- Bundle 20% calculation.
- Mixed order: Bundle line + Standard lines.
- VIP order containing configured Bundle SKU: VIP only, no double count.
- VIP snapshot remains stable after role changes.
- Bundle snapshot remains stable after Bundle Manager changes.
- Pre-V2 VIP fallback to current role.
- Pre-V2 Bundle fallback to current SKU configuration.
- Shipping excluded from commissionable revenue.
- Marketing/Other Costs affect only Net Earnings.
- Refund-adjusted line revenue.
- Daily Commission to Pay values sum consistently to monthly Commission to Pay, subject only to currency rounding rules.
- Current partial-month previous-period alignment.
- Historical month day alignment.
- Report payload values equal dashboard values.
- Report send metadata update.
- Permission/nonce rejection.

Manual verification must cover desktop/mobile layout, Settings modal CRUD, report preview, test email, real report email rendering, PDF generation/download, and no V2 assets/work on storefront pages.

## 21. Out of scope for V2

- Automatic/scheduled report sending.
- Permanent PDF archive.
- External ad-platform integrations.
- Automatic Marketing import.
- Multiple commission recipients/salespeople.
- Configurable commission percentages/formulas in UI.
- Guest VIP inference.
- Editing WooCommerce products/customers from this plugin.
- Third-party chart library.

## 22. Final agreed rules summary

- VIP role slug: `nishman_vip`.
- VIP rate: 20%.
- Bundle rate: 20%.
- Standard: `(S - S/1.4) + 20% * (S/1.4)`.
- VIP wins over Bundle.
- Bundle classification is per line item, not whole order.
- Shipping earns no commission.
- Commission to Pay / Brutto is what the employer pays.
- Marketing and Other Costs are paid later by the user and do not reduce employer payment.
- Net Earnings = Commission to Pay - Marketing - Other Costs.
- VIP and Bundle use V2 snapshots; historical orders fall back to current role/SKU rules.
- Bundle list is CRUD by SKU.
- Report is manual, previewable, emailable and downloadable as PDF.
- Employer can see VIP/Bundle detail, Marketing, Other Costs and Net Earnings.
- No PDF archive; keep last-send audit metadata.
- Graphs include dashed previous-month series, including Commission to Pay.
- Keep plugin fast, small and admin-only in normal operation.
