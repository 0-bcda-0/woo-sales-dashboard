<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-forecast-service.php';

function fail_forecast(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function assert_forecast(bool $condition, string $message): void {
    if (! $condition) fail_forecast($message);
}
function history_row(float $sales, float $commission, float $marketing = 0.0, int $days = 30): array {
    return [
        'dailySales' => array_fill(0, $days, $sales),
        'dailyCommission' => array_fill(0, $days, $commission),
        'marketing' => $marketing,
    ];
}
function current_aggregate(string $month, array $sales, array $commission, int $days = 30): array {
    $daily = [];
    for ($day = 1; $day <= $days; $day++) {
        $date = sprintf('%s-%02d', $month, $day);
        $daily[$date] = [
            'sales' => (float)($sales[$day - 1] ?? 0.0),
            'commissionToPay' => (float)($commission[$day - 1] ?? 0.0),
        ];
    }
    return ['daily' => $daily];
}

$service = new WSD_Forecast_Service();
$tz = new DateTimeZone('UTC');
$today10 = new DateTimeImmutable('2026-09-10 12:00:00', $tz);
$current = current_aggregate('2026-09', array_fill(0, 10, 150.0), array_fill(0, 10, 60.0));

$baseHistory = [
    '2026-07' => history_row(90.0, 36.0, 700.0, 31),
    '2026-08' => history_row(100.0, 40.0, 800.0, 31),
];
$base = $service->forecast('2026-09', $current, $baseHistory, 0.0, $today10);
assert_forecast(is_array($base), 'current month should produce a forecast');
assert_forecast($base['sales']['projected'] >= 1500.0, 'projection must include actual MTD sales');
assert_forecast($base['sales']['low'] >= 0.0, 'sales low range must not be negative');
assert_forecast($base['sales']['low'] <= $base['sales']['projected'] && $base['sales']['projected'] <= $base['sales']['high'], 'sales point must sit inside range');
assert_forecast($base['netEarnings']['low'] >= 0.0, 'net earnings low range must not be negative');

$historical = $service->forecast('2026-08', $current, $baseHistory, 0.0, $today10);
assert_forecast($historical === null, 'historical month must not produce a forecast');

$withSeason = $service->forecast('2026-09', $current, $baseHistory + [
    '2025-09' => history_row(300.0, 120.0, 900.0, 30),
], 0.0, $today10);
assert_forecast($withSeason['sales']['projected'] > $base['sales']['projected'], 'same calendar month history should influence seasonality');

$recentHigh = $service->forecast('2026-09', $current, [
    '2026-03' => history_row(60.0, 24.0, 600.0, 31),
    '2026-08' => history_row(220.0, 88.0, 900.0, 31),
], 0.0, $today10);
$recentLow = $service->forecast('2026-09', $current, [
    '2026-03' => history_row(220.0, 88.0, 900.0, 31),
    '2026-08' => history_row(60.0, 24.0, 600.0, 31),
], 0.0, $today10);
assert_forecast($recentHigh['sales']['projected'] > $recentLow['sales']['projected'], 'newer history should outweigh older history');

$hugeDay2 = current_aggregate('2026-09', [1000.0, 1000.0], [400.0, 400.0]);
$day2 = new DateTimeImmutable('2026-09-02 12:00:00', $tz);
$bounded = $service->forecast('2026-09', $hugeDay2, $baseHistory, 0.0, $day2);
$unboundedLinear = 2000.0 / 2.0 * 30.0;
assert_forecast($bounded['sales']['projected'] < $unboundedLinear, 'early-month pace must be bounded');

$current20 = current_aggregate('2026-09', array_fill(0, 20, 150.0), array_fill(0, 20, 60.0));
$day3Forecast = $service->forecast('2026-09', current_aggregate('2026-09', array_fill(0, 3, 150.0), array_fill(0, 3, 60.0)), $baseHistory, 0.0, new DateTimeImmutable('2026-09-03 12:00:00', $tz));
$day20Forecast = $service->forecast('2026-09', $current20, $baseHistory, 0.0, new DateTimeImmutable('2026-09-20 12:00:00', $tz));
assert_forecast(($day20Forecast['sales']['high'] - $day20Forecast['sales']['low']) < ($day3Forecast['sales']['high'] - $day3Forecast['sales']['low']), 'range should narrow later in month');

$marketingOverride = $service->forecast('2026-09', $current, $baseHistory, 1200.0, $today10);
$marketingFallback = $service->forecast('2026-09', $current, $baseHistory, 0.0, $today10);
assert_forecast($marketingOverride['netEarnings']['projected'] <= $marketingFallback['netEarnings']['projected'], 'saved current marketing should override lower historical fallback');
assert_forecast(! array_key_exists('otherCosts', $marketingOverride), 'Other Costs must not be part of forecast payload');

$sameSalesDifferentCommissionA = $service->forecast('2026-09', $current, [
    '2026-08' => history_row(100.0, 20.0, 0.0, 31),
], 0.0, $today10);
$sameSalesDifferentCommissionB = $service->forecast('2026-09', $current, [
    '2026-08' => history_row(100.0, 70.0, 0.0, 31),
], 0.0, $today10);
assert_forecast($sameSalesDifferentCommissionA['sales']['projected'] === $sameSalesDifferentCommissionB['sales']['projected'], 'sales projection should stay fixed when only commission history changes');
assert_forecast($sameSalesDifferentCommissionA['netEarnings']['projected'] !== $sameSalesDifferentCommissionB['netEarnings']['projected'], 'net earnings must use commission history rather than flat sales percentage');

echo "PASS forecast service\n";
