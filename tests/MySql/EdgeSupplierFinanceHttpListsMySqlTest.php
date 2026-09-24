<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W4 (Team 4) — R8.4 Supplier Payments list/detail + R8.6 Manual Journals list/show on the Branch Server, over the REAL
 * routes, fed by the F2 events recorded through the real routes. Online route permissions (*.index / *.show) gate each
 * screen; the journal detail offers NO reverse (owner-dependent). Read-only: rendering the screens mutates nothing.
 */
class EdgeSupplierFinanceHttpListsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeSupplierFinanceFixture;

    private int $branchId;
    private int $terminalId;
    private int $operatorId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::SF_EDGE_TABLES, self::SF_TABLES, [
            'edge_sync_outbox', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions',
            'shifts', 'payment_methods', 'products', 'categories', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Finance Branch', 'timezone' => 'Asia/Karachi']);
        $this->operatorId = $this->makeUser(['name' => 'Accountant Sana', 'default_branch_id' => $this->branchId, 'employee_code' => 'OP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $this->seedCloudSupplierFinance($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'http-finance-lists');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->seedEdgeCredential($this->operatorId, $this->branchId, 1);
        foreach ([EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL,
            'tenant.supplier-payments.index', 'tenant.supplier-payments.show', 'tenant.finance.manual-journals.index', 'tenant.finance.manual-journals.show'] as $p) {
            $this->grantEdgePermission($this->operatorId, $p);
        }
        $this->projectSupplierFinanceToAppliance($this->branchId);
        $this->login();
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function login(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->operatorId), 'tenant');
        Auth::shouldUse('tenant');
    }

    private function revoke(string $p): void
    {
        DB::table('model_has_permissions')->where('model_id', $this->operatorId)->where('permission_id', DB::table('permissions')->where('name', $p)->value('id'))->delete();
        $this->login();
    }

    public function test_supplier_payments_and_manual_journals_list_and_detail_screens(): void
    {
        $paid = $this->postJson('/edge/local/pos/suppliers/payments', [
            'cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 1200, 'payment_method' => 'cheque',
            'cheque_no' => 'CHQ-77', 'reference_no' => 'R-9', 'notes' => 'weekly settlement',
        ])->assertStatus(201)->json('event');
        $journal = $this->postJson('/edge/local/pos/finance/journal', ['description' => 'packaging accrual', 'reference_no' => 'JV-1', 'lines' => [
            ['cloud_account_id' => $this->accountId('6500'), 'debit' => 900],
            ['cloud_account_id' => $this->accountId('2100'), 'credit' => 900, 'cloud_supplier_id' => $this->supplierBId],
        ]])->assertStatus(201)->json('event');
        $outbox = DB::table('edge_sync_outbox')->count();

        $list = $this->get('/edge/local/pos/supplier-payments')->assertOk()->getContent();
        foreach (['id="supplier-payment-table"', 'Payment No', 'id="pay-supplier"', 'Cheque', '1,200.00', 'PENDING', 'id="supplier-payment-view-' . $paid['event_uuid'] . '"'] as $n) {
            $this->assertStringContainsString($n, $list, "the payments list must carry {$n}");
        }
        $this->assertStringNotContainsString('1,200.00', $this->get('/edge/local/pos/supplier-payments?supplier_id=' . $this->supplierBId)->assertOk()->getContent(), 'supplier filter');
        $this->assertStringNotContainsString('packaging accrual', $list, 'a journal is not a payment');
        $show = $this->get('/edge/local/pos/supplier-payments/' . $paid['event_uuid'])->assertOk()->getContent();
        foreach (['Payment Details', 'Against Bill', 'No specific bill (general payment)', 'Pay From (Cash/Bank)', 'CHQ-77', 'R-9', 'weekly settlement', 'Accountant Sana', 'PENDING SYNC'] as $n) {
            $this->assertStringContainsString($n, $show, "the payment detail must carry {$n}");
        }
        $this->get('/edge/local/pos/supplier-payments/' . $journal['event_uuid'])->assertNotFound();

        $jl = $this->get('/edge/local/pos/finance/manual-journals')->assertOk()->getContent();
        foreach (['id="manual-journal-table"', 'Entry #', 'packaging accrual', 'JV-1', '900.00', 'id="manual-journal-view-' . $journal['event_uuid'] . '"'] as $n) {
            $this->assertStringContainsString($n, $jl, "the journal list must carry {$n}");
        }
        $this->assertStringNotContainsString('packaging accrual', $this->get('/edge/local/pos/finance/manual-journals?q=nomatch')->assertOk()->getContent());
        $js = $this->get('/edge/local/pos/finance/manual-journals/' . $journal['event_uuid'])->assertOk()->getContent();
        foreach (['Journal Lines', '6500', '2100', 'Totals', '900.00', 'id="manual-journal-reverse-note"'] as $n) {
            $this->assertStringContainsString($n, $js, "the journal detail must carry {$n}");
        }
        $this->assertStringNotContainsString('/reverse', $js, 'no reverse action offline (owner-dependent)');
        $this->assertSame($outbox, DB::table('edge_sync_outbox')->count(), 'the screens are read-only');

        // Online route permissions per screen.
        $this->revoke('tenant.supplier-payments.show');
        $this->get('/edge/local/pos/supplier-payments/' . $paid['event_uuid'])->assertForbidden();
        $this->revoke('tenant.supplier-payments.index');
        $this->get('/edge/local/pos/supplier-payments')->assertForbidden();
        $this->revoke('tenant.finance.manual-journals.show');
        $this->get('/edge/local/pos/finance/manual-journals/' . $journal['event_uuid'])->assertForbidden();
        $this->revoke('tenant.finance.manual-journals.index');
        $this->get('/edge/local/pos/finance/manual-journals')->assertForbidden();
    }
}
