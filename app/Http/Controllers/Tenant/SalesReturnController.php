<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
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
            $scope->applyToReturns($query, $user);   // RETURN-NO-BILL-1: one without a bill by its own branch / counter
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
        // RETURN-NO-BILL-1: the same screen, second way — "Without bill". Its item search answers here too (JSON),
        // so whoever can open the return screen can do it (owner: no separate permission).
        if ($request->input('mode') === 'no_bill') {
            abort_unless(\App\Support\TenantFeatures::on(\App\Support\TenantFeatures::RETURN_NO_BILL), 404);
            if ($request->filled('lookup') && $request->expectsJson()) {
                return response()->json(['items' => $this->noBillLookup((string) $request->input('lookup'), (int) $request->input('branch_id'))]);
            }

            return $this->createNoBill($request);
        }

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

        // RETURN-MANAGER-APPROVAL-1: decides whether the screen asks for a PIN. The controller
        // enforces it regardless — this only tells the page whether to offer the modal.
        $needsManagerApproval = $salesOrder
            && ($salesOrder->branch?->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE)
                !== Branch::SALES_RETURN_AUTO_APPROVE;

        return view('tenant.sales-returns.create', compact(
            'salesOrder', 'returnAllocations', 'outstandingDelivery', 'defaultRefundMethod', 'needsManagerApproval'
        ));
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
        if ($request->input('mode') === 'no_bill') {
            return $this->storeNoBill($request, $salesReturnService);
        }

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
            // RETURN-RESTOCK-1: back on the shelf (1, the default) or thrown away (0)
            'lines.*.restock'             => ['nullable', 'boolean'],
            // RETURN-MANAGER-APPROVAL-1: present only when the branch asks for it. Validated as an
            // existing row so a typed id cannot reach the lookup below and 500.
            'manager_approval_id'         => ['nullable', 'integer', 'exists:manager_approvals,id'],
            // REFUND-SHIFT-1: the counter paying the refund (the POS passes the one it is on).
            'terminal_id'                 => ['nullable', 'integer'],
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

        // RETURN-MANAGER-APPROVAL-1 — a branch may require a manager before money goes back.
        //
        // Posting a return hands cash over, puts stock back on the shelf and writes to the ledger,
        // yet it was the one POS action with no approval at all — cancelling a single item already
        // needed one. Same machinery cancellations use, with its own action_type so an approval for
        // one can never authorise the other.
        //
        // The approval is bound to the REFUND AMOUNT, which is the number the manager actually
        // reads before typing the PIN. That one binding closes both doors, because
        // SalesReturnService independently refuses any refund_amount that does not equal the total
        // it computes from the lines:
        //   - approve 100, then post lines worth 10,000  -> the service rejects the mismatch
        //   - approve 100, then post 10,000              -> consume() rejects the changed payload
        // So the amount is required here; without it the service skips its own check and the
        // binding would guard nothing.
        $needsManager = ($salesOrder->branch?->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE)
            !== Branch::SALES_RETURN_AUTO_APPROVE;

        if ($needsManager) {
            if (! isset($data['refund_amount']) || $data['refund_amount'] === null) {
                return back()->withErrors([
                    'refund_amount' => 'Enter the refund amount — a manager-approved return is approved for a specific figure.',
                ])->withInput();
            }
            if (empty($data['manager_approval_id'])) {
                return back()->withErrors([
                    'manager_approval_id' => 'Manager approval is required to post a return at this branch.',
                ])->withInput();
            }

            $approval = \App\Models\Tenant\ManagerApproval::find($data['manager_approval_id']);
            if (! $approval) {
                return back()->withErrors(['manager_approval_id' => 'Manager approval was not found.'])->withInput();
            }

            try {
                app(\App\Services\Sales\ManagerApprovalService::class)->consume(
                    $approval,
                    'sales_return',
                    (int) auth('tenant')->id(),
                    [
                        'sales_order_id' => (int) $salesOrder->id,
                        'branch_id'      => (int) $salesOrder->branch_id,
                        'refund_method'  => (string) $data['refund_method'],
                        'refund_amount'  => round((float) $data['refund_amount'], 2),
                    ]
                );
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['manager_approval_id' => $exception->getMessage()])->withInput();
            }
        }

        try {
            $salesReturn = $salesReturnService->processReturn(
                salesOrder:   $salesOrder,
                lines:        $data['lines'],
                reason:       $data['reason'] ?? null,
                refundMethod: $data['refund_method'] ?? null,
                refundAmount: isset($data['refund_amount']) ? (float) $data['refund_amount'] : null,
                userId:       auth('tenant')->id(),
                refundShiftId: $this->refundShiftId((int) $salesOrder->branch_id, $data['terminal_id'] ?? null),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput();
        }

        return redirect(url('/sales-returns/' . $salesReturn->id))->with('status', 'Sales return posted.');
    }

    /** RETURN-NO-BILL-1: the "Without bill" way — pick the branch, then the items. */
    private function createNoBill(Request $request)
    {
        $user = auth('tenant')->user();
        $assigned = $user?->branches()->pluck('branches.id') ?? collect();
        $branches = Branch::query()->where('status', 'active')
            ->when($assigned->isNotEmpty(), fn ($q) => $q->whereIn('id', $assigned))
            ->orderBy('name')->get(['id', 'name', 'sales_return_approval_mode']);

        // The counter the POS is on decides the branch; else the user's own branch; else the first.
        $terminalBranch = $request->filled('terminal_id')
            ? \App\Models\Tenant\Terminal::query()->whereKey((int) $request->input('terminal_id'))->value('branch_id')
            : null;
        $branchId = (int) (old('branch_id') ?: $terminalBranch ?: $user?->default_branch_id ?: $branches->first()?->id);
        if (! $branches->contains('id', $branchId)) {
            $branchId = (int) $branches->first()?->id;
        }

        return view('tenant.sales-returns.create', [
            'mode'                 => 'no_bill',
            'salesOrder'           => null,
            'returnAllocations'    => collect(),
            'outstandingDelivery'  => 0.0,
            'defaultRefundMethod'  => 'cash',
            'needsManagerApproval' => false,
            'noBillBranches'       => $branches,
            'noBillBranchId'       => $branchId,
            'noBillApproval'       => $branches->mapWithKeys(fn ($b) => [(string) $b->id => ($b->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE) !== Branch::SALES_RETURN_AUTO_APPROVE]),
        ]);
    }

    /**
     * RETURN-NO-BILL-1: items for the "Without bill" search — what it is sold in today (its unit, and its packs where
     * packs are on) at today's price in this branch, and its batches here (to put it back into). Sellable items only.
     *
     * @return list<array<string, mixed>>
     */
    private function noBillLookup(string $q, int $branchId): array
    {
        $q = trim($q);
        if ($q === '' || ! $branchId || ! $this->userCanAccessBranch($branchId)) {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $products = \App\Models\Tenant\Product::query()
            ->with(['unit', 'variants', 'packUnits'])
            ->where('is_sellable', true)
            ->where(function ($w) use ($q, $like) {
                $w->where('name', 'like', $like)->orWhere('sku', $q)->orWhere('generic_name', 'like', $like)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $q));
            })
            ->orderBy('name')->limit(12)->get();

        $pricing = app(\App\Services\Sales\SalePricingService::class);
        $packsOn = \App\Support\PackUnits::enabled();
        $out = [];
        foreach ($products as $product) {
            // One variant (most items) = its default one, where its stock and batches sit; several = each its own row.
            $many = $product->variants->count() > 1;
            $variants = $many ? $product->variants : collect([$product->variants->firstWhere('is_default', true) ?? $product->variants->first()]);
            foreach ($variants as $variant) {
                $base = round($pricing->resolveSellingPrice($product, $variant, $branchId, null), 2);
                $units = [['id' => null, 'name' => $product->unit?->name ?: 'Unit', 'factor' => 1, 'price' => $base]];
                if ($packsOn) {
                    foreach ($product->packUnits->filter(fn ($p) => $p->can_sell && (int) $p->factor > 1)->sortByDesc('factor') as $pack) {
                        $units[] = ['id' => (int) $pack->id, 'name' => $pack->name, 'factor' => (int) $pack->factor,
                            'price' => round($pack->sale_price !== null ? (float) $pack->sale_price : $pack->factor * $base, 2)];
                    }
                }
                $batches = \App\Models\Tenant\InventoryBatch::query()
                    ->where('branch_id', $branchId)->where('product_id', $product->id)
                    ->where('product_variant_id', $variant?->id)
                    ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->limit(20)
                    ->get(['id', 'batch_no', 'expiry_date'])
                    ->map(fn ($b) => ['id' => (int) $b->id, 'no' => $b->batch_no, 'exp' => $b->expiry_date?->format('m/y')])
                    ->values()->all();
                $out[] = [
                    'product_id' => (int) $product->id,
                    'variant_id' => $variant?->id ? (int) $variant->id : null,
                    'name'       => $product->name . ($many && $variant ? ' — ' . $variant->name : ''),
                    'units'      => $units,
                    'tracked'    => (bool) $product->is_stock_tracked,
                    'has_expiry' => (bool) $product->has_expiry,
                    'shelf'      => ! $product->is_perishable,
                    'loose'      => $product->unit && $product->unit->unit_type !== 'quantity',
                    // the item's own tax, added on top exactly as SalePricingService does, so the page shows the
                    // refund the server will post
                    'tax'        => $product->is_taxable ? (float) $product->tax_rate_percent : 0.0,
                    'batches'    => $batches,
                ];
            }
        }

        return $out;
    }

    /** RETURN-NO-BILL-1: post a return without a bill. */
    private function storeNoBill(Request $request, SalesReturnService $salesReturnService)
    {
        // Master switch "Return without a bill" — off for every tenant until switched on.
        abort_unless(\App\Support\TenantFeatures::on(\App\Support\TenantFeatures::RETURN_NO_BILL), 404);

        $data = $request->validate([
            'branch_id'                  => ['required', 'integer', 'exists:branches,id'],
            'reason'                     => ['required', 'string', 'max:500'],
            'refund_method'              => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'other'])],
            'refund_amount'              => ['nullable', 'numeric', 'min:0'],
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.product_id'         => ['required', 'integer', 'exists:products,id'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.pack_unit_id'       => ['nullable', 'integer'],
            'lines.*.quantity'           => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price'         => ['required', 'numeric', 'min:0'],
            'lines.*.restock'            => ['nullable', 'boolean'],
            'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.batch_no'           => ['nullable', 'string', 'max:100'],
            'lines.*.expiry'             => ['nullable', 'string', 'max:10'],
            'manager_approval_id'        => ['nullable', 'integer', 'exists:manager_approvals,id'],
            'terminal_id'                => ['nullable', 'integer'],
        ], [
            'reason.required' => 'Write why the item is coming back — a return without a bill needs a reason.',
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        app(\App\Services\Edge\BranchOperatingModeService::class)->assertSaleMutationAllowed($branch);
        if (! $this->userCanAccessBranch((int) $branch->id)) {
            return back()->withErrors(['return' => 'That branch is not one you are assigned to.'])->withInput();
        }

        // The branch's manager-PIN setting applies exactly as for a return with a bill, bound to the refund amount.
        if (($branch->sales_return_approval_mode ?? Branch::SALES_RETURN_AUTO_APPROVE) !== Branch::SALES_RETURN_AUTO_APPROVE) {
            if (! isset($data['refund_amount']) || empty($data['manager_approval_id'])) {
                return back()->withErrors(['manager_approval_id' => 'Manager approval is required to post a return at this branch.'])->withInput();
            }
            try {
                app(\App\Services\Sales\ManagerApprovalService::class)->consume(
                    \App\Models\Tenant\ManagerApproval::findOrFail($data['manager_approval_id']),
                    'sales_return',
                    (int) auth('tenant')->id(),
                    [
                        'sales_order_id' => null,
                        'branch_id'      => (int) $branch->id,
                        'refund_method'  => (string) $data['refund_method'],
                        'refund_amount'  => round((float) $data['refund_amount'], 2),
                    ]
                );
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['manager_approval_id' => $exception->getMessage()])->withInput();
            }
        }

        try {
            $salesReturn = $salesReturnService->processNoBillReturn(
                branch:        $branch,
                lines:         $data['lines'],
                reason:        $data['reason'],
                refundMethod:  $data['refund_method'],
                refundAmount:  isset($data['refund_amount']) ? (float) $data['refund_amount'] : null,
                userId:        (int) auth('tenant')->id(),
                refundShiftId: $this->refundShiftId((int) $branch->id, $data['terminal_id'] ?? null),
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()])->withInput();
        }

        return redirect(url('/sales-returns/' . $salesReturn->id))->with('status', 'Return without a bill posted.');
    }

    /**
     * REFUND-SHIFT-1 — which open shift pays this refund (its drawer gives the cash), in this order:
     *   1. the counter the POS is on (passed as terminal_id), when it is this branch's and its shift is open;
     *   2. the user's own counter (default terminal), same rule;
     *   3. the branch's only open shift, or else the user's own open shift in the branch.
     * Null = the service falls back to the sale's own shift while it is still open (today's rule), else none.
     */
    private function refundShiftId(int $branchId, mixed $terminalId): ?int
    {
        $shifts = app(\App\Services\Sales\ShiftService::class);
        $user = auth('tenant')->user();
        foreach (array_filter([(int) $terminalId, (int) ($user?->default_terminal_id ?? 0)]) as $id) {
            $terminal = \App\Models\Tenant\Terminal::query()->whereKey($id)->where('branch_id', $branchId)->first();
            if ($terminal && ($shift = $shifts->activeShiftForTerminal($terminal))) {
                return (int) $shift->id;
            }
        }
        $open = \App\Models\Tenant\Shift::query()->where('branch_id', $branchId)->where('status', 'open')->get(['id', 'opened_by_user_id']);
        if ($open->count() === 1) {
            return (int) $open->first()->id;
        }
        $mine = $open->where('opened_by_user_id', $user?->id)->sortByDesc('id')->first();

        return $mine ? (int) $mine->id : null;
    }

    public function show(SalesReturn $salesReturn)
    {
        $salesReturn->load(['order', 'branch', 'lines.product', 'lines.variant', 'lines.orderLine', 'createdBy']);

        return view('tenant.sales-returns.show', compact('salesReturn'));
    }
}
