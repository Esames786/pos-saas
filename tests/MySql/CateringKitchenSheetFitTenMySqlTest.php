<?php

namespace Tests\MySql;

use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\CateringMaterialRate;
use App\Services\Catering\CateringEstimateService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * KITCHEN-SHEET-FIT-10-1 (26 Nov) — malik: "10 item k bad address wala footer
 * area agae."
 *
 * Asli parcha (VALIMA, 10 khane, teen lambe naam do satron me) chhapa to paer
 * — waqt, venue, pata — doosre safhe par chala gaya aur staff ne pehle safhe
 * par haath se likha. Wajah naapi gayi: Urdu dish 24px par line-height 1.7
 * = har satar ~10.8mm, aur 42% ke Item khaane me lambe naam toot kar do satrein
 * lete the.
 *
 * Ilaj (malik ne "A" chuna): font 24px HI, dish ki satron ka faasla 1.5, aur
 * Item ka khaana 48% (Instructions 19 -> 13%). Is file ki jaanchein:
 *   • naye adad CSS/markup me mojood hain, aur faasla 1.5 se NEECHE nahi
 *     jata (Nastaliq ke neeche wale harf — ے، ں — takraane lagte hain);
 *   • asli shakl ka 10 khanon wala parcha kuch kho nahi raha: har khana,
 *     har checkbox, waqt, venue aur pata HTML me hai.
 *
 * Chrome wali asal naap (safhe gin-na) is test me nahi hoti — Chrome aur
 * Jameel Noori Nastaleeq CI par nahi. Tareeqa: KITCHEN_SHEET_DUMP=<path.html>
 * de kar ye test chalao, phir
 *   chrome --headless=new --no-pdf-header-footer --print-to-pdf=out.pdf file:///<path.html>
 * aur out.pdf me `/Count N` parho.
 */
class CateringKitchenSheetFitTenMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    /** @var array<string,int> */
    private array $units = [];

    /** Wohi 10 khane, wohi tarteeb aur miqdaar jo malik ke bheje parche par thi. */
    private const DISHES = [
        ['Hot and Sour Soup', 'ہاٹ اور ساؤر سوپ', 130, 'PH', null, 0],
        ['Biryani Masala Chicken', 'بریانی مصالحہ چکن', 10, 'KG', null, 15],
        ['Beef Qorma Badami', 'بیف قورمہ بادامی', 15, 'KG', null, 15],
        ['Chicken Chanp Grill', 'چکن چانپ گرل', 30, 'KG', null, 30],
        ['Lahori Fish', 'لاہوری فش', 22, 'KG', 'Summai', 0],
        ['Halwa Gajar Desi Ghee Special', 'حلوہ گاجر دیسی گھی اسپیشل', 17, 'KG', null, 0],
        ['Nan Milky', 'نان ملکی', 5, 'KG', null, 0],
        ['Agra Shahi', 'آگرہ شاہی', 10, 'KG', null, 0],
        ['Raita', 'رائتہ', 3, 'KG', null, 0],
        ['Salad Plater Green', 'سلاد پلیٹر گرین', 1, 'PCS', null, 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_estimate_line_cost_blocks',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'units', 'products', 'categories', 'customers', 'branches',
        ]);

        $this->branchId = $this->makeBranch();
        foreach (['KG' => 'weight', 'PH' => 'quantity', 'PCS' => 'quantity'] as $code => $type) {
            $this->units[$code] = $this->tenant()->table('units')->insertGetId([
                'code' => $code, 'name' => $code, 'unit_type' => $type,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_the_dish_rows_are_tighter_but_not_below_what_nastaliq_needs(): void
    {
        $css = view('tenant.catering.documents.partials.kitchen-sheet-style', ['isUr' => true])->render();

        // Probe pehle khud ko zinda sabit kare: qaida mile, warna neeche ki
        // "kam se kam" jaanch khali matn par bhi pass ho jati.
        $this->assertSame(1, preg_match('/\.item-ur\s*\{([^}]*)\}/', $css, $rule),
            'dish ke Urdu naam ka qaida CSS me milna chahiye');
        $this->assertStringContainsString('font-size: 24px', $rule[1],
            'font 24px hi rehna hai — malik ka 29 Sep ka faisla; jagah faasle se bachi, harf se nahi');
        $this->assertSame(1, preg_match('/line-height:\s*([\d.]+)/', $rule[1], $m),
            'dish ki satron ka faasla isi qaide me likha ho');
        $this->assertLessThan(1.7, (float) $m[1],
            'faasla wapas 1.7 par gaya — 10 khanon ka paer phir doosre safhe par jayega');
        $this->assertGreaterThanOrEqual(1.5, (float) $m[1],
            '1.5 se kam par Nastaliq ke neeche wale harf agli satar se takraate hain');
    }

    public function test_the_item_column_is_wider_and_the_columns_still_add_up(): void
    {
        $html = $this->sheetHtml('ur');

        $this->assertSame(1, preg_match('/<table class="items">\s*<thead>(.*?)<\/thead>/s', $html, $head),
            'khanon ka sar milna chahiye');
        preg_match_all('/style="width:\s*(\d+)%;"/', $head[1], $w);

        $this->assertSame(['5', '16', '48', '13', '13', '5'], $w[1],
            'tarteeb: # · miqdaar · ITEM 48 · hidayat 13 · maal · checkbox');
        $this->assertSame(100, array_sum(array_map('intval', $w[1])), 'chaurai jama 100 ho');
    }

    public function test_the_ten_dish_sheet_keeps_every_dish_and_the_whole_footer(): void
    {
        $html = $this->sheetHtml('ur');

        if ($dump = env('KITCHEN_SHEET_DUMP')) {
            file_put_contents($dump, $html);
        }

        foreach (self::DISHES as [$en, $ur]) {
            $this->assertStringContainsString($ur, $html, "khana '{$en}' parche par hona chahiye");
        }
        $this->assertSame(10, substr_count($html, '☐'), 'har khane ka apna checkbox');
        $this->assertStringContainsString('9:00 PM', $html, 'paer ka waqt');
        $this->assertStringContainsString('Sample Hall', $html, 'paer ka venue');
        $this->assertStringContainsString('Block 10, Sample Road', $html, 'paer ka pata');
        $this->assertStringContainsString('Summai', $html, 'hidayat tang khaane me bhi chhapti hai');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function sheetHtml(string $lang): string
    {
        return view('tenant.catering.documents.kitchen-sheet', [
            'release' => $this->release(),
            'lang' => $lang,
            'pdf' => true,
            'businessName' => 'Sample Kitchen',
        ])->render();
    }

    /** Asli raasta: quotation banao, bhejo, qubool karo, confirm karo, release karo. */
    private function release(): \App\Models\Tenant\CateringProductionRelease
    {
        static $run = 0;
        $run++;

        $categoryId = $this->makeCategory(['name' => "MENU {$run}", 'sort_order' => 1]);
        $lines = [];
        foreach (self::DISHES as $i => [$en, $ur, $qty, $unit, $note]) {
            $pid = $this->makeProduct($categoryId, [
                'name' => $en, 'sku' => "F{$run}-{$i}", 'unit_id' => $this->units[$unit],
            ]);
            CateringMaterialRate::create([
                'product_id' => $pid, 'rate' => 100, 'unit_id' => $this->units[$unit],
                'effective_from' => now()->subMonth()->toDateString(),
            ]);
            $lines[] = [
                'product_id' => $pid, 'item_name' => $en, 'item_name_ur' => $ur,
                'quantity' => $qty, 'unit_id' => $this->units[$unit], 'unit_code' => $unit,
                'rate' => 500, 'instructions' => $note,
            ];
        }

        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. SAMPLE GUEST',
            'customer_phone' => '03000000000',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(),
            'event_type' => 'VALIMA',
            'pax' => 400,
            'venue' => 'Sample Hall',
            'customer_address' => 'Block 10, Sample Road',
            'dispatch_time' => '21:00:00',
        ]);

        $estimate = $event->currentEstimate;
        $estimates->saveDraftLines($estimate, $lines);

        // Party ka maal teen khanon par — asli parche par bhi teen par tha. Is ke
        // baghair kaala dabba banta hi nahi, aur us ki bulandi naap me nahi aati.
        foreach ($estimate->refresh()->lines as $k => $line) {
            $partyKg = self::DISHES[$k][5] ?? 0;
            if ($partyKg <= 0) {
                continue;
            }
            \App\Models\Tenant\CateringEstimateLineCostBlock::create([
                'catering_estimate_line_id' => $line->id,
                'label' => "Maal {$k}", 'material_name' => "Maal {$k}", 'unit_code' => 'KG',
                'block_type' => \App\Models\Tenant\CateringProductCostBlock::TYPE_MATERIAL,
                'charge_basis' => \App\Models\Tenant\CateringProductCostBlock::BASIS_PER_UNIT,
                'rate_basis' => \App\Models\Tenant\CateringProductCostBlock::RATE_PER_MATERIAL_UNIT,
                'rate' => 120, 'quantity_per_unit' => 1,
                'default_material_qty' => $partyKg, 'event_material_qty' => $partyKg,
                'is_customer_supplied' => true, 'amount' => 0, 'sort_order' => 1,
            ]);
        }
        $estimates->markSent($estimate->refresh());
        $estimates->markAccepted($estimate->refresh());
        $estimates->confirmEvent($event->refresh());

        $release = app(\App\Services\Catering\CateringProductionReleaseService::class)
            ->release(CateringEvent::find($event->id));

        return $release->load(['lines', 'event']);
    }
}
