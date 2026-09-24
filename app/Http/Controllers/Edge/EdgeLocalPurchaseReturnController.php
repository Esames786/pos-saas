<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** OFFLINE EDGE — F3: the Branch Server's purchase-return operator surface (Online UX; server-side canonical permissions). */
class EdgeLocalPurchaseReturnController extends Controller
{
    public function __construct(private readonly EdgeBranchContext $context, private readonly EdgeLocalPurchaseReturnService $returns)
    {
    }

    public function screen(Request $request): View
    {
        $user = $this->requireView($request);
        $meta = $this->context->requireCurrent();
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);
        $perms = $this->returns->permissionsFor($user);

        return view('edge.finance.purchase-returns', [
            'branchName' => $branch?->name ?? ('Branch ' . $meta->branch_id),
            'userName' => $user->name,
            'canPost' => $perms['can_post'],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $this->requireView($request);

        return response()->json($this->returns->options($user));
    }

    public function grn(Request $request, int $grn): JsonResponse
    {
        $this->requireView($request);
        try {
            return response()->json($this->returns->grn($grn));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user('tenant');
        if (! $user || ! $user->can(EdgeLocalPurchaseReturnService::PERM_STORE) || ! $user->can(EdgeLocalPurchaseReturnService::PERM_POST)) {
            abort(403, 'You are not allowed to post purchase returns on this branch server.');
        }
        $data = $request->validate([
            'cloud_grn_id' => ['required', 'integer'],
            'return_date' => ['nullable', 'date'],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.cloud_grn_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.reason_code' => ['nullable', 'string', 'max:50'],
        ]);
        try {
            $view = $this->returns->postReturn($data, $user, $this->terminalId($request));
        } catch (ValidationException $e) {
            return $this->invalid($e);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['event' => $view], 201);
    }

    public function show(Request $request, string $event): JsonResponse
    {
        $this->requireView($request);
        try {
            return response()->json(['event' => $this->returns->event($event)]);
        } catch (ValidationException $e) {
            return $this->invalid($e);
        }
    }

    /** W4 R9.4 — Purchase Returns list (Online PurchaseReturnController@index, `tenant.purchase-returns.index`). */
    public function listScreen(Request $request): View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.purchase-returns.index'), 403, 'Viewing purchase returns needs the Purchase Returns permission (tenant.purchase-returns.index).');
        $filters = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $meta = $this->context->requireCurrent();
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);

        return view('edge.finance.purchase-returns-index', [
            'branchName' => $branch?->name ?? ('Branch ' . $meta->branch_id),
            'userName' => $user->name,
            'returns' => $this->returns->listReturns($filters),
            'suppliers' => $this->returns->supplierBook(),
            'filters' => $filters,
            'canShow' => (bool) $user->can('tenant.purchase-returns.show'),
            'canCreate' => $this->returns->permissionsFor($user)['can_view'],
        ]);
    }

    /** W4 R9.4 — one Purchase Return (Online PurchaseReturnController@show, `tenant.purchase-returns.show`). No Edit/Cancel Draft offline (R9.2). */
    public function detailScreen(Request $request, string $event): View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.purchase-returns.show'), 403, 'Viewing a purchase return needs the Purchase Return detail permission (tenant.purchase-returns.show).');
        try {
            $return = $this->returns->returnDetail($event);
        } catch (ValidationException $e) {
            abort(404, 'No such purchase return on this branch server.');
        }
        $meta = $this->context->requireCurrent();
        $branch = Branch::on('tenant')->find((int) $meta->branch_id);
        $products = \App\Models\Tenant\Product::on('tenant')->whereIn('id', collect($return['lines'])->pluck('product_id')->all() ?: [0])->get(['id', 'name', 'sku'])->keyBy('id');

        return view('edge.finance.purchase-returns-show', [
            'branchName' => $branch?->name ?? ('Branch ' . $meta->branch_id),
            'userName' => $user->name,
            'return' => $return,
            'products' => $products,
            'canIndex' => (bool) $user->can('tenant.purchase-returns.index'),
        ]);
    }

    private function requireView(Request $request): \App\Models\Tenant\User
    {
        $user = $request->user('tenant');
        if (! $user || ! ($user->can(EdgeLocalPurchaseReturnService::PERM_STORE) || $user->can(EdgeLocalPurchaseReturnService::PERM_POST))) {
            abort(403, 'You are not allowed to use purchase returns on this branch server.');
        }

        return $user;
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
