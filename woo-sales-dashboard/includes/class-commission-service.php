<?php

defined('ABSPATH') || exit;

final class WSD_Commission_Service {
    public function bucket_commission(string $bucket, float $sales): float {
        $sales = max(0.0, $sales);
        if ($bucket === 'standard') {
            $vpc = $sales / 1.4;
            return ($sales - $vpc) + ($vpc * 0.20);
        }
        if ($bucket === 'vip' || $bucket === 'bundle') return $sales * 0.20;
        throw new InvalidArgumentException('Unknown commission bucket.');
    }

    public function calculate(float $standardSales, float $vipSales, float $bundleSales, float $marketing = 0.0, float $otherCosts = 0.0): array {
        $s = max(0.0, $standardSales);
        $v = max(0.0, $vipSales);
        $b = max(0.0, $bundleSales);
        $marketing = max(0.0, $marketing);
        $otherCosts = max(0.0, $otherCosts);
        $vpc = $s / 1.4;
        $standardCommission = ($s - $vpc) + ($vpc * 0.20);
        $vipCommission = $v * 0.20;
        $bundleCommission = $b * 0.20;
        $commissionToPay = $standardCommission + $vipCommission + $bundleCommission;
        return [
            'productSales' => $s + $v + $b,
            'standardSales' => $s,
            'vipSales' => $v,
            'bundleSales' => $b,
            'standardVpc' => $vpc,
            'standardCommission' => $standardCommission,
            'vipCommission' => $vipCommission,
            'bundleCommission' => $bundleCommission,
            'commissionToPay' => $commissionToPay,
            'marketing' => $marketing,
            'otherCosts' => $otherCosts,
            'netEarnings' => $commissionToPay - $marketing - $otherCosts,
        ];
    }
}
