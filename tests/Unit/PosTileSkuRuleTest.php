<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * PRODUCT-TILE-SKU-1 — the rule that decides whether a POS tile prints its SKU.
 *
 * A SKU exists to tell things apart. On some tenants every SKU is the product name in capitals,
 * so the tile printed the same words twice; on others it is a real code (KF-001, RM-BEEF) or a
 * scannable barcode, where hiding it would take away something the counter reads. The rule is
 * therefore per product, and needs no setting anyone has to keep true.
 *
 * The rule lives in JavaScript, and a guard that only greps the blade is weak: one such guard in
 * this repo stayed GREEN while the code it was meant to protect had been switched off with
 * `if (false)`. So this pulls the SHIPPED function out of the blade and RUNS it in node against
 * real product shapes taken from the four live tenants. Where node is unavailable the
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

    /** Run the blade's own skuSaysSomethingNew() over these products and return its answers. */
    private function runShippedRule(array $products): array
    {
        $node = $this->nodeBinary();
        if ($node === null) {
            $this->markTestSkipped('node not found; set NODE_BINARY to run the behavioural SKU-rule guard');
        }

        preg_match('/\n    function skuSaysSomethingNew\(product\) \{.*?\n    \}\n/s', $this->posBlade(), $m);
        $this->assertNotEmpty($m, 'skuSaysSomethingNew() could not be located in the POS blade');

        // The cases are EMBEDDED in the script rather than passed as argv: Windows wraps arguments
        // in double quotes and ate the JSON on its way through the shell.
        $script = $m[0]
            . PHP_EOL . 'const cases = ' . json_encode($products) . ';'
            . PHP_EOL . 'console.log(JSON.stringify(cases.map(skuSaysSomethingNew)));' . PHP_EOL;

        $file = tempnam(sys_get_temp_dir(), 'skurule') . '.js';
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

    public function test_a_sku_that_only_repeats_the_name_is_not_shown(): void
    {
        // Real rows: Khatri is 66/66 like this, Kashif Food 212/212.
        $answers = $this->runShippedRule([
            ['name' => 'Beef Khatri Biryani 1 Pao', 'sku' => 'BEEF-KHATRI-BIRYANI-1-PAO'],
            ['name' => 'Plain Rice', 'sku' => 'PLAIN-RICE'],
            // Punctuation must not fool it: this name is stored with the slashes dropped.
            ['name' => 'Beef Khatri Biryani (1/2 kg)', 'sku' => 'BEEF-KHATRI-BIRYANI-12-KG'],
            ['name' => 'Singaporean Rice (Family Pack Large)', 'sku' => 'SINGAPOREAN-RICE-FAMILY-PACK-LARGE'],
            // Case alone is not a difference either.
            ['name' => 'plain rice', 'sku' => 'PLAIN-RICE'],
        ]);

        $this->assertSame([false, false, false, false, false], $answers,
            'a SKU carrying no information beyond the name must not take a line on the tile');
    }

    public function test_a_real_code_or_a_barcode_is_always_shown(): void
    {
        // Rows that exist today: Tawakal is 0/112 name-like, Kashif Kitchen 0/918, and retaildemo
        // stores actual barcodes. Hiding these would destroy what the counter reads.
        $answers = $this->runShippedRule([
            ['name' => 'Singaporean Rice', 'sku' => 'KF-001'],
            ['name' => 'Chicken (Regular)', 'sku' => 'RM-CHICKEN'],
            ['name' => 'Beef (With Bone)', 'sku' => 'RM-BEEF'],
            ['name' => 'Basmati Rice 5kg', 'sku' => '890100000001'],
            // A near-miss is still a difference — it is exactly how a stale SKU shows itself.
            ['name' => 'Rice of Khaas', 'sku' => 'SINGAPOREAN-RICE-KHAAS-BBQ'],
        ]);

        $this->assertSame([true, true, true, true, true], $answers,
            'a SKU that says something the name does not must stay on the tile');
    }

    public function test_an_empty_sku_keeps_the_old_no_sku_line(): void
    {
        // No product carries an empty SKU today, but changing that behaviour would be a silent
        // extra change riding along with this one.
        $answers = $this->runShippedRule([
            ['name' => 'Plain Rice', 'sku' => ''],
            ['name' => 'Plain Rice', 'sku' => null],
            ['name' => 'Plain Rice'],
        ]);

        $this->assertSame([true, true, true], $answers, 'an empty SKU still renders the No SKU line');
    }

    public function test_search_and_barcode_scanning_are_not_touched(): void
    {
        // THE guard that matters most on a sensitive screen: a hidden SKU must still be findable.
        // The tile stops PRINTING it; nothing may stop READING it.
        $view = $this->posBlade();

        $this->assertStringContainsString("String(product.sku || '').toLowerCase().includes(query)", $view,
            'the product grid filter must still match on SKU');
        $this->assertStringContainsString("String(p.sku || '').toLowerCase().indexOf(q) !== -1", $view,
            'the search suggestion list must still match on SKU');
    }

    public function test_the_tile_actually_consults_the_rule(): void
    {
        $view = $this->posBlade();

        // Pin the call site: the rule existing while the tile ignores it is the failure mode a
        // grep-only guard misses.
        $this->assertStringContainsString('(skuSaysSomethingNew(product)', $view);
        $this->assertStringNotContainsString(
            "'<div class=\"text-muted small mb-2\">' + escapeHtml(product.sku || 'No SKU') + '</div>' +",
            $view,
            'the unconditional SKU line must be gone, not merely shadowed'
        );
    }
}
