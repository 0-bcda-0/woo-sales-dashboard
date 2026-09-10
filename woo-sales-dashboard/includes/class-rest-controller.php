<?php

defined('ABSPATH') || exit;

final class WSD_REST_Controller {
    public function __construct(
        private WSD_Cache $cache,
        private WSD_Order_Data_Provider $provider,
        private WSD_Dashboard_Service $service,
        private WSD_Commission_Service $commissionService,
        private WSD_Settings_Store $settings,
        private WSD_Report_Service $reportService
    ) {}

    public function register(): void {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_ajax_wsd_preview_report', [$this, 'ajax_preview_report']);
        add_action('wp_ajax_wsd_send_report', [$this, 'ajax_send_report']);
    }

    public function routes(): void {
        $permission = static fn() => current_user_can('view_woocommerce_reports');
        register_rest_route('woo-sales-dashboard/v1', '/month', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'month'],
            'permission_callback' => $permission,
            'args' => ['month' => ['required' => true, 'type' => 'string'], 'refresh' => ['required' => false, 'type' => 'boolean']],
        ]);
        register_rest_route('woo-sales-dashboard/v1', '/settings', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_settings'], 'permission_callback' => $permission],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'save_settings'], 'permission_callback' => $permission],
        ]);
        register_rest_route('woo-sales-dashboard/v1', '/costs', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'save_costs'], 'permission_callback' => $permission,
        ]);
        register_rest_route('woo-sales-dashboard/v1', '/test-email', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'test_email'], 'permission_callback' => $permission,
        ]);
        register_rest_route('woo-sales-dashboard/v1', '/report/preview', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'preview_report'], 'permission_callback' => $permission,
        ]);
        register_rest_route('woo-sales-dashboard/v1', '/report/send', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'send_report'], 'permission_callback' => $permission,
        ]);
        register_rest_route('woo-sales-dashboard/v1', '/commission-override', [
            'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'save_commission_override'], 'permission_callback' => $permission,
        ]);
    }

    public function ajax_preview_report(): void {
        $this->ajax_guard();
        $month = sanitize_text_field((string)($_POST['month'] ?? ''));
        $valid = $this->validate_month($month);
        if (is_wp_error($valid)) wp_send_json_error(['message'=>$valid->get_error_message()], 400);
        $mode = (string)($_POST['mode'] ?? '') === 'print' ? 'print' : 'preview';
        $payload = $this->reportService->build_payload($month, $this->month_payload($month));
        wp_send_json_success(['payload'=>$payload,'html'=>$this->reportService->render_html($payload,$mode)]);
    }

    public function ajax_send_report(): void {
        $this->ajax_guard();
        $month = sanitize_text_field((string)($_POST['month'] ?? ''));
        $valid = $this->validate_month($month);
        if (is_wp_error($valid)) wp_send_json_error(['message'=>$valid->get_error_message()], 400);
        $recipient = sanitize_email((string)($_POST['recipient'] ?? $this->settings->get_report_email()));
        if (! is_email($recipient)) wp_send_json_error(['message'=>'Enter a valid report email.'], 400);
        $payload = $this->reportService->build_payload($month, $this->month_payload($month));
        $subject = 'Monthly Commission Report — ' . $payload['monthLabel'];
        $sent = wp_mail($recipient, $subject, $this->reportService->render_html($payload,'email'), ['Content-Type: text/html; charset=UTF-8']);
        if (! $sent) wp_send_json_error(['message'=>'WordPress could not send the report email.'], 500);
        $audit = $this->settings->mark_sent($month, $recipient, current_time('c'));
        wp_send_json_success(['sent'=>true,'recipient'=>$recipient,'reportAudit'=>['last_sent_at'=>$audit['last_sent_at'],'last_sent_to'=>$audit['last_sent_to']]]);
    }

    public function save_commission_override(WP_REST_Request $request) {
        $type = sanitize_key((string)$request->get_param('type'));
        $orderId = absint($request->get_param('orderId'));
        $itemId = absint($request->get_param('itemId'));
        $force = (bool)$request->get_param('forceStandard');
        if (! in_array($type, ['vip','bundle'], true) || $orderId <= 0) return new WP_Error('wsd_invalid_override', 'Invalid commission override.', ['status'=>400]);
        $order = wc_get_order($orderId);
        if (! $order) return new WP_Error('wsd_order_not_found', 'Order was not found.', ['status'=>404]);
        if ($type === 'vip') {
            $order->update_meta_data(WSD_Snapshot_Service::FORCE_STANDARD_ORDER_META, $force ? '1' : '0');
            $order->save();
        } else {
            $items = $order->get_items('line_item');
            if ($itemId <= 0 || ! isset($items[$itemId])) return new WP_Error('wsd_item_not_found', 'Order item was not found.', ['status'=>404]);
            $items[$itemId]->update_meta_data(WSD_Snapshot_Service::FORCE_STANDARD_ITEM_META, $force ? '1' : '0');
            $items[$itemId]->save();
        }
        $created = $order->get_date_created();
        if ($created) $this->cache->delete($created->setTimezone(wp_timezone())->format('Y-m'));
        return rest_ensure_response(['saved'=>true,'forceStandard'=>$force]);
    }

    private function ajax_guard(): void {
        if (! current_user_can('view_woocommerce_reports')) wp_send_json_error(['message'=>'You do not have permission to use this action.'], 403);
        if (! check_ajax_referer('wsd_report_ajax', 'nonce', false)) wp_send_json_error(['message'=>'Security check failed. Refresh the page and try again.'], 403);
    }

    public function month(WP_REST_Request $request) {
        $month = sanitize_text_field((string) $request->get_param('month'));
        $valid = $this->validate_month($month);
        if (is_wp_error($valid)) return $valid;
        return rest_ensure_response($this->month_payload($month, (bool)$request->get_param('refresh')));
    }

    public function get_settings() {
        return rest_ensure_response([
            'bundleSkus' => $this->settings->get_bundle_skus(),
            'reportEmail' => $this->settings->get_report_email(),
            'classificationRevision' => $this->settings->classification_revision(),
        ]);
    }

    public function save_settings(WP_REST_Request $request) {
        try {
            $changedBundle = false;
            $email = $request->get_param('reportEmail');
            $rawEmail = $email === null ? null : trim((string)$email);
            if ($rawEmail !== null && $rawEmail !== '' && ! is_email($rawEmail)) return new WP_Error('wsd_invalid_email', 'Enter a valid report email.', ['status'=>400]);
            $bundleSkus = $request->get_param('bundleSkus');
            if ($bundleSkus !== null) {
                if (! is_array($bundleSkus)) return new WP_Error('wsd_invalid_skus', 'Bundle SKUs must be an array.', ['status'=>400]);
                $before = $this->settings->get_bundle_skus();
                $after = $this->settings->save_bundle_skus($bundleSkus);
                $changedBundle = $before !== $after;
            }
            if ($rawEmail !== null) $this->settings->save_report_email($rawEmail);
            return rest_ensure_response([
                'bundleSkus' => $this->settings->get_bundle_skus(),
                'reportEmail' => $this->settings->get_report_email(),
                'classificationRevision' => $this->settings->classification_revision(),
                'bundleChanged' => $changedBundle,
            ]);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('wsd_invalid_settings', $e->getMessage(), ['status'=>400]);
        }
    }

    public function save_costs(WP_REST_Request $request) {
        $month = sanitize_text_field((string)$request->get_param('month'));
        $valid = $this->validate_month($month);
        if (is_wp_error($valid)) return $valid;
        $marketing = $request->get_param('marketing');
        $other = $request->get_param('otherCosts');
        if (! is_numeric($marketing) || ! is_numeric($other)) return new WP_Error('wsd_invalid_costs', 'Costs must be numeric.', ['status'=>400]);
        try {
            $costs = $this->settings->save_costs($month, (float)$marketing, (float)$other);
            $aggregate = $this->aggregate($month);
            $commission = $this->commissionService->calculate(
                $aggregate['commissionSource']['standardSales'], $aggregate['commissionSource']['vipSales'], $aggregate['commissionSource']['bundleSales'],
                $costs['marketing'], $costs['other_costs']
            );
            return rest_ensure_response(['costs'=>$costs,'commission'=>$commission]);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('wsd_invalid_costs', $e->getMessage(), ['status'=>400]);
        }
    }

    public function test_email(WP_REST_Request $request) {
        $recipient = sanitize_email((string)($request->get_param('recipient') ?: $this->settings->get_report_email()));
        if (! is_email($recipient)) return new WP_Error('wsd_invalid_email', 'Enter a valid report email.', ['status'=>400]);
        $subject = '[TEST] Woo Sales Dashboard report email';
        $body = '<div style="font-family:Arial,sans-serif"><h2>Woo Sales Dashboard</h2><p>This is a test email. Report delivery is configured correctly.</p></div>';
        $sent = wp_mail($recipient, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        if (! $sent) return new WP_Error('wsd_mail_failed', 'WordPress could not send the test email.', ['status'=>500]);
        return rest_ensure_response(['sent'=>true,'recipient'=>$recipient]);
    }

    public function preview_report(WP_REST_Request $request) {
        $month = sanitize_text_field((string)$request->get_param('month'));
        $valid = $this->validate_month($month);
        if (is_wp_error($valid)) return $valid;
        $mode = $request->get_param('mode') === 'print' ? 'print' : 'preview';
        $payload = $this->reportService->build_payload($month, $this->month_payload($month));
        return rest_ensure_response(['payload'=>$payload,'html'=>$this->reportService->render_html($payload,$mode)]);
    }

    public function send_report(WP_REST_Request $request) {
        $month = sanitize_text_field((string)$request->get_param('month'));
        $valid = $this->validate_month($month);
        if (is_wp_error($valid)) return $valid;
        $recipient = sanitize_email((string)($request->get_param('recipient') ?: $this->settings->get_report_email()));
        if (! is_email($recipient)) return new WP_Error('wsd_invalid_email', 'Enter a valid report email.', ['status'=>400]);
        $monthData = $this->month_payload($month);
        $payload = $this->reportService->build_payload($month, $monthData);
        $subject = 'Monthly Commission Report — ' . $payload['monthLabel'];
        $sent = wp_mail($recipient, $subject, $this->reportService->render_html($payload,'email'), ['Content-Type: text/html; charset=UTF-8']);
        if (! $sent) return new WP_Error('wsd_mail_failed', 'WordPress could not send the report email.', ['status'=>500]);
        $audit = $this->settings->mark_sent($month, $recipient, current_time('c'));
        return rest_ensure_response(['sent'=>true,'recipient'=>$recipient,'reportAudit'=>['last_sent_at'=>$audit['last_sent_at'],'last_sent_to'=>$audit['last_sent_to']]]);
    }

    public function month_payload(string $month, bool $refresh = false): array {
        if ($refresh && $this->cache->is_current_month($month)) $this->cache->delete($month);
        $selected = $this->aggregate($month);
        $previousMonth = DateTimeImmutable::createFromFormat('!Y-m', $month, wp_timezone())->modify('-1 month')->format('Y-m');
        $previous = $this->aggregate($previousMonth);
        $comparison = $this->service->comparison($selected, $previous, $month);

        $productsQuantity = $selected['products'];
        usort($productsQuantity, static fn($a,$b) => $b['quantity'] <=> $a['quantity'] ?: $b['revenue'] <=> $a['revenue']);
        $productsRevenue = $selected['products'];
        usort($productsRevenue, static fn($a,$b) => $b['revenue'] <=> $a['revenue'] ?: $b['quantity'] <=> $a['quantity']);

        $costs = $this->settings->get_month($month);
        $commission = $this->commissionService->calculate(
            $selected['commissionSource']['standardSales'], $selected['commissionSource']['vipSales'], $selected['commissionSource']['bundleSales'],
            $costs['marketing'], $costs['other_costs']
        );

        [$daily,$previousDaily] = $this->aligned_daily($selected['daily'], $previous['daily'], $month);
        $now = current_time('c');
        return [
            'month' => $month,
            'isCurrentMonth' => $this->cache->is_current_month($month),
            'updatedAt' => $now,
            'generatedAt' => $now,
            'currency' => ['code'=>get_woocommerce_currency(),'symbol'=>get_woocommerce_currency_symbol(),'decimals'=>wc_get_price_decimals(),'decimalSeparator'=>wc_get_price_decimal_separator(),'thousandSeparator'=>wc_get_price_thousand_separator()],
            'totals' => $selected['totals'],
            'secondary' => $selected['secondary'],
            'shipping' => $selected['shipping'],
            'daily' => $daily,
            'previousDaily' => $previousDaily,
            'comparison' => $comparison,
            'products' => ['quantity'=>array_slice($productsQuantity,0,5),'revenue'=>array_slice($productsRevenue,0,5)],
            'commission' => $commission,
            'specialSales' => $selected['specialSales'],
            'costs' => ['marketing'=>$costs['marketing'],'otherCosts'=>$costs['other_costs']],
            'reportAudit' => ['last_sent_at'=>$costs['last_sent_at'],'last_sent_to'=>$costs['last_sent_to']],
        ];
    }

    private function aggregate(string $month): array {
        $revision = $this->settings->classification_revision();
        $cached = $this->cache->get($month, $revision);
        if (is_array($cached)) return $cached;
        $aggregate = $this->service->aggregate_month($month, $this->provider->get_orders_for_month($month));
        $this->cache->set($month, $aggregate, $revision);
        return $aggregate;
    }

    private function aligned_daily(array $selected, array $previous, string $month): array {
        $selectedRows = array_values(array_map(static fn($date,$values)=>['date'=>$date]+$values,array_keys($selected),$selected));
        $previousRows = array_values(array_map(static fn($date,$values)=>['date'=>$date]+$values,array_keys($previous),$previous));
        $limit = $month === wp_date('Y-m') ? min(count($selectedRows),(int)wp_date('j')) : count($selectedRows);
        return [array_slice($selectedRows,0,$limit), array_slice($previousRows,0,min($limit,count($previousRows)))];
    }

    private function validate_month(string $month) {
        if (! $this->provider->is_valid_month($month)) return new WP_Error('wsd_invalid_month', 'Month must use YYYY-MM format.', ['status'=>400]);
        if ($this->provider->is_future_month($month)) return new WP_Error('wsd_future_month', 'Future months are not available.', ['status'=>400]);
        return true;
    }
}
