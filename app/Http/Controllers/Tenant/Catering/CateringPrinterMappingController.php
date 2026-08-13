<?php

namespace App\Http\Controllers\Tenant\Catering;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\CateringPrinterMapping;
use App\Models\Tenant\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CATERING-SLICE-3: separate Catering routing authority (spec §15).
 * "Copy From POS KOT Mappings" is a ONE-WAY read of category_printer_mappings
 * into catering_printer_mappings; the POS table is never written here, and
 * after the copy the catering mappings are managed independently.
 */
class CateringPrinterMappingController extends Controller
{
    public function index(Request $request)
    {
        $mappings = CateringPrinterMapping::with(['branch', 'category', 'printer'])
            ->orderBy('branch_id')
            ->orderBy('category_id')
            ->get();

        $branches = Branch::where('status', 'active')->orderBy('name')->get();
        $categories = Category::where('is_active', true)->orderBy('name')->get();
        $printers = Printer::where('is_active', true)->orderBy('name')->get();

        return view('tenant.catering.printer-mappings.index', compact('mappings', 'branches', 'categories', 'printers'));
    }

    public function store(Request $request)
    {
        if (in_array($request->input('category_id'), ['0', '', null], true)) {
            $request->merge(['category_id' => null]);
        }

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'production_station' => ['nullable', 'string', 'max:50'],
            'printer_id' => ['required', 'exists:printers,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['branch_id'] = $data['branch_id'] ?? null;
        $data['production_station'] = trim((string) ($data['production_station'] ?? '')) ?: null;
        $data['is_active'] = ! empty($data['is_active']);

        DB::connection('tenant')->transaction(function () use ($data) {
            // MySQL unique indexes don't constrain NULLs — explicit duplicate check
            // inside the transaction (the category_printer_mappings pattern).
            if ($data['category_id'] !== null) {
                Category::whereKey($data['category_id'])->lockForUpdate()->firstOrFail();
            }

            $duplicate = CateringPrinterMapping::query()
                ->when($data['branch_id'] === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $data['branch_id']))
                ->when($data['category_id'] === null, fn ($q) => $q->whereNull('category_id'), fn ($q) => $q->where('category_id', $data['category_id']))
                ->when($data['production_station'] === null, fn ($q) => $q->whereNull('production_station'), fn ($q) => $q->where('production_station', $data['production_station']))
                ->where('printer_id', $data['printer_id'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'printer_id' => 'This catering mapping already exists.',
                ]);
            }

            CateringPrinterMapping::create($data);
        });

        return back()->with('status', 'Catering mapping added.');
    }

    public function destroy(CateringPrinterMapping $cateringPrinterMapping)
    {
        $cateringPrinterMapping->delete();

        return back()->with('status', 'Catering mapping removed.');
    }

    /** One-way convenience copy: POS KOT mappings → catering mappings. */
    public function copyFromPos(\App\Services\Catering\CateringPrinterRoutingService $routing)
    {
        $copied = $routing->copyFromPosKotMappings();

        return back()->with('status', "{$copied} mapping(s) copied from POS KOT routing. They are now independent — changes here never affect POS.");
    }
}
