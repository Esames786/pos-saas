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

    // ── W4 R8.4 / R8.6 — list / detail screens (Online route permissions: *.index / *.show) ────────────────────────

    /** Supplier Payments list (Online SupplierPaymentController@index, `tenant.supplier-payments.index`). */
    public function paymentsIndex(Request $request): View
    {
        $user = $this->requireAny($request, ['tenant.supplier-payments.index']);
        $filters = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return view('edge.finance.supplier-payments-index', $this->pageVars($user) + [
            'payments' => $this->finance->listEvents(\App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT, $filters),
            'suppliers' => $this->finance->supplierBook(),
            'filters' => $filters,
            'canShow' => (bool) $user->can('tenant.supplier-payments.show'),
        ]);
    }

    /** One supplier payment (Online SupplierPaymentController@show, `tenant.supplier-payments.show`). */
    public function paymentShow(Request $request, string $event): View
    {
        $user = $this->requireAny($request, ['tenant.supplier-payments.show']);
        try {
            $payment = $this->finance->eventOfType($event, \App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder::EVENT_PAYMENT);
        } catch (ValidationException $e) {
            abort(404, 'No such supplier payment on this branch server.');
        }

        return view('edge.finance.supplier-payments-show', $this->pageVars($user) + [
            'payment' => $payment,
            'canIndex' => (bool) $user->can('tenant.supplier-payments.index'),
        ]);
    }

    /** Manual Journals list (Online ManualJournalController@index, `tenant.finance.manual-journals.index`): date range + search. */
    public function journalsIndex(Request $request): View
    {
        $user = $this->requireAny($request, ['tenant.finance.manual-journals.index']);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return view('edge.finance.manual-journals-index', $this->pageVars($user) + [
            'journals' => $this->finance->listEvents(\App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL, $filters),
            'filters' => $filters,
            'canShow' => (bool) $user->can('tenant.finance.manual-journals.show'),
        ]);
    }

    /** One manual journal (Online ManualJournalController@show, `tenant.finance.manual-journals.show`). Reverse: owner-dependent, not offered. */
    public function journalShow(Request $request, string $event): View
    {
        $user = $this->requireAny($request, ['tenant.finance.manual-journals.show']);
        try {
            $journal = $this->finance->eventOfType($event, \App\Services\Edge\EdgeSupplierFinanceEnvelopeBuilder::EVENT_AP_JOURNAL);
        } catch (ValidationException $e) {
            abort(404, 'No such journal on this branch server.');
        }

        return view('edge.finance.manual-journals-show', $this->pageVars($user) + [
            'journal' => $journal,
            'canIndex' => (bool) $user->can('tenant.finance.manual-journals.index'),
        ]);
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
