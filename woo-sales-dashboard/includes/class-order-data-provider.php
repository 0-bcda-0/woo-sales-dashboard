<?php

defined('ABSPATH') || exit;

final class WSD_Order_Data_Provider {
    public const STATUSES = ['processing', 'completed', 'cancelled', 'failed', 'refunded'];
    public function is_valid_month(string $month): bool {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month, wp_timezone());
        return $date && $date->format('Y-m') === $month;
    }
    public function is_future_month(string $month): bool { return $month > wp_date('Y-m'); }
    public function month_bounds(string $month): array {
        if (! $this->is_valid_month($month)) throw new InvalidArgumentException('Invalid month.');
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month, wp_timezone())->setTime(0, 0, 0);
        return ['start' => $start, 'end' => $start->modify('first day of next month')];
    }
    public function get_orders_for_month(string $month): array {
        $bounds = $this->month_bounds($month);
        $range = $bounds['start']->format('Y-m-d') . '...' . $bounds['end']->modify('-1 day')->format('Y-m-d');
        $orders = []; $page = 1;
        do {
            $result = wc_get_orders(['status' => self::STATUSES, 'date_created' => $range, 'limit' => 100, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'ASC']);
            foreach ($result->orders as $order) $orders[] = $order;
            $page++;
        } while ($page <= (int) $result->max_num_pages);
        return $orders;
    }
}
