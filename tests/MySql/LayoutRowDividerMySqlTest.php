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
    // KOT-HEADING-STARS-1 — `*** KOT #1 ***` ke sitare, per-branch switch ke peeche
    //
    // Client ne parchi par likha tha "star remove". Ye sitare
    // EscPosPayloadService::kot() me likhe hue hain, yani wohi code chaaron chalti hui
    // businesses ki parchi chhapta hai. Is liye hataye NAHI — switch ke peeche rakhe,
    // default = aaj wali soorat.
    //
    // Doc: docs/plans/kot-heading-stars-toggle-2026-09-26.md
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * 🚨 SAB SE AHEM GUARD — deploy ke din kisi ki parchi na badle.
     *
     * Switch chhue baghair (jaisa har mojooda tenant par hoga) sitare wahin rehne chahiyen.
     * Ye RED ho to matlab chaar chalti hui businesses ki parchi chup-chaap badal di gayi.
     */
    public function test_stars_stay_by_default_so_no_tenant_ticket_changes_on_deploy(): void
    {
        $this->setLayout('kot', ['kot_font_size' => 18]);   // switch jaan-boojh kar set nahi kiya

        $payload = $this->kotPayload();

        $this->assertStringContainsString('*** KOT #1 ***', $payload,
            'default par KOT heading ke sitare qaayam rehne chahiyen');
        $this->assertStringContainsString('** DINE IN **', $payload,
            'default par order type ke sitare bhi qaayam');
    }

    /** Switch band — sitare jayen, magar naam aur number bilkul wahi rahein. */
    public function test_turning_the_switch_off_drops_the_stars_and_nothing_else(): void
    {
        $this->setLayout('kot', ['kot_font_size' => 18, 'show_heading_stars' => 0]);

        $payload = $this->kotPayload();

        $this->assertStringNotContainsString('*** KOT', $payload, 'KOT heading ke sitare jane chahiyen');
        $this->assertStringContainsString('KOT #1', $payload, 'heading ka naam aur number qaayam');
        $this->assertStringContainsString('DINE IN', $payload, 'order type qaayam');
        $this->assertStringNotContainsString('** DINE IN **', $payload, 'order type ke sitare bhi jayen');
    }

    /**
     * CANCEL / ADDITION / DUPLICATE — teenon variants par wohi qaida.
     *
     * Ye alag satarein hain (`match ($eventType)`), is liye inhen bhool jane ka poora mauqa tha.
     */
    public function test_the_switch_covers_cancel_addition_and_duplicate_headings(): void
    {
        $src = file_get_contents(app_path('Services/Printing/EscPosPayloadService.php'));

        $at = strpos($src, '$kotLabel = match ($eventType)');
        $this->assertNotFalse($at, 'KOT heading ka match block milna chahiye');
        $block = substr($src, $at, 400);

        foreach (['CANCEL KOT #', 'ADDITION KOT #', 'DUPLICATE KOT #', 'KOT #'] as $label) {
            $this->assertStringContainsString($label, $block, "[{$label}] heading me hona chahiye");
        }
        $this->assertStringNotContainsString('*** CANCEL KOT', $block,
            'sitare label me hard-code nahi hone chahiyen, warna switch un par lagta hi nahi');
    }

    /**
     * Receipt alag document hai — KOT ka switch us par asar na dale.
     *
     * Receipt ka apna `** ORDER TYPE **` hai (`receipt()` me), aur us ki apni layout row.
     * KOT ki row par switch band karne se wo chhuna nahi chahiye.
     */
    public function test_receipt_keeps_its_own_stars_when_the_kot_switch_is_off(): void
    {
        $this->setLayout('kot', ['show_heading_stars' => 0]);
        $this->setLayout('receipt', ['font_size' => 15]);

        $job = app(PrintJobService::class)->queueReceipt(SalesOrder::findOrFail($this->makeMixedSale('paid')));

        $this->assertStringContainsString('** DINE IN **', (string) $job->raw_payload,
            'receipt ka order type KOT ke switch se nahi badalna chahiye');
    }

    /**
     * Har branch apni qeemat rakhe — ek branch ka switch doosri par na chale.
     *
     * Tawakal par do branch hain aur har ek ki apni layout row — ye asli soorat hai, farzi nahi.
     */
    public function test_each_branch_keeps_its_own_switch(): void
    {
        $doosri = $this->makeBranch(['name' => 'Doosri Branch']);

        $this->setLayout('kot', ['show_heading_stars' => 0]);      // is branch par band
        DB::connection('tenant')->table('receipt_layout_settings')->updateOrInsert(
            ['branch_id' => $doosri, 'document_type' => 'kot'],
            ['paper_size' => '80mm', 'is_active' => 1, 'show_heading_stars' => 1,
             'created_at' => now(), 'updated_at' => now()],
        );

        $rows = DB::connection('tenant')->table('receipt_layout_settings')
            ->where('document_type', 'kot')->pluck('show_heading_stars', 'branch_id');

        $this->assertSame(0, (int) $rows[$this->branchId], 'pehli branch par band rehna chahiye');
        $this->assertSame(1, (int) $rows[$doosri], 'doosri branch par chalu rehna chahiye');
    }

    /**
     * 🚨 LINKAGE — operator tak raasta maujood ho.
     *
     * Column ban jaye, thermal maan le, magar Edit Layout par toggle na ho — to setting ka
     * koi faida hi nahi. Teenon jagah ek saath.
     */
    public function test_the_operator_can_actually_reach_this_setting(): void
    {
        $this->assertContains('show_heading_stars',
            \App\Models\Tenant\ReceiptLayoutSetting::TOGGLE_FIELDS,
            'TOGGLE_FIELDS me na ho to controller ise boolean me badalta hi nahi');

        $this->assertStringContainsString("'show_heading_stars'",
            file_get_contents(resource_path('views/tenant/printing/layouts/_form.blade.php')),
            'Edit Layout ke form par toggle hona chahiye');

        $this->assertStringContainsString("'show_heading_stars'",
            file_get_contents(app_path('Http/Controllers/Tenant/ReceiptLayoutController.php')),
            'controller ki validation me hona chahiye, warna field chup-chaap gir jata hai');
    }

    /** Browser KOT bhi usi switch par chale — preview aur kaghaz ek baat kahein. */
    public function test_the_browser_kot_follows_the_same_switch(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/printing/documents/kot.blade.php'));

        $this->assertStringContainsString('show_heading_stars', $blade,
            'browser KOT bhi wohi switch parhe, warna preview aur parchi alag bolenge');
        $this->assertStringNotContainsString('<div class="center big">** {{ strtoupper', $blade,
            'order type ke sitare ab shart ke peeche hone chahiyen, hard-coded nahi');
    }

    /** Wohi ek KOT payload, jis par upar ke guards assert karte hain. */
    private function kotPayload(): string
    {
        $jobs = app(PrintJobService::class)->queueKot(SalesOrder::findOrFail($this->makeMixedSale('held')));

        return collect($jobs)->pluck('raw_payload')->implode("\n");
    }
}
