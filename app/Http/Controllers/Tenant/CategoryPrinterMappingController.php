<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Printer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryPrinterMappingController extends Controller
{
    public function index(Request $request)
    {
        $selectedBranchId = $request->input('branch_id');

        $query = CategoryPrinterMapping::with(['branch', 'category', 'printer'])
            ->orderBy('branch_id')
            ->orderBy('category_id');

        if ($selectedBranchId) {
            $query->where('branch_id', $selectedBranchId);
        }

        $mappings  = $query->get();
        $branches  = Branch::where('status', 'active')->orderBy('name')->get();
        $categories = Category::where('is_active', true)->orderBy('name')->get();
        $printers  = Printer::where('is_active', true)->orderBy('name')->get();

        return view('tenant.printing.category-mappings.index',
            compact('mappings', 'branches', 'categories', 'printers', 'selectedBranchId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'   => ['required', 'exists:branches,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'printer_id'  => ['required', 'exists:printers,id'],
            'print_role'  => ['required', Rule::in(['kot', 'receipt'])],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = !empty($data['is_active']);

        CategoryPrinterMapping::updateOrCreate(
            [
                'branch_id'   => $data['branch_id'],
                'category_id' => $data['category_id'],
                'print_role'  => $data['print_role'],
            ],
            [
                'printer_id' => $data['printer_id'],
                'is_active'  => $data['is_active'],
            ]
        );

        return back()->with('status', 'Mapping saved.');
    }

    public function destroy(CategoryPrinterMapping $categoryPrinterMapping)
    {
        $categoryPrinterMapping->delete();

        return back()->with('status', 'Mapping removed.');
    }
}
