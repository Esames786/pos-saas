<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Edge\Concerns\ResolvesEdgePosContext;
use App\Models\Edge\EdgeTableReservation;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\SalesOrder;
use App\Services\Edge\EdgeBranchContext;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeTableReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * W0c (Team 3 owns) — the TABLE layer on the Branch Server: board, open/close a table session, reservations.
 * Split out of EdgeLocalPosController; same routes, same behaviour. EdgeLocalPosService / EdgeTableReservationService
 * hold the authority (locks, one-open-check rule, Edge-owned reservations); this controller adds none of its own.
 */
class EdgeLocalRestaurantController extends Controller
{
    use ResolvesEdgePosContext;

    public function __construct(
        private readonly EdgeBranchContext $context,
        private readonly EdgeLocalPosService $pos,
        private readonly EdgeTableReservationService $reservations,
    ) {
    }

    /** Table board data for the bound branch: floors → tables → open session + open-check summary. */
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
                    $held = $session ? SalesOrder::on('tenant')->where('restaurant_table_session_id', $session->id)
                        ->where('status', 'held')->get(['id', 'sale_no', 'sale_uuid', 'grand_total']) : collect();

                    // ONLINE-POS PARITY: a free table carrying an ACTIVE Edge reservation shows as reserved
                    // (reservations live in the Edge-owned table, never on restaurant_tables config).
                    $reservation = $session ? null : $this->reservations->activeFor((int) $t->id);

                    return [
                        'id' => $t->id, 'table_no' => $t->table_no, 'name' => $t->name, 'capacity' => $t->capacity,
                        'status' => $session ? ($session->status === 'bill_requested' ? 'bill_requested' : 'occupied') : ($reservation ? 'reserved' : $t->status),
                        'reservation' => $reservation ? $this->reservationView($reservation) : null,
                        'session' => $session ? [
                            'id' => $session->id, 'session_uuid' => $session->session_uuid, 'session_no' => $session->session_no,
                            'guest_count' => $session->guest_count, 'status' => $session->status,
                            'business_date' => $session->business_date?->toDateString(),
                            'waiter_name' => $session->waiter?->name,
                            'held_orders' => $held->values(),
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

        return response()->json(['session_id' => $closed->id, 'status' => $closed->status]);
    }

    /** ONLINE-POS PARITY — reserve a table (walk-in or existing customer, booking time, note). */
    public function reserveTable(Request $request, int $table): JsonResponse
    {
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
        $r = $this->reservations->activeFor($table);

        return response()->json(['reservation' => $r ? $this->reservationView($r) : null]);
    }

    /** ONLINE-POS PARITY — cancel the active reservation on a table. */
    public function cancelReservation(int $table): JsonResponse
    {
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
        ];
    }
}
