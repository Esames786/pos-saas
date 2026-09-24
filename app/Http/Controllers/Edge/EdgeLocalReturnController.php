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
        $this->denyUnlessMayReturn($request);

        $user = $request->user('tenant');

        return response()->json([
            'sales' => $this->returns->search((string) $request->query('q', ''), 20, $user),
            // W4 R3.6: the Online Sales Returns list entry (shown only with its own permission).
            'can_view_list' => (bool) $user->can('tenant.sales-returns.index'),
            'list_url' => url('/edge/local/pos/sales-returns'),
        ]);
    }

    /** The return screen's data for one sale (what the Online create screen shows + the offline facts). */
    public function returnableSale(Request $request, int $sale): JsonResponse
    {
        $this->denyUnlessMayReturn($request);
        try {
            return response()->json($this->returns->returnable($sale, $request->user('tenant')));
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
    }

    /** Post a return. Business messages for every refusal; nothing is ever half-posted. */
    public function storeReturn(Request $request): JsonResponse
    {
        $this->denyUnlessMayReturn($request);
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
        $this->denyUnlessMayReturn($request);

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

    /** Online's return permission, resolved from the synced effective permission set (fail closed). */
    private function denyUnlessMayReturn(Request $request): void
    {
        $user = $request->user('tenant');
        if (! $user || ! $user->can('tenant.sales-returns.store')) {
            abort(403, 'You are not allowed to post sales returns.');
        }
    }
}
