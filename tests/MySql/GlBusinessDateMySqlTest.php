<?php

namespace Tests\MySql;

use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * GL-BUSINESS-DATE-1 — khaata usi din par baithe jis din par report baithti hai.
 *
 * GL apni tareekh `sale_date` ke DIN se banata tha. Aaj tak wo business date ke barabar nikla —
 * 2,713 entries me se sifar ka farq — magar sirf ITTEFAQ se: waqt UTC me rakha jaata hai, Karachi
 * UTC se +5 hai, aur restaurant ka poora din (dopehar 12 se raat 3) ek hi UTC tareekh me aa jaata
 * hai.
 *
 * Ye test wohi raat banata hai jab ittefaq TOOT-ta hai: shift subah 5 baje (Karachi) se aage chal
 * rahi ho, yani UTC ki tareekh badal chuki ho jabke business date wohi purana ho. Purane code par
 * ye test RED hai.
 */
class GlBusinessDateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'journal_lines', 'journal_entries', 'accounts', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'shifts', 'terminals', 'products', 'categories', 'branches', 'users',
        ]);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->userId = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
    }

    /**
     * Ek bill jiska business date 5 tareekh hai, magar jo UTC ke 6 tareekh wale lamhe par bana.
     * Karachi me ye subah 5 baje ke baad hai — yani wo raat jab shift abhi tak khuli hai.
     */
    private function lateNightSale(): SalesOrder
    {
        $id = $this->makeSale($this->branchId, [
            'status'        => 'paid',
            'order_type'    => 'takeaway',
            'grand_total'   => 1000,
            'business_date' => '2026-09-05',
            // 2026-09-06 00:30 UTC = 2026-09-06 05:30 Karachi — shift abhi 5 tareekh wali hai.
            'sale_date'     => '2026-09-06 00:30:00',
        ]);

        return SalesOrder::on('tenant')->findOrFail($id);
    }

    /** ASAL BAAT: GL business date par baithe, ghadi ke din par nahi. */
    public function test_a_paid_sale_books_on_its_business_date(): void
    {
        $sale = $this->lateNightSale();

        app(JournalPostingService::class)->postPaidSale($sale, $this->userId);

        $entry = JournalEntry::on('tenant')->where('source_type', 'sales_order_paid')
            ->where('source_id', $sale->id)->firstOrFail();

        $this->assertSame('2026-09-05', $entry->entry_date->toDateString(),
            'GL usi din par baithna chahiye jis din par report baithti hai — sale_date ke din par nahi');
        $this->assertNotSame('2026-09-06', $entry->entry_date->toDateString(),
            'ghadi ka din GL ki tareekh nahi hai');
    }

    /**
     * Jis purani row par business_date na ho, uska bartaao HU-BA-HU wohi rahe jo pehle tha.
     * Finance ka code hai: nayi soorat sirf wahan chale jahan nayi maloomat mojood ho.
     */
    public function test_a_row_without_a_business_date_behaves_exactly_as_before(): void
    {
        $sale = $this->lateNightSale();
        DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)
            ->update(['business_date' => null]);

        app(JournalPostingService::class)->postPaidSale($sale->fresh(), $this->userId);

        $entry = JournalEntry::on('tenant')->where('source_type', 'sales_order_paid')
            ->where('source_id', $sale->id)->firstOrFail();

        $this->assertSame('2026-09-06', $entry->entry_date->toDateString(),
            'business_date na ho to purana fallback chale — warna hum ne chupke se bartaao badal diya');
    }
}
