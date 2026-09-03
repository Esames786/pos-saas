<?php

namespace Tests\MySql;

use App\Services\Printing\EscPosPayloadService;
use App\Support\ThermalLayout;
use Tests\TestCase;

/**
 * THERMAL-ITEM-LAYOUT-1 — the two item reports must fit the paper and read as a hierarchy.
 *
 * The shop read a Z report off the counter and listed what was wrong: long names ran off the roll
 * or broke over two lines with "(1 kg)" stranded at the left margin; category, sub-category and item
 * were told apart only by one space of indent; a rule after every single entry buried the headings;
 * and every category printed its total BEFORE the items it was the total of.
 *
 * These assertions are about SHAPE only. Not one of them touches a figure — the money is asserted
 * to be untouched by the report guards, and by the before/after snapshot taken at deploy.
 */
class ThermalItemLayoutMySqlTest extends TestCase
{
    /** A report payload with one nested head, one flat head, and a name too long for any paper. */
    private function payload(string $paper = '80mm'): array
    {
        $item = fn (string $name, $q, $v) => [
            'item' => $name, 'variant' => null, 'sold_qty' => $q, 'returned_qty' => 0,
            'net_qty' => $q, 'net' => $v, 'returns_amount' => 0, 'net_value' => $v,
        ];

        return [
            'meta' => ['business_name' => 'TEST', 'label' => 'Z', 'date_from' => '2026-09-02',
                       'date_to' => '2026-09-02', 'generated' => '', 'paper' => $paper],
            'sections' => ['items', 'category_items'],
            'items' => [
                (object) $item('2 Pcs Crispy Fried Chicken (Spicy, With Fries)', 12, 7200),
                (object) $item('Chicken Biryani (1/2 kg)', 22, 7260),
            ],
            'categoryItems' => [
                ['head' => 'Chicken Biryani', 'head_id' => 1, 'nested' => true,
                 'sold_qty' => 24, 'returned_qty' => 0, 'net_qty' => 24,
                 'net' => 8560, 'returns_amount' => 0, 'net_value' => 8560,
                 'groups' => [[
                     'id' => 2, 'name' => 'Biryani Chicken', 'sold_qty' => 24, 'returned_qty' => 0,
                     'net_qty' => 24, 'net' => 8560, 'returns_amount' => 0, 'net_value' => 8560,
                     'items' => [
                         $item('Chicken Biryani (1/2 kg)', 22, 7260),
                         $item('2 Pcs Crispy Fried Chicken (Spicy, With Fries)', 2, 1300),
                     ],
                 ]]],
                ['head' => 'Singaporean Rice', 'head_id' => 3, 'nested' => false,
                 'sold_qty' => 3, 'returned_qty' => 0, 'net_qty' => 3,
                 'net' => 2100, 'returns_amount' => 0, 'net_value' => 2100,
                 'groups' => [[
                     'id' => 3, 'name' => 'Singaporean Rice', 'sold_qty' => 3, 'returned_qty' => 0,
                     'net_qty' => 3, 'net' => 2100, 'returns_amount' => 0, 'net_value' => 2100,
                     'items' => [$item('Singaporean Rice (Large)', 3, 2100)],
                 ]]],
            ],
        ];
    }

    /** The printable text of the roll, with every ESC/POS command byte stripped out. */
    private function roll(string $paper = '80mm'): array
    {
        $esc = app(EscPosPayloadService::class)->buildReport($this->payload($paper));
        $txt = preg_replace('/\x1d\x21[\x00-\xff]/', '', $esc);
        $txt = preg_replace('/\x1b\x45[\x00-\x01]|\x1b\x2d[\x00-\x01]|\x1b\x61[\x00-\x02]|\x1b\x40|\x1d\x56[\x00-\xff]{1,2}|\x1b\x64[\x00-\xff]/', '', $txt);
        $txt = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $txt);

        return explode("\n", $txt);
    }

    /* ── the paper ───────────────────────────────────────────────────────── */

    public static function paperSizes(): array
    {
        return ['80mm' => ['80mm', 42], '58mm' => ['58mm', 32]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("paperSizes")]
    public function test_no_line_runs_off_the_paper(string $paper, int $cols): void
    {
        $over = array_filter($this->roll($paper), fn ($l) => mb_strlen(rtrim($l)) > $cols);

        $this->assertSame([], array_values($over),
            "every line must fit {$cols} columns; a longer one is what pushed '(1 kg)' onto a line of its own");
    }

    /**
     * A name that cannot fit is CUT, never wrapped — and its size survives the cut, because
     * "Special (1 kg)" and "Special (1/2 kg)" trimmed plainly are the same string, which would put
     * two different products on two rows under one name.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider("paperSizes")]
    public function test_a_long_name_is_cut_and_keeps_its_size(string $paper, int $cols): void
    {
        $lines = $this->roll($paper);
        $hit = array_values(array_filter($lines, fn ($l) => str_contains($l, '...')));

        $this->assertNotEmpty($hit, 'the 46-character name must have been shortened on both papers');
        foreach ($hit as $line) {
            $this->assertLessThanOrEqual($cols, mb_strlen(rtrim($line)));
        }

        // 80mm has room to keep the size; 58mm may not, and then a plain cut is honest.
        if ($paper === '80mm') {
            $this->assertTrue(
                (bool) array_filter($hit, fn ($l) => str_contains($l, '(Spicy, With Fries)')),
                'on 80mm the trailing size must survive so two sizes never read as one product'
            );
        }
    }

    /* ── the hierarchy ───────────────────────────────────────────────────── */

    public function test_a_total_comes_after_the_things_it_totals(): void
    {
        $all = $this->roll();
        // ITEMS (flat) isi parche par ooper chhapta hai aur wahi naam rakhta hai, is liye talash
        // sirf ITEMS BY CATEGORY ke hisse me — warna pehla match doosre section ka nikal aata hai.
        $from = 0;
        foreach ($all as $i => $l) { if (str_contains($l, "ITEMS BY CATEGORY")) { $from = $i; break; } }
        $lines = array_slice($all, $from);
        $find = function (string $needle) use ($lines) {
            foreach ($lines as $i => $l) {
                if (str_contains($l, $needle)) { return $i; }
            }
            return -1;
        };

        $head  = $find('CHICKEN BIRYANI');
        $child = $find('Biryani Chicken');
        $item  = $find('Chicken Biryani (1/2 kg)');

        $this->assertGreaterThan(-1, $head);
        $this->assertGreaterThan($head, $child, 'the sub-head comes under its head');
        $this->assertGreaterThan($child, $item, 'items come under their sub-head');

        // The first TOTAL after the items is the child's; the head's follows it.
        $totals = [];
        foreach ($lines as $i => $l) {
            if (trim($l) !== '' && str_starts_with(ltrim($l), 'TOTAL')) { $totals[] = $i; }
        }
        $after = array_values(array_filter($totals, fn ($i) => $i > $item));
        $this->assertNotEmpty($after, 'a category prints its total AFTER its items, not above them');
    }

    public function test_a_flat_category_prints_no_sub_head_of_its_own_name(): void
    {
        $lines = $this->roll();
        $body = implode("\n", $lines);

        $this->assertStringContainsString('SINGAPOREAN RICE', $body);
        // "Singaporean Rice" in title case would be the ceremonial sub-head repeating its parent.
        $repeat = array_filter($lines, fn ($l) => trim($l) === 'Singaporean Rice');
        $this->assertSame([], array_values($repeat),
            'a category with no children must not print a sub-head that only says its own name again');
    }

    public function test_entries_are_not_separated_by_a_rule(): void
    {
        $lines = $this->roll();
        $dashRules = array_filter($lines, fn ($l) => rtrim($l) !== '' && trim($l, '- ') === '');

        // Two section headings each draw one; a rule per entry would be many more.
        // Har section ka heading do rule kheenchta hai (ooper aur column heading ke neeche),
        // aur report ka apna heading ek. Per-entry design me ye ginti item ke saath barhti.
        $this->assertLessThanOrEqual(6, count($dashRules),
            'a rule after every entry buried the category headings — rules are for headings and totals only');
    }

    /* ── the helper ──────────────────────────────────────────────────────── */

    public function test_the_layout_helper_never_returns_a_longer_string_than_asked(): void
    {
        foreach ([8, 12, 20, 28, 34, 42] as $width) {
            foreach ([
                '2 Pcs Crispy Fried Chicken (Spicy, With Fries)',
                '2 Pcs Crispy Fried Chicken (Spicy, With Fries)',
                'Raita',
                'Chicken Chow Mein (1 Person)',
            ] as $name) {
                $this->assertLessThanOrEqual($width, mb_strlen(ThermalLayout::fit($name, $width)),
                    "fit('{$name}', {$width}) must never exceed the width it was given");
            }
        }
    }

    public function test_the_indent_shrinks_when_the_label_budget_does(): void
    {
        [$wide]   = ThermalLayout::indents(18);
        [$narrow] = ThermalLayout::indents(8);
        [$none]   = ThermalLayout::indents(2);

        $this->assertSame(6, $wide, '80mm can afford the full indent');
        $this->assertLessThan($wide, $narrow, '58mm must give the label room back');
        $this->assertSame(0, $none, 'with no budget left there is no indent to give');
    }
}
