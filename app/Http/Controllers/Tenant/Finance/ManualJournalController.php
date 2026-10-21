<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\CashBankAccountTransaction;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Supplier;
use App\Services\Finance\ManualJournalReversalRefused;
use App\Services\Finance\ManualJournalService;
use App\Services\Finance\SupplierPayableService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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
 *
 * D1 (owner decision, 20 Oct 2026) — a THIN caller: the business rules (MANUAL-JOURNAL-CASH-LINE-GUARD-1 R1–R3 cash
 * lines, R5 narration, the AP-supplier rule) and the reverse rules (row lock, already-reversed refusal) live in
 * ManualJournalService / ManualJournalRules, shared with the Edge offline General Journal and the Cloud ingestion.
 * This controller validates the request shape, calls the service and translates its answers into the canonical
 * responses (field-keyed validation errors, 404/422 aborts, the B3 "already reversed" redirect) — unchanged for users.
 */
class ManualJournalController extends Controller
{
    public function __construct(
        private ManualJournalService $manualJournals,
        private SupplierPayableService $supplierPayable,
    ) {}

    public function index(Request $request)
    {
        // MANUAL-JOURNAL-CASH-LINE-GUARD-1 (B2) — reversal bhi list me. Pehle sirf `manual_journal`
        // dikhta tha, is liye Reverse ke baad na reversal nazar aata tha na asal par koi nishan —
        // aur list ka "Reversal" badge kabhi chalta hi nahi tha.
        $query = JournalEntry::whereIn('source_type', ['manual_journal', 'manual_journal_reversal'])
            ->with('reversedEntry:id,entry_no')
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

        $entries = $query->limit(500)->get();

        // Asal entry par "Reversed by JE-…" — reversal aaj ki tareekh par banti hai, is liye date
        // filter se bahar ho sakti hai; usay id se dhoonda jata hai, filter se nahi.
        $reversedBy = JournalEntry::whereIn('reversed_entry_id', $entries->pluck('id'))
            ->get(['id', 'entry_no', 'reversed_entry_id'])
            ->keyBy('reversed_entry_id');

        return view('tenant.finance.manual-journals.index', [
            'entries'    => $entries,
            'reversedBy' => $reversedBy,
            'filters'    => $request->only(['date_from', 'date_to', 'q']),
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
            // ONE official code path (ManualJournalService): the shared business rules (R1–R3, R5, AP supplier), then
            // GL + cash/bank movements + supplier subledger mirror in one transaction — the same authority and the same
            // rules the Edge supplier-finance ingestion posts through.
            $entry = $this->manualJournals->post($data, Auth::guard('tenant')->id());
        } catch (ValidationException $e) {
            // A rule refused the journal: field-keyed errors back to the form (or 422 JSON) — the canonical response.
            throw $e;
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['journal' => $e->getMessage()]);
        }

        return redirect(url('/finance/manual-journals/' . $entry->id))
            ->with('status', 'Manual journal ' . $entry->entry_no . ' posted successfully.');
    }

    public function show(JournalEntry $manualJournal)
    {
        // MANUAL-JOURNAL-CASH-LINE-GUARD-1 (B1) — Reverse ke baad user reversal ke safhe par jata
        // hai; pehle wo 404 deta tha (sirf `manual_journal` khulta tha), aur user ghabra kar
        // Reverse dobara dabata tha. Reversal ab khulta hai — sirf parhne ke liye: view us par
        // Reverse ka button nahi dikhata (`is_reversal`), aur reverse() bhi usay qubool nahi karta.
        abort_unless(in_array($manualJournal->source_type, ['manual_journal', 'manual_journal_reversal'], true), 404);

        $manualJournal->load(['lines.account', 'lines.branch', 'postedBy', 'reversedEntry']);

        $reversal = JournalEntry::where('reversed_entry_id', $manualJournal->id)->first();

        // Reversal ki cash satrein asal ki SATRON ki id par bandhi hain (reverseCashBankLines:
        // reference_id = asal transaction ki id), entry ki id par nahi.
        $cashBankTxns = $manualJournal->source_type === 'manual_journal_reversal'
            ? CashBankAccountTransaction::query()
                ->where('reference_type', 'manual_journal_reversal')
                ->whereIn('reference_id', CashBankAccountTransaction::query()
                    ->where('reference_type', 'manual_journal')
                    ->where('reference_id', $manualJournal->reversed_entry_id)
                    ->pluck('id'))
                ->with('cashBankAccount')
                ->get()
            : CashBankAccountTransaction::query()
                ->where('reference_type', 'manual_journal')
                ->where('reference_id', $manualJournal->id)
                ->with('cashBankAccount')
                ->get();

        return view('tenant.finance.manual-journals.show', compact('manualJournal', 'reversal', 'cashBankTxns'));
    }

    public function reverse(Request $request, JournalEntry $manualJournal)
    {
        $reason = $request->input('reason', 'Manual reversal');
        // VOID-ON-ORIGINAL-DATE-1: a wrong journal is wiped on its own date; a right one reversed today (default).
        $request->validate(['void_date_mode' => \App\Support\VoidDate::rule()]);
        $mode = \App\Support\VoidDate::mode($request->input('void_date_mode'));

        try {
            // ONE official path, ONE rule set (D1): ManualJournalService::reverse locks the original row (two tabs pressing
            // Reverse at once serialise), accepts only a posted manual journal that is not itself a reversal, and refuses
            // an entry that already has a reversal without posting anything — then GL reversal + cash/bank counter-movements
            // + supplier subledger mirror, the same authority the Edge supplier-finance ingestion posts through.
            // VOID-ON-ORIGINAL-DATE-1 (canonical, merged 22 Oct 2026): the date mode rides the SAME service call — a wrong entry
            // wiped on its own date, a right one reversed today; the service dates the GL reversal and the cash/bank counter-movements.
            $reversal = $this->manualJournals->reverse($manualJournal, $reason, Auth::guard('tenant')->id(), $mode);
        } catch (ManualJournalReversalRefused $e) {
            // Dobara dabaya gaya Reverse kuch nahi banata (B3) — user ko us reversal par le jao jo pehle ban chuki hai.
            // Pehle ye error ke saath wapas bhejta tha aur reversal ka safha 404 tha, is liye user ko pata hi nahi
            // chalta tha ke kaam ho chuka hai.
            if ($e->reason === ManualJournalReversalRefused::ALREADY_REVERSED && $e->existingReversal) {
                return redirect(\App\Support\CashBookReturn::from($request) ?? url('/finance/manual-journals/' . $e->existingReversal->id))
                    ->with('status', $e->getMessage());
            }

            // The canonical abort codes: not a manual journal → 404; not posted / itself a reversal → 422.
            abort($e->reason === ManualJournalReversalRefused::NOT_MANUAL_JOURNAL ? 404 : 422);
        } catch (Throwable $e) {
            return back()->withErrors(['journal' => $e->getMessage()]);
        }

        // CASH-BOOK-VOID-1: Cash Book se aaya reverse wapas Cash Book par.
        return redirect(\App\Support\CashBookReturn::from($request) ?? url('/finance/manual-journals/' . $reversal->id))
            ->with('status', 'Reversal ' . $reversal->entry_no . ' posted for ' . $manualJournal->entry_no
                . ($mode === \App\Support\VoidDate::ORIGINAL ? ' on its own date (' . $reversal->entry_date->format('d M Y') . ').' : ', dated today.'));
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

        // The business rules (debit XOR credit, every Accounts Payable line names its supplier, R5 narration, R1–R3 cash
        // lines) are NOT here any more — ManualJournalService::post() enforces them (D1), shared with the Edge paths.
        return $data;
    }

    private function formData(): array
    {
        return [
            'accounts'        => Account::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'type']),
            // R3 — group heads list me nazar aate hain magar chune nahi ja sakte (server bhi rad karta hai).
            'groupAccountIds' => Account::whereNotNull('parent_id')->distinct()->pluck('parent_id')->map(fn ($id) => (int) $id)->all(),
            // R4 — har drawer ka khaata saath aata hai, taake satar ka khaata hi drawer ka picker chalaye.
            'cashBankAccounts'=> CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type', 'account_id']),
            'branches'        => Branch::orderBy('name')->get(['id', 'name']),
            // AP ki satar par supplier chunna lazmi hai; form ko dono cheezein chahiye —
            // supplier ki list, aur kaunse account AP hain (taake picker sirf tab khule).
            'suppliers'       => Supplier::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'apAccountIds'    => $this->supplierPayable->apAccountIds(),
        ];
    }
}
