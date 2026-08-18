<?php

namespace Tests\Unit\Printing;

use App\Services\Printing\EscPosPayloadService;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-SEND-TO-NETWORK-1 — the Sales Report renders to ESC/POS bytes: header, OVERALL + CASH,
 * the three-figure (Sold/Ret/Net) tables, and a paper cut. Figures keep two decimals (a financial
 * summary), and every requested section appears while unticked ones stay off.
 */
class EscPosReportPayloadTest extends TestCase
{
    private function sample(array $sections): array
    {
        return [
            'meta' => ['business_name' => 'Khatri Biryani', 'label' => 'Z', 'date_from' => '2026-08-18', 'date_to' => '2026-08-18', 'generated' => '18-Aug-2026 14:00', 'paper' => '80mm'],
            'sections' => $sections,
            'overview' => [
                'orders' => 5, 'sold_qty' => 10, 'returned_qty' => 1, 'net_qty' => 9, 'gross_sales' => 5000,
                'discount' => 0, 'tax' => 0, 'service_charge' => 0, 'delivery_charge' => 150, 'tips' => 0,
                'grand_total' => 5150, 'returns_amount' => 200, 'net_sales' => 4950,
                'cash_collected' => 5150, 'cash_refunds' => 200, 'net_cash_from_sales' => 4950,
                'payments' => ['cash' => 5150],
            ],
            'orderTypes' => [['label' => 'Delivery', 'orders' => 5, 'grand_total' => 5150, 'returns_amount' => 200, 'net_sales' => 4950]],
            'categories' => [['id' => 1, 'name' => 'Biryani', 'sold_qty' => 8, 'returned_qty' => 1, 'net_qty' => 7, 'net' => 4000, 'returns_amount' => 200, 'net_value' => 3800, 'children' => []]],
            'items' => [(object) ['item' => 'Beef Biryani', 'variant' => null, 'sold_qty' => 8, 'returned_qty' => 1, 'net_qty' => 7, 'net' => 4000, 'returns_amount' => 200, 'net_value' => 3800]],
        ];
    }

    private function text(string $raw): string
    {
        // strip ESC/POS control bytes so the assertions read the printed characters
        return preg_replace(['/\x1D\x21./s', '/\x1B\x45./s', '/[\x00-\x08\x0B-\x1F]/'], ['', '', ' '], $raw);
    }

    public function test_it_renders_the_requested_sections_with_two_decimal_money(): void
    {
        $out = app(EscPosPayloadService::class)->buildReport($this->sample(['overview', 'order_types', 'categories', 'items']));
        $body = $this->text($out);

        $this->assertStringContainsString('KHATRI BIRYANI', $body);
        $this->assertStringContainsString('OVERALL', $body);
        $this->assertStringContainsString('NET SALES', $body);
        $this->assertStringContainsString('4,950.00', $body, 'financial figures keep two decimals');
        $this->assertStringContainsString('CASH FROM SALES', $body);
        $this->assertStringContainsString('ORDER TYPES', $body);
        $this->assertStringContainsString('DELIVERY', $body);
        $this->assertStringContainsString('CATEGORIES', $body);
        $this->assertStringContainsString('BIRYANI', $body);
        $this->assertStringContainsString('ITEMS', $body);
        $this->assertStringContainsString('BEEF BIRYANI', $body);
        $this->assertStringEndsWith("\x1D\x56\x42\x00", $out, 'the payload ends with a paper cut');
    }

    public function test_unticked_sections_do_not_render(): void
    {
        $body = $this->text(app(EscPosPayloadService::class)->buildReport($this->sample(['overview'])));

        $this->assertStringContainsString('OVERALL', $body);
        $this->assertStringNotContainsString('ITEMS', $body, 'an unticked section is omitted');
        $this->assertStringNotContainsString('CATEGORIES', $body);
    }
}
