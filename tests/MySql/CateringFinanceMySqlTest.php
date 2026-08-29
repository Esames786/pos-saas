<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CateringEvent;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinalInvoiceService;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * CATERING-GO-LIVE-READINESS-1 (§5/§6): the full accounting contract on real
 * GL rows — advance liability, invoice AR/revenue, advance clearing,
 * settlement, zero-difference reconciliation, replay idempotency, conflict
 * refusal, and cash/bank movement without duplicates.
 */
class CateringFinanceMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private CateringEstimateService $estimates;

    private CateringAdvanceService $advances;

    private CateringFinalInvoiceService $invoices;

    private int $branchId;

    private int $cashAccountId;      // cash_bank_accounts.id

    private int $paymentMethodId;    // mapped to the cash account

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        Mail::fake();

        $this->cleanTenant([
            'catering_email_logs', 'catering_event_reminders', 'catering_material_issue_lines', 'catering_material_issues',
            'catering_production_release_lines', 'catering_production_releases', 'catering_refunds', 'catering_final_invoices',
            'catering_advances', 'catering_cost_snapshots', 'catering_estimate_lines', 'catering_estimates',
            'catering_events', 'catering_material_rates', 'catering_product_profiles', 'catering_settings',
            'journal_lines', 'journal_entries', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'accounts', 'payment_methods', 'sale_payments', 'sales_ledgers', 'sales_order_lines', 'sales_orders',
            'shifts', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'products', 'categories', 'customers', 'branches',
        ]);

        (new DefaultChartOfAccountsSeeder)->run();

        $this->estimates = app(CateringEstimateService::class);
        $this->advances = app(CateringAdvanceService::class);
        $this->invoices = app(CateringFinalInvoiceService::class);

        $this->branchId = $this->makeBranch();

        // Real cash/bank account mapped to the 1110 Main Cash Drawer CoA account.
        $this->cashAccountId = $this->tenant()->table('cash_bank_accounts')->insertGetId([
            'code' => 'CB-'.uniqid(), 'name' => 'Catering Cash', 'account_type' => 'cash',
            'account_id' => Account::where('code', '1110')->value('id'),
            'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->cashAccountId]);
    }

    /** Confirmed 100,000 event ready for billing. */
    private function confirmedEvent(): CateringEvent
    {
        $categoryId = $this->makeCategory();
        $productId = $this->makeProduct($categoryId, ['default_purchase_price' => 400]);
        $this->tenant()->table('catering_material_rates')->insert([
            'product_id' => $productId, 'rate' => 400, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $event = $this->estimates->createEvent([
            'branch_id' => $this->branchId,
            'customer_name' => 'Finance Test Customer',
            'booking_date' => now()->toDateString(),
            'event_date' => now()->addDays(5)->toDateString(),
            'pax' => 200,
        ]);
        $this->estimates->saveDraftLines($event->currentEstimate, [
            ['product_id' => $productId, 'item_name' => 'Catering Package', 'quantity' => 100, 'rate' => 1000],
        ]); // grand 100,000
        $this->estimates->markSent($event->currentEstimate->refresh());
        $this->estimates->confirmEvent($event->refresh());

        return $event->refresh();
    }

    /** Net GL movement for an account code: debits − credits. */
    private function accountNet(string $code): float
    {
        $accountId = Account::where('code', $code)->value('id');
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->where('account_id', $accountId)->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    public function test_full_accounting_lifecycle_reconciles_to_zero_difference(): void
    {
        $event = $this->confirmedEvent();

        // ── A. Advance receipt 30,000 (pre-invoice) ─────────────────────────
        $advance = $this->advances->record($event, [
            'amount' => 30000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId, 'reference' => 'ADV-1',
        ]);

        $this->assertSame('advance', $advance->posting_type);
        $this->assertNotNull($advance->journal_entry_id);
        $this->assertSame(-30000.0, $this->accountNet('2300'), 'Cr 2300 liability exists from the advance');
        $this->assertSame(30000.0, $this->accountNet('1110'), 'Dr cash drawer via payment-method mapping');
        $this->assertEqualsWithDelta(30000.0, (float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 0.001, 'cash/bank balance moved');

        // ── B + C. Final invoice 100,000: revenue/AR + advance clearing ─────
        $invoice = $this->invoices->issue($event->refresh());

        $this->assertNotNull($invoice->journal_entry_id, 'invoice GL linked');
        $this->assertNotNull($invoice->advance_application_journal_entry_id, 'advance application GL linked');
        $this->assertSame(100000.0, -$this->accountNet('4160'), '100,000 catering revenue recognized');
        $this->assertSame(0.0, $this->accountNet('2300'), '30,000 advance liability fully cleared against AR');
        $this->assertSame(70000.0, $this->accountNet('1300'), '70,000 remains due on AR');
        $this->assertSame('70000.00', (string) $invoice->balance_due);

        // ── D. Settlement 70,000 (post-invoice) ─────────────────────────────
        $settlement = $this->advances->record($event->refresh(), [
            'amount' => 70000, 'received_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethodId, 'reference' => 'FINAL',
        ]);

        $this->assertSame('settlement', $settlement->posting_type, 'post-invoice receipt settles AR, not the advance liability');
        $this->assertSame(0.0, $this->accountNet('1300'), 'customer AR balance is ZERO');
        $this->assertSame(0.0, $this->accountNet('2300'), 'advance liability stays zero');
        $this->assertSame(100000.0, $this->accountNet('1110'), 'cash holds the full 100,000');
        $this->assertEqualsWithDelta(100000.0, (float) $this->tenant()->table('cash_bank_accounts')
            ->where('id', $this->cashAccountId)->value('current_balance'), 0.001);

        // Event closes at zero balance.
        $this->invoices->close($event->refresh());
        $this->assertSame(CateringEvent::STATUS_CLOSED, $event->refresh()->status);

        // ── Reconciliation: every entry balanced; books net to zero ─────────
        foreach ($this->tenant()->table('journal_entries')->get() as $entry) {
            $this->assertEqualsWithDelta((float) $entry->total_debit, (float) $entry->total_credit, 0.001,
                "journal {$entry->entry_no} must balance");
        }
        $row = $this->tenant()->table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();
        $this->assertEqualsWithDelta((float) $row->d, (float) $row->c, 0.001,
            'the whole catering ledger reconciles to zero difference');

        // ── Replay every posting: no duplicate journals / cash-bank rows ────
        $journalCount = (int) $this->tenant()->table('journal_entries')->count();
        $cashTxnCount = (int) $this->tenant()->table('cash_bank_account_transactions')->count();

        $posting = app(JournalPostingService::class);
        $advance->setRelation('event', $event);
        $settlement->setRelation('event', $event);
        $posting->postCateringAdvance($advance->refresh()->setRelation('event', $event));
        $posting->postCateringSettlement($settlement->refresh()->setRelation('event', $event));
        $posting->postCateringFinalInvoice($invoice->refresh()->load('event'));
        $posting->applyCateringAdvance($invoice);

        $this->assertSame($journalCount, (int) $this->tenant()->table('journal_entries')->count(),
            'exact replays return existing postings — never duplicates');
        $this->assertSame($cashTxnCount, (int) $this->tenant()->table('cash_bank_account_transactions')->count());

        // No POS involvement anywhere.
        foreach (['sales_orders', 'sale_payments', 'shifts', 'stock_ledgers'] as $table) {
            $this->assertSame(0, (int) $this->tenant()->table($table)->count());
        }
    }

    public function test_conflicting_replay_refuses_and_unmapped_method_uses_undeposited_funds(): void
    {
        $event = $this->confirmedEvent();

        // Unmapped receipt: GL Dr 1500 Undeposited Funds, NO cash/bank movement.
        $advance = $this->advances->record($event, [
            'amount' => 10000, 'received_date' => now()->toDateString(),
        ]);
        $this->assertSame(10000.0, $this->accountNet('1500'), 'unmapped receipt debits Undeposited Funds');
        $this->assertSame(0, (int) $this->tenant()->table('cash_bank_account_transactions')->count(),
            'no cash/bank account, no cash/bank movement');

        // Same identity + conflicting payload → REFUSED, nothing merged.
        $this->tenant()->table('catering_advances')->where('id', $advance->id)->update(['amount' => 99999]);
        try {
            app(JournalPostingService::class)->postCateringAdvance(
                \App\Models\Tenant\CateringAdvance::find($advance->id)->setRelation('event', $event)
            );
            $this->fail('a replay with a conflicting financial payload must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('conflicting financial payloads', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->tenant()->table('journal_entries')->count(), 'nothing extra was posted');
    }
}
