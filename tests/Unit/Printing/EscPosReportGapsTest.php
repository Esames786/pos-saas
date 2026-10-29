<?php

namespace Tests\Unit\Printing;

use App\Services\Printing\EscPosPayloadService;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-NETWORK-GAPS-1 — what the network slip printed differently from the browser print.
 *
 * 1. A total wider than every row it adds up: the columns were sized from the rows only, so a
 *    CATEGORIES grand total of 133,480 printed "0133,480" (Ret 0 run into Net 133,480).
 * 2. BY ORDER TYPE: the slip had no layout for it at all.
 * Deals reaching the slip is the controllers' half and is held by the MySQL tests.
 */
class EscPosReportGapsTest extends TestCase
{
    private const META = ['business_name' => 'Test Kitchen', 'label' => 'Z / End of Day', 'date_from' => '2026-10-27', 'date_to' => '2026-10-27', 'generated' => '27-Oct-2026 23:50', 'paper' => '80mm'];

    private function text(string $raw): string
    {
        return preg_replace(['/\x1D\x21./s', '/\x1B\x45./s', '/\x1B\x2D./s', '/\x1D\x56\x42./s', '/[\x00-\x08\x0B-\x1F]/'], ['', '', '', '', ''], $raw);
    }

    private function cat(int $id, string $name, float $qty, float $amount, float $ret = 0): array
    {
        return ['id' => $id, 'name' => $name, 'sold_qty' => $qty, 'returned_qty' => 0, 'net_qty' => $qty,
            'net' => $amount, 'returns_amount' => $ret, 'net_value' => $amount - $ret, 'children' => []];
    }

    /** Lines that carry the three Sold / Ret / Net figures, and the figures on each. */
    private function figureLines(string $body): array
    {
        preg_match_all('/^ *(?:Qty|Amt|\d+ ord)( +.*)$/m', $body, $m);

        return array_map(fn ($tail) => preg_split('/ +/', trim($tail)), $m[1]);
    }

    public function test_a_grand_total_wider_than_any_category_keeps_its_columns_apart(): void
    {
        // Each category is six characters wide (70,000 / 63,480); their sum is seven (133,480).
        $body = $this->text(app(EscPosPayloadService::class)->buildReport([
            'meta' => self::META, 'sections' => ['categories'],
            'categories' => [$this->cat(1, 'Karahi', 140, 70000), $this->cat(2, 'Biryani', 352, 63480)],
        ]));

        $this->assertMatchesRegularExpression('/^Amt +133,480 +0 +133,480$/m', $body, 'GRAND TOTAL: Sold, Ret and Net each in its own column');
        foreach ($this->figureLines($body) as $cells) {
            $this->assertCount(3, $cells, 'three separate figures on every line, got ' . implode('|', $cells));
        }
    }

    public function test_order_type_and_waiter_totals_keep_their_columns_apart(): void
    {
        $rows = [
            ['label' => 'Dine In', 'orders' => 50, 'grand_total' => 70000, 'returns_amount' => 0, 'net_sales' => 70000],
            ['label' => 'Delivery', 'orders' => 21, 'grand_total' => 63480, 'returns_amount' => 0, 'net_sales' => 63480],
        ];
        $body = $this->text(app(EscPosPayloadService::class)->buildReport([
            'meta' => self::META, 'sections' => ['order_types', 'waiters'], 'orderTypes' => $rows, 'waiters' => $rows,
        ]));

        $this->assertMatchesRegularExpression('/^71 ord +133,480 +0 +133,480$/m', $body);
        foreach ($this->figureLines($body) as $cells) {
            $this->assertCount(3, $cells, 'three separate figures on every line, got ' . implode('|', $cells));
        }
    }

    private function combos(): array
    {
        $line = fn (string $label, float $qty, float $amount) => ['label' => $label, 'sold_qty' => $qty, 'returned_qty' => 0, 'net_qty' => $qty,
            'net' => $amount, 'returns_amount' => 0, 'net_value' => $amount];

        return [
            'categories' => ['Dine In' => [$line('Karahi', 1, 1200)], 'Delivery' => [$line('Biryani', 3, 300)]],
            'items' => ['Dine In' => [$line('Chicken Karahi (Half)', 1, 1200)], 'Delivery' => [$line('Biryani Chicken', 3, 300)]],
            'waiters' => ['Dine In' => [['label' => 'Waiter One', 'orders' => 1, 'grand_total' => 1200, 'returns_amount' => 0, 'net_sales' => 1200]],
                'Delivery' => [['label' => 'Unassigned', 'orders' => 1, 'grand_total' => 330, 'returns_amount' => 0, 'net_sales' => 330]]],
            'totals' => ['Dine In' => ['merch_net' => 1200, 'net_sales' => 1200, 'net_charges' => 0],
                'Delivery' => ['merch_net' => 300, 'net_sales' => 330, 'net_charges' => 30]],
        ];
    }

    public function test_by_order_type_prints_every_type_and_closes_each_on_its_own_net_sales(): void
    {
        $body = $this->text(app(EscPosPayloadService::class)->buildReport([
            'meta' => self::META, 'sections' => ['order_type_combos'], 'combos' => $this->combos(),
        ]));

        // The browser's order: every type's categories, then every type's items, then waiters.
        $order = ['BY ORDER TYPE', 'DINE IN - CATEGORIES', 'DELIVERY - CATEGORIES', 'DINE IN - ITEMS', 'DELIVERY - ITEMS', 'DINE IN - WAITERS', 'DELIVERY - WAITERS'];
        $at = array_map(fn ($h) => strpos($body, $h), $order);
        foreach ($order as $i => $h) {
            $this->assertNotFalse($at[$i], "{$h} is on the slip");
        }
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, 'headings in the browser print\'s order');

        // Delivery closes on 300 + 30 = 330 under its categories AND its items; Dine In has no charge, so no bridge.
        $this->assertSame(2, preg_match_all('/^Plus Delivery & Other Charges +30$/m', $body));
        $this->assertSame(2, preg_match_all('/^= NET SALES +330$/m', $body));
        $this->assertDoesNotMatchRegularExpression('/^= NET SALES +1,200$/m', $body);
        $this->assertMatchesRegularExpression('/^1 ord +330 +0 +330$/m', $body, 'Delivery waiters');
    }

    public function test_no_line_runs_off_the_paper(): void
    {
        foreach (['80mm' => 42, '58mm' => 32] as $paper => $cols) {
            $raw = app(EscPosPayloadService::class)->buildReport([
                'meta' => ['paper' => $paper] + self::META,
                'sections' => ['categories', 'order_types', 'order_type_combos'],
                'categories' => [$this->cat(1, 'Karahi', 140, 70000), $this->cat(2, 'Biryani', 352, 63480)],
                'orderTypes' => [['label' => 'Delivery', 'orders' => 21, 'grand_total' => 133480, 'returns_amount' => 0, 'net_sales' => 133480]],
                'combos' => $this->combos(),
            ]);
            foreach (explode("\n", $this->text($raw)) as $line) {
                $this->assertLessThanOrEqual($cols, mb_strlen($line), "{$paper}: [{$line}]");
            }
        }
    }
}
