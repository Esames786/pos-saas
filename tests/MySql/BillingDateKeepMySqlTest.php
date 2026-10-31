<?php

namespace Tests\MySql;

use App\Models\Master\CentralUser;
use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use App\Services\Saas\SubscriptionBillingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * BILLING-DATE-KEEP-1 — Master mein invoice "paid" karne se subscription ki khatam hone ki tareekh kabhi PEECHE nahi
 * jaati. Plan: docs/plans/billing-date-keep-2026-10-31.md.
 *
 * 31 Oct 2026: aik live tenant ki tareekh 31 Oct 00:00 thi (paid invoice ka period_end, aik DATE), raat 00:10 ki
 * sweep ne usay past_due kar diya aur POS band. Haath se saal bhar aage ki gayi — magar agli invoice paid hote hi
 * purana code tareekh us invoice ke period_end par wapas le aata. Ye test Master ke asal raaste (payment record
 * aur verify) se chalta hai.
 */
class BillingDateKeepMySqlTest extends MySqlTenantTestCase
{
    private const PERMS = ['central.invoices.payments.store', 'central.invoices.payments.verify'];

    private string $host;

    private Tenant $tenant;

    private Subscription $sub;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'http://'.config('tenancy.central_domain');

        // Apna tenant aur plan — kisi aur test ka subscription kabhi nahi chhuna.
        $m = DB::connection('master');
        $m->table('tenants')->updateOrInsert(['tenant_code' => 'billdatekeep'], ['business_name' => 'Billing Date Keep Test',
            'owner_name' => 'Owner', 'owner_email' => 'owner@billdatekeep.test', 'currency_code' => 'PKR', 'status' => 'active',
            'is_demo' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->tenant = Tenant::where('tenant_code', 'billdatekeep')->firstOrFail();
        $planId = $m->table('plans')->where('code', 'billdatekeep-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'billdatekeep-plan', 'name' => 'Billing Date Keep', 'price' => 25000,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->wipe();

        $sub = Subscription::where('tenant_id', $this->tenant->id)->first()
            ?: Subscription::create(['tenant_id' => $this->tenant->id, 'plan_id' => $planId, 'status' => 'active',
                'currency_code' => 'PKR', 'billing_period' => 'monthly', 'current_period_ends_at' => '2027-01-01 00:00:00']);
        $sub->update(['plan_id' => $planId, 'price_snapshot' => 25000, 'invoice_day' => 15]);
        $this->sub = $sub->fresh();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach ($this->userIds as $id) {
            DB::connection('master')->table('model_has_permissions')->where('model_id', $id)
                ->where('model_type', CentralUser::class)->delete();
            DB::connection('master')->table('central_users')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    private function wipe(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('subscription_invoices')->where('tenant_id', $this->tenant->id)->pluck('id');
        $m->table('subscription_payments')->whereIn('subscription_invoice_id', $ids)->delete();
        $m->table('subscription_invoices')->whereIn('id', $ids)->delete();
    }

    private function admin(): CentralUser
    {
        $user = CentralUser::create(['name' => 'Billing Admin', 'email' => 'billdate'.uniqid().'@example.test',
            'password' => bcrypt('secret'), 'status' => 'active']);
        $this->userIds[] = $user->id;
        foreach (self::PERMS as $p) {
            Permission::findOrCreate($p, 'central');
        }
        $user->givePermissionTo(self::PERMS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function setSub(string $status, ?string $endsAt): void
    {
        DB::connection('master')->table('subscriptions')->where('id', $this->sub->id)
            ->update(['status' => $status, 'current_period_ends_at' => $endsAt]);
    }

    private function invoice(string $type, string $from, string $to, float $total = 25000): SubscriptionInvoice
    {
        return app(SubscriptionBillingService::class)->createInvoice($this->tenant->fresh(), [
            'invoice_type' => $type, 'status' => 'issued', 'subtotal' => $total, 'period_start' => $from, 'period_end' => $to,
        ]);
    }

    /** Master → Invoice → "Record payment" — wohi form jo admin bharta hai. */
    private function pay(CentralUser $u, SubscriptionInvoice $invoice, string $status = 'verified'): void
    {
        $this->actingAs($u, 'central')->post($this->host.'/invoices/'.$invoice->id.'/payments', [
            'amount' => (float) $invoice->total_amount, 'currency_code' => 'PKR', 'payment_date' => '2026-11-16',
            'status' => $status, 'reference_no' => 'test',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function subEnds(): string
    {
        return (string) DB::connection('master')->table('subscriptions')->where('id', $this->sub->id)->value('current_period_ends_at');
    }

    private function subStatus(): string
    {
        return (string) DB::connection('master')->table('subscriptions')->where('id', $this->sub->id)->value('status');
    }

    public function test_paying_an_invoice_never_pulls_the_end_date_back(): void
    {
        // Tareekh saal bhar aage (haath se di gayi); is mahine ki invoice (16 Oct – 15 Nov) paid hoti hai.
        $this->setSub('active', '2027-10-31 23:59:59');
        $admin = $this->admin();
        $inv = $this->invoice('subscription', '2026-10-16', '2026-11-15');

        $this->pay($admin, $inv);

        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame('2027-10-31 23:59:59', $this->subEnds(),
            'Invoice paid hone se tareekh peeche aa gayi — agli raat tenant band ho jata.');
        $this->assertSame('active', $this->subStatus());

        // Purane mahine ki invoice baad mein paid ho, tab bhi wohi.
        $old = $this->invoice('subscription', '2026-08-16', '2026-09-15');
        $this->pay($admin, $old);
        $this->assertSame('2027-10-31 23:59:59', $this->subEnds());
    }

    public function test_a_lapsed_subscription_is_carried_to_the_end_of_the_paid_day(): void
    {
        // Wohi halat jo 31 Oct ko thi: tareekh raat 12 baje ki, sweep ne past_due kar diya.
        $this->setSub('past_due', '2026-10-31 00:00:00');
        $admin = $this->admin();
        $inv = $this->invoice('subscription', '2026-11-01', '2026-11-30');

        $this->pay($admin, $inv);

        $this->assertSame('2026-11-30 23:59:59', $this->subEnds(), 'Paid mahine ka aakhri din bhi ginna chahiye.');
        $this->assertSame('active', $this->subStatus());

        // Sweep saare subscriptions par chalti hai; doosre tests ke subscriptions ki halat wapas rakhni hai.
        $others = DB::connection('master')->table('subscriptions')->where('id', '!=', $this->sub->id)->pluck('status', 'id');
        try {
            // Raat ki sweep (00:10) paid mahine ke aakhri din band NAHI karti ...
            $this->travelTo('2026-11-30 00:10:00');
            $this->artisan('saas:subscriptions-expire')->assertSuccessful();
            $this->assertSame('active', $this->subStatus(), 'Paid mahine ke aakhri din sweep ne band kar diya.');

            // ... magar agle din karti hai — paisa na aaye to band hona bhi zaroori hai.
            $this->travelTo('2026-12-01 00:10:00');
            $this->artisan('saas:subscriptions-expire')->assertSuccessful();
            $this->assertSame('past_due', $this->subStatus());
        } finally {
            $this->travelBack();
            foreach ($others as $id => $status) {
                DB::connection('master')->table('subscriptions')->where('id', $id)->update(['status' => $status]);
            }
        }
    }

    public function test_verifying_a_tenant_proof_payment_keeps_the_later_date_too(): void
    {
        // Doosra raasta: payment "pending" (tenant ka proof), phir Master mein "Verify".
        $this->setSub('active', '2027-10-31 23:59:59');
        $admin = $this->admin();
        $inv = $this->invoice('subscription', '2026-10-16', '2026-11-15');
        $this->pay($admin, $inv, 'pending');
        $this->assertSame('issued', $inv->fresh()->status);
        $payment = $inv->fresh()->payments()->firstOrFail();

        $this->actingAs($admin, 'central')->post($this->host.'/invoices/'.$inv->id.'/payments/'.$payment->id.'/verify')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame('2027-10-31 23:59:59', $this->subEnds());
    }

    public function test_a_new_subscription_invoice_still_moves_the_date_forward(): void
    {
        // Ulta bhi sach rehna chahiye: aage ki invoice paid ho to tareekh aage jaye, warna nayi adaegi kaam na kare.
        $this->setSub('active', '2026-11-15 23:59:59');
        $inv = $this->invoice('subscription', '2026-11-16', '2026-12-15');

        $this->pay($this->admin(), $inv);

        $this->assertSame('2026-12-15 23:59:59', $this->subEnds());
    }
}
