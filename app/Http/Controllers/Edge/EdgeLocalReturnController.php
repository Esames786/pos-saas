<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * W0c (Team 4 owns — finance) — F1 SALES RETURNS on the Branch Server (post-settlement void = return; cash refund out
 * of this till). Split out of EdgeLocalPosController; same routes, same behaviour. EdgeLocalReturnService is the authority.
 */
class EdgeLocalReturnController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalReturnService $returns,
    ) {
    }

    /** Returnable sales by number / customer — local sales and mirrored Online sales alike (scoped to the operator). */
    public function returnsSearch(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessMayReturn($request)) {
            return $denied;
        }

        $user = $request->user('tenant');
        $sales = $this->returns->search((string) $request->query('q', ''), 20, $user);
        $branchName = \App\Models\Tenant\Branch::on('tenant')->whereKey((int) $this->context->requireCurrent()->branch_id)->value('name');

        return response()->json([
            'sales' => $sales,
            // W-B shared screens: the tenant sales-returns/create sale picker is a select2 over Online /ajax/sales
            // (`{results:[{id,text}], pagination}`) — the same shape, from the same scoped search (additive).
            'results' => collect($sales)->map(fn ($s) => [
                'id' => $s['id'],
                'text' => $s['sale_no'] . ' — ' . ($branchName ?? '') . ' — ' . number_format((float) $s['grand_total'], 2)
                    . ($s['customer_name'] ? ' — ' . $s['customer_name'] : '') . ' (' . ($s['business_date'] ?? '') . ')',
            ])->values(),
            'pagination' => ['more' => false],
            // W4 R3.6: the Online Sales Returns list entry (shown only with its own permission).
            'can_view_list' => (bool) $user->can('tenant.sales-returns.index'),
            'list_url' => url('/edge/local/pos/sales-returns'),
        ]);
    }

    /** The return screen's data for one sale (what the Online create screen shows + the offline facts). */
    public function returnableSale(Request $request, int $sale): JsonResponse
    {
        if ($denied = $this->denyUnlessMayReturn($request)) {
            return $denied;
        }
        try {
            return response()->json($this->returns->returnable($sale, $request->user('tenant')));
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
    }

    /** Post a return. Business messages for every refusal; nothing is ever half-posted. */
    public function storeReturn(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessMayReturn($request)) {
            return $denied;
        }
        $data = $request->validate([
            'sales_order_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['required', 'string', 'in:cash,bank_transfer,card,other'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'manager_approval_id' => ['nullable', 'integer'],
        ]);
        $terminalId = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        if ($terminalId <= 0) {
            return response()->json(['message' => 'Select a terminal before posting a return.'], 422);
        }
        try {
            $view = $this->returns->processReturn(
                (int) $data['sales_order_id'], $data['lines'], $data['reason'] ?? null, (string) $data['refund_method'],
                isset($data['refund_amount']) ? (float) $data['refund_amount'] : null, $request->user('tenant'), $terminalId,
                isset($data['manager_approval_id']) ? (int) $data['manager_approval_id'] : null
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // W4 R3.6: Online lands on the posted return (/sales-returns/{id}); the Edge detail screen when the operator may open it.
        $view['detail_url'] = $request->user('tenant')->can('tenant.sales-returns.show') ? url('/edge/local/pos/sales-returns/' . $view['id']) : null;

        return response()->json(['return' => $view], 201);
    }

    public function showReturn(Request $request, int $return): JsonResponse
    {
        if ($denied = $this->denyUnlessMayReturn($request)) {
            return $denied;
        }

        return response()->json(['return' => $this->returns->show($return)]);
    }

    /**
     * W4 R3.6 — SALES RETURNS list screen (Online SalesReturnController@index, gated `tenant.sales-returns.index`):
     * Return No / Sale No / Branch / Return Date / Grand Total / Refund Method / Status (+ sync) / View, the Online
     * date-range filter with Today / Yesterday, and the operator's UserDataScope on the underlying order.
     */
    public function listScreen(Request $request): \Illuminate\Contracts\View\View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.sales-returns.index'), 403, 'Viewing sales returns needs the Sales Returns permission (tenant.sales-returns.index).');
        $filters = $request->validate([
            'range' => ['nullable', 'in:today,yesterday'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $branch = \App\Models\Tenant\Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        $clock = app(\App\Support\TenantClock::class);
        $today = $clock->now($clock->businessTimezone($branch))->toDateString();
        [$from, $to] = match ($filters['range'] ?? null) {
            'today' => [$today, $today],
            'yesterday' => [($y = \Illuminate\Support\Carbon::parse($today)->subDay()->toDateString()), $y],
            default => [$filters['date_from'] ?? null, $filters['date_to'] ?? null],
        };

        return view('edge.finance.sales-returns-index', [
            'branchName' => $branch?->name,
            'userName' => $user->name,
            'returns' => $this->returns->listReturns($user, ['date_from' => $from, 'date_to' => $to]),
            'dateFrom' => $from,
            'dateTo' => $to,
            'today' => $today,
            'clock' => $clock,
            'tz' => $clock->businessTimezone($branch),
            'canShow' => (bool) $user->can('tenant.sales-returns.show'),
            'canCreate' => (bool) $user->can('tenant.sales-returns.store'),
        ]);
    }

    /** W4 R3.6 — one SALES RETURN (Online SalesReturnController@show, gated `tenant.sales-returns.show`). */
    public function detailScreen(Request $request, int $salesReturn): \Illuminate\Contracts\View\View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.sales-returns.show'), 403, 'Viewing a sales return needs the Sales Return detail permission (tenant.sales-returns.show).');
        try {
            $return = $this->returns->returnDetail($salesReturn, $user);
        } catch (ValidationException $e) {
            abort(404, 'No such sales return on this branch server.');
        }
        $branch = \App\Models\Tenant\Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        $clock = app(\App\Support\TenantClock::class);

        return view('edge.finance.sales-returns-show', [
            'branchName' => $branch?->name,
            'userName' => $user->name,
            'salesReturn' => $return,
            'clock' => $clock,
            'tz' => $clock->businessTimezone($branch),
            'canIndex' => (bool) $user->can('tenant.sales-returns.index'),
        ]);
    }

    // ═══════════ W-B SEPARATE SCREENS — the SAME tenant sales-return pages (tenant/sales-returns/*), Edge data + routes ═══════════
    // The Online POS embeds /sales-returns/create?embed=1 in its Return modal and links the list/detail; the Branch Server
    // renders the SAME Blade views from the local DB (local sales + mirrored Online sales from the returnable cache), with
    // `$posRuntime` so links/forms resolve to edge.local.* routes. EdgeLocalReturnService stays the ONLY posting authority.

    private function runtime(Request $request): \App\Support\Pos\PosRuntime
    {
        return app(\App\Services\Edge\EdgePosRuntimeFactory::class)->make($request);
    }

    /** Online SalesReturnController@create → tenant.sales-returns.create (sale picker + the chosen sale's return grid). */
    public function createPage(Request $request, \App\Services\Sales\SalesReturnService $salesReturns): \Illuminate\Contracts\View\View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.sales-returns.create'), 403, 'Returning a sale needs the Sales Return permission (tenant.sales-returns.create).');
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $salesOrder = null;
        if ($request->filled('sales_order_id')) {
            $scope = app(\App\Services\Security\UserDataScope::class);
            $salesOrder = \App\Models\Tenant\SalesOrder::on('tenant')->with([
                'branch', 'terminal', 'customer', 'createdBy', 'shift', 'restaurantTable', 'restaurantWaiter', 'payments.method',
                'lines' => fn ($q) => $q->where(fn ($l) => $l->whereNull('line_kind')->orWhereNotIn('line_kind', ['component', 'modifier'])),
                'lines.product.unit', 'lines.variant', 'lines.returnLines',
            ])
                ->where('branch_id', $branchId)
                ->whereIn('status', ['paid', 'partially_returned', \App\Services\Edge\EdgeReturnableSaleCacheService::SHADOW_STATUS])
                ->when($scope->isScoped($user), fn ($q) => $scope->applyToSales($q, $user))
                ->find((int) $request->input('sales_order_id'));
        }
        $defaultRefundMethod = null;
        if ($salesOrder) {
            $primary = $salesOrder->payments->sortByDesc('amount')->first();
            $type = (string) ($primary?->method?->method_type ?? $primary?->payment_method ?? '');
            $defaultRefundMethod = match ($type) { 'cash' => 'cash', 'card' => 'card', 'bank_transfer' => 'bank_transfer', '' => null, default => 'other' };
        }

        return view('tenant.sales-returns.create', [
            'salesOrder' => $salesOrder,
            'returnAllocations' => $salesOrder
                ? $salesOrder->lines->mapWithKeys(fn ($line) => [$line->id => $salesReturns->originalLineAllocation($salesOrder, $line)])
                : collect(),
            'outstandingDelivery' => $salesOrder
                ? max(round((float) $salesOrder->delivery_charge_amount - (float) $salesOrder->returns()->whereIn('status', ['posted', 'cloud_mirror'])->sum('delivery_charge_amount'), 2), 0)
                : 0.0,
            'defaultRefundMethod' => $defaultRefundMethod,
            'needsManagerApproval' => $salesOrder
                && ($salesOrder->branch?->sales_return_approval_mode ?? \App\Models\Tenant\Branch::SALES_RETURN_AUTO_APPROVE) !== \App\Models\Tenant\Branch::SALES_RETURN_AUTO_APPROVE,
            'posRuntime' => $this->runtime($request),
        ]);
    }

    /**
     * Online SalesReturnController@store (the create form's POST): same fields; EdgeLocalReturnService::processReturn posts it
     * (cash refund out of the selected till, branch approval mode, offline refund-method policy, returnable-cache freshness);
     * success → the return's detail page with the status flash, refusal → back with the error (Online behaviour).
     */
    public function storeFromPage(Request $request): \Illuminate\Http\RedirectResponse
    {
        if (! (bool) $request->user('tenant')?->can('tenant.sales-returns.store')) {
            abort(403, 'You are not allowed to post sales returns.');
        }
        $data = $request->validate([
            'sales_order_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_method' => ['required', 'string', 'in:cash,bank_transfer,card,other'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'manager_approval_id' => ['nullable', 'integer'],
        ]);
        if (collect($data['lines'])->sum(fn ($l) => (float) ($l['quantity'] ?? 0)) <= 0) {
            return back()->withErrors(['return' => 'Enter a return quantity for at least one item.'])->withInput();
        }
        $terminalId = (int) $request->session()->get(self::TERMINAL_SESSION_KEY, 0);
        if ($terminalId <= 0) {
            return back()->withErrors(['return' => 'Select a terminal on the POS before posting a return.'])->withInput();
        }
        try {
            $view = $this->returns->processReturn(
                (int) $data['sales_order_id'], array_values(array_filter($data['lines'], fn ($l) => (float) ($l['quantity'] ?? 0) > 0)),
                $data['reason'] ?? null, (string) $data['refund_method'],
                isset($data['refund_amount']) ? (float) $data['refund_amount'] : null, $request->user('tenant'), $terminalId,
                isset($data['manager_approval_id']) ? (int) $data['manager_approval_id'] : null
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors() ?: ['return' => $e->getMessage()])->withInput();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput();
        }
        $runtime = $this->runtime($request);
        $target = $request->user('tenant')->can('tenant.sales-returns.show')
            ? $runtime->route('salesReturnShowPage', ['salesReturn' => $view['id']])
            : $runtime->route('salesReturnIndexPage');

        return redirect((string) $target)->with('status', 'Sales return posted.');
    }

    /** Online SalesReturnController@index → tenant.sales-returns.index (Today / Yesterday / range on the return date). */
    public function indexPage(Request $request): \Illuminate\Contracts\View\View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.sales-returns.index'), 403, 'Viewing sales returns needs the Sales Returns permission (tenant.sales-returns.index).');
        $filters = $request->validate([
            'range' => ['nullable', 'in:today,yesterday'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $branch = \App\Models\Tenant\Branch::on('tenant')->find((int) $this->context->requireCurrent()->branch_id);
        $clock = app(\App\Support\TenantClock::class);
        $today = $clock->now($clock->businessTimezone($branch))->toDateString();
        [$from, $to] = match ($filters['range'] ?? null) {
            'today' => [$today, $today],
            'yesterday' => [($y = \Illuminate\Support\Carbon::parse($today)->subDay()->toDateString()), $y],
            default => [$filters['date_from'] ?? null, $filters['date_to'] ?? null],
        };
        $returns = $this->returns->listReturns($user, ['date_from' => $from, 'date_to' => $to]);
        collect($returns->items())->each(fn ($r) => $r->loadMissing('branch'));

        return view('tenant.sales-returns.index', [
            'returns' => $returns,
            'dateFrom' => $from,
            'dateTo' => $to,
            'posRuntime' => $this->runtime($request),
        ]);
    }

    /** Online SalesReturnController@show → tenant.sales-returns.show (same scope and branch fence as the list). */
    public function showPage(Request $request, int $salesReturn): \Illuminate\Contracts\View\View
    {
        $user = $request->user('tenant');
        abort_unless((bool) $user?->can('tenant.sales-returns.show'), 403, 'Viewing a sales return needs the Sales Return detail permission (tenant.sales-returns.show).');
        try {
            $return = $this->returns->returnDetail($salesReturn, $user);
        } catch (ValidationException $e) {
            abort(404, 'No such sales return on this branch server.');
        }
        $return->loadMissing(['branch', 'lines.product', 'lines.variant', 'lines.orderLine']);

        return view('tenant.sales-returns.show', ['salesReturn' => $return, 'posRuntime' => $this->runtime($request)]);
    }

    /**
     * Online's return permission, resolved from the synced effective permission set (fail closed). W-B canonical contract
     * (§3.2): the refusal is the shared `{message, permission}` 403 (denyUnlessCan), never a bare abort(403) page.
     */
    private function denyUnlessMayReturn(Request $request): ?JsonResponse
    {
        return $this->denyUnlessCan('tenant.sales-returns.store', 'You are not allowed to post sales returns.');
    }
}
