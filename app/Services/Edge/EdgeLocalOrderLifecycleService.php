<?php

namespace App\Services\Edge;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use App\Support\EdgeUserAuthz;
use Illuminate\Validation\ValidationException;

/**
 * W3 (Team 3) — order-lifecycle READS for the cashier page: the Recent / Completed Orders list (Online
 * POSController::recentSales) and the Held Orders list with its type filter (Online HeldSaleController::ajaxList).
 *
 * Same rules as Online: the bound branch only; only the order types this operator may run (an optional filter narrows,
 * never widens); UserDataScope (branch / terminal / order-type assignments) applied to the query; newest first, 50 rows.
 * Read-only — nothing here mutates a sale, a print job or the outbox.
 */
class EdgeLocalOrderLifecycleService
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly UserDataScope $scope,
    ) {
    }

    /** Online POSController::recentSales — last 50 non-held sales of the branch, scoped like Online. */
    public function recentSales(User $user, ?string $orderType = null): array
    {
        $branchId = $this->guard($user);
        $allowed = $user->effectiveAllowedOrderTypes() ?? [];
        $filter = (string) ($orderType ?? '');

        $query = SalesOrder::on('tenant')->with(['restaurantWaiter:id,name', 'restaurantTable:id,table_no', 'deliveryChannel:id,name,type', 'deliveryRider:id,name'])
            ->where('branch_id', $branchId)
            ->where('status', '!=', 'held')
            ->whereNotNull('sale_no')
            ->when($allowed, fn ($q) => $q->whereIn('order_type', $allowed))
            ->when($filter !== '' && in_array($filter, $allowed, true), fn ($q) => $q->where('order_type', $filter));
        $this->scope->applyToSales($query, $user);
        $sales = $query->orderByDesc('id')->limit(50)->get();

        // Print state per sale (Edge truth = its print_jobs rows; Online reads direct_pay_print_state).
        $jobs = PrintJob::on('tenant')->whereIn('reference_id', $sales->pluck('id'))
            ->whereIn('document_type', ['receipt', 'kot', 'reminder'])->orderBy('id')
            ->get(['id', 'reference_id', 'document_type', 'print_status'])->groupBy('reference_id');

        return $sales->map(function (SalesOrder $s) use ($jobs) {
            $mine = $jobs->get($s->id, collect());
            $last = fn (string $type) => $mine->where('document_type', $type)->last()?->print_status;
            $pending = $mine->contains(fn ($j) => in_array($j->print_status, ['queued', 'failed', 'pending', 'pending_retry'], true));

            return [
                'id' => (int) $s->id,
                'sale_no' => $s->sale_no,
                'sale_uuid' => $s->sale_uuid,
                'order_type' => $s->order_type,
                'status' => $s->status,
                'payment_status' => $s->payment_status,
                'customer' => $s->customer_name ?: 'Walk-in',
                'delivery_channel' => $s->deliveryChannel?->name,
                'delivery_channel_type' => $s->deliveryChannel?->type,
                'delivery_rider' => $s->deliveryRider?->name,
                'waiter' => $s->restaurantWaiter?->name,
                'vehicle_number' => $s->vehicle_number,
                'table' => $s->restaurantTable?->table_no,
                'total' => number_format((float) $s->grand_total, 2, '.', ''),
                'grand_total' => (float) $s->grand_total,
                'time' => ($s->sale_date ?? $s->created_at)?->toIso8601String(),
                'edge_sync_state' => $s->edge_sync_state,
                'printing' => $mine->isEmpty() ? null : [
                    'kot' => $last('kot'), 'receipt' => $last('receipt'), 'reminder' => $last('reminder'),
                    'resume_available' => $pending,
                ],
            ];
        })->values()->all();
    }

    /** Online HeldSaleController::ajaxList scoping, for the Held Orders modal (type filter + UserDataScope). */
    public function heldSalesQuery(User $user, ?string $orderType = null)
    {
        $branchId = $this->guard($user);
        $allowed = $user->effectiveAllowedOrderTypes() ?? [];
        $filter = (string) ($orderType ?? '');
        $query = SalesOrder::on('tenant')->with(['restaurantTable:id,table_no,name', 'restaurantWaiter:id,name', 'lines:id,sales_order_id,quantity'])
            ->where('branch_id', $branchId)->where('status', 'held')
            ->when($allowed, fn ($q) => $q->whereIn('order_type', $allowed))
            ->when($filter !== '' && in_array($filter, $allowed, true), fn ($q) => $q->where('order_type', $filter));
        $this->scope->applyToSales($query, $user);

        return $query;
    }

    private function guard(User $user): int
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $auth = auth('tenant')->user();
        if (! $auth || (int) $auth->id !== (int) $user->id) {
            throw ValidationException::withMessages(['user' => 'This list requires an authenticated Edge cashier session.']);
        }
        if (! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            throw ValidationException::withMessages(['user' => 'This user is not authorized on this Branch Server.']);
        }

        return $branchId;
    }
}
