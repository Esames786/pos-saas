<?php

namespace App\Services\Finance;

use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\OpeningBalanceBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening balances / owner capital workflow (FIN-13).
 *
 * A batch is a manual, balanced set of GL opening lines (Dr cash/inventory/AR…,
 * Cr owner capital, etc.). Posting it writes a single balanced double-entry
 * journal (source_type = opening_balance) so the Trial Balance and Balance Sheet
 * start from real numbers, and operationally bumps cash/bank current_balance for
 * any cash/bank line. Everything is idempotent per batch. Void reverses both the
 * GL journal and the cash/bank movements without deleting originals.
 */
class OpeningBalanceService
{
    public function __construct(
        private JournalService $journal,
        private JournalPostingService $journalPosting,
    ) {}

    /** Recalculate batch total_debit / total_credit from its lines. */
    public function recalcTotals(OpeningBalanceBatch $batch): OpeningBalanceBatch
    {
        $batch->loadMissing('lines');

        $batch->forceFill([
            'total_debit'  => round((float) $batch->lines->sum('debit'), 4),
            'total_credit' => round((float) $batch->lines->sum('credit'), 4),
        ])->save();

        return $batch;
    }

    /** Create a draft batch with its lines. */
    public function createDraft(array $data): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($data) {
            $batch = OpeningBalanceBatch::create([
                'batch_no'           => $data['batch_no'] ?: $this->nextBatchNo($data['opening_date']),
                'opening_date'       => $data['opening_date'],
                'branch_id'          => $data['branch_id'] ?? null,
                'description'        => $data['description'] ?? null,
                'status'             => 'draft',
                'created_by_user_id' => Auth::guard('tenant')->id(),
            ]);

            $this->syncLines($batch, $data['lines']);
            $this->recalcTotals($batch);

            return $batch->fresh('lines');
        });
    }

    /** Replace a draft batch's header + lines. */
    public function updateDraft(OpeningBalanceBatch $batch, array $data): OpeningBalanceBatch
    {
        if (! $batch->isDraft()) {
            throw new RuntimeException('Only draft batches can be edited.');
        }

        return DB::transaction(function () use ($batch, $data) {
            $batch->update([
                'batch_no'     => $data['batch_no'] ?: $batch->batch_no,
                'opening_date' => $data['opening_date'],
                'branch_id'    => $data['branch_id'] ?? null,
                'description'  => $data['description'] ?? null,
            ]);

            $this->syncLines($batch, $data['lines']);
            $this->recalcTotals($batch);

            return $batch->fresh('lines');
        });
    }

    /**
     * Post a draft batch: write the balanced GL opening journal and sync cash/bank
     * current_balance for cash/bank lines. Idempotent per batch.
     */
    public function post(OpeningBalanceBatch $batch, ?int $userId = null): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($batch, $userId) {
            $batch = OpeningBalanceBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! $batch->isDraft()) {
                throw new RuntimeException('Only draft batches can be posted.');
            }

            $this->recalcTotals($batch);
            $batch->refresh()->loadMissing('lines');

            $totalDebit  = round((float) $batch->total_debit, 4);
            $totalCredit = round((float) $batch->total_credit, 4);

            if ($totalDebit <= 0) {
                throw new RuntimeException('Opening balance total must be greater than zero.');
            }
            if ($totalDebit !== $totalCredit) {
                throw new RuntimeException("Opening balance is not balanced: debit {$totalDebit} != credit {$totalCredit}.");
            }

            // ── Balanced GL journal (source_type = opening_balance, idempotent per batch) ──
            $lines = $batch->lines->map(fn ($l) => [
                'account_id'  => $l->account_id,
                'branch_id'   => $batch->branch_id,
                'description' => trim($batch->batch_no . ' ' . ($l->description ?? 'Opening balance')),
                'debit'       => (float) $l->debit,
                'credit'      => (float) $l->credit,
            ])->all();

            $entry = $this->journal->post(
                'opening_balance',
                $batch->id,
                $batch->batch_no,
                'Opening balances ' . $batch->batch_no,
                $batch->opening_date->toDateString(),
                $lines,
                $userId
            );

            // ── Operational cash/bank sync (idempotent per batch) ──
            $this->syncCashBankOnPost($batch, $userId);

            $batch->update([
                'status'            => 'posted',
                'journal_entry_id'  => $entry->id,
                'posted_by_user_id' => $userId,
                'posted_at'         => now(),
            ]);

            return $batch->fresh(['lines', 'journalEntry']);
        });
    }

    /**
     * Void a posted batch: reverse the GL journal and any cash/bank opening movement.
     * Originals are kept; reversals net them to zero. Idempotent.
     */
    public function void(OpeningBalanceBatch $batch, ?int $userId = null, ?string $reason = null): OpeningBalanceBatch
    {
        $batch = DB::transaction(function () use ($batch, $userId, $reason) {
            $batch = OpeningBalanceBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! $batch->isPosted()) {
                throw new RuntimeException('Only posted batches can be voided.');
            }

            $this->syncCashBankOnVoid($batch, $userId);

            $batch->update([
                'status'            => 'void',
                'voided_by_user_id' => $userId,
                'voided_at'         => now(),
                'void_reason'       => $reason,
            ]);

            return $batch->fresh();
        });

        // Reverse the GL journal (idempotent + safe; never throws into this flow).
        $this->journalPosting->reverseForSource(
            'opening_balance',
            $batch->id,
            $reason ?? ('Opening balance batch ' . $batch->batch_no . ' voided'),
            $userId
        );

        return $batch;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function syncLines(OpeningBalanceBatch $batch, array $lines): void
    {
        $batch->lines()->delete();

        $sort = 0;
        foreach ($lines as $line) {
            $debit  = round((float) ($line['debit'] ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);

            // Skip fully-empty rows; a row with both sides set keeps the larger intent
            // out — the controller validates exclusivity before this point.
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $batch->lines()->create([
                'account_id'          => $line['account_id'],
                'cash_bank_account_id' => $line['cash_bank_account_id'] ?? null,
                'party_type'          => $line['party_type'] ?? null,
                'party_id'            => $line['party_id'] ?? null,
                'description'         => $line['description'] ?? null,
                'debit'               => $debit,
                'credit'              => $credit,
                'sort_order'          => $sort++,
            ]);
        }
    }

    /**
     * One cash/bank transaction per cash/bank line, bumping current_balance.
     * Debit line increases the balance (money in), credit decreases it (money out).
     * Idempotent: skips entirely if this batch already has opening_balance txns.
     */
    private function syncCashBankOnPost(OpeningBalanceBatch $batch, ?int $userId): void
    {
        $already = CashBankAccountTransaction::query()
            ->where('reference_type', 'opening_balance')
            ->where('reference_id', $batch->id)
            ->where('transaction_type', 'opening_balance')
            ->exists();

        if ($already) {
            return;
        }

        foreach ($batch->lines as $line) {
            if (! $line->cash_bank_account_id) {
                continue;
            }

            $debit  = round((float) $line->debit, 4);
            $credit = round((float) $line->credit, 4);
            $amount = $debit > 0 ? $debit : $credit;
            if ($amount <= 0) {
                continue;
            }

            $direction = $debit > 0 ? 'in' : 'out';

            $cash = CashBankAccount::whereKey($line->cash_bank_account_id)->lockForUpdate()->first();
            if (! $cash) {
                continue;
            }

            $newBalance = (float) $cash->current_balance + ($direction === 'in' ? $amount : -$amount);

            CashBankAccountTransaction::create([
                'cash_bank_account_id' => $cash->id,
                'transaction_date'     => $batch->opening_date->toDateString(),
                'direction'            => $direction,
                'amount'               => $amount,
                'balance_after'        => $newBalance,
                'transaction_type'     => 'opening_balance',
                'reference_type'       => 'opening_balance',
                'reference_id'         => $batch->id,
                'notes'                => 'Opening balance ' . $batch->batch_no,
                'created_by_user_id'   => $userId,
            ]);

            $cash->update(['current_balance' => $newBalance]);
        }
    }

    /** Reverse cash/bank opening movements on void. Keeps originals; idempotent. */
    private function syncCashBankOnVoid(OpeningBalanceBatch $batch, ?int $userId): void
    {
        $alreadyReversed = CashBankAccountTransaction::query()
            ->where('reference_type', 'opening_balance')
            ->where('reference_id', $batch->id)
            ->where('transaction_type', 'opening_balance_void_reversal')
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $batch->loadMissing('lines');

        foreach ($batch->lines as $line) {
            if (! $line->cash_bank_account_id) {
                continue;
            }

            $debit  = round((float) $line->debit, 4);
            $credit = round((float) $line->credit, 4);
            $amount = $debit > 0 ? $debit : $credit;
            if ($amount <= 0) {
                continue;
            }

            // Reverse the original direction.
            $direction = $debit > 0 ? 'out' : 'in';

            $cash = CashBankAccount::whereKey($line->cash_bank_account_id)->lockForUpdate()->first();
            if (! $cash) {
                continue;
            }

            $newBalance = (float) $cash->current_balance + ($direction === 'in' ? $amount : -$amount);

            CashBankAccountTransaction::create([
                'cash_bank_account_id' => $cash->id,
                'transaction_date'     => now()->toDateString(),
                'direction'            => $direction,
                'amount'               => $amount,
                'balance_after'        => $newBalance,
                'transaction_type'     => 'opening_balance_void_reversal',
                'reference_type'       => 'opening_balance',
                'reference_id'         => $batch->id,
                'notes'                => 'Void reversal for opening balance ' . $batch->batch_no,
                'created_by_user_id'   => $userId,
            ]);

            $cash->update(['current_balance' => $newBalance]);
        }
    }

    private function nextBatchNo(string $openingDate): string
    {
        $prefix = 'OB-' . Carbon::parse($openingDate)->format('Ymd') . '-';

        $last = OpeningBalanceBatch::where('batch_no', 'like', $prefix . '%')
            ->orderByDesc('batch_no')
            ->value('batch_no');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
