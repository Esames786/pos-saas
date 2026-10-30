<?php

namespace App\Support;

/**
 * REPORT-NETWORK-BRIDGE-1 — the lines that walk a line-based section's total (Categories, Items,
 * Items by Category) up to NET SALES, for the thermal NETWORK print.
 *
 * The browser print (resources/views/tenant/reports/center/print.blade.php, `$bridgeRows`) has
 * closed these sections with "Plus Delivery Charge ... = NET SALES" since REPORT-CHARGE-BRIDGE-2,
 * BRIDGE-DEALS-1 and CHARGE-BREAKUP-1. EscPosPayloadService was written before them and never got
 * the lines, so a report sent to a network printer stopped at GRAND TOTAL (Khatri delivery counter,
 * 27 Oct: GRAND TOTAL 52,020, no Delivery 7,200, no NET SALES 59,220).
 *
 * The rules below are the blade's, line for line; ReportNetworkBridgeMySqlTest holds the two prints
 * to the same labels and figures, so a change to one that misses the other turns red.
 */
final class SalesBridge
{
    /**
     * @param  array  $bridge         the controller's `bridge`: the engine overview + `deals_net`
     * @param  float  $lineNet        the section's own net total (sum of net_value)
     * @param  bool   $excludesDeals  true for Items / Items by Category, which leave the deals out
     * @return list<array{label: string, amount: float, total: bool}>  empty when nothing needs bridging
     */
    public static function lines(array $bridge, float $lineNet, bool $excludesDeals = false): array
    {
        $netSales = (float) ($bridge['net_sales'] ?? 0);
        $deals = $excludesDeals ? (float) ($bridge['deals_net'] ?? 0) : 0.0;
        $netCharges = round($netSales - $lineNet - $deals, 2);
        if (abs($netCharges) <= 0.01 && abs($deals) <= 0.01) {
            return [];
        }

        // Delivery is NET (charged − refunded); the discount is the BILL discount, line discounts
        // already sit inside line_total.
        $parts = [
            'Plus Delivery Charge' => (float) ($bridge['delivery_charge'] ?? 0) - (float) ($bridge['delivery_refunded'] ?? 0),
            'Plus Service Charge'  => (float) ($bridge['service_charge'] ?? 0),
            'Plus Tax'             => (float) ($bridge['tax'] ?? 0),
            'Plus Tips'            => (float) ($bridge['tips'] ?? 0),
            'Less Discount'        => -(float) ($bridge['discount'] ?? 0),
        ];

        $lines = [];
        if (abs($deals) > 0.01) {
            $lines[] = ['label' => 'Plus Deals', 'amount' => $deals, 'total' => false];
        }

        // Named lines first, zeros left out; whatever has no name lands in "Other", so the section
        // always closes on NET SALES.
        $named = 0.0;
        foreach ($parts as $name => $amount) {
            if (abs($amount) > 0.01) {
                $lines[] = ['label' => $name, 'amount' => $amount, 'total' => false];
                $named += $amount;
            }
        }

        $other = round($netCharges - $named, 2);
        if (abs($other) > 0.01) {
            $lines[] = ['label' => 'Plus Other Charges', 'amount' => $other, 'total' => false];
        }

        $lines[] = ['label' => '= NET SALES', 'amount' => $netSales, 'total' => true];

        return $lines;
    }
}
