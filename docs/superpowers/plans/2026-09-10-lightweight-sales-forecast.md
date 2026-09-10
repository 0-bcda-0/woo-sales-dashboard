# Lightweight Sales Forecast Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two current-month forecast cards—Projected Total Sales and Projected Net Earnings—using compact cached historical aggregates without adding a dedicated WooCommerce order scan.

**Architecture:** Introduce a focused `WSD_Forecast_Service` that contains all forecasting math and operates only on compact numeric history. Reuse the existing monthly aggregate/cache pipeline and extend the current `/month` response; progressively warm at most two missing historical months per dashboard request. Frontend changes remain inside the existing Sales grid and the existing single REST request.

**Tech Stack:** WordPress, WooCommerce CRUD APIs, PHP, WordPress options/transients, vanilla JavaScript, existing plugin CSS, custom PHP regression tests.

**Spec:** `docs/superpowers/specs/2026-09-10-lightweight-sales-forecast-design.md`

## Global Constraints

- No separate Forecast tab.
- Forecast horizon is only the end of the current month.
- Forecast cards are visible only for the current month.
- Forecast uses all available historical data, with newer history weighted more heavily.
- Combine recent trend, same-calendar-month seasonality, and weekday behavior.
- Early month must be history-heavy; current-month actual pace gains influence smoothly with elapsed days.
- Show point estimate plus expected low/high range.
- Do not include Low / Medium / High confidence labels.
- Other Costs must not be included in forecast calculations.
- If current-month Marketing is non-zero, use it as final monthly Marketing; otherwise use historical recency-weighted Marketing.
- No dedicated forecast `wc_get_orders()` traversal.
- No full-history order rescan on normal dashboard load.
- Progressive warm-up may process at most 2 missing historical months per dashboard request.
- Keep the existing single frontend `/month` request.
- No cron, autorefresh, external APIs, telemetry, ML/statistical libraries, Chart.js, or JS framework.
- Forecast failure must not break the ordinary dashboard.
- Mobile responsiveness remains a primary UI requirement.

---

## File Structure

### Create

- `woo-sales-dashboard/includes/class-forecast-service.php` — pure forecast/history math and payload assembly; no Woo queries.
- `woo-sales-dashboard/includes/class-forecast-history-store.php` — compact schema-versioned persistent monthly summaries and invalidation helpers.
- `woo-sales-dashboard/tests/test-forecast-service.php` — deterministic unit-style forecast behavior tests.
- `woo-sales-dashboard/tests/test-forecast-integration.php` — REST/history/backfill/performance guard regression coverage.

### Modify

- `woo-sales-dashboard/includes/class-plugin.php` — load/wire forecast classes.
- `woo-sales-dashboard/includes/class-rest-controller.php` — sync compact summaries, bounded progressive warm-up, append current-month forecast to existing payload.
- `woo-sales-dashboard/includes/class-cache.php` — expose/integrate month-scoped invalidation hook so compact history can be marked stale without global clearing.
- `woo-sales-dashboard/includes/class-admin-page.php` — add two hidden-by-default forecast cards after Items / Order.
- `woo-sales-dashboard/assets/js/dashboard.js` — render/hide forecast cards from existing response.
- `woo-sales-dashboard/assets/css/dashboard.css` — minimal forecast-card metadata/range styling only if existing styles are insufficient.
- `woo-sales-dashboard/tests/test-cache-schema.php` — preserve aggregate cache contract if cache invalidation wiring changes.
- `README.md` and `readme.txt` — document forecast behavior and release change.

---

### Task 1: Compact Forecast History Store

**Files:**
- Create: `woo-sales-dashboard/includes/class-forecast-history-store.php`
- Modify: `woo-sales-dashboard/includes/class-plugin.php`
- Test: `woo-sales-dashboard/tests/test-forecast-integration.php`

**Interfaces:**
- Consumes: monthly aggregate arrays containing `daily[date].sales`, `daily[date].commissionToPay`; monthly Marketing value from `WSD_Settings_Store`.
- Produces: `WSD_Forecast_History_Store::get_all(): array`, `get_month(string $month): ?array`, `put_month(string $month, array $aggregate, float $marketing): void`, `delete_month(string $month): void`, `months(): array`.

- [ ] **Step 1: Write the failing compact-history schema tests**

Create `test-forecast-integration.php` with WordPress option stubs and assertions equivalent to:

```php
$store = new WSD_Forecast_History_Store();
$aggregate = [
    'daily' => [
        '2026-08-01' => ['sales' => 100.0, 'commissionToPay' => 42.86],
        '2026-08-02' => ['sales' => 250.0, 'commissionToPay' => 107.14],
    ],
];
$store->put_month('2026-08', $aggregate, 850.0);
$row = $store->get_month('2026-08');

assert($row['dailySales'] === [100.0, 250.0]);
assert($row['dailyCommission'] === [42.86, 107.14]);
assert($row['marketing'] === 850.0);
assert(!isset($row['orders']));
assert(!isset($row['products']));
```

Also seed an incompatible stored schema version and assert `get_all()` returns an empty normalized dataset rather than trusting stale data.

- [ ] **Step 2: Run the new test and verify it fails**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-integration.php
```

Expected: FAIL because `WSD_Forecast_History_Store` does not exist.

- [ ] **Step 3: Implement the compact history store**

Create a final class with constants similar to:

```php
final class WSD_Forecast_History_Store {
    private const OPTION = 'wsd_forecast_history';
    private const SCHEMA_VERSION = 1;

    public function get_all(): array { /* normalize schema + month rows */ }
    public function get_month(string $month): ?array { /* month lookup */ }
    public function put_month(string $month, array $aggregate, float $marketing): void { /* compact numeric copy only */ }
    public function delete_month(string $month): void { /* remove one month */ }
    public function months(): array { return array_keys($this->get_all()); }
}
```

Stored option shape:

```php
[
    '_schemaVersion' => 1,
    'months' => [
        '2026-08' => [
            'dailySales' => [100.0, 250.0],
            'dailyCommission' => [42.86, 107.14],
            'marketing' => 850.0,
        ],
    ],
]
```

Reject invalid month keys with `/^\d{4}-(0[1-9]|1[0-2])$/`. Cast every stored metric to non-negative float. Do not persist dates inside daily arrays because the month key plus array index defines date cheaply.

Wire `class-forecast-history-store.php` into `WSD_Plugin::boot()` before constructing the REST controller.

- [ ] **Step 4: Run the compact-history tests**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-integration.php
```

Expected: PASS for schema normalization, compact persistence, single-month deletion, and stale-schema rejection.

- [ ] **Step 5: Commit**

```bash
git add woo-sales-dashboard/includes/class-forecast-history-store.php woo-sales-dashboard/includes/class-plugin.php woo-sales-dashboard/tests/test-forecast-integration.php
git commit -m "feat: add compact forecast history store"
```

---

### Task 2: Deterministic Forecast Service

**Files:**
- Create: `woo-sales-dashboard/includes/class-forecast-service.php`
- Modify: `woo-sales-dashboard/includes/class-plugin.php`
- Test: `woo-sales-dashboard/tests/test-forecast-service.php`

**Interfaces:**
- Consumes: current month string, current aggregate, compact historical month rows, current saved Marketing, current date/day.
- Produces: `WSD_Forecast_Service::forecast(string $month, array $currentAggregate, array $history, float $currentMarketing, DateTimeImmutable $today): ?array` returning `sales.projected|low|high` and `netEarnings.projected|low|high`.

- [ ] **Step 1: Write failing deterministic forecast tests**

Create fixtures with synthetic months where behavior is obvious. Include assertions for all of these independent cases:

```php
// newer history outweighs older history
assert($forecastFromRecentHigh['sales']['projected'] > $forecastFromRecentLow['sales']['projected']);

// same-calendar-month last year gets seasonal influence
assert($withLastSeptember['sales']['projected'] > $withoutLastSeptember['sales']['projected']);

// weekday pattern matters
assert($monthWithStrongUpcomingSaturday['sales']['projected'] > $monthWithWeakUpcomingSaturday['sales']['projected']);

// early-month pace is bounded
assert($day2HugeOrder['sales']['projected'] < $unboundedLinearProjection);

// range never negative
assert($forecast['sales']['low'] >= 0.0);
assert($forecast['netEarnings']['low'] >= 0.0);

// range narrows later in month
assert(($day20['sales']['high'] - $day20['sales']['low']) < ($day3['sales']['high'] - $day3['sales']['low']));

// non-zero current marketing wins
assert($withSavedMarketing['netEarnings']['projected'] === $projectedCommission - 1200.0);

// zero marketing uses weighted historical marketing
assert($withoutSavedMarketing['netEarnings']['projected'] < $projectedCommission);

// other costs never enter the service API or output math
assert(!array_key_exists('otherCosts', $forecast));
```

Also test that commission forecast changes when `dailyCommission` history changes while `dailySales` is held constant, proving Net Earnings is not derived from a flat sales percentage.

- [ ] **Step 2: Run the forecast-service test and verify it fails**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-service.php
```

Expected: FAIL because `WSD_Forecast_Service` does not exist.

- [ ] **Step 3: Implement centralized lightweight constants and helpers**

Use constants inside `WSD_Forecast_Service`, for example:

```php
private const RECENCY_DECAY = 0.88;
private const SAME_MONTH_MULTIPLIER = 1.60;
private const PACE_MIN = 0.55;
private const PACE_MAX = 1.80;
private const FULL_PACE_WEIGHT_DAY = 14;
private const RANGE_FLOOR_RATIO = 0.05;
```

Implement focused private helpers:

```php
private function recency_weight(int $monthsAgo): float;
private function history_day_value(array $row, string $series, int $day): float;
private function weekday_baseline(array $history, string $series, int $weekday, string $targetMonth): ?float;
private function weighted_marketing(array $history): float;
private function bounded_pace(float $actual, float $expected): float;
private function current_weight(int $elapsedDays): float;
private function range_from_history(...): array;
```

Keep loops strictly over month rows and daily numeric arrays. No WooCommerce functions, no DB calls, no options access.

- [ ] **Step 4: Implement the forecast algorithm**

For each series (`dailySales`, `dailyCommission`):

```text
actual_mtd = sum known current daily values through today
baseline_elapsed = weighted expected values for elapsed dates
raw_pace = actual_mtd / baseline_elapsed when baseline_elapsed > 0
bounded_pace = clamp(raw_pace, PACE_MIN, PACE_MAX)
pace_weight = min(1, elapsed_days / FULL_PACE_WEIGHT_DAY)
adjustment = 1 + ((bounded_pace - 1) * pace_weight)
remaining = sum(weighted weekday/seasonal baseline for each remaining date) * adjustment
projected = actual_mtd + remaining
```

For very sparse history, fall back in this order:

1. available weighted weekday history;
2. weighted historical daily average;
3. current-month actual daily average with bounded extrapolation;
4. actual MTD if no meaningful extrapolation exists.

Derive low/high from weighted historical residual/variability and scale remaining uncertainty by the fraction of month still unknown. Clamp low to zero and ensure `low <= projected <= high`.

Net Earnings point/range:

```php
$marketing = $currentMarketing > 0.0
    ? $currentMarketing
    : $this->weighted_marketing($history);

$netProjected = max(0.0, $commissionForecast['projected'] - $marketing);
$netLow = max(0.0, $commissionForecast['low'] - $marketing);
$netHigh = max(0.0, $commissionForecast['high'] - $marketing);
```

Do not accept Other Costs as an argument.

- [ ] **Step 5: Run forecast tests until all deterministic cases pass**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-service.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woo-sales-dashboard/includes/class-forecast-service.php woo-sales-dashboard/includes/class-plugin.php woo-sales-dashboard/tests/test-forecast-service.php
git commit -m "feat: add lightweight seasonal forecast service"
```

---

### Task 3: Integrate History Sync, Bounded Warm-up, and REST Payload

**Files:**
- Modify: `woo-sales-dashboard/includes/class-plugin.php`
- Modify: `woo-sales-dashboard/includes/class-rest-controller.php`
- Modify: `woo-sales-dashboard/includes/class-cache.php`
- Test: `woo-sales-dashboard/tests/test-forecast-integration.php`
- Test: `woo-sales-dashboard/tests/test-cache-schema.php`

**Interfaces:**
- Consumes: `WSD_Forecast_History_Store`, `WSD_Forecast_Service`, existing `aggregate(string $month)` path.
- Produces: current-month `forecast` key in `month_payload()` and synchronized compact history.

- [ ] **Step 1: Add failing integration tests for current vs historical payloads**

Stub current time and assert:

```php
$current = $controller->month_payload('2026-09');
assert(is_array($current['forecast']));
assert(isset($current['forecast']['sales']['projected']));
assert(isset($current['forecast']['netEarnings']['low']));

$historical = $controller->month_payload('2026-08');
assert($historical['forecast'] === null);
```

- [ ] **Step 2: Add failing bounded-backfill test**

Seed compact history with many missing past months and instrument the provider stub:

```php
$controller->month_payload('2026-09');
assert($provider->monthlyCallsForForecastWarmup <= 2);
```

The test must distinguish the normal selected/previous month aggregation from extra warm-up work and prove that no more than two additional missing historical months are fetched.

- [ ] **Step 3: Add source-level performance guard**

Read PHP source files and assert the only production `wc_get_orders(` occurrence remains in `class-order-data-provider.php`:

```php
$matches = [];
foreach (glob(__DIR__ . '/../includes/*.php') as $file) {
    if (str_contains(file_get_contents($file), 'wc_get_orders(')) $matches[] = basename($file);
}
assert($matches === ['class-order-data-provider.php']);
```

- [ ] **Step 4: Run integration tests and verify failure**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-integration.php
php woo-sales-dashboard/tests/test-cache-schema.php
```

Expected: forecast integration assertions fail before implementation; existing cache test still passes.

- [ ] **Step 5: Wire services into the plugin and REST controller**

Construct:

```php
$forecastHistory = new WSD_Forecast_History_Store();
$forecast = new WSD_Forecast_Service();
```

Pass both to `WSD_REST_Controller` through explicit constructor arguments.

- [ ] **Step 6: Sync compact summary whenever an aggregate is available**

After obtaining a valid monthly aggregate through the existing `aggregate($month)` path, write a compact history row using the corresponding saved Marketing. Ensure this does not trigger another Woo query.

Use a small helper in the controller such as:

```php
private function sync_forecast_history(string $month, array $aggregate): void;
```

- [ ] **Step 7: Add progressive warm-up capped at two months**

Add a helper such as:

```php
private function warm_forecast_history(string $currentMonth, int $limit = 2): void;
```

Rules:

- only run for current-month dashboard requests;
- never inspect future months;
- skip months already present in compact history;
- process newest missing completed months first;
- stop after two missing months;
- call the existing `aggregate($month)` method, so an already-valid monthly aggregate cache is reused;
- never call the provider directly from forecast code.

Do not attempt to discover an unbounded historical start by scanning orders. Use an explicit bounded lookback cap for discovery, e.g. 36 months, while retaining all history already stored. This preserves low-hosting safety even when the store is new.

- [ ] **Step 8: Append forecast to current `/month` response**

In `month_payload()`:

```php
$forecast = null;
if ($this->cache->is_current_month($month)) {
    $this->warm_forecast_history($month, 2);
    $this->sync_forecast_history($month, $selected);
    $forecast = $this->forecastService->forecast(
        $month,
        $selected,
        $this->forecastHistory->get_all(),
        (float) $costs['marketing'],
        new DateTimeImmutable('now', wp_timezone())
    );
}
```

Return `'forecast' => $forecast` in the existing payload. Historical requests must return `null` without warm-up.

- [ ] **Step 9: Connect month-scoped invalidation**

When existing cache invalidation knows an affected order month, also remove that compact forecast-history month so stale sales/commission summaries are not reused after recalculation. Preserve the existing aggregate schema/version behavior.

Use a month-specific callback/hook or an explicit injected invalidation path; do not clear all forecast history for one changed order.

- [ ] **Step 10: Run integration and cache tests**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-integration.php
php woo-sales-dashboard/tests/test-cache-schema.php
```

Expected: PASS, including max-two warm-up guard and single `wc_get_orders()` source path.

- [ ] **Step 11: Commit**

```bash
git add woo-sales-dashboard/includes/class-plugin.php woo-sales-dashboard/includes/class-rest-controller.php woo-sales-dashboard/includes/class-cache.php woo-sales-dashboard/tests/test-forecast-integration.php woo-sales-dashboard/tests/test-cache-schema.php
git commit -m "feat: integrate forecast into monthly dashboard payload"
```

---

### Task 4: Sales Tab Forecast Cards

**Files:**
- Modify: `woo-sales-dashboard/includes/class-admin-page.php`
- Modify: `woo-sales-dashboard/assets/js/dashboard.js`
- Modify: `woo-sales-dashboard/assets/css/dashboard.css`
- Test: `woo-sales-dashboard/tests/test-forecast-integration.php`

**Interfaces:**
- Consumes: `data.forecast.sales.{projected,low,high}`, `data.forecast.netEarnings.{projected,low,high}`, `data.isCurrentMonth`.
- Produces: two accessible Sales-grid cards visible only when current-month forecast exists.

- [ ] **Step 1: Add failing markup/source assertions**

Assert admin markup contains exact forecast card keys and labels:

```text
data-forecast-card="sales"
Projected Total Sales
data-forecast-card="netEarnings"
Projected Net Earnings
```

Assert JS contains an explicit forecast renderer and historical hide behavior.

- [ ] **Step 2: Run UI source test and verify failure**

Run:

```bash
php woo-sales-dashboard/tests/test-forecast-integration.php
```

Expected: FAIL because forecast cards do not exist.

- [ ] **Step 3: Add hidden-by-default forecast cards after Items / Order**

In the Sales grid, append exactly two articles after the existing six Sales metrics:

```php
<article class="wsd-card wsd-forecast-card" data-forecast-card="sales" hidden>
    <div class="wsd-card-head"><span>Projected Total Sales</span></div>
    <div class="wsd-value wsd-skeleton" data-value>—</div>
    <div class="wsd-meta" data-range>&nbsp;</div>
</article>

<article class="wsd-card wsd-forecast-card" data-forecast-card="netEarnings" hidden>
    <div class="wsd-card-head"><span>Projected Net Earnings</span></div>
    <div class="wsd-value wsd-skeleton" data-value>—</div>
    <div class="wsd-meta" data-range>&nbsp;</div>
</article>
```

No chart element is needed.

- [ ] **Step 4: Render forecast from the existing response**

Add:

```js
const forecastCards=Object.fromEntries($$('[data-forecast-card]').map(x=>[x.dataset.forecastCard,x]));
```

Extend loading state to cover forecast card values without changing request count.

Implement:

```js
function renderForecast(){
  const f=data?.forecast;
  const visible=!!data?.isCurrentMonth&&!!f;
  Object.entries(forecastCards).forEach(([key,card])=>{
    card.hidden=!visible;
    if(!visible)return;
    const row=f[key];
    card.querySelector('[data-value]').classList.remove('wsd-skeleton');
    card.querySelector('[data-value]').textContent=money(row.projected);
    card.querySelector('[data-range]').textContent=`Expected ${money(row.low)} – ${money(row.high)}`;
  });
}
```

Call `renderForecast()` from `render()` after `renderSales()`.

- [ ] **Step 5: Preserve mobile layout with minimal CSS**

Reuse `.wsd-card`, `.wsd-value`, and `.wsd-meta`. Add CSS only if the range line needs wrapping protection, for example:

```css
.wsd-forecast-card [data-range] { white-space: normal; }
```

Do not add fixed widths or new breakpoints unless the existing grid demonstrably clips these cards.

- [ ] **Step 6: Run JS syntax and UI tests**

Run:

```bash
node --check woo-sales-dashboard/assets/js/dashboard.js
php woo-sales-dashboard/tests/test-forecast-integration.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add woo-sales-dashboard/includes/class-admin-page.php woo-sales-dashboard/assets/js/dashboard.js woo-sales-dashboard/assets/css/dashboard.css woo-sales-dashboard/tests/test-forecast-integration.php
git commit -m "feat: show current month forecast cards"
```

---

### Task 5: Release Documentation and Full Regression Verification

**Files:**
- Modify: `README.md`
- Modify: `woo-sales-dashboard/readme.txt`
- Modify: `docs/PROJECT-HANDOFF.md`
- Modify: `docs/RELEASE-CHECKLIST.md` only if the forecast store creates a new release-specific validation worth retaining.

**Interfaces:**
- Consumes: completed feature behavior from Tasks 1–4.
- Produces: documented, regression-verified feature branch ready for review/PR.

- [ ] **Step 1: Document exact forecast semantics**

Add concise documentation stating:

- two forecast cards live on Sales, not a new tab;
- current month only;
- weighted recent + seasonal + weekday history;
- compact progressive history warm-up capped at two missing months per request;
- current non-zero Marketing overrides historical Marketing estimate;
- Other Costs are excluded from forecast only;
- no external service or extra dedicated Woo order scan.

Do not describe the iOS widget as planned or approved work.

- [ ] **Step 2: Run PHP syntax checks**

Run:

```bash
find woo-sales-dashboard -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every PHP file reports `No syntax errors detected`.

- [ ] **Step 3: Run JavaScript syntax check**

Run:

```bash
node --check woo-sales-dashboard/assets/js/dashboard.js
```

Expected: exit code 0.

- [ ] **Step 4: Run all shipped PHP regression tests**

Run each test under `woo-sales-dashboard/tests/`, including at minimum:

```bash
php woo-sales-dashboard/tests/test-cache-schema.php
php woo-sales-dashboard/tests/test-dashboard-v2-hotfix.php
php woo-sales-dashboard/tests/test-forecast-service.php
php woo-sales-dashboard/tests/test-forecast-integration.php
```

Expected: all PASS.

- [ ] **Step 5: Run architecture/performance source audit**

Run:

```bash
grep -R "wc_get_orders(" -n woo-sales-dashboard/includes
```

Expected: production occurrence only in `class-order-data-provider.php`.

Run:

```bash
grep -R -E "Chart\.js|chart\.js|setInterval|wp_schedule_event|wp_cron|https?://.*(cdn|googleapis|jsdelivr|unpkg)" -n woo-sales-dashboard --exclude='*.md' --exclude='readme.txt' || true
```

Expected: no newly introduced runtime dependency/autorefresh/cron hits.

- [ ] **Step 6: Review forecast failure isolation**

Force the forecast service to return `null` in the integration fixture and verify the ordinary Sales payload and existing Sales/Commission fields still render. Expected: dashboard behavior remains intact and only the two forecast cards remain hidden.

- [ ] **Step 7: Commit docs and final verification state**

```bash
git add README.md woo-sales-dashboard/readme.txt docs/PROJECT-HANDOFF.md docs/RELEASE-CHECKLIST.md
git commit -m "docs: document lightweight sales forecast"
```

- [ ] **Step 8: Compare branch against main before PR**

Run:

```bash
git diff --stat main...feature/lightweight-sales-forecast
git diff --check main...feature/lightweight-sales-forecast
```

Expected: only forecast-related source, tests, and docs changed; `git diff --check` emits no whitespace errors.

---

## Self-Review

### Spec coverage

- Two current-month-only Sales cards: Task 4.
- Projected Sales and Net Earnings: Tasks 2–4.
- Recent trend + same-month seasonality + weekday behavior: Task 2.
- Early-month history bias and later actual-pace influence: Task 2.
- Expected range, no confidence label: Tasks 2 and 4.
- Marketing override/history fallback: Task 2.
- Other Costs excluded from forecast only: Task 2 and docs.
- Compact history, no order/customer/item persistence: Task 1.
- Max-two progressive warm-up: Task 3.
- Single existing frontend request: Task 3 and Task 4.
- No dedicated Woo scan: Task 3 source guard and Task 5 audit.
- Forecast failure isolation: Tasks 2, 3, and 5.
- Mobile/lightweight UI: Task 4.
- iOS widget excluded: spec and Task 5 docs guard.

### Placeholder scan

No TBD/TODO/"implement later" placeholders remain. Every implementation task defines concrete interfaces, test behavior, commands, and expected outcomes.

### Type consistency

- `WSD_Forecast_History_Store` methods are named consistently across Tasks 1 and 3.
- `WSD_Forecast_Service::forecast(...)` input/output shape is consistent across Tasks 2–4.
- REST payload keys `forecast.sales` and `forecast.netEarnings` match frontend `data-forecast-card` keys.
- Marketing is a float; Other Costs are intentionally absent from the forecast service interface.
