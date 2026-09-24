<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeLocalTableOperationsService;
use App\Services\Edge\EdgeTableReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * W0c/W3 (Team 3 owns) — the TABLE layer on the Branch Server: board, open/close a table session, reservations, and the
 * W3 Table Workspace operations (Request Bill, Move, Merge, session detail, per-table Bill Preview, the Change Order
 * table picker). EdgeLocalPosService / EdgeTableReservationService / EdgeLocalTableOperationsService hold the authority
 * (locks, one-open-check rule, Edge-owned reservations); this controller adds the Online route permission gate
 * (denyUnlessCan — the permission that gates the SAME Online route) and the HTTP shape, nothing else.
 */
class EdgeLocalRestaurantController extends Controller
{
    use ResolvesEdgePosContext;

    /** Online RestaurantTableController::RESERVE_PERMISSION — reserve / unreserve / details gate on the table-open permission. */
    private const RESERVE_PERMISSION = 'tenant.restaurant.table-sessions.open';

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly EdgeTableReservationService $reservations,
        private readonly EdgeLocalTableOperationsService $tables,
    ) {
    }

    /**
     * Table board data for the bound branch: floors → tables → open session + open-check summary (Online table-board partial
     * data: table_no, seats, effective status, session no, waiter, running total, whether the session carries any order).
     */
    public function restaurantBoard(): JsonResponse
    {
        $branchId = (int) $this->context->requireCurrent()->branch_id;
        $floors = RestaurantFloor::on('tenant')->where('branch_id', $branchId)
            ->where('status', 'active')->orderBy('sort_order')->orderBy('name')
            ->with(['tables' => fn ($q) => $q->where('status', '!=', 'inactive')->orderBy('sort_order')->orderBy('table_no')
                ->with(['openSession' => fn ($s) => $s->with('waiter')])])
            ->get()
            ->map(fn ($floor) => [
                'id' => $floor->id, 'name' => $floor->name,
                'tables' => $floor->tables->map(function ($t) {
                    $session = $t->openSession;
                    $orders = $session ? SalesOrder::on('tenant')->withCount('lines')->where('restaurant_table_session_id', $session->id)
                        ->orderBy('id')->get(['id', 'sale_no', 'sale_uuid', 'grand_total', 'status', 'is_draft', 'updated_at']) : collect();
                    $held = $orders->where('status', 'held')->values();

                    // ONLINE-POS PARITY: a free table carrying an ACTIVE Edge reservation shows as reserved
                    // (reservations live in the Edge-owned table, never on restaurant_tables config).
                    $reservation = $session ? null : $this->reservations->activeFor((int) $t->id);
                    $status = $session ? ($session->status === 'bill_requested' ? 'bill_requested' : 'occupied') : ($reservation ? 'reserved' : $t->status);

                    return [
                        'id' => $t->id, 'table_no' => $t->table_no, 'name' => $t->name, 'capacity' => $t->capacity,
                        'status' => $status,
                        'reservation' => $reservation ? $this->reservationView($reservation) : null,
                        // R19: a table reserved on the ONLINE POS before handover arrives with status `reserved` only — the
                        // bootstrap does not carry restaurant_tables.reserved_* — so the page can say so honestly.
                        'reservation_details_missing' => ! $session && ! $reservation && $t->status === 'reserved',
                        'session' => $session ? [
                            'id' => $session->id, 'session_uuid' => $session->session_uuid, 'session_no' => $session->session_no,
                            'guest_count' => $session->guest_count, 'status' => $session->status,
                            'business_date' => $session->business_date?->toDateString(),
                            'waiter_id' => $session->restaurant_waiter_id ? (int) $session->restaurant_waiter_id : null,
                            'waiter_name' => $session->waiter?->name,
                            'notes' => $session->notes,
                            // Online: Total = the session's orders; Close Table only while the session carries NO order at all.
                            'open_check' => round((float) $held->sum('grand_total'), 2),
                            'order_count' => $orders->count(),
                            'held_orders' => $held->map(fn ($h) => [
                                'id' => (int) $h->id, 'sale_no' => $h->sale_no, 'sale_uuid' => $h->sale_uuid,
                                'grand_total' => (float) $h->grand_total, 'is_draft' => (bool) $h->is_draft,
                                'items_count' => (int) $h->lines_count, 'updated_at' => $h->updated_at?->toIso8601String(),
                            ])->values(),
                        ] : null,
                    ];
                })->values(),
            ])->values();

        return response()->json(['branch_id' => $branchId, 'floors' => $floors]);
    }

    /** Open a dine-in table session on the selected terminal. */
    public function openTable(Request $request, int $table): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.open', 'Opening a table needs the Open Table permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'restaurant_waiter_id' => ['nullable', 'integer'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $terminal = $this->selectedTerminal($request);
        if ($terminal instanceof JsonResponse) {
            return $terminal;
        }
        try {
            $session = $this->pos->openTableSession($table, $data, auth('tenant')->user(), $terminal->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session_id' => $session->id, 'session_uuid' => $session->session_uuid, 'session_no' => $session->session_no,
            'table_id' => $session->restaurant_table_id, 'status' => $session->status,
            'business_date' => $session->business_date?->toDateString(),
            'guest_count' => (int) $session->guest_count, 'notes' => $session->notes,
            'session' => $this->tables->sessionPayload($session),
        ], 201);
    }

    /** Close/cancel a table session that has no remaining open orders. */
    public function closeTableSession(Request $request, int $session): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.close', 'Closing a table needs the Close Table permission.')) {
            return $denied;
        }
        $data = $request->validate(['status' => ['nullable', 'string', 'in:closed,cancelled']]);
        try {
            $closed = $this->pos->closeTableSession($session, $data['status'] ?? 'closed', auth('tenant')->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['session_id' => $closed->id, 'status' => $closed->status,
            'message' => $closed->status === 'cancelled' ? 'Session cancelled.' : 'Session closed as paid.']);
    }

    // ═══════════════════════ W3 — Table Workspace operations (Online RestaurantTableSessionController) ═══════════════════════

    /** R11 — Request Bill: session + table → bill_requested (Online billRequested). */
    public function requestBill(int $session): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.bill-requested', 'Requesting the bill needs the Request Bill permission.')) {
            return $denied;
        }
        try {
            $s = $this->tables->requestBill($session, auth('tenant')->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'status' => 'bill_requested',
            'message' => 'Bill requested for table ' . $s->table?->table_no . '.', 'session' => $this->tables->sessionPayload($s)]);
    }

    /** R12 — Move table: the live session and its open checks to an available table (Online move). */
    public function moveSession(Request $request, int $session): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.move', 'Moving a table needs the Move Table permission.')) {
            return $denied;
        }
        $data = $request->validate(['target_table_id' => ['required', 'integer']]);
        try {
            $s = $this->tables->moveSession($session, (int) $data['target_table_id'], auth('tenant')->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Table moved successfully.', 'session' => $this->tables->sessionPayload($s)]);
    }

    /** R13 — Merge: the source session's open checks onto the target session (Online merge). */
    public function mergeSessions(Request $request, int $session): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.merge', 'Merging tables needs the Merge Tables permission.')) {
            return $denied;
        }
        $data = $request->validate(['target_session_id' => ['required', 'integer']]);
        try {
            $s = $this->tables->mergeSessions($session, (int) $data['target_session_id'], auth('tenant')->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Table sessions merged successfully.', 'session' => $this->tables->sessionPayload($s)]);
    }

    /** R20 — session detail (Online show): the session card + every order on it. */
    public function showSession(int $session): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.show', 'Viewing a table session needs the Table Session permission.')) {
            return $denied;
        }
        try {
            return response()->json($this->tables->sessionDetail($session, auth('tenant')->user()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /** R14 — per-table Bill Preview document (rounds / previously paid / totals / print target = held ids). */
    public function sessionBillPreview(int $session): JsonResponse
    {
        if ($denied = $this->denyUnlessCan('tenant.restaurant.table-sessions.bill-preview', 'The table bill preview needs the Bill Preview permission.')) {
            return $denied;
        }
        try {
            return response()->json(['ok' => true] + $this->tables->billPreview($session, auth('tenant')->user()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /** R21/A28 — the Change Order table picker (Online /api/pos/table-sessions — permission-free on Online, mirrored). */
    public function tableSessions(): JsonResponse
    {
        return response()->json(['sessions' => $this->tables->tableSessions(auth('tenant')->user())]);
    }

    // ═══════════════════════ reservations ═══════════════════════

    /** ONLINE-POS PARITY — reserve a table (walk-in or existing customer, booking time, note). */
    public function reserveTable(Request $request, int $table): JsonResponse
    {
        if ($denied = $this->denyUnlessCan(self::RESERVE_PERMISSION, 'Reserving a table needs the Open Table permission.')) {
            return $denied;
        }
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'reserved_for' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $r = $this->reservations->reserve($table, $data, auth('tenant')->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->reservationView($r), 201);
    }

    /** ONLINE-POS PARITY — view the active reservation on a table. */
    public function tableReservation(int $table): JsonResponse
    {
        if ($denied = $this->denyUnlessCan(self::RESERVE_PERMISSION, 'Viewing a reservation needs the Open Table permission.')) {
            return $denied;
        }
        $r = $this->reservations->activeFor($table);

        return response()->json(['reservation' => $r ? $this->reservationView($r) : null]);
    }

    /** ONLINE-POS PARITY — cancel the active reservation on a table. */
    public function cancelReservation(int $table): JsonResponse
    {
        if ($denied = $this->denyUnlessCan(self::RESERVE_PERMISSION, 'Cancelling a reservation needs the Open Table permission.')) {
            return $denied;
        }
        try {
            $this->reservations->cancel($table, auth('tenant')->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'cancelled']);
    }

    private function reservationView(EdgeTableReservation $r): array
    {
        return [
            'reservation_uuid' => $r->reservation_uuid,
            'restaurant_table_id' => (int) $r->restaurant_table_id,
            'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null,
            'customer_name' => $r->customer_name,
            'customer_phone' => $r->customer_phone,
            'reserved_for' => $r->reserved_for?->toIso8601String(),
            'note' => $r->note,
            'status' => $r->status,
            // R16 — Online reservationDetailsModal: who reserved it and when it was marked.
            'reserved_by' => $r->reserved_by_user_id ? \App\Models\Tenant\User::on('tenant')->find((int) $r->reserved_by_user_id)?->name : null,
            'reserved_at' => $r->reserved_at?->toIso8601String(),
        ];
    }
}
