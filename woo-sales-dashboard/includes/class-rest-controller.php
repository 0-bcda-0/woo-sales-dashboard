<?php

defined('ABSPATH') || exit;

final class WSD_REST_Controller {
    public function __construct(private WSD_Cache $cache, private WSD_Order_Data_Provider $provider, private WSD_Dashboard_Service $service) {}

    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }

    public function routes(): void {
        register_rest_route('woo-sales-dashboard/v1', '/month', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'month'],
            'permission_callback' => static fn() => current_user_can('view_woocommerce_reports'),
            'args' => ['month' => ['required' => true, 'type' => 'string'], 'refresh' => ['required' => false, 'type' => 'boolean']],
        ]);
    }

    public function month(WP_REST_Request $request) {
        $month = sanitize_text_field((string) $request->get_param('month'));
        if (! $this->provider->is_valid_month($month)) return new WP_Error('wsd_invalid_month', 'Month must use YYYY-MM format.', ['status' => 400]);
        if ($this->provider->is_future_month($month)) return new WP_Error('wsd_future_month', 'Future months are not available.', ['status' => 400]);

        $refresh = (bool) $request->get_param('refresh') && $this->cache->is_current_month($month);
        if ($refresh) $this->cache->delete($month);
        $selected = $this->aggregate($month);
        $previousMonth = DateTimeImmutable::createFromFormat('!Y-m', $month, wp_timezone())->modify('-1 month')->format('Y-m');
        $previous = $this->aggregate($previousMonth);
        $comparison = $this->service->comparison($selected, $previous, $month);

        $productsQuantity = $selected['products'];
        usort($productsQuantity, static fn($a,$b) => $b['quantity'] <=> $a['quantity'] ?: $b['revenue'] <=> $a['revenue']);
        $productsRevenue = $selected['products'];
        usort($productsRevenue, static fn($a,$b) => $b['revenue'] <=> $a['revenue'] ?: $b['quantity'] <=> $a['quantity']);

        return rest_ensure_response([
            'month' => $month,
            'isCurrentMonth' => $this->cache->is_current_month($month),
            'updatedAt' => current_time('c'),
            'currency' => ['code' => get_woocommerce_currency(), 'symbol' => get_woocommerce_currency_symbol(), 'decimals' => wc_get_price_decimals(), 'decimalSeparator' => wc_get_price_decimal_separator(), 'thousandSeparator' => wc_get_price_thousand_separator()],
            'totals' => $selected['totals'],
            'secondary' => $selected['secondary'],
            'shipping' => $selected['shipping'],
            'daily' => array_values(array_map(static fn($date,$values) => ['date'=>$date] + $values, array_keys($selected['daily']), $selected['daily'])),
            'comparison' => $comparison,
            'products' => ['quantity' => array_slice($productsQuantity, 0, 5), 'revenue' => array_slice($productsRevenue, 0, 5)],
        ]);
    }

    private function aggregate(string $month): array {
        $cached = $this->cache->get($month);
        if (is_array($cached)) return $cached;
        $aggregate = $this->service->aggregate_month($month, $this->provider->get_orders_for_month($month));
        $this->cache->set($month, $aggregate);
        return $aggregate;
    }
}
