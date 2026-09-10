# Woo Sales Dashboard — Project Handoff

Last updated: 2026-09-10

## Read this first

This is the durable handoff for a future developer or AI agent. Before changing behavior, read this file, the approved V2 spec, current source/tests, `docs/ROADMAP.md`, and `docs/RELEASE-CHECKLIST.md`. Do not reconstruct business rules from screenshots or the original spreadsheet when the current source and these documents answer the question.

## Product purpose and architecture

Woo Sales Dashboard is deliberately small: WordPress admin only, WooCommerce CRUD/query APIs, HPOS compatible, no storefront analytics work, no scheduled reporting, telemetry, CDN, external fonts, frontend framework, or chart library.

The monthly order query and line-item traversal are shared by Sales and Commission. Do not add a second full order scan just for a new Commission widget/report field. The order provider fetches orders; dashboard service aggregates; commission service owns formulas; snapshot service owns historical classification; settings store owns Bundle SKUs/report recipient/month costs/send audit; transport controllers own authenticated actions; report service consumes the normalized data used by report outputs.

## Locked commission rules

Commissionable orders are `processing` and `completed`. Product line revenue includes product tax and line refunds, excludes shipping and shipping tax, and cannot be double-classified.

Normal automatic precedence is VIP -> Bundle -> Standard. VIP role slug is `nishman_vip`. VIP and Bundle commission are 20%. Standard preserves the spreadsheet-compatible formula: `VPC = S / 1.4`; `Standard Commission = (S - VPC) + (VPC * 0.20)`.

`Commission to Pay = Standard Commission + VIP Commission + Bundle Commission`.

`Net Earnings = Commission to Pay - Marketing - Other Costs`.

Marketing/Other Costs never reduce Commission to Pay. Shipping earns no commission and must not be subtracted a second time.

## Historical snapshots and production manual override

V2 stores `_wsd_vip_at_order_time` order meta and `_wsd_bundle_at_order_time` order-item meta. Once a V2 snapshot exists it is historical truth; later user-role or Bundle-list changes must not rewrite it. Pre-V2 orders without snapshots fall back to the current role/current Bundle SKU list and therefore can be historically imperfect.

Production testing exposed exactly that case: an old order was being treated as NishFamily/VIP even though the customer was not VIP at the time. The approved solution is the **Count as Standard** override in Special Sales Breakdown.

The override is intentionally narrow:

- VIP row: override applies to the entire order and forces its product lines to Standard commission.
- Bundle row: override applies only to that Bundle line item and forces that line to Standard.
- Override wins before automatic VIP/Bundle classification.
- The special-sales row remains visible while overridden and the checkbox remains reversible, so an admin can restore automatic special-rate treatment.
- Do not rewrite the historical VIP/Bundle snapshot to implement this override. It is separate plugin-owned override state stored in `_wsd_force_standard_commission` meta.
- Changing an override must invalidate only the affected order month, not globally flush all monthly aggregates.
- The write request must contain a valid `orderId`; Bundle additionally needs its item identifier. `itemId = 0` is valid for an order-level VIP override.

Any future change to this precedence or scope requires explicit user approval and regression tests.

## Cache design and V2.0.2 lesson

Use one transient key per month. Bundle `classification_revision` is metadata inside the cached payload and is checked on read. A mismatch is a miss and overwrites the same monthly key. Do not put revision values into transient key names.

Current-month cache is short (about five minutes); historical cache is long-lived with targeted order invalidation. Marketing/Other Costs/report-recipient changes must not force expensive order aggregation.

**Critical migration rule:** whenever the cached aggregate response shape or meaning changes, bump an explicit cache schema/version (or equivalent schema discriminator). A plugin version bump alone is not a cache migration.

This became a real production bug during the V2 rollout. A pre-upgrade cached `specialSales` payload did not contain the newly required `orderId`. The new UI rendered the checkbox but serialized no `orderId`, causing `POST /commission-override` to return `wsd_invalid_override` / HTTP 400. V2.0.2 fixed this by rejecting old aggregate schema and rebuilding the month. Every future cached-shape change needs a **warm-cache upgrade regression test**, not just cold-cache tests.

## Reporting and transport

Reporting is manual: select month -> verify/edit costs -> Preview -> Send Email and/or print/Save as PDF. Dashboard/report calculations must come from shared normalized data. Email is HTML and contains the useful report body. Test email exists. Keep only lightweight last-sent metadata; no PDF archive unless requirements explicitly change.

The initial production V2 rollout returned WordPress 404 `rest_no_route` for report preview/send endpoints even though the development package contained the routes. Production hardening therefore added a WordPress `admin-ajax.php` fallback for report actions when the REST request returns HTTP 404. The fallback decision must be based on HTTP status, not localized error-message text. Primary and fallback transports must enforce equivalent nonce/capability checks and call the same report service.

Browser print/Save-as-PDF is the approved lightweight PDF strategy. Avoid introducing Dompdf/TCPDF unless requirements materially change. Open the print window synchronously from the user gesture before asynchronous report fetching where necessary to avoid popup blockers.

## Responsive UI lesson

Production testing found large KPI values clipped vertically on desktop and mobile. The underlying problem was value text combined with restrictive overflow/line-height behavior. Regression testing must include realistic large currency values, not only short sample numbers, at desktop and mobile widths. Do not solve clipping by blindly making every card taller; fix the text/value layout safely.

## Canonical release state

V2.0.2 production hotfix source has been reconciled back into `feat/v2-commission`, together with permanent regression tests for the cache-schema/warm-cache failure and Count-as-Standard behavior. The branch version metadata and WordPress stable tag are 2.0.2. The remaining release step at the time of this note is to run the final release checklist, build/inspect the canonical binary ZIP from this reconciled source, and merge the verified feature branch into `main`.

A release ZIP is never the canonical source by itself. Source, tests, plugin header version, `WSD_VERSION`, readme stable tag/changelog, cache schema expectations, and ZIP filename/content must describe the same release. Never build the next feature on an older GitHub source while silently carrying fixes only in a ZIP.

## Performance invariants

Use WooCommerce CRUD/query APIs; no direct `wp_posts`/`wp_postmeta` analytics. Preserve the single monthly aggregation pass. Avoid per-line repeated product/database lookups. Load plugin CSS/JS only on Sales Dashboard. No automatic polling, cron, telemetry, remote assets, or storefront processing. Cache derived monthly data and invalidate narrowly.

## Safe continuation

Before packaging, a future AI should be able to answer: what data is read/written; where each business rule lives; how snapshots and manual overrides interact; what invalidates cache; whether cached schema changed; whether dashboard/report values share one source; and how both cold-cache and warm-cache upgrades were verified. If any answer is unclear, inspect source/tests and update this handoff before release.