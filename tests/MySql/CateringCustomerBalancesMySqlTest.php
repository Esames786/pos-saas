<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\Customer;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringCustomerBalanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Catering\CateringFinancialPositionService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-CUSTOMER-BALANCES-1 — graahak ka hisaab, booking ke bahar se.
 *
 * SAB SE AHEM PEHRA is file me `test_the_module_agrees_with_the_booking_screen`
 * hai, aur wo is module ki poori wajah-e-wujood par khara hai.
 *
 * Is screen ki qeemat sirf ye hai ke us ke adad wohi hon jo booking ki screen
 * kehti hai. Agar wo kabhi alag ho gaye to ye screen ek DOOSRA sach bana degi,
 * aur do sach ek sach se hamesha bure hote hain: koi nahi jaanta kis par amal
 * karna hai.
 *
 * Aur ye koi farzi khatra nahi. 27 September ko theek yehi ho chuka hai — ek
 * hi sawal ("kitna baqi hai") ke chaar jawab chaar jagah likhe the, aur teen
 * purana khaana parhte the. Malik ko kaghaz par 38,000 dikha jab ke screen 0
 * keh rahi thi. Is liye ye test module ka total AUR `position()` ka jama,
 * dono alag alag nikaal kar milata hai.
 */
class CateringCustomerBalancesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

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
            'catering_refunds', 'catering_final_invoices', 'catering_advances',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'customers', 'units', 'products', 'categories', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder())->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->advances = app(CateringAdvanceService::class);
        $this->balances = app(CateringCustomerBalanceService::class);

        $this->branchId = $this->makeBranch();
        $unitId = $this->tenant()->table('units')->insertGetId([
            'code' => 'PKG', 'name' => 'Package', 'unit_type' => 'quantity',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'PACKAGES']), [
            'name' => 'Catering Package', 'sku' => 'CB1', 'unit_id' => $unitId,
            'default_purchase_price' => 400,
        ]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $this->productId, 'rate' => 400,
            'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cashBankId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::on('tenant')->where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $cashBankId]);
    }

    private function booking(string $name, string $phone, float $total): CateringEvent
    {
        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 100,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return $event->refresh();
    }

    /**
     * Quotation NEECHE karna — credit banne ka asli raasta.
     *
     * Pehli koshish me test ne graahak se bill se ZYADA le kar credit
     * banane ki koshish ki, aur system ne sahi rok diya: "taking more would
     * leave the business holding money it has not billed for". Credit us
     * tarah paida nahi hota; wo tab banta hai jab paisa aane ke BAAD sauda
     * chhota ho jaye — aur prod par Kashif ki apni booking par yehi hua tha.
     */
    private function requoteAt(CateringEvent $event, float $total): void
    {
        $revised = $this->estimates->revise($event->currentEstimate()->first());
        $this->estimates->saveDraftLines($revised, [
            ['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => $total],
        ]);
        $this->estimates->markSent($revised->refresh());
        $this->estimates->confirmEvent($event->refresh());
    }

    private function receive(CateringEvent $event, float $amount): void
    {
        $this->advances->record($event->refresh(), [
            'amount' => $amount,
            'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId,
            'reference' => 'R-'.$amount,
        ]);
    }

    /**
     * DO SACH NA BANEN. Module ka har adad booking ki screen ke barabar ho.
     *
     * Module jaan-boojh kar alag raasta chalata hai — saare events ek baar,
     * sums aggregate se, hisaab PHP me — kyunke fehrist par har booking par
     * `position()` bulana N+1 hai. Wo raasta tez hai; ye test us ki QEEMAT ka
     * pehra hai.
     */
    public function test_the_module_agrees_with_the_booking_screen(): void
    {
        $phone = '03001234567';
        $a = $this->booking('MR. REPEAT CUSTOMER', $phone, 100000);
        $this->receive($a, 40000);

        // Doosri booking usi phone par, poori ada aur invoice ke saath.
        $b = $this->booking('MR. REPEAT CUSTOMER', $phone, 38000);
        app(CateringFinalInvoiceService::class)->issue($b->refresh());
        $this->receive($b, 38000);

        // Teesri par paisa aane ke BAAD sauda chhota ho gaya — credit banta hai.
        $c = $this->booking('MR. REPEAT CUSTOMER', $phone, 25000);
        $this->receive($c, 25000);
        $this->requoteAt($c, 20000);

        $customer = Customer::on('tenant')->where('phone', $phone)->firstOrFail();
        $row = $this->balances->rows()->firstWhere('customer_id', $customer->id);

        $this->assertNotNull($row, 'graahak fehrist me hona chahiye — warna neeche ka milan bemani hai');
        $this->assertSame(3, $row['events'], 'teenon bookings ginni chahiyen');

        // Ab WOHI adad doosre raaste se — har booking par position(), alag se
        // jama. Ye jaan-boojh kar module ke code ko HAATH NAHI lagata.
        $position = app(CateringFinancialPositionService::class);
        $billed = $received = $balance = $credit = 0.0;
        foreach ([$a, $b, $c] as $event) {
            $p = $position->position($event->refresh());
            $billed += $p['billed'];
            $received += $p['net_received'];
            $balance += $p['balance_due'];
            $credit += $p['customer_credit'];
        }

        $this->assertSame(round($billed, 2), $row['billed'], 'billed alag nikla');
        $this->assertSame(round($received, 2), $row['received'], 'received alag nikla');
        $this->assertSame(round($balance, 2), $row['balance'], 'balance alag nikla');
        $this->assertSame(round($credit, 2), $row['credit'], 'credit alag nikla');
    }

    /**
     * Balance aur Credit ek doosre ko KHATAM na karen.
     *
     * Ek hi graahak ek booking par 60,000 ka qarzdaar hai aur doosri par us ka
     * 5,000 lena hai. Ek signed adad 55,000 kehta — aur wo dono haqeeqaton ko
     * chhupa deta. Cashier ko dono maloom hone chahiyen: ek wasooli hai,
     * doosra wapsi.
     */
    public function test_a_balance_on_one_booking_and_a_credit_on_another_are_both_shown(): void
    {
        $phone = '03009998888';
        $owing = $this->booking('MR. BOTH WAYS', $phone, 100000);
        $this->receive($owing, 40000);

        $overpaid = $this->booking('MR. BOTH WAYS', $phone, 25000);
        $this->receive($overpaid, 25000);
        $this->requoteAt($overpaid, 20000);

        $customer = Customer::on('tenant')->where('phone', $phone)->firstOrFail();
        $row = $this->balances->rows()->firstWhere('customer_id', $customer->id);

        $this->assertSame(60000.0, $row['balance'], 'ek booking par 60,000 lena hai');
        $this->assertSame(5000.0, $row['credit'], 'aur doosri par 5,000 dena hai');
    }

    /**
     * Jin bookings par graahak juda hi nahi, wo fehrist me GHUL na jayen —
     * magar chhupen bhi nahi.
     *
     * Ye faisla design doc me likha hai aur yahan par pehra hai: ek total jis
     * me se rows khamoshi se nikal jayen us total se bura hai jis ke saath
     * baqiya saath likha ho. Wahi baqiya un bookings ki fehrist bhi hai jinhe
     * jorna baqi hai.
     */
    public function test_unlinked_bookings_are_reported_apart_and_not_folded_in(): void
    {
        $linked = $this->booking('MR. LINKED', '03005556666', 50000);
        $this->receive($linked, 10000);

        $orphan = $this->booking('MR. ORPHAN', '03007778888', 30000);
        $orphan->forceFill(['customer_id' => null])->save();

        $rows = $this->balances->rows();
        $unlinked = $this->balances->unlinked();

        $this->assertSame(40000.0, round((float) $rows->sum('balance'), 2),
            'fehrist ka total sirf jure hue graahak ka ho');
        $this->assertSame(1, $unlinked['count'], 'aur bina jura booking alag gina jaye');
        $this->assertSame(30000.0, $unlinked['balance'], 'us ka baqi bhi alag dikhe');
    }

    /** Cancelled booking par bill sifar hai — magar wapas dene wala paisa nazar aana chahiye. */
    public function test_a_cancelled_booking_still_shows_money_that_must_go_back(): void
    {
        $phone = '03004445555';
        $event = $this->booking('MR. CANCELLED', $phone, 80000);
        $this->receive($event, 30000);
        $event->forceFill(['status' => CateringEvent::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        $customer = Customer::on('tenant')->where('phone', $phone)->firstOrFail();
        $row = $this->balances->rows()->firstWhere('customer_id', $customer->id);

        $this->assertSame(0.0, $row['billed'], 'cancelled booking par bill nahi banta');
        $this->assertSame(0.0, $row['balance'], 'is liye graahak par kuch baqi bhi nahi');
        $this->assertSame(30000.0, $row['credit'],
            'magar us ke 30,000 abhi hamare paas hain — ye rakam nazar se ojhal nahi honi chahiye');
    }

    /** Dono screenein khulti hain — asal controller se, sirf service se nahi. */
    public function test_both_screens_render(): void
    {
        $phone = '03001112222';
        $event = $this->booking('MR. RENDER', $phone, 45000);
        $this->receive($event, 15000);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $user = \App\Models\Tenant\User::on('tenant')->find($this->makeUser(['employee_code' => 'CB'.\Illuminate\Support\Str::random(4)]));
        $this->actingAs($user, 'tenant');
        \Illuminate\Support\Facades\Auth::shouldUse('tenant');

        $controller = app(\App\Http\Controllers\Tenant\Catering\CateringCustomerBalanceController::class);

        $list = $controller->index(Request::create('/catering/customer-balances', 'GET'))->render();
        $this->assertStringContainsString('MR. RENDER', $list);
        $this->assertStringContainsString('30,000.00', $list, 'baqi rakam fehrist par dikhni chahiye');

        $customer = Customer::on('tenant')->where('phone', $phone)->firstOrFail();
        $detail = $controller->show(Request::create('/x', 'GET'), $customer)->render();
        $this->assertStringContainsString($event->event_no, $detail, 'tafseel par booking ka number ho');
    }

    /**
     * Ye screen jama hua `balance_due` kabhi na parhe.
     *
     * Wohi pehra jo 27 Sep ko chaar files par lagaya gaya tha, ab is naye
     * module par bhi — kyunke ek nayi screen us purani ghalti ko dobara
     * daakhil karne ki sab se aasan jagah hai.
     */
    public function test_this_module_never_reads_the_frozen_invoice_balance(): void
    {
        foreach ([
            'app/Services/Catering/CateringCustomerBalanceService.php',
            'app/Http/Controllers/Tenant/Catering/CateringCustomerBalanceController.php',
            'resources/views/tenant/catering/customer-balances/index.blade.php',
            'resources/views/tenant/catering/customer-balances/show.blade.php',
        ] as $path) {
            $src = file_get_contents(base_path($path));
            $this->assertNotSame('', trim($src), "{$path} parhi jani chahiye");

            $code = preg_replace(['/\{\{--.*?--\}\}/s', '/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '', $src);
            $this->assertDoesNotMatchRegularExpression('/->balance_due/i', $code,
                "{$path} jama hua balance_due parh raha hai — wo invoice jaari hote waqt ka adad hai");
        }
    }
}
