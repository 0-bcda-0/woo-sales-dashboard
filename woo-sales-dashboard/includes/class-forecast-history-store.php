<?php

defined('ABSPATH') || exit;

final class WSD_Forecast_History_Store {
    private const OPTION = 'wsd_forecast_history';
    private const SCHEMA_VERSION = 1;

    public function get_all(): array {
        $raw = get_option(self::OPTION, []);
        if (! is_array($raw) || (int)($raw['_schemaVersion'] ?? 0) !== self::SCHEMA_VERSION) return [];
        $months = isset($raw['months']) && is_array($raw['months']) ? $raw['months'] : [];
        $out = [];
        foreach ($months as $month => $row) {
            if (! $this->valid_month((string)$month) || ! is_array($row)) continue;
            $out[(string)$month] = $this->normalize_row($row);
        }
        ksort($out);
        return $out;
    }

    public function get_month(string $month): ?array {
        if (! $this->valid_month($month)) return null;
        $all = $this->get_all();
        return $all[$month] ?? null;
    }

    public function put_month(string $month, array $aggregate, float $marketing): void {
        if (! $this->valid_month($month)) throw new InvalidArgumentException('Month must use YYYY-MM format.');
        $sales = [];
        $commission = [];
        foreach (($aggregate['daily'] ?? []) as $day) {
            if (! is_array($day)) continue;
            $sales[] = max(0.0, (float)($day['sales'] ?? 0.0));
            $commission[] = max(0.0, (float)($day['commissionToPay'] ?? 0.0));
        }
        $all = $this->get_all();
        $all[$month] = [
            'dailySales' => $sales,
            'dailyCommission' => $commission,
            'marketing' => max(0.0, $marketing),
        ];
        ksort($all);
        update_option(self::OPTION, ['_schemaVersion' => self::SCHEMA_VERSION, 'months' => $all], false);
    }

    public function delete_month(string $month): void {
        if (! $this->valid_month($month)) return;
        $all = $this->get_all();
        if (! array_key_exists($month, $all)) return;
        unset($all[$month]);
        update_option(self::OPTION, ['_schemaVersion' => self::SCHEMA_VERSION, 'months' => $all], false);
    }

    public function months(): array {
        return array_keys($this->get_all());
    }

    private function normalize_row(array $row): array {
        return [
            'dailySales' => array_values(array_map(static fn($v) => max(0.0, (float)$v), is_array($row['dailySales'] ?? null) ? $row['dailySales'] : [])),
            'dailyCommission' => array_values(array_map(static fn($v) => max(0.0, (float)$v), is_array($row['dailyCommission'] ?? null) ? $row['dailyCommission'] : [])),
            'marketing' => max(0.0, (float)($row['marketing'] ?? 0.0)),
        ];
    }

    private function valid_month(string $month): bool {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
    }
}
