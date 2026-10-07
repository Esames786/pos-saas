<?php

namespace Tests\MySql;

use App\Jobs\Saas\ProvisionTrialWorkspaceJob;
use App\Jobs\Saas\SendTrialReadyMailJob;
use App\Mail\TrialWorkspaceCreatedMail;
use App\Mail\TrialWorkspaceFailedMail;
use App\Mail\TrialWorkspacePreparingMail;
use App\Models\Master\Plan;
use App\Models\Master\Tenant;
use App\Services\Saas\SelfSignupService;
use App\Services\Saas\TrialHttpsProbe;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySql\Support\ParsesInlineScripts;

/**
 * TRIAL-SIGNUP-QUEUE-1 — the public "Start Free Trial" answers at once; the workspace is built on
 * the queue worker; the customer is emailed at once, and again when the login link really works.
 *
 * Measured on prod, 7 Oct: one request created the database, ran every tenant migration, seeded
 * the owner and only then sent the first email — the browser spun for 70 seconds. Before this
 * there was no signup test at all.
 */
class TrialSignupQueueMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;

    private const PASSWORD = 'Trial-Secret-4821';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('master');
        $this->dropTestSignups();
        $this->plan = Plan::create([
            'code' => 'trialtest-' . uniqid(), 'name' => 'Trial Test Plan', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'is_active' => true, 'is_public' => true, 'is_custom' => false, 'trial_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTestSignups();
        parent::tearDown();
    }

    /** Master rows, queued jobs and tenant databases this class made. Left behind they break later suites. */
    private function dropTestSignups(): void
    {
        $master = DB::connection('master');
        $ids = $master->table('tenants')->where('tenant_code', 'like', 'trialtest%')->pluck('id');
        $master->table('tenant_databases')->whereIn('tenant_id', $ids)->delete();
        $master->table('tenant_domains')->whereIn('tenant_id', $ids)->delete();
        $master->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
        $master->table('tenants')->whereIn('id', $ids)->delete();
        $master->table('plans')->where('code', 'like', 'trialtest-%')->delete();
        $master->table('jobs')->where('payload', 'like', '%trialtest%')->delete();
        foreach ($master->select("SHOW DATABASES LIKE 'pos\\_tenant\\_trialtest%'") as $row) {
            $master->statement('DROP DATABASE IF EXISTS `' . array_values((array) $row)[0] . '`');
        }
    }

    private function central(string $path): string
    {
        return 'http://' . config('tenancy.central_domain') . $path;
    }

    private function signupForm(string $code): array
    {
        return [
            'business_name' => 'Trial Test Kitchen', 'tenant_code' => $code,
            'owner_name' => 'Test Owner', 'owner_email' => $code . '@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'plan_id' => $this->plan->id,
        ];
    }

    private function newCode(): string
    {
        return 'trialtest' . Str::lower(Str::random(6));
    }

    private function databaseExists(string $code): bool
    {
        return DB::connection('master')->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', 'pos_tenant_' . $code)->exists();
    }

    private function pendingTenant(string $code): Tenant
    {
        return app(SelfSignupService::class)->createPendingTrial($this->signupForm($code));
    }

    private function fakeProbe(bool $answer): object
    {
        $probe = new class extends TrialHttpsProbe {
            public bool $answer = false;
            public int $calls = 0;

            public function serves(string $host, int $timeoutSeconds = 5): bool
            {
                $this->calls++;

                return $this->answer;
            }
        };
        $probe->answer = $answer;
        $this->app->instance(TrialHttpsProbe::class, $probe);

        return $probe;
    }

    public function test_the_signup_answers_at_once_emails_first_and_builds_nothing_in_the_request(): void
    {
        // The real queue the server runs: a row per job in the master `jobs` table, worked in id order.
        config(['queue.default' => 'database']);
        $code = $this->newCode();
        $lastJob = (int) DB::connection('master')->table('jobs')->max('id');

        $this->post($this->central('/start-trial'), $this->signupForm($code))
            ->assertRedirect($this->central('/trial/success'));

        $tenant = Tenant::where('tenant_code', $code)->firstOrFail();
        $this->assertSame('pending', $tenant->status);
        $this->assertSame(1, $tenant->domains()->count());
        $this->assertSame('trial', $tenant->subscription->status);
        $this->assertFalse($this->databaseExists($code), 'no database is built inside the request');

        $rows = DB::connection('master')->table('jobs')->where('id', '>', $lastJob)->orderBy('id')->get();
        $this->assertSame(
            [TrialWorkspacePreparingMail::class, ProvisionTrialWorkspaceJob::class],
            $rows->map(fn ($r) => json_decode($r->payload, true)['displayName'])->all(),
            'the "setting up" email is queued FIRST — one worker, and the build behind it takes a minute'
        );

        foreach ($rows as $row) {
            $this->assertStringNotContainsString(self::PASSWORD, $row->payload, 'the plain password never sits in the jobs table');
        }
        $job = unserialize(json_decode($rows[1]->payload, true)['data']['command']);
        $this->assertSame($tenant->id, $job->tenantId);
        $this->assertTrue(Hash::check(self::PASSWORD, $job->ownerPasswordHash), 'the job carries a hash of the password the customer chose');
        $this->assertSame('http://' . $code . '.' . config('tenancy.tenant_base_domain') . '/login', $job->loginUrl);
    }

    public function test_the_build_job_makes_a_working_workspace_and_leaves_the_worker_clean(): void
    {
        Queue::fake();
        $code = $this->newCode();
        $tenant = $this->pendingTenant($code);
        $tenantConnectionBefore = config('database.connections.tenant');
        $loginUrl = 'https://' . $code . '.example.test/login';

        // The real provisioner, the real migrations, a real database.
        (new ProvisionTrialWorkspaceJob($tenant->id, Hash::make(self::PASSWORD), $loginUrl, 'Trial Test Kitchen', $code . '@example.test'))
            ->handle(app(SelfSignupService::class));

        $this->assertSame('active', $tenant->fresh()->status);
        $this->assertTrue($this->databaseExists($code));
        $hash = DB::connection('master')->table('pos_tenant_' . $code . '.users')->where('email', $code . '@example.test')->value('password');
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $hash), 'the owner signs in with the password typed at signup — hashed once, not twice');

        $this->assertSame($tenantConnectionBefore, config('database.connections.tenant'),
            'the worker serves every tenant: the tenant connection must be put back');
        $this->assertSame('master', DB::getDefaultConnection());
        Queue::assertPushed(SendTrialReadyMailJob::class, fn ($j) => $j->tenantId === $tenant->id && $j->loginUrl === $loginUrl);
    }

    public function test_a_failed_build_is_removed_and_the_customer_is_told(): void
    {
        Mail::fake();
        Queue::fake();
        $code = $this->newCode();
        $tenant = $this->pendingTenant($code);
        DB::connection('master')->statement('CREATE DATABASE `pos_tenant_' . $code . '`');   // a half-made workspace
        $this->mock(TenantProvisioner::class, fn ($m) => $m->shouldReceive('provisionTenant')->andThrow(new RuntimeException('disk full')));

        $job = new ProvisionTrialWorkspaceJob($tenant->id, Hash::make(self::PASSWORD), 'https://x.example.test/login', 'Trial Test Kitchen', $code . '@example.test');
        try {
            $job->handle(app(SelfSignupService::class));
            $this->fail('the build was meant to fail');
        } catch (RuntimeException $e) {
            $job->failed($e);   // what the worker does next
        }

        $this->assertNull(Tenant::find($tenant->id), 'master rows removed — the address is free to try again');
        $this->assertFalse($this->databaseExists($code), 'the half-made database is dropped');
        Mail::assertSent(TrialWorkspaceFailedMail::class, fn ($m) => $m->hasTo($code . '@example.test'));
        Queue::assertNotPushed(SendTrialReadyMailJob::class);
    }

    public function test_failed_never_removes_a_workspace_that_finished(): void
    {
        Mail::fake();
        $tenant = $this->pendingTenant($this->newCode());
        $tenant->update(['status' => 'active']);

        (new ProvisionTrialWorkspaceJob($tenant->id, 'x', 'https://x/login', 'Trial Test Kitchen', 'o@example.test'))
            ->failed(new RuntimeException('mail server down after the build'));

        $this->assertNotNull(Tenant::find($tenant->id));
        Mail::assertNothingSent();
    }

    public function test_the_ready_email_waits_for_https_but_never_forever(): void
    {
        Mail::fake();
        $tenant = $this->pendingTenant($this->newCode());
        $tenant->update(['status' => 'active']);
        $https = 'https://' . $tenant->tenant_code . '.example.test/login';

        $probe = $this->fakeProbe(false);
        $job = (new SendTrialReadyMailJob($tenant->id, $https))->withFakeQueueInteractions();
        $job->handle($probe);
        $job->assertReleased(SendTrialReadyMailJob::RECHECK_SECONDS);
        Mail::assertNothingSent();

        $job = (new SendTrialReadyMailJob($tenant->id, $https))->withFakeQueueInteractions();
        $job->sendAnywayAt = now()->subSecond()->timestamp;   // 15 minutes are up
        $job->handle($probe);
        $job->assertNotReleased();
        Mail::assertSent(TrialWorkspaceCreatedMail::class, 1);

        $probe->answer = true;
        $job = (new SendTrialReadyMailJob($tenant->id, $https))->withFakeQueueInteractions();
        $job->handle($probe);
        $job->assertNotReleased();
        Mail::assertSent(TrialWorkspaceCreatedMail::class, fn ($m) => $m->loginUrl === $https && $m->hasTo($tenant->owner_email));

        $calls = $probe->calls;
        $job = (new SendTrialReadyMailJob($tenant->id, 'http://' . $tenant->tenant_code . '.test/login'))->withFakeQueueInteractions();
        $job->handle($probe);
        $this->assertSame($calls, $probe->calls, 'plain http (local) has no certificate to wait for');
        Mail::assertSent(TrialWorkspaceCreatedMail::class, 3);
    }

    public function test_the_page_follows_the_build_and_only_this_sessions_signup(): void
    {
        Queue::fake();
        Mail::fake();
        $code = $this->newCode();
        $this->post($this->central('/start-trial'), $this->signupForm($code));
        $tenant = Tenant::where('tenant_code', $code)->firstOrFail();

        $html = $this->get($this->central('/trial/success'))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="preparing"', $html);
        $this->assertStringContainsString('Trial Test Kitchen', $html);
        $this->assertInlineScriptsParse($html, 'trial success', 'data-status-url');
        Mail::assertQueued(TrialWorkspacePreparingMail::class, fn ($m) => $m->hasTo($code . '@example.test'));

        $status = fn () => $this->getJson($this->central('/trial/status'))->json('state');
        $this->assertSame('preparing', $status());

        $tenant->update(['status' => 'active']);
        $this->assertSame('ready', $status(), 'local http: built is ready');

        $https = 'https://' . $code . '.example.test/login';
        $this->withSession(['trial_signup' => array_merge(session('trial_signup'), ['login_url' => $https])]);
        $probe = $this->fakeProbe(false);
        Cache::flush();
        $this->assertSame('securing', $status(), 'built, but the browser would warn — not ready yet');
        $probe->answer = true;
        Cache::flush();
        $this->assertSame('ready', $this->getJson($this->central('/trial/status'))->assertJson(['login_url' => $https])->json('state'));

        $tenant->delete();
        $this->assertSame('failed', $status(), 'a failed build removes the tenant');

        $this->flushSession();
        $this->getJson($this->central('/trial/status'))->assertNotFound();
        $this->get($this->central('/trial/success'))->assertRedirect($this->central('/pricing'));
    }
}
