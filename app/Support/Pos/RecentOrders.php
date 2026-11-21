<?php

namespace App\Support\Pos;

use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use App\Support\TenantClock;
use Illuminate\Database\Eloquent\Builder;

/**
 * ONE Recent Orders list rule for BOTH runtimes — Online `POSController::recentSales` and the Branch Server twin
 * `EdgeLocalOrderLifecycleService::recentSales` call this class; neither owns a rule of its own.
 *
 * Owner decision, Phase 4 gap C (18 Oct 2026): "Standby Recent Orders must follow the Online cashier list rule … Do not
 * introduce an Edge-only Recent Orders rule." So inclusion, ordering, the limit and every field the shared view
 * (resources/views/tenant/pos/index.blade.php `loadRecentSales` / `posOrderMeta`) consumes live HERE. A runtime only
 * supplies what is genuinely runtime truth: its own print state, the Cloud status of a sale it mirrors, and the
 * ordering key it holds for the Cloud's sale id.
 *
 *   INCLUSION  every sale of the branch that is NOT held and carries a sale number — cancelled sales INCLUDED (the list
 *              is the branch's order history; what may be RETURNED is a different rule: the return flow's own guard) —
 *              narrowed to the order types the operator may run (an optional type filter narrows, never widens) and to
 *              the operator's UserDataScope.
 *   ORDER      newest first by the CLOUD's sale id (Online: `sales_orders.id`; Edge: the mirrored Cloud id / the
 *              acknowledged Cloud id of a local sale, a sale the Cloud has not numbered yet sorting first).
 *   LIMIT      50.
 *   ROW        id · sale_no · order_type · status · payment_status · customer (customer_name, else the customer's name,
 *              else "Walk-in") · delivery_channel / delivery_channel_type / delivery_rider · waiter · vehicle_number ·
 *              table (restaurantTable.table_no — the label is resolved through the table row by identity on both
 *              runtimes, the Edge's row being the bootstrapped copy of the Cloud's) · total (number_format 2) ·
 *              time (TenantClock 'd M, h:i A') · ago · printing {kot, receipt, reminder, resume_available} | null.
 */
final class RecentOrders
{
    public const LIMIT = 50;

    /** The only status the history list leaves out; every other status (incl. cancelled) is listed. */
    public const EXCLUDED_STATUSES = ['held'];

    /** The relations a row needs (eager-load them on the query). */
    public const RELATIONS = ['customer', 'deliveryChannel', 'deliveryRider', 'branch', 'shift', 'restaurantWaiter', 'restaurantTable'];

    /**
     * Apply the inclusion rule. Column names are qualified with $table so a runtime may join other tables
     * (the Edge joins its mirror map for the Cloud id) without ambiguity.
     */
    public static function applyInclusion(Builder $query, ?User $user, ?int $branchId, ?string $orderTypeFilter, string $table = 'sales_orders'): Builder
    {
        $allowed = $user?->effectiveAllowedOrderTypes() ?? [];
        $filter = (string) ($orderTypeFilter ?? '');

        $query->whereNotIn("{$table}.status", self::EXCLUDED_STATUSES)
            ->whereNotNull("{$table}.sale_no")
            ->when($branchId, fn ($q) => $q->where("{$table}.branch_id", $branchId))
            ->when($allowed, fn ($q) => $q->whereIn("{$table}.order_type", $allowed))
            ->when($filter !== '' && in_array($filter, $allowed, true), fn ($q) => $q->where("{$table}.order_type", $filter));
        app(UserDataScope::class)->applyToSales($query, $user, $table);

        return $query;
    }

    /**
     * The Online row for one sale.
     *
     * @param  array|null  $printing  the RUNTIME's print state for this sale ({kot, receipt, reminder, resume_available}) or null
     * @param  string|null  $status  presented status when it is not the row's own (a mirrored sale presents its CLOUD status)
     */
    public static function present(SalesOrder $s, ?array $printing, ?string $status = null): array
    {
        return [
            'id' => (int) $s->id,
            'sale_no' => $s->sale_no,
            'order_type' => $s->order_type,
            'status' => $status ?? $s->status,
            'payment_status' => $s->payment_status,
            'customer' => $s->customer_name ?: $s->customer?->name ?: 'Walk-in',
            // Both lists render order meta through one JS helper, so both must feed it the same keys: which channel
            // took the order, and who is carrying it.
            'delivery_channel' => $s->deliveryChannel?->name,
            'delivery_channel_type' => $s->deliveryChannel?->type,
            'delivery_rider' => $s->deliveryRider?->name,
            // Waiter (quick-sale + dine-in), vehicle (quick-sale), table (dine-in) for the list.
            'waiter' => $s->restaurantWaiter?->name,
            'vehicle_number' => $s->vehicle_number,
            'table' => $s->restaurantTable?->table_no,
            'total' => number_format((float) $s->grand_total, 2),
            'time' => app(TenantClock::class)->formatSale($s, 'd M, h:i A'),
            'ago' => optional($s->sale_date ?? $s->created_at)->diffForHumans(),
            'printing' => $printing,
        ];
    }

    /** Online's print state (sales_orders.direct_pay_print_state) in the shape the shared view consumes. */
    public static function directPayPrinting(SalesOrder $s): ?array
    {
        $printState = $s->direct_pay_print_state;
        if (! $printState) {
            return null;
        }
        $resume = in_array($printState['kot_status'] ?? null, ['pending', 'pending_retry'], true)
            || in_array($printState['receipt_status'] ?? null, ['pending', 'pending_retry'], true)
            || in_array($printState['reminder_status'] ?? null, ['pending_retry', 'awaiting_confirmation'], true);

        return [
            'kot' => $printState['kot_status'] ?? null,
            'receipt' => $printState['receipt_status'] ?? null,
            'reminder' => $printState['reminder_status'] ?? null,
            'resume_available' => $resume,
        ];
    }
}
