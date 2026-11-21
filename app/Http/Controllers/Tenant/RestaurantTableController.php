<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Customer;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use App\Models\Tenant\User;
use Illuminate\Http\Request;

class RestaurantTableController extends Controller
{
    /** TABLE-RESERVATION-1 — anyone who can open a dine-in table may reserve / cancel it. */
    private const RESERVE_PERMISSION = 'tenant.restaurant.table-sessions.open';

    private function assertMayReserve(): void
    {
        abort_unless((bool) auth('tenant')->user()?->can(self::RESERVE_PERMISSION), 403, 'Permission denied.');
    }

    /** Mark a free table reserved with an attached customer (or a typed walk-in) + time + note. */
    public function reserve(Request $request, RestaurantTable $restaurantTable)
    {
        $this->assertMayReserve();
        // OFFLINE EDGE — while this branch is handed to its Branch Server (Local Mode), the Branch Server owns
        // reservation mutation; the Cloud must not also write it (no split-brain). Same fence as sales.
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(Branch::findOrFail($restaurantTable->branch_id));

        if ($restaurantTable->openSession()->exists()) {
            return response()->json(['ok' => false, 'message' => 'This table has an open session — it cannot be reserved.'], 422);
        }

        $data = $request->validate([
            'reserved_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'reserved_name'        => ['nullable', 'string', 'max:190'],
            'reserved_phone'       => ['nullable', 'string', 'max:40'],
            'reserved_for'         => ['nullable', 'date'],
            'reservation_note'     => ['nullable', 'string', 'max:1000'],
        ]);

        // Snapshot the attached customer's name/phone for display (so the card reads even if the
        // operator typed nothing and the customer record later changes).
        if (! empty($data['reserved_customer_id'])) {
            $customer = Customer::find($data['reserved_customer_id']);
            $data['reserved_name']  = ($data['reserved_name']  ?? '') !== '' ? $data['reserved_name']  : ($customer->name  ?? null);
            $data['reserved_phone'] = ($data['reserved_phone'] ?? '') !== '' ? $data['reserved_phone'] : ($customer->phone ?? null);
        }

        $restaurantTable->update(array_merge($data, [
            'status'              => 'reserved',
            'reserved_by_user_id' => auth('tenant')->id(),
            'reserved_at'         => now(),
        ]));

        return response()->json(['ok' => true]);
    }

    /** Cancel a reservation — the table returns to available and all reservation fields clear. */
    public function unreserve(RestaurantTable $restaurantTable)
    {
        $this->assertMayReserve();
        app(\App\Services\Edge\BranchOperatingModeService::class)
            ->assertSaleMutationAllowed(Branch::findOrFail($restaurantTable->branch_id));

        $restaurantTable->update([
            'status'               => 'available',
            'reserved_customer_id' => null, 'reserved_name' => null, 'reserved_phone' => null,
            'reserved_for'         => null, 'reservation_note' => null,
            'reserved_by_user_id'  => null, 'reserved_at' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Reservation details for the "view" click on a reserved tile. */
    public function reservation(RestaurantTable $restaurantTable)
    {
        $this->assertMayReserve();

        return response()->json(['ok' => true, 'reservation' => [
            'customer_id'  => $restaurantTable->reserved_customer_id,
            'name'         => $restaurantTable->reserved_name,
            'phone'        => $restaurantTable->reserved_phone,
            'reserved_for' => $restaurantTable->reserved_for?->format('d-M-Y h:i A'),
            'note'         => $restaurantTable->reservation_note,
            'reserved_by'  => User::on('tenant')->find($restaurantTable->reserved_by_user_id)?->name,
            'reserved_at'  => $restaurantTable->reserved_at?->format('d-M-Y h:i A'),
        ]]);
    }

    public function index(Request $request)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $floors   = RestaurantFloor::where('status', 'active')->orderBy('branch_id')->orderBy('sort_order')->get();

        $query = RestaurantTable::with(['branch', 'floor', 'openSession.waiter'])
            ->orderBy('restaurant_floor_id')
            ->orderBy('sort_order');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('restaurant_floor_id')) {
            $query->where('restaurant_floor_id', $request->restaurant_floor_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tables = $query->paginate(25)->withQueryString();

        return view('tenant.restaurant.tables.index', compact('tables', 'branches', 'floors'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'           => 'required|exists:branches,id',
            'restaurant_floor_id' => 'required|exists:restaurant_floors,id',
            'table_no'            => 'required|string|max:20',
            'name'                => 'nullable|string|max:100',
            'capacity'            => 'nullable|integer|min:1|max:100',
            'sort_order'          => 'nullable|integer|min:0',
            'status'              => 'in:available,occupied,reserved,bill_requested,cleaning,inactive',
        ]);

        $floor = RestaurantFloor::where('id', $data['restaurant_floor_id'])
            ->where('branch_id', $data['branch_id'])
            ->first();

        if (!$floor) {
            return back()->withErrors(['restaurant_floor_id' => 'Selected floor does not belong to the chosen branch.'])->withInput();
        }

        $data['capacity']   = $data['capacity'] ?? 4;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status']     = $data['status'] ?? 'available';

        RestaurantTable::create($data);

        return redirect(url('/restaurant/tables'))->with('status', 'Table created.');
    }

    public function update(Request $request, RestaurantTable $restaurantTable)
    {
        $data = $request->validate([
            'branch_id'           => 'required|exists:branches,id',
            'restaurant_floor_id' => 'required|exists:restaurant_floors,id',
            'table_no'            => 'required|string|max:20',
            'name'                => 'nullable|string|max:100',
            'capacity'            => 'nullable|integer|min:1|max:100',
            'sort_order'          => 'nullable|integer|min:0',
            'status'              => 'in:available,occupied,reserved,bill_requested,cleaning,inactive',
        ]);

        $floor = RestaurantFloor::where('id', $data['restaurant_floor_id'])
            ->where('branch_id', $data['branch_id'])
            ->first();

        if (!$floor) {
            return back()->withErrors(['restaurant_floor_id' => 'Selected floor does not belong to the chosen branch.'])->withInput();
        }

        $data['capacity']   = $data['capacity'] ?? 4;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $restaurantTable->update($data);

        return redirect(url('/restaurant/tables'))->with('status', 'Table updated.');
    }

    public function destroy(RestaurantTable $restaurantTable)
    {
        $restaurantTable->delete();

        return redirect(url('/restaurant/tables'))->with('status', 'Table deleted.');
    }
}
