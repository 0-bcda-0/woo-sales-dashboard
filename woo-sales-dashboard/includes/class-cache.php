<?php

defined('ABSPATH') || exit;

final class WSD_Cache {
    private const PREFIX = 'wsd_v2_';
    private const CURRENT_TTL = 300;
    private const HISTORICAL_TTL = 31536000;

    public function key(string $month): string { return self::PREFIX . str_replace('-', '_', $month); }
    public function is_current_month(string $month): bool { return $month === wp_date('Y-m'); }

    public function get(string $month, int $classificationRevision = 1) {
        $value = get_transient($this->key($month));
        if (! is_array($value)) return false;
        if ((int)($value['_classificationRevision'] ?? 0) !== $classificationRevision) return false;
        unset($value['_classificationRevision']);
        return $value;
    }

    public function set(string $month, array $aggregate, int $classificationRevision = 1): bool {
        $aggregate['_classificationRevision'] = $classificationRevision;
        return set_transient($this->key($month), $aggregate, $this->is_current_month($month) ? self::CURRENT_TTL : self::HISTORICAL_TTL);
    }

    public function delete(string $month): bool { return delete_transient($this->key($month)); }

    public function register_invalidation_hooks(): void {
        add_action('woocommerce_new_order', [$this, 'invalidate_order']);
        add_action('woocommerce_update_order', [$this, 'invalidate_order']);
        add_action('woocommerce_order_status_changed', [$this, 'invalidate_order']);
        add_action('woocommerce_order_refunded', [$this, 'invalidate_order']);
        add_action('woocommerce_delete_order', [$this, 'invalidate_order']);
        add_action('woocommerce_trash_order', [$this, 'invalidate_order']);
    }

    public function invalidate_order($orderId): void {
        $order = is_object($orderId) ? $orderId : wc_get_order((int) $orderId);
        if (! $order) return;
        $created = $order->get_date_created();
        if ($created) $this->delete($created->setTimezone(wp_timezone())->format('Y-m'));
    }
}
