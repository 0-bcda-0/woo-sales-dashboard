# Lightweight Sales Forecast Design

**Date:** 2026-09-10
**Status:** Approved

## Goal

Add two lightweight forecast cards to the existing Sales tab without introducing a new tab, background jobs, external services, ML libraries, or an additional WooCommerce order scan.

The two cards are:

- Projected Total Sales
- Projected Net Earnings

They are visible only for the current month.

## Product decisions

- No separate Forecast tab.
- Forecast horizon is only the end of the current month.
- Forecast uses all available historical data, with newer history weighted more heavily.
- The model should combine recent trend with seasonal behavior, including the same period from the previous year when available.
- Day-of-week behavior should influence the projection.
- During the first days of the month, the forecast should rely more on history; the current month should gain weight as more actual days are observed.
- Both cards show a point estimate and an expected range.
- No Low / Medium / High confidence label.
- Forecast cards are hidden for historical months.
- The iOS widget idea is explicitly out of scope for this feature.

## Performance constraints

The forecast must remain suitable for low-end shared WordPress hosting.

Hard requirements:

- Do not add another `wc_get_orders()` traversal dedicated to forecast calculations.
- Do not rescan all historical orders when the dashboard loads.
- Keep the existing single `/month` REST request from the frontend.
- Do not add cron, autorefresh, external APIs, telemetry, statistical packages, or JavaScript frameworks.
- Use the existing monthly aggregate/cache path as the source of truth for order-derived data.
- Forecast runtime should operate on compact numeric summaries, not WooCommerce order objects or line items.
- Historical backfill must be progressive and capped so one dashboard request cannot trigger an unbounded number of monthly WooCommerce queries.

## Forecast history index

Add a compact forecast-history store. It contains only data required for forecasting and is separate from the full monthly aggregate cache.

Suggested stored structure per month:

```php
[
    '2026-08' => [
        'dailySales' => [120.0, 340.0, 0.0, 510.0],
        'dailyCommission' => [51.43, 145.71, 0.0, 218.57],
        'marketing' => 850.0,
    ],
]
```

Do not store order IDs, customer data, products, audit rows, or line-item details in this index.

When a month aggregate is already available through the normal dashboard flow, sync the compact summary into the forecast history store. For missing historical months, allow a progressive warm-up of at most **2 missing months per dashboard request**. Completed historical months, once summarized, should be reused without another order scan unless their underlying monthly aggregate is invalidated and recalculated.

The forecast history should use a small WordPress option or similarly cheap persistent WordPress storage. It must be bounded to valid month keys and compact numeric arrays.

## Projected Total Sales algorithm

Use a deterministic weighted model. No ML library is required.

For the current month:

1. Read actual current-month daily sales up to the current day.
2. Build historical weekday expectations from compact history.
3. Weight historical observations by recency so newer months matter more.
4. Give an additional seasonal boost to observations from the same calendar month in previous years when available.
5. Estimate a baseline expected amount for each remaining day according to its weekday and the weighted historical pattern.
6. Compare actual month-to-date sales with what the historical baseline would have expected for the elapsed portion of the month.
7. Apply a bounded pace adjustment to the remaining-day baseline.
8. Blend the pace adjustment based on elapsed progress: very early in the month, history dominates; as more days pass, actual current-month pace gains weight.
9. Projected Total Sales equals actual month-to-date sales plus the adjusted expected sales for the remaining days.

The pace adjustment must be bounded so a few unusually large or small early orders cannot create absurd projections.

## Early-month weighting

The current month's influence should increase smoothly with elapsed days rather than switch at a hard threshold.

A suitable lightweight rule is:

```text
current_month_weight = min(1, elapsed_days / 14)
```

This means the first few days are history-heavy, around day 7 current performance has meaningful influence, and around mid-month the observed pace can dominate. Equivalent bounded logic is acceptable if tests preserve this behavior.

## Seasonal and recency weighting

Use simple arithmetic weights rather than regression packages.

A suitable model is:

- recency weight decreases as history gets older;
- same-calendar-month observations receive a seasonal multiplier;
- weekday matching is used when estimating each remaining day.

Exact constants should be centralized in `WSD_Forecast_Service` and covered by tests so they can be tuned later without changing architecture.

## Expected range

Do not use an arbitrary fixed percentage such as ±10%.

Derive uncertainty from historical forecast residuals or weighted daily variability available in the compact history. The range should:

- be wider early in the month;
- narrow as more actual days become known;
- never produce a negative lower bound;
- remain deterministic for the same inputs.

Return point, low, and high values for both forecast cards.

## Projected Net Earnings

Projected Net Earnings is based on projected commission economics, not a flat percentage of Total Sales.

Historical daily aggregates already contain `commissionToPay`, reflecting the actual Standard / VIP / Bundle mix. Use that series to forecast month-end Commission to Pay with the same lightweight forecasting method used for sales.

Then calculate:

```text
Projected Net Earnings = Projected Commission to Pay - Forecast Marketing
```

### Marketing rule

- If the current month has a non-zero saved Marketing amount, treat that as the final monthly Marketing cost.
- If the current month Marketing amount is zero / not entered, estimate Marketing from historical saved Marketing values using a recency-weighted average.

### Other Costs rule

**Other Costs must not be included in forecast calculations.**

The normal Commission tab remains unchanged: its actual Net Earnings continues using the existing real calculation, including both Marketing and Other Costs. This exception applies only to the forecast card.

## REST payload

Do not create a separate frontend request. Extend the existing current-month `/month` response with a small `forecast` object:

```json
{
  "forecast": {
    "sales": {
      "projected": 8420.00,
      "low": 7650.00,
      "high": 9180.00
    },
    "netEarnings": {
      "projected": 3610.00,
      "low": 3220.00,
      "high": 3970.00
    }
  }
}
```

For historical months, return `forecast: null` or omit forecast in a consistently tested way; the UI must hide both forecast cards.

## UI

Place the forecast cards after the existing `Items / Order` card in the Sales metrics grid.

Card copy:

```text
Projected Total Sales
€8,420
Expected €7,650 – €9,180
```

```text
Projected Net Earnings
€3,610
Expected €3,220 – €3,970
```

Requirements:

- Reuse the existing responsive card grid and visual language.
- No new graph for forecast cards.
- No new tab.
- No animation or frontend library.
- Forecast cards are hidden when the selected month is not the current month.
- Preserve mobile responsiveness as a primary use case.

## Architecture

Introduce a focused `WSD_Forecast_Service` responsible only for:

- compact history normalization;
- recency / seasonality / weekday weighting;
- point forecast;
- expected range;
- projected marketing fallback;
- forecast payload assembly.

Keep WooCommerce querying inside `WSD_Order_Data_Provider`; do not let the forecast service query WooCommerce directly.

The REST controller may coordinate progressive history warm-up and pass compact aggregates to the forecast service, but should not contain forecasting math.

## Cache and invalidation

The existing monthly aggregate cache remains authoritative for monthly Woo-derived aggregates.

When an aggregate is recalculated or an affected month is invalidated, its compact forecast-history entry must be refreshed before future forecasts rely on it. Avoid global cache invalidation where month-scoped invalidation is possible.

If forecast-history storage schema changes in a future release, use an explicit forecast-history schema version and discard/rebuild incompatible stored data.

## Error behavior

Forecast failure must not break the Sales dashboard. If history is unavailable or insufficient, return a conservative forecast using current-month actuals plus the best available baseline. If no meaningful projection can be produced, return `forecast: null` and render the ordinary dashboard normally.

## Tests

Add focused regression tests for:

- forecast cards only for current month;
- no forecast for historical months;
- early-month history-heavy weighting;
- later-month actual pace has stronger influence;
- newer history outweighs older history;
- same calendar month last year receives seasonal influence;
- weekday patterns influence remaining days;
- bounded pace adjustment prevents extreme early-month projections;
- expected range widens/narrows based on elapsed progress and never goes below zero;
- saved current-month Marketing overrides historical marketing forecast;
- zero current-month Marketing uses historical weighted average;
- Other Costs never affect forecast Net Earnings;
- forecast commission is based on `commissionToPay` history rather than a fixed Total Sales percentage;
- progressive backfill processes no more than 2 missing historical months in one dashboard request;
- forecast does not introduce a second independent `wc_get_orders()` call path;
- frontend hides forecast cards for historical months and renders the expected range on current month.

## Out of scope

- Future-month forecasts.
- Separate Forecast tab.
- iOS widget / public API.
- Machine learning services.
- External statistical APIs.
- Forecast graphs.
- Forecast editing or manual override.
