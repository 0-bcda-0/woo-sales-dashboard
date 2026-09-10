<?php

define('ABSPATH', __DIR__ . '/');
$GLOBALS['options'] = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }

require_once dirname(__DIR__) . '/includes/class-forecast-history-store.php';

function fail_forecast_integration(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function assert_forecast_integration(bool $condition, string $message): void {
    if (! $condition) fail_forecast_integration($message);
}

$store = new WSD_Forecast_History_Store();
$aggregate = [
    'daily' => [
        '2026-08-01' => ['sales' => 100.0, 'commissionToPay' => 42.86, 'orders' => 1],
        '2026-08-02' => ['sales' => 250.0, 'commissionToPay' => 107.14, 'orders' => 2],
    ],
    'products' => [['id' => 1, 'name' => 'Do not persist']],
];
$store->put_month('2026-08', $aggregate, 850.0);
$row = $store->get_month('2026-08');
assert_forecast_integration($row !== null, 'history store should return persisted month');
assert_forecast_integration($row['dailySales'] === [100.0, 250.0], 'history should persist daily sales only');
assert_forecast_integration($row['dailyCommission'] === [42.86, 107.14], 'history should persist daily commission only');
assert_forecast_integration($row['marketing'] === 850.0, 'history should persist marketing');
assert_forecast_integration(! isset($row['products']) && ! isset($row['orders']), 'history must not persist heavy aggregate data');

$store->delete_month('2026-08');
assert_forecast_integration($store->get_month('2026-08') === null, 'month deletion should be scoped');

$GLOBALS['options']['wsd_forecast_history'] = ['_schemaVersion' => 999, 'months' => ['2026-08' => $row]];
assert_forecast_integration($store->get_all() === [], 'incompatible forecast schema should be rejected');

$includes = dirname(__DIR__) . '/includes';
$productionMatches = [];
foreach (glob($includes . '/*.php') as $file) {
    if (str_contains(file_get_contents($file), 'wc_get_orders(')) $productionMatches[] = basename($file);
}
sort($productionMatches);
assert_forecast_integration($productionMatches === ['class-order-data-provider.php'], 'wc_get_orders must remain isolated to order data provider');

$rest = file_get_contents($includes . '/class-rest-controller.php');
assert_forecast_integration(str_contains($rest, "warm_forecast_history($month, 2)"), 'current payload should cap progressive warm-up at two months');
assert_forecast_integration(str_contains($rest, "for ($i = 0; $i < 36 && $processed < $limit;"), 'history discovery must remain bounded');
assert_forecast_integration(str_contains($rest, "'forecast' => $forecast"), 'existing month payload should carry forecast data');
assert_forecast_integration(! str_contains($rest, "register_rest_route('woo-sales-dashboard/v1', '/forecast'"), 'forecast must not add a second frontend REST route');

$admin = file_get_contents($includes . '/class-admin-page.php');
assert_forecast_integration(str_contains($admin, 'data-forecast-card="sales"'), 'Projected Total Sales card must exist');
assert_forecast_integration(str_contains($admin, 'Projected Total Sales'), 'Projected Total Sales label must exist');
assert_forecast_integration(str_contains($admin, 'data-forecast-card="netEarnings"'), 'Projected Net Earnings card must exist');
assert_forecast_integration(str_contains($admin, 'Projected Net Earnings'), 'Projected Net Earnings label must exist');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/dashboard.js');
assert_forecast_integration(str_contains($js, 'function renderForecast()'), 'frontend should have forecast renderer');
assert_forecast_integration(str_contains($js, '!!data?.isCurrentMonth&&!!f'), 'frontend should hide forecast outside current month');
assert_forecast_integration(str_contains($js, 'Expected ${money(row.low)} – ${money(row.high)}'), 'frontend should render expected range');

$forecastService = file_get_contents($includes . '/class-forecast-service.php');
assert_forecast_integration(! str_contains($forecastService, 'wc_get_orders'), 'forecast service must never query WooCommerce orders');
assert_forecast_integration(! str_contains($forecastService, 'otherCosts'), 'Other Costs must not enter forecast service');

echo "PASS forecast integration guards\n";
