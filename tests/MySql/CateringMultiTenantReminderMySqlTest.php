<?php

namespace Tests\MySql;

use App\Mail\Catering\CateringCustomerMail;
use App\Models\Master\Module;
use App\Models\Master\Plan;
use App\Models\Master\PlanModule;
use App\Models\Master\Subscription;
use App\Models\Master\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PDO;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-V1-CLOSURE-1 (§8): the scheduled reminder command across TWO real
 * tenant databases. Proofs: only the entitled tenant with a due event gets its
 * email, no first-tenant context leaks into the second tenant's database,
 * TenancyManager deactivates in finally, and a rerun sends nothing twice.
 */
class CateringMultiTenantReminderMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const TENANT_B_DB = 'pos_test_tenant_cat_b';

    private static bool $tenantBSchemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');

        $this->ensureTenantBSchema();

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders',
            'catering_estimate_lines', 'catering_estimates', 'catering_events', 'catering_settings',
        ]);
        $this->cleanTenantB();

        // Master-side scratch: this test owns the tenants table content.
        $master = DB::connection('master');
        $master->table('subscriptions')->delete();
        $master->table('tenant_databases')->delete();
        $master->table('tenants')->delete();
        $master->table('plan_modules')->whereIn('plan_id', $master->table('plans')->where('code', 'like', 'catrem-%')->pluck('id'))->delete();
        $master->table('plans')->where('code', 'like', 'catrem-%')->delete();
    }

    /** Create + migrate the second REAL tenant schema once per process. */
    private function ensureTenantBSchema(): void
    {
        if (self::$tenantBSchemaReady) {
            return;
        }
        if (stripos(self::TENANT_B_DB, 'test') === false) {
            throw new \RuntimeException('tenant B database name must contain "test"');
        }

        $config = config('database.connections.tenant');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.self::TENANT_B_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $mainDb = $config['database'];
        try {
            config(['database.connections.tenant.database' => self::TENANT_B_DB]);
            DB::purge('tenant');
            $code = Artisan::call('migrate:fresh', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
            if ($code !== 0) {
                throw new \RuntimeException('tenant B migrations failed: '.Artisan::output());
            }
        } finally {
            config(['database.connections.tenant.database' => $mainDb]);
            DB::purge('tenant');
        }

        self::$tenantBSchemaReady = true;
    }

    private function onTenantB(callable $callback): mixed
    {
        $mainDb = config('database.connections.tenant.database');
        try {
            config(['database.connections.tenant.database' => self::TENANT_B_DB]);
            DB::purge('tenant');

            return $callback();
        } finally {
            config(['database.connections.tenant.database' => $mainDb]);
            DB::purge('tenant');
        }
    }

    private function cleanTenantB(): void
    {
        $this->onTenantB(function () {
            $this->cleanTenant([
                'catering_email_logs', 'catering_event_reminders',
                'catering_estimate_lines', 'catering_estimates', 'catering_events', 'catering_settings',
            ]);
        });
    }

    private function seedMasterTenant(string $code, string $database, bool $cateringEnabled): Tenant
    {
        $module = Module::updateOrCreate(
            ['key' => 'catering'],
            ['name' => 'Catering & Events', 'category' => 'Operations', 'description' => 'Catering',
                'route_module_keys' => ['tenant.catering'], 'sort_order' => 145, 'is_core' => false, 'is_active' => true]
        );

        $plan = Plan::create(['code' => 'catrem-'.$code, 'name' => 'Plan '.$code, 'price' => 0, 'is_active' => true]);
        PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => $cateringEnabled]);

        $tenant = Tenant::create(['tenant_code' => $code, 'business_name' => 'Tenant '.$code, 'status' => 'active']);
        Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $config = config('database.connections.tenant');
        // Via the model: db_password carries the `encrypted` cast.
        \App\Models\Master\TenantDatabase::create([
            'tenant_id' => $tenant->id, 'db_host' => $config['host'], 'db_port' => $config['port'],
            'db_database' => $database, 'db_username' => $config['username'], 'db_password' => $config['password'] ?? '',
            'migration_status' => 'completed',
        ]);

        return $tenant->fresh();
    }

    private function seedDueEvent(string $recipient, string $customerLabel): void
    {
        $this->tenant()->table('catering_settings')->insert([
            'branch_id' => null, 'reminder_recipient_email' => $recipient,
            'reminder_offsets' => json_encode(['d3']), 'print_language_profile' => 'en',
            'default_service_charge_percent' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->tenant()->table('catering_events')->insert([
            'event_uuid' => strtoupper(bin2hex(random_bytes(13))), 'event_no' => 'EV-TEST-'.uniqid(),
            'customer_name' => $customerLabel, 'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(3)->toDateString(), 'pax' => 100, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_reminders_stay_tenant_isolated_entitlement_gated_and_idempotent(): void
    {
        Mail::fake();

        $mainDb = config('database.connections.tenant.database');
        $this->seedMasterTenant('catrem-a', $mainDb, cateringEnabled: true);
        $this->seedMasterTenant('catrem-b', self::TENANT_B_DB, cateringEnabled: false);

        // Tenant A: due D-3 event. Tenant B: ALSO a due event — but catering is
        // disabled for B, so the command must never even activate its context.
        $this->seedDueEvent('ops-a@bingoo.test', 'Tenant A Customer');
        $this->onTenantB(fn () => $this->seedDueEvent('ops-b@bingoo.test', 'Tenant B Customer'));

        $exitCode = Artisan::call('catering:dispatch-event-reminders');
        $this->assertSame(0, $exitCode, Artisan::output());

        // Only tenant A's recipient got a reminder — exactly one.
        Mail::assertSent(CateringCustomerMail::class, 1);
        Mail::assertSent(CateringCustomerMail::class, fn (CateringCustomerMail $mail) => $mail->hasTo('ops-a@bingoo.test')
            && $mail->event->customer_name === 'Tenant A Customer');
        Mail::assertNotSent(CateringCustomerMail::class, fn (CateringCustomerMail $mail) => $mail->hasTo('ops-b@bingoo.test'));

        // TenancyManager deactivated in finally: default connection is back on master.
        $this->assertSame('master', DB::getDefaultConnection());
        DB::setDefaultConnection('tenant');

        // No cross-tenant DB leak: A has its claim row, B has ZERO rows anywhere.
        $this->assertSame(1, (int) $this->tenant()->table('catering_event_reminders')->whereNotNull('sent_at')->count());
        $this->assertSame(0, $this->onTenantB(fn () => (int) $this->tenant()->table('catering_event_reminders')->count()),
            'a disabled tenant must receive no reminder rows at all');
        $this->assertSame('Tenant A Customer', $this->tenant()->table('catering_events')->value('customer_name'),
            'tenant A rows never bled into another database');
        $this->assertSame('Tenant B Customer', $this->onTenantB(fn () => $this->tenant()->table('catering_events')->value('customer_name')),
            'tenant B rows untouched by tenant A\'s run');

        // Idempotent rerun: nothing new is sent anywhere.
        $rerunExit = Artisan::call('catering:dispatch-event-reminders');
        $this->assertSame(0, $rerunExit);
        Mail::assertSent(CateringCustomerMail::class, 1);
        $this->assertSame(1, (int) $this->tenant()->table('catering_event_reminders')->count());
    }
}
