<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalSupplierFinanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * OFFLINE EDGE — F2: the Branch Server's supplier-finance operator surface (Suppliers → Supplier Ledger → Record
 * Payment; General Journal with the supplier/AP dimension). Every mutation targets the Edge-local authority; the
 * Cloud posts the official transaction later, exactly once. Authorization is server-side and mirrors the Online
 * permission names (a normal cashier never gains Supplier Payment / Manual Journal).
 */
class EdgeLocalSupplierFinanceController extends Controller
{
    public function __construct(private readonly EdgeBranchContext $context, private readonly EdgeLocalSupplierFinanceService $finance)
    {
    }

    public function suppliersScreen(Request $request): View
    {
        $user = $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT]);

        return view('edge.finance.suppliers', $this->pageVars($user));
    }

    public function options(Request $request): JsonResponse
    {
        $user = $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT]);

        return response()->json($this->finance->options($user));
    }

    public function ledger(Request $request, int $supplier): JsonResponse
    {
        $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT]);
        try {
            return response()->json($this->finance->ledger($supplier));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    public function storePayment(Request $request): JsonResponse
    {
        $user = $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_PAYMENT]);
        $data = $request->validate([
            'cloud_supplier_id' => ['required', 'integer'],
            'cloud_cash_bank_account_id' => ['nullable', 'integer'],
            'cloud_bill_id' => ['nullable', 'integer'],
            'payment_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'max:30'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_no' => ['nullable', 'string', 'max:100'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'cheque_no' => ['nullable', 'string', 'max:100'],
            'cheque_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $view = $this->finance->recordPayment($data, $user, $this->terminalId($request));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['event' => $view], 201);
    }

    public function journalScreen(Request $request): View
    {
        $user = $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_JOURNAL]);

        return view('edge.finance.journal', $this->pageVars($user));
    }

    public function journalOptions(Request $request): JsonResponse
    {
        $user = $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_JOURNAL]);

        return response()->json($this->finance->journalOptions($user));
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $user = $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_JOURNAL]);
        $data = $request->validate([
            'entry_date' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.cloud_account_id' => ['required', 'integer'],
            'lines.*.cloud_cash_bank_account_id' => ['nullable', 'integer'],
            'lines.*.cloud_supplier_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);
        try {
            $view = $this->finance->postApJournal($data, $user, $this->terminalId($request));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['event' => $view], 201);
    }

    public function showEvent(Request $request, string $event): JsonResponse
    {
        $this->requireAny($request, [EdgeLocalSupplierFinanceService::PERM_LEDGER, EdgeLocalSupplierFinanceService::PERM_PAYMENT, EdgeLocalSupplierFinanceService::PERM_JOURNAL]);
        try {
            return response()->json(['event' => $this->finance->event($event)]);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    private function pageVars(\App\Models\Tenant\User $user): array
    {
        $meta = $this->context->requireCurrent();
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);
        $perms = $this->finance->permissionsFor($user);

        return [
            'branchName' => $branch?->name ?? ('Branch ' . $meta->branch_id),
            'userName' => $user->name,
            'canViewLedger' => $perms['can_view_ledger'],
            'canPay' => $perms['can_pay'],
            'canJournal' => $perms['can_journal'],
        ];
    }

    private function requireAny(Request $request, array $permissions): \App\Models\Tenant\User
    {
        $user = $request->user('tenant');
        if (! $user) {
            abort(403, 'Sign in first.');
        }
        foreach ($permissions as $p) {
            if ($user->can($p)) {
                return $user;
            }
        }
        abort(403, 'You are not allowed to use supplier finance on this branch server (' . implode(' / ', $permissions) . ').');
    }

    private function terminalId(Request $request): ?int
    {
        $id = (int) $request->session()->get(EdgeLocalPosController::TERMINAL_SESSION_KEY, 0);

        return $id > 0 ? $id : null;
    }

    private function invalid(ValidationException $e): JsonResponse
    {
        return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
    }
}
