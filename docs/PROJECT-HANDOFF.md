# Woo Sales Dashboard — Project Handoff

Last updated: 2026-09-10

## Read this first

This document is the durable handoff for a future developer or AI agent. Do not reconstruct business rules from screenshots, old chat messages, or the original spreadsheet when this document and the approved V2 spec answer the question. Before changing behavior, read this file, `docs/superpowers/specs/2026-09-10-woo-sales-dashboard-v2-commission-design.md`, the current source, tests, `docs/ROADMAP.md`, and `docs/RELEASE-CHECKLIST.md`.

## Product purpose

Woo Sales Dashboard is a deliberately small WordPress/WooCommerce admin plugin for monthly sales analytics, commission calculation, and manual monthly reporting. Performance and auditability are first-class requirements. It must not become a general WooCommerce management suite.

## Architecture that must be preserved

Use WooCommerce CRUD/query APIs so HPOS and legacy order storage remain supported. Do not introduce direct analytics queries against `wp_posts`/`wp_postmeta`. Monthly Sales and Commission aggregation share the same order/line-item traversal; Commission must not trigger a second full order scan. JavaScript renders data but must not duplicate commission business formulas. CSS/JS load only on the Sales Dashboard admin page. No storefront analytics work, polling, cron reports, telemetry, CDN, external fonts, frontend framework, or chart library.

The main responsibilities are intentionally separated: order provider fetches orders; dashboard service aggregates sales and line values; commission service owns deterministic formulas; snapshot service owns historical VIP/Bundle classification; settings store owns Bundle SKUs, report recipient, month costs and send audit; REST controller owns authenticated transport/actions; report service consumes the normalized data object used by dashboard/report output.

## Locked commission rules

Commissionable orders are `processing` and `completed`. Commission is based on net product line revenue including product tax and line refunds, excluding shipping and shipping tax. Every line belongs to exactly one classification.

Priority is VIP -> Bundle -> Standard. VIP role slug is `nishman_vip`. VIP and Bundle commission are 20%. Standard uses the explicit spreadsheet-compatible formula: `VPC = S / 1.4`; `Standard Commission = (S - VPC) + (VPC * 0.20)`. Do not replace the explicit formula merely with its equivalent percentage because the explicit form is easier to audit against the historical workbook.

`Commission to Pay = Standard Commission + VIP Commission + Bundle Commission` and is the amount the employer pays. Marketing and Other Costs do not reduce that payout. `Net Earnings = Commission to Pay - Marketing - Other Costs`. Shipping earns no commission and must not be subtracted a second time.

## Historical classification

V2 introduced `_wsd_vip_at_order_time` order meta and `_wsd_bundle_at_order_time` order-item meta. Once a V2 snapshot exists it is historical truth: later user-role or Bundle-list changes must not rewrite old classification. Pre-V2 orders without snapshots fall back to current role/current Bundle SKU configuration, so that historical fallback is inherently less exact.

If a future feature adds a manual classification override, it must be explicit, auditable, narrowly scoped, and tested against the VIP -> Bundle -> Standard priority. Never silently rewrite snapshots.

## Cache design

There is one transient key per month. The Bundle `classification_revision` belongs inside the cached payload; it is checked on read. A revision mismatch is a cache miss and the same monthly key is overwritten. Do NOT put the revision in the transient key: doing so creates stale/orphaned keys. Current-month cache is short (5 minutes); historical cache is long-lived and invalidated by relevant order changes. Marketing/Other Costs and report-recipient edits must not cause expensive order re-aggregation.

When the shape or meaning of cached aggregate data changes, bump the cache schema/prefix or otherwise guarantee old payloads cannot be interpreted as the new schema. A plugin version bump alone is not sufficient cache migration.

## Reporting

Reporting is manual: select month -> verify/edit costs -> Preview -> Send Email and/or print/Save as PDF. Dashboard, preview, email and PDF/print must consume the same normalized report data. Email is HTML and must contain the useful report itself, not only a link. Send Test Email exists. Keep only lightweight last-sent metadata; do not add a PDF archive unless requirements explicitly change.

Report endpoints/actions are authenticated and require `view_woocommerce_reports`. If transport fallbacks are retained (for hosts where a REST/report route can 404), both primary and fallback paths must enforce equivalent nonce/capability checks and call the same report service; never maintain two business implementations.

## Production lessons from V2 rollout

The first V2 production cycle exposed three classes of issues worth preserving as regression knowledge: report-route 404 behavior on the target WordPress environment, mobile/KPI visual clipping, and stale-cache/data-shape problems around order identifiers after a code change. The lesson is not to patch only the visible symptom: release verification must exercise actual WordPress routing, responsive rendering, cache migration, and a warm-cache upgrade path.

A release ZIP is not the source of truth by itself. GitHub source, plugin version metadata, documentation, tests, and ZIP contents must describe the same release. Never ship a hotfix that exists only inside a locally rebuilt ZIP.

## Current repository-state warning

At the time this handoff was written, the `feat/v2-commission` branch source identifies itself as V2.0.0 and its committed distribution artifacts are V2.0.0. The repository default branch still identifies itself as V1.0.0. Production hotfix work discussed/tested after V2.0.0 must therefore be reconciled into source control before any later release is treated as canonical. A future agent must verify the actual deployed version/source and must not claim repository/production parity until that reconciliation is committed and tested.

## Rules for future implementation

Before implementing a feature, write/approve the behavior and identify whether it changes business semantics, cached payload shape, snapshots, REST contract, report contract, or permissions. Add failing tests first for business logic/regressions. Keep the existing single-pass aggregation invariant unless measurement proves a different design is necessary. Prefer extending existing normalized data over adding extra monthly requests. Any new settings should be plugin-owned compact options unless scale genuinely requires a table.

For UI changes, preserve one top-level Sales Dashboard page with Sales/Commission tabs unless a future approved design intentionally changes navigation. Keep English UI, responsive behavior, accessible states, custom lightweight SVG charts, and safe DOM rendering.

## Definition of safe continuation

A future AI should be able to answer: what data is read, what data is written, where each business rule lives, how old orders are classified, what invalidates cache, whether a feature changes the report contract, and how the release will be verified. If any answer is unclear, inspect source/tests and update this handoff before packaging.