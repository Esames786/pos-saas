<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeInboundSupplierFinanceIngestionService;
use App\Services\Edge\EdgeIngestionVerdicts;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use App\Services\Finance\ManualJournalReversalRefused;
use App\Services\Finance\ManualJournalRules;
use App\Services\Finance\ManualJournalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — D1 (owner decision, 20 Oct 2026): the Edge side of the ONE shared manual-journal rule set.
 *
 * The Branch Server's General Journal twin (EdgeLocalSupplierFinanceService::postApJournal) runs ManualJournalRules
 * against its replica chart with the Online messages, so the till cannot build an envelope the Cloud would refuse; the
 * Cloud ingestion (EdgeInboundSupplierFinanceIngestionService → ManualJournalService::guard/post) re-runs the same rules
 * and refuses a violating envelope with the explicit, terminal code JOURNAL_RULE_REFUSED — never silently posting.
 *
 * Owner cases on this side: refused (R1–R3/R5 at the till AND at ingestion) · authority (PERM_JOURNAL, the STANDBY
 * local-mutation gate, the Cloud's ACTOR_UNAUTHORIZED) · replay/idempotency (an envelope applied twice posts once) ·
 * allowed reversal of an ingested journal (Online only — the appliance never reverses a GL entry) · already-reversed.
 *
 * Single database: the Cloud's tenant tables and the appliance replica live side by side (the F2 authority test's
 * posture); till tests run as branch_server, ingestion tests as Cloud.
 */
class EdgeSharedJournalRulesMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeSupplierFinanceFixture;

    private const TENANT_ID = 42;                 // bindEdgeLocalMeta's default tenant
    private const DEVICE_UUID = 'd1-shared-journal-box';

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $cashierId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::SF_EDGE_TABLES, self::SF_TABLES, [
            'edge_sync_outbox', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Finance Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'D1J' . Str::random(4)]);
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'D1C' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->seedCloudSupplierFinance($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, self::TENANT_ID, self::DEVICE_UUID);
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();   // the appliance binds (EdgeBranchContext) only on a branch_server; ingest() flips back to Cloud
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->cashierId, $this->branchId, 1);
        foreach ([EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
        $this->projectSupplierFinanceToAppliance($this->branchId);

        // The Cloud's registration of this appliance (for the ingestion tests): device + activation generation 1.
        $m = DB::connection('master');
        $m->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->delete();
        $m->table('edge_devices')->insert([
            'public_uuid' => self::DEVICE_UUID, 'tenant_id' => self::TENANT_ID, 'branch_id' => $this->branchId,
            'installation_uuid' => (string) Str::uuid(), 'device_secret_hash' => hash('sha256', 'x'),
            'status' => 'active', 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('edge_branch_activations')->where('tenant_id', self::TENANT_ID)->where('branch_id', $this->branchId)->delete();
        $m->table('edge_branch_activations')->insert([
            'tenant_id' => self::TENANT_ID, 'branch_id' => $this->branchId, 'generation' => 1,
            'device_public_uuid' => self::DEVICE_UUID, 'reason' => 'initial', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('master')->table('edge_devices')->where('public_uuid', self::DEVICE_UUID)->delete();
            DB::connection('master')->table('edge_branch_activations')->where('tenant_id', self::TENANT_ID)->where('branch_id', $this->branchId)->delete();
        } catch (\Throwable) {
        }
        config(['edge.authority.heartbeat_url' => null]);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── refused at the till: R1–R3 / R5 with the Online messages ─────────────────────────────────────────────────

    public function test_the_till_refuses_r1_r2_r3_r5_with_the_online_messages_and_autofills_the_single_drawer(): void
    {
        $this->asBranchServerRuntime();
        $ap = $this->accountId('2100');
        $expense = $this->accountId('6100');
        $cash = $this->accountId('1110');
        $group = $this->accountId('1100');

        // R1 — the till drawer on the expense line.
        $this->refuse(fn () => $this->journal([
            ['cloud_account_id' => $ap, 'debit' => 100, 'cloud_supplier_id' => $this->supplierAId],
            ['cloud_account_id' => $expense, 'credit' => 100, 'cloud_cash_bank_account_id' => $this->tillCbId],
        ]), 'Main Till is linked to 1110 Main Cash Drawer. Choose it on the 1110 line, not on 6100 Rent Expense.', 'lines.1.cloud_cash_bank_account_id');
        // R1 — a drawer linked to no chart account.
        $this->refuse(fn () => $this->journal([
            ['cloud_account_id' => $expense, 'debit' => 100],
            ['cloud_account_id' => $cash, 'credit' => 100, 'cloud_cash_bank_account_id' => $this->unmappedCbId],
        ]), 'Unmapped Cash is not linked to any account in the chart of accounts, so it cannot be used on a journal line. Link it on the Cash & Bank screen first.', 'lines.1.cloud_cash_bank_account_id');
        // R3 — group head 1100.
        $this->refuse(fn () => $this->journal([
            ['cloud_account_id' => $expense, 'debit' => 100],
            ['cloud_account_id' => $group, 'credit' => 100],
        ]), '1100 Cash on Hand is a group. Choose an account under it, e.g. 1110 Main Cash Drawer.', 'lines.1.cloud_account_id');
        // R5 — narration.
        foreach (['.', '-', '...', 'ab'] as $bad) {
            $this->refuse(fn () => $this->journal([
                ['cloud_account_id' => $expense, 'debit' => 100],
                ['cloud_account_id' => $cash, 'credit' => 100, 'cloud_cash_bank_account_id' => $this->tillCbId],
            ], $bad), ManualJournalRules::NARRATION_MESSAGE, 'description');
        }
        $this->nothingRecorded();

        // R2 — the single active drawer on 1110 is auto-filled: the envelope carries it and the local cash effect is projected.
        $tillBefore = $this->projectedTill();
        $event = $this->journal([
            ['cloud_account_id' => $expense, 'debit' => 250, 'description' => 'rent paid from the till'],
            ['cloud_account_id' => $cash, 'credit' => 250],   // no drawer chosen
        ], 'Rent paid in cash');
        $envelope = json_decode((string) DB::table('edge_sync_outbox')->where('sale_uuid', $event['event_uuid'])->value('envelope'), true);
        $this->assertSame($this->tillCbId, $envelope['lines'][1]['cloud_cash_bank_account_id'], 'R2 auto-fill travels in the envelope');
        $this->assertSame(-250.0, (float) DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->where('event_uuid', $event['event_uuid'])->whereNotNull('cloud_cash_bank_account_id')->value('cash_delta'));
        $this->assertSame($tillBefore - 250.0, $this->projectedTill());

        // R2 — two active drawers on 1110 (a second till added Online and projected) → the operator must choose.
        DB::table('cash_bank_accounts')->insert(['code' => 'TILL2', 'name' => 'Second Till', 'account_type' => 'cash', 'account_id' => $cash, 'branch_id' => $this->branchId,
            'current_balance' => 0, 'is_default' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->projectSupplierFinanceToAppliance($this->branchId);
        $this->refuse(fn () => $this->journal([
            ['cloud_account_id' => $expense, 'debit' => 10],
            ['cloud_account_id' => $cash, 'credit' => 10],
        ]), '1110 Main Cash Drawer is used by more than one cash/bank account (Main Till, Second Till). Choose which one the money went through.', 'lines.1.cloud_cash_bank_account_id');

        $this->assertSame(1, DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count(), 'only the compliant journal was recorded');
        $this->assertSame(0, DB::table('journal_entries')->count(), 'the appliance never posts a GL entry itself');
    }

    // ── authority at the till: PERM_JOURNAL + the local-mutation gate; the appliance never reverses ─────────────

    public function test_a_standby_appliance_and_an_operator_without_the_permission_are_refused_and_the_appliance_never_reverses(): void
    {
        $this->asBranchServerRuntime();
        $lines = [
            ['cloud_account_id' => $this->accountId('6100'), 'debit' => 100],
            ['cloud_account_id' => $this->accountId('2100'), 'credit' => 100, 'cloud_supplier_id' => $this->supplierAId],
        ];

        // No PERM_JOURNAL → refused server-side.
        $cashier = User::on('tenant')->find($this->cashierId);
        $this->assertFalse($cashier->can(EdgeLocalSupplierFinanceService::PERM_JOURNAL));
        try {
            $this->svc()->postApJournal(['description' => 'accrual', 'lines' => $lines], $cashier, $this->terminalId);
            $this->fail('a cashier must not post manual journals');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        // STANDBY — lease mode on (a heartbeat URL is configured) and the Cloud holds the branch → every mutation refuses.
        config(['edge.authority.heartbeat_url' => 'http://127.0.0.1:9/api/edge/authority/heartbeat']);
        DB::table('edge_local_meta')->update(['authority_state' => 'standby']);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        try {
            $this->svc()->postApJournal(['description' => 'accrual', 'lines' => $lines], $this->user(), $this->terminalId);
            $this->fail('a standby appliance must not record a journal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Local Mode is not active', $e->getMessage());
        }
        $this->nothingRecorded();

        // The writer (local_active) may post again.
        DB::table('edge_local_meta')->update(['authority_state' => 'local_active']);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $this->assertSame(EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, $this->svc()->postApJournal(['description' => 'accrual', 'lines' => $lines], $this->user(), $this->terminalId)['event_type']);

        // The appliance never reverses (or posts) a GL entry: the Cloud service fails closed before touching the database,
        // and no Edge route offers a reversal.
        $svc = app(ManualJournalService::class);
        $ghost = new JournalEntry(['entry_no' => 'JE-GHOST']);
        $ghost->id = 1;
        try {
            $svc->reverse($ghost, 'x', $this->userId);
            $this->fail('a Branch Server must not reverse a GL entry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must not run on a Branch Server', $e->getMessage());
        }
        try {
            $svc->post(['entry_date' => '2026-10-01', 'description' => 'accrual', 'lines' => []], $this->userId);
            $this->fail('a Branch Server must not post a GL entry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must not run on a Branch Server', $e->getMessage());
        }
        $edgeReverse = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'edge/') && str_contains($r->uri(), 'journal') && str_contains($r->uri(), 'reverse'));
        $this->assertCount(0, $edgeReverse, 'no Edge route reverses a journal (Reverse is Online-only)');
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    // ── refused at ingestion: explicit, terminal code; never silently posted ─────────────────────────────────────

    public function test_the_cloud_ingestion_refuses_a_rule_violating_envelope_with_an_explicit_terminal_code(): void
    {
        $expense = $this->accountId('6100');
        $cash = $this->accountId('1110');

        // R3 — a group-head line (no compliant till can build this; an older appliance or a hand-made envelope could).
        $ack = $this->ingest($this->envelope([
            ['cloud_account_id' => $expense, 'account_code' => '6100', 'debit' => 100],
            ['cloud_account_id' => $this->accountId('1100'), 'account_code' => '1100', 'credit' => 100],
        ]));
        $this->assertSame('refused', $ack['status']);
        $this->assertSame(EdgeInboundSupplierFinanceIngestionService::JOURNAL_RULE_REFUSED, $ack['failure_code']);
        $this->assertStringContainsString('1100 Cash on Hand is a group', $ack['message']);
        $this->assertTrue(EdgeIngestionVerdicts::isTerminal($ack['failure_code']), 'the immutable envelope can never pass → a supervisor acts');

        // R1 — the till drawer on the expense line.
        $ack = $this->ingest($this->envelope([
            ['cloud_account_id' => $expense, 'account_code' => '6100', 'debit' => 100, 'cloud_cash_bank_account_id' => $this->tillCbId],
            ['cloud_account_id' => $cash, 'account_code' => '1110', 'credit' => 100],
        ]));
        $this->assertSame('JOURNAL_RULE_REFUSED', $ack['failure_code']);
        $this->assertStringContainsString('Main Till is linked to 1110 Main Cash Drawer', $ack['message']);

        // R5 — narration.
        $ack = $this->ingest($this->envelope([
            ['cloud_account_id' => $expense, 'account_code' => '6100', 'debit' => 100],
            ['cloud_account_id' => $cash, 'account_code' => '1110', 'credit' => 100, 'cloud_cash_bank_account_id' => $this->tillCbId],
        ], '...'));
        $this->assertSame('JOURNAL_RULE_REFUSED', $ack['failure_code']);
        $this->assertSame(ManualJournalRules::NARRATION_MESSAGE, $ack['message']);

        // Authority — the operator lost the Cloud permission.
        $this->revokeEdgePermission($this->userId, EdgeLocalSupplierFinanceService::PERM_JOURNAL);
        $ack = $this->ingest($this->envelope([
            ['cloud_account_id' => $expense, 'account_code' => '6100', 'debit' => 100],
            ['cloud_account_id' => $cash, 'account_code' => '1110', 'credit' => 100, 'cloud_cash_bank_account_id' => $this->tillCbId],
        ]));
        $this->assertSame('ACTOR_UNAUTHORIZED', $ack['failure_code']);

        $rows = DB::table('edge_inbound_supplier_finance_ingestions')->get();
        $this->assertCount(4, $rows);
        $this->assertSame(['refused'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(0, DB::table('journal_entries')->count(), 'nothing posted');
        $this->assertSame(0, DB::table('cash_bank_account_transactions')->count());
        $this->assertSame(50000.0, (float) DB::table('cash_bank_accounts')->where('id', $this->tillCbId)->value('current_balance'));
    }

    // ── replay/idempotency + allowed reversal of an ingested journal (Online) + already-reversed ─────────────────

    public function test_an_ingested_journal_applies_once_on_replay_and_is_reversed_online_exactly_once(): void
    {
        $expense = $this->accountId('6100');
        $cash = $this->accountId('1110');
        $envelope = $this->envelope([
            ['cloud_account_id' => $expense, 'account_code' => '6100', 'debit' => 300, 'description' => 'rent'],
            ['cloud_account_id' => $cash, 'account_code' => '1110', 'credit' => 300, 'cloud_cash_bank_account_id' => $this->tillCbId],
        ], 'Rent paid in cash');

        $ack = $this->ingest($envelope);
        $this->assertSame('applied', $ack['status'], json_encode($ack));
        $entry = JournalEntry::where('source_type', 'manual_journal')->sole();
        $movement = DB::table('cash_bank_account_transactions')->where('reference_type', 'manual_journal')->where('reference_id', $entry->id)->get();
        $this->assertCount(1, $movement);
        $this->assertSame('out', $movement[0]->direction);
        $this->assertSame(49700.0, $this->cloudTill());

        // Replay of the SAME immutable envelope → already_applied; one journal, one movement, the till moved once.
        $replay = $this->ingest($envelope);
        $this->assertSame('already_applied', $replay['status']);
        $this->assertSame((int) $ack['journal_entry_id'], (int) $replay['journal_entry_id']);
        $this->assertSame(1, JournalEntry::where('source_type', 'manual_journal')->count());
        $this->assertSame(1, DB::table('cash_bank_account_transactions')->count());
        $this->assertSame(49700.0, $this->cloudTill());
        $this->assertSame(1, DB::table('edge_inbound_supplier_finance_ingestions')->where('status', 'applied')->count());

        // R2 at ingestion: an envelope WITHOUT the drawer on the cash line posts exactly what the Online form would
        // (the single drawer auto-filled → a Cash Book movement) — the finance verifier agrees, nothing is silent.
        $auto = $this->ingest($this->envelope([
            ['cloud_account_id' => $expense, 'account_code' => '6100', 'debit' => 50],
            ['cloud_account_id' => $cash, 'account_code' => '1110', 'credit' => 50],
        ], 'Small cash expense'));
        $this->assertSame('applied', $auto['status'], json_encode($auto));
        $this->assertSame(1, DB::table('cash_bank_account_transactions')->where('reference_id', (int) $auto['journal_entry_id'])->where('cash_bank_account_id', $this->tillCbId)->count());
        $this->assertSame(49650.0, $this->cloudTill());

        // Allowed reversal — Online, through the ONE service: GL reversal + cash counter-movement, once.
        $svc = app(ManualJournalService::class);
        $reversal = $svc->reverse($entry, 'wrong month', $this->userId);
        $this->assertSame($entry->id, (int) $reversal->reversed_entry_id);
        $counter = DB::table('cash_bank_account_transactions')->where('reference_type', 'manual_journal_reversal')->get();
        $this->assertCount(1, $counter);
        $this->assertSame('in', $counter[0]->direction);
        $this->assertSame(49950.0, $this->cloudTill(), 'the 300 came back, the 50 stays');

        // Already reversed — a replayed reversal is refused with THE reversal and posts nothing.
        try {
            $svc->reverse($entry->fresh(), 'again', $this->userId);
            $this->fail('a replayed reversal must be refused');
        } catch (ManualJournalReversalRefused $e) {
            $this->assertSame(ManualJournalReversalRefused::ALREADY_REVERSED, $e->reason);
            $this->assertSame($reversal->id, $e->existingReversal?->id);
        }
        $this->assertSame(1, JournalEntry::where('reversed_entry_id', $entry->id)->count());
        $this->assertSame(1, DB::table('cash_bank_account_transactions')->where('reference_type', 'manual_journal_reversal')->count());
        $this->assertSame(49950.0, $this->cloudTill());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function svc(): EdgeLocalSupplierFinanceService
    {
        return app(EdgeLocalSupplierFinanceService::class);
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function journal(array $lines, string $description = 'test journal'): array
    {
        return $this->svc()->postApJournal(['description' => $description, 'lines' => $lines], $this->user(), $this->terminalId);
    }

    private function refuse(callable $fn, string $message, string $key): void
    {
        try {
            $fn();
            $this->fail("expected a refusal [{$key}]: {$message}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), json_encode($e->errors()));
            $this->assertSame($message, $e->errors()[$key][0]);
        }
    }

    private function nothingRecorded(): void
    {
        $this->assertSame(0, DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count(), 'no local event');
        $this->assertSame(0, DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->count(), 'no local effect');
        $this->assertSame(0, DB::table('edge_sync_outbox')->count(), 'nothing queued');
    }

    private function projectedTill(): float
    {
        return (float) collect(app(EdgeSupplierFinanceCacheService::class)->cashBankAccounts())->firstWhere('cloud_cash_bank_account_id', $this->tillCbId)['projected_balance'];
    }

    private function cloudTill(): float
    {
        return (float) DB::table('cash_bank_accounts')->where('id', $this->tillCbId)->value('current_balance');
    }

    /** An AP-journal envelope exactly as the appliance's builder signs it (ULID event, canonical hash), for the Cloud ingestion. */
    private function envelope(array $lines, string $description = 'Journal from the till'): array
    {
        $meta = EdgeLocalMeta::on('tenant')->firstOrFail();
        $journal = ['entry_date' => now()->toDateString(), 'description' => $description, 'reference_no' => null, 'lines' => array_map(fn ($l) => [
            'line_uuid' => (string) Str::ulid(), 'cloud_account_id' => $l['cloud_account_id'], 'account_code' => $l['account_code'],
            'cloud_cash_bank_account_id' => $l['cloud_cash_bank_account_id'] ?? null, 'cloud_supplier_id' => $l['cloud_supplier_id'] ?? null,
            'description' => $l['description'] ?? null, 'debit' => $l['debit'] ?? 0, 'credit' => $l['credit'] ?? 0,
        ], $lines)];

        return app(EdgeSupplierFinanceEnvelopeBuilder::class)->buildApJournal($meta, (string) Str::ulid(), $journal,
            ['user_id' => $this->userId, 'employee_code' => 'D1J', 'terminal_id' => $this->terminalId],
            ['watermark' => (string) $meta->supplier_finance_cache_watermark, 'as_of' => now()->toIso8601String()]);
    }

    private function ingest(array $envelope): array
    {
        $this->resetRuntimeRole();   // the Cloud ingests

        return app(EdgeInboundSupplierFinanceIngestionService::class)->ingest($envelope);
    }
}
