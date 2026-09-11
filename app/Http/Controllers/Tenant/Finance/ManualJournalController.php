<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Supplier;
use App\Services\Finance\ManualJournalService;
use App\Services\Finance\SupplierPayableService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(
        private ManualJournalService $manualJournals,
        private SupplierPayableService $supplierPayable,
    ) {}

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
            // ONE official code path (ManualJournalService): GL + cash/bank movements + supplier subledger mirror in
            // one transaction — the same authority the Edge supplier-finance ingestion posts through.
            $entry = $this->manualJournals->post($data, Auth::guard('tenant')->id());
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
            $reversal = $this->manualJournals->reverse($manualJournal, $reason, Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return back()->withErrors(['journal' => $e->getMessage()]);
        }

        return redirect(url('/finance/manual-journals/' . $reversal->id))
            ->with('status', 'Reversal ' . $reversal->entry_no . ' posted.');
    }

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
            // SUPPLIER-FINANCE-DIRECT-1 — AP ki satar par supplier ka dimension (aaina subledger mein jata hai).
            'lines.*.counterparty_type'      => ['nullable', 'string', 'in:supplier'],
            'lines.*.supplier_id'            => ['nullable', 'integer', 'exists:tenant.suppliers,id'],
            'lines.*.description'            => ['nullable', 'string', 'max:255'],
            'lines.*.debit'                  => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'                 => ['nullable', 'numeric', 'min:0'],
        ]);

        // Debit XOR credit per line, and every Accounts Payable line names its supplier — the canonical rule,
        // shared with the Edge ingestion through the service.
        $this->manualJournals->assertApLinesNameTheirSupplier($data['lines']);

        return $data;
    }

    private function formData(): array
    {
        return [
            'accounts'        => Account::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'cashBankAccounts'=> CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
            // AP ki satar par supplier chunna lazmi hai; form ko dono cheezein chahiye —
            // supplier ki list, aur kaunse account AP hain (taake picker sirf tab khule).
            'suppliers'       => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'apAccountIds'    => $this->supplierPayable->apAccountIds(),
        ];
    }
}
