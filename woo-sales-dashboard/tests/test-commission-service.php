<?php

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-commission-service.php';

function wsd_assert_commission($condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
}

$service = new WSD_Commission_Service();
$r = $service->calculate(1400.0, 100.0, 200.0, 50.0, 25.0);
wsd_assert_commission(abs($r['standardVpc'] - 1000.0) < 0.001, 'VPC');
wsd_assert_commission(abs($r['standardCommission'] - 600.0) < 0.001, 'standard commission');
wsd_assert_commission(abs($r['vipCommission'] - 20.0) < 0.001, 'VIP commission');
wsd_assert_commission(abs($r['bundleCommission'] - 40.0) < 0.001, 'Bundle commission');
wsd_assert_commission(abs($r['commissionToPay'] - 660.0) < 0.001, 'commission to pay');
wsd_assert_commission(abs($r['netEarnings'] - 585.0) < 0.001, 'net earnings');
$z = $service->calculate(-10, -10, -10, -5, -5);
wsd_assert_commission($z['commissionToPay'] === 0.0, 'negative sales clamped');
wsd_assert_commission($z['netEarnings'] === 0.0, 'negative costs clamped');
wsd_assert_commission(abs($service->bucket_commission('vip', 100) - 20) < 0.001, 'vip bucket');
wsd_assert_commission(abs($service->bucket_commission('bundle', 100) - 20) < 0.001, 'bundle bucket');
wsd_assert_commission(abs($service->bucket_commission('standard', 140) - 60) < 0.001, 'standard bucket');

echo "PASS commission service\n";
