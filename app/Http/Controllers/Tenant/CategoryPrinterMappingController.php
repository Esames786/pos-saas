<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\CategoryPrinterMapping;
use App\Models\Tenant\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryPrinterMappingController extends Controller
{
    public function index(Request $request)
    {
        $selectedBranchId = $request->input('branch_id');

        $query = CategoryPrinterMapping::with(['branch', 'category', 'printer'])
            ->orderBy('branch_id')
            ->orderBy('order_type')
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
            'branch_id'   => ['nullable', 'exists:branches,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'printer_id'  => ['required', 'exists:printers,id'],
            'print_role'  => ['required', Rule::in(['kot', 'receipt', 'reminder'])],
            'order_type'  => ['required', Rule::in(['all', 'dine_in', 'takeaway', 'quick_sale', 'delivery'])],
            'reminder_confirm_on_addition' => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $data['branch_id'] = $data['branch_id'] ?? null;
        $data['order_type'] = trim((string) ($data['order_type'] ?? '')) ?: 'all';
        $data['is_active'] = !empty($data['is_active']);
        $data['reminder_confirm_on_addition'] = $data['print_role'] === 'reminder'
            && !empty($data['reminder_confirm_on_addition']);
        $printer = Printer::findOrFail($data['printer_id']);

        $capable = match ($data['print_role']) {
            'kot' => in_array($printer->print_role, ['kot', 'both'], true),
            'receipt' => in_array($printer->print_role, ['receipt', 'both'], true),
            'reminder' => (bool) $printer->supports_reminder,
        };
        if (!$capable) {
            throw ValidationException::withMessages([
                'printer_id' => 'The selected printer is not capable of this document type.',
            ]);
        }

        DB::connection('tenant')->transaction(function () use ($data) {
            // Serializes NULL-branch inserts, which a MySQL unique index cannot protect.
            Category::whereKey($data['category_id'])->lockForUpdate()->firstOrFail();

            $duplicate = CategoryPrinterMapping::query()
                ->when(
                    $data['branch_id'] === null,
                    fn ($query) => $query->whereNull('branch_id'),
                    fn ($query) => $query->where('branch_id', $data['branch_id'])
                )
                ->where('category_id', $data['category_id'])
                ->where('printer_id', $data['printer_id'])
                ->where('print_role', $data['print_role'])
                ->where('order_type', $data['order_type'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'printer_id' => 'This branch, order type, category, document, and printer mapping already exists.',
                ]);
            }

            CategoryPrinterMapping::create($data);
        });

        return back()->with('status', 'Mapping added.');
    }

    public function destroy(CategoryPrinterMapping $categoryPrinterMapping)
    {
        $categoryPrinterMapping->delete();

        return back()->with('status', 'Mapping removed.');
    }
}
