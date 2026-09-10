<?php

defined('ABSPATH') || exit;

final class WSD_Forecast_Service {
    private const RECENCY_DECAY = 0.88;
    private const SAME_MONTH_MULTIPLIER = 1.60;
    private const PACE_MIN = 0.55;
    private const PACE_MAX = 1.80;
    private const FULL_PACE_WEIGHT_DAY = 14;
    private const RANGE_FLOOR_RATIO = 0.05;
    private const RANGE_MAX_RATIO = 0.45;

    public function forecast(string $month, array $currentAggregate, array $history, float $currentMarketing, DateTimeImmutable $today): ?array {
        if ($month !== $today->format('Y-m')) return null;
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month, $today->getTimezone());
        if (! $start) return null;

        $history = $this->history_before($history, $month);
        $sales = $this->forecast_series($month, $currentAggregate['daily'] ?? [], $history, 'dailySales', $today);
        $commission = $this->forecast_series($month, $currentAggregate['daily'] ?? [], $history, 'dailyCommission', $today);
        if ($sales === null || $commission === null) return null;

        $marketing = $currentMarketing > 0.0 ? $currentMarketing : $this->weighted_marketing($history, $month);

        return [
            'sales' => $sales,
            'netEarnings' => [
                'projected' => max(0.0, $commission['projected'] - $marketing),
                'low' => max(0.0, $commission['low'] - $marketing),
                'high' => max(0.0, $commission['high'] - $marketing),
            ],
        ];
    }

    private function forecast_series(string $month, array $currentDaily, array $history, string $historySeries, DateTimeImmutable $today): ?array {
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month, $today->getTimezone());
        if (! $start) return null;
        $daysInMonth = (int)$start->format('t');
        $elapsedDays = min((int)$today->format('j'), $daysInMonth);
        if ($elapsedDays <= 0) return null;

        $actual = 0.0;
        $baselineElapsed = 0.0;
        $remainingBaseline = 0.0;
        $currentValues = [];
        foreach ($currentDaily as $date => $row) {
            if (! is_array($row) || substr((string)$date, 0, 7) !== $month) continue;
            $day = (int)substr((string)$date, 8, 2);
            if ($day < 1 || $day > $daysInMonth) continue;
            $key = $historySeries === 'dailyCommission' ? 'commissionToPay' : 'sales';
            if ($day <= $elapsedDays) {
                $value = max(0.0, (float)($row[$key] ?? 0.0));
                $actual += $value;
                $currentValues[$day] = $value;
            }
        }

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $start->setDate((int)$start->format('Y'), (int)$start->format('m'), $day);
            $baseline = $this->weekday_baseline($history, $historySeries, (int)$date->format('N'), $month);
            if ($baseline === null) $baseline = $this->weighted_daily_average($history, $historySeries, $month);
            if ($baseline === null && $currentValues) $baseline = array_sum($currentValues) / count($currentValues);
            $baseline = max(0.0, (float)($baseline ?? 0.0));
            if ($day <= $elapsedDays) $baselineElapsed += $baseline;
            else $remainingBaseline += $baseline;
        }

        if ($remainingBaseline <= 0.0 && ! $currentValues) {
            return ['projected' => $actual, 'low' => $actual, 'high' => $actual];
        }

        $pace = $this->bounded_pace($actual, $baselineElapsed);
        $paceWeight = $this->current_weight($elapsedDays);
        $adjustment = 1.0 + (($pace - 1.0) * $paceWeight);
        $projected = max($actual, $actual + ($remainingBaseline * $adjustment));

        [$low, $high] = $this->range_from_history($projected, $actual, $remainingBaseline, $history, $historySeries, $month, $elapsedDays, $daysInMonth);
        return ['projected' => $projected, 'low' => $low, 'high' => $high];
    }

    private function history_before(array $history, string $month): array {
        $out = [];
        foreach ($history as $key => $row) {
            if (! is_string($key) || $key >= $month || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key) || ! is_array($row)) continue;
            $out[$key] = $row;
        }
        ksort($out);
        return $out;
    }

    private function recency_weight(int $monthsAgo): float {
        return pow(self::RECENCY_DECAY, max(0, $monthsAgo - 1));
    }

    private function months_ago(string $historicalMonth, string $targetMonth): int {
        $h = DateTimeImmutable::createFromFormat('!Y-m', $historicalMonth, new DateTimeZone('UTC'));
        $t = DateTimeImmutable::createFromFormat('!Y-m', $targetMonth, new DateTimeZone('UTC'));
        if (! $h || ! $t) return 120;
        return max(1, (((int)$t->format('Y') - (int)$h->format('Y')) * 12) + ((int)$t->format('m') - (int)$h->format('m')));
    }

    private function observation_weight(string $historicalMonth, string $targetMonth): float {
        $weight = $this->recency_weight($this->months_ago($historicalMonth, $targetMonth));
        if (substr($historicalMonth, 5, 2) === substr($targetMonth, 5, 2)) $weight *= self::SAME_MONTH_MULTIPLIER;
        return $weight;
    }

    private function weekday_baseline(array $history, string $series, int $weekday, string $targetMonth): ?float {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($history as $historicalMonth => $row) {
            $values = is_array($row[$series] ?? null) ? $row[$series] : [];
            $start = DateTimeImmutable::createFromFormat('!Y-m', $historicalMonth, new DateTimeZone('UTC'));
            if (! $start) continue;
            $weight = $this->observation_weight($historicalMonth, $targetMonth);
            foreach ($values as $index => $value) {
                $day = $index + 1;
                if ($day > (int)$start->format('t')) break;
                $date = $start->setDate((int)$start->format('Y'), (int)$start->format('m'), $day);
                if ((int)$date->format('N') !== $weekday) continue;
                $sum += max(0.0, (float)$value) * $weight;
                $weights += $weight;
            }
        }
        return $weights > 0.0 ? $sum / $weights : null;
    }

    private function weighted_daily_average(array $history, string $series, string $targetMonth): ?float {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($history as $historicalMonth => $row) {
            $values = is_array($row[$series] ?? null) ? $row[$series] : [];
            if (! $values) continue;
            $weight = $this->observation_weight($historicalMonth, $targetMonth);
            foreach ($values as $value) {
                $sum += max(0.0, (float)$value) * $weight;
                $weights += $weight;
            }
        }
        return $weights > 0.0 ? $sum / $weights : null;
    }

    private function weighted_marketing(array $history, string $targetMonth): float {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($history as $historicalMonth => $row) {
            $value = max(0.0, (float)($row['marketing'] ?? 0.0));
            if ($value <= 0.0) continue;
            $weight = $this->observation_weight($historicalMonth, $targetMonth);
            $sum += $value * $weight;
            $weights += $weight;
        }
        return $weights > 0.0 ? $sum / $weights : 0.0;
    }

    private function bounded_pace(float $actual, float $expected): float {
        if ($expected <= 0.0) return 1.0;
        return max(self::PACE_MIN, min(self::PACE_MAX, $actual / $expected));
    }

    private function current_weight(int $elapsedDays): float {
        return max(0.0, min(1.0, $elapsedDays / self::FULL_PACE_WEIGHT_DAY));
    }

    private function range_from_history(float $projected, float $actual, float $remainingBaseline, array $history, string $series, string $targetMonth, int $elapsedDays, int $daysInMonth): array {
        $mean = $this->weighted_daily_average($history, $series, $targetMonth);
        $weightedSq = 0.0;
        $weights = 0.0;
        if ($mean !== null && $mean > 0.0) {
            foreach ($history as $historicalMonth => $row) {
                $values = is_array($row[$series] ?? null) ? $row[$series] : [];
                $weight = $this->observation_weight($historicalMonth, $targetMonth);
                foreach ($values as $value) {
                    $delta = max(0.0, (float)$value) - $mean;
                    $weightedSq += ($delta * $delta) * $weight;
                    $weights += $weight;
                }
            }
        }
        $std = $weights > 0.0 ? sqrt($weightedSq / $weights) : 0.0;
        $cv = $mean !== null && $mean > 0.0 ? $std / $mean : 0.20;
        $cv = max(self::RANGE_FLOOR_RATIO, min(self::RANGE_MAX_RATIO, $cv));
        $unknownFraction = max(0.0, min(1.0, ($daysInMonth - $elapsedDays) / max(1, $daysInMonth)));
        $remainingProjected = max(0.0, $projected - $actual);
        $uncertainty = max($projected * self::RANGE_FLOOR_RATIO * $unknownFraction, $remainingProjected * $cv * sqrt($unknownFraction));
        return [max(0.0, $projected - $uncertainty), max($projected, $projected + $uncertainty)];
    }
}
