<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringCustomerBalanceService;
use App\Services\Catering\CateringEstimateService;
use App\Support\Catering\EventDateWindow;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-BALANCES-DATE-FILTER-1 — 6 October.
 *
 * Malik ne screen par nishan laga kar likha: "add dates here — From / TO".
 *
 * ── IS KAAM KI DO ASAL BAATEIN ─────────────────────────────────────────────
 *
 * 1. Muddat EVENT KI TAREEKH par lagti hai, PAISA AANE ki tareekh par nahi.
 *    Ye farq bemani nahi: October ki booking ka advance September me aa chuka
 *    ho sakta hai. Agar "Received" ko bhi muddat me band kar diya jata to ek
 *    poori bhari hui booking adhi bhari dikhne lagti — aur yehi wo screen hai
 *    jahan se log paisa lete aur refund karte hain.
 *
 * 2. Ulti likhi hui muddat (From 30 Oct, To 1 Oct) ka seedha natija ek KHALI
 *    fehrist hai. Wo jawab jhoota nahi magar gumraah karta hai: parhne wala
 *    samajhta hai ke us arse me kaam hi nahi hua. Is liye sire badal diye
 *    jate hain.
 *
 * Aur qaida EK jagah hai: `EventDateWindow`. Pehle ye `CateringEventController`
 * ke andar private tha; yahan naql kar lena aasan tha, aur wohi ghalti is
 * module me pehle ho chuki hai.
 */
class CateringBalancesDateFilterMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringCustomerBalanceService $balances;

    private int $branchId;

    private int $productId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_production_release_lines', 'catering_production_releases',
            'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'catering_product_cost_blocks', 'catering_product_profiles',
            'journal_lines', 'journal_entries',
            'cash_bank_account_transactions', 'cash_bank_accounts', 'accounts', 'payment_methods',
            'customers', 'product_translations', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->balances = app(CateringCustomerBalanceService::class);
        $this->branchId = $this->makeBranch();

        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'KG', 'name' => 'Kilogram', 'unit_type' => 'weight',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'RICE']), [
            'name' => 'Biryani', 'sku' => 'DF1', 'unit_id' => $unitId, 'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 900000, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashAccountId]);
    }

    /** Ek booking, di hui tareekh par, diye hue total ki. */
    private function booking(string $date, float $total, ?string $phone = '0300-1112223'): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'MR. WINDOW',
            'customer_phone' => $phone,
            'booking_date' => now()->subMonths(2)->toDateString(),
            'event_date' => $date,
            'pax' => 50,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Biryani', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->markAccepted($event->currentEstimate->refresh());

        return $event->refresh();
    }

    /**
     * Graahak ko PHONE SE nahi dhoonda ja raha.
     *
     * System likhte waqt number ko apni shakl deta hai (`CustomerDirectory::
     * normalizePhone`), is liye jo matn booking me bheja tha wo DB me waisa
     * para hi nahi hota. Us shakl ka andaza lagana test ko ek aise qaide se
     * baandh deta jo is test ka maudu hai hi nahi — aur jis din wo qaida
     * badalta, ye test bina kisi asal kharabi ke girta.
     */
    private function theCustomer(): Customer
    {
        $customers = Customer::on('tenant')->get();
        $this->assertCount(1, $customers, 'in teston me ek hi graahak banta hai');

        return $customers->first();
    }

    private function rowFor(?string $from = null, ?string $to = null): ?array
    {
        return $this->balances->rows(null, null, [], $from, $to)
            ->firstWhere('customer_id', $this->theCustomer()->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Muddat waqai chhanti hai — aur PAISA bhi us ke saath chhanta hai.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_window_counts_only_bookings_whose_event_date_falls_inside(): void
    {
        $inside = now()->startOfMonth()->addDays(10);
        $outside = now()->startOfMonth()->subMonths(3)->addDays(4);

        $this->booking($inside->toDateString(), 40000);
        $this->booking($outside->toDateString(), 70000);

        // Bina muddat ke: dono.
        $all = $this->rowFor();
        $this->assertNotNull($all, 'graahak milna chahiye — warna neeche ki jaanch bemani hai');
        $this->assertSame(2, $all['events']);
        $this->assertEqualsWithDelta(110000.0, $all['billed'], 0.01);

        // Muddat ke andar: sirf ek — aur Billed bhi usi ka.
        $windowed = $this->rowFor(
            $inside->copy()->startOfMonth()->toDateString(),
            $inside->copy()->endOfMonth()->toDateString(),
        );
        $this->assertNotNull($windowed);
        $this->assertSame(1, $windowed['events'], 'sirf us mahine ki booking');
        $this->assertEqualsWithDelta(40000.0, $windowed['billed'], 0.01,
            'adad bhi chhant-ne chahiyen — warna screen ek rakam ke saamne doosri likh degi');
        $this->assertEqualsWithDelta(40000.0, $windowed['balance'], 0.01);
    }

    /**
     * SAB SE BAREEK BAAT: paisa muddat se BAHAR aaya ho, phir bhi gina jaye.
     *
     * Advance do mahine pehle liya gaya, booking is mahine ki hai. Agar
     * "Received" ko bhi muddat me band kar diya jata to ye poori bhari hui
     * booking 40,000 baqi dikhati — aur koi doosri baar paisa maang leta.
     */
    public function test_received_counts_money_taken_outside_the_window(): void
    {
        $eventDate = now()->startOfMonth()->addDays(10);
        $event = $this->booking($eventDate->toDateString(), 40000);

        app(CateringAdvanceService::class)->record($event, [
            'amount' => 40000,
            'payment_method_id' => $this->paymentMethodId,
            // Muddat se BAHAR — aur jaan-boojh kar.
            'received_date' => $eventDate->copy()->subMonths(2)->toDateString(),
        ]);

        $row = $this->rowFor(
            $eventDate->copy()->startOfMonth()->toDateString(),
            $eventDate->copy()->endOfMonth()->toDateString(),
        );

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(40000.0, $row['received'], 0.01,
            'paisa muddat se bahar aaya tha — magar wo IN bookings ka hai, is liye ginna chahiye');
        $this->assertEqualsWithDelta(0.0, $row['balance'], 0.01,
            'aur booking poori bhari hui hai — warna koi dobara paisa maang lega');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Muddat ka qaida khud.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_backwards_window_is_straightened_rather_than_returning_nothing(): void
    {
        $this->assertSame(
            ['2026-10-01', '2026-10-30'],
            EventDateWindow::window('2026-10-30', '2026-10-01'),
            'ulti muddat seedhi honi chahiye — warna jawab ek khali fehrist hai jo kehti hai kaam hi nahi hua'
        );

        // Aur seedhi muddat chhedi na jaye.
        $this->assertSame(
            ['2026-10-01', '2026-10-30'],
            EventDateWindow::window('2026-10-01', '2026-10-30')
        );
    }

    public function test_a_date_that_is_not_a_date_is_ignored_not_guessed(): void
    {
        foreach (['', '   ', 'yesterday', '2026-13-45', '01/10/2026', 'DROP TABLE', '2026-10'] as $junk) {
            $this->assertNull(EventDateWindow::parse($junk),
                "[{$junk}] ko tareekh nahi mana jana chahiye — na-samajh matn ko aaj maan lena ek aisi muddat bana deta hai jo kisi ne maangi hi nahi");
        }

        // Aur probe zinda hai: sahi tareekh waqai guzarti hai.
        $this->assertSame('2026-10-06', EventDateWindow::parse('2026-10-06'));
    }

    /** Aik sira khula bhi chalta hai. */
    public function test_one_open_end_still_filters(): void
    {
        $old = now()->startOfMonth()->subMonths(4);
        $new = now()->startOfMonth()->addDays(3);
        $this->booking($old->toDateString(), 10000);
        $this->booking($new->toDateString(), 25000);

        $onlyNew = $this->rowFor($new->copy()->subDay()->toDateString(), null);
        $this->assertSame(1, $onlyNew['events'], 'sirf From diya ho to us ke baad wali');
        $this->assertEqualsWithDelta(25000.0, $onlyNew['billed'], 0.01);

        $onlyOld = $this->rowFor(null, $old->copy()->addDay()->toDateString());
        $this->assertSame(1, $onlyOld['events'], 'sirf To diya ho to us se pehle wali');
        $this->assertEqualsWithDelta(10000.0, $onlyOld['billed'], 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Filter dono screenon par ek jaisa chale.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_window_carries_into_the_customer_own_page(): void
    {
        $inside = now()->startOfMonth()->addDays(8);
        $this->booking($inside->toDateString(), 40000);
        $this->booking($inside->copy()->subMonths(3)->toDateString(), 70000);

        $customer = $this->theCustomer();

        $full = $this->balances->forCustomer($customer);
        $this->assertCount(2, $full['events'], 'bina muddat ke dono bookings');

        $windowed = $this->balances->forCustomer(
            $customer, null, [],
            $inside->copy()->startOfMonth()->toDateString(),
            $inside->copy()->endOfMonth()->toDateString(),
        );
        $this->assertCount(1, $windowed['events'],
            'fehrist par 1 dekh kar click kiya to andar bhi 1 milni chahiye — warna screen apni hi pichhli satar ko jhutlati hai');
        $this->assertEqualsWithDelta(40000.0, $windowed['totals']['billed'], 0.01);
    }

    /** Upar wala banner bhi usi muddat ka ho. */
    public function test_the_unlinked_banner_respects_the_window(): void
    {
        $inside = now()->startOfMonth()->addDays(6);

        // Bina phone ke do bookings — yani kisi graahak se juri hui nahi.
        $this->booking($inside->toDateString(), 15000, null);
        $this->booking($inside->copy()->subMonths(5)->toDateString(), 90000, null);

        $all = $this->balances->unlinked();
        $this->assertSame(2, $all['count'], 'bina muddat ke dono');

        $windowed = $this->balances->unlinked(null, [],
            $inside->copy()->startOfMonth()->toDateString(),
            $inside->copy()->endOfMonth()->toDateString(),
        );
        $this->assertSame(1, $windowed['count'],
            'banner bhi muddat maane — warna upar ka adad neeche ki fehrist ko jhutlata hai');
        $this->assertEqualsWithDelta(15000.0, $windowed['balance'], 0.01);
    }

    /**
     * Aur screen ye BATAYE ke muddat kis tareekh par lagi hai.
     *
     * Ye sirf alfaz ki jaanch nahi: "Received" ko muddat me band samajh lena
     * is screen par sab se mehngi ghalat-fehmi hai, aur us se bachne ka
     * raasta sirf likh dena hai.
     */
    public function test_the_screen_says_what_the_window_is_measured_on(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/catering/customer-balances/index.blade.php'));

        $this->assertStringContainsString('name="from"', $blade, 'From ka khaana');
        $this->assertStringContainsString('name="to"', $blade, 'To ka khaana');
        $this->assertStringContainsString('Event from', $blade,
            'label se pata chale ke muddat EVENT ki tareekh par hai');
        $this->assertStringContainsString('event date', $blade, 'banner bhi yehi kahe');
        $this->assertStringContainsString('whenever it was taken', $blade,
            'aur ye bhi ke Received muddat se bandha hua NAHI hai');

        // Filter lagte hi banner nikle — chahe sirf tareekh lagi ho, status na ho.
        $this->assertStringContainsString('$status !== \'\' || $from || $to', $blade,
            'sirf tareekh par bhi banner aaye — warna adad chup chaap adhure ho jate hain');
    }
}
