<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantFloor;
use App\Models\Tenant\RestaurantTable;
use Illuminate\Http\Request;

class RestaurantTableController extends Controller
{
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
