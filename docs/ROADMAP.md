# Woo Sales Dashboard — Roadmap

Last updated: 2026-09-10

This is a feature-preparation document, not a promise that every item should be built. A future developer/AI must preserve the invariants in `PROJECT-HANDOFF.md` and create an approved design/spec before implementing material behavior changes.

## Near-term production hardening

First priority is source/release reconciliation: ensure every production hotfix is represented in GitHub source, tests, version metadata, changelog, and the final ZIP. Specifically retain regression coverage for report endpoint/routing failures, responsive KPI clipping, and stale cached aggregate payloads/order identifiers across upgrades. Test both cold-cache and warm-cache upgrade scenarios.

Add a small release/version integrity check that fails packaging when the plugin header version, `WSD_VERSION`, readme stable tag, changelog target, ZIP filename/version, and expected cache schema are inconsistent.

## Candidate features

### Manual line classification override

Useful when historical data cannot be correctly inferred from pre-V2 role/SKU state. Design it as an explicit per-order/per-line audit override, not as mutation of WooCommerce products/users or silent replacement of snapshots. Store actor/time/reason and previous/effective classification. Define precedence before coding. Add tests for VIP/Bundle/Standard conflicts and report output.

### Better report delivery diagnostics

Expose actionable errors for failed `wp_mail`, routing/authentication, and print-report access. Keep sending manual. Do not add cron or background retries without a separately approved requirement. Preserve the same normalized report payload for preview/email/PDF.

### Exportable audit data

A CSV export of the selected month's normalized commission/special-sales data could help reconciliation. Generate on demand from the already aggregated/report data; do not re-query every order separately. Escape spreadsheet-formula injection in exported cells.

### Monthly close / lock

Potentially allow a month to be marked reviewed/closed so accidental cost/config edits are obvious. This must not freeze legitimate WooCommerce refund corrections invisibly. Design semantics for reopening and late refunds before implementation.

### Report notes

Optional month-specific note for the employer. Keep it plugin-owned, sanitized, small, and included through the shared report payload so preview/email/print cannot diverge.

### Additional commission categories/rates

Do not implement generic configurable formulas casually. Current Standard/VIP/Bundle formulas are locked business rules. If future categories are needed, first design classification precedence, snapshot strategy, migration behavior, cache revision impact, reporting/audit representation, and tests. Avoid turning classification into repeated per-line database lookups.

### Performance observability for admins

If real stores become large, consider non-invasive debug timing/count information available only on demand to authorized admins. No telemetry and no persistent high-volume logs by default. Measure before optimizing.

## Features intentionally not planned

No automatic scheduled report email, PDF archive, storefront dashboard, external analytics/telemetry, Chart.js/frontend framework, direct order-table analytics, or arbitrary WooCommerce editing from this plugin unless a future approved product decision explicitly changes scope.

## Feature implementation protocol

For each material feature: inspect current source/tests and handoff; write a short design with user-visible behavior and edge cases; identify cache/snapshot/report/API impacts; add failing tests; implement the smallest compatible change; run focused and full verification; perform code review; update handoff/roadmap/changelog when architecture or behavior changes; only then run the release checklist. Never use a ZIP as the only copy of new source.