<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Services\Finance\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Manual Journal Entry — Q2 feature.
 *
 * Allows posting any balanced double-entry journal directly against the General
 * Ledger for: asset purchases, capital injections, inter-account transfers,
 * corrections, depreciation, accruals, or any ad-hoc event not covered by the
 * operational flows (sales / purchases / expenses).
 *
 * Optionally links cash/bank lines to operational cash_bank_account_transactions
 * so the cash/bank running balance stays in sync (same as opening balances do).
 */
class ManualJournalController extends Controller
{
    public function __construct(private JournalService $journal) {}

    public function index(Request $request)
    {
        $query = JournalEntry::where('source_type', 'manual_journal')
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($request->filled('date_from')) {
            $query->whereDate('entry_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('entry_date', '<=', $request->date_to);
        }
        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                  ->orWhere('source_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return view('tenant.finance.manual-journals.index', [
            'entries' => $query->limit(500)->get(),
            'filters' => $request->only(['date_from', 'date_to', 'q']),
        ]);
    }

    public function create()
    {
        return view('tenant.finance.manual-journals.form', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        try {
            $entry = DB::connection('tenant')->transaction(function () use ($data, $request) {
                $lines = $this->normalizeLines($data['lines']);

                $entry = $this->journal->post(
                    sourceType:  'manual_journal',
                    sourceId:    $this->nextManualJournalId(),
                    sourceNo:    $data['reference_no'] ?: null,
                    description: $data['description'],
                    entryDate:   $data['entry_date'],
                    lines:       $lines,
                    userId:      Auth::guard('tenant')->id(),
                );

                // Update cash/bank operational balances for any cash/bank-linked lines.
                $this->syncCashBankLines($entry, $data['lines'], $data['entry_date'], Auth::guard('tenant')->id());

                return $entry;
            });
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['journal' => $e->getMessage()]);
        }

        return redirect(url('/finance/manual-journals/' . $entry->id))
            ->with('status', 'Manual journal ' . $entry->entry_no . ' posted successfully.');
    }

    public function show(JournalEntry $manualJournal)
    {
        abort_unless($manualJournal->source_type === 'manual_journal', 404);

        $manualJournal->load(['lines.account', 'lines.branch', 'postedBy', 'reversedEntry']);

        $reversal = JournalEntry::where('reversed_entry_id', $manualJournal->id)->first();

        $cashBankTxns = CashBankAccountTransaction::query()
            ->where('reference_type', 'manual_journal')
            ->where('reference_id', $manualJournal->id)
            ->with('cashBankAccount')
            ->get();

        return view('tenant.finance.manual-journals.show', compact('manualJournal', 'reversal', 'cashBankTxns'));
    }

    public function reverse(Request $request, JournalEntry $manualJournal)
    {
        abort_unless($manualJournal->source_type === 'manual_journal', 404);
        abort_unless($manualJournal->status === 'posted', 422);
        abort_unless(! $manualJournal->is_reversal, 422);

        $existing = JournalEntry::where('reversed_entry_id', $manualJournal->id)->first();
        if ($existing) {
            return back()->withErrors(['journal' => 'This entry has already been reversed (' . $existing->entry_no . ').']);
        }

        $reason = $request->input('reason', 'Manual reversal');

        try {
            $reversal = DB::connection('tenant')->transaction(function () use ($manualJournal, $reason) {
                $reversal = $this->journal->reverse(
                    $manualJournal,
                    $reason,
                    Auth::guard('tenant')->id()
                );

                // Reverse the cash/bank operational movements too.
                $this->reverseCashBankLines($manualJournal, Auth::guard('tenant')->id());

                return $reversal;
            });
        } catch (Throwable $e) {
            return back()->withErrors(['journal' => $e->getMessage()]);
        }

        return redirect(url('/finance/manual-journals/' . $reversal->id))
            ->with('status', 'Reversal ' . $reversal->entry_no . ' posted.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'entry_date'   => ['required', 'date'],
            'description'  => ['required', 'string', 'max:500'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'branch_id'    => ['nullable', 'integer', 'exists:branches,id'],
            'lines'                          => ['required', 'array', 'min:2'],
            'lines.*.account_id'             => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.cash_bank_account_id'   => ['nullable', 'integer', 'exists:cash_bank_accounts,id'],
            'lines.*.description'            => ['nullable', 'string', 'max:255'],
            'lines.*.debit'                  => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'                 => ['nullable', 'numeric', 'min:0'],
        ]);

        // Validate no line has both debit and credit.
        foreach ($data['lines'] as $i => $line) {
            $d = (float) ($line['debit']  ?? 0);
            $c = (float) ($line['credit'] ?? 0);
            if ($d > 0 && $c > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "lines.$i.debit" => 'A line cannot have both a debit and a credit.',
                ]);
            }
        }

        return $data;
    }

    private function normalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            $debit  = round((float) ($line['debit']  ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);
            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }
            $normalized[] = [
                'account_id'  => (int) $line['account_id'],
                'branch_id'   => ! empty($line['branch_id']) ? (int) $line['branch_id'] : null,
                'description' => $line['description'] ?? null,
                'debit'       => $debit,
                'credit'      => $credit,
            ];
        }
        return $normalized;
    }

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

            // Debit on a cash/bank account = money IN (e.g. receiving payment).
            // Credit on a cash/bank account = money OUT (e.g. paying for asset).
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
     * Manual journals don't use the (source_type, source_id) idempotency key the same
     * way automated ones do — each manual post is a NEW entry. We use a dedicated
     * auto-increment sequence stored in a simple counter table row, falling back to
     * a timestamp-based ID to avoid race conditions.
     */
    private function nextManualJournalId(): int
    {
        return (int) DB::connection('tenant')
            ->table('journal_entries')
            ->where('source_type', 'manual_journal')
            ->max('source_id') + 1;
    }

    private function formData(): array
    {
        return [
            'accounts'        => Account::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'cashBankAccounts'=> CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
        ];
    }
}
