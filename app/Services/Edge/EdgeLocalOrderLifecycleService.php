<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use App\Support\EdgeUserAuthz;
use App\Support\Pos\RecentOrders;
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

    /**
     * Online POSController::recentSales — the SAME list rule (App\Support\Pos\RecentOrders, Phase 4 gap C): every non-held
     * sale with a sale number (cancelled included), the operator's order types + UserDataScope, newest 50, the Online row.
     *
     * What is runtime truth here, and only here:
     *   - ORDER KEY: Online orders by the Cloud's sale id. A mirrored Cloud sale (sales_orders.status = cloud_mirror) holds
     *     it in edge_returnable_sales.cloud_sales_order_id; a LOCAL sale the Cloud has acknowledged holds it in the sale
     *     envelope's ACK (edge_sync_outbox.ack_payload.sales_order_id); a local sale the Cloud has not numbered yet sorts
     *     first (it is newer than everything the Cloud has). So the Edge list is in Online's order, not local-id order.
     *   - STATUS of a mirrored sale = the Cloud's status (paid / partially_returned / cancelled …) — never the shadow
     *     marker `cloud_mirror`, which is a storage fact, not a presentation.
     *   - PRINT STATE = this appliance's print_jobs (Online reads its own direct_pay_print_state); a mirrored sale was
     *     printed by the Cloud's terminal, so it carries no resumable print state here.
     * Additive Edge-only keys (sale_uuid, grand_total, time_iso, edge_sync_state, origin) stay for the Edge callers.
     */
    public function recentSales(User $user, ?string $orderType = null): array
    {
        $branchId = $this->guard($user);

        $query = SalesOrder::on('tenant')->with(RecentOrders::RELATIONS)
            ->select('sales_orders.*')
            ->selectRaw("COALESCE(m.cloud_sales_order_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(o.ack_payload, '$.sales_order_id')) AS UNSIGNED)) AS edge_cloud_order_id")
            ->selectRaw('m.cloud_status AS edge_mirror_status')
            ->leftJoin('edge_returnable_sales as m', 'm.local_sales_order_id', '=', 'sales_orders.id')
            ->leftJoin('edge_sync_outbox as o', function ($join) {
                $join->on('o.sale_uuid', '=', 'sales_orders.sale_uuid')->where('o.state', '=', EdgeSyncOutbox::STATE_ACKNOWLEDGED);
            });
        RecentOrders::applyInclusion($query, $user, $branchId, $orderType);
        $sales = $query
            ->orderByRaw('(edge_cloud_order_id IS NULL) DESC, edge_cloud_order_id DESC, sales_orders.id DESC')
            ->limit(RecentOrders::LIMIT)
            ->get();

        // Print state per sale (Edge truth = its print_jobs rows; Online reads direct_pay_print_state).
        $jobs = PrintJob::on('tenant')->whereIn('reference_id', $sales->pluck('id'))
            ->whereIn('document_type', ['receipt', 'kot', 'reminder'])->orderBy('id')
            ->get(['id', 'reference_id', 'document_type', 'print_status'])->groupBy('reference_id');

        return $sales->map(function (SalesOrder $s) use ($jobs) {
            $mine = $jobs->get($s->id, collect());
            $last = fn (string $type) => $mine->where('document_type', $type)->last()?->print_status;
            $pending = $mine->contains(fn ($j) => in_array($j->print_status, ['queued', 'failed', 'pending', 'pending_retry'], true));
            $printing = $mine->isEmpty() ? null : [
                'kot' => $last('kot'), 'receipt' => $last('receipt'), 'reminder' => $last('reminder'),
                'resume_available' => $pending,
            ];
            $mirrored = (string) $s->status === EdgeReturnableSaleCacheService::SHADOW_STATUS;
            $row = RecentOrders::present($s, $printing, $mirrored && $s->edge_mirror_status ? (string) $s->edge_mirror_status : null);

            return $row + [
                'sale_uuid' => $s->sale_uuid,
                'grand_total' => (float) $s->grand_total,
                'time_iso' => ($s->sale_date ?? $s->created_at)?->toIso8601String(),
                'edge_sync_state' => $s->edge_sync_state,
                'origin' => $mirrored ? 'online' : 'local',
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
