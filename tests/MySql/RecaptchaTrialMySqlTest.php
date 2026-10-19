<?php

namespace Tests\MySql;

use App\Jobs\Saas\ProvisionTrialWorkspaceJob;
use App\Models\Master\Plan;
use App\Models\Master\Tenant;
use App\Services\Saas\SelfSignupService;
use App\Services\Tenancy\TenancyManager;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * RECAPTCHA-TRIAL-1 — the public Start Trial form asks "I'm not a robot" (Google reCAPTCHA v2).
 *
 * Off until both keys are set: no box, no call to Google, signups exactly as before. On, no signup
 * is created unless Google confirms the tick — a missing tick, a "no", or Google not answering all
 * refuse it, and a refusal Google explains is logged with its reason. The token goes no further
 * than the check. The box speaks the page's language.
 */
class RecaptchaTrialMySqlTest extends MySqlTenantTestCase
{
    private const SITE = 'test-site-key-1234';

    private const SECRET = 'test-secret-key-5678';

    private const TOKEN = 'token-from-the-browser-ABC123';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        config(['saas.public_site_mode' => 'live', 'saas.recaptcha.site_key' => null, 'saas.recaptcha.secret_key' => null]);
        // Only the gate is under test here: no workspace is built, no email leaves.
        Queue::fake();
        Mail::fake();
        $this->dropTestSignups();
        $this->plan = Plan::create([
            'code' => 'recaptest-' . uniqid(), 'name' => 'Recaptcha Test Plan', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTestSignups();
        parent::tearDown();
    }

    private function dropTestSignups(): void
    {
        $master = DB::connection('master');
        $ids = $master->table('tenants')->where('tenant_code', 'like', 'recaptest%')->pluck('id');
        $master->table('tenant_databases')->whereIn('tenant_id', $ids)->delete();
        $master->table('tenant_domains')->whereIn('tenant_id', $ids)->delete();
        $master->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
        $master->table('tenants')->whereIn('id', $ids)->delete();
        $master->table('plans')->where('code', 'like', 'recaptest-%')->delete();
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    private function keysOn(): void
    {
        config(['saas.recaptcha.site_key' => self::SITE, 'saas.recaptcha.secret_key' => self::SECRET]);
    }

    private function form(string $code, ?string $token = null): array
    {
        return array_filter([
            'business_name' => 'Recaptcha Test Kitchen', 'tenant_code' => $code,
            'owner_name' => 'Test Owner', 'owner_email' => $code . '@example.test',
            'password' => 'Trial-Secret-4821', 'password_confirmation' => 'Trial-Secret-4821',
            'plan_id' => $this->plan->id,
            'g-recaptcha-response' => $token,
        ], fn ($v) => $v !== null);
    }

    private function code(): string
    {
        return 'recaptest' . Str::lower(Str::random(6));
    }

    private function googleSays(array $body): void
    {
        Http::fake([config('saas.recaptcha.verify_url') => Http::response($body)]);
    }

    public function test_off_until_both_keys_are_set_and_signups_work_as_before(): void
    {
        Http::fake();
        foreach ([[null, null], [self::SITE, null], [null, self::SECRET]] as [$site, $secret]) {
            config(['saas.recaptcha.site_key' => $site, 'saas.recaptcha.secret_key' => $secret]);
            $html = $this->get($this->central('/start-trial'))->assertOk()->getContent();
            $this->assertStringNotContainsString('g-recaptcha', $html, 'no box while a key is missing');
            $this->assertStringNotContainsString('recaptcha/api.js', $html);
        }

        $code = $this->code();
        $this->post($this->central('/start-trial'), $this->form($code))->assertRedirect($this->central('/trial/success'));
        $this->assertSame('pending', Tenant::where('tenant_code', $code)->value('status'), 'off = signups exactly as before');
        Http::assertNothingSent();
    }

    public function test_the_box_is_on_the_form_in_the_pages_language_and_nowhere_else(): void
    {
        $this->keysOn();
        $html = $this->get($this->central('/start-trial'))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="g-recaptcha" data-sitekey="' . self::SITE . '"></div>', $html);
        $this->assertStringContainsString('<script src="https://www.google.com/recaptcha/api.js?hl=en" async defer></script>', $html);
        $this->assertStringNotContainsString(self::SECRET, $html, 'the secret never reaches a page');
        $this->assertMatchesRegularExpression('/<style>\s*\/\*[^*]*\*\/\s*\.recaptcha-box/s', $html, 'the box fits a small phone');

        $this->assertStringContainsString('recaptcha/api.js?hl=ar', $this->get($this->central('/ar/start-trial'))->assertOk()->getContent());
        $this->assertStringNotContainsString('recaptcha', $this->get($this->central('/pricing'))->assertOk()->getContent(), 'Google loads on the form page only');
    }

    public function test_no_tick_no_signup_and_google_is_not_even_asked(): void
    {
        $this->keysOn();
        Http::fake();
        $code = $this->code();
        $this->from($this->central('/start-trial'))->post($this->central('/start-trial'), $this->form($code))
            ->assertRedirect($this->central('/start-trial'))
            ->assertSessionHasErrors(['g-recaptcha-response' => 'Please tick “I’m not a robot” and try again.']);
        $this->assertFalse(Tenant::where('tenant_code', $code)->exists());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_no_from_google_refuses_and_says_why_in_the_log(): void
    {
        $this->keysOn();
        // The visitor's side failed: a forged, expired or already-used tick.
        $this->googleSays(['success' => false, 'error-codes' => ['invalid-input-response']]);
        Log::spy();
        $code = $this->code();
        $this->post($this->central('/start-trial'), $this->form($code, self::TOKEN))->assertSessionHasErrors('g-recaptcha-response');
        $this->assertFalse(Tenant::where('tenant_code', $code)->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'reCAPTCHA')
            && in_array('invalid-input-response', $context['error_codes'] ?? [], true))->once();
        Queue::assertNothingPushed();
    }

    public function test_a_wrong_secret_key_never_locks_customers_out_but_shouts(): void
    {
        $this->keysOn();
        // OUR key is wrong — the visitor did nothing wrong. Without this, one typo in .env refuses every signup.
        $this->googleSays(['success' => false, 'error-codes' => ['invalid-input-secret']]);
        Log::spy();
        $code = $this->code();
        $this->post($this->central('/start-trial'), $this->form($code, self::TOKEN))->assertRedirect($this->central('/trial/success'));
        $this->assertSame('pending', Tenant::where('tenant_code', $code)->value('status'));
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains($message, 'SECRET key')
            && in_array('invalid-input-secret', $context['error_codes'] ?? [], true))->once();

        // …but a missing tick is still refused: the exception is only for Google rejecting our key.
        $other = $this->code();
        $this->post($this->central('/start-trial'), $this->form($other))->assertSessionHasErrors('g-recaptcha-response');
        $this->assertFalse(Tenant::where('tenant_code', $other)->exists());
    }

    public function test_google_not_answering_refuses_the_signup(): void
    {
        $this->keysOn();
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $code = $this->code();
        $this->post($this->central('/start-trial'), $this->form($code, self::TOKEN))->assertSessionHasErrors('g-recaptcha-response');
        $this->assertFalse(Tenant::where('tenant_code', $code)->exists(), 'fails closed');
    }

    public function test_a_yes_from_google_lets_the_signup_through_and_the_token_goes_no_further(): void
    {
        $this->keysOn();
        $this->googleSays(['success' => true, 'hostname' => config('tenancy.central_domain')]);
        // What the controller hands the real signup service — today's service ignores unknown fields, so
        // only this shows whether the token is handed on at all.
        $signup = new class(app(TenantProvisioner::class), app(TenancyManager::class)) extends SelfSignupService {
            public array $received = [];

            public function createPendingTrial(array $data): Tenant
            {
                $this->received = $data;

                return parent::createPendingTrial($data);
            }
        };
        $this->app->instance(SelfSignupService::class, $signup);
        $code = $this->code();
        $this->post($this->central('/start-trial'), $this->form($code, self::TOKEN))->assertRedirect($this->central('/trial/success'));

        $this->assertSame('pending', Tenant::where('tenant_code', $code)->value('status'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === config('saas.recaptcha.verify_url')
            && $r['secret'] === self::SECRET && $r['response'] === self::TOKEN && $r['remoteip'] === '127.0.0.1');
        Http::assertSentCount(1);
        $this->assertSame($code, $signup->received['tenant_code'] ?? null, 'the real service did the signup');
        $this->assertArrayNotHasKey('g-recaptcha-response', $signup->received, 'the token stops at the check');
        Queue::assertPushed(ProvisionTrialWorkspaceJob::class, fn ($job) => ! str_contains(serialize($job), self::TOKEN));
        $tenantId = Tenant::where('tenant_code', $code)->value('id');
        $saved = json_encode([
            DB::connection('master')->table('tenants')->where('id', $tenantId)->first(),
            DB::connection('master')->table('subscriptions')->where('tenant_id', $tenantId)->get(),
        ]);
        $this->assertStringNotContainsString(self::TOKEN, $saved, 'the token is not stored anywhere');
    }

    public function test_the_refusal_is_in_arabic_on_the_arabic_form(): void
    {
        $this->keysOn();
        Http::fake();
        $html = $this->followingRedirects()->from($this->central('/ar/start-trial'))
            ->post($this->central('/ar/start-trial'), $this->form($this->code()))->assertOk()->getContent();
        $this->assertStringContainsString('يُرجى تحديد مربع «أنا لست برنامج روبوت» ثم المحاولة مرة أخرى.', $html);
    }
}
