<?php

namespace App\Services\Finance;

use App\Models\Tenant\CateringAdvance;
use App\Models\Tenant\CateringEvent;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurgedEntry;
use App\Models\Tenant\SupplierPayment;
use App\Support\PurgedNumbers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * VOID-PURGE-1 (22 Oct) — remove, permanently, an entry that was already voided on its own date, together with its
 * reversal, as if neither had happened.
 *
 * Why this is safe: the void already brought every balance back (GL, drawer, supplier, bill). Removing BOTH halves
 * therefore leaves every total where it is. The only stored numbers that move are the running `balance_after` of rows
 * inserted BETWEEN an original and its reversal (they carried the original's effect); rows after the reversal are
 * untouched (the pair nets to zero). `current_balance` is never written, and is asserted unchanged.
 *
 * Refused, with a plain message, whenever any of that cannot be proven: not voided; voided on a different day ("reverse
 * today"); a half missing; GL that does not net to zero per account; another record pointing at the entries; a type
 * outside v1. Everything happens in one transaction; any failure leaves every row where it was.
 *
 * Rows are found by their real keys (source_type/source_id, reference_type/reference_id, reversed_entry_id,
 * void_journal_entry_id) — never by amount or date.
 */
class VoidPurgeService
{
    public const TYPES = [
        'manual_journal'   => 'Manual journal',
        'expense'          => 'Expense voucher',
        'supplier_payment' => 'Supplier payment',
        'catering_receipt' => 'Catering receipt',
    ];

    /** Columns that may point at a journal entry; [table, column]. */
    private const JOURNAL_REFERENCES = [
        ['catering_advances', 'journal_entry_id'], ['catering_advances', 'void_journal_entry_id'],
        ['catering_final_invoices', 'journal_entry_id'], ['catering_final_invoices', 'advance_application_journal_entry_id'],
        ['catering_material_issues', 'cogs_journal_entry_id'], ['catering_refunds', 'journal_entry_id'],
        ['opening_balance_batches', 'journal_entry_id'], ['purchase_returns', 'journal_entry_id'],
        ['supplier_payments', 'void_journal_entry_id'],
        ['department_handovers', 'reclass_journal_entry_id'], ['department_handovers', 'payout_journal_entry_id'],
        ['finished_good_receipts', 'journal_entry_id'], ['manufacturing_consumption_records', 'journal_entry_id'],
        ['manufacturing_rejection_records', 'journal_entry_id'], ['manufacturing_scrap_records', 'journal_entry_id'],
        ['production_orders', 'journal_entry_id'],
    ];

    public static function ready(): bool
    {
        return DB::connection('tenant')->getSchemaBuilder()->hasTable('purged_entries') && PurgedNumbers::ready();
    }

    public function purge(string $type, int $id, string $reason, ?int $userId, ?string $ip = null): PurgedEntry
    {
        if (! isset(self::TYPES[$type])) {
            throw new RuntimeException('Only a voided manual journal, expense voucher, supplier payment or catering receipt can be deleted permanently.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Write why this entry is being deleted (at least 5 characters). It is kept with the hidden copy.');
        }
        if (! self::ready()) {
            throw new RuntimeException('Permanent delete is not ready on this business yet (its tables are missing). Nothing was changed.');
        }

        return DB::connection('tenant')->transaction(function () use ($type, $id, $reason, $userId, $ip) {
            $plan = match ($type) {
                'manual_journal'   => $this->planManualJournal($id),
                'expense'          => $this->planExpense($id),
                'supplier_payment' => $this->planSupplierPayment($id),
                'catering_receipt' => $this->planCateringReceipt($id),
            };
            $this->assertNothingElsePointsAt($plan);

            $before = $this->totals($plan);
            $this->shiftRunningBalances($plan);
            $this->deleteRows($plan);

            $entry = PurgedEntry::create([
                'entity_type'       => $type,
                'entity_id'         => $id,
                'document_no'       => $plan['document_no'],
                'document_date'     => $plan['document_date'],
                'amount'            => $plan['amount'],
                'reason'            => $reason,
                'snapshot'          => ['tables' => $plan['tables'], 'shifted' => $plan['shifted'] ?? []],
                'purged_by_user_id' => $userId,
                'purged_at'         => now(),
                'ip'                => $ip,
            ]);
            foreach ($plan['numbers'] as [$kind, $number]) {
                PurgedNumbers::reserve($kind, (string) $number, (int) $entry->id);
            }

            if ($this->totals($plan) !== $before) {
                throw new RuntimeException('Deleting this entry would change a balance, so nothing was deleted. Please contact support.');
            }

            return $entry;
        });
    }

    /* ── the four kinds ──────────────────────────────────────────────────── */

    private function planManualJournal(int $id): array
    {
        $original = JournalEntry::whereKey($id)->lockForUpdate()->first();
        if (! $original || $original->source_type !== 'manual_journal' || $original->is_reversal) {
            throw new RuntimeException('That is not a manual journal that can be deleted.');
        }
        $plan = $this->newPlan('manual_journal', $id, (string) $original->entry_no, $original->entry_date, (float) $original->total_debit);
        $reversal = $this->journalPair($plan, $original, 'manual_journal_reversal');

        $c = DB::connection('tenant');
        $cash = $c->table('cash_bank_account_transactions')->where('reference_type', 'manual_journal')
            ->where('reference_id', $original->id)->where('transaction_type', 'manual_journal')->lockForUpdate()->get();
        foreach ($cash as $row) {
            $back = $c->table('cash_bank_account_transactions')->where('reference_type', 'manual_journal_reversal')
                ->where('reference_id', $row->id)->lockForUpdate()->first();
            $this->cashPair($plan, $row, $back);
        }
        $this->ledgerPairs($plan,
            $c->table('supplier_ledgers')->where('entry_type', 'journal_adjustment')->where('reference_type', JournalEntry::class)
                ->where('reference_id', $original->id)->orderBy('id')->lockForUpdate()->get(),
            $c->table('supplier_ledgers')->where('entry_type', 'journal_reversal')->where('reference_type', JournalEntry::class)
                ->where('reference_id', $reversal->id)->orderBy('id')->lockForUpdate()->get());

        $plan['numbers'][] = [PurgedNumbers::MANUAL_JOURNAL, (string) $original->source_id];

        return $plan;
    }

    private function planExpense(int $id): array
    {
        $voucher = ExpenseVoucher::whereKey($id)->lockForUpdate()->first();
        if (! $voucher) {
            throw new RuntimeException('That expense voucher was not found.');
        }
        if ($voucher->status !== 'void') {
            throw new RuntimeException('Only a voided expense can be deleted. Void it on its own date first.');
        }
        if (str_starts_with((string) $voucher->voucher_no, 'EXP-SHORT-')) {
            throw new RuntimeException('A cash-shortage voucher is made by closing the shift and cannot be deleted.');
        }
        $plan = $this->newPlan('expense', $id, (string) $voucher->voucher_no, $voucher->expense_date, (float) $voucher->total_amount);

        $c = DB::connection('tenant');
        $out = $c->table('cash_bank_account_transactions')->where('reference_type', 'expense_voucher')->where('reference_id', $id)
            ->where('transaction_type', 'expense_payment')->lockForUpdate()->get();
        $back = $c->table('cash_bank_account_transactions')->where('reference_type', 'expense_voucher')->where('reference_id', $id)
            ->where('transaction_type', 'expense_void_reversal')->lockForUpdate()->get();
        $this->pairByAccount($plan, $out, $back);

        $gl = JournalEntry::where('source_type', 'expense_voucher')->where('source_id', $id)->where('is_reversal', false)->lockForUpdate()->get();
        foreach ($gl as $entry) {
            $this->journalPair($plan, $entry, 'expense_voucher_reversal');
        }
        $this->ensureNoOrphanReversal($plan, 'expense_voucher_reversal', $id);

        $plan['tables']['expense_voucher_lines'] = $this->rows($c->table('expense_voucher_lines')->where('expense_voucher_id', $id)->lockForUpdate()->get());
        $plan['tables']['expense_vouchers'] = $this->rows(collect([$c->table('expense_vouchers')->where('id', $id)->first()]));
        $plan['numbers'][] = [PurgedNumbers::EXPENSE_VOUCHER, (string) $voucher->voucher_no];

        return $plan;
    }

    private function planSupplierPayment(int $id): array
    {
        $payment = SupplierPayment::whereKey($id)->lockForUpdate()->first();
        if (! $payment) {
            throw new RuntimeException('That supplier payment was not found.');
        }
        if ($payment->voided_at === null) {
            throw new RuntimeException('Only a voided supplier payment can be deleted. Void it on its own date first.');
        }
        $plan = $this->newPlan('supplier_payment', $id, (string) $payment->payment_no, $payment->payment_date, (float) $payment->amount);
        $c = DB::connection('tenant');

        if ($c->getSchemaBuilder()->hasTable('supplier_credit_allocations')
            && $c->table('supplier_credit_allocations')->where('source_type', 'payment')->where('source_id', $id)->exists()) {
            throw new RuntimeException('This payment still settles a bill, so it cannot be deleted.');
        }

        $original = JournalEntry::where('source_type', 'supplier_payment')->where('source_id', $id)->where('is_reversal', false)->lockForUpdate()->first();
        if (! $original) {
            throw new RuntimeException('This payment has no accounting entry to delete with it, so it is kept.');
        }
        $reversal = $this->journalPair($plan, $original, 'supplier_payment_reversal');
        if ((int) $payment->void_journal_entry_id !== (int) $reversal->id) {
            throw new RuntimeException('This payment\'s void does not match its accounting reversal, so it is kept.');
        }

        $out = $c->table('cash_bank_account_transactions')->where('reference_type', 'supplier_payment')->where('reference_id', $id)
            ->where('transaction_type', 'supplier_payment')->lockForUpdate()->get();
        $back = $c->table('cash_bank_account_transactions')->where('reference_type', 'supplier_payment')->where('reference_id', $id)
            ->where('transaction_type', 'supplier_payment_void_reversal')->lockForUpdate()->get();
        $this->pairByAccount($plan, $out, $back);

        $this->ledgerPairs($plan,
            $c->table('supplier_ledgers')->where('entry_type', 'payment')->where('reference_type', SupplierPayment::class)
                ->where('reference_id', $id)->orderBy('id')->lockForUpdate()->get(),
            $c->table('supplier_ledgers')->where('entry_type', 'payment_void')->where('reference_type', SupplierPayment::class)
                ->where('reference_id', $id)->orderBy('id')->lockForUpdate()->get());

        $plan['tables']['supplier_payments'] = $this->rows(collect([$c->table('supplier_payments')->where('id', $id)->first()]));
        $plan['self_refs'][] = ['supplier_payments', 'void_journal_entry_id', $id];
        $plan['bill_ids'] = array_values(array_filter([(int) $payment->purchase_bill_id]));
        $plan['supplier_ids'][] = (int) $payment->supplier_id;

        return $plan;
    }

    private function planCateringReceipt(int $id): array
    {
        $advance = CateringAdvance::withoutGlobalScope('notVoided')->whereKey($id)->lockForUpdate()->first();
        if (! $advance) {
            throw new RuntimeException('That catering receipt was not found.');
        }
        if ($advance->voided_at === null) {
            throw new RuntimeException('Only a voided catering receipt can be deleted. Void it on its own date first.');
        }
        if ($advance->posting_type !== CateringAdvance::POSTING_ADVANCE) {
            throw new RuntimeException('Only a receipt taken before the final invoice can be deleted; this one is part of a settlement.');
        }
        $event = CateringEvent::find($advance->catering_event_id);
        if ($event && $event->finalInvoice()->exists()) {
            throw new RuntimeException('This booking has a final invoice, so its receipts are kept.');
        }
        $plan = $this->newPlan('catering_receipt', $id, 'Receipt #' . $id, $advance->received_date, (float) $advance->amount);
        $c = DB::connection('tenant');

        if ($advance->journal_entry_id || $advance->void_journal_entry_id) {
            $original = JournalEntry::whereKey($advance->journal_entry_id)->lockForUpdate()->first();
            if (! $original || $original->source_type !== 'catering_advance' || (int) $original->source_id !== $id) {
                throw new RuntimeException('This receipt\'s accounting entry does not match it, so it is kept.');
            }
            $reversal = $this->journalPair($plan, $original, 'catering_advance_reversal');
            if ((int) $advance->void_journal_entry_id !== (int) $reversal->id) {
                throw new RuntimeException('This receipt\'s void does not match its accounting reversal, so it is kept.');
            }
        }

        $in = $c->table('cash_bank_account_transactions')->where('reference_type', 'catering_advance')->where('reference_id', $id)
            ->where('transaction_type', 'customer_payment')->lockForUpdate()->get();
        $back = $c->table('cash_bank_account_transactions')->where('reference_type', 'catering_advance')->where('reference_id', $id)
            ->where('transaction_type', 'catering_advance_void_reversal')->lockForUpdate()->get();
        $this->pairByAccount($plan, $in, $back);

        $plan['tables']['catering_advances'] = $this->rows(collect([$c->table('catering_advances')->where('id', $id)->first()]));
        $plan['self_refs'][] = ['catering_advances', 'journal_entry_id', $id];
        $plan['self_refs'][] = ['catering_advances', 'void_journal_entry_id', $id];
        if ($c->getSchemaBuilder()->hasTable('catering_email_logs')) {
            $plan['tables']['catering_email_logs'] = $this->rows($c->table('catering_email_logs')->where('dedupe_key', 'advance-' . $id)->get());
        }

        return $plan;
    }

    /* ── pairing and checks ──────────────────────────────────────────────── */

    private function newPlan(string $type, int $id, string $no, $date, float $amount): array
    {
        return [
            'type' => $type, 'id' => $id, 'document_no' => $no,
            'document_date' => $date ? Carbon::parse($date)->toDateString() : null, 'amount' => round($amount, 2),
            'tables' => [], 'journal_ids' => [], 'cash_pairs' => [], 'ledger_pairs' => [], 'numbers' => [],
            'self_refs' => [], 'bill_ids' => [], 'supplier_ids' => [], 'cash_account_ids' => [],
        ];
    }

    /** The original's reversal: posted, of the expected kind, the same day, and netting to zero per account. */
    private function journalPair(array &$plan, JournalEntry $original, string $reversalType): JournalEntry
    {
        $reversal = JournalEntry::where('reversed_entry_id', $original->id)->lockForUpdate()->get();
        if ($reversal->count() !== 1 || $reversal->first()->source_type !== $reversalType || $reversal->first()->status !== 'posted') {
            throw new RuntimeException('This entry has not been voided (no single reversal found). Void it on its own date first.');
        }
        $reversal = $reversal->first();
        if ($original->entry_date->toDateString() !== $reversal->entry_date->toDateString()) {
            throw new RuntimeException('This entry was reversed on a different day (' . $reversal->entry_date->format('d M Y')
                . ', the entry is ' . $original->entry_date->format('d M Y') . '). Only an entry voided on its own date can be deleted.');
        }

        $c = DB::connection('tenant');
        $net = $c->table('journal_lines')->whereIn('journal_entry_id', [$original->id, $reversal->id])
            ->groupBy('account_id')->selectRaw('account_id, ROUND(SUM(debit) - SUM(credit), 4) n')->pluck('n', 'account_id');
        foreach ($net as $n) {
            if (abs((float) $n) > 0.0001) {
                throw new RuntimeException('This entry and its reversal do not cancel out in the accounts, so they are kept. Please contact support.');
            }
        }

        foreach ([$original, $reversal] as $entry) {
            $plan['journal_ids'][] = (int) $entry->id;
            $plan['numbers'][] = [PurgedNumbers::JOURNAL_ENTRY, (string) $entry->entry_no];
        }
        $plan['tables']['journal_lines'] = array_merge($plan['tables']['journal_lines'] ?? [],
            $this->rows($c->table('journal_lines')->whereIn('journal_entry_id', [$original->id, $reversal->id])->orderBy('id')->get()));
        $plan['tables']['journal_entries'] = array_merge($plan['tables']['journal_entries'] ?? [],
            $this->rows($c->table('journal_entries')->whereIn('id', [$original->id, $reversal->id])->orderBy('id')->get()));

        return $reversal;
    }

    /** An expense with no GL original must not have a stray GL reversal either. */
    private function ensureNoOrphanReversal(array $plan, string $reversalType, int $sourceId): void
    {
        $stray = JournalEntry::where('source_type', $reversalType)->where('source_id', $sourceId)
            ->whereNotIn('id', $plan['journal_ids'] ?: [0])->exists();
        if ($stray) {
            throw new RuntimeException('This entry has an accounting reversal without its original, so it is kept. Please contact support.');
        }
    }

    private function cashPair(array &$plan, object $original, ?object $reversal): void
    {
        if (! $reversal) {
            throw new RuntimeException('The cash movement of this entry was never reversed, so it is kept. Void it first.');
        }
        if ((int) $reversal->cash_bank_account_id !== (int) $original->cash_bank_account_id
            || abs((float) $reversal->amount - (float) $original->amount) > 0.0001 || $reversal->direction === $original->direction) {
            throw new RuntimeException('The cash reversal does not match the original movement, so the entry is kept.');
        }
        if (Carbon::parse($original->transaction_date)->toDateString() !== Carbon::parse($reversal->transaction_date)->toDateString()) {
            throw new RuntimeException('The cash came back on a different day (' . Carbon::parse($reversal->transaction_date)->format('d M Y')
                . '). Only an entry voided on its own date can be deleted.');
        }
        if ((int) $reversal->id < (int) $original->id) {
            throw new RuntimeException('The cash rows of this entry are out of order, so it is kept. Please contact support.');
        }
        $plan['cash_pairs'][] = [$original, $reversal];
        $plan['cash_account_ids'][] = (int) $original->cash_bank_account_id;
        $plan['tables']['cash_bank_account_transactions'] = array_merge($plan['tables']['cash_bank_account_transactions'] ?? [],
            $this->rows(collect([$original, $reversal])));
    }

    private function pairByAccount(array &$plan, $originals, $reversals): void
    {
        if ($originals->count() !== $reversals->count()) {
            throw new RuntimeException('The cash movement of this entry was not fully reversed, so it is kept.');
        }
        foreach ($originals as $row) {
            $match = $reversals->first(fn ($r) => (int) $r->cash_bank_account_id === (int) $row->cash_bank_account_id);
            $this->cashPair($plan, $row, $match);
            $reversals = $reversals->reject(fn ($r) => $match && (int) $r->id === (int) $match->id)->values();
        }
    }

    private function ledgerPairs(array &$plan, $originals, $reversals): void
    {
        if ($originals->count() !== $reversals->count()) {
            throw new RuntimeException('The supplier ledger of this entry was not fully reversed, so it is kept.');
        }
        $reversals = $reversals->values();
        foreach ($originals->values() as $i => $row) {
            $back = $reversals->first(fn ($r) => (int) $r->supplier_id === (int) $row->supplier_id
                && abs((float) $r->amount - (float) $row->amount) < 0.0001 && $r->direction !== $row->direction && (int) $r->id > (int) $row->id);
            if (! $back) {
                throw new RuntimeException('The supplier ledger reversal does not match the original, so the entry is kept.');
            }
            $reversals = $reversals->reject(fn ($r) => (int) $r->id === (int) $back->id)->values();
            $plan['ledger_pairs'][] = [$row, $back];
            $plan['supplier_ids'][] = (int) $row->supplier_id;
            $plan['tables']['supplier_ledgers'] = array_merge($plan['tables']['supplier_ledgers'] ?? [], $this->rows(collect([$row, $back])));
        }
    }

    private function assertNothingElsePointsAt(array $plan): void
    {
        if (! $plan['journal_ids']) {
            return;
        }
        $c = DB::connection('tenant');
        $schema = $c->getSchemaBuilder();
        foreach (self::JOURNAL_REFERENCES as [$table, $column]) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
                continue;
            }
            $q = $c->table($table)->whereIn($column, $plan['journal_ids']);
            foreach ($plan['self_refs'] as [$selfTable, $selfColumn, $selfId]) {
                if ($selfTable === $table && $selfColumn === $column) {
                    $q->where('id', '!=', $selfId);
                }
            }
            if ($q->exists()) {
                throw new RuntimeException('Another record (' . str_replace('_', ' ', $table) . ') points at this entry, so it is kept.');
            }
        }
        $stray = $c->table('journal_entries')->whereIn('reversed_entry_id', $plan['journal_ids'])->whereNotIn('id', $plan['journal_ids'])->exists();
        if ($stray) {
            throw new RuntimeException('Another journal points at this entry, so it is kept.');
        }
    }

    /* ── the write ───────────────────────────────────────────────────────── */

    /**
     * Rows inserted between an original and its reversal carry the original's effect in their stored balance_after;
     * take exactly that effect out. Rows after the reversal are unchanged (the pair nets to zero).
     */
    private function shiftRunningBalances(array &$plan): void
    {
        $c = DB::connection('tenant');
        foreach ($plan['cash_pairs'] as [$o, $r]) {
            $effect = $o->direction === 'in' ? (float) $o->amount : -(float) $o->amount;
            $n = $c->table('cash_bank_account_transactions')->where('cash_bank_account_id', $o->cash_bank_account_id)
                ->where('id', '>', $o->id)->where('id', '<', $r->id)->update(['balance_after' => DB::raw('balance_after - (' . $effect . ')')]);
            $plan['shifted'][] = ['table' => 'cash_bank_account_transactions', 'key' => (int) $o->cash_bank_account_id, 'between' => [(int) $o->id, (int) $r->id], 'by' => -$effect, 'rows' => $n];
        }
        foreach ($plan['ledger_pairs'] as [$o, $r]) {
            $effect = $o->direction === 'debit' ? (float) $o->amount : -(float) $o->amount;
            $n = $c->table('supplier_ledgers')->where('supplier_id', $o->supplier_id)
                ->where('id', '>', $o->id)->where('id', '<', $r->id)->update(['balance_after' => DB::raw('balance_after - (' . $effect . ')')]);
            $plan['shifted'][] = ['table' => 'supplier_ledgers', 'key' => (int) $o->supplier_id, 'between' => [(int) $o->id, (int) $r->id], 'by' => -$effect, 'rows' => $n];
        }
    }

    private function deleteRows(array $plan): void
    {
        $c = DB::connection('tenant');
        $ids = fn (string $table) => array_map(fn ($row) => $row['id'], $plan['tables'][$table] ?? []);

        foreach (['catering_email_logs', 'cash_bank_account_transactions', 'supplier_ledgers', 'expense_voucher_lines', 'journal_lines'] as $table) {
            if ($ids($table)) {
                $c->table($table)->whereIn('id', $ids($table))->delete();
            }
        }
        // Documents first (they point at the journals), then the reversal journal, then the original.
        foreach (['supplier_payments', 'catering_advances', 'expense_vouchers'] as $table) {
            if ($ids($table)) {
                $c->table($table)->whereIn('id', $ids($table))->delete();
            }
        }
        if ($plan['journal_ids']) {
            $c->table('journal_entries')->whereIn('id', $plan['journal_ids'])->whereNotNull('reversed_entry_id')->delete();
            $c->table('journal_entries')->whereIn('id', $plan['journal_ids'])->delete();
        }
    }

    /**
     * What must not move: every drawer's and supplier's stored balance, the touched bills, and the trial balance per
     * account. (The pair nets to zero, so all of these are identical before and after.)
     */
    private function totals(array $plan): array
    {
        $c = DB::connection('tenant');
        $out = [];
        foreach (array_unique($plan['cash_account_ids']) as $id) {
            $out['cash'][$id] = round((float) $c->table('cash_bank_accounts')->where('id', $id)->value('current_balance'), 4);
        }
        foreach (array_unique($plan['supplier_ids']) as $id) {
            $out['supplier'][$id] = round((float) $c->table('suppliers')->where('id', $id)->value('current_balance'), 4);
        }
        foreach (array_unique($plan['bill_ids']) as $id) {
            $bill = $c->table('purchase_bills')->where('id', $id)->first(['amount_paid', 'balance_due', 'status']);
            $out['bill'][$id] = $bill ? [round((float) $bill->amount_paid, 4), round((float) $bill->balance_due, 4), $bill->status] : null;
        }
        $out['gl'] = $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->groupBy('l.account_id')->orderBy('l.account_id')
            ->selectRaw('l.account_id, ROUND(SUM(l.debit) - SUM(l.credit), 4) n')->pluck('n', 'account_id')
            // An account used only by this pair sums to 0 before and has no lines after: the same balance.
            ->filter(fn ($n) => abs((float) $n) > 0.00001)->map(fn ($n) => (string) $n)->all();

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function rows($rows): array
    {
        return collect($rows)->filter()->map(fn ($r) => (array) $r)->values()->all();
    }
}
