<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use App\Services\Edge\EdgePurchaseReturnCacheService;
use App\Services\Edge\EdgePurchaseReturnEnvelopeBuilder;
use App\Services\Edge\EdgeRestoreService;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgePurchaseReturnFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F3 §14: a pending purchase return (event, lines, provisional supplier effect, the operational stock
 * movement and the projection it validated against) survives encrypted backup → total loss → restore with the SAME bytes
 * and hash — the Cloud will apply it exactly once; nothing is duplicated.
 */
class EdgePurchaseReturnBackupRecoveryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgePurchaseReturnFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(['edge_local_backups'], self::PR_EDGE_TABLES, self::PR_TABLES, [
            'edge_sync_outbox', 'edge_local_print_deliveries', 'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_return_lines', 'sales_returns', 'sales_ledgers',
            'sale_payments', 'sales_order_lines', 'sales_orders', 'restaurant_table_sessions', 'edge_local_table_reservations', 'shifts', 'manager_approvals',
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_baseline_cutovers',
            'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_supplier_finance_bills', 'edge_supplier_finance_cash_bank_accounts', 'edge_supplier_finance_accounts', 'edge_supplier_finance_ledger_entries',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Backup Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PRB' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Raw Item', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_purchasable' => 1, 'status' => 'active']);
        $this->seedCloudPurchaseReturnTruth($this->branchId, $product, $unit);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'pr-backup-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, EdgeLocalPurchaseReturnService::PERM_STORE);
        $this->grantEdgePermission($this->userId, EdgeLocalPurchaseReturnService::PERM_POST);
        $this->projectPurchaseReturnsToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        config([
            'edge.backup.path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-f3-backup-' . Str::lower(Str::random(6)),
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

    public function test_a_pending_purchase_return_survives_backup_and_restore_with_the_same_hash(): void
    {
        foreach (array_merge(['edge_purchase_return_grns', 'edge_purchase_return_grn_lines', 'edge_purchase_return_applied_events', 'edge_local_purchase_return_events', 'edge_local_purchase_return_lines'], ['edge_sync_outbox', 'edge_operational_stock_movements', 'edge_local_supplier_finance_effects']) as $t) {
            $this->assertContains($t, EdgeBackupService::TABLES, "{$t} must be in the backup census");
        }
        $ev = app(EdgeLocalPurchaseReturnService::class)->postReturn(['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 4]]], User::on('tenant')->find($this->userId), $this->terminalId);
        $snapshot = function () {
            return [
                'outbox' => DB::table('edge_sync_outbox')->orderBy('sale_uuid')->get(['sale_uuid', 'envelope_schema_version', 'content_hash', 'envelope', 'state'])->map(fn ($r) => (array) $r)->all(),
                'events' => DB::table(EdgePurchaseReturnCacheService::T_EVENTS)->get(['event_uuid', 'grand_total', 'content_hash', 'payload'])->map(fn ($r) => (array) $r)->all(),
                'lines' => DB::table(EdgePurchaseReturnCacheService::T_EVENT_LINES)->get(['event_uuid', 'line_uuid', 'cloud_grn_line_id', 'quantity', 'unit_cost', 'line_total'])->map(fn ($r) => (array) $r)->all(),
                'effects' => DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->get(['event_uuid', 'cloud_supplier_id', 'payable_delta'])->map(fn ($r) => (array) $r)->all(),
                'movements' => DB::table('edge_operational_stock_movements')->where('movement_type', 'purchase_return')->count(),
                'on_hand' => (float) DB::table('edge_operational_stock_balances')->where('product_id', $this->prProductId)->sum('quantity_on_hand'),
                'returnable' => app(EdgePurchaseReturnCacheService::class)->lines($this->prGrnId)[0]['returnable'],
                'freshness' => app(EdgePurchaseReturnCacheService::class)->freshness(),
            ];
        };
        $before = $snapshot();
        $this->assertSame(16.0, $before['on_hand']);
        $this->assertSame(6.0, $before['returnable']);
        $this->assertTrue($before['freshness']['ok']);

        $backup = app(EdgeBackupService::class)->backup();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['edge_local_purchase_return_lines', 'edge_local_purchase_return_events', 'edge_purchase_return_applied_events', 'edge_purchase_return_grn_lines', 'edge_purchase_return_grns', 'edge_local_supplier_finance_effects', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('edge_local_meta')->update(['purchase_return_cache_watermark' => null, 'purchase_return_cache_as_of' => null, 'standby_purchase_return_watermark_seen' => null]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());

        $this->assertNotEmpty(app(EdgeRestoreService::class)->restore($backup->path, $this->branchId));
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);

        $after = $snapshot();
        $this->assertSame($before['outbox'], $after['outbox'], 'the SAME immutable bytes and hash — the Cloud will apply it exactly once');
        $this->assertSame($before['events'], $after['events']);
        $this->assertSame($before['lines'], $after['lines']);
        $this->assertSame($before['effects'], $after['effects']);
        $this->assertSame(1, $after['movements'], 'ONE operational stock movement');
        $this->assertSame(16.0, $after['on_hand'], 'the local stock effect is restored once');
        $this->assertSame(6.0, $after['returnable']);
        $this->assertTrue($after['freshness']['ok']);
        $this->assertSame('pending', app(EdgePurchaseReturnCacheService::class)->event($ev['event_uuid'])['sync']['state']);
        $this->assertSame(1, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgePurchaseReturnEnvelopeBuilder::SCHEMA)->where('state', 'pending')->count());
    }
}
