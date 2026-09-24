<?php

namespace App\Services\Edge;

use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use App\Support\EdgeUserAuthz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * W3 (Team 3) — the TABLE operations the Online Table Workspace / session bar / standalone board offer, executed on the
 * Branch Server against Edge-local state: Request Bill, Move table, Merge sessions, Reattach a held bill whose session was
 * closed (HELD-SALE-DEAD-SESSION-1), the session detail view and the per-table Bill Preview document.
 *
 * Semantics are those of the Online controllers (RestaurantTableSessionController::billRequested / move / merge / show /
 * billPreview, HeldSaleController::reattachTable) — same status transitions, same refusal rules, same lock order:
 *   move   : session → both tables (id order) → locking read of the target's live sessions
 *   merge  : both sessions (id order) → both tables (id order) → live sessions per table → the source's held/draft sales
 *   reattach: shift (terminal) → table → locking read of live sessions → sale         (Online order; shift FIRST)
 * so no two restaurant mutations take the same rows in opposite order (the Edge canonical order is
 * shift → table/session → sale; merge/move never take a shift lock, exactly like Online).
 *
 * Edge-specific guards (stricter, never looser):
 *   - an active Edge-owned reservation (edge_local_table_reservations) makes a table NOT available for move / reattach
 *     (Online encodes a reservation in restaurant_tables.status = reserved, which its move already refuses);
 *   - move re-points only the session's OPEN checks (held/draft). A PAID sale on the Branch Server has already been
 *     frozen into its immutable sync envelope, so rewriting its table locally would make the appliance disagree with
 *     what the Cloud received — paid fiscal history stays where it was taken (the rule Online's merge already applies).
 *   - a tombstoned (inactive) table is never resurrected to available/occupied.
 *
 * Held sales stay LOCAL until settled (no outbox row, no envelope change): nothing here alters what reaches the Cloud
 * beyond the values the existing sale envelope already carries (the final table session at settle time).
 */
class EdgeLocalTableOperationsService
{
    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly ShiftService $shiftService,
    ) {
    }

    // ───────────────────────────── Request Bill (bill_requested) ─────────────────────────────

    /** Online RestaurantTableSessionController::billRequested — session + table → bill_requested. */
    public function requestBill(int $sessionId, User $user): RestaurantTableSession
    {
        $branchId = $this->guardMutation($user);

        return DB::connection('tenant')->transaction(function () use ($sessionId, $branchId) {
            $session = RestaurantTableSession::on('tenant')->where('id', $sessionId)->where('branch_id', $branchId)->lockForUpdate()->first();
            if (! $session || ! in_array($session->status, ['open', 'bill_requested'], true)) {
                throw new RuntimeException('Session is not open.');
            }
            $session->update(['status' => 'bill_requested']);
            RestaurantTable::on('tenant')->where('id', $session->restaurant_table_id)
                ->where('status', '!=', 'inactive')->update(['status' => 'bill_requested']);

            return $session->fresh(['table', 'waiter']);
        });
    }

    // ───────────────────────────── Move table ─────────────────────────────

    /** Online RestaurantTableSessionController::move — the live session (and its open checks) to an available table. */
    public function moveSession(int $sessionId, int $targetTableId, User $user): RestaurantTableSession
    {
        $branchId = $this->guardMutation($user);

        return DB::connection('tenant')->transaction(function () use ($sessionId, $targetTableId, $branchId) {
            $session = RestaurantTableSession::on('tenant')->where('id', $sessionId)->where('branch_id', $branchId)->lockForUpdate()->first();
            if (! $session || ! in_array($session->status, ['open', 'bill_requested'], true)) {
                throw new RuntimeException('Only open table sessions can be moved.');
            }
            if ((int) $session->restaurant_table_id === $targetTableId) {
                throw new RuntimeException('Please select a different target table.');
            }

            $ids = collect([(int) $session->restaurant_table_id, $targetTableId])->sort()->values()->all();
            $tables = RestaurantTable::on('tenant')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $tables->get((int) $session->restaurant_table_id);
            $target = $tables->get($targetTableId);
            if (! $target || (int) $target->branch_id !== $branchId) {
                throw new RuntimeException('Target table is not available.');
            }
            if (! $source || (int) $source->branch_id !== (int) $session->branch_id) {
                throw new RuntimeException('The source or target table is no longer valid.');
            }

            $targetHasSession = RestaurantTableSession::on('tenant')->where('restaurant_table_id', $target->id)
                ->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->exists();
            if ($targetHasSession || ! in_array($target->status, ['available', 'cleaning'], true)) {
                throw new RuntimeException('Target table is not available.');
            }
            if ($this->activeReservationLocked((int) $target->id)) {
                throw new RuntimeException('Target table is reserved — cancel or seat the reservation first.');
            }

            $session->update(['restaurant_table_id' => $target->id]);
            SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)
                ->whereIn('status', ['held', 'draft'])
                ->update(['restaurant_floor_id' => $target->restaurant_floor_id, 'restaurant_table_id' => $target->id]);

            if ($source->status !== 'inactive') {
                $source->update(['status' => 'available']);
            }
            $target->update(['status' => $session->status === 'bill_requested' ? 'bill_requested' : 'occupied']);

            return $session->fresh(['table', 'waiter']);
        }, 3);
    }

    // ───────────────────────────── Merge sessions ─────────────────────────────

    /**
     * Online RestaurantTableSessionController::merge — the source session's OPEN checks (held/draft) move onto the target
     * session (floor / table / session / waiter follow the target); paid history stays on the source; the source session
     * is cancelled with a note; bill_requested survives the merge; the source table frees.
     * Order identity is preserved: the checks keep their sale_uuid, lines, captured prices and KOT-sent quantities.
     */
    public function mergeSessions(int $sourceSessionId, int $targetSessionId, User $user): RestaurantTableSession
    {
        $branchId = $this->guardMutation($user);
        if ($sourceSessionId === $targetSessionId) {
            throw new RuntimeException('Source and target sessions cannot be the same.');
        }

        return DB::connection('tenant')->transaction(function () use ($sourceSessionId, $targetSessionId, $branchId, $user) {
            $sessions = RestaurantTableSession::on('tenant')->whereIn('id', [$sourceSessionId, $targetSessionId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $sessions->get($sourceSessionId);
            $target = $sessions->get($targetSessionId);
            if (! $source || (int) $source->branch_id !== $branchId || ! in_array($source->status, ['open', 'bill_requested'], true)) {
                throw new RuntimeException('Only open table sessions can be merged.');
            }
            if (! $target || (int) $target->branch_id !== $branchId || ! in_array($target->status, ['open', 'bill_requested'], true)) {
                throw new RuntimeException('One of the table sessions is no longer available for merge.');
            }

            $tableIds = collect([(int) $source->restaurant_table_id, (int) $target->restaurant_table_id])->sort()->values()->all();
            $tables = RestaurantTable::on('tenant')->whereIn('id', $tableIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sourceTable = $tables->get((int) $source->restaurant_table_id);
            $targetTable = $tables->get((int) $target->restaurant_table_id);
            if (! $sourceTable || ! $targetTable || (int) $sourceTable->branch_id !== (int) $targetTable->branch_id) {
                throw new RuntimeException('Source and destination tables are invalid.');
            }
            if ((int) $sourceTable->id === (int) $targetTable->id
                || ! in_array($sourceTable->status, ['occupied', 'bill_requested'], true)
                || ! in_array($targetTable->status, ['occupied', 'bill_requested'], true)) {
                throw new RuntimeException('One of the tables is no longer in an active mergeable state.');
            }

            $live = RestaurantTableSession::on('tenant')->whereIn('restaurant_table_id', [$sourceTable->id, $targetTable->id])
                ->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->get(['id', 'restaurant_table_id'])->groupBy('restaurant_table_id');
            $liveSource = $live->get($sourceTable->id, collect());
            $liveTarget = $live->get($targetTable->id, collect());
            if ($liveSource->count() !== 1 || (int) $liveSource->first()->id !== (int) $source->id) {
                throw new RuntimeException('The source table session changed. Refresh and try again.');
            }
            if ($liveTarget->count() !== 1 || (int) $liveTarget->first()->id !== (int) $target->id) {
                throw new RuntimeException('The destination table session changed. Refresh and try again.');
            }

            $active = SalesOrder::on('tenant')->where('restaurant_table_session_id', $source->id)
                ->whereIn('status', ['held', 'draft'])->lockForUpdate()->get();
            if ($active->isEmpty()) {
                throw new RuntimeException('The source table has no active held order to merge.');
            }

            // Paid fiscal history intentionally remains attached to the original session/table.
            SalesOrder::on('tenant')->whereIn('id', $active->pluck('id'))->update([
                'restaurant_floor_id' => $targetTable->restaurant_floor_id,
                'restaurant_table_id' => $targetTable->id,
                'restaurant_table_session_id' => $target->id,
                'restaurant_waiter_id' => $target->restaurant_waiter_id,
            ]);

            $billWasRequested = in_array('bill_requested', [$source->status, $target->status], true);
            $source->update([
                'status' => 'cancelled',
                'closed_at' => now(),
                'closed_by_user_id' => $user->id,
                'notes' => trim(($source->notes ? $source->notes . ' | ' : '') . 'Active check merged into session ' . $target->session_no . '; paid history retained.'),
            ]);
            $mergedStatus = $billWasRequested ? 'bill_requested' : 'open';
            if ($target->status !== $mergedStatus) {
                $target->update(['status' => $mergedStatus]);
            }
            if ($sourceTable->status !== 'inactive') {
                $sourceTable->update(['status' => 'available']);
            }
            $targetTable->update(['status' => $mergedStatus === 'bill_requested' ? 'bill_requested' : 'occupied']);

            return $target->fresh(['table', 'waiter']);
        }, 3);
    }

    // ───────────────────────────── Dead-session recovery (reattach-table) ─────────────────────────────

    /**
     * Online HeldSaleController::reattachTable — a HELD dine-in bill whose table session is closed/cancelled moves onto a
     * NEW session on a free table (never onto a live one; never resurrecting the dead session). The kitchen is not told
     * again. Lock order: shift (the operator's terminal) → table → live sessions → sale.
     */
    public function reattachTable(int $saleId, int $tableId, Terminal $terminal, User $user): array
    {
        $branchId = $this->guardMutation($user);

        $probe = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)->first();
        if (! $probe || $probe->status !== 'held') {
            throw ValidationException::withMessages(['sale' => 'Only an open (held) bill can be moved to another table.']);
        }
        if ($probe->order_type !== 'dine_in') {
            throw ValidationException::withMessages(['sale' => 'Only a dine-in bill belongs to a table.']);
        }
        $dead = $probe->restaurant_table_session_id ? RestaurantTableSession::on('tenant')->find((int) $probe->restaurant_table_session_id) : null;
        if ($dead && in_array($dead->status, ['open', 'bill_requested'], true)) {
            throw ValidationException::withMessages(['sale' => 'This bill is still on a live table — use Move on the table board instead.']);
        }

        return DB::connection('tenant')->transaction(function () use ($saleId, $tableId, $terminal, $branchId, $user, $dead) {
            $shift = $this->shiftService->lockOpenShiftForTerminal($terminal);
            $table = RestaurantTable::on('tenant')->where('id', $tableId)->where('branch_id', $branchId)
                ->where('status', '!=', 'inactive')->lockForUpdate()->first();
            if (! $table) {
                throw new RuntimeException('That table does not belong to this branch.');
            }
            $occupied = RestaurantTableSession::on('tenant')->where('restaurant_table_id', $table->id)
                ->whereIn('status', ['open', 'bill_requested'])->lockForUpdate()->exists();
            if ($occupied) {
                throw new RuntimeException('That table is occupied now. Refresh the list and pick another one.');
            }
            if ($this->activeReservationLocked((int) $table->id)) {
                throw new RuntimeException('That table is reserved. Pick another one.');
            }
            $sale = SalesOrder::on('tenant')->where('id', $saleId)->where('branch_id', $branchId)->lockForUpdate()->first();
            if (! $sale || $sale->status !== 'held') {
                throw new RuntimeException('This bill changed while you were looking at it. Refresh and try again.');
            }

            // A NEW session (the Edge canonical identity: one ULID → session_uuid + display number), never the dead one.
            $ulid = (string) Str::ulid();
            $session = new RestaurantTableSession([
                'session_no' => 'TS-' . $branchId . '-' . $ulid,
                'branch_id' => $branchId,
                'restaurant_table_id' => $table->id,
                'opened_by_user_id' => $user->id,
                'opened_shift_id' => $shift->id,
                'business_date' => $shift->business_date->toDateString(),
                'guest_count' => 1,
                'status' => 'open',
                'opened_at' => now(),
                'notes' => 'Bill ' . $sale->sale_no . ' moved here from a closed session (' . ($dead?->session_no ?? 'unknown') . ').',
            ]);
            $session->session_uuid = $ulid;
            $session->save();

            $sale->update([
                'restaurant_floor_id' => $table->restaurant_floor_id,
                'restaurant_table_id' => $table->id,
                'restaurant_table_session_id' => $session->id,
            ]);
            $table->update(['status' => 'occupied']);

            return ['sale' => $sale->fresh(), 'session' => $session->fresh(['table'])];
        });
    }

    /** The dead-session facts Online POSController@index computes for the deadSessionModal (null when the session is live). */
    public function deadSessionInfo(SalesOrder $sale): ?array
    {
        if (! $sale->restaurant_table_session_id || $sale->status !== 'held') {
            return null;
        }
        $session = RestaurantTableSession::on('tenant')->with(['table', 'closedBy'])->find((int) $sale->restaurant_table_session_id);
        if ($session && in_array($session->status, ['open', 'bill_requested'], true)) {
            return null;
        }
        $tableId = (int) ($session?->restaurant_table_id ?? 0);
        $canReopen = $tableId > 0
            && ! RestaurantTableSession::on('tenant')->where('restaurant_table_id', $tableId)->whereIn('status', ['open', 'bill_requested'])->exists()
            && ! EdgeTableReservation::on('tenant')->where('restaurant_table_id', $tableId)->where('status', EdgeTableReservation::STATUS_ACTIVE)->exists()
            && RestaurantTable::on('tenant')->where('id', $tableId)->where('status', '!=', 'inactive')->exists();

        return [
            'sale_id' => (int) $sale->id, 'sale_no' => $sale->sale_no, 'total' => (float) $sale->grand_total,
            'table_id' => $tableId ?: null, 'table_no' => $session?->table?->table_no, 'session_no' => $session?->session_no,
            'closed_by' => $session?->closedBy?->name, 'closed_at' => $session?->closed_at?->toIso8601String(),
            'can_reopen' => $canReopen,
        ];
    }

    // ───────────────────────────── Reads: session detail, bill preview, table sessions ─────────────────────────────

    /** Online RestaurantTableSessionController::show — the session card + every order on it (incl. paid / cancelled). */
    public function sessionDetail(int $sessionId, User $user): array
    {
        $branchId = $this->guardRead($user);
        $session = RestaurantTableSession::on('tenant')->with(['table.floor', 'waiter', 'openedBy', 'closedBy'])
            ->where('branch_id', $branchId)->find($sessionId);
        if (! $session) {
            throw new RuntimeException('No table session found.');
        }
        $orders = SalesOrder::on('tenant')->with(['lines'])->where('restaurant_table_session_id', $session->id)
            ->orderBy('id')->get();

        return [
            'session' => $this->sessionPayload($session),
            'orders' => $orders->map(fn (SalesOrder $s) => [
                'id' => (int) $s->id, 'sale_no' => $s->sale_no, 'status' => $s->status, 'is_draft' => (bool) $s->is_draft,
                'order_type' => $s->order_type, 'grand_total' => (float) $s->grand_total, 'paid_amount' => (float) $s->paid_amount,
                'customer_name' => $s->customer_name, 'created_at' => $s->created_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
                'items' => $s->lines->where('line_kind', '!=', 'component')->map(fn ($l) => [
                    'name' => $l->product_name, 'quantity' => (float) $l->quantity, 'line_total' => (float) $l->line_total,
                    'kot_sent_quantity' => (float) $l->kot_sent_quantity,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Per-table Bill Preview (canonical TABLE-BILL-PREVIEW-PARITY-1 / BILL-PREVIEW-WRONG-PRINT-1, 14 Sep): the OPEN rounds
     * (held checks) are the bill; paid rounds are listed as "Previously paid" and never re-added. Returns the structured
     * document data (rounds / previously paid / totals / held_sale_ids — the print target is the HELD ids) plus the SAME
     * receipt document Online renders (tenant.printing.documents.receipt over a transient, never-saved SalesOrder).
     */
    public function billPreview(int $sessionId, User $user): array
    {
        $branchId = $this->guardRead($user);
        $session = RestaurantTableSession::on('tenant')->with(['branch', 'table.floor', 'waiter'])->where('branch_id', $branchId)->find($sessionId);
        if (! $session) {
            throw new RuntimeException('No table session found.');
        }
        $sales = SalesOrder::on('tenant')->with(['lines.product', 'lines.variant', 'payments.method'])
            ->where('restaurant_table_session_id', $session->id)->whereIn('status', ['held', 'paid'])->orderBy('id')->get();
        $session->setRelation('salesOrders', $sales);
        $held = $sales->where('status', 'held')->values();
        $paid = $sales->where('status', 'paid')->values();
        $sum = fn (string $col) => round((float) $held->sum(fn ($s) => (float) ($s->{$col} ?? 0)), 2);

        $layout = \App\Models\Tenant\ReceiptLayoutSetting::on('tenant')->where('document_type', 'receipt')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $session->branch_id))
            ->orderByDesc('branch_id')->first();

        return [
            'session' => $this->sessionPayload($session),
            'rounds' => $held->map(fn (SalesOrder $s) => [
                'id' => (int) $s->id, 'sale_no' => $s->sale_no, 'is_draft' => (bool) $s->is_draft,
                'created_at' => $s->created_at?->toIso8601String(),
                'lines' => $s->lines->map(fn ($l) => [
                    'id' => (int) $l->id, 'name' => $l->product_name, 'variant_name' => $l->variant_name,
                    'line_kind' => (string) ($l->line_kind ?? 'standard'),
                    'parent_line_id' => $l->parent_sales_order_line_id ? (int) $l->parent_sales_order_line_id : null,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'line_total' => (float) $l->line_total,
                    'modifiers' => $l->modifiers ?? [],
                ])->values(),
                'subtotal' => (float) $s->subtotal, 'discount_amount' => (float) $s->discount_amount, 'tax_amount' => (float) $s->tax_amount,
                'service_charge_amount' => (float) $s->service_charge_amount, 'grand_total' => (float) $s->grand_total,
            ])->values(),
            'previously_paid' => $paid->map(fn (SalesOrder $s) => [
                'id' => (int) $s->id, 'sale_no' => $s->sale_no, 'grand_total' => (float) $s->grand_total,
                'paid_amount' => (float) $s->paid_amount, 'completed_at' => $s->completed_at?->toIso8601String(),
                'methods' => $s->payments->map(fn ($p) => $p->method?->name)->filter()->unique()->values(),
            ])->values(),
            'totals' => [
                'subtotal' => $sum('subtotal'), 'discount_amount' => $sum('discount_amount'), 'tax_amount' => $sum('tax_amount'),
                'service_charge_amount' => $sum('service_charge_amount'), 'delivery_charge_amount' => $sum('delivery_charge_amount'),
                'grand_total' => $sum('grand_total'),
            ],
            'previously_paid_total' => round((float) $paid->sum('grand_total'), 2),
            // BILL-PREVIEW-WRONG-PRINT-1: the print target of THIS bill — its open (held) checks, never the cart.
            'held_sale_ids' => $held->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'html' => $this->renderTableBillReceipt($session, $held, $paid, $layout, $user),
        ];
    }

    /**
     * Online HeldSaleController::ajaxTableSessions shape (the Change Order modal's table picker) — every usable table of
     * the bound branch with its live session (if any) and whether that session already carries an open check.
     */
    public function tableSessions(User $user): array
    {
        $branchId = $this->guardRead($user);
        $tables = RestaurantTable::on('tenant')->with(['openSession.waiter', 'floor'])->where('branch_id', $branchId)
            ->where('status', '!=', 'inactive')->orderBy('table_no')->limit(100)->get();
        $reserved = EdgeTableReservation::on('tenant')->whereIn('restaurant_table_id', $tables->pluck('id'))
            ->where('status', EdgeTableReservation::STATUS_ACTIVE)->pluck('restaurant_table_id')->map(fn ($id) => (int) $id)->all();

        return $tables->map(function ($t) use ($reserved) {
            $session = $t->openSession;
            $held = $session ? SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)->where('status', 'held')->orderBy('id')->pluck('id') : collect();

            return [
                'table_id' => (int) $t->id,
                'session_id' => $session?->id ? (int) $session->id : null,
                'label' => 'Table ' . $t->table_no . ($t->floor ? ' — ' . $t->floor->name : '') . ($t->name && $t->name !== $t->table_no ? ' (' . $t->name . ')' : ''),
                'table_no' => $t->table_no,
                'waiter' => $session?->waiter?->name,
                'has_session' => $session !== null,
                'held_sale_ids' => $held->map(fn ($id) => (int) $id)->values()->all(),
                'reserved' => in_array((int) $t->id, $reserved, true) || (! $session && $t->status === 'reserved'),
                'status' => $session ? ($session->status === 'bill_requested' ? 'bill_requested' : 'occupied') : $t->status,
            ];
        })->values()->all();
    }

    /** The session summary the page paints into the session bar (Online HeldSaleController::sessionPayload shape). */
    public function sessionPayload(RestaurantTableSession $session): array
    {
        $session->loadMissing(['table', 'waiter']);
        $openCheck = (float) SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)->where('status', 'held')->sum('grand_total');

        return [
            'id' => (int) $session->id, 'session_uuid' => $session->session_uuid, 'session_no' => $session->session_no,
            'table_id' => (int) $session->restaurant_table_id, 'table_no' => $session->table?->table_no,
            'floor' => $session->table?->floor?->name,
            'waiter_id' => $session->restaurant_waiter_id ? (int) $session->restaurant_waiter_id : null,
            'waiter_name' => $session->waiter?->name, 'guest_count' => (int) $session->guest_count,
            'status' => $session->status, 'notes' => $session->notes,
            'business_date' => $session->business_date?->toDateString(),
            'opened_at' => $session->opened_at?->toIso8601String(), 'closed_at' => $session->closed_at?->toIso8601String(),
            'opened_by' => $session->relationLoaded('openedBy') ? $session->openedBy?->name : null,
            'closed_by' => $session->relationLoaded('closedBy') ? $session->closedBy?->name : null,
            'open_check' => round($openCheck, 2),
        ];
    }

    // ───────────────────────────── internals ─────────────────────────────

    /** The receipt document Online renders for the table bill (243e01d renderTableBillReceipt), over a transient sale. */
    private function renderTableBillReceipt(RestaurantTableSession $session, $held, $paid, $layout, User $user): ?string
    {
        $sum = fn (string $col) => (float) $held->sum(fn ($s) => (float) ($s->{$col} ?? 0));
        $sale = new SalesOrder([
            'branch_id' => $session->branch_id, 'order_type' => 'dine_in',
            'subtotal' => $sum('subtotal'), 'discount_amount' => $sum('discount_amount'), 'tax_amount' => $sum('tax_amount'),
            'service_charge_amount' => $sum('service_charge_amount'), 'delivery_charge_amount' => $sum('delivery_charge_amount'),
            'tip_amount' => $sum('tip_amount'), 'grand_total' => $sum('grand_total'), 'paid_amount' => 0, 'change_amount' => 0,
        ]);
        $sale->setConnection('tenant');
        $sale->sale_no = $session->session_no; // the check number identifies a table bill — no round's own number
        $sale->sale_date = app(\App\Support\TenantClock::class)->now();
        $sale->setRelation('branch', $session->branch);
        $sale->setRelation('customer', null);
        $sale->setRelation('createdBy', $user);
        $sale->setRelation('payments', collect());
        $sale->setRelation('restaurantTable', $session->table);
        $sale->setRelation('restaurantTableSession', $session);
        $sale->setRelation('restaurantWaiter', $session->waiter);
        $sale->setRelation('deliveryChannel', null);
        $sale->setRelation('deliveryRider', null);
        $sale->setRelation('shift', null);
        $sale->setRelation('lines', $held->flatMap(fn ($round) => $round->lines)->values());

        try {
            return view('tenant.printing.documents.receipt', [
                'job' => null, 'salesOrder' => $sale, 'layout' => $layout, 'isPreview' => true,
                // Dormant until the canonical 243e01d receipt block (`@isset($tableBill)`) is reconciled (Team 6).
                'tableBill' => ['session' => $session, 'rounds' => $held, 'paid' => $paid],
            ])->render();
        } catch (\Throwable $e) {
            report($e);

            return null; // the structured rounds / previously paid / totals still answer; the page renders them itself
        }
    }

    private function activeReservationLocked(int $tableId): bool
    {
        return EdgeTableReservation::on('tenant')->where('restaurant_table_id', $tableId)
            ->where('status', EdgeTableReservation::STATUS_ACTIVE)->lockForUpdate()->exists();
    }

    /** Principal + bound branch + dine-in allowance (Online assertDineInAllowed); returns the branch id. */
    private function guardRead(User $user): int
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $auth = auth('tenant')->user();
        if (! $auth || (int) $auth->id !== (int) $user->id) {
            throw ValidationException::withMessages(['user' => 'A table action requires an authenticated Edge cashier session.']);
        }
        if (! EdgeUserAuthz::isActive($user) || ! EdgeUserAuthz::isEdgeLoginEligible($user) || ! EdgeUserAuthz::mayOperateBranch($user, $branchId)) {
            throw ValidationException::withMessages(['user' => 'This user is not authorized to sell on this Branch Server.']);
        }
        if (! $user->allowsOrderType('dine_in')) {
            throw ValidationException::withMessages(['order_type' => 'Your account is not allowed to use Dine In orders.']);
        }

        return $branchId;
    }

    /** guardRead + P0 branch authority (a standby appliance fails closed before any work). */
    private function guardMutation(User $user): int
    {
        $branchId = $this->guardRead($user);
        try {
            app(EdgeAuthorityService::class)->assertLocalMutationAllowed();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['authority' => $e->getMessage()]);
        }

        return $branchId;
    }
}
