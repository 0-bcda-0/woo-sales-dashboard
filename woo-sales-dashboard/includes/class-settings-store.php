<?php

defined('ABSPATH') || exit;

final class WSD_Settings_Store {
    private const SETTINGS = 'wsd_v2_settings';
    private const MONTHS = 'wsd_v2_months';

    public function get_bundle_skus(): array {
        $settings = $this->settings();
        return array_values($settings['bundle_skus'] ?? []);
    }

    public function save_bundle_skus(array $skus): array {
        $normalized = [];
        $seen = [];
        foreach ($skus as $raw) {
            $sku = sanitize_text_field((string) $raw);
            if ($sku === '') continue;
            $productId = (int) wc_get_product_id_by_sku($sku);
            if (! $productId) throw new InvalidArgumentException('Unknown Bundle SKU: ' . $sku);
            $product = wc_get_product($productId);
            $canonical = $product && $product->get_sku() !== '' ? (string) $product->get_sku() : $sku;
            $key = strtolower($canonical);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $normalized[] = $canonical;
        }
        $settings = $this->settings();
        $old = array_values($settings['bundle_skus'] ?? []);
        if ($old !== $normalized) {
            $settings['bundle_skus'] = $normalized;
            $settings['classification_revision'] = max(1, (int)($settings['classification_revision'] ?? 1)) + 1;
            update_option(self::SETTINGS, $settings, false);
        }
        return $normalized;
    }

    public function get_report_email(): string {
        return (string) ($this->settings()['report_email'] ?? '');
    }

    public function save_report_email(string $email): string {
        $email = trim($email);
        if ($email !== '' && ! is_email($email)) throw new InvalidArgumentException('Invalid report email.');
        $settings = $this->settings();
        $settings['report_email'] = $email;
        update_option(self::SETTINGS, $settings, false);
        return $email;
    }

    public function classification_revision(): int {
        return max(1, (int)($this->settings()['classification_revision'] ?? 1));
    }

    public function get_month(string $month): array {
        $this->assert_month($month);
        $months = get_option(self::MONTHS, []);
        $value = is_array($months) && isset($months[$month]) && is_array($months[$month]) ? $months[$month] : [];
        return [
            'marketing' => (float)($value['marketing'] ?? 0.0),
            'other_costs' => (float)($value['other_costs'] ?? 0.0),
            'last_sent_at' => (string)($value['last_sent_at'] ?? ''),
            'last_sent_to' => (string)($value['last_sent_to'] ?? ''),
        ];
    }

    public function save_costs(string $month, float $marketing, float $otherCosts): array {
        $this->assert_month($month);
        if ($marketing < 0 || $otherCosts < 0) throw new InvalidArgumentException('Costs cannot be negative.');
        $months = get_option(self::MONTHS, []);
        if (! is_array($months)) $months = [];
        $current = $this->get_month($month);
        $current['marketing'] = $marketing;
        $current['other_costs'] = $otherCosts;
        $months[$month] = $current;
        update_option(self::MONTHS, $months, false);
        return $current;
    }

    public function mark_sent(string $month, string $recipient, string $timestamp): array {
        $this->assert_month($month);
        if (! is_email($recipient)) throw new InvalidArgumentException('Invalid recipient email.');
        $months = get_option(self::MONTHS, []);
        if (! is_array($months)) $months = [];
        $current = $this->get_month($month);
        $current['last_sent_at'] = sanitize_text_field($timestamp);
        $current['last_sent_to'] = $recipient;
        $months[$month] = $current;
        update_option(self::MONTHS, $months, false);
        return $current;
    }

    private function settings(): array {
        $settings = get_option(self::SETTINGS, []);
        if (! is_array($settings)) $settings = [];
        return $settings + ['bundle_skus' => [], 'report_email' => '', 'classification_revision' => 1];
    }

    private function assert_month(string $month): void {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new InvalidArgumentException('Month must use YYYY-MM format.');
    }
}
