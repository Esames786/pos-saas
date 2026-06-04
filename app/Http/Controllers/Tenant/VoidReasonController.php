<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\VoidReason;
use Illuminate\Http\Request;

class VoidReasonController extends Controller
{
    public function index()
    {
        return view('tenant.void-reasons.index', [
            'reasons' => VoidReason::where('is_active', true)->get(),
        ]);
    }

    public function create()
    {
        return view('tenant.void-reasons.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                       => ['required', 'string', 'max:190'],
            'reason_type'                => ['required', 'in:void,discount,return,cancel,wastage,other'],
            'requires_manager_approval'  => ['nullable', 'boolean'],
            'is_active'                  => ['nullable', 'boolean'],
        ]);

        VoidReason::create($data);
        return redirect(url('/void-reasons'))->with('status', 'Void reason created.');
    }

    public function edit(VoidReason $voidReason)
    {
        return view('tenant.void-reasons.edit', ['reason' => $voidReason]);
    }

    public function update(Request $request, VoidReason $voidReason)
    {
        $data = $request->validate([
            'name'                       => ['required', 'string', 'max:190'],
            'reason_type'                => ['required', 'in:void,discount,return,cancel,wastage,other'],
            'requires_manager_approval'  => ['nullable', 'boolean'],
            'is_active'                  => ['nullable', 'boolean'],
        ]);

        $voidReason->update($data);
        return redirect(url('/void-reasons'))->with('status', 'Void reason updated.');
    }

    public function destroy(VoidReason $voidReason)
    {
        $voidReason->delete();
        return back()->with('status', 'Void reason deleted.');
    }
}
