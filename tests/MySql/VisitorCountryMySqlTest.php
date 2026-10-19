<?php

namespace Tests\MySql;

use App\Models\Master\Plan;
use App\Models\Master\PlanFeature;
use App\Models\Master\PlanPrice;
use App\Services\Saas\VisitorCountry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WEBSITE-I18N-GEO-1 P4 — a visitor's country picks their first language and their prices.
 *
 * A first visit from Saudi Arabia lands on the Arabic site and sees SAR per branch; from the UAE or
 * Qatar only when the browser itself prefers Arabic. A choice (the language switcher, the currency
 * picker) always wins and is remembered. Search engines, link previews and form posts are never
 * redirected. With no country database the site behaves exactly as it did in P3.
 */
class VisitorCountryMySqlTest extends MySqlTenantTestCase
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

    /** Every address now answers $country — only the lookup is faked; the bot and Cloudflare rules stay real. */
    private function visitorFrom(?string $country): void
    {
        $this->app->instance(VisitorCountry::class, new class($country) extends VisitorCountry {
            public function __construct(private ?string $fakeCountry) {}

            public function lookup(string $ip): ?string
            {
                return $this->fakeCountry;
            }
        });
    }

    private function restaurantPlans(): void
    {
        foreach ([['restaurant_starter', 7000, 219, 59], ['restaurant_pro', 15000, 549, 169]] as [$code, $pkr, $sar, $usd]) {
            DB::connection('master')->table('plans')->where('code', $code)->delete();
            $plan = Plan::create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)), 'price' => $pkr, 'currency_code' => 'PKR',
                'billing_period' => 'monthly', 'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30,
                'monthly_price' => $pkr, 'yearly_price' => $pkr * 10]);
            $this->planIds[] = $plan->id;
            foreach (['branch_limit' => 1, 'terminals_per_branch' => 2, 'users_per_branch' => 8, 'product_limit' => 1000] as $k => $v) {
                PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => $k, 'feature_value' => (string) $v]);
            }
            PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'PKR', 'pricing_model' => 'bundle', 'monthly_price' => $pkr, 'yearly_price' => $pkr * 10]);
            PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'SAR', 'pricing_model' => 'per_branch', 'monthly_price' => $sar, 'extra_terminal_monthly' => 59]);
            PlanPrice::create(['plan_id' => $plan->id, 'currency_code' => 'USD', 'pricing_model' => 'per_branch', 'monthly_price' => $usd, 'extra_terminal_monthly' => 19]);
        }
    }

    public function test_a_first_visit_from_saudi_opens_the_arabic_page_it_asked_for(): void
    {
        $this->visitorFrom('SA');
        $this->get($this->central('/pricing'))->assertRedirect($this->central('/ar/pricing'))->assertCookie('bingoo_lang', 'ar');
        $this->get($this->central('/start-trial?plan=restaurant_pro&billing=yearly'))
            ->assertRedirect($this->central('/ar/start-trial?billing=yearly&plan=restaurant_pro'));
        $this->get($this->central('/'))->assertRedirect($this->central('/ar'));
        $this->get($this->central('/ar/pricing'))->assertOk(); // already Arabic: no loop
        $this->get($this->central('/ar/pricing?lang=ar'))->assertOk()->assertCookie('bingoo_lang', 'ar');
    }

    public function test_a_chosen_language_always_wins_and_is_remembered(): void
    {
        $this->visitorFrom('SA');
        $this->withCookie('bingoo_lang', 'en')->get($this->central('/pricing'))->assertOk();
        // The switcher marks the click as a choice (?lang=), so English sticks for a Saudi visitor.
        $this->get($this->central('/pricing?lang=en'))->assertOk()->assertCookie('bingoo_lang', 'en');
        $html = $this->withCookie('bingoo_lang', 'en')->get($this->central('/pricing'))->getContent();
        $this->assertMatchesRegularExpression('/id="lang-switch-ar" href="[^"]*[?&]lang=ar"/', $html, 'the switcher says it is a choice');
    }

    public function test_search_engines_previews_and_posts_are_never_redirected(): void
    {
        $this->visitorFrom('SA');
        foreach (['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'facebookexternalhit/1.1', 'WhatsApp/2.23'] as $agent) {
            $this->withHeader('User-Agent', $agent)->get($this->central('/pricing'))->assertOk();
        }
        $this->flushHeaders();
        $post = $this->post($this->central('/start-trial'), []);
        $this->assertStringNotContainsString('/ar/', (string) $post->headers->get('Location'), 'a form post is answered in its own language');
    }

    public function test_the_uae_and_qatar_follow_the_browser(): void
    {
        $this->visitorFrom('AE');
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9,ar;q=0.5')->get($this->central('/pricing'))->assertOk();
        $this->withHeader('Accept-Language', 'ar-AE,ar;q=0.9,en;q=0.5')->get($this->central('/pricing'))->assertRedirect($this->central('/ar/pricing'));
        $this->visitorFrom('QA');
        $this->withHeader('Accept-Language', 'ar')->get($this->central('/features'))->assertRedirect($this->central('/ar/features'));
    }

    public function test_pakistan_and_unknown_visitors_stay_where_they_are(): void
    {
        foreach (['PK', 'US', null] as $country) {
            $this->visitorFrom($country);
            $this->get($this->central('/pricing'))->assertOk();
        }
    }

    public function test_the_country_picks_the_prices_and_a_choice_still_wins(): void
    {
        $this->restaurantPlans();

        $this->visitorFrom('SA');
        $html = $this->withCookie('bingoo_lang', 'en')->get($this->central('/pricing'))->getContent();
        $this->assertStringContainsString("\u{2068}SAR 549\u{2069}", $html, 'a Saudi visitor reading English still sees Riyal');

        $this->visitorFrom('GB');
        $this->assertStringContainsString("\u{2068}\$169\u{2069}", $this->get($this->central('/pricing'))->getContent(), 'the rest of the world: USD per branch');

        $this->visitorFrom('PK');
        $html = $this->get($this->central('/pricing'))->getContent();
        $this->assertStringContainsString('PKR 15,000', $html);
        $this->assertStringNotContainsString('id="build"', $html);

        $this->visitorFrom('SA');
        $this->assertStringContainsString('PKR 15,000', $this->withCookie('bingoo_lang', 'en')->get($this->central('/pricing?market=pk'))->getContent(), 'the currency picker beats the country');

        // Google crawls from the US: the English page must be indexed with the Pakistan prices, not dollars.
        $this->visitorFrom('US');
        $html = $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->get($this->central('/pricing'))->getContent();
        $this->assertStringContainsString('PKR 15,000', $html, 'a crawler is from nowhere');
        $this->assertStringNotContainsString('id="build"', $html);
    }

    public function test_cloudflare_country_header_only_when_trusted(): void
    {
        $request = Request::create('/pricing', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'SA', 'REMOTE_ADDR' => '127.0.0.1']);

        config(['saas.geoip.trust_cloudflare_header' => false]);
        $this->assertNull((new VisitorCountry)->of($request), 'anyone can send that header — ignored unless Cloudflare is really in front');

        config(['saas.geoip.trust_cloudflare_header' => true]);
        $this->assertSame('SA', (new VisitorCountry)->of($request));
        $request->headers->set('CF-IPCountry', 'XX');
        $this->assertNull((new VisitorCountry)->of($request), 'XX = Cloudflare does not know either');
        $request->headers->set('CF-IPCountry', 'SA');
        $request->headers->set('User-Agent', 'Googlebot/2.1');
        $this->assertNull((new VisitorCountry)->of($request), 'a crawler is from nowhere, even behind Cloudflare');
    }

    public function test_the_country_database_file(): void
    {
        config(['saas.geoip.path' => storage_path('app/geoip/does-not-exist.mmdb')]);
        $missing = new VisitorCountry;
        $this->assertNull($missing->lookup('8.8.8.8'), 'no file = unknown, never an error');

        $real = storage_path('app/geoip/dbip-country-lite.mmdb');
        if (! is_file($real)) {
            $this->markTestSkipped('run php artisan geoip:update to check the real file');
        }
        config(['saas.geoip.path' => $real]);
        $geo = new VisitorCountry;
        $this->assertSame('US', $geo->lookup('8.8.8.8'));
        $this->assertNull($geo->lookup('127.0.0.1'), 'a local address is unknown');
        $this->assertNull($geo->lookup('192.168.1.5'));
    }
}
