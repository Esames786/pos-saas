<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\User;
use App\Services\Finance\JournalService;
use App\Services\Finance\ManualJournalReversalRefused;
use App\Services\Finance\ManualJournalRules;
use App\Services\Finance\ManualJournalService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * OFFLINE EDGE — D1 (owner decision, 20 Oct 2026): the Online side of the ONE shared manual-journal rule set.
 *
 * "Move canonical's manual-journal guards and reverse-lock behaviour into the ONE shared journal service. Cloud and
 * Edge must consume the same business rule." — ManualJournalService::post()/reverse() + ManualJournalRules are that
 * service; ManualJournalController is a thin caller. The canonical HTTP behaviour (ManualJournalCashLineGuardMySqlTest,
 * CashBookVoidMySqlTest) stays green unchanged; THIS class proves the owner's cases on the Online path:
 *
 *   allowed reversal · refused reversal · already-reversed protection · authority restrictions · reverse replay,
 *   and that the SERVICE (not the controller) is where R1–R3 / R5 are enforced.
 *
 * Fixture = ManualJournalCashLineGuardMySqlTest's (drawer "Cash in Hand" → 1110, drawings 3000-1 under Equity).
 */
class ManualJournalSharedRulesOnlineMySqlTest extends MySqlTenantTestCase
{
    private const OPENING = 100000.0;

    private int $tenantId;
    private int $ownerId;
    private int $clerkId;       // index + show only — may neither post nor reverse
    private int $drawerId;
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $this->host = 'mjshared.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->bootTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'mjshared')->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    // ── allowed reversal ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_online_allowed_reversal_posts_the_gl_reversal_and_the_cash_counter_movement_once(): void
    {
        $this->postJournal([
            ['code' => '3000-1', 'debit' => 5000, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 5000, 'drawer' => $this->drawerId],
        ])->assertSessionHasNoErrors();
        $entry = JournalEntry::where('source_type', 'manual_journal')->sole();
        $this->assertSame(self::OPENING - 5000, $this->drawerBalance());

        $res = $this->actingAs($this->owner(), 'tenant')->post($this->reverseUrl($entry), ['reason' => 'posted on the wrong partner']);

        $reversal = JournalEntry::where('reversed_entry_id', $entry->id)->sole();
        $res->assertRedirect(url('/finance/manual-journals/' . $reversal->id));
        $this->assertTrue((bool) $reversal->is_reversal);
        $this->assertSame('manual_journal_reversal', $reversal->source_type);
        $this->assertStringContainsString('posted on the wrong partner', (string) $reversal->description);
        $rev = CashBankAccountTransaction::where('reference_type', 'manual_journal_reversal')->sole();
        $this->assertSame('in', $rev->direction, 'the drawing went OUT, the reversal comes back IN');
        $this->assertSame(5000.0, (float) $rev->amount);
        $this->assertSame(self::OPENING, $this->drawerBalance(), 'drawer back where it was');
    }

    // ── the service is the enforcement point ────────────────────────────────────────────────────────────────────

    public function test_the_service_enforces_r1_r2_r3_r5_without_the_controller(): void
    {
        $svc = app(ManualJournalService::class);
        $post = fn (array $lines, string $description = 'Partner A drawing') => $svc->post([
            'entry_date' => '2026-10-01', 'description' => $description, 'reference_no' => null, 'lines' => $lines,
        ], $this->ownerId);
        $refused = function (callable $fn, string $key, string $needle): void {
            try {
                $fn();
                $this->fail("expected the service to refuse [{$key}]");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
                $this->assertStringContainsString($needle, $e->errors()[$key][0]);
            }
        };

        // R1 — drawer on the drawing line.
        $refused(fn () => $post([
            ['account_id' => $this->accId('3000-1'), 'debit' => 500, 'credit' => 0, 'cash_bank_account_id' => $this->drawerId],
            ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => 500],
        ]), 'lines.0.cash_bank_account_id', 'Cash in Hand is linked to 1110 Main Cash Drawer. Choose it on the 1110 line, not on 3000-1 PARTNER A DRAWING.');
        // R3 — group head 1100.
        $refused(fn () => $post([
            ['account_id' => $this->accId('3000-1'), 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->accId('1100'), 'debit' => 0, 'credit' => 500],
        ]), 'lines.1.account_id', '1100 Cash on Hand is a group. Choose an account under it, e.g. 1110 Main Cash Drawer.');
        // R5 — narration.
        $refused(fn () => $post([
            ['account_id' => $this->accId('3000-1'), 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => 500, 'cash_bank_account_id' => $this->drawerId],
        ], '.'), 'description', ManualJournalRules::NARRATION_MESSAGE);
        // AP rule — an Accounts Payable line without its supplier.
        $refused(fn () => $post([
            ['account_id' => $this->accId('3000-1'), 'debit' => 500, 'credit' => 0],
            ['account_id' => $this->accId('2100'), 'debit' => 0, 'credit' => 500],
        ]), 'lines.1.supplier_id', 'must name the supplier');
        $this->assertNothingWritten();

        // R2 — the single active drawer on 1110 is auto-filled by the service itself; the Cash Book moves.
        $entry = $post([
            ['account_id' => $this->accId('3000-1'), 'debit' => 700, 'credit' => 0],
            ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => 700],
        ]);
        $txn = CashBankAccountTransaction::where('reference_type', 'manual_journal')->where('reference_id', $entry->id)->sole();
        $this->assertSame($this->drawerId, (int) $txn->cash_bank_account_id);
        $this->assertSame('out', $txn->direction);
        $this->assertSame(self::OPENING - 700, $this->drawerBalance());

        // R2 — two active drawers on 1110 → the service refuses; nothing more is written.
        CashBankAccount::create(['code' => 'CASH-2', 'name' => 'Second Drawer', 'account_type' => 'cash', 'account_id' => $this->accId('1110'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true]);
        $refused(fn () => $post([
            ['account_id' => $this->accId('3000-1'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => 100],
        ]), 'lines.1.cash_bank_account_id', 'is used by more than one cash/bank account (Cash in Hand, Second Drawer)');
        $this->assertSame(1, JournalEntry::where('source_type', 'manual_journal')->count());
    }

    // ── refused reversal ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_refused_reversal_not_a_manual_journal_not_posted_or_itself_a_reversal(): void
    {
        $svc = app(ManualJournalService::class);
        $refusedWith = function (JournalEntry $entry, string $reason) use ($svc): void {
            try {
                $svc->reverse($entry, 'x', $this->ownerId);
                $this->fail("expected [{$reason}]");
            } catch (ManualJournalReversalRefused $e) {
                $this->assertSame($reason, $e->reason);
            }
        };

        // Not a manual journal (a system journal) → service NOT_MANUAL_JOURNAL, route 404.
        $system = app(JournalService::class)->post(sourceType: 'supplier_payment', sourceId: 1, sourceNo: null, description: 'system posting',
            entryDate: '2026-10-01', lines: [
                ['account_id' => $this->accId('2100'), 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => 100],
            ], userId: $this->ownerId);
        $refusedWith($system, ManualJournalReversalRefused::NOT_MANUAL_JOURNAL);
        $this->actingAs($this->owner(), 'tenant')->post($this->reverseUrl($system), ['reason' => 'x'])->assertNotFound();

        // Not posted → NOT_POSTED, route 422.
        $draft = $this->manualJournal(300);
        JournalEntry::whereKey($draft->id)->update(['status' => 'draft']);
        $refusedWith($draft->fresh(), ManualJournalReversalRefused::NOT_POSTED);
        $this->actingAs($this->owner(), 'tenant')->post($this->reverseUrl($draft), ['reason' => 'x'])->assertStatus(422);

        // A reversal itself → the canonical order says source_type first: NOT_MANUAL_JOURNAL, route 404.
        $original = $this->manualJournal(400);
        $reversal = $svc->reverse($original, 'first', $this->ownerId);
        $refusedWith($reversal, ManualJournalReversalRefused::NOT_MANUAL_JOURNAL);
        $this->actingAs($this->owner(), 'tenant')->post($this->reverseUrl($reversal), ['reason' => 'x'])->assertNotFound();

        $this->assertSame(1, JournalEntry::where('is_reversal', true)->count(), 'exactly the one legitimate reversal');
        $this->assertSame(0, JournalEntry::where('reversed_entry_id', $system->id)->count());
    }

    // ── already-reversed protection + replay ─────────────────────────────────────────────────────────────────────

    public function test_already_reversed_a_second_reverse_posts_nothing_on_the_route_and_in_the_service(): void
    {
        $entry = $this->manualJournal(1620);
        $svc = app(ManualJournalService::class);

        $first = $this->actingAs($this->owner(), 'tenant')->post($this->reverseUrl($entry), ['reason' => 'x', '_submit_token' => (string) Str::uuid()]);
        $reversal = JournalEntry::where('reversed_entry_id', $entry->id)->sole();
        $first->assertRedirect(url('/finance/manual-journals/' . $reversal->id));

        // Route replay (another tab, a new token) → the canonical B3 redirect, nothing posted.
        $second = $this->actingAs($this->owner(), 'tenant')->post($this->reverseUrl($entry), ['reason' => 'x', '_submit_token' => (string) Str::uuid()]);
        $second->assertRedirect(url('/finance/manual-journals/' . $reversal->id));
        $this->assertSame($entry->entry_no . ' was already reversed by ' . $reversal->entry_no . ' — nothing was posted again.', session('status'));

        // Service replay → ALREADY_REVERSED carrying THE reversal; still nothing posted.
        try {
            $svc->reverse($entry->fresh(), 'again', $this->ownerId);
            $this->fail('a replayed reversal must be refused');
        } catch (ManualJournalReversalRefused $e) {
            $this->assertSame(ManualJournalReversalRefused::ALREADY_REVERSED, $e->reason);
            $this->assertSame($reversal->id, $e->existingReversal?->id);
        }
        $this->assertSame($reversal->id, $svc->reversalOf($entry)?->id);

        $this->assertSame(1, JournalEntry::where('reversed_entry_id', $entry->id)->count());
        $this->assertSame(1, CashBankAccountTransaction::where('reference_type', 'manual_journal_reversal')->count());
        $this->assertSame(self::OPENING, $this->drawerBalance(), 'the drawer came back exactly once');
    }

    // ── authority restrictions ───────────────────────────────────────────────────────────────────────────────────

    public function test_store_and_reverse_require_their_route_permissions(): void
    {
        $entry = $this->manualJournal(900);
        $clerk = User::on('tenant')->find($this->clerkId);
        $this->assertTrue($clerk->can('tenant.finance.manual-journals.index'));
        $this->assertFalse($clerk->can('tenant.finance.manual-journals.store'));
        $this->assertFalse($clerk->can('tenant.finance.manual-journals.reverse'));

        $this->actingAs($clerk, 'tenant')->post('http://' . $this->host . '/finance/manual-journals', $this->payload([
            ['code' => '3000-1', 'debit' => 50, 'credit' => 0],
            ['code' => '1110', 'debit' => 0, 'credit' => 50, 'drawer' => $this->drawerId],
        ]))->assertForbidden();
        $this->actingAs($clerk, 'tenant')->post($this->reverseUrl($entry), ['reason' => 'x'])->assertForbidden();

        $this->assertSame(1, JournalEntry::where('source_type', 'manual_journal')->count(), 'the clerk posted nothing');
        $this->assertSame(0, JournalEntry::where('reversed_entry_id', $entry->id)->count(), 'the clerk reversed nothing');
        $this->assertSame(self::OPENING - 900, $this->drawerBalance());

        // The clerk may still read.
        $this->actingAs($clerk, 'tenant')->get('http://' . $this->host . '/finance/manual-journals/' . $entry->id)->assertOk();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function manualJournal(float $amount): JournalEntry
    {
        return app(ManualJournalService::class)->post([
            'entry_date' => '2026-10-01', 'description' => 'Partner A drawing', 'reference_no' => null, 'lines' => [
                ['account_id' => $this->accId('3000-1'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => $amount, 'cash_bank_account_id' => $this->drawerId],
            ],
        ], $this->ownerId);
    }

    private function reverseUrl(JournalEntry $entry): string
    {
        return 'http://' . $this->host . '/finance/manual-journals/' . $entry->id . '/reverse';
    }

    private function payload(array $lines, string $description = 'Partner A drawing'): array
    {
        $payload = ['entry_date' => '2026-10-01', 'description' => $description, 'reference_no' => '', 'lines' => []];
        foreach ($lines as $l) {
            $row = ['account_id' => $this->accId($l['code']), 'debit' => $l['debit'], 'credit' => $l['credit']];
            if (! empty($l['drawer'])) {
                $row['cash_bank_account_id'] = $l['drawer'];
            }
            $payload['lines'][] = $row;
        }

        return $payload;
    }

    private function postJournal(array $lines, string $description = 'Partner A drawing')
    {
        return $this->actingAs($this->owner(), 'tenant')->post('http://' . $this->host . '/finance/manual-journals', $this->payload($lines, $description));
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, JournalEntry::where('source_type', 'manual_journal')->count());
        $this->assertSame(0, CashBankAccountTransaction::count());
        $this->assertSame(self::OPENING, $this->drawerBalance());
    }

    private function drawerBalance(): float
    {
        return (float) CashBankAccount::find($this->drawerId)->current_balance;
    }

    private function owner(): User
    {
        return User::on('tenant')->find($this->ownerId);
    }

    private function accId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'mjshared')->delete();
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'mjshared', 'business_name' => 'Shared Journal Rules Kitchen', 'owner_name' => 'Owner', 'owner_email' => 'owner@mjshared.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert(['tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedSubscription(): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'mjshared-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'mjshared-plan', 'name' => 'Shared Journal Rules', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active', 'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'supplier_ledgers', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'model_has_roles', 'model_has_permissions', 'users', 'accounts', 'branches']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new DefaultChartOfAccountsSeeder)->run();
        Account::create(['code' => '3000-1', 'name' => 'PARTNER A DRAWING', 'type' => 'equity', 'normal_balance' => Account::normalBalanceForType('equity'),
            'parent_id' => $this->accId('3000'), 'is_active' => true, 'sort_order' => 999]);
        $this->drawerId = CashBankAccount::create(['code' => 'HAND-CASH', 'name' => 'Cash in Hand', 'account_type' => 'cash', 'account_id' => $this->accId('1110'),
            'opening_balance' => self::OPENING, 'current_balance' => self::OPENING, 'is_active' => true])->id;

        $c = DB::connection('tenant');
        $all = ['tenant.finance.manual-journals.index', 'tenant.finance.manual-journals.create', 'tenant.finance.manual-journals.store',
            'tenant.finance.manual-journals.show', 'tenant.finance.manual-journals.reverse'];
        foreach ($all as $permName) {
            $c->table('permissions')->updateOrInsert(['name' => $permName, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }
        $this->ownerId = $c->table('users')->insertGetId(['name' => 'Shared Owner', 'email' => 'mjshared-owner@test.local', 'password' => bcrypt('x'),
            'employee_code' => 'MJSOWN', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('model_has_roles')->insert(['role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId]);

        // The clerk holds index + show DIRECTLY (no role): may look, may neither post nor reverse.
        $this->clerkId = $c->table('users')->insertGetId(['name' => 'Shared Clerk', 'email' => 'mjshared-clerk@test.local', 'password' => bcrypt('x'),
            'employee_code' => 'MJSCLK', 'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['tenant.finance.manual-journals.index', 'tenant.finance.manual-journals.show'] as $permName) {
            $permId = (int) $c->table('permissions')->where('name', $permName)->where('guard_name', 'tenant')->value('id');
            $c->table('model_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->clerkId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
