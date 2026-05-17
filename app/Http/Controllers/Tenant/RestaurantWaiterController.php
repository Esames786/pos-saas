<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\RestaurantWaiter;
use Illuminate\Http\Request;

class RestaurantWaiterController extends Controller
{
    public function index(Request $request)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        $query = RestaurantWaiter::with('branch')->orderBy('branch_id')->orderBy('name');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $waiters = $query->paginate(25)->withQueryString();

        return view('tenant.restaurant.waiters.index', compact('waiters', 'branches'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'name'      => 'required|string|max:100',
            'code'      => 'nullable|string|max:20',
            'phone'     => 'nullable|string|max:30',
            'status'    => 'in:active,inactive',
        ]);

        $data['branch_id'] = $data['branch_id'] ?: null;
        $data['status']    = $data['status'] ?? 'active';

        RestaurantWaiter::create($data);

        return redirect(url('/restaurant/waiters'))->with('status', 'Waiter created.');
    }

    public function update(Request $request, RestaurantWaiter $restaurantWaiter)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'name'      => 'required|string|max:100',
            'code'      => 'nullable|string|max:20',
            'phone'     => 'nullable|string|max:30',
            'status'    => 'in:active,inactive',
        ]);

        $data['branch_id'] = $data['branch_id'] ?: null;

        $restaurantWaiter->update($data);

        return redirect(url('/restaurant/waiters'))->with('status', 'Waiter updated.');
    }

    public function destroy(RestaurantWaiter $restaurantWaiter)
    {
        $restaurantWaiter->delete();

        return redirect(url('/restaurant/waiters'))->with('status', 'Waiter deleted.');
    }
}
