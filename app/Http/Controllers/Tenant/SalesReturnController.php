<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesReturn;
use App\Services\Sales\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SalesReturn::with(['order', 'branch', 'createdBy'])
            ->orderByDesc('return_date')
            ->orderByDesc('id');

        // Same terminal/order-type scope the Sales Orders list uses — a returns list was the one
        // sales screen that showed EVERY terminal's rows to a terminal-scoped cashier. Applied to
        // the underlying order so a hand-edited URL cannot widen it.
        $scope = app(\App\Services\Security\UserDataScope::class);
        $user = auth('tenant')->user();
        if ($scope->isScoped($user)) {
            $query->whereHas('order', fn ($q) => $scope->applyToSales($q, $user));
        }

        // Date range on the RETURN date, in the branch's local day (Today / Yesterday / custom).
        [$from, $to] = $this->dateRange($request);
        if ($from && $to) {
            $tz = app(\App\Support\TenantClock::class)->displayTimezone($user, null);
            $query->whereBetween('return_date', [
                \Illuminate\Support\Carbon::parse($from . ' 00:00:00', $tz)->utc(),
                \Illuminate\Support\Carbon::parse($to . ' 23:59:59', $tz)->utc(),
            ]);
        }

        return view('tenant.sales-returns.index', [
            'returns'   => $query->paginate(15)->withQueryString(),
            'dateFrom'  => $from,
            'dateTo'    => $to,
        ]);
    }

    /**
     * Resolve the from/to date range from the request. The Today / Yesterday quick buttons post a
     * `range` shortcut; an explicit from/to wins. Dates are the branch's LOCAL calendar days.
     */
    private function dateRange(Request $request): array
    {
        $today = app(\App\Support\TenantClock::class)
            ->now(app(\App\Support\TenantClock::class)->displayTimezone(auth('tenant')->user(), null))
            ->toDateString();

        return match ($request->input('range')) {
            'today'     => [$today, $today],
            'yesterday' => [($y = \Illuminate\Support\Carbon::parse($today)->subDay()->toDateString()), $y],
            default     => [$request->input('date_from') ?: null, $request->input('date_to') ?: null],
        };
    }

    public function create(Request $request, SalesReturnService $salesReturnService)
    {
        $salesOrder = null;
        $scope = app(\App\Services\Security\UserDataScope::class);
        $user = auth('tenant')->user();

        if ($request->filled('sales_order_id')) {
            $salesOrder = SalesOrder::with([
                'branch', 'terminal', 'customer', 'createdBy', 'shift',
                'restaurantTable', 'restaurantWaiter',
                'payments.method',
                'lines' => fn ($query) => $query->where(function ($lineQuery) {
                    $lineQuery->whereNull('line_kind')
                        ->orWhereNotIn('line_kind', ['component', 'modifier']);
                }),
                // lines.product.unit → the Return Qty stepper matches how the item was sold
                // (whole units step by 1; weight/volume/length step by 0.001).
                'lines.product.unit', 'lines.variant', 'lines.returnLines',
            ])
                ->whereIn('status', ['paid', 'partially_returned'])
                // USER-DATA-SCOPE-1: a terminal/order-type-restricted operator cannot open a sale
                // outside his scope even by hand-editing sales_order_id (the search is scoped too).
                ->when($scope->isScoped($user), fn ($q) => $scope->applyToSales($q, $user))
                ->find($request->sales_order_id);

            // SALES-RETURN-UX-1: branch guard — users with explicit branch
            // assignments can only return sales of their branches.
            if ($salesOrder && ! $this->userCanAccessBranch($salesOrder->branch_id)) {
                return redirect(url('/sales-returns/create'))
                    ->withErrors(['return' => 'That sale belongs to a branch you are not assigned to.']);
            }
        }

        // RETURN-UX: default the refund method to how the customer originally paid (cash sale →
        // Cash pre-selected), so the cashier confirms rather than picks from scratch. Split payments
        // take the largest tender. The cashier can still override.
        $defaultRefundMethod = null;
        if ($salesOrder) {
            $primary = $salesOrder->payments->sortByDesc('amount')->first();
            $type = (string) ($primary?->method?->method_type ?? $primary?->payment_method ?? '');
            $defaultRefundMethod = match ($type) {
                'cash' => 'cash',
                'card' => 'card',
                'bank_transfer' => 'bank_transfer',
                '' => null,
                default => 'other',
            };
        }

        $returnAllocations = $salesOrder
            ? $salesOrder->lines->mapWithKeys(fn ($line) => [
                $line->id => $salesReturnService->originalLineAllocation($salesOrder, $line),
            ])
            : collect();

        // Delivery still refundable on this order — the charge less anything an earlier return
        // already gave back. The screen adds it to the preview only when the whole order comes
        // back, matching SalesReturnService, so the posted refund equals what the cashier saw.
        $outstandingDelivery = $salesOrder
            ? max(round((float) $salesOrder->delivery_charge_amount - (float) $salesOrder->returns()
                ->where('status', 'posted')->sum('delivery_charge_amount'), 2), 0)
            : 0.0;

        return view('tenant.sales-returns.create', compact('salesOrder', 'returnAllocations', 'outstandingDelivery', 'defaultRefundMethod'));
    }

    private function userCanAccessBranch(int $branchId): bool
    {
        $user = auth('tenant')->user();
        if (! $user) {
            return false;
        }
        $assigned = $user->branches()->pluck('branches.id');

        return $assigned->isEmpty() || $assigned->contains($branchId);
    }

    public function store(Request $request, SalesReturnService $salesReturnService)
    {
        $data = $request->validate([
            'sales_order_id'              => ['required', 'exists:sales_orders,id'],
            'reason'                      => ['nullable', 'string'],
            // REQUIRED, not nullable: without a method the GL credits 1500 Undeposited Funds and no
            // cash movement is written, so the refund silently never happens on the books.
            'refund_method'               => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'other'])],
            'refund_amount'               => ['nullable', 'numeric', 'min:0'],
            'lines'                       => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'exists:sales_order_lines,id'],
            // min:0 (not 0.001): the form posts EVERY line, and lines the cashier did not touch stay
            // at 0. Rejecting those made returning a single item impossible ("qty must be at least
            // 0.001"). Zero lines are simply skipped by SalesReturnService; the guard below keeps a
            // return with NOTHING selected from posting.
            'lines.*.quantity'            => ['required', 'numeric', 'min:0'],
        ]);

        if (collect($data['lines'])->sum(fn ($l) => (float) ($l['quantity'] ?? 0)) <= 0) {
            return back()->withErrors(['return' => 'Enter a return quantity for at least one item.'])->withInput();
        }

        $scope = app(\App\Services\Security\UserDataScope::class);
        $scopeUser = auth('tenant')->user();
        $salesOrder = SalesOrder::with(['branch', 'lines.product', 'lines.variant'])
            ->whereIn('status', ['paid', 'partially_returned'])
            // Same terminal/order-type fence as the search + create screen — a scoped operator
            // cannot post a return for a sale outside his terminals/order types.
            ->when($scope->isScoped($scopeUser), fn ($q) => $scope->applyToSales($q, $scopeUser))
            ->findOrFail($data['sales_order_id']);

        // BRANCH-OPERATING-MODE-1: an active Local POS branch handles returns on its Branch Server.
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(\App\Models\Tenant\Branch::findOrFail($salesOrder->branch_id));

        if (! $this->userCanAccessBranch($salesOrder->branch_id)) {
            return back()->withErrors(['return' => 'That sale belongs to a branch you are not assigned to.'])->withInput();
        }

        try {
            $salesReturn = $salesReturnService->processReturn(
                salesOrder:   $salesOrder,
                lines:        $data['lines'],
                reason:       $data['reason'] ?? null,
                refundMethod: $data['refund_method'] ?? null,
                refundAmount: isset($data['refund_amount']) ? (float) $data['refund_amount'] : null,
                userId:       auth('tenant')->id(),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput();
        }

        return redirect(url('/sales-returns/' . $salesReturn->id))->with('status', 'Sales return posted.');
    }

    public function show(SalesReturn $salesReturn)
    {
        $salesReturn->load(['order', 'branch', 'lines.product', 'lines.variant', 'lines.orderLine', 'createdBy']);

        return view('tenant.sales-returns.show', compact('salesReturn'));
    }
}
