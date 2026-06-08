<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\RestaurantWaiter;
use App\Models\Tenant\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RestaurantTableSessionController extends Controller
{
    public function board(Request $request)
    {
        $branches         = Branch::where('status', 'active')->orderBy('name')->get();
        $selectedBranchId = $request->input('branch_id', $branches->first()?->id);

        $floors = RestaurantFloor::with([
            'tables' => fn ($q) => $q->orderBy('sort_order'),
            'tables.openSession.waiter',
            'tables.openSession.salesOrders',
        ])
            ->where('branch_id', $selectedBranchId)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        $waiters = RestaurantWaiter::where(function ($q) use ($selectedBranchId) {
            $q->where('branch_id', $selectedBranchId)->orWhereNull('branch_id');
        })->where('status', 'active')->orderBy('name')->get();

        return view('tenant.restaurant.board', compact('floors', 'waiters', 'branches', 'selectedBranchId'));
    }

    public function open(Request $request, RestaurantTable $restaurantTable)
    {
        $data = $request->validate([
            'restaurant_waiter_id' => 'nullable|exists:restaurant_waiters,id',
            'guest_count'          => 'required|integer|min:1|max:100',
            'notes'                => 'nullable|string|max:255',
        ]);

        if (!empty($data['restaurant_waiter_id'])) {
            $waiter = RestaurantWaiter::find($data['restaurant_waiter_id']);
            if ($waiter && $waiter->branch_id && (int) $waiter->branch_id !== (int) $restaurantTable->branch_id) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Selected waiter does not belong to this branch.'], 422);
                }
                return back()->withErrors(['restaurant_waiter_id' => 'Selected waiter does not belong to this branch.']);
            }
        }

        $sessionNo = 'TS-' . now()->format('YmdHis') . '-' . random_int(100, 999);

        try {
            DB::connection('tenant')->transaction(function () use ($restaurantTable, $data, $sessionNo) {
                $table = RestaurantTable::lockForUpdate()->findOrFail($restaurantTable->id);

                if ($table->openSession()->exists()) {
                    throw new \RuntimeException('Table already has an open session.');
                }

                RestaurantTableSession::create([
                    'session_no'           => $sessionNo,
                    'branch_id'            => $table->branch_id,
                    'restaurant_table_id'  => $table->id,
                    'restaurant_waiter_id' => $data['restaurant_waiter_id'] ?? null,
                    'opened_by_user_id'    => Auth::id(),
                    'guest_count'          => $data['guest_count'],
                    'status'               => 'open',
                    'opened_at'            => now(),
                    'notes'                => $data['notes'] ?? null,
                ]);

                $table->update(['status' => 'occupied']);
            });
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['table' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            $session = RestaurantTableSession::where('session_no', $sessionNo)
                ->where('restaurant_table_id', $restaurantTable->id)
                ->first();
            return response()->json([
                'session_id' => $session?->id,
                'branch_id'  => $restaurantTable->branch_id,
            ]);
        }

        return redirect(url('/restaurant/board?branch_id=' . $restaurantTable->branch_id))
            ->with('status', "Table {$restaurantTable->table_no} session opened.");
    }

    public function billRequested(RestaurantTableSession $restaurantTableSession)
    {
        if (!in_array($restaurantTableSession->status, ['open', 'bill_requested'], true)) {
            if (request()->expectsJson()) {
                return response()->json(['message' => 'Session is not open.'], 422);
            }
            return back()->withErrors(['session' => 'Session is not open.']);
        }

        $restaurantTableSession->update(['status' => 'bill_requested']);
        $restaurantTableSession->table->update(['status' => 'bill_requested']);

        if (request()->expectsJson()) {
            return response()->json([
                'ok'      => true,
                'status'  => 'bill_requested',
                'message' => 'Bill requested for table ' . $restaurantTableSession->table->table_no . '.',
            ]);
        }

        return back()->with('status', 'Bill requested for table ' . $restaurantTableSession->table->table_no . '.');
    }

    public function close(Request $request, RestaurantTableSession $restaurantTableSession)
    {
        if (in_array($restaurantTableSession->status, ['closed', 'cancelled'])) {
            return back()->withErrors(['session' => 'Session is already closed or cancelled.']);
        }

        $closeType     = $request->input('status', 'closed');
        $sessionStatus = $closeType === 'cancelled' ? 'cancelled' : 'closed';

        if ($sessionStatus === 'closed') {
            $openSales = $restaurantTableSession->salesOrders()
                ->whereIn('status', ['draft', 'held'])
                ->exists();

            if ($openSales) {
                return back()->withErrors(['session' => 'Cannot close: open or held orders exist on this session.']);
            }
        }

        $restaurantTableSession->update([
            'status'            => $sessionStatus,
            'closed_by_user_id' => Auth::id(),
            'closed_at'         => now(),
        ]);

        $restaurantTableSession->table->update(['status' => 'available']);

        $msg = $sessionStatus === 'cancelled' ? 'Session cancelled.' : 'Session closed as paid.';

        return redirect(url('/restaurant/board'))->with('status', $msg);
    }

    public function show(RestaurantTableSession $restaurantTableSession)
    {
        $restaurantTableSession->load([
            'table.floor',
            'waiter',
            'openedBy',
            'salesOrders.lines.product',
        ]);

        return view('tenant.restaurant.sessions.show', compact('restaurantTableSession'));
    }

    public function billPreview(RestaurantTableSession $restaurantTableSession)
    {
        $restaurantTableSession->load([
            'branch',
            'table.floor.tables',
            'waiter',
            'salesOrders' => function ($query) {
                $query->whereIn('status', ['held', 'paid'])
                    ->with(['lines.product', 'lines.variant', 'payments.method']);
            },
        ]);

        return view('tenant.restaurant.table-sessions.bill-preview', [
            'session' => $restaurantTableSession,
        ]);
    }

    public function move(Request $request, RestaurantTableSession $restaurantTableSession)
    {
        $data = $request->validate([
            'target_table_id' => ['required', 'exists:restaurant_tables,id'],
        ]);

        if (!in_array($restaurantTableSession->status, ['open', 'bill_requested'], true)) {
            return back()->withErrors(['session' => 'Only open table sessions can be moved.']);
        }

        $targetTable = RestaurantTable::where('branch_id', $restaurantTableSession->branch_id)
            ->where('id', $data['target_table_id'])
            ->firstOrFail();

        if ((int) $targetTable->id === (int) $restaurantTableSession->restaurant_table_id) {
            return back()->withErrors(['table' => 'Please select a different target table.']);
        }

        $targetHasOpenSession = RestaurantTableSession::where('restaurant_table_id', $targetTable->id)
            ->whereIn('status', ['open', 'bill_requested'])
            ->exists();

        if ($targetHasOpenSession || !in_array($targetTable->status, ['available', 'cleaning'], true)) {
            return back()->withErrors(['table' => 'Target table is not available.']);
        }

        try {
            DB::connection('tenant')->transaction(function () use ($restaurantTableSession, $targetTable) {
                $sourceTableLocked = RestaurantTable::lockForUpdate()->find($restaurantTableSession->restaurant_table_id);
                $targetTableLocked = RestaurantTable::lockForUpdate()->find($targetTable->id);

                $targetHasSession = RestaurantTableSession::where('restaurant_table_id', $targetTableLocked->id)
                    ->whereIn('status', ['open', 'bill_requested'])
                    ->lockForUpdate()
                    ->exists();

                if ($targetHasSession || !in_array($targetTableLocked->status, ['available', 'cleaning'], true)) {
                    throw new \RuntimeException('Target table is no longer available.');
                }

                $restaurantTableSession->update([
                    'restaurant_table_id' => $targetTableLocked->id,
                ]);

                SalesOrder::where('restaurant_table_session_id', $restaurantTableSession->id)
                    ->update([
                        'restaurant_floor_id' => $targetTableLocked->restaurant_floor_id,
                        'restaurant_table_id' => $targetTableLocked->id,
                    ]);

                $sourceTableLocked?->update(['status' => 'available']);

                $targetTableLocked->update([
                    'status' => $restaurantTableSession->status === 'bill_requested'
                        ? 'bill_requested'
                        : 'occupied',
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['table' => $e->getMessage()]);
        }

        return back()->with('status', 'Table moved successfully.');
    }

    public function merge(Request $request, RestaurantTableSession $restaurantTableSession)
    {
        $data = $request->validate([
            'target_session_id' => ['required', 'exists:restaurant_table_sessions,id'],
        ]);

        if (!in_array($restaurantTableSession->status, ['open', 'bill_requested'], true)) {
            return back()->withErrors(['session' => 'Only open table sessions can be merged.']);
        }

        if ((int) $restaurantTableSession->id === (int) $data['target_session_id']) {
            return back()->withErrors(['session' => 'Source and target sessions cannot be the same.']);
        }

        $targetSession = RestaurantTableSession::with(['table'])
            ->where('branch_id', $restaurantTableSession->branch_id)
            ->whereIn('status', ['open', 'bill_requested'])
            ->findOrFail($data['target_session_id']);

        DB::connection('tenant')->transaction(function () use ($restaurantTableSession, $targetSession) {
            SalesOrder::where('restaurant_table_session_id', $restaurantTableSession->id)
                ->whereIn('status', ['held', 'draft'])
                ->update([
                    'restaurant_floor_id'         => $targetSession->table?->restaurant_floor_id,
                    'restaurant_table_id'          => $targetSession->restaurant_table_id,
                    'restaurant_table_session_id'  => $targetSession->id,
                    'restaurant_waiter_id'         => $targetSession->restaurant_waiter_id,
                ]);

            $restaurantTableSession->update([
                'status'            => 'cancelled',
                'closed_at'         => now(),
                'closed_by_user_id' => auth('tenant')->id(),
                'notes'             => trim(($restaurantTableSession->notes ? $restaurantTableSession->notes . ' | ' : '') . 'Merged into session ' . $targetSession->session_no),
            ]);

            $restaurantTableSession->table?->update(['status' => 'available']);

            $targetSession->table?->update([
                'status' => $targetSession->status === 'bill_requested' ? 'bill_requested' : 'occupied',
            ]);
        });

        return back()->with('status', 'Table sessions merged successfully.');
    }
}
