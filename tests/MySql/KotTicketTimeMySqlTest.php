<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Printing\EscPosPayloadService;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * KOT-TIME-TRUTH-1 — KOT par ORDER ka waqt chhapta hai, chhapne ka nahi.
 *
 * Dono raaste seedha now() likhte the, is liye saat din purani KOT reprint karne par aaj ki
 * tareekh chhapti thi: kitchen ke liye ek purani parchi taaza dikhti thi. Owner ne yehi pakra.
 *
 * Ye guard DONO raaston par chalta hai — printer ke bytes AUR preview ka safha. Pichli baar
 * guard sirf ek raasta parhta tha aur doosra chupke se kharab reh gaya tha.
 */
class KotTicketTimeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $terminalId;
    private int $printerId;
    private int $productId;
    private int $secondProductId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors',
            'combo_components', 'combos', 'category_printer_mappings', 'printers',
            'products', 'categories', 'terminals', 'branches', 'users',
        ]);

        $userId = $this->makeUser(['employee_code' => 'KT' . Str::random(4)]);
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');

        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->printerId = $this->makePrinter([
            'code' => 'KF-P-BBQ', 'print_role' => 'both', 'branch_id' => $this->branchId,
            'supports_reminder' => 1,
        ]);

        $category = $this->makeCategory(['name' => 'Bar-B-Que', 'slug' => 'bbq-' . Str::random(4)]);
        foreach (['kot', 'reminder'] as $role) {
            DB::connection('tenant')->table('category_printer_mappings')->insert([
                'branch_id' => $this->branchId, 'category_id' => $category,
                'printer_id' => $this->printerId, 'print_role' => $role,
                'order_type' => 'all', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->productId = $this->makeProduct($category, ['name' => 'Chicken Tikka (Chest)']);
        $this->secondProductId = $this->makeProduct($category, ['name' => 'Chicken Baluchi Boti']);
    }

    private function slip(PrintJob $job): string
    {
        $raw = app(EscPosPayloadService::class)->build($job);
        $t = preg_replace('/\x1b@|\x1d\x56[\x00-\xff]{1,2}|\x1b[!aEMdG][\x00-\xff]?|\x1d[!Bh][\x00-\xff]?|\x1b[23][\x00-\xff]?/', '', $raw);

        return trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $t));
    }

    /** Everything between the QTY ITEM heading and the closing rule. */
    private function itemsOn(PrintJob $job): string
    {
        preg_match('/QTY ITEM\s*-+\s*(.*?)\s*-+\s*$/s', $this->slip($job), $m);

        return trim($m[1] ?? '');
    }

    /** The POS rewrites the whole line set on every save, so every id changes. */
    private function saveTheBill(int $saleId): void
    {
        $rows = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->get();
        DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)->delete();
        foreach ($rows as $row) {
            $data = (array) $row;
            unset($data['id']);
            DB::connection('tenant')->table('sales_order_lines')->insert($data);
        }
    }

    private function heldOrderWithTwoItems(): SalesOrder
    {
        $saleId = $this->makeSale($this->branchId, [
            'status' => 'held', 'order_type' => 'dine_in', 'terminal_id' => $this->terminalId,
        ]);
        $this->makeSaleLine($saleId, $this->productId, [
            'product_name' => 'Chicken Tikka (Chest)', 'quantity' => 1, 'kot_sent_quantity' => 0,
        ]);
        $this->makeSaleLine($saleId, $this->secondProductId, [
            'product_name' => 'Chicken Baluchi Boti', 'quantity' => 2, 'kot_sent_quantity' => 0,
        ]);

        return SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
    }


    /** Round ko peeche le jao — bilkul aisa jaisa teen din purana order hota. */
    private function backdate(int $saleId, string $when): void
    {
        DB::connection('tenant')->table('sales_orders')->where('id', $saleId)
            ->update(['created_at' => $when]);
        DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $saleId)
            ->update(['created_at' => $when]);
    }

    /**
     * Preview ka safha — wohi controller jo route chalata hai, sirf middleware ke bagair.
     * View ko haath se render karna ghalat hota: guard ko wohi data-tayyari parhni chahiye jo
     * asal me chalti hai, warna wo apna hi banaya hua safha check karta rahega.
     */
    private function previewHtml(PrintJob $job): string
    {
        return app(\App\Http\Controllers\Tenant\PrintDocumentController::class)
            ->preview($job)->render();
    }

    /**
     * ASAL KEERA: purani KOT reprint karo to parchi par usi round ka waqt aaye — aaj ka nahi.
     * Dono raaste barabar bolen.
     */
    public function test_a_reprint_carries_the_rounds_own_date_not_todays(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId);

        $threeDaysAgo = now()->subDays(3)->setTime(19, 40);
        $this->backdate($sale->id, $threeDaysAgo->toDateTimeString());

        $reprint = app(PrintJobService::class)->queueKot(
            sale: $sale->fresh('lines'), terminalId: (string) $this->terminalId, isReprint: true,
        )[0];

        $wanted = $threeDaysAgo->copy()->timezone(\App\Support\TenantClock::DEFAULT_TIMEZONE);
        $slip   = $this->slip($reprint);
        $html   = $this->previewHtml($reprint);

        foreach (['bytes' => $slip, 'preview' => $html] as $where => $out) {
            $this->assertStringContainsString('TIME: ' . $wanted->format('h:i A'), $out,
                "{$where}: parchi par us ROUND ka waqt hona chahiye");
            $this->assertStringContainsString($wanted->format('D d-M-Y'), $out,
                "{$where}: parchi par us ROUND ki tareekh honi chahiye");
            $this->assertStringNotContainsString(now()->timezone(\App\Support\TenantClock::DEFAULT_TIMEZONE)->format('D d-M-Y'), $out,
                "{$where}: aaj ki tareekh parchi par nahi aani chahiye — yehi asal keera tha");
        }
    }

    /** Duplicate par kitchen ko DONO waqt chahiye: khana kab manga, aur kaagaz kab nikla. */
    public function test_a_reprint_also_says_when_the_duplicate_came_out(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId);
        $this->backdate($sale->id, now()->subDays(3)->setTime(19, 40)->toDateTimeString());

        $reprint = app(PrintJobService::class)->queueKot(
            sale: $sale->fresh('lines'), terminalId: (string) $this->terminalId, isReprint: true,
        )[0];

        foreach (['bytes' => $this->slip($reprint), 'preview' => $this->previewHtml($reprint)] as $where => $out) {
            $this->assertStringContainsString('REPRINT: ', $out, "{$where}: duplicate par nikalne ka waqt bhi chahiye");
        }
    }

    /** Pehli parchi par REPRINT ki line nahi — wahan dono waqt ek hi hain. */
    public function test_the_first_ticket_carries_no_reprint_line(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        $job = app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId)[0];

        foreach (['bytes' => $this->slip($job), 'preview' => $this->previewHtml($job)] as $where => $out) {
            $this->assertStringNotContainsString('REPRINT:', $out, "{$where}: asli parchi par REPRINT ki line bekaar hai");
        }
    }
    /**
     * Ye blade TEEN jagah se render hota hai. Maine do sambhale aur teesri chhod di: Layout screen
     * ka live preview koi print job deta hi nahi — wo settings dikhata hai, kisi parchi ki nakal
     * nahi — aur safha 500 ho gaya: "Undefined variable $job". Owner ne screenshot bhej kar pakra.
     *
     * Guard usi ASLI raaste par hai (wohi controller method jo route chalata hai), mirror kiye
     * hue view par nahi — warna wo apna hi banaya hua safha parhta rehta.
     */
    public function test_the_layout_preview_renders_without_a_print_job(): void
    {
        $sale = $this->heldOrderWithTwoItems();
        app(PrintJobService::class)->queueKot(sale: $sale, terminalId: (string) $this->terminalId);

        $layout = \App\Models\Tenant\ReceiptLayoutSetting::on('tenant')->create([
            'branch_id' => $this->branchId, 'document_type' => 'kot',
            'paper_size' => '80mm', 'font_size' => 14, 'is_active' => true,
        ]);

        $html = app(\App\Http\Controllers\Tenant\ReceiptLayoutController::class)
            ->preview(new \Illuminate\Http\Request(), $layout)->render();

        $this->assertStringContainsString('TIME:', $html, 'layout preview par KOT ka waqt hona chahiye');
        $this->assertStringNotContainsString('Undefined variable', $html);
    }
}
