<?php

namespace Tests\Unit\Printing;

use App\Services\Printing\EscPosPayloadService;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-NETWORK-BRIDGE-1 — the network report closes Categories / Items / Items by Category on
 * NET SALES, and nothing else on the paper moves.
 *
 * Khatri's delivery counter, 27 Oct: the report sent to the printer stopped at GRAND TOTAL 52,020;
 * the browser print of the same day carried "Plus Delivery Charge 7,200" and "= NET SALES 59,220".
 * The owner's worry is the rest of the slip, so the first test proves the change only INSERTS lines.
 */
class EscPosReportBridgeTest extends TestCase
{
    private const BRIDGE_LINE = '/^(Plus Deals|Plus Delivery Charge|Plus Service Charge|Plus Tax|Plus Tips|Less Discount|Plus Other Charges|= NET SALES) +-?[\d,]+$/';

    private function report(array $sections, ?array $bridge): array
    {
        $r = [
            'meta' => ['business_name' => 'Khatri Biryani', 'label' => 'Standard', 'date_from' => '2026-10-27', 'date_to' => '2026-10-27', 'generated' => '27-Oct-2026 23:50', 'paper' => '80mm'],
            'sections' => $sections,
            'categories' => [
                ['id' => 1, 'name' => 'Chicken Biryani', 'sold_qty' => 4, 'returned_qty' => 0, 'net_qty' => 4, 'net' => 1960, 'returns_amount' => 0, 'net_value' => 1960,
                    'children' => [['id' => 2, 'name' => 'Biryani Chicken', 'sold_qty' => 4, 'returned_qty' => 0, 'net_qty' => 4, 'net' => 1960, 'returns_amount' => 0, 'net_value' => 1960]]],
                ['id' => 3, 'name' => 'Matka', 'sold_qty' => 7, 'returned_qty' => 0, 'net_qty' => 7, 'net' => 31000, 'returns_amount' => 0, 'net_value' => 31000, 'children' => []],
                ['id' => 4, 'name' => 'Beef Changezi Pulao', 'sold_qty' => 37, 'returned_qty' => 0, 'net_qty' => 37, 'net' => 19060, 'returns_amount' => 0, 'net_value' => 19060, 'children' => []],
            ],
            'items' => [
                (object) ['item' => 'Biryani Chicken', 'variant' => null, 'sold_qty' => 4, 'returned_qty' => 0, 'net_qty' => 4, 'net' => 1960, 'returns_amount' => 0, 'net_value' => 1960],
                (object) ['item' => 'Matka', 'variant' => null, 'sold_qty' => 7, 'returned_qty' => 0, 'net_qty' => 7, 'net' => 31000, 'returns_amount' => 0, 'net_value' => 31000],
            ],
        ];
        if ($bridge !== null) {
            $r['bridge'] = $bridge;
        }

        return $r;
    }

    /** The day in the photo: lines 52,020, delivery 7,200, NET SALES 59,220. */
    private function khatriBridge(): array
    {
        return ['net_sales' => 59220, 'delivery_charge' => 7200, 'delivery_refunded' => 0, 'service_charge' => 0,
            'tax' => 0, 'tips' => 0, 'discount' => 0, 'deals_net' => 0];
    }

    private function text(string $raw): string
    {
        return preg_replace(['/\x1D\x21./s', '/\x1B\x45./s', '/\x1B\x2D./s', '/[\x00-\x08\x0B-\x1F]/'], ['', '', '', ''], $raw);
    }

    public function test_the_category_report_now_closes_on_delivery_and_net_sales(): void
    {
        $raw = app(EscPosPayloadService::class)->buildReport($this->report(['categories'], $this->khatriBridge()));
        $body = $this->text($raw);

        $grand = strpos($body, 'GRAND TOTAL');
        $delivery = strpos($body, 'Plus Delivery Charge');
        $net = strpos($body, '= NET SALES');
        $this->assertNotFalse($grand);
        $this->assertNotFalse($delivery, 'the delivery charge reaches the paper');
        $this->assertNotFalse($net, 'NET SALES reaches the paper');
        $this->assertTrue($grand < $delivery && $delivery < $net, 'GRAND TOTAL, then delivery, then NET SALES');
        $this->assertMatchesRegularExpression('/Plus Delivery Charge +7,200\n/', $body);
        $this->assertMatchesRegularExpression('/= NET SALES +59,220\n/', $body);
        $this->assertStringEndsWith("\x1D\x56\x42\x00", $raw, 'still ends with the paper cut');
    }

    /**
     * The owner's question: "pora print to kharab nahi hoga?" Take the new slip, drop the bridge
     * lines, and what is left must be the old slip byte for byte — same order, nothing else added,
     * nothing removed, nothing reworded.
     */
    public function test_nothing_but_the_bridge_lines_is_added_anywhere_on_the_slip(): void
    {
        $svc = app(EscPosPayloadService::class);
        foreach ([['categories'], ['items'], ['categories', 'items']] as $sections) {
            $old = explode("\n", $svc->buildReport($this->report($sections, null)));
            $new = explode("\n", $svc->buildReport($this->report($sections, $this->khatriBridge())));

            $i = 0;
            $added = [];
            foreach ($new as $line) {
                if ($i < count($old) && $line === $old[$i]) {
                    $i++;
                    continue;
                }
                $added[] = $this->text($line);
            }

            $this->assertSame(count($old), $i, implode('+', $sections) . ': every old line is still there, in order');
            $this->assertNotEmpty($added, implode('+', $sections) . ': the bridge was added');
            foreach ($added as $line) {
                $this->assertMatchesRegularExpression(self::BRIDGE_LINE, $line, "only bridge lines are new, got [{$line}]");
            }
        }
    }

    /** No bridge figures sent (an older caller) → the slip is exactly what it always was. */
    public function test_without_bridge_figures_nothing_is_printed(): void
    {
        $svc = app(EscPosPayloadService::class);
        $raw = $svc->buildReport($this->report(['categories', 'items'], null));

        $this->assertStringNotContainsString('= NET SALES', $this->text($raw));
    }

    /** Lines already equal NET SALES (no charges, no deals) → no bridge, byte-identical slip. */
    public function test_a_day_with_no_charges_prints_no_bridge(): void
    {
        $svc = app(EscPosPayloadService::class);
        $even = ['net_sales' => 52020, 'delivery_charge' => 0, 'deals_net' => 0];

        $this->assertSame(
            $svc->buildReport($this->report(['categories'], null)),
            $svc->buildReport($this->report(['categories'], $even))
        );
    }

    /**
     * Items leave the deals out, so the deals get their own line before the charges (BRIDGE-DEALS-1).
     * Items net 32,960 + deals 5,000 + delivery 7,200 = NET SALES 45,160.
     */
    public function test_items_name_the_deals_before_the_charges(): void
    {
        $bridge = ['net_sales' => 45160, 'delivery_charge' => 7200, 'deals_net' => 5000];
        $body = $this->text(app(EscPosPayloadService::class)->buildReport($this->report(['items'], $bridge)));

        $this->assertMatchesRegularExpression('/Plus Deals +5,000\n/', $body);
        $this->assertMatchesRegularExpression('/Plus Delivery Charge +7,200\n/', $body);
        $this->assertMatchesRegularExpression('/= NET SALES +45,160\n/', $body);
        $this->assertLessThan(strpos($body, 'Plus Delivery Charge'), strpos($body, 'Plus Deals'), 'deals first, then charges');
    }

    /**
     * REPORT-NETWORK-ITEMS-DEALS-1 — the Report Center sends Items WITH the deal lines in them. The
     * total already holds the deals, so naming them again printed "Plus Deals 119,425" and then
     * "Plus Other Charges -119,425" to cancel it (a live day, 28 Oct). Items 6,960 (deal 5,000 in
     * it) + delivery 7,200 − discount 200 = NET SALES 13,960, with no deal or "other" line.
     */
    public function test_items_that_already_hold_the_deals_do_not_add_them_again(): void
    {
        $r = $this->report(['items'], ['net_sales' => 13960, 'delivery_charge' => 7200, 'discount' => 200, 'deals_net' => 5000]);
        $r['items'] = [
            (object) ['item' => 'Biryani Chicken', 'variant' => null, 'combo_id' => null, 'sold_qty' => 4, 'returned_qty' => 0, 'net_qty' => 4, 'net' => 1960, 'returns_amount' => 0, 'net_value' => 1960],
            (object) ['item' => 'Family Deal', 'variant' => null, 'combo_id' => 7, 'sold_qty' => 2, 'returned_qty' => 0, 'net_qty' => 2, 'net' => 5000, 'returns_amount' => 0, 'net_value' => 5000],
        ];
        $body = $this->text(app(EscPosPayloadService::class)->buildReport($r));
        $tail = substr($body, strrpos($body, 'TOTAL'));
        preg_match_all('/^((?:Plus|Less) [A-Za-z ]+?|= NET SALES) +(-?[\d,]+)$/m', $tail, $m);

        $this->assertSame(['Plus Delivery Charge', 'Less Discount', '= NET SALES'], $m[1]);
        $this->assertSame(['7,200', '-200', '13,960'], $m[2]);
    }
}
