<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * PRODUCT-TILE-SKU-1 — the rule that decides whether a POS tile prints its identifier.
 *
 * Both tiles carry one: a product prints its `sku`, a deal prints its `code`, in the same slot
 * under the name. An identifier exists to TELL THINGS APART, so it earns that line only when it
 * says something the name does not.
 *
 * Measured across every tenant before this was wired: 272 of 1,557 products repeat their own name
 * (Khatri 66/66, Kashif Food 212/212), but 0 of 90 deals do — every deal code is a real one
 * (KF-PLAT-ALFAHAM-H, TK-D1). So this hides a great many product lines and, today, not one deal
 * line. The deal tile is wired anyway so the rule cannot disagree with itself later.
 *
 * The rule lives in JavaScript, and a guard that only greps the blade is weak: one such guard in
 * this repo stayed GREEN while the code it protected had been switched off with `if (false)`. So
 * this pulls the SHIPPED function out of the blade and RUNS it in node. Where node is missing the
 * behavioural part skips loudly instead of passing quietly.
 */
class PosTileSkuRuleTest extends TestCase
{
    private function posBlade(): string
    {
        return file_get_contents(resource_path('views/tenant/pos/index.blade.php'));
    }

    private function nodeBinary(): ?string
    {
        foreach (array_filter([getenv('NODE_BINARY') ?: null, 'node']) as $candidate) {
            $out = [];
            $code = 1;
            @exec(escapeshellarg($candidate) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /** Run the blade's own labelSaysSomethingNew() over these [label, name] pairs. */
    private function runShippedRule(array $cases): array
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('node not found; set NODE_BINARY to run the behavioural tile-label guard');
        }

        preg_match('/\n    function labelSaysSomethingNew\(label, name\) \{.*?\n    \}\n/s', $this->posBlade(), $m);
        $this->assertNotEmpty($m, 'labelSaysSomethingNew() could not be located in the POS blade');

        // Cases are EMBEDDED in the script rather than passed as argv: Windows wraps arguments in
        // double quotes and ate the JSON on its way through the shell.
        $script = $m[0]
            . PHP_EOL . 'const cases = ' . json_encode($cases) . ';'
            . PHP_EOL . 'console.log(JSON.stringify(cases.map(function (c) { return labelSaysSomethingNew(c.label, c.name); })));' . PHP_EOL;

        $file = tempnam(sys_get_temp_dir(), 'tilelabel') . '.js';
        file_put_contents($file, $script);

        $out = [];
        $code = 1;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        @unlink($file);

        $this->assertSame(0, $code, 'node could not run the shipped rule: ' . implode("\n", $out));
        $answers = json_decode(trim(implode('', $out)), true);
        $this->assertIsArray($answers, 'the shipped rule did not return an array: ' . implode("\n", $out));

        return $answers;
    }

    public function test_a_label_that_only_repeats_the_name_is_not_shown(): void
    {
        $answers = $this->runShippedRule([
            ['name' => 'Beef Khatri Biryani 1 Pao', 'label' => 'BEEF-KHATRI-BIRYANI-1-PAO'],
            ['name' => 'Plain Rice', 'label' => 'PLAIN-RICE'],
            // Punctuation must not fool it: this name is stored with the slashes dropped.
            ['name' => 'Beef Khatri Biryani (1/2 kg)', 'label' => 'BEEF-KHATRI-BIRYANI-12-KG'],
            ['name' => 'Singaporean Rice (Family Pack Large)', 'label' => 'SINGAPOREAN-RICE-FAMILY-PACK-LARGE'],
            // Case alone is not a difference either.
            ['name' => 'plain rice', 'label' => 'PLAIN-RICE'],
        ]);

        $this->assertSame([false, false, false, false, false], $answers,
            'a label carrying no information beyond the name must not take a line on the tile');
    }

    public function test_a_real_code_or_a_barcode_is_always_shown(): void
    {
        // Rows that exist today: Tawakal 0/112 name-like products, Kashif Kitchen 0/918, and
        // retaildemo stores actual barcodes. Hiding these would destroy what the counter reads.
        $answers = $this->runShippedRule([
            ['name' => 'Singaporean Rice', 'label' => 'KF-001'],
            ['name' => 'Chicken (Regular)', 'label' => 'RM-CHICKEN'],
            ['name' => 'Beef (With Bone)', 'label' => 'RM-BEEF'],
            ['name' => 'Basmati Rice 5kg', 'label' => '890100000001'],
            // A near-miss is still a difference — it is how a stale SKU shows itself.
            ['name' => 'Rice of Khaas', 'label' => 'SINGAPOREAN-RICE-KHAAS-BBQ'],
        ]);

        $this->assertSame([true, true, true, true, true], $answers,
            'a label that says something the name does not must stay on the tile');
    }

    public function test_every_deal_code_in_the_system_today_stays_on_its_tile(): void
    {
        // 0 of 90 combos repeat their name, so wiring the deal tile must change nothing for them.
        $answers = $this->runShippedRule([
            ['name' => 'Grill Chicken Al-Faham (Half)', 'label' => 'KF-PLAT-ALFAHAM-H'],
            ['name' => 'Classic Platter 1 (6 Persons)', 'label' => 'KF-PLAT-CLASSIC-1'],
            ['name' => 'Deal 1', 'label' => 'TK-D1'],
            ['name' => 'Burger Meal Combo', 'label' => 'COMBO-BURGER'],
        ]);

        $this->assertSame([true, true, true, true], $answers);

        // …and it still dedupes a deal whose code IS its name, which is the only reason the deal
        // tile is wired at all.
        $this->assertSame([false], $this->runShippedRule([
            ['name' => 'Family Deal', 'label' => 'FAMILY-DEAL'],
        ]));
    }

    public function test_an_empty_label_keeps_the_old_fallback_line(): void
    {
        // Nothing carries an empty label today, but changing that behaviour would be a silent
        // extra change riding along with this one.
        $answers = $this->runShippedRule([
            ['name' => 'Plain Rice', 'label' => ''],
            ['name' => 'Plain Rice', 'label' => null],
            ['name' => 'Plain Rice'],
        ]);

        $this->assertSame([true, true, true], $answers);
    }

    public function test_searching_by_sku_or_deal_code_is_not_touched(): void
    {
        // THE guard that matters most on a sensitive screen: a hidden identifier must still be
        // findable. The tile stops PRINTING it; nothing may stop READING it.
        $view = $this->posBlade();

        $this->assertStringContainsString("String(product.sku || '').toLowerCase().includes(query)", $view,
            'the product grid filter must still match on SKU');
        $this->assertStringContainsString("String(p.sku || '').toLowerCase().indexOf(q) !== -1", $view,
            'the search suggestion list must still match on SKU');
        $this->assertStringContainsString("String(combo.code || '').toLowerCase().includes(query)", $view,
            'deal search must still match on code');
    }

    public function test_both_tiles_consult_the_rule(): void
    {
        $view = $this->posBlade();

        // Wiring one tile and not the other is exactly the gap this test exists to close.
        $this->assertStringContainsString('(labelSaysSomethingNew(product.sku, product.name)', $view);
        $this->assertStringContainsString('(labelSaysSomethingNew(combo.code, combo.name)', $view);

        foreach (["escapeHtml(product.sku || 'No SKU')", "escapeHtml(combo.code || 'Combo')"] as $call) {
            $this->assertStringNotContainsString(
                $call . " + '</div>' +",
                $view,
                'the UNCONDITIONAL line must be gone: only it carries the trailing plus that '
                    . 'joined it straight into the next fragment — ' . $call
            );
        }
    }
}
