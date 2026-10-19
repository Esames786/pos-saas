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
        abort_unless($manualJournal->source_type === 'manual_journal', 404);
        abort_unless($manualJournal->status === 'posted', 422);
        abort_unless(! $manualJournal->is_reversal, 422);

        // Dobara dabaya gaya Reverse kuch nahi banata (B3) — user ko us reversal par le jao jo
        // pehle ban chuki hai. Pehle ye error ke saath wapas bhejta tha aur reversal ka safha 404
        // tha, is liye user ko pata hi nahi chalta tha ke kaam ho chuka hai.
        $existing = JournalEntry::where('reversed_entry_id', $manualJournal->id)->first();
        if ($existing) {
            return redirect(url('/finance/manual-journals/' . $existing->id))
                ->with('status', $manualJournal->entry_no . ' was already reversed by ' . $existing->entry_no
                    . ' — nothing was posted again.');
        }

        $reason = $request->input('reason', 'Manual reversal');

        try {
            // MANUAL-JOURNAL-CASH-LINE-GUARD-1 (canonical ed581520, merge D8): two tabs pressing Reverse at once — the
            // original row is locked so the second request waits for the first commit and meets the reversal already made.
            // The Edge branch keeps the ONE official path (ManualJournalService::reverse — GL + cash/bank counter-movements +
            // supplier subledger mirror, the same authority the Edge supplier-finance ingestion posts through) inside that
            // lock; team D1 moves the lock (and the R1–R3 / narration guards above) into the service.
            $reversal = DB::connection('tenant')->transaction(function () use ($manualJournal, $reason) {
                JournalEntry::whereKey($manualJournal->id)->lockForUpdate()->first();

                return $this->manualJournals->reverse($manualJournal, $reason, Auth::guard('tenant')->id());
            });
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

        $this->guardNarration((string) $data['description']);
        $data['lines'] = $this->guardCashLines($data['lines']);

        return $data;
    }

    /**
     * MANUAL-JOURNAL-CASH-LINE-GUARD-1 (R5) — narration asli ho.
     *
     * Ek live tenant ki paanchon ghalat entries ki narration sirf "." thi; baad me koi nahi bata
     * sakta tha ke wo kis cheez ki thi. Kam az kam 3 characters, aur un me koi harf ya hindsa —
     * ".", "-", "..." rad.
     */
    private function guardNarration(string $description): void
    {
        $text = trim($description);

        if (mb_strlen($text) < 3 || ! preg_match('/[\p{L}\p{N}]/u', $text)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'description' => 'Write what this entry is for, in at least 3 characters (e.g. "Owner drawing - cash"). '
                    . 'A dot or a dash is not a description.',
            ]);
        }
    }

    /**
     * MANUAL-JOURNAL-CASH-LINE-GUARD-1 — Cash/Bank sirf apni hi satar par.
     *
     * Pehle form har satar par Cash/Bank lene deta tha aur syncCashBankLines() usay aankh band
     * kar ke maan leta tha: debit = paisa ANDAR, credit = BAHAR — chahe satar kisi bhi khaate
     * ki ho. Ek live tenant par drawer drawing (debit) ki satar par chuna gaya, to drawing
     * Cash Book me "In" ban gayi; aur jahan credit group head 1100 par gaya wahan drawer chuna
     * hi nahi gaya, to paisa nikla magar Cash Book me kuch nahi aaya.
     *
     *   R1  drawer chuna hai → satar ka khaata wohi ho jis se drawer juda hai (CashBankAccount.account_id)
     *   R2  satar kisi active drawer ke khaate par hai → drawer lazmi; ek hi juda ho to khud bhar do,
     *       kai hon (jaise do drawer ek hi 1110 par) to user se chunwao
     *   R3  group head (jis ke neeche khaate hain) par posting nahi
     *
     * Direction ka usool NAHI badla — sahi satar par debit = andar, credit = bahar theek hai.
     * Shart sirf IS raaste par hai; system ke apne journals (JournalPostingService) par nahi.
     * Reverse bhi azad hai: purani ghalat entry palatna hi to us ka ilaaj hai.
     *
     * @return array satrein — R2 ka khud bhara hua drawer samet
     */
    private function guardCashLines(array $lines): array
    {
        $accountIds = collect($lines)->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $accounts   = Account::whereIn('id', $accountIds)->get(['id', 'code', 'name'])->keyBy('id');
        $groupIds   = Account::whereIn('parent_id', $accountIds)->distinct()->pluck('parent_id')
            ->map(fn ($id) => (int) $id)->all();

        $drawers    = CashBankAccount::query()->get(['id', 'code', 'name', 'account_id', 'is_active'])->keyBy('id');
        $activeByGl = $drawers->filter(fn ($d) => $d->is_active && $d->account_id)
            ->groupBy(fn ($d) => (int) $d->account_id);
        $drawerGls  = Account::whereIn('id', $drawers->pluck('account_id')->filter()->unique())
            ->get(['id', 'code', 'name'])->keyBy('id');

        $errors = [];

        foreach ($lines as $i => $line) {
            // Khali satar kuch post nahi karti (normalizeLines aur syncCashBankLines dono chhor dete hain).
            if ((float) ($line['debit'] ?? 0) <= 0 && (float) ($line['credit'] ?? 0) <= 0) {
                continue;
            }

            $accountId = (int) ($line['account_id'] ?? 0);
            $account   = $accounts->get($accountId);
            $label     = $account ? $account->code . ' ' . $account->name : 'this account';

            // R3 — group head.
            if (in_array($accountId, $groupIds, true)) {
                $child = Account::where('parent_id', $accountId)->where('is_active', true)
                    ->orderBy('sort_order')->orderBy('code')->first(['code', 'name']);
                $errors["lines.$i.account_id"] = $label . ' is a group. Choose an account under it'
                    . ($child ? ', e.g. ' . $child->code . ' ' . $child->name . '.' : '.');
                continue;
            }

            $drawerId = (int) ($line['cash_bank_account_id'] ?? 0);

            // R1 — chuna hua drawer apni hi satar par.
            if ($drawerId) {
                $drawer = $drawers->get($drawerId);
                if ($drawer && (int) $drawer->account_id !== $accountId) {
                    $gl = $drawerGls->get((int) $drawer->account_id);
                    $errors["lines.$i.cash_bank_account_id"] = $gl
                        ? $drawer->name . ' is linked to ' . $gl->code . ' ' . $gl->name . '. Choose it on the '
                            . $gl->code . ' line, not on ' . $label . '.'
                        : $drawer->name . ' is not linked to any account in the chart of accounts, so it cannot '
                            . 'be used on a journal line. Link it on the Cash & Bank screen first.';
                }
                continue;
            }

            // R2 — cash ki satar bina drawer ke.
            $linked = $activeByGl->get($accountId, collect());
            if ($linked->count() === 1) {
                $lines[$i]['cash_bank_account_id'] = $linked->first()->id;
            } elseif ($linked->count() > 1) {
                $errors["lines.$i.cash_bank_account_id"] = $label . ' is used by more than one cash/bank account ('
                    . $linked->pluck('name')->implode(', ') . '). Choose which one the money went through.';
            }
        }

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return $lines;
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
