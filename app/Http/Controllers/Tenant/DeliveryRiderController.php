<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DeliveryRider;
use Illuminate\Http\Request;

class DeliveryRiderController extends Controller
{
    public function index(Request $request)
    {
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        $query = DeliveryRider::with('branch')->orderBy('branch_id')->orderBy('name');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $riders = $query->paginate(25)->withQueryString();

        return view('tenant.delivery.riders.index', compact('riders', 'branches'));
    }

    public function store(Request $request)
    {
        $data = $this->validateRider($request);

        DeliveryRider::create($data);

        return redirect(url('/delivery/riders'))->with('status', 'Rider created.');
    }

    public function update(Request $request, DeliveryRider $deliveryRider)
    {
        $data = $this->validateRider($request);

        $deliveryRider->update($data);

        return redirect(url('/delivery/riders'))->with('status', 'Rider updated.');
    }

    public function destroy(DeliveryRider $deliveryRider)
    {
        if ($deliveryRider->sales()->exists()) {
            return redirect(url('/delivery/riders'))
                ->withErrors(['rider' => 'This rider has deliveries recorded against them. Mark them inactive instead of deleting.']);
        }

        $deliveryRider->delete();

        return redirect(url('/delivery/riders'))->with('status', 'Rider deleted.');
    }

    private function validateRider(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'name'      => 'required|string|max:100',
            'phone'     => 'nullable|string|max:30',
            'status'    => 'in:active,inactive',
        ]);

        $data['branch_id'] = $data['branch_id'] ?: null;
        $data['status']    = $data['status'] ?? 'active';

        return $data;
    }
}
