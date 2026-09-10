# Woo Sales Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-ready, mobile-first, read-only WooCommerce monthly sales dashboard with lightweight custom charts, accurate net metrics, HPOS/legacy compatibility, caching, and an installable ZIP.

**Architecture:** A small WordPress plugin uses WooCommerce CRUD/query APIs as its source of truth. A single authenticated REST endpoint composes independently cached monthly aggregates, while vanilla JavaScript and CSS render the dashboard and custom SVG charts without a build step or external dependencies.

**Tech Stack:** PHP 8+/WordPress/WooCommerce APIs, WordPress REST API, vanilla JavaScript, CSS, SVG, PHPUnit-compatible pure PHP tests where practical, WordPress/WooCommerce integration verification.

**Spec:** `docs/superpowers/specs/2026-09-10-woo-sales-dashboard-design.md`

## Global Constraints

- Plugin slug: `woo-sales-dashboard`; admin page: `Sales Dashboard`.
- Read-only toward WooCommerce/WordPress business data; only plugin-owned cache may be written.
- Support WooCommerce HPOS and legacy order storage through WooCommerce CRUD/query APIs; no direct `wp_posts`/`wp_postmeta` analytics queries.
- Sales statuses: `processing` + `completed`; secondary negative count: `cancelled` + `failed` + `refunded`; ignore `pending` and `on-hold` in v1.
- Attribute orders to `date_created` in site timezone.
- Guest and registered orders are equal.
- Current-month aggregate cache TTL is 5 minutes; completed months persist until invalidated by affected order changes.
- One REST request per dashboard load/month change; no request-per-card, polling, background jobs, external APIs, telemetry, CDNs, web fonts, frontend frameworks, or chart libraries.
- UI is English-only, mobile-first, modern Material-3-inspired, with 2 KPI columns on typical mobile, 1 on very narrow screens, and up to 4 on desktop.
- Currency uses WooCommerce formatting/store currency context; do not hardcode `€` in aggregation logic.
- Future months are rejected/disabled.

---

## File Map

- `woo-sales-dashboard/woo-sales-dashboard.php` — plugin bootstrap, constants, WooCommerce dependency guard, HPOS declaration.
- `woo-sales-dashboard/includes/class-plugin.php` — wires services and hooks.
- `woo-sales-dashboard/includes/class-admin-page.php` — top-level admin page, shell markup, scoped asset enqueue/localization.
- `woo-sales-dashboard/includes/class-rest-controller.php` — namespaced month endpoint, auth, validation, refresh handling, response.
- `woo-sales-dashboard/includes/class-order-data-provider.php` — WooCommerce API order retrieval by month/status.
- `woo-sales-dashboard/includes/class-dashboard-service.php` — pure-ish aggregation, daily series, comparisons, Top Products.
- `woo-sales-dashboard/includes/class-cache.php` — per-month aggregate keys, TTL policy, get/set/delete and invalidation hooks.
- `woo-sales-dashboard/assets/js/dashboard.js` — month loading, render state, SVG charts/tooltips, Top Products toggle, refresh/retry.
- `woo-sales-dashboard/assets/css/dashboard.css` — responsive product-style admin UI, skeletons, cards, charts, accessibility states.
- `woo-sales-dashboard/tests/bootstrap.php` — minimal test bootstrap/stubs for isolated business logic.
- `woo-sales-dashboard/tests/test-dashboard-service.php` — aggregation/comparison tests.
- `woo-sales-dashboard/tests/test-cache.php` — cache-key/TTL/invalidation-policy tests.
- `woo-sales-dashboard/README.md` — developer/user overview.
- `woo-sales-dashboard/readme.txt` — WordPress plugin metadata/install notes.
- `dist/woo-sales-dashboard.zip` — final installable artifact, generated after verification.

### Task 1: Bootstrap, compatibility, and admin shell

**Files:** Create `woo-sales-dashboard/woo-sales-dashboard.php`, `woo-sales-dashboard/includes/class-plugin.php`, `woo-sales-dashboard/includes/class-admin-page.php`, `woo-sales-dashboard/assets/js/dashboard.js`, `woo-sales-dashboard/assets/css/dashboard.css`.

**Interfaces:** Produces `WSD_Plugin::instance()`, `WSD_Admin_Page`, admin page hook identifier, and localized frontend config `{restUrl, nonce, currentMonth, currency}`.

- [ ] Write a failing smoke test/script that loads the bootstrap with WordPress/WooCommerce functions stubbed and asserts plugin constants/classes exist without fatal error.
- [ ] Run the smoke test and confirm failure because bootstrap/classes do not exist.
- [ ] Implement plugin header/constants, guarded bootstrap, supported WooCommerce HPOS declaration via `before_woocommerce_init`, and graceful WooCommerce-unavailable admin notice.
- [ ] Implement top-level `Sales Dashboard` menu using the WooCommerce report-viewing capability and an accessible dashboard shell containing header, month control, refresh/update region, six KPI placeholders, error region, and Top Products region.
- [ ] Enqueue plugin CSS/JS only when the plugin page hook matches; localize only endpoint/config data needed by the frontend.
- [ ] Add minimal CSS/JS placeholders sufficient to prove scoped asset loading and shell initialization.
- [ ] Run PHP syntax checks and smoke test; expect PASS.
- [ ] Commit: `feat: add plugin bootstrap and admin shell`.

### Task 2: Month validation and WooCommerce order provider

**Files:** Create `woo-sales-dashboard/includes/class-order-data-provider.php`; extend tests.

**Interfaces:** Produces `WSD_Order_Data_Provider::get_orders_for_month(string $month): array` and `WSD_Order_Data_Provider::month_bounds(string $month): array{start:string,end:string}`. Orders returned include statuses required for successful and secondary counts.

- [ ] Write failing tests for strict `YYYY-MM`, site-timezone month boundaries, February/leap-year handling, and future-month rejection helper behavior.
- [ ] Run tests; confirm failure.
- [ ] Implement month-bound calculation using WordPress site timezone and half-open `[month start, next month start)` boundaries.
- [ ] Implement paginated `wc_get_orders()` retrieval through WooCommerce APIs for the selected month and approved statuses only, avoiding unbounded object loading.
- [ ] Ensure no storage-specific SQL or post IDs assumptions are introduced.
- [ ] Run focused tests and PHP syntax checks; expect PASS.
- [ ] Commit: `feat: add WooCommerce monthly order provider`.

### Task 3: Aggregation engine and business rules

**Files:** Create `woo-sales-dashboard/includes/class-dashboard-service.php`; create/extend `woo-sales-dashboard/tests/test-dashboard-service.php`.

**Interfaces:** Produces `WSD_Dashboard_Service::aggregate_month(string $month, array $orders): array` with `totals`, `daily`, `secondary`, `shipping`, and `products`; and `compare(array $selected, array $previous, string $selectedMonth, DateTimeImmutable $now): array`.

- [ ] Write failing fixtures/tests for `processing`/`completed` filtering and `cancelled`/`failed`/`refunded` secondary counts.
- [ ] Add failing tests for Total Sales = order total minus recorded refunds, daily attribution by order creation day, and guest parity.
- [ ] Add failing tests for net Items Sold after refunded line quantities, Shipping net of shipping refunds, paid/free shipping counts based on net charged shipping, AOV, Items/Order, and zero-order division behavior.
- [ ] Add failing tests for variable-product aggregation to parent, Top Product quantity and product-line revenue including tax/excluding shipping/net line refunds, and both Top 5 ranking modes.
- [ ] Add failing tests for current partial-month comparison using equal elapsed day count, full historical-month comparison, and neutral delta when previous value is zero.
- [ ] Run tests; confirm intended failures.
- [ ] Implement one logical aggregation pass over order objects using WooCommerce getters/refund APIs, daily buckets initialized for every day of the month, parent-product resolution for variations, and deterministic Top Product sorting.
- [ ] Implement comparison helpers with explicit neutral state rather than infinity/NaN.
- [ ] Run focused test suite and syntax checks; expect PASS.
- [ ] Commit: `feat: implement dashboard aggregation metrics`.

### Task 4: Cache service and targeted invalidation

**Files:** Create `woo-sales-dashboard/includes/class-cache.php`; create `woo-sales-dashboard/tests/test-cache.php`; wire in `class-plugin.php`.

**Interfaces:** Produces `WSD_Cache::get(string $month)`, `set(string $month,array $aggregate)`, `delete(string $month)`, `is_current_month(string $month)`, and order/refund invalidation callbacks.

- [ ] Write failing tests for versioned month cache keys, 5-minute current-month TTL, long-lived historical TTL, and month derivation from an order's `date_created`.
- [ ] Write failing test showing an order/refund mutation invalidates only the affected source month aggregate.
- [ ] Run tests; confirm failure.
- [ ] Implement WordPress transient-backed cache with schema/version prefix and approved TTL policy.
- [ ] Wire conservative WooCommerce order/status/refund/delete hooks; resolve affected order and invalidate its creation month only.
- [ ] Ensure invalidation callbacks never save/update WooCommerce entities.
- [ ] Run tests and syntax checks; expect PASS.
- [ ] Commit: `feat: add monthly dashboard caching`.

### Task 5: Secure single REST endpoint

**Files:** Create `woo-sales-dashboard/includes/class-rest-controller.php`; wire in `class-plugin.php`; extend tests/smoke verification.

**Interfaces:** `GET /woo-sales-dashboard/v1/month?month=YYYY-MM[&refresh=1]`; returns one payload containing selected metadata, comparison metadata, update timestamp, KPIs, daily series, secondary counts, shipping counts, and Top Products data.

- [ ] Write failing tests/checks for missing capability, invalid month, future month, normal cache hit, cache miss, current-month refresh bypass, and rejection/ignoring of historical refresh bypass.
- [ ] Run checks; confirm failure.
- [ ] Register REST route with server-side permission callback using WooCommerce report-view capability and WordPress REST nonce authentication.
- [ ] Validate/sanitize `month`; reject future months with a structured REST error.
- [ ] Compose selected and previous monthly aggregates through cache/provider/service; for current partial month comparison use only elapsed daily buckets even though the previous aggregate may contain the full month.
- [ ] Permit authorized `refresh=1` only for current month; recompute selected current aggregate and return a fresh update timestamp.
- [ ] Return raw numeric values plus store currency/format metadata required for consistent client formatting; keep response compact.
- [ ] Run endpoint checks, unit tests, and syntax checks; expect PASS.
- [ ] Commit: `feat: expose secure dashboard REST endpoint`.

### Task 6: Modern mobile-first dashboard UI

**Files:** Replace/extend `woo-sales-dashboard/assets/css/dashboard.css`, `woo-sales-dashboard/assets/js/dashboard.js`, and shell markup in `class-admin-page.php`.

**Interfaces:** Frontend consumes the Task 5 payload only; no additional endpoints.

- [ ] Define a manual UI verification checklist at mobile widths (~320, 375, 430 px), tablet, and desktop, covering 1/2/4-column behavior, overflow, tap targets, loading, zero, and error states.
- [ ] Implement design tokens scoped beneath `.wsd-dashboard`: modern surface hierarchy, strong numeric type, rounded cards, restrained accent, no external font, no generic WordPress metabox appearance.
- [ ] Implement responsive KPI grid: 2 columns on normal mobile, 1 on extremely narrow screens, up to 4 desktop; give Total Sales stronger hierarchy without harming compact mobile use.
- [ ] Implement skeleton state, atomic response rendering, inline error + Retry, zero state, month change without reload, current-month-only Refresh, disabled future month behavior, and `Updated HH:mm`.
- [ ] Implement lightweight custom SVG smooth area/sparkline renderer with subtle fill, pointer hover and touch/tap nearest-point tooltip showing date/value, and textual accessible context.
- [ ] Implement Top Products responsive ranking list and client-side `Quantity | Revenue` toggle using the same returned product aggregate; no request on toggle.
- [ ] Format currency/numbers with localized WooCommerce-provided currency metadata and `Intl.NumberFormat` fallback logic; never inject product names with unsafe `innerHTML`.
- [ ] Run JS syntax check and manual responsive checklist; correct overflow/touch/accessibility issues.
- [ ] Commit: `feat: build responsive sales dashboard UI`.

### Task 7: Documentation, compatibility verification, and packaging

**Files:** Create `woo-sales-dashboard/README.md`, `woo-sales-dashboard/readme.txt`; possibly add test runner config if needed; generate `dist/woo-sales-dashboard.zip`.

**Interfaces:** Produces an installable WordPress ZIP whose root contains `woo-sales-dashboard/` and runtime files only.

- [ ] Document requirements, installation (`Plugins > Add New > Upload Plugin`), permissions, metrics semantics, cache behavior, HPOS/legacy compatibility, and read-only guarantees.
- [ ] Run all business-logic/cache tests from a clean state and record passing output.
- [ ] Run `php -l` recursively across every shipped PHP file; require zero syntax errors.
- [ ] Run JavaScript syntax verification on `dashboard.js`.
- [ ] Inspect source for direct `wp_posts`/`wp_postmeta` analytics queries, external URLs/CDNs/telemetry, and business-data write methods; require none except plugin cache writes.
- [ ] Verify asset enqueue guard, REST permission callback, future-month rejection, WooCommerce-inactive graceful behavior, and HPOS declaration by inspection/smoke harness.
- [ ] Build `dist/woo-sales-dashboard.zip` excluding `tests/`, `docs/`, VCS files, and `dist/` itself from the plugin runtime package.
- [ ] List ZIP contents and verify the archive has exactly one `woo-sales-dashboard/` root directory and required runtime files.
- [ ] Perform final whole-plugin review against every Success Criterion in the approved spec and fix any discovered gap before release.
- [ ] Commit source/docs and final distribution artifact with release-ready message.

## Definition of Done

- Every approved metric and status/refund rule is covered by tests or explicit integration verification.
- One authenticated endpoint powers the whole dashboard.
- No WooCommerce business data is modified.
- HPOS and legacy storage are supported through WooCommerce APIs.
- Dashboard is usable primarily on mobile and remains polished on desktop.
- Current month refreshes manually or via 5-minute cache expiry; historical data invalidates on relevant order mutation.
- No unnecessary runtime dependency/build step exists.
- Source and docs are in GitHub and an installable ZIP is both committed under `dist/` and delivered directly to the user.