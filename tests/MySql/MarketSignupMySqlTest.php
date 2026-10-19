<?php

namespace Tests\MySql;

use App\Jobs\Saas\ProvisionTrialWorkspaceJob;
use App\Mail\TrialWorkspaceFailedMail;
use App\Mail\TrialWorkspacePreparingMail;
use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanPrice;
use App\Models\Master\Tenant;
use App\Services\Saas\SelfSignupService;
use App\Services\Saas\TenantSubscriptionAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * WEBSITE-I18N-GEO-1 P3 — a signup keeps what it bought, where it is, and its language.
 *
 * A Saudi visitor who builds "Restaurant Pro, 4 branches, 2 extra terminals, yearly" gets exactly
 * that: the subscription records it (priced again on the server, the browser's currency ignored),
 * the limits follow it, the first branch runs on Asia/Riyadh — not Karachi — and the emails arrive
 * in Arabic. Pakistan signs up exactly as before.
 */
class MarketSignupMySqlTest extends MySqlTenantTestCase
{
    private const PASSWORD = 'Market-Secret-4821';

    private array $planIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        config(['saas.public_site_mode' => 'live']);
        $this->dropTestSignups();
    }

    protected function tearDown(): void
    {
        $this->dropTestSignups();
        DB::connection('master')->table('plans')->whereIn('id', $this->planIds)->delete();
        parent::tearDown();
    }

    private function dropTestSignups(): void
    {
        $master = DB::connection('master');
        $ids = $master->table('tenants')->where('tenant_code', 'like', 'mktest%')->pluck('id');
        $master->table('tenant_databases')->whereIn('tenant_id', $ids)->delete();
        $master->table('tenant_domains')->whereIn('tenant_id', $ids)->delete();
        $master->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
        $master->table('tenants')->whereIn('id', $ids)->delete();
        $master->table('jobs')->where('payload', 'like', '%mktest%')->delete();
        foreach ($master->select("SHOW DATABASES LIKE 'pos\\_tenant\\_mktest%'") as $row) {
            $master->statement('DROP DATABASE IF EXISTS `' . array_values((array) $row)[0] . '`');
        }
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    /** Restaurant Pro as the P2 migration prices it: PKR bundle (3 branches) + SAR/USD per branch, 3 terminals + 10 users a branch. */
    private function restaurantPro(): Plan
    {
        DB::connection('master')->table('plans')->where('code', 'restaurant_pro')->delete();
        $plan = Plan::create(['code' => 'restaurant_pro', 'name' => 'Restaurant Pro', 'price' => 15000, 'currency_code' => 'PKR', 'billing_period' => 'monthly',
            'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30, 'display_order' => 40,
            'monthly_price' => 15000, 'yearly_price' => 150000]);
        $this->planIds[] = $plan->id;
        foreach (['branch_limit' => 3, 'terminal_limit' => 6, 'user_limit' => 20, 'product_limit' => 10000, 'terminals_per_branch' => 3, 'users_per_branch' => 10] as $k => $v) {
            PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => $k, 'feature_value' => (string) $v]);
        }
        PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'PKR', 'pricing_model' => 'bundle', 'monthly_price' => 15000, 'yearly_price' => 150000]);
        PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'SAR', 'pricing_model' => 'per_branch', 'monthly_price' => 549, 'extra_terminal_monthly' => 59]);
        PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'USD', 'pricing_model' => 'per_branch', 'monthly_price' => 169, 'extra_terminal_monthly' => 19]);

        return $plan;
    }

    private function form(Plan $plan, array $extra = []): array
    {
        $code = 'mktest' . Str::lower(Str::random(6));

        return $extra + [
            'business_name' => 'Market Test Kitchen', 'tenant_code' => $code, 'owner_name' => 'Owner',
            'owner_email' => $code . '@example.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'plan_id' => $plan->id, 'billing_period' => 'monthly',
        ];
    }

    private function signUp(string $path, array $form): Tenant
    {
        Queue::fake();
        Mail::fake();
        $this->post($this->central($path), $form)->assertSessionHasNoErrors();

        return Tenant::where('tenant_code', $form['tenant_code'])->firstOrFail();
    }

    public function test_a_saudi_per_branch_signup_records_what_was_bought(): void
    {
        $plan = $this->restaurantPro();
        // The browser says PKR; the server decides from the market.
        $tenant = $this->signUp('/ar/start-trial', $this->form($plan, [
            'market' => 'sa', 'branches' => 4, 'extra_terminals' => 2, 'billing_period' => 'yearly', 'currency_code' => 'PKR',
        ]));
        $sub = $tenant->subscription;

        $this->assertSame('per_branch', $sub->pricing_model);
        $this->assertSame('SAR', $sub->currency_code);
        $this->assertSame(4, $sub->branches_purchased);
        $this->assertSame(2, $sub->extra_terminals);
        $this->assertSame('yearly', $sub->billing_period);
        $this->assertEquals(20826, $sub->price_snapshot['total'], 'the quote at signup, kept');
        $this->assertSame('SAR', $tenant->currency_code, 'never the currency the browser posted');
        $this->assertSame('Asia/Riyadh', $tenant->timezone);
        $this->assertSame('ar', $tenant->locale);
    }

    public function test_pakistan_signs_up_as_before(): void
    {
        $plan = $this->restaurantPro();
        $tenant = $this->signUp('/start-trial', $this->form($plan, ['branches' => 9, 'extra_terminals' => 4]));
        $sub = $tenant->subscription;

        $this->assertSame('bundle', $sub->pricing_model);
        $this->assertSame('PKR', $sub->currency_code);
        $this->assertNull($sub->branches_purchased, 'a bundle plan has its own branches');
        $this->assertSame(0, $sub->extra_terminals);
        $this->assertSame('Asia/Karachi', $tenant->timezone);
        $this->assertSame('en', $tenant->locale);
        $this->assertSame(3, app(TenantSubscriptionAccessService::class)->featureLimit($tenant, 'branch_limit'), 'the plan\'s 3 branches, not the asked 9');
    }

    public function test_the_us_uses_the_visitors_own_timezone_and_refuses_a_made_up_one(): void
    {
        $plan = $this->restaurantPro();
        $tenant = $this->signUp('/start-trial', $this->form($plan, ['market' => 'us', 'timezone' => 'America/Chicago']));
        $this->assertSame('America/Chicago', $tenant->timezone);
        $this->assertSame('USD', $tenant->currency_code);

        $this->post($this->central('/start-trial'), $this->form($plan, ['market' => 'us', 'timezone' => 'Mars/Olympus']))
            ->assertSessionHasErrors('timezone');
    }

    public function test_limits_follow_what_was_bought(): void
    {
        $plan = $this->restaurantPro();
        $tenant = $this->signUp('/ar/start-trial', $this->form($plan, ['market' => 'sa', 'branches' => 4, 'extra_terminals' => 2]));
        $limits = app(TenantSubscriptionAccessService::class);

        $this->assertSame(4, $limits->featureLimit($tenant, 'branch_limit'));
        $this->assertSame(14, $limits->featureLimit($tenant, 'terminal_limit'), '4 × 3 + 2');
        $this->assertSame(40, $limits->featureLimit($tenant, 'user_limit'));
        $this->assertSame(10000, $limits->featureLimit($tenant, 'product_limit'), 'products stay the plan\'s');

        // A subscription from before per-branch pricing reads exactly as it always did.
        $tenant->subscription->update(['pricing_model' => null, 'branches_purchased' => null, 'extra_terminals' => 0]);
        $this->assertSame(3, $limits->featureLimit($tenant->fresh(), 'branch_limit'));
        $this->assertSame(6, $limits->featureLimit($tenant->fresh(), 'terminal_limit'));
    }

    public function test_more_than_ten_branches_is_a_sales_conversation(): void
    {
        $plan = $this->restaurantPro();
        $this->post($this->central('/ar/start-trial'), $this->form($plan, ['market' => 'sa', 'branches' => 11]))
            ->assertSessionHasErrors('branches');
        $this->assertSame(0, Tenant::where('tenant_code', 'like', 'mktest%')->count(), 'nothing was created');
    }

    public function test_the_first_branch_runs_on_the_business_clock(): void
    {
        $plan = $this->restaurantPro();
        Queue::fake();
        $form = $this->form($plan, ['market' => 'sa', 'branches' => 2]);
        $tenant = app(SelfSignupService::class)->createPendingTrial($form + ['market' => 'sa']);

        // The real provisioner, the real migrations, a real database.
        (new ProvisionTrialWorkspaceJob($tenant->id, Hash::make(self::PASSWORD), 'http://x.test/login', 'Market Test Kitchen', $form['owner_email'], 'ar'))
            ->handle(app(SelfSignupService::class));

        $zones = DB::connection('master')->table('pos_tenant_' . $form['tenant_code'] . '.branches')->pluck('timezone')->all();
        $this->assertSame(['Asia/Riyadh'], $zones, 'a Saudi shop\'s business day and shifts run on Riyadh time');
        $this->assertSame('SAR', DB::connection('master')->table('pos_tenant_' . $form['tenant_code'] . '.currencies')->value('code'));
    }

    public function test_the_emails_speak_the_signup_language(): void
    {
        $plan = $this->restaurantPro();
        $this->signUp('/ar/start-trial', $this->form($plan, ['market' => 'sa']));
        Mail::assertQueued(TrialWorkspacePreparingMail::class, fn ($m) => $m->locale === 'ar');

        // A build that fails still writes in Arabic, with an Arabic "try again" link.
        Mail::fake();
        (new ProvisionTrialWorkspaceJob(0, 'x', 'http://x.test/login', 'Market Test Kitchen', 'mktest@example.test', 'ar'))->failed(null);
        Mail::assertSent(TrialWorkspaceFailedMail::class, fn ($m) => $m->locale === 'ar' && str_ends_with($m->tryAgainUrl, '/ar/start-trial'));

        $html = (new TrialWorkspacePreparingMail(brand: 'Bingoo', businessName: 'Kitchen', workspaceAddress: 'k.test', ownerEmail: 'o@k.test', supportEmail: 's@k.test'))
            ->locale('ar')->render();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('نجهّز مساحة عملك الآن', $html);
    }
}
