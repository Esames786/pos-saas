<?php

namespace Tests\MySql;

/**
 * REPORT-CHARGE-BRIDGE — the BY ORDER TYPE section prints MERCHANDISE nets, but a delivery type's
 * cash / NET SALES also carries its delivery charge. Without a bridge the delivery total (e.g.
 * 35,196) looks like it contradicts the cash (41,310). Each per-type sub-table now closes with
 * "Plus Delivery & Other Charges (net) → = NET SALES", in BOTH the A4 and the thermal layout.
 *
 * This also renders print.blade.php end-to-end so a Blade/PHP error in the new helper is caught.
 */
class ReportCenterChargeBridgeMySqlTest extends MySqlTenantTestCase
{
    private function render(string $mode): string
    {
        $combos = [
            'categories' => ['Delivery' => [[
                'label' => 'Biryani', 'orders' => 20, 'sold_qty' => 40, 'returned_qty' => 2,
                'net_qty' => 38, 'net' => 360.0, 'returns_amount' => 60.0, 'net_value' => 300.0,
            ]]],
            'items' => ['Delivery' => [[
                'label' => 'Chicken Biryani', 'sold_qty' => 40, 'returned_qty' => 2,
                'net_qty' => 38, 'net' => 360.0, 'returns_amount' => 60.0, 'net_value' => 300.0,
            ]]],
            'waiters' => ['Delivery' => []],
            'totals' => ['Delivery' => ['merch_net' => 300.0, 'net_sales' => 330.0, 'net_charges' => 30.0]],
        ];

        return view('tenant.reports.center.print', [
            'mode' => $mode, 'paper' => '80mm',
            'filters' => ['date_from' => '2026-08-20', 'date_to' => '2026-08-20'],
            'sections' => ['order_type_combos'],
            'bridge' => ['delivery_charge' => 0, 'delivery_refunded' => 0, 'net_sales' => 0],
            'overview' => null, 'orderTypes' => null, 'categories' => null, 'items' => null,
            'waiters' => null, 'combos' => $combos, 'cancellations' => null, 'cashBank' => null,
        ])->render();
    }

    public function test_thermal_by_order_type_shows_the_charge_bridge_to_net_sales(): void
    {
        $html = $this->render('thermal');

        $this->assertStringContainsString('Plus Delivery', $html, 'thermal: the delivery-charge bridge line prints');
        $this->assertStringContainsString('= NET SALES', $html, 'thermal: the bridge closes on NET SALES');
        $this->assertStringContainsString('30.00', $html, 'thermal: the net charge amount prints');
        $this->assertStringContainsString('330.00', $html, 'thermal: net sales = merchandise 300 + charge 30');
    }

    public function test_a4_by_order_type_shows_the_charge_bridge_to_net_sales(): void
    {
        $html = $this->render('a4');

        $this->assertStringContainsString('Plus Delivery', $html, 'a4: the delivery-charge bridge line prints');
        $this->assertStringContainsString('= NET SALES', $html, 'a4: the bridge closes on NET SALES');
        $this->assertStringContainsString('30.00', $html, 'a4: the net charge amount prints');
        $this->assertStringContainsString('330.00', $html, 'a4: net sales = merchandise 300 + charge 30');
    }
}
