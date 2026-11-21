<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeLocalReturnService;
use App\Services\Edge\EdgeRestoreService;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * F1 — CRASH / BACKUP RECOVERY of a PENDING return: the immutable return event, the local return document, the
 * operational return movement and the till effect are in the backup census; after the appliance loses its state and
 * restores the encrypted backup, the event is still pending with the same hash (syncable exactly once), the return
 * document and its returned quantity are back, and no second operational return appears.
 */
class EdgeReturnBackupRecoveryMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_backups', 'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_baseline_cutovers', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users', 'print_jobs', 'kot_batch_lines', 'kot_batches', 'restaurant_table_sessions']);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0]);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'BK' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'backup-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 500.0);
        config([
            'edge.backup.path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-f1-backup-' . Str::lower(Str::random(6)),
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

    public function test_a_pending_return_survives_backup_and_restore_and_stays_syncable_exactly_once(): void
    {
        $this->assertContains('sales_returns', EdgeBackupService::TABLES);
        $this->assertContains('sales_return_lines', EdgeBackupService::TABLES);
        $this->assertContains('edge_returnable_sales', EdgeBackupService::TABLES);
        $this->assertContains('edge_sync_outbox', EdgeBackupService::TABLES);

        $user = User::on('tenant')->find($this->userId);
        $sale = app(EdgeLocalPosService::class)->completePaidSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->productId, 'quantity' => 3]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 300]]], $user, $this->terminalId);
        $lineId = (int) DB::table('sales_order_lines')->where('sales_order_id', $sale->id)->value('id');
        $ret = app(EdgeLocalReturnService::class)->processReturn($sale->id, [['sales_order_line_id' => $lineId, 'quantity' => 1]], 'crash test', 'cash', 100.0, $user, $this->terminalId);
        $row = DB::table('edge_sync_outbox')->where('sale_uuid', $ret['return_uuid'])->first();
        $this->assertSame('pending', $row->state);
        $snapshot = [
            'hash' => $row->content_hash, 'envelope' => $row->envelope,
            'returned_qty' => (float) DB::table('sales_order_lines')->where('id', $lineId)->value('returned_quantity'),
            'movements' => DB::table('edge_operational_stock_movements')->where('movement_type', 'sale_return')->count(),
            'on_hand' => (float) DB::table('edge_operational_stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'),
            'expected_cash' => (float) DB::table('shifts')->value('expected_cash'),
        ];

        $backup = app(EdgeBackupService::class)->backup();

        // THE APPLIANCE LOSES ITS STATE (crash / disk): the return, its event, the movement, the sale, the shift — gone.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['edge_sync_outbox', 'sales_return_lines', 'sales_returns', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts'] as $t) {
            DB::table($t)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());

        $result = app(EdgeRestoreService::class)->restore($backup->path, $this->branchId);
        $this->assertNotEmpty($result);

        // Restored: the SAME pending event (same hash → the Cloud will apply it exactly once), the document, the quantities.
        $restored = DB::table('edge_sync_outbox')->where('sale_uuid', $ret['return_uuid'])->first();
        $this->assertNotNull($restored, 'the pending return event survived');
        $this->assertSame('pending', $restored->state);
        $this->assertSame($snapshot['hash'], $restored->content_hash);
        $this->assertSame($snapshot['envelope'], $restored->envelope, 'immutable bytes');
        $this->assertSame(1, DB::table('sales_returns')->where('edge_return_uuid', $ret['return_uuid'])->count());
        $this->assertSame($snapshot['returned_qty'], (float) DB::table('sales_order_lines')->where('id', $lineId)->value('returned_quantity'));
        $this->assertSame($snapshot['movements'], DB::table('edge_operational_stock_movements')->where('movement_type', 'sale_return')->count(), 'ONE operational return, not two');
        $this->assertSame($snapshot['on_hand'], (float) DB::table('edge_operational_stock_balances')->where('product_id', $this->productId)->sum('quantity_on_hand'));
        $this->assertSame($snapshot['expected_cash'], (float) DB::table('shifts')->value('expected_cash'), 'the till effect is restored once');
        // A restart re-reads the same facts: returnable quantity still excludes the pending return.
        $view = app(EdgeLocalReturnService::class)->returnable($sale->id, $user);
        $this->assertSame(2.0, $view['lines'][0]['returnable']);
        $this->assertSame(1, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->count());
    }
}
