<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F2 REAL EDGE UI PROOFS (branch_server-booted app, real routes → middleware → controller → services →
 * Blade): the Suppliers page (Supplier list → Supplier Ledger → Record Payment: Against Bill optional, Pay From Cash/Bank
 * required, no usable "no cash/bank effect" option, PENDING SYNC status), the General Journal page (AP account → Supplier
 * enabled + required; non-AP → disabled), the cashier header entry points, and server-side authorization: a normal
 * cashier never gains Supplier Payment / Manual Journal.
 */
class EdgeSupplierFinanceHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeSupplierFinanceFixture;

    private int $branchId;
    private int $terminalId;
    private int $operatorId;
    private int $cashierId;

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
        $this->operatorId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'OP' . Str::random(4)]);
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CA' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $this->seedCloudSupplierFinance($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'http-finance-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->seedEdgeCredential($this->operatorId, $this->branchId, 1);
        $this->seedEdgeCredential($this->cashierId, $this->branchId, 1);
        foreach ([EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL] as $p) {
            $this->grantEdgePermission($this->operatorId, $p);
        }
        $this->projectSupplierFinanceToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->operatorId), 'tenant');
        Auth::shouldUse('tenant');
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

    public function test_the_real_pages_carry_the_online_supplier_finance_ux(): void
    {
        // Cashier header: the entry points follow the Online permissions.
        $pos = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('id="suppliers-link"', $pos);
        $this->assertStringContainsString('id="journal-link"', $pos);

        // Suppliers → Supplier Ledger → Record Payment (Online form semantics).
        $html = $this->get('/edge/local/pos/suppliers')->assertOk()->getContent();
        foreach (['Supplier Ledger', 'Record Payment', 'Against Bill', '(optional)', 'No specific bill (general payment)', 'Pay From (Cash/Bank)', 'Select the Cash/Bank account (required)',
            'Payment Method', 'Reference No', 'Notes', 'PENDING SYNC', 'Current Payable (Cloud official)', 'Available payable', '/suppliers/payments', "/suppliers/' + id + '/ledger", 'Dr Accounts Payable / Cr'] as $needle) {
            $this->assertStringContainsString($needle, $html, "the Suppliers page must carry {$needle}");
        }
        $this->assertStringNotContainsString('None (no cash/bank effect)', $html, 'no usable "no cash/bank effect" option offline — CASH_BANK_REQUIRED');
        $this->assertStringContainsString('disabled selected>— Select the Cash/Bank account (required)', $html, 'the only empty option is a disabled placeholder');

        // General Journal: the supplier cell opens only on an Accounts Payable line and is then required.
        $mj = $this->get('/edge/local/pos/finance/journal')->assertOk()->getContent();
        foreach (['General Journal', 'Entry Date', 'Description / Memo', 'Supplier <small>(AP lines)</small>', 'mj-supplier', 'AP_ACCOUNT_IDS', 'sup.disabled = !isAp', 'sup.required = isAp',
            'cloud_supplier_id: !sup.disabled && sup.value ? Number(sup.value) : null', 'Post Journal (pending sync)', '/finance/journal/options'] as $needle) {
            $this->assertStringContainsString($needle, $mj, "the General Journal page must carry {$needle}");
        }
    }

    public function test_the_operator_records_a_payment_and_a_journal_through_the_real_routes(): void
    {
        $options = $this->getJson('/edge/local/pos/suppliers/options')->assertOk()->json();
        $this->assertTrue($options['freshness']['ok'], json_encode($options['freshness']));
        $this->assertTrue($options['rules']['cash_bank_required']);
        $this->assertFalse($options['rules']['ledger_only_payment_possible']);
        $this->assertFalse($options['rules']['supplier_advance_supported']);
        $this->assertFalse(collect($options['payment_methods'])->firstWhere('code', 'card')['offline_allowed'], 'card needs the Online POS');
        $this->assertTrue(collect($options['payment_methods'])->firstWhere('code', 'bank_transfer')['offline_allowed']);
        $a = collect($options['suppliers'])->firstWhere('cloud_supplier_id', $this->supplierAId);
        $this->assertSame(10000.0, (float) $a['available_payable']);
        $this->assertEmpty(collect($options['cash_bank_accounts'])->whereIn('cloud_cash_bank_account_id', [$this->unmappedCbId, $this->inactiveCbId]));
        $this->assertSame(['can_view_ledger' => true, 'can_pay' => true, 'can_journal' => true], $options['permissions']);

        $ledger = $this->getJson('/edge/local/pos/suppliers/' . $this->supplierAId . '/ledger')->assertOk()->json();
        $this->assertSame(1, $ledger['official_count']);
        $this->assertSame(0, $ledger['provisional_count']);
        $this->assertSame('PB-A-1', $ledger['open_bills'][0]['bill_no']);

        // CASH_BANK_REQUIRED at the HTTP boundary.
        $this->postJson('/edge/local/pos/suppliers/payments', ['cloud_supplier_id' => $this->supplierAId, 'amount' => 100, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'ledger-only'));
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());

        $paid = $this->postJson('/edge/local/pos/suppliers/payments', [
            'cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 7000, 'payment_method' => 'cash', 'reference_no' => 'R-1', 'notes' => 'weekly settlement',
        ])->assertStatus(201)->json('event');
        $this->assertSame('pending', $paid['sync']['state']);
        $this->assertSame(3000.0, (float) $paid['position']['available_payable']);
        $this->assertSame($this->terminalId, json_decode((string) DB::table('edge_sync_outbox')->where('sale_uuid', $paid['event_uuid'])->value('envelope'), true)['actor']['terminal_id']);

        $ledger = $this->getJson('/edge/local/pos/suppliers/' . $this->supplierAId . '/ledger')->assertOk()->json();
        $this->assertSame(1, $ledger['provisional_count']);
        $this->assertSame('provisional', $ledger['rows'][0]['kind']);
        $this->assertSame('pending', $ledger['rows'][0]['sync']['state']);
        $this->assertSame(7000.0, (float) $ledger['rows'][0]['credit']);
        $this->assertSame(3000.0, (float) $ledger['supplier']['available_payable']);
        $this->getJson('/edge/local/pos/finance/events/' . $paid['event_uuid'])->assertOk()->assertJsonPath('event.event_type', EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT);

        $this->postJson('/edge/local/pos/suppliers/payments', ['cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 7000, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'advance'));

        // The General Journal through the real route: Dr Expense / Cr AP (supplier) → the payable rises, pending sync.
        $jo = $this->getJson('/edge/local/pos/finance/journal/options')->assertOk()->json();
        $this->assertContains($this->accountId('2100'), $jo['ap_account_ids']);
        $journal = $this->postJson('/edge/local/pos/finance/journal', ['description' => 'packaging accrual', 'lines' => [
            ['cloud_account_id' => $this->accountId('6500'), 'debit' => 900],
            ['cloud_account_id' => $this->accountId('2100'), 'credit' => 900, 'cloud_supplier_id' => $this->supplierBId],
        ]])->assertStatus(201)->json('event');
        $this->assertSame(EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, $journal['event_type']);
        $this->assertSame(5900.0, (float) $journal['positions'][0]['available_payable']);
        $this->postJson('/edge/local/pos/finance/journal', ['description' => 'no supplier', 'lines' => [
            ['cloud_account_id' => $this->accountId('6500'), 'debit' => 10], ['cloud_account_id' => $this->accountId('2100'), 'credit' => 10],
        ]])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'must name the supplier'));
        $this->assertSame(1, count($this->getJson('/edge/local/pos/finance/journal/options')->assertOk()->json('recent_events')));
        $this->assertSame(2, DB::table('edge_sync_outbox')->whereIn('envelope_schema_version', EdgeSupplierFinanceEnvelopeBuilder::SCHEMAS)->count());
    }

    public function test_a_normal_cashier_never_gains_supplier_payment_or_manual_journal(): void
    {
        $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant');
        $pos = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="suppliers-link"', $pos);
        $this->assertStringNotContainsString('id="journal-link"', $pos);
        $this->get('/edge/local/pos/suppliers')->assertStatus(403);
        $this->getJson('/edge/local/pos/suppliers/options')->assertStatus(403);
        $this->getJson('/edge/local/pos/suppliers/' . $this->supplierAId . '/ledger')->assertStatus(403);
        $this->postJson('/edge/local/pos/suppliers/payments', ['cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 10, 'payment_method' => 'cash'])->assertStatus(403);
        $this->get('/edge/local/pos/finance/journal')->assertStatus(403);
        $this->getJson('/edge/local/pos/finance/journal/options')->assertStatus(403);
        $this->postJson('/edge/local/pos/finance/journal', ['description' => 'x', 'lines' => [['cloud_account_id' => 1, 'debit' => 1], ['cloud_account_id' => 2, 'credit' => 1]]])->assertStatus(403);
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
    }
}
