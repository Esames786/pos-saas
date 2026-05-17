<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\RestaurantTableSession;
use App\Models\Tenant\RestaurantWaiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if ($restaurantTable->openSession()->exists()) {
            return back()->withErrors(['table' => 'Table already has an open session.']);
        }

        $sessionNo = 'TS-' . now()->format('YmdHis') . '-' . random_int(100, 999);

        RestaurantTableSession::create([
            'session_no'           => $sessionNo,
            'branch_id'            => $restaurantTable->branch_id,
            'restaurant_table_id'  => $restaurantTable->id,
            'restaurant_waiter_id' => $data['restaurant_waiter_id'] ?? null,
            'opened_by_user_id'    => Auth::id(),
            'guest_count'          => $data['guest_count'],
            'status'               => 'open',
            'opened_at'            => now(),
            'notes'                => $data['notes'] ?? null,
        ]);

        $restaurantTable->update(['status' => 'occupied']);

        return redirect(url('/restaurant/board?branch_id=' . $restaurantTable->branch_id))
            ->with('status', "Table {$restaurantTable->table_no} session opened.");
    }

    public function billRequested(RestaurantTableSession $restaurantTableSession)
    {
        if ($restaurantTableSession->status !== 'open') {
            return back()->withErrors(['session' => 'Session is not open.']);
        }

        $restaurantTableSession->update(['status' => 'bill_requested']);
        $restaurantTableSession->table->update(['status' => 'bill_requested']);

        return redirect(url('/restaurant/board'))->with('status', 'Bill requested for table ' . $restaurantTableSession->table->table_no . '.');
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
}
