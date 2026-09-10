<?php

defined('ABSPATH') || exit;

final class WSD_Snapshot_Service {
    public const VIP_META = '_wsd_vip_at_order_time';
    public const BUNDLE_META = '_wsd_bundle_at_order_time';
    public const FORCE_STANDARD_ORDER_META = '_wsd_force_standard_commission';
    public const FORCE_STANDARD_ITEM_META = '_wsd_force_standard_commission';
    private ?array $bundleSkus = null;

    public function __construct(private WSD_Settings_Store $settings) {}

    public function is_force_standard_order($order): bool {
        return (string)$order->get_meta(self::FORCE_STANDARD_ORDER_META, true) === '1';
    }

    public function is_force_standard_item($item): bool {
        return (string)$item->get_meta(self::FORCE_STANDARD_ITEM_META, true) === '1';
    }

    public function is_vip_order($order): bool {
        $snapshot = (string) $order->get_meta(self::VIP_META, true);
        if ($snapshot === '1') return true;
        if ($snapshot === '0') return false;
        $user = method_exists($order, 'get_user') ? $order->get_user() : null;
        if (! $user && method_exists($order, 'get_user_id')) {
            $userId = (int) $order->get_user_id();
            if ($userId > 0 && function_exists('get_user_by')) $user = get_user_by('id', $userId);
        }
        return $user && in_array('nishman_vip', (array) $user->roles, true);
    }

    public function is_bundle_item($order, $item): bool {
        $snapshot = (string) $item->get_meta(self::BUNDLE_META, true);
        if ($snapshot === '1') return true;
        if ($snapshot === '0') return false;
        $sku = $this->item_sku($item);
        if ($sku === '') return false;
        foreach ($this->bundle_skus() as $configured) {
            if (strcasecmp((string)$configured, $sku) === 0) return true;
        }
        return false;
    }

    public function snapshot_order($order): void {
        $orderSnapshot = (string) $order->get_meta(self::VIP_META, true);
        if ($orderSnapshot !== '0' && $orderSnapshot !== '1') {
            $order->update_meta_data(self::VIP_META, $this->is_vip_order($order) ? '1' : '0');
            $order->save();
        }
        foreach ($order->get_items('line_item') as $item) {
            $snapshot = (string) $item->get_meta(self::BUNDLE_META, true);
            if ($snapshot === '0' || $snapshot === '1') continue;
            $item->update_meta_data(self::BUNDLE_META, $this->is_bundle_item($order, $item) ? '1' : '0');
            $item->save();
        }
    }

    public function snapshot_order_by_id($orderId): void {
        if (! function_exists('wc_get_order')) return;
        $order = is_object($orderId) ? $orderId : wc_get_order((int)$orderId);
        if ($order) $this->snapshot_order($order);
    }

    public function snapshot_on_status($orderId, string $from = '', string $to = ''): void {
        if (! in_array($to, ['processing','completed'], true)) return;
        $this->snapshot_order_by_id($orderId);
    }

    private function bundle_skus(): array {
        return $this->bundleSkus ??= $this->settings->get_bundle_skus();
    }

    public function item_sku($item): string {
        $product = method_exists($item, 'get_product') ? $item->get_product() : null;
        if ($product && method_exists($product, 'get_sku')) {
            $sku = trim((string)$product->get_sku());
            if ($sku !== '') return $sku;
        }
        foreach (['get_variation_id','get_product_id'] as $getter) {
            if (! method_exists($item, $getter)) continue;
            $id = (int)$item->{$getter}();
            if ($id <= 0 || ! function_exists('wc_get_product')) continue;
            $product = wc_get_product($id);
            if ($product && method_exists($product, 'get_sku')) {
                $sku = trim((string)$product->get_sku());
                if ($sku !== '') return $sku;
            }
        }
        return '';
    }
}
