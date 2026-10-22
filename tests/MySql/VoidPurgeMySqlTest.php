<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurgedEntry;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Catering\CateringAdvanceService;
use App\Services\Catering\CateringEstimateService;
use App\Services\Catering\CateringFinancialPositionService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\PurchasingService;
use App\Support\PurgedNumbers;
use App\Support\VoidDate;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * VOID-PURGE-1 — the Owner deletes, permanently, an entry that was voided on its own date, with its reversal.
 *
 * Through the real routes, for each v1 type (supplier payment, manual journal, expense voucher, catering receipt):
 * every row of both halves goes; the hidden copy holds every one of them; every stored total (drawer, supplier, bill,
 * GL per account) is exactly what it was; the running balance of a row posted between the two halves is corrected;
 * numbers are never handed out again. Every refusal — live entry, a void dated another day, a settlement receipt, a
 * shortage voucher, a wrong password, no reason, no permission, the switch off — changes nothing. A failure halfway
 * puts every row back. The buttons and the Owner's log appear only with the switch and the permission.
 */
class VoidPurgeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const OPENING = 100000.0;
    private const PASSWORD = 'owner-pass';

    private int $tenantId;
    private int $ownerId;
    private int $clerkId;
    private int $branchId;
    private int $supplierId;
    private int $drawerId;
    private int $productId;
    private int $paymentMethodId;
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);
        Mail::fake();
        $this->host = 'vpurge.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->bootTenant();
        PurgedNumbers::forget();
    }

    protected function tearDown(): void
    {
        PurgedEntry::flushEventListeners();
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'vpurge')->delete();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    /* ── the four kinds ──────────────────────────────────────────────────── */

    public function test_a_supplier_payment_voided_on_its_own_date_goes_with_every_row_and_no_total_moves(): void
    {
        $day = now()->subDays(3)->toDateString();
        $this->postBill(1000);
        $wrong = $this->pay(500, null, $day);
        $between = $this->pay(200, null, $day);           // posted before the void: its stored balances carry the 500
        $this->voidPayment($wrong, 'original')->assertSessionHasNoErrors();

        $betweenCash = $this->cashRow('supplier_payment', $between->id, 'supplier_payment');
        $betweenLedger = $this->ledgerRow($between->id, 'payment');
        $before = $this->books();

        $this->purge('supplier_payment', $wrong->id)->assertSessionHasNoErrors()->assertRedirect();

        $c = DB::connection('tenant');
        $this->assertNull($c->table('supplier_payments')->find($wrong->id));
        $this->assertSame(0, $c->table('journal_entries')->whereIn('source_type', ['supplier_payment', 'supplier_payment_reversal'])->where('source_id', $wrong->id)->count());
        $this->assertSame(0, $c->table('cash_bank_account_transactions')->where('reference_type', 'supplier_payment')->where('reference_id', $wrong->id)->count());
        $this->assertSame(0, $c->table('supplier_ledgers')->where('reference_type', SupplierPayment::class)->where('reference_id', $wrong->id)->count());

        $copy = PurgedEntry::sole();
        $this->assertSame(['cash_bank_account_transactions' => 2, 'journal_entries' => 2, 'journal_lines' => 4, 'supplier_ledgers' => 2, 'supplier_payments' => 1],
            $this->counts($copy), 'the hidden copy holds every row of both halves');
        $this->assertSame($before, $this->books(), 'drawer, supplier, bills and every GL account are exactly what they were');

        $this->assertEqualsWithDelta((float) $betweenCash->balance_after + 500, (float) $this->cashRow('supplier_payment', $between->id, 'supplier_payment')->balance_after, 0.001,
            'the payment posted in between no longer carries the deleted 500');
        $this->assertEqualsWithDelta(self::OPENING - 200, (float) $this->cashRow('supplier_payment', $between->id, 'supplier_payment')->balance_after, 0.001);
        $this->assertEqualsWithDelta((float) $betweenLedger->balance_after + 500, (float) $this->ledgerRow($between->id, 'payment')->balance_after, 0.001,
            'and nor does its supplier-ledger row');

        $this->cashBook($day);                                    // shows (and uses up) the "deleted" message, which names the payment
        $book = $this->cashBook($day);
        $this->assertStringNotContainsString($wrong->payment_no, $book, 'the pair is gone from the Cash Book');
        $this->assertStringContainsString($between->payment_no, $book);
    }

    public function test_a_manual_journal_voided_on_its_own_date_goes_and_its_numbers_are_never_reused(): void
    {
        $day = now()->subDays(2)->toDateString();
        $journal = $this->manualJournal(900, $day);
        $this->actingAsOwner()->post($this->url('/finance/manual-journals/' . $journal->id . '/reverse'),
            ['reason' => 'posted to the wrong account', 'void_date_mode' => 'original'])->assertSessionHasNoErrors();
        $reversal = JournalEntry::where('reversed_entry_id', $journal->id)->sole();
        $before = $this->books();

        $this->purge('manual_journal', $journal->id)->assertSessionHasNoErrors();

        $c = DB::connection('tenant');
        $this->assertSame(0, $c->table('journal_entries')->whereIn('id', [$journal->id, $reversal->id])->count());
        $this->assertSame(0, $c->table('journal_lines')->whereIn('journal_entry_id', [$journal->id, $reversal->id])->count());
        $this->assertSame(0, $c->table('cash_bank_account_transactions')->whereIn('transaction_type', ['manual_journal', 'manual_journal_reversal'])->count());
        $this->assertSame(['cash_bank_account_transactions' => 2, 'journal_entries' => 2, 'journal_lines' => 4], $this->counts(PurgedEntry::sole()));
        $this->assertSame($before, $this->books());

        // The next journal on that day does not take a deleted number, nor its manual-journal number.
        $next = $this->manualJournal(300, $day);
        $this->assertNotContains($next->entry_no, [$journal->entry_no, $reversal->entry_no], 'a deleted journal number is never handed out again');
        $this->assertGreaterThan((int) $journal->source_id, (int) $next->source_id);
        $this->assertGreaterThan(max($journal->entry_no, $reversal->entry_no), $next->entry_no);

        $list = $this->actingAsOwner()->get($this->url('/finance/journal-entries?date_from=' . $day . '&date_to=' . $day))->assertOk()->getContent();
        $this->assertStringContainsString($next->entry_no, $list, 'the journal list shows that day');
        $this->assertStringNotContainsString($journal->entry_no, $list, 'and no longer shows the deleted pair');
        $this->assertStringNotContainsString($reversal->entry_no, $list);
    }

    public function test_an_expense_voided_on_its_own_date_goes_with_its_lines_cash_and_journal(): void
    {
        $day = now()->subDays(5)->toDateString();
        $voucher = $this->expense(700, $day);
        $this->assertTrue(JournalEntry::where('source_type', 'expense_voucher')->where('source_id', $voucher->id)->exists(), 'precondition: the expense is in the books');
        $this->actingAsOwner()->post($this->url('/finance/expenses/' . $voucher->id . '/void'),
            ['void_reason' => 'entered twice', 'void_date_mode' => 'original'])->assertSessionHasNoErrors();
        $before = $this->books();

        $this->purge('expense', $voucher->id)->assertSessionHasNoErrors();

        $c = DB::connection('tenant');
        $this->assertNull($c->table('expense_vouchers')->find($voucher->id));
        $this->assertSame(0, $c->table('expense_voucher_lines')->where('expense_voucher_id', $voucher->id)->count());
        $this->assertSame(0, $c->table('journal_entries')->whereIn('source_type', ['expense_voucher', 'expense_voucher_reversal'])->where('source_id', $voucher->id)->count());
        $this->assertSame(['cash_bank_account_transactions' => 2, 'expense_voucher_lines' => 1, 'expense_vouchers' => 1, 'journal_entries' => 2, 'journal_lines' => 4],
            $this->counts(PurgedEntry::sole()));
        $this->assertSame($before, $this->books());

        $next = $this->expense(100, $day);
        $this->assertNotSame($voucher->voucher_no, $next->voucher_no, 'a deleted voucher number is never handed out again');
    }

    public function test_a_catering_receipt_voided_on_its_own_date_goes_with_its_cash_journal_and_email_log(): void
    {
        $day = now()->subDays(3)->toDateString();
        $advance = $this->cateringReceipt(40000, $day);
        DB::connection('tenant')->table('catering_email_logs')->insert(['catering_event_id' => $advance->catering_event_id, 'email_type' => 'advance_received',
            'dedupe_key' => 'advance-' . $advance->id, 'recipient' => 'client@test.local', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(CateringAdvanceService::class)->void($advance, 'wrong slip', $this->ownerId, VoidDate::ORIGINAL);
        $before = $this->books();
        $event = CateringEvent::findOrFail($advance->catering_event_id);
        $position = app(CateringFinancialPositionService::class)->position($event);

        $this->purge('catering_receipt', $advance->id)->assertSessionHasNoErrors();

        $this->assertSame($position['balance_due'], app(CateringFinancialPositionService::class)->position($event->fresh())['balance_due'],
            'the booking still owes exactly what it owed after the void');

        $c = DB::connection('tenant');
        $this->assertNull($c->table('catering_advances')->find($advance->id));
        $this->assertSame(0, $c->table('cash_bank_account_transactions')->where('reference_type', 'catering_advance')->where('reference_id', $advance->id)->count());
        $this->assertSame(0, $c->table('journal_entries')->whereIn('source_type', ['catering_advance', 'catering_advance_reversal'])->where('source_id', $advance->id)->count());
        $this->assertSame(0, $c->table('catering_email_logs')->where('dedupe_key', 'advance-' . $advance->id)->count());
        $this->assertSame(['cash_bank_account_transactions' => 2, 'catering_advances' => 1, 'catering_email_logs' => 1, 'journal_entries' => 2, 'journal_lines' => 4],
            $this->counts(PurgedEntry::sole()));
        $this->assertSame($before, $this->books());
    }

    /* ── refusals: each one changes nothing ───────────────────────────────── */

    public function test_every_refusal_says_why_and_changes_nothing(): void
    {
        $this->postBill(3000);
        $live = $this->pay(400);
        $today = $this->pay(300, null, now()->subDays(4)->toDateString());
        $this->voidPayment($today, 'today')->assertSessionHasNoErrors();              // "reverse today": two different days
        $own = $this->pay(250, null, now()->subDays(2)->toDateString());
        $this->voidPayment($own, 'original')->assertSessionHasNoErrors();
        $shortage = $this->expense(50, now()->subDay()->toDateString(), 'EXP-SHORT-' . Str::upper(Str::random(5)));
        $this->actingAsOwner()->post($this->url('/finance/expenses/' . $shortage->id . '/void'), ['void_reason' => 'shortage', 'void_date_mode' => 'original']);
        $settlement = $this->cateringReceipt(10000, now()->subDays(2)->toDateString());
        app(CateringAdvanceService::class)->void($settlement, 'wrong', $this->ownerId, VoidDate::ORIGINAL);
        DB::connection('tenant')->table('catering_advances')->where('id', $settlement->id)->update(['posting_type' => CateringAdvance::POSTING_SETTLEMENT]);

        $tables = ['supplier_payments', 'journal_entries', 'journal_lines', 'cash_bank_account_transactions', 'supplier_ledgers', 'expense_vouchers', 'catering_advances', 'purchase_bills'];
        $snapshot = $this->fingerprint($tables);

        foreach ([
            ['supplier_payment', $live->id, [], 'Only a voided supplier payment can be deleted'],
            ['supplier_payment', $today->id, [], 'reversed on a different day'],
            ['expense', $shortage->id, [], 'cash-shortage voucher'],
            ['catering_receipt', $settlement->id, [], 'part of a settlement'],
            ['supplier_payment', $own->id, ['purge_password' => 'wrong'], 'The password did not match'],
        ] as [$type, $id, $override, $says]) {
            $this->purge($type, $id, $override)->assertSessionHasErrors();
            $this->assertStringContainsString($says, collect(session('errors')->all())->implode(' '), "{$type} #{$id}");
        }
        $this->purge('supplier_payment', $own->id, ['purge_reason' => 'no'])->assertSessionHasErrors('purge_reason');
        $this->actingAs(User::on('tenant')->find($this->clerkId), 'tenant')
            ->post($this->url('/finance/voided-entries/supplier_payment/' . $own->id . '/purge'), ['purge_reason' => 'not mine to delete', 'purge_password' => 'x'])
            ->assertForbidden();
        $this->setFeatures([]);
        $this->purge('supplier_payment', $own->id)->assertNotFound();

        $this->assertSame($snapshot, $this->fingerprint($tables), 'not one row moved');
        $this->assertSame(0, PurgedEntry::count());
    }

    public function test_a_failure_halfway_puts_every_row_back(): void
    {
        $this->postBill(1000);
        $wrong = $this->pay(500, null, now()->subDays(2)->toDateString());
        $this->voidPayment($wrong, 'original')->assertSessionHasNoErrors();
        $tables = ['supplier_payments', 'journal_entries', 'journal_lines', 'cash_bank_account_transactions', 'supplier_ledgers', 'purged_numbers'];
        $snapshot = $this->fingerprint($tables);

        // Fails AFTER the rows are deleted, when the hidden copy is written.
        PurgedEntry::creating(fn () => throw new \RuntimeException('disk full while writing the copy'));
        $this->purge('supplier_payment', $wrong->id)->assertSessionHasErrors('purge');

        $this->assertSame($snapshot, $this->fingerprint($tables), 'everything rolled back');
        $this->assertSame(0, PurgedEntry::count());
    }

    /* ── screens ─────────────────────────────────────────────────────────── */

    public function test_the_button_and_the_owner_log_appear_only_with_the_switch_and_the_permission(): void
    {
        $day = now()->subDays(1)->toDateString();
        $this->postBill(1000);
        $wrong = $this->pay(500, null, $day);
        $this->voidPayment($wrong, 'original')->assertSessionHasNoErrors();

        $this->assertStringContainsString('/finance/voided-entries/supplier_payment/' . $wrong->id . '/purge', $this->cashBook($day), 'the Owner sees it on the Cash Book');
        $page = $this->actingAsOwner()->get($this->url('/supplier-payments/' . $wrong->id))->assertOk()->getContent();
        $this->assertStringContainsString('Delete permanently', $page, 'and on the payment\'s own page');
        $this->assertStringNotContainsString('/purge', $this->actingAs(User::on('tenant')->find($this->clerkId), 'tenant')
            ->get($this->url('/reports/cash-book?' . http_build_query(['date_from' => $day, 'date_to' => now()->toDateString(), 'mode' => 'entries'])))->assertOk()->getContent(),
            'a user without the permission sees no button');

        $this->purge('supplier_payment', $wrong->id)->assertSessionHasNoErrors();
        $log = $this->actingAsOwner()->get($this->url('/finance/voided-entries'))->assertOk()->getContent();
        $this->assertStringContainsString($wrong->payment_no, $log);
        $this->assertStringContainsString('typed by mistake', $log, 'with the reason');
        $this->actingAs(User::on('tenant')->find($this->clerkId), 'tenant')->get($this->url('/finance/voided-entries'))->assertForbidden();

        $this->setFeatures([]);
        $this->assertStringNotContainsString('Delete permanently', $this->cashBook($day), 'switch off: no button anywhere');
        $this->actingAsOwner()->get($this->url('/finance/voided-entries'))->assertNotFound();
    }

    /* ── helpers ─────────────────────────────────────────────────────────── */

    private function purge(string $type, int $id, array $override = [])
    {
        return $this->actingAsOwner()->from($this->url('/finance/voided-entries'))
            ->post($this->url('/finance/voided-entries/' . $type . '/' . $id . '/purge'), $override + ['purge_reason' => 'typed by mistake', 'purge_password' => self::PASSWORD]);
    }

    /** What must not move: every drawer's and supplier's stored balance, every bill, and every GL account. */
    private function books(): array
    {
        $c = DB::connection('tenant');

        return [
            'drawers'   => $c->table('cash_bank_accounts')->orderBy('id')->pluck('current_balance', 'id')->map(fn ($v) => (string) $v)->all(),
            'suppliers' => $c->table('suppliers')->orderBy('id')->pluck('current_balance', 'id')->map(fn ($v) => (string) $v)->all(),
            'bills'     => $c->table('purchase_bills')->orderBy('id')->get(['id', 'amount_paid', 'balance_due', 'status'])->map(fn ($b) => (array) $b)->all(),
            'gl'        => $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.status', 'posted')
                ->groupBy('l.account_id')->orderBy('l.account_id')->selectRaw('l.account_id, ROUND(SUM(l.debit) - SUM(l.credit), 2) n')
                ->pluck('n', 'account_id')->filter(fn ($n) => abs((float) $n) > 0.001)->map(fn ($n) => (string) $n)->all(),
            'tb'        => (string) $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.status', 'posted')
                ->selectRaw('ROUND(COALESCE(SUM(l.debit) - SUM(l.credit), 0), 2) v')->value('v'),
        ];
    }

    private function counts(PurgedEntry $p): array
    {
        $out = collect($p->snapshot['tables'])->map(fn ($rows) => count($rows))->filter()->all();
        ksort($out);

        return $out;
    }

    private function fingerprint(array $tables): array
    {
        $c = DB::connection('tenant');

        return collect($tables)->filter(fn ($t) => $c->getSchemaBuilder()->hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => md5(json_encode($c->table($t)->orderBy('id')->get()))])->all();
    }

    private function cashRow(string $referenceType, int $id, string $type)
    {
        return DB::connection('tenant')->table('cash_bank_account_transactions')->where('reference_type', $referenceType)->where('reference_id', $id)
            ->where('transaction_type', $type)->first();
    }

    private function ledgerRow(int $paymentId, string $entryType)
    {
        return DB::connection('tenant')->table('supplier_ledgers')->where('reference_type', SupplierPayment::class)->where('reference_id', $paymentId)
            ->where('entry_type', $entryType)->first();
    }

    private function cashBook(string $from): string
    {
        return $this->actingAsOwner()->get($this->url('/reports/cash-book?' . http_build_query([
            'date_from' => $from, 'date_to' => now()->toDateString(), 'mode' => 'entries',
        ])))->assertOk()->getContent();
    }

    private function voidPayment(SupplierPayment $payment, string $mode)
    {
        return $this->actingAsOwner()->post($this->url('/supplier-payments/' . $payment->id . '/void'), ['reason' => 'paid twice by mistake', 'void_date_mode' => $mode]);
    }

    private function postBill(float $total): PurchaseBill
    {
        $bill = PurchaseBill::create([
            'bill_no' => 'BILL-' . Str::upper(Str::random(6)), 'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId,
            'bill_date' => now()->subDays(10)->toDateString(), 'subtotal' => $total, 'grand_total' => $total, 'amount_paid' => 0,
            'balance_due' => $total, 'status' => 'posted',
        ]);
        app(PurchasingService::class)->postBill($bill, $this->ownerId);

        return $bill->fresh();
    }

    private function pay(float $amount, ?int $billId = null, ?string $date = null): SupplierPayment
    {
        return app(SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->drawerId,
            'purchase_bill_id' => $billId, 'payment_date' => $date ?? now()->toDateString(), 'amount' => $amount, 'payment_method' => 'cash',
        ], $this->ownerId);
    }

    private function manualJournal(float $amount, string $date): JournalEntry
    {
        $this->actingAsOwner()->post($this->url('/finance/manual-journals'), [
            'entry_date' => $date, 'description' => 'Misc cash expense', 'reference_no' => '',
            'lines' => [
                ['account_id' => $this->accId('6800'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->accId('1110'), 'debit' => 0, 'credit' => $amount, 'cash_bank_account_id' => $this->drawerId],
            ],
        ])->assertSessionHasNoErrors();

        return JournalEntry::where('source_type', 'manual_journal')->orderByDesc('id')->firstOrFail();
    }

    private function expense(float $amount, string $date, ?string $voucherNo = null): ExpenseVoucher
    {
        $voucher = ExpenseVoucher::create([
            'voucher_no' => $voucherNo ?? (new \ReflectionMethod(\App\Http\Controllers\Tenant\Finance\ExpenseVoucherController::class, 'nextVoucherNo'))
                ->invoke(app(\App\Http\Controllers\Tenant\Finance\ExpenseVoucherController::class), $date),
            'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->drawerId, 'expense_date' => $date, 'payment_date' => $date,
            'payee_name' => 'Stationery shop', 'status' => 'draft', 'subtotal' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
        ]);
        $category = (int) DB::connection('tenant')->table('expense_categories')->where('code', 'VP-STAT')->value('id')
            ?: DB::connection('tenant')->table('expense_categories')->insertGetId(['account_id' => $this->accId('6800'), 'code' => 'VP-STAT', 'name' => 'Stationery',
                'is_system' => 0, 'is_active' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $voucher->lines()->create(['expense_category_id' => $category, 'account_id' => $this->accId('6800'), 'description' => 'Stationery', 'amount' => $amount, 'tax_amount' => 0,
            'line_total' => $amount, 'sort_order' => 0]);

        return app(ExpenseService::class)->post($voucher->fresh(), $this->ownerId)->fresh();
    }

    private function cateringReceipt(float $amount, string $date): CateringAdvance
    {
        $estimates = app(CateringEstimateService::class);
        $event = $estimates->createEvent(['branch_id' => $this->branchId, 'customer_name' => 'MR. PURGE', 'customer_phone' => '03001239999',
            'booking_date' => now()->toDateString(), 'event_date' => now()->subDays(4)->toDateString(), 'pax' => 50]);
        $estimates->saveDraftLines($event->currentEstimate, [['product_id' => $this->productId, 'item_name' => 'Catering Package', 'quantity' => 1, 'rate' => 100000]]);
        $estimates->markSent($event->currentEstimate->refresh());
        $estimates->confirmEvent($event->refresh());

        return app(CateringAdvanceService::class)->record($event->refresh(), [
            'amount' => $amount, 'received_date' => $date, 'payment_method_id' => $this->paymentMethodId, 'reference' => 'SLIP-' . Str::random(4),
        ]);
    }

    private function accId(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function url(string $path): string
    {
        return 'http://' . $this->host . $path;
    }

    private function actingAsOwner()
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant');
    }

    private function setFeatures(array $features): void
    {
        DB::connection('master')->table('tenants')->where('id', $this->tenantId)->update(['features' => json_encode($features)]);
        if (app()->bound('tenant')) {
            app('tenant')->features = $features;
        }
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $m->table('tenants')->where('tenant_code', 'vpurge')->delete();
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'vpurge', 'business_name' => 'Void Purge', 'owner_name' => 'Owner', 'owner_email' => 'owner@vpurge.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0, 'features' => json_encode(['void_purge' => true]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant', 'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'), 'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert(['tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedSubscription(): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'vpurge-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'vpurge-plan', 'name' => 'Void Purge', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function bootTenant(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'purged_numbers', 'purged_entries', 'catering_email_logs',
            'catering_production_release_lines', 'catering_production_releases', 'catering_final_invoices', 'catering_advances', 'catering_refunds',
            'catering_material_rates', 'catering_estimate_lines', 'catering_estimates', 'catering_events', 'catering_product_cost_blocks', 'catering_product_profiles',
            'supplier_credit_allocations', 'purchasing_settings', 'journal_lines', 'journal_entries', 'supplier_ledgers', 'supplier_payments',
            'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines', 'purchase_bills', 'expense_voucher_lines', 'expense_vouchers',
            'cash_bank_account_transactions', 'cash_bank_accounts', 'expense_categories', 'payment_methods', 'customers', 'product_translations', 'units', 'products', 'categories',
            'model_has_roles', 'users', 'suppliers', 'accounts', 'branches',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new DefaultChartOfAccountsSeeder)->run();
        $c = DB::connection('tenant');

        $this->branchId = $c->table('branches')->insertGetId(['name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->supplierId = Supplier::create(['code' => 'SUP-VP', 'name' => 'Rice Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0])->id;
        $this->drawerId = CashBankAccount::create(['code' => 'CASH-MAIN', 'name' => 'Main Cash Drawer', 'account_type' => 'cash',
            'account_id' => $this->accId('1110'), 'branch_id' => $this->branchId, 'opening_balance' => self::OPENING, 'current_balance' => self::OPENING, 'is_active' => true])->id;
        $unitId = $c->table('units')->insertGetId(['code' => 'PKG', 'name' => 'Package', 'unit_type' => 'quantity', 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = $this->makeProduct($this->makeCategory(['name' => 'PACKAGES']), ['name' => 'Catering Package', 'sku' => 'VP1', 'unit_id' => $unitId, 'default_purchase_price' => 400]);
        $c->table('catering_material_rates')->insert(['product_id' => $this->productId, 'rate' => 400, 'effective_from' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->paymentMethodId = $this->makePaymentMethod(['cash_bank_account_id' => $this->drawerId]);

        $perms = [
            'tenant.reports.cash-book.index', 'tenant.supplier-payments.index', 'tenant.supplier-payments.show', 'tenant.supplier-payments.void',
            'tenant.finance.expenses.show', 'tenant.finance.expenses.void', 'tenant.finance.manual-journals.store', 'tenant.finance.manual-journals.show',
            'tenant.finance.manual-journals.reverse', 'tenant.catering.advances.void', 'tenant.sales-orders.cancel',
            'tenant.finance.voided-entries.purge', 'tenant.finance.voided-entries.index', 'tenant.finance.journal-entries.index',
        ];
        foreach ($perms as $permName) {
            $c->table('permissions')->updateOrInsert(['name' => $permName, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }
        $clerkRole = $c->table('roles')->where('name', 'Cash Book Viewer')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Cash Book Viewer', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('role_has_permissions')->where('role_id', $clerkRole)->delete();
        $c->table('role_has_permissions')->insert(['permission_id' => $c->table('permissions')->where('name', 'tenant.reports.cash-book.index')->value('id'), 'role_id' => $clerkRole]);

        foreach ([['Purge Owner', 'vp-owner@test.local', 'VPOWN', $ownerRole, self::PASSWORD], ['Book Clerk', 'vp-clerk@test.local', 'VPCLK', $clerkRole, 'x']] as [$name, $email, $code, $role, $pass]) {
            $id = $c->table('users')->insertGetId(['name' => $name, 'email' => $email, 'password' => bcrypt($pass), 'employee_code' => $code,
                'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()]);
            $c->table('model_has_roles')->insert(['role_id' => $role, 'model_type' => User::class, 'model_id' => $id]);
            $code === 'VPOWN' ? $this->ownerId = $id : $this->clerkId = $id;
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
