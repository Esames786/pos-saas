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
        $this->assertStringContainsString('class="amt">30</td>', $html, 'thermal: the net charge amount prints without a .00 tail');
        $this->assertStringContainsString('class="amt">330</td>', $html, 'thermal: net sales = merchandise 300 + charge 30');
    }

    public function test_a4_by_order_type_shows_the_charge_bridge_to_net_sales(): void
    {
        $html = $this->render('a4');

        $this->assertStringContainsString('Plus Delivery', $html, 'a4: the delivery-charge bridge line prints');
        $this->assertStringContainsString('= NET SALES', $html, 'a4: the bridge closes on NET SALES');
        $this->assertStringContainsString('30.00', $html, 'a4: the net charge amount prints');
        $this->assertStringContainsString('330.00', $html, 'a4: net sales = merchandise 300 + charge 30');
    }

    /**
     * The GLOBAL categories/items totals must also reach NET SALES. The old bridge added only the
     * delivery charge and printed only if it closed exactly — so a discount (or tax) broke the
     * arithmetic and the bridge silently vanished, leaving the merchandise total looking like it
     * contradicted the cash. The gap is now derived as (net sales − line net), so it always closes.
     */
    public function test_global_categories_bridge_closes_even_with_a_discount(): void
    {
        $categories = [[
            'id' => 1, 'name' => 'Biryani', 'sold_qty' => 3, 'returned_qty' => 0, 'net_qty' => 3,
            'net' => 300.0, 'returns_amount' => 0.0, 'net_value' => 300.0, 'children' => [],
        ]];

        // net sales 330, line net 300 — but delivery charge 36 with a 6 discount means the old
        // "line net + delivery" (336) never equalled 330, so the bridge used to disappear.
        $html = view('tenant.reports.center.print', [
            'mode' => 'thermal', 'paper' => '80mm',
            'filters' => ['date_from' => '2026-08-20', 'date_to' => '2026-08-20'],
            'sections' => ['categories'],
            'bridge' => ['net_sales' => 330.0, 'delivery_charge' => 36.0, 'delivery_refunded' => 0.0,
                         'discount' => 6.0, 'tax' => 0.0, 'service_charge' => 0.0, 'tips' => 0.0],
            'overview' => null, 'orderTypes' => null, 'categories' => $categories, 'items' => null,
            'waiters' => null, 'combos' => null, 'cancellations' => null, 'cashBank' => null,
        ])->render();

        $this->assertStringContainsString('Plus Delivery', $html, 'global categories bridge prints despite the discount');
        $this->assertStringContainsString('= NET SALES', $html);
        $this->assertStringContainsString('class="amt">330</td>', $html, 'the global total reaches NET SALES');

        // CHARGE-BREAKUP-1: pehle yahan ek hi line "30" thi (330 − 300). Ab wo 30 apne do naamon
        // me batti hai — delivery 36 aur discount −6 — aur unka jama wahi 30 hai.
        $this->assertStringContainsString('class="amt">36</td>', $html, 'the delivery charge is named');
        $this->assertStringContainsString('Less Discount', $html, 'the order discount is named, not left as an anonymous minus');
        $this->assertStringContainsString('class="amt">-6</td>', $html, 'the discount prints as a deduction');
        $this->assertStringNotContainsString('Plus Other Charges', $html,
            'everything is named, so nothing may fall through to Other');
    }

    /* ── CHARGE-BREAKUP-1 ─────────────────────────────────────────────────── */

    /**
     * The bridge used to print one residual called "Plus Delivery & Other Charges". Four different
     * things wore that one name and a reader could not tell whose money the figure was. Each is
     * named now.
     */
    private function bridge(array $over): string
    {
        $categories = [[
            'id' => 1, 'name' => 'Biryani', 'sold_qty' => 3, 'returned_qty' => 0, 'net_qty' => 3,
            'net' => 300.0, 'returns_amount' => 0.0, 'net_value' => 300.0, 'children' => [],
        ]];

        return view('tenant.reports.center.print', [
            'mode' => 'a4',
            'filters' => ['date_from' => '2026-08-20', 'date_to' => '2026-08-20'],
            'sections' => ['categories'],
            'bridge' => $over + ['delivery_charge' => 0.0, 'delivery_refunded' => 0.0,
                                 'service_charge' => 0.0, 'tax' => 0.0, 'tips' => 0.0, 'discount' => 0.0],
            'overview' => null, 'orderTypes' => null, 'categories' => $categories, 'items' => null,
            'waiters' => null, 'combos' => null, 'cancellations' => null, 'cashBank' => null,
        ])->render();
    }

    /** Every charge on its own line, and the four of them still reaching NET SALES. */
    public function test_each_charge_is_named_on_its_own_line(): void
    {
        // 300 merchandise + 50 delivery + 20 service + 15 tax + 10 tips − 5 discount = 390.
        $html = $this->bridge([
            'net_sales' => 390.0, 'delivery_charge' => 50.0, 'service_charge' => 20.0,
            'tax' => 15.0, 'tips' => 10.0, 'discount' => 5.0,
        ]);

        foreach ([
            'Plus Delivery Charge' => 'class="amt">50.00</td>',
            'Plus Service Charge'  => 'class="amt">20.00</td>',
            'Plus Tax'             => 'class="amt">15.00</td>',
            'Plus Tips'            => 'class="amt">10.00</td>',
            'Less Discount'        => 'class="amt">-5.00</td>',
        ] as $label => $amount) {
            $this->assertStringContainsString($label, $html, "{$label} must be named");
            $this->assertStringContainsString($amount, $html, "{$label} must carry its own figure");
        }

        $this->assertStringNotContainsString('Delivery &amp; Other Charges', $html,
            'the old mixed label must be gone');
        $this->assertStringNotContainsString('Plus Other Charges', $html,
            'the named lines account for the whole gap, so Other must not print');
        $this->assertStringContainsString('class="amt">390.00</td>', $html, 'the bridge still reaches NET SALES');
    }

    /** A charge worth nothing is not worth a line. */
    public function test_a_zero_charge_prints_no_line(): void
    {
        $html = $this->bridge(['net_sales' => 350.0, 'delivery_charge' => 50.0]);

        $this->assertStringContainsString('Plus Delivery Charge', $html);
        foreach (['Plus Service Charge', 'Plus Tax', 'Plus Tips', 'Less Discount'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "{$absent} is zero and must not print");
        }
    }

    /**
     * THE test. Naming the parts must never cost the guarantee the residual was built for: if
     * something arrives that has no name, it has to SHOW, not vanish. Here 40 of the 90 gap is
     * unaccounted — the four named charges cover 50 — and that 40 must appear as Other.
     */
    public function test_an_unnamed_charge_surfaces_instead_of_vanishing(): void
    {
        $html = $this->bridge(['net_sales' => 390.0, 'delivery_charge' => 50.0]);

        $this->assertStringContainsString('Plus Other Charges', $html,
            'a gap no named line explains must still be shown');
        $this->assertStringContainsString('class="amt">40.00</td>', $html,
            'and it must be shown at its real size');
        $this->assertStringContainsString('class="amt">390.00</td>', $html,
            'the bridge closes on NET SALES either way');
    }
}
