<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use App\Services\Edge\EdgeRestoreService;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F2 §21 BACKUP / RESTORE OF PENDING SUPPLIER-FINANCE EVENTS: a pending supplier payment and a pending
 * manual AP journal (with their effects and the warm projection they validated against) survive the encrypted backup →
 * total loss → restore with the SAME bytes and hash, so the Cloud will apply each exactly once; nothing is duplicated.
 */
class EdgeSupplierFinanceBackupRecoveryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeSupplierFinanceFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(['edge_local_backups'], self::SF_EDGE_TABLES, self::SF_TABLES, [
            'edge_sync_outbox', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Backup Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SFB' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->seedCloudSupplierFinance($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'backup-finance-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        foreach ([EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
        $this->projectSupplierFinanceToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        config([
            'edge.backup.path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-f2-backup-' . Str::lower(Str::random(6)),
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)),
            'edge.backup.recovery_key_id' => 'k1',
            'edge.backup.retired_keys' => [],
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    public function test_pending_supplier_finance_events_survive_backup_and_restore_with_the_same_hash(): void
    {
        foreach (array_merge(self::SF_EDGE_TABLES, ['edge_sync_outbox', 'edge_local_meta']) as $t) {
            $this->assertContains($t, EdgeBackupService::TABLES, "{$t} must be in the backup census");
        }
        $svc = app(EdgeLocalSupplierFinanceService::class);
        $user = User::on('tenant')->find($this->userId);
        $pay = $svc->recordPayment(['cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 4000, 'payment_method' => 'cash', 'cloud_bill_id' => $this->billAId], $user, $this->terminalId);
        $journal = $svc->postApJournal(['description' => 'accrual', 'lines' => [
            ['cloud_account_id' => $this->accountId('6100'), 'debit' => 1500],
            ['cloud_account_id' => $this->accountId('2100'), 'credit' => 1500, 'cloud_supplier_id' => $this->supplierBId],
        ]], $user, $this->terminalId);

        $snapshot = function () {
            return [
                'outbox' => DB::table('edge_sync_outbox')->orderBy('sale_uuid')->get(['sale_uuid', 'envelope_schema_version', 'content_hash', 'envelope', 'state'])->map(fn ($r) => (array) $r)->all(),
                'events' => DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->orderBy('event_uuid')->get(['event_uuid', 'event_type', 'amount', 'content_hash', 'payload'])->map(fn ($r) => (array) $r)->all(),
                'effects' => DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->orderBy('event_uuid')->orderBy('id')->get(['event_uuid', 'cloud_supplier_id', 'cloud_cash_bank_account_id', 'cloud_bill_id', 'payable_delta', 'cash_delta'])->map(fn ($r) => (array) $r)->all(),
                'position_a' => app(EdgeSupplierFinanceCacheService::class)->position($this->supplierAId),
                'position_b' => app(EdgeSupplierFinanceCacheService::class)->position($this->supplierBId),
                'bills' => app(EdgeSupplierFinanceCacheService::class)->openBills($this->supplierAId),
                'freshness' => app(EdgeSupplierFinanceCacheService::class)->freshness(),
                'suppliers' => DB::table(EdgeSupplierFinanceCacheService::T_SUPPLIERS)->count(),
                'accounts' => DB::table(EdgeSupplierFinanceCacheService::T_ACCOUNTS)->count(),
            ];
        };
        $before = $snapshot();
        $this->assertCount(2, $before['outbox']);
        $this->assertSame(6000.0, $before['position_a']['available_payable']);
        $this->assertSame(6500.0, $before['position_b']['available_payable']);
        $this->assertTrue($before['freshness']['ok']);

        $backup = app(EdgeBackupService::class)->backup();

        // THE APPLIANCE LOSES ITS STATE: events, effects, projection, outbox — gone.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_merge(self::SF_EDGE_TABLES, ['edge_sync_outbox']) as $t) {
            DB::table($t)->delete();
        }
        DB::table('edge_local_meta')->update(['supplier_finance_cache_watermark' => null, 'supplier_finance_cache_as_of' => null, 'standby_supplier_finance_watermark_seen' => null]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
        $this->assertFalse(app(EdgeSupplierFinanceCacheService::class)->freshness()['ok']);

        $result = app(EdgeRestoreService::class)->restore($backup->path, $this->branchId);
        $this->assertNotEmpty($result);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);

        $after = $snapshot();
        $this->assertSame($before['outbox'], $after['outbox'], 'the SAME immutable bytes and hashes — the Cloud will apply each exactly once');
        $this->assertSame($before['events'], $after['events']);
        $this->assertSame($before['effects'], $after['effects']);
        $this->assertSame($before['position_a'], $after['position_a'], 'the provisional payable is restored exactly once');
        $this->assertSame($before['position_b'], $after['position_b']);
        $this->assertSame($before['bills'], $after['bills']);
        $this->assertSame($before['suppliers'], $after['suppliers']);
        $this->assertSame($before['accounts'], $after['accounts']);
        $this->assertTrue($after['freshness']['ok'], 'the projection watermark stamps travel with the binding');
        $this->assertSame(2, DB::table('edge_sync_outbox')->whereIn('envelope_schema_version', EdgeSupplierFinanceEnvelopeBuilder::SCHEMAS)->where('state', 'pending')->count());
        $this->assertSame('pending', app(EdgeSupplierFinanceCacheService::class)->event($pay['event_uuid'])['sync']['state']);
        $this->assertSame('pending', app(EdgeSupplierFinanceCacheService::class)->event($journal['event_uuid'])['sync']['state']);
    }
}
