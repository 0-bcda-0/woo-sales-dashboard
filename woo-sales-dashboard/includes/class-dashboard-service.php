<?php

defined('ABSPATH') || exit;

final class WSD_Dashboard_Service {
    private const SUCCESS = ['processing', 'completed'];
    private const NEGATIVE = ['cancelled', 'failed', 'refunded'];

    public function __construct(
        private ?WSD_Commission_Service $commissionService = null,
        private ?WSD_Snapshot_Service $snapshotService = null
    ) {}

    public function aggregate_month(string $month, array $orders): array {
        $tz = wp_timezone();
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month, $tz);
        $daily = [];
        for ($day = 1; $day <= (int) $start->format('t'); $day++) {
            $date = $start->setDate((int) $start->format('Y'), (int) $start->format('m'), $day)->format('Y-m-d');
            $daily[$date] = [
                'sales' => 0.0,
                'orders' => 0,
                'items' => 0.0,
                'aov' => 0.0,
                'itemsPerOrder' => 0.0,
                'standardSales' => 0.0,
                'vipSales' => 0.0,
                'bundleSales' => 0.0,
                'commissionToPay' => 0.0,
            ];
        }

        $out = [
            'month' => $month,
            'totals' => ['sales' => 0.0, 'orders' => 0, 'items' => 0.0, 'shipping' => 0.0, 'aov' => 0.0, 'itemsPerOrder' => 0.0],
            'secondary' => ['negative' => 0],
            'shipping' => ['paidOrders' => 0, 'freeOrders' => 0],
            'daily' => $daily,
            'products' => [],
            'commissionSource' => ['productSales' => 0.0, 'standardSales' => 0.0, 'vipSales' => 0.0, 'bundleSales' => 0.0],
            'commission' => $this->zero_commission(),
            'specialSales' => ['vip' => [], 'bundle' => []],
        ];

        foreach ($orders as $order) {
            $status = $order->get_status();
            if (in_array($status, self::NEGATIVE, true)) { $out['secondary']['negative']++; continue; }
            if (! in_array($status, self::SUCCESS, true)) continue;
            $created = $order->get_date_created();
            if (! $created) continue;
            $date = $created->setTimezone($tz)->format('Y-m-d');
            if (! isset($out['daily'][$date])) continue;

            $netSales = max(0.0, (float) $order->get_total() - abs((float) $order->get_total_refunded()));
            $netShipping = max(0.0, (float) $order->get_shipping_total() + (float) $order->get_shipping_tax() - abs((float) $order->get_total_shipping_refunded()));
            $orderItems = 0.0;
            $isVip = $this->snapshotService ? $this->snapshotService->is_vip_order($order) : false;
            $vipOrderRevenue = 0.0;

            foreach ($order->get_items('line_item') as $itemId => $item) {
                $netQty = max(0.0, (float) $item->get_quantity() - abs((float) $order->get_qty_refunded_for_item($itemId)));
                $orderItems += $netQty;
                $netLine = max(0.0, (float) $item->get_total() + (float) $item->get_total_tax() - abs((float) $order->get_total_refunded_for_item($itemId, true)));

                $bucket = 'standard';
                if ($isVip) {
                    $bucket = 'vip';
                    $vipOrderRevenue += $netLine;
                } elseif ($this->snapshotService && $this->snapshotService->is_bundle_item($order, $item)) {
                    $bucket = 'bundle';
                }

                $sourceKey = $bucket . 'Sales';
                $out['commissionSource']['productSales'] += $netLine;
                $out['commissionSource'][$sourceKey] += $netLine;
                $out['daily'][$date][$sourceKey] += $netLine;

                if ($bucket === 'bundle' && $netLine > 0.0) {
                    $out['specialSales']['bundle'][] = [
                        'date' => $date,
                        'orderNumber' => $this->order_number($order),
                        'product' => (string) $item->get_name(),
                        'sku' => $this->snapshotService ? $this->snapshotService->item_sku($item) : '',
                        'revenue' => $netLine,
                    ];
                }

                $product = $item->get_product();
                $productId = (int) $item->get_product_id();
                if ($product && $product->is_type('variation')) $productId = (int) $product->get_parent_id();
                if (! $productId) continue;
                $parent = wc_get_product($productId);
                $name = $parent ? $parent->get_name() : $item->get_name();
                if (! isset($out['products'][$productId])) $out['products'][$productId] = ['id' => $productId, 'name' => $name, 'quantity' => 0.0, 'revenue' => 0.0];
                $out['products'][$productId]['quantity'] += $netQty;
                $out['products'][$productId]['revenue'] += $netLine;
            }

            if ($isVip && $vipOrderRevenue > 0.0) {
                $out['specialSales']['vip'][] = [
                    'date' => $date,
                    'orderNumber' => $this->order_number($order),
                    'customer' => $this->customer_name($order),
                    'revenue' => $vipOrderRevenue,
                ];
            }

            $out['totals']['sales'] += $netSales;
            $out['totals']['orders']++;
            $out['totals']['items'] += $orderItems;
            $out['totals']['shipping'] += $netShipping;
            $netShipping > 0.00001 ? $out['shipping']['paidOrders']++ : $out['shipping']['freeOrders']++;
            $out['daily'][$date]['sales'] += $netSales;
            $out['daily'][$date]['orders']++;
            $out['daily'][$date]['items'] += $orderItems;
        }

        $count = $out['totals']['orders'];
        $out['totals']['aov'] = $count ? $out['totals']['sales'] / $count : 0.0;
        $out['totals']['itemsPerOrder'] = $count ? $out['totals']['items'] / $count : 0.0;
        foreach ($out['daily'] as &$day) {
            $day['aov'] = $day['orders'] ? $day['sales'] / $day['orders'] : 0.0;
            $day['itemsPerOrder'] = $day['orders'] ? $day['items'] / $day['orders'] : 0.0;
            if ($this->commissionService) {
                $dailyCommission = $this->commissionService->calculate($day['standardSales'], $day['vipSales'], $day['bundleSales']);
                $day['commissionToPay'] = $dailyCommission['commissionToPay'];
            }
        }
        unset($day);

        if ($this->commissionService) {
            $out['commission'] = $this->commissionService->calculate(
                $out['commissionSource']['standardSales'],
                $out['commissionSource']['vipSales'],
                $out['commissionSource']['bundleSales']
            );
        }

        $out['products'] = array_values($out['products']);
        return $out;
    }

    public function comparison(array $selected, array $previous, string $selectedMonth): array {
        $limit = $selectedMonth === wp_date('Y-m') ? (int) wp_date('j') : PHP_INT_MAX;
        $a = $this->period_values($selected, $limit);
        $b = $this->period_values($previous, $limit);
        $result = [];
        foreach (['sales', 'orders', 'items', 'aov', 'itemsPerOrder'] as $key) {
            $result[$key] = ['current' => $a[$key], 'previous' => $b[$key], 'percent' => (float) $b[$key] === 0.0 ? null : (($a[$key] - $b[$key]) / abs($b[$key])) * 100];
        }
        return $result;
    }

    private function period_values(array $aggregate, int $limit): array {
        $sales = 0.0; $orders = 0; $items = 0.0; $index = 0;
        foreach ($aggregate['daily'] as $day) {
            if (++$index > $limit) break;
            $sales += $day['sales']; $orders += $day['orders']; $items += $day['items'];
        }
        return ['sales' => $sales, 'orders' => $orders, 'items' => $items, 'aov' => $orders ? $sales / $orders : 0.0, 'itemsPerOrder' => $orders ? $items / $orders : 0.0];
    }

    private function zero_commission(): array {
        if ($this->commissionService) return $this->commissionService->calculate(0,0,0);
        return ['productSales'=>0.0,'standardSales'=>0.0,'vipSales'=>0.0,'bundleSales'=>0.0,'standardVpc'=>0.0,'standardCommission'=>0.0,'vipCommission'=>0.0,'bundleCommission'=>0.0,'commissionToPay'=>0.0,'marketing'=>0.0,'otherCosts'=>0.0,'netEarnings'=>0.0];
    }

    private function order_number($order): string {
        return method_exists($order, 'get_order_number') ? (string)$order->get_order_number() : '';
    }

    private function customer_name($order): string {
        if (method_exists($order, 'get_formatted_billing_full_name')) {
            $name = trim((string)$order->get_formatted_billing_full_name());
            if ($name !== '') return $name;
        }
        if (method_exists($order, 'get_billing_first_name')) {
            $name = trim((string)$order->get_billing_first_name() . ' ' . (string)$order->get_billing_last_name());
            if ($name !== '') return $name;
        }
        return 'Guest';
    }
}
