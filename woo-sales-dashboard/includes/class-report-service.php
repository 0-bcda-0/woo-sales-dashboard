<?php

defined('ABSPATH') || exit;

final class WSD_Report_Service {
    public function build_payload(string $month, array $monthResponse): array {
        return [
            'month' => $month,
            'monthLabel' => $this->month_label($month),
            'generatedAt' => (string)($monthResponse['generatedAt'] ?? $monthResponse['updatedAt'] ?? ''),
            'currency' => $monthResponse['currency'] ?? ['code'=>'EUR','symbol'=>'€'],
            'totals' => $monthResponse['totals'] ?? [],
            'commission' => $monthResponse['commission'] ?? [],
            'daily' => $monthResponse['daily'] ?? [],
            'previousDaily' => $monthResponse['previousDaily'] ?? [],
            'specialSales' => $monthResponse['specialSales'] ?? ['vip'=>[],'bundle'=>[]],
            'reportAudit' => $monthResponse['reportAudit'] ?? ['last_sent_at'=>'','last_sent_to'=>''],
        ];
    }

    public function render_html(array $payload, string $mode = 'preview'): string {
        $email = $mode === 'email';
        $c = $payload['commission'];
        $t = $payload['totals'];
        $symbol = (string)($payload['currency']['symbol'] ?? '€');
        $title = 'Monthly Commission Report — ' . $payload['monthLabel'];
        $bodyBg = $email ? '#f5f6f8' : '#ffffff';
        $wrapStyle = 'width:100%;max-width:720px;margin:0 auto;border-collapse:collapse;background:#ffffff;color:#15171a;font-family:Arial,Helvetica,sans-serif;';
        $card = 'padding:18px;border:1px solid #e8e9ed;border-radius:14px;background:#ffffff;';
        $muted = 'color:#697077;font-size:12px;';
        $html = '<div style="background:' . $bodyBg . ';padding:20px 8px;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="' . $wrapStyle . '"><tr><td style="padding:0 0 18px 0;">';
        $html .= '<div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6750a4;">Woo Sales Dashboard</div>';
        $html .= '<h1 style="margin:5px 0 3px;font-size:26px;line-height:1.2;">' . esc_html($title) . '</h1>';
        $html .= '<div style="' . $muted . '">Generated ' . esc_html((string)$payload['generatedAt']) . '</div></td></tr>';

        $html .= '<tr><td style="' . $card . 'background:#f7f3ff;">';
        $html .= '<div style="' . $muted . 'font-weight:700;">COMMISSION TO PAY</div>';
        $html .= '<div style="font-size:38px;font-weight:800;letter-spacing:-1px;margin-top:7px;">' . $this->money((float)($c['commissionToPay'] ?? 0), $symbol) . '</div>';
        $html .= '<div style="' . $muted . 'margin-top:4px;">Amount payable for this month</div></td></tr>';

        $html .= '<tr><td style="padding-top:16px;"><h2 style="font-size:18px;margin:0 0 8px;">Sales & commission breakdown</h2>';
        $rows = [
            ['Product Sales',$c['productSales'] ?? 0],
            ['Standard Sales',$c['standardSales'] ?? 0],
            ['VIP Sales',$c['vipSales'] ?? 0],
            ['Bundle Sales',$c['bundleSales'] ?? 0],
            ['Standard VPC',$c['standardVpc'] ?? 0],
            ['Standard Commission',$c['standardCommission'] ?? 0],
            ['VIP Commission',$c['vipCommission'] ?? 0],
            ['Bundle Commission',$c['bundleCommission'] ?? 0],
        ];
        $html .= $this->metric_table($rows, $symbol);
        $html .= '</td></tr>';

        $html .= '<tr><td style="padding-top:16px;"><h2 style="font-size:18px;margin:0 0 8px;">Performance</h2>';
        $html .= '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;border:1px solid #e8e9ed;">';
        $html .= '<tr><td style="border-bottom:1px solid #e8e9ed;"><strong>Orders</strong><br><span style="' . $muted . '">' . esc_html((string)($t['orders'] ?? 0)) . '</span></td>';
        $html .= '<td style="border-bottom:1px solid #e8e9ed;"><strong>Items Sold</strong><br><span style="' . $muted . '">' . esc_html((string)($t['items'] ?? 0)) . '</span></td>';
        $html .= '<td style="border-bottom:1px solid #e8e9ed;"><strong>AOV</strong><br><span style="' . $muted . '">' . $this->money((float)($t['aov'] ?? 0), $symbol) . '</span></td></tr>';
        $html .= '<tr><td colspan="3"><strong>Shipping (informational)</strong><br><span style="' . $muted . '">' . $this->money((float)($t['shipping'] ?? 0), $symbol) . ' — no commission</span></td></tr></table></td></tr>';

        $html .= '<tr><td style="padding-top:16px;"><h2 style="font-size:18px;margin:0 0 8px;">Sales structure</h2>' . $this->composition_bars($c, $symbol) . '</td></tr>';
        $html .= '<tr><td style="padding-top:16px;">' . $this->daily_comparison('Daily Sales', $payload['daily'] ?? [], $payload['previousDaily'] ?? [], 'sales', $symbol) . '</td></tr>';
        $html .= '<tr><td style="padding-top:16px;">' . $this->daily_comparison('Daily Commission to Pay', $payload['daily'] ?? [], $payload['previousDaily'] ?? [], 'commissionToPay', $symbol) . '</td></tr>';
        $html .= '<tr><td style="padding-top:16px;">' . $this->special_table('VIP Sales', $payload['specialSales']['vip'] ?? [], 'vip', $symbol) . '</td></tr>';
        $html .= '<tr><td style="padding-top:16px;">' . $this->special_table('Bundle Sales', $payload['specialSales']['bundle'] ?? [], 'bundle', $symbol) . '</td></tr>';

        $html .= '<tr><td style="padding-top:16px;"><h2 style="font-size:18px;margin:0 0 8px;">Personal costs & net earnings</h2>';
        $html .= $this->metric_table([
            ['Marketing',$c['marketing'] ?? 0],
            ['Other Costs',$c['otherCosts'] ?? 0],
            ['Net Earnings',$c['netEarnings'] ?? 0],
        ], $symbol);
        $html .= '<div style="' . $muted . 'margin-top:8px;">Marketing and Other Costs do not reduce Commission to Pay.</div></td></tr>';
        $html .= '</table></div>';
        return $html;
    }

    private function daily_comparison(string $title, array $current, array $previous, string $key, string $symbol): string {
        $html = '<h2 style="font-size:18px;margin:0 0 8px;">' . esc_html($title) . '</h2>';
        if (!$current) return $html . '<div style="color:#697077;font-size:12px;">No daily data.</div>';
        $values = [];
        foreach ($current as $row) $values[] = max(0.0, (float)($row[$key] ?? 0));
        foreach ($previous as $row) $values[] = max(0.0, (float)($row[$key] ?? 0));
        $max = max(1.0, ...$values);
        $html .= '<table role="presentation" width="100%" cellpadding="5" cellspacing="0" style="border-collapse:collapse;border:1px solid #e8e9ed;font-size:11px;">';
        $html .= '<tr><th align="left">Day</th><th align="left">Current / Previous</th><th align="right">Values</th></tr>';
        foreach ($current as $i => $row) {
            $cv = max(0.0, (float)($row[$key] ?? 0));
            $pv = isset($previous[$i]) ? max(0.0, (float)($previous[$i][$key] ?? 0)) : 0.0;
            $cw = max(0.0, min(100.0, ($cv / $max) * 100));
            $pw = max(0.0, min(100.0, ($pv / $max) * 100));
            $day = isset($row['date']) ? substr((string)$row['date'], 8, 2) : (string)($i + 1);
            $html .= '<tr><td style="border-top:1px solid #eef0f2;">' . esc_html($day) . '</td><td style="border-top:1px solid #eef0f2;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f0f1f3;"><tr><td width="' . number_format($cw,1,'.','') . '%" style="height:6px;background:#6750a4;"></td><td></td></tr></table>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f0f1f3;margin-top:3px;"><tr><td width="' . number_format($pw,1,'.','') . '%" style="height:4px;background:#a8adb4;"></td><td></td></tr></table></td>';
            $html .= '<td align="right" style="border-top:1px solid #eef0f2;white-space:nowrap;">' . $this->money($cv,$symbol) . ' / ' . $this->money($pv,$symbol) . '</td></tr>';
        }
        return $html . '</table><div style="color:#697077;font-size:10px;margin-top:4px;">Current month / previous month, aligned by day.</div>';
    }

    private function metric_table(array $rows, string $symbol): string {
        $html = '<table role="presentation" width="100%" cellpadding="9" cellspacing="0" style="border-collapse:collapse;border:1px solid #e8e9ed;">';
        foreach ($rows as $index => [$label,$value]) {
            $border = $index ? 'border-top:1px solid #e8e9ed;' : '';
            $html .= '<tr><td style="' . $border . 'color:#444;">' . esc_html((string)$label) . '</td><td align="right" style="' . $border . 'font-weight:700;">' . $this->money((float)$value,$symbol) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function composition_bars(array $c, string $symbol): string {
        $total = max(0.0, (float)($c['productSales'] ?? 0));
        $html = '<table role="presentation" width="100%" cellpadding="5" cellspacing="0" style="border-collapse:collapse;">';
        foreach ([['Standard','standardSales'],['VIP','vipSales'],['Bundle','bundleSales']] as [$label,$key]) {
            $value = max(0.0,(float)($c[$key] ?? 0));
            $pct = $total > 0 ? min(100, max(0, ($value/$total)*100)) : 0;
            $html .= '<tr><td width="80" style="font-size:12px;font-weight:700;">' . esc_html($label) . '</td><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f0f1f3;"><tr><td width="' . number_format($pct,1,'.','') . '%" style="height:9px;background:#6750a4;"></td><td></td></tr></table></td><td align="right" width="100" style="font-size:12px;">' . $this->money($value,$symbol) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function special_table(string $title, array $rows, string $type, string $symbol): string {
        $html = '<h2 style="font-size:18px;margin:0 0 8px;">' . esc_html($title) . '</h2>';
        if (!$rows) return $html . '<div style="color:#697077;font-size:12px;">No special-rate sales.</div>';
        $html .= '<table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse;border:1px solid #e8e9ed;font-size:12px;">';
        if ($type === 'vip') {
            $html .= '<tr><th align="left">Date</th><th align="left">Order</th><th align="left">Customer</th><th align="right">Revenue</th></tr>';
            foreach ($rows as $r) $html .= '<tr><td>' . esc_html($r['date'] ?? '') . '</td><td>#' . esc_html($r['orderNumber'] ?? '') . '</td><td>' . esc_html($r['customer'] ?? '') . '</td><td align="right">' . $this->money((float)($r['revenue'] ?? 0),$symbol) . '</td></tr>';
        } else {
            $html .= '<tr><th align="left">Date</th><th align="left">Order</th><th align="left">Product / SKU</th><th align="right">Revenue</th></tr>';
            foreach ($rows as $r) $html .= '<tr><td>' . esc_html($r['date'] ?? '') . '</td><td>#' . esc_html($r['orderNumber'] ?? '') . '</td><td>' . esc_html($r['product'] ?? '') . '<br><span style="color:#697077;">' . esc_html($r['sku'] ?? '') . '</span></td><td align="right">' . $this->money((float)($r['revenue'] ?? 0),$symbol) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function money(float $value, string $symbol): string {
        return esc_html(number_format($value, 2, '.', ',') . ' ' . $symbol);
    }

    private function month_label(string $month): string {
        $d = DateTimeImmutable::createFromFormat('!Y-m', $month);
        return $d ? $d->format('F Y') : $month;
    }
}
