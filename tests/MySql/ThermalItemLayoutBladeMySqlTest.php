<?php

namespace Tests\MySql;

use App\Support\ThermalLayout;

/**
 * THERMAL-ITEM-LAYOUT-1, the OTHER renderer.
 *
 * A thermal Z report leaves the building by two roads: EscPosPayloadService writes the bytes a
 * counter printer eats, and this Blade draws the preview, the PDF and the e-mailed copy. The first
 * road was fixed and the second was not, so the shop saw the old shape on screen and reported the
 * same faults again — names broken over two lines, and CATEGORIES and DEALS printing as one
 * unbroken block with no hierarchy at all. These assertions hold the Blade to what the roll does.
 *
 * SHAPE ONLY. Not one assertion here touches a figure.
 */
class ThermalItemLayoutBladeMySqlTest extends MySqlTenantTestCase
{
    private function item(string $name, $q, $v): array
    {
        return ['item' => $name, 'variant' => null, 'sold_qty' => $q, 'returned_qty' => 0,
                'net_qty' => $q, 'net' => $v, 'returns_amount' => 0, 'net_value' => $v];
    }

    private function render(string $paper = '80mm'): string
    {
        // The engine's own shape: every root carries a children list, and a root with no real
        // sub-categories carries exactly one child that IS itself.
        $cat = fn (int $id, string $name, array $kids, $q, $v) => [
            'id' => $id, 'name' => $name, 'orders' => $q, 'sold_qty' => $q, 'gross' => $v,
            'discount' => 0, 'tax' => 0, 'net' => $v, 'returned_qty' => 0,
            'returns_amount' => 0, 'net_qty' => $q, 'net_value' => $v, 'children' => $kids,
        ];
        return view('tenant.reports.center.print', [
            'mode' => 'thermal', 'paper' => $paper,
            'filters'  => ['date_from' => '2026-09-02', 'date_to' => '2026-09-02'],
            'sections' => ['categories', 'category_items', 'deals'],
            'bridge'   => ['delivery_charge' => 0, 'delivery_refunded' => 0, 'net_sales' => 0],
            'overview' => null, 'orderTypes' => null, 'waiters' => null, 'combos' => null,
            'cancellations' => null, 'cashBank' => null, 'items' => null,
            'categories' => [
                // A parent with children, and a parent with none — the two shapes on the roll.
                $cat(10, 'Continental', [
                    $cat(11, 'Steaks', [], 8, 9600),
                    $cat(12, 'Pasta', [], 11, 13750),
                ], 19, 23350),
                $cat(20, 'Bar-B-Que', [$cat(20, 'Bar-B-Que', [], 58, 45100)], 58, 45100),
            ],
            'categoryItems' => [
                ['head' => 'Continental', 'head_id' => 1, 'nested' => true,
                 'sold_qty' => 8, 'returned_qty' => 0, 'net_qty' => 8,
                 'net' => 9600, 'returns_amount' => 0, 'net_value' => 9600,
                 'groups' => [[
                     'id' => 2, 'name' => 'Steaks', 'sold_qty' => 8, 'returned_qty' => 0,
                     'net_qty' => 8, 'net' => 9600, 'returns_amount' => 0, 'net_value' => 9600,
                     'items' => [$this->item('Tarragon Steak With Mushroom Sauce (Chicken)', 3, 6150)],
                 ]]],
                ['head' => 'Bar-B-Que', 'head_id' => 3, 'nested' => false,
                 'sold_qty' => 58, 'returned_qty' => 0, 'net_qty' => 58,
                 'net' => 45100, 'returns_amount' => 0, 'net_value' => 45100,
                 'groups' => [[
                     'id' => 3, 'name' => 'Bar-B-Que', 'sold_qty' => 58, 'returned_qty' => 0,
                     'net_qty' => 58, 'net' => 45100, 'returns_amount' => 0, 'net_value' => 45100,
                     'items' => [$this->item('Chicken Malai Boti Full With Garlic Rice', 58, 45100)],
                 ]]],
            ],
            'deals' => [
                ['head' => 'Exclusive Deals', 'deal' => 'Exclusive Deal 1 - 2 Zinger + 2 Singaporean Rice + 4 Drinks',
                 'sold_qty' => 1, 'returned_qty' => 0, 'net_qty' => 1,
                 'net' => 2500, 'returns_amount' => 0, 'net_value' => 2500],
                ['head' => 'Midnight', 'deal' => 'Singaporean Rice (Regular)',
                 'sold_qty' => 33, 'returned_qty' => 0, 'net_qty' => 33,
                 'net' => 18150, 'returns_amount' => 0, 'net_value' => 18150],
            ],
        ])->render();
    }

    /** Every name the reader sees, in order, with its CSS level and its indent already applied. */
    private function names(string $html): array
    {
        preg_match_all('#<tr class="([^"]*\bname\b[^"]*)"><td colspan="4">(.*?)</td></tr>#s', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($r) => [
            'class' => $r[1],
            'text'  => html_entity_decode(strip_tags($r[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ], $m);
    }

    public static function paperSizes(): array
    {
        return ['80mm' => ['80mm', ThermalLayout::COLS_80MM], '58mm' => ['58mm', ThermalLayout::COLS_58MM]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("paperSizes")]
    public function test_no_name_is_wider_than_the_paper(string $paper, int $cols): void
    {
        foreach ($this->names($this->render($paper)) as $row) {
            $text = str_replace("\xC2\xA0", ' ', $row['text']);   // the indent is non-breaking space
            $this->assertLessThanOrEqual(
                $cols,
                mb_strlen($text),
                "{$paper}: a name plus its indent must fit the paper — [{$text}] is " . mb_strlen($text) . ' of ' . $cols
            );
        }
    }

    public function test_a_long_name_is_cut_rather_than_left_to_wrap(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString(
            'Exclusive Deal 1 - 2 Zinger + 2 Singaporean Rice + 4 Drinks',
            $html,
            'a 58-character deal name cannot print whole on 42 columns — it must be cut'
        );
        $this->assertStringContainsString('...', $html, 'the cut is marked with an ellipsis');
        // Bound to the SELECTOR, not to the words: the page carries other nowrap rules, and an
        // assertion that any of them exists would pass with the name cell free to wrap.
        $this->assertMatchesRegularExpression(
            '/tr\.name td,\s*td\.lbl\s*\{[^}]*white-space:\s*nowrap/',
            $html,
            'the name cell itself must forbid wrapping, as the belt to the cut'
        );
    }

    /** One section's HTML, so a name shared by two sections is not counted twice. */
    private function section(string $html, string $heading): string
    {
        $from = strpos($html, '<h2>' . $heading . '</h2>');
        $this->assertNotFalse($from, "the {$heading} section prints");
        $next = strpos($html, '<h2>', $from + 4);

        return $next === false ? substr($html, $from) : substr($html, $from, $next - $from);
    }

    public function test_a_category_with_no_children_prints_its_name_once_and_no_blank_line(): void
    {
        $html  = $this->render();
        $names = $this->names($this->section($html, 'CATEGORIES'));
        $texts = array_map(fn ($r) => trim(str_replace("\xC2\xA0", ' ', $r['text'])), $names);

        $this->assertSame(
            1,
            count(array_keys($texts, 'BAR-B-QUE', true)),
            'a childless category names itself once — not again as a sub-head of itself'
        );
        $this->assertNotContains(
            '',
            array_map(fn ($r) => trim(str_replace("\xC2\xA0", ' ', $r['text'])), $this->names($html)),
            'no name row anywhere may be blank — that is a wasted line of paper'
        );
    }

    public function test_a_total_closes_its_block_from_below(): void
    {
        $html = $this->render();

        // The rule that closes a block rides the Amt row — the LAST line of the entry — so it
        // prints under the total, not above it.
        $this->assertMatchesRegularExpression(
            '/tr\.amt-row\.lvl-headtotal td\s*\{[^}]*border-bottom:\s*2px solid/',
            $html,
            'a parent block closes with a solid rule BELOW its total'
        );
        $this->assertMatchesRegularExpression(
            '/tr\.amt-row\.lvl-subtotal\s+td\s*\{[^}]*border-bottom:\s*1px dotted/',
            $html,
            'a child block closes with a dotted rule BELOW its total'
        );
        $this->assertStringContainsString('class="amt-row total lvl-headtotal"', $html, 'the parent total carries the closing row');
    }

    public function test_no_rule_is_drawn_between_an_entrys_own_qty_and_amt(): void
    {
        $html = $this->render();

        // Every Qty row is the middle line of an entry. A rule there was the noise the shop asked
        // to have taken out: it fenced off each half of a single reading.
        $this->assertMatchesRegularExpression(
            '/tr\[class\*="lvl-"\] td\s*\{\s*border-top:\s*0/',
            $html,
            'a levelled row never inherits the legacy dashed rule'
        );
    }

    public function test_deals_print_as_a_hierarchy_with_a_total_per_head(): void
    {
        $names = $this->names($this->render());
        $seq   = array_map(fn ($r) => trim(str_replace("\xC2\xA0", ' ', $r['text'])) . '|' . $r['class'], $names);

        $head = array_search('EXCLUSIVE DEALS|name lvl-head', $seq, true);
        $this->assertNotFalse($head, 'a deal head prints as a head, with the rule that leads a block');

        $rest = array_slice($seq, $head + 1);
        $this->assertNotFalse(
            array_search('TOTAL|name total lvl-headtotal', $rest, true),
            'each deal head closes with its own total'
        );
        $this->assertGreaterThan(
            0,
            count(array_filter($rest, fn ($s) => str_contains($s, '|name lvl-item'))),
            'the deals themselves print as items under their head, not as one unbroken block'
        );
    }

    public function test_the_section_wide_total_is_named_in_the_reports_own_language(): void
    {
        $html = $this->render();

        // It was "KUL" — Urdu for total — on a report written entirely in English, and the owner
        // had to ask what it meant. If the person who commissioned the report cannot read a word
        // on it, the cashier at the counter certainly cannot.
        $this->assertStringNotContainsString('KUL', $html, 'no Urdu label on an English report');

        // One per section, and never confusable with the per-category TOTAL above it.
        foreach (['CATEGORIES', 'ITEMS BY CATEGORY', 'DEALS'] as $heading) {
            $texts = array_map(
                fn ($r) => trim(str_replace("\xC2\xA0", ' ', $r['text'])),
                $this->names($this->section($html, $heading))
            );
            $this->assertSame(
                1,
                count(array_keys($texts, 'GRAND TOTAL', true)),
                "{$heading}: exactly one section-wide total, named GRAND TOTAL"
            );
        }
    }

    public function test_each_level_steps_in_from_the_one_above_it(): void
    {
        $indent = fn (string $t) => mb_strlen($t) - mb_strlen(ltrim(str_replace("\xC2\xA0", ' ', $t)));
        $rows   = $this->names($this->render());

        $by = [];
        foreach ($rows as $r) {
            foreach (['lvl-head', 'lvl-sub', 'lvl-item'] as $lvl) {
                if (preg_match('/\b' . $lvl . '\b/', $r['class'])) { $by[$lvl][] = $indent($r['text']); }
            }
        }

        $this->assertSame([0], array_values(array_unique($by['lvl-head'] ?? [])), 'a head sits at the left margin');
        $this->assertSame([2], array_values(array_unique($by['lvl-sub'] ?? [])), 'a sub-head steps in one');
        $this->assertNotEmpty($by['lvl-item'] ?? [], 'items print');
        foreach ($by['lvl-item'] as $i) {
            $this->assertGreaterThanOrEqual(2, $i, 'an item is always further in than the left margin');
        }
    }
}
