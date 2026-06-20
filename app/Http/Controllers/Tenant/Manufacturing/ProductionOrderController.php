<?php

namespace App\Http\Controllers\Tenant\Manufacturing;

use App\Http\Controllers\Concerns\GeneratesSequentialCode;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ManufacturingCustomer;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionOrderController extends Controller
{
    use GeneratesSequentialCode;

    public function index(Request $request)
    {
        $query = ProductionOrder::query()
            ->with(['manufacturingCustomer', 'branch', 'product']);

        if ($request->filled('q')) {
            $s = trim($request->q);
            $query->where(function ($q) use ($s) {
                $q->where('order_no', 'like', "%{$s}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")
                                                        ->orWhere('sku', 'like', "%{$s}%"))
                  ->orWhereHas('manufacturingCustomer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status') && in_array($request->status, ProductionOrder::STATUSES, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('date_from')) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('order_date', '<=', $request->date_to);
        }

        $orders   = $query->orderByDesc('order_date')->orderByDesc('id')->paginate(20)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('tenant.manufacturing.production-orders.index', [
            'orders'   => $orders,
            'branches' => $branches,
            'filters'  => $request->only(['q', 'status', 'branch_id', 'date_from', 'date_to']),
            'statuses' => ProductionOrder::STATUSES,
        ]);
    }

    public function create()
    {
        return view('tenant.manufacturing.production-orders.create', [
            'order'     => null,
            'title'     => 'New Production Order',
            'nextNo'    => $this->nextOrderNo(),
            'customers' => ManufacturingCustomer::active()->orderBy('name')->get(['id', 'name', 'code']),
            'branches'  => Branch::orderBy('name')->get(['id', 'name']),
            'products'  => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'statuses'  => ProductionOrder::STATUSES,
            'priorities'=> ProductionOrder::PRIORITIES,
        ]);
    }

    public function store(Request $request)
    {
        if (empty(trim($request->input('order_no', '')))) {
            $request->merge(['order_no' => $this->nextOrderNo()]);
        }

        $data = $this->validate($request, $this->rules());
        $this->guardProducedQty($data);

        $data['created_by_user_id'] = auth('tenant')->id();

        $order = ProductionOrder::create($data);

        return redirect(url('/manufacturing/production-orders/' . $order->id))
            ->with('status', 'Production order created.');
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load(['manufacturingCustomer', 'branch', 'product', 'createdBy']);

        return view('tenant.manufacturing.production-orders.show', [
            'order' => $productionOrder,
        ]);
    }

    public function edit(ProductionOrder $productionOrder)
    {
        return view('tenant.manufacturing.production-orders.edit', [
            'order'     => $productionOrder,
            'title'     => 'Edit: ' . $productionOrder->order_no,
            'nextNo'    => null,
            'customers' => ManufacturingCustomer::active()->orderBy('name')->get(['id', 'name', 'code']),
            'branches'  => Branch::orderBy('name')->get(['id', 'name']),
            'products'  => Product::where('status', 'active')->orderBy('name')->get(['id', 'name', 'sku']),
            'statuses'  => ProductionOrder::STATUSES,
            'priorities'=> ProductionOrder::PRIORITIES,
        ]);
    }

    public function update(Request $request, ProductionOrder $productionOrder)
    {
        $data = $this->validate($request, $this->rules($productionOrder));
        $this->guardProducedQty($data);

        $productionOrder->update($data);

        return redirect(url('/manufacturing/production-orders/' . $productionOrder->id))
            ->with('status', 'Production order updated.');
    }

    public function destroy(ProductionOrder $productionOrder)
    {
        if ($productionOrder->isClosed()) {
            return back()->withErrors(['order' => 'This order is already closed and cannot be cancelled again.']);
        }

        $productionOrder->update(['status' => 'cancelled']);

        return redirect(url('/manufacturing/production-orders'))
            ->with('status', 'Production order cancelled.');
    }

    private function rules(?ProductionOrder $order = null): array
    {
        return [
            'order_no'                    => ['required', 'string', 'max:50',
                                              Rule::unique('production_orders', 'order_no')->ignore($order?->id)],
            'manufacturing_customer_id'   => ['nullable', 'integer', 'exists:manufacturing_customers,id'],
            'branch_id'                   => ['nullable', 'integer', 'exists:branches,id'],
            'product_id'                  => ['required', 'integer', 'exists:products,id'],
            'planned_quantity'            => ['required', 'numeric', 'min:0.0001'],
            'produced_quantity'           => ['nullable', 'numeric', 'min:0'],
            'order_date'                  => ['required', 'date'],
            'due_date'                    => ['nullable', 'date', 'after_or_equal:order_date'],
            'status'                      => ['required', Rule::in(ProductionOrder::STATUSES)],
            'priority'                    => ['nullable', Rule::in(ProductionOrder::PRIORITIES)],
            'notes'                       => ['nullable', 'string', 'max:3000'],
        ];
    }

    private function guardProducedQty(array $data): void
    {
        $planned  = (float) ($data['planned_quantity'] ?? 0);
        $produced = (float) ($data['produced_quantity'] ?? 0);

        if ($produced > $planned) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'produced_quantity' => 'Produced quantity cannot exceed planned quantity (' . $planned . ').',
            ]);
        }
    }

    private function nextOrderNo(): string
    {
        return $this->nextSequentialCode(ProductionOrder::class, 'order_no', 'PROD-', 6);
    }
}
