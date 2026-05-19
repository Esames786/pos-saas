<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Unit;
use App\Models\Tenant\UnitConversion;
use Illuminate\Http\Request;

class UnitConversionController extends Controller
{
    public function index()
    {
        $conversions = UnitConversion::with(['fromUnit', 'toUnit'])
            ->orderBy('from_unit_id')
            ->orderBy('to_unit_id')
            ->paginate(25);

        $units = Unit::orderBy('name')->get();

        return view('tenant.unit-conversions.index', compact('conversions', 'units'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_unit_id' => ['required', 'exists:units,id'],
            'to_unit_id'   => ['required', 'exists:units,id', 'different:from_unit_id'],
            'factor'       => ['required', 'numeric', 'min:0.00000001'],
        ]);

        $exists = UnitConversion::where('from_unit_id', $data['from_unit_id'])
            ->where('to_unit_id', $data['to_unit_id'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['from_unit_id' => 'Conversion between these units already exists.'])->withInput();
        }

        UnitConversion::create($data);

        return redirect(url('/unit-conversions'))->with('status', 'Unit conversion added.');
    }

    public function update(Request $request, UnitConversion $unitConversion)
    {
        $data = $request->validate([
            'factor' => ['required', 'numeric', 'min:0.00000001'],
        ]);

        $unitConversion->update($data);

        return redirect(url('/unit-conversions'))->with('status', 'Unit conversion updated.');
    }

    public function destroy(UnitConversion $unitConversion)
    {
        $unitConversion->delete();

        return redirect(url('/unit-conversions'))->with('status', 'Unit conversion deleted.');
    }
}
