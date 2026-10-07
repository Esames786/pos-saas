<?php

namespace Tests\MySql;

use App\Http\Controllers\PublicSiteController;
use App\Http\Requests\Public\StartTrialRequest;
use App\Models\Master\Plan;
use App\Models\Master\Tenant;
use App\Services\Saas\BillingPeriodResolver;
use App\Services\Saas\SelfSignupService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

/**
 * WEBSITE-I18N-GEO-1 P0 (CLOUD-BILLING-2 brought to production) — Monthly / Yearly reaches signup.
 *
 * The pricing page's Yearly toggle changed only CSS: the Start-Trial link carried no cycle, the
 * checkout always showed the monthly price "per month", and the subscription had nowhere to keep the
 * choice. A visitor who picked Yearly saw PKR 8,000 per month at checkout. These render the REAL
 * pages through the REAL controller.
 */
class TrialBillingPeriodMySqlTest extends MySqlTenantTestCase
{
    private array $planIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.public_site_mode' => 'live']);
        view()->share('errors', new ViewErrorBag);
    }

    protected function tearDown(): void
    {
        $master = DB::connection('master');
        $tenantIds = $master->table('tenants')->where('tenant_code', 'like', 'bptest%')->pluck('id');
        $master->table('subscriptions')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenant_domains')->whereIn('tenant_id', $tenantIds)->delete();
        $master->table('tenants')->whereIn('id', $tenantIds)->delete();
        $master->table('plans')->whereIn('id', $this->planIds)->delete();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_chosen_cycle_reaches_the_subscription(): void
    {
        $plan = $this->plan();
        $this->mock(TenantProvisioner::class, fn ($m) => $m->shouldReceive('provisionTenant')->andReturnUsing(fn ($tenant) => $tenant));

        $this->assertSame('yearly', $this->signup($plan, 'yearly')->subscription->billing_period);
        $this->assertSame('monthly', $this->signup($plan, 'monthly')->subscription->billing_period);
        $this->assertSame('monthly', $this->signup($plan, null)->subscription->billing_period, 'nothing posted = monthly');
    }

    public function test_the_form_takes_yearly_only_when_it_says_yearly(): void
    {
        foreach (['yearly' => 'yearly', ' YEARLY ' => 'yearly', 'monthly' => 'monthly', 'lifetime' => 'monthly', '' => 'monthly'] as $posted => $kept) {
            $request = StartTrialRequest::create('/start-trial', 'POST', ['billing_period' => $posted]);
            (fn () => $this->prepareForValidation())->call($request);
            $this->assertSame($kept, $request->input('billing_period'), "posted [{$posted}]");
        }
    }

    public function test_the_link_from_pricing_opens_checkout_on_that_cycle(): void
    {
        $plan = $this->plan();
        $this->assertSame('yearly', $this->checkout($plan, 'yearly')->getData()['selectedBilling']);
        $this->assertSame('monthly', $this->checkout($plan, 'lifetime')->getData()['selectedBilling']);
        $this->assertSame('monthly', $this->checkout($plan, null)->getData()['selectedBilling'], 'default is monthly');
    }

    public function test_checkout_shows_the_yearly_price_when_yearly_was_chosen(): void
    {
        $plan = $this->plan(['monthly_price' => 8000, 'yearly_price' => 80000]);

        $yearly = $this->checkout($plan, 'yearly')->render();
        $this->assertStringContainsString('<span id="selPlanAmount">80,000</span>', $yearly);
        $this->assertStringContainsString('<div class="text-muted small mb-1" id="selPlanPer">per year</div>', $yearly);
        $this->assertMatchesRegularExpression('/name="billing_period" value="yearly"\s+checked/', $yearly);
        $this->assertStringContainsString('PKR 6,667</span> a month', $yearly, 'the monthly equivalent of a year');
        $this->assertMatchesRegularExpression('/<span class="plan-price-monthly"\s+hidden\s*>/', $yearly, 'the plan list follows the cycle too');

        $monthly = $this->checkout($plan, null)->render();
        $this->assertStringContainsString('<span id="selPlanAmount">8,000</span>', $monthly);
        $this->assertStringContainsString('id="selPlanPer">per month</div>', $monthly);
        $this->assertMatchesRegularExpression('/id="selPlanSaving"\s+hidden/', $monthly);
    }

    public function test_pricing_trial_links_carry_the_cycle(): void
    {
        $plan = $this->plan();
        $html = app(PublicSiteController::class)->pricing()->render();

        $this->assertStringContainsString('/start-trial?plan=' . $plan->code . '&amp;billing=monthly" data-trial-cta data-plan="' . $plan->code . '"', $html);
        $this->assertStringContainsString("setCtaBilling('yearly')", $html, 'the Yearly button rewrites the links');
    }

    public function test_every_script_on_both_pages_parses(): void
    {
        $plan = $this->plan();
        foreach (['pricing' => app(PublicSiteController::class)->pricing()->render(), 'checkout' => $this->checkout($plan, 'yearly')->render()] as $page => $html) {
            $this->assertScriptsParse($page, $html);
        }
    }

    public function test_yearly_is_ten_months_over_twelve_calendar_months(): void
    {
        $r = app(BillingPeriodResolver::class);
        $plan = $this->plan(['monthly_price' => 3000]);

        $this->assertSame(3000.0, $r->invoiceAmount($plan, 'monthly'));
        $this->assertSame(30000.0, $r->invoiceAmount($plan, 'yearly'));
        $this->assertSame('2026-02-28', $r->periodEnd(Carbon::parse('2026-01-31'), 'monthly')->toDateString(), 'never spills into March');
        $this->assertSame('2025-02-28', $r->periodEnd(Carbon::parse('2024-02-29'), 'yearly')->toDateString());
        $this->assertSame('2027-01-15', $r->periodEnd(Carbon::parse('2026-12-15'), 'monthly')->toDateString());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function plan(array $overrides = []): Plan
    {
        $plan = Plan::create(array_merge([
            'code' => 'bp' . substr(uniqid(), -6), 'name' => 'Billing Test Plan', 'price' => 5000, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_active' => true, 'is_public' => true, 'is_custom' => false,
            'trial_days' => 30, 'display_order' => 0, 'monthly_price' => 5000, 'yearly_price' => 50000,
        ], $overrides));
        $this->planIds[] = $plan->id;

        return $plan;
    }

    private function checkout(Plan $plan, ?string $billing)
    {
        return app(PublicSiteController::class)->trialCreate(
            Request::create('/start-trial', 'GET', array_filter(['plan' => $plan->code, 'billing' => $billing]))
        );
    }

    private function signup(Plan $plan, ?string $billing): Tenant
    {
        return app(SelfSignupService::class)->registerTrial(array_filter([
            'business_name' => 'Billing Co', 'tenant_code' => 'bptest' . substr(uniqid(), -6),
            'owner_name' => 'Owner', 'owner_email' => 'bp_' . uniqid() . '@test.local', 'password' => 'password123',
            'plan_id' => $plan->id, 'currency_code' => 'PKR', 'billing_period' => $billing,
        ], fn ($v) => $v !== null));
    }

    /** A syntax error in an inline script kills the whole block in the browser; render-only tests never see it. */
    private function assertScriptsParse(string $page, string $html): void
    {
        $node = collect([getenv('NODE_BINARY') ?: null, ...(glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [])])
            ->filter(fn ($p) => $p && is_file($p))->first()
            ?? trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        $node = strtok((string) $node, "\r\n");
        if (! $node || ! is_file($node)) {
            $this->markTestSkipped('node not found (set NODE_BINARY)');
        }

        preg_match_all('#<script([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER);
        $inline = array_filter($m, fn ($s) => ! str_contains($s[1], 'src=') && ! preg_match('/type="(?!text\/javascript|module)/', $s[1]));
        $this->assertNotEmpty($inline, "{$page}: no inline script found");
        foreach ($inline as $i => $script) {
            $file = tempnam(sys_get_temp_dir(), 'bp') . '.js';
            file_put_contents($file, $script[2]);
            $out = [];
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, "{$page}: inline script #{$i} does not parse:\n" . implode("\n", $out));
        }
    }
}
