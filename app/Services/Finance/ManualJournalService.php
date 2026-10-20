<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Support\EdgeRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The OFFICIAL Manual Journal authority (Cloud).
 *
 * Extracted from ManualJournalController (SUPPLIER-FINANCE-DIRECT-1) so the Online form and the Edge
 * supplier-finance ingestion (OFFLINE EDGE F2) post a manual journal through ONE code path: the GL entry
 * (JournalService::post), the optional cash/bank running-balance movements, and the supplier subledger
 * mirror of every Accounts Payable line — all in one tenant transaction. Behaviour is the controller's,
 * unchanged; the controller now delegates here.
 *
 * D1 (owner decision, 20 Oct 2026) — this service is also the ONE enforcement point of the manual-journal business
 * rules: post() runs the canonical guards (ManualJournalRules — R1–R3 cash lines, R5 narration, the AP-supplier rule)
 * before anything is written, and reverse() owns the reverse rules (row lock, posted-manual-journal-only, the
 * already-reversed refusal that never posts twice). The Online controller is a thin caller; the Edge ingestion posts
 * through here; the Edge till runs the same ManualJournalRules against its replica before building the envelope.
 *
 * GL is Cloud accounting authority: JournalService refuses to post on a Branch Server, so this service can
 * never post there either (and fails closed itself before touching the database).
 */
class ManualJournalService
{
    public const SOURCE_TYPE = 'manual_journal';

    public function __construct(
        private JournalService $journal,
        private SupplierPayableService $supplierPayable,
    ) {}

    /**
     * SUPPLIER-FINANCE-DIRECT-1 — an Accounts Payable line (2100 or any descendant) must name the supplier it
     * belongs to; otherwise the supplier ledger and the AP control account drift apart. Validated form rows in,
     * ValidationException keyed like the form out.
     */
    public function assertApLinesNameTheirSupplier(array $lines): void
    {
        foreach ($lines as $i => $line) {
            $d = (float) ($line['debit'] ?? 0);
            $c = (float) ($line['credit'] ?? 0);
            if ($d > 0 && $c > 0) {
                throw ValidationException::withMessages([
                    "lines.$i.debit" => 'A line cannot have both a debit and a credit.',
                ]);
            }
        }

        $apIds = $this->supplierPayable->apAccountIds();
        if (! $apIds) {
            return;
        }
        foreach ($lines as $i => $line) {
            $debit  = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }
            if (! in_array((int) ($line['account_id'] ?? 0), $apIds, true)) {
                continue;
            }
            if (($line['counterparty_type'] ?? null) !== 'supplier' || empty($line['supplier_id'])) {
                throw ValidationException::withMessages([
                    "lines.$i.supplier_id" => 'This line posts to Accounts Payable, so it must name the supplier '
                        . 'it belongs to — otherwise the supplier ledger and the AP control account drift apart.',
                ]);
            }
        }
    }

    /**
     * D1 — the ONE manual-journal rule set, applied to a shape-validated journal BEFORE anything is written:
     *
     *   • debit XOR credit per line, and every Accounts Payable line names its supplier (SUPPLIER-FINANCE-DIRECT-1);
     *   • R5 — the narration is real (ManualJournalRules::guardNarration);
     *   • R1–R3 — Cash/Bank only on its own line, a drawer required / auto-filled on a drawer's GL account, no group
     *     head (ManualJournalRules::guardCashLines against the tenant chart).
     *
     * post() always runs it; callers that need the lines AS THEY WILL BE POSTED (the Edge ingestion hands them to its
     * finance verifier) call it first. Keys are the form's: `description`, `lines.<i>.account_id`,
     * `lines.<i>.cash_bank_account_id`, `lines.<i>.supplier_id`, `lines.<i>.debit`.
     *
     * @return array $data with the R2 auto-filled drawers written into lines.*.cash_bank_account_id
     *
     * @throws ValidationException
     */
    public function guard(array $data): array
    {
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        $this->assertApLinesNameTheirSupplier($lines);
        ManualJournalRules::guardNarration((string) ($data['description'] ?? ''));
        $data['lines'] = ManualJournalRules::guardCashLines($lines, $this->chart());

        return $data;
    }

    /** The tenant chart as ManualJournalRules reads it — every account (display order) and every drawer. */
    public function chart(): ManualJournalChart
    {
        return new ManualJournalChart(
            Account::query()->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'parent_id', 'is_active'])->toArray(),
            CashBankAccount::query()->get(['id', 'name', 'account_id', 'is_active'])->toArray(),
        );
    }

    /**
     * Post a manual journal: GL + cash/bank movements + supplier subledger mirror, one transaction — after guard().
     *
     * @param array $data ['entry_date', 'description', 'reference_no'?, 'lines' => [[account_id, branch_id?, cash_bank_account_id?,
     *                    counterparty_type?, supplier_id?, description?, debit?, credit?], ...]]
     *
     * @throws ValidationException a rule refused the journal — nothing was written
     */
    public function post(array $data, ?int $userId = null): JournalEntry
    {
        $this->assertCloud('posting');

        return DB::connection('tenant')->transaction(function () use ($data, $userId) {
            // The single enforcement point (Online form, Edge ingestion): a refusal rolls back before any write.
            $data = $this->guard($data);

            $lines = $this->normalizeLines($data['lines']);
            $sourceId = $this->nextManualJournalId();

            $entry = $this->journal->post(
                sourceType:  self::SOURCE_TYPE,
                sourceId:    $sourceId,
                sourceNo:    ($data['reference_no'] ?? null) ?: null,
                description: $data['description'],
                entryDate:   $data['entry_date'],
                lines:       $lines,
                userId:      $userId,
            );

            // JournalService::post is idempotent on (source_type, source_id): had a concurrent post claimed the same
            // sequence, we would silently receive SOMEONE ELSE's entry. The sequence read is locked above; this
            // guard makes the contract explicit — the entry handed back is the one described here.
            $expectedDebit = round(array_sum(array_column($lines, 'debit')), 4);
            if ((string) $entry->description !== (string) $data['description'] || abs(round((float) $entry->total_debit, 4) - $expectedDebit) > 0.00005) {
                throw new RuntimeException('Manual journal sequence collision: another journal was posted with the same sequence. Nothing was saved — try again.');
            }

            $this->syncCashBankLines($entry, $data['lines'], $data['entry_date'], $userId);
            $this->supplierPayable->mirrorApLinesToSupplierLedger($entry, $userId);

            return $entry;
        });
    }

    /**
     * Reverse a posted manual journal: GL reversal + cash/bank counter-movements + subledger mirror of the reversal.
     *
     * D1 — the reverse rules live HERE (canonical MANUAL-JOURNAL-CASH-LINE-GUARD-1 ed581520 + B3):
     *   • the original row is locked for the whole transaction, so two tabs pressing Reverse at once serialise — the
     *     second meets the reversal the first made;
     *   • only a POSTED `manual_journal` that is not itself a reversal may be reversed (NOT_MANUAL_JOURNAL / NOT_POSTED /
     *     IS_REVERSAL);
     *   • an entry that already has a reversal is refused with ALREADY_REVERSED carrying that reversal — "Reverse pressed
     *     twice posts nothing": no GL row, no cash counter-movement, no subledger mirror. A replayed reversal is idempotent.
     * Reverse is deliberately free of R1–R3/R5: reversing an old wrong entry is the remedy for it.
     *
     * @throws ManualJournalReversalRefused
     */
    public function reverse(JournalEntry $entry, string $reason, ?int $userId = null): JournalEntry
    {
        $this->assertCloud('reversal');

        return DB::connection('tenant')->transaction(function () use ($entry, $reason, $userId) {
            /** @var JournalEntry|null $locked */
            $locked = JournalEntry::whereKey($entry->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->source_type !== self::SOURCE_TYPE) {
                throw new ManualJournalReversalRefused(ManualJournalReversalRefused::NOT_MANUAL_JOURNAL, 'Only a manual journal can be reversed here.');
            }
            if ($locked->status !== 'posted') {
                throw new ManualJournalReversalRefused(ManualJournalReversalRefused::NOT_POSTED, $locked->entry_no . ' is not posted, so there is nothing to reverse.');
            }
            if ($locked->is_reversal) {
                throw new ManualJournalReversalRefused(ManualJournalReversalRefused::IS_REVERSAL, $locked->entry_no . ' is itself a reversal and cannot be reversed again.');
            }

            $existing = JournalEntry::where('reversed_entry_id', $locked->id)->first();
            if ($existing) {
                throw new ManualJournalReversalRefused(
                    ManualJournalReversalRefused::ALREADY_REVERSED,
                    $locked->entry_no . ' was already reversed by ' . $existing->entry_no . ' — nothing was posted again.',
                    $existing,
                );
            }

            $reversal = $this->journal->reverse($locked, $reason, $userId);
            $this->reverseCashBankLines($locked, $userId);
            $this->supplierPayable->mirrorApLinesToSupplierLedger($reversal, $userId);

            return $reversal;
        });
    }

    /** The reversal of a manual journal, if one exists (read-only; what the already-reversed refusal carries). */
    public function reversalOf(JournalEntry $entry): ?JournalEntry
    {
        return JournalEntry::where('reversed_entry_id', $entry->getKey())->first();
    }

    /** GL mutation is Cloud authority — fail closed on a Branch Server before touching the database. */
    private function assertCloud(string $what): void
    {
        if (EdgeRuntime::isBranchServer()) {
            throw new RuntimeException("Manual journal {$what} must not run on a Branch Server — the Cloud posts the official journal.");
        }
    }

    public function normalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            $debit  = round((float) ($line['debit']  ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }
            $normalized[] = [
                'account_id'        => (int) $line['account_id'],
                'branch_id'         => ! empty($line['branch_id']) ? (int) $line['branch_id'] : null,
                'counterparty_type' => $line['counterparty_type'] ?? null,
                'supplier_id'       => ! empty($line['supplier_id']) ? (int) $line['supplier_id'] : null,
                'description'       => $line['description'] ?? null,
                'debit'             => $debit,
                'credit'            => $credit,
            ];
        }

        return $normalized;
    }

    /**
     * Cash/bank lines optionally sync to operational cash_bank_account_transactions so the running balance
     * stays in step (same as opening balances do). A debit on a cash/bank line = money IN; a credit = money OUT.
     */
    private function syncCashBankLines(JournalEntry $entry, array $lines, string $entryDate, ?int $userId): void
    {
        foreach ($lines as $line) {
            $cbId = (int) ($line['cash_bank_account_id'] ?? 0);
            if (! $cbId) {
                continue;
            }
            $debit  = round((float) ($line['debit']  ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }
            $amount    = $debit > 0 ? $debit : $credit;
            $direction = $debit > 0 ? 'in' : 'out';

            $cash = CashBankAccount::whereKey($cbId)->lockForUpdate()->first();
            if (! $cash) {
                continue;
            }
            $newBalance = $direction === 'in'
                ? (float) $cash->current_balance + $amount
                : (float) $cash->current_balance - $amount;

            CashBankAccountTransaction::create([
                'cash_bank_account_id' => $cash->id,
                'transaction_date'     => $entryDate,
                'direction'            => $direction,
                'amount'               => $amount,
                'balance_after'        => $newBalance,
                'transaction_type'     => 'manual_journal',
                'reference_type'       => 'manual_journal',
                'reference_id'         => $entry->id,
                'notes'                => 'Manual journal ' . $entry->entry_no,
                'created_by_user_id'   => $userId,
            ]);
            $cash->update(['current_balance' => $newBalance]);
        }
    }

    private function reverseCashBankLines(JournalEntry $entry, ?int $userId): void
    {
        $txns = CashBankAccountTransaction::query()
            ->where('reference_type', 'manual_journal')
            ->where('reference_id', $entry->id)
            ->where('transaction_type', 'manual_journal')
            ->get();

        foreach ($txns as $txn) {
            $already = CashBankAccountTransaction::query()
                ->where('reference_type', 'manual_journal_reversal')
                ->where('reference_id', $txn->id)
                ->exists();
            if ($already) {
                continue;
            }
            $cash = CashBankAccount::whereKey($txn->cash_bank_account_id)->lockForUpdate()->first();
            if (! $cash) {
                continue;
            }
            $reverseDir = $txn->direction === 'in' ? 'out' : 'in';
            $newBalance = $reverseDir === 'in'
                ? (float) $cash->current_balance + (float) $txn->amount
                : (float) $cash->current_balance - (float) $txn->amount;

            CashBankAccountTransaction::create([
                'cash_bank_account_id' => $cash->id,
                'transaction_date'     => now()->toDateString(),
                'direction'            => $reverseDir,
                'amount'               => $txn->amount,
                'balance_after'        => $newBalance,
                'transaction_type'     => 'manual_journal_reversal',
                'reference_type'       => 'manual_journal_reversal',
                'reference_id'         => $txn->id,
                'notes'                => 'Reversal of manual journal ' . $entry->entry_no,
                'created_by_user_id'   => $userId,
            ]);
            $cash->update(['current_balance' => $newBalance]);
        }
    }

    /**
     * Manual journals don't use the (source_type, source_id) idempotency key the way automated ones do — each
     * manual post is a NEW entry with the next sequence. The read is locked inside the posting transaction so two
     * concurrent posts cannot both take the same sequence (and, through JournalService's idempotency, silently
     * share one entry).
     */
    private function nextManualJournalId(): int
    {
        return (int) DB::connection('tenant')
            ->table('journal_entries')
            ->where('source_type', self::SOURCE_TYPE)
            ->lockForUpdate()
            ->max('source_id') + 1;
    }
}
