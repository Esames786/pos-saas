<?php

namespace Tests\MySql;

use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanPrice;
use App\Services\Saas\PlanPricingService;
use Illuminate\Support\Facades\DB;

/**
 * WEBSITE-I18N-GEO-1 P2 — one price list per market.
 *
 * Pakistan keeps its bundle plans in PKR (the page it always had). Saudi Arabia, the UAE, Qatar and
 * the US pay per branch, plus extra terminals, less a multi-branch discount; yearly is ten months.
 * Every figure the pricing page, the builder and the checkout show comes from quote().
 */
class MarketPricingMySqlTest extends MySqlTenantTestCase
{
    private array $planIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['saas.public_site_mode' => 'live']);
    }

    protected function tearDown(): void
    {
        DB::connection('master')->table('plans')->whereIn('id', $this->planIds)->delete();
        parent::tearDown();
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    /**
     * The two restaurant plans as the migration prices them (the builder needs both levels of a
     * business): PKR bundle + SAR/USD per branch. Returns Restaurant Pro — 3 terminals, 10 users a branch.
     */
    private function plan(): Plan
    {
        $this->makePlan('restaurant_starter', 'Restaurant Starter', 7000, 1, 2, 8, 219, 59);

        return $this->makePlan('restaurant_pro', 'Restaurant Pro', 15000, 3, 3, 10, 549, 169);
    }

    private function makePlan(string $code, string $name, int $pkr, int $branches, int $tpb, int $upb, int $sar, int $usd): Plan
    {
        DB::connection('master')->table('plans')->where('code', $code)->delete();
        $plan = Plan::create(['code' => $code, 'name' => $name, 'price' => $pkr, 'currency_code' => 'PKR', 'billing_period' => 'monthly',
            'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30, 'display_order' => 40,
            'monthly_price' => $pkr, 'yearly_price' => $pkr * 10, 'public_description' => 'Best for full-service restaurants, kitchens, and multi-station operations.']);
        $this->planIds[] = $plan->id;
        foreach (['branch_limit' => $branches, 'terminal_limit' => $branches * 2, 'user_limit' => 20, 'product_limit' => 10000, 'terminals_per_branch' => $tpb, 'users_per_branch' => $upb] as $k => $v) {
            PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => $k, 'feature_value' => (string) $v]);
        }
        PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'PKR', 'pricing_model' => 'bundle', 'monthly_price' => $pkr, 'yearly_price' => $pkr * 10]);
        PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'SAR', 'pricing_model' => 'per_branch', 'monthly_price' => $sar, 'extra_terminal_monthly' => 59]);
        PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'USD', 'pricing_model' => 'per_branch', 'monthly_price' => $usd, 'extra_terminal_monthly' => 19]);

        return $plan->fresh(['features', 'prices', 'enabledModules']);
    }

    public function test_the_worked_example_from_the_plan(): void
    {
        // MD §2.6: Restaurant Pro · Saudi · 4 branches · 2 extra terminals · yearly.
        $q = app(PlanPricingService::class)->quote($this->plan(), 'sa', 4, 2, 'yearly');

        $this->assertSame('SAR', $q['currency']);
        $this->assertSame(2196.0, $q['branches_total']);
        $this->assertSame(118.0, $q['extra_terminals_total']);
        $this->assertSame(10, $q['discount_percent']);
        $this->assertSame(231.4, $q['discount']);
        $this->assertSame(2082.6, $q['monthly_total']);
        $this->assertSame(20826.0, $q['yearly_total'], 'yearly is ten months');
        $this->assertSame(20826.0, $q['total']);
        $this->assertSame(14, $q['terminals'], '4 × 3 + 2');
        $this->assertSame(40, $q['users']);
        $this->assertSame(15, $q['vat_percent']);
    }

    public function test_discount_steps_and_the_branch_ceiling(): void
    {
        $plan = $this->plan();
        $s = app(PlanPricingService::class);
        $this->assertSame(0, $s->quote($plan, 'sa', 2)['discount_percent']);
        $this->assertSame(10, $s->quote($plan, 'sa', 5)['discount_percent']);
        $this->assertSame(15, $s->quote($plan, 'sa', 6)['discount_percent']);
        $this->assertSame(15, $s->quote($plan, 'sa', 10)['discount_percent']);
        $eleven = $s->quote($plan, 'sa', 11);
        $this->assertTrue($eleven['contact_sales'], '11+ branches is a sales conversation');
        $this->assertSame(10, $eleven['branches'], 'never priced beyond the self-service ceiling');
    }

    public function test_pakistan_stays_a_bundle_whatever_is_asked(): void
    {
        $q = app(PlanPricingService::class)->quote($this->plan(), 'pk', 7, 5, 'yearly');
        $this->assertSame('bundle', $q['pricing']);
        $this->assertSame('PKR', $q['currency']);
        $this->assertSame(3, $q['branches'], 'the plan\'s own branches, not the asked 7');
        $this->assertSame(0, $q['extra_terminals']);
        $this->assertSame(15000.0, $q['monthly_total']);
        $this->assertSame(150000.0, $q['yearly_total'], 'the stored yearly price');
    }

    public function test_a_market_without_a_price_is_contact_sales_not_a_guess(): void
    {
        $this->assertNull(app(PlanPricingService::class)->quote($this->plan(), 'ae'), 'no AED row → no number');
    }

    public function test_pakistan_sees_the_page_it_always_had(): void
    {
        $this->plan();
        $html = $this->get($this->central('/pricing'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="build"', $html);
        $this->assertStringContainsString('PKR 15,000', $html);
        $this->assertStringNotContainsString('per branch', $html);
    }

    public function test_saudi_sees_per_branch_prices_and_the_builder_in_arabic(): void
    {
        $this->plan();
        $html = $this->get($this->central('/ar/pricing'))->assertOk()->getContent();
        $this->assertStringContainsString('id="build"', $html);
        $this->assertStringContainsString("\u{2068}549 ر.س\u{2069}", $html);
        $this->assertStringContainsString('لكل فرع / شهرياً', $html);
        $this->assertStringContainsString('ضريبة القيمة المضافة 15%', $html);
        $this->assertStringNotContainsString('15,000', $html, 'no Pakistani bundle price on the Saudi page');
    }

    public function test_the_checkout_carries_the_builder_choice_and_shows_its_quote(): void
    {
        $this->plan();
        $html = $this->get($this->central('/ar/start-trial?plan=restaurant_pro&market=sa&branches=4&terminals=2&billing=yearly'))->assertOk()->getContent();

        $this->assertStringContainsString("id=\"pbTotal\">\u{2068}20,826 ر.س\u{2069}</div>", $html, 'the same total as the builder, from the server');
        $this->assertStringContainsString('name="market" value="sa"', $html);
        $this->assertMatchesRegularExpression('/id="pbBranches" name="branches"[^>]*value="4"/', $html);
        $this->assertMatchesRegularExpression('/id="pbExtra" name="extra_terminals"[^>]*value="2"/', $html);
        $this->assertStringContainsString('name="currency_code" value="SAR"', $html);

        // A URL asking for 50 branches is held to the ceiling, never priced beyond it.
        $over = $this->get($this->central('/start-trial?plan=restaurant_pro&market=sa&branches=50'))->getContent();
        $this->assertMatchesRegularExpression('/id="pbBranches" name="branches"[^>]*value="10"/', $over);
    }

    public function test_the_currency_picker_is_remembered(): void
    {
        $this->plan();
        // An unknown market is ignored and nothing is remembered. (First: the test app keeps a queued
        // cookie across requests, which a real request never does.)
        $this->get($this->central('/pricing?market=xx'))->assertOk()->assertCookieMissing('bingoo_market');
        $this->get($this->central('/pricing?market=us'))->assertOk()->assertCookie('bingoo_market', 'us');
        $html = $this->withCookie('bingoo_market', 'us')->get($this->central('/pricing'))->getContent();
        $this->assertStringContainsString("\u{2068}\$169\u{2069}", $html, 'an English visitor who picked USD keeps USD');
        $this->assertStringContainsString('id="build"', $html);
    }
    public function test_every_script_on_the_per_branch_pages_parses(): void
    {
        $this->plan();
        $node = collect([getenv('NODE_BINARY') ?: null, ...(glob('D:/laragon2/bin/nodejs/*/node.exe') ?: [])])
            ->filter(fn ($p) => $p && is_file($p))->first()
            ?? strtok(trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')), "\r\n");
        if (! $node || ! is_file($node)) {
            $this->markTestSkipped('node not found (set NODE_BINARY)');
        }
        foreach (['/ar/pricing', '/pricing?market=us', '/ar/start-trial?plan=restaurant_pro&market=sa&branches=4'] as $page) {
            $html = $this->get($this->central($page))->getContent();
            $this->assertStringContainsString('window.BingooQuote', $html, "{$page}: the shared arithmetic is on the page");
            preg_match_all('#<script([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER);
            foreach ($m as $i => $script) {
                if (str_contains($script[1], 'src=')) {
                    continue;
                }
                $file = tempnam(sys_get_temp_dir(), 'pb') . '.js';
                file_put_contents($file, $script[2]);
                $out = [];
                exec(escapeshellarg($node) . ' --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
                @unlink($file);
                $this->assertSame(0, $code, "{$page} script #{$i}: " . implode("\n", $out));
            }
            $this->assertSame(1, substr_count($html, 'window.BingooQuote = '), "{$page}: the shared arithmetic is pushed once");
        }
    }
}
