<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $query = Unit::query()->orderBy('unit_type')->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('tenant.units.index', [
            'units' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('tenant.units.form', ['unit' => null, 'title' => 'Create Unit']);
    }

    public function store(Request $request)
    {
        Unit::create($this->validated($request));

        return redirect('/units')->with('status', 'Unit created successfully.');
    }

    public function edit(Unit $unit)
    {
        return view('tenant.units.form', ['unit' => $unit, 'title' => 'Edit Unit']);
    }

    public function update(Request $request, Unit $unit)
    {
        $unit->update($this->validated($request, $unit));

        return redirect('/units')->with('status', 'Unit updated successfully.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->products()->exists()) {
            return back()->withErrors(['unit' => 'Unit is linked to products and cannot be deleted.']);
        }

        $unit->delete();

        return back()->with('status', 'Unit deleted successfully.');
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        $data = $request->validate([
            'code'       => ['required', 'string', 'max:50', Rule::unique('units', 'code')->ignore($unit?->id)],
            'name'       => ['required', 'string', 'max:190'],
            'unit_type'  => ['required', Rule::in(['quantity', 'weight', 'volume', 'length'])],
            'base_factor'=> ['required', 'numeric', 'min:0.000001'],
            'is_base'    => ['nullable', 'boolean'],
            'is_active'  => ['nullable', 'boolean'],
        ]);

        return [
            'code'        => strtoupper($data['code']),
            'name'        => $data['name'],
            'unit_type'   => $data['unit_type'],
            'base_factor' => $data['base_factor'],
            'is_base'     => !empty($data['is_base']),
            'is_active'   => !empty($data['is_active']),
        ];
    }
}
