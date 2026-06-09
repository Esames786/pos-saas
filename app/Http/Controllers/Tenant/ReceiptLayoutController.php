<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceiptLayoutSetting;
use App\Models\Tenant\SalesOrder;
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

    public function preview(Request $request, ReceiptLayoutSetting $receiptLayoutSetting)
    {
        $base = $receiptLayoutSetting->load('branch');

        // Allow live override from query params (real-time preview)
        $boolFields = [
            'show_logo', 'show_branch_name', 'show_branch_address', 'show_branch_phone',
            'show_tax_number', 'show_cashier_name', 'show_customer_name', 'show_table_info',
            'show_order_no', 'show_item_codes', 'show_payment_breakdown',
        ];

        // Build a live layout object from request params (or fall back to saved values)
        $layout = (object) [
            'branch'                  => $base->branch,
            'branch_id'               => $base->branch_id,
            'document_type'           => $base->document_type,
            'paper_size'              => $request->input('paper_size',   $base->paper_size),
            'font_size'               => (int) $request->input('font_size',    $base->font_size ?? 12),
            'kot_font_size'           => (int) $request->input('kot_font_size', $base->kot_font_size ?? 14),
            'logo_path'               => $base->logo_path,
            'header_text'             => $request->input('header_text',  $base->header_text),
            'footer_text'             => $request->input('footer_text',  $base->footer_text),
            'is_active'               => true,
        ];

        foreach ($boolFields as $f) {
            $layout->{$f} = $request->has($f)
                ? filter_var($request->input($f), FILTER_VALIDATE_BOOLEAN)
                : (bool) $base->{$f};
        }

        // Use most recent real paid sale, else fake
        $salesOrder = SalesOrder::with([
                'lines', 'branch', 'payments.method',
                'restaurantTable.floor', 'restaurantWaiter', 'createdBy',
            ])
            ->where('branch_id', $base->branch_id)
            ->where('status', 'paid')
            ->latest('sale_date')
            ->first() ?? $this->fakeSalesOrder($base->branch);

        $view = $base->document_type === 'kot'
            ? 'tenant.printing.documents.kot'
            : 'tenant.printing.documents.receipt';

        return view($view, [
            'salesOrder'     => $salesOrder,
            'layout'         => $layout,
            'kotLines'       => $salesOrder->lines ?? collect(),
            'lineQuantities' => collect(),
            'isReprint'      => true,
        ]);
    }

    private function fakeSalesOrder($branch): object
    {
        $items = [
            ['name' => 'Loose Basmati Rice', 'qty' => 2.500, 'unit' => 'KG', 'price' => 280.00],
            ['name' => 'Chicken Karahi (Half)', 'qty' => 1.000, 'unit' => 'PCS', 'price' => 950.00],
            ['name' => 'Coca Cola 500ml', 'qty' => 3.000, 'unit' => 'PCS', 'price' => 60.00],
        ];

        $lines = collect($items)->map(function ($item) {
            return (object) [
                'product_name' => $item['name'],
                'variant_name' => $item['name'],
                'quantity'     => $item['qty'],
                'unit_code'    => $item['unit'],
                'unit_price'   => $item['price'],
                'line_total'   => $item['qty'] * $item['price'],
                'discount_amount' => 0,
                'tax_amount'   => 0,
                'kitchen_note' => null,
                'kot_sent'     => true,
                'id'           => rand(100, 999),
            ];
        });

        $subtotal = $lines->sum('line_total');
        $svcCharge = round($subtotal * 0.05, 2);

        return (object) [
            'sale_no'               => 'PREVIEW-001',
            'order_no'              => 'PREVIEW-001',
            'sale_date'             => now(),
            'order_type'            => 'quick_sale',
            'branch'                => $branch,
            'branch_id'             => $branch?->id,
            'lines'                 => $lines,
            'payments'              => collect([(object)[
                'amount'  => $subtotal + $svcCharge,
                'method'  => (object)['name' => 'Cash'],
                'payment_method' => 'cash',
            ]]),
            'subtotal'              => $subtotal,
            'discount_amount'       => 0,
            'tax_amount'            => 0,
            'service_charge_amount' => $svcCharge,
            'tip_amount'            => 0,
            'grand_total'           => $subtotal + $svcCharge,
            'paid_amount'           => $subtotal + $svcCharge,
            'change_amount'         => 0,
            'customer_name'         => null,
            'notes'                 => null,
            'restaurantTable'       => null,
            'restaurantTableSession'=> null,
            'restaurantWaiter'      => null,
            'created_by_user_id'    => null,
            'createdBy'             => (object)['name' => 'Demo Cashier'],
        ];
    }
}
