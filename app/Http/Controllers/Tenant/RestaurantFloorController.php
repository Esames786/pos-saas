<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantFloor;
use Illuminate\Http\Request;

class RestaurantFloorController extends Controller
{
    public function index()
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $floors   = RestaurantFloor::with('branch')->orderBy('branch_id')->orderBy('sort_order')->paginate(25);

        return view('tenant.restaurant.floors.index', compact('floors', 'branches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'  => 'required|exists:branches,id',
            'name'       => 'required|string|max:100',
            'code'       => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'status'     => 'in:active,inactive',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status']     = $data['status'] ?? 'active';

        RestaurantFloor::create($data);

        return redirect(url('/restaurant/floors'))->with('status', 'Floor created.');
    }

    public function update(Request $request, RestaurantFloor $restaurantFloor)
    {
        $data = $request->validate([
            'branch_id'  => 'required|exists:branches,id',
            'name'       => 'required|string|max:100',
            'code'       => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'status'     => 'in:active,inactive',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;

        $restaurantFloor->update($data);

        return redirect(url('/restaurant/floors'))->with('status', 'Floor updated.');
    }

    public function destroy(RestaurantFloor $restaurantFloor)
    {
        $restaurantFloor->delete();

        return redirect(url('/restaurant/floors'))->with('status', 'Floor deleted.');
    }
}
