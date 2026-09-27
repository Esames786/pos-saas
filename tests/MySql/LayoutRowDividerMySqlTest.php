<?php

namespace Tests\MySql;

use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINT-LAYOUT-ROWS-1 — the KOT, receipt and reminder honour the new layout settings end-to-end,
 * with a sale that mixes a NORMAL product, a VARIANT product, a COMBO (header + component) and a
 * MODIFIER — proving every line kind still renders once dividers / category / row-size are applied.
 */
class LayoutRowDividerMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'category_printer_mappings', 'terminal_printer_settings', 'receipt_layout_settings',
            'printers', 'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->categoryId = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $printer = $this->makePrinter([
            'code' => 'P1', 'name' => 'Kitchen', 'print_role' => 'both',
            'branch_id' => $this->branchId, 'is_default' => 1,
        ]);
        DB::connection('tenant')->table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'category_id' => $this->categoryId, 'printer_id' => $printer,
            'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A sale carrying every line kind: normal (+ modifier), variant, combo header + component. */
    private function makeMixedSale(string $status): int
    {
        $saleId = $this->makeSale($this->branchId, ['order_type' => 'dine_in', 'status' => $status]);

        $normal = $this->makeProduct($this->categoryId, ['name' => 'Chicken Biryani']);
        $this->makeSaleLine($saleId, $normal, [
            'product_name' => 'Chicken Biryani', 'quantity' => 2,
            'modifiers' => json_encode([['name' => 'Extra Spicy', 'price_delta' => 0]]),
        ]);

        $variant = $this->makeProduct($this->categoryId, ['name' => 'Beef Khatri Biryani']);
        $this->makeSaleLine($saleId, $variant, [
            'product_name' => 'Beef Khatri Biryani', 'variant_name' => '1/2 kg', 'quantity' => 1,
        ]);

        $comboProduct = $this->makeProduct($this->categoryId, ['name' => 'Family Deal']);
        $comboId = $this->makeSaleLine($saleId, $comboProduct, [
            'product_name' => 'Family Deal', 'line_kind' => 'combo_header', 'quantity' => 1,
        ]);
        $componentProduct = $this->makeProduct($this->categoryId, ['name' => 'Raita']);
        $this->makeSaleLine($saleId, $componentProduct, [
            'product_name' => 'Raita', 'line_kind' => 'component',
            'parent_sales_order_line_id' => $comboId, 'quantity' => 1,
        ]);

        return $saleId;
    }

    private function setLayout(string $documentType, array $attrs): void
    {
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $this->branchId, 'document_type' => $documentType],
            array_merge(['paper_size' => '80mm', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()], $attrs),
        );
    }

    public function test_kot_with_dividers_on_and_category_off_still_renders_every_line_kind(): void
    {
        $this->setLayout('kot', [
            'kot_font_size' => 18, 'item_font_size' => 17, 'time_font_size' => 12,
            'show_column_dividers' => 1, 'show_category_header' => 0,
        ]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($this->makeMixedSale('held')));
        $payload = collect($jobs)->pluck('raw_payload')->implode("\n");

        $this->assertStringContainsString(' | ', $payload, 'divider line between Qty and Item');
        $this->assertStringNotContainsString('[ ', $payload, 'category header removed when toggled off');
        // Every line kind survives: normal, variant sub-row, combo component, modifier sub-row.
        $this->assertStringContainsString('CHICKEN BIRYANI', $payload);
        $this->assertStringContainsString('BEEF KHATRI BIRYANI', $payload);
        $this->assertStringContainsString('1/2 kg', $payload, 'variant prints as a sub-row');
        $this->assertStringContainsString('RAITA', $payload, 'combo component prints on the KOT');
        $this->assertStringContainsString('Extra Spicy', $payload, 'modifier prints as a sub-row');
    }

    public function test_kot_defaults_keep_category_header_and_no_dividers(): void
    {
        // No item/time overrides, dividers OFF (default), category ON (default) — today's ticket.
        $this->setLayout('kot', [
            'kot_font_size' => 14, 'show_column_dividers' => 0, 'show_category_header' => 1,
        ]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($this->makeMixedSale('held')));
        $payload = collect($jobs)->pluck('raw_payload')->implode("\n");

        $this->assertStringContainsString('[ ', $payload, 'category header prints when left on');
        $this->assertStringNotContainsString(' | ', $payload, 'no divider when the toggle is off');
    }

    public function test_receipt_with_dividers_renders_combo_modifier_and_variant(): void
    {
        $this->setLayout('receipt', [
            'font_size' => 15, 'item_font_size' => 14, 'show_column_dividers' => 1,
        ]);

        $job = app(PrintJobService::class)->queueReceipt(SalesOrder::findOrFail($this->makeMixedSale('paid')));
        $payload = (string) $job->raw_payload;

        $this->assertStringContainsString(' | ', $payload, 'divider lines between the receipt columns');
        $this->assertStringContainsString('Amount', $payload, 'the Amount column header is present');
        $this->assertStringContainsString('Family Deal', $payload, 'combo header prints on the receipt');
        // COMBO-RECEIPT-NAME-ONLY (f191680): the receipt shows only the DEAL NAME, not its components
        // (the KOT keeps every component). This assertion was stale — it predated that shipped change.
        $this->assertStringNotContainsString('Raita', $payload, 'combo components are dropped from the receipt (deal name only)');
        $this->assertStringContainsString('(1/2 kg)', $payload, 'variant prints in parentheses');
        $this->assertStringContainsString('Extra Spicy', $payload, 'modifier prints under its item');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REMINDER-DEAL-NAME-ONLY-1 — reminder par deal ka sirf naam
    //
    // Maalik: deal/combo ki tafseel reminder par nahi chahiye. Receipt par ye faisla
    // pehle se laga hua hai (COMBO-RECEIPT-NAME-ONLY); ab reminder us ke saath mil raha
    // hai. KOT par components QAAYAM hain — kitchen unhi se khana banati hai.
    //
    // Doc: docs/plans/reminder-deal-name-only-2026-09-28.md
    // ══════════════════════════════════════════════════════════════════════════

    /** 🚨 Reminder par deal apne naam se aaye, us ke purze nahi. */
    public function test_reminder_prints_the_deal_name_without_its_components(): void
    {
        $this->setLayout('reminder', ['font_size' => 18, 'item_font_size' => 17]);

        $payload = $this->reminderPayload();

        $this->assertStringContainsString('FAMILY DEAL', $payload,
            'deal apne naam se chhapni chahiye');
        $this->assertStringNotContainsString('RAITA', $payload,
            'deal ke components reminder par nahi aane chahiyen — yehi asal farmaish thi');
    }

    /** Top-level items aur un ke modifiers qaayam rahein — sirf components gaye hain. */
    public function test_reminder_keeps_top_level_items_and_their_modifiers(): void
    {
        $this->setLayout('reminder', ['font_size' => 18, 'item_font_size' => 17]);

        $payload = $this->reminderPayload();

        $this->assertStringContainsString('CHICKEN BIRYANI', $payload, 'aam item qaayam');
        $this->assertStringContainsString('BEEF KHATRI BIRYANI', $payload, 'variant wala item qaayam');
        $this->assertStringContainsString('Extra Spicy', $payload,
            'TOP-LEVEL item ka modifier qaayam rahe — sirf COMPONENT ke saath wale gaye hain');
    }

    /**
     * 🚨 KOT BE-HARKAT — sab se ahem guard.
     *
     * Reminder counter ka recap hai; KOT kitchen ka parcha. Agar ye tabdeeli KOT tak pahunch
     * jaye to kitchen ko pata hi na chale ke deal me kya banana hai — khana ruk jaye.
     */
    public function test_the_kot_still_prints_every_deal_component(): void
    {
        $this->setLayout('kot', ['kot_font_size' => 18]);

        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($this->makeMixedSale('held')));
        $payload = collect($jobs)->pluck('raw_payload')->implode("\n");

        $this->assertStringContainsString('RAITA', $payload,
            'KOT par deal ke components QAAYAM rehne chahiyen — kitchen unhi se khana banati hai');

        // ⚠️ Yahan pehle maine `FAMILY DEAL` bhi assert kiya tha — GALAT tha, aur harness ne
        // pakra. KOT combo_header ko jaan-boojh kar chhoRta hai ("the kitchen makes its
        // COMPONENTS"), warna wo apni alag parchi ban kar khali slip chhapta. Deal ka naam
        // components ke saath KOT-CATEGORY/DEAL-NAME wale raaste se aata hai, is line se nahi.
        $this->assertStringContainsString('CHICKEN BIRYANI', $payload,
            'baaqi items bhi KOT par qaayam rahein');
    }

    /**
     * Font: item row aur sub-row ek hi scale par.
     *
     * `$sub = ['w' => 1, 'h' => $rowBig['h']]` — farq sirf chaurai ka hai. `item_font_size = 17`
     * par `$rowBig` bhi `w1 h2` ho jata hai, yani dono barabar. Ye SETTING ka pehra hai: koi kal
     * `scaleFor()` ki bands badal de to ye guard batayega.
     */
    public function test_item_rows_and_sub_rows_share_one_scale_at_seventeen(): void
    {
        $svc = app(\App\Services\Printing\EscPosPayloadService::class);
        $m = new \ReflectionMethod($svc, 'scaleFor');
        $m->setAccessible(true);

        $row = $m->invoke($svc, 17);

        $this->assertSame(['w' => 1, 'h' => $row['h']], $row,
            'item_font_size 17 par item row aur sub-row ka scale ek hona chahiye (w1 h2)');
        $this->assertSame(2, $m->invoke($svc, 18)['w'],
            '18 par chaurai dugni hoti hai — yehi wo soorat thi jo maalik ne chhoti karwayi');
    }

    /**
     * Asli raasta: asli sale → asli KOT batch → asli reminder → asli payload.
     *
     * ⚠️ Reminder ki apni mapping chahiye (`print_role = reminder`). Shuru me maine socha tha
     * ke KOT wali mapping kaafi hai — harness ne khali jobs laut kar bata diya ke nahi. Ye
     * mapping yahin banayi ja rahi hai, `setUp()` me nahi, taake baaqi tests jyun ke tyun rahein.
     */
    private function reminderPayload(): string
    {
        $printerId = DB::connection('tenant')->table('printers')->value('id');

        // Reminder ke do taqaze hain, aur DONO ka na hona khamoshi se khali array deta hai:
        //   1. `print_role = reminder` wali category mapping
        //   2. printer par `supports_reminder = 1`  (PrintJobService:442)
        // Doosri shart mujhe harness ne batayi — bina us ke guard "khali array" par girta raha.
        DB::connection('tenant')->table('printers')->where('id', $printerId)
            ->update(['supports_reminder' => 1]);

        DB::connection('tenant')->table('category_printer_mappings')->updateOrInsert(
            [
                'branch_id' => $this->branchId, 'category_id' => $this->categoryId,
                'print_role' => 'reminder', 'order_type' => 'all',
            ],
            ['printer_id' => $printerId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );

        $sale = SalesOrder::findOrFail($this->makeMixedSale('held'));
        app(PrintJobService::class)->queueKot($sale);

        $batch = \App\Models\Tenant\KotBatch::where('sales_order_id', $sale->id)->latest('id')->firstOrFail();

        // ⚠️ PrintJobService ko SEEDHA bulaya ja raha hai, KotCancellationService ke zariye nahi.
        // Wo wrapper har exception nigal kar khali array laut-ta hai (Log::warning) — jo prod ke
        // liye durust hai (reminder na bane to Cancel KOT na ruke), magar test me wo asal wajah
        // chhupa leta hai aur guard "khali array" par girta rehta hai bina bataye kyun.
        // ⚠️ TERMINAL dena LAZMI hai, aur `$wholeOrder` FALSE. `true` har line ko sifar kar deta hai
        // method ka apna comment kehta hai: "a WHOLE-order cancellation zeroes every line, so
        // routing against $effective finds no active line and returns nothing — the route is
        // resolved from the order's real lines instead" jab terminal maloom ho
        // (RECALL-REPRINT-TERMINAL-2). Bina terminal ke guard khamoshi se khali array par girta
        // raha — code ka keera nahi, mera harness adhoora tha.
        $terminalId = $this->makeTerminal($this->branchId);

        $jobs = app(PrintJobService::class)
            ->queueCancellationReminders($sale->fresh(["lines"]), $batch, false, (string) $terminalId);

        $this->assertNotEmpty($jobs, 'reminder job banna chahiye — warna neeche ka har assert bemani hai');

        return collect($jobs)->pluck('raw_payload')->implode("\n");
    }
}
