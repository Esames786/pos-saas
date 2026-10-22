<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\Supplier;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchasingService $purchasingService) {}

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['branch', 'supplier', 'postedBy'])
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $orders    = $query->paginate(20)->withQueryString();
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();

        return view('tenant.purchase-orders.index', compact('orders', 'branches', 'suppliers'));
    }

    public function create()
    {
        $branches  = Branch::orderBy('name')->get();
        $suppliers = Supplier::where('status', 'active')->orderBy('name')->get();
        $products  = Product::with(['unit', 'variants'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('tenant.purchase-orders.create', compact('branches', 'suppliers', 'products') + [
            'entryV2' => $entryV2 = \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PURCHASING_V2),
            'supplierBox' => $entryV2 ? \App\Support\SupplierBox::data($suppliers) : null,
        ]);
    }

    public function store(Request $request)
    {
        // PURCHASING-V2-1: the new entry area posts packs; turned into today's lines first (no bonus on a PO).
        if ($request->boolean('_entry_v2')) {
            $request->merge(['lines' => \App\Support\PackLines::toLines((array) $request->input('lines', []), 'quantity_ordered', false, null,
                ['type' => $request->input('bill_disc_type'), 'value' => $request->input('bill_disc')])]);
        }

        // Drop fully-blank rows before validation so empty lines never trigger
        // confusing per-line errors (works even if client-side JS is bypassed).
        $request->merge([
            'lines' => collect($request->input('lines', []))
                ->filter(fn ($l) => !empty($l['product_id'] ?? null))
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'branch_id'                => 'required|exists:tenant.branches,id',
            'supplier_id'              => 'required|exists:tenant.suppliers,id',
            'order_date'               => 'required|date',
            'expected_delivery_date'   => 'nullable|date|after_or_equal:order_date',
            'notes'                    => 'nullable|string|max:1000',
            'lines'                    => 'required|array|min:1',
            'lines.*.product_id'       => 'required|exists:tenant.products,id',
            'lines.*.product_variant_id' => 'nullable|exists:tenant.product_variants,id',
            'lines.*.quantity_ordered' => 'required|numeric|min:0.001',
            'lines.*.unit_cost'        => 'required|numeric|min:0',
            'lines.*.discount_amount'  => 'nullable|numeric|min:0',
            'lines.*.tax_amount'       => 'nullable|numeric|min:0',
            'lines.*.notes'            => 'nullable|string|max:500',
            'lines.*.pack_unit_id'     => 'nullable|integer',
            'lines.*.pack_factor'      => 'nullable|integer|min:1',
            'lines.*.base_quantity'    => 'nullable|numeric|min:0',
            'lines.*.entry_json'       => 'nullable|array',
        ]);
        // W2-C: each quantity exact once (3 decimals; counted items whole where switched on) before the total reads it.
        $data['lines'] = \App\Support\LineQuantity::prepareStockLines($data['lines'], 'quantity_ordered');

        $validLines = collect($data['lines'])->filter(fn($l) => !empty($l['product_id']));
        if ($validLines->isEmpty()) {
            return back()->withErrors(['lines' => 'At least one product line is required.'])->withInput();
        }

        $po = null;
        DB::connection('tenant')->transaction(function () use ($data, $validLines, &$po) {
            $total = $validLines->sum(function ($l) {
                return ($l['quantity_ordered'] * $l['unit_cost'])
                    - ($l['discount_amount'] ?? 0)
                    + ($l['tax_amount'] ?? 0);
            });

            $po = PurchaseOrder::create([
                'po_no'                   => $this->purchasingService->nextPoNo(),
                'branch_id'               => $data['branch_id'],
                'supplier_id'             => $data['supplier_id'],
                'order_date'              => $data['order_date'],
                'expected_delivery_date'  => $data['expected_delivery_date'] ?? null,
                'status'                  => 'draft',
                'notes'                   => $data['notes'] ?? null,
                'total_amount'            => $total,
                'posted_by_user_id'       => auth('tenant')->id(),
            ]);

            foreach ($validLines as $line) {
                PurchaseOrderLine::create([
                    'purchase_order_id'  => $po->id,
                    'product_id'         => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'quantity_ordered'   => $line['quantity_ordered'],
                    'unit_cost'          => $line['unit_cost'],
                    'discount_amount'    => $line['discount_amount'] ?? 0,
                    'tax_amount'         => $line['tax_amount'] ?? 0,
                    'notes'              => $line['notes'] ?? null,
                    'pack_unit_id'       => $line['pack_unit_id'] ?? null,
                    'pack_factor'        => $line['pack_factor'] ?? null,
                    'base_quantity'      => $line['base_quantity'] ?? null,
                    'entry_json'         => $line['entry_json'] ?? null,
                ]);
            }
        });

        // PURCHASING-V2-2: the new screen saves without leaving the page.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $po->id, 'number' => $po->po_no, 'url' => url('/purchase-orders/' . $po->id)]);
        }

        return redirect(url('/purchase-orders'))->with('status', 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['branch', 'supplier', 'lines.product', 'lines.variant', 'postedBy', 'approvedBy', 'goodsReceipts', 'bills']);
        return view('tenant.purchase-orders.show', compact('purchaseOrder'));
    }

    public function approve(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return back()->withErrors(['status' => 'Only draft orders can be approved.']);
        }

        $purchaseOrder->update([
            'status'              => 'approved',
            'approved_by_user_id' => auth('tenant')->id(),
            'approved_at'         => now(),
        ]);

        return redirect(url('/purchase-orders/' . $purchaseOrder->id))
            ->with('status', 'Purchase order approved.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        if (!in_array($purchaseOrder->status, ['draft', 'approved'])) {
            return back()->withErrors(['status' => 'Only draft or approved orders can be cancelled.']);
        }

        $purchaseOrder->update(['status' => 'cancelled']);

        return redirect(url('/purchase-orders/' . $purchaseOrder->id))
            ->with('status', 'Purchase order cancelled.');
    }
}
