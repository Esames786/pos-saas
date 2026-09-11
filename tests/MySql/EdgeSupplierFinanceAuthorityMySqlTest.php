<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use App\Services\Edge\EdgeSupplierFinanceCacheService;
use App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeSupplierFinanceFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F2: the appliance's supplier-finance authority against a FRESH warm projection (Branch Server role,
 * no Cloud transport). Online rules mirrored: Purchase Bill OPTIONAL, Cash/Bank REQUIRED (ledger-only impossible),
 * overpayment fails closed (no supplier advance), inactive supplier / unmapped or inactive cash-bank refused, card
 * needs the Online POS, stale projection fails closed; the manual AP journal moves the provisional payable and every
 * AP line names its supplier. Local effects: provisional payable / cash exactly once, PENDING SYNC event, immutable
 * envelope with a self-consistent hash — and never a local GL / AP / cash-bank ledger.
 */
class EdgeSupplierFinanceAuthorityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgeSupplierFinanceFixture;

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
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'FIN' . Str::random(4)]);
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CSH' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->seedCloudSupplierFinance($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'finance-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->cashierId, $this->branchId, 1);
        foreach ([EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
        $this->projectSupplierFinanceToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function svc(): EdgeLocalSupplierFinanceService
    {
        return app(EdgeLocalSupplierFinanceService::class);
    }

    private function cache(): EdgeSupplierFinanceCacheService
    {
        return app(EdgeSupplierFinanceCacheService::class);
    }

    private function pay(array $overrides): array
    {
        return $this->svc()->recordPayment(array_merge([
            'cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 1000, 'payment_method' => 'cash',
        ], $overrides), $this->user(), $this->terminalId);
    }

    private function refuse(callable $fn, string $needle, ?string $key = null): void
    {
        try {
            $fn();
            $this->fail("expected a refusal containing [{$needle}]");
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' | ');
            $this->assertStringContainsString($needle, $messages);
            if ($key !== null) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }
    }

    private function nothingRecorded(): void
    {
        $this->assertSame(0, DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count(), 'no local event');
        $this->assertSame(0, DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->count(), 'no local effect');
        $this->assertSame(0, DB::table('edge_sync_outbox')->count(), 'nothing queued');
    }

    public function test_a_supplier_is_paid_on_account_without_a_purchase_bill_and_the_local_position_is_provisional(): void
    {
        $fresh = $this->cache()->freshness();
        $this->assertTrue($fresh['ok'], json_encode($fresh));
        $before = $this->cache()->position($this->supplierAId);
        $this->assertSame(['cloud_payable' => 10000.0, 'pending_delta' => 0.0, 'available_payable' => 10000.0, 'pending_events' => 0], array_intersect_key($before, array_flip(['cloud_payable', 'pending_delta', 'available_payable', 'pending_events'])));
        $tillBefore = collect($this->cache()->cashBankAccounts())->firstWhere('cloud_cash_bank_account_id', $this->tillCbId);

        $event = $this->pay(['amount' => 3000, 'reference_no' => 'RCPT-1', 'notes' => 'on account']);

        // The local operational event + PENDING SYNC status; provisional payable and cash effect exactly once.
        $this->assertSame(EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT, $event['event_type']);
        $this->assertSame('pending', $event['sync']['state']);
        $this->assertStringContainsString('PENDING SYNC', $event['sync']['label']);
        $this->assertNull($event['payload']['cloud_bill_id'], 'PAYMENT_WITHOUT_PURCHASE_BILL — no bill is required');
        $this->assertSame(['cloud_payable' => 10000.0, 'pending_delta' => -3000.0, 'available_payable' => 7000.0, 'pending_events' => 1], array_intersect_key($event['position'], array_flip(['cloud_payable', 'pending_delta', 'available_payable', 'pending_events'])));
        $till = collect($this->cache()->cashBankAccounts())->firstWhere('cloud_cash_bank_account_id', $this->tillCbId);
        $this->assertSame($tillBefore['projected_balance'] - 3000.0, $till['projected_balance'], 'LOCAL_CASH_EFFECT: the projected cash position moves once');
        $this->assertSame(50000.0, $till['cloud_balance'], 'the Cloud balance itself is untouched until the Cloud posts');

        // The Supplier Ledger: official opening row + ONE provisional row marked PENDING SYNC.
        $ledger = $this->svc()->ledger($this->supplierAId);
        $this->assertSame(1, $ledger['provisional_count']);
        $this->assertSame(1, $ledger['official_count']);
        $this->assertSame('provisional', $ledger['rows'][0]['kind']);
        $this->assertSame(3000.0, $ledger['rows'][0]['credit']);
        $this->assertSame(7000.0, $ledger['rows'][0]['balance_after']);
        $this->assertSame('pending', $ledger['rows'][0]['sync']['state']);

        // The immutable event: schema, identity, self-consistent hash, canonical accounting facts.
        $row = EdgeSyncOutbox::on('tenant')->where('sale_uuid', $event['event_uuid'])->firstOrFail();
        $this->assertSame(EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT, $row->envelope_schema_version);
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $row->state);
        $envelope = json_decode((string) $row->envelope, true);
        $copy = $envelope;
        unset($copy['content_hash']);
        $this->assertSame(hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($copy)), $envelope['content_hash']);
        $this->assertSame($row->content_hash, $envelope['content_hash']);
        $this->assertSame('supplier_payment', $envelope['event_type']);
        $this->assertSame($this->supplierAId, $envelope['supplier']['cloud_supplier_id']);
        $this->assertSame($this->tillCbId, $envelope['cash_bank_account']['cloud_cash_bank_account_id']);
        $this->assertNull($envelope['purchase_bill']);
        $this->assertSame(3000.0, (float) $envelope['amount']);
        $this->assertSame('cash', $envelope['payment_method']);
        $this->assertSame($fresh['watermark'], $envelope['freshness']['supplier_finance_watermark']);
        $this->assertSame($this->userId, $envelope['actor']['user_id']);
        $this->assertSame($this->terminalId, $envelope['actor']['terminal_id']);

        // NO fake local official finance: no GL, no AP journal, no cash/bank ledger movement was written by the appliance.
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'supplier_payment')->count());
        $this->assertSame(0, DB::table('supplier_payments')->count());
        $this->assertSame(0, DB::table('cash_bank_account_transactions')->count());
        $this->assertSame(1, DB::table('supplier_ledgers')->where('supplier_id', $this->supplierAId)->count(), 'the (Cloud) subledger seed row only');
    }

    public function test_cash_bank_is_required_and_a_ledger_only_payment_is_impossible(): void
    {
        $this->refuse(fn () => $this->pay(['cloud_cash_bank_account_id' => null]), 'ledger-only', 'cloud_cash_bank_account_id');
        $this->refuse(fn () => $this->pay(['cloud_cash_bank_account_id' => 0]), 'ledger-only');
        $this->refuse(fn () => $this->pay(['cloud_cash_bank_account_id' => $this->inactiveCbId]), 'ACTIVE Cash/Bank');
        $this->refuse(fn () => $this->pay(['cloud_cash_bank_account_id' => $this->unmappedCbId]), 'not mapped to a chart-of-accounts account');
        $this->refuse(fn () => $this->pay(['cloud_cash_bank_account_id' => 999999]), 'ACTIVE Cash/Bank');
        $this->assertEmpty(collect($this->cache()->cashBankAccounts(true))->whereIn('cloud_cash_bank_account_id', [$this->unmappedCbId, $this->inactiveCbId]), 'the UI options never offer an unusable account');
        $this->nothingRecorded();
    }

    public function test_overpayment_fails_closed_before_any_mutation_and_the_exact_payable_is_allowed(): void
    {
        $this->assertFalse($this->cache()->rules()['supplier_advance_supported'], 'SUPPLIER_ADVANCE_SUPPORTED=no (canonical)');
        $this->refuse(fn () => $this->pay(['cloud_supplier_id' => $this->supplierBId, 'amount' => 5000.01]), 'advance', 'amount');
        $this->nothingRecorded();

        $ok = $this->pay(['cloud_supplier_id' => $this->supplierBId, 'amount' => 5000]);
        $this->assertSame(0.0, $ok['position']['available_payable'], 'the whole payable may be settled');
        $this->refuse(fn () => $this->pay(['cloud_supplier_id' => $this->supplierBId, 'amount' => 1]), 'advance');
        $this->assertSame(1, DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count());
    }

    public function test_two_payments_aggregate_never_exceed_the_boundary(): void
    {
        $this->pay(['amount' => 7000]);
        $this->refuse(fn () => $this->pay(['amount' => 7000]), 'advance');
        $this->assertSame(3000.0, $this->cache()->position($this->supplierAId)['available_payable']);
        $this->pay(['amount' => 3000]);
        $this->assertSame(0.0, $this->cache()->position($this->supplierAId)['available_payable']);
        $this->assertSame(-10000.0, (float) DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->where('cloud_supplier_id', $this->supplierAId)->sum('payable_delta'));
        $this->assertSame(2, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_PAYMENT)->count());
    }

    public function test_a_specific_bill_allocation_is_optional_capped_and_must_belong_to_the_supplier(): void
    {
        $bills = $this->cache()->openBills($this->supplierAId);
        $this->assertSame(4000.0, $bills[0]['available']);
        $this->refuse(fn () => $this->pay(['cloud_bill_id' => $this->billAId, 'amount' => 4500]), 'outstanding', 'amount');
        $this->refuse(fn () => $this->pay(['cloud_supplier_id' => $this->supplierBId, 'cloud_bill_id' => $this->billAId, 'amount' => 100]), 'not an open bill of this supplier');
        $this->nothingRecorded();

        $ev = $this->pay(['cloud_bill_id' => $this->billAId, 'amount' => 2500]);
        $this->assertSame($this->billAId, $ev['payload']['cloud_bill_id']);
        $this->assertSame('PB-A-1', $ev['payload']['bill_no']);
        $bills = $this->cache()->openBills($this->supplierAId);
        $this->assertSame(2500.0, $bills[0]['pending_allocated']);
        $this->assertSame(1500.0, $bills[0]['available']);
        $this->refuse(fn () => $this->pay(['cloud_bill_id' => $this->billAId, 'amount' => 2000]), 'outstanding');
        $this->pay(['amount' => 2000]);   // on account — still fine (bill optional)
        $this->assertSame(5500.0, $this->cache()->position($this->supplierAId)['available_payable']);
        $envelope = json_decode((string) EdgeSyncOutbox::on('tenant')->where('sale_uuid', $ev['event_uuid'])->value('envelope'), true);
        $this->assertEquals(['cloud_bill_id' => $this->billAId, 'bill_no' => 'PB-A-1'], $envelope['purchase_bill']);
    }

    public function test_inactive_supplier_and_provider_card_payment_are_refused_and_bank_bookkeeping_is_allowed(): void
    {
        $this->refuse(fn () => $this->pay(['cloud_supplier_id' => $this->supplierInactiveId, 'amount' => 100]), 'inactive');
        $this->refuse(fn () => $this->pay(['payment_method' => 'card']), 'needs the Online POS', 'payment_method');
        $this->refuse(fn () => $this->pay(['payment_method' => 'wire']), 'Select how');
        $this->refuse(fn () => $this->pay(['amount' => 0]), 'at least 0.01');
        $this->nothingRecorded();
        $ev = $this->pay(['payment_method' => 'bank_transfer', 'cloud_cash_bank_account_id' => $this->bankCbId, 'transaction_ref' => 'TT-9', 'bank_name' => 'HBL']);
        $this->assertSame('bank_transfer', $ev['payload']['payment_method']);
        $bank = collect($this->cache()->cashBankAccounts())->firstWhere('cloud_cash_bank_account_id', $this->bankCbId);
        $this->assertSame(200000.0 - 1000.0, $bank['projected_balance']);
    }

    public function test_a_stale_projection_fails_closed_for_payments_and_journals(): void
    {
        // The Cloud advertised a NEWER watermark than the appliance holds and the last acknowledged heartbeat is after our cache.
        DB::table('edge_local_meta')->update(['standby_supplier_finance_watermark_seen' => 'sf:newer-cloud-position', 'authority_last_ack_at' => now()]);
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class);
        $f = $this->cache()->freshness();
        $this->assertFalse($f['ok']);
        $this->refuse(fn () => $this->pay([]), 'not current on this branch server');
        $this->refuse(fn () => $this->svc()->postApJournal(['description' => 'accrual', 'lines' => [
            ['cloud_account_id' => $this->accountId('5100'), 'debit' => 100], ['cloud_account_id' => $this->accountId('2100'), 'credit' => 100, 'cloud_supplier_id' => $this->supplierAId],
        ]], $this->user()), 'not current on this branch server');
        $this->nothingRecorded();
        // Never a guessed balance: the cache still reports the Cloud position it holds, flagged stale.
        $this->assertSame(10000.0, $this->cache()->position($this->supplierAId)['cloud_payable']);
    }

    public function test_the_manual_ap_journal_moves_the_provisional_payable_and_every_ap_line_names_its_supplier(): void
    {
        $ap = $this->accountId('2100');
        $expense = $this->accountId('6100');
        $cashCoa = $this->accountId('1110');
        $journal = fn (array $lines, string $desc = 'test journal') => $this->svc()->postApJournal(['description' => $desc, 'reference_no' => 'MJ-1', 'lines' => $lines], $this->user(), $this->terminalId);

        // Dr Expense / Cr AP (supplier) → the supplier's payable INCREASES.
        $up = $journal([
            ['cloud_account_id' => $expense, 'debit' => 7000, 'description' => 'rent accrual'],
            ['cloud_account_id' => $ap, 'credit' => 7000, 'cloud_supplier_id' => $this->supplierBId],
        ], 'Dr Expense / Cr AP');
        $this->assertSame(EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, $up['event_type']);
        $this->assertSame(12000.0, $this->cache()->position($this->supplierBId)['available_payable'], 'Cr AP raised the payable 5,000 → 12,000');
        $this->assertSame(1, DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->where('event_uuid', $up['event_uuid'])->count());
        $envelope = json_decode((string) EdgeSyncOutbox::on('tenant')->where('sale_uuid', $up['event_uuid'])->value('envelope'), true);
        $this->assertSame(EdgeSupplierFinanceEnvelopeBuilder::SCHEMA_AP_JOURNAL, $envelope['envelope_schema_version']);
        $this->assertSame('supplier', $envelope['lines'][1]['counterparty_type']);
        $this->assertSame($this->supplierBId, $envelope['lines'][1]['cloud_supplier_id']);
        $this->assertNull($envelope['lines'][0]['counterparty_type'], 'a non-AP line carries no counterparty');
        $this->assertSame('2100', $envelope['lines'][1]['account_code']);
        $copy = $envelope;
        unset($copy['content_hash']);
        $this->assertSame(hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($copy)), $envelope['content_hash']);

        // Dr AP (supplier) / Cr Cash (cash/bank dimension) → payable and projected cash both DECREASE once.
        $tillBefore = collect($this->cache()->cashBankAccounts())->firstWhere('cloud_cash_bank_account_id', $this->tillCbId)['projected_balance'];
        $down = $journal([
            ['cloud_account_id' => $ap, 'debit' => 2000, 'cloud_supplier_id' => $this->supplierBId],
            ['cloud_account_id' => $cashCoa, 'credit' => 2000, 'cloud_cash_bank_account_id' => $this->tillCbId],
        ], 'Dr AP / Cr Cash');
        $this->assertSame(10000.0, $this->cache()->position($this->supplierBId)['available_payable']);
        $this->assertSame($tillBefore - 2000.0, collect($this->cache()->cashBankAccounts())->firstWhere('cloud_cash_bank_account_id', $this->tillCbId)['projected_balance']);
        $this->assertSame(2, DB::table(EdgeSupplierFinanceCacheService::T_EFFECTS)->where('event_uuid', $down['event_uuid'])->count(), 'one payable effect + one cash effect');

        // The ledger shows both provisional adjustments as PENDING SYNC.
        $ledger = $this->svc()->ledger($this->supplierBId);
        $this->assertSame(2, $ledger['provisional_count']);
        $this->assertSame('journal_adjustment', $ledger['rows'][0]['entry_type']);

        // Refusals — AP_SUPPLIER_REQUIRED, supplier on a non-AP line, unbalanced, unknown account, advance, wrong cash/bank mapping.
        $count = DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count();
        $this->refuse(fn () => $journal([['cloud_account_id' => $expense, 'debit' => 500], ['cloud_account_id' => $ap, 'credit' => 500]]), 'must name the supplier', 'lines.1.cloud_supplier_id');
        $this->refuse(fn () => $journal([['cloud_account_id' => $expense, 'debit' => 500, 'cloud_supplier_id' => $this->supplierAId], ['cloud_account_id' => $ap, 'credit' => 500, 'cloud_supplier_id' => $this->supplierAId]]), 'Only an Accounts Payable line carries a supplier');
        $this->refuse(fn () => $journal([['cloud_account_id' => $expense, 'debit' => 500], ['cloud_account_id' => $ap, 'credit' => 400, 'cloud_supplier_id' => $this->supplierAId]]), 'not balanced');
        $this->refuse(fn () => $journal([['cloud_account_id' => 999999, 'debit' => 500], ['cloud_account_id' => $ap, 'credit' => 500, 'cloud_supplier_id' => $this->supplierAId]]), 'active account');
        $this->refuse(fn () => $journal([['cloud_account_id' => $ap, 'debit' => 10001, 'cloud_supplier_id' => $this->supplierAId], ['cloud_account_id' => $expense, 'credit' => 10001]]), 'advance');
        $this->refuse(fn () => $journal([['cloud_account_id' => $ap, 'debit' => 100, 'cloud_supplier_id' => $this->supplierAId], ['cloud_account_id' => $expense, 'credit' => 100, 'cloud_cash_bank_account_id' => $this->tillCbId]]), 'mapped to chart account');
        $this->refuse(fn () => $journal([['cloud_account_id' => $ap, 'debit' => 100, 'cloud_supplier_id' => $this->supplierInactiveId], ['cloud_account_id' => $expense, 'credit' => 100]]), 'inactive');
        $this->refuse(fn () => $journal([['cloud_account_id' => $expense, 'debit' => 100]]), 'at least two lines');
        $this->assertSame($count, DB::table(EdgeSupplierFinanceCacheService::T_EVENTS)->count(), 'refusals record nothing');
        $this->assertSame(0, DB::table('journal_entries')->count(), 'the appliance never posts a GL entry itself');
    }

    public function test_handback_findings_name_pending_failed_and_divergent_supplier_finance_events(): void
    {
        $this->assertSame(['pending' => 0, 'failed' => 0, 'divergent' => 0], array_intersect_key($this->cache()->handbackFindings(), array_flip(['pending', 'failed', 'divergent'])));
        $a = $this->pay(['amount' => 1000]);
        $b = $this->pay(['amount' => 500, 'cloud_supplier_id' => $this->supplierBId]);
        $c = $this->svc()->postApJournal(['description' => 'accrual', 'lines' => [
            ['cloud_account_id' => $this->accountId('6100'), 'debit' => 300], ['cloud_account_id' => $this->accountId('2100'), 'credit' => 300, 'cloud_supplier_id' => $this->supplierAId],
        ]], $this->user(), $this->terminalId);
        $this->assertSame(['pending' => 3, 'failed' => 0, 'divergent' => 0], array_intersect_key($this->cache()->handbackFindings(), array_flip(['pending', 'failed', 'divergent'])), 'three events still syncing → SUPPLIER_FINANCE_PENDING');

        // The Cloud refused one permanently; another was acknowledged without an applied verdict; a third lost its outbox row.
        DB::table('edge_sync_outbox')->where('sale_uuid', $a['event_uuid'])->update(['state' => EdgeSyncOutbox::STATE_FAILED_PERMANENT, 'last_error' => 'refused:PAYMENT_REFUSED']);
        DB::table('edge_sync_outbox')->where('sale_uuid', $b['event_uuid'])->update(['state' => EdgeSyncOutbox::STATE_ACKNOWLEDGED, 'acknowledged_at' => now(), 'ack_payload' => json_encode(['status' => 'refused', 'sale_uuid' => $b['event_uuid'], 'event_uuid' => $b['event_uuid']])]);
        DB::table('edge_sync_outbox')->where('sale_uuid', $c['event_uuid'])->delete();
        $f = $this->cache()->handbackFindings();
        $this->assertSame(['pending' => 0, 'failed' => 1, 'divergent' => 2], array_intersect_key($f, array_flip(['pending', 'failed', 'divergent'])), json_encode($f));
        $this->assertCount(2, $f['details']);
        $this->assertSame('failed', $this->cache()->event($a['event_uuid'])['sync']['state']);
        $this->assertSame('missing', $this->cache()->event($c['event_uuid'])['sync']['state']);
    }

    public function test_a_normal_cashier_without_the_online_permission_is_refused_server_side(): void
    {
        $cashier = User::on('tenant')->find($this->cashierId);
        $this->assertFalse($cashier->can(EdgeLocalSupplierFinanceService::PERM_PAYMENT));
        try {
            $this->svc()->recordPayment(['cloud_supplier_id' => $this->supplierAId, 'cloud_cash_bank_account_id' => $this->tillCbId, 'amount' => 10, 'payment_method' => 'cash'], $cashier, $this->terminalId);
            $this->fail('a cashier must not record supplier payments');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
        try {
            $this->svc()->postApJournal(['description' => 'x', 'lines' => [['cloud_account_id' => $this->accountId('6100'), 'debit' => 10], ['cloud_account_id' => $this->accountId('2100'), 'credit' => 10, 'cloud_supplier_id' => $this->supplierAId]]], $cashier);
            $this->fail('a cashier must not post manual journals');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
        $this->nothingRecorded();
        $perms = $this->svc()->permissionsFor($cashier);
        $this->assertSame(['can_view_ledger' => false, 'can_pay' => false, 'can_journal' => false], $perms);
    }
}
