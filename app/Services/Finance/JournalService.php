<?php

namespace App\Services\Finance;

use App\Models\Tenant\Account;
use App\Models\Tenant\JournalEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Double-entry General Ledger posting (FIN-7).
 *
 * Creates balanced journal entries (total debit == total credit) and reversals.
 * Idempotent per (source_type, source_id) for non-reversal entries. Operational
 * subledgers (sales/stock/supplier/customer/cash) remain the source detail — the
 * GL is the summarized double-entry view that posts FROM those events.
 */
class JournalService
{
    /** @var array<string,int> code → account_id cache for the current request */
    private array $accountCache = [];

    /**
     * Post a balanced journal entry. Idempotent: if a non-reversal posted entry
     * already exists for the source, it is returned unchanged.
     *
     * @param array<int, array{account_code?:string, account_id?:int, branch_id?:int|null, description?:string|null, debit?:float|int, credit?:float|int}> $lines
     */
    public function post(
        string $sourceType,
        int $sourceId,
        ?string $sourceNo,
        string $description,
        string $entryDate,
        array $lines,
        ?int $userId = null
    ): JournalEntry {
        if ($existing = $this->findPostedForSource($sourceType, $sourceId)) {
            return $existing;
        }

        $normalized = $this->normalizeLines($lines);

        return DB::connection('tenant')->transaction(function () use ($sourceType, $sourceId, $sourceNo, $description, $entryDate, $normalized, $userId) {
            $totalDebit  = array_sum(array_column($normalized, 'debit'));
            $totalCredit = array_sum(array_column($normalized, 'credit'));

            $entry = JournalEntry::create([
                'entry_no'          => $this->nextEntryNo($entryDate),
                'entry_date'        => $entryDate,
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'source_no'         => $sourceNo,
                'description'       => $description,
                'status'            => 'posted',
                'total_debit'       => $totalDebit,
                'total_credit'      => $totalCredit,
                'posted_by_user_id' => $userId,
                'posted_at'         => now(),
                'is_reversal'       => false,
            ]);

            $this->writeLines($entry, $normalized);

            return $entry->fresh('lines');
        });
    }

    /**
     * Reverse a posted entry by creating a new entry with debits/credits flipped.
     * Idempotent: returns the existing reversal if one already exists. The original
     * is never deleted; both stay posted and net to zero in the ledger.
     */
    public function reverse(JournalEntry $entry, string $reason, ?int $userId = null): JournalEntry
    {
        if ($existing = JournalEntry::where('reversed_entry_id', $entry->id)->first()) {
            return $existing;
        }

        return DB::connection('tenant')->transaction(function () use ($entry, $reason, $userId) {
            $entry->loadMissing('lines');

            $reversal = JournalEntry::create([
                'entry_no'          => $this->nextEntryNo(now()->toDateString()),
                'entry_date'        => now()->toDateString(),
                'source_type'       => $entry->source_type . '_reversal',
                'source_id'         => $entry->source_id,
                'source_no'         => $entry->source_no,
                'description'       => 'Reversal of ' . $entry->entry_no . ($reason ? ' — ' . $reason : ''),
                'status'            => 'posted',
                'total_debit'       => $entry->total_credit,
                'total_credit'      => $entry->total_debit,
                'posted_by_user_id' => $userId,
                'posted_at'         => now(),
                'reversed_entry_id' => $entry->id,
                'is_reversal'       => true,
            ]);

            $sort = 0;
            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'account_id'  => $line->account_id,
                    'branch_id'   => $line->branch_id,
                    'description' => 'Reversal: ' . ($line->description ?? ''),
                    'debit'       => $line->credit,   // flipped
                    'credit'      => $line->debit,     // flipped
                    'sort_order'  => $sort++,
                ]);
            }

            return $reversal->fresh('lines');
        });
    }

    public function findPostedForSource(string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->where('is_reversal', false)
            ->first();
    }

    /** Resolve an active account id by code (cached). Throws if missing/inactive. */
    public function accountId(string $code): int
    {
        if (isset($this->accountCache[$code])) {
            return $this->accountCache[$code];
        }

        $account = Account::where('code', $code)->first();

        if (! $account) {
            throw new RuntimeException("Account with code [{$code}] not found in the chart of accounts.");
        }

        if (! $account->is_active) {
            throw new RuntimeException("Account [{$code}] is inactive and cannot be posted to.");
        }

        return $this->accountCache[$code] = $account->id;
    }

    /**
     * Resolve account_code → account_id, validate debit/credit exclusivity and
     * balance, drop zero lines.
     *
     * @return array<int, array{account_id:int, branch_id:?int, description:?string, debit:float, credit:float}>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $debit  = round((float) ($line['debit'] ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Journal line amounts cannot be negative.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A journal line cannot have both a debit and a credit.');
            }

            if ($debit === 0.0 && $credit === 0.0) {
                continue; // skip empty lines
            }

            $accountId = $line['account_id'] ?? null;
            if (! $accountId && ! empty($line['account_code'])) {
                $accountId = $this->accountId($line['account_code']);
            }

            if (! $accountId) {
                throw new InvalidArgumentException('Each journal line requires an account_id or account_code.');
            }

            $normalized[] = [
                'account_id'  => (int) $accountId,
                'branch_id'   => $line['branch_id'] ?? null,
                'description' => $line['description'] ?? null,
                'debit'       => $debit,
                'credit'      => $credit,
            ];
        }

        if (count($normalized) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $totalDebit  = round(array_sum(array_column($normalized, 'debit')), 4);
        $totalCredit = round(array_sum(array_column($normalized, 'credit')), 4);

        if ($totalDebit <= 0) {
            throw new InvalidArgumentException('Journal entry total must be greater than zero.');
        }

        if ($totalDebit !== $totalCredit) {
            throw new InvalidArgumentException("Journal entry is not balanced: debit {$totalDebit} != credit {$totalCredit}.");
        }

        return $normalized;
    }

    private function writeLines(JournalEntry $entry, array $normalized): void
    {
        $sort = 0;
        foreach ($normalized as $line) {
            $entry->lines()->create([
                'account_id'  => $line['account_id'],
                'branch_id'   => $line['branch_id'],
                'description' => $line['description'],
                'debit'       => $line['debit'],
                'credit'      => $line['credit'],
                'sort_order'  => $sort++,
            ]);
        }
    }

    private function nextEntryNo(string $entryDate): string
    {
        $prefix = 'JE-' . \Illuminate\Support\Carbon::parse($entryDate)->format('Ymd') . '-';

        // BUG-034 FIX: lock the max-sequence read so two concurrent posts on the
        // same date cannot both read the same last entry_no and collide.
        // We run this inside the outer transaction (which already holds a lock on
        // the new JournalEntry row being created), so the lock is coherent.
        $last = JournalEntry::where('entry_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('entry_no')
            ->value('entry_no');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
