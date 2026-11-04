<?php

namespace Tests\MySql;

use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanPrice;
use App\Models\Master\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * WEBSITE-HIDE-PRICES-1 — SAAS_HIDE_PUBLIC_PRICES=true takes every subscription price off the public
 * website (a client demo): plans, features, demos and the free trial stay, each price reads "Price on
 * request", and no figure reaches the page source — not even inside the plan builder's data. Signing
 * up still works and the server still prices and records the plan.
 *
 * The plans here carry unusual prices so a leftover figure cannot hide among other numbers; the first
 * test proves those figures DO show with the switch off, so "not found" later means something.
 */
class HidePublicPricesMySqlTest extends MySqlTenantTestCase
{
    /** Every figure the fixture plans can show, in any market or cycle. */
    private const FIGURES = ['7,350', '15,450', '73,500', '154,500', 'SAR 263', 'SAR 587', '$61', '$173'];

    /** Price machinery that must not be in a hidden page at all. */
    private const MACHINERY = ['BingooQuote', 'data-monthly', 'data-yearly', 'id="build"', 'id="market-picker"', 'id="btnYearly"', 'id="billingCycle"'];

    private array $planIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        config(['saas.public_site_mode' => 'live', 'saas.hide_public_prices' => false,
            'saas.recaptcha.site_key' => null, 'saas.recaptcha.secret_key' => null]);
        $this->dropSignups();
        foreach ([['restaurant_starter', 7350, 263, 61], ['restaurant_pro', 15450, 587, 173]] as [$code, $pkr, $sar, $usd]) {
            DB::connection('master')->table('plans')->where('code', $code)->delete();
            $plan = Plan::create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)), 'price' => $pkr, 'currency_code' => 'PKR',
                'billing_period' => 'monthly', 'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30,
                'monthly_price' => $pkr, 'yearly_price' => $pkr * 10]);
            $this->planIds[$code] = $plan->id;
            foreach (['branch_limit' => 1, 'terminals_per_branch' => 2, 'users_per_branch' => 8, 'product_limit' => 1000] as $k => $v) {
                PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => $k, 'feature_value' => (string) $v]);
            }
            PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'PKR', 'pricing_model' => 'bundle', 'monthly_price' => $pkr, 'yearly_price' => $pkr * 10]);
            PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'SAR', 'pricing_model' => 'per_branch', 'monthly_price' => $sar, 'extra_terminal_monthly' => 59]);
            PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'USD', 'pricing_model' => 'per_branch', 'monthly_price' => $usd, 'extra_terminal_monthly' => 19]);
        }
    }

    protected function tearDown(): void
    {
        $this->dropSignups();
        DB::connection('master')->table('plans')->whereIn('id', $this->planIds)->delete();
        parent::tearDown();
    }

    private function dropSignups(): void
    {
        $master = DB::connection('master');
        $ids = $master->table('tenants')->where('tenant_code', 'like', 'hideprice%')->pluck('id');
        foreach (['tenant_databases', 'tenant_domains', 'subscriptions'] as $table) {
            $master->table($table)->whereIn('tenant_id', $ids)->delete();
        }
        $master->table('tenants')->whereIn('id', $ids)->delete();
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    private function page(string $path): string
    {
        return $this->get($this->central($path))->assertOk()->getContent();
    }

    public function test_the_prices_show_while_the_switch_is_off(): void
    {
        $this->assertStringContainsString('15,450', $this->page('/'), 'home preview');
        $pricing = $this->page('/pricing');
        $this->assertStringContainsString('7,350', $pricing);
        $this->assertStringContainsString('154,500', $pricing, 'the yearly figure is on the page');
        $saudi = $this->page('/pricing?market=sa');
        $this->assertStringContainsString('SAR 587', $saudi);
        $this->assertStringContainsString('BingooQuote', $saudi, 'the builder is there to hide');
        $this->assertStringContainsString('data-monthly', $this->page('/start-trial?plan=restaurant_pro'));
    }

    public function test_no_price_anywhere_on_the_site_when_hidden(): void
    {
        config(['saas.hide_public_prices' => true]);
        $pages = [
            '/' => 'Price on request',
            '/pricing' => 'Price on request',
            '/pricing?market=sa' => 'Price on request',
            '/pricing?market=us' => 'Price on request',
            '/start-trial?plan=restaurant_pro&billing=yearly' => 'Price on request',
            '/start-trial?plan=restaurant_pro&market=sa&branches=4&terminals=2' => 'Price on request',
            '/ar' => 'السعر عند الطلب',
            '/ar/pricing' => 'السعر عند الطلب',
            '/ar/start-trial?market=sa' => 'السعر عند الطلب',
        ];
        foreach ($pages as $path => $sign) {
            $html = $this->page($path);
            $this->assertStringContainsString($sign, $html, "{$path} says the price is on request");
            foreach (self::FIGURES as $figure) {
                $this->assertStringNotContainsString($figure, $html, "{$path} still shows {$figure}");
            }
            foreach (self::MACHINERY as $bit) {
                $this->assertStringNotContainsString($bit, $html, "{$path} still carries {$bit}");
            }
        }

        $pricing = $this->page('/pricing');
        $this->assertStringContainsString('Plans for every business', $pricing, 'no "transparent pricing" promise while prices are hidden');
        $this->assertStringContainsString('On request', $pricing, 'the plans-at-a-glance box');
        $this->assertStringContainsString('Start 30-Day Trial', $pricing, 'the free trial stays');
        $this->assertStringContainsString('Try Demo', $pricing, 'the demos stay');
        $this->assertStringContainsString('Compare plans by workflow', $pricing, 'the features table stays');
    }

    public function test_signing_up_still_works_and_the_server_still_prices_the_plan(): void
    {
        config(['saas.hide_public_prices' => true]);
        Queue::fake();
        Mail::fake();
        $form = $this->page('/start-trial?plan=restaurant_pro&market=sa');
        $this->assertMatchesRegularExpression('/name="market" value="sa"/', $form, 'the market still goes with the form');

        $code = 'hideprice' . Str::lower(Str::random(6));
        $this->post($this->central('/start-trial'), [
            'business_name' => 'Hidden Price Kitchen', 'tenant_code' => $code,
            'owner_name' => 'Test Owner', 'owner_email' => $code . '@example.test',
            'password' => 'Trial-Secret-4821', 'password_confirmation' => 'Trial-Secret-4821',
            'plan_id' => $this->planIds['restaurant_pro'], 'market' => 'sa',
        ])->assertRedirect($this->central('/trial/success'));

        $tenant = Tenant::where('tenant_code', $code)->firstOrFail();
        $this->assertSame('pending', $tenant->status);
        $sub = DB::connection('master')->table('subscriptions')->where('tenant_id', $tenant->id)->first();
        $this->assertSame('SAR', $sub->currency_code, 'priced in the visitor\'s market, as before');
        $this->assertSame(1, (int) $sub->branches_purchased);
        $this->assertStringContainsString('587', (string) $sub->price_snapshot, 'the server recorded the real price');
    }
}
