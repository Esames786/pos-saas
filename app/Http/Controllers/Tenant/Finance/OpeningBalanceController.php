<?php

namespace App\Http\Controllers\Tenant\Finance;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\OpeningBalanceBatch;
use App\Services\Finance\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Throwable;

class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $service) {}

    public function index(Request $request)
    {
        $query = OpeningBalanceBatch::query()->with(['branch', 'journalEntry']);

        if ($request->filled('status') && in_array($request->status, OpeningBalanceBatch::STATUSES, true)) {
            $query->where('status', $request->status);
        }

        return view('tenant.finance.opening-balances.index', [
            'batches'  => $query->orderByDesc('opening_date')->orderByDesc('id')->limit(500)->get(),
            'statuses' => OpeningBalanceBatch::STATUSES,
            'filters'  => $request->only(['status']),
        ]);
    }

    public function create()
    {
        return view('tenant.finance.opening-balances.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $batch = $this->service->createDraft($data);

        return redirect(url('/finance/opening-balances/' . $batch->id))
            ->with('status', 'Opening balance batch created (draft).');
    }

    public function show(OpeningBalanceBatch $openingBalanceBatch)
    {
        $openingBalanceBatch->load([
            'lines.account', 'lines.cashBankAccount', 'branch',
            'journalEntry', 'createdBy', 'postedBy', 'voidedBy',
        ]);

        return view('tenant.finance.opening-balances.show', [
            'batch' => $openingBalanceBatch,
        ]);
    }

    public function edit(OpeningBalanceBatch $openingBalanceBatch)
    {
        if (! $openingBalanceBatch->isDraft()) {
            return redirect(url('/finance/opening-balances/' . $openingBalanceBatch->id))
                ->withErrors(['batch' => 'Only draft batches can be edited.']);
        }

        $openingBalanceBatch->load('lines');

        return view('tenant.finance.opening-balances.edit', $this->formData() + [
            'batch' => $openingBalanceBatch,
        ]);
    }

    public function update(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        if (! $openingBalanceBatch->isDraft()) {
            return back()->withErrors(['batch' => 'Only draft batches can be edited.']);
        }

        $data = $this->validateData($request, $openingBalanceBatch);

        $this->service->updateDraft($openingBalanceBatch, $data);

        return redirect(url('/finance/opening-balances/' . $openingBalanceBatch->id))
            ->with('status', 'Opening balance batch updated.');
    }

    public function post(OpeningBalanceBatch $openingBalanceBatch)
    {
        try {
            $this->service->post($openingBalanceBatch, Auth::guard('tenant')->id());
        } catch (Throwable $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        return redirect(url('/finance/opening-balances/' . $openingBalanceBatch->id))
            ->with('status', 'Opening balance posted to the general ledger.');
    }

    public function void(Request $request, OpeningBalanceBatch $openingBalanceBatch)
    {
        $data = $request->validate(['void_reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->service->void($openingBalanceBatch, Auth::guard('tenant')->id(), $data['void_reason'] ?? null);
        } catch (Throwable $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        return redirect(url('/finance/opening-balances/' . $openingBalanceBatch->id))
            ->with('status', 'Opening balance voided — journal reversed and cash/bank restored.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function validateData(Request $request, ?OpeningBalanceBatch $batch = null): array
    {
        $data = $request->validate([
            'batch_no'     => ['nullable', 'string', 'max:50', Rule::unique('opening_balance_batches', 'batch_no')->ignore($batch?->id)],
            'opening_date' => ['required', 'date'],
            'branch_id'    => ['nullable', 'integer', 'exists:branches,id'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'lines'                       => ['required', 'array', 'min:1'],
            'lines.*.account_id'          => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
            'lines.*.cash_bank_account_id' => ['nullable', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', true)],
            'lines.*.description'         => ['nullable', 'string', 'max:255'],
            'lines.*.debit'               => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'              => ['nullable', 'numeric', 'min:0'],
        ]);

        // Each line must be a pure debit OR pure credit (never both, never empty),
        // and at least two non-zero lines must exist to form a valid journal.
        $nonZero = 0;
        foreach ($data['lines'] as $i => $line) {
            $debit  = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit > 0 && $credit > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "lines.$i.debit" => 'A line cannot have both a debit and a credit.',
                ]);
            }
            if ($debit > 0 || $credit > 0) {
                $nonZero++;
            }
        }

        if ($nonZero < 2) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'lines' => 'Enter at least two lines with a debit or credit amount.',
            ]);
        }

        return $data;
    }

    private function formData(): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'accounts' => Account::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'cashBankAccounts' => CashBankAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_id']),
        ];
    }
}
