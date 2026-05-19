<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceiptLayoutSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceiptLayoutController extends Controller
{
    public function index(Request $request)
    {
        $selectedBranchId = $request->input('branch_id');

        $query = ReceiptLayoutSetting::with('branch')->orderBy('branch_id')->orderBy('document_type');

        if ($selectedBranchId) {
            $query->where('branch_id', $selectedBranchId);
        }

        $layouts  = $query->get();
        $branches = Branch::where('status', 'active')->orderBy('name')->get();

        return view('tenant.printing.layouts.index', compact('layouts', 'branches', 'selectedBranchId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'              => ['required', 'exists:branches,id'],
            'document_type'          => ['required', Rule::in(['receipt', 'kot'])],
            'paper_size'             => ['required', Rule::in(['58mm', '80mm', 'A4'])],
            'logo'                   => ['nullable', 'image', 'max:1024'],
            'show_logo'              => ['nullable', 'boolean'],
            'show_branch_name'       => ['nullable', 'boolean'],
            'show_branch_address'    => ['nullable', 'boolean'],
            'show_branch_phone'      => ['nullable', 'boolean'],
            'show_tax_number'        => ['nullable', 'boolean'],
            'show_cashier_name'      => ['nullable', 'boolean'],
            'show_customer_name'     => ['nullable', 'boolean'],
            'show_table_info'        => ['nullable', 'boolean'],
            'show_order_no'          => ['nullable', 'boolean'],
            'show_item_codes'        => ['nullable', 'boolean'],
            'show_payment_breakdown' => ['nullable', 'boolean'],
            'header_text'            => ['nullable', 'string', 'max:500'],
            'footer_text'            => ['nullable', 'string', 'max:500'],
            'font_size'              => ['required', 'integer', 'min:8', 'max:24'],
            'kot_font_size'          => ['required', 'integer', 'min:8', 'max:24'],
            'is_active'              => ['nullable', 'boolean'],
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('printing/logos', 'public');
        }

        $boolFields = [
            'show_logo', 'show_branch_name', 'show_branch_address', 'show_branch_phone',
            'show_tax_number', 'show_cashier_name', 'show_customer_name', 'show_table_info',
            'show_order_no', 'show_item_codes', 'show_payment_breakdown', 'is_active',
        ];

        foreach ($boolFields as $f) {
            $data[$f] = !empty($data[$f]);
        }

        $updateData = collect($data)->except(['logo'])->toArray();
        if ($logoPath) {
            $updateData['logo_path'] = $logoPath;
        }

        ReceiptLayoutSetting::updateOrCreate(
            ['branch_id' => $data['branch_id'], 'document_type' => $data['document_type']],
            $updateData
        );

        return back()->with('status', 'Layout settings saved.');
    }
}
